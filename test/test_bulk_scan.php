#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
require_once __DIR__ . '/../lib/kreabank_bulk.lib.php';
$checks = 0;
function checkBulk($condition, $message) { global $checks; if (!$condition) { throw new RuntimeException($message); } $checks++; }
$now = 0;
$clock = static function () use (&$now) { return $now; };
$source = array();
for ($id = 1; $id <= 141; $id++) { $source[] = array('rowid' => $id, 'status' => 0); }
$fetch = static function ($limit, $offset) use ($source) { return array_slice($source, $offset, $limit); };
$load = static function ($id) use ($source) { return $source[$id - 1] ?? null; };
$prepared = array();
$prepare = static function ($line) use (&$now, &$prepared) {
	$now += 0.7;
	$prepared[] = $line['rowid'];
	return array('line' => $line, 'suggestion' => array('doc_type' => 'native_bank', 'doc_id' => 1000 + $line['rowid'], 'score' => 100), 'allocation' => 10, 'preselected' => false);
};
$state = array('done' => false, 'next_offset' => 0, 'matched_rows' => array(), 'display_rows' => array());
$state = kreabankAdvanceBulkScan($state, 141, 200, 1200, 0, 25, $fetch, $load, $prepare, $clock);
checkBulk($prepared === array(1, 2) && $state['next_offset'] === 2, 'The budget stops after two slow lines, not the entire 200-line chunk');
checkBulk(!$state['done'] && count($state['matched_rows']) === 2, 'Partial progress retains its exact cursor and matches');
checkBulk(isset($state['display_rows'][1]) && !$state['display_rows'][1]['preselected'], 'Prepared display rows retain the shared resolver safety result');
$state = kreabankAdvanceBulkScan($state, 141, 200, 1200, 0, 25, $fetch, $load, $prepare, $clock);
checkBulk($prepared === array(1, 2, 3, 4), 'The next request resumes without repeating or skipping lines');
$state['done'] = true;
$prepared = array();
$again = kreabankAdvanceBulkScan($state, 141, 200, 1200, 0, 25, $fetch, $load, $prepare, $clock);
checkBulk(!$prepared && !kreabankBulkScanNeedsWork($again, 0, 25), 'A prepared page performs no repeated matching');
$again['display_rows'] = array();
checkBulk(kreabankBulkScanNeedsWork($again, 0, 25), 'Cached compact IDs are hydrated before being shown');
$again = kreabankAdvanceBulkScan($again, 141, 200, 1200, 0, 25, $fetch, $load, $prepare, $clock);
checkBulk($prepared === array(1, 2) && count($again['display_rows']) === 2, 'Page hydration also respects the per-line budget');
checkBulk(kreabankBulkScanNeedsWork($again, 0, 25), 'Unhydrated results remain explicitly incomplete');
$again['display_rows'] = array();
$loadStale = static function ($id) { return array('rowid' => $id, 'status' => 2); };
$again = kreabankAdvanceBulkScan($again, 141, 200, 1200, 0, 25, $fetch, $loadStale, $prepare, $clock);
checkBulk(!kreabankBulkScanNeedsWork($again, 0, 25) && !$again['matched_rows'] && count(array_filter($again['display_rows'])) === 0, 'Reconciled cached lines leave the result count and are not offered again or retried forever');
$state = array('done' => false, 'next_offset' => 140, 'matched_rows' => array(), 'display_rows' => array());
$state = kreabankAdvanceBulkScan($state, 141, 200, 1200, 0, 25, $fetch, $load, $prepare, $clock);
checkBulk($state['done'] && $state['next_offset'] === 141, 'The last partial chunk completes the queue');
$state = array('done' => false, 'next_offset' => 0, 'matched_rows' => array(), 'display_rows' => array());
$miss = static function ($line) use (&$now) { $now += 0.7; return null; };
$state = kreabankAdvanceBulkScan($state, 141, 200, 1200, 0, 25, $fetch, $load, $miss, $clock);
checkBulk($state['next_offset'] === 2 && !$state['matched_rows'], 'Unmatched lines advance under the same budget');
$state = kreabankAdvanceBulkScan($state, 141, 200, 1200, 0, 25, static function () { return array(); }, $load, $miss, $clock);
checkBulk($state['done'], 'An empty queue stops polling');
$expected = array('generation' => 'synthetic-epoch', 'next_offset' => 4, 'reload_count' => 2);
$identity = array('synthetic-user', 7);
checkBulk(kreabankBulkScanCanSave($expected, $expected, $identity, $identity), 'Unchanged session and progress can commit');
foreach (array(array('generation' => 'new-view'), array('next_offset' => 6), array('reload_count' => 3)) as $change) {
	checkBulk(!kreabankBulkScanCanSave(array_merge($expected, $change), $expected, $identity, $identity), 'An older scan cannot overwrite a changed view or newer cursor');
}
checkBulk(!kreabankBulkScanCanSave($expected, $expected, $identity, array('synthetic-user', 8)), 'Company switching discards the old response');
checkBulk(!kreabankBulkScanCanSave($expected, $expected, $identity, array('another-user', 7)), 'Login changes discard the old response');
checkBulk(!kreabankBulkScanCanSave(array(), $expected, $identity, $identity), 'Confirmation or logout clearing the cache prevents resurrection');
$quick = static function ($line) { return array('line' => $line, 'suggestion' => array('doc_type' => 'native_bank', 'doc_id' => $line['rowid'], 'score' => 100), 'allocation' => 10); };
$state = kreabankAdvanceBulkScan(array('done' => false, 'next_offset' => 0, 'matched_rows' => array(), 'display_rows' => array()), 141, 200, 1200, 0, 25, $fetch, $load, $quick, $clock);
checkBulk(count($state['matched_rows']) === 141 && count($state['display_rows']) === 25, 'Fast scans retain only the visible page presentation, not the entire backlog');
$state = kreabankAdvanceBulkScan($state, 141, 200, 1200, 1, 25, $fetch, $load, $quick, $clock);
checkBulk(array_keys($state['display_rows']) === range(26, 50), 'Changing results pages hydrates only that page and drops prior presentation');
echo 'OK: ' . $checks . " bounded bulk scan checks\n";
