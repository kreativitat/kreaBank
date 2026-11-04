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

**Next** (pt_PT: **Seguinte**) opens the visible line immediately after the selected
line, wrapping at the end, and rotates the queue so that target appears first.
Manual selection and restored queue order must not make it jump from the queue
head instead. If the selected line is hidden by search or absent from the loaded
queue, Next opens the first visible line without skipping it.
It is available in both workflows and to readers,
and disabled when fewer than two lines match the queue search. The order survives
navigation/reloads within the browser tab, scoped to user, company, account and
sort; new lines append and removed lines disappear. Explicitly choosing another
sort uses that sort's own queue. Next changes no pending status or financial data.
The selected-line Resolve this line later control is removed; bulk deferral
remains in the full pending view.

Successful reconciliation continues with the next **displayed** pending line,
including signed per-card approval, native batch allocation and all three creation
choices. Forms carry bounded queue order, visible successors, search and sort;
these identities control navigation only. The server selects a successor only
from a fresh company/account-scoped pending population, skips removed successors
and rotates the queue before loading the selected line's candidates. The returned
queue updates this tab's saved order. Failed actions and partial allocations stay
on the current line. If a completed search has no pending result left, clear that
search and resume the remaining queue. No-JavaScript forms retain server-order
continuation defaults. Deploy `lib/kreabank_reconcile_queue.lib.php` with the
controller and `js/reconcile.js`.

When the initial candidate set is empty, the workspace opens **Create a new entry
and reconcile** if creation is permitted. Existing candidates (including locked
evidence), an explicit entry search or batch search keep matching visible.
Users can always switch workflows; opening creation never submits a record.
Other-account movements with the exact signed amount and nearby booking date
load in the standard review/correction cards on initial selection, including
paid invoices whose bank narrative has no payment reference or full supplier name.
These are possible matches for explicit manual review; the stronger wrong-account
warning used by automation is unchanged. Keep matching visible and
suppress the empty-results message when those records exist; no separate banner
or Search click is needed.
Read-only links from matched/bulk review may explicitly request `workflow=create`
or `workflow=match`. Creation still requires the existing write/account checks.

**Match an existing entry** uses one card layout for native bank movements,
invoices and payments: reference/type, date on its own line, counterparty,
amount, evidence, consequence and a green action on the card. Ordinary native
movements use **Reconcile this movement**, with the exact persisted signed amount
and source account. They reuse the existing native movement and create no payment.

Batch detection or **Search batch payments** retains multiple native checkboxes,
the selected total/difference and the combined approval button. The combined
allocation must balance within the existing 0.01 service tolerance. Original
hidden amounts retain their precision; only displayed values have two decimals.
Server execution still revalidates every submitted source and target.

Invoice and payment candidates have their actual record type and consequence.
They use **Record payment and reconcile** or **Link payment and reconcile** on
the one-by-one page itself. Each button submits its own associated POST form to
`reconcile.php`, outside the native-movement form, with the exact source namespace,
account, expected document and expiring signed review. Invoice approval records
a payment against the existing invoice; it creates no new invoice.
`lib/kreabank_manual_review.lib.php` shares candidate eligibility and execution
with matched/bulk review. Each eligible card has its own button
and signature, allowing an explicit user choice between equal candidates.
Confirmation recomputes that exact target and authenticates the displayed pair
before the service revalidates it under locks. Changing the target invalidates
the signature; no candidate is arbitrarily selected or automatically preselected.
Matched/bulk review retains its unique-suggestion requirement. Changed, foreign
or wrong-account evidence cannot produce an executable pair. Success
returns to the one-by-one queue with account/sort context; failure retains the
selected line and reports the error. No navigation through `matched.php` is needed.

