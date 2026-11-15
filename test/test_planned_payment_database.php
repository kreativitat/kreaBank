#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 * ONLY the guarded disposable 127.0.0.1:33317/kreabank_auto_test fixture.
 * Installed native Salary/PaymentSalary/ChargeSociales/PaymentSocialContribution
 * persist all financial records. No ERP bootstrap or installation credentials.
 */

require __DIR__.'/test_automatic_reconciliation_database.php';
// Only actor authorization is synthetic; employee identity comes from the fixture DB.
class PlannedPaymentFixtureUser extends User
{
	public $denied=array(), $children=array(7,170001);
	public function hasRight($a,$b,$c='') { return $this->allowed && !in_array(implode('.',array_filter(array($a,$b,$c))),$this->denied,true); }
	public function getAllChildIds($include=1) { return $this->children; }
}
$user=new PlannedPaymentFixtureUser();
$initialPlannedChecks=$checks;
require_once __DIR__.'/../class/KreaBankPlannedPayment.class.php';
require_once __DIR__.'/../class/KreaBankWorklist.class.php';
require_once __DIR__.'/../class/KreaBankHistory.class.php';
$_SESSION=array();
$GLOBALS['taxFixtureEnabled']=true;
$GLOBALS['draftInvoiceFixtureModules']=array('salaries','bank');
foreach (array('salary','payment_salary','c_paiement','c_country','c_departements','accounting_journal') as $table) {
	$sql=preg_replace('/--[^\n]*/','',file_get_contents(DOL_DOCUMENT_ROOT.'/install/mysql/tables/llx_'.$table.'.sql'));
	foreach (explode(';',str_replace('llx_',MAIN_DB_PREFIX,$sql)) as $statement) { if (trim($statement)!=='') { $db->query($statement); } }
}
$db->query('ALTER TABLE kbauto_user ADD entity INT NOT NULL DEFAULT 1,ADD firstname VARCHAR(50),ADD lastname VARCHAR(50),ADD statut SMALLINT DEFAULT 1');
$db->query("INSERT INTO kbauto_user(rowid,login,entity,firstname,lastname,statut) VALUES (170001,'synthetic.employee',1,'Avery','Example',0),(170002,'other.employee',2,'Other','Example',1)");
$db->query("INSERT INTO kbauto_c_paiement(id,entity,code,libelle,type,active) VALUES(1,1,'VIR','Transfer',2,1),(2,1,'CHQ','Cheque',1,1),(3,1,'OLD','Inactive',2,0),(4,2,'EXT','Foreign',2,1),(5,1,'CASHIN','Incoming only',0,1),(6,1,'OTHER','Other native type',3,1)");
$db->query("INSERT INTO kbauto_c_chargesociales(id,libelle,active,code) VALUES(1,'Synthetic tax',1,'TEST')");
$schema=preg_replace('/--[^\n]*/','',file_get_contents(__DIR__.'/../sql/llx_kreabank_payment_batch.sql'));
foreach (explode(';',str_replace('llx_',MAIN_DB_PREFIX,$schema)) as $statement) { if (trim($statement)!=='') { $db->query($statement); } }

function dol_trunc($value,$length,...$args) { return mb_substr($value,0,$length); }
function dol_sanitizeFileName($name,...$args) { return $name; }
function dol_getIP(...$args) { return '127.0.0.1'; }
function getDolGlobalFloat($key,$default=0) { return (float)($GLOBALS['conf']->global->$key??$default); }
function getDolGlobalBool($key) { return (bool)getDolGlobalInt($key); }
function dol_include_once($file,...$args) { return include_once DOL_DOCUMENT_ROOT.$file; }
function dol_buildpath($path,$absolute=0) { return $path; }
function dol_osencode($value) { return $value; }
function dol_print_error($db,$message='') {}
class Conf extends stdClass {}
class Translate extends AutoTestLangs { public function transnoentitiesnoconv($key) { return $key; } public function transnoentities($key) { return $key; } }
$oldConf=$conf; $conf=new Conf(); foreach ((array)$oldConf as $key=>$value) { $conf->$key=$value; }
$conf->modules_parts=array('triggers'=>array()); $conf->modules=array();
$conf->file=(object)array('dol_document_root'=>array(DOL_DOCUMENT_ROOT));
$conf->societe=(object)array('enabled'=>0); $conf->notification=(object)array('enabled'=>0);
$conf->global->MAIN_ROUNDING_RULE_TOT=0;
$langs=new Translate(); $extrafields->attributes['bank_account']=array('loaded'=>1,'label'=>array());
$extrafields->attributes['salary']=array('loaded'=>1,'label'=>array());

