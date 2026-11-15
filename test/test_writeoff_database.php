#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 * ONLY the unchanged guarded localhost:33317/kreabank_auto_test fixture.
 */
require __DIR__ . '/test_draft_invoice_review_database.php';
$writeOffInitialChecks = $checks;
$GLOBALS['draftInvoiceFixtureModules'] = array('invoice', 'supplier_invoice');
$conf->global->MAIN_DISABLE_PDF_AUTOUPDATE = 1;
$conf->modules_parts['models'] = array(DOL_DOCUMENT_ROOT . '/');
$conf->modules_parts['hooks'] = array();
require_once DOL_DOCUMENT_ROOT . '/core/class/hookmanager.class.php';
$hookmanager = new HookManager($db);
if (!function_exists('dol_getIdFromCode')) {
	function dol_getIdFromCode($db, $key, $table, $field = 'code', $id = 'id', $entity = 0, $filters = '', $cache = true) {
		if ($table !== 'c_paiement' || $field !== 'code' || $id !== 'id' || $entity !== 1 || $filters !== '') { throw new RuntimeException('Unexpected fixture payment dictionary lookup'); }
		$result = $db->query('SELECT id FROM ' . MAIN_DB_PREFIX . 'c_paiement WHERE entity=' . (int) $GLOBALS['conf']->entity . " AND code='" . $db->escape($key) . "'");
		$row = $result ? $db->fetch_object($result) : null;
		return $row ? (int) $row->id : 0;
	}
}
// Native payment deletion uses these two static framework filter expressions.
// Keep the payment/invoice persistence and deletion guards in the real native classes.
if (!function_exists('forgeSQLFromUniversalSearchCriteria')) {
	function forgeSQLFromUniversalSearchCriteria($filter, &$error = '', ...$unused) {
		$known = array('f.fk_statut:>:1'=>' AND f.fk_statut>1', 'paye:=:1'=>' AND paye=1');
		if (!isset($known[$filter])) { throw new RuntimeException('Unexpected native deletion filter'); }
		return $known[$filter];
	}
}
foreach (array('paiement', 'paiementfourn', 'payment', 'payment_supplier') as $element) { $extrafields->attributes[$element] = array('loaded'=>1, 'label'=>array()); }
foreach (array('accounting_account', 'paiement_extrafields') as $table) {
	$sql = preg_replace('/--[^\n]*/', '', file_get_contents(DOL_DOCUMENT_ROOT . '/install/mysql/tables/llx_' . $table . '.sql'));
	foreach (explode(';', str_replace('llx_', MAIN_DB_PREFIX, $sql)) as $statement) { if (trim($statement) !== '') { $db->query($statement); } }
}
foreach (array('627', '688', '682', '782') as $account) {
	$db->query("INSERT INTO kbauto_accounting_account(entity,fk_pcg_version,pcg_type,account_number,label,active) VALUES(1,'TEST','TEST','$account','Synthetic account $account',1)");
}
$writeOffSettings = array('ENABLED'=>'1', 'MAX_ABS'=>'2', 'MAX_PERCENT'=>'1', 'ACCOUNT_BANK_FEE'=>'627', 'ACCOUNT_ROUNDING'=>'688', 'ACCOUNT_DISCOUNT_GRANTED'=>'682', 'ACCOUNT_DISCOUNT_RECEIVED'=>'782');
function writeOffSettingFixture($suffix, $value, $entity = 1, $mirror = true)
{
	global $db, $conf;
	$name = 'KREABANK_WRITEOFF_' . $suffix;
	$db->query("DELETE FROM kbauto_const WHERE entity=" . (int) $entity . " AND name='" . $db->escape($name) . "'");
	$db->query("INSERT INTO kbauto_const(name,entity,value,type) VALUES('" . $db->escape($name) . "'," . (int) $entity . ",'" . $db->escape($value) . "','chaine')");
	if ($mirror && $entity === 1) { $conf->global->$name = $value; }
}
foreach ($writeOffSettings as $key=>$value) { writeOffSettingFixture($key, $value); }
$db->query("INSERT INTO kbauto_societe(rowid,entity,nom,client,fournisseur) VALUES(98099,1,'Synthetic difference counterparty',1,1)");
function writeOffFixture($id, $supplier = false)
{
	global $db;
	draftFixture($id, $supplier, 1, 1, 0);
	$table = $supplier ? 'facture_fourn' : 'facture';
	$db->query("UPDATE kbauto_{$table} SET total_ttc=100,total_ht=100,multicurrency_total_ttc=100,multicurrency_tx=1,multicurrency_code='EUR',paye=0,fk_mode_reglement=1,fk_soc=98099 WHERE rowid=$id AND entity=1");
	stageAuto($id, $supplier ? '-99' : '99', 'WRITEOFF-' . $id);
}
function writeOffReviewFixture($id, $supplier = false, $reason = 'rounding', $actor = null)
{
	global $service;
	$actor = $actor ?: $service;
	return $actor->getWriteOffReview($actor->getBulkReviewLine($id, 'staged'), $supplier ? 'supplier_invoice' : 'customer_invoice', $id, $reason);
}
function applyWriteOffFixture($row, $actor = null, $automatic = 0)
{
	global $service;
	$actor = $actor ?: $service;
	$suggestion = $row['suggestion'];
	return $actor->reconcileLine((int) $row['line']['rowid'], array(array('doc_type'=>$suggestion['doc_type'], 'fk_doc'=>$suggestion['doc_id'],
		'doc_ref'=>$suggestion['doc_ref'], 'allocated_amount'=>$row['allocation'], 'match_score'=>1, 'match_reasons'=>array())),
		'manual_writeoff', $automatic, 'Synthetic reviewed difference', 1, null, $row);
}
writeOffFixture(98000);
$db->query("UPDATE kbauto_const SET entity=0 WHERE entity=1 AND name='KREABANK_WRITEOFF_ENABLED'");
checkAuto($service->writeOffConfiguration()===null && writeOffReviewFixture(98000)===null, 'Inherited global enablement cannot replace a missing current-company flag');
$db->query("UPDATE kbauto_const SET entity=1 WHERE entity=0 AND name='KREABANK_WRITEOFF_ENABLED'");
$db->query("UPDATE kbauto_const SET entity=0 WHERE entity=1 AND name LIKE 'KREABANK_WRITEOFF_%'");
checkAuto($service->writeOffConfiguration()===null && writeOffReviewFixture(98000)===null, 'Global-only configuration never enables a company through the request cache');
$db->query("UPDATE kbauto_const SET entity=1 WHERE entity=0 AND name LIKE 'KREABANK_WRITEOFF_%'");
$db->query("INSERT INTO kbauto_const(name,entity,value,type) VALUES('KREABANK_WRITEOFF_MAX_ABS',1,'2','chaine')");
$duplicateConst=(int)$db->last_insert_id();
checkAuto($service->writeOffConfiguration()===null && writeOffReviewFixture(98000)===null, 'Duplicate current-company configuration rows fail closed');
$db->query('DELETE FROM kbauto_const WHERE entity=1 AND rowid='.$duplicateConst);
$row = writeOffReviewFixture(98000);
checkAuto($row && $row['writeoff']['difference']==='1' && $row['allocation']==='99', 'Write-off reviews exact persisted bank/invoice amounts');
$reasonReviews=$service->getWriteOffReviews($row['line'],'customer_invoice',98000);
foreach ($reasonReviews as $reason=>$prepared) { checkAuto($prepared===writeOffReviewFixture(98000,false,$reason), 'Prepared reason rows retain exactly the independently recomputed signed facts'); }
$before = array(countAuto('bank'), countAuto('paiement'), countAuto('kreabank_recon_audit'));
checkAuto(writeOffReviewFixture(98000)!==null && $before===array(countAuto('bank'),countAuto('paiement'),countAuto('kreabank_recon_audit')), 'Difference preview is read-only');
checkAuto($service->getSafeSuggestion(array($row['suggestion']),150)===null, 'Difference review cannot become a safe automatic match');
rejectAuto(static function () use ($row) { applyWriteOffFixture($row, null, 1); }, 'Write-offs never run with automatic approval');
writeOffSettingFixture('ENABLED', '0');
checkAuto(writeOffReviewFixture(98000)===null, 'Default-off configuration hides the feature');
rejectAuto(static function () use ($row) { applyWriteOffFixture($row); }, 'A previously signed review cannot outlive disabling the feature');
writeOffSettingFixture('ENABLED', '1');
writeOffSettingFixture('MAX_ABS', '0.50');
rejectAuto(static function () use ($row) { applyWriteOffFixture($row); }, 'Changed limits reject an old review rather than reusing old eligibility');
writeOffSettingFixture('MAX_ABS', '2');
$beforeConstChange=array(countAuto('bank'),countAuto('paiement'),countAuto('kreabank_recon_audit'));
foreach (array('ENABLED'=>'0', 'MAX_ABS'=>'3', 'MAX_PERCENT'=>'0.50', 'ACCOUNT_ROUNDING'=>'627') as $key=>$value) {
	$row=writeOffReviewFixture(98000);
	$db->beforeBegin=static function () use($key,$value) { writeOffSettingFixture($key,$value,1,false); };
	$configurationFailure='';
	try { applyWriteOffFixture($row); } catch (Throwable $e) { $configurationFailure=$e->getMessage(); }
	checkAuto($configurationFailure==='Invoice difference configuration changed; review it again', 'Locked execution rejects a concurrent persisted flag/limit/account change despite an unchanged request cache');
	checkAuto($beforeConstChange===array(countAuto('bank'),countAuto('paiement'),countAuto('kreabank_recon_audit'))
		&& $service->getBulkReviewLine(98000,'staged')['status']===0 && $db->depth===0, 'Concurrent configuration edits create no financial or audit writes');
	writeOffSettingFixture($key,$writeOffSettings[$key]);
}
$row=writeOffReviewFixture(98000);
foreach (array("UPDATE kbauto_facture SET total_ttc=101 WHERE entity=1 AND rowid=98000",
	"UPDATE kbauto_facture SET entity=2 WHERE rowid=98000",
	"UPDATE kbauto_kreabank_statement_line SET amount=98 WHERE entity=1 AND rowid=98000",
	"UPDATE kbauto_accounting_account SET active=0 WHERE entity=1 AND account_number='688'") as $mutation) {
	$db->beforeBegin = static function () use ($db,$mutation) { $db->query($mutation); };
	rejectAuto(static function () use ($row) { applyWriteOffFixture($row); }, 'Concurrent source/invoice/account changes invalidate the displayed approval');
	$db->query('UPDATE kbauto_facture SET total_ttc=100,entity=1 WHERE rowid=98000');
	$db->query('UPDATE kbauto_kreabank_statement_line SET amount=99 WHERE entity=1 AND rowid=98000');
	$db->query("UPDATE kbauto_accounting_account SET active=1 WHERE entity=1 AND account_number='688'");
}
$denied = new class extends User { public function hasRight($module,$right,$subright='') { return $module!=='facture' || $right!=='paiement'; } };
$deniedService = new KreaBankService($db,$denied,$langs);
checkAuto(writeOffReviewFixture(98000,false,'rounding',$deniedService)===null, 'Native payment rights are required before displaying an action');
rejectAuto(static function () use ($row,$deniedService) { applyWriteOffFixture($row,$deniedService); }, 'Current native payment rights are rechecked by execution');
foreach (array("type=2", "fk_statut=0", "multicurrency_code='USD'") as $change) {
	$db->query("UPDATE kbauto_facture SET $change WHERE entity=1 AND rowid=98000");
	checkAuto(writeOffReviewFixture(98000)===null, 'Credit-note, draft and foreign-currency differences remain hidden');
	$db->query("UPDATE kbauto_facture SET type=0,fk_statut=1,multicurrency_code='EUR' WHERE entity=1 AND rowid=98000");
}
$db->query("INSERT INTO kbauto_const(name,entity,value,type) VALUES('KREABANK_ACCOUNT_PAIRS',1,'{\"101\":102}','chaine')");
rejectAuto(static function () { writeOffReviewFixture(98000); }, 'Restricted account invoices cannot authorize a difference');
$db->query("DELETE FROM kbauto_const WHERE entity=1 AND name='KREABANK_ACCOUNT_PAIRS'");
writeOffFixture(98002);
bankAuto(99002,'99','WRITEOFF-98002');
$db->query('UPDATE kbauto_kreabank_statement_line SET fk_native_bank_line=99002 WHERE entity=1 AND rowid=98002');
$db->query("INSERT INTO kbauto_bank_url(fk_bank,url_id,url,label,type) VALUES(99002,123,'/synthetic/','Existing financial link','payment')");
checkAuto(writeOffReviewFixture(98002)===null, 'An imported movement already carrying a payment cannot create another invoice payment');
$row=writeOffReviewFixture(98000);
foreach (array('INSERT INTO kbauto_paiement ', 'INSERT INTO kbauto_bank_url', 'INSERT INTO kbauto_kreabank_recon_audit', 'UPDATE kbauto_kreabank_statement_line') as $failure) {
	$before = array(countAuto('bank'),countAuto('paiement'),countAuto('bank_url'),countAuto('kreabank_recon_audit'));
	$db->fail=$failure;
	rejectAuto(static function () use ($row) { applyWriteOffFixture($row); }, 'Native/payment/link/audit write failure aborts difference reconciliation');
	$db->fail='';
	checkAuto($before===array(countAuto('bank'),countAuto('paiement'),countAuto('bank_url'),countAuto('kreabank_recon_audit')) && $db->depth===0
		&& (int)$db->query('SELECT fk_statut FROM kbauto_facture WHERE entity=1 AND rowid=98000')->fetch_object()->fk_statut===1, 'Failed difference approval rolls back invoice, native movement, payment and audit');
}
$before = array(countAuto('bank'),countAuto('paiement'),countAuto('bank_url'),countAuto('kreabank_recon_audit'));
$db->fail = "INSERT INTO kbauto_kreabank_recon_audit (entity, audit_type, fk_statement_line, fk_reconciliation, payload_json, fk_user, ip_address, datec) VALUES (1, 'manual_writeoff'";
$auditFailure='';
try { applyWriteOffFixture($row); } catch (Throwable $e) { $auditFailure=$e->getMessage(); }
$db->fail='';
checkAuto($auditFailure==='Failed to persist invoice difference audit', 'The separate manual_writeoff audit failure is reached after native closing and ordinary reconciliation audit');
checkAuto($before===array(countAuto('bank'),countAuto('paiement'),countAuto('bank_url'),countAuto('kreabank_recon_audit'))
	&& (int)$db->query('SELECT fk_statut FROM kbauto_facture WHERE entity=1 AND rowid=98000')->fetch_object()->fk_statut===1
	&& $service->getBulkReviewLine(98000,'staged')['status']===0 && $db->depth===0, 'Failure of the second audit rolls back both audits, native payment, closing and statement mapping');
