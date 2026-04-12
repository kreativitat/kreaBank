<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

require_once __DIR__ . '/KreaBankParseStrategyInterface.class.php';

/**
 * Parse strategy for generic delimited/text exports.
 */
class KreaBankDelimitedTextParseStrategy implements KreaBankParseStrategyInterface
{
	/**
	 * @inheritDoc
	 */
	public function supportsFormat($format)
	{
		$format = (string) $format;
		return in_array($format, array('tabulated', 'bit_recon', 'emc', 'consolidated', 'nib_compound'), true);
	}

	/**
	 * @inheritDoc
	 */
	public function parse($parser, $filePath, $fileName, $defaultCurrency)
	{
		$format = (string) $parser->callHelper('detectFormat', $filePath, $fileName);

		if ($format === 'nib_compound') {
			$fixedLines = $this->parseNibCompoundRows($parser, $filePath, $defaultCurrency);
			if (!empty($fixedLines)) {
				return $fixedLines;
			}
		}
		if ($format === 'consolidated') {
			$fixedLines = $this->parseConsolidatedRows($parser, $filePath, $defaultCurrency);
			if (!empty($fixedLines)) {
				return $fixedLines;
			}
		}
		if ($format === 'bit_recon') {
			$fixedLines = $this->parseBitReconRows($parser, $filePath, $defaultCurrency);
			if (!empty($fixedLines)) {
				return $fixedLines;
			}
		}
		if ($format === 'emc') {
			$fixedLines = $this->parseEmcRows($parser, $filePath, $defaultCurrency);
			if (!empty($fixedLines)) {
				return $fixedLines;
			}
		}

		$rows = (array) $parser->callHelper('loadDelimitedRows', $filePath);
		if (!empty($rows)) {
			if ($format === 'tabulated') {
				$tabulatedLines = $this->parseKnownTabulatedRows($parser, $rows, $defaultCurrency);
				if (!empty($tabulatedLines)) {
					return $tabulatedLines;
				}
			}

			$lines = (array) $parser->callHelper('parseTabularRows', $rows, $defaultCurrency);
			if (!empty($lines)) {
				return $lines;
			}
		}

		return $parser->parseFormatCsv($filePath, $fileName, $defaultCurrency);
	}

