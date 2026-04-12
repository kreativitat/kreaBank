<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file       kreabank/pending.php
 * \ingroup    kreabank
 * \brief      Pending statement lines.
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
require_once __DIR__ . '/class/KreaBankService.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/class/html.form.class.php';

$langs->loadLangs(array('errors', 'kreabank@kreabank'));

if (!isModEnabled('kreabank')) {
	accessforbidden();
}
if (!isModEnabled('banque')) {
	accessforbidden($langs->trans('ModuleBankDisabled'));
}
if (!$user->hasRight('kreabank', 'reconciliation', 'read')) {
	accessforbidden($langs->trans('KreaBankNoPermission'));
}
if (!$user->hasRight('banque', 'lire')) {
	accessforbidden($langs->trans('ErrorForbidden'));
}

$form = new Form($db);
$service = new KreaBankService($db, $user, $langs);

$sortfield = GETPOST('sortfield', 'aZ09comma');
$sortorder = strtoupper(GETPOST('sortorder', 'aZ09comma'));
$page = GETPOSTISSET('pageplusone') ? (GETPOSTINT('pageplusone') - 1) : GETPOSTINT('page');
if ($page < 0) {
	$page = 0;
}

$limit = GETPOSTINT('limit');
if ($limit <= 0) {
	$limit = !empty($conf->liste_limit) ? (int) $conf->liste_limit : 20;
}
$limit = max(1, min(1000, $limit));
$offset = $limit * $page;

if (empty($sortfield)) {
	$sortfield = 'operation_date';
}
if ($sortorder !== 'ASC' && $sortorder !== 'DESC') {
	$sortorder = 'ASC';
}

$searchAll = trim((string) GETPOST('search_all', 'restricthtml'));
$searchDescription = trim((string) GETPOST('search_description', 'restricthtml'));
$searchAmount = trim((string) GETPOST('search_amount', 'alphanohtml'));
$searchDirection = trim((string) GETPOST('search_direction', 'aZ09'));
$searchDateFrom = trim((string) GETPOST('search_date_from', 'alphanohtml'));
$searchDateTo = trim((string) GETPOST('search_date_to', 'alphanohtml'));
if ($searchDirection !== 'debit' && $searchDirection !== 'credit') {
	$searchDirection = '';
}

if (GETPOST('button_removefilter_x', 'alpha') !== '' || GETPOST('button_removefilter', 'alpha') !== '' || GETPOST('removefilter', 'alpha') !== '') {
	$searchAll = '';
	$searchDescription = '';
	$searchAmount = '';
	$searchDirection = '';
	$searchDateFrom = '';
	$searchDateTo = '';
	$page = 0;
	$offset = 0;
}

$filters = array(
	'search_all' => $searchAll,
	'search_description' => $searchDescription,
	'search_amount' => $searchAmount,
	'search_direction' => $searchDirection,
	'search_date_from' => $searchDateFrom,
	'search_date_to' => $searchDateTo,
);

$pendingLines = array();
$nbtotalofrecords = 0;
$num = 0;
try {
	$nbtotalofrecords = $service->getPendingLinesCount($filters, true);
	if ($page > 0 && ($page * $limit) >= $nbtotalofrecords) {
		$page = 0;
		$offset = 0;
	}
	$pendingLines = $service->getPendingLines($limit + 1, $offset, $sortfield, $sortorder, $filters, true);
	$num = count($pendingLines);
	if ($num > $limit) {
		$pendingLines = array_slice($pendingLines, 0, $limit);
	}
} catch (Exception $e) {
	setEventMessages($e->getMessage(), null, 'errors');
}

$param = '';
$param .= '&mainmenu=bank';
$param .= '&leftmenu=kreabank_pending';
if ($searchAll !== '') {
	$param .= '&search_all=' . urlencode($searchAll);
}
if ($searchDescription !== '') {
	$param .= '&search_description=' . urlencode($searchDescription);
}
if ($searchAmount !== '') {
	$param .= '&search_amount=' . urlencode($searchAmount);
}
if ($searchDirection !== '') {
	$param .= '&search_direction=' . urlencode($searchDirection);
}
if ($searchDateFrom !== '') {
	$param .= '&search_date_from=' . urlencode($searchDateFrom);
}
if ($searchDateTo !== '') {
	$param .= '&search_date_to=' . urlencode($searchDateTo);
}
if ($limit > 0 && $limit !== (int) $conf->liste_limit) {
	$param .= '&limit=' . $limit;
}

llxHeader('', $langs->trans('KreaBankPending'), '', '', 0, 0, '', '', '', 'mod-kreabank page-pending bodyforlist');

print load_fiche_titre($langs->trans('KreaBankPending'), '', 'title_setup');
$head = kreabankPrepareHead();
print dol_get_fiche_head($head, 'pending', $langs->trans('KreaBankPending'), -1, 'bank');

print '<form method="GET" id="searchFormList" action="' . dol_escape_htmltag($_SERVER['PHP_SELF']) . '">';
print '<input type="hidden" name="mainmenu" value="bank">';
print '<input type="hidden" name="leftmenu" value="kreabank_pending">';
print '<input type="hidden" name="sortfield" value="' . dol_escape_htmltag($sortfield) . '">';
print '<input type="hidden" name="sortorder" value="' . dol_escape_htmltag($sortorder) . '">';
print '<input type="hidden" name="page" value="' . ((int) $page) . '">';

print_barre_liste($langs->trans('KreaBankPending'), $page, $_SERVER['PHP_SELF'], $param, $sortfield, $sortorder, '', $num, $nbtotalofrecords, 'title_setup', 0, '', '', $limit, 0, 0, 1);