// Simulate native-bank amount drift at the actual payment boundary. Persistence
// still uses the real native classes; the injected change must roll back with them.
$changedBankService = new class($db,$user,$langs) extends KreaBankService {
	protected function createCustomerPaymentForBankLine($line,$invoiceId,$amount,$note='') {
		$created=parent::createCustomerPaymentForBankLine($line,$invoiceId,$amount,$note);
		$this->db->query('UPDATE kbauto_bank b JOIN kbauto_bank_account ba ON ba.rowid=b.fk_account SET b.amount=-99 WHERE ba.entity=1 AND b.rowid='.(int)$line['rowid']);
		return $created;
	}
};
$bankFailure='';
try { applyWriteOffFixture($row,$changedBankService); } catch (Throwable $e) { $bankFailure=$e->getMessage(); }
checkAuto($bankFailure==='Native bank movement did not preserve the exact reviewed signed amount', 'Exact native signed bank amount is verified after native creation');
checkAuto($before===array(countAuto('bank'),countAuto('paiement'),countAuto('bank_url'),countAuto('kreabank_recon_audit')) && $db->depth===0, 'Native signed amount drift rolls back the payment and movement');
$row=writeOffReviewFixture(98000);
checkAuto(applyWriteOffFixture($row)===98000, 'Explicit reviewed customer difference uses the existing reconcileLine executor');
$invoice=$db->query('SELECT fk_statut,paye,close_code FROM kbauto_facture WHERE entity=1 AND rowid=98000')->fetch_object();
checkAuto((int)$invoice->fk_statut===2 && (int)$invoice->paye===0 && $invoice->close_code==='other', 'Customer native close reason closes the invoice without inventing a full payment');
$audit=$db->query("SELECT payload_json FROM kbauto_kreabank_recon_audit WHERE entity=1 AND audit_type='reconcile' AND fk_statement_line=98000 ORDER BY rowid DESC LIMIT 1")->fetch_object();
$payload=json_decode($audit->payload_json,true);
checkAuto($payload['strategy']==='manual_writeoff' && $payload['writeoff']['difference']==='1' && $payload['writeoff']['account']==='688'
	&& !empty($payload['writeoff']['payment_id']), 'Atomic audit retains reason, exact difference, configured account and created payment');
