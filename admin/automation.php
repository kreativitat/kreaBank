<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
$res = @include __DIR__ . '/../../../main.inc.php';
if (!$res) {
	$res = @include __DIR__ . '/../../../../main.inc.php';
}
if (!$res) {
	die('Dolibarr bootstrap unavailable');
}
require_once __DIR__ . '/../lib/kreabank.lib.php';
require_once __DIR__ . '/../class/KreaBankAutoReconcile.class.php';
$langs->loadLangs(array('admin', 'banks', 'kreabank@kreabank'));
if (empty($user->admin) || !empty($user->socid) || !isModEnabled('kreabank') || !isModEnabled('banque')
	|| !$user->hasRight('banque', 'modifier') || !$user->hasRight('kreabank', 'reconciliation', 'write')) {
	accessforbidden();
}
$automation = new KreaBankAutoReconcile($db);
$action = GETPOST('action', 'aZ09');
$preview = null;
if ($action !== '') {
	if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !kreabankIsTokenValid()) {
		accessforbidden();
	}
	try {
		if ($action === 'save') {
			$automation->configure($user, GETPOST('mode', 'aZ09'), GETPOST('accounts', 'array'));
			setEventMessages($langs->trans('SetupSaved'), null, 'mesgs');
			header('Location: ' . dol_buildpath('/custom/kreabank/admin/automation.php', 1));
			exit;
		} elseif ($action === 'preview') {
			$preview = $automation->process($user, true);
		}
	} catch (Throwable $e) {
		dol_syslog('KreaBank automatic reconciliation setup action failed for company ' . (int) $conf->entity, LOG_ERR);
		setEventMessages($langs->trans('KreaBankAutoError'), null, 'errors');
	}
}
llxHeader('', $langs->trans('KreaBankAutomation'));
print load_fiche_titre($langs->trans('KreaBankAutomation'), '', 'bank');
print dol_get_fiche_head(kreabankAdminPrepareHead(), 'automation', $langs->trans('KreaBankSetup'), -1, 'bank');
print '<p>' . $langs->trans('KreaBankAutoPurpose') . '</p>';
print '<p class="opacitymedium">' . $langs->trans('KreaBankAutoScheduleHelp') . '</p>';
try {
	$mode = $automation->setting('MODE', 'off');
	$selected = array_map('intval', explode(',', $automation->setting('ACCOUNTS')));
	$accounts = $automation->accounts();
	print '<form method="post" action="' . dol_escape_htmltag($_SERVER['PHP_SELF']) . '">';
	print '<input type="hidden" name="token" value="' . newToken() . '"><input type="hidden" name="action" value="save">';
	print '<p><label for="mode">' . $langs->trans('KreaBankAutoMode') . '</label><br>';
	print '<select id="mode" name="mode" class="flat" style="max-width:100%">';
	foreach (array('off' => 'KreaBankAutoOff', 'preview' => 'KreaBankAutoPreviewMode', 'enabled' => 'KreaBankAutoEnabled') as $value => $label) {
		print '<option value="' . $value . '"' . ($mode === $value ? ' selected' : '') . '>' . $langs->trans($label) . '</option>';
	}
	print '</select></p><fieldset class="border"><legend>' . $langs->trans('KreaBankAutoAccounts') . '</legend>';
	if (!$accounts) {
		print '<p>' . $langs->trans('KreaBankAutoNoAccounts') . '</p>';
	}
	foreach ($accounts as $account) {
		$id = (int) $account->rowid;
		print '<div class="marginbottomonly"><input type="checkbox" name="accounts[]" value="' . $id . '" id="account_' . $id . '"'
			. (in_array($id, $selected, true) ? ' checked' : '') . '> <label for="account_' . $id . '">'
			. dol_escape_htmltag($account->ref . ' — ' . $account->label . ' (' . $account->currency_code . ')') . '</label></div>';
	}
	print '</fieldset><p>' . $langs->trans('KreaBankAutoSafetyHelp') . '</p>';
	print '<div class="center"><button type="submit" class="button button-save">' . $langs->trans('Save') . '</button></div></form>';
	print '<form method="post" class="center"><input type="hidden" name="token" value="' . newToken() . '"><input type="hidden" name="action" value="preview">';
	print '<p>' . $langs->trans('KreaBankAutoPreviewHelp') . '</p><button type="submit" class="button">' . $langs->trans('KreaBankAutoPreview') . '</button></form>';
	if ($preview !== null) {
		print '<h2>' . $langs->trans('KreaBankAutoPreview') . '</h2>';
		if ($preview['status'] === 'no_accounts') {
			print '<p>' . $langs->trans('KreaBankAutoSelectAccounts') . '</p>';
		} else {
			print '<p>' . $langs->trans('KreaBankAutoSummary', (int) $preview['scanned'], (int) $preview['eligible'], (int) $preview['failed']) . '</p>';
			print '<div class="div-table-responsive"><table class="liste centpercent"><tr class="liste_titre"><th scope="col">' . $langs->trans('KreaBankAutoStatementLine')
				. '</th><th scope="col">' . $langs->trans('KreaBankAutoBankMovement') . '</th><th scope="col">' . $langs->trans('KreaBankAutoScore') . '</th></tr>';
			foreach ($preview['matches'] as $match) {
				print '<tr class="oddeven"><td>' . (int) $match['statement_line_id'] . '</td><td><a href="'
					. dol_buildpath('/compta/bank/line.php?rowid=' . (int) $match['bank_line_id'], 1) . '">'
					. (int) $match['bank_line_id'] . '</a></td><td>' . (int) $match['score'] . '</td></tr>';
			}
			print '</table></div>';
			if (!$preview['matches']) {
				print '<p>' . $langs->trans('KreaBankNoSecureSuggestions') . '</p>';
			}
		}
	}
} catch (Throwable $e) {
	print '<p class="error">' . $langs->trans('KreaBankAutoError') . '</p>';
}
print dol_get_fiche_end();
llxFooter();
$db->close();
