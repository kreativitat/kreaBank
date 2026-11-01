# Automatic reconciliation of existing bank movements

KreaBank 1.14 can reconcile pending imported statement lines with movements that
already exist in the native Dolibarr bank account. It uses the same matcher,
service and native `AccountLine::update_conciliation()` lifecycle as manual
reconciliation. It never creates an invoice, payment, quick entry or bank movement.
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

Each scheduled pass scans at most 50 staging IDs with a 20-second between-line
budget. A persisted cursor moves past unresolved rows, resets at the end, and
revisits them on later passes. Large queues therefore take multiple ticks; the
20-second budget does not interrupt an individual database operation. Check the
native job's result/output for scanned, eligible, reconciled and failed counts.
Failures identify staging IDs without publishing financial descriptions. Other
lines continue; failed rows remain pending and are revisited on the next full pass.
Normal pending/history and native bank pages remain available for review.

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
