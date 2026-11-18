<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

require_once __DIR__ . '/kreabank_csv.lib.php';
require_once __DIR__ . '/kreabank_monthly_list.lib.php';
require_once __DIR__ . '/kreabank_worklist_view.lib.php';
require_once __DIR__ . '/kreabank_monthly_balances.lib.php';

/** Keep old pending bookmarks on the bank side; new subtabs are independent. */
function kreabankMonthlyReportView($view)
{
	if ($view === 'pending') { return 'bank_pending'; }
	return in_array($view,array('balances','summary','checks','bank_pending','ledger_pending','bank','ledger'),true) ? $view : 'balances';
}

/** Read-only report links carry only the displayed report's filter state. */
function kreabankMonthlyReportUrl($state, $changes = array())
{
	$allowed = array('account_context', 'month', 'bank_balance', 'balance_account', 'balance_month', 'view');
	$params = array_intersect_key(array_merge($state, $changes), array_flip($allowed));
	if (isset($params['view'])) { $params['view'] = kreabankMonthlyReportView($params['view']); }
	$request = array_merge($state, $changes);
	foreach (kreabankMonthlyListRequestState($request) as $key=>$value) {
		if (array_key_exists($key, $request)) { $params[$key] = $value; }
	}
	if (($changes['export'] ?? '') === 'csv') { $params['export'] = 'csv'; }
	if ((int) ($changes['print_report'] ?? 0) === 1) { $params['print_report'] = 1; }
	return dol_buildpath('/custom/kreabank/monthlyreport.php', 1) . '?' . http_build_query(array_merge(array('mainmenu' => 'bank', 'leftmenu' => 'kreabank_monthlyreport'), $params), '', '&', PHP_QUERY_RFC3986);
}

/** Compact read-only controls; the optional balance stays bound to one account/month. */
function kreabankMonthlyFiltersHtml($state, $langs, $accountSelector = '')
{
	$accountId = max(0, (int) $state['account_context']);
	$html = '<form class="kb-monthly-filters" method="get" action="' . dol_escape_htmltag(dol_buildpath('/custom/kreabank/monthlyreport.php', 1)) . '">';
	foreach (array('mainmenu'=>'bank','leftmenu'=>'kreabank_monthlyreport','view'=>kreabankMonthlyReportView($state['view']),
		'balance_account'=>$accountId,'balance_month'=>$state['month']) as $name=>$value) {
		$id = in_array($name, array('balance_account','balance_month'), true) ? ' id="kb-monthly-' . str_replace('_', '-', $name) . '"' : '';
		$html .= '<input type="hidden"' . $id . ' name="' . $name . '" value="' . dol_escape_htmltag((string) $value) . '">';
	}
	if ($accountId > 0) {
		$html .= '<input type="hidden" id="kreabank-account-context" name="account_context" value="' . $accountId . '">';
	} else {
		$html .= '<div class="kb-monthly-account">' . $accountSelector . '</div>';
	}
	$html .= '<div><label for="kb-monthly-month">' . $langs->trans('KreaBankMonthlyPeriod') . '</label><input class="flat" type="month" id="kb-monthly-month" name="month" required value="' . dol_escape_htmltag($state['month']) . '"></div>';
	$html .= '<div><label for="kb-monthly-balance">' . $langs->trans('KreaBankMonthlyBalanceShort') . ' <span class="opacitymedium">(' . $langs->trans('KreaBankMonthlyOptional') . ')</span></label><input class="flat right" type="text" inputmode="decimal" id="kb-monthly-balance" name="bank_balance" value="' . dol_escape_htmltag($state['bank_balance']) . '" aria-describedby="kb-monthly-balance-help"></div>';
	$html .= '<button type="submit" class="button"><span class="fas fa-search" aria-hidden="true"></span> ' . $langs->trans('KreaBankMonthlyView') . '</button>';
	$html .= '<details class="kb-monthly-balance-help"><summary aria-label="' . dol_escape_htmltag($langs->trans('KreaBankMonthlyOfficialBalance')) . '" title="' . dol_escape_htmltag($langs->trans('KreaBankMonthlyOfficialBalance')) . '"><span class="fas fa-info-circle" aria-hidden="true"></span></summary><p id="kb-monthly-balance-help">' . $langs->trans('KreaBankMonthlyOfficialBalanceHelp') . '</p></details></form>';
	return $html;
}

