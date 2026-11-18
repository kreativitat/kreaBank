#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 * ONLY the disposable 127.0.0.1:33317/kreabank_auto_test fixture. No installation config,
 * schema changes or financial writes; two independent MariaDB connections verify exclusion.
 */
require_once __DIR__ . '/../class/KreaBankReconciliationLock.class.php';
class ReconciliationLockTestDb
{
	public $connected = true;
	private $connection;
	public function __construct() { $this->connection = new mysqli('127.0.0.1', 'root', '', 'kreabank_auto_test', 33317); }
	public function prefix() { return 'kbauto_'; }
	public function query($sql) { return $this->connection->query($sql); }
	public function fetch_object($result) { return $result->fetch_object(); }
	public function close() { $this->connection->close(); $this->connected = false; }
}
$checks = 0;
function checkLockDatabase($condition, $message) { global $checks; if (!$condition) { throw new RuntimeException($message); } $checks++; }
function rejectLockDatabase($callback, $message) {
	$caught = false; try { $callback(); } catch (Throwable $e) { $caught = true; }
	checkLockDatabase($caught, $message);
}
$workerDb = new ReconciliationLockTestDb(); $nativeDb = new ReconciliationLockTestDb();
$worker = new KreaBankReconciliationLock($workerDb, 1); $native = new KreaBankReconciliationLock($nativeDb, 1);
$runnerName = 'kb-auto-' . substr(hash('sha256', 'kbauto_'), 0, 24) . '-1';
// Existing runner/native worker begins before manual preflight. Recursive service calls must
// not release its ownership, including when their SQL transaction cannot begin.
checkLockDatabase((int) $workerDb->fetch_object($workerDb->query("SELECT GET_LOCK('$runnerName',0) acquired"))->acquired === 1, 'The pre-existing runner lock is acquired');
$worker->acquire();
try { throw new RuntimeException('Synthetic transaction begin failed'); } catch (Throwable $e) { $worker->release(); }
rejectLockDatabase(static function () use ($native) { $native->acquire(); }, 'A failed nested execution cannot release the outer runner lock');
$workerDb->query("SELECT RELEASE_LOCK('$runnerName')");
$native->acquire();
rejectLockDatabase(static function () use ($worker) { $worker->acquire(); }, 'Native preflight and its subsequent transaction exclude an automatic worker');
$separateCompany = new KreaBankReconciliationLock($workerDb, 2); $separateCompany->acquire(); $separateCompany->release();
checkLockDatabase(true, 'A different company remains independent');
// A native hook cannot release its lock when returning: the core write follows later.
$native->holdUntilShutdown();
rejectLockDatabase(static function () use ($worker) { $worker->acquire(); }, 'Request retention preserves exclusion through the native write window');
$native->release(); $worker->acquire(); $worker->release();
checkLockDatabase(true, 'Native completion permits the next worker to recheck committed movements');
$native->acquire(); $nativeDb->close(); $native->release();
$closed = $workerDb->fetch_object($workerDb->query("SELECT GET_LOCK('$runnerName',1) acquired"));
checkLockDatabase((int) $closed->acquired === 1, 'Server observes native connection teardown within one second');
$workerDb->query("SELECT RELEASE_LOCK('$runnerName')");
$worker->acquire(); $worker->release();
checkLockDatabase(true, 'Native database teardown releases a retained request acquisition safely');
$workerDb->close();
echo 'OK: ' . $checks . " reconciliation lock database checks (two disposable connections, no writes)\n";
