<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

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
