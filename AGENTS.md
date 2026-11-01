# AGENTS.md — KreaBank

## Purpose & Scope

- 2026-07-16: KreaBank imports bank statement data into module staging tables, proposes matches against Dolibarr bank, payment, and invoice records, and materializes or reuses native bank lines only during reconciliation.

## Architecture Decisions

- 2026-07-16: Keep one reconciliation path through `KreaBankService`, `KreaBankNativeBankAdapter`, and `KreaBankMatcher`; do not introduce a second matching or execution engine.
- 2026-07-16: Automatic safe matching requires an exact amount signal, a strong identity signal (`ref_payment`, `ref_exact`, `iban`, or `pattern_iban`), the configured score threshold, and at least a 35-point lead over the second valid candidate. Batch approval, bulk matching, and manual preselection must use `KreaBankService::getSafeSuggestion()`.
- 2026-07-16: Customer and supplier credit notes are excluded from normal invoice candidates. Supporting refunds requires dedicated reverse-direction document types and payment semantics; credit notes must not be treated as standard positive invoice payments.
- 2026-07-16: The manual reconciliation controller intentionally accepts only `native_bank` checkbox and drag/drop assignments. Payments and invoices remain supported through bulk, suggestion, and quick-entry service workflows.

## Database & Schema Notes

- 2026-07-16: `llx_facture` and `llx_facture_fourn` are entity-scoped. Candidate queries must filter their `entity` column explicitly.
- 2026-07-16: `llx_paiement_facture` and `llx_paiementfourn_facturefourn` have no independent entity scope; reconciliation scopes them through the current-entity parent invoice.
- 2026-07-16: `llx_societe_remise_except` is entity-scoped. Remaining-balance queries filter both the discount row and its source invoice by the current entity.
- 2026-07-16: Invoice candidate `amount_open` is `total_ttc` minus allocated payment rows and applied deposit/credit-note rows. Execution revalidates with Dolibarr `CommonInvoice::getRemainToPay()`.
- 2026-07-16: Only validated invoices (`fk_statut = 1`) are eligible for existing-invoice matching; closed, paid, abandoned, and draft invoices are rejected again under the execution-time lock.
- 2026-07-16: Version 1.12.54 changes no database schema and requires no migration.

## Integration Points

- 2026-07-16: Existing-invoice reconciliation creates native `Paiement` or `PaiementFourn` objects, requests Dolibarr native paid-invoice lifecycle handling with `closepaidinvoices=1`, and attaches the payment to the selected native bank line with `update_fk_bank()`.

## Multicompany Notes

- 2026-07-16: Invoice candidate reads, execution-time row locks, and applied-credit lookups must remain restricted to `$conf->entity`; link-table rows must never be trusted as an entity boundary by themselves.

## Known Pitfalls & Gotchas

- 2026-07-16: Never use invoice `total_ttc` directly as the matchable open amount; partial payments and applied deposits or credit notes make it stale.
- 2026-07-16: Never force a supplier invoice to paid after payment creation. Dolibarr closes it only when the native remaining balance reaches zero or an authorized payment hook handles a valid partial-close case.
- 2026-07-16: Exact amount plus date scores 100 but is not a safe automatic match without a strong identity signal and sufficient separation from competing candidates.
- 2026-07-16: `test/test_format_consistency.php` and `test/test_parser_golden.php` depend on bank-format fixtures that are absent from the current local checkout.

## Environment & Configuration

- 2026-07-16: The module descriptor declares Dolibarr 19 minimum compatibility. Remaining-balance SQL was runtime-validated on the local `lamp-php81` container with the `mysqli` driver.

## Technical Debt / Open Issues

- 2026-07-16: Implement dedicated customer-credit-note and supplier-credit-note refund matching with explicit reversed directions before exposing those documents as candidates.
- 2026-07-16: Define and enforce a complete multicurrency reconciliation contract before allowing automatic foreign-currency invoice payments; current invoice payment creation is primarily company-currency oriented.
- 2026-07-16: Replace the unconditional `VIR`/`CHQ` payment-mode fallback with an explicit configured or user-confirmed payment mode, or a separately tested bank-description classifier, before relying on generated payment method metadata for accounting reports.

## Conventions

- 2026-07-16: Log every production-relevant change in `ChangeLog.md`.
- 2026-07-16: Keep each change note short and direct.
- 2026-07-16: After each update, bump `$this->version` in `core/modules/modKreaBank.class.php`.
- 2026-11-01: Track sanitized maintainer instructions under the current repository policy. Keep the legacy private `ChangeLog.md` ignored; public release notes are in `doc/RELEASES.md`.

