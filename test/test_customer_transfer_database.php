#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 * ONLY guarded disposable 127.0.0.1:33317/kreabank_auto_test; resets kbauto_*.
 * Existing synthetic payments, allocations and movements are fixture inputs.
 * Real shared matcher/signed-review/native AccountLine execution; no payment creation.
 */
require __DIR__.'/test_automatic_reconciliation_database.php';
require_once __DIR__.'/../class/KreaBankCustomerTransfer.class.php';
require_once __DIR__.'/../class/KreaBankWorklist.class.php';
require_once __DIR__.'/../lib/kreabank_manual_review.lib.php';
$initialCustomerChecks=$checks;
$GLOBALS['draftInvoiceFixtureModules']=array('facture','invoice');
foreach(array('c_paiement','societe_commerciaux','salary','payment_salary','accounting_bookkeeping-accounting')as$table){
	$sql=preg_replace('/--[^\n]*/','',file_get_contents(DOL_DOCUMENT_ROOT.'/install/mysql/tables/llx_'.$table.'.sql'));
	foreach(explode(';',str_replace('llx_',MAIN_DB_PREFIX,$sql))as$statement){if(trim($statement)!==''){$db->query($statement);}}
}
$schema=preg_replace('/--[^\n]*/','',file_get_contents(__DIR__.'/../sql/llx_kreabank_payment_batch.sql'));
foreach(explode(';',str_replace('llx_',MAIN_DB_PREFIX,$schema))as$statement){if(trim($statement)!==''){$db->query($statement);}}
$db->query("INSERT INTO kbauto_c_paiement(id,entity,code,libelle,type,active) VALUES(901,1,'VIR','Synthetic bank transfer',2,1),(902,1,'TRF','Synthetic transfer synonym',2,1),(903,1,'CHQ','Synthetic cheque',2,1),(904,2,'VIR','Foreign dictionary',2,1)");
$db->query("INSERT INTO kbauto_societe(rowid,entity,nom,client) VALUES(860001,1,'Avery Synthetic Trading Lda',1),(860002,1,'Other Synthetic Customer Lda',1),(860003,2,'Foreign Synthetic Customer Lda',1)");
class CustomerTransferFixtureActor extends User
{
	public $denied=array();
	public function hasRight($a,$b,$c='') { return $this->allowed && !in_array(implode('.',array_filter(array($a,$b,$c))),$this->denied,true); }
}
class CustomerTransferFixtureService extends KreaBankService
{
	public function nativeCandidate($id,$account=101) { return $this->getOpenNativeBankDocuments(1,20,null,0,0,$account,$id,true); }
	public function defaultNativeCandidate($id) { return $this->getOpenNativeBankDocuments(1,20,null,0,0,101,$id); }
}
$user=new CustomerTransferFixtureActor();$service=new CustomerTransferFixtureService($db,$user,$langs);
function customerTransferFixture($seed,$amount='379.42',$account=101)
{
	global $db;
	$f=array('source'=>$seed,'bank'=>$seed+100,'payment'=>$seed+200,'invoice1'=>$seed+300,'invoice2'=>$seed+301,'allocation1'=>$seed+400,'allocation2'=>$seed+401,
		'amount'=>$amount,'reference'=>'RC-SYNTHETIC-'.$seed,'number'=>'SEPA-20310410-'.$seed);
	$second=KreaBankPaymentBatchDecimal::sum(array($amount,'-100.11'));
	stageAuto($f['source'],$amount,$f['number']);
	$db->query("UPDATE kbauto_kreabank_statement_line SET operation_date='2031-04-10',value_date='2031-04-10',description='TRF CRED SEPA Avery Synthetic Trading Lda' WHERE rowid=".$f['source']);
	bankAuto($f['bank'],$amount,'',$account,'2031-04-10');
	$db->query("UPDATE kbauto_bank SET fk_type='VIR',label='(CustomerInvoicePayment)' WHERE rowid=".$f['bank']);
	$db->query("INSERT INTO kbauto_paiement(rowid,entity,ref,datec,datep,amount,fk_bank,fk_paiement,num_paiement) VALUES(".$f['payment'].",1,'".$f['reference']."',NOW(),'2031-04-10',$amount,".$f['bank'].",901,'".$f['number']."')");
	foreach(array(array($f['invoice1'],$f['allocation1'],'100.11'),array($f['invoice2'],$f['allocation2'],$second))as$allocation){
		list($invoice,$id,$allocated)=$allocation;
		$db->query("INSERT INTO kbauto_facture(rowid,entity,ref,fk_soc,datec,datef,total_ttc,fk_statut,paye,type,multicurrency_code) VALUES($invoice,1,'INV-SYNTHETIC-$invoice',860001,NOW(),'2031-04-01',$allocated,2,1,0,'EUR')");
		$db->query("INSERT INTO kbauto_paiement_facture(rowid,fk_paiement,fk_facture,amount,multicurrency_code) VALUES($id,".$f['payment'].",$invoice,$allocated,'EUR')");
	}
	$db->query("INSERT INTO kbauto_bank_url(fk_bank,url_id,url,label,type) VALUES(".$f['bank'].",".$f['payment'].",'/compta/paiement/card.php?id=','(paiement)','payment')");
	return $f;
}
function customerTransferMeta($bank,$actor=null)
{
	global $db,$user;
	return (new KreaBankCustomerTransfer($db,$actor?:$user,1))->metadataForBankIds(array($bank));
}
function customerTransferPair($source,$bank)
{
	global $service;
	$line=$service->getBulkReviewLine($source,'staged');
	$matches=$service->getSuggestionsForLines(array($line),0,3,null,false,true)[$source]??array();
	foreach($matches as$match){if($match['doc_type']==='native_bank' && (int)$match['doc_id']===$bank){
		return array('line'=>$line,'suggestion'=>$match,'allocation'=>abs((float)$line['amount']),'preselected'=>false,'is_bulk_safe'=>false,'approval_context'=>'selected_native','other_account_warning'=>false);
	}}
	throw new RuntimeException('Recorded synthetic transfer is not a native candidate');
}
function customerTransferFinancialState()
{
	global $db;$state=array();
	$state['bank']=$db->query('SELECT rowid,fk_account,dateo,datev,amount,label,num_chq,fk_type,note FROM kbauto_bank WHERE rowid>=840000 ORDER BY rowid')->fetch_all(MYSQLI_ASSOC);
	foreach(array('paiement','paiement_facture','facture')as$table){$state[$table]=$db->query('SELECT * FROM kbauto_'.$table.' WHERE rowid>=840000 ORDER BY rowid')->fetch_all(MYSQLI_ASSOC);}
	$state['bank_url']=$db->query('SELECT * FROM kbauto_bank_url WHERE fk_bank>=840000 ORDER BY rowid')->fetch_all(MYSQLI_ASSOC);
	return $state;
}
function customerTransferSignedApply($row,$actorService=null)
{
	global $service;
	$expires=time()+900;$scope='1|7|101';$secret=str_repeat('synthetic-customer-review-',3);
	$signature=kreabankBulkReviewSignature($row,$scope,$expires,$secret);
	if(!kreabankBulkReviewIsValid($row,$scope,$expires,$signature,$secret,time(),true,101)){throw new RuntimeException('Synthetic signed review is invalid');}
	return kreabankExecuteManualReview($actorService?:$service,$row);
}

