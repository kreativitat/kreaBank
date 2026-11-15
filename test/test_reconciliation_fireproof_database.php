#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 * ONLY the guarded disposable 127.0.0.1:33317/kreabank_auto_test fixture.
 * Everyday mistakes that must never double-count the ledger, with native invoices/payments.
 */
require __DIR__ . '/test_writeoff_database.php';
require_once __DIR__ . '/../class/KreaBankTransferGuard.class.php';
$fireproofInitialChecks = $checks;
$scalar = static function ($sql) use ($db) { return $db->query($sql)->fetch_object()->n; };
$refused = static function ($callable) use ($db) {
	try { $callable(); } catch (Throwable $e) { $db->depth = 0; $db->connection->rollback(); return $e; }
	return null;
};
$toNative = static function ($line, $bank, $amount) use ($service) {
	return $service->reconcileLine($line, array(array('doc_type'=>'native_bank', 'fk_doc'=>$bank, 'allocated_amount'=>$amount, 'match_score'=>100, 'match_reasons'=>array('amount'))), 'manual');
};
$toInvoice = static function ($line, $invoice, $amount, $actor = null) use ($service) {
	return ($actor ?: $service)->reconcileLine($line, array(array('doc_type'=>'customer_invoice', 'fk_doc'=>$invoice, 'allocated_amount'=>$amount, 'match_score'=>100, 'match_reasons'=>array('amount'))), 'manual');
};
$status = static function ($line) use ($scalar) { return (int) $scalar('SELECT status n FROM kbauto_kreabank_statement_line WHERE rowid=' . (int) $line); };

// 1. Double submit of a quick entry creates one movement.
stageAuto(941001, '-33.00', 'FIRE-1');
$movements = countAuto('bank');
$service->createQuickEntryAndReconcile(941001, 'expense', 'Synthetic fee', '33.00');
checkAuto($refused(static function () use ($service) { $service->createQuickEntryAndReconcile(941001, 'expense', 'Synthetic fee', '33.00'); }) !== null
	&& countAuto('bank') === $movements + 1, 'A repeated quick entry creates no second movement');

// 2. One existing movement can settle only one statement line, even after a native un-reconcile.
stageAuto(941010, '77.00', 'FIRE-2A'); stageAuto(941011, '77.00', 'FIRE-2B'); bankAuto(941020, '77.00', 'FIRE-2');
$toNative(941010, 941020, 77);
checkAuto($refused(static function () use ($toNative) { $toNative(941011, 941020, 77); }) !== null, 'A reconciled movement is refused for a second line');
$db->query('UPDATE kbauto_bank SET rappro=0 WHERE rowid=941020'); // Cleared in the native screen, outside KreaBank.
checkAuto($refused(static function () use ($toNative) { $toNative(941011, 941020, 77); }) !== null && $status(941011) === 0
	&& (int) $scalar('SELECT COUNT(*) n FROM kbauto_kreabank_statement_line WHERE fk_native_bank_line=941020') === 1, 'A movement mapped to one line cannot be claimed by another after a native un-reconcile');
$offered = array_column(array_filter($service->getOpenDocuments(1, 250, '2026-11-01', 3, 0, 101, false), static function ($d) { return $d['doc_type'] === 'native_bank'; }), 'rowid');
checkAuto(!in_array(941020, array_map('intval', $offered), true), 'A movement mapped to a statement line is no longer offered as a candidate');
$db->query('UPDATE kbauto_bank SET rappro=1 WHERE rowid=941020');

// 3. One invoice is paid once.
writeOffFixture(941100); $db->query('UPDATE kbauto_kreabank_statement_line SET amount=100 WHERE rowid=941100');
stageAuto(941101, '100.00', 'FIRE-3B');
$payments = countAuto('paiement'); $movements = countAuto('bank');
$toInvoice(941100, 941100, 100);
// The first payment's movement belongs to line 941100, so it is distinct evidence for line 941101.
checkAuto($refused(static function () use ($toInvoice) { $toInvoice(941101, 941100, 100); }) !== null
	&& countAuto('paiement') === $payments + 1 && countAuto('bank') === $movements + 1 && $status(941101) === 0, 'A second line cannot pay the same invoice again');

