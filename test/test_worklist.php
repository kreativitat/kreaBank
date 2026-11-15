#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Synthetic request/list checks. No application bootstrap or database access.
 */
require_once __DIR__ . '/../lib/kreabank_worklist.lib.php';
require_once __DIR__ . '/../lib/kreabank_worklist_view.lib.php';

$checks = 0;
function checkWorklist($condition, $message)
{
	global $checks;
	if (!$condition) { throw new RuntimeException($message); }
	$checks++;
}
function rejectWorklist($callable, $message)
{
	$caught = false;
	try { $callable(); } catch (InvalidArgumentException $e) { $caught = true; }
	checkWorklist($caught, $message);
}
function worklistKeys($rows) { return array_column($rows, 'key'); }

$accountReview = array(array('bank_line_id'=>81,'bank_account_id'=>102));
checkWorklist(kreabankWorklistCorrectionMatches($accountReview,array('rowid'=>81,'fk_account'=>102)), 'The bank reference opens its exact reviewed native payment');
checkWorklist(!kreabankWorklistCorrectionMatches($accountReview,array('rowid'=>82,'fk_account'=>102)), 'Another payment in the same bank cannot replace the displayed correction');
checkWorklist(!kreabankWorklistCorrectionMatches($accountReview,array('rowid'=>81,'fk_account'=>103)), 'A changed payment account cannot reuse the displayed correction');
checkWorklist(!kreabankWorklistCorrectionMatches(array_merge($accountReview,$accountReview),array('rowid'=>81,'fk_account'=>102)), 'Competing reviewed payments require an explicit choice');

$state = kreabankWorklistState(array(), 25);
checkWorklist($state['limit'] === 25 && $state['page'] === 0, 'Configured native list size and first page are the defaults');
checkWorklist($state['filters']['status'] === 'pending' && $state['sort']['field'] === 'operation_date' && $state['sort']['order'] === 'DESC', 'Pending newest-first is the default list');
checkWorklist(kreabankWorklistState(array('sortfield'=>'operation_date','sortorder'=>'asc'), 25)['params']['sortorder'] === 'ASC', 'An explicit ascending date choice survives normalization and read navigation');
checkWorklist(kreabankWorklistState(array('sortfield'=>'amount'), 25)['sort']['order'] === 'ASC', 'Other columns retain their ascending default');
$state = kreabankWorklistState(array('page'=>4,'limit'=>15,'search_description'=>'  Example  ','button_search_x'=>'x'), 25);
checkWorklist($state['page'] === 0 && $state['limit'] === 15 && $state['filters']['description'] === 'Example', 'Header searching resets the page while retaining list size');
$state = kreabankWorklistState(array('pageplusone'=>3,'pageplusoneold'=>1,'button_search_x'=>'x'), 25);
checkWorklist($state['page'] === 2, 'Changed native one-based page entry takes precedence over an implicit search button');
foreach (array('button_search','button_search_x','button_search.x') as $button) {
	checkWorklist(kreabankWorklistState(array('page'=>4,$button=>'x'), 25)['page'] === 0, 'Native search button variants return to page one');
}
foreach (array('button_removefilter','button_removefilter_x','button_removefilter.x') as $button) {
	$state = kreabankWorklistState(array('page'=>5,'limit'=>15,'search_date_from'=>'2026-02-30','search_amount'=>'not a number','search_description'=>'old',$button=>'x'), 25);
	checkWorklist($state['page'] === 0 && $state['limit'] === 15 && $state['filters']['status'] === 'pending'
		&& count(array_filter($state['filters'], static function ($value, $key) { return $key !== 'status' && $value !== ''; }, ARRAY_FILTER_USE_BOTH)) === 0,
		'Clear filters recovers even from malformed inputs without retaining a hidden filter');
	$state = kreabankWorklistState(array('page'=>5,'search_account'=>'101','search_status'=>'reconciled','search_description'=>'old',$button=>'x'),25);
	checkWorklist($state['filters']['account']==='101' && $state['filters']['status']==='reconciled' && $state['filters']['description']==='' && $state['page']===0,
		'Clearing column filters preserves the selected bank and status view');
}
$state = kreabankWorklistState(array('page'=>-9,'limit'=>99999,'sortfield'=>'amount; DROP TABLE bank','sortorder'=>'DESC; SELECT 1','token'=>'secret','action'=>'approve','review'=>'signed','line_id'=>123,'account_context'=>2,'kind'=>'all','selected'=>array(9),'search_description'=>array('injected')), 25);
checkWorklist($state['page'] === 0 && $state['limit'] === 100, 'The page and list size are bounded');
checkWorklist($state['sort']['field'] === 'operation_date' && $state['sort']['order'] === 'DESC', 'Unknown sort expressions are replaced by allowlisted state');
checkWorklist($state['filters']['description'] === '', 'Nested request values cannot become a text filter');
checkWorklist(!array_intersect(array_keys($state['params']), array('token','action','review','line_id','account_context','kind','selected')), 'Read navigation excludes financial actions, signatures, selections and unrelated account state');
checkWorklist(kreabankWorklistState(array('search_amount'=>'0'), 25)['params']['search_amount'] === '0', 'Zero is retained as an active amount filter');
foreach (array(array('search_date_from'=>'2026-02-30'), array('search_date_to'=>'2026-13-01'), array('search_date_from'=>'2026-11-14','search_date_to'=>'2026-11-13'), array('search_amount'=>'1 OR 1=1'), array('search_amount'=>'1e3'), array('search_amount'=>'0.123456789'), array('search_match'=>str_repeat('x', 257))) as $request) {
	rejectWorklist(static function () use ($request) { kreabankWorklistState($request, 25); }, 'Invalid date, amount or oversized text is rejected');
}
foreach (array('staged:7'=>array('staged',7), 'native:7'=>array('native',7), 'native:2147483648'=>array('native',2147483648)) as $key=>$expected) {
	checkWorklist(kreabankWorklistSource($key) === $expected, 'Source identities preserve their explicit namespace');
}
foreach (array('7','staged:0','staged:07','staged:-7','staging:7','native:7 OR 1=1',"native:7\n",array('staged:7')) as $key) {
	rejectWorklist(static function () use ($key) { kreabankWorklistSource($key); }, 'Malformed or ambiguous source identity is rejected');
}

