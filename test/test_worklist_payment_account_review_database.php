#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 * ONLY the guarded disposable 127.0.0.1:33317/kreabank_auto_test fixture.
 */
require __DIR__.'/test_planned_payment_database.php';
$initialWorklistAccountChecks=$checks;
$sql=preg_replace('/--[^\n]*/','',file_get_contents(DOL_DOCUMENT_ROOT.'/install/mysql/tables/llx_accounting_bookkeeping-accounting.sql'));
foreach(explode(';',str_replace('llx_',MAIN_DB_PREFIX,$sql)) as $statement){if(trim($statement)!==''){$db->query($statement);}}
$service=new KreaBankService($db,$user,$langs);
function weakAccountSource($id,$amount) {
	global $db; stageAuto($id,$amount,'');
	$db->query("UPDATE kbauto_kreabank_statement_line SET description='Generic TSU debit',payment_reference=NULL WHERE rowid=$id");
}
function weakAccountState($id) {
	global $db;
	return array('source'=>(array)$db->query('SELECT * FROM kbauto_kreabank_statement_line WHERE rowid='.(int)$id)->fetch_object(),
		'bank'=>countAuto('bank'),'payment_salary'=>countAuto('payment_salary'),'tax_payment'=>countAuto('paiementcharge'),'audit'=>countAuto('kreabank_recon_audit'),
		'groups'=>countAuto('kreabank_payment_batch'),'members'=>countAuto('kreabank_payment_batch_line'));
}
weakAccountSource(196001,'-67.91'); bankAuto(197001,'-67.91','',102);
$db->query("INSERT INTO kbauto_chargesociales(rowid,entity,date_ech,libelle,fk_type,amount,paye,fk_mode_reglement) VALUES(196001,1,'2026-11-01','TSU',1,67.91,1,1)");
$db->query("INSERT INTO kbauto_paiementcharge(rowid,fk_charge,datep,amount,fk_typepaiement,num_paiement,fk_bank) VALUES(196001,196001,'2026-11-01',67.91,1,'',197001)");
$db->query("INSERT INTO kbauto_bank_url(fk_bank,url_id,url,label,type) VALUES(197001,196001,'/compta/payment_sc/card.php?id=','(paiement)','payment_sc'),(197001,196001,'/compta/charges.php?id=','TSU','sc')");
$before=weakAccountState(196001); $review=$service->getWorklistPaymentAccountReview(196001,array(101));
checkAuto($service->getPaymentAccountReview(196001,array(101))===null,'Generic TSU amount/date remains outside strong payment identity');
checkAuto($review && $review['evidence']==='amount_date' && $review['status']==='unique' && !$review['truncated']
	&& count($review['candidates'])===1 && $review['candidates'][0]['bank_line_id']===197001
	&& $review['candidates'][0]['payment_type']==='native_bank','The list exposes the unique exact same-day other-account movement as manual weak evidence');
checkAuto(weakAccountState(196001)===$before,'Weak account advice writes no source, payment, bank or audit record');
checkAuto($service->getAutomaticNativeSuggestion(196001,array(101))===null,'Weak account advice creates no unattended native suggestion');
checkAuto($service->getWorklistPaymentAccountReview(196001,array(102))===null,'Weak review retains the explicit source-account allowlist');
rejectAuto(static function()use($service){$service->listWorklistSelectionCandidates('staged:196001');},'A weak wrong-account proposal blocks the list selector');
rejectAuto(static function()use($service){$service->prepareWorklistMovementReview('staged:196001');},'A weak wrong-account proposal blocks duplicate list movement creation');

