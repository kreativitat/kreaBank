#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Uses ONLY a disposable, local MariaDB on 127.0.0.1:33316. Never bootstrap production.
 * docker run --rm -d --name kreabank-fintable-test -p 127.0.0.1:33316:3306 -e MARIADB_ALLOW_EMPTY_ROOT_PASSWORD=1 mariadb:11.4
 */
require_once __DIR__ . '/test_fintable.php';
define('DOL_DOCUMENT_ROOT', __DIR__ . '/fixtures/fintable-dolibarr');
define('DOL_URL_ROOT', '');
define('MAIN_DB_PREFIX', 'kbtest_');
function dolDecrypt($value) { return strpos($value,'dolcrypt:test:') === 0 ? substr($value,14) : $value; }
function dol_now() { return time(); }
function dol_print_date($time, $format) { return gmdate(strtr($format, array('%Y'=>'Y','%m'=>'m','%d'=>'d','%H'=>'H','%M'=>'i','%S'=>'s')), $time); }
function dol_syslog($message, $level = 0) {}
function dol_string_nospecial($value) { return $value; }
function getDolGlobalInt($name, $default = 0) { return $default; }
function getDolGlobalString($name, $default = '') { return $default; }
function isModEnabled($name) { return in_array($name, array('kreabank','multicompany'), true); }
function price2num($value, $round = '') { return (string) $value; }
class FeedTestDb
{
	public $connection;
	public $depth = 0;
	public $failLedger = false;
	public function __construct() {
		$this->connection = new mysqli('127.0.0.1', 'root', '', '', 33316);
		$this->connection->query('CREATE DATABASE IF NOT EXISTS kreabank_feed_test');
		$this->connection->select_db('kreabank_feed_test');
	}
	public function prefix() { return 'kbtest_'; }
	public function escape($value) { return $this->connection->real_escape_string($value); }
	public function query($sql) {
		if ($this->failLedger && strpos($sql, 'INSERT INTO kbtest_kreabank_feed_transaction') === 0) { return false; }
		try { return $this->connection->query($sql); } catch (mysqli_sql_exception $e) { throw new RuntimeException($e->getMessage() . ' [test SQL: ' . $sql . ']'); }
	}
	public function fetch_object($result) { return $result->fetch_object(); }
	public function free($result) { $result->free(); }
	public function num_rows($result) { return $result->num_rows; }
	public function plimit($limit, $offset = 0) { return ' LIMIT ' . (int) $offset . ',' . (int) $limit; }
	public function last_insert_id($table = '') { return $this->connection->insert_id; }
	public function lasterror() { return $this->connection->error; }
	public function idate($time) { return gmdate('Y-m-d H:i:s', is_numeric($time) ? $time : strtotime($time)); }
	public function begin() { if ($this->depth++ === 0) { return $this->connection->begin_transaction(); } return true; }
	public function commit() { if (--$this->depth === 0) { return $this->connection->commit(); } return true; }
	public function rollback() { if ($this->depth > 1) { $this->depth--; return true; } $this->depth = 0; return $this->connection->rollback(); }
}
class FeedTestConf
{
	public $entity = 1;
	public $global;
	public $currency = 'EUR';
	public function __construct() { $this->global = new stdClass(); }
	public function setEntityValues($db, $entity) { $this->entity = (int) $entity; }
}
class FeedTestUser
{
	public $id = 1;
	public $admin = 1;
	public $entity = 0;
	public $socid = 0;
	public function loadRights($a = '', $b = 0) {}
	public function hasRight($a, $b) { return true; }
}
class FeedTestLangs { public function trans($key) { return $key; } }
$db = new FeedTestDb();
foreach ($db->query('SHOW TABLES')->fetch_all() as $table) {
	if (strpos($table[0], 'kbtest_') === 0) { $db->query('DROP TABLE `' . $table[0] . '`'); }
}
$db->query('CREATE TABLE kbtest_entity (rowid INT PRIMARY KEY,label VARCHAR(32),active INT)');
$db->query('CREATE TABLE kbtest_const (entity INT,name VARCHAR(128),value TEXT)');
$db->query('CREATE TABLE kbtest_bank_account (rowid INT PRIMARY KEY,entity INT,ref VARCHAR(32),label VARCHAR(64),iban_prefix VARCHAR(64),currency_code VARCHAR(3),clos INT DEFAULT 0,courant INT DEFAULT 1,rappro INT DEFAULT 1)');
$db->query('CREATE TABLE kbtest_bank (rowid INT PRIMARY KEY,fk_account INT,num_releve VARCHAR(64),label TEXT,note TEXT,rappro INT DEFAULT 0)');
$db->query("INSERT INTO kbtest_entity VALUES (1,'Example One',1),(2,'Example Two',1),(3,'Inactive',0),(4,'Module disabled',1)");
$db->query("INSERT INTO kbtest_const VALUES (1,'MAIN_MODULE_KREABANK','1'),(2,'MAIN_MODULE_KREABANK','1'),(3,'MAIN_MODULE_KREABANK','1'),(4,'MAIN_MODULE_KREABANK','0'),(0,'KREABANK_FINTABLE_WORKSPACE','ws_fixture'),(0,'KREABANK_FINTABLE_ENABLED','1')");
$db->query("INSERT INTO kbtest_bank_account (rowid,entity,ref,label,iban_prefix,currency_code) VALUES (101,1,'CO_A','Example A','GB82WEST12345698765432','EUR'),(202,2,'CO_B','Example B','DE89370400440532013000','EUR'),(303,3,'INACTIVE','Inactive','FR1420041010050500013M02606','EUR')");
$conf = new FeedTestConf();
$user = new FeedTestUser();
$langs = new FeedTestLangs();
require_once __DIR__ . '/../class/KreaBankFintableSync.class.php';
$sources = array(
	array('id'=>'acc_one','connection_id'=>'conn_fixture','enabled'=>true,'iban'=>'GB82WEST12345698765432','currency'=>'EUR','balance'=>'100.00','balance_available'=>'80.00','sync_start_date'=>'2026-01-01'),
	array('id'=>'acc_two','connection_id'=>'conn_fixture','enabled'=>true,'iban'=>'DE89370400440532013000','currency'=>'EUR','balance'=>'200.00','balance_available'=>'200.00','sync_start_date'=>'2026-01-01'),
	array('id'=>'acc_inactive','connection_id'=>'conn_fixture','enabled'=>true,'iban'=>'FR1420041010050500013M02606','currency'=>'EUR','balance'=>'0.00','balance_available'=>'0.00','sync_start_date'=>'2026-01-01'),
);
function fixtureTx($id, $account, $amount) { return array('id'=>$id,'ext_id'=>'entry_'.$id,'account_id'=>$account,'pending'=>false,'amount'=>$amount,'currency'=>'EUR','date'=>'2026-10-30','description'=>'10,00'); }
$pages = array('acc_one'=>array(array(fixtureTx('tx_a','acc_one','-1000.12'),fixtureTx('tx_b','acc_one','-1000.12')),array(fixtureTx('tx_c','acc_one','200.50'),fixtureTx('tx_zero','acc_one','0.00'))), 'acc_two'=>array(array(fixtureTx('tx_d','acc_two','-50.00'))));
$postCount = 0;
$allowSync = false;
$postTimeout = false;
$connectionState = '';
$healthy = true;
$transport = static function ($method, $url) use (&$sources, &$pages, &$postCount, &$allowSync, &$postTimeout, &$connectionState, &$healthy) {
	if ($method === 'POST') {
		checkFeed($allowSync && parse_url($url, PHP_URL_PATH) === '/api/v2/sync', 'Only the approved included sync endpoint may write');
		$postCount++;
		if ($postTimeout) { throw new RuntimeException('Synthetic unknown sync outcome'); }
		return array('status'=>200,'body'=>json_encode(array('data'=>array('queued'=>true),'workspace_id'=>'ws_fixture')));
	}
	$path = substr(parse_url($url, PHP_URL_PATH), strlen('/api/v2/'));
	parse_str(parse_url($url, PHP_URL_QUERY), $query);
	if ($path === 'me') { $data = array('can_sync'=>true); }
	elseif ($path === 'connections') { $data = array(array('id'=>'conn_fixture','healthy'=>$healthy,'needs_reconnect'=>!$healthy,'last_successful_update'=>gmdate('c'),'sync_status'=>array('state'=>$connectionState))); }
	elseif ($path === 'accounts') { $data = $sources; }
	else {
		preg_match('~^accounts/([^/]+)/transactions$~', $path, $m);
		checkFeed(isset($pages[$m[1]]), 'Inactive/unmapped companies must not retrieve movements');
		$index = isset($query['cursor']) ? (int) $query['cursor'] : 0;
		return array('status'=>200,'body'=>json_encode(array('data'=>$pages[$m[1]][$index],'next_cursor'=>isset($pages[$m[1]][$index+1])?(string)($index+1):null,'workspace_id'=>'ws_fixture')));
	}
	return array('status'=>200,'body'=>json_encode(array('data'=>$data,'workspace_id'=>'ws_fixture')));
};
$client = new KreaBankFintableClient(str_repeat('x',32), 'ws_fixture', $transport);
$sync = new KreaBankFintableSync($db, $client);
$sync->ensureSchema();
function feedCount($db, $table) { return (int) $db->query('SELECT COUNT(*) n FROM kbtest_' . $table)->fetch_object()->n; }
$first = $sync->collect($user);
checkFeed($first['failed'] === 0 && $first['imported'] === 4, 'Initial collection imports four nonzero movements: ' . json_encode($first));
checkFeed(feedCount($db,'kreabank_statement_line') === 4 && feedCount($db,'kreabank_feed_transaction') === 5, 'Zero movement is recorded without a native/staged posting');
checkFeed((int)$db->query('SELECT COUNT(*) n FROM kbtest_kreabank_statement_line WHERE entity=1 AND amount=-1000.12')->fetch_object()->n === 2, 'Preserve identical-looking transactions and trusted amount');
$again = $sync->collect($user);
checkFeed($again['imported'] === 0 && $again['unchanged'] === 5, 'Replay adds no statement rows');
$adapter = new KreaBankNativeBankAdapter($db,$user,$langs,1);
$stagedId = (int)$db->query('SELECT MIN(rowid) id FROM kbtest_kreabank_statement_line WHERE entity=1')->fetch_object()->id;
$staged = $adapter->getLineById($stagedId);
checkFeed($staged['amount'] === -1000.12 && strpos($staged['line_uid'],'fintable:') === 0, 'Reconciliation read preserves structured amount and source identity');
checkFeed($conf->entity === 1 && $db->depth === 0, 'Restore company context and transaction depth');
// An account failure cannot block a different company's new movement.
$watermark = $db->query('SELECT watermark FROM kbtest_kreabank_feed_account WHERE entity=1')->fetch_object()->watermark;
$pages['acc_one'][0][0]['amount'] = '-999.00';
$pages['acc_two'][0][] = fixtureTx('tx_e','acc_two','25.00');
$changed = $sync->collect($user);
checkFeed($changed['failed'] === 1 && $changed['conflicts'] === 1 && $changed['imported'] === 1, 'Quarantine financial change while another entity continues');
checkFeed($db->query('SELECT watermark FROM kbtest_kreabank_feed_account WHERE entity=1')->fetch_object()->watermark === $watermark, 'A failed account retains its watermark');
checkFeed(feedCount($db,'kreabank_statement_line') === 5, 'Conflicting source must not rewrite or duplicate original staged movement');
$pages['acc_one'][0][0]['amount'] = '-1000.12';
$sync->collect($user);
// Inject a ledger failure after staging: nested native import must roll back with its identities.
$pages['acc_one'][0][] = fixtureTx('tx_rollback','acc_one','10.00');
$db->failLedger = true;
$failed = $sync->collect($user);
$db->failLedger = false;
checkFeed($failed['imported'] === 0 && feedCount($db,'kreabank_statement_line') === 5, 'Source identity failure rolls staged page back atomically');
$recovered = $sync->collect($user);
checkFeed($recovered['imported'] === 1 && feedCount($db,'kreabank_statement_line') === 6, 'Retry recovers rolled-back page once');
// Verify real database lock exclusion across independent connections.
$secondDb = new FeedTestDb();
$lock = 'kb-fintable-' . substr(hash('sha256','kbtest_'),0,24);
$secondDb->query("SELECT GET_LOCK('$lock',0)");
checkFeed($sync->collect($user)['status'] === 'already_running', 'Prevent concurrent collectors');
$secondDb->query("SELECT RELEASE_LOCK('$lock')");
// Misassigned account import is denied even below the feed mapping layer.
$adapter = new KreaBankNativeBankAdapter($db,$user,$langs,1);
rejectFeed(static function () use ($adapter) { $adapter->importLines(array(),'test','fintable',202); }, 'Native adapter denies another company bank account');
$badActor = clone $user;
$badActor->entity = 1;
rejectFeed(static function () use ($sync,$badActor) { $sync->collect($badActor); }, 'Company administrator cannot collect all companies');
$scheduled = $sync->collect($user,'2026-11-01 08:45');
checkFeed($scheduled['status'] === 'completed' && $sync->collect($user,'2026-11-01 08:45')['status'] === 'already_collected', 'Claim each wall-clock slot once');
checkFeed(feedCount($db,'kreabank_feed_account') === 2, 'Only active enabled owners receive mappings');
// Several bank accounts in one entity must not collide on the statement reference unique key.
$db->query("INSERT INTO kbtest_bank_account (rowid,entity,ref,label,iban_prefix,currency_code) VALUES (102,1,'CO_C','Example C','NL91ABNA0417164300','EUR')");
$sources[] = array_merge($sources[0], array('id'=>'acc_three','iban'=>'NL91ABNA0417164300'));
$pages['acc_three'] = array(array(fixtureTx('tx_f','acc_three','-2.00')));
$sameCompany = $sync->collect($user);
checkFeed($sameCompany['failed'] === 0 && $sameCompany['imported'] === 1, 'Multiple bank accounts in the same company use valid distinct statement references');
checkFeed(feedCount($db,'bank') === 0, 'Automatic collection never materializes native bank movements');
// The scheduler requests one refresh and waits without exhausting retries.
$db->query("INSERT INTO kbtest_const VALUES (0,'KREABANK_FINTABLE_REFRESH','dolcrypt:test:1')");
checkFeed($sync->setting('KREABANK_FINTABLE_REFRESH') === '1', 'Read native encrypted non-token settings');
$allowSync = true;
$slot = '2026-11-02 08:45';
checkFeed($sync->collect($user,$slot)['status'] === 'refresh_wait' && $postCount === 1, 'Request included refresh before a scheduled collection');
$connectionState = 'queued';
checkFeed($sync->collect($user,$slot)['status'] === 'refresh_wait' && $postCount === 1, 'Wait without repeating sync');
checkFeed((int)$db->query("SELECT attempts FROM kbtest_kreabank_feed_run WHERE slot_key='$slot'")->fetch_object()->attempts === 1, 'Queued refresh does not consume failure retries');
$connectionState = '';
checkFeed($sync->collect($user,$slot)['status'] === 'completed' && $postCount === 1, 'Finish refreshed slot once');
checkFeed($sync->collect($user,$slot)['status'] === 'already_collected', 'Completed scheduled slot cannot repeat');
// An ambiguous POST timeout must never cause a second write.
$postTimeout = true;
$slot = '2026-11-02 20:45';
rejectFeed(static function () use ($sync,$user,$slot) { $sync->collect($user,$slot); }, 'Unknown POST outcome is visible');
$postTimeout = false;
checkFeed($sync->collect($user,$slot)['status'] === 'completed' && $postCount === 2, 'Retry reads cached data without repeating ambiguous sync');
// Bound asynchronous waits, and continue other banks when one authorization expires.
$slot = '2026-11-03 08:45';
$sync->collect($user,$slot);
$connectionState = 'executing';
$db->query("UPDATE kbtest_kreabank_feed_run SET started_at=DATE_SUB(NOW(), INTERVAL 31 MINUTE) WHERE slot_key='$slot'");
$busy = $sync->collect($user,$slot);
checkFeed($busy['status'] === 'partial' && $busy['failed'] === 3, 'A long-running refresh becomes an actionable per-account failure');
$connectionState = '';
$healthy = false;
$expired = $sync->collect($user);
checkFeed($expired['failed'] === 3 && (int)$db->query("SELECT COUNT(*) n FROM kbtest_kreabank_feed_account WHERE status='failed'")->fetch_object()->n === 3, 'Expired authorization is visible and preserves existing imports');
$healthy = true;
$sync->collect($user);
// Disabled provider accounts retain their audit history but cease to appear ready.
$sources[0]['enabled'] = false;
$sync->collect($user);
checkFeed($db->query("SELECT status FROM kbtest_kreabank_feed_account WHERE provider_account_id='acc_one'")->fetch_object()->status === 'unavailable', 'Disabled sources are marked unavailable');
checkFeed(feedCount($db,'bank') === 0, 'Scheduled refresh never writes native accounting movements');
print "OK: $checks checks including real MariaDB imports, rollback, retry, locking and company isolation\n";
