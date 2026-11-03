# AGENTS.md — KreaBank

## Purpose & Scope

- 2026-07-16: KreaBank imports bank statement data into module staging tables, proposes matches against Dolibarr bank, payment, and invoice records, and materializes or reuses native bank lines only during reconciliation.

## Architecture Decisions

- 2026-07-16: Keep one reconciliation path through `KreaBankService`, `KreaBankNativeBankAdapter`, and `KreaBankMatcher`; do not introduce a second matching or execution engine.
- 2026-07-16: Automatic safe matching requires an exact amount signal, a strong identity signal (`ref_payment`, `ref_exact`, `iban`, or `pattern_iban`), the configured score threshold, and at least a 35-point lead over the second valid candidate. Automatic batch approval and bulk/manual preselection must use `KreaBankService::getSafeSuggestion()`.
- 2026-11-01: Explicitly reviewed bulk selections use manual `bulk_manual` reconciliation, including weak suggestions approved by the user. Require CSRF, current write permissions, the review checkbox and a session-signed, unexpired exact displayed pair; recompute suggestions and reject changed pairs. Never relax unattended matching to implement manual approval.
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

- The read-only `KreaBankApi` in `class/api_kreabank.class.php` uses `/kreabankapi` and the shared `KreaBankIntegration` projection. Require both native bank and KreaBank read rights, deny external users, enforce direct current-company account ownership and bound SQL pages to 1–500. Never construct `KreaBankService` for reads: its initialization/helpers can create tables. See [REST integration](doc/API.md); `php test/test_api_database.php` uses only the disposable port 33317 fixture and checks installed Restler routes.
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

- The banking workspace keeps the native Dolibarr left menu visible. Use supported hooks and scoped CSS; do not patch core bank pages. Native account tabs are hidden, not extended with extra workspace tabs.
- `lib/kreabank_navigation.lib.php` owns account-context URLs, ownership checks and shared navigation. `account_context` is explicit per request, never session-global.
- Keep six operational banking tabs: movements, bank statement, statements, reconciliation, connections and history. The bank statement tab aggregates imported content; statements retains import list/detail. Accounts and account-tool pages are separate, without operational tabs; account references in the overview open movements, with a pencil icon beside each reference opening its native detail/tool page. Import is an action in statements. Transfers and cheque deposits use their existing native left-menu entries. Automatic reconciliation is accessible only through module configuration, never operational tabs or the left menu. Do not add a second standalone banking heading. Do not reintroduce a floating account-tools dropdown.
- Keep filters outside the navigation header: operational pages have only an owned-bank-account selector; the standalone accounts overview uses compact account-type view links (`kind`: `all`, `bank`, `cash`, `closed`). Do not carry `kind` into operational queries, forms, URLs or scans. The accounts overview ignores stale `account_context`; its type filter always stays within the active owner company. Closed-account reconciliation and feed eligibility remain unchanged. `php test/test_account_filters_database.php` uses only the same guarded disposable database on port 33317.
- Account context uses direct current-company ownership, not shared account access. List/count/scan filters must agree before pagination. The audit log is explicitly company-wide.
- `php test/test_navigation.php` loads installed Dolibarr source without bootstrapping or opening a database. Use `KREABANK_DOLIBARR_ROOT` if source is elsewhere.
- After deploying 1.14.4, refresh only KreaBank menu/hook/CSS registration; do not disable/re-enable the module or change scheduler settings. See `doc/NAVIGATION.md`.

## Bulk review performance

- `lib/kreabank_review_view.lib.php` renders prepared pairs only; it never loads
  candidates or executes records. Deploy `css/review-workspace.css` and
  `js/review-workspace.js` with the shared review controller and scan client.
  `test/test_review_workspace.php` and its Node counterpart use synthetic data.
- Keep progress separate from review presentation; unchanged results must preserve
  focus and expanded details. Individual matched review waits for stable sorted
  results before displaying a pair. Payment lookup indexes are explicit CLI-only
  online maintenance, never page-load DDL; see `doc/PERFORMANCE.md` and the guarded
  disposable `test/test_review_indexes_database.php` suite.
