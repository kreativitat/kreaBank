# AGENTS.md — KreaBank

## Reviewed Uber settlements

- `KreaBankUberSettlementTrait` connects complete KreaEats payout evidence to a
  positive imported EUR credit through an explicitly configured clearing account.
  Keep the existing service/native lifecycle, exact cents, owned accounts, signed
  review, duplicate guards and one atomic bank/KreaEats transaction. Never feed
  payout advice to automatic matching or create invoice payments/adjustment plugs.
- `admin/uber.php` stores only current-company store/account mappings. Cash
  clearing accounts use native `LIQ` transfer semantics. Bank Undo retains the
  transfer and KreaEats association; document association is a separate status.
  See [Uber settlements](doc/UBER_SETTLEMENTS.md) for dependencies and disposable
  port 33317 checks. Deployment configures no mappings and executes no settlement.

## Opening reconciliation

- `admin/opening.php` is the setup-only, explicit preview/confirmation flow for
  `KreaBankOpeningBalance`. Storage installation is POST-only. One immutable
  company/account opening records the official previous-day booked balance and
  audit; native reconciliation plus an optional exact `SOLD` difference commit
  atomically. Preserve original staged statuses/links and existing native matches.
- Historical acceptance is the separate `opening_reconciled` projection; never
  forge a native link or assign normal matched status. Pending counts, statement
  filters, worklist, History and monthly reports must agree. Recheck the boundary
  under ordinary execution locks; protect it from scalar/batch Undo and staged
  or legacy statement deletion. Start-date transactions remain ordinary work.
- See [opening reconciliation](doc/OPENING_RECONCILIATION.md). Its database suites
  use only the guarded disposable port 33317 fixture; deployment never applies an
  opening or enables jobs.

## Continuous integration

- 2026-11-15: GitHub Actions runs every non-database PHP test and Node CJS suite with Dolibarr 24 source on PHP 8.1/8.3. Missing private parser fixtures skip explicitly; database suites remain restricted to their documented disposable fixtures.

## Native request filters

- 1.20.53: History Undo nonce input uses native `GETPOST(..., 'aZ09')`. Dolibarr 24
  does not support `alphanum`; its fallback can discard the nonce. Keep the native
  request-helper regression alongside synthetic controller checks.

## Unified existing-movement selector

- 1.20.51: The list has no standalone Search row action/panel. The layers selector
  supports one or more same-day existing movements. A singleton uses a signed
  `selected_native` review through `reconcileLine()`, never a one-member batch.
  `KreaBankWorklistSelectionTrait` signs/rechecks exact source/target and linked
  facts under existing locks; scalar completion clears only that user's stale
  staged draft. Groups retain all existing batch invariants. Competing singles
  and exact groups leave advisory selection manual. Other-account correction
  belongs only in the row's payment-bank column, never in selector allocation.
- 1.20.52: The selector accepts staged sources only; legacy imports already occupy
  native ledger rows and need duplicate review before choosing another row. Legacy
  workspace search is unchanged.
- 1.20.62: The selector has no text-search or editable batch-name field. Keep
  Save draft separate from Review selection and final approval, with native
  descriptive tooltips. The list defaults to 10 rows; explicit limits persist.

## Advisory bank rules

- 2026-11-15: Rule management uses permission `15656004`. Preserve historical KreaBank rights and legacy KreaTaxMatch `156564`; check definitions and orphan user/group grants before registering a newly allocated right.
- 2026-11-15: `KreaBankRule` and `KreaBankRuleServiceTrait` add selected-line advice only. Sign the exact rule/source/target/conflict snapshots, recheck them under existing reconciliation locks, and commit usage with the audit. Never pass rule evidence to unattended or bulk matching. New rules are inactive; schema installation is explicit and idempotent. Accounting/VAT quick-entry values are review hints by user choice. See [bank rules](doc/BANK_RULES.md).

## Reviewed invoice differences

- 2026-11-15: `KreaBankWriteOff` and its service/native traits extend only signed selected-line `manual_writeoff` approvals through `reconcileLine()`. Lock company-local configuration and exact source/invoice/payment facts; require both limits and active reason accounts. Customer closure uses native codes; never force supplier residuals paid. Audit and undo preserve the native bank evidence and reverse only the created payment. Never feed this action to the matcher or learning. See [payment differences](doc/WRITE_OFF.md).

## Separate reconciliation list

