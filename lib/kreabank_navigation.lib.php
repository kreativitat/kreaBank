<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

require_once __DIR__ . '/kreabank_integration.lib.php';

/** Build a workspace URL without carrying actions, line IDs or pagination across pages. */
function kreabankWorkspaceUrl($page, $accountId = 0)
{
	$pages = array('reconcile', 'bulkmatch', 'pending', 'import', 'feed', 'history', 'statement');
	if (!in_array($page, $pages, true)) {
		throw new InvalidArgumentException('Unknown bank workspace page');
	}
	return dol_buildpath('/custom/kreabank/' . $page . '.php', 1)
		. '?mainmenu=bank&leftmenu=kreabank_' . $page
		. ((int) $accountId > 0 ? '&account_context=' . (int) $accountId : '');
}

/** Resolve only an account owned by the active company; never trust Account::entity. */
function kreabankNavigationAccount($accountId)
{
	global $db, $conf;
	if ((int) $accountId <= 0) {
		return null;
	}
	$sql = 'SELECT rowid FROM ' . $db->prefix() . 'bank_account WHERE rowid = ' . (int) $accountId
		. ' AND entity = ' . (int) $conf->entity;
	$result = $db->query($sql);
	if (!$result) {
		throw new RuntimeException('Unable to read bank account context');
	}
	if (!$db->fetch_object($result)) {
		accessforbidden();
	}
	require_once DOL_DOCUMENT_ROOT . '/compta/bank/class/account.class.php';
	$account = new Account($db);
	if ($account->fetch((int) $accountId) <= 0) {
		accessforbidden();
	}
	return $account;
}

/** Keep the native account tabs, with distinct names for the two reconciliation tasks. */
function kreabankCompleteAccountHead($head, $accountId)
{
	global $user, $langs;
	if (!$user->hasRight('banque', 'lire')) {
		return $head;
	}
	$langs->load('kreabank@kreabank');
	$hasWorkspace = false;
	foreach ($head as &$tab) {
		if (in_array($tab[2], array('reconcile', 'reconcileauto'), true)) {
			$tab[1] = $langs->trans('KreaBankStatementNumbering');
		}
		if ($tab[2] === 'kreabank') {
			$hasWorkspace = true;
		}
	}
	unset($tab);
	if (!$hasWorkspace && $user->hasRight('kreabank', 'reconciliation', 'read')) {
		array_splice($head, 2, 0, array(array(kreabankWorkspaceUrl('reconcile', $accountId), 'KreaBank', 'kreabank')));
	}
	return array_values($head);
}

/** Persist the account context through GET filters and POST actions. */
function kreabankNavigationHiddenFields($accountId)
{
	return (int) $accountId > 0 ? '<input type="hidden" name="account_context" value="' . (int) $accountId . '">' : '';
}

/** Render the shared account selector, native tabs, and the smaller KreaBank workspace. */
function kreabankPrintWorkspaceNavigation($page, $account = null)
{
	global $db, $conf, $langs, $user, $hookmanager;
	$accountId = $account ? (int) $account->id : 0;
	$langs->loadLangs(array('banks', 'kreabank@kreabank'));
	print '<div class="kreabank-context"><a href="' . DOL_URL_ROOT . '/compta/bank/list.php?mainmenu=bank&leftmenu=bank&search_status=opened">'
		. $langs->trans('KreaBankAccounts') . '</a>';
	print '<form method="get" action="' . dol_escape_htmltag(kreabankWorkspaceUrl($page)) . '">';
	print '<input type="hidden" name="mainmenu" value="bank"><input type="hidden" name="leftmenu" value="kreabank_' . $page . '">';
	print '<label for="kreabank-account-context">' . $langs->trans('BankAccount') . '</label> ';
	print '<select class="flat" id="kreabank-account-context" name="account_context"><option value="0">' . $langs->trans('KreaBankAllAccounts') . '</option>';
	$result = $db->query('SELECT rowid, ref, label FROM ' . $db->prefix() . 'bank_account WHERE entity = ' . (int) $conf->entity . ' ORDER BY clos, ref, label');
	if (!$result) {
		throw new RuntimeException('Unable to list bank accounts');
	}
	while ($row = $db->fetch_object($result)) {
		print '<option value="' . (int) $row->rowid . '"' . ((int) $row->rowid === $accountId ? ' selected' : '') . '>'
			. dol_escape_htmltag($row->ref . ' — ' . $row->label) . '</option>';
	}
	print '</select> <button type="submit" class="button small">' . $langs->trans('Show') . '</button></form></div>';
	if ($account) {
		require_once DOL_DOCUMENT_ROOT . '/core/lib/bank.lib.php';
		$hookmanager->initHooks(array('kreabankworkspace'));
		$head = kreabankCompleteAccountHead(bank_prepare_head($account), $accountId);
		print dol_get_fiche_head($head, 'kreabank', $langs->trans('BankAccount'), 1, 'bank');
	}
	$active = $page === 'statement' ? 'import' : (in_array($page, array('bulkmatch', 'pending'), true) ? 'reconcile' : $page);
	print '<nav class="kreabank-workspace-nav" aria-label="KreaBank">';
	foreach (kreabankPrepareHead($accountId) as $tab) {
		print '<a' . ($tab[2] === $active ? ' class="active" aria-current="page"' : '') . ' href="' . dol_escape_htmltag($tab[0]) . '">' . $tab[1] . '</a>';
	}
	print '</nav>';
	if ($active === 'reconcile') {
		print '<nav class="kreabank-tools" aria-label="' . dol_escape_htmltag($langs->trans('KreaBankReconcile')) . '">';
		foreach (array('reconcile' => 'KreaBankReviewOneByOne', 'bulkmatch' => 'KreaBankBulkMatch', 'pending' => 'KreaBankPending') as $tool => $label) {
			print '<a' . ($page === $tool ? ' class="active" aria-current="page"' : '') . ' href="' . dol_escape_htmltag(kreabankWorkspaceUrl($tool, $accountId)) . '">' . $langs->trans($label) . '</a>';
		}
		print '</nav>';
	}
	print kreabankIntegrationPanel($accountId);
	print '<p class="opacitymedium kreabank-workspace-help">' . $langs->trans('KreaBankWorkspaceHelp_' . $page) . '</p>';
	// Callers already close this fiche at the end of their page.
	print dol_get_fiche_head(array(), '', '', -1);
}
