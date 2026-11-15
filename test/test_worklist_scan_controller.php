#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Actual controller admission and lease branches, synthetic requests/session only.
 * No Dolibarr bootstrap, database, real PHP session or financial action.
 */
require_once __DIR__ . '/../lib/kreabank_worklist.lib.php';

$checks = 0;
function checkScanController($condition, $message)
{
	global $checks;
	if (!$condition) { throw new RuntimeException($message); }
	$checks++;
}
function GETPOST($key, $filter = '') { return $GLOBALS['scanControllerRequest'][$key] ?? ''; }
function GETPOSTINT($key) { return (int) GETPOST($key); }
class WorklistScanStopped extends RuntimeException
{
	public $status;
	public function __construct($status) { parent::__construct('Synthetic HTTP terminal'); $this->status = $status; }
}
class WorklistScanRedirect extends RuntimeException
{
	public $params;
	public function __construct($params) { parent::__construct('Synthetic redirect'); $this->params = $params; }
}

/** Capture only terminal HTTP exits; all admission/view/lease logic stays original. */
function scanControllerRegion()
{
	$source = file_get_contents(__DIR__ . '/../reconciliationlist.php');
	$start = strpos($source, '$sourceKeys = array_keys($sources);');
	$more = strpos($source, "if (\$action === 'more_scan') {", $start);
	$advance = strpos($source, "if (\$action === 'advance_scan') {", $more);
	$release = strpos($source, '$sessionId = session_id(); session_write_close();', $advance);
	if ($start === false || $more === false || $advance === false || $release === false) {
		throw new RuntimeException('Actual scan controller boundaries changed');
	}
	$admission = substr($source, $start, $more - $start);
	$moreBranch = substr($source, $more, $advance - $more);
	// Stop before real session release or matching. This includes the actual claim
	// and its publication to session state, exactly where a second request sees it.
	$claim = substr($source, $advance, $release - $advance) . "}\n";
	return str_replace('exit;', 'throw new WorklistScanStopped(http_response_code());', $admission . $moreBranch . $claim);
}

function syntheticScanSources()
{
	$rows = array();
	for ($id = 1; $id <= 166; $id++) {
		$rows['staged:' . $id] = array('rowid'=>$id,'source_storage'=>'staging','amount'=>'1.00','currency'=>'EUR',
			'operation_date'=>gmdate('Y-m-d',strtotime('2026-01-01 UTC') + ($id-1)*86400),
			'description'=>'Synthetic transaction '.$id,'payment_reference'=>'REFERENCE-'.$id,'bank_reference'=>'BANK-'.$id,
			'status'=>0,'native_id'=>null,'native_amount'=>null,'native_currency'=>'EUR','num_releve'=>null,'rappro'=>0,
			'group_id'=>0,'group_valid'=>0,'group_count'=>0,'group_date'=>null,'statement_id'=>$id,'line_rank'=>1,
			'bank_account_id'=>101,'bank_account_ref'=>'SYNTHETIC','is_duplicate'=>0);
	}
	return $rows;
}

function runScanController($request = array(), $session = array(), $options = array())
{
	$_SESSION = $session;
	$_SERVER['HTTP_X_KREABANK_SCAN'] = $options['ajax'] ?? '1';
	$GLOBALS['scanControllerRequest'] = $request;
	$state = kreabankWorklistState($request,25);
	$baseParams = $state['params'] + array('page'=>$state['page']);
	$sources = $options['sources'] ?? syntheticScanSources();
	$scope = $options['scope'] ?? '1|7';
	$action = $request['action'] ?? '';
	$expand = $request['expand'] ?? '';
	$paymentReturn = $request['payment_return'] ?? '';
	$worklist = new class { public $calls=array(); public function compact($key) { $this->calls[]=$key; return array('key'=>$key,'status'=>'pending','match'=>'Fresh payment evidence','payment_accounts'=>array(),'payment_account'=>''); } };
	$langs = new class { public function trans($key) { return $key; } };
	$user = (object) array('id'=>7); $conf = (object) array('entity'=>1);
	$source = file_get_contents(__DIR__ . '/../reconciliationlist.php');
	$redirectStart = strpos($source, '$redirect = static function');
	$redirectEnd = strpos($source, '$pairDescriptor =', $redirectStart);
	$redirectSource = substr($source,$redirectStart,$redirectEnd-$redirectStart);
	$terminal = "header('Location: ' . \$url . '?' . http_build_query(array_merge(\$baseParams,\$extra),'','&',PHP_QUERY_RFC3986)); exit;";
	$redirectSource = str_replace($terminal,'throw new WorklistScanRedirect(array_merge($baseParams,$extra));',$redirectSource,$replaced);
	if ($replaced !== 1) { throw new RuntimeException('Actual redirect boundary changed'); }
	$url = '/synthetic/reconciliationlist.php'; eval($redirectSource);
	$status = 200; $redirected = false;
	$redirectParams = null;
	http_response_code(200); ob_start();
	try { eval(scanControllerRegion()); }
	catch (WorklistScanStopped $stopped) { $status = $stopped->status; }
	catch (WorklistScanRedirect $stopped) { $redirected = true; $redirectParams = $stopped->params; }
	finally { $body = ob_get_clean(); }
	return array('session'=>$_SESSION,'cache'=>$_SESSION['kreabank_worklist_scan'] ?? null,'state'=>$state,'base'=>$baseParams,
		'view'=>$scanView ?? null,'scope'=>$scanScope,'display'=>$displayKeys ?? array(),'status'=>$status,'redirected'=>$redirected,
		'body'=>json_decode($body,true),'redirect'=>$redirectParams,'compact_calls'=>$worklist->calls);
}

