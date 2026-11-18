# Automatic reconciliation

KreaBank 1.14 can reconcile pending imported statement lines with movements that
already exist in the native Dolibarr bank account. It uses the same matcher,
service and native `AccountLine::update_conciliation()` lifecycle as manual
reconciliation. The existing-movement path never creates an invoice, payment, quick entry or bank movement.
Invoice payment creation remains a reviewed operation.

## Setup and operation

Open **KreaBank setup → Automatic reconciliation** in the intended company.
An active internal administrator needs native bank read/write and KreaBank
reconciliation write rights. Select the open bank accounts that may be processed
and save with **Disabled** or **Scheduled preview only** first. Unselected accounts
remain outside automation. Settings are company-specific; shared bank-account
visibility does not grant ownership for this operation.

**Preview matches** scans up to 50 pending lines using the saved account selection.
It never reconciles, writes a reconciliation audit or advances the scheduled
cursor, including when execution is enabled. A preview is a current assessment,
not a reserved match; execution revalidates every decision.

Select **Automatic matching enabled** when ready. Saving installs or updates one
native scheduled job in this company, checked every 15 minutes by the installation's
existing Dolibarr scheduler. Its execution user must have the required rights.
No host cron, daemon or legacy bank feed job is installed or enabled. The separate
Fintable collection job continues to collect only; reconciliation picks up eligible
posted imports on a subsequent scheduler run, also supporting file imports and
bank movements entered after the initial collection.

Each scheduled pass evaluates at most 50 staging IDs with a 20-second between-line
budget. Up to ten slots and six seconds are reserved for recognized TPA credits
and own-account transfer descriptions. A separate persisted priority cursor rotates
that lane; the ordinary cursor continues through the remaining expense/document
queue without jumping over unresolved rows. Neither cursor changes during an
explicit preview. Large queues take multiple ticks; the time budgets do not
interrupt an individual database operation. Job output reports scanned, eligible,
reconciled and failed counts without financial descriptions. Failed or ambiguous
rows remain pending and are revisited on later passes.

## Eligibility

- The company, selected account, open/reconciliation status, currency and signed
  persisted amount must agree. A one-cent difference is not an automatic match.
- The booking date must be valid and within `KREABANK_AUTOMATCH_DATE_TOLERANCE`
  (default three days, capped at 31 for unattended operation).
- The shared `getSafeSuggestion()` requires exact amount evidence and a strong
  reference or checksum-valid IBAN signal, with the configured safe-score threshold
  (never below 150 automatically) and at least a 35-point lead over the next candidate.
- Automatic reference matches additionally require at least eight normalized
  characters and a numeric component. Description references must match a complete
  reference, not a prefix. Names, generic transfer labels, amount and date alone
  cannot authorize an automatic reconciliation.
- Another matching pending staged line competing for the selected bank movement
  requires review. Truncated searches (501 native candidates or 101 competing
  staging rows) also require review rather than assuming uniqueness.
- Duplicate, skipped, partially allocated, already materialized or reconciled
  staging lines are excluded. Fintable lines require an imported source identity
  without a recorded source conflict. Existing source evidence is retained.

The current Fintable mapper does not supply counterparty IBANs. Its automatic
coverage depends mainly on usable transaction references/descriptions. Native
payment/check references (`bank.num_chq`) are included in candidate matching.
No promise of complete automatic coverage is made.

## Concurrency, audit and recovery

A database advisory lock excludes overlapping runs and configuration changes in
one company. Staged and native rows are locked and eligibility is checked again
before writing. Automatic reconciliation, staging linkage and its `auto_native`
audit commit together. An audit or staging failure rolls the native update back.
Automatic results do not train matching patterns. Retries re-read persisted status;
a completed reconciliation cannot be repeated by this job.

## Deployment and rollback

This release adds no tables or columns and leaves automation disabled by default.
Deploy the complete module source, including the new class, admin page and language
keys. Saving settings creates only the company constants and native job. Source
publication alone does not configure, enable or deploy an installation.

To stop processing, save **Disabled**; this disables the matching job and preserves
imports and audits. A running pass finishes under its company lock before settings
can change. Fintable collection remains independent. Before rolling source back,
disable this job; older releases do not contain its class. Reconciliations already
performed remain native records and require the established reviewed undo workflow.

## Validation

```sh
php test/test_reconciliation_guards.php
php test/test_fintable.php
# Disposable fixture only, no installation configuration or credentials:
docker run --rm -d --name kreabank-auto-test \
  -p 127.0.0.1:33317:3306 -e MARIADB_ALLOW_EMPTY_ROOT_PASSWORD=1 mariadb:11.4
# Wait until MariaDB is ready, then run with an installed Dolibarr source tree:
KREABANK_DOLIBARR_ROOT=/path/to/dolibarr/htdocs php test/test_automatic_reconciliation_database.php
docker stop kreabank-auto-test
```

