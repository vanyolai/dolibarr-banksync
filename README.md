# Dolibarr BankSync

BankSync is a Dolibarr external module for importing, classifying and reconciling bank transactions from multiple data sources through a provider-neutral staging layer.

## Current status: 0.2.0

The first provider is **BinX CSV**. The module currently:

- installs as a standard Dolibarr external module;
- parses BinX transaction-history CSV exports including their metadata block;
- normalizes transactions into provider-neutral DTOs;
- stores imports and transactions in dedicated staging tables;
- prevents duplicate imports both at file level and transaction-entry level;
- preserves the BinX transaction identifier for grouping;
- creates a separate deterministic entry identifier because one BinX transaction ID can occur on multiple accounting rows (for example a transfer and its fee);
- discovers provider source accounts and maps them explicitly to native Dolibarr bank accounts;
- proposes exact account-number/IBAN matches but requires manual confirmation before posting;
- classifies bank events separately from reconciliation targets (`transfer`, `card`, `direct_debit`, `bank_fee`, `cash`, `conversion`, etc.);
- maps compatible bank event types to native Dolibarr payment codes such as `VIR`, `CB`, `PRE` and `LIQ`;
- provides a generic N:N reconciliation model for future links to customer invoices, supplier invoices, salaries, social contributions, taxes and other Dolibarr objects;
- provides a dashboard, source-account mapping page, import page, and staged transaction list;
- **does not yet create native Dolibarr payment/bank records automatically**.

This staging-first design intentionally separates ingestion, bank-event classification, reconciliation and posting. Future providers (Wise API, CAMT.053, MT940, other CSV formats or AISP APIs) can feed the same normalized model.

## Repository and development model

This repository is the **canonical development source** of the BankSync module. Development, commits, tests and releases must be made here first.

The module is embedded into `vanyolai/dolibarr` as a squash-merged Git subtree:

```text
repository: vanyolai/dolibarr-banksync
branch:     main
consumer:   vanyolai/dolibarr
branch:     23.0
prefix:     htdocs/custom/banksync
```

The copy under `htdocs/custom/banksync` in the Dolibarr repository is therefore a downstream integration copy. Do not develop or hot-fix the module there directly unless the change is immediately exported back to this repository.

A local Dolibarr checkout can configure the source repository as a remote once:

```bash
git remote add banksync https://github.com/vanyolai/dolibarr-banksync.git
git fetch banksync
```

The initial subtree integration is performed with:

```bash
git subtree add \
  --prefix=htdocs/custom/banksync \
  banksync main \
  --squash
```

After development has been committed and pushed to this repository, update the Dolibarr integration with:

```bash
git fetch banksync
git subtree pull \
  --prefix=htdocs/custom/banksync \
  banksync main \
  --squash
```

This keeps the standalone module history clean while the Dolibarr repository records only explicit integration points and pins the exact BankSync source commit through Git subtree metadata.

If a change is ever made inside the Dolibarr subtree first, export it back before continuing normal development:

```bash
git subtree split --prefix=htdocs/custom/banksync -b banksync-export
git push banksync banksync-export:main
```

Normal development should follow the opposite direction: **BankSync repository → Dolibarr subtree**.

## Installation

In the integrated `vanyolai/dolibarr` checkout the module is already located at:

```text
htdocs/custom/banksync/
```

For an independent Dolibarr installation that does not consume the parent repository, cloning this repository directly into that location also works:

```bash
cd /path/to/dolibarr/htdocs/custom
git clone https://github.com/vanyolai/dolibarr-banksync.git banksync
```

Then open **Home → Setup → Modules/Applications**, find **Bank Sync**, and enable it. Dolibarr will create the staging tables automatically.

Development upgrades from 0.1.x are migrated lazily on first BankSync page access. The current migration helper targets MariaDB/MySQL, matching the deployment environment.

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

## Account mapping

A provider account is represented independently from the native Dolibarr bank account:

```text
BinX source account
       │
       ▼
llx_banksync_account
       │ explicit confirmed mapping
       ▼
llx_bank_account
```

The source account number is normalized for matching. If exactly one open Dolibarr bank account has the same normalized account number or IBAN and a compatible currency, BankSync stores it as a **suggestion only**. A user must confirm the mapping before later posting logic may use it.

## Transaction classification

Bank-event classification is deliberately separate from business reconciliation. For example, `transfer` describes how money moved, while the same transaction may later reconcile to a supplier invoice, salary or tax payment.

Current normalized event types include:

```text
transfer
card
direct_debit
bank_fee
cash
conversion
internal_transfer
refund
other
```

For BinX, `CHRG` is classified as `bank_fee`, while `DMCT` is currently classified as `transfer` and mapped to Dolibarr payment code `VIR`.

## Reconciliation model

`llx_banksync_match` is an N:N allocation table between one bank transaction and one or more Dolibarr targets. Target types are intentionally polymorphic, including:

```text
customer_invoice
supplier_invoice
salary
social_contribution
expense_report
tax
bank_fee
internal_transfer
other
```

This allows one payment to settle multiple invoices and supports partial payments without coupling provider-specific structures to Dolibarr business objects.

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
                       source-account mapping
                               │
                               ▼
                       event classification
                               │
                               ▼
                        BankSync staging
                               │
                               ▼
                      reconciliation matches
                               │
                               ▼
                  native Dolibarr payment/posting
                            (next layer)
```

Provider-specific structures must not leak into reconciliation logic. The raw source row is retained as JSON for diagnostics and future migrations.

## Development

Smoke tests that do not require a Dolibarr runtime:

```bash
php tests/binx_parser_smoke.php
php tests/classifier_smoke.php
```

## Roadmap

1. Add automatic/manual matching for customer and supplier invoices, including split and partial allocations.
2. Add native payment posting through `Paiement` / `PaiementFourn` and mark fully settled invoices paid.
3. Add salary reconciliation through `PaymentSalary`.
4. Add social-contribution/tax reconciliation through `PaymentSocialContribution` and related Dolibarr objects.
5. Add controlled posting for bank fees, internal transfers and unmatched generic bank entries.
6. Add review/approval workflow and posting idempotency.
7. Add a Wise API provider.
8. Add CAMT.053 / MT940 statement providers.

## License

GPL-3.0-or-later, matching Dolibarr's licensing model.
