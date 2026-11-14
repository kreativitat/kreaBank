<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

require_once __DIR__ . '/kreabank_list.lib.php';

require_once __DIR__ . '/kreabank_integration.lib.php';

/** Build a workspace URL without carrying actions, line IDs or pagination across pages. */
function kreabankWorkspaceUrl($page, $accountId = 0)
{
	$pages = array('reconcile', 'matched', 'bulkmatch', 'pending', 'import', 'feed', 'history', 'statement', 'bankstatement', 'accounts');
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
	if ($page === 'worklist') {
		return dol_buildpath('/custom/kreabank/reconciliationlist.php', 1)
			. '?mainmenu=bank&leftmenu=bank'
			. ((int) $accountId > 0 ? '&search_account=' . (int) $accountId : '');
	}
	if ($page === 'movements') {
		return kreabankNativeEntriesUrl($accountId);
	}
	if (in_array($page, array('card', 'documents', 'reports', 'forecast', 'native_statements', 'numbering'), true)) {
		if ((int) $accountId <= 0) {
			return kreabankWorkspaceUrl('accounts');
		}
		foreach (kreabankAccountToolEntries($accountId, true) as $entry) {
			if ($entry['key'] === $page) {
				return dol_buildpath($entry['url'], 1);
			}
		}
	}
	return $page === 'statement' ? kreabankStatementUrl($accountId) : kreabankWorkspaceUrl($page, $accountId);
}

/** Native account tools; hidden legacy routes remain valid for existing links. */
function kreabankAccountToolEntries($accountId, $includeHidden = false)
{
	if ((int) $accountId <= 0) {
		throw new InvalidArgumentException('An owned bank account is required');
	}
	$entries = array();
	$tools = array(
		'card' => array('card.php?id=', 'KreaBankAccountDetails'),
		'documents' => array('document.php?id=', 'Documents'),
		'reports' => array('annuel.php?id=', 'Reports'),
		'forecast' => array('treso.php?account=', 'KreaBankForecast'),
		'native_statements' => array('releve.php?native_statements=1&account=', 'KreaBankNativeStatements'),
		'numbering' => array('bankentries_list.php?action=reconcile&search_conciliated=0&id=', 'KreaBankStatementNumbering'),
	);
	foreach ($tools as $key => $tool) {
		if (!$includeHidden && in_array($key, array('forecast', 'native_statements', 'numbering'), true)) {
			continue;
		}
		$entries[] = array('key' => $key, 'url' => '/compta/bank/' . $tool[0] . (int) $accountId . '&mainmenu=bank&leftmenu=bank', 'label' => $tool[1]);
	}
	return $entries;
}

/** Account-type state belongs only to the standalone accounts overview. */
function kreabankNavigationKind($default = 'all')
{
	$kind = GETPOST('kind', 'aZ09');
	return in_array($kind, array('all', 'bank', 'cash', 'closed'), true) ? $kind : $default;
}

