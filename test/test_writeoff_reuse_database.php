#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 * ONLY the unchanged guarded localhost:33317/kreabank_auto_test fixture.
 */
require __DIR__ . '/test_writeoff_database.php';
$writeOffReuseInitialChecks = $checks;

// Reuse the retained imported movement through an ordinary invoice payment.
// Imported movements intentionally cannot match themselves as native candidates.
$reuseId = 98400;
writeOffFixture($reuseId);
$db->query("UPDATE kbauto_facture SET ref='WRITEOFF-$reuseId' WHERE entity=1 AND rowid=$reuseId");
$writeOffRow = writeOffReviewFixture($reuseId);
checkAuto($writeOffRow && applyWriteOffFixture($writeOffRow) === $reuseId, 'The reuse fixture first records its explicitly reviewed write-off');
$completed = $history->find($reuseId, 'staged', 101);
$originalWriteOff = KreaBankWriteOff::undoSnapshotFromHistory($completed);
$retainedBankId = (int) $completed['native_bank_line_id'];
$originalPaymentId = (int) $originalWriteOff['writeoff']['payment_id'];
$bankCount = countAuto('bank');
$service->undoReconciliation($reuseId, 'Reverse first reviewed difference', KreaBankHistory::snapshot($completed));
checkAuto(countAuto('bank') === $bankCount && $service->getBulkReviewLine($reuseId, 'staged')['status'] === 0
	&& (int) $db->query("SELECT COUNT(*) n FROM kbauto_paiement WHERE entity=1 AND rowid=$originalPaymentId")->fetch_object()->n === 0,
	'Write-off undo removes its payment while retaining the original imported bank evidence');

$pending = $service->getBulkReviewLine($reuseId, 'staged');
$callbacks = kreabankManualReviewCallbacks($service, 3, 100);
$ordinaryRows = $callbacks['prepareDocumentReviews']($pending);
$ordinary = $ordinaryRows['customer_invoice__' . $reuseId] ?? null;
checkAuto($ordinary && empty($ordinary['writeoff']) && empty($ordinary['bank_rule']), 'The next ordinary invoice card has no write-off approval context');
checkAuto(kreabankExecuteManualReview($service, $ordinary) === $reuseId, 'Ordinary manual approval reuses the retained native movement');
$ordinaryCompleted = $history->find($reuseId, 'staged', 101);
checkAuto($ordinaryCompleted['strategy'] === 'bulk_manual' && !$ordinaryCompleted['needs_review']
	&& (int) $ordinaryCompleted['native_bank_line_id'] === $retainedBankId && countAuto('bank') === $bankCount,
	'History selects the newest ordinary reconciliation audit for the unchanged imported movement');
$ordinaryUndo = KreaBankHistory::snapshot($ordinaryCompleted);
$olderAudits = array();
for ($i=0; $i<1001; $i++) {
	$olderAudits[] = "(1,'reconcile',$reuseId,'{\"native_bank_line_id\":999999,\"strategy\":\"bulk_manual\"}',7,'2000-01-01 00:00:00')";
}
$db->query('INSERT INTO kbauto_kreabank_recon_audit(entity,audit_type,fk_statement_line,payload_json,fk_user,datec) VALUES ' . implode(',', $olderAudits));
checkAuto($service->writeOffUndoSnapshot($ordinaryCompleted) === null, 'A latest associated ordinary audit remains undoable even with more than 1000 older events');
checkAuto(empty($ordinaryUndo['writeoff']) && $service->writeOffUndoSnapshot($ordinaryCompleted) === null,
	'An older write-off audit cannot become the undo identity of a later ordinary reconciliation');
$paymentBefore = $db->query("SELECT * FROM kbauto_paiement WHERE entity=1 AND fk_bank=$retainedBankId")->fetch_all(MYSQLI_ASSOC);
checkAuto(count($paymentBefore) === 1 && (int) $paymentBefore[0]['rowid'] !== $originalPaymentId,
	'The current movement carries only its new ordinary native payment');
$auditsBefore = countAuto('kreabank_recon_audit');
$service->undoReconciliation($reuseId, 'Undo the later ordinary reconciliation', $ordinaryUndo);
checkAuto($service->getBulkReviewLine($reuseId, 'staged')['status'] === 0 && countAuto('bank') === $bankCount
	&& (int) $db->query("SELECT rappro FROM kbauto_bank WHERE rowid=$retainedBankId")->fetch_object()->rappro === 0,
	'Later ordinary undo restores pending and unreconciled status without deleting imported bank evidence');
checkAuto($paymentBefore === $db->query("SELECT * FROM kbauto_paiement WHERE entity=1 AND fk_bank=$retainedBankId")->fetch_all(MYSQLI_ASSOC)
	&& countAuto('kreabank_recon_audit') === $auditsBefore + 1,
	'Ordinary undo preserves its payment and records exactly one atomic audit');
$lastUndo = $db->query("SELECT payload_json FROM kbauto_kreabank_recon_audit WHERE entity=1 AND audit_type='undo' AND fk_statement_line=$reuseId ORDER BY datec DESC,rowid DESC LIMIT 1")->fetch_object();
$undoPayload = json_decode($lastUndo->payload_json, true, 32, JSON_THROW_ON_ERROR);
checkAuto($undoPayload['writeoff_reversed'] === false && empty($undoPayload['writeoff']),
	'The later ordinary undo audit confirms no stale write-off payment was reversed again');
echo 'OK: ' . ($checks - $writeOffReuseInitialChecks) . " write-off reuse/ordinary undo checks plus guarded regression suites\n";
