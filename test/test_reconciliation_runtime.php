#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

if (!function_exists('dol_string_nospecial')) {
	function dol_string_nospecial($value)
	{
		$GLOBALS['__kb_last_nospecial_input'] = (string) $value;
		return (string) $value;
	}
}

if (!function_exists('getDolGlobalString')) {
	function getDolGlobalString($name, $default = '')
	{
		return (string) $default;
	}
}

if (!isset($GLOBALS['conf']) || !is_object($GLOBALS['conf'])) {
	$GLOBALS['conf'] = new stdClass();
}
if (!isset($GLOBALS['conf']->global) || !is_object($GLOBALS['conf']->global)) {
	$GLOBALS['conf']->global = new stdClass();
}

/**
 * Run runtime assertions against the reconciliation matcher behavior.
 *
 * @param string $rootDir
 * @return array<int,string>
 */
function kreabankRunRuntimeReconciliationAssertions($rootDir)
{
	$errors = array();
	$matcherFile = rtrim((string) $rootDir, '/').'/class/KreaBankMatcher.class.php';
	if (!is_readable($matcherFile)) {
		return array('Unable to read matcher file: '.$matcherFile);
	}

	require_once $matcherFile;
	if (!class_exists('KreaBankMatcherRuntimeProbe')) {
		class KreaBankMatcherRuntimeProbe extends KreaBankMatcher
		{
			/**
			 * @param string $a
			 * @param string $b
			 * @return float
			 */
			public function probeTextSimilarity($a, $b)
			{
				return (float) $this->textSimilarity($a, $b);
			}

			/**
			 * @param string $linePaymentReference
			 * @param string $documentRef
			 * @return bool
			 */
			public function probeStrongReferenceMatch($linePaymentReference, $documentRef)
			{
				return (bool) $this->hasStrongPaymentReferenceMatch($linePaymentReference, $documentRef);
			}

			/**
			 * @param array<string,mixed> $line
			 * @param array<string,mixed> $document
			 * @param array<int,array<string,mixed>> $patterns
			 * @param array<int,string> $details
			 * @return array{score:int,details:array<int,string>}
			 */
			public function probeApplyPatternScore($line, $document, $patterns, $details = array())
			{
				$runtimeDetails = (array) $details;
				$score = (int) $this->applyPatternScore($line, $document, $patterns, $runtimeDetails);

				return array(
					'score' => $score,
					'details' => $runtimeDetails,
				);
			}
		}
	}
	$matcher = new KreaBankMatcher();
	$matcherProbe = new KreaBankMatcherRuntimeProbe();

	$assertTrue = static function ($condition, $label) use (&$errors) {
		if (!$condition) {
			$errors[] = $label;
		}
	};

	$line = array(
		'amount' => -15.80,
		'operation_date' => '2026-01-06',
		'payment_reference' => '',
		'description' => '',
		'counterparty_name' => '',
		'counterparty_iban' => '',
	);
	$document = array(
		'rowid' => 1,
		'doc_type' => 'payment_supplier',
		'amount_open' => -15.80,
		'doc_date' => '2026-01-06',
		'ref' => '',
		'ref_client' => '',
		'thirdparty_name' => '',
		'thirdparty_iban' => '',
	);

	$exactScore = $matcher->scoreLineToDocument($line, $document, array(), 3);
	$exactDetailMap = array_flip((array) $exactScore['details']);
	$assertTrue((int) $exactScore['score'] === 100, 'Exact amount+date score should be 100');
	$assertTrue(isset($exactDetailMap['amount']), 'Exact amount+date should include amount evidence');
	$assertTrue(isset($exactDetailMap['date']), 'Exact amount+date should include date evidence');
	$amountDateOnlySafe = $matcher->getSafeSuggestion(array(array(
		'score' => (int) $exactScore['score'],
		'details' => (array) $exactScore['details'],
		'doc_id' => 1,
		'amount_open' => -15.80,
	)), 100);
	$assertTrue($amountDateOnlySafe === null, 'Exact amount+date without strong identity must not be treated as an automatic safe match');

	$linkedLine = array(
		'amount' => -9.00,
		'operation_date' => '2026-01-09',
		'payment_reference' => '',
		'description' => '',
		'counterparty_name' => '',
		'counterparty_iban' => '',
	);
	$linkedDocument = array(
		'rowid' => 2,
		'doc_type' => 'payment_linked',
		'amount_open' => -9.00,
		'doc_date' => '2025-11-15',
		'linked_bank_date' => '2026-01-09',
		'ref' => '',
		'ref_client' => '',
		'thirdparty_name' => '',
		'thirdparty_iban' => '',
	);
	$linkedScore = $matcher->scoreLineToDocument($linkedLine, $linkedDocument, array(), 3);
	$linkedDetailMap = array_flip((array) $linkedScore['details']);
	$assertTrue((int) $linkedScore['score'] === 100, 'Linked payment should use linked_bank_date for date evidence');
	$assertTrue(isset($linkedDetailMap['amount']), 'Linked payment should include amount evidence');
	$assertTrue(isset($linkedDetailMap['date']), 'Linked payment should include date evidence');

	$noAmountLine = array(
		'amount' => -20.00,
		'operation_date' => '2026-01-10',
		'payment_reference' => 'ABC12345',
		'description' => 'ABC12345',
		'counterparty_name' => '',
		'counterparty_iban' => '',
	);
	$noAmountDocument = array(
		'rowid' => 3,
		'doc_type' => 'payment',
		'amount_open' => -10.00,
		'doc_date' => '2026-01-10',
		'ref' => 'ABC12345',
		'ref_client' => '',
		'thirdparty_name' => '',
		'thirdparty_iban' => '',
	);
	$noAmountScore = $matcher->scoreLineToDocument($noAmountLine, $noAmountDocument, array(), 3);
	$assertTrue((int) $noAmountScore['score'] === 0, 'Reference-only matches without amount evidence must be rejected');
	$assertTrue(empty($noAmountScore['details']), 'Rejected reference-only match should not return scoring details');

	$prefixOnlyLine = array(
		'amount' => -12.50,
		'operation_date' => '2026-01-11',
		'payment_reference' => 'FAT20260001XYZCO',
		'description' => '',
		'counterparty_name' => '',
		'counterparty_iban' => '',
	);
	$prefixOnlyDocument = array(
		'rowid' => 4,
		'doc_type' => 'payment',
		'amount_open' => -12.50,
		'doc_date' => '2026-01-11',
		'ref' => 'FAT20260',
		'ref_client' => '',
		'thirdparty_name' => '',
		'thirdparty_iban' => '',
	);
	$prefixOnlyScore = $matcher->scoreLineToDocument($prefixOnlyLine, $prefixOnlyDocument, array(), 3);
	$prefixDetailMap = array_flip((array) $prefixOnlyScore['details']);
	$assertTrue(!isset($prefixDetailMap['ref_payment']), 'Prefix-only reference relationship must not be treated as strong payment reference match');
	$assertTrue((int) $prefixOnlyScore['score'] === 150, 'Prefix-only reference should score through ref_exact only (no 120-point strong-reference bonus)');
	$shortReferenceMatch = $matcherProbe->probeStrongReferenceMatch('0320001', '0320001');
	$assertTrue($shortReferenceMatch === false, 'Strong payment reference matching should reject normalized references shorter than the minimum-length threshold');
	$minLengthReferenceMatch = $matcherProbe->probeStrongReferenceMatch('03200001', '03200001');
	$assertTrue($minLengthReferenceMatch === true, 'Strong payment reference matching should accept exact matches at the minimum-length threshold');

	$patternLine = array(
		'amount' => -30.00,
		'operation_date' => '2026-01-12',
		'payment_reference' => '',
		'description' => 'Pagamento mensal fatura 90210',
		'counterparty_name' => '',
		'counterparty_iban' => 'PT50000201231234567890154',
	);
	$patternDocument = array(
		'rowid' => 10,
		'doc_type' => 'payment',
		'amount_open' => -30.00,
		'doc_date' => '2026-01-12',
		'ref' => '',
		'ref_client' => '',
		'thirdparty_name' => '',
		'thirdparty_iban' => '',
	);
	$duplicatePatterns = array(
		array('doc_type' => 'payment', 'fk_doc' => 10, 'pattern_type' => 'iban', 'pattern_value' => 'PT50000201231234567890154'),
		array('doc_type' => 'payment', 'fk_doc' => 10, 'pattern_type' => 'iban', 'pattern_value' => 'PT50000201231234567890154'),
		array('doc_type' => 'payment', 'fk_doc' => 10, 'pattern_type' => 'description', 'pattern_value' => 'pagamento mensal'),
		array('doc_type' => 'payment', 'fk_doc' => 10, 'pattern_type' => 'description', 'pattern_value' => 'pagamento mensal'),
	);
	$patternScore = $matcher->scoreLineToDocument($patternLine, $patternDocument, $duplicatePatterns, 3);
	$patternDetails = (array) $patternScore['details'];
	$patternDetailCounts = array_count_values($patternDetails);
	$assertTrue((int) $patternScore['score'] === 200, 'Duplicate pattern rows should not inflate pattern score beyond one IBAN and one description bonus');
	$assertTrue((int) ($patternDetailCounts['pattern_iban'] ?? 0) === 1, 'Duplicate IBAN patterns should add pattern_iban evidence only once');
	$assertTrue((int) ($patternDetailCounts['pattern_desc'] ?? 0) === 1, 'Duplicate description patterns should add pattern_desc evidence only once');
	$patternCapProbe = $matcherProbe->probeApplyPatternScore($patternLine, $patternDocument, $duplicatePatterns, array());
	$assertTrue((int) $patternCapProbe['score'] <= 100, 'applyPatternScore should stay capped at the sum of IBAN and description pattern weights');

	$ibanLine = array(
		'amount' => -44.20,
		'operation_date' => '2026-01-12',
		'payment_reference' => '',
		'description' => '',
		'counterparty_name' => '',
		'counterparty_iban' => 'PT50000201231234567890154',
	);
	$ibanValidDocument = array(
		'rowid' => 12,
		'doc_type' => 'payment',
		'amount_open' => -44.20,
		'doc_date' => '2026-01-12',
		'ref' => '',
		'ref_client' => '',
		'thirdparty_name' => '',
		'thirdparty_iban' => 'PT50000201231234567890154',
	);
	$ibanValidScore = $matcher->scoreLineToDocument($ibanLine, $ibanValidDocument, array(), 3);
	$ibanValidDetailMap = array_flip((array) $ibanValidScore['details']);
	$assertTrue(isset($ibanValidDetailMap['iban']), 'Valid IBAN with known country/checksum should contribute IBAN evidence');
	$assertTrue((int) $ibanValidScore['score'] === 145, 'Valid IBAN should add 45-point IBAN signal to amount+date score');

	$ibanInvalidChecksumDocument = $ibanValidDocument;
	$ibanInvalidChecksumDocument['rowid'] = 13;
	$ibanInvalidChecksumDocument['thirdparty_iban'] = 'PT00000201231234567890154';
	$ibanInvalidChecksumScore = $matcher->scoreLineToDocument($ibanLine, $ibanInvalidChecksumDocument, array(), 3);
	$ibanInvalidChecksumDetailMap = array_flip((array) $ibanInvalidChecksumScore['details']);
	$assertTrue(!isset($ibanInvalidChecksumDetailMap['iban']), 'Invalid IBAN checksum must not contribute IBAN evidence');
	$assertTrue((int) $ibanInvalidChecksumScore['score'] === 100, 'Invalid IBAN checksum should keep only amount+date score');

	$ibanUnknownCountryDocument = $ibanValidDocument;
	$ibanUnknownCountryDocument['rowid'] = 14;
	$ibanUnknownCountryDocument['thirdparty_iban'] = 'ZZ50000201231234567890154';
	$ibanUnknownCountryScore = $matcher->scoreLineToDocument($ibanLine, $ibanUnknownCountryDocument, array(), 3);
	$ibanUnknownCountryDetailMap = array_flip((array) $ibanUnknownCountryScore['details']);
	$assertTrue(!isset($ibanUnknownCountryDetailMap['iban']), 'Unknown IBAN country code must not contribute IBAN evidence');

	$calendarBoundaryLine = array(
		'amount' => -18.00,
		'operation_date' => '2026-01-10 23:59:00',
		'payment_reference' => '',
		'description' => '',
		'counterparty_name' => '',
		'counterparty_iban' => '',
	);
	$calendarBoundaryDocument = array(
		'rowid' => 11,
		'doc_type' => 'payment',
		'amount_open' => -18.00,
		'doc_date' => '2026-01-14 00:00:00',
		'ref' => '',
		'ref_client' => '',
		'thirdparty_name' => '',
		'thirdparty_iban' => '',
	);
	$calendarBoundaryScore = $matcher->scoreLineToDocument($calendarBoundaryLine, $calendarBoundaryDocument, array(), 3);
	$calendarBoundaryDetailMap = array_flip((array) $calendarBoundaryScore['details']);
	$assertTrue(!isset($calendarBoundaryDetailMap['date']), 'Calendar-day distance must treat 2026-01-10 to 2026-01-14 as 4 days (outside 3-day tolerance)');
	$assertTrue((int) $calendarBoundaryScore['score'] === 70, 'Calendar-day boundary case should keep amount evidence only when day distance exceeds tolerance');

	$safeGapSuggestions = array(
		array(
			'score' => 190,
			'details' => array('amount', 'ref_payment'),
			'doc_id' => 100,
			'amount_open' => -20.00,
		),
		array(
			'score' => 160,
			'details' => array('amount', 'date', 'name_partial'),
			'doc_id' => 101,
			'amount_open' => -20.00,
		),
	);
	$safeGapTop = $matcher->getSafeSuggestion($safeGapSuggestions, 150);
	$assertTrue($safeGapTop === null, 'Safe suggestion requires at least 35-point gap between top and second candidate');
	$strongSafeSuggestions = array(
		array(
			'score' => 210,
			'details' => array('amount', 'ref_payment', 'date'),
			'doc_id' => 110,
			'amount_open' => -20.00,
		),
		array(
			'score' => 160,
			'details' => array('amount', 'date', 'name_partial'),
			'doc_id' => 111,
			'amount_open' => -20.00,
		),
	);
	$strongSafeTop = $matcher->getSafeSuggestion($strongSafeSuggestions, 150);
	$assertTrue(is_array($strongSafeTop) && (int) $strongSafeTop['doc_id'] === 110, 'Strong identity with exact amount and sufficient score gap should remain automatically safe');

	$GLOBALS['__kb_last_nospecial_input'] = null;
	$normalizedProbe = kreabankNormalizeText('  ABC   DEF  ');
	$assertTrue((string) ($GLOBALS['__kb_last_nospecial_input'] ?? '') === 'abc def', 'Text normalization must lowercase input before dol_string_nospecial');
	$assertTrue($normalizedProbe === 'abc def', 'Text normalization should return a lowercase single-space normalized token');

	$ratioGuardSimilarity = $matcherProbe->probeTextSimilarity(str_repeat('a', 120), 'bb');
	$assertTrue($ratioGuardSimilarity === 0.0, 'textSimilarity should short-circuit extreme length-ratio comparisons');

	$cappedSimilarity = $matcherProbe->probeTextSimilarity(str_repeat('x', 80), str_repeat('x', 80));
	$assertTrue($cappedSimilarity >= 99.9, 'textSimilarity should preserve high similarity after bounded-length truncation');

	$tieLine = array(
		'amount' => -22.00,
		'operation_date' => '2026-01-15',
		'payment_reference' => '',
		'description' => '',
		'counterparty_name' => '',
		'counterparty_iban' => '',
	);
	$tieDocuments = array(
		array(
			'rowid' => 3002,
			'doc_type' => 'payment',
			'amount_open' => -22.00,
			'doc_date' => '2026-01-15',
			'ref' => '',
			'ref_client' => '',
			'thirdparty_name' => '',
			'thirdparty_iban' => '',
		),
		array(
			'rowid' => 3001,
			'doc_type' => 'payment',
			'amount_open' => -22.00,
			'doc_date' => '2026-01-15',
			'ref' => '',
			'ref_client' => '',
			'thirdparty_name' => '',
			'thirdparty_iban' => '',
		),
	);
	$tieSuggestions = $matcher->getSuggestions($tieLine, $tieDocuments, array(), 3, 1);
	$assertTrue(count($tieSuggestions) === 2, 'Tie-order runtime scenario should return both candidates');
	$assertTrue((int) $tieSuggestions[0]['doc_id'] === 3001 && (int) $tieSuggestions[1]['doc_id'] === 3002, 'getSuggestions should use doc_id as deterministic final tiebreak for equal score and amount');

	$suggestions = $matcher->getSuggestions($line, array($document), array(), 3, 1);
	$assertTrue(count($suggestions) === 1, 'getSuggestions should return one runtime candidate above min score');
	$assertTrue((int) $suggestions[0]['score'] === 100, 'Runtime suggestion score should match scorer output');

	return $errors;
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath((string) $argv[0]) === __FILE__) {
	$rootDir = dirname(__DIR__);
	$errors = kreabankRunRuntimeReconciliationAssertions($rootDir);
	if (!empty($errors)) {
		fwrite(STDERR, "Runtime reconciliation assertions failed:".PHP_EOL);
		foreach ($errors as $error) {
			fwrite(STDERR, ' - '.$error.PHP_EOL);
		}
		exit(1);
	}

	echo "OK: runtime reconciliation assertions".PHP_EOL;
	exit(0);
}
