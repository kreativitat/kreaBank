<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
$res = @include __DIR__ . '/../../../main.inc.php';
if (!$res) { $res = @include __DIR__ . '/../../../../main.inc.php'; }
if (!$res) { die('Dolibarr bootstrap unavailable'); }
require_once __DIR__ . '/../lib/kreabank.lib.php';
require_once __DIR__ . '/../class/KreaBankAccountPairs.class.php';
$langs->loadLangs(array('admin', 'banks', 'kreabank@kreabank'));
if (empty($user->admin) || !empty($user->socid) || !isModEnabled('kreabank') || !isModEnabled('banque')
	|| !$user->hasRight('banque', 'modifier') || !$user->hasRight('kreabank', 'reconciliation', 'write')) { accessforbidden(); }
$policy = new KreaBankAccountPairs($db, $conf->entity);
$action = GETPOST('action', 'aZ09');
if ($action !== '') {
	if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !kreabankIsTokenValid()) { accessforbidden(); }
	try {
		$map = $policy->pairs();
		if ($action === 'save') {
			if (GETPOSTINT('restricted_account') <= 0 || GETPOSTINT('main_account') <= 0) { throw new Exception('Select both accounts'); }
			$map[GETPOSTINT('restricted_account')] = GETPOSTINT('main_account');
		} elseif ($action === 'remove') {
			unset($map[GETPOSTINT('restricted_account')]);
		} else { throw new Exception('Invalid account pair action'); }
		$policy->configure($user, $map, GETPOST('revision', 'alphanohtml'));
		setEventMessages($langs->trans('SetupSaved'), null, 'mesgs');
		header('Location: ' . dol_buildpath('/custom/kreabank/admin/pairs.php', 1));
		exit;
	} catch (Throwable $e) {
		setEventMessages($langs->trans('KreaBankPairConfigError'), null, 'errors');
	}
}
llxHeader('', $langs->trans('KreaBankAccountPairs'));
print kreabankListAssets();
print load_fiche_titre($langs->trans('KreaBankAccountPairs'), '', 'bank');
print dol_get_fiche_head(kreabankAdminPrepareHead(), 'pairs', $langs->trans('KreaBankSetup'), -1, 'bank');
print '<p>' . $langs->trans('KreaBankPairPurpose') . '</p>';
try {
	$map = $policy->pairs();
	$revision = hash('sha256', json_encode((object) $map));
	$accounts = array();
	foreach ($policy->accounts() as $account) { $accounts[(int) $account->rowid] = $account; }
	$accountLabel = static function ($id) use ($accounts, $langs) {
		if (!isset($accounts[$id])) { return $langs->trans('KreaBankPairUnavailable'); }
		return $accounts[$id]->ref . ' — ' . $accounts[$id]->label . ' (' . $accounts[$id]->currency_code . ')';
	};
	print '<div class="div-table-responsive"><table data-kb-sort data-kb-sort-skip="2" class="liste centpercent"><tr class="liste_titre">';
	foreach (array('KreaBankPairRestricted', 'KreaBankPairMain', 'KreaBankActions') as $key) { print '<th scope="col">' . $langs->trans($key) . '</th>'; }
	print '</tr>';
	foreach ($map as $restricted => $main) {
		print '<tr class="oddeven"><td>' . dol_escape_htmltag($accountLabel($restricted)) . '</td><td>' . dol_escape_htmltag($accountLabel($main)) . '</td><td>';
		print '<form method="post"><input type="hidden" name="token" value="' . newToken() . '"><input type="hidden" name="revision" value="' . $revision . '"><input type="hidden" name="action" value="remove">';
		print '<input type="hidden" name="restricted_account" value="' . (int) $restricted . '"><button class="button small" type="submit">' . $langs->trans('Remove') . '</button></form></td></tr>';
	}
	if (!$map) { print '<tr><td colspan="3">' . $langs->trans('KreaBankPairEmpty') . '</td></tr>'; }
	print '</table></div><form method="post" class="marginbottomonly">';
	print '<input type="hidden" name="token" value="' . newToken() . '"><input type="hidden" name="revision" value="' . $revision . '"><input type="hidden" name="action" value="save">';
	foreach (array('restricted_account' => 'KreaBankPairRestricted', 'main_account' => 'KreaBankPairMain') as $name => $key) {
		print '<p><label for="' . $name . '">' . $langs->trans($key) . '</label><br><select id="' . $name . '" name="' . $name . '" class="flat" required style="max-width:100%"><option value="">—</option>';
		foreach ($accounts as $id => $account) {
			if ((int) $account->clos !== 0 || (int) $account->rappro !== 1 || (int) $account->courant === 2) { continue; }
			print '<option value="' . $id . '">' . dol_escape_htmltag($accountLabel($id)) . '</option>';
		}
		print '</select></p>';
	}
	print '<button type="submit" class="button button-save">' . $langs->trans('Save') . '</button></form>';
	print '<p>' . $langs->trans('KreaBankPairAutomaticHelp') . ' ' . $langs->trans('KreaBankPairCompanyCurrency') . ' <a href="' . dol_buildpath('/custom/kreabank/admin/automation.php', 1) . '">' . $langs->trans('KreaBankAutomation') . '</a></p>';
} catch (Throwable $e) { print '<p class="error">' . $langs->trans('KreaBankPairConfigError') . '</p>'; }
print dol_get_fiche_end();
llxFooter();
$db->close();
