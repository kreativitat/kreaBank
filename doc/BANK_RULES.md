# Bank rules

Rules provide company-local advice in the selected-line reconciliation workspace.
They never authorize unattended matching, bulk approval, TPA receipts or restricted
account transfers. Existing safe suggestion criteria and execution remain unchanged.

Open **Module configuration → Bank rules**. Read access uses the existing KreaBank
and native bank read rights. Changes require `kreabank->rules->write`, native bank
write permission, POST and CSRF. New rules are inactive until explicitly enabled.

Use the native configuration list to create/edit a rule, enable/disable it, change
its numerical priority or delete it. Lower priorities run first; equal priorities
use the stored rule ID. Every matching rule is returned so the workspace can show
conflicts while using only the first match. Usage counts update only after a
successful approved reconciliation, in the same transaction as the financial audit.
Configuration changes and deletion have their own atomic audit entries.

Rules apply to one directly owned account or all directly owned accounts. Direction
is incoming, outgoing or both. All conditions in **All** must match. When **Any**
contains conditions, at least one of those must also match. A rule needs at least
one condition; blank form rows are ignored. Amounts are signed decimal strings:
an outgoing amount of twelve euros is `-12.00`. Intervals include both endpoints.
IBAN conditions require a valid checksum. Regex input is a pattern body without
delimiters. Patterns are limited to 256 bytes, evaluated against at most 4,096
bytes with fixed PCRE match/depth budgets; invalid or exhausted patterns fail closed.

Choose one action:

- Search supplier/customer invoices for an existing directly owned third party.
- Prefill a quick entry with an existing supplier, accounting account, VAT, label
  and explicitly selected active native payment mode.
- Suggest an existing current-company native bank movement category.

Advice uses the normal candidate-card evidence and signed manual review. It does
not become an identity or increase automatic matching scores. Payment mode has
no implicit transfer/cheque fallback. Approval rechecks current source ownership,
the winning rule/revision, references and financial facts under locks. Rule results
do not train learned patterns.

Quick-entry accounting account and VAT are review hints only. They remain in the
signed review, native private note and atomic audit; they create no invoice lines,
tax records or accounting postings. The explicit **Create and reconcile** action
uses the existing quick-entry lifecycle with the reviewed supplier, label and
payment mode. Missing configuration or unsupported source facts fail closed.

**Test against last 90 days** is read-only and also works for inactive rules. It
previews the newest 500 imported lines in that period and clearly reports when
the bounded sample is incomplete. It creates no financial records, audit entries,
usage counts, model samples or jobs.

## Installation and rollback

`sql/llx_kreabank_rule.sql` is both the clean-install schema and idempotent upgrade
migration. Existing installations must execute it through the reviewed module
installation/migration procedure using their actual native table prefix. Never
execute DDL from a page request. It creates only the rule table and does not alter
financial records. Repeating the migration preserves rules and counters. Until
the migration is applied, ordinary reconciliation continues without rule advice
and configuration shows an installation notice.

Deploy the rule class, exact decimal helper, configuration page, shared service
integration, selected-line view, descriptor, translations and schema together.
Refresh native permission registration to grant the new configuration right;
do not disable existing scheduled jobs or change scheduler settings. For rollback,
disable the rules and restore the prior source set. Retain the rule table and
audits for recovery; do not drop business data.

## Validation

- `php test/test_rules.php` runs pure condition/regex, priority/conflict and exact
  decimal checks without a database or ERP bootstrap.
- `php test/test_rules_database.php` extends only the existing guarded disposable
  `127.0.0.1:33317/kreabank_auto_test` fixture. It checks migration repeatability,
  entity/reference isolation, configuration audit rollback, manual usage atomicity
  and unchanged automatic approval. Never point it at an installation database.

The configuration design reference is the installed Dolibarr 24 UI documentation
at `/admin/tools/ui/index.php`, the native bank category configuration and KreaBank's
existing `admin/pairs.php`; selected-line cards follow `RECONCILIATION_UI.md`.

Synthetic rendering was checked in Codex's internal browser at 1280 px and 390 px:
configuration action fields, horizontal table scrolling, translated review cards
and intercepted creation submission. No installation database was opened.
