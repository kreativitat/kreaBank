#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 * ONLY the guarded disposable 127.0.0.1:33317/kreabank_auto_test fixture.
 * Exercises installed native Account/AccountLine; never bootstraps an installation.
 */
require __DIR__ . '/test_automatic_reconciliation_database.php';
$initialChecks = $checks;
foreach (array('c_country','c_departements','accounting_journal','c_paiement','salary','payment_salary','payment_various') as $table) {
	$sql = preg_replace('/--[^\n]*/', '', file_get_contents(DOL_DOCUMENT_ROOT . '/install/mysql/tables/llx_' . $table . '.sql'));
	foreach (explode(';', str_replace('llx_', MAIN_DB_PREFIX, $sql)) as $statement) { if (trim($statement) !== '') { $db->query($statement); } }
}
function dol_trunc($value, $length, $mode = 'right', $charset = '', $dots = 1) { return substr($value, 0, $length); }
function dol_print_error($db, $message = '') { /* Native failure remains observable through its return value. */ }
class WorklistMovementLangs extends AutoTestLangs
{
	public function transnoentitiesnoconv($key) { return $key; }
	public function transnoentities($key) { return $key; }
}
class WorklistMovementDb extends DoliDB
{
	public $ddlInsideTransaction = false;
	public function __construct($connection) { $this->connection = $connection; }
	public function query($sql) {
		if ($this->depth && preg_match('/^(CREATE|ALTER|DROP)\b/i', $sql)) { $this->ddlInsideTransaction = true; }
		return parent::query($sql);
	}
}
$db = new WorklistMovementDb($db->connection);
$langs = new WorklistMovementLangs();
$extrafields->attributes['bank_account'] = array('loaded'=>1,'label'=>array());
$db->query("INSERT INTO kbauto_c_paiement(id,entity,code,libelle,type,active) VALUES(1,1,'VIR','Bank transfer',2,1),(2,1,'CHQ','Cheque',2,1),(3,1,'OLD','Inactive',2,0),(4,2,'OTHER','Foreign mode',2,1)");
$service = new KreaBankService($db, $user, $langs);
$_SESSION = array();
stageAuto(141000, '-83.12345678', 'NEW-MOVEMENT-141000');
bankAuto(141000, '1.07', 'UNRELATED-COLLIDING-NATIVE', 102, '2010-01-01');
function worklistMovementReview($id = 141000) { global $service; return $service->prepareWorklistMovementReview('staged:' . $id); }
function worklistMovementState($id = 141000) {
	global $db;
	return array('native'=>$db->query('SELECT rowid,fk_account,CAST(amount AS DECIMAL(24,8)) amount,rappro,num_releve FROM kbauto_bank ORDER BY rowid')->fetch_all(MYSQLI_ASSOC),
		'source'=>$db->query('SELECT * FROM kbauto_kreabank_statement_line WHERE rowid=' . (int) $id)->fetch_all(MYSQLI_ASSOC),
		'meta'=>$db->query('SELECT * FROM kbauto_kreabank_bankmeta ORDER BY rowid')->fetch_all(MYSQLI_ASSOC),
		'audit'=>$db->query('SELECT * FROM kbauto_kreabank_recon_audit ORDER BY rowid')->fetch_all(MYSQLI_ASSOC));
}
$state = worklistMovementState(); $review = worklistMovementReview();
checkAuto($review['source']['amount'] === '-83.12345678' && $review['source']['allocated_amount'] === '0.00000000'
	&& $review['source']['bank_account_id'] === 101 && $review['source_key'] === 'staged:141000'
	&& array_keys($review['modes']) === array('CHQ','VIR'), 'Review binds exact persisted source facts and only active owned dictionary choices');
