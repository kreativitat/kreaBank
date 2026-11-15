#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Installed native GETPOST + actual History nonce-consumption statement.
 * No ERP bootstrap, credentials, database connection or financial execution.
 */
$nativeRoot = getenv('KREABANK_DOLIBARR_ROOT') ?: dirname(__DIR__, 3);
if (!is_file($nativeRoot . '/core/lib/functions.lib.php')) {
	fwrite(STDERR, "Set KREABANK_DOLIBARR_ROOT to installed Dolibarr source.\n");
	exit(2);
}
define('DOL_DOCUMENT_ROOT', $nativeRoot);
define('DOL_URL_ROOT', '');
require_once DOL_DOCUMENT_ROOT . '/core/lib/functions.lib.php';
require_once __DIR__ . '/../lib/kreabank_history.lib.php';
$conf = (object) array('entity'=>7, 'global'=>new stdClass());
$user = (object) array('id'=>9);
$_SERVER['PHP_SELF'] = '/custom/kreabank/history.php';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_GET = $_POST = $_SESSION = array();
$checks = 0;
function checkHistoryUndoNative($condition, $label)
{
	global $checks;
	if (!$condition) { throw new RuntimeException($label); }
	$checks++;
}
function rejectHistoryUndoNative($call, $label)
{
	$rejected = false;
	try { $call(); } catch (DomainException $error) { $rejected = true; }
	checkHistoryUndoNative($rejected, $label);
}
$controller = file_get_contents(__DIR__ . '/../history.php');
$matched = preg_match('/\$review\s*=\s*(kreabankHistoryTakeUndoReview\(GETPOST\(\x27undo_review\x27,\s*\x27([^\x27]+)\x27\),\s*\$navigationAccountId\));/', $controller, $match);
if ($matched !== 1) { throw new LogicException('History undo request boundary changed'); }
$controllerCall = $match[1];
$consume = static function ($call, $account = 101) {
	$navigationAccountId = $account;
	return eval('return ' . $call . ';');
};
$row = array('rowid'=>701, 'line_source'=>'staged', 'bank_account_id'=>101, 'native_bank_line_id'=>801,
	'amount'=>'-42.25000000', 'native_amount'=>'-42.25000000', 'currency'=>'EUR', 'status'=>2,
	'rappro'=>1, 'statement_ref'=>'SYNTHETIC-UNDO', 'num_releve'=>'SYNTHETIC-UNDO',
	'operation_date'=>'2031-04-10', 'description'=>'Synthetic reconciliation', 'group_id'=>null, 'needs_review'=>false);
$expected = KreaBankHistory::snapshot($row);
$newReview = static function () use ($row) {
	global $conf, $user;
	$conf->entity = 7; $user->id = 9; $_GET = $_POST = $_SESSION = array();
	return kreabankHistoryUndoReview($row);
};

$nonce = $newReview();
checkHistoryUndoNative((bool) preg_match('/^[a-f0-9]{48}$/D', $nonce), 'The real displayed undo helper generates an opaque 48-character nonce');
$_POST['undo_review'] = $nonce;
// Reproduce the former native filter bug without replacing or mocking GETPOST.
$oldCall = str_replace("GETPOST('undo_review', '" . $match[2] . "')", "GETPOST('undo_review', 'alphanum')", $controllerCall);
checkHistoryUndoNative(GETPOST('undo_review', 'alphanum') === '', 'The unsupported old native filter loses a legitimate undo nonce');
rejectHistoryUndoNative(static function () use ($consume, $oldCall) { $consume($oldCall); }, 'The former controller filter rejects a legitimate displayed undo review');
checkHistoryUndoNative(isset($_SESSION['kreabank_history_undo'][$nonce]), 'The old-filter failure does not consume the actual displayed review');
checkHistoryUndoNative($consume($controllerCall) === $expected, 'The actual controller filter carries the nonce into native History consumption');
checkHistoryUndoNative(!isset($_SESSION['kreabank_history_undo'][$nonce]), 'Successful native-filter consumption removes its nonce exactly once');
rejectHistoryUndoNative(static function () use ($consume, $controllerCall) { $consume($controllerCall); }, 'Replayed native History POST cannot reuse the review');

$nonce = $newReview(); $_POST['undo_review'] = ' ' . $nonce . ' ';
checkHistoryUndoNative($consume($controllerCall) === $expected, 'Native scalar sanitization preserves a valid nonce after whitespace normalization');
foreach (array('', 'not-a-review', str_repeat('a',47), str_repeat('a',49), str_repeat('b',48), '<script>unsafe</script>', array('nonce'), array('nested'=>array('nonce'))) as $malformed) {
	$nonce = $newReview(); $_POST['undo_review'] = $malformed;
	rejectHistoryUndoNative(static function () use ($consume, $controllerCall) { $consume($controllerCall); }, 'Malformed scalar/array undo input is cleanly rejected before financial execution');
	checkHistoryUndoNative(isset($_SESSION['kreabank_history_undo'][$nonce]), 'Malformed input cannot consume an unrelated legitimate review');
}
$nonce = $newReview(); $_POST['undo_review'] = $nonce; $_SESSION['kreabank_history_undo'][$nonce]['expires'] = time() - 1;
rejectHistoryUndoNative(static function () use ($consume, $controllerCall) { $consume($controllerCall); }, 'Expired native History review is rejected');
checkHistoryUndoNative(!isset($_SESSION['kreabank_history_undo'][$nonce]), 'Expired review cannot be retried after the refusal');
foreach (array('entity','user','account') as $scope) {
	$nonce = $newReview(); $_POST['undo_review'] = $nonce;
	if ($scope === 'entity') { $conf->entity = 8; }
	if ($scope === 'user') { $user->id = 10; }
	$account = $scope === 'account' ? 102 : 101;
	rejectHistoryUndoNative(static function () use ($consume, $controllerCall, $account) { $consume($controllerCall, $account); }, 'Native History review is bound to its displayed company/user/account: ' . $scope);
	checkHistoryUndoNative(!isset($_SESSION['kreabank_history_undo'][$nonce]), 'Wrong-scope review is consumed and cannot authorize a later retry');
}
$nonce = $newReview(); $_POST['undo_review'] = $nonce;
checkHistoryUndoNative($consume($controllerCall, 0) === $expected, 'The all-account History view retains the signed source account rather than inventing one');
echo "OK: $checks native History undo request checks (installed GETPOST, no database)\n";
