<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

require_once __DIR__ . '/kreabank_integration.lib.php';

/** Build a workspace URL without carrying actions, line IDs or pagination across pages. */
function kreabankWorkspaceUrl($page, $accountId = 0)
{
	$pages = array('reconcile', 'bulkmatch', 'pending', 'import', 'feed', 'history', 'statement', 'accounts');
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

/** Native tabs are replaced by the shared workspace header, not extended. */
function kreabankCompleteAccountHead($head, $accountId)
{
	return $head;
}

/** Persist the account context through GET filters and POST actions. */
function kreabankNavigationHiddenFields($accountId)
{
	return (int) $accountId > 0 ? '<input type="hidden" name="account_context" value="' . (int) $accountId . '">' : '';
}

/** Resolve a page in the single banking workspace. */
function kreabankBankingUrl($page, $accountId = 0)
{
	if ($page === 'movements') {
		return kreabankNativeEntriesUrl($accountId);
	}
	if ($page === 'card') {
		return (int) $accountId > 0 ? dol_buildpath('/compta/bank/card.php', 1) . '?id=' . (int) $accountId : kreabankWorkspaceUrl('accounts');
	}
	return $page === 'statement' ? kreabankStatementUrl($accountId) : kreabankWorkspaceUrl($page, $accountId);
}

/** One account selector and one primary navigation bar for native and module pages. */
function kreabankBankingHeader($page, $account = null)
{
	global $db, $conf, $langs, $user, $mysoc;
	$accountId = $account ? (int) $account->id : 0;
	$langs->loadLangs(array('banks', 'kreabank@kreabank'));
	$css = dol_buildpath('/custom/kreabank/css/navigation.css', 1) . '?v=' . (int) filemtime(__DIR__ . '/../css/navigation.css');
	$html = '<link rel="stylesheet" href="' . dol_escape_htmltag($css) . '"><header class="kb-header">';
	$html .= '<div class="kb-context"><div class="kb-brand"><span class="fas fa-university" aria-hidden="true"></span><strong>' . $langs->trans('KreaBankBanking') . '</strong>';
	$html .= '<span class="kb-company" title="' . dol_escape_htmltag($mysoc->name ?? '') . '">' . dol_escape_htmltag($mysoc->name ?? '') . '</span></div>';
	$html .= '<form method="get" action="' . dol_escape_htmltag(dol_buildpath('/custom/kreabank/accounts.php', 1)) . '"><input type="hidden" name="view" value="' . dol_escape_htmltag($page) . '">';
	$html .= '<label for="kreabank-account-context">' . $langs->trans('BankAccount') . '</label><select class="flat" id="kreabank-account-context" name="account_context"><option value="0">' . $langs->trans('KreaBankAllAccounts') . '</option>';
	$result = $db->query('SELECT rowid,ref,label FROM ' . $db->prefix() . 'bank_account WHERE entity=' . (int) $conf->entity . ' ORDER BY clos,ref,label');
	if (!$result) {
		throw new RuntimeException('Unable to read company accounts');
	}
	while ($row = $db->fetch_object($result)) {
		$html .= '<option value="' . (int) $row->rowid . '"' . ((int) $row->rowid === $accountId ? ' selected' : '') . '>' . dol_escape_htmltag($row->ref . ' — ' . $row->label) . '</option>';
	}
	$html .= '</select><button class="button" type="submit">' . $langs->trans('Show') . '</button></form></div>';
	$active = in_array($page, array('bulkmatch', 'pending'), true) ? 'reconcile' : ($page === 'import' ? 'statement' : ($page === 'card' ? 'accounts' : $page));
	$html .= '<nav class="kb-primary" aria-label="' . dol_escape_htmltag($langs->trans('KreaBankBanking')) . '">';
	foreach (kreabankPrepareHead($accountId) as $tab) {
		$html .= '<a' . ($active === $tab[2] ? ' class="active" aria-current="page"' : '') . ' href="' . dol_escape_htmltag($tab[0]) . '">' . $tab[1] . '</a>';
	}
	$html .= '</nav>';
	if ($active === 'reconcile') {
		$html .= '<div class="kb-view-switch" aria-label="' . dol_escape_htmltag($langs->trans('KreaBankReconcile')) . '">';
		foreach (array('reconcile' => 'KreaBankReviewOneByOne', 'bulkmatch' => 'KreaBankBulkMatch', 'pending' => 'KreaBankPending') as $view => $label) {
			$html .= '<a' . ($page === $view ? ' class="active" aria-current="page"' : '') . ' href="' . dol_escape_htmltag(kreabankWorkspaceUrl($view, $accountId)) . '">' . $langs->trans($label) . '</a>';
		}
		$html .= '</div>';
	}
	$html .= '<details class="kb-more"><summary>' . $langs->trans('KreaBankMoreOptions') . '</summary><div>';
	foreach (($accountId > 0 ? array('card.php?id=' => 'KreaBankAccountDetails', 'document.php?id=' => 'Documents', 'annuel.php?id=' => 'Reports', 'treso.php?id=' => 'KreaBankForecast', 'releve.php?native_statements=1&account=' => 'KreaBankNativeStatements', 'bankentries_list.php?action=reconcile&search_conciliated=0&id=' => 'KreaBankStatementNumbering') : array()) as $route => $label) {
		$html .= '<a href="' . dol_escape_htmltag(dol_buildpath('/compta/bank/' . $route . $accountId, 1)) . '">' . $langs->trans($label) . '</a>';
	}
	if ($user->hasRight('banque','modifier')) {
		$html .= '<a href="' . dol_buildpath('/compta/bank/transfer.php',1) . '">' . $langs->trans('Transfer') . '</a>';
		$html .= '<a href="' . dol_buildpath('/compta/paiement/cheque/index.php',1) . '">' . $langs->trans('KreaBankChequeDeposits') . '</a>';
	}
	if (!empty($user->admin)) {
		$html .= '<a href="' . dol_buildpath('/custom/kreabank/admin/automation.php',1) . '">' . $langs->trans('KreaBankAutomation') . '</a>';
	}
	$html .= '</div></details>';
	$html .= '</header><script nonce="' . dol_escape_htmltag(function_exists('getNonce') ? getNonce() : '') . '">document.body.classList.add("kreabank-banking");</script>';
	return $html;
}

function kreabankPrintWorkspaceNavigation($page, $account = null)
{
	print kreabankBankingHeader($page, $account);
	// Existing page controllers close this content wrapper once.
	print dol_get_fiche_head(array(), '', '', -1);
}
