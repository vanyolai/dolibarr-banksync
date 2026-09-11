<?php
/* SPDX-License-Identifier: GPL-3.0-or-later */

/**
 * Provider-neutral bank statement DTO.
 */
class BankStatement
{
    public $provider = '';
    public $accountNumber = '';
    public $conversionAccountNumber = '';
    public $currency = '';
    public $periodStart = '';
    public $periodEnd = '';
    public $metadata = array();
    public $transactions = array();

    /**
     * @param BankTransaction $transaction Transaction to append
     * @return void
     */
    public function addTransaction($transaction)
    {
        $this->transactions[] = $transaction;
    }
}
