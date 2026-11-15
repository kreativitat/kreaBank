#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 * ONLY the guarded disposable 127.0.0.1:33317/kreabank_auto_test fixture.
 */
require __DIR__ . '/test_automatic_reconciliation_database.php';
$initialChecks = $checks;
$scalar = static function ($sql) use ($db) { return $db->query($sql)->fetch_object()->n; };
$find = static function ($id) use ($service) { return $service->findExistingMovementsForCreation($service->getBulkReviewLine($id, 'staged')); };

// Reported case: an imported debit and an existing native transfer leg of the same amount.
stageAuto(931001, '-15000.00', 'TRF IMEDIATA', 101);
checkAuto($find(931001) === array(), 'A line without a possible existing movement may create one');
bankAuto(931010, '-15000.00', '', 101, '2026-11-02');
checkAuto($find(931001) === array(931010), 'An existing same-account movement with the exact amount and nearby date is found');
$db->query('UPDATE kbauto_bank SET rappro=1 WHERE rowid=931010');
checkAuto($find(931001) === array(931010), 'A reconciled existing movement still requires review');
$db->query('UPDATE kbauto_bank SET rappro=0 WHERE rowid=931010');

$entries = (int) $scalar('SELECT COUNT(*) n FROM kbauto_kreabank_quick_entry');
$movements = countAuto('bank');
$audits = countAuto('kreabank_recon_audit');
$caught = null;
try { $service->createQuickEntryAndReconcile(931001, 'payment', 'Synthetic transfer', '15000.00'); } catch (KreaBankExistingMovementException $e) { $caught = $e; }
checkAuto($caught && $caught->movementIds === array(931010) && $caught->complete, 'Quick entry is refused and names the existing movement');
checkAuto((int) $scalar('SELECT COUNT(*) n FROM kbauto_kreabank_quick_entry') === $entries && countAuto('bank') === $movements
	&& countAuto('kreabank_recon_audit') === $audits
	&& (int) $scalar('SELECT status n FROM kbauto_kreabank_statement_line WHERE rowid=931001') === 0, 'A refused quick entry creates no entry, movement or audit and leaves the line pending');
rejectAuto(static function () use ($service) { $service->assertNoExistingMovementForCreation(931001, array(931011)); }, 'Confirming another movement does not override');

// Evidence that must not block.
$db->query("UPDATE kbauto_bank SET dateo='2026-11-05' WHERE rowid=931010");
checkAuto($find(931001) === array(), 'A movement outside the date tolerance is not a possible duplicate');
$db->query("UPDATE kbauto_bank SET dateo='2026-11-02',amount=-15000.01 WHERE rowid=931010");
checkAuto($find(931001) === array(), 'A different amount is not a possible duplicate');
$db->query('UPDATE kbauto_bank SET amount=15000.00 WHERE rowid=931010');
checkAuto($find(931001) === array(), 'The opposite direction is not a possible duplicate');
$db->query('UPDATE kbauto_bank SET amount=-15000.00,fk_account=102 WHERE rowid=931010');
checkAuto($find(931001) === array(), 'A movement in another owned account is not searched here');
$db->query('UPDATE kbauto_bank SET fk_account=202 WHERE rowid=931010');
checkAuto($find(931001) === array(), 'Another company cannot block or reveal a movement');
$db->query('UPDATE kbauto_bank SET fk_account=101 WHERE rowid=931010');
stageAuto(931002, '-15000.00', 'OTHER LINE', 101);
$db->query('UPDATE kbauto_kreabank_statement_line SET fk_native_bank_line=931010,status=2 WHERE rowid=931002');
checkAuto($find(931001) === array(), 'The movement of another imported line is distinct bank evidence');
$db->query('UPDATE kbauto_kreabank_statement_line SET fk_native_bank_line=NULL,status=0 WHERE rowid=931002');
checkAuto($find(931001) === array(931010) && $find(931002) === array(931010), 'Unmapped movements block every matching pending line');

