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
if (!defined('DOL_DOCUMENT_ROOT')) {
	define('DOL_DOCUMENT_ROOT', dirname($rootDir, 2));
}
require_once $rootDir.'/class/KreaBankParser.class.php';

$parser = new KreaBankParser();
$reflection = new ReflectionClass($parser);
$errors = array();

$parseDate = $reflection->getMethod('parseDate');
$parseDate->setAccessible(true);
$parsedOverflowDate = $parseDate->invoke($parser, '1/19/2026');
if ((string) $parsedOverflowDate !== '2026-01-19') {
	$errors[] = 'parseDate should reject overflowed d/m parsing for 1/19/2026';
}
$parsedNamedMonthDate = $parseDate->invoke($parser, '25 Janeiro 2024');
if ((string) $parsedNamedMonthDate !== '2024-01-25') {
	$errors[] = 'Named-month dates should be parsed using bank statement month names';
}
$parsedContextDate = $parseDate->invokeArgs($parser, array('15/01', array(array('year' => 2026, 'month' => 1, 'day' => 6))));
if ((string) $parsedContextDate !== '2026-01-15') {
	$errors[] = 'Partial dates should inherit year/month from parsed statement context';
}
$parsedCompactDmyDate = $parseDate->invoke($parser, '12012026');
if ((string) $parsedCompactDmyDate !== '2026-01-12') {
	$errors[] = 'Compact DDMMYYYY token should parse to 2026-01-12';
}
$parsedCompactYmdDate = $parseDate->invoke($parser, '20260112');
if ((string) $parsedCompactYmdDate !== '2026-01-12') {
	$errors[] = 'Compact YYYYMMDD token should parse to 2026-01-12';
}
$decodeTextToUtf8 = $reflection->getMethod('decodeTextToUtf8');
$decodeTextToUtf8->setAccessible(true);
if (function_exists('mb_check_encoding') && function_exists('mb_convert_encoding')) {
	$sparseNullInput = "AB\0CD";
	$sparseNullDecoded = $decodeTextToUtf8->invoke($parser, $sparseNullInput);
	if ((string) $sparseNullDecoded !== $sparseNullInput) {
		$errors[] = 'decodeTextToUtf8 should not force UTF-16 conversion for sparse null-byte content';
	}

	$utf16LeInput = "\x41\x00\x42\x00\x43\x00\x44\x00\x45\x00\x46\x00\x47\x00\x48\x00\x49\x00\x4A\x00";
	$utf16LeDecoded = $decodeTextToUtf8->invoke($parser, $utf16LeInput);
	if ((string) $utf16LeDecoded !== 'ABCDEFGHIJ') {
		$errors[] = 'decodeTextToUtf8 should still decode high-density UTF-16LE content';
	}
}
$detectCsvDelimiter = $reflection->getMethod('detectCsvDelimiter');
$detectCsvDelimiter->setAccessible(true);
$tmpCsvProbe = tempnam(sys_get_temp_dir(), 'kreabank_csv_probe_');
if (!is_string($tmpCsvProbe) || $tmpCsvProbe === '') {
	$errors[] = 'Failed to allocate temporary CSV probe file for delimiter detection test';
} else {
	$csvProbePayload = "Conta 123456\nDate,Amount,Description\n2026-01-01,-10.00,Fee\n";
	file_put_contents($tmpCsvProbe, $csvProbePayload);
	$detectedDelimiter = $detectCsvDelimiter->invoke($parser, $tmpCsvProbe);
	if ((string) $detectedDelimiter !== ',') {
		$errors[] = 'detectCsvDelimiter should inspect multiple non-empty lines and detect comma delimiter after metadata rows';
	}
	@unlink($tmpCsvProbe);
}
$parseLocalizedNumber = $reflection->getMethod('parseLocalizedNumber');
$parseLocalizedNumber->setAccessible(true);
$doubleSignedNumber = $parseLocalizedNumber->invoke($parser, '(-1.234,56-)');
if (!is_float($doubleSignedNumber) || abs((float) $doubleSignedNumber + 1234.56) > 0.001) {
	$errors[] = 'parseLocalizedNumber should keep malformed dual-sign parenthesized values negative';
}
$internalSignNumber = $parseLocalizedNumber->invoke($parser, '1-234,56');
if ($internalSignNumber !== null) {
	$errors[] = 'parseLocalizedNumber should reject malformed internal sign placement';
}

$resolveThreePartBankDate = $reflection->getMethod('resolveThreePartBankDate');
$resolveThreePartBankDate->setAccessible(true);
$resolvedThreePartWithMarchContext = $resolveThreePartBankDate->invokeArgs($parser, array(array('03', '02', '2026'), array(array('year' => 2026, 'month' => 3, 'day' => 1))));
if (!is_array($resolvedThreePartWithMarchContext) || (int) ($resolvedThreePartWithMarchContext['year'] ?? 0) !== 2026 || (int) ($resolvedThreePartWithMarchContext['month'] ?? 0) !== 3 || (int) ($resolvedThreePartWithMarchContext['day'] ?? 0) !== 2) {
	$errors[] = 'resolveThreePartBankDate should use context to resolve MM/DD/YYYY when month token matches previous context';
}
$resolvedThreePartWithFebruaryContext = $resolveThreePartBankDate->invokeArgs($parser, array(array('03', '02', '2026'), array(array('year' => 2026, 'month' => 2, 'day' => 1))));
if (!is_array($resolvedThreePartWithFebruaryContext) || (int) ($resolvedThreePartWithFebruaryContext['year'] ?? 0) !== 2026 || (int) ($resolvedThreePartWithFebruaryContext['month'] ?? 0) !== 2 || (int) ($resolvedThreePartWithFebruaryContext['day'] ?? 0) !== 3) {
	$errors[] = 'resolveThreePartBankDate should preserve DD/MM/YYYY resolution when context month matches second token';
}

