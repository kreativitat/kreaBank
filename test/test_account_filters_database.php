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
$pending = $service->getPendingLines(1, 0, 'operation_date', 'ASC', array('account_kind' => 'cash'));
checkAuto(count($pending) === 1 && (int) $pending[0]['bank_account_id'] === 301, 'Cash filter is applied before the pending page limit and excludes foreign cash');
checkAuto($service->getPendingLinesCount(array('account_kind' => 'cash')) === 1, 'Pending count agrees with the filtered page');
checkAuto($service->getPendingLinesCount(array('account_kind' => 'closed')) === 0, 'Closed accounts remain ineligible for reconciliation');
checkAuto($service->getPendingLinesCount(array('account_kind' => 'cash', 'bank_account_id' => 303)) === 0, 'Conflicting account and type filters cannot expose another account');
checkAuto($service->getPendingLinesCount(array('account_kind' => 'cash', 'bank_account_id' => 401)) === 0, 'A type filter never grants foreign-account visibility');
$report = new KreaBankIntegration($db, 1);
foreach (array('cash' => 301, 'closed' => 302) as $kind => $accountId) {
	$accounts = $report->accounts($kind);
	checkAuto($db->num_rows($accounts) === 1 && (int) $db->fetch_object($accounts)->rowid === $accountId, 'Account overview keeps ' . $kind . ' within the current company');
	$statements = $report->query($report->statementsSql(0, $kind));
	checkAuto($db->num_rows($statements) === 1 && (int) $db->fetch_object($statements)->bank_account_id === $accountId, 'Statement rows and counts respect ' . $kind . ' ownership');
}
checkAuto(!$report->statement(303, 'ST-9003', 'cash'), 'A direct statement reference cannot bypass the selected type');
checkAuto(!$report->statement(401, 'ST-9004', 'cash'), 'Direct statement references remain company-scoped');
$db->query('UPDATE kbauto_kreabank_statement_line SET status=2,fk_native_bank_line=rowid WHERE rowid BETWEEN 9001 AND 9005');
$db->query('UPDATE kbauto_bank SET rappro=1 WHERE rowid BETWEEN 9001 AND 9005');
foreach (array(9001 => array(301, 1), 9002 => array(302, 1), 9003 => array(303, 1), 9004 => array(401, 2)) as $id => $scope) {
	$db->query("INSERT INTO kbauto_kreabank_bankmeta(entity,fk_bank_line,bank_account_id,idempotency_hash,amount,currency,status,datec) VALUES(" . $scope[1] . ',' . $id . ',' . $scope[0] . ",SHA2('filter-" . $id . "',256),25,'EUR',2,NOW())");
}
foreach (array('cash' => 9001, 'closed' => 9002) as $kind => $lineId) {
	$history = $service->getRecentReconciliations(1, 0, 0, $kind);
	checkAuto(count($history) === 1 && (int) $history[0]['native_bank_line_id'] === $lineId, 'Native history applies ' . $kind . ' and ownership before its limit');
}
stageAuto(9011, '22.00', 'FILTER-LEGACY', 301);
$db->query('UPDATE kbauto_kreabank_statement_line SET status=2 WHERE rowid=9011');
class FilterHistoryAdapter extends KreaBankNativeBankAdapter
{
	public function legacyHistory($kind) { return $this->getRecentReconciledLinesFromStaging(10, 0, 0, $kind); }
}
$adapter = new FilterHistoryAdapter($db, $user, $langs, 1);
$legacy = $adapter->legacyHistory('cash');
checkAuto(count($legacy) === 1 && (int) $legacy[0]['fk_statement_line'] === 9011, 'Legacy history uses the same cash and owner-company predicates');
$bankLegacyIds = array_map(static function ($row) { return (int) $row['fk_statement_line']; }, $adapter->legacyHistory('bank'));
checkAuto(!in_array(9011, $bankLegacyIds, true), 'Legacy bank history excludes the cash fixture');
$before = countAuto('bank');
$report->query($report->statementsSql(0, 'all'));
$report->accounts('all');
checkAuto(countAuto('bank') === $before, 'Filter reads never create native movements');
print "OK: $checks account filter database checks\n";
