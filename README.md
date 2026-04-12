# KreaBank for [Dolibarr ERP & CRM](https://www.dolibarr.org)

KreaBank is a native-first bank statement import and reconciliation assistant for Dolibarr.

It is designed to accelerate bank operations without creating a parallel accounting engine: imported statements and reconciliation results are persisted in Dolibarr's native bank domain, while KreaBank stores only the metadata required to support import, matching, and audit flows.

## What KreaBank does

- Imports bank statements from CSV, OFX, CAMT.053 XML, XLS, and XLSX files.
- Lets the user confirm field mapping before import.
- Detects reusable import layouts per bank/export format.
- Prevents duplicate imports with contextual idempotency checks.
- Suggests matching customer and supplier documents for reconciliation.
- Persists reconciliation through native Dolibarr bank/payment links and native reconciliation status.
- Provides pending, reconciliation, and history workspaces on top of the native bank module.
- Respects Dolibarr permissions and active `entity` scoping.

## Native-first architecture

Official statement and reconciliation data remains in native Dolibarr tables:

- `llx_bank`
- `llx_bank_url`
- native payment/bank linkage flows triggered through Dolibarr business logic

KreaBank auxiliary tables are limited to metadata and operational support:

- `llx_kreabank_bankmeta`
- `llx_kreabank_import_profile`
- `llx_kreabank_pattern`
- `llx_kreabank_quick_entry`
- `llx_kreabank_recon_audit`

This keeps bank reconciliation aligned with Dolibarr core behavior and avoids a second source of truth for financial data.

## Requirements

- Dolibarr with native bank module support (`modBanque`)
- Dolibarr version `>= 19`
- PHP `>= 7.1`
- Composer only when the deployment package does not already contain the module-local `vendor/` directory

## Installation

### From GitHub

Clone the repository into Dolibarr's custom modules directory:

```bash
cd /path/to/dolibarr/htdocs/custom
git clone https://github.com/kreativitat/kreaBank.git kreabank
```

If the deployed copy does not contain the module-local dependencies, install them from the module root:

```bash
cd /path/to/dolibarr/htdocs/custom/kreabank
composer install --no-dev --prefer-dist
```

### From a ZIP package

If you deploy the module from a packaged ZIP, upload it from:

`Home -> Setup -> Modules -> Deploy external module`

If the ZIP package does not ship the `vendor/` directory, run the same `composer install --no-dev --prefer-dist` command inside `custom/kreabank` after deployment.

### Activation

1. Log into Dolibarr as a super administrator.
2. Open `Setup -> Modules`.
3. Enable `KreaBank`.

## Operational flow

### Import

1. Open `/custom/kreabank/import.php`.
2. Upload a statement file and confirm the detected mapping.
3. KreaBank normalizes the imported data and persists the result into Dolibarr's native bank domain.
4. Technical metadata is stored in KreaBank support tables for idempotency, profile reuse, and auditability.

### Reconciliation

1. Open `/custom/kreabank/reconcile.php`.
2. Review the proposed matches for invoices or payments.
3. Confirm reconciliation.
4. Reconciled state remains visible in the native Dolibarr bank pages under `/compta/bank/...`.

### History and pending review

- `/custom/kreabank/pending.php` lists statement lines still awaiting action.
- `/custom/kreabank/history.php` exposes reconciliation and audit history.

## Permissions and multicompany

- Read access follows native bank read rights: `banque->lire`
- Write/import/reconciliation actions follow native bank write rights: `banque->modifier`
- Queries and writes are scoped to the active Dolibarr `entity`

KreaBank is intended for real ERP environments and must not leak bank or reconciliation data across entities.

## Regression checks

Run the module regression script from the repository root:

```bash
bash test/run_regression_native.sh
```

The script includes:

- PHP lint checks on key entrypoints and classes
- route safety checks for KreaBank and native bank URLs
- optional HTTP smoke checks against a running local container
- parser wiring checks
- format consistency tests
- reconciliation guard tests

## Additional documentation

- Native assistant architecture note (Portuguese): `doc/KREABANK_NATIVE_ASSISTANT.md`

## License

### Main code

GPL v3 or later. See [COPYING](COPYING).

### Documentation

Project documentation and README content are licensed under [GFDL 1.3](https://www.gnu.org/licenses/fdl-1.3.en.html).