$callHelperRejected = false;
try {
	$parser->callHelper('normalizeHeaderKey', 'Data Movimento');
} catch (Throwable $e) {
	$callHelperRejected = true;
}
if (!$callHelperRejected) {
	$errors[] = 'callHelper should reject helper methods that are not explicitly allowlisted';
}
$registeredNormalizeHelper = $parser->registerStrategyHelper('normalizeHeaderKey');
if ($registeredNormalizeHelper !== true) {
	$errors[] = 'registerStrategyHelper should allow explicit helper registration for existing parser methods';
}
$normalizedViaRegisteredHelper = $parser->callHelper('normalizeHeaderKey', 'Data Movimento');
if ((string) $normalizedViaRegisteredHelper !== 'data_movimento') {
	$errors[] = 'callHelper should allow registered helper methods after explicit registration';
}

$getFieldAliases = $reflection->getMethod('getFieldAliases');
$getFieldAliases->setAccessible(true);
$expandedAliases = $getFieldAliases->invoke($parser);
$descriptionAliases = (isset($expandedAliases['description']) && is_array($expandedAliases['description'])) ? $expandedAliases['description'] : array();
if (in_array('data', $descriptionAliases, true)) {
	$errors[] = 'buildExpandedFieldAliases should avoid cross-field generic alias collisions such as \"data\" on description';
}
$parseRows = $reflection->getMethod('parseTabularRowsWithMapping');
$parseRows->setAccessible(true);
$mappedLines = $parseRows->invoke(
	$parser,
	array(
		array('01/06/2025', '-10.00'),
		array('15/06', '-20.00'),
		array('16', '-30.00'),
	),
	'EUR',
	array(
		'header_row' => -1,
		'operation_date' => 0,
		'value_date' => 0,
		'amount' => 1,
		'debit' => -1,
		'credit' => -1,
		'running_balance' => -1,
		'description' => -1,
		'payment_reference' => -1,
		'bank_reference' => -1,
		'counterparty_iban' => -1,
		'counterparty_name' => -1,
		'currency' => -1,
	),
	0
);
$mappedDates = array();
foreach ((array) $mappedLines as $mappedLine) {
	$mappedDates[] = isset($mappedLine['operation_date']) ? (string) $mappedLine['operation_date'] : '';
}
if ($mappedDates !== array('2025-06-01', '2025-06-15', '2025-06-16')) {
	$errors[] = 'Tabular parsing should propagate date context across statement rows';
}

$loadAutoloader = $reflection->getMethod('loadPhpSpreadsheetAutoloader');
$loadAutoloader->setAccessible(true);
$loadAutoloader->invoke($parser);

$spreadsheetClass = '\\PhpOffice\\PhpSpreadsheet\\Spreadsheet';
$writerClass = '\\PhpOffice\\PhpSpreadsheet\\Writer\\Xlsx';
if (!class_exists($spreadsheetClass) || !class_exists($writerClass)) {
	echo 'SKIP: PhpSpreadsheet not available'.PHP_EOL;
	exit(0);
}

$tempBase = tempnam(sys_get_temp_dir(), 'kreabank_excel_');
if (!is_string($tempBase) || $tempBase === '') {
	fwrite(STDERR, "Failed to create temporary file".PHP_EOL);
	exit(1);
}
@unlink($tempBase);
$tempFile = $tempBase.'.xlsx';
register_shutdown_function(static function () use ($tempFile) {
	if (is_string($tempFile) && $tempFile !== '' && file_exists($tempFile)) {
		@unlink($tempFile);
	}
});

$spreadsheet = new $spreadsheetClass();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setCellValue('A1', 46028);
$sheet->getStyle('A1')->getNumberFormat()->setFormatCode('m/d/yyyy');
$sheet->setCellValue('B1', 'REF000123');
$writer = new $writerClass($spreadsheet);
$writer->save($tempFile);

$loadRows = $reflection->getMethod('loadExcelRowsWithPhpSpreadsheet');
$loadRows->setAccessible(true);
$rows = $loadRows->invoke($parser, $tempFile);

$firstDate = isset($rows[0][0]) ? (string) $rows[0][0] : '';
$firstReference = isset($rows[0][1]) ? (string) $rows[0][1] : '';
if ($firstDate !== '2026-01-06') {
	$errors[] = 'PhpSpreadsheet Excel date cell should normalize to ISO date (expected 2026-01-06, got '.$firstDate.')';
}
if ($firstReference !== 'REF000123') {
	$errors[] = 'Non-date cells should keep their original formatted value';
}

if (!empty($errors)) {
	fwrite(STDERR, "Excel date normalization test failed:".PHP_EOL);
	foreach ($errors as $error) {
		fwrite(STDERR, ' - '.$error.PHP_EOL);
	}
	exit(1);
}

echo 'OK: Excel date normalization'.PHP_EOL;
exit(0);