- Initial bulk review GETs do not run matching. Background POSTs retain native authorization and CSRF checks; `kreabankAdvanceBulkScan()` budgets work between individual lines, not whole chunks.
- Release the PHP session lock during read-only matching. Check session identity, generation and cursor before saving progress; never overwrite a newer company/account/page scan.
- Cache presentation only for the displayed page. Confirmation recomputes suggestions, authenticates the displayed pair and uses the same native reconciliation service. Manual approval rechecks source facts under locks and persists the audit atomically; it does not train automatic patterns.
- Keep staging/native ID namespaces explicit during reviewed lookup and persistence, including legacy import metadata. A native ID must never update an unrelated staged row with the same number.
- `php test/test_bulk_review.php` checks review authenticity and name evidence. `php test/test_bulk_review_database.php` extends ONLY the disposable automatic-reconciliation database on port 33317; it never opens an installation database.
- `php test/test_bulk_scan.php` and `node test/test_bulkmatch_client.cjs` exercise bounded progress and browser request behavior without business data. See `doc/BULK_REVIEW.md`.

## Connected bank views

- `KreaBankIntegration` is a read-only projection of staging evidence and native ledger links. Never materialize imported lines merely to view/export a statement. `statement.php` supports bank/module read permissions; importing retains write permissions.
- Statements list both staging and legacy native imports without double-counting mapped native lines. Native balance always comes from native bank movements, never staging totals.
- Native footer/list hooks provide the shared workspace header and an optional `kreabank_matched` filter. Keep native posting dates and owned-company boundaries. Flag historical missing/amount/status/reference discrepancies for review; never guess a replacement financial record.
- `php test/test_bank_integration_database.php` extends the automatic reconciliation suite on its same disposable local database; see `doc/BANK_INTEGRATION.md`.

- Company/account ownership is revalidated by `KreaBankReconciliationScope` for prepared sources and all execution targets, including parent invoices of payments. Entity sharing does not grant reconciliation authority.
- Bulk review must not choose an arbitrary best candidate when competing documents are fewer than 35 points apart. Keep those lines pending; explicit manual approval of a unique weak pair remains separate from automatic safe matching.
- `php test/test_company_isolation_database.php` extends ONLY the disposable local MariaDB fixture and covers these boundaries plus the equal-amount/date invoice tie.

- `accounts.php` is the company-owned banking entry point. Native account-list GETs route here for KreaBank readers. Balances use native operation dates; pending counts include imported evidence only. Never show a combined monetary total across currencies.
- The `leftblock` menu hook renames only the native account-list entry to Account list (pt_PT: Lista de contas), preserving its URL, permissions and position.
- Always preserve the native Dolibarr left menu and its responsive behavior. Hide only the duplicate native account tab bar within the banking workspace. Avoid explanatory banners and duplicate account navigation.

- Menu permission expressions run through native `verifCond()` and its function whitelist. Use supported property/permission checks; `empty()` is not allowed on the deployed Dolibarr 24 evaluator. `php test/test_navigation_permissions.php` evaluates the descriptor conditions with installed native source and no database.

## Restricted account pairs

- `KreaBankAccountPairs` stores explicit current-company restricted-to-main account mappings in `KREABANK_ACCOUNT_PAIRS`; configure at `admin/pairs.php`. Allow multiple restricted accounts per main, never chains/cycles or inferred name/suffix relationships.
- Restricted accounts accept only their own native transfer leg with a reciprocal, same-amount main-account counterpart. Apply this to suggestions, execution, quick flows and native transfer POSTs, including users without workspace read rights. Main accounts retain normal activity.
- The existing runner's optional `PAIRS_ENABLED` flag defaults off and requires both selected endpoints and native transfer permission. Saving pairs alone must never enable jobs. MacMiniServerPro jobs remain disabled.
- Paired execution remains in `KreaBankService`/native adapter/shared matcher. Require two mutually safe imported legs, exact opposite persisted amounts, matching currency, valid nearby dates, strong reference or mutual checksum-valid IBAN evidence, score floor 150 and gap 35. Fail closed on competing/truncated/conflicting evidence.
- This opt-in paired path may create both native transfer legs only when neither side has a possible native movement and currency is the company currency. Reuse valid reciprocal native transfers; never duplicate one-sided/conflicting ledger evidence. The ordinary automatic-native path still creates no movements.
- Both legs, explicit staging/native mappings and `auto_pair` audits commit atomically under account/staging/native range locks. Use explicit `VIR`; do not train patterns. Test only with `php test/test_account_pairs_database.php` on the guarded disposable port 33317 database. See `doc/ACCOUNT_PAIRS.md`.