- Recorded customer bank-transfer enrichment is owned by `KreaBankCustomerTransfer`.
  Accept native mode codes `TRF`/`VIR`, not numeric IDs or translated labels.
  Keep one existing receipt/native target across its complete owned invoice
  allocations, require exact decimal evidence, and sign/recheck the receipt facts.
  This creates no customer payment or allocation. Enrichment is an explicit
  manual-review opt-in; unattended scans keep their original candidate evidence.
  Other payment methods and the shared safe matcher retain their contracts.
- Existing unpaid salary/tax payment advice is list-only in
  `KreaBankPlannedPayment`; never add salaries to the unattended/bulk matcher.
  `recordpayment.php` creates only native payments for existing parents under
  owned source/account/parent/payment locks and a single-use displayed context.
  Keep reconciliation separate, preserve salary employee visibility, and block
  possible duplicate/unbanked payments. The native standalone salary/tax forms
  do not provide this locking contract. See [list](doc/RECONCILIATION_LIST.md).

- 1.20.50: New pending batches use only the source booking day, including retained
  selections and locked execution. `KreaBankPaymentBatchSuggestion` preselects
  only a unique exact signed-decimal subset from complete unsearched candidates;
  bounded/ambiguous results remain manual. Preserve saved inputs and historical
  active groups/undo. GET advice performs no financial mutation.
- Native payment-dialog cancellation preserves the list and scan state. Only an
  unprevented native payment POST attempt requests a one-row refresh; consume
  `refresh_payment` into
  the canonical paused `payment_return` view. Never invalidate the full scan on
  every GET or restart analysis from the modal close callback.
- Native popup handlers create the iframe after capture listeners: install its
  watcher in the next task, not a capture microtask. Native invoice Cancel is a
  submit input; intercept it before required-field validation and close only its
  exact iframe-owned dialog. Evaluate final submit-event prevention at close so
  invalid attempts cannot erase a genuine attempt or trigger a spurious refresh.

- 1.20.49 removes the row More options panel and its POST actions. Skip line,
  quick tax creation and quick supplier invoice creation are retired everywhere;
  reject stale actions rather than mapping them to ordinary movement creation.
  Preserve historical records. Remaining draft advice/validation uses
  `KreaBankDraftInvoiceReview` with exact persisted amounts and complete identity;
  date-only or amount-only evidence never qualifies.

- `reconciliationlist.php` is additive; keep `reconcile.php` and its assets unchanged
  when maintaining this feature. Use native header filters/pagination, explicit
  staged/native IDs, signed row reviews and the existing matcher/service.
- Payment batches group existing native movements through the shared service
  trait and normalized mappings. Preserve exact signed amounts, all components,
  atomic audit/undo, private drafts and claim guards. Never create a duplicate
  aggregate movement. Read projections/monthly reports must verify every member.
- The third operational tab, **Reconcile**, opens this list, using `search_account` for an owned selected bank. Keep the header button and removed sidebar shortcut absent.
- The new list defaults to newest booking date first; explicit sort choices persist. Keep the legacy selected-line queue oldest-first.
- Automatic matching is limited to native page-size targets; **More** validates the current session generation/cursor and grants one bounded next batch.
- Action returns retain unchanged scan summaries. Prepared display envelopes
  stay on one native page for a fixed 120 seconds and one authorized continuation;
  bind current actor/company/filter/rights/employee scope and recheck target
  visibility. Invalidate changed/shared targets and amount/date peers, fence old
  workers, and always bypass presentation caching for financial confirmation.
- See [the reconciliation list](doc/RECONCILIATION_LIST.md) for migration,
  rollback and strictly disposable database checks. No GET-time batch DDL or jobs.

## Monthly reports

- `balances` is the first/default view: January-to-selected-month bank/Dolibarr
  closes and raw bank-minus-Dolibarr differences, then cumulative pending bank
  movements. Reuse one owned evidence load; preserve each month's cutoff/opening
  and global claims. Missing evidence remains unknown. Comments are not saved;
  the workbook-style CSV and print contain the two complete sections. See
  `lib/kreabank_monthly_balances.lib.php` and `test/test_monthly_balances.php`.
- `monthlyreport.php` and `KreaBankMonthlyReport` are read-only current-evidence
  reports, not a second matcher or historical close/approval system. Keep exact
  owned-account amounts, both booking-date cutoffs, carried open items and explicit
  unknown/provisional bank balances. Never use the native ledger as a bank anchor.
- Monthly reports have their own active tab before History. Keep historical report
  figures separate from the shared current-balance panel; use the compact toolbar
  and preserve exact account/month balance bindings through views and exports.
