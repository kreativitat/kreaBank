# Banking workspace

Operational banking pages share six tabs: Movements, Bank statement, Statements,
Reconciliation, Connections and History. Bank statement combines all imported
movements; Statements retains the individual import list and details. Account
management is separate: the native left-menu Account list opens the accounts
overview. Account references open movements; the pencil icon in the right-aligned
Actions column opens the account’s native details and tool tabs.
The accounts overview and account-tool pages do not display the operational tabs.
Internal transfers and new-account creation keep the native layout without a
workspace header or tabs.

A separate Filters panel below the operational navigation contains one bank-account
selector, listing only current-company accounts. On both statement views it lists
only accounts with imported statements, including legacy imports. The standalone accounts overview
uses compact account-type view links (all, bank, cash or closed) and has no filter
form or account selector.
It defaults to bank accounts. There is no selector inside the navigation header.
Operational filters submit directly to their own page, reset pagination and preserve
only account context through tabs, search forms, sorting, pagination and bulk scans.
Account types belong only to the accounts overview; old operational `kind` parameters
are ignored and removed from generated links/forms. The overview ignores stale
`account_context` parameters, since it no longer has an individual-account filter.
Closed-account reconciliation and feed eligibility remain unchanged. The audit log
remains explicitly company-wide, separate from account-filtered reconciliation history.

Import is an action under Statements. Reconciliation offers compact view controls
for single, bulk and pending review. Internal transfers keep the native layout.

The bank entry point opens `accounts.php`, with bank, cash and closed-account
views. It shows each account's native current balance in its own currency and
pending imported evidence. It never combines balances across currencies. Native
account-list GETs route here for KreaBank readers. Readers without module rights
retain the native banking interface.

Selected-account Movements pages show the current Dolibarr balance, synced bank
balance and bank refresh time above the native list. These reuse the accounts
overview's read-only projection, currency and snapshot status, independently of
list filters, matched view or pagination. Missing snapshots remain a dash and
stale/failed snapshots retain their status. The all-accounts view has no combined
balance. Deploy `lib/kreabank_integration.lib.php`, `class/actions_kreabank.class.php`
and `css/navigation.css` together; no registration or schema update is required.
Movements also offers **Unreconciled** (pt_PT: **Por reconciliar**), selecting native
`b.rappro=0` rows within the same owned-account scope before counting/pagination.
The explicit `kreabank_matched=2` view persists through account/search forms and
native sort/page links. Existing reconciled-view semantics remain unchanged.

The native Dolibarr left menu remains visible with its standard layout and
responsive behavior. Its bank section includes a **Reconcile** shortcut
(pt_PT: **Reconciliar**) to `reconcile.php` for internal users with both native
bank and KreaBank read access. The existing `leftblock` hook adds it after the
account list and its children, without a new menu registration or module restart.
Retain the native siblings' sort position so the rendered shortcut stays inside
the **Banks/Cash** block (pt_PT: **Bancos Dinheiro**).
Only the duplicate native account tab bar is hidden inside
this workspace. Global ERP navigation remains available. Selected-account pages
have tabs for details, documents, annual reports, forecasts and statement numbering.
These tools do not add left-menu sections or operational tabs. Transfers and
cheque deposits retain their existing native left-menu entries. Automatic
reconciliation is accessed only through module configuration, not operational
banking tabs or the left menu. There is no floating account-tools dropdown.
Native movement,
payment and invoice lifecycle logic remains responsible for financial records.
No core files are patched. Source evidence stays separate from the ledger.

`lib/kreabank_navigation.lib.php` owns navigation URLs, shared filter forms and
company ownership. `KreaBankIntegration::accounts()` owns account-type filtering
for the standalone overview; operational readers use selected-account scope.
`ActionsKreaBank` supplies native hooks, read filters and entry-point routing.
The shared header renders the selected account’s tools as tabs.
`css/navigation.css` scopes presentation to the banking workspace. Empty native
statement-list URLs open the imported statement list; explicit numbered-statement
views remain native. The matched-movements filter and direct native card links
remain supported. Native list queries require current owner-company membership,
even when multicompany account sharing is enabled.

After deploying hook/menu changes (including the 1.14.25 `leftblock` registration
for the account-list label), run the native registration helper without
re-enabling the module or changing jobs:

```sh
php custom/kreabank/scripts/update_navigation.php --root=/dolibarr/htdocs --login=admin
php custom/kreabank/scripts/update_navigation.php --root=/dolibarr/htdocs --login=admin --apply --backup-dir=/private/backup
```

The helper requires an existing private backup directory outside the webroot. It
updates only registration for already-enabled companies and preserves business
data, rights, schemas and schedulers. Retain the registration snapshot and source
preimages for rollback. Do not overwrite an earlier backup file.

Verify accounts, account selection, native movements and details, statements,
reconciliation views, connections and history on desktop/mobile. Check one visible
banking bar, a visible native left menu, no duplicate account tabs/banner, company
denial, and no page overflow. Verification must not import, collect feeds, reconcile, undo or create
payments as test data. Run `php test/test_navigation.php` for URL/scope contracts.
`php test/test_account_filters_database.php` uses only the guarded disposable
automatic-reconciliation database on port 33317; see
[database setup](AUTOMATIC_RECONCILIATION.md#validation).
