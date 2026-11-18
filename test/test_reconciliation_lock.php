#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Synthetic native-hook checks: no database or application bootstrap.
 */
$nativeRoot = getenv('KREABANK_DOLIBARR_ROOT') ?: dirname(__DIR__, 3);
if (!is_file($nativeRoot . '/core/class/commonhookactions.class.php')) {
	fwrite(STDERR, "Set KREABANK_DOLIBARR_ROOT to installed Dolibarr source.\n"); exit(2);
}
define('DOL_DOCUMENT_ROOT', $nativeRoot);
require_once __DIR__ . '/../class/KreaBankReconciliationLock.class.php';
require_once __DIR__ . '/../class/actions_kreabank.class.php';
function GETPOSTINT($name) { return (int) ($GLOBALS['lockRequest'][$name] ?? 0); }
function GETPOST($name, $filter = '') { return $GLOBALS['lockRequest'][$name] ?? ''; }
function price2num($value, $mode = '', $rounding = 0) { return (string) $value; }
function dol_mktime($h, $m, $s, $month, $day, $year, $timezone = '') { return gmmktime($h, $m, $s, $month, $day, $year); }
function isModEnabled($module) { return in_array($module, array('kreabank', 'banque'), true); }
function getEntity($module) { return $GLOBALS['lockAccountScope'] ?? '7'; }
function getDolGlobalInt($key, $default = 0) { return $default; }
function dol_syslog($message, $level = 0) {}
class LockFixtureServer
{
	public $locks = array();
	public $movements = array();
	public $movementsByOwner = array();
	public $accounts = array(
		11 => array('rowid' => 11, 'entity' => 7, 'currency_code' => 'EUR'),
		12 => array('rowid' => 12, 'entity' => 7, 'currency_code' => 'EUR'),
		21 => array('rowid' => 21, 'entity' => 8, 'currency_code' => 'EUR'),
		22 => array('rowid' => 22, 'entity' => 9, 'currency_code' => 'EUR'),
	);
}
class LockFixtureDb
{
	public $connected = true;
	public $queries = array();
	public $fail = '';
	private $server;
	private $id;
	private $prefix;
	public function __construct($server, $id, $prefix) { $this->server = $server; $this->id = $id; $this->prefix = $prefix; }
	public function prefix() { return $this->prefix; }
	public function plimit($limit) { return ' LIMIT ' . (int) $limit; }
	public function escape($value) { return addslashes($value); }
	public function idate($value) { return gmdate('Y-m-d H:i:s', $value); }
	public function query($sql) {
		$this->queries[] = $sql;
		if ($this->fail !== '' && strpos($sql, $this->fail) !== false) { return false; }
		if (preg_match("/GET_LOCK\('([^']+)',0\)/", $sql, $matches)) {
			$name = $matches[1]; $lock = $this->server->locks[$name] ?? array($this->id, 0);
			if ($lock[0] !== $this->id) { return new ArrayIterator(array((object) array('acquired' => 0))); }
			$this->server->locks[$name] = array($this->id, $lock[1] + 1);
			return new ArrayIterator(array((object) array('acquired' => 1)));
		}
		if (preg_match("/RELEASE_LOCK\('([^']+)'\)/", $sql, $matches)) {
			$name = $matches[1]; $lock = $this->server->locks[$name] ?? null;
			if (!$lock || $lock[0] !== $this->id) { return new ArrayIterator(array((object) array('released' => 0))); }
			if (--$lock[1] === 0) { unset($this->server->locks[$name]); } else { $this->server->locks[$name] = $lock; }
			return new ArrayIterator(array((object) array('released' => 1)));
		}
		if (strpos($sql, 'SELECT value FROM ') === 0) { return new ArrayIterator(array()); }
		if (preg_match('/^SELECT rowid,entity,currency_code FROM .*bank_account WHERE entity IN \(([0-9,]+)\).*rowid IN \(([0-9,]+)\)/', $sql, $matches)) {
			$scope = array_map('intval', explode(',', $matches[1])); $ids = array_map('intval', explode(',', $matches[2])); $rows = array();
			foreach ($ids as $id) { if (isset($this->server->accounts[$id]) && in_array($this->server->accounts[$id]['entity'], $scope, true)) { $rows[] = (object) $this->server->accounts[$id]; } }
			return new ArrayIterator($rows);
		}
		if (strpos($sql, 'SELECT b.rowid FROM ') === 0) {
			preg_match('/ba.entity=([0-9]+)/', $sql, $owner);
			$movements = $this->server->movementsByOwner[(int) $owner[1]] ?? $this->server->movements;
			return new ArrayIterator(array_map(static function ($id) { return (object) array('rowid' => $id); }, $movements));
		}
		if (strpos($sql, 'INSERT INTO ') === 0) { return true; }
		throw new RuntimeException('Unexpected fixture SQL');
	}
	public function fetch_object($result) { if (!$result->valid()) { return null; } $row = $result->current(); $result->next(); return $row; }
	public function close() {
		foreach ($this->server->locks as $name => $lock) { if ($lock[0] === $this->id) { unset($this->server->locks[$name]); } }
		$this->connected = false;
	}
}
$checks = 0;
function checkLock($condition, $message) { global $checks; if (!$condition) { throw new RuntimeException($message); } $checks++; }
function rejectLock($callback, $message) { $caught = false; try { $callback(); } catch (Throwable $e) { $caught = true; } checkLock($caught, $message); }
$server = new LockFixtureServer();
$a = new LockFixtureDb($server, 1, 'synthetic_'); $b = new LockFixtureDb($server, 2, 'synthetic_');
foreach (array(0, -1, '1 OR 1=1', '') as $invalid) {
	rejectLock(static function () use ($a, $invalid) { new KreaBankReconciliationLock($a, $invalid); }, 'Invalid company cannot choose a lock identity');
}
$lock = new KreaBankReconciliationLock($a, '7'); $lock->acquire(); $lock->acquire();
$name = 'kb-auto-' . substr(hash('sha256', 'synthetic_'), 0, 24) . '-7';
checkLock($server->locks[$name] === array(1, 1), 'A lock instance acquires once using the existing company-runner identity');
$nested = new KreaBankReconciliationLock($a, 7); $nested->acquire();
$other = new KreaBankReconciliationLock($b, 7);
rejectLock(static function () use ($other) { $other->acquire(); }, 'A concurrent connection cannot enter a locked company');
$lock->release(); $lock->release();
rejectLock(static function () use ($other) { $other->acquire(); }, 'An outer release cannot release the nested acquisition');
$nested->release(); $other->acquire(); $other->release();
checkLock(!isset($server->locks[$name]), 'Balanced recursive releases unblock the competing connection');
$a->fail = 'GET_LOCK';
rejectLock(static function () use ($lock) { $lock->acquire(); }, 'Lock database failures are visible');
$a->fail = '';
$conf = (object) array('entity' => 7);
$user = new class { public $id = 4; public function hasRight($a, $b, $c = '') { return false; } };
$langs = new class { public function load($name) {} public function trans($key) { return $key; } };
$_SERVER['PHP_SELF'] = '/compta/bank/transfer.php';
$lockRequest = array('1_account_from' => 11, '1_account_to' => 12, '1_amount' => '81.23', '1_year' => 2026, '1_month' => 11, '1_day' => 18);
$hook = new ActionsKreaBank($b); $object = null;
$lock->acquire(); $action = 'add';
checkLock($hook->doActions(array(), $object, $action, null) === -1 && $action === ''
	&& $hook->error === 'KreaBankTransferReviewUnavailable', 'Native transfer fails closed while the automatic worker owns the company lock');
