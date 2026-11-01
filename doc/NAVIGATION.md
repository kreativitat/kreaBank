# Banking workspace

Operational banking pages share five tabs: Movements, Statements, Reconciliation,
Connections and History. Account management is separate: the native left-menu List
opens the accounts overview, and account names open their details and tool tabs.
The accounts overview and account-tool pages do not display the operational tabs.
Internal transfers keep the native layout without a workspace header or tabs.

A separate Filters panel below the operational navigation contains current-company
account and account-type dropdowns. The accounts overview uses the same panel above
its table. There is no account selector inside the banking header. Filters submit
directly to their page, reset pagination, and persist through tabs, search forms,
sorting, pagination and bulk-scan requests. Operational pages default to all types;
the accounts overview retains its bank/cash/closed default. Both offer an explicit
all-types option. Type predicates supplement current-company ownership before row
limits and counts. Closed accounts remain ineligible for reconciliation; connections
retain the feed's open-bank-account eligibility. The audit log remains explicitly
company-wide, separate from filtered reconciliation history.

Import is an action under Statements. Reconciliation offers compact view controls
for single, bulk and pending review. Refresh open bulk-review forms after 1.14.24;
approval signatures and scan caches now include the account-type filter.

The bank entry point opens `accounts.php`, with bank, cash and closed-account
views. It shows each account's native current balance in its own currency and
pending imported evidence. It never combines balances across currencies. Native
account-list GETs route here for KreaBank readers. Readers without module rights
retain the native banking interface.

The native Dolibarr left menu remains visible with its standard layout and
responsive behavior. Only the duplicate native account tab bar is hidden inside
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
company ownership. `lib/kreabank_account_filter.lib.php` owns pure account-type SQL
predicates; readers must still enforce company ownership independently.
`ActionsKreaBank` supplies native hooks, read filters and entry-point routing.
The shared header renders the selected account’s tools as tabs.
`css/navigation.css` scopes presentation to the banking workspace. Empty native
statement-list URLs open the imported statement list; explicit numbered-statement
views remain native. The matched-movements filter and direct native card links
remain supported. Native list queries require current owner-company membership,
even when multicompany account sharing is enabled.

After deploying hook/menu changes, run the native registration helper without
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
