#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 * ONLY the disposable 127.0.0.1:33317/kreabank_auto_test fixture, never installation configuration.
 */
require __DIR__ . '/test_account_pairs_database.php';
$initialChecks = $checks;
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
$auto->configure($user,'enabled',array(101,102,103),false,true);
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
 && $audit['terminal_settlement']==='12345678/1', 'Atomic audit records the source settlement identity and native receipt');
$bankCount=countAuto('bank');
checkAuto(!$service->reconcileAutomaticTpaReceipt(60001,array(101)) && countAuto('bank')===$bankCount, 'Retry cannot create a second completed receipt');
tpaFixture(60002,'781.24',101,1,'csv','FECHO TPA 012345678 PERIODO N 001');
checkAuto($service->getAutomaticTpaSuggestion(60002,array(101))===null, 'Completed settlement identity blocks a different amount or leading-zero variant');
tpaFixture(60003,'782.23'); bankAuto(66003,'782.23','',101);
checkAuto($service->getAutomaticTpaSuggestion(60003,array(101))===null, 'Possible existing equal-amount ledger entry blocks creation');
$db->query('UPDATE kbauto_bank SET rappro=1 WHERE rowid=66003');
checkAuto($service->getAutomaticTpaSuggestion(60003,array(101))===null, 'Already reconciled possible movement also blocks duplication');
$db->query('UPDATE kbauto_bank SET amount=0.01 WHERE rowid=66003');
$db->query("UPDATE kbauto_bank SET label='FECHO TPA 12345678 PERIODO N 3',dateo='2026-10-10' WHERE rowid=66003");
checkAuto($service->getAutomaticTpaSuggestion(60003,array(101))===null, 'Same native settlement identity outside ordinary date tolerance blocks creation');
tpaFixture(60004,'783.23'); tpaFixture(60005,'783.24',101,1,'csv','FECHO TPA 12345678 PERIODO N 4');
checkAuto($service->getAutomaticTpaSuggestion(60004,array(101))===null && $service->getAutomaticTpaSuggestion(60005,array(101))===null,
 'Competing staged identities with conflicting amounts both remain pending');
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
$db->query("INSERT INTO kbauto_kreabank_feed_transaction(entity,fk_feed_account,provider_transaction_id,source_uid,financial_hash,content_hash,fk_statement_line,source_payload,status,datec) VALUES(1,1,'tpa_fixture','tpa-fixture-60011',SHA2('tpa-a',256),SHA2('tpa-b',256),60011,'{}','imported',NOW())");
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
checkAuto($service->getAutomaticNativeSuggestion(60015,array(101))!==null && $service->getAutomaticTpaSuggestion(60015,array(101))===null,
 'A proven existing native movement remains eligible through the unchanged safe matcher');
checkAuto($service->reconcileAutomaticNativeLine(60015,array(101)), 'Existing movement is reused instead of creating a receipt');
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
echo 'OK: '.($checks-$initialChecks)." TPA receipt database checks\n";
