#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Pure displayed-context and URL checks. No application bootstrap/database.
 */
require_once __DIR__.'/../lib/kreabank_planned_payment.lib.php';
require_once __DIR__.'/../lib/kreabank_worklist.lib.php';
require_once __DIR__.'/../lib/kreabank_worklist_view.lib.php';
function dol_buildpath($path,$mode=0) { return $path; }
function dol_print_date($time,$format) { return gmdate('d/m/Y',$time); }
function kreabankPrice($amount) { return number_format((float)$amount,2,'.',','); }
function dolButtonToOpenUrlInDialogPopup($name,$label,$button,$url,$disabled='',$class='',$open='',$close='') { return '<a href="'.htmlspecialchars($url,ENT_QUOTES).'">'.$button.'</a>'; }
$checks=0;
function plannedViewCheck($test,$message) { global $checks; if(!$test)throw new RuntimeException($message);$checks++; }
function plannedViewReject($callback,$message) { $rejected=false;try{$callback();}catch(DomainException $e){$rejected=true;}plannedViewCheck($rejected,$message); }
$conf=(object)array('entity'=>1);$user=(object)array('id'=>7);$_SESSION=array();
$langs=new class {public function trans($key){return $key;}};
$context=array('type'=>'salary','id'=>51,'source'=>array('rowid'=>11,'bank_account_id'=>10,'bank_account_ref'=>'BANK <one>'),'reference'=>'#51','label'=>'Synthetic <employee>','booking_date'=>'2026-11-09','amount'=>'100.00000000','remaining'=>'100.00000000','currency'=>'EUR');
$nonce=kreabankPlannedPaymentRemember($context);
plannedViewCheck(preg_match('/^[a-f0-9]{48}$/D',$nonce)===1,'Displayed context receives an unpredictable native-filter-compatible nonce');
plannedViewCheck(kreabankPlannedPaymentReview($nonce)===$context && kreabankPlannedPaymentReview($nonce)===$context,'Repeated GET opens preserve the same exact facts');
$conf->entity=2;plannedViewReject(static function()use($nonce){kreabankPlannedPaymentReview($nonce);},'Company switch refuses displayed payroll facts');$conf->entity=1;
$user->id=8;plannedViewReject(static function()use($nonce){kreabankPlannedPaymentReview($nonce);},'Another actor cannot view or submit the context');$user->id=7;
foreach(array('',str_repeat('f',47),str_repeat('f',49),str_repeat('F',48),array($nonce),$nonce."\n") as $bad) {plannedViewReject(static function()use($bad){kreabankPlannedPaymentReview($bad);},'Malformed contexts fail closed');}
plannedViewCheck(kreabankPlannedPaymentReview($nonce,true)===$context,'POST consumes exactly its displayed facts');
plannedViewReject(static function()use($nonce){kreabankPlannedPaymentReview($nonce,true);},'Replayed financial context is unavailable');
$expired=kreabankPlannedPaymentRemember($context);$_SESSION['kreabank_planned_payments'][$expired]['expires']=time()-1;
plannedViewReject(static function()use($expired){kreabankPlannedPaymentReview($expired);},'Expired displays must be reopened');
for($i=0;$i<110;$i++){kreabankPlannedPaymentRemember($context);}
plannedViewCheck(count($_SESSION['kreabank_planned_payments'])===100,'Private contexts remain bounded without financial state changes');
$model=array('url'=>'/custom/kreabank/reconciliationlist.php','state'=>array('search_account'=>'10'));
$line=array('bank_account_id'=>10,'bank_account_ref'=>'BANK');
foreach(array('salary','tax') as $type) {
 $candidate=array('doc_type'=>$type,'doc_id'=>51,'payment_url'=>kreabankPlannedPaymentUrl($type,51,$nonce));
 $html=kreabankWorklistPaymentButton($candidate,$line,'staged:11','test-'.$type,$model,$langs);
 plannedViewCheck(strpos($html,'data-kb-payment-popup')!==false && strpos($html,'recordpayment.php')!==false,'Existing '.$type.' uses the same native popup lifecycle');
 plannedViewCheck(strpos($html,'refresh_payment=staged%3A11')!==false,'Payment completion refresh remains limited to its owned source');
 foreach(array('https://foreign.example'.$candidate['payment_url'],$candidate['payment_url'].'&amount_51=500',$candidate['payment_url'].'&accountid=99',str_replace('id=51','id=52',$candidate['payment_url']),str_replace('type='.$type,'type=customer_invoice',$candidate['payment_url']),str_replace('review='.$nonce,'review[]=bad',$candidate['payment_url']),'/salaries/payment_salary.php?action=create&id=51','/compta/paiement_charge.php?action=create&id=51') as $bad) {
  $test=$candidate;$test['payment_url']=$bad;
  plannedViewCheck(kreabankWorklistPaymentButton($test,$line,'staged:11','test',$model,$langs)==='','Unsafe/native unguarded '.$type.' payment URL is not rendered');
 }
 $pair=array('line'=>$line,'suggestion'=>array('doc_type'=>$type));
 plannedViewCheck(kreabankWorklistPaymentAccount($pair)===array('id'=>10,'ref'=>'BANK','planned'=>true) && !kreabankWorklistPairCanReconcile($pair),'Unpaid '.$type.' stays advisory with an explicitly planned bank');
}
plannedViewCheck(kreabankWorklistDocumentUrl('salary',51)==='/salaries/card.php?id=51','Salary reference opens its native parent, not a payment action');
$html=kreabankPlannedPaymentEvidence($context,$langs);
plannedViewCheck(strpos($html,'Synthetic &lt;employee&gt;')!==false && strpos($html,'BANK &lt;one&gt;')!==false,'Parent evidence and bank labels are escaped once');
plannedViewCheck(strpos($html,'09/11/2026')!==false && substr_count($html,'100.00')===2,'Native evidence displays the exact reviewed date, remaining and amount');
plannedViewCheck(strpos($html,'<input')===false && strpos($html,'<select')===false,'Amount, date and account cannot be edited away from the statement');
echo 'OK '.$checks." planned payment view/context checks\n";
