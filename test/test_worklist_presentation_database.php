#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 * ONLY the guarded disposable 127.0.0.1:33317/kreabank_auto_test fixture.
 * Cached ownership is read afresh; no installation bootstrap or credentials.
 */
require __DIR__.'/test_salary_account_review_database.php';
require_once __DIR__.'/../class/KreaBankWorklist.class.php';
require_once __DIR__.'/../lib/kreabank_manual_review.lib.php';
$initialPresentationChecks=$checks;

// Target ownership and salary visibility can change without a source/user change.
$worklist=new KreaBankWorklist($db,1,$service);
function presentationVisibleNativeFixture($source,$bank){global $service;return array('line'=>$service->getBulkReviewLine($source,'staged'),
 'pair'=>array('suggestion'=>array('doc_type'=>'native_bank','doc_id'=>$bank,'bank_account_id'=>101)));}
$supplierPresentation=presentationVisibleNativeFixture(80000,81000);$salaryPresentation=presentationVisibleNativeFixture(180010,181010);
checkAuto($worklist->presentationVisible($supplierPresentation),'Owned original supplier payment is visible without rerunning matching');
checkAuto($worklist->presentationVisible($salaryPresentation),'Owned original salary payment is visible without rerunning matching');
foreach(array(array('facture_fourn','rowid=80000','entity=2','entity=1',$supplierPresentation),
 array('paiementfourn','rowid=80000','entity=2','entity=1',$supplierPresentation),
 array('salary','rowid=180010','entity=2','entity=1',$salaryPresentation),
 array('payment_salary','rowid=180010','entity=2','entity=1',$salaryPresentation),
 array('user','rowid=180001','entity=2','entity=1',$salaryPresentation),
 array('salary','rowid=180010','fk_user=180002','fk_user=180001',$salaryPresentation)) as $change) {
 $db->query('UPDATE kbauto_'.$change[0].' SET '.$change[2].' WHERE '.$change[1]);
 try {checkAuto(!$worklist->presentationVisible($change[4]),'Current '.$change[0].' ownership denies stale cached facts');}
 finally {$db->query('UPDATE kbauto_'.$change[0].' SET '.$change[3].' WHERE '.$change[1]);}
}
$user->denied=array('salaries.read');
checkAuto(!$worklist->presentationVisible($salaryPresentation),'Current base salary read denial hides cached native salary');
$user->denied=array('salaries.readall');$user->children=array(7);
checkAuto(!$worklist->presentationVisible($salaryPresentation),'Current hierarchy denial hides cached native salary');
$user->children[]=180001;
checkAuto($worklist->presentationVisible($salaryPresentation),'Current authorized hierarchy admits existing owned salary');
$user->denied=array();$originalModules=$GLOBALS['draftInvoiceFixtureModules'];$GLOBALS['draftInvoiceFixtureModules']=array();
checkAuto(!$worklist->presentationVisible($salaryPresentation),'Disabled salary module hides cached native salary');
$GLOBALS['draftInvoiceFixtureModules']=$originalModules;
$db->query("INSERT INTO kbauto_salary(rowid,entity,fk_user,amount,fk_typepayment,label,datesp,dateep,ref,paye) VALUES(180030,1,180001,321.11,1,'Synthetic unpaid salary','2026-10-01','2026-10-31','SAL-180030',0)");
$planned=array('line'=>$salaryPresentation['line'],'pair'=>array('suggestion'=>array('doc_type'=>'salary','doc_id'=>180030)));
checkAuto($worklist->presentationVisible($planned),'Owned unpaid salary advice is visible');
$db->query('UPDATE kbauto_salary SET entity=2 WHERE rowid=180030');
checkAuto(!$worklist->presentationVisible($planned),'Re-owned planned salary hides cached advice');
$db->query('UPDATE kbauto_salary SET entity=1,paye=1 WHERE rowid=180030');
checkAuto(!$worklist->presentationVisible($planned),'Already paid planned salary is not reused as unpaid advice');
$completed=array('line'=>$salaryPresentation['line'],'completed'=>array('doc_type'=>'native_bank','doc_id'=>181010,'reference'=>'#181010'));
checkAuto($worklist->presentationVisible($completed),'Owned completed native record is visible');
$db->query('UPDATE kbauto_salary SET entity=2 WHERE rowid=180010');
checkAuto(!$worklist->presentationVisible($completed),'Re-owned completed native salary cannot disclose cached details');
$db->query('UPDATE kbauto_salary SET entity=1 WHERE rowid=180010');

