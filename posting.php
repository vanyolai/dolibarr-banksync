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
require_once __DIR__.'/class/banksynccandidatematcher.class.php';
require_once __DIR__.'/class/banksyncpostingservice.class.php';

$langs->load('banksync@banksync');
if (!isModEnabled('banksync')) accessforbidden('BankSync module is not enabled.');
if (!$user->hasRight('banksync', 'read')) accessforbidden();

try {
    BankSyncSchema::ensure($db);
} catch (Exception $e) {
    setEventMessages($langs->trans('BankSyncSchemaMigrationFailed', $e->getMessage()), null, 'errors');
}

$transactionId = GETPOSTINT('id');
if ($transactionId <= 0) accessforbidden('Missing transaction id.');

$matcher = new BankSyncCandidateMatcher($db, (int) $conf->entity);
$transaction = $matcher->fetchTransaction($transactionId);
if (!$transaction) accessforbidden('BankSync transaction not found.');

$posting = new BankSyncPostingService($db, (int) $conf->entity);
try {
    $preview = $posting->buildPreview($transaction);
} catch (Exception $e) {
    $preview = array(
        'postable' => false,
        'kind' => '',
        'payment_code' => '',
        'bank_account_id' => 0,
        'bank_account_label' => '',
        'bank_amount' => abs((float) $transaction->amount),
        'signed_bank_amount' => (float) $transaction->amount,
        'currency' => (string) $transaction->currency,
        'booking_date' => (string) $transaction->booking_date,
        'reference' => (string) $transaction->reference,
        'counterparty' => (string) $transaction->counterparty_name,
        'rows' => array(),
        'warnings' => array(),
        'errors' => array($e->getMessage()),
        'rounding_difference' => 0.0,
    );
}

function banksyncPostingMessage($langs, $key)
{
    $translated = $langs->trans((string) $key);
    return $translated === (string) $key ? (string) $key : $translated;
}

function banksyncPostingOperationLabel($langs, $kind)
{
    switch ((string) $kind) {
        case BankSyncMatchManager::TARGET_CUSTOMER_INVOICE:
            return $langs->trans('BankSyncPostingNativeCustomerPayment');
        case BankSyncMatchManager::TARGET_SUPPLIER_INVOICE:
            return $langs->trans('BankSyncPostingNativeSupplierPayment');
        case BankSyncMatchManager::TARGET_BANK_FEE:
            return $langs->trans('BankSyncPostingNativeBankFee');
        default:
            return $langs->trans('BankSyncPostingTargetNotSupportedYet');
    }
}

llxHeader('', $langs->trans('BankSyncPostingPreview'));
print load_fiche_titre($langs->trans('BankSyncPostingPreview').' #'.$transactionId, '', 'bank');

print '<div class="tabsAction">';
print '<a class="butAction" href="'.dol_buildpath('/banksync/reconcile.php', 1).'?mainmenu=bank&leftmenu=banksync_transactions&id='.$transactionId.'">'.$langs->trans('Back').'</a>';
print '</div>';

print '<div class="info">'.$langs->trans('BankSyncPostingPreviewHelp').'</div><br>';

print '<table class="border centpercent">';
print '<tr><td class="titlefield">'.$langs->trans('BankSyncBookingDate').'</td><td>'.dol_escape_htmltag((string) $preview['booking_date']).'</td></tr>';
print '<tr><td>'.$langs->trans('BankSyncBankEventType').'</td><td>'.dol_escape_htmltag($langs->trans('BankSyncEventType_'.(string) $transaction->bank_event_type)).'</td></tr>';
print '<tr><td>'.$langs->trans('BankSyncCounterparty').'</td><td>'.dol_escape_htmltag((string) $preview['counterparty']).'</td></tr>';
print '<tr><td>'.$langs->trans('BankSyncReference').'</td><td>'.dol_escape_htmltag((string) $preview['reference']).'</td></tr>';
print '<tr><td>'.$langs->trans('BankSyncBankAmount').'</td><td><strong>'.price($preview['signed_bank_amount']).' '.dol_escape_htmltag((string) $preview['currency']).'</strong></td></tr>';
print '<tr><td>'.$langs->trans('BankSyncDolibarrBankAccount').'</td><td>'.dol_escape_htmltag((string) $preview['bank_account_label']).'</td></tr>';
print '<tr><td>'.$langs->trans('BankSyncPostingPlannedOperation').'</td><td><strong>'.dol_escape_htmltag(banksyncPostingOperationLabel($langs, $preview['kind'])).'</strong></td></tr>';
if (!empty($preview['payment_code'])) {
    print '<tr><td>'.$langs->trans('BankSyncPostingPaymentCode').'</td><td>'.dol_escape_htmltag((string) $preview['payment_code']).'</td></tr>';
}
print '<tr><td>'.$langs->trans('Status').'</td><td>';
if (!empty($preview['postable'])) {
    print '<span class="badge badge-status4">'.$langs->trans('BankSyncPostingReady').'</span>';
} else {
    print '<span class="badge badge-status1">'.$langs->trans('BankSyncPostingBlocked').'</span>';
}
print '</td></tr>';
print '</table>';

