#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 * ONLY disposable 127.0.0.1:33317/kreabank_auto_test; never an installation DB.
 */
require __DIR__ . '/test_worklist_database.php';
require_once __DIR__ . '/../lib/kreabank_history.lib.php';
$conf->entity = 1; $checks = 0;
$db->query('CREATE TABLE IF NOT EXISTS kbauto_user (rowid INT PRIMARY KEY,login VARCHAR(80))');
$db->query("INSERT INTO kbauto_user VALUES (7,'History tester')");
stageAuto(50101, '125.00000000', 'HISTORY-STAGED', 101);
bankAuto(50201, '125.00000000', 'HISTORY-STAGED', 101);
bankAuto(50101, '-17.00000000', 'HISTORY-NATIVE', 102);
$db->query("UPDATE kbauto_kreabank_statement_line SET status=2,allocated_amount=125,fk_native_bank_line=50201 WHERE rowid=50101");
$db->query("UPDATE kbauto_bank SET rappro=1,num_releve=IF(rowid=50201,'ST-50101','HISTORY-NATIVE') WHERE rowid IN (50201,50101)");
$db->query("INSERT INTO kbauto_kreabank_bankmeta (entity,fk_bank_line,bank_account_id,idempotency_hash,amount,currency,status,datec) VALUES (1,50101,102,SHA2('history-native',256),-17,'EUR',2,NOW())");
$db->query("INSERT INTO kbauto_bank_url(fk_bank,url_id,url,label,type) VALUES (50201,1001,'/compta/paiement/card.php?id=','Staged document','payment'),(50101,1002,'/compta/paiement/card.php?id=','Native document','payment')");
foreach (array(array(50101,50201,'staged','auto_native'),array(50101,50101,'native','manual')) as $data) {
	$payload = $db->escape(json_encode(array('native_bank_line_id'=>$data[1],'source'=>$data[2],'strategy'=>$data[3])));
	$db->query("INSERT INTO kbauto_kreabank_recon_audit(entity,audit_type,fk_statement_line,payload_json,fk_user,datec) VALUES (1,'reconcile',{$data[0]},'$payload',7,'2026-11-14 11:00:00')");
}
$reader = new KreaBankHistory($db, 1);
$before = array(countAuto('bank'),countAuto('kreabank_statement_line'),countAuto('kreabank_recon_audit'));
$rows = $reader->recent(); $indexed = array();
foreach ($rows as $row) { $indexed[$row['line_source'] . ':' . $row['rowid']] = $row; }
checkAuto(isset($indexed['staged:50101'],$indexed['native:50101']), 'Both storage generations remain visible when numeric IDs collide');
checkAuto($indexed['staged:50101']['strategy']==='auto_native' && $indexed['native:50101']['strategy']==='manual', 'Audit metadata follows the explicit source and native mapping');
checkAuto($indexed['staged:50101']['history_audit']['login']==='History tester', 'Reconciliation actor and event time are retained');
foreach (array(101=>'staged',102=>'native') as $account=>$source) {
	$scoped = $reader->recent(5000,0,$account);
	foreach ($scoped as $row) { checkAuto((int) $row['bank_account_id']===$account,'History scope is applied before bounding and pagination'); }
	checkAuto(count(array_filter($scoped,static function($row)use($source){return (int)$row['rowid']===50101 && $row['line_source']===$source;}))===1,'Selected-account History retains modern and legacy sources');
}
checkAuto($reader->find(50101,'staged',202)===null, 'Foreign account cannot select a staged history row');
checkAuto((new KreaBankHistory($db,2))->find(50101,'native')===null,'Foreign company cannot read a native history row');
$adapter = new KreaBankNativeBankAdapter($db,$user,$langs,1);
$links = $adapter->getLineLinksBatch(array(50201,50101),'native');
checkAuto($links[50201][0]['fk_doc']===1001 && $links[50101][0]['fk_doc']===1002,'Explicit native document lookup never remaps a colliding staged ID');
checkAuto($before===array(countAuto('bank'),countAuto('kreabank_statement_line'),countAuto('kreabank_recon_audit')),'History reads do not write business records');
// Valid payment groups expose every component, without a representative bank ID.
$groupRows = array_values(array_filter($reader->recent(),static function($row){return !empty($row['group_id']) && !$row['needs_review'];}));
checkAuto(count($groupRows)>0,'Synthetic worklist fixture supplies an active verified payment group');
$groupLinks=$reader->links($groupRows,$adapter);
foreach($groupRows as $group){
	$key='staged:'.$group['rowid'];
	$bankLinks=array_filter($groupLinks[$key],static function($link){return $link['doc_type']==='bank_line';});
	checkAuto(count($bankLinks)===(int)$group['group_count'] && (int)$group['native_bank_line_id']===0,'History exposes every verified group member without an aggregate movement');
	$member=(int)reset($bankLinks)['fk_doc'];
	$db->query('UPDATE kbauto_bank SET amount=amount+1 WHERE rowid='.$member);
	checkAuto($reader->find($group['rowid'],'staged')['needs_review'],'A changed group component flags History for review');
	$db->query('UPDATE kbauto_bank SET amount=amount-1 WHERE rowid='.$member);
}
$conf->entity=2;
rejectAuto(static function()use($service,$reader){$service->undoReconciliation(50101,'Retained service',KreaBankHistory::snapshot($reader->find(50101,'native')));},'A retained service cannot undo after switching the active company');
$conf->entity=1;
$nativeReview = KreaBankHistory::snapshot($reader->find(50101,'native'));
$stagedReview = KreaBankHistory::snapshot($reader->find(50101,'staged'));
rejectAuto(static function()use($service){$service->undoReconciliation(50101);},'Undo rejects a missing displayed snapshot');
$db->fail='INSERT INTO kbauto_kreabank_recon_audit';
rejectAuto(static function()use($service,$nativeReview){$service->undoReconciliation(50101,'Synthetic audit failure',$nativeReview);},'Audit failure rejects Undo');
$db->fail='';
checkAuto((int)$db->query('SELECT rappro FROM kbauto_bank WHERE rowid=50101')->fetch_object()->rappro===1
	&& (int)$db->query('SELECT status FROM kbauto_kreabank_bankmeta WHERE fk_bank_line=50101')->fetch_object()->status===2,'Audit failure rolls back native conciliation and imported status');
