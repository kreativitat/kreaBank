<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/** Workbook-style report; all figures and pending rows come from the shared report. */
function kreabankMonthlyBalancesLabels($langs)
{
	$labels = array();
	foreach (array('date'=>'Date','description'=>'Description','reference'=>'ReferenceType','amount'=>'SignedAmount',
		'nature'=>'Nature','origin_month'=>'OriginMonth','original_id'=>'OriginalId','comment'=>'Comment') as $field=>$label) {
		$labels[$field] = $langs->transnoentities('KreaBankMonthly' . $label);
	}
	return $labels;
}

/** Direction is explicit signed evidence, never an inferred payment method or document. */
function kreabankMonthlyBalancesFacts($row, $langs, $accountCurrency)
{
	$sign = $row['amount'] !== null ? kreabankMonthlyListDecimalCompare($row['amount'], '0') : null;
	return array('date'=>$row['date'], 'description'=>kreabankWorklistNativeLabel($row['description'], $langs),
		'reference'=>kreabankWorklistNativeLabel($row['reference'], $langs), 'amount'=>$row['amount'],
		'nature'=>$sign ? $langs->transnoentities($sign > 0 ? 'KreaBankMonthlyReceipt' : 'KreaBankMonthlyPayment') : '',
		'origin_month'=>$row['date'] !== '' ? substr($row['date'], 0, 7) : '',
		'original_id'=>kreabankMonthlySourceLabel($row, $langs),
		'comment'=>$row['currency'] !== $accountCurrency ? $langs->transnoentities('KreaBankMonthlyCurrencyReview', $row['currency'] ?: '—')
			: ($row['status'] === 'review' ? $langs->transnoentities('KreaBankMonthlyEvidenceReview') : ''));
}

