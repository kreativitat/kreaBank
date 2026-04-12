<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

require_once __DIR__ . '/../lib/kreabank.lib.php';

/**
 * Multi-criteria matching engine.
 */
class KreaBankMatcher
{
	/**
	 * Minimum normalized reference length required for strong payment-reference matching.
	 * This prevents short/generic tokens from receiving the high-confidence ref bonus.
	 */
	const STRONG_REFERENCE_MIN_LENGTH = 8;

	/**
	 * Score one line against candidate documents.
	 *
	 * @param array<string,mixed> $line
	 * @param array<int,array<string,mixed>> $documents
	 * @param array<int,array<string,mixed>> $patterns
	 * @param int $dateTolerance
	 * @param int $minScore
	 * @return array<int,array<string,mixed>>
	 */
	public function getSuggestions($line, $documents, $patterns, $dateTolerance = 3, $minScore = 0)
	{
		$suggestions = array();

		foreach ($documents as $document) {
			$scoreData = $this->scoreLineToDocument($line, $document, $patterns, $dateTolerance);
			if ($scoreData['score'] < $minScore) {
				continue;
			}

			$suggestions[] = array(
				'doc_type' => $document['doc_type'],
				'doc_id' => (int) $document['rowid'],
				'doc_ref' => $document['ref'],
				'doc_date' => !empty($document['doc_date']) ? (string) $document['doc_date'] : '',
				'thirdparty_name' => $document['thirdparty_name'],
				'amount_open' => (float) $document['amount_open'],
				'supplier_payment_id' => (int) (!empty($document['supplier_payment_id']) ? $document['supplier_payment_id'] : 0),
				'customer_payment_id' => (int) (!empty($document['customer_payment_id']) ? $document['customer_payment_id'] : 0),
				'score' => (int) $scoreData['score'],
				'details' => $scoreData['details'],
				'strategy_level' => $scoreData['strategy_level'],
			);
		}

		usort($suggestions, static function ($a, $b) {
			if ($b['score'] !== $a['score']) {
				return $b['score'] <=> $a['score'];
			}

			$amountComparison = abs($a['amount_open']) <=> abs($b['amount_open']);
			if ($amountComparison !== 0) {
				return $amountComparison;
			}

			return ((int) $a['doc_id']) <=> ((int) $b['doc_id']);
		});

		return $suggestions;
	}

