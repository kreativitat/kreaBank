# Reconciliation workspace

`reconcile.php` presents one selected statement line with account, booking date,
original description, signed amount, currency and remaining allocation. Reference
and counterparty evidence is available under Statement details and references.

The queue displays the existing bounded population (up to 200 loaded lines).
Search filters those loaded lines by description, counterparty, reference,
account, date or amount, ignoring accents. It does not fetch or match on each
keystroke. **View all pending lines** opens the full company/account-scoped pending
view; bulk deferral remains there. The queue starts collapsed at mobile widths.
Selecting a line is a read-only link and preserves queue search and sort state.

**Next** (pt_PT: **Seguinte**) moves the first visible pending line to the bottom
and opens the next visible line. It is available in both workflows and to readers,
and disabled when fewer than two lines match the queue search. The order survives
navigation/reloads within the browser tab, scoped to user, company, account and
sort; new lines append and removed lines disappear. Explicitly choosing another
sort uses that sort's own queue. Next changes no pending status or financial data.
The selected-line Resolve this line later control is removed; bulk deferral
remains in the full pending view.

When the initial candidate set is empty, the workspace opens **Create a new entry
and reconcile** if creation is permitted. Existing candidates (including locked
evidence), an explicit entry search or batch search keep matching visible.
Users can always switch workflows; opening creation never submits a record.
Read-only links from matched/bulk review may explicitly request `workflow=create`
or `workflow=match`. Creation still requires the existing write/account checks.

**Match an existing entry** offers existing native bank movements using the
existing candidate loader, search, batch detection and shared safe preselection.
Selecting a checkbox updates the selected total and remaining difference. The
button requires at least one selection and a balanced allocation within the
existing 0.01 service tolerance. Original hidden amounts retain their precision;
only displayed values have two decimals. Server execution still revalidates every
submitted source and target.

**Create a new entry and reconcile** describes each available action:

| Choice | Records and consequence |
| --- | --- |
| Bank entry | Creates/reuses the native bank movement through the existing quick-entry flow and reconciles immediately. It creates no invoice payment. |
| Tax payment | Creates a native tax/social contribution and its payment, links the bank movement and reconciles. Debit lines and existing module rights only. |
| Supplier invoice and payment | Reviews product lines, creates/validates a supplier invoice, records its payment and reconciles. Debit lines and existing supplier-invoice rights only. |

Account-pair restrictions block creation. Read-only users see the source and
candidate evidence without write controls. A failed creation keeps the chosen
workflow, fields, supplier and product lines. Native error reporting remains sanitized; successful
supplier/tax creation shows a persistent result with the created-record link.

The page retains the shared banking header and native left menu. Workflow buttons
support Left/Right, Home and End; native controls retain Tab/Space/Enter behavior.
The invoice dialog contains focus, closes with Escape and returns focus to its
opener. Without JavaScript both financial workflows remain separately labelled;
the existing server validation remains authoritative. Product-line review needs
JavaScript.

## Ownership and execution

Presentation assets: `css/reconcile.css`, `js/reconcile.js` and
`js/reconcile-supplier.js`. The latter extracts the previous inline supplier
editor, with keyboard/focus and failed-input recovery improvements. Assets are
cache-busted by modification time.

Financial actions still call `KreaBankService` and the native adapter/shared
matcher. No second execution path, schema, currency rounding, ownership policy,
CSRF contract, scheduling or unattended matching policy is introduced. Creation
choices control which explicit existing POST action is submitted; inactive submit
buttons cannot become an accidental Enter-key action. The client gate is advisory,
and the server remains the authority under locks.

## Validation

Run with supported PHP (installed source is needed by the native regression
checks) and Node.js:

```sh
php -l reconcile.php
node --check js/reconcile.js
node --check js/reconcile-supplier.js
php test/test_reconcile_workspace.php
node test/test_reconcile_workspace.cjs
node test/test_reconcile_queue.cjs
php test/test_reconciliation_guards.php
php test/test_navigation.php
php test/test_navigation_permissions.php
php test/test_amount_display.php
php test/test_list_sorting.php
```

The view fixture evaluates the real presentation with synthetic read-only
substitutes. It never bootstraps Dolibarr, opens a database or invokes a write
service. To inspect a case, generate HTML with
`php test/test_reconcile_workspace.php --html debit pt_PT`. Serve it in a temporary
static directory with the module `css/` and `js/` exposed under
`/custom/kreabank/`. Every fixture submit is intercepted and reports the submitted
contract; there is no ERP endpoint behind it.

Cases: `debit`, `credit`, `readonly`, `limited`, `empty`, `locked`, `noentries`,
`safe`, `error`, `restricted`, `unavailable`, `precision`, `success`, `single`.
Inspect English and pt_PT rendering.

Browser acceptance performed against synthetic data:

- Desktop and mobile layouts; source summary and native/shared navigation.
- Queue accent-insensitive search, empty search results and keyboard links.
- Next rotates the first visible line, links to the new first line and restores
  the queue after reload. Search with one match and single-line queues disable it.
- Selection totals, remaining difference, mismatch/empty disabling and explicit
  existing-entry POST payload with original amounts.
- Creation choices and tax Enter submission using the chosen action.
- Supplier/product review, mismatch blocking, focus containment, Escape and focus
  restoration; credit/permission/account-pair restrictions and failed-input state.

No real financial action is an acceptance test. Full production theme/runtime
acceptance remains part of a separate deployment.

## Deployment and rollback

Deploy the PHP view, all three assets and both language files together with the
normal module version metadata. No migration, scheduler change or module
re-enable is required. Use the existing production preimage/backup/atomic-file
procedure and perform read-only acceptance in the target native shell. Roll back
the same runtime files together. A Git push does not deploy this release.
