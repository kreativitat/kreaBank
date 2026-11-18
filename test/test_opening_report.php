#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
require_once __DIR__ . '/../class/KreaBankMonthlyReport.class.php';
$openingReportChecks = 0;
function checkOpeningReport($condition, $message) { global $openingReportChecks; if (!$condition) { throw new RuntimeException($message); } $openingReportChecks++; }
function openingReportIssue($report, $key) { foreach ($report['checks'] as $check) { if ($check['key'] === $key) { return $check; } } throw new RuntimeException('Missing check ' . $key); }
function openingReportBank($id, $date, $amount, $balance = null, $nativeId = 0) {
	return array('rowid'=>$id,'bank_account_id'=>101,'operation_date'=>$date,'amount'=>$amount,'currency'=>'EUR','running_balance'=>$balance,'statement_id'=>1,'line_rank'=>$id,'source_storage'=>'staging','expected_native_id'=>$nativeId,'native_id'=>$nativeId,'native_amount'=>$amount,'native_currency'=>'EUR','status'=>$nativeId ? 2 : 0,'rappro'=>$nativeId ? 1 : 0,'statement_ref'=>'ST','num_releve'=>'ST','description'=>'Synthetic source');
}
function openingReportNative($id, $date, $amount, $reconciled = true) { return array('rowid'=>$id,'bank_account_id'=>101,'operation_date'=>$date,'amount'=>$amount,'currency'=>'EUR','rappro'=>$reconciled ? 1 : 0,'num_releve'=>'ST','description'=>'Synthetic movement'); }
$monthly = new KreaBankMonthlyReport(null, 1);
$account = array('rowid'=>101,'entity'=>1,'ref'=>'TEST','label'=>'Synthetic account','currency_code'=>'EUR');
$opening = array('rowid'=>1,'entity'=>1,'fk_bank_account'=>101,'start_date'=>'2026-11-01','currency'=>'EUR','opening_balance'=>'100.00000000','ledger_balance'=>'27.00000000','adjustment_amount'=>'73.00000000','fk_adjustment_bank_line'=>12,'statement_ref'=>'OPENING');
$adjustment = openingReportNative(12,'2026-10-31','73.00000000'); $adjustment['fk_type']='SOLD'; $adjustment['num_releve']='OPENING';
$native = array(openingReportNative(10,'2026-09-30','-13'),openingReportNative(11,'2026-10-31','40'),$adjustment,openingReportNative(13,'2026-11-02','20'));
$bank = array(openingReportBank(1,'2026-10-31','5000','9000'),openingReportBank(2,'2026-11-01','-10'),openingReportBank(3,'2026-11-02','20',null,13));
$r = $monthly->assemble($account,'2026-11',$bank,$native,'',$opening);
checkOpeningReport($r['bank']['opening']==='100.00000000' && $r['ledger']['opening']==='100.00000000','Official opening and real native adjustment establish the same opening');
checkOpeningReport($r['bank']['pending']===1 && $r['ledger']['pending']===0 && $r['bank_pending'][0]['rowid']===2,'Historical unrelated sources and native rows are accepted; 1 November remains pending');
checkOpeningReport(count($r['bank_rows'])===2 && count($r['ledger_rows'])===1 && $r['bank']['matched']===1,'Historical acceptance does not invent source/native matches or repeat historical detail');
checkOpeningReport($r['map']['bank_close']==='110.00000000' && $r['map']['ledger_close']==='120.00000000' && $r['map']['difference']==='0.00000000','Postcut amounts and signed pending differences reconcile from the independent opening anchor');
checkOpeningReport($r['map']['opening_difference']==='0.00000000' && !$r['map']['balance_confirmed'] && $r['map']['balance_basis']==='opening_projection','Calculated closing stays provisional despite the confirmed historical opening');
checkOpeningReport(openingReportIssue($r,'HistoricalOpening')['status']==='ok' && openingReportIssue($r,'UnreconciledNative')['status']==='ok','Unlinked historical native rows are not false reconciliation warnings');
checkOpeningReport($r['ledger_rows'][0]['balance']==='120.00000000' && $bank[0]['status']===0 && $bank[0]['native_id']===0,'Actual native running totals include history and adjustment without mutating source evidence');
$bank[] = openingReportBank(4,'2026-12-01','5');
$r = $monthly->assemble($account,'2026-12',$bank,$native,'',$opening);
checkOpeningReport($r['bank']['opening']==='110.00000000' && $r['bank']['closing']==='115.00000000' && $r['bank']['pending_amount']==='-5.00000000','Later months carry only real postcut activity and postcut pending items');
checkOpeningReport(count($r['bank_rows'])===2 && $r['bank_rows'][0]['rowid']===2 && $r['bank_rows'][0]['carried'] && $r['map']['opening_difference']==='0.00000000','Historical backlog never reappears in later-month carryovers');
$r = $monthly->assemble($account,'2026-12',$bank,$native,'116',$opening);
checkOpeningReport($r['bank']['opening']==='110.00000000' && $r['map']['opening_difference']==='0.00000000' && $r['map']['month_difference']==='1.00000000','A later confirmed closing never rebases the persisted opening or hides a monthly difference');
$historicalNative = array_slice($native,0,3);
$r = $monthly->assemble($account,'2026-11',array($bank[0]),$historicalNative,'',$opening);
checkOpeningReport($r['bank']['opening']==='100.00000000' && $r['bank']['closing']===null && $r['map']['difference']===null,'A known opening with no postcut bank evidence does not assert an unknown month closing');
$r = $monthly->assemble($account,'2026-11',array($bank[0]),$historicalNative,'100',$opening);
checkOpeningReport($r['map']['bank_close']==='100.00000000' && $r['map']['balance_confirmed'] && $r['map']['balance_basis']==='confirmed','An explicit month-end confirmation stays distinct from the opening');
$r = $monthly->assemble($account,'2026-11',array($bank[0]),$historicalNative);
checkOpeningReport($r['bank']['pending']===1 && $r['ledger']['pending']===3 && !isset($r['map']['opening_date']),'Absent opening keeps existing pending and warning behavior');
$without = $monthly->assemble($account,'2026-10',array($bank[0]),$historicalNative);
$with = $monthly->assemble($account,'2026-10',array($bank[0]),$historicalNative,'',$opening);
unset($without['generated_at'],$with['generated_at']);
checkOpeningReport($without===$with,'Reports preceding the start retain their original evidence and metadata');
foreach (array('entity'=>2,'fk_bank_account'=>102,'currency'=>'USD','start_date'=>'2026-02-30','opening_balance'=>'101','fk_adjustment_bank_line'=>0) as $key=>$value) {
	$bad = $opening; $bad[$key]=$value;
	try { $monthly->assemble($account,'2026-11',$bank,$native,'',$bad); throw new RuntimeException('Invalid opening accepted'); } catch (InvalidArgumentException $e) { $openingReportChecks++; }
}
$badNative=$native; $badNative[0]['amount']='-12';
$r = $monthly->assemble($account,'2026-11',$bank,$badNative,'',$opening);
checkOpeningReport(openingReportIssue($r,'HistoricalOpening')['status']==='error' && $r['map']['difference']===null && $r['map']['ledger_close']==='121.00000000','Later native history drift is visible and never silently readjusted');
$badNative=$native; $badNative[2]['fk_type']='VIR';
$r=$monthly->assemble($account,'2026-11',$bank,$badNative,'',$opening);
checkOpeningReport(!$r['map']['opening_valid'] && $r['map']['difference']===null,'A replaced adjustment of the same amount cannot impersonate the saved native opening');
$badBank=$bank; $badBank[2]['running_balance']='111';
$r=$monthly->assemble($account,'2026-11',$badBank,$native,'',$opening);
checkOpeningReport($r['map']['bank_close']===null && openingReportIssue($r,'BalanceChain')['status']==='error','Supplied source balances that conflict with the opening fail closed');
$badBank=$bank; $badBank[0]['status']=2;
$r=$monthly->assemble($account,'2026-11',$badBank,$native,'',$opening);
checkOpeningReport(openingReportIssue($r,'ReconciliationFlags')['status']==='error' && $r['bank_pending'][0]['status']==='review','Historical opening never erases corruption in existing source/native match evidence');
$badBank=$bank; $badBank[1]['opening_reconciled']=1;
$r=$monthly->assemble($account,'2026-11',$badBank,$native,'',$opening);
checkOpeningReport($r['bank_pending'][0]['rowid']===2,'A source projection flag cannot promote a movement on the boundary');
$zeroOpening=$opening; $zeroOpening['ledger_balance']='100'; $zeroOpening['adjustment_amount']='0'; $zeroOpening['fk_adjustment_bank_line']=null;
$r=$monthly->assemble($account,'2026-11',array(openingReportBank(1,'2026-11-01','1')),array(openingReportNative(1,'2026-10-31','100')),'',$zeroOpening);
checkOpeningReport($r['map']['opening_valid'] && $r['map']['bank_close']==='101.00000000','An already correct native balance needs no artificial zero adjustment');
$timingBank=array(openingReportBank(2,'2026-11-30','10',null,14)); $timingBank[0]['native_operation_date']='2026-12-01';
$timingNative=$historicalNative; $timingNative[]=openingReportNative(14,'2026-12-01','10');
$r=$monthly->assemble($account,'2026-11',$timingBank,$timingNative,'',$opening);
checkOpeningReport($r['bank']['pending']===1 && openingReportIssue($r,'ReconciliationFlags')['status']==='ok','Postcut cross-month native timing retains its normal pending contract');
$crossing=openingReportBank(9,'2026-10-31','20',null,13);
$r=$monthly->assemble($account,'2026-11',array($crossing),$native,'',$opening);
checkOpeningReport($r['bank_pending'][0]['status']==='review' && openingReportIssue($r,'ReconciliationFlags')['status']==='error','A source/native link crossing the migration boundary cannot reconcile postcut activity');
$sameDay=array(openingReportBank(20,'2026-11-01','1'),openingReportBank(21,'2026-11-01','2')); $sameDay[1]['statement_id']=2;
$r=$monthly->assemble($account,'2026-11',$sameDay,$historicalNative,'',$opening);
checkOpeningReport($r['map']['bank_close']==='103.00000000','Unordered same-day amounts without running balances retain their exact opening-anchored sum');
$mappedOpening=$zeroOpening; $mappedOpening['opening_balance']='27'; $mappedOpening['ledger_balance']='27';
$mappedSource=openingReportBank(81,'2026-10-31','27',null,81); $mappedSource['status']=0; $mappedSource['num_releve']='OPENING';
$mappedNative=openingReportNative(81,'2026-10-31','27'); $mappedNative['num_releve']='OPENING';
$r=$monthly->assemble($account,'2026-11',array($mappedSource),array($mappedNative),'',$mappedOpening);
checkOpeningReport($r['map']['opening_valid'] && openingReportIssue($r,'ReconciliationFlags')['status']==='ok' && $r['bank']['pending']===0 && $r['ledger']['pending']===0,'Opening-produced native flag/reference accepts its mapped historical staging row without rewriting its raw status');
checkOpeningReport($r['bank']['matched']===0 && !$r['bank_rows'] && !$r['ledger_rows'] && $mappedSource['status']===0 && $mappedSource['native_id']===81,'A mapped historical opening acceptance never manufactures a normal source/native match');
foreach (array('amount'=>'26','native_amount'=>'26','native_currency'=>'USD','operation_date'=>'2026-11-01','num_releve'=>'OTHER') as $field=>$value) {
	$bad=$mappedSource; $bad[$field]=$value;
	$r=$monthly->assemble($account,'2026-11',array($bad),array($mappedNative),'',$mappedOpening);
	checkOpeningReport(openingReportIssue($r,'ReconciliationFlags')['status']==='error' && $r['bank_pending'][0]['status']==='review','Historical mapped opening preserves amount, native evidence, currency, date boundary and reference checks');
}
$r=$monthly->assemble($account,'2026-11',array($mappedSource),array($mappedNative));
checkOpeningReport(openingReportIssue($r,'ReconciliationFlags')['status']==='error','Without a saved declaration, pending staging/native reconciliation disagreement remains an error');
print "OK: $openingReportChecks opening report pure checks\n";
