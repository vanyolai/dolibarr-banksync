<?php
/* SPDX-License-Identifier: GPL-3.0-or-later */

$res = 0;
if (!$res && !empty($_SERVER['CONTEXT_DOCUMENT_ROOT'])) {
    $res = @include str_replace('..', '', $_SERVER['CONTEXT_DOCUMENT_ROOT']).'/main.inc.php';
}
if (!$res && file_exists('../main.inc.php')) {
    $res = @include '../main.inc.php';
}
if (!$res && file_exists('../../main.inc.php')) {
    $res = @include '../../main.inc.php';
}
if (!$res && file_exists('../../../main.inc.php')) {
    $res = @include '../../../main.inc.php';
}
if (!$res) {
    die('Include of main fails');
}

require_once __DIR__.'/class/banksyncschema.class.php';
require_once __DIR__.'/class/banksyncmatchmanager.class.php';
require_once __DIR__.'/class/banksynccandidatematcher.class.php';

$langs->load('banksync@banksync');

if (!isModEnabled('banksync')) {
    accessforbidden('BankSync module is not enabled.');
}
if (!$user->hasRight('banksync', 'read')) {
    accessforbidden();
}

try {
    BankSyncSchema::ensure($db);
} catch (Exception $e) {
    setEventMessages($langs->trans('BankSyncSchemaMigrationFailed', $e->getMessage()), null, 'errors');
}

$entity = (int) $conf->entity;
$transactionId = GETPOSTINT('id');
$action = GETPOST('action', 'aZ09');
if ($transactionId <= 0) {
    accessforbidden('Missing transaction id.');
}

$matchManager = new BankSyncMatchManager($db, $entity);
$matcher = new BankSyncCandidateMatcher($db, $entity);

if (in_array($action, array('confirm', 'reject', 'refresh'), true) && !$user->hasRight('banksync', 'import')) {
    accessforbidden();
}

