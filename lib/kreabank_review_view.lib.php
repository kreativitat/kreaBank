<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/** Read-only presentation of one already prepared, signed review pair. */
function kreabankReviewPairHtml($row, $langs, $companyName, $resolveUrl)
{
	$line = $row['line'];
	$target = $row['suggestion'];
	$type = (string) $target['doc_type'];
	$invoice = in_array($type, array('supplier_invoice', 'customer_invoice'), true);
	$kind = $type === 'tax' ? 'Tax' : ($type === 'native_bank' ? 'Bank' : ($invoice ? 'Invoice' : 'Payment'));
	$currency = dol_escape_htmltag((string) $line['currency']);
	$sourceAmount = (float) $line['amount'];
	$outstanding = max(0, abs($sourceAmount) - abs((float) ($line['allocated_amount'] ?? 0)));
	if ($outstanding <= 0.00001) { $outstanding = abs($sourceAmount); }
	$allocation = (float) $row['allocation'];
	$title = trim((string) ($line['description'] ?? '')) ?: (string) ($line['counterparty_name'] ?? '');
	$reference = kreabankBulkSuggestionReference($target);
	$counterparty = (string) ($target['thirdparty_name'] ?? '');
	if ($type === 'native_bank') {
		$reference = kreabankNativeBankLabel($reference, $langs);
		$counterparty = kreabankNativeBankLabel($counterparty, $langs);
	}
	$url = $resolveUrl($type, (int) $target['doc_id']);
	$formatDisplayDate = static function ($value) {
		$ts = strtotime((string) $value);
		if ($ts === false || $ts <= 0) { return '—'; }
		return dol_print_date($ts, 'day');
	};
	$fact = static function ($label, $value) use ($langs) {
		return '<div><dt>' . $langs->trans($label) . '</dt><dd>' . $value . '</dd></div>';
	};
	ob_start();
	print '<div class="kb-review-pair">';
	print '<section class="kb-review-source" aria-labelledby="kb-review-source-' . (int) $line['rowid'] . '">';
	print '<span class="kb-review-eyebrow">' . $langs->trans('KreaBankReviewStatementLine') . '</span>';
	print '<div class="kb-review-card-title"><h2 id="kb-review-source-' . (int) $line['rowid'] . '">' . dol_escape_htmltag($title) . '</h2><strong class="kb-review-money">' . ($sourceAmount > 0 ? '+' : '') . kreabankPrice($sourceAmount) . ' <small>' . $currency . '</small></strong></div>';
	print '<dl class="kb-review-facts">';
	print $fact('BankAccount', dol_escape_htmltag((string) ($line['bank_account_ref'] ?? '')));
	print $fact('KreaBankDate', $formatDisplayDate(($line['operation_date'] ?? '') ?: ($line['value_date'] ?? '')));
	print $fact('KreaBankWorkspaceRemaining', kreabankPrice($outstanding) . ' ' . $currency);
	print '</dl></section>';
	print '<section class="kb-review-target" aria-labelledby="kb-review-target-' . (int) $line['rowid'] . '">';
	print '<span class="kb-review-eyebrow">' . $langs->trans('KreaBankReviewExisting' . $kind) . '</span>';
	print '<div class="kb-review-card-title"><h3 id="kb-review-target-' . (int) $line['rowid'] . '">';
	if ($url !== '') { print '<a href="' . dol_escape_htmltag($url) . '" target="_blank" rel="noopener">' . dol_escape_htmltag($reference) . '</a>'; }
	else { print dol_escape_htmltag($reference); }
	print '</h3><strong class="kb-review-money">' . kreabankPrice((float) $target['amount_open']) . ' <small>' . $currency . '</small></strong></div>';
	if (isModEnabled('degema') && !empty($target['supplier_payment_id'])) {
		print '<a class="kb-review-alternative" href="' . dol_escape_htmltag(dol_buildpath('/custom/degema/paiement_card.php?id=' . (int) $target['supplier_payment_id'], 1)) . '" target="_blank" rel="noopener">' . $langs->trans('KreaBankPaymentBadge') . ' #' . (int) $target['supplier_payment_id'] . '</a>';
	}
	print '<dl class="kb-review-facts">';
	print $fact('BankAccount', dol_escape_htmltag(trim((string) ($target['bank_account_ref'] ?? '')) ?: '—'));
	print $fact('KreaBankDate', $formatDisplayDate($target['doc_date'] ?? ''));
	print $fact('KreaBankCounterparty', dol_escape_htmltag($counterparty) ?: '—');
	print '</dl><p class="kb-review-consequence">' . $langs->trans('KreaBankReviewConsequence' . $kind) . '</p>';
	print '</section></div>';
	print '<div class="kb-review-allocation"><span>' . $langs->trans('KreaBankReviewAllocation') . ': <strong>' . kreabankPrice($allocation) . ' ' . $currency . '</strong></span><span>' . $langs->trans('KreaBankWorkspaceDifference') . ': <strong>' . kreabankPrice($outstanding - $allocation) . ' ' . $currency . '</strong></span></div>';
	print '<details class="kb-review-evidence"><summary>' . $langs->trans('KreaBankBulkMatchEvidence') . ' · <span class="krea-bulk-score">' . $langs->trans('KreaBankBulkMatchScore') . ' ' . (int) $target['score'] . '</span> <span class="kb-review-confidence">' . $langs->trans(empty($row['is_bulk_safe']) ? 'KreaBankBulkMatchLowConfidence' : 'KreaBankReviewStrongEvidence') . '</span></summary>';
	print '<dl class="kb-review-facts">' . $fact('KreaBankAccountOwner', dol_escape_htmltag($companyName));
	foreach (array('counterparty_name'=>'KreaBankCounterparty', 'counterparty_iban'=>'IBAN', 'payment_reference'=>'KreaBankReference', 'bank_reference'=>'Ref') as $key=>$label) {
		if (trim((string) ($line[$key] ?? '')) !== '') { print $fact($label, dol_escape_htmltag((string) $line[$key])); }
	}
	$reasons = array();
	foreach ((array) ($target['details'] ?? array()) as $reason) {
		$key = 'KreaBankMatchReason_' . $reason;
		$reasons[] = $langs->trans($key) === $key ? $reason : $langs->trans($key);
	}
	print $fact('KreaBankBulkMatchReasons', dol_escape_htmltag(implode(', ', $reasons)));
	print '</dl></details>';
	return ob_get_clean();
}
