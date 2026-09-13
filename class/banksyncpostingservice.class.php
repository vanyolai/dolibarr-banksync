<?php
/* SPDX-License-Identifier: GPL-3.0-or-later */

require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.facture.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';
require_once __DIR__.'/banksyncmatchmanager.class.php';

/**
 * Builds a read-only preview of the native Dolibarr posting that would be created
 * from a reconciled BankSync transaction.
 *
 * IMPORTANT: this class deliberately does not write to Dolibarr core tables.
 * Native posting must later be implemented through Dolibarr business objects
 * (Paiement, PaiementFourn, Account::addline(), addPaymentToBank(), ...).
 */
class BankSyncPostingService
{
    /** @var DoliDB */
    private $db;
    /** @var int */
    private $entity;
    /** @var BankSyncMatchManager */
    private $matchManager;

    public function __construct($db, $entity)
    {
        $this->db = $db;
        $this->entity = (int) $entity;
        $this->matchManager = new BankSyncMatchManager($db, $entity);
    }

    /**
     * Build a read-only posting preview.
     *
     * @param object $transaction BankSync staging transaction
     * @return array<string,mixed>
     */
    public function buildPreview($transaction)
    {
        $preview = array(
            'postable' => false,
            'kind' => '',
            'payment_code' => '',
            'bank_account_id' => !empty($transaction->fk_bank_account) ? (int) $transaction->fk_bank_account : 0,
            'bank_account_label' => trim((string) (!empty($transaction->bank_account_label) ? $transaction->bank_account_label : $transaction->bank_account_ref)),
            'bank_amount' => abs((float) $transaction->amount),
            'signed_bank_amount' => (float) $transaction->amount,
            'currency' => (string) $transaction->currency,
            'booking_date' => (string) $transaction->booking_date,
            'reference' => (string) $transaction->reference,
            'counterparty' => (string) $transaction->counterparty_name,
            'rows' => array(),
            'warnings' => array(),
            'errors' => array(),
            'rounding_difference' => 0.0,
        );

        if ($preview['bank_account_id'] <= 0) {
            $preview['errors'][] = 'BankSyncPostingMissingBankAccount';
        } else {
            $account = new Account($this->db);
            if ($account->fetch($preview['bank_account_id']) <= 0) {
                $preview['errors'][] = 'BankSyncPostingBankAccountNotFound';
            } else {
                $preview['bank_account_label'] = trim((string) $account->label) !== '' ? (string) $account->label : (string) $account->ref;
                $accountCurrency = trim((string) $account->currency_code);
                if ($accountCurrency !== '' && strtoupper($accountCurrency) !== strtoupper($preview['currency'])) {
                    $preview['warnings'][] = 'BankSyncPostingBankCurrencyMismatch';
                }
            }
        }

        // Bank fees do not need a business-object match. They will later be posted
        // through the native Account::addline() API as their own bank movement.
        if ((string) $transaction->bank_event_type === 'bank_fee') {
            $preview['kind'] = BankSyncMatchManager::TARGET_BANK_FEE;
            $preview['rows'][] = array(
                'target_type' => BankSyncMatchManager::TARGET_BANK_FEE,
                'target_id' => 0,
                'ref' => (string) $transaction->transaction_code,
                'label' => (string) $transaction->transaction_type,
                'thirdparty' => (string) $transaction->counterparty_name,
                'allocated_amount' => abs((float) $transaction->amount),
                'remaining_before' => null,
                'remaining_after' => null,
                'url' => '',
            );
            $preview['postable'] = empty($preview['errors']);
            return $preview;
        }

        $summary = $this->matchManager->getAllocationSummary((int) $transaction->rowid);
        $preview['rounding_difference'] = (float) $summary['rounding_difference'];
        if (empty($summary['balanced'])) {
            $preview['errors'][] = 'BankSyncPostingTransactionNotBalanced';
        }
        // Until an accountant-approved rounding posting rule exists, do not create
        // a native payment whose bank line would differ from the actual bank amount.
        if (abs((float) $summary['rounding_difference']) > 0.00001) {
            $preview['errors'][] = 'BankSyncPostingRoundingPolicyRequired';
        }

        $confirmed = array();
        foreach ($this->matchManager->getForTransaction((int) $transaction->rowid) as $match) {
            if ((string) $match->status === 'confirmed') $confirmed[] = $match;
            if ((string) $match->status === 'posted') $preview['errors'][] = 'BankSyncPostingAlreadyPosted';
        }
        if (empty($confirmed)) {
            $preview['errors'][] = 'BankSyncPostingNoConfirmedMatches';
            return $preview;
        }

        $targetType = (string) $confirmed[0]->target_type;
        if (!in_array($targetType, array(BankSyncMatchManager::TARGET_CUSTOMER_INVOICE, BankSyncMatchManager::TARGET_SUPPLIER_INVOICE), true)) {
            $preview['errors'][] = 'BankSyncPostingTargetNotSupportedYet';
            return $preview;
        }
        $preview['kind'] = $targetType;
        $preview['payment_code'] = trim((string) $transaction->dolibarr_payment_code);
        if ($preview['payment_code'] === '') {
            $preview['errors'][] = 'BankSyncPostingMissingPaymentCode';
        } else {
            $paymentModeId = dol_getIdFromCode($this->db, $preview['payment_code'], 'c_paiement', 'code', 'id', 1);
            if ((int) $paymentModeId <= 0) $preview['errors'][] = 'BankSyncPostingPaymentCodeNotFound';
        }

        $thirdpartyId = 0;
        foreach ($confirmed as $match) {
            if ((string) $match->target_type !== $targetType) {
                $preview['errors'][] = 'BankSyncPostingMixedTargetTypes';
                continue;
            }

            $allocated = (float) $match->allocated_amount;
            if ($allocated <= 0) {
                $preview['errors'][] = 'BankSyncPostingInvalidAllocation';
                continue;
            }

            if ($targetType === BankSyncMatchManager::TARGET_CUSTOMER_INVOICE) {
                $invoice = new Facture($this->db);
                if ($invoice->fetch((int) $match->target_id) <= 0) {
                    $preview['errors'][] = 'BankSyncPostingInvoiceNotFound';
                    continue;
                }
                $invoice->fetch_thirdparty();
                $invoiceThirdpartyId = !empty($invoice->thirdparty->id) ? (int) $invoice->thirdparty->id : (int) $invoice->socid;
                $remaining = (float) $invoice->total_ttc - (float) $invoice->getSommePaiement();
                $ref = (string) $invoice->ref;
                $url = '/compta/facture/card.php?facid='.(int) $invoice->id;
                $label = !empty($invoice->thirdparty->name) ? (string) $invoice->thirdparty->name : '';
            } else {
                $invoice = new FactureFournisseur($this->db);
                if ($invoice->fetch((int) $match->target_id) <= 0) {
                    $preview['errors'][] = 'BankSyncPostingInvoiceNotFound';
                    continue;
                }
                $invoice->fetch_thirdparty();
                $invoiceThirdpartyId = !empty($invoice->thirdparty->id) ? (int) $invoice->thirdparty->id : (int) $invoice->socid;
                $remaining = (float) $invoice->total_ttc - (float) $invoice->getSommePaiement();
                $ref = trim((string) $invoice->ref_supplier) !== '' ? (string) $invoice->ref_supplier : (string) $invoice->ref;
                $url = '/fourn/facture/card.php?facid='.(int) $invoice->id;
                $label = !empty($invoice->thirdparty->name) ? (string) $invoice->thirdparty->name : '';
            }

            if ($thirdpartyId === 0) $thirdpartyId = $invoiceThirdpartyId;
            elseif ($invoiceThirdpartyId !== $thirdpartyId) $preview['errors'][] = 'BankSyncPostingMultipleThirdparties';

            // Credit-note settlement is intentionally deferred until its signed
            // allocation workflow is implemented explicitly.
            if (($targetType === BankSyncMatchManager::TARGET_CUSTOMER_INVOICE && (int) $invoice->type === Facture::TYPE_CREDIT_NOTE)
                || ($targetType === BankSyncMatchManager::TARGET_SUPPLIER_INVOICE && (int) $invoice->type === FactureFournisseur::TYPE_CREDIT_NOTE)) {
                $preview['errors'][] = 'BankSyncPostingCreditNoteNotSupportedYet';
            }

            if ($allocated - abs($remaining) > 0.00001) {
                $preview['warnings'][] = 'BankSyncPostingAllocationExceedsCurrentRemaining';
            }

            $preview['rows'][] = array(
                'target_type' => $targetType,
                'target_id' => (int) $match->target_id,
                'ref' => $ref,
                'label' => $label,
                'thirdparty' => $label,
                'allocated_amount' => $allocated,
                'remaining_before' => $remaining,
                'remaining_after' => $remaining - $allocated,
                'url' => $url,
            );
        }

        if ($targetType === BankSyncMatchManager::TARGET_CUSTOMER_INVOICE && (float) $transaction->amount < 0) {
            $preview['warnings'][] = 'BankSyncPostingUnexpectedDirection';
        }
        if ($targetType === BankSyncMatchManager::TARGET_SUPPLIER_INVOICE && (float) $transaction->amount > 0) {
            $preview['warnings'][] = 'BankSyncPostingUnexpectedDirection';
        }

        $preview['thirdparty_id'] = $thirdpartyId;
        $preview['postable'] = empty($preview['errors']);
        return $preview;
    }
}
