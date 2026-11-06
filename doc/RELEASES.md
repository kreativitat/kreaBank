# Changelog

## 1.19.39 — 2026-11-06

- Preserve an explicitly selected all-accounts reconciliation queue when sorting
  by date or selecting a transaction. Infer an account only for direct links that
  omit account context.

## 1.19.38 — 2026-11-06

- Keep the reconciliation queue oldest first through Next and successful
  reconciliation. Ignore saved queue rotations while retaining explicit sorting,
  search and continuation to the next displayed pending transaction.

## 1.19.37 — 2026-11-04

- Isolate account action cells from theme flex styles to preserve table alignment.

## 1.19.36 — 2026-11-04

- Use the module’s Actions translation for the account-table heading.

## 1.19.35 — 2026-11-04

- Move account edit icons to a compact, right-aligned Actions column. Keep account
  references linked to movements and retain accessible edit labels.

## 1.19.34 — 2026-11-04

- Speed up Next and selected-line loading with an indexable other-account
  amount/date lookup. Keep the same calendar-day tolerance and review safeguards.
- Extend explicit online reconciliation maintenance with a native bank
  `(amount,dateo,fk_account)` index; web requests never apply this DDL.

## 1.19.33 — 2026-11-04

- Show the selected account's Dolibarr balance, synced bank balance and bank
  refresh time above native movements, independently of list filters and pages.
- Add an Unreconciled movements view using native reconciliation status, retained
  through account selection, search, sorting and pagination.

## 1.19.32 — 2026-11-04

- Show each reconciliation candidate’s recorded bank account reference on its
  card, retaining individual approval buttons and using a dash for unbanked records.

## 1.19.31 — 2026-11-04

- Keep individual reconciliation buttons when automatic batch detection finds
  nearby payments, description hints or a learned batch prediction. Checkbox
  allocation requires the explicit Search batch payments action.

## 1.19.30 — 2026-11-04

- Show a repeated payment/reference label only once on reconciliation cards.
  Retain the counterparty line when it adds a different name.

## 1.19.29 — 2026-11-04

- Align larger candidate amounts to the right of the date on the top row. Use
  the same prominent date/amount row above each pending sidebar description.

## 1.19.28 — 2026-11-04

- Move candidate dates to the top of selected-line reconciliation cards and make
  them larger and bold for easier review.

## 1.19.27 — 2026-11-04

- Allow signed one-by-one approval of an exact existing movement in the statement
  account while another-account payment warning is visible. Preserve the other
  payment/account and retain bulk, automatic and invoice/payment creation blocks.
- Sign the approval context and warning state, revalidate under locks and audit
  the explicit choice. No schema or scheduler changes.

## 1.19.26 — 2026-11-04

- Show possible other-account invoice payments on initial line selection using
  exact amount and nearby booking dates, even without a matching bank reference
  or full supplier name. Retain explicit reviewed account correction and separate
  reconciliation; automatic matching and financial execution are unchanged.

## 1.19.25 — 2026-11-04

- Continue successful one-by-one reconciliation in the displayed pending order
  instead of restarting at the first database row. Preserve search and sort
  across signed approval, native allocation and bank/tax/invoice creation.
- Revalidate continuation against current company/account pending rows. Keep
  failed or partial actions on the selected line; financial execution is unchanged.

## 1.19.24 — 2026-11-04

- Make Next follow the selected pending line in the displayed queue, including
  after manual selection or restoring a saved order. Preserve the cyclic order
  and open the first visible line when search hides the current selection.
- No financial, schema or scheduler changes.

## 1.19.23 — 2026-11-04

- Show customer and supplier draft invoices in selected-line reconciliation.
  Expand Validate invoice and confirm before native validation; payment and
  reconciliation remain separate explicit actions.
- Enforce native permissions, company ownership and a single-use review of
  unchanged invoice/source evidence. Keep drafts outside automatic and bulk
  matching; warehouse-dependent validation opens the native invoice options.

## 1.19.22 — 2026-11-04

- Reload corrected tax movements through the same projection as ordinary
  candidates. Keep displayed tax details and the signed approval identical so
  the first reconciliation attempt after account correction is accepted.