- See [monthly reporting](doc/MONTHLY_REPORTS.md) for the report contract, UI
  reference, guarded disposable database suite and deployment file set.

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
- 2026-07-16: Only validated invoices (`fk_statut = 1`) are eligible for existing-invoice matching and payment execution. Selected-line draft cards use a separate, confirmed native validation action; drafts never enter the matcher or bulk approval. Closed, paid and abandoned invoices remain excluded.
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
- One global native Cronjob checks one timezone-aware slot daily by default (existing two-slot schedules remain supported). A database lock prevents overlap. Never install another host scheduler or turn on legacy feed jobs.
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

- 1.20.66: Native `bankentries_list.php` uses only its table filters. Suppress the shared movement status switch and account filter panel through the header's `showFilters` flag; retain the banking header, scoped balances, direct filtered links, entry-detail controls and statement numbering.
- The banking workspace keeps the native Dolibarr left menu visible. Use supported hooks and scoped CSS; do not patch core bank pages. Native account tabs are hidden, not extended with extra workspace tabs.
- `lib/kreabank_navigation.lib.php` owns account-context URLs, ownership checks and shared navigation. `account_context` is explicit per request, never session-global.
- Keep six operational banking tabs in this order: bank statement, statements, the new reconciliation list, monthly reports, movements and history. Movements also remains in the native left menu directly below New financial account as a standalone global list without operational tabs. Its editable account/search_account filters never become workspace context and retain ba.ref. Only fixed native id/ref or explicit account_context select the workspace Movements view, which retains six tabs and removes ba.ref through completeArrayFields without rewriting user preferences. Validate ownership for both fixed context and editable account filters. The Reconcile tab opens `reconciliationlist.php`; retain the old `reconcile.php` controllers, assets and direct routes as dormant legacy tools. Connections retains its direct route without an operational tab. The bank statement tab aggregates imported content; statements retains import list/detail. Accounts and account-tool pages are separate, without operational tabs; bank references and pending counts in the overview open the account-scoped reconciliation list (cash accounts retain native Movements), with a pencil icon in the right-aligned Actions column opening its native detail/tool page. Import is an action in statements. Transfers and cheque deposits use their existing native left-menu entries. Automatic reconciliation is accessible only through module configuration, never operational tabs or the left menu. Do not add a second standalone banking heading. Do not reintroduce a floating account-tools dropdown.
- Keep filters outside the navigation header: unscoped operational pages have only an owned-bank-account selector; selected-account pages show its reference and label beside Banking instead of the company name, with no account switch; combined views show All accounts; the standalone accounts overview uses compact account-type view links (`kind`: `all`, `bank`, `cash`, `closed`). Do not carry `kind` into operational queries, forms, URLs or scans. The accounts overview ignores stale `account_context`; its type filter always stays within the active owner company. Closed-account reconciliation and feed eligibility remain unchanged. `php test/test_account_filters_database.php` uses only the same guarded disposable database on port 33317.
- Account context uses direct current-company ownership, not shared account access. List/count/scan filters must agree before pagination. The audit log is explicitly company-wide and loads/renders only in all-account History.
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
- For internal workspace readers, the `leftblock` hook retains the native Entry list as Movements directly below New financial account, preserving its URL, permissions and enabled state. It removes the level-one Account list and old Reconcile links plus the separate reconciliation-list shortcut; retain the Banks/Cash heading as the account entry point. Keep the existing KreaTax fiscal/bank report at level one after native bank siblings and before the next section, preserving its URL/permissions. Physical order must match native sibling sorting before reindexing; Eldy checks adjacent integer keys after a preserved-key sort. The installed-renderer regression is `php test/test_navigation_native_menu.php`. Native-only menus remain unchanged. Account tools show only details, documents and annual reports; hidden native tools retain direct routes.
- Always preserve the native Dolibarr left menu and its responsive behavior. Hide the duplicate native account tab bar and the isolated account banner on ordinary movement lists within the banking workspace; retain native action confirmations and account tools. Avoid explanatory banners and duplicate account navigation.

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

- Original bank file order is independent of import/feed batch order. Reviewed
  `KreaBankService::recordStatementEvidence()` metadata retains file checksum,
  exact existing staging identities, chronological sequence, value dates and
  balances in `kreabank_statement.import_payload`. Never rewrite feed rows or
  native financial records to repair display order. The combined projection
  validates each live identity/amount/currency/date and sorts before pagination.
  Use only the guarded disposable `test_statement_evidence_database.php` suite.
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

