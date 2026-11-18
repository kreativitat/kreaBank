#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 * ONLY disposable 127.0.0.1:33317 / kreabank_auto_test; never an installation database.
 */
require __DIR__ . '/test_account_pairs_database.php';
$initialChecks=$checks;
foreach(array(3104,3105,3106)as$id){$db->query("INSERT INTO kbauto_bank_account(rowid,entity,ref,label,currency_code,clos,courant,rappro,fk_pays) VALUES($id,1,'OWN-$id','Synthetic owned bank','EUR',0,1,1,1)");}
dolibarr_set_const($db,'MAIN_INFO_SOCIETE_NOM','TEST01 - Example Trading Lda','chaine',0,'',1);
function ownSettings($mode='enabled',$accounts='3104,3105'){
	global$db;settingAuto($mode,$accounts);
	dolibarr_set_const($db,'KREABANK_AUTO_NATIVE_OWN_TRANSFERS_ENABLED','1','chaine',0,'',1);
}
function ownStage($id,$amount,$account,$description=''){
	global$db;
	stageAuto($id,$amount,'',$account);
	if($description===''){$description=$amount[0]==='-'?'TRF IMEDIATA SEPA+ Online 123456789 PARA EXAMPLE TRADING LDA':'TRF.IMED. DE EXAMPLE TRADING, LDA-R12345678';}
	$db->query("UPDATE kbauto_kreabank_statement_line SET description='".$db->escape($description)."' WHERE rowid=$id");
}
function ownPair($id,$amount){ownStage($id,'-'.$amount,3104);ownStage($id+1,$amount,3105);}
function ownNativePair($left,$right,$amount,$leftAccount=3104,$rightAccount=3105){
	global$db;bankAuto($left,'-'.$amount,'',$leftAccount);bankAuto($right,$amount,'',$rightAccount);
	$db->query("INSERT INTO kbauto_bank_url(fk_bank,url_id,url,label,type) VALUES($left,$right,'/compta/bank/line.php?rowid=','(banktransfert)','banktransfert'),($right,$left,'/compta/bank/line.php?rowid=','(banktransfert)','banktransfert')");
}
ownSettings();ownPair(900000,'751.23000000');
$beforeBank=countAuto('bank');$beforeAudit=countAuto('kreabank_recon_audit');
$match=$service->getAutomaticOwnTransferSuggestion(900000,array(3104,3105));
checkAuto($match&&$match['created_transfer']&&$match['bank_ids']===array(0,0),'Two direction-bound owned identities propose one ordinary transfer');
checkAuto(countAuto('bank')===$beforeBank&&countAuto('kreabank_recon_audit')===$beforeAudit,'Owned transfer preview writes no financial record');
foreach(array('INSERT INTO kbauto_bank_url','UPDATE kbauto_kreabank_statement_line','INSERT INTO kbauto_kreabank_recon_audit')as$failure){
	$db->fail=$failure;
	rejectAuto(static function()use($service){$service->reconcileAutomaticOwnTransfer(900000,array(3104,3105));},'Owned transfer failure rolls back: '.$failure);
	$db->fail='';
	checkAuto(countAuto('bank')===$beforeBank&&countAuto('kreabank_recon_audit')===$beforeAudit&&$service->getBulkReviewLine(900000,'staged')['status']===0&&$db->depth===0,'No created leg, mapping or audit survives failed pair');
}
checkAuto($service->reconcileAutomaticOwnTransfer(900000,array(3104,3105))===2,'Both ordinary transfer legs complete atomically');
checkAuto(countAuto('bank')===$beforeBank+2&&countAuto('kreabank_recon_audit')===$beforeAudit+2,'Exactly two movements and two audits created');
$created=$db->query('SELECT b.rowid,b.fk_type FROM kbauto_bank b JOIN kbauto_kreabank_statement_line l ON l.fk_native_bank_line=b.rowid WHERE l.rowid IN(900000,900001)')->fetch_all(MYSQLI_ASSOC);
checkAuto(count($created)===2&&$created[0]['fk_type']==='VIR'&&$created[1]['fk_type']==='VIR','Created movements use explicit native VIR');
checkAuto($service->reconcileAutomaticOwnTransfer(900001,array(3104,3105))===0&&countAuto('bank')===$beforeBank+2,'Opposite-side retry cannot duplicate transfer');
$audit=json_decode($db->query('SELECT payload_json FROM kbauto_kreabank_recon_audit WHERE fk_statement_line=900000 ORDER BY rowid DESC LIMIT 1')->fetch_object()->payload_json,true);
checkAuto($audit['strategy']==='auto_transfer'&&$audit['source']==='staged'&&$audit['paired_statement_line_id']===900001&&$audit['created_transfer'],'Audit binds exact source/native pair and creation');