## Amount display

- Use `kreabankPrice()` for displayed amounts, balances, totals and percentages;
  it retains native language separators and enforces exactly two decimals.
- Connected native bank pages cap `MAIN_MAX_DECIMALS_SHOWN` for the current request
  only. Never change unit/total calculation precision or round persisted evidence
  to implement a display preference. Numeric amount inputs use two decimals with
  a dot separator; hidden reconciliation values retain their source precision.
- `php test/test_amount_display.php` exercises installed native formatting without
  credentials, application bootstrap or database access.

## List sorting

- Combined statement header filters and native pagination are owned by
  `bankstatement.php` and `lib/kreabank_statement_list.lib.php`. Apply identical
  filters to counts and rows after the complete scoped balance projection; never
  filter the balance anchor inputs. Preserve filters/account/size across native
  paging and sorting. See `doc/LISTS.md` and the guarded disposable
  `test/test_bank_statement_filters_database.php` suite.
- `lib/kreabank_list.lib.php` owns allowlisted sort state and read-only sort links. Keep `kind` exclusive to the accounts overview; never carry actions, CSRF tokens or selections into sort URLs.
- The same helper owns bounded native pagination state for accounts, statements,
  connections and the feed company summary. Sort complete scoped results before
  slicing; retain account/reference/view context in native paging forms and URLs.
- Sort SQL/complete scoped arrays before pagination. History and audit have independent sort state and a fixed 5,000-row recent window; changing the window with the page causes duplicates/skips under other orders.
- Bulk sorting orders compact results, preserves the scan cursor/budget and binds the presentation generation to the sort order. Rehydrate only the visible page; confirmation still recomputes and authenticates the reviewed pair.
- `data-kb-sort` is only for fully rendered tables; never apply it to paginated lists or grouped detail rows. Preserve form controls by moving rows, and use raw sort attributes for localized numbers/dates. See `doc/LISTS.md` and its pure/disposable-database checks.

## TPA settlement receipts

- The existing automatic runner has an opt-in company `TPA_ENABLED` flag, default off. Recognize complete FECHO TPA terminal/period or merchant/terminal/batch identities through `KreaBankMatcher::tpaSettlementIdentity()`. The word TPA alone never authorizes a receipt. Preserve printed leading zeroes (distinct settlement streams) and scope staged duplicate identities to the booking day; counters can recur on later dates.
- `KreaBankService` checks persisted positive company-currency evidence, current owner account selection, duplicates, feed conflicts, restricted accounts, possible native entries and unbanked customer payments before creating a native receipt with the explicitly selected active native `TPA_PAYMENT_MODE` through the shared adapter. Missing, inactive or foreign-scope payment modes fail closed. This creates no sales invoice or invoice payment.
- Account/staging/native range locks and the shared finalizer commit receipt, source mapping and `auto_tpa` audit together; no pattern training. Preserve original evidence. Existing safe native matching remains unchanged.
- `php test/test_tpa_receipts.php` is pure. `php test/test_tpa_receipts_database.php` extends ONLY the guarded disposable port 33317 fixture. See `doc/TPA_RECEIPTS.md`; test-installation jobs remain disabled.

- Account overview feed snapshots are opt-in on `KreaBankIntegration::accounts()`. Keep native `balance` and the REST contract unchanged; `feed_balance` uses current workspace/company/account/currency/IBAN, with bank refresh and balance collection timestamps. Validate with `test/test_account_feed_database.php` on the guarded disposable database.

## Payment account review

- `KreaBankService::getPaymentAccountReview()` accepts explicit staging IDs and
  checks current-company invoice payments in other owned accounts. It returns
  review evidence only; never feed these records into cross-account execution.