try {
    if ($action === 'confirm') {
        $matchManager->setStatus(GETPOSTINT('match_id'), 'confirmed', $user->id, $transactionId);
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
} catch (Exception $e) {
    setEventMessages($langs->trans('BankSyncMatchActionFailed', $e->getMessage()), null, 'errors');
}

$transaction = null;
$candidates = array();
try {
    $transaction = $matcher->fetchTransaction($transactionId);
    if (!$transaction) {
        accessforbidden('BankSync transaction not found.');
    }
    $candidates = $matcher->refreshSuggestions($transactionId, $user->id);
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

llxHeader('', $langs->trans('BankSyncReconciliation'));

print load_fiche_titre($langs->trans('BankSyncReconciliation').' #'.((int) $transactionId), '', 'bank');
print '<div class="tabsAction">';
print '<a class="butAction" href="'.dol_buildpath('/banksync/transactions.php', 1).'?mainmenu=bank&leftmenu=banksync_transactions">'.$langs->trans('BackToList').'</a>';
if ($user->hasRight('banksync', 'import') && (string) $transaction->bank_event_type !== 'bank_fee') {
    print '<form method="POST" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'" class="inline-block">';
    print '<input type="hidden" name="token" value="'.newToken().'">';
    print '<input type="hidden" name="id" value="'.((int) $transactionId).'">';
    print '<input type="hidden" name="action" value="refresh">';
    print '<button type="submit" class="butAction">'.$langs->trans('BankSyncRefreshCandidates').'</button>';
    print '</form>';
}
print '</div>';

print '<table class="border centpercent">';
print '<tr><td class="titlefield">'.$langs->trans('BankSyncBookingDate').'</td><td>'.dol_escape_htmltag((string) $transaction->booking_date).'</td></tr>';
print '<tr><td>'.$langs->trans('BankSyncBankEventType').'</td><td>'.dol_escape_htmltag($langs->trans('BankSyncEventType_'.((string) $transaction->bank_event_type))).(!empty($transaction->dolibarr_payment_code) ? ' <span class="opacitymedium">('.dol_escape_htmltag($transaction->dolibarr_payment_code).')</span>' : '').'</td></tr>';
print '<tr><td>'.$langs->trans('BankSyncTransactionCode').'</td><td>'.dol_escape_htmltag((string) $transaction->transaction_code).' <span class="opacitymedium">'.dol_escape_htmltag((string) $transaction->transaction_type).'</span></td></tr>';
print '<tr><td>'.$langs->trans('BankSyncCounterparty').'</td><td>'.dol_escape_htmltag((string) $transaction->counterparty_name);
if (!empty($transaction->counterparty_account)) {
    print '<br><span class="opacitymedium">'.dol_escape_htmltag((string) $transaction->counterparty_account).'</span>';
}
print '</td></tr>';
print '<tr><td>'.$langs->trans('BankSyncReference').'</td><td>'.dol_escape_htmltag((string) $transaction->reference).'</td></tr>';
print '<tr><td>'.$langs->trans('Amount').'</td><td><strong>'.price($transaction->amount).' '.dol_escape_htmltag((string) $transaction->currency).'</strong></td></tr>';
print '<tr><td>'.$langs->trans('BankSyncDolibarrBankAccount').'</td><td>'.(!empty($transaction->fk_bank_account) ? dol_escape_htmltag(trim((string) $transaction->bank_account_label) !== '' ? (string) $transaction->bank_account_label : (string) $transaction->bank_account_ref) : '<span class="error">'.$langs->trans('BankSyncUnmapped').'</span>').'</td></tr>';
print '<tr><td>'.$langs->trans('Status').'</td><td>'.dol_escape_htmltag((string) $transaction->status).'</td></tr>';
print '</table>';

print '<br>';
print load_fiche_titre($langs->trans('BankSyncCandidates'), '', 'search');

if ((string) $transaction->bank_event_type === 'bank_fee') {
    print '<div class="info">'.$langs->trans('BankSyncBankFeeNoBusinessMatch').'</div>';
} elseif (empty($candidates)) {
    print '<div class="opacitymedium">'.$langs->trans('BankSyncNoCandidates').'</div>';
} else {
    print '<div class="div-table-responsive">';
    print '<table class="noborder centpercent">';
    print '<tr class="liste_titre">';
    print '<th>'.$langs->trans('BankSyncMatchTarget').'</th>';
    print '<th>'.$langs->trans('Ref').'</th>';
    print '<th>'.$langs->trans('Label').'</th>';
    print '<th>'.$langs->trans('Date').'</th>';
    print '<th class="right">'.$langs->trans('BankSyncRemainingAmount').'</th>';
    print '<th class="center">'.$langs->trans('BankSyncConfidence').'</th>';
    print '<th>'.$langs->trans('BankSyncMatchReasons').'</th>';
    print '<th>'.$langs->trans('Status').'</th>';
    print '<th class="right">'.$langs->trans('Action').'</th>';
    print '</tr>';

    foreach ($candidates as $candidate) {
        $status = isset($candidate['status']) ? (string) $candidate['status'] : 'suggested';
        print '<tr class="oddeven">';
        print '<td>'.dol_escape_htmltag(banksyncTargetLabel($langs, (string) $candidate['target_type'])).'</td>';
        print '<td>';
        if (!empty($candidate['url'])) {
            print '<a href="'.dol_buildpath((string) $candidate['url'], 1).'">'.dol_escape_htmltag((string) $candidate['ref']).'</a>';
        } else {
            print dol_escape_htmltag((string) $candidate['ref']);
        }
        print '</td>';
        print '<td>'.dol_escape_htmltag((string) $candidate['label']).'</td>';
        print '<td>'.dol_escape_htmltag((string) $candidate['date']).'</td>';
        print '<td class="right nowrap">'.price($candidate['remaining_amount']).' '.dol_escape_htmltag((string) $transaction->currency).'</td>';
        print '<td class="center"><strong>'.((int) $candidate['confidence']).'%</strong></td>';
        print '<td>';
        $reasonLabels = array();
        foreach ($candidate['reason_codes'] as $reasonCode) {
            $reasonLabels[] = banksyncReasonLabel($langs, (string) $reasonCode);
        }
        print dol_escape_htmltag(implode(', ', $reasonLabels));
        print '</td>';
        print '<td>';
        if ($status === 'confirmed') {
            print '<span class="badge badge-status4">'.$langs->trans('BankSyncMatchConfirmedStatus').'</span>';
        } elseif ($status === 'rejected') {
            print '<span class="badge badge-status8">'.$langs->trans('BankSyncMatchRejectedStatus').'</span>';
        } elseif ($status === 'posted') {
            print '<span class="badge badge-status6">'.$langs->trans('BankSyncMatchPostedStatus').'</span>';
        } else {
            print '<span class="badge badge-status1">'.$langs->trans('BankSyncSuggested').'</span>';
        }
        print '</td>';
        print '<td class="right nowrap">';
        if ($user->hasRight('banksync', 'import') && !empty($candidate['match_id'])) {
            if ($status !== 'confirmed' && $status !== 'posted') {
                print '<form method="POST" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'" class="inline-block">';
                print '<input type="hidden" name="token" value="'.newToken().'">';
                print '<input type="hidden" name="id" value="'.((int) $transactionId).'">';
                print '<input type="hidden" name="match_id" value="'.((int) $candidate['match_id']).'">';
                print '<input type="hidden" name="action" value="confirm">';
                print '<button type="submit" class="button button-save">'.$langs->trans('BankSyncConfirmMatch').'</button>';
                print '</form> ';
            }
            if ($status !== 'rejected' && $status !== 'posted') {
                print '<form method="POST" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'" class="inline-block">';
                print '<input type="hidden" name="token" value="'.newToken().'">';
                print '<input type="hidden" name="id" value="'.((int) $transactionId).'">';
                print '<input type="hidden" name="match_id" value="'.((int) $candidate['match_id']).'">';
                print '<input type="hidden" name="action" value="reject">';
                print '<button type="submit" class="button">'.$langs->trans('BankSyncRejectMatch').'</button>';
                print '</form>';
            }
        }
        print '</td>';
        print '</tr>';
    }
    print '</table>';
    print '</div>';
}

print '<br><div class="opacitymedium">'.$langs->trans('BankSyncReconciliationHelp').'</div>';

llxFooter();
$db->close();
