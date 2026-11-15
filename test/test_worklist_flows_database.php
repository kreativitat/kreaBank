#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 * ONLY disposable 127.0.0.1:33317/kreabank_auto_test synthetic data.
 * Resets kbauto_* through the guarded parent fixture; never run concurrently.
 * Executes the actual list POST branch with the real service and native AccountLine.
 * Framework request/redirect helpers are intercepted; this is not a browser test.
 */
require __DIR__ . '/test_automatic_reconciliation_database.php';
require_once __DIR__ . '/../class/KreaBankWorklist.class.php';
require_once __DIR__ . '/../class/KreaBankHistory.class.php';
require_once __DIR__ . '/../lib/kreabank_worklist_view.lib.php';
$checks = 0;

// The fixture deliberately supplies no installation bootstrap or credentials.
function GETPOST($name, $filter = '') { return $_POST[$name] ?? $_GET[$name] ?? ($filter === 'array' ? array() : ''); }
function currentToken() { return $_SESSION['flow_csrf'] ?? ''; }
function newToken() { return currentToken(); }
function dol_buildpath($path, $type = 0) { return $path; }
function setEventMessages($message, $other = null, $style = '') { $GLOBALS['flow_events'][] = array($message, $style); }
function accessforbidden($message = '') { throw new WorklistFlowForbidden($message); }
class WorklistFlowForbidden extends RuntimeException {}
class WorklistFlowRedirect extends RuntimeException
{
	public $params;
	public function __construct($params) { parent::__construct('Intercepted redirect'); $this->params = $params; }
}
class WorklistFlowUser extends User
{
	public $denied = array();
	public function hasRight($a, $b, $c = '') { return !in_array(implode('/', array_filter(array($a,$b,$c))), $this->denied, true); }
}

