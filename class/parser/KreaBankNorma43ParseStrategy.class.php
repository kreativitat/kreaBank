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
 * Parse strategy for Norma 43 variants.
 */
class KreaBankNorma43ParseStrategy implements KreaBankParseStrategyInterface
{
	/**
	 * @inheritDoc
	 */
	public function supportsFormat($format)
	{
		$format = (string) $format;
		return in_array($format, array('norma43', 'norma43_r24', 'norma43_978'), true);
	}

	/**
	 * @inheritDoc
	 */
	public function parse($parser, $filePath, $fileName, $defaultCurrency)
	{
		$content = file_get_contents($filePath);
		if ($content === false) {
			throw new Exception('Unable to read Norma 43 file');
		}

		$rows = preg_split('/\r\n|\r|\n/', $content);
		if (!is_array($rows)) {
			return array();
		}

		$lines = array();
		$current = null;
		$currency = strtoupper((string) $defaultCurrency);

		$extractReference = function ($text) {
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
		};

		$finalize = function () use (&$current, &$lines, &$currency, $parser, $extractReference) {
			if (!is_array($current)) {
				return;
			}

			$description = trim((string) $current['description']);
			if (!empty($current['extra']) && is_array($current['extra'])) {
				$description = trim((string) ($description . ' ' . implode(' ', $current['extra'])));
			}
			$description = $this->cleanNorma43Text($description);
			$conceptCode = strtoupper(trim((string) (!empty($current['concept_code']) ? $current['concept_code'] : '')));
			if ($conceptCode !== '' && preg_match('/^[A-Z0-9]{3,4}$/', $conceptCode)) {
				$prefixPattern = '/^' . preg_quote($conceptCode, '/') . '\s+/i';
				if (preg_match($prefixPattern, (string) $description) && strlen((string) $description) > (strlen($conceptCode) + 8)) {
					$description = trim((string) preg_replace($prefixPattern, '', (string) $description));
				}
			}

			$amount = isset($current['amount']) ? (float) $current['amount'] : 0.0;
			if (abs($amount) < 0.0000001 && $description === '' && (string) $current['payment_reference'] === '') {
				$current = null;
				return;
			}

			$lineRank = count($lines);
			$paymentReference = $this->cleanNorma43Reference((string) $current['payment_reference']);
			$detectedReference = ($description !== '') ? $this->cleanNorma43Reference((string) $extractReference($description)) : '';
			if ($detectedReference !== '' && ($paymentReference === '' || strlen($detectedReference) > (strlen($paymentReference) + 3) || preg_match('/^0+/', $paymentReference))) {
				$paymentReference = $detectedReference;
			}
			$bankReference = $this->cleanNorma43Reference((string) $current['bank_reference']);
			if ($bankReference === '' || strlen($bankReference) < 6 || preg_match('/^0+/', preg_replace('/\s+/', '', $bankReference))) {
				$bankReference = $paymentReference;
			}

			$lines[] = array(
				'line_rank' => $lineRank,
				'operation_date' => $current['operation_date'],
				'value_date' => $current['value_date'],
				'amount' => $amount,
				'currency' => strtoupper((string) ($current['currency'] ?: $currency)),
				'running_balance' => null,
				'counterparty_name' => (string) $current['counterparty_name'],
				'counterparty_iban' => $parser->callHelper('normalizeIban', (string) $current['counterparty_iban']),
				'description' => $description,
				'payment_reference' => $paymentReference,
				'bank_reference' => $bankReference,
				'direction' => $amount >= 0 ? 1 : -1,
				'line_uid' => sha1(implode('|', array($lineRank, $current['operation_date'], $amount, $paymentReference, $bankReference, $description))),
			);

			$current = null;
		};

		foreach ($rows as $row) {
			$row = rtrim((string) $row);
			if ($row === '') {
				continue;
			}

			$recordType = substr($row, 0, 2);
			if (!preg_match('/^\d{2}$/', (string) $recordType)) {
				continue;
			}

			if ($recordType === '11') {
				if (preg_match('/([A-Z]{3}|978)\s*$/', $row, $currencyMatch)) {
					$code = strtoupper((string) $currencyMatch[1]);
					$currency = ($code === '978') ? 'EUR' : $code;
				}
				continue;
			}

			if ($recordType === '22') {
				$finalize();

				$operationDate = $parser->callHelper('parseShortBankDate', substr($row, 10, 6));
				$valueDate = $parser->callHelper('parseShortBankDate', substr($row, 16, 6));
				if ($operationDate === null) {
					$operationDate = $parser->callHelper('parseShortBankDate', substr($row, 22, 6));
				}
				if ($valueDate === null) {
					$valueDate = $operationDate;
				}

				$signToken = trim((string) substr($row, 27, 1));
				$amountRaw = '';
				$amountPositions = array(
					array(28, 14),
					array(29, 14),
					array(27, 14),
					array(30, 14),
				);
				foreach ($amountPositions as $position) {
					$candidate = trim((string) substr($row, $position[0], $position[1]));
					if ($candidate !== '' && preg_match('/\d/', $candidate)) {
						$amountRaw = $candidate;
						break;
					}
				}
				$amount = $parser->callHelper('parseNorma43Amount', $amountRaw, $signToken);
				if ($amount === null) {
					$amount = 0.0;
				}

				$paymentReference = '';
				$bankReference = '';
				$conceptCode = trim((string) substr($row, 24, 3));
				$conceptText = $this->cleanNorma43Text((string) substr($row, 52));
				$concept = $conceptText;

				$current = array(
					'operation_date' => $operationDate,
					'value_date' => $valueDate,
					'amount' => $amount,
					'currency' => strtoupper((string) $currency),
					'counterparty_name' => '',
					'counterparty_iban' => '',
					'description' => $concept,
					'payment_reference' => $paymentReference,
					'bank_reference' => $bankReference,
					'concept_code' => $conceptCode,
					'extra' => array(),
				);
				continue;
			}

			if (($recordType === '23' || $recordType === '24') && is_array($current)) {
				$text = $this->cleanNorma43Text((string) substr($row, 4));
				if ($text !== '') {
					// Registo 24 may carry currency/amount mirror, keep only descriptive fragments.
					if (!preg_match('/^[A-Z]{3}\d+\s*$/', $text) && !preg_match('/^\d+$/', $text)) {
						$current['extra'][] = $text;
					}
					if (preg_match('/([A-Z]{2}[0-9]{2}[A-Z0-9]{11,30})/', strtoupper($text), $ibanMatch)) {
						$current['counterparty_iban'] = (string) $ibanMatch[1];
					}
				}
				continue;
			}

			if (($recordType === '33' || $recordType === '88') && is_array($current)) {
				$finalize();
			}
		}

		$finalize();
		if (!empty($lines)) {
			return $lines;
		}

		return $parser->parseFormatDelimitedText($filePath, $fileName, $defaultCurrency);
	}

	/**
	 * Clean text extracted from fixed-width Norma43 records.
	 *
	 * @param string $text
	 * @return string
	 */
	protected function cleanNorma43Text($text)
	{
		$text = str_replace(array("\r", "\n", "\t"), ' ', (string) $text);
		$text = preg_replace('/^\s*0{4,}\s*/', '', (string) $text);
		$text = preg_replace('/\s+/', ' ', (string) $text);
		return trim((string) $text);
	}

	/**
	 * Normalize reference-like values from Norma43 tokens.
	 *
	 * @param string $text
	 * @return string
	 */
	protected function cleanNorma43Reference($text)
	{
		$text = $this->cleanNorma43Text((string) $text);
		if ($text === '' || preg_match('/^0+$/', preg_replace('/\s+/', '', $text))) {
			return '';
		}
		$alnumLen = strlen((string) preg_replace('/[^A-Za-z0-9]/', '', $text));
		if ($alnumLen < 4) {
			return '';
		}
		return $text;
	}
}