	/**
	 * Score a statement line against one document.
	 *
	 * @param array<string,mixed> $line
	 * @param array<string,mixed> $document
	 * @param array<int,array<string,mixed>> $patterns
	 * @param int $dateTolerance
	 * @return array{score:int,details:array<int,string>,strategy_level:int}
	 */
	public function scoreLineToDocument($line, $document, $patterns, $dateTolerance = 3)
	{
		$score = 0;
		$details = array();
		$strategyLevel = 0;

		$lineAmount = abs((float) $line['amount']);
		$docAmount = abs((float) $document['amount_open']);
		$epsilon = 0.01;
		$docType = ($document['doc_type'] ?? '');
		$isNativeBankDocument = ($docType === 'native_bank');
		$isInvoiceDocument = in_array($docType, array('customer_invoice', 'supplier_invoice'), true);
		$isLoteBatchLine = $this->isLoteBatchLine($line);
		$hasStrongReferenceMatch = false;
		$dateOutsideTolerance = false;
		$distanceDays = null;

		// Native-bank pairing in this module is full-amount based.
		// Ignore amount_pending matches to avoid noisy candidates and allocation errors.
		if ($isNativeBankDocument && !$isLoteBatchLine && abs($lineAmount - $docAmount) > $epsilon) {
			return array(
				'score' => 0,
				'details' => array(),
				'strategy_level' => 0,
			);
		}

		if (abs($lineAmount - $docAmount) <= $epsilon) {
			$score += 70;
			$details[] = 'amount';
			$strategyLevel = max($strategyLevel, 1);
		} elseif ($isInvoiceDocument && $docAmount > 0 && $lineAmount < $docAmount + $epsilon) {
			// Partial amount matching is valid only for invoices (partial payment scenarios).
			$score += 40;
			$details[] = 'amount_pending';
		}

		$lineDate = !empty($line['operation_date']) ? strtotime((string) $line['operation_date']) : false;
		$docDateSource = (string) (!empty($document['doc_date']) ? $document['doc_date'] : '');
		if (in_array($docType, array('payment_linked', 'payment_supplier_linked'), true) && !empty($document['linked_bank_date'])) {
			$docDateSource = (string) $document['linked_bank_date'];
		}
		$docDate = ($docDateSource !== '' ? strtotime($docDateSource) : false);
		if ($lineDate && $docDate) {
			$distanceDays = $this->calculateCalendarDayDistance((int) $lineDate, (int) $docDate);
			if ($distanceDays <= (int) $dateTolerance) {
				$score += 30;
				$details[] = 'date';
				$strategyLevel = max($strategyLevel, 1);
			} else {
				$dateOutsideTolerance = true;
			}
		}

		$lineRefText = kreabankNormalizeText(($line['payment_reference'] ?? '') . ' ' . ($line['description'] ?? ''));
		$docRef = kreabankNormalizeText(($document['ref'] ?? ''));
		$docRefClients = $this->extractNormalizedReferences(($document['ref_client'] ?? ''));
		if ($this->hasStrongPaymentReferenceMatch(($line['payment_reference'] ?? ''), ($document['ref'] ?? ''))) {
			$score += 120;
			$details[] = 'ref_payment';
			$hasStrongReferenceMatch = true;
			$strategyLevel = max($strategyLevel, 3);
		} elseif ($docRef !== '' && strpos($lineRefText, $docRef) !== false) {
			$score += 50;
			$details[] = 'ref_exact';
			$hasStrongReferenceMatch = true;
			$strategyLevel = max($strategyLevel, 1);
		} elseif ($this->hasOneReferenceMatch($lineRefText, $docRefClients)) {
			$score += 35;
			$details[] = 'ref_client';
		}

		$lineName = kreabankNormalizeText(($line['counterparty_name'] ?? ''));
		$docName = kreabankNormalizeText(($document['thirdparty_name'] ?? ''));
		if ($lineName !== '' && $docName !== '') {
			if (strpos($lineName, $docName) !== false || strpos($docName, $lineName) !== false) {
				$score += 35;
				$details[] = 'name_partial';
				$strategyLevel = max($strategyLevel, 2);
			} else {
				$similarity = $this->textSimilarity($lineName, $docName);
				if ($similarity >= 72) {
					$score += 25;
					$details[] = 'name_fuzzy';
					$strategyLevel = max($strategyLevel, 2);
				}
			}
		}

		$lineIban = strtoupper(preg_replace('/\s+/', '', ($line['counterparty_iban'] ?? '')));
		$docIban = strtoupper(preg_replace('/\s+/', '', ($document['thirdparty_iban'] ?? '')));
		if ($lineIban !== '' && $docIban !== '' && $this->isLikelyIban($lineIban) && $this->isLikelyIban($docIban) && $lineIban === $docIban) {
			$score += 45;
			$details[] = 'iban';
			$strategyLevel = max($strategyLevel, 2);
		}

		if ($isNativeBankDocument && !$isLoteBatchLine && $dateOutsideTolerance && !$hasStrongReferenceMatch && $distanceDays !== null) {
			// Penalize candidates far from statement date to prefer exact-date rows when amounts are equal.
			$datePenalty = min(80, 20 + (int) max(0, $distanceDays - (int) $dateTolerance) * 2);
			$score -= $datePenalty;
			if ($score < 0) {
				$score = 0;
			}
		}

		$allowPatternScore = true;
		if ($isNativeBankDocument && !$isLoteBatchLine && $dateOutsideTolerance && !$hasStrongReferenceMatch) {
			// Prevent stale learned patterns from outranking a same-amount candidate on the exact date.
			$allowPatternScore = false;
		}

		$patternScore = $allowPatternScore ? $this->applyPatternScore($line, $document, $patterns, $details) : 0;
		if ($patternScore > 0) {
			$score += $patternScore;
			$strategyLevel = max($strategyLevel, 3);
		}

		$details = array_values(array_unique($details));
		$hasAmountEvidence = (in_array('amount', $details, true) || in_array('amount_pending', $details, true));
		if (!$hasAmountEvidence) {
			// Never suggest by date/name/reference alone without amount compatibility.
			return array(
				'score' => 0,
				'details' => array(),
				'strategy_level' => 0,
			);
		}
		$detailsWithoutPending = array_values(array_filter($details, static function ($detail) {
			return ((string) $detail !== 'amount_pending');
		}));
		if (!empty($details) && empty($detailsWithoutPending)) {
			// Ignore candidates that match only by "line amount < document amount".
			// This avoids noisy suggestions for unrelated large invoices/payments.
			return array(
				'score' => 0,
				'details' => array(),
				'strategy_level' => 0,
			);
		}
		if (in_array('amount_pending', $details, true)) {
			$detailMap = array_flip($details);
			$hasPendingIdentity = (
				isset($detailMap['ref_payment'])
				|| isset($detailMap['ref_exact'])
				|| isset($detailMap['ref_client'])
				|| isset($detailMap['name_partial'])
				|| isset($detailMap['name_fuzzy'])
				|| isset($detailMap['iban'])
				|| isset($detailMap['pattern_iban'])
				|| isset($detailMap['pattern_desc'])
			);
			if (!$hasPendingIdentity) {
				// Date + amount_pending alone is too weak and creates false positives.
				return array(
					'score' => 0,
					'details' => array(),
					'strategy_level' => 0,
				);
			}
		}

		return array(
			'score' => (int) $score,
			'details' => $details,
			'strategy_level' => $strategyLevel,
		);
	}

