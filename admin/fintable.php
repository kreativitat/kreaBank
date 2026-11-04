<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
$res = @include __DIR__ . '/../../../main.inc.php';
if (!$res) {
	$res = @include __DIR__ . '/../../../../main.inc.php';
}
if (!$res) {
	die('Dolibarr bootstrap unavailable');
}
require_once __DIR__ . '/../lib/kreabank.lib.php';
require_once __DIR__ . '/../class/KreaBankFintableSync.class.php';
$langs->loadLangs(array('admin', 'banks', 'kreabank@kreabank'));
if (empty($user->admin) || !empty($user->entity) || !empty($user->socid) || !isModEnabled('kreabank')) {
	accessforbidden();
}
$sync = new KreaBankFintableSync($db);
$action = GETPOST('action', 'aZ09');
if ($action !== '') {
	if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !kreabankIsTokenValid()) {
		accessforbidden();
	}
	try {
		if ($action === 'save') {
			$sync->configure(array('workspace' => GETPOST('workspace', 'alphanohtml'), 'token' => GETPOST('api_token', 'none'),
				'times' => GETPOST('times', 'alphanohtml'), 'timezone' => GETPOST('timezone', 'alphanohtml'),
				'enabled' => GETPOSTINT('enabled'), 'refresh' => GETPOSTINT('refresh'), 'alert_email' => GETPOST('alert_email', 'alphanohtml')), $user);
			$sync->ensureSchema();
			$sync->installJob($user);
			setEventMessages($langs->trans('SetupSaved'), null, 'mesgs');
		}
	} catch (Throwable $e) {
		setEventMessages($e->getMessage(), null, 'errors');
	}
	header('Location: ' . $_SERVER['PHP_SELF']);
	exit;
}
llxHeader('', $langs->trans('KreaBankFeeds'));
print kreabankListAssets();
print load_fiche_titre($langs->trans('KreaBankFeeds'), '', 'bank');
print dol_get_fiche_head(kreabankAdminPrepareHead(), 'fintable', $langs->trans('KreaBankSetup'), -1, 'bank');
print '<p>' . $langs->trans('KreaBankFeedAdminPurpose') . ' <a href="https://fintable.io" target="_blank" rel="noopener noreferrer">Fintable</a>.</p>';
print '<form method="post" autocomplete="off"><input type="hidden" name="token" value="' . newToken() . '"><input type="hidden" name="action" value="save">';
print '<table class="border centpercent">';
$fields = array('workspace' => array('KreaBankFeedWorkspace', $sync->setting('KREABANK_FINTABLE_WORKSPACE')),
	'times' => array('KreaBankFeedSchedule', $sync->setting('KREABANK_FINTABLE_TIMES', '08:45')),
	'timezone' => array('KreaBankFeedTimezone', $sync->setting('KREABANK_FINTABLE_TIMEZONE', 'Europe/Lisbon')),
	'alert_email' => array('KreaBankFeedAlertEmail', $sync->setting('KREABANK_FINTABLE_ALERT_EMAIL')));
foreach ($fields as $name => $field) {
	print '<tr><td><label for="' . $name . '">' . $langs->trans($field[0]) . '</label></td><td><input class="minwidth300" id="' . $name . '" name="' . $name . '" value="' . dol_escape_htmltag($field[1]) . '"' . ($name === 'alert_email' ? ' type="email"' : ' required') . '></td></tr>';
}
print '<tr><td><label for="api_token">' . $langs->trans('KreaBankFeedToken') . '</label></td><td><input type="password" autocomplete="new-password" id="api_token" name="api_token" class="minwidth300" value="">';
print '<p class="opacitymedium">' . $langs->trans('KreaBankFeedTokenHelp') . '</p></td></tr>';
foreach (array('enabled' => 'KreaBankFeedEnabled', 'refresh' => 'KreaBankFeedRefresh') as $name => $key) {
	print '<tr><td><label for="' . $name . '">' . $langs->trans($key) . '</label></td><td><input type="checkbox" value="1" id="' . $name . '" name="' . $name . '"'
		. ($sync->setting('KREABANK_FINTABLE_' . strtoupper($name), '0') === '1' ? ' checked' : '') . '></td></tr>';
}
print '</table><p class="opacitymedium">' . $langs->trans('KreaBankFeedRefreshHelp') . '</p><div class="center"><button class="button button-save" type="submit">' . $langs->trans('Save') . '</button></div></form>';
print '<h2>' . $langs->trans('KreaBankFeedCoverage') . '</h2><p>' . $langs->trans('KreaBankFeedCoverageHelp') . '</p>';
try {
	$lastRun = $sync->lastRun();
	if ($lastRun) {
		$summary = json_decode((string) $lastRun->result_json, true);
		print '<p>' . $langs->trans('KreaBankFeedCollectedAt') . ': ' . dol_print_date($db->jdate($lastRun->started_at), 'dayhour')
			. ' — ' . dol_escape_htmltag($lastRun->status) . '</p>';
		if (is_array($summary)) {
			print '<p>' . $langs->trans('KreaBankFeedRunSummary', (int) ($summary['mapped'] ?? 0), (int) ($summary['unmapped'] ?? 0), (int) ($summary['failed'] ?? 0)) . '</p>';
		}
	}
	$entities = $sync->activeEntities();
	$companyRows = array();
	foreach ($entities as $entity => $label) {
		$accounts = $sync->accountStatus($entity);
		$mapped = 0;
		$latest = '';
		foreach ($accounts as $account) {
			$mapped += empty($account->provider_account_id) ? 0 : 1;
			$latest = max($latest, (string) $account->last_success);
		}
		$companyRows[] = array('company'=>$label, 'mapped'=>$mapped, 'accounts'=>count($accounts), 'latest'=>$latest);
	}
	$columns = array('company'=>'Company', 'mapped'=>'KreaBankFeedMappedAccounts', 'latest'=>'KreaBankFeedCollectedAt');
	$sort = kreabankListSort($columns, 'company');
	$companyRows = kreabankListOrderRows($companyRows, $sort, static function($row,$field){return $row[$field];});
	$total = count($companyRows);
	$paging = kreabankListPagination($total);
	$params = array('limit'=>$paging['limit']);
	$param = '&' . http_build_query($params);
	print '<form method="GET" action="' . dol_escape_htmltag($_SERVER['PHP_SELF']) . '">';
	foreach(array('sortfield'=>$sort['field'],'sortorder'=>$sort['order']) as $key=>$value){print '<input type="hidden" name="'.$key.'" value="'.dol_escape_htmltag($value).'">';}
	print_barre_liste($langs->trans('Company'), $paging['page'], $_SERVER['PHP_SELF'], $param, $sort['field'], $sort['order'], '', $paging['num'], $total, '', 0, '', '', $paging['limit'], 0, 0, 1);
	print '</form><div class="div-table-responsive"><table class="liste centpercent"><tr class="liste_titre">';
	foreach($columns as $field=>$label){print kreabankListHeader($langs->trans($label),$field,$sort,'',$params);}
	print '</tr>';
	foreach(array_slice($companyRows,$paging['offset'],$paging['limit']) as $row){
		print '<tr class="oddeven"><td>'.dol_escape_htmltag($row['company']).'</td><td>'.$row['mapped'].' / '.$row['accounts'].'</td><td>'
			.($row['latest'] ? dol_print_date($db->jdate($row['latest']),'dayhour') : '—').'</td></tr>';
	}
	print '</table></div>';
} catch (Throwable $e) {
	print '<p>' . dol_escape_htmltag($e->getMessage()) . '</p>';
}
print dol_get_fiche_end();
llxFooter();
$db->close();