ownPair(900010,'752.23');ownNativePair(901010,901011,'752.23');
$beforeBank=countAuto('bank');
checkAuto($service->reconcileAutomaticOwnTransfer(900010,array(3104,3105))===2&&countAuto('bank')===$beforeBank,'Existing complete reciprocal native transfer is reused despite generic native labels');
checkAuto($service->getBulkReviewLine(900011,'staged')['status']===2,'Both imported sources bind their existing native leg');

ownPair(900020,'753.23');ownNativePair(901020,901021,'753.23');
$db->query("UPDATE kbauto_bank SET rappro=1,num_releve='ST-900021' WHERE rowid=901021");
$db->query('UPDATE kbauto_kreabank_statement_line SET status=2,allocated_amount=753.23,fk_native_bank_line=901021 WHERE rowid=900021');
$beforeBank=countAuto('bank');$beforeAudit=countAuto('kreabank_recon_audit');
checkAuto($service->reconcileAutomaticOwnTransfer(900020,array(3104,3105))===1&&countAuto('bank')===$beforeBank,'One pending leg reuses a complete transfer whose peer source is already reconciled');
checkAuto(countAuto('kreabank_recon_audit')===$beforeAudit+1&&$service->getBulkReviewLine(900021,'staged')['status']===2,'Completed peer source/audit is preserved');

ownPair(900030,'754.23');bankAuto(901030,'-754.23','',3104);
$beforeBank=countAuto('bank');
checkAuto($service->getAutomaticOwnTransferSuggestion(900030,array(3104,3105))===null&&$service->reconcileAutomaticOwnTransfer(900030,array(3104,3105))===0&&countAuto('bank')===$beforeBank,'One-sided ledger evidence never creates a replacement or missing leg');
ownPair(900040,'755.23');ownNativePair(901040,901041,'755.23');
$db->query("UPDATE kbauto_kreabank_statement_line SET description='TRF IMEDIATA SEPA+ PARA ANOTHER SUPPLIER LDA' WHERE rowid=900040");
checkAuto($service->getAutomaticOwnTransferSuggestion(900040,array(3104,3105))===null,'An expense debit cannot select an owned transfer by amount/date');
ownPair(900050,'756.23');ownStage(900052,'756.23',3106);
checkAuto($service->getAutomaticOwnTransferSuggestion(900050,array(3104,3105))===null,'An unselected owned account still participates in counterpart ambiguity');
ownPair(900060,'757.23');ownStage(900062,'-757.23',3104);
checkAuto($service->getAutomaticOwnTransferSuggestion(900060,array(3104,3105))===null,'Two same-side source claims remain pending');
ownPair(900070,'758.23');ownNativePair(901070,901071,'758.23',3104,202);
checkAuto($service->getAutomaticOwnTransferSuggestion(900070,array(3104,3105,202))===null,'A reciprocal native link into another company is never owned-transfer authority');
ownPair(900080,'759.23');
$db->query("UPDATE kbauto_kreabank_statement_line SET counterparty_iban='DE89370400440532013000' WHERE rowid=900080");
checkAuto($service->getAutomaticOwnTransferSuggestion(900080,array(3104,3105))===null,'Contradictory source IBAN cannot fall back to company name');
ownPair(900090,'760.23');
$db->query("UPDATE kbauto_kreabank_statement_line SET currency='USD' WHERE rowid=900091");
checkAuto($service->getAutomaticOwnTransferSuggestion(900090,array(3104,3105))===null,'Currency mismatch never creates a transfer');
ownPair(900100,'761.23');ownNativePair(901100,901101,'761.23');
$db->query("INSERT INTO kbauto_bank_url(fk_bank,url_id,url,label,type) VALUES(901100,777,'/payment/','Synthetic payment','payment_supplier')");
checkAuto($service->getAutomaticOwnTransferSuggestion(900100,array(3104,3105))===null,'Invoice/payment-linked native movements cannot be captured as transfers');
ownPair(900110,'762.23');ownNativePair(901110,901111,'762.23');
$db->query("INSERT INTO kbauto_paiementfourn(rowid,entity,ref,datep,amount,fk_bank,fk_paiement) VALUES(901110,1,'SYNTH-PAY',NOW(),762.23,901110,1)");
checkAuto($service->getAutomaticOwnTransferSuggestion(900110,array(3104,3105))===null,'A banked payment blocks reuse even when its bank_url is missing');
ownPair(900120,'763.23');
$db->query("INSERT INTO kbauto_paiement(rowid,entity,ref,datep,amount,fk_paiement) VALUES(901120,1,'SYNTH-UNBANKED','2026-11-01',763.23,1)");
checkAuto($service->getAutomaticOwnTransferSuggestion(900120,array(3104,3105))===null,'Existing unbanked payment blocks new transfer creation');

