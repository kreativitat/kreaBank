#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

$rootDir = dirname(__DIR__);
require_once $rootDir.'/class/KreaBankParser.class.php';

$parser = new KreaBankParser();
$fixtures = array(
	'5700__norma43.txt' => 'norma43',
	'5703__norma43_registo24.txt' => 'norma43_r24',
	'5704__norma43_978.txt' => 'norma43_978',
	'5706__composto_nib.txt' => 'nib_compound',
	'5709__consolidado.txt' => 'consolidated',
	'5711__tabulado_folha_calculo.txt' => 'tabulated',
	'5712__tabulado_excel.csv' => 'tabulated_excel',
	'5713__bit_reconciliacao.txt' => 'bit_recon',
	'5716__emc.txt' => 'emc',
	'5717__mt940.txt' => 'mt940',
);

$expectedFirstFive = array(
	array('date' => '2026-01-20', 'amount' => -8.49),
	array('date' => '2026-01-20', 'amount' => -3.96),
	array('date' => '2026-01-20', 'amount' => -642.60),
	array('date' => '2026-01-20', 'amount' => 3300.00),
	array('date' => '2026-01-20', 'amount' => -3753.79),
);

/**
 * @param string $path
 * @return array<int,string>
 */
$loadDecodedLines = static function ($path) {
	$content = @file_get_contents($path);
	if (!is_string($content) || $content === '') {
		return array();
	}
	if (function_exists('mb_check_encoding') && function_exists('mb_convert_encoding')) {
		if (!mb_check_encoding($content, 'UTF-8')) {
			$converted = @mb_convert_encoding($content, 'UTF-8', 'ISO-8859-1');
			if (is_string($converted) && $converted !== '') {
				$content = $converted;
			}
		}
	}
	$rows = preg_split('/\r\n|\r|\n/', $content);
	if (!is_array($rows)) {
		return array();
	}
	$lines = array();
	foreach ($rows as $row) {
		$row = rtrim((string) $row);
		if ($row !== '') {
			$lines[] = $row;
		}
	}
	return $lines;
};

$errors = array();
foreach ($fixtures as $fixture => $expectedFormat) {
	$path = __DIR__.'/'.$fixture;
	if (!is_readable($path)) {
		$errors[] = 'Missing fixture: '.$fixture;
		continue;
	}

	$lines = $loadDecodedLines($path);
	if (empty($lines)) {
		$errors[] = 'No lines loaded for fixture: '.$fixture;
		continue;
	}

	$detected = $parser->detect_format($lines);
	if ((string) $detected !== (string) $expectedFormat) {
		$errors[] = $fixture.' detected format mismatch: '.$detected.' != '.$expectedFormat;
		continue;
	}

	$txns = $parser->parse_lines($lines, $detected, 'EUR');
	$count = count((array) $txns);
	if ($count !== 53) {
		$errors[] = $fixture.' transaction count mismatch: '.$count.' != 53';
		continue;
	}

	for ($i = 0; $i < 5; $i++) {
		if (!isset($txns[$i]) || !is_array($txns[$i])) {
			$errors[] = $fixture.' missing transaction index '.$i;
			break;
		}
		$txn = $txns[$i];
		$expected = $expectedFirstFive[$i];
		$date = isset($txn['booking_date']) ? (string) $txn['booking_date'] : '';
		$amount = isset($txn['amount']) ? (float) $txn['amount'] : null;
		if ($date !== (string) $expected['date']) {
			$errors[] = $fixture.' booking_date mismatch at index '.$i.': '.$date.' != '.$expected['date'];
		}
		if ($amount === null || abs((float) $amount - (float) $expected['amount']) > 0.001) {
			$errors[] = $fixture.' amount mismatch at index '.$i.': '.(is_null($amount) ? 'NULL' : sprintf('%.2f', (float) $amount)).' != '.sprintf('%.2f', (float) $expected['amount']);
		}
		if ($i >= 2) {
			$description = isset($txn['description']) ? trim((string) $txn['description']) : '';
			if ($description === '') {
				$errors[] = $fixture.' expected non-empty description at index '.$i;
			}
		}
		$reference = isset($txn['reference']) ? $txn['reference'] : null;
		if ($reference !== null && trim((string) $reference) !== '') {
			$errors[] = $fixture.' expected null reference in first five entries at index '.$i;
		}
	}

	foreach ($txns as $idx => $txn) {
		if (!is_array($txn)) {
			$errors[] = $fixture.' transaction payload is invalid at index '.$idx;
			continue;
		}

		$reference = isset($txn['reference']) ? $txn['reference'] : null;
		if ($reference !== null) {
			$refCompact = strtoupper((string) preg_replace('/\s+/', '', (string) $reference));
			if ($refCompact === 'NONREF') {
				$errors[] = $fixture.' reference NONREF leaked at index '.$idx;
			}
			if ($refCompact !== '' && preg_match('/^0+$/', $refCompact)) {
				$errors[] = $fixture.' all-zero reference leaked at index '.$idx;
			}
		}

		foreach (array('description', 'reference', 'counterparty_name') as $field) {
			if (!isset($txn[$field]) || $txn[$field] === null) {
				continue;
			}
			$value = trim((string) $txn[$field]);
			if ($value !== '' && preg_match('/^0{6,}/', $value)) {
				$errors[] = $fixture.' field '.$field.' contains leading zero-padding at index '.$idx.': '.$value;
			}
		}

		$raw = isset($txn['raw']) && is_array($txn['raw']) ? $txn['raw'] : array();
		if (empty($raw['format']) || (string) $raw['format'] !== (string) $expectedFormat) {
			$errors[] = $fixture.' raw.format mismatch at index '.$idx;
		}
		if (!isset($raw['lines']) || !is_array($raw['lines']) || empty($raw['lines'])) {
			$errors[] = $fixture.' raw.lines missing at index '.$idx;
		}
		if (!isset($raw['fields']) || !is_array($raw['fields'])) {
			$errors[] = $fixture.' raw.fields missing at index '.$idx;
		}
	}
}

if (!empty($errors)) {
	fwrite(STDERR, 'Parser golden tests failed:'.PHP_EOL);
	foreach ($errors as $error) {
		fwrite(STDERR, ' - '.$error.PHP_EOL);
	}
	exit(1);
}

echo 'OK: parser golden tests ('.count($fixtures).' fixtures)'.PHP_EOL;
exit(0);
