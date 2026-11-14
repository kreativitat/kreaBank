#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
require_once __DIR__ . '/../class/KreaBankMonthlyReport.class.php';
$monthlyChecks = 0;
function checkMonthly($condition, $message) { global $monthlyChecks; if (!$condition) { throw new RuntimeException($message); } $monthlyChecks++; }
function monthlyIssue($report, $key) { foreach ($report['checks'] as $check) { if ($check['key'] === $key) { return $check; } } throw new RuntimeException('Missing check ' . $key); }
function monthlyBank($id, $date, $amount, $balance = null, $nativeId = 0) {
	return array('rowid'=>$id,'bank_account_id'=>101,'operation_date'=>$date,'amount'=>$amount,'currency'=>'EUR','running_balance'=>$balance,'statement_id'=>1,'line_rank'=>$id,'source_storage'=>'staging','expected_native_id'=>$nativeId,'native_id'=>$nativeId,'native_amount'=>$amount,'native_currency'=>'EUR','status'=>$nativeId ? 2 : 0,'rappro'=>$nativeId ? 1 : 0,'statement_ref'=>'ST','num_releve'=>'ST','description'=>'Source ' . $id);
}
function monthlyNative($id, $date, $amount, $matched = false) { return array('rowid'=>$id,'bank_account_id'=>101,'operation_date'=>$date,'amount'=>$amount,'currency'=>'EUR','rappro'=>$matched ? 1 : 0,'num_releve'=>'ST','description'=>'Native ' . $id); }
$monthly = new KreaBankMonthlyReport(null, 1);
$account = array('rowid'=>101,'entity'=>1,'ref'=>'TEST','label'=>'Synthetic account','currency_code'=>'EUR');
$bank = array(monthlyBank(1,'2026-08-01','100.00000001','100.00000001',11), monthlyBank(2,'2026-09-01','0.10000001','100.10000002',12), monthlyBank(3,'2026-09-30','-0.00000001','100.10000001'));
$native = array(monthlyNative(11,'2026-08-01','100.00000001',true),monthlyNative(12,'2026-09-01 23:59:59','0.10000001',true));
$r = $monthly->assemble($account,'2026-09',$bank,$native);
checkMonthly($r['bank']['movement']==='0.10000000' && $r['bank']['closing']==='100.10000001','Eight-place amounts sum without float rounding');
checkMonthly($r['ledger']['closing']==='100.10000002' && $r['map']['difference']==='0.00000000','Adjusted balances explain unmatched signed bank evidence');
checkMonthly($r['bank']['matched']===1 && count($r['bank_rows'])===2 && count($r['ledger_rows'])===1,'Prior matched details are omitted while balances retain history');
checkMonthly($r['map']['opening_difference']==='0.00000000' && $r['map']['month_difference']==='0.00000000','Opening and monthly differences partition exactly');
checkMonthly(!$r['map']['balance_confirmed'] && monthlyIssue($r,'BalanceConfirmed')['status']==='warning','Supplied balances are provisional until explicitly confirmed');
checkMonthly($r['end']==='2026-09-30' && strlen($r['generated_at'])===19,'Period and generation timestamp follow UTC contract');
foreach (array('UTC','Europe/Lisbon','America/New_York','Europe/Zurich') as $timezone) {
	date_default_timezone_set($timezone);
	checkMonthly($monthly->assemble($account,'2026-09',$bank,$native)['bank_pending'][0]['age']===0,'Ageing does not drift with server timezone');
}
$b = array(monthlyBank(1,'2026-08-01','10.00','10.00'),monthlyBank(2,'2026-09-15','5.00','15.00',12));
$n = array(monthlyNative(12,'2026-10-01','5.00',true),monthlyNative(13,'2026-09-30','7.00',true));
$r = $monthly->assemble($account,'2026-09',$b,$n);
checkMonthly($r['bank']['pending']===2 && $r['bank']['pending_amount']==='15.00000000','Bank sources matched to a next-month ledger remain open at cutoff');
checkMonthly($r['ledger']['closing']==='7.00000000' && $r['ledger']['pending']===1,'Next-month native rows never enter month closing');
checkMonthly($r['bank_pending'][0]['carried'] && $r['bank_pending'][0]['age']===60 && $r['bank']['over30']===1,'Prior open evidence carries with calendar ageing');
checkMonthly(monthlyIssue($r,'UnreconciledNative')['status']==='warning' && $r['ledger_pending'][0]['status']==='review','Native reconciliation flag without source evidence stays open for review');
$future = monthlyBank(3,'2026-10-01','7.00',null,13);
$r = $monthly->assemble($account,'2026-09',array($future),$n,'7.00');
checkMonthly($r['ledger']['pending']===1 && $r['bank']['count']===0,'Future bank evidence cannot cover a native prior-month movement');
checkMonthly($r['map']['bank_close']==='7.00000000' && $r['map']['balance_confirmed'],'A request-only explicit closing balance is distinguishable from source evidence');
$bad = monthlyBank(2,'2026-09-15','5.00','15.00',12); $bad['native_amount']='5.00000001';
$r = $monthly->assemble($account,'2026-09',array($bad),array(monthlyNative(12,'2026-09-15','5.00000001',true)));
checkMonthly($r['bank_rows'][0]['status']==='review' && $r['map']['difference']===null,'Sub-cent discrepancies never become verified matches');
$bad = monthlyBank(2,'2026-09-15','5.00','15.00',12); $bad['num_releve']='OTHER';
$r = $monthly->assemble($account,'2026-09',array($bad),array(monthlyNative(12,'2026-09-15','5.00',true)));
checkMonthly($r['bank']['matched']===0 && monthlyIssue($r,'ReconciliationFlags')['status']==='error','Source flag and statement reference must agree');
$bad = monthlyBank(2,'2026-09-15','5.00','15.00',12); $bad['claim_count']=2;
$r = $monthly->assemble($account,'2026-09',array($bad),array(monthlyNative(12,'2026-09-15','5.00',true)));
checkMonthly($r['bank']['matched']===0 && monthlyIssue($r,'ConflictingLinks')['status']==='error','Global future claims block current matching');
$bad = monthlyBank(2,'2026-09-15','5.00','15.00'); $bad['is_duplicate']=1;
$r = $monthly->assemble($account,'2026-09',array($bad),array());
checkMonthly($r['bank_rows'][0]['status']==='duplicate' && $r['bank']['count']===0 && $r['bank']['pending']===0 && $r['map']['bank_close']===null,'Duplicate evidence is visible, excluded from totals and cannot anchor a balance');
$bad = monthlyBank(2,'2026-09-15','5.00','15.00'); $bad['currency']='USD';
$r = $monthly->assemble($account,'2026-09',array($bad),array());
checkMonthly($r['bank']['count']===0 && monthlyIssue($r,'CurrencyMismatch')['status']==='error' && $r['map']['difference']===null,'Foreign-currency evidence cannot enter account totals');
$bad = monthlyBank(2,'2026-02-30','5.00','15.00');
$r = $monthly->assemble($account,'2026-09',array($bad),array());
checkMonthly($r['bank_rows'][0]['date']==='' && $r['bank_rows'][0]['status']==='review' && monthlyIssue($r,'InvalidDates')['status']==='error','Invalid calendar dates remain visible as errors');
$bad = monthlyBank(2,'2026-09-15','5,00','15.00');
$r = $monthly->assemble($account,'2026-09',array($bad),array());
checkMonthly(monthlyIssue($r,'InvalidAmounts')['status']==='error' && $r['map']['bank_close']===null,'Malformed amounts fail closed rather than changing sign or precision');
$a = monthlyBank(1,'2026-09-01','10.00','10.00'); $b = monthlyBank(2,'2026-09-01','5.00','15.00'); $b['statement_id']=2;
$r = $monthly->assemble($account,'2026-09',array($a,$b),array());
checkMonthly($r['map']['bank_close']===null && monthlyIssue($r,'BalanceOrder')['status']==='warning','Same-day multiple statements cannot invent a bank order');
$a['statement_sequence']=1; $b['statement_sequence']=2; $a['evidence_statement_ref']='REVIEWED'; $b['evidence_statement_ref']='REVIEWED';
$r = $monthly->assemble($account,'2026-09',array($b,$a),array());
checkMonthly($r['map']['bank_close']==='15.00000000','Explicit statement order supports a coherent balance chain');
$b['running_balance']='16.00';
$r = $monthly->assemble($account,'2026-09',array($a,$b),array());
checkMonthly($r['map']['bank_close']===null && monthlyIssue($r,'BalanceChain')['status']==='error','Broken supplied balances invalidate the source anchor');
$bad = monthlyBank(2,'2026-09-15','5.00'); $bad['status']=3;
$r = $monthly->assemble($account,'2026-09',array($bad),array());
checkMonthly($r['bank']['pending_amount']==='5.00000000' && $r['map']['bank_close']===null,'Skipped sources remain bank activity and unknown balances stay null');
foreach (array('2026-00','2026-13','2026-9','2026-09-01','2026-09 OR 1=1') as $month) {
	try { $monthly->assemble($account,$month,array(),array()); throw new RuntimeException('Invalid month accepted'); } catch (InvalidArgumentException $e) { $monthlyChecks++; }
}
foreach (array('1,23','1.123456789','1e2',' 1.00',1.5) as $value) {
	try { $monthly->assemble($account,'2026-09',array(),array(),$value); throw new RuntimeException('Invalid confirmed balance accepted'); } catch (InvalidArgumentException $e) { $monthlyChecks++; }
}
try { $monthly->assemble($account,'2026-09',array(),array(),'92233720368.54775808'); throw new RuntimeException('Overflow accepted'); } catch (OverflowException $e) { $monthlyChecks++; }
$badAccount=$account; $badAccount['entity']=2;
try { $monthly->assemble($badAccount,'2026-09',array(),array()); throw new RuntimeException('Foreign account accepted'); } catch (InvalidArgumentException $e) { $monthlyChecks++; }
$bad=monthlyBank(1,'2026-09-01','1'); $bad['bank_account_id']=102;
try { $monthly->assemble($account,'2026-09',array($bad),array()); throw new RuntimeException('Foreign evidence accepted'); } catch (InvalidArgumentException $e) { $monthlyChecks++; }
$b=monthlyBank(1,'2026-08-31','10.00','10.00',11);
$r=$monthly->assemble($account,'2026-09',array($b),array(monthlyNative(11,'2026-09-01','10.00',true)));
checkMonthly($r['map']['opening_difference']==='0.00000000' && $r['map']['month_difference']==='0.00000000','Prior bank/current native pair was pending at opening, despite matched cutoff state');
$b=monthlyBank(1,'2026-09-01','10.00','10.00',11);
$r=$monthly->assemble($account,'2026-09',array($b),array(monthlyNative(11,'2026-08-31','10.00',true)));
checkMonthly($r['map']['opening_difference']==='0.00000000' && $r['map']['month_difference']==='0.00000000','Prior native/current bank pair was pending at opening');
$b['source_storage']='legacy_native'; $b['status']=0;
$r=$monthly->assemble($account,'2026-09',array($b),array(monthlyNative(11,'2026-09-01','10.00',true)));
checkMonthly($r['bank']['matched']===1,'Legacy native rapprochement does not require newer metadata status');
$bad=monthlyBank(1,'2026-09-01','broken');
$r=$monthly->assemble($account,'2026-09',array($bad),array());
checkMonthly($r['bank_rows'][0]['amount']===null,'Malformed source amount never renders as a real zero');
$r=$monthly->assemble($account,'2026-09',array(),array(monthlyNative(1,'invalid','10.00')),'10.00');
checkMonthly($r['map']['ledger_close']===null && $r['map']['ledger_adjusted']===null && $r['ledger']['closing']===null,'Invalid native evidence cannot supply an authoritative partial closing balance');
checkMonthly($r['map']['balance_date']==='2026-09-30','Confirmed bank balance belongs to the selected close date');
$a=monthlyBank(1,'2026-09-01','5.00','15.00'); $b=monthlyBank(2,'2026-09-01','10.00','10.00');
$r=$monthly->assemble($account,'2026-09',array($a,$b),array());
checkMonthly($r['map']['bank_close']===null,'Reverse same-day file order is rejected instead of silently anchoring the wrong balance');
$b=monthlyBank(1,'2026-09-01','10.00','10.00',11); $b['native_id']=0;
$r=$monthly->assemble($account,'2026-09',array($b),array());
checkMonthly($r['bank_rows'][0]['native_id']===0 && monthlyIssue($r,'ReconciliationFlags')['status']==='error','Missing or foreign target pointer cannot become a native movement link');
$b=monthlyBank(1,'2026-09-01','10.00','10.00',11); $b['status']=1;
$r=$monthly->assemble($account,'2026-09',array($b),array(monthlyNative(11,'2026-09-01','10.00',true)));
checkMonthly($r['bank_rows'][0]['status']==='review' && monthlyIssue($r,'ReconciliationFlags')['status']==='error','Staging and native reconciliation-state disagreement remains explicit');
$b=monthlyBank(1,'2026-09-01','10.00','10.00'); $n=monthlyNative(11,'2026-09-01 99:99:99','10.00');
$r=$monthly->assemble($account,'2026-09',array($b),array($n));
checkMonthly($r['map']['ledger_close']===null && monthlyIssue($r,'InvalidDates')['status']==='error','Impossible native timestamps cannot enter the period balance');
// Limit+1 must reject the entire report, not produce success-shaped partial totals.
$boundedDb=new class {
	public $queries=array();
	public function prefix() { return 'kbmonthly_'; }
	public function escape($value) { return addslashes((string) $value); }
	public function plimit($limit) { return ' LIMIT ' . (int) $limit; }
	public function query($sql) {
		if (!preg_match('/^SELECT\\b/', $sql)) { throw new RuntimeException('Report attempted a write'); }
		$this->queries[]=$sql;
		return (object) array('kind'=>strpos($sql,'SELECT rowid,ref,label,currency_code,entity')===0 ? 'account' : (strpos($sql,'SELECT h.*,n.dateo')===0 ? 'bank' : 'empty'),'index'=>0);
	}
	public function fetch_object($result) {
		if ($result->kind==='account' && $result->index++===0) { return (object) array('rowid'=>101,'ref'=>'TEST','label'=>'Fixture','currency_code'=>'EUR','entity'=>1); }
		if ($result->kind==='bank' && $result->index++<KreaBankMonthlyReport::MAX_ROWS+1) { return (object) monthlyBank($result->index,'2026-09-01','1.00'); }
		return false;
	}
};
try { (new KreaBankMonthlyReport($boundedDb,1))->load(101,'2026-09'); throw new RuntimeException('Truncated report accepted'); } catch (RuntimeException $e) { checkMonthly(strpos($e->getMessage(),'complete-evidence limit')!==false,'Limit+1 rejects a truncated source set'); }
checkMonthly(strpos(end($boundedDb->queries),'LIMIT 20001')!==false,'Loader bounds the SQL result before fetching business rows');
$n1=monthlyNative(1,'2026-08-31','5.00000000',true);
$n2=monthlyNative(2,'2026-09-01','0.00000001',true);
$n3=monthlyNative(3,'2026-09-01','-2.00000002');
$nativeInput=array($n3,$n1,$n2);
$r=$monthly->assemble($account,'2026-09',array(monthlyBank(1,'2026-08-31','5.00000000','5.00000000',1),monthlyBank(2,'2026-09-01','0.00000001','5.00000001',2)),$nativeInput);
checkMonthly(count($r['ledger_rows'])===2 && $r['ledger_rows'][0]['rowid']===2 && $r['ledger_rows'][0]['balance']==='5.00000001','First visible native row includes all prior ledger amounts before detail slicing');
checkMonthly($r['ledger_rows'][1]['rowid']===3 && $r['ledger_rows'][1]['balance']==='2.99999999','Same-day native running balances use stable row ID order and exact decimals');
checkMonthly($nativeInput===array($n3,$n1,$n2) && $r['ledger_rows'][1]['amount']==='-2.00000002','Running balances preserve original source decimals and input rows');
$n2['amount']='invalid';
$r=$monthly->assemble($account,'2026-09',array(),array($n1,$n2,$n3));
checkMonthly($r['ledger_rows'][0]['balance']==='5.00000000' && $r['ledger_rows'][1]['balance']===null && $r['ledger_rows'][2]['balance']===null && $r['map']['ledger_close']===null,'Invalid native evidence invalidates subsequent running balances and closing headlines');
print "OK: $monthlyChecks monthly report pure checks\n";