// 4. Paying an invoice while a same-amount movement already exists needs an explicit review.
writeOffFixture(941200); $db->query('UPDATE kbauto_kreabank_statement_line SET amount=100 WHERE rowid=941200');
bankAuto(941210, '100.00', 'Manual receipt');
$payments = countAuto('paiement'); $movements = countAuto('bank');
$error = $refused(static function () use ($toInvoice) { $toInvoice(941200, 941200, 100); });
checkAuto($error instanceof KreaBankExistingMovementException && $error->movementIds === array(941210)
	&& countAuto('paiement') === $payments && countAuto('bank') === $movements && $status(941200) === 0
	&& (int) $scalar('SELECT paye n FROM kbauto_facture WHERE rowid=941200') === 0, 'An invoice payment is refused, with nothing created, while a possible existing movement is unreviewed');
$fresh = new KreaBankService($db, $user, $langs);
$fresh->allowConfirmedExistingMovements(941200, array(941211));
checkAuto($refused(static function () use ($toInvoice, $fresh) { $toInvoice(941200, 941200, 100, $fresh); }) instanceof KreaBankExistingMovementException, 'A confirmation of other movements does not apply');
bankAuto(941212, '100.00', 'Second manual receipt');
$fresh->allowConfirmedExistingMovements(941200, array(941210));
checkAuto($refused(static function () use ($toInvoice, $fresh) { $toInvoice(941200, 941200, 100, $fresh); }) instanceof KreaBankExistingMovementException, 'A confirmation is void once another movement appears');
$db->query('DELETE FROM kbauto_bank WHERE rowid=941212');
$fresh->assertNoExistingMovementForCreation(941200, array(941210));
$toInvoice(941200, 941200, 100, $fresh);
checkAuto(countAuto('paiement') === $payments + 1 && countAuto('bank') === $movements + 1 && $status(941200) === 2
	&& (int) $scalar("SELECT COUNT(*) n FROM kbauto_kreabank_recon_audit WHERE audit_type='existing_movement_override' AND fk_statement_line=941200") === 1,
	'After the exact audited confirmation the payment is created once');

// 5. Wrong existing-movement targets are refused and leave the line pending.
stageAuto(941300, '80.00', 'FIRE-5');
bankAuto(941310, '81.00', 'Other amount'); bankAuto(941311, '-80.00', 'Opposite'); bankAuto(941312, '80.00', 'Other account', 102); bankAuto(941313, '80.00', 'Reconciled');
$db->query('UPDATE kbauto_bank SET rappro=1 WHERE rowid=941313');
foreach (array(array(941310, 80), array(941310, 81), array(941311, 80), array(941312, 80), array(941313, 80), array(999999999, 80)) as $target) {
	checkAuto($refused(static function () use ($toNative, $target) { $toNative(941300, $target[0], $target[1]); }) !== null, 'Wrong native target #' . $target[0] . ' is refused');
}
checkAuto($status(941300) === 0 && (int) $scalar('SELECT COUNT(*) n FROM kbauto_bank WHERE rowid IN (941310,941311,941312) AND rappro=1') === 0, 'Refused targets leave the line pending and the movements untouched');

// 6. Lines outside the active company or on a closed account create nothing.
stageAuto(941500, '-12.00', 'FIRE-6', 202, 2); stageAuto(941501, '-13.00', 'FIRE-6B');
$movements = countAuto('bank');
checkAuto($refused(static function () use ($service) { $service->createQuickEntryAndReconcile(941500, 'expense', 'Synthetic', '12.00'); }) !== null, 'Another company line cannot receive a quick entry');
$db->query('UPDATE kbauto_bank_account SET clos=1 WHERE rowid=101');
checkAuto($refused(static function () use ($service) { $service->createQuickEntryAndReconcile(941501, 'expense', 'Synthetic', '13.00'); }) !== null && countAuto('bank') === $movements, 'A closed account receives no quick-entry movement');
$db->query('UPDATE kbauto_bank_account SET clos=0 WHERE rowid=101');