foreach (array(
	array('9999999999999999.12345678','9999999999999999.12345679',-1),
	array('-9999999999999999.12345678','-9999999999999999.12345679',1),
	array('-0.00000001','0',-1), array('-0.00000000','+000.0',0),
	array('12,34567890','12.3456789',0), array('1000000000000000','999999999999999',1),
) as $example) {
	checkWorklist(kreabankWorklistCompareDecimal($example[0], $example[1]) === $example[2], 'Decimal comparisons preserve signs and eight-place evidence without floats');
}
foreach (array(array('12.34567890','>=12,34567890',true),array('12.34567890','>12.34567890',false),array('-12','<=-12',true),array('-12','<-12',false),array('0','0',true),array('1','<>0',true),array('1','<>1',false),array('1','invalid',false)) as $example) {
	checkWorklist(kreabankWorklistAmountMatches($example[0], $example[1]) === $example[2], 'Signed amount operators compare exact decimal strings');
}
foreach (array('1e3','INF','','0.000000001') as $amount) {
	rejectWorklist(static function () use ($amount) { kreabankWorklistCompareDecimal($amount, '0'); }, 'Malformed source decimals never become a successful comparison');
}

$rows = array(
	array('key'=>'staged:7','line'=>array('rowid'=>7,'fk_statement'=>7,'operation_date'=>'2026-11-11','bank_account_id'=>101,'bank_account_ref'=>'Account A','description'=>'SALARIES NOVEMBER','amount'=>'-3300.00000000','currency'=>'EUR','status'=>0),'status'=>'draft','match'=>'November salaries SAL-001 SAL-002 SAL-003','payment_accounts'=>array(101),'payment_account'=>'Account A'),
	array('key'=>'native:7','line'=>array('rowid'=>7,'fk_statement'=>0,'operation_date'=>'2026-11-11','bank_account_id'=>102,'bank_account_ref'=>'Account B','description'=>'CUSTOMER TRANSFER','amount'=>'420.00000000','currency'=>'EUR','status'=>0),'status'=>'pending','match'=>'REC-0142 Example Customer','payment_accounts'=>array(102),'payment_account'=>'Account B'),
	array('key'=>'staged:8','line'=>array('rowid'=>8,'fk_statement'=>8,'operation_date'=>'2026-11-12 23:59:59','bank_account_id'=>101,'bank_account_ref'=>'Account A','description'=>'Fee 100%_!','payment_reference'=>'RF-8','bank_reference'=>'BREF33','amount'=>'12.34567890','currency'=>'EUR','status'=>0),'status'=>'pending','match'=>'','payment_accounts'=>array(),'payment_account'=>''),
	array('key'=>'native:8','line'=>array('rowid'=>8,'fk_statement'=>0,'operation_date'=>'2026-11-13','bank_account_id'=>102,'bank_account_ref'=>'Account B','description'=>'LARGE A','amount'=>'9999999999999999.12345678','currency'=>'EUR','status'=>2),'status'=>'reconciled','match'=>'LARGE-1','payment_accounts'=>array(102),'payment_account'=>'Account B'),
	array('key'=>'staged:9','line'=>array('rowid'=>9,'fk_statement'=>9,'operation_date'=>'2026-11-13','bank_account_id'=>101,'bank_account_ref'=>'Account A','description'=>'LARGE B','amount'=>'9999999999999999.12345679','currency'=>'EUR','status'=>0),'status'=>'pending','match'=>'LARGE-2 Example Supplier','payment_accounts'=>array(101,102),'payment_account'=>'Account A, Account B'),
);
$state = kreabankWorklistState(array(), 25);
$filtered = kreabankWorklistFilterRows($rows, $state);
checkWorklist(count($filtered) === 4 && in_array('staged:7', worklistKeys($filtered), true) && in_array('native:7', worklistKeys($filtered), true), 'Default pending view includes drafts and preserves colliding staged/native numeric IDs');
checkWorklist(worklistKeys(kreabankWorklistFilterRows($rows, kreabankWorklistState(array('search_status'=>'draft'), 25))) === array('staged:7'), 'Draft-only filter selects unfinished payment groups');
checkWorklist(worklistKeys(kreabankWorklistFilterRows($rows, kreabankWorklistState(array('search_status'=>'reconciled'), 25))) === array('native:8'), 'Completed-only filter excludes pending and draft rows');
checkWorklist(worklistKeys(kreabankWorklistFilterRows($rows, kreabankWorklistState(array('search_date_from'=>'2026-11-12','search_date_to'=>'2026-11-12'), 25))) === array('staged:8'), 'Booking date range includes late native timestamps on its ending day');
foreach (array('100%_!','rf-8','bref33') as $description) {
	checkWorklist(worklistKeys(kreabankWorklistFilterRows($rows, kreabankWorklistState(array('search_description'=>$description), 25))) === array('staged:8'), 'Description and both bank-reference filters are literal and case-insensitive');
}
$state = kreabankWorklistState(array('limit'=>1,'search_match'=>'Example Supplier','search_payment_account'=>'102'), 25);
$page = kreabankWorklistPage(kreabankWorklistFilterRows($rows, $state), $state);
checkWorklist($page['total'] === 1 && worklistKeys($page['rows']) === array('staged:9'), 'Payment and payment-bank filters inspect the complete scan before pagination');
checkWorklist(worklistKeys(kreabankWorklistFilterRows($rows, kreabankWorklistState(array('search_payment_account'=>'none'), 25))) === array('staged:8'), 'No-payment-account filter retains unmatched/unbanked rows without inventing an account');
checkWorklist(count(kreabankWorklistFilterRows($rows, kreabankWorklistState(array('search_account'=>'101'), 25))) === 3, 'Statement account filtering is independent of payment account filtering');
$state = kreabankWorklistState(array('search_status'=>'all','sortfield'=>'amount','sortorder'=>'DESC','limit'=>2), 25);
$filtered = kreabankWorklistFilterRows($rows, $state);
checkWorklist(array_slice(worklistKeys($filtered), 0, 2) === array('staged:9','native:8'), 'Amount sorting distinguishes large values one database unit apart');
$page = kreabankWorklistPage($filtered, $state);
checkWorklist($page['total'] === 5 && $page['num'] === 3 && $page['page'] === 0 && worklistKeys($page['rows']) === array('staged:9','native:8'), 'Native paging metadata uses the complete sorted population and next-page sentinel');
$state['page'] = 1;
checkWorklist(worklistKeys(kreabankWorklistPage($filtered, $state)['rows']) === array('native:7','staged:8'), 'Changing pages neither duplicates nor skips a sorted source');
$state['page'] = 2;
checkWorklist(worklistKeys(kreabankWorklistPage($filtered, $state)['rows']) === array('staged:7'), 'Final partial page retains its final source');
$state['page'] = 99;
checkWorklist(kreabankWorklistPage($filtered, $state)['page'] === 0 && kreabankWorklistPage(array(), $state)['page'] === 0, 'Out-of-range and empty pages return to native page zero');
$state = kreabankWorklistState(array('pageplusone'=>3,'limit'=>2,'sortfield'=>'match','sortorder'=>'DESC','search_account'=>'101','search_match'=>'Supplier','search_payment_account'=>'102','search_status'=>'all'), 25);
checkWorklist($state['page'] === 2 && $state['params']['search_account'] === '101' && $state['params']['search_match'] === 'Supplier'
	&& $state['params']['search_payment_account'] === '102' && $state['params']['sortfield'] === 'match' && $state['params']['sortorder'] === 'DESC', 'Pagination state preserves both account filters, match search and sort');

