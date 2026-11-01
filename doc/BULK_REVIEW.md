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
for the configured scan TTL; confirmation always reloads lines and recomputes the
shared safe suggestion before calling the existing reconciliation service.

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
node test/test_bulkmatch_client.cjs
php test/test_reconciliation_guards.php
php test/test_navigation.php
```

The bulk scan tests use synthetic callbacks and a controlled clock. They do not
bootstrap Dolibarr, access an installation database or reconcile records.