// Exact confirmation is audited; it authorizes nothing but the creation attempt.
$service->assertNoExistingMovementForCreation(931001, array(931010));
$audit = $db->query("SELECT fk_statement_line,payload_json FROM kbauto_kreabank_recon_audit WHERE audit_type='existing_movement_override' ORDER BY rowid DESC LIMIT 1")->fetch_object();
checkAuto($audit && (int) $audit->fk_statement_line === 931001 && json_decode($audit->payload_json, true)['confirmed_movement_ids'] === array(931010), 'The exact confirmation is audited');

// Statement deletion: reconciled check, deletion and audit share one locked transaction.
stageAuto(931003, '41.00', 'DELETE ME', 101);
$db->query('UPDATE kbauto_kreabank_statement_line SET status=2 WHERE rowid=931003');
rejectAuto(static function () use ($service) { $service->deleteImportedStatement(101, 'ST-931003'); }, 'A statement with a reconciled line is not deleted');
checkAuto((int) $scalar('SELECT COUNT(*) n FROM kbauto_kreabank_statement_line WHERE rowid=931003') === 1, 'The blocked statement keeps its lines');
$db->query('UPDATE kbauto_kreabank_statement_line SET status=0 WHERE rowid=931003');
$db->fail = 'INSERT INTO kbauto_kreabank_recon_audit';
rejectAuto(static function () use ($service) { $service->deleteImportedStatement(101, 'ST-931003'); }, 'A deletion without its audit fails');
$db->fail = '';
checkAuto((int) $scalar('SELECT COUNT(*) n FROM kbauto_kreabank_statement WHERE rowid=931003') === 1
	&& (int) $scalar('SELECT COUNT(*) n FROM kbauto_kreabank_statement_line WHERE rowid=931003') === 1, 'A failed audit rolls the deletion back');
$audits = (int) $scalar("SELECT COUNT(*) n FROM kbauto_kreabank_recon_audit WHERE audit_type='delete_statement'");
rejectAuto(static function () use ($service) { $service->deleteImportedStatement(202, 'ST-931003'); }, 'Another company account cannot delete the statement');
$result = $service->deleteImportedStatement(101, 'ST-931003');
checkAuto((int) $result['deleted_lines'] === 1 && (int) $scalar('SELECT COUNT(*) n FROM kbauto_kreabank_statement WHERE rowid=931003') === 0
	&& (int) $scalar("SELECT COUNT(*) n FROM kbauto_kreabank_recon_audit WHERE audit_type='delete_statement'") === $audits + 1, 'A pending statement is deleted with exactly one audit');
checkAuto($db->depth === 0, 'Statement deletion leaves no open transaction');

// Table checks run once per deployed code version, not on every request.
$created = static function () use ($db) { return (int) $db->query("SHOW SESSION STATUS LIKE 'Com_create_table'")->fetch_object()->Value; };
checkAuto(getDolGlobalString('KREABANK_SCHEMA_FINGERPRINT') === KreaBankService::schemaFingerprint()
	&& $scalar("SELECT value n FROM kbauto_const WHERE entity=0 AND name='KREABANK_SCHEMA_FINGERPRINT'") === KreaBankService::schemaFingerprint(), 'The first verified construction stores the code version globally');
unset($conf->global->KREABANK_SCHEMA_FINGERPRINT);
$before = $created();
new KreaBankService($db, $user, $langs);
checkAuto($created() > $before, 'An unverified code version still checks its tables');
checkAuto(getDolGlobalString('KREABANK_SCHEMA_FINGERPRINT') === KreaBankService::schemaFingerprint(), 'Completed table checks record the code version');
$before = $created();
$verified = new KreaBankService($db, $user, $langs);
checkAuto($created() === $before, 'A verified code version issues no table DDL');
checkAuto($verified->findExistingMovementsForCreation($verified->getBulkReviewLine(931001, 'staged')) === array(931010), 'A verified service remains fully usable');
$conf->global->KREABANK_SCHEMA_FINGERPRINT = 'stale';
$before = $created();
new KreaBankService($db, $user, $langs);
checkAuto($created() > $before, 'A changed code version checks its tables again');
unset($conf->global->KREABANK_SCHEMA_FINGERPRINT);

echo 'OK: ' . ($checks - $initialChecks) . " existing-movement, statement-deletion and schema-check database checks\n";