/** One filter panel outside navigation, submitted directly to its destination page. */
function kreabankBankingFilters($page, $accountId = 0, $kind = null)
{
	global $langs;
	// The separate list owns its native column filters, including account scope.
	if ($page === 'worklist') { return ''; }
	$kind = $page === 'accounts' ? ($kind === null ? kreabankNavigationKind('bank') : $kind) : 'all';
	if ($page === 'accounts') {
		$html = '<div class="kb-view-switch kb-account-kinds" role="navigation" aria-label="' . dol_escape_htmltag($langs->trans('AccountType')) . '">';
		foreach (array('all' => 'KreaBankAllAccountTypes', 'bank' => 'KreaBankBankAccounts', 'cash' => 'KreaBankCashAccounts', 'closed' => 'KreaBankClosedAccounts') as $value => $label) {
			$html .= '<a' . ($value === $kind ? ' class="active" aria-current="page"' : '') . ' href="' . dol_escape_htmltag(kreabankWorkspaceUrl('accounts') . '&kind=' . $value) . '">' . $langs->trans($label) . '</a>';
		}
		return $html . '</div>';
	}
	if ((int) $accountId > 0) {
		return '';
	}
	$accountPages = array('card', 'documents', 'reports', 'forecast', 'native_statements', 'numbering');
	$isAccountTool = in_array($page, $accountPages, true) || $page === 'import';
	$action = $isAccountTool ? dol_buildpath('/custom/kreabank/accounts.php', 1) : strtok(kreabankBankingUrl($page), '?');
	$html = '<form class="kb-accounts-filters" method="get" action="' . dol_escape_htmltag($action) . '" role="search" aria-label="' . dol_escape_htmltag($langs->trans('Filters')) . '">';
	$html .= '<input type="hidden" name="mainmenu" value="bank"><input type="hidden" name="leftmenu" value="bank">';
	if ($isAccountTool) {
		$html .= '<input type="hidden" name="view" value="' . $page . '">';
	}
	if ($page === 'movements' && kreabankMovementFilter() !== 0) {
		$html .= '<input type="hidden" name="kreabank_matched" value="' . kreabankMovementFilter() . '">';
	}
	$html .= '<strong class="kb-filter-title">' . $langs->trans('Filters') . '</strong>';
	// Native movements need id to load the account and disable their own account control.
	$html .= '<div class="kb-filter-field">' . kreabankAccountFilterSelect($accountId, in_array($page, array('bankstatement', 'statement'), true), $page === 'movements' ? 'id' : 'account_context') . '</div>';
	return $html . '<button class="button" type="submit">' . $langs->trans('ToFilter') . '</button></form>';
}

/** Render the current-company account control for workspace or list filters. */
function kreabankAccountFilterSelect($accountId = 0, $statementsOnly = false, $field = 'account_context')
{
	global $db, $conf, $langs;
	if (!in_array($field, array('account_context', 'id'), true)) {
		throw new InvalidArgumentException('Unknown account filter field');
	}
	$html = '<label for="kreabank-account-context">' . $langs->trans('BankAccount') . '</label><select class="flat" id="kreabank-account-context" name="' . $field . '"><option value="0">' . $langs->trans('KreaBankAllAccounts') . '</option>';
	$result = $statementsOnly
		? (new KreaBankIntegration($db, $conf->entity))->statementAccounts()
		: $db->query('SELECT rowid,ref,label FROM ' . $db->prefix() . 'bank_account WHERE entity=' . (int) $conf->entity . ' ORDER BY clos,ref,label');
	if (!$result) {
		throw new RuntimeException('Unable to read company accounts');
	}
	while ($row = $db->fetch_object($result)) {
		$html .= '<option value="' . (int) $row->rowid . '"' . ((int) $row->rowid === (int) $accountId ? ' selected' : '') . '>' . dol_escape_htmltag($statementsOnly ? $row->ref : $row->ref . ' — ' . $row->label) . '</option>';
	}
	return $html . '</select>';
}

