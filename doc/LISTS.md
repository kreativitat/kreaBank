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
