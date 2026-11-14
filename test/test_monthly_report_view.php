#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Synthetic renderer and export checks; no application bootstrap or database.
 */
error_reporting(E_ALL);
set_error_handler(static function ($severity, $message) { throw new RuntimeException($message); });
ob_start();
require __DIR__ . '/test_monthly_report.php';
ob_end_clean();
require_once __DIR__ . '/../lib/kreabank_monthly_report.lib.php';
function dol_escape_htmltag($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function dol_buildpath($path, $absolute = 0) { return $path; }
function price($value, ...$options) { return number_format((float) $value, 2, ',', ' '); }
function getTitleFieldOfList($label, $thead=0, $file='', $field='', $begin='', $params='', $attributes='', $sortfield='', $sortorder='', $prefix='', $disabled=0) {
	return '<th class="' . $prefix . 'liste_titre" ' . $attributes . '>' . dol_escape_htmltag($label) . '</th>';
}
function print_barre_liste($title, $page, $file, $params, $sortfield='', $sortorder='', $center='', $num=-1, $total='', $picto='', $full=0, $right='', $css='', $limit=25, $suffix=0) {
	print '<div class="fixture-native-pager" data-page="' . $page . '" data-total="' . $total . '" data-num="' . $num . '">' . dol_escape_htmltag($title);
	print '<input type="hidden" name="pageplusoneold" value="' . ($page+1) . '"><select class="selectlimit" id="limit' . $suffix . '" name="' . $suffix . '"><option selected value="' . $limit . '">' . $limit . '</option></select>';
	if ($page > 0 || $num > $limit) {
		print '<input name="pageplusone" value="' . ($page+1) . '">';
		if ($page > 0) { print '<a class="paginationprevious" href="' . $file . '?page=' . ($page-1) . $params . '">Previous</a>'; }
		if ($num > $limit) { print '<a class="paginationnext" href="' . $file . '?page=' . ($page+1) . $params . '">Next</a>'; }
	}
	print '</div>';
}
class Form {
	public function __construct($db) {}
	public function showFilterButtons() { return '<button type="submit" name="button_search_x" value="x">Search</button><button type="submit" name="button_removefilter_x" value="x">Reset</button>'; }
}
$conf = (object) array('liste_limit'=>25);
$db = null;
define('DOL_DOCUMENT_ROOT', getenv('KREABANK_DOLIBARR_ROOT') ?: dirname(__DIR__, 3));
require_once __DIR__ . '/../lib/kreabank.lib.php';
class MonthlyViewLangs
{
	private $messages = array();
	public function __construct($locale) {
		foreach (array('en_US', $locale) as $lang) {
			foreach (file(__DIR__ . '/../langs/' . $lang . '/kreabank.lang', FILE_IGNORE_NEW_LINES) as $line) {
				if (strpos($line, '=') !== false && substr($line, 0, 1) !== '#') { list($key, $value) = explode('=', $line, 2); $this->messages[$key] = $value; }
			}
		}
	}
	public function trans($key, ...$args) { return $args ? vsprintf($this->messages[$key] ?? $key, $args) : ($this->messages[$key] ?? $key); }
	public function transnoentities($key, ...$args) { return $this->trans($key, ...$args); }
	public function loadLangs($files) {}
	public function load($file) {}
}
$account = array('rowid' => 101, 'entity' => 1, 'ref' => 'ST_TEST', 'label' => 'Synthetic account', 'currency_code' => 'EUR');
$source = array(monthlyBank(1, '2026-08-01', '1000.00', '1000.00', 11), monthlyBank(2, '2026-09-01', '125.50', '1125.50', 12), monthlyBank(3, '2026-09-30', '-15.80', '1109.70'));
$source[2]['description'] = '<script>unsafe()</script> Monthly bank fee';
$native = array(monthlyNative(11, '2026-08-01', '1000.00', true), monthlyNative(12, '2026-09-02', '125.50', true), monthlyNative(13, '2026-09-29', '-50.00'));
$report = $monthly->assemble($account, '2026-09', $source, $native);
$report['company'] = 'Synthetic company';
$report['entity'] = 1;
$state = array('account_context' => 101, 'month' => '2026-09', 'view' => 'summary', 'bank_balance' => '1109.70', 'balance_account'=>101, 'balance_month'=>'2026-09');
$checks = 0;
function monthlyViewCheck($ok, $message) { global $checks; if (!$ok) { throw new RuntimeException($message); } $checks++; }
foreach (array('en_US', 'pt_PT') as $locale) {
	$langs = new MonthlyViewLangs($locale);
	foreach (array('summary', 'checks', 'pending', 'bank', 'ledger') as $view) {
		$html = kreabankMonthlyReportHtml($report, array_merge($state, array('view' => $view)), $langs);
		monthlyViewCheck(strpos($html, 'KreaBankMonthly') === false, 'All monthly labels are translated: ' . $locale . '/' . $view);
		monthlyViewCheck(strpos($html, '<script>unsafe') === false, 'Evidence is escaped: ' . $locale . '/' . $view);
	}
	$print = kreabankMonthlyReportHtml($report, $state, $langs, true);
	monthlyViewCheck(strpos($print, 'kb-monthly-checks') !== false && strpos($print, 'kb-monthly-ledger') !== false && strpos($print, 'kb-monthly-bank') !== false, 'Print includes map, checks and both complete open lists');
}
$langs = new MonthlyViewLangs('en_US');
$rows = array_fill(0, 127, $report['bank_pending'][0]);
$html = kreabankMonthlyDetailsHtml($rows, 'bank', 'Synthetic', array_merge($state, array('ml_bank_page' => 1)), $langs);
monthlyViewCheck(substr_count($html, '&lt;script&gt;') === 25 && strpos($html, 'data-page="1" data-total="127" data-num="26"') !== false, 'Only the native configured detail page is rendered, with a next-page sentinel');
monthlyViewCheck(strpos($html, 'month=2026-09') !== false && strpos($html, 'bank_balance=1109.70') !== false && strpos($html, 'account_context=101') !== false, 'Paging retains the period, official balance and account');
monthlyViewCheck(substr_count(kreabankMonthlyDetailsHtml($rows, 'bank', 'Synthetic', $state, $langs, true), '&lt;script&gt;') === 127, 'Print never truncates pending evidence');
$url = kreabankMonthlyReportUrl(array_merge($state, array('token' => 'secret', 'action' => 'delete', 'kind' => 'cash')));
monthlyViewCheck(strpos($url, 'token') === false && strpos($url, 'action') === false && strpos($url, 'kind') === false, 'Report URLs exclude actions, tokens and unrelated account type state');
monthlyViewCheck(strpos($url, 'mainmenu=bank') !== false && strpos($url, 'leftmenu=kreabank_monthlyreport') !== false, 'Every report URL retains native bank menu context');
monthlyViewCheck(kreabankMonthlyScopedBalance('15.00', 101, '2026-09', 101, '2026-09') === '15.00', 'Entered official balance stays bound to its displayed account and period');
monthlyViewCheck(kreabankMonthlyScopedBalance('15.00', 102, '2026-09', 101, '2026-09') === '' && kreabankMonthlyScopedBalance('15.00', 101, '2026-10', 101, '2026-09') === '', 'Account or month changes discard an old official balance server-side');
monthlyViewCheck(kreabankMonthlyScopedBalance('15.00', 101, '2026-09', 0, '') === '', 'Unscoped URL balance cannot silently become confirmed');
foreach (array('=1+1', "\t=1+1", '+SUM(A1)', '-cmd', '@formula') as $value) { monthlyViewCheck(kreabankMonthlyCsvCell($value) === "'" . $value, 'Unsafe spreadsheet text is neutralized'); }
monthlyViewCheck(kreabankMonthlyCsvCell('-15.80000001') === '-15.80000001', 'Exact negative decimals remain numeric CSV values');
$stream = fopen('php://temp', 'w+');
kreabankMonthlyReportCsv($stream, $report, $langs);
rewind($stream); $csv = stream_get_contents($stream); fclose($stream);
monthlyViewCheck(strpos($csv, '-15.80000000') !== false && strpos($csv, '1109.70000000') !== false, 'CSV exports original signed decimal precision');
monthlyViewCheck(strpos($csv, 'historical close') !== false && strpos($csv, 'unconfirmed') !== false && strpos($csv, 'general-ledger') !== false, 'CSV retains evidence and ledger limitations');
$csvHeader = str_getcsv(strtok($csv, "\n"), ';', '"', '');
monthlyViewCheck(array_slice($csvHeader, 1) === array('Synthetic company', '1', 'ST_TEST', '101', '2026-09', 'EUR') && strpos(kreabankMonthlyReportHtml($report, $state, $langs, true), 'Synthetic company') !== false, 'CSV and print retain explicit company and account identity');
$controller = file_get_contents(__DIR__ . '/../monthlyreport.php');
monthlyViewCheck(strpos($controller, "!empty(\$user->socid)") !== false && strpos($controller, "hasRight('banque', 'lire')") !== false && strpos($controller, "hasRight('kreabank', 'reconciliation', 'read')") !== false, 'Controller retains both read rights and external-user denial');
monthlyViewCheck(strpos($controller, 'KreaBankService') === false && strpos($controller, '->load($accountId, $month, $bankBalance)') !== false, 'Read-only controller uses the report loader only');
// Execute the shipped authorization guard, without running the native bootstrap.
function isModEnabled($module) { return !in_array($module, $GLOBALS['monthlyDisabledModules'] ?? array(), true); }
function accessforbidden() { throw new LogicException('Forbidden'); }
class MonthlyViewUser {
	public $socid = 0;
	public $denied = '';
	public function hasRight(...$parts) { return implode('.', $parts) !== $this->denied; }
}
$guard = substr($controller, strpos($controller, "if (!isModEnabled('kreabank')"));
$guard = substr($guard, 0, strpos($guard, '$accountId ='));
foreach (array('external', 'banque.lire', 'kreabank.reconciliation.read', 'disabled') as $denial) {
	$user = new MonthlyViewUser(); $monthlyDisabledModules = array();
	if ($denial === 'external') { $user->socid = 12; }
	elseif ($denial === 'disabled') { $monthlyDisabledModules = array('kreabank'); }
	else { $user->denied = $denial; }
	$denied = false; try { eval($guard); } catch (LogicException $e) { $denied = true; }
	monthlyViewCheck($denied, 'Real report authorization denies ' . $denial);
}
$user = new MonthlyViewUser(); $monthlyDisabledModules = array(); eval($guard);
monthlyViewCheck(true, 'Owned internal reader with both rights passes the real authorization guard');

foreach (array('en_US','pt_PT') as $locale) {
	$langs = new MonthlyViewLangs($locale);
	$controls = kreabankMonthlyFiltersHtml($state, $langs);
	$previousLibxml = libxml_use_internal_errors(true);
	$dom = new DOMDocument(); $dom->loadHTML('<?xml encoding="utf-8" ?>' . $controls); $xp = new DOMXPath($dom);
	libxml_clear_errors(); libxml_use_internal_errors($previousLibxml);
	monthlyViewCheck($xp->query('//select')->length === 0 && $xp->query('//input[@id="kreabank-account-context" and @name="account_context" and @value="101"]')->length === 1, 'Scoped toolbar fixes the current owned account without another account switch');
	monthlyViewCheck($xp->query('//input[@id="kb-monthly-balance-account" and @value="101"]')->length === 1 && $xp->query('//input[@id="kb-monthly-balance-month" and @value="2026-09"]')->length === 1, 'Compact controls retain explicit balance binding');
	monthlyViewCheck(strpos($controls . kreabankMonthlyActionsHtml($state,$langs) . kreabankMonthlyViewsHtml($state,$langs), 'KreaBankMonthly') === false, 'All compact toolbar/view labels are translated');
	$unscoped = kreabankMonthlyFiltersHtml(array_merge($state,array('account_context'=>0)), $langs, '<label for="kreabank-account-context">Account</label><select id="kreabank-account-context" name="account_context"><option value="101">ST_TEST</option></select>');
	monthlyViewCheck(strpos($unscoped,'<select') !== false && strpos($unscoped,'type="hidden" id="kreabank-account-context"') === false, 'Unscoped reports retain their provided owned-account selector');
}

// Exercise real prepared arrays through the shared filter/sort/page boundary.
$langs = new MonthlyViewLangs('en_US');
$detailRows = array(
	array('id'=>'one','date'=>'2026-09-01','description'=>"O'Brien 100%_literal",'reference'=>'PAY-001','amount'=>'1.00000001','balance'=>null,'status'=>'pending','age'=>29),
	array('id'=>'two','date'=>'2026-09-02','description'=>'Ordinary 100XXliteral','reference'=>'PAY-002','amount'=>'1.00000002','balance'=>'9999999999999999.12345678','status'=>'matched','age'=>null),
	array('id'=>'three','date'=>'','description'=>'Unknown evidence','reference'=>'OTHER','amount'=>null,'balance'=>null,'status'=>'review','age'=>90)
);
$identity = static function ($row) { return $row; };
$originalDetails = serialize($detailRows);
$filterCases = array(
	array('description'=>"O'Brien 100%_", 'expected'=>array('one')),
	array('description'=>'100%_', 'expected'=>array('one')),
	array('reference'=>'PAY-002', 'expected'=>array('two')),
	array('date_from'=>'2026-09-02','date_to'=>'2026-09-02', 'expected'=>array('two')),
	array('date_from'=>'2026-02-30', 'expected'=>array()),
	array('amount'=>'=1,00000001', 'expected'=>array('one')),
	array('amount'=>'<>1.00000001', 'expected'=>array('two')),
	array('amount'=>'—', 'expected'=>array('three')),
	array('amount'=>'1 OR 1=1', 'expected'=>array()),
	array('balance'=>'9999999999999999.12345678', 'expected'=>array('two')),
	array('balance'=>'>9999999999999999.12345677', 'expected'=>array('two')),
	array('status'=>'review', 'expected'=>array('three')),
	array('status'=>'not-a-status', 'expected'=>array()),
	array('age'=>'>30', 'expected'=>array('three')),
	array('age'=>'1.5', 'expected'=>array())
);
foreach ($filterCases as $case) {
	$expected = $case['expected']; unset($case['expected']); $request = array();
	foreach ($case as $field=>$value) { $request['ml_bank_search_' . $field] = $value; }
	$list = kreabankMonthlyListPrepare($detailRows, 'bank', $request, $identity);
	monthlyViewCheck(array_column($list['rows'],'id') === $expected && $list['total'] === count($expected), 'Filtered rows and count agree: ' . json_encode($case));
}
$list = kreabankMonthlyListPrepare($detailRows, 'bank', array('ml_bank_sortfield'=>'amount','ml_bank_sortorder'=>'DESC'), $identity);
monthlyViewCheck(array_column($list['rows'],'id') === array('two','one','three'), 'Eight-place numeric sorting keeps unknown amounts last');
$list = kreabankMonthlyListPrepare($detailRows, 'bank', array('ml_bank_sortfield'=>'description;DROP','ml_bank_sortorder'=>'bad','ml_bank_page'=>PHP_INT_MAX), $identity);
monthlyViewCheck($list['sortfield'] === 'date' && $list['sortorder'] === 'ASC' && $list['page'] === 0, 'Unknown sorts and extreme pages normalize safely');
monthlyViewCheck(serialize($detailRows) === $originalDetails, 'Presentation filters and sorting never mutate prepared source rows');
$normalized = kreabankMonthlyListRequestState(array('ml_bank_page'=>3,'ml_bank_limit'=>99999,'ml_bank_search_amount'=>'<>1.00000001',
	'ml_ledger_page'=>2,'ml_ledger_search_description'=>'keep','ml_bank_button_removefilter.x'=>'x','token'=>'secret','ml_unknown_search_id'=>'9'));
monthlyViewCheck($normalized['ml_bank_page'] === 0 && $normalized['ml_bank_limit'] === 5000 && !isset($normalized['ml_bank_search_amount'])
	&& $normalized['ml_ledger_page'] === 2 && $normalized['ml_ledger_search_description'] === 'keep', 'One table reset preserves the other table and bounds the requested size');
monthlyViewCheck(!isset($normalized['token'], $normalized['ml_unknown_search_id']), 'List state never admits unowned namespaces or actions');
$boundedState = kreabankMonthlyListRequestState(array('ml_bank_search_description'=>str_repeat('á',300)));
monthlyViewCheck(mb_strlen($boundedState['ml_bank_search_description'],'UTF-8') === 256, 'Carried text filters are bounded without splitting UTF-8 characters');
$jump = kreabankMonthlyListState(array('ml_bank_pageplusone'=>3,'ml_bank_pageplusoneold'=>2,'ml_bank_button_search_x'=>'x'), 'bank');
$search = kreabankMonthlyListState(array('ml_bank_pageplusone'=>3,'ml_bank_pageplusoneold'=>3,'ml_bank_button_search_x'=>'x'), 'bank');
monthlyViewCheck($jump['page'] === 2 && $search['page'] === 0, 'Native page jumps win over implicit Search while explicit search resets only its table');
$conf->liste_limit = 50;
monthlyViewCheck(kreabankMonthlyListPrepare(array_fill(0,127,$detailRows[0]),'bank',array(),$identity)['limit'] === 50, 'The native configured page size supplies the default');
$conf->liste_limit = 25;
$mixedChecks = array(array('label'=>'Count','status'=>'ok','value'=>'2'), array('label'=>'Date','status'=>'warning','value'=>'2026-09-30'), array('label'=>'Unknown','status'=>'warning','value'=>''));
foreach (array('>1'=>array('Count'),'2026-09'=>array('Date'),'—'=>array('Unknown')) as $filter=>$expected) {
	$list = kreabankMonthlyListPrepare($mixedChecks,'checks',array('ml_checks_search_value'=>$filter),$identity);
	monthlyViewCheck(array_column($list['rows'],'label') === $expected, 'Mixed check values retain numeric/date/unknown semantics: ' . $filter);
}
$indicatorRows = array(array('label'=>'Open items','bank'=>2,'ledger'=>3), array('label'=>'Matched items','bank'=>4,'ledger'=>1));
$list = kreabankMonthlyListPrepare($indicatorRows,'indicators',array('ml_indicators_search_label'=>'items','ml_indicators_search_bank'=>'>=2','ml_indicators_search_ledger'=>'<2'),$identity);
monthlyViewCheck(count($list['rows']) === 1 && $list['rows'][0]['label'] === 'Matched items', 'Each indicator data column filters before count and paging');
$reportBefore = serialize($report);
foreach (array('summary'=>array('map','indicators'),'checks'=>array('checks'),'pending'=>array('bank_pending','ledger_pending'),'bank'=>array('bank'),'ledger'=>array('ledger')) as $view=>$keys) {
	$html = kreabankMonthlyReportHtml($report, array_merge($state,array('view'=>$view)), $langs);
	$previousLibxml = libxml_use_internal_errors(true); $dom = new DOMDocument(); $dom->loadHTML($html); $xp = new DOMXPath($dom); libxml_clear_errors(); libxml_use_internal_errors($previousLibxml);
	monthlyViewCheck($xp->query('//form//form')->length === 0 && $xp->query('//form')->length === count($keys), 'Each ' . $view . ' table has its own non-nested GET owner');
	$ids = array(); foreach ($xp->query('//*[@id]') as $element) { $ids[] = $element->getAttribute('id'); }
	monthlyViewCheck(count($ids) === count(array_unique($ids)), 'Native controls have unique IDs in ' . $view);
	foreach ($keys as $key) {
		$prefix = kreabankMonthlyListPrefix($key);
		monthlyViewCheck($xp->query('//form[@id="kb-monthly-list-' . $key . '"]//button[starts-with(@name,"' . $prefix . 'button_")]')->length === 2, 'Native buttons belong to ' . $key);
		monthlyViewCheck($xp->query('//table[@id="kb-monthly-table-' . $key . '"]/thead/tr[@class="liste_titre"]//a')->length === count(kreabankMonthlyListDefinitions()[$key]), 'Every data column has a native sortable header in ' . $key);
		foreach (kreabankMonthlyListDefinitions()[$key] as $field=>$type) {
			foreach ($type === 'date' ? array($field . '_from',$field . '_to') : array($field) as $filter) {
				monthlyViewCheck($xp->query('//form[@id="kb-monthly-list-' . $key . '"]//*[@name="' . $prefix . 'search_' . $filter . '"]')->length === 1, 'One meaningful header control for ' . $key . '/' . $filter);
			}
		}
	}
}
$manyReport = $report;
$manyReport['bank_pending'] = $rows;
$manyReport['ledger_pending'] = array_fill(0,53,$report['ledger_pending'][0]);
$listState = array_merge($state,array('view'=>'pending','ml_bank_pending_page'=>1,'ml_bank_pending_limit'=>25,
	'ml_ledger_pending_page'=>2,'ml_ledger_pending_limit'=>10,'ml_bank_pending_search_amount'=>'>999999','token'=>'secret','action'=>'undo'));
$html = kreabankMonthlyReportHtml($manyReport,$listState,$langs);
monthlyViewCheck(strpos($html,'data-page="0" data-total="0"') !== false && strpos($html,'data-page="2" data-total="53" data-num="11"') !== false, 'The two pending lists filter/count/page independently');
$previousLibxml = libxml_use_internal_errors(true); $dom = new DOMDocument(); $dom->loadHTML($html); $xp = new DOMXPath($dom); libxml_clear_errors(); libxml_use_internal_errors($previousLibxml);
foreach ($xp->query('//form[@class="kb-monthly-list-form"]//a') as $link) {
	parse_str((string)parse_url($link->getAttribute('href'),PHP_URL_QUERY),$params);
	if (($params['leftmenu'] ?? '') !== 'kreabank_monthlyreport') { continue; }
	monthlyViewCheck(($params['account_context'] ?? '') === '101' && ($params['month'] ?? '') === '2026-09' && ($params['bank_balance'] ?? '') === '1109.70'
		&& ($params['balance_account'] ?? '') === '101' && ($params['balance_month'] ?? '') === '2026-09' && ($params['view'] ?? '') === 'pending'
		&& !isset($params['page'],$params['sortfield'],$params['token'],$params['action']), 'Read links retain report identity/balance/view and no generic or write state');
}
$print = kreabankMonthlyReportHtml($manyReport,$listState,$langs,true);
monthlyViewCheck(substr_count($print,'&lt;script&gt;') === 127 && strpos($print,'fixture-native-pager') === false && strpos($print,'<form') === false,
	'Print ignores filters and pages, with complete pending evidence and no list controls');
monthlyViewCheck(strpos($print,'kb-monthly-table-map') !== false && strpos($print,'kb-monthly-table-indicators') !== false && strpos($print,'kb-monthly-table-checks') !== false
	&& strpos($print,'kb-monthly-table-ledger_pending') !== false, 'Print retains the complete map, indicators, checks and both pending tables');
monthlyViewCheck(serialize($report) === $reportBefore, 'Filtering every view preserves all report facts and KPI totals');
monthlyViewCheck(strpos($controller,'kreabankMonthlyListRequestState(array_merge($_GET, $_POST)') !== false
	&& strpos($controller,'->load($accountId, $month, $bankBalance)') !== false, 'Controller confines unrounded list inputs to presentation state, outside the financial loader');
if (($argv[1] ?? '') === '--html') {
	$locale = in_array($argv[2] ?? '', array('en_US', 'pt_PT'), true) ? $argv[2] : 'en_US';
	$view = in_array($argv[3] ?? '', array('summary', 'checks', 'pending', 'bank', 'ledger', 'print'), true) ? $argv[3] : 'summary';
	$langs = new MonthlyViewLangs($locale);
	$state['view'] = $view;
	$user = new MonthlyViewUser();
	$accountView = (object) array('id'=>101,'ref'=>'ST_TEST','label'=>'Synthetic account','clos'=>0);
	print '<!doctype html><html lang="' . str_replace('_', '-', $locale) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="theme.css"><style>' . file_get_contents(__DIR__ . '/../css/navigation.css') . file_get_contents(__DIR__ . '/../css/monthly-report.css') . 'body{margin:0;font-family:Arial,sans-serif;color:#253746;font-size:14px}.fixture-shell{display:grid;grid-template-columns:170px minmax(0,1fr)}.fixture-menu{padding:28px 16px;background:#f5f6f7;border-right:1px solid #ddd;line-height:2.5}.fixture-page{padding:24px;min-width:0}.fixture-note{font-size:12px;color:#59636c}@media(max-width:700px){.fixture-shell{grid-template-columns:minmax(0,1fr)}.fixture-menu{display:none}.fixture-page{padding:14px}}</style></head><body><div class="fixture-shell"><aside class="fixture-menu">Banks<br>New financial account<br>Movements<br>Transfers<br>Cheque deposits</aside><main class="fixture-page">';
	print kreabankBankingHeader('monthlyreport', $accountView, false);
	print '<div class="kb-monthly-heading"><h1>' . $langs->trans('KreaBankMonthlyShortTitle') . '</h1>' . kreabankMonthlyActionsHtml($state, $langs, $view === 'print') . '</div>';
	print kreabankMonthlyFiltersHtml($state, $langs);
	if ($view !== 'print') { print kreabankMonthlyViewsHtml($state, $langs); }
	print kreabankMonthlyReportHtml($report, $state, $langs, $view === 'print') . '</main></div><script>' . file_get_contents(__DIR__ . '/../js/monthly-report.js') . '</script></body></html>';

	exit;
}
print "OK: $checks monthly report view/export checks\n";