rejectAuto(static function () use ($row) { applyWriteOffFixture($row); }, 'Duplicate approval cannot create another payment');
require_once __DIR__ . '/../class/KreaBankHistory.class.php';
$history=new KreaBankHistory($db,1);
$completed=$history->find(98000,'staged',101);
$undo=KreaBankHistory::snapshot($completed);
$undo['writeoff']=$service->writeOffUndoSnapshot($completed);
$beforeBank=countAuto('bank');
$db->query('UPDATE kbauto_paiement SET amount=98 WHERE entity=1 AND rowid='.(int)$payload['writeoff']['payment_id']);
rejectAuto(static function () use($service,$undo) { $service->undoReconciliation(98000,'Changed payment',$undo); }, 'Changed payment evidence invalidates undo before reversal');
checkAuto($service->getBulkReviewLine(98000,'staged')['status']===2
	&& (int)$db->query('SELECT fk_statut FROM kbauto_facture WHERE entity=1 AND rowid=98000')->fetch_object()->fk_statut===2, 'Denied undo retains reconciled bank and closed invoice');
$db->query('UPDATE kbauto_paiement SET amount=99 WHERE entity=1 AND rowid='.(int)$payload['writeoff']['payment_id']);
$beforeUndo=array(countAuto('paiement'),countAuto('bank_url'),countAuto('kreabank_recon_audit'));
$db->fail="INSERT INTO kbauto_kreabank_recon_audit (entity, audit_type, fk_statement_line, fk_reconciliation, payload_json, fk_user, ip_address, datec) VALUES (1, 'undo'";
$undoFailure='';
try { $service->undoReconciliation(98000,'Failed reversal audit',$undo); } catch (Throwable $e) { $undoFailure=$e->getMessage(); }
$db->fail='';
checkAuto($undoFailure==='Failed to persist undo audit', 'Undo reaches its required atomic audit after native payment deletion');
checkAuto($beforeUndo===array(countAuto('paiement'),countAuto('bank_url'),countAuto('kreabank_recon_audit')) && countAuto('bank')===$beforeBank
	&& $service->getBulkReviewLine(98000,'staged')['status']===2
	&& (int)$db->query('SELECT fk_statut FROM kbauto_facture WHERE entity=1 AND rowid=98000')->fetch_object()->fk_statut===2, 'Undo audit failure restores native payment, invoice closing, mapping and bank evidence');
