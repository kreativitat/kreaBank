# Existing tax reconciliation

KreaBank reviews Dolibarr social/fiscal contributions from `chargesociales` and
payments from `paiementcharge`, including IVA and social contributions. These are
existing records; creating a new tax remains the separate Tax payment creation
choice.

| Existing record | Explicit approval |
| --- | --- |
| Unpaid tax with a remaining balance | Record tax payment and reconcile; create one native payment against that tax. |
| Tax payment without a bank movement | Link payment and reconcile; reuse the payment and create only its bank movement if needed. |
| Banked tax payment in the statement account | Reconcile its existing native movement; create no tax, payment or movement. |
| Banked tax payment in another owned account | Search and review the account correction, then reconcile the corrected movement in a separate action. |

Tax cards link to their native social/fiscal contribution page; payment cards link
to the native tax-payment page. Candidate date filtering uses the existing
workspace date window. A partial tax payment requires the shared matcher's
existing identity evidence; amount plus date alone does not suggest a partial
payment. Ambiguous candidates are available only as separate explicit choices in
one-by-one review. Matched/bulk review keeps its unique-suggestion requirement.

## Ownership and lifecycle

The only executor remains `KreaBankService::reconcileLine()` and its native bank
adapter. `KreaBankTaxReconciliation` supplies projections and native tax lifecycle
calls; it installs no runner or matching engine. Tax targets require native tax
read and create/payment rights, bank/module write rights, CSRF and the exact
expiring signed manual review. Tax parent, type and selected payment method are
included in the signature. Tax creation/attachment is never unattended and does
not train learned patterns.

`paiementcharge` has no entity column. Scope it through the direct current-company
`chargesociales` parent; require the same owner company and account for its native
movement. Bank URL links cannot substitute for parent ownership. Taxes and their
payments are locked and the displayed facts rechecked before payment writes.
Remaining balances use exact DECIMAL subtraction of all native tax payments,
including unbanked payments. Paid, exhausted, foreign-company and foreign-currency
taxes are excluded. Refunds and negative tax-payment reversal records are not
ordinary tax-payment candidates.

An unpaid tax must have an explicitly configured active native payment method.
The payment and a newly materialized bank movement use that same method; an
existing payment retains its recorded method. Missing, inactive and foreign-scope
methods fail closed. Native `PaymentSocialContribution::create(..., 1)` handles
full/partial paid state; KreaBank never forces a partially paid tax closed. Payment,
bank links, native reconciliation, staging mapping and audit share the service's
outer transaction, including rollback on failed paid-state or audit writes.

## Wrong-account payments

A tax-like debit (for example DUC or social-tax evidence) with an exact signed
amount/currency and nearby bank date can flag an existing tax payment in another
owned account. Amount/date evidence is advisory and never authorizes automatic
matching, payment creation or an account change. The warning includes locked
movements for review and blocks unattended/manual pair approval until resolved.
Search additionally finds tax IDs, labels and payment references before limiting
results. Correction requires the existing single-use session review and native
modifier rights, tax rights, unchanged source/payment/tax facts, one coherent tax
payment, and the existing accounting/reconciliation/deposit/transfer restrictions.
It changes only the native movement's account. Tax defaults, tax/payment identity,
amounts and paid state remain unchanged. Reconciliation is a separate action.

## Validation and delivery

```sh
php test/test_reconcile_workspace.php
php test/test_reconcile_document.php
php test/test_bulk_review.php
php test/test_tax_reconciliation_database.php
```

The database suite uses ONLY the disposable localhost port 33317 database
`kreabank_auto_test`; it never bootstraps Dolibarr or reads installation credentials.
It extends the guarded account-correction fixtures and calls the installed native
`ChargeSociales`, `PaymentSocialContribution`, `Account` and `AccountLine` classes
with synthetic company, translation and trigger context. It covers full/partial
payments, payment/movement reuse, retries, stale-parent/method/source facts,
permissions, company/currency boundaries, correction locks and injected rollback
failures. Custom installation hooks/triggers are not loaded in these fixtures.

The UI fixture's `--html tax pt_PT` and `--html taxotheraccounts pt_PT` render the
actual workspace with intercepted submissions. Desktop and 390px mobile rendering,
signed tax submission and correction confirmation were checked in the internal
browser; the clean mobile page has no horizontal overflow or JavaScript errors.
No real tax/payment or reconciliation is a test.

No schema migration or scheduler change is required. Deploy the tax helper,
service, adapter, scope, matcher, shared review/render helpers, controllers,
translations and version metadata together through the established deployment
procedure. A Git push does not deploy. Roll back the same source files together;
completed payments remain native financial records and require reviewed native
correction rather than deleting records as a source rollback.
