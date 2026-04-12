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
 * Parse strategy for CSV format.
 */
class KreaBankCsvParseStrategy implements KreaBankParseStrategyInterface
{
	/**
	 * @inheritDoc
	 */
	public function supportsFormat($format)
	{
		return ((string) $format) === 'csv';
	}

	/**
	 * @inheritDoc
	 */
	public function parse($parser, $filePath, $fileName, $defaultCurrency)
	{
		$delimiter = (string) $parser->callHelper('detectCsvDelimiter', $filePath);
		$handle = fopen($filePath, 'r');
		if (!is_resource($handle)) {
			throw new Exception('Unable to open CSV file');
		}

		$lines = array();
		$firstRow = fgetcsv($handle, 0, $delimiter, '"', '\\');
		if (!is_array($firstRow)) {
			fclose($handle);
			return $lines;
		}

		$hasHeader = (bool) $parser->callHelper('isHeaderRow', $firstRow);
		$map = $hasHeader ? (array) $parser->callHelper('buildHeaderMap', $firstRow) : array();
		$rank = 0;

		if (!$hasHeader) {
			$parsed = $this->parseCsvRow($parser, $firstRow, $defaultCurrency, $map, $rank);
			if (!empty($parsed)) {
				$lines[] = $parsed;
			}
			$rank++;
		}

		while (($row = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
			$parsed = $this->parseCsvRow($parser, $row, $defaultCurrency, $map, $rank);
			if (!empty($parsed)) {
				$lines[] = $parsed;
			}
			$rank++;
		}

		fclose($handle);

		return $lines;
	}

	/**
	 * Parse one CSV row into normalized line shape.
	 *
	 * @param KreaBankParser $parser
	 * @param array<int,string> $row
	 * @param string $defaultCurrency
	 * @param array<string,int> $map
	 * @param int $rank
	 * @return array<string,mixed>
	 */
	protected function parseCsvRow($parser, $row, $defaultCurrency, $map, $rank)
	{
		$row = array_map(static function ($value) {
			return trim((string) $value);
		}, (array) $row);
		if (count(array_filter($row, static function ($item) {
			return $item !== '';
		})) === 0) {
			return array();
		}

		$operationDate = (string) $parser->callHelper('extractMapped', $row, $map, array('date', 'booking_date', 'operation_date', 'data', 'data_movimento', 'data_lancamento', 'data_operacao'), 0);
		$valueDate = (string) $parser->callHelper('extractMapped', $row, $map, array('value_date', 'valuta', 'valuedate', 'data_valor'), 1);
		$amountValue = (string) $parser->callHelper('extractMapped', $row, $map, array('amount', 'montant', 'betrag', 'valor', 'montante'), 2);
		$runningBalance = (string) $parser->callHelper('extractMapped', $row, $map, array('balance', 'saldo', 'running_balance', 'saldo_contabilistico'), 3);
		$description = (string) $parser->callHelper('extractMapped', $row, $map, array('description', 'memo', 'label', 'purpose', 'remittance', 'descricao', 'descrição'), 4);
		$reference = (string) $parser->callHelper('extractMapped', $row, $map, array('reference', 'ref', 'payment_reference', 'endtoendid', 'referencia', 'referência'), 5);
		$iban = (string) $parser->callHelper('extractMapped', $row, $map, array('iban', 'counterparty_iban'), 6);
		$name = (string) $parser->callHelper('extractMapped', $row, $map, array('name', 'counterparty', 'beneficiary', 'partner'), 7);
		$currency = (string) $parser->callHelper('extractMapped', $row, $map, array('currency', 'devise'), 8);

		$amount = $parser->callHelper('parseLocalizedNumber', (string) $amountValue);
		if ($amount === '' || $amount === null) {
			return array();
		}

		$direction = ((float) $amount > 0) ? 1 : -1;
		$currency = strtoupper($currency ?: $defaultCurrency);
		$runningBalanceNum = ($runningBalance !== '' && $runningBalance !== null)
			? $parser->callHelper('parseLocalizedNumber', (string) $runningBalance)
			: null;

		return array(
			'line_rank' => (int) $rank,
			'operation_date' => $parser->callHelper('parseDate', $operationDate),
			'value_date' => $parser->callHelper('parseDate', $valueDate),
			'amount' => (float) $amount,
			'currency' => $currency,
			'running_balance' => ($runningBalanceNum !== null)
				? (float) $runningBalanceNum
				: null,
			'counterparty_name' => $name,
			'counterparty_iban' => $parser->callHelper('normalizeIban', $iban),
			'description' => $description,
			'payment_reference' => $reference,
			'bank_reference' => $reference,
			'direction' => $direction,
			'line_uid' => sha1(implode('|', array($rank, $operationDate, $amount, $reference, $description))),
		);
	}
}
