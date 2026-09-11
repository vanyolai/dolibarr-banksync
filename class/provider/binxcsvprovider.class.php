<?php
/* SPDX-License-Identifier: GPL-3.0-or-later */

require_once __DIR__.'/../bankdataproviderinterface.class.php';
require_once __DIR__.'/../bankstatement.class.php';
require_once __DIR__.'/../banktransaction.class.php';

/**
 * Parser for BinX transaction-history CSV exports.
 */
class BinxCsvProvider implements BankDataProviderInterface
{
    const PROVIDER_KEY = 'binx_csv';

    /** @var array<string,string> */
    private $metaKeys = array(
        'Konverziós számla száma' => 'conversionAccountNumber',
        'E-pénz rendelkezési számla száma' => 'accountNumber',
        'Időszak kezdete' => 'periodStart',
        'Időszak vége' => 'periodEnd',
        'Devizanem' => 'currency',
    );

    /** @var array<int,string> */
    private $requiredColumns = array(
        'Értéknap',
        'Könyvelés napja',
        'Terhelés vagy jóváírás (T/J)',
        'Összeg',
        'Tranzakció típusa',
        'Tranzakciós kód',
        'Partner neve',
        'Partner bankszámlaszáma',
        'Közlemény',
        'Tranzakció azonosító',
    );

    public function getKey()
    {
        return self::PROVIDER_KEY;
    }

    public function getLabel()
    {
        return 'BinX CSV';
    }

    public function getSourceType()
    {
        return 'file';
    }

    /**
     * @param array<string,mixed> $context Context containing path
     * @return BankStatement
     * @throws Exception
     */
    public function fetch(array $context)
    {
        if (empty($context['path']) || !is_string($context['path'])) {
            throw new InvalidArgumentException('BinX CSV provider requires context[path].');
        }

        $path = $context['path'];
        if (!is_readable($path)) {
            throw new RuntimeException('CSV file is not readable.');
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Unable to open CSV file.');
        }

        $statement = new BankStatement();
        $statement->provider = $this->getKey();

        $headers = null;
        $lineNumber = 0;

        try {
            while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
                $lineNumber++;

                if ($lineNumber === 1 && isset($row[0])) {
                    $row[0] = $this->stripUtf8Bom($row[0]);
                }

                if ($this->isEmptyRow($row)) {
                    continue;
                }

                if ($headers === null) {
                    $first = isset($row[0]) ? trim((string) $row[0]) : '';

                    if ($first === 'Értéknap') {
                        $headers = array_map(array($this, 'cleanHeader'), $row);
                        $this->validateHeaders($headers);
                        continue;
                    }

                    if (isset($this->metaKeys[$first]) && isset($row[1])) {
                        $value = trim((string) $row[1]);
                        $statement->metadata[$first] = $value;
                        $property = $this->metaKeys[$first];

                        if ($property === 'periodStart' || $property === 'periodEnd') {
                            $value = $this->parseDate($value, $first);
                        } elseif ($property === 'accountNumber' || $property === 'conversionAccountNumber') {
                            $value = $this->normalizeAccountNumber($value);
                        }

                        $statement->{$property} = $value;
                    }

                    continue;
                }

                $data = $this->combineRow($headers, $row);
                $transaction = $this->mapTransaction($statement, $data, $lineNumber);
                $statement->addTransaction($transaction);
            }
        } finally {
            fclose($handle);
        }

        if ($headers === null) {
            throw new RuntimeException('BinX transaction header was not found in the CSV file.');
        }
        if ($statement->accountNumber === '') {
            throw new RuntimeException('BinX account number metadata is missing.');
        }
        if ($statement->currency === '') {
            throw new RuntimeException('BinX currency metadata is missing.');
        }

