<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

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
	$allowed = array('check', 'search', 'layer-group', 'save', 'times', 'exchange-alt', 'eye', 'undo', 'sync-alt', 'chevron-down', 'chevron-up');
	return '<span class="fas fa-' . (in_array($icon, $allowed, true) ? $icon : 'eye') . '" aria-hidden="true"></span>';
}

function kreabankWorklistButton($label, $icon, $attributes = '', $primary = false, $disabled = false)
{
	return '<button class="button kb-wl-icon' . ($primary ? ' kb-wl-primary' : '') . '" title="' . kreabankWorklistEscape($label)
		. '" aria-label="' . kreabankWorklistEscape($label) . '"' . ($disabled ? ' disabled' : '') . ' ' . $attributes . '>'
		. kreabankWorklistIcon($icon) . '<span class="kb-wl-sr">' . kreabankWorklistEscape($label) . '</span></button>';
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

/** Candidate evidence uses the same compact structure for every accounting record type. */
function kreabankWorklistCandidate($candidate, $langs)
{
	$html = '<strong>' . kreabankWorklistEscape($candidate['reference'] ?? '') . '</strong>';
	if (!empty($candidate['description'])) { $html .= '<span class="kb-wl-caption">' . kreabankWorklistEscape($candidate['description']) . '</span>'; }
	$html .= '<span class="kb-wl-caption">' . kreabankWorklistEscape(kreabankWorklistDate($candidate['date'] ?? ''));
	if (isset($candidate['amount'])) { $html .= ' · ' . kreabankWorklistMoney($candidate['amount'], $candidate['currency'] ?? ''); }
	elseif (array_key_exists('amount', $candidate)) { $html .= ' · —'; }
	$html .= '</span>';
	if (!empty($candidate['consequence'])) { $html .= '<span class="kb-wl-caption">' . kreabankWorklistEscape(kreabankWorklistTranslate($langs, $candidate['consequence'])) . '</span>'; }
	return $html;
}

/** The same reviewed account-change dialog serves list proposals and expanded results. */
function kreabankWorklistCorrection($candidate, $line, $id, $model, $langs)
{
	if (empty($model['can_write']) || empty($candidate['correction'])) { return array('button'=>'', 'form'=>'', 'dialog'=>''); }
	$e = 'kreabankWorklistEscape';
	$t = static function ($key) use ($langs) { return kreabankWorklistEscape(kreabankWorklistTranslate($langs, $key)); };
	$correction = $candidate['correction'];
	$dialogId = $id . '-account';
	$actionId = $dialogId . '-form';
	$dialog = '<dialog class="kb-wl-dialog" id="' . $dialogId . '" aria-labelledby="' . $dialogId . '-title" aria-describedby="' . $dialogId . '-effect"><h2 id="' . $dialogId . '-title">' . $t('KreaBankWorklistChangeAccount') . '</h2>'
		. '<p><strong>' . $e($candidate['reference'] ?? '') . '</strong></p><dl><dt>' . $t('KreaBankDate') . '</dt><dd>' . $e(kreabankWorklistDate($candidate['date'] ?? '')) . '</dd><dt>' . $t('Amount') . '</dt><dd>' . kreabankWorklistMoney($candidate['amount'], $candidate['currency'] ?? $line['currency']) . '</dd><dt>' . $t('KreaBankWorklistStatementAccount') . '</dt><dd>' . $e($line['bank_account_ref']) . '</dd><dt>' . $t('KreaBankWorklistFromAccount') . '</dt><dd>' . $e($correction['from_account_ref'] ?? $candidate['account_ref'] ?? '') . '</dd><dt>' . $t('KreaBankWorklistToAccount') . '</dt><dd>' . $e($correction['to_account_ref'] ?? $line['bank_account_ref']) . '</dd></dl><p id="' . $dialogId . '-effect">' . $t('KreaBankWorklistCorrectionEffect') . '</p><div class="kb-wl-dialog-actions"><button type="button" class="button" data-kb-dialog-close autofocus>' . $t('Cancel') . '</button><button type="submit" class="button" form="' . $actionId . '">' . $t('Save') . '</button></div></dialog>';
	return array('button'=>kreabankWorklistButton(kreabankWorklistTranslate($langs, 'KreaBankWorklistChangeAccount'), 'exchange-alt', 'type="button" data-kb-dialog="' . $dialogId . '" aria-haspopup="dialog"'),
		'form'=>kreabankWorklistPostForm($actionId, $correction, $model), 'dialog'=>$dialog);
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
	$forms = '';
	$dialogs = '';
	$filterId = 'searchFormList';
	$stateWithoutFilters = array_filter($state, static function ($key) { return strpos($key, 'search_') !== 0 && !in_array($key, array('page', 'expand', 'mode', 'entry_search', 'q'), true); }, ARRAY_FILTER_USE_KEY);
	if (!empty($model['pagination'])) { unset($stateWithoutFilters['limit']); }
	$formatSample = html_entity_decode(strip_tags(kreabankPrice('1000.10')), ENT_QUOTES, 'UTF-8');
	$moneyFormat = preg_match('/^\s*1([^0-9]*)000([^0-9]+)10\s*$/uD', $formatSample, $separators)
		? ' data-group-separator="' . $e($separators[1]) . '" data-decimal-separator="' . $e($separators[2]) . '"' : '';
	$html = '<div class="kb-worklist" data-locale="' . $e(str_replace('_', '-', $langs->defaultlang ?? 'en_US')) . '"' . $moneyFormat . '>';
	if (!$canWrite) { $html .= '<p class="opacitymedium" role="status">' . $t('KreaBankWorkspaceReadOnly') . '</p>'; }
	if (!empty($model['notice'])) { $html .= '<p role="status">' . $e($model['notice']) . '</p>'; }
	if (!empty($model['scan'])) {
		$scan = $model['scan'];
		$html .= '<div class="kb-wl-scan" role="status" aria-live="polite" data-page-ready="' . (!empty($scan['page_ready']) ? '1' : '0') . '" data-ready="' . $t('KreaBankWorklistResultsReady') . '" data-failed="' . $t('KreaBankWorklistAnalysisFailed') . '" data-changed="' . $t('KreaBankWorklistAnalysisChanged') . '" data-paused="' . $t('KreaBankWorklistAnalysisPaused') . '"><span data-kb-scan-message>' . $e($scan['message'] ?? '') . '</span><span data-kb-scan-count>' . (int) ($scan['done'] ?? 0) . ' / ' . (int) ($scan['total'] ?? 0) . '</span>';
		if (isset($scan['done'], $scan['total']) && (int) $scan['total'] > 0) {
			$html .= '<progress data-kb-scan-progress max="' . (int) $scan['total'] . '" value="' . min((int) $scan['total'], max(0, (int) $scan['done'])) . '"></progress>';
		}
		if (!empty($scan['action'])) {
			$id = 'kb-wl-scan-form';
			$hasExpandedRow = (bool) array_filter((array) ($model['rows'] ?? array()), static function ($row) { return !empty($row['expanded']); });
			$html .= kreabankWorklistButton(kreabankWorklistTranslate($langs, 'KreaBankWorklistContinueAnalysis'), 'sync-alt', 'type="submit" form="' . $id . '"', false, $hasExpandedRow);
			$forms .= kreabankWorklistPostForm($id, $scan['action'], $model, ' data-kb-scan');
		}
		$html .= '</div>';
	}
	$html .= '<form method="get" action="' . $e(strtok($model['url'], '?')) . '" id="' . $filterId . '" name="searchFormList" role="search" aria-label="' . $t('Filters') . '">'
		. kreabankWorklistFields($stateWithoutFilters) . ($model['pagination'] ?? '') . '<div class="div-table-responsive"><table class="tagtable liste kb-wl-table" id="kb-worklist-table">';
	$html .= '<thead><tr class="liste_titre_filter">';
	$html .= '<td class="liste_titre"><label class="kb-wl-sr" for="kb-wl-date-from">' . $t('KreaBankWorklistDateFrom') . '</label><input class="flat kb-wl-date" id="kb-wl-date-from" type="date" name="search_date_from" value="' . $e($filters['search_date_from'] ?? '') . '"><label class="kb-wl-sr" for="kb-wl-date-to">' . $t('KreaBankWorklistDateTo') . '</label><input class="flat kb-wl-date" id="kb-wl-date-to" type="date" name="search_date_to" value="' . $e($filters['search_date_to'] ?? '') . '"></td>';
	$accountFilter = static function ($name, $label, $withNone = false) use ($model, $filters, $e, $t) {
		$html = '<label class="kb-wl-sr" for="kb-wl-' . $name . '">' . $t($label) . '</label><select class="flat maxwidth150" id="kb-wl-' . $name . '" name="' . $name . '"><option value="">' . $t('All') . '</option>';
		if ($withNone) { $html .= '<option value="none"' . (($filters[$name] ?? '') === 'none' ? ' selected' : '') . '>' . $t('KreaBankWorklistNotBanked') . '</option>'; }
		foreach ((array) ($model['accounts'] ?? array()) as $account) {
			$id = (string) ($account['id'] ?? $account['rowid'] ?? '');
			$html .= '<option value="' . $e($id) . '"' . ((string) ($filters[$name] ?? '') === $id ? ' selected' : '') . '>' . $e($account['ref'] ?? '') . '</option>';
		}
		return $html . '</select>';
	};
	$html .= '<td class="liste_titre">' . $accountFilter('search_account', 'KreaBankWorklistStatementAccount') . '</td>';
	foreach (array('search_description'=>'KreaBankReviewStatementLine', 'search_amount'=>'Amount', 'search_match'=>'KreaBankWorklistMatch') as $name => $label) {
		$html .= '<td class="liste_titre"><label class="kb-wl-sr" for="kb-wl-' . $name . '">' . $t($label) . '</label><input class="flat ' . ($name === 'search_amount' ? 'width75 right' : 'maxwidth150') . '" id="kb-wl-' . $name . '" type="search" name="' . $name . '" value="' . $e($filters[$name] ?? '') . '"></td>';
	}
	$html .= '<td class="liste_titre">' . $accountFilter('search_payment_account', 'KreaBankWorklistPaymentAccount', true) . '</td><td class="liste_titre"><label class="kb-wl-sr" for="kb-wl-status">' . $t('Status') . '</label><select class="flat maxwidth125" name="search_status" id="kb-wl-status">';
	foreach (array('pending'=>'KreaBankWorklistPending', 'draft'=>'Draft', 'reconciled'=>'KreaBankWorklistReconciled', 'review'=>'KreaBankLinkNeedsReview', 'all'=>'All') as $value => $label) {
		$html .= '<option value="' . $value . '"' . (($filters['search_status'] ?? 'pending') === $value ? ' selected' : '') . '>' . $t($label) . '</option>';
	}
	$html .= '</select></td><td class="liste_titre center">';
	$html .= $model['filter_buttons'] ?? (kreabankWorklistButton(kreabankWorklistTranslate($langs, 'Search'), 'search', 'type="submit" name="button_search" value="1"') . kreabankWorklistButton(kreabankWorklistTranslate($langs, 'RemoveFilter'), 'times', 'type="submit" name="button_removefilter" value="1"'));
	$html .= '</td></tr><tr class="liste_titre">';
	$columns = array('operation_date'=>'KreaBankDate', 'bank_account_ref'=>'KreaBankWorklistStatementAccount', 'description'=>'KreaBankReviewStatementLine', 'amount'=>'Amount', 'match'=>'KreaBankWorklistMatch', 'payment_account'=>'KreaBankWorklistPaymentAccount', 'status'=>'Status');
	foreach ($columns as $field => $label) {
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
		$batchMode = !empty($row['batch_mode']);
		$batchReview = !empty($row['batch_review']);
		$activeBatch = !empty($row['batch_active']);
		$draft = (array) ($row['draft'] ?? array());
		$done = (int) ($line['status'] ?? 0) === 2 || !empty($row['reconciled']);
		$needsReview = !empty($row['needs_review']);
		$status = $needsReview ? 'KreaBankLinkNeedsReview' : ($done ? 'KreaBankWorklistReconciled' : ($draft ? 'Draft' : 'KreaBankWorklistPending'));
		$html .= '<tr class="oddeven kb-wl-row" id="' . $id . '" data-line-key="' . $e($key) . '"><td class="nowrap">' . $e(kreabankWorklistDate($line['operation_date'] ?? $line['date'] ?? '')) . '</td><td>' . $e($line['bank_account_ref'] ?? '') . '</td><td class="kb-wl-statement">' . $e($line['description'] ?? '') . '</td><td class="right nowrap">' . kreabankWorklistMoney($line['amount'], $line['currency']) . '</td><td class="kb-wl-match">';
		if ($review) { $html .= kreabankWorklistCandidate($review, $langs); }
		elseif ($draft) { $html .= '<strong>' . $e(($draft['name'] ?? '') ?: kreabankWorklistTranslate($langs, 'KreaBankWorklistPaymentBatch')) . '</strong><span class="kb-wl-caption">' . $t('Draft') . ' · ' . count((array) ($draft['member_keys'] ?? array())) . ' ' . $t('KreaBankWorklistPayments') . '</span>'; }
		else { $html .= '<span class="opacitymedium">' . $t(!empty($row['ambiguous']) ? 'KreaBankWorklistAmbiguous' : (!empty($row['pending_analysis']) ? 'KreaBankWorklistAwaitingAnalysis' : 'KreaBankWorklistNoMatch')) . '</span>'; }
		if (!empty($row['notice'])) { $html .= '<span class="kb-wl-caption" role="status">' . $e($row['notice']) . '</span>'; }
		$html .= '</td><td>' . $e(trim((string) ($review['account_ref'] ?? $row['payment_account_ref'] ?? '')) ?: '—');
		if (!$done && !empty($review['correction'])) {
			$correctionUi = kreabankWorklistCorrection($review, $line, $id . '-proposal', $model, $langs);
			$html .= $correctionUi['button']; $forms .= $correctionUi['form']; $dialogs .= $correctionUi['dialog'];
		}
		$html .= '</td><td><span class="badge ' . ($needsReview ? 'badge-status7' : ($done ? 'badge-status4' : ($draft ? 'badge-status1' : 'badge-status0'))) . '">' . $t($status) . '</span></td><td class="right nowrap kb-wl-actions">';
		if ($canWrite && !$done && !empty($review['action'])) {
			$formId = $id . '-approve';
			$html .= kreabankWorklistButton(kreabankWorklistTranslate($langs, $review['action']['label'] ?? 'KreaBankWorklistReconcile'), 'check', 'type="submit" form="' . $formId . '"', true);
			$forms .= kreabankWorklistPostForm($formId, $review['action'], $model);
		}
		if (!$done) {
			foreach (array('search'=>'Search', 'batch'=>'KreaBankWorklistCreateBatch') as $mode => $label) {
				if ($mode === 'batch' && (!$canWrite || !empty($row['batch_unavailable']))) { continue; }
				$url = kreabankWorklistUrl($model, array('expand'=>$key, 'mode'=>$mode));
				$html .= '<a class="button kb-wl-icon" href="' . $e($url) . '" title="' . $t($label) . '" aria-label="' . $t($label) . '" aria-controls="' . $id . '-detail" aria-expanded="' . ($expanded && ($batchMode === ($mode === 'batch')) ? 'true' : 'false') . '">' . kreabankWorklistIcon($mode === 'batch' ? 'layer-group' : 'search') . '<span class="kb-wl-sr">' . $t($label) . '</span></a>';
			}
		}
		if ($done && $activeBatch) {
			$html .= '<a class="button kb-wl-icon" href="' . $e(kreabankWorklistUrl($model, array('expand'=>$key, 'mode'=>'view'))) . '" title="' . $t('KreaBankWorklistViewBatch') . '" aria-label="' . $t('KreaBankWorklistViewBatch') . '" aria-controls="' . $id . '-detail" aria-expanded="' . ($expanded ? 'true' : 'false') . '">' . kreabankWorklistIcon('eye') . '</a>';
		}
		$html .= '</td></tr>';
		if (!$expanded || ($done && !$activeBatch)) { continue; }
		$html .= '<tr class="kb-wl-detail-row" id="' . $id . '-detail"><td colspan="8"><section class="kb-wl-detail" aria-label="' . $e($line['description'] ?? '') . '"><div class="kb-wl-detail-heading"><strong>' . $t($batchMode || $activeBatch ? 'KreaBankWorklistPaymentBatch' : 'KreaBankWorklistFindMatch') . '</strong><a class="button kb-wl-icon" href="' . $e(kreabankWorklistUrl($model, array('expand'=>null, 'mode'=>null, 'entry_search'=>null, 'q'=>null))) . '" title="' . $t('Close') . '" aria-label="' . $t('Close') . '">' . kreabankWorklistIcon('times') . '</a></div>';
		$searchId = $id . '-search';
		if (!$activeBatch && !$batchReview) {
		$html .= '<div class="kb-wl-search"><label for="' . $searchId . '-query">' . $t('KreaBankWorkspaceSearchEntries') . '</label><input class="flat" form="' . $searchId . '" type="search" name="q" id="' . $searchId . '-query" value="' . $e($row['search'] ?? '') . '">' . kreabankWorklistButton(kreabankWorklistTranslate($langs, 'Search'), 'search', 'type="submit" form="' . $searchId . '"') . '</div>';
		$searchState = $state;
		unset($searchState['q'], $searchState['entry_search']);
		$forms .= '<form method="get" action="' . $e(strtok($model['url'], '?')) . '" id="' . $searchId . '">' . kreabankWorklistFields(array_merge($searchState, array('expand'=>$key, 'mode'=>$batchMode ? 'batch' : 'search'))) . '</form>';
		}
		$batch = (array) ($row['batch_form'] ?? array());
		$batchId = $id . '-batch';
		if ($batchMode && $canWrite && !$batchReview) {
			$nameField = $batch['name_field'] ?? 'batch_name';
			$html .= '<div class="kb-wl-batch-name"><label for="' . $batchId . '-name">' . $t('KreaBankWorklistBatchName') . '</label><input class="flat" form="' . $batchId . '" type="text" name="' . $e($nameField) . '" id="' . $batchId . '-name" maxlength="80" value="' . $e($draft['name'] ?? '') . '"></div>';
		}
		$candidates = (array) ($batchMode ? ($row['batch_candidates'] ?? array()) : ($row['candidates'] ?? array()));
		if ($activeBatch || $batchReview) {
			$candidates = $row['batch_components'] ?? array_values(array_filter($candidates, static function ($candidate) use ($draft) { return !empty($candidate['selected']) || in_array((string) ($candidate['key'] ?? ''), array_map('strval', (array) ($draft['member_keys'] ?? array())), true); }));
			if (!empty($draft['name'])) { $html .= '<p><strong>' . $e($draft['name']) . '</strong></p>'; }
		}
		$otherCandidates = (array) ($row['other_accounts'] ?? $row['other_account'] ?? array());
		if ($activeBatch || $batchReview) { $otherCandidates = array(); }
		if (isset($otherCandidates['key'])) { $otherCandidates = array($otherCandidates); }
		if (!$candidates && !$otherCandidates) { $html .= '<p class="opacitymedium">' . $t('KreaBankWorklistNoResults') . '</p>'; }
		if ($batchMode) {
			$html .= '<div class="kb-wl-batch"' . (!$batchReview && $canWrite ? ' data-kb-batch' : '') . ' data-target="' . $e($line['amount_decimal'] ?? $line['amount']) . '" data-currency="' . $e($line['currency']) . '" data-incomplete="' . $t('KreaBankWorklistBatchIncomplete') . '" data-ready="' . $t('KreaBankWorklistReady') . '"><div class="kb-wl-members">';
		}
		foreach (array_merge($candidates, $otherCandidates) as $index => $candidate) {
			$candidateId = $id . '-candidate-' . $index;
			$isOther = in_array($candidate, $otherCandidates, true);
			$html .= '<div class="kb-wl-candidate' . ($isOther ? ' kb-wl-other-account' : '') . '">';
			if ($batchMode && !$batchReview && $canWrite) {
				$selected = !empty($candidate['selected']) || in_array((string) $candidate['key'], array_map('strval', (array) ($draft['member_keys'] ?? array())), true);
				$selectable = $canWrite && !$isOther && !empty($candidate['selectable']);
				$html .= '<input type="checkbox" id="' . $candidateId . '" form="' . $batchId . '" name="' . $e($batch['selection_name'] ?? 'batch_members[]') . '" value="' . $e($candidate['key']) . '" data-kb-member data-amount="' . $e($candidate['amount']) . '"' . ($selected ? ' checked' : '') . (!$selectable ? ' disabled' : '') . ' aria-label="' . $e(kreabankWorklistTranslate($langs, 'Select') . ' ' . ($candidate['reference'] ?? '')) . '">';
			}
			$evidence = kreabankWorklistCandidate($candidate, $langs);
			if ($batchMode && !$batchReview && $canWrite && $selectable) { $evidence = '<label class="kb-wl-member-label" for="' . $candidateId . '">' . $evidence . '</label>'; }
			$html .= '<div class="kb-wl-candidate-evidence">' . $evidence . '<span class="kb-wl-caption">' . $t('KreaBankWorklistPaymentAccount') . ': <strong>' . $e(trim((string) ($candidate['account_ref'] ?? '')) ?: '—') . '</strong></span>';
			if ($isOther) { $html .= '<span class="kb-wl-caption">' . $t('KreaBankWorklistOtherAccount') . '</span>'; }
			$html .= '</div><div class="kb-wl-candidate-actions">';
			if (!$batchMode && !$isOther && !$done && $canWrite && !empty($candidate['action'])) {
				$actionId = $candidateId . '-approve';
				$html .= kreabankWorklistButton(kreabankWorklistTranslate($langs, $candidate['action']['label'] ?? 'KreaBankWorklistReconcile'), 'check', 'type="submit" form="' . $actionId . '"', true);
				$forms .= kreabankWorklistPostForm($actionId, $candidate['action'], $model);
			}
			if (!$done && !$batchReview && $canWrite && !empty($candidate['correction'])) {
				$correctionUi = kreabankWorklistCorrection($candidate, $line, $candidateId, $model, $langs);
				$html .= $correctionUi['button']; $forms .= $correctionUi['form']; $dialogs .= $correctionUi['dialog'];
			}
			$html .= '</div></div>';
		}
		if ($batchMode) {
			$html .= '</div><div class="kb-wl-totals" role="status" aria-live="polite" aria-atomic="true"><div><span>' . $t('KreaBankReviewStatementLine') . '</span><strong>' . kreabankWorklistMoney($line['amount'], $line['currency']) . '</strong></div><div><span>' . $t('KreaBankWorkspaceSelectedTotal') . '</span><strong data-kb-selected-total>' . (isset($batch['selected_total']) ? kreabankWorklistMoney($batch['selected_total'], $line['currency']) : '—') . '</strong></div><div><span>' . $t('KreaBankWorkspaceDifference') . '</span><strong data-kb-difference>' . (isset($batch['difference']) ? kreabankWorklistMoney($batch['difference'], $line['currency']) : '—') . '</strong></div></div><div class="kb-wl-batch-footer"><span class="opacitymedium" data-kb-selected-count data-label="' . $t('KreaBankWorklistSelected') . '"></span>';
			if ($canWrite && $batch && !$batchReview) {
				$html .= kreabankWorklistButton(kreabankWorklistTranslate($langs, 'KreaBankWorklistSaveDraft'), 'save', 'type="submit" form="' . $batchId . '" name="action" value="' . $e($batch['save_action'] ?? 'save_draft') . '"');
				$html .= kreabankWorklistButton(kreabankWorklistTranslate($langs, 'KreaBankWorklistReviewBatch'), 'eye', 'type="submit" form="' . $batchId . '" name="action" value="' . $e($batch['review_action'] ?? 'review_batch') . '" data-kb-batch-review aria-describedby="' . $batchId . '-feedback"', true);
				$forms .= kreabankWorklistPostForm($batchId, array('fields'=>$batch['fields'] ?? array()), $model);
			}
			$html .= '</div>';
			if (!$batchReview && $canWrite) { $html .= '<p class="kb-wl-batch-feedback opacitymedium" id="' . $batchId . '-feedback" data-kb-batch-feedback role="status">' . $t('KreaBankWorklistBatchIncomplete') . '</p>'; }
			$html .= '</div>';
		}
		if ($canWrite && !empty($row['batch_review'])) {
			$actionId = $id . '-confirm-batch';
			$html .= '<div class="kb-wl-confirm-batch"><span>' . $t('KreaBankWorklistBatchReviewed') . '</span>' . kreabankWorklistButton(kreabankWorklistTranslate($langs, 'KreaBankWorklistReconcileBatch'), 'check', 'type="submit" form="' . $actionId . '"', true) . '</div>';
			$forms .= kreabankWorklistPostForm($actionId, $row['batch_review'], $model);
		}
		if ($canWrite && $activeBatch && !empty($row['undo_review'])) {
			$actionId = $id . '-undo-batch';
			$html .= '<div class="kb-wl-confirm-batch"><span>' . $t('KreaBankWorklistUndoEffect') . '</span>' . kreabankWorklistButton(kreabankWorklistTranslate($langs, $row['undo_review']['label'] ?? 'KreaBankWorklistUndoBatch'), 'undo', 'type="submit" form="' . $actionId . '"') . '</div>';
			$forms .= kreabankWorklistPostForm($actionId, $row['undo_review'], $model);
		}
		$html .= '</section></td></tr>';
	}
	if (empty($model['rows'])) { $html .= '<tr><td colspan="8" class="opacitymedium center">' . $t('KreaBankWorklistEmpty') . '</td></tr>'; }
	$html .= '</tbody></table></div></form>' . $forms . $dialogs . '</div>';
	return $html;
}
