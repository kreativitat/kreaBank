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
		'/compta/sociales/class/chargesociales.class.php' => <<<'PHP'
<?php
class ChargeSociales
{
	const STATUS_UNPAID = 0;

	public $type = 0;
	public $label = '';
	public $date_ech = 0;
	public $period = 0;
	public $periode = 0;
	public $amount = 0.0;
	public $mode_reglement_id = 0;
	public $fk_account = 0;
	public $paye = 0;
	public $type_label = 'Stub Social Type';
	public $ref = 'CS-STUB';
	public $fk_user = 0;

	public function __construct($db)
	{
	}

	public function create($user)
	{
		return 501;
	}

	public function fetch($id)
	{
		$this->ref = 'CS-'.((int) $id);
		if ($this->type_label === '') {
			$this->type_label = 'Stub Social Type';
		}

		return ((int) $id > 0 ? 1 : 0);
	}
}
PHP
		,
		'/compta/sociales/class/paymentsocialcontribution.class.php' => <<<'PHP'
<?php
class PaymentSocialContribution
{
	public $chid = 0;
	public $datepaye = 0;
	public $amounts = array();
	public $paiementtype = 0;
	public $num_payment = '';
	public $note = '';
	public $note_private = '';

	public function __construct($db)
	{
	}

	public function create($user, $dummy = 0)
	{
		if ($this->num_payment === '') {
			$this->num_payment = 'PAYSC-STUB';
		}

		return 601;
	}

	public function update_fk_bank($lineId)
	{
		return ((int) $lineId > 0 ? 1 : 0);
	}

	public function fetch($id)
	{
		return ((int) $id > 0 ? 1 : 0);
	}
}
PHP
		,
		'/user/class/user.class.php' => <<<'PHP'
<?php
class User
{
	public function __construct($db)
	{
	}

	public function fetch($id)
	{
		return ((int) $id > 0 ? 1 : 0);
	}

	public function getFullName($langs)
	{
		return 'Stub User';
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

		return true;
	}

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

class KreaBankQuickFlowNativeTaxStub
{
	public $linkTypes = array();
	public $conciliateCalls = 0;
	public $markReconciledCalls = 0;
	public $unconciliateCalls = 0;
	public $markPendingCalls = 0;

	public function addLineLink($nativeLineId, $urlId, $url, $label, $type)
	{
		$this->linkTypes[] = (string) $type;
		return count($this->linkTypes) + 100;
	}

	public function conciliateLine($nativeLineId, $statementLabel = '', $categoryId = 0)
	{
		$this->conciliateCalls++;
		return 1;
	}

	public function markStatementLineReconciled($lineId, $allocatedAmount = 0.0, $nativeLineId = 0)
	{
		$this->markReconciledCalls++;
		return false; // Force mid-flow failure after conciliation.
	}

	public function unconciliateLine($nativeLineId)
	{
		$this->unconciliateCalls++;
		return 1;
	}

	public function markStatementLinePending($lineId)
	{
		$this->markPendingCalls++;
		return true;
	}
}

class KreaBankQuickFlowTaxServiceStub extends KreaBankService
{
	public $stubLine = array();

	public function __construct()
	{
	}

	public function getLineById($lineId)
	{
		return $this->stubLine;
	}

	protected function resolveSocialContributionTypeId($preferredTypeId = 0)
	{
		$socialTypeId = (int) $preferredTypeId;
		return ($socialTypeId > 0 ? $socialTypeId : 1);
	}

	protected function resolveNativeLineIdFromStatementLine($line, $createIfMissing = true)
	{
		return 321;
	}

	protected function buildNativeLineContext($line, $nativeLineId)
	{
		$line['bank_account_id'] = 11;
		return $line;
	}

	protected function resolveLineDateTimestamp($line)
	{
		return 1700000000;
	}

	protected function resolvePaymentModeId()
	{
		return 4;
	}

	protected function resolveLinePaymentRef($line)
	{
		return 'PAY-SC-STUB';
	}

