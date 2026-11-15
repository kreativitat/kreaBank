# KreaBank for [Dolibarr ERP & CRM](https://www.dolibarr.org)

KreaBank imports bank statements and helps finance teams reconcile them with
Dolibarr movements and payments. Imported evidence stays separate from the native
ledger until reconciliation explicitly creates or reuses the required financial
records. Viewing a statement or monthly report never posts a bank movement.

## Banking workspace

The account overview opens directly owned accounts in the active company. Bank
references and the statement icon open **Bank statement**; pending counts open
**Reconcile**. Cash accounts open native **Movements**. The pencil opens account
details and tools.

Five tabs retain the selected account:

| Tab | Purpose |
| --- | --- |
| Bank statement | Combined imported transactions, booking/value dates, debit/credit and bank balances. |
| Statements | Import files, inspect imported statements and their original evidence. |
| Reconcile | Review proposed pairs and resolve each bank transaction in its row. |
| Monthly reports | Read-only reconciliation summary, controls, separate pending bank/ERP lists, and statement/ledger evidence. |
| History | Reconciliation history and company-wide audit, with permission-controlled undo. |

**Movements** remains a separate native left-menu page. Lists use Dolibarr header
filters, sorting and pagination. Selected-account views omit repeated bank columns;
all-account views retain them. Monthly **Summary** and **Controls** use compact
report tables; transaction subtabs retain filterable, paginated lists.

## Reconciliation

Open `/custom/kreabank/reconciliationlist.php`, choose an account or work across
owned bank accounts, then review each proposed match. Analysis starts with the
current page size; **More** processes another bounded group.

- Search existing movements and payments when no suitable pair is proposed.
- Open linked document references in a new tab.
- Use **Record payment** on eligible unpaid invoice proposals without leaving the
  list. Payment recording and reconciliation are separate explicit actions;
  unpaid proposals cannot be reconciled.
- Correct an eligible payment's bank account through the reviewed change-bank
  dialog. Protected, posted, imported, transferred or reconciled movements cannot
  be moved by this shortcut.
- Create a missing movement through **Create and reconcile**, after reviewing its
  payment mode and persisted bank evidence.
- Use **Create batch** for one bank transaction covering several already recorded
  payments, such as salaries. Members must have the same account, currency and
  direction, and their exact total must match. No aggregate ledger entry or payroll
  payment is created.

See the [reconciliation list and batch guide](doc/RECONCILIATION_LIST.md).
The original selected-line workspace at `reconcile.php` remains supported through
its direct routes, with its existing creation and review flows. See the
[selected-line guide](doc/RECONCILIATION_UI.md).

Native internal transfers use Dolibarr's own transfer form and posting lifecycle.
KreaBank presents its controls as labelled, full-width responsive cards, including
additional transfers, without replacing native validation or financial execution.

## Imports and bank connections

- File formats: CSV, XLS/XLSX, OFX, MT940, CAMT.053, Norma 43 and supported text
  exports, with reviewed column mapping and reusable import profiles.
- Contextual source identities prevent duplicate imports and preserve source
  dates, amounts and balances.
- Fintable collects bank balances and posted transactions through an encrypted,
  shared connection mapped by exact IBAN, currency and account ownership. See
  [Fintable](doc/FINTABLE.md).
- Optional, explicitly configured automatic reconciliation handles safe existing
  movements. Restricted/main account pairs and TPA receipts have separate opt-in
  settings and checks. See [automation](doc/AUTOMATIC_RECONCILIATION.md),
  [account pairs](doc/ACCOUNT_PAIRS.md) and [TPA receipts](doc/TPA_RECEIPTS.md).

## Data and permissions

`kreabank_statement` and `kreabank_statement_line` retain imported evidence.
KreaBank also stores reviewed mappings, batch membership, import profiles and audit
records. Native financial records remain in Dolibarr's `bank`, `bank_url`, payment,
invoice and tax objects. Legacy native imports are projected without counting
mapped evidence twice. One shared service and native adapter execute reconciliation.

Bank balances come from supplied statement evidence or a coherent bank feed;
monthly reports show unknown or provisional balances explicitly. The native
ledger is not used as a bank-balance anchor. Read-only reports are current-evidence
reports, not historical close or approval records.

Access requires the relevant KreaBank and native bank permissions. Payment,
account correction, transfer and undo operations also require their corresponding
native rights. Reconciliation revalidates direct ownership in the active company;
shared account access does not grant authority to reconcile another company's data.

## Requirements and installation

The descriptor declares Dolibarr 19+ and PHP 7.1+. The bundled machine-learning
dependency requires PHP 8.0+; learning is unavailable on older runtimes. Current
validation uses Dolibarr 24 with PHP 8.1/8.2. Enable Dolibarr's native bank module.

Install in `htdocs/custom/kreabank`:

```sh
cd /path/to/dolibarr/htdocs/custom
git clone https://github.com/kreativitat/kreaBank.git kreabank
cd kreabank
composer install --no-dev --prefer-dist
```

Run Composer only when the deployment package does not already include the
module-local `vendor/` directory, using a compatible PHP runtime. A packaged ZIP
can also be uploaded through **Setup → Modules → Deploy external module**.
Activate KreaBank as an administrator for a new installation.

For an existing installation, back up source and business data, deploy the complete
compatible file set and follow the relevant migration guide. Payment batches need
the additive migration in the [batch guide](doc/RECONCILIATION_LIST.md).
Refresh only the required native menu/hook registration; do not disable/re-enable
the module or change scheduled jobs as a routine upgrade step. See
[navigation and deployment](doc/NAVIGATION.md).

## Administration and validation

**Setup** controls matching, import options, learning and audit retention. Account
pairs, automation and Fintable have separate administration tabs. **About** shows
the installed version, current capabilities, license and documentation links.

Run targeted checks from the module root using installed Dolibarr source:

```sh
php test/test_transfer.php
php test/test_navigation.php
php test/test_machine_learning_render.php
node test/test_worklist_client.cjs
bash test/run_regression_native.sh
```

Set `KREABANK_DOLIBARR_ROOT` when the native source is elsewhere. The broad regression
script includes parser suites whose bank-format fixtures may be absent from a
source checkout. Database suites require their explicitly guarded disposable
fixtures; never point them at an installation database or create real financial
records as a test.

Further guides: [monthly reports](doc/MONTHLY_REPORTS.md),
[lists](doc/LISTS.md), [bank integration](doc/BANK_INTEGRATION.md),
[learning](doc/MACHINE_LEARNING.md), [tax reconciliation](doc/TAX_RECONCILIATION.md),
[read-only REST API](doc/API.md) and [release notes](doc/RELEASES.md).

## License

Code: [GNU GPL v3 or later](COPYING).
Documentation and README: [GFDL 1.3](https://www.gnu.org/licenses/fdl-1.3.en.html).
