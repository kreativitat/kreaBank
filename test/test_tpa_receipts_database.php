#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 * ONLY the disposable 127.0.0.1:33317/kreabank_auto_test fixture, never installation configuration.
 */
require __DIR__ . '/test_account_pairs_database.php';
$initialChecks = $checks;
$sql = preg_replace('/--[^\n]*/', '', file_get_contents(DOL_DOCUMENT_ROOT . '/install/mysql/tables/llx_c_paiement.sql'));
foreach (explode(';', str_replace('llx_', MAIN_DB_PREFIX, $sql)) as $statement) { if (trim($statement) !== '') { $db->query($statement); } }
$db->query("INSERT INTO kbauto_c_paiement(id,entity,code,libelle,type,active) VALUES(1,1,'CB','Card',2,1),(2,1,'MB','Multibanco',2,1),(3,2,'OTHER','Other company mode',2,1),(4,1,'CDB','Inactive card',2,0)");

function tpaFixture($id, $amount = '781.23', $account = 101, $entity = 1, $source = 'csv', $description = null) {
 global $db;
 stageAuto($id, $amount, '', $account, $entity, $source);
 $description = $description ?? ('FECHO TPA 0012345678 PERIODO N ' . ($id - 60000));
 $db->query("UPDATE kbauto_kreabank_statement_line SET description='" . $db->escape($description) . "' WHERE rowid=$id");
}
// Isolate the scheduler queue without changing completed audit/native fixtures.
$db->query('UPDATE kbauto_kreabank_statement_line SET status=3 WHERE status=0');
settingAuto('enabled','101,102,103','0');
dolibarr_set_const($db,'KREABANK_AUTO_NATIVE_PAIRS_ENABLED','0','chaine',0,'',1);
tpaFixture(60001);
checkAuto($service->getAutomaticTpaSuggestion(60001,array(101)) === null, 'TPA receipt creation defaults off');
$jobCount=countAuto('cronjob');
rejectAuto(static function () use($auto,$user) { $auto->configure($user,'enabled',array(101,102,103),false,true); },'TPA enablement requires an explicitly selected active native payment mode');
$auto->configure($user,'enabled',array(101,102,103),false,true,'CB');
checkAuto($auto->setting('TPA_ENABLED')==='1' && countAuto('cronjob')===$jobCount,'Opt-in uses the existing company job without another scheduler');
checkAuto($service->getAutomaticTpaSuggestion(60001,array(101)) !== null, 'Recognized full settlement identity is eligible after explicit opt-in');
checkAuto($service->getAutomaticTpaSuggestion(60001,array(102)) === null, 'Call account allowlist is enforced');
$db->query("UPDATE kbauto_const SET value='102' WHERE name='KREABANK_AUTO_NATIVE_ACCOUNTS' AND entity=1");
checkAuto($service->getAutomaticTpaSuggestion(60001,array(101)) === null, 'Saved company account selection cannot be bypassed by a caller');
$db->query("UPDATE kbauto_const SET value='101,102,103' WHERE name='KREABANK_AUTO_NATIVE_ACCOUNTS' AND entity=1");
$bankCount=countAuto('bank'); $auditCount=countAuto('kreabank_recon_audit'); $metaCount=countAuto('kreabank_bankmeta');
$preview=$auto->process($user,true);
checkAuto($preview['eligible']===1 && !empty($preview['matches'][0]['tpa_receipt']) && $preview['reconciled']===0,
 'Existing scheduler previews a new card receipt explicitly');
checkAuto(countAuto('bank')===$bankCount && countAuto('kreabank_recon_audit')===$auditCount && $auto->setting('CURSOR')==='0',
 'Preview is read-only and preserves the scan cursor');
settingAuto('preview','101,102,103');
checkAuto(!$service->reconcileAutomaticTpaReceipt(60001,array(101)) && $auto->process($user,false)['reconciled']===0,
 'Preview mode prevents receipt creation through both service and scheduler');
