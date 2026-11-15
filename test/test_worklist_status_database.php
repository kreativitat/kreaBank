#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 * ONLY disposable 127.0.0.1:33317/kreabank_auto_test; resets kbauto_*.
 * Actual combined projection, actor-private drafts and History/native bank links.
 * Source status/count reads must never invoke matching or financial execution.
 */
require __DIR__.'/test_worklist_database.php';
require_once __DIR__.'/../lib/kreabank_history.lib.php';
require_once __DIR__.'/../lib/kreabank_worklist_undo.lib.php';
$checks=0;

function statusDatabaseState($extra=array())
{
	return kreabankWorklistState(array_merge(array('search_description'=>'STATUS-DB-QA'),$extra),10);
}

$db->query("INSERT INTO kbauto_bank_account(rowid,entity,ref,label,currency_code,clos,courant,rappro,fk_pays) VALUES"
	."(95901,1,'QS CLOSED','Synthetic closed bank','EUR',1,1,1,1),"
	."(95902,1,'QS CASH','Synthetic cash','EUR',0,2,0,1),"
	."(95903,1,'QS NO RECON','Synthetic nonreconcilable bank','EUR',0,1,0,1)");
foreach(array(
	95001=>array('101',101,1),95002=>array('102',101,1),95003=>array('103',101,1),95004=>array('104',101,1),
	95005=>array('105',95901,1),95006=>array('106',101,1),95007=>array('107',101,1),95008=>array('-30',101,1),
	95009=>array('109',202,2),95010=>array('110',95902,1),95011=>array('111',101,1),95012=>array('112',95903,1),
	95013=>array('-31',101,1),
) as $id=>$facts){
	stageAuto($id,$facts[0],'STATUS-DB-QA-'.$id,$facts[1],$facts[2]);
	$db->query("UPDATE kbauto_kreabank_statement_line SET description='STATUS-DB-QA source $id',operation_date='2031-04-10',value_date='2031-04-10' WHERE rowid=$id");
}
$db->query('UPDATE kbauto_kreabank_statement_line SET status=3 WHERE rowid=95006');
$db->query('UPDATE kbauto_kreabank_statement_line SET is_duplicate=1 WHERE rowid=95011');
foreach(array(95003=>'103',95004=>'104.50') as $source=>$amount){
	$native=$source+1000;bankAuto($native,$amount,'STATUS-DB-QA-'.$native,101,'2031-04-10');
	$db->query("UPDATE kbauto_bank SET rappro=1,num_releve='ST-$source' WHERE rowid=$native");
	$db->query("UPDATE kbauto_kreabank_statement_line SET status=2,allocated_amount=ABS(amount),fk_native_bank_line=$native WHERE rowid=$source");
}
// This legacy native import deliberately collides with the actor's staged draft.
bankAuto(95002,'17','STATUS-DB-QA-LEGACY',102,'2031-04-10');
$db->query("UPDATE kbauto_bank SET label='STATUS-DB-QA legacy source',rappro=1,num_releve='STATUS-LEGACY' WHERE rowid=95002");
$db->query("INSERT INTO kbauto_kreabank_bankmeta(entity,fk_bank_line,bank_account_id,idempotency_hash,amount,currency,status,datec)"
	." VALUES(1,95002,102,SHA2('status-legacy',256),17,'EUR',2,NOW())");