- Exact signed persisted amount/currency, nearby booking dates and a complete
  payment/check reference are required. Suspected errors block automatic-native
  and TPA execution and bulk approval. Review/correction uses the existing native
  bank movement form; preserve payment allocations, accounting and reconciliation
  locks. Recompute warnings after correction, never cache approval of a move.
- `test/test_payment_account_review.php` is pure; its database counterpart extends
  only the guarded disposable port 33317 fixture. No schema or scheduler changes.

- Statement-view account selectors list only current-company owned accounts with staged or recognizable legacy imported statements. Show only account references in statement selectors and account columns; keep the other operational account selectors unchanged.

- Combined-statement balances prefer supplied line balances; the read-only `bankStatementWithBalancesSql()` fallback calculates backwards from a completed, coherent feed snapshot with exact DECIMAL subtraction. Keep calculation provenance in the balance tooltip without a visible Calculated label, preserve same-day statement order across sorting/pages, and fail closed on source/mapping/conflict/coverage errors. Never persist derived balances or use the native ledger as the anchor. Validate with `test/test_statement_balances_database.php` on the guarded disposable database.

- `matched.php` reuses the bulk review controller with one pair per page, a separate
  scan cache and the same signed manual approval. Exclude unmatched lines and
  wrong-account warnings; never relax matching or unattended approval rules.

- In individual matched review, clicking **Reconcile this match** is the explicit
  approval of the sole signed pair. Do not require either selection or review
  checkboxes there. Bulk selections retain their separate review checkbox.

## Machine learning

- `KreaBankService` owns batch/supplier learning; `KreaBankParser` owns header
  mapping. Learning remains advisory and never changes the shared safe matcher.
- Batch schema version 2 stores normalized features exactly once and an explicit
  native `bank_line_id`. Load only current-company, still-reconciled sources and
  the newest sample per movement. Preserve unversioned data but exclude it from
  training: its repeatedly scaled values cannot be reconstructed reliably.
- Learn only from `manual`/`drag_drop` pre-mutation snapshots. Automatic, bulk and
  quick-entry results do not create training truth. Multi-link batches do not
  train recurring single-document patterns.
- Keep validation chronological; remove duplicate feature groups from training
  when they occur in holdout. Balance only training, evaluate the production
  confidence gate, and report prediction errors instead of successful metrics.
- Supplier confidence needs three identity-supported historical outflows, matching
  company ownership and no conflicting IBAN. Header samples stay company-local;
  never load the unowned shared legacy file. Save templates under a merge lock and
  atomic replacement; model caches permit only the bundled classifier classes.
- `php test/test_machine_learning.php` covers the real bundled PHP-ML classifiers,
  temporary private files and concurrent writers, with installed Dolibarr source
  but no application bootstrap/database. Its database counterpart uses ONLY the
  guarded disposable localhost:33317 fixture. See [learning](doc/MACHINE_LEARNING.md).

## Selected-line reconciliation workspace

- `reconcile.php` keeps the existing controller and shared service calls. Scoped
  presentation assets are `css/reconcile.css`, `js/reconcile.js` and
  `js/reconcile-supplier.js`; include all three when deploying this workspace.
- Pending search filters the bounded loaded queue (up to 200 lines); the full
  pending view remains accessible. Mobile starts with the queue collapsed.
  Matching and creation have separate panels and explicitly labelled actions.
- Allocation feedback sums original hidden decimal strings; two-decimal display
  must never rewrite POST amounts. The service remains authoritative for amount,
  company, permission, lock, duplicate and safe-match validation.
- Creation choices must retain explicit submit actions, including implicit Enter
  submission. Supplier invoices require product-line review; keep dialog focus
  containment, Escape and return focus. Failed creation retains reviewed inputs.
- `php test/test_reconcile_workspace.php` renders the actual view without an ERP
  bootstrap or database. Its `--html <case> [locale]` output intercepts every
  submission for browser checks. `node test/test_reconcile_workspace.cjs` checks
  exact allocation boundaries. See `doc/RECONCILIATION_UI.md`.
