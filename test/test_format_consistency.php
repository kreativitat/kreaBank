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
$fixturesDir = __DIR__;
require_once $rootDir.'/class/KreaBankParser.class.php';

$fixtures = array(
	'5700__norma43.txt',
	'5703__norma43_registo24.txt',
	'5704__norma43_978.txt',
	'5706__composto_nib.txt',
	'5709__consolidado.txt',
	'5711__tabulado_folha_calculo.txt',
	'5712__tabulado_excel.csv',
	'5713__bit_reconciliacao.txt',
	'5716__emc.txt',
	'5717__mt940.txt',
);

$baselineFile = '5717__mt940.txt';
$parser = new KreaBankParser();

/**
 * Build a stable signature for parsed lines.
 *
 * @param array<int,array<string,mixed>> $lines
 * @return array{count:int,sum:float,min_date:string,max_date:string,signature:string}
 */
$summarize = function ($lines) {
	$count = count((array) $lines);
	$sum = 0.0;
	$minDate = '';
	$maxDate = '';
	$pairs = array();

	foreach ((array) $lines as $line) {
		$amount = isset($line['amount']) ? (float) $line['amount'] : 0.0;
		$date = isset($line['operation_date']) ? trim((string) $line['operation_date']) : '';
		$sum += $amount;

		if ($date !== '') {
			if ($minDate === '' || $date < $minDate) {
				$minDate = $date;
			}
			if ($maxDate === '' || $date > $maxDate) {
				$maxDate = $date;
			}
		}

		$pairs[] = $date.'|'.sprintf('%.2f', $amount);
	}

	sort($pairs);

	return array(
		'count' => $count,
		'sum' => (float) $sum,
		'min_date' => $minDate,
		'max_date' => $maxDate,
		'signature' => sha1(implode("\n", $pairs)),
	);
};

$baselinePath = $fixturesDir.'/'.$baselineFile;
if (!is_readable($baselinePath)) {
	fwrite(STDERR, "Missing baseline fixture: ".$baselineFile.PHP_EOL);
	exit(1);
}

$baselineParsed = $parser->parse($baselinePath, $baselineFile, 'EUR');
$baselineSummary = $summarize((array) $baselineParsed['lines']);

$errors = array();
$parsedByFixture = array();
foreach ($fixtures as $fixture) {
	$path = $fixturesDir.'/'.$fixture;
	if (!is_readable($path)) {
		$errors[] = 'Missing fixture: '.$fixture;
		continue;
	}

	try {
		$parsed = $parser->parse($path, $fixture, 'EUR');
		$parsedByFixture[$fixture] = $parsed;
		$summary = $summarize((array) $parsed['lines']);
	} catch (Throwable $e) {
		$errors[] = 'Parse failed for '.$fixture.': '.$e->getMessage();
		continue;
	}

	if ((int) $summary['count'] !== (int) $baselineSummary['count']) {
		$errors[] = $fixture.' count mismatch '.$summary['count'].' != '.$baselineSummary['count'];
	}
	if (abs((float) $summary['sum'] - (float) $baselineSummary['sum']) > 0.01) {
		$errors[] = $fixture.' sum mismatch '.sprintf('%.2f', (float) $summary['sum']).' != '.sprintf('%.2f', (float) $baselineSummary['sum']);
	}
	if ((string) $summary['min_date'] !== (string) $baselineSummary['min_date']) {
		$errors[] = $fixture.' min_date mismatch '.$summary['min_date'].' != '.$baselineSummary['min_date'];
	}
	if ((string) $summary['max_date'] !== (string) $baselineSummary['max_date']) {
		$errors[] = $fixture.' max_date mismatch '.$summary['max_date'].' != '.$baselineSummary['max_date'];
	}
	if ((string) $summary['signature'] !== (string) $baselineSummary['signature']) {
		$errors[] = $fixture.' transaction signature mismatch';
	}
}

$noMappingFixtures = array(
	'5700__norma43.txt',
	'5703__norma43_registo24.txt',
	'5704__norma43_978.txt',
	'5706__composto_nib.txt',
	'5709__consolidado.txt',
	'5713__bit_reconciliacao.txt',
	'5716__emc.txt',
	'5717__mt940.txt',
);
foreach ($noMappingFixtures as $fixture) {
	$path = $fixturesDir.'/'.$fixture;
	if (!is_readable($path)) {
		continue;
	}
	try {
		$analysis = $parser->analyze($path, $fixture, 'EUR', false);
		if (!empty($analysis['supports_mapping'])) {
			$errors[] = $fixture.' analysis should not require mapping editor by default';
		}
		if (empty($analysis['sample_lines'])) {
			$errors[] = $fixture.' analysis sample_lines is unexpectedly empty';
		}
	} catch (Throwable $e) {
		$errors[] = 'Analyze failed for '.$fixture.': '.$e->getMessage();
	}
}

