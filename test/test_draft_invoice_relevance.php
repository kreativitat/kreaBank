#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Pure relevance and synthetic service checks; no application bootstrap or database.
 */
require_once __DIR__ . '/../class/KreaBankDraftInvoiceReview.class.php';
$checks = 0;
$check = static function ($ok, $label) use (&$checks) { if (!$ok) { throw new RuntimeException($label); } $checks++; };
$line = array('rowid'=>11, 'fk_statement'=>1, 'bank_account_id'=>101, 'entity'=>1, 'status'=>0, 'allocated_amount'=>'0', 'amount'=>'-49.46000000',
	'currency'=>'EUR', 'description'=>'DIRECT DEBIT North Market Supplies Ltd', 'operation_date'=>'2026-10-01');
$header = (object) array('entity'=>1, 'fk_statut'=>0, 'paye'=>0, 'type'=>0, 'total_ttc'=>'49.46000000', 'multicurrency_code'=>'EUR',
	'ref'=>'(PROV501)', 'ref_supplier'=>'INV2026/00123', 'datef'=>'2026-10-01', 'review_thirdparty'=>'North Market Supplies Ltd');
$relevant = static function ($l, $h, $type = 'supplier_invoice') { return KreaBankDraftInvoiceReview::relevant($l,$h,$type,1,'EUR'); };
$check($relevant($line,$header), 'Exact amount and complete counterparty permit manual draft advice');
foreach (array(
	'amount'=>'-1.68', 'allocated_amount'=>'0.01', 'status'=>2, 'entity'=>2, 'currency'=>'USD',
	'description'=>'MEAL CARD TRANSFER SYNTHETIC-FEE-99',
) as $key=>$value) { $check(!$relevant(array_replace($line,array($key=>$value)),$header), 'Irrelevant or ineligible source denied: ' . $key); }
foreach (array('entity'=>2, 'fk_statut'=>1, 'paye'=>1, 'type'=>2, 'total_ttc'=>'49.46000001', 'multicurrency_code'=>'USD') as $key=>$value) {
	$check(!$relevant($line,(object) array_replace((array) $header,array($key=>$value))), 'Changed invoice facts denied: ' . $key);
}
$refLine = array_replace($line,array('description'=>'PAYMENT INV2026/00123'));
$check($relevant($refLine,$header), 'Complete bounded supplier reference is relevant without a payee name');
foreach (array('PAYMENT XINV2026/00123','PAYMENT INV2026/001234','PAYMENT PROV501','PAYMENT 2026-10-01','North Market') as $description) {
	$check(!$relevant(array_replace($line,array('description'=>$description)),$header), 'Partial, provisional and date evidence denied: ' . $description);
}
foreach (array('(PROV12345678)','12345678','ABC','2026-10-01','invoice') as $reference) {
	$h = clone $header; $h->ref=$reference; $h->ref_supplier=$reference;
	$check(!$relevant(array_replace($line,array('description'=>'PAYMENT ' . $reference)),$h), 'Generic references do not authorize advice');
}
$customer = clone $header; $customer->ref_client='CUS2026/00123';
$check($relevant(array_replace($line,array('amount'=>'49.46')),$customer,'customer_invoice'), 'Customer drafts require an inflow');
$check(!$relevant($line,$customer,'customer_invoice'), 'Supplier outflow cannot authorize a customer draft');
$check(!$relevant(array_replace($line,array('amount'=>'-4.946e1')),$header), 'Exponent/float-coercible inputs fail closed');

// Exercise the real provider and the locked validation rejection, with SELECT-only fake evidence.
function isModEnabled($key) { return true; }
function getDolGlobalInt($key) { return 0; }
function getDolGlobalString($key) { return ''; }
define('DOL_DOCUMENT_ROOT', getenv('KREABANK_DOLIBARR_ROOT') ?: dirname(__DIR__, 3));
require_once __DIR__ . '/../class/KreaBankService.class.php';
require_once __DIR__ . '/../lib/kreabank_bulk.lib.php';
$conf = (object) array('entity'=>1, 'currency'=>'EUR');
class DraftRelevanceDb {
	public $rollbacks=0; public $queries=array();
	public function prefix() { return 'llx_'; }
	public function escape($value) { return addslashes((string) $value); }
	public function query($sql) { if (strpos($sql,'SELECT ') !== 0) { throw new RuntimeException('Unexpected write'); } $this->queries[]=$sql; return (object) array('rows'=>strpos($sql, 'SELECT value FROM') === 0 ? array() : array((object) array('rowid'=>11))); }
	public function fetch_object($result) { return array_shift($result->rows); }
	public function begin() { return true; }
	public function rollback() { $this->rollbacks++; }
}
class DraftRelevanceService extends KreaBankService {
	public $line; public $header; public $locked=false;
	public function __construct($line,$header) {
		$this->line=$line; $this->header=$header; $this->entity=1; $this->db=new DraftRelevanceDb();
		$this->user=new class { public function hasRight(...$path) { return true; } };
		$this->langs=new KreaBankNullLangs();
	}
	protected function bankRuleLineEvidence($line) { return $line; }
	public function getOpenInvoiceDocuments($direction=0,$limit=120,$anchorDate=null,$intervalDays=null,$draftOnly=false,$search='',$thirdpartyId=0) {
		return array(array('rowid'=>501,'doc_type'=>'supplier_invoice','amount_open'=>$this->header->total_ttc));
	}
	protected function draftInvoiceSnapshots($type,$ids,$lock=false) { if ($lock && !$this->locked) { throw new RuntimeException('Source was not locked'); } return array(501=>array('header'=>$this->header,'snapshot'=>str_repeat('a',64))); }
	public function canValidateDraftInvoice($type) { return true; }
	public function getBulkReviewLine($id,$source) { return $this->line; }
	protected function lockReconciliationRow($table,$id) { $this->locked=true; }
}
$service = new DraftRelevanceService($line,$header);
$check(count($service->getDraftInvoiceDocuments($line))===1, 'Real provider retains a relevant draft');
$fee = array_replace($line,array('amount'=>'-1.68','description'=>'MEAL CARD TRANSFER SYNTHETIC-FEE-99'));
$check($service->getDraftInvoiceDocuments($fee)===array(), 'Real provider rejects nearby unrelated drafts for a bank fee');
$service->line=$fee;
$failed=false;
try { $service->validateDraftInvoice(11,'staged',101,'supplier_invoice',501,str_repeat('a',64),kreabankBulkReviewLineFacts($fee)); }
catch (Exception $e) { $failed=strpos($e->getMessage(),'no longer matches')!==false; }
$check($failed && $service->locked && $service->db->rollbacks===1, 'A valid old snapshot cannot bypass locked relevance revalidation');
echo "OK: $checks draft invoice relevance checks (synthetic, no database)\n";