foreach(array(95008=>array('-30','-10','-20.01'),95013=>array('-31','-11','-20')) as $source=>$facts){
	$group=$source+2000;$first=$source+1000;$second=$first+100;
	bankAuto($first,$facts[1],'STATUS-DB-QA-MEMBER-'.$first,101,'2031-04-10');
	bankAuto($second,$facts[2],'STATUS-DB-QA-MEMBER-'.$second,101,'2031-04-10');
	$db->query("UPDATE kbauto_bank SET rappro=1,num_releve='ST-$source' WHERE rowid IN ($first,$second)");
	$db->query("UPDATE kbauto_kreabank_statement_line SET status=2,allocated_amount=ABS(amount) WHERE rowid=$source");
	$db->query("INSERT INTO kbauto_kreabank_payment_batch(rowid,entity,fk_statement_line,active_statement_line,bank_account_id,amount,currency,operation_date,statement_ref,name,status,source_snapshot,fk_user,datec)"
		." VALUES($group,1,$source,$source,101,{$facts[0]},'EUR','2031-04-10','ST-$source','Synthetic status group',1,'{}',7,NOW())");
	$expectedSecond=$source===95008?'-20':$facts[2];
	$db->query("INSERT INTO kbauto_kreabank_payment_batch_line(entity,fk_batch,fk_bank_line,active_bank_line,amount,snapshot_json)"
		." VALUES(1,$group,$first,$first,{$facts[1]},'{}'),(1,$group,$second,$second,$expectedSecond,'{}')");
}
foreach(array(array(1,7,95002),array(1,8,95007),array(2,7,95009),array(1,7,99999999),array(1,7,95003),array(1,7,95004)) as $draft){
	$db->query("INSERT INTO kbauto_kreabank_payment_batch_draft(entity,fk_user,fk_statement_line,name,ids_json,datec) VALUES({$draft[0]},{$draft[1]},{$draft[2]},'Synthetic private draft','[]',NOW())");
}
// Real native links are grouped by their bank owner, not by payment link order.
foreach(array(96013=>array(98013,99013),96113=>array(98113,99113)) as $bank=>$ids){
	$db->query("INSERT INTO kbauto_bank_url(fk_bank,url_id,url,label,type) VALUES"
		."($bank,{$ids[1]},'/user/card.php?id=','Synthetic employee {$ids[1]}','user'),"
		."($bank,{$ids[0]},'/salaries/payment_salary/card.php?id=','Synthetic salary {$ids[0]}','payment_salary')");
}
// Existing financial objects are fixture inputs; scalar Undo must only remove
// the reconciliation, retaining the original receipt, allocations and ledger.
$db->query("INSERT INTO kbauto_societe(rowid,entity,nom,client) VALUES(99701,1,'Synthetic Undo customer',1)");
$db->query("INSERT INTO kbauto_facture(rowid,entity,ref,fk_soc,datec,datef,total_ttc,fk_statut,paye,type) VALUES(99702,1,'STATUS-UNDO-INVOICE',99701,NOW(),'2031-04-10',103,2,1,0)");
$db->query("INSERT INTO kbauto_paiement(rowid,entity,ref,datec,datep,amount,fk_bank,fk_paiement,num_paiement) VALUES(99703,1,'STATUS-UNDO-RECEIPT',NOW(),'2031-04-10',103,96003,1,'STATUS-DB-QA-95003')");
$db->query("INSERT INTO kbauto_paiement_facture(rowid,fk_paiement,fk_facture,amount) VALUES(99704,99703,99702,103)");
$db->query("INSERT INTO kbauto_bank_url(fk_bank,url_id,url,label,type) VALUES(96003,99703,'/compta/paiement/card.php?id=','Recorded Undo fixture receipt','payment')");

function statusUndoFinancialState()
{
	global $db;$out=array();
	foreach(array('paiement'=>99703,'paiement_facture'=>99704,'facture'=>99702) as $table=>$id){$out[$table]=$db->query("SELECT * FROM kbauto_$table WHERE rowid=$id")->fetch_all(MYSQLI_ASSOC);}
	$out['bank']=$db->query('SELECT rowid,fk_account,dateo,datev,amount,label,num_chq,fk_type,note FROM kbauto_bank WHERE rowid=96003')->fetch_all(MYSQLI_ASSOC);
	$out['links']=$db->query('SELECT * FROM kbauto_bank_url WHERE fk_bank=96003 ORDER BY rowid')->fetch_all(MYSQLI_ASSOC);
	return $out;
}