settingAuto('enabled','101,102,103');
foreach (array('INSERT INTO kbauto_bank ', 'INSERT INTO kbauto_kreabank_bankmeta', 'UPDATE kbauto_kreabank_statement_line', 'UPDATE kbauto_bank SET', 'INSERT INTO kbauto_kreabank_recon_audit') as $failure) {
 $db->fail=$failure;
 rejectAuto(static function () use($service) { $service->reconcileAutomaticTpaReceipt(60001,array(101)); }, 'Write failure is visible: '.$failure);
 $db->fail='';
 checkAuto(countAuto('bank')===$bankCount && countAuto('kreabank_bankmeta')===$metaCount && countAuto('kreabank_recon_audit')===$auditCount
  && $service->getBulkReviewLine(60001,'staged')['status']===0 && $db->depth===0, 'Receipt, metadata, mapping, flags and audit roll back together');
}
$result=$auto->process($user,false);
checkAuto($result['reconciled']===1 && $result['failed']===0 && countAuto('bank')===$bankCount+1, 'Scheduled run creates exactly one native receipt');
$line=$service->getBulkReviewLine(60001,'staged'); $nativeId=(int)$line['native_bank_line_id'];
$native=$db->query('SELECT * FROM kbauto_bank WHERE rowid='.$nativeId)->fetch_object();
checkAuto($native->fk_type==='CB' && $native->amount==='781.23000000' && $native->rappro==='1'
 && substr($native->dateo,0,10)==='2026-11-01' && $line['status']===2 && (int)$native->fk_account===101,
 'Native card operation, original amount/date, account and reconciliation flags are correct');
$audit=json_decode($db->query('SELECT payload_json FROM kbauto_kreabank_recon_audit ORDER BY rowid DESC LIMIT 1')->fetch_object()->payload_json,true);
checkAuto($audit['strategy']==='auto_tpa' && $audit['is_auto']===1 && $audit['native_bank_line_id']===$nativeId
 && $audit['terminal_settlement']==='0012345678/1', 'Atomic audit records the source settlement identity and native receipt');
$bankCount=countAuto('bank');
checkAuto(!$service->reconcileAutomaticTpaReceipt(60001,array(101)) && countAuto('bank')===$bankCount, 'Retry cannot create a second completed receipt');
tpaFixture(60002,'781.24',101,1,'csv','FECHO TPA 0012345678 PERIODO N 1');
checkAuto($service->getAutomaticTpaSuggestion(60002,array(101))!==null, 'A complete audited different-amount credit explains a repeated printed counter');
tpaFixture(60003,'782.23'); bankAuto(66003,'782.23','',101);
checkAuto($service->getAutomaticTpaSuggestion(60003,array(101))===null, 'Possible existing equal-amount ledger entry blocks creation');
$db->query('UPDATE kbauto_bank SET rappro=1 WHERE rowid=66003');
checkAuto($service->getAutomaticTpaSuggestion(60003,array(101))===null, 'Already reconciled possible movement also blocks duplication');
$db->query('UPDATE kbauto_bank SET amount=0.01 WHERE rowid=66003');
$db->query("UPDATE kbauto_bank SET label='FECHO TPA 0012345678 PERIODO N 3',dateo='2026-11-01' WHERE rowid=66003");
checkAuto($service->getAutomaticTpaSuggestion(60003,array(101))===null, 'Same native settlement identity within date tolerance blocks conflicting amounts');
tpaFixture(60004,'783.23'); tpaFixture(60005,'783.24',101,1,'csv','FECHO TPA 0012345678 PERIODO N 4');
checkAuto($service->getAutomaticTpaSuggestion(60004,array(101))!==null && $service->getAutomaticTpaSuggestion(60005,array(101))!==null,
 'Different exact staged credit amounts remain distinct despite the same printed counter');
$beforeDistinct=countAuto('bank');
checkAuto($service->reconcileAutomaticTpaReceipt(60004,array(101)) && $service->reconcileAutomaticTpaReceipt(60005,array(101))
 && countAuto('bank')===$beforeDistinct+2, 'Both different-amount credits post once, with exact audited source mappings');