- Preserve exact review validation; changed records still require a new review.

## 1.19.21 — 2026-11-04

- Show existing tax/invoice payments flagged in another account immediately in
  the normal matching panel, without pressing Search. Keep their standard cards,
  reviewed account correction and reconciliation safeguards; do not show an
  empty-results message or default to creation when those records are present.

## 1.19.20 — 2026-11-04

- Use the standard Record payment and reconcile action text for existing taxes.
  Translate native tax payment labels through the shared bank-label renderer.
  Keep the same candidate-card layout and controls for all reconciliation types.

## 1.19.19 — 2026-11-04

- Remove the large payment-account warning banner above one-by-one reconciliation.
  Keep existing other-account search/correction cards and server-side safeguards.

## 1.19.18 — 2026-11-04

- Reconcile existing taxes/social contributions and unbanked tax payments through
  signed manual review. Compute remaining balances from native payments; use the
  configured active payment method and native paid/partial-payment lifecycle.
- Reuse banked tax movements without duplicate candidates or financial records.
  Include tax evidence in other-account search and reviewed account correction;
  flag possible misposted DUC/social-tax payments before approval or automation.
- Recheck parent-company ownership, reviewed tax identity/method, currency and
  current balances under locks. Commit payments, links, reconciliation and audit
  atomically. No schema or scheduler change.

## 1.19.17 — 2026-11-04

- Translate native `(banktransfert)` labels in reconciliation cards and movement
  search results. Preserve stored references, signed reviews and financial data.

## 1.19.16 — 2026-11-04

- Use the same per-card action layout for ordinary bank movements, invoices and
  payments in one-by-one review. Native movements use signed approval of their
  exact persisted amount and account through the existing service.
- Keep multiple native selections and allocation controls in batch mode.
  No schema or scheduler changes.

## 1.19.15 — 2026-11-04

- Give each eligible one-by-one invoice/payment candidate its own signed action,
  including equal candidates. Confirm exactly the clicked card after revalidation;
  reject signatures from another card. Keep unsafe actions visibly disabled.
- Preserve matched/bulk ambiguity safeguards and unattended matching rules.
  No schema or scheduler changes.

## 1.19.14 — 2026-11-04

- Show candidate dates on their own line immediately below the reference and
  record type in one-by-one reconciliation, followed by the counterparty name.

## 1.19.13 — 2026-11-04

- Record an existing invoice's payment and reconcile directly in one-by-one
  review. Submit a signed displayed pair on the same page and return to its queue;
  preserve account/sort context and retain the line on failure.
- Share manual review preparation/execution with matched and bulk review. Keep
  CSRF, permissions, ownership, ambiguity and execution safeguards unchanged.
  Prevent native allocation scripts from intercepting document-only approval.
  No schema or scheduler changes.

## 1.19.12 — 2026-11-04

- Identify invoice/payment candidates correctly in selected-line reconciliation.
  Open their explicit signed individual review instead of submitting unsupported
  native-movement checkboxes. Show the payment consequence before approval.
- Review an explicit owned statement source without scanning the whole queue;
  preserve shared suggestion, ambiguity, signature and execution safeguards.
  No schema or scheduler changes.

## 1.19.11 — 2026-11-04

- Preserve distinct identical-looking rows in reviewed XLSX imports through an
  explicit verified source-file checksum and original row identity. Replays skip
  the original rows, changed identities fail closed, and older row hashes remain
  recognized. Ordinary import duplicate checks remain unchanged. No schema change.

## 1.19.10 — 2026-11-04

- Preserve a reviewed original bank file's within-day sequence, value dates and
  running balances across import batches and duplicate-skipped feed movements.
  Date sorting reverses that sequence before pagination and header filtering.
- Attach source checksum and row evidence atomically to existing statement
  metadata. Keep feed identities, staged amounts/statuses, native movements,
  payments and reconciliation receipts unchanged. No schema or scheduler change.
- Normalize description encodings in the read projection for legacy latin1
  databases; preserve UTF-8 accents without changing stored source columns.

## 1.19.9 — 2026-11-04

