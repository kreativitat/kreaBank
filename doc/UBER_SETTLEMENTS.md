# Reviewed Uber settlements

KreaBank 1.21.0 connects a complete KreaEats payout to its imported bank credit.
KreaEats supplies immutable report evidence and document associations; KreaBank
owns financial execution through its existing service and native Dolibarr objects.
The common identity is the receiving native bank movement, not an invoice or a
second imported statement.

This optional flow requires PHP 8.1+, compatible Dolibarr native bank objects and
KreaEats 1.20.0 with `KreaEatsBankIntegration`. Other KreaBank flows retain their
existing runtime and permission contracts.

## Configure and review

1. Configure the restaurant's verified receiving EUR account and Uber supplier in
   KreaEats. Establish where its sales receipts and any intermediate cash-close
   transfers are actually posted; a payment-mode or account name is not proof.
2. In KreaBank **Setup → Uber settlements**, explicitly map that restaurant to its
   existing company-owned clearing account. Both accounts must be open, distinct
   EUR accounts; neither endpoint may be a restricted account. No mapping is inferred.
3. In **Reconcile**, an eligible positive bank credit shows **Review Uber
   settlement**. Advice requires exact net cents, receiving account and a booking
   date from the reported payout day through seven days later. A truncated bank
   description is acceptable for advice, but amount/date alone never authorize
   unattended execution. Competing payouts remain explicit user choices.
4. Review the original payout reference, period, sales, signed charges/adjustments,
   bank credit, clearing account and both transfer legs. Confirm the identity
   against the original evidence before approving.

The action reuses one complete reciprocal native transfer when its locked facts
match. Otherwise it creates clearing **−net** and receiving bank **+net**, using
native `Account` lifecycle and reciprocal transfer links. If the clearing account
is a native cash account, both legs use native `LIQ` semantics. Existing ambiguous,
one-sided, claimed, conflicting or linked movements stop execution.

The receiving bank leg is reconciled with the imported credit and associated in
KreaEats within the same outer transaction. Native reference annotations identify
reviewed provider evidence; they do not overwrite the bank-supplied description,
reference, amounts or dates. A deterministic association key and consumed signed
review prevent blind replay after a timeout or duplicate submission.

## Financial boundaries

This is bank-receipt reconciliation, not automatic completion of the whole payout.
Existing sales payments stay paid. Supplier invoices, commission payments, refunds
and individual adjustment documents need their own reviewed evidence. Never create
another payment from the receiving bank for a commission already withheld by Uber,
and never manufacture an adjustment to empty the clearing account.

For example, synthetic reported sales of 1,200.00, charges of −300.00 and explicit
adjustments of −10.00 produce a net transfer of 890.00. Only the 890.00 transfer is
executed here. The other components must already be accounted for or remain visibly
unresolved in their document workflow.

KreaEats association status and KreaBank bank-statement reconciliation status are
independent. Ordinary reviewed bank Undo resets reconciliation while retaining the
native transfer and KreaEats evidence. Reversing a transfer or invoice payment is a
separate native financial operation. Reconcile the retained receiving movement
through the ordinary reviewed selector after Undo; never create a replacement.

## Safeguards and ownership

Current internal-user, native bank transfer and KreaBank write rights are checked
again at execution, together with KreaEats read/reconcile and native document read
rights. Store, supplier, accounts, report provenance, complete payout sources, all
retained associations, configuration, original bank evidence and native transfer
facts are bound to a 15-minute, single-use review.

The shared company reconciliation lock, account/staging locks and native KreaEats
store/payout/target locks protect the atomic write. Opening boundaries, feed
conflicts, existing payment/batch claims, exact signed amounts and direct owner
company scope retain their existing contracts. Superseded/split payouts and any
previous bank association, including a removed association, require separate review.
No scheduler, auto-matcher threshold or learning behavior changes.

## Validation and deployment

Pure checks:

```sh
php test/test_uber_settlement.php
php test/test_uber_view.php
php test/test_worklist_view.php
php ../kreaeats/tests/bankintegration.php
```

`test/test_uber_settlement_database.php` uses only its guarded disposable
`127.0.0.1:33317/kreabank_auto_test` fixture with installed native source and an
isolated `kbuber_` table prefix. It must never run against an installation database.
It covers native transfer/association execution, rollback, duplicate/stale review
rejection, company isolation and Undo. No real transfer or payment is a test.

The UI reference is the existing KreaBank candidate renderer and reviewed native
payment form, together with installed Dolibarr 24's
`admin/tools/ui/components/buttons.php`. Reuse native titles, tables, feedback,
buttons and explicit confirmation; keep the same candidate presentation.

Deploy the compatible KreaEats bank-integration class first, then the complete
KreaBank change, including the service trait/settings helper, `ubersettlement.php`,
`admin/uber.php`, `lib/kreabank_uber_view.lib.php`, changed worklist/controller and
admin-navigation helpers, descriptor and both language files. No schema migration
is required. Existing `const` storage holds explicit mappings. Refreshing source
does not save mappings, enable jobs or perform any financial action.

Back up both module source sets before deployment. Roll back the complete compatible
source sets together; preserve existing native transfer legs, staging mappings,
KreaEats links and audits. Never delete financial records as source rollback.

## Source acceptance — 2026-11-18

- PHP 8.2.35: 63 exact-arithmetic/policy checks, 52 review/view checks and 54
  actual native database checks passed. The latter used disposable MariaDB 11.4
  on port 33317 and exercised failure rollback, company locking and retained Undo.
- All 76 non-database KreaBank PHP suites completed without failures and eight Node
  suites passed. Missing private parser fixtures and the optional protected-file
  baseline comparison skipped explicitly. Native
  History translation checks passed with the reviewed Uber label. KreaEats' new
  wrapper passed 46 checks against both the working checkout and pristine main
  with only the new integration overlaid; eight existing KreaEats suites passed.
- The actual review renderer was inspected with synthetic evidence and native
  Dolibarr 24 theme CSS in the internal browser at desktop and 390px width. There
  was no horizontal page overflow; unchecked confirmation prevented submission
  and checked synthetic approval was intercepted. No financial POST was sent.
- The local installation's dynamic theme endpoint returned an existing technical
  error; a temporary read-only copy of production's native theme CSS was used for
  synthetic visual verification. Authenticated installation/controller acceptance
  and production financial execution remain unverified. No deployment, mapping
  configuration or production reconciliation was performed.