if (!empty($preview['errors'])) {
    print '<br>'.load_fiche_titre($langs->trans('Errors'), '', 'error');
    print '<div class="error">';
    foreach (array_unique($preview['errors']) as $message) print '<div>'.dol_escape_htmltag(banksyncPostingMessage($langs, $message)).'</div>';
    print '</div>';
}

if (!empty($preview['warnings'])) {
    print '<br>'.load_fiche_titre($langs->trans('Warnings'), '', 'warning');
    print '<div class="warning">';
    foreach (array_unique($preview['warnings']) as $message) print '<div>'.dol_escape_htmltag(banksyncPostingMessage($langs, $message)).'</div>';
    print '</div>';
}

print '<br>'.load_fiche_titre($langs->trans('BankSyncPostingTarget'), '', 'payment');
print '<div class="div-table-responsive"><table class="noborder centpercent">';
print '<tr class="liste_titre"><th>'.$langs->trans('BankSyncMatchTarget').'</th><th>'.$langs->trans('Ref').'</th><th>'.$langs->trans('Label').'</th><th class="right">'.$langs->trans('BankSyncAllocation').'</th><th class="right">'.$langs->trans('BankSyncPostingRemainingBefore').'</th><th class="right">'.$langs->trans('BankSyncPostingRemainingAfter').'</th></tr>';
if (empty($preview['rows'])) {
    print '<tr><td colspan="6"><span class="opacitymedium">'.$langs->trans('BankSyncNoCandidates').'</span></td></tr>';
} else {
    foreach ($preview['rows'] as $row) {
        $targetKey = 'BankSyncTarget_'.(string) $row['target_type'];
        $targetLabel = $langs->trans($targetKey);
        if ($targetLabel === $targetKey) $targetLabel = (string) $row['target_type'];
        print '<tr class="oddeven"><td>'.dol_escape_htmltag($targetLabel).'</td><td>';
        if (!empty($row['url'])) print '<a href="'.dol_buildpath((string) $row['url'], 1).'">'.dol_escape_htmltag((string) $row['ref']).'</a>'; else print dol_escape_htmltag((string) $row['ref']);
        print '</td><td>'.dol_escape_htmltag((string) $row['label']).'</td>';
        print '<td class="right nowrap">'.price($row['allocated_amount']).' '.dol_escape_htmltag((string) $preview['currency']).'</td>';
        print '<td class="right nowrap">'.($row['remaining_before'] === null ? '—' : price($row['remaining_before']).' '.dol_escape_htmltag((string) $preview['currency'])).'</td>';
        print '<td class="right nowrap">'.($row['remaining_after'] === null ? '—' : price($row['remaining_after']).' '.dol_escape_htmltag((string) $preview['currency'])).'</td></tr>';
    }
}
print '</table></div>';

if (abs((float) $preview['rounding_difference']) > 0.00001) {
    print '<br><div class="warning">'.$langs->trans('BankSyncRoundingDifference').': <strong>'.price(abs((float) $preview['rounding_difference'])).' '.dol_escape_htmltag((string) $preview['currency']).'</strong></div>';
}

print '<br><div class="opacitymedium">'.$langs->trans('BankSyncPostingPreviewOnly').'</div>';

llxFooter();
$db->close();
