# Monthly reconciliation reports

Open the **Monthly reports** banking tab before **History**, select one owned bank
account and a calendar month, then generate the report. The same reports work for
any supported bank; the Santander workbook is a reporting reference, not imported
business data or a replacement matching algorithm.

- **Reconciliation map:** bank and native ledger closing balances, signed open
  items on both sides, adjusted balances and unexplained/opening/month differences.
- **Quality checks:** source integrity, exact currencies and amounts, duplicate or
  conflicting links, supplied balance continuity, missing evidence and ageing.
- **Closing pending items:** both sides, including unresolved earlier periods.
- **Bank statement / Dolibarr bank ledger:** monthly detail plus carried open items.
- **CSV:** complete report, independent of the visible page; exact decimal values
  and formula-injection protection for untrusted text. **Print view** includes the
  map, checks and both open-item lists; use the browser's print/PDF action.

The ERP side is the native **bank ledger**, not the general ledger/trial balance.
Closing ledger balance is the exact sum of owned-account movements with native
operation dates before the next month. There is no combined-currency total.

## Evidence and cutoff

Reports read current records. They do not claim to reproduce the state of a past
approved close. A match counts only when both booking dates fall before the
selected cutoff and the existing owned-account link, signed amount, currency,
statement reference and reconciliation state agree. Cross-month links remain open
on the earlier side. No report operation runs the matcher, creates native records,
changes reconciliations, saves a close or installs a scheduler.

Supplied bank balances are provisional evidence. Optional **Official bank closing
balance** is a user confirmation for this report only, accepted as an exact dot
decimal and retained across report views/exports; it is not saved. Missing or
ambiguous evidence remains unknown. Native ledger balances never substitute for
bank balances. Integrity errors prevent a complete reconciliation conclusion.
Eight-decimal fixed-point arithmetic rejects overflow rather than rounding source
amounts; displayed amounts use the module's two-decimal formatter.

The workbook's Excel capacity checks do not apply. Classifications, explanatory
comments, preparer/reviewer approvals and immutable period locking require a
separate close workflow; these read-only reports do not imply those controls exist.

## Implementation and checks

`KreaBankMonthlyReport` loads only SELECT projections through `KreaBankIntegration`
and owner-scoped native bank rows. It refuses more than 20,000 rows per side rather
than producing truncated totals. `monthlyreport.php` enforces native/module read
permissions and denies external users. The renderer/export are shared in
`lib/kreabank_monthly_report.lib.php`; tables paginate only after complete totals.

```sh
php test/test_monthly_report.php
php test/test_monthly_report_view.php
php test/test_monthly_report_native_translation.php
node test/test_monthly_report_client.cjs
php test/test_monthly_report_database.php
php test/test_navigation.php
```

The database suite uses only the guarded disposable localhost:33317
`kreabank_auto_test` fixture and installed Dolibarr source. Never run it against
an installation database. The view suite's `--html [en_US|pt_PT] [summary|checks|pending|bank|ledger|print]`
mode renders synthetic data without an application bootstrap or database.

Design reference: existing `bankstatement.php`, native bank annual reporting and
installed Dolibarr `/admin/tools/ui/content/tables.php` and
`/admin/tools/ui/components/buttons.php`. Preserve the native left menu and shared
operational navigation. Monthly reporting is its own active tab; the former
Bank statement action is removed. The selected-account toolbar retains the owned
account as a hidden control, with month, optional exact closing balance and compact
CSV/print actions. Detailed evidence/basis explanations remain expandable and open
in print. Summary and indicators sit side by side on desktop and stack on mobile.
Historical report values never use the header’s current-balance panel.

Deploy the controller, class, library, CSS, JS and translations together. No schema
migration, job or module reactivation is required. Source publication does not
deploy production. Roll back these files and the shared navigation together.

See the [2026-11-14 production deployment record](deployment-2026-11-14.md) for
verified runtime identity, checks and rollback locations.

Summary and Checks show complete compact tables without list filters or pagination.
The four transaction tables across Pending, Bank and Dolibarr use native Dolibarr
header filters, sortable headers and pagination (configured list size, maximum
5,000). Each transaction table owns independent namespaced state. Filters and sorting
apply to the complete prepared display rows before paging; loader inputs, totals,
CSV and print remain complete. Unknown amounts stay unknown and support an em-dash
filter. Shared column widths and wrapped references keep both sides aligned and
within the desktop browser width. Known native movement markers and source captions
are translated for display and filtering; original evidence and CSV remain unchanged.
Phone layouts retain readable transaction columns with horizontal scrolling confined
to each table frame; native headings and page controls wrap above them.
Deploy the shared `lib/kreabank_worklist_view.lib.php` label helper with these views.
See `lib/kreabank_monthly_list.lib.php` and the pure/native view suites.
