<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
/** Separate all-account reconciliation list. Existing reconciliation pages are untouched. */
$loaded = false;
foreach (array($_SERVER['CONTEXT_DOCUMENT_ROOT'] ?? '', __DIR__ . '/../..', __DIR__ . '/../../..') as $root) {
	if ($root !== '' && is_file($root . '/main.inc.php')) { $loaded = require $root . '/main.inc.php'; if ($loaded) { break; } }
}
if (!$loaded) { die('Include of main fails'); }
require_once __DIR__ . '/lib/kreabank.lib.php';
require_once __DIR__ . '/class/KreaBankService.class.php';
require_once __DIR__ . '/class/KreaBankWorklist.class.php';
require_once __DIR__ . '/lib/kreabank_worklist_view.lib.php';
require_once __DIR__ . '/lib/kreabank_planned_payment.lib.php';
require_once __DIR__ . '/lib/kreabank_worklist_undo.lib.php';
$langs->loadLangs(array('banks','bills','salaries','trips','donations','loan','kreabank@kreabank','worklist@kreabank'));
if (!isModEnabled('kreabank') || !isModEnabled('banque') || !empty($user->socid)
	|| !$user->hasRight('banque','lire') || !$user->hasRight('kreabank','reconciliation','read')) { accessforbidden(); }
// One line at a time: every creation and review option of the former selected-line page.
// Its POST actions are distinct from the list actions below and keep their own checks.
if (in_array(GETPOST('action','aZ09'), array('more_entry','more_rule','more_writeoff','more_draft','skip_line','bulk_skip'), true)) { accessforbidden(); }
if (GETPOST('workspace','aZ09') === '1' || ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(GETPOST('action','aZ09'), array('reconcile','quick_assign','quick_entry',
	'batch_safe','confirm_document','confirm_rule_entry','confirm_writeoff','confirm_existing_movements','change_movement_account','validate_draft_invoice'), true))) {
	define('KREABANK_LINE_WORKSPACE', 1);
	require __DIR__ . '/line_workspace.inc.php';
	exit;
}
$service = new KreaBankService($db, $user, $langs);
$worklist = new KreaBankWorklist($db, $conf->entity, $service);
$history = new KreaBankHistory($db, (int) $conf->entity);
$canWrite = $user->hasRight('kreabank','reconciliation','write') && ($user->hasRight('banque','modifier') || $user->hasRight('banque','consolidate'));
$canMove = $canWrite && $user->hasRight('banque','modifier');
$canCreate = $canMove && !getDolGlobalInt('BANK_DISABLE_DIRECT_INPUT');
$paymentPermissions = array(
	'customer_invoice'=>$canWrite && isModEnabled('invoice') && $user->hasRight('facture','lire') && $user->hasRight('facture','paiement'),
	'supplier_invoice'=>$canWrite && isModEnabled('supplier_invoice') && ($user->hasRight('fournisseur','facture','lire') || $user->hasRight('supplier_invoice','lire'))
		&& ($user->hasRight('fournisseur','facture','creer') || $user->hasRight('supplier_invoice','creer')),
);
$canUndo = $canWrite && $user->hasRight('kreabank','reconciliation','undo');
$url = dol_buildpath('/custom/kreabank/reconciliationlist.php', 1);
$action = GETPOST('action','aZ09');
$post = $_SERVER['REQUEST_METHOD'] === 'POST';
if ($post && !kreabankIsTokenValid()) { accessforbidden('Invalid token'); }
if ($action !== '' && !$post) { accessforbidden(); }
$invalidFilters = false;
try { $state = kreabankWorklistState(array_merge($_GET,$_POST), 10); }
catch (InvalidArgumentException $e) { $invalidFilters = true; $state = kreabankWorklistState(array(), 10); setEventMessages($langs->trans('KreaBankWorklistInvalidFilter'),null,'errors'); }
$baseParams = $state['params'] + array('page'=>$state['page']);
$expand = GETPOST('expand','alphanohtml');
$mode = in_array(GETPOST('mode','alpha'),array('batch','create'),true) ? GETPOST('mode','alpha') : 'batch';
$search = $mode === 'batch' ? '' : substr((string) GETPOST('q','alphanohtml'),0,256);
$paymentReturn = !$post ? (string) GETPOST('payment_return','alphanohtml') : '';
$scope = (int) $conf->entity . '|' . (int) $user->id;
if (($_SESSION['kreabank_worklist_scope'] ?? '') !== $scope) {
	unset($_SESSION['kreabank_worklist_inputs'],$_SESSION['kreabank_worklist_corrected'],$_SESSION['kreabank_worklist_batch_review'],$_SESSION['kreabank_worklist_scan'],$_SESSION['kreabank_worklist_movement_inputs'],$_SESSION['kreabank_worklist_movement_reviews']);
	$_SESSION['kreabank_worklist_scope'] = $scope;
}
if (empty($_SESSION['kreabank_worklist_secret'])) { $_SESSION['kreabank_worklist_secret'] = bin2hex(random_bytes(32)); }
$secret = $_SESSION['kreabank_worklist_secret'];
$now = time();
foreach ((array) ($_SESSION['kreabank_worklist_reviews'] ?? array()) as $key=>$review) {
	if (($review['expires'] ?? 0) < $now || ($review['scope'] ?? '') !== $scope) { unset($_SESSION['kreabank_worklist_reviews'][$key]); }
}
$remember = static function ($kind,$payload) use ($scope) {
	$nonce = bin2hex(random_bytes(24));
	$_SESSION['kreabank_worklist_reviews'][$nonce] = array('kind'=>$kind,'scope'=>$scope,'expires'=>time()+900,'payload'=>$payload);
	// Only a bounded number of displayed, expiring approvals are retained.
	if (count($_SESSION['kreabank_worklist_reviews']) > 500) { $_SESSION['kreabank_worklist_reviews'] = array_slice($_SESSION['kreabank_worklist_reviews'],-500,null,true); }
	return $nonce;
};
$take = static function ($kind) use ($scope) {
	$key = (string) GETPOST('review','aZ09'); $value = $_SESSION['kreabank_worklist_reviews'][$key] ?? null;
	unset($_SESSION['kreabank_worklist_reviews'][$key]);
	if (!$value || $value['kind'] !== $kind || $value['scope'] !== $scope || $value['expires'] < time()) { throw new RuntimeException('KreaBankWorklistChanged'); }
	return $value['payload'];
};
$redirect = static function ($extra = array()) use ($url,$baseParams) {
	header('Location: ' . $url . '?' . http_build_query(array_merge($baseParams,$extra),'','&',PHP_QUERY_RFC3986)); exit;
};
$pairDescriptor = static function ($key,$pair,$explicit = false,$searchContext = '',$selection = false) use ($remember,$scope,$secret,$baseParams) {
	if (!kreabankWorklistPairCanReconcile($pair)) { return array('disabled'=>true,'label'=>'KreaBankWorklistPaymentRequired'); }
	$expires = time()+900;
	$signature = kreabankBulkReviewSignature($pair,$scope . '|' . $key,$expires,$secret);
	$nonce = $remember('pair',array('key'=>$key,'pair'=>$pair,'explicit'=>$explicit,'search'=>$searchContext,'expires'=>$expires,'signature'=>$signature,'selection'=>$selection));
	$type = $pair['suggestion']['doc_type'];
	$label = $type === 'native_bank' ? 'KreaBankWorklistReconcile' : 'KreaBankWorkspaceReconcilePayment';
	return array('action'=>'confirm_pair','fields'=>array_merge($baseParams,array('review'=>$nonce)),'label'=>$label);
};

