<?php
/* SPDX-License-Identifier: GPL-3.0-or-later */

require_once __DIR__.'/bankstatement.class.php';

/**
 * Manages provider source accounts and their explicit mapping to Dolibarr bank accounts.
 */
class BankSyncAccountManager
{
    /** @var DoliDB */
    private $db;
    /** @var int */
    private $entity;
    /** @var int */
    private $userId;

    public function __construct($db, $entity, $userId = 0)
    {
        $this->db = $db;
        $this->entity = (int) $entity;
        $this->userId = (int) $userId;
    }

    /**
     * Ensure a stable source-account row exists for a normalized statement.
     * Exact Dolibarr account-number/IBAN matches are stored only as suggestions;
     * explicit user confirmation is required before posting.
     *
     * @param BankStatement $statement Statement
     * @return array<string,mixed>
     */
    public function ensureForStatement($statement)
    {
        $sourceKey = self::normalizeAccountNumber($statement->accountNumber);
        if ($sourceKey === '') {
            throw new RuntimeException('Source account number is empty.');
        }
        $currency = strtoupper(trim((string) $statement->currency));

        $sql = 'SELECT rowid, fk_bank_account, suggested_fk_bank_account, mapping_status, mapping_method';
        $sql .= ' FROM '.$this->db->prefix().'banksync_account';
        $sql .= ' WHERE entity = '.$this->entity;
        $sql .= " AND provider = '".$this->db->escape($statement->provider)."'";
        $sql .= " AND source_account_key = '".$this->db->escape($sourceKey)."'";
        $sql .= " AND currency = '".$this->db->escape($currency)."'";
        $sql .= ' LIMIT 1';
        $resql = $this->db->query($sql);
        if (!$resql) {
            throw new RuntimeException($this->db->lasterror());
        }
        $obj = $this->db->fetch_object($resql);
        $this->db->free($resql);

        if ($obj) {
            $id = (int) $obj->rowid;
            $this->refreshMetadata($id, $statement);
            if (empty($obj->fk_bank_account) && empty($obj->suggested_fk_bank_account)) {
                $suggestion = $this->findDolibarrAccountSuggestion($sourceKey, $currency);
                if ($suggestion > 0) {
                    $this->storeSuggestion($id, $suggestion);
                    $obj->suggested_fk_bank_account = $suggestion;
                    $obj->mapping_method = 'exact_account_number';
                }
            }
            return array(
                'id' => $id,
                'fk_bank_account' => (int) $obj->fk_bank_account,
                'suggested_fk_bank_account' => (int) $obj->suggested_fk_bank_account,
                'mapping_status' => (string) $obj->mapping_status,
                'mapping_method' => (string) $obj->mapping_method,
            );
        }

        $suggestion = $this->findDolibarrAccountSuggestion($sourceKey, $currency);
        $now = $this->db->idate(dol_now());
        $sql = 'INSERT INTO '.$this->db->prefix().'banksync_account (';
        $sql .= 'entity, provider, source_account_key, source_account_number, conversion_account_number, source_account_label, currency,';
        $sql .= ' fk_bank_account, suggested_fk_bank_account, mapping_status, mapping_method, date_creation, fk_user_create';
        $sql .= ') VALUES (';
        $sql .= $this->entity;
        $sql .= ", '".$this->db->escape($statement->provider)."'";
        $sql .= ", '".$this->db->escape($sourceKey)."'";
        $sql .= ", '".$this->db->escape($statement->accountNumber)."'";
        $sql .= ", '".$this->db->escape($statement->conversionAccountNumber)."'";
        $sql .= ", ''";
        $sql .= ", '".$this->db->escape($currency)."'";
        $sql .= ', NULL';
        $sql .= $suggestion > 0 ? ', '.$suggestion : ', NULL';
        $sql .= ", 'unmapped'";
        $sql .= $suggestion > 0 ? ", 'exact_account_number'" : ', NULL';
        $sql .= ", '".$now."'";
        $sql .= ', '.($this->userId > 0 ? $this->userId : 'NULL');
        $sql .= ')';
        if (!$this->db->query($sql)) {
            throw new RuntimeException($this->db->lasterror());
        }

        $id = (int) $this->db->last_insert_id($this->db->prefix().'banksync_account');
        return array(
            'id' => $id,
            'fk_bank_account' => 0,
            'suggested_fk_bank_account' => $suggestion,
            'mapping_status' => 'unmapped',
            'mapping_method' => $suggestion > 0 ? 'exact_account_number' : '',
        );
    }

