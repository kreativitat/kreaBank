#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

if (!function_exists('dol_string_nospecial')) {
	function dol_string_nospecial($value)
	{
		return (string) $value;
	}
}

if (!function_exists('price2num')) {
	function price2num($value, $type = 'MU')
	{
		$normalized = str_replace(',', '.', (string) $value);
		$normalized = preg_replace('/[^0-9\.\-]/', '', $normalized);
		if (!is_string($normalized) || $normalized === '' || $normalized === '-' || $normalized === '.') {
			return 0;
		}

		return (float) $normalized;
	}
}

if (!function_exists('dol_trunc')) {
	function dol_trunc($value, $size, $suffix = 'right', $charset = 'UTF-8', $dots = 1)
	{
		$text = (string) $value;
		$size = (int) $size;
		if ($size <= 0 || strlen($text) <= $size) {
			return $text;
		}

		return substr($text, 0, $size);
	}
}

if (!function_exists('dol_now')) {
	function dol_now()
	{
		return time();
	}
}

if (!function_exists('dol_print_date')) {
	function dol_print_date($timestamp, $format = '%Y%m%d')
	{
		$timestamp = (int) $timestamp;
		$phpFormat = 'Y-m-d';
		if ($format === '%Y%m%d') {
			$phpFormat = 'Ymd';
		}

		return date($phpFormat, $timestamp > 0 ? $timestamp : time());
	}
}

if (!function_exists('getDolGlobalInt')) {
	function getDolGlobalInt($name, $default = 0)
	{
		return (int) $default;
	}
}

if (!function_exists('getDolGlobalString')) {
	function getDolGlobalString($name, $default = '')
	{
		return (string) $default;
	}
}

if (!function_exists('isModEnabled')) {
	function isModEnabled($module)
	{
		return false;
	}
}

if (!defined('LOG_WARNING')) {
	define('LOG_WARNING', 4);
}

if (!function_exists('dol_syslog')) {
	function dol_syslog($message, $level = 0)
	{
		$GLOBALS['__kreabank_quickflow_test_logs'][] = array(
			'level' => (int) $level,
			'message' => (string) $message,
		);
	}
}

if (!isset($GLOBALS['conf']) || !is_object($GLOBALS['conf'])) {
	$GLOBALS['conf'] = new stdClass();
}
if (!isset($GLOBALS['conf']->entity)) { $GLOBALS['conf']->entity = 1; }
if (!isset($GLOBALS['conf']->global) || !is_object($GLOBALS['conf']->global)) {
	$GLOBALS['conf']->global = new stdClass();
}

/**
 * @param string $dolRoot
 * @return void
 */
function kreabankEnsureQuickFlowStubDolRoot($dolRoot)
{
	$stubFiles = array(
		'/compta/bank/class/account.class.php' => <<<'PHP'
<?php
class Account
{
	public function __construct($db)
	{
	}

	public function add_url_line($lineId, $urlId, $url, $label, $type)
	{
		return 1;
	}
}

class AccountLine
{
	public $num_releve = '';

	public function __construct($db)
	{
	}

	public function fetch($lineId)
	{
		return ((int) $lineId > 0 ? 1 : -1);
	}

	public function update_conciliation($user, $categoryId, $status)
	{
		return 1;
	}
}
PHP
		,
	);

	foreach ($stubFiles as $relativePath => $content) {
		$fullPath = rtrim((string) $dolRoot, '/').$relativePath;
		$directory = dirname($fullPath);
		if (!is_dir($directory)) {
			mkdir($directory, 0777, true);
		}
		if (!is_file($fullPath)) {
			file_put_contents($fullPath, $content);
		}
	}
}

$__kreabankQuickFlowRootDir = dirname(__DIR__);
$__kreabankQuickFlowDolRoot = rtrim((string) sys_get_temp_dir(), '/').'/kreabank_quickflow_stub_dol';
kreabankEnsureQuickFlowStubDolRoot($__kreabankQuickFlowDolRoot);
if (!defined('DOL_DOCUMENT_ROOT')) {
	define('DOL_DOCUMENT_ROOT', $__kreabankQuickFlowDolRoot);
}
if (!defined('DOL_URL_ROOT')) {
	define('DOL_URL_ROOT', '');
}
require_once $__kreabankQuickFlowRootDir.'/class/KreaBankService.class.php';

class KreaBankQuickFlowFakeDb
{
	public $begins = 0;
	public $commits = 0;
	public $rollbacks = 0;
	public $queries = array();
	public $lastError = '';
	public $failFailureStatusUpdates = false;

	public function begin()
	{
		$this->begins++;

		return true;
	}

	public function commit()
	{
		$this->commits++;

		return true;
	}

	public function rollback()
	{
		$this->rollbacks++;

		return true;
	}

	public function query($sql)
	{
		$this->queries[] = (string) $sql;
		if (strpos($sql, 'SELECT l.rowid FROM llx_kreabank_statement_line l') === 0 && preg_match('/l.rowid=([0-9]+)/', $sql, $source)) {
			return (object) array('rows'=>array((object) array('rowid'=>(int) $source[1])));
		}
		if ($this->failFailureStatusUpdates && strpos($sql, 'UPDATE llx_kreabank_quick_entry SET status = 9') === 0) {
			$this->lastError = 'Synthetic status update failure';
			return false;
		}

		return true;
	}