- Search existing movements in other owned accounts from selected-line reconciliation.
  Keep these results unselectable until an explicit, reviewed account correction.
- Preserve native movements, payments and invoice allocations. Recheck ownership,
  currency, source facts, accounting/reconciliation locks and protected operations
  under locks; commit the account-only change and its audit atomically.
  Reconciliation remains a separate action. No schema or scheduler changes.

## 1.19.8 — 2026-11-04

- Keep reconciliation primary-button text white on hover, keyboard focus and
  press. Match the native theme selector specificity so the theme's dark text cannot
  override the green button style. Financial actions remain unchanged.

## 1.19.7 — 2026-11-04

- Allow one daily Fintable collection across all active companies, defaulting to
  08:45 Europe/Lisbon. Retain support for existing two-slot schedules, slot
  idempotency, retry bounds and the single native scheduler. No schema change.

## 1.19.6 — 2026-11-04

- Flag possible invoice payments recorded in another owned account when the bank
  omits a payment reference but supplies the complete counterparty name, with the
  exact signed amount/currency and a nearby date. Require one invoice counterparty;
  normalize accents, punctuation and internal display codes for name evidence.
- Keep name evidence advisory: no cross-account reconciliation, account changes
  or relaxation of unattended matching. No schema or scheduler changes.

## 1.19.5 — 2026-11-04

- Add Next (pt_PT: Seguinte) to move the first visible pending line to the bottom
  and open the next one. Keep queue order within the tab, company, user, account
  and sort; pending status and financial records remain unchanged.
- Remove the selected-line Resolve this line later control. Bulk deferral remains
  available from pending lines. No schema or scheduler changes.

## 1.19.4 — 2026-11-03

- Wrap the native bulk paging title on small screens, preserving its page-size
  selector and eliminating horizontal overflow. Extend the synthetic native shell.

## 1.19.3 — 2026-11-03

- Align individual matched and bulk review with the reconciliation workspace:
  separate statement and existing-record summaries, allocation differences,
  explicit payment consequences and expandable evidence.
- Show live bulk selection counts and exact per-currency totals; retain signed
  manual approval, stable scanning and native navigation. Empty match results
  open the permitted creation workflow through the selected account context.
- Add synthetic view and interaction coverage. No schema or scheduler changes.

## 1.19.2 — 2026-11-03

- Open record creation when a selected statement line has no existing candidates
  and creation is permitted. Preserve explicit matching searches, restrictions
  and failed-creation inputs. No financial execution or schema changes.

## 1.19.1 — 2026-11-03

- Keep Reconcile (pt_PT: Reconciliar) inside the native Banks/Cash left-menu
  group instead of rendering it outside the group after sorting.

## 1.19.0 — 2026-11-03

- Redesign reconciliation around a compact searchable queue and a selected-line
  summary, with separate existing-entry matching and record-creation workflows.
- Show live allocation totals and differences; make bank entry, tax payment and
  supplier invoice consequences explicit. Preserve service execution safeguards.
- Add responsive queue disclosure, keyboard workflow selection and accessible
  invoice review. Retain creation inputs after errors and show persistent results.
- Add synthetic view/interaction fixtures and decimal-boundary checks. No schema
  or scheduler changes. See [workspace validation](RECONCILIATION_UI.md).

## 1.18.17 — 2026-11-03

- Add the Reconcile shortcut (pt_PT: Reconciliar) to the native bank left menu
  for internal bank and workspace readers, preserving account children and links.

## 1.18.16 — 2026-11-03

- Default the account overview to CO_ accounts, then other bank-feed-connected
  accounts, then remaining accounts, with reference ordering within each group.
  Preserve manual column sorting and stable pagination.

## 1.18.15 — 2026-11-03

- Correct batch learning feature scaling and Random Forest loading. Version new
  samples, retain incompatible legacy evidence, and learn once per current native
  movement from confirmed manual snapshots. Undone/corrected outcomes no longer
  inflate or silently retain obsolete training labels.
- Validate on later observations without duplicate feature leakage; report failed
  predictions, F1, balanced accuracy and a majority-class baseline. Keep financial
  approval rules unchanged.