// Execute the actual controller's authorization and POST branches with synthetic
// services. Only the terminal redirect is intercepted; no ERP bootstrap runs.
require_once __DIR__ . '/../lib/kreabank_bulk.lib.php';
require_once __DIR__ . '/../lib/kreabank_manual_review.lib.php';
class WorklistControllerRedirect extends RuntimeException
{
	public $params;
	public function __construct($params) { parent::__construct('Synthetic redirect'); $this->params = $params; }
}
class WorklistControllerForbidden extends RuntimeException {}
class WorklistControllerUser
{
	public $id = 7;
	public $socid = 0;
	public $denied = array();
	public function hasRight($a, $b, $c = '') { return !in_array(implode('/',array_filter(array($a,$b,$c))), $this->denied, true); }
}
class WorklistControllerService
{
	public $calls = array();
	public $failCreation = false;
	public function createWorklistMovementAndReconcile(...$args) { if ($this->failCreation) { throw new RuntimeException('Review changed'); } $this->calls[] = array('createWorklistMovementAndReconcile',$args); return 88; }
	public function reconcileLine(...$args) { $this->calls[] = array('reconcileLine',$args); return 8; }
	public function changeMovementAccount(...$args) { $this->calls[] = array('changeMovementAccount',$args); return true; }
	public function savePaymentBatchDraft(...$args) { $this->calls[] = array('savePaymentBatchDraft',$args); return true; }
	public function preparePaymentBatchReview(...$args) { $this->calls[] = array('preparePaymentBatchReview',$args); return array('source'=>8,'ids'=>$args[1],'proof'=>'backend signed review'); }
	public function reconcilePaymentBatch(...$args) { $this->calls[] = array('reconcilePaymentBatch',$args); return 5; }
	public function undoPaymentBatch(...$args) { $this->calls[] = array('undoPaymentBatch',$args); return true; }
}
class WorklistControllerRead
{
	public $pair;
	public $batchSchema = true;
	public $candidateSearches = array();
	public function __construct($pair) { $this->pair = $pair; }
	public function load($key) {
		list($source,$id) = kreabankWorklistSource($key);
		$line = $this->pair['line']; $line['rowid'] = $id; $line['fk_statement'] = $source === 'staged' ? $id : 0;
		return $line;
	}
	public function prepare($key) { $pair = $this->pair; $pair['line'] = $this->load($key); return array('pair'=>$pair); }
	public function candidates($line, $search = '') { $this->candidateSearches[] = $search; $pair = $this->pair; $pair['line'] = $line; return array($pair['suggestion']['doc_type'] . '__' . $pair['suggestion']['doc_id']=>$pair); }
	public function batchesAvailable() { return $this->batchSchema; }
}
function GETPOST($name, $filter = '') { return $_POST[$name] ?? $_GET[$name] ?? ($filter === 'array' ? array() : ''); }
function getDolGlobalInt($name) { return $GLOBALS['worklistControllerDisableInput'] ?? 0; }
function kreabankIsTokenValid() { return $GLOBALS['worklistControllerCsrf']; }
function isModEnabled($name) { return in_array($name, $GLOBALS['worklistControllerModules'], true); }
function accessforbidden($message = '') { $GLOBALS['worklistControllerDenied'] = true; throw new WorklistControllerForbidden($message); }
function setEventMessages($message, $other = null, $style = '') { $GLOBALS['worklistControllerEvents'][] = array($message,$style); }
function dol_syslog($message, $level = 0) {}
function dol_buildpath($path, $type = 0) { return $path; }
class WorklistControllerLangs { public function trans($key) { return $key; } }
function worklistControllerPair()
{
	return array('line'=>array('rowid'=>8,'fk_statement'=>8,'native_bank_line_id'=>0,'bank_account_id'=>101,'direction'=>-1,'status'=>0,
		'amount'=>'-50.00000000','currency'=>'EUR','operation_date'=>'2026-11-12','statement_ref'=>'SYNTHETIC','description'=>'Fixture'),
		'suggestion'=>array('doc_type'=>'native_bank','doc_id'=>81,'doc_ref'=>'PAY-81','doc_date'=>'2026-11-12','bank_account_id'=>101,
			'bank_account_ref'=>'Account A','amount_open'=>'-50.00000000','score'=>100,'details'=>array('amount','date')),'allocation'=>50);
}
function worklistControllerReview($kind, $payload, $scope = '1|7', $expires = null)
{
	return array('kind'=>$kind,'scope'=>$scope,'expires'=>$expires ?? time()+900,'payload'=>$payload);
}
function worklistControllerSession($explicit = false, $search = '')
{
	$pair = worklistControllerPair(); $expires = time()+900; $secret = str_repeat('s',64);
	$payload = array('key'=>'staged:8','pair'=>$pair,'explicit'=>$explicit,'search'=>$search,'expires'=>$expires,
		'signature'=>kreabankBulkReviewSignature($pair,'1|7|staged:8',$expires,$secret));
	return array('kreabank_worklist_scope'=>'1|7','kreabank_worklist_secret'=>$secret,'kreabank_worklist_reviews'=>array('fixture'=>worklistControllerReview('pair',$payload)),
		'kreabank_worklist_scan'=>array('generation'=>'old scan'));
}
function runWorklistController($post, $session = array(), $options = array())
{
	$_GET = $options['get'] ?? array(); $_POST = $post; $_SESSION = $session;
	$_SERVER['REQUEST_METHOD'] = $options['method'] ?? 'POST';
	$GLOBALS['worklistControllerDisableInput'] = $options['disable_input'] ?? 0;
	$GLOBALS['worklistControllerCsrf'] = $options['csrf'] ?? true;
	$GLOBALS['worklistControllerModules'] = $options['modules'] ?? array('kreabank','banque');
	$GLOBALS['worklistControllerDenied'] = false; $GLOBALS['worklistControllerEvents'] = array();
	$conf = (object) array('entity'=>$options['entity'] ?? 1,'liste_limit'=>25); $user = new WorklistControllerUser();
	$user->denied = $options['denied'] ?? array(); $user->socid = $options['socid'] ?? 0;
	$langs = new WorklistControllerLangs(); $service = new WorklistControllerService(); $service->failCreation = $options['fail_creation'] ?? false;
	$worklist = new WorklistControllerRead($options['fresh_pair'] ?? worklistControllerPair());
	$worklist->batchSchema = $options['batch_schema'] ?? true;
	$controller = file_get_contents(__DIR__ . '/../reconciliationlist.php');
	$authStart = strpos($controller, "if (!isModEnabled('kreabank')");
	$authEnd = strpos($controller, '$service = new KreaBankService', $authStart);
	$start = strpos($controller, '$canWrite =');
	$end = strpos($controller, 'try { $sources =', $start);
	if ($authStart === false || $authEnd === false || $start === false || $end === false) { throw new RuntimeException('Controller harness boundaries changed'); }
	$auth = substr($controller,$authStart,$authEnd-$authStart);
	$branch = substr($controller,$start,$end-$start);
	$terminal = "header('Location: ' . \$url . '?' . http_build_query(array_merge(\$baseParams,\$extra),'','&',PHP_QUERY_RFC3986)); exit;";
	$branch = str_replace($terminal, 'throw new WorklistControllerRedirect(array_merge($baseParams,$extra));', $branch, $replaced);
	if ($replaced !== 1) { throw new RuntimeException('Controller terminal redirect contract changed'); }
	$redirect = null; $descriptor = null;
	try { eval($auth); eval($branch); if (isset($options['describe_pair'])) { $descriptor = $pairDescriptor('staged:8',$options['describe_pair']); } }
	catch (WorklistControllerRedirect $end) { $redirect = $end->params; }
	catch (WorklistControllerForbidden $end) {}
	return array('calls'=>$service->calls,'session'=>$_SESSION,'redirect'=>$redirect,'denied'=>$GLOBALS['worklistControllerDenied'],'events'=>$GLOBALS['worklistControllerEvents'],'searches'=>$worklist->candidateSearches,'descriptor'=>$descriptor);
}
foreach (array('more_entry','more_rule','more_writeoff','more_draft','skip_line','bulk_skip') as $removed) {
	$result = runWorklistController(array('action'=>$removed),worklistControllerSession());
	checkWorklist($result['denied'] && !$result['calls'], 'Retired endpoint cannot execute even with a valid old session: ' . $removed);
}
$confirm = array('action'=>'confirm_pair','review'=>'fixture');
$result = runWorklistController($confirm,worklistControllerSession());
checkWorklist(count($result['calls']) === 1 && $result['calls'][0][0] === 'reconcileLine'
	&& $result['calls'][0][1][0] === 8 && $result['calls'][0][1][1][0]['fk_doc'] === 81 && $result['calls'][0][1][2] === 'bulk_manual', 'Actual inline pair approval executes only the authenticated displayed target through the existing service');
