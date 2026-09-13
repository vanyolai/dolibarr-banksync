<?php
/* SPDX-License-Identifier: GPL-3.0-or-later */

$res = 0;
if (!$res && !empty($_SERVER['CONTEXT_DOCUMENT_ROOT'])) {
    $res = @include str_replace('..', '', $_SERVER['CONTEXT_DOCUMENT_ROOT']).'/main.inc.php';
}
if (!$res && file_exists('../main.inc.php')) $res = @include '../main.inc.php';
if (!$res && file_exists('../../main.inc.php')) $res = @include '../../main.inc.php';
if (!$res && file_exists('../../../main.inc.php')) $res = @include '../../../main.inc.php';
if (!$res) die('Include of main fails');

require_once __DIR__.'/class/banksyncschema.class.php';
require_once __DIR__.'/class/banksyncmatchmanager.class.php';
require_once __DIR__.'/class/banksynccandidatematcher.class.php';
require_once __DIR__.'/class/banksyncmanualsearch.class.php';

$langs->load('banksync@banksync');

if (!isModEnabled('banksync')) accessforbidden('BankSync module is not enabled.');
if (!$user->hasRight('banksync', 'read')) accessforbidden();

try {
    BankSyncSchema::ensure($db);
} catch (Exception $e) {
    setEventMessages($langs->trans('BankSyncSchemaMigrationFailed', $e->getMessage()), null, 'errors');
}

$entity = (int) $conf->entity;
$transactionId = GETPOSTINT('id');
$action = GETPOST('action', 'aZ09');
if ($transactionId <= 0) accessforbidden('Missing transaction id.');

$matchManager = new BankSyncMatchManager($db, $entity);
$matcher = new BankSyncCandidateMatcher($db, $entity);
$manualSearch = new BankSyncManualSearch($db, $entity);

$transaction = $matcher->fetchTransaction($transactionId);
if (!$transaction) accessforbidden('BankSync transaction not found.');

$manualType = GETPOST('manual_type', 'alpha');
$manualQuery = GETPOST('manual_q', 'alphanohtml');
$manualRequested = GETPOSTINT('manual_search') > 0;
if ($manualType === '') {
    $isCredit = ((string) $transaction->direction === 'credit' || (float) $transaction->amount > 0);
    $manualType = $isCredit ? BankSyncMatchManager::TARGET_CUSTOMER_INVOICE : BankSyncMatchManager::TARGET_SUPPLIER_INVOICE;
}

if (in_array($action, array('confirm', 'reject', 'refresh', 'manual_confirm'), true) && !$user->hasRight('banksync', 'import')) {
    accessforbidden();
}

try {
    if ($action === 'confirm') {
        $matchManager->setStatus(GETPOSTINT('match_id'), 'confirmed', $user->id, $transactionId, GETPOST('allocated_amount', 'alphanohtml'));
        setEventMessages($langs->trans('BankSyncMatchConfirmed'), null, 'mesgs');
        header('Location: '.dol_buildpath('/banksync/reconcile.php', 1).'?mainmenu=bank&leftmenu=banksync_transactions&id='.$transactionId);
        exit;
    }
    if ($action === 'reject') {
        $matchManager->setStatus(GETPOSTINT('match_id'), 'rejected', $user->id, $transactionId);
        setEventMessages($langs->trans('BankSyncMatchRejected'), null, 'mesgs');
        header('Location: '.dol_buildpath('/banksync/reconcile.php', 1).'?mainmenu=bank&leftmenu=banksync_transactions&id='.$transactionId);
        exit;
    }
    if ($action === 'refresh') {
        $matcher->refreshSuggestions($transactionId, $user->id);
        setEventMessages($langs->trans('BankSyncCandidatesRefreshed'), null, 'mesgs');
        header('Location: '.dol_buildpath('/banksync/reconcile.php', 1).'?mainmenu=bank&leftmenu=banksync_transactions&id='.$transactionId);
        exit;
    }
    if ($action === 'manual_confirm') {
        $targetType = GETPOST('target_type', 'alpha');
        $targetId = GETPOSTINT('target_id');
        $allocatedAmount = GETPOST('allocated_amount', 'alphanohtml');
        $matchManager->upsert($transactionId, $targetType, $targetId, $allocatedAmount, 0, 'manual', 'confirmed', $user->id);
        setEventMessages($langs->trans('BankSyncManualMatchConfirmed'), null, 'mesgs');
        header('Location: '.dol_buildpath('/banksync/reconcile.php', 1).'?mainmenu=bank&leftmenu=banksync_transactions&id='.$transactionId);
        exit;
    }
} catch (Exception $e) {
    setEventMessages($langs->trans('BankSyncMatchActionFailed', $e->getMessage()), null, 'errors');
}

