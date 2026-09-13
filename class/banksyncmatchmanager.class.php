<?php
/* SPDX-License-Identifier: GPL-3.0-or-later */

/**
 * Generic reconciliation allocation manager.
 *
 * One bank transaction may have multiple allocations. The target is intentionally
 * polymorphic so later posting handlers can translate targets into native Dolibarr
 * payment/settlement objects.
 */
class BankSyncMatchManager
{
    const TARGET_CUSTOMER_INVOICE = 'customer_invoice';
    const TARGET_SUPPLIER_INVOICE = 'supplier_invoice';
    const TARGET_SALARY = 'salary';
    const TARGET_SOCIAL_CONTRIBUTION = 'social_contribution';
    const TARGET_EXPENSE_REPORT = 'expense_report';
    const TARGET_TAX = 'tax';
    const TARGET_BANK_FEE = 'bank_fee';
    const TARGET_INTERNAL_TRANSFER = 'internal_transfer';
    const TARGET_OTHER = 'other';

    /** @var DoliDB */
    private $db;
    /** @var int */
    private $entity;

    public function __construct($db, $entity)
    {
        $this->db = $db;
        $this->entity = (int) $entity;
    }

    /**
     * Insert or update a reconciliation allocation.
     *
     * @return int Match row id
     */
    public function upsert($transactionId, $targetType, $targetId, $allocatedAmount, $confidence, $method, $status, $userId)
    {
        $transactionId = (int) $transactionId;
        $targetId = (int) $targetId;
        $confidence = max(0, min(100, (int) $confidence));
        $status = in_array($status, array('suggested', 'confirmed', 'rejected', 'posted'), true) ? $status : 'suggested';
        $allocatedAmount = $this->normalizeAmount($allocatedAmount);
        if ($transactionId <= 0 || $targetId <= 0 || trim((string) $targetType) === '') {
            throw new InvalidArgumentException('Invalid reconciliation target.');
        }

        $existing = $this->findExisting($transactionId, $targetType, $targetId);
        if ($existing) {
            $id = (int) $existing->rowid;
            $sql = 'UPDATE '.$this->db->prefix().'banksync_match SET';
            $sql .= " allocated_amount = '".$this->db->escape($allocatedAmount)."'";
            $sql .= ', confidence = '.$confidence;
            $sql .= ", match_method = '".$this->db->escape((string) $method)."'";
            $sql .= ", status = '".$this->db->escape($status)."'";
            $sql .= ', fk_user_modif = '.((int) $userId);
            $sql .= ' WHERE rowid = '.$id.' AND entity = '.$this->entity;
            if (!$this->db->query($sql)) {
                throw new RuntimeException($this->db->lasterror());
            }
            $this->syncTransactionStatus($transactionId);
            return $id;
        }

        $id = $this->insert($transactionId, $targetType, $targetId, $allocatedAmount, $confidence, $method, $status, $userId);
        $this->syncTransactionStatus($transactionId);
        return $id;
    }

    /**
     * Store an automatically generated suggestion without overwriting a human decision.
     *
     * @return int Match row id
     */
    public function upsertSuggestion($transactionId, $targetType, $targetId, $allocatedAmount, $confidence, $method, $userId)
    {
        $existing = $this->findExisting($transactionId, $targetType, $targetId);
        if ($existing) {
            if ((string) $existing->status !== 'suggested') {
                return (int) $existing->rowid;
            }

            $sql = 'UPDATE '.$this->db->prefix().'banksync_match SET';
            $sql .= " allocated_amount = '".$this->db->escape($this->normalizeAmount($allocatedAmount))."'";
            $sql .= ', confidence = '.max(0, min(100, (int) $confidence));
            $sql .= ", match_method = '".$this->db->escape((string) $method)."'";
            $sql .= ', fk_user_modif = '.((int) $userId);
            $sql .= ' WHERE rowid = '.((int) $existing->rowid).' AND entity = '.$this->entity;
            if (!$this->db->query($sql)) {
                throw new RuntimeException($this->db->lasterror());
            }
            return (int) $existing->rowid;
        }

        return $this->insert($transactionId, $targetType, $targetId, $allocatedAmount, $confidence, $method, 'suggested', $userId);
    }

