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
 * Parse strategy for Excel formats.
 */
class KreaBankExcelParseStrategy implements KreaBankParseStrategyInterface
{
	/**
	 * @inheritDoc
	 */
	public function supportsFormat($format)
	{
		return ((string) $format) === 'excel';
	}

	/**
	 * @inheritDoc
	 */
	public function parse($parser, $filePath, $fileName, $defaultCurrency)
	{
		$ext = strtolower(pathinfo((string) $fileName, PATHINFO_EXTENSION));

		if ($ext === 'xls' && (bool) $parser->callHelper('looksLikeSpreadsheetXmlFile', $filePath)) {
			$lines = (array) $parser->callHelper('parseSpreadsheetXml', $filePath, $defaultCurrency);
			if (!empty($lines)) {
				return $lines;
			}
		}

		if ($ext === 'xlsx') {
			$lines = (array) $parser->callHelper('parseXlsxByZip', $filePath, $defaultCurrency);
			if (!empty($lines)) {
				return $lines;
			}
		}

		$lines = (array) $parser->callHelper('parseExcelWithPhpSpreadsheet', $filePath, $defaultCurrency);
		if (!empty($lines)) {
			return $lines;
		}

		$lines = (array) $parser->callHelper('parseSpreadsheetXml', $filePath, $defaultCurrency);
		if (!empty($lines)) {
			return $lines;
		}

		if ($ext === 'xls') {
			$lines = (array) $parser->callHelper('parseXlsAsHtmlTable', $filePath, $defaultCurrency);
			if (!empty($lines)) {
				return $lines;
			}
		}

		$lines = (array) $parser->callHelper('parseExcelByCommandConversion', $filePath, $defaultCurrency);
		if (!empty($lines)) {
			return $lines;
		}

		throw new Exception('Unable to parse Excel file. Install PhpSpreadsheet or import as CSV/OFX/CAMT.053.');
	}
}
