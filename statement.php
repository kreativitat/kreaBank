<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file       kreabank/statement.php
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
require_once __DIR__ . '/lib/kreabank_import_list.lib.php';
$langs->loadLangs(array('banks', 'kreabank@kreabank', 'worklist@kreabank'));
if (!isModEnabled('kreabank') || !isModEnabled('banque') || !empty($user->socid) || !$user->hasRight('banque', 'lire') || !$user->hasRight('kreabank', 'reconciliation', 'read')) {
	accessforbidden();
}
$navigationAccountId = max(0, GETPOSTINT('account_context'));
$navigationAccount = kreabankNavigationAccount($navigationAccountId);
$reference = trim((string) GETPOST('ref', 'alphanohtml'));
if ($reference !== '' && !$navigationAccount) {
	accessforbidden();
}
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
	if ($navigationAccount) { unset($columns['bank_account_ref']); }
	$sort = kreabankListSort($columns, 'imported_at', 'DESC');
	$tieOrder = ',h.bank_account_id,h.statement_ref';
}
$order = 'h.' . $sort['field'] . ' ' . $sort['order'] . $tieOrder;
$list = kreabankImportListState(array_merge($_GET, $_POST), $conf->liste_limit, (bool) $statement);
// The selected owned account replaces this column and its otherwise invisible filter.
if ($navigationAccount && !$statement) { $list['filters']['bank_account_ref'] = ''; }
$filters = $list['filters'];
$where = kreabankImportListWhere($db, $filters, (bool) $statement, $reference, $statement ? $statement->source_type : '');
$total = (int) $db->fetch_object($report->query('SELECT COUNT(*) nb FROM ' . $source . $where))->nb;
$page = $list['page']; $limit = $list['limit'];
if ($page * $limit >= $total) { $page = 0; }
$params = array('mainmenu'=>'bank', 'leftmenu'=>'kreabank_import', 'account_context'=>$navigationAccountId, 'ref'=>$reference, 'limit'=>$limit);
foreach ($filters as $key=>$value) { if ($value !== '') { $params['search_' . $key] = $value; } }
$param = '&' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
$result = $report->query('SELECT h.* FROM ' . $source . $where . ' ORDER BY ' . $order . $db->plimit($limit + 1, $page * $limit));
$num = $db->num_rows($result);
$form = new Form($db);
llxHeader('', $langs->trans('KreaBankImportedStatements'));
kreabankPrintWorkspaceNavigation('statement', $navigationAccount);
$importAction = '';
if ($user->hasRight('kreabank', 'reconciliation', 'write') && $user->hasRight('banque', 'modifier')) {
	$importAction = '<a class="butAction" href="' . dol_escape_htmltag(kreabankWorkspaceUrl('import', $navigationAccountId)) . '">' . $langs->trans('KreaBankImport') . '</a>';
}
if ($statement) {
	print '<h2>' . dol_escape_htmltag($reference) . '</h2>';
	print '<p>' . $langs->trans('KreaBankStatementProgress', (int) $statement->imported_lines, (int) $statement->native_lines, (int) $statement->reconciled_lines) . '</p>';
	print '<p>' . $langs->trans('KreaBankPostingDateExplanation') . '</p>';
	if ((int) $statement->native_lines > 0) {
		print '<p><a class="button" href="' . dol_escape_htmltag(kreabankNativeEntriesUrl($navigationAccountId, false, $reference)) . '">' . $langs->trans('KreaBankNativeMovements') . '</a> ';
		print '<a class="button" href="' . dol_escape_htmltag(dol_buildpath('/compta/bank/releve.php', 1) . '?account=' . $navigationAccountId . '&num=' . rawurlencode($reference)) . '">' . $langs->trans('KreaBankNativeStatements') . '</a></p>';
	}

}
print '<form method="GET" id="searchFormList" action="' . dol_escape_htmltag($_SERVER['PHP_SELF']) . '">';
foreach (array_merge($params,array('sortfield'=>$sort['field'],'sortorder'=>$sort['order'])) as $key=>$value) {
	if ($key !== 'limit' && strpos($key, 'search_') !== 0) { print '<input type="hidden" name="' . $key . '" value="' . dol_escape_htmltag((string) $value) . '">'; }
}
print_barre_liste($langs->trans('KreaBankNavStatements'), $page, $_SERVER['PHP_SELF'], $param, $sort['field'], $sort['order'], '', $num, $total, 'bank', 0, $importAction, '', $limit, 0, 0, 1);
print '<div class="div-table-responsive kb-accounts-scroll"><table class="tagtable liste centpercent kreabank-statement-list"><thead><tr class="liste_titre_filter">';
foreach ($columns as $field=>$key) {
	$title = $langs->trans($key);
	print '<td class="liste_titre' . (in_array($field,array('amount','imported_lines','native_lines','reconciled_lines'),true) ? ' right' : '') . '">';
	$input = static function ($name, $label, $type = 'search', $class = 'width100') use ($filters) {
		return '<input class="flat ' . $class . '" type="' . $type . '" name="search_' . $name . '" value="' . dol_escape_htmltag($filters[$name]) . '" aria-label="' . dol_escape_htmltag($label) . '" title="' . dol_escape_htmltag($label) . '">';
	};
	if (in_array($field,array('imported_at','operation_date'),true)) {
		$prefix = $field === 'imported_at' ? 'imported' : 'operation';
		print '<div class="kb-statement-date-filter">' . $input($prefix . '_from',$title . ' — ' . $langs->trans('From'),'date','width125') . $input($prefix . '_to',$title . ' — ' . $langs->trans('To'),'date','width125') . '</div>';
	} elseif ($field === 'status') {
		print '<select class="flat maxwidth150" name="search_status" aria-label="' . dol_escape_htmltag($title) . '"><option value="">' . $langs->trans('All') . '</option>';
		foreach (kreabankStatementListStatusOptions() as $value=>$label) { print '<option value="' . $value . '"' . ($filters['status'] === $value ? ' selected' : '') . '>' . $langs->trans($label) . '</option>'; }
		print '</select>';
	} else {
		print $input($field,$title,'search',$field === 'description' ? 'minwidth100 maxwidth200' : 'width100');
	}
	print '</td>';
}
print '<td class="liste_titre center maxwidthsearch">' . $form->showFilterButtons() . '</td></tr><tr class="liste_titre">';
foreach ($columns as $field=>$key) {
	print_liste_field_titre($langs->trans($key), $_SERVER['PHP_SELF'], $field, '', $param,
		in_array($field,array('amount','imported_lines','native_lines','reconciled_lines'),true) ? 'class="right"' : '', $sort['field'], $sort['order']);
}
print '<th></th></tr></thead><tbody>';
$shown = 0;
if ($statement) {
	while ($shown < $limit && ($row = $db->fetch_object($result))) {
		$shown++;
		$state = kreabankIntegrationLineState($row, $reference);
		print '<tr class="oddeven"><td>' . dol_escape_htmltag($row->operation_date) . '</td><td>' . dol_escape_htmltag($row->description);
		foreach (kreabankStatementReferences($row, $statement->source_type) as $referenceValue) {
			print '<br><small>' . dol_escape_htmltag($referenceValue) . '</small>';
		}
		print '</td>';
		print '<td class="right">' . kreabankPrice($row->amount) . ' ' . dol_escape_htmltag($row->currency) . '</td>';
		print '<td' . ($state === 'KreaBankLinkNeedsReview' ? ' class="warning"' : '') . '>' . $langs->trans($state);
		if ((int) $row->is_duplicate > 0) { print '<br><span class="warning">' . $langs->trans('KreaBankDuplicate') . '</span>'; }
		print '</td><td>';
		if ((int) $row->native_id > 0) {
			print '<a href="' . dol_buildpath('/compta/bank/line.php', 1) . '?rowid=' . (int) $row->native_id . '">#' . (int) $row->native_id . '</a>';
			if ($state === 'KreaBankLinkNeedsReview') {
				print '<br>' . kreabankPrice($row->native_amount) . ' ' . dol_escape_htmltag($row->native_currency);
			}
		} elseif (!empty($row->group_id)) {
			$batchUrl = dol_buildpath('/custom/kreabank/reconciliationlist.php',1) . '?' . http_build_query(array('mainmenu'=>'bank','leftmenu'=>'kreabank_worklist','search_account'=>$navigationAccountId,'search_status'=>'all','expand'=>'staged:' . (int) $row->rowid,'mode'=>'batch'),'','&',PHP_QUERY_RFC3986);
			print '<a href="' . dol_escape_htmltag($batchUrl) . '">' . $langs->trans('KreaBankWorklistViewBatch') . ' (' . (int) $row->group_count . ')</a>';
		} elseif (!empty($row->opening_reconciled)) {
			print '—';
		} else {
			print $langs->trans((int) $row->expected_native_id > 0 ? 'KreaBankNativeMissing' : 'KreaBankAwaitingReconciliation');
		}
		print '</td><td></td></tr>';
	}
} else {

	while ($shown < $limit && ($row = $db->fetch_object($result))) {
		$shown++;
		print '<tr class="oddeven"><td><a href="' . dol_escape_htmltag(kreabankStatementUrl($row->bank_account_id, $row->statement_ref)) . '">' . dol_escape_htmltag($row->statement_ref) . '</a>';
		if ((int) $row->issue_lines > 0) {
			print '<br><strong class="warning">' . $langs->trans('KreaBankLinkIssues', (int) $row->issue_lines) . '</strong>';
		}
		print '</td>';
		if (isset($columns['bank_account_ref'])) {
			print '<td><a href="' . dol_escape_htmltag(kreabankStatementUrl($row->bank_account_id)) . '">' . dol_escape_htmltag($row->bank_account_ref) . '</a></td>';
		}
		print '<td>' . dol_print_date($db->jdate($row->imported_at), 'dayhour') . '</td><td class="right">' . (int) $row->imported_lines . '</td><td class="right">' . (int) $row->native_lines . '</td><td class="right">' . (int) $row->reconciled_lines . '</td><td></td></tr>';
	}
}
if ($total === 0) {
	print '<tr><td colspan="' . (count($columns) + 1) . '">' . $langs->trans('KreaBankImportHistoryEmpty') . '</td></tr>';
}
print '</tbody></table></div></form>';
print dol_get_fiche_end();
llxFooter();
$db->close();
