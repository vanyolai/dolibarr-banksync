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
require_once __DIR__.'/class/banksyncaccountmanager.class.php';

$langs->loadLangs(array('banks', 'banksync@banksync'));

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
$action = GETPOST('action', 'aZ09');
$manager = new BankSyncAccountManager($db, $entity, $user->id);

if (($action === 'map' || $action === 'clear') && !$user->hasRight('banksync', 'import')) {
    accessforbidden();
}

if ($action === 'map') {
    try {
        $manager->setMapping(GETPOSTINT('source_account_id'), GETPOSTINT('bank_account_id'), $user->id);
        setEventMessages($langs->trans('BankSyncAccountMappingSaved'), null, 'mesgs');
    } catch (Exception $e) {
        setEventMessages($langs->trans('BankSyncAccountMappingError', $e->getMessage()), null, 'errors');
    }
} elseif ($action === 'clear') {
    try {
        $manager->clearMapping(GETPOSTINT('source_account_id'), $user->id);
        setEventMessages($langs->trans('BankSyncAccountMappingCleared'), null, 'mesgs');
    } catch (Exception $e) {
        setEventMessages($langs->trans('BankSyncAccountMappingError', $e->getMessage()), null, 'errors');
    }
}

$bankAccounts = array();
$sql = 'SELECT rowid, ref, label, bank, number, iban_prefix, currency_code, clos';
$sql .= ' FROM '.$db->prefix().'bank_account';
$sql .= ' WHERE entity = '.$entity.' AND clos = 0';
$sql .= ' ORDER BY label ASC, ref ASC';
$resql = $db->query($sql);
if ($resql) {
    while ($obj = $db->fetch_object($resql)) {
        $bankAccounts[(int) $obj->rowid] = $obj;
    }
    $db->free($resql);
}

$sql = 'SELECT a.rowid, a.provider, a.source_account_number, a.conversion_account_number, a.source_account_label, a.currency,';
$sql .= ' a.fk_bank_account, a.suggested_fk_bank_account, a.mapping_status, a.mapping_method, a.date_creation';
$sql .= ' FROM '.$db->prefix().'banksync_account AS a';
$sql .= ' WHERE a.entity = '.$entity;
$sql .= ' ORDER BY a.provider ASC, a.source_account_number ASC, a.currency ASC';
$resql = $db->query($sql);

llxHeader('', $langs->trans('BankSyncAccounts'));
print load_fiche_titre($langs->trans('BankSyncAccounts'), '', 'bank');
print '<div class="opacitymedium">'.$langs->trans('BankSyncAccountsHelp').'</div><br>';

print '<div class="div-table-responsive">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<th>'.$langs->trans('BankSyncProvider').'</th>';
print '<th>'.$langs->trans('BankSyncSourceAccount').'</th>';
print '<th>'.$langs->trans('Currency').'</th>';
print '<th>'.$langs->trans('BankSyncDolibarrBankAccount').'</th>';
print '<th>'.$langs->trans('Status').'</th>';
print '<th class="right">'.$langs->trans('Action').'</th>';
print '</tr>';

$found = false;
if ($resql) {
    while ($obj = $db->fetch_object($resql)) {
        $found = true;
        $mappedId = (int) $obj->fk_bank_account;
        $suggestedId = (int) $obj->suggested_fk_bank_account;
        $selectedId = $mappedId > 0 ? $mappedId : $suggestedId;

        print '<tr class="oddeven">';
        print '<td>'.dol_escape_htmltag($obj->provider).'</td>';
        print '<td><strong>'.dol_escape_htmltag($obj->source_account_number).'</strong>';
        if (!empty($obj->conversion_account_number)) {
            print '<br><span class="opacitymedium small">'.$langs->trans('BankSyncConversionAccount').': '.dol_escape_htmltag($obj->conversion_account_number).'</span>';
        }
        print '</td>';
        print '<td>'.dol_escape_htmltag($obj->currency).'</td>';
        print '<td>';

        if ($user->hasRight('banksync', 'import')) {
            print '<form method="POST" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'" class="inline-block">';
            print '<input type="hidden" name="token" value="'.newToken().'">';
            print '<input type="hidden" name="action" value="map">';
            print '<input type="hidden" name="source_account_id" value="'.((int) $obj->rowid).'">';
            print '<select name="bank_account_id" class="flat minwidth300" required>';
            print '<option value="">'.$langs->trans('BankSyncSelectDolibarrBankAccount').'</option>';
            foreach ($bankAccounts as $bankId => $bank) {
                $label = trim((string) $bank->label);
                if ($label === '') {
                    $label = trim((string) $bank->ref);
                }
                $number = trim((string) $bank->number);
                $iban = trim((string) $bank->iban_prefix);
                $accountText = $number !== '' ? $number : $iban;
                $currency = trim((string) $bank->currency_code);
                $display = $label;
                if ($accountText !== '') {
                    $display .= ' — '.$accountText;
                }
                if ($currency !== '') {
                    $display .= ' ('.$currency.')';
                }
                print '<option value="'.$bankId.'"'.($bankId === $selectedId ? ' selected' : '').'>'.dol_escape_htmltag($display).'</option>';
            }
            print '</select> ';
            print '<button type="submit" class="button button-save">'.$langs->trans('Save').'</button>';
            print '</form>';
        } elseif ($mappedId > 0 && isset($bankAccounts[$mappedId])) {
            print dol_escape_htmltag($bankAccounts[$mappedId]->label);
        } else {
            print '<span class="opacitymedium">'.$langs->trans('BankSyncUnmapped').'</span>';
        }

        if ($mappedId === 0 && $suggestedId > 0 && isset($bankAccounts[$suggestedId])) {
            print '<br><span class="opacitymedium small">'.$langs->trans('BankSyncExactAccountSuggestion').'</span>';
        }
        print '</td>';

        print '<td>';
        if ($mappedId > 0) {
            print '<span class="badge badge-status4">'.$langs->trans('BankSyncMapped').'</span>';
        } elseif ($suggestedId > 0) {
            print '<span class="badge badge-status1">'.$langs->trans('BankSyncSuggested').'</span>';
        } else {
            print '<span class="badge badge-status0">'.$langs->trans('BankSyncUnmapped').'</span>';
        }
        print '</td>';

        print '<td class="right">';
        if ($mappedId > 0 && $user->hasRight('banksync', 'import')) {
            print '<form method="POST" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'" class="inline-block">';
            print '<input type="hidden" name="token" value="'.newToken().'">';
            print '<input type="hidden" name="action" value="clear">';
            print '<input type="hidden" name="source_account_id" value="'.((int) $obj->rowid).'">';
            print '<button type="submit" class="button">'.$langs->trans('BankSyncClearMapping').'</button>';
            print '</form>';
        }
        print '</td>';
        print '</tr>';
    }
    $db->free($resql);
}

if (!$found) {
    print '<tr><td colspan="6"><span class="opacitymedium">'.$langs->trans('BankSyncNoSourceAccounts').'</span></td></tr>';
}
print '</table>';
print '</div>';

llxFooter();
$db->close();
