#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 * ONLY a disposable MariaDB on 127.0.0.1:33317, database kreabank_auto_test.
 * Uses the installed Dolibarr AccountLine implementation, never an installation database.
 */
$nativeRoot = getenv('KREABANK_DOLIBARR_ROOT') ?: dirname(__DIR__, 3);
if (!is_file($nativeRoot . '/compta/bank/class/account.class.php')) {
	fwrite(STDERR, "Set KREABANK_DOLIBARR_ROOT to a Dolibarr htdocs source directory.\n");
	exit(2);
}
define('DOL_DOCUMENT_ROOT', $nativeRoot);
define('DOL_URL_ROOT', '');
define('MAIN_DB_PREFIX', 'kbauto_');
function dol_now() { return time(); }
function dol_print_date($time, $format = '%Y%m%d') { return gmdate(strtr($format, array('%Y'=>'Y','%m'=>'m','%d'=>'d','%H'=>'H','%M'=>'i','%S'=>'s')), $time); }
function dol_syslog($message, $level = 0) {}
function dol_string_nospecial($value) { return $value; }
function getDolGlobalInt($key, $default = 0) { return (int) ($GLOBALS['conf']->global->$key ?? $default); }
function getDolGlobalString($key, $default = '') { return (string) ($GLOBALS['conf']->global->$key ?? $default); }
function isModEnabled($name) { return in_array($name, array('kreabank', 'banque', 'multicompany'), true); }
function getEntity($name) { return (string) $GLOBALS['conf']->entity; }
function price2num($value, $round = '') { return (string) $value; }
function dol_escape_htmltag($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function dol_strlen($value) { return strlen((string) $value); }
function dol_substr($value, $start, $length, $charset = '', $dots = 0) { return substr($value, $start, $length); }
class DoliDB
{
	public $connection;
	public $depth = 0;
	public $fail = '';
	public $beforeBegin;
	public function __construct() {
		$this->connection = new mysqli('127.0.0.1', 'root', '', '', 33317);
		$this->connection->query('CREATE DATABASE IF NOT EXISTS kreabank_auto_test');
		$this->connection->select_db('kreabank_auto_test');
	}
	public function prefix() { return MAIN_DB_PREFIX; }
	public function escape($value) { return $this->connection->real_escape_string((string) $value); }
	public function encrypt($value) { return "'" . $this->escape($value) . "'"; }
	public function query($sql) {
		if ($this->fail !== '' && strpos($sql, $this->fail) === 0) { return false; }
		return $this->connection->query($sql);
	}
	public function fetch_object($result) { return $result->fetch_object(); }
	public function free($result) { $result->free(); }
	public function num_rows($result) { return $result->num_rows; }
	public function plimit($limit, $offset = 0) { return ' LIMIT ' . (int) $offset . ',' . (int) $limit; }
	public function last_insert_id($table = '') { return $this->connection->insert_id; }
	public function lasterror() { return $this->connection->error; }
	public function idate($time) { return gmdate('Y-m-d H:i:s', is_numeric($time) ? $time : strtotime($time)); }
	public function jdate($time) { return $time ? strtotime($time) : null; }
	public function begin() {
		if ($this->beforeBegin) { $hook = $this->beforeBegin; $this->beforeBegin = null; $hook(); }
		if ($this->depth++ === 0) { return $this->connection->begin_transaction(); }
		return true;
	}
	public function commit() { return --$this->depth === 0 ? $this->connection->commit() : true; }
	public function rollback() { $this->depth = 0; return $this->connection->rollback(); }
}
class User
{
	public $id = 7;
	public $admin = 1;
	public $entity = 0;
	public $socid = 0;
	public $statut = 1;
	public $allowed = true;
	public function loadRights($a = '', $b = 0) {}
	public function hasRight($a, $b, $c = '') { return $this->allowed; }
}
class AutoTestLangs { public function trans($key) { return $key; } public function load($name) { return 1; } }
$conf = (object) array('entity'=>1, 'currency'=>'EUR', 'global'=>new stdClass());
$user = new User();
$langs = new AutoTestLangs();
$extrafields = (object) array('attributes'=>array('bank'=>array('loaded'=>1,'label'=>array())));
require_once __DIR__ . '/../class/KreaBankAutoReconcile.class.php';
$db = new DoliDB();
foreach ($db->query('SHOW TABLES')->fetch_all() as $table) {
	if (strpos($table[0], MAIN_DB_PREFIX) === 0) { $db->query('DROP TABLE `' . $table[0] . '`'); }
}
foreach (array('bank_account','bank','bank_url','const','cronjob') as $table) {
	$sql = file_get_contents(DOL_DOCUMENT_ROOT . '/install/mysql/tables/llx_' . $table . '.sql');
	$sql = preg_replace('/--[^\n]*/', '', $sql);
	foreach (explode(';', str_replace('llx_', MAIN_DB_PREFIX, $sql)) as $statement) {
		if (trim($statement) !== '') { $db->query($statement); }
	}
}
$db->query('CREATE TABLE kbauto_entity (rowid INT PRIMARY KEY,active INT NOT NULL)');
$db->query('INSERT INTO kbauto_entity VALUES (1,1),(2,1),(3,0)');
$db->query("INSERT INTO kbauto_bank_account (rowid,entity,ref,label,iban_prefix,currency_code,clos,courant,rappro,fk_pays) VALUES (101,1,'TEST A','Test account','GB82WEST12345698765432','EUR',0,1,1,1),(202,2,'TEST B','Other company','DE89370400440532013000','EUR',0,1,1,1),(102,1,'TEST C','Second account','NL91ABNA0417164300','EUR',0,1,1,1)");
$service = new KreaBankService($db, $user, $langs);
$auto = new KreaBankAutoReconcile($db);
$checks = 0;
function checkAuto($condition, $label) { global $checks; if (!$condition) { throw new RuntimeException($label); } $checks++; }
function rejectAuto($callable, $label) { $caught = false; try { $callable(); } catch (Throwable $e) { $caught = true; } checkAuto($caught, $label); }
function countAuto($table) { global $db; return (int) $db->query('SELECT COUNT(*) n FROM kbauto_' . $table)->fetch_object()->n; }
function stageAuto($id, $amount = '125.00', $reference = 'TRANSFER123456', $account = 101, $entity = 1, $source = 'csv') {
	global $db;
	$db->query("INSERT INTO kbauto_kreabank_statement (rowid,entity,ref,source_type,bank_account_id,filename,currency,date_import,datec,fk_user_import) VALUES ($id,$entity,'ST-$id','$source',$account,'fixture.csv','EUR',NOW(),NOW(),7)");
	$db->query("INSERT INTO kbauto_kreabank_statement_line (rowid,entity,fk_statement,line_uid,line_rank,operation_date,value_date,amount,currency,description,payment_reference,direction,duplicate_hash,datec) VALUES ($id,$entity,$id,'fixture-$id',1,'2026-11-01','2026-11-01',$amount,'EUR','Received $reference','$reference'," . ($amount[0] === '-' ? '-1' : '1') . ",SHA2('line-$id',256),NOW())");
}
function bankAuto($id, $amount = '125.00', $reference = 'TRANSFER123456', $account = 101, $date = '2026-11-01') {
	global $db;
	$db->query("INSERT INTO kbauto_bank (rowid,fk_account,datec,dateo,datev,amount,label,num_chq,rappro) VALUES ($id,$account,NOW(),'$date','$date',$amount,'Existing movement','$reference',0)");
}
function settingAuto($mode, $accounts = '101', $cursor = '0') {
	global $db;
	foreach (array('MODE'=>$mode,'ACCOUNTS'=>$accounts,'CURSOR'=>$cursor) as $key=>$value) {
		$db->query("DELETE FROM kbauto_const WHERE entity=1 AND name='KREABANK_AUTO_NATIVE_$key'");
		$db->query("INSERT INTO kbauto_const (entity,name,value) VALUES (1,'KREABANK_AUTO_NATIVE_$key','$value')");
	}
}
stageAuto(1);
bankAuto(1); // Deliberate overlapping staged/native IDs.
$match = $service->getAutomaticNativeSuggestion(1, array(101));
checkAuto($match && $match['suggestion']['doc_type'] === 'native_bank' && $match['suggestion']['doc_id'] === 1, 'Strong exact reference finds existing native movement across ID namespaces');
checkAuto($service->getAutomaticNativeSuggestion(1, array(202)) === null, 'Account allowlist is enforced');
settingAuto('off');
checkAuto($auto->process($user, false)['status'] === 'disabled', 'Disabled by default');
$preview = $auto->process($user, true);
checkAuto($preview['eligible'] === 1 && $preview['reconciled'] === 0 && countAuto('kreabank_recon_audit') === 0, 'Preview performs no financial/audit writes');
checkAuto($auto->setting('CURSOR') === '0', 'Preview leaves cursor unchanged');
settingAuto('preview');
checkAuto($auto->process($user, false)['reconciled'] === 0, 'Scheduled preview cannot execute matching');
settingAuto('enabled');
// Revalidate source after initial suggestion, immediately before taking transaction locks.
$db->beforeBegin = static function () use ($db) { $db->query('UPDATE kbauto_bank SET rappro=1 WHERE rowid=1'); };
rejectAuto(static function () use ($service) { $service->reconcileAutomaticNativeLine(1, array(101)); }, 'Concurrent manual reconciliation invalidates automatic claim');
checkAuto((int) $db->query('SELECT status FROM kbauto_kreabank_statement_line WHERE rowid=1')->fetch_object()->status === 0, 'Lost claim leaves staging pending');
$db->query('UPDATE kbauto_bank SET rappro=0 WHERE rowid=1');
foreach (array('INSERT INTO kbauto_kreabank_recon_audit','UPDATE kbauto_kreabank_statement_line','UPDATE kbauto_bank SET') as $failure) {
	$db->fail = $failure;
	rejectAuto(static function () use ($service) { $service->reconcileAutomaticNativeLine(1, array(101)); }, 'Write failure is visible: ' . $failure);
	$db->fail = '';
	checkAuto((int) $db->query('SELECT rappro FROM kbauto_bank WHERE rowid=1')->fetch_object()->rappro === 0 && countAuto('kreabank_recon_audit') === 0 && $db->depth === 0, 'Native update and audit roll back atomically');
}
$completed = $auto->process($user, false);
checkAuto($completed['reconciled'] === 1 && $completed['failed'] === 0, 'Enabled scheduled run reconciles exact native match');
checkAuto(countAuto('bank') === 1 && countAuto('bank_url') === 0 && countAuto('kreabank_quick_entry') === 0, 'No bank movement, link, payment or quick entry is created');
checkAuto((int) $db->query('SELECT fk_native_bank_line FROM kbauto_kreabank_statement_line WHERE rowid=1')->fetch_object()->fk_native_bank_line === 1, 'Persist exact staged-to-native link');
$audit = json_decode($db->query('SELECT payload_json FROM kbauto_kreabank_recon_audit')->fetch_object()->payload_json, true);
checkAuto($audit['strategy'] === 'auto_native' && $audit['is_auto'] === 1 && $audit['native_bank_line_id'] === 1, 'Durable automatic audit identifies the selected movement');
checkAuto($auto->process($user, false)['reconciled'] === 0 && countAuto('kreabank_recon_audit') === 1, 'Retry cannot reconcile twice');
stageAuto(2, '50.00', 'UNIQUE123456'); bankAuto(12, '50.00', 'UNIQUE123456'); bankAuto(13, '50.00', 'UNIQUE123456');
checkAuto($service->getAutomaticNativeSuggestion(2, array(101)) === null, 'Ambiguous native candidates remain pending');
$db->query('DELETE FROM kbauto_bank WHERE rowid=13');
checkAuto($service->getAutomaticNativeSuggestion(2, array(101)) !== null, 'Removing ambiguity restores eligibility');
stageAuto(3, '50.00', 'UNIQUE123456');
checkAuto($service->getAutomaticNativeSuggestion(2, array(101)) === null && $service->getAutomaticNativeSuggestion(3, array(101)) === null, 'Two staged lines competing for one native movement both require review');
$db->query('UPDATE kbauto_kreabank_statement_line SET is_duplicate=1 WHERE rowid=3');
checkAuto($service->getAutomaticNativeSuggestion(3, array(101)) === null, 'Duplicate staging excluded');
$db->query('UPDATE kbauto_kreabank_statement_line SET status=3 WHERE rowid=2');
checkAuto($service->getAutomaticNativeSuggestion(2, array(101)) === null, 'Skipped staging excluded');
stageAuto(4, '80.00', 'REFERENCE8000'); bankAuto(14, '-80.00', 'REFERENCE8000');
checkAuto($service->getAutomaticNativeSuggestion(4, array(101)) === null, 'Opposite direction rejected');
$db->query('UPDATE kbauto_bank SET amount=80.01 WHERE rowid=14');
checkAuto($service->getAutomaticNativeSuggestion(4, array(101)) === null, 'One-cent difference is not an automatic exact match');
$db->query("UPDATE kbauto_bank SET amount=80,dateo='2026-10-01' WHERE rowid=14");
checkAuto($service->getAutomaticNativeSuggestion(4, array(101)) === null, 'Strong reference cannot override date boundary');
$db->query("UPDATE kbauto_bank SET dateo='2026-11-01',fk_account=102 WHERE rowid=14");
checkAuto($service->getAutomaticNativeSuggestion(4, array(101,102)) === null, 'Different native account rejected');
$db->query('UPDATE kbauto_bank SET fk_account=101 WHERE rowid=14');
$db->query("UPDATE kbauto_kreabank_statement_line SET currency='USD' WHERE rowid=4");
checkAuto($service->getAutomaticNativeSuggestion(4, array(101)) === null, 'Currency mismatch rejected');
stageAuto(5, '15.00', 'TRANSFER99999', 202, 2); bankAuto(15, '15.00', 'TRANSFER99999', 202);
checkAuto($service->getAutomaticNativeSuggestion(5, array(202)) === null, 'Other company staging denied even with explicit account ID');
$bad = clone $user; $bad->allowed = false;
rejectAuto(static function () use ($auto,$bad) { $auto->process($bad, true); }, 'Missing rights deny scheduled and preview use');
$bad->allowed = true; $bad->socid = 42;
rejectAuto(static function () use ($auto,$bad) { $auto->process($bad, true); }, 'External user denied');
$bad->socid = 0; $bad->entity = 2;
rejectAuto(static function () use ($auto,$bad) { $auto->process($bad, true); }, 'Company-bound user cannot run in another company');
$conf->entity = 2;
rejectAuto(static function () use ($auto,$user) { $auto->process($user, true); }, 'Runner context mismatch denied');
$conf->entity = 1;
// Feed corrections remain quarantined, even when an old amount has an exact match.
require_once __DIR__ . '/../class/KreaBankFintableSync.class.php';
(new KreaBankFintableSync($db))->ensureSchema();
stageAuto(6, '31.00', 'SOURCE123456', 101, 1, 'fintable'); bankAuto(16, '31.00', 'SOURCE123456');
checkAuto($service->getAutomaticNativeSuggestion(6, array(101)) === null, 'Feed line without imported source identity rejected');
$db->query("INSERT INTO kbauto_kreabank_feed_transaction (entity,fk_feed_account,provider_transaction_id,source_uid,financial_hash,content_hash,fk_statement_line,source_payload,status,datec) VALUES (1,1,'tx_test','uid',SHA2('a',256),SHA2('b',256),6,'{}','imported',NOW())");
checkAuto($service->getAutomaticNativeSuggestion(6, array(101)) !== null, 'Confirmed posted feed line eligible');
$db->query("UPDATE kbauto_kreabank_feed_transaction SET status='review',conflict_payload='{}'");
checkAuto($service->getAutomaticNativeSuggestion(6, array(101)) === null, 'Known source conflict excluded');
// Locks span independent connections, not just one PHP process.
$secondDb = new DoliDB();
$lock = 'kb-auto-' . substr(hash('sha256', MAIN_DB_PREFIX),0,24) . '-1';
$secondDb->query("SELECT GET_LOCK('$lock',0)");
rejectAuto(static function () use ($auto,$user) { $auto->process($user, true); }, 'Concurrent runner denied by database lock');
$secondDb->query("SELECT RELEASE_LOCK('$lock')");
// Scanning can pass permanently unmatched early rows and circle back later.
settingAuto('preview', '101', '1000000');
checkAuto($auto->process($user, false)['scanned'] === 0 && $auto->setting('CURSOR') === '0', 'End of scan resets cursor');
checkAuto($auto->process($user, false)['scanned'] > 0, 'Next pass revisits unresolved staging');
checkAuto(countAuto('bank') === 5 && $db->depth === 0, 'Synthetic native records preserved and no transaction left open');
// Description-only and short references must never authorize unattended writes.
stageAuto(7, '44.00', 'TRANSFERENCIA'); bankAuto(17, '44.00', 'TRANSFERENCIA');
checkAuto($service->getAutomaticNativeSuggestion(7, array(101)) === null, 'Generic transfer label rejected');
$db->query("UPDATE kbauto_bank SET num_chq='123' WHERE rowid=17");
$db->query("UPDATE kbauto_kreabank_statement_line SET payment_reference='123',description='123' WHERE rowid=7");
checkAuto($service->getAutomaticNativeSuggestion(7, array(101)) === null, 'Short numeric identity rejected');
$db->query("UPDATE kbauto_bank SET num_chq='ABC12345' WHERE rowid=17");
$db->query("UPDATE kbauto_kreabank_statement_line SET payment_reference='',description='ABC1234567' WHERE rowid=7");
checkAuto($service->getAutomaticNativeSuggestion(7, array(101)) === null, 'Reference prefix cannot authorize automatic matching');
$bad = clone $user; $bad->statut = 0;
$badService = new KreaBankService($db, $bad, $langs);
rejectAuto(static function () use ($badService) { $badService->reconcileAutomaticNativeLine(7,array(101)); }, 'Inactive user denied below the scheduler');
$conf->entity = 3;
$inactiveAuto = new KreaBankAutoReconcile($db);
rejectAuto(static function () use ($inactiveAuto,$user) { $inactiveAuto->process($user,true); }, 'Inactive company denied');
$conf->entity = 1;
// Candidate truncation must fail closed, even if the first candidate is otherwise unique.
stageAuto(8, '1.00', 'CAP12345678');
for ($i=1000; $i<1501; $i++) { bankAuto($i,'1.00',$i === 1000 ? 'CAP12345678' : 'OTHER'.$i); }
checkAuto($service->getAutomaticNativeSuggestion(8,array(101)) === null, 'Truncated candidate search cannot establish uniqueness');
$db->query('DELETE FROM kbauto_bank WHERE rowid>=1000');
// Native job creation and configuration use their real Dolibarr implementations.
// Exercise native job registration and configuration rollback as well as execution.
$auto->configure($user, 'preview', array(101));
$auto->configure($user, 'preview', array(101));
checkAuto(countAuto('cronjob') === 1 && $auto->setting('MODE') === 'preview', 'Repeated setup updates one company-specific native job');
rejectAuto(static function () use ($auto,$user) { $auto->configure($user, 'enabled', array(202)); }, 'Configuration cannot select another company account');
rejectAuto(static function () use ($auto,$user) { $auto->configure($user, 'enabled', array()); }, 'Enabled configuration requires an explicit account selection');
$auto->configure($user, 'off', array(101));
checkAuto((int) $db->query('SELECT status FROM kbauto_cronjob')->fetch_object()->status === 0, 'Disabling automation disables its native scheduled job');
$db->fail = 'INSERT INTO kbauto_const';
rejectAuto(static function () use ($auto,$user) { $auto->configure($user,'enabled',array(101)); }, 'Settings failure aborts enablement');
$db->fail = '';
checkAuto($auto->setting('MODE') === 'off' && countAuto('cronjob') === 1 && $db->depth === 0, 'Configuration rollback preserves disabled mode and the single job');
rejectAuto(static function () use ($service) {
 $service->reconcileLine(7,array(array('doc_type'=>'customer_invoice','fk_doc'=>123,'allocated_amount'=>44)), 'auto_native',1,'',200,array(101));
}, 'Automatic entry point rejects invoice payment creation before any write');
// An unmatched head of the queue must not consume every tick forever.
for ($i=100;$i<160;$i++) { stageAuto($i,'2.00','PENDING'.$i); }
settingAuto('preview','101','99');
$firstPage = $auto->process($user,false);
checkAuto($firstPage['scanned'] === 50 && $auto->setting('CURSOR') === '149', 'Scheduled scan is bounded to 50 staging IDs');
$nextPage = $auto->process($user,false);
checkAuto($nextPage['scanned'] === 10 && $auto->setting('CURSOR') === '159', 'Next tick advances past unmatched early rows');
checkAuto($auto->process($user,false)['scanned'] === 0 && $auto->setting('CURSOR') === '0', 'Full traversal circles back without losing pending rows');
echo 'OK: ' . $checks . " automatic reconciliation database checks (native AccountLine)\n";