$candidates = array();
$allocationSummary = array();
$manualResults = array();
try {
    if ((string) $transaction->bank_event_type !== 'bank_fee') {
        $candidates = $matcher->refreshSuggestions($transactionId, $user->id);
    }

    $candidateKeys = array();
    foreach ($candidates as $candidate) {
        $candidateKeys[(string) $candidate['target_type'].':'.(int) $candidate['target_id']] = true;
    }
    foreach ($matchManager->getForTransaction($transactionId) as $storedMatch) {
        if (!in_array((string) $storedMatch->status, array('confirmed', 'posted'), true)) continue;
        $key = (string) $storedMatch->target_type.':'.(int) $storedMatch->target_id;
        if (isset($candidateKeys[$key])) continue;
        $target = $manualSearch->fetchTarget((string) $storedMatch->target_type, (int) $storedMatch->target_id);
        if (!$target) continue;
        $target['allocated_amount'] = (string) $storedMatch->allocated_amount;
        $target['confidence'] = (int) $storedMatch->confidence;
        $target['reason_codes'] = array('manual');
        $target['match_id'] = (int) $storedMatch->rowid;
        $target['status'] = (string) $storedMatch->status;
        $target['match_method'] = (string) $storedMatch->match_method;
        $candidates[] = $target;
        $candidateKeys[$key] = true;
    }

    $allocationSummary = $matchManager->getAllocationSummary($transactionId);
    if ($manualRequested && (string) $transaction->bank_event_type !== 'bank_fee') {
        $manualResults = $manualSearch->search($transaction, $manualType, $manualQuery, 30);
    }
} catch (Exception $e) {
    setEventMessages($langs->trans('BankSyncCandidateSearchFailed', $e->getMessage()), null, 'errors');
}

function banksyncTargetLabel($langs, $type)
{
    $key = 'BankSyncTarget_'.$type;
    $label = $langs->trans($key);
    return $label === $key ? $type : $label;
}

function banksyncReasonLabel($langs, $code)
{
    $key = 'BankSyncReason_'.$code;
    $label = $langs->trans($key);
    return $label === $key ? $code : $label;
}

function banksyncConfidencePresentation($langs, $confidence)
{
    $confidence = (int) $confidence;
    if ($confidence >= 100) return array('class' => 'badge-status4', 'label' => $langs->trans('BankSyncConfidenceCertain'));
    if ($confidence >= 80) return array('class' => 'badge-status1', 'label' => $langs->trans('BankSyncConfidenceStrong'));
    if ($confidence >= 60) return array('class' => 'badge-status1', 'label' => $langs->trans('BankSyncConfidencePossible'));
    return array('class' => 'badge-status0', 'label' => $langs->trans('BankSyncConfidenceWeak'));
}

llxHeader('', $langs->trans('BankSyncReconciliation'));
print load_fiche_titre($langs->trans('BankSyncReconciliation').' #'.$transactionId, '', 'bank');

print '<div class="tabsAction">';
print '<a class="butAction" href="'.dol_buildpath('/banksync/transactions.php', 1).'?mainmenu=bank&leftmenu=banksync_transactions">'.$langs->trans('BackToList').'</a>';
if ($user->hasRight('banksync', 'import') && (string) $transaction->bank_event_type !== 'bank_fee') {
    print '<form method="POST" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'" class="inline-block">';
    print '<input type="hidden" name="token" value="'.newToken().'">';
    print '<input type="hidden" name="id" value="'.$transactionId.'"><input type="hidden" name="action" value="refresh">';
    print '<button type="submit" class="butAction">'.$langs->trans('BankSyncRefreshCandidates').'</button></form>';
}
print '</div>';

