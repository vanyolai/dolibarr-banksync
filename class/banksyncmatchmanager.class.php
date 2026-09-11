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
     * Insert or update a suggested/confirmed allocation.
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

        $sql = 'SELECT rowid FROM '.$this->db->prefix().'banksync_match';
        $sql .= ' WHERE entity = '.$this->entity.' AND fk_transaction = '.$transactionId;
        $sql .= " AND target_type = '".$this->db->escape($targetType)."'";
        $sql .= ' AND target_id = '.$targetId.' LIMIT 1';
        $resql = $this->db->query($sql);
        if (!$resql) {
            throw new RuntimeException($this->db->lasterror());
        }
        $obj = $this->db->fetch_object($resql);
        $this->db->free($resql);

        if ($obj) {
            $id = (int) $obj->rowid;
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
            return $id;
        }

        $sql = 'INSERT INTO '.$this->db->prefix().'banksync_match (';
        $sql .= 'entity, fk_transaction, target_type, target_id, allocated_amount, confidence, match_method, status, date_creation, fk_user_create';
        $sql .= ') VALUES (';
        $sql .= $this->entity.', '.$transactionId;
        $sql .= ", '".$this->db->escape($targetType)."', ".$targetId;
        $sql .= ", '".$this->db->escape((string) $allocatedAmount)."'";
        $sql .= ', '.$confidence;
        $sql .= ", '".$this->db->escape((string) $method)."'";
        $sql .= ", '".$this->db->escape($status)."'";
        $sql .= ", '".$this->db->idate(dol_now())."'";
        $sql .= ', '.((int) $userId).')';
        if (!$this->db->query($sql)) {
            throw new RuntimeException($this->db->lasterror());
        }
        return (int) $this->db->last_insert_id($this->db->prefix().'banksync_match');
    }

    public function setStatus($matchId, $status, $userId)
    {
        if (!in_array($status, array('suggested', 'confirmed', 'rejected', 'posted'), true)) {
            throw new InvalidArgumentException('Invalid reconciliation status.');
        }
        $sql = 'UPDATE '.$this->db->prefix().'banksync_match';
        $sql .= " SET status = '".$this->db->escape($status)."', fk_user_modif = ".((int) $userId);
        $sql .= ' WHERE rowid = '.((int) $matchId).' AND entity = '.$this->entity;
        if (!$this->db->query($sql)) {
            throw new RuntimeException($this->db->lasterror());
        }
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
        $sql .= ' ORDER BY rowid ASC';
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
}
