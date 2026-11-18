#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 * ONLY the guarded disposable 127.0.0.1:33317/kreabank_auto_test fixture.
 */
require __DIR__ . '/test_statement_balances_database.php';
require_once __DIR__ . '/../class/KreaBankMonthlyReport.class.php';
$checks = 0;
$monthly = new KreaBankMonthlyReport($db,1);
$db->begin();
// Keep the independent file-balance fixture outside these feed-only cutoffs.
$db->query("UPDATE kbauto_kreabank_statement_line SET operation_date='2026-12-01' WHERE rowid=905");
$before = array(countAuto('bank'),countAuto('kreabank_statement_line'),countAuto('kreabank_feed_transaction'));
$r = $monthly->load(120,'2026-11','',true);
checkAuto($r['map']['bank_close']==='3323.75000000' && $r['balances'][10]['bank']==='3323.75000000',
	'First report reuses coherent feed snapshots when transactions have no supplied running balance');
checkAuto($r['balances'][10]['snapshot_date']==='2026-11-03T08:57:41Z' && !$r['balances'][10]['confirmed']
	&& $r['balances'][9]['bank']===null, 'Derived balances retain snapshot provenance and stay unknown before the first source evidence');
checkAuto($before===array(countAuto('bank'),countAuto('kreabank_statement_line'),countAuto('kreabank_feed_transaction')),
	'Calculated monthly reporting performs no native, staging or feed writes');
$payload = $db->escape(json_encode(array('amount'=>'-10.00','currency'=>'EUR','operation_date'=>'2026-10-31')));
$db->query("UPDATE kbauto_kreabank_statement_line SET operation_date='2026-10-31',value_date='2026-10-31' WHERE rowid=904");
$db->query("UPDATE kbauto_kreabank_feed_transaction SET source_payload='$payload' WHERE provider_transaction_id='tx_904'");
$db->query("UPDATE kbauto_kreabank_feed_account SET history_from='2026-10-31' WHERE rowid=20");
$r = $monthly->load(120,'2026-11','',true);
checkAuto($r['balances'][9]['bank']==='3249.69000000' && $r['balances'][10]['bank']==='3323.75000000',
	'Earlier month close subtracts all later feed movements, including after its selected cutoff');
$earlier = $monthly->load(120,'2026-10','',true);
checkAuto($earlier['balances'][9]['bank']===$r['balances'][9]['bank'], 'Earlier balance is identical from its direct report or the annual overview');
$projection = new KreaBankIntegration($db,1);
$endpoint = $projection->query('SELECT a.* FROM (' . $projection->bankStatementWithBalancesSql(120,'2026-11-01') . ') a');
$anchor = $db->fetch_object($endpoint);
checkAuto((int) $anchor->rowid===904 && $anchor->display_balance==='3249.69000000' && !$db->fetch_object($endpoint),
	'Endpoint mode calculates exactly one scoped source before the cutoff');
foreach (array(array(0,'2026-11-01'),array(120,'2026-02-30')) as $invalidEndpoint) {
	try { $projection->bankStatementWithBalancesSql($invalidEndpoint[0],$invalidEndpoint[1]); throw new RuntimeException('Invalid endpoint accepted'); }
	catch (InvalidArgumentException $e) { $checks++; }
}
$db->query("UPDATE kbauto_kreabank_feed_transaction SET status='review' WHERE provider_transaction_id='tx_902'");
$invalid = $monthly->load(120,'2026-11','',true);
checkAuto($invalid['balances'][9]['bank']===null && $invalid['balances'][10]['bank']===null,
	'A conflicting or incomplete feed cannot provide apparent monthly bank balances');
$confirmed = $monthly->load(120,'2026-11','3323.75',true);
checkAuto($confirmed['balances'][9]['bank']===null && $confirmed['balances'][10]['bank']==='3323.75000000'
	&& $confirmed['balances'][10]['confirmed'], 'Explicit month-bound confirmation never repairs earlier unavailable bank snapshots');
$db->query("UPDATE kbauto_kreabank_feed_transaction SET status='imported' WHERE provider_transaction_id='tx_902'");
stageAuto(910,'1.00','UNMAPPED-FEED',120,1,'fintable');
$db->query("UPDATE kbauto_kreabank_statement_line SET operation_date='2026-11-02',line_uid=NULL WHERE rowid=910");
$unmapped = $monthly->load(120,'2026-11','',true);
checkAuto($unmapped['balances'][10]['bank']===null, 'An unmapped feed source with a null identity cannot provide an apparent bank balance');
$db->rollback();
print "OK: $checks monthly balance snapshot database checks\n";
