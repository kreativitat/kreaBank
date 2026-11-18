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
movement. `KreaBankAutomaticTpaTrait`, inside `KreaBankService`, can then reuse an
exactly identified unclaimed receipt or create one native receipt for an otherwise
unmatched positive settlement. Creation uses the selected native payment mode and
`Account::addline()`; both paths use `AccountLine::update_conciliation()` and the
shared reconciliation finalizer. Original amounts, booking/value dates,
descriptions and import metadata are retained. This records a bank receipt for
already settled card sales; it creates no sales invoice, VAT calculation, customer
allocation, invoice payment or separate income document. Do not record sales
revenue a second time.

Recognized descriptions start with `FECHO TPA` and contain a complete terminal
and period (`1234567890 PERIODO N 123`) or merchant/terminal/batch identity
(`1234567/1234567890/123`). Trailing location text is allowed. Leading zeroes in
printed terminal and settlement identifiers are preserved; they can distinguish
independent settlement streams. The generic word TPA, fees, refunds,
chargebacks and negative movements do not qualify.

UNICRE credits additionally support the exact bank descriptor
`TRF CRED SEPA+ DE UNICRE S.A.` (case and whitespace normalized). The descriptor
alone is insufficient: the source must be a verified Fintable import with one
persisted provider transaction. Its original normalized payload and content hash,
source UID, bank reference, amount, booking date and currency must agree with the
staged evidence. The bank reference must also match its persisted external-ID
hash or provider transaction ID. Generic transfers mentioning UNICRE, file imports
without this provider provenance and incomplete or changed payloads remain for
review. No merchant-name or payer-name guess authorizes creation.

Creation requires an unallocated, pending, nonduplicate staging row in a selected,
open, current-company account, matching the statement/company/account currency
and original persisted amount. Restricted transfer-only accounts and Fintable
source conflicts remain excluded. Invalid dates and file-mapping repairs remain
ineligible. Debits never become automatic TPA receipts.

For the same account, any other imported settlement with the same complete
printed terminal identity, booking day and exact signed amount blocks creation,
including completed, skipped and duplicate-marked rows. Different exact positive
amounts are distinct credits even when a printed terminal/period counter repeats
on the same day. Counters can also recur on later dates. UNICRE source identities
are checked across booking dates: another source with the same bank reference or
provider source UID blocks a new receipt, regardless of its status.

Native checks include reconciled and imported movements within the configured
date tolerance. An equal amount with a different complete settlement identity
does not by itself conflict. An identical identity and amount may reuse exactly
one unreconciled, company/account-owned native receipt with no source/batch claim
or financial-object link. Reuse preserves its recorded native payment mode.
Imported native rows without a trustworthy staged association, already reconciled
identical receipts, multiple candidates and inconsistent mappings require review.

A native receipt with the same terminal identity but a different amount blocks
creation unless an intact completed source mapping and `auto_tpa` audit prove it
is a distinct credit from the same booking day. Unexplained amount changes are
never repaired automatically. Contradictory native labels, references or metadata
also fail closed: a clean field cannot override a refund, fee or cancellation
marker in another field. An unrelated different-amount bank fee does not prevent
a separate proven receipt.

An unidentified equal-amount native movement or an unbanked customer payment of
the same amount within that tolerance still requires review. Truncated searches
fail closed. These checks can leave legitimate credits pending; do not infer a
balancing adjustment, net fees, discard a collision as a duplicate or lower
matching thresholds to bypass the missing evidence.

Native `bank.num_chq` references are limited to 50 characters. Longer source
references use a deterministic 33-character prefix, a hyphen and 16 SHA-256 hex
characters. The complete original reference remains in KreaBank metadata; the
short native reference is never substituted for the original source identity.

Preview distinguishes creating a receipt from reusing an existing receipt and
creates no financial records or audit and does not advance the cursor. Execution
revalidates settings, source provenance and native claims under company-account,
staging and native range locks. Native receipt, source mapping, reconciliation
flags and `auto_tpa` audit commit atomically. The audit records the settlement
kind/identity, exact amount, native operation code and whether a receipt was
created. Failures roll back; retries inspect persisted status and cannot create
another completed receipt. Neither creation nor reuse trains matching patterns.
The standard existing-movement safe-score/reference/ambiguity requirements are
unchanged.

Deploy the service, automatic TPA trait, adapter, runner, admin page and
translations together, with version files. No schema migration or module
reactivation is needed. Back up the affected source and company settings first.
Enable only the intended companies/accounts, preview their full pending queue, and inspect
scheduled output plus staging/native/audit readback. To stop creating new
receipts, clear the TPA option or disable automatic reconciliation. Source rollback
does not reverse posted receipts: preserve them and use the reviewed native undo
workflow when needed.

Validation uses `php test/test_tpa_receipts.php` and
`php test/test_tpa_receipts_database.php`. The database suite extends the existing
native reconciliation/account-pair fixtures in ONLY the disposable MariaDB at
`127.0.0.1:33317`, database `kreabank_auto_test`; it never loads installation
configuration or credentials. It covers distinct amounts, identical-source
duplicates, native reuse, contradictory evidence, provider provenance, currency,
long references, rollback, post-preview changes and concurrent retries.