	/**
	 * Parse known "tabulado" export layouts (date/date/desc/debit/credit/balance).
	 *
	 * @param object $parser
	 * @param array<int,array<int,string>> $rows
	 * @param string $defaultCurrency
	 * @return array<int,array<string,mixed>>
	 */
	protected function parseKnownTabulatedRows($parser, $rows, $defaultCurrency)
	{
		$scan = 0;
		$matches = 0;
		foreach (array_slice((array) $rows, 0, 25) as $row) {
			if (!is_array($row) || count($row) < 8) {
				continue;
			}
			$scan++;
			$date1 = $parser->callHelper('parseDate', (string) $row[1]);
			$date2 = $parser->callHelper('parseDate', (string) $row[2]);
			$debit = $parser->callHelper('parseLocalizedNumber', (string) $row[5]);
			$credit = $parser->callHelper('parseLocalizedNumber', (string) $row[6]);
			if ($date1 !== null && $date2 !== null && ($debit !== null || $credit !== null)) {
				$matches++;
			}
		}
		if ($scan < 5 || ($matches / max(1, $scan)) < 0.7) {
			return array();
		}

		$lines = array();
		$rank = 0;
		foreach ((array) $rows as $row) {
			if (!is_array($row) || count($row) < 8) {
				continue;
			}

			$operationDate = $parser->callHelper('parseDate', (string) $row[1]);
			$valueDate = $parser->callHelper('parseDate', (string) $row[2]);
			if ($operationDate === null && $valueDate === null) {
				continue;
			}
			if ($operationDate === null) {
				$operationDate = $valueDate;
			}
			if ($valueDate === null) {
				$valueDate = $operationDate;
			}

			$description = trim((string) $row[3]);
			$debit = $parser->callHelper('parseLocalizedNumber', (string) $row[5]);
			$credit = $parser->callHelper('parseLocalizedNumber', (string) $row[6]);
			$amount = null;
			if ($debit !== null || $credit !== null) {
				$amount = (float) (abs((float) ($credit ?: 0.0)) - abs((float) ($debit ?: 0.0)));
			}
			if ($amount === null) {
				$amount = $parser->callHelper('parseLocalizedNumber', (string) $row[5]);
			}
			if ($amount === null) {
				$amount = $parser->callHelper('parseLocalizedNumber', (string) $row[6]);
			}
			if ($amount === null) {
				continue;
			}

			$runningBalance = $parser->callHelper('parseLocalizedNumber', isset($row[7]) ? (string) $row[7] : '');
			$paymentReference = $this->extractReferenceFromText($description);
			if ($paymentReference === '') {
				$paymentReference = $description;
			}

			$lines[] = array(
				'line_rank' => $rank,
				'operation_date' => $operationDate,
				'value_date' => $valueDate,
				'amount' => (float) $amount,
				'currency' => strtoupper((string) $defaultCurrency),
				'running_balance' => $runningBalance !== null ? (float) $runningBalance : null,
				'counterparty_name' => '',
				'counterparty_iban' => '',
				'description' => $description,
				'payment_reference' => $paymentReference,
				'bank_reference' => $paymentReference,
				'direction' => ((float) $amount >= 0.0) ? 1 : -1,
				'line_uid' => sha1(implode('|', array($rank, $operationDate, $amount, $paymentReference, $description))),
			);
			$rank++;
		}

		return $lines;
	}

	/**
	 * Parse "composto com NIB" fixed-width rows.
	 *
	 * @param object $parser
	 * @param string $filePath
	 * @param string $defaultCurrency
	 * @return array<int,array<string,mixed>>
	 */
	protected function parseNibCompoundRows($parser, $filePath, $defaultCurrency)
	{
		$content = @file_get_contents($filePath);
		if (!is_string($content) || $content === '') {
			return array();
		}

		$rows = preg_split('/\r\n|\r|\n/', $content);
		if (!is_array($rows)) {
			return array();
		}

		$lines = array();
		$rank = 0;
		foreach ($rows as $row) {
			$row = rtrim((string) $row);
			if ($row === '' || $row[0] !== '5') {
				continue;
			}

			$opRaw = substr($row, 1, 6);
			$valRaw = substr($row, 7, 6);
			$operationDate = $parser->callHelper('parseShortBankDate', $opRaw);
			$valueDate = $parser->callHelper('parseShortBankDate', $valRaw);
			if ($operationDate === null && $valueDate === null) {
				continue;
			}
			if ($operationDate === null) {
				$operationDate = $valueDate;
			}
			if ($valueDate === null) {
				$valueDate = $operationDate;
			}

			$plusPos = strrpos($row, '+');
			$minusPos = strrpos($row, '-');
			$signPos = max(($plusPos !== false ? $plusPos : -1), ($minusPos !== false ? $minusPos : -1));
			if ($signPos < 0) {
				continue;
			}
			$sign = (string) $row[$signPos];
			$amountRaw = trim((string) substr($row, $signPos + 1));
			$amount = $parser->callHelper('parseLocalizedNumber', $amountRaw);
			if ($amount === null) {
				continue;
			}
			$amount = abs((float) $amount);
			if ($sign === '-') {
				$amount *= -1;
			}

			$core = trim((string) substr($row, 13, $signPos - 13));
			$description = $core;
			$bankCode = '';
			if (preg_match('/^(.*?)([A-Z0-9]{3})0{8}$/', $core, $m)) {
				$description = trim((string) $m[1]);
				$bankCode = trim((string) $m[2]);
			}
			$paymentReference = $this->extractReferenceFromText($description);
			if ($paymentReference === '') {
				$paymentReference = $description;
			}
			$bankReference = ($bankCode !== '') ? $bankCode : $paymentReference;

			$lines[] = array(
				'line_rank' => $rank,
				'operation_date' => $operationDate,
				'value_date' => $valueDate,
				'amount' => (float) $amount,
				'currency' => strtoupper((string) $defaultCurrency),
				'running_balance' => null,
				'counterparty_name' => '',
				'counterparty_iban' => '',
				'description' => $description,
				'payment_reference' => $paymentReference,
				'bank_reference' => $bankReference,
				'direction' => ((float) $amount >= 0.0) ? 1 : -1,
				'line_uid' => sha1(implode('|', array($rank, $operationDate, $amount, $paymentReference, $description))),
			);
			$rank++;
		}

		return $lines;
	}

