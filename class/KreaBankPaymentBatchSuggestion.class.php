<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
require_once __DIR__ . '/KreaBankPaymentBatchTrait.php';

/** Bounded exact-sum advice for explicit batch review; performs no reads or writes. */
final class KreaBankPaymentBatchSuggestion
{
	const MAX_CANDIDATES = 100;
	const MAX_STATES = 10000;
	const MAX_TRANSITIONS = 200000;

	/** Only a completely searched unique subset of at least two native IDs is returned. */
	public static function suggest(array $source, array $rows, $truncated = false)
	{
		$result = array('status'=>'none', 'ids'=>array(), 'candidate_count'=>count($rows));
		if ($truncated) { $result['status'] = 'truncated'; return $result; }
		if (count($rows) > self::MAX_CANDIDATES) { $result['status'] = 'bounded'; return $result; }
		$day = self::bookingDay($source['operation_date'] ?? '');
		if ($day === '' || $day !== ($source['operation_date'] ?? '') || (int) ($source['entity'] ?? 0) <= 0
			|| (int) ($source['bank_account_id'] ?? 0) <= 0 || (int) ($source['status'] ?? -1) !== 0
			|| !empty($source['is_duplicate']) || !empty($source['fk_native_bank_line'])
			|| !preg_match('/^[A-Z]{3}$/D', $source['currency'] ?? '')) {
			$result['status'] = 'invalid'; return $result;
		}
		try {
			$signedTarget = KreaBankPaymentBatchDecimal::normalize($source['amount'] ?? '');
			if (KreaBankPaymentBatchDecimal::normalize($source['allocated_amount'] ?? '0') !== '0.00000000') {
				$result['status'] = 'invalid'; return $result;
			}
		}
		catch (DomainException $e) { $result['status'] = 'invalid'; return $result; }
		if ($signedTarget === '0.00000000') { $result['status'] = 'invalid'; return $result; }
		$target = KreaBankPaymentBatchDecimal::absolute($signedTarget);
		$candidates = array(); $seen = array();
		foreach ($rows as $row) {
			if (!is_array($row)) { $result['status'] = 'invalid'; return $result; }
			$id = $row['rowid'] ?? null;
			if ((!is_int($id) && !is_string($id)) || !preg_match('/^[1-9][0-9]{0,9}$/D', (string) $id)
				|| isset($seen[(int) $id]) || (int) ($row['entity'] ?? 0) !== (int) $source['entity']
				|| (int) ($row['bank_account_id'] ?? 0) !== (int) $source['bank_account_id']
				|| ($row['currency'] ?? '') !== $source['currency'] || self::bookingDay($row['doc_date'] ?? '') !== $day) {
				$result['status'] = 'invalid'; return $result;
			}
			try { $amount = KreaBankPaymentBatchDecimal::normalize($row['amount_decimal'] ?? ''); }
			catch (DomainException $e) { $result['status'] = 'invalid'; return $result; }
			if ($amount === '0.00000000' || ($amount[0] === '-') !== ($signedTarget[0] === '-')) {
				$result['status'] = 'invalid'; return $result;
			}
			$seen[(int) $id] = true;
			$absolute = KreaBankPaymentBatchDecimal::absolute($amount);
			// Positive components equal to or above the target cannot form a two-member subset.
			if (self::comparePositive($absolute, $target) >= 0) { continue; }
			$candidates[] = array('id'=>(int) $id, 'amount'=>$absolute);
		}
		usort($candidates, static function ($a, $b) { return $a['id'] <=> $b['id']; });
		// Complements are a bijection: a near-total batch can be proved by its small
		// excluded remainder without discarding any competing exact subset.
		$remainder = KreaBankPaymentBatchDecimal::sum(array_merge(array_column($candidates, 'amount'), array('-' . $target)));
		if ($remainder[0] === '-') { return $result; }
		if ($remainder === '0.00000000') {
			if (count($candidates) >= 2) { $result['status'] = 'unique'; $result['ids'] = array_column($candidates, 'id'); }
			return $result;
		}
		$complement = self::comparePositive($remainder, $target) < 0;
		if ($complement) { $target = $remainder; }
		$states = array('0.00000000'=>array('ways'=>1, 'path'=>array()));
		$transitions = 0;
		foreach ($candidates as $candidate) {
			$previous = $states;
			foreach ($previous as $sum=>$state) {
				if (++$transitions > self::MAX_TRANSITIONS) { $result['status'] = 'bounded'; return $result; }
				$next = KreaBankPaymentBatchDecimal::sum(array($sum, $candidate['amount']));
				if (self::comparePositive($next, $target) > 0) { continue; }
				if (!isset($states[$next])) {
					if (count($states) >= self::MAX_STATES) { $result['status'] = 'bounded'; return $result; }
					$path = $state['path'];
					if ($state['ways'] === 1) { $path[] = $candidate['id']; }
					$states[$next] = array('ways'=>$state['ways'], 'path'=>$path);
				} else {
					$states[$next]['ways'] = min(2, $states[$next]['ways'] + $state['ways']);
					$states[$next]['path'] = array();
				}
				if ($next === $target && $states[$next]['ways'] > 1) {
					$result['status'] = 'ambiguous'; return $result;
				}
			}
		}
		if (isset($states[$target]) && $states[$target]['ways'] === 1) {
			$ids = $complement ? array_values(array_diff(array_column($candidates, 'id'), $states[$target]['path'])) : $states[$target]['path'];
			if (count($ids) >= 2) { $result['status'] = 'unique'; $result['ids'] = $ids; }
		}
		return $result;
	}

	private static function comparePositive($a, $b)
	{
		$a = ltrim(str_replace('.', '', $a), '0') ?: '0';
		$b = ltrim(str_replace('.', '', $b), '0') ?: '0';
		return strlen($a) === strlen($b) ? (strcmp($a, $b) <=> 0) : (strlen($a) <=> strlen($b));
	}

	private static function bookingDay($value)
	{
		if (!is_string($value) || !preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})(?: (?:[01][0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9])?$/D', $value, $parts)
			|| !checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) { return ''; }
		return $parts[1] . '-' . $parts[2] . '-' . $parts[3];
	}
}