/** Run unmodified financial/controller branches; replace only the terminal redirect. */
function runWorklistFlow($post, $session = array(), $options = array())
{
	global $db, $conf, $user, $langs;
	$_GET = $options['get'] ?? array(); $_POST = $post; $_SESSION = $session;
	$_SERVER['REQUEST_METHOD'] = $options['method'] ?? 'POST';
	$GLOBALS['flow_events'] = array();
	$conf->entity = $options['entity'] ?? 1; $conf->liste_limit = 25;
	$user = new WorklistFlowUser(); $user->id = $options['user_id'] ?? 7; $user->denied = $options['denied'] ?? array();
	$service = new KreaBankService($db, $user, $langs);
	$worklist = new KreaBankWorklist($db, $conf->entity, $service);
	$source = file_get_contents(__DIR__ . '/../reconciliationlist.php');
	$authStart = strpos($source, "if (!isModEnabled('kreabank')");
	$authEnd = strpos($source, '$service = new KreaBankService', $authStart);
	$start = strpos($source, '$canWrite ='); $end = strpos($source, 'try { $sources =', $start);
	if ($authStart === false || $authEnd === false || $start === false || $end === false) { throw new LogicException('Worklist controller boundaries changed'); }
	$auth = substr($source, $authStart, $authEnd - $authStart);
	$branch = substr($source, $start, $end - $start);
	$terminal = "header('Location: ' . \$url . '?' . http_build_query(array_merge(\$baseParams,\$extra),'','&',PHP_QUERY_RFC3986)); exit;";
	$branch = str_replace($terminal, 'throw new WorklistFlowRedirect(array_merge($baseParams,$extra));', $branch, $replaced);
	if ($replaced !== 1) { throw new LogicException('Worklist redirect contract changed'); }
	$redirect = null; $forbidden = false;
	try { eval($auth); eval($branch); }
	catch (WorklistFlowRedirect $event) { $redirect = $event->params; }
	catch (WorklistFlowForbidden $event) { $forbidden = true; }
	return array('session'=>$_SESSION, 'events'=>$GLOBALS['flow_events'], 'redirect'=>$redirect, 'forbidden'=>$forbidden);
}
function flowSession() { return array('flow_csrf'=>'disposable-fixture-token', 'kreabank_worklist_scope'=>'1|7'); }
function flowPost($action, $fields = array()) { return array_merge(array('action'=>$action, 'token'=>'disposable-fixture-token'), $fields); }
function flowState($sourceIds, $nativeIds)
{
	global $db;
	return array(
		'sources'=>$db->query('SELECT rowid,status,allocated_amount,fk_native_bank_line FROM kbauto_kreabank_statement_line WHERE rowid IN (' . implode(',', $sourceIds) . ') ORDER BY rowid')->fetch_all(MYSQLI_ASSOC),
		'native'=>$db->query('SELECT rowid,rappro,num_releve,CAST(amount AS DECIMAL(24,8)) amount FROM kbauto_bank WHERE rowid IN (' . implode(',', $nativeIds) . ') ORDER BY rowid')->fetch_all(MYSQLI_ASSOC),
		'bank_count'=>countAuto('bank'), 'groups'=>countAuto('kreabank_payment_batch'), 'audits'=>countAuto('kreabank_recon_audit'));
}
function flowFinancialEvidence()
{
	global $db;
	$out = array('bank'=>$db->query('SELECT rowid,fk_account,CAST(amount AS DECIMAL(24,8)) amount,dateo,datev,label,num_chq FROM kbauto_bank ORDER BY rowid')->fetch_all(MYSQLI_ASSOC));
	foreach (array('bank_url','paiement','paiement_facture','facture','paiementfourn','paiementfourn_facturefourn','facture_fourn','salary','payment_salary','paiementcharge','chargesociales','kreabank_ml_sample') as $table) {
		$out[$table] = $db->query('SELECT * FROM kbauto_' . $table . ' ORDER BY rowid')->fetch_all(MYSQLI_ASSOC);
	}
	return $out;
}
function flowEnvelope($result, $key)
{
	$nonce = $result['session']['kreabank_worklist_batch_review'][$key] ?? '';
	if ($nonce === '') { throw new RuntimeException('Controller did not prepare a selector review: ' . json_encode($result['events'])); }
	return array($nonce, $result['session']['kreabank_worklist_reviews'][$nonce]);
}
function flowProjection($id)
{
	global $db;
	$projection = new KreaBankIntegration($db, 1);
	return $db->fetch_object($projection->query($projection->linesSql(101, 'ST-' . $id)));
}
function flowService()
{
	global $db, $conf, $user, $langs;
	$conf->entity = 1; $user = new WorklistFlowUser();
	return new KreaBankService($db, $user, $langs);
}

foreach (array('salary','payment_salary','accounting_bookkeeping-accounting') as $table) {
	$sql = preg_replace('/--[^\n]*/', '', file_get_contents(DOL_DOCUMENT_ROOT . '/install/mysql/tables/llx_' . $table . '.sql'));
	foreach (explode(';', str_replace('llx_', MAIN_DB_PREFIX, $sql)) as $statement) { if (trim($statement) !== '') { $db->query($statement); } }
}
$schema = preg_replace('/--[^\n]*/', '', file_get_contents(__DIR__ . '/../sql/llx_kreabank_payment_batch.sql'));
foreach (explode(';', str_replace('llx_', MAIN_DB_PREFIX, $schema)) as $sql) { if (trim($sql) !== '') { $db->query($sql); } }
// Use separate amounts and references so unrelated fixture candidates cannot win advice.
stageAuto(99100, '-137.23000001', 'SINGLE-FLOW-99100'); bankAuto(99200, '-137.23000001', 'SINGLE-FLOW-99100');
stageAuto(99101, '-75.00000003', 'GROUP-FLOW-99101'); bankAuto(99201, '-25.00000001', 'GROUP-A-99201'); bankAuto(99202, '-50.00000002', 'GROUP-B-99202');
stageAuto(99102, '-18.25', 'STALE-FLOW-99102'); bankAuto(99203, '-18.25', 'STALE-FLOW-99102');
$sourceIds = array(99100,99101,99102); $nativeIds = array(99200,99201,99202,99203);
$original = flowState($sourceIds, $nativeIds); $financialOriginal = flowFinancialEvidence();
$service = flowService();
$selection = $service->listWorklistSelectionCandidates('staged:99100');
checkAuto($selection['suggestion']['status'] === 'unique' && $selection['suggestion']['ids'] === array(99200), 'Scalar discovery preselects the sole exact same-day native movement');
$selected = runWorklistFlow(flowPost('review_batch', array('source_key'=>'staged:99100','batch_members'=>array('99200'))), flowSession());
list($nonce, $envelope) = flowEnvelope($selected, 'staged:99100');
checkAuto($selected['redirect']['expand'] === 'staged:99100' && $selected['redirect']['mode'] === 'batch' && $envelope['kind'] === 'pair', 'Real controller prepares and redirects to an ordinary scalar pair review');
checkAuto($envelope['payload']['selection'] && $envelope['payload']['pair']['approval_context'] === 'selected_native'
	&& kreabankWorklistSelectionReviewMatches($envelope, 'staged:99100', $envelope['payload']['pair']['line'], '1|7', time()), 'Stored scalar review rehydrates for its exact source/account');
