#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 * ONLY the guarded disposable 127.0.0.1:33317/kreabank_auto_test fixture.
 * Uses installed native AccountLine. Never bootstrap installation configuration.
 */
require __DIR__ . '/test_automatic_reconciliation_database.php';
$initialChecks=$checks;
foreach (array('salary','payment_salary','accounting_bookkeeping-accounting') as $table) {
	$sql=preg_replace('/--[^\n]*/','',file_get_contents(DOL_DOCUMENT_ROOT.'/install/mysql/tables/llx_'.$table.'.sql'));
	foreach (explode(';',str_replace('llx_',MAIN_DB_PREFIX,$sql)) as $statement) { if (trim($statement)!=='') { $db->query($statement); } }
}
$schema=preg_replace('/--[^\n]*/','',file_get_contents(__DIR__.'/../sql/llx_kreabank_payment_batch.sql'));
for ($run=0;$run<2;$run++) {
	foreach (explode(';',str_replace('llx_',MAIN_DB_PREFIX,$schema)) as $statement) { if (trim($statement)!=='') { checkAuto((bool)$db->query($statement),'Batch schema is safe to install repeatedly'); } }
}
checkAuto($service->hasPaymentBatchSchema(),'Batch schema is detected without page-load DDL');
$_SESSION=array();
stageAuto(95000,'-3000.00000003','PAYROLL-NOVEMBER');
foreach (array(96000=>array('1000.00000001',97000,98000,17),96001=>array('2000.00000002',97001,98001,18)) as $bankId=>$facts) {
	list($amount,$paymentId,$salaryId,$employeeId)=$facts;
	bankAuto($bankId,'-'.$amount,'SALARY-'.$paymentId);
	$db->query("INSERT INTO kbauto_salary (rowid,entity,fk_user,amount,fk_typepayment,paye,label) VALUES($salaryId,1,$employeeId,$amount,6,1,'Synthetic November payroll')");
	$db->query("INSERT INTO kbauto_payment_salary (rowid,entity,fk_salary,fk_bank,amount,fk_typepayment,num_payment,label) VALUES($paymentId,1,$salaryId,$bankId,$amount,6,'SALARY-$paymentId','Synthetic paid salary')");
	$db->query("INSERT INTO kbauto_bank_url (fk_bank,url_id,url,label,type) VALUES($bankId,$paymentId,'/synthetic/','Salary $paymentId','payment_salary'),($bankId,$employeeId,'/synthetic/','Employee $employeeId','user')");
}
$ids=array(96000,96001);
function batchDatabaseReview($source=95000,$ids=array(96000,96001)) { global $service; return $service->preparePaymentBatchReview($source,$ids,'November salaries'); }
function batchDatabaseState() {
	global $db;
	return array('bank'=>$db->query('SELECT rowid,fk_account,CAST(amount AS DECIMAL(24,8)) amount,rappro,num_releve FROM kbauto_bank WHERE rowid IN (96000,96001) ORDER BY rowid')->fetch_all(MYSQLI_ASSOC),
		'source'=>$db->query('SELECT status,allocated_amount,fk_native_bank_line FROM kbauto_kreabank_statement_line WHERE rowid=95000')->fetch_all(MYSQLI_ASSOC),
		'groups'=>countAuto('kreabank_payment_batch'),'members'=>countAuto('kreabank_payment_batch_line'),'audit'=>countAuto('kreabank_recon_audit'));
}
$candidates=$service->listBatchCandidates(95000);
checkAuto(count(array_intersect($ids,array_column($candidates['rows'],'rowid')))===2,'Separate existing salary movements are discoverable');
bankAuto(96002,'-1000.00000001','OUTSIDE-BATCH-DAY',101,'2026-10-31');
bankAuto(96003,'-2000.00000002','OUTSIDE-BATCH-DAY',101,'2026-11-02');
checkAuto(!array_intersect(array(96002,96003),array_column($service->listBatchCandidates(95000)['rows'],'rowid')),'Neighboring booking days never enter pending batch discovery');
checkAuto($service->listBatchCandidates(95000,'OUTSIDE-BATCH-DAY')['rows']===array(),'An explicit batch search cannot widen the booking day');
$offDay=$service->getPaymentBatchSelectedCandidates(95000,array(96000,96002));
checkAuto(array_map('intval',array_column($offDay['rows'],'rowid'))===array(96000) && $offDay['unavailable']===array(96002),'Retained off-day draft members are unavailable rather than silently carried into review');
rejectAuto(static function () use ($service) { $service->savePaymentBatchDraft(95000,array(96002,96001),'Off-day draft'); },'New drafts cannot contain another booking day');
rejectAuto(static function () use ($service) { $service->preparePaymentBatchReview(95000,array(96002,96001),'Off-day review'); },'Exact totals do not permit off-day review members');
$dateReview=$service->preparePaymentBatchReview(95000,$ids,'Same-day review');
$db->query("UPDATE kbauto_bank SET dateo='2026-11-02' WHERE rowid=96001");
$dateState=batchDatabaseState();
rejectAuto(static function () use ($service,$dateReview) { $service->reconcilePaymentBatch($dateReview); },'Execution rejects a selected movement moved to another booking day');
checkAuto(batchDatabaseState()===$dateState && $db->depth===0,'Date rejection creates no native, group, source or audit changes');
$db->query("UPDATE kbauto_bank SET dateo='2026-11-01' WHERE rowid=96001");
checkAuto($service->listBatchCandidates(95000,'SALARY-97000')['rows'][0]['amount_decimal']==='-1000.00000001','Candidate display retains persisted eight-decimal strings');
checkAuto($service->listBatchCandidates(95000,'%')['rows']===array(),'Search wildcard input remains literal');
$rehydrated=$service->getPaymentBatchSelectedCandidates(95000,array(96001,99999,96000));
checkAuto(array_map('intval',array_column($rehydrated['rows'],'rowid'))===$ids && $rehydrated['unavailable']===array(99999),'Explicit selections outside the search page are rehydrated without dropping missing IDs');
$beforeNative=countAuto('bank'); $beforePayroll=$db->query('SELECT * FROM kbauto_payment_salary ORDER BY rowid')->fetch_all(MYSQLI_ASSOC);
$beforeSalary=$db->query('SELECT * FROM kbauto_salary ORDER BY rowid')->fetch_all(MYSQLI_ASSOC);
$beforeLinks=$db->query('SELECT * FROM kbauto_bank_url ORDER BY rowid')->fetch_all(MYSQLI_ASSOC);
$beforeLearning=countAuto('kreabank_ml_sample');
$service->savePaymentBatchDraft(95000,array(96001,96000),'November salaries');
checkAuto($service->loadPaymentBatch(95000)['draft']['ids']===$ids,'A named draft preserves explicit selections in native ID order');
$otherUser=clone $user; $otherUser->id=8;
$otherService=new KreaBankService($db,$otherUser,$langs);
checkAuto($otherService->loadPaymentBatch(95000)['draft']===null,'Drafts are private to their reviewing user');
$otherService->savePaymentBatchDraft(95000,array(96000),'Other reviewer');
checkAuto($service->loadPaymentBatch(95000)['draft']['name']==='November salaries','Another reviewer cannot overwrite this draft');
$review=batchDatabaseReview();
checkAuto($review['amount']==='-3000.00000003' && !isset($review['components'][0]['payment_salary']),'Review signs exact sum without exposing complete payroll rows');
rejectAuto(static function () use ($service) { $service->preparePaymentBatchReview(95000,array(96000),'Incomplete'); },'One component is not a payment batch');
rejectAuto(static function () use ($service) { $service->preparePaymentBatchReview(95000,array(96000,96000),'Duplicate'); },'Duplicate native IDs cannot inflate a batch total');
$db->query('UPDATE kbauto_bank SET fk_account=102 WHERE rowid=96001');
rejectAuto(static function () { batchDatabaseReview(); },'A movement in another owned account cannot be grouped');
$rehydrated=$service->getPaymentBatchSelectedCandidates(95000,$ids);
checkAuto(count($rehydrated['rows'])===1 && $rehydrated['unavailable']===array(96001),'Ineligible selected components remain explicit unavailable IDs rather than fake current facts');
$db->query('UPDATE kbauto_bank SET fk_account=101 WHERE rowid=96001');
$db->query('UPDATE kbauto_bank SET amount=-2000.00000003 WHERE rowid=96001');
rejectAuto(static function () { batchDatabaseReview(); },'A one-unit difference at decimal place eight cannot reconcile');
$db->query('UPDATE kbauto_bank SET amount=-2000.00000002 WHERE rowid=96001');
foreach (array('kbauto_salary'=>'98001','kbauto_payment_salary'=>'97001') as $table=>$rowId) {
	$db->query("UPDATE $table SET entity=2 WHERE rowid=$rowId");
	rejectAuto(static function () { batchDatabaseReview(); },'Owned native bank rows do not override foreign payroll parent ownership');
	$db->query("UPDATE $table SET entity=1 WHERE rowid=$rowId");
}
foreach (array('rappro=1','fk_bordereau=7',"fk_type='SOLD'") as $change) {
	$db->query('UPDATE kbauto_bank SET '.$change.' WHERE rowid=96001');
	rejectAuto(static function () { batchDatabaseReview(); },'Reconciled, cheque-deposit and opening-balance entries are protected');
	$db->query('UPDATE kbauto_bank SET rappro=0,fk_bordereau=NULL,fk_type=NULL WHERE rowid=96001');
}
$db->query("INSERT INTO kbauto_bank_url (fk_bank,url_id,url,label,type) VALUES(96001,96000,'/synthetic/','Protected transfer','banktransfert')");
rejectAuto(static function () { batchDatabaseReview(); },'A native transfer cannot be absorbed into a salary group');
$db->query("DELETE FROM kbauto_bank_url WHERE fk_bank=96001 AND type='banktransfert'");
$db->query('UPDATE kbauto_kreabank_statement_line SET is_duplicate=1 WHERE rowid=95000');
rejectAuto(static function () { batchDatabaseReview(); },'Duplicate source evidence cannot be reconciled');
$db->query('UPDATE kbauto_kreabank_statement_line SET is_duplicate=0 WHERE rowid=95000');
$db->query("UPDATE kbauto_kreabank_statement_line SET currency='USD' WHERE rowid=95000");
rejectAuto(static function () { batchDatabaseReview(); },'Source currency must equal the bank account and statement currency');
$db->query("UPDATE kbauto_kreabank_statement_line SET currency='EUR' WHERE rowid=95000");
stageAuto(95005,'-2000.00000002','MAPPED-SOURCE');
$db->query('UPDATE kbauto_kreabank_statement_line SET fk_native_bank_line=96001 WHERE rowid=95005');
rejectAuto(static function () { batchDatabaseReview(); },'An existing scalar source mapping cannot be claimed by a group');
$db->query('UPDATE kbauto_kreabank_statement_line SET fk_native_bank_line=NULL WHERE rowid=95005');
$db->query("INSERT INTO kbauto_kreabank_bankmeta (entity,fk_bank_line,bank_account_id,source_type,idempotency_hash,amount,datec) VALUES(1,96001,101,'csv',SHA2('batch-imported',256),-2000.00000002,NOW())");
rejectAuto(static function () { batchDatabaseReview(); },'Imported native evidence cannot be used as a payment component');
$db->query('DELETE FROM kbauto_kreabank_bankmeta WHERE fk_bank_line=96001');
$conf->entity=2; $foreignService=new KreaBankService($db,$user,$langs);
rejectAuto(static function () use ($foreignService) { $foreignService->listBatchCandidates(95000); },'Another company cannot inspect grouped payroll candidates');
$conf->entity=1;
class BatchReadOnlyUser extends User { public function hasRight($a,$b,$c='') { return !($a==='kreabank' && $c==='write') && !($a==='banque' && in_array($b,array('modifier','consolidate'),true)); } }
$reader=new BatchReadOnlyUser(); $readOnlyService=new KreaBankService($db,$reader,$langs);
checkAuto(count($readOnlyService->listBatchCandidates(95000)['rows'])>=2,'Readers may inspect bank candidates');
checkAuto(count($readOnlyService->getPaymentBatchSelectedCandidates(95000,$ids)['rows'])===2,'Read rights may inspect explicit draft selections without approving them');
rejectAuto(static function () use ($readOnlyService) { $readOnlyService->savePaymentBatchDraft(95000,array(96000),'Unauthorized'); },'Read access cannot save an executable draft');
rejectAuto(static function () use ($readOnlyService) { $readOnlyService->preparePaymentBatchReview(95000,array(96000,96001),'Unauthorized'); },'Read access cannot prepare approval');
$policy=new KreaBankAccountPairs($db,1); $policy->configure($user,array(101=>102));
rejectAuto(static function () { batchDatabaseReview(); },'Restricted transfer-only accounts cannot reconcile payroll groups');
$policy->configure($user,array());
$review=batchDatabaseReview();
$db->query("UPDATE kbauto_payment_salary SET note='Changed after review' WHERE rowid=97001");
rejectAuto(static function () use ($service,$review) { $service->reconcilePaymentBatch($review); },'Changed payroll allocations or evidence invalidates a displayed review');
$db->query('UPDATE kbauto_payment_salary SET note=NULL WHERE rowid=97001');
$review=batchDatabaseReview();
$db->beforeBegin=static function () use ($db) { $db->query("UPDATE kbauto_kreabank_statement_line SET description='Concurrent change' WHERE rowid=95000"); };
rejectAuto(static function () use ($service,$review) { $service->reconcilePaymentBatch($review); },'A concurrent source edit is detected after locking');
$db->query("UPDATE kbauto_kreabank_statement_line SET description='Received PAYROLL-NOVEMBER' WHERE rowid=95000");
foreach (array('INSERT INTO kbauto_kreabank_payment_batch ','INSERT INTO kbauto_kreabank_payment_batch_line ','UPDATE kbauto_kreabank_statement_line SET status=2','INSERT INTO kbauto_kreabank_recon_audit') as $failure) {
	$review=batchDatabaseReview(); $state=batchDatabaseState(); $db->fail=$failure;
	rejectAuto(static function () use ($service,$review) { $service->reconcilePaymentBatch($review); },'Write failure is visible: '.$failure);
	$db->fail='';
	checkAuto(batchDatabaseState()===$state && $db->depth===0,'Failure restores every native component, source, mapping and audit');
}
class BatchSecondNativeFailureDb extends DoliDB
{
	public $matchingUpdates=0;
	public $failConciliated=1;
	public function __construct($connection) { $this->connection=$connection; }
	public function query($sql) {
		if (strpos($sql,'UPDATE kbauto_bank SET rappro = '.$this->failConciliated)===0 && ++$this->matchingUpdates===2) { return false; }
		return parent::query($sql);
	}
}
$faultDb=new BatchSecondNativeFailureDb($db->connection); $faultService=new KreaBankService($faultDb,$user,$langs);
$review=$faultService->preparePaymentBatchReview(95000,$ids,'November salaries'); $state=batchDatabaseState();
rejectAuto(static function () use ($faultService,$review) { $faultService->reconcilePaymentBatch($review); },'Failure on the second native lifecycle call rolls back the first');
checkAuto(batchDatabaseState()===$state,'Native nested transactions preserve atomic batch rollback');
// Two prepared groups competing for one component cannot both claim it.
stageAuto(95002,'-3000.00000003','SECOND-CLAIM');
$competitor=batchDatabaseReview(95002); $review=batchDatabaseReview();
$beforePayroll=$db->query('SELECT * FROM kbauto_payment_salary ORDER BY rowid')->fetch_all(MYSQLI_ASSOC);
$beforeSalary=$db->query('SELECT * FROM kbauto_salary ORDER BY rowid')->fetch_all(MYSQLI_ASSOC);
$batchId=$service->reconcilePaymentBatch($review);
checkAuto($batchId>0 && countAuto('bank')===$beforeNative,'A salary batch creates no summary movement');
checkAuto(countAuto('kreabank_payment_batch')===1 && countAuto('kreabank_payment_batch_line')===2,'One normalized group retains every native component');
$active=$service->loadPaymentBatch(95000);
checkAuto((int)$active['source']['status']===2 && !$active['source']['fk_native_bank_line'] && count($active['active']['components'])===2,'A grouped source completes without a misleading scalar native mapping');
checkAuto($active['source']['allocated_amount']==='3000.00000003' && (int)$active['active']['bank_account_id']===101,'Stored source and exact allocated total remain coherent');
foreach ($active['active']['components'] as $component) { checkAuto((int)$component['rappro']===1 && $component['num_releve']==='ST-95000','Every native component uses the statement reference'); }
checkAuto($service->loadPaymentBatch(95000)['draft']===null && $otherService->loadPaymentBatch(95000)['draft']['name']==='Other reviewer','Completion clears only this reviewer draft');
rejectAuto(static function () use ($service,$competitor) { $service->reconcilePaymentBatch($competitor); },'A competing prepared group cannot claim already reconciled components');
rejectAuto(static function () use ($service,$review) { $service->reconcilePaymentBatch($review); },'Successful signed approval cannot be replayed');
checkAuto($db->query('SELECT * FROM kbauto_payment_salary ORDER BY rowid')->fetch_all(MYSQLI_ASSOC)===$beforePayroll,'Payroll payments are byte-for-byte unchanged');
checkAuto($db->query('SELECT * FROM kbauto_salary ORDER BY rowid')->fetch_all(MYSQLI_ASSOC)===$beforeSalary,'Salary state and amounts are unchanged');
checkAuto($db->query('SELECT * FROM kbauto_bank_url ORDER BY rowid')->fetch_all(MYSQLI_ASSOC)===$beforeLinks && countAuto('kreabank_ml_sample')===$beforeLearning,'Existing links and learning truth are untouched');
$faultDb->matchingUpdates=0; $faultDb->failConciliated=0;
$undo=$faultService->preparePaymentBatchUndoReview(95000); $state=batchDatabaseState();
rejectAuto(static function () use ($faultService,$undo) { $faultService->undoPaymentBatch(95000,$undo); },'Undo failure on a later native component is atomic');
checkAuto(batchDatabaseState()===$state,'Failed undo leaves the complete group reconciled');
foreach (array('UPDATE kbauto_kreabank_payment_batch_line SET active_bank_line=NULL','UPDATE kbauto_kreabank_payment_batch SET active_statement_line=NULL','UPDATE kbauto_kreabank_statement_line SET status=0','INSERT INTO kbauto_kreabank_recon_audit') as $failure) {
	$undo=$service->preparePaymentBatchUndoReview(95000); $state=batchDatabaseState(); $db->fail=$failure;
	rejectAuto(static function () use ($service,$undo) { $service->undoPaymentBatch(95000,$undo); },'Undo persistence failure is visible');
	$db->fail=''; checkAuto(batchDatabaseState()===$state,'Undo mappings/source/audit roll back with native lifecycle');
}
$db->query('UPDATE kbauto_bank SET amount=-2000.00000003 WHERE rowid=96001');
rejectAuto(static function () use ($service) { $service->preparePaymentBatchUndoReview(95000); },'Tampered reconciled component amount cannot be silently undone');
$db->query('UPDATE kbauto_bank SET amount=-2000.00000002 WHERE rowid=96001');
// Existing installations may contain historical groups created before the same-day policy.
$db->query("UPDATE kbauto_bank SET dateo='2026-10-31' WHERE rowid=96001");
$undo=$service->preparePaymentBatchUndoReview(95000);
checkAuto(substr($undo['components'][1]['doc_date'],0,10)==='2026-10-31','Active historical off-day groups remain reviewable for undo');
checkAuto($service->undoPaymentBatch(95000,$undo),'Explicit fresh group undo succeeds');
checkAuto($service->loadPaymentBatch(95000)['active']===null && (int)$service->loadPaymentBatch(95000)['source']['status']===0,'Undo returns the source to pending');
checkAuto((int)$db->query('SELECT COUNT(*) n FROM kbauto_bank WHERE rowid IN (96000,96001) AND rappro=0')->fetch_object()->n===2,'Undo reverses all component reconciliations');
checkAuto(countAuto('kreabank_payment_batch')===1 && (int)$db->query('SELECT COUNT(*) n FROM kbauto_kreabank_payment_batch_line WHERE active_bank_line IS NOT NULL')->fetch_object()->n===0,'Undo preserves normalized history and releases active claims');
rejectAuto(static function () use ($service,$undo) { $service->undoPaymentBatch(95000,$undo); },'Undo review is single use');
$db->query("UPDATE kbauto_bank SET dateo='2026-11-01' WHERE rowid=96001");
$newId=$service->reconcilePaymentBatch(batchDatabaseReview());
checkAuto($newId!==$batchId && countAuto('kreabank_payment_batch')===2,'A reviewed retry after undo creates a new historical group');
class BatchNoUndoUser extends User { public function hasRight($a,$b,$c='') { return !($a==='kreabank' && $b==='reconciliation' && $c==='undo'); } }
$noUndoService=new KreaBankService($db,new BatchNoUndoUser(),$langs);
rejectAuto(static function () use ($noUndoService) { $noUndoService->preparePaymentBatchUndoReview(95000); },'Service-level review preparation requires the dedicated undo permission');
$undo=$service->preparePaymentBatchUndoReview(95000);
rejectAuto(static function () use ($noUndoService,$undo) { $noUndoService->undoPaymentBatch(95000,$undo); },'Service-level execution rechecks the dedicated undo permission');
// Feed conflicts cannot be laundered through manual grouped reconciliation.
$feedSchema=preg_replace('/--[^\n]*/','',file_get_contents(__DIR__.'/../sql/llx_kreabank_fintable.sql'));
foreach (explode(';',str_replace('llx_',MAIN_DB_PREFIX,$feedSchema)) as $statement) { if (trim($statement)!=='') { $db->query($statement); } }
stageAuto(95050,'-700.00','FEED-GROUP',101,1,'fintable'); bankAuto(96050,'-300.00','FEED-A'); bankAuto(96051,'-400.00','FEED-B');
$db->query("INSERT INTO kbauto_kreabank_feed_account (rowid,entity,fk_bank_account,workspace_id,provider_account_id,provider_connection_id,iban,currency,datec) VALUES(99,1,101,'fixture','fixture','fixture','GB82WEST12345698765432','EUR',NOW())");
$db->query("INSERT INTO kbauto_kreabank_feed_transaction (entity,fk_feed_account,provider_transaction_id,source_uid,financial_hash,content_hash,fk_statement_line,source_payload,conflict_payload,status,datec) VALUES(1,99,'fixture','fixture-95050',SHA2('-700|2026-11-01|EUR',256),SHA2('fixture',256),95050,'{}','{}','conflict',NOW())");
rejectAuto(static function () use ($service) { $service->preparePaymentBatchReview(95050,array(96050,96051),'Feed group'); },'Conflict-marked feed evidence cannot approve a batch');
$db->query("UPDATE kbauto_kreabank_feed_transaction SET status='imported',conflict_payload=NULL WHERE fk_statement_line=95050");
$db->query('UPDATE kbauto_kreabank_statement_line SET amount=-701 WHERE rowid=95050');
rejectAuto(static function () use ($service) { $service->preparePaymentBatchReview(95050,array(96050,96051),'Feed group'); },'Altered staged financial evidence cannot replace the imported feed hash');
$db->query('UPDATE kbauto_kreabank_statement_line SET amount=-700 WHERE rowid=95050');
$feedReview=$service->preparePaymentBatchReview(95050,array(96050,96051),'Feed group');
$db->query("UPDATE kbauto_kreabank_feed_transaction SET financial_hash=SHA2('changed',256) WHERE fk_statement_line=95050");
rejectAuto(static function () use ($service,$feedReview) { $service->reconcilePaymentBatch($feedReview); },'Feed evidence changes after display invalidate the signed review');
// Real overlapping transactions: an account lock makes reconciliation wait, then
// reject the component change committed while its earlier review was displayed.
stageAuto(95070,'-70.00','CONCURRENT-BATCH'); bankAuto(96070,'-30.00','CONCURRENT-A'); bankAuto(96071,'-40.00','CONCURRENT-B');
$concurrentReview=$service->preparePaymentBatchReview(95070,array(96070,96071),'Concurrent group');
$worker= <<<'PHP'
$db=new mysqli('127.0.0.1','root','','kreabank_auto_test',33317);
$db->begin_transaction();
$db->query('SELECT rowid FROM kbauto_bank_account WHERE rowid=101 FOR UPDATE');
$db->query('UPDATE kbauto_bank SET amount=-41 WHERE rowid=96071');
echo "LOCKED\n"; flush();
usleep(250000);
$db->commit();
PHP;
$process=proc_open(array(PHP_BINARY,'-r',$worker),array(0=>array('pipe','r'),1=>array('pipe','w'),2=>array('pipe','w')),$pipes);
if (!is_resource($process)) { throw new RuntimeException('Cannot start isolated concurrency fixture'); }
checkAuto(trim(fgets($pipes[1]))==='LOCKED','Independent transaction acquired the shared account lock');
rejectAuto(static function () use ($service,$concurrentReview) { $service->reconcilePaymentBatch($concurrentReview); },'Contended account locking revalidates the committed movement before any reconciliation');
foreach ($pipes as $pipe) { fclose($pipe); }
checkAuto(proc_close($process)===0 && (int)$db->query('SELECT rappro FROM kbauto_bank WHERE rowid=96070')->fetch_object()->rappro===0,'Concurrent rejection leaves every component pending');
// Existing invoice payments may share a bank transaction without recreating them.
foreach (array(false,true) as $supplier) {
	$base=$supplier ? 95200 : 95100; $sign=$supplier ? '-' : '';
	$pay=$supplier ? 'paiementfourn' : 'paiement'; $invoice=$supplier ? 'facture_fourn' : 'facture';
	$alloc=$supplier ? 'paiementfourn_facturefourn' : 'paiement_facture'; $paymentKey=$supplier ? 'fk_paiementfourn' : 'fk_paiement'; $invoiceKey=$supplier ? 'fk_facturefourn' : 'fk_facture';
	stageAuto($base,$sign.'75.00','INVOICE-PAYMENT-GROUP'); $paymentIds=array(); $nativeIds=array();
	foreach (array('25.00','50.00') as $offset=>$amount) {
		$id=$base+$offset+1; $bank=$base+1000+$offset; $paymentIds[]=$id; $nativeIds[]=$bank;
		bankAuto($bank,$sign.$amount,'PAID-'.$id);
		$db->query("INSERT INTO kbauto_$invoice (rowid,ref,".($supplier?'ref_supplier,':'')."entity,fk_soc,datec,total_ttc,fk_statut,paye,type) VALUES($id,'INVOICE-$id',".($supplier?"'SUPPLIER-$id',":'')."1,1,NOW(),$amount,2,1,0)");
		$db->query("INSERT INTO kbauto_$pay (rowid,entity,ref,datec,datep,amount,fk_bank,fk_paiement,num_paiement) VALUES($id,1,'PAID-$id',NOW(),'2026-11-01',$amount,$bank,1,'GROUP-$base')");
		$db->query("INSERT INTO kbauto_$alloc ($paymentKey,$invoiceKey,amount) VALUES($id,$id,$amount)");
		$db->query("INSERT INTO kbauto_bank_url (fk_bank,url_id,url,label,type) VALUES($bank,$id,'/synthetic/','Paid $id','".($supplier?'payment_supplier':'payment')."')");
	}
	$paymentState=$db->query("SELECT * FROM kbauto_$pay ORDER BY rowid")->fetch_all(MYSQLI_ASSOC);
	$allocationState=$db->query("SELECT * FROM kbauto_$alloc ORDER BY rowid")->fetch_all(MYSQLI_ASSOC);
	$invoiceState=$db->query("SELECT * FROM kbauto_$invoice ORDER BY rowid")->fetch_all(MYSQLI_ASSOC); $nativeCount=countAuto('bank');
	if ($supplier) {
		stageAuto(95401,'-25.00','PAID-'.$paymentIds[0],102);
		stageAuto(95402,'-25.00','PAID-'.$paymentIds[0],101);
		$earlierCorrection=$service->searchOtherAccountMovements(95401,'PAID-'.$paymentIds[0],$nativeIds[0])[0] ?? null;
		checkAuto($earlierCorrection && $earlierCorrection['can_move'],'A normal supplier payment has a legitimate correction review before being grouped');
	}
	$service->reconcilePaymentBatch($service->preparePaymentBatchReview($base,$nativeIds,'Existing payment group'));
	checkAuto($paymentState===$db->query("SELECT * FROM kbauto_$pay ORDER BY rowid")->fetch_all(MYSQLI_ASSOC) && $allocationState===$db->query("SELECT * FROM kbauto_$alloc ORDER BY rowid")->fetch_all(MYSQLI_ASSOC)
		&& $invoiceState===$db->query("SELECT * FROM kbauto_$invoice ORDER BY rowid")->fetch_all(MYSQLI_ASSOC) && $nativeCount===countAuto('bank'),'Grouped '.($supplier?'supplier':'customer').' payments preserve invoices, allocations and native entry counts');
	if ($supplier) {
		$member=$nativeIds[0]; $auditCount=countAuto('kreabank_recon_audit');
		$db->query('UPDATE kbauto_bank SET rappro=0 WHERE rowid='.$member);
		checkAuto(!in_array($member,array_map('intval',array_column($service->getOpenDocuments(-1,500,'2026-11-01',7,95402,101,false),'rowid')),true),'An active group claim survives an externally cleared native reconciliation flag');
		rejectAuto(static function () use ($service,$member) { $service->reconcileLine(95402,array(array('doc_type'=>'native_bank','fk_doc'=>$member,'allocated_amount'=>'25.00')),'manual'); },'Old scalar reconciliation cannot steal an active group member');
		rejectAuto(static function () use ($service,$earlierCorrection) { $service->changeMovementAccount(95401,$earlierCorrection['rowid'],$earlierCorrection['snapshot'],101,102); },'A valid earlier account-correction review cannot move a newly grouped member');
		checkAuto($service->searchOtherAccountMovements(95401,'PAID-'.$paymentIds[0],$member)===array(),'Active group members are absent from other-account correction discovery');
		checkAuto((int)$db->query('SELECT fk_account FROM kbauto_bank WHERE rowid='.$member)->fetch_object()->fk_account===101 && countAuto('kreabank_recon_audit')===$auditCount,'Rejected scalar and correction paths preserve group ownership and audit');
		$db->query('UPDATE kbauto_bank SET rappro=1 WHERE rowid='.$member);
	}
}
// Explicit reference searches must operate before the result limit, across all dates.
stageAuto(95300,'23.45','TYPED-SEARCH-SOURCE');
for ($offset=0;$offset<110;$offset++) { bankAuto(96400+$offset,'23.45','UNRELATED-SEARCH-'.$offset,101,'2026-11-01'); }
bankAuto(96300,'23.45','LATE-REFERENCE-96300',101,'2024-01-15');
$searchLine=$service->getBulkReviewLine(95300,'staged');
$search=$service->searchWorklistNativeDocuments($searchLine,'LATE-REFERENCE-96300');
checkAuto(count($search['suggestions'])===1 && (int)$search['suggestions'][0]['doc_id']===96300 && !$search['truncated'],'Typed native search finds an exact reference outside default dates and beyond the unfiltered candidate cap');
checkAuto($service->searchWorklistNativeDocuments($searchLine,'%')['suggestions']===array(),'Native search treats wildcard characters literally');
checkAuto($service->searchWorklistNativeDocuments($searchLine,'UNRELATED-SEARCH')['truncated'],'Native candidate truncation is explicit');
stageAuto(95301,'-45.67','PAID-INVOICE-SEARCH'); bankAuto(96301,'-45.67','ORIGINAL-PAYMENT',101,'2024-01-15');
$db->query("INSERT INTO kbauto_facture_fourn (rowid,ref,ref_supplier,entity,fk_soc,datec,total_ttc,fk_statut,paye,type) VALUES(98301,'HISTORICAL-INVOICE-98301','SUPPLIER-98301',1,1,NOW(),45.67,2,1,0)");
$db->query("INSERT INTO kbauto_paiementfourn (rowid,entity,ref,datec,datep,amount,fk_bank,fk_paiement) VALUES(97301,1,'PAYMENT-97301',NOW(),'2024-01-15',45.67,96301,1)");
$db->query('INSERT INTO kbauto_paiementfourn_facturefourn (fk_paiementfourn,fk_facturefourn,amount) VALUES(97301,98301,45.67)');
$db->query("INSERT INTO kbauto_bank_url (fk_bank,url_id,url,label,type) VALUES(96301,97301,'/synthetic/','(payment)','payment_supplier')");
$searchLine=$service->getBulkReviewLine(95301,'staged'); $search=$service->searchWorklistNativeDocuments($searchLine,'HISTORICAL-INVOICE-98301');
checkAuto(count($search['suggestions'])===1 && $search['suggestions'][0]['doc_type']==='native_bank' && (int)$search['suggestions'][0]['doc_id']===96301,'Searching a paid invoice returns its existing bank movement without creating another payment');
$db->query('UPDATE kbauto_facture_fourn SET entity=2 WHERE rowid=98301');
checkAuto($service->searchWorklistNativeDocuments($searchLine,'ORIGINAL-PAYMENT')['suggestions']===array(),'An owned native bank row cannot expose a payment of a foreign invoice parent');
$db->query('UPDATE kbauto_facture_fourn SET entity=1 WHERE rowid=98301');
$searchAsReader=$readOnlyService->searchWorklistNativeDocuments($searchLine,'HISTORICAL-INVOICE-98301');
checkAuto(count($searchAsReader['suggestions'])===1,'Native and module read rights can search without granting approval');

