#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Execute the shipped one-by-one approval controller with synthetic dependencies.
 * No application bootstrap, credentials, database or financial records.
 */
require_once __DIR__ . '/../lib/kreabank_manual_review.lib.php';
require_once __DIR__ . '/../lib/kreabank_reconcile_queue.lib.php';

function GETPOST($key, $type = '') { return $GLOBALS['request'][$key] ?? ''; }
function GETPOSTINT($key) { return (int) GETPOST($key); }
function dol_now() { return 1800000000; }
function getDolGlobalInt($key) { return 0; }
function kreabankIsTokenValid() { return GETPOST('token') === 'synthetic-token'; }
function accessforbidden() { throw new RuntimeException('Forbidden'); }
function setEventMessages(...$args) { $GLOBALS['events'][] = $args; }
function kreabankWorkspaceUrl($page, $account) { return '/custom/kreabank/' . $page . '.php?account_context=' . $account; }
function kreabankNavigationAccount($id) { return (object) array('id'=>$id); }
function price2num($value, $mode = '') { return (float) $value; }
function dol_buildpath($path, $absolute = 0) { return $path; }

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
	public $partial = false;
	public function getLineById($id) {
		if ($id === 11) { return $this->line; }
		return in_array($id, array(12,13,14), true) ? array_merge($this->line, array('rowid'=>$id,'status'=>0)) : null;
	}
	public function getPendingLines(...$args) {
		$lines = array($this->getLineById(13), $this->getLineById(12), $this->getLineById(14));
		if ($this->line['status'] !== 2) { $lines[] = $this->line; }
		return $lines;
	}
	public function getPendingLinesCount(...$args) { return count($this->getPendingLines()); }
	public function getBulkReviewLine($id, $source) { return $this->owned && $id === 11 && $source === 'staged' ? $this->line : null; }
	public function getPaymentAccountReview(...$args) { return $this->accountReview; }
	public function getSuggestionsForLines($lines, ...$args) { return array(11=>$this->suggestions); }
	public function getLinkedPaymentDocuments(...$args) { return array(); }
	public function getSafeSuggestion(...$args) { return null; }
	public function reconcileLine(...$args) {
		if ($this->fail) { throw new RuntimeException('Synthetic rollback'); }
		$this->executions[] = $args;
		$this->line['status'] = $this->partial ? 1 : 2;
	}
	public function createQuickEntryAndReconcile(...$args) { $this->reconcileLine(...$args); }
	public function createQuickSupplierInvoiceAndReconcile(...$args) { $this->reconcileLine(...$args); return array('invoice_id'=>22,'invoice_ref'=>'SYNTHETIC'); }
	public function createQuickTaxContributionAndReconcile(...$args) { $this->reconcileLine(...$args); return array('social_contribution_id'=>22); }
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
	$request += array('queue_order'=>'11,12,14,13', 'queue_next'=>'12,14,13', 'queue_search'=>'pagamento');
	$request['review_signature'] = kreabankBulkReviewSignature($row, '7|9|81', $request['review_expires'], $_SESSION['kreabank_bulk_review_secret']);
}