$service->undoReconciliation(98000,'Synthetic reversal',$undo);
checkAuto(countAuto('bank')===$beforeBank && $service->getBulkReviewLine(98000,'staged')['status']===0, 'Undo preserves imported native bank evidence and restores pending source');
checkAuto((int)$db->query('SELECT COUNT(*) n FROM kbauto_paiement WHERE entity=1 AND rowid='.(int)$payload['writeoff']['payment_id'])->fetch_object()->n===0
	&& (int)$db->query('SELECT fk_statut FROM kbauto_facture WHERE entity=1 AND rowid=98000')->fetch_object()->fk_statut===1, 'Undo deletes only the created native payment and reopens its invoice');
writeOffFixture(98001,true);
$supplier=writeOffReviewFixture(98001,true,'discount_received');
checkAuto($supplier && applyWriteOffFixture($supplier)===98001, 'Supplier reviewed difference records its exact bank payment');
$invoice=$db->query('SELECT fk_statut,paye,close_code FROM kbauto_facture_fourn WHERE entity=1 AND rowid=98001')->fetch_object();
checkAuto((int)$invoice->fk_statut===1 && (int)$invoice->paye===0 && !$invoice->close_code, 'Supplier residual remains partly paid under the native lifecycle');
$supplierCompleted=$history->find(98001,'staged',101);
$supplierUndo=KreaBankHistory::snapshot($supplierCompleted);
$beforeSupplierUndo=countAuto('paiementfourn'); $beforeSupplierBank=countAuto('bank');
$service->undoReconciliation(98001,'Synthetic supplier reversal',$supplierUndo);
checkAuto(countAuto('paiementfourn')===$beforeSupplierUndo-1 && countAuto('bank')===$beforeSupplierBank
	&& $service->getBulkReviewLine(98001,'staged')['status']===0
	&& (int)$db->query('SELECT fk_statut FROM kbauto_facture_fourn WHERE entity=1 AND rowid=98001')->fetch_object()->fk_statut===1, 'Supplier reversal uses native deletion while preserving the bank evidence and original open invoice');
