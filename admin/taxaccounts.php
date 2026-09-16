<?php
/* SPDX-License-Identifier: GPL-3.0-or-later */

$res = 0;
if (!$res && file_exists('../../../main.inc.php')) $res = @include '../../../main.inc.php';
if (!$res && file_exists('../../../../main.inc.php')) $res = @include '../../../../main.inc.php';
if (!$res) die('Include of main fails');

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once __DIR__.'/../lib/banksync.lib.php';
require_once __DIR__.'/../class/banksyncschema.class.php';
require_once __DIR__.'/../class/banksynctaxaccountmanager.class.php';

$langs->load('banksync@banksync');
if (!$user->admin) accessforbidden();

try {
    BankSyncSchema::ensure($db);
} catch (Exception $e) {
    setEventMessages($langs->trans('BankSyncSchemaMigrationFailed', $e->getMessage()), null, 'errors');
}

$manager = new BankSyncTaxAccountManager($db, (int) $conf->entity);
$action = GETPOST('action', 'aZ09');

if ($action === 'save') {
    try {
        $manager->save(
            GETPOST('target_account_number', 'alphanohtml'),
            GETPOSTINT('fk_charge_type'),
            GETPOST('mapping_label', 'alphanohtml'),
            (int) $user->id
        );
        setEventMessages($langs->trans('BankSyncTaxAccountMappingSaved'), null, 'mesgs');
    } catch (Throwable $e) {
        setEventMessages($langs->trans('BankSyncTaxAccountMappingFailed', $e->getMessage()), null, 'errors');
    }
}
if ($action === 'delete') {
    try {
        $manager->delete(GETPOSTINT('id'));
        setEventMessages($langs->trans('BankSyncTaxAccountMappingDeleted'), null, 'mesgs');
    } catch (Throwable $e) {
        setEventMessages($langs->trans('BankSyncTaxAccountMappingFailed', $e->getMessage()), null, 'errors');
    }
}

$mappings = array();
$types = array();
$observed = array();
try {
    $mappings = $manager->getMappings();
    $types = $manager->getChargeTypes();
    $observed = $manager->getUnmappedObservedAccounts(30);
} catch (Throwable $e) {
    setEventMessages($langs->trans('BankSyncTaxAccountMappingFailed', $e->getMessage()), null, 'errors');
}

$prefillAccount = GETPOST('prefill_account', 'alphanohtml');
$prefillLabel = GETPOST('prefill_label', 'alphanohtml');

llxHeader('', $langs->trans('BankSyncTaxAccountMappings'));
$head = banksyncAdminPrepareHead();
print dol_get_fiche_head($head, 'taxaccounts', $langs->trans('BankSync'), -1, 'bank');

print '<div class="opacitymedium">'.$langs->trans('BankSyncTaxAccountMappingsHelp').'</div><br>';

print '<form method="POST" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="save">';
print '<table class="border centpercent">';
print '<tr><td class="titlefield fieldrequired">'.$langs->trans('BankSyncTaxDestinationAccount').'</td>';
print '<td><input type="text" class="minwidth300" name="target_account_number" value="'.dol_escape_htmltag($prefillAccount).'" placeholder="10032000..."> <span class="opacitymedium">'.$langs->trans('BankSyncTaxDestinationAccountHelp').'</span></td></tr>';
print '<tr><td class="fieldrequired">'.$langs->trans('BankSyncTaxContributionType').'</td><td>';
print '<select name="fk_charge_type" class="minwidth300">';
print '<option value="0">'.$langs->trans('BankSyncSelectTaxContributionType').'</option>';
foreach ($types as $type) {
    $label = trim((string) $type->libelle);
    if (trim((string) $type->code) !== '') $label .= ' ['.(string) $type->code.']';
    print '<option value="'.((int) $type->id).'">'.dol_escape_htmltag($label).'</option>';
}
print '</select></td></tr>';
print '<tr><td>'.$langs->trans('Label').'</td><td><input type="text" class="minwidth300" name="mapping_label" value="'.dol_escape_htmltag($prefillLabel).'" placeholder="NAV TB / NAV SZJA / NAV Szocho"></td></tr>';
print '</table>';
print '<div class="center"><button type="submit" class="button button-save">'.$langs->trans('BankSyncAddOrUpdateMapping').'</button></div>';
print '</form>';

