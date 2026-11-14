<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
require_once __DIR__ . '/../class/KreaBankHistory.class.php';
require_once __DIR__ . '/kreabank_worklist_view.lib.php';

/** Known application codes have labels; unknown/custom evidence stays verbatim. */
function kreabankHistoryCodeLabel($value, $kind, $langs)
{
	$value = (string) $value;
	$documents = array(
		'payment'=>'CustomerInvoicePayment','payment_linked'=>'CustomerInvoicePayment','paiement'=>'CustomerInvoicePayment',
		'payment_supplier'=>'SupplierInvoicePayment','payment_supplier_linked'=>'SupplierInvoicePayment','paiementfourn'=>'SupplierInvoicePayment',
		'payment_salary'=>'SalaryPayment','payment_sc'=>'SocialContributionPayment','payment_tax'=>'SocialContributionPayment',
		'payment_various'=>'VariousPayment','payment_expensereport'=>'ExpenseReportPayment','payment_donation'=>'DonationPayment',
		'payment_loan'=>'LoanPayment','payment_withdraw'=>'WithdrawalPayment','banktransfert'=>'BankTransfer',
		'company'=>'ThirdParty','user'=>'User','bank_line'=>'KreaBankHistoryNativeEntry','native_bank'=>'KreaBankHistoryNativeEntry',
		'customer_invoice'=>'CustomerInvoice','supplier_invoice'=>'SupplierInvoice','tax'=>'SocialContribution','sc'=>'SocialContribution',
		'quick_entry'=>'KreaBankHistoryActionQuickEntry',
	);
	$actions = array(
		'import'=>'KreaBankHistoryActionImport','reconcile'=>'KreaBankHistoryActionReconcile','undo'=>'KreaBankHistoryActionUndo',
		'quick_entry'=>'KreaBankHistoryActionQuickEntry','quick_tax_entry'=>'KreaBankHistoryActionQuickTax',
		'quick_supplier_invoice'=>'KreaBankHistoryActionQuickSupplierInvoice','batch_approve'=>'KreaBankHistoryActionBatchApprove',
		'feed_sync'=>'KreaBankHistoryActionFeedSync','skip'=>'KreaBankHistoryActionSkip','delete_statement'=>'KreaBankHistoryActionDeleteStatement',
		'account_correction'=>'KreaBankHistoryActionAccountCorrection','invoice_validation'=>'KreaBankHistoryActionInvoiceValidation',
		'statement_evidence'=>'KreaBankHistoryActionStatementEvidence','audit_purge'=>'KreaBankHistoryActionAuditPurge',
		'trigger_event'=>'KreaBankHistoryActionTriggerEvent',
	);
	$strategies = array(
		'native'=>'KreaBankHistoryNativeEntry','legacy_staging'=>'KreaBankHistoryTypeLegacyImport','manual'=>'KreaBankHistoryTypeManual',
		'drag_drop'=>'KreaBankHistoryTypeAssignment','bulk_manual'=>'KreaBankHistoryTypeReviewed',
		'auto_native'=>'KreaBankHistoryTypeAutomaticMovement','auto_safe'=>'KreaBankHistoryTypeAutomaticSafe',
		'auto_pair'=>'KreaBankHistoryTypeAutomaticTransfer','auto_tpa'=>'KreaBankHistoryTypeAutomaticCard',
		'manual_payment_batch'=>'KreaBankHistoryTypePaymentBatch','manual_worklist_movement'=>'KreaBankHistoryTypeCreatedMovement',
		'quick_entry'=>$actions['quick_entry'],'quick_tax_entry'=>$actions['quick_tax_entry'],'quick_supplier_invoice'=>$actions['quick_supplier_invoice'],
	);
	$maps = array('document'=>$documents,'action'=>$actions,'strategy'=>$strategies);
	$key = $maps[$kind][$value] ?? '';
	if ($key === '') { return $value; }
	$label = kreabankWorklistTranslate($langs, $key);
	// Native English labels such as "User" may legitimately equal their key.
	return $label !== '' && (strpos($key,'KreaBank') !== 0 || $label !== $key) ? $label : $value;
}

/** Only reserved standalone native markers are translated, including composite references. */
function kreabankHistoryNativeLabel($value, $langs)
{
	$parts = preg_split('/(\s+\/\s+)/u', (string) $value, -1, PREG_SPLIT_DELIM_CAPTURE);
	if ($parts === false) { return (string) $value; }
	foreach ($parts as &$part) {
		if (preg_match('/^(\s*)\((payment|various_payment|various payment)\)(\s*)$/iuD', $part, $match)) {
			$key = strtolower($match[2]) === 'payment' ? 'Payment' : 'VariousPayment';
			$part = $match[1] . kreabankWorklistTranslate($langs, $key) . $match[3];
		} else { $part = kreabankWorklistNativeLabel($part, $langs); }
	}
	unset($part);
	return implode('', $parts);
}