function submitDirectReview($nativeWrite = true, $account = 81)
{
	global $service, $conf, $user, $langs;
	$action = 'confirm_document'; $canNativeWrite = $nativeWrite; $navigationAccountId = $account;
	$lineId = GETPOSTINT('line_id'); $dateTolerance = 3; $pendingSort = array('field'=>'b.dateo', 'order'=>'DESC');
	$queueOrder = kreabankReconcileQueueIds(GETPOST('queue_order'));
	$queueNext = kreabankReconcileQueueIds(GETPOST('queue_next'));
	$queueSearch = GETPOST('queue_search');
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

function loadDirectQueue($lineId, $params, $queueContinue, $account = 81)
{
	global $service;
	$navigationAccountId = $account; $pendingSort = array('field'=>'b.dateo', 'order'=>'DESC');
	$queueOrder = kreabankReconcileQueueIds($params['queue_order'] ?? '');
	$queueNext = kreabankReconcileQueueIds($params['queue_next'] ?? '');
	$queueSearch = $params['queue_search'] ?? ''; $selectedLine = null;
	$source = file_get_contents(__DIR__ . '/../reconcile.php');
	$start = strpos($source, '$requestedLineId = (int) $lineId;');
	$end = strpos($source, "\n\tif (\$selectedLine) {", $start);
	if ($start === false || $end === false) { throw new LogicException('Review pending selection boundary'); }
	eval(substr($source, $start, $end - $start));
	return array('selected'=>$selectedLine['rowid'] ?? 0, 'order'=>array_column($pendingLines, 'rowid'), 'search'=>$queueSearch, 'account'=>$navigationAccountId);
}

function submitQueueAction($action)
{
	global $service, $user, $langs;
	$canNativeWrite = true; $canQuickSupplierInvoice = true; $canQuickTaxEntry = true;
	$queueContinue = false; $lineId = GETPOSTINT('line_id');
	$quickEntryFailed = false; $quickEntryFormState = array();
	$reportActionException = static function ($e, ...$args) { $GLOBALS['events'][] = array('error', $e->getMessage()); };
	$sanitizeUiErrorMessage = static function ($value) { return $value; };
	$source = file_get_contents(__DIR__ . '/../reconcile.php');
	$following = array('reconcile'=>'quick_assign', 'quick_assign'=>'quick_entry', 'quick_entry'=>'skip_line');
	$start = strpos($source, "if (\$action === '" . $action . "' &&");
	$end = strpos($source, "if (\$action === '" . $following[$action] . "' &&", $start);
	if ($start === false || $end === false) { throw new LogicException('Review reconciliation action boundary'); }
	eval(substr($source, $start, $end - $start));
	return $queueContinue;
}

$checks = 0;
function checkDirect($condition, $label) { global $checks; $checks++; if (!$condition) { throw new RuntimeException($label); } }
resetDirectReview();
$redirect = submitDirectReview();
parse_str(parse_url($redirect, PHP_URL_QUERY), $redirectParams);
checkDirect($redirectParams['account_context'] === '81' && $redirectParams['sortfield'] === 'b.dateo' && $redirectParams['sortorder'] === 'DESC', 'Successful approval stays in one-by-one with account and sort context');
checkDirect(($redirectParams['queue_order'] ?? '') === '11,12,14,13' && ($redirectParams['queue_next'] ?? '') === '12,14,13' && ($redirectParams['queue_continue'] ?? '') === '1', 'Successful approval carries the exact displayed continuation through its redirect');
checkDirect(loadDirectQueue(0, $redirectParams, true) === array('selected'=>12,'order'=>array(12,14,13),'search'=>'pagamento','account'=>81), 'After approval, select the following displayed line instead of the first database row');
checkDirect(loadDirectQueue(11, $redirectParams, true, 0)['account'] === 0, 'Continuing an all-accounts queue does not silently narrow it to the completed source account');
checkDirect(loadDirectQueue(0, array('queue_order'=>'11,12,14,13','queue_next'=>'999,14,13'), true)['selected'] === 14, 'A removed or foreign next identity falls through to the next scoped pending row');
checkDirect(loadDirectQueue(0, array('queue_order'=>'11,12,14,13','queue_next'=>'999','queue_search'=>'missing'), true) === array('selected'=>12,'order'=>array(12,14,13),'search'=>'','account'=>81), 'Exhausted search results resume the remaining queue with search cleared');
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
resetDirectReview();
$service->accountReview = array('synthetic'=>true);
$service->suggestions[] = array_merge($service->suggestions[0], array('doc_type'=>'native_bank', 'doc_id'=>24,
	'amount_open'=>-47.66, 'bank_account_id'=>81));
$callbacks = kreabankManualReviewCallbacks($service, 3, 100);
$choices = $callbacks['prepareDocumentReviews']($service->line);
checkDirect(array_keys($choices) === array('native_bank__24') && $choices['native_bank__24']['other_account_warning']
	&& !$choices['native_bank__24']['preselected'], 'Other-account evidence permits only an explicit same-account native choice');
checkDirect($callbacks['prepareReviewRow']($service->line) === null, 'Matched review remains blocked by the same warning');
$request['review_doc'] = 'native_bank__24';
$request['review_signature'] = kreabankBulkReviewSignature($choices['native_bank__24'], '7|9|81', $request['review_expires'], $_SESSION['kreabank_bulk_review_secret']);
checkDirect(submitDirectReview() !== null && count($service->executions) === 1
	&& $service->executions[0][1][0]['fk_doc'] === 24 && $service->executions[0][7]['approval_context'] === 'selected_native',
	'Signed direct approval selects the existing same-account movement without an account-change action');
resetDirectReview();
$service->suggestions[0] = array_merge($service->suggestions[0], array('doc_type'=>'native_bank', 'bank_account_id'=>81, 'amount_open'=>-47.66));
$row = kreabankManualReviewCallbacks($service,3,100)['prepareDocumentReviews']($service->line)['native_bank__22'];
$request['review_doc'] = 'native_bank__22';
$request['review_signature'] = kreabankBulkReviewSignature($row,'7|9|81',$request['review_expires'],$_SESSION['kreabank_bulk_review_secret']);
$service->accountReview = array('new'=>true);
checkDirect(submitDirectReview() === null && !$service->executions, 'A newly appearing warning requires fresh displayed review');
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
checkDirect(loadDirectQueue(11, $request, false)['selected'] === 11, 'Failed reconciliation retains the selected line despite submitted next IDs');
resetDirectReview(); $service->line['status'] = 1;
checkDirect(loadDirectQueue(11, $request, true)['selected'] === 11, 'A successful partial allocation retains the current incomplete line');
foreach (array('customer_invoice', 'payment', 'payment_supplier', 'payment_linked', 'payment_supplier_linked', 'native_bank', 'tax', 'payment_tax') as $type) {
	resetDirectReview();
	$service->suggestions[0]['doc_type'] = $type;
	if ($type === 'native_bank') { $service->suggestions[0]['bank_account_id'] = 81; }
	if ($type === 'customer_invoice' || $type === 'payment' || $type === 'payment_linked') { $service->line['amount'] = 47.66; $service->line['direction'] = 1; }
	else { $service->suggestions[0]['amount_open'] = -47.66; }
	$callbacks = kreabankManualReviewCallbacks($service, 3, 100);
	$row = $callbacks['prepareDocumentReviews']($service->line)[$type . '__22'];
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
foreach (array('reconcile','quick_assign','payment_entry','supplier_invoice','tax_entry') as $flow) {
	foreach (array(false, true) as $fail) {
		resetDirectReview(); $service->fail = $fail;
		$request += array('selected_docs'=>array('native_bank__22'), 'doc_amount'=>array('native_bank__22'=>'47.66'),
			'doc_type'=>'native_bank', 'doc_id'=>22, 'allocated_amount'=>'47.66', 'entry_label'=>'Synthetic entry', 'entry_amount'=>'47.66',
			'entry_submit_action'=>$flow, 'entry_supplier_socid'=>91,
			'entry_product_lines_json'=>json_encode(array(array('product_id'=>51,'qty'=>1,'amount'=>47.66))));
		$action = in_array($flow, array('reconcile','quick_assign'), true) ? $flow : 'quick_entry';
		$advance = submitQueueAction($action);
		checkDirect($advance === !$fail && count($service->executions) === ($fail ? 0 : 1), $flow . ': continue only after successful native service execution');
		checkDirect(loadDirectQueue(11, $request, $advance)['selected'] === ($fail ? 11 : 12), $flow . ': successful completion follows the displayed successor; failure retains the source');
	}
}
resetDirectReview(); $service->partial = true;
$request += array('selected_docs'=>array('native_bank__22'), 'doc_amount'=>array('native_bank__22'=>'20.00'));
checkDirect(submitQueueAction('reconcile') && loadDirectQueue(11, $request, true)['selected'] === 11, 'Actual manual controller retains a partially allocated source');
checkDirect(kreabankReconcileQueueIds('12,12,0,-1,13,x,2147483648') === array(12,13), 'Navigation identities reject duplicates, invalid and oversized IDs');
checkDirect(kreabankReconcileQueueIds(array(12,13)) === array() && kreabankReconcileQueueIds(str_repeat('1', 2212)) === array(), 'Malformed or overlong navigation state is bounded');
checkDirect(count(kreabankReconcileQueueIds(implode(',', range(1,250)))) === 201, 'Navigation state cannot exceed the loaded queue bound');
checkDirect(kreabankReconcileQueueState(array(), array(11,12), array(12), true)['line_id'] === 0, 'An exhausted queue has no fabricated successor');
echo 'OK: ' . $checks . " direct one-by-one approval checks (synthetic, no database)\n";
