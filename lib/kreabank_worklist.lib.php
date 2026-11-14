<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/** Invoice/tax approval creates its payment in the statement account; other targets retain their recorded account. */
function kreabankWorklistPaymentAccount($pair)
{
	$suggestion = $pair['suggestion'];
	$planned = in_array($suggestion['doc_type'], array('customer_invoice','supplier_invoice','tax'), true);
	return array('id'=>(int) ($planned ? ($pair['line']['bank_account_id'] ?? 0) : ($suggestion['bank_account_id'] ?? 0)),
		'ref'=>(string) ($planned ? ($pair['line']['bank_account_ref'] ?? '') : ($suggestion['bank_account_ref'] ?? '')), 'planned'=>$planned);
}

/** Request state for the separate list. Financial actions never enter read URLs. */
function kreabankWorklistState($request, $defaultLimit = 25)
{
	$columns = array('operation_date','bank_account_ref','description','amount','match','payment_account','status');
	$clear = false;
	foreach (array('button_removefilter','button_removefilter_x','button_removefilter.x') as $button) { if (isset($request[$button])) { $clear = true; } }
	$filters = array();
	foreach (array('date_from','date_to','account','description','amount','match','payment_account','status') as $name) {
		$value = $clear ? '' : ($request['search_' . $name] ?? '');
		$filters[$name] = is_scalar($value) ? trim((string) $value) : '';
		if (strlen($filters[$name]) > 256) { throw new InvalidArgumentException('KreaBankWorklistInvalidFilter'); }
	}
	foreach (array('date_from','date_to') as $name) {
		$v = $filters[$name];
		if ($v !== '' && (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $v, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1]))) {
			throw new InvalidArgumentException('KreaBankWorklistInvalidFilter');
		}
	}
	if ($filters['date_from'] !== '' && $filters['date_to'] !== '' && $filters['date_from'] > $filters['date_to']) {
		throw new InvalidArgumentException('KreaBankWorklistInvalidFilter');
	}
	$filters['account'] = ctype_digit($filters['account']) ? (string) max(0, (int) $filters['account']) : '';
	if ($filters['account'] === '0') { $filters['account'] = ''; }
	if ($filters['payment_account'] !== 'none') {
		$filters['payment_account'] = ctype_digit($filters['payment_account']) ? (string) max(0, (int) $filters['payment_account']) : '';
		if ($filters['payment_account'] === '0') { $filters['payment_account'] = ''; }
	}
	$filters['status'] = in_array($filters['status'], array('pending','draft','reconciled','review','all'), true) ? $filters['status'] : 'pending';
	if ($filters['amount'] !== '' && !preg_match('/^(?:>=|<=|<>|>|<|=)?\s*[+-]?\d+(?:[.,]\d{1,8})?$/D', $filters['amount'])) {
		throw new InvalidArgumentException('KreaBankWorklistInvalidFilter');
	}
	$sort = is_scalar($request['sortfield'] ?? '') ? (string) ($request['sortfield'] ?? '') : '';
	$sort = in_array($sort, $columns, true) ? $sort : 'operation_date';
	$order = strtoupper(is_scalar($request['sortorder'] ?? '') ? (string) ($request['sortorder'] ?? '') : '');
	$order = in_array($order, array('ASC','DESC'), true) ? $order : ($sort === 'operation_date' ? 'DESC' : 'ASC');
	$limit = (int) ($request['limit'] ?? 0);
	if ($limit <= 0) { $limit = max(1,(int) $defaultLimit); }
	$limit = min(100,$limit);
	$page = isset($request['pageplusone']) ? (int) $request['pageplusone'] - 1 : (int) ($request['page'] ?? 0);
	$page = max(0, $page);
	$jump = isset($request['pageplusone'], $request['pageplusoneold']) && (int) $request['pageplusone'] !== (int) $request['pageplusoneold'];
	foreach (array('button_search','button_search_x','button_search.x','button_removefilter','button_removefilter_x','button_removefilter.x') as $button) {
		if (array_key_exists($button, $request)) {
			if ($clear || !$jump) { $page = 0; }
			if (strpos($button, 'removefilter') !== false) {
				$filters = array_fill_keys(array_keys($filters), '');
				$filters['status'] = 'pending';
			}
		}
	}
	$params = array('mainmenu'=>'bank','leftmenu'=>'kreabank_worklist','sortfield'=>$sort,'sortorder'=>$order,'limit'=>$limit);
	foreach ($filters as $name=>$value) { if ($value !== '') { $params['search_' . $name] = $value; } }
	return array('filters'=>$filters,'sort'=>array('field'=>$sort,'order'=>$order,'prefix'=>''),'page'=>$page,'limit'=>$limit,'params'=>$params);
}