	/**
	 * Calculate absolute distance between two timestamps using UTC calendar days.
	 *
	 * @param int $leftTimestamp
	 * @param int $rightTimestamp
	 * @return int
	 */
	protected function calculateCalendarDayDistance($leftTimestamp, $rightTimestamp)
	{
		$leftDay = gmmktime(
			0,
			0,
			0,
			(int) gmdate('n', (int) $leftTimestamp),
			(int) gmdate('j', (int) $leftTimestamp),
			(int) gmdate('Y', (int) $leftTimestamp)
		);
		$rightDay = gmmktime(
			0,
			0,
			0,
			(int) gmdate('n', (int) $rightTimestamp),
			(int) gmdate('j', (int) $rightTimestamp),
			(int) gmdate('Y', (int) $rightTimestamp)
		);

		return (int) abs(($leftDay - $rightDay) / 86400);
	}

	/**
	 * Detect batch-like statement lines using configured hint keywords.
	 *
	 * @param array<string,mixed> $line
	 * @return bool
	 */
	protected function isLoteBatchLine($line)
	{
		return kreabankIsBatchLikeLine($line);
	}

	/**
	 * Evaluate if top suggestion is safe for batch reconciliation.
	 *
	 * @param array<int,array<string,mixed>> $suggestions
	 * @param int $safeScore
	 * @return array<string,mixed>|null
	 */
	public function getSafeSuggestion($suggestions, $safeScore)
	{
		if (empty($suggestions)) {
			return null;
		}

		$top = $suggestions[0];
		if ((int) $top['score'] < (int) $safeScore) {
			return null;
		}

		$topDetails = array_flip($top['details']);
		$hasStrongIdentity = isset($topDetails['ref_payment']) || isset($topDetails['ref_exact']) || isset($topDetails['iban']) || isset($topDetails['pattern_iban']);
		$hasAmount = isset($topDetails['amount']);
		if (!$hasStrongIdentity || !$hasAmount) {
			return null;
		}

		$minimumSafeGap = 35;
		if (isset($suggestions[1])) {
			$second = $suggestions[1];
			if (((int) $top['score'] - (int) $second['score']) < $minimumSafeGap) {
				return null;
			}
		}

		return $top;
	}

	/**
	 * Apply pattern-based scoring.
	 *
	 * @param array<string,mixed> $line
	 * @param array<string,mixed> $document
	 * @param array<int,array<string,mixed>> $patterns
	 * @param array<int,string> $details
	 * @return int
	 */
	protected function applyPatternScore($line, $document, $patterns, &$details)
	{
		$lineAmount = abs((float) ($line['amount'] ?? 0));
		$docAmount = abs((float) ($document['amount_open'] ?? 0));
		$epsilon = 0.01;
		$amountExact = (abs($lineAmount - $docAmount) <= $epsilon);
		$amountPending = ($docAmount > 0 && $lineAmount < $docAmount + $epsilon);

		// Never apply learned pattern score when document amount is smaller than line amount.
		// This avoids stale learned patterns forcing wrong candidates (ex: 11.20 suggested for 29.80 line).
		if (!$amountExact && !$amountPending) {
			return 0;
		}

		$ibanPatternWeight = ($amountExact ? 60 : 25);
		$descPatternWeight = ($amountExact ? 40 : 15);
		$maxPatternScore = $ibanPatternWeight + $descPatternWeight;
		$extraScore = 0;
		$lineIban = strtoupper(preg_replace('/\s+/', '', ($line['counterparty_iban'] ?? '')));
		$lineDesc = kreabankNormalizeText(($line['description'] ?? ''));
		$matchedIbanPattern = in_array('pattern_iban', (array) $details, true);
		$matchedDescPattern = in_array('pattern_desc', (array) $details, true);

		foreach ($patterns as $pattern) {
			if ((string) $pattern['doc_type'] !== (string) $document['doc_type']) {
				continue;
			}
			if ((int) $pattern['fk_doc'] !== (int) $document['rowid']) {
				continue;
			}

			if (
				!$matchedIbanPattern
				&&
				(string) $pattern['pattern_type'] === 'iban'
				&& $lineIban !== ''
				&& $this->isLikelyIban($lineIban)
				&& $this->isLikelyIban((string) $pattern['pattern_value'])
				&& $lineIban === strtoupper((string) $pattern['pattern_value'])
			) {
				$extraScore += $ibanPatternWeight;
				$details[] = 'pattern_iban';
				$matchedIbanPattern = true;
			}

			if (!$matchedDescPattern && (string) $pattern['pattern_type'] === 'description') {
				$patternText = kreabankNormalizeText((string) $pattern['pattern_value']);
				if ($patternText !== '' && strpos($lineDesc, $patternText) !== false) {
					$extraScore += $descPatternWeight;
					$details[] = 'pattern_desc';
					$matchedDescPattern = true;
				}
			}

			if ($matchedIbanPattern && $matchedDescPattern) {
				break;
			}
		}

		// Keep learned-pattern influence bounded to one IBAN bonus + one description bonus.
		return min($extraScore, $maxPatternScore);
	}