function scanControllerPost($rendered, $extra = array())
{
	return array_merge($rendered['base'],array('action'=>'advance_scan','scan_generation'=>$rendered['cache']['generation'],
		'scan_cursor'=>$rendered['cache']['cursor']),$extra);
}

$pageA = runScanController(array('page'=>0));
checkScanController($pageA['cache']['targets'] === array_map(static function ($id) { return 'staged:'.$id; },range(166,142)), 'Actual GET grants only the newest page of the 166 sources');
$paymentSession = $pageA['session'];
$paymentSession['kreabank_worklist_scan']['summaries']['staged:165'] = array('key'=>'staged:165','status'=>'pending','match'=>'Kept evidence','payment_accounts'=>array(),'payment_account'=>'');
$returned = runScanController(array('payment_return'=>'staged:166'),$paymentSession);
checkScanController($returned['compact_calls'] === array('staged:166') && $returned['cache']['summaries']['staged:165'] === $paymentSession['kreabank_worklist_scan']['summaries']['staged:165'], 'A native payment return refreshes only its owned source and retains all other summaries');
checkScanController($returned['cache']['generation'] !== $pageA['cache']['generation'] && $returned['cache']['cursor'] === 0, 'Payment return rejects stale background publications without deleting the scan');
$foreignReturn = runScanController(array('payment_return'=>'staged:999'),$paymentSession);
checkScanController(!$foreignReturn['compact_calls'], 'Unknown or foreign payment return cannot trigger candidate reads');
$sessionA = $pageA['session']; $sessionA['unrelated_fixture_state'] = 'preserve';
$pageB = runScanController(array('page'=>1),$sessionA);
checkScanController($pageB['cache']['targets'] === array_map(static function ($id) { return 'staged:'.$id; },range(141,117)), 'Actual GET page two grants only its displayed source identities');
checkScanController($pageA['cache']['generation'] !== $pageB['cache']['generation'], 'Changing native page replaces the active batch generation');

foreach (array('advance_scan','more_scan') as $action) {
	$before = serialize($pageB['session']);
	$stale = runScanController(scanControllerPost($pageA,array('action'=>$action)),$pageB['session']);
	checkScanController($stale['status'] === 409 && ($stale['body']['error'] ?? '') === 'changed', 'Late '.$action.' from page A is rejected before matching');
	checkScanController(serialize($stale['session']) === $before, 'Late '.$action.' preserves page B session state byte-for-byte');
}
$staleMore = runScanController(scanControllerPost($pageA,array('action'=>'more_scan')),$pageB['session'],array('ajax'=>''));
checkScanController($staleMore['redirected'] && serialize($staleMore['session']) === serialize($pageB['session']), 'A normal stale More form redirects without replacing the newer page state');
$mismatchedView = scanControllerPost($pageB,array('page'=>0));
$stale = runScanController($mismatchedView,$pageB['session']);
checkScanController($stale['status'] === 409 && serialize($stale['session']) === serialize($pageB['session']), 'Even a current generation cannot replace its bound view through changed POST parameters');
$stale = runScanController(scanControllerPost($pageB,array('scan_cursor'=>9)),$pageB['session']);
checkScanController($stale['status'] === 409 && serialize($stale['session']) === serialize($pageB['session']), 'An old or forged cursor leaves the active batch unchanged');

