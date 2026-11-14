#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Extends ONLY the disposable 127.0.0.1:33317/kreabank_auto_test fixture.
 * Never reads installation configuration or connects to an installation database.
 */
require __DIR__ . '/test_tpa_receipts_database.php';
require_once __DIR__ . '/../lib/kreabank_manual_review.lib.php';
$auditInitialChecks = $checks;

/** Bind synthetic imported evidence to its exact owned feed mapping. */
function auditFeedEvidence($id, $feedId, $account)
{
	global $db;
	$bank = $db->query('SELECT iban_prefix FROM kbauto_bank_account WHERE rowid=' . (int) $account)->fetch_object();
	$db->query("INSERT IGNORE INTO kbauto_kreabank_feed_account (rowid,entity,fk_bank_account,workspace_id,provider_account_id,provider_connection_id,iban,currency,status,datec) VALUES ("
		. (int) $feedId . ',1,' . (int) $account . ",'ws_audit_fixture','audit_account_" . (int) $feedId . "','audit_connection','" . $db->escape($bank->iban_prefix) . "','EUR','ready',NOW())");
	$row = $db->query('SELECT line_uid,amount,operation_date,currency FROM kbauto_kreabank_statement_line WHERE rowid=' . (int) $id)->fetch_object();
	$hash = hash('sha256', KreaBankFintableMapper::decimal($row->amount) . '|' . $row->operation_date . '|' . $row->currency);
	$db->query("UPDATE kbauto_kreabank_statement SET source_type='fintable' WHERE rowid=" . (int) $id);
	$db->query("INSERT INTO kbauto_kreabank_feed_transaction (rowid,entity,fk_feed_account,provider_transaction_id,source_uid,financial_hash,content_hash,fk_statement_line,source_payload,status,datec) VALUES ("
		. (int) $id . ',1,' . (int) $feedId . ",'audit_tx_" . (int) $id . "','" . $db->escape($row->line_uid) . "','$hash',SHA2('synthetic audit content',256)," . (int) $id . ",'{}','imported',NOW())");
}

// All three unattended paths must verify the same provider identity and mapping.
$db->query("UPDATE kbauto_c_paiement SET active=1 WHERE code='MB' AND entity=1");
$auto->configure($user, 'enabled', array(101,102,103), true, true, 'MB');
stageAuto(81000, '913.17', 'AUDIT-NATIVE-81000');
bankAuto(91000, '913.17', 'AUDIT-NATIVE-81000');
auditFeedEvidence(81000, 89000, 101);
pairFixture(81010, '914.17', 'AUDIT-PAIR-81010');
auditFeedEvidence(81010, 89001, 102);
auditFeedEvidence(81011, 89000, 101);
tpaFixture(81020, '915.17', 101, 1, 'fintable', 'FECHO TPA 0081000000 PERIODO N 81020');
auditFeedEvidence(81020, 89000, 101);
$auditEligibility = static function () use ($service) {
	return array(
		$service->getAutomaticNativeSuggestion(81000, array(101)),
		$service->getAutomaticTransferSuggestion(81010, array(101,102)),
		$service->getAutomaticTpaSuggestion(81020, array(101)),
	);
};
checkAuto(!in_array(null, $auditEligibility(), true), 'Exact imported identities are eligible for native, pair and TPA review');
foreach (array("status='mapping_review'", "status='unavailable'", 'fk_bank_account=103', "currency='USD'", 'entity=2') as $change) {
	$db->query('UPDATE kbauto_kreabank_feed_account SET ' . $change . ' WHERE rowid=89000');
	checkAuto($auditEligibility() === array(null,null,null), 'Changed feed account blocks every unattended path: ' . $change);
	$db->query("UPDATE kbauto_kreabank_feed_account SET status='ready',fk_bank_account=101,currency='EUR',entity=1 WHERE rowid=89000");
}
foreach (array("source_uid='other-source'", "financial_hash=SHA2('changed evidence',256)", "status='review'", "conflict_payload='{}'", 'fk_feed_account=999999') as $change) {
	$db->query('UPDATE kbauto_kreabank_feed_transaction SET ' . $change . ' WHERE rowid IN (81000,81011,81020)');
	checkAuto($auditEligibility() === array(null,null,null), 'Changed source identity blocks every unattended path: ' . $change);
	$db->query("UPDATE kbauto_kreabank_feed_transaction ft JOIN kbauto_kreabank_statement_line l ON l.rowid=ft.fk_statement_line SET ft.source_uid=l.line_uid,ft.financial_hash=SHA2(CONCAT(TRIM(TRAILING '.' FROM TRIM(TRAILING '0' FROM CAST(l.amount AS CHAR))),'|',l.operation_date,'|',l.currency),256),ft.status='imported',ft.conflict_payload=NULL,ft.fk_feed_account=89000 WHERE ft.rowid IN (81000,81011,81020)");
}
checkAuto(!in_array(null, $auditEligibility(), true), 'Restored exact evidence restores eligibility without changing financial records');
$db->query("INSERT INTO kbauto_kreabank_feed_transaction (entity,fk_feed_account,provider_transaction_id,source_uid,financial_hash,content_hash,fk_statement_line,source_payload,status,datec) SELECT entity,fk_feed_account,'audit_duplicate_tx',source_uid,financial_hash,content_hash,fk_statement_line,source_payload,status,NOW() FROM kbauto_kreabank_feed_transaction WHERE rowid=81000");
checkAuto($service->getAutomaticNativeSuggestion(81000, array(101)) === null, 'Multiple provider identities cannot authorize the same staged source');
$db->query("UPDATE kbauto_kreabank_feed_transaction SET fk_feed_account=999999 WHERE provider_transaction_id='audit_duplicate_tx'");
checkAuto($service->getAutomaticNativeSuggestion(81000, array(101)) === null, 'An orphan duplicate provider identity cannot disappear from uniqueness checks');
$groupRejected = false;
try { $service->listBatchCandidates(81000); } catch (DomainException $error) {
	$groupRejected = $error->getMessage() === 'Bank feed source changed or needs conflict review';
}
checkAuto($groupRejected, 'Grouped review also rejects mixed valid and orphan provider identities');
$db->query("DELETE FROM kbauto_kreabank_feed_transaction WHERE provider_transaction_id='audit_duplicate_tx'");