function kreabankMonthlyActionsHtml($state, $langs, $print = false)
{
	$export = $langs->trans(kreabankMonthlyReportView($state['view'] ?? '') === 'balances' ? 'KreaBankMonthlyBalancesExport' : 'KreaBankMonthlyExport');
	$html = '<div class="kb-monthly-actions"><a class="button" href="' . dol_escape_htmltag(kreabankMonthlyReportUrl($state, array('export'=>'csv'))) . '" title="' . dol_escape_htmltag($export) . '" aria-label="' . dol_escape_htmltag($export) . '"><span class="fas fa-file-csv" aria-hidden="true"></span> CSV</a>';
	$label = $langs->trans($print ? 'KreaBankMonthlyPrint' : 'KreaBankMonthlyPrintView');
	$html .= $print ? '<button type="button" class="button" id="kb-monthly-print"' : '<a class="button" href="' . dol_escape_htmltag(kreabankMonthlyReportUrl($state, array('print_report'=>1))) . '"';
	return $html . ' aria-label="' . dol_escape_htmltag($label) . '" title="' . dol_escape_htmltag($label) . '"><span class="fas fa-print" aria-hidden="true"></span>' . ($print ? '</button>' : '</a>') . '</div>';
}

function kreabankMonthlyViewsHtml($state, $langs)
{
	$html = '<nav class="kb-view-switch kb-monthly-views" aria-label="' . dol_escape_htmltag($langs->trans('KreaBankMonthlyNav')) . '">';
	foreach (array('balances'=>'Balances','summary'=>'Summary','checks'=>'Checks','bank_pending'=>'BankPending','ledger_pending'=>'LedgerPending','bank'=>'Bank','ledger'=>'Ledger') as $key=>$label) {
		$html .= '<a' . (kreabankMonthlyReportView($state['view']) === $key ? ' class="active" aria-current="page"' : '') . ' href="' . dol_escape_htmltag(kreabankMonthlyReportUrl($state, array('view'=>$key))) . '" title="' . dol_escape_htmltag($langs->trans('KreaBankMonthly' . $label)) . '">' . $langs->trans('KreaBankMonthly' . $label . 'Short') . '</a>';
	}
	return $html . '</nav>';
}

/** An entered balance belongs only to the account and month explicitly displayed. */
function kreabankMonthlyScopedBalance($value, $accountId, $month, $balanceAccount, $balanceMonth)
{
	return (int) $accountId > 0 && (int) $accountId === (int) $balanceAccount && (string) $month === (string) $balanceMonth ? trim((string) $value) : '';
}

function kreabankMonthlyAmount($value)
{
	return $value === null ? '—' : kreabankPrice($value);
}

/** Localized source identity for display/search only; report and CSV IDs stay original. */
function kreabankMonthlySourceLabel($row, $langs)
{
	$storage = (string) ($row['source_storage'] ?? '');
	if (in_array($storage, array('staging','native','legacy_native'), true) && (int) ($row['rowid'] ?? 0) > 0) {
		return $langs->transnoentities($storage === 'staging' ? 'KreaBankReviewStatementLine' : 'KreaBankHistoryNativeEntry') . ' #' . (int) $row['rowid'];
	}
	return (string) $row['id'];
}

/** Reuse the reserved-segment native translator without changing arbitrary bank prose. */
function kreabankMonthlyDetailFacts($row, $langs)
{
	return array('date'=>$row['date'],'description'=>kreabankWorklistNativeLabel($row['description'], $langs) . ' ' . kreabankMonthlySourceLabel($row, $langs),
		'reference'=>kreabankWorklistNativeLabel($row['reference'], $langs) . ($row['native_id'] > 0 ? ' #' . $row['native_id'] : ''),
		'amount'=>$row['amount'],'balance'=>$row['balance'],'status'=>$row['status'],'age'=>in_array($row['status'],array('matched','opening'),true) ? null : $row['age']);
}