	/**
	 * Parse "consolidado" fixed-width rows.
	 *
	 * @param object $parser
	 * @param string $filePath
	 * @param string $defaultCurrency
	 * @return array<int,array<string,mixed>>
	 */
	protected function parseConsolidatedRows($parser, $filePath, $defaultCurrency)
	{
		$content = @file_get_contents($filePath);
		if (!is_string($content) || $content === '') {
			return array();
		}
		$rows = preg_split('/\r\n|\r|\n/', $content);
		if (!is_array($rows)) {
			return array();
		}

		$currency = strtoupper((string) $defaultCurrency);
		$lines = array();
		$rank = 0;

		foreach ($rows as $row) {
			$row = rtrim((string) $row);
			if ($row === '') {
				continue;
			}
			if (strpos($row, '01') === 0 && strlen($row) >= 20) {
				$headerCurrency = strtoupper(trim((string) substr($row, 17, 3)));
				if (preg_match('/^[A-Z]{3}$/', $headerCurrency)) {
					$currency = $headerCurrency;
				}
				continue;
			}
			if (strpos($row, '03') !== 0 || strlen($row) < 130) {
				continue;
			}

			$operationDate = $parser->callHelper('parseDate', (string) substr($row, 48, 8));
			$valueDate = $parser->callHelper('parseShortBankDate', (string) substr($row, 39, 6));
			if ($operationDate === null && $valueDate === null) {
				continue;
			}
			if ($operationDate === null) {
				$operationDate = $valueDate;
			}
			if ($valueDate === null) {
				$valueDate = $operationDate;
			}

			if (!preg_match('/([+-]\d{18})\s+([+-]\d{18})\s+(\d{15})\s*$/', $row, $m, PREG_OFFSET_CAPTURE)) {
				continue;
			}
			$runningBalance = $this->parseSignedCentsToken((string) $m[1][0]);
			$movement = $this->parseSignedCentsToken((string) $m[2][0]);
			if ($movement === null) {
				continue;
			}
			if (abs((float) $movement) < 0.0000001 && $runningBalance !== null && abs((float) $runningBalance) > 0.0000001) {
				$movement = (float) $runningBalance;
				$runningBalance = null;
			}

			$prefix = substr($row, 0, (int) $m[1][1]);
			$description = trim((string) substr((string) $prefix, 56));
			$description = preg_replace('/\s+0{8}\s*$/', '', (string) $description);
			$description = preg_replace('/\s+/', ' ', (string) $description);
			$description = trim((string) $description);
			$paymentReference = $this->extractReferenceFromText($description);
			if ($paymentReference === '') {
				$paymentReference = $description;
			}
			$bankCode = trim((string) substr($row, 8, 3));
			$bankReference = ($bankCode !== '') ? $bankCode : $paymentReference;

			$lines[] = array(
				'line_rank' => $rank,
				'operation_date' => $operationDate,
				'value_date' => $valueDate,
				'amount' => (float) $movement,
				'currency' => $currency,
				'running_balance' => $runningBalance !== null ? (float) $runningBalance : null,
				'counterparty_name' => '',
				'counterparty_iban' => '',
				'description' => $description,
				'payment_reference' => $paymentReference,
				'bank_reference' => $bankReference,
				'direction' => ((float) $movement >= 0.0) ? 1 : -1,
				'line_uid' => sha1(implode('|', array($rank, $operationDate, $movement, $paymentReference, $description))),
			);
			$rank++;
		}

		return $lines;
	}

