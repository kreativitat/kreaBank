# Reconciliation list

`reconciliationlist.php` is the banking reconciliation list. Its row actions are
one selector for existing movements (the layers button) and ordinary bank-movement creation.
The standalone Search row action and panel were removed in 1.20.51. The selector
accepts one or more movements; its text filter remains available. More options and
all `more_entry`, `more_rule`, `more_writeoff` and `more_draft` actions were removed
in 1.20.49; stale POSTs are rejected before workspace/service dispatch.

The selected-line workspace remains available through `workspace=1` and the
legacy `reconcile.php` redirect. Skip line, quick tax creation and quick supplier
invoice creation are removed from every entry point and service API. Existing
invoice payments, tax matching, rules, reviewed differences and historical audit
records remain supported. Draft advice requires exact persisted source/draft
amounts and a complete counterparty or bounded real invoice reference; native
validation repeats the same relevance check under source/invoice locks.

The list remains directly accessible; its sidebar shortcut is hidden and no
longer registered. All accounts belong directly to the active company; cash
accounts are excluded. Account selection is explicit in the header filter,
never session-global.

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
Unpaid invoice and tax suggestions have a grey disabled Reconcile action until a
payment is recorded. Rows never issue approval tokens
for these documents; the controller also rejects earlier signed invoice/tax reviews.
Existing native movements and recorded payments retain their reviewed approval.
Eligible unpaid customer/supplier invoice proposals offer **Record payment** in
the row. This opens the installed native payment form
in Dolibarr's iframe dialog, with the invoice, statement account, booking date
and exact bounded amount prefilled. Native payment permissions and confirmation
remain authoritative. Closing with X or Escape, or cancelling without a native payment POST, returns
to the unchanged list without navigation, candidate reads or restarted analysis.
A native payment POST attempt invalidates only that row's advisory approvals and
requests one fresh row read through a consumed, canonical return URL. Automatic
analysis stays paused after return; neither a close nor a POST attempt means
payment succeeded or automatically reconciles the line. The existing-movement selector remains available to find recorded payments.

The payment shortcut requires owned, pending, unallocated, unmapped staged
evidence in company currency. Legacy ledger imports and mapped/partial sources
remain excluded. Existing unpaid salaries and taxes use `recordpayment.php` and
`KreaBankPlannedPayment`: the native standalone salary/tax payment controllers
lack the parent ownership and locked remaining-balance checks required here.
The adapter uses native payment objects and bank attachment inside one guarded
transaction; it creates no parent document and never reconciles the source.
Amount, date and bank come from the displayed statement; payment method,
reference and note remain explicit choices. Native invoice payment forms retain
their existing routes.

Salary advice stays outside the matcher and bulk execution. A suggestion needs
an exact unpaid balance, complete employee identity in the statement and a nearby
salary period. Native salary visibility (`readall`, or read plus employee
hierarchy) applies before reading names. Tax advice similarly needs exact balance
and meaningful identity. Ambiguous/truncated results remain pending. Existing
movements, unbanked payments, other-account warnings, restricted accounts and
active batches block new payment creation. Private actor/company-bound contexts
expire after fifteen minutes and are single-use on POST. Parent, payments,
source and bank are checked again under locks before native creation; the audit
commits with the payment and source stays pending for separate reconciliation.

Compact status tabs replace the redundant status column while preserving native
header filters and pagination. A selected bank hides the repeated statement-bank
column and selector, retaining the bank as hidden native list state. The all-bank
view retains the bank column and its filter. Statements follows the same conditional-column
rule and drops stale filters for its hidden column.
Exceptional review warnings remain beside the match evidence.

Other-bank advice appears directly in the row's **Payment bank** column, including
when the row is collapsed. One complete eligible movement uses an underlined
warning and bank reference to open the existing reviewed correction dialog.
The selector has no separate other-bank section or correction cards.

Strong payment identity can still supply the normal Match card. Exact signed
amount and booking-day evidence alone is only a **possible bank issue**: Match
remains **No match**, with no automatic identity, selection or learned evidence.
An eligible exact full-amount native movement already in the source bank takes
precedence over this weaker coincidence. Competing and incomplete results never
choose a correction automatically. Fresh list creation, selection and execution
checks preserve this review condition under existing locks.

Invoice, salary and tax payments retain their native ownership, visibility and
financial locks. Reconciled, posted, deposited, claimed and protected movements
cannot be moved through this shortcut. Successful correction returns to the list
for fresh same-account reconciliation; the two approvals remain separate. Closing
the correction dialog does not submit, navigate or restart analysis. The inline
control and existing modal follow the installed Dolibarr UI button/table guidance
and the native theme; no new page shell or dialog framework is introduced.

Suggested row pairs retain the existing signed manual review. The unified selector
lists only movements on the source booking day, in its owned bank account and
currency. One exact signed movement uses `selected_native` through
`KreaBankService::reconcileLine()`, including eligible transfers and restricted-account
transfer counterparts. Its source, target, linked payment and
transfer facts are signed and rechecked under the shared reconciliation locks.
No one-member group is created. Legacy imports already occupy native ledger rows;
the selector rejects them, because choosing another ledger row is a duplicate
correction rather than an ordinary reconciliation. Existing legacy workspace
entry points remain separate. A successful staged scalar selection removes
only the current user's stale private group draft in the same transaction.
Multiple movements retain the existing batch service and its stricter eligibility.
Other-account correction cards sit outside the allocation list and totals.

Bank correction reuses `changeMovementAccount()` with a single-use reviewed
snapshot. Its destination is the statement account. Native modifier permission
is required; current accounting, transfer, restricted-account, imported/mapped
and payroll-operation protections remain. Correction refreshes the payment
unchecked and does not reconcile it.

## Payment batches

**Select existing movements** groups existing native payment movements, including salary
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
`test_worklist_native_translation.php`, `test_worklist_selection.php`, `test_payment_batches.php`, and
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
Selector opening and closing, inline searches, POST redirects and
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
The eligible Create movement plus icon remains in the row,
with the existing permission/no-results conditions and row-position navigation.

## Same-day batch selection

New pending batch discovery, retained selections, review and locked execution use
the source booking calendar day. Searches never widen it. Historical active
groups, projections and undo preserve their original evidence.

Initial selector opening preselects only a unique exact signed-decimal choice of
one eligible movement or at least two eligible native payments. Exact singleton
choices compete with group choices; any competing choice leaves selection manual. The advisory helper uses the
complete, unsearched candidate set and bounded exact arithmetic, including a
complementary-sum proof for near-total batches. Truncation, competing combinations,
changed evidence or exhausted budgets leave selection manual. Saved drafts and
retained form inputs take precedence. GET neither saves a draft nor reconciles.

Totals, difference, count and review actions sit above the bounded payment list;
only members scroll, with keyboard access and contained scrolling at narrow
widths. Explicit signed confirmation still rechecks every component and claim
under the shared service locks.
