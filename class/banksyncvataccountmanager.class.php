<?php
/* SPDX-License-Identifier: GPL-3.0-or-later */

/**
 * Maps destination bank account numbers to Dolibarr VAT declarations.
 *
 * VAT is a separate Dolibarr domain (`tva` / `PaymentVAT`) and must not be
 * represented as a fake social/fiscal contribution type.
 */
class BankSyncVatAccountManager
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

    public static function normalizeAccount($value)
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', (string) $value));
    }

    /** @return array<int,object> */
    public function getMappings()
    {
        $rows = array();
        $sql = 'SELECT rowid, target_account_number, label, active';
        $sql .= ' FROM '.$this->db->prefix().'banksync_vat_account_map';
        $sql .= ' WHERE entity = '.$this->entity;
        $sql .= ' ORDER BY target_account_number ASC';
        $resql = $this->db->query($sql);
        if (!$resql) throw new RuntimeException($this->db->lasterror());
        while ($obj = $this->db->fetch_object($resql)) $rows[] = $obj;
        $this->db->free($resql);
        return $rows;
    }

    /** @return object|null */
    public function findByAccount($accountNumber)
    {
        $normalized = self::normalizeAccount($accountNumber);
        if ($normalized === '') return null;
        $sql = 'SELECT rowid, target_account_number, label, active';
        $sql .= ' FROM '.$this->db->prefix().'banksync_vat_account_map';
        $sql .= ' WHERE entity = '.$this->entity;
        $sql .= " AND target_account_number = '".$this->db->escape($normalized)."'";
        $sql .= ' AND active = 1 LIMIT 1';
        $resql = $this->db->query($sql);
        if (!$resql) throw new RuntimeException($this->db->lasterror());
        $obj = $this->db->fetch_object($resql);
        $this->db->free($resql);
        return $obj ?: null;
    }

    public function save($accountNumber, $label, $userId)
    {
        $normalized = self::normalizeAccount($accountNumber);
        if ($normalized === '') throw new InvalidArgumentException('Invalid VAT account mapping.');

        $sql = 'SELECT rowid FROM '.$this->db->prefix().'banksync_vat_account_map';
        $sql .= ' WHERE entity = '.$this->entity;
        $sql .= " AND target_account_number = '".$this->db->escape($normalized)."' LIMIT 1";
        $resql = $this->db->query($sql);
        if (!$resql) throw new RuntimeException($this->db->lasterror());
        $obj = $this->db->fetch_object($resql);
        $this->db->free($resql);

        if ($obj) {
            $sql = 'UPDATE '.$this->db->prefix().'banksync_vat_account_map SET';
            $sql .= " label = '".$this->db->escape(trim((string) $label))."'";
            $sql .= ', active = 1, fk_user_modif = '.((int) $userId);
            $sql .= ' WHERE rowid = '.((int) $obj->rowid).' AND entity = '.$this->entity;
        } else {
            $sql = 'INSERT INTO '.$this->db->prefix().'banksync_vat_account_map (';
            $sql .= 'entity, target_account_number, label, active, date_creation, fk_user_create, fk_user_modif';
            $sql .= ') VALUES (';
            $sql .= $this->entity.', ';
            $sql .= "'".$this->db->escape($normalized)."', '".$this->db->escape(trim((string) $label))."', 1, '".$this->db->idate(dol_now())."', ";
            $sql .= ((int) $userId).', '.((int) $userId).')';
        }
        if (!$this->db->query($sql)) throw new RuntimeException($this->db->lasterror());
    }

    public function delete($rowid)
    {
        $sql = 'DELETE FROM '.$this->db->prefix().'banksync_vat_account_map';
        $sql .= ' WHERE rowid = '.((int) $rowid).' AND entity = '.$this->entity;
        if (!$this->db->query($sql)) throw new RuntimeException($this->db->lasterror());
    }
}
