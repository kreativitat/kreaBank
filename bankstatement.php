<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file       kreabank/bankstatement.php
 * \ingroup    kreabank
 * \brief      Combined read-only bank statement across all imports.
 */

$res = 0;
if (!$res && !empty($_SERVER['CONTEXT_DOCUMENT_ROOT'])) {
	$res = @include $_SERVER['CONTEXT_DOCUMENT_ROOT'] . '/main.inc.php';
}
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);
$i = strlen($tmp) - 1;
$j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] === $tmp2[$j]) {
	$i--;
	$j--;
}
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1)) . '/main.inc.php')) {
	$res = @include substr($tmp, 0, ($i + 1)) . '/main.inc.php';
}
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1))) . '/main.inc.php')) {
	$res = @include dirname(substr($tmp, 0, ($i + 1))) . '/main.inc.php';
}
if (!$res && file_exists('../main.inc.php')) {
	$res = @include '../main.inc.php';
}
if (!$res && file_exists('../../main.inc.php')) {
	$res = @include '../../main.inc.php';
}
if (!$res && file_exists('../../../main.inc.php')) {
	$res = @include '../../../main.inc.php';
}
if (!$res) {
	die('Include of main fails');
}


require_once __DIR__ . '/lib/kreabank.lib.php';
$langs->loadLangs(array('banks', 'kreabank@kreabank'));
if (!isModEnabled('kreabank') || !isModEnabled('banque') || !empty($user->socid)
	|| !$user->hasRight('banque', 'lire') || !$user->hasRight('kreabank', 'reconciliation', 'read')) {
	accessforbidden();
}
$accountId = max(0, GETPOSTINT('account_context'));
$account = kreabankNavigationAccount($accountId);
$report = new KreaBankIntegration($db, $conf->entity);
if ($accountId > 0 && $db->num_rows($report->statementAccounts($accountId)) === 0) {
	header('Location: ' . kreabankWorkspaceUrl('bankstatement'));
	exit;
}
$columns = array('operation_date' => 'KreaBankOperationDate', 'value_date' => 'KreaBankStatementValueDate',
	'description' => 'KreaBankDescription', 'debit' => 'KreaBankHistorySummaryDebits', 'credit' => 'KreaBankHistorySummaryCredits',
	'running_balance' => 'KreaBankStatementBalance', 'currency' => 'KreaBankCurrency', 'bank_account_ref' => 'BankAccount',
	'statement_ref' => 'KreaBankSourceStatement', 'status' => 'Status');
$sort = kreabankListSort($columns, 'operation_date', 'DESC');
// Signed decimal expressions sort the complete scoped result before pagination.
$fields = array('debit' => 'CASE WHEN h.amount<0 THEN -h.amount ELSE NULL END',
	'credit' => 'CASE WHEN h.amount>=0 THEN h.amount ELSE NULL END', 'running_balance' => 'h.display_balance');
$orderField = $fields[$sort['field']] ?? 'h.' . $sort['field'];
$source = '(' . $report->bankStatementWithBalancesSql($accountId) . ') h';
$total = (int) $db->fetch_object($report->query('SELECT COUNT(*) nb FROM ' . $source))->nb;
$limit = 50;
$page = max(0, GETPOSTINT('page'));
if ($page * $limit >= $total) {
	$page = 0;
}
$order = $orderField . ' IS NULL ASC,' . $orderField . ' ' . $sort['order']
	. ',h.operation_date ' . $sort['order'] . ',h.bank_account_id,h.statement_id ' . $sort['order']
	. ',h.line_rank ' . $sort['order'] . ',h.rowid ' . $sort['order'] . ',h.source_storage';