checkLock(!array_filter($b->queries, static function ($query) { return strpos($query, 'SELECT b.rowid FROM ') === 0; }), 'The native duplicate check never runs outside the serialization lock');
$lock->release(); $action = 'add';
checkLock($hook->doActions(array(), $object, $action, null) === 0 && $action === 'add', 'Native transfer may proceed after acquiring the shared lock');
rejectLock(static function () use ($lock) { $lock->acquire(); }, 'The native hook retains the lock after returning to the native transaction');
$b->close(); $lock->acquire(); $lock->release();
checkLock(!isset($server->locks[$name]), 'Native connection closure releases the request lock without opening a SQL transaction');
$c = new LockFixtureDb($server, 3, 'synthetic_'); $c->fail = 'SELECT b.rowid FROM ';
$hook = new ActionsKreaBank($c); $action = 'add';
checkLock($hook->doActions(array(), $object, $action, null) === -1 && $action === ''
	&& $hook->error === 'KreaBankTransferReviewUnavailable', 'A failed duplicate query clears the native write action');
$c->close();
$d = new LockFixtureDb($server, 4, 'synthetic_');
$hook = new ActionsKreaBank($d); $server->movements = array(91, 92); $_SESSION = array(); $action = 'add';
checkLock($hook->doActions(array(), $object, $action, null) === -1 && $action === '', 'An existing movement still requires an explicit reviewed confirmation');
$key = array_key_first($_SESSION['kreabank_transfer_confirm']); $_SESSION['kreabank_transfer_confirm'][$key] = time() - 6;
$action = 'add';
checkLock($hook->doActions(array(), $object, $action, null) === 0 && $action === 'add'
	&& !isset($_SESSION['kreabank_transfer_confirm'][$key]), 'The existing exact single-use confirmation remains valid under the lock');
