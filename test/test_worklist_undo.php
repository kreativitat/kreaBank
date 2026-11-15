#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Installed request helpers and actual History nonce/snapshot with a read-only History observer.
 * No application bootstrap, database or financial execution.
 */
$nativeRoot=getenv('KREABANK_DOLIBARR_ROOT')?:dirname(__DIR__,3);
if (!is_file($nativeRoot.'/core/lib/functions.lib.php')) { fwrite(STDERR,"Set KREABANK_DOLIBARR_ROOT to installed Dolibarr source.\n"); exit(2); }
define('DOL_DOCUMENT_ROOT',$nativeRoot); define('DOL_URL_ROOT','');
require_once DOL_DOCUMENT_ROOT.'/core/lib/functions.lib.php';
require_once __DIR__.'/../lib/kreabank_worklist_undo.lib.php';
$conf=(object)array('entity'=>7,'global'=>new stdClass()); $user=(object)array('id'=>21); $_SESSION=array();
$checks=0;
function undoCheck($ok,$label) { global $checks; if (!$ok) { throw new RuntimeException($label); } $checks++; }
function undoReject($call,$label) { $caught=false; try { $call(); } catch (DomainException $error) { $caught=true; } undoCheck($caught,$label); }
function undoRow($source='staged')
{
	return array('rowid'=>71,'line_source'=>$source,'bank_account_id'=>101,'native_bank_line_id'=>$source==='staged'?801:71,
		'amount'=>'-42.25000000','native_amount'=>'-42.25000000','currency'=>'EUR','status'=>2,'rappro'=>1,
		'statement_ref'=>'SYNTHETIC-UNDO','num_releve'=>'SYNTHETIC-UNDO','operation_date'=>'2031-04-10',
		'description'=>'Synthetic reconciled movement','group_id'=>null,'needs_review'=>false);
}
class WorklistUndoHistoryObserver
{
	public $rows=array(),$reads=array();
	public function find($id,$source,$account) {
		global $conf;
		$this->reads[]=array($id,$source,$account);
		$row=$this->rows[$source.':'.$id]??null;
		return (int)$conf->entity===7 && $row && (int)$row['bank_account_id']===(int)$account ? $row : null;
	}
}
$history=new WorklistUndoHistoryObserver();
$history->rows=array('staged:71'=>undoRow(),'native:71'=>undoRow('native'));
$prepared=kreabankWorklistPrepareUndo($history,'staged:71',101);
undoCheck($prepared['snapshot']===KreaBankHistory::snapshot(undoRow()),'Preparation retains the exact native History snapshot');
undoCheck(preg_match('/^[a-f0-9]{48}$/D',$prepared['nonce'])===1,'Preparation uses the existing opaque native History nonce');
undoCheck($history->reads===array(array(71,'staged',101)),'Preparation fetches the typed source inside its explicit owned account');
$nonce=$prepared['nonce']; $original=$prepared['snapshot']; $before=count($_SESSION['kreabank_history_undo']);
undoCheck(kreabankWorklistTakeUndo($history,'staged:71',101,$nonce)===$original,'Fresh unchanged consumption returns the original snapshot for locked service Undo');
undoCheck(count($_SESSION['kreabank_history_undo'])===$before-1,'Consumption removes one review and creates no replacement nonce');
undoReject(static function()use($history,$nonce){kreabankWorklistTakeUndo($history,'staged:71',101,$nonce);},'Replayed confirmation cannot undo twice');