// An eligibility preview must not survive a mapping change immediately before locks.
$auditBankCount = countAuto('bank'); $auditCount = countAuto('kreabank_recon_audit');
$db->beforeBegin = static function () use ($db) { $db->query("UPDATE kbauto_kreabank_feed_account SET status='mapping_review' WHERE rowid=89000"); };
rejectAuto(static function () use ($service) { $service->reconcileAutomaticNativeLine(81000, array(101)); }, 'Native execution revalidates feed mapping after preview');
$db->query("UPDATE kbauto_kreabank_feed_account SET status='ready' WHERE rowid=89000");
$db->beforeBegin = static function () use ($db) { $db->query("UPDATE kbauto_kreabank_feed_transaction SET source_uid='changed-opposite-leg' WHERE rowid=81011"); };
checkAuto(!$service->reconcileAutomaticTransferPair(81010, array(101,102)), 'Pair execution revalidates the opposite imported leg under locks');
$db->query("UPDATE kbauto_kreabank_feed_transaction SET source_uid='fixture-81011' WHERE rowid=81011");
$db->beforeBegin = static function () use ($db) { $db->query("UPDATE kbauto_kreabank_feed_account SET status='unavailable' WHERE rowid=89000"); };
checkAuto(!$service->reconcileAutomaticTpaReceipt(81020, array(101)), 'TPA execution revalidates provider mapping before creating a receipt');
$db->query("UPDATE kbauto_kreabank_feed_account SET status='ready' WHERE rowid=89000");
checkAuto(countAuto('bank') === $auditBankCount && countAuto('kreabank_recon_audit') === $auditCount && $db->depth === 0,
	'Feed evidence changes leave native movements and audit untouched');

