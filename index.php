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

$langs->load('banksync@banksync');

if (!isModEnabled('banksync')) {
    accessforbidden('BankSync module is not enabled.');
}
if (!$user->hasRight('banksync', 'read')) {
    accessforbidden();
}

$entity = (int) $conf->entity;
$stats = array('imports' => 0, 'transactions' => 0, 'new_transactions' => 0);

$sql = 'SELECT COUNT(*) AS nb FROM '.$db->prefix().'banksync_import WHERE entity = '.$entity;
$resql = $db->query($sql);
if ($resql && ($obj = $db->fetch_object($resql))) {
    $stats['imports'] = (int) $obj->nb;
}

$sql = 'SELECT COUNT(*) AS nb, SUM(CASE WHEN status = \'new\' THEN 1 ELSE 0 END) AS nbnew';
$sql .= ' FROM '.$db->prefix().'banksync_transaction WHERE entity = '.$entity;
$resql = $db->query($sql);
if ($resql && ($obj = $db->fetch_object($resql))) {
    $stats['transactions'] = (int) $obj->nb;
    $stats['new_transactions'] = (int) $obj->nbnew;
}

llxHeader('', $langs->trans('BankSync'));
print load_fiche_titre($langs->trans('BankSync'), '', 'bank');

print '<div class="fichecenter">';
print '<div class="fichehalfleft">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><th colspan="2">'.$langs->trans('BankSyncOverview').'</th></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('BankSyncImports').'</td><td class="right">'.((int) $stats['imports']).'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('BankSyncTransactions').'</td><td class="right">'.((int) $stats['transactions']).'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('BankSyncNewTransactions').'</td><td class="right">'.((int) $stats['new_transactions']).'</td></tr>';
print '</table>';
print '</div>';
print '<div class="fichehalfright">';
print '<div class="center">';
if ($user->hasRight('banksync', 'import')) {
    print '<a class="butAction" href="'.dol_buildpath('/banksync/import.php', 1).'">'.$langs->trans('BankSyncImportStatement').'</a>';
}
print '<a class="butAction" href="'.dol_buildpath('/banksync/transactions.php', 1).'">'.$langs->trans('BankSyncViewTransactions').'</a>';
print '</div>';
print '</div>';
print '</div>';

print '<div class="clearboth"></div><br>';
print load_fiche_titre($langs->trans('BankSyncRecentImports'), '', 'history');

$sql = 'SELECT rowid, provider, source_filename, account_number, currency, period_start, period_end, date_creation, status, transaction_count, imported_count, skipped_count';
$sql .= ' FROM '.$db->prefix().'banksync_import';
$sql .= ' WHERE entity = '.$entity;
$sql .= ' ORDER BY rowid DESC';
$sql .= $db->plimit(10, 0);
$resql = $db->query($sql);

print '<div class="div-table-responsive">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<th>'.$langs->trans('Date').'</th>';
print '<th>'.$langs->trans('BankSyncProvider').'</th>';
print '<th>'.$langs->trans('BankSyncSourceFile').'</th>';
print '<th>'.$langs->trans('BankSyncAccount').'</th>';
print '<th>'.$langs->trans('BankSyncPeriod').'</th>';
print '<th class="right">'.$langs->trans('BankSyncImported').'</th>';
print '<th class="right">'.$langs->trans('BankSyncSkipped').'</th>';
print '</tr>';

if ($resql) {
    $found = false;
    while ($obj = $db->fetch_object($resql)) {
        $found = true;
        print '<tr class="oddeven">';
        print '<td>'.dol_print_date($db->jdate($obj->date_creation), 'dayhour').'</td>';
        print '<td>'.dol_escape_htmltag($obj->provider).'</td>';
        print '<td>'.dol_escape_htmltag($obj->source_filename).'</td>';
        print '<td>'.dol_escape_htmltag($obj->account_number).' ('.dol_escape_htmltag($obj->currency).')</td>';
        print '<td>'.dol_escape_htmltag((string) $obj->period_start).' - '.dol_escape_htmltag((string) $obj->period_end).'</td>';
        print '<td class="right">'.((int) $obj->imported_count).' / '.((int) $obj->transaction_count).'</td>';
        print '<td class="right">'.((int) $obj->skipped_count).'</td>';
        print '</tr>';
    }
    if (!$found) {
        print '<tr><td colspan="7"><span class="opacitymedium">'.$langs->trans('BankSyncNoImports').'</span></td></tr>';
    }
} else {
    print '<tr><td colspan="7" class="error">'.dol_escape_htmltag($db->lasterror()).'</td></tr>';
}
print '</table>';
print '</div>';

llxFooter();
$db->close();
