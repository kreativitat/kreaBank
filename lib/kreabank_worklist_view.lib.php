<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
require_once __DIR__ . '/kreabank_worklist_creation_view.lib.php';

/** Presentation only: prepared facts and action descriptors come from the controller. */
function kreabankWorklistEscape($value)
{
	// Preserve literal source entities; native dol_escape_htmltag decodes some of them.
	return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Native translations are plain text before the view escapes them once. */
function kreabankWorklistTranslate($langs, $key)
{
	return method_exists($langs, 'transnoentities') ? $langs->transnoentities($key) : $langs->trans($key);
}

/** Translate reserved native label segments without rewriting customer prose. */
function kreabankWorklistNativeLabel($value, $langs)
{
	$parts = preg_split('/(\s+\/\s+)/u', (string) $value, -1, PREG_SPLIT_DELIM_CAPTURE);
	if ($parts === false) { return (string) $value; }
	$keys = array('banktransfert'=>'BankTransfer', 'paiement'=>'Payment', 'supplierinvoicepayment'=>'SupplierInvoicePayment', 'customerinvoicepayment'=>'CustomerInvoicePayment',
		'socialcontributionpayment'=>'SocialContributionPayment', 'variouspayment'=>'VariousPayment', 'salarypayment'=>'SalaryPayment', 'payment_salary'=>'SalaryPayment',
		'expensereportpayment'=>'ExpenseReportPayment', 'donationpayment'=>'DonationPayment', 'loanpayment'=>'LoanPayment',
		'withdrawalpayment'=>'WithdrawalPayment', 'banktransferpayment'=>'BankTransferPayment');
	$pattern = '/^(\s*)\((' . implode('|', array_keys($keys)) . ')\)(\s*)$/iuD';
	foreach ($parts as &$part) {
		if (preg_match($pattern, $part, $match)) {
			$part = $match[1] . kreabankWorklistTranslate($langs, $keys[strtolower($match[2])]) . $match[3];
		}
	}
	unset($part);
	return implode('', $parts);
}

/** Keep navigation read-only; never carry a financial action or review token into links. */
function kreabankWorklistViewState($state)
{
	$result = array();
	foreach ((array) $state as $key => $value) {
		if (is_scalar($value) && (preg_match('/^search_[a-z_]+$/D', $key)
			|| in_array($key, array('mainmenu', 'leftmenu', 'account_context', 'sortfield', 'sortorder', 'page', 'limit', 'expand', 'mode', 'entry_search', 'q'), true))) {
			$result[$key] = (string) $value;
		}
	}
	return $result;
}

function kreabankWorklistUrl($model, $changes = array())
{
	$state = array_merge(kreabankWorklistViewState(array_merge((array) ($model['state'] ?? array()), (array) ($model['sort_params'] ?? array()), (array) ($model['filters'] ?? array()))), $changes);
	foreach ($state as $key => $value) { if ($value === null) { unset($state[$key]); } }
	return strtok((string) $model['url'], '?') . ($state ? '?' . http_build_query($state, '', '&', PHP_QUERY_RFC3986) : '');
}

/** Hidden fields retain exact strings, including decimal and signed review values. */
function kreabankWorklistFields($fields, $prefix = '')
{
	$html = '';
	foreach ((array) $fields as $key => $value) {
		$name = $prefix === '' ? (string) $key : $prefix . '[' . $key . ']';
		if (is_array($value)) { $html .= kreabankWorklistFields($value, $name); }
		elseif (is_scalar($value) || $value === null) {
			$html .= '<input type="hidden" name="' . kreabankWorklistEscape($name) . '" value="' . kreabankWorklistEscape($value) . '">';
		}
	}
	return $html;
}

function kreabankWorklistDate($value)
{
	$date = DateTimeImmutable::createFromFormat('!Y-m-d', substr((string) $value, 0, 10), new DateTimeZone('UTC'));
	if (!$date || $date->format('Y-m-d') !== substr((string) $value, 0, 10)) { return '—'; }
	return dol_print_date($date->getTimestamp(), 'day', 'gmt');
}

function kreabankWorklistMoney($amount, $currency)
{
	return '<span class="kb-wl-money">' . kreabankPrice((string) $amount) . ' <small>' . kreabankWorklistEscape($currency) . '</small></span>';
}

function kreabankWorklistIcon($icon)
{
	$allowed = array('check', 'search', 'layer-group', 'plus', 'clock', 'money-bill-wave', 'save', 'times', 'exchange-alt', 'exclamation-circle', 'eye', 'undo', 'sync-alt', 'chevron-down', 'chevron-up');
	return '<span class="fas fa-' . (in_array($icon, $allowed, true) ? $icon : 'eye') . '" aria-hidden="true"></span>';
}

function kreabankWorklistButton($label, $icon, $attributes = '', $primary = false, $disabled = false)
{
	return '<button class="button kb-wl-icon' . ($primary ? ' kb-wl-primary' : '') . '" title="' . kreabankWorklistEscape($label)
		. '" aria-label="' . kreabankWorklistEscape($label) . '"' . ($disabled ? ' disabled' : '') . ' ' . $attributes . '>'
		. kreabankWorklistIcon($icon) . '<span class="kb-wl-sr">' . kreabankWorklistEscape($label) . '</span></button>';
}

/** Keep draft and review actions explicit, with native hover and accessible help. */
function kreabankWorklistSelectionButton($label, $help, $icon, $attributes, $primary = false)
{
	return '<button class="button kb-wl-selection-action' . ($primary ? ' kb-wl-primary' : '') . '" title="' . kreabankWorklistEscape($help)
		. '" aria-label="' . kreabankWorklistEscape($label) . '" ' . $attributes . '>' . kreabankWorklistIcon($icon) . '<span>' . kreabankWorklistEscape($label) . '</span></button>';
}

/** Render an isolated, associated POST form; never call this while a GET form is open. */
function kreabankWorklistPostForm($id, $descriptor, $model, $extra = '')
{
	$fields = (array) ($descriptor['fields'] ?? array());
	if (!isset($fields['token'])) { $fields['token'] = (string) ($model['token'] ?? ''); }
	if (!empty($descriptor['action'])) { $fields['action'] = $descriptor['action']; }
	return '<form method="post" action="' . kreabankWorklistEscape($model['url']) . '" id="' . kreabankWorklistEscape($id) . '"' . $extra . '>'
		. kreabankWorklistFields($fields) . '</form>';
}

/** Only typed, explicitly prepared native document identities become read-only links. */
function kreabankWorklistDocumentUrl($type, $id)
{
	$paths = array(
		'native_bank'=>'/compta/bank/line.php?rowid=',
		'customer_invoice'=>'/compta/facture/card.php?facid=',
		'supplier_invoice'=>'/fourn/facture/card.php?facid=',
		'payment'=>'/compta/paiement/card.php?id=', 'payment_linked'=>'/compta/paiement/card.php?id=',
		'payment_supplier'=>'/fourn/paiement/card.php?id=', 'payment_supplier_linked'=>'/fourn/paiement/card.php?id=',
		'tax'=>'/compta/sociales/card.php?id=', 'payment_tax'=>'/compta/payment_sc/card.php?id=',
		'salary'=>'/salaries/card.php?id=', 'payment_salary'=>'/salaries/payment_salary/card.php?id=',
	);
	if (!is_string($type) || !isset($paths[$type]) || (!is_int($id) && !is_string($id))
		|| !preg_match('/^[1-9][0-9]{0,9}$/D',(string) $id)) { return ''; }
	return dol_buildpath($paths[$type] . (int) $id, 1);
}

/** Candidate evidence uses the same compact structure for every accounting record type. */
function kreabankWorklistCandidate($candidate, $langs)
{
	$reference = kreabankWorklistEscape(kreabankWorklistNativeLabel($candidate['reference'] ?? '', $langs));
	$url = kreabankWorklistDocumentUrl($candidate['doc_type'] ?? '', $candidate['doc_id'] ?? 0);
	$transfer = $candidate['customer_transfer'] ?? null;
	if ($transfer) { $url = kreabankWorklistDocumentUrl('payment', $transfer['payment_id'] ?? 0); }
	$html = '<strong>' . ($url !== '' ? '<a href="' . kreabankWorklistEscape($url) . '" target="_blank" rel="noopener noreferrer">' . $reference . '</a>' : $reference) . '</strong>';
	if (!empty($candidate['description'])) { $html .= '<span class="kb-wl-caption">' . kreabankWorklistEscape(kreabankWorklistNativeLabel($candidate['description'], $langs)) . '</span>'; }
	$html .= '<span class="kb-wl-caption">' . kreabankWorklistEscape(kreabankWorklistDate($candidate['date'] ?? ''));
	if (isset($candidate['amount'])) { $html .= ' · ' . kreabankWorklistMoney($candidate['amount'], $candidate['currency'] ?? ''); }
	elseif (array_key_exists('amount', $candidate)) { $html .= ' · —'; }
	$html .= '</span>';
	if ($transfer) {
		$html .= '<span class="kb-wl-caption">' . kreabankWorklistEscape(kreabankWorklistTranslate($langs,'KreaBankWorklistCustomerTransfer'))
			. ' · ' . kreabankWorklistEscape(kreabankWorklistTranslate($langs,'KreaBankWorklistAllocatedInvoices')) . ': ' . (int) ($transfer['allocation_count'] ?? 0) . '</span>';
	}
	if (!empty($candidate['consequence'])) { $html .= '<span class="kb-wl-caption">' . kreabankWorklistEscape(kreabankWorklistTranslate($langs, $candidate['consequence'])) . '</span>'; }
	return $html;
}

/** A planned destination is explicit; it is never represented as an existing banked payment. */
function kreabankWorklistAccountReference($candidate, $fallback, $langs)
{
	$reference = kreabankWorklistEscape(trim((string) ($candidate['account_ref'] ?? $fallback)) ?: '—');
	if (empty($candidate['account_planned'])) { return $reference; }
	$label = kreabankWorklistEscape(kreabankWorklistTranslate($langs,'KreaBankWorklistPlannedPaymentAccount'));
	return '<span class="kb-wl-planned-account" title="' . $label . '"><span class="kb-wl-sr">' . $label . ': </span>' . kreabankWorklistIcon('clock') . ' ' . $reference . '</span>';
}

/** Keep a wrong-bank warning visible even when the movement cannot be edited. */
function kreabankWorklistAccountWarning($langs, $reference = '', $possible = false)
{
	$label = kreabankWorklistTranslate($langs,$possible ? 'KreaBankWorklistPossibleOtherAccount' : 'KreaBankWorklistAccountMismatch');
	return '<span class="kb-wl-bank-warning" data-kb-account-warning role="img" title="' . kreabankWorklistEscape($label) . '" aria-label="' . kreabankWorklistEscape(trim($label . ' ' . $reference)) . '">'
		. kreabankWorklistIcon('exclamation-circle') . ($reference !== '' ? ' ' . kreabankWorklistEscape($reference) : '') . '</span>';
}

/** Native payment confirmation stays in the iframe; cancellation leaves the list intact. */
function kreabankWorklistPaymentButton($candidate, $line, $key, $id, $model, $langs)
{
	$url = (string) ($candidate['payment_url'] ?? '');
	if ($url === '') { return ''; }
	$paths = array('customer_invoice'=>'/compta/paiement.php','supplier_invoice'=>'/fourn/facture/paiement.php',
		'salary'=>'/custom/kreabank/recordpayment.php','tax'=>'/custom/kreabank/recordpayment.php');
	$parts = parse_url($url); $type = $candidate['doc_type'] ?? '';
	if (!$parts || isset($parts['scheme']) || isset($parts['host'])
		|| !isset($paths[$type]) || ($parts['path'] ?? '') !== $paths[$type] || isset($parts['fragment'])) { return ''; }
	parse_str($parts['query'] ?? '',$params);
	$docId = (int) ($candidate['doc_id'] ?? 0); $amountKey = 'amount_' . $docId;
	$guarded = in_array($type,array('salary','tax'),true);
	$allowed = $guarded ? array('action','type','id','review') : array('action','facid','accountid','year','month','day','reyear','remonth','reday',$amountKey);
	if ($guarded) {
		if (array_diff(array_keys($params),$allowed) || ($params['action'] ?? '') !== 'create' || ($params['type'] ?? '') !== $type
			|| (string) ($params['id'] ?? '') !== (string) $docId || $docId <= 0
			|| !is_string($params['review'] ?? null) || !preg_match('/^[a-f0-9]{48}$/D',$params['review'])) { return ''; }
	} else {
	if (array_diff(array_keys($params),$allowed) || ($params['action'] ?? '') !== 'create' || $docId <= 0
		|| (string) ($params['facid'] ?? '') !== (string) $docId
		|| (string) ($params['accountid'] ?? '') !== (string) (int) ($line['bank_account_id'] ?? 0)) { return ''; }
	foreach (array('facid','accountid','year','month','day','reyear','remonth','reday') as $name) {
		if (!is_string($params[$name] ?? null) || !preg_match('/^[1-9][0-9]{0,9}$/D',$params[$name])) { return ''; }
	}
	if (!is_string($params[$amountKey] ?? null) || !preg_match('/^\d+(?:[.,]\d{1,8})?$/D',$params[$amountKey])) { return ''; }
	}
	$url = $paths[$type] . '?' . http_build_query($params,'','&',PHP_QUERY_RFC3986);
	$label = kreabankWorklistTranslate($langs,'KreaBankWorklistRecordPayment');
	$button = kreabankWorklistIcon('money-bill-wave') . ' ' . kreabankWorklistEscape($label);
	if (!function_exists('dolButtonToOpenUrlInDialogPopup')) {
		return '<a class="button kb-wl-record-payment" href="' . kreabankWorklistEscape(dol_buildpath($url,1)) . '" target="_blank" rel="noopener noreferrer">' . $button . '</a>';
	}
	$name = 'kbwlpay' . substr(hash('sha256',$id),0,16); $holder = $name . '-holder';
	$refresh = kreabankWorklistUrl($model,array('refresh_payment'=>$key));
	$close = "document.getElementById('" . $holder . "').dispatchEvent(new Event('kb-worklist-payment-close',{bubbles:true}))";
	return '<div class="kb-wl-payment-popup" id="' . $holder . '" data-kb-payment-popup data-line-key="' . kreabankWorklistEscape($key)
		. '" data-kb-payment-frame="iframedialog' . $name . '" data-kb-payment-refresh="' . kreabankWorklistEscape($refresh) . '">'
		. dolButtonToOpenUrlInDialogPopup($name,$label,$button,$url,'','kb-wl-record-payment button','',$close) . '</div>';
}

function kreabankWorklistAccountFilter($model, $filters, $langs, $name, $label, $withNone = false)
{
	$e = 'kreabankWorklistEscape';
	$html = '<label class="kb-wl-sr" for="kb-wl-' . $name . '">' . $e(kreabankWorklistTranslate($langs,$label)) . '</label><select class="flat maxwidth150" id="kb-wl-' . $name . '" name="' . $name . '"'
		. '><option value="">' . $e(kreabankWorklistTranslate($langs,'All')) . '</option>';
	if ($withNone) { $html .= '<option value="none"' . (($filters[$name] ?? '') === 'none' ? ' selected' : '') . '>' . $e(kreabankWorklistTranslate($langs,'KreaBankWorklistNotBanked')) . '</option>'; }
	foreach ((array) ($model['accounts'] ?? array()) as $account) {
		$id = (string) ($account['id'] ?? $account['rowid'] ?? '');
		$html .= '<option value="' . $e($id) . '"' . ((string) ($filters[$name] ?? '') === $id ? ' selected' : '') . '>' . $e($account['ref'] ?? '') . '</option>';
	}
	return $html . '</select>';
}

/** The payment-bank column opens the existing reviewed account-change dialog. */
function kreabankWorklistCorrection($candidate, $line, $id, $model, $langs)
{
	if (empty($model['can_write']) || empty($candidate['correction'])) { return array('button'=>'', 'reference'=>'', 'form'=>'', 'dialog'=>''); }
	$e = 'kreabankWorklistEscape';
	$t = static function ($key) use ($langs) { return kreabankWorklistEscape(kreabankWorklistTranslate($langs, $key)); };
	$correction = $candidate['correction'];
	$dialogId = $id . '-account';
	$actionId = $dialogId . '-form';
	$accountRef = trim((string) ($candidate['account_ref'] ?? ''));
	$label = kreabankWorklistTranslate($langs,!empty($candidate['possible']) ? 'KreaBankWorklistPossibleOtherAccount' : 'KreaBankWorklistAccountMismatch') . ' ' . $accountRef . '. ' . kreabankWorklistTranslate($langs,'KreaBankWorklistChangeAccount');
	$reference = $accountRef === '' ? '' : '<button type="button" class="kb-wl-account-ref" data-kb-account-warning data-kb-dialog="' . $dialogId . '" aria-controls="' . $dialogId . '" aria-haspopup="dialog" title="' . $e($label) . '" aria-label="' . $e($label) . '">'
		. kreabankWorklistIcon('exclamation-circle') . ' ' . $e($accountRef) . '</button>';
	$dialog = '<dialog class="kb-wl-dialog" id="' . $dialogId . '" aria-labelledby="' . $dialogId . '-title" aria-describedby="' . $dialogId . '-effect">'
		. '<button type="button" class="button kb-wl-icon kb-wl-dialog-close" data-kb-dialog-close title="' . $t('Close') . '" aria-label="' . $t('Close') . '">' . kreabankWorklistIcon('times') . '</button><h2 id="' . $dialogId . '-title">' . $t('KreaBankWorklistChangeAccount') . '</h2>'
		. '<p><strong>' . $e(kreabankWorklistNativeLabel($candidate['reference'] ?? '', $langs)) . '</strong></p><dl><dt>' . $t('KreaBankDate') . '</dt><dd>' . $e(kreabankWorklistDate($candidate['date'] ?? '')) . '</dd><dt>' . $t('Amount') . '</dt><dd>' . kreabankWorklistMoney($candidate['amount'], $candidate['currency'] ?? $line['currency']) . '</dd><dt>' . $t('KreaBankWorklistStatementAccount') . '</dt><dd>' . $e($line['bank_account_ref']) . '</dd><dt>' . $t('KreaBankWorklistFromAccount') . '</dt><dd>' . $e($correction['from_account_ref'] ?? $candidate['account_ref'] ?? '') . '</dd><dt>' . $t('KreaBankWorklistToAccount') . '</dt><dd>' . $e($correction['to_account_ref'] ?? $line['bank_account_ref']) . '</dd></dl><p id="' . $dialogId . '-effect">' . $t('KreaBankWorklistCorrectionEffect') . '</p><div class="kb-wl-dialog-actions"><button type="button" class="button" data-kb-dialog-close autofocus>' . $t('Cancel') . '</button><button type="submit" class="button" form="' . $actionId . '">' . $t('Save') . '</button></div></dialog>';
	return array('button'=>'',
		'reference'=>$reference, 'form'=>kreabankWorklistPostForm($actionId, $correction, $model), 'dialog'=>$dialog);
}

/**
 * Render the separate reconciliation list. This helper performs no matching or writes.
 * Controller-owned descriptors define every POST action and its authenticated fields.
 */
function kreabankWorklistTable($model, $langs)
{
	$e = 'kreabankWorklistEscape';
	$t = static function ($key) use ($langs) { return kreabankWorklistEscape(kreabankWorklistTranslate($langs, $key)); };
	$state = kreabankWorklistViewState($model['state'] ?? array());
	$filters = (array) ($model['filters'] ?? array());
	$canWrite = !empty($model['can_write']);
	$hideStatementAccount = !empty($model['hide_statement_account']);
	$columnCount = $hideStatementAccount ? 6 : 7;
	$forms = '';
	$dialogs = '';
	$filterId = 'searchFormList';
	$stateWithoutFilters = array_filter($state, static function ($key) { return strpos($key, 'search_') !== 0 && !in_array($key, array('page', 'expand', 'mode', 'entry_search', 'q'), true); }, ARRAY_FILTER_USE_KEY);
	if (!empty($model['pagination'])) { unset($stateWithoutFilters['limit']); }
	$formatSample = html_entity_decode(strip_tags(kreabankPrice('1000.10')), ENT_QUOTES, 'UTF-8');
	$moneyFormat = preg_match('/^\s*1([^0-9]*)000([^0-9]+)10\s*$/uD', $formatSample, $separators)
		? ' data-group-separator="' . $e($separators[1]) . '" data-decimal-separator="' . $e($separators[2]) . '"' : '';
	$html = '<div class="kb-worklist" data-locale="' . $e(str_replace('_', '-', $langs->defaultlang ?? 'en_US')) . '"' . $moneyFormat
		. ' data-navigation-scope="' . $e($model['navigation_scope'] ?? '') . '" data-navigation-context="' . $e($model['navigation_context'] ?? '') . '" data-navigation-page="' . max(0, (int) ($model['navigation_page'] ?? 0)) . '">';
	if (!$canWrite) { $html .= '<p class="opacitymedium" role="status">' . $t('KreaBankWorkspaceReadOnly') . '</p>'; }
	if (!empty($model['notice'])) { $html .= '<p role="status">' . $e($model['notice']) . '</p>'; }
	$html .= '<div class="kb-wl-toolbar"><nav class="kb-view-switch kb-wl-status-tabs" aria-label="' . $t('Status') . '">';
	foreach (array('pending'=>'KreaBankWorklistPending','reconciled'=>'KreaBankWorklistReconciled','draft'=>'Draft','review'=>'KreaBankLinkNeedsReview','all'=>'All') as $status=>$label) {
		$html .= '<a href="' . $e(kreabankWorklistUrl($model,array('search_status'=>$status,'page'=>0,'expand'=>null,'mode'=>null,'q'=>null,'entry_search'=>null))) . '"'
			. (($filters['search_status'] ?? 'pending') === $status ? ' class="active" aria-current="page"' : '') . '>' . $t($label) . '</a>';
	}
	$html .= '</nav></div>';
	if (!empty($model['scan'])) {
		$scan = $model['scan'];
		$html .= '<div class="kb-wl-scan" role="status" aria-live="polite" data-auto-scan="' . (!isset($scan['auto_scan']) || $scan['auto_scan'] ? '1' : '0') . '" data-page-ready="' . (!empty($scan['page_ready']) ? '1' : '0') . '" data-ready="' . $t('KreaBankWorklistResultsReady') . '" data-failed="' . $t('KreaBankWorklistAnalysisFailed') . '" data-changed="' . $t('KreaBankWorklistAnalysisChanged') . '" data-paused="' . $t('KreaBankWorklistAnalysisPaused') . '"><span data-kb-scan-message>' . $e($scan['message'] ?? '') . '</span><span data-kb-scan-count>' . (int) ($scan['done'] ?? 0) . ' / ' . (int) ($scan['total'] ?? 0) . '</span>';
		if (isset($scan['done'], $scan['total']) && (int) $scan['total'] > 0) {
			$html .= '<progress data-kb-scan-progress max="' . (int) $scan['total'] . '" value="' . min((int) $scan['total'], max(0, (int) $scan['done'])) . '"></progress>';
		}
		if (!empty($scan['action'])) {
			$id = 'kb-wl-scan-form';
			$hasExpandedRow = (bool) array_filter((array) ($model['rows'] ?? array()), static function ($row) { return !empty($row['expanded']); });
			$isMore = ($scan['action']['action'] ?? '') === 'more_scan';
			$html .= $isMore ? '<button type="submit" class="button" form="' . $id . '"' . ($hasExpandedRow ? ' disabled' : '') . '>' . $t('KreaBankWorklistMoreAnalysis') . '</button>'
				: kreabankWorklistButton(kreabankWorklistTranslate($langs, 'KreaBankWorklistContinueAnalysis'), 'sync-alt', 'type="submit" form="' . $id . '"', false, $hasExpandedRow);
			$forms .= kreabankWorklistPostForm($id, $scan['action'], $model, $isMore ? '' : ' data-kb-scan');
		}
		$html .= '</div>';
	}
	$html .= '<form method="get" action="' . $e(strtok($model['url'], '?')) . '" id="' . $filterId . '" name="searchFormList" role="search" aria-label="' . $t('Filters') . '">'
		. kreabankWorklistFields($stateWithoutFilters) . '<input type="hidden" name="search_status" value="' . $e($filters['search_status'] ?? 'pending') . '">'
		. ($hideStatementAccount ? '<input type="hidden" name="search_account" value="' . $e($filters['search_account'] ?? '') . '">' : '')
		. ($model['pagination'] ?? '') . '<div class="div-table-responsive"><table class="tagtable liste kb-wl-table" id="kb-worklist-table">';
	$html .= '<thead><tr class="liste_titre_filter">';
	$html .= '<td class="liste_titre"><label class="kb-wl-sr" for="kb-wl-date-from">' . $t('KreaBankWorklistDateFrom') . '</label><input class="flat kb-wl-date" id="kb-wl-date-from" type="date" name="search_date_from" value="' . $e($filters['search_date_from'] ?? '') . '"><label class="kb-wl-sr" for="kb-wl-date-to">' . $t('KreaBankWorklistDateTo') . '</label><input class="flat kb-wl-date" id="kb-wl-date-to" type="date" name="search_date_to" value="' . $e($filters['search_date_to'] ?? '') . '"></td>';
	if (!$hideStatementAccount) { $html .= '<td class="liste_titre">' . kreabankWorklistAccountFilter($model,$filters,$langs,'search_account','KreaBankWorklistStatementAccount') . '</td>'; }
	foreach (array('search_description'=>'KreaBankReviewStatementLine', 'search_amount'=>'Amount', 'search_match'=>'KreaBankWorklistMatch') as $name => $label) {
		$html .= '<td class="liste_titre"><label class="kb-wl-sr" for="kb-wl-' . $name . '">' . $t($label) . '</label><input class="flat ' . ($name === 'search_amount' ? 'width75 right' : 'maxwidth150') . '" id="kb-wl-' . $name . '" type="search" name="' . $name . '" value="' . $e($filters[$name] ?? '') . '"></td>';
	}
	$html .= '<td class="liste_titre">' . kreabankWorklistAccountFilter($model,$filters,$langs,'search_payment_account','KreaBankWorklistPaymentAccount',true) . '</td><td class="liste_titre center">';
	$html .= $model['filter_buttons'] ?? (kreabankWorklistButton(kreabankWorklistTranslate($langs, 'Search'), 'search', 'type="submit" name="button_search" value="1"') . kreabankWorklistButton(kreabankWorklistTranslate($langs, 'RemoveFilter'), 'times', 'type="submit" name="button_removefilter" value="1"'));
	$html .= '</td></tr><tr class="liste_titre">';
	$columns = array('operation_date'=>'KreaBankDate', 'bank_account_ref'=>'KreaBankWorklistStatementAccount', 'description'=>'KreaBankReviewStatementLine', 'amount'=>'Amount', 'match'=>'KreaBankWorklistMatch', 'payment_account'=>'KreaBankWorklistPaymentAccount');
	foreach ($columns as $field => $label) {
		if ($hideStatementAccount && $field === 'bank_account_ref') { continue; }
		if (isset($model['headers'][$field])) { $html .= $model['headers'][$field]; continue; }
		$sortField = $model['sort_fields'][$field] ?? $field;
		$order = (($model['sort']['field'] ?? '') === $sortField && ($model['sort']['order'] ?? 'ASC') === 'ASC') ? 'DESC' : 'ASC';
		$url = kreabankWorklistUrl($model, array('sortfield'=>$sortField, 'sortorder'=>$order, 'page'=>null, 'expand'=>null, 'mode'=>null));
		$html .= '<th scope="col" class="liste_titre' . ($field === 'amount' ? ' right' : '') . '" aria-sort="' . (($model['sort']['field'] ?? '') === $sortField ? (($model['sort']['order'] ?? 'ASC') === 'ASC' ? 'ascending' : 'descending') : 'none') . '"><a href="' . $e($url) . '">' . $t($label) . ' <span aria-hidden="true">' . (($model['sort']['field'] ?? '') === $sortField ? (($model['sort']['order'] ?? 'ASC') === 'ASC' ? '↑' : '↓') : '↕') . '</span></a></th>';
	}
	$html .= '<th scope="col" class="liste_titre right">' . $t('KreaBankActions') . '</th></tr></thead><tbody>';
	foreach ((array) ($model['rows'] ?? array()) as $row) {
		$key = (string) $row['key'];
		$id = 'kb-wl-' . substr(hash('sha256', $key), 0, 16);
		$line = (array) $row['line'];
		$review = (array) ($row['review'] ?? array());
		$expanded = !empty($row['expanded']);
		$createMode = !empty($row['create_mode']);
		$batchMode = !$createMode;
		$batchReview = !empty($row['batch_review']);
		$activeBatch = !empty($row['batch_active']);
		$draft = (array) ($row['draft'] ?? array());
		$done = (int) ($line['status'] ?? 0) === 2 || !empty($row['reconciled']);
		$bankReviews = !$done ? (array) ($row['bank_reviews'] ?? (!empty($review['correction']) ? array($review) : array())) : array();
		$accountMismatch = !$done && (!empty($row['account_mismatch']) || $bankReviews);
		$needsReview = !empty($row['needs_review']);
		$html .= '<tr class="oddeven kb-wl-row" id="' . $id . '" data-line-key="' . $e($key) . '"><td class="nowrap">' . $e(kreabankWorklistDate($line['operation_date'] ?? $line['date'] ?? '')) . '</td>' . (!$hideStatementAccount ? '<td>' . $e($line['bank_account_ref'] ?? '') . '</td>' : '') . '<td class="kb-wl-statement">' . $e($line['description'] ?? '') . '</td><td class="right nowrap">' . kreabankWorklistMoney($line['amount'], $line['currency']) . '</td><td class="kb-wl-match">';
		if ($review) { $html .= kreabankWorklistCandidate($review, $langs); }
		elseif ($draft) { $html .= '<strong>' . $e(count((array) ($draft['member_keys'] ?? array())) === 1 ? kreabankWorklistTranslate($langs, 'KreaBankWorklistSelectedMovement') : (($draft['name'] ?? '') ?: kreabankWorklistTranslate($langs, 'KreaBankWorklistPaymentBatch'))) . '</strong><span class="kb-wl-caption">' . $t(!empty($row['batch_suggestion']) || count((array) ($draft['member_keys'] ?? array())) === 1 ? 'KreaBankWorklistSelected' : 'Draft') . ' · ' . count((array) ($draft['member_keys'] ?? array())) . ' ' . $t(count((array) ($draft['member_keys'] ?? array())) === 1 ? 'KreaBankWorklistMovement' : 'KreaBankWorklistMovements') . '</span>'; }
		else { $html .= '<span class="opacitymedium">' . $t(!empty($row['ambiguous']) ? 'KreaBankWorklistAmbiguous' : (!empty($row['pending_analysis']) ? 'KreaBankWorklistAwaitingAnalysis' : 'KreaBankWorklistNoMatch')) . '</span>'; }
		if (!empty($row['notice'])) { $html .= '<span class="kb-wl-caption" role="status">' . $e($row['notice']) . '</span>'; }
		if ($needsReview) { $html .= '<span class="kb-wl-caption warning" role="status">' . $t('KreaBankLinkNeedsReview') . '</span>'; }
		if ($canWrite && !$done && !$accountMismatch) { $html .= kreabankWorklistPaymentButton($review,$line,$key,$id . '-proposal',$model,$langs); }
		$paymentAccount = $accountMismatch ? $e(trim((string) ($review['account_ref'] ?? $row['payment_account_ref'] ?? '')) ?: '—') : kreabankWorklistAccountReference($review,$row['payment_account_ref'] ?? '',$langs);
		$html .= '</td><td class="kb-wl-payment-bank">';
		foreach ($bankReviews as $bankIndex=>$bankReview) {
			$correctionUi = count($bankReviews) === 1 ? kreabankWorklistCorrection($bankReview,$line,$id . '-bank-' . $bankIndex,$model,$langs) : array('reference'=>'','form'=>'','dialog'=>'');
			$html .= '<span class="kb-wl-bank-review">' . ($correctionUi['reference'] ?: kreabankWorklistAccountWarning($langs,trim((string) ($bankReview['account_ref'] ?? '')) ?: '—',!empty($bankReview['possible'])));
			if (count($bankReviews) > 1 && !empty($bankReview['reference'])) { $html .= '<span class="kb-wl-caption">' . $e(kreabankWorklistNativeLabel($bankReview['reference'],$langs)) . '</span>'; }
			$html .= '</span>'; $forms .= $correctionUi['form']; $dialogs .= $correctionUi['dialog'];
		}
		if (!$bankReviews) { $html .= $accountMismatch ? kreabankWorklistAccountWarning($langs,trim((string) ($review['account_ref'] ?? $row['payment_account_ref'] ?? '')) ?: '—') : $paymentAccount; }
		$html .= '</td><td class="right nowrap kb-wl-actions">';
		if ($accountMismatch) {
			$html .= kreabankWorklistButton(kreabankWorklistTranslate($langs,'KreaBankWorklistAccountCorrectionRequired'),'check','type="button"',false,true);
		} elseif ($canWrite && !$done && !empty($review['action'])) {
			$formId = $id . '-approve';
			$disabled = !empty($review['action']['disabled']) || in_array($review['doc_type'] ?? '',array('customer_invoice','supplier_invoice','tax','salary'),true);
			$html .= kreabankWorklistButton(kreabankWorklistTranslate($langs, $disabled ? 'KreaBankWorklistPaymentRequired' : ($review['action']['label'] ?? 'KreaBankWorklistReconcile')), 'check', $disabled ? 'type="button"' : 'type="submit" form="' . $formId . '"', !$disabled, $disabled);
			if (!$disabled) { $forms .= kreabankWorklistPostForm($formId, $review['action'], $model); }
		}
		if (!$done) {
			foreach (array('batch'=>'KreaBankWorklistSelectMovements', 'create'=>'KreaBankWorklistCreateMovement') as $mode => $label) {
				if ($mode === 'batch' && !empty($row['batch_unavailable'])) { continue; }
				if ($mode === 'create' && (!$canWrite || $accountMismatch || empty($row['create_available']))) { continue; }
				$url = kreabankWorklistUrl($model, array('expand'=>$key, 'mode'=>$mode));
				$html .= '<a class="button kb-wl-icon" data-kb-row-navigation="' . $e($key) . '" href="' . $e($url) . '" title="' . $t($label) . '" aria-label="' . $t($label) . '" aria-controls="' . $id . '-detail" aria-expanded="' . ($expanded && ($createMode ? 'create' : 'batch') === $mode ? 'true' : 'false') . '">' . kreabankWorklistIcon($mode === 'batch' ? 'layer-group' : ($mode === 'create' ? 'plus' : 'search')) . '<span class="kb-wl-sr">' . $t($label) . '</span></a>';
			}
		}
		if ($done && $activeBatch) {
			$html .= '<a class="button kb-wl-icon" data-kb-row-navigation="' . $e($key) . '" href="' . $e(kreabankWorklistUrl($model, array('expand'=>$key, 'mode'=>'view'))) . '" title="' . $t('KreaBankWorklistViewBatch') . '" aria-label="' . $t('KreaBankWorklistViewBatch') . '" aria-controls="' . $id . '-detail" aria-expanded="' . ($expanded ? 'true' : 'false') . '">' . kreabankWorklistIcon('eye') . '</a>';
		}
		$html .= '</td></tr>';
		if (!$expanded || ($done && !$activeBatch)) { continue; }
		$html .= '<tr class="kb-wl-detail-row" id="' . $id . '-detail" data-line-key="' . $e($key) . '"><td colspan="' . $columnCount . '"><section class="kb-wl-detail" aria-label="' . $e($line['description'] ?? '') . '"><div class="kb-wl-detail-heading"><strong>' . $t($createMode ? 'KreaBankWorklistCreateMovement' : ($activeBatch ? 'KreaBankWorklistPaymentBatch' : 'KreaBankWorklistSelectMovements')) . '</strong><a class="button kb-wl-icon" data-kb-row-navigation="' . $e($key) . '" href="' . $e(kreabankWorklistUrl($model, array('expand'=>null, 'mode'=>null, 'entry_search'=>null, 'q'=>null))) . '" title="' . $t('Close') . '" aria-label="' . $t('Close') . '">' . kreabankWorklistIcon('times') . '</a></div>';
		if ($createMode) {
			$creation = kreabankWorklistCreation($row,$line,$id,$model,$langs);
			$html .= $creation['html'] . '</section></td></tr>'; $forms .= $creation['form']; continue;
		}
		$batch = (array) ($row['batch_form'] ?? array());
		$batchId = $id . '-batch';
		$candidates = (array) ($batchMode ? ($row['batch_candidates'] ?? array()) : ($row['candidates'] ?? array()));
		if ($activeBatch || $batchReview) {
			$candidates = $row['batch_components'] ?? array_values(array_filter($candidates, static function ($candidate) use ($draft) { return !empty($candidate['selected']) || in_array((string) ($candidate['key'] ?? ''), array_map('strval', (array) ($draft['member_keys'] ?? array())), true); }));
			if (!empty($draft['name'])) { $html .= '<p><strong>' . $e($draft['name']) . '</strong></p>'; }
		}
		if (!$candidates) { $html .= '<p class="opacitymedium">' . $t('KreaBankWorklistNoResults') . '</p>'; }
		if ($batchMode) {
			$html .= '<div class="kb-wl-batch"' . (!$batchReview && !$activeBatch && $canWrite ? ' data-kb-batch' : '') . ' data-max-members="' . (int) ($row['max_members'] ?? 500) . '" data-target="' . $e($line['amount_decimal'] ?? $line['amount']) . '" data-currency="' . $e($line['currency']) . '" data-incomplete="' . $t('KreaBankWorklistBatchIncomplete') . '" data-ready="' . $t('KreaBankWorklistReady') . '"><div class="kb-wl-batch-controls">';
			$html .= '<p class="kb-wl-batch-day">' . $t('KreaBankWorklistBatchDay') . ' <strong>' . $e(kreabankWorklistDate($line['operation_date'])) . '</strong></p>';
			if (!empty($row['batch_suggestion']) && in_array($row['batch_suggestion'],array('unique','ambiguous','bounded','truncated'),true)) { $html .= '<p class="kb-wl-caption">' . $t('KreaBankWorklistBatchSuggestion_' . $row['batch_suggestion']) . '</p>'; }
			$html .= '<div class="kb-wl-totals" role="status" aria-live="polite" aria-atomic="true"><div><span>' . $t('KreaBankReviewStatementLine') . '</span><strong>' . kreabankWorklistMoney($line['amount'], $line['currency']) . '</strong></div><div><span>' . $t('KreaBankWorkspaceSelectedTotal') . '</span><strong data-kb-selected-total>' . (isset($batch['selected_total']) ? kreabankWorklistMoney($batch['selected_total'], $line['currency']) : '—') . '</strong></div><div><span>' . $t('KreaBankWorkspaceDifference') . '</span><strong data-kb-difference>' . (isset($batch['difference']) ? kreabankWorklistMoney($batch['difference'], $line['currency']) : '—') . '</strong></div></div><div class="kb-wl-batch-footer"><span class="opacitymedium" data-kb-selected-count data-label="' . $t('KreaBankWorklistSelected') . '">' . count((array) ($draft['member_keys'] ?? array())) . '</span>';
			if ($canWrite && $batch && !$batchReview && !$activeBatch) {
				$html .= '<span class="kb-wl-sr" id="' . $batchId . '-save-help">' . $t('KreaBankWorklistSaveDraftHelp') . '</span><span class="kb-wl-sr" id="' . $batchId . '-review-help">' . $t('KreaBankWorklistReviewSelectionHelp') . '</span>';
				$html .= kreabankWorklistSelectionButton(kreabankWorklistTranslate($langs,'KreaBankWorklistSaveDraft'),kreabankWorklistTranslate($langs,'KreaBankWorklistSaveDraftHelp'),'save','type="submit" form="' . $batchId . '" name="action" value="' . $e($batch['save_action'] ?? 'save_draft') . '" data-kb-save-draft aria-describedby="' . $batchId . '-save-help"' . ((isset($batch['grouping_available']) && !$batch['grouping_available']) || count((array) ($draft['member_keys'] ?? array())) === 1 ? ' hidden' : ''));
				$html .= kreabankWorklistSelectionButton(kreabankWorklistTranslate($langs,'KreaBankWorklistReviewSelection'),kreabankWorklistTranslate($langs,'KreaBankWorklistReviewSelectionHelp'),'eye','type="submit" form="' . $batchId . '" name="action" value="' . $e($batch['review_action'] ?? 'review_batch') . '" data-kb-batch-review aria-describedby="' . $batchId . '-feedback ' . $batchId . '-review-help"',true);
				$forms .= kreabankWorklistPostForm($batchId, array('fields'=>$batch['fields'] ?? array()), $model);
			}
			$html .= '</div>';
			if (!$batchReview && !$activeBatch && $canWrite) { $html .= '<p class="kb-wl-batch-feedback opacitymedium" id="' . $batchId . '-feedback" data-kb-batch-feedback role="status">' . $t('KreaBankWorklistBatchIncomplete') . '</p>'; }
			$html .= '</div><div class="kb-wl-members" role="region" tabindex="0" aria-label="' . $t('KreaBankWorklistBatchPayments') . '">';
		}
		foreach ($candidates as $index => $candidate) {
			$candidateId = $id . '-candidate-' . $index;
			$selectable = false;
			$html .= '<div class="kb-wl-candidate">';
			if ($batchMode && !$batchReview && !$activeBatch && $canWrite) {
				$selected = !empty($candidate['selected']) || in_array((string) $candidate['key'], array_map('strval', (array) ($draft['member_keys'] ?? array())), true);
				$selectable = $canWrite && !empty($candidate['selectable']);
				$html .= '<input type="checkbox" id="' . $candidateId . '" form="' . $batchId . '" name="' . $e($batch['selection_name'] ?? 'batch_members[]') . '" value="' . $e($candidate['key']) . '" data-kb-member data-single-eligible="' . (!isset($candidate['single_eligible']) || $candidate['single_eligible'] ? '1' : '0') . '" data-group-eligible="' . (!isset($candidate['group_eligible']) || $candidate['group_eligible'] ? '1' : '0') . '" data-amount="' . $e($candidate['amount']) . '"' . ($selected ? ' checked' : '') . (!$selectable ? ' disabled' : '') . ' aria-label="' . $e(kreabankWorklistTranslate($langs, 'Select') . ' ' . kreabankWorklistNativeLabel($candidate['reference'] ?? '', $langs)) . '">';
			}
			$evidence = kreabankWorklistCandidate($candidate, $langs);
			if ($batchMode && !$batchReview && $canWrite && $selectable) { $evidence = '<label class="kb-wl-member-label" for="' . $candidateId . '">' . $evidence . '</label>'; }
			$html .= '<div class="kb-wl-candidate-evidence">' . $evidence . '<span class="kb-wl-caption">' . $t('KreaBankWorklistPaymentAccount') . ': <strong>' . kreabankWorklistAccountReference($candidate,'',$langs) . '</strong></span>';
			if (!$batchMode && !$done && $canWrite && !$accountMismatch) { $html .= kreabankWorklistPaymentButton($candidate,$line,$key,$candidateId,$model,$langs); }
			$html .= '</div><div class="kb-wl-candidate-actions">';
			if (!$batchMode && !$done && $canWrite && !$accountMismatch && !empty($candidate['action'])) {
				$actionId = $candidateId . '-approve';
				$disabled = !empty($candidate['action']['disabled']) || in_array($candidate['doc_type'] ?? '',array('customer_invoice','supplier_invoice','tax','salary'),true);
				$html .= kreabankWorklistButton(kreabankWorklistTranslate($langs, $disabled ? 'KreaBankWorklistPaymentRequired' : ($candidate['action']['label'] ?? 'KreaBankWorklistReconcile')), 'check', $disabled ? 'type="button"' : 'type="submit" form="' . $actionId . '"', !$disabled, $disabled);
				if (!$disabled) { $forms .= kreabankWorklistPostForm($actionId, $candidate['action'], $model); }
			}
			$html .= '</div></div>';
		}
		if ($batchMode) { $html .= '</div></div>'; }
		if ($canWrite && !empty($row['batch_review'])) {
			$actionId = $id . '-confirm-batch';
			$html .= '<div class="kb-wl-confirm-batch"><span>' . $t('KreaBankWorklistBatchReviewed') . '</span>' . kreabankWorklistButton(kreabankWorklistTranslate($langs, 'KreaBankWorklistReconcileSelection'), 'check', 'type="submit" form="' . $actionId . '"', true) . '</div>';
			$forms .= kreabankWorklistPostForm($actionId, $row['batch_review'], $model);
		}
		if ($canWrite && $activeBatch && !empty($row['undo_review'])) {
			$actionId = $id . '-undo-batch';
			$html .= '<div class="kb-wl-confirm-batch"><span>' . $t('KreaBankWorklistUndoEffect') . '</span>' . kreabankWorklistButton(kreabankWorklistTranslate($langs, $row['undo_review']['label'] ?? 'KreaBankWorklistUndoBatch'), 'undo', 'type="submit" form="' . $actionId . '"') . '</div>';
			$forms .= kreabankWorklistPostForm($actionId, $row['undo_review'], $model);
		}
		$html .= '</section></td></tr>';
	}
	if (empty($model['rows'])) { $html .= '<tr><td colspan="' . $columnCount . '" class="opacitymedium center">' . $t(!empty($model['analysis_pending']) ? 'KreaBankWorklistMoreForFilters' : 'KreaBankWorklistEmpty') . '</td></tr>'; }
	$html .= '</tbody></table></div></form>' . $forms . $dialogs . '</div>';
	return $html;
}
