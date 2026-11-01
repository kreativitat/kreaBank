#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Uses ONLY the disposable local database guarded by the automatic reconciliation suite.
 */
require __DIR__ . '/test_automatic_reconciliation_database.php';
require_once __DIR__ . '/../lib/kreabank_integration.lib.php';
$conf->entity = 1;
$checks = 0;
stageAuto(501, '25.00');
stageAuto(502, '25.00');
stageAuto(503, '25.00', 'FOREIGN', 202, 2);
bankAuto(501, '25.00');
bankAuto(502, '25.00');
bankAuto(503, '25.00', 'FOREIGN', 202);
$db->query("UPDATE kbauto_bank SET rappro=1,num_releve='ST-502' WHERE rowid=502");
$db->query('UPDATE kbauto_kreabank_statement_line SET status=2,fk_native_bank_line=502 WHERE rowid=502');
$db->query("UPDATE kbauto_bank SET num_releve='LEGACY-INTEGRATION',note='KreaBank import fixture' WHERE rowid=501");
$db->query("INSERT INTO kbauto_kreabank_bankmeta(entity,fk_bank_line,bank_account_id,idempotency_hash,amount,currency,status,datec) VALUES(1,502,101,SHA2('integration-fixture',256),25,'EUR',2,NOW())");
$report = new KreaBankIntegration($db, 1);
$before = countAuto('bank');
$pending = $report->statement(101, 'ST-501');
checkAuto($pending && (int) $pending->imported_lines === 1 && (int) $pending->native_lines === 0, 'Pending staging stays visible alongside native import history');
$matched = $report->statement(101, 'ST-502');
checkAuto((int) $matched->native_lines === 1 && (int) $matched->reconciled_lines === 1 && (int) $matched->issue_lines === 0, 'Staged and native metadata for the same movement count once');
checkAuto($report->statement(101, 'LEGACY-INTEGRATION') !== false, 'Legacy native imports remain visible together with staging');
checkAuto(!$report->statement(202, 'ST-503'), 'Foreign account evidence is denied');
checkAuto(!$report->statement(102, 'ST-502'), 'Same-company account context cannot cross accounts');
$rows = $report->query($report->linesSql(101, 'ST-502'));
$row = $db->fetch_object($rows);
checkAuto($db->num_rows($rows) === 1 && (int) $row->native_id === 502 && kreabankIntegrationLineState($row, 'ST-502') === 'KreaBankReconciled', 'Statement links resolve native namespace and verified state');
checkAuto($db->num_rows($report->query('SELECT b.rowid FROM kbauto_bank b WHERE b.rowid=502' . $report->matchedWhereSql())) === 1, 'Native list filter includes the actual reconciled movement');
checkAuto($db->num_rows($report->query('SELECT b.rowid FROM kbauto_bank b WHERE b.rowid=503' . $report->matchedWhereSql())) === 0, 'Native list filter cannot expose foreign-company claims');
checkAuto(!$report->statement(101, "ST-502' OR 1=1 --"), 'Statement reference is escaped');
$db->query('UPDATE kbauto_bank SET amount=-25 WHERE rowid=502');
$row = $db->fetch_object($report->query($report->linesSql(101, 'ST-502')));
checkAuto(kreabankIntegrationLineState($row, 'ST-502') === 'KreaBankLinkNeedsReview' && (int) $report->statement(101, 'ST-502')->issue_lines === 1 && (int) $report->statement(101, 'ST-502')->reconciled_lines === 0, 'Signed amount mismatch is visible instead of a success label');
$db->query("UPDATE kbauto_bank SET amount=25,num_releve='OTHER' WHERE rowid=502");
checkAuto((int) $report->statement(101, 'ST-502')->issue_lines === 1, 'Native statement-number mismatch is detected');
$db->query("UPDATE kbauto_bank SET num_releve='ST-502' WHERE rowid=502");
$db->query("UPDATE kbauto_kreabank_statement_line SET currency='USD' WHERE rowid=502");
$row = $db->fetch_object($report->query($report->linesSql(101, 'ST-502')));
checkAuto(kreabankIntegrationLineState($row, 'ST-502') === 'KreaBankLinkNeedsReview' && (int) $report->statement(101, 'ST-502')->reconciled_lines === 0, 'Currency mismatch cannot count as verified');
$db->query("UPDATE kbauto_kreabank_statement_line SET currency='EUR' WHERE rowid=502");
$db->query('UPDATE kbauto_bank SET amount=25,rappro=0 WHERE rowid=502');
checkAuto((int) $report->statement(101, 'ST-502')->issue_lines === 1, 'Native flag mismatch is detected');
$db->query('UPDATE kbauto_bank SET rappro=1,fk_account=202 WHERE rowid=502');
checkAuto((int) $report->statement(101, 'ST-502')->native_lines === 0 && (int) $report->statement(101, 'ST-502')->issue_lines === 1, 'Wrong-account native mapping is not exposed');
$db->query('UPDATE kbauto_bank SET fk_account=101 WHERE rowid=502');
$db->query('UPDATE kbauto_kreabank_statement_line SET fk_native_bank_line=999999 WHERE rowid=502');
$row = $db->fetch_object($report->query($report->linesSql(101, 'ST-502')));
checkAuto(kreabankIntegrationLineState($row, 'ST-502') === 'KreaBankLinkNeedsReview', 'Missing native movement remains a visible review issue');
$service = new KreaBankService($db, $user, $langs);
$legacyRead = $service->ensureNativeStatementLines(101, 'ST-501');
checkAuto($legacyRead['created_count'] === 0 && countAuto('bank') === $before, 'Legacy open helper does not materialize statements');
rejectAuto(static function () use ($report) { $report->query('UPDATE kbauto_bank SET amount=0'); }, 'Report interface refuses mutations');
$accountRows = $report->accounts('bank');
$accountIds = array();
while ($accountRow = $db->fetch_object($accountRows)) { $accountIds[] = (int) $accountRow->rowid; }
checkAuto(in_array(101,$accountIds,true) && !in_array(202,$accountIds,true), 'Account overview shows owner-company accounts only');
print "OK: $checks bank integration database checks\n";
