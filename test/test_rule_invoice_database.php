#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 * ONLY the unchanged guarded localhost:33317/kreabank_auto_test fixture.
 * Native invoice/payment classes perform all financial persistence.
 */
require __DIR__ . '/test_draft_invoice_review_database.php';
require_once __DIR__ . '/../lib/kreabank_manual_review.lib.php';
$ruleInvoiceInitialChecks = $checks;
$GLOBALS['draftInvoiceFixtureModules'] = array('invoice', 'supplier_invoice');
$conf->global->MAIN_DISABLE_PDF_AUTOUPDATE = 1;
$conf->modules_parts['models'] = array(DOL_DOCUMENT_ROOT . '/');
$conf->modules_parts['hooks'] = array();
require_once DOL_DOCUMENT_ROOT . '/core/class/hookmanager.class.php';
$hookmanager = new HookManager($db);
foreach (array('paiement', 'paiementfourn', 'payment', 'payment_supplier') as $element) {
	$extrafields->attributes[$element] = array('loaded'=>1, 'label'=>array());
}
// The guarded fixture deliberately does not load functions.lib.php. Supply its
// dictionary lookup boundary with a real, company-scoped query, never a fake ID.
if (!function_exists('dol_getIdFromCode')) {
	function dol_getIdFromCode($db, $key, $table, $field = 'code', $id = 'id', $entity = 0, $filters = '', $cache = true)
	{
		if ($table !== 'c_paiement' || $field !== 'code' || $id !== 'id' || $entity !== 1 || $filters !== '') {
			throw new RuntimeException('Unexpected fixture payment dictionary lookup');
		}
		$result = $db->query('SELECT id FROM ' . MAIN_DB_PREFIX . "c_paiement WHERE entity=" . (int) $GLOBALS['conf']->entity
			. " AND code='" . $db->escape($key) . "'");
		$row = $result ? $db->fetch_object($result) : null;
		return $row ? (int) $row->id : 0;
	}
}
foreach (array('paiement_extrafields') as $table) {
	$sql = preg_replace('/--[^\n]*/', '', file_get_contents(DOL_DOCUMENT_ROOT . '/install/mysql/tables/llx_' . $table . '.sql'));
	foreach (explode(';', str_replace('llx_', MAIN_DB_PREFIX, $sql)) as $statement) {
		if (trim($statement) !== '') { $db->query($statement); }
	}
}
$schema = preg_replace('/--[^\n]*/', '', file_get_contents(__DIR__ . '/../sql/llx_kreabank_rule.sql'));
foreach (explode(';', str_replace('llx_', MAIN_DB_PREFIX, $schema)) as $statement) {
	if (trim($statement) !== '') { $db->query($statement); }
}
$db->query("INSERT INTO kbauto_societe(rowid,entity,nom,fournisseur,client) VALUES (98300,1,'Synthetic rule counterparty',1,1)");
$rules = new KreaBankRule($db, $user, 1);

/** Prepare the same signed per-card row as reconcile.php, without an ERP bootstrap. */
function ruleInvoiceReviewFixture($id, $type)
{
	global $service;
	$line = $service->getBulkReviewLine($id, 'staged');
	$callbacks = kreabankManualReviewCallbacks($service, 3, 100);
	return $callbacks['prepareDocumentReviews']($line)[$type . '__' . $id] ?? null;
}