	public function fetch_object($result) { return is_object($result) ? array_shift($result->rows) : false; } // Only the explicit synthetic source exists.
	public function last_insert_id($table) { return 901; }
	public function plimit($limit = 0, $offset = 0) { return ' LIMIT ' . (int) $limit; }

	public function prefix()
	{
		return 'llx_';
	}

	public function escape($value)
	{
		return addslashes((string) $value);
	}

	public function idate($timestamp)
	{
		return date('Y-m-d H:i:s', (int) $timestamp);
	}

	public function lasterror()
	{
		return (string) $this->lastError;
	}
}

/** Generic entry wrapper fixture; the delegated executor owns its transaction. */
class KreaBankQuickFlowMovementServiceStub extends KreaBankService
{
	public $stubLine = array();
	public $schemaChecks = 0;
	public $lineReads = 0;
	public $reconcileCalls = array();
	public $audits = array();
	public $failReconciliation = false;

	public function __construct() {}

	protected function ensureQuickEntryTable() { $this->schemaChecks++; }

	public function getLineById($lineId)
	{
		$this->lineReads++;
		return $this->stubLine;
	}

	public function reconcileLine($lineId, $links, $strategy = 'manual', $isAuto = 0, $note = '', $confidenceScore = 0, $automaticAccounts = null, $reviewedRow = null)
	{
		$this->reconcileCalls[] = array('line_id'=>$lineId, 'links'=>$links, 'strategy'=>$strategy, 'is_auto'=>$isAuto);
		$this->db->begin();
		if ($this->failReconciliation) {
			$this->db->rollback();
			throw new RuntimeException('Synthetic delegated reconciliation failure');
		}
		$this->db->commit();
		return (int) $lineId;
	}

	protected function logAudit($type, $lineId = null, $reconciliationId = null, $payload = array())
	{
		$this->audits[] = array('type'=>$type, 'line_id'=>$lineId, 'payload'=>$payload);
		return true;
	}
}

/**
 * @param object $object
 * @param string $property
 * @param mixed $value
 * @return void
 */
function kreabankSetProtectedProperty($object, $property, $value)
{
	$reflection = new ReflectionClass($object);
	while ($reflection) {
		if ($reflection->hasProperty($property)) {
			$refProp = $reflection->getProperty($property);
			$refProp->setAccessible(true);
			$refProp->setValue($object, $value);
			return;
		}
		$reflection = $reflection->getParentClass();
	}
}

/**
 * Exercise removed APIs and the surviving generic entry wrapper with synthetic writes.
 * The fake executor checks wrapper error handling, not native financial lifecycle behavior.
 *
 * @param string $rootDir
 * @return array<int,string>
 */
