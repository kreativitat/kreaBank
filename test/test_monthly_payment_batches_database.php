#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 * --pure opens no database. The default resets ONLY the guarded disposable
 * 127.0.0.1:33317/kreabank_auto_test fixture; never run its DB mode concurrently.
 */
$pureOnly = in_array('--pure', $argv, true);
if (!$pureOnly) { require __DIR__ . '/test_automatic_reconciliation_database.php'; }
require_once __DIR__ . '/../class/KreaBankMonthlyReport.class.php';

$monthlyGroupChecks = 0;
function checkMonthlyGroup($condition, $message)
{
	global $monthlyGroupChecks;
	if (!$condition) { throw new RuntimeException($message); }
	$monthlyGroupChecks++;
}
function monthlyGroupIssue($report, $key)
{
	foreach ($report['checks'] as $check) { if ($check['key'] === $key) { return $check['status']; } }
	throw new RuntimeException('Missing report check ' . $key);
}
function monthlyGroupComponent($id, $date, $amount)
{
	return array('group_id'=>501,'expected_native_id'=>$id,'active_native_id'=>$id,'expected_amount'=>$amount,
		'rowid'=>$id,'bank_account_id'=>101,'operation_date'=>$date,'amount'=>$amount,'currency'=>'EUR','rappro'=>1,'num_releve'=>'GROUP-ST','claim_count'=>1);
}
function monthlyGroupSource($date, $components)
{
	return array('rowid'=>1,'bank_account_id'=>101,'operation_date'=>$date,'amount'=>'-100.00000003','currency'=>'EUR',
		'running_balance'=>'-100.00000003','statement_id'=>1,'line_rank'=>1,'source_storage'=>'staging','expected_native_id'=>0,'native_id'=>0,
		'native_amount'=>'-100.00000003','native_currency'=>'EUR','status'=>2,'rappro'=>1,'statement_ref'=>'GROUP-ST','num_releve'=>'GROUP-ST',
		'description'=>'Synthetic salary batch','group_id'=>501,'group_count'=>2,'group_valid'=>1,'group_components'=>$components);
}
function monthlyGroupNative($component)
{
	return array_intersect_key($component, array_flip(array('rowid','bank_account_id','operation_date','amount','currency','rappro','num_releve','claim_count')));
}
$account = array('rowid'=>101,'entity'=>1,'ref'=>'GROUP','currency_code'=>'EUR');
$reporter = new KreaBankMonthlyReport(null, 1);
$c1 = monthlyGroupComponent(11,'2026-11-04','-40.00000001');
$c2 = monthlyGroupComponent(12,'2026-11-05','-60.00000002');
$source = monthlyGroupSource('2026-11-05',array($c1,$c2));
$native = array(monthlyGroupNative($c1),monthlyGroupNative($c2));
$report = $reporter->assemble($account,'2026-11',array($source),$native);
checkMonthlyGroup($report['bank']['matched'] === 1 && $report['ledger']['matched'] === 2, 'One grouped bank source covers every real native movement');
checkMonthlyGroup($report['bank']['pending'] === 0 && $report['ledger']['pending'] === 0 && $report['map']['difference'] === '0.00000000', 'Exact grouped source and native totals reconcile without a summary movement');
checkMonthlyGroup($report['bank_rows'][0]['native_id'] === 0 && $report['bank_rows'][0]['native_ids'] === array(11,12), 'Grouped report evidence retains all native identities without a fake representative ID');
checkMonthlyGroup($report['ledger_rows'][1]['balance'] === '-100.00000003', 'Native running balance adds components once with their original precision');
$future = $c2; $future['operation_date'] = '2026-12-01';
$futureSource = monthlyGroupSource('2026-11-05',array($c1,$future));
foreach (array(array(monthlyGroupNative($c1),monthlyGroupNative($future)),array(monthlyGroupNative($c1))) as $ledger) {
	$report = $reporter->assemble($account,'2026-11',array($futureSource),$ledger);
	checkMonthlyGroup($report['bank']['matched'] === 0 && $report['bank']['pending'] === 1 && $report['ledger']['matched'] === 0 && $report['ledger']['pending'] === 1, 'A group with a next-month component stays entirely open at this cutoff');
	checkMonthlyGroup($report['bank_pending'][0]['status'] === 'pending' && $report['ledger_pending'][0]['status'] === 'pending'
		&& monthlyGroupIssue($report,'ReconciliationFlags') === 'ok', 'Known future components are timing differences, not broken reconciliation evidence');
	checkMonthlyGroup($report['ledger']['closing'] === '-40.00000001' && $report['map']['difference'] === '0.00000000', 'Future native components never enter the current ledger closing or duplicate adjusted balances');
}
$report = $reporter->assemble($account,'2026-11',array($source),array(monthlyGroupNative($c1)));
checkMonthlyGroup($report['bank']['matched'] === 0 && monthlyGroupIssue($report,'ReconciliationFlags') === 'error', 'A missing current-period native component cannot be treated as future timing');
$bad = $source; $bad['group_components'][1]['bank_account_id'] = 202;
$report = $reporter->assemble($account,'2026-11',array($bad),array(monthlyGroupNative($c1)));
checkMonthlyGroup($report['bank_rows'][0]['status'] === 'review' && $report['bank_rows'][0]['native_ids'] === array(11), 'Foreign-account component facts never produce a native link or partial matched group');
$bad = $source; $bad['amount'] = '-100.00000004';
$report = $reporter->assemble($account,'2026-11',array($bad),$native);
checkMonthlyGroup($report['bank']['matched'] === 0 && $report['map']['difference'] === null, 'Changed source amount invalidates the complete group at sub-cent precision');
foreach (array('expected_amount'=>'-60.00000003','currency'=>'USD','active_native_id'=>99,'num_releve'=>'OTHER','group_id'=>999) as $field=>$value) {
	$bad = $source; $bad['group_components'][1][$field] = $value;
	$report = $reporter->assemble($account,'2026-11',array($bad),$native);
	checkMonthlyGroup($report['bank']['matched'] === 0 && monthlyGroupIssue($report,'ReconciliationFlags') === 'error', 'Changed component amount, currency, claim, statement or group identity invalidates all coverage');
}
$bad = $source; $bad['group_valid'] = 0;
$report = $reporter->assemble($account,'2026-11',array($bad),$native);
checkMonthlyGroup($report['bank']['matched'] === 0 && $report['ledger']['matched'] === 0, 'A failed persisted group projection cannot be rescued by local totals');
$claim = array('rowid'=>2,'bank_account_id'=>101,'operation_date'=>'2026-12-01','amount'=>'-40.00000001','currency'=>'EUR',
	'statement_id'=>2,'line_rank'=>1,'source_storage'=>'staging','expected_native_id'=>11,'native_id'=>11,'native_amount'=>'-40.00000001','native_currency'=>'EUR','status'=>2,'rappro'=>1,'statement_ref'=>'OTHER','num_releve'=>'OTHER');
