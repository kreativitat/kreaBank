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
require_once DOL_DOCUMENT_ROOT . '/core/class/html.form.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/ajax.lib.php';
require_once __DIR__ . '/../lib/kreabank.lib.php';
require_once __DIR__ . '/../lib/kreabank_monthly_report.lib.php';
require_once __DIR__ . '/../class/KreaBankMonthlyReport.class.php';

$conf = (object) array('liste_limit'=>25,'currency'=>'EUR','use_javascript_ajax'=>0,'dol_optimize_smallscreen'=>0,
	'file'=>(object) array('dol_document_root'=>array('main'=>DOL_DOCUMENT_ROOT)), 'browser'=>(object) array('layout'=>'classic','name'=>'fixture'), 'global' => (object) array(
	'MAIN_MAX_DECIMALS_UNIT' => '8', 'MAIN_MAX_DECIMALS_TOT' => '8', 'MAIN_MAX_DECIMALS_SHOWN' => '8...'
));
$db = null;
$langs = new Translate(DOL_DOCUMENT_ROOT, $conf);
$langs->defaultlang = 'pt_PT';
$langs->dir = array(dirname(__DIR__), DOL_DOCUMENT_ROOT);
$langs->load('main', 2, 0, 'pt_PT', 1);
$langs->load('kreabank', 2, 0, 'pt_PT', 1);
foreach (array('banks','bills','salaries','trips','donations','loan') as $domain) { $langs->load($domain, 2, 0, 'pt_PT', 1); }

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

// Exercise installed Form, header and pager helpers, including both native limit modes.
$report['ledger_pending'] = $report['bank_pending'];
$nativeState = array('account_context'=>101,'month'=>'2026-09','view'=>'bank_pending','bank_balance'=>'20.24691356','balance_account'=>101,'balance_month'=>'2026-09',
	'ml_bank_pending_limit'=>1,'ml_ledger_pending_limit'=>1,'ml_bank_pending_sortfield'=>'amount','ml_bank_pending_sortorder'=>'DESC');
foreach (array(0,1) as $html5) {
	$conf->global->MAIN_USE_HTML5_LIMIT_SELECTOR = $html5;
	foreach (array('bank_pending','ledger_pending') as $key) {
	$html = kreabankMonthlyReportHtml($report,array_merge($nativeState,array('view'=>$key)),$langs);
	$previousLibxml = libxml_use_internal_errors(true); $dom = new DOMDocument(); $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html); $xp = new DOMXPath($dom); libxml_clear_errors(); libxml_use_internal_errors($previousLibxml);
	checkMonthlyNativeTranslation($xp->query('//form')->length === 1 && $xp->query('//form//form')->length === 0
		&& $xp->query('//table[@id="kb-monthly-table-' . $key . '"]')->length === 1, 'Installed native helpers render only the active pending subtab (HTML5=' . $html5 . ')');
	$ids = array(); foreach ($xp->query('//*[@id]') as $element) { $ids[] = $element->getAttribute('id'); }
	checkMonthlyNativeTranslation(count($ids) === count(array_unique($ids)), 'Installed native limit controls and datalists have no duplicate IDs (HTML5=' . $html5 . ')');
	checkMonthlyNativeTranslation($xp->query('//*[@name="limit" or @name="pageplusone" or @name="pageplusoneold" or @name="button_search_x" or @name="button_removefilter_x"]')->length === 0,
		'Native generic control names cannot leak between two table namespaces');
		$prefix = kreabankMonthlyListPrefix($key);
		checkMonthlyNativeTranslation($xp->query('//form[@id="kb-monthly-list-' . $key . '"]//*[@name="' . $prefix . 'limit"]')->length === 1
			&& $xp->query('//form[@id="kb-monthly-list-' . $key . '"]//*[@name="' . $prefix . 'pageplusone"]')->length === 1, 'Native page size and page jump belong to ' . $key);
		$nextLinks = $xp->query('//form[@id="kb-monthly-list-' . $key . '"]//a[contains(@class,"paginationnext")]');
		checkMonthlyNativeTranslation($nextLinks->length === 1, 'Native limit+1 sentinel exposes the next page for ' . $key);
		parse_str((string) parse_url($nextLinks->item(0)->getAttribute('href'),PHP_URL_QUERY),$next);
		checkMonthlyNativeTranslation(($next[$prefix . 'page'] ?? '') === '1' && ($next['account_context'] ?? '') === '101' && ($next['month'] ?? '') === '2026-09'
			&& ($next['bank_balance'] ?? '') === '20.24691356' && ($next['balance_account'] ?? '') === '101' && ($next['balance_month'] ?? '') === '2026-09' && ($next['view'] ?? '') === $key
			&& !isset($next['page'],$next['sortfield'],$next['token'],$next['action']), 'Actual native next links preserve bound report context and only their own page namespace');
	checkMonthlyNativeTranslation(strpos($html,'&amp;oacute;') === false && strpos($html,'&amp;atilde;') === false, 'Native table headers and aria labels encode translated Portuguese once');
	}
}
$filteredState = array_merge($nativeState,array('ml_bank_pending_search_amount'=>'<>10.12345678','ml_ledger_pending_search_description'=>'«ação»'));
$filtered = kreabankMonthlyReportHtml($report,$filteredState,$langs);
checkMonthlyNativeTranslation(strpos($filtered,'name="ml_bank_pending_search_amount" value="&lt;&gt;10.12345678"') !== false,
	'Comparison operators survive raw allowlisted presentation inputs and HTML escaping');
