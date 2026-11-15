#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>; GPL-3.0-or-later
 * ONLY disposable localhost:33317/kreabank_auto_test; owns kbtransfer_ tables.
 * Never bootstraps an installation or accepts database connection overrides.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../lib/kreabank_transfer.lib.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
class TransferHistoryDb
{
	public $connection;
	public $queries = 0;
	public $fail = false;
	public function __construct() {
		$this->connection = new mysqli('127.0.0.1', 'root', '', '', 33317);
		$this->connection->query('CREATE DATABASE IF NOT EXISTS kreabank_auto_test');
		$this->connection->select_db('kreabank_auto_test');
		$this->connection->set_charset('utf8mb4');
	}
	public function prefix() { return 'kbtransfer_'; }
	public function plimit($limit) { return ' LIMIT ' . (int) $limit; }
	public function query($sql) { $this->queries++; return $this->fail ? false : $this->connection->query($sql); }
	public function fetch_object($result) { return $result->fetch_object(); }
	public function free($result) { $result->free(); }
}
class TransferHistoryUser
{
	public $socid = 0;
	public $denied = '';
	public function hasRight(...$parts) { return implode('.', $parts) !== $this->denied; }
}
$db = new TransferHistoryDb();
$user = new TransferHistoryUser();
$checks = 0;
function checkTransferHistory($ok, $label) {
	global $checks;
	if (!$ok) { throw new RuntimeException($label); }
	$checks++;
}
function transferHistoryPair($label, $date = '2026-11-01', $from = 101, $to = 102, $reciprocal = true, $amountTo = '10.00', $type = 'VIR') {
	global $db;
	$label = $db->connection->real_escape_string($label);
	$db->query("INSERT INTO kbtransfer_bank(fk_account,label,amount,dateo,type) VALUES ($from,'$label',-10,'$date','$type')");
	$a = $db->connection->insert_id;
	$db->query("INSERT INTO kbtransfer_bank(fk_account,label,amount,dateo,type) VALUES ($to,'$label',$amountTo,'$date','$type')");
	$b = $db->connection->insert_id;
	$db->query("INSERT INTO kbtransfer_bank_url(fk_bank,url_id,type) VALUES ($a,$b,'banktransfert')");
	if ($reciprocal) { $db->query("INSERT INTO kbtransfer_bank_url(fk_bank,url_id,type) VALUES ($b,$a,'banktransfert')"); }
	return array($a, $b);
}
try {
	foreach (array('bank_url', 'bank', 'bank_account') as $table) { $db->query('DROP TABLE IF EXISTS kbtransfer_' . $table); }
	$db->query('CREATE TABLE kbtransfer_bank_account(rowid INT PRIMARY KEY, entity INT, currency_code VARCHAR(3))');
	$db->query('CREATE TABLE kbtransfer_bank(rowid INT PRIMARY KEY AUTO_INCREMENT, fk_account INT, label VARCHAR(255), amount DECIMAL(24,8), dateo DATE, type VARCHAR(8)) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
	$db->query('CREATE TABLE kbtransfer_bank_url(fk_bank INT, url_id VARCHAR(255), type VARCHAR(32))');
	$db->query("INSERT INTO kbtransfer_bank_account VALUES (101,1,'EUR'),(102,1,'EUR'),(103,1,'USD'),(201,2,'EUR'),(202,2,'EUR')");
	checkTransferHistory(kreabankTransferDescriptions($db, $user, 1) === array(), 'Empty history remains usable');
	for ($i = 0; $i < 3; $i++) { transferHistoryPair('Most used'); }
	for ($i = 0; $i < 2; $i++) { transferHistoryPair('Second'); }
	$duplicate = transferHistoryPair('Duplicate links', '2026-11-02');
	for ($i = 0; $i < 8; $i++) { $db->query("INSERT INTO kbtransfer_bank_url VALUES ($duplicate[0],$duplicate[1],'banktransfert'),($duplicate[1],$duplicate[0],'banktransfert')"); }
	transferHistoryPair('Cross currency', '2026-11-03', 101, 103, true, '11.25');
	transferHistoryPair('Cash', '2026-11-04', 101, 102, true, '10.00', 'LIQ');
	transferHistoryPair('A same date', '2026-11-05');
	transferHistoryPair('B same date', '2026-11-05');
	transferHistoryPair(' Transferência <&> "livre" ', '2026-11-06');
	foreach (array(array('Foreign destination',101,201,true), array('Foreign source',201,101,true), array('Foreign both',201,202,true), array('Incomplete',101,102,false), array('Same account',101,101,true), array('   ',101,102,true)) as $bad) {
		for ($i = 0; $i < 5; $i++) { transferHistoryPair($bad[0], '2026-11-15', $bad[1], $bad[2], $bad[3]); }
	}
	$wrong = transferHistoryPair('Different leg labels');
	$db->query("UPDATE kbtransfer_bank SET label='Other label' WHERE rowid=$wrong[1]");
	$db->query("INSERT INTO kbtransfer_bank(fk_account,label,amount,dateo) VALUES (101,'Ordinary movement',-10,'2026-11-15')");
	$db->connection->begin_transaction();
	transferHistoryPair('Rolled back transfer');
	$db->connection->rollback();
	$expected = array('Most used', 'Second', 'Transferência <&> "livre"', 'A same date', 'B same date', 'Cash', 'Cross currency', 'Duplicate links');
	checkTransferHistory(kreabankTransferDescriptions($db, $user, 1) === $expected, 'Frequency counts each committed transfer once; date/name ties deterministic; invalid/foreign pairs excluded');
	checkTransferHistory(kreabankTransferDescriptions($db, $user, 2) === array('Foreign both'), 'Other company sees only its own complete transfers');
	for ($i = 0; $i < 12; $i++) { transferHistoryPair(sprintf('Older %02d', $i), '2026-10-01'); }
	checkTransferHistory(kreabankTransferDescriptions($db, $user, 1) === array_merge($expected, array('Older 00', 'Older 01')), 'At most ten most-used descriptions returned');
	foreach (array('banque.lire', 'banque.transfer', 'kreabank.reconciliation.read') as $denied) {
		$user->denied = $denied; $before = $db->queries;
		checkTransferHistory(kreabankTransferDescriptions($db, $user, 1) === array() && $before === $db->queries, 'Missing right denies history before SQL: ' . $denied);
	}
	$user->denied = ''; $user->socid = 99; $before = $db->queries;
	checkTransferHistory(kreabankTransferDescriptions($db, $user, 1) === array() && $before === $db->queries, 'External users denied before SQL');
	$user->socid = 0;
	checkTransferHistory(kreabankTransferDescriptions($db, $user, 0) === array() && $before === $db->queries, 'Invalid company denied before SQL');
	$db->fail = true; $caught = false;
	try { kreabankTransferDescriptions($db, $user, 1); } catch (RuntimeException $e) { $caught = true; }
	checkTransferHistory($caught, 'Query failure remains visible to the caller');
} finally {
	$db->fail = false;
	foreach (array('bank_url', 'bank', 'bank_account') as $table) { $db->query('DROP TABLE IF EXISTS kbtransfer_' . $table); }
	$db->connection->close();
}
echo "OK: $checks transfer history database checks (disposable fixture only)\n";
