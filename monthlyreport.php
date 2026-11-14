<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

// Resolve the same native bootstrap locations as the other workspace controllers.
$res = 0;
if (!empty($_SERVER['CONTEXT_DOCUMENT_ROOT'])) { $res = @include $_SERVER['CONTEXT_DOCUMENT_ROOT'] . '/main.inc.php'; }
foreach (array('../main.inc.php', '../../main.inc.php', '../../../main.inc.php') as $bootstrap) {
	if (!$res && is_file($bootstrap)) { $res = @include $bootstrap; }
}
if (!$res) { die('Include of main fails'); }

require_once __DIR__ . '/lib/kreabank.lib.php';
require_once __DIR__ . '/lib/kreabank_monthly_report.lib.php';
require_once __DIR__ . '/class/KreaBankMonthlyReport.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/class/html.form.class.php';
$langs->loadLangs(array('banks', 'kreabank@kreabank'));
if (!isModEnabled('kreabank') || !isModEnabled('banque') || !empty($user->socid)
	|| !$user->hasRight('banque', 'lire') || !$user->hasRight('kreabank', 'reconciliation', 'read')) {
	accessforbidden();
}
$accountId = max(0, GETPOSTINT('account_context'));
$account = kreabankNavigationAccount($accountId);
$month = GETPOSTISSET('month') ? GETPOST('month', 'alphanohtml') : dol_print_date(dol_now(), '%Y-%m');
$enteredBankBalance = trim((string) GETPOST('bank_balance', 'alphanohtml'));
$bankBalance = kreabankMonthlyScopedBalance($enteredBankBalance, $accountId, $month,
	GETPOSTINT('balance_account'), GETPOST('balance_month', 'alphanohtml'));
$view = kreabankMonthlyReportView(GETPOST('view', 'aZ09'));
$state = array('account_context' => $accountId, 'month' => $month, 'bank_balance' => $bankBalance,
	'balance_account' => $accountId, 'balance_month' => $month,
	'view' => $view);
// Presentation filters never change the full report's loader inputs or totals.
$state = array_merge($state, kreabankMonthlyListRequestState(array_merge($_GET, $_POST), !empty($conf->liste_limit) ? $conf->liste_limit : 25));
$report = null;
$error = '';
if ($accountId > 0) {
	try {
		$report = (new KreaBankMonthlyReport($db, $conf->entity))->load($accountId, $month, $bankBalance);
		$report['company'] = (string) $mysoc->name;
		$report['entity'] = (int) $conf->entity;
	} catch (InvalidArgumentException $e) {
		$error = $langs->trans('KreaBankMonthlyInvalidFilter');
	} catch (Throwable $e) {
		$error = $langs->trans('KreaBankMonthlyUnavailable');
		dol_syslog('KreaBank monthly report failed for company ' . (int) $conf->entity . ', account ' . $accountId, LOG_ERR);
	}
}
if (GETPOST('export', 'aZ09') === 'csv') {
	if (!$report) { http_response_code(400); header('Content-Type: text/plain; charset=UTF-8'); print $error ?: $langs->trans('KreaBankMonthlySelectAccount'); exit; }
	header('Content-Type: text/csv; charset=UTF-8');
	header('Content-Disposition: attachment; filename="kreabank-' . $accountId . '-' . $report['month'] . '.csv"');
	header('Cache-Control: private, no-store');
	$stream = fopen('php://output', 'wb');
	fwrite($stream, "\xEF\xBB\xBF");
	kreabankMonthlyReportCsv($stream, $report, $langs);
	fclose($stream);
	exit;
}
$print = GETPOSTINT('print_report') === 1;
llxHeader('', $langs->trans('KreaBankMonthlyTitle'), '', '', 0, 0,
	array('/custom/kreabank/js/monthly-report.js'));
print '<link rel="stylesheet" href="' . dol_escape_htmltag(dol_buildpath('/custom/kreabank/css/monthly-report.css', 1) . '?v=' . filemtime(__DIR__ . '/css/monthly-report.css')) . '">';
print kreabankBankingHeader('monthlyreport', $account, false);
print '<div class="kb-monthly-heading">';
print load_fiche_titre($langs->trans('KreaBankMonthlyShortTitle'), '', 'bank');
if ($report) { print kreabankMonthlyActionsHtml($state, $langs, $print); }
print '</div>';
print kreabankMonthlyFiltersHtml($state, $langs, $account ? '' : kreabankAccountFilterSelect(0));
if ($error !== '') { print '<div class="error" role="alert">' . dol_escape_htmltag($error) . '</div>'; }
if ($enteredBankBalance !== '' && $bankBalance === '') { print '<p role="status">' . $langs->trans('KreaBankMonthlyBalanceReset') . '</p>'; }
if (!$account) { print '<p>' . $langs->trans('KreaBankMonthlySelectAccount') . '</p>'; }
if ($report) {
	if (!$print) { print kreabankMonthlyViewsHtml($state, $langs); }
	print kreabankMonthlyReportHtml($report, $state, $langs, $print);
}
llxFooter();
$db->close();