class PlannedPaymentFixtureDb extends DoliDB
{
	public $ddlInsideTransaction=false;
	public $queries=array();
	public $tamperBankReference=null;
	public $failOuterCommit=false;
	public function __construct($connection) { $this->connection=$connection; }
	public function query($sql) {
		$this->queries[]=$sql;
		if ($this->depth && preg_match('/^(CREATE|ALTER|DROP)\b/i',$sql)) { $this->ddlInsideTransaction=true; }
		if ($this->tamperBankReference!==null && strpos($sql,'SELECT b.*,CAST(b.amount AS DECIMAL(24,8)) AS amount_decimal FROM kbauto_bank b ')===0
			&& preg_match('/WHERE b\.rowid=([0-9]+)\b/',$sql,$match)) {
			parent::query("UPDATE kbauto_bank SET num_chq='".$this->escape($this->tamperBankReference)."' WHERE rowid=".(int)$match[1]);
			$this->tamperBankReference=null;
		}
		return parent::query($sql);
	}
	public function commit() { return $this->depth===1 && $this->failOuterCommit ? false : parent::commit(); }
}
$db=new PlannedPaymentFixtureDb($db->connection);
$service=new KreaBankService($db,$user,$langs);
$planned=new KreaBankPlannedPayment($db,$user,1,$service);
function plannedSalary($id,$amount='321.47',$employee=170001,$entity=1,$period='2026-10-31') {
	global $db;
	$db->query("INSERT INTO kbauto_salary(rowid,entity,fk_user,amount,fk_typepayment,label,datesp,dateep,ref,paye) VALUES($id,$entity,$employee,$amount,1,'Synthetic termination salary','2026-10-01','$period','SAL-$id',0)");
}
function plannedTax($id,$amount='428.16',$label='Synthetic tax November 2026',$ref='TAX-2026-170001',$entity=1) {
	global $db;
	$db->query("INSERT INTO kbauto_chargesociales(rowid,entity,date_ech,libelle,ref,fk_type,amount,paye,fk_mode_reglement,periode) VALUES($id,$entity,'2026-11-01','$label','$ref',1,$amount,0,1,'2026-10-01')");
}
function plannedStage($id,$amount='-321.47',$description='Transfer Avery Example') {
	global $db; stageAuto($id,$amount,'');
	$db->query("UPDATE kbauto_kreabank_statement_line SET operation_date='2026-11-09',value_date='2026-11-09',description='".$db->escape($description)."' WHERE rowid=$id");
}
function plannedLine($id) { global $service; return $service->getBulkReviewLine($id,'staged'); }
function plannedState() {
	global $db; $state=array();
	foreach (array('salary','payment_salary','chargesociales','paiementcharge','bank','bank_url','kreabank_statement_line','kreabank_bankmeta','kreabank_recon_audit') as $table) {
		$state[$table]=$db->query('SELECT * FROM kbauto_'.$table.' ORDER BY rowid')->fetch_all(MYSQLI_ASSOC);
	} return $state;
}

plannedSalary(170001); plannedStage(170001);
$before=plannedState(); $advice=$planned->propose(plannedLine(170001)); $salary=$advice['candidate'];
checkAuto($advice['status']==='unique' && $salary['type']==='salary' && $salary['id']===170001 && $salary['amount']==='321.47000000'
	&& $salary['doc_date']==='2026-10-31' && $salary['booking_date']==='2026-11-09' && $salary['source']['bank_account_ref']==='TEST A',
	'Complete employee identity and prior monthly period produce exact list-only salary advice, including inactive employees');
