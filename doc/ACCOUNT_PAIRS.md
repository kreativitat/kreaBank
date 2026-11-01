# Restricted account pairs

Available in KreaBank 1.15.0 under **Module configuration → Account pairs**.
Configure each restricted account (for example, a Caucionada credit facility) and
its main account explicitly. A main account may have several restricted accounts.
Names, labels and account-number suffixes never create relationships.

Both accounts must be open bank accounts, enabled for reconciliation, owned by the
active company and use the same currency. Self-links, chains and cycles are
rejected. Configuration requires an internal administrator with bank and KreaBank
reconciliation write permissions; POSTs require native CSRF verification. Stale
forms cannot overwrite newer configuration. Relationships are stored in the
company-scoped native `KREABANK_ACCOUNT_PAIRS` constant. No schema migration.

A configured restricted account has only native transfer candidates linked to its
main account. KreaBank rejects invoice, customer/supplier payment, tax and quick
entry reconciliation on that account before creating financial objects. The
native internal-transfer form also rejects other counterpart accounts in either
direction, even for users without workspace read rights. The main account retains
normal invoice/payment activity and transfers to other unrestricted accounts.
These guards cover KreaBank and the native transfer form; they do not replace
Dolibarr permissions or intercept unrelated third-party payment APIs.

## Automatic two-sided reconciliation

Under **Automatic reconciliation**, select both endpoints, check **Automatically
reconcile transfers between configured account pairs**, preview, then select the
existing automation mode. The option defaults to off. Saving relationships alone
does not create, enable or alter scheduled jobs. Processing uses the existing
company runner and its bounded scan; no additional scheduler is installed.

Both imported statement legs must be pending, unallocated, not duplicated, in
open company-owned accounts and have opposite exact persisted amounts, identical
currency and valid booking dates within the configured tolerance. Fintable source
conflicts, skipped records and file-mapping amount repairs are ineligible. Each
leg must select the other through `KreaBankMatcher` and the shared safe suggestion
contract: score at least 150 (or the configured higher floor), strong reference
or mutually verified checksum-valid account IBANs, and a 35-point lead. Candidates
from every configured counterpart participate in ambiguity checks, including
accounts not selected for processing. Amount and date alone never suffice.

An unambiguous, unreconciled native transfer with reciprocal `banktransfert`
links is reused. If either account already has a possible native movement of the
same signed amount within the date window, an absent, ambiguous or inconsistent
other leg blocks creation. Only when neither side has a possible native movement
may the native adapter materialize both imported legs and link them as one native
transfer. New transfers require company currency; foreign-currency transfers need
an existing native transfer because no exchange-rate contract is inferred.
Native transfer movements use the explicit `VIR` operation code.

Execution rechecks evidence under company account, staging and native/range locks.
Both native reconciliation states, statement statuses and per-leg `auto_pair`
audits commit in one transaction. Link/audit/staging failures roll everything
back. Neither repeated runs nor the opposite-side retry create a second transfer.
Native and staging IDs remain explicit, including when numeric IDs collide.
Paired transfers create no invoice/payment and do not train learned patterns.
Ordinary automatic existing-movement matching retains its existing safety rules.

Preview displays each pair once and writes no financial records or audit. Pairs
missing a leg or strong identity, with inconsistent amounts/currencies, or with
competing claims remain pending. Review these instead of inventing balancing
entries. Historical transfers are not rewritten when a relationship is saved.
Existing manual undo operates on the chosen statement leg; the native transfer
and its reciprocal links remain. Review both sides when reversing reconciliation.

## Validation and deployment

`php test/test_account_pairs_database.php` extends the guarded disposable
MariaDB fixture on **127.0.0.1:33317 / kreabank_auto_test**. It never loads
installation credentials or business data. See the container setup in
[AUTOMATIC_RECONCILIATION.md](AUTOMATIC_RECONCILIATION.md).

Deploy the complete module source. Configure relationships independently in each
company and installation. Do not copy company mappings between installations.
MacMiniServerPro remains a test environment with all jobs disabled; enabling
production processing is a separate deployment/configuration action.

Before rolling back to a release without pair support, disable paired processing
and review the removal of relationship restrictions. Already reconciled transfers
remain native records; source rollback does not reverse financial operations.
