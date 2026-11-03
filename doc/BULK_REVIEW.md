# Responsive bulk review

The individual and bulk pages share the reconciliation workspace's visual layout.
Each pair separates the signed statement-line description, account, date and amount
from the existing target record. Allocation and remaining difference are visible;
matching evidence and original references expand without replacing the pair.
The target explains whether confirmation links an existing movement/payment or
records a payment against an existing invoice. It never implies a new invoice.

Completed scans without eligible matches offer **Create a new entry and reconcile**
in the account-scoped selected-line workspace. This is a read-only navigation link;
`workflow=create` requests presentation only and cannot bypass permissions or account
restrictions. Loading and truly empty queues offer no financial action. Other
pending lines remain accessible through the account-scoped workspace navigation.

Bulk selections show a live count and exact, separately formatted currency totals.
Approval stays separate from selection, and changing the selection clears approval.
No currencies are combined. Individual review still uses one explicit approval
button and no checkboxes. Without JavaScript, native required approval and all
server checks remain authoritative.

Bulk review opens without computing suggestions. Authenticated, CSRF-protected
background POST requests use the existing service and matcher to advance the scan.
Each request checks its configured time budget between individual lines, including
when preparing a different results page. A slow individual database query cannot
be interrupted by this budget; it never authorizes skipping that line.

The native PHP session lock is released during matching and acquired briefly to
save progress. A generation and cursor check reject results after another request
changes the company, account, results page, queue or scan. Concurrent requests
cannot overwrite newer progress. A changed context requires refreshing the page.

Only the displayed page's prepared rows are retained alongside the existing compact
match index. Pages contain at most 100 suggestions. Presentation can remain cached
for the configured scan TTL. Preselection still uses the shared safe suggestion;
the score is points, not a probability. Amount and date alone give 100 points.

Competing candidate documents within 35 points remain pending; the page never
chooses an arbitrary first invoice from an amount/date tie. Source account/owner
and target document reference are shown separately from the counterparty name.
Every prepared source and execution target must belong to the current company;
native movement/payment links must use the same bank account. Payment parents
cannot cross companies. These checks also run inside the execution transaction.
The suggested correspondence panel shows the account reference read from the target
native movement or linked payment. Documents without a recorded bank movement show
`-`; the source account is never substituted. Target account identity/reference are
bound to the signed approval. Validate with `test/test_suggestion_bank_account_database.php`
on the same guarded disposable fixture.
Native movements also show their linked customer/supplier payment references before
the existing description. These are read through owned, consistent payment links
and signed with the displayed pair; scoring references remain unchanged. Validate
with `test/test_suggestion_payment_reference_database.php` on that disposable fixture.

Users can select suggestions requiring manual review and tick the explicit approval
checkbox before confirming. This is a manual decision, including for pairs without
a reference/IBAN; unattended matching and automatic batch approval retain their
existing strong-identity, score and ambiguity rules. A full counterparty name found
only in a bank description adds weak supporting evidence, never automatic authority.

Each displayed pair carries a session signature bound to the company, user, account,
source namespace, source facts, target identity/description/date/amount, allocation
and a 30-minute expiry. Confirmation reloads and recomputes the pair; changed,
unavailable, unsigned or expired pairs stay pending and are reported. Selection
changes clear approval. Resuming analysis replaces the form and requires review
again. Double submits are blocked in the browser; server-side locks and persisted
status reject retries and competing claims.

Execution goes through `KreaBankService::reconcileLine()` as `bulk_manual`, with
`is_auto=0`. It rechecks source facts under locks, preserves account ownership,
currency, signed amount and native payment/invoice lifecycle validation, and commits
the reconciliation audit atomically. It does not train automatic patterns. Staged
and legacy native import IDs stay explicit, including when their numeric IDs collide.
After confirmation a redirect refreshes the pending queue and displays completed,
changed/unavailable and failed counts; a zero-result submission is a warning.

The page updates without reloading. Selecting a checkbox pauses background updates
so the current review remains stable; **Continue analysis** resumes explicitly.
The existing automatic-request limit also pauses the scan. Failed requests display
a retry action and preserve already displayed rows. Leaving the page cancels the
browser request; an already running read-only pass may finish and save progress.
Progress has its own region. An unchanged results presentation preserves its DOM,
open details and keyboard focus. Individual matched review displays its pair only
when scanning and sorting finish, then stops polling; partial discoveries cannot
replace the pair being reviewed. Payment lookup index maintenance is documented in
[PERFORMANCE.md](PERFORMANCE.md).

No financial operation occurs during loading or scanning. Scheduled automatic
reconciliation remains independent; no job configuration or schema is changed.

Validation:

```sh
php test/test_bulk_scan.php
php test/test_bulk_review.php
php test/test_matched_review.php
node test/test_bulkmatch_client.cjs
php test/test_review_workspace.php
node test/test_review_workspace.cjs
php test/test_reconciliation_guards.php
php test/test_navigation.php
# ONLY the disposable localhost:33317/kreabank_auto_test fixture:
php test/test_bulk_review_database.php
```

The bulk scan tests use synthetic callbacks and a controlled clock. They do not
bootstrap Dolibarr, access an installation database or reconcile records. The separate
database suite uses installed native AccountLine source and disposable synthetic data.

Deploy changed source and translations together. No schema migration, module
reactivation, scheduler change or worker restart is needed. Existing open bulk forms
must be refreshed before confirming. Back up the changed files and restore those
exact preimages for rollback; do not restore a database or replay a confirmation.

Presentation lives in `lib/kreabank_review_view.lib.php`,
`css/review-workspace.css` and `js/review-workspace.js`. Deploy these with
`bulkmatch.php`, `js/bulkmatch.js`, both translations and the selected-line
`reconcile.php` workflow-link support. Assets use modification-time cache keys.
The scan client owns selection changes and refreshes the display after updating
selection/approval; unchanged scan presentations still retain focus and details.

For browser checks, `php test/test_matched_review.php --html single ready` (or
`bulk`, with `invoice`, `unmatched`, `empty`, `loading`, `readonly`) emits the actual
controller/view with synthetic dependencies. Serve it as static HTML with module
assets exposed under `/custom/kreabank/`. Submissions are intercepted and no ERP
endpoint exists behind this fixture. Never submit financial actions on production
as a UI test.

## Individual matched review

`matched.php` uses the same controller, matcher and signed approval as bulk review,
with exactly one proposed pair per page. The reconciliation subtab **Review matched
one by one** excludes unmatched lines and payment-account warnings. Ambiguous
candidates remain pending under the existing shared resolver rules. Weak unique
suggestions still require explicit manual approval through **Reconcile this match**.
The button submits the sole displayed source without selection or approval
checkboxes; the bulk page retains its checkbox approval and safe preselection. Previous/next links preserve the account and sort order. Confirmation
accepts at most one source and reloads the remaining queue. Both presentation
caches are invalidated after a confirmation. This view has its own scoped scan
state, so scanning it cannot overwrite the bulk page's progress. No schema or
scheduler changes are required.