checkAuto(flowState($sourceIds, $nativeIds) === $original && countAuto('kreabank_payment_batch_draft') === 0, 'Scalar review creates no native, group, draft, source or audit changes');
$confirm = flowPost('confirm_pair', array('review'=>$nonce, 'batch_members'=>array('99999'), 'source_key'=>'staged:99999'));
$confirmed = runWorklistFlow($confirm, $selected['session']);
checkAuto($confirmed['events'] === array(array('KreaBankWorklistReconciled','mesgs')) && !isset($confirmed['redirect']['expand']), 'Scalar confirmation completes through the actual list POST branch');
$afterSingle = flowState($sourceIds, $nativeIds);
checkAuto((int) $afterSingle['sources'][0]['status'] === 2 && (int) $afterSingle['sources'][0]['fk_native_bank_line'] === 99200
	&& (int) $afterSingle['native'][0]['rappro'] === 1 && $afterSingle['native'][0]['num_releve'] === 'ST-99100', 'Scalar controller persists the exact native/staging mapping and statement reference');
checkAuto($afterSingle['bank_count'] === $original['bank_count'] && $afterSingle['groups'] === $original['groups']
	&& $afterSingle['audits'] === $original['audits'] + 1, 'Scalar confirmation creates one audit and neither a native movement nor one-member group');
checkAuto(!isset($confirmed['session']['kreabank_worklist_batch_review']['staged:99100'], $confirmed['session']['kreabank_worklist_inputs']['staged:99100']), 'Completed scalar controller clears its own planning state');
$replayed = runWorklistFlow($confirm, $confirmed['session']);
checkAuto(flowState($sourceIds, $nativeIds) === $afterSingle && $replayed['events'][0][1] === 'errors', 'Replayed scalar nonce is rejected without another native or audit mutation');
checkAuto(flowFinancialEvidence() === $financialOriginal, 'Scalar controller leaves native financial fields, invoice/payment allocations and learning evidence unchanged');
$scalarProjection = flowProjection(99100);
checkAuto(kreabankIntegrationLineState($scalarProjection, 'ST-99100') === 'KreaBankReconciled' && (int) $scalarProjection->native_id === 99200, 'Statement projection verifies the scalar controller result');
$history = new KreaBankHistory($db, 1); $singleHistory = $history->find(99100, 'staged', 101);
checkAuto($singleHistory && !$singleHistory['needs_review'] && empty($singleHistory['group_id']) && (int) $singleHistory['native_bank_line_id'] === 99200, 'History exposes the same verified scalar mapping for reviewed undo');
$service = flowService(); $singleUndo = KreaBankHistory::snapshot($singleHistory);
checkAuto($service->undoReconciliation(99100, 'Disposable controller flow verification', $singleUndo), 'Scalar History snapshot undoes through the shared native lifecycle');
$undoneSingle = flowState($sourceIds, $nativeIds);
checkAuto((int) $undoneSingle['sources'][0]['status'] === 0 && empty($undoneSingle['sources'][0]['fk_native_bank_line'])
	&& (int) $undoneSingle['native'][0]['rappro'] === 0 && $undoneSingle['bank_count'] === $original['bank_count'], 'Scalar undo returns the source and original movement to pending without deleting financial evidence');