    public function clearSuggested($transactionId)
    {
        $sql = 'DELETE FROM '.$this->db->prefix().'banksync_match';
        $sql .= ' WHERE entity = '.$this->entity.' AND fk_transaction = '.((int) $transactionId);
        $sql .= " AND status = 'suggested'";
        if (!$this->db->query($sql)) {
            throw new RuntimeException($this->db->lasterror());
        }
    }

    /**
     * Change workflow state and optionally change the allocation amount at confirmation time.
     */
    public function setStatus($matchId, $status, $userId, $transactionId = 0, $allocatedAmount = null)
    {
        if (!in_array($status, array('suggested', 'confirmed', 'rejected', 'posted'), true)) {
            throw new InvalidArgumentException('Invalid reconciliation status.');
        }

        $sql = 'SELECT fk_transaction FROM '.$this->db->prefix().'banksync_match';
        $sql .= ' WHERE rowid = '.((int) $matchId).' AND entity = '.$this->entity;
        if ((int) $transactionId > 0) {
            $sql .= ' AND fk_transaction = '.((int) $transactionId);
        }
        $sql .= ' LIMIT 1';
        $resql = $this->db->query($sql);
        if (!$resql) {
            throw new RuntimeException($this->db->lasterror());
        }
        $obj = $this->db->fetch_object($resql);
        $this->db->free($resql);
        if (!$obj) {
            throw new RuntimeException('Reconciliation match not found.');
        }
        $actualTransactionId = (int) $obj->fk_transaction;

        $sql = 'UPDATE '.$this->db->prefix().'banksync_match';
        $sql .= " SET status = '".$this->db->escape($status)."', fk_user_modif = ".((int) $userId);
        if ($allocatedAmount !== null && $allocatedAmount !== '') {
            $sql .= ", allocated_amount = '".$this->db->escape($this->normalizeAmount($allocatedAmount))."'";
        }
        $sql .= ' WHERE rowid = '.((int) $matchId).' AND entity = '.$this->entity;
        if (!$this->db->query($sql)) {
            throw new RuntimeException($this->db->lasterror());
        }
        $this->syncTransactionStatus($actualTransactionId);
    }

    /**
     * @return array<int,object>
     */
    public function getForTransaction($transactionId)
    {
        $rows = array();
        $sql = 'SELECT rowid, target_type, target_id, allocated_amount, confidence, match_method, status';
        $sql .= ' FROM '.$this->db->prefix().'banksync_match';
        $sql .= ' WHERE entity = '.$this->entity.' AND fk_transaction = '.((int) $transactionId);
        $sql .= " ORDER BY CASE status WHEN 'confirmed' THEN 0 WHEN 'posted' THEN 1 WHEN 'suggested' THEN 2 ELSE 3 END, confidence DESC, rowid ASC";
        $resql = $this->db->query($sql);
        if (!$resql) {
            throw new RuntimeException($this->db->lasterror());
        }
        while ($obj = $this->db->fetch_object($resql)) {
            $rows[] = $obj;
        }
        $this->db->free($resql);
        return $rows;
    }

    /**
     * Return transaction-level reconciliation allocation summary.
     * Confirmed/posted allocations are signed deliberately so future credit-note
     * allocations can offset normal invoice allocations.
     *
     * @return array<string,mixed>
     */
    public function getAllocationSummary($transactionId)
    {
        $transactionId = (int) $transactionId;
        $sql = 'SELECT amount, currency FROM '.$this->db->prefix().'banksync_transaction';
        $sql .= ' WHERE rowid = '.$transactionId.' AND entity = '.$this->entity.' LIMIT 1';
        $resql = $this->db->query($sql);
        if (!$resql) throw new RuntimeException($this->db->lasterror());
        $tx = $this->db->fetch_object($resql);
        $this->db->free($resql);
        if (!$tx) throw new RuntimeException('BankSync transaction not found.');

        $sql = 'SELECT';
        $sql .= " COALESCE(SUM(CASE WHEN status IN ('confirmed','posted') THEN allocated_amount ELSE 0 END),0) AS allocated_amount,";
        $sql .= " SUM(CASE WHEN status = 'confirmed' THEN 1 ELSE 0 END) AS confirmed_count,";
        $sql .= " SUM(CASE WHEN status = 'posted' THEN 1 ELSE 0 END) AS posted_count";
        $sql .= ' FROM '.$this->db->prefix().'banksync_match';
        $sql .= ' WHERE entity = '.$this->entity.' AND fk_transaction = '.$transactionId;
        $resql = $this->db->query($sql);
        if (!$resql) throw new RuntimeException($this->db->lasterror());
        $sum = $this->db->fetch_object($resql);
        $this->db->free($resql);

        $target = abs((float) $tx->amount);
        $allocated = (float) $sum->allocated_amount;
        $remaining = $target - $allocated;
        $balanced = abs($remaining) <= 0.01;

        return array(
            'transaction_amount' => (float) $tx->amount,
            'target_amount' => $target,
            'allocated_amount' => $allocated,
            'remaining_amount' => $remaining,
            'confirmed_count' => (int) $sum->confirmed_count,
            'posted_count' => (int) $sum->posted_count,
            'balanced' => $balanced,
            'currency' => (string) $tx->currency,
        );
    }

