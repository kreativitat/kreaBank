# Module checkup — 2026-11-14

## Scope

Reviewed the current module source, installed Dolibarr 24.0.2 contracts, permissions
and company boundaries, statement import/parsers, Fintable, reconciliation and
payment batches, automatic native/pair/TPA execution, and monthly reports. Two
specialists independently reviewed import/security and reconciliation behavior.
History and Undo changes belong to the concurrent History maintenance task.

## Confirmed defects and corrections

- Statement CSV export passed bank-supplied text directly to spreadsheet cells.
  Import and monthly reports now share formula neutralization while preserving
  signed eight-decimal monetary strings, ordinary text and UTF-8 evidence.
- Import wizard confirmation, duplicate import and cleanup actions could run
  through GET when a wizard token was known. Every wizard mutation now requires
  POST and native CSRF validation before its action executes.
- Outgoing CAMT entries containing both parties replaced the creditor with the
  account holder. Counterparty name and IBAN now follow debit/credit direction;
  absent counterparty evidence stays absent.
- XLSX ZIP expansion and dense column allocation were unbounded. Imports now
  enforce 32 MiB per expanded entry, 64 MiB per archive, one million materialized
  cells, 100,000 rows and the native XFD column boundary, cleaning temporary XML
  on failure. Alternate readers and converters retain the archive preflight.
- Automatic native, paired-transfer and TPA eligibility checked only an imported
  feed row's status. They must also verify the unique source identity, exact
  financial hash, current company/account/currency mapping and conflict status,
  with execution-time locks. Automatic and grouped review both retain orphan
  provider rows when checking uniqueness; a broken mapping cannot hide a second
  identity for the same staged line.
- Payment candidate searches compared a native DATETIME to the final day's
  midnight. Half-open date ranges include the whole permitted calendar day and
  exclude the following midnight.
- Required candidate-provider SQL failures appeared as successful empty results,
  including the wider linked-payment fallback. They now propagate to existing
  controller error handling so incomplete evidence cannot authorize a match.

## Verification and boundaries

Validation uses PHP 8.2.35, installed native source and synthetic fixtures. Database
suites connect only to disposable MariaDB 11.4 on fixture ports 33317 and 33316 in
separate network namespaces with no external networking. Their PHP CLI containers
bypass the application image's installer entrypoint and mount source read-only.
No installation configuration, production database or real financial records are
used. Test jobs and collector settings stay disabled.

Focused regressions are `test_import_safety.php`, `test_parser_safety.php` and
`test_audit_safety_database.php`, alongside the existing pure, JavaScript and
disposable integration suites. The audit database suite first reproduced unsafe
feed eligibility after a mapping changed to `mapping_review`. All 76 available
suites passed; the focused suites contain 24 import, 50 parser and 37 audit
database checks. PHP syntax validation passed for 142 module files and JavaScript
syntax validation for seven browser scripts. The final orphan-identity correction
also passed payment-batch, separate-list and monthly payment-batch database suites.

The historical `test_format_consistency.php` and `test_parser_golden.php` suites
remain **UNVERIFIED**: all ten original bank-format files are absent from this
checkout. Synthetic parser checks do not substitute for those original fixtures.

These source fixes require no schema migration or scheduler changes. Production
deployment and production-runtime acceptance are separate from this checkup.
Deploy the shared CSV helper with both importing and monthly-report code; deploy
all reviewed parser/service dependencies together. Restore the previous complete
runtime file set to roll back; never alter imported evidence to repair old parser
output automatically.
