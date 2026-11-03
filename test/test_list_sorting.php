#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
require_once __DIR__ . '/../lib/kreabank_list.lib.php';
require_once __DIR__ . '/../lib/kreabank_bulk.lib.php';
require_once __DIR__ . '/../class/kreabankintegration.class.php';
function GETPOST($name, $filter) { return $_POST[$name] ?? $_GET[$name] ?? ''; }
function GETPOSTISSET($name) { return isset($_POST[$name]) || isset($_GET[$name]); }
function dol_escape_htmltag($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function dol_now() { return 100; }
$checks = 0;
function checkSort($ok, $message) { global $checks; if (!$ok) { throw new RuntimeException($message); } $checks++; }
$_GET = array('sortfield' => 'amount; DROP TABLE bank', 'sortorder' => 'DESC,ASC');
$_POST = array();
$sort = kreabankListSort(array('amount' => true), 'amount');
checkSort($sort['field'] === 'amount' && $sort['order'] === 'ASC', 'Unrecognized fields and directions fall back to the list allowlist');
$rows = array(array('id' => 1, 'amount' => '1000.10'), array('id' => 2, 'amount' => '-20.5'), array('id' => 3, 'amount' => '9.00'), array('id' => 4, 'amount' => '9.00'), array('id' => 5, 'amount' => null));
$value = static function ($row, $field) { return $row[$field]; };
checkSort(array_column(kreabankListOrderRows($rows, $sort, $value), 'id') === array(2, 3, 4, 1, 5), 'Amounts sort numerically with negatives, stable ties and nulls last');
$sort['order'] = 'DESC';
checkSort(array_column(kreabankListOrderRows($rows, $sort, $value), 'id') === array(1, 3, 4, 2, 5), 'Descending order keeps ties stable and nulls last');
$_GET = array('account_context' => 7, 'ref' => 'Statement & <test>', 'search_description' => 'A&B', 'limit' => 25, 'page' => 5, 'kind' => 'closed', 'action' => 'collect', 'token' => 'private', 'selected_line_ids' => array(1));
$_POST = array('action' => 'reconcile', 'review_confirmed' => 1);
$url = kreabankListSortUrl($sort, 'amount');
parse_str(parse_url($url, PHP_URL_QUERY), $params);
checkSort($params['account_context'] === '7' && $params['ref'] === $_GET['ref'] && $params['search_description'] === 'A&B' && $params['limit'] === '25', 'Sort links preserve account, statement, search and page size');
checkSort(!array_intersect(array('action', 'token', 'selected_line_ids', 'review_confirmed', 'page', 'kind'), array_keys($params)), 'Sorting cannot replay a write or keep an obsolete page');
$header = kreabankListHeader('<Amount>', 'amount', $sort, 'right');
checkSort(strpos($header, '&lt;Amount&gt;') !== false && strpos($header, 'aria-sort="descending"') !== false && strpos($header, 'scope="col"') !== false, 'Headers escape text and expose sorting to assistive technology');
$_GET['history_page'] = 4;
$auditSort = array('field' => 'datec', 'order' => 'DESC', 'prefix' => 'audit_');
parse_str(parse_url(kreabankListSortUrl($auditSort, 'login'), PHP_URL_QUERY), $params);
checkSort($params['audit_nav'] === '1' && $params['audit_page'] === '0' && $params['audit_sortfield'] === 'login' && $params['history_page'] === '4', 'Audit sorting has its own request namespace');
$_GET=array('sortfield'=>'overview','sortorder'=>'ASC','limit'=>2);
$overviewSort=kreabankListSort(array('ref'=>true,'balance'=>true),'overview');
checkSort($overviewSort['field']==='overview','The account overview can retain its compound default in pagination forms');
parse_str(parse_url(kreabankListSortUrl($overviewSort,'ref'),PHP_URL_QUERY),$params);
checkSort($params['sortfield']==='ref' && $params['sortorder']==='ASC' && $params['limit']==='2','Clicking a column replaces the compound default with ordinary sorting');
class SortTestDb
{
	public $sql;
	public function prefix() { return 'sort_'; }
	public function escape($value) { return addslashes($value); }
	public function idate($value) { return '2026-01-01'; }
	public function query($sql) { $this->sql = $sql; return true; }
}
$db = new SortTestDb();
$conf = (object) array('liste_limit'=>25);
$_GET=array();$_POST=array();
checkSort(kreabankListPagination(58)===array('page'=>0,'limit'=>25,'offset'=>0,'num'=>26),'Native list sizing reports one extra row for the next-page arrow');
$_GET=array('page'=>1,'limit'=>10);
checkSort(kreabankListPagination(58)===array('page'=>1,'limit'=>10,'offset'=>10,'num'=>11),'Page size and offset apply to the complete sorted result');
$_GET=array('page'=>0,'pageplusone'=>6,'limit'=>10);
checkSort(kreabankListPagination(58)===array('page'=>5,'limit'=>10,'offset'=>50,'num'=>8),'Native one-based page entry reaches the last partial page');
$_GET=array('page'=>PHP_INT_MAX,'limit'=>999999);
checkSort(kreabankListPagination(58)===array('page'=>0,'limit'=>5000,'offset'=>0,'num'=>58),'Invalid pages and sizes are bounded before offset multiplication');
$_GET=array('page'=>9,'limit'=>10);
checkSort(kreabankListPagination(0)===array('page'=>0,'limit'=>10,'offset'=>0,'num'=>0),'An empty list resets stale pagination');
$report = new KreaBankIntegration($db, 7);
$report->accounts('cash', 12, 'balance', 'DESC');
checkSort(strpos($db->sql, 'ORDER BY balance DESC,ba.rowid') !== false && strpos($db->sql, 'ba.entity=7') !== false && strpos($db->sql, 'ba.rowid=12') !== false && strpos($db->sql, 'ba.courant=2') !== false, 'SQL sorting preserves company, account and account-type boundaries');
$report->accounts('all', 0, 'ref; DELETE', 'DESC; DELETE');
checkSort(strpos($db->sql, 'DELETE') === false && strpos($db->sql, 'ORDER BY ba.ref ASC,ba.rowid') !== false, 'SQL sort expressions and direction come only from allowlisted values');
// Sort compact bulk results before selecting a page, without retaining all presentation.
$source = array();
foreach (array(100, -20, 9, 300) as $index => $amount) { $source[] = array('rowid' => $index + 1, 'status' => 0, 'amount' => $amount); }
$fetch = static function ($limit, $offset) use ($source) { return array_slice($source, $offset, $limit); };
$load = static function ($id) use ($source) { return $source[$id - 1]; };
$prepare = static function ($line) { return array('line' => $line, 'suggestion' => array('doc_id' => $line['rowid'], 'doc_type' => 'native_bank', 'score' => 170), 'allocation' => abs($line['amount'])); };
$state = array('matched_rows' => array(), 'display_rows' => array(), 'next_offset' => 0);
$sort = array('field' => 'amount', 'order' => 'ASC', 'prefix' => '');
$state = kreabankAdvanceBulkScan($state, 4, 20, 1200, 0, 2, $fetch, $load, $prepare, null, $sort);
$state = kreabankAdvanceBulkScan($state, 4, 20, 1200, 0, 2, $fetch, $load, $prepare, null, $sort);
checkSort(array_column($state['matched_rows'], 'line_id') === array(2, 3, 1, 4), 'Bulk review sorts the complete scanned result before pagination');
checkSort(count($state['display_rows']) === 2 && isset($state['display_rows'][2], $state['display_rows'][3]) && !kreabankBulkScanNeedsWork($state, 0, 2), 'Only the sorted visible page is hydrated and polling stops when complete');
$sort['order'] = 'DESC';
$state['display_rows'] = array();
$state = kreabankAdvanceBulkScan($state, 4, 20, 1200, 0, 2, $fetch, $load, $prepare, null, $sort);
checkSort(isset($state['display_rows'][4], $state['display_rows'][1]) && $state['next_offset'] === 4, 'Changing bulk order hydrates the new page without rescanning the backlog');
$large = array();
for ($i = 0; $i < 5001; $i++) { $large[] = array('line_id' => $i + 1, 'scan_position' => $i, 'amount' => -$i); }
$retained = array();
foreach (array('ASC', 'DESC') as $order) {
	$state = array('matched_rows' => $large, 'display_rows' => array(), 'next_offset' => 5001, 'done' => true);
	$state = kreabankAdvanceBulkScan($state, 5001, 20, 1200, 0, 1, $fetch, static function ($id) { return array('rowid' => $id, 'status' => 0, 'amount' => -$id); }, $prepare, null, array('field' => 'amount', 'order' => $order));
	$retained[$order] = array_column($state['matched_rows'], 'line_id');
	sort($retained[$order]);
}
checkSort($retained['ASC'] === $retained['DESC'] && count($retained['ASC']) === 5000 && $retained['ASC'][0] === 2, 'Changing display order cannot change the scan-retention candidate set');
echo "OK: $checks list sorting checks\n";