## Fintable feed

- Source: `KreaBankFintableClient`, `KreaBankFintableMapper`, `KreaBankFintableSync`; operational page `feed.php`, global settings `admin/fintable.php`.
- Use `KreaBankService::importStatementFromParsed()` with format `fintable`. Feed source identities must survive retries, and structured amounts must bypass file-mapping repairs on both write and read paths.
- One global native Cronjob checks two timezone-aware slots daily. A database lock prevents overlap. Never install another host scheduler or turn on legacy feed jobs.
- Match only a unique checksum-valid IBAN to an open bank account in an active owner entity with KreaBank enabled; currencies must match. Do not infer ownership from names or suffixes.
- Keep the API token encrypted in the native credential configuration. Never embed credentials, company records or production mapping IDs in this public repository.
- `php test/test_fintable.php` runs pure API contract checks. `php test/test_fintable_database.php` requires disposable local MariaDB on port 33316; never point it at a real installation. See `doc/FINTABLE.md` for operational deployment and rollback.
- `deploy/fintable/` is an optional container adapter for installations with no running general scheduler. It invokes only the exact global Fintable native job; never run it alongside an existing scheduler for that job. Preserve its SIGTERM override and graceful job shutdown when using an Apache-based image.

## Automatic existing-movement matching

- `KreaBankAutoReconcile` schedules bounded, company-specific runs; the only matcher/executor remains `KreaBankService` and its native adapter. Configure at `admin/automation.php`; defaults to off with no accounts selected.
- Native `Cronjob::run_jobs()` overwrites the scheduled object's `entity` with its fetched database value, which may be a string. Normalize it before comparing company scope; never remove the ownership check.
- `getAutomaticNativeSuggestion()` and `reconcileAutomaticNativeLine()` accept explicit staging IDs. Never resolve a native candidate through the mixed staging/native ID lookup.
- Unattended matching requires the same signed persisted amount, currency and account, a date within tolerance, the shared safe suggestion (score floor 150 and gap 35), and a sufficiently strong reference/IBAN. Fail closed on truncated candidate sets or competing staged claims.
- Native/staged row locks, a company runner lock and an atomic reconciliation audit protect repeated/concurrent execution. Automation creates no payment, invoice or new native movement, and does not train learned patterns.
- `php test/test_automatic_reconciliation_database.php` uses ONLY disposable local MariaDB on port 33317/database `kreabank_auto_test` and installed Dolibarr source (`KREABANK_DOLIBARR_ROOT` if needed); never run it against an installation database. See `doc/AUTOMATIC_RECONCILIATION.md`.

- Bank matching date strings use explicit UTC parsing; do not let the PHP server timezone alter calendar-day tolerance. Runtime regression checks cover UTC, Lisbon, Zurich and New York.

## Account navigation

- Native bank tabs are extended with `completeTabsHead`; do not patch core bank pages.
- `lib/kreabank_navigation.lib.php` owns account-context URLs, ownership checks and shared navigation. `account_context` is explicit per request, never session-global.
- Keep four main tasks (reconcile, import, feed, history); batch and pending are reconciliation tools. Preserve native statement numbering and other modules' tabs.
- Account context uses direct current-company ownership, not shared account access. List/count/scan filters must agree before pagination. The audit log is explicitly company-wide.
- `php test/test_navigation.php` loads installed Dolibarr source without bootstrapping or opening a database. Use `KREABANK_DOLIBARR_ROOT` if source is elsewhere.
- After deploying 1.14.4, refresh only KreaBank menu/hook/CSS registration; do not disable/re-enable the module or change scheduler settings. See `doc/NAVIGATION.md`.

## Bulk review performance

- Initial bulk review GETs do not run matching. Background POSTs retain native authorization and CSRF checks; `kreabankAdvanceBulkScan()` budgets work between individual lines, not whole chunks.
- Release the PHP session lock during read-only matching. Check session identity, generation and cursor before saving progress; never overwrite a newer company/account/page scan.
- Cache presentation only for the displayed page. Confirmation always recomputes the existing shared safe suggestion and uses the native reconciliation service.
- `php test/test_bulk_scan.php` and `node test/test_bulkmatch_client.cjs` exercise bounded progress and browser request behavior without business data. See `doc/BULK_REVIEW.md`.
