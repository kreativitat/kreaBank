# Imported statements and the native bank ledger

KreaBank keeps bank statement evidence in staging. Dolibarr bank entries are the
account ledger. Importing a statement must not change that ledger or its balance:
it may contain movements already registered through payments or other workflows.
Reconciliation links to an existing native movement, or uses the established
reviewed reconciliation path to create the required native entry. Automatic
matching continues to reuse existing movements only.

`statement.php` provides a read-only list/detail view for both storage generations.
It preserves original imported amounts and shows the linked native ID, native
amount when different, and whether reconciliation flags, signed amount, account
and statement reference agree. A missing or inconsistent native link is a review
issue, not permission to create a replacement or rewrite history. Only verified
links count toward the verified reconciliation total. Viewing, exporting and the
legacy `ensureNativeStatementLines()` helper perform no financial writes.

The same panel appears on KreaBank pages and native bank account, movements and
statement pages through existing registered hooks. Account balances come from
native bank movements in the account currency, excluding future operation dates
as the native current-balance banner does. All module evidence is restricted
to the current owner company, including when native account sharing is enabled.
Native numbered statements contain registered movements; unlinked imported lines
remain accessible through **Imported statements** on that same screen.

**Movements reconciled with KreaBank** opens the native list with an explicit
company-scoped filter using native IDs. Sorting, pagination and search retain the
filter; **All Dolibarr movements** clears it. Native posting dates are preserved:
a reconciliation performed today can refer to an older movement. Statement detail
also links directly to the native movement card and its numbered statement.

No schema migration, navigation re-registration, automation configuration change,
or historical data repair is required. Deploy the committed module files as usual,
retain their preimages for rollback, and verify both native and module screens.
Rolling back source does not require changing financial records.

Validation:

- `php test/test_navigation.php`
- `php test/test_reconciliation_guards.php`
- `php test/test_bank_integration_database.php` (runs the native automatic suite
  first, then mixed-source, company-isolation, retry-safe read and discrepancy
  checks; ONLY disposable MariaDB at `127.0.0.1:33317`, database
  `kreabank_auto_test`, as described in `AUTOMATIC_RECONCILIATION.md`).
- Browse imported statements, a pending detail, native movement/statement pages,
  and the matched filter at desktop/mobile sizes. Read-only verification must not
  import, reconcile, undo, create payments, or alter bank balances as a test.