print '<table class="border centpercent">';
print '<tr><td class="titlefield">'.$langs->trans('BankSyncBookingDate').'</td><td>'.dol_escape_htmltag((string) $transaction->booking_date).'</td></tr>';
print '<tr><td>'.$langs->trans('BankSyncBankEventType').'</td><td>'.dol_escape_htmltag($langs->trans('BankSyncEventType_'.(string) $transaction->bank_event_type)).(!empty($transaction->dolibarr_payment_code) ? ' <span class="opacitymedium">('.dol_escape_htmltag($transaction->dolibarr_payment_code).')</span>' : '').'</td></tr>';
print '<tr><td>'.$langs->trans('BankSyncTransactionCode').'</td><td>'.dol_escape_htmltag((string) $transaction->transaction_code).' <span class="opacitymedium">'.dol_escape_htmltag((string) $transaction->transaction_type).'</span></td></tr>';
print '<tr><td>'.$langs->trans('BankSyncCounterparty').'</td><td>'.dol_escape_htmltag((string) $transaction->counterparty_name);
if (!empty($transaction->counterparty_account)) print '<br><span class="opacitymedium">'.dol_escape_htmltag((string) $transaction->counterparty_account).'</span>';
print '</td></tr>';
print '<tr><td>'.$langs->trans('BankSyncReference').'</td><td>'.dol_escape_htmltag((string) $transaction->reference).'</td></tr>';
print '<tr><td>'.$langs->trans('Amount').'</td><td><strong>'.price($transaction->amount).' '.dol_escape_htmltag((string) $transaction->currency).'</strong></td></tr>';
print '<tr><td>'.$langs->trans('BankSyncDolibarrBankAccount').'</td><td>'.(!empty($transaction->fk_bank_account) ? dol_escape_htmltag(trim((string) $transaction->bank_account_label) !== '' ? (string) $transaction->bank_account_label : (string) $transaction->bank_account_ref) : '<span class="error">'.$langs->trans('BankSyncUnmapped').'</span>').'</td></tr>';
print '<tr><td>'.$langs->trans('Status').'</td><td>'.dol_escape_htmltag((string) $transaction->status).'</td></tr>';
print '</table>';

if (!empty($allocationSummary) && (string) $transaction->bank_event_type !== 'bank_fee') {
    print '<br>'.load_fiche_titre($langs->trans('BankSyncAllocationSummary'), '', 'payment');
    print '<table class="border centpercent">';
    print '<tr><td class="titlefield">'.$langs->trans('BankSyncBankAmount').'</td><td>'.price($allocationSummary['target_amount']).' '.dol_escape_htmltag($allocationSummary['currency']).'</td></tr>';
    print '<tr><td>'.$langs->trans('BankSyncAllocatedAmount').'</td><td>'.price($allocationSummary['allocated_amount']).' '.dol_escape_htmltag($allocationSummary['currency']).'</td></tr>';
    $remainingClass = !empty($allocationSummary['balanced']) ? 'badge-status4' : 'badge-status1';
    $remainingLabel = !empty($allocationSummary['balanced']) ? $langs->trans('BankSyncFullyAllocated') : $langs->trans('BankSyncRemainingToAllocate');
    print '<tr><td>'.$langs->trans('BankSyncAllocationDifference').'</td><td><span class="badge '.$remainingClass.'">'.dol_escape_htmltag($remainingLabel).'</span> '.price($allocationSummary['remaining_amount']).' '.dol_escape_htmltag($allocationSummary['currency']).'</td></tr>';
    print '</table>';
}

