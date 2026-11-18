<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
$res = @include __DIR__ . '/../../../main.inc.php';
if (!$res) { $res = @include __DIR__ . '/../../../../main.inc.php'; }
if (!$res) { die('Dolibarr bootstrap unavailable'); }
require_once __DIR__ . '/../lib/kreabank.lib.php';
require_once __DIR__ . '/../lib/kreabank_opening_view.lib.php';
require_once __DIR__ . '/../class/KreaBankOpeningBalance.class.php';
$langs->loadLangs(array('main', 'admin', 'banks', 'kreabank@kreabank'));
if (empty($user->admin) || !empty($user->socid) || !isModEnabled('kreabank') || !isModEnabled('banque')
	|| !$user->hasRight('banque', 'modifier') || !$user->hasRight('banque', 'consolidate')
	|| !$user->hasRight('kreabank', 'reconciliation', 'write')) { accessforbidden(); }
$engine = new KreaBankOpeningBalance($db, $user, $conf->entity);
$action = GETPOST('action', 'aZ09');
$url = dol_buildpath('/custom/kreabank/admin/opening.php', 1);
$values = array('account_id'=>GETPOSTINT('account_id'));
foreach (array('start_date','opening_balance','statement_ref','evidence_ref') as $name) {
	$value = GETPOST($name, 'alphanohtml');
	$values[$name] = is_scalar($value) ? (string) $value : '';
}
$result = array();
if ($action !== '') {
	if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !kreabankIsTokenValid()) { accessforbidden(); }
	try {
		$input = $values + array('review_nonce'=>GETPOST('review_nonce','aZ09'), 'confirmed'=>GETPOST('confirmed','aZ09'));
		$result = kreabankOpeningHandleAction($engine, $_SESSION, $action, $input, (int) $user->id, (int) $conf->entity, dol_now());
		if (!empty($result['message'])) { setEventMessages($langs->trans($result['message']), null, 'mesgs'); }
		if (!empty($result['redirect'])) { header('Location: ' . $url); exit; }
	} catch (Throwable $error) {
		$known = array('KreaBankOpeningInvalidDate','KreaBankOpeningInvalidAmount','KreaBankOpeningInvalidReference','KreaBankOpeningInvalidEvidence',
			'KreaBankOpeningAlreadyApplied','KreaBankOpeningReviewChanged','KreaBankOpeningCrossingLinks','KreaBankOpeningPermissions',
			'KreaBankOpeningSchemaRequired','KreaBankOpeningTooManyRows','KreaBankOpeningInvalidCurrency','KreaBankOpeningNativeWriteFailed',
			'KreaBankOpeningPrecisionMismatch','KreaBankOpeningDatabaseError');
		$key = in_array($error->getMessage(), $known, true) ? $error->getMessage() : 'KreaBankOpeningError';
		setEventMessages($langs->trans($key), null, 'errors');
	}
}
llxHeader('', $langs->trans('KreaBankOpeningTitle'));
print load_fiche_titre($langs->trans('KreaBankOpeningTitle'), '', 'title_setup');
print dol_get_fiche_head(kreabankAdminPrepareHead(), 'opening', $langs->trans('KreaBankSetup'), -1, 'kreabank@kreabank');
try {
	$available = $engine->available();
	$openings = $available ? $engine->all() : array();
	$applied = array();
	foreach ($openings as $opening) { $applied[(int) $opening['fk_bank_account']] = true; }
	$accounts = array();
	$query = $db->query('SELECT rowid,ref,label,currency_code FROM ' . $db->prefix() . 'bank_account WHERE entity=' . (int) $conf->entity
		. ' AND clos=0 AND rappro=1 AND courant<>2 ORDER BY ref,label,rowid');
	if (!$query) { throw new RuntimeException('Unable to load owned bank accounts'); }
	while ($account = $db->fetch_object($query)) { if (!isset($applied[(int) $account->rowid])) { $accounts[] = (array) $account; } }
	print kreabankOpeningPageHtml(array('available'=>$available, 'openings'=>$openings, 'accounts'=>$accounts, 'values'=>$values,
		'review'=>$result['review'] ?? null, 'review_nonce'=>$result['review_nonce'] ?? '', 'url'=>$url, 'token'=>newToken(),
		'max_date'=>dol_print_date(dol_now(),'%Y-%m-%d')), $langs);
} catch (Throwable $error) { print '<p class="error">' . $langs->trans('KreaBankOpeningError') . '</p>'; }
print dol_get_fiche_end();
llxFooter();
$db->close();