tpaFixture(60006,'-784.23');
checkAuto($service->getAutomaticTpaSuggestion(60006,array(101))===null, 'Debits never become card receipts');
tpaFixture(60007,'785.23',102);
checkAuto($service->getAutomaticTpaSuggestion(60007,array(102))===null, 'Restricted transfer-only account rejects receipts');
tpaFixture(60008,'786.23',202,2);
checkAuto($service->getAutomaticTpaSuggestion(60008,array(202))===null, 'Foreign-company staged row is excluded');
tpaFixture(60009,'787.23',101,1,'csv','TPA CARD PAYMENT');
checkAuto($service->getAutomaticTpaSuggestion(60009,array(101))===null, 'Generic TPA word cannot authorize financial writes');
tpaFixture(60010,'788.23');
$db->query("UPDATE kbauto_kreabank_statement_line SET currency='USD' WHERE rowid=60010");
checkAuto($service->getAutomaticTpaSuggestion(60010,array(101))===null, 'Currency mismatch is excluded');
tpaFixture(60011,'789.23',101,1,'fintable');
checkAuto($service->getAutomaticTpaSuggestion(60011,array(101))===null, 'Fintable receipts require an independently imported source identity');
$db->query("INSERT INTO kbauto_kreabank_feed_transaction(entity,fk_feed_account,provider_transaction_id,source_uid,financial_hash,content_hash,fk_statement_line,source_payload,status,datec) VALUES(1,1,'tpa_fixture','fixture-60011',SHA2('789.23|2026-11-01|EUR',256),SHA2('tpa-b',256),60011,'{}','imported',NOW())");
checkAuto($service->getAutomaticTpaSuggestion(60011,array(101))!==null, 'Valid Fintable source uses the same TPA path');
$db->query("UPDATE kbauto_kreabank_feed_transaction SET conflict_payload='{}' WHERE fk_statement_line=60011");
checkAuto($service->getAutomaticTpaSuggestion(60011,array(101))===null, 'Provider source conflicts remain pending');
tpaFixture(60012,'790.23');
$db->query("INSERT INTO kbauto_paiement(rowid,entity,ref,datec,datep,amount,fk_bank,fk_paiement) VALUES(66012,1,'TEST-UNBANKED',NOW(),'2026-11-01',790.23,0,1)");
checkAuto($service->getAutomaticTpaSuggestion(60012,array(101))===null, 'Possible existing unbanked customer payment requires allocation review');
tpaFixture(60013,'791.23');
$db->beforeBegin=static function () use($db) { bankAuto(66013,'791.23','',101); };
checkAuto(!$service->reconcileAutomaticTpaReceipt(60013,array(101)), 'New native evidence after preview is rechecked before creation');
tpaFixture(60014,'792.23');
$bad=clone $user; $bad->allowed=false; $deniedService=new KreaBankService($db,$bad,$langs);
rejectAuto(static function () use($deniedService) { $deniedService->reconcileAutomaticTpaReceipt(60014,array(101)); }, 'Permission loss stops creation');
$conf->entity=2;
rejectAuto(static function () use($service) { $service->getAutomaticTpaSuggestion(60014,array(101)); }, 'Company context switch invalidates the service');
$conf->entity=1;
$otherDb=new DoliDB(); $otherDb->query('SET SESSION innodb_lock_wait_timeout=1');
$otherService=new KreaBankService($otherDb,$user,$langs);
$db->begin(); $db->query('SELECT rowid FROM kbauto_bank_account WHERE entity=1 AND rowid=101 FOR UPDATE');
rejectAuto(static function () use($otherService) { $otherService->reconcileAutomaticTpaReceipt(60014,array(101)); }, 'Concurrent connection cannot bypass the account execution lock');
$db->rollback(); $bankCount=countAuto('bank');
checkAuto($otherService->reconcileAutomaticTpaReceipt(60014,array(101)) && !$service->reconcileAutomaticTpaReceipt(60014,array(101))
 && countAuto('bank')===$bankCount+1, 'Concurrent retries create one receipt after lock release');
// Existing safe matching retains precedence and creates no second receipt.
tpaFixture(60015,'793.23'); bankAuto(66015,'793.23','FECHO TPA 0012345678 PERIODO N 15');
checkAuto($service->getAutomaticNativeSuggestion(60015,array(101))!==null && $service->getAutomaticTpaSuggestion(60015,array(101))['bank_line_id']===66015,
 'Both safe paths identify the same proven existing native movement for reuse');