$report = $reporter->assemble($account,'2026-11',array($source,$claim),$native);
checkMonthlyGroup($report['bank']['matched'] === 0 && monthlyGroupIssue($report,'ConflictingLinks') === 'error', 'A future scalar source claiming a group component invalidates the current group');
$prior1 = $c1; $prior1['operation_date'] = '2026-10-30';
$priorSource = monthlyGroupSource('2026-10-31',array($prior1,$c2));
$report = $reporter->assemble($account,'2026-11',array($priorSource),array(monthlyGroupNative($prior1),monthlyGroupNative($c2)));
checkMonthlyGroup($report['map']['opening_difference'] === '0.00000000' && $report['map']['month_difference'] === '0.00000000', 'Opening adjustment treats a group crossing the month boundary as open until every leg is present');
$prior2 = $c2; $prior2['operation_date'] = '2026-10-31';
$priorSource = monthlyGroupSource('2026-10-31',array($prior1,$prior2));
$report = $reporter->assemble($account,'2026-11',array($priorSource),array(monthlyGroupNative($prior1),monthlyGroupNative($prior2)));
checkMonthlyGroup(!$report['bank_rows'] && !$report['ledger_rows'] && $report['bank']['opening'] === '-100.00000003'
	&& $report['ledger']['opening'] === '-100.00000003' && $report['map']['opening_difference'] === '0.00000000', 'Fully prior matched groups stay in opening balances without repeating carried details');
$futureSource = monthlyGroupSource('2026-12-01',array($c1,$c2));
$report = $reporter->assemble($account,'2026-11',array($futureSource),$native,'0.00');
checkMonthlyGroup($report['bank']['count'] === 0 && $report['ledger']['pending'] === 2 && $report['ledger']['matched'] === 0, 'A future bank source cannot cover current native components');
print "OK: $monthlyGroupChecks monthly payment batch pure checks\n";
if ($pureOnly) { exit(0); }