	/**
	 * Parse "BIT reconciliação" fixed-width rows.
	 *
	 * @param object $parser
	 * @param string $filePath
	 * @param string $defaultCurrency
	 * @return array<int,array<string,mixed>>
	 */
	protected function parseBitReconRows($parser, $filePath, $defaultCurrency)
	{
		$content = @file_get_contents($filePath);
		if (!is_string($content) || $content === '') {
			return array();
		}
		$rows = preg_split('/\r\n|\r|\n/', $content);
		if (!is_array($rows)) {
			return array();
		}

		$lines = array();
		$rank = 0;
		foreach ($rows as $row) {
			$row = rtrim((string) $row);
			if ($row === '') {
				continue;
			}
			if (!preg_match('/^(\d{11})\s{2}(\d{8})\d{6}(.{23})([CD])\s{2}(\d{8})\d{6}(\d{15})([CD])(\d{15})([CD])(\d{4})([A-Z]{3})$/', $row, $m)) {
				continue;
			}

			$operationDate = $parser->callHelper('parseDate', (string) $m[2]);
			$valueDate = $parser->callHelper('parseDate', (string) $m[5]);
			if ($operationDate === null && $valueDate === null) {
				continue;
			}
			if ($operationDate === null) {
				$operationDate = $valueDate;
			}
			if ($valueDate === null) {
				$valueDate = $operationDate;
			}

			$amount = $this->parseUnsignedCentsWithSign((string) $m[6], (string) $m[7]);
			$runningBalance = $this->parseUnsignedCentsWithSign((string) $m[8], (string) $m[9]);
			if ($amount === null) {
				continue;
			}

			$description = trim((string) $m[3]);
			$paymentReference = $this->extractReferenceFromText($description);
			if ($paymentReference === '') {
				$paymentReference = $description;
			}

			$lines[] = array(
				'line_rank' => $rank,
				'operation_date' => $operationDate,
				'value_date' => $valueDate,
				'amount' => (float) $amount,
				'currency' => strtoupper((string) (!empty($m[11]) ? $m[11] : $defaultCurrency)),
				'running_balance' => $runningBalance !== null ? (float) $runningBalance : null,
				'counterparty_name' => '',
				'counterparty_iban' => '',
				'description' => $description,
				'payment_reference' => $paymentReference,
				'bank_reference' => trim((string) $m[10]),
				'direction' => ((float) $amount >= 0.0) ? 1 : -1,
				'line_uid' => sha1(implode('|', array($rank, $operationDate, $amount, $paymentReference, $description))),
			);
			$rank++;
		}

		return $lines;
	}

