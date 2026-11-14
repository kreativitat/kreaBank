# Banking workspace

Operational banking pages share four tabs: Reconcile, Bank statement, Statements
and History. Movements is in the native left menu directly below New financial
account. Its standalone page does not render the operational tab bar. Reconcile opens the new `reconciliationlist.php`. The legacy
`reconcile.php` controllers and direct routes remain dormant and intact. Connections also retains its
direct route without an operational tab. Bank statement combines all imported
movements; Statements retains the individual import list and details. Account
management is separate: the native Banks/Cash heading opens the accounts
overview. Account references open movements; the pencil icon in the right-aligned
Actions column opens the account’s native details and tool tabs.
The accounts overview and account-tool pages do not display the operational tabs.
Internal transfers and new-account creation keep the native layout without a
workspace header or tabs.

A separate Filters panel below unscoped operational navigation contains one bank-account
selector, listing only current-company accounts. Opening an account fixes the
request-local context across tabs, subviews, search and pagination. The shared
header shows its escaped reference and label beside Banking, replacing the
company name and duplicate reference line, and removes the account selector on every scoped page,
including native account tools and direct Connections links. Return through the
Banks/Cash heading to choose another account. Unscoped views identify All accounts.
The separate worklist takes its header account from the explicit statement-bank
filter and revalidates current-company ownership. On both unscoped statement views the selector lists
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
remains explicitly company-wide, available only in the all-account History view.
Scoped History neither loads nor renders that panel; its reconciliation list
continues to use the selected account before the fixed recent window and paging.

Import is an action under Statements. Direct legacy Reconciliation routes retain
compact view controls for single, bulk and pending review. Internal transfers keep the native layout.

The bank entry point opens `accounts.php`, with bank, cash and closed-account
views. It shows each account's native current balance in its own currency and
pending imported evidence. It never combines balances across currencies. Native
account-list GETs route here for KreaBank readers. Readers without module rights
retain the native banking interface.

Selected-account Movements and the four operational tabs show the current Dolibarr balance, synced bank
balance and bank refresh time above the page content. These reuse the accounts
overview's read-only projection, currency and snapshot status, independently of
list filters, matched view or pagination. The shared order is bank identity, operational tabs where applicable,
balance panel, then each page’s existing controls and table. Normal selected-account
native Movements hides only its duplicate account-banner wrapper; native action
confirmations and account tools keep their original content. Closed status remains
visible in the shared identity. The accounts overview omits its duplicate New
financial account list button; the native left-menu action remains available.
Missing snapshots remain a dash and stale/failed snapshots retain their status.
The all-accounts view has no combined balance. Deploy `lib/kreabank.lib.php`,
`lib/kreabank_navigation.lib.php`, `class/actions_kreabank.class.php`,
`css/navigation.css` and `accounts.php` with the descriptor/version files.
The existing `lib/kreabank_integration.lib.php` provides the projection; no
registration or schema update is required.
Movements also offers **Unreconciled** (pt_PT: **Por reconciliar**), selecting native
`b.rappro=0` rows within the same owned-account scope before counting/pagination.
The explicit `kreabank_matched=2` view persists through account/search forms and
native sort/page links. Existing reconciled-view semantics remain unchanged.

The native Dolibarr left menu remains visible with its standard layout and
responsive behavior. For internal workspace readers, the existing `leftblock`
hook retains the native Entry list as **Movements** (pt_PT: **Movimentos**) directly
below New financial account, preserving its URL, permissions and enabled state.
It removes the Account list, old Reconcile and separate Reconciliation list links, keeping
the **Banks/Cash** heading (pt_PT: **Bancos Dinheiro**) as the account-list entry
point. The existing KreaTax fiscal/bank report follows the native bank siblings
as a level-one item, with its URL and permissions preserved. Keep its physical
array order aligned with native sorting before reindexing: Eldy sorts with
preserved keys but checks the next integer key when closing blocks. This avoids
a divider before the report without hiding native section separators. Native-only readers retain their menu.
The duplicate native account tab bar is hidden inside this workspace.
The normal selected-account movement list also hides its duplicate account banner.
Global ERP navigation remains available. Selected-account pages
have tabs for details, documents and annual reports. Forecasts, native numbered
statements and statement numbering keep their direct routes without visible tabs.
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

The 1.20.3 navigation changes need no schema, scheduler or menu-registration update.
Deploy the shared libraries, hook, navigation CSS and History controller together.

Verify accounts, account selection, native movements and details, statements,
reconciliation views, connections and history on desktop/mobile. Check one visible
banking bar, a visible native left menu, no duplicate account tabs/banner, company
denial, and no page overflow. Verification must not import, collect feeds, reconcile, undo or create
payments as test data. Run `php test/test_navigation.php` for URL/scope contracts.
`php test/test_account_filters_database.php` uses only the guarded disposable
automatic-reconciliation database on port 33317; see
[database setup](AUTOMATIC_RECONCILIATION.md#validation).

The navigation UI reference is the existing banking workspace and installed
Dolibarr UI documentation (`admin/tools/ui/index.php`, form components and
`core/lib/bank.lib.php`); retain its current header, tabs and native menu shell.
For 1.20.1, installed-source PHP checks cover navigation/account scope, native
menu permissions, connected-view translations, list sorting and reconciliation
guards. Synthetic previews of the actual navigation renderer passed desktop and
390px checks in English/Portuguese, including tab navigation and no page overflow.
Full ERP rendering remains UNVERIFIED: the local test installation returned its
technical-error page before authentication in both the internal browser and the
authorized Chrome fallback. No business operation was submitted.

The dormant-tab/header update is covered by `php test/test_navigation.php` and
`php test/test_navigation_native_menu.php`. The latter renders the installed
Eldy final-menu stage without an ERP bootstrap or database, reproduces the old
divider and verifies that fiscal remains inside Banks/Cash while cheque deposits
retain their own section. It also checks the native Movements position, retained
permissions and English/Portuguese labels. Deploy the descriptor, shared navigation libraries,
hook/CSS and worklist controller together. The hook also hides an existing
registered worklist link, so this update needs no menu refresh.

The **Reconcile** operational tab opens the separate reconciliation list. It retains
an owned selected account using `search_account`, or opens all accounts when
unscoped. It adds no header button or sidebar item. The old reconciliation UI
remains dormant. Native bank and module read rights are both required, and the
new list keeps its own column filters.