- Require supplier identity support and abstain on unfamiliar/conflicting evidence.
  Isolate approved header examples by company, reject ambiguous mappings, and
  serialize concurrent template saves. Restrict/private-cache model restoration.
- See [learning design, upgrade behavior and validation](MACHINE_LEARNING.md).

## 1.18.14 — 2026-11-03

- Use native Dolibarr pagination for account lists, imported statements and their
  lines, connection accounts and the installation feed company summary. Retain
  account/view context, page size and complete-result sorting across navigation.

## 1.18.13 — 2026-11-03

- Prefix suggested native-movement descriptions with their linked payment
  references. Revalidate company, movement and invoice-parent ownership without
  changing matching scores.
- Use search inputs for literal statement text filters so native numeric-search
  tooltips do not advertise unsupported text expressions.

## 1.18.12 — 2026-11-03

- Add combined-statement table header filters and native Dolibarr pagination,
  including page size, direct page entry, sorting and search/reset controls.
- Filter the complete owned-account projection before pagination, preserving
  original and calculated balances and the source evidence status.

## 1.18.11 — 2026-11-03

- Show the recorded bank account reference in suggested correspondence panels for
  native movements and linked payments. Bind that account to manual review approval.

## 1.18.10 — 2026-11-03

- Keep review panels stable during background progress; show individual matches
  after sorting finishes and stop polling when ready.
- Add explicit online payment lookup index maintenance for large native payment
  histories. Preserve candidate rules, ownership checks and financial records.

## 1.18.9 — 2026-11-03

- Confirm the current matched pair directly with its reconciliation button. Remove
  the individual review checkboxes; keep signed-pair revalidation and bulk approval.

## 1.18.8 — 2026-11-03

- Open account movements from each account reference. Keep account details on a
  pencil icon beside the reference and remove the separate row actions column.

## 1.18.7 — 2026-11-03

- Add a matched-only reconciliation tab with one proposed pair at a time, account
  filtering and previous/next navigation. Reuse the bounded background scan and
  signed manual confirmation; unmatched and wrong-account-review lines stay pending.

## 1.18.6 — 2026-11-03

- Show only account references in statement account columns and filters.

## 1.18.5 — 2026-11-03

- Remove the visible Calculated label beneath statement balances. Keep the
  calculation and source information in the balance tooltip.

## 1.18.4 — 2026-11-03

- Show calculated running balances when the feed supplies only a current bank
  balance. Work backwards across the complete collected account history using
  exact decimals; sorting and pagination retain each line's balance.
- Label calculated values and preserve supplied balances. Same-day ordering uses
  the imported statement order because the feed supplies no transaction times.
  Reject incomplete collections, changed mappings, conflicts, duplicates and
  missing or inconsistent source links. No persisted balance or ledger changes.

## 1.18.3 — 2026-11-03

- Open all imported statements when arriving from an account without imports,
  keeping the statement filter and displayed account scope consistent.

## 1.18.2 — 2026-11-03

- Limit statement-view account filters to current-company accounts with imported
  statements, including legacy imports. Other operational selectors are unchanged.

## 1.18.1 — 2026-11-03

- Hide provider external transaction identifiers from combined and individual
  statement tables. Preserve bank descriptions, payment references, amounts and
  stored source identities used for duplicate detection.

## 1.18.0 — 2026-11-03

- Add a Bank statement tab combining all imported statement movements in one
  company/account-scoped table, including pending and legacy imports.
- Show operation/value dates, original descriptions and references, debit, credit,
  currency, supplied bank balances, source statements and reconciliation status.
  Sort before pagination; mapped native entries appear once. Missing balances
  remain unknown. No schema, ledger, REST or scheduler changes.

## 1.17.0 — 2026-11-03

- Flag possible invoice payments recorded in another company-owned bank account
  during automatic preview, bulk review and individual reconciliation. Show the
  recorded and statement accounts, payment and existing native movement to verify.
- Keep suspected mistakes pending, exclude them from automatic execution and bulk
  approval, and require a reviewed native correction before ordinary matching resumes.
  Exact signed amount, currency, nearby dates and a complete payment reference are
  required; ambiguous candidates remain visible for review. No schema migration,
  automatic account correction, new payments or scheduler configuration changes.