/** Prepare the map once so screen, print and CSV use identical figures. */
function kreabankMonthlyMapRows($report)
{
	return array(
		array('BankClosing', $report['map']['bank_close']),
		array('LedgerPendingAmount', $report['ledger']['pending_amount']),
		array('BankAdjusted', $report['map']['bank_adjusted']),
		array('LedgerClosing', $report['map']['ledger_close']),
		array('BankPendingAmount', $report['bank']['pending_amount']),
		array('LedgerAdjusted', $report['map']['ledger_adjusted']),
		array('UnexplainedDifference', $report['map']['difference']),
		array('OpeningDifference', $report['map']['opening_difference']),
		array('MonthDifference', $report['map']['month_difference'])
	);
}

/** Complete compact summary/check tables never acquire filters, sorting or pagination. */
function kreabankMonthlyStaticTableHtml($rows, $key, $labels, $langs, $render)
{
	$html = '<div class="div-table-responsive"><table id="kb-monthly-table-' . $key . '" class="tagtable liste centpercent kb-monthly-static-table"><thead><tr class="liste_titre">';
	foreach ($labels as $field=>$label) {
		$html .= '<th scope="col"' . (in_array($field,array('amount','bank','ledger','value'),true) ? ' class="right"' : '') . '>' . dol_escape_htmltag($label) . '</th>';
	}
	$html .= '</tr></thead><tbody>';
	foreach ($rows as $row) { $html .= $render($row, ''); }
	if (!$rows) { $html .= '<tr><td colspan="' . count($labels) . '">' . $langs->trans('KreaBankMonthlyEmpty') . '</td></tr>'; }
	return $html . '</tbody></table></div>';
}

function kreabankMonthlySummaryHtml($report, $langs, $state = array(), $print = false)
{
	$label = static function ($key) use ($langs) { return $langs->transnoentities('KreaBankMonthly' . $key); };
	$html = '<section id="kb-monthly-summary" aria-label="' . dol_escape_htmltag($label('Summary')) . '"><div class="kb-monthly-summary-grid"><div class="kb-monthly-map">';
	$html .= '<h2>' . $langs->trans('KreaBankMonthlySummary') . '</h2>';
	$html .= kreabankMonthlyStaticTableHtml(kreabankMonthlyMapRows($report), 'map',
		array('label'=>$label('Summary'),'amount'=>$report['account']['currency_code']), $langs, static function ($row, $extra) use ($langs) {
		$highlight = in_array($row[0], array('BankAdjusted', 'LedgerAdjusted', 'UnexplainedDifference'), true);
		return '<tr class="' . ($highlight ? 'liste_total' : 'oddeven') . '"><th scope="row">' . $langs->trans('KreaBankMonthly' . $row[0]) . '</th><td class="right nowrap">' . kreabankMonthlyAmount($row[1]) . '</td>' . $extra . '</tr>';
	});
	$html .= '<p class="opacitymedium">' . $langs->trans('KreaBankMonthlyBalanceBasis', $report['map']['balance_date'] ?: '—') . '</p></div><div class="kb-monthly-indicators">';
	$html .= '<h3>' . $langs->trans('KreaBankMonthlyIndicators') . '</h3>';
	$indicators = array();
	foreach (array('count' => 'MovementCount', 'matched' => 'MatchedCount', 'pending' => 'PendingCount', 'over30' => 'Over30', 'over90' => 'Over90') as $key => $label) {
		$indicators[] = array('label'=>$langs->transnoentities('KreaBankMonthly' . $label),'bank'=>$report['bank'][$key],'ledger'=>$report['ledger'][$key]);
	}
	$html .= kreabankMonthlyStaticTableHtml($indicators, 'indicators',
		array('label'=>$langs->transnoentities('KreaBankMonthlyIndicators'),'bank'=>$langs->transnoentities('KreaBankMonthlyBank'),'ledger'=>$langs->transnoentities('KreaBankMonthlyLedger')), $langs, static function ($row, $extra) {
			return '<tr class="oddeven"><th scope="row">' . dol_escape_htmltag($row['label']) . '</th><td class="right">' . (int) $row['bank'] . '</td><td class="right">' . (int) $row['ledger'] . '</td>' . $extra . '</tr>';
		});
	return $html . '</div></div></section>';
}