foreach (array(false, true) as $supplier) {
	$id = $supplier ? 98302 : 98301;
	$type = $supplier ? 'supplier_invoice' : 'customer_invoice';
	$table = $supplier ? 'facture_fourn' : 'facture';
	$paymentTable = $supplier ? 'paiementfourn' : 'paiement';
	$linkTable = $supplier ? 'paiementfourn_facturefourn' : 'paiement_facture';
	$paymentKey = $supplier ? 'fk_paiementfourn' : 'fk_paiement';
	$invoiceKey = $supplier ? 'fk_facturefourn' : 'fk_facture';
	$reference = 'RULE-INVOICE-' . $id;
	draftFixture($id, $supplier, 1, 1, 0);
	$db->query("UPDATE kbauto_{$table} SET fk_soc=98300,multicurrency_total_ttc=125.50,multicurrency_tx=1,fk_mode_reglement=1 WHERE entity=1 AND rowid=$id");
	stageAuto($id, $supplier ? '-125.50' : '125.50', $reference);
	$ruleId = $rules->save(array('label'=>'Synthetic ' . $type . ' search', 'active'=>1, 'priority'=>100,
		'fk_bank_account'=>101, 'direction'=>$supplier ? 'out' : 'in',
		'conditions'=>array('all'=>array(array('field'=>'description', 'operator'=>'contains', 'value'=>$reference))),
		'action'=>array('type'=>'invoice_search', 'thirdparty_id'=>98300, 'invoice_type'=>$type)));
	$row = ruleInvoiceReviewFixture($id, $type);
	checkAuto($row && (int) $row['bank_rule']['id'] === $ruleId && $row['bank_rule_target']['amount_open'] === '125.5'
		&& (int) $row['bank_rule_target']['thirdparty_id'] === 98300, 'Per-card ' . $type . ' review binds the exact rule and invoice facts');
	$before = array(countAuto($paymentTable), countAuto('bank'), countAuto('bank_url'), countAuto('kreabank_recon_audit'));
	$db->beforeBegin = static function () use ($db, $table, $id) {
		$db->query("UPDATE kbauto_{$table} SET total_ttc=126.50 WHERE entity=1 AND rowid=$id");
	};
	rejectAuto(static function () use ($service, $row) { kreabankExecuteManualReview($service, $row); }, 'Locked ' . $type . ' approval rejects a changed invoice amount');
	checkAuto($before === array(countAuto($paymentTable), countAuto('bank'), countAuto('bank_url'), countAuto('kreabank_recon_audit'))
		&& $service->getBulkReviewLine($id, 'staged')['status'] === 0 && $db->depth === 0,
		'Changed ' . $type . ' rejection creates no payment, native movement, mapping or audit');
	$db->query("UPDATE kbauto_{$table} SET total_ttc=125.50 WHERE entity=1 AND rowid=$id");
	$row = ruleInvoiceReviewFixture($id, $type);
	$db->fail = 'INSERT INTO kbauto_kreabank_recon_audit';
	rejectAuto(static function () use ($service, $row) { kreabankExecuteManualReview($service, $row); }, 'Rule invoice audit failure rejects ' . $type . ' approval');
	$db->fail = '';
	$rule = null;
	foreach ($rules->all() as $candidate) { if ((int) $candidate['rowid'] === $ruleId) { $rule = $candidate; } }
	$invoice = $db->query("SELECT paye,fk_statut FROM kbauto_{$table} WHERE entity=1 AND rowid=$id")->fetch_object();
	checkAuto($before === array(countAuto($paymentTable), countAuto('bank'), countAuto('bank_url'), countAuto('kreabank_recon_audit'))
		&& (int) $rule['hit_count'] === 0 && (int) $invoice->paye === 0 && (int) $invoice->fk_statut === 1
		&& $service->getBulkReviewLine($id, 'staged')['status'] === 0 && $db->depth === 0,
		'Failed ' . $type . ' audit rolls back native payment, paid state, source and rule usage');
	checkAuto(kreabankExecuteManualReview($service, $row) === $id, 'Approved ' . $type . ' rule uses the existing manual reconciliation executor');
	$completed = $service->getBulkReviewLine($id, 'staged');
	$payment = $db->query("SELECT p.rowid,p.fk_bank,p.amount,pl.amount AS allocation FROM kbauto_{$paymentTable} p"
		. " JOIN kbauto_{$linkTable} pl ON pl.{$paymentKey}=p.rowid JOIN kbauto_{$table} f ON f.rowid=pl.{$invoiceKey}"
		. " WHERE p.entity=1 AND f.entity=1 AND f.rowid=$id")->fetch_object();
	$invoice = $db->query("SELECT paye,fk_statut FROM kbauto_{$table} WHERE entity=1 AND rowid=$id")->fetch_object();
	checkAuto($payment && (int) $payment->fk_bank === (int) $completed['native_bank_line_id']
		&& KreaBankDecimal::compare((string) $payment->amount, '125.50') === 0
		&& KreaBankDecimal::compare((string) $payment->allocation, '125.50') === 0
		&& (int) $invoice->paye === 1 && (int) $invoice->fk_statut === 2 && $completed['status'] === 2,
		'Native ' . $type . ' payment allocates the exact amount, attaches to the bank line and closes its paid invoice');
	$rule = null;
	foreach ($rules->all() as $candidate) { if ((int) $candidate['rowid'] === $ruleId) { $rule = $candidate; } }
	$audit = $db->query("SELECT payload_json FROM kbauto_kreabank_recon_audit WHERE entity=1 AND audit_type='reconcile' AND fk_statement_line=$id ORDER BY rowid DESC LIMIT 1")->fetch_object();
	$payload = json_decode($audit->payload_json, true, 32, JSON_THROW_ON_ERROR);
	checkAuto((int) $rule['hit_count'] === 1 && $rule['last_used'] !== null && countAuto($paymentTable) === $before[0] + 1
		&& countAuto('bank') === $before[1] + 1 && countAuto('kreabank_recon_audit') === $before[3] + 1
		&& (int) $payload['bank_rule']['id'] === $ruleId && $payload['bank_rule_target'] === $row['bank_rule_target']
		&& $payload['strategy'] === 'bulk_manual' && $payload['is_auto'] === 0,
		'Approved ' . $type . ' payment, source, rule hit and exact reviewed audit commit together');
	$after = array(countAuto($paymentTable), countAuto('bank'), countAuto('kreabank_recon_audit'));
	rejectAuto(static function () use ($service, $row) { kreabankExecuteManualReview($service, $row); }, 'A completed ' . $type . ' rule review cannot be replayed');
	checkAuto($after === array(countAuto($paymentTable), countAuto('bank'), countAuto('kreabank_recon_audit')), 'Repeated ' . $type . ' approval preserves completed financial state');
}
echo 'OK: ' . ($checks - $ruleInvoiceInitialChecks) . " invoice-rule lifecycle/review checks plus guarded regression suites\n";