// The unified selector dispatches one movement through the existing scalar service.
require_once __DIR__.'/../lib/kreabank_manual_review.lib.php';
stageAuto(95500,'-137.23000001','SCALAR-SELECTION');
bankAuto(96600,'-137.23000001','SCALAR-SELECTION');
bankAuto(96601,'-137.23000001','SCALAR-SELECTION-OFF-DAY',101,'2026-11-02');
$selection=$service->listWorklistSelectionCandidates('staged:95500');
checkAuto(in_array(96600,array_map('intval',array_column($selection['rows'],'rowid')),true) && !in_array(96601,array_map('intval',array_column($selection['rows'],'rowid')),true),'Unified discovery retains the exact scalar movement and excludes neighboring days');
$scalar=$service->prepareWorklistSelectionPair('staged:95500',96600);
checkAuto($scalar['approval_context']==='selected_native' && $scalar['worklist_selection']['target']['record']['amount_decimal']==='-137.23000001','Scalar review uses ordinary selected-native approval with exact persisted evidence');
$sourceBefore=$db->query('SELECT * FROM kbauto_kreabank_statement_line WHERE rowid=95500')->fetch_all(MYSQLI_ASSOC);
$auditBefore=countAuto('kreabank_recon_audit');
foreach(array("dateo='2026-11-02'","amount=-137.23000002","label='Changed after display'") as $change){
	$db->query('UPDATE kbauto_bank SET '.$change.' WHERE rowid=96600');
	rejectAuto(static function()use($service,$scalar){kreabankExecuteManualReview($service,$scalar);},'Locked scalar selection rejects edited date, decimal amount or native evidence');
	checkAuto($sourceBefore===$db->query('SELECT * FROM kbauto_kreabank_statement_line WHERE rowid=95500')->fetch_all(MYSQLI_ASSOC) && $auditBefore===countAuto('kreabank_recon_audit') && $db->depth===0,'Rejected scalar selection rolls back without source/audit writes');
	$db->query("UPDATE kbauto_bank SET dateo='2026-11-01',amount=-137.23000001,label='Existing movement' WHERE rowid=96600");
}
$service->savePaymentBatchDraft(95500,array(96600),'Planning a scalar selection');
$otherService->savePaymentBatchDraft(95500,array(96600),'Other reviewer planning state');
$scalar=$service->prepareWorklistSelectionPair('staged:95500',96600);
$nativeBefore=countAuto('bank');$groupsBefore=countAuto('kreabank_payment_batch');
checkAuto(kreabankExecuteManualReview($service,$scalar)===95500,'One reviewed selection executes through the ordinary reconciliation service');
checkAuto($nativeBefore===countAuto('bank') && $groupsBefore===countAuto('kreabank_payment_batch'),'Scalar selection creates neither a native movement nor a one-member group');
checkAuto((int)$db->query('SELECT fk_native_bank_line FROM kbauto_kreabank_statement_line WHERE rowid=95500')->fetch_object()->fk_native_bank_line===96600,'Scalar selection preserves the ordinary staging/native mapping');
$remainingDrafts=$db->query('SELECT fk_user,name FROM kbauto_kreabank_payment_batch_draft WHERE entity=1 AND fk_statement_line=95500 ORDER BY fk_user')->fetch_all(MYSQLI_ASSOC);
checkAuto(count($remainingDrafts)===1 && (int)$remainingDrafts[0]['fk_user']===(int)$otherUser->id && $remainingDrafts[0]['name']==='Other reviewer planning state','Scalar completion atomically removes only the current reviewer draft');