checkAuto($service->reconcileAutomaticNativeLine(60015,array(101)), 'Existing movement is reused instead of creating a receipt');
// Bank terminal counters repeat and leading zeroes distinguish independent settlement streams.
tpaFixture(60020,'798.23',101,1,'csv','FECHO TPA 0012345678 PERIODO N 1');
$db->query("UPDATE kbauto_kreabank_statement_line SET operation_date='2026-11-20',value_date='2026-11-20' WHERE rowid=60020");
checkAuto($service->getAutomaticTpaSuggestion(60020,array(101))!==null,'Repeated terminal counter on a later booking day is a distinct settlement');
tpaFixture(60021,'799.23',101,1,'csv','FECHO TPA 012345678 PERIODO N 1');
checkAuto($service->getAutomaticTpaSuggestion(60021,array(101))!==null,'Printed terminal leading zeroes distinguish independent settlement streams');
tpaFixture(60022,'800.23',101,1,'csv','FECHO TPA 0123456/0001234567/007 SHOP');
tpaFixture(60023,'801.23',101,1,'csv','FECHO TPA 0123456/0001234567/007 CITY');
$db->query("UPDATE kbauto_kreabank_statement_line SET operation_date='2026-11-20',value_date='2026-11-20' WHERE rowid=60023");
checkAuto($service->getAutomaticTpaSuggestion(60022,array(101))!==null && $service->getAutomaticTpaSuggestion(60023,array(101))!==null,
 'Merchant-terminal-batch counters may recur across booking dates without being duplicate evidence');
// Recheck mutable source flags and company-currency policy through the actual service.
tpaFixture(60016,'794.23');
foreach (array('is_duplicate=1','allocated_amount=1','status=3','direction=-1','fk_native_bank_line=999999') as $change) {
 $db->query('UPDATE kbauto_kreabank_statement_line SET '.$change.' WHERE rowid=60016');
 checkAuto($service->getAutomaticTpaSuggestion(60016,array(101))===null,'Changed source remains pending: '.$change);
 $db->query('UPDATE kbauto_kreabank_statement_line SET is_duplicate=0,allocated_amount=0,status=0,direction=1,fk_native_bank_line=NULL WHERE rowid=60016');
}
$db->query("UPDATE kbauto_kreabank_statement_line SET value_date=NULL WHERE rowid=60016");
checkAuto($service->reconcileAutomaticTpaReceipt(60016,array(101)), 'Missing value date uses the source booking date');
$mapped=$service->getBulkReviewLine(60016,'staged');
checkAuto(substr($db->query('SELECT datev FROM kbauto_bank WHERE rowid='.(int)$mapped['native_bank_line_id'])->fetch_object()->datev,0,10)==='2026-11-01', 'Value-date fallback never substitutes the execution date');
tpaFixture(60017,'795.23'); bankAuto(66017,'795.23','',101,'2026-11-04 18:00:00');
checkAuto($service->getAutomaticTpaSuggestion(60017,array(101))===null,'Final tolerance calendar day includes native times after midnight');
tpaFixture(60018,'796.23');
$db->query("INSERT INTO kbauto_paiement(rowid,entity,ref,datec,datep,amount,fk_bank,fk_paiement) VALUES(66018,1,'TEST-BOUNDARY',NOW(),'2026-11-04 18:00:00',796.23,0,1)");
checkAuto($service->getAutomaticTpaSuggestion(60018,array(101))===null,'Final tolerance day includes unbanked payment times');
tpaFixture(60019,'797.23');
for($i=0;$i<1001;$i++) { bankAuto(70000+$i,'0.01','',101); }
checkAuto($service->getAutomaticTpaSuggestion(60019,array(101))===null,'Truncated native search never authorizes receipt creation');
$db->query('DELETE FROM kbauto_bank WHERE rowid BETWEEN 70000 AND 71000');
for($i=0;$i<1001;$i++) { tpaFixture(70000+$i,'0.01',101,1,'csv','FECHO TPA 9876543210 PERIODO N '.($i+1)); }
checkAuto($service->getAutomaticTpaSuggestion(60019,array(101))===null,'Truncated imported identity search never assumes uniqueness');
// A configured native payment code replaces the former unconditional CB operation.
$db->query('DELETE FROM kbauto_kreabank_statement_line WHERE rowid BETWEEN 70000 AND 71000');
tpaFixture(60030,'802.23');
$db->query("UPDATE kbauto_c_paiement SET active=0 WHERE code='CB' AND entity=1");
checkAuto($service->getAutomaticTpaSuggestion(60030,array(101))===null,'A disabled configured payment code prevents unattended receipt creation');
rejectAuto(static function () use($auto,$user) { $auto->configure($user,'enabled',array(101),false,true,'OTHER'); },'Another company payment dictionary cannot authorize a receipt mode');
rejectAuto(static function () use($auto,$user) { $auto->configure($user,'enabled',array(101),false,true,'CDB'); },'An inactive native mode cannot be selected');
$auto->configure($user,'enabled',array(101,102,103),false,true,'MB');
checkAuto($service->getAutomaticTpaSuggestion(60030,array(101))['payment_mode']==='MB','Preview resolves the explicitly selected Multibanco mode');
checkAuto($service->reconcileAutomaticTpaReceipt(60030,array(101)),'Selected Multibanco mode creates the receipt through the same native adapter');
$mapped=$service->getBulkReviewLine(60030,'staged');$bank=$db->query('SELECT fk_type FROM kbauto_bank WHERE rowid='.(int)$mapped['native_bank_line_id'])->fetch_object();
$audit=json_decode($db->query('SELECT payload_json FROM kbauto_kreabank_recon_audit ORDER BY rowid DESC LIMIT 1')->fetch_object()->payload_json,true);
checkAuto($bank->fk_type==='MB' && $audit['operation_code']==='MB','Native receipt and audit use the selected code rather than hardcoded CB');