## 1.16.3 — 2026-11-03

- Shorten the Portuguese bank refresh heading to “Última atualização”.

## 1.16.2 — 2026-11-03

- Show only the bank refresh date and time in the account overview update column.
  Collection details remain on the bank feeds page; synchronization is unchanged.

## 1.16.1 — 2026-11-03

- Show the daily feed bank balance, bank refresh and collection times in the
  account overview beside the separately labelled Dolibarr ledger balance.
- Keep missing balances distinct from zero and expose stale/failed feed states.
  Scope snapshots by current company, workspace, account, currency and IBAN.
- Keep wide account tables scrollable inside the content area.
- No schema, accounting, scheduler or REST contract changes.

## 1.16.0 — 2026-11-02

- Add authenticated, current-company REST reads for accounts, statements, original
  imported lines and audit metadata, supporting KreaMCP without a parallel ledger.
- Preserve decimal amounts, explicit currencies, staging/native ID namespaces,
  link discrepancy states and bounded SQL pagination. Both native bank and
  KreaBank read permissions are required; external users are denied.
- No schema migration, financial writes, feed synchronization or scheduler changes.
  See [REST integration](API.md) for deployment order and regression checks.

## 1.15.6 — 2026-11-02

- Require an explicit active native payment mode for automatic TPA receipts.
  Revalidate dictionary scope and availability before creating the bank line;
  missing or disabled modes fail closed. No schema migration or new scheduler.

## 1.15.5 — 2026-11-02

- Preserve printed terminal identifiers, including leading zeroes, and scope
  duplicate TPA closure checks to their booking day. Bank settlement streams
  can legitimately reuse counters on later dates. Retain native-ledger conflicts,
  source-identity checks and atomic receipt creation. No schema migration.

## 1.15.4 — 2026-11-02

- Add opt-in automatic TPA settlement receipts to the existing company runner.
  Recognize complete FECHO TPA closure identities, reuse safe existing matches,
  and create native card receipts (CB) only without competing ledger/import evidence.
- Preserve company/account restrictions, preview mode and atomic audit/rollback.
  No schema migration or additional scheduler; see [TPA receipts](TPA_RECEIPTS.md).

## 1.15.3 — 2026-11-01

- Pass the native `account` parameter in treasury links and account-filter
  redirects, fixing `ErrorBankAccountNotFound` and the missing page layout.
- No schema, registration or scheduler changes. Replace old `treso.php?id=…`
  bookmarks with `treso.php?account=…`.

## 1.15.2 — 2026-11-01

- Align account-table balance/action headings with their values.
- Add ascending/descending sorting across operational lists, reconciliation cards,
  bulk review, import previews and administration data tables. Preserve filters,
  company/account scope, signed review pairs and bounded background scanning.
- Keep history/audit ordering independent and their recent window fixed across
  pages. No schema or scheduler migration; see [list sorting](LISTS.md).

## 1.15.1 — 2026-11-01

- Show KreaBank amounts, balances, totals and percentages with two decimal places,
  including import previews, reconciliation and quick-entry amounts. Preserve
  localized separators, stored evidence and calculation/matching precision.
- Cap native bank-list displays at two decimals for the current request without
  changing saved Dolibarr settings. No schema migration.

## 1.15.0 — 2026-11-01

- Configure company-owned restricted/main account pairs, including several
  restricted accounts sharing one main account. Enforce the relationship in
  KreaBank reconciliation and native internal transfers.
- Add opt-in automatic two-sided transfer reconciliation to the existing runner.
  Reuse native transfers or materialize both proven statement legs atomically;
  retain strong identity, exact amount, ambiguity, permission and retry guards.
- Pair configuration alone enables no scheduled jobs. No schema migration;
  see [account pairs](ACCOUNT_PAIRS.md) for activation and rollback.

## 1.14.27 — 2026-11-01

- Rename only the account-list submenu; retain the native Bank/Cash heading that
  uses the same route.

## 1.14.26 — 2026-11-01

