#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Execute the shipped one-by-one approval controller with synthetic dependencies.
 * No application bootstrap, credentials, database or financial records.
 */
require_once __DIR__ . '/../lib/kreabank_manual_review.lib.php';

function GETPOST($key, $type = '') { return $GLOBALS['request'][$key] ?? ''; }
function GETPOSTINT($key) { return (int) GETPOST($key); }
function dol_now() { return 1800000000; }
function getDolGlobalInt($key) { return 0; }
function kreabankIsTokenValid() { return GETPOST('token') === 'synthetic-token'; }
function accessforbidden() { throw new RuntimeException('Forbidden'); }
function setEventMessages(...$args) { $GLOBALS['events'][] = $args; }
function kreabankWorkspaceUrl($page, $account) { return '/custom/kreabank/' . $page . '.php?account_context=' . $account; }

class DirectReviewFixtureService
{
	public $line = array('rowid'=>11, 'fk_statement'=>3, 'bank_account_id'=>81, 'status'=>0, 'direction'=>-1,
		'amount'=>-47.66, 'allocated_amount'=>0, 'currency'=>'EUR', 'operation_date'=>'2026-11-03', 'description'=>'Synthetic statement');
	public $suggestions = array(array('doc_type'=>'supplier_invoice', 'doc_id'=>22, 'doc_ref'=>'INV-SYNTHETIC',
		'doc_date'=>'2026-11-03', 'thirdparty_name'=>'Synthetic supplier', 'amount_open'=>47.66, 'score'=>100, 'details'=>array('amount', 'date')));
	public $owned = true;
	public $accountReview = null;
	public $executions = array();
	public $fail = false;
	public function getBulkReviewLine($id, $source) { return $this->owned && $id === 11 && $source === 'staged' ? $this->line : null; }
	public function getPaymentAccountReview(...$args) { return $this->accountReview; }
	public function getSuggestionsForLines($lines, ...$args) { return array(11=>$this->suggestions); }
	public function getLinkedPaymentDocuments(...$args) { return array(); }
	public function getSafeSuggestion(...$args) { return null; }
	public function reconcileLine(...$args) {
		if ($this->fail) { throw new RuntimeException('Synthetic rollback'); }
		$this->executions[] = $args;
		$this->line['status'] = 2;
	}
}

function resetDirectReview()
{
	global $service, $request, $conf, $user, $langs, $events;
	$service = new DirectReviewFixtureService();
	$conf = (object) array('entity'=>7);
	$user = new class { public $id = 9; public $write = true; public function hasRight(...$args) { return $this->write; } };
	$langs = new class { public function trans($key, ...$args) { return $key; } };
	$events = array();
	$_SERVER['REQUEST_METHOD'] = 'POST';
	$_SESSION = array('kreabank_bulk_review_secret'=>str_repeat('synthetic-secret-', 4),
		'kreabank_bulkmatch_scan_state'=>array('old'), 'kreabank_matched_scan_state'=>array('old'));
	$callbacks = kreabankManualReviewCallbacks($service, 3, 100);
	$row = $callbacks['prepareReviewRow']($service->line);
	$request = array('token'=>'synthetic-token', 'line_id'=>11, 'line_source'=>'staged', 'review_doc'=>'supplier_invoice__22', 'review_expires'=>dol_now()+1800);
	$request['review_signature'] = kreabankBulkReviewSignature($row, '7|9|81', $request['review_expires'], $_SESSION['kreabank_bulk_review_secret']);
}

function submitDirectReview($nativeWrite = true, $account = 81)
{
	global $service, $conf, $user, $langs;
	$action = 'confirm_document'; $canNativeWrite = $nativeWrite; $navigationAccountId = $account;
	$lineId = GETPOSTINT('line_id'); $dateTolerance = 3; $pendingSort = array('field'=>'b.dateo', 'order'=>'DESC');
	$reportActionException = static function ($e, ...$args) { $GLOBALS['events'][] = array('error', $e->getMessage()); };
	$source = file_get_contents(__DIR__ . '/../reconcile.php');
	$start = strpos($source, "if (\$action === 'confirm_document') {");
	$end = strpos($source, "if (\$action === 'batch_safe'", $start);
	if ($start === false || $end === false) { throw new LogicException('Review direct approval controller boundary'); }
	$block = substr($source, $start, $end - $start);
	// Inspect the actual redirect instead of sending headers/exiting the test process.
	$block = str_replace("header('Location: ' . \$redirectUrl);\n\t\texit;", 'return $redirectUrl;', $block, $replaced);
	if ($replaced !== 1) { throw new LogicException('Review direct approval redirect boundary'); }
	return eval($block);
}

