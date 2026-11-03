# Reconciliation payment indexes

Large native payment tables without secondary indexes can make each proposal scan
the entire customer and supplier payment history. Diagnose with read-only query
timings and `EXPLAIN` before applying this maintenance. No matching rule or candidate
limit changes are required.

`scripts/optimize_reconciliation.php` is CLI-only and requires an active installation
administrator. It plans four nonunique indexes across the existing `paiement` and
`paiementfourn` tables: `(entity,datep,fk_bank)` for scoped date searches and
`(fk_bank,entity)` for bank/payment joins. Equivalent active BTREE indexes are reused;
conflicting definitions fail closed. The web controller never applies this DDL.

```sh
php scripts/optimize_reconciliation.php --root=/dolibarr/htdocs --login=admin
php scripts/optimize_reconciliation.php --root=/dolibarr/htdocs --login=admin \
  --apply --backup-dir=/private/kreabank-index-maintenance
```

The backup directory must already exist outside the webroot and contain no previous
`payment-index-journal.json`. Review the plan, confirm sufficient free disk space and
retain the private journal. Apply serializes maintenance and refuses an active
KreaBank job. It supports MariaDB 10.3+ with existing InnoDB tables and uses explicit
`ALGORITHM=INPLACE, LOCK=NONE`; unsupported online DDL fails without a table-copy or
blocking fallback. The session metadata-lock wait is capped at five seconds and
restored afterwards. Index construction consumes resources and briefly needs metadata
locks; schedule it appropriately for the installation's traffic. See MariaDB's
[online index documentation](https://mariadb.com/docs/server/server-usage/storage-engines/innodb/innodb-online-ddl/innodb-online-ddl-operations-with-the-inplace-alter-algorithm).

DDL is nontransactional. Each attempt is journaled before and after execution. If
interrupted, inspect `SHOW INDEX` and the journal before any rerun; an `applying`
event without `applied` has an unknown outcome. A fresh read-only plan establishes
the actual state. Use a new private journal directory for a deliberate rerun.
Rollback uses only the `rollback` statements for indexes this run actually added,
after confirming their exact definitions. Never drop reused native indexes or
restore business tables to roll back this maintenance. Partial success remains
valid and recoverable; the script does not blindly retry or remove indexes.

Validate before/after payment, invoice, bank and audit rows, candidate identities,
account-review warnings, query plans and representative page timings. No payment,
reconciliation, module reactivation or scheduler change is part of this operation.

```sh
# ONLY disposable localhost:33317/kreabank_auto_test, never an installation database:
php test/test_review_indexes_database.php
```
