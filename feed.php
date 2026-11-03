<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
$res = @include __DIR__ . '/../../main.inc.php';
if (!$res) {
	$res = @include __DIR__ . '/../../../main.inc.php';
}
if (!$res) {
	die('Dolibarr bootstrap unavailable');
}
require_once __DIR__ . '/lib/kreabank.lib.php';
require_once __DIR__ . '/class/KreaBankFintableSync.class.php';
$langs->loadLangs(array('banks', 'kreabank@kreabank'));
if (!isModEnabled('kreabank') || !$user->hasRight('banque', 'lire')) {
	accessforbidden();
}
$navigationAccountId = max(0, GETPOSTINT('account_context'));
$navigationAccount = kreabankNavigationAccount($navigationAccountId);
$sync = new KreaBankFintableSync($db);
$installationAdmin = !empty($user->admin) && empty($user->entity) && empty($user->socid);
if (GETPOST('action', 'aZ09') === 'collect') {
	if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !$installationAdmin || !kreabankIsTokenValid()) {
		accessforbidden();
	}
	try {
		$result = $sync->collect($user);
		setEventMessages($langs->trans('KreaBankFeedCollected', (int) ($result['imported'] ?? 0), (int) ($result['failed'] ?? 0)), null, !empty($result['failed']) ? 'warnings' : 'mesgs');
	} catch (Throwable $e) {
		setEventMessages($e->getMessage(), null, 'errors');
	}
	header('Location: ' . kreabankWorkspaceUrl('feed', $navigationAccountId));
	exit;
}
llxHeader('', $langs->trans('KreaBankFeeds'));
kreabankPrintWorkspaceNavigation('feed', $navigationAccount);
print load_fiche_titre($langs->trans('KreaBankFeeds'), '', 'bank');
print '<p>' . $langs->trans('KreaBankFeedPurpose') . ' <a href="https://fintable.io" target="_blank" rel="noopener noreferrer">Fintable</a>.</p>';
print '<p class="opacitymedium">' . $langs->trans('KreaBankFeedPdfNotice') . '</p>';
print '<p>' . $langs->trans('KreaBankFeedSchedule') . ': <strong>' . dol_escape_htmltag($sync->setting('KREABANK_FINTABLE_TIMES', '08:45,20:45'))
	. '</strong> (' . dol_escape_htmltag($sync->setting('KREABANK_FINTABLE_TIMEZONE', 'Europe/Lisbon')) . ')</p>';
if ($installationAdmin) {
	print '<div class="tabsAction"><a class="butAction" href="' . dol_buildpath('/custom/kreabank/admin/fintable.php', 1) . '">' . $langs->trans('Settings') . '</a>';
	print '<form method="post" action="' . dol_escape_htmltag($_SERVER['PHP_SELF']) . '" style="display:inline">';
	print kreabankNavigationHiddenFields($navigationAccountId);
	print '<input type="hidden" name="token" value="' . newToken() . '"><input type="hidden" name="action" value="collect">';
	print '<button class="butAction" type="submit">' . $langs->trans('KreaBankFeedCollectAll') . '</button></form></div>';
}
$showUnidentified = GETPOSTINT('show_unidentified') === 1;
print '<p><a href="' . dol_escape_htmltag($_SERVER['PHP_SELF']) . '?mainmenu=bank&amp;leftmenu=kreabank_feed&amp;account_context=' . $navigationAccountId . '&amp;show_unidentified=' . ($showUnidentified ? '0' : '1') . '">'
	. $langs->trans($showUnidentified ? 'KreaBankFeedShowEligible' : 'KreaBankFeedShowOtherRecords') . '</a></p>';
