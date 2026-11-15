#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 * ONLY the hard-coded disposable localhost:33317/kreabank_auto_test fixture.
 * This file never loads main.inc.php or installation credentials.
 */
require __DIR__ . '/test_bulk_review_database.php';
require_once __DIR__ . '/../class/KreaBankRule.class.php';
require_once __DIR__ . '/../lib/kreabank_manual_review.lib.php';
$initialChecks = $checks;
$conf->modules_parts = array('hooks'=>array());
function dol_trunc($value, $length, $mode = 'right', $charset = '', $dots = 1) { return substr($value,0,$length); }
class RuleTestLangs extends AutoTestLangs {
	public function transnoentitiesnoconv($key) { return $key; }
	public function transnoentities($key) { return $key; }
}
$langs = new RuleTestLangs();
$service = new KreaBankService($db,$user,$langs);
foreach (array('categorie','category_bankline','accounting_account','c_paiement','c_country','c_departements','accounting_journal') as $table) {
	$sql = preg_replace('/--[^\n]*/','',file_get_contents(DOL_DOCUMENT_ROOT . '/install/mysql/tables/llx_' . $table . '.sql'));
	$sql = preg_replace('/create table /i','CREATE TABLE IF NOT EXISTS ', $sql);
	foreach (explode(';', str_replace('llx_', MAIN_DB_PREFIX, $sql)) as $statement) { if (trim($statement) !== '') { $db->query($statement); } }
}
$extrafields->attributes['bank_account'] = array('loaded'=>1,'label'=>array());
$rules = new KreaBankRule($db,$user,1);
checkAuto(!$rules->available() && $rules->all() === array(), 'An existing installation without rule schema remains readable without page-load DDL');
$schema = preg_replace('/--[^\n]*/','',file_get_contents(__DIR__ . '/../sql/llx_kreabank_rule.sql'));
foreach (explode(';',str_replace('llx_',MAIN_DB_PREFIX,$schema)) as $statement) { if(trim($statement)!==''){$db->query($statement);} }
checkAuto($rules->available(), 'Reviewed migration adds the rule table');
$db->query("INSERT INTO kbauto_categorie(rowid,entity,label,type) VALUES (81001,1,'Owned bank category',8),(81002,2,'Foreign bank category',8),(81003,1,'Product category',0)");
$db->query("INSERT INTO kbauto_societe(rowid,entity,nom,fournisseur,client) VALUES (81001,1,'Owned supplier',1,1),(81002,2,'Foreign supplier',1,1)");
$db->query("INSERT INTO kbauto_accounting_account(rowid,entity,fk_pcg_version,pcg_type,account_number,label,active) VALUES (81001,1,'TEST','TEST','622TEST','Owned expense',1),(81002,2,'TEST','TEST','622FOREIGN','Foreign expense',1)");
$db->query("INSERT INTO kbauto_c_paiement(id,entity,code,libelle,type,active) VALUES (81001,1,'RULE','Explicit test mode',0,1),(81002,2,'OTHER','Foreign mode',0,1)");
$data = array('label'=>'Bank fee','priority'=>100,'fk_bank_account'=>101,'direction'=>'out',
	'conditions'=>array('all'=>array(array('field'=>'description','operator'=>'contains','value'=>'Monthly fee'))),
	'action'=>array('type'=>'category','category_id'=>81001));