ownPair(900130,'764.23');ownSettings('preview');
checkAuto($service->getAutomaticOwnTransferSuggestion(900130,array(3104,3105))!==null&&$service->reconcileAutomaticOwnTransfer(900130,array(3104,3105))===0,'Scheduled preview cannot execute owned transfers');
ownSettings();dolibarr_set_const($db,'KREABANK_AUTO_NATIVE_OWN_TRANSFERS_ENABLED','0','chaine',0,'',1);
checkAuto($service->reconcileAutomaticOwnTransfer(900130,array(3104,3105))===0,'Direct execution respects the persisted opt-in flag');
ownSettings('off');checkAuto($service->reconcileAutomaticOwnTransfer(900130,array(3104,3105))===0,'Direct execution respects disabled mode');
ownSettings('enabled','3104');checkAuto($service->reconcileAutomaticOwnTransfer(900130,array(3104,3105))===0,'Caller cannot extend the persisted account allowlist');
ownSettings();$denied=clone$user;$denied->allowed=false;$deniedService=new KreaBankService($db,$denied,$langs);
rejectAuto(static function()use($deniedService){$deniedService->reconcileAutomaticOwnTransfer(900130,array(3104,3105));},'Permission loss denies owned transfers');
$conf->entity=2;rejectAuto(static function()use($service){$service->reconcileAutomaticOwnTransfer(900130,array(3104,3105));},'Changed company denies old transfer context');$conf->entity=1;

// Two independent native connections serialize on the same owned-account rows.
$db2=new DoliDB();$service2=new KreaBankService($db2,$user,$langs);$db2->query('SET innodb_lock_wait_timeout=1');
$db->begin();$db->query('SELECT rowid FROM kbauto_bank_account WHERE entity=1 ORDER BY rowid FOR UPDATE');
rejectAuto(static function()use($service2){$service2->reconcileAutomaticOwnTransfer(900130,array(3104,3105));},'Concurrent execution cannot pass the held owner-account locks');
$db->rollback();
checkAuto($db2->depth===0&&$service2->reconcileAutomaticOwnTransfer(900130,array(3104,3105))===2&&$service->reconcileAutomaticOwnTransfer(900130,array(3104,3105))===0,'Retry after competing execution creates only one transfer');
// Source claims remain authoritative even when historical scalar state conflicts.
foreach (explode(';', str_replace('llx_', 'kbauto_', preg_replace('/--[^\n]*/', '', file_get_contents(__DIR__.'/../sql/llx_kreabank_payment_batch.sql')))) as $sql) {
	if (trim($sql) !== '') { $db->query($sql); }
}
function ownBatchClaim($id) {
	global $db;
	$db->query("INSERT INTO kbauto_kreabank_payment_batch(entity,fk_statement_line,active_statement_line,bank_account_id,amount,currency,operation_date,statement_ref,name,source_snapshot,fk_user,datec) SELECT entity,rowid,rowid,3104,amount,currency,operation_date,'synthetic','Synthetic claim','{}',7,NOW() FROM kbauto_kreabank_statement_line WHERE rowid=$id");
}
ownPair(900140,'765.23');ownBatchClaim(900140);
checkAuto($service->getAutomaticOwnTransferSuggestion(900140,array(3104,3105))===null,'Active batch source cannot be captured by ordinary transfers');
ownPair(900150,'766.23');ownNativePair(901150,901151,'766.23');
$db->query("UPDATE kbauto_bank SET rappro=1,num_releve='ST-900151' WHERE rowid=901151");
$db->query('UPDATE kbauto_kreabank_statement_line SET status=2,allocated_amount=766.23,fk_native_bank_line=901151 WHERE rowid=900151');
ownBatchClaim(900151);
checkAuto($service->getAutomaticOwnTransferSuggestion(900150,array(3104,3105))===null,'Completed peer with an active batch source claim remains manual');

ownPair(900160,'767.23');
$db->query('UPDATE kbauto_kreabank_statement_line SET value_date=NULL WHERE rowid IN(900160,900161)');
checkAuto($service->reconcileAutomaticOwnTransfer(900160,array(3104,3105))===2,'Historical imports with no value date use their booking day');
$wrongDates=(int)$db->query("SELECT COUNT(*) n FROM kbauto_bank b JOIN kbauto_kreabank_statement_line l ON l.fk_native_bank_line=b.rowid WHERE l.rowid IN(900160,900161) AND DATE(b.datev)<>l.operation_date")->fetch_object()->n;
checkAuto($wrongDates===0,'Both missing value dates retain the historical booking day');
ownPair(900170,'768.23');
$db->query("UPDATE kbauto_kreabank_statement_line SET value_date='0000-00-00' WHERE rowid=900170");
checkAuto($service->getAutomaticOwnTransferSuggestion(900170,array(3104,3105))===null&&$service->reconcileAutomaticOwnTransfer(900170,array(3104,3105))===0,'Supplied invalid value dates fail closed instead of using today');

