<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/** Original file order and balances are evidence, independent of feed/import order. */
class KreaBankStatementEvidence
{
	public static function description($value)
	{
		$value = function_exists('dol_string_unaccent') ? dol_string_unaccent($value) : iconv('UTF-8', 'ASCII//TRANSLIT', $value);
		if ($value === false) { throw new InvalidArgumentException('Invalid statement description encoding'); }
		return strtolower(trim(preg_replace('/\s+/', ' ', $value)));
	}
	public static function decimal($value)
	{
		if (!is_string($value) || !preg_match('/^(-?)([0-9]{1,16})(?:\.([0-9]{1,8}))?$/D', $value, $m)) {
			throw new InvalidArgumentException('Statement evidence requires exact decimal strings');
		}
		$number = (ltrim($m[2], '0') ?: '0') . '.' . str_pad($m[3] ?? '', 8, '0');
		return ($number === '0.00000000' ? '' : $m[1]) . $number;
	}

	private static function date($value)
	{
		if (!is_string($value) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $m)
			|| !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
			throw new InvalidArgumentException('Invalid statement evidence date');
		}
		return $value;
	}

	/** Input is explicitly oldest-first or newest-first; never invent transaction times. */
	public static function normalize($evidence)
	{
		if (!is_array($evidence) || ($evidence['version'] ?? null) !== 1
			|| !preg_match('/^[a-f0-9]{64}$/D', $evidence['sha256'] ?? '')
			|| !is_string($evidence['filename'] ?? null) || trim($evidence['filename']) === ''
			|| strlen($evidence['filename']) > 255 || !in_array($evidence['order'] ?? '', array('ASC', 'DESC'), true)
			|| empty($evidence['lines']) || !is_array($evidence['lines'])) {
			throw new InvalidArgumentException('Invalid original statement evidence');
		}
		$lines = array_values($evidence['lines']);
		if ($evidence['order'] === 'DESC') { $lines = array_reverse($lines); }
		$out = array(); $lastDate = ''; $currency = null;
		foreach ($lines as $line) {
			$id = filter_var($line['staged_id'] ?? null, FILTER_VALIDATE_INT, array('options'=>array('min_range'=>1)));
			if (!$id || isset($out[$id]) || !is_string($line['description'] ?? null)
				|| !preg_match('/^[A-Z]{3}$/D', $line['currency'] ?? '')
				|| !is_string($line['line_uid'] ?? '') || strlen($line['line_uid'] ?? '') > 128) {
				throw new InvalidArgumentException('Invalid or repeated statement evidence row');
			}
			$date = self::date($line['operation_date'] ?? null);
			if ($date < $lastDate || ($currency !== null && $currency !== $line['currency'])) {
				throw new InvalidArgumentException('Statement evidence has conflicting dates or currencies');
			}
			$lastDate = $date; $currency = $line['currency'];
			$out[$id] = array('staged_id'=>(int) $id, 'sequence'=>count($out) + 1,
				'line_uid'=>$line['line_uid'] ?? '', 'operation_date'=>$date,
				'value_date'=>self::date($line['value_date'] ?? null), 'currency'=>$currency,
				'amount'=>self::decimal($line['amount'] ?? null),
				'running_balance'=>self::decimal($line['running_balance'] ?? null),
				'description'=>$line['description'], 'expected_description'=>(string) ($line['expected_description'] ?? ''),
				'source_row'=>(int) ($line['source_row'] ?? 0));
		}
		return array('version'=>1, 'sha256'=>$evidence['sha256'], 'filename'=>$evidence['filename'],
			'order'=>'ASC', 'lines'=>array_values($out));
	}

	/** No DDL or writes. All metadata and target rows must belong to the owner company. */
	public static function projectionSql($db, $entity, $accountId = 0)
	{
		$p = $db->prefix(); $entity = (int) $entity;
		$scope = (int) $accountId > 0 ? ' AND s.bank_account_id=' . (int) $accountId : '';
		$result = $db->query("SELECT s.rowid,s.ref,s.bank_account_id,s.import_payload FROM {$p}kreabank_statement s"
			. " JOIN {$p}bank_account a ON a.rowid=s.bank_account_id AND a.entity=s.entity"
			. " WHERE s.entity=$entity$scope AND s.import_payload IS NOT NULL ORDER BY s.rowid");
		if (!$result) { throw new RuntimeException('Cannot read original statement evidence'); }
		$evidence = array();
		while ($row = $db->fetch_object($result)) {
			$payload = json_decode($row->import_payload, true);
			if (!is_array($payload) || !isset($payload['bank_statement_evidence'])) { continue; }
			$proof = self::normalize($payload['bank_statement_evidence']);
			foreach ($proof['lines'] as $line) {
				$line['bank_account_id'] = (int) $row->bank_account_id;
				$line['statement_id'] = (int) $row->rowid; $line['statement_ref'] = $row->ref;
				$evidence[$row->bank_account_id . ':' . $line['staged_id']] = $line;
			}
		}
		$quote = static function ($s) use ($db) { return "'" . $db->escape($s) . "'"; };
		$rows = array();
		foreach ($evidence as $line) {
			$rows[] = 'SELECT ' . $line['staged_id'] . ' staged_id,' . $line['bank_account_id'] . ' bank_account_id,'
				. $line['sequence'] . ' statement_sequence,' . $line['statement_id'] . ' evidence_statement_id,'
				. $quote($line['statement_ref']) . ' evidence_statement_ref,' . $quote($line['line_uid']) . ' line_uid,'
				. 'CAST(' . $quote($line['operation_date']) . ' AS DATE) operation_date,CAST(' . $quote($line['value_date']) . ' AS DATE) value_date,'
				. 'CAST(' . $quote($line['amount']) . ' AS DECIMAL(24,8)) amount,CAST(' . $quote($line['running_balance']) . ' AS DECIMAL(24,8)) running_balance,'
				. $quote($line['currency']) . ' currency,' . $quote($line['description']) . ' description,' . $quote($line['expected_description']) . ' expected_description';
		}
		return $rows ? implode(' UNION ALL ', $rows) : 'SELECT NULL staged_id,NULL bank_account_id,NULL statement_sequence,'
			. 'NULL evidence_statement_id,NULL evidence_statement_ref,NULL line_uid,NULL operation_date,NULL value_date,NULL amount,NULL running_balance,NULL currency,NULL description,NULL expected_description WHERE 1=0';
	}
}