/** Expose the real providers without duplicating their query implementation. */
class AuditSafetyService extends KreaBankService
{
	public function openPayments($direction, $date = '2026-11-01', $days = 3) { return $this->getOpenPaymentDocuments($direction, 1000, $date, $days); }
	public function openInvoices($direction) { return $this->getOpenInvoiceDocuments($direction, 1000, '2026-11-01', 3); }
}
$auditService = new AuditSafetyService($db, $user, $langs);
foreach (array(array('paiement',82000,0),array('paiementfourn',82001,0),array('paiement',82002,92002),array('paiementfourn',82003,92003)) as $fixture) {
	list($table,$id,$bankId) = $fixture;
	if ($bankId) { bankAuto($bankId, $table === 'paiement' ? '919.19' : '-919.19', 'AUDIT-BOUNDARY-' . $id, 101, '2026-11-04'); }
	$db->query("INSERT INTO kbauto_$table (rowid,entity,ref,datec,datep,amount,fk_bank,fk_paiement) VALUES ($id,1,'AUDIT-PAYMENT-$id',NOW(),'2026-11-04 23:59:59',919.19,$bankId,1)");
}
foreach (array(1=>array(82000,82002),-1=>array(82001,82003)) as $direction=>$ids) {
	$open = $auditService->openPayments($direction);
	$linked = $auditService->getLinkedPaymentDocuments($direction,1000,'2026-11-01',3,null,101,false);
	checkAuto(in_array($ids[0],array_column($open,'rowid'),true), 'Unbanked payment includes the complete final tolerance day, direction ' . $direction);
	checkAuto(in_array($ids[1],array_column($linked,'rowid'),true), 'Linked payment includes the complete final tolerance day, direction ' . $direction);
	checkAuto(in_array($ids[0],array_column($auditService->openPayments($direction,'2026-11-04',0),'rowid'),true), 'Zero-day tolerance includes payment times on the anchor day, direction ' . $direction);
}
$db->query("UPDATE kbauto_paiement SET datep='2026-11-05 00:00:00' WHERE rowid IN (82000,82002)");
$db->query("UPDATE kbauto_paiementfourn SET datep='2026-11-05 00:00:00' WHERE rowid IN (82001,82003)");
foreach (array(1=>array(82000,82002),-1=>array(82001,82003)) as $direction=>$ids) {
	checkAuto(!in_array($ids[0],array_column($auditService->openPayments($direction),'rowid'),true), 'Unbanked payment excludes the following midnight, direction ' . $direction);
	checkAuto(!in_array($ids[1],array_column($auditService->getLinkedPaymentDocuments($direction,1000,'2026-11-01',3,null,101,false),'rowid'),true), 'Linked payment excludes the following midnight, direction ' . $direction);
}

// A failed provider must not look like an empty successful candidate set.
foreach (array(1,-1) as $direction) {
	$db->fail = 'SELECT p.rowid, p.ref';
	rejectAuto(static function () use ($auditService,$direction) { $auditService->openPayments($direction); }, 'Unbanked candidate SQL failure is visible, direction ' . $direction);
	rejectAuto(static function () use ($auditService,$direction) { $auditService->getLinkedPaymentDocuments($direction,1000,'2026-11-01',3,null,101,false); }, 'Linked candidate SQL failure is visible, direction ' . $direction);
	$db->fail = 'SELECT f.rowid, f.ref';
	rejectAuto(static function () use ($auditService,$direction) { $auditService->openInvoices($direction); }, 'Invoice candidate SQL failure is visible, direction ' . $direction);
	$db->fail = '';
}
$db->fail = 'SELECT p.rowid, p.ref';
rejectAuto(static function () use ($auditService) { $auditService->getOpenDocuments(1,1000,'2026-11-01',3,0,101,false); }, 'A failed provider prevents a partial combined candidate result');
$db->fail = '';

// The wider manual fallback must propagate a provider failure to controller handlers.
$failedFallback = new class {
	public function getPaymentAccountReview($id,$accounts) { return null; }
	public function getSuggestionsForLines($lines,$score,$days,$interval,$linked) { return array($lines[0]['rowid']=>array()); }
	public function getLinkedPaymentDocuments($direction,$limit,$date,$days,$amount,$account,$reconciled) { throw new RuntimeException('Synthetic provider failure'); }
};
$auditCallbacks = kreabankManualReviewCallbacks($failedFallback,3,100,false);
$auditLine = $service->getBulkReviewLine(81000,'staged');
rejectAuto(static function () use ($auditCallbacks,$auditLine) { ($auditCallbacks['prepareReviewRow'])($auditLine); }, 'Manual fallback SQL failure cannot become an empty successful review');
echo 'OK: ' . ($checks - $auditInitialChecks) . " module audit safety database checks\n";
