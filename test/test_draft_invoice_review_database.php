#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 * ONLY disposable localhost:33317/kreabank_auto_test; never an installation.
 */
require_once __DIR__.'/test_tax_reconciliation_database.php';
$draftChecksBefore = $checks;
$GLOBALS['draftInvoiceFixtureModules'] = array('invoice', 'supplier_invoice');
foreach (array('facturedet', 'facture_fourn_det', 'facture_extrafields', 'facture_fourn_extrafields',
	'c_payment_term', 'c_input_reason', 'c_incoterms', 'societe_extrafields', 'societe_remise', 'societe_remise_supplier', 'c_effectif', 'c_country',
	'c_stcomm', 'c_forme_juridique', 'c_departements', 'c_regions', 'c_typent', 'c_civility', 'product',
	'product_extrafields', 'facturedet_extrafields', 'facture_fourn_det_extrafields') as $table) {
	if ($db->num_rows($db->query("SHOW TABLES LIKE 'kbauto_".$table."'"))) { continue; }
	$sql = preg_replace('/--[^\n]*/', '', file_get_contents(DOL_DOCUMENT_ROOT.'/install/mysql/tables/llx_'.$table.'.sql'));
	foreach (explode(';', str_replace('llx_', MAIN_DB_PREFIX, $sql)) as $statement) { if (trim($statement) !== '') { $db->query($statement); } }
}
foreach (array('facture_fourn','facture','societe','product','facturedet','facture_fourn_det') as $element) {
	$extrafields->attributes[$element] = array('loaded'=>1, 'label'=>array());
}
$mysoc = (object) array('country_id'=>0);
function draftFixture($id, $supplier = true, $entity = 1, $status = 0, $type = 0)
{
	global $db;
	$table = $supplier ? 'facture_fourn' : 'facture';
	$detail = $supplier ? 'facture_fourn_det' : 'facturedet';
	$parent = $supplier ? 'fk_facture_fourn' : 'fk_facture';
	$referenceColumn = $supplier ? 'ref_supplier' : 'ref_client';
	$db->query("INSERT INTO kbauto_{$table}(rowid,entity,ref,{$referenceColumn},fk_soc,datef,total_ttc,total_ht,total_tva,fk_statut,type,multicurrency_code) VALUES ($id,$entity,'DRAFT-$id','SYNTHETIC-$id',1,'2026-11-03',125.50,125.50,0,$status,$type,'EUR')");
	$price = $supplier ? 'pu_ht' : 'subprice';
	$db->query("INSERT INTO kbauto_{$detail}({$parent},description,qty,{$price},total_ht,total_ttc,tva_tx,product_type) VALUES ($id,'Synthetic service',1,125.50,125.50,125.50,0,1)");
}
function draftReviewFixture($source, $type, $target)
{
	global $service;
	$line = $service->getBulkReviewLine($source,'staged');
	foreach ($service->getDraftInvoiceDocuments($line,120,'2026-11-03',30) as $doc) {
		if ($doc['doc_type'] === $type && $doc['rowid'] === $target) { return array($line, $doc); }
	}
	return null;
}
function validateDraftFixture($pair, $actor = null)
{
	global $service;
	list($line, $doc) = $pair;
	return ($actor ?: $service)->validateDraftInvoice($line['rowid'],'staged',$line['bank_account_id'],$doc['doc_type'],$doc['rowid'],$doc['draft_snapshot'],kreabankBulkReviewLineFacts($line));
}
draftFixture(97000); stageAuto(97000,'-125.50','DRAFT-97000');
$pair = draftReviewFixture(97000,'supplier_invoice',97000);
checkAuto($pair && $pair[1]['is_draft'], 'Supplier draft is visible as review-only');
$ordinary = $service->getOpenDocuments(-1,200,'2026-11-03',30,97000,101,false);
checkAuto(!array_filter($ordinary, static function ($doc) { return $doc['doc_type']==='supplier_invoice' && $doc['rowid']===97000; }), 'Ordinary matcher candidates exclude the draft');
$before = array(countAuto('paiementfourn'),countAuto('bank'),countAuto('kreabank_recon_audit'));
checkAuto(validateDraftFixture($pair)===97000, 'Native supplier draft validation succeeds');
checkAuto((int)$db->query('SELECT fk_statut FROM kbauto_facture_fourn WHERE rowid=97000')->fetch_object()->fk_statut===1, 'Native lifecycle validates the existing invoice');
checkAuto($before[0]===countAuto('paiementfourn') && $before[1]===countAuto('bank') && $service->getBulkReviewLine(97000,'staged')['status']===0, 'Validation creates no payment or movement and leaves source pending');
checkAuto($before[2]+1===countAuto('kreabank_recon_audit'), 'Validation audit commits with the invoice');
rejectAuto(static function () use ($pair) { validateDraftFixture($pair); }, 'A repeated validation cannot execute');
draftFixture(97001, false); stageAuto(97001,'125.50','DRAFT-97001');
$customer = draftReviewFixture(97001,'customer_invoice',97001);
checkAuto($customer && validateDraftFixture($customer)===97001, 'Native customer draft validation succeeds');
checkAuto((int)$db->query('SELECT fk_statut FROM kbauto_facture WHERE rowid=97001')->fetch_object()->fk_statut===1
	&& $service->getBulkReviewLine(97001,'staged')['status']===0, 'Customer invoice is validated while its statement remains pending');
