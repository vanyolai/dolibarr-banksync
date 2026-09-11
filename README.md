# Dolibarr BankSync

BankSync is a Dolibarr external module for importing and, later, synchronizing bank transactions from multiple data sources through a provider-neutral staging layer.

## Current status: 0.1.0

The first provider is **BinX CSV**. The module currently:

- installs as a standard Dolibarr external module;
- parses BinX transaction-history CSV exports including their metadata block;
- normalizes transactions into provider-neutral DTOs;
- stores imports and transactions in dedicated staging tables;
- prevents duplicate imports both at file level and transaction-entry level;
- preserves the BinX transaction identifier for grouping;
- creates a separate deterministic entry identifier because one BinX transaction ID can occur on multiple accounting rows (for example a transfer and its fee);
- provides a dashboard, import page, and staged transaction list;
- **does not yet create Dolibarr bank ledger entries or match invoices**.

This staging-first design intentionally separates ingestion from reconciliation and posting. Future providers (Wise API, CAMT.053, MT940, other CSV formats or AISP APIs) can feed the same normalized model.

## Installation

Clone the repository into Dolibarr's custom module directory so the module root is:

```text
htdocs/custom/banksync/
```

For example:

```bash
cd /path/to/dolibarr/htdocs/custom
git clone https://github.com/vanyolai/dolibarr-banksync.git banksync
```

Then open **Home → Setup → Modules/Applications**, find **Bank Sync**, and enable it. Dolibarr will create the staging tables automatically.

## BinX CSV format

The provider expects a BinX transaction export with the metadata block followed by the transaction header, for example:

```text
Konverziós számla száma,...
E-pénz rendelkezési számla száma,...
Időszak kezdete,2026.08.02
Időszak vége,2026.08.31
Devizanem,HUF
,
Értéknap,Könyvelés napja,Terhelés vagy jóváírás (T/J),Összeg,...
```

Required transaction columns are validated by name, so malformed or incompatible exports fail before anything is written to staging.

## Deduplication model

`Tranzakció azonosító` from BinX is stored as `external_transaction_id`, but it is **not assumed to be unique per accounting row**.

BankSync derives `external_entry_id` as a SHA-256 fingerprint from the normalized accounting entry. The database uniqueness constraint is:

```text
(entity, provider, account_number, external_entry_id)
```

This allows overlapping statement periods to be imported safely while keeping related BinX rows grouped under their original transaction ID.

## Architecture

```text
BinX CSV ───────┐
Wise API ───────┤
CAMT.053 ───────┤   BankDataProviderInterface
MT940 ──────────┘              │
                               ▼
                         BankStatement
                               │
                         BankTransaction[]
                               │
                               ▼
                        BankSync staging
                               │
                    reconciliation / posting
                         (future layer)
```

Provider-specific structures must not leak into reconciliation logic. The raw source row is retained as JSON for diagnostics and future migrations.

## Development

A small parser smoke test can be run without Dolibarr:

```bash
php tests/binx_parser_smoke.php
```

## Roadmap

1. Bind imported account numbers to Dolibarr bank accounts.
2. Add reconciliation/matching rules for customer and supplier invoices.
3. Add review/approval workflow before posting.
4. Post approved transactions to Dolibarr's bank module without duplication.
5. Add a Wise API provider.
6. Add CAMT.053 / MT940 statement providers.

## License

GPL-3.0-or-later, matching Dolibarr's licensing model.
