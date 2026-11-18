<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
$res = @include __DIR__ . '/../../../main.inc.php';
if (!$res) { $res = @include __DIR__ . '/../../../../main.inc.php'; }
if (!$res) { die('Dolibarr bootstrap unavailable'); }
require_once __DIR__ . '/../lib/kreabank.lib.php';
require_once __DIR__ . '/../class/KreaBankUberSettings.class.php';
$langs->loadLangs(array('admin', 'banks', 'kreabank@kreabank'));
if (empty($user->admin) || !empty($user->socid) || !isModEnabled('kreabank') || !isModEnabled('banque')
	|| !$user->hasRight('banque', 'modifier') || !$user->hasRight('banque', 'transfer')
	|| !$user->hasRight('kreabank', 'reconciliation', 'write')) { accessforbidden(); }
$policy = new KreaBankUberSettings($db, (int) $conf->entity);
$action = GETPOST('action', 'aZ09');
if ($action !== '') {
	if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !kreabankIsTokenValid()) { accessforbidden(); }
	try {
		$map = $policy->mappings(); $store = GETPOSTINT('store_id');
		if ($store <= 0) { throw new DomainException('Select a restaurant'); }
		if ($action === 'save' && GETPOSTINT('clearing_account') > 0) { $map[$store] = GETPOSTINT('clearing_account'); }
		elseif ($action === 'remove') { unset($map[$store]); }
		else { throw new DomainException('Select an Uber clearing account'); }
		$policy->configure($user, $map, GETPOST('revision', 'aZ09'));
		setEventMessages($langs->trans('SetupSaved'), null, 'mesgs');
		header('Location: ' . dol_buildpath('/custom/kreabank/admin/uber.php', 1)); exit;
	} catch (Throwable $error) { setEventMessages($langs->trans('KreaBankUberConfigError'), null, 'errors'); }
}
llxHeader('', $langs->trans('KreaBankUberSettlements'));
print load_fiche_titre($langs->trans('KreaBankUberSettlements'), '', 'bank');
print dol_get_fiche_head(kreabankAdminPrepareHead(), 'uber', $langs->trans('KreaBankSetup'), -1, 'bank');
print '<p>' . dol_escape_htmltag($langs->trans('KreaBankUberSetupHelp')) . '</p>';
try {
	$stores = $policy->stores(); $map = $policy->mappings(); $revision = $policy->revision(); $accounts = array();
	foreach ($policy->accounts() as $account) { $accounts[(int) $account->rowid] = $account; }
	$label = static function ($id) use ($accounts, $langs) {
		return isset($accounts[$id]) ? $accounts[$id]->ref . ' — ' . $accounts[$id]->label . ' (' . $accounts[$id]->currency_code . ')' : $langs->trans('KreaBankPairUnavailable');
	};
	print '<div class="div-table-responsive"><table class="liste centpercent"><thead><tr class="liste_titre">';
	foreach (array('KreaBankUberRestaurant', 'KreaBankUberReceivingAccount', 'KreaBankUberClearingAccount', 'KreaBankActions') as $key) { print '<th scope="col">' . $langs->trans($key) . '</th>'; }
	print '</tr></thead><tbody>';
	foreach ($stores as $store) {
		$id = (int) $store['rowid'];
		print '<tr class="oddeven"><td>' . dol_escape_htmltag($store['label']) . '</td><td>' . dol_escape_htmltag($label((int) $store['fk_account'])) . '</td><td>' . (isset($map[$id]) ? dol_escape_htmltag($label((int) $map[$id])) : $langs->trans('KreaBankUberNotConfigured')) . '</td><td>';
		if (isset($map[$id])) {
			print '<form method="post"><input type="hidden" name="token" value="' . newToken() . '"><input type="hidden" name="action" value="remove"><input type="hidden" name="store_id" value="' . $id . '"><input type="hidden" name="revision" value="' . dol_escape_htmltag($revision) . '"><button class="button small" type="submit">' . $langs->trans('Remove') . '</button></form>';
		}
		print '</td></tr>';
	}
	if (!$stores) { print '<tr><td colspan="4">' . $langs->trans('KreaBankUberNoStores') . '</td></tr>'; }
	print '</tbody></table></div>';
	if ($stores) {
		print '<form method="post"><input type="hidden" name="token" value="' . newToken() . '"><input type="hidden" name="action" value="save"><input type="hidden" name="revision" value="' . dol_escape_htmltag($revision) . '">';
		print '<p><label for="uber-store">' . $langs->trans('KreaBankUberRestaurant') . '</label><br><select id="uber-store" name="store_id" class="flat maxwidth500" required><option value="">—</option>';
		foreach ($stores as $store) { print '<option value="' . (int) $store['rowid'] . '">' . dol_escape_htmltag($store['label']) . '</option>'; }
		print '</select></p><p><label for="uber-clearing">' . $langs->trans('KreaBankUberClearingAccount') . '</label><br><select id="uber-clearing" name="clearing_account" class="flat maxwidth500" required><option value="">—</option>';
		foreach ($accounts as $id=>$account) {
			if ((int) $account->clos !== 0 || (string) $account->currency_code !== 'EUR') { continue; }
			print '<option value="' . $id . '">' . dol_escape_htmltag($label($id)) . '</option>';
		}
		print '</select></p><p class="opacitymedium">' . $langs->trans('KreaBankUberMappingHelp') . '</p><button class="button button-save" type="submit">' . $langs->trans('Save') . '</button></form>';
	}
} catch (Throwable $error) { print '<p class="error">' . $langs->trans('KreaBankUberUnavailable') . '</p>'; }
print dol_get_fiche_end();
llxFooter();
$db->close();
