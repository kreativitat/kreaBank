# Banking workspace

KreaBank provides one banking header on module pages and native bank pages.
Its primary destinations are Accounts, Movements, Statements, Reconciliation,
Connections and History. The account selector contains only current-company
accounts and preserves the current view. Import is an action under Statements.
Reconciliation offers compact view controls for single, bulk and pending review.

The bank entry point opens `accounts.php`, with bank, cash and closed-account
views. It shows each account's native current balance in its own currency and
pending imported evidence. It never combines balances across currencies. Native
account-list GETs route here for KreaBank readers. Readers without module rights
retain the native banking interface.

The native Dolibarr left menu remains visible with its standard layout and
responsive behavior. Only the duplicate native account tab bar is hidden inside
this workspace. Global ERP navigation remains available. Account options retain
native account details, documents, annual reports, forecasts and statement numbering;
transfers and cheque deposits remain available to bank writers. Native movement,
payment and invoice lifecycle logic remains responsible for financial records.
No core files are patched. Source evidence stays separate from the ledger.

`lib/kreabank_navigation.lib.php` owns navigation URLs and company ownership.
`ActionsKreaBank` supplies native hooks, read filters and entry-point routing.
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