        return $statement;
    }

    /**
     * @param BankStatement $statement Statement metadata
     * @param array<string,string> $data CSV row
     * @param int $lineNumber CSV line number
     * @return BankTransaction
     * @throws Exception
     */
    private function mapTransaction($statement, array $data, $lineNumber)
    {
        $transaction = new BankTransaction();
        $transaction->provider = $this->getKey();
        $transaction->accountNumber = $statement->accountNumber;
        $transaction->externalTransactionId = trim($data['Tranzakció azonosító']);
        $transaction->valueDate = $this->parseDate($data['Értéknap'], 'Értéknap');
        $transaction->bookingDate = $this->parseDate($data['Könyvelés napja'], 'Könyvelés napja');
        $transaction->amount = $this->normalizeAmount($data['Összeg']);
        $transaction->currency = strtoupper(trim($statement->currency));
        $transaction->transactionType = trim($data['Tranzakció típusa']);
        $transaction->transactionCode = strtoupper(trim($data['Tranzakciós kód']));
        $transaction->counterpartyName = trim($data['Partner neve']);
        $transaction->counterpartyAccount = $this->normalizeAccountNumber($data['Partner bankszámlaszáma']);
        $transaction->reference = trim($data['Közlemény']);
        $transaction->sourceLine = (int) $lineNumber;
        $transaction->rawData = $data;

        $direction = strtoupper(trim($data['Terhelés vagy jóváírás (T/J)']));
        if ($direction !== 'T' && $direction !== 'J') {
            throw new RuntimeException('Invalid T/J direction on CSV line '.((int) $lineNumber).'.');
        }
        $transaction->direction = ($direction === 'T') ? 'debit' : 'credit';

        $numericAmount = (float) $transaction->amount;
        if (($direction === 'T' && $numericAmount > 0) || ($direction === 'J' && $numericAmount < 0)) {
            throw new RuntimeException('Amount sign contradicts T/J direction on CSV line '.((int) $lineNumber).'.');
        }

        // BinX can reuse one transaction id for several accounting entries (e.g. transfer + fee).
        // Therefore externalTransactionId is preserved for grouping, while externalEntryId is
        // a deterministic hash of the complete accounting entry for deduplication across exports.
        $fingerprint = array(
            $transaction->provider,
            $transaction->accountNumber,
            $transaction->externalTransactionId,
            $transaction->valueDate,
            $transaction->bookingDate,
            $transaction->amount,
            $transaction->currency,
            $transaction->transactionType,
            $transaction->transactionCode,
            $transaction->counterpartyName,
            $transaction->counterpartyAccount,
            $transaction->reference,
        );
        $transaction->externalEntryId = hash('sha256', implode("\x1f", $fingerprint));

        return $transaction;
    }

    /**
     * @param array<int,string> $headers Headers
     * @param array<int,string|null> $row Row
     * @return array<string,string>
     */
    private function combineRow(array $headers, array $row)
    {
        $data = array();
        foreach ($headers as $index => $header) {
            $data[$header] = isset($row[$index]) ? trim((string) $row[$index]) : '';
        }
        return $data;
    }

    /**
     * @param array<int,string> $headers Headers
     * @return void
     * @throws Exception
     */
    private function validateHeaders(array $headers)
    {
        $missing = array_diff($this->requiredColumns, $headers);
        if (!empty($missing)) {
            throw new RuntimeException('Missing BinX CSV columns: '.implode(', ', $missing));
        }
    }

    /**
     * @param string $value Date in Y.m.d
     * @param string $fieldName Field label
     * @return string SQL date in Y-m-d
     * @throws Exception
     */
    private function parseDate($value, $fieldName)
    {
        $value = trim((string) $value);
        $date = DateTimeImmutable::createFromFormat('!Y.m.d', $value);
        $errors = DateTimeImmutable::getLastErrors();
        if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw new RuntimeException('Invalid date in '.$fieldName.': '.$value);
        }
        return $date->format('Y-m-d');
    }

    /**
     * Normalize decimal representation without converting through float.
     *
     * @param string $value Amount
     * @return string
     * @throws Exception
     */
    private function normalizeAmount($value)
    {
        $value = str_replace(array("\xC2\xA0", ' '), '', trim((string) $value));
        if (strpos($value, ',') !== false && strpos($value, '.') === false) {
            $value = str_replace(',', '.', $value);
        }
        if (!preg_match('/^[+-]?\d+(?:\.\d+)?$/', $value)) {
            throw new RuntimeException('Invalid amount: '.$value);
        }
        return $value;
    }

    /**
     * @param string $accountNumber Bank account number
     * @return string
     */
    private function normalizeAccountNumber($accountNumber)
    {
        return preg_replace('/[^0-9A-Za-z]/u', '', trim((string) $accountNumber));
    }

    /**
     * @param string $header Header
     * @return string
     */
    private function cleanHeader($header)
    {
        return trim($this->stripUtf8Bom((string) $header));
    }

    /**
     * @param string $value Value
     * @return string
     */
    private function stripUtf8Bom($value)
    {
        if (substr($value, 0, 3) === "\xEF\xBB\xBF") {
            return substr($value, 3);
        }
        return $value;
    }

    /**
     * @param array<int,mixed> $row CSV row
     * @return bool
     */
    private function isEmptyRow(array $row)
    {
        foreach ($row as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }
        return true;
    }
}
