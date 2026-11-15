# Reviewed payment differences

Small differences are an opt-in selected-line invoice action. They never enter
the shared safe matcher, background automation, TPA receipts, account pairs or
bulk approval, and never train learned patterns. The ordinary invoice action and
its candidate-card layout remain the design reference.

## Configuration

Module setup stores company-specific native constants:

- `KREABANK_WRITEOFF_ENABLED`, default off.
- `KREABANK_WRITEOFF_MAX_ABS` and `KREABANK_WRITEOFF_MAX_PERCENT`, both positive.
- `KREABANK_WRITEOFF_ACCOUNT_BANK_FEE`, `ACCOUNT_ROUNDING`,
  `ACCOUNT_DISCOUNT_GRANTED` and `ACCOUNT_DISCOUNT_RECEIVED`: native accounting
  account numbers, each active and directly owned by the current company.

All values must be present. Invalid/inactive accounts or incomplete limits hide
the feature and reject old approvals. Both limits apply independently to the
current unpaid invoice balance. Percentage checks compare exact decimal products;
they never round a percentage into eligibility. Bank and invoice amounts must be
exact company-currency cents. Overpayments require a separate credit/excess
payment workflow and are excluded from this underpayment action.

Only constants stored directly for the current company apply; inherited global
settings cannot enable this action. Approval locks and rereads those constants,
so a changed flag, limit or account invalidates the displayed review.

## Native behavior

The expandable **Record payment with difference** action signs the original
source, invoice balance, reason, account and configuration. Confirmation rechecks
current native bank/payment/invoice rights, direct ownership, restrictions and
wrong-account warnings. The existing `KreaBankService::reconcileLine()` transaction
locks source/account/invoice/payment/discount evidence, recomputes the policy and
verifies `CommonInvoice::getRemainToPay()` before creating its usual native
`Paiement` or `PaiementFourn` and attaching it with `update_fk_bank()`.

The payment equals the bank amount. For a customer invoice, the native adapter
calls `Facture::setPaid()` with `bankcharge` for bank fees, `discount_vat` for
discounts granted, or `other` for rounding. This uses Dolibarr's explicit partial
closing semantics: no synthetic full payment or balancing movement is created.
For suppliers, the native payment lifecycle remains authoritative. A nonzero
residual remains partially paid and the interface reports that outcome; KreaBank
never forces a supplier invoice to paid.

The configured account is retained with the reason and exact difference in the
atomic `manual_writeoff` reconciliation audit. Native closing is not a generated
general-ledger adjustment; it does not create a new accounting posting.

## Reversal and exclusions

History undo binds the audited payment and invoice facts to its single-use review.
It rejects changed allocations, changed invoices/payments, other payment links and
accounting-posted movements. Within the existing undo transaction, native calls
reopen the invoice, detach and delete only the created payment, then restore the
statement to pending. Detachment prevents Dolibarr's payment deletion from deleting
the imported bank movement. Original statement evidence is preserved.

Credit notes, drafts, foreign-currency invoices, restricted accounts, partially
allocated sources, stale reviews and missing permissions remain excluded. There
is no schema migration or scheduler change. Disabling the flag stops new approvals;
the exact existing audit still governs authorized reversal.

For rollback, disable new approvals first. Keep the write-off-aware History and
native reversal code while these reconciliations remain: an older ordinary undo
cannot reverse their payment and invoice-closing effects. Retain all audits.

The expandable cards and their associated signed forms were inspected in Codex's
internal browser at desktop and 390 px widths in English and European Portuguese,
using intercepted synthetic submissions and the existing invoice-card layout.

## Validation

`php test/test_writeoff.php` checks exact limits, fractional percentage boundaries,
direction, reasons, incomplete configuration and snapshot changes without a
database. `php test/test_writeoff_database.php` extends the existing guarded,
disposable **localhost:33317 / kreabank_auto_test** fixture and installed native
classes. It checks company/permission boundaries, concurrent edits, atomic failure,
native customer/supplier outcomes, duplicate approval and payment-preserving undo.
Never run that fixture against an installation database.

`test/test_writeoff_view.php` exercises the actual signed controller and expandable
card with synthetic data. `test/test_writeoff_reuse_database.php` also checks
write-off undo followed by ordinary reconciliation and ordinary undo on the
retained movement; an old write-off audit must not override the latest approval.

Native API references are Dolibarr 24's customer invoice and payment classes,
supplier invoice/payment classes and `core/class/commoninvoice.class.php`. The
supported partial closing reasons follow the native invoice card action.