// Existing unclaimed receipts use the same transactional finalizer without creating a second credit.
tpaFixture(60100,'912.34'); bankAuto(66100,'912.34','FECHO TPA 0012345678 PERIODO N 100');
$before=countAuto('bank'); $beforeAudit=countAuto('kreabank_recon_audit');
checkAuto($service->getAutomaticTpaSuggestion(60100,array(101))['bank_line_id']===66100,'Exact terminal identity and amount identify one unclaimed native receipt');
$db->fail='INSERT INTO kbauto_kreabank_recon_audit';
rejectAuto(static function () use($service) { $service->reconcileAutomaticTpaReceipt(60100,array(101)); },'Reused receipt audit failure is visible');
$db->fail='';
checkAuto(countAuto('bank')===$before && countAuto('kreabank_recon_audit')===$beforeAudit
 && $service->getBulkReviewLine(60100,'staged')['status']===0
 && (int)$db->query('SELECT rappro FROM kbauto_bank WHERE rowid=66100')->fetch_object()->rappro===0,
 'Reused receipt flags, source mapping and audit roll back together');
checkAuto($service->reconcileAutomaticTpaReceipt(60100,array(101)) && countAuto('bank')===$before
 && (int)$service->getBulkReviewLine(60100,'staged')['native_bank_line_id']===66100,'Existing terminal receipt is reused exactly once');