Existing unpaid taxes/social contributions also appear in matching, with
**Record payment and reconcile** (pt_PT: **Registar pagamento e
reconciliar**). This pays the existing tax; the creation panel's Tax payment choice
creates a new tax. Existing unbanked tax payments use **Link payment and reconcile**.
Already banked tax payments reuse their existing native movement. See
[tax reconciliation](TAX_RECONCILIATION.md) for ownership, account-correction and
validation rules.

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
php test/test_reconcile_document.php
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
- Next follows the selected line after manual selection or restored order, wraps
  to the first visible line and restores the rotated queue after reload. A hidden
  or absent selection opens the first visible line. Search with one match and
  single-line queues disable it.
- Successful synthetic approvals and creation flows continue in the displayed
  queue through POST/redirect/GET, retain search/sort and ignore removed or foreign
  successor identities. Failures and partial allocations retain the current line.
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

## Draft invoices

The selected-line existing-record panel also shows current-company customer
(incoming) and supplier (outgoing) drafts, with a **Draft** badge. They use the
same date/search filters and card layout. Expand **Validate invoice…**, review
the confirmation and choose **Confirm invoice validation**. This invokes the
native invoice validation lifecycle only. The selected statement stays pending;
review the newly validated invoice and approve its payment/reconciliation
separately. Drafts never enter the shared matcher, matched queue or bulk approval.

KreaBank/native bank write rights and native invoice read/validation rights are
required, including advanced permissions when enabled. A single-use 15-minute
session review binds the user, company, account, explicit source namespace,
source facts and complete persisted invoice header/lines. Recheck ownership,
status, currency and snapshots under source/invoice/line locks. Native validation
and its audit share a transaction. Changed, repeated or expired reviews fail
closed. Company-currency, unpaid, positive non-credit-note drafts are eligible;
stock-on-invoice configurations use the native invoice form for warehouse options.

Validate with `php test/test_draft_invoice_review.php`,
`php test/test_reconcile_workspace.php` and
`php test/test_draft_invoice_review_database.php`. The database suite uses only
the guarded disposable localhost:33317/kreabank_auto_test fixture with installed
native invoice classes. `--html draftinvoice pt_PT` renders intercepted UI checks.
Deploy the controller, service, translations and version metadata together; no
migration, scheduler change or module re-enable is needed.

## Searching other accounts

The initial selected-line view also searches movements recorded in
other open, reconcilable accounts owned by the current company, with the same
currency/direction and an amount no greater than the statement line. An empty
search uses the exact amount and nearby booking dates; text searches cover native
labels/references, payment/invoice references, full invoice counterparties, dates
and amounts before the 100-result display limit. Restricted transfer-only accounts
and imported/mapped statement evidence are excluded.
Press **Search** to refine these results by invoice, supplier, payment or movement
evidence. A paid invoice is shown through its existing banked payment; it never
becomes an unpaid-invoice candidate or authorizes a second payment.

These results have no reconciliation checkbox. Expand **Change account to…**,
review the old and statement accounts, then choose **Confirm account change**.
The correction changes only the existing movement's recorded bank account,
preserves payment/invoice allocations, and creates an audit. It does not reconcile.
The corrected entry is immediately shown in the ordinary list, unchecked; select
it and reconcile normally. Native movement links remain available for inspection.

Only a current native bank modifier with KreaBank write rights can correct an
account. POST/CSRF and a single-use, 15-minute session review bind the user,
company, staging source, exact native movement and both accounts. The service
rechecks persisted evidence and ownership under account/source/movement/payment/
allocation/bookkeeping locks. Reconciled, accounting-posted, cheque-deposit,
transfer and unsupported protected-operation movements cannot be moved here.
Changed evidence, lost permissions and repeated/stale submissions fail closed.

Validate the controller with `php test/test_account_correction.php` and the native
write/rollback/isolation behavior with `php test/test_account_correction_database.php`.
The latter extends only the guarded disposable localhost:33317 fixture. Never run
it against an installation database. The workspace fixture's `otheraccounts` case
exercises the real renderer with intercepted submissions for desktop/mobile checks.
Deploy `reconcile.php`, the service, native adapter, shared library, workspace CSS,
translations and version metadata together. No migration or scheduler change.
