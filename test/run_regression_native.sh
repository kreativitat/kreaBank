#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "${ROOT_DIR}"

echo "[1/7] PHP lint"
php_files=(
  "import.php"
  "pending.php"
  "reconcile.php"
  "history.php"
  "kreabankindex.php"
  "lib/kreabank.lib.php"
  "class/KreaBankService.class.php"
  "class/KreaBankParser.class.php"
  "class/KreaBankNativeBankAdapter.class.php"
  "class/parser/KreaBankParseStrategyInterface.class.php"
  "class/parser/KreaBankCsvParseStrategy.class.php"
  "class/parser/KreaBankExcelParseStrategy.class.php"
  "class/parser/KreaBankCamt053ParseStrategy.class.php"
  "class/parser/KreaBankOfxParseStrategy.class.php"
  "class/parser/KreaBankMt940ParseStrategy.class.php"
  "class/parser/KreaBankNorma43ParseStrategy.class.php"
  "class/parser/KreaBankDelimitedTextParseStrategy.class.php"
  "core/modules/modKreaBank.class.php"
  "test/test_reconciliation_guards.php"
)

for f in "${php_files[@]}"; do
  php -l "${ROOT_DIR}/${f}" >/dev/null
done
echo "OK: PHP syntax"

echo "[2/7] Legacy route guards"
if rg -n "dol_buildpath\('/kreabank/" --glob "*.php" "${ROOT_DIR}" >/dev/null; then
  echo "FAIL: Found legacy UI route '/kreabank/...'. Use '/custom/kreabank/...'."
  rg -n "dol_buildpath\('/kreabank/" --glob "*.php" "${ROOT_DIR}" || true
  exit 1
fi
if rg -n "/compta/bank/index\\.php" --glob "*.php" "${ROOT_DIR}" >/dev/null; then
  echo "FAIL: Found invalid native bank URL '/compta/bank/index.php'."
  rg -n "/compta/bank/index\\.php" --glob "*.php" "${ROOT_DIR}" || true
  exit 1
fi
echo "OK: No legacy/invalid UI routes"

echo "[3/7] Endpoint smoke checks (container)"
container="${KREABANK_HTTP_CONTAINER:-lamp-php81}"
if command -v docker >/dev/null 2>&1 && docker ps --format '{{.Names}}' | grep -qx "${container}"; then
  mapfile -t lines < <(
    docker exec "${container}" bash -lc '
      for u in /custom/kreabank/import.php /custom/kreabank/reconcile.php /custom/kreabank/pending.php /compta/bank/list.php /kreabank/import.php; do
        code=$(curl -s -o /dev/null -w "%{http_code}" "http://localhost$u")
        echo "$u $code"
      done
    '
  )

  for line in "${lines[@]}"; do
    path="${line% *}"
    code="${line##* }"
    echo "  ${path} -> ${code}"
    if [[ "${code}" == "500" ]]; then
      echo "FAIL: HTTP 500 on ${path}"
      exit 1
    fi
    if [[ "${path}" == "/custom/kreabank/import.php" || "${path}" == "/custom/kreabank/reconcile.php" || "${path}" == "/custom/kreabank/pending.php" ]]; then
      if [[ "${code}" != "200" && "${code}" != "302" ]]; then
        echo "FAIL: Unexpected status ${code} on ${path}"
        exit 1
      fi
    fi
  done
  echo "OK: HTTP smoke checks"
else
  echo "WARN: Docker container '${container}' not running. Skipping HTTP smoke checks."
fi

echo "[4/7] Strategy wiring check"
if rg -n "/parser/KreaBank.*ParseStrategy" "${ROOT_DIR}/class/KreaBankParser.class.php" >/dev/null 2>&1; then
  echo "OK: Strategy parser wiring present"
else
  echo "FAIL: Strategy parser wiring missing"
  exit 1
fi

echo "[5/7] Format consistency fixtures"
php "${ROOT_DIR}/test/test_format_consistency.php"

echo "[6/7] Reconciliation guard checks"
php "${ROOT_DIR}/test/test_reconciliation_guards.php"

echo "[7/7] Manual checklist"
cat <<'EOF'
[ ] Import via /custom/kreabank/import.php and confirm new lines exist in /compta/bank/list.php (native).
[ ] Reconcile via /custom/kreabank/reconcile.php and confirm native line shows reconciled in /compta/bank/line.php.
[ ] Reimport the same file and confirm no duplicate native bank entries are created.
[ ] Validate permissions:
    - User without banque->lire cannot access KreaBank pages.
    - User without banque->modifier cannot import/reconcile write actions.
[ ] Validate multi-entity: import/reconcile in one entity does not leak to another entity.
EOF

echo "DONE: regression smoke finished."