function kreabankMonthlyChecksHtml($report, $langs, $state = array(), $print = false)
{
	$html = '<section id="kb-monthly-checks" aria-label="' . dol_escape_htmltag($langs->transnoentities('KreaBankMonthlyChecks')) . '">';
	$html .= '<h2>' . $langs->trans('KreaBankMonthlyChecks') . '</h2>';
	$html .= kreabankMonthlyStaticTableHtml($report['checks'], 'checks',
		array('label'=>$langs->transnoentities('KreaBankMonthlyCheck'),'status'=>$langs->transnoentities('Status'),'value'=>$langs->transnoentities('Value')), $langs, static function ($check, $extra) use ($langs) {
		$status = in_array($check['status'], array('ok', 'warning', 'error'), true) ? $check['status'] : 'error';
		$value = $check['value'] !== '' && in_array($check['key'], array('OpeningDifference', 'UnexplainedDifference', 'BankBalance'), true) ? kreabankPrice($check['value']) : dol_escape_htmltag($check['value']);
		return '<tr class="oddeven"><th scope="row">' . $langs->trans('KreaBankMonthlyCheck' . $check['key']) . '</th><td><span class="badge ' . ($status === 'ok' ? 'badge-status4' : ($status === 'error' ? 'badge-status8' : 'badge-status1')) . '">' . $langs->trans('KreaBankMonthlyCheckState' . ucfirst($status)) . '</span></td><td class="right">' . $value . '</td>' . $extra . '</tr>';
	});
	return $html . '</section>';
}

/** Paginate presentation only; totals and export always use the complete report. */
function kreabankMonthlyDetailsHtml($rows, $side, $title, $state, $langs, $print = false, $listKey = '')
{
	$langs->loadLangs(array('banks','bills','salaries','trips','donations','loan'));
	$listKey = $listKey ?: $side;
	$html = '<section id="kb-monthly-' . $listKey . '" aria-label="' . dol_escape_htmltag($title) . '">';
	if ($print) { $html .= '<h2>' . dol_escape_htmltag($title) . ' <span class="opacitymedium">(' . count($rows) . ')</span></h2>'; }
	$labels = array();
	foreach (array('date'=>'Date','description'=>'Description','reference'=>'Reference','amount'=>'Amount','balance'=>$side === 'ledger' ? 'RunningBalance' : 'Balance','status'=>'Status','age'=>'Age') as $field=>$label) { $labels[$field] = $langs->transnoentities('KreaBankMonthly' . $label); }
	$html .= kreabankMonthlyListHtml($rows, $listKey, $title, $labels, $state, $langs, static function ($row) use ($langs) {
		return kreabankMonthlyDetailFacts($row, $langs);
	}, static function ($row, $extra) use ($langs) {
		$html = '<tr class="oddeven"><td class="nowrap">' . dol_escape_htmltag($row['date'] ?: '—') . ($row['carried'] ? '<br><small>' . $langs->trans('KreaBankMonthlyCarried') . '</small>' : '') . '</td><td class="kb-monthly-description">' . dol_escape_htmltag(kreabankWorklistNativeLabel($row['description'], $langs)) . '<br><small>' . dol_escape_htmltag(kreabankMonthlySourceLabel($row, $langs)) . '</small></td><td class="kb-monthly-reference">' . dol_escape_htmltag(kreabankWorklistNativeLabel($row['reference'], $langs));
		if ($row['native_id'] > 0) {
			$html .= '<br><a href="' . dol_escape_htmltag(dol_buildpath('/compta/bank/line.php?rowid=' . (int) $row['native_id'], 1)) . '">#' . (int) $row['native_id'] . '</a>';
		}
		return $html . '</td><td class="right nowrap">' . kreabankMonthlyAmount($row['amount']) . ' ' . dol_escape_htmltag($row['currency']) . '</td><td class="right nowrap">' . kreabankMonthlyAmount($row['balance']) . '</td><td>' . $langs->trans('KreaBankMonthlyState' . ucfirst($row['status'])) . '</td><td class="right">' . (in_array($row['status'],array('matched','opening'),true) ? '—' : (int) $row['age']) . '</td>' . $extra . '</tr>';
	}, $print);
	return $html . '</section>';
}