// Consume legacy refresh links once; they must never survive an analysis reload.
if (!$post && GETPOST('refresh_payment','alphanohtml') !== '') {
	try {
		$refreshKey = (string) GETPOST('refresh_payment','alphanohtml');
		$worklist->load($refreshKey);
		kreabankWorklistRefreshPayment($_SESSION,$refreshKey);
	} catch (Throwable $error) { $redirect(); }
	$redirect(array('payment_return'=>$refreshKey));
}

if (!$post && $expand !== '' && GETPOST('mode','alpha') === 'search') { $redirect(array('expand'=>$expand,'mode'=>'batch','q'=>$search)); }

if ($post && $action !== '' && !in_array($action, array('advance_scan','more_scan'), true)) {
	if (!$canWrite) { accessforbidden(); }
	$scanEffect = null;
	try {
		if ($action === 'confirm_pair') {
			$review = $take('pair'); $key = $review['key']; $scanEffect = kreabankWorklistActionEffect($key,$review['pair']); $line = $worklist->load($key);
			if (!kreabankWorklistPairCanReconcile($review['pair'])) { throw new RuntimeException('KreaBankWorklistPaymentRequired'); }
			if (!empty($review['selection'])) {
				$fresh = $service->prepareWorklistSelectionPair($key,(int) $review['pair']['suggestion']['doc_id']);
			} elseif ($review['explicit']) {
				$choices = $worklist->candidates($line,$review['search'] ?? '');
				$target = $review['pair']['suggestion']; $fresh = $choices[$target['doc_type'] . '__' . (int) $target['doc_id']] ?? null;
			} else { $fresh = $worklist->prepare($key)['pair']; }
			if (!$fresh || !kreabankBulkReviewIsValid($fresh,$scope . '|' . $key,$review['expires'],$review['signature'],$secret,time(),true,(int) $line['bank_account_id'])) {
				throw new RuntimeException('KreaBankWorklistChanged');
			}
			if (!kreabankWorklistPairCanReconcile($fresh)) { throw new RuntimeException('KreaBankWorklistPaymentRequired'); }
			$scanEffect = kreabankWorklistActionEffect($key,$fresh);
			kreabankExecuteManualReview($service,$fresh);
			if (!empty($review['selection'])) { unset($_SESSION['kreabank_worklist_batch_review'][$key],$_SESSION['kreabank_worklist_inputs'][$key]); }
			setEventMessages($langs->trans('KreaBankWorklistReconciled'),null,'mesgs');
			$expand = '';
		} elseif ($action === 'create_movement') {
			if (!$canCreate) { accessforbidden(); }
			$paymentMode = (string) GETPOST('payment_mode','alphanohtml');
			$note = (string) GETPOST('movement_note','none');
			$inputs = array('payment_mode'=>substr($paymentMode,0,6),'note'=>mb_substr($note,0,1000,'UTF-8'));
			// Retain navigation inputs after an expired review only for a freshly owned source.
			if (preg_match('/^staged:[1-9][0-9]{0,9}$/D',$expand)) {
				$worklist->load($expand); $_SESSION['kreabank_worklist_movement_inputs'][$expand] = $inputs;
				if (count($_SESSION['kreabank_worklist_movement_inputs']) > 30) { array_shift($_SESSION['kreabank_worklist_movement_inputs']); }
			}
			$r = $take('movement'); $key = $r['key'];
			$scanEffect = kreabankWorklistActionEffect($key,array('line'=>$r['movement']['source']??array()));
			$expand = $key; $mode = 'create';
			$_SESSION['kreabank_worklist_movement_inputs'][$key] = $inputs;
			if (count($_SESSION['kreabank_worklist_movement_inputs']) > 30) { $_SESSION['kreabank_worklist_movement_inputs'] = array_slice($_SESSION['kreabank_worklist_movement_inputs'],-30,null,true); }
			$service->createWorklistMovementAndReconcile($r['movement'],$paymentMode,$note);
			unset($_SESSION['kreabank_worklist_movement_inputs'][$key]);
			$expand = ''; setEventMessages($langs->trans('KreaBankWorklistMovementCreated'),null,'mesgs');
		} elseif ($action === 'correct_account') {
			if (!$canMove) { accessforbidden(); }
			$r = $take('move'); $key = $r['key']; list($source,$id) = kreabankWorklistSource($key);
			if ($source !== 'staged') { throw new RuntimeException('KreaBankWorklistChanged'); }
			$scanEffect = kreabankWorklistActionEffect($key,array('line'=>$worklist->load($key)));
			$scanEffect['tokens'][] = 'native_bank:' . (int) $r['bank_line']; $scanEffect['radius'] = 62;
			$service->changeMovementAccount($id,$r['bank_line'],$r['snapshot'],$r['old'],$r['target']);
			$_SESSION['kreabank_worklist_corrected'][$key] = array('bank_line'=>$r['bank_line'],'expires'=>time()+900);
			$expand = ''; $mode = 'batch'; $search = '';
			setEventMessages($langs->trans('KreaBankAccountMoveDone'),null,'mesgs');
		} elseif (in_array($action,array('save_draft','review_batch'),true)) {
			$key = GETPOST('source_key','alphanohtml'); list($source,$id) = kreabankWorklistSource($key);
			$scanEffect = kreabankWorklistActionEffect($key,array('line'=>$worklist->load($key)),false);
			$members = GETPOST('batch_members','array'); $ids = array();
			foreach ((array) $members as $member) {
				if (!is_scalar($member) || !preg_match('/^[1-9][0-9]{0,9}$/D',(string) $member)) { throw new RuntimeException('KreaBankWorklistChanged'); }
				$ids[] = (int) $member;
			}
			$name = (string) GETPOST('batch_name','alphanohtml');
			if (!array_key_exists('batch_name',$_POST) && $source==='staged' && $worklist->batchesAvailable()) {
				$saved=$service->loadPaymentBatch($id); $name=(string)($saved['draft']['name']??'');
			}
			if (count($ids) !== 1 && trim($name) === '') { $name = kreabankWorklistTranslate($langs, 'KreaBankWorklistPaymentBatch'); }
			$_SESSION['kreabank_worklist_inputs'][$key] = array('name'=>$name,'ids'=>$ids);
			$expand = $key; $mode = 'batch';
			unset($_SESSION['kreabank_worklist_batch_review'][$key]);
			if ($action === 'review_batch' && count($ids) === 1) {
				// A singleton is an ordinary native reconciliation, never a one-member group.
				$pair = $service->prepareWorklistSelectionPair($key,$ids[0]);
				$descriptor = $pairDescriptor($key,$pair,true,'',true);
				$_SESSION['kreabank_worklist_batch_review'][$key] = $descriptor['fields']['review'];
			} else {
				if ($source !== 'staged' || !$worklist->batchesAvailable()) { throw new RuntimeException('KreaBankWorklistChanged'); }
				$service->savePaymentBatchDraft($id,$ids,$name);
				if ($action === 'review_batch') {
					$batchReview = $service->preparePaymentBatchReview($id,$ids,$name);
					$_SESSION['kreabank_worklist_batch_review'][$key] = $remember('batch',array('key'=>$key,'batch'=>$batchReview));
				} else { setEventMessages($langs->trans('KreaBankWorklistDraftSaved'),null,'mesgs'); }
			}
		} elseif ($action === 'confirm_batch') {
			$r = $take('batch'); $scanEffect = kreabankWorklistActionEffect($r['key'],array('line'=>$r['batch']['source']??array(),'batch'=>array('active'=>$r['batch']))); $service->reconcilePaymentBatch($r['batch']);
			unset($_SESSION['kreabank_worklist_batch_review'][$r['key']],$_SESSION['kreabank_worklist_inputs'][$r['key']]);
			$expand = ''; setEventMessages($langs->trans('KreaBankWorklistReconciled'),null,'mesgs');
		} elseif ($action === 'undo_pair') {
			if (!$canUndo) { accessforbidden(); }
			$key = (string) GETPOST('source_key','alphanohtml');
			$line = $worklist->load($key);
			$review = kreabankWorklistTakeUndo($history,$key,(int) $line['bank_account_id'],GETPOST('undo_review','aZ09'));
			$scanEffect = kreabankWorklistUndoEffect($key,$line,$review);
			list($source,$id) = kreabankWorklistSource($key);
			$service->undoReconciliation($id,mb_substr((string) GETPOST('reason','alphanohtml'),0,1000,'UTF-8'),$review);
			$expand = ''; setEventMessages($langs->trans('KreaBankWorklistReconciliationUndone'),null,'mesgs');
		} elseif ($action === 'undo_batch') {
			if (!$canUndo) { accessforbidden(); }
			$r = $take('undo'); list($source,$id) = kreabankWorklistSource($r['key']);
			$scanEffect = kreabankWorklistActionEffect($r['key'],array('line'=>$r['batch']['source']??array(),'batch'=>array('active'=>$r['batch'])));
			$service->undoPaymentBatch($id,$r['batch']);
			$expand = ''; setEventMessages($langs->trans('KreaBankWorklistUndoDone'),null,'mesgs');
		} else { throw new RuntimeException('KreaBankWorklistChanged'); }
		if ($scanEffect) { kreabankWorklistInvalidateScan($_SESSION,$scanEffect,!empty($scanEffect['financial'])); }
	} catch (Throwable $error) {
		if ($scanEffect) { kreabankWorklistInvalidateScan($_SESSION,$scanEffect); }
		// SQL, source payloads and provider details are never copied into UI errors.
		dol_syslog('KreaBank worklist action failed: ' . get_class($error),LOG_ERR);
		$message = $error->getMessage();
		if ($error instanceof KreaBankExistingMovementException) {
			// A possible duplicate is a review step: name the movements, create nothing.
			$existing = array();
			foreach ($error->movementIds as $existingId) { $existing[] = '<a href="' . dol_escape_htmltag(DOL_URL_ROOT . '/compta/bank/line.php?rowid=' . (int) $existingId) . '" target="_blank" rel="noopener">#' . (int) $existingId . '</a>'; }
			setEventMessages(dol_escape_htmltag($langs->transnoentitiesnoconv($error->complete ? 'KreaBankExistingMovementBlocked' : 'KreaBankExistingMovementIncomplete')) . ' ' . implode(', ',$existing),null,'errors');
			$redirect($expand !== '' ? array('expand'=>$expand,'mode'=>$mode,'q'=>$search) : array());
		}
		$key = $message === 'KreaBankWorklistPaymentRequired' ? $message : (preg_match('/changed|expired|already|review.*again|unavailable/i',$message) ? 'KreaBankWorklistChanged' : (preg_match('/exact.*amount|exact.*total|total.*equal|at least|requires 2/i',$message) ? 'KreaBankWorklistBatchIncomplete' : 'KreaBankWorklistActionFailed'));
		setEventMessages($langs->trans($key),null,'errors');
	}
	$redirect($expand !== '' ? array('expand'=>$expand,'mode'=>$mode,'q'=>$search) : array());
}