/** Compare arbitrary signed eight-place database decimals without floating point. */
function kreabankWorklistCompareDecimal($left, $right)
{
	$normalize = static function ($value) {
		$value = str_replace(',', '.', trim((string) $value));
		if (!preg_match('/^([+-]?)(\d+)(?:\.(\d{1,8}))?$/D', $value, $m)) { throw new InvalidArgumentException('Invalid decimal'); }
		$digits = ltrim($m[2] . str_pad($m[3] ?? '', 8, '0'), '0') ?: '0';
		return array($digits !== '0' && $m[1] === '-', $digits);
	};
	list($negativeA,$a) = $normalize($left); list($negativeB,$b) = $normalize($right);
	if ($negativeA !== $negativeB) { return $negativeA ? -1 : 1; }
	$compare = strlen($a) === strlen($b) ? strcmp($a, $b) : strlen($a) <=> strlen($b);
	return ($negativeA ? -1 : 1) * ($compare <=> 0);
}

function kreabankWorklistAmountMatches($amount, $filter)
{
	if ($filter === '') { return true; }
	if (!preg_match('/^(>=|<=|<>|>|<|=)?\s*([+-]?\d+(?:[.,]\d{1,8})?)$/D', $filter, $m)) { return false; }
	$comparison = kreabankWorklistCompareDecimal($amount, $m[2]);
	switch ($m[1] ?: '=') {
		case '>=': return $comparison >= 0;
		case '<=': return $comparison <= 0;
		case '>': return $comparison > 0;
		case '<': return $comparison < 0;
		case '<>': return $comparison !== 0;
		default: return $comparison === 0;
	}
}

/** Filter the complete prepared population, never a visible page. */
function kreabankWorklistFilterRows($rows, $state)
{
	$f = $state['filters'];
	$contains = static function ($haystack, $needle) {
		return $needle === '' || (function_exists('mb_stripos') ? mb_stripos((string) $haystack, $needle, 0, 'UTF-8') !== false : stripos((string) $haystack, $needle) !== false);
	};
	$rows = array_values(array_filter($rows, static function ($row) use ($f, $contains) {
		$l = $row['line'];
		$date = substr((string) ($l['operation_date'] ?? ''), 0, 10);
		$status = $row['status'] ?? ((int) ($l['status'] ?? 0) === 2 ? 'reconciled' : 'pending');
		if (($f['account'] !== '' && (int) $l['bank_account_id'] !== (int) $f['account'])
			|| ($f['date_from'] !== '' && $date < $f['date_from']) || ($f['date_to'] !== '' && $date > $f['date_to'])
			|| !$contains(($l['description'] ?? '') . ' ' . ($l['payment_reference'] ?? '') . ' ' . ($l['bank_reference'] ?? ''), $f['description'])
			|| !kreabankWorklistAmountMatches($l['amount'], $f['amount']) || !$contains($row['match'] ?? '', $f['match'])
			|| ($f['status'] === 'pending' && !in_array($status, array('pending','draft'), true))
			|| ($f['status'] !== 'pending' && $f['status'] !== 'all' && $f['status'] !== $status)) { return false; }
		$accounts = $row['payment_accounts'] ?? array();
		if ($f['payment_account'] === 'none') { return !$accounts; }
		return $f['payment_account'] === '' || in_array((int) $f['payment_account'], array_map('intval', $accounts), true);
	}));
	$sort = $state['sort'];
	usort($rows, static function ($a, $b) use ($sort) {
		$field = $sort['field'];
		if ($field === 'amount') { $compare = kreabankWorklistCompareDecimal($a['line']['amount'], $b['line']['amount']); }
		else {
			$va = $a['line'][$field] ?? $a[$field] ?? ''; $vb = $b['line'][$field] ?? $b[$field] ?? '';
			$compare = strnatcasecmp((string) $va, (string) $vb);
		}
		return ($compare ? ($sort['order'] === 'DESC' ? -$compare : $compare) : strcmp($a['key'], $b['key']));
	});
	return $rows;
}

