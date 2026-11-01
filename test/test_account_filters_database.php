#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 * ONLY the disposable database guarded by the automatic reconciliation suite.
 */
require __DIR__ . '/test_automatic_reconciliation_database.php';
require_once __DIR__ . '/../lib/kreabank_integration.lib.php';
$conf->entity = 1;
$checks = 0;
$db->query("INSERT INTO kbauto_bank_account (rowid,entity,ref,label,currency_code,clos,courant,rappro,fk_pays) VALUES
 (301,1,'F_CASH','Cash fixture','EUR',0,2,1,1),
 (302,1,'F_CLOSED','Closed fixture','EUR',1,1,1,1),
 (303,1,'F_BANK','Bank fixture','EUR',0,1,1,1),
 (401,2,'X_CASH','Other company cash','EUR',0,2,1,1),
 (402,2,'X_CLOSED','Other company closed','EUR',1,1,1,1)");
foreach (array(9001 => array(301, 1), 9002 => array(302, 1), 9003 => array(303, 1), 9004 => array(401, 2), 9005 => array(402, 2)) as $id => $scope) {
	stageAuto($id, '25.00', 'FILTER-' . $id, $scope[0], $scope[1]);
	bankAuto($id, '25.00', 'FILTER-' . $id, $scope[0]);
}
$service = new KreaBankService($db, $user, $langs);
$pending = $service->getPendingLines(1, 0, 'operation_date', 'ASC', array('bank_account_id' => 301));
checkAuto(count($pending) === 1 && (int) $pending[0]['bank_account_id'] === 301, 'Operational account filter applies before the pending page limit');
checkAuto($service->getPendingLinesCount(array('bank_account_id' => 301)) === 1, 'Pending count agrees with the selected account');
checkAuto($service->getPendingLinesCount(array('bank_account_id' => 302)) === 0, 'Closed accounts remain ineligible for reconciliation');
checkAuto($service->getPendingLinesCount(array('bank_account_id' => 401)) === 0, 'Selecting an account never grants foreign-company visibility');
$report = new KreaBankIntegration($db, 1);
foreach (array('cash' => 301, 'closed' => 302) as $kind => $accountId) {
	$accounts = $report->accounts($kind);
	checkAuto($db->num_rows($accounts) === 1 && (int) $db->fetch_object($accounts)->rowid === $accountId, 'Account overview keeps ' . $kind . ' within the current company');
	$statements = $report->query($report->statementsSql($accountId));
	checkAuto($db->num_rows($statements) === 1 && (int) $db->fetch_object($statements)->bank_account_id === $accountId, 'Statement rows and counts respect ' . $kind . ' ownership');
}
checkAuto(!$report->statement(301, 'ST-9003'), 'A direct statement reference cannot bypass the selected account');
checkAuto(!$report->statement(401, 'ST-9004'), 'Direct statement references remain company-scoped');
$db->query('UPDATE kbauto_kreabank_statement_line SET status=2,fk_native_bank_line=rowid WHERE rowid BETWEEN 9001 AND 9005');
$db->query('UPDATE kbauto_bank SET rappro=1 WHERE rowid BETWEEN 9001 AND 9005');
foreach (array(9001 => array(301, 1), 9002 => array(302, 1), 9003 => array(303, 1), 9004 => array(401, 2)) as $id => $scope) {
	$db->query("INSERT INTO kbauto_kreabank_bankmeta(entity,fk_bank_line,bank_account_id,idempotency_hash,amount,currency,status,datec) VALUES(" . $scope[1] . ',' . $id . ',' . $scope[0] . ",SHA2('filter-" . $id . "',256),25,'EUR',2,NOW())");
}
foreach (array(301 => 9001, 302 => 9002) as $accountId => $lineId) {
	$history = $service->getRecentReconciliations(1, 0, $accountId);
	checkAuto(count($history) === 1 && (int) $history[0]['native_bank_line_id'] === $lineId, 'Native history applies selected-account ownership before its limit');
}
$allAccountIds = array();
$allAccounts = $report->accounts('all');
while ($account = $db->fetch_object($allAccounts)) { $allAccountIds[] = (int) $account->rowid; }
checkAuto(in_array(301, $allAccountIds, true) && in_array(302, $allAccountIds, true) && in_array(303, $allAccountIds, true), 'All account types include bank, cash and closed accounts');
checkAuto(!in_array(401, $allAccountIds, true) && !in_array(402, $allAccountIds, true), 'All account types stay inside the active owner company');
$before = countAuto('bank');
$report->query($report->statementsSql());
$report->accounts('all');
checkAuto(countAuto('bank') === $before, 'Filter reads never create native movements');
print "OK: $checks account filter database checks\n";
