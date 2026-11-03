#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Extends ONLY the disposable 127.0.0.1:33317/kreabank_auto_test fixture.
 */
require __DIR__ . '/test_automatic_reconciliation_database.php';
require_once __DIR__ . '/../lib/kreabank_bulk.lib.php';
$initialChecks = $checks;
function reviewedFixture($id, $bankId, $source = 'staged') {
	global $service;
	$line = $service->getBulkReviewLine($id, $source);
	$suggestions = $service->getSuggestionsForLines(array($line), 0, 3, null, false)[$id] ?? array();
	foreach ($suggestions as $suggestion) {
		if ($suggestion['doc_type'] === 'native_bank' && $suggestion['doc_id'] === $bankId) {
			return array('line' => $line, 'suggestion' => $suggestion, 'allocation' => abs($line['amount']));
		}
	}
	throw new RuntimeException('Synthetic reviewed pair missing');
}
function applyReviewedFixture($row, $serviceOverride = null) {
	global $service;
	$target = $serviceOverride ?: $service;
	return $target->reconcileLine($row['line']['rowid'], array(array('doc_type' => $row['suggestion']['doc_type'],
		'fk_doc' => $row['suggestion']['doc_id'], 'allocated_amount' => $row['allocation'],
		'doc_ref' => $row['suggestion']['doc_ref'], 'match_reasons' => $row['suggestion']['details'])),
		'bulk_manual', 0, 'Reviewed synthetic pair', $row['suggestion']['score'], null, $row);
}
stageAuto(9000, '-47.66', ''); bankAuto(19000, '-47.66', '');
$row = reviewedFixture(9000, 19000);
checkAuto($row['suggestion']['score'] === 100 && $service->getSafeSuggestion(array($row['suggestion']), 100) === null, 'Amount/date-only pair remains ineligible for automatic approval');
checkAuto($service->getAutomaticNativeSuggestion(9000, array(101)) === null, 'Automatic matching stays strict for the same reviewed pair');
$beforeBank = countAuto('bank'); $beforeAudit = countAuto('kreabank_recon_audit');
foreach (array('INSERT INTO kbauto_kreabank_recon_audit', 'UPDATE kbauto_kreabank_statement_line', 'UPDATE kbauto_bank SET') as $failure) {
	$db->fail = $failure;
	rejectAuto(static function () use ($row) { applyReviewedFixture($row); }, 'Reviewed write failure is visible: ' . $failure);
	$db->fail = '';
	checkAuto((int) $db->query('SELECT rappro FROM kbauto_bank WHERE rowid=19000')->fetch_object()->rappro === 0
		&& countAuto('kreabank_recon_audit') === $beforeAudit && $service->getBulkReviewLine(9000, 'staged')['status'] === 0,
		'Reviewed native state, staging and audit roll back together');
}
checkAuto(applyReviewedFixture($row) === 9000, 'Explicit manual approval reconciles a 100-point pair through the existing service');
checkAuto($service->getBulkReviewLine(9000, 'staged')['status'] === 2
	&& (int) $db->query('SELECT rappro FROM kbauto_bank WHERE rowid=19000')->fetch_object()->rappro === 1,
	'Reviewed success persists both native and staged reconciliation');
checkAuto(countAuto('bank') === $beforeBank && countAuto('kreabank_recon_audit') === $beforeAudit + 1, 'Manual native matching creates no movement and exactly one audit');
$audit = json_decode($db->query('SELECT payload_json FROM kbauto_kreabank_recon_audit ORDER BY rowid DESC LIMIT 1')->fetch_object()->payload_json, true);
checkAuto($audit['strategy'] === 'bulk_manual' && $audit['is_auto'] === 0, 'Audit distinguishes manual approval from unattended matching');
rejectAuto(static function () use ($row) { applyReviewedFixture($row); }, 'Duplicate submission cannot reconcile twice');
stageAuto(9001, '-31.45', ''); bankAuto(19001, '-31.45', '');
$changedRow = reviewedFixture(9001, 19001);
$db->beforeBegin = static function () use ($db) { $db->query("UPDATE kbauto_kreabank_statement_line SET description='Changed after review' WHERE rowid=9001"); };
rejectAuto(static function () use ($changedRow) { applyReviewedFixture($changedRow); }, 'Concurrent source edits invalidate approval under the row lock');
checkAuto((int) $db->query('SELECT rappro FROM kbauto_bank WHERE rowid=19001')->fetch_object()->rappro === 0, 'Changed review leaves the candidate unreconciled');
$row = reviewedFixture(9001, 19001);
$db->beforeBegin = static function () use ($db) { $db->query('UPDATE kbauto_bank SET amount=31.45 WHERE rowid=19001'); };
rejectAuto(static function () use ($row) { applyReviewedFixture($row); }, 'Concurrent sign reversal is rejected under the candidate lock');
$db->query('UPDATE kbauto_bank SET amount=-31.45 WHERE rowid=19001');
$row = reviewedFixture(9001, 19001);
$bad = clone $user; $bad->allowed = false;
$deniedService = new KreaBankService($db, $bad, $langs);
rejectAuto(static function () use ($row, $deniedService) { applyReviewedFixture($row, $deniedService); }, 'Manual bulk writes require current bank and module permissions');
checkAuto($service->getBulkReviewLine(5, 'staged') === null && $service->getBulkReviewLine(15, 'native') === null, 'Both namespaces reject another company');
// Legacy imported native IDs must never update unrelated staging rows with the same ID.
stageAuto(9002, '-19.23', ''); bankAuto(9002, '-19.23', ''); bankAuto(19002, '-19.23', '');
$db->query("INSERT INTO kbauto_kreabank_bankmeta (entity,fk_bank_line,bank_account_id,amount,currency,status,source_type,idempotency_hash,datec) VALUES (1,9002,101,-19.23,'EUR',0,'csv',SHA2('legacy-review-fixture',256),NOW())");
$legacy = reviewedFixture(9002, 19002, 'native');
checkAuto(applyReviewedFixture($legacy) === 9002, 'Legacy native imports can be explicitly reviewed');
checkAuto((int) $db->query('SELECT status FROM kbauto_kreabank_statement_line WHERE rowid=9002')->fetch_object()->status === 0
	&& (int) $db->query('SELECT status FROM kbauto_kreabank_bankmeta WHERE fk_bank_line=9002')->fetch_object()->status === 2,
	'Legacy completion marks its import metadata and preserves a colliding staging ID');
echo 'OK: ' . ($checks - $initialChecks) . " manual bulk reconciliation database checks\n";
