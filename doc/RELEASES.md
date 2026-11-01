# Changelog

## 1.14.11 — 2026-11-01

- Reconcile explicitly reviewed bulk selections through the native service instead
  of skipping them for automatic-confidence rules. Bind approval to the exact
  displayed pairs, reject changed/expired reviews and show the outcome after redirect.
- Count complete payee names found in bank descriptions and explain scores as points.
  Preserve strict unattended matching, company/account boundaries, replay protection,
  transaction rollback and atomic manual audits, including legacy/native ID collisions.
- No schema or scheduler change. Refresh open bulk forms before approving. See
  [bulk review](BULK_REVIEW.md).

## 1.14.10 — 2026-11-01

- Invalidate cached shared bank styles so the integration panel and links render
  consistently on native and KreaBank pages.

## 1.14.9 — 2026-11-01

- Translate reconciliation states in the connected statement view and avoid
  repeating the native matched filter in generated sort/pagination links.

## 1.14.8 — 2026-11-01

- Connect imported statements, native account balances, statement reports and bank
  movements with one read-only view and account-aware links on both workspaces.
- Show staged and legacy imports together; expose missing, mismatched and
  unconfirmed native links instead of presenting them as verified matches.
- Opening or exporting a statement no longer creates native bank movements.
- Add an optional KreaBank reconciliation filter to the native movements list,
  preserving native posting dates and company ownership. No schema, scheduler
  or business-data migration. See [bank integration](BANK_INTEGRATION.md).

## 1.14.7 — 2026-11-01

- Render background status translations as plain text and hide inactive controls
  consistently with native button styles.

## 1.14.6 — 2026-11-01

- Open bulk review immediately and check suggestions in bounded background requests,
  with per-line budget checks and a current-page presentation cache.
- Release the session lock during matching so navigation stays available; retain
  selections, pause while reviewing, and reject stale concurrent results.
- Preserve confirmation-time matching, permissions and safe-match rules. No schema
  change or scheduler configuration change. See [bulk review](BULK_REVIEW.md).

## 1.14.5 — 2026-11-01

- Render the shared native tab bar without an extra content wrapper or duplicate
  container ID. Keep legacy batch account links aligned with the account selector.

## 1.14.4 — 2026-11-01

- Connect native bank account tabs and KreaBank through a shared account context.
- Group the workspace into reconciliation, import, connections and history; keep
  batch review and pending lines inside reconciliation. Clearly label native
  statement numbering and preserve all native account pages.
- Apply account filters to pending lists, batch scans and reconciliation history;
  retain context through forms and pagination. Company audit events remain
  explicitly company-wide. No schema change. See [navigation](NAVIGATION.md).

## 1.14.3 — 2026-11-01

- Normalize the company ID supplied by native scheduled jobs before checking
  reconciliation permissions. Preserve rejection of foreign and global companies.
- Cover native fetched job IDs in the automatic reconciliation regression suite.
  No schema change.

## 1.14.2 — 2026-11-01

- Add an optional isolated Compose worker that selects only the native Fintable
  job, keeps scheduler credentials in memory and stops active jobs gracefully.
- Document read-only collection in secondary installations without enabling
  other cloned scheduled jobs or automatic reconciliation. No schema change.

## 1.14.1 — 2026-11-01

- Parse bank matching dates in a fixed timezone so server timezone differences
  cannot shorten the calendar-day tolerance at midnight.

## 1.14.0 — 2026-11-01

- Add company-scoped automatic matching against existing native bank movements,
  with an explicit account selection, disabled default, previews and native scheduling.
- Revalidate identity, amount, currency, date, ownership and ambiguity under locks;
  retain duplicate, skipped and conflicting source lines for review.
- Persist automatic reconciliation and its audit atomically, without creating
  invoices, payments or new bank movements. See [operations](AUTOMATIC_RECONCILIATION.md).

## 1.13.4 — 2026-11-01

- Distinguish a provider outage from expired bank authorization.
- Show eligible bank accounts by default; keep other bank records available through a view toggle.

## 1.13.3 — 2026-11-01

- Collect finished banks independently while other banks refresh, with bounded waits and per-account status.

## 1.13.2 — 2026-11-01

- Check native bank ownership directly when Account::fetch() omits its entity.
- Decode native encrypted refresh settings while retaining protected token storage.
- Initialize Dolibarr translations in the CLI installer and collector (1.13.1).

## 1.13.0 — 2026-11-01

- Add Fintable API collection, twice-daily timezone-aware native scheduling,
  encrypted credentials and company-scoped balances/status pages.
- Route feed movements through existing statement staging with stable source
  identities, atomic retries, pagination, incremental overlap and correction review.
- Require unique IBAN/currency ownership and reject imports into another entity.
- Keep structured feed amounts out of file-mapping repair heuristics.

Earlier release notes remain in the existing maintainer changelog.
