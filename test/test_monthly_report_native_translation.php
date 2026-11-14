#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

// Use installed native translation/formatting without main.inc.php, credentials
// or a database. File-only loading also avoids translation override queries.
$nativeRoot = getenv('KREABANK_DOLIBARR_ROOT') ?: dirname(__DIR__, 3);
if (!is_file($nativeRoot . '/core/class/translate.class.php')) {
	fwrite(STDERR, "Set KREABANK_DOLIBARR_ROOT to an installed Dolibarr source directory.\n");
	exit(2);
}
define('DOL_DOCUMENT_ROOT', $nativeRoot);
define('DOL_URL_ROOT', '');
require_once DOL_DOCUMENT_ROOT . '/core/lib/functions.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/class/translate.class.php';
require_once __DIR__ . '/../lib/kreabank.lib.php';
require_once __DIR__ . '/../lib/kreabank_monthly_report.lib.php';
require_once __DIR__ . '/../class/KreaBankMonthlyReport.class.php';

$conf = (object) array('global' => (object) array(
	'MAIN_MAX_DECIMALS_UNIT' => '8', 'MAIN_MAX_DECIMALS_TOT' => '8', 'MAIN_MAX_DECIMALS_SHOWN' => '8...'
));
$langs = new Translate(DOL_DOCUMENT_ROOT, $conf);
$langs->defaultlang = 'pt_PT';
$langs->dir = array(dirname(__DIR__), DOL_DOCUMENT_ROOT);
$langs->load('main', 2, 0, 'pt_PT', 1);
$langs->load('kreabank', 2, 0, 'pt_PT', 1);

$checks = 0;
function checkMonthlyNativeTranslation($ok, $message)
{
	global $checks;
	if (!$ok) { throw new RuntimeException($message); }
	$checks++;
}

checkMonthlyNativeTranslation($langs->transnoentities('KreaBankMonthlyTitle') === 'Relatórios mensais de reconciliação', 'The installed native loader reads the Portuguese module translation');
checkMonthlyNativeTranslation(strpos($langs->trans('KreaBankMonthlyTitle'), '&oacute;') !== false, 'Native HTML translation exposes the entity-encoding regression condition');

$originalDescription = "Descrição <script>unsafe()</script> <b>original</b> &amp; &oacute; «ação»; \"aspas\"\nsegunda linha";
$formulaDescription = "\t=HYPERLINK(\"https://example.invalid/\",\"ação\")";
$source = array();
foreach (array($originalDescription, $formulaDescription) as $offset => $description) {
	$source[] = array('rowid' => $offset + 1, 'source_storage' => 'staging', 'bank_account_id' => 101,
		'operation_date' => '2026-09-0' . ($offset + 1), 'amount' => '10.12345678', 'currency' => 'EUR',
		'description' => $description, 'payment_reference' => 'Referência &amp; original', 'status' => 0,
		'statement_id' => 1, 'line_rank' => $offset + 1, 'running_balance' => null, 'native_id' => 0, 'expected_native_id' => 0);
}
$report = (new KreaBankMonthlyReport(null, 1))->assemble(
	array('rowid' => 101, 'entity' => 1, 'ref' => 'ST_TEST', 'label' => 'Synthetic account', 'currency_code' => 'EUR'),
	'2026-09', $source, array()
);
$report['company'] = 'Empresa Açúcar & Filhos';
$report['entity'] = 1;
checkMonthlyNativeTranslation($report['map']['balance_date'] === null, 'The fixture exercises a genuinely unavailable bank-balance date');

$stream = fopen('php://temp', 'w+');
kreabankMonthlyReportCsv($stream, $report, $langs);
rewind($stream);
$csv = stream_get_contents($stream);
rewind($stream);
$rows = array();
while (($row = fgetcsv($stream, 0, ';', '"', '')) !== false) { $rows[] = $row; }
fclose($stream);

checkMonthlyNativeTranslation(preg_match('//u', $csv) === 1, 'CSV remains valid UTF-8');
checkMonthlyNativeTranslation($rows[0][0] === 'Relatórios mensais de reconciliação' && $rows[0][1] === $report['company'], 'CSV labels and company identity use original Portuguese characters');
$metadata = array();
$ordinaryRows = array();
$formulaRows = array();
foreach ($rows as $row) {
	if (($row[0] ?? '') === 'staging:1') { $ordinaryRows[] = $row; }
	elseif (($row[0] ?? '') === 'staging:2') { $formulaRows[] = $row; }
	else { $metadata = array_merge($metadata, $row); }
}
checkMonthlyNativeTranslation(!preg_match('/&(?:[a-zA-Z]+|#[0-9]+|#x[0-9a-fA-F]+);/', implode("\n", $metadata)), 'All translated CSV metadata is plain text rather than HTML entities');
checkMonthlyNativeTranslation(in_array($langs->transnoentities('KreaBankMonthlyBankPending'), $metadata, true)
	&& in_array($langs->transnoentities('KreaBankMonthlyDescription'), $metadata, true), 'Section and column labels use the native plain-text translation API');
checkMonthlyNativeTranslation(count($ordinaryRows) === 2 && count($formulaRows) === 2, 'Complete detail and pending sections both retain source evidence');
foreach ($ordinaryRows as $row) {
	checkMonthlyNativeTranslation($row[2] === $originalDescription && $row[3] === 'Referência &amp; original', 'Untrusted descriptions and references are preserved exactly, including literal entities and line breaks');
	checkMonthlyNativeTranslation($row[4] === '10.12345678', 'Native translation does not alter exported financial precision');
}
foreach ($formulaRows as $row) {
	checkMonthlyNativeTranslation($row[2] === "'" . $formulaDescription, 'Plain-text translation retains formula-injection protection on untrusted evidence');
}

$html = kreabankMonthlySummaryHtml($report, $langs);
$basis = $langs->transnoentities('KreaBankMonthlyBalanceBasis', '—');
$htmlText = html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8');
checkMonthlyNativeTranslation(strpos($htmlText, $basis) !== false, 'HTML displays the fallback dash after exactly one native encoding pass');
checkMonthlyNativeTranslation(strpos($html, '&amp;mdash;') === false, 'HTML does not double-encode the unknown-date dash');
$report['map']['balance_date'] = '2026-09-30';
$html = kreabankMonthlySummaryHtml($report, $langs);
checkMonthlyNativeTranslation(strpos(html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8'), $langs->transnoentities('KreaBankMonthlyBalanceBasis', '2026-09-30')) !== false, 'Validated source dates remain correct under the actual native renderer');
$html = kreabankMonthlyDetailsHtml($report['bank_rows'], 'bank', 'Synthetic', array('account_context'=>101, 'month'=>'2026-09'), $langs);
checkMonthlyNativeTranslation(strpos($html, '<script>unsafe()') === false && strpos($html, '&lt;script&gt;unsafe()&lt;/script&gt;') !== false, 'HTML continues to escape untrusted source descriptions separately from translation');

print "OK: $checks native monthly translation/render/CSV checks\n";
