<?php
/* SPDX-License-Identifier: GPL-3.0-or-later */

/**
 * Provider-neutral bank transaction DTO.
 */
class BankTransaction
{
    public $provider = '';
    public $accountNumber = '';
    public $externalTransactionId = '';
    public $externalEntryId = '';
    public $valueDate = '';
    public $bookingDate = '';
    public $direction = '';
    public $amount = '0';
    public $currency = '';
    public $transactionType = '';
    public $transactionCode = '';
    public $counterpartyName = '';
    public $counterpartyAccount = '';
    public $reference = '';
    public $sourceLine = 0;
    public $rawData = array();
}