$initialGroupChecks = $monthlyGroupChecks;
foreach (array('salary','payment_salary') as $table) {
	$sql = preg_replace('/--[^\n]*/','',file_get_contents(DOL_DOCUMENT_ROOT . '/install/mysql/tables/llx_' . $table . '.sql'));
	foreach (explode(';',str_replace('llx_',MAIN_DB_PREFIX,$sql)) as $statement) { if (trim($statement) !== '') { $db->query($statement); } }
}
$sql = preg_replace('/--[^\n]*/','',file_get_contents(__DIR__ . '/../sql/llx_kreabank_payment_batch.sql'));
foreach (explode(';',str_replace('llx_',MAIN_DB_PREFIX,$sql)) as $statement) { if (trim($statement) !== '') { $db->query($statement); } }
$_SESSION = array();
function createMonthlyGroupFixture($accountId, $sourceId, $sourceDate, $firstId, $firstDate, $secondDate, $amounts = array('-100.00000003','-40.00000001','-60.00000002'))
{
	global $db,$service;
	$db->query("INSERT INTO kbauto_bank_account(rowid,entity,ref,label,currency_code,clos,courant,rappro,fk_pays) VALUES($accountId,1,'MGR-$accountId','Synthetic monthly group','EUR',0,1,1,1)");
	stageAuto($sourceId,$amounts[0],'MONTHLY-GROUP-'.$sourceId,$accountId);
	$db->query("UPDATE kbauto_kreabank_statement_line SET operation_date='$sourceDate',value_date='$sourceDate',running_balance=" . $amounts[0] . " WHERE rowid=$sourceId");
	// Create under the current same-day approval contract, then simulate the
	// historical posting differences that this read-only report must preserve.
	bankAuto($firstId,$amounts[1],'MONTHLY-A-'.$sourceId,$accountId,$sourceDate);
	bankAuto($firstId+1,$amounts[2],'MONTHLY-B-'.$sourceId,$accountId,$sourceDate);
	$before = countAuto('bank');
	$groupId = $service->reconcilePaymentBatch($service->preparePaymentBatchReview($sourceId,array($firstId,$firstId+1),'Monthly salary batch'));
	checkMonthlyGroup($groupId > 0 && countAuto('bank') === $before, 'Creating an approved group does not create a summary bank movement');
	$db->query("UPDATE kbauto_bank SET dateo='$firstDate' WHERE rowid=$firstId");
	$db->query("UPDATE kbauto_bank SET dateo='$secondDate' WHERE rowid=" . ($firstId+1));
	return $groupId;
}
function monthlyGroupDbCounts()
{
	return array(countAuto('bank'),countAuto('kreabank_statement_line'),countAuto('kreabank_payment_batch'),countAuto('kreabank_payment_batch_line'),countAuto('kreabank_recon_audit'));
}
$groupId = createMonthlyGroupFixture(32101,33001,'2026-11-05',34001,'2026-11-04','2026-11-05');
$monthly = new KreaBankMonthlyReport($db,1);
$before = monthlyGroupDbCounts();
$report = $monthly->load(32101,'2026-11');
checkMonthlyGroup($report['bank']['matched'] === 1 && $report['ledger']['matched'] === 2 && $report['map']['difference'] === '0.00000000', 'Database loader reconciles one source against all real native components');
checkMonthlyGroup($report['bank_rows'][0]['native_id'] === 0 && $report['bank_rows'][0]['group_id'] === $groupId
	&& $report['bank_rows'][0]['native_ids'] === array(34001,34002), 'Database report exposes every owned component without a scalar substitute');
checkMonthlyGroup(monthlyGroupDbCounts() === $before, 'Group-aware monthly report loading writes no financial, source, mapping or audit record');
$annual = $monthly->load(32101,'2026-11','',true);
checkMonthlyGroup($annual['bank_pending']===$report['bank_pending'] && $annual['balances'][10]['difference']==='0.00000000',
	'Annual balances preserve complete historical groups without creating an aggregate movement');
$db->query("UPDATE kbauto_bank SET dateo='2026-12-01' WHERE rowid=34002");
$report = $monthly->load(32101,'2026-11');
checkMonthlyGroup($report['bank']['pending'] === 1 && $report['ledger']['pending'] === 1 && $report['ledger']['closing'] === '-40.00000001'
	&& monthlyGroupIssue($report,'ReconciliationFlags') === 'ok', 'Loader reads a future component for validation without including it in current ledger closing');