print '<br>'.load_fiche_titre($langs->trans('BankSyncCandidates'), '', 'search');
if ((string) $transaction->bank_event_type === 'bank_fee') {
    print '<div class="info">'.$langs->trans('BankSyncBankFeeNoBusinessMatch').'</div>';
} elseif (empty($candidates)) {
    print '<div class="opacitymedium">'.$langs->trans('BankSyncNoCandidates').'</div>';
} else {
    print '<div class="div-table-responsive"><table class="noborder centpercent">';
    print '<tr class="liste_titre"><th>'.$langs->trans('BankSyncMatchTarget').'</th><th>'.$langs->trans('Ref').'</th><th>'.$langs->trans('Label').'</th><th>'.$langs->trans('Date').'</th><th class="right">'.$langs->trans('BankSyncRemainingAmount').'</th><th class="right">'.$langs->trans('BankSyncAllocation').'</th><th class="center">'.$langs->trans('BankSyncConfidence').'</th><th>'.$langs->trans('BankSyncMatchReasons').'</th><th>'.$langs->trans('Status').'</th><th class="right">'.$langs->trans('Action').'</th></tr>';
    foreach ($candidates as $candidate) {
        $status = isset($candidate['status']) ? (string) $candidate['status'] : 'suggested';
        $confidence = isset($candidate['confidence']) ? (int) $candidate['confidence'] : 0;
        $cp = banksyncConfidencePresentation($langs, $confidence);
        $isManual = isset($candidate['match_method']) && (string) $candidate['match_method'] === 'manual';
        print '<tr class="oddeven"><td>'.dol_escape_htmltag(banksyncTargetLabel($langs, (string) $candidate['target_type'])).'</td><td>';
        if (!empty($candidate['url'])) print '<a href="'.dol_buildpath((string) $candidate['url'], 1).'">'.dol_escape_htmltag((string) $candidate['ref']).'</a>'; else print dol_escape_htmltag((string) $candidate['ref']);
        print '</td><td>'.dol_escape_htmltag((string) $candidate['label']).'</td><td>'.dol_escape_htmltag((string) $candidate['date']).'</td>';
        print '<td class="right nowrap">'.price($candidate['remaining_amount']).' '.dol_escape_htmltag((string) $transaction->currency).'</td>';
        print '<td class="right nowrap">'.price($candidate['allocated_amount']).' '.dol_escape_htmltag((string) $transaction->currency).'</td>';
        print '<td class="center">'.($isManual ? '<span class="badge badge-status0">'.$langs->trans('BankSyncManual').'</span>' : '<span class="badge '.dol_escape_htmltag($cp['class']).'">'.$confidence.'%</span>').'</td>';
        $reasonLabels = array();
        foreach ($candidate['reason_codes'] as $reasonCode) $reasonLabels[] = banksyncReasonLabel($langs, (string) $reasonCode);
        print '<td>'.dol_escape_htmltag(implode(', ', $reasonLabels)).'</td><td>';
        if ($status === 'confirmed') print '<span class="badge badge-status4">'.$langs->trans('BankSyncMatchConfirmedStatus').'</span>';
        elseif ($status === 'rejected') print '<span class="badge badge-status8">'.$langs->trans('BankSyncMatchRejectedStatus').'</span>';
        elseif ($status === 'posted') print '<span class="badge badge-status6">'.$langs->trans('BankSyncMatchPostedStatus').'</span>';
        else print '<span class="badge '.dol_escape_htmltag($cp['class']).'">'.dol_escape_htmltag($cp['label']).'</span>';
        print '</td><td class="right nowrap">';
        if ($user->hasRight('banksync', 'import') && !empty($candidate['match_id'])) {
            if ($status !== 'confirmed' && $status !== 'posted') {
                print '<form method="POST" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'" class="inline-block">';
                print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="id" value="'.$transactionId.'"><input type="hidden" name="match_id" value="'.(int) $candidate['match_id'].'"><input type="hidden" name="action" value="confirm">';
                print '<input class="width75 right" type="text" name="allocated_amount" value="'.dol_escape_htmltag((string) $candidate['allocated_amount']).'"> ';
                print '<button type="submit" class="button button-save">'.$langs->trans('BankSyncConfirmMatch').'</button></form> ';
            }
            if ($status !== 'rejected' && $status !== 'posted') {
                print '<form method="POST" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'" class="inline-block">';
                print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="id" value="'.$transactionId.'"><input type="hidden" name="match_id" value="'.(int) $candidate['match_id'].'"><input type="hidden" name="action" value="reject">';
                print '<button type="submit" class="button">'.$langs->trans('BankSyncRejectMatch').'</button></form>';
            }
        }
        print '</td></tr>';
    }
    print '</table></div>';
}