// Restricted accounts retain their supported reciprocal-transfer scalar path.
$savedPairs=$db->query("SELECT value FROM kbauto_const WHERE entity=1 AND name='KREABANK_ACCOUNT_PAIRS'")->fetch_all(MYSQLI_ASSOC);
$db->query("DELETE FROM kbauto_const WHERE entity=1 AND name='KREABANK_ACCOUNT_PAIRS'");
$db->query("INSERT INTO kbauto_const(entity,name,value) VALUES(1,'KREABANK_ACCOUNT_PAIRS','{\"102\":101}')");
stageAuto(95511,'-17.00','SCALAR-TRANSFER',102);
bankAuto(96611,'-17.00','SCALAR-TRANSFER',102);bankAuto(96612,'17.00','SCALAR-TRANSFER',101);bankAuto(96613,'-17.00','RESTRICTED-BARE',102);
$db->query("INSERT INTO kbauto_bank_url(fk_bank,url_id,url,label,type) VALUES(96611,96612,'/synthetic/','Reciprocal transfer','banktransfert'),(96612,96611,'/synthetic/','Reciprocal transfer','banktransfert')");
$restricted=$service->listWorklistSelectionCandidates('staged:95511');
checkAuto(!$restricted['grouping_available'] && array_map('intval',array_column($restricted['rows'],'rowid'))===array(96611),'Restricted accounts show only the eligible native transfer and never enable grouping');
checkAuto($service->prepareWorklistSelectionPair('staged:95511',96611)['approval_context']==='selected_native','Restricted reciprocal transfers use the same signed scalar review');
$db->query('UPDATE kbauto_bank SET amount=17.01 WHERE rowid=96612');
rejectAuto(static function()use($service){$service->prepareWorklistSelectionPair('staged:95511',96611);},'A changed restricted transfer counterpart remains ineligible');
$db->query('UPDATE kbauto_bank SET amount=17.00 WHERE rowid=96612');
$db->query("DELETE FROM kbauto_const WHERE entity=1 AND name='KREABANK_ACCOUNT_PAIRS'");
foreach($savedPairs as $savedPair){$db->query("INSERT INTO kbauto_const(entity,name,value) VALUES(1,'KREABANK_ACCOUNT_PAIRS','".$db->escape($savedPair['value'])."')");}