try { $sources = $invalidFilters ? array() : $worklist->sources($state); }
catch (Throwable $error) { $sources = array(); setEventMessages($langs->trans($error->getMessage() === 'KreaBankWorklistRefineFilters' ? $error->getMessage() : 'KreaBankWorklistReadFailed'),null,'errors'); }
$sourceKeys = array_keys($sources);
$requiresComplete = $state['filters']['match'] !== '' || $state['filters']['payment_account'] !== ''
	|| in_array($state['sort']['field'],array('match','payment_account'),true);
$scanFilters = $state['filters']; unset($scanFilters['match'],$scanFilters['payment_account']);
$policy=array();
foreach (array('KREABANK_AUTOMATCH_DATE_TOLERANCE','KREABANK_OPEN_DOC_DATE_INTERVAL_DAYS','KREABANK_BULKMATCH_MIN_SCORE','KREABANK_ACCOUNT_PAIRS') as $setting) { $policy[$setting]=$conf->global->$setting??null; }
$access = kreabankWorklistAccessFingerprint($user,array($canWrite??false,$canMove??false,$canCreate??false,$canUndo??false,$paymentPermissions??array(),$policy,
	function_exists('isModEnabled') ? array(isModEnabled('tax'),isModEnabled('salaries'),isModEnabled('invoice'),isModEnabled('supplier_invoice')) : array()));
