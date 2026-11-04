<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file       kreabank/accounts.php
 * \ingroup    kreabank
 * \brief      Read-only company-owned bank account overview.
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
$langs->loadLangs(array('banks','kreabank@kreabank'));
if (!isModEnabled('kreabank') || !isModEnabled('banque') || !$user->hasRight('banque','lire') || !$user->hasRight('kreabank','reconciliation','read')) {
	accessforbidden();
}
$accountId = max(0,GETPOSTINT('account_context'));
$view = GETPOST('view','aZ09');
$allowedViews = array('accounts','movements','statement','reconcile','bulkmatch','pending','import','feed','history','card','documents','reports','forecast','native_statements','numbering');
if ($view !== '' && in_array($view,$allowedViews,true)) {
	$accountId = $view === 'accounts' ? 0 : $accountId;
	kreabankNavigationAccount($accountId);
	header('Location: ' . kreabankBankingUrl($view,$accountId));
	exit;
}
$accountId = 0;
$account = null;
$kind = kreabankNavigationKind('bank');
$report = new KreaBankIntegration($db,$conf->entity);
$accountColumns = array('ref' => 'BankAccount', 'currency_code' => 'Currency', 'feed_balance' => 'KreaBankFeedBalance', 'bank_updated_at' => 'KreaBankFeedBankUpdated', 'balance' => 'KreaBankLedgerBalance', 'pending_count' => 'KreaBankPending');
$accountSort = kreabankListSort($accountColumns, 'overview');
$rows = $report->accounts($kind, $accountId, $accountSort['field'], $accountSort['order'], 0, 0, true);
$total = $db->num_rows($rows);
$paging = kreabankListPagination($total);
$params = array('kind'=>$kind, 'mainmenu'=>'bank', 'leftmenu'=>'kreabank_accounts', 'limit'=>$paging['limit']);
$param = '&' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
llxHeader('', $langs->trans('KreaBankAccounts'));
kreabankPrintWorkspaceNavigation('accounts',$account);
$newAccount = '';
if ($user->hasRight('banque','configurer')) {
	$newAccount = '<a class="button" href="' . dol_buildpath('/compta/bank/card.php?action=create',1) . '">' . $langs->trans('NewFinancialAccount') . '</a>';
}
print kreabankBankingFilters('accounts', $accountId, $kind);
print '<form method="GET" action="' . dol_escape_htmltag($_SERVER['PHP_SELF']) . '">';
foreach (array_merge($params, array('sortfield'=>$accountSort['field'],'sortorder'=>$accountSort['order'])) as $key=>$value) {
	if ($key !== 'limit') { print '<input type="hidden" name="' . $key . '" value="' . dol_escape_htmltag((string) $value) . '">'; }
}
print_barre_liste($langs->trans('KreaBankAccounts'), $paging['page'], $_SERVER['PHP_SELF'], $param, $accountSort['field'], $accountSort['order'], '', $paging['num'], $total, 'bank', 0, $newAccount, '', $paging['limit'], 0, 0, 1);
print '</form>';
print '<div class="div-table-responsive kb-accounts-scroll"><table class="kb-accounts-table"><tr>';
foreach ($accountColumns as $field => $label) {
	print kreabankListHeader($langs->trans($label), $field, $accountSort, in_array($field, array('balance', 'feed_balance'), true) ? 'kb-money' : '', $params);
}
print '<th scope="col" class="kb-account-actions">' . $langs->trans('KreaBankActions') . '</th></tr>';
$count=0;
while ($row=$db->fetch_object($rows)) {
	if ($count++ < $paging['offset']) { continue; }
	if ($count > $paging['offset'] + $paging['limit']) { break; }
	print '<tr><td><a class="kb-account-name" href="' . dol_escape_htmltag(kreabankNativeEntriesUrl($row->rowid)) . '">' . dol_escape_htmltag($row->ref) . '</a>';
	print '<small>' . dol_escape_htmltag($row->label) . '</small></td>';
	print '<td>' . dol_escape_htmltag($row->currency_code) . '</td>';
	print kreabankAccountFeedCells($row);
	print '<td class="kb-money">' . kreabankPrice($row->balance) . '</td>';
	print '<td><a class="kb-count" href="' . dol_escape_htmltag(kreabankWorkspaceUrl('reconcile',$row->rowid)) . '">' . (int) $row->pending_count . '</a></td>';
	print '<td class="kb-account-actions"><a class="kb-account-edit" href="' . dol_escape_htmltag(kreabankBankingUrl('card', $row->rowid)) . '" aria-label="' . dol_escape_htmltag($langs->trans('Modify') . ' ' . $row->ref) . '" title="' . dol_escape_htmltag($langs->trans('Modify')) . '">' . img_picto('', 'edit') . '</a></td>';
	print '</tr>';
}
if (!$total) {
	print '<tr><td colspan="' . (count($accountColumns) + 1) . '">' . $langs->trans('NoRecordFound') . '</td></tr>';
}
print '</table></div>';
print dol_get_fiche_end();
llxFooter();
$db->close();
