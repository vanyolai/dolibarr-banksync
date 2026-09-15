# BankSync development decisions

This document records intentional architectural and workflow decisions so they remain explicit across future development.

## Repository and integration

- `vanyolai/dolibarr-banksync` is the canonical development source.
- The module is embedded into `vanyolai/dolibarr` under `htdocs/custom/banksync` using `git subtree --squash`.
- Development should happen in the standalone BankSync repository first; Dolibarr consumes reviewed BankSync states via subtree pulls.
- BankSync lives under the native **Bank / Cash** main menu, not as a separate top-level Dolibarr menu.

## Import and staging

- Imported bank rows first enter BankSync staging; import does not directly create native Dolibarr bank/payment records.
- Source bank accounts are explicitly mapped to native Dolibarr bank accounts.
- Even an exact source-account match is only a suggestion until a user confirms the mapping once.
- Raw provider data and stable source identifiers are preserved for auditability and idempotency.

## BinX classification

Current exact BinX mappings:

- `CDPT` -> transfer -> Dolibarr payment code `VIR`
- `DMCT` -> transfer -> Dolibarr payment code `VIR`
- `CAPA` -> card -> Dolibarr payment code `CB`
- `CHRG` -> bank fee -> no direct payment code; posting may inherit the payment mode from its grouped companion transaction, otherwise falls back to `VIR`
- `PPCF` -> card fee -> native bank-fee posting uses payment code `CB`

Notes:

- `CAPA` counterparty-account data must not be treated as an IBAN/partner bank account; BinX may place card-related identifiers there.
- `CHRG` rows are separate real bank movements but are normally companion fees, not invoice payments.

## Reconciliation policy

- Automatic matching is normally advisory.
- A `100%` confidence score by itself is **not** sufficient for automatic confirmation.
- A customer- or supplier-invoice candidate may be auto-confirmed only when all of the following are true:
  - it is the single qualifying strict candidate for the transaction;
  - the bank amount exactly equals the invoice's remaining amount;
  - the bank reference contains the full invoice/customer/supplier reference (`reference` signal, not merely a partial reference);
  - there is a strong identity signal: exact normalized partner-name match or matching registered partner bank account;
  - the transaction has no already confirmed/posted allocation;
  - the candidate has not previously been explicitly rejected by a user.
- Human rejection is sticky: automatic matching must never overwrite a preserved rejected decision for the same target.
- Salary, social-contribution/tax, merchant-only card matches, partial-reference matches and other heuristic candidates remain advisory even if their accumulated score reaches `100%`.
- Automatic reconciliation confirmation does **not** mean automatic Dolibarr posting. Native posting always remains a separate preview -> explicit confirmation workflow.
- Confidence and workflow state are separate concepts:
  - confidence: Certain / Strong / Possible / Weak / Manual
  - workflow: suggested / confirmed / rejected / posted
- Manual reconciliation is always available even when the automatic matcher produces no candidate.
- Card transactions require merchant-aware matching because provider merchant text often differs from the legal Dolibarr partner name and card rows do not expose a usable partner IBAN.
- For card transactions, a strong normalized merchant-name <-> partner-name match is sufficient to surface open supplier invoices as candidates even when amount/reference/date evidence is weak. Such candidates remain advisory and must still be confirmed manually unless they independently satisfy the strict invoice auto-confirm rules above.
- Card candidate ranking should use amount proximity and date proximity to rank multiple open invoices from the same merchant, but weak amount/date evidence must not suppress an otherwise clear merchant relationship.
- Salary matching uses the salary period (`salary.datesp` / `salary.dateep`) as its primary temporal evidence. `salary.datep` is the payment date and may be empty before the salary is paid, so it must not be the field that excludes an otherwise valid unpaid salary candidate.
- For salary candidates, employee-name match, payroll wording in bank text (for example `munkabér`, `salary`, `payroll`), amount proximity and closeness to the salary-period end are independent advisory signals.
- Salary candidates and manual salary search results must display the salary period (start–end) so recurring monthly salary objects for the same employee can be distinguished safely.

## Allocation model

- One bank transaction may reconcile to multiple Dolibarr business objects.
- Reconciliation is therefore N:N and stores an `allocated_amount` per target.
- Transaction-level reconciliation states are allocation based:
  - `new`: no confirmed allocation
  - `partially_matched`: one or more confirmed allocations, but the total does not balance the bank transaction
  - `matched`: confirmed allocations balance the bank transaction within reconciliation tolerance
  - `posted`: native Dolibarr posting has been created
