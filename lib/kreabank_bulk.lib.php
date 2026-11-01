<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/** Manual review may choose a unique weak suggestion, never an arbitrary tied document. */
function kreabankBulkSuggestionIsUnambiguous($suggestions)
{
	if (!$suggestions) {
		return false;
	}
	$first = $suggestions[0];
	foreach (array_slice($suggestions, 1) as $other) {
		if (($first['doc_type'] ?? '') === ($other['doc_type'] ?? '') && (int) ($first['doc_id'] ?? 0) === (int) ($other['doc_id'] ?? 0)) {
			continue;
		}
		if ((int) ($first['score'] ?? 0) - (int) ($other['score'] ?? 0) < 35) {
			return false;
		}
	}
	return true;
}

/** Financial and displayed source facts bound to the user's review. */
function kreabankBulkReviewLineFacts($line)
{
	$facts = array();
	foreach (array('rowid', 'fk_statement', 'native_bank_line_id', 'bank_account_id', 'direction', 'status') as $key) {
		$facts[$key] = (int) ($line[$key] ?? 0);
	}
	$facts['amount'] = number_format((float) ($line['amount'] ?? 0), 8, '.', '');
	foreach (array('currency', 'operation_date', 'value_date', 'statement_ref', 'counterparty_name', 'counterparty_iban', 'description', 'payment_reference', 'bank_reference') as $key) {
		$facts[$key] = (string) ($line[$key] ?? '');
	}
	return $facts;
}

/** Sign the exact displayed pair, its account/company/user scope and expiry. */
function kreabankBulkReviewSignature($row, $scope, $expires, $secret)
{
	$suggestion = $row['suggestion'] ?? array();
	$document = array('doc_id' => (int) ($suggestion['doc_id'] ?? 0));
	foreach (array('doc_type', 'doc_ref', 'doc_date', 'thirdparty_name') as $key) {
		$document[$key] = (string) ($suggestion[$key] ?? '');
	}
	$document['amount_open'] = number_format((float) ($suggestion['amount_open'] ?? 0), 8, '.', '');
	$payload = json_encode(array($scope, (int) $expires, kreabankBulkReviewLineFacts($row['line'] ?? array()),
		$document, number_format((float) ($row['allocation'] ?? 0), 8, '.', '')));
	if ($payload === false || strlen((string) $secret) < 32) {
		throw new RuntimeException('Cannot prepare bank reconciliation review');
	}
	return hash_hmac('sha256', $payload, $secret);
}

/** Manual approval must concern an unexpired, unchanged pair actually displayed to this user. */
function kreabankBulkReviewIsValid($row, $scope, $expires, $signature, $secret, $now, $confirmed, $accountId)
{
	$line = $row['line'] ?? array();
	if (!$confirmed || (int) $expires < (int) $now || (int) $expires > (int) $now + 1800
		|| (int) ($line['status'] ?? -1) !== 0 || (float) ($row['allocation'] ?? 0) <= 0
		|| ((int) $accountId > 0 && (int) ($line['bank_account_id'] ?? 0) !== (int) $accountId)
		|| !is_string($signature) || !preg_match('/^[a-f0-9]{64}$/D', $signature)) {
		return false;
	}
	return hash_equals(kreabankBulkReviewSignature($row, $scope, $expires, $secret), $signature);
}

/** Advance read-only review work, checking the budget between individual lines. */
function kreabankAdvanceBulkScan($state, $pendingTotal, $chunkSize, $budgetMs, $page, $limit, $fetchPending, $loadLine, $prepare, $clock = null)
{
	$clock = $clock ?: static function () { return microtime(true); };
	$deadline = $clock() + $budgetMs / 1000;
	$processed = 0;
	$offset = $page * $limit;
	$state['display_rows'] = isset($state['display_rows']) ? $state['display_rows'] : array();
	// Hydrate a new results page incrementally instead of matching 25 cached IDs again.
	foreach (array_slice($state['matched_rows'], $offset, $limit) as $match) {
		$id = (int) $match['line_id'];
		if (array_key_exists($id, $state['display_rows'])) {
			continue;
		}
		if ($processed > 0 && $clock() >= $deadline) {
			break;
		}
		$line = $loadLine($id);
		$state['display_rows'][$id] = $line && (int) $line['status'] === 0 ? $prepare($line) : null;
		if (!$state['display_rows'][$id]) {
			$state['matched_rows'] = array_values(array_filter($state['matched_rows'], static function ($candidate) use ($id) {
				return (int) $candidate['line_id'] !== $id;
			}));
		}
		$processed++;
	}
	if (empty($state['done']) && ($processed === 0 || $clock() < $deadline)) {
		$pending = $fetchPending($chunkSize, (int) $state['next_offset']);
		if (!$pending) {
			$state['done'] = true;
		}
		foreach ($pending as $line) {
			if ($processed > 0 && $clock() >= $deadline) {
				break;
			}
			$row = $prepare($line);
			$processed++;
			$state['next_offset']++;
			if ($row) {
				$suggestion = $row['suggestion'];
				$state['matched_rows'][] = array(
					'line_id' => (int) (!empty($line['rowid']) ? $line['rowid'] : 0),
					'doc_type' => (string) (!empty($suggestion['doc_type']) ? $suggestion['doc_type'] : ''),
					'doc_id' => (int) (!empty($suggestion['doc_id']) ? $suggestion['doc_id'] : 0),
					'score' => (int) (!empty($suggestion['score']) ? $suggestion['score'] : 0),
					'allocation' => (float) $row['allocation'],
				);
				$index = count($state['matched_rows']) - 1;
				if ($index >= $offset && $index < $offset + $limit) {
					$state['display_rows'][(int) $line['rowid']] = $row;
				}
			}
		}
	}
	$state['done'] = !empty($state['done']) || (int) $state['next_offset'] >= $pendingTotal;
	if (count($state['matched_rows']) > 5000) {
		$state['matched_rows'] = array_slice($state['matched_rows'], -5000);
	}
	$visibleIds = array_column(array_slice($state['matched_rows'], $offset, $limit), 'line_id');
	$state['display_rows'] = array_intersect_key($state['display_rows'], array_fill_keys($visibleIds, true));
	return $state;
}

/** Whether another request is needed for the queue or the current results page. */
function kreabankBulkScanNeedsWork($state, $page, $limit)
{
	if (empty($state['done'])) {
		return true;
	}
	foreach (array_slice($state['matched_rows'], $page * $limit, $limit) as $match) {
		if (!array_key_exists((int) $match['line_id'], $state['display_rows'])) {
			return true;
		}
	}
	return false;
}

/** Reject work started before a logout, company/view change or newer scan commit. */
function kreabankBulkScanCanSave($current, $expected, $identity, $currentIdentity)
{
	return $identity === $currentIdentity && !empty($expected['generation'])
		&& ($current['generation'] ?? '') === $expected['generation']
		&& (int) ($current['next_offset'] ?? -1) === (int) $expected['next_offset']
		&& (int) ($current['reload_count'] ?? -1) === (int) $expected['reload_count'];
}