print '<br>'.load_fiche_titre($langs->trans('BankSyncConfiguredTaxAccounts'), '', 'bank');
print '<div class="div-table-responsive"><table class="noborder centpercent">';
print '<tr class="liste_titre"><th>'.$langs->trans('BankSyncTaxDestinationAccount').'</th><th>'.$langs->trans('BankSyncTaxContributionType').'</th><th>'.$langs->trans('Label').'</th><th class="center">'.$langs->trans('Status').'</th><th class="right">'.$langs->trans('Action').'</th></tr>';
if (empty($mappings)) {
    print '<tr><td colspan="5"><span class="opacitymedium">'.$langs->trans('BankSyncNoTaxAccountMappings').'</span></td></tr>';
} else {
    foreach ($mappings as $mapping) {
        $typeLabel = trim((string) $mapping->type_label);
        if (trim((string) $mapping->type_code) !== '') $typeLabel .= ' ['.(string) $mapping->type_code.']';
        print '<tr class="oddeven">';
        print '<td><strong>'.dol_escape_htmltag((string) $mapping->target_account_number).'</strong></td>';
        print '<td>'.dol_escape_htmltag($typeLabel).'</td>';
        print '<td>'.dol_escape_htmltag((string) $mapping->label).'</td>';
        print '<td class="center"><span class="badge badge-status4">'.$langs->trans('Enabled').'</span></td>';
        $deleteUrl = $_SERVER['PHP_SELF'].'?action=delete&id='.(int) $mapping->rowid.'&token='.newToken();
        print '<td class="right"><a class="buttonDelete" href="'.dol_escape_htmltag($deleteUrl).'">'.$langs->trans('Delete').'</a></td>';
        print '</tr>';
    }
}
print '</table></div>';

print '<br>'.load_fiche_titre($langs->trans('BankSyncObservedTaxAccounts'), '', 'search');
print '<div class="opacitymedium">'.$langs->trans('BankSyncObservedTaxAccountsHelp').'</div>';
print '<div class="div-table-responsive"><table class="noborder centpercent">';
print '<tr class="liste_titre"><th>'.$langs->trans('BankSyncTaxDestinationAccount').'</th><th>'.$langs->trans('BankSyncCounterparty').'</th><th>'.$langs->trans('BankSyncLastSeen').'</th><th class="right">'.$langs->trans('BankSyncTransactionCountShort').'</th><th class="right">'.$langs->trans('Action').'</th></tr>';
if (empty($observed)) {
    print '<tr><td colspan="5"><span class="opacitymedium">'.$langs->trans('BankSyncNoObservedTaxAccounts').'</span></td></tr>';
} else {
    foreach ($observed as $row) {
        $prefillUrl = $_SERVER['PHP_SELF'].'?prefill_account='.rawurlencode((string) $row->counterparty_account).'&prefill_label='.rawurlencode((string) $row->counterparty_name);
        print '<tr class="oddeven"><td>'.dol_escape_htmltag((string) $row->counterparty_account).'</td>';
        print '<td>'.dol_escape_htmltag((string) $row->counterparty_name).'</td>';
        print '<td>'.dol_escape_htmltag((string) $row->last_date).'</td>';
        print '<td class="right">'.((int) $row->tx_count).'</td>';
        print '<td class="right"><a class="button" href="'.dol_escape_htmltag($prefillUrl).'">'.$langs->trans('BankSyncUseForMapping').'</a></td></tr>';
    }
}
print '</table></div>';

print dol_get_fiche_end();
llxFooter();
$db->close();