checkAuto(plannedState()===$before,'Salary advice and snapshots create no payment, mapping or audit');
checkAuto(array_column($planned->paymentModes(),'id')===array(2,6,1),'Only current-entity active native DBIT modes, including other type3, are editable');
foreach (array('Salary payment','Avery','Other Person') as $description) {
	$db->query("UPDATE kbauto_kreabank_statement_line SET description='$description' WHERE rowid=170001");
	checkAuto($planned->propose(plannedLine(170001))['status']==='none','Amount and date or incomplete employee identity never produce advice');
}
$db->query("UPDATE kbauto_kreabank_statement_line SET description='Transfer Avery Example' WHERE rowid=170001");
plannedSalary(170003); checkAuto($planned->propose(plannedLine(170001))['status']==='ambiguous','Two exact identity-supported unpaid salaries remain ambiguous');
$db->query('DELETE FROM kbauto_salary WHERE rowid=170003');
foreach (array("dateep='2026-09-01'","dateep='2026-11-10'",'entity=2','fk_user=170002','paye=1','amount=321.48') as $change) {
	$db->query('UPDATE kbauto_salary SET '.$change.' WHERE rowid=170001');
	rejectAuto(static function()use($planned){$planned->snapshot(plannedLine(170001),'salary',170001);},'Period, owner, employee, paid status and exact amount changes are rejected');
	$db->query("UPDATE kbauto_salary SET dateep='2026-10-31',entity=1,fk_user=170001,paye=0,amount=321.47 WHERE rowid=170001");
}
foreach (array('banque.lire','banque.modifier','kreabank.reconciliation.read','kreabank.reconciliation.write','salaries.read','salaries.write') as $right) {
	$denied=clone $user; $denied->denied=array($right); $adapter=new KreaBankPlannedPayment($db,$denied,1,$service);
	checkAuto(!$adapter->permissions('salary'),'Native and module read/write rights are mandatory');
}
$limited=clone $user; $limited->denied=array('salaries.readall'); $limited->children=array(7);
$limitedAdapter=new KreaBankPlannedPayment($db,$limited,1,$service);
rejectAuto(static function()use($limitedAdapter){$limitedAdapter->snapshot(plannedLine(170001),'salary',170001);},'Read-with-hierarchy cannot inspect a salary outside the employee scope');
$limited->children[]=170001;
checkAuto($limitedAdapter->snapshot(plannedLine(170001),'salary',170001)['id']===170001,'Native salary hierarchy permits the matching employee');
foreach (array('status=1','status=2','allocated_amount=0.01','fk_native_bank_line=10','is_duplicate=1',"currency='USD'") as $change) {
	$db->query('UPDATE kbauto_kreabank_statement_line SET '.$change.' WHERE rowid=170001');
	rejectAuto(static function()use($planned){$planned->snapshot(array('rowid'=>170001,'fk_statement'=>170001),'salary',170001);},'Partial, mapped, allocated, duplicate and foreign-currency sources fail closed');
	$db->query("UPDATE kbauto_kreabank_statement_line SET status=0,allocated_amount=0,fk_native_bank_line=NULL,is_duplicate=0,currency='EUR' WHERE rowid=170001");
}
$db->query('UPDATE kbauto_bank_account SET clos=1 WHERE rowid=101');
rejectAuto(static function()use($planned){$planned->snapshot(plannedLine(170001),'salary',170001);},'Closed account cannot record a payment');
$db->query('UPDATE kbauto_bank_account SET clos=0 WHERE rowid=101');
$db->query("INSERT INTO kbauto_const(entity,name,value) VALUES(1,'KREABANK_ACCOUNT_PAIRS','{\"101\":102}')");
rejectAuto(static function()use($planned){$planned->snapshot(plannedLine(170001),'salary',170001);},'Restricted account cannot create a salary payment');
$db->query("DELETE FROM kbauto_const WHERE name='KREABANK_ACCOUNT_PAIRS'");
$db->query("INSERT INTO kbauto_kreabank_payment_batch_draft(entity,fk_user,fk_statement_line,name,ids_json,datec) VALUES(1,7,170001,'Selection','[]',NOW())");
rejectAuto(static function()use($planned){$planned->snapshot(plannedLine(170001),'salary',170001);},'Even a cleared saved selection wins over creation advice');
$db->query('DELETE FROM kbauto_kreabank_payment_batch_draft WHERE fk_statement_line=170001');
$db->query("INSERT INTO kbauto_kreabank_payment_batch(entity,fk_statement_line,active_statement_line,bank_account_id,amount,currency,operation_date,statement_ref,name,status,source_snapshot,fk_user,datec) VALUES(1,170001,170001,101,-321.47,'EUR','2026-11-09','ST-170001','Synthetic group',1,'{}',7,NOW())");
rejectAuto(static function()use($planned){$planned->snapshot(plannedLine(170001),'salary',170001);},'An active grouped reconciliation blocks new payment creation');
$db->query('DELETE FROM kbauto_kreabank_payment_batch WHERE fk_statement_line=170001');
plannedStage(170004); rejectAuto(static function()use($planned){$planned->snapshot(plannedLine(170001),'salary',170001);},'Another same-day identity-supported staged claim blocks creation');
$db->query('DELETE FROM kbauto_kreabank_statement_line WHERE rowid=170004');
bankAuto(170005,'-321.47','',101,'2026-11-09');
rejectAuto(static function()use($planned){$planned->snapshot(plannedLine(170001),'salary',170001);},'A matching existing native debit blocks duplicate payment creation');
$db->query('DELETE FROM kbauto_bank WHERE rowid=170005');
plannedSalary(170006);
$db->query("INSERT INTO kbauto_payment_salary(rowid,entity,fk_salary,amount,fk_typepayment,num_payment,datep,fk_bank) VALUES(170006,1,170006,321.47,1,'OLD-UNBANKED','2026-11-09',0)");
rejectAuto(static function()use($planned){$planned->snapshot(plannedLine(170001),'salary',170001);},'Another parent unbanked salary payment with the same employee, amount and date blocks creation');
bankAuto(170006,'-321.47','OTHER-OWNED-ACCOUNT',102,'2026-11-09');
$db->query('UPDATE kbauto_payment_salary SET fk_bank=170006 WHERE rowid=170006');
$state=plannedState();
rejectAuto(static function()use($planned){$planned->snapshot(plannedLine(170001),'salary',170001);},'A salary payment for the same employee, exact amount and booking date in another owned account blocks creation');
checkAuto(plannedState()===$state,'Other-account salary evidence is a read-only refusal without ledger or payment correction');
$db->query('DELETE FROM kbauto_bank WHERE rowid=170006');
$db->query('DELETE FROM kbauto_payment_salary WHERE rowid=170006'); $db->query('DELETE FROM kbauto_salary WHERE rowid=170006');
$db->query("INSERT INTO kbauto_payment_salary(rowid,entity,fk_salary,amount,fk_typepayment,num_payment,datep,fk_bank) VALUES(170007,1,170001,1.25,1,'PARTIAL-UNBANKED','2026-10-31',0)");
$db->query('UPDATE kbauto_salary SET amount=322.72 WHERE rowid=170001');
rejectAuto(static function()use($planned){$planned->snapshot(plannedLine(170001),'salary',170001);},'Any same-parent unbanked partial payment blocks even exact remaining advice');
$db->query('DELETE FROM kbauto_payment_salary WHERE rowid=170007'); $db->query('UPDATE kbauto_salary SET amount=321.47 WHERE rowid=170001');
$review=$planned->snapshot(plannedLine(170001),'salary',170001);
foreach (array('description'=>"Changed statement",'amount'=>'-321.48','operation_date'=>'2026-11-10') as $field=>$value) {
	$original=$db->query("SELECT $field FROM kbauto_kreabank_statement_line WHERE rowid=170001")->fetch_object()->$field;
	$db->beforeBegin=static function()use($db,$field,$value){$db->query("UPDATE kbauto_kreabank_statement_line SET $field='".$db->escape($value)."' WHERE rowid=170001");};
	rejectAuto(static function()use($planned,$review){$planned->create($review,1,'SYNTHETIC','');},'Exact signed source facts are checked again after transaction locks');
	$db->query("UPDATE kbauto_kreabank_statement_line SET $field='".$db->escape($original)."' WHERE rowid=170001");
}
$db->beforeBegin=static function()use($db){$db->query('UPDATE kbauto_bank_account SET clos=1 WHERE rowid=101');};
rejectAuto(static function()use($planned,$review){$planned->create($review,1,'SYNTHETIC','');},'An account closed after display is denied under creation locks');
$db->query('UPDATE kbauto_bank_account SET clos=0 WHERE rowid=101');

