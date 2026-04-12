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
 * Parse strategy for OFX format.
 */
class KreaBankOfxParseStrategy implements KreaBankParseStrategyInterface
{
	/**
	 * @inheritDoc
	 */
	public function supportsFormat($format)
	{
		return ((string) $format) === 'ofx';
	}

	/**
	 * @inheritDoc
	 */
	public function parse($parser, $filePath, $fileName, $defaultCurrency)
	{
		$content = file_get_contents($filePath);
		if ($content === false) {
			throw new Exception('Unable to read OFX file');
		}

		preg_match_all('/<STMTTRN>(.*?)<\/STMTTRN>/is', $content, $matches);
		$entries = isset($matches[1]) ? $matches[1] : array();

		$currency = $defaultCurrency;
		if (preg_match('/<CURDEF>([A-Z]{3})/i', $content, $currencyMatch)) {
			$currency = strtoupper($currencyMatch[1]);
		}

		$lines = array();
		$rank = 0;
		foreach ($entries as $entry) {
			$amount = (string) $parser->callHelper('extractTagValue', $entry, 'TRNAMT');
			$date = (string) $parser->callHelper('extractTagValue', $entry, 'DTPOSTED');
			$name = (string) $parser->callHelper('extractTagValue', $entry, 'NAME');
			$memo = (string) $parser->callHelper('extractTagValue', $entry, 'MEMO');
			$fitid = (string) $parser->callHelper('extractTagValue', $entry, 'FITID');
			$checknum = (string) $parser->callHelper('extractTagValue', $entry, 'CHECKNUM');

			$amountNum = $parser->callHelper('parseLocalizedNumber', (string) $amount);
			if ($amountNum === '' || $amountNum === null) {
				continue;
			}
			$amountNum = (float) $amountNum;

			$lines[] = array(
				'line_rank' => $rank,
				'operation_date' => $parser->callHelper('parseDate', $date),
				'value_date' => $parser->callHelper('parseDate', $date),
				'amount' => $amountNum,
				'currency' => $currency,
				'running_balance' => null,
				'counterparty_name' => $name,
				'counterparty_iban' => '',
				'description' => trim($memo . ' ' . $name),
				'payment_reference' => $fitid,
				'bank_reference' => $checknum,
				'direction' => $amountNum > 0 ? 1 : -1,
				'line_uid' => sha1(implode('|', array($rank, $date, $amountNum, $fitid))),
			);
			$rank++;
		}

		return $lines;
	}
}