ownPair(900180,'769.23');ownSettings('off');
checkAuto($service->getAutomaticOwnTransferSuggestion(900180,array(3104,3105))!==null&&$service->reconcileAutomaticOwnTransfer(900180,array(3104,3105))===0,'Off mode supports read-only preview but cannot execute');
ownSettings();
dolibarr_set_const($db,'MAIN_INFO_SOCIETE_NOM','TEST02 - Example Trading S.A.','chaine',0,'',2);
checkAuto($service->getAutomaticOwnTransferSuggestion(900180,array(3104,3105))===null,'Another company with the same canonical legal name blocks name-only proof');
$db->query("UPDATE kbauto_bank_account SET iban_prefix=CASE rowid WHEN 3104 THEN 'NL91ABNA0417164300' ELSE 'GB82WEST12345698765432' END WHERE rowid IN(3104,3105)");
$db->query("UPDATE kbauto_kreabank_statement_line SET counterparty_iban=CASE rowid WHEN 900180 THEN 'GB82WEST12345698765432' ELSE 'NL91ABNA0417164300' END WHERE rowid IN(900180,900181)");
checkAuto($service->getAutomaticOwnTransferSuggestion(900180,array(3104,3105))!==null,'Exact owned counterparty IBANs remain valid when company names collide');
$db->query("DELETE FROM kbauto_const WHERE entity=2 AND name='MAIN_INFO_SOCIETE_NOM'");
ownPair(900190,'770.23');bankAuto(901190,'-770.23','',3104,'2026-11-04 18:00:00');
checkAuto($service->getAutomaticOwnTransferSuggestion(900190,array(3104,3105))===null,'Possible native evidence late on the final tolerance day blocks duplicate creation');
// A native request retains the company lock between its duplicate preflight and commit.
require_once __DIR__.'/../class/KreaBankTransferGuard.class.php';
ownPair(900200,'771.23');
$nativeLock=new KreaBankReconciliationLock($db2,1);$nativeLock->acquire();
$nativeRequest=array(array('from'=>3104,'to'=>3105,'amount'=>'771.23','amount_to'=>'','date'=>strtotime('2026-11-01 UTC')));
checkAuto((new KreaBankTransferGuard($db2,1))->existingMovements($nativeRequest,3)===array(),'Native preflight sees no prior transfer while retaining its request lock');
rejectAuto(static function()use($service){$service->reconcileAutomaticOwnTransfer(900200,array(3104,3105));},'Automatic creation cannot pass a native request after its preflight');
$db2->begin();
$db2->query("INSERT INTO kbauto_bank(rowid,fk_account,datec,dateo,datev,amount,label,num_chq,rappro) VALUES(901200,3104,NOW(),'2026-11-01','2026-11-01',-771.23,'Synthetic native transfer','',0),(901201,3105,NOW(),'2026-11-01','2026-11-01',771.23,'Synthetic native transfer','',0)");
$db2->query("INSERT INTO kbauto_bank_url(fk_bank,url_id,url,label,type) VALUES(901200,901201,'/compta/bank/line.php?rowid=','(banktransfert)','banktransfert'),(901201,901200,'/compta/bank/line.php?rowid=','(banktransfert)','banktransfert')");
$db2->commit();$nativeLock->release();$beforeBank=countAuto('bank');
$afterNative=$service->getAutomaticOwnTransferSuggestion(900200,array(3104,3105));
checkAuto($afterNative&&!$afterNative['created_transfer']&&$service->reconcileAutomaticOwnTransfer(900200,array(3104,3105))===2&&countAuto('bank')===$beforeBank,'After native commit automation reuses both legs without creating duplicates');
ownPair(900210,'772.23');
dolibarr_set_const($db,'MAIN_INFO_SOCIETE_NOM','Example Trading Lda','chaine',0,'',0);
$db->query("DELETE FROM kbauto_const WHERE entity=1 AND name='MAIN_INFO_SOCIETE_NOM'");
checkAuto($service->getAutomaticOwnTransferSuggestion(900210,array(3104,3105))===null,'A global default legal name cannot establish the active company identity');
dolibarr_set_const($db,'MAIN_INFO_SOCIETE_NOM','TEST01 - Example Trading Lda','chaine',0,'',1);
echo 'OK: '.($checks-$initialChecks)." ordinary owned-transfer database checks\n";