$f=customerTransferFixture(840000);
$before=customerTransferFinancialState();$counts=array(countAuto('bank'),countAuto('paiement'),countAuto('kreabank_recon_audit'),countAuto('kreabank_payment_batch'));
$metadata=customerTransferMeta($f['bank']);$meta=$metadata[$f['bank']]??null;
checkAuto($meta && $meta['payment_id']===$f['payment'] && $meta['allocation_count']===2 && $meta['customer_id']===860001
	&& $meta['invoice_refs']===array('INV-SYNTHETIC-'.$f['invoice1'],'INV-SYNTHETIC-'.$f['invoice2']),'An existing owned multi-invoice transfer retains exact receipt/customer/allocation evidence');
$document=$service->nativeCandidate($f['bank'])[0];
checkAuto($document['rowid']===$f['bank'] && $document['doc_type']==='native_bank' && $document['ref']==='(paiement)'
	&& $document['amount_open']===379.42 && substr($document['doc_date'],0,10)==='2031-04-10' && !isset($document['native_ref']),'Enrichment preserves the original native candidate identity, reference, date and amount');
checkAuto($document['customer_transfer']===$meta && $document['thirdparty_name']==='Avery Synthetic Trading Lda'
	&& strpos($document['payment_ref'],$f['reference'])!==false && strpos($document['ref_client'],'INV-SYNTHETIC-')!==false,'Native candidate advice adds actual recorded receipt and invoice/customer evidence');
