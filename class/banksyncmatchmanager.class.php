<?php
/* SPDX-License-Identifier: GPL-3.0-or-later */

/**
 * Generic reconciliation allocation manager.
 *
 * The target is intentionally polymorphic: later handlers translate target_type/target_id
 * into native Dolibarr payment objects such as Paiement, PaiementFourn,
 * PaymentSalary or PaymentSocialContribution.
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
        if ($transactionId <= 0 || $targetId <= 0 || trim((string) $targetType) === '') {
            throw new InvalidArgumentException('Invalid reconciliation target.');
        }

        $existing = $this->findExisting($transactionId, $targetType, $targetId);
        if ($existing) {
            $id = (int) $existing->rowid;
            $sql = 'UPDATE '.$this->db->prefix().'banksync_match SET';
            $sql .= " allocated_amount = '".$this->db->escape((string) $allocatedAmount)."'";
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
            $sql .= " allocated_amount = '".$this->db->escape((string) $allocatedAmount)."'";
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

    public function setStatus($matchId, $status, $userId, $transactionId = 0)
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
        $sql .= ", '".$this->db->escape((string) $allocatedAmount)."'";
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
        if ($transactionId <= 0) {
            return;
        }

        $sql = 'SELECT status FROM '.$this->db->prefix().'banksync_transaction';
        $sql .= ' WHERE rowid = '.$transactionId.' AND entity = '.$this->entity.' LIMIT 1';
        $resql = $this->db->query($sql);
        if (!$resql) {
            throw new RuntimeException($this->db->lasterror());
        }
        $tx = $this->db->fetch_object($resql);
        $this->db->free($resql);
        if (!$tx || in_array((string) $tx->status, array('posted', 'ignored', 'error'), true)) {
            return;
        }

        $sql = 'SELECT';
        $sql .= " SUM(CASE WHEN status = 'posted' THEN 1 ELSE 0 END) AS posted_count,";
        $sql .= " SUM(CASE WHEN status = 'confirmed' THEN 1 ELSE 0 END) AS confirmed_count";
        $sql .= ' FROM '.$this->db->prefix().'banksync_match';
        $sql .= ' WHERE entity = '.$this->entity.' AND fk_transaction = '.$transactionId;
        $resql = $this->db->query($sql);
        if (!$resql) {
            throw new RuntimeException($this->db->lasterror());
        }
        $counts = $this->db->fetch_object($resql);
        $this->db->free($resql);

        $status = ((int) $counts->posted_count > 0) ? 'posted' : (((int) $counts->confirmed_count > 0) ? 'matched' : 'new');
        $sql = 'UPDATE '.$this->db->prefix().'banksync_transaction';
        $sql .= " SET status = '".$this->db->escape($status)."'";
        $sql .= ' WHERE rowid = '.$transactionId.' AND entity = '.$this->entity;
        if (!$this->db->query($sql)) {
            throw new RuntimeException($this->db->lasterror());
        }
    }
}