$d->close();
// The native selector permits configured sharing, while arbitrary owner IDs remain denied.
$server->movements = array(); $lockAccountScope = '7,8';
$sharedDb = new LockFixtureDb($server, 10, 'shared_'); $sharedOtherDb = new LockFixtureDb($server, 11, 'shared_');
$sharedWorker = new KreaBankReconciliationLock($sharedOtherDb, 8); $sharedWorker->acquire();
$lockRequest['1_account_to'] = 21;
$sharedHook = new ActionsKreaBank($sharedDb); $action = 'add';
checkLock($sharedHook->doActions(array(), $object, $action, null) === -1 && $action === '', 'A shared endpoint owner worker blocks native preflight from another active company');
$sharedWorker->release(); $action = 'add';
checkLock($sharedHook->doActions(array(), $object, $action, null) === 0 && $action === 'add', 'A native shared-account transfer retains its supported selector scope');
$sharedNames = array_keys(array_filter($server->locks, static function ($lock) { return $lock[0] === 10; }));
checkLock(count($sharedNames) === 2 && substr($sharedNames[0], -2) === '-7' && substr($sharedNames[1], -2) === '-8', 'Current and shared endpoint owner locks are acquired in numeric order');
rejectLock(static function () use ($sharedWorker) { $sharedWorker->acquire(); }, 'The shared owner remains locked after native preflight returns');
$sharedDb->close();
$hiddenDb = new LockFixtureDb($server, 12, 'hidden_'); $hiddenHook = new ActionsKreaBank($hiddenDb);
$lockRequest['1_account_to'] = 22; $action = 'add';
checkLock($hiddenHook->doActions(array(), $object, $action, null) === -1 && $action === '', 'An account outside native sharing scope is rejected');
checkLock(!array_filter($hiddenDb->queries, static function ($query) { return strpos($query, 'GET_LOCK') !== false || strpos($query, 'SELECT b.rowid FROM ') === 0; }), 'An unauthorized account cannot expose movement IDs or select an owner lock');
$lockRequest['1_account_to'] = 21;
$unionDb = new LockFixtureDb($server, 13, 'union_'); $unionHook = new ActionsKreaBank($unionDb);
$lockRequest['1_amountto'] = '99.99';
$server->movementsByOwner = array(7 => array(503), 8 => array(501, 503)); $action = 'add'; $_SESSION['kreabank_transfer_confirm'] = array();
checkLock($unionHook->doActions(array(), $object, $action, null) === -1 && $action === '', 'Existing movements from any shared endpoint owner require confirmation');
$normalizedTransfer = array('from' => 11, 'to' => 21, 'amount' => '81.23', 'amount_to' => '', 'date' => gmmktime(12, 0, 0, 11, 18, 2026));
$expectedKey = (new KreaBankTransferGuard($unionDb, 7))->confirmationKey(4, array($normalizedTransfer), array(501, 503));
checkLock(isset($_SESSION['kreabank_transfer_confirm'][$expectedKey]), 'Shared owner movements form one sorted unique confirmation bound to the active company');
checkLock((bool) array_filter($unionDb->queries, static function ($query) { return strpos($query, 'b.fk_account=21 AND CAST(b.amount AS DECIMAL(24,8))=81.23') !== false; })
	&& !array_filter($unionDb->queries, static function ($query) { return strpos($query, '=99.99') !== false; }), 'Equal-currency destination matching uses the native amount despite an inconsistent submitted amount_to');