$beforeAudit = countAuto('kreabank_recon_audit');
$id = $rules->save($data);
$saved = $rules->all()[0];
checkAuto((int)$saved['active']===0 && $rules->all(true)===array(), 'New rules default inactive');
checkAuto(countAuto('kreabank_recon_audit')===$beforeAudit+1, 'Rule configuration and its audit commit together');
foreach (explode(';',str_replace('llx_',MAIN_DB_PREFIX,$schema)) as $statement) { if(trim($statement)!==''){$db->query($statement);} }
checkAuto(count($rules->all())===1, 'Repeated migration preserves existing rules');
$foreign=$data; $foreign['fk_bank_account']=202;
rejectAuto(static function()use($rules,$foreign){$rules->save($foreign);}, 'Rules cannot target another company account');
$foreign=$data; $foreign['action']['category_id']=81002;
rejectAuto(static function()use($rules,$foreign){$rules->save($foreign);}, 'Rules cannot suggest another company category');
$foreign=$data; $foreign['action']['category_id']=81003;
rejectAuto(static function()use($rules,$foreign){$rules->save($foreign);}, 'Product categories cannot be used as bank movement categories');
$foreign=$data; $foreign['action']=array('type'=>'invoice_search','thirdparty_id'=>81002,'invoice_type'=>'supplier_invoice');
rejectAuto(static function()use($rules,$foreign){$rules->save($foreign);}, 'Invoice search cannot reference a foreign third party');
$quick=$data; $quick['action']=array('type'=>'quick_entry','thirdparty_id'=>81001,'accounting_account'=>'622TEST','vat'=>'23','label'=>'Fee','payment_mode'=>'RULE');
$quickId=$rules->save($quick);
checkAuto($quickId>0, 'Complete owned quick-entry prefill is accepted with an explicitly selected native payment mode');
foreach(array('accounting_account'=>'622FOREIGN','payment_mode'=>'OTHER','thirdparty_id'=>81002) as $key=>$value){
	$bad=$quick;$bad['action'][$key]=$value;
	rejectAuto(static function()use($rules,$bad){$rules->save($bad);},'Foreign quick-entry reference rejected: '.$key);
}
$user->allowed=false;
rejectAuto(static function()use($rules,$data){$rules->save($data);}, 'Current read/write permissions are required');
$user->allowed=true;
$conf->entity=2; $foreignRules=new KreaBankRule($db,$user,2);
checkAuto($foreignRules->all()===array(), 'Rule lists do not leak another company');
rejectAuto(static function()use($rules){$rules->all();}, 'An old rule service cannot survive a company switch');
rejectAuto(static function()use($foreignRules,$id,$saved){$foreignRules->delete($id,$saved['revision']);}, 'Another company cannot delete the displayed rule');
$conf->entity=1;
$active=$saved; $active['active']=1; $rules->save($active,$id);
$active=$rules->all()[0];
rejectAuto(static function()use($rules,$saved,$id){$rules->save($saved,$id);}, 'Stale displayed rule revision rejects changes');
$count=count($rules->all()); $audit=countAuto('kreabank_recon_audit'); $db->fail='INSERT INTO kbauto_kreabank_recon_audit';
rejectAuto(static function()use($rules,$data){$rules->save($data);}, 'Configuration audit failure is visible');
$db->fail='';
checkAuto(count($rules->all())===$count && countAuto('kreabank_recon_audit')===$audit, 'Configuration audit failure rolls back the rule itself');
stageAuto(82000,'-12.34',''); bankAuto(92000,'-12.34','');
$db->query("UPDATE kbauto_kreabank_statement_line SET description='Monthly fee' WHERE entity=1 AND rowid=82000");
$line=$service->getBulkReviewLine(82000,'staged');
$line['entity']=1; $line['amount']='-12.34';
$advice=KreaBankRule::evaluate($rules->all(true),$line);
checkAuto((int)$advice['winner']['rowid']===$id, 'An owned active rule provides advisory evidence');
checkAuto($service->getAutomaticNativeSuggestion(82000,array(101))===null, 'A matching rule never makes amount/date-only evidence automatically safe');
checkAuto((int)$rules->all()[0]['hit_count']===0, 'Evaluation never increments usage');
$worklistCallbacks = kreabankManualReviewCallbacks($service, 3, 100, false);
$worklistRows = $worklistCallbacks['prepareDocumentReviews']($line);
checkAuto(count($worklistRows) > 0, 'Shared list review retains ordinary manual candidates while a rule matches');
foreach ($worklistRows as $worklistRow) {
	checkAuto(empty($worklistRow['bank_rule']), 'Shared list review never receives undisplayed selected-line rule actions');
}
rejectAuto(static function()use($rules,$id,$active,$line){$rules->recordHit($id,$active['revision'],$line);}, 'Usage cannot update outside the approval transaction');
$row=reviewedFixture(82000,92000);
$row['bank_rule']=KreaBankService::bankRuleReviewSnapshot($service->getBankRuleAdvice($line));
$bankBefore=countAuto('bank'); $auditBefore=countAuto('kreabank_recon_audit');
$db->beforeBegin=static function()use($db,$id){$db->query("UPDATE kbauto_kreabank_rule SET label='Concurrent rule change' WHERE entity=1 AND rowid=".(int)$id);};
rejectAuto(static function()use($row){applyReviewedFixture($row);}, 'A rule changed after display cannot be approved');
$db->query("UPDATE kbauto_kreabank_rule SET label='Bank fee' WHERE entity=1 AND rowid=".(int)$id);
checkAuto((int)$rules->all()[0]['hit_count']===0 && $service->getBulkReviewLine(82000,'staged')['status']===0 && countAuto('kreabank_recon_audit')===$auditBefore,
	'Changed-rule rejection leaves the source, usage and financial audit unchanged');
