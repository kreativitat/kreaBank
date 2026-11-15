#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

// Synthetic only: reuses the quick-flow stubs; no ERP bootstrap and no database.
require_once __DIR__ . '/test_reconciliation_quickflows_runtime.php';

class KreaBankExistingMovementFakeDb extends KreaBankQuickFlowFakeDb
{
	public $movementIds = array();
	private $pending = array();

	public function query($sql)
	{
		$this->queries[] = (string) $sql;
		$this->pending = (strpos($sql, 'SELECT b.rowid FROM llx_bank b') === 0) ? $this->movementIds : array();
		return true;
	}

	public function fetch_object($result)
	{
		if (!$this->pending) { return false; }
		return (object) array('rowid' => array_shift($this->pending));
	}

	public function plimit($limit = 0, $offset = 0) { return ' LIMIT ' . (int) $limit; }
}

class KreaBankExistingMovementServiceStub extends KreaBankService
{
	public $audits = array();
	public $auditResult = true;

	public function __construct() {}

	protected function logAudit($type, $lineId = null, $reconciliationId = null, $payload = array())
	{
		$this->audits[] = array($type, $lineId, $payload);
		return $this->auditResult;
	}
}

$checks = 0;
$check = static function ($condition, $label) use (&$checks) {
	if (!$condition) { throw new RuntimeException($label); }
	$checks++;
};
$blocked = static function ($service, $line, $confirmed = array()) {
	try { $service->assertNoExistingMovementForCreation($line, $confirmed); } catch (KreaBankExistingMovementException $e) { return $e; }
	return null;
};

$db = new KreaBankExistingMovementFakeDb();
$service = new KreaBankExistingMovementServiceStub();
$service->db = $db;
$service->user = (object) array('id' => 1);
kreabankSetProtectedProperty($service, 'entity', 7);
// The reported case: imported -15000.00 on an account that already holds a transfer leg.
$line = array('rowid' => 19, 'fk_statement' => 4, 'native_bank_line_id' => 0, 'bank_account_id' => 3,
	'amount' => '-15000.00000000', 'operation_date' => '2026-10-01', 'currency' => 'EUR');

$db->movementIds = array();
$check($blocked($service, $line) === null, 'no existing movement must allow creation');
$sql = end($db->queries);
$check(strpos($sql, 'ba.entity=7') !== false && strpos($sql, 'b.fk_account=3') !== false, 'search is scoped to the owner company and account');
$check(strpos($sql, 'CAST(b.amount AS DECIMAL(24,8))=-15000') !== false, 'search uses the exact signed persisted amount');
$check(strpos($sql, "b.dateo>='2026-09-28'") !== false && strpos($sql, "b.dateo<'2026-10-05'") !== false, 'search covers the calendar-day tolerance');
$check(strpos($sql, 'o.fk_native_bank_line=b.rowid AND o.rowid<>19') !== false, 'movements of other imported lines are distinct evidence');
$check(strpos($sql, 'rappro') === false, 'reconciled existing movements still require review');

$db->movementIds = array(582569);
$error = $blocked($service, $line);
$check($error !== null && $error->movementIds === array(582569) && $error->complete, 'an existing movement blocks creation and is reported');
$check(!$service->audits, 'a refusal writes no override audit');
$check($blocked($service, $line, array(582570)) !== null, 'confirming a different movement does not override');
$db->movementIds = array(582569, 582600);
$check($blocked($service, $line, array(582569)) !== null, 'a partial confirmation does not override');
$check($blocked($service, $line, array('582600', 582569, 582569)) === null, 'the exact current list overrides');
$check(count($service->audits) === 1 && $service->audits[0][0] === 'existing_movement_override' && $service->audits[0][1] === 19
	&& $service->audits[0][2]['confirmed_movement_ids'] === array(582569, 582600), 'the override is audited with the confirmed movements');

$service->auditResult = false;
$failed = false;
try { $service->assertNoExistingMovementForCreation($line, array(582569, 582600)); } catch (KreaBankExistingMovementException $e) { $failed = false; } catch (Exception $e) { $failed = true; }
$check($failed, 'an override without its audit fails closed');
$service->auditResult = true;

$db->movementIds = range(1, 101);
$error = $blocked($service, $line, range(1, 101));
$check($error !== null && !$error->complete && count($error->movementIds) === 100, 'a truncated search can never be overridden');

$db->movementIds = array(582569);
$before = count($db->queries);
$check($blocked($service, array('rowid' => 582569, 'fk_statement' => 0, 'bank_account_id' => 3, 'amount' => '-15000', 'operation_date' => '2026-10-01')) === null
	&& $blocked($service, array_merge($line, array('native_bank_line_id' => 578316))) === null && count($db->queries) === $before,
	'lines that create no new movement are not searched or blocked');

