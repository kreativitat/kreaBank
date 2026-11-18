#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 * ONLY the disposable 127.0.0.1:33317/kreabank_auto_test fixture.
 */
require __DIR__ . '/test_automatic_reconciliation_database.php';
require_once __DIR__ . '/../class/KreaBankOpeningBalance.class.php';
require_once __DIR__ . '/../class/KreaBankMonthlyReport.class.php';
$checks = 0;
function openingReportDatabaseCheck($report, $key) { foreach ($report['checks'] as $check) { if ($check['key'] === $key) { return $check; } } throw new RuntimeException('Missing check ' . $key); }
$openingEngine = new KreaBankOpeningBalance($db,$user,1);
$openingEngine->install();
$db->query("INSERT INTO kbauto_bank_account(rowid,entity,ref,label,currency_code,clos,courant,rappro,fk_pays) VALUES(19601,1,'OPEN-MTH','Opening monthly fixture','EUR',0,1,1,1),(19602,2,'FOREIGN-OPEN','Foreign opening fixture','EUR',0,1,1,1),(19603,1,'NO-OPEN','No opening fixture','EUR',0,1,1,1)");
foreach (array(196001=>array('5000','2026-10-31'),196002=>array('-10','2026-11-01'),196003=>array('20','2026-11-02'),196004=>array('5','2026-12-01')) as $id=>$fixture) {
	stageAuto($id,$fixture[0],'OPEN-MONTHLY',19601);
	$db->query("UPDATE kbauto_kreabank_statement_line SET operation_date='".$fixture[1]."',value_date='".$fixture[1]."' WHERE rowid=$id");
}
$db->query('UPDATE kbauto_kreabank_statement_line SET running_balance=9000 WHERE rowid=196001');
bankAuto(196011,'27','OPEN-HISTORY',19601,'2026-10-25');
bankAuto(196012,'73','OPEN-ADJUSTMENT',19601,'2026-10-31');
bankAuto(196013,'20','OPEN-NOVEMBER',19601,'2026-11-02');
$db->query("UPDATE kbauto_bank SET rappro=1,num_releve='OPEN-MONTHLY' WHERE rowid IN (196011,196012)");
$db->query("UPDATE kbauto_bank SET fk_type='SOLD' WHERE rowid=196012");
$db->query("UPDATE kbauto_bank SET rappro=1,num_releve='ST-196003' WHERE rowid=196013");
$db->query('UPDATE kbauto_kreabank_statement_line SET fk_native_bank_line=196013,status=2 WHERE rowid=196003');
$db->query("INSERT INTO kbauto_kreabank_opening_balance(entity,fk_bank_account,start_date,currency,opening_balance,ledger_balance,adjustment_amount,fk_adjustment_bank_line,statement_ref,evidence_ref,fk_user,datec,facts_hash,payload_json) VALUES(1,19601,'2026-11-01','EUR',100,27,73,196012,'OPEN-MONTHLY','Synthetic bank statement',7,NOW(),SHA2('opening-monthly',256),'{}'),(2,19602,'2026-11-01','EUR',222,222,0,NULL,'FOREIGN-OPEN','Synthetic foreign bank statement',7,NOW(),SHA2('foreign-opening-monthly',256),'{}')");
$monthly = new KreaBankMonthlyReport($db,1);
$before=array(countAuto('bank'),countAuto('kreabank_statement_line'),countAuto('kreabank_recon_audit'),countAuto('kreabank_opening_balance'));
$r=$monthly->load(19601,'2026-11');
checkAuto($r['bank']['opening']==='100.00000000' && $r['ledger']['opening']==='100.00000000' && $r['map']['opening_valid'],'Owned saved opening loads with its real native adjustment');
checkAuto($r['bank']['pending']===1 && $r['bank_pending'][0]['rowid']===196002 && $r['ledger']['pending']===0,'The loader accepts historical migration rows and preserves 1 November reconciliation');
checkAuto(count($r['bank_rows'])===2 && count($r['ledger_rows'])===1 && $r['bank']['matched']===1,'Historical rows do not become fake native match details');
checkAuto($r['map']['bank_close']==='110.00000000' && $r['map']['ledger_close']==='120.00000000' && $r['map']['difference']==='0.00000000' && !$r['map']['balance_confirmed'],'Opening-anchored provisional closing reconciles exact signed pending evidence');
checkAuto(openingReportDatabaseCheck($r,'UnreconciledNative')['status']==='ok' && openingReportDatabaseCheck($r,'HistoricalOpening')['status']==='ok','Unlinked historical native rows satisfy the opening declaration without source-match warnings');
$raw=$db->query('SELECT * FROM (' . (new KreaBankIntegration($db,1))->bankStatementSql(19601) . ') h WHERE h.rowid=196001')->fetch_object();
checkAuto((int)$raw->opening_reconciled===1 && (int)$raw->status===0 && !$raw->native_id,'Read projection exposes historical state while preserving original source status and absent native link');
checkAuto($before===array(countAuto('bank'),countAuto('kreabank_statement_line'),countAuto('kreabank_recon_audit'),countAuto('kreabank_opening_balance')),'Report loading creates no financial, source, audit or opening records');
$r=$monthly->load(19601,'2026-12');
checkAuto($r['bank']['opening']==='110.00000000' && $r['bank']['closing']==='115.00000000' && $r['bank']['pending_amount']==='-5.00000000' && count($r['bank_rows'])===2,'Later periods carry only postcut unmatched activity and preserve the established opening');
$r=$monthly->load(19601,'2026-12','116');
checkAuto($r['bank']['opening']==='110.00000000' && $r['map']['opening_difference']==='0.00000000' && $r['map']['month_difference']==='1.00000000' && $r['map']['balance_confirmed'],'Report-only closing confirmation never rebases an opening or hides a monthly discrepancy');
$r=$monthly->load(19601,'2026-10');
checkAuto(!isset($r['map']['opening_date']) && $r['bank']['pending']===1,'Periods before the implementation date retain original evidence behavior');
foreach (array(19602,999999,0) as $accountId) { rejectAuto(static function () use($monthly,$accountId){ $monthly->load($accountId,'2026-11'); },'Foreign or missing accounts cannot consume a saved opening'); }
// An unrelated owned account without a saved opening keeps the existing contract.
stageAuto(196005,'7','NO-OPENING',19603);
$db->query("UPDATE kbauto_kreabank_statement_line SET operation_date='2026-10-31' WHERE rowid=196005");
bankAuto(196014,'7','NO-OPENING',19603,'2026-10-31');
$db->query('UPDATE kbauto_bank SET rappro=1 WHERE rowid=196014');
$r=$monthly->load(19603,'2026-11');
checkAuto(!isset($r['map']['opening_date']) && $r['bank']['pending']===1 && $r['ledger']['pending']===1 && openingReportDatabaseCheck($r,'UnreconciledNative')['status']==='warning','Opening configuration never leaks to another owned account');
$db->query("UPDATE kbauto_kreabank_opening_balance SET currency='USD' WHERE entity=1 AND fk_bank_account=19601");
rejectAuto(static function() use($monthly){ $monthly->load(19601,'2026-11'); },'A saved opening in a different currency cannot anchor the report');
$db->query("UPDATE kbauto_kreabank_opening_balance SET currency='EUR' WHERE entity=1 AND fk_bank_account=19601");
$db->query('UPDATE kbauto_bank SET amount=28 WHERE rowid=196011');
$r=$monthly->load(19601,'2026-11');
checkAuto(!$r['map']['opening_valid'] && $r['map']['difference']===null && $r['ledger']['opening']==='101.00000000','Editing old native amounts exposes opening drift without another automatic adjustment');
$db->query('UPDATE kbauto_bank SET amount=27,rappro=0 WHERE rowid=196011');
$r=$monthly->load(19601,'2026-11');
checkAuto(openingReportDatabaseCheck($r,'HistoricalOpening')['status']==='error' && $r['map']['difference']===null,'Reopening an accepted historical native movement invalidates the saved declaration');
$db->query('UPDATE kbauto_bank SET rappro=1 WHERE rowid=196011');
$db->query("UPDATE kbauto_bank SET fk_type='VIR' WHERE rowid=196012");
$r=$monthly->load(19601,'2026-11');
checkAuto(!$r['map']['opening_valid'],'A replacement transfer cannot impersonate the opening adjustment');
$db->query("UPDATE kbauto_bank SET fk_type='SOLD' WHERE rowid=196012");
$db->query('UPDATE kbauto_kreabank_statement_line SET running_balance=111 WHERE rowid=196003');
$r=$monthly->load(19601,'2026-11');
checkAuto($r['map']['bank_close']===null && openingReportDatabaseCheck($r,'BalanceChain')['status']==='error','Supplied bank balances must agree exactly with the confirmed opening and subsequent activity');
$db->query('UPDATE kbauto_kreabank_statement_line SET running_balance=NULL WHERE rowid=196003');
// Historical native claims remain subject to the existing foreign-target safeguards.
bankAuto(196022,'5000','FOREIGN-POINTER',19602,'2026-10-31');
$db->query('UPDATE kbauto_kreabank_statement_line SET fk_native_bank_line=196022,status=2 WHERE rowid=196001');
$r=$monthly->load(19601,'2026-11');
checkAuto($r['bank_pending'][0]['native_id']===0 && $r['bank_pending'][0]['status']==='review' && $r['map']['difference']===null,'Historical migration never hides a missing or foreign native match pointer');
$db->query('UPDATE kbauto_kreabank_statement_line SET fk_native_bank_line=NULL,status=0 WHERE rowid=196001');
// Exercise the real migration lifecycle with a historical pending mapped source.
$db->query("INSERT INTO kbauto_bank_account(rowid,entity,ref,label,currency_code,clos,courant,rappro,fk_pays) VALUES(19604,1,'OPEN-MAPPED','Mapped historical opening fixture','EUR',0,1,1,1)");
stageAuto(196006,'27','MAPPED-OPENING',19604);
bankAuto(196041,'27','MAPPED-OPENING',19604,'2026-10-31');
$db->query("UPDATE kbauto_kreabank_statement_line SET operation_date='2026-10-31',value_date='2026-10-31',fk_native_bank_line=196041 WHERE rowid=196006");
$beforeMapped=countAuto('bank');
$appliedMapped=$openingEngine->apply($openingEngine->preview(19604,'2026-11-01','27','OPENING-MAPPED','Synthetic bank statement at 31 October'));
$nativeMapped=$db->query('SELECT rappro,num_releve FROM kbauto_bank WHERE rowid=196041')->fetch_object();
$sourceMapped=$db->query('SELECT status,fk_native_bank_line FROM kbauto_kreabank_statement_line WHERE rowid=196006')->fetch_object();
checkAuto(!$appliedMapped['fk_adjustment_bank_line'] && countAuto('bank')===$beforeMapped && (int)$nativeMapped->rappro===1 && $nativeMapped->num_releve==='OPENING-MAPPED','Native opening lifecycle reconciles the existing historical movement without a zero adjustment');
checkAuto((int)$sourceMapped->status===0 && (int)$sourceMapped->fk_native_bank_line===196041,'Actual migration preserves pending staging status and its existing historical native pointer');
$r=$monthly->load(19604,'2026-11');
checkAuto($r['map']['opening_valid'] && openingReportDatabaseCheck($r,'ReconciliationFlags')['status']==='ok' && $r['bank']['pending']===0 && $r['ledger']['pending']===0,'Monthly loading recognizes the exact flag/reference state produced by opening application');
checkAuto($r['bank']['matched']===0 && !$r['bank_rows'] && !$r['ledger_rows'] && $r['bank']['opening']==='27.00000000','Mapped historical opening acceptance neither invents normal matches nor repeats backlog');
stageAuto(196007,'1','BOUNDARY-OPENING',19604);
$r=$monthly->load(19604,'2026-11');
checkAuto($r['bank']['pending']===1 && $r['bank_pending'][0]['rowid']===196007 && $r['map']['bank_close']==='28.00000000','A real opening migration still leaves the inclusive first-day movement pending');
$db->query('UPDATE kbauto_kreabank_statement_line SET amount=28 WHERE rowid=196006');
$r=$monthly->load(19604,'2026-11');
checkAuto(openingReportDatabaseCheck($r,'ReconciliationFlags')['status']==='error' && $r['bank_pending'][0]['status']==='review','Changed historical source amounts cannot use the expected migration flags as an integrity bypass');
$db->query("UPDATE kbauto_kreabank_statement_line SET amount=27,operation_date='2026-11-01' WHERE rowid=196006");
$r=$monthly->load(19604,'2026-11');
checkAuto(openingReportDatabaseCheck($r,'ReconciliationFlags')['status']==='error','Moving a mapped source across the opening boundary remains a report integrity error');
$db->query("UPDATE kbauto_kreabank_statement_line SET operation_date='2026-10-31' WHERE rowid=196006");
// No optional schema is a supported older installation; reads may not install it.
$db->query('DROP TABLE kbauto_kreabank_opening_balance');
// Schema discovery is request-local; a fresh connection models the older request.
$withoutOpeningDb=new DoliDB();
$r=(new KreaBankMonthlyReport($withoutOpeningDb,1))->load(19601,'2026-11');
checkAuto(!isset($r['map']['opening_date']) && $r['bank']['pending']===2,'An older installation without opening schema retains ordinary reconciliation semantics');
checkAuto(!(new KreaBankOpeningBalance($withoutOpeningDb,$user,1))->available(),'A report GET never creates missing opening tables');
print "OK: $checks opening report database checks\n";
