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