$raw=$service->defaultNativeCandidate($f['bank'])[0];
checkAuto(!isset($raw['customer_transfer']) && $raw['fk_soc']===0 && $raw['ref']===$document['ref'] && $raw['thirdparty_name']!==$document['thirdparty_name'],'Default native discovery remains raw even after manual enrichment on the same service');
$defaultLine=$service->getBulkReviewLine($f['source'],'staged');
$defaultAdvice=$service->getSuggestionsForLines(array($defaultLine),0,3,null,false)[$f['source']]??array();
checkAuto(!array_filter($defaultAdvice,static function($candidate){return isset($candidate['customer_transfer']);})
	&& $service->getAutomaticNativeSuggestion($f['source'],array(101))===null,'Recorded receipt-only identity never enters default/automatic matching without explicit manual opt-in');
$row=customerTransferPair($f['source'],$f['bank']);
checkAuto(isset($row['suggestion']['customer_transfer']) && in_array('ref_payment',$row['suggestion']['details'],true)
	&& strpos(kreabankBulkSuggestionReference($row['suggestion']),$f['reference'])===0,'The unchanged shared matcher proposes the receipt by its real bank reference and displays its actual receipt reference');
$prepared=(new KreaBankWorklist($db,1,$service))->prepare('staged:'.$f['source']);
checkAuto(($prepared['pair']['suggestion']['doc_type']??'')==='native_bank' && (int)$prepared['pair']['suggestion']['doc_id']===$f['bank']
	&& $prepared['pair']['suggestion']['customer_transfer']===$meta,'Actual reconciliation-list preparation presents the existing transfer as its shared native proposal');
checkAuto(customerTransferFinancialState()===$before && $counts===array(countAuto('bank'),countAuto('paiement'),countAuto('kreabank_recon_audit'),countAuto('kreabank_payment_batch')),'Metadata, matching and preparation perform no financial or audit writes');
$readonly=clone $user;$readonly->denied=array('banque.modifier','kreabank.reconciliation.write');
$readonlyService=new CustomerTransferFixtureService($db,$readonly,$langs);
rejectAuto(static function()use($row,$readonlyService){customerTransferSignedApply($row,$readonlyService);},'Receipt read/enrichment authority never grants manual reconciliation write permission');
checkAuto(customerTransferFinancialState()===$before && countAuto('kreabank_recon_audit')===$counts[2],'Denied write preserves recorded customer transfer and audit state');