$normaFixtures = array('5700__norma43.txt', '5703__norma43_registo24.txt', '5704__norma43_978.txt');
foreach ($normaFixtures as $fixture) {
	if (empty($parsedByFixture[$fixture]['lines']) || !is_array($parsedByFixture[$fixture]['lines'])) {
		continue;
	}
	foreach (array_slice((array) $parsedByFixture[$fixture]['lines'], 0, 15) as $line) {
		$description = isset($line['description']) ? trim((string) $line['description']) : '';
		if ($description !== '' && preg_match('/^0{4,}/', $description)) {
			$errors[] = $fixture.' description still has numeric left-padding: '.$description;
			break;
		}
	}
}

// Regression guard: XLSX statements without explicit header row must not map description digits as amount.
$xlsxRegressionFixture = 's1.xlsx';
$xlsxRegressionPath = $fixturesDir.'/'.$xlsxRegressionFixture;
if (is_readable($xlsxRegressionPath)) {
	try {
		$analysis = $parser->analyze($xlsxRegressionPath, $xlsxRegressionFixture, 'EUR', false);
		$mapping = (!empty($analysis['suggested_mapping']) && is_array($analysis['suggested_mapping'])) ? (array) $analysis['suggested_mapping'] : array();
		if ((int) (!isset($analysis['header_row_index']) ? -999 : $analysis['header_row_index']) !== -1) {
			$errors[] = $xlsxRegressionFixture.' should not auto-select a data row as header';
		}
		if ((int) (!isset($mapping['amount']) ? -1 : $mapping['amount']) < 0) {
			$errors[] = $xlsxRegressionFixture.' should infer a numeric amount column';
		}

		$parsed = $parser->parse($xlsxRegressionPath, $xlsxRegressionFixture, 'EUR');
		$lines = (!empty($parsed['lines']) && is_array($parsed['lines'])) ? (array) $parsed['lines'] : array();
		if (count($lines) < 20) {
			$errors[] = $xlsxRegressionFixture.' parsed line count unexpectedly low';
		}
		if (!empty($lines[0]) && is_array($lines[0])) {
			$firstDate = isset($lines[0]['operation_date']) ? (string) $lines[0]['operation_date'] : '';
			$firstAmount = isset($lines[0]['amount']) ? (float) $lines[0]['amount'] : 0.0;
			if ($firstDate !== '2026-02-02') {
				$errors[] = $xlsxRegressionFixture.' first operation_date mismatch '.$firstDate.' != 2026-02-02';
			}
			if (abs($firstAmount - (-61.0)) > 0.01) {
				$errors[] = $xlsxRegressionFixture.' first amount mismatch '.sprintf('%.2f', $firstAmount).' != -61.00';
			}
		}
		foreach (array_slice($lines, 0, 10) as $idx => $line) {
			if (!is_array($line)) {
				$errors[] = $xlsxRegressionFixture.' invalid line payload at index '.$idx;
				continue;
			}
			$amount = isset($line['amount']) ? (float) $line['amount'] : 0.0;
			$date = isset($line['operation_date']) ? trim((string) $line['operation_date']) : '';
			if ($date === '') {
				$errors[] = $xlsxRegressionFixture.' missing operation_date at index '.$idx;
			}
			if (abs($amount) > 5000.0) {
				$errors[] = $xlsxRegressionFixture.' suspicious amount at index '.$idx.' (possible balance-column mapping): '.sprintf('%.2f', $amount);
			}
		}
	} catch (Throwable $e) {
		$errors[] = 'Regression parse failed for '.$xlsxRegressionFixture.': '.$e->getMessage();
	}
}

if (!empty($errors)) {
	fwrite(STDERR, "Format consistency check failed:".PHP_EOL);
	foreach ($errors as $error) {
		fwrite(STDERR, ' - '.$error.PHP_EOL);
	}
	exit(1);
}

echo 'OK: format consistency check ('.count($fixtures).' fixtures)'.PHP_EOL;
exit(0);