function kreabankMonthlyReportHtml($report, $state, $langs, $print = false)
{
	$view = kreabankMonthlyReportView($state['view'] ?? '');
	$html = '<div class="kb-monthly-report"><div class="kb-monthly-status"><span class="kb-monthly-identity"><strong>' . dol_escape_htmltag($report['company'] ?? '') . '</strong> · ' . dol_escape_htmltag($report['account']['ref']) . ' · ' . dol_escape_htmltag($report['account']['currency_code']) . ' · ' . dol_escape_htmltag($report['month']) . '</span>';
	$errors = count(array_filter($report['checks'], static function ($check) { return $check['status'] === 'error'; }));
	$warnings = count(array_filter($report['checks'], static function ($check) { return $check['status'] === 'warning'; }));
	$html .= '<a class="badge ' . ($errors ? 'badge-status8' : ($warnings ? 'badge-status1' : 'badge-status4')) . '" href="' . dol_escape_htmltag(kreabankMonthlyReportUrl($state, array('view'=>'checks'))) . '">' . $langs->trans('KreaBankMonthlyCheckCounts', $errors, $warnings) . '</a></div>';
	$html .= '<details class="kb-help kb-monthly-basis"' . ($print ? ' open' : '') . '><summary>' . $langs->trans('KreaBankMonthlyEvidenceShort', dol_escape_htmltag($report['end'])) . '</summary><p>' . $langs->trans('KreaBankMonthlyCurrentEvidence', dol_escape_htmltag($report['end']), dol_escape_htmltag($report['generated_at'])) . '</p><p>' . $langs->trans('KreaBankMonthlyLedgerBasis') . '</p></details>';
	if ($view === 'balances') { return $html . kreabankMonthlyBalancesHtml($report, $state, $langs, $print) . '</div>'; }
	if (!$print) {
		$html .= '<dl class="kb-monthly-totals">';
		foreach (array('bank_close'=>'BankCloseShort','ledger_close'=>'LedgerCloseShort','difference'=>'DifferenceShort') as $key=>$label) {
			$html .= '<div><dt>' . $langs->trans('KreaBankMonthly' . $label) . '</dt><dd>' . kreabankMonthlyAmount($report['map'][$key]) . ' <small>' . dol_escape_htmltag($report['account']['currency_code']) . '</small></dd>';
			if ($key === 'bank_close') { $html .= '<span class="opacitymedium">' . $langs->trans($report['map']['balance_confirmed'] ? 'KreaBankMonthlyConfirmed' : 'KreaBankMonthlyUnconfirmed') . '</span>'; }
			$html .= '</div>';
		}
		$html .= '</dl>';
	}
	if ($print || $view === 'summary') { $html .= kreabankMonthlySummaryHtml($report, $langs, $state, $print); }
	if ($print || $view === 'checks') { $html .= kreabankMonthlyChecksHtml($report, $langs, $state, $print); }
	if ($print || $view === 'bank_pending') {
		$html .= kreabankMonthlyDetailsHtml($report['bank_pending'], 'bank', $langs->transnoentities('KreaBankMonthlyBankPending'), $state, $langs, $print, 'bank_pending');
	}
	if ($print || $view === 'ledger_pending') {
		$html .= kreabankMonthlyDetailsHtml($report['ledger_pending'], 'ledger', $langs->transnoentities('KreaBankMonthlyLedgerPending'), $state, $langs, $print, 'ledger_pending');
	}
	if (!$print && in_array($view, array('bank', 'ledger'), true)) {
		$html .= kreabankMonthlyDetailsHtml($report[$view . '_rows'], $view, $langs->transnoentities('KreaBankMonthly' . ucfirst($view)), $state, $langs);
	}
	return $html . '</div>';
}

