# Reconciliation list — production deployment, 2026-11-14

Deployed KreaBank **1.20.2** to `fin.degema.pt`, with runtime source
[`06d302b`](https://github.com/kreativitat/kreaBank/commit/06d302b26435e94ba8055890de4e1ab9db7fa127).
This includes the separate reconciliation list from 1.20.0 and navigation from
1.20.1. The later 1.20.3 navigation follow-up was not part of this deployment.

## Deployment and migration

- Production: Dolibarr 24.0.2, 64-bit PHP 8.1.34. Deployed from committed source,
  preserving unrelated checkout/index edits and production-specific differences
  in the existing reconciliation controller and original language resources.
- Verified and backed up target preimages, syntax-checked PHP on production,
  staged replacements, and installed 28 runtime/schema/helper paths under a
  deployment lock. The guarded source operation preserved its immediate
  configuration, scheduler and financial snapshots.
- Applied the additive, rerunnable payment-batch migration through the native
  CLI administrator check. Verified all three InnoDB tables, exact DECIMAL(24,8)
  columns, complete column sets and four unique membership/claim/draft indexes.
- Refreshed managed menus and hook/CSS registration for 15 already-enabled
  companies, with the native helper's private registration snapshot. No module
  reactivation, rights change, scheduler change or service restart was performed.
- Live checking found native theme issues absent from the initial fixture:
  table-cell intrinsic width expanded the page, and native button specificity
  overrode compact icon dimensions. A four-file guarded follow-up deployed the
  1.20.2 correction and native sort-parameter cleanup. Final readback verified
  all 28 deployed path hashes against the committed artifact.

## Verification

- Authenticated production checks used the internal browser and Google sign-in.
  The new translated menu and all-account list loaded successfully with distinct
  statement and payment bank columns and native header controls.
- Exercised header filtering, native pagination, stable page boundaries without
  overlapping rows, and complete amount sorting. Sort links retained filters and
  contained no orphaned underscore fragments. A foreign-company account filter
  returned no rows.
- Opened the change-bank modal from explicit cross-account payment search.
  Verified source/destination banks and payment evidence; Cancel and Escape
  closed it. The account correction was not submitted.
- Selected an existing multi-payment salary group. The exact total enabled
  review; removing a member disabled it. Saved one temporary private draft and
  opened the signed, read-only group review. No reconciliation or undo was
  submitted. Preserved the prior draft state, removed only the exact test draft,
  and verified unchanged statement/member hashes, native bank/salary aggregates
  and reconciliation audit around that test. No test draft or active group
  remained afterward.
- Desktop and 390px checks found no page-wide overflow. Wide tables scroll
  within their own wrapper; native pagination remains in the page, and icon
  actions compute to 30px. Browser error logs were empty. Screenshots remain in
  the private deployment receipt, outside this public repository.
- Production PHP passed 67 native translation/render/sort checks and 33 pure
  batch checks without application bootstrap or database access. Local controller,
  view and permission checks passed, together with 63 Chromium interactions and
  26 exact-allocation checks. Financial execution/rollback/concurrency coverage
  used the previously validated disposable database suites, never live records.

This was a running installation. Later global customer-invoice/payment/bank
counts advanced; whole-database immutability is not claimed. The immediate
deployment guards and the targeted before/after batch checks are the evidence
for this operation's financial boundaries.

## Rollback

Private source preimages, manifests, original absent-path lists, migration/menu
receipts and verification evidence are retained. The initial source rollback is:

`/home/degemapt/backups/kreabank-worklist-20261114T175129Z/rollback.tar.gz`

The 1.20.2 presentation/sort follow-up is:

`/home/degemapt/backups/kreabank-worklist-native-20261114T181040Z/rollback.tar.gz`

The registration snapshot is under the initial receipt's
`navigation/navigation-registration.json`. Restore source preimages only after
checking deployed hashes for concurrent edits. A presentation-only rollback to
1.20.1 retains group-aware services. Before restoring scalar-only code, inspect
active batch mappings and follow the coordinated rollback rules in
[the feature contract](RECONCILIATION_LIST.md#installation-and-rollback).
Retain the additive tables and their history; do not drop them as routine cleanup.