$outOfRange = runScanController(array('page'=>99));
checkScanController($outOfRange['state']['page'] === 0 && $outOfRange['base']['page'] === 0, 'Out-of-range GET binds both rendered state and action fields to native page zero');
checkScanController($outOfRange['cache']['view'] === $pageA['cache']['view'] && $outOfRange['cache']['targets'] === $pageA['cache']['targets'], 'Out-of-range view uses the actual rendered page fingerprint and target set');
$admitted = runScanController(scanControllerPost($outOfRange),$outOfRange['session']);
checkScanController($admitted['status'] === 200 && isset($admitted['cache']['lease']), 'The first request from a clamped page is admitted and reserves its work');
checkScanController($admitted['cache']['generation'] === $outOfRange['cache']['generation'] && $admitted['cache']['view'] === $outOfRange['cache']['view'], 'Clamped-page POST does not reset its just-rendered generation');
$expanded = runScanController(array('page'=>0,'expand'=>'staged:101'));
checkScanController($expanded['state']['page'] === 2 && $expanded['cache']['targets'] === array_map(static function ($id) { return 'staged:'.$id; },range(116,92)), 'An expanded row binds analysis to its rendered page rather than the requested page');
$expandedPost = runScanController(scanControllerPost($expanded),$expanded['session']);
checkScanController($expandedPost['status'] === 200 && $expandedPost['cache']['generation'] === $expanded['cache']['generation'], 'Expanded-row page normalization remains admitted when action fields use its rendered page');

// Emulate the second tab at the precise interleaving after the first publishes
// its lease and before it releases/reacquires the session around matching.
$before = serialize($admitted['session']);
$competing = runScanController(scanControllerPost($outOfRange),$admitted['session']);
checkScanController($competing['status'] === 409 && ($competing['body']['error'] ?? '') === 'busy', 'A simultaneous same-generation/cursor request is denied by the actual controller lease branch');
checkScanController(serialize($competing['session']) === $before, 'A competing request cannot replace the owner lease or progress');
$expired = $admitted['session']; $oldLease = $expired['kreabank_worklist_scan']['lease']['id'];
$expired['kreabank_worklist_scan']['lease']['expires'] = time()-1;
$recovered = runScanController(scanControllerPost($outOfRange),$expired);
checkScanController($recovered['status'] === 200 && $recovered['cache']['lease']['id'] !== $oldLease, 'An expired worker reservation is recoverable without granting extra targets');
checkScanController($recovered['cache']['targets'] === $outOfRange['cache']['targets'], 'Recovering an abandoned reservation retains its exact page-sized quota');

$changedSource = syntheticScanSources(); $changedSource['staged:1']['amount'] = '1.01';
$stale = runScanController(scanControllerPost($pageA),$pageA['session'],array('sources'=>$changedSource));
checkScanController($stale['status'] === 409 && serialize($stale['session']) === serialize($pageA['session']), 'Changed persisted source evidence rejects an old request without replacing session state');
$stale = runScanController(scanControllerPost($pageA),$pageA['session'],array('scope'=>'2|7'));
checkScanController($stale['status'] === 409 && serialize($stale['session']) === serialize($pageA['session']), 'A different company scope cannot reuse or overwrite the previous company scan');
$stale = runScanController(scanControllerPost($pageA),$pageA['session'],array('scope'=>'1|8'));
checkScanController($stale['status'] === 409 && serialize($stale['session']) === serialize($pageA['session']), 'A different user scope cannot admit the previous user scan');

$cached = $pageA['session'];
$unfinishedMore = runScanController(scanControllerPost($pageA,array('action'=>'more_scan')),$cached);
checkScanController($unfinishedMore['redirected'] && serialize($unfinishedMore['session']) === serialize($cached), 'Actual More handler cannot replace an unfinished automatic batch');
foreach ($pageA['cache']['targets'] as $key) { $cached['kreabank_worklist_scan']['summaries'][$key] = array('key'=>$key,'status'=>'pending'); }
$reload = runScanController(array('page'=>0),$cached);
checkScanController(serialize($reload['session']) === serialize($cached), 'Reloading a completed page does not grant hidden additional work');
$more = runScanController(scanControllerPost($reload,array('action'=>'more_scan')),$reload['session']);
checkScanController($more['redirected'] && $more['cache']['targets'] === array_map(static function ($id) { return 'staged:'.$id; },range(141,117)), 'Actual explicit More POST grants the next 25 and redirects without matching');
checkScanController($more['base']['page'] === 1, 'More opens the native page containing the newly granted results');
checkScanController($more['redirect']['page'] === 1, 'The actual by-value redirect closure receives the new page explicitly');
$morePage = runScanController($more['base'],$more['session']);
checkScanController($morePage['cache']['targets'] === $more['cache']['targets'], 'The next native page retains the granted batch rather than expanding matching');
$repeatMore = runScanController(scanControllerPost($reload,array('action'=>'more_scan')),$more['session']);
checkScanController($repeatMore['status'] === 409 && serialize($repeatMore['session']) === serialize($more['session']), 'Replaying More cannot widen its already-issued quota');
$leasedComplete = $reload['session'];
kreabankWorklistClaimScan($leasedComplete['kreabank_worklist_scan']);
$moreWhileBusy = runScanController(scanControllerPost($reload,array('action'=>'more_scan')),$leasedComplete);
checkScanController($moreWhileBusy['redirected'] && serialize($moreWhileBusy['session']) === serialize($leasedComplete), 'Even a completed target set cannot grant More while its owner lease is live');

echo "OK: $checks worklist scan controller checks\n";
