# Changelog

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