$unionDb->close();
$boundDb = new LockFixtureDb($server, 14, 'bounded_'); $boundHook = new ActionsKreaBank($boundDb);
$server->movementsByOwner = array(7 => range(1, 60), 8 => range(61, 120)); $action = 'add'; $_SESSION['kreabank_transfer_confirm'] = array();
checkLock($boundHook->doActions(array(), $object, $action, null) === -1 && $action === '' && $boundHook->error === 'KreaBankTransferExistingIncomplete'
	&& !$_SESSION['kreabank_transfer_confirm'], 'More than100 combined owner movements fail closed without storing an incomplete confirmation');
$boundDb->close(); $server->movementsByOwner = array();
$lockAccountScope = '7'; $lockRequest['1_account_to'] = 12; unset($lockRequest['1_amountto']);
$negativeDb = new LockFixtureDb($server, 15, 'negative_'); $negativeHook = new ActionsKreaBank($negativeDb);
$lockRequest['1_amount'] = '-81.23'; $lockRequest['1_amountto'] = '99.99'; $action = 'add';
checkLock($negativeHook->doActions(array(), $object, $action, null) === -1 && $action === '' && $negativeHook->error === 'AmountMustBePositive',
	'Negative source amounts cannot bypass native locking/review even with a positive FX destination amount');
checkLock(!array_filter($negativeDb->queries, static function ($query) { return strpos($query, 'SELECT b.rowid FROM ') === 0; }), 'Invalid negative transfers expose no native movement IDs');
$lockRequest['1_amount'] = '81.23'; unset($lockRequest['1_amountto']);
// Both direct service executors must balance a recursive acquisition even before their
// transaction starts. Bypass service initialization so this remains a no-database check.
require_once __DIR__ . '/../class/KreaBankService.class.php';
class LockBeginFailureDb extends LockFixtureDb
{
	public $begins = 0;
	public $rollbacks = 0;
	public function begin() { $this->begins++; return false; }
	public function rollback() { $this->rollbacks++; return true; }
}
class LockBeginFailureService extends KreaBankService
{
	public function __construct($db, $actor) { $this->db = $db; $this->user = $actor; $this->entity = 7; }
}
$actor = new class { public $id = 4; public $statut = 1; public function hasRight($a, $b, $c = '') { return true; } };
$failureDb = new LockBeginFailureDb($server, 5, 'begin_failure_');
$competingDb = new LockFixtureDb($server, 6, 'begin_failure_');
$failureService = new LockBeginFailureService($failureDb, $actor);
$outer = new KreaBankReconciliationLock($failureDb, 7); $competitor = new KreaBankReconciliationLock($competingDb, 7);
foreach (array('reconcileAutomaticOwnTransfer', 'reconcileAutomaticTransferPair') as $method) {
	$outer->acquire();
	rejectLock(static function () use ($failureService, $method) { $failureService->$method(1, array(11, 12)); }, 'Direct execution reports its failed transaction start');
	rejectLock(static function () use ($competitor) { $competitor->acquire(); }, 'Failed direct execution leaves the outer runner acquisition intact');
	$outer->release(); $competitor->acquire(); $competitor->release();
	checkLock(true, 'Failed direct execution does not leak its recursive company acquisition');
}
checkLock($failureDb->begins === 2 && $failureDb->rollbacks === 2, 'Both direct execution paths reach and handle begin=false');
echo 'OK: ' . $checks . " reconciliation lock/native hook checks (synthetic, no database)\n";