$print = kreabankMonthlyReportHtml($report,$filteredState,$langs,true);
checkMonthlyNativeTranslation(substr_count($print,'&lt;script&gt;unsafe()&lt;/script&gt;') === 2 && strpos($print,'<form') === false,
	'Actual native translations preserve both full pending lists during filtered printing');

$markerKeys = array('banktransfert'=>'BankTransfer','paiement'=>'Payment','SupplierInvoicePayment'=>'SupplierInvoicePayment','CustomerInvoicePayment'=>'CustomerInvoicePayment',
	'SocialContributionPayment'=>'SocialContributionPayment','VariousPayment'=>'VariousPayment','SalaryPayment'=>'SalaryPayment','payment_salary'=>'SalaryPayment',
	'ExpenseReportPayment'=>'ExpenseReportPayment','DonationPayment'=>'DonationPayment','LoanPayment'=>'LoanPayment','WithdrawalPayment'=>'WithdrawalPayment','BankTransferPayment'=>'BankTransferPayment');
foreach ($markerKeys as $marker=>$key) {
	$translated = $langs->transnoentities($key);
	checkMonthlyNativeTranslation($translated !== $key && kreabankWorklistNativeLabel('(' . $marker . ')',$langs) === $translated, 'Monthly display has a native Portuguese translation for ' . $marker);
	$prose = 'Original note mentions (' . $marker . ') inside arbitrary source prose';
	checkMonthlyNativeTranslation(kreabankWorklistNativeLabel($prose,$langs) === $prose, 'A native marker substring does not rewrite arbitrary prose: ' . $marker);
}
$displayReport = $report;
$displayReport['bank_rows'][0]['description'] = '(CustomerInvoicePayment)';
$displayReport['bank_pending'][0] = $displayReport['bank_rows'][0];
$ledgerRow = $displayReport['bank_rows'][0];
$ledgerRow['id'] = 'native:99'; $ledgerRow['rowid'] = 99; $ledgerRow['source_storage'] = 'native'; $ledgerRow['native_id'] = 99;
$ledgerRow['description'] = '(SupplierInvoicePayment)';
$ledgerRow['reference'] = 'acc_' . str_repeat('0123456789abcdef',24) . '_gocardless';
$displayReport['ledger_pending'] = array($ledgerRow); $displayReport['ledger_rows'] = array($ledgerRow);
$displayBefore = serialize($displayReport);
$stream=fopen('php://temp','w+'); kreabankMonthlyReportCsv($stream,$displayReport,$langs); rewind($stream); $beforeDisplayCsv=stream_get_contents($stream); fclose($stream);
foreach (array('summary','checks','bank_pending','ledger_pending','bank','ledger') as $view) {
	$html = kreabankMonthlyReportHtml($displayReport,array_merge($nativeState,array('view'=>$view)),$langs);
	$visible = html_entity_decode(strip_tags($html),ENT_QUOTES,'UTF-8');
	checkMonthlyNativeTranslation(!preg_match('/\bKreaBank[A-Za-z]+|\((?:SupplierInvoicePayment|CustomerInvoicePayment)\)|\b(?:staging|native|legacy_native):\d+/',$visible),
		'Every monthly subtab localizes native descriptions and source identity using actual native translations: ' . $view);
	if (in_array($view,array('summary','checks'),true)) {
		$previousLibxml=libxml_use_internal_errors(true); $dom=new DOMDocument(); $dom->loadHTML($html); $xp=new DOMXPath($dom); libxml_clear_errors(); libxml_use_internal_errors($previousLibxml);
		checkMonthlyNativeTranslation($xp->query('//form|//input|//select|//button|//tr[@class="liste_titre_filter"]')->length === 0,
			'Actual native ' . $view . ' displays compact static tables without list controls');
		$expected = $view === 'summary' ? array('map'=>9,'indicators'=>5) : array('checks'=>count($displayReport['checks']));
		foreach ($expected as $key=>$count) {
			checkMonthlyNativeTranslation($xp->query('//table[@id="kb-monthly-table-' . $key . '"]/tbody/tr')->length === $count,
				'Actual native static ' . $key . ' keeps every summary/check row');
		}
	}
}
$html = kreabankMonthlyDetailsHtml(array($ledgerRow),'ledger','Synthetic',array_merge($nativeState,array('ml_ledger_search_description'=>$langs->transnoentities('SupplierInvoicePayment'))),$langs);
checkMonthlyNativeTranslation(strpos($html,dol_escape_htmltag($langs->transnoentities('SupplierInvoicePayment'))) !== false && strpos($html,'<tbody><tr class="oddeven">') !== false,
	'Filtering uses the same plain Portuguese native description that the row displays');
checkMonthlyNativeTranslation(strpos($html,dol_escape_htmltag($langs->transnoentities('KreaBankHistoryNativeEntry') . ' #99')) !== false && strpos($html,'native:99') === false,
	'Native source identities use a translated bank-entry label rather than an internal namespace');
checkMonthlyNativeTranslation(strpos($html,$ledgerRow['reference']) !== false && strpos($html,'class="kb-monthly-reference"') !== false,
	'Complete long references remain in their own wrapping detail column');
$stream=fopen('php://temp','w+'); kreabankMonthlyReportCsv($stream,$displayReport,$langs); rewind($stream); $rawCsv=stream_get_contents($stream); fclose($stream);
checkMonthlyNativeTranslation(strpos($rawCsv,'(SupplierInvoicePayment)') !== false && strpos($rawCsv,'native:99') !== false && strpos($rawCsv,$ledgerRow['reference']) !== false
	&& $rawCsv === $beforeDisplayCsv && serialize($displayReport) === $displayBefore, 'UI localization changes neither original exported evidence nor report facts');

print "OK: $checks native monthly translation/render/CSV checks\n";
