#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 * ONLY the disposable 127.0.0.1:33317/kreabank_auto_test fixture.
 */
require __DIR__ . '/test_automatic_reconciliation_database.php';
require_once __DIR__ . '/../class/KreaBankMonthlyReport.class.php';
$checks = 0;
$db->query("INSERT INTO kbauto_bank_account(rowid,entity,ref,label,currency_code,clos,courant,rappro,fk_pays) VALUES(19901,1,'MTH','Monthly synthetic fixture','EUR',0,1,1,1),(19902,2,'FMTH','Foreign synthetic fixture','EUR',0,1,1,1)");
foreach (array(199001=>array('100.00000001','2026-08-31'),199002=>array('0.10000001','2026-09-01'),199003=>array('-0.00000001','2026-09-30')) as $id=>$fixture) {
	stageAuto($id,$fixture[0],'MONTHLY',19901);
	$db->query("UPDATE kbauto_kreabank_statement_line SET operation_date='".$fixture[1]."',value_date='".$fixture[1]."' WHERE rowid=$id");
}
bankAuto(199011,'100.00000001','MONTHLY',19901,'2026-08-31');
bankAuto(199012,'0.10000001','MONTHLY',19901,'2026-09-01');
$db->query("UPDATE kbauto_bank SET rappro=1,num_releve='ST-199001' WHERE rowid=199011");
$db->query("UPDATE kbauto_bank SET rappro=1,num_releve='ST-199002' WHERE rowid=199012");
$db->query('UPDATE kbauto_kreabank_statement_line SET fk_native_bank_line=199011,status=2,running_balance=100.00000001 WHERE rowid=199001');
$db->query('UPDATE kbauto_kreabank_statement_line SET fk_native_bank_line=199012,status=2,running_balance=100.10000002 WHERE rowid=199002');
$db->query('UPDATE kbauto_kreabank_statement_line SET running_balance=100.10000001 WHERE rowid=199003');
$monthly = new KreaBankMonthlyReport($db,1);
$before=array(countAuto('bank'),countAuto('kreabank_statement'),countAuto('kreabank_statement_line'),countAuto('kreabank_recon_audit'));
$r=$monthly->load(19901,'2026-09');
checkAuto($r['bank']['movement']==='0.10000000' && $r['ledger']['closing']==='100.10000002','Loader preserves eight-place evidence and exact owned ledger sums');
checkAuto($r['bank']['matched']===1 && $r['map']['difference']==='0.00000000','Loader shares native statement references and verified one-to-one matching');
checkAuto($r['bank']['count']===2 && count($r['bank_rows'])===2 && count($r['ledger_rows'])===1,'Loader carries balances without repeating old matched details');
checkAuto($r['ledger_rows'][0]['balance']==='100.10000002','Visible native running balance includes prior matched history with exact precision');
checkAuto($before===array(countAuto('bank'),countAuto('kreabank_statement'),countAuto('kreabank_statement_line'),countAuto('kreabank_recon_audit')),'Monthly loading writes no financial, source or audit records');
foreach (array(19902,999999,0) as $id) {
	try { $monthly->load($id,'2026-09'); throw new RuntimeException('Foreign or missing account accepted'); } catch (InvalidArgumentException $e) { $checks++; }
}
// A future native counterpart is open for September, not an invalid dangling link.
$db->query("UPDATE kbauto_bank SET dateo='2026-10-01' WHERE rowid=199012");
$r=$monthly->load(19901,'2026-09');
checkAuto($r['bank']['matched']===0 && $r['bank']['pending']===2 && $r['ledger']['closing']==='100.00000001','Native posting cutoff preserves bank-side timing differences');
$flags=array_filter($r['checks'],static function($check){return $check['key']==='ReconciliationFlags';});
checkAuto(reset($flags)['status']==='ok','Existing exact future counterpart is distinguished from missing native evidence');
$db->query("UPDATE kbauto_bank SET dateo='2026-09-01' WHERE rowid=199012");
// Even a future bank statement cannot claim the same native movement twice.
stageAuto(199004,'0.10000001','MONTHLY',19901);
$db->query("UPDATE kbauto_kreabank_statement_line SET operation_date='2026-10-02',fk_native_bank_line=199012,status=2 WHERE rowid=199004");
$r=$monthly->load(19901,'2026-09');
checkAuto($r['bank']['matched']===0 && $r['map']['difference']===null,'Global future claims invalidate a current native pairing');
$db->query('UPDATE kbauto_kreabank_statement_line SET fk_native_bank_line=NULL WHERE rowid=199004');
// Native ownership is enforced even if staged data contains a foreign target ID.
bankAuto(199022,'0.10000001','FOREIGN',19902,'2026-09-01');
$db->query('UPDATE kbauto_kreabank_statement_line SET fk_native_bank_line=199022 WHERE rowid=199002');
$r=$monthly->load(19901,'2026-09');
$row=array_values(array_filter($r['bank_rows'],static function($row){return $row['rowid']===199002;}))[0];
checkAuto($row['native_id']===0 && $row['status']==='review' && $r['map']['difference']===null,'Foreign native pointer is neither exposed as a link nor accepted as a match');
$db->query('UPDATE kbauto_kreabank_statement_line SET fk_native_bank_line=199012 WHERE rowid=199002');
// Null source dates must be visible as errors rather than silently filtered away.
$db->query('UPDATE kbauto_kreabank_statement_line SET operation_date=NULL WHERE rowid=199003');
$r=$monthly->load(19901,'2026-09');
checkAuto(count($r['bank_rows'])===2 && $r['map']['bank_close']===null && $r['map']['difference']===null,'Loader retains undated bank evidence and blocks complete balance claims');
$db->query("UPDATE kbauto_kreabank_statement_line SET operation_date='2026-09-30' WHERE rowid=199003");
// Legacy imports follow native rapprochement even with older metadata status.
bankAuto(199031,'3.00','LEGACY',19901,'2026-09-29');
$db->query("UPDATE kbauto_bank SET rappro=1,num_releve='MONTHLY-LEGACY',note='KreaBank import synthetic' WHERE rowid=199031");
$r=$monthly->load(19901,'2026-09');
$legacy=array_values(array_filter($r['bank_rows'],static function($row){return $row['id']==='legacy_native:199031';}));
checkAuto(count($legacy)===1 && $legacy[0]['status']==='matched','Legacy native imports use the installed projection without duplicating ledger evidence');
print "OK: $checks monthly report database checks\n";