- Replace the accounts overview filter form with compact all/bank/cash/closed
  view links, highlighting the active type. Operational account filters stay separate.

## 1.14.25 — 2026-11-01

- Rename the native banking List menu entry to Account list.
- Keep new-account creation in the native layout without a workspace header,
  account selector or operational tabs.

- Show only the bank-account filter on operational pages and only the account-type
  filter on the standalone accounts overview. Remove hidden account/type context
  that cannot be changed through those controls.
- Stop carrying account types into operational links and queries; old `kind=closed`
  URLs no longer hide operational data. Preserve current-company account ownership.

## 1.14.24 — 2026-11-01

- Separate account management from the five operational banking tabs. Keep account
  details and tools on their own pages, accessible through the accounts list.
- Use one separate account/type filter panel on movements, statements,
  reconciliation, connections and history; remove the old header selector.
- Apply type filters before counting/pagination, retaining company ownership and
  filter state across navigation, searches and bulk scans. Refresh open bulk-review
  forms after upgrading. No schema, financial-data or scheduler migration.

## 1.14.23 — 2026-11-01

- Keep internal transfers in the native layout without the KreaBank workspace
  header, account selector or tabs. Preserve the native left menu and transfer flow.

## 1.14.22 — 2026-11-01

- Separate account-list filters from banking navigation. Group the owned-account
  selector and account-type dropdown above the table instead of tab-style filters.
- Submit both filters together, preserving their values without an intermediate
  view redirect. Other banking pages retain their account-context selector.

## 1.14.21 — 2026-11-01

- Place the bank-account selector below the banking/company heading, aligned to
  the left above the workspace tabs.

## 1.14.20 — 2026-11-01

- Keep automatic reconciliation accessible only through module configuration;
  remove its operational left-menu shortcut.
- Move selected-account tools into account-options tabs and remove their left-menu
  section. Preserve native bank navigation and company/account ownership checks.

## 1.14.19 — 2026-11-01

- Remove the duplicate standalone banking heading. Place automatic reconciliation
  and the restricted feed shortcut under the existing native Bank/Cash menu.
- Keep the banking entry point, account tools and permissions unchanged.

## 1.14.18 — 2026-11-01

- Use Dolibarr’s supported menu condition syntax for automatic reconciliation
  visibility, retaining administrator, internal-user and write-permission checks.

## 1.14.17 — 2026-11-01

- Remove the floating “More options” dropdown. Keep transfers and cheque deposits
  in their native left-menu entries and add permission-scoped automatic settings.
- Place selected-account tools in the native left menu, validating current-company
  ownership before rendering the links. No financial or scheduler changes.

## 1.14.16 — 2026-11-01

- Restore the native Dolibarr left menu and its standard content layout on all
  banking pages. Keep the simplified banking header and account controls.
- No business-data, permission, schema or scheduler changes.

## 1.14.15 — 2026-11-01

- Keep the accounts overview consistent with its selected account and native
  account type. Restore a compact all/reconciled view switch in Movements.
- Fold longer reconciliation guidance into optional help and refine banking labels.

## 1.14.14 — 2026-11-01

- Use the full banking content width after hiding the native sidebar, and remove
  the redundant native content border/spacing.

## 1.14.13 — 2026-11-01

- Replace layered bank menus with one workspace header, an owned-account selector
  and a clean accounts overview. Hide the native banking sidebar and tabs within
  this workspace; retain account tools in a compact options menu.
- Remove explanatory integration banners and duplicate bulk-review headings.
  Place import within statements and preserve native ledger/lifecycle behavior.
- Scope native list reads to the current owner company and keep financial writes,
  reconciliation identity checks and schedules unchanged. No schema migration.

## 1.14.12 — 2026-11-01

- Leave competing bulk candidates pending instead of selecting the first equal
  amount/date invoice. Preserve explicit review of unique suggestions.
- Revalidate company and bank-account ownership for prepared lines and every
  execution target before native writes; reject foreign payment parents, account
  links, signed-direction and currency conflicts.
- Show the source account/owner and target document reference in bulk review.
  No schema, scheduler or financial-data migration.

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