// Exact partial history must remain banked and owned; all rows affect remaining.
plannedSalary(170020,'476.95'); plannedStage(170020,'-475.70');
bankAuto(170020,'-1.25','PRIOR-SYNTHETIC',101,'2026-10-01');
$db->query("INSERT INTO kbauto_payment_salary(rowid,entity,fk_salary,amount,fk_typepayment,num_payment,datep,fk_bank) VALUES(170020,1,170020,1.25,1,'PRIOR-SYNTHETIC','2026-10-01',170020)");
$partial=$planned->snapshot(plannedLine(170020),'salary',170020);
checkAuto($partial['remaining']==='475.70000000' && count($partial['payments'])===1,'Exact remaining subtracts every earlier banked salary payment');
$db->beforeBegin=static function()use($db){$db->query('UPDATE kbauto_payment_salary SET amount=1.26 WHERE rowid=170020');};
rejectAuto(static function()use($planned,$partial){$planned->create($partial,1,'SYNTHETIC','');},'Changed earlier allocations invalidate the signed remaining balance');
$db->query('UPDATE kbauto_payment_salary SET amount=1.25,entity=2 WHERE rowid=170020');
rejectAuto(static function()use($planned){$planned->snapshot(plannedLine(170020),'salary',170020);},'A foreign salary payment row cannot contribute to owned remaining');
$db->query('UPDATE kbauto_payment_salary SET entity=1 WHERE rowid=170020');
$db->query('UPDATE kbauto_bank SET fk_account=202 WHERE rowid=170020');
rejectAuto(static function()use($planned){$planned->snapshot(plannedLine(170020),'salary',170020);},'Earlier salary payments cannot rely on another company bank evidence');
$db->query('UPDATE kbauto_bank SET fk_account=101 WHERE rowid=170020');
$partial=$planned->snapshot(plannedLine(170020),'salary',170020);
$partialResult=$planned->create($partial,6,'SYNTHETIC-PARTIAL','');
checkAuto($partialResult['payment_id']>0 && countAuto('salary')===2,'The selected native type3 mode supports an exact remaining salary payment');

