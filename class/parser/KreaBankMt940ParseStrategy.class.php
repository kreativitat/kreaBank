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
 * Parse strategy for SWIFT MT940 format.
 */
class KreaBankMt940ParseStrategy implements KreaBankParseStrategyInterface
{
	/**
	 * @inheritDoc
	 */
	public function supportsFormat($format)
	{
		return ((string) $format) === 'mt940';
	}

	/**
	 * @inheritDoc
	 */
	public function parse($parser, $filePath, $fileName, $defaultCurrency)
	{
		$content = file_get_contents($filePath);
		if ($content === false) {
			throw new Exception('Unable to read MT940 file');
		}

		$currency = strtoupper((string) $defaultCurrency);
		if (preg_match('/:(60F|60M|62F|62M):[CD]\d{6}([A-Z]{3})/', $content, $match)) {
			$currency = strtoupper((string) $match[2]);
		}

		$linesRaw = preg_split('/\r\n|\r|\n/', $content);
		if (!is_array($linesRaw)) {
			return array();
		}

		$entries = array();
		$current = null;
		foreach ($linesRaw as $rawLine) {
			$line = trim((string) $rawLine);
			if ($line === '') {
				continue;
			}

			if (strpos($line, ':61:') === 0) {
				if (is_array($current)) {
					$entries[] = $current;
				}
				$current = array(
					'61' => trim((string) substr($line, 4)),
					'86' => array(),
				);
				continue;
			}

			if (!is_array($current)) {
				continue;
			}

			if (strpos($line, ':86:') === 0) {
				$current['86'][] = trim((string) substr($line, 4));
				continue;
			}

			if (preg_match('/^:[0-9]{2}[A-Z]?:/', $line)) {
				$entries[] = $current;
				$current = null;
				continue;
			}

			if (!empty($current['86'])) {
				$lastIndex = count($current['86']) - 1;
				$current['86'][$lastIndex] = trim($current['86'][$lastIndex] . ' ' . $line);
			} else {
				$current['61'] = trim($current['61'] . ' ' . $line);
			}
		}
		if (is_array($current)) {
			$entries[] = $current;
		}

		$lines = array();
		$rank = 0;
		foreach ($entries as $entry) {
			$field61 = isset($entry['61']) ? trim((string) $entry['61']) : '';
			if ($field61 === '' || !preg_match('/^(\d{6})(\d{4})?(.*)$/', $field61, $dateMatch)) {
				continue;
			}

			$operationDate = $parser->callHelper('parseShortBankDate', (string) $dateMatch[1]);
			$valueDate = $operationDate;
			$entryDate = isset($dateMatch[2]) ? trim((string) $dateMatch[2]) : '';
			if ($entryDate !== '' && strlen($entryDate) === 4 && !empty($operationDate)) {
				$year = (int) substr((string) $operationDate, 0, 4);
				$candidate = sprintf('%04d-%02d-%02d', $year, (int) substr($entryDate, 0, 2), (int) substr($entryDate, 2, 2));
				if (strtotime($candidate) !== false) {
					$valueDate = $candidate;
				}
			}

			$tail = isset($dateMatch[3]) ? trim((string) $dateMatch[3]) : '';
			$sign = 1;
			if (preg_match('/^R?([CD])/', $tail, $signMatch)) {
				$sign = ($signMatch[1] === 'D') ? -1 : 1;
				$tail = substr($tail, strlen($signMatch[0]));
			}

			if (!preg_match('/^([0-9\.,]+)/', $tail, $amountMatch)) {
				continue;
			}
			$amount = $parser->callHelper('parseLocalizedNumber', (string) $amountMatch[1]);
			if ($amount === null) {
				continue;
			}
			$amount = abs((float) $amount) * $sign;
			$tail = trim((string) substr($tail, strlen((string) $amountMatch[1])));

			if (preg_match('/^(N[A-Z0-9]{3}|F[A-Z0-9]{3}|S[A-Z0-9]{3})/', $tail, $trxTypeMatch)) {
				$tail = trim((string) substr($tail, strlen((string) $trxTypeMatch[0])));
			}

			$paymentReference = '';
			$bankReference = '';
			if (strpos($tail, '//') !== false) {
				list($paymentReference, $bankReference) = explode('//', $tail, 2);
				$paymentReference = trim((string) $paymentReference);
				$bankReference = trim((string) $bankReference);
			} else {
				$paymentReference = trim((string) $tail);
			}

			$description = '';
			$descLines = isset($entry['86']) && is_array($entry['86']) ? $entry['86'] : array();
			if (!empty($descLines)) {
				$description = trim((string) implode(' ', $descLines));
				$description = preg_replace('/\?[0-9]{2}/', ' ', $description);
				$description = preg_replace('/\s+/', ' ', (string) $description);
				$description = trim((string) $description);
			}
			if ($description === '') {
				$description = $paymentReference;
			}

			$lines[] = array(
				'line_rank' => $rank,
				'operation_date' => $operationDate,
				'value_date' => $valueDate,
				'amount' => (float) $amount,
				'currency' => $currency,
				'running_balance' => null,
				'counterparty_name' => '',
				'counterparty_iban' => '',
				'description' => $description,
				'payment_reference' => $paymentReference,
				'bank_reference' => $bankReference,
				'direction' => $amount >= 0 ? 1 : -1,
				'line_uid' => sha1(implode('|', array($rank, $operationDate, $amount, $paymentReference, $bankReference, $description))),
			);
			$rank++;
		}

		return $lines;
	}
}