checkAuto(!$service->reconcileAutomaticTpaReceipt(60100,array(101)) && countAuto('bank')===$before,'Repeated reused-receipt execution creates nothing');
tpaFixture(60101,'913.34'); bankAuto(66101,'913.34','FECHO TPA 0012345678 PERIODO N 101');
$db->query("INSERT INTO kbauto_bank_url(fk_bank,url_id,url,label,type) VALUES(66101,123,'/fixture','Fixture payment','payment')");
checkAuto($service->getAutomaticTpaSuggestion(60101,array(101))===null,'An invoice payment movement cannot be captured as an automatic receipt');
tpaFixture(60102,'914.34'); bankAuto(66102,'914.34','FECHO TPA 0012345678 PERIODO N 102');
$db->query('UPDATE kbauto_bank SET rappro=1 WHERE rowid=66102');
checkAuto($service->getAutomaticTpaSuggestion(60102,array(101))===null,'An already reconciled identical receipt never authorizes another credit');
tpaFixture(60103,'915.34'); bankAuto(66103,'915.34','FECHO TPA 0012345678 PERIODO N 103');
tpaFixture(60104,'915.34');
$db->query('UPDATE kbauto_kreabank_statement_line SET fk_native_bank_line=66103 WHERE rowid=60104');
checkAuto($service->getAutomaticTpaSuggestion(60103,array(101))===null,'A conflicting source mapping prevents receipt reuse');
tpaFixture(60105,'921.23'); bankAuto(66105,'921.23','FECHO TPA 0012345678 PERIODO N 9999');
$db->query('UPDATE kbauto_bank SET rappro=1 WHERE rowid=66105');
$before=countAuto('bank');
checkAuto($service->reconcileAutomaticTpaReceipt(60105,array(101)) && countAuto('bank')===$before+1,
 'An equal-amount native receipt with a different complete terminal identity is a distinct credit');
tpaFixture(60106,'922.23'); tpaFixture(60107,'922.23',101,1,'csv','FECHO TPA 0012345678 PERIODO N 106');
$db->query('UPDATE kbauto_kreabank_statement_line SET status=3,is_duplicate=1 WHERE rowid=60107');
checkAuto($service->getAutomaticTpaSuggestion(60106,array(101))===null,'Same-day same-identity same-amount evidence blocks duplication even when skipped or marked duplicate');
tpaFixture(60108,'923.23'); bankAuto(66108,'923.23','FECHO TPA 0012345678 PERIODO N 108');
$db->beforeBegin=static function () use($db) { $db->query('UPDATE kbauto_bank SET rappro=1 WHERE rowid=66108'); };
checkAuto(!$service->reconcileAutomaticTpaReceipt(60108,array(101)),'A newly claimed native receipt is rechecked after preview');
tpaFixture(60109,'924.23');
$before=countAuto('bank');
$db->beforeBegin=static function () { tpaFixture(60110,'924.23',101,1,'csv','FECHO TPA 0012345678 PERIODO N 109'); };
checkAuto(!$service->reconcileAutomaticTpaReceipt(60109,array(101)) && countAuto('bank')===$before,
 'A competing same-identity source appearing after preview prevents creation');

// Provider settlements carry an exact descriptor and independently persisted source/bank identity.
function unicreFixture($id,$amount='930.23',$reference=null) {
 global $db;
 $reference=$reference??('BANKREF'.str_repeat('0',98).$id);
 $uid='fintable:'.hash('sha256','synthetic-unicre-'.$id);
 tpaFixture($id,$amount,101,1,'fintable','TRF CRED SEPA+ DE UNICRE S.A.');
 $payload=array('line_uid'=>$uid,'operation_date'=>'2026-11-01','value_date'=>'2026-11-01','amount'=>$amount,'currency'=>'EUR',
  'description'=>'TRF CRED SEPA+ DE UNICRE S.A.','bank_reference'=>$reference);
 $db->query("UPDATE kbauto_kreabank_statement_line SET line_uid='".$db->escape($uid)."',bank_reference='".$db->escape($reference)."' WHERE rowid=$id");
 $provider=strpos($reference,'tx_')===0?$reference:'tx_synthetic_unicre_'.$id;
 $external=$provider===$reference?'NULL':"'".hash('sha256',$reference)."'";
 $hash=hash('sha256',KreaBankFintableMapper::decimal($amount).'|2026-11-01|EUR');
 $json=json_encode($payload);
 $db->query("INSERT INTO kbauto_kreabank_feed_transaction(entity,fk_feed_account,provider_transaction_id,external_id_hash,source_uid,financial_hash,content_hash,fk_statement_line,source_payload,status,datec) VALUES(1,1,'"
  .$db->escape($provider)."',$external,'".$db->escape($uid)."','$hash','".hash('sha256',$json)."',$id,'".$db->escape($json)."','imported',NOW())");
 return $reference;
}
$reference=unicreFixture(60120);
$suggestion=$service->getAutomaticTpaSuggestion(60120,array(101));
checkAuto($suggestion!==null && $suggestion['kind']==='unicre' && $suggestion['bank_line_id']===0,'Exact UNICRE originator and persisted provider identity qualify a positive credit');
$before=countAuto('bank');
checkAuto($service->reconcileAutomaticTpaReceipt(60120,array(101)) && countAuto('bank')===$before+1,'Verified provider settlement creates one native receipt');
$mapped=$service->getBulkReviewLine(60120,'staged'); $nativeId=(int)$mapped['native_bank_line_id'];
$bank=$db->query('SELECT num_chq FROM kbauto_bank WHERE rowid='.$nativeId)->fetch_object();
$meta=$db->query('SELECT bank_reference FROM kbauto_kreabank_bankmeta WHERE fk_bank_line='.$nativeId.' AND entity=1')->fetch_object();
checkAuto(strlen($reference)===110 && strlen($bank->num_chq)<=50 && $bank->num_chq===KreaBankNativeBankAdapter::nativePaymentReference($reference)
 && $meta->bank_reference===$reference,'Native reference fits its field while metadata retains the complete 110-character source reference');