// 7. A quick entry always records exactly the reconciled line amount.
stageAuto(941600, '-50.00', 'FIRE-7'); stageAuto(941601, '-50.00', 'FIRE-7B');
checkAuto($refused(static function () use ($service) { $service->createQuickEntryAndReconcile(941601, 'expense', 'Synthetic', '49.00'); }) !== null
	&& $refused(static function () use ($service) { $service->createQuickEntryAndReconcile(941601, 'expense', 'Synthetic', '0'); }) !== null && $status(941601) === 0, 'A partial or zero quick entry is refused');
$entry = $service->createQuickEntryAndReconcile(941600, 'expense', 'Synthetic', '49.99');
checkAuto((string) $scalar('SELECT CAST(amount AS DECIMAL(24,2)) n FROM kbauto_kreabank_quick_entry WHERE rowid=' . (int) $entry) === '50.00'
	&& (string) $scalar('SELECT CAST(b.amount AS DECIMAL(24,2)) n FROM kbauto_bank b JOIN kbauto_kreabank_statement_line l ON l.fk_native_bank_line=b.rowid WHERE l.rowid=941600') === '-50.00',
	'A rounding-tolerance entry is stored with the exact line amount');
// Its movement is another line's evidence: the identical pending line 941601 is not blocked by it.
checkAuto($service->findExistingMovementsForCreation($service->getBulkReviewLine(941601, 'staged')) === array(), 'Two identical imported lines stay independent transactions');

// 8. Native internal transfer: a leg that repeats an existing movement needs review (the reported case).
$guard = new KreaBankTransferGuard($db, 1);
$day = static function ($date) { return strtotime($date . ' 12:00:00 UTC'); };
stageAuto(942001, '-15000.00', 'TRF IMEDIATA');
$service->createQuickEntryAndReconcile(942001, 'payment', 'Trf imediata', '15000.00');
$quickMovement = (int) $scalar('SELECT fk_native_bank_line n FROM kbauto_kreabank_statement_line WHERE rowid=942001');
$transfer = array('from'=>101, 'to'=>102, 'amount'=>'15000.00', 'amount_to'=>'', 'date'=>$day('2026-11-02'));
checkAuto($guard->existingMovements(array($transfer), 3) === array($quickMovement), 'A transfer leg repeating a reconciled imported movement is found');
checkAuto($guard->existingMovements(array(array_merge($transfer, array('date'=>$day('2026-11-05')))), 3) === array()
	&& $guard->existingMovements(array(array_merge($transfer, array('amount'=>'15000.01'))), 3) === array()
	&& $guard->existingMovements(array(array_merge($transfer, array('from'=>102, 'to'=>101))), 3) === array(), 'Another date, amount or direction is a different transfer');
bankAuto(942010, '15000.00', 'Existing receiving leg', 102, '2026-11-01');
checkAuto($guard->existingMovements(array($transfer), 3) === array($quickMovement, 942010), 'The receiving leg is reviewed too');
$db->query('UPDATE kbauto_bank SET fk_account=202 WHERE rowid=942010');
checkAuto($guard->existingMovements(array($transfer), 3) === array($quickMovement)
	&& (new KreaBankTransferGuard($db, 2))->existingMovements(array($transfer), 3) === array(), 'Another company is neither searched nor revealed');
bankAuto(942020, '-250.00', 'Entered transfer', 101, '2026-11-01'); bankAuto(942021, '250.00', 'Entered transfer', 102, '2026-11-01');
checkAuto($guard->existingMovements(array(array('from'=>101, 'to'=>102, 'amount'=>'250', 'amount_to'=>'250.00', 'date'=>$day('2026-11-01'))), 3) === array(942020, 942021), 'The same transfer entered twice is found on both legs');
$audits = countAuto('kreabank_recon_audit');
checkAuto($guard->auditOverride(7, array($transfer), array($quickMovement)) && countAuto('kreabank_recon_audit') === $audits + 1, 'A confirmed transfer override is audited');
checkAuto(countAuto('bank') === (int) $scalar('SELECT COUNT(*) n FROM kbauto_bank') && $db->depth === 0, 'The transfer review writes no movement and leaves no transaction open');

echo 'OK: ' . ($checks - $fireproofInitialChecks) . " everyday-mistake reconciliation database checks\n";
