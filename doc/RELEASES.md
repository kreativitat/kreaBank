# Changelog

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