if ((string) $transaction->bank_event_type !== 'bank_fee') {
    print '<br>'.load_fiche_titre($langs->trans('BankSyncManualReconciliation'), '', 'search');
    print '<div class="opacitymedium">'.$langs->trans('BankSyncManualReconciliationHelp').'</div><br>';
    print '<form method="GET" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'">';
    print '<input type="hidden" name="mainmenu" value="bank"><input type="hidden" name="leftmenu" value="banksync_transactions"><input type="hidden" name="id" value="'.$transactionId.'"><input type="hidden" name="manual_search" value="1">';
    print '<select name="manual_type" class="flat">';
    $types = array(BankSyncMatchManager::TARGET_CUSTOMER_INVOICE, BankSyncMatchManager::TARGET_SUPPLIER_INVOICE, BankSyncMatchManager::TARGET_SALARY, BankSyncMatchManager::TARGET_SOCIAL_CONTRIBUTION);
    foreach ($types as $type) print '<option value="'.dol_escape_htmltag($type).'"'.($manualType === $type ? ' selected' : '').'>'.dol_escape_htmltag(banksyncTargetLabel($langs, $type)).'</option>';
    print '</select> ';
    print '<input type="text" class="minwidth300" name="manual_q" value="'.dol_escape_htmltag((string) $manualQuery).'" placeholder="'.dol_escape_htmltag($langs->trans('BankSyncManualSearchPlaceholder')).'"> ';
    print '<button type="submit" class="button">'.$langs->trans('Search').'</button></form>';

    if ($manualRequested) {
        print '<br><div class="div-table-responsive"><table class="noborder centpercent">';
        print '<tr class="liste_titre"><th>'.$langs->trans('Ref').'</th><th>'.$langs->trans('Label').'</th><th>'.$langs->trans('Date').'</th><th class="right">'.$langs->trans('BankSyncRemainingAmount').'</th><th class="right">'.$langs->trans('Action').'</th></tr>';
        if (empty($manualResults)) {
            print '<tr><td colspan="5"><span class="opacitymedium">'.$langs->trans('BankSyncManualNoResults').'</span></td></tr>';
        } else {
            foreach ($manualResults as $result) {
                $freeAmount = isset($allocationSummary['remaining_amount']) ? max(0, (float) $allocationSummary['remaining_amount']) : abs((float) $transaction->amount);
                $defaultAllocation = min((float) $result['remaining_amount'], $freeAmount > 0 ? $freeAmount : (float) $result['remaining_amount']);
                print '<tr class="oddeven"><td><a href="'.dol_buildpath((string) $result['url'], 1).'">'.dol_escape_htmltag((string) $result['ref']).'</a></td><td>'.dol_escape_htmltag((string) $result['label']).'</td><td>'.dol_escape_htmltag((string) $result['date']).'</td><td class="right nowrap">'.price($result['remaining_amount']).' '.dol_escape_htmltag((string) $transaction->currency).'</td>';
                print '<td class="right nowrap"><form method="POST" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'" class="inline-block">';
                print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="id" value="'.$transactionId.'"><input type="hidden" name="action" value="manual_confirm"><input type="hidden" name="target_type" value="'.dol_escape_htmltag((string) $result['target_type']).'"><input type="hidden" name="target_id" value="'.(int) $result['target_id'].'">';
                print '<input class="width100 right" type="text" name="allocated_amount" value="'.dol_escape_htmltag(number_format($defaultAllocation, 2, '.', '')).'"> '.dol_escape_htmltag((string) $transaction->currency).' ';
                print '<button type="submit" class="button button-save">'.$langs->trans('BankSyncManualAssign').'</button></form></td></tr>';
            }
        }
        print '</table></div>';
    }
}

print '<br><div class="opacitymedium">'.$langs->trans('BankSyncReconciliationHelp').'</div>';
llxFooter();
$db->close();