	/**
	 * Parse EMC fixed-width rows.
	 *
	 * @param object $parser
	 * @param string $filePath
	 * @param string $defaultCurrency
	 * @return array<int,array<string,mixed>>
	 */
	protected function parseEmcRows($parser, $filePath, $defaultCurrency)
	{
		$content = @file_get_contents($filePath);
		if (!is_string($content) || $content === '') {
			return array();
		}
		$rows = preg_split('/\r\n|\r|\n/', $content);
		if (!is_array($rows)) {
			return array();
		}

		$currency = strtoupper((string) $defaultCurrency);
		$lines = array();
		$rank = 0;
		foreach ($rows as $row) {
			$row = rtrim((string) $row);
			if ($row === '') {
				continue;
			}

			if (strpos($row, 'EMC13') === 0) {
				if (preg_match('/([A-Z]{3})\d{6}\d{6}/', $row, $currencyMatch)) {
					$currency = strtoupper((string) $currencyMatch[1]);
				}
				continue;
			}
			if (strpos($row, 'EMC23') !== 0) {
				continue;
			}

			if (!preg_match('/^EMC23\d{16}(\d{8})(\d{8})\d{8}([A-Z0-9 ]{3})(.+?)([CD]{2})(\d{16})([CD ])(\d{16})\s*\d*$/', $row, $m)) {
				continue;
			}

			$operationDate = $parser->callHelper('parseDate', (string) $m[1]);
			$valueDate = $parser->callHelper('parseDate', (string) $m[2]);
			if ($operationDate === null && $valueDate === null) {
				continue;
			}
			if ($operationDate === null) {
				$operationDate = $valueDate;
			}
			if ($valueDate === null) {
				$valueDate = $operationDate;
			}

			$amountSign = (strpos((string) $m[5], 'D') !== false) ? 'D' : 'C';
			$amount = $this->parseUnsignedCentsWithSign((string) $m[6], $amountSign);
			if ($amount === null) {
				continue;
			}
			$runningBalance = $this->parseUnsignedCentsWithSign((string) $m[8], trim((string) $m[7]));

			$description = trim((string) $m[4]);
			$paymentReference = $this->extractReferenceFromText($description);
			if ($paymentReference === '') {
				$paymentReference = $description;
			}

			$lines[] = array(
				'line_rank' => $rank,
				'operation_date' => $operationDate,
				'value_date' => $valueDate,
				'amount' => (float) $amount,
				'currency' => $currency,
				'running_balance' => $runningBalance !== null ? (float) $runningBalance : null,
				'counterparty_name' => '',
				'counterparty_iban' => '',
				'description' => $description,
				'payment_reference' => $paymentReference,
				'bank_reference' => trim((string) $m[3]),
				'direction' => ((float) $amount >= 0.0) ? 1 : -1,
				'line_uid' => sha1(implode('|', array($rank, $operationDate, $amount, $paymentReference, $description))),
			);
			$rank++;
		}

		return $lines;
	}

	/**
	 * Convert token like "+000000000000000849" into signed float cents.
	 *
	 * @param string $token
	 * @return float|null
	 */
	protected function parseSignedCentsToken($token)
	{
		$token = trim((string) $token);
		if (!preg_match('/^([+-])(\d+)$/', $token, $m)) {
			return null;
		}
		$value = ((float) $m[2]) / 100.0;
		return ($m[1] === '-') ? -$value : $value;
	}

	/**
	 * Convert unsigned cents + sign token into signed float.
	 *
	 * @param string $unsignedCents
	 * @param string $signToken
	 * @return float|null
	 */
	protected function parseUnsignedCentsWithSign($unsignedCents, $signToken)
	{
		$unsignedCents = trim((string) $unsignedCents);
		if ($unsignedCents === '' || !preg_match('/^\d+$/', $unsignedCents)) {
			return null;
		}
		$value = ((float) $unsignedCents) / 100.0;
		$signToken = strtoupper(trim((string) $signToken));
		if (strpos($signToken, 'D') !== false || strpos($signToken, '-') !== false) {
			return -$value;
		}
		return $value;
	}

	/**
	 * Extract a useful reference token from description.
	 *
	 * @param string $text
	 * @return string
	 */
	protected function extractReferenceFromText($text)
	{
		$text = trim((string) $text);
		if ($text === '') {
			return '';
		}
		$patterns = array(
			'/\bDG01-[A-Z0-9-]+\b/i',
			'/\bCOB\.REC\.[A-Z0-9\.\/ -]+\b/i',
			'/\bTRF\.\s*COBR\s*DUC\s*\d+\b/i',
		);
		foreach ($patterns as $pattern) {
			if (preg_match($pattern, $text, $m)) {
				return trim((string) $m[0]);
			}
		}

		return $text;
	}
}
