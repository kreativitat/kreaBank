<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
require_once __DIR__ . '/../class/KreaBankHistory.class.php';

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