$columns = array('ref' => 'BankAccount', 'iban_prefix' => 'IBAN', 'balance' => 'KreaBankFeedBalance', 'balance_available' => 'KreaBankFeedAvailable', 'bank_updated_at' => 'KreaBankFeedBankUpdated', 'last_success' => 'KreaBankFeedCollectedAt', 'history_from' => 'KreaBankFeedHistory', 'status' => 'Status');
$sort = kreabankListSort($columns, 'ref');
$rows = array();
$loadError = '';
try {
	$rows = $sync->accountStatus((int) $conf->entity, $showUnidentified);
	if ($navigationAccountId > 0) {
		$rows = array_values(array_filter($rows, static function ($row) use ($navigationAccountId) { return (int) $row->rowid === $navigationAccountId; }));
	}
	$rows = kreabankListOrderRows($rows, $sort, static function ($row, $field) { return $row->$field; });
} catch (Throwable $e) { $loadError = $e->getMessage(); }
$total = count($rows);
$paging = kreabankListPagination($total);
$rows = array_slice($rows, $paging['offset'], $paging['limit']);
$params = array('mainmenu'=>'bank', 'leftmenu'=>'kreabank_feed', 'account_context'=>$navigationAccountId, 'show_unidentified'=>(int) $showUnidentified, 'limit'=>$paging['limit']);
$param = '&' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
print '<form method="GET" action="' . dol_escape_htmltag($_SERVER['PHP_SELF']) . '">';
foreach (array_merge($params,array('sortfield'=>$sort['field'],'sortorder'=>$sort['order'])) as $key=>$value) {
	if ($key !== 'limit') { print '<input type="hidden" name="' . $key . '" value="' . dol_escape_htmltag((string) $value) . '">'; }
}
print_barre_liste($langs->trans('KreaBankAccounts'), $paging['page'], $_SERVER['PHP_SELF'], $param, $sort['field'], $sort['order'], '', $paging['num'], $total, 'bank', 0, '', '', $paging['limit'], 0, 0, 1);
print '</form>';
print '<div class="div-table-responsive"><table class="liste centpercent">';
print '<tr class="liste_titre">';
foreach ($columns as $field => $key) {
	print kreabankListHeader($langs->trans($key), $field, $sort, in_array($field, array('balance', 'balance_available'), true) ? 'right' : '', $params);
}
print '</tr>';
if ($loadError !== '') {
	print '<tr><td colspan="8">' . dol_escape_htmltag($loadError) . '</td></tr>';
} else {
	foreach ($rows as $row) {
		$state = $row->status ?: 'unmapped';
		print '<tr class="oddeven"><td><a href="' . DOL_URL_ROOT . '/compta/bank/card.php?id=' . (int) $row->rowid . '">'
			. dol_escape_htmltag($row->ref) . '</a><br><span class="opacitymedium">' . dol_escape_htmltag($row->label) . '</span></td>';
		print '<td>' . dol_escape_htmltag($row->iban_prefix) . '</td>';
		foreach (array('balance', 'balance_available') as $column) {
			print '<td class="right nowrap">' . ($row->$column === null ? '—' : kreabankPrice($row->$column) . ' ' . dol_escape_htmltag($row->currency_code)) . '</td>';
		}
		print '<td>' . ($row->bank_updated_at ? dol_print_date(strtotime($row->bank_updated_at), 'dayhour') : '—') . '</td>';
		print '<td>' . ($row->last_success ? dol_print_date($db->jdate($row->last_success), 'dayhour') : '—') . '</td>';
		print '<td>' . dol_escape_htmltag(trim(($row->history_from ?: '') . ' — ' . ($row->history_to ?: ''), ' —')) . '</td>';
		print '<td><span class="badge ' . ($state === 'ready' ? 'badge-status4' : 'badge-status1') . '">' . $langs->trans('KreaBankFeedState_' . $state) . '</span>';
		if ($row->error_code) {
			print '<br>' . dol_escape_htmltag($row->error_code);
		}
		print '</td></tr>';
	}
	if (!$rows) {
		print '<tr><td colspan="8">' . $langs->trans('KreaBankFeedNoAccounts') . '</td></tr>';
	}
}
print '</table></div>';
print '<p class="opacitymedium">' . $langs->trans('KreaBankFeedBalanceNotice') . '</p>';
print dol_get_fiche_end();
llxFooter();
$db->close();
