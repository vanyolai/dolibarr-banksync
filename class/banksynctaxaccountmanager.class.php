<?php
/* SPDX-License-Identifier: GPL-3.0-or-later */

/**
 * Maps destination bank account numbers to Dolibarr social/fiscal contribution types.
 *
 * The bank account is treated as an explicit reconciliation signal. One destination
 * account may map to multiple Dolibarr contribution types (for example related TB
 * categories paid to the same authority account), and one contribution type may also
 * have multiple historical/current destination accounts.
 */
class BankSyncTaxAccountManager
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
        $sql = 'SELECT m.rowid, m.target_account_number, m.fk_charge_type, m.label, m.active,';
        $sql .= ' c.code AS type_code, c.libelle AS type_label';
        $sql .= ' FROM '.$this->db->prefix().'banksync_tax_account_map AS m';
        $sql .= ' LEFT JOIN '.$this->db->prefix().'c_chargesociales AS c ON c.id = m.fk_charge_type';
        $sql .= ' WHERE m.entity = '.$this->entity;
        $sql .= ' ORDER BY m.target_account_number ASC, c.libelle ASC';
        $resql = $this->db->query($sql);
        if (!$resql) throw new RuntimeException($this->db->lasterror());
        while ($obj = $this->db->fetch_object($resql)) $rows[] = $obj;
        $this->db->free($resql);
        return $rows;
    }

    /** @return array<int,object> */
    public function getMappingsByAccount($accountNumber)
    {
        $rows = array();
        $normalized = self::normalizeAccount($accountNumber);
        if ($normalized === '') return $rows;

        $sql = 'SELECT m.rowid, m.target_account_number, m.fk_charge_type, m.label, m.active,';
        $sql .= ' c.code AS type_code, c.libelle AS type_label';
        $sql .= ' FROM '.$this->db->prefix().'banksync_tax_account_map AS m';
        $sql .= ' LEFT JOIN '.$this->db->prefix().'c_chargesociales AS c ON c.id = m.fk_charge_type';
        $sql .= ' WHERE m.entity = '.$this->entity;
        $sql .= " AND m.target_account_number = '".$this->db->escape($normalized)."'";
        $sql .= ' AND m.active = 1 ORDER BY c.libelle ASC, m.rowid ASC';
        $resql = $this->db->query($sql);
        if (!$resql) throw new RuntimeException($this->db->lasterror());
        while ($obj = $this->db->fetch_object($resql)) $rows[] = $obj;
        $this->db->free($resql);
        return $rows;
    }

    /** @return object|null Backward-compatible convenience accessor. */
    public function findByAccount($accountNumber)
    {
        $rows = $this->getMappingsByAccount($accountNumber);
        return !empty($rows) ? $rows[0] : null;
    }

    public function save($accountNumber, $chargeTypeId, $label, $userId)
    {
        $normalized = self::normalizeAccount($accountNumber);
        $chargeTypeId = (int) $chargeTypeId;
        if ($normalized === '' || $chargeTypeId <= 0) throw new InvalidArgumentException('Invalid tax account mapping.');

        $sql = 'SELECT id FROM '.$this->db->prefix().'c_chargesociales WHERE id = '.$chargeTypeId.' LIMIT 1';
        $resql = $this->db->query($sql);
        if (!$resql) throw new RuntimeException($this->db->lasterror());
        $exists = $this->db->num_rows($resql) > 0;
        $this->db->free($resql);
        if (!$exists) throw new InvalidArgumentException('Unknown social contribution type.');

        $sql = 'SELECT rowid FROM '.$this->db->prefix().'banksync_tax_account_map';
        $sql .= ' WHERE entity = '.$this->entity;
        $sql .= " AND target_account_number = '".$this->db->escape($normalized)."'";
        $sql .= ' AND fk_charge_type = '.$chargeTypeId.' LIMIT 1';
        $resql = $this->db->query($sql);
        if (!$resql) throw new RuntimeException($this->db->lasterror());
        $obj = $this->db->fetch_object($resql);
        $this->db->free($resql);

        if ($obj) {
            $sql = 'UPDATE '.$this->db->prefix().'banksync_tax_account_map SET';
            $sql .= ", label = '".$this->db->escape(trim((string) $label))."'";
            $sql .= ', active = 1, fk_user_modif = '.((int) $userId);
            $sql .= ' WHERE rowid = '.((int) $obj->rowid).' AND entity = '.$this->entity;
        } else {
            $sql = 'INSERT INTO '.$this->db->prefix().'banksync_tax_account_map (';
            $sql .= 'entity, target_account_number, fk_charge_type, label, active, date_creation, fk_user_create, fk_user_modif';
            $sql .= ') VALUES (';
            $sql .= $this->entity.', ';
            $sql .= "'".$this->db->escape($normalized)."', ".$chargeTypeId.', ';
            $sql .= "'".$this->db->escape(trim((string) $label))."', 1, '".$this->db->idate(dol_now())."', ";
            $sql .= ((int) $userId).', '.((int) $userId).')';
        }
        if (!$this->db->query($sql)) throw new RuntimeException($this->db->lasterror());
    }

    public function delete($rowid)
    {
        $sql = 'DELETE FROM '.$this->db->prefix().'banksync_tax_account_map';
        $sql .= ' WHERE rowid = '.((int) $rowid).' AND entity = '.$this->entity;
        if (!$this->db->query($sql)) throw new RuntimeException($this->db->lasterror());
    }

    /** @return array<int,object> */
    public function getChargeTypes()
    {
        $rows = array();
        $sql = 'SELECT id, code, libelle FROM '.$this->db->prefix().'c_chargesociales';
        $sql .= ' WHERE active = 1 ORDER BY libelle ASC';
        $resql = $this->db->query($sql);
        if (!$resql) throw new RuntimeException($this->db->lasterror());
        while ($obj = $this->db->fetch_object($resql)) $rows[] = $obj;
        $this->db->free($resql);
        return $rows;
    }

    /** @return array<int,object> */
    public function getUnmappedObservedAccounts($limit = 30)
    {
        $rows = array();
        $sql = 'SELECT counterparty_account, MAX(counterparty_name) AS counterparty_name, MAX(booking_date) AS last_date, COUNT(*) AS tx_count';
        $sql .= ' FROM '.$this->db->prefix().'banksync_transaction';
        $sql .= ' WHERE entity = '.$this->entity;
        $sql .= " AND counterparty_account IS NOT NULL AND counterparty_account <> ''";
        $sql .= " AND (UPPER(counterparty_name) LIKE '%NAV%' OR UPPER(counterparty_name) LIKE '%ADÓ%' OR UPPER(counterparty_name) LIKE '%ADO%'";
        $sql .= " OR UPPER(counterparty_name) LIKE '%JÁRULÉK%' OR UPPER(counterparty_name) LIKE '%JARULEK%' OR UPPER(counterparty_name) LIKE '%SZOCHO%'";
        $sql .= " OR UPPER(counterparty_name) LIKE '%SZJA%' OR UPPER(counterparty_name) LIKE '%TB%')";
        $sql .= ' GROUP BY counterparty_account ORDER BY last_date DESC';
        $sql .= $this->db->plimit(max(1, (int) $limit), 0);
        $resql = $this->db->query($sql);
        if (!$resql) return $rows;
        while ($obj = $this->db->fetch_object($resql)) {
            if (!empty($this->getMappingsByAccount((string) $obj->counterparty_account))) continue;
            $rows[] = $obj;
        }
        $this->db->free($resql);
        return $rows;
    }
}