// Same source/context is insufficient when a cached salary parent changes owner.
$source=$salaryPresentation['line'];$key='staged:180010';$now=time();$prepareCalls=0;
$scan=array('context'=>'synthetic-actor-and-filter','scope'=>'synthetic-scope','expires'=>$now+600,
 'sources'=>array($key=>array('hash'=>kreabankWorklistSourceFingerprint($source))),
 'visible'=>array($key),'summaries'=>array(),'presentations'=>array());
kreabankWorklistStoreSummary($scan,$key,array('key'=>$key,'_prepared'=>$salaryPresentation),$now);
$prepare=static function($key)use(&$prepareCalls,$worklist){$prepareCalls++;return $worklist->compact($key);};
$visible=static function($row)use($worklist){return $worklist->presentationVisible($row);};
checkAuto(kreabankWorklistPreparedPresentation($scan,$key,$source,true,$prepare,$now,$visible)===$salaryPresentation
 && $prepareCalls===0,'Unchanged visible target reuses presentation without invoking matching');
$db->query('UPDATE kbauto_salary SET entity=2 WHERE rowid=180010');
try {rejectAuto(static function()use(&$scan,$key,$source,$prepare,$now,$visible){
 kreabankWorklistPreparedPresentation($scan,$key,$source,true,$prepare,$now,$visible);
},'A changed target owner rejects cached presentation with the original source and actor context');}
finally {$db->query('UPDATE kbauto_salary SET entity=1 WHERE rowid=180010');}
checkAuto(!isset($scan['presentations'][$key]) && !isset($scan['summaries'][$key]) && $prepareCalls===0,
 'Visibility rejection evicts stale facts and never renders them as a fresh fallback');
$freshPrepared=kreabankWorklistPreparedPresentation($scan,$key,$source,false,$prepare,$now,$visible);
checkAuto($prepareCalls===1 && (int)$freshPrepared['line']['rowid']===180010,
 'A later owned reload prepares fresh presentation after stale data was evicted');
foreach(array($supplierPresentation,$salaryPresentation) as $presentation) {
 $id=(int)$presentation['pair']['suggestion']['doc_id'];
 $db->query('UPDATE kbauto_bank SET fk_account=202 WHERE rowid='.$id);
 try {checkAuto(!$worklist->presentationVisible($presentation),'A current foreign bank account hides an otherwise unchanged cached payment');}
 finally {$db->query('UPDATE kbauto_bank SET fk_account=101 WHERE rowid='.$id);}
}

// Correction retains the original payment; fresh signed approval only links it.
$qaCases=array('supplier'=>array(80000,81000,'paiementfourn','facture_fourn'), 'salary'=>array(180010,181010,'payment_salary','salary'));

