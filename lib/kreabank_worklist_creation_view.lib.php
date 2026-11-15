<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/** Render only reviewed, fixed source facts and the user's explicit native mode choice. */
function kreabankWorklistCreation($row, $line, $id, $model, $langs)
{
	$e = 'kreabankWorklistEscape';
	$t = static function ($key) use ($langs) { return kreabankWorklistEscape(kreabankWorklistTranslate($langs,$key)); };
	$form = $row['movement_form'] ?? array();
	if (!$form || empty($model['can_write'])) {
		return array('html'=>'<p role="status">' . $e($row['creation_notice'] ?? kreabankWorklistTranslate($langs,'KreaBankWorklistMovementUnavailable')) . '</p>', 'form'=>'');
	}
	$formId = $id . '-create-form';
	$source = $form['source'];
	$html = '<p>' . $t('KreaBankWorklistMovementEffect') . '</p><div class="kb-wl-totals">';
	foreach (array('KreaBankWorklistStatementAccount'=>$e($source['bank_account_ref']),'KreaBankDate'=>$e(kreabankWorklistDate($source['operation_date'])),'Amount'=>kreabankWorklistMoney($source['amount'],$source['currency'])) as $key=>$value) {
		$html .= '<div><span>' . $t($key) . '</span><strong>' . $value . '</strong></div>';
	}
	$html .= '</div><p>' . $e($source['description']) . '</p><div class="kb-wl-create-fields"><label for="' . $formId . '-mode">' . $t('PaymentMode') . '</label><select class="flat" id="' . $formId . '-mode" name="payment_mode" form="' . $formId . '" required><option value="">' . $t('Select') . '</option>';
	foreach ($form['modes'] as $code=>$label) {
		$translated = kreabankWorklistTranslate($langs,'PaymentTypeShort' . $code);
		if ($translated === 'PaymentTypeShort' . $code) { $translated = $label; }
		$html .= '<option value="' . $e($code) . '"' . (($form['payment_mode'] ?? '') === $code ? ' selected' : '') . '>' . $e($translated) . '</option>';
	}
	$html .= '</select><label for="' . $formId . '-note">' . $t('Note') . '</label><textarea class="flat" id="' . $formId . '-note" name="movement_note" form="' . $formId . '" rows="2" maxlength="1000">' . $e($form['note'] ?? '') . '</textarea></div>';
	$html .= '<div class="kb-wl-dialog-actions"><button type="submit" class="button" form="' . $formId . '">' . kreabankWorklistIcon('plus') . ' ' . $t('KreaBankWorklistCreateAndReconcile') . '</button></div>';
	return array('html'=>$html,'form'=>kreabankWorklistPostForm($formId,$form,$model));
}

/**
 * Row "more options": tax entry, supplier invoice and skip — the choices no other list tool offers.
 * Row controls are associated with one POST form rendered outside the filter form.
 */
