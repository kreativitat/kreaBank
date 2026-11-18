# Banking workspace

Operational banking pages share six tabs: Bank statement, Statements, Reconcile,
Monthly reports, Movements and History. The selected-account Movements view shares
the operational tab bar and highlights Movements. The native left-menu Movements
entry directly below New financial account opens a separate global list without
operational tabs. Reconcile opens the new `reconciliationlist.php`. The legacy
`reconcile.php` controllers and direct routes remain dormant and intact. Connections also retains its
direct route without an operational tab. Bank statement combines all imported
movements; Statements retains the individual import list and details. Account
management is separate: the native Banks/Cash heading opens the accounts
overview. Bank references and the statement icon open the account-scoped Bank
statement with its six tabs; pending counts open Reconcile. An owned account
without imports keeps its empty statement view and account context. Cash accounts retain native Movements because they
are excluded from bank reconciliation. The pencil icon in the right-aligned
Actions column opens the account’s native details and tool tabs.
Bank rows have a statement icon beside the pencil, using the same scoped
statement URL as the account reference.
The accounts overview and account-tool pages do not display the operational tabs.
Internal transfers and new-account creation keep the native layout without a
workspace header or tabs.

A separate Filters panel below unscoped operational navigation contains one bank-account
selector, listing only current-company accounts. The native movement list uses
its own table filters instead. Opening an account fixes the
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

Selected-account Movements, Reconcile, Bank statement, Statements and History show the current Dolibarr balance, synced bank
balance and bank refresh time above the page content. These reuse the accounts
overview's read-only projection, currency and snapshot status, independently of
list filters, matched view or pagination. The shared order is bank identity, operational tabs where applicable,
balance panel, then each page’s existing controls and table. Normal selected-account
native Movements hides only its duplicate account-banner wrapper; native action
confirmations and account tools keep their original content. Closed status remains
visible in the shared identity. The accounts overview omits its duplicate New
financial account list button; the native left-menu action remains available.
Missing snapshots remain a dash and stale/failed snapshots retain their status.
Monthly reports show only their selected-period evidence and balances, without the current-balance panel.
The all-accounts view has no combined balance. Deploy `lib/kreabank.lib.php`,
`lib/kreabank_navigation.lib.php`, `class/actions_kreabank.class.php`,
`css/navigation.css` and `accounts.php` with the descriptor/version files.
The existing `lib/kreabank_integration.lib.php` provides the projection; no
registration or schema update is required.
The direct **Unreconciled** movement view (pt_PT: **Por reconciliar**) selects native
`b.rappro=0` rows within the same owned-account scope before counting/pagination.
Since 1.20.66, `bankentries_list.php` uses only its native table filters: the extra
All/Reconciled/Unreconciled controls and separate account Filters panel are absent.
The banking header, scoped balances, bank-entry detail controls and statement
numbering remain unchanged. Explicit `kreabank_matched=2` links still select
unreconciled rows and persist through native search/sort/page links; existing
reconciled-view semantics remain unchanged. This presentation change requires no
schema, menu-registration or scheduler update.

Since 1.20.67, the Movements tab opens the native list with the selected account's
`id`, so native account loading fixes the scope before counts and pagination and
retains it through search, reset, sorting and paging. The `completeArrayFields`
hook removes `ba.ref` from scoped lists, including its column-picker option;
saved native column preferences cannot restore the redundant account column or
filter. Preferences are not rewritten. Since 1.20.68, the left-menu global list
has no workspace tabs and retains the bank-account column, filter and picker
option, including when narrowed to one account. Native `search_account` and its
`account` alias remain editable list filters; only fixed `id`/`ref` or explicit
legacy `account_context` select workspace presentation. Search, reset, sorting and
paging never promote an editable filter to fixed context. Both context and filter
IDs retain direct current-company ownership validation.
The native controller and financial actions remain unchanged. Validate with
`php test/test_navigation.php` and `php test/test_movement_columns.php`, which
uses the installed native column selector, including text-browser mode.

The native Dolibarr left menu remains visible with its standard layout and
responsive behavior. For internal workspace readers, the existing `leftblock`
hook retains the native Entry list as **Movements** (pt_PT: **Movimentos**) directly
below New financial account, preserving its URL, permissions and enabled state.
It removes the Account list, old Reconcile and separate Reconciliation list links, keeping
the **Banks/Cash** heading (pt_PT: **Bancos Dinheiro**) as the account-list entry
point. The existing KreaTax fiscal/bank report follows Movements (and its children)
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
Production 1.20.14 was verified on Dolibarr 24.0.2 / PHP 8.1 after deploying
source `c94d995fd8ae4e3b76b51fe9fdcd571a76d7f4b9`. Guarded preimage checks,
production PHP lint and deployed hashes passed; configuration, scheduler/menu
registration and financial state were unchanged during each source deployment.
Private preimage rollback archives remain outside the webroot. This update needs
no schema migration or registration refresh.

The internal browser and dedicated Chrome checks confirmed native menu ordering,
standalone Movements without operational tabs, retained filters/pagination and
balances, consistent selected-account headers, and removal of the accounts-list
creation button. History entry/Undo controls and the reason input each measured
112 by 28 CSS pixels, with centred button labels and distinct Undo colour.
The new list stopped at 25/25 with no automatic worker form; explicit More opened
the next unanalysed native page and stopped at 25/25 again. Existing reconciliation
controller/assets remained byte-identical. No financial approval, correction,
batch execution or Undo was submitted in production.

Focused verification passed: 277 navigation/scope checks; installed native-menu
rendering and four authorization checks; 166 connected-view labels; 110 separate
list/controller guards; 30 batch-quota and 31 actual-controller admission/redirect
checks; 354 view and 67 installed-native render checks; 22 History checks; and
26 exact-allocation plus 73 Chromium interactions. Modal opening, keyboard focus,
close/return focus and correction POST wiring used intercepted synthetic records;
no eligible correction was present on the live pages inspected.

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

## Native internal transfer form

The transfer form at `/compta/bank/transfer.php` uses a presentation-only footer
enhancement for workspace readers. `lib/kreabank_transfer.lib.php` loads scoped
`css/transfer.css` and `js/transfer.js`; core Dolibarr source is unchanged. The
design reference is the installed Dolibarr 24 UI documentation
(`/admin/tools/ui/index.php`) and the existing monthly-report controls, borders
and spacing. Native-only users keep the native presentation.

Keep the original form, field nodes/names, token, row siblings and hidden-state
classes: native adding, currency switching and failed-input restoration depend
on them. Initialize after native ready handlers. Add is a non-submit button
that invokes the native add action, focuses the revealed account and disables
at the native limit of 19 rows. No transfer is submitted during UI verification.

Run `php test/test_transfer.php` with installed Dolibarr source and no application
bootstrap or database. `--html error pt_PT` renders a synthetic retry/foreign
currency fixture; `--html cheque en_US` includes the optional cheque field.
Serve generated HTML only in the isolated local test installation; submissions
are intercepted. Check exact form values, all 19 rows, labels/focus and responsive
rendering there, then inspect the actual native theme without saving a transfer.

Deploy the helper and both assets before the footer hook, with the version files
and appended locale keys. Preserve preimages and any independent locale changes.
No schema migration, menu refresh, module reactivation or scheduler change is
needed. Roll back the hook first and restore exact preimages; remove only new
assets that still match the deployed checksums.
