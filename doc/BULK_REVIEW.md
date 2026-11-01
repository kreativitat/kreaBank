# Responsive bulk review

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

No financial operation occurs during loading or scanning. Scheduled automatic
reconciliation remains independent; no job configuration or schema is changed.

Validation:

```sh
php test/test_bulk_scan.php
php test/test_bulk_review.php
node test/test_bulkmatch_client.cjs
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