$scanContext = hash('sha256',json_encode(array('worklist-presentation-v5',$scope,$access,$state['filters'])));
$sourceState = kreabankWorklistSourceState($sources);
$scanScope = hash('sha256',json_encode(array($scanContext,$sourceState)));
$cache = $_SESSION['kreabank_worklist_scan'] ?? array();
$rejectScan = static function () use ($redirect) {
	if (($_SERVER['HTTP_X_KREABANK_SCAN'] ?? '') === '1') { http_response_code(409); header('Content-Type: application/json'); print json_encode(array('error'=>'changed')); exit; }
	$redirect();
};
$isScanPost = in_array($action, array('advance_scan','more_scan'), true);
if ($isScanPost && !kreabankWorklistScanAdmitted($cache,$scanScope,GETPOST('scan_generation','aZ09'),GETPOSTINT('scan_cursor'))) { $rejectScan(); }
$reusePrepared = !$post && ($cache['context']??'') === $scanContext && ($cache['reuse_once']??0)>=time();
if (!$post) { unset($cache['reuse_once']); }
foreach (array('button_search','button_search_x','button_search.x','button_removefilter','button_removefilter_x','button_removefilter.x') as $button) {
	if (!$post && array_key_exists($button,$_GET)) { $cache=array(); $reusePrepared=false; break; }
}
$cache = kreabankWorklistRebaseScan($cache,$scanContext,$scanScope,$sourceState,time());
$_SESSION['kreabank_worklist_scan'] = $cache;
// Only the payment's owned source is refreshed; the rest of the scan is retained.
if ($paymentReturn !== '' && isset($sources[$paymentReturn])) {
	try {
		kreabankWorklistStoreSummary($cache,$paymentReturn,$worklist->compact($paymentReturn));
		$cache['generation'] = bin2hex(random_bytes(16)); $cache['cursor'] = 0; unset($cache['lease']);
		$_SESSION['kreabank_worklist_scan'] = $cache;
	} catch (Throwable $error) { setEventMessages($langs->trans('KreaBankWorklistChanged'),null,'errors'); }
}
// Bind the rendered native page, including an out-of-range or filtered-page reset.
$scanComplete = count($cache['summaries']) >= count($sourceKeys);
$previewRows = array();
foreach ($sources as $key=>$source) {
	$summary = $cache['summaries'][$key] ?? array('key'=>$key,'status'=>$source['worklist_status']);
	$summary['line'] = $source; $previewRows[] = $summary;
}
$previewFiltered = $requiresComplete && !$scanComplete ? array() : kreabankWorklistFilterRows($previewRows,$state);
if ($expand !== '') {
	$position = array_search($expand,array_column($previewFiltered,'key'),true);
	if ($position !== false) { $state['page'] = intdiv($position,$state['limit']); }
}
$previewPage = kreabankWorklistPage($previewFiltered,$state);
$state['page'] = $previewPage['page']; $baseParams['page'] = $state['page'];
$displayKeys = $requiresComplete ? array() : array_column($previewPage['rows'],'key');
$scanView = hash('sha256', json_encode(array($state['params'], $state['page'])));
if ($isScanPost && !kreabankWorklistScanAdmitted($cache,$scanScope,GETPOST('scan_generation','aZ09'),GETPOSTINT('scan_cursor'),$scanView)) { $rejectScan(); }
if (($cache['view'] ?? '') !== $scanView || !isset($cache['targets'])) {
	$cache['view'] = $scanView;
	$cache['targets'] = kreabankWorklistScanTargets($sourceKeys, $displayKeys, $cache['summaries'], $state['limit']);
	$cache['generation'] = bin2hex(random_bytes(16)); $cache['cursor'] = 0; unset($cache['lease']);
	$_SESSION['kreabank_worklist_scan'] = $cache;
}
kreabankWorklistPresentationPage($cache,$displayKeys ?: $cache['targets'],$state['limit']);
$_SESSION['kreabank_worklist_scan'] = $cache;
$pageReady = static function ($scan) {
	foreach ($scan['targets'] as $key) { if (!isset($scan['summaries'][$key])) { return false; } }
	return true;
};
if ($action === 'more_scan') {
	$moreKeys = $requiresComplete ? $sourceKeys : array_column($previewFiltered,'key');
	if (!kreabankWorklistMoreScan($cache, $moreKeys, $state['limit'], GETPOST('scan_generation','aZ09'), GETPOSTINT('scan_cursor'))) { $redirect(); }
	$_SESSION['kreabank_worklist_scan'] = $cache;
	if (!$requiresComplete && $cache['targets']) {
		$position = array_search($cache['targets'][0],$moreKeys,true);
		if ($position !== false) { $baseParams['page'] = intdiv($position,$state['limit']); }
	}
	$redirect(array('page'=>$baseParams['page']));
}
if ($action === 'advance_scan') {
	$ajax = ($_SERVER['HTTP_X_KREABANK_SCAN'] ?? '') === '1';
	$expectedGeneration = GETPOST('scan_generation','aZ09'); $expectedCursor = GETPOSTINT('scan_cursor');
	$identity = array(session_id(),$_SESSION['dol_login'] ?? '',$_SESSION['dol_entity'] ?? $conf->entity,(int) $user->id);
	if ($expectedGeneration !== $cache['generation'] || $expectedCursor !== $cache['cursor']) {
		if ($ajax) { http_response_code(409); header('Content-Type: application/json'); print json_encode(array('error'=>'changed')); exit; }
		$redirect();
	}
	if (!kreabankWorklistClaimScan($cache)) { http_response_code(409); header('Content-Type: application/json'); print json_encode(array('error'=>'busy')); exit; }
	$_SESSION['kreabank_worklist_scan'] = $cache;
	$sessionId = session_id(); session_write_close();
	$error = false; $next = $cache;
	try {
		kreabankWorklistAdvanceScan($next, static function ($key) use ($worklist) { return $worklist->compact($key); });
	} catch (Throwable $exception) { $error = true; }
	if (!session_start()) { http_response_code(409); exit; }
	$currentIdentity = array(session_id(),$_SESSION['dol_login'] ?? '',$_SESSION['dol_entity'] ?? $conf->entity,(int) $user->id);
	$current = $_SESSION['kreabank_worklist_scan'] ?? array();
	if ($identity !== $currentIdentity || ($current['generation'] ?? '') !== $cache['generation'] || (int) ($current['cursor'] ?? -1) !== $cache['cursor'] || ($current['lease']['id'] ?? '') !== $cache['lease']['id']) {
		if ($ajax) { http_response_code(409); header('Content-Type: application/json'); print json_encode(array('error'=>'changed')); exit; }
		$redirect();
	}
	unset($next['lease']);
	$next['cursor']++;
	if (!$error && $pageReady($next)) { $next['reuse_once']=time()+30; }
	$_SESSION['kreabank_worklist_scan'] = $next;
	if ($ajax) {
		header('Content-Type: application/json'); header('Cache-Control: no-store');
		$progress = array('done'=>$pageReady($next),'processed'=>count(array_intersect_key($next['summaries'],array_fill_keys($next['targets'],true))),'total'=>count($next['targets']),'generation'=>$next['generation'],'cursor'=>$next['cursor'],'page_ready'=>$pageReady($next));
		if ($error) { http_response_code(503); $progress['error'] = 'failed'; }
		print json_encode($progress);
		exit;
	}
	if ($error) { setEventMessages($langs->trans('KreaBankWorklistAnalysisFailed'),null,'errors'); }
	$redirect();
}
$scanDone = count($cache['summaries']) >= count($sourceKeys);
$indexed = array();
foreach ($sources as $key=>$source) {
	$line = $source; $line['fk_statement'] = (int) $source['statement_id'];
	$summary = $cache['summaries'][$key] ?? array('key'=>$key,'status'=>$source['worklist_status'],'pending_analysis'=>true);
	$summary['line'] = $line; $indexed[] = $summary;
}
$filtered = $requiresComplete && !$scanDone ? array() : kreabankWorklistFilterRows($indexed,$state);
if ($expand !== '') {
	$position = array_search($expand,array_column($filtered,'key'),true);
	if ($position !== false) { $state['page'] = intdiv($position,$state['limit']); }
}
$page = kreabankWorklistPage($filtered,$state);
$baseParams['page'] = $page['page'];
$accounts = $worklist->accounts(); $modelRows = array();
$form = new Form($db);
$paymentUrl = static function ($pair) use ($paymentPermissions,$conf,$db,$user,$service) {
	$type = $pair['suggestion']['doc_type'] ?? '';
	if (in_array($type,array('salary','tax'),true)) {
		$payments = new KreaBankPlannedPayment($db,$user,(int) $conf->entity,$service);
		if (!$payments->permissions($type)) { return ''; }
		try { $context = $payments->snapshot($pair['line'],$type,(int) $pair['suggestion']['doc_id']); }
		catch (DomainException $error) { return ''; }
		return kreabankPlannedPaymentUrl($type,(int) $context['id'],kreabankPlannedPaymentRemember($context));
	}
	$url = kreabankWorklistPaymentUrl($pair,$paymentPermissions,(string) $conf->currency);
	if ($url !== '') {
		$scope = new KreaBankReconciliationScope($db,(int) $conf->entity);
		$scope->assertLine($pair['line']);
		$scope->assertDocument($pair['suggestion']['doc_type'],(int) $pair['suggestion']['doc_id'],(int) $pair['line']['bank_account_id']);
	}
	return $url;
};
$candidateModel = static function ($pair,$paymentUrl = '') {
	$s = $pair['suggestion']; $account = kreabankWorklistPaymentAccount($pair);
	return array('key'=>$s['doc_type'] . '__' . (int) $s['doc_id'],'doc_type'=>$s['doc_type'],'doc_id'=>(int) $s['doc_id'],'reference'=>kreabankBulkSuggestionReference($s),'date'=>$s['doc_date'] ?? '',
		'amount'=>$s['amount_open'],'currency'=>$pair['line']['currency'],'account_ref'=>$account['ref'],'account_planned'=>$account['planned'],
		'description'=>$s['thirdparty_name'] ?? '', 'consequence'=>$account['planned'] ? 'KreaBankWorklistPaymentRequired' : '', 'payment_url'=>$paymentUrl,
		'customer_transfer'=>$s['customer_transfer'] ?? null);
};
kreabankWorklistPresentationPage($cache,array_column($page['rows'],'key'),$state['limit']);
foreach ($page['rows'] as $indexRow) {
	$key = $indexRow['key']; $isExpanded = $expand === $key;
	$row = array('key'=>$key,'line'=>$indexRow['line'],'expanded'=>$isExpanded,'batch_mode'=>$mode === 'batch','create_mode'=>$mode === 'create','search'=>$search,
		'payment_account_ref'=>$indexRow['payment_account'] ?? '',
		'pending_analysis'=>!isset($cache['summaries'][$key]),'batch_unavailable'=>false);
	try {
		if (isset($cache['summaries'][$key]) || $isExpanded || ($indexRow['line']['worklist_status'] ?? '') === 'reconciled') {
			$prepared = kreabankWorklistPreparedPresentation($cache,$key,$sources[$key]??$indexRow['line'],!empty($reusePrepared),
				static function($sourceKey)use($worklist){return $worklist->compact($sourceKey);},null,
				static function($presentation)use($worklist){return $worklist->presentationVisible($presentation);}); $row['line'] = $prepared['line'];
			$row['account_mismatch'] = !empty($prepared['account_review']);
			$row['ambiguous'] = count($prepared['account_candidates']) > 1;
			if (!empty($prepared['account_review']['truncated'])) { $row['notice'] = kreabankWorklistTranslate($langs,'KreaBankWorklistRefineSearch'); }
			$row['needs_review'] = !empty($row['line']['needs_review']);
			$row['batch_unavailable'] = $row['batch_unavailable'] || strpos($key,'native:') === 0 || empty($row['line']['account_open']) || (int) $row['line']['status'] !== 0;
			if (strpos($key,'native:') === 0 && (int) $row['line']['status'] === 0) { $row['notice'] = kreabankWorklistTranslate($langs,'KreaBankWorklistLegacySelectionUnavailable'); }
			if (!$row['account_mismatch'] && !empty($prepared['pair'])) {
				$row['review'] = $candidateModel($prepared['pair'],empty($prepared['account_candidates']) && empty($prepared['batch']['active']) && empty($prepared['batch']['draft']) ? $paymentUrl($prepared['pair']) : '');
				if ($canWrite) { $row['review']['action'] = $pairDescriptor($key,$prepared['pair']); }
			} elseif (count($prepared['account_candidates']) === 1 && empty($prepared['account_review']['truncated']) && ($prepared['account_review']['evidence'] ?? '') !== 'amount_date') {
				$c = $prepared['account_candidates'][0];
				$row['review'] = array('doc_type'=>$c['payment_type'],'doc_id'=>(int) $c['payment_id'],'reference'=>$c['payment_ref'],'date'=>$c['date'],'amount'=>$c['amount'],'currency'=>$row['line']['currency'],'account_ref'=>$c['account_ref'],'description'=>$c['reference']);
			}
			if (!empty($prepared['completed'])) { $row['review'] = $prepared['completed']; }
			$batch = $prepared['batch'];
			if (!empty($batch['draft'])) {
				$row['draft'] = array('name'=>$batch['draft']['name'],'member_keys'=>array_map('strval',$batch['draft']['ids']));
				$row['payment_account_ref'] = implode(', ',array_column($batch['draft']['accounts'],'ref'));
			}
			if (isset($_SESSION['kreabank_worklist_inputs'][$key])) {
				$inputs = $_SESSION['kreabank_worklist_inputs'][$key];
				$row['draft'] = array('name'=>$inputs['name'],'member_keys'=>array_map('strval',$inputs['ids']));
			}
			if (!empty($batch['active'])) {
				$row['batch_active'] = true; $row['reconciled'] = true; $row['batch_unavailable'] = true; $row['payment_account_ref'] = implode(', ',array_unique(array_column($batch['active']['components'],'bank_account_ref')));
				$row['review'] = array('reference'=>$batch['active']['name'] ?: kreabankWorklistTranslate($langs, 'KreaBankWorklistPaymentBatch'),'date'=>$row['line']['operation_date'],
					'amount'=>$row['needs_review'] ? null : $row['line']['amount_decimal'],'currency'=>$row['line']['currency'],'account_ref'=>$row['payment_account_ref']);
				foreach ($batch['active']['components'] as $c) { $row['batch_components'][] = array('key'=>(string) $c['fk_bank_line'],'doc_type'=>'native_bank','doc_id'=>(int) $c['fk_bank_line'],'reference'=>$c['ref'] ?: '#' . $c['fk_bank_line'],'date'=>$c['doc_date'],'amount'=>$c['current_amount'],'currency'=>$row['line']['currency'],'account_ref'=>$c['bank_account_ref'],'description'=>$c['description']); }
				if ($canUndo && !$row['needs_review']) { $row['undo_review'] = array('action'=>'undo_batch','fields'=>array_merge($baseParams,array('review'=>$remember('undo',array('key'=>$key,'batch'=>$service->preparePaymentBatchUndoReview((int) $row['line']['rowid'])))))); }
			}
			if ($canUndo && empty($row['batch_active']) && !$row['needs_review'] && ($row['line']['worklist_status'] ?? '') === 'reconciled') {
				$undo = kreabankWorklistPrepareUndo($history,$key,(int) $row['line']['bank_account_id']);
				if ($undo) { $row['undo_review'] = array('action'=>'undo_pair','fields'=>array_merge($baseParams,array('source_key'=>$key,'undo_review'=>$undo['nonce'])),
					'effect'=>!empty($undo['snapshot']['writeoff']) ? 'KreaBankWorklistUndoWriteoffEffect' : 'KreaBankWorklistUndoReconciliationEffect'); }
			}
			$row['create_available'] = $canCreate && strpos($key,'staged:') === 0 && !$row['batch_unavailable']
				&& empty($row['needs_review']) && empty($prepared['pair']) && !$row['account_mismatch'] && empty($batch['draft']) && empty($batch['active']);
			if ($isExpanded && $mode === 'create') {
				if (!$row['create_available']) { $row['creation_notice'] = kreabankWorklistTranslate($langs, 'KreaBankWorklistMovementUnavailable'); }
				else {
					try {
						$movement = $service->prepareWorklistMovementReview($key);
						$inputs = $_SESSION['kreabank_worklist_movement_inputs'][$key] ?? array();
						$row['movement_form'] = array('action'=>'create_movement','fields'=>array_merge($baseParams,array('expand'=>$key,'mode'=>'create','q'=>$search,'review'=>$remember('movement',array('key'=>$key,'movement'=>$movement)))),
							'source'=>$movement['source'],'modes'=>$movement['modes'],'payment_mode'=>$inputs['payment_mode'] ?? '', 'note'=>$inputs['note'] ?? '');
					} catch (Throwable $error) {
						$row['creation_notice'] = kreabankWorklistTranslate($langs, 'KreaBankWorklistMovementUnavailable');
					}
				}
			}
			if ($isExpanded && $mode !== 'create' && empty($row['batch_active'])) {
				$line = $row['line'];
				if ($mode === 'batch' && !$row['batch_unavailable'] && !$row['account_mismatch']) {
					$candidates = $service->listWorklistSelectionCandidates($key,$search);
					$row['grouping_available'] = $candidates['grouping_available'];
					$row['max_members'] = $candidates['grouping_available'] ? 500 : 1;
					// A fresh batch gets advisory checkboxes only; stored/user choices take precedence.
					if (!array_key_exists('draft',$row) && !isset($_SESSION['kreabank_worklist_inputs'][$key])) {
						$suggestion = $candidates['suggestion'];
						$row['batch_suggestion'] = $suggestion['status'];
						if ($suggestion['status'] === 'unique') { $row['draft'] = array('name'=>'','member_keys'=>array_map('strval',$suggestion['ids'])); }
					}
					$selectedIds = array_map('intval',$row['draft']['member_keys'] ?? array());
					$retained = $service->getWorklistSelectionMembers($key,$selectedIds);
					$batchCandidates = array();
					foreach (array_merge($retained['rows'],$candidates['rows']) as $c) { $batchCandidates[(int) $c['rowid']] = $c; }
					foreach ($batchCandidates as $c) { $row['batch_candidates'][] = array('key'=>(string) $c['rowid'],'doc_type'=>'native_bank','doc_id'=>(int) $c['rowid'],'reference'=>(($c['payment_refs'] ?? '') ?: $c['ref']) ?: '#' . $c['rowid'],'date'=>$c['doc_date'],'amount'=>$c['amount_decimal'],'currency'=>$line['currency'],'account_ref'=>$c['bank_account_ref'],'description'=>$c['description'],'selectable'=>true,'single_eligible'=>!empty($c['single_eligible']),'group_eligible'=>!empty($c['group_eligible'])); }
					foreach ($retained['unavailable'] as $missing) { $row['batch_candidates'][] = array('key'=>(string) $missing,'reference'=>'#' . $missing,'date'=>'','amount'=>null,'currency'=>$line['currency'],'account_ref'=>'','description'=>kreabankWorklistTranslate($langs, 'KreaBankWorklistUnavailablePayment'),'selectable'=>true,'selected'=>true,'single_eligible'=>false,'group_eligible'=>false); }
					if ($candidates['truncated']) { $row['notice'] = kreabankWorklistTranslate($langs, 'KreaBankWorklistRefineSearch'); }
					$row['batch_form'] = array('fields'=>array_merge($baseParams,array('source_key'=>$key,'expand'=>$key,'mode'=>'batch')),'selection_name'=>'batch_members[]','name_field'=>'batch_name','save_action'=>'save_draft','review_action'=>'review_batch','grouping_available'=>$candidates['grouping_available']);
					if (!$retained['unavailable']) {
						$total = KreaBankPaymentBatchDecimal::sum(array_map(static function ($c) { return $c['amount_decimal']; },$retained['rows']));
						$row['batch_form']['selected_total'] = $total;
						$row['batch_form']['difference'] = KreaBankPaymentBatchDecimal::sum(array($line['amount_decimal'],$total[0] === '-' ? substr($total,1) : '-' . $total));
					}
					$reviewNonce = $_SESSION['kreabank_worklist_batch_review'][$key] ?? '';
					$stored = $_SESSION['kreabank_worklist_reviews'][$reviewNonce] ?? null;
					if (kreabankWorklistSelectionReviewMatches($stored,$key,$line,$scope,time())) {
						if ($stored['kind'] === 'pair' && !empty($stored['payload']['selection'])) {
							$r = $stored['payload']; $pair = $service->prepareWorklistSelectionPair($key,(int) $r['pair']['suggestion']['doc_id']);
							if (!kreabankBulkReviewIsValid($pair,$scope . '|' . $key,$r['expires'],$r['signature'],$secret,time(),true,(int) $line['bank_account_id'])) { throw new RuntimeException('KreaBankWorklistChanged'); }
							$row['batch_components'] = array($candidateModel($pair));
							$row['batch_form']['selected_total'] = $pair['suggestion']['amount_open']; $row['batch_form']['difference'] = '0.00000000';
							$row['batch_review'] = array('action'=>'confirm_pair','fields'=>array_merge($baseParams,array('review'=>$reviewNonce)));
						} elseif ($stored['kind'] === 'batch') {
							$review = $stored['payload']['batch']; $row['batch_components'] = array();
							$row['draft'] = array('name'=>$review['name'],'member_keys'=>array_map('strval',$review['ids']));
							$row['batch_form']['selected_total'] = $review['amount']; $row['batch_form']['difference'] = '0.00000000';
							foreach ($review['components'] as $c) { $row['batch_components'][] = array('key'=>(string) ($c['rowid'] ?? $c['id'] ?? $c['bank_line_id'] ?? ''),'doc_type'=>'native_bank','doc_id'=>(int) ($c['rowid'] ?? $c['id'] ?? $c['bank_line_id'] ?? 0),'reference'=>($c['payment_refs'] ?? '') ?: ($c['ref'] ?? '') ?: '#' . ($c['rowid'] ?? ''),'date'=>$c['doc_date'] ?? $c['dateo'] ?? '','amount'=>$c['amount'] ?? $c['amount_decimal'],'currency'=>$line['currency'],'account_ref'=>$line['bank_account_ref'],'description'=>$c['description'] ?? $c['label'] ?? ''); }
							$row['batch_review'] = array('action'=>'confirm_batch','fields'=>array_merge($baseParams,array('review'=>$reviewNonce)));
						}
					}
				}
			}
			if (!empty($row['line']['fk_statement']) && (int) $row['line']['status'] === 0 && !empty($prepared['account_candidates'])) {
				$unique = count($prepared['account_candidates']) === 1 && empty($prepared['account_review']['truncated']);
				foreach ($prepared['account_candidates'] as $c) {
					$item = array('key'=>'native:' . $c['bank_line_id'],'doc_type'=>'native_bank','doc_id'=>(int) $c['bank_line_id'],
						'reference'=>$c['payment_ref'] ?: $c['reference'],'date'=>$c['date'],'amount'=>$c['amount'],'currency'=>$row['line']['currency'],
						'account_ref'=>$c['account_ref'],'description'=>$c['reference'],'possible'=>($prepared['account_review']['evidence'] ?? '') === 'amount_date');
					if ($unique && $canMove) {
						$native = $service->searchOtherAccountMovements((int) $row['line']['rowid'],'',(int) $c['bank_line_id']);
						if (count($native) === 1 && $native[0]['can_move'] && kreabankWorklistCorrectionMatches($prepared['account_candidates'],$native[0])) {
							$n = $native[0];
							$item['correction'] = array('action'=>'correct_account','fields'=>array_merge($baseParams,array('review'=>$remember('move',array('key'=>$key,'bank_line'=>(int) $n['rowid'],'snapshot'=>$n['snapshot'],'old'=>(int) $n['fk_account'],'target'=>(int) $n['target_account_id'])))),
								'from_account_ref'=>$c['account_ref'],'to_account_ref'=>$row['line']['bank_account_ref']);
						}
					}
					$row['bank_reviews'][] = $item;
				}
			}
		}
	} catch (Throwable $e) {
		$row['pending_analysis'] = false; $row['notice'] = kreabankWorklistTranslate($langs, 'KreaBankWorklistChanged'); $row['batch_unavailable'] = true;
		unset($row['review']['action'],$row['review']['correction'],$row['review']['payment_url'],$row['batch_form'],$row['batch_review'],$row['undo_review'],$row['movement_form']);
		foreach (array('candidates','bank_reviews') as $field) { foreach (($row[$field] ?? array()) as $i=>$unused) { unset($row[$field][$i]['action'],$row[$field][$i]['correction'],$row[$field][$i]['payment_url']); } }
	}
	$modelRows[] = $row;
}
if (!empty($cache['presentation_invalidated'])) {
	$cache['targets']=kreabankWorklistScanTargets($sourceKeys,array_column($page['rows'],'key'),$cache['summaries'],$state['limit']);
	unset($cache['presentation_invalidated']);
}
$scanDone=count($cache['summaries'])>=count($sourceKeys);
$_SESSION['kreabank_worklist_scan'] = $cache;
ob_start();
print_barre_liste($langs->trans('KreaBankWorklistTitle'),$page['page'],$_SERVER['PHP_SELF'],'&' . http_build_query($state['params'],'','&',PHP_QUERY_RFC3986),$state['sort']['field'],$state['sort']['order'],'',$page['num'],$page['total'],'bank',0,'','',$page['limit'],0,0,1);
$pagination = ob_get_clean();
$headers = array();
$headerParams = $state['params'];
// Native title links add sort state themselves; their cleanup truncates underscores.
unset($headerParams['sortfield'], $headerParams['sortorder']);
foreach (array('operation_date'=>'KreaBankDate','bank_account_ref'=>'KreaBankWorklistStatementAccount','description'=>'KreaBankReviewStatementLine','amount'=>'Amount','match'=>'KreaBankWorklistMatch','payment_account'=>'KreaBankWorklistPaymentAccount') as $field=>$label) {
	ob_start(); print_liste_field_titre($langs->trans($label),$_SERVER['PHP_SELF'],$field,'','&' . http_build_query($headerParams,'','&',PHP_QUERY_RFC3986),'',$state['sort']['field'],$state['sort']['order'],$field === 'amount' ? 'right ' : ''); $headers[$field] = ob_get_clean();
}
$scan = null;
if (!$scanDone) {
	$batchDone = $pageReady($cache);
	$scan = array('message'=>kreabankWorklistTranslate($langs, $batchDone ? 'KreaBankWorklistResultsReady' : 'KreaBankWorklistAnalyzing'),'done'=>count(array_intersect_key($cache['summaries'],array_fill_keys($cache['targets'],true))),'total'=>count($cache['targets']),'page_ready'=>$batchDone,'auto_scan'=>$paymentReturn === '',
		'action'=>array('action'=>$batchDone ? 'more_scan' : 'advance_scan','fields'=>array_merge($baseParams,array('scan_generation'=>$cache['generation'],'scan_cursor'=>$cache['cursor']))));
}
$navigationAccount = kreabankNavigationAccount((int) $state['filters']['account']);
llxHeader('',$langs->trans('KreaBankWorklistTitle'));
print kreabankBankingHeader('worklist',$navigationAccount,false);
$assetRoot = dol_buildpath('/custom/kreabank/',1);
print '<link rel="stylesheet" href="' . dol_escape_htmltag($assetRoot . 'css/worklist.css?v=' . filemtime(__DIR__ . '/css/worklist.css')) . '">';
print '<script defer src="' . dol_escape_htmltag($assetRoot . 'js/worklist.js?v=' . filemtime(__DIR__ . '/js/worklist.js')) . '"></script>';
print kreabankWorklistTable(array('url'=>$url,'token'=>newToken(),'state'=>$baseParams,'filters'=>array_combine(array_map(static function ($k) { return 'search_' . $k; },array_keys($state['filters'])),array_values($state['filters'])),
	'navigation_scope'=>hash('sha256',$scope . '|' . $secret),'navigation_context'=>hash('sha256',json_encode(array($state['filters'],$state['sort'],$state['limit']))),'navigation_page'=>$page['page'],
	'accounts'=>$accounts,'rows'=>$modelRows,'can_write'=>$canWrite,'sort'=>$state['sort'],'pagination'=>$pagination,'headers'=>$headers,'filter_buttons'=>$form->showFilterButtons(),'scan'=>$scan,
	'hide_statement_account'=>!empty($navigationAccount),
	'analysis_pending'=>$requiresComplete && !$scanDone,'status_counts'=>$worklist->statusCounts($state,$cache['summaries'],$scanDone),
	'notice'=>!$worklist->batchesAvailable() ? kreabankWorklistTranslate($langs, 'KreaBankWorklistBatchesUnavailable') : ''),$langs);
llxFooter(); $db->close();