checkAuto(!$service->reconcileAutomaticTpaReceipt(60120,array(101)) && countAuto('bank')===$before+1,'Provider retries cannot create a second receipt');
tpaFixture(60121,'931.23',101,1,'csv','TRF CRED SEPA+ DE UNICRE S.A.');
checkAuto($service->getAutomaticTpaSuggestion(60121,array(101))===null,'An originator name without persisted provider identity cannot create a receipt');
unicreFixture(60122,'932.23');
$db->query("UPDATE kbauto_kreabank_statement_line SET description='TRF CRED SEPA+ DE SAMPLE UNICRE S.A.' WHERE rowid=60122");
checkAuto($service->getAutomaticTpaSuggestion(60122,array(101))===null,'A generic or embedded merchant name is not an exact provider settlement');
unicreFixture(60123,'933.23');
$db->query("UPDATE kbauto_kreabank_feed_transaction SET source_payload='{}' WHERE fk_statement_line=60123");
checkAuto($service->getAutomaticTpaSuggestion(60123,array(101))===null,'Incomplete original provider payload fails closed');
unicreFixture(60124,'934.23');
$db->query("UPDATE kbauto_kreabank_statement_line SET bank_reference='DIFFERENTBANKREF0001' WHERE rowid=60124");
checkAuto($service->getAutomaticTpaSuggestion(60124,array(101))===null,'Changed bank reference cannot borrow another provider identity');
unicreFixture(60125,'935.23');
$db->query("UPDATE kbauto_kreabank_feed_transaction SET external_id_hash=SHA2('different-reference',256) WHERE fk_statement_line=60125");
checkAuto($service->getAutomaticTpaSuggestion(60125,array(101))===null,'Provider external identity must agree with the exact bank reference');
$reference=unicreFixture(60126,'936.23'); tpaFixture(60127,'936.23');
$db->query("UPDATE kbauto_kreabank_statement_line SET bank_reference='".$db->escape($reference)."',status=3 WHERE rowid=60127");
checkAuto($service->getAutomaticTpaSuggestion(60126,array(101))===null,'The same bank transaction identity in another source blocks duplicate provider receipts');
unicreFixture(60128,'937.23','tx_synthetic_unicre_60128');
checkAuto($service->getAutomaticTpaSuggestion(60128,array(101))!==null,'The provider transaction ID is an explicit identity when no external ID exists');
$reference=unicreFixture(60129,'938.23');
bankAuto(66129,'938.23',KreaBankNativeBankAdapter::nativePaymentReference($reference));
$db->query("UPDATE kbauto_bank SET label='TRF CRED SEPA+ DE UNICRE S.A.' WHERE rowid=66129");
$before=countAuto('bank');
checkAuto($service->getAutomaticTpaSuggestion(60129,array(101))['bank_line_id']===66129
 && $service->reconcileAutomaticTpaReceipt(60129,array(101)) && countAuto('bank')===$before,
 'Exact originator and deterministic full-reference binding reuse one native provider receipt');
