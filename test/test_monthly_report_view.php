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
	private $locale;
	public function __construct($locale) {
		$this->locale = $locale;
		foreach (array('en_US', $locale) as $lang) {
			foreach (file(__DIR__ . '/../langs/' . $lang . '/kreabank.lang', FILE_IGNORE_NEW_LINES) as $line) {
				if (strpos($line, '=') !== false && substr($line, 0, 1) !== '#') { list($key, $value) = explode('=', $line, 2); $this->messages[$key] = $value; }
			}
		}
		$this->loadLangs(array('main','banks','bills','salaries','trips','donations','loan'));
	}
	public function trans($key, ...$args) { return $args ? vsprintf($this->messages[$key] ?? $key, $args) : ($this->messages[$key] ?? $key); }
	public function transnoentities($key, ...$args) { return $this->trans($key, ...$args); }
	public function loadLangs($files) {
		foreach ($files as $file) {
			foreach (array('en_US',$this->locale) as $locale) {
				$path = DOL_DOCUMENT_ROOT . '/langs/' . $locale . '/' . $file . '.lang';
				if (!is_file($path)) { continue; }
				foreach (file($path,FILE_IGNORE_NEW_LINES) as $line) {
					if (strpos($line,'=') !== false && substr($line,0,1) !== '#') { list($key,$value)=explode('=',$line,2); $this->messages[$key]=$value; }
				}
			}
		}
	}
	public function load($file) { $this->loadLangs(array($file)); }
}
$account = array('rowid' => 101, 'entity' => 1, 'ref' => 'ST_TEST', 'label' => 'Synthetic account', 'currency_code' => 'EUR');
$source = array(monthlyBank(1, '2026-08-01', '1000.00', '1000.00', 11), monthlyBank(2, '2026-09-01', '125.50', '1125.50', 12), monthlyBank(3, '2026-09-30', '-15.80', '1109.70'));
$source[2]['description'] = '<script>unsafe()</script> Monthly bank fee';
$source[2]['payment_reference'] = 'acc_' . str_repeat('0123456789abcdef',16) . '_gocardless';
$native = array(monthlyNative(11, '2026-08-01', '1000.00', true), monthlyNative(12, '2026-09-02', '125.50', true), monthlyNative(13, '2026-09-29', '-50.00'));
$native[2]['description'] = '(SupplierInvoicePayment)';
$report = $monthly->assembleWithBalances($account, '2026-09', $source, $native);
$report['company'] = 'Synthetic company';
$report['entity'] = 1;
$state = array('account_context' => 101, 'month' => '2026-09', 'view' => 'summary', 'bank_balance' => '1109.70', 'balance_account'=>101, 'balance_month'=>'2026-09');
$checks = 0;
function monthlyViewCheck($ok, $message) { global $checks; if (!$ok) { throw new RuntimeException($message); } $checks++; }
foreach (array('en_US', 'pt_PT') as $locale) {
	$langs = new MonthlyViewLangs($locale);
	foreach (array('balances', 'summary', 'checks', 'bank_pending', 'ledger_pending', 'bank', 'ledger') as $view) {
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
monthlyViewCheck(strpos($controller, 'KreaBankService') === false && strpos($controller, '->load($accountId, $month, $bankBalance,') !== false, 'Read-only controller uses the report loader only');
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
$reportBefore = serialize($report);
foreach (array('summary'=>array(),'checks'=>array(),'bank_pending'=>array('bank_pending'),'ledger_pending'=>array('ledger_pending'),'bank'=>array('bank'),'ledger'=>array('ledger')) as $view=>$keys) {
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
$obsoleteSummaryState = array('ml_map_search_label'=>'absent','ml_map_sortfield'=>'amount','ml_map_sortorder'=>'DESC','ml_map_page'=>999,'ml_map_limit'=>1,
	'ml_indicators_search_bank'=>'>999','ml_indicators_page'=>999,'ml_indicators_limit'=>1,'ml_checks_search_status'=>'absent','ml_checks_page'=>999,'ml_checks_limit'=>1);
foreach (array('summary'=>array('map'=>9,'indicators'=>5),'checks'=>array('checks'=>count($report['checks']))) as $view=>$tables) {
	$html = kreabankMonthlyReportHtml($report,array_merge($state,$obsoleteSummaryState,array('view'=>$view)),$langs);
	$previousLibxml = libxml_use_internal_errors(true); $dom=new DOMDocument(); $dom->loadHTML($html); $xp=new DOMXPath($dom); libxml_clear_errors(); libxml_use_internal_errors($previousLibxml);
	monthlyViewCheck($xp->query('//form|//input|//select|//button|//tr[@class="liste_titre_filter"]')->length === 0 && strpos($html,'fixture-native-pager') === false,
		'Static ' . $view . ' has no native filters, controls or pager');
	foreach ($tables as $key=>$count) {
		monthlyViewCheck($xp->query('//table[@id="kb-monthly-table-' . $key . '" and contains(@class,"kb-monthly-static-table")]/tbody/tr')->length === $count
			&& $xp->query('//table[@id="kb-monthly-table-' . $key . '"]/thead//a')->length === 0, 'Complete original ' . $key . ' rows ignore stale filters, sorting and pages');
	}
}
$withoutObsolete = kreabankMonthlyListRequestState($obsoleteSummaryState + array('ml_bank_page'=>2,'ml_bank_search_description'=>'kept'));
monthlyViewCheck(!array_intersect_key($withoutObsolete,$obsoleteSummaryState) && $withoutObsolete['ml_bank_page'] === 2 && $withoutObsolete['ml_bank_search_description'] === 'kept',
	'Only transaction tables participate in canonical list state');
$cleanUrl = kreabankMonthlyReportUrl(array_merge($state,$obsoleteSummaryState,array('ml_bank_search_description'=>'kept')));
monthlyViewCheck(strpos($cleanUrl,'ml_map_') === false && strpos($cleanUrl,'ml_indicators_') === false && strpos($cleanUrl,'ml_checks_') === false
	&& strpos($cleanUrl,'ml_bank_search_description=kept') !== false, 'Report links discard obsolete summary/check list state while retaining transaction filters');
$manyReport = $report;
$manyReport['bank_pending'] = $rows;
$manyReport['ledger_pending'] = array_fill(0,53,$report['ledger_pending'][0]);
$listState = array_merge($state,array('view'=>'bank_pending','ml_bank_pending_page'=>1,'ml_bank_pending_limit'=>25,
	'ml_ledger_pending_page'=>2,'ml_ledger_pending_limit'=>10,'ml_bank_pending_search_amount'=>'>999999','token'=>'secret','action'=>'undo'));
foreach (array('bank_pending','ledger_pending') as $pendingView) {
	$html = kreabankMonthlyReportHtml($manyReport,array_merge($listState,array('view'=>$pendingView)),$langs);
	$otherView = $pendingView === 'bank_pending' ? 'ledger_pending' : 'bank_pending';
	monthlyViewCheck(strpos($html,'id="kb-monthly-table-' . $pendingView . '"') !== false && strpos($html,'id="kb-monthly-table-' . $otherView . '"') === false,
		'Each pending subtab renders only its own transactions: ' . $pendingView);
	monthlyViewCheck(strpos($html,$pendingView === 'bank_pending' ? 'data-page="0" data-total="0"' : 'data-page="2" data-total="53" data-num="11"') !== false,
		'The pending subtab retains its independent filters/count/page: ' . $pendingView);
	$previousLibxml = libxml_use_internal_errors(true); $dom = new DOMDocument(); $dom->loadHTML($html); $xp = new DOMXPath($dom); libxml_clear_errors(); libxml_use_internal_errors($previousLibxml);
	foreach ($xp->query('//form[@class="kb-monthly-list-form"]//a') as $link) {
		parse_str((string)parse_url($link->getAttribute('href'),PHP_URL_QUERY),$params);
		if (($params['leftmenu'] ?? '') !== 'kreabank_monthlyreport') { continue; }
		monthlyViewCheck(($params['account_context'] ?? '') === '101' && ($params['month'] ?? '') === '2026-09' && ($params['bank_balance'] ?? '') === '1109.70'
			&& ($params['balance_account'] ?? '') === '101' && ($params['balance_month'] ?? '') === '2026-09' && ($params['view'] ?? '') === $pendingView
			&& !isset($params['page'],$params['sortfield'],$params['token'],$params['action']), 'Read links retain report identity/balance/current subtab and no write state');
	}
}
$print = kreabankMonthlyReportHtml($manyReport,$listState,$langs,true);
monthlyViewCheck(substr_count($print,'&lt;script&gt;') === 127 && strpos($print,'fixture-native-pager') === false && strpos($print,'<form') === false,
	'Print ignores filters and pages, with complete pending evidence and no list controls');
monthlyViewCheck(strpos($print,'kb-monthly-table-map') !== false && strpos($print,'kb-monthly-table-indicators') !== false && strpos($print,'kb-monthly-table-checks') !== false
	&& strpos($print,'kb-monthly-table-ledger_pending') !== false, 'Print retains the complete map, indicators, checks and both pending tables');
monthlyViewCheck(serialize($report) === $reportBefore, 'Filtering every view preserves all report facts and KPI totals');
monthlyViewCheck(strpos($controller,'kreabankMonthlyListRequestState(array_merge($_GET, $_POST)') !== false
	&& strpos($controller,"->load(\$accountId, \$month, \$bankBalance, \$view === 'balances')") !== false, 'Controller confines unrounded list inputs to presentation state, outside the financial loader');

monthlyViewCheck(kreabankMonthlyReportView('pending') === 'bank_pending' && kreabankMonthlyReportView('not-a-view') === 'balances', 'Legacy pending bookmarks resolve to the bank subtab and default views open bank/ERP balances');
$legacyHtml = kreabankMonthlyReportHtml($report,array_merge($state,array('view'=>'pending')),$langs);
monthlyViewCheck(strpos($legacyHtml,'id="kb-monthly-table-bank_pending"') !== false && strpos($legacyHtml,'id="kb-monthly-table-ledger_pending"') === false,
	'Legacy pending bookmarks display only the bank side');
$navState = array_merge($listState,array('view'=>'pending','ml_ledger_pending_search_status'=>'pending'));
foreach (array('en_US'=>array('Pending bank','Pending ERP'),'pt_PT'=>array('Pendentes do banco','Pendentes ERP')) as $locale=>$shortLabels) {
	$langs = new MonthlyViewLangs($locale);
	$navigation=kreabankMonthlyViewsHtml($navState,$langs);
	$previousLibxml=libxml_use_internal_errors(true); $dom=new DOMDocument(); $dom->loadHTML('<?xml encoding="utf-8" ?>' . $navigation); $xp=new DOMXPath($dom); libxml_clear_errors(); libxml_use_internal_errors($previousLibxml);
	monthlyViewCheck($xp->query('//nav/a')->length === 7 && $xp->query('//nav/a[@aria-current="page"]')->length === 1, 'Seven report subtabs have exactly one active state in ' . $locale);
	$labels=array(); $views=array();
	foreach ($xp->query('//nav/a') as $link) {
		parse_str((string)parse_url($link->getAttribute('href'),PHP_URL_QUERY),$params); $views[]=$params['view']; $labels[$params['view']]=$link->textContent;
		monthlyViewCheck(($params['account_context'] ?? '') === '101' && ($params['month'] ?? '') === '2026-09' && ($params['bank_balance'] ?? '') === '1109.70'
			&& ($params['balance_account'] ?? '') === '101' && ($params['balance_month'] ?? '') === '2026-09' && ($params['ml_ledger_pending_page'] ?? '') === '2'
			&& ($params['ml_ledger_pending_search_status'] ?? '') === 'pending' && ($params['ml_bank_pending_search_amount'] ?? '') === '>999999'
			&& !isset($params['token'],$params['action']), 'Changing subtabs preserves report scope and independent transaction state without replaying writes');
	}
	monthlyViewCheck($views === array('balances','summary','checks','bank_pending','ledger_pending','bank','ledger')
		&& $labels['bank_pending'] === $shortLabels[0] && $labels['ledger_pending'] === $shortLabels[1], 'Pending tabs have the requested short translated labels and distinct identities in ' . $locale);
}
$langs = new MonthlyViewLangs('en_US');
foreach (array('bank_pending','ledger_pending') as $pendingView) {
	$activeState=array_merge($listState,array('view'=>$pendingView));
	$print=kreabankMonthlyReportHtml($manyReport,$activeState,$langs,true);
	monthlyViewCheck(substr_count($print,'&lt;script&gt;') === 127 && strpos($print,'id="kb-monthly-table-bank_pending"') !== false
		&& strpos($print,'id="kb-monthly-table-ledger_pending"') !== false && strpos($print,'<form') === false, 'Print from either pending subtab retains both complete sides despite active filters/pages');
	foreach (array('export'=>'csv','print_report'=>1) as $action=>$value) {
		parse_str((string)parse_url(kreabankMonthlyReportUrl($activeState,array($action=>$value)),PHP_URL_QUERY),$params);
		monthlyViewCheck(($params['view'] ?? '') === $pendingView && (string)$params[$action] === (string)$value
			&& ($params['bank_balance'] ?? '') === '1109.70' && ($params['balance_account'] ?? '') === '101', 'CSV/print links preserve exact scope from ' . $pendingView);
	}
}
monthlyViewCheck(strpos($controller,"kreabankMonthlyReportView(GETPOST('view', 'aZ09'))") !== false, 'Actual controller uses the same canonical view and compatibility alias as the renderer');

// All detail lists share widths and translate only reserved display tokens.
foreach (array('en_US','pt_PT') as $locale) {
	$langs = new MonthlyViewLangs($locale);
	foreach (array('balances','summary','checks','bank_pending','ledger_pending','bank','ledger') as $view) {
		$html = kreabankMonthlyReportHtml($report,array_merge($state,array('view'=>$view)),$langs);
		$text = html_entity_decode(strip_tags($html),ENT_QUOTES,'UTF-8');
		monthlyViewCheck(!preg_match('/\bKreaBank[A-Za-z]+|\(SupplierInvoicePayment\)|\b(?:staging|native|legacy_native):\d+/',$text), 'No raw module keys, native placeholders or source namespaces in ' . $locale . '/' . $view);
	}
	$translated = $langs->transnoentities('SupplierInvoicePayment');
	$html = kreabankMonthlyDetailsHtml($report['ledger_pending'],'ledger','Synthetic',array_merge($state,array('ml_ledger_search_description'=>$translated)),$langs);
	monthlyViewCheck(strpos($html,'data-total="1"') !== false && strpos($html,dol_escape_htmltag($translated)) !== false, 'Description search matches the visible native translation in ' . $locale);
	$sourceLabel = $langs->transnoentities('KreaBankReviewStatementLine') . ' #3';
	$html = kreabankMonthlyDetailsHtml($report['bank_pending'],'bank','Synthetic',array_merge($state,array('ml_bank_search_description'=>$sourceLabel)),$langs);
	monthlyViewCheck(strpos($html,'data-total="1"') !== false && strpos($html,dol_escape_htmltag($sourceLabel)) !== false, 'Source-identity search matches the visible translated label in ' . $locale);
	$referenceRow = $report['bank_pending'][0]; $referenceRow['reference'] = '(banktransfert)';
	$html = kreabankMonthlyDetailsHtml(array($referenceRow),'bank','Synthetic',array_merge($state,array('ml_bank_search_reference'=>$langs->transnoentities('BankTransfer'))),$langs);
	monthlyViewCheck(strpos($html,'data-total="1"') !== false && strpos($html,'(banktransfert)') === false, 'Reference search uses the same translated text as its visible cells in ' . $locale);
}
$langs = new MonthlyViewLangs('pt_PT');
$prose = 'Original note contains (SupplierInvoicePayment), acc_token and literal staging:123 text';
monthlyViewCheck(kreabankWorklistNativeLabel($prose,$langs) === $prose, 'Arbitrary source prose containing reserved words is preserved exactly');
$pendingHtml = kreabankMonthlyReportHtml($report,array_merge($state,array('view'=>'bank_pending')),$langs);
monthlyViewCheck(strpos($pendingHtml,$source[2]['payment_reference']) !== false, 'Long bank references remain complete rather than clipped or abbreviated');
$columnSets = array();
foreach (array('bank_pending','ledger_pending') as $pendingView) {
	$pendingHtml=kreabankMonthlyReportHtml($report,array_merge($state,array('view'=>$pendingView)),$langs);
	$previousLibxml = libxml_use_internal_errors(true); $dom = new DOMDocument(); $dom->loadHTML($pendingHtml); $xp = new DOMXPath($dom); libxml_clear_errors(); libxml_use_internal_errors($previousLibxml);
	foreach ($xp->query('//table[contains(@class,"kb-monthly-detail-table")]') as $table) {
		$columns = array(); foreach ($xp->query('./colgroup/col',$table) as $column) { $columns[] = $column->getAttribute('class'); } $columnSets[] = $columns;
	}
}
monthlyViewCheck(count($columnSets) === 2 && count($columnSets[0]) === 8 && $columnSets[0] === $columnSets[1], 'Both separate pending subtabs retain identical detail column definitions');
$stream = fopen('php://temp','w+'); kreabankMonthlyReportCsv($stream,$report,$langs); rewind($stream); $originalCsv=stream_get_contents($stream); fclose($stream);
monthlyViewCheck(strpos($originalCsv,'(SupplierInvoicePayment)') !== false && strpos($originalCsv,'staging:3') !== false && strpos($originalCsv,'native:13') !== false
	&& strpos($originalCsv,$source[2]['payment_reference']) !== false, 'CSV retains original placeholders, source identities and complete long references');
monthlyViewCheck(serialize($report) === $reportBefore, 'Translated presentation leaves all original report evidence and amounts unchanged');
if (($argv[1] ?? '') === '--html') {
	$locale = in_array($argv[2] ?? '', array('en_US', 'pt_PT'), true) ? $argv[2] : 'en_US';
	$view = ($argv[3] ?? '') === 'print' ? 'print' : kreabankMonthlyReportView($argv[3] ?? 'summary');
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