class WorklistStatusReadDatabase
{
	private $inner;
	public $queries=array();
	public function __construct($inner){$this->inner=$inner;}
	public function query($sql){
		if(!preg_match('/^(SELECT|SHOW) /',$sql)){throw new RuntimeException('Status/History read attempted SQL mutation');}
		$this->queries[]=$sql;return $this->inner->query($sql);
	}
	public function __call($name,$arguments){return $this->inner->$name(...$arguments);}
}
$readonlyDb=new WorklistStatusReadDatabase($db);
$sourceOnlyService=new class {
	public $user;
	public function __call($name,$arguments){throw new RuntimeException('Status/count read invoked the matcher or financial service: '.$name);}
};
$sourceOnlyService->user=clone $user;
$worklist=new KreaBankWorklist($readonlyDb,1,$sourceOnlyService);
$before=worklistBusinessCounts();$draftCount=countAuto('kreabank_payment_batch_draft');$groupCount=countAuto('kreabank_payment_batch');
$expected=array('pending'=>3,'reconciled'=>3,'draft'=>1,'review'=>2,'all'=>11);
$initialCounts=$worklist->statusCounts(statusDatabaseState());
checkAuto($initialCounts===$expected,'Actual persisted source/native/group facts produce exact cross-status totals without matching: '.json_encode($initialCounts));
$all=$worklist->sources(statusDatabaseState(array('search_status'=>'all')));
checkAuto(count($all)===11 && !isset($all['staged:95009'],$all['staged:95010'],$all['staged:95011']),'Foreign, cash and duplicate evidence cannot enter the actual status/count projection');
checkAuto($all['staged:95002']['worklist_status']==='draft' && $all['native:95002']['worklist_status']==='reconciled','Actor-private staging drafts cannot overwrite a colliding legacy native identity');
checkAuto($all['staged:95003']['worklist_status']==='reconciled' && $all['staged:95004']['worklist_status']==='review','Stale planning rows never override verified reconciliation or an inconsistent native link');
checkAuto($all['staged:95008']['worklist_status']==='review' && $all['staged:95013']['worklist_status']==='reconciled','Normalized group integrity classifies complete and changed components before any candidate scan');
foreach(array('pending','reconciled','draft','review','all') as $status){
	$state=statusDatabaseState(array('search_status'=>$status,'limit'=>1,'page'=>9));
	$sources=$worklist->sources($state);$displayRows=array();
	foreach($sources as $key=>$source){$displayRows[]=array('key'=>$key,'line'=>$source,'status'=>$source['worklist_status']);}
	$displayRows=kreabankWorklistFilterRows($displayRows,$state);
	checkAuto(count($displayRows)===$expected[$status],'The real SQL-selected/classified/display-filtered rows agree with their status count: '.$status);
	checkAuto($worklist->statusCounts($state)===$expected,'Cross-status counts ignore the selected tab and pagination only: '.$status);
}
checkAuto(array_keys($worklist->sources(statusDatabaseState(array('search_status'=>'draft'))))===array('staged:95002'),'Only the current actor draft appears in the Draft source selection');
$sourceOnlyService->user->id=8;
checkAuto(array_keys($worklist->sources(statusDatabaseState(array('search_status'=>'draft'))))===array('staged:95007'),'A changed actor receives its own private draft index on the retained reader');
$sourceOnlyService->user->id=99;
checkAuto($worklist->statusCounts(statusDatabaseState())===array('pending'=>3,'reconciled'=>3,'draft'=>0,'review'=>2,'all'=>11),'An actor without planning rows receives zero drafts without changing financial classification');
$sourceOnlyService->user->id=7;
foreach(array(
	array(array('search_account'=>'102'),array('pending'=>0,'reconciled'=>1,'draft'=>0,'review'=>0,'all'=>1)),
	array(array('search_account'=>'202'),array('pending'=>0,'reconciled'=>0,'draft'=>0,'review'=>0,'all'=>0)),
	array(array('search_description'=>'STATUS-DB-QA source 95003'),array('pending'=>0,'reconciled'=>1,'draft'=>0,'review'=>0,'all'=>1)),
	array(array('search_amount'=>'<0'),array('pending'=>0,'reconciled'=>1,'draft'=>0,'review'=>1,'all'=>2)),
	array(array('search_date_from'=>'2031-04-11'),array('pending'=>0,'reconciled'=>0,'draft'=>0,'review'=>0,'all'=>0)),
) as $case){checkAuto($worklist->statusCounts(statusDatabaseState($case[0]))===$case[1],'Actual account/description/exact-amount/date predicates constrain all status totals: '.json_encode($case[0]));}
$unknown=array_fill_keys(array_keys($expected),null);
checkAuto($worklist->statusCounts(statusDatabaseState(array('search_match'=>'receipt'))) ===$unknown
	&& $worklist->statusCounts(statusDatabaseState(array('search_payment_account'=>'none'))) ===$unknown,'Unscanned candidate-derived filters report unknown rather than inventing empty counts or invoking matching');