- `getWorklistPaymentAccountReview()` adds list-only, exact same-day amount
  advice when there is no eligible full-amount native choice in the source bank.
  Keep this possible bank issue independent of Match and strong automatic
  identity. Preserve all raw ambiguity/overflow and native correction guards;
  only one complete eligible movement gets a signed bank-column correction.
  Never restore the separate other-bank selector section. See the guarded
  `test_worklist_payment_account_review_database.php` and pure bank-column suite.
- `KreaBankService::getPaymentAccountReview()` accepts explicit staging IDs and
  checks current-company invoice, salary and tax payments in other owned accounts.
  It returns
  review evidence only; never feed these records into cross-account execution.
- Exact signed persisted amount/currency and nearby booking dates are required,
  with a complete payment/check reference or, when the bank reference is absent,
  the complete name of the single fully allocated invoice counterparty or salary
  employee. Salary visibility follows native base read/hierarchy rights; correction
  also requires native write and binds exact parent/payment/employee/link facts.
  Tax warnings require a specific liability/payment reference or a complete
  period-bearing label. Names are
  review evidence only, never strong identity for unattended matching. Suspected
  errors block automatic-native and TPA execution and bulk approval. Bulk review/correction uses the existing native
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

- Selected-line bank movement, invoice and payment candidates submit directly to
  `reconcile.php`
  with explicit `line_id`, `line_source`, owned account and a signed displayed
  `review_doc`; successful approval returns to the one-by-one queue. Ordinary
  review uses the same per-card action layout for every record type. Only batch
  mode exposes native-movement checkboxes and combined allocation controls.
  Native per-card approval requires the exact signed amount and source account;
  restricted accounts still permit their eligible existing transfer movements.
  Automatic batch detection is advisory and must retain per-card actions; only
  the explicit Search batch payments action enables checkbox/allocation mode.
  `lib/kreabank_manual_review.lib.php`
  shares candidate eligibility and execution with matched/bulk review. One-by-one
  cards allow an explicitly signed choice among competing records;
  never rank or preselect that choice automatically. Matched/bulk review retains
  its unique-suggestion requirement. Invoice approval is labelled **Record
  payment and reconcile**.
  Associated forms stay outside the native-movement form. Validate with the
  synthetic `test/test_reconcile_document.php` controller suite.

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

- Successful selected-line reconciliation must continue in the displayed queue,
  not restart at the first SQL-sorted row. `lib/kreabank_reconcile_queue.lib.php`
  carries bounded navigation state only; choose targets from freshly scoped
  pending rows. Keep the default oldest-first booking-date order through Next and
  completion; never rotate or restore a saved order over the active server sort.
  Preserve search/sort, stay on failed or partial actions, and deploy
  the helper with `reconcile.php` and `js/reconcile.js`. Synthetic controller and
  queue suites cover the POST/redirect/GET contract; never reconcile live data to
  test navigation.

- Keep the same candidate-card layout, controls and action wording for equivalent
  reconciliation flows, including taxes. Do not introduce record-type-specific
  layouts or oversized warning banners.

- The legacy `reconcile.php` address redirects to the selected-line include. Its
  surviving presentation assets are `css/reconcile.css` and `js/reconcile.js`.
- Pending search filters the bounded loaded queue (up to 200 lines); the full
  pending view remains accessible. Mobile starts with the queue collapsed.
  Matching and creation have separate panels and explicitly labelled actions.
- Allocation feedback sums original hidden decimal strings; two-decimal display
  must never rewrite POST amounts. The service remains authoritative for amount,
  company, permission, lock, duplicate and safe-match validation.
- Ordinary bank creation retains its explicit submit action, including implicit
  Enter submission, and failed creation retains reviewed inputs. Supplier invoice
  and tax creation are retired; keep native payment-dialog cancellation separate
  from financial submission.
- `php test/test_reconcile_workspace.php` renders the actual view without an ERP
  bootstrap or database. Its `--html <case> [locale]` output intercepts every
  submission for browser checks. `node test/test_reconcile_workspace.cjs` checks
  exact allocation boundaries. See `doc/RECONCILIATION_UI.md`.

- Selected-line Search includes review-only movements from other current-company
  accounts. `searchOtherAccountMovements()` never feeds the matcher. The explicit
  `changeMovementAccount()` path requires a single-use session review and native
  modifier rights, revalidates the exact displayed snapshot under locks, changes
  only the native account through the adapter and commits its audit atomically.
  Keep wrong-account results outside the selectable reconciliation form; reload
  corrected movements unchecked. Never move imported/mapped, reconciled,
  accounting-posted, cheque-deposit, transfer or restricted-account movements.
  See `doc/RECONCILIATION_UI.md` and the pure/disposable `test_account_correction`
  suites. Bulk account warnings retain their native correction links.

