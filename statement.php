<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file       kreabank/import.php
 * \ingroup    kreabank
 * \brief      Read-only imported statements and native ledger links.
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
if (!isModEnabled('kreabank') || !isModEnabled('banque') || !$user->hasRight('banque', 'lire') || !$user->hasRight('kreabank', 'reconciliation', 'read')) {
	accessforbidden();
}
$navigationAccountId = max(0, GETPOSTINT('account_context'));
$navigationAccount = kreabankNavigationAccount($navigationAccountId);
$reference = trim((string) GETPOST('ref', 'alphanohtml'));
if ($reference !== '' && !$navigationAccount) {
	accessforbidden();
}
$page = max(0, GETPOSTINT('page'));
$limit = 50;
$report = new KreaBankIntegration($db, $conf->entity);
if ($reference === '' && $navigationAccountId > 0 && $db->num_rows($report->statementAccounts($navigationAccountId)) === 0) {
	header('Location: ' . kreabankStatementUrl());
	exit;
}
$statement = $reference !== '' ? $report->statement($navigationAccountId, $reference) : null;
if ($reference !== '' && !$statement) {
	http_response_code(404);
	llxHeader('', $langs->trans('KreaBankImportedStatements'));
	print '<div class="warning">' . $langs->trans('KreaBankImportDeleteNotFound') . '</div>';
	llxFooter();
	exit;
}
if ($statement) {
	$source = '(' . $report->linesSql($navigationAccountId, $reference) . ') h';
	$columns = array('operation_date' => 'KreaBankDate', 'description' => 'KreaBankDescription', 'amount' => 'KreaBankAmount', 'status' => 'Status', 'native_id' => 'KreaBankNativeMovements');
	$sort = kreabankListSort($columns, 'operation_date');
	$tieOrder = ',h.line_rank,h.rowid';
} else {
	$source = '(' . $report->statementsSql($navigationAccountId) . ') h';
	$columns = array('statement_ref' => 'Ref', 'bank_account_ref' => 'BankAccount', 'imported_at' => 'KreaBankWhen', 'imported_lines' => 'KreaBankImportedLines', 'native_lines' => 'KreaBankNativeMovements', 'reconciled_lines' => 'KreaBankReconciled');
	$sort = kreabankListSort($columns, 'imported_at', 'DESC');
	$tieOrder = ',h.bank_account_id,h.statement_ref';
}
$order = 'h.' . $sort['field'] . ' ' . $sort['order'] . $tieOrder;
$result = $report->query('SELECT COUNT(*) nb FROM ' . $source);
$total = (int) $db->fetch_object($result)->nb;
if ($page * $limit >= $total) {
	$page = 0;
}
$result = $report->query('SELECT h.* FROM ' . $source . ' ORDER BY ' . $order . $db->plimit($limit, $page * $limit));
llxHeader('', $langs->trans('KreaBankImportedStatements'));
kreabankPrintWorkspaceNavigation('statement', $navigationAccount);
print '<div class="kb-page-heading"><h2>' . $langs->trans('KreaBankNavStatements') . '</h2>';
if ($user->hasRight('kreabank', 'reconciliation', 'write') && $user->hasRight('banque', 'modifier')) {
	print '<a class="butAction" href="' . dol_escape_htmltag(kreabankWorkspaceUrl('import', $navigationAccountId)) . '">' . $langs->trans('KreaBankImport') . '</a>';
}
print '</div>';
if ($statement) {
	print '<h2>' . dol_escape_htmltag($reference) . '</h2>';
	print '<p>' . $langs->trans('KreaBankStatementProgress', (int) $statement->imported_lines, (int) $statement->native_lines, (int) $statement->reconciled_lines) . '</p>';
	print '<p>' . $langs->trans('KreaBankPostingDateExplanation') . '</p>';
	if ((int) $statement->native_lines > 0) {
		print '<p><a class="button" href="' . dol_escape_htmltag(kreabankNativeEntriesUrl($navigationAccountId, false, $reference)) . '">' . $langs->trans('KreaBankNativeMovements') . '</a> ';
		print '<a class="button" href="' . dol_escape_htmltag(dol_buildpath('/compta/bank/releve.php', 1) . '?account=' . $navigationAccountId . '&num=' . rawurlencode($reference)) . '">' . $langs->trans('KreaBankNativeStatements') . '</a></p>';
	}
	print '<div class="div-table-responsive"><table class="liste centpercent kreabank-statement-list"><tr class="liste_titre">';
	foreach ($columns as $field => $key) {
		print kreabankListHeader($langs->trans($key), $field, $sort, $field === 'amount' ? 'right' : '');
	}
	print '</tr>';
	while ($row = $db->fetch_object($result)) {
		$state = kreabankIntegrationLineState($row, $reference);
		print '<tr class="oddeven"><td>' . dol_escape_htmltag($row->operation_date) . '</td><td>' . dol_escape_htmltag($row->description);
		foreach (kreabankStatementReferences($row, $statement->source_type) as $referenceValue) {
			print '<br><small>' . dol_escape_htmltag($referenceValue) . '</small>';
		}
		print '</td>';
		print '<td class="right">' . kreabankPrice($row->amount) . ' ' . dol_escape_htmltag($row->currency) . '</td>';
		print '<td' . ($state === 'KreaBankLinkNeedsReview' ? ' class="warning"' : '') . '>' . $langs->trans($state) . '</td><td>';
		if ((int) $row->native_id > 0) {
			print '<a href="' . dol_buildpath('/compta/bank/line.php', 1) . '?rowid=' . (int) $row->native_id . '">#' . (int) $row->native_id . '</a>';
			if ($state === 'KreaBankLinkNeedsReview') {
				print '<br>' . kreabankPrice($row->native_amount) . ' ' . dol_escape_htmltag($row->native_currency);
			}
		} else {
			print $langs->trans((int) $row->expected_native_id > 0 ? 'KreaBankNativeMissing' : 'KreaBankAwaitingReconciliation');
		}
		print '</td></tr>';
	}
} else {
	print '<div class="div-table-responsive"><table class="liste centpercent kreabank-statement-list"><tr class="liste_titre">';
	foreach ($columns as $field => $key) {
		print kreabankListHeader($langs->trans($key), $field, $sort, $field === 'amount' ? 'right' : '');
	}
	print '</tr>';
	while ($row = $db->fetch_object($result)) {
		print '<tr class="oddeven"><td><a href="' . dol_escape_htmltag(kreabankStatementUrl($row->bank_account_id, $row->statement_ref)) . '">' . dol_escape_htmltag($row->statement_ref) . '</a>';
		if ((int) $row->issue_lines > 0) {
			print '<br><strong class="warning">' . $langs->trans('KreaBankLinkIssues', (int) $row->issue_lines) . '</strong>';
		}
		print '</td><td><a href="' . dol_escape_htmltag(kreabankStatementUrl($row->bank_account_id)) . '">' . dol_escape_htmltag($row->bank_account_ref) . '</a></td>';
		print '<td>' . dol_print_date($db->jdate($row->imported_at), 'dayhour') . '</td><td>' . (int) $row->imported_lines . '</td><td>' . (int) $row->native_lines . '</td><td>' . (int) $row->reconciled_lines . '</td></tr>';
	}
}
if ($total === 0) {
	print '<tr><td colspan="' . count($columns) . '">' . $langs->trans('KreaBankImportHistoryEmpty') . '</td></tr>';
}
print '</table></div><nav class="kreabank-tools" aria-label="' . dol_escape_htmltag($langs->trans('Page')) . '">';
$base = kreabankStatementUrl($navigationAccountId, $reference) . '&sortfield=' . rawurlencode($sort['field']) . '&sortorder=' . $sort['order'];
if ($page > 0) {
	print '<a href="' . dol_escape_htmltag($base . '&page=' . ($page - 1)) . '">' . $langs->trans('Previous') . '</a>';
}
print '<span>' . ($total > 0 ? ($page * $limit + 1) : 0) . '–' . min($total, ($page + 1) * $limit) . ' / ' . $total . '</span>';
if (($page + 1) * $limit < $total) {
	print '<a href="' . dol_escape_htmltag($base . '&page=' . ($page + 1)) . '">' . $langs->trans('Next') . '</a>';
}
print '</nav>';
print dol_get_fiche_end();
llxFooter();
$db->close();
