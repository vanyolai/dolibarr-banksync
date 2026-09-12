<?php
/* SPDX-License-Identifier: GPL-3.0-or-later */

require_once __DIR__.'/bankstatement.class.php';
require_once __DIR__.'/banktransaction.class.php';
require_once __DIR__.'/banksyncaccountmanager.class.php';
require_once __DIR__.'/banksynctransactionclassifier.class.php';

/**
 * Persists normalized statements into BankSync staging tables.
 */
class BankSyncImporter
{
    /** @var DoliDB */
    private $db;
    /** @var int */
    private $userId;
    /** @var int */
    private $entity;
    /** @var BankSyncAccountManager */
    private $accountManager;
    /** @var BankSyncTransactionClassifier */
    private $classifier;

    /**
     * @param DoliDB $db Database handler
     * @param int $userId Dolibarr user id
     * @param int $entity Dolibarr entity id
     */
    public function __construct($db, $userId, $entity)
    {
        $this->db = $db;
        $this->userId = (int) $userId;
        $this->entity = (int) $entity;
        $this->accountManager = new BankSyncAccountManager($db, $this->entity, $this->userId);
        $this->classifier = new BankSyncTransactionClassifier();
    }

    /**
     * Import one normalized statement into staging.
     *
     * @param BankStatement $statement Statement
     * @param string $sourceFilename Original filename
     * @param string $sourceSha256 SHA-256 of uploaded source
     * @return array<string,mixed>
     * @throws Exception
     */
    public function importStatement($statement, $sourceFilename, $sourceSha256)
    {
        $sourceAccount = $this->accountManager->ensureForStatement($statement);
        $sourceAccountId = (int) $sourceAccount['id'];

        $existingImport = $this->findImportBySourceHash($statement->provider, $sourceSha256);
        if ($existingImport > 0) {
            $this->backfillSourceAccount($existingImport, $sourceAccountId);
            $this->backfillClassification($existingImport);
            return array(
                'import_id' => $existingImport,
                'duplicate_file' => true,
                'transaction_count' => count($statement->transactions),
                'imported_count' => 0,
                'skipped_count' => count($statement->transactions),
                'source_account_id' => $sourceAccountId,
                'fk_bank_account' => (int) $sourceAccount['fk_bank_account'],
                'suggested_fk_bank_account' => (int) $sourceAccount['suggested_fk_bank_account'],
                'mapping_status' => (string) $sourceAccount['mapping_status'],
            );
        }

        $this->db->begin();

        try {
            $importId = $this->createImportRow($statement, $sourceFilename, $sourceSha256, $sourceAccountId);
            $imported = 0;
            $skipped = 0;

            foreach ($statement->transactions as $transaction) {
                if ($this->entryExists($transaction)) {
                    $skipped++;
                    continue;
                }

                $this->classifier->classify($transaction);
                $this->insertTransaction($importId, $sourceAccountId, $transaction);
                $imported++;
            }

            $sql = 'UPDATE '.$this->db->prefix().'banksync_import';
            $sql .= " SET status = 'completed'";
            $sql .= ', transaction_count = '.((int) count($statement->transactions));
            $sql .= ', imported_count = '.((int) $imported);
            $sql .= ', skipped_count = '.((int) $skipped);
            $sql .= ' WHERE rowid = '.((int) $importId);
            if (!$this->db->query($sql)) {
                throw new RuntimeException($this->db->lasterror());
            }

            $this->db->commit();

            return array(
                'import_id' => $importId,
                'duplicate_file' => false,
                'transaction_count' => count($statement->transactions),
                'imported_count' => $imported,
                'skipped_count' => $skipped,
                'source_account_id' => $sourceAccountId,
                'fk_bank_account' => (int) $sourceAccount['fk_bank_account'],
                'suggested_fk_bank_account' => (int) $sourceAccount['suggested_fk_bank_account'],
                'mapping_status' => (string) $sourceAccount['mapping_status'],
            );
        } catch (Exception $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    private function findImportBySourceHash($provider, $sourceSha256)
    {
        $sql = 'SELECT rowid FROM '.$this->db->prefix().'banksync_import';
        $sql .= ' WHERE entity = '.$this->entity;
        $sql .= " AND provider = '".$this->db->escape($provider)."'";
        $sql .= " AND source_sha256 = '".$this->db->escape($sourceSha256)."'";
        $sql .= ' LIMIT 1';

        $resql = $this->db->query($sql);
        if (!$resql) {
            throw new RuntimeException($this->db->lasterror());
        }

        $obj = $this->db->fetch_object($resql);
        $this->db->free($resql);
        return $obj ? (int) $obj->rowid : 0;
    }

    private function backfillSourceAccount($importId, $sourceAccountId)
    {
        $sql = 'UPDATE '.$this->db->prefix().'banksync_import SET fk_banksync_account = '.((int) $sourceAccountId);
        $sql .= ' WHERE rowid = '.((int) $importId).' AND entity = '.$this->entity.' AND fk_banksync_account IS NULL';
        if (!$this->db->query($sql)) {
            throw new RuntimeException($this->db->lasterror());
        }

        $sql = 'UPDATE '.$this->db->prefix().'banksync_transaction SET fk_banksync_account = '.((int) $sourceAccountId);
        $sql .= ' WHERE fk_import = '.((int) $importId).' AND entity = '.$this->entity.' AND fk_banksync_account IS NULL';
        if (!$this->db->query($sql)) {
            throw new RuntimeException($this->db->lasterror());
        }
    }

    /**
     * Reclassify rows from older BankSync versions when the same source file is seen again.
     * Missing classifications are filled, and authoritative provider-specific codes are
     * refreshed so improved exact mappings can replace an older heuristic result.
     *
     * @param int $importId Existing import id
     * @return void
     */
    private function backfillClassification($importId)
    {
        $sql = 'SELECT rowid, provider, account_number, external_transaction_id, external_entry_id,';
        $sql .= ' value_date, booking_date, direction, amount, currency, transaction_type, transaction_code,';
        $sql .= ' counterparty_name, counterparty_account, reference';
        $sql .= ' FROM '.$this->db->prefix().'banksync_transaction';
        $sql .= ' WHERE fk_import = '.((int) $importId).' AND entity = '.$this->entity;
        $sql .= " AND ((bank_event_type IS NULL OR bank_event_type = '')";
        $sql .= " OR (provider = 'binx_csv' AND transaction_code IN ('CHRG', 'DMCT', 'CAPA', 'PPCF')))";
        $resql = $this->db->query($sql);
        if (!$resql) {
            throw new RuntimeException($this->db->lasterror());
        }

        $rows = array();
        while ($obj = $this->db->fetch_object($resql)) {
            $rows[] = $obj;
        }
        $this->db->free($resql);

        foreach ($rows as $obj) {
            $transaction = new BankTransaction();
            $transaction->provider = (string) $obj->provider;
            $transaction->accountNumber = (string) $obj->account_number;
            $transaction->externalTransactionId = (string) $obj->external_transaction_id;
            $transaction->externalEntryId = (string) $obj->external_entry_id;
            $transaction->valueDate = (string) $obj->value_date;
            $transaction->bookingDate = (string) $obj->booking_date;
            $transaction->direction = (string) $obj->direction;
            $transaction->amount = (string) $obj->amount;
            $transaction->currency = (string) $obj->currency;
            $transaction->transactionType = (string) $obj->transaction_type;
            $transaction->transactionCode = (string) $obj->transaction_code;
            $transaction->counterpartyName = (string) $obj->counterparty_name;
            $transaction->counterpartyAccount = (string) $obj->counterparty_account;
            $transaction->reference = (string) $obj->reference;
            $this->classifier->classify($transaction);

            $sql = 'UPDATE '.$this->db->prefix().'banksync_transaction SET';
            $sql .= " bank_event_type = '".$this->db->escape($transaction->bankEventType)."'";
            $sql .= $transaction->dolibarrPaymentCode !== ''
                ? ", dolibarr_payment_code = '".$this->db->escape($transaction->dolibarrPaymentCode)."'"
                : ', dolibarr_payment_code = NULL';
            $sql .= ', classification_confidence = '.((int) $transaction->classificationConfidence);
            $sql .= ", classification_method = '".$this->db->escape($transaction->classificationMethod)."'";
            $sql .= ' WHERE rowid = '.((int) $obj->rowid).' AND entity = '.$this->entity;
            if (!$this->db->query($sql)) {
                throw new RuntimeException($this->db->lasterror());
            }
        }
    }

    private function createImportRow($statement, $sourceFilename, $sourceSha256, $sourceAccountId)
    {
        $sql = 'INSERT INTO '.$this->db->prefix().'banksync_import (';
        $sql .= 'entity, provider, source_filename, source_sha256, account_number, conversion_account_number, currency, period_start, period_end, date_creation, fk_user_create, status, transaction_count, imported_count, skipped_count, fk_banksync_account';
        $sql .= ') VALUES (';
        $sql .= $this->entity;
        $sql .= ", '".$this->db->escape($statement->provider)."'";
        $sql .= ", '".$this->db->escape($sourceFilename)."'";
        $sql .= ", '".$this->db->escape($sourceSha256)."'";
        $sql .= ", '".$this->db->escape($statement->accountNumber)."'";
        $sql .= ", '".$this->db->escape($statement->conversionAccountNumber)."'";
        $sql .= ", '".$this->db->escape($statement->currency)."'";
        $sql .= $statement->periodStart !== '' ? ", '".$this->db->escape($statement->periodStart)."'" : ', NULL';
        $sql .= $statement->periodEnd !== '' ? ", '".$this->db->escape($statement->periodEnd)."'" : ', NULL';
        $sql .= ", '".$this->db->idate(dol_now())."'";
        $sql .= ', '.$this->userId;
        $sql .= ", 'processing'";
        $sql .= ', 0, 0, 0';
        $sql .= ', '.((int) $sourceAccountId);
        $sql .= ')';

        if (!$this->db->query($sql)) {
            throw new RuntimeException($this->db->lasterror());
        }

        $id = (int) $this->db->last_insert_id($this->db->prefix().'banksync_import');
        if ($id <= 0) {
            throw new RuntimeException('Could not determine BankSync import id.');
        }

        return $id;
    }

    private function entryExists($transaction)
    {
        $sql = 'SELECT rowid FROM '.$this->db->prefix().'banksync_transaction';
        $sql .= ' WHERE entity = '.$this->entity;
        $sql .= " AND provider = '".$this->db->escape($transaction->provider)."'";
        $sql .= " AND account_number = '".$this->db->escape($transaction->accountNumber)."'";
        $sql .= " AND external_entry_id = '".$this->db->escape($transaction->externalEntryId)."'";
        $sql .= ' LIMIT 1';

        $resql = $this->db->query($sql);
        if (!$resql) {
            throw new RuntimeException($this->db->lasterror());
        }

        $exists = (bool) $this->db->fetch_object($resql);
        $this->db->free($resql);
        return $exists;
    }

    private function insertTransaction($importId, $sourceAccountId, $transaction)
    {
        $rawJson = json_encode($transaction->rawData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($rawJson === false) {
            $rawJson = '{}';
        }

        $sql = 'INSERT INTO '.$this->db->prefix().'banksync_transaction (';
        $sql .= 'entity, fk_import, fk_banksync_account, provider, account_number, external_transaction_id, external_entry_id, value_date, booking_date, direction, amount, currency, transaction_type, transaction_code, counterparty_name, counterparty_account, reference, bank_event_type, dolibarr_payment_code, classification_confidence, classification_method, source_line, raw_data, status, date_creation';
        $sql .= ') VALUES (';
        $sql .= $this->entity;
        $sql .= ', '.((int) $importId);
        $sql .= ', '.((int) $sourceAccountId);
        $sql .= ", '".$this->db->escape($transaction->provider)."'";
        $sql .= ", '".$this->db->escape($transaction->accountNumber)."'";
        $sql .= ", '".$this->db->escape($transaction->externalTransactionId)."'";
        $sql .= ", '".$this->db->escape($transaction->externalEntryId)."'";
        $sql .= ", '".$this->db->escape($transaction->valueDate)."'";
        $sql .= ", '".$this->db->escape($transaction->bookingDate)."'";
        $sql .= ", '".$this->db->escape($transaction->direction)."'";
        $sql .= ", '".$this->db->escape($transaction->amount)."'";
        $sql .= ", '".$this->db->escape($transaction->currency)."'";
        $sql .= ", '".$this->db->escape($transaction->transactionType)."'";
        $sql .= ", '".$this->db->escape($transaction->transactionCode)."'";
        $sql .= ", '".$this->db->escape($transaction->counterpartyName)."'";
        $sql .= ", '".$this->db->escape($transaction->counterpartyAccount)."'";
        $sql .= ", '".$this->db->escape($transaction->reference)."'";
        $sql .= ", '".$this->db->escape($transaction->bankEventType)."'";
        $sql .= $transaction->dolibarrPaymentCode !== '' ? ", '".$this->db->escape($transaction->dolibarrPaymentCode)."'" : ', NULL';
        $sql .= ', '.((int) $transaction->classificationConfidence);
        $sql .= ", '".$this->db->escape($transaction->classificationMethod)."'";
        $sql .= ', '.((int) $transaction->sourceLine);
        $sql .= ", '".$this->db->escape($rawJson)."'";
        $sql .= ", 'new'";
        $sql .= ", '".$this->db->idate(dol_now())."'";
        $sql .= ')';

        if (!$this->db->query($sql)) {
            throw new RuntimeException($this->db->lasterror());
        }
    }
}
