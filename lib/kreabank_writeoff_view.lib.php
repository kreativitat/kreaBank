<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/** Optional action within the existing invoice card. Prepared evidence only; no database access. */
function kreabankWriteOffOption($reviews, $formId, $langs)
{
	if (!$reviews) { return ''; }
	$first = reset($reviews); $currency = dol_escape_htmltag($first['line']['currency']);
	$html = '<details class="kb-account-move"><summary>' . $langs->trans('KreaBankWriteOffAction') . '</summary>'
		. '<p>' . $langs->trans('KreaBankWriteOffAmounts', kreabankPrice($first['writeoff']['bank_amount']) . ' ' . $currency,
			kreabankPrice($first['writeoff']['difference']) . ' ' . $currency) . '</p>'
		. '<p class="krea-muted">' . $langs->trans($first['writeoff']['invoice_type'] === 'supplier_invoice' ? 'KreaBankWriteOffSupplierOutcome' : 'KreaBankWriteOffCustomerOutcome') . '</p>'
		. '<p><label for="' . dol_escape_htmltag($formId) . '-reason">' . $langs->trans('KreaBankWriteOffReason') . '</label> '
		. '<select class="flat" style="max-width:100%" id="' . dol_escape_htmltag($formId) . '-reason" form="' . dol_escape_htmltag($formId) . '" name="writeoff_reason" required>'
		. '<option value="">' . $langs->trans('KreaBankWriteOffChooseReason') . '</option>';
	foreach ($reviews as $reason=>$review) {
		$html .= '<option value="' . dol_escape_htmltag($reason) . '">' . dol_escape_htmltag($langs->trans('KreaBankWriteOffReason_' . $reason) . ' — ' . $review['writeoff']['account']) . '</option>';
	}
	return $html . '</select></p><button type="submit" form="' . dol_escape_htmltag($formId) . '" class="button kb-primary-action">'
		. $langs->trans('KreaBankWriteOffAction') . '</button></details>';
}