/** Native page/limit semantics applied only after complete filtering and sorting. */
function kreabankWorklistPage($rows, $state)
{
	$total = count($rows); $page = $state['page']; $limit = $state['limit'];
	if ($page * $limit >= $total) { $page = 0; }
	return array('rows'=>array_slice($rows, $page * $limit, $limit),'total'=>$total,'page'=>$page,'limit'=>$limit,'num'=>min($limit + 1, max(0, $total - $page * $limit)));
}

/** Only an explicit More request can grant matching outside the displayed page. */
function kreabankWorklistScanTargets($sourceKeys, $displayKeys, $summaries, $limit, $more = false)
{
	$owned = array_fill_keys($sourceKeys, true);
	$keys = $more ? array_values(array_filter($sourceKeys, static function ($key) use ($summaries) { return !isset($summaries[$key]); })) : ($displayKeys ?: $sourceKeys);
	return array_slice(array_values(array_unique(array_filter($keys, static function ($key) use ($owned) { return isset($owned[$key]); }))), 0, max(1, min(100, (int) $limit)));
}

/** A bounded read worker never widens its server-granted target list. */
function kreabankWorklistAdvanceScan(&$scan, $prepare)
{
	$started = microtime(true); $processed = 0;
	foreach ($scan['targets'] as $key) {
		if (isset($scan['summaries'][$key])) { continue; }
		if ($processed > 0 && (microtime(true) - $started >= 1.2 || $processed >= 8)) { break; }
		$scan['summaries'][$key] = $prepare($key); $processed++;
	}
}

/** One current completed batch review grants at most one further batch. */
function kreabankWorklistMoreScan(&$scan, $sourceKeys, $limit, $generation, $cursor)
{
	if ($generation !== $scan['generation'] || (int) $cursor !== $scan['cursor']) { return false; }
	if (($scan['lease']['expires'] ?? 0) > time()) { return false; }
	foreach ($scan['targets'] as $key) { if (!isset($scan['summaries'][$key])) { return false; } }
	$scan['targets'] = kreabankWorklistScanTargets($sourceKeys, array(), $scan['summaries'], $limit, true);
	$scan['generation'] = bin2hex(random_bytes(16)); $scan['cursor'] = 0;
	return true;
}

/** Reject stale requests before they can replace another page's scan state. */
function kreabankWorklistScanAdmitted($scan, $scope, $generation, $cursor, $view = null)
{
	return ($scan['scope'] ?? '') === $scope && ($scan['expires'] ?? 0) >= time()
		&& isset($scan['targets']) && ($scan['generation'] ?? '') === $generation
		&& (int) ($scan['cursor'] ?? -1) === (int) $cursor && ($view === null || ($scan['view'] ?? '') === $view);
}

/** Reserve read work before releasing the PHP session lock. */
function kreabankWorklistClaimScan(&$scan)
{
	if (($scan['lease']['expires'] ?? 0) > time()) { return false; }
	$scan['lease'] = array('id'=>bin2hex(random_bytes(16)), 'expires'=>time()+90);
	return true;
}

/** A bank reference alone cannot identify which native payment the modal moves. */
function kreabankWorklistCorrectionMatches($accountCandidates, $movement)
{
	if (count($accountCandidates) !== 1) { return false; }
	$candidate = reset($accountCandidates);
	return (int) ($candidate['bank_line_id'] ?? 0) > 0
		&& (int) $candidate['bank_line_id'] === (int) $movement['rowid']
		&& (int) $candidate['bank_account_id'] === (int) $movement['fk_account'];
}

/** Source namespaces are mandatory, even when their numeric IDs happen to match. */
function kreabankWorklistSource($key)
{
	if (!is_string($key) || !preg_match('/^(staged|native):([1-9][0-9]{0,9})$/D', $key, $m)) { throw new InvalidArgumentException('KreaBankWorklistChanged'); }
	return array($m[1], (int) $m[2]);
}