function presentationRowsFixture($table,$where) {
 global $db;return $db->query('SELECT * FROM kbauto_'.$table.' WHERE '.$where.' ORDER BY rowid')->fetch_all(MYSQLI_ASSOC);
}
function presentationCaseStateFixture($type,$ids) {
 list($source,$bank,$payment,$parent)=$ids;
 return array('bank'=>presentationRowsFixture('bank','rowid='.$bank),'payment'=>presentationRowsFixture($payment,'rowid='.$source),
  'parent'=>presentationRowsFixture($parent,'rowid='.$source),'urls'=>presentationRowsFixture('bank_url','fk_bank='.$bank),
  'allocation'=>$type==='supplier'?presentationRowsFixture('paiementfourn_facturefourn','fk_paiementfourn='.$source):array(),
  'source'=>presentationRowsFixture('kreabank_statement_line','rowid='.$source),'audit'=>presentationRowsFixture('kreabank_recon_audit','fk_statement_line='.$source),
  'counts'=>array('bank'=>countAuto('bank'),'salary_payments'=>countAuto('payment_salary'),'supplier_payments'=>countAuto('paiementfourn'),
   'salary_parents'=>countAuto('salary'),'supplier_parents'=>countAuto('facture_fourn'),
   'groups'=>countAuto('kreabank_payment_batch'),'members'=>countAuto('kreabank_payment_batch_line')));
}
foreach($qaCases as $type=>$ids) {
 list($source,$bank)=$ids; $key='staged:'.$source;
 $pair=$service->prepareWorklistSelectionPair($key,$bank);
 $scope='1|7|'.$key;$expires=time()+900;$secret=str_repeat('Q',32);
 $signature=kreabankBulkReviewSignature($pair,$scope,$expires,$secret);
 $fresh=$service->prepareWorklistSelectionPair($key,$bank);
 checkAuto(kreabankBulkReviewIsValid($fresh,$scope,$expires,$signature,$secret,time(),true,101),$type.': freshly prepared exact existing movement validates the displayed signature');
 checkAuto(!kreabankBulkReviewIsValid($fresh,'2|7|'.$key,$expires,$signature,$secret,time(),true,101),$type.': foreign-company signature is rejected');
 checkAuto(!kreabankBulkReviewIsValid($fresh,$scope,$expires,$signature,$secret,time()+901,true,101),$type.': expired signature is rejected');
 $before=presentationCaseStateFixture($type,$ids);
 $denied=clone $user;$denied->allowed=false;$deniedService=new KreaBankService($db,$denied,$langs);
 rejectAuto(static function()use($deniedService,$fresh){kreabankExecuteManualReview($deniedService,$fresh);},$type.': current permission loss denies execution');
 checkAuto(presentationCaseStateFixture($type,$ids)===$before,$type.': permission denial changes no records');
 $conf->entity=2;
 try {rejectAuto(static function()use($service,$fresh){kreabankExecuteManualReview($service,$fresh);},$type.': company change denies execution');}
 finally {$conf->entity=1;}
 checkAuto(presentationCaseStateFixture($type,$ids)===$before,$type.': company denial changes no records');
 $db->fail='INSERT INTO kbauto_kreabank_recon_audit';
 try {rejectAuto(static function()use($service,$fresh){kreabankExecuteManualReview($service,$fresh);},$type.': audit failure denies and rolls back reconciliation');}
 finally {$db->fail='';}
 checkAuto(presentationCaseStateFixture($type,$ids)===$before && $db->depth===0,$type.': partial source/native/audit writes roll back together');
 checkAuto(kreabankExecuteManualReview($service,$fresh)===$source,$type.': signed selected-native approval reconciles the corrected original movement');
 $after=presentationCaseStateFixture($type,$ids);
 checkAuto($after['counts']===$before['counts'],$type.': no payment, movement, parent or one-member group is added');
 foreach(array('payment','parent','urls','allocation') as $field)checkAuto($after[$field]===$before[$field],$type.': preserves original '.$field.' identity and financial evidence');
 $bankBefore=$before['bank'][0];$bankAfter=$after['bank'][0];
 checkAuto((int)$bankAfter['fk_user_rappro']===7,$type.': native reconciliation records the reviewing actor');
 foreach(array('rappro','num_releve','fk_user_rappro','tms') as $field){unset($bankBefore[$field],$bankAfter[$field]);}
 checkAuto($bankBefore===$bankAfter,$type.': reconciliation preserves amount/date/account/reference and all other financial movement fields');
 checkAuto((int)$after['source'][0]['status']===2 && (int)$after['source'][0]['fk_native_bank_line']===$bank
  && (int)$after['bank'][0]['rappro']===1,$type.': original source and original native movement reconcile together');
 checkAuto(count($after['audit'])===count($before['audit'])+1 && end($after['audit'])['audit_type']==='reconcile',$type.': adds exactly one reconciliation audit beside the retained correction audit');
 checkAuto(count(presentationRowsFixture('kreabank_statement_line','fk_native_bank_line='.$bank))===1,$type.': exactly one source mapping targets the original movement');
 rejectAuto(static function()use($service,$fresh){kreabankExecuteManualReview($service,$fresh);},$type.': repeat approval is refused');
 checkAuto(presentationCaseStateFixture($type,$ids)===$after,$type.': retry makes no additional record or audit');
}

echo 'OK: '.($checks-$initialPresentationChecks)." worklist cached visibility and corrected payment checks\n";
