<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

function kreabankStatementListStatusOptions()
{
	return array('pending'=>'KreaBankPending', 'registered'=>'KreaBankNativeRegistered',
		'reconciled'=>'KreaBankReconciled', 'review'=>'KreaBankLinkNeedsReview',
		'skipped'=>'KreaBankSkipped', 'duplicate'=>'KreaBankDuplicate');
}

/** Reverse the original within-day sequence with the chosen date direction. */
function kreabankStatementListOrder($order, $expression)
{
	$order = strtoupper($order) === 'ASC' ? 'ASC' : 'DESC';
	return $expression . ' IS NULL ASC,' . $expression . ' ' . $order
		. ',h.operation_date ' . $order . ',h.bank_account_id,h.statement_sequence ' . $order
		. ',h.statement_id ' . $order . ',h.line_rank ' . $order . ',h.rowid ' . $order . ',h.source_storage';
}

/** Normalize only read-only list state; native search/reset buttons start at page one. */
function kreabankStatementListState($request, $defaultLimit)
{
	$filters = array();
	foreach (array('operation_from','operation_to','value_from','value_to','description','debit','credit','running_balance','currency','bank_account_ref','statement_ref','status') as $field) {
		$value = $request['search_' . $field] ?? '';
		$filters[$field] = is_scalar($value) ? trim((string) $value) : '';
	}
	$has = static function ($keys) use ($request) {
		foreach ($keys as $key) { if (isset($request[$key])) { return true; } }
		return false;
	};
	$clear = $has(array('button_removefilter','button_removefilter_x','button_removefilter.x'));
	if ($clear) { $filters = array_fill_keys(array_keys($filters), ''); }
	$page = isset($request['pageplusone']) ? (int) $request['pageplusone'] - 1 : (int) ($request['page'] ?? 0);
	$jump = isset($request['pageplusone'], $request['pageplusoneold']) && (int) $request['pageplusone'] !== (int) $request['pageplusoneold'];
	if ($clear || (!$jump && $has(array('button_search','button_search_x','button_search.x')))) { $page = 0; }
	$limit = (int) ($request['limit'] ?? 0);
	if ($limit <= 0) { $limit = max(1, (int) $defaultLimit); }
	return array('filters'=>$filters, 'page'=>max(0, $page), 'limit'=>min(5000, $limit));
}

/** Filter the complete, already owner-scoped balance projection, never its inputs. */
function kreabankStatementListWhere($db, $filters)
{
	$where = ' WHERE 1=1';
	foreach (array('operation_from'=>array('operation_date','>='), 'operation_to'=>array('operation_date','<'),
		'value_from'=>array('value_date','>='), 'value_to'=>array('value_date','<')) as $key=>$date) {
		$value = $filters[$key] ?? '';
		if ($value === '') { continue; }
		if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $parts) || !checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
			$where .= ' AND 1=0';
			continue;
		}
		$literal = "'" . $db->escape($value) . "'";
		$where .= ' AND h.' . $date[0] . $date[1] . ($date[1] === '<' ? 'DATE_ADD(' . $literal . ', INTERVAL 1 DAY)' : $literal);
	}
	foreach (array('description','currency','bank_account_ref','statement_ref') as $field) {
		$value = $filters[$field] ?? '';
		if ($value === '') { continue; }
		$literal = $db->escape(strtr($value, array('!'=>'!!','%'=>'!%','_'=>'!_')));
		$like = static function ($column) use ($literal) { return "$column LIKE '%$literal%' ESCAPE '!'"; };
		if ($field === 'description') {
			$where .= ' AND (' . $like('h.description') . ' OR ' . $like('h.payment_reference')
				. " OR (COALESCE(h.source_type,'')<>'fintable' AND " . $like('h.bank_reference') . '))';
		} else { $where .= ' AND ' . $like('h.' . $field); }
	}
	foreach (array('debit'=>'CASE WHEN h.amount<0 THEN -h.amount ELSE NULL END',
		'credit'=>'CASE WHEN h.amount>=0 THEN h.amount ELSE NULL END', 'running_balance'=>'h.display_balance') as $field=>$expression) {
		$value = $filters[$field] ?? '';
		if ($value === '') { continue; }
		// Preserve DECIMAL precision; accept exact values or one comparison, including comma decimals.
		if (!preg_match('/^(<=|>=|<>|!=|=|<|>)?\s*([+-]?\d{1,16}(?:[.,]\d{1,8})?)$/D', $value, $parts)) {
			$where .= ' AND 1=0';
			continue;
		}
		$where .= ' AND (' . $expression . ') ' . ($parts[1] ?: '=') . ' ' . str_replace(',', '.', $parts[2]);
	}
	$status = $filters['status'] ?? '';
	if ($status === 'duplicate') { $where .= ' AND h.is_duplicate>0'; }
	elseif ($status !== '') {
		if (!isset(kreabankStatementListStatusOptions()[$status])) { return $where . ' AND 1=0'; }
		// Mirror kreabankIntegrationLineState(), including missing/changed native evidence.
		$state = "CASE WHEN h.status=2 OR h.expected_native_id>0 THEN CASE"
			. " WHEN h.native_id IS NULL OR h.native_id=0 OR COALESCE(h.currency,'')<>COALESCE(h.native_currency,'')"
			. " OR ABS(h.amount-h.native_amount)>0.01 OR (h.status=2 AND (COALESCE(h.rappro,0)<>1 OR COALESCE(h.num_releve,'')<>COALESCE(h.statement_ref,''))) THEN 'review'"
			. " WHEN h.rappro=1 THEN 'reconciled' ELSE 'registered' END WHEN h.status=3 THEN 'skipped' ELSE 'pending' END";
		$where .= ' AND (' . $state . ")='" . $status . "'";
	}
	return $where;
}