	protected function extractObjectErrorMessage($object, $default = '')
	{
		return (string) $default;
	}

	protected function logAudit($type, $lineId = null, $reconciliationId = null, $payload = array())
	{
		return 1;
	}
}

class KreaBankQuickFlowSupplierServiceStub extends KreaBankService
{
	public $stubLine = array();
	public $cleanupCalls = array();
	public $supplierCreateCalls = 0;
	public $reconcileCalls = 0;

	public function __construct()
	{
	}

	public function getLineById($lineId)
	{
		return $this->stubLine;
	}

	protected function createSupplierInvoiceForBankLine($line, $supplierId, $label, $amount, $note = '', $supplierRef = '', $invoiceProductLines = array())
	{
		$this->supplierCreateCalls++;
		return array(
			'invoice_id' => 901,
			'invoice_ref' => 'FS901',
			'supplier_id' => (int) $supplierId,
			'supplier_name' => 'Supplier Stub',
		);
	}

	public function reconcileLine($lineId, $links, $strategy = 'manual', $isAuto = 0, $note = '', $confidenceScore = 0)
	{
		$this->reconcileCalls++;
		throw new Exception('forced reconcile failure');
	}

	protected function cleanupFailedQuickSupplierInvoiceFlow($invoiceId, $failureMessage = '')
	{
		$this->cleanupCalls[] = array(
			'invoice_id' => (int) $invoiceId,
			'message' => (string) $failureMessage,
		);
	}

