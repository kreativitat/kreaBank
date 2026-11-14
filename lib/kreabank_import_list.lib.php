<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
require_once __DIR__ . '/kreabank_statement_list.lib.php';

/** Header filters for imported statements and their original, read-only lines. */
function kreabankImportListState($request, $defaultLimit, $detail = false)
{
	$keys = $detail ? array('operation_from','operation_to','description','amount','status','native_id')
		: array('statement_ref','bank_account_ref','imported_from','imported_to','imported_lines','native_lines','reconciled_lines');
	$state = kreabankStatementListState($request, $defaultLimit);
	$clear = isset($request['button_removefilter']) || isset($request['button_removefilter_x']) || isset($request['button_removefilter.x']);
	$state['filters'] = array();
	foreach ($keys as $key) {
		$value = $request['search_' . $key] ?? '';
		$state['filters'][$key] = !$clear && is_scalar($value) ? trim((string) $value) : '';
	}
	return $state;
}

/** Identical outer predicates for count and rows; never change aggregation inputs. */
function kreabankImportListWhere($db, $filters, $detail = false, $reference = '', $sourceType = '')
{
	$where = ' WHERE 1=1';
	$dateColumn = $detail ? 'operation_date' : 'imported_at';
	$prefix = $detail ? 'operation' : 'imported';
	foreach (array('from'=>'>=', 'to'=>'<') as $bound=>$operator) {
		$value = $filters[$prefix . '_' . $bound] ?? '';
		if ($value === '') { continue; }
		if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $parts) || !checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) { $where .= ' AND 1=0'; continue; }
		$literal = "'" . $db->escape($value) . "'";
		$where .= ' AND h.' . $dateColumn . $operator . ($bound === 'to' ? 'DATE_ADD(' . $literal . ', INTERVAL 1 DAY)' : $literal);
	}
	foreach ($detail ? array('description') : array('statement_ref','bank_account_ref') as $field) {
		$value = $filters[$field] ?? '';
		if ($value === '') { continue; }
		$literal = $db->escape(strtr($value, array('!'=>'!!','%'=>'!%','_'=>'!_')));
		$like = static function ($column) use ($literal) { return "$column LIKE '%$literal%' ESCAPE '!'"; };
		$where .= ' AND (' . $like('h.' . $field)
			. ($detail ? ' OR ' . $like('h.payment_reference') . ($sourceType !== 'fintable' ? ' OR ' . $like('h.bank_reference') : '') : '') . ')';
	}
	foreach ($detail ? array('amount','native_id') : array('imported_lines','native_lines','reconciled_lines') as $field) {
		$value = $filters[$field] ?? '';
		if ($value === '') { continue; }
		$pattern = $field === 'amount' ? '/^(<=|>=|<>|!=|=|<|>)?\s*([+-]?\d{1,16}(?:[.,]\d{1,8})?)$/D' : '/^(<=|>=|<>|!=|=|<|>)?\s*(\d{1,16})$/D';
		if (!preg_match($pattern, $value, $parts)) { $where .= ' AND 1=0'; continue; }
		$where .= ' AND h.' . $field . ' ' . ($parts[1] ?: '=') . ' ' . str_replace(',', '.', $parts[2]);
	}
	$status = $detail ? ($filters['status'] ?? '') : '';
	if ($status === 'duplicate') { $where .= ' AND h.is_duplicate>0'; }
	elseif ($status !== '') {
		if (!isset(kreabankStatementListStatusOptions()[$status])) { return $where . ' AND 1=0'; }
		$state = kreabankStatementListStatusSql("'" . $db->escape($reference) . "'");
		$where .= ' AND (' . $state . ")='" . $status . "'";
	}
	return $where;
}