function kreabankRunQuickFlowRuntimeAssertions($rootDir)
{
	$errors = array();
	$checks = 0;
	$assertTrue = static function ($condition, $label) use (&$errors, &$checks) {
		$checks++;
		if (!$condition) { $errors[] = $label; }
	};
	if (!is_readable(rtrim((string) $rootDir, '/') . '/class/KreaBankService.class.php')) {
		return array('Unable to read reconciliation service');
	}
	$makeService = static function () {
		$service = new KreaBankQuickFlowMovementServiceStub();
		$service->db = new KreaBankQuickFlowFakeDb();
		$service->user = (object) array('id'=>1);
		$service->langs = new KreaBankNullLangs();
		$service->stubLine = array('rowid'=>77, 'fk_statement'=>8, 'native_bank_line_id'=>0,
			'bank_account_id'=>11, 'direction'=>-1, 'amount'=>'-125.45000000',
			'allocated_amount'=>'0', 'status'=>0, 'currency'=>'EUR', 'operation_date'=>'2031-04-10');
		kreabankSetProtectedProperty($service, 'entity', (int) $GLOBALS['conf']->entity);
		return $service;
	};
	$writes = static function ($db) {
		return array_values(array_filter($db->queries, static function ($sql) {
			return preg_match('/^\s*(INSERT|UPDATE|DELETE|REPLACE|CREATE|ALTER|DROP|TRUNCATE)\b/i', $sql);
		}));
	};

	foreach (array('skipLine', 'createQuickTaxContributionAndReconcile', 'createQuickSupplierInvoiceAndReconcile') as $method) {
		$service = $makeService();
		$assertTrue(!method_exists(KreaBankService::class, $method), 'Removed API must be absent: ' . $method);
		$refused = false;
		try { $service->$method(77, 'Removed synthetic operation', 125.45); }
		catch (Error $e) { $refused = true; }
		catch (Exception $e) { /* A still-existing API must fail the absence assertion. */ }
		$assertTrue($refused && !$service->db->queries && !$service->schemaChecks && !$service->lineReads,
			'Removed API cannot read sources, install tables or write: ' . $method);
		$assertTrue(!$service->db->begins && !$service->db->commits && !$service->db->rollbacks && !$service->reconcileCalls && !$service->audits,
			'Removed API cannot execute or audit a reconciliation: ' . $method);
	}

	foreach (array('tax', 'tax_entry', 'supplier_invoice', 'skip_line', 'bulk_skip', '', 'PAYMENT') as $type) {
		$service = $makeService(); $refused = false;
		try { $service->createQuickEntryAndReconcile(77, $type, 'Synthetic entry', '125.45'); }
		catch (InvalidArgumentException $e) { $refused = true; }
		$assertTrue($refused && !$service->schemaChecks && !$service->lineReads && !$service->db->queries,
			'Unsupported generic type is rejected before table/source access: ' . $type);
		$assertTrue(!$service->reconcileCalls && !$service->audits && !$service->db->begins,
			'Unsupported generic type cannot execute financial work: ' . $type);
	}

	foreach (array('expense', 'bank_fee', 'payment') as $type) {
		$service = $makeService();
		$id = $service->createQuickEntryAndReconcile(77, $type, 'Synthetic entry', '125.45', 0, 'Synthetic note');
		$assertTrue($id === 901 && count($service->reconcileCalls) === 1, 'Supported generic entry delegates once: ' . $type);
		$call = $service->reconcileCalls[0];
		$assertTrue($call['line_id'] === 77 && $call['strategy'] === 'quick_entry' && $call['is_auto'] === 0
			&& $call['links'][0]['doc_type'] === 'quick_entry' && $call['links'][0]['fk_doc'] === 901
			&& abs((float) $call['links'][0]['allocated_amount'] - 125.45) < 0.00000001,
			'Generic entry retains its source, allocation and manual execution contract: ' . $type);
		$assertTrue($service->db->begins === 1 && $service->db->commits === 1 && !$service->db->rollbacks,
			'Successful delegated execution commits once: ' . $type);
		$writeSql = $writes($service->db);
		$assertTrue(count($writeSql) === 2 && strpos($writeSql[0], 'INSERT INTO llx_kreabank_quick_entry (') === 0
			&& strpos($writeSql[0], "'" . $type . "'") !== false
			&& strpos($writeSql[1], 'UPDATE llx_kreabank_quick_entry SET status = 1') === 0,
			'Wrapper writes only its generic quick entry and completion status: ' . $type);
		$assertTrue(count($service->audits) === 1 && $service->audits[0]['type'] === 'quick_entry'
			&& $service->audits[0]['payload']['entry_type'] === $type, 'Supported entry retains its audit: ' . $type);
	}

	foreach (array(false, true) as $failStatus) {
		$service = $makeService(); $service->failReconciliation = true;
		$service->db->failFailureStatusUpdates = $failStatus;
		$message = '';
		try { $service->createQuickEntryAndReconcile(77, 'bank_fee', 'Synthetic entry', '125.45'); }
		catch (RuntimeException $e) { $message = $e->getMessage(); }
		$assertTrue($message === 'Synthetic delegated reconciliation failure', 'Wrapper preserves the delegated failure cause');
		$assertTrue($service->db->begins === 1 && !$service->db->commits && $service->db->rollbacks === 1,
			'Failed delegated execution remains rolled back');
		$assertTrue(!$service->audits, 'Failed entry does not receive a success audit');
		$writeSql = $writes($service->db);
		$failureUpdates = array_values(array_filter($writeSql, static function ($sql) {
			return strpos($sql, 'UPDATE llx_kreabank_quick_entry SET status = 9') === 0;
		}));
		$assertTrue(count($failureUpdates) === ($failStatus ? 3 : 1), 'Failure status uses bounded retries');
		$assertTrue(isset($failureUpdates[0]) && strpos($failureUpdates[0], 'WHERE rowid = 901 AND entity = ' . (int) $GLOBALS['conf']->entity) !== false
			&& strpos($failureUpdates[0], 'Synthetic delegated reconciliation failure') !== false,
			'Failure status and cause remain scoped to the created entry');
		$deletes = array_values(array_filter($writeSql, static function ($sql) { return strpos($sql, 'DELETE ') === 0; }));
		$assertTrue($failStatus
			? $deletes === array('DELETE FROM llx_kreabank_quick_entry WHERE rowid = 901 AND entity = ' . (int) $GLOBALS['conf']->entity)
			: !$deletes, 'Only an entry that cannot retain its failure status is removed');
		$assertTrue(count($writeSql) === ($failStatus ? 5 : 2), 'Failure cleanup adds no invoice, tax, payment or native-bank writes');
	}
	$GLOBALS['__kreabank_quickflow_runtime_checks'] = $checks;
	return $errors;
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath((string) $argv[0]) === __FILE__) {
	$errors = kreabankRunQuickFlowRuntimeAssertions(dirname(__DIR__));
	if ($errors) {
		fwrite(STDERR, "Runtime bank-entry/removal assertions failed:" . PHP_EOL);
		foreach ($errors as $error) { fwrite(STDERR, ' - ' . $error . PHP_EOL); }
		exit(1);
	}
	echo 'OK: ' . $GLOBALS['__kreabank_quickflow_runtime_checks'] . " runtime bank-entry/removal checks (synthetic, no database)\n";
	exit(0);
}