// Enrichment fails closed; incompatible native candidates keep their old generic presentation.
$cases=array(
	array("UPDATE kbauto_paiement SET fk_paiement=903 WHERE rowid={$f['payment']}","UPDATE kbauto_paiement SET fk_paiement=901 WHERE rowid={$f['payment']}",'Non-transfer receipt mode'),
	array("UPDATE kbauto_bank SET fk_type='CHQ' WHERE rowid={$f['bank']}","UPDATE kbauto_bank SET fk_type='VIR' WHERE rowid={$f['bank']}",'Non-transfer bank operation'),
	array("UPDATE kbauto_paiement SET entity=2 WHERE rowid={$f['payment']}","UPDATE kbauto_paiement SET entity=1 WHERE rowid={$f['payment']}",'Foreign receipt'),
	array("UPDATE kbauto_bank SET fk_account=202 WHERE rowid={$f['bank']}","UPDATE kbauto_bank SET fk_account=101 WHERE rowid={$f['bank']}",'Foreign-company native bank'),
	array("UPDATE kbauto_facture SET entity=2 WHERE rowid={$f['invoice1']}","UPDATE kbauto_facture SET entity=1 WHERE rowid={$f['invoice1']}",'Foreign invoice'),
	array("UPDATE kbauto_paiement_facture SET fk_facture=99999999 WHERE rowid={$f['allocation1']}","UPDATE kbauto_paiement_facture SET fk_facture={$f['invoice1']} WHERE rowid={$f['allocation1']}",'Orphan allocation'),
	array("UPDATE kbauto_facture SET type=2 WHERE rowid={$f['invoice1']}","UPDATE kbauto_facture SET type=0 WHERE rowid={$f['invoice1']}",'Credit-note allocation'),
	array("UPDATE kbauto_facture SET fk_statut=0 WHERE rowid={$f['invoice1']}","UPDATE kbauto_facture SET fk_statut=2 WHERE rowid={$f['invoice1']}",'Draft invoice'),
	array("UPDATE kbauto_facture SET fk_soc=860002 WHERE rowid={$f['invoice1']}","UPDATE kbauto_facture SET fk_soc=860001 WHERE rowid={$f['invoice1']}",'Mixed customer allocations'),
	array("UPDATE kbauto_societe SET entity=2 WHERE rowid=860001","UPDATE kbauto_societe SET entity=1 WHERE rowid=860001",'Foreign customer'),
	array("UPDATE kbauto_facture SET multicurrency_code='USD' WHERE rowid={$f['invoice1']}","UPDATE kbauto_facture SET multicurrency_code='EUR' WHERE rowid={$f['invoice1']}",'Foreign invoice currency'),
	array("UPDATE kbauto_paiement_facture SET multicurrency_code='USD' WHERE rowid={$f['allocation1']}","UPDATE kbauto_paiement_facture SET multicurrency_code='EUR' WHERE rowid={$f['allocation1']}",'Foreign allocation currency'),
	array("UPDATE kbauto_paiement_facture SET amount=100.10 WHERE rowid={$f['allocation1']}","UPDATE kbauto_paiement_facture SET amount=100.11 WHERE rowid={$f['allocation1']}",'Incomplete receipt allocation'),
	array("UPDATE kbauto_paiement_facture SET amount=0 WHERE rowid={$f['allocation1']}","UPDATE kbauto_paiement_facture SET amount=100.11 WHERE rowid={$f['allocation1']}",'Zero allocation'),
	array("UPDATE kbauto_paiement SET amount=-379.42 WHERE rowid={$f['payment']}","UPDATE kbauto_paiement SET amount=379.42 WHERE rowid={$f['payment']}",'Negative receipt'),
	array("UPDATE kbauto_bank SET amount=-379.42 WHERE rowid={$f['bank']}","UPDATE kbauto_bank SET amount=379.42 WHERE rowid={$f['bank']}",'Negative native movement'),
	array("UPDATE kbauto_paiement SET amount=379.43 WHERE rowid={$f['payment']}","UPDATE kbauto_paiement SET amount=379.42 WHERE rowid={$f['payment']}",'Receipt/native amount mismatch'),
	array("UPDATE kbauto_bank_url SET url_id=99999999 WHERE fk_bank={$f['bank']}","UPDATE kbauto_bank_url SET url_id={$f['payment']} WHERE fk_bank={$f['bank']}",'Mismatched native receipt link'),
	array("UPDATE kbauto_paiement SET fk_paiement=904 WHERE rowid={$f['payment']}","UPDATE kbauto_paiement SET fk_paiement=901 WHERE rowid={$f['payment']}",'Foreign payment dictionary')
);
foreach($cases as$case){$db->query($case[0]);checkAuto(customerTransferMeta($f['bank'])===array(),$case[2].' cannot enrich a native movement');$db->query($case[1]);}
$db->query("UPDATE kbauto_paiement_facture SET amount=CASE rowid WHEN {$f['allocation1']} THEN -1 ELSE 380.42 END WHERE fk_paiement={$f['payment']}");
checkAuto(customerTransferMeta($f['bank'])===array(),'Balanced positive/negative allocations cannot masquerade as full positive receipt allocation');
$db->query("UPDATE kbauto_paiement_facture SET amount=CASE rowid WHEN {$f['allocation1']} THEN 100.11 ELSE 279.31 END WHERE fk_paiement={$f['payment']}");
$db->query("UPDATE kbauto_paiement_facture SET fk_facture={$f['invoice1']} WHERE rowid={$f['allocation2']}");
checkAuto(customerTransferMeta($f['bank'])===array(),'Repeated allocations to one invoice do not fabricate a multi-invoice receipt');
$db->query("UPDATE kbauto_paiement_facture SET fk_facture={$f['invoice2']} WHERE rowid={$f['allocation2']}");
$db->query("INSERT INTO kbauto_paiement(rowid,entity,ref,datep,amount,fk_bank,fk_paiement) VALUES(849999,1,'RC-EXTRA','2031-04-10',379.42,{$f['bank']},901)");
checkAuto(customerTransferMeta($f['bank'])===array(),'Multiple receipts attached to one bank movement require review');
$db->query('DELETE FROM kbauto_paiement WHERE rowid=849999');
$db->query("UPDATE kbauto_bank SET fk_type='TRF' WHERE rowid={$f['bank']}");$db->query("UPDATE kbauto_paiement SET fk_paiement=902 WHERE rowid={$f['payment']}");
checkAuto(customerTransferMeta($f['bank'])[$f['bank']]['mode_code']==='TRF','Recorded native TRF transfers retain the same evidence contract as VIR');
$db->query("UPDATE kbauto_bank SET fk_type='VIR' WHERE rowid={$f['bank']}");$db->query("UPDATE kbauto_paiement SET fk_paiement=901 WHERE rowid={$f['payment']}");