checkWorklist(!isset($result['session']['kreabank_worklist_reviews']['fixture'],$result['session']['kreabank_worklist_scan']), 'Successful approval consumes its one-use review and invalidates the read scan');
checkWorklist(!runWorklistController($confirm,$result['session'])['calls'], 'An inline approval cannot be replayed after its review was consumed');
checkWorklist(count(runWorklistController($confirm,worklistControllerSession(true))['calls']) === 1, 'Explicit candidate choice revalidates and executes its exact shared manual-review target');
foreach (array('customer_invoice','supplier_invoice','tax') as $type) {
	$pair = worklistControllerPair(); $pair['suggestion']['doc_type'] = $type;
	foreach (array(false,true) as $explicit) {
		$session = worklistControllerSession($explicit); $payload = &$session['kreabank_worklist_reviews']['fixture']['payload'];
		$payload['pair'] = $pair; $payload['signature'] = kreabankBulkReviewSignature($pair,'1|7|staged:8',$payload['expires'],$session['kreabank_worklist_secret']); unset($payload);
		$result = runWorklistController($confirm,$session,array('fresh_pair'=>$pair));
		checkWorklist(!$result['calls'] && !isset($result['session']['kreabank_worklist_reviews']['fixture']) && isset($result['session']['kreabank_worklist_scan'])
			&& $result['events'] === array(array('KreaBankWorklistPaymentRequired','errors')), 'Authentic pre-update unpaid-document review is consumed and rejected without creating a payment: ' . $type . '/' . (int) $explicit);
	}
	$result = runWorklistController(array(),worklistControllerSession(),array('method'=>'GET','describe_pair'=>$pair));
	checkWorklist($result['descriptor'] === array('disabled'=>true,'label'=>'KreaBankWorklistPaymentRequired') && count($result['session']['kreabank_worklist_reviews']) === 1,
		'Unpaid document renders a disabled descriptor without issuing a new signed approval: ' . $type);
}
foreach (array('native_bank','payment','payment_supplier','payment_linked','payment_supplier_linked','payment_tax') as $type) {
	$pair = worklistControllerPair(); $pair['suggestion']['doc_type']=$type;
	$result = runWorklistController(array(),worklistControllerSession(),array('method'=>'GET','describe_pair'=>$pair));
	checkWorklist(($result['descriptor']['action'] ?? '') === 'confirm_pair' && !isset($result['descriptor']['disabled']) && count($result['session']['kreabank_worklist_reviews']) === 2,
		'Recorded movement/payment still receives a signed reconciliation approval: ' . $type);
}
$unknown = worklistControllerPair(); $unknown['suggestion']['doc_type']='unknown_document';
checkWorklist(!kreabankWorklistPairCanReconcile($unknown), 'Unknown target types fail closed under the recorded-payment policy');
$result = runWorklistController($confirm + array('q'=>'changed after display'),worklistControllerSession(true,'OLD-INVOICE-991'));
checkWorklist(count($result['calls']) === 1 && $result['searches'] === array('OLD-INVOICE-991'), 'Typed search approval repeats the stored displayed search rather than a replacement POST query');
foreach (array(array('csrf'=>false),array('denied'=>array('kreabank/reconciliation/write')),
	array('denied'=>array('banque/modifier','banque/consolidate')),array('socid'=>42),array('denied'=>array('banque/lire')),
	array('denied'=>array('kreabank/reconciliation/read')),array('modules'=>array('banque'))) as $options) {
	$result = runWorklistController($confirm,worklistControllerSession(),$options);
	checkWorklist(!$result['calls'] && $result['denied'], 'CSRF, module, external-user and current native/module permission boundaries deny actual controller mutation');
}
$result = runWorklistController(array(),worklistControllerSession(),array('method'=>'GET','get'=>$confirm));
checkWorklist(!$result['calls'] && $result['denied'], 'Read navigation cannot execute a financial action through GET');
$changed = worklistControllerPair(); $changed['suggestion']['doc_id'] = 82;
$result = runWorklistController($confirm,worklistControllerSession(),array('fresh_pair'=>$changed));
checkWorklist(!$result['calls'] && isset($result['session']['kreabank_worklist_scan']), 'Changed suggested target cannot execute or clear a valid read scan');
$session = worklistControllerSession(); $session['kreabank_worklist_reviews']['fixture']['payload']['key'] = 'native:8';
checkWorklist(!runWorklistController($confirm,$session)['calls'], 'Changing a source namespace invalidates the signed displayed pair');
foreach (array(array('expires'=>time()-1),array('scope'=>'2|7')) as $change) {
	$session = worklistControllerSession(); $session['kreabank_worklist_reviews']['fixture'] = array_merge($session['kreabank_worklist_reviews']['fixture'],$change);
	checkWorklist(!runWorklistController($confirm,$session)['calls'], 'Expired or foreign-company review envelopes cannot execute');
}
$session = worklistControllerSession();
$session['kreabank_worklist_inputs'] = array('staged:8'=>array('name'=>'Previous company','ids'=>array(81)));
$session['kreabank_worklist_corrected'] = array('staged:8'=>array('bank_line'=>81,'expires'=>time()+900));
$session['kreabank_worklist_batch_review'] = array('staged:8'=>'old review');
$result = runWorklistController(array(),$session,array('method'=>'GET','entity'=>2));
checkWorklist(!$result['calls'] && !isset($result['session']['kreabank_worklist_inputs'],$result['session']['kreabank_worklist_corrected'],
	$result['session']['kreabank_worklist_batch_review'],$result['session']['kreabank_worklist_scan'],$result['session']['kreabank_worklist_reviews']['fixture']), 'Changing company clears private typed selections, corrections, scan and old review envelopes');
