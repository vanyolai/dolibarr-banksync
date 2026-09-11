<?php
/* SPDX-License-Identifier: GPL-3.0-or-later */

require_once __DIR__.'/../class/provider/binxcsvprovider.class.php';

$provider = new BinxCsvProvider();
$statement = $provider->fetch(array('path' => __DIR__.'/fixtures/binx-sample.csv'));

$failures = array();
if ($statement->accountNumber !== '11111111') {
    $failures[] = 'account number';
}
if ($statement->currency !== 'HUF') {
    $failures[] = 'currency';
}
if (count($statement->transactions) !== 2) {
    $failures[] = 'transaction count';
}
if (count($statement->transactions) === 2) {
    if ($statement->transactions[0]->externalTransactionId !== $statement->transactions[1]->externalTransactionId) {
        $failures[] = 'shared BinX transaction id';
    }
    if ($statement->transactions[0]->externalEntryId === $statement->transactions[1]->externalEntryId) {
        $failures[] = 'entry-level deduplication id';
    }
}

if (!empty($failures)) {
    fwrite(STDERR, 'FAILED: '.implode(', ', $failures).PHP_EOL);
    exit(1);
}

fwrite(STDOUT, "BinX parser smoke test: OK\n");