foreach (array('71','staged:0','native:071','other:71',array('staged:71')) as $key) {
	$count=count($_SESSION['kreabank_history_undo']);
	undoCheck(kreabankWorklistPrepareUndo($history,$key,101)===null,'Malformed/unnamespaced sources offer no Undo action');
	undoCheck(count($_SESSION['kreabank_history_undo'])===$count,'Invalid preparation cannot issue a review');
}
foreach (array(0,-1,'',array(101),'101other') as $account) {
	undoCheck(kreabankWorklistPrepareUndo($history,'staged:71',$account)===null,'Preparation requires a positive explicit account');
}
undoCheck(kreabankWorklistPrepareUndo($history,'staged:71',102)===null,'Another owned account cannot substitute for the displayed source');
foreach (array('needs_review'=>true,'group_id'=>9,'is_reversed'=>1,'native_bank_line_id'=>0,'rappro'=>0) as $field=>$value) {
	$history->rows['staged:71']=array_merge(undoRow(),array($field=>$value)); $before=count($_SESSION['kreabank_history_undo']);
	undoCheck(kreabankWorklistPrepareUndo($history,'staged:71',101)===null,'Unsafe scalar state offers no Undo: '.$field);
	undoCheck(count($_SESSION['kreabank_history_undo'])===$before,'Unsafe state creates no executable nonce: '.$field);
}
$history->rows['staged:71']=undoRow();
$prepared=kreabankWorklistPrepareUndo($history,'staged:71',101);
undoReject(static function()use($history,$prepared){kreabankWorklistTakeUndo($history,'native:71',101,$prepared['nonce']);},'Equal staged/native numeric IDs cannot consume each other’s review');
undoCheck(!isset($_SESSION['kreabank_history_undo'][$prepared['nonce']]),'A source-namespace mismatch consumes its rejected nonce');
$native=kreabankWorklistPrepareUndo($history,'native:71',101);
undoCheck(kreabankWorklistTakeUndo($history,'native:71',101,$native['nonce'])['native_bank_line_id']==='71','A reviewed legacy-native source retains its own bank identity');

foreach (array('entity','user','account','expiry') as $scope) {
	$conf->entity=7; $user->id=21;
	$prepared=kreabankWorklistPrepareUndo($history,'staged:71',101); $account=101;
	if ($scope==='entity') { $conf->entity=8; }
	if ($scope==='user') { $user->id=22; }
	if ($scope==='account') { $account=102; }
	if ($scope==='expiry') { $_SESSION['kreabank_history_undo'][$prepared['nonce']]['expires']=time()-1; }
	undoReject(static function()use($history,$prepared,$account){kreabankWorklistTakeUndo($history,'staged:71',$account,$prepared['nonce']);},'Native nonce enforces displayed scope: '.$scope);
	undoCheck(!isset($_SESSION['kreabank_history_undo'][$prepared['nonce']]),'Rejected scope/expiry cannot be retried: '.$scope);
}
$conf->entity=7; $user->id=21;
foreach (array('amount'=>'-42.24000000','native_amount'=>'-42.24000000','native_bank_line_id'=>802,'statement_ref'=>'CHANGED','description'=>'Changed source','operation_date'=>'2031-04-11','needs_review'=>true,'group_id'=>9,'is_reversed'=>1,'rappro'=>0) as $field=>$value) {
	$history->rows['staged:71']=undoRow(); $prepared=kreabankWorklistPrepareUndo($history,'staged:71',101);
	$history->rows['staged:71'][$field]=$value;
	undoReject(static function()use($history,$prepared){kreabankWorklistTakeUndo($history,'staged:71',101,$prepared['nonce']);},'Changed fresh History facts require a new review: '.$field);
	undoCheck(!isset($_SESSION['kreabank_history_undo'][$prepared['nonce']]),'A stale review is consumed once: '.$field);
}
$history->rows['staged:71']=undoRow(); $prepared=kreabankWorklistPrepareUndo($history,'staged:71',101); unset($history->rows['staged:71']);
undoReject(static function()use($history,$prepared){kreabankWorklistTakeUndo($history,'staged:71',101,$prepared['nonce']);},'A disappeared reconciliation cannot reuse its displayed review');
undoCheck(!isset($_SESSION['kreabank_history_undo'][$prepared['nonce']]),'A disappeared source cannot retain a retryable confirmation');

