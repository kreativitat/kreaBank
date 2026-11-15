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
echo 'OK: '.$checks." worklist scalar Undo checks (installed GETPOST, native History nonce/snapshot, no database)\n";