$moveSession = array('kreabank_worklist_reviews'=>array('move'=>worklistControllerReview('move',array('key'=>'staged:8','bank_line'=>81,'snapshot'=>'displayed snapshot','old'=>102,'target'=>101))));
$result = runWorklistController(array('action'=>'correct_account','review'=>'move','bank_line'=>999,'target'=>202),$moveSession);
checkWorklist($result['calls'] === array(array('changeMovementAccount',array(8,81,'displayed snapshot',102,101))), 'Change-bank controller uses the reviewed movement and destination, ignoring forged POST account IDs');
checkWorklist($result['redirect']['expand'] === 'staged:8' && $result['redirect']['mode'] === 'search', 'Account correction returns to the same row for fresh unchecked review');
$result = runWorklistController(array('action'=>'correct_account','review'=>'move'),$moveSession,array('denied'=>array('banque/modifier')));
checkWorklist(!$result['calls'] && $result['denied'], 'Native consolidate rights do not grant native account-modifier authority');
$draft = array('action'=>'save_draft','source_key'=>'staged:8','batch_members'=>array('81','82'),'batch_name'=>'Salaries');
$result = runWorklistController($draft);
checkWorklist($result['calls'] === array(array('savePaymentBatchDraft',array(8,array(81,82),'Salaries'))), 'Saving a batch draft stores only the explicit grouping and performs no reconciliation');
$draft['action'] = 'review_batch'; $result = runWorklistController($draft);
checkWorklist(array_column($result['calls'],0) === array('savePaymentBatchDraft','preparePaymentBatchReview'), 'Batch review prepares a signed group without executing it');
$nonce = $result['session']['kreabank_worklist_batch_review']['staged:8'];
$approved = runWorklistController(array('action'=>'confirm_batch','review'=>$nonce,'batch_members'=>array('999')),$result['session']);
checkWorklist($approved['calls'] === array(array('reconcilePaymentBatch',array(array('source'=>8,'ids'=>array(81,82),'proof'=>'backend signed review')))), 'Batch confirmation executes the stored backend-signed review rather than submitted replacement members');
checkWorklist(!runWorklistController(array('action'=>'confirm_batch','review'=>$nonce),$approved['session'])['calls'], 'Batch confirmation envelope is single use');
foreach (array(array('source_key'=>'native:8'),array('batch_members'=>array('81','1 OR 1=1')),array('batch_members'=>array(array('81')))) as $change) {
	checkWorklist(!runWorklistController(array_merge($draft,$change))['calls'], 'Legacy source and malformed batch-member identities cannot reach draft or review services');
}
checkWorklist(!runWorklistController($draft,array(),array('batch_schema'=>false))['calls'], 'Missing batch schema never falls back to a financial operation');
$undoSession = array('kreabank_worklist_reviews'=>array('undo'=>worklistControllerReview('undo',array('key'=>'staged:8','batch'=>array('signed'=>'undo proof')))));
$result = runWorklistController(array('action'=>'undo_batch','review'=>'undo'),$undoSession,array('denied'=>array('kreabank/reconciliation/undo')));
checkWorklist(!$result['calls'] && $result['denied'], 'Batch undo requires its separate native/module authority');
$result = runWorklistController(array('action'=>'undo_batch','review'=>'undo'),$undoSession);
checkWorklist($result['calls'] === array(array('undoPaymentBatch',array(8,array('signed'=>'undo proof')))), 'Authorized group undo delegates the exact reviewed group to the shared service');