	protected function logAudit($type, $lineId = null, $reconciliationId = null, $payload = array())
	{
		return 1;
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
 * Run runtime assertions for quick-flow reconciliation rollback/cleanup paths.
 *
 * @param string $rootDir
 * @return array<int,string>
 */
function kreabankRunQuickFlowRuntimeAssertions($rootDir)
{
	$errors = array();
	$assertTrue = static function ($condition, $label) use (&$errors) {
		if (!$condition) {
			$errors[] = $label;
		}
	};

	$serviceFile = rtrim((string) $rootDir, '/').'/class/KreaBankService.class.php';
	if (!is_readable($serviceFile)) {
		return array('Unable to read service file: '.$serviceFile);
	}

	$langsStub = new class {
		public function trans($key)
		{
			return (string) $key;
		}
	};
	$userStub = (object) array('id' => 1);

	// Quick tax flow: simulate failure after native conciliation and assert compensating cleanup behavior.
	$taxDb = new KreaBankQuickFlowFakeDb();
	$taxNative = new KreaBankQuickFlowNativeTaxStub();
	$taxService = new KreaBankQuickFlowTaxServiceStub();
	$taxService->db = $taxDb;
	$taxService->user = $userStub;
	$taxService->langs = $langsStub;
	$taxService->stubLine = array(
		'rowid' => 77,
		'direction' => -1,
		'amount' => -125.45,
		'allocated_amount' => 0.0,
		'description' => 'Tax debit',
		'payment_reference' => 'AT-REF',
		'counterparty_name' => 'Tax Authority',
		'statement_ref' => 'KB-TEST-TAX',
		'bank_account_id' => 11,
	);
	kreabankSetProtectedProperty($taxService, 'entity', 1);
	kreabankSetProtectedProperty($taxService, 'native', $taxNative);

	$taxExceptionMessage = '';
	try {
		$taxService->createQuickTaxContributionAndReconcile(77, 'Tax test', 125.45, 'Tax note', 3);
		$errors[] = 'Quick tax flow should throw when markStatementLineReconciled fails';
	} catch (Exception $e) {
		$taxExceptionMessage = (string) $e->getMessage();
	}
	$assertTrue(strpos($taxExceptionMessage, 'Failed to mark statement line as reconciled') !== false, 'Quick tax flow should expose reconciliation-mark failure');
	$assertTrue($taxDb->begins === 1, 'Quick tax flow should open one outer transaction');
	$assertTrue($taxDb->commits === 0, 'Quick tax flow should not commit when reconciliation mark fails');
	$assertTrue($taxDb->rollbacks === 1, 'Quick tax flow should rollback on mid-flow failure');
	$assertTrue($taxNative->conciliateCalls === 1, 'Quick tax flow should reach native conciliation before forced failure');
	$assertTrue($taxNative->unconciliateCalls === 1, 'Quick tax flow should undo native conciliation during cleanup');
	$assertTrue($taxNative->markPendingCalls === 1, 'Quick tax flow should mark statement line pending during cleanup');
	$assertTrue(in_array('payment_sc', $taxNative->linkTypes, true), 'Quick tax flow should create payment_sc bank link before failure');
	$assertTrue(in_array('sc', $taxNative->linkTypes, true), 'Quick tax flow should create social contribution bank link before failure');
	$joinedTaxQueries = implode("\n", (array) $taxDb->queries);
	$assertTrue(strpos($joinedTaxQueries, 'DELETE FROM llx_paiementcharge') !== false, 'Quick tax cleanup should delete orphan paiementcharge rows');
	$assertTrue(strpos($joinedTaxQueries, 'DELETE FROM llx_chargesociales') !== false, 'Quick tax cleanup should delete orphan chargesociales rows');

	// Quick supplier invoice flow: simulate reconcile failure and assert compensating cleanup callback.
	$supplierDb = new KreaBankQuickFlowFakeDb();
	$supplierService = new KreaBankQuickFlowSupplierServiceStub();
	$supplierService->db = $supplierDb;
	$supplierService->user = $userStub;
	$supplierService->langs = $langsStub;
	$supplierService->stubLine = array(
		'rowid' => 88,
		'direction' => -1,
		'amount' => -210.00,
		'allocated_amount' => 0.0,
		'description' => 'Supplier debit',
		'payment_reference' => 'INV-TEST',
		'counterparty_name' => 'Supplier A',
	);
	kreabankSetProtectedProperty($supplierService, 'entity', 1);

	$supplierExceptionMessage = '';
	try {
		$supplierService->createQuickSupplierInvoiceAndReconcile(88, 'Supplier invoice', 210.00, 44, 'Supplier note', 'SREF-1');
		$errors[] = 'Quick supplier flow should throw when reconcileLine fails';
	} catch (Exception $e) {
		$supplierExceptionMessage = (string) $e->getMessage();
	}
	$assertTrue(strpos($supplierExceptionMessage, 'forced reconcile failure') !== false, 'Quick supplier flow should rethrow reconcile failure');
	$assertTrue($supplierService->supplierCreateCalls === 1, 'Quick supplier flow should create supplier invoice before reconcile attempt');
	$assertTrue($supplierService->reconcileCalls === 1, 'Quick supplier flow should attempt reconcile once');
	$assertTrue(count($supplierService->cleanupCalls) === 1, 'Quick supplier flow should trigger compensating cleanup once');
	if (!empty($supplierService->cleanupCalls[0])) {
		$cleanupCall = $supplierService->cleanupCalls[0];
		$assertTrue((int) $cleanupCall['invoice_id'] === 901, 'Quick supplier cleanup should target created invoice id');
		$assertTrue(strpos((string) $cleanupCall['message'], 'forced reconcile failure') !== false, 'Quick supplier cleanup should receive failure cause');
	}

	return $errors;
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath((string) $argv[0]) === __FILE__) {
	$rootDir = dirname(__DIR__);
	$errors = kreabankRunQuickFlowRuntimeAssertions($rootDir);
	if (!empty($errors)) {
		fwrite(STDERR, "Runtime quick-flow reconciliation assertions failed:".PHP_EOL);
		foreach ($errors as $error) {
			fwrite(STDERR, ' - '.$error.PHP_EOL);
		}
		exit(1);
	}

	echo "OK: runtime quick-flow reconciliation assertions".PHP_EOL;
	exit(0);
}
