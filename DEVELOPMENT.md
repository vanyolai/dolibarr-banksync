# Development Guide — BankSync

> Last reviewed: 2026-10-19

## Repository role

```text
canonical source: vanyolai/dolibarr-banksync
source branch:    main
consumer prefix:  htdocs/custom/banksync
subtree mode:     squashed
```

Current production/integration version: **0.5.1**.

## Architectural model

BankSync deliberately separates acquisition, normalization, reconciliation and posting:

```text
provider
 -> provider-neutral statement/transactions
 -> source-account mapping
 -> event classification
 -> staging
 -> scored reconciliation candidates
 -> human confirmation
 -> posting preview
 -> explicit posting
 -> native Dolibarr objects
```

Provider-specific structures must not leak into reconciliation logic.

The module may use SQL for its own staging/reconciliation/audit tables, but native Dolibarr business objects must perform core financial mutations.

## Current implementation state

Current provider: **BinX CSV**.

Implemented:
- provider-neutral DTO/staging model;
- file/entry deduplication;
- account discovery and explicit native bank-account mapping;
- normalized bank-event classification;
- candidate matching for customer/supplier invoices, salaries and social/tax entries;
- N:N allocation model;
- partial/multi-invoice reconciliation;
- manual search;
- posting preview;
- explicit native customer/supplier invoice posting;
- explicit bank-fee posting through `PaymentVarious`;
- posting audit/idempotency boundary;
- dedicated posting permission.

Important current restrictions intentionally block unsafe posting:
- foreign currency without explicit rate workflow;
- credit-note settlement;
- mixed target types;
- multiple third parties in one payment;
- non-zero tolerated rounding difference;
- allocation beyond current outstanding balance.

## Development rules

- Reconciliation never implies posting.
- Suggestions, even 100% scores, require explicit confirmation.
- Never guess accounting accounts.
- Posting must use native Dolibarr APIs and preserve an auditable link to the staging transaction.
- A retry must not double-post.
- Provider additions implement the provider interface; they do not fork reconciliation logic.

## Roadmap

1. Validate native customer/supplier posting against representative full and partial payments.
2. Validate bank-fee posting and agree accounting account policy.
3. Implement signed credit-note settlement components.
4. Implement salary posting through `PaymentSalary`.
5. Implement social-contribution/tax posting through native workflows.
6. Add controlled internal-transfer and unmatched-generic-bank workflows.
7. Add explicit reversal/unposting assistance while preserving audit history.
8. Add Wise API provider.
9. Add CAMT.053 / MT940 providers.

Each financial-posting milestone requires DB-backed integration and representative sandbox acceptance; syntax tests are insufficient.

## Subtree update

```bash
git subtree pull \
  --prefix=htdocs/custom/banksync \
  https://github.com/vanyolai/dolibarr-banksync.git \
  main \
  --squash \
  -m "Update BankSync subtree"
```
