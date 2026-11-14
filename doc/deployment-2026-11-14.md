# Monthly reports — production deployment, 2026-11-14

Deployed KreaBank **1.19.43** to `fin.degema.pt`. Runtime source is
[`25c005f`](https://github.com/kreativitat/kreaBank/commit/25c005fdcc45ad96483d78c8cc59c15caf213124),
including the original monthly-report implementation and native HTML/CSV
translation corrections discovered during live verification.

## Scope and safeguards

- Production runs Dolibarr 24.0.2 and 64-bit PHP 8.1.34. Required integration,
  statement-evidence and formatting/navigation dependencies matched the reviewed
  source before deployment; the custom directory uses the standard native layout.
- Installed the 11 runtime files described in [monthly reporting](MONTHLY_REPORTS.md),
  with source checksums, production PHP syntax checks, preserved file modes,
  guarded preimages, staged atomic replacements and automatic rollback on failure.
- Preserved the production language-file baseline and appended only the monthly
  report keys. Those two deployed files intentionally retain their pre-existing
  translation differences from GitHub source.
- No migration, module reactivation, menu registration refresh, worker restart,
  scheduler change, reconciliation or financial-record write was performed.
  Existing opcode-cache timestamp revalidation served the updated files.

## Verification

- Authenticated browser checks used the internal browser with Google sign-in;
  the dedicated Chrome fallback was used for the native print interaction.
- Generated three calendar-month reports from an owned production account.
  Every native closing balance matched an independent company/account-scoped
  SQL sum. A foreign-company account was denied. Read-only checks used an
  explicit read-only database transaction.
- Verified map, quality checks, both pending lists, bank/native details and
  pagination, including carried items and retained account/month state.
- Downloaded the complete CSV through the authenticated UI. Confirmed UTF-8
  labels, company/account/month identity and unchanged eight-decimal numeric
  strings after the native translation fix. Original evidence is not decoded.
- Verified the print view contains the map, checks and all pending rows, without
  applying screen pagination. The final native print/PDF dialog remains
  **UNVERIFIED**: its automation action timed out in both browsers. No physical
  print or successful PDF save is claimed.
- Inspected production desktop/mobile rendering and visible balance-field
  clearing on account/month changes. No report page overflow, untranslated
  labels, application error or monthly-report browser console error was found.
- The installed native translation regression passed on production PHP 8.1
  without application bootstrap, credentials or database access. Local pure,
  view/export and JavaScript regression checks also passed.
- File readbacks verified the runtime manifest. Guarded deployment snapshots
  preserved configuration, scheduler/menu state, financial counts and the
  captured ledger/staging aggregates.

The later readback retained the runtime hashes, menu state and financial
invariants. Full constants/job hashes had advanced: the existing automatic
runner updated `KREABANK_AUTO_NATIVE_CURSOR` and its execution timestamps during
verification. Those operational fields are included in the full hashes; they
must not be mistaken for a failed immediate deployment guard or frozen runtime
state. This deployment did not write scheduler settings or run the jobs itself.

Missing official bank closing balances and historical pending items remain
visible review conditions. Reports describe current evidence, not approved
historical closes; verification did not resolve or alter business records.

## Rollback

Private receipts, original files, absent-path lists and checksums are retained.
The initial rollback is under
`/home/degemapt/backups/kreabank-monthly-reports-20261114T163236Z`.
The display and CSV follow-ups are under
`/home/degemapt/backups/kreabank-monthly-date-display-20261114T163811Z` and
`/home/degemapt/backups/kreabank-monthly-csv-20261114T164436Z`.
To return to the previous runtime, restore follow-ups in reverse order, then the
initial preimages. Remove only newly introduced files that still match the
recorded deployed hashes; stop on concurrent edits. Do not change business data
or scheduler configuration during source rollback.