// Rule entries have no confirmation step and the controller wires the exact list.
$serviceSource = file_get_contents(dirname(__DIR__) . '/class/KreaBankService.class.php');
$check(strpos($serviceSource, '$this->assertNoExistingMovementForCreation($line, $ruleReview ? array() : $confirmedMovementIds);') !== false, 'rule entries cannot override');
$controller = file_get_contents(dirname(__DIR__) . '/reconcile.php');
$check(strpos($controller, '$service->assertNoExistingMovementForCreation($lineId, $confirmedMovementIds);') !== false
	&& strpos($controller, 'name="confirm_existing_movements"') !== false, 'every creation choice is guarded and confirmable');

// Every flow that would materialize a movement passes the last in-transaction gate.
$reconcileBody = substr($serviceSource, strpos($serviceSource, 'public function reconcileLine('));
$check(strpos($reconcileBody, '$this->assertReviewedExistingMovementsForCreation($line);') !== false
	&& strpos($reconcileBody, '$this->assertReviewedExistingMovementsForCreation($line);') < strpos($reconcileBody, 'ensureNativeLineForStatementLine('), 'reconciliation reviews existing movements before creating one');
$service->allowConfirmedExistingMovements(19, array(582600, 582569));
$db->movementIds = array(582569, 582600);
$audits = count($service->audits);
$check($blocked($service, $line) === null && count($service->audits) === $audits, 'a restored confirmation of the exact list applies without a second audit');
$db->movementIds = array(582569, 582600, 582601);
$check($blocked($service, $line) !== null, 'a restored confirmation is void when the list changes');
$check(strpos($serviceSource, "throw new Exception('Source native bank line already belongs to another statement line #'") !== false
	&& strpos($serviceSource, 'claimed.fk_native_bank_line = b.rowid') !== false, 'a movement mapped to one statement line is neither offered nor accepted for another');
$check(strpos($controller, "\$action === 'confirm_existing_movements'") !== false && strpos($controller, "'expires' => time() + 900") !== false
	&& strpos($controller, '$service->allowConfirmedExistingMovements((int) $lineId') !== false, 'card actions use a separate, expiring, audited confirmation');

// Native transfer review: repeating the identical transfer confirms it; a double submit does not.
require_once dirname(__DIR__) . '/class/KreaBankTransferGuard.class.php';
$guard = new KreaBankTransferGuard($db, 7);
$transfer = array('from' => 3, 'to' => 4, 'amount' => '15000.00', 'amount_to' => '', 'date' => strtotime('2026-10-01 12:00:00 UTC'));
$db->movementIds = array();
$db->queries = array();
$check($guard->existingMovements(array(array('from' => 3, 'to' => 4, 'amount' => '0', 'amount_to' => '', 'date' => 1)), 3) === array() && !$db->queries, 'an empty or invalid transfer is not searched');
$key = $guard->confirmationKey(1, array($transfer), array(582569));
$check($key !== $guard->confirmationKey(1, array($transfer), array(582569, 582600)) && $key !== $guard->confirmationKey(2, array($transfer), array(582569))
	&& $key !== $guard->confirmationKey(1, array(array_merge($transfer, array('amount' => '15000.01'))), array(582569))
	&& $key === $guard->confirmationKey(1, array(array_merge($transfer, array('amount' => '15000'))), array(582569)), 'a confirmation is bound to the user, exact transfer and exact existing movements');
$store = array();
$check(!KreaBankTransferGuard::consumeConfirmation($store, $key, 1000) && isset($store[$key]), 'the first submission is refused and remembered');
$check(!KreaBankTransferGuard::consumeConfirmation($store, $key, 1002) && $store[$key] === 1000, 'an immediate repeat is a double submit, not a confirmation');
$check(KreaBankTransferGuard::consumeConfirmation($store, $key, 1006) && !isset($store[$key]), 'a deliberate repeat confirms once');
$check(!KreaBankTransferGuard::consumeConfirmation($store, $key, 1007), 'a consumed confirmation cannot be replayed');
$store = array($key => 1000);
$check(!KreaBankTransferGuard::consumeConfirmation($store, $key, 1000 + 601) && $store[$key] === 1601, 'an expired confirmation starts over');
$hook = file_get_contents(dirname(__DIR__) . '/class/actions_kreabank.class.php');
$check(strpos($hook, 'KreaBankTransferGuard::consumeConfirmation(') !== false && strpos($hook, "\$action = '';\n\t\t\t\t\t\treturn -1;") !== false, 'the native transfer hook blocks an unconfirmed duplicate-looking transfer');

echo 'OK: ' . $checks . " existing-movement creation guard checks (synthetic, no database)\n";
