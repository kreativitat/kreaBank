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
$view = GETPOST('view', 'aZ09');
if (!in_array($view, array('summary', 'checks', 'pending', 'bank', 'ledger'), true)) { $view = 'summary'; }
$state = array('account_context' => $accountId, 'month' => $month, 'bank_balance' => $bankBalance,
	'balance_account' => $accountId, 'balance_month' => $month,
	'view' => $view, 'bank_page' => max(0, GETPOSTINT('bank_page')), 'ledger_page' => max(0, GETPOSTINT('ledger_page')));
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
	array('/custom/kreabank/js/monthly-report.js'), array('/custom/kreabank/css/monthly-report.css'));
print kreabankBankingHeader('bankstatement', $account, false);
print load_fiche_titre($langs->trans('KreaBankMonthlyTitle') . ($account ? ' — ' . dol_escape_htmltag($account->ref) : ''), '', 'bank');
print '<form class="kb-monthly-filters" method="get" action="' . dol_escape_htmltag(dol_buildpath('/custom/kreabank/monthlyreport.php', 1)) . '">';
print '<input type="hidden" name="mainmenu" value="bank"><input type="hidden" name="leftmenu" value="kreabank_bankstatement">';
print '<input type="hidden" id="kb-monthly-balance-account" name="balance_account" value="' . $accountId . '"><input type="hidden" id="kb-monthly-balance-month" name="balance_month" value="' . dol_escape_htmltag($month) . '">';
print '<input type="hidden" name="view" value="' . $view . '"><div>' . kreabankAccountFilterSelect($accountId) . '</div>';
print '<div><label for="kb-monthly-month">' . $langs->trans('KreaBankMonthlyPeriod') . '</label><input class="flat" type="month" id="kb-monthly-month" name="month" required value="' . dol_escape_htmltag($month) . '"></div>';
print '<div><label for="kb-monthly-balance">' . $langs->trans('KreaBankMonthlyOfficialBalance') . '</label><input class="flat right" type="text" inputmode="decimal" id="kb-monthly-balance" name="bank_balance" value="' . dol_escape_htmltag($bankBalance) . '" aria-describedby="kb-monthly-balance-help"><small id="kb-monthly-balance-help">' . $langs->trans('KreaBankMonthlyOfficialBalanceHelp') . '</small></div>';
print '<button type="submit" class="button">' . $langs->trans('KreaBankMonthlyGenerate') . '</button></form>';
if ($error !== '') { print '<div class="error" role="alert">' . dol_escape_htmltag($error) . '</div>'; }
if ($enteredBankBalance !== '' && $bankBalance === '') { print '<p role="status">' . $langs->trans('KreaBankMonthlyBalanceReset') . '</p>'; }
if (!$account) { print '<p>' . $langs->trans('KreaBankMonthlySelectAccount') . '</p>'; }
if ($report) {
	print '<div class="kb-monthly-actions"><a class="button" href="' . dol_escape_htmltag(kreabankMonthlyReportUrl($state, array('export' => 'csv'))) . '">' . $langs->trans('KreaBankMonthlyExport') . '</a>';
	if ($print) { print '<button type="button" class="button" id="kb-monthly-print">' . $langs->trans('KreaBankMonthlyPrint') . '</button>'; }
	else { print '<a class="button" href="' . dol_escape_htmltag(kreabankMonthlyReportUrl($state, array('print_report' => 1))) . '">' . $langs->trans('KreaBankMonthlyPrintView') . '</a>'; }
	print '<a href="' . dol_escape_htmltag(kreabankWorkspaceUrl('bankstatement', $accountId)) . '">' . $langs->trans('KreaBankBankStatement') . '</a></div>';
	if (!$print) {
		print '<nav class="kb-view-switch kb-monthly-views" aria-label="' . dol_escape_htmltag($langs->trans('KreaBankMonthlyTitle')) . '">';
		foreach (array('summary' => 'Summary', 'checks' => 'Checks', 'pending' => 'Pending', 'bank' => 'Bank', 'ledger' => 'Ledger') as $key => $label) {
			print '<a' . ($view === $key ? ' class="active" aria-current="page"' : '') . ' href="' . dol_escape_htmltag(kreabankMonthlyReportUrl($state, array('view' => $key, 'bank_page' => 0, 'ledger_page' => 0))) . '">' . $langs->trans('KreaBankMonthly' . $label) . '</a>';
		}
		print '</nav>';
	}
	print kreabankMonthlyReportHtml($report, $state, $langs, $print);
}
llxFooter();
$db->close();
