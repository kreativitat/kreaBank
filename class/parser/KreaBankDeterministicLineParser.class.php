<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * Deterministic statement parser working on decoded text lines.
 */
class KreaBankDeterministicLineParser
{
	/**
	 * @var array<int,string>
	 */
	protected $supportedFormats = array(
		'norma43',
		'norma43_r24',
		'norma43_978',
		'nib_compound',
		'consolidated',
		'bit_recon',
		'emc',
		'mt940',
		'tabulated',
		'tabulated_excel',
		'csv',
	);

	/**
	 * Check whether a format is supported by this deterministic parser.
	 *
	 * @param string $format
	 * @return bool
	 */
	public function supports_format($format)
	{
		return in_array(strtolower(trim((string) $format)), $this->supportedFormats, true);
	}

	/**
	 * Detect statement format from decoded lines.
	 *
	 * @param array<int,string> $lines
	 * @return string
	 */
	public function detect_format($lines)
	{
		$lines = $this->normalizeLines($lines);
		if (empty($lines)) {
			return 'csv';
		}

		if ($this->looksLikeMt940($lines)) {
			return 'mt940';
		}

		$norma43 = $this->detectNorma43Variant($lines);
		if ($norma43 !== '') {
			return $norma43;
		}

		if ($this->looksLikeBitRecon($lines)) {
			return 'bit_recon';
		}

		if ($this->looksLikeConsolidated($lines)) {
			return 'consolidated';
		}

		if ($this->looksLikeNibCompound($lines)) {
			return 'nib_compound';
		}

		if ($this->looksLikeEmc($lines)) {
			return 'emc';
		}

		if ($this->looksLikeTabulatedExcel($lines)) {
			return 'tabulated_excel';
		}

		if ($this->looksLikeTabulatedTsv($lines)) {
			return 'tabulated';
		}

		return 'csv';
	}

	/**
	 * Parse decoded lines by known format into unified transaction schema.
	 *
	 * @param array<int,string> $lines
	 * @param string $format
	 * @param string $defaultCurrency
	 * @return array<int,array<string,mixed>>
	 */
	public function parse($lines, $format, $defaultCurrency = 'EUR')
	{
		$lines = $this->normalizeLines($lines);
		$format = strtolower(trim((string) $format));
		if ($format === 'csv') {
			$format = $this->looksLikeTabulatedExcel($lines) ? 'tabulated_excel' : 'tabulated';
		}

		switch ($format) {
			case 'norma43':
			case 'norma43_r24':
			case 'norma43_978':
				return $this->parseNorma43($lines, $format, (string) $defaultCurrency);
			case 'nib_compound':
				return $this->parseNibCompound($lines, $format, (string) $defaultCurrency);
			case 'consolidated':
				return $this->parseConsolidated($lines, $format, (string) $defaultCurrency);
			case 'bit_recon':
				return $this->parseBitRecon($lines, $format, (string) $defaultCurrency);
			case 'emc':
				return $this->parseEmc($lines, $format, (string) $defaultCurrency);
			case 'mt940':
				return $this->parseMt940($lines, $format, (string) $defaultCurrency);
			case 'tabulated_excel':
				$delimiter = $this->detectBestDelimitedSeparator($lines, array(';', ','));
				return $this->parseDelimitedTabular($lines, $delimiter, 'tabulated_excel', (string) $defaultCurrency);
			case 'tabulated':
				return $this->parseDelimitedTabular($lines, "\t", 'tabulated', (string) $defaultCurrency);
			default:
				return array();
		}
	}

	/**
	 * @param array<int,string> $lines
	 * @return bool
	 */
	protected function looksLikeMt940($lines)
	{
		$tagHits = 0;
		$has61 = false;
		foreach (array_slice($lines, 0, 200) as $line) {
			$line = trim((string) $line);
			if ($line === '') {
				continue;
			}
			if (strpos($line, ':') === 0 && preg_match('/^:(20|25|28|60[FM]?|61|86):/', $line)) {
				$tagHits++;
			}
			if (strpos($line, ':61:') === 0) {
				$has61 = true;
			}
		}

		return ($has61 && $tagHits >= 3);
	}

	/**
	 * @param array<int,string> $lines
	 * @return string
	 */
	protected function detectNorma43Variant($lines)
	{
		$recordHits = 0;
		$total = 0;
		$has24 = false;
		$has978 = false;
		foreach (array_slice($lines, 0, 220) as $line) {
			$line = rtrim((string) $line);
			if ($line === '') {
				continue;
			}
			$total++;
			$type = substr($line, 0, 2);
			if (in_array($type, array('11', '22', '23', '24', '33', '88'), true)) {
				$recordHits++;
				if ($type === '24') {
					$has24 = true;
				}
				if ($type === '11' && preg_match('/978\s*[0-9]*\s*$/', $line)) {
					$has978 = true;
				}
			}
		}

		if ($recordHits >= 3 && $recordHits >= (int) floor(max(3, $total * 0.55))) {
			if ($has24) {
				return 'norma43_r24';
			}
			if ($has978) {
				return 'norma43_978';
			}
			return 'norma43';
		}

		return '';
	}

	/**
	 * @param array<int,string> $lines
	 * @return bool
	 */
	protected function looksLikeTabulatedExcel($lines)
	{
		$best = $this->scoreDelimitedLayout($lines, array(';', ','));
		return ($best['rows'] >= 3 && $best['stable'] >= 2 && $best['date_hits'] >= 3 && $best['columns'] >= 8);
	}

	/**
	 * @param array<int,string> $lines
	 * @return bool
	 */
	protected function looksLikeTabulatedTsv($lines)
	{
		$best = $this->scoreDelimitedLayout($lines, array("\t"));
		return ($best['rows'] >= 3 && $best['stable'] >= 2 && $best['date_hits'] >= 3 && $best['columns'] >= 8);
	}

