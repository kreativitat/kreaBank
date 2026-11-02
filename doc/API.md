# Read-only REST integration

KreaBank 1.16.0 adds `class/api_kreabank.class.php`, exposing `KreaBankApi`
through Dolibarr's native REST loader at `/api/index.php/kreabankapi`.
The REST API, bank module and KreaBank must already be enabled. Authentication
uses the existing `DOLAPIKEY`; company selection uses native `DOLAPIENTITY`.
No additional credential, public listener or scheduler is introduced.

Every route requires an internal authenticated user with both `banque/lire`
and `kreabank/reconciliation/read`. Account IDs must belong directly to the
current company; Multicompany sharing grants no banking evidence access here.
An unavailable or foreign account returns 404; missing permissions return 403.

| GET route | Parameters | Result |
| --- | --- | --- |
| `/accounts` | Optional `kind=all\|bank\|cash\|closed`, `bank_account_id`, `limit`, `page` | Native balances and pending imported counts per owned account |
| `/statements` | Optional `bank_account_id`, `limit`, `page` | Staged and legacy imports with native/reconciled/issue counts |
| `/statement` | Required `bank_account_id`, `reference` | One exact statement summary |
| `/statement/lines` | Required `bank_account_id`, `reference`; optional `limit`, `page` | Original evidence, separate native links and verified state |
| `/audit` | Optional `limit`, `page` | Company-wide audit metadata; no payload, IP or login |

References are query values, never URL path components or SQL expressions.
List responses contain `entity_id`, `items`, `limit`, `page` and `has_more`.
Limits default to 100, accept 1–500, and use zero-based pages. The database
reads one extra row to establish `has_more`; pages beyond the end stay empty.
There is no implicit full scan or fabricated total. Detail returns
`{"entity_id": 1, "statement": {...}}`; unavailable statements return 404.
Database failures return a sanitized 503, never an empty successful collection.

Monetary database decimals retain their original string precision. Account
balances come from native movements at the current operation date; statement
amounts remain imported evidence. Each account/statement has an explicit currency;
never add balances in different currencies. A staging `rowid` is distinct from
`native_id`. Line `source_storage` is `staging` or `legacy_native`; audit line
and reconciliation IDs always refer to native bank rows. `state` reuses the web
projection's `KreaBankPending`, `KreaBankSkipped`, `KreaBankNativeRegistered`,
`KreaBankReconciled` and `KreaBankLinkNeedsReview` rules. A missing or discrepant
link must remain a review finding, never a verified reconciliation.

These GETs reuse `KreaBankIntegration`, perform no table initialization, matching,
financial writes, feed calls or audit insertion, and expose no POST/PUT/DELETE.
Deploy the KreaBank module before exposing the corresponding KreaMCP tools.
No database migration or module disable/re-enable is required. Respect existing
API endpoint allowlists and the installation's API cache refresh procedure.
Rollback restores the previous module files; refresh API route caches if used.
MCP calls then report an unavailable endpoint until the module is restored.
Source publication alone does not deploy either service.

## Validation

`php test/test_api_database.php` extends the existing automatic-reconciliation
and bank-integration fixture. It connects **only** to disposable local MariaDB
at `127.0.0.1:33317`, database `kreabank_auto_test`, and rebuilds its prefixed
fixture tables. Never point it at an installation database. Set
`KREABANK_DOLIBARR_ROOT` to an installed Dolibarr `htdocs` source directory;
the test uses native AccountLine and Restler without application bootstrap or
credentials. It covers both company contexts, independent permissions,
external-user denial, decimal preservation, staging/legacy namespaces,
discrepancies, pagination, SQL literal escaping, failures, authenticated GET
route registration, denied POST methods and unchanged financial/evidence tables.