// Both real native lifecycle branches must roll back on every payment/bank/audit failure.
plannedTax(170010); plannedStage(170010,'-428.16','Payment TAX-2026-170001');
checkAuto($planned->propose(plannedLine(170010))['candidate']['type']==='tax','Strong full tax reference plus exact amount produces tax advice');
$db->query("UPDATE kbauto_kreabank_statement_line SET description='Tax payment' WHERE rowid=170010");
checkAuto($planned->propose(plannedLine(170010))['status']==='none','Amount and date cannot identify a tax');
$db->query("UPDATE kbauto_chargesociales SET libelle='Social Security' WHERE rowid=170010");
$db->query("UPDATE kbauto_kreabank_statement_line SET description='Social Security' WHERE rowid=170010");
checkAuto($planned->propose(plannedLine(170010))['status']==='none','Generic tax kinds do not count as liability identity');
$db->query("UPDATE kbauto_chargesociales SET libelle='Synthetic tax November 2026' WHERE rowid=170010");
$db->query("UPDATE kbauto_kreabank_statement_line SET description='Payment TAX-2026-170001' WHERE rowid=170010");
foreach (array('salary'=>170001,'tax'=>170010) as $type=>$id) {
	$review=$planned->snapshot(plannedLine($id),$type,$id);
	foreach (array(0,3,4,5) as $mode) { rejectAuto(static function()use($planned,$review,$mode){$planned->create($review,$mode,'SYNTHETIC','');},'Missing, inactive, foreign and incoming-only modes are rejected'); }
	foreach (array(array(str_repeat('X',51),''),array('',str_repeat('N',1001)),array("bad\0ref",'')) as $input) {
		rejectAuto(static function()use($planned,$review,$input){$planned->create($review,1,$input[0],$input[1]);},'Server validates editable text length and controls');
		checkAuto($planned->failureStage()==='input','Invalid optional text exposes only the fixed input diagnostic stage');
	}
	$parent=$type==='salary'?'salary':'chargesociales'; $payments=$type==='salary'?'payment_salary':'paiementcharge';
	$db->beforeBegin=static function()use($db,$parent,$id){$db->query("UPDATE kbauto_$parent SET amount=amount+0.01 WHERE rowid=$id");};
	rejectAuto(static function()use($planned,$review){$planned->create($review,1,'SYNTHETIC','');},'Parent remaining is freshly checked after transaction locks');
	checkAuto($planned->failureStage()==='admission','Changed reviewed facts report the fixed admission stage');
	$db->query("UPDATE kbauto_$parent SET amount=amount-0.01 WHERE rowid=$id");
	$review=$planned->snapshot(plannedLine($id),$type,$id);
	$failures=array('INSERT INTO kbauto_'.$payments=>'payment_create','UPDATE kbauto_'.$parent.' SET'=>'verification',
		'INSERT INTO kbauto_bank ('=>'bank_attachment','INSERT INTO kbauto_bank_url'=>'bank_attachment',
		'UPDATE kbauto_'.$payments.' SET fk_bank'=>'bank_attachment','INSERT INTO kbauto_kreabank_recon_audit'=>'audit');
	foreach ($failures as $failure=>$stage) {
		$state=plannedState(); $db->fail=$failure;
		rejectAuto(static function()use($planned,$review){$planned->create($review,1,'SYNTHETIC','Test-only note');},'Native failure is visible: '.$failure);
		$db->fail='';
		checkAuto(plannedState()===$state && $db->depth===0,'Payment, native paid state, ledger, links, source and audit roll back together');
		checkAuto($planned->failureStage()===$stage,'Native failure exposes only its fixed workflow diagnostic stage');
	}
	$state=plannedState(); $db->tamperBankReference='DIFFERENT-NONEMPTY-REFERENCE';
	rejectAuto(static function()use($planned,$review){$planned->create($review,1,'APPROVED-REFERENCE','');},'A different nonempty bank reference is rejected after native creation');
	checkAuto($planned->failureStage()==='verification' && plannedState()===$state && $db->depth===0,
		'Nonempty reference mismatch rolls back payment, paid parent, native debit, links and audit');
	$state=plannedState(); $db->failOuterCommit=true;
	rejectAuto(static function()use($planned,$review){$planned->create($review,1,'','');},'A confirmed outer commit failure remains visible');
	$db->failOuterCommit=false;
	checkAuto($planned->failureStage()==='commit' && plannedState()===$state && $db->depth===0,'The fixed commit stage preserves rollback of a confirmed non-commit');
	$source=(array)$db->query("SELECT * FROM kbauto_kreabank_statement_line WHERE rowid=$id")->fetch_object();
	$financialBefore=array(countAuto('salary'),countAuto('chargesociales'),countAuto($payments),countAuto('bank'));
	// The native form permits an empty reference and persists bank.num_chq as NULL.
	$result=$planned->create($review,1,'','Test-only note');
	$recorded=$db->query("SELECT * FROM kbauto_$payments WHERE rowid=".$result['payment_id'])->fetch_object();
	$bankReference=$db->query('SELECT num_chq FROM kbauto_bank WHERE rowid='.$result['native_bank_line_id'])->fetch_object()->num_chq;
	checkAuto($recorded->{$type==='salary'?'num_payment':'num_paiement'}==='' && $bankReference===null && $planned->failureStage()==='complete',
		'Blank optional reference remains exact on the real native payment and accepts its NULL bank alias');
	checkAuto($result['payment_id']>0 && $result['native_bank_line_id']>0 && $financialBefore===array(countAuto('salary'),countAuto('chargesociales'),countAuto($payments)-1,countAuto('bank')-1),
		'Native creation adds exactly one payment and debit, never a salary or tax document');
	checkAuto((array)$db->query("SELECT * FROM kbauto_kreabank_statement_line WHERE rowid=$id")->fetch_object()===$source
		&& (int)$source['status']===0 && empty($source['fk_native_bank_line']),'Recording a native payment leaves the imported source pending and unmapped');
	$audit=json_decode($db->query("SELECT payload_json FROM kbauto_kreabank_recon_audit WHERE audit_type='planned_payment_create' AND fk_statement_line=$id")->fetch_object()->payload_json,true);
	checkAuto($audit['payment_id']===$result['payment_id'] && $audit['native_bank_line_id']===$result['native_bank_line_id'] && $audit['native_reconciled']===0,'Atomic payment audit preserves source and native payment evidence');
	$state=plannedState(); rejectAuto(static function()use($planned,$review){$planned->create($review,1,'SYNTHETIC','');},'A repeated reviewed submission cannot create another payment');
	checkAuto(plannedState()===$state,'Rejected replay has no financial or audit effects');
	checkAuto($planned->failureStage()==='admission','Every new attempt resets the diagnostic stage before replay admission fails');
	$worklist=new KreaBankWorklist($db,1,$service); $prepared=$worklist->prepare('staged:'.$id); $pair=$prepared['pair'];
	checkAuto($pair && $pair['suggestion']['doc_type']==='native_bank' && (int)$pair['suggestion']['doc_id']===$result['native_bank_line_id'],
		'A fresh list preparation proposes the newly created native debit through the ordinary matching path');
	$expires=time()+900; $scope='1|7|101'; $secret=str_repeat('synthetic-planned-payment-',3);
	$signature=kreabankBulkReviewSignature($pair,$scope,$expires,$secret);
	$fresh=(new KreaBankWorklist($db,1,$service))->prepare('staged:'.$id)['pair'];
	checkAuto(kreabankBulkReviewIsValid($fresh,$scope,$expires,$signature,$secret,time(),true,101),
		'The ordinary freshly recomputed native pair authenticates the displayed payment debit');
	$nativeState=array('payment'=>(array)$db->query("SELECT * FROM kbauto_$payments WHERE rowid=".$result['payment_id'])->fetch_object(),
		'parent'=>(array)$db->query("SELECT * FROM kbauto_$parent WHERE rowid=$id")->fetch_object(),
		'bank_count'=>countAuto('bank'),'payment_count'=>countAuto($payments));
	checkAuto(kreabankExecuteManualReview($service,$fresh)===$id,'The separately approved ordinary native review reconciles the source');
	$history=(new KreaBankHistory($db,1))->find($id,'staged',101);
	checkAuto($history && !$history['needs_review'] && (int)$history['native_bank_line_id']===$result['native_bank_line_id'],
		'History verifies the same scalar source/native identity without a one-member group');
	checkAuto($service->undoReconciliation($id,'Synthetic native-payment undo verification',KreaBankHistory::snapshot($history)),
		'History undo uses the existing shared native reconciliation lifecycle');
	checkAuto($nativeState===array('payment'=>(array)$db->query("SELECT * FROM kbauto_$payments WHERE rowid=".$result['payment_id'])->fetch_object(),
		'parent'=>(array)$db->query("SELECT * FROM kbauto_$parent WHERE rowid=$id")->fetch_object(),
		'bank_count'=>countAuto('bank'),'payment_count'=>countAuto($payments))
		&& (int)$db->query("SELECT status FROM kbauto_kreabank_statement_line WHERE rowid=$id")->fetch_object()->status===0
		&& (int)$db->query('SELECT rappro FROM kbauto_bank WHERE rowid='.$result['native_bank_line_id'])->fetch_object()->rappro===0,
		'Undo returns source and debit to pending while retaining the created native payment and paid parent');
}

