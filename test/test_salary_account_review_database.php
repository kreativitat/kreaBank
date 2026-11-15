#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 * ONLY the guarded disposable 127.0.0.1:33317/kreabank_auto_test fixture.
 * All names and financial evidence are synthetic; no installation bootstrap.
 */
require __DIR__.'/test_account_correction_database.php';
$initialSalaryChecks=$checks;
$GLOBALS['draftInvoiceFixtureModules']=array('salaries');
foreach (array('salary','payment_salary') as $table) {
	$sql=preg_replace('/--[^\n]*/','',file_get_contents(DOL_DOCUMENT_ROOT.'/install/mysql/tables/llx_'.$table.'.sql'));
	foreach (explode(';',str_replace('llx_',MAIN_DB_PREFIX,$sql)) as $statement) { if(trim($statement)!==''){$db->query($statement);} }
}
$db->query('ALTER TABLE kbauto_user ADD entity INT DEFAULT 1,ADD firstname VARCHAR(50),ADD lastname VARCHAR(50),ADD statut SMALLINT DEFAULT 1');
$db->query("INSERT INTO kbauto_user(rowid,login,entity,firstname,lastname,statut) VALUES(180001,'synthetic.employee',1,'Avery','Example',0),(180002,'foreign.employee',2,'Other','Employee',1)");
class SalaryAccountFixtureUser extends User
{
	public $denied=array(),$children=array(7,180001);
	public function hasRight($a,$b,$c='') { return $this->allowed && !in_array(implode('.',array_filter(array($a,$b,$c))),$this->denied,true); }
	public function getAllChildIds($include=1) { return $this->children; }
}
$user=new SalaryAccountFixtureUser(); $service=new KreaBankService($db,$user,$langs);
function salaryAccountFixture($id,$amount='444.27') {
	global $db;
	stageAuto($id,'-'.$amount,''); bankAuto($id+1000,'-'.$amount,'',102);
	$db->query("UPDATE kbauto_kreabank_statement_line SET description='Transfer Avery Example',payment_reference=NULL WHERE rowid=$id");
	$db->query("INSERT INTO kbauto_salary(rowid,entity,fk_user,amount,fk_typepayment,label,datesp,dateep,ref,paye) VALUES($id,1,180001,$amount,1,'Synthetic salary','2026-10-01','2026-10-31','SAL-$id',1)");
	$db->query("INSERT INTO kbauto_payment_salary(rowid,entity,fk_salary,datep,amount,fk_typepayment,num_payment,fk_bank) VALUES($id,1,$id,'2026-11-01',$amount,1,'',".($id+1000).')');
	$db->query("INSERT INTO kbauto_bank_url(fk_bank,url_id,url,label,type) VALUES(".($id+1000).",$id,'/salaries/payment_salary/card.php?id=','(paiement)','payment_salary'),(".($id+1000).",180001,'/user/card.php?id=','Avery Example','user')");
}
function salaryAccountEntry($id=180010) { global $service; return $service->searchOtherAccountMovements($id,'',$id+1000)[0]??null; }
function salaryAccountCorrect($entry,$actor=null) { global $service; return ($actor?:$service)->changeMovementAccount($entry['source_id'],$entry['rowid'],$entry['snapshot'],$entry['fk_account'],$entry['target_account_id']); }
function salaryAccountState() {
	global $db; $out=array();
	foreach(array('salary','payment_salary','user','bank','bank_url','kreabank_statement_line','kreabank_recon_audit') as $table) {
		$out[$table]=$db->query('SELECT * FROM kbauto_'.$table.' ORDER BY rowid')->fetch_all(MYSQLI_ASSOC);
	} return $out;
}
salaryAccountFixture(180010);
$state=salaryAccountState(); $review=$service->getPaymentAccountReview(180010,array(101)); $entry=salaryAccountEntry();
checkAuto($review && count($review['candidates'])===1 && $review['candidates'][0]['payment_type']==='payment_salary'
	&& $review['candidates'][0]['bank_line_id']===181010 && $review['candidates'][0]['parent_id']===180010,'Wrong-bank salary is identified through the complete employee identity and original native payment');
