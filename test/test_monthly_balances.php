#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
ob_start();
require __DIR__ . '/test_monthly_report_view.php';
ob_end_clean();
$checks = 0;
$account = array('rowid'=>101,'entity'=>1,'ref'=>'TEST','label'=>'Synthetic account','currency_code'=>'EUR');
$source = array(monthlyBank(1,'2026-01-31','100.00000001','100.00000001',11),
	monthlyBank(2,'2026-02-28','20.00000002','120.00000003'));
$native = array(monthlyNative(11,'2026-01-31','100.00000001',true), monthlyNative(12,'2026-02-28','-5.00000001'));
$r = $monthly->assembleWithBalances($account,'2026-03',$source,$native,'130.00000004');
monthlyViewCheck(array_column($r['balances'],'date') === array('2026-01-31','2026-02-28','2026-03-31'), 'One month-end row from January through selected cutoff, including inactive months');
monthlyViewCheck(array_column($r['balances'],'bank') === array('100.00000001','120.00000003','130.00000004')
	&& array_column($r['balances'],'confirmed') === array(false,false,true), 'Entered official balance applies only to its selected month');
monthlyViewCheck(array_column($r['balances'],'difference') === array('0.00000000','25.00000003','35.00000004'), 'Workbook differences subtract raw ledger close from bank close at exact source precision');
monthlyViewCheck($r['bank_pending_total'] === '20.00000002' && count($r['bank_pending']) === 1 && $r['map']['difference'] !== $r['balances'][2]['difference'], 'Carried bank pending total stays independent of ERP-only items and adjusted differences');
$withoutBalance = $source; foreach ($withoutBalance as &$row) { $row['running_balance'] = null; } unset($row);
$unknown = $monthly->assembleWithBalances($account,'2026-02',$withoutBalance,$native);
monthlyViewCheck($unknown['balances'][0]['bank'] === null && $unknown['balances'][1]['difference'] === null, 'Missing bank anchors remain unknown instead of using ledger or zero');
$future = monthlyBank(3,'2026-03-01','invalid'); $future['currency'] = 'USD';
$futureNative = monthlyNative(13,'2026-03-01','invalid'); $futureNative['currency'] = 'USD';
$invalid = $monthly->assembleWithBalances($account,'2026-03',array_merge($source,array($future)),array_merge($native,array($futureNative)));
monthlyViewCheck($invalid['balances'][0]['bank'] === '100.00000001' && $invalid['balances'][1]['ledger'] === '95.00000000'
	&& $invalid['balances'][2]['difference'] === null && $invalid['bank_pending_total'] === null, 'Later malformed evidence cannot invalidate earlier months and cannot produce a partial pending total');
$foreignCurrency = $source; $foreignCurrency[1]['currency'] = 'USD';
$foreign = $monthly->assembleWithBalances($account,'2026-02',$foreignCurrency,$native);
monthlyViewCheck($foreign['bank_pending_total'] === null, 'A mixed-currency pending list has no apparently complete monetary total');
$futureClaim = monthlyBank(4,'2026-03-01','100.00000001',null,11);
$claimed = $monthly->assembleWithBalances($account,'2026-03',array_merge($source,array($futureClaim)),$native);
monthlyViewCheck($claimed['balances'][0]['comment'] === 'EvidenceReview', 'Future competing claims remain fenced even after month filtering');
$boundary = array('entity'=>1,'fk_bank_account'=>101,'currency'=>'EUR','start_date'=>'2026-02-01',
	'opening_balance'=>'100.00000001','ledger_balance'=>'100.00000001','adjustment_amount'=>'0','fk_adjustment_bank_line'=>0,'statement_ref'=>'OPEN');
$opened = $monthly->assembleWithBalances($account,'2026-03',$source,$native,'',$boundary);
monthlyViewCheck($opened['balances'][0]['bank'] === '100.00000001' && $opened['balances'][1]['bank'] === '120.00000003', 'Opening applicability is evaluated separately for each month');
try { $monthly->assembleWithBalances(array_merge($account,array('entity'=>2)),'2026-03',$source,$native); throw new RuntimeException('Foreign report accepted'); }
catch (InvalidArgumentException $e) { monthlyViewCheck(true, 'Foreign owner account remains denied'); }

$r['company'] = 'Synthetic company';
$r['bank_pending'][0]['description'] = '=HYPERLINK("https://example.invalid") <script>unsafe</script>';
$r['bank_pending'][0]['reference'] = 'Original reference';
$state = array('account_context'=>101,'month'=>'2026-03','view'=>'balances','bank_balance'=>'130.00000004','balance_account'=>101,'balance_month'=>'2026-03');
foreach (array('en_US','pt_PT') as $locale) {
	$langs = new MonthlyViewLangs($locale);
	$html = kreabankMonthlyReportHtml($r,$state,$langs);
	monthlyViewCheck(strpos($html,'KreaBankMonthly') === false && strpos($html,'<script>unsafe') === false, 'New report translations and evidence escaping: ' . $locale);
	monthlyViewCheck(strpos($html,'id="kb-monthly-table-balances"') !== false && strpos($html,'id="kb-monthly-table-balances_pending"') !== false
		&& strpos($html,'kb-monthly-totals') === false && strpos($html,'id="kb-monthly-table-map"') === false, 'First report shows precisely the workbook sections without duplicated KPI/map panels');
	$facts = kreabankMonthlyBalancesFacts($r['bank_pending'][0],$langs,$account['currency_code']);
	monthlyViewCheck($facts['origin_month'] === '2026-02' && $facts['nature'] === $langs->transnoentities('KreaBankMonthlyReceipt') && $facts['comment'] === '', 'Source month and receipt direction come from evidence; accountant comments are not invented');
	$filtered = array_merge($state,array('ml_balances_pending_search_amount'=>'>9999'));
	$screen = kreabankMonthlyReportHtml($r,$filtered,$langs);
	$print = kreabankMonthlyReportHtml($r,$filtered,$langs,true);
	monthlyViewCheck(strpos($screen,'&lt;script&gt;') === false && strpos($print,'&lt;script&gt;') !== false && strpos($print,'<form') === false
		&& strpos($print,'<tfoot>') !== false, 'Print retains complete pending rows and total despite screen filters');
	$stream = fopen('php://temp','w+'); kreabankMonthlyBalancesCsv($stream,$r,$langs); rewind($stream); $csv = stream_get_contents($stream); fclose($stream);
	monthlyViewCheck(strpos($csv,"'=HYPERLINK") !== false && strpos($csv,'staging:2') !== false && strpos($csv,'35.00000004') !== false
		&& strpos($csv,'20.00000002') !== false, 'CSV retains exact differences, original stable IDs and formula-safe evidence');
	$foreignHtml = kreabankMonthlyReportHtml($foreign,$state,$langs);
	$stream=fopen('php://temp','w+'); kreabankMonthlyBalancesCsv($stream,$foreign,$langs); rewind($stream); $foreignCsv=stream_get_contents($stream); fclose($stream);
	monthlyViewCheck(strpos($foreignHtml,'USD') !== false && strpos($foreignCsv,'USD') !== false
		&& strpos($foreignCsv,'20.00000002') !== false, 'A mismatched source currency stays explicit in the row and CSV comment without losing numeric precision');
}
print "OK: $checks monthly balance report checks\n";
