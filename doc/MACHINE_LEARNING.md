# Machine learning

KreaBank 1.18.15 repairs the existing learning paths without introducing a second
matcher or executor. PHP-ML 0.10.0 is bundled through the locked module-local
Composer dependency. Learning requires PHP 8 or later; the module retains its
existing fallback behavior on older supported PHP versions.

## What learns, and what can act

| Path | Evidence | Result |
| --- | --- | --- |
| Import headers | Built-in aliases and approved current-company templates | Proposed column mapping; ambiguous or unfamiliar headers remain for review |
| Batch detection | Confirmed manual reconciliation with its pre-mutation six-feature snapshot | Advisory batch detection in individual reconciliation |
| Supplier prediction | Current-company reconciled supplier outflows, one supplier per movement | Supplier suggestion/auto-fill in the existing user-initiated quick invoice flow |
| Recurring match patterns | Explicit manual or drag/drop confirmation of one document | Bounded bonuses in the existing shared matcher |

Learning does not authorize unattended financial writes. The shared exact amount,
strong identity, score, lead, account, company, currency and execution-time checks
still apply. Automatic native, paired-transfer and TPA results, reviewed bulk
approvals and quick-entry results do not become batch training truth. Multi-link
confirmations do not train an arbitrary first-document recurring pattern.

## Feature integrity and upgrade

Batch features are normalized once: keyword presence, candidate count, coverage,
logarithmic amount, text length and direction. Storage and inference validate the
same six finite values instead of scaling already normalized values again.

New `features_json` rows use a version 2 envelope with `features` and an explicit
native `bank_line_id`. Staging IDs never identify training sources. Loading
revalidates the native movement's current owner and reconciled status, uses only
the newest sample for each movement, and retains observation order. Undo or source
deletion revokes eligibility; a later manual correction supersedes the old label.
The source evidence is retained in the database.

There is no schema migration or bulk retraining write. Unversioned samples remain
stored and are reported as legacy, but are excluded: their original values cannot
be recovered reliably after the former repeated scaling. A batch model remains
unavailable until enough new manual confirmations exist, including at least four
examples of each class and the configured minimum total. Header templates with
current-company ownership remain usable. The old shared `header_templates.json`
is not loaded because it has no company ownership evidence.

## Prediction reliability

- All four configured families use their real bundled implementations: KNN,
  Naive Bayes, Decision Tree and `Classification\Ensemble\RandomForest`.
  KNN remains the existing default; no algorithm is declared universally best.
- Supplier inference requires the existing total sample/class coverage plus at
  least three supporting historical movements for the proposed supplier.
  Checksum-valid IBAN conflicts reject support. Otherwise meaningful matching
  name/text evidence is required; amount similarity alone cannot identify a new
  supplier. Refunds and mixed-company historical links are excluded.
- The supplier classifier and weighted-neighbor winner must agree. Existing
  confidence and runner-up separation requirements still apply, against full
  precision scores rather than rounded display percentages.
- Equidistant neighbors at the neighborhood boundary all contribute, avoiding
  confidence that depends on database ordering. Neighbor agreement is a heuristic
  confidence score, **not a calibrated probability of financial correctness**.
- Header examples are deduplicated. Conflicting labels and competing columns
  abstain; exact approved custom headers can be reused. Fuzzy mappings need both
  sufficient similarity and separation from alternative fields. Training and
  inference use the same unit-length hashed header vectors.

## Validation report

The settings report uses the latest 20 percent of eligible observations as a
chronological holdout. Exact feature groups appearing in holdout are removed
from training, even when their labels differ. Class balancing applies only to
training; holdout retains its observed class distribution. Both partitions must
contain both classes or the report says coverage is insufficient.

Validation applies the same classifier and confidence threshold as batch
inference. It reports accuracy, precision, recall, F1, balanced accuracy, the
training-majority baseline, confusion counts, exclusions and prediction errors.
A failed prediction makes the report unsuccessful; it cannot disappear from the
score denominator. These diagnostics need representative real observations:
passing synthetic regression fixtures does not establish production accuracy or
superiority to another product. No production accuracy benchmark was run for this
source change.

## Storage and operational behavior

Header saves lock the complete read/merge/write operation and atomically replace
JSON, preventing concurrent approved templates from overwriting one another.
Header stores and model caches are written with owner-only file permissions.

Model cache keys cover the full training set, feature version, company, runtime,
locked dependencies and classifier settings. A service rechecks source eligibility
before reusing an in-memory model. Disk restoration is size/depth bounded, rejects
symlinks and permits only the required bundled classifier/distance/tree classes;
unknown serialized classes cannot run wakeup hooks. A missing/incompatible cache
is retrained from eligible samples. Prediction failures return a non-positive
result with an explicit reason. Optional learning never converts a failed sample
save into successful training.

This release changes no schedule, automation enable flag or financial data.
Source publication does not deploy production. Roll back the module source as a
unit if necessary; preserve learning stores and database rows. Older code does
not understand version 2 envelopes, so its batch model may remain unavailable.

## Reproducible checks

Use installed Dolibarr source without bootstrapping an installation:

```sh
KREABANK_DOLIBARR_ROOT=/path/to/dolibarr/htdocs php test/test_machine_learning.php
php test/test_machine_learning_render.php
```

The runtime suite trains and restores all four real classifiers, verifies temporal
validation and failures, checks source/company boundaries, and starts four
concurrent template writers using synthetic temporary data. The rendering suite
executes the shipped report markup with synthetic metrics, including escaping.

For real SQL persistence and ownership checks, use **only** the disposable fixture
in [automatic reconciliation](AUTOMATIC_RECONCILIATION.md#validation):

```sh
KREABANK_DOLIBARR_ROOT=/path/to/dolibarr/htdocs php test/test_machine_learning_database.php
```

That suite recreates only `kbauto_` fixture tables in `kreabank_auto_test` on
`127.0.0.1:33317`. It never loads installation configuration or credentials. It
also runs the native automatic reconciliation regressions. Run the existing
company-isolation, bulk-review, account-pair, TPA and payment-account-review suites
when changing learning inputs or execution boundaries.