    /**
     * Confirm mapping to one native Dolibarr bank account.
     *
     * @param int $sourceAccountId BankSync source account id
     * @param int $bankAccountId Dolibarr bank account id
     * @param int $userId User performing change
     * @return void
     */
    public function setMapping($sourceAccountId, $bankAccountId, $userId)
    {
        $sourceAccountId = (int) $sourceAccountId;
        $bankAccountId = (int) $bankAccountId;
        if ($sourceAccountId <= 0 || $bankAccountId <= 0) {
            throw new InvalidArgumentException('Invalid account mapping ids.');
        }

        $source = $this->fetchSourceAccount($sourceAccountId);
        $bank = $this->fetchDolibarrAccount($bankAccountId);
        if (!$source || !$bank) {
            throw new RuntimeException('Source or Dolibarr bank account was not found.');
        }

        $sourceCurrency = strtoupper(trim((string) $source->currency));
        $bankCurrency = strtoupper(trim((string) $bank->currency_code));
        if ($sourceCurrency !== '' && $bankCurrency !== '' && $sourceCurrency !== $bankCurrency) {
            throw new RuntimeException('Source and Dolibarr bank-account currencies differ.');
        }

        $sql = 'UPDATE '.$this->db->prefix().'banksync_account SET';
        $sql .= ' fk_bank_account = '.$bankAccountId;
        $sql .= ', suggested_fk_bank_account = NULL';
        $sql .= ", mapping_status = 'confirmed'";
        $sql .= ", mapping_method = 'manual'";
        $sql .= ', fk_user_modif = '.((int) $userId);
        $sql .= ' WHERE rowid = '.$sourceAccountId.' AND entity = '.$this->entity;
        if (!$this->db->query($sql)) {
            throw new RuntimeException($this->db->lasterror());
        }
    }

    public function clearMapping($sourceAccountId, $userId)
    {
        $sourceAccountId = (int) $sourceAccountId;
        $source = $this->fetchSourceAccount($sourceAccountId);
        if (!$source) {
            throw new RuntimeException('Source bank account was not found.');
        }
        $suggestion = $this->findDolibarrAccountSuggestion((string) $source->source_account_key, (string) $source->currency);

        $sql = 'UPDATE '.$this->db->prefix().'banksync_account SET fk_bank_account = NULL';
        $sql .= $suggestion > 0 ? ', suggested_fk_bank_account = '.$suggestion : ', suggested_fk_bank_account = NULL';
        $sql .= ", mapping_status = 'unmapped'";
        $sql .= $suggestion > 0 ? ", mapping_method = 'exact_account_number'" : ', mapping_method = NULL';
        $sql .= ', fk_user_modif = '.((int) $userId);
        $sql .= ' WHERE rowid = '.$sourceAccountId.' AND entity = '.$this->entity;
        if (!$this->db->query($sql)) {
            throw new RuntimeException($this->db->lasterror());
        }
    }

    private function refreshMetadata($id, $statement)
    {
        $sql = 'UPDATE '.$this->db->prefix().'banksync_account SET';
        $sql .= " source_account_number = '".$this->db->escape($statement->accountNumber)."'";
        $sql .= ", conversion_account_number = '".$this->db->escape($statement->conversionAccountNumber)."'";
        $sql .= ' WHERE rowid = '.((int) $id).' AND entity = '.$this->entity;
        if (!$this->db->query($sql)) {
            throw new RuntimeException($this->db->lasterror());
        }
    }

    private function storeSuggestion($id, $bankAccountId)
    {
        $sql = 'UPDATE '.$this->db->prefix().'banksync_account SET suggested_fk_bank_account = '.((int) $bankAccountId);
        $sql .= ", mapping_method = 'exact_account_number'";
        $sql .= ' WHERE rowid = '.((int) $id).' AND entity = '.$this->entity.' AND fk_bank_account IS NULL';
        if (!$this->db->query($sql)) {
            throw new RuntimeException($this->db->lasterror());
        }
    }

    private function findDolibarrAccountSuggestion($sourceAccountKey, $currency)
    {
        $sql = 'SELECT rowid, number, iban_prefix, currency_code';
        $sql .= ' FROM '.$this->db->prefix().'bank_account';
        $sql .= ' WHERE entity = '.$this->entity.' AND clos = 0';
        $resql = $this->db->query($sql);
        if (!$resql) {
            throw new RuntimeException($this->db->lasterror());
        }

        $matches = array();
        while ($obj = $this->db->fetch_object($resql)) {
            $bankCurrency = strtoupper(trim((string) $obj->currency_code));
            if ($currency !== '' && $bankCurrency !== '' && $currency !== $bankCurrency) {
                continue;
            }
            $number = self::normalizeAccountNumber((string) $obj->number);
            $iban = self::normalizeAccountNumber((string) $obj->iban_prefix);
            if (($number !== '' && $number === $sourceAccountKey) || ($iban !== '' && $iban === $sourceAccountKey)) {
                $matches[] = (int) $obj->rowid;
            }
        }
        $this->db->free($resql);

        return count($matches) === 1 ? $matches[0] : 0;
    }

    private function fetchSourceAccount($id)
    {
        $sql = 'SELECT rowid, source_account_key, currency FROM '.$this->db->prefix().'banksync_account';
        $sql .= ' WHERE rowid = '.((int) $id).' AND entity = '.$this->entity;
        $resql = $this->db->query($sql);
        if (!$resql) {
            throw new RuntimeException($this->db->lasterror());
        }
        $obj = $this->db->fetch_object($resql);
        $this->db->free($resql);
        return $obj ?: null;
    }

    private function fetchDolibarrAccount($id)
    {
        $sql = 'SELECT rowid, currency_code FROM '.$this->db->prefix().'bank_account';
        $sql .= ' WHERE rowid = '.((int) $id).' AND entity = '.$this->entity.' AND clos = 0';
        $resql = $this->db->query($sql);
        if (!$resql) {
            throw new RuntimeException($this->db->lasterror());
        }
        $obj = $this->db->fetch_object($resql);
        $this->db->free($resql);
        return $obj ?: null;
    }

    public static function normalizeAccountNumber($value)
    {
        return strtoupper((string) preg_replace('/[^0-9A-Za-z]/u', '', trim((string) $value)));
    }
}
