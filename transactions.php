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

$page = max(0, GETPOSTINT('page'));
$limit = 50;
$offset = $page * $limit;
$entity = (int) $conf->entity;

$sql = 'SELECT t.rowid, t.booking_date, t.value_date, t.direction, t.amount, t.currency, t.transaction_type, t.transaction_code,';
$sql .= ' t.counterparty_name, t.counterparty_account, t.reference, t.external_transaction_id, t.status, t.fk_import,';
$sql .= ' t.bank_event_type, t.dolibarr_payment_code, t.classification_confidence, t.classification_method, t.fk_bank,';
$sql .= ' a.source_account_number, a.mapping_status, a.fk_bank_account,';
$sql .= ' ba.label AS bank_account_label, ba.ref AS bank_account_ref';
$sql .= ' FROM '.$db->prefix().'banksync_transaction AS t';
$sql .= ' LEFT JOIN '.$db->prefix().'banksync_account AS a ON a.rowid = t.fk_banksync_account';
$sql .= ' LEFT JOIN '.$db->prefix().'bank_account AS ba ON ba.rowid = a.fk_bank_account';
$sql .= ' WHERE t.entity = '.$entity;
$sql .= ' ORDER BY t.booking_date DESC, t.rowid DESC';
$sql .= $db->plimit($limit + 1, $offset);
$resql = $db->query($sql);

llxHeader('', $langs->trans('BankSyncTransactions'));
print load_fiche_titre($langs->trans('BankSyncTransactions'), '', 'bank');

print '<div class="div-table-responsive">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<th>'.$langs->trans('BankSyncBookingDate').'</th>';
print '<th>'.$langs->trans('BankSyncBankEventType').'</th>';
print '<th>'.$langs->trans('BankSyncTransactionCode').'</th>';
print '<th>'.$langs->trans('BankSyncCounterparty').'</th>';
print '<th>'.$langs->trans('BankSyncReference').'</th>';
print '<th>'.$langs->trans('BankSyncDolibarrBankAccount').'</th>';
print '<th class="right">'.$langs->trans('Amount').'</th>';
print '<th>'.$langs->trans('Status').'</th>';
print '</tr>';

$num = 0;
$hasMore = false;
if ($resql) {
    while ($obj = $db->fetch_object($resql)) {
        if ($num >= $limit) {
            $hasMore = true;
            break;
        }
        $num++;

        print '<tr class="oddeven">';
        print '<td>'.dol_escape_htmltag((string) $obj->booking_date).'</td>';
        print '<td>';
        $eventType = !empty($obj->bank_event_type) ? $obj->bank_event_type : 'other';
        print dol_escape_htmltag($langs->trans('BankSyncEventType_'.$eventType));
        if (!empty($obj->dolibarr_payment_code)) {
            print '<br><span class="opacitymedium small">'.dol_escape_htmltag($obj->dolibarr_payment_code).'</span>';
        }
        print '</td>';
        print '<td title="'.dol_escape_htmltag($obj->transaction_type).'">'.dol_escape_htmltag($obj->transaction_code).'</td>';
        print '<td>'.dol_escape_htmltag($obj->counterparty_name);
        if (!empty($obj->counterparty_account)) {
            print '<br><span class="opacitymedium small">'.dol_escape_htmltag($obj->counterparty_account).'</span>';
        }
        print '</td>';
        print '<td>'.dol_escape_htmltag($obj->reference).'</td>';
        print '<td>';
        if (!empty($obj->fk_bank_account)) {
            $bankLabel = trim((string) $obj->bank_account_label);
            if ($bankLabel === '') {
                $bankLabel = trim((string) $obj->bank_account_ref);
            }
            print dol_escape_htmltag($bankLabel);
        } else {
            print '<a href="'.dol_buildpath('/banksync/accounts.php', 1).'?mainmenu=bank&leftmenu=banksync_accounts" class="error">'.$langs->trans('BankSyncUnmapped').'</a>';
        }
        if (!empty($obj->source_account_number)) {
            print '<br><span class="opacitymedium small">'.dol_escape_htmltag($obj->source_account_number).'</span>';
        }
        print '</td>';
        print '<td class="right nowrap">'.price($obj->amount).' '.dol_escape_htmltag($obj->currency).'</td>';
        print '<td>'.dol_escape_htmltag($obj->status).'</td>';
        print '</tr>';
    }
    $db->free($resql);
}

if ($num === 0) {
    print '<tr><td colspan="8"><span class="opacitymedium">'.$langs->trans('BankSyncNoTransactions').'</span></td></tr>';
}
print '</table>';
print '</div>';

print '<div class="pagination">';
if ($page > 0) {
    print '<a class="button" href="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'?mainmenu=bank&leftmenu=banksync_transactions&page='.($page - 1).'">&laquo; '.$langs->trans('Previous').'</a> ';
}
if ($hasMore) {
    print '<a class="button" href="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'?mainmenu=bank&leftmenu=banksync_transactions&page='.($page + 1).'">'.$langs->trans('Next').' &raquo;</a>';
}
print '</div>';

llxFooter();
$db->close();
