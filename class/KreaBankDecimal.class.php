<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/** Exact decimal arithmetic for advisory rules and explicitly reviewed differences. */
final class KreaBankDecimal
{
	public static function normalize($value)
	{
		if ((!is_string($value) && !is_int($value)) || !preg_match('/^[+-]?\d{1,96}(?:\.\d{1,96})?$/D', (string) $value)) {
			throw new InvalidArgumentException('A plain decimal string is required');
		}
		$negative = substr((string) $value, 0, 1) === '-';
		$parts = explode('.', ltrim((string) $value, '+-'), 2);
		$whole = ltrim($parts[0], '0');
		$fraction = rtrim($parts[1] ?? '', '0');
		$out = ($whole === '' ? '0' : $whole) . ($fraction !== '' ? '.' . $fraction : '');
		return $negative && $out !== '0' ? '-' . $out : $out;
	}

	public static function abs($value) { return ltrim(self::normalize($value), '-'); }
	public static function negate($value) { $value = self::normalize($value); return $value === '0' ? '0' : ($value[0] === '-' ? substr($value, 1) : '-' . $value); }

	public static function compare($a, $b)
	{
		$a = self::normalize($a); $b = self::normalize($b);
		$na = $a[0] === '-'; $nb = $b[0] === '-';
		if ($na !== $nb) { return $na ? -1 : 1; }
		list($da, $db) = self::aligned($a, $b);
		return self::unsignedCompare($da, $db) * ($na ? -1 : 1);
	}

	public static function add($a, $b)
	{
		$a = self::normalize($a); $b = self::normalize($b);
		$na = $a[0] === '-'; $nb = $b[0] === '-';
		list($da, $db, $scale) = self::aligned($a, $b);
		if ($na === $nb) { return self::decimal(self::unsignedAdd($da, $db), $scale, $na); }
		$cmp = self::unsignedCompare($da, $db);
		if ($cmp === 0) { return '0'; }
		return $cmp > 0 ? self::decimal(self::unsignedSubtract($da, $db), $scale, $na)
			: self::decimal(self::unsignedSubtract($db, $da), $scale, $nb);
	}

	public static function subtract($a, $b) { return self::add($a, self::negate($b)); }

	public static function multiply($a, $b)
	{
		$a = self::normalize($a); $b = self::normalize($b);
		$scale = self::scale($a) + self::scale($b);
		$da = str_replace('.', '', ltrim($a, '-')); $db = str_replace('.', '', ltrim($b, '-'));
		$result = '0';
		for ($j = strlen($db) - 1, $zeros = ''; $j >= 0; $j--, $zeros .= '0') {
			$carry = 0; $row = '';
			for ($i = strlen($da) - 1; $i >= 0; $i--) {
				$n = (int) $da[$i] * (int) $db[$j] + $carry;
				$row = ($n % 10) . $row; $carry = intdiv($n, 10);
			}
			if ($carry) { $row = $carry . $row; }
			$result = self::unsignedAdd($result, $row . $zeros);
		}
		return self::decimal($result, $scale, ($a[0] === '-') !== ($b[0] === '-'));
	}

	private static function scale($value) { $dot = strpos($value, '.'); return $dot === false ? 0 : strlen($value) - $dot - 1; }
	private static function aligned($a, $b)
	{
		$scale = max(self::scale($a), self::scale($b));
		return array(str_replace('.', '', ltrim($a, '-')) . str_repeat('0', $scale - self::scale($a)),
			str_replace('.', '', ltrim($b, '-')) . str_repeat('0', $scale - self::scale($b)), $scale);
	}
	private static function unsignedCompare($a, $b)
	{
		$a = ltrim($a, '0'); $b = ltrim($b, '0');
		return strlen($a) === strlen($b) ? (strcmp($a, $b) <=> 0) : (strlen($a) <=> strlen($b));
	}
	private static function unsignedAdd($a, $b)
	{
		$out = ''; $carry = 0;
		for ($i = strlen($a) - 1, $j = strlen($b) - 1; $i >= 0 || $j >= 0 || $carry; $i--, $j--) {
			$n = ($i >= 0 ? (int) $a[$i] : 0) + ($j >= 0 ? (int) $b[$j] : 0) + $carry;
			$out = ($n % 10) . $out; $carry = intdiv($n, 10);
		}
		return $out === '' ? '0' : $out;
	}
	private static function unsignedSubtract($a, $b)
	{
		$out = ''; $borrow = 0;
		for ($i = strlen($a) - 1, $j = strlen($b) - 1; $i >= 0; $i--, $j--) {
			$n = (int) $a[$i] - ($j >= 0 ? (int) $b[$j] : 0) - $borrow;
			$borrow = $n < 0 ? 1 : 0; if ($n < 0) { $n += 10; } $out = $n . $out;
		}
		return ltrim($out, '0') ?: '0';
	}
	private static function decimal($digits, $scale, $negative)
	{
		$digits = str_pad($digits, $scale + 1, '0', STR_PAD_LEFT);
		$out = $scale ? substr($digits, 0, -$scale) . '.' . substr($digits, -$scale) : $digits;
		$out = ltrim($out, '0'); if ($out === '' || $out[0] === '.') { $out = '0' . $out; }
		if ($scale) { $out = rtrim(rtrim($out, '0'), '.'); }
		return $negative && $out !== '0' ? '-' . $out : $out;
	}
}
