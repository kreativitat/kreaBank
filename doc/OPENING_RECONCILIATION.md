# Opening reconciliation

Use **Module setup → Opening reconciliation** to establish an initial position
for each bank account directly owned by the active company. Nothing is applied
automatically when this feature is installed or its pages are opened.

For a start date of **1 November 2026**, enter the official **closing booked
balance on 31 October 2026**, in the account's currency. Movements dated
1 November remain subject to normal reconciliation. Do not substitute an available
balance or a balance after start-date transactions.

## Setup flow

1. Use **Initialize opening reconciliation** once if the additive storage is
   absent. This explicit POST creates only `kreabank_opening_balance`.
2. Select an open, reconcilable bank account. Enter the start date, exact official
   opening balance, a native-compatible reconciliation statement reference and a
   reference identifying the official bank evidence.
3. Preview the historical native/imported counts, existing native ledger balance
   before the start and the exact proposed difference. The preview makes no writes.
4. Review the evidence, select the confirmation checkbox and apply. Cancel makes
   no financial changes. Changed facts or an expired review require a new preview.

The operation requires an internal administrator with native bank read, modify
and reconciliation rights plus KreaBank read/write rights. It applies to the
current company's directly owned account only. Cash, closed and nonreconcilable
accounts are unavailable. Existing applied positions remain visible after an
account is subsequently closed.

## Persisted behavior

The opening declaration is immutable in this interface and records its date,
currency, official balance, prior ledger balance, difference, actor, evidence and
original source/native snapshots. A company-scoped `opening_reconciliation` audit
is committed with it. Original imported amounts, raw statuses, links, payment and
invoice allocations remain intact; no payment or invented source/native link is
created.

Snapshot JSON escapes Unicode for storage so legacy table character sets cannot
change copied source text. Confirmation reads the saved declaration and audit
back before committing. A changed amount, identity, evidence reference or snapshot
rolls the entire opening back.

Previously reconciled native movements retain their existing statement references
and reconciliation actors. Other native movements before the start are reconciled
through `AccountLine::update_conciliation()` using the reviewed statement reference.
If the ledger differs, only **official opening balance − existing pre-start ledger
balance** is inserted through native `AccountLine` as one `SOLD` opening movement
dated the previous day. A zero difference creates no movement. This administrative
initial balance also supports restricted accounts; ordinary transfer-only account
rules and automation settings are unchanged. No currency conversion is inferred.

The unique company/account opening prevents duplicate adjustments. Confirmation
locks and rechecks the account, complete historical evidence and cutoff-crossing
claims against the exact session-held preview. Native movements, reconciliation
flags, declaration and audit commit atomically on InnoDB tables. Failed writes or
loss of native numeric precision roll back the operation. Existing scalar/group
links crossing the cutoff must be reviewed before an opening can be applied.

## Lists, reports and subsequent imports

Statements, account pending counts, the reconciliation list and History recognize
earlier imported evidence as **Reconciled at opening**, separately from a verified
transaction match. Later imports with an earlier booking date receive the same
historical classification without ledger materialization. Ordinary matching,
payment/entry creation, Undo and imported-statement deletion cannot reopen or
remove this opening evidence through KreaBank.

Monthly reports for periods starting on or after the implementation date use the
saved official bank opening and verify its native ledger/adjustment identity.
Only post-start activity carries into pending lists. Subsequent calculated bank
balances remain provisional; an opening declaration does not prove that every
later bank transaction has been collected. Missing subsequent evidence remains
unknown. Reports before or straddling the start retain their original current
evidence behavior.

Native ledger edits/backdated records made through other Dolibarr tools can change
the balance after setup. Reports flag opening drift; the module never posts a
second adjustment automatically. The original declaration, snapshots and bank
evidence remain available for investigation.

## Deployment and recovery

Deploy the complete compatible module, including the new class/controller/view,
shared projection/execution guards, reporting changes and translations. No job,
feed, scheduler or company opening configuration is enabled by deployment. Existing
installations without initialized opening storage retain their normal behavior.

Before applying a real opening, retain a recoverable database backup and the bank
evidence. The database contains both the new declaration and changes to native
reconciliation flags/balance, so a source-only rollback does not undo an applied
opening. Do not delete its table or adjustment row, or use ordinary Undo, as a
rollback. Restore a consistent backup, or prepare a separately reviewed recovery
using the retained snapshots and native lifecycle without overwriting later work.
No one-click reversal or correction is provided in this initial feature.

## Validation

Database-free suites:

```sh
php test/test_opening_balance.php
php test/test_opening_view.php
php test/test_opening_report.php
php test/test_opening_projection.php
```

Guarded database suites reset only the disposable MariaDB fixture at
`127.0.0.1:33317/kreabank_auto_test`. Never run them against an installation or
concurrently with another suite using that fixture:

```sh
php test/test_opening_balance_database.php
php test/test_opening_report_database.php
php test/test_opening_integration_database.php
```

Set `KREABANK_DOLIBARR_ROOT` to installed Dolibarr source where needed. The setup
view suite's `--html en_US|pt_PT prepare|review|applied|initialize` mode renders
synthetic states without an application bootstrap or database. Verify desktop and
phone layouts against native setup forms. Financial acceptance uses disposable
records; do not submit a real opening as a test.