- A candidate may be confirmed with a manually adjusted allocation amount.
- Multiple invoices can be confirmed progressively until the full bank amount is allocated.
- Credit notes will later be represented as signed settlement components rather than being forced into a fake negative bank payment.

## Rounding tolerance

- Tiny differences should not leave transactions permanently `partially_matched`.
- Current reconciliation tolerance:
  - HUF: ±1 HUF
  - other currencies: ±0.01 currency units
- If the difference is within tolerance, the transaction may be considered `matched`, but the difference must remain visible as a rounding difference and must not be silently discarded.
- Native posting must preserve bank-account accuracy; rounding differences may need their own accounting/bank adjustment depending on the accountant's preferred treatment.
- Until an accountant-approved native rounding rule exists, a non-zero tolerated rounding difference may be shown as `matched` for reconciliation purposes but must block actual native posting.

## Posting policy

- No automatic native Dolibarr posting from reconciliation, including automatically confirmed strict matches.
- Native posting must use Dolibarr business/domain APIs where available, not direct SQL writes into Dolibarr core business tables.
- Direct SQL is allowed for BankSync-owned staging/reconciliation/audit tables.
- Read-only SELECTs against core tables may still be used by matching/search code where a suitable performant public API is not available, but core mutations must go through native Dolibarr objects/methods.
- Native targets currently implemented for the first posting milestone:
  - customer invoice -> `Paiement::create()` + `Paiement::addPaymentToBank()`
  - supplier invoice -> `PaiementFourn::create()` + inherited `addPaymentToBank()`
  - bank fees -> `PaymentVarious::create()`; Dolibarr itself creates and links the bank line through `Account::addline()`
- Planned later native targets include salary (`PaymentSalary`) and social contribution/tax (`PaymentSocialContribution` or the corresponding native workflow).
- Posting is always an explicit preview -> confirmation workflow. Viewing the preview never creates a native record.
- Actual posting requires the dedicated BankSync `post` permission and the corresponding native Dolibarr permission.
- Posting is idempotent. `llx_banksync_posting` has a unique `(entity, fk_transaction)` boundary and stores the resulting native object type/id plus native bank-line id. A transaction cannot be posted twice.
- Native core writes and the BankSync posting audit/status updates are wrapped in one outer DoliDB transaction; Dolibarr's nested transaction depth keeps domain-object transactions inside that atomic boundary.
- The first posting milestone supports company-currency transactions only. Foreign-currency posting remains blocked until an explicit exchange-rate workflow is implemented.
- Initial invoice posting supports one native target type and one third party per bank transaction. Mixed target types or multiple third parties are blocked until a deliberate native workflow exists.
- Credit-note posting is blocked until signed settlement components are implemented explicitly.
- If the Accounting module is enabled, bank-fee posting requires an explicit `BANKSYNC_BANK_FEE_ACCOUNTANCY_CODE`. The value must be agreed with the accountant; BankSync must not invent an accounting account.

## UX principles

- `100%` candidates are visually highlighted as **Certain match**. Only candidates satisfying the strict invoice auto-confirm policy above are automatically confirmed; all other `100%` candidates remain suggestions.
- Lower-confidence candidates are visually distinguished as Strong / Possible / Weak.
- Manual matches are shown as **Manual**, not as `0%` confidence.
- Reconciliation pages should show a transaction-level allocation summary: bank amount, confirmed allocation, and remaining/rounding difference.
- Reconciliation is a sequential review workflow. Opening **Find candidates / Review**, performing actions on the reconciliation page, and then using **Back to list** must restore the transaction list page and the exact transaction row the user came from instead of returning to the top of the list.
- The restored transaction-list state must include active filters as well as pagination and row position.
- Batch candidate scanning must preserve the current transaction-list page and active filters.
- Transaction lists should provide numbered pagination rather than only previous/next navigation.
- Transaction-list filtering should cover at least booking-date range, bank-event type, transaction code, counterparty, bank reference and reconciliation status.
- Posting preview is available only when a transaction is fully reconciled (`matched`) or when the transaction is a standalone bank fee that requires no business-object reconciliation.
- After posting, the preview remains an audit view and links to the created native Dolibarr object; the posting action is no longer offered.