/** Private, short-lived displayed undo snapshot; never accept identities from hidden fields alone. */
function kreabankHistoryUndoReview($row)
{
	global $conf, $user;
	$reviews = $_SESSION['kreabank_history_undo'] ?? array();
	foreach ($reviews as $key => $review) {
		if ($review['expires'] < time()) { unset($reviews[$key]); }
	}
	$reviews = array_slice($reviews, -1999, null, true);
	$key = bin2hex(random_bytes(24));
	$reviews[$key] = array('entity'=>(int) $conf->entity, 'user'=>(int) $user->id,
		'expires'=>time() + 900, 'snapshot'=>KreaBankHistory::snapshot($row));
	$_SESSION['kreabank_history_undo'] = $reviews;
	return $key;
}

function kreabankHistoryTakeUndoReview($key, $accountId)
{
	global $conf, $user;
	$review = $_SESSION['kreabank_history_undo'][$key] ?? null;
	unset($_SESSION['kreabank_history_undo'][$key]);
	if (!$review || $review['expires'] < time() || $review['entity'] !== (int) $conf->entity
		|| $review['user'] !== (int) $user->id
		|| ((int) $accountId > 0 && (int) $review['snapshot']['bank_account_id'] !== (int) $accountId)) {
		throw new DomainException('Review the reconciliation again before undoing it');
	}
	return $review['snapshot'];
}

/** Only same-origin HTTP(S) or application-root paths can open native documents. */
function kreabankHistoryDocumentUrl($prefix, $id, $host)
{
	$prefix = trim((string) $prefix);
	if ((int) $id <= 0 || preg_match('/[\x00-\x20\\\\]/', $prefix)) { return ''; }
	if (substr($prefix, 0, 1) === '/' && substr($prefix, 0, 2) !== '//') { return dol_buildpath($prefix . (int) $id, 1); }
	$parts = parse_url($prefix);
	if (!is_array($parts) || !in_array(strtolower($parts['scheme'] ?? ''), array('http','https'), true)
		|| strtolower($parts['host'] ?? '') !== strtolower(preg_replace('/:\d+$/', '', $host))
		|| isset($parts['user']) || isset($parts['pass'])) { return ''; }
	return $prefix . (int) $id;
}

/** Native header appearance with independent History/audit sort namespaces. */
function kreabankHistoryListHeader($label, $field, $sort, $params, $right = false)
{
	$html = getTitleFieldOfList($label, 0, '', $field, '', '', 'scope="col"', $sort['field'], $sort['order'], $right ? 'right ' : '', 1);
	$url = dol_escape_htmltag(kreabankListSortUrl($sort, $field, $params));
	return preg_replace_callback('/(<th\b[^>]*>)(.*)(<\/th>)/s', static function ($parts) use ($url) {
		return $parts[1] . '<a class="reposition" href="' . $url . '">' . $parts[2] . '</a>' . $parts[3];
	}, $html);
}

/** Keep native search controls associated with a GET form, outside Undo POST forms. */
function kreabankHistoryFilterButtons($form, $formId)
{
	return str_replace('<button ', '<button form="' . dol_escape_htmltag($formId) . '" ', $form->showFilterButtons());
}

/** Exact displayed amount comparisons; no rounding or binary float conversion. */
function kreabankHistoryAmountMatches($value, $filter)
{
	if ($filter === '') { return true; }
	if (!preg_match('/^(<=|>=|<>|!=|=|<|>)?\s*([+-]?\d{1,16}(?:[.,]\d{1,8})?)$/D', $filter, $parts)) { return false; }
	try {
		$target = KreaBankPaymentBatchDecimal::normalize(str_replace(',', '.', $parts[2]));
		$delta = KreaBankPaymentBatchDecimal::sum(array((string) $value, $target[0] === '-' ? substr($target,1) : '-' . $target));
	} catch (DomainException $e) { return false; }
	$comparison = $delta === '0.00000000' ? 0 : ($delta[0] === '-' ? -1 : 1);
	switch ($parts[1] ?: '=') {
		case '>': return $comparison > 0;
		case '>=': return $comparison >= 0;
		case '<': return $comparison < 0;
		case '<=': return $comparison <= 0;
		case '<>': case '!=': return $comparison !== 0;
		default: return $comparison === 0;
	}
}
