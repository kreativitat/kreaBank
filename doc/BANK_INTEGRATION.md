# Imported statements and the native bank ledger

KreaBank keeps bank statement evidence in staging. Dolibarr bank entries are the
account ledger. Importing a statement must not change that ledger or its balance:
it may contain movements already registered through payments or other workflows.
Reconciliation links to an existing native movement, or uses the established
reviewed reconciliation path to create the required native entry. Automatic
matching continues to reuse existing movements only.

`bankstatement.php` combines content from all imports in one read-only table,
newest operation first by default, with sorting before 50-row pagination. It shows
operation/value dates, description and bank/payment references, debit/credit,
currency, source statement and reconciliation status. The account filter includes
only current-company owned accounts with imported statements, including closed
accounts and recognizable legacy imports. Bank balances
use original supplied line balances where available. For feed lines without one,
`bankStatementWithBalancesSql()` calculates backwards from the last successfully
collected bank balance, subtracting all later collected movements with exact SQL
DECIMAL arithmetic before display sorting or pagination. The balance tooltip
identifies calculated values; same-day sequencing follows the default statement order, because
the feed supplies no transaction time or bank sequence. No opening balance,
ledger balance or combined currency total is invented. Incomplete collections,
conflicts, duplicate/missing links and changed company/workspace/IBAN/currency
mappings leave unknown balances blank. Stored source balances remain unchanged. Potential source
duplicates remain visibly flagged; mapped native movements are not repeated.
Provider external transaction IDs stay stored for duplicate detection and are
not shown as bank references in statement tables.
Legacy imported movements without a staging mapping remain visible, even when
other staged evidence shares their statement reference. Ordinary native ledger
movements are not imported evidence and do not enter this table.

`statement.php` provides a read-only list/detail view for both storage generations.
It preserves original imported amounts and shows the linked native ID, native
amount when different, and whether reconciliation flags, signed amount, account
and statement reference agree. A missing or inconsistent native link is a review
issue, not permission to create a replacement or rewrite history. Only verified
links count toward the verified reconciliation total. Viewing, exporting and the
legacy `ensureNativeStatementLines()` helper perform no financial writes.

One shared banking header connects module views and native bank pages through
supported hooks. The account overview shows native current balances, excluding
future operation dates as the native current-balance banner does. Module evidence
is restricted to its owner company, including when native sharing is enabled.
Native numbered statements contain registered movements; imported evidence is
available under Statements. See [navigation](NAVIGATION.md).

**Movements reconciled with KreaBank** opens the native list with an explicit
company-scoped filter using native IDs. Sorting, pagination and search retain the
filter; **All Dolibarr movements** clears it. Native posting dates are preserved:
a reconciliation performed today can refer to an older movement. Statement detail
also links directly to the native movement card and its numbered statement.

No schema migration, automation configuration change,
or historical data repair is required. Deploy the committed module files as usual,
retain their preimages for rollback, refresh hook registration as documented in
NAVIGATION.md, and verify both native and module screens.
Rolling back source does not require changing financial records.

Validation:

- `php test/test_statement_balances_database.php` (same guarded disposable database)
- `php test/test_bank_statement_database.php` (same guarded disposable database)
- `php test/test_navigation.php`
- `php test/test_reconciliation_guards.php`
- `php test/test_bank_integration_database.php` (runs the native automatic suite
  first, then mixed-source, company-isolation, retry-safe read and discrepancy
  checks; ONLY disposable MariaDB at `127.0.0.1:33317`, database
  `kreabank_auto_test`, as described in `AUTOMATIC_RECONCILIATION.md`).
- Browse imported statements, a pending detail, native movement/statement pages,
  and the matched filter at desktop/mobile sizes. Read-only verification must not
  import, reconcile, undo, create payments, or alter bank balances as a test.