- Wrong-account payment evidence blocks all approvals, including signed
  `selected_native` choices and retained selector drafts. Preserve the full
  review/truncation state; never promote an arbitrary or incomplete warning.
  The list displays the actual recorded bank with an accessible exclamation,
  an eligible reviewed correction dialog and disabled reconciliation. Recheck
  after correction; no cross-account payment becomes a reconciliation target.
  Validate with the worklist/controller/view suites and guarded
  `test_manual_native_account_review_database.php`.

## Existing tax reconciliation

- `KreaBankTaxReconciliation` supplies tax projections and native tax payment
  lifecycle calls within `KreaBankService::reconcileLine()`. Existing unpaid taxes
  and unbanked tax payments require signed manual review and native tax read/write
  rights. Keep these targets out of unattended approval and pattern learning.
- `paiementcharge` has no entity column; its `chargesociales` parent and any native
  bank account must belong directly to the current company. Subtract all native
  tax payments with DECIMAL arithmetic; lock the tax and payments before approval.
  Preserve the configured active native payment mode, parent identity, source
  currency, partial-payment status and atomic reconciliation audit.
- Banked tax payments remain native-movement candidates. Other-account tax search
  and correction reuse the existing reviewed correction path and its financial
  locks; never change the tax, its default account or its payment allocations.
  Tax-like amount/date warnings are advisory and block approval pending review.
- `php test/test_tax_reconciliation_database.php` extends ONLY the guarded
  localhost:33317/kreabank_auto_test fixture with installed native tax/payment
  classes. See [tax reconciliation](doc/TAX_RECONCILIATION.md).

## Draft invoice validation

- Selected-line draft cards retain the standard layout and an expandable, separate
  validation confirmation. `KreaBankService::validateDraftInvoice()` rechecks native
  invoice/advanced rights, direct company ownership, source facts and complete
  header/line snapshots under locks, and commits the native lifecycle with its
  audit. Single-use session reviews expire after 15 minutes. Validation creates no
  payment or reconciliation; require fresh ordinary approval afterward.
- Keep drafts outside shared matching, matched/bulk review and automatic execution.
  Credit notes and foreign-currency drafts remain excluded. Use native invoice
  options when stock-on-invoice requires warehouse selection. Pure/controller/UI
  checks and `test/test_draft_invoice_review_database.php` cover this flow; the
  latter uses only the guarded disposable localhost:33317 fixture.

## History safety

- `KreaBankHistory` reuses the read-only combined statement projection, preserves
  source/native namespaces and verifies audit association and payment-group members.
  History GETs never construct the execution service. Keep independent table
  filters/pagers, the fixed 5,000-source window and per-currency totals.
- Scalar Undo requires a single-use displayed snapshot, current owner company and
  permissions, exact locked source/native facts and atomic audit/commit. Preserve
  imported statement references so undone lines remain available for review.
  Groups use the existing group controller. See `doc/LISTS.md`; regression checks
  are `test/test_history.php` and guarded disposable `test/test_history_database.php`.

## Existing-movement creation guard

- `KreaBankService::assertNoExistingMovementForCreation()` runs before every selected-line
  creation choice and inside `createQuickEntryAndReconcile()`. Same owned account, exact
  signed persisted amount and booking date within tolerance block creation, whatever the
  reconciled state; movements mapped to another imported line are distinct evidence.
  Only the user's confirmation of the exact current list overrides, with an
  `existing_movement_override` audit. Rule entries and truncated searches never override.
- `reconcileLine()` repeats the check inside its transaction before any flow materializes a
  movement. `reconcile.php` holds the audited confirmation per line for 15 minutes
  (`confirm_existing_movements`); matched/bulk/list and unattended paths fail closed.
- A native movement with a `kreabank_statement_line.fk_native_bank_line` mapping belongs to
  that line only: never offer or accept it for another line, whatever its native `rappro`.
- `KreaBankTransferGuard` (hooked on native `transfer.php` add) is read-only review: a leg
  repeating an owned same-amount, nearby-date movement is refused until the identical
  transfer is entered again (5 s–10 min). Keep `audit_type` values within 32 characters.
- `php test/test_reconciliation_fireproof_database.php` replays everyday mistakes with
  native invoices/payments on the guarded disposable fixture only.
