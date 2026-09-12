<?php
/* SPDX-License-Identifier: GPL-3.0-or-later */

require_once __DIR__.'/banktransaction.class.php';

/**
 * Provider-aware classification into provider-neutral bank event types.
 *
 * This deliberately classifies the bank event separately from reconciliation targets.
 * A transfer can later be matched to an invoice, salary, social contribution or other object.
 */
class BankSyncTransactionClassifier
{
    const TYPE_TRANSFER = 'transfer';
    const TYPE_CARD = 'card';
    const TYPE_DIRECT_DEBIT = 'direct_debit';
    const TYPE_BANK_FEE = 'bank_fee';
    const TYPE_CASH = 'cash';
    const TYPE_CONVERSION = 'conversion';
    const TYPE_INTERNAL_TRANSFER = 'internal_transfer';
    const TYPE_REFUND = 'refund';
    const TYPE_OTHER = 'other';

    /**
     * Mutate and return a normalized transaction with classification fields populated.
     * Provider-specific exact codes are authoritative and may replace an older heuristic
     * classification. Otherwise an existing non-empty classification is preserved.
     *
     * @param BankTransaction $transaction Transaction
     * @return BankTransaction
     */
    public function classify($transaction)
    {
        $code = strtoupper(trim((string) $transaction->transactionCode));

        if ($transaction->provider === 'binx_csv') {
            if ($code === 'CHRG') {
                return $this->apply($transaction, self::TYPE_BANK_FEE, '', 100, 'binx_code:CHRG');
            }
            if ($code === 'DMCT') {
                return $this->apply($transaction, self::TYPE_TRANSFER, 'VIR', 100, 'binx_code:DMCT');
            }
            if ($code === 'CDPT') {
                return $this->apply($transaction, self::TYPE_TRANSFER, 'VIR', 100, 'binx_code:CDPT');
            }
            if ($code === 'CAPA') {
                return $this->apply($transaction, self::TYPE_CARD, 'CB', 100, 'binx_code:CAPA');
            }
            if ($code === 'PPCF') {
                return $this->apply($transaction, self::TYPE_BANK_FEE, '', 100, 'binx_code:PPCF');
            }
        }

        if ($transaction->bankEventType !== '') {
            return $transaction;
        }

        $type = $this->lower((string) $transaction->transactionType);
        $reference = $this->lower((string) $transaction->reference);
        $text = trim($type.' '.$reference);

        if ($this->containsAny($text, array('bankkárty', 'bankkart', 'card purchase', 'card payment', 'kártyás'))) {
            return $this->apply($transaction, self::TYPE_CARD, 'CB', 85, 'text:card');
        }
        if ($this->containsAny($text, array('csoportos beszed', 'beszedés', 'beszedes', 'direct debit'))) {
            return $this->apply($transaction, self::TYPE_DIRECT_DEBIT, 'PRE', 85, 'text:direct_debit');
        }
        if ($this->containsAny($text, array('tranzakciós díj', 'tranzakcios dij', 'banki költség', 'banki koltseg', 'bankköltség', 'bankkoltseg', 'bank fee'))) {
            return $this->apply($transaction, self::TYPE_BANK_FEE, '', 90, 'text:bank_fee');
        }
        if ($this->containsAny($text, array('átutal', 'atutal', 'utalás', 'utalas', 'credit transfer', 'bank transfer'))) {
            return $this->apply($transaction, self::TYPE_TRANSFER, 'VIR', 80, 'text:transfer');
        }
        if ($this->containsAny($text, array('készpénz', 'keszpenz', 'cash withdrawal', 'cash deposit'))) {
            return $this->apply($transaction, self::TYPE_CASH, 'LIQ', 80, 'text:cash');
        }
        if ($this->containsAny($text, array('konverzió', 'konverzio', 'devizavált', 'devizavalt', 'currency conversion', 'exchange'))) {
            return $this->apply($transaction, self::TYPE_CONVERSION, '', 75, 'text:conversion');
        }
        if ($this->containsAny($text, array('visszatérítés', 'visszaterites', 'refund'))) {
            return $this->apply($transaction, self::TYPE_REFUND, '', 75, 'text:refund');
        }

        return $this->apply($transaction, self::TYPE_OTHER, '', 0, 'fallback');
    }

    private function apply($transaction, $eventType, $paymentCode, $confidence, $method)
    {
        $transaction->bankEventType = $eventType;
        $transaction->dolibarrPaymentCode = $paymentCode;
        $transaction->classificationConfidence = (int) $confidence;
        $transaction->classificationMethod = $method;
        return $transaction;
    }

    private function containsAny($haystack, array $needles)
    {
        foreach ($needles as $needle) {
            if ($needle !== '' && strpos($haystack, $this->lower($needle)) !== false) {
                return true;
            }
        }
        return false;
    }

    private function lower($value)
    {
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }
}