The database suite recreates only `kbauto_` fixture tables in the fixed
`kreabank_auto_test` database on port 33317. It exercises the actual installed
Dolibarr `AccountLine` and `Cronjob` implementations, synthetic companies,
permissions, ambiguous matches, source conflicts, rollback injection, retries,
preview mode, cursor rotation and exclusion across independent connections.
The runtime fixture adapter supplies no production credentials or business data.

## Optional paired transfers

Release 1.15.0 adds a separate, disabled-by-default paired-transfer option to this
runner. It requires two imported legs and explicit company account relationships,
and may create a complete native transfer atomically. The existing-movement path
above still creates no bank movement. See [account pairs](ACCOUNT_PAIRS.md).

## Transfers between all owned bank accounts

The independent **Automatically reconcile transfers between our own accounts**
option uses the same runner, service and native adapter. It defaults off. Select
both open, reconcilable bank accounts in the current company; existing restricted
account mappings remain enforced, but ordinary banks need no restricted mapping.
Accounts in another company never become eligible through shared visibility.

A debit and credit must carry exact opposite persisted amounts and the same
currency. Each imported description must name the complete current legal company
with an explicit transfer direction, or supply the exact checksum-valid IBAN of
the opposite owned account. Conflicting IBANs, fee/payroll/refund markers and
ambiguous legal names across companies fail closed. Creation based on company
names requires the same booking day; mutual IBANs permit the configured tolerance.
The candidate must be unique in both directions, including unselected accounts
and competing sources. Generic transfer text or amount/date alone is insufficient.

A complete reciprocal native transfer is reused. One pending imported leg may
reuse its native side when the opposite side already has a complete, exact owned
source mapping. One-sided ledger records, invoice/payment links, active batch
claims, foreign ownership or opening-reconciliation cutoffs prevent execution.
When neither side has possible native evidence, two proven imported legs may
create exactly two reciprocal `VIR` movements, only in company currency. Missing
value dates use the original booking day; invalid supplied dates remain pending.
No expense debit, invoice, salary, tax or invoice payment is created by this path.

Both mappings and `auto_transfer` audits commit atomically. Repeated execution
reuses persisted completion. A reentrant company advisory lock also spans native
transfer POST preflight through request shutdown, preventing manual and automatic
transfer creation from passing the duplicate check concurrently. Explicit native
review of a real repeated transfer retains the existing confirmation contract.

Validate with `test/test_own_transfer_evidence.php`,
`test/test_own_transfers_database.php` and
`test/test_automatic_priority_database.php`; database suites use only the guarded
disposable port 33317 fixture described above. No schema migration is required.

## Optional TPA receipts

Release 1.15.4 adds an independent opt-in TPA settlement receipt option to this
runner. It may create a native card receipt only from a recognized positive
settlement with no conflicting financial evidence. Existing matching rules are
unchanged. See [TPA receipts](TPA_RECEIPTS.md).

## Invoice payments recorded in the wrong bank account

Since 1.17.0, each scanned staging line is checked for an existing customer or
supplier invoice payment recorded in a different account owned by the active
company. The statement's signed persisted amount and currency must agree with
the payment's native movement; its booking date must be within the configured
automatic tolerance. A complete payment/check reference with at least eight
alphanumeric characters and a digit must occur in the statement reference or
description. Since 1.19.6, a missing statement payment reference can instead use
the complete name of the single invoice counterparty, with all invoice allocations
accounting exactly for the payment amount. Name evidence ignores accents,
punctuation and an internal display-code prefix (such as `AB42 -`). It requires
a complete multi-word name in the statement description or counterparty field;
partial/fuzzy names, amount/date alone, mixed counterparties and conflicting
statement references do not qualify. This flags a possible account mismatch for
review; it does not establish an error or qualify as unattended matching identity.

These are possible mistakes for human verification. Multiple candidates are
shown together; reaching the 101-payment search limit also requires review.
Foreign-company bank accounts, payments and parent invoices, mixed-company
allocations, credit notes and restricted transfer-only accounts are excluded.
An already reconciled payment can be flagged, but its existing reconciliation
and native accounting restrictions must be reviewed before correction.

**Preview matches**, the bounded background **bulk reconciliation** scan, and the
individual reconciliation page display **Verify the payment bank account**.
Compare the statement account with the account recorded on the payment, open the
payment and choose **Verify / correct bank account** to inspect the existing
native bank movement. Make the correction only after checking the bank evidence.
Refresh reconciliation afterward; the existing same-account matcher then applies
its normal safety checks. The warning does not offer a bulk approval checkbox,
move a payment, duplicate a bank line, or change invoice allocations/paid states.

Scheduled output includes `review_required` and staging IDs in `account_reviews`,
without financial descriptions or account details. Review is a pending business
condition rather than a processing failure. The cursor continues to rotate over
the full selected queue. UI review is recalculated from current evidence; no
second scheduler or stored financial correction queue is created. Bulk submission
rechecks for account warnings before accepting the signed ordinary match.

Validate with `php test/test_payment_account_review.php` and
`php test/test_payment_account_review_database.php`. The latter extends only the
guarded disposable MariaDB fixture described above. Source deployment requires
the service, runner, views, shared library and translations together; no database
migration or settings save is needed. Existing job enablement remains unchanged.