// A created native movement requires the private displayed review and modifier rights.
$movementPayload = array('key'=>'staged:8','movement'=>array('source_key'=>'staged:8','source'=>array('amount'=>'-50.00000000'),'signature'=>'private proof'));
$movementSession = worklistControllerSession();
$movementSession['kreabank_worklist_reviews']['movement'] = worklistControllerReview('movement',$movementPayload);
$movementPost = array('action'=>'create_movement','review'=>'movement','payment_mode'=>'VIR','movement_note'=>'Reviewed fee','source_key'=>'staged:999','amount'=>'999','expand'=>'staged:8','mode'=>'create','q'=>'Fee');
$result = runWorklistController($movementPost,$movementSession);
checkWorklist($result['calls'] === array(array('createWorklistMovementAndReconcile',array($movementPayload['movement'],'VIR','Reviewed fee'))), 'Movement creation uses the private reviewed source and ignores replacement financial POST fields');
checkWorklist(!isset($result['session']['kreabank_worklist_reviews']['movement'],$result['session']['kreabank_worklist_movement_inputs']['staged:8'],$result['session']['kreabank_worklist_scan']), 'Created movement consumes its nonce, clears sticky inputs and invalidates analysis');
checkWorklist(!isset($result['redirect']['expand']) && !runWorklistController($movementPost,$result['session'])['calls'], 'Completed movement closes its review and cannot replay');
foreach (array(array('csrf'=>false),array('denied'=>array('banque/modifier')),array('disable_input'=>1),array('denied'=>array('kreabank/reconciliation/write'))) as $options) {
    $result = runWorklistController($movementPost,$movementSession,$options);
    checkWorklist(!$result['calls'] && $result['denied'], 'Movement creation requires CSRF, native modifier rights, module write and direct input policy');
}
$result = runWorklistController($movementPost,$movementSession,array('fail_creation'=>true));
checkWorklist(!$result['calls'] && $result['redirect']['expand']==='staged:8' && $result['redirect']['mode']==='create' && $result['redirect']['q']==='Fee', 'Failed creation returns to its source row and search context');
checkWorklist($result['session']['kreabank_worklist_movement_inputs']['staged:8']===array('payment_mode'=>'VIR','note'=>'Reviewed fee') && isset($result['session']['kreabank_worklist_scan']), 'Failed creation retains reviewed user inputs without claiming financial success');
$expired = $movementSession; $expired['kreabank_worklist_reviews']['movement']['expires']=time()-1;
$result=runWorklistController($movementPost,$expired);
checkWorklist(!$result['calls'] && $result['redirect']['expand']==='staged:8' && $result['session']['kreabank_worklist_movement_inputs']['staged:8']['note']==='Reviewed fee', 'Expired reviews retain bounded inputs for a freshly scoped source without executing');
$movementSession['kreabank_worklist_movement_inputs'] = array('staged:8'=>array('payment_mode'=>'VIR','note'=>'Other company'));
$movementSession['kreabank_worklist_movement_reviews'] = array('private'=>'old source');
$result = runWorklistController(array(),$movementSession,array('method'=>'GET','entity'=>2));
checkWorklist(!isset($result['session']['kreabank_worklist_movement_inputs'],$result['session']['kreabank_worklist_movement_reviews']), 'Company changes clear creation inputs and private backend reviews');
$result=runWorklistController(array(),$movementSession,array('method'=>'GET','get'=>$movementPost));
checkWorklist($result['denied'] && !$result['calls'], 'Creation is never executed by read navigation');

