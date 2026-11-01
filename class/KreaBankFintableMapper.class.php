<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
require_once __DIR__ . '/KreaBankFintableClient.class.php';

/** Strict source validation independent of database and locale. */
class KreaBankFintableMapper
{
	public static function iban($value)
	{
		$value = strtoupper(preg_replace('/\s+/', '', (string) $value));
		if (!preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}$/D', $value)) {
			return '';
		}
		$reordered = substr($value, 4) . substr($value, 0, 4);
		$remainder = 0;
		foreach (str_split($reordered) as $character) {
			$digits = ctype_alpha($character) ? (string) (ord($character) - 55) : $character;
			foreach (str_split($digits) as $digit) {
				$remainder = ($remainder * 10 + (int) $digit) % 97;
			}
		}
		return $remainder === 1 ? $value : '';
	}

	public static function decimal($value, $nullable = false)
	{
		if ($nullable && $value === null) {
			return null;
		}
		if (!is_string($value) || !preg_match('/^-?[0-9]{1,16}(?:\.[0-9]{1,8})?$/D', $value)) {
			throw new RuntimeException('Invalid Fintable decimal amount');
		}
		$negative = substr($value, 0, 1) === '-';
		$pieces = explode('.', ltrim($value, '-'));
		$integer = ltrim($pieces[0], '0');
		$integer = $integer === '' ? '0' : $integer;
		$fraction = isset($pieces[1]) ? rtrim($pieces[1], '0') : '';
		$result = $integer . ($fraction !== '' ? '.' . $fraction : '');
		return ($negative && $result !== '0' ? '-' : '') . $result;
	}

	public static function date($value)
	{
		if (!is_string($value) || !preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/D', $value, $m)
			|| !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
			throw new RuntimeException('Invalid Fintable booking date');
		}
		return $value;
	}

	public static function transaction($transaction, $account, $workspace)
	{
		KreaBankFintableClient::assertId($transaction['id'] ?? null, 'tx_');
		if (($transaction['account_id'] ?? null) !== $account['id']) {
			throw new RuntimeException('Fintable transaction/account mismatch');
		}
		if (!array_key_exists('pending', $transaction) || $transaction['pending'] !== false) {
			throw new RuntimeException('Unposted Fintable transaction rejected');
		}
		if (($transaction['currency'] ?? null) !== $account['currency']) {
			throw new RuntimeException('Fintable transaction currency mismatch');
		}
		$amount = self::decimal($transaction['amount'] ?? null);
		$date = self::date($transaction['date'] ?? null);
		$uid = 'fintable:' . hash('sha256', $workspace . '|' . $account['id'] . '|' . $transaction['id']);
		$external = (string) ($transaction['ext_id'] ?? '');
		$line = array(
			'line_uid' => $uid, 'operation_date' => $date, 'value_date' => $date,
			'amount' => $amount, 'currency' => $account['currency'], 'running_balance' => null,
			'description' => (string) ($transaction['description'] ?? ''),
			'counterparty_name' => (string) ($transaction['merchant'] ?? ''), 'counterparty_iban' => '',
			'payment_reference' => (string) ($transaction['check_num'] ?? ''),
			'bank_reference' => strlen($external) <= 190 ? $external : (string) $transaction['id'],
			'direction' => $amount === '0' ? 0 : (substr($amount, 0, 1) === '-' ? -1 : 1),
		);
		return array(
			'id' => $transaction['id'], 'external_id' => $external,
			'previous_external_id' => (string) ($transaction['previous_ext_id'] ?? ''),
			'uid' => $uid, 'line' => $line,
			'financial_hash' => hash('sha256', $amount . '|' . $date . '|' . $account['currency']),
			'content_hash' => hash('sha256', json_encode($line)),
		);
	}

	/** Latest due Lisbon wall-clock slot. Handles DST without drifting UTC intervals. */
	public static function slot($timestamp, $times, $timezone)
	{
		$zone = new DateTimeZone($timezone);
		$now = (new DateTimeImmutable('@' . (int) $timestamp))->setTimezone($zone);
		$chosen = null;
		foreach (explode(',', $times) as $time) {
			$time = trim($time);
			if (!preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/D', $time)) {
				throw new RuntimeException('Invalid Fintable collection schedule');
			}
			$candidate = new DateTimeImmutable($now->format('Y-m-d') . ' ' . $time, $zone);
			if ($candidate <= $now && ($chosen === null || $candidate > $chosen)) {
				$chosen = $candidate;
			}
		}
		return $chosen === null ? null : $chosen->format('Y-m-d H:i');
	}
}
