#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Actual controller, synthetic session/service only; no ERP or business data.
 */
function GETPOST($key, $filter = '') { return $GLOBALS['draftRequest'][$key] ?? ''; }
function kreabankIsTokenValid() { return $GLOBALS['draftToken']; }
function accessforbidden() { throw new RuntimeException('Denied'); }
function setEventMessages(...$args) {}
function submitDraftReview($saved, $request = array(), $permission = true, $method = 'POST', $token = true, $fail = false)
{
	$GLOBALS['draftRequest'] = $request + array('draft_invoice_review'=>'review');
	$GLOBALS['draftToken'] = $token;
	$_SERVER['REQUEST_METHOD'] = $method;
	$_SESSION = array('kreabank_draft_invoices'=>array('review'=>$saved), 'kreabank_matched_scan_state'=>array('old'));
	$action = 'validate_draft_invoice'; $lineId = 11; $navigationAccountId = 101; $canNativeWrite = $permission;
	$openDocumentsSearch = 'PROV-11';
	$conf = (object) array('entity'=>1);
	$user = new class($permission) {
		public $id = 7; private $allowed;
		public function __construct($allowed) { $this->allowed=$allowed; }
		public function hasRight(...$path) { return $this->allowed; }
	};
	$langs = new class { public function trans($key) { return $key; } };
	$service = new class($fail) {
		public $calls = array(); private $fail;
		public function __construct($fail) { $this->fail=$fail; }
		public function validateDraftInvoice(...$args) {
			if ($this->fail) { throw new RuntimeException('Synthetic validation failure'); }
			$this->calls[]=$args;
		}
	};
	$errors = array();
	$reportActionException = static function ($e, ...$args) use (&$errors) { $errors[]=$e->getMessage(); };
	$source = file_get_contents(__DIR__.'/../reconcile.php');
	$start = strpos($source, "if (\$action === 'validate_draft_invoice') {");
	$end = strpos($source, "if (\$action === 'confirm_document') {", $start);
	if ($start === false || $end === false) { throw new LogicException('Draft controller boundaries changed'); }
	try { eval(substr($source, $start, $end-$start)); } catch (Throwable $e) { $errors[]=$e->getMessage(); }
	return array('calls'=>$service->calls, 'errors'=>$errors, 'session'=>$_SESSION, 'search'=>$openDocumentsSearch);
}
$valid = array('expires'=>time()+900, 'entity'=>1, 'user'=>7, 'line'=>11, 'account'=>101,
	'source'=>'staged', 'type'=>'supplier_invoice', 'invoice'=>501, 'snapshot'=>str_repeat('a',64), 'line_facts'=>array('amount'=>'-125.50000000'));
$checks = 0;
function checkDraftController($ok, $label) { global $checks; $checks++; if (!$ok) { throw new RuntimeException($label); } }
$result = submitDraftReview($valid, array('invoice'=>999, 'type'=>'customer_invoice', 'snapshot'=>'tampered'));
checkDraftController($result['calls'] === array(array(11,'staged',101,'supplier_invoice',501,str_repeat('a',64),$valid['line_facts'])), 'Only server-stored reviewed identities reach validation');
checkDraftController(!$result['errors'] && $result['search'] === '' && !isset($result['session']['kreabank_matched_scan_state']), 'Validation reloads fresh normal candidates and invalidates old scan results');
checkDraftController(empty($result['session']['kreabank_draft_invoices']), 'Review is consumed exactly once');
foreach (array('expires'=>time()-1, 'entity'=>2, 'user'=>8, 'line'=>12, 'account'=>102) as $field=>$value) {
	$result = submitDraftReview(array_replace($valid, array($field=>$value)));
	checkDraftController(!$result['calls'] && $result['errors'], 'Expired or changed scope is denied: '.$field);
}
$result = submitDraftReview($valid, array('draft_invoice_review'=>'missing'));
checkDraftController(!$result['calls'] && $result['errors'], 'Unknown or repeated review is denied');
foreach (array(array(false,'POST',true), array(true,'GET',true), array(true,'POST',false)) as $case) {
	$result = submitDraftReview($valid, array(), ...$case);
	checkDraftController(!$result['calls'] && $result['errors'], 'Current permissions, POST and CSRF are mandatory');
}
$result = submitDraftReview($valid, array(), true, 'POST', true, true);
checkDraftController(!$result['calls'] && $result['errors'] && empty($result['session']['kreabank_draft_invoices']), 'Failure stays reviewable and consumes stale authorization');
echo "OK: $checks draft invoice controller checks (synthetic, no database)\n";
