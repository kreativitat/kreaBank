# Fintable bank feeds

KreaBank 1.13 collects posted transactions and bank-reported balances from the
[Fintable API](https://fintable.io/docs). It uses the existing statement staging,
matching and reconciliation service. Collection never creates a payment, pays an
invoice, materializes a native bank movement or adjusts the accounting balance.
Official bank PDF statements are outside this feed.

## Ownership and coverage

One installation-level credential selects an explicit Fintable Workspace. Each
enabled source account must have a checksum-valid IBAN matching exactly one open
native bank account in an active company with KreaBank enabled. Currencies must
agree. Duplicate IBANs, missing IBANs, inactive companies and disconnected banks
are never assigned by a display name or by an account-number suffix.

The Bank feeds page displays only the current company's native accounts. The
shared credential, scheduler and installation-wide coverage page require a
global administrator. Adding a bank connection in Fintable automatically makes
it eligible on the next collection, once its native IBAN mapping exists.

## Schedule and freshness

The existing Dolibarr scheduler checks one global job every 15 minutes. An
installation lock and unique wall-clock slots allow two collections per day,
by default at 08:45 and 20:45 in Europe/Lisbon. The timezone handles daylight
saving changes; a missed morning run can catch up before the evening slot.
Each failed slot has at most three attempts. There is no new host cron or daemon.

Read-only access collects the data already stored in Fintable. The separate
optional refresh setting uses only the included `POST /sync` endpoint, requires
a Fintable write scope and waits for provider sync completion on a later scheduler
tick. Finished banks collect independently while busy banks wait up to 30 minutes
before becoming actionable failures. It never requests a billed realtime refresh. A POST with an unknown outcome
is not repeated automatically. The displayed bank refresh time remains distinct
from the Dolibarr collection time; bank/provider freshness is not guaranteed.

## Storage and recovery

The token stays in the operator's Keychain and is installed via stdin, encrypted
using Dolibarr `dolcrypt` in an entity-0 setting. Configuration refuses plaintext
fallback and never echoes or prefills a saved credential. Keep the instance
encryption key in the installation's established protected backup workflow.

New tables are `kreabank_feed_account`, `kreabank_feed_transaction` and
`kreabank_feed_run`. They contain source mapping, source balances, stable movement
identities, correction evidence and collection status. All financial metadata
carries its native owner entity. They supplement, rather than replace, KreaBank
statement staging. Balances do not become balancing accounting entries.

Pages are bounded and processed outside network-held transactions. Each imported
page and its source identities commit atomically through the existing import
service. An incomplete scan never advances its account's watermark; retries use
a two-day update overlap and stable IDs, including previous provider IDs after
reconnection. Distinct identical-looking transactions stay distinct.

The API supplies a booking date but no separate bank value date; staging uses
the booking date for both fields. History bounds describe actual returned
transactions and do not prove that a period with no transactions is complete.

Description updates can update an unmaterialized pending line. A changed booked
amount/date/currency is retained as a source conflict requiring review; existing
financial rows are not rewritten. One account's failure does not prevent the
other accounts from collecting. Native cron results and account status expose
failures, expiry/reconnect requirements and stale bank data.

## Installation and rollback

Back up the deployed module and affected settings/tables before deployment. Load
the additive SQL through `ensureSchema()` or normal module installation. No
existing statement or reconciliation table is rebuilt. Configure with the
global-administrator setup page or the CLI below; a blank token preserves the
saved credential. Never put a token in arguments or files.

```sh
php scripts/install_fintable.php --root=/path/to/htdocs --login=admin
php scripts/install_fintable.php --root=/path/to/htdocs --login=admin --apply
php scripts/install_fintable.php --root=/path/to/htdocs --login=admin --preview
php scripts/install_fintable.php --root=/path/to/htdocs --login=admin --collect
```

`--apply` reads JSON on stdin: `workspace`, `token` (optional replacement),
`times`, `timezone`, `enabled`, and `refresh`. The default command is read-only.
The setup/CLI updates one global native Cronjob by class/method identity;
disabled legacy GoCardless jobs are left unchanged. Existing module menus can be
refreshed through the module descriptor's native menu registration without
disabling company modules or changing their settings.

For rollback, disable this scheduler/configuration and restore the backed-up
module files. Keep imported staging rows and source identities together; deleting
identities while retaining statements can undermine retry protection. Do not
delete bank, payment or reconciliation data as a rollback shortcut.

Run `php test/test_fintable.php` for pure contract checks and
`php test/test_fintable_database.php` against the disposable local MariaDB test
instance described by that test. Production acceptance additionally requires a
successful collection, a duplicate-free replay and unchanged financial tables.

## Isolated container installations

When an isolated clone deliberately keeps its general scheduler disabled, use
the optional [Compose overlay](../deploy/fintable/compose.example.yaml) and
companion worker/loop files. Do not add this worker where an existing scheduler
already runs the Fintable job. The overlay expects the installation's internal
`test` network and an existing allowlisted HTTPS proxy. Adapt mount sources to
the actual running application, including a separate custom-module mount when
present. Confirm the container reads the intended files and matching hashes.

The worker accepts exactly one enabled installation-level native job matching
the Fintable class, method, module, class path and empty parameters. It passes
only that job's identifier to Dolibarr's native CLI scheduler. The scheduler key
stays in PHP memory; neither it nor the Fintable token belongs in command-line
arguments, deployment files or logs. Set `FINTABLE_RUNNER_LOGIN` to an existing
authorized global administrator; the script's compatibility default is
`DeGema_Bot`. The collector's own company and source checks still apply.

Keep the application's existing image and database environment injection. Leave
general `DOLI_CRON` disabled and other cloned jobs disabled. Allow only Fintable
HTTPS through the proxy. Run the worker as the application user with read-only
source mounts, writable documents, bounded resources and logs, and no published
ports. Override the Apache image's inherited stop signal with `SIGTERM`; the loop
stops sleeping promptly and lets an active native job finish during the grace
period. Its heartbeat reports runner availability, while bank/provider failures
remain visible in KreaBank and native cron status.

A secondary installation can use a read-only token and collect after the primary
installation's refresh window, avoiding duplicate provider-refresh requests.
This deployment adapter is compatible with the 1.13 feed engine; it does not
enable automatic reconciliation or upgrade the application's business logic.
Before enabling it, back up local configuration and bank identifiers, check
unique IBAN/currency ownership, and verify a real scheduled run, a duplicate-free
replay, graceful restart and unchanged unrelated scheduled jobs. Preserve actual
opening entries when restoring missing bank cards; never synthesize balances.