function kreabankMonthlyBalancesHtml($report, $state, $langs, $print = false)
{
	$langs->loadLangs(array('banks','bills','salaries','trips','donations','loan'));
	$label = static function ($key) use ($langs) { return $langs->transnoentities('KreaBankMonthly' . $key); };
	$currency = dol_escape_htmltag($report['account']['currency_code']);
	$html = '<section class="kb-monthly-balances" aria-labelledby="kb-monthly-balances-title"><h2 id="kb-monthly-balances-title">' . $langs->trans('KreaBankMonthlyBalances') . ' <span class="opacitymedium">' . dol_escape_htmltag(substr($report['month'], 0, 4)) . ' · ' . $currency . '</span></h2>';
	$html .= kreabankMonthlyStaticTableHtml($report['balances'], 'balances',
		array('date'=>$label('ClosingDate'),'bank'=>$label('BankCloseShort'),'ledger'=>$label('LedgerCloseShort'),'amount'=>$label('RawDifference'),'comment'=>$label('Comment')),
		$langs, static function ($row) use ($langs) {
			$date = DateTimeImmutable::createFromFormat('!Y-m-d', $row['date'], new DateTimeZone('UTC'))->format('d/m/Y');
			$html = '<tr class="oddeven"><th scope="row" class="nowrap">' . $date . '</th>';
			foreach (array('bank','ledger','difference') as $field) {
				$basis = $langs->transnoentities($row['confirmed'] ? 'KreaBankMonthlyConfirmed' : 'KreaBankMonthlyUnconfirmed') . ' · ' . ($row['balance_date'] ?: '—');
				if (!$row['confirmed'] && $row['snapshot_date'] !== '') { $basis .= ' · ' . $langs->transnoentities('KreaBankMonthlyBalanceSnapshot', $row['snapshot_date']); }
				$title = $field === 'bank' ? ' title="' . dol_escape_htmltag($basis) . '"' : '';
				$html .= '<td class="right nowrap"' . $title . '>' . kreabankMonthlyAmount($row[$field]) . '</td>';
			}
			return $html . '<td>' . ($row['comment'] !== '' ? $langs->trans('KreaBankMonthly' . $row['comment']) : '') . '</td></tr>';
		});
	$html .= '<p class="opacitymedium kb-monthly-balances-note">' . $langs->trans('KreaBankMonthlyBalancesHelp') . '</p></section>';
	$title = $langs->transnoentities('KreaBankMonthlyBalancesPending', $report['account']['ref']);
	$html .= '<section class="kb-monthly-balances-pending" aria-label="' . dol_escape_htmltag($title) . '">';
	if ($print) { $html .= '<h2>' . dol_escape_htmltag($title) . '</h2>'; }
	$total = $report['bank_pending_total'];
	$footer = '<tfoot><tr class="liste_total"><th scope="row" colspan="3">' . $langs->trans('KreaBankMonthlyPendingTotal') . '</th><td class="right nowrap">' . kreabankMonthlyAmount($total) . ' ' . $currency . '</td><td colspan="' . ($print ? 4 : 5) . '"></td></tr></tfoot>';
	$html .= kreabankMonthlyListHtml($report['bank_pending'], 'balances_pending', $title, kreabankMonthlyBalancesLabels($langs), $state, $langs,
		static function ($row) use ($langs, $report) { return kreabankMonthlyBalancesFacts($row, $langs, $report['account']['currency_code']); },
		static function ($row, $extra) use ($langs, $report) {
			$facts = kreabankMonthlyBalancesFacts($row, $langs, $report['account']['currency_code']);
			$html = '<tr class="oddeven">';
			foreach ($facts as $field=>$value) {
				if ($field === 'date' && $value !== '') { $value = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'))->format('d/m/Y'); }
				$amountCurrency = $field === 'amount' && $row['currency'] !== $report['account']['currency_code'] ? ' ' . dol_escape_htmltag($row['currency']) : '';
				$html .= '<td' . ($field === 'amount' ? ' class="right nowrap"' : '') . '>' . ($field === 'amount' ? kreabankMonthlyAmount($value) . $amountCurrency : dol_escape_htmltag($value !== '' ? $value : '—')) . '</td>';
			}
			return $html . $extra . '</tr>';
		}, $print, $footer);
	return $html . '<p class="opacitymedium kb-monthly-balances-note">' . $langs->trans('KreaBankMonthlyBalancesPendingHelp') . '</p></section>';
}

/** Export the two complete workbook sections, with editable blank comment cells. */
function kreabankMonthlyBalancesCsv($stream, $report, $langs)
{
	$write = static function ($cells) use ($stream) { fputcsv($stream, array_map('kreabankMonthlyCsvCell', $cells), ';', '"', ''); };
	$label = static function ($key) use ($langs) { return $langs->transnoentities('KreaBankMonthly' . $key); };
	$write(array($label('Balances'), $report['company'] ?? '', $report['account']['ref'], $report['month'], $report['account']['currency_code']));
	$write(array($langs->transnoentities('KreaBankMonthlyCurrentEvidence', $report['end'], $report['generated_at'])));
	$write(array($label('BalancesHelp')));
	$write(array($label('ClosingDate'),$label('BankCloseShort'),$label('LedgerCloseShort'),$label('RawDifference'),$label('Comment')));
	foreach ($report['balances'] as $row) {
		$comment = $row['comment'] !== '' ? $label($row['comment']) : '';
		if (!$row['confirmed'] && $row['snapshot_date'] !== '') { $comment .= ($comment !== '' ? ' · ' : '') . $langs->transnoentities('KreaBankMonthlyBalanceSnapshot', $row['snapshot_date']); }
		$write(array($row['date'], $row['bank'], $row['ledger'], $row['difference'], $comment));
	}
	$write(array());
	$write(array($langs->transnoentities('KreaBankMonthlyBalancesPending', $report['account']['ref'])));
	$write(array_values(kreabankMonthlyBalancesLabels($langs)));
	foreach ($report['bank_pending'] as $row) {
		$facts = kreabankMonthlyBalancesFacts($row, $langs, $report['account']['currency_code']);
		// Export original evidence and stable storage IDs, without UI translations.
		$facts['description'] = $row['description'];
		$facts['reference'] = $row['reference'];
		$facts['original_id'] = $row['id'];
		$write(array_values($facts));
	}
	$write(array($label('PendingTotal'), '', '', $report['bank_pending_total']));
	$write(array($label('BalancesPendingHelp')));
}