// Exercise the actual new controller boundary with installed GETPOST; the old unsupported
// "alphanum" filter erased native History nonces and must never return in this route.
$controller=file_get_contents(__DIR__.'/../reconciliationlist.php');
$matched=preg_match('/(kreabankWorklistTakeUndo\(\$history,\s*\$key,\s*\(int\)\s*\$line\[\x27bank_account_id\x27\],\s*GETPOST\(\x27undo_review\x27,\s*\x27aZ09\x27\)\))/',$controller,$match);
undoCheck($matched===1,'The list controller carries Undo nonces through the supported native scalar filter');
$history->rows['staged:71']=undoRow(); $prepared=kreabankWorklistPrepareUndo($history,'staged:71',101);
$_SERVER['REQUEST_METHOD']='POST'; $_GET=array(); $_POST=array('undo_review'=>' '.$prepared['nonce'].' ');
$key='staged:71'; $line=array('bank_account_id'=>101);
undoCheck(eval('return '.$match[1].';')===$prepared['snapshot'],'The actual list Undo request consumes its exact native History snapshot');

// A reviewed write-off reversal changes an invoice balance and deletes its created
// payment. Those targets can be cached under completely different statement amounts.
foreach (array('customer_invoice'=>1,'supplier_invoice'=>-1) as $invoiceType=>$direction) {
	$line=undoRow(); $line['amount']=$line['native_amount']=($direction<0?'-':'').'98.00000000';
	$ordinary=KreaBankHistory::snapshot($line);
	$ordinaryEffect=kreabankWorklistUndoEffect('staged:71',$line,$ordinary);
	undoCheck($ordinaryEffect['tokens']===array('native_bank:801') && $ordinaryEffect['keys']===array('staged:71') && $ordinaryEffect['financial'],
		'Ordinary scalar Undo preserves its existing native/source invalidation contract');
	$writeoff=array('invoice_type'=>$invoiceType,'invoice_id'=>501,'payment_id'=>502,'amount_open'=>'100.00',
		'bank_amount'=>'98.00','native_bank_line_id'=>801,'invoice_after'=>str_repeat('a',64),'allocations_after'=>str_repeat('b',64),'payment_after'=>str_repeat('c',64));
	$line['history_audit']=array('rowid'=>901,'audit_type'=>'reconcile','fk_statement_line'=>71,'payload_json'=>json_encode(array(
		'strategy'=>'manual_writeoff','source'=>'staged','native_bank_line_id'=>801,'writeoff'=>$writeoff)));
	$review=KreaBankHistory::snapshot($line);
	$effect=kreabankWorklistUndoEffect('staged:71',$line,$review);
	$paymentType=$direction>0?'payment':'payment_supplier'; $restored=($direction<0?'-':'').'100.00000000';
	undoCheck($effect['tokens']===array('native_bank:801',$invoiceType.':501',$paymentType.':502'),
		'Write-off Undo invalidates the exact invoice and created payment types: '.$invoiceType);
	undoCheck(count($effect['footprints'])===2 && $effect['footprints'][1]['amount']===$restored && $effect['footprints'][1]['currency']==='EUR'
		&& $effect['footprints'][1]['account']===101 && $effect['footprints'][1]['all_dates']===true,
		'Restored invoice amount has an exact signed footprint without an invented invoice date: '.$invoiceType);
	$source=static function($id,$amount,$date='2031-04-10',$currency='EUR') { return array('rowid'=>$id,'bank_account_id'=>101,'currency'=>$currency,'operation_date'=>$date,'amount_decimal'=>$amount); };
	$sources=array('staged:71'=>$source(71,$line['amount']),'staged:72'=>$source(72,'777.00000000','2045-04-10'),
		'staged:73'=>$source(73,'888.00000000','2045-04-10'),'staged:74'=>$source(74,$restored,'2045-04-10'),
		'staged:75'=>$source(75,'333.00000000'),'staged:76'=>$source(76,$restored,'2031-04-10','USD'),
		'staged:77'=>$source(77,$line['amount'],'2031-04-11'),'staged:78'=>$source(78,($direction>0?'-':'').'100.00000000'),
		'staged:79'=>$source(79,'333.00000000'));
	$scan=array('context'=>'synthetic-undo','scope'=>'synthetic-scope','generation'=>'before','cursor'=>8,'sources'=>kreabankWorklistSourceState($sources),'summaries'=>array(),'presentations'=>array());
	foreach ($sources as $key=>$raw) {
		$tokens=$key==='staged:72'?array($invoiceType.':501'):($key==='staged:73'?array($paymentType.':502'):($key==='staged:79'?array($invoiceType.':999'):array()));
		$scan['summaries'][$key]=array('key'=>$key,'dependencies'=>array('tokens'=>$tokens,'footprints'=>array(kreabankWorklistFootprint($raw))));
		$scan['presentations'][$key]=array('synthetic'=>'cached-'.$key);
	}
	$session=array('kreabank_worklist_scan'=>$scan,'kreabank_worklist_reviews'=>array('affected'=>array('payload'=>array('key'=>'staged:72')),'unrelated'=>array('payload'=>array('key'=>'staged:75'))));
	kreabankWorklistInvalidateScan($session,$effect,true);
	$remaining=array('staged:75','staged:76','staged:78','staged:79');
	undoCheck(array_keys($session['kreabank_worklist_scan']['summaries'])===$remaining,
		'Invoice/payment dependencies and restored-amount no-pair peers are evicted while unrelated amounts/currencies/signs remain: '.$invoiceType);
	undoCheck(array_keys($session['kreabank_worklist_scan']['presentations'])===$remaining,
		'Affected prepared details are evicted with their summaries: '.$invoiceType);
	undoCheck(array_keys($session['kreabank_worklist_reviews'])===array('unrelated') && $session['kreabank_worklist_scan']['generation']!=='before',
		'Stale dependent approvals are cleared without discarding unrelated progress: '.$invoiceType);
}
$validReview=$review; $validLine=$line;
foreach (array('missing_wrapper','missing_writeoff','invalid_audit','invalid_hash','invalid_type','invalid_invoice','invalid_payment','missing_amount','negative_amount','nested_amount') as $malformed) {
	$changed=$validReview;
	if ($malformed==='missing_wrapper') { $changed['writeoff']=null; }
	elseif ($malformed==='missing_writeoff') { unset($changed['writeoff']['writeoff']); }
	elseif ($malformed==='invalid_audit') { $changed['writeoff']['audit_id']=array(901); }
	elseif ($malformed==='invalid_hash') { $changed['writeoff']['audit_hash']='not-a-snapshot'; }
	elseif ($malformed==='invalid_type') { $changed['writeoff']['writeoff']['invoice_type']='native_bank'; }
	elseif ($malformed==='invalid_invoice') { $changed['writeoff']['writeoff']['invoice_id']='501other'; }
	elseif ($malformed==='invalid_payment') { $changed['writeoff']['writeoff']['payment_id']=0; }
	elseif ($malformed==='missing_amount') { unset($changed['writeoff']['writeoff']['amount_open']); }
	elseif ($malformed==='negative_amount') { $changed['writeoff']['writeoff']['amount_open']='-100.00'; }
	elseif ($malformed==='nested_amount') { $changed['writeoff']['writeoff']['amount_open']=array('100.00'); }
	undoReject(static function()use($validLine,$changed){kreabankWorklistUndoEffect('staged:71',$validLine,$changed);},
		'Malformed write-off evidence cannot return a success-shaped incomplete invalidation: '.$malformed);
}
echo 'OK: '.$checks." worklist scalar Undo checks (installed GETPOST, native History nonce/snapshot, no database)\n";