print '<div class="div-table-responsive">';
print '<table class="tagtable nobottomiftotal liste">';

print '<tr class="liste_titre_filter">';
print '<td class="liste_titre">';
print '<div style="display:flex;gap:4px;flex-direction:column;min-width:130px">';
print '<input class="flat" type="date" name="search_date_from" value="' . dol_escape_htmltag($searchDateFrom) . '" title="' . $langs->trans('From') . '">';
print '<input class="flat" type="date" name="search_date_to" value="' . dol_escape_htmltag($searchDateTo) . '" title="' . $langs->trans('To') . '">';
print '</div>';
print '</td>';
print '<td class="liste_titre">';
print '<input class="flat" type="text" name="search_description" value="' . dol_escape_htmltag($searchDescription) . '" placeholder="' . dol_escape_htmltag($langs->trans('KreaBankDescription')) . '"><br>';
print '<input class="flat" type="text" name="search_all" value="' . dol_escape_htmltag($searchAll) . '" placeholder="' . dol_escape_htmltag($langs->trans('Search')) . '" style="margin-top:4px">';
print '</td>';
print '<td class="liste_titre right">';
print '<input class="flat right" type="text" name="search_amount" value="' . dol_escape_htmltag($searchAmount) . '" placeholder="0,00" style="max-width:95px"><br>';
print '<select class="flat" name="search_direction" style="margin-top:4px;max-width:95px">';
print '<option value="">' . dol_escape_htmltag($langs->trans('All')) . '</option>';
print '<option value="debit"' . ($searchDirection === 'debit' ? ' selected' : '') . '>' . $langs->trans('KreaBankDirectionDebit') . '</option>';
print '<option value="credit"' . ($searchDirection === 'credit' ? ' selected' : '') . '>' . $langs->trans('KreaBankDirectionCredit') . '</option>';
print '</select>';
print '</td>';
print '<td class="liste_titre right">&nbsp;</td>';
print '<td class="liste_titre center">&nbsp;</td>';
print '<td class="liste_titre center maxwidthsearch">' . $form->showFilterButtons() . '</td>';
print '</tr>';

print '<tr class="liste_titre">';
print_liste_field_titre($langs->trans('KreaBankDate'), $_SERVER['PHP_SELF'], 'b.dateo', '', $param, 'class="nowrap"', $sortfield, $sortorder);
print_liste_field_titre($langs->trans('KreaBankDescription'), $_SERVER['PHP_SELF'], 'm.description', '', $param, '', $sortfield, $sortorder);
print_liste_field_titre($langs->trans('KreaBankAmount'), $_SERVER['PHP_SELF'], 'b.amount', '', $param, 'class="right"', $sortfield, $sortorder);
print_liste_field_titre($langs->trans('KreaBankBalance'), $_SERVER['PHP_SELF'], 'm.running_balance', '', $param, 'class="right"', $sortfield, $sortorder);
print_liste_field_titre($langs->trans('KreaBankStatus'), $_SERVER['PHP_SELF'], 'b.rappro', '', $param, '', $sortfield, $sortorder);
print_liste_field_titre($langs->trans('KreaBankAction'), '', '', '', '', 'class="center"');
print '</tr>';

if (empty($pendingLines)) {
	print '<tr class="oddeven"><td colspan="6" class="opacitymedium">' . $langs->trans('KreaBankNoStatementLines') . '</td></tr>';
} else {
	foreach ($pendingLines as $line) {
		$statusLabel = kreabankStatusLabel((int) $line['status'], $langs);
		$statusClass = kreabankStatusClass((int) $line['status']);
		$lineId = (int) $line['rowid'];
		$reconcileUrl = dol_buildpath('/custom/kreabank/reconcile.php?mainmenu=bank&leftmenu=kreabank_reconcile&line_id=' . $lineId, 1);
		$operationDateRaw = '';
		if (!empty($line['operation_date'])) {
			$operationDateRaw = (string) $line['operation_date'];
		} elseif (!empty($line['value_date'])) {
			$operationDateRaw = (string) $line['value_date'];
		}
		$operationDateTs = ($operationDateRaw !== '' ? strtotime($operationDateRaw) : false);

		print '<tr class="oddeven">';
		print '<td class="nowrap">' . (($operationDateTs !== false && $operationDateTs > 0) ? dol_print_date($operationDateTs, 'day') : '<span class="opacitymedium">-</span>') . '</td>';
		print '<td>' . dol_escape_htmltag((string) $line['description']) . '</td>';
		print '<td class="right nowrap"><strong>' . price((float) $line['amount']) . ' ' . dol_escape_htmltag((string) $line['currency']) . '</strong>';
		if ((int) $line['is_duplicate'] === 1) {
			print '<br><span class="badge badge-status7">Duplicate</span>';
		}
		print '</td>';
		print '<td class="right nowrap">' . ($line['running_balance'] !== null ? price((float) $line['running_balance']) . ' ' . dol_escape_htmltag((string) $line['currency']) : '<span class="opacitymedium">-</span>') . '</td>';
		print '<td><span class="badge ' . $statusClass . '">' . $statusLabel . '</span></td>';
		print '<td class="center">';
		print '<a class="button smallpaddingimp" href="' . $reconcileUrl . '">' . $langs->trans('KreaBankReconcile') . '</a>';
		print '</td>';
		print '</tr>';
	}
}

print '</table>';
print '</div>';
print '</form>';

print dol_get_fiche_end();
llxFooter();
$db->close();