checkAuto(worklistMovementState() === $state, 'Opening a creation review writes no native, staging, mapping or audit record');
foreach (array('native:141000','141000','staged:0','staged:0141000','staged:141000 OR 1=1') as $key) {
	rejectAuto(static function () use ($service,$key) { $service->prepareWorklistMovementReview($key); }, 'Creation accepts only explicit valid staged identities');
}
class WorklistMovementNoModifier extends User { public function hasRight($a,$b,$c='') { return !($a === 'banque' && $b === 'modifier'); } }
class WorklistMovementNoModuleWrite extends User { public function hasRight($a,$b,$c='') { return !($a === 'kreabank' && $c === 'write'); } }
foreach (array(new WorklistMovementNoModifier(), new WorklistMovementNoModuleWrite()) as $denied) {
	$restrictedService = new KreaBankService($db,$denied,$langs);
	rejectAuto(static function () use ($restrictedService) { $restrictedService->prepareWorklistMovementReview('staged:141000'); }, 'Consolidation-only and missing module write cannot prepare creation');
	rejectAuto(static function () use ($restrictedService,$review) { $restrictedService->createWorklistMovementAndReconcile($review,'VIR'); }, 'Current native modifier and module write are rechecked before creation');
}
$conf->global->BANK_DISABLE_DIRECT_INPUT = 1;
rejectAuto(static function () { worklistMovementReview(); }, 'Native disabled-direct-input policy blocks review');
rejectAuto(static function () use ($service,$review) { $service->createWorklistMovementAndReconcile($review,'VIR'); }, 'Native disabled-direct-input policy also blocks execution');
$conf->global->BANK_DISABLE_DIRECT_INPUT = 0;
$conf->entity = 2;
rejectAuto(static function () { worklistMovementReview(); }, 'A company switch invalidates the old service context');
$conf->entity = 1;
$external = clone $user; $external->socid = 42;
$externalService = new KreaBankService($db,$external,$langs);
rejectAuto(static function () use ($externalService) { $externalService->prepareWorklistMovementReview('staged:141000'); }, 'External users cannot prepare native movement creation');
stageAuto(141050,'-83.12345678','FOREIGN-SOURCE',202,2);
rejectAuto(static function () use ($service) { $service->prepareWorklistMovementReview('staged:141050'); }, 'A foreign-company statement cannot be used through a numeric ID');
$otherUser = clone $user; $otherUser->id = 8;
$otherService = new KreaBankService($db,$otherUser,$langs);
$review = worklistMovementReview();
rejectAuto(static function () use ($otherService,$review) { $otherService->createWorklistMovementAndReconcile($review,'VIR'); }, 'A signed review cannot be reused by another user');
$review = worklistMovementReview(); $review['expires'] = time()-1; unset($review['signature']);
$review['signature'] = hash_hmac('sha256',json_encode($review,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$_SESSION['kreabank_worklist_movement_secret']);
$_SESSION['kreabank_worklist_movement_reviews'][$review['nonce']]['signature'] = $review['signature'];
rejectAuto(static function () use ($service,$review) { $service->createWorklistMovementAndReconcile($review,'VIR'); }, 'Even an authentic expired review cannot create a movement');
foreach (array('amount'=>'-83.12345679','bank_account_id'=>102,'description'=>'Changed text','operation_date'=>'2026-11-02') as $field=>$value) {
	$review = worklistMovementReview(); $forged = $review; $forged['source'][$field] = $value;
	rejectAuto(static function () use ($service,$forged) { $service->createWorklistMovementAndReconcile($forged,'VIR'); }, 'Tampered exact source amount/account/date/description cannot authorize creation');
}
foreach (array('OLD','OTHER','NONE','') as $mode) {
	$review = worklistMovementReview();
	rejectAuto(static function () use ($service,$review,$mode) { $service->createWorklistMovementAndReconcile($review,$mode); }, 'Inactive, foreign and missing native mode choices are refused');
}
$review = worklistMovementReview(); $db->query("UPDATE kbauto_c_paiement SET active=0 WHERE code='VIR'");
rejectAuto(static function () use ($service,$review) { $service->createWorklistMovementAndReconcile($review,'VIR'); }, 'A mode deactivated after review is refused under execution locks');
$db->query("UPDATE kbauto_c_paiement SET active=1 WHERE code='VIR'");
foreach (array('status=1','status=2','is_duplicate=1','allocated_amount=0.00000001','fk_native_bank_line=141000',"currency='USD'") as $change) {
	$db->query('UPDATE kbauto_kreabank_statement_line SET ' . $change . ' WHERE rowid=141000');
	rejectAuto(static function () { worklistMovementReview(); }, 'Partial, reconciled, duplicate, allocated, mapped and currency-conflicting sources cannot create');
	$db->query("UPDATE kbauto_kreabank_statement_line SET status=0,is_duplicate=0,allocated_amount=0,fk_native_bank_line=NULL,currency='EUR' WHERE rowid=141000");
}
$db->query('UPDATE kbauto_bank_account SET clos=1 WHERE rowid=101');
rejectAuto(static function () { worklistMovementReview(); }, 'A closed statement account cannot create movements');
$db->query('UPDATE kbauto_bank_account SET clos=0 WHERE rowid=101');
$policy = new KreaBankAccountPairs($db,1); $policy->configure($user,array(101=>102));
rejectAuto(static function () { worklistMovementReview(); }, 'Restricted transfer-only source accounts cannot create generic movements');
$policy->configure($user,array());
$db->query("UPDATE kbauto_bank_account SET currency_code='USD' WHERE rowid=102");
stageAuto(141051,'-93.12345678','FOREIGN-CURRENCY',102);
$db->query("UPDATE kbauto_kreabank_statement SET currency='USD' WHERE rowid=141051");
$db->query("UPDATE kbauto_kreabank_statement_line SET currency='USD' WHERE rowid=141051");
rejectAuto(static function () use ($service) { $service->prepareWorklistMovementReview('staged:141051'); }, 'Generic creation remains disabled for a bank and statement in foreign company currency');
$db->query("UPDATE kbauto_bank_account SET currency_code='EUR' WHERE rowid=102");
$schema = preg_replace('/--[^\n]*/','',file_get_contents(__DIR__.'/../sql/llx_kreabank_payment_batch.sql'));
foreach (explode(';',str_replace('llx_',MAIN_DB_PREFIX,$schema)) as $statement) { if (trim($statement)!=='') { $db->query($statement); } }
$db->query("INSERT INTO kbauto_kreabank_payment_batch(entity,fk_statement_line,active_statement_line,bank_account_id,amount,currency,operation_date,statement_ref,name,status,source_snapshot,fk_user,datec) VALUES(1,141000,141000,101,-83.12345678,'EUR','2026-11-01','ST-141000','Existing claim',1,'{}',7,NOW())");
rejectAuto(static function () { worklistMovementReview(); }, 'An active grouped claim blocks new scalar native creation');
$db->query('DELETE FROM kbauto_kreabank_payment_batch WHERE fk_statement_line=141000');
stageAuto(141052,'-94.12345678','FEED-CREATE',101,1,'fintable');
rejectAuto(static function () use ($service) { $service->prepareWorklistMovementReview('staged:141052'); }, 'A feed source without verified provider evidence cannot create a movement');
$db->query("INSERT INTO kbauto_kreabank_feed_transaction(entity,fk_feed_account,provider_transaction_id,source_uid,financial_hash,content_hash,fk_statement_line,source_payload,status,datec) VALUES(1,1,'create-feed-fixture','fixture-141052',SHA2('-94.12345678|2026-11-01|EUR',256),SHA2('original-create-content',256),141052,'{}','imported',NOW())");
$feedReview = $service->prepareWorklistMovementReview('staged:141052');
checkAuto(count($feedReview['source']['feed_facts']) === 1, 'A creation review binds the verified provider identity and financial/content hashes');
$db->query("UPDATE kbauto_kreabank_feed_transaction SET content_hash=SHA2('changed-create-content',256) WHERE fk_statement_line=141052");
rejectAuto(static function () use ($service,$feedReview) { $service->createWorklistMovementAndReconcile($feedReview,'VIR'); }, 'Provider evidence changed after review cannot authorize native creation');
bankAuto(141010, '-83.12345678', '', 101);
rejectAuto(static function () { worklistMovementReview(); }, 'An exact nearby existing native movement blocks creation independently of typed search');
$db->query('UPDATE kbauto_bank SET rappro=1 WHERE rowid=141010');
rejectAuto(static function () { worklistMovementReview(); }, 'An already reconciled possible native movement also blocks duplication');
$db->query("UPDATE kbauto_bank SET dateo='2000-01-01',num_chq='NEW-MOVEMENT-141000',fk_account=102 WHERE rowid=141010");
rejectAuto(static function () { worklistMovementReview(); }, 'An exact reference in another owned account is checked across all dates');
$db->query('DELETE FROM kbauto_bank WHERE rowid=141010');
$db->query("INSERT INTO kbauto_paiementfourn(rowid,entity,ref,datec,datep,amount,fk_bank,fk_paiement) VALUES(141020,1,'UNBANKED-141020',NOW(),'2026-11-01',83.12345678,0,1)");
rejectAuto(static function () { worklistMovementReview(); }, 'A possible existing unbanked supplier payment blocks generic movement creation');
$db->query("UPDATE kbauto_paiementfourn SET datep='1999-01-01',num_paiement='NEW-MOVEMENT-141000' WHERE rowid=141020");
rejectAuto(static function () { worklistMovementReview(); }, 'An existing payment reference outside the initial date range still blocks duplication');
$db->query('DELETE FROM kbauto_paiementfourn WHERE rowid=141020');
$db->query("INSERT INTO kbauto_payment_various(rowid,entity,sens,datep,amount,fk_bank,fk_typepayment,num_payment,label) VALUES(141020,1,0,'2026-11-01',83.12345678,0,1,'VARIOUS-141020','Unbanked native miscellaneous payment')");
rejectAuto(static function () { worklistMovementReview(); }, 'An existing native miscellaneous payment also prevents duplicate bank creation');
$db->query('DELETE FROM kbauto_payment_various WHERE rowid=141020');
$db->query("INSERT INTO kbauto_salary(rowid,entity,fk_user,amount,fk_typepayment,paye,label) VALUES(141021,1,17,83.12345678,1,1,'Paid synthetic salary')");
$db->query("INSERT INTO kbauto_payment_salary(rowid,entity,fk_salary,fk_bank,datep,amount,fk_typepayment,num_payment) VALUES(141021,1,141021,0,'2026-11-01',83.12345678,1,'SYNTHETIC-SALARY')");
rejectAuto(static function () { worklistMovementReview(); }, 'Existing unbanked salary payments require allocation review instead of another movement');
$db->query('DELETE FROM kbauto_payment_salary WHERE rowid=141021');
$db->query("INSERT INTO kbauto_chargesociales(rowid,entity,date_ech,libelle,fk_type,amount,paye) VALUES(141022,1,'2026-11-01','Paid synthetic tax',1,83.12345678,1)");
$db->query("INSERT INTO kbauto_paiementcharge(rowid,fk_charge,fk_bank,datep,amount,fk_typepaiement,num_paiement) VALUES(141022,141022,0,'2026-11-01',83.12345678,1,'SYNTHETIC-TAX')");
rejectAuto(static function () { worklistMovementReview(); }, 'Existing unbanked tax payments require allocation review instead of another movement');
$db->query('DELETE FROM kbauto_paiementcharge WHERE rowid=141022');
$secondDb = new DoliDB(); $secondDb->begin();
$secondDb->query('SELECT rowid FROM kbauto_bank_account WHERE rowid=101 FOR UPDATE');
$db->query('SET SESSION innodb_lock_wait_timeout=1'); $review = worklistMovementReview(); $state = worklistMovementState();
rejectAuto(static function () use ($service,$review) { $service->createWorklistMovementAndReconcile($review,'VIR'); }, 'Another connection owning the account lock prevents concurrent creation');
$secondDb->rollback();
checkAuto(worklistMovementState() === $state && $db->depth === 0, 'A real lock conflict leaves every financial record unchanged');
$review = worklistMovementReview();
$db->beforeBegin = static function () use ($db) { $db->query("UPDATE kbauto_kreabank_statement_line SET description='Changed after review' WHERE rowid=141000"); };
rejectAuto(static function () use ($service,$review) { $service->createWorklistMovementAndReconcile($review,'VIR'); }, 'Concurrent source evidence is revalidated after locking');
$db->query("UPDATE kbauto_kreabank_statement_line SET description='Received NEW-MOVEMENT-141000' WHERE rowid=141000");
$review = worklistMovementReview();
$db->beforeBegin = static function () { bankAuto(141030,'-83.12345678','',101); };
rejectAuto(static function () use ($service,$review) { $service->createWorklistMovementAndReconcile($review,'VIR'); }, 'A native movement inserted after review is checked again under locks');
$db->query('DELETE FROM kbauto_bank WHERE rowid=141030');
foreach (array('INSERT INTO kbauto_bank ', 'INSERT INTO kbauto_kreabank_bankmeta', 'UPDATE kbauto_kreabank_statement_line', 'UPDATE kbauto_bank SET', 'INSERT INTO kbauto_kreabank_recon_audit') as $failure) {
	$review = worklistMovementReview(); $state = worklistMovementState(); $db->fail = $failure;
	rejectAuto(static function () use ($service,$review) { $service->createWorklistMovementAndReconcile($review,'VIR'); }, 'Native lifecycle failure is visible: ' . $failure);
	$db->fail = '';
	checkAuto(worklistMovementState() === $state && $db->depth === 0, 'Native movement, metadata, map, reconciliation and audit roll back atomically');
}
$db->fail = 'SELECT b.rowid FROM kbauto_bank b JOIN kbauto_bank_account a';
rejectAuto(static function () { worklistMovementReview(); }, 'Failed conflict reads never become evidence that no movement exists');
$db->fail = '';
$paymentsBefore = $db->query('SELECT * FROM kbauto_paiement ORDER BY rowid')->fetch_all(MYSQLI_ASSOC);
$supplierBefore = $db->query('SELECT * FROM kbauto_paiementfourn ORDER BY rowid')->fetch_all(MYSQLI_ASSOC);
$balanceBefore = $db->query('SELECT CAST(SUM(CAST(amount AS DECIMAL(24,8))) AS DECIMAL(24,8)) amount FROM kbauto_bank WHERE fk_account=101')->fetch_object()->amount;
$bankBefore = countAuto('bank'); $quickBefore = countAuto('kreabank_quick_entry'); $learnBefore = countAuto('kreabank_ml_sample');
$review = worklistMovementReview(); $competingReview = worklistMovementReview();
$nativeId = $service->createWorklistMovementAndReconcile($review,'VIR','Explicit synthetic review');
$native = $db->query('SELECT *,CAST(amount AS DECIMAL(24,8)) exact_amount FROM kbauto_bank WHERE rowid=' . $nativeId)->fetch_object();
$stage = $db->query('SELECT * FROM kbauto_kreabank_statement_line WHERE rowid=141000')->fetch_object();
checkAuto($nativeId !== 141000 && $native->exact_amount === '-83.12345678' && (int) $native->fk_account === 101 && $native->fk_type === 'VIR'
	&& substr($native->dateo,0,10) === '2026-11-01' && substr($native->datev,0,10) === '2026-11-01' && (int) $native->rappro === 1 && $native->num_releve === 'ST-141000', 'Real native lifecycle persists the exact signed source amount, bank, dates, mode and reconciliation');
checkAuto((int) $stage->status === 2 && $stage->allocated_amount === '83.12345678' && (int) $stage->fk_native_bank_line === $nativeId
	&& $db->query('SELECT rappro FROM kbauto_bank WHERE rowid=141000')->fetch_object()->rappro === '0', 'Explicit staged identity completes only its own map despite a colliding unrelated native ID');
$audit = json_decode($db->query('SELECT payload_json FROM kbauto_kreabank_recon_audit ORDER BY rowid DESC LIMIT 1')->fetch_object()->payload_json,true);
checkAuto($audit['strategy'] === 'manual_worklist_movement' && $audit['created_native'] === 1 && $audit['native_bank_line_id'] === $nativeId
	&& $audit['amount_decimal'] === '-83.12345678' && $audit['note'] === 'Explicit synthetic review', 'Atomic audit identifies explicit creation, exact source, native ID and review note');
rejectAuto(static function () use ($service,$review) { $service->createWorklistMovementAndReconcile($review,'VIR'); }, 'Successful creation review cannot be replayed');
rejectAuto(static function () use ($service,$competingReview) { $service->createWorklistMovementAndReconcile($competingReview,'VIR'); }, 'Another prepared approval cannot create a second movement for the completed source');
$balanceAfter = $db->query('SELECT CAST(SUM(CAST(amount AS DECIMAL(24,8))) AS DECIMAL(24,8)) amount FROM kbauto_bank WHERE fk_account=101')->fetch_object()->amount;
checkAuto($balanceAfter === KreaBankPaymentBatchDecimal::sum(array($balanceBefore,'-83.12345678')) && countAuto('bank') === $bankBefore+1, 'Owned native balance changes exactly once by the original signed bank amount');
checkAuto(countAuto('kreabank_quick_entry') === $quickBefore && countAuto('kreabank_ml_sample') === $learnBefore
	&& $db->query('SELECT * FROM kbauto_paiement ORDER BY rowid')->fetch_all(MYSQLI_ASSOC) === $paymentsBefore
	&& $db->query('SELECT * FROM kbauto_paiementfourn ORDER BY rowid')->fetch_all(MYSQLI_ASSOC) === $supplierBefore, 'Creation invents no quick metadata, learning sample or invoice payment allocation');
checkAuto(!$db->ddlInsideTransaction && $db->depth === 0, 'Shared schema initialization stays outside the completed financial transaction');
stageAuto(141060,'85.12345678','POSITIVE-CREATE');
$db->query("INSERT INTO kbauto_paiement(rowid,entity,ref,datec,datep,amount,fk_bank,fk_paiement) VALUES(141060,1,'CUSTOMER-PAYMENT',NOW(),'2026-11-01',85.12345678,0,1)");
rejectAuto(static function () use ($service) { $service->prepareWorklistMovementReview('staged:141060'); }, 'A positive receipt also checks existing unbanked customer payments');
$db->query('DELETE FROM kbauto_paiement WHERE rowid=141060');
$positiveId = $service->createWorklistMovementAndReconcile($service->prepareWorklistMovementReview('staged:141060'),'CHQ');
checkAuto($db->query('SELECT CAST(amount AS DECIMAL(24,8)) amount FROM kbauto_bank WHERE rowid=' . $positiveId)->fetch_object()->amount === '85.12345678', 'Explicit native creation retains the positive source direction without inventing a balancing adjustment');
stageAuto(141070,'96.12345678','OVERFLOW-CREATE');
for ($i=0;$i<101;$i++) { bankAuto(142000+$i,'96.12345678','',101); }
$overflow = '';
try { $service->prepareWorklistMovementReview('staged:141070'); } catch (DomainException $error) { $overflow = $error->getMessage(); }
checkAuto(strpos($overflow,'incomplete') !== false, 'A bounded native conflict scan fails closed explicitly on overflow');
echo 'OK: ' . ($checks-$initialChecks) . " worklist movement database checks (native lifecycle)\n";
