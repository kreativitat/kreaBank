# Reconciliation list

`reconciliationlist.php` is a separate banking feature. The existing
`reconcile.php`, its JavaScript and CSS retain their existing workflows.
The list remains directly accessible; its sidebar shortcut is hidden and no
longer registered. The old reconciliation tab remains dormant, with its code and
direct routes preserved. All accounts belong directly to the active company; cash accounts
are excluded. Account selection is explicit in the header filter, never session-global.

The page uses native `print_barre_liste()`, `print_liste_field_titre()` and filter
buttons. It defaults to newest booking date first; explicit header sort choices
persist through filters and pagination. Source filters run before analysis; match/payment-bank filters and sorts
require the complete bounded analysis before pagination. A visible page becomes
actionable without waiting for other pages. Read-only matching releases the PHP
session lock and authenticates the session, generation and cursor before saving.
Analysis stops during editing and after a bounded automatic request count.
Scans include both staged and recognizable legacy imports with distinct IDs.
An oversized population requires narrower filters rather than silently dropping rows.

Statement and payment bank accounts are separate. Unpaid invoice and tax proposals
show the statement account as the payment's planned destination, with a clock
tooltip; existing movements and payments retain their recorded bank. The same
projection supplies displayed references, payment-bank filters and sorting.
Unique suggestions and explicit search choices use the
existing signed manual review and `KreaBankService::reconcileLine()`; ambiguous
records are never preselected. Typed searches filter eligible native entries,
existing payments and invoices before their bounded limits, across all dates.
Approval rechecks the same search context and exact displayed target.

Bank correction reuses `changeMovementAccount()` with a single-use reviewed
snapshot. Its destination is the statement account. Native modifier permission
is required; current accounting, transfer, restricted-account, imported/mapped
and payroll-operation protections remain. Correction refreshes the payment
unchecked and does not reconcile it.

## Payment batches

**Create batch** groups existing native payment movements, including salary
payments already recorded in the bank ledger. It does not initiate payroll or
send payments. Drafts are private to the current user and company. Selected
members survive search changes; unavailable members require explicit removal.

Review signs the complete original statement and every selected payment. An
approval requires at least two eligible movements in the same account, currency
and direction with an exact DECIMAL(24,8) total. Display rounding never changes
stored evidence or approval amounts. A total alone does not establish membership.

The shared service/native adapter reconciles every member under deterministic
account/source/native/payment locks. Normalized mappings, native reconciliation,
source status and audit commit together. No aggregate bank movement is created,
and native payment allocations and amounts remain unchanged. An active member
cannot be reused by ordinary matching or bank correction, even if its native
reconciliation flag is changed externally.

Completed batches expand to show all members. Group undo requires a fresh signed
review and native/module undo rights, and clears all members atomically. Statement
deletion requires undo first. Statements, native matched filters and monthly
reports verify the full group, including both monthly booking cutoffs. Missing,
changed, foreign or conflicting members are reported for review. A group has no
representative native ID.

Batches require staged statement evidence. Legacy imported summary movements
retain single-pair reconciliation; grouping them would require a separate,
reviewed ledger migration. Unbanked payroll payments and protected salary-account
correction are not introduced by this feature.

## Installation and rollback

New installations load `sql/llx_kreabank_payment_batch.sql` through the module.
For an existing installation, deploy the complete change, then inspect and apply
the rerunnable additive migration using an active installation administrator:

```sh
php custom/kreabank/scripts/migrate_payment_batches.php --root=/dolibarr/htdocs --login=admin
php custom/kreabank/scripts/migrate_payment_batches.php --root=/dolibarr/htdocs --login=admin --apply
```

Refresh only native registration using the existing
[navigation helper](NAVIGATION.md#deployment), with its required private backup.
Do not disable/re-enable the module or alter scheduled jobs. Deploy the new
controller, worklist helpers/assets/translations, shared service/adapter, batch
trait/schema/projection, integration/monthly report changes, statement links and
version descriptor together. No scheduler or external service is added.

Keep source preimages and database backups. Once a batch exists, do not restore
older scalar-only service/projection code while active group mappings remain.
Use the signed group undo first or restore a coordinated database/source backup.
Keep normalized history tables; do not drop them as a routine rollback.

## Validation and references

Pure/controller suites: `test_worklist.php`, `test_worklist_view.php`,
`test_worklist_native_translation.php`, `test_payment_batches.php`, and
`node test/test_worklist_client.cjs`.
The view fixture supports `--html <case> [en_US|pt_PT]` with intercepted submissions.

Database suites `test_payment_batches_database.php`, `test_worklist_database.php`
and `test_monthly_payment_batches_database.php` use only the guarded disposable
localhost:33317/kreabank_auto_test fixture. Run them sequentially; each resets
synthetic tables. Never point them at an installation database.

The design references are installed Dolibarr `compta/bank/bankentries_list.php`
and `admin/tools/ui/content/tables.php`, with the
[Primavera ERP reconciliation workflow](https://help.ila.cegid.com/primavera/docs/1606130127)
as a functional reference. All-account scope, reviewed correction dialog and
named private drafts are KreaBank adaptations. Synthetic rendering was inspected
at desktop/mobile widths in English and European Portuguese. Installation/theme
acceptance and migration execution are environment-specific deployment steps.
Current production UI acceptance is recorded in [navigation](NAVIGATION.md).

Payment bank references with a prepared account correction open the same reviewed
change-bank modal as the exchange icon, in both list rows and expanded candidates.
Read-only, reconciled and ineligible references remain text. Opening or cancelling
the modal changes no records; Save retains the existing signed POST review.

Automatic analysis is limited server-side to the displayed source IDs and native
page size (default 25, maximum 100). Completed batches never restart on reload.
The CSRF-protected More action grants one further page-size batch, using the
current scope, generation and cursor; replayed or unfinished grants are rejected.
With source-based filters, More opens the native page containing the next results.
Each worker request still yields after eight rows or 1.2 seconds between rows.
Global payment/match filters and derived ordering retain their incomplete state
until the whole filtered population is analysed; no partial result is labelled
complete. `php test/test_worklist_scan.php` covers quotas, pages, retries and replay.
A short in-flight session claim prevents concurrent tabs from matching the same
batch. Stale views cannot replace newer state; failed workers return their current
retry cursor while preserving completed summaries.

Row actions preserve the active row, horizontal scroll and keyboard focus across
Search/Create batch opening and closing, inline searches, POST redirects and
read-only analysis refreshes. Position hints expire after two minutes and are
bound to the session/company/user, filter/sort context and page; no financial
inputs or review tokens are stored in the browser.

Unmatched staged rows offer Create movement when native modifier rights and direct
bank input policy permit. Opening the panel only prepares a private, single-use
review of exact persisted evidence. The user chooses an active payment mode and
explicitly approves Create and reconcile. The shared native adapter/finalizer
creates the movement, source mapping and audit atomically, without learning.
Existing movement/payment, duplicate, feed, batch, currency, restricted-account
and ownership conflicts fail closed and are rechecked under locks. The legacy
reconciliation controller is unchanged. Validate using the guarded disposable
`test/test_worklist_movement_database.php`; never create live records for testing.

Proposed, searched and completed references open their typed native detail card in
a new tab. Links use the same constant route mapping as native/bulk review, never
parsed labels or stored URLs. Batch component references open each existing native
bank movement; aggregate batch names and unavailable placeholders remain text.
The eligible Create movement plus icon sits beside the expanded Search control,
with the existing permission/no-results conditions and row-position navigation.