	/**
	 * Validate if token is a structurally valid IBAN with known country code and checksum.
	 *
	 * @param string $value
	 * @return bool
	 */
	protected function isLikelyIban($value)
	{
		$iban = strtoupper((string) preg_replace('/[^A-Z0-9]/', '', (string) $value));
		if ($iban === '') {
			return false;
		}
		if (!preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}$/', $iban)) {
			return false;
		}

		$countryCode = substr($iban, 0, 2);
		$countryLengths = $this->getIbanCountryLengths();
		if (empty($countryLengths[$countryCode])) {
			return false;
		}
		if (strlen($iban) !== (int) $countryLengths[$countryCode]) {
			return false;
		}

		return $this->isValidIbanChecksum($iban);
	}

	/**
	 * Return known IBAN country codes and canonical lengths.
	 *
	 * @return array<string,int>
	 */
	protected function getIbanCountryLengths()
	{
		return array(
			'AD' => 24,
			'AE' => 23,
			'AL' => 28,
			'AT' => 20,
			'AZ' => 28,
			'BA' => 20,
			'BE' => 16,
			'BG' => 22,
			'BH' => 22,
			'BI' => 16,
			'BR' => 29,
			'BY' => 28,
			'CH' => 21,
			'CR' => 22,
			'CY' => 28,
			'CZ' => 24,
			'DE' => 22,
			'DK' => 18,
			'DO' => 28,
			'EE' => 20,
			'EG' => 29,
			'ES' => 24,
			'FI' => 18,
			'FO' => 18,
			'FR' => 27,
			'GB' => 22,
			'GE' => 22,
			'GI' => 23,
			'GL' => 18,
			'GR' => 27,
			'GT' => 28,
			'HR' => 21,
			'HU' => 28,
			'IE' => 22,
			'IL' => 23,
			'IQ' => 23,
			'IS' => 26,
			'IT' => 27,
			'JO' => 30,
			'KW' => 30,
			'KZ' => 20,
			'LB' => 28,
			'LC' => 32,
			'LI' => 21,
			'LT' => 20,
			'LU' => 20,
			'LV' => 21,
			'MC' => 27,
			'MD' => 24,
			'ME' => 22,
			'MK' => 19,
			'MR' => 27,
			'MT' => 31,
			'MU' => 30,
			'NL' => 18,
			'NO' => 15,
			'OM' => 23,
			'PK' => 24,
			'PL' => 28,
			'PS' => 29,
			'PT' => 25,
			'QA' => 29,
			'RO' => 24,
			'RS' => 22,
			'SA' => 24,
			'SC' => 31,
			'SE' => 24,
			'SI' => 19,
			'SK' => 24,
			'SM' => 27,
			'ST' => 25,
			'SV' => 28,
			'TL' => 23,
			'TN' => 24,
			'TR' => 26,
			'UA' => 29,
			'VA' => 22,
			'VG' => 24,
			'XK' => 20,
		);
	}

	/**
	 * Validate IBAN MOD-97 checksum.
	 *
	 * @param string $iban
	 * @return bool
	 */
	protected function isValidIbanChecksum($iban)
	{
		$reordered = substr((string) $iban, 4) . substr((string) $iban, 0, 4);
		$remainder = 0;
		$length = strlen($reordered);

		for ($i = 0; $i < $length; $i++) {
			$char = $reordered[$i];
			$ord = ord($char);
			if ($ord >= 48 && $ord <= 57) {
				$remainder = (($remainder * 10) + ($ord - 48)) % 97;
				continue;
			}
			if ($ord < 65 || $ord > 90) {
				return false;
			}

			$expanded = (string) ($ord - 55);
			$expandedLength = strlen($expanded);
			for ($j = 0; $j < $expandedLength; $j++) {
				$digitOrd = ord($expanded[$j]);
				$remainder = (($remainder * 10) + ($digitOrd - 48)) % 97;
			}
		}

		return ($remainder === 1);
	}

	/**
	 * Lightweight similarity score between 0 and 100.
	 *
	 * @param string $a
	 * @param string $b
	 * @return float
	 */
	protected function textSimilarity($a, $b)
	{
		if ($a === '' || $b === '') {
			return 0;
		}

		$lengthA = function_exists('mb_strlen') ? (int) mb_strlen($a, 'UTF-8') : (int) strlen($a);
		$lengthB = function_exists('mb_strlen') ? (int) mb_strlen($b, 'UTF-8') : (int) strlen($b);
		if ($lengthA <= 0 || $lengthB <= 0) {
			return 0;
		}

		$shorterLength = min($lengthA, $lengthB);
		$longerLength = max($lengthA, $lengthB);
		if ($shorterLength <= 0 || ((float) $longerLength / (float) $shorterLength) > 3.0) {
			return 0;
		}

		$maxSimilarityLength = 50;
		if ($lengthA > $maxSimilarityLength) {
			$a = function_exists('mb_substr') ? (string) mb_substr($a, 0, $maxSimilarityLength, 'UTF-8') : (string) substr($a, 0, $maxSimilarityLength);
		}
		if ($lengthB > $maxSimilarityLength) {
			$b = function_exists('mb_substr') ? (string) mb_substr($b, 0, $maxSimilarityLength, 'UTF-8') : (string) substr($b, 0, $maxSimilarityLength);
		}

		$percent = 0.0;
		similar_text($a, $b, $percent);

		return $percent;
	}

	/**
	 * Check a strong reference match between statement payment reference and document ref.
	 * Accepts suffixes appended by banks (example: -E7357655).
	 *
	 * @param string $linePaymentReference
	 * @param string $documentRef
	 * @return bool
	 */
	protected function hasStrongPaymentReferenceMatch($linePaymentReference, $documentRef)
	{
		$lineCore = $this->normalizeReferenceCore($linePaymentReference);
		$docCore = $this->normalizeReferenceCore($documentRef);
		$minStrongReferenceLength = (int) self::STRONG_REFERENCE_MIN_LENGTH;
		if ($lineCore === '' || $docCore === '') {
			return false;
		}
		if (strlen($docCore) < $minStrongReferenceLength || strlen($lineCore) < $minStrongReferenceLength) {
			return false;
		}
		if ($lineCore === $docCore) {
			return true;
		}

		return false;
	}

	/**
	 * Normalize references into a comparable core token.
	 *
	 * @param string $value
	 * @return string
	 */
	protected function normalizeReferenceCore($value)
	{
		$value = strtoupper(trim((string) $value));
		if ($value === '') {
			return '';
		}

		$value = preg_replace('/\s+/', '', $value);
		$value = preg_replace('/[^A-Z0-9\-\/]/', '', (string) $value);
		// Common bank suffixes: ...-E1234567, .../E1234567
		$value = preg_replace('/(?:[\-\/]?E[0-9]{4,})$/', '', (string) $value);
		$value = preg_replace('/[^A-Z0-9]/', '', (string) $value);

		return (string) $value;
	}

	/**
	 * Return normalized references extracted from one raw string.
	 *
	 * @param string $rawRefs
	 * @return array<int,string>
	 */
	protected function extractNormalizedReferences($rawRefs)
	{
		$rawRefs = trim((string) $rawRefs);
		if ($rawRefs === '') {
			return array();
		}

		$parts = preg_split('/[,;|]+/', $rawRefs);
		if (!is_array($parts) || empty($parts)) {
			$parts = array($rawRefs);
		}

		$refs = array();
		foreach ($parts as $part) {
			$ref = kreabankNormalizeText((string) $part);
			if ($ref === '') {
				continue;
			}
			$refs[$ref] = $ref;
		}

		return array_values($refs);
	}

	/**
	 * Check if one normalized reference matches the normalized line text.
	 *
	 * @param string $lineRefText
	 * @param array<int,string> $references
	 * @return bool
	 */
	protected function hasOneReferenceMatch($lineRefText, $references)
	{
		if ($lineRefText === '' || empty($references)) {
			return false;
		}

		foreach ($references as $reference) {
			if ($reference !== '' && strpos($lineRefText, (string) $reference) !== false) {
				return true;
			}
		}

		return false;
	}
}
