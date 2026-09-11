<?php
/* SPDX-License-Identifier: GPL-3.0-or-later */

$res = 0;
if (!$res && file_exists('../../../main.inc.php')) {
    $res = @include '../../../main.inc.php';
}
if (!$res && file_exists('../../../../main.inc.php')) {
    $res = @include '../../../../main.inc.php';
}
if (!$res) {
    die('Include of main fails');
}

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once __DIR__.'/../lib/banksync.lib.php';

$langs->load('banksync@banksync');

if (!$user->admin) {
    accessforbidden();
}

llxHeader('', $langs->trans('BankSyncSetup'));

$head = banksyncAdminPrepareHead();
print dol_get_fiche_head($head, 'settings', $langs->trans('BankSync'), -1, 'bank');

print '<div class="opacitymedium">'.$langs->trans('BankSyncSetupHelp').'</div><br>';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><th>'.$langs->trans('Parameter').'</th><th>'.$langs->trans('Value').'</th></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('BankSyncVersion').'</td><td>0.1.0</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('BankSyncAvailableProviders').'</td><td>BinX CSV</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('BankSyncPostingMode').'</td><td>'.$langs->trans('BankSyncStagingOnly').'</td></tr>';
print '</table>';

print dol_get_fiche_end();
llxFooter();
$db->close();