	/**
	 * @param array<int,string> $lines
	 * @return bool
	 */
	protected function looksLikeBitRecon($lines)
	{
		$hits = 0;
		foreach (array_slice($lines, 0, 140) as $line) {
			$line = rtrim((string) $line);
			if ($line === '') {
				continue;
			}
			if (preg_match('/[CD]\d{4}[A-Z]{3}$/', $line) && preg_match('/^\d{11}\s{2}20\d{6}/', $line)) {
				$hits++;
				if ($hits >= 2) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * @param array<int,string> $lines
	 * @return bool
	 */
	protected function looksLikeConsolidated($lines)
	{
		$has01 = false;
		$hits03 = 0;
		foreach (array_slice($lines, 0, 160) as $line) {
			$line = rtrim((string) $line);
			if ($line === '') {
				continue;
			}
			if (strpos($line, '01') === 0) {
				$has01 = true;
			}
			if (strpos($line, '03') === 0 && strpos($line, 'EUR') !== false && preg_match('/[+-]\d{18}/', $line)) {
				$hits03++;
			}
		}
		return ($has01 && $hits03 >= 2);
	}

	/**
	 * @param array<int,string> $lines
	 * @return bool
	 */
	protected function looksLikeNibCompound($lines)
	{
		$hits = 0;
		foreach (array_slice($lines, 0, 180) as $line) {
			$line = rtrim((string) $line);
			if (preg_match('/^5\d{6}\d{6}/', $line)) {
				$hits++;
				if ($hits >= 2) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * @param array<int,string> $lines
	 * @return bool
	 */
	protected function looksLikeEmc($lines)
	{
		$prefixHits = 0;
		$lengths = array();
		$denseNumeric = 0;
		foreach (array_slice($lines, 0, 160) as $line) {
			$line = rtrim((string) $line);
			if ($line === '') {
				continue;
			}
			$length = strlen($line);
			$lengths[$length] = isset($lengths[$length]) ? ((int) $lengths[$length] + 1) : 1;
			if (strpos($line, 'EMC13') === 0 || strpos($line, 'EMC23') === 0) {
				$prefixHits++;
			}
			if (preg_match_all('/\d/', $line, $m) && strlen($line) > 0) {
				if ((count($m[0]) / strlen($line)) > 0.55) {
					$denseNumeric++;
				}
			}
		}

		$stableLength = false;
		foreach ($lengths as $count) {
			if ((int) $count >= 3) {
				$stableLength = true;
				break;
			}
		}

		return ($prefixHits >= 2 || ($stableLength && $denseNumeric >= 3 && $this->containsToken($lines, 'EUR')));
	}

	/**
	 * Parse Norma43 family (22/23/24 records).
	 *
	 * @param array<int,string> $lines
	 * @param string $format
	 * @param string $defaultCurrency
	 * @return array<int,array<string,mixed>>
	 */
	protected function parseNorma43($lines, $format, $defaultCurrency)
	{
		$transactions = array();
		$current = null;
		$currentCurrency = $this->normalizeCurrency($defaultCurrency);

		$finalize = function () use (&$current, &$transactions, $format, &$currentCurrency) {
			if (!is_array($current)) {
				return;
			}

			$bookingDate = isset($current['booking_date']) ? (string) $current['booking_date'] : '';
			$amount = isset($current['amount']) ? (float) $current['amount'] : null;
			if ($bookingDate === '' || $amount === null) {
				$current = null;
				return;
			}

			$parts = array();
			if (!empty($current['concept'])) {
				$parts[] = (string) $current['concept'];
			}
			if (!empty($current['continuations']) && is_array($current['continuations'])) {
				foreach ($current['continuations'] as $segment) {
					$segment = trim((string) $segment);
					if ($segment !== '') {
						$parts[] = $segment;
					}
				}
			}
			$description = $this->normalizeText(implode(' ', $parts), true);

			$reference = $this->normalizeReference(isset($current['reference']) ? $current['reference'] : null);
			if ($reference === null) {
				$reference = $this->extractStrongReference($description);
			}

			$iban = $this->extractIbanFromText($description);
			if ($iban === null && !empty($current['iban'])) {
				$iban = $this->normalizeIban((string) $current['iban']);
			}

			$currency = !empty($current['currency']) ? (string) $current['currency'] : $currentCurrency;
			$currency = $this->normalizeCurrency($currency);

			$rawLines = !empty($current['raw_lines']) && is_array($current['raw_lines']) ? $current['raw_lines'] : array();
			$rawFields = !empty($current['raw_fields']) && is_array($current['raw_fields']) ? $current['raw_fields'] : array();

			$transactions[] = $this->buildTransaction(
				$format,
				$bookingDate,
				!empty($current['value_date']) ? (string) $current['value_date'] : null,
				$amount,
				$currency,
				$description,
				$reference,
				null,
				$iban,
				null,
				$rawLines,
				$rawFields
			);

			$current = null;
		};

		foreach ($lines as $line) {
			$line = rtrim((string) $line);
			if ($line === '') {
				continue;
			}
			$type = substr($line, 0, 2);

			if ($type === '11') {
				if (preg_match('/(978|[A-Z]{3})\s*[0-9]*\s*$/', $line, $m)) {
					$currentCurrency = $this->normalizeCurrency((string) $m[1], $currentCurrency);
				}
				continue;
			}

			if ($type === '22') {
				$finalize();

				$bookingDate = $this->parseYYMMDD(substr($line, 10, 6));
				$valueDate = $this->parseYYMMDD(substr($line, 16, 6));
				$signToken = substr($line, 27, 1);
				$amountDigits = preg_replace('/\D+/', '', (string) substr($line, 28, 14));
				if ($bookingDate === null || $amountDigits === '' || !preg_match('/^\d+$/', $amountDigits)) {
					$current = null;
					continue;
				}
				$amount = $this->parseImpliedCentsSigned($amountDigits, $signToken);
				if ($amount === null) {
					$current = null;
					continue;
				}

				$referenceRaw = trim((string) substr($line, 42, 10));
				$concept = $this->normalizeText((string) substr($line, 52), true);
				$current = array(
					'booking_date' => $bookingDate,
					'value_date' => ($valueDate !== null ? $valueDate : $bookingDate),
					'amount' => $amount,
					'currency' => $currentCurrency,
					'reference' => $this->normalizeReference($referenceRaw),
					'concept' => $concept,
					'continuations' => array(),
					'iban' => null,
					'raw_lines' => array($line),
					'raw_fields' => array(
						'rec_type' => '22',
						'sign_token' => $signToken,
						'amount_digits' => $amountDigits,
						'concept_code' => trim((string) substr($line, 24, 3)),
						'reference_raw' => $referenceRaw,
					),
				);
				continue;
			}

			if ($type === '23' && is_array($current)) {
				$segment = $this->normalizeText((string) substr($line, 4), true);
				if ($segment !== '') {
					$current['continuations'][] = $segment;
					$iban = $this->extractIbanFromText($segment);
					if ($iban !== null) {
						$current['iban'] = $iban;
					}
				}
				$current['raw_lines'][] = $line;
				continue;
			}

			if ($type === '24' && is_array($current)) {
				$current['raw_lines'][] = $line;
				$currencyToken = trim((string) substr($line, 4, 3));
				if ($currencyToken !== '') {
					$current['currency'] = $this->normalizeCurrency($currencyToken, (string) $current['currency']);
				}
				$current['raw_fields']['record24'] = array(
					'currency_raw' => $currencyToken,
					'line' => $line,
				);
				continue;
			}

			if (($type === '33' || $type === '88') && is_array($current)) {
				$finalize();
			}
		}

		$finalize();
		return $transactions;
	}

	/**
	 * Parse NIB compound rows.
	 *
	 * @param array<int,string> $lines
	 * @param string $format
	 * @param string $defaultCurrency
	 * @return array<int,array<string,mixed>>
	 */
	protected function parseNibCompound($lines, $format, $defaultCurrency)
	{
		$transactions = array();
		$currency = $this->normalizeCurrency($defaultCurrency);

		foreach ($lines as $line) {
			$line = rtrim((string) $line);
			if (!preg_match('/^5\d{6}\d{6}/', $line)) {
				continue;
			}

			$bookingDate = $this->parseYYMMDD(substr($line, 1, 6));
			$valueDate = $this->parseYYMMDD(substr($line, 7, 6));
			if ($bookingDate === null) {
				continue;
			}
			if ($valueDate === null) {
				$valueDate = $bookingDate;
			}

			$opCode = trim((string) substr($line, 43, 3));
			$searchTail = (strlen($line) > 46) ? substr($line, 46) : '';
			$plusPos = strrpos($searchTail, '+');
			$minusPos = strrpos($searchTail, '-');
			$tailPos = max(($plusPos === false ? -1 : (int) $plusPos), ($minusPos === false ? -1 : (int) $minusPos));
			if ($tailPos < 0) {
				continue;
			}
			$signPos = 46 + $tailPos;
			$sign = (string) $line[$signPos];
			$amountRaw = trim((string) substr($line, $signPos + 1));
			$amount = $this->parseDecimal($amountRaw);
			if ($amount === null) {
				continue;
			}
			$amount = abs((float) $amount);
			$amount = ($sign === '-') ? (-1.0 * $amount) : $amount;
			$amount = round($amount, 2);

			$description = $this->normalizeText((string) substr($line, 13, 30), true);
			if ($description === '') {
				$description = $this->normalizeText((string) substr($line, 13, max(0, $signPos - 13)), true);
			}

			$transactions[] = $this->buildTransaction(
				$format,
				$bookingDate,
				$valueDate,
				$amount,
				$currency,
				$description,
				$this->extractStrongReference($description),
				null,
				null,
				null,
				array($line),
				array(
					'op_code' => $opCode,
					'sign' => $sign,
					'amount_raw' => $amountRaw,
				)
			);
		}

		return $transactions;
	}

	/**
	 * Parse consolidated fixed-width rows.
	 *
	 * @param array<int,string> $lines
	 * @param string $format
	 * @param string $defaultCurrency
	 * @return array<int,array<string,mixed>>
	 */
	protected function parseConsolidated($lines, $format, $defaultCurrency)
	{
		$transactions = array();
		$currencyDefault = $this->normalizeCurrency($defaultCurrency);

		foreach ($lines as $line) {
			$line = rtrim((string) $line);
			if ($line === '' || strpos($line, '03') !== 0) {
				continue;
			}

			preg_match_all('/\s{2}(20\d{6})/', $line, $dates, PREG_OFFSET_CAPTURE);
			$dateHits = isset($dates[1]) && is_array($dates[1]) ? $dates[1] : array();
			$bookingDate = null;
			$valueDate = null;
			$bookingOffset = null;
			$valueOffset = null;
			foreach ($dateHits as $hit) {
				$token = (string) $hit[0];
				$offset = (int) $hit[1];
				if ($offset <= 10) {
					continue;
				}
				if ($bookingDate === null) {
					$bookingDate = $this->parseYYYYMMDD($token);
					$bookingOffset = $offset;
					continue;
				}
				$valueDate = $this->parseYYYYMMDD($token);
				$valueOffset = $offset;
				break;
			}
			if ($bookingDate === null) {
				continue;
			}

			preg_match_all('/[+-]\d{18}/', $line, $blocks, PREG_OFFSET_CAPTURE);
			$amountBlocks = isset($blocks[0]) && is_array($blocks[0]) ? $blocks[0] : array();
			if (empty($amountBlocks)) {
				continue;
			}

			$movementBlock = null;
			$runningBlock = null;
			$count = count($amountBlocks);
			if ($count === 1) {
				$movementBlock = $amountBlocks[0];
			} elseif ($count === 2) {
				$movementBlock = $amountBlocks[1];
			} else {
				$movementBlock = $amountBlocks[$count - 2];
				$runningBlock = $amountBlocks[$count - 1];
			}

			$amount = $this->parseSignedImpliedCentsBlock((string) $movementBlock[0]);
			if ($amount === null) {
				continue;
			}
			$runningBalance = ($runningBlock !== null)
				? $this->parseSignedImpliedCentsBlock((string) $runningBlock[0])
				: null;

			$firstAmountOffset = (int) $amountBlocks[0][1];
			$descriptionStart = ($bookingOffset !== null) ? ((int) $bookingOffset + 8) : 0;
			if ($valueOffset !== null && (int) $valueOffset < $firstAmountOffset) {
				$descriptionStart = (int) $valueOffset + 8;
			}
			if ($descriptionStart < 0) {
				$descriptionStart = 0;
			}
			$descriptionLen = max(0, $firstAmountOffset - $descriptionStart);
			$description = $descriptionLen > 0 ? substr($line, $descriptionStart, $descriptionLen) : '';
			$description = preg_replace('/\s+0{8}\s*$/', '', (string) $description);
			$description = $this->normalizeText((string) $description, true);

			$currency = $currencyDefault;
			$prefix = substr($line, 0, $firstAmountOffset);
			if (preg_match('/\b([A-Z]{3})\b/', (string) $prefix, $currencyMatch)) {
				$currency = $this->normalizeCurrency((string) $currencyMatch[1], $currencyDefault);
			}

			$transactions[] = $this->buildTransaction(
				$format,
				$bookingDate,
				($valueDate !== null ? $valueDate : null),
				$amount,
				$currency,
				$description,
				$this->extractStrongReference($description),
				null,
				null,
				$runningBalance,
				array($line),
				array(
					'amount_blocks' => array_map(function ($item) {
						return (string) $item[0];
					}, $amountBlocks),
				)
			);
		}

		return $transactions;
	}

	/**
	 * Parse BIT reconciliation fixed-width rows.
	 *
	 * @param array<int,string> $lines
	 * @param string $format
	 * @param string $defaultCurrency
	 * @return array<int,array<string,mixed>>
	 */
	protected function parseBitRecon($lines, $format, $defaultCurrency)
	{
		$transactions = array();
		$currencyDefault = $this->normalizeCurrency($defaultCurrency);

		foreach ($lines as $line) {
			$line = rtrim((string) $line);
			if ($line === '') {
				continue;
			}

			$match = array();
			if (!preg_match('/^(\d{11})\s{2}(20\d{6})\d{6}(.{23})([CD])\s{2}(20\d{6})\d{6}(\d{15,18})([CD])(\d{15,18})([CD])(\d{4})([A-Z]{3})$/', $line, $match)) {
				if (!preg_match('/(\d{15,18})([CD])(\d{15,18})([CD])(\d{4})([A-Z]{3})$/', $line, $tailMatch, PREG_OFFSET_CAPTURE)) {
					continue;
				}
				$tailOffset = (int) $tailMatch[0][1];
				$prefix = substr($line, 0, $tailOffset);
				preg_match_all('/20\d{6}/', (string) $prefix, $dates, PREG_OFFSET_CAPTURE);
				if (empty($dates[0])) {
					continue;
				}

				$bookingDate = $this->parseYYYYMMDD((string) $dates[0][0][0]);
				$valueDate = (isset($dates[0][1][0])) ? $this->parseYYYYMMDD((string) $dates[0][1][0]) : null;
				if ($bookingDate === null) {
					continue;
				}

				$descStart = (int) $dates[0][0][1] + 14;
				$descEnd = isset($dates[0][1][1]) ? (int) $dates[0][1][1] : $tailOffset;
				$description = substr($prefix, max(0, $descStart), max(0, $descEnd - $descStart));
				$description = preg_replace('/[CD]$/', '', (string) $description);
				$description = $this->normalizeText((string) $description, true);

				$amount = $this->parseUnsignedImpliedCents((string) $tailMatch[1][0], (string) $tailMatch[2][0]);
				$running = $this->parseUnsignedImpliedCents((string) $tailMatch[3][0], (string) $tailMatch[4][0]);
				if ($amount === null) {
					continue;
				}

				$currency = $this->normalizeCurrency((string) $tailMatch[6][0], $currencyDefault);
				$transactions[] = $this->buildTransaction(
					$format,
					$bookingDate,
					$valueDate,
					$amount,
					$currency,
					$description,
					$this->extractStrongReference($description),
					null,
					null,
					$running,
					array($line),
					array('entity' => (string) $tailMatch[5][0])
				);
				continue;
			}

			$bookingDate = $this->parseYYYYMMDD((string) $match[2]);
			$valueDate = $this->parseYYYYMMDD((string) $match[5]);
			if ($bookingDate === null) {
				continue;
			}
			$amount = $this->parseUnsignedImpliedCents((string) $match[6], (string) $match[7]);
			$runningBalance = $this->parseUnsignedImpliedCents((string) $match[8], (string) $match[9]);
			if ($amount === null) {
				continue;
			}
			$description = $this->normalizeText((string) $match[3], true);
			$currency = $this->normalizeCurrency((string) $match[11], $currencyDefault);

			$transactions[] = $this->buildTransaction(
				$format,
				$bookingDate,
				$valueDate,
				$amount,
				$currency,
				$description,
				$this->extractStrongReference($description),
				null,
				null,
				$runningBalance,
				array($line),
				array('entity' => (string) $match[10])
			);
		}

		return $transactions;
	}

	/**
	 * Parse EMC fixed-width rows.
	 *
	 * @param array<int,string> $lines
	 * @param string $format
	 * @param string $defaultCurrency
	 * @return array<int,array<string,mixed>>
	 */
	protected function parseEmc($lines, $format, $defaultCurrency)
	{
		$transactions = array();
		$currency = $this->normalizeCurrency($defaultCurrency);

		foreach ($lines as $line) {
			$line = rtrim((string) $line);
			if ($line === '') {
				continue;
			}

			if (strpos($line, 'EMC13') === 0) {
				if (preg_match('/([A-Z]{3})\d{6}\d{6}/', $line, $m)) {
					$currency = $this->normalizeCurrency((string) $m[1], $currency);
				}
				continue;
			}
			if (strpos($line, 'EMC23') !== 0) {
				continue;
			}

			if (strlen($line) < 80) {
				continue;
			}

			$match = array();
			if (!preg_match('/^EMC23\d{16}(20\d{6})(20\d{6})\d{8}(.+)([CD]{2})(\d{16})([CD ])(\d{16})(?:\s+\d+)?\s*$/', $line, $match)) {
				continue;
			}

			$bookingDate = $this->parseYYYYMMDD((string) $match[1]);
			$valueDate = $this->parseYYYYMMDD((string) $match[2]);
			if ($bookingDate === null) {
				continue;
			}

			$body = (string) $match[3];
			$opCode = trim((string) substr($body, 0, 3));
			$description = $this->normalizeText((string) substr($body, 3), true);
			$signPair = strtoupper((string) $match[4]);
			$amountDigits = trim((string) $match[5]);
			$balanceSign = strtoupper((string) $match[6]);
			$balanceDigits = trim((string) $match[7]);
			if (!preg_match('/^[CD]{2}$/', $signPair) || !preg_match('/^\d{16}$/', $amountDigits) || !preg_match('/^[CD ]$/', $balanceSign) || !preg_match('/^\d{16}$/', $balanceDigits)) {
				continue;
			}
			$signToken = (strpos($signPair, 'D') !== false) ? 'D' : 'C';
			$amount = $this->parseUnsignedImpliedCents((string) $amountDigits, $signToken);
			if ($amount === null) {
				continue;
			}
			$runningBalance = $this->parseUnsignedImpliedCents((string) $balanceDigits, (string) $balanceSign);
			$reference = $this->extractStrongReference($description);

			$transactions[] = $this->buildTransaction(
				$format,
				$bookingDate,
				$valueDate,
				$amount,
				$currency,
				$description,
				$reference,
				null,
				null,
				$runningBalance,
				array($line),
				array(
					'op_code' => $opCode,
					'sign_pair' => $signPair,
				)
			);
		}

		return $transactions;
	}

	/**
	 * Parse MT940 tokens as state machine (:61: + :86:).
	 *
	 * @param array<int,string> $lines
	 * @param string $format
	 * @param string $defaultCurrency
	 * @return array<int,array<string,mixed>>
	 */
	protected function parseMt940($lines, $format, $defaultCurrency)
	{
		$tokens = $this->tokenizeMt940($lines);
		$currency = $this->normalizeCurrency($defaultCurrency);
		foreach ($tokens as $token) {
			if (($token['tag'] === '60F' || $token['tag'] === '60M') && preg_match('/^[CD]\d{6}([A-Z]{3})/', (string) $token['value'], $m)) {
				$currency = $this->normalizeCurrency((string) $m[1], $currency);
				break;
			}
		}

		$transactions = array();
		$current = null;

		$finalize = function () use (&$current, &$transactions, $format, $currency) {
			if (!is_array($current)) {
				return;
			}
			if (empty($current['booking_date']) || !isset($current['amount'])) {
				$current = null;
				return;
			}

			$descParts = !empty($current['description_parts']) && is_array($current['description_parts'])
				? $current['description_parts']
				: array();
			$description = $this->normalizeText(implode(' ', $descParts), true);
			if ($description === '' && !empty($current['reference'])) {
				$description = (string) $current['reference'];
			}

			$transactions[] = $this->buildTransaction(
				$format,
				(string) $current['booking_date'],
				(isset($current['value_date']) ? $current['value_date'] : null),
				(float) $current['amount'],
				(!empty($current['currency']) ? (string) $current['currency'] : $currency),
				$description,
				(isset($current['reference']) ? $current['reference'] : null),
				null,
				null,
				null,
				(isset($current['raw_lines']) ? (array) $current['raw_lines'] : array()),
				(isset($current['raw_fields']) ? (array) $current['raw_fields'] : array())
			);
			$current = null;
		};

		foreach ($tokens as $token) {
			$tag = (string) $token['tag'];
			$value = (string) $token['value'];

			if ($tag === '61') {
				$finalize();
				$parsed61 = $this->parseMt940Field61($value);
				if ($parsed61 === null) {
					$current = null;
					continue;
				}
				$current = array(
					'booking_date' => $parsed61['booking_date'],
					'value_date' => $parsed61['value_date'],
					'amount' => $parsed61['amount'],
					'currency' => $currency,
					'reference' => $parsed61['reference'],
					'description_parts' => array(),
					'raw_lines' => (array) $token['lines'],
					'raw_fields' => (array) $parsed61['raw_fields'],
				);
				continue;
			}

			if ($tag === '86' && is_array($current)) {
				$current['description_parts'][] = (string) $value;
				$current['raw_lines'] = array_merge((array) $current['raw_lines'], (array) $token['lines']);
				continue;
			}
		}

		$finalize();
		return $transactions;
	}

	/**
	 * Parse delimited tabular rows using fixed column indices.
	 *
	 * @param array<int,string> $lines
	 * @param string $delimiter
	 * @param string $format
	 * @param string $defaultCurrency
	 * @return array<int,array<string,mixed>>
	 */
	protected function parseDelimitedTabular($lines, $delimiter, $format, $defaultCurrency)
	{
		$transactions = array();
		$currency = $this->normalizeCurrency($defaultCurrency);
		foreach ($lines as $line) {
			$rawLine = rtrim((string) $line);
			if ($rawLine === '') {
				continue;
			}

			$row = str_getcsv($rawLine, $delimiter, '"', '\\');
			if (!is_array($row) || count($row) < 8) {
				continue;
			}

			$bookingDate = $this->parseDateFlexible(isset($row[1]) ? (string) $row[1] : '');
			$valueDate = $this->parseDateFlexible(isset($row[2]) ? (string) $row[2] : '');
			if ($bookingDate === null) {
				continue;
			}
			if ($valueDate === null) {
				$valueDate = $bookingDate;
			}

			$debitRaw = isset($row[5]) ? (string) $row[5] : '';
			$creditRaw = isset($row[6]) ? (string) $row[6] : '';
			$debit = $this->parseDecimal($debitRaw);
			$credit = $this->parseDecimal($creditRaw);
			$hasDebit = (trim($debitRaw) !== '' && $debit !== null);
			$hasCredit = (trim($creditRaw) !== '' && $credit !== null);
			$amount = null;
			if ($hasDebit) {
				$amount = -abs((float) $debit);
			} elseif ($hasCredit) {
				$amount = abs((float) $credit);
			} elseif (($debit !== null && abs((float) $debit) < 0.0000001) || ($credit !== null && abs((float) $credit) < 0.0000001)) {
				$amount = 0.0;
			}
			if ($amount === null) {
				continue;
			}

			$runningBalance = null;
			if (isset($row[7]) && trim((string) $row[7]) !== '') {
				$runningBalance = $this->parseDecimal((string) $row[7]);
			}
			$description = $this->normalizeText(isset($row[3]) ? (string) $row[3] : '', true);
			$reference = $this->normalizeReference(isset($row[4]) ? (string) $row[4] : null);
			if ($reference === null) {
				$reference = $this->extractStrongReference($description);
			}

			$transactions[] = $this->buildTransaction(
				$format,
				$bookingDate,
				$valueDate,
				$amount,
				$currency,
				$description,
				$reference,
				null,
				null,
				($runningBalance !== null ? (float) $runningBalance : null),
				array($rawLine),
				array(
					'columns' => $row,
				)
			);
		}

		return $transactions;
	}

	/**
	 * @param array<int,string> $lines
	 * @return array<int,array<string,mixed>>
	 */
	protected function tokenizeMt940($lines)
	{
		$tokens = array();
		$currentIndex = -1;
		foreach ($lines as $line) {
			$line = rtrim((string) $line);
			if ($line === '') {
				continue;
			}
			if (preg_match('/^:([0-9]{2}[A-Z]?):(.*)$/', $line, $m)) {
				$tokens[] = array(
					'tag' => (string) $m[1],
					'value' => (string) $m[2],
					'lines' => array($line),
				);
				$currentIndex = count($tokens) - 1;
				continue;
			}
			if ($currentIndex >= 0 && isset($tokens[$currentIndex])) {
				$tokens[$currentIndex]['value'] .= '\n' . $line;
				$tokens[$currentIndex]['lines'][] = $line;
			}
		}
		return $tokens;
	}

	/**
	 * @param string $value
	 * @return array<string,mixed>|null
	 */
	protected function parseMt940Field61($value)
	{
		$value = trim((string) $value);
		if ($value === '') {
			return null;
		}

		if (!preg_match('/^(\d{6})(\d{4})?(R?[DC])([A-Z])?([0-9,\.]+)(.*)$/', $value, $m)) {
			return null;
		}

		$bookingDate = $this->parseYYMMDD((string) $m[1]);
		if ($bookingDate === null) {
			return null;
		}

		$entryDateToken = isset($m[2]) ? (string) $m[2] : '';
		$valueDate = $this->resolveMt940EntryDate($bookingDate, $entryDateToken);
		$dcMark = strtoupper((string) $m[3]);
		$amount = $this->parseDecimal((string) $m[5]);
		if ($amount === null) {
			return null;
		}
		$amount = abs((float) $amount);
		if ($dcMark === 'D' || $dcMark === 'RC') {
			$amount *= -1.0;
		}
		if ($dcMark === 'RD') {
			$amount = abs($amount);
		}

		$rest = trim((string) $m[6]);
		$txnCode = null;
		if (preg_match('/^([A-Z][A-Z0-9]{3})(.*)$/', $rest, $codeMatch)) {
			$txnCode = (string) $codeMatch[1];
			$rest = trim((string) $codeMatch[2]);
		}

		$customerRefRaw = $rest;
		$bankRefRaw = '';
		if (strpos($rest, '//') !== false) {
			$parts = explode('//', $rest, 2);
			$customerRefRaw = trim((string) $parts[0]);
			$bankRefRaw = trim((string) $parts[1]);
		}

		$customerRef = $this->normalizeReference($customerRefRaw);
		$bankRef = $this->normalizeReference($bankRefRaw);
		$reference = ($customerRef !== null) ? $customerRef : $bankRef;

		return array(
			'booking_date' => $bookingDate,
			'value_date' => $valueDate,
			'amount' => round((float) $amount, 2),
			'reference' => $reference,
			'raw_fields' => array(
				'value_date_token' => (string) $m[1],
				'entry_date_token' => $entryDateToken,
				'dc_mark' => $dcMark,
				'funds_code' => isset($m[4]) ? (string) $m[4] : '',
				'transaction_code' => ($txnCode !== null ? $txnCode : ''),
				'customer_reference_raw' => $customerRefRaw,
				'bank_reference_raw' => $bankRefRaw,
			),
		);
	}

	/**
	 * @param string $bookingDate
	 * @param string $entryDateToken
	 * @return string|null
	 */
	protected function resolveMt940EntryDate($bookingDate, $entryDateToken)
	{
		$bookingDate = trim((string) $bookingDate);
		$entryDateToken = trim((string) $entryDateToken);
		if ($bookingDate === '' || $entryDateToken === '' || !preg_match('/^\d{4}$/', $entryDateToken)) {
			return null;
		}

		$year = (int) substr($bookingDate, 0, 4);
		$month = (int) substr($entryDateToken, 0, 2);
		$day = (int) substr($entryDateToken, 2, 2);
		if (!checkdate($month, $day, $year)) {
			return null;
		}
		$candidate = sprintf('%04d-%02d-%02d', $year, $month, $day);
		$bookingTs = strtotime($bookingDate);
		$candidateTs = strtotime($candidate);
		if ($bookingTs !== false && $candidateTs !== false) {
			$diffDays = (int) round(($candidateTs - $bookingTs) / 86400);
			if ($diffDays > 180) {
				$year--;
			} elseif ($diffDays < -180) {
				$year++;
			}
		}
		if (!checkdate($month, $day, $year)) {
			return $candidate;
		}
		return sprintf('%04d-%02d-%02d', $year, $month, $day);
	}

	/**
	 * @param array<int,string> $lines
	 * @param array<int,string> $delimiters
	 * @return string
	 */
	protected function detectBestDelimitedSeparator($lines, $delimiters)
	{
		$bestDelimiter = isset($delimiters[0]) ? (string) $delimiters[0] : ';';
		$bestScore = -1;
		foreach ((array) $delimiters as $delimiter) {
			$score = 0;
			$rows = 0;
			foreach (array_slice((array) $lines, 0, 220) as $line) {
				$line = (string) $line;
				if (strpos($line, (string) $delimiter) === false) {
					continue;
				}
				$row = str_getcsv($line, (string) $delimiter, '"', '\\');
				if (!is_array($row) || count($row) < 2) {
					continue;
				}
				$rows++;
				$score += count($row);
			}
			if ($rows > 0) {
				$score += ($rows * 10);
			}
			if ($score > $bestScore) {
				$bestScore = $score;
				$bestDelimiter = (string) $delimiter;
			}
		}
		return $bestDelimiter;
	}

	/**
	 * @param array<int,string> $lines
	 * @return array<string,int>
	 */
	protected function scoreDelimitedLayout($lines, $delimiters)
	{
		$best = array('rows' => 0, 'stable' => 0, 'date_hits' => 0, 'columns' => 0);
		foreach ($delimiters as $delimiter) {
			$rows = 0;
			$stable = 0;
			$dateHits = 0;
			$lastCols = -1;
			$maxCols = 0;
			foreach (array_slice($lines, 0, 200) as $line) {
				$line = (string) $line;
				if (strpos($line, $delimiter) === false) {
					continue;
				}
				$row = str_getcsv($line, $delimiter, '"', '\\');
				if (!is_array($row)) {
					continue;
				}
				$cols = count($row);
				if ($cols < 2) {
					continue;
				}
				$rows++;
				$maxCols = max($maxCols, $cols);
				if ($lastCols > 0 && abs($cols - $lastCols) <= 1) {
					$stable++;
				}
				$lastCols = $cols;
				$date1 = isset($row[1]) ? $this->parseDateFlexible((string) $row[1]) : null;
				$date2 = isset($row[2]) ? $this->parseDateFlexible((string) $row[2]) : null;
				if ($date1 !== null || $date2 !== null) {
					$dateHits++;
				}
			}

			$current = array('rows' => $rows, 'stable' => $stable, 'date_hits' => $dateHits, 'columns' => $maxCols);
			$bestScore = ($best['rows'] * 5) + ($best['stable'] * 3) + ($best['date_hits'] * 4) + $best['columns'];
			$currentScore = ($rows * 5) + ($stable * 3) + ($dateHits * 4) + $maxCols;
			if ($currentScore > $bestScore) {
				$best = $current;
			}
		}

		return $best;
	}

	/**
	 * @param array<int,string> $lines
	 * @param string $needle
	 * @return bool
	 */
	protected function containsToken($lines, $needle)
	{
		$needle = strtoupper((string) $needle);
		foreach ($lines as $line) {
			if (strpos(strtoupper((string) $line), $needle) !== false) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param array<int,string> $lines
	 * @return array<int,string>
	 */
	protected function normalizeLines($lines)
	{
		$normalized = array();
		foreach ((array) $lines as $line) {
			$line = str_replace("\0", '', (string) $line);
			$line = rtrim($line, "\r\n");
			if ($line === '') {
				continue;
			}
			$normalized[] = $line;
		}
		return $normalized;
	}

	/**
	 * @param string $token
	 * @param string $fallback
	 * @return string
	 */
	protected function normalizeCurrency($token, $fallback = 'EUR')
	{
		$token = strtoupper(trim((string) $token));
		if ($token === '978') {
			return 'EUR';
		}
		if (preg_match('/^[A-Z]{3}$/', $token)) {
			return $token;
		}
		$fallback = strtoupper(trim((string) $fallback));
		return preg_match('/^[A-Z]{3}$/', $fallback) ? $fallback : 'EUR';
	}

	/**
	 * @param string $token
	 * @return string|null
	 */
	protected function parseYYYYMMDD($token)
	{
		$token = trim((string) $token);
		if (!preg_match('/^\d{8}$/', $token)) {
			return null;
		}
		$year = (int) substr($token, 0, 4);
		$month = (int) substr($token, 4, 2);
		$day = (int) substr($token, 6, 2);
		if (!checkdate($month, $day, $year)) {
			return null;
		}
		return sprintf('%04d-%02d-%02d', $year, $month, $day);
	}

	/**
	 * @param string $token
	 * @return string|null
	 */
	protected function parseYYMMDD($token)
	{
		$token = trim((string) $token);
		if (!preg_match('/^\d{6}$/', $token)) {
			return null;
		}
		$year = (int) substr($token, 0, 2);
		$month = (int) substr($token, 2, 2);
		$day = (int) substr($token, 4, 2);
		$fullYear = ($year >= 70) ? (1900 + $year) : (2000 + $year);
		if (!checkdate($month, $day, $fullYear)) {
			return null;
		}
		return sprintf('%04d-%02d-%02d', $fullYear, $month, $day);
	}

	/**
	 * @param string $value
	 * @return string|null
	 */
	protected function parseDateFlexible($value)
	{
		$value = trim((string) $value);
		if ($value === '') {
			return null;
		}
		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
			return $this->parseYYYYMMDD(str_replace('-', '', $value));
		}
		if (preg_match('/^(\d{2})[-\/](\d{2})[-\/](\d{4})$/', $value, $m)) {
			$day = (int) $m[1];
			$month = (int) $m[2];
			$year = (int) $m[3];
			if (checkdate($month, $day, $year)) {
				return sprintf('%04d-%02d-%02d', $year, $month, $day);
			}
		}
		if (preg_match('/^\d{8}$/', $value)) {
			$ymd = $this->parseYYYYMMDD($value);
			if ($ymd !== null) {
				return $ymd;
			}
			$dmy = preg_match('/^(\d{2})(\d{2})(\d{4})$/', $value, $m)
				? sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1])
				: null;
			if ($dmy !== null) {
				$parts = explode('-', $dmy);
				if (count($parts) === 3 && checkdate((int) $parts[1], (int) $parts[2], (int) $parts[0])) {
					return $dmy;
				}
			}
		}
		return null;
	}

	/**
	 * @param string $digits
	 * @param string $signToken
	 * @return float|null
	 */
	protected function parseImpliedCentsSigned($digits, $signToken)
	{
		$digits = trim((string) $digits);
		if ($digits === '' || !preg_match('/^\d+$/', $digits)) {
			return null;
		}
		$value = ((float) $digits) / 100.0;
		$signToken = strtoupper(trim((string) $signToken));
		if ($signToken === '1' || $signToken === 'D' || $signToken === '-') {
			return -$value;
		}
		if ($signToken === '2' || $signToken === 'C' || $signToken === '+') {
			return $value;
		}
		return $value;
	}

	/**
	 * @param string $token
	 * @return float|null
	 */
	protected function parseSignedImpliedCentsBlock($token)
	{
		$token = trim((string) $token);
		if (!preg_match('/^([+-])(\d{18})$/', $token, $m)) {
			return null;
		}
		$value = ((float) $m[2]) / 100.0;
		return ($m[1] === '-') ? (-$value) : $value;
	}

	/**
	 * @param string $unsignedDigits
	 * @param string $signToken
	 * @return float|null
	 */
	protected function parseUnsignedImpliedCents($unsignedDigits, $signToken)
	{
		$unsignedDigits = trim((string) $unsignedDigits);
		if ($unsignedDigits === '' || !preg_match('/^\d{9,18}$/', $unsignedDigits)) {
			return null;
		}
		$value = ((float) $unsignedDigits) / 100.0;
		$signToken = strtoupper(trim((string) $signToken));
		if ($signToken === 'D' || $signToken === '-') {
			return -$value;
		}
		return $value;
	}

	/**
	 * @param string $value
	 * @return float|null
	 */
	protected function parseDecimal($value)
	{
		$value = trim((string) $value);
		if ($value === '') {
			return null;
		}
		$value = str_replace(array(chr(194) . chr(160), ' '), '', $value);
		$value = str_replace(array('EUR', 'eur', '€'), '', $value);
		$value = trim((string) $value);
		if ($value === '') {
			return null;
		}

		$negative = false;
		if (preg_match('/^\((.*)\)$/', $value, $m)) {
			$negative = true;
			$value = (string) $m[1];
		}
		if (substr($value, -1) === '-') {
			$negative = true;
			$value = substr($value, 0, -1);
		}

		$value = preg_replace('/[^0-9,\.\+\-]/', '', (string) $value);
		if ($value === '' || $value === '+' || $value === '-') {
			return null;
		}

		$sign = 1;
		if (strpos($value, '-') !== false) {
			$sign = -1;
		}
		$value = str_replace(array('+', '-'), '', (string) $value);

		$commaCount = substr_count($value, ',');
		$dotCount = substr_count($value, '.');
		if ($commaCount > 0 && $dotCount > 0) {
			$lastComma = strrpos($value, ',');
			$lastDot = strrpos($value, '.');
			if ($lastComma !== false && $lastDot !== false && $lastComma > $lastDot) {
				$value = str_replace('.', '', $value);
				$value = str_replace(',', '.', $value);
			} else {
				$value = str_replace(',', '', $value);
			}
		} elseif ($commaCount > 0) {
			$value = str_replace('.', '', $value);
			$value = str_replace(',', '.', $value);
		} elseif ($dotCount > 1 && preg_match('/\.\d{3}$/', $value)) {
			$value = str_replace('.', '', $value);
		}

		if (!is_numeric($value)) {
			return null;
		}
		$number = (float) $value;
		$number = ($sign < 0 || $negative) ? -abs($number) : abs($number);
		return round($number, 2);
	}

	/**
	 * @param string $text
	 * @param bool $stripPaddingPrefix
	 * @return string
	 */
	protected function normalizeText($text, $stripPaddingPrefix = true)
	{
		$text = str_replace(array("\r", "\n", "\t"), ' ', (string) $text);
		$text = preg_replace('/\s+/', ' ', (string) $text);
		$text = trim((string) $text);
		if ($stripPaddingPrefix) {
			$text = preg_replace('/^0{6,}\s*/', '', (string) $text);
			$text = preg_replace('/^0+\b\s*/', '', (string) $text);
			$text = trim((string) $text);
		}
		return $text;
	}

	/**
	 * @param string|null $value
	 * @return string|null
	 */
	protected function normalizeReference($value)
	{
		if ($value === null) {
			return null;
		}
		$value = $this->normalizeText((string) $value, true);
		if ($value === '') {
			return null;
		}
		$compact = preg_replace('/\s+/', '', (string) $value);
		if ($compact === '' || preg_match('/^0+$/', $compact)) {
			return null;
		}
		if (strtoupper($compact) === 'NONREF') {
			return null;
		}
		$alnum = preg_replace('/[^A-Za-z0-9]/', '', (string) $compact);
		if (strlen((string) $alnum) < 4) {
			return null;
		}
		return $value;
	}

	/**
	 * @param string $text
	 * @return string|null
	 */
	protected function extractStrongReference($text)
	{
		$text = trim((string) $text);
		if ($text === '') {
			return null;
		}
		$patterns = array(
			'/\bDG01-[A-Z0-9-]+\b/i',
			'/\bCOB\.REC\.[A-Z0-9\.\/ -]+\b/i',
			'/\bTRF\.\s*COBR\s*DUC\s*\d+\b/i',
			'/\bDG\d{2}-PAY\d{4}-\d{5}\b/i',
		);
		foreach ($patterns as $pattern) {
			if (preg_match($pattern, $text, $m)) {
				return $this->normalizeReference((string) $m[0]);
			}
		}
		return null;
	}

	/**
	 * @param string $text
	 * @return string|null
	 */
	protected function extractIbanFromText($text)
	{
		$text = strtoupper((string) $text);
		if (preg_match('/\b([A-Z]{2}\d{2}[A-Z0-9]{11,30})\b/', $text, $m)) {
			return $this->normalizeIban((string) $m[1]);
		}
		return null;
	}

	/**
	 * @param string $iban
	 * @return string|null
	 */
	protected function normalizeIban($iban)
	{
		$iban = strtoupper((string) preg_replace('/\s+/', '', (string) $iban));
		if (!preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/', $iban)) {
			return null;
		}
		return $iban;
	}

	/**
	 * @param string $format
	 * @param string $bookingDate
	 * @param string|null $valueDate
	 * @param float $amount
	 * @param string $currency
	 * @param string $description
	 * @param string|null $reference
	 * @param string|null $counterpartyName
	 * @param string|null $counterpartyIban
	 * @param float|null $runningBalance
	 * @param array<int,string> $rawLines
	 * @param array<string,mixed> $rawFields
	 * @return array<string,mixed>
	 */
	protected function buildTransaction($format, $bookingDate, $valueDate, $amount, $currency, $description, $reference, $counterpartyName, $counterpartyIban, $runningBalance, $rawLines, $rawFields)
	{
		$bookingDate = (string) $bookingDate;
		if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $bookingDate)) {
			return array();
		}
		if (!is_numeric($amount)) {
			return array();
		}
		$currency = $this->normalizeCurrency($currency);
		$description = $this->normalizeText((string) $description, true);
		$reference = $this->normalizeReference($reference);
		$counterpartyName = $this->normalizeText((string) $counterpartyName, false);
		$counterpartyIban = $this->normalizeIban((string) $counterpartyIban);
		$valueDate = (is_string($valueDate) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $valueDate)) ? $valueDate : null;
		$runningBalance = (is_numeric($runningBalance) ? round((float) $runningBalance, 2) : null);

		return array(
			'booking_date' => $bookingDate,
			'value_date' => $valueDate,
			'amount' => round((float) $amount, 2),
			'currency' => $currency,
			'description' => $description,
			'reference' => $reference,
			'counterparty_name' => ($counterpartyName !== '' ? $counterpartyName : null),
			'counterparty_iban' => $counterpartyIban,
			'running_balance' => $runningBalance,
			'raw' => array(
				'format' => (string) $format,
				'lines' => array_values((array) $rawLines),
				'fields' => (array) $rawFields,
			),
		);
	}
}