checkAuto($entry && $entry['can_move'] && count($entry['salary_facts']['payments'])===1,'Coherent native salary payment/user links permit the existing account-only correction');
checkAuto(salaryAccountState()===$state,'Salary warning and correction preparation change no financial/source/audit evidence');
checkAuto($service->getAutomaticNativeSuggestion(180010,array(101))===null,'A salary account warning blocks unattended reconciliation');
foreach(array('Avery','Example','Avery Exampleton','Different Employee') as $name) {
	$db->query("UPDATE kbauto_kreabank_statement_line SET description='$name' WHERE rowid=180010");
	checkAuto($service->getPaymentAccountReview(180010,array(101))===null,'Partial and unrelated employee identities cannot create a salary account warning');
}
$db->query("UPDATE kbauto_kreabank_statement_line SET description='Transfer Avery Example',payment_reference='CONFLICT-REF-12345' WHERE rowid=180010");
checkAuto($service->getPaymentAccountReview(180010,array(101))===null,'A conflicting explicit bank reference cannot be overridden by an employee name');
$db->query("UPDATE kbauto_kreabank_statement_line SET description='',payment_reference='SALARY-REF-12345' WHERE rowid=180010");
$db->query("UPDATE kbauto_payment_salary SET num_payment='SALARY-REF-12345' WHERE rowid=180010");
checkAuto($service->getPaymentAccountReview(180010,array(101))!==null,'A complete native salary payment reference independently identifies the warning');
$db->query("UPDATE kbauto_kreabank_statement_line SET payment_reference='SALARY-REF-123456' WHERE rowid=180010");
checkAuto($service->getPaymentAccountReview(180010,array(101))===null,'Salary reference prefixes are never an identity match');
$db->query("UPDATE kbauto_kreabank_statement_line SET description='Transfer Avery Example',payment_reference=NULL WHERE rowid=180010");
$db->query("UPDATE kbauto_payment_salary SET num_payment='' WHERE rowid=180010");
foreach(array('salaries.read','salaries.write') as $right) {
	$actor=clone $user; $actor->denied=array($right); $actorService=new KreaBankService($db,$actor,$langs);
	if($right==='salaries.read') {
		checkAuto($actorService->getPaymentAccountReview(180010,array(101))===null && $actorService->searchOtherAccountMovements(180010,'',181010)===array(),'Read-all without native base salary read cannot disclose salary evidence');
	} else {
		checkAuto($actorService->getPaymentAccountReview(180010,array(101))!==null && !$actorService->searchOtherAccountMovements(180010,'',181010)[0]['can_move'],'Salary readers can inspect a warning while lacking correction authority');
	}
	rejectAuto(static function()use($actorService,$entry){salaryAccountCorrect($entry,$actorService);},'Salary permissions are rechecked before an account change');
}
$limited=clone $user; $limited->denied=array('salaries.readall'); $limited->children=array(7);
$limitedService=new KreaBankService($db,$limited,$langs);
checkAuto($limitedService->getPaymentAccountReview(180010,array(101))===null && $limitedService->searchOtherAccountMovements(180010,'',181010)===array(),'Native employee hierarchy restricts salary evidence before projection');
$limited->children[]=180001;
checkAuto($limitedService->getPaymentAccountReview(180010,array(101))!==null,'An owned inactive employee remains visible through the authorized hierarchy');
foreach(array(array('salary','entity=2','entity=1'),array('payment_salary','entity=2','entity=1'),array('salary','fk_user=180002','fk_user=180001'),array('payment_salary','fk_salary=999999','fk_salary=180010')) as $change) {
	$db->query('UPDATE kbauto_'.$change[0].' SET '.$change[1].' WHERE rowid=180010');
	checkAuto($service->getPaymentAccountReview(180010,array(101))===null && salaryAccountEntry()===null,'Foreign/missing salary, payment and employee ownership cannot leak through an owned bank');
	$db->query('UPDATE kbauto_'.$change[0].' SET '.$change[2].' WHERE rowid=180010');
}
foreach(array('rappro=1','fk_bordereau=8') as $change) {
	$db->query('UPDATE kbauto_bank SET '.$change.' WHERE rowid=181010');
	checkAuto($service->getPaymentAccountReview(180010,array(101))!==null && !salaryAccountEntry()['can_move'],'Reconciled or deposited salary payments remain warnings without unlocking financial locks');
	rejectAuto(static function()use($entry){salaryAccountCorrect($entry);},'Stale salary review cannot bypass a native financial lock');
	$db->query('UPDATE kbauto_bank SET rappro=0,fk_bordereau=NULL WHERE rowid=181010');
}
$db->query("INSERT INTO kbauto_accounting_bookkeeping(rowid,doc_ref,fk_user_author,doc_date,doc_type,fk_doc,fk_docdet,numero_compte,label_compte,label_operation,debit,credit,montant,sens,code_journal,journal_label,piece_num,entity,date_creation) VALUES(180010,'SYNTHETIC',7,'2026-11-01','bank',181010,181010,'TEST','Test','Test',0,444.27,444.27,'C','TEST','Test',180010,1,NOW())");
checkAuto($service->getPaymentAccountReview(180010,array(101))!==null && !salaryAccountEntry()['can_move'],'Posted salary payment is visible as a warning but cannot move');
rejectAuto(static function()use($entry){salaryAccountCorrect($entry);},'Posted bookkeeping remains protected under the existing native guard');
$db->query('DELETE FROM kbauto_accounting_bookkeeping WHERE rowid=180010');
$db->query("UPDATE kbauto_bank_url SET url_id=999999 WHERE fk_bank=181010 AND type='payment_salary'");
checkAuto(salaryAccountEntry()===null,'A dangling native salary payment link is never admitted for correction');
$db->query("UPDATE kbauto_bank_url SET url_id=180010 WHERE fk_bank=181010 AND type='payment_salary'");
$db->query("UPDATE kbauto_bank_url SET url_id=180002 WHERE fk_bank=181010 AND type='user'");
checkAuto(!salaryAccountEntry()['can_move'],'An unrelated employee bank link cannot be treated as an ordinary salary payment');
$db->query("UPDATE kbauto_bank_url SET url_id=180001 WHERE fk_bank=181010 AND type='user'");
foreach(array('payment_salary','user') as $linkType) {
	$link=(array)$db->query("SELECT * FROM kbauto_bank_url WHERE fk_bank=181010 AND type='$linkType'")->fetch_object();
	$db->query("DELETE FROM kbauto_bank_url WHERE rowid=".(int)$link['rowid']);
	checkAuto(!salaryAccountEntry()['can_move'],'A missing native salary payment/employee bank link cannot be corrected');
	$db->query("INSERT INTO kbauto_bank_url(rowid,fk_bank,url_id,url,label,type) VALUES(".(int)$link['rowid'].",181010,".(int)$link['url_id'].",'".$db->escape($link['url'])."','".$db->escape($link['label'])."','$linkType')");
	$db->query("INSERT INTO kbauto_bank_url(fk_bank,url_id,url,label,type) VALUES(181010,".(int)$link['url_id'].",'".$db->escape($link['url'])."','".$db->escape($link['label'])."','$linkType')");
	$duplicate=(int)$db->last_insert_id('kbauto_bank_url');
	checkAuto(!salaryAccountEntry()['can_move'],'Duplicate native salary payment/employee links cannot be treated as a coherent single payment');
	$db->query('DELETE FROM kbauto_bank_url WHERE rowid='.$duplicate);
}
$db->query("INSERT INTO kbauto_payment_salary(rowid,entity,fk_salary,datep,amount,fk_typepayment,num_payment,fk_bank) VALUES(180011,1,180010,'2026-11-01',444.27,1,'',181010)");
checkAuto(!salaryAccountEntry()['can_move'],'Multiple native salary payments claiming one debit cannot be moved as a single coherent payment');
$db->query('DELETE FROM kbauto_payment_salary WHERE rowid=180011');
foreach(array('Avery','SAL-180010') as $needle) {
	checkAuto((int)$service->searchOtherAccountMovements(180010,$needle)[0]['rowid']===181010,'Native salary employee and parent references remain searchable without another matching engine');
}
foreach(array('2026-11-04 23:59:59'=>true,'2026-11-05 00:00:00'=>false) as $day=>$expected) {
	$db->query("UPDATE kbauto_bank SET dateo='$day' WHERE rowid=181010");
	checkAuto(($service->getPaymentAccountReview(180010,array(101))!==null)===$expected,'Salary warning respects both complete calendar-day tolerance boundaries');
}
$db->query("UPDATE kbauto_bank SET dateo='2026-11-01' WHERE rowid=181010");
foreach(array(array('salary',"label='Changed salary'","label='Synthetic salary'"),array('salary','paye=0','paye=1'),array('payment_salary',"num_payment='CHANGED-12345'","num_payment=''"),array('user',"firstname='Changed'","firstname='Avery'")) as $change) {
	$entry=salaryAccountEntry(); $id=$change[0]==='user'?180001:180010;
	$db->query('UPDATE kbauto_'.$change[0].' SET '.$change[1].' WHERE rowid='.$id);
	rejectAuto(static function()use($entry){salaryAccountCorrect($entry);},'Every changed salary/payment/employee fact invalidates the exact correction snapshot');
	$db->query('UPDATE kbauto_'.$change[0].' SET '.$change[2].' WHERE rowid='.$id);
}
$schema=preg_replace('/--[^\n]*/','',file_get_contents(__DIR__.'/../sql/llx_kreabank_payment_batch.sql'));
foreach(explode(';',str_replace('llx_',MAIN_DB_PREFIX,$schema)) as $statement){if(trim($statement)!==''){$db->query($statement);}}
$entry=salaryAccountEntry();
$db->query("INSERT INTO kbauto_kreabank_payment_batch_line(entity,fk_batch,fk_bank_line,active_bank_line,amount,snapshot_json) VALUES(1,999999,181010,181010,-444.27,'{}')");
checkAuto(salaryAccountEntry()===null,'An active group claim excludes salary correction');
rejectAuto(static function()use($entry){salaryAccountCorrect($entry);},'Group ownership is rechecked under the correction locks');
$db->query('DELETE FROM kbauto_kreabank_payment_batch_line WHERE fk_bank_line=181010');
foreach(array('UPDATE kbauto_bank SET fk_account','INSERT INTO kbauto_kreabank_recon_audit') as $failure) {
	$entry=salaryAccountEntry(); $state=salaryAccountState(); $db->fail=$failure;
	rejectAuto(static function()use($entry){salaryAccountCorrect($entry);},'Salary correction exposes account/audit write failures');
	$db->fail=''; checkAuto(salaryAccountState()===$state && $db->depth===0,'Failed salary correction rolls back account and audit without altering any payment/parent/source');
}
$entry=salaryAccountEntry(); $payment=(array)$db->query('SELECT * FROM kbauto_payment_salary WHERE rowid=180010')->fetch_object();
$parent=(array)$db->query('SELECT * FROM kbauto_salary WHERE rowid=180010')->fetch_object();
$bank=(array)$db->query('SELECT * FROM kbauto_bank WHERE rowid=181010')->fetch_object(); $bankCount=countAuto('bank');
checkAuto(salaryAccountCorrect($entry),'The existing account-only adapter corrects the reviewed salary payment');
$after=(array)$db->query('SELECT * FROM kbauto_bank WHERE rowid=181010')->fetch_object(); $after['fk_account']=$bank['fk_account']; unset($bank['tms'],$after['tms']);
checkAuto($after===$bank && countAuto('bank')===$bankCount && (array)$db->query('SELECT * FROM kbauto_payment_salary WHERE rowid=180010')->fetch_object()===$payment
	&& (array)$db->query('SELECT * FROM kbauto_salary WHERE rowid=180010')->fetch_object()===$parent,'Correction preserves every financial field, payment identity and native salary paid state');