// Execute the real native paymentdao hook boundary and real Facture::setPaid().
// This recreates a supported native early-discount policy without replacing its
// invoice/payment persistence or getRemainToPay behavior.
$nativeDiscountHook=new class {
	public $target=0; public $calls=0; public $changeTotal=false;
	public $error=''; public $errors=array(); public $warnings=array();
	public function createPayment($parameters,&$object,&$action,$manager) {
		if ($action!=='CLOSEPAIDINVOICE' || (int)($parameters['facid']??0)!==$this->target) { return 0; }
		$this->calls++;
		$invoice=$parameters['invoice'];
		$result=$invoice->setPaid($GLOBALS['user'],'discount_vat','Synthetic native early discount');
		if ($this->changeTotal) { $GLOBALS['db']->query('UPDATE kbauto_facture SET total_ttc=101 WHERE entity=1 AND rowid='.(int)$invoice->id); }
		return $result>0 ? 1 : -1;
	}
};
$hookmanager->hooks=array('paymentdao'=>array('writeoff_fixture'=>$nativeDiscountHook));
$hookmanager->hooksSorted=array('paymentdao'=>array('50:writeoff_fixture'=>$nativeDiscountHook));
$hookmanager->contextarray=array('paymentdao');
writeOffFixture(98003);
$nativeDiscountHook->target=98003;
$hookRow=writeOffReviewFixture(98003,false,'discount_granted');
checkAuto($hookRow && applyWriteOffFixture($hookRow)===98003 && $nativeDiscountHook->calls===1, 'Matching explicitly reviewed customer discount accepts the existing native payment hook closing');
$hookInvoice=$db->query('SELECT fk_statut,paye,close_code,close_note FROM kbauto_facture WHERE entity=1 AND rowid=98003')->fetch_object();
checkAuto((int)$hookInvoice->fk_statut===2 && (int)$hookInvoice->paye===0 && $hookInvoice->close_code==='discount_vat'
	&& $hookInvoice->close_note==='Synthetic native early discount', 'Native early-discount closing is retained rather than executed a second time');
