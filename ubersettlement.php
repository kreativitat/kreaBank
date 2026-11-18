<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
$loaded = false;
foreach (array($_SERVER['CONTEXT_DOCUMENT_ROOT'] ?? '', __DIR__ . '/../..', __DIR__ . '/../../..') as $root) {
	if ($root !== '' && is_file($root . '/main.inc.php')) { $loaded = require $root . '/main.inc.php'; if ($loaded) { break; } }
}
if (!$loaded) { die('Include of main fails'); }
require_once __DIR__ . '/lib/kreabank.lib.php';
require_once __DIR__ . '/class/KreaBankService.class.php';
require_once __DIR__ . '/lib/kreabank_uber_view.lib.php';
require_once __DIR__ . '/lib/kreabank_worklist.lib.php';
$langs->loadLangs(array('banks', 'bills', 'kreabank@kreabank', 'worklist@kreabank'));
if (!isModEnabled('kreabank') || !isModEnabled('banque') || !isModEnabled('kreaeats') || !empty($user->socid)
	|| !$user->hasRight('banque', 'lire') || !$user->hasRight('banque', 'transfer')
	|| !$user->hasRight('kreabank', 'reconciliation', 'read') || !$user->hasRight('kreabank', 'reconciliation', 'write')
	|| !$user->hasRight('kreaeats', 'read') || !$user->hasRight('kreaeats', 'reconcile')) { accessforbidden(); }
$post = $_SERVER['REQUEST_METHOD'] === 'POST';
if ($post && !kreabankIsTokenValid()) { accessforbidden('Invalid token'); }
$action = GETPOST('action', 'aZ09');
if (($action !== '' && !$post) || ($post && $action !== 'confirm_uber')) { accessforbidden(); }
try { $returnState = kreabankWorklistState($_GET, 10); $returnParams = $returnState['params'] + array('page'=>$returnState['page']); }
catch (Throwable $error) { $returnParams = array(); }
$returnUrl = static function ($params) { return dol_buildpath('/custom/kreabank/reconciliationlist.php', 1) . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986); };
$scope = (int) $conf->entity . '|' . (int) $user->id;
foreach ((array) ($_SESSION['kreabank_uber_page_reviews'] ?? array()) as $key=>$saved) {
	if (($saved['scope'] ?? '') !== $scope || ($saved['review']['expires'] ?? 0) < time()) { unset($_SESSION['kreabank_uber_page_reviews'][$key]); }
}
$service = new KreaBankService($db, $user, $langs);
$review = null;
try {
	if ($post) {
		$key = GETPOST('review', 'aZ09'); $saved = $_SESSION['kreabank_uber_page_reviews'][$key] ?? null;
		unset($_SESSION['kreabank_uber_page_reviews'][$key]);
		if (!$saved || ($saved['scope'] ?? '') !== $scope || ($saved['review']['expires'] ?? 0) < time() || GETPOST('confirmed', 'aZ09') !== '1') {
			throw new DomainException('The Uber settlement review expired or was not confirmed');
		}
		$returnParams = $saved['return']; $review = $saved['review'];
		$nativeId = $service->reconcileUberSettlement($review);
		$effect = kreabankWorklistActionEffect($review['source_key'], array('line'=>$review['source']));
		$effect['tokens'][] = 'native_bank:' . (int) $nativeId;
		kreabankWorklistInvalidateScan($_SESSION, $effect, true);
		setEventMessages($langs->trans('KreaBankUberSettlementDone'), null, 'mesgs');
		header('Location: ' . $returnUrl($returnParams)); exit;
	}
	$review = $service->prepareUberSettlementReview(GETPOST('source_key', 'alphanohtml'), GETPOSTINT('settlement_id'));
	$_SESSION['kreabank_uber_page_reviews'][$review['nonce']] = array('scope'=>$scope, 'review'=>$review, 'return'=>$returnParams);
	if (count($_SESSION['kreabank_uber_page_reviews']) > 30) { $_SESSION['kreabank_uber_page_reviews'] = array_slice($_SESSION['kreabank_uber_page_reviews'], -30, null, true); }
} catch (Throwable $error) {
	dol_syslog('KreaBank Uber settlement review failed: ' . get_class($error), LOG_ERR);
	setEventMessages($langs->trans('KreaBankUberReviewUnavailable'), null, 'errors');
	$review = null;
}
llxHeader('', $langs->trans('KreaBankUberReview'));
print load_fiche_titre($langs->trans('KreaBankUberReview'), '', 'bank');
if ($review) { print kreabankUberReviewHtml($review, $returnUrl($returnParams), newToken(), $langs); }
else { print '<div class="center"><a class="button" href="' . dol_escape_htmltag($returnUrl($returnParams)) . '">' . $langs->trans('BackToList') . '</a></div>'; }
llxFooter();
$db->close();