$limited=clone $user;$limited->denied=array('societe.client.voir');
checkAuto(customerTransferMeta($f['bank'],$limited)===array(),'Customer-restricted readers cannot discover unassigned customer receipt facts');
$db->query('INSERT INTO kbauto_societe_commerciaux(fk_soc,fk_user) VALUES(860001,7)');
checkAuto(isset(customerTransferMeta($f['bank'],$limited)[$f['bank']]),'Native sales-representative ownership permits the same existing receipt');
$db->query('DELETE FROM kbauto_societe_commerciaux WHERE fk_soc=860001 AND fk_user=7');

$wrong=customerTransferFixture(841000,'451.73',102);$worklist=new KreaBankWorklist($db,1,$service);$wrongPrepared=$worklist->prepare('staged:'.$wrong['source']);
checkAuto(!empty($wrongPrepared['account_review']) && $wrongPrepared['pair']===null
	&& $wrongPrepared['account_candidates'][0]['bank_line_id']===$wrong['bank'],'A recorded customer transfer in another owned bank remains a warning without an executable match');
rejectAuto(static function()use($service,$wrong){$service->prepareWorklistSelectionPair('staged:'.$wrong['source'],$wrong['bank']);},'The selector cannot bypass wrong-bank customer-transfer review');

// A changed receipt or allocation is rejected again under native execution locks.
$row=customerTransferPair($f['source'],$f['bank']);$audits=countAuto('kreabank_recon_audit');
foreach(array("UPDATE kbauto_paiement SET ref='RC-CHANGED' WHERE rowid={$f['payment']}","UPDATE kbauto_paiement_facture SET amount=100.12 WHERE rowid={$f['allocation1']}")as$change){
	$db->beforeBegin=static function()use($db,$change){$db->query($change);};
	rejectAuto(static function()use($row){customerTransferSignedApply($row);},'A signed native pair cannot reuse changed receipt/allocation facts');
	checkAuto((int)$db->query("SELECT rappro FROM kbauto_bank WHERE rowid={$f['bank']}")->fetch_object()->rappro===0 && countAuto('kreabank_recon_audit')===$audits,'Changed evidence leaves native/source reconciliation and audit untouched');
	$db->query("UPDATE kbauto_paiement SET ref='{$f['reference']}' WHERE rowid={$f['payment']}");$db->query("UPDATE kbauto_paiement_facture SET amount=100.11 WHERE rowid={$f['allocation1']}");
	$row=customerTransferPair($f['source'],$f['bank']);
}
$mismatch=customerTransferFixture(842000,'379.43');
// Keep this bank/receipt coherent at379.43 while its imported source differs by one cent.
$db->query("UPDATE kbauto_kreabank_statement_line SET amount=379.42 WHERE rowid={$mismatch['source']}");
$mismatchLine=$service->getBulkReviewLine($mismatch['source'],'staged');
$mismatchDoc=$service->nativeCandidate($mismatch['bank'])[0];
$mismatchSuggestion=(new KreaBankMatcher())->getSuggestions($mismatchLine,array($mismatchDoc),array(),3,0)[0];
checkAuto($mismatchSuggestion['score']===0,'Even a one-cent difference receives no enriched recorded-transfer match');
$mismatchRow=array('line'=>$mismatchLine,'suggestion'=>$mismatchSuggestion,'allocation'=>379.42,'approval_context'=>'selected_native','other_account_warning'=>false);
rejectAuto(static function()use($mismatchRow){customerTransferSignedApply($mismatchRow);},'Signed manual execution also rejects exact source/native receipt mismatch');
checkAuto(empty($service->getBulkReviewLine($mismatch['source'],'staged')['native_bank_line_id']),'A mismatched recorded receipt is never linked to the imported source');

