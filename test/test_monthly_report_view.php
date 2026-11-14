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
function kreabankPrice($value) { return number_format((float) $value, 2, ',', ' '); }
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
	public function loadLangs($files) {}
}
$account = array('rowid' => 101, 'entity' => 1, 'ref' => 'ST_TEST', 'label' => 'Synthetic account', 'currency_code' => 'EUR');
$source = array(monthlyBank(1, '2026-08-01', '1000.00', '1000.00', 11), monthlyBank(2, '2026-09-01', '125.50', '1125.50', 12), monthlyBank(3, '2026-09-30', '-15.80', '1109.70'));
$source[2]['description'] = '<script>unsafe()</script> Monthly bank fee';
$native = array(monthlyNative(11, '2026-08-01', '1000.00', true), monthlyNative(12, '2026-09-02', '125.50', true), monthlyNative(13, '2026-09-29', '-50.00'));
$report = $monthly->assemble($account, '2026-09', $source, $native);
$report['company'] = 'Synthetic company';
$report['entity'] = 1;
$state = array('account_context' => 101, 'month' => '2026-09', 'view' => 'summary', 'bank_balance' => '1109.70');
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
$html = kreabankMonthlyDetailsHtml($rows, 'bank', 'Synthetic', array_merge($state, array('bank_page' => 1)), $langs);
monthlyViewCheck(substr_count($html, '&lt;script&gt;') === 50 && strpos($html, '2 / 3') !== false, 'Only the displayed detail page is rendered');
monthlyViewCheck(strpos($html, 'month=2026-09') !== false && strpos($html, 'bank_balance=1109.70') !== false && strpos($html, 'account_context=101') !== false, 'Paging retains the period, official balance and account');
monthlyViewCheck(substr_count(kreabankMonthlyDetailsHtml($rows, 'bank', 'Synthetic', $state, $langs, true), '&lt;script&gt;') === 127, 'Print never truncates pending evidence');
$url = kreabankMonthlyReportUrl(array_merge($state, array('token' => 'secret', 'action' => 'delete', 'kind' => 'cash')));
monthlyViewCheck(strpos($url, 'token') === false && strpos($url, 'action') === false && strpos($url, 'kind') === false, 'Report URLs exclude actions, tokens and unrelated account type state');
monthlyViewCheck(strpos($url, 'mainmenu=bank') !== false && strpos($url, 'leftmenu=kreabank_bankstatement') !== false, 'Every report URL retains native bank menu context');
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
if (($argv[1] ?? '') === '--html') {
	$locale = in_array($argv[2] ?? '', array('en_US', 'pt_PT'), true) ? $argv[2] : 'en_US';
	$view = in_array($argv[3] ?? '', array('summary', 'checks', 'pending', 'bank', 'ledger', 'print'), true) ? $argv[3] : 'summary';
	$langs = new MonthlyViewLangs($locale);
	$state['view'] = $view;
	print '<!doctype html><html lang="' . str_replace('_', '-', $locale) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="http://127.0.0.1:8080/theme/eldy/style.css.php"><style>' . file_get_contents(__DIR__ . '/../css/navigation.css') . file_get_contents(__DIR__ . '/../css/monthly-report.css') . 'body{margin:0}.fixture-shell{display:grid;grid-template-columns:170px minmax(0,1fr)}.fixture-menu{padding:28px 16px;background:#f5f6f7;border-right:1px solid #ddd;line-height:2.5}.fixture-page{padding:24px;min-width:0}.kb-monthly-report table{border-collapse:collapse}.kb-monthly-report td,.kb-monthly-report th{padding:10px}.fixture-note{font-size:12px;color:#59636c}@media(max-width:700px){.fixture-shell{grid-template-columns:minmax(0,1fr)}.fixture-menu{display:none}.fixture-page{padding:14px}}</style></head><body><div class="fixture-shell"><aside class="fixture-menu">Banks<br>Account list<br>Transfers<br>Cheque deposits</aside><main class="fixture-page"><header class="kb-header"><div class="kb-brand"><strong>KreaBank</strong><span class="kb-company">Synthetic company</span></div><nav class="kb-primary"><a>Movements</a><a class="active">Bank statement</a><a>Statements</a><a>Reconciliation</a><a>Connections</a><a>History</a></nav></header><h1>' . $langs->trans('KreaBankMonthlyTitle') . ' — ST_TEST</h1><p class="fixture-note">Synthetic data · No ERP connection</p><form class="kb-monthly-filters"><div><label for="account">Bank account</label><select id="account"><option>ST_TEST</option></select></div><div><label for="month">' . $langs->trans('KreaBankMonthlyPeriod') . '</label><input id="month" type="month" value="2026-09"></div><div><label for="balance">' . $langs->trans('KreaBankMonthlyOfficialBalance') . '</label><input id="balance" inputmode="decimal"><small>' . $langs->trans('KreaBankMonthlyOfficialBalanceHelp') . '</small></div><button type="button" class="button">' . $langs->trans('KreaBankMonthlyGenerate') . '</button></form><nav class="kb-view-switch kb-monthly-views">';
	foreach (array('summary' => 'Summary', 'checks' => 'Checks', 'pending' => 'Pending', 'bank' => 'Bank', 'ledger' => 'Ledger') as $key => $label) { print '<a' . ($key === $view ? ' class="active"' : '') . '>' . $langs->trans('KreaBankMonthly' . $label) . '</a>'; }
	print '</nav>' . kreabankMonthlyReportHtml($report, $state, $langs, $view === 'print') . '</main></div></body></html>';
	exit;
}
print "OK: $checks monthly report view/export checks\n";