checkAuto(kreabankIntegrationLineState(flowProjection(99100), 'ST-99100') === 'KreaBankPending' && !(new KreaBankHistory($db,1))->find(99100, 'staged', 101), 'Scalar undo updates statement and current History projections consistently');
rejectAuto(static function () use ($service, $singleUndo) { $service->undoReconciliation(99100, 'Replay', $singleUndo); }, 'Scalar undo snapshot cannot be replayed');

$service = flowService(); $groupChoice = $service->listWorklistSelectionCandidates('staged:99101');
checkAuto($groupChoice['suggestion']['status'] === 'unique' && $groupChoice['suggestion']['ids'] === array(99201,99202), 'Group discovery preselects the unique exact multi-movement choice');
$groupPost = flowPost('review_batch', array('source_key'=>'staged:99101','batch_members'=>array('99201','99202'),'batch_name'=>'Disposable reviewed group'));
$group = runWorklistFlow($groupPost, flowSession()); list($groupNonce, $groupEnvelope) = flowEnvelope($group, 'staged:99101');
checkAuto($groupEnvelope['kind'] === 'batch' && $groupEnvelope['payload']['batch']['ids'] === array(99201,99202)
	&& countAuto('kreabank_payment_batch_draft') === 1 && countAuto('kreabank_payment_batch') === 0, 'Group review stores a private draft and signed backend group without executing reconciliation');
$groupSource = $groupEnvelope['payload']['batch']['source'];
checkAuto(kreabankWorklistSelectionReviewMatches($groupEnvelope, 'staged:99101', $groupSource, '1|7', time())
	&& !kreabankWorklistSelectionReviewMatches($groupEnvelope, 'staged:99100', $groupSource, '1|7', time()), 'Group review restores only beneath its exact displayed staging source');
$groupConfirmPost = flowPost('confirm_batch', array('review'=>$groupNonce, 'batch_members'=>array('99200')));
$groupConfirmed = runWorklistFlow($groupConfirmPost, $group['session']);
checkAuto($groupConfirmed['events'] === array(array('KreaBankWorklistReconciled','mesgs')), 'Actual group confirmation executes the signed original components, ignoring replacement POST IDs');
$afterGroup = flowState($sourceIds, $nativeIds);
checkAuto((int) $afterGroup['sources'][1]['status'] === 2 && empty($afterGroup['sources'][1]['fk_native_bank_line'])
	&& $afterGroup['sources'][1]['allocated_amount'] === '75.00000003' && $afterGroup['groups'] === 1, 'Group completion stores exact allocation without a misleading scalar mapping');
checkAuto((int) $afterGroup['native'][1]['rappro'] === 1 && (int) $afterGroup['native'][2]['rappro'] === 1
	&& $afterGroup['native'][1]['num_releve'] === 'ST-99101' && $afterGroup['native'][2]['num_releve'] === 'ST-99101'
	&& $afterGroup['bank_count'] === $original['bank_count'], 'All group components retain their native identities and exact statement reference without an aggregate movement');
checkAuto(countAuto('kreabank_payment_batch_draft') === 0
	&& (int) $db->query('SELECT COUNT(*) n FROM kbauto_kreabank_payment_batch_line WHERE active_bank_line IS NOT NULL')->fetch_object()->n === 2, 'Group completion removes its private draft and claims both members');
$replayedGroup = runWorklistFlow($groupConfirmPost, $groupConfirmed['session']);
checkAuto(flowState($sourceIds, $nativeIds) === $afterGroup && $replayedGroup['events'][0][1] === 'errors', 'Replayed group confirmation is rejected without duplicate history or financial writes');
checkAuto(flowFinancialEvidence() === $financialOriginal, 'Group controller leaves all original financial fields, allocations, payroll and learning evidence unchanged');
$groupProjection = flowProjection(99101); $groupHistory = (new KreaBankHistory($db,1))->find(99101, 'staged', 101);
checkAuto(kreabankIntegrationLineState($groupProjection,'ST-99101') === 'KreaBankReconciled'
	&& (int) $groupProjection->group_count === 2 && $groupHistory && !$groupHistory['needs_review'] && (int) $groupHistory['group_id'] > 0, 'Statement and History projections verify the complete two-member group');