/** Prevent spreadsheet formula execution in exported untrusted evidence cells. */
function kreabankMonthlyCsvCell($value)
{
	return kreabankCsvCell($value);
}

/** CSV retains exact decimal strings; never export rounded display amounts. */
function kreabankMonthlyReportCsv($stream, $report, $langs)
{
	$write = static function ($cells) use ($stream) { fputcsv($stream, array_map('kreabankMonthlyCsvCell', $cells), ';', '"', ''); };
	$write(array($langs->transnoentities('KreaBankMonthlyTitle'), $report['company'] ?? '', $report['entity'] ?? '', $report['account']['ref'], $report['account']['rowid'], $report['month'], $report['account']['currency_code']));
	$write(array($langs->transnoentities('KreaBankMonthlyCurrentEvidence', $report['end'], $report['generated_at'])));
	$write(array($langs->transnoentities('KreaBankMonthlyLedgerBasis')));
	$write(array($langs->transnoentities('KreaBankMonthlyBalanceBasis', $report['map']['balance_date'] ?: '—'), $report['map']['balance_confirmed'] ? $langs->transnoentities('KreaBankMonthlyConfirmed') : $langs->transnoentities('KreaBankMonthlyUnconfirmed')));
	foreach (kreabankMonthlyMapRows($report) as $row) { $write(array($langs->transnoentities('KreaBankMonthly' . $row[0]), $row[1])); }
	$write(array($langs->transnoentities('KreaBankMonthlyIndicators'), $langs->transnoentities('KreaBankMonthlyBank'), $langs->transnoentities('KreaBankMonthlyLedger')));
	foreach (array('count' => 'MovementCount', 'matched' => 'MatchedCount', 'pending' => 'PendingCount', 'over30' => 'Over30', 'over90' => 'Over90') as $key => $label) {
		$write(array($langs->transnoentities('KreaBankMonthly' . $label), $report['bank'][$key], $report['ledger'][$key]));
	}
	$write(array());
	foreach ($report['checks'] as $check) { $write(array($langs->transnoentities('KreaBankMonthlyCheck' . $check['key']), $langs->transnoentities('KreaBankMonthlyCheckState' . ucfirst($check['status'])), $check['value'])); }
	foreach (array('bank_rows' => 'Bank', 'ledger_rows' => 'Ledger', 'bank_pending' => 'BankPending', 'ledger_pending' => 'LedgerPending') as $key => $label) {
		$write(array());
		$write(array($langs->transnoentities('KreaBankMonthly' . $label)));
		$write(array('ID', $langs->transnoentities('KreaBankMonthlyDate'), $langs->transnoentities('KreaBankMonthlyDescription'), $langs->transnoentities('KreaBankMonthlyReference'), $langs->transnoentities('KreaBankMonthlyAmount'), $langs->transnoentities('KreaBankCurrency'), $langs->transnoentities(strpos($key, 'ledger_') === 0 ? 'KreaBankMonthlyRunningBalance' : 'KreaBankMonthlyBalance'), $langs->transnoentities('Status'), $langs->transnoentities('KreaBankMonthlyAge'), $langs->transnoentities('KreaBankMonthlyCarried')));
		foreach ($report[$key] as $row) {
			$write(array($row['id'], $row['date'], $row['description'], $row['reference'], $row['amount'], $row['currency'], $row['balance'], $langs->transnoentities('KreaBankMonthlyState' . ucfirst($row['status'])), $row['age'], $row['carried'] ? '1' : '0'));
		}
	}
}
