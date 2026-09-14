<?php
/* SPDX-License-Identifier: GPL-3.0-or-later */

/**
 * Persists BankSync-owned posting metadata and enforces one native posting per
 * staged bank transaction.
 *
 * This class only writes BankSync-owned tables. Dolibarr core business records
 * are created by BankSyncPostingService through native domain APIs.
 */
class BankSyncPostingManager
{
    /** @var DoliDB */
    private $db;
    /** @var int */
    private $entity;

    public function __construct($db, $entity)
    {
        $this->db = $db;
        $this->entity = (int) $entity;
    }

    /** @return object|null */
    public function getForTransaction($transactionId)
    {
        $sql = 'SELECT rowid, fk_transaction, posting_kind, native_object_type, native_object_id, fk_bank, status, error_message, date_creation, date_posted, fk_user_create, fk_user_post';
        $sql .= ' FROM '.$this->db->prefix().'banksync_posting';
        $sql .= ' WHERE entity = '.$this->entity.' AND fk_transaction = '.((int) $transactionId).' LIMIT 1';
        $resql = $this->db->query($sql);
        if (!$resql) throw new RuntimeException($this->db->lasterror());
        $obj = $this->db->fetch_object($resql);
        $this->db->free($resql);
        return $obj ?: null;
    }

    /**
     * Reserve the transaction for posting. Unique(entity,fk_transaction) is the
     * hard idempotency boundary and also serializes accidental double submits.
     */
    public function acquire($transactionId, $postingKind, $userId)
    {
        $existing = $this->getForTransaction($transactionId);
        if ($existing) {
            if ((string) $existing->status === 'posted') {
                throw new RuntimeException('BankSyncPostingAlreadyPosted');
            }
            throw new RuntimeException('BankSyncPostingAlreadyInProgress');
        }

        $sql = 'INSERT INTO '.$this->db->prefix().'banksync_posting (';
        $sql .= 'entity, fk_transaction, posting_kind, status, date_creation, fk_user_create';
        $sql .= ') VALUES (';
        $sql .= $this->entity.', '.((int) $transactionId);
        $sql .= ", '".$this->db->escape((string) $postingKind)."', 'processing', '".$this->db->idate(dol_now())."', ".((int) $userId).')';
        if (!$this->db->query($sql)) {
            // A concurrent request may have won the unique-key race.
            $existing = $this->getForTransaction($transactionId);
            if ($existing) throw new RuntimeException('BankSyncPostingAlreadyInProgress');
            throw new RuntimeException($this->db->lasterror());
        }
        return (int) $this->db->last_insert_id($this->db->prefix().'banksync_posting');
    }

    public function markPosted($postingId, $nativeType, $nativeId, $bankLineId, $userId)
    {
        $sql = 'UPDATE '.$this->db->prefix().'banksync_posting SET';
        $sql .= " native_object_type = '".$this->db->escape((string) $nativeType)."'";
        $sql .= ', native_object_id = '.((int) $nativeId);
        $sql .= ', fk_bank = '.((int) $bankLineId);
        $sql .= ", status = 'posted', error_message = NULL";
        $sql .= ", date_posted = '".$this->db->idate(dol_now())."'";
        $sql .= ', fk_user_post = '.((int) $userId);
        $sql .= ' WHERE rowid = '.((int) $postingId).' AND entity = '.$this->entity;
        if (!$this->db->query($sql)) throw new RuntimeException($this->db->lasterror());
    }

    public function markBankSyncObjectsPosted($transactionId, $userId)
    {
        $transactionId = (int) $transactionId;

        $sql = 'UPDATE '.$this->db->prefix().'banksync_match SET';
        $sql .= " status = 'posted', fk_user_modif = ".((int) $userId);
        $sql .= ' WHERE entity = '.$this->entity.' AND fk_transaction = '.$transactionId;
        $sql .= " AND status = 'confirmed'";
        if (!$this->db->query($sql)) throw new RuntimeException($this->db->lasterror());

        $sql = 'UPDATE '.$this->db->prefix().'banksync_transaction SET';
        $sql .= " status = 'posted'";
        $sql .= ' WHERE entity = '.$this->entity.' AND rowid = '.$transactionId;
        if (!$this->db->query($sql)) throw new RuntimeException($this->db->lasterror());
    }
}
