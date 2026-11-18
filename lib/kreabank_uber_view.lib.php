<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
require_once __DIR__ . '/kreabank_worklist_view.lib.php';

/** Fixed, reviewed financial evidence only; no candidate lookup or execution. */
function kreabankUberReviewHtml($review, $returnUrl, $token, $langs)
{
	$e = 'kreabankWorklistEscape';
	$t = static function ($key) use ($langs, $e) { return $e(kreabankWorklistTranslate($langs, $key)); };
	$source = $review['source']; $payout = $review['payout']; $settlement = $payout['settlement'];
	$currency = $source['currency'];
	$money = static function ($cents) use ($currency) { return kreabankWorklistMoney(KreaBankUberSettings::money($cents), $currency); };
	$account = static function ($value) { return trim($value['ref'] . ' — ' . $value['label']); };
	$payoutUrl = dol_buildpath('/custom/kreaeats/kreaeatsindex.php', 1) . '?id=' . (int) $settlement['rowid'] . '&view=settlement';
	$html = '<div class="fichecenter"><div class="fichehalfleft"><table class="border centpercent">';
	$rows = array('KreaBankUberRestaurant'=>$e($payout['store']['label']),
		'KreaBankUberPayoutReference'=>'<a href="' . $e($payoutUrl) . '" target="_blank" rel="noopener noreferrer">' . $e($settlement['payout_ref']) . '</a>',
		'KreaBankUberReportPeriod'=>$e($payout['batch']['date_start'] . ' — ' . $payout['batch']['date_end']),
		'KreaBankUberSales'=>$money($settlement['gross']), 'KreaBankUberFees'=>$money($settlement['fees']),
		'KreaBankUberAdjustments'=>$money($settlement['adjustments']), 'KreaBankUberNet'=>$money($settlement['net']));
	foreach ($rows as $label=>$value) { $html .= '<tr><td class="titlefield">' . $t($label) . '</td><td>' . $value . '</td></tr>'; }
	$html .= '</table></div><div class="fichehalfright"><div class="ficheaddleft"><table class="border centpercent">';
	$rows = array('KreaBankWorklistStatementAccount'=>$e($account($payout['receiving_account'])),
		'KreaBankDate'=>$e(kreabankWorklistDate($source['operation_date'])),
		'Amount'=>kreabankWorklistMoney($source['amount'], $currency), 'Description'=>$e($source['description']),
		'KreaBankUberClearingAccount'=>$e($account($payout['clearing_account'])));
	foreach ($rows as $label=>$value) { $html .= '<tr><td class="titlefield">' . $t($label) . '</td><td>' . $value . '</td></tr>'; }
	$html .= '</table></div></div></div><div class="clearboth"></div>';
	$html .= '<p>' . $t($review['transfer']['state'] === 'reuse' ? 'KreaBankUberReuseEffect' : 'KreaBankUberCreateEffect') . '</p>';
	$html .= '<div class="div-table-responsive"><table class="liste centpercent"><thead><tr class="liste_titre"><th scope="col">' . $t('BankAccount') . '</th><th scope="col" class="right">' . $t('Amount') . '</th><th scope="col">' . $t('KreaBankDocument') . '</th></tr></thead><tbody>';
	foreach (array(array('clearing_account', -1, 'clearing_line_id'), array('receiving_account', 1, 'bank_line_id')) as $leg) {
		$id = (int) ($review['transfer'][$leg[2]] ?? 0);
		$html .= '<tr class="oddeven"><td>' . $e($account($payout[$leg[0]])) . '</td><td class="right nowrap">' . $money($leg[1] * (int) $settlement['net']) . '</td><td>';
		$html .= $id ? '<a href="' . $e(dol_buildpath('/compta/bank/line.php', 1) . '?rowid=' . $id) . '" target="_blank" rel="noopener noreferrer">#' . $id . '</a>' : $t('KreaBankUberNewMovement');
		$html .= '</td></tr>';
	}
	$html .= '</tbody></table></div><p class="opacitymedium">' . $t('KreaBankUberDocumentsSeparate') . '</p>';
	$html .= '<form method="post" action="' . $e(dol_buildpath('/custom/kreabank/ubersettlement.php', 1)) . '">';
	$html .= kreabankWorklistFields(array('token'=>$token, 'action'=>'confirm_uber', 'review'=>$review['nonce']));
	$html .= '<p><label><input type="checkbox" name="confirmed" value="1" required> ' . $t('KreaBankUberConfirmIdentity') . '</label></p>';
	$html .= '<div class="center"><button type="submit" class="button button-save">' . $t('KreaBankUberConfirmSettlement') . '</button> <a class="button button-cancel" href="' . $e($returnUrl) . '">' . $t('Cancel') . '</a></div></form>';
	return $html;
}