$result = $report->query('SELECT h.* FROM ' . $source . ' ORDER BY ' . $order . $db->plimit($limit, $page * $limit));
llxHeader('', $langs->trans('KreaBankBankStatement'));
kreabankPrintWorkspaceNavigation('bankstatement', $account);
print '<div class="kb-page-heading"><h2>' . $langs->trans('KreaBankBankStatement') . '</h2></div>';
print '<div class="div-table-responsive kb-accounts-scroll"><table class="liste centpercent kb-bank-statement"><thead><tr class="liste_titre">';
foreach ($columns as $field => $label) {
	print kreabankListHeader($langs->trans($label), $field, $sort, in_array($field, array('debit', 'credit', 'running_balance'), true) ? 'right' : '');
}
print '</tr></thead><tbody>';
while ($row = $db->fetch_object($result)) {
	$state = kreabankIntegrationLineState($row, $row->statement_ref);
	print '<tr class="oddeven"><td class="nowrap">' . ($row->operation_date ? dol_print_date($db->jdate($row->operation_date), 'day') : '—')
		. '</td><td class="nowrap">' . ($row->value_date ? dol_print_date($db->jdate($row->value_date), 'day') : '—') . '</td>';
	print '<td class="kb-statement-description">' . dol_escape_htmltag($row->description);
	foreach (kreabankStatementReferences($row) as $reference) {
		print '<br><small>' . dol_escape_htmltag($reference) . '</small>';
	}
	print '</td><td class="right nowrap">' . ((float) $row->amount < 0 ? kreabankPrice(-$row->amount) : '—') . '</td>';
	print '<td class="right nowrap">' . ((float) $row->amount >= 0 ? kreabankPrice($row->amount) : '—') . '</td>';
	print '<td class="right nowrap"' . ($row->balance_calculated ? ' title="' . dol_escape_htmltag($langs->trans('KreaBankCalculatedBalanceHelp', $row->balance_updated_at)) . '"' : '') . '>'
		. ($row->display_balance !== null ? kreabankPrice($row->display_balance) : '—') . '</td>';
	print '<td>' . dol_escape_htmltag($row->currency) . '</td><td>' . dol_escape_htmltag($row->bank_account_ref) . '</td>';
	print '<td><a href="' . dol_escape_htmltag(kreabankStatementUrl($row->bank_account_id, $row->statement_ref)) . '">' . dol_escape_htmltag($row->statement_ref) . '</a></td>';
	print '<td' . ($state === 'KreaBankLinkNeedsReview' ? ' class="warning"' : '') . '>' . $langs->trans($state);
	if ((int) $row->is_duplicate > 0) {
		print '<br><span class="warning">' . $langs->trans('KreaBankDuplicate') . '</span>';
	}
	if ((int) $row->native_id > 0) {
		print '<br><a href="' . dol_escape_htmltag(dol_buildpath('/compta/bank/line.php', 1) . '?rowid=' . (int) $row->native_id) . '">#' . (int) $row->native_id . '</a>';
	}
	print '</td></tr>';
}
if ($total === 0) {
	print '<tr><td colspan="' . count($columns) . '">' . $langs->trans('KreaBankImportHistoryEmpty') . '</td></tr>';
}
print '</tbody></table></div><nav class="kreabank-tools" aria-label="' . dol_escape_htmltag($langs->trans('Page')) . '">';
$base = kreabankWorkspaceUrl('bankstatement', $accountId) . '&sortfield=' . rawurlencode($sort['field']) . '&sortorder=' . $sort['order'];
if ($page > 0) {
	print '<a href="' . dol_escape_htmltag($base . '&page=' . ($page - 1)) . '">' . $langs->trans('Previous') . '</a> ';
}
print '<span>' . ($total > 0 ? $page * $limit + 1 : 0) . '–' . min($total, ($page + 1) * $limit) . ' / ' . $total . '</span>';
if (($page + 1) * $limit < $total) {
	print ' <a href="' . dol_escape_htmltag($base . '&page=' . ($page + 1)) . '">' . $langs->trans('Next') . '</a>';
}
print '</nav>' . dol_get_fiche_end();
llxFooter();
$db->close();