bankAuto(96620,'-18.00','LEGACY-SCALAR-SOURCE');bankAuto(96621,'-18.00','LEGACY-SCALAR-TARGET');
$db->query("UPDATE kbauto_bank SET num_releve='LEGACY-SCALAR-STATEMENT' WHERE rowid=96620");
$db->query("INSERT INTO kbauto_kreabank_bankmeta(entity,fk_bank_line,bank_account_id,source_type,idempotency_hash,amount,currency,status,datec) VALUES(1,96620,101,'csv',SHA2('legacy-scalar-selector',256),-18,'EUR',0,NOW())");
$legacyBefore=$db->query('SELECT * FROM kbauto_bank WHERE rowid IN (96620,96621) ORDER BY rowid')->fetch_all(MYSQLI_ASSOC);$legacyAudit=countAuto('kreabank_recon_audit');
rejectAuto(static function()use($service){$service->listWorklistSelectionCandidates('native:96620');},'Legacy imported ledger sources cannot enter unified replacement-movement discovery');
rejectAuto(static function()use($service){$service->getWorklistSelectionMembers('native:96620',array(96621));},'Retained legacy selector choices cannot establish a second ledger mapping');
rejectAuto(static function()use($service){$service->prepareWorklistSelectionPair('native:96620',96621);},'The unified selector rejects a legacy source mapped to another native movement');
rejectAuto(static function()use($service){$service->prepareWorklistSelectionPair('native:96621',96620);},'An ordinary native movement cannot masquerade as a staged source');
checkAuto($legacyBefore===$db->query('SELECT * FROM kbauto_bank WHERE rowid IN (96620,96621) ORDER BY rowid')->fetch_all(MYSQLI_ASSOC) && $legacyAudit===countAuto('kreabank_recon_audit'),'Rejected legacy selections leave original ledger evidence and audit unchanged');
bankAuto(96622,'-19.00','OLD-IMPORT-SOURCE');bankAuto(96623,'-19.00','OLD-IMPORT-TARGET');
$db->query("UPDATE kbauto_bank SET num_releve='OLD-IMPORT-STATEMENT',note='KreaBank import fixture.csv' WHERE rowid=96622");
rejectAuto(static function()use($service){$service->prepareWorklistSelectionPair('native:96622',96623);},'A historical native import without metadata also remains outside staged selection');
echo 'OK: '.($checks-$initialChecks)." payment batch database checks\n";
