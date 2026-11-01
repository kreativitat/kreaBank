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
$account = kreabankNavigationAccount($accountId);
$view = GETPOST('view','aZ09');
$allowedViews = array('accounts','movements','statement','reconcile','bulkmatch','pending','import','feed','history','card','documents','reports','forecast','native_statements','numbering');
if ($view !== '' && in_array($view,$allowedViews,true)) {
	header('Location: ' . kreabankBankingUrl($view,$accountId));
	exit;
}
$kind = GETPOST('kind','aZ09');
if (!in_array($kind,array('bank','cash','closed'),true)) {
	$kind = $account ? ((int) $account->clos === 1 ? 'closed' : ((int) $account->type === 2 ? 'cash' : 'bank')) : 'bank';
}
$report = new KreaBankIntegration($db,$conf->entity);
$rows = $report->accounts($kind, $accountId);
llxHeader('', $langs->trans('KreaBankAccounts'));
kreabankPrintWorkspaceNavigation('accounts',$account);
print '<div class="kb-accounts-toolbar"><h2>' . $langs->trans('KreaBankAccounts') . '</h2>';
if ($user->hasRight('banque','configurer')) {
	print '<a class="button" href="' . dol_buildpath('/compta/bank/card.php?action=create',1) . '">' . $langs->trans('NewFinancialAccount') . '</a>';
}
print '</div><form class="kb-accounts-filters" method="get" action="' . dol_escape_htmltag(dol_buildpath('/custom/kreabank/accounts.php', 1)) . '" role="search" aria-label="' . dol_escape_htmltag($langs->trans('Filters')) . '">';
print '<input type="hidden" name="mainmenu" value="bank"><input type="hidden" name="leftmenu" value="bank">';
print '<strong class="kb-filter-title">' . $langs->trans('Filters') . '</strong><div class="kb-filter-field">' . kreabankAccountFilterSelect($accountId) . '</div>';
print '<div class="kb-filter-field"><label for="kreabank-account-kind">' . $langs->trans('AccountType') . '</label><select class="flat" id="kreabank-account-kind" name="kind">';
foreach (array('bank'=>'KreaBankBankAccounts','cash'=>'KreaBankCashAccounts','closed'=>'KreaBankClosedAccounts') as $type=>$label) {
	print '<option value="' . $type . '"' . ($type===$kind ? ' selected' : '') . '>' . $langs->trans($label) . '</option>';
}
print '</select></div><button class="button" type="submit">' . $langs->trans('ToFilter') . '</button></form>';
print '<div class="div-table-responsive"><table class="kb-accounts-table"><tr>';
foreach (array('BankAccount','Currency','KreaBankCurrentBalance','KreaBankPending','KreaBankActions') as $label) {
	print '<th>' . $langs->trans($label) . '</th>';
}
print '</tr>';
$count=0;
while ($row=$db->fetch_object($rows)) {
	$count++;
	print '<tr><td><a class="kb-account-name" href="' . dol_escape_htmltag(kreabankNativeEntriesUrl($row->rowid)) . '">' . dol_escape_htmltag($row->ref) . '</a><small>' . dol_escape_htmltag($row->label) . '</small></td>';
	print '<td>' . dol_escape_htmltag($row->currency_code) . '</td><td class="kb-money">' . price($row->balance) . '</td>';
	print '<td><a class="kb-count" href="' . dol_escape_htmltag(kreabankWorkspaceUrl('reconcile',$row->rowid)) . '">' . (int) $row->pending_count . '</a></td>';
	print '<td><div class="kb-account-actions"><a href="' . dol_escape_htmltag(kreabankNativeEntriesUrl($row->rowid)) . '">' . $langs->trans('KreaBankNavMovements') . '</a><a href="' . dol_escape_htmltag(kreabankWorkspaceUrl('reconcile',$row->rowid)) . '">' . $langs->trans('KreaBankReconcile') . '</a></div></td></tr>';
}
if (!$count) {
	print '<tr><td colspan="5">' . $langs->trans('NoRecordFound') . '</td></tr>';
}
print '</table></div>';
print dol_get_fiche_end();
llxFooter();
$db->close();