function kreabankWorklistMore($row, $line, $id, $model, $langs)
{
	$e = 'kreabankWorklistEscape';
	$t = static function ($key) use ($langs) { return kreabankWorklistEscape(kreabankWorklistTranslate($langs,$key)); };
	$form = $row['more_form'] ?? array();
	if (!$form || empty($model['can_write'])) {
		return array('html'=>'<p role="status">' . $t('KreaBankWorklistMovementUnavailable') . '</p>', 'form'=>'');
	}
	$lineId = (int) $form['line_id'];
	// The shared supplier-invoice script binds to this form id.
	$formId = 'krea_quick_entry_form_' . $lineId;
	$modalId = 'krea_supplier_invoice_modal_' . $lineId;
	$html = '<div class="kb-wl-create-fields"><label for="' . $formId . '-label">' . $t('KreaBankQuickEntryLabel') . '</label><input class="flat" type="text" id="' . $formId . '-label" name="entry_label" form="' . $formId . '" maxlength="190" value="' . $e($form['label'] ?? '') . '">'
		. '<label for="' . $formId . '-note">' . $t('KreaBankReason') . '</label><textarea class="flat" id="' . $formId . '-note" name="entry_note" form="' . $formId . '" rows="2" maxlength="1000">' . $e($form['note'] ?? '') . '</textarea></div>';
	$html .= '<div class="kb-wl-dialog-actions"><button type="submit" class="button" form="' . $formId . '" name="entry_submit_action" value="skip_line">' . kreabankWorklistIcon('clock') . ' ' . $t('KreaBankSkipLine') . '</button>';
	if (!empty($form['tax'])) { $html .= '<button type="submit" class="button" form="' . $formId . '" name="entry_submit_action" value="tax_entry">' . kreabankWorklistIcon('plus') . ' ' . $t('KreaBankWorkspaceCreateTax') . '</button>'; }
	if (!empty($form['invoice'])) { $html .= '<button type="button" class="button" data-krea-supplier-modal-open="' . $modalId . '" aria-haspopup="dialog">' . kreabankWorklistIcon('plus') . ' ' . $t('KreaBankWorkspaceReviewInvoice') . '</button>'; }
	$html .= '</div>';
	$fields = (array) $form['fields'];
	$fields['token'] = (string) ($model['token'] ?? ''); $fields['action'] = $form['action'];
	$post = '<form method="post" action="' . $e($model['url']) . '" id="' . $formId . '" data-locale="' . $e(str_replace('_','-',$langs->defaultlang ?? 'en_US')) . '" data-initial-lines="' . $e(json_encode($form['lines'] ?? array())) . '">'
		. kreabankWorklistFields($fields) . '<input type="hidden" name="entry_amount" value="' . $e($form['amount']) . '">'
		. '<input type="hidden" name="entry_supplier_lookup" id="entry_supplier_lookup_' . $lineId . '" value=""><input type="hidden" name="entry_product_lines_json" id="' . $modalId . '_json" value="">';
	if (!empty($form['invoice'])) {
		$supplierRequired = kreabankWorklistTranslate($langs,'KreaBankQuickSupplierInvoiceSupplier');
		$supplierRequired = method_exists($langs,'transnoentitiesnoconv') ? $langs->transnoentitiesnoconv('ErrorFieldRequired',$supplierRequired) : $supplierRequired;
		$post .= '<div id="' . $modalId . '" class="krea-modal-overlay krea-supplier-lines-modal" role="dialog" aria-modal="true" aria-labelledby="' . $modalId . '_title" tabindex="-1" data-target-total="' . $e($form['amount']) . '"'
			. ' data-target-total-id="' . $modalId . '_target" data-current-total-id="' . $modalId . '_current" data-feedback-id="' . $modalId . '_feedback" data-confirm-id="' . $modalId . '_confirm" data-json-input-id="' . $modalId . '_json"'
			. ' data-supplier-lookup-id="entry_supplier_lookup_' . $lineId . '" data-msg-empty="' . $t('KreaBankQuickSupplierLinesNoSelection') . '" data-msg-incomplete="' . $t('KreaBankQuickSupplierLinesIncomplete') . '"'
			. ' data-msg-mismatch="' . $t('KreaBankQuickSupplierLinesMismatch') . '" data-msg-supplier="' . $e($supplierRequired) . '" style="display:none;"><div class="krea-modal-dialog">'
			. '<div class="krea-modal-header"><div class="krea-modal-title" id="' . $modalId . '_title">' . $t('KreaBankQuickSupplierLinesTitle') . '</div><button type="button" class="butAction krea-modal-close" aria-label="' . $t('Close') . '" data-krea-modal-close="' . $modalId . '">x</button></div>'
			. '<div class="kb-wl-create-fields"><label for="entry_supplier_socid">' . $t('KreaBankQuickSupplierInvoiceSupplier') . '</label><div>' . ($form['supplier_select'] ?? '') . '<small data-kb-supplier-feedback role="alert" hidden></small></div>'
			. '<label for="' . $formId . '-ref">' . $t('KreaBankQuickSupplierInvoiceRefSupplier') . '</label><input class="flat" type="text" id="' . $formId . '-ref" name="entry_ref_supplier" maxlength="180" value="' . $e($form['supplier_ref'] ?? '') . '"></div>'
			. '<div class="opacitymedium">' . $t('KreaBankQuickSupplierLinesHelp') . '</div><table class="nobordernopadding centpercent krea-product-line-table"><tr class="liste_titre"><th>' . $t('Ref') . '</th><th>' . $t('Description') . '</th><th class="center">' . $t('Qty') . '</th><th class="right">' . $t('Amount') . '</th><th class="center">' . $t('Delete') . '</th></tr>';
		foreach (array_values((array) ($form['product_selects'] ?? array())) as $slot => $select) {
			$post .= '<tr class="krea-product-line-row' . ($slot > 0 ? ' krea-product-line-row-hidden' : '') . '" data-krea-product-row="' . $slot . '"><td>' . $select . '</td>'
				. '<td><input class="flat minwidth200 krea-product-line-label" type="text" aria-label="' . $t('Description') . '" name="entry_product_line_label_' . $slot . '" value="" readonly></td>'
				. '<td class="center"><input class="flat right krea-product-line-qty" type="number" step="0.000001" min="0" aria-label="' . $t('Qty') . '" name="entry_product_line_qty_' . $slot . '" value="' . ($slot === 0 ? '1' : '') . '"></td>'
				. '<td class="right"><input class="flat right krea-product-line-amount" type="number" step="0.01" min="0" inputmode="decimal" aria-label="' . $t('Amount') . '" name="entry_product_line_amount_' . $slot . '" value="' . ($slot === 0 ? $e($form['amount']) : '') . '"></td>'
				. '<td class="center"><button type="button" class="butAction krea-product-line-remove" data-krea-product-remove="' . $slot . '" title="' . $t('Delete') . '" aria-label="' . $t('Delete') . '"' . ($slot === 0 ? ' style="display:none"' : '') . '>' . kreabankWorklistIcon('times') . '</button></td></tr>';
		}
		$post .= '</table><div class="kb-wl-batch-footer"><button type="button" class="butAction krea-product-line-add">' . $t('KreaBankQuickSupplierLinesAddRow') . '</button>'
			. '<span class="opacitymedium">' . $t('KreaBankQuickSupplierLinesTarget') . ': <strong id="' . $modalId . '_target">' . kreabankPrice((string) $form['amount']) . '</strong></span>'
			. '<span class="opacitymedium">' . $t('KreaBankQuickSupplierLinesCurrent') . ': <strong id="' . $modalId . '_current">' . kreabankPrice('0') . '</strong></span></div>'
			. '<div id="' . $modalId . '_feedback" role="status" aria-live="polite" class="warning" style="margin-top:8px;display:none"></div>'
			. '<div class="kb-wl-dialog-actions"><button type="button" class="butAction" data-krea-modal-close="' . $modalId . '">' . $t('KreaBankQuickSupplierLinesClose') . '</button>'
			. '<button class="button" type="button" name="entry_submit_action" value="supplier_invoice" id="' . $modalId . '_confirm">' . $t('KreaBankWorkspaceCreateInvoice') . '</button></div></div></div>';
	}
	// Existing-record choices use the common candidate card; each confirmation is its own signed POST.
	$review = ''; $forms = $post . '</form>'; $index = 0;
	$card = static function ($candidate, $actions) use ($e, $langs) {
		$notes = '';
		foreach ((array) ($candidate['notes'] ?? array()) as $note) { $notes .= '<span class="kb-wl-caption">' . $e($note) . '</span>'; }
		return '<div class="kb-wl-candidate"><div class="kb-wl-candidate-evidence">' . kreabankWorklistCandidate($candidate,$langs) . $notes . '</div><div class="kb-wl-candidate-actions">' . $actions . '</div></div>';
	};
	if (!empty($form['rule'])) {
		$actionId = $id . '-more-' . $index++;
		$review .= $card($form['rule'],'<button type="submit" class="button" form="' . $actionId . '">' . kreabankWorklistIcon('plus') . ' ' . $t('KreaBankWorkspaceCreateBank') . '</button>');
		$forms .= kreabankWorklistPostForm($actionId,$form['rule'],$model);
	}
	foreach ((array) ($form['writeoffs'] ?? array()) as $writeoff) {
		$actionId = $id . '-more-' . $index++;
		$options = '<option value="">' . $t('KreaBankWriteOffChooseReason') . '</option>';
		foreach ((array) $writeoff['reasons'] as $reason=>$label) { $options .= '<option value="' . $e($reason) . '">' . $e($label) . '</option>'; }
		$review .= $card($writeoff,'<details class="kb-wl-more-confirm"><summary>' . $t('KreaBankWriteOffAction') . '</summary><label for="' . $actionId . '-reason">' . $t('KreaBankWriteOffReason') . '</label>'
			. '<select class="flat" id="' . $actionId . '-reason" name="writeoff_reason" form="' . $actionId . '" required>' . $options . '</select>'
			. '<button type="submit" class="button" form="' . $actionId . '">' . kreabankWorklistIcon('check') . ' ' . $t('KreaBankWriteOffAction') . '</button></details>');
		$forms .= kreabankWorklistPostForm($actionId,$writeoff,$model);
	}
	foreach ((array) ($form['drafts'] ?? array()) as $draft) {
		$draft['consequence'] = 'KreaBankDraftInvoice'; $actions = '';
		if (!empty($draft['action'])) {
			$actionId = $id . '-more-' . $index++;
			$actions = '<details class="kb-wl-more-confirm"><summary>' . $t('KreaBankDraftInvoiceValidate') . '</summary><p>' . $e($draft['confirm'] ?? '') . '</p>'
				. '<button type="submit" class="button" form="' . $actionId . '">' . kreabankWorklistIcon('check') . ' ' . $t('KreaBankDraftInvoiceConfirmAction') . '</button></details>';
			$forms .= kreabankWorklistPostForm($actionId,$draft,$model);
		}
		$review .= $card($draft,$actions);
	}
	return array('html'=>$html,'review'=>$review,'review_search'=>!empty($form['review_search']),'form'=>$forms);
}