$row=customerTransferPair($f['source'],$f['bank']);$expires=time()+900;$secret=str_repeat('synthetic-review-',4);$signature=kreabankBulkReviewSignature($row,'1|7|101',$expires,$secret);
checkAuto(kreabankBulkReviewIsValid($row,'1|7|101',$expires,$signature,$secret,time(),true,101),'Actual DB receipt facts are bound to a valid actor/company/account signed review');
$tampered=$row;$tampered['suggestion']['customer_transfer']['facts_hash']=str_repeat('0',64);
checkAuto(!kreabankBulkReviewIsValid($tampered,'1|7|101',$expires,$signature,$secret,time(),true,101),'A replaced persisted-allocation fingerprint cannot reuse the displayed signature');
$financial=customerTransferFinancialState();$beforeCounts=array(countAuto('bank'),countAuto('paiement'),countAuto('paiement_facture'),countAuto('kreabank_payment_batch'));
checkAuto(customerTransferSignedApply($row)===$f['source'],'Signed manual reconciliation reuses the already recorded receipt/native movement');
$completed=$service->getBulkReviewLine($f['source'],'staged');
checkAuto((int)$completed['status']===2 && (int)$completed['native_bank_line_id']===$f['bank'] && (int)$db->query("SELECT rappro FROM kbauto_bank WHERE rowid={$f['bank']}")->fetch_object()->rappro===1,'The original staged source maps to the same existing reconciled native bank line');
checkAuto(customerTransferFinancialState()===$financial && $beforeCounts===array(countAuto('bank'),countAuto('paiement'),countAuto('paiement_facture'),countAuto('kreabank_payment_batch')),'Receipt, invoice allocations, native financial bank facts and all counts remain unchanged; no new receipt, movement or group');
checkAuto(countAuto('kreabank_recon_audit')===$audits+1,'The existing reconciliation path records exactly one reconciliation audit');
$after=customerTransferFinancialState();$auditCount=countAuto('kreabank_recon_audit');
rejectAuto(static function()use($row){customerTransferSignedApply($row);},'A repeated signed transfer approval cannot reconcile twice');
checkAuto(customerTransferFinancialState()===$after && countAuto('kreabank_recon_audit')===$auditCount,'Retries create no duplicate payment, bank movement, allocation, group or audit');
echo 'OK: '.($checks-$initialCustomerChecks)." customer-transfer database checks (recorded native receipt only)\n";