$hookCompleted=$history->find(98003,'staged',101);
$hookAudit=KreaBankWriteOff::undoSnapshotFromHistory($hookCompleted);
checkAuto($hookAudit && $hookAudit['writeoff']['difference']==='1' && $hookAudit['writeoff']['reason']==='discount_granted', 'The native hook retains the exact original reviewed difference in the atomic audit');
$hookPaymentId=(int)$hookAudit['writeoff']['payment_id'];
$service->undoReconciliation(98003,'Reverse matching native discount hook',KreaBankHistory::snapshot($hookCompleted));
checkAuto($service->getBulkReviewLine(98003,'staged')['status']===0
	&& (int)$db->query('SELECT COUNT(*) n FROM kbauto_paiement WHERE entity=1 AND rowid='.$hookPaymentId)->fetch_object()->n===0
	&& (int)$db->query('SELECT fk_statut FROM kbauto_facture WHERE entity=1 AND rowid=98003')->fetch_object()->fk_statut===1, 'A native-hook-closed customer discount remains exactly reversible');
foreach (array(98004=>array('rounding',false,'Native customer invoice closing differs from the reviewed reason'),
	98005=>array('discount_granted',true,'Native payment residual differs from the reviewed amount')) as $id=>$case) {
	writeOffFixture($id); $nativeDiscountHook->target=$id; $nativeDiscountHook->calls=0; $nativeDiscountHook->changeTotal=$case[1];
	$hookRow=writeOffReviewFixture($id,false,$case[0]);
	$beforeHook=array(countAuto('bank'),countAuto('paiement'),countAuto('kreabank_recon_audit'));
	$hookFailure=''; try { applyWriteOffFixture($hookRow); } catch(Throwable $e) { $hookFailure=$e->getMessage(); }
	checkAuto($nativeDiscountHook->calls===1 && $hookFailure===$case[2], 'A native hook cannot substitute another reason or hide a changed raw invoice residual');
	checkAuto($beforeHook===array(countAuto('bank'),countAuto('paiement'),countAuto('kreabank_recon_audit'))
		&& $service->getBulkReviewLine($id,'staged')['status']===0 && $db->depth===0
		&& (int)$db->query('SELECT fk_statut FROM kbauto_facture WHERE entity=1 AND rowid='.$id)->fetch_object()->fk_statut===1, 'Conflicting native hook closing rolls back its payment, invoice mutation and audit');
}
$hookmanager->hooks=array(); $hookmanager->hooksSorted=array(); $hookmanager->contextarray=array();
echo 'OK: '.($checks-$writeOffInitialChecks)." write-off transaction/ownership/reversal checks plus guarded regression suites\n";