$db->query("UPDATE kbauto_bank SET dateo='2026-11-05' WHERE rowid=34002");
stageAuto(33002,'-60.00000002','FUTURE-SCALAR-CLAIM',32101);
$db->query("UPDATE kbauto_kreabank_statement_line SET operation_date='2026-12-01',fk_native_bank_line=34002,status=2,allocated_amount=60.00000002 WHERE rowid=33002");
$report = $monthly->load(32101,'2026-11');
checkMonthlyGroup($report['bank']['matched'] === 0 && monthlyGroupIssue($report,'ConflictingLinks') === 'error' && $report['map']['difference'] === null, 'Global scalar and group claims include later source dates');
$db->query('UPDATE kbauto_kreabank_statement_line SET fk_native_bank_line=NULL,status=0,allocated_amount=0 WHERE rowid=33002');
$db->query('UPDATE kbauto_bank SET amount=-60.00000003 WHERE rowid=34002');
$report = $monthly->load(32101,'2026-11');
checkMonthlyGroup($report['bank']['matched'] === 0 && monthlyGroupIssue($report,'ReconciliationFlags') === 'error', 'Changed native component amount invalidates the whole persisted group');
$db->query('UPDATE kbauto_bank SET amount=-60.00000002 WHERE rowid=34002');
$db->query("INSERT INTO kbauto_bank_account(rowid,entity,ref,label,currency_code,clos,courant,rappro,fk_pays) VALUES(32201,2,'MGR-FOREIGN','Foreign synthetic group account','EUR',0,1,1,1)");
$db->query('UPDATE kbauto_bank SET fk_account=32201 WHERE rowid=34002');
$report = $monthly->load(32101,'2026-11');
checkMonthlyGroup($report['bank']['matched'] === 0 && $report['bank_rows'][0]['native_ids'] === array(34001)
	&& !in_array(34002,array_column($report['ledger_rows'],'rowid'),true), 'Foreign moved component is not disclosed or accepted through an owned group mapping');
$db->query('UPDATE kbauto_bank SET fk_account=32101 WHERE rowid=34002');
$db->query('UPDATE kbauto_kreabank_statement_line SET amount=-100.00000004 WHERE rowid=33001');
$report = $monthly->load(32101,'2026-11');
checkMonthlyGroup($report['bank']['matched'] === 0 && $report['map']['difference'] === null, 'Changed persisted source amount cannot retain a verified group');
$db->query('UPDATE kbauto_kreabank_statement_line SET amount=-100.00000003 WHERE rowid=33001');
$beforeBalance = (string) $db->query('SELECT CAST(SUM(amount) AS DECIMAL(24,8)) balance FROM kbauto_bank WHERE fk_account=32101')->fetch_object()->balance;
$service->undoPaymentBatch(33001,$service->preparePaymentBatchUndoReview(33001));
$report = $monthly->load(32101,'2026-11');
checkMonthlyGroup($report['bank']['matched'] === 0 && $report['bank']['pending'] === 1 && $report['ledger']['pending'] === 2, 'Group undo returns one source and all components to open report evidence');
checkMonthlyGroup((string) $db->query('SELECT CAST(SUM(amount) AS DECIMAL(24,8)) balance FROM kbauto_bank WHERE fk_account=32101')->fetch_object()->balance === $beforeBalance, 'Group approval and undo preserve the native bank balance');
createMonthlyGroupFixture(32102,33010,'2026-10-31',34010,'2026-10-30','2026-11-01');
$report = $monthly->load(32102,'2026-11');
checkMonthlyGroup($report['map']['opening_difference'] === '0.00000000' && $report['map']['month_difference'] === '0.00000000', 'Loader preserves both opening cutoffs for a group crossing months');
createMonthlyGroupFixture(32103,33020,'2026-10-31',34020,'2026-10-30','2026-10-31');
$report = $monthly->load(32103,'2026-11');
checkMonthlyGroup(!$report['bank_rows'] && !$report['ledger_rows'] && $report['bank']['opening'] === '-100.00000003', 'Prior complete groups affect balances without recurring as carried details');
createMonthlyGroupFixture(32104,33030,'2026-12-01',34030,'2026-11-04','2026-11-05');
$report = $monthly->load(32104,'2026-11','0.00');
checkMonthlyGroup($report['bank']['count'] === 0 && $report['ledger']['pending'] === 2, 'A next-month source cannot prematurely cover current native ledger movements');
createMonthlyGroupFixture(32105,33040,'2026-11-05',34040,'2026-11-04','2026-11-05',array('-0.30000000','-0.10000000','-0.20000000'));
$report = $monthly->load(32105,'2026-11');
checkMonthlyGroup($report['bank']['matched'] === 1 && $report['ledger']['matched'] === 2 && $report['ledger']['closing'] === '-0.30000000'
	&& $report['map']['difference'] === '0.00000000', 'Native DOUBLE storage is projected as exact DECIMAL before validating 0.10 plus 0.20 against 0.30');
print 'OK: ' . ($monthlyGroupChecks - $initialGroupChecks) . " monthly payment batch database checks\n";