$service = flowService(); $groupUndo = $service->preparePaymentBatchUndoReview(99101);
$undoSession = $_SESSION; $undoSession['kreabank_worklist_reviews']['group-undo'] = array('kind'=>'undo','scope'=>'1|7','expires'=>time()+900,'payload'=>array('key'=>'staged:99101','batch'=>$groupUndo));
$undone = runWorklistFlow(flowPost('undo_batch',array('review'=>'group-undo')), $undoSession);
checkAuto($undone['events'] === array(array('KreaBankWorklistUndoDone','mesgs')), 'Actual list group-undo POST consumes the reviewed original group');
$afterUndo = flowState($sourceIds, $nativeIds);
checkAuto((int) $afterUndo['sources'][1]['status'] === 0 && (int) $afterUndo['native'][1]['rappro'] === 0 && (int) $afterUndo['native'][2]['rappro'] === 0
	&& (int) $db->query('SELECT COUNT(*) n FROM kbauto_kreabank_payment_batch_line WHERE active_bank_line IS NOT NULL')->fetch_object()->n === 0, 'Group undo returns every source/member to pending and releases all active claims');
checkAuto($afterUndo['groups'] === 1 && $afterUndo['bank_count'] === $original['bank_count']
	&& kreabankIntegrationLineState(flowProjection(99101),'ST-99101') === 'KreaBankPending', 'Undo preserves group history and native financial evidence while updating the statement projection');
$undoReplay = runWorklistFlow(flowPost('undo_batch',array('review'=>'group-undo')), $undone['session']);
checkAuto(flowState($sourceIds,$nativeIds) === $afterUndo && $undoReplay['events'][0][1] === 'errors', 'Group undo envelope is single use');

// A displayed review is not authority over later source/target changes or another actor.
$stalePost = flowPost('review_batch',array('source_key'=>'staged:99102','batch_members'=>array('99203')));
$stale = runWorklistFlow($stalePost, flowSession()); list($staleNonce) = flowEnvelope($stale, 'staged:99102');
$db->query("UPDATE kbauto_bank SET dateo='2026-11-02' WHERE rowid=99203"); $changedState = flowState($sourceIds, $nativeIds);
$staleConfirmed = runWorklistFlow(flowPost('confirm_pair',array('review'=>$staleNonce)), $stale['session']);
checkAuto(flowState($sourceIds, $nativeIds) === $changedState && $staleConfirmed['events'][0][1] === 'errors', 'Real controller rejects a movement whose booking day changed after review');
$db->query("UPDATE kbauto_bank SET dateo='2026-11-01' WHERE rowid=99203");
foreach (array(array('entity'=>2),array('user_id'=>8),array('denied'=>array('kreabank/reconciliation/write'))) as $actor) {
	$review = runWorklistFlow($stalePost, flowSession()); list($actorNonce) = flowEnvelope($review, 'staged:99102');
	$before = flowState($sourceIds, $nativeIds);
	$attempt = runWorklistFlow(flowPost('confirm_pair',array('review'=>$actorNonce)), $review['session'], $actor);
	checkAuto(flowState($sourceIds, $nativeIds) === $before && ($attempt['forbidden'] || ($attempt['events'][0][1] ?? '') === 'errors'), 'Scalar approval cannot cross the reviewed company/user or missing write authority');
}
$before = flowState($sourceIds, $nativeIds);
$badToken = runWorklistFlow(array_merge($groupPost,array('token'=>'wrong')), flowSession());
checkAuto($badToken['forbidden'] && flowState($sourceIds, $nativeIds) === $before, 'Missing CSRF authority is rejected before even persisting a group draft');
checkAuto(flowFinancialEvidence() === $financialOriginal, 'Confirmation, undo and rejected retries preserve every original financial record and allocation');
checkAuto($db->depth === 0, 'Every integrated controller success/rejection ends with no open transaction');
echo "OK: $checks integrated scalar/group controller database flow checks\n";
