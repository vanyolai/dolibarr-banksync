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

require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
require_once __DIR__.'/class/provider/binxcsvprovider.class.php';
require_once __DIR__.'/class/banksyncimporter.class.php';

$langs->load('banksync@banksync');

if (!isModEnabled('banksync')) {
    accessforbidden('BankSync module is not enabled.');
}
if (!$user->hasRight('banksync', 'import')) {
    accessforbidden();
}

$action = GETPOST('action', 'aZ09');
$providerKey = GETPOST('provider', 'aZ09');
if ($providerKey === '') {
    $providerKey = BinxCsvProvider::PROVIDER_KEY;
}

$lastResult = null;
$statement = null;

if ($action === 'import') {
    if ($providerKey !== BinxCsvProvider::PROVIDER_KEY) {
        setEventMessages($langs->trans('BankSyncUnsupportedProvider'), null, 'errors');
    } elseif (empty($_FILES['statement_file']) || !isset($_FILES['statement_file']['error'])) {
        setEventMessages($langs->trans('BankSyncFileRequired'), null, 'errors');
    } elseif ((int) $_FILES['statement_file']['error'] !== UPLOAD_ERR_OK) {
        setEventMessages($langs->trans('BankSyncUploadFailed', (int) $_FILES['statement_file']['error']), null, 'errors');
    } elseif ((int) $_FILES['statement_file']['size'] > 10 * 1024 * 1024) {
        setEventMessages($langs->trans('BankSyncFileTooLarge'), null, 'errors');
    } else {
        $originalName = dol_sanitizeFileName($_FILES['statement_file']['name']);
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        if ($extension !== 'csv') {
            setEventMessages($langs->trans('BankSyncCsvRequired'), null, 'errors');
        } else {
            $tempDir = DOL_DATA_ROOT.'/banksync/temp';
            if (dol_mkdir($tempDir) < 0 && !is_dir($tempDir)) {
                setEventMessages($langs->trans('ErrorFailedToCreateDir', $tempDir), null, 'errors');
            } else {
                $tempPath = $tempDir.'/'.dol_print_date(dol_now(), '%Y%m%d%H%M%S').'-'.uniqid('', true).'-'.$originalName;
                $moveResult = dol_move_uploaded_file($_FILES['statement_file']['tmp_name'], $tempPath, 1, 0, $_FILES['statement_file']['error']);

                if (!is_numeric($moveResult) || (int) $moveResult <= 0) {
                    $moveError = is_string($moveResult) ? ': '.$moveResult : '';
                    setEventMessages($langs->trans('ErrorFailedToSaveFile').$moveError, null, 'errors');
                } else {
                    try {
                        $sourceHash = hash_file('sha256', $tempPath);
                        if ($sourceHash === false) {
                            throw new RuntimeException('Unable to hash uploaded CSV.');
                        }

                        $provider = new BinxCsvProvider();
                        $statement = $provider->fetch(array('path' => $tempPath));

                        $importer = new BankSyncImporter($db, $user->id, $conf->entity);
                        $lastResult = $importer->importStatement($statement, $originalName, $sourceHash);

                        if (!empty($lastResult['duplicate_file'])) {
                            setEventMessages($langs->trans('BankSyncDuplicateFile', $lastResult['import_id']), null, 'warnings');
                        } else {
                            setEventMessages(
                                $langs->trans('BankSyncImportSuccess', $lastResult['imported_count'], $lastResult['skipped_count']),
                                null,
                                'mesgs'
                            );
                        }
                    } catch (Exception $e) {
                        dol_syslog('BankSync import failed: '.$e->getMessage(), LOG_ERR);
                        setEventMessages($langs->trans('BankSyncImportError', $e->getMessage()), null, 'errors');
                    }

                    dol_delete_file($tempPath);
                }
            }
        }
    }
}

llxHeader('', $langs->trans('BankSyncImport'));
print load_fiche_titre($langs->trans('BankSyncImport'), '', 'upload');

print '<div class="opacitymedium">'.$langs->trans('BankSyncImportHelp').'</div><br>';

print '<form method="POST" enctype="multipart/form-data" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="import">';

print '<table class="border centpercent">';
print '<tr><td class="titlefieldcreate fieldrequired">'.$langs->trans('BankSyncProvider').'</td><td>';
print '<select name="provider" class="flat minwidth200">';
print '<option value="binx_csv"'.($providerKey === 'binx_csv' ? ' selected' : '').'>BinX CSV</option>';
print '</select>';
print '</td></tr>';
print '<tr><td class="fieldrequired">'.$langs->trans('BankSyncStatementFile').'</td><td>';
print '<input type="file" name="statement_file" accept=".csv,text/csv" required>';
print ' <span class="opacitymedium">'.$langs->trans('BankSyncMaxFileSize').'</span>';
print '</td></tr>';
print '</table>';

print '<div class="center">';
print '<input type="submit" class="button button-save" value="'.$langs->trans('BankSyncImportToStaging').'">';
print '</div>';
print '</form>';

if ($statement !== null && $lastResult !== null) {
    print '<br>';
    print load_fiche_titre($langs->trans('BankSyncParsedStatement'), '', 'bank');
    print '<table class="border centpercent">';
    print '<tr><td class="titlefield">'.$langs->trans('BankSyncAccount').'</td><td>'.dol_escape_htmltag($statement->accountNumber).'</td></tr>';
    print '<tr><td>'.$langs->trans('Currency').'</td><td>'.dol_escape_htmltag($statement->currency).'</td></tr>';
    print '<tr><td>'.$langs->trans('BankSyncPeriod').'</td><td>'.dol_escape_htmltag($statement->periodStart).' - '.dol_escape_htmltag($statement->periodEnd).'</td></tr>';
    print '<tr><td>'.$langs->trans('BankSyncTransactions').'</td><td>'.count($statement->transactions).'</td></tr>';
    print '<tr><td>'.$langs->trans('BankSyncImported').'</td><td>'.((int) $lastResult['imported_count']).'</td></tr>';
    print '<tr><td>'.$langs->trans('BankSyncSkipped').'</td><td>'.((int) $lastResult['skipped_count']).'</td></tr>';
    print '</table>';

    print '<div class="center"><a class="butAction" href="'.dol_buildpath('/banksync/transactions.php', 1).'">'.$langs->trans('BankSyncViewTransactions').'</a></div>';
}

llxFooter();
$db->close();