$db->begin();
applyReviewedFixture($row);
$db->rollback();
checkAuto((int)$rules->all()[0]['hit_count']===0 && $service->getBulkReviewLine(82000,'staged')['status']===0 && countAuto('kreabank_recon_audit')===$auditBefore,
	'Approval, native changes, usage and audit roll back in one transaction');
$db->begin();
applyReviewedFixture($row);
$db->commit();
$used=$rules->all()[0];
checkAuto((int)$used['hit_count']===1 && $used['last_used']!==null && countAuto('bank')===$bankBefore && countAuto('kreabank_recon_audit')===$auditBefore+1,
	'Usage counts only successful approved reconciliation without creating another movement');
checkAuto((int)$db->query('SELECT COUNT(*) n FROM kbauto_category_bankline WHERE lineid=92000 AND fk_categ=81001')->fetch_object()->n===1,
	'The approved category uses the native bank reconciliation lifecycle');
$auditPayload=json_decode($db->query("SELECT payload_json FROM kbauto_kreabank_recon_audit WHERE entity=1 AND audit_type='reconcile' ORDER BY rowid DESC LIMIT 1")->fetch_object()->payload_json,true);
checkAuto((int)$auditPayload['bank_rule']['id']===$id && $auditPayload['bank_rule']['revision']===$active['revision'], 'Atomic approval audit identifies the exact reviewed rule revision');
checkAuto($used['revision']===$active['revision'], 'Usage counters do not invalidate the displayed rule configuration revision');
$quickRow=$rules->all()[1]; $quickRow['active']=1; $quickRow['priority']=50; $rules->save($quickRow,$quickId);
stageAuto(82010,'-23.45','RULEQUICK82010');
$db->query("UPDATE kbauto_kreabank_statement_line SET description='Monthly fee' WHERE entity=1 AND rowid=82010");
$quickLine=$service->getBulkReviewLine(82010,'staged');
$quickReview=$service->getBankRuleQuickReview($quickLine);
checkAuto($quickReview!==null && $quickReview['rule_creation']['payment_mode']==='RULE' && $quickReview['allocation']==='23.45', 'Quick-entry advice binds explicit mode and exact persisted amount');
checkAuto(count($quickReview['bank_rule']['conflicts'])===2 && (int)$quickReview['bank_rule']['id']===$quickId, 'Signed quick advice preserves conflicts while the lowest priority wins');
$beforeQuick=countAuto('kreabank_quick_entry'); $beforeBank=countAuto('bank'); $beforeAudit=countAuto('kreabank_recon_audit');
$db->fail='UPDATE kbauto_kreabank_rule SET hit_count';
rejectAuto(static function()use($service,$quickReview){$service->reconcileBankRuleQuickEntry($quickReview);}, 'Quick-entry usage failure rejects approval');
$db->fail='';
checkAuto(countAuto('kreabank_quick_entry')===$beforeQuick && countAuto('bank')===$beforeBank && countAuto('kreabank_recon_audit')===$beforeAudit
	&& $service->getBulkReviewLine(82010,'staged')['status']===0, 'Quick-entry usage failure rolls back native entry, staging and audit');
$quickEntryId=$service->reconcileBankRuleQuickEntry($quickReview);
$nativeQuick=$service->getBulkReviewLine(82010,'staged');
$nativeBank=$db->query('SELECT amount,fk_type,numero_compte,note AS note_private FROM kbauto_bank WHERE rowid='.(int)$nativeQuick['native_bank_line_id'])->fetch_object();
checkAuto($quickEntryId>0 && $nativeQuick['status']===2 && KreaBankDecimal::compare((string)$nativeBank->amount,'-23.45')===0 && $nativeBank->fk_type==='RULE', 'Approved quick entry uses the exact signed amount and explicit native payment mode');
checkAuto(empty($nativeBank->numero_compte) && strpos((string)$nativeBank->note_private,'622TEST')!==false, 'Accounting account and VAT remain review hints without an accounting posting');
rejectAuto(static function()use($service,$quickReview){$service->reconcileBankRuleQuickEntry($quickReview);}, 'An approved quick-entry review cannot be replayed');
$quickRow=null; foreach($rules->all() as $ruleRow){if((int)$ruleRow['rowid']===$quickId){$quickRow=$ruleRow;}}
$rules->delete($quickId,$quickRow['revision']);
checkAuto(count($rules->all())===1, 'Owned revision-checked deletion removes only the chosen rule');
echo 'OK: '.($checks-$initialChecks)." bank-rule database checks\n";