// Parent/line edits, company ownership, source facts and duplicate requests fail closed.
draftFixture(97002); stageAuto(97002,'-125.50','DRAFT-97002');
$pair = draftReviewFixture(97002,'supplier_invoice',97002);
foreach (array("UPDATE kbauto_facture_fourn SET ref_supplier='CHANGED' WHERE rowid=97002",
	"UPDATE kbauto_facture_fourn_det SET description='CHANGED' WHERE fk_facture_fourn=97002",
	"UPDATE kbauto_facture_fourn SET entity=2 WHERE rowid=97002",
	"UPDATE kbauto_kreabank_statement_line SET amount=-126 WHERE rowid=97002") as $mutation) {
	$db->beforeBegin = static function () use ($db,$mutation) { $db->query($mutation); };
	rejectAuto(static function () use ($pair) { validateDraftFixture($pair); }, 'Locked review rejects a concurrent edit: '.$mutation);
	$db->query("UPDATE kbauto_facture_fourn SET ref_supplier='SYNTHETIC-97002',entity=1 WHERE rowid=97002");
	$db->query("UPDATE kbauto_facture_fourn_det SET description='Synthetic service' WHERE fk_facture_fourn=97002");
	$db->query('UPDATE kbauto_kreabank_statement_line SET amount=-125.50 WHERE rowid=97002');
	$pair = draftReviewFixture(97002,'supplier_invoice',97002);
}
$before = array(countAuto('kreabank_recon_audit'), countAuto('bank'), countAuto('paiementfourn'));
foreach (array('INSERT INTO kbauto_kreabank_recon_audit', 'UPDATE kbauto_facture_fourn SET ref=') as $failure) {
	$db->fail=$failure;
	rejectAuto(static function () use ($pair) { validateDraftFixture($pair); }, 'Audit/native validation write failure rolls back: '.$failure);
	$db->fail='';
	checkAuto($before === array(countAuto('kreabank_recon_audit'),countAuto('bank'),countAuto('paiementfourn'))
		&& (int)$db->query('SELECT fk_statut FROM kbauto_facture_fourn WHERE rowid=97002')->fetch_object()->fk_statut===0 && $db->depth===0, 'Failed validation preserves invoice, audit and bank state');
}
$denied = new class extends User {
	public function hasRight($module,$right,$subright='') { return !in_array($right,array('creer','invoice_advance','supplier_invoice_advance'),true) && $subright!=='creer'; }
};
$deniedService = new KreaBankService($db,$denied,$langs);
rejectAuto(static function () use ($pair,$deniedService) { validateDraftFixture($pair,$deniedService); }, 'Native invoice validation permission is enforced by the service');
$creationOnly = new class extends User { public function hasRight($module,$right,$subright='') { return $subright!=='validate'; } };
$creationOnlyService = new KreaBankService($db,$creationOnly,$langs);
checkAuto($creationOnlyService->canValidateDraftInvoice('supplier_invoice') && $creationOnlyService->canValidateDraftInvoice('customer_invoice'), 'Native creation rights permit validation without advanced permissions');
$conf->global->MAIN_USE_ADVANCED_PERMS=1;
checkAuto(!$creationOnlyService->canValidateDraftInvoice('supplier_invoice') && !$creationOnlyService->canValidateDraftInvoice('customer_invoice'), 'Advanced validation rights cannot be substituted with ordinary creation rights');
$conf->global->MAIN_USE_ADVANCED_PERMS=0;
foreach (array(array(97003,true,2,0,0),array(97004,true,1,0,2),array(97005,true,1,1,0),array(97006,true,1,2,0)) as $args) { draftFixture(...$args); }
$drafts=$service->getDraftInvoiceDocuments($pair[0],120,'2026-11-03',30);
$visible=array_column($drafts,'rowid');
checkAuto(!array_intersect(array(97003,97004,97005,97006),$visible), 'Foreign, credit-note, validated and closed invoices never enter the draft review');
$db->query("UPDATE kbauto_facture_fourn SET multicurrency_code='USD' WHERE rowid=97002");
checkAuto(!draftReviewFixture(97002,'supplier_invoice',97002), 'Foreign-currency drafts are excluded until a supported reconciliation contract exists');
$db->query("UPDATE kbauto_facture_fourn SET multicurrency_code='EUR' WHERE rowid=97002");
$pair=draftReviewFixture(97002,'supplier_invoice',97002);
$db->query("INSERT INTO kbauto_const(name,entity,value,type) VALUES ('KREABANK_ACCOUNT_PAIRS',1,'{\"101\":102}','chaine')");
rejectAuto(static function () use ($pair) { validateDraftFixture($pair); }, 'Restricted accounts do not validate invoice candidates');
$db->query("DELETE FROM kbauto_const WHERE name='KREABANK_ACCOUNT_PAIRS' AND entity=1");
$GLOBALS['draftInvoiceFixtureModules'][]='stock'; $conf->global->STOCK_CALCULATE_ON_SUPPLIER_BILL=1;
rejectAuto(static function () use ($pair) { validateDraftFixture($pair); }, 'Warehouse-dependent validation requires the native invoice options');
$conf->global->STOCK_CALCULATE_ON_SUPPLIER_BILL=0;
checkAuto(validateDraftFixture($pair)===97002, 'A fresh reviewed draft still validates after denied attempts');
echo 'OK: '.($checks-$draftChecksBefore)." draft invoice lifecycle/review checks plus guarded regression suites\n";