checkAuto($service->getPaymentAccountReview(180010,array(101))===null && (int)$service->getCorrectedAccountMovement(180010,181010)['rowid']===181010
	&& (int)$db->query('SELECT status FROM kbauto_kreabank_statement_line WHERE rowid=180010')->fetch_object()->status===0,'Corrected salary becomes an ordinary same-account native candidate and source remains pending');
rejectAuto(static function()use($entry){salaryAccountCorrect($entry);},'The prior salary correction review cannot be replayed');

// Raw candidate overflow stays blocking even when no identity in the truncated set matches.
salaryAccountFixture(180020,'555.61');
$db->query("UPDATE kbauto_kreabank_statement_line SET description='No employee identity' WHERE rowid=180020");
for($i=0;$i<101;$i++) {
	$id=182000+$i; bankAuto($id,'-555.61','',102);
	$db->query("INSERT INTO kbauto_payment_salary(rowid,entity,fk_salary,datep,amount,fk_typepayment,num_payment,fk_bank) VALUES($id,1,180020,'2026-11-01',555.61,1,'',$id)");
}
$overflow=$service->getPaymentAccountReview(180020,array(101));
checkAuto($overflow && $overflow['truncated'] && !$overflow['candidates'],'Unmatched salary overflow is an explicit blocking review, never proof of no conflicts');
echo 'OK: '.($checks-$initialSalaryChecks)." salary account review/correction checks\n";