// The actual candidate projection carries document identity, not the source or linked bank ID.
$projectionSource=file_get_contents(__DIR__.'/../reconciliationlist.php');
$projectionStart=strpos($projectionSource,'$candidateModel =');
$projectionEnd=strpos($projectionSource,'foreach ($page',$projectionStart);
if ($projectionStart===false || $projectionEnd===false) { throw new RuntimeException('Candidate projection boundary changed'); }
eval(substr($projectionSource,$projectionStart,$projectionEnd-$projectionStart));
$pair=worklistControllerPair(); $pair['suggestion']['doc_type']='payment_supplier_linked'; $pair['suggestion']['doc_id']=51;
$pair['suggestion']['linked_bank_line_id']=81;
$projected=$candidateModel($pair);
checkWorklist($projected['doc_type']==='payment_supplier_linked' && $projected['doc_id']===51 && $projected['key']==='payment_supplier_linked__51', 'Projected payment navigation retains the payment ID, independently of source and linked movement IDs');

$pair['line']['bank_account_ref']='STATEMENT-BANK'; $pair['suggestion']['bank_account_id']=102; $pair['suggestion']['bank_account_ref']='RECORDED-BANK';
foreach (array('customer_invoice','supplier_invoice','tax') as $type) {
	$pair['suggestion']['doc_type']=$type; $projected=$candidateModel($pair);
	checkWorklist($projected['account_ref']==='STATEMENT-BANK' && $projected['account_planned']===true && kreabankWorklistPaymentAccount($pair)['id']===101,
		'New payment displays and indexes its explicit statement destination instead of any invoice default bank: '.$type);
}
$pair['suggestion']['doc_type']='native_bank'; $projected=$candidateModel($pair);
checkWorklist($projected['account_ref']==='RECORDED-BANK' && $projected['account_planned']===false && kreabankWorklistPaymentAccount($pair)['id']===102,
	'Existing movements preserve their actual recorded bank');