unicreFixture(60130,'939.23'); bankAuto(66130,'939.23','OTHERBANKSETTLEMENT0001');
$db->query("UPDATE kbauto_bank SET label='TRF CRED SEPA+ DE UNICRE S.A.',rappro=1 WHERE rowid=66130");
checkAuto($service->getAutomaticTpaSuggestion(60130,array(101))!==null,'Different fully identified provider settlement does not collide merely on amount');
unicreFixture(60131,'-940.23');
checkAuto($service->getAutomaticTpaSuggestion(60131,array(101))===null,'Provider identity never turns a debit into a receipt');
tpaFixture(60137,'945.23');
$db->query("UPDATE kbauto_kreabank_statement SET currency='USD' WHERE rowid=60137");
checkAuto($service->getAutomaticTpaSuggestion(60137,array(101))===null,'CSV parent statement currency must agree with its credit and owned account');
tpaFixture(60138,'946.23'); bankAuto(66138,'946.23','FECHO TPA 0012345678 PERIODO N 138');
$db->query("UPDATE kbauto_bank SET label='ESTORNO FECHO TPA 0012345678 PERIODO N 138' WHERE rowid=66138");
$before=countAuto('bank');
checkAuto($service->getAutomaticTpaSuggestion(60138,array(101))===null
 && !$service->reconcileAutomaticTpaReceipt(60138,array(101)) && countAuto('bank')===$before,
 'A clean reference cannot override native reversal evidence to reuse or create a receipt');
$db->query("UPDATE kbauto_bank SET label='FECHO TPA 0012345678 PERIODO N 138',num_chq='COMISSAO' WHERE rowid=66138");
checkAuto($service->getAutomaticTpaSuggestion(60138,array(101))===null,'A clean label cannot override a disqualified native reference');
tpaFixture(60139,'947.23'); bankAuto(66139,'-0.23','',101);
$db->query("UPDATE kbauto_bank SET label='COMISSAO BANCARIA' WHERE rowid=66139");
checkAuto($service->getAutomaticTpaSuggestion(60139,array(101))!==null,'An unrelated different-amount bank fee does not suppress a proven separate receipt');
tpaFixture(60136,'944.23',101,1,'csv','FECHO TPA 0012345678 PERIODO N 4');
$db->query("UPDATE kbauto_kreabank_recon_audit SET payload_json='{}' WHERE audit_type='reconcile' AND fk_statement_line=60004");
checkAuto($service->getAutomaticTpaSuggestion(60136,array(101))===null,'Unverifiable posted same-counter evidence blocks creation rather than inventing a balancing credit');
// Active normalized source claims exclude receipt creation even if scalar flags were changed.
foreach (explode(';', str_replace('llx_', 'kbauto_', preg_replace('/--[^\n]*/', '', file_get_contents(__DIR__.'/../sql/llx_kreabank_payment_batch.sql')))) as $sql) {
 if (trim($sql) !== '') { $db->query($sql); }
}
tpaFixture(60140,'948.23');
$db->query("INSERT INTO kbauto_kreabank_payment_batch(entity,fk_statement_line,active_statement_line,bank_account_id,amount,currency,operation_date,statement_ref,name,source_snapshot,fk_user,datec) VALUES(1,60140,60140,101,948.23,'EUR','2026-11-01','synthetic','Synthetic claim','{}',7,NOW())");
checkAuto($service->getAutomaticTpaSuggestion(60140,array(101))===null&&!$service->reconcileAutomaticTpaReceipt(60140,array(101)),'Active batch source cannot be captured as a TPA receipt');
// Losing dictionary availability after a preview cannot fall back to any other code.
tpaFixture(60031,'803.23');
$db->query("UPDATE kbauto_c_paiement SET active=0 WHERE code='MB' AND entity=1");
$before=countAuto('bank');checkAuto(!$service->reconcileAutomaticTpaReceipt(60031,array(101)) && countAuto('bank')===$before,'Payment mode loss after preview fails closed without a fallback');
$auto->configure($user,'off',array(101),false,false,'MB');
checkAuto($auto->setting('MODE')==='off','An inactive former payment mode does not prevent disabling automation');
echo 'OK: '.($checks-$initialChecks)." TPA receipt database checks\n";