bankAuto(197002,'-67.91','',102,'2026-11-02');
checkAuto($service->getWorklistPaymentAccountReview(196001,array(101))['status']==='unique','An adjacent-day movement never competes with same-day weak evidence');
$db->query("UPDATE kbauto_bank SET dateo='2026-11-01',rappro=1 WHERE rowid=197002");
$ambiguous=$service->getWorklistPaymentAccountReview(196001,array(101));
checkAuto($ambiguous['status']==='ambiguous' && count($ambiguous['candidates'])===2,'Uniqueness is evaluated before move eligibility; a locked competing record cannot be discarded');
$db->query('DELETE FROM kbauto_bank WHERE rowid=197002');
$db->query('UPDATE kbauto_bank SET rappro=1 WHERE rowid=197001');
checkAuto(!$service->getWorklistPaymentAccountReview(196001,array(101))['candidates'][0]['can_move'],'A sole reconciled weak candidate remains review-only');
$db->query('UPDATE kbauto_bank SET rappro=0 WHERE rowid=197001');
bankAuto(197003,'-67.91','',101);
checkAuto($service->getWorklistPaymentAccountReview(196001,array(101))===null,'An eligible same-day full-amount own-account movement suppresses only weak other-account advice');
checkAuto($service->prepareWorklistSelectionPair('staged:196001',197003)['suggestion']['doc_id']===197003,'Weak coincidences never disable a valid ordinary same-account selection');
$db->query('UPDATE kbauto_bank SET rappro=1 WHERE rowid=197003');
checkAuto($service->getWorklistPaymentAccountReview(196001,array(101))['status']==='unique','An ineligible reconciled own-account movement cannot suppress the weak warning');
$db->query('UPDATE kbauto_bank SET rappro=0 WHERE rowid=197003');
$db->query("INSERT INTO kbauto_kreabank_payment_batch_line(entity,fk_batch,fk_bank_line,active_bank_line,amount,snapshot_json) VALUES(1,999999,197003,197003,-67.91,'{}')");
checkAuto($service->getWorklistPaymentAccountReview(196001,array(101))['status']==='unique','An already claimed own-account movement cannot suppress weak evidence');
$db->query('DELETE FROM kbauto_kreabank_payment_batch_line WHERE fk_bank_line=197003');
$db->query("UPDATE kbauto_kreabank_statement_line SET payment_reference='TAX-REF-123456' WHERE rowid=196001");
$db->query("UPDATE kbauto_paiementcharge SET num_paiement='TAX-REF-123456' WHERE rowid=196001");
checkAuto($service->getPaymentAccountReview(196001,array(101))!==null && $service->getWorklistPaymentAccountReview(196001,array(101))!==null,
	'Existing strong identity review still blocks even with an eligible own-account movement');
$db->query("UPDATE kbauto_kreabank_statement_line SET payment_reference=NULL WHERE rowid=196001");
$db->query("UPDATE kbauto_paiementcharge SET num_paiement='' WHERE rowid=196001");
$db->query('DELETE FROM kbauto_bank WHERE rowid=197003');

// Stale movement creation must repeat the list guard after its account/source locks.
weakAccountSource(196010,'-71.22'); $movement=$service->prepareWorklistMovementReview('staged:196010');
$state=weakAccountState(196010);
$db->beforeBegin=static function(){bankAuto(197010,'-71.22','',102);};
rejectAuto(static function()use($service,$movement){$service->createWorklistMovementAndReconcile($movement,'VIR');},'An other-account movement appearing after review blocks creation under locks');
$after=weakAccountState(196010); $after['bank']--;
checkAuto($state===$after && $db->depth===0,'The stale creation retains only the concurrent external movement and makes no financial/audit/source change');

// Signed group execution still rechecks the list guard; group size remains >=2.
weakAccountSource(196020,'-93.33'); bankAuto(197020,'-40','',101); bankAuto(197021,'-53.33','',101);
$batch=$service->preparePaymentBatchReview(196020,array(197020,197021),'Synthetic existing payments');
$state=weakAccountState(196020);
$db->beforeBegin=static function(){bankAuto(197022,'-93.33','',102);};
rejectAuto(static function()use($service,$batch){$service->reconcilePaymentBatch($batch);},'A weak full-amount other-account movement invalidates a previously signed group under locks');
$after=weakAccountState(196020); $after['bank']--;
checkAuto($after===$state && $db->depth===0,'Rejected group execution creates no reconciliation, group, payment or audit');
rejectAuto(static function()use($service){$service->preparePaymentBatchReview(196020,array(197020,197021),'Synthetic existing payments');},'New group review is blocked until the weak account issue is resolved');

