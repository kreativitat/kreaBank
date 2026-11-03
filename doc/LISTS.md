# List sorting

Accounts align balances and actions with their column headings. Click a data
heading to order a list; click it again to reverse direction. Arrows show the
active order. Action and selection columns are not sortable.

- Accounts, statements and statement lines order allowlisted SQL columns before
  pagination. Pending lines retain the native sortable list controls.
- Connections order their complete current-company/account result using raw
  values. Missing values stay last in either direction.
- Reconciliation exposes sort links above the statement and document cards.
  Sorting changes presentation only; candidate eligibility, scoring and selection
  rules remain in the existing reconciliation service.
- Bulk review orders compact scan results before page hydration. Requests carry
  the selected order; changing it invalidates the displayed page generation so an
  older background request cannot overwrite the new view. The scan keeps its
  cursor and per-line budget, and only the displayed page retains presentation.
- History and audit have independent sort parameters and retain filters. Sorting
  happens before slicing the prepared rows. The existing 5,000-row recent-history
  ceiling is fixed across pages to avoid duplicates/skips when ordering. Other
  reconciliation candidate limits still apply; sorting does not expand them or
  retrieve the entire ledger.
- Import samples/duplicate review and administration data tables are fully
  rendered tables with opt-in local sorting. Raw amounts and ISO dates are used
  where available; moving rows preserves checkboxes and form values. Raw file
  preview columns use natural text ordering, preserving original row numbers.
  Configuration field tables are not lists and retain their defined field order.

`lib/kreabank_list.lib.php` owns the shared request, link and array-order helpers.
`js/lists.js` handles only tables explicitly marked `data-kb-sort`; never mark a
paginated table or a table with paired detail rows. Native banking pages retain
their native sorting. Sort URLs carry view/filter state and omit action, token and
selection fields. No sorting path writes financial records or changes scheduling.

## Validation

Run with the supported PHP runtime and Node:

```sh
php test/test_list_sorting.php
php test/test_bulk_scan.php
node test/test_bulkmatch_client.cjs
node test/test_lists_client.cjs
```

`php test/test_list_sorting_database.php` extends the guarded disposable MariaDB
fixture on **127.0.0.1:33317 / kreabank_auto_test**. Never run it against an
installation database. See [the fixture setup](AUTOMATIC_RECONCILIATION.md).

Verify the rendered account table, both sort directions, statement detail links,
history/audit filters and the bulk background flow in a signed-in test session.
No schema, menu-registration or scheduler migration is required. Deploy the full
module source, including the new list helper, stylesheet and script. Source
publication alone does not update production.

## Combined bank statement

`bankstatement.php` uses `print_barre_liste()` for native page size and navigation,
`print_liste_field_titre()` for sort headers and `Form::showFilterButtons()` for
search/reset. The initial page size follows the native list setting; requests are
bounded to 5,000 rows. Native one-based page entry is supported. Search and reset
return to the first page; sort and pagination links retain normalized filters,
account context and page size without carrying actions or tokens.

Every data column has a header filter. Operation/value dates have independent,
inclusive date bounds. Description searches also cover visible payment and file
bank references; hidden feed provider IDs remain excluded. Text is a literal
substring, including `%` and `_`. Debit/credit use the positive amounts displayed
in those columns. Debit, credit and balance accept an exact decimal or one comparison
(`>=100`, `<0`), using a dot or comma decimal separator and up to eight fractional
digits. Unknown balances cannot match a numeric filter. Currency, account reference,
source statement and displayed evidence status can be filtered independently.
Duplicate warnings are available in the status selector.

`lib/kreabank_statement_list.lib.php` owns normalization and allowlisted SQL filter
expressions. Count and data queries use the same filters after the full scoped
balance projection; filtering never changes the feed anchor or recomputes a balance
from the reduced result. Native/staged row order stays deterministic across pages.
The read path creates no bank movements, payments, source rows or audits.

## Native pagination throughout the lists

Accounts, imported statements and statement details, connections and the feed
administration company summary use the same native `print_barre_liste()` controls
as pending lines, bulk review, import history, reconciliation history and audit.
The configured native page size is the default. `kreabankListPagination()` bounds
new list requests to 5,000 rows, handles one-based page entry and resets stale pages
after the scoped result shrinks. Sort headers retain page size and read-only view
state. Account type stays exclusive to the account overview; statements retain
their source reference and connections retain the identified/unidentified view.

Small account/company result sets are fully scoped and sorted before selecting a
page. Statement SQL orders before applying its limit. Do not add `data-kb-sort` to
these paginated tables. Existing bulk candidate/page limits and the fixed history
and audit windows remain unchanged. Individual review keeps one signed pair per
page; editable configuration matrices, import samples and transaction previews
remain complete forms/previews rather than independently paginated record lists.

```sh
php test/test_bank_statement_filters.php
# ONLY the guarded disposable localhost:33317/kreabank_auto_test fixture:
php test/test_bank_statement_filters_database.php
```