$history=new KreaBankHistory($readonlyDb,1);$historyRows=array();
foreach($history->recent() as $row){if(strpos($row['description'],'STATUS-DB-QA')===0){$historyRows[$row['line_source'].':'.$row['rowid']]=$row;}}
checkAuto(count($historyRows)===5 && count(array_filter($historyRows,static function($row){return $row['needs_review'];}))===2,'Actual current History exposes the same three verified reconciliations and two link-review rows');
$links=$history->links(array_values($historyRows),new KreaBankNativeBankAdapter($readonlyDb,$user,$langs,1));
$components=kreabankHistoryLinkComponents($links['staged:95013']);
checkAuto(count($components)===2 && array_column($components,'native_bank_line_id')===array(96013,96113),'History associates each verified component with its actual native identity from the normalized group');
foreach($components as $component){
	$id=$component['native_bank_line_id'];$expectedIds=$id===96013?array(98013,99013):array(98113,99113);
	$actualIds=array_column($component['documents'],'fk_doc');sort($actualIds);
	checkAuto($actualIds===$expectedIds && count($component['bank_entries'])===1 && $component['bank_entries'][0]['fk_doc']===$id,'Real parent/payment bank_url rows remain paired with their exact native bank entry: '.$id);
}
checkAuto(!$links['staged:95008'] && $historyRows['staged:95008']['needs_review'],'Invalid group links remain a review state without fabricating per-member evidence');
checkAuto($before===worklistBusinessCounts() && $draftCount===countAuto('kreabank_payment_batch_draft') && $groupCount===countAuto('kreabank_payment_batch'),'Status/count/History reads preserve every source, native movement, private draft, group and audit count');
checkAuto(!array_filter($readonlyDb->queries,static function($sql){return !preg_match('/^(SELECT|SHOW) /',$sql);}), 'Real source/status/count/History code executes read-only SQL only');

$_SESSION=array();$financial=statusUndoFinancialState();$undoAudits=countAuto('kreabank_recon_audit');
$undo=kreabankWorklistPrepareUndo($history,'staged:95003',101);
checkAuto($undo && $undo['snapshot']['native_bank_line_id']==='96003' && statusUndoFinancialState()===$financial,'Actual safe scalar Undo preparation binds its native History snapshot without changing recorded financial objects');
checkAuto(kreabankWorklistPrepareUndo($history,'staged:95004',101)===null && kreabankWorklistPrepareUndo($history,'staged:95013',101)===null,'A scalar list Undo cannot admit an inconsistent source or bypass signed batch Undo');
$review=kreabankWorklistTakeUndo($history,'staged:95003',101,$undo['nonce']);
checkAuto($review===$undo['snapshot'] && !isset($_SESSION['kreabank_history_undo'][$undo['nonce']]),'Real History nonce consumption returns the original immutable scalar review once');
checkAuto($service->undoReconciliation(95003,'Disposable list scalar Undo verification',$review),'The consumed scalar list review executes through the existing shared native Undo lifecycle');
$source=$db->query('SELECT status,allocated_amount,fk_native_bank_line FROM kbauto_kreabank_statement_line WHERE rowid=95003')->fetch_object();
$native=$db->query('SELECT rappro,num_releve FROM kbauto_bank WHERE rowid=96003')->fetch_object();
checkAuto((int)$source->status===0 && (float)$source->allocated_amount===0.0 && (int)$source->fk_native_bank_line===0
	&& (int)$native->rappro===0 && $native->num_releve==='ST-95003','Undo restores only its imported source mapping to pending and preserves the same native statement evidence');
checkAuto(statusUndoFinancialState()===$financial && countAuto('kreabank_recon_audit')===$undoAudits+1,'Undo preserves the original native bank, paid invoice, receipt, allocation and links; one audit records the reversal');
checkAuto($history->find(95003,'staged',101)===null && $worklist->statusCounts(statusDatabaseState())===array('pending'=>4,'reconciled'=>2,'draft'=>2,'review'=>2,'all'=>11),'Current History and status counts agree after Undo; the retained private draft becomes available again');
$afterUndo=statusUndoFinancialState();$afterAudits=countAuto('kreabank_recon_audit');
rejectAuto(static function()use($history,$undo){kreabankWorklistTakeUndo($history,'staged:95003',101,$undo['nonce']);},'The consumed list Undo nonce cannot replay');
rejectAuto(static function()use($service,$review){$service->undoReconciliation(95003,'Disposable replay',$review);},'The service also rejects a replay of the already undone exact snapshot');
checkAuto(statusUndoFinancialState()===$afterUndo && countAuto('kreabank_recon_audit')===$afterAudits,'Rejected Undo retries neither delete nor duplicate any recorded financial object or audit');
echo 'OK: '.$checks." candidate-free worklist status and History database checks\n";