- Statement deletion checks reconciled lines under account/statement/line locks in one
  transaction with its audit. Never move that check or the audit outside the transaction.
- Table checks run once per `KreaBankService::schemaFingerprint()` (stored globally in
  `KREABANK_SCHEMA_FINGERPRINT`, cleared on module activation). Never add per-request DDL.
- `kreabankIsTokenValid()` intentionally accepts the current and the next-request session
  token: both are session secrets and background/multi-tab POSTs need the latter when
  per-call token renewal is enabled.
- `php test/test_existing_movement_guard.php` is pure; its database counterpart uses ONLY
  the guarded disposable localhost:33317 fixture.

## Line workspace inside the reconciliation list

- `reconcile.php` is only a permission-checked redirect. The selected-line workspace
  lives in `line_workspace.inc.php`, included by `reconciliationlist.php` for
  `workspace=1` or its explicitly listed POST actions. References to the old
  controller above mean this workspace. Its surviving assets are
  `css/reconcile.css` and `js/reconcile.js`.
- Keep workspace and list action names disjoint and the routed list explicit.
  The include has no bootstrap and refuses direct requests.
  `kreabankWorkspaceUrl('reconcile')` is the URL builder; never link to the retired
  controller. `php test/test_line_workspace_route.php` checks the routing contract.
- 1.20.49 removes More options and its four list actions, plus skip, tax creation
  and supplier invoice creation everywhere. Do not restore these retired features
  or the deleted supplier-dialog script. Preserve the unified existing-movement selector, batches, ordinary bank
  movements and the other signed legacy workspace reviews.

## Status filters and reviewed list Undo

- Reconciliation-list status admission/counts use persisted link integrity and actor-private batch drafts before matching. Draft is a private selection; Review is an integrity warning, never a no-match result. Keep counts scoped to the other filters; incomplete derived-filter counts remain unknown. Hide empty exceptional tabs without removing their routes.
- List scalar Undo reuses fresh History snapshots and single-use scoped nonces through `lib/kreabank_worklist_undo.lib.php`, then the existing locked service. Groups keep complete-batch Undo; reviewed payment differences retain their native reversal. Cancellation is a local dialog close. Include the target native movement in scan invalidation dependencies.
- History components pair documents/payments with explicit `native_bank_line_id`, never proximity/order. Customer transfer metadata alone follows native `getEntity('societe')` customer sharing; all financial parents still require direct owner-company scope, refreshed at display and execution.
- `test_worklist_status.php`, `test_worklist_undo.php` and the view/controller suites are database-free. The status database suite uses only the guarded disposable automatic-reconciliation fixture.

## Automatic receipts and ordinary owned transfers

- 1.20.71 adds `OWN_TRANSFERS_ENABLED`, independent of restricted account maps. Keep the shared service and native adapter; never lower the ordinary matcher's score or identity requirements. `KreaBankOwnTransferEvidence` checks explicit transfer direction plus complete current-company legal identity or exact owned IBAN. Duplicate canonical names across companies disable name-only proof. Never infer ownership from account suffixes.
- Require exact opposite signed persisted amounts, currency, both selected directly owned accounts, unique source claims and complete reciprocal native links. Reuse native transfers, including one completed peer with its intact owned mapping. Create both `VIR` legs only when neither side has possible native evidence; no expense or invoice payment creation. Preserve opening cutoffs and restricted-account policy.
- `KreaBankReconciliationLock` retains the existing company runner lock name and balances recursive acquisitions. Both direct transfer executors and native transfer POSTs share it; native requests hold authorized endpoint-owner locks through shutdown. Native duplicate-check failures stop the write. Do not wrap the native request in another SQL transaction.
- TPA duplicate identity is scoped to booking day and exact amount; distinct amounts require intact evidence. Exact UNICRE descriptors additionally require persisted provider payload/hash/source identity. Reuse only unclaimed, unlinked native receipts with exact identity and amount. Unidentified equal amounts and contradictory evidence remain manual. Native references longer than 50 characters use a deterministic hash suffix; full source evidence is retained.
- The existing runner reserves up to 10 of its 50 evaluations for recognized receipts/transfers, with a separate priority cursor and six-second between-line priority budget. Explicit previews advance neither cursor. Test with the pure evidence/reference/lock suites and the guarded `test_own_transfers_database.php`, `test_automatic_priority_database.php`, `test_tpa_receipts_database.php` and `test_reconciliation_lock_database.php` on disposable port 33317 only.
