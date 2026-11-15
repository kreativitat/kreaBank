<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/** Prepared advice only; the controller signs the associated form and the service executes it. */
function kreabankRuleQuickCard($row, $formId, $langs)
{
	$action = $row['rule_creation']; $rule = $row['bank_rule']; $suggestion = $row['suggestion'];
	$html = '<article class="krea-doc-item"><div class="kb-candidate-heading"><span class="kb-candidate-date">'
		. dol_escape_htmltag(substr($row['line']['operation_date'], 0, 10)) . '</span><strong class="kb-candidate-amount kb-money">'
		. kreabankPrice($row['allocation']) . ' <small>' . dol_escape_htmltag($row['line']['currency']) . '</small></strong></div>'
		. '<strong>' . dol_escape_htmltag($action['label']) . '</strong><p class="krea-muted">'
		. dol_escape_htmltag($suggestion['thirdparty_name']) . '</p><p class="krea-muted">'
		. $langs->trans('KreaBankRuleEvidence', dol_escape_htmltag($rule['label'])) . '</p>';
	if (count($rule['conflicts']) > 1) { $html .= '<p class="krea-muted">' . $langs->trans('KreaBankRuleConflictEvidence', dol_escape_htmltag(implode(', ', array_column($rule['conflicts'], 'label')))) . '</p>'; }
	$html .= '<p>' . $langs->trans('KreaBankRulePaymentMode') . ': <strong>' . dol_escape_htmltag($action['payment_mode']) . '</strong></p>'
		. '<p class="krea-muted">' . $langs->trans('KreaBankRuleAccountingHints', dol_escape_htmltag($action['accounting_account']), kreabankPrice($action['vat'])) . '</p>'
		. '<p>' . $langs->trans('KreaBankWorkspaceConsequenceBank') . '</p>'
		. '<button type="submit" class="button kb-primary-action" form="' . dol_escape_htmltag($formId) . '">'
		. $langs->trans('KreaBankWorkspaceCreateBank') . '</button></article>';
	return $html;
}
