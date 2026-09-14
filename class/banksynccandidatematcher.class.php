<?php
/* SPDX-License-Identifier: GPL-3.0-or-later */

require_once __DIR__.'/banksyncmatchmanager.class.php';

/**
 * Finds Dolibarr business objects that may explain a staged bank transaction.
 *
 * Matching is deliberately advisory: this class never creates native Dolibarr payments.
 * It scores candidates and stores them as suggested BankSync matches for human review.
 */
class BankSyncCandidateMatcher
{
    /** @var DoliDB */
    private $db;
    /** @var int */
    private $entity;
    /** @var BankSyncMatchManager */
    private $matchManager;
    /** @var array<int,array<int,string>> */
    private $partnerAccounts = array();

    public function __construct($db, $entity)
    {
        $this->db = $db;
        $this->entity = (int) $entity;
        $this->matchManager = new BankSyncMatchManager($db, $this->entity);
    }

    /**
     * Rebuild suggested matches for one transaction and return scored candidates.
     * Confirmed/rejected/posted decisions are preserved.
     *
     * @param int $transactionId BankSync transaction rowid
     * @param int $userId User doing the refresh
     * @param int $limit Maximum number of candidates to persist/return
     * @return array<int,array<string,mixed>>
     */
    public function refreshSuggestions($transactionId, $userId, $limit = 8)
    {
        $transaction = $this->fetchTransaction($transactionId);
        if (!$transaction) {
            throw new RuntimeException('BankSync transaction not found.');
        }

        $this->matchManager->clearSuggested($transactionId);

        if ((string) $transaction->bank_event_type === 'bank_fee') {
            return array();
        }

        $candidates = array();
        $isCredit = ((string) $transaction->direction === 'credit' || (float) $transaction->amount > 0);
        $isDebit = !$isCredit;
        $eventType = (string) $transaction->bank_event_type;

        if ($isCredit && in_array($eventType, array('transfer', 'refund', 'other'), true)) {
            $candidates = array_merge($candidates, $this->findCustomerInvoices($transaction));
        }

        if ($isDebit && in_array($eventType, array('transfer', 'card', 'direct_debit', 'other'), true)) {
            $candidates = array_merge($candidates, $this->findSupplierInvoices($transaction));
        }

        if ($isDebit && in_array($eventType, array('transfer', 'other'), true)) {
            $candidates = array_merge($candidates, $this->findSalaries($transaction));
            $candidates = array_merge($candidates, $this->findSocialContributions($transaction));
        }

        usort($candidates, function ($a, $b) {
            if ((int) $a['confidence'] !== (int) $b['confidence']) {
                return ((int) $a['confidence'] > (int) $b['confidence']) ? -1 : 1;
            }
            $dateA = isset($a['date_distance']) ? (int) $a['date_distance'] : PHP_INT_MAX;
            $dateB = isset($b['date_distance']) ? (int) $b['date_distance'] : PHP_INT_MAX;
            if ($dateA !== $dateB) {
                return $dateA < $dateB ? -1 : 1;
            }
            $amountA = isset($a['amount_distance']) ? (float) $a['amount_distance'] : PHP_INT_MAX;
            $amountB = isset($b['amount_distance']) ? (float) $b['amount_distance'] : PHP_INT_MAX;
            if ($amountA != $amountB) {
                return $amountA < $amountB ? -1 : 1;
            }
            return strcmp((string) $a['target_type'].':'.(string) $a['target_id'], (string) $b['target_type'].':'.(string) $b['target_id']);
        });

        $deduped = array();
        $seen = array();
        foreach ($candidates as $candidate) {
            if ((int) $candidate['confidence'] < 45) {
                continue;
            }
            $key = (string) $candidate['target_type'].':'.(int) $candidate['target_id'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $deduped[] = $candidate;
            if (count($deduped) >= max(1, (int) $limit)) {
                break;
            }
        }

        foreach ($deduped as $candidate) {
            $method = 'auto:'.implode('+', array_slice($candidate['reason_codes'], 0, 4));
            $this->matchManager->upsertSuggestion(
                (int) $transactionId,
                (string) $candidate['target_type'],
                (int) $candidate['target_id'],
                (string) $candidate['allocated_amount'],
                (int) $candidate['confidence'],
                substr($method, 0, 64),
                (int) $userId
            );
        }

        $statuses = array();
        foreach ($this->matchManager->getForTransaction($transactionId) as $match) {
            $key = (string) $match->target_type.':'.(int) $match->target_id;
            $statuses[$key] = array(
                'match_id' => (int) $match->rowid,
                'status' => (string) $match->status,
                'allocated_amount' => (string) $match->allocated_amount,
            );
        }

        foreach ($deduped as &$candidate) {
            $key = (string) $candidate['target_type'].':'.(int) $candidate['target_id'];
            if (isset($statuses[$key])) {
                $candidate['match_id'] = $statuses[$key]['match_id'];
                $candidate['status'] = $statuses[$key]['status'];
                $candidate['allocated_amount'] = $statuses[$key]['allocated_amount'];
            } else {
                $candidate['match_id'] = 0;
                $candidate['status'] = 'suggested';
            }
        }
        unset($candidate);

        return $deduped;
    }

    /**
     * Refresh candidates for a batch of unreconciled transactions.
     *
     * @return int Number of transactions scanned
     */
    public function refreshOpenTransactions($userId, $limit = 100)
    {
        $sql = 'SELECT t.rowid FROM '.$this->db->prefix().'banksync_transaction AS t';
        $sql .= ' WHERE t.entity = '.$this->entity;
        $sql .= ' AND t.fk_bank IS NULL';
        $sql .= " AND COALESCE(t.bank_event_type, '') <> 'bank_fee'";
        $sql .= " AND t.status <> 'posted'";
        $sql .= ' AND NOT EXISTS (SELECT 1 FROM '.$this->db->prefix().'banksync_match AS m';
        $sql .= ' WHERE m.entity = t.entity AND m.fk_transaction = t.rowid';
        $sql .= " AND m.status IN ('confirmed', 'posted'))";
        $sql .= ' ORDER BY t.booking_date DESC, t.rowid DESC';
        $sql .= $this->db->plimit(max(1, (int) $limit), 0);

        $resql = $this->db->query($sql);
        if (!$resql) {
            throw new RuntimeException($this->db->lasterror());
        }

        $ids = array();
        while ($obj = $this->db->fetch_object($resql)) {
            $ids[] = (int) $obj->rowid;
        }
        $this->db->free($resql);

        foreach ($ids as $id) {
            $this->refreshSuggestions($id, $userId);
        }

        return count($ids);
    }

    /** @return object|null */
    public function fetchTransaction($transactionId)
    {
        $sql = 'SELECT t.*, a.fk_bank_account, a.source_account_number, ba.label AS bank_account_label, ba.ref AS bank_account_ref';
        $sql .= ' FROM '.$this->db->prefix().'banksync_transaction AS t';
        $sql .= ' LEFT JOIN '.$this->db->prefix().'banksync_account AS a ON a.rowid = t.fk_banksync_account';
        $sql .= ' LEFT JOIN '.$this->db->prefix().'bank_account AS ba ON ba.rowid = a.fk_bank_account';
        $sql .= ' WHERE t.entity = '.$this->entity.' AND t.rowid = '.((int) $transactionId).' LIMIT 1';
        $resql = $this->db->query($sql);
        if (!$resql) {
            throw new RuntimeException($this->db->lasterror());
        }
        $obj = $this->db->fetch_object($resql);
        $this->db->free($resql);
        return $obj ?: null;
    }

    private function findCustomerInvoices($transaction)
    {
        $rows = array();
        $dateFilter = $this->dateFilter('f.datef', (string) $transaction->booking_date, 365, 45);
        $sql = 'SELECT f.rowid, f.ref, f.ref_client, f.datef, f.date_lim_reglement, f.total_ttc, f.fk_soc, s.nom,';
        $sql .= ' COALESCE((SELECT SUM(pf.amount) FROM '.$this->db->prefix().'paiement_facture AS pf WHERE pf.fk_facture = f.rowid), 0) AS paid_amount';
        $sql .= ' FROM '.$this->db->prefix().'facture AS f';
        $sql .= ' INNER JOIN '.$this->db->prefix().'societe AS s ON s.rowid = f.fk_soc';
        $sql .= ' WHERE f.entity = '.$this->entity.' AND f.paye = 0 AND f.fk_statut = 1 AND f.total_ttc > 0';
        $sql .= $dateFilter;
        $sql .= ' ORDER BY f.datef DESC';
        $sql .= $this->db->plimit(250, 0);

        $resql = $this->db->query($sql);
        if (!$resql) {
            return $rows;
        }
        while ($obj = $this->db->fetch_object($resql)) {
            $remaining = max(0, (float) $obj->total_ttc - (float) $obj->paid_amount);
            if ($remaining <= 0.00001) {
                continue;
            }
            $candidate = $this->scoreInvoice($transaction, $obj, BankSyncMatchManager::TARGET_CUSTOMER_INVOICE, $remaining, array($obj->ref, $obj->ref_client));
            if ($candidate !== null) {
                $candidate['url'] = '/compta/facture/card.php?facid='.(int) $obj->rowid;
                $rows[] = $candidate;
            }
        }
        $this->db->free($resql);
        return $rows;
    }

    private function findSupplierInvoices($transaction)
    {
        $rows = array();
        $dateFilter = $this->dateFilter('f.datef', (string) $transaction->booking_date, 365, 45);
        $sql = 'SELECT f.rowid, f.ref, f.ref_supplier, f.datef, f.date_lim_reglement, f.total_ttc, f.fk_soc, s.nom,';
        $sql .= ' COALESCE((SELECT SUM(pf.amount) FROM '.$this->db->prefix().'paiementfourn_facturefourn AS pf WHERE pf.fk_facturefourn = f.rowid), 0) AS paid_amount';
        $sql .= ' FROM '.$this->db->prefix().'facture_fourn AS f';
        $sql .= ' INNER JOIN '.$this->db->prefix().'societe AS s ON s.rowid = f.fk_soc';
        $sql .= ' WHERE f.entity = '.$this->entity.' AND f.paye = 0 AND f.fk_statut = 1 AND f.total_ttc > 0';
        $sql .= $dateFilter;
        $sql .= ' ORDER BY f.datef DESC';
        $sql .= $this->db->plimit(250, 0);

        $resql = $this->db->query($sql);
        if (!$resql) {
            return $rows;
        }
        while ($obj = $this->db->fetch_object($resql)) {
            $remaining = max(0, (float) $obj->total_ttc - (float) $obj->paid_amount);
            if ($remaining <= 0.00001) {
                continue;
            }
            $candidate = $this->scoreInvoice($transaction, $obj, BankSyncMatchManager::TARGET_SUPPLIER_INVOICE, $remaining, array($obj->ref_supplier, $obj->ref));
            if ($candidate !== null) {
                $candidate['url'] = '/fourn/facture/card.php?facid='.(int) $obj->rowid;
                $rows[] = $candidate;
            }
        }
        $this->db->free($resql);
        return $rows;
    }

    private function scoreInvoice($transaction, $invoice, $targetType, $remaining, array $refs)
    {
        $score = 0;
        $reasons = array();
        $reasonCodes = array();
        $amount = abs((float) $transaction->amount);
        $isCard = ((string) $transaction->bank_event_type === 'card');

        if ($this->moneyEquals($amount, $remaining)) {
            $score += 40;
            $reasons[] = 'Exact amount';
            $reasonCodes[] = 'amount';
        } elseif ($remaining > 0 && $amount > 0 && abs($amount - $remaining) / max($amount, $remaining) <= 0.02) {
            $score += 18;
            $reasons[] = 'Amount within 2%';
            $reasonCodes[] = 'amount_near';
        }

        $referenceStrength = $this->referenceStrength((string) $transaction->reference, $refs);
        if ($referenceStrength === 2) {
            $score += 45;
            $reasons[] = 'Invoice reference in bank reference';
            $reasonCodes[] = 'reference';
        } elseif ($referenceStrength === 1) {
            $score += 25;
            $reasons[] = 'Partial reference match';
            $reasonCodes[] = 'reference_partial';
        }

        $account = (string) $transaction->counterparty_account;
        if (!$isCard && $account !== '' && $this->partnerAccountMatches((int) $invoice->fk_soc, $account)) {
            $score += 35;
            $reasons[] = 'Counterparty bank account';
            $reasonCodes[] = 'account';
        }

        $nameScore = $this->nameStrength((string) $transaction->counterparty_name, (string) $invoice->nom);
        if ($nameScore >= 2) {
            $score += $isCard ? 50 : 20;
            $reasons[] = $isCard ? 'Card merchant name' : 'Counterparty name';
            $reasonCodes[] = 'partner';
        } elseif ($nameScore === 1) {
            $score += $isCard ? 30 : 10;
            $reasons[] = $isCard ? 'Similar card merchant name' : 'Similar counterparty name';
            $reasonCodes[] = 'partner_similar';
        }

        $days = $this->dayDistance((string) $transaction->booking_date, (string) $invoice->datef);
        if ($days !== null) {
            if ($isCard) {
                if ($days <= 14) {
                    $score += 15;
                    $reasons[] = 'Card transaction date within 14 days';
                    $reasonCodes[] = 'date';
                } elseif ($days <= 45) {
                    $score += 10;
                    $reasons[] = 'Card transaction date within 45 days';
                    $reasonCodes[] = 'date_near';
                } elseif ($days <= 90) {
                    $score += 5;
                    $reasons[] = 'Card transaction date within 90 days';
                    $reasonCodes[] = 'date_near';
                }
            } else {
                if ($days <= 14) {
                    $score += 10;
                    $reasons[] = 'Date within 14 days';
                    $reasonCodes[] = 'date';
                } elseif ($days <= 60) {
                    $score += 5;
                    $reasons[] = 'Date within 60 days';
                    $reasonCodes[] = 'date_near';
                }
            }
        }

        $score = min(100, $score);
        if ($score < 45) {
            return null;
        }

        $ref = '';
        foreach ($refs as $refCandidate) {
            if (trim((string) $refCandidate) !== '') {
                $ref = (string) $refCandidate;
                break;
            }
        }

        return array(
            'target_type' => $targetType,
            'target_id' => (int) $invoice->rowid,
            'ref' => $ref,
            'label' => (string) $invoice->nom,
            'date' => (string) $invoice->datef,
            'remaining_amount' => $this->decimalString($remaining),
            'allocated_amount' => $this->decimalString(min($amount, $remaining)),
            'confidence' => $score,
            'reasons' => $reasons,
            'reason_codes' => $reasonCodes,
            'date_distance' => $days === null ? PHP_INT_MAX : $days,
            'amount_distance' => abs($amount - $remaining),
            'url' => '',
        );
    }

    private function findSalaries($transaction)
    {
        $rows = array();
        $periodFilter = $this->dateFilter('COALESCE(s.dateep, s.datesp, s.datep)', (string) $transaction->booking_date, 180, 45);
        $sql = 'SELECT s.rowid, s.ref, s.datep, s.datev, s.amount, s.label, s.datesp, s.dateep, s.fk_user,';
        $sql .= ' u.firstname, u.lastname,';
        $sql .= ' COALESCE((SELECT SUM(ps.amount) FROM '.$this->db->prefix().'payment_salary AS ps WHERE ps.fk_salary = s.rowid), 0) AS paid_amount';
        $sql .= ' FROM '.$this->db->prefix().'salary AS s';
        $sql .= ' INNER JOIN '.$this->db->prefix().'user AS u ON u.rowid = s.fk_user';
        $sql .= ' WHERE s.entity = '.$this->entity.' AND s.paye = 0 AND s.amount > 0';
        $sql .= $periodFilter;
        $sql .= ' ORDER BY COALESCE(s.dateep, s.datesp, s.datep) DESC, s.rowid DESC';
        $sql .= $this->db->plimit(150, 0);
        $resql = $this->db->query($sql);
        if (!$resql) {
            return $rows;
        }

        $bankText = $this->normalizeText((string) $transaction->counterparty_name.' '.(string) $transaction->reference);
        $salaryMarker = $this->containsAny($bankText, array('munkaber', 'berfizetes', 'salary', 'wage', 'payroll'));
        $amount = abs((float) $transaction->amount);

        while ($obj = $this->db->fetch_object($resql)) {
            $remaining = max(0, (float) $obj->amount - (float) $obj->paid_amount);
            if ($remaining <= 0.00001) {
                continue;
            }
            $score = 0;
            $reasons = array();
            $reasonCodes = array();

            if ($this->moneyEquals($amount, $remaining)) {
                $score += 45;
                $reasons[] = 'Exact amount';
                $reasonCodes[] = 'amount';
            } elseif ($remaining > 0 && $amount > 0 && abs($amount - $remaining) / max($amount, $remaining) <= 0.02) {
                $score += 20;
                $reasons[] = 'Amount within 2%';
                $reasonCodes[] = 'amount_near';
            }
            if ($salaryMarker) {
                $score += 25;
                $reasons[] = 'Salary marker in bank text';
                $reasonCodes[] = 'salary_marker';
            }

            $employee = trim((string) $obj->lastname.' '.(string) $obj->firstname);
            $nameScore = $this->nameStrength((string) $transaction->counterparty_name, $employee);
            if ($nameScore >= 2) {
                $score += 30;
                $reasons[] = 'Employee name';
                $reasonCodes[] = 'employee';
            } elseif ($nameScore === 1) {
                $score += 15;
                $reasons[] = 'Similar employee name';
                $reasonCodes[] = 'employee_similar';
            }

            $periodStart = (string) $obj->datesp;
            $periodEnd = (string) $obj->dateep;
            $periodDate = trim($periodEnd) !== '' ? $periodEnd : (trim($periodStart) !== '' ? $periodStart : (string) $obj->datep);
            $days = $this->dayDistance((string) $transaction->booking_date, $periodDate);
            if ($days !== null && $days <= 10) {
                $score += 20;
                $reasons[] = 'Salary period close to bank date';
                $reasonCodes[] = 'salary_period';
            } elseif ($days !== null && $days <= 31) {
                $score += 10;
                $reasons[] = 'Salary period near bank date';
                $reasonCodes[] = 'date';
            } elseif ($days !== null && $days <= 62) {
                $score += 5;
                $reasons[] = 'Salary period within 62 days';
                $reasonCodes[] = 'date_near';
            }

            $score = min(100, $score);
            if ($score < 45) {
                continue;
            }

            $rows[] = array(
                'target_type' => BankSyncMatchManager::TARGET_SALARY,
                'target_id' => (int) $obj->rowid,
                'ref' => trim((string) $obj->ref) !== '' ? (string) $obj->ref : '#'.(int) $obj->rowid,
                'label' => $employee,
                'date' => $this->salaryPeriodLabel($periodStart, $periodEnd, (string) $obj->datep),
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'remaining_amount' => $this->decimalString($remaining),
                'allocated_amount' => $this->decimalString(min($amount, $remaining)),
                'confidence' => $score,
                'reasons' => $reasons,
                'reason_codes' => $reasonCodes,
                'date_distance' => $days === null ? PHP_INT_MAX : $days,
                'amount_distance' => abs($amount - $remaining),
                'url' => '/salaries/card.php?id='.(int) $obj->rowid,
            );
        }
        $this->db->free($resql);
        return $rows;
    }

    private function findSocialContributions($transaction)
    {
        $rows = array();
        $dateFilter = $this->dateFilter('c.date_ech', (string) $transaction->booking_date, 180, 90);
        $sql = 'SELECT c.rowid, c.ref, c.date_ech, c.libelle, c.amount, c.periode, c.fk_type,';
        $sql .= ' ct.libelle AS type_label, ct.code AS type_code,';
        $sql .= ' COALESCE((SELECT SUM(pc.amount) FROM '.$this->db->prefix().'paiementcharge AS pc WHERE pc.fk_charge = c.rowid), 0) AS paid_amount';
        $sql .= ' FROM '.$this->db->prefix().'chargesociales AS c';
        $sql .= ' LEFT JOIN '.$this->db->prefix().'c_chargesociales AS ct ON ct.id = c.fk_type';
        $sql .= ' WHERE c.entity = '.$this->entity.' AND c.paye = 0 AND c.amount > 0';
        $sql .= $dateFilter;
        $sql .= ' ORDER BY c.date_ech DESC, c.rowid DESC';
        $sql .= $this->db->plimit(150, 0);
        $resql = $this->db->query($sql);
        if (!$resql) {
            return $rows;
        }

        $bankText = $this->normalizeText((string) $transaction->counterparty_name.' '.(string) $transaction->reference);
        $taxMarker = $this->containsAny($bankText, array('nav', 'ado', 'jarulek', 'szocho', 'szja', 'tb', 'social', 'tax'));
        $amount = abs((float) $transaction->amount);

        while ($obj = $this->db->fetch_object($resql)) {
            $remaining = max(0, (float) $obj->amount - (float) $obj->paid_amount);
            if ($remaining <= 0.00001) {
                continue;
            }

            $score = 0;
            $reasons = array();
            $reasonCodes = array();
            if ($this->moneyEquals($amount, $remaining)) {
                $score += 45;
                $reasons[] = 'Exact amount';
                $reasonCodes[] = 'amount';
            }
            if ($taxMarker) {
                $score += 20;
                $reasons[] = 'Tax/social contribution marker';
                $reasonCodes[] = 'tax_marker';
            }

            $targetText = $this->normalizeText((string) $obj->libelle.' '.(string) $obj->type_label.' '.(string) $obj->type_code);
            $overlap = $this->tokenOverlap($bankText, $targetText);
            if ($overlap >= 2) {
                $score += 25;
                $reasons[] = 'Matching tax/contribution terms';
                $reasonCodes[] = 'label';
            } elseif ($overlap === 1) {
                $score += 12;
                $reasons[] = 'One matching tax/contribution term';
                $reasonCodes[] = 'label_partial';
            }

            $days = $this->dayDistance((string) $transaction->booking_date, (string) $obj->date_ech);
            if ($days !== null && $days <= 31) {
                $score += 10;
                $reasons[] = 'Due date proximity';
                $reasonCodes[] = 'date';
            } elseif ($days !== null && $days <= 90) {
                $score += 5;
                $reasons[] = 'Due date within 90 days';
                $reasonCodes[] = 'date_near';
            }

            $score = min(100, $score);
            if ($score < 50) {
                continue;
            }

            $label = trim((string) $obj->libelle);
            if (trim((string) $obj->type_label) !== '') {
                $label .= ($label !== '' ? ' — ' : '').(string) $obj->type_label;
            }
            $rows[] = array(
                'target_type' => BankSyncMatchManager::TARGET_SOCIAL_CONTRIBUTION,
                'target_id' => (int) $obj->rowid,
                'ref' => trim((string) $obj->ref) !== '' ? (string) $obj->ref : '#'.(int) $obj->rowid,
                'label' => $label,
                'date' => (string) $obj->date_ech,
                'remaining_amount' => $this->decimalString($remaining),
                'allocated_amount' => $this->decimalString(min($amount, $remaining)),
                'confidence' => $score,
                'reasons' => $reasons,
                'reason_codes' => $reasonCodes,
                'url' => '/compta/sociales/card.php?id='.(int) $obj->rowid,
            );
        }
        $this->db->free($resql);
        return $rows;
    }

    private function partnerAccountMatches($partnerId, $bankAccount)
    {
        $partnerId = (int) $partnerId;
        if ($partnerId <= 0) {
            return false;
        }
        if (!isset($this->partnerAccounts[$partnerId])) {
            $this->partnerAccounts[$partnerId] = array();
            $sql = 'SELECT number, iban_prefix, code_banque, code_guichet, cle_rib';
            $sql .= ' FROM '.$this->db->prefix().'societe_rib';
            $sql .= ' WHERE fk_soc = '.$partnerId.' AND entity = '.$this->entity." AND type = 'ban' AND status = 1";
            $resql = $this->db->query($sql);
            if ($resql) {
                while ($obj = $this->db->fetch_object($resql)) {
                    $values = array(
                        (string) $obj->number,
                        (string) $obj->iban_prefix,
                        (string) $obj->code_banque.(string) $obj->code_guichet.(string) $obj->number.(string) $obj->cle_rib,
                    );
                    foreach ($values as $value) {
                        $normalized = $this->normalizeAccount($value);
                        if ($normalized !== '') {
                            $this->partnerAccounts[$partnerId][$normalized] = $normalized;
                        }
                    }
                }
                $this->db->free($resql);
            }
        }
        $needle = $this->normalizeAccount($bankAccount);
        return $needle !== '' && isset($this->partnerAccounts[$partnerId][$needle]);
    }

    private function referenceStrength($bankReference, array $refs)
    {
        $bank = $this->normalizeReference($bankReference);
        if ($bank === '') {
            return 0;
        }
        foreach ($refs as $ref) {
            $candidate = $this->normalizeReference((string) $ref);
            if (strlen($candidate) < 4) {
                continue;
            }
            if ($bank === $candidate || strpos($bank, $candidate) !== false) {
                return 2;
            }
            if (strlen($candidate) >= 6) {
                $tail = substr($candidate, -6);
                if ($tail !== '' && strpos($bank, $tail) !== false) {
                    return 1;
                }
            }
        }
        return 0;
    }

    private function nameStrength($bankName, $targetName)
    {
        $a = $this->normalizeCompanyName($bankName);
        $b = $this->normalizeCompanyName($targetName);
        if ($a === '' || $b === '') {
            return 0;
        }
        $compactA = str_replace(' ', '', $a);
        $compactB = str_replace(' ', '', $b);
        if ($a === $b || strpos($a, $b) !== false || strpos($b, $a) !== false) {
            return 2;
        }
        if (strlen($compactA) >= 4 && strlen($compactB) >= 4 && ($compactA === $compactB || strpos($compactA, $compactB) !== false || strpos($compactB, $compactA) !== false)) {
            return 2;
        }
        similar_text($a, $b, $percentSpaced);
        similar_text($compactA, $compactB, $percentCompact);
        return max($percentSpaced, $percentCompact) >= 72 ? 1 : 0;
    }

    private function tokenOverlap($a, $b)
    {
        $stop = array('nav' => true, 'kft' => true, 'bt' => true, 'zrt' => true, 'ado' => true, 'tax' => true);
        $ta = array_filter(explode(' ', $a), function ($v) use ($stop) { return strlen($v) >= 2 && !isset($stop[$v]); });
        $tb = array_filter(explode(' ', $b), function ($v) use ($stop) { return strlen($v) >= 2 && !isset($stop[$v]); });
        if (empty($ta) || empty($tb)) {
            return 0;
        }
        return count(array_intersect(array_unique($ta), array_unique($tb)));
    }

    private function dateFilter($field, $bookingDate, $daysBack, $daysForward)
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $bookingDate)) {
            return '';
        }
        $date = $this->db->escape($bookingDate);
        return " AND {$field} BETWEEN DATE_SUB('{$date}', INTERVAL ".((int) $daysBack)." DAY) AND DATE_ADD('{$date}', INTERVAL ".((int) $daysForward)." DAY)";
    }

    private function dayDistance($dateA, $dateB)
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}/', $dateA) || !preg_match('/^\d{4}-\d{2}-\d{2}/', $dateB)) {
            return null;
        }
        $a = strtotime(substr($dateA, 0, 10));
        $b = strtotime(substr($dateB, 0, 10));
        if ($a === false || $b === false) {
            return null;
        }
        return (int) floor(abs($a - $b) / 86400);
    }

    private function salaryPeriodLabel($start, $end, $fallback = '')
    {
        $start = trim((string) $start);
        $end = trim((string) $end);
        if ($start !== '' && $end !== '') return $start.' – '.$end;
        if ($start !== '') return $start;
        if ($end !== '') return $end;
        return trim((string) $fallback);
    }

    private function moneyEquals($a, $b)
    {
        return abs((float) $a - (float) $b) <= 0.5;
    }

    private function decimalString($value)
    {
        return number_format((float) $value, 8, '.', '');
    }

    private function normalizeReference($value)
    {
        return strtoupper(preg_replace('/[^A-Z0-9]/', '', $this->ascii((string) $value)));
    }

    private function normalizeAccount($value)
    {
        return strtoupper(preg_replace('/[^A-Z0-9]/', '', (string) $value));
    }

    private function normalizeCompanyName($value)
    {
        $value = $this->normalizeText($value);
        $parts = array_values(array_filter(explode(' ', $value), function ($part) {
            return !in_array($part, array('kft', 'bt', 'zrt', 'nyrt', 'kkt', 'ev', 'egyeni', 'vallalkozo'), true);
        }));
        return implode(' ', $parts);
    }

    private function normalizeText($value)
    {
        $value = strtolower($this->ascii((string) $value));
        $value = preg_replace('/[^a-z0-9]+/', ' ', $value);
        return trim(preg_replace('/\s+/', ' ', $value));
    }

    private function ascii($value)
    {
        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
            if ($converted !== false) {
                return $converted;
            }
        }
        return $value;
    }

    private function containsAny($haystack, array $needles)
    {
        foreach ($needles as $needle) {
            if ($needle !== '' && strpos($haystack, $needle) !== false) {
                return true;
            }
        }
        return false;
    }
}
