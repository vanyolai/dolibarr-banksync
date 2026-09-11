<?php
/* SPDX-License-Identifier: GPL-3.0-or-later */

require_once __DIR__.'/../class/banktransaction.class.php';
require_once __DIR__.'/../class/banksynctransactionclassifier.class.php';

$classifier = new BankSyncTransactionClassifier();
$failures = array();

$fee = new BankTransaction();
$fee->provider = 'binx_csv';
$fee->transactionCode = 'CHRG';
$fee->transactionType = 'Tranzakciós díj';
$classifier->classify($fee);
if ($fee->bankEventType !== 'bank_fee' || $fee->dolibarrPaymentCode !== '') {
    $failures[] = 'CHRG classification';
}

$transfer = new BankTransaction();
$transfer->provider = 'binx_csv';
$transfer->transactionCode = 'DMCT';
$transfer->transactionType = 'E-pénz visszaváltás azonnali forint átutalással';
$classifier->classify($transfer);
if ($transfer->bankEventType !== 'transfer' || $transfer->dolibarrPaymentCode !== 'VIR') {
    $failures[] = 'DMCT classification';
}

if (!empty($failures)) {
    fwrite(STDERR, 'FAILED: '.implode(', ', $failures).PHP_EOL);
    exit(1);
}

fwrite(STDOUT, "BankSync classifier smoke test: OK\n");