$checks = 0;
function checkDirect($condition, $label) { global $checks; $checks++; if (!$condition) { throw new RuntimeException($label); } }
resetDirectReview();
$redirect = submitDirectReview();
checkDirect($redirect === '/custom/kreabank/reconcile.php?account_context=81&sortfield=b.dateo&sortorder=DESC', 'Successful approval stays in one-by-one with account and sort context');
checkDirect(count($service->executions) === 1 && $service->executions[0][1][0]['doc_type'] === 'supplier_invoice', 'Direct submit executes the reviewed invoice');
checkDirect($service->executions[0][2] === 'bulk_manual' && $service->executions[0][3] === 0 && $service->executions[0][7]['suggestion']['doc_id'] === 22, 'Direct submit retains shared manual strategy and exact reviewed pair');
checkDirect(!isset($_SESSION['kreabank_bulkmatch_scan_state'], $_SESSION['kreabank_matched_scan_state']), 'Successful execution invalidates stale scan caches');
checkDirect(submitDirectReview() === null && count($service->executions) === 1, 'A repeated approval cannot execute an already reconciled source');

foreach (array('line_id'=>12, 'line_source'=>'native', 'review_doc'=>'supplier_invoice__23', 'review_signature'=>str_repeat('0', 64), 'review_expires'=>dol_now()-1) as $field=>$value) {
	resetDirectReview(); $request[$field] = $value;
	checkDirect(submitDirectReview() === null && !$service->executions && $events, 'Changed or expired request is rejected: ' . $field);
}
foreach (array('amount'=>-47.65, 'currency'=>'USD', 'description'=>'Changed evidence', 'status'=>2) as $field=>$value) {
	resetDirectReview(); $service->line[$field] = $value;
	checkDirect(submitDirectReview() === null && !$service->executions, 'Changed persisted source is rejected: ' . $field);
}
foreach (array('doc_ref'=>'OTHER', 'amount_open'=>47.67, 'thirdparty_name'=>'Different party', 'doc_date'=>'2026-11-04') as $field=>$value) {
	resetDirectReview(); $service->suggestions[0][$field] = $value;
	checkDirect(submitDirectReview() === null && !$service->executions, 'Changed target is rejected: ' . $field);
}
resetDirectReview(); $service->suggestions[] = array_merge($service->suggestions[0], array('doc_id'=>23));
checkDirect(submitDirectReview() !== null && $service->executions[0][1][0]['fk_doc'] === 22, 'An explicit signed card selects its own invoice among equal candidates');
resetDirectReview(); $service->suggestions[] = array_merge($service->suggestions[0], array('doc_id'=>23));
$callbacks = kreabankManualReviewCallbacks($service, 3, 100);
$choices = $callbacks['prepareDocumentReviews']($service->line);
checkDirect(count($choices) === 2 && !$choices['supplier_invoice__22']['preselected'] && !$choices['supplier_invoice__23']['is_bulk_safe'], 'Equal candidates have separate manual reviews and no automatic preselection');
$request['review_doc'] = 'supplier_invoice__23';
checkDirect(submitDirectReview() === null && !$service->executions, 'A card cannot reuse the other invoice signature');
$request['review_signature'] = kreabankBulkReviewSignature($choices['supplier_invoice__23'], '7|9|81', $request['review_expires'], $_SESSION['kreabank_bulk_review_secret']);
$service->suggestions = array_reverse($service->suggestions);
checkDirect(submitDirectReview() !== null && $service->executions[0][1][0]['fk_doc'] === 23, 'Explicit second-invoice approval survives candidate reordering');
resetDirectReview(); $service->accountReview = array('synthetic'=>true);
checkDirect(submitDirectReview() === null && !$service->executions, 'Wrong-account warnings block direct invoice approval');
resetDirectReview(); $service->owned = false;
checkDirect(submitDirectReview() === null && !$service->executions, 'Foreign-company source cannot be approved');
resetDirectReview(); $conf->entity = 8;
checkDirect(submitDirectReview() === null && !$service->executions, 'Company change invalidates review');
resetDirectReview(); $user->id = 10;
checkDirect(submitDirectReview() === null && !$service->executions, 'User change invalidates review');
resetDirectReview();
checkDirect(submitDirectReview(true, 82) === null && !$service->executions, 'Account-context change invalidates review');
foreach (array('GET', 'token', 'write', 'native') as $denied) {
	resetDirectReview();
	if ($denied === 'GET') { $_SERVER['REQUEST_METHOD'] = 'GET'; }
	if ($denied === 'token') { $request['token'] = 'invalid'; }
	if ($denied === 'write') { $user->write = false; }
	try { submitDirectReview($denied !== 'native'); throw new LogicException('Denied request was accepted'); }
	catch (RuntimeException $e) { checkDirect($e->getMessage() === 'Forbidden' && !$service->executions, 'POST, CSRF and current permissions remain mandatory: ' . $denied); }
}
resetDirectReview(); $service->fail = true;
checkDirect(submitDirectReview() === null && !$service->executions && $events[0][1] === 'Synthetic rollback', 'Service failure stays on the current line with an error');
foreach (array('customer_invoice', 'payment', 'payment_supplier', 'payment_linked', 'payment_supplier_linked', 'native_bank', 'tax', 'payment_tax') as $type) {
	resetDirectReview();
	$service->suggestions[0]['doc_type'] = $type;
	if ($type === 'native_bank') { $service->suggestions[0]['bank_account_id'] = 81; }
	if ($type === 'customer_invoice' || $type === 'payment' || $type === 'payment_linked') { $service->line['amount'] = 47.66; $service->line['direction'] = 1; }
	else { $service->suggestions[0]['amount_open'] = -47.66; }
	$callbacks = kreabankManualReviewCallbacks($service, 3, 100);
	$row = $callbacks['prepareReviewRow']($service->line);
	$request['review_doc'] = $type . '__22';
	$request['review_signature'] = kreabankBulkReviewSignature($row, '7|9|81', $request['review_expires'], $_SESSION['kreabank_bulk_review_secret']);
	checkDirect(submitDirectReview() !== null && count($service->executions) === 1 && $service->executions[0][1][0]['doc_type'] === $type, 'Direct review retains supported payment semantics: ' . $type);
}
foreach (array(array('bank_account_id',82), array('amount_open',47.66), array('amount_open',-48.00)) as $change) {
	resetDirectReview();
	$service->suggestions[0] = array_merge($service->suggestions[0], array('doc_type'=>'native_bank', 'bank_account_id'=>81, 'amount_open'=>-47.66));
	$callbacks = kreabankManualReviewCallbacks($service, 3, 100);
	$row = $callbacks['prepareDocumentReviews']($service->line)['native_bank__22'];
	$request['review_doc'] = 'native_bank__22';
	$request['review_signature'] = kreabankBulkReviewSignature($row, '7|9|81', $request['review_expires'], $_SESSION['kreabank_bulk_review_secret']);
	$service->suggestions[0][$change[0]] = $change[1];
	checkDirect(submitDirectReview() === null && !$service->executions, 'Native movement account, sign and full persisted amount are revalidated: ' . $change[0]);
}
foreach (array('tax_id','tax_type_id','tax_payment_mode_id') as $field) {
    resetDirectReview();
    $service->suggestions[0] = array_merge($service->suggestions[0], array('doc_type'=>'tax','amount_open'=>-47.66,'tax_id'=>22,'tax_type_id'=>1,'tax_payment_mode_id'=>1));
    $row = kreabankManualReviewCallbacks($service,3,100)['prepareDocumentReviews']($service->line)['tax__22'];
    $request['review_doc']='tax__22';
    $request['review_signature']=kreabankBulkReviewSignature($row,'7|9|81',$request['review_expires'],$_SESSION['kreabank_bulk_review_secret']);
    $service->suggestions[0][$field]++;
    checkDirect(submitDirectReview()===null && !$service->executions, 'Tax identity and payment method are signed: '.$field);
}
echo 'OK: ' . $checks . " direct one-by-one approval checks (synthetic, no database)\n";
