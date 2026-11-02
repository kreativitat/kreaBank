# Automatic TPA settlement receipts

Available in 1.15.4 under **KreaBank setup → Automatic reconciliation**. Enable
**Automatically create and reconcile card-terminal settlement receipts (TPA)**
for each intended company, with its existing selected accounts and automation
mode. From 1.15.6, select an active native payment mode for TPA receipts;
missing, inactive or out-of-scope modes stop receipt creation. Native dictionary
sharing applies to payment modes, while bank accounts remain company-owned.
The option defaults off and uses the existing bounded company runner;
no additional job or host scheduler is installed. Test installations retain
their disabled jobs.

The existing safe matcher gets the first opportunity to reuse a proven native
movement. With TPA receipts enabled, an otherwise unmatched positive settlement
can create one native bank receipt with the selected native payment mode, using
`Account::addline()` and `AccountLine::update_conciliation()`. Its original amount,
booking/value dates, description and import metadata are retained. This is a bank
receipt for card sales already settled by the terminal: it creates no sales
invoice, VAT calculation, customer allocation, invoice payment or separate income
document. Do not use it to record sales revenue a second time.

Recognized descriptions start with `FECHO TPA` and contain a complete terminal
and period (`1234567890 PERIODO N 123`) or merchant/terminal/batch identity
(`1234567/1234567890/123`). Trailing location text is allowed. Leading zeroes in
printed terminal and settlement identifiers are preserved; they can distinguish
independent settlement streams. The generic word TPA, fees, refunds,
chargebacks and negative movements do not qualify.

Creation requires an unallocated, pending, nonduplicate staging row in a selected,
open, current-company account, matching the company/account currency and original
persisted amount. Restricted transfer-only accounts and Fintable source conflicts
remain excluded. Invalid dates and file-mapping repairs remain ineligible.

For the same account, any other imported settlement with the same complete
printed identity on the same booking day blocks creation, including completed,
skipped and conflicting rows. Counters can legitimately recur on later dates.
Native ledger checks include reconciled/imported movements: a matching settlement
identity or an equal signed amount within the configured date tolerance requires
existing matching or review.
An unbanked customer payment of the same amount within that tolerance also blocks
creation. Truncated searches fail closed. These conservative checks can leave
legitimate equal-value receipts pending; they must not be bypassed by lowering
matching thresholds.

Preview shows **Create card-terminal receipt** without creating anything or
advancing the cursor. Execution revalidates settings and source facts under
company-account, staging and native range locks. Native receipt, source mapping,
reconciliation flags and `auto_tpa` audit commit atomically. Failures roll back;
retries inspect persisted status and cannot create another completed receipt.
Automatic receipt creation does not train matching patterns. The standard
existing-movement safe-score/reference/ambiguity requirements are unchanged.

Deploy the changed service, matcher, adapter, runner, admin page and translations
together, with version files. No schema migration or module reactivation is
needed. Back up the affected source and company settings first. Enable only the
intended companies/accounts, preview their full pending queue, and inspect
scheduled output plus staging/native/audit readback. To stop creating new
receipts, clear the TPA option or disable automatic reconciliation. Source rollback
does not reverse posted receipts: preserve them and use the reviewed native undo
workflow when needed.

Validation uses `php test/test_tpa_receipts.php` and
`php test/test_tpa_receipts_database.php`. The database suite extends the existing
native reconciliation/account-pair fixtures in ONLY the disposable MariaDB at
`127.0.0.1:33317`, database `kreabank_auto_test`; it never loads installation
configuration or credentials.
