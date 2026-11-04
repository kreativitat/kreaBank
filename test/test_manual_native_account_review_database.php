#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Extends ONLY the guarded 127.0.0.1:33317/kreabank_auto_test fixture.
 */
require __DIR__ . '/test_payment_account_review_database.php';
require_once __DIR__ . '/../lib/kreabank_manual_review.lib.php';
$initialChecks = $checks;

// Separate invoices/payments of the same supplier/amount in two owned accounts.
accountReviewFixture(77000, true);
bankAuto(79000, '-231.47', 'PAYMENT-77000-20261101', 101);
$db->query("INSERT INTO kbauto_facture_fourn(rowid,ref,ref_supplier,entity,fk_soc,datec,total_ttc,fk_statut,paye,type) VALUES(77001,'INVOICE-77001','SUPPLIER-77001',1,1,NOW(),231.47,1,1,0)");
$db->query("INSERT INTO kbauto_paiementfourn(rowid,entity,ref,datec,datep,amount,fk_bank,fk_paiement) VALUES(77001,1,'PAY-77001',NOW(),'2026-11-01',231.47,79000,1)");
$db->query('INSERT INTO kbauto_paiementfourn_facturefourn(fk_paiementfourn,fk_facturefourn,amount) VALUES(77001,77001,231.47)');
$db->query("INSERT INTO kbauto_bank_url(fk_bank,url_id,url,label,type) VALUES(79000,77001,'/synthetic/','(paiement)','payment_supplier')");
$line = $service->getBulkReviewLine(77000,'staged');
$callbacks = kreabankManualReviewCallbacks($service,3,100);
$row = $callbacks['prepareDocumentReviews']($line)['native_bank__79000'];
checkAuto($row['approval_context']==='selected_native' && $row['other_account_warning'] && !$row['preselected'],
	'An exact same-account movement gets an explicit review with the other-account warning');
checkAuto($callbacks['prepareReviewRow']($line)===null && $service->getAutomaticNativeSuggestion(77000,array(101))===null,
	'Matched and unattended matching remain blocked by the same payment evidence');
$bulk = $row; unset($bulk['approval_context'],$bulk['other_account_warning']);
rejectAuto(static function () use ($bulk) { applyReviewedFixture($bulk); }, 'Ordinary bulk approval cannot use the selected-native exception');

function preservedOtherPayment() {
	global $db;
	$result = array();
	foreach (array('bank'=>'rowid=78000','bank_url'=>'fk_bank=78000','paiementfourn'=>'rowid=77000',
		'paiementfourn_facturefourn'=>'fk_paiementfourn=77000','facture_fourn'=>'rowid=77000') as $table=>$where) {
		$result[$table] = $db->query('SELECT * FROM kbauto_'.$table.' WHERE '.$where)->fetch_all(MYSQLI_ASSOC);
	}
	return $result;
}
$otherBefore = preservedOtherPayment();
$counts = array(countAuto('bank'),countAuto('paiementfourn'),countAuto('kreabank_recon_audit'));
foreach (array('bank_account_id'=>102,'amount_open'=>-231.48,'doc_type'=>'supplier_invoice') as $field=>$value) {
	$bad = $row; $bad['suggestion'][$field] = $value;
	rejectAuto(static function () use ($bad) { applyReviewedFixture($bad); }, 'The manual exception rejects invalid targets: '.$field);
}
$bad = $row; $bad['approval_context']='unknown';
rejectAuto(static function () use ($bad) { applyReviewedFixture($bad); }, 'Unknown approval contexts are rejected');
$bad = $row; $bad['other_account_warning']=false;
rejectAuto(static function () use ($bad) { applyReviewedFixture($bad); }, 'A warning appearing before the locked approval requires a fresh review');
foreach (array('INSERT INTO kbauto_kreabank_recon_audit','UPDATE kbauto_kreabank_statement_line','UPDATE kbauto_bank SET') as $failure) {
	$db->fail = $failure;
	rejectAuto(static function () use ($row) { applyReviewedFixture($row); }, 'Manual warning approval rolls back write failures: '.$failure);
	$db->fail = '';
	checkAuto((int)$db->query('SELECT rappro FROM kbauto_bank WHERE rowid=79000')->fetch_object()->rappro===0
		&& $service->getBulkReviewLine(77000,'staged')['status']===0
		&& countAuto('kreabank_recon_audit')===$counts[2] && preservedOtherPayment()===$otherBefore,
		'Rollback preserves the selected movement, source, audit and other-account payment');
}
$db->beforeBegin = static function () use ($db) { $db->query('UPDATE kbauto_bank SET fk_account=102 WHERE rowid=79000'); };
rejectAuto(static function () use ($row) { applyReviewedFixture($row); }, 'A concurrent target account change is rejected under locks');
$db->query('UPDATE kbauto_bank SET fk_account=101 WHERE rowid=79000');
$denied = clone $user; $denied->allowed=false;
$deniedService = new KreaBankService($db,$denied,$langs);
rejectAuto(static function () use ($row,$deniedService) { applyReviewedFixture($row,$deniedService); }, 'Permission loss rejects the selected-native exception');
checkAuto(applyReviewedFixture($row)===77000, 'Explicit manual approval reconciles the chosen existing same-account movement');
checkAuto($service->getBulkReviewLine(77000,'staged')['status']===2
	&& (int)$db->query('SELECT rappro FROM kbauto_bank WHERE rowid=79000')->fetch_object()->rappro===1,
	'The selected source and native movement reconcile atomically');
checkAuto(preservedOtherPayment()===$otherBefore, 'The other bank movement, payment, invoice and allocations are byte-for-byte unchanged');
checkAuto(countAuto('bank')===$counts[0] && countAuto('paiementfourn')===$counts[1]
	&& countAuto('kreabank_recon_audit')===$counts[2]+1, 'Manual approval creates only its reconciliation audit');
$audit=json_decode($db->query('SELECT payload_json FROM kbauto_kreabank_recon_audit ORDER BY rowid DESC LIMIT 1')->fetch_object()->payload_json,true);
checkAuto($audit['strategy']==='bulk_manual' && $audit['is_auto']===0
	&& $audit['approval_context']==='selected_native' && $audit['other_account_warning']===true,
	'The atomic audit records explicit approval with the warning retained');
rejectAuto(static function () use ($row) { applyReviewedFixture($row); }, 'A repeated approval cannot reconcile twice');
echo 'OK: '.($checks-$initialChecks)." selected native movement with account-warning database checks\n";