$db->query("UPDATE kbauto_bank SET amount=-18 WHERE rowid=50101");
rejectAuto(static function()use($service,$nativeReview){$service->undoReconciliation(50101,'Stale displayed amount',$nativeReview);},'Stale exact amount cannot be undone from an old display');
$db->query('UPDATE kbauto_bank SET amount=-17 WHERE rowid=50101');
$user->allowed=false;
rejectAuto(static function()use($service,$nativeReview){$service->undoReconciliation(50101,'Forbidden',$nativeReview);},'Undo rechecks native and module permissions');
$user->allowed=true;
checkAuto($service->undoReconciliation(50101,'Synthetic native Undo',$nativeReview),'Native Undo succeeds through the native lifecycle');
checkAuto((int)$db->query('SELECT status FROM kbauto_kreabank_statement_line WHERE rowid=50101')->fetch_object()->status===2
	&& (int)$db->query('SELECT rappro FROM kbauto_bank WHERE rowid=50201')->fetch_object()->rappro===1,'Native Undo preserves the unrelated colliding staged reconciliation');
checkAuto($db->query('SELECT num_releve FROM kbauto_bank WHERE rowid=50101')->fetch_object()->num_releve==='HISTORY-NATIVE','Undo preserves imported statement identity for pending review');
checkAuto($reader->find(50101,'native')===null,'Undone native source leaves reconciled History');
rejectAuto(static function()use($service,$nativeReview){$service->undoReconciliation(50101,'Replay',$nativeReview);},'A repeated Undo fails closed');
checkAuto($service->undoReconciliation(50101,'Synthetic staged Undo',$stagedReview),'Staged Undo uses its explicit mapping despite a native ID collision');
checkAuto((int)$db->query('SELECT status FROM kbauto_kreabank_statement_line WHERE rowid=50101')->fetch_object()->status===0
	&& (int)$db->query('SELECT rappro FROM kbauto_bank WHERE rowid=50201')->fetch_object()->rappro===0,'Staged Undo resets exactly its imported source and native match');
// Commit failure must not be presented as a successful Undo.
class HistoryCommitDb extends DoliDB {
	public $rejectCommit=false;
	public function commit(){if($this->rejectCommit && $this->depth===1){return false;}return parent::commit();}
}
$db->query("UPDATE kbauto_bank SET rappro=1 WHERE rowid=50101");
$db->query('UPDATE kbauto_kreabank_bankmeta SET status=2 WHERE fk_bank_line=50101');
$commitDb=new HistoryCommitDb(); $commitService=new KreaBankService($commitDb,$user,$langs); $commitDb->rejectCommit=true;
rejectAuto(static function()use($commitService,$nativeReview){$commitService->undoReconciliation(50101,'Commit failure',$nativeReview);},'Commit failure is reported instead of success');
checkAuto((int)$db->query('SELECT rappro FROM kbauto_bank WHERE rowid=50101')->fetch_object()->rappro===1,'Uncommitted Undo is rolled back');
echo "OK: $checks History database checks\n";