/** Account management and operations have separate navigation; filters follow the header. */
function kreabankBankingHeader($page, $account = null, $showFilters = true)
{
	global $db, $conf, $langs, $user;
	$accountId = $account ? (int) $account->id : 0;
	$langs->loadLangs(array('banks', 'kreabank@kreabank'));
	$css = dol_buildpath('/custom/kreabank/css/navigation.css', 1) . '?v=' . (int) filemtime(__DIR__ . '/../css/navigation.css');
	$html = kreabankListAssets() . '<link rel="stylesheet" href="' . dol_escape_htmltag($css) . '"><header class="kb-header">';
	$html .= '<div class="kb-context"><div class="kb-brand"><span class="fas fa-university" aria-hidden="true"></span><strong>' . $langs->trans('KreaBankBanking') . '</strong>';
	$accountLabel = $langs->trans('KreaBankAllAccounts');
	if ($accountId > 0) {
		$parts = array(trim((string) ($account->ref ?? '')), trim((string) ($account->label ?? '')));
		$accountLabel = implode(' — ', array_filter($parts, static function ($value) { return $value !== ''; }));
	}
	$html .= '<span class="kb-account-context" title="' . dol_escape_htmltag($accountLabel) . '">' . dol_escape_htmltag($accountLabel) . '</span>';
	if ($page !== 'worklist' && empty($user->socid) && $user->hasRight('banque', 'lire') && $user->hasRight('kreabank', 'reconciliation', 'read')) {
		$html .= '<a class="butAction kb-reconcile-action" href="' . dol_escape_htmltag(kreabankBankingUrl('worklist', $accountId)) . '"><span class="fas fa-check-double" aria-hidden="true"></span>' . $langs->trans('KreaBankReconcile') . '</a>';
	}
	$html .= '</div></div>';
	$accountTools = $accountId > 0 ? kreabankAccountToolEntries($accountId) : array();
	$active = in_array($page, array('card', 'documents', 'reports', 'forecast', 'native_statements', 'numbering'), true) ? 'account' : (in_array($page, array('matched', 'bulkmatch', 'pending'), true) ? 'reconcile' : ($page === 'import' ? 'statement' : $page));
	if ($page !== 'accounts' && $active !== 'account') {
		$html .= '<nav class="kb-primary" aria-label="' . dol_escape_htmltag($langs->trans('KreaBankBanking')) . '">';
		foreach (kreabankPrepareHead($accountId) as $tab) {
			$html .= '<a' . ($active === $tab[2] ? ' class="active" aria-current="page"' : '') . ' href="' . dol_escape_htmltag($tab[0]) . '">' . $tab[1] . '</a>';
		}
		$html .= '</nav>';
	}
	if ($active === 'account' && $accountTools) {
		$html .= '<div class="kb-view-switch kb-account-tabs" role="navigation" aria-label="' . dol_escape_htmltag($langs->trans('KreaBankAccountOptions')) . '">';
		foreach ($accountTools as $entry) {
			$html .= '<a' . ($page === $entry['key'] ? ' class="active" aria-current="page"' : '') . ' href="' . dol_escape_htmltag(dol_buildpath($entry['url'], 1)) . '">' . $langs->trans($entry['label']) . '</a>';
		}
		$html .= '</div>';
	}
	if ($active === 'reconcile') {
		$html .= '<div class="kb-view-switch" aria-label="' . dol_escape_htmltag($langs->trans('KreaBankReconcile')) . '">';
		foreach (array('reconcile' => 'KreaBankReviewOneByOne', 'matched' => 'KreaBankReviewMatched', 'bulkmatch' => 'KreaBankBulkMatch', 'pending' => 'KreaBankPending') as $view => $label) {
			$html .= '<a' . ($page === $view ? ' class="active" aria-current="page"' : '') . ' href="' . dol_escape_htmltag(kreabankWorkspaceUrl($view, $accountId)) . '">' . $langs->trans($label) . '</a>';
		}
		$html .= '</div>';
	}
	if ($page === 'movements') {
		$matched = kreabankMovementFilter();
		$html .= '<div class="kb-view-switch" aria-label="' . dol_escape_htmltag($langs->trans('KreaBankNavMovements')) . '">';
		foreach (array(0 => 'KreaBankAllMovements', 1 => 'KreaBankReconciledMovements', 2 => 'KreaBankUnreconciledMovements') as $filter => $label) {
			$html .= '<a' . ($matched === $filter ? ' class="active" aria-current="page"' : '') . ' href="' . dol_escape_htmltag(kreabankNativeEntriesUrl($accountId, $filter)) . '">' . $langs->trans($label) . '</a>';
		}
		$html .= '</div>';
	}
	$html .= '</header><script nonce="' . dol_escape_htmltag(function_exists('getNonce') ? getNonce() : '') . '">document.body.classList.add("kreabank-banking");</script>';
	return $html . (!$showFilters || $page === 'accounts' || ($active === 'account' && !$accountId) ? '' : kreabankBankingFilters($page, $accountId));
}

function kreabankPrintWorkspaceNavigation($page, $account = null)
{
	print kreabankBankingHeader($page, $account);
	// Existing page controllers close this content wrapper once.
	print dol_get_fiche_head(array(), '', '', -1);
}
