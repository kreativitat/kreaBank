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
require_once __DIR__ . '/lib/kreabank_statement_list.lib.php';
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
$request = array();
foreach (array('operation_from','operation_to','value_from','value_to','description','debit','credit','running_balance','currency','bank_account_ref','statement_ref','status') as $field) {
	$request['search_' . $field] = GETPOST('search_' . $field, 'alphanohtml');
}
foreach (array('page','pageplusone','pageplusoneold','limit','button_search','button_search_x','button_search.x','button_removefilter','button_removefilter_x','button_removefilter.x') as $field) {
	if (GETPOSTISSET($field)) { $request[$field] = GETPOST($field, 'alphanohtml'); }
}
$list = kreabankStatementListState($request, $conf->liste_limit);
$filters = $list['filters'];
$limit = $list['limit'];
$page = $list['page'];
$where = kreabankStatementListWhere($db, $filters);
$total = (int) $db->fetch_object($report->query('SELECT COUNT(*) nb FROM ' . $source . $where))->nb;
if ($page * $limit >= $total) {
	$page = 0;
}
$order = $orderField . ' IS NULL ASC,' . $orderField . ' ' . $sort['order']
	. ',h.operation_date ' . $sort['order'] . ',h.bank_account_id,h.statement_id ' . $sort['order']
	. ',h.line_rank ' . $sort['order'] . ',h.rowid ' . $sort['order'] . ',h.source_storage';
$result = $report->query('SELECT h.* FROM ' . $source . $where . ' ORDER BY ' . $order . $db->plimit($limit + 1, $page * $limit));
$num = $db->num_rows($result);
$params = array('mainmenu'=>'bank', 'leftmenu'=>'kreabank_bankstatement', 'account_context'=>$accountId, 'limit'=>$limit);
foreach ($filters as $field=>$value) { if ($value !== '') { $params['search_' . $field] = $value; } }
$param = '&' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
$form = new Form($db);
llxHeader('', $langs->trans('KreaBankBankStatement'));
kreabankPrintWorkspaceNavigation('bankstatement', $account);
print '<form method="GET" id="searchFormList" action="' . dol_escape_htmltag($_SERVER['PHP_SELF']) . '">';
print kreabankNavigationHiddenFields($accountId);
foreach (array('mainmenu'=>'bank', 'leftmenu'=>'kreabank_bankstatement', 'sortfield'=>$sort['field'], 'sortorder'=>$sort['order'], 'page'=>$page) as $field=>$value) {
	print '<input type="hidden" name="' . $field . '" value="' . dol_escape_htmltag((string) $value) . '">';
}
print_barre_liste($langs->trans('KreaBankBankStatement'), $page, $_SERVER['PHP_SELF'], $param, $sort['field'], $sort['order'], '', $num, $total, 'bank', 0, '', '', $limit, 0, 0, 1);
print '<div class="div-table-responsive kb-accounts-scroll"><table class="tagtable liste centpercent kb-bank-statement"><thead><tr class="liste_titre_filter">';
$input = static function ($name, $label, $type = 'text', $class = 'width75') use ($filters) {
	return '<input type="' . $type . '" class="flat ' . $class . '" name="search_' . $name . '" value="' . dol_escape_htmltag($filters[$name])
		. '" aria-label="' . dol_escape_htmltag($label) . '" title="' . dol_escape_htmltag($label) . '">';
};
foreach ($columns as $field=>$label) {
	$title = $langs->trans($label);
	print '<td class="liste_titre' . (isset($fields[$field]) ? ' right' : '') . '">';
	if (in_array($field, array('operation_date','value_date'), true)) {
		$prefix = $field === 'operation_date' ? 'operation' : 'value';
		print '<div class="kb-statement-date-filter">' . $input($prefix . '_from', $title . ' — ' . $langs->trans('From'), 'date', 'width125')
			. $input($prefix . '_to', $title . ' — ' . $langs->trans('To'), 'date', 'width125') . '</div>';
	} elseif ($field === 'status') {
		print '<select class="flat maxwidth150" name="search_status" aria-label="' . dol_escape_htmltag($title) . '"><option value="">' . $langs->trans('All') . '</option>';
		foreach (kreabankStatementListStatusOptions() as $value=>$key) {
			print '<option value="' . $value . '"' . ($filters['status'] === $value ? ' selected' : '') . '>' . $langs->trans($key) . '</option>';
		}
		print '</select>';
	} else {
		$class = $field === 'description' ? 'minwidth100 maxwidth150' : ($field === 'currency' ? 'width50' : 'width75');
		print $input($field, $title, isset($fields[$field]) ? 'text' : 'search', $class . (isset($fields[$field]) ? ' right' : ''));
	}
	print '</td>';
}
print '<td class="liste_titre center maxwidthsearch">' . $form->showFilterButtons() . '</td></tr><tr class="liste_titre">';
foreach ($columns as $field => $label) {
	print_liste_field_titre($langs->trans($label), $_SERVER['PHP_SELF'], $field, '', $param,
		isset($fields[$field]) ? 'class="right"' : '', $sort['field'], $sort['order']);
}
print '<th></th></tr></thead><tbody>';
$shown = 0;
while ($shown < $limit && ($row = $db->fetch_object($result))) {
	$shown++;
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
	print '</td><td></td></tr>';
}
if ($total === 0) {
	print '<tr><td colspan="' . (count($columns) + 1) . '">' . $langs->trans('KreaBankImportHistoryEmpty') . '</td></tr>';
}
print '</tbody></table></div></form>' . dol_get_fiche_end();
llxFooter();
$db->close();