$paymentPair=worklistControllerPair(); $paymentPair['line']['account_open']=true; $paymentPair['line']['allocated_amount']='0';
$paymentPair['suggestion']['doc_type']='supplier_invoice'; $paymentPair['suggestion']['amount_open']='100.00000000';
$permission=array('supplier_invoice'=>true,'customer_invoice'=>true);
parse_str(parse_url(kreabankWorklistPaymentUrl($paymentPair,$permission,'EUR'),PHP_URL_QUERY),$nativePayment);
checkWorklist($nativePayment['action']==='create' && $nativePayment['facid']==='81' && $nativePayment['accountid']==='101'
	&& $nativePayment['amount_81']==='50.00000000' && $nativePayment['reyear']==='2026' && $nativePayment['remonth']==='11' && $nativePayment['reday']==='12', 'Native supplier payment form defaults retain exact source amount, owned account and calendar day without a payment POST');
$paymentPair['line']['amount']='-9007199254.74099301'; $paymentPair['suggestion']['amount_open']='9007199254.74099300';
parse_str(parse_url(kreabankWorklistPaymentUrl($paymentPair,$permission,'EUR'),PHP_URL_QUERY),$nativePayment);
checkWorklist($nativePayment['amount_81']==='9007199254.74099300','Native payment default is capped at exact open invoice balance without floating point rounding');
$paymentPair=worklistControllerPair(); $paymentPair['line']['account_open']=true; $paymentPair['suggestion']['doc_type']='supplier_invoice';
foreach (array('unpaid_tax','legacy','mapped','closed','reconciled','partial','foreign_currency','wrong_direction','bad_date','bad_id','no_native_right') as $case) {
	$blocked=$paymentPair; $rights=$permission;
	switch($case) {
		case 'unpaid_tax':$blocked['suggestion']['doc_type']='tax';break;
		case 'legacy':$blocked['line']['fk_statement']=0;break;
		case 'mapped':$blocked['line']['native_bank_line_id']=99;break;
		case 'closed':$blocked['line']['account_open']=false;break;
		case 'reconciled':$blocked['line']['status']=2;break;
		case 'partial':$blocked['line']['allocated_amount']='1';break;
		case 'foreign_currency':$blocked['line']['currency']='USD';break;
		case 'wrong_direction':$blocked['line']['amount']='50.00';break;
		case 'bad_date':$blocked['line']['operation_date']='2026-02-30';break;
		case 'bad_id':$blocked['suggestion']['doc_id']='81<script>';break;
		case 'no_native_right':$rights['supplier_invoice']=false;break;
	}
	checkWorklist(kreabankWorklistPaymentUrl($blocked,$rights,'EUR')==='','Unavailable/unsafe native invoice payment shortcut fails closed: '.$case);
}
$paymentPair['suggestion']['doc_type']='customer_invoice'; $paymentPair['line']['amount']='50.00000000';
checkWorklist(strpos(kreabankWorklistPaymentUrl($paymentPair,$permission,'EUR'),'/compta/paiement.php?action=create&facid=81')===0,'Customer invoice shortcut uses its own installed native payment route');
$session=worklistControllerSession(); $session['kreabank_worklist_reviews']['other']=worklistControllerReview('pair',array('key'=>'staged:9'));
$session['kreabank_worklist_batch_review']=array('staged:8'=>'old','staged:9'=>'kept');
kreabankWorklistRefreshPayment($session,'staged:8');
checkWorklist(!isset($session['kreabank_worklist_scan'],$session['kreabank_worklist_reviews']['fixture'],$session['kreabank_worklist_batch_review']['staged:8'])
	&& isset($session['kreabank_worklist_reviews']['other'],$session['kreabank_worklist_batch_review']['staged:9']), 'Closing a native payment form invalidates only advisory approvals for that row and recomputes the scan');
$keptStatus=kreabankWorklistState(array('search_status'=>'reconciled','search_description'=>'old','button_removefilter_x'=>'x','page'=>3));
checkWorklist($keptStatus['filters']['status']==='reconciled' && $keptStatus['filters']['description']==='' && $keptStatus['page']===0, 'Clearing column filters keeps the selected status tab and resets pagination');


// Preparing matches retains read permission and CSRF without financial authority.
foreach (array('advance_scan','more_scan') as $readAction) {
	$result = runWorklistController(array('action'=>$readAction),worklistControllerSession(),array('denied'=>array('kreabank/reconciliation/write','banque/modifier','banque/consolidate')));
	checkWorklist(!$result['denied'] && !$result['calls'], 'Read-only users retain analysis without entering financial execution: ' . $readAction);
	$result = runWorklistController(array('action'=>$readAction),worklistControllerSession(),array('csrf'=>false));
	checkWorklist($result['denied'] && !$result['calls'], 'Read-only analysis still requires CSRF: ' . $readAction);
}
// Optional task acceptance evidence; do not permanently freeze legacy source hashes.
$baselinePath = getenv('KREABANK_PROTECTED_BASELINE');
if ($baselinePath) {
	$baseline = json_decode(file_get_contents($baselinePath), true);
	foreach (array('css/reconcile.css','js/reconcile.js') as $file) { // The page itself moved into the list in 1.20.47.
		checkWorklist(isset($baseline[$file]) && hash_equals((string) $baseline[$file], hash_file('sha256', __DIR__ . '/../' . $file)), 'Existing reconciliation controller and presentation assets are unchanged: ' . $file);
	}
} else {
	print "SKIP: protected-file baseline comparison (set KREABANK_PROTECTED_BASELINE for task acceptance)\n";
}
print "OK: $checks separate reconciliation list checks\n";