// Native PHP truthiness also treats the exact text '0' as an absent bank cheque
// reference; keep that native alias without rewriting either payment reference.
foreach (array('salary'=>170030,'tax'=>170040) as $type=>$id) {
	if ($type==='salary') { plannedSalary($id,'612.34'); plannedStage($id,'-612.34'); }
	else { plannedTax($id,'723.45','Synthetic tax October 2026','TAX-ZERO-123456'); plannedStage($id,'-723.45','Payment TAX-ZERO-123456'); }
	$review=$planned->snapshot(plannedLine($id),$type,$id); $result=$planned->create($review,1,'0','');
	$table=$type==='salary'?'payment_salary':'paiementcharge'; $referenceField=$type==='salary'?'num_payment':'num_paiement';
	$paymentReference=$db->query("SELECT $referenceField FROM kbauto_$table WHERE rowid=".$result['payment_id'])->fetch_object()->$referenceField;
	$native=$db->query('SELECT num_chq,rappro,CAST(amount AS DECIMAL(24,8)) amount_decimal FROM kbauto_bank WHERE rowid='.$result['native_bank_line_id'])->fetch_object();
	checkAuto($paymentReference==='0' && $native->num_chq===null && $native->amount_decimal===$review['source']['amount'] && (int)$native->rappro===0,
		'Zero reference remains exact on salary/tax payment while accepting only the native absent-bank alias');
	checkAuto($planned->failureStage()==='complete' && (int)$db->query("SELECT status FROM kbauto_kreabank_statement_line WHERE rowid=$id")->fetch_object()->status===0,
		'Zero-reference creation commits normally and leaves reconciliation for separate approval');
}
checkAuto(!$db->ddlInsideTransaction,'Financial creation performs no schema changes');
echo 'OK: '.($checks-$initialPlannedChecks)." guarded planned-payment checks\n";