    /** @return object|null */
    private function findExisting($transactionId, $targetType, $targetId)
    {
        $sql = 'SELECT rowid, status FROM '.$this->db->prefix().'banksync_match';
        $sql .= ' WHERE entity = '.$this->entity.' AND fk_transaction = '.((int) $transactionId);
        $sql .= " AND target_type = '".$this->db->escape((string) $targetType)."'";
        $sql .= ' AND target_id = '.((int) $targetId).' LIMIT 1';
        $resql = $this->db->query($sql);
        if (!$resql) {
            throw new RuntimeException($this->db->lasterror());
        }
        $obj = $this->db->fetch_object($resql);
        $this->db->free($resql);
        return $obj ?: null;
    }

    private function insert($transactionId, $targetType, $targetId, $allocatedAmount, $confidence, $method, $status, $userId)
    {
        $sql = 'INSERT INTO '.$this->db->prefix().'banksync_match (';
        $sql .= 'entity, fk_transaction, target_type, target_id, allocated_amount, confidence, match_method, status, date_creation, fk_user_create';
        $sql .= ') VALUES (';
        $sql .= $this->entity.', '.((int) $transactionId);
        $sql .= ", '".$this->db->escape((string) $targetType)."', ".((int) $targetId);
        $sql .= ", '".$this->db->escape($this->normalizeAmount($allocatedAmount))."'";
        $sql .= ', '.max(0, min(100, (int) $confidence));
        $sql .= ", '".$this->db->escape((string) $method)."'";
        $sql .= ", '".$this->db->escape((string) $status)."'";
        $sql .= ", '".$this->db->idate(dol_now())."'";
        $sql .= ', '.((int) $userId).')';
        if (!$this->db->query($sql)) {
            throw new RuntimeException($this->db->lasterror());
        }
        return (int) $this->db->last_insert_id($this->db->prefix().'banksync_match');
    }

    private function syncTransactionStatus($transactionId)
    {
        $transactionId = (int) $transactionId;
        if ($transactionId <= 0) return;

        $sql = 'SELECT status FROM '.$this->db->prefix().'banksync_transaction';
        $sql .= ' WHERE rowid = '.$transactionId.' AND entity = '.$this->entity.' LIMIT 1';
        $resql = $this->db->query($sql);
        if (!$resql) throw new RuntimeException($this->db->lasterror());
        $tx = $this->db->fetch_object($resql);
        $this->db->free($resql);
        if (!$tx || in_array((string) $tx->status, array('posted', 'ignored', 'error'), true)) return;

        $summary = $this->getAllocationSummary($transactionId);
        if ((int) $summary['confirmed_count'] + (int) $summary['posted_count'] === 0) {
            $status = 'new';
        } elseif (!empty($summary['balanced'])) {
            $status = 'matched';
        } else {
            $status = 'partially_matched';
        }

        $sql = 'UPDATE '.$this->db->prefix().'banksync_transaction';
        $sql .= " SET status = '".$this->db->escape($status)."'";
        $sql .= ' WHERE rowid = '.$transactionId.' AND entity = '.$this->entity;
        if (!$this->db->query($sql)) {
            throw new RuntimeException($this->db->lasterror());
        }
    }

    private function normalizeAmount($value)
    {
        $value = trim((string) $value);
        $value = str_replace(array("\xc2\xa0", ' '), '', $value);
        if (substr_count($value, ',') === 1 && strpos($value, '.') === false) {
            $value = str_replace(',', '.', $value);
        }
        if (!is_numeric($value)) {
            throw new InvalidArgumentException('Invalid allocation amount.');
        }
        return number_format((float) $value, 8, '.', '');
    }
}
