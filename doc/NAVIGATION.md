# Bank account navigation

KreaBank 1.14.4 adds a KreaBank tab beside native account movements. It keeps the
native account, movements, statements, attachments, reports and forecast pages.
The native manual reconciliation tab is labelled **Number bank statements** to
distinguish it from matching imported transactions to ERP records. Its URL and
behavior are unchanged.

The workspace has four main tasks, in this order: reconciliation, statement
import, bank connections and history. One-by-one review, batch review and pending
lines are secondary tools within reconciliation. Batch and pending pages remain
accessible through existing bookmarks, but no longer repeat in the sidebar.

An explicit **Bank account** selector supports one account or all accounts in the
active company. Selected-account pages show the same native account tabs and
retain the account through workspace links, filters, pagination and forms. Import
prefills that account; choosing another target in the import wizard updates its
context. Pending and batch scans filter before pagination. Reconciliation history
uses the selected account; the audit section is labelled company-wide because
it also contains events without an account.

Permissions remain server-side. Import requires the existing module and bank write
rights; other navigation links follow their destination's read rights. Account
context requires direct current-company ownership and does not grant access to
shared or foreign-company accounts. Other modules' account tabs are preserved.

## Upgrade

No schema migration is required. Back up and deploy the changed source files and
new CSS/navigation library. Existing installations must refresh KreaBank's stored
hook contexts, CSS and menus. Use the CLI helper, first without `--apply`:

```sh
php custom/kreabank/scripts/update_navigation.php --root=/dolibarr/htdocs --login=admin
php custom/kreabank/scripts/update_navigation.php --root=/dolibarr/htdocs --login=admin --apply --backup-dir=/private/backup
```

The backup directory must already exist, be private, and be outside the webroot.
The helper requires an active installation administrator and backs up only the
affected UI registration. It refreshes companies where KreaBank is already enabled
using native menu/constant APIs. It does not activate modules, reset settings,
load SQL, change rights, change scheduled jobs, or collect bank data. It refuses
to overwrite a previous registration backup. Reload the browser after updating.

For rollback, restore the prior deployed source and the saved KreaBank hook/CSS
constants and module-owned menu rows from `navigation-registration.json`, keeping
their original company IDs and menu parent IDs. Keep the old source and snapshot
together; a module disable/enable cycle is not a navigation rollback.

## Checks

```sh
php test/test_navigation.php
php test/test_reconciliation_guards.php
php test/test_reconciliation_runtime.php
php test/test_reconciliation_quickflows_runtime.php
```

Navigation tests load installed source without bootstrapping Dolibarr or opening a
database. Set `KREABANK_DOLIBARR_ROOT` if the module is outside the installed tree.
Verify rendered account and global views, account switching, read-only navigation,
batch/pending links, pager/filter retention, and returning to native tabs. Do not
import files, collect feeds, reconcile or undo real bank records as UI tests.
