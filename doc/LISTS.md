# List sorting

Accounts align balances and actions with their column headings. Click a data
heading to order a list; click it again to reverse direction. Arrows show the
active order. Action and selection columns are not sortable.

- Accounts, statements and statement lines order allowlisted SQL columns before
  pagination. Pending lines retain the native sortable list controls.
- The account overview defaults to `CO_` references first, then other accounts
  connected to the current bank feed, then remaining accounts. Each group orders
  by reference. Connections require the current company, workspace, currency and
  IBAN mapping; unavailable or review mappings do not count. Column clicks select
  ordinary column sorting instead, and pagination preserves the chosen order.
- Connections order their complete current-company/account result using raw
  values. Missing values stay last in either direction.
- Reconciliation exposes sort links above the statement and document cards.
  Pending transactions default to oldest booking date first. Next and successful
  reconciliation select a successor without rotating the sorted queue; saved
  browser order cannot override the active sort.
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

### Original bank sequence

Import time, provider row order and bank reference suffixes do not establish the
bank's within-day sequence. An original file may be newest-first, and overlapping
feed movements may already exist in other import batches. Never manufacture
transaction timestamps from those dates or order by the displayed balance.

For an explicitly reviewed original file, `KreaBankService::recordStatementEvidence()`
stores versioned `bank_statement_evidence` in the owning import's existing
`import_payload`. Supply the file SHA-256, filename, explicit `ASC`/`DESC` source
order and complete contiguous rows with `staged_id`, operation/value dates, exact
decimal amount/balance strings, currency, description and original `source_row`.
Check the retained file bytes and independently establish the complete one-to-one
mapping before calling this method. Indistinguishable repeated movements require
their full original multiplicity; never collapse equal amounts/descriptions.
When importing only independently verified missing XLSX rows, pass the
`source_file_sha256` import option and `xlsx:<sha256>:row:<original-row>` identities.
Verify the retained file bytes and reconcile multiplicity against all existing
account evidence first. This explicit path preserves legitimate identical fees
without forcing duplicates or inventing bank references. It is not enabled by
the ordinary upload form and must never replace cross-file duplicate review.

The method rechecks bank/import permissions, direct company/account ownership,
unique staging IDs, signed amounts, descriptions and the complete balance chain
under locks. It retains existing metadata, refuses conflicting replacement,
records immutable feed identities and commits its audit atomically. Retrying the
same attachment is a no-op. It creates no movements or reconciliations and does
not change feed payloads or staging rows. No schema migration is required.

The combined view reads this evidence through `KreaBankStatementEvidence`; a
changed live identity, date, amount or currency cannot reuse its overlay. Original
value dates and balances drive header filters. Same-day sequence reverses with
the selected date direction before pagination. The original import link remains,
with a second link to the reviewed file import where different. Imports without
reviewed file evidence retain their deterministic existing fallback order.

```sh
# ONLY the guarded disposable localhost:33317/kreabank_auto_test fixture:
php test/test_statement_evidence_database.php
```

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