// A singleton stays usable while its own native target is eligible. Once it is
// concurrently claimed, the new weak account issue is checked before execution.
weakAccountSource(196030,'-81.77'); bankAuto(197030,'-81.77','',101);
$pair=$service->prepareWorklistSelectionPair('staged:196030',197030); $auditCount=countAuto('kreabank_recon_audit');
$db->beforeBegin=static function()use($db){$db->query('UPDATE kbauto_bank SET rappro=1 WHERE rowid=197030');bankAuto(197031,'-81.77','',102);};
rejectAuto(static function()use($service,$pair){kreabankExecuteManualReview($service,$pair);},'Stale singleton execution rechecks the current weak account guard after locks');
checkAuto((int)$db->query('SELECT status FROM kbauto_kreabank_statement_line WHERE rowid=196030')->fetch_object()->status===0
	&& countAuto('kreabank_recon_audit')===$auditCount && $db->depth===0,'A stale singleton cannot reconcile or audit over new other-account evidence');

// Planned native payment creation uses the same list guard, without adding it to the matcher.
weakAccountSource(196040,'-89.41'); plannedTax(196040,'89.41','Synthetic tax November 2026','TAX-WEAK-12345');
$db->query("UPDATE kbauto_kreabank_statement_line SET description='Payment TAX-WEAK-12345' WHERE rowid=196040");
$payments=new KreaBankPlannedPayment($db,$user,1,$service); $planned=$payments->snapshot(plannedLine(196040),'tax',196040);
$state=weakAccountState(196040); $db->beforeBegin=static function(){bankAuto(197040,'-89.41','',102);};
rejectAuto(static function()use($payments,$planned){$payments->create($planned,1,'','');},'A weak other-account movement appearing after payment review blocks native payment creation');
$after=weakAccountState(196040); $after['bank']--;
checkAuto($state===$after && $payments->failureStage()==='admission' && $db->depth===0,'The planned payment refuses weak conflict before any native financial write');

// Complete candidate bounds cannot choose an arbitrary first weak movement.
weakAccountSource(196050,'-92.46');
for($i=0;$i<101;$i++){bankAuto(198000+$i,'-92.46','',102);}
$overflow=$service->getWorklistPaymentAccountReview(196050,array(101));
checkAuto($overflow && $overflow['truncated'] && $overflow['status']==='truncated','A truncated default search blocks the list instead of promoting its first result');

weakAccountSource(196060,'-98.37'); bankAuto(197060,'-98.37','AUTO-NATIVE-REF-196060',101); bankAuto(197061,'-98.37','',102);
$db->query("UPDATE kbauto_kreabank_statement_line SET payment_reference='AUTO-NATIVE-REF-196060' WHERE rowid=196060");
checkAuto($service->getPaymentAccountReview(196060,array(101))===null && $service->getAutomaticNativeSuggestion(196060,array(101))!==null,
	'Weak account coincidences never alter the shared automatic safe-suggestion engine');
checkAuto($service->getWorklistPaymentAccountReview(196060,array(101))===null,'The list retains the eligible exact same-day own-account native choice');

// Reviewed correction and reconciliation retain the original native tax/payment identities.
$entry=$service->searchOtherAccountMovements(196001,'',197001)[0];
$payment=(array)$db->query('SELECT * FROM kbauto_paiementcharge WHERE rowid=196001')->fetch_object();
$parent=(array)$db->query('SELECT * FROM kbauto_chargesociales WHERE rowid=196001')->fetch_object(); $bankCount=countAuto('bank');
checkAuto($service->changeMovementAccount(196001,197001,$entry['snapshot'],102,101),'Explicit weak-case review corrects the original native movement through the existing adapter');
checkAuto($service->getWorklistPaymentAccountReview(196001,array(101))===null,'Weak warning disappears after the account is corrected');
$fresh=$service->prepareWorklistSelectionPair('staged:196001',197001);
checkAuto(kreabankExecuteManualReview($service,$fresh)===196001,'A newly prepared ordinary native review reconciles the corrected movement');
checkAuto(countAuto('bank')===$bankCount && (array)$db->query('SELECT * FROM kbauto_paiementcharge WHERE rowid=196001')->fetch_object()===$payment
	&& (array)$db->query('SELECT * FROM kbauto_chargesociales WHERE rowid=196001')->fetch_object()===$parent
	&& (int)$db->query('SELECT fk_native_bank_line FROM kbauto_kreabank_statement_line WHERE rowid=196001')->fetch_object()->fk_native_bank_line===197001,
	'Correction and reconciliation preserve tax/payment identity and paid state without creating another debit');
echo 'OK: '.($checks-$initialWorklistAccountChecks)." list-only weak account review checks\n";
