<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

require_once __DIR__ . '/../lib/kreabank.lib.php';
require_once __DIR__ . '/parser/KreaBankParseStrategyInterface.class.php';
require_once __DIR__ . '/parser/KreaBankCamt053ParseStrategy.class.php';
require_once __DIR__ . '/parser/KreaBankOfxParseStrategy.class.php';
require_once __DIR__ . '/parser/KreaBankMt940ParseStrategy.class.php';
require_once __DIR__ . '/parser/KreaBankExcelParseStrategy.class.php';
require_once __DIR__ . '/parser/KreaBankDelimitedTextParseStrategy.class.php';
require_once __DIR__ . '/parser/KreaBankNorma43ParseStrategy.class.php';
require_once __DIR__ . '/parser/KreaBankCsvParseStrategy.class.php';
require_once __DIR__ . '/parser/KreaBankDeterministicLineParser.class.php';

/**
 * Statement parsing service for CSV/OFX/CAMT.053/XLS/XLSX and bank text exports
 * (Norma 43 variants, BIT recon, EMC, MT940).
 */
class KreaBankParser
{
	/** @var object|null */
	protected $mlHeaderClassifier = null;

	/** @var bool */
	protected $mlHeaderClassifierReady = false;

	/** @var int */
	protected $mlHeaderVectorSize = 96;

	/** @var array<string,array<int,string>>|null */
	protected $fieldAliasesCache = null;

	/** @var array<int,KreaBankParseStrategyInterface>|null */
	protected $parseStrategies = null;

	/** @var KreaBankDeterministicLineParser|null */
	protected $lineParser = null;

	/** @var array<int,string> */
	protected $strategyHelperAllowlist = array();

	/**
	 * Parse a bank statement file.
	 *
	 * @param string $filePath
	 * @param string $fileName
	 * @param string $defaultCurrency
	 * @return array{format:string,lines:array<int,array<string,mixed>>,raw:array<string,mixed>}
	 */
	public function parse($filePath, $fileName, $defaultCurrency = 'EUR')
	{
		$format = $this->detectFormat($filePath, $fileName);
		$deterministicFormats = array(
			'norma43',
			'norma43_r24',
			'norma43_978',
			'nib_compound',
			'consolidated',
			'bit_recon',
			'emc',
			'mt940',
		);
		if (in_array((string) $format, $deterministicFormats, true)) {
			$decodedLines = $this->loadDecodedTextLines($filePath);
			$lineFormat = (string) $format;
			if ($lineFormat === 'tabulated') {
				$detectedLineFormat = $this->detect_format($decodedLines);
				if ($detectedLineFormat === 'tabulated_excel' || $detectedLineFormat === 'tabulated') {
					$lineFormat = $detectedLineFormat;
				}
			}
			$transactions = $this->parse_lines($decodedLines, $lineFormat, $defaultCurrency);
			$legacyLines = $this->mapNormalizedTransactionsToLegacyLines($transactions);
			return array(
				'format' => $format,
				'lines' => $legacyLines,
				'transactions' => $transactions,
				'raw' => array('filename' => $fileName, 'path' => $filePath),
			);
		}

		$strategy = $this->resolveParseStrategy($format);
		if (!is_object($strategy)) {
			throw new Exception('Unsupported format ' . $format);
		}
		$lines = $strategy->parse($this, $filePath, $fileName, $defaultCurrency);

		return array(
			'format' => $format,
			'lines' => $lines,
			'raw' => array('filename' => $fileName, 'path' => $filePath),
		);
	}

	/**
	 * Line-based format detection (deterministic parser API).
	 *
	 * @param array<int,string> $lines
	 * @return string
	 */
	public function detect_format($lines)
	{
		return $this->getDeterministicLineParser()->detect_format((array) $lines);
	}

	/**
	 * Line-based parse (deterministic parser API, unified schema).
	 *
	 * @param array<int,string> $lines
	 * @param string $format
	 * @param string $defaultCurrency
	 * @return array<int,array<string,mixed>>
	 */
	public function parse_lines($lines, $format, $defaultCurrency = 'EUR')
	{
		$txns = $this->getDeterministicLineParser()->parse((array) $lines, (string) $format, (string) $defaultCurrency);
		$out = array();
		foreach ((array) $txns as $txn) {
			if (is_array($txn) && !empty($txn)) {
				$out[] = $txn;
			}
		}
		return $out;
	}

	/**
	 * Resolve deterministic line parser instance.
	 *
	 * @return KreaBankDeterministicLineParser
	 */
	protected function getDeterministicLineParser()
	{
		if (!($this->lineParser instanceof KreaBankDeterministicLineParser)) {
			$this->lineParser = new KreaBankDeterministicLineParser();
		}
		return $this->lineParser;
	}

	/**
	 * Read file content safely with explicit diagnostics.
	 *
	 * @param string $filePath
	 * @param int $maxBytes
	 * @param string $context
	 * @return string
	 */
	protected function readFileContentSafe($filePath, $maxBytes = 0, $context = '')
	{
		$filePath = (string) $filePath;
		$context = trim((string) $context);
		if ($filePath === '' || !is_file($filePath) || !is_readable($filePath)) {
			dol_syslog('KreaBank parser file not readable [' . ($context !== '' ? $context : 'read') . ']: ' . $filePath, LOG_WARNING);
			return '';
		}

		if ((int) $maxBytes > 0) {
			$handle = fopen($filePath, 'rb');
			if (!is_resource($handle)) {
				dol_syslog('KreaBank parser failed to open file [' . ($context !== '' ? $context : 'read') . ']: ' . $filePath, LOG_WARNING);
				return '';
			}
			$data = fread($handle, (int) $maxBytes);
			fclose($handle);
			if (!is_string($data)) {
				dol_syslog('KreaBank parser failed to read file chunk [' . ($context !== '' ? $context : 'read') . ']: ' . $filePath, LOG_WARNING);
				return '';
			}

			return $data;
		}

		$data = file_get_contents($filePath);
		if (!is_string($data)) {
			dol_syslog('KreaBank parser failed to read file [' . ($context !== '' ? $context : 'read') . ']: ' . $filePath, LOG_WARNING);
			return '';
		}

		return $data;
	}

	/**
	 * Remove a temporary file and log failures.
	 *
	 * @param string $filePath
	 * @param string $context
	 * @return bool
	 */
	protected function removeTempFile($filePath, $context = '')
	{
		$filePath = (string) $filePath;
		if ($filePath === '' || !file_exists($filePath)) {
			return true;
		}
		if (unlink($filePath)) {
			return true;
		}

		if (function_exists('dol_syslog')) {
			dol_syslog('KreaBank parser failed to remove temp file [' . trim((string) $context) . ']: ' . $filePath, LOG_WARNING);
		}

		return false;
	}

	/**
	 * Register custom parse strategy.
	 *
	 * @param KreaBankParseStrategyInterface $strategy
	 * @return void
	 */
	public function registerParseStrategy($strategy)
	{
		if (!($strategy instanceof KreaBankParseStrategyInterface)) {
			return;
		}
		$this->getParseStrategies();
		$this->parseStrategies[] = $strategy;
	}

	/**
	 * Resolve parser strategy for a format.
	 *
	 * @param string $format
	 * @return KreaBankParseStrategyInterface|null
	 */
	protected function resolveParseStrategy($format)
	{
		foreach ($this->getParseStrategies() as $strategy) {
			if ($strategy instanceof KreaBankParseStrategyInterface && $strategy->supportsFormat((string) $format)) {
				return $strategy;
			}
		}
		return null;
	}

	/**
	 * Get active parse strategies list.
	 *
	 * @return array<int,KreaBankParseStrategyInterface>
	 */
	protected function getParseStrategies()
	{
		if (is_array($this->parseStrategies)) {
			return $this->parseStrategies;
		}

		$this->parseStrategies = array(
			new KreaBankCamt053ParseStrategy(),
			new KreaBankOfxParseStrategy(),
			new KreaBankMt940ParseStrategy(),
			new KreaBankExcelParseStrategy(),
			new KreaBankDelimitedTextParseStrategy(),
			new KreaBankNorma43ParseStrategy(),
			new KreaBankCsvParseStrategy(),
		);

		return $this->parseStrategies;
	}

	/**
	 * Public format wrapper for CAMT strategy.
	 *
	 * @param string $filePath
	 * @param string $fileName
	 * @param string $defaultCurrency
	 * @return array<int,array<string,mixed>>
	 */
	public function parseFormatCamt053($filePath, $fileName, $defaultCurrency = 'EUR')
	{
		$strategy = $this->resolveParseStrategy('camt053');
		if (!is_object($strategy)) {
			throw new Exception('Unsupported format camt053');
		}
		return $strategy->parse($this, $filePath, $fileName, $defaultCurrency);
	}

	/**
	 * Public format wrapper for OFX strategy.
	 *
	 * @param string $filePath
	 * @param string $fileName
	 * @param string $defaultCurrency
	 * @return array<int,array<string,mixed>>
	 */
	public function parseFormatOfx($filePath, $fileName, $defaultCurrency = 'EUR')
	{
		$strategy = $this->resolveParseStrategy('ofx');
		if (!is_object($strategy)) {
			throw new Exception('Unsupported format ofx');
		}
		return $strategy->parse($this, $filePath, $fileName, $defaultCurrency);
	}

	/**
	 * Public format wrapper for MT940 strategy.
	 *
	 * @param string $filePath
	 * @param string $fileName
	 * @param string $defaultCurrency
	 * @return array<int,array<string,mixed>>
	 */
	public function parseFormatMt940($filePath, $fileName, $defaultCurrency = 'EUR')
	{
		$strategy = $this->resolveParseStrategy('mt940');
		if (!is_object($strategy)) {
			throw new Exception('Unsupported format mt940');
		}
		return $strategy->parse($this, $filePath, $fileName, $defaultCurrency);
	}

	/**
	 * Public format wrapper for Excel strategy.
	 *
	 * @param string $filePath
	 * @param string $fileName
	 * @param string $defaultCurrency
	 * @return array<int,array<string,mixed>>
	 */
	public function parseFormatExcel($filePath, $fileName, $defaultCurrency = 'EUR')
	{
		$strategy = $this->resolveParseStrategy('excel');
		if (!is_object($strategy)) {
			throw new Exception('Unsupported format excel');
		}
		return $strategy->parse($this, $filePath, $fileName, $defaultCurrency);
	}

	/**
	 * Public format wrapper for generic text exports.
	 *
	 * @param string $filePath
	 * @param string $fileName
	 * @param string $defaultCurrency
	 * @return array<int,array<string,mixed>>
	 */
	public function parseFormatDelimitedText($filePath, $fileName, $defaultCurrency = 'EUR')
	{
		$strategy = $this->resolveParseStrategy('tabulated');
		if (!is_object($strategy)) {
			throw new Exception('Unsupported format tabulated');
		}
		return $strategy->parse($this, $filePath, $fileName, $defaultCurrency);
	}

	/**
	 * Public format wrapper for Norma 43 strategy.
	 *
	 * @param string $filePath
	 * @param string $fileName
	 * @param string $defaultCurrency
	 * @return array<int,array<string,mixed>>
	 */
	public function parseFormatNorma43($filePath, $fileName, $defaultCurrency = 'EUR')
	{
		$strategy = $this->resolveParseStrategy('norma43');
		if (!is_object($strategy)) {
			throw new Exception('Unsupported format norma43');
		}
		return $strategy->parse($this, $filePath, $fileName, $defaultCurrency);
	}

	/**
	 * Public format wrapper for CSV strategy.
	 *
	 * @param string $filePath
	 * @param string $fileName
	 * @param string $defaultCurrency
	 * @return array<int,array<string,mixed>>
	 */
	public function parseFormatCsv($filePath, $fileName, $defaultCurrency = 'EUR')
	{
		$strategy = $this->resolveParseStrategy('csv');
		if (!is_object($strategy)) {
			throw new Exception('Unsupported format csv');
		}
		return $strategy->parse($this, $filePath, $fileName, $defaultCurrency);
	}

	/**
	 * Safe utility bridge so parse strategies can call parser helper methods.
	 *
	 * @param string $method
	 * @param mixed ...$args
	 * @return mixed
	 */
	public function callHelper($method, ...$args)
	{
		$method = (string) $method;
		$allowed = $this->getStrategyHelperAllowlist();
		if (!in_array($method, $allowed, true) || !method_exists($this, $method)) {
			throw new Exception('Invalid parser helper method: ' . $method);
		}

		return call_user_func_array(array($this, $method), $args);
	}

	/**
	 * Register an additional parser helper method callable from strategies.
	 *
	 * @param string $method
	 * @return bool
	 */
	public function registerStrategyHelper($method)
	{
		$method = trim((string) $method);
		if ($method === '' || !method_exists($this, $method)) {
			return false;
		}
		if (!in_array($method, $this->strategyHelperAllowlist, true)) {
			$this->strategyHelperAllowlist[] = $method;
		}

		return true;
	}

	/**
	 * Return strategy-callable parser helpers.
	 *
	 * @return array<int,string>
	 */
	protected function getStrategyHelperAllowlist()
	{
		$allowed = array(
			'detectFormat',
			'loadDelimitedRows',
			'parseTabularRows',
			'parseShortBankDate',
			'parseLocalizedNumber',
			'parseNorma43Amount',
			'normalizeIban',
			'parseDate',
			'detectCsvDelimiter',
			'isHeaderRow',
			'buildHeaderMap',
			'extractMapped',
			'looksLikeSpreadsheetXmlFile',
			'parseSpreadsheetXml',
			'parseExcelWithPhpSpreadsheet',
			'parseXlsxByZip',
			'parseXlsAsHtmlTable',
			'parseExcelByCommandConversion',
			'extractTagValue',
			'loadExcelRows',
			'loadCsvRows',
		);
		foreach ((array) $this->strategyHelperAllowlist as $extraMethod) {
			$extraMethod = trim((string) $extraMethod);
			if ($extraMethod === '' || in_array($extraMethod, $allowed, true)) {
				continue;
			}
			$allowed[] = $extraMethod;
		}

		return $allowed;
	}

	/**
	 * Analyze a statement file and return preview/mapping metadata.
	 *
	 * @param string $filePath
	 * @param string $fileName
	 * @param string $defaultCurrency
	 * @return array<string,mixed>
	 */
	public function analyze($filePath, $fileName, $defaultCurrency = 'EUR', $forceMappingEditor = false)
	{
		$format = $this->detectFormat($filePath, $fileName);
		$result = array(
			'format' => $format,
			'supports_mapping' => false,
			'mapping_forced' => false,
			'columns' => array(),
			'preview_rows' => array(),
			'sample_lines' => array(),
			'suggested_mapping' => $this->defaultMapping(),
			'header_row_index' => -1,
			'fingerprint' => '',
			'layout_signature' => '',
			'row_count' => 0,
		);

		if (!$this->isMappingSupportedFormat($format)) {
			if (!empty($forceMappingEditor)) {
				$forcedRows = $this->normalizeTabularRows($this->loadDelimitedRows($filePath));
				if ($this->hasUsableRowsForMapping($forcedRows)) {
					$headerRowIndex = $this->guessHeaderRowIndex($forcedRows);
					$headerMap = ($headerRowIndex >= 0 && isset($forcedRows[$headerRowIndex]))
						? $this->buildHeaderMap($forcedRows[$headerRowIndex])
						: array();
					$columns = $this->buildColumnDefinitions($forcedRows, $headerRowIndex);
					$suggestedMapping = $this->buildSuggestedMapping($headerMap, $columns, $forcedRows, $headerRowIndex);
					$suggestedMapping['header_row'] = $headerRowIndex;
					$sampleLines = array();
					$sampleRowCount = 0;
					try {
						$autoParsed = $this->parse($filePath, $fileName, $defaultCurrency);
						$sampleLines = array_slice((array) $autoParsed['lines'], 0, 20);
						$sampleRowCount = count((array) $autoParsed['lines']);
					} catch (Throwable $e) {
						if (function_exists('dol_syslog')) {
							dol_syslog('KreaBank parser analyze forced mapping fallback parse failed: ' . $e->getMessage(), LOG_WARNING);
						}
						$sampleLines = $this->parseTabularRowsWithMapping($forcedRows, $defaultCurrency, $suggestedMapping, 20);
						$sampleRowCount = count($forcedRows);
					}

					$result['supports_mapping'] = true;
					$result['mapping_forced'] = true;
					$result['preview_rows'] = array_slice($forcedRows, 0, 30);
					$result['row_count'] = $sampleRowCount;
					$result['columns'] = $columns;
					$result['header_row_index'] = $headerRowIndex;
					$result['suggested_mapping'] = $suggestedMapping;
					$result['sample_lines'] = $sampleLines;
					$result['fingerprint'] = $this->buildLayoutFingerprint($format, $forcedRows, $columns, $headerRowIndex);
					$result['layout_signature'] = $this->buildLayoutSignature($format, $columns, $headerRowIndex);
					return $result;
				}
			}

			$parsed = $this->parse($filePath, $fileName, $defaultCurrency);
			$result['sample_lines'] = array_slice((array) $parsed['lines'], 0, 20);
			$result['row_count'] = count((array) $parsed['lines']);
			$result['fingerprint'] = hash('sha256', $format . '|' . strtolower((string) $fileName));
			$result['layout_signature'] = hash('sha256', $format . '|' . strtolower((string) pathinfo((string) $fileName, PATHINFO_EXTENSION)));
			return $result;
		}

		$rows = ($format === 'excel')
			? $this->loadExcelRows($filePath, $fileName)
			: $this->loadCsvRows($filePath);
		$rows = $this->normalizeTabularRows($rows);

		$result['supports_mapping'] = true;
		$result['preview_rows'] = array_slice($rows, 0, 30);
		$result['row_count'] = count($rows);

		if (empty($rows)) {
			$result['fingerprint'] = hash('sha256', $format . '|empty');
			$result['layout_signature'] = hash('sha256', $format . '|empty');
			return $result;
		}

		$headerRowIndex = $this->guessHeaderRowIndex($rows);
		$headerMap = ($headerRowIndex >= 0 && isset($rows[$headerRowIndex]))
			? $this->buildHeaderMap($rows[$headerRowIndex])
			: array();
		$columns = $this->buildColumnDefinitions($rows, $headerRowIndex);
		$suggestedMapping = $this->buildSuggestedMapping($headerMap, $columns, $rows, $headerRowIndex);
		$suggestedMapping['header_row'] = $headerRowIndex;
		$sampleLines = $this->parseTabularRowsWithMapping($rows, $defaultCurrency, $suggestedMapping, 20);
		$sampleRowCount = max(0, count($rows) - (($headerRowIndex >= 0) ? ($headerRowIndex + 1) : 0));
		if (count($rows) <= 5000) {
			$mappedLines = $this->parseTabularRowsWithMapping($rows, $defaultCurrency, $suggestedMapping, 0);
			if (!empty($mappedLines)) {
				$sampleLines = array_slice((array) $mappedLines, 0, 20);
				$sampleRowCount = count((array) $mappedLines);
			}
		}
		if (empty($sampleLines)) {
			try {
				$autoParsed = $this->parse($filePath, $fileName, $defaultCurrency);
				$autoLines = (!empty($autoParsed['lines']) && is_array($autoParsed['lines'])) ? $autoParsed['lines'] : array();
				$sampleLines = array_slice($autoLines, 0, 20);
				if (!empty($autoLines)) {
					$sampleRowCount = count($autoLines);
				}
			} catch (Throwable $e) {
				if (function_exists('dol_syslog')) {
					dol_syslog('KreaBank parser analyze automatic parse fallback failed: ' . $e->getMessage(), LOG_WARNING);
				}
				$sampleLines = array();
			}
		}

		$result['columns'] = $columns;
		$result['header_row_index'] = $headerRowIndex;
		$result['suggested_mapping'] = $suggestedMapping;
		$result['sample_lines'] = $sampleLines;
		$result['row_count'] = $sampleRowCount;
		$result['fingerprint'] = $this->buildLayoutFingerprint($format, $rows, $columns, $headerRowIndex);
		$result['layout_signature'] = $this->buildLayoutSignature($format, $columns, $headerRowIndex);

		return $result;
	}

	/**
	 * Parse statement with explicit mapping (tabular formats only).
	 *
	 * @param string $filePath
	 * @param string $fileName
	 * @param array<string,mixed> $mapping
	 * @param string $defaultCurrency
	 * @return array{format:string,lines:array<int,array<string,mixed>>,raw:array<string,mixed>}
	 */
	public function parseWithMapping($filePath, $fileName, $mapping, $defaultCurrency = 'EUR')
	{
		$format = $this->detectFormat($filePath, $fileName);
		if (!$this->isMappingSupportedFormat($format)) {
			$rows = $this->normalizeTabularRows($this->loadDelimitedRows($filePath));
			if ($this->hasUsableRowsForMapping($rows)) {
				$lines = $this->parseTabularRowsWithMapping($rows, $defaultCurrency, $mapping, 0);
				return array(
					'format' => $format,
					'lines' => $lines,
					'raw' => array('filename' => $fileName, 'path' => $filePath),
				);
			}

			return $this->parse($filePath, $fileName, $defaultCurrency);
		}

		$rows = ($format === 'excel')
			? $this->loadExcelRows($filePath, $fileName)
			: $this->loadCsvRows($filePath);
		$rows = $this->normalizeTabularRows($rows);
		$lines = $this->parseTabularRowsWithMapping($rows, $defaultCurrency, $mapping, 0);

		return array(
			'format' => $format,
			'lines' => $lines,
			'raw' => array('filename' => $fileName, 'path' => $filePath),
		);
	}

	/**
	 * Check whether normalized rows are usable for mapping editor.
	 *
	 * @param array<int,array<int,string>> $rows
	 * @return bool
	 */
	protected function hasUsableRowsForMapping($rows)
	{
		if (!is_array($rows) || count($rows) < 2) {
			return false;
		}

		$multiColumnRows = 0;
		$maxColumns = 0;
		foreach (array_slice($rows, 0, 80) as $row) {
			$cols = is_array($row) ? count($row) : 0;
			$maxColumns = max($maxColumns, $cols);
			if ($cols >= 2) {
				$multiColumnRows++;
			}
		}

		return ($maxColumns >= 2 && $multiColumnRows >= 2);
	}

	/**
	 * Detect file format.
	 *
	 * @param string $filePath
	 * @param string $fileName
	 * @return string
	 */
	protected function detectFormat($filePath, $fileName)
	{
		$ext = strtolower(pathinfo((string) $fileName, PATHINFO_EXTENSION));
		$contentSampleRaw = $this->readFileContentSafe($filePath, 131072, 'detect_format');
		$contentDecoded = $this->decodeTextToUtf8((string) $contentSampleRaw);
		$contentHead = strtolower((string) $contentDecoded);
		$sampleLines = $this->extractSampleLines((string) $contentDecoded, 260);

		if ($ext === 'xlsx' || $ext === 'xls') {
			return 'excel';
		}
		if ($ext === 'xml' && $this->looksLikeCamt053Content($contentHead)) {
			return 'camt053';
		}
		if ($ext === 'ofx' && $this->looksLikeOfxContent($contentHead)) {
			return 'ofx';
		}
		if ($this->looksLikeCamt053Content($contentHead)) {
			return 'camt053';
		}
		if ($this->looksLikeOfxContent($contentHead)) {
			return 'ofx';
		}
		$detected = $this->detect_format($sampleLines);
		if ($detected === 'tabulated_excel') {
			return 'tabulated';
		}
		if ($detected !== 'csv') {
			return $detected;
		}
		if ($this->looksLikeDelimitedTabularContent($sampleLines)) {
			return 'tabulated';
		}
		// Fall back to CSV parser when no stronger signature is detected.
		return 'csv';
	}

	/**
	 * Extract non-empty sample lines from content.
	 *
	 * @param string $content
	 * @param int $maxLines
	 * @return array<int,string>
	 */
	protected function extractSampleLines($content, $maxLines = 120)
	{
		if (!is_string($content) || $content === '') {
			return array();
		}

		$rows = preg_split('/\r\n|\r|\n/', $content);
		if (!is_array($rows)) {
			return array();
		}

		$lines = array();
		foreach ($rows as $row) {
			$row = rtrim((string) $row);
			if ($row === '') {
				continue;
			}
			$lines[] = $row;
			if (count($lines) >= $maxLines) {
				break;
			}
		}

		return $lines;
	}

	/**
	 * Decode raw bytes to UTF-8 text (fallback ISO-8859-1).
	 *
	 * @param string $content
	 * @return string
	 */
	protected function decodeTextToUtf8($content)
	{
		$content = (string) $content;
		if ($content === '') {
			return '';
		}

		// UTF-8 BOM.
		if (strncmp($content, "\xEF\xBB\xBF", 3) === 0) {
			$content = substr($content, 3);
		}

		if (function_exists('mb_check_encoding') && function_exists('mb_convert_encoding')) {
			$bom2 = substr($content, 0, 2);
			if ($bom2 === "\xFF\xFE") {
				$converted = @mb_convert_encoding(substr($content, 2), 'UTF-8', 'UTF-16LE');
				if (is_string($converted) && $converted !== '') {
					return $converted;
				}
			}
			if ($bom2 === "\xFE\xFF") {
				$converted = @mb_convert_encoding(substr($content, 2), 'UTF-8', 'UTF-16BE');
				if (is_string($converted) && $converted !== '') {
					return $converted;
				}
			}

			// Heuristic-free UTF-16 detection by null-byte distribution.
			if (strpos($content, "\0") !== false) {
				$probe = substr($content, 0, 4096);
				$len = strlen($probe);
				$evenNulls = 0;
				$oddNulls = 0;
				for ($i = 0; $i < $len; $i++) {
					if ($probe[$i] === "\0") {
						if (($i % 2) === 0) {
							$evenNulls++;
						} else {
							$oddNulls++;
						}
					}
				}
				$nullDensity = ($len > 0 ? ((float) ($evenNulls + $oddNulls) / (float) $len) : 0.0);
				if ($len >= 16 && $nullDensity >= 0.20 && ($evenNulls > 0 || $oddNulls > 0)) {
					$sourceEncoding = ($oddNulls >= $evenNulls) ? 'UTF-16LE' : 'UTF-16BE';
					$converted = @mb_convert_encoding($content, 'UTF-8', $sourceEncoding);
					if (is_string($converted) && $converted !== '') {
						return $converted;
					}
				}
			}

			if (!mb_check_encoding($content, 'UTF-8')) {
				$converted = @mb_convert_encoding($content, 'UTF-8', 'ISO-8859-1');
				if (is_string($converted) && $converted !== '') {
					return $converted;
				}

				$converted = @mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
				if (is_string($converted) && $converted !== '') {
					return $converted;
				}
			}
		}
		return $content;
	}

	/**
	 * Load statement file lines using UTF-8 decoding fallback.
	 *
	 * @param string $filePath
	 * @return array<int,string>
	 */
	protected function loadDecodedTextLines($filePath)
	{
		$content = $this->readFileContentSafe($filePath, 0, 'load_decoded_lines');
		if (!is_string($content) || $content === '') {
			return array();
		}
		$content = $this->decodeTextToUtf8($content);
		return $this->extractSampleLines($content, 500000);
	}

	/**
	 * Map unified parser transactions to legacy line payload.
	 *
	 * @param array<int,array<string,mixed>> $transactions
	 * @return array<int,array<string,mixed>>
	 */
	protected function mapNormalizedTransactionsToLegacyLines($transactions)
	{
		$lines = array();
		$rank = 0;
		foreach ((array) $transactions as $txn) {
			if (!is_array($txn) || empty($txn)) {
				continue;
			}
			$bookingDate = !empty($txn['booking_date']) ? (string) $txn['booking_date'] : '';
			$amount = isset($txn['amount']) && is_numeric($txn['amount']) ? (float) $txn['amount'] : null;
			if ($bookingDate === '' || $amount === null) {
				continue;
			}

			$reference = !empty($txn['reference']) ? trim((string) $txn['reference']) : '';
			$description = isset($txn['description']) ? (string) $txn['description'] : '';
			$lineUid = sha1(implode('|', array(
				$rank,
				$bookingDate,
				sprintf('%.2f', (float) $amount),
				$reference,
				$description,
			)));

			$lines[] = array(
				'line_rank' => $rank,
				'operation_date' => $bookingDate,
				'value_date' => (!empty($txn['value_date']) ? (string) $txn['value_date'] : $bookingDate),
				'amount' => (float) $amount,
				'currency' => !empty($txn['currency']) ? strtoupper((string) $txn['currency']) : 'EUR',
				'running_balance' => (isset($txn['running_balance']) && $txn['running_balance'] !== null)
					? (float) $txn['running_balance']
					: null,
				'counterparty_name' => !empty($txn['counterparty_name']) ? (string) $txn['counterparty_name'] : '',
				'counterparty_iban' => !empty($txn['counterparty_iban']) ? (string) $txn['counterparty_iban'] : '',
				'description' => $description,
				'payment_reference' => $reference,
				'bank_reference' => $reference,
				'direction' => ((float) $amount >= 0 ? 1 : -1),
				'line_uid' => $lineUid,
				'raw' => (isset($txn['raw']) && is_array($txn['raw'])) ? $txn['raw'] : array(),
			);
			$rank++;
		}
		return $lines;
	}

	/**
	 * Detect CAMT.053 XML by content markers.
	 *
	 * @param string $contentHead
	 * @return bool
	 */
	protected function looksLikeCamt053Content($contentHead)
	{
		$contentHead = strtolower((string) $contentHead);
		if ($contentHead === '') {
			return false;
		}

		return (
			strpos($contentHead, '<bkttocstmrstmt') !== false
			|| strpos($contentHead, 'camt.053') !== false
			|| strpos($contentHead, 'urn:iso:std:iso:20022:tech:xsd:camt.053') !== false
		);
	}

	/**
	 * Detect OFX format by content markers.
	 *
	 * @param string $contentHead
	 * @return bool
	 */
	protected function looksLikeOfxContent($contentHead)
	{
		$contentHead = strtolower((string) $contentHead);
		if ($contentHead === '') {
			return false;
		}

		return (strpos($contentHead, '<ofx') !== false || strpos($contentHead, 'ofxheader:') !== false);
	}

	/**
	 * Detect MT940 by tags/signatures.
	 *
	 * @param string $contentHead
	 * @param array<int,string> $sampleLines
	 * @return bool
	 */
	protected function looksLikeMt940Content($contentHead, $sampleLines)
	{
		$contentHead = strtolower((string) $contentHead);
		if ($contentHead !== '' && preg_match('/^:20:/m', $contentHead) && preg_match('/^:61:/m', $contentHead)) {
			return true;
		}

		$has20 = false;
		$has61 = false;
		$scan = 0;
		foreach ((array) $sampleLines as $line) {
			$line = trim((string) $line);
			if ($line === '') {
				continue;
			}
			$scan++;
			if (strpos($line, ':20:') === 0) {
				$has20 = true;
			} elseif (strpos($line, ':61:') === 0) {
				$has61 = true;
			}
			if ($has20 && $has61) {
				return true;
			}
			if ($scan >= 80) {
				break;
			}
		}

		return false;
	}

	/**
	 * Infer Norma 43 variant from content.
	 *
	 * @param array<int,string> $sampleLines
	 * @param string $fileTag
	 * @return string
	 */
	protected function inferNorma43Variant($sampleLines, $fileTag = '')
	{
		$recordHits = 0;
		$reg24Hits = 0;
		$seen978 = false;

		foreach ((array) $sampleLines as $line) {
			$line = rtrim((string) $line);
			if ($line === '') {
				continue;
			}

			if (preg_match('/^(11|22|23|24|33|88)\s*[0-9A-Z]{6,}/', $line, $m)) {
				$recordHits++;
				if ((string) $m[1] === '24') {
					$reg24Hits++;
				}
				if ((string) $m[1] === '11' && preg_match('/978\s*[0-9]*\s*$/', $line)) {
					$seen978 = true;
				}
			}
		}

		if ($recordHits >= 3) {
			if ($reg24Hits > 0 || strpos((string) $fileTag, 'registo 24') !== false || strpos((string) $fileTag, 'registo24') !== false) {
				return 'norma43_r24';
			}
			if ($seen978 || strpos((string) $fileTag, '978') !== false) {
				return 'norma43_978';
			}
			return 'norma43';
		}

		return '';
	}

	/**
	 * Detect EMC fixed-width layout.
	 *
	 * @param array<int,string> $sampleLines
	 * @param string $fileTag
	 * @return bool
	 */
	protected function looksLikeEmcContent($sampleLines, $fileTag = '')
	{
		$emcHits = 0;
		foreach ((array) $sampleLines as $line) {
			if (preg_match('/^EMC(13|23)\d+/', (string) $line)) {
				$emcHits++;
				if ($emcHits >= 2) {
					return true;
				}
			}
		}

		return (bool) preg_match('/(^|[^a-z])emc([^a-z]|$)/', strtolower((string) $fileTag));
	}

	/**
	 * Detect BIT reconciliation fixed layout.
	 *
	 * @param array<int,string> $sampleLines
	 * @param string $fileTag
	 * @return bool
	 */
	protected function looksLikeBitReconContent($sampleLines, $fileTag = '')
	{
		$hits = 0;
		foreach ((array) $sampleLines as $line) {
			if (preg_match('/^\d{11}\s{2}\d{8}\d{6}.+[CD]\d{15}[CD]\d{15}[CD]\d{4}[A-Z]{3}$/', (string) $line)) {
				$hits++;
				if ($hits >= 2) {
					return true;
				}
			}
		}

		$fileTag = strtolower((string) $fileTag);
		return (strpos($fileTag, 'bit reconcilia') !== false || strpos($fileTag, 'bit_recon') !== false || strpos($fileTag, 'bit_reconciliacao') !== false);
	}

	/**
	 * Detect consolidated fixed-width layout.
	 *
	 * @param array<int,string> $sampleLines
	 * @param string $fileTag
	 * @return bool
	 */
	protected function looksLikeConsolidatedContent($sampleLines, $fileTag = '')
	{
		$has01 = false;
		$has03 = false;
		foreach ((array) $sampleLines as $line) {
			$line = (string) $line;
			if (preg_match('/^01\d{4}/', $line)) {
				$has01 = true;
			}
			if (preg_match('/^03\d{4}/', $line)) {
				$has03 = true;
			}
			if ($has01 && $has03) {
				return true;
			}
		}

		return (strpos(strtolower((string) $fileTag), 'consolidado') !== false);
	}

	/**
	 * Detect NIB compound fixed-width layout.
	 *
	 * @param array<int,string> $sampleLines
	 * @param string $fileTag
	 * @return bool
	 */
	protected function looksLikeNibCompoundContent($sampleLines, $fileTag = '')
	{
		$hits = 0;
		foreach ((array) $sampleLines as $line) {
			$line = (string) $line;
			if (preg_match('/^5\d{6}\d{6}.+[+-]\d{5,},\d{2,3}\s*$/', $line)) {
				$hits++;
				if ($hits >= 2) {
					return true;
				}
			}
		}

		$fileTag = strtolower((string) $fileTag);
		return (strpos($fileTag, 'composto com nib') !== false || strpos($fileTag, 'composto_nib') !== false || strpos($fileTag, 'nib_composto') !== false);
	}

	/**
	 * Detect generic delimited tabular files.
	 *
	 * @param array<int,string> $sampleLines
	 * @return bool
	 */
	protected function looksLikeDelimitedTabularContent($sampleLines)
	{
		$delimiters = array(';', "\t", '|', ',');
		foreach ($delimiters as $delimiter) {
			$rows = 0;
			$consistent = 0;
			$lastFields = -1;
			foreach ((array) $sampleLines as $line) {
				$line = (string) $line;
				$count = substr_count($line, $delimiter);
				if ($count < 1) {
					continue;
				}
				$fields = $count + 1;
				$rows++;
				if ($lastFields > 0 && abs($fields - $lastFields) <= 2) {
					$consistent++;
				}
				$lastFields = $fields;
				if ($rows >= 20) {
					break;
				}
			}
			if ($rows >= 3 && $consistent >= 2) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check if format supports interactive field mapping.
	 *
	 * @param string $format
	 * @return bool
	 */
	protected function isMappingSupportedFormat($format)
	{
		return in_array((string) $format, array(
			'csv',
			'excel',
			'tabulated',
		), true);
	}

	/**
	 * Lightweight detector for Norma 43 text layout.
	 *
	 * @param string $contentHead
	 * @return bool
	 */
	protected function looksLikeNorma43Content($contentHead)
	{
		$sampleLines = $this->extractSampleLines((string) $contentHead, 150);
		return $this->inferNorma43Variant($sampleLines) !== '';
	}

	/**
	 * Load rows from generic text exports.
	 *
	 * @param string $filePath
	 * @return array<int,array<int,string>>
	 */
	protected function loadDelimitedRows($filePath)
	{
		$content = $this->readFileContentSafe($filePath, 0, 'load_delimited_rows');
		if ($content === '') {
			return array();
		}
		$content = $this->decodeTextToUtf8((string) $content);

		$rawLines = preg_split('/\r\n|\r|\n/', $content);
		if (!is_array($rawLines)) {
			return array();
		}

		$sample = implode("\n", array_slice(array_values(array_filter($rawLines, static function ($line) {
			return trim((string) $line) !== '';
		})), 0, 20));

		$delimiters = array(';', ',', "\t", '|');
		$bestDelimiter = '';
		$bestScore = 0;
		foreach ($delimiters as $delimiter) {
			$score = substr_count($sample, $delimiter);
			if ($score > $bestScore) {
				$bestScore = $score;
				$bestDelimiter = $delimiter;
			}
		}

		$rows = array();
		if ($bestDelimiter !== '' && $bestScore > 0) {
			$handle = fopen('php://temp', 'r+');
			if (!is_resource($handle)) {
				return array();
			}
			fwrite($handle, $content);
			rewind($handle);

			while (($row = fgetcsv($handle, 0, $bestDelimiter, '"', '\\')) !== false) {
				if (!is_array($row)) {
					continue;
				}
				$rows[] = array_map(static function ($value) {
					return trim((string) $value);
				}, $row);
			}
			fclose($handle);
			return $rows;
		}

		foreach ($rawLines as $line) {
			$line = trim((string) $line);
			if ($line === '') {
				continue;
			}

			$row = preg_split('/\s{2,}|\t+/', $line);
			if (!is_array($row) || count($row) < 2) {
				continue;
			}
			$rows[] = array_map(static function ($value) {
				return trim((string) $value);
			}, $row);
		}

		return $rows;
	}

	/**
	 * Parse Norma 43 amount token.
	 *
	 * @param string $amountRaw
	 * @param string $signToken
	 * @return float|null
	 */
	protected function parseNorma43Amount($amountRaw, $signToken = '')
	{
		$amountRaw = trim((string) $amountRaw);
		if ($amountRaw === '') {
			return null;
		}

		$sign = 0;
		$normalizedSign = strtoupper(trim((string) $signToken));
		if ($normalizedSign !== '') {
			if (strpos($normalizedSign, 'D') !== false || strpos($normalizedSign, '-') !== false || strpos($normalizedSign, '1') !== false) {
				$sign = -1;
			}
			if (strpos($normalizedSign, 'C') !== false || strpos($normalizedSign, '+') !== false || strpos($normalizedSign, '2') !== false) {
				$sign = 1;
			}
		}

		$value = null;
		if (preg_match('/^-?\d+$/', $amountRaw)) {
			$value = ((float) $amountRaw) / 100;
		} else {
			$value = $this->parseLocalizedNumber($amountRaw);
		}

		if ($value === null) {
			return null;
		}

		$value = (float) $value;
		if ($sign < 0) {
			return -abs($value);
		}
		if ($sign > 0) {
			return abs($value);
		}

		return $value;
	}

	/**
	 * Parse bank short date formats (YYMMDD).
	 *
	 * @param string $value
	 * @return string|null
	 */
	protected function parseShortBankDate($value)
	{
		$value = trim((string) $value);
		if ($value === '') {
			return null;
		}

		if (preg_match('/^\d{6}$/', $value)) {
			$year = (int) substr($value, 0, 2);
			$month = (int) substr($value, 2, 2);
			$day = (int) substr($value, 4, 2);
			$fullYear = ($year >= 70) ? (1900 + $year) : (2000 + $year);
			if (checkdate($month, $day, $fullYear)) {
				return sprintf('%04d-%02d-%02d', $fullYear, $month, $day);
			}
		}

		return $this->parseDate($value);
	}

	/**
	 * Parse Excel using PhpSpreadsheet when available.
	 *
	 * @param string $filePath
	 * @param string $defaultCurrency
	 * @return array<int,array<string,mixed>>
	 */
	protected function parseExcelWithPhpSpreadsheet($filePath, $defaultCurrency)
	{
		$rows = $this->loadExcelRowsWithPhpSpreadsheet($filePath);
		return $this->parseTabularRows($rows, $defaultCurrency);
	}

	/**
	 * Attempt to load PhpSpreadsheet autoloader from common Dolibarr paths.
	 *
	 * @return void
	 */
	protected function loadPhpSpreadsheetAutoloader()
	{
		$psrAutoloaders = array(
			DOL_DOCUMENT_ROOT . '/includes/Psr/autoloader.php',
			DOL_DOCUMENT_ROOT . '/includes/psr/autoloader.php',
		);
		foreach ($psrAutoloaders as $psrAutoloader) {
			if (file_exists($psrAutoloader)) {
				require_once $psrAutoloader;
			}
		}

		$candidates = array(
			DOL_DOCUMENT_ROOT . '/includes/phpoffice/vendor/autoload.php',
			DOL_DOCUMENT_ROOT . '/includes/phpoffice/phpspreadsheet/vendor/autoload.php',
			DOL_DOCUMENT_ROOT . '/includes/phpoffice/PhpSpreadsheet/vendor/autoload.php',
			DOL_DOCUMENT_ROOT . '/includes/phpoffice/phpspreadsheet/src/autoloader.php',
			DOL_DOCUMENT_ROOT . '/includes/phpoffice/phpspreadsheet/src/Bootstrap.php',
			DOL_DOCUMENT_ROOT . '/includes/PhpSpreadsheet/vendor/autoload.php',
			DOL_DOCUMENT_ROOT . '/vendor/autoload.php',
		);

		foreach ($candidates as $autoloadPath) {
			if (file_exists($autoloadPath)) {
				require_once $autoloadPath;
				return;
			}
		}
	}

	/**
	 * Parse XLSX directly from ZIP/XML structure.
	 *
	 * @param string $filePath
	 * @param string $defaultCurrency
	 * @return array<int,array<string,mixed>>
	 */
	protected function parseXlsxByZip($filePath, $defaultCurrency)
	{
		$rows = $this->loadXlsxRowsByZip($filePath);
		return $this->parseTabularRows($rows, $defaultCurrency);
	}

	/**
	 * Read XLSX shared strings table.
	 *
	 * @param ZipArchive $zip
	 * @return array<int,string>
	 */
	protected function readXlsxSharedStrings($zip)
	{
		$shared = array();
		$sharedXmlTemp = $this->copyZipEntryToTempFile($zip, 'xl/sharedStrings.xml', 'kreastr_');
		if ($sharedXmlTemp !== '' && class_exists('XMLReader')) {
			$reader = new XMLReader();
			$flags = LIBXML_NONET | LIBXML_COMPACT | LIBXML_NOERROR | LIBXML_NOWARNING;
			if (@$reader->open($sharedXmlTemp, null, $flags)) {
				$insideString = false;
				$current = '';
				while (@$reader->read()) {
					if ($reader->nodeType === XMLReader::ELEMENT) {
						$nodeName = (string) $reader->localName;
						if ($nodeName === 'si') {
							$insideString = true;
							$current = '';
							if ($reader->isEmptyElement) {
								$shared[] = '';
								$insideString = false;
							}
						} elseif ($insideString && $nodeName === 't') {
							$current .= (string) $reader->readString();
						}
					} elseif ($reader->nodeType === XMLReader::END_ELEMENT && (string) $reader->localName === 'si') {
						$shared[] = $current;
						$insideString = false;
					}
				}
				$reader->close();
			}
			$this->removeTempFile($sharedXmlTemp, 'xlsx_shared_strings');
			if (!empty($shared)) {
				return $shared;
			}
		}

		$sharedXmlContent = $zip->getFromName('xl/sharedStrings.xml');
		if ($sharedXmlContent === false) {
			return $shared;
		}

		$sharedXmlContent = preg_replace('/\sxmlns(:[A-Za-z0-9_]+)?="[^"]*"/', '', (string) $sharedXmlContent);
		$sharedXmlContent = preg_replace('/(<\/?)[A-Za-z0-9_]+:/', '$1', (string) $sharedXmlContent);
		$sharedXml = @simplexml_load_string((string) $sharedXmlContent);
		if (!$sharedXml || !isset($sharedXml->si)) {
			return $shared;
		}

		foreach ($sharedXml->si as $si) {
			$value = '';
			if (isset($si->t)) {
				$value = (string) $si->t;
			}
			if ($value === '' && isset($si->r)) {
				foreach ($si->r as $run) {
					$value .= (string) $run->t;
				}
			}
			$shared[] = $value;
		}

		return $shared;
	}

	/**
	 * Parse legacy XLS exported as HTML table.
	 *
	 * @param string $filePath
	 * @param string $defaultCurrency
	 * @return array<int,array<string,mixed>>
	 */
	protected function parseXlsAsHtmlTable($filePath, $defaultCurrency)
	{
		$rows = $this->loadXlsRowsAsHtmlTable($filePath);
		return $this->parseTabularRows($rows, $defaultCurrency);
	}

	/**
	 * Parse Excel by converting to CSV with local command tools when available.
	 *
	 * @param string $filePath
	 * @param string $defaultCurrency
	 * @return array<int,array<string,mixed>>
	 */
	protected function parseExcelByCommandConversion($filePath, $defaultCurrency)
	{
		$rows = $this->loadExcelRowsByCommandConversion($filePath);
		return $this->parseTabularRows($rows, $defaultCurrency);
	}

	/**
	 * Load raw CSV rows.
	 *
	 * @param string $filePath
	 * @return array<int,array<int,string>>
	 */
	protected function loadCsvRows($filePath)
	{
		$delimiter = $this->detectCsvDelimiter($filePath);
		$content = $this->readFileContentSafe($filePath, 0, 'load_csv_rows');
		if (!is_string($content) || $content === '') {
			throw new Exception('Unable to open CSV file');
		}
		$content = $this->decodeTextToUtf8($content);
		$content = str_replace("\0", '', $content);

		$handle = fopen('php://temp', 'r+');
		if (!is_resource($handle)) {
			throw new Exception('Unable to open CSV file');
		}
		fwrite($handle, $content);
		rewind($handle);

		$rows = array();
		while (($row = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
			if (!is_array($row)) {
				continue;
			}
			$rows[] = array_map(static function ($value) {
				return trim((string) $value);
			}, $row);
		}
		fclose($handle);

		return $rows;
	}

	/**
	 * Load raw rows from Excel file (XLS/XLSX).
	 *
	 * @param string $filePath
	 * @param string $fileName
	 * @return array<int,array<int,mixed>>
	 */
	protected function loadExcelRows($filePath, $fileName)
	{
		$ext = strtolower(pathinfo((string) $fileName, PATHINFO_EXTENSION));
		if ($ext === 'xls' && $this->looksLikeSpreadsheetXmlFile($filePath)) {
			$rows = $this->loadSpreadsheetXmlRows($filePath);
			if (!empty($rows)) {
				return $rows;
			}
		}

		if ($ext === 'xlsx') {
			$rows = $this->loadXlsxRowsByZip($filePath);
			if (!empty($rows)) {
				return $rows;
			}
		}

		$rows = $this->loadExcelRowsWithPhpSpreadsheet($filePath);
		if (!empty($rows)) {
			return $rows;
		}

		$rows = $this->loadSpreadsheetXmlRows($filePath);
		if (!empty($rows)) {
			return $rows;
		}

		if ($ext === 'xls') {
			$rows = $this->loadXlsRowsAsHtmlTable($filePath);
			if (!empty($rows)) {
				return $rows;
			}
		}

		$rows = $this->loadExcelRowsByCommandConversion($filePath);
		if (!empty($rows)) {
			return $rows;
		}

		return array();
	}

	/**
	 * Load rows from Excel using PhpSpreadsheet.
	 *
	 * @param string $filePath
	 * @return array<int,array<int,mixed>>
	 */
	protected function loadExcelRowsWithPhpSpreadsheet($filePath)
	{
		$this->loadPhpSpreadsheetAutoloader();
		$ioFactoryClass = '\\PhpOffice\\PhpSpreadsheet\\IOFactory';
		if (!class_exists($ioFactoryClass)) {
			return array();
		}

		try {
			$spreadsheet = $ioFactoryClass::load($filePath);
		} catch (Throwable $e) {
			if (function_exists('dol_syslog')) {
				dol_syslog('KreaBank Excel PhpSpreadsheet load failed: ' . $e->getMessage(), LOG_WARNING);
			}
			return array();
		}

		if (!is_object($spreadsheet) || !method_exists($spreadsheet, 'getSheet')) {
			return array();
		}

		if (method_exists($spreadsheet, 'getSheetCount')) {
			$sheetCount = (int) $spreadsheet->getSheetCount();
			if ($sheetCount <= 0) {
				return array();
			}
		}

		try {
			$sheet = $spreadsheet->getSheet(0);
		} catch (Throwable $e) {
			if (function_exists('dol_syslog')) {
				dol_syslog('KreaBank Excel PhpSpreadsheet sheet read failed: ' . $e->getMessage(), LOG_WARNING);
			}
			return array();
		}

		if (!is_object($sheet) || !method_exists($sheet, 'toArray')) {
			return array();
		}

		try {
			$rows = $this->extractPhpSpreadsheetRows($sheet);
		} catch (Throwable $e) {
			if (function_exists('dol_syslog')) {
				dol_syslog('KreaBank Excel PhpSpreadsheet row extraction failed: ' . $e->getMessage(), LOG_WARNING);
			}
			try {
				$rows = $sheet->toArray('', true, true, false);
			} catch (Throwable $fallbackException) {
				if (function_exists('dol_syslog')) {
					dol_syslog('KreaBank Excel PhpSpreadsheet toArray failed: ' . $fallbackException->getMessage(), LOG_WARNING);
				}
				return array();
			}
		}
		return is_array($rows) ? $rows : array();
	}

	/**
	 * Extract first-sheet rows from PhpSpreadsheet while canonicalizing date cells.
	 *
	 * Date-formatted numeric cells are converted to ISO strings before the generic
	 * tabular parser sees them. This avoids locale-dependent `m/d/yyyy` formatting
	 * leaking into `parseDate()` and producing wrong month/year rollovers.
	 *
	 * @param object $sheet
	 * @return array<int,array<int,string>>
	 */
	protected function extractPhpSpreadsheetRows($sheet)
	{
		if (!is_object($sheet) || !method_exists($sheet, 'getHighestDataRow') || !method_exists($sheet, 'getHighestDataColumn')) {
			return $sheet->toArray('', true, true, false);
		}

		$highestRow = (int) $sheet->getHighestDataRow();
		$highestColumn = (string) $sheet->getHighestDataColumn();
		$highestColumnIndex = $this->columnIndexFromCellRef($highestColumn . '1');
		if ($highestRow <= 0 || $highestColumnIndex < 0) {
			return array();
		}

		$rows = array();
		for ($rowIndex = 1; $rowIndex <= $highestRow; $rowIndex++) {
			$row = array();
			for ($columnIndex = 1; $columnIndex <= ($highestColumnIndex + 1); $columnIndex++) {
				if (method_exists($sheet, 'cellExistsByColumnAndRow') && !$sheet->cellExistsByColumnAndRow($columnIndex, $rowIndex)) {
					$row[] = '';
					continue;
				}

				$cell = $sheet->getCellByColumnAndRow($columnIndex, $rowIndex);
				$row[] = $this->normalizePhpSpreadsheetCellValue($sheet, $cell);
			}
			$rows[] = $row;
		}

		return $rows;
	}

	/**
	 * Normalize one PhpSpreadsheet cell into a stable scalar string.
	 *
	 * @param object $sheet
	 * @param object $cell
	 * @return string
	 */
	protected function normalizePhpSpreadsheetCellValue($sheet, $cell)
	{
		if (!is_object($cell)) {
			return '';
		}

		$rawValue = null;
		if (method_exists($cell, 'getValue')) {
			$rawValue = $cell->getValue();
		}

		$resolvedValue = $rawValue;
		if (is_string($rawValue) && $rawValue !== '' && substr($rawValue, 0, 1) === '=' && method_exists($cell, 'getCalculatedValue')) {
			try {
				$resolvedValue = $cell->getCalculatedValue();
			} catch (Throwable $e) {
				$resolvedValue = $rawValue;
			}
		}

		$formatCode = $this->getPhpSpreadsheetCellFormatCode($sheet, $cell);
		if ($resolvedValue instanceof DateTimeInterface) {
			return $resolvedValue->format('Y-m-d');
		}
		if ($this->isPhpSpreadsheetDateCell($resolvedValue, $formatCode)) {
			$normalizedDate = $this->convertPhpSpreadsheetExcelDate($resolvedValue);
			if ($normalizedDate !== null) {
				return $normalizedDate;
			}
		}

		$formattedValue = $resolvedValue;
		if (method_exists($cell, 'getFormattedValue')) {
			try {
				$formattedValue = $cell->getFormattedValue();
			} catch (Throwable $e) {
				$formattedValue = $resolvedValue;
			}
		}

		if ($formattedValue instanceof DateTimeInterface) {
			return $formattedValue->format('Y-m-d');
		}
		if (is_bool($formattedValue)) {
			return $formattedValue ? '1' : '0';
		}
		if (is_scalar($formattedValue) || $formattedValue === null) {
			return trim((string) $formattedValue);
		}

		return '';
	}

	/**
	 * Read number-format code for one PhpSpreadsheet cell.
	 *
	 * @param object $sheet
	 * @param object $cell
	 * @return string
	 */
	protected function getPhpSpreadsheetCellFormatCode($sheet, $cell)
	{
		if (!is_object($sheet) || !is_object($cell) || !method_exists($sheet, 'getStyle') || !method_exists($cell, 'getCoordinate')) {
			return '';
		}

		try {
			$style = $sheet->getStyle($cell->getCoordinate());
			if (!is_object($style) || !method_exists($style, 'getNumberFormat')) {
				return '';
			}
			$numberFormat = $style->getNumberFormat();
			if (!is_object($numberFormat) || !method_exists($numberFormat, 'getFormatCode')) {
				return '';
			}

			return trim((string) $numberFormat->getFormatCode());
		} catch (Throwable $e) {
			return '';
		}
	}

	/**
	 * Detect whether a PhpSpreadsheet cell represents an Excel date serial.
	 *
	 * @param mixed $value
	 * @param string $formatCode
	 * @return bool
	 */
	protected function isPhpSpreadsheetDateCell($value, $formatCode)
	{
		if (!is_numeric($value)) {
			return false;
		}

		$formatCode = trim((string) $formatCode);
		if ($formatCode === '' || strcasecmp($formatCode, 'General') === 0 || $formatCode === '@') {
			return false;
		}

		$probe = strtolower($formatCode);
		$probe = preg_replace('/;.*$/', '', $probe);
		$probe = preg_replace('/"[^"]*"/', '', (string) $probe);
		$probe = preg_replace('/\[[^\]]*\]/', '', (string) $probe);
		$probe = preg_replace('/\\\\./', '', (string) $probe);
		$probe = str_replace(array('_', '*'), '', (string) $probe);
		$probe = trim((string) $probe);
		if ($probe === '') {
			return false;
		}

		if (strpos($probe, 'am/pm') !== false) {
			return true;
		}
		if (preg_match('/(^|[^a-z])(yy(?:yy)?|dd?|hh?|ss?)([^a-z]|$)/', $probe)) {
			return true;
		}
		if (preg_match('/(^|[^a-z])m{3,}([^a-z]|$)/', $probe)) {
			return true;
		}
		if (preg_match('/[dmy].*[\/\-.].*[dmy]/', $probe)) {
			return true;
		}
		if (preg_match('/[hms].*:.*[hms]/', $probe)) {
			return true;
		}

		return false;
	}

	/**
	 * Convert Excel numeric serial date to ISO day string.
	 *
	 * @param mixed $value
	 * @return string|null
	 */
	protected function convertPhpSpreadsheetExcelDate($value)
	{
		if (!is_numeric($value)) {
			return null;
		}

		$dateClass = '\\PhpOffice\\PhpSpreadsheet\\Shared\\Date';
		if (class_exists($dateClass) && method_exists($dateClass, 'excelToDateTimeObject')) {
			try {
				$dateObject = $dateClass::excelToDateTimeObject((float) $value, 'UTC');
				if ($dateObject instanceof DateTimeInterface) {
					return $dateObject->format('Y-m-d');
				}
			} catch (Throwable $e) {
				// Fall through to generic serial handling below.
			}
		}

		$excelSerial = (float) $value;
		if ($excelSerial <= 0 || $excelSerial >= 90000) {
			return null;
		}

		$timestamp = (int) round(($excelSerial - 25569) * 86400);
		if ($timestamp <= 0) {
			return null;
		}

		return gmdate('Y-m-d', $timestamp);
	}

	/**
	 * Load rows from XLSX ZIP structure.
	 *
	 * @param string $filePath
	 * @return array<int,array<int,string>>
	 */
	protected function loadXlsxRowsByZip($filePath)
	{
		if (!class_exists('ZipArchive')) {
			return array();
		}

		$zip = new ZipArchive();
		if ($zip->open($filePath) !== true) {
			return array();
		}

		$sheetPath = '';
		for ($i = 0; $i < $zip->numFiles; $i++) {
			$name = (string) $zip->getNameIndex($i);
			if ($name !== '' && preg_match('#^xl/worksheets/[^/]+\.xml$#', $name)) {
				$sheetPath = $name;
				break;
			}
		}
		if ($sheetPath === '') {
			$sheetPath = 'xl/worksheets/sheet1.xml';
		}

		$sharedStrings = $this->readXlsxSharedStrings($zip);
		$rows = array();
		$sheetXmlTemp = $this->copyZipEntryToTempFile($zip, $sheetPath, 'kreasht_');
		if ($sheetXmlTemp !== '' && class_exists('XMLReader')) {
			$reader = new XMLReader();
			$flags = LIBXML_NONET | LIBXML_COMPACT | LIBXML_NOERROR | LIBXML_NOWARNING;
			if (@$reader->open($sheetXmlTemp, null, $flags)) {
				while (@$reader->read()) {
					if ($reader->nodeType !== XMLReader::ELEMENT || (string) $reader->localName !== 'row') {
						continue;
					}
					if ($reader->isEmptyElement) {
						continue;
					}

					$row = array();
					$maxCol = -1;
					$rowDepth = $reader->depth;

					while (@$reader->read()) {
						if ($reader->nodeType === XMLReader::END_ELEMENT && (string) $reader->localName === 'row' && $reader->depth === $rowDepth) {
							break;
						}
						if ($reader->nodeType !== XMLReader::ELEMENT || (string) $reader->localName !== 'c') {
							continue;
						}

						$cellRef = (string) $reader->getAttribute('r');
						$colIndex = $this->columnIndexFromCellRef($cellRef);
						if ($colIndex < 0) {
							$colIndex = $maxCol + 1;
						}
						$maxCol = max($maxCol, $colIndex);

						$type = (string) $reader->getAttribute('t');
						$value = '';
						if (!$reader->isEmptyElement) {
							$cellDepth = $reader->depth;
							while (@$reader->read()) {
								if ($reader->nodeType === XMLReader::END_ELEMENT && (string) $reader->localName === 'c' && $reader->depth === $cellDepth) {
									break;
								}
								if ($reader->nodeType !== XMLReader::ELEMENT) {
									continue;
								}
								$cellNodeName = (string) $reader->localName;
								if ($cellNodeName === 'v') {
									$value = (string) $reader->readString();
								} elseif ($type === 'inlineStr' && $cellNodeName === 't') {
									$value .= (string) $reader->readString();
								}
							}
						}

						if ($type === 's') {
							$stringIndex = (int) $value;
							$value = isset($sharedStrings[$stringIndex]) ? $sharedStrings[$stringIndex] : '';
						}

						$row[$colIndex] = $value;
					}

					if ($maxCol >= 0) {
						$normalizedRow = array();
						for ($i = 0; $i <= $maxCol; $i++) {
							$normalizedRow[] = isset($row[$i]) ? $row[$i] : '';
						}
						$rows[] = $normalizedRow;
					}
				}
				$reader->close();
			}
			$this->removeTempFile($sheetXmlTemp, 'xlsx_sheet_xml');
		}

		if (!empty($rows)) {
			$zip->close();
			return $rows;
		}

		$sheetXmlContent = $zip->getFromName($sheetPath);
		if ($sheetXmlContent === false) {
			$zip->close();
			return array();
		}

		$sheetXmlContent = preg_replace('/\sxmlns(:[A-Za-z0-9_]+)?="[^"]*"/', '', (string) $sheetXmlContent);
		$sheetXmlContent = preg_replace('/(<\/?)[A-Za-z0-9_]+:/', '$1', (string) $sheetXmlContent);
		$sheetXml = @simplexml_load_string((string) $sheetXmlContent);
		if (!$sheetXml || !isset($sheetXml->sheetData)) {
			$zip->close();
			return array();
		}

		foreach ($sheetXml->sheetData->row as $rowNode) {
			$row = array();
			$maxCol = -1;
			foreach ($rowNode->c as $cellNode) {
				$cellRef = (string) $cellNode['r'];
				$colIndex = $this->columnIndexFromCellRef($cellRef);
				if ($colIndex < 0) {
					$colIndex = $maxCol + 1;
				}
				$maxCol = max($maxCol, $colIndex);

				$type = (string) $cellNode['t'];
				$value = '';
				if ($type === 's') {
					$stringIndex = (int) ((string) $cellNode->v);
					$value = isset($sharedStrings[$stringIndex]) ? $sharedStrings[$stringIndex] : '';
				} elseif ($type === 'inlineStr' && isset($cellNode->is)) {
					foreach ($cellNode->is->t as $inlineText) {
						$value .= (string) $inlineText;
					}
					if ($value === '' && isset($cellNode->is->r)) {
						foreach ($cellNode->is->r as $run) {
							$value .= (string) $run->t;
						}
					}
				} else {
					$value = (string) $cellNode->v;
				}

				$row[$colIndex] = $value;
			}

			if ($maxCol >= 0) {
				$normalizedRow = array();
				for ($i = 0; $i <= $maxCol; $i++) {
					$normalizedRow[] = isset($row[$i]) ? $row[$i] : '';
				}
				$rows[] = $normalizedRow;
			}
		}

		$zip->close();
		return $rows;
	}

	/**
	 * Copy one ZIP entry to a temporary file.
	 *
	 * @param ZipArchive $zip
	 * @param string $entryName
	 * @param string $prefix
	 * @return string
	 */
	protected function copyZipEntryToTempFile($zip, $entryName, $prefix = 'kreazip_')
	{
		$entryName = trim((string) $entryName);
		if ($entryName === '') {
			return '';
		}

		$stream = $zip->getStream($entryName);
		if (!is_resource($stream)) {
			return '';
		}

		$tmpFile = tempnam(sys_get_temp_dir(), (string) $prefix);
		if ($tmpFile === false) {
			fclose($stream);
			return '';
		}

		$out = @fopen($tmpFile, 'wb');
		if (!is_resource($out)) {
			fclose($stream);
			@unlink($tmpFile);
			return '';
		}

		@stream_copy_to_stream($stream, $out);
		fclose($out);
		fclose($stream);

		if (!is_readable($tmpFile) || (int) @filesize($tmpFile) <= 0) {
			@unlink($tmpFile);
			return '';
		}

		return $tmpFile;
	}

	/**
	 * Detect SpreadsheetML XML payload by file head.
	 *
	 * @param string $filePath
	 * @return bool
	 */
	protected function looksLikeSpreadsheetXmlFile($filePath)
	{
		$head = $this->readFileContentSafe($filePath, 12000, 'probe_spreadsheet_xml');
		if (!is_string($head) || $head === '') {
			return false;
		}

		$head = strtolower((string) ltrim($head, "\xEF\xBB\xBF \t\r\n"));
		return (strpos($head, '<workbook') !== false && strpos($head, 'urn:schemas-microsoft-com:office:spreadsheet') !== false);
	}

	/**
	 * Load rows from HTML-like XLS.
	 *
	 * @param string $filePath
	 * @return array<int,array<int,string>>
	 */
	protected function loadXlsRowsAsHtmlTable($filePath)
	{
		$content = $this->readFileContentSafe($filePath, 0, 'load_xls_html');
		if ($content === '') {
			return array();
		}
		if (stripos($content, '<table') === false) {
			return array();
		}
		if (!class_exists('DOMDocument')) {
			return array();
		}

		$dom = new DOMDocument();
		$previousUseErrors = libxml_use_internal_errors(true);
		$loaded = @$dom->loadHTML($content);
		libxml_clear_errors();
		libxml_use_internal_errors($previousUseErrors);
		if (!$loaded) {
			return array();
		}

		$tables = $dom->getElementsByTagName('table');
		if (!$tables || $tables->length === 0) {
			return array();
		}

		$rows = array();
		$table = $tables->item(0);
		$trList = $table ? $table->getElementsByTagName('tr') : null;
		if (!$trList) {
			return array();
		}

		foreach ($trList as $trNode) {
			$row = array();
			foreach ($trNode->childNodes as $cellNode) {
				if (!isset($cellNode->nodeName)) {
					continue;
				}
				$nodeName = strtolower((string) $cellNode->nodeName);
				if ($nodeName !== 'td' && $nodeName !== 'th') {
					continue;
				}
				$value = trim(html_entity_decode((string) $cellNode->textContent, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
				$row[] = $value;
			}
			if (!empty($row)) {
				$rows[] = $row;
			}
		}

		return $rows;
	}

	/**
	 * Load rows from Excel converted with local command tools.
	 *
	 * @param string $filePath
	 * @return array<int,array<int,string>>
	 */
	protected function loadExcelRowsByCommandConversion($filePath)
	{
		if (!function_exists('exec')) {
			return array();
		}

		$tmpBase = tempnam(sys_get_temp_dir(), 'kreaxls_');
		if ($tmpBase === false) {
			return array();
		}

		$tmpDir = dirname($tmpBase);
		$tmpCsv = $tmpBase . '.csv';
		@unlink($tmpBase);

		$rows = array();
		if ($this->isCommandAvailable('ssconvert')) {
			$cmd = 'ssconvert -T Gnumeric_stf:stf_csv ' . escapeshellarg($filePath) . ' ' . escapeshellarg($tmpCsv) . ' 2>&1';
			$exitCode = 1;
			@exec($cmd, $output, $exitCode);
			if ($exitCode === 0 && file_exists($tmpCsv) && filesize($tmpCsv) > 0) {
				$rows = $this->loadCsvRows($tmpCsv);
			}
		}

		if (empty($rows) && $this->isCommandAvailable('libreoffice')) {
			$outDir = $tmpDir;
			$outDirRealPath = realpath($outDir);
			$sourceBaseName = basename((string) pathinfo((string) $filePath, PATHINFO_FILENAME));
			if ($sourceBaseName === '' || $sourceBaseName === '.' || $sourceBaseName === '..') {
				$sourceBaseName = 'converted_statement';
			}
			$candidate = $outDir . '/' . $sourceBaseName . '.csv';
			$candidateDir = realpath(dirname($candidate));
			$candidateInsideOutDir = (
				is_string($outDirRealPath)
				&& $outDirRealPath !== ''
				&& is_string($candidateDir)
				&& $candidateDir === $outDirRealPath
			);
			$cmd = 'libreoffice --headless --convert-to csv --outdir ' . escapeshellarg($outDir) . ' ' . escapeshellarg($filePath) . ' 2>&1';
			$exitCode = 1;
			@exec($cmd, $output, $exitCode);
			if ($exitCode === 0 && $candidateInsideOutDir && file_exists($candidate) && filesize($candidate) > 0) {
				$rows = $this->loadCsvRows($candidate);
			}
			if ($candidateInsideOutDir && file_exists($candidate) && !unlink($candidate) && function_exists('dol_syslog')) {
				dol_syslog('KreaBank parser failed to remove LibreOffice conversion temp file: ' . $candidate, LOG_WARNING);
			}
			if (!$candidateInsideOutDir && file_exists($candidate) && function_exists('dol_syslog')) {
				dol_syslog('KreaBank parser skipped unsafe LibreOffice temp cleanup path: ' . $candidate, LOG_WARNING);
			}
		}

		if (file_exists($tmpCsv)) {
			@unlink($tmpCsv);
		}

		return $rows;
	}

	/**
	 * Parse SpreadsheetML 2003 XML exports (often saved as .xls).
	 *
	 * @param string $filePath
	 * @param string $defaultCurrency
	 * @return array<int,array<string,mixed>>
	 */
	protected function parseSpreadsheetXml($filePath, $defaultCurrency)
	{
		$rows = $this->loadSpreadsheetXmlRows($filePath);
		return $this->parseTabularRows($rows, $defaultCurrency);
	}

	/**
	 * Load rows from SpreadsheetML 2003 XML (Excel XML).
	 *
	 * @param string $filePath
	 * @return array<int,array<int,string>>
	 */
	protected function loadSpreadsheetXmlRows($filePath)
	{
		$content = $this->readFileContentSafe($filePath, 0, 'load_spreadsheet_xml');
		if (!is_string($content) || $content === '') {
			return array();
		}
		$contentHead = strtolower(substr($content, 0, 6000));
		if (strpos($contentHead, '<workbook') === false || strpos($contentHead, 'urn:schemas-microsoft-com:office:spreadsheet') === false) {
			return array();
		}
		if (!class_exists('SimpleXMLElement')) {
			return array();
		}

		$previousUseErrors = libxml_use_internal_errors(true);
		$xml = @simplexml_load_string($content);
		libxml_clear_errors();
		libxml_use_internal_errors($previousUseErrors);
		if (!$xml instanceof SimpleXMLElement) {
			return array();
		}

		$ns = 'urn:schemas-microsoft-com:office:spreadsheet';
		$xml->registerXPathNamespace('ss', $ns);

		$rowNodes = $xml->xpath('//ss:Worksheet[1]/ss:Table/ss:Row');
		if (!is_array($rowNodes) || empty($rowNodes)) {
			// Fallback when worksheet namespace prefixes are inconsistent.
			$rowNodes = $xml->xpath('//Row');
		}
		if (!is_array($rowNodes) || empty($rowNodes)) {
			return array();
		}

		$rows = array();
		foreach ($rowNodes as $rowNode) {
			if (!$rowNode instanceof SimpleXMLElement) {
				continue;
			}

			$cellNodes = $rowNode->xpath('./ss:Cell');
			if (!is_array($cellNodes) || empty($cellNodes)) {
				$cellNodes = $rowNode->xpath('./Cell');
			}
			if (!is_array($cellNodes) || empty($cellNodes)) {
				continue;
			}

			$row = array();
			$col = 1; // SpreadsheetML columns are 1-based.
			foreach ($cellNodes as $cellNode) {
				if (!$cellNode instanceof SimpleXMLElement) {
					continue;
				}

				$attrs = $cellNode->attributes($ns);
				if ($attrs && isset($attrs['Index'])) {
					$newCol = (int) $attrs['Index'];
					if ($newCol > 0) {
						$col = $newCol;
					}
				}

				$value = '';
				$dataNodes = $cellNode->xpath('./ss:Data');
				if (!is_array($dataNodes) || empty($dataNodes)) {
					$dataNodes = $cellNode->xpath('./Data');
				}
				if (is_array($dataNodes) && !empty($dataNodes)) {
					$value = (string) $dataNodes[0];
				} else {
					$value = (string) $cellNode;
				}

				$value = trim(html_entity_decode((string) $value, ENT_QUOTES | ENT_XML1, 'UTF-8'));
				$row[$col - 1] = $value;
				$col++;
			}

			if (empty($row)) {
				continue;
			}

			ksort($row);
			$maxCol = (int) max(array_keys($row));
			$normalized = array();
			for ($i = 0; $i <= $maxCol; $i++) {
				$normalized[] = isset($row[$i]) ? (string) $row[$i] : '';
			}
			while (!empty($normalized) && trim((string) end($normalized)) === '') {
				array_pop($normalized);
			}
			if (!empty($normalized)) {
				$rows[] = $normalized;
			}
		}

		return $rows;
	}

	/**
	 * Normalize generic tabular rows.
	 *
	 * @param array<int,array<int,mixed>> $rows
	 * @return array<int,array<int,string>>
	 */
	protected function normalizeTabularRows($rows)
	{
		$normalizedRows = array();
		foreach ((array) $rows as $row) {
			if (!is_array($row)) {
				continue;
			}

			$row = array_values($row);
			$normalizedRow = array();
			foreach ($row as $cell) {
				if ($cell instanceof DateTimeInterface) {
					$value = $cell->format('Y-m-d');
				} elseif (is_bool($cell)) {
					$value = $cell ? '1' : '0';
				} elseif (is_scalar($cell) || $cell === null) {
					$value = trim((string) $cell);
				} else {
					$value = '';
				}
				$normalizedRow[] = $value;
			}

			while (!empty($normalizedRow) && end($normalizedRow) === '') {
				array_pop($normalizedRow);
			}
			if (!empty($normalizedRow)) {
				$normalizedRows[] = $normalizedRow;
			}
		}

		return $normalizedRows;
	}

	/**
	 * Build default mapping payload.
	 *
	 * @return array<string,int>
	 */
	protected function defaultMapping()
	{
		return array(
			'header_row' => -1,
			'operation_date' => -1,
			'value_date' => -1,
			'amount' => -1,
			'debit' => -1,
			'credit' => -1,
			'running_balance' => -1,
			'description' => -1,
			'payment_reference' => -1,
			'bank_reference' => -1,
			'counterparty_iban' => -1,
			'counterparty_name' => -1,
			'currency' => -1,
		);
	}

	/**
	 * Guess header row index from first rows.
	 *
	 * @param array<int,array<int,string>> $rows
	 * @return int
	 */
	protected function guessHeaderRowIndex($rows)
	{
		$maxScan = min(80, count($rows));
		$bestScore = -9999;
		$bestIndex = -1;

		for ($i = 0; $i < $maxScan; $i++) {
			$row = $rows[$i];
			$nonEmpty = 0;
			foreach ((array) $row as $cellValue) {
				if (trim((string) $cellValue) !== '') {
					$nonEmpty++;
				}
			}
			if ($nonEmpty < 2) {
				continue;
			}

			$headerMap = $this->buildHeaderMap($row);
			$aliasScore = $this->scoreHeaderMap($headerMap);
			$rowProfile = $this->buildRowProfile($row);
			if ($rowProfile['date_cells'] > 0 && $rowProfile['numeric_cells'] > 0) {
				// Data rows often contain parsed date serials + amounts in the same row.
				continue;
			}
			$score = ($aliasScore * 12);
			$score += ($rowProfile['text_cells'] * 2);
			$score -= ($rowProfile['numeric_cells'] * 3);
			$score -= ($rowProfile['date_cells'] * 2);
			if ($rowProfile['date_cells'] > 0 && $rowProfile['numeric_cells'] >= 2) {
				// Rows mixing serial dates + numeric values are usually statement data, not headers.
				$score -= 24;
			}

			// A good header is usually followed by data-like rows.
			if (($i + 1) < count($rows)) {
				$nextProfile = $this->buildRowProfile($rows[$i + 1]);
				if ($nextProfile['date_cells'] > 0 && $nextProfile['numeric_cells'] > 0) {
					$score += 8;
				}
				if ($nextProfile['text_cells'] > 0) {
					$score += 2;
				}
			}

			// Rows with many all-caps labels are likely metadata sections.
			if ($aliasScore === 0 && $rowProfile['numeric_cells'] === 0 && $rowProfile['text_cells'] <= 2) {
				$score -= 8;
			}

			if ($score > $bestScore) {
				$bestScore = $score;
				$bestIndex = $i;
			}
		}

		return ($bestScore >= 8) ? $bestIndex : -1;
	}

	/**
	 * Score a normalized header map against known aliases.
	 *
	 * @param array<string,int> $headerMap
	 * @return int
	 */
	protected function scoreHeaderMap($headerMap)
	{
		$aliases = $this->getFieldAliases();
		$score = 0;
		foreach ($aliases as $fieldAliases) {
			foreach ($fieldAliases as $alias) {
				if (isset($headerMap[$alias])) {
					$score++;
					break;
				}
				foreach ($headerMap as $headerKey => $idx) {
					if (strpos($headerKey, $alias) !== false || strpos($alias, $headerKey) !== false) {
						$score++;
						break 2;
					}
				}
			}
		}

		return $score;
	}

	/**
	 * Build selectable column definitions for UI.
	 *
	 * @param array<int,array<int,string>> $rows
	 * @param int $headerRowIndex
	 * @return array<int,array{index:int,label:string}>
	 */
	protected function buildColumnDefinitions($rows, $headerRowIndex)
	{
		$maxColumns = 0;
		foreach ($rows as $row) {
			$maxColumns = max($maxColumns, count($row));
			if ($maxColumns >= 60) {
				break;
			}
		}

		$columns = array();
		for ($i = 0; $i < $maxColumns; $i++) {
			$label = 'Column ' . ($i + 1);
			if ($headerRowIndex >= 0 && isset($rows[$headerRowIndex][$i]) && trim((string) $rows[$headerRowIndex][$i]) !== '') {
				$label = trim((string) $rows[$headerRowIndex][$i]);
			}
			$columns[] = array(
				'index' => $i,
				'label' => $label,
			);
		}

		return $columns;
	}

	/**
	 * Build suggested mapping from header map and columns.
	 *
	 * @param array<string,int> $headerMap
	 * @param array<int,array{index:int,label:string}> $columns
	 * @param array<int,array<int,string>> $rows
	 * @param int $headerRowIndex
	 * @return array<string,int>
	 */
	protected function buildSuggestedMapping($headerMap, $columns, $rows = array(), $headerRowIndex = -1)
	{
		$mapping = $this->defaultMapping();
		$aliases = $this->getFieldAliases();

		foreach ($aliases as $fieldKey => $fieldAliases) {
			$idx = $this->findColumnByAliases($headerMap, $fieldAliases);
			if ($idx >= 0) {
				$mapping[$fieldKey] = $idx;
			}
		}

		$mapping = $this->applyMlHeaderMapping($mapping, $headerMap);
		$mapping = $this->applyColumnProfileMapping($mapping, $columns, $headerMap);
		$mapping = $this->applyDataProfileMapping($mapping, $rows, (int) $headerRowIndex);

		if (
			$mapping['amount'] >= 0
			&& ($mapping['amount'] === $mapping['operation_date'] || $mapping['amount'] === $mapping['value_date'])
			&& ($mapping['debit'] >= 0 || $mapping['credit'] >= 0)
		) {
			$mapping['amount'] = -1;
		}
		if ($mapping['debit'] >= 0 && $mapping['credit'] >= 0) {
			if ((int) $mapping['debit'] === (int) $mapping['credit']) {
				// Same source column cannot represent split debit/credit buckets.
				// Preserve it as a single signed amount instead.
				$shared = (int) $mapping['debit'];
				$mapping['amount'] = $shared;
				$mapping['debit'] = -1;
				$mapping['credit'] = -1;
			} else {
				$mapping['amount'] = -1;
			}
		}

		if ($mapping['operation_date'] < 0 && isset($columns[0])) {
			$mapping['operation_date'] = 0;
		}
		if ($mapping['value_date'] < 0 && isset($columns[1])) {
			$mapping['value_date'] = 1;
		}
		if ($mapping['description'] < 0 && isset($columns[3])) {
			$mapping['description'] = 3;
		}
		if ($mapping['amount'] < 0 && $mapping['debit'] < 0 && $mapping['credit'] < 0 && isset($columns[4])) {
			$mapping['amount'] = 4;
		}
		if ($mapping['bank_reference'] < 0) {
			$mapping['bank_reference'] = ($mapping['payment_reference'] >= 0) ? (int) $mapping['payment_reference'] : (int) $mapping['description'];
		}
		if ($mapping['payment_reference'] < 0 && $mapping['description'] >= 0) {
			// In many bank exports, operation label is the only stable reference token.
			$mapping['payment_reference'] = (int) $mapping['description'];
		}

		return $mapping;
	}

	/**
	 * Apply column-profile inference when header names are incomplete/unknown.
	 *
	 * @param array<string,int> $mapping
	 * @param array<int,array{index:int,label:string}> $columns
	 * @param array<string,int> $headerMap
	 * @return array<string,int>
	 */
	protected function applyColumnProfileMapping($mapping, $columns, $headerMap)
	{
		if (empty($columns)) {
			return $mapping;
		}

		$profiles = array();
		foreach ($columns as $col) {
			$idx = (int) $col['index'];
			$label = isset($col['label']) ? (string) $col['label'] : '';
			$headerKey = $this->normalizeHeaderKey($label);
			$profiles[$idx] = array(
				'index' => $idx,
				'label' => $label,
				'header_key' => $headerKey,
				'is_date' => (bool) preg_match('/(^|_)(date|data|valor_data|valuta|booking|operation|movimento|posting|settlement|effective|processing|fecha)($|_)/', $headerKey),
				'is_debit' => (bool) preg_match('/(^|_)(debit|debito|saida|saidas|withdraw|pagamento|debito_direto|outgoing|expense)($|_)/', $headerKey),
				'is_credit' => (bool) preg_match('/(^|_)(credit|credito|entrada|recebimento|deposit|incoming|receipt)($|_)/', $headerKey),
				'is_amount' => (bool) preg_match('/(^|_)(amount|valor|montante|quantia|movimento|importe|total|value)($|_)/', $headerKey),
				'is_balance' => (bool) preg_match('/(^|_)(saldo|balance|running_balance|contabilistico|disponivel|controlo|controle|opening|ending|final)($|_)/', $headerKey),
				'is_description' => (bool) preg_match('/(^|_)(descricao|description|detalhe|historico|memo|narrative|remittance|details|descr|label|purpose|texto)($|_)/', $headerKey),
				'is_currency' => (bool) preg_match('/(^|_)(currency|moeda|divisa|devise|ccy|curr)($|_)/', $headerKey),
				'is_reference' => (bool) preg_match('/(^|_)(referencia|reference|ref|transacao|documento|id|codigo|code|transaction_id|operation_id)($|_)/', $headerKey),
				'is_iban' => (bool) preg_match('/(^|_)(iban|nib|conta|account|beneficiary_account|debtor_account|creditor_account)($|_)/', $headerKey),
			);
		}

		$find = function ($predicate, $exclude = array()) use ($profiles) {
			foreach ($profiles as $idx => $profile) {
				if (in_array((int) $idx, $exclude, true)) {
					continue;
				}
				if ($predicate($profile)) {
					return (int) $idx;
				}
			}
			return -1;
		};

		if ($mapping['operation_date'] < 0) {
			$mapping['operation_date'] = $find(static function ($p) {
				return !empty($p['is_date']);
			});
		}
		if ($mapping['value_date'] < 0) {
			$mapping['value_date'] = $find(static function ($p) {
				return !empty($p['is_date']);
			}, array((int) $mapping['operation_date']));
		}
		if ($mapping['debit'] < 0) {
			$mapping['debit'] = $find(static function ($p) {
				return !empty($p['is_debit']);
			});
		}
		if ($mapping['credit'] < 0) {
			$mapping['credit'] = $find(static function ($p) {
				return !empty($p['is_credit']);
			});
		}
		if ($mapping['running_balance'] < 0) {
			$mapping['running_balance'] = $find(static function ($p) {
				return !empty($p['is_balance']);
			});
		}
		if ($mapping['amount'] < 0) {
			$mapping['amount'] = $find(static function ($p) {
				return !empty($p['is_amount']) && empty($p['is_date']);
			}, array((int) $mapping['debit'], (int) $mapping['credit'], (int) $mapping['running_balance']));
		}
		if ($mapping['description'] < 0) {
			$mapping['description'] = $find(static function ($p) {
				return !empty($p['is_description']);
			});
		}
		if ($mapping['currency'] < 0) {
			$mapping['currency'] = $find(static function ($p) {
				return !empty($p['is_currency']);
			});
		}
		if ($mapping['counterparty_iban'] < 0) {
			$mapping['counterparty_iban'] = $find(static function ($p) {
				return !empty($p['is_iban']);
			});
		}
		if ($mapping['payment_reference'] < 0) {
			$mapping['payment_reference'] = $find(static function ($p) {
				return !empty($p['is_reference']);
			}, array((int) $mapping['description']));
		}
		if ($mapping['counterparty_name'] < 0) {
			$mapping['counterparty_name'] = $find(static function ($p) {
				return !empty($p['is_description']) || !empty($p['is_reference']);
			}, array((int) $mapping['description'], (int) $mapping['payment_reference']));
		}

		$maxColumnIndex = -1;
		foreach ($columns as $col) {
			$maxColumnIndex = max($maxColumnIndex, (int) $col['index']);
		}
		foreach ($mapping as $key => $idx) {
			if (!is_int($idx) || $idx < 0 || $idx > $maxColumnIndex) {
				$mapping[$key] = -1;
			}
		}

		return $mapping;
	}

	/**
	 * Apply data-driven column inference using sampled row values.
	 *
	 * @param array<string,int> $mapping
	 * @param array<int,array<int,string>> $rows
	 * @param int $headerRowIndex
	 * @return array<string,int>
	 */
	protected function applyDataProfileMapping($mapping, $rows, $headerRowIndex)
	{
		if (empty($rows) || !is_array($rows)) {
			return $mapping;
		}

		$startIndex = ((int) $headerRowIndex >= 0) ? (((int) $headerRowIndex) + 1) : 0;
		$profiles = $this->buildColumnDataProfiles($rows, $startIndex, 220);
		if (empty($profiles)) {
			return $mapping;
		}

		$ratio = function ($num, $den) {
			return ((int) $den > 0) ? ((float) $num / (float) $den) : 0.0;
		};

		$isDateHealthy = function ($idx) use ($profiles, $ratio) {
			$idx = (int) $idx;
			if ($idx < 0 || !isset($profiles[$idx])) {
				return false;
			}
			$p = $profiles[$idx];
			$dateRatio = $ratio($p['date_count'], $p['non_empty']);
			return ($p['date_count'] >= 3 && $dateRatio >= 0.45);
		};
		$isDescriptionHealthy = function ($idx) use ($profiles, $ratio) {
			$idx = (int) $idx;
			if ($idx < 0 || !isset($profiles[$idx])) {
				return false;
			}
			$p = $profiles[$idx];
			$textRatio = $ratio($p['text_count'], $p['non_empty']);
			return ($p['text_count'] >= 2 && $textRatio >= 0.25);
		};
		$isAmountHealthy = function ($idx) use ($profiles, $ratio) {
			$idx = (int) $idx;
			if ($idx < 0 || !isset($profiles[$idx])) {
				return false;
			}
			$p = $profiles[$idx];
			$numericRatio = $ratio($p['numeric_count'], $p['non_empty']);
			$dateRatio = $ratio($p['date_count'], $p['non_empty']);
			return ($p['numeric_count'] >= 3 && $p['non_zero_count'] >= 2 && $p['text_count'] === 0 && $numericRatio >= 0.55 && $dateRatio < 0.40);
		};
		$isBalanceHealthy = function ($idx) use ($profiles, $ratio) {
			$idx = (int) $idx;
			if ($idx < 0 || !isset($profiles[$idx])) {
				return false;
			}
			$p = $profiles[$idx];
			$numericRatio = $ratio($p['numeric_count'], $p['non_empty']);
			$dateRatio = $ratio($p['date_count'], $p['non_empty']);
			return ($p['numeric_count'] >= 3 && $p['text_count'] === 0 && $numericRatio >= 0.55 && $dateRatio < 0.35);
		};

		$dateCandidates = array();
		foreach ($profiles as $idx => $p) {
			$dateRatio = $ratio($p['date_count'], $p['non_empty']);
			if ($p['date_count'] >= 3 && $dateRatio >= 0.55) {
				$dateCandidates[(int) $idx] = (float) ($p['date_count'] * 100) + ($dateRatio * 10.0) + (((float) $idx) * 0.01);
			}
		}
		if (!empty($dateCandidates)) {
			arsort($dateCandidates, SORT_NUMERIC);
		}
		$dateIndexes = array_map('intval', array_keys($dateCandidates));

		if ((!isset($mapping['operation_date']) || (int) $mapping['operation_date'] < 0 || !$isDateHealthy((int) $mapping['operation_date'])) && !empty($dateIndexes)) {
			$mapping['operation_date'] = (int) $dateIndexes[0];
		}
		if (!isset($mapping['value_date']) || (int) $mapping['value_date'] < 0 || !$isDateHealthy((int) $mapping['value_date'])) {
			$replacement = -1;
			foreach ($dateIndexes as $idx) {
				if ($idx !== (int) $mapping['operation_date']) {
					$replacement = (int) $idx;
					break;
				}
			}
			if ($replacement >= 0) {
				$mapping['value_date'] = $replacement;
			} elseif ((int) $mapping['operation_date'] >= 0) {
				$mapping['value_date'] = (int) $mapping['operation_date'];
			}
		}

		$bestDescriptionIndex = -1;
		$bestDescriptionScore = -999999.0;
		foreach ($profiles as $idx => $p) {
			$textRatio = $ratio($p['text_count'], $p['non_empty']);
			if ($p['text_count'] < 3 || $textRatio < 0.35) {
				continue;
			}
			$score = ($p['text_count'] * 4.0) + ((float) $p['avg_length']) - ($p['numeric_count'] * 2.0) - ($p['date_count'] * 2.0);
			if ($score > $bestDescriptionScore) {
				$bestDescriptionScore = $score;
				$bestDescriptionIndex = (int) $idx;
			}
		}
		if ((!isset($mapping['description']) || (int) $mapping['description'] < 0 || !$isDescriptionHealthy((int) $mapping['description'])) && $bestDescriptionIndex >= 0) {
			$mapping['description'] = $bestDescriptionIndex;
		}

		$reserved = array();
		foreach (array('operation_date', 'value_date', 'description') as $field) {
			$idx = isset($mapping[$field]) ? (int) $mapping[$field] : -1;
			if ($idx >= 0) {
				$reserved[$idx] = 1;
			}
		}

		$amountCandidates = array();
		$amountCandidatesWithNegatives = array();
		foreach ($profiles as $idx => $p) {
			$idx = (int) $idx;
			if (isset($reserved[$idx])) {
				continue;
			}
			$numericRatio = $ratio($p['numeric_count'], $p['non_empty']);
			$dateRatio = $ratio($p['date_count'], $p['non_empty']);
			if ($p['numeric_count'] < 3 || $p['non_zero_count'] < 2 || $numericRatio < 0.60 || $dateRatio >= 0.40) {
				continue;
			}
			if ($p['text_count'] > 0) {
				continue;
			}
			$score = ($p['non_zero_count'] * 4.0) + (min($p['negative_count'], $p['positive_count']) * 6.0);
			if ($p['negative_count'] > 0) {
				$score += 3.0;
			}
			if ($p['positive_count'] > 0) {
				$score += 3.0;
			}
			$score -= (float) $p['zero_count'];
			$score += ((float) $idx * 0.05);
			$amountCandidates[$idx] = $score;
			if ($p['negative_count'] > 0) {
				$amountCandidatesWithNegatives[$idx] = $score;
			}
		}
		if (!empty($amountCandidatesWithNegatives)) {
			$amountCandidates = $amountCandidatesWithNegatives;
		}
		if (!empty($amountCandidates)) {
			arsort($amountCandidates, SORT_NUMERIC);
		}

		if (!isset($mapping['amount']) || (int) $mapping['amount'] < 0 || !$isAmountHealthy((int) $mapping['amount'])) {
			$amountIndexes = array_keys($amountCandidates);
			if (!empty($amountIndexes)) {
				$mapping['amount'] = (int) $amountIndexes[0];
			} else {
				$mapping['amount'] = -1;
			}
		}

		$negativeCandidates = array();
		$positiveCandidates = array();
		foreach ($profiles as $idx => $p) {
			$idx = (int) $idx;
			if (isset($reserved[$idx])) {
				continue;
			}
			$numericRatio = $ratio($p['numeric_count'], $p['non_empty']);
			$dateRatio = $ratio($p['date_count'], $p['non_empty']);
			if ($p['numeric_count'] < 3 || $numericRatio < 0.55 || $dateRatio >= 0.40 || $p['text_count'] > 0) {
				continue;
			}
			$minimumZeros = max(1, (int) floor(((int) $p['non_empty']) * 0.15));
			if ($p['negative_count'] >= 2 && $p['positive_count'] === 0 && $p['zero_count'] >= $minimumZeros) {
				$negativeCandidates[$idx] = ($p['negative_count'] * 3.0) + ($p['non_zero_count'] * 1.5) - ($p['positive_count'] * 2.0);
			}
			if ($p['positive_count'] >= 2 && $p['negative_count'] === 0 && $p['zero_count'] >= $minimumZeros) {
				$positiveCandidates[$idx] = ($p['positive_count'] * 3.0) + ($p['non_zero_count'] * 1.5) - ($p['negative_count'] * 2.0);
			}
		}
		if (!empty($negativeCandidates)) {
			arsort($negativeCandidates, SORT_NUMERIC);
		}
		if (!empty($positiveCandidates)) {
			arsort($positiveCandidates, SORT_NUMERIC);
		}

		$debitIdx = isset($mapping['debit']) ? (int) $mapping['debit'] : -1;
		if ($debitIdx < 0 || !isset($negativeCandidates[$debitIdx])) {
			$negKeys = array_keys($negativeCandidates);
			if (!empty($negKeys)) {
				$mapping['debit'] = (int) $negKeys[0];
			} else {
				$mapping['debit'] = -1;
			}
		}
		$creditIdx = isset($mapping['credit']) ? (int) $mapping['credit'] : -1;
		if ($creditIdx < 0 || !isset($positiveCandidates[$creditIdx])) {
			$posKeys = array_keys($positiveCandidates);
			if (!empty($posKeys)) {
				$candidate = (int) $posKeys[0];
				if ($candidate !== (int) $mapping['debit']) {
					$mapping['credit'] = $candidate;
				} else {
					$mapping['credit'] = -1;
				}
			} else {
				$mapping['credit'] = -1;
			}
		}

		$balanceCandidates = array();
		$reservedForBalance = $reserved;
		foreach (array('amount', 'debit', 'credit') as $field) {
			$idx = isset($mapping[$field]) ? (int) $mapping[$field] : -1;
			if ($idx >= 0) {
				$reservedForBalance[$idx] = 1;
			}
		}
		foreach ($profiles as $idx => $p) {
			$idx = (int) $idx;
			if (isset($reservedForBalance[$idx])) {
				continue;
			}
			$numericRatio = $ratio($p['numeric_count'], $p['non_empty']);
			$dateRatio = $ratio($p['date_count'], $p['non_empty']);
			if ($p['numeric_count'] < 3 || $p['non_zero_count'] < 2 || $numericRatio < 0.55 || $dateRatio >= 0.35 || $p['text_count'] > 0) {
				continue;
			}
			$score = ($p['non_empty'] * 2.0) + ($p['non_zero_count'] * 1.0) + ((float) $idx * 0.10);
			if ($p['positive_count'] >= $p['negative_count']) {
				$score += 1.0;
			}
			$balanceCandidates[$idx] = $score;
		}
		if (!empty($balanceCandidates)) {
			arsort($balanceCandidates, SORT_NUMERIC);
		}

		if (!isset($mapping['running_balance']) || (int) $mapping['running_balance'] < 0 || !$isBalanceHealthy((int) $mapping['running_balance'])) {
			$balanceKeys = array_keys($balanceCandidates);
			if (!empty($balanceKeys)) {
				$mapping['running_balance'] = (int) $balanceKeys[0];
			}
		}

		return $mapping;
	}

	/**
	 * Build per-column profiles from sampled tabular rows.
	 *
	 * @param array<int,array<int,string>> $rows
	 * @param int $startIndex
	 * @param int $maxRows
	 * @return array<int,array<string,mixed>>
	 */
	protected function buildColumnDataProfiles($rows, $startIndex = 0, $maxRows = 200)
	{
		$profiles = array();
		$dateContexts = array();
		$rowCount = count((array) $rows);
		$startIndex = max(0, (int) $startIndex);
		$maxRows = max(20, (int) $maxRows);
		$scanned = 0;

		for ($i = $startIndex; $i < $rowCount && $scanned < $maxRows; $i++) {
			$row = isset($rows[$i]) && is_array($rows[$i]) ? (array) $rows[$i] : array();
			$rowHasData = false;
			foreach ($row as $idx => $rawCell) {
				$cell = trim((string) $rawCell);
				if ($cell === '') {
					continue;
				}
				$rowHasData = true;
				$idx = (int) $idx;
				if (!isset($profiles[$idx])) {
					$profiles[$idx] = array(
						'index' => $idx,
						'non_empty' => 0,
						'numeric_count' => 0,
						'date_count' => 0,
						'text_count' => 0,
						'negative_count' => 0,
						'positive_count' => 0,
						'zero_count' => 0,
						'non_zero_count' => 0,
						'len_total' => 0,
						'avg_length' => 0.0,
					);
				}

				$profiles[$idx]['non_empty']++;
				$profiles[$idx]['len_total'] += strlen($cell);
				if (preg_match('/[A-Za-zÀ-ÿ]/u', $cell)) {
					$profiles[$idx]['text_count']++;
				}

				$number = $this->parseLocalizedNumber($cell);
				if ($number !== null) {
					$profiles[$idx]['numeric_count']++;
					if (abs((float) $number) < 0.0000001) {
						$profiles[$idx]['zero_count']++;
					} else {
						$profiles[$idx]['non_zero_count']++;
					}
					if ((float) $number > 0) {
						$profiles[$idx]['positive_count']++;
					} elseif ((float) $number < 0) {
						$profiles[$idx]['negative_count']++;
					}
				}

				$dateContext = isset($dateContexts[$idx]) && is_array($dateContexts[$idx]) ? $dateContexts[$idx] : array();
				$date = $this->parseDate($cell, $dateContext);
				if ($date !== null) {
					$profiles[$idx]['date_count']++;
					$dateContexts[$idx] = $this->appendDateContext($dateContext, $date);
				}
			}

			if ($rowHasData) {
				$scanned++;
			}
		}

		foreach ($profiles as $idx => $profile) {
			$den = max(1, (int) $profile['non_empty']);
			$profiles[$idx]['avg_length'] = ((float) $profile['len_total']) / (float) $den;
		}
		ksort($profiles);

		return $profiles;
	}

	/**
	 * Try ML-driven header mapping using module-local PHP-ML.
	 *
	 * @param array<string,int> $mapping
	 * @param array<string,int> $headerMap
	 * @return array<string,int>
	 */
	protected function applyMlHeaderMapping($mapping, $headerMap)
	{
		if (!$this->isPhpMlAvailable()) {
			return $mapping;
		}
		if (empty($headerMap) || !is_array($headerMap)) {
			return $mapping;
		}

		$classifier = $this->getMlHeaderClassifier();
		if (!is_object($classifier) || !method_exists($classifier, 'predict')) {
			return $mapping;
		}

		$usedIndexes = array();
		foreach ($mapping as $field => $idx) {
			if (is_int($idx) && $idx >= 0) {
				$usedIndexes[(int) $idx] = true;
			}
		}

		$aliases = $this->getFieldAliases();
		foreach ($headerMap as $headerKey => $index) {
			$index = (int) $index;
			if (isset($usedIndexes[$index])) {
				continue;
			}
			if ($headerKey === '') {
				continue;
			}

			$predictedField = (string) $classifier->predict($this->headerToMlVector((string) $headerKey));
			if ($predictedField === '' || !array_key_exists($predictedField, $mapping)) {
				continue;
			}
			if ((int) $mapping[$predictedField] >= 0) {
				continue;
			}

			$confidence = $this->estimateMlPredictionConfidence((string) $headerKey, isset($aliases[$predictedField]) ? (array) $aliases[$predictedField] : array());
			if ($confidence < 0.58) {
				continue;
			}

			$mapping[$predictedField] = $index;
			$usedIndexes[$index] = true;
		}

		return $mapping;
	}

	/**
	 * Build simple ML feature vector from normalized header token.
	 *
	 * @param string $headerKey
	 * @return array<int,float>
	 */
	protected function headerToMlVector($headerKey)
	{
		$vector = array_fill(0, $this->mlHeaderVectorSize, 0.0);
		$headerKey = trim((string) $this->normalizeHeaderKey((string) $headerKey));
		if ($headerKey === '') {
			return $vector;
		}

		$tokens = preg_split('/_+/', $headerKey);
		if (!is_array($tokens)) {
			$tokens = array($headerKey);
		}
		foreach ($tokens as $token) {
			$token = trim((string) $token);
			if ($token === '') {
				continue;
			}
			$idx = abs((int) crc32('tok:' . $token)) % $this->mlHeaderVectorSize;
			$vector[$idx] += 3.0;
		}

		$padded = '^' . $headerKey . '$';
		$len = strlen($padded);
		if ($len >= 3) {
			for ($i = 0; $i <= ($len - 3); $i++) {
				$gram = substr($padded, $i, 3);
				$idx = abs((int) crc32('gram:' . $gram)) % $this->mlHeaderVectorSize;
				$vector[$idx] += 1.0;
			}
		}

		return $vector;
	}

	/**
	 * Estimate confidence for ML prediction by normalized token similarity.
	 *
	 * @param string $headerKey
	 * @param array<int,string> $fieldAliases
	 * @return float
	 */
	protected function estimateMlPredictionConfidence($headerKey, $fieldAliases)
	{
		$headerKey = (string) $this->normalizeHeaderKey((string) $headerKey);
		if ($headerKey === '' || empty($fieldAliases)) {
			return 0.0;
		}

		$best = 0.0;
		foreach ((array) $fieldAliases as $alias) {
			$alias = (string) $this->normalizeHeaderKey((string) $alias);
			if ($alias === '') {
				continue;
			}
			if ($alias === $headerKey) {
				return 1.0;
			}
			$score = 0.0;
			if (strpos($headerKey, $alias) !== false || strpos($alias, $headerKey) !== false) {
				$score = 0.9;
			} else {
				$percent = 0.0;
				similar_text($headerKey, $alias, $percent);
				$score = ((float) $percent) / 100.0;
			}
			if ($score > $best) {
				$best = $score;
			}
		}

		return $best;
	}

	/**
	 * Get or train ML header classifier.
	 *
	 * @return object|null
	 */
	protected function getMlHeaderClassifier()
	{
		if ($this->mlHeaderClassifierReady) {
			return $this->mlHeaderClassifier;
		}
		$this->mlHeaderClassifierReady = true;
		$this->mlHeaderClassifier = null;

		if (!$this->isPhpMlAvailable()) {
			return null;
		}
		$knnClass = '\\Phpml\\Classification\\KNearestNeighbors';
		if (!class_exists($knnClass)) {
			return null;
		}

		try {
			$classifier = new $knnClass(5);
			$aliases = $this->getFieldAliases();
			$samples = array();
			$labels = array();
			foreach ($aliases as $fieldKey => $fieldAliases) {
				foreach ((array) $fieldAliases as $alias) {
					$aliasKey = $this->normalizeHeaderKey((string) $alias);
					if ($aliasKey === '') {
						continue;
					}
					$samples[] = $this->headerToMlVector($aliasKey);
					$labels[] = (string) $fieldKey;
				}
			}

			$templateSamples = $this->loadMlTrainingSamplesFromStore();
			foreach ($templateSamples as $sample) {
				$fieldKey = !empty($sample['field']) ? (string) $sample['field'] : '';
				$header = !empty($sample['header']) ? (string) $sample['header'] : '';
				if ($fieldKey === '' || $header === '' || !array_key_exists($fieldKey, $aliases)) {
					continue;
				}

				$headerKey = $this->normalizeHeaderKey($header);
				if ($headerKey === '') {
					continue;
				}
				$samples[] = $this->headerToMlVector($headerKey);
				$labels[] = $fieldKey;
			}
			if (count($samples) < 8 || count($samples) !== count($labels)) {
				return null;
			}

			$classifier->train($samples, $labels);
			$this->mlHeaderClassifier = $classifier;
		} catch (Throwable $e) {
			$this->mlHeaderClassifier = null;
		}

		return $this->mlHeaderClassifier;
	}

	/**
	 * Load persisted ML samples saved from user-approved templates.
	 *
	 * @return array<int,array{field:string,header:string}>
	 */
	protected function loadMlTrainingSamplesFromStore()
	{
		$paths = $this->getMlTrainingStoreCandidates();
		$samples = array();
		foreach ($paths as $path) {
			if (!is_string($path) || $path === '' || !is_readable($path)) {
				continue;
			}

			$raw = $this->readFileContentSafe($path, 0, 'load_ml_template_samples');
			if (!is_string($raw) || $raw === '') {
				continue;
			}
			$data = json_decode($raw, true);
			if (!is_array($data)) {
				continue;
			}

			foreach ($data as $row) {
				if (!is_array($row)) {
					continue;
				}
				$field = isset($row['field']) ? trim((string) $row['field']) : '';
				$header = isset($row['header']) ? trim((string) $row['header']) : '';
				if ($field === '' || $header === '') {
					continue;
				}
				$samples[] = array('field' => $field, 'header' => $header);
				if (count($samples) >= 4000) {
					break 2;
				}
			}
		}

		return $samples;
	}

	/**
	 * Resolve ML training store paths (entity-specific first).
	 *
	 * @return array<int,string>
	 */
	protected function getMlTrainingStoreCandidates()
	{
		if (!defined('DOL_DATA_ROOT') || DOL_DATA_ROOT === '') {
			return array();
		}

		$entity = 1;
		if (isset($GLOBALS['conf']) && is_object($GLOBALS['conf']) && isset($GLOBALS['conf']->entity)) {
			$entity = max(1, (int) $GLOBALS['conf']->entity);
		}

		return array(
			rtrim((string) DOL_DATA_ROOT, '/') . '/kreabank/header_templates_entity' . $entity . '.json',
			rtrim((string) DOL_DATA_ROOT, '/') . '/kreabank/header_templates.json',
		);
	}

	/**
	 * Try to load PHP-ML from this module only.
	 *
	 * @return bool
	 */
	protected function isPhpMlAvailable()
	{
		if (class_exists('\\Phpml\\Classification\\KNearestNeighbors')) {
			return true;
		}
		if (defined('PHP_VERSION_ID') && PHP_VERSION_ID < 80000) {
			return false;
		}

		$candidates = array(
			__DIR__ . '/../vendor/autoload.php',
		);
		if (defined('DOL_DOCUMENT_ROOT')) {
			$candidates[] = DOL_DOCUMENT_ROOT . '/custom/kreabank/vendor/autoload.php';
		}
		foreach ($candidates as $autoloadPath) {
			if (is_string($autoloadPath) && $autoloadPath !== '' && file_exists($autoloadPath)) {
				require_once $autoloadPath;
				if (class_exists('\\Phpml\\Classification\\KNearestNeighbors')) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Build row profile for header detection.
	 *
	 * @param array<int,string> $row
	 * @return array<string,int>
	 */
	protected function buildRowProfile($row)
	{
		$textCells = 0;
		$numericCells = 0;
		$dateCells = 0;
		foreach ((array) $row as $cell) {
			$cell = trim((string) $cell);
			if ($cell === '') {
				continue;
			}

			$isDate = ($this->parseDate($cell) !== null);
			$isNumeric = ($this->parseLocalizedNumber($cell) !== null);
			if ($isDate) {
				$dateCells++;
			}
			if ($isNumeric) {
				$numericCells++;
			}
			if (!$isDate && !$isNumeric && preg_match('/[A-Za-zÀ-ÿ]/u', $cell)) {
				$textCells++;
			}
		}

		return array(
			'text_cells' => (int) $textCells,
			'numeric_cells' => (int) $numericCells,
			'date_cells' => (int) $dateCells,
		);
	}

	/**
	 * Find first column index by alias set.
	 *
	 * @param array<string,int> $headerMap
	 * @param array<int,string> $aliases
	 * @return int
	 */
	protected function findColumnByAliases($headerMap, $aliases)
	{
		foreach ($aliases as $alias) {
			if (isset($headerMap[$alias])) {
				return (int) $headerMap[$alias];
			}
		}

		foreach ($headerMap as $headerKey => $idx) {
			foreach ($aliases as $alias) {
				if (strpos($headerKey, $alias) !== false || strpos($alias, $headerKey) !== false) {
					return (int) $idx;
				}
			}
		}

		return -1;
	}

	/**
	 * Known header aliases by target field.
	 *
	 * @return array<string,array<int,string>>
	 */
	protected function getFieldAliases()
	{
		if (is_array($this->fieldAliasesCache)) {
			return $this->fieldAliasesCache;
		}

		$aliases = array(
			'operation_date' => array(
				'date',
				'operation_date',
				'booking_date',
				'transaction_date',
				'posting_date',
				'movement_date',
				'entry_date',
				'movement_value_date',
				'data',
				'data_movimento',
				'data_lancamento',
				'data_operacao',
				'data_operacao_banco',
				'data_operacao_cartao',
				'fecha_operacion',
				'fecha_contable',
				'transaction_date_time'
			),
			'value_date' => array(
				'value_date',
				'valuta',
				'settlement_date',
				'effective_date',
				'clearing_date',
				'data_valor',
				'data_credito',
				'data_debito',
				'data_valuta',
				'fecha_valor',
				'processing_date'
			),
			'amount' => array(
				'amount',
				'montante',
				'valor',
				'quantia',
				'movimento',
				'importe',
				'total',
				'valor_movimento',
				'valor_transacao',
				'amount_eur',
				'transaction_amount',
				'movement_amount'
			),
			'debit' => array(
				'debit',
				'debito',
				'debito_total',
				'saida',
				'saidas',
				'withdrawal',
				'pago',
				'valor_debito',
				'montante_debito',
				'debit_amount',
				'debit_value',
				'outgoing',
				'expenses'
			),
			'credit' => array(
				'credit',
				'credito',
				'credito_total',
				'entrada',
				'entradas',
				'deposit',
				'recebido',
				'valor_credito',
				'montante_credito',
				'credit_amount',
				'credit_value',
				'incoming',
				'receipts'
			),
			'running_balance' => array(
				'balance',
				'running_balance',
				'saldo',
				'saldo_contabilistico',
				'saldo_disponivel',
				'saldo_controlo',
				'saldo_controle',
				'saldo_apos_movimento',
				'ending_balance',
				'final_balance',
				'opening_balance'
			),
			'description' => array(
				'description',
				'memo',
				'label',
				'purpose',
				'remittance',
				'narrative',
				'details',
				'descricao',
				'detalhe',
				'historico',
				'descricao_movimento',
				'descricao_balcao',
				'transaction_description',
				'operation_description',
				'movement_description',
				'reference_description'
			),
			'payment_reference' => array(
				'reference',
				'payment_reference',
				'ref',
				'referencia',
				'documento',
				'n_documento',
				'num_documento',
				'id_transacao',
				'cod_transacao',
				'transaction_id',
				'payment_id',
				'document_reference',
				'operation_id'
			),
			'bank_reference' => array(
				'bank_reference',
				'reference_bank',
				'referencia_banco',
				'ref_banco',
				'id_transacao',
				'cod_banco',
				'codigo_transacao',
				'cod_indicador_da_transacao',
				'indicator_code',
				'codigo_indicador'
			),
			'counterparty_iban' => array(
				'iban',
				'counterparty_iban',
				'nib',
				'conta',
				'account',
				'account_number',
				'numero_conta',
				'beneficiary_account',
				'debtor_account',
				'creditor_account',
				'origin_account'
			),
			'counterparty_name' => array(
				'name',
				'counterparty',
				'beneficiary',
				'partner',
				'nome',
				'contraparte',
				'beneficiario',
				'ordenante',
				'emitente',
				'entity',
				'company',
				'third_party',
				'titular'
			),
			'currency' => array(
				'currency',
				'moeda',
				'devise',
				'divisa',
				'curr',
				'ccy',
				'currency_code'
			),
		);

		$this->fieldAliasesCache = $this->buildExpandedFieldAliases($aliases);

		return $this->fieldAliasesCache;
	}

	/**
	 * Expand field aliases into normalized variants.
	 *
	 * @param array<string,array<int,string>> $aliases
	 * @return array<string,array<int,string>>
	 */
	protected function buildExpandedFieldAliases($aliases)
	{
		$expanded = array();
		$subAliasOwner = array();
		$fieldSubAliasCandidates = array();
		foreach ((array) $aliases as $fieldKey => $fieldAliases) {
			$seen = array();
			$expanded[$fieldKey] = array();
			$fieldSubAliasCandidates[$fieldKey] = array();
			foreach ((array) $fieldAliases as $alias) {
				$normalized = $this->normalizeHeaderKey((string) $alias);
				if ($normalized === '') {
					continue;
				}
				if (!isset($seen[$normalized])) {
					$expanded[$fieldKey][] = $normalized;
					$seen[$normalized] = 1;
				}

				$parts = preg_split('/_+/', $normalized);
				if (is_array($parts) && count($parts) > 1) {
					foreach ($parts as $part) {
						$part = trim((string) $part);
						if (strlen($part) < 6 || isset($seen[$part])) {
							continue;
						}
						$fieldSubAliasCandidates[$fieldKey][] = $part;
						if (!isset($subAliasOwner[$part])) {
							$subAliasOwner[$part] = (string) $fieldKey;
						} elseif ((string) $subAliasOwner[$part] !== (string) $fieldKey) {
							$subAliasOwner[$part] = '';
						}
					}
				}
			}
		}
		foreach ($fieldSubAliasCandidates as $fieldKey => $subAliases) {
			$seen = array_flip((array) $expanded[$fieldKey]);
			foreach ((array) $subAliases as $subAlias) {
				$subAlias = trim((string) $subAlias);
				if ($subAlias === '' || isset($seen[$subAlias])) {
					continue;
				}
				if (!isset($subAliasOwner[$subAlias]) || (string) $subAliasOwner[$subAlias] !== (string) $fieldKey) {
					continue;
				}
				$expanded[$fieldKey][] = $subAlias;
				$seen[$subAlias] = 1;
			}
		}

		return $expanded;
	}

	/**
	 * Build stable layout fingerprint.
	 *
	 * @param string $format
	 * @param array<int,array<int,string>> $rows
	 * @param array<int,array{index:int,label:string}> $columns
	 * @param int $headerRowIndex
	 * @return string
	 */
	protected function buildLayoutFingerprint($format, $rows, $columns, $headerRowIndex)
	{
		$columnLabels = array();
		foreach ($columns as $column) {
			$columnLabels[] = $this->normalizeHeaderKey($column['label']);
		}

		$sampleRows = array_slice($rows, 0, 5);
		$sampleSignature = array();
		foreach ($sampleRows as $row) {
			$sampleSignature[] = count($row);
		}

		return hash('sha256', json_encode(array(
			'format' => $format,
			'header_row' => (int) $headerRowIndex,
			'columns' => $columnLabels,
			'sample' => $sampleSignature,
		)));
	}

	/**
	 * Build relaxed layout signature for template reuse across small file changes.
	 *
	 * @param string $format
	 * @param array<int,array{index:int,label:string}> $columns
	 * @param int $headerRowIndex
	 * @return string
	 */
	protected function buildLayoutSignature($format, $columns, $headerRowIndex)
	{
		$columnLabels = array();
		foreach ($columns as $column) {
			$label = isset($column['label']) ? (string) $column['label'] : '';
			$normalized = $this->normalizeHeaderKey($label);
			if ($normalized === '' || strpos($normalized, 'column_') === 0) {
				continue;
			}
			$columnLabels[] = $normalized;
		}

		return hash('sha256', json_encode(array(
			'format' => (string) $format,
			'header_row' => ((int) $headerRowIndex >= 0) ? 1 : 0,
			'columns' => $columnLabels,
			'count' => count($columns),
		)));
	}

	/**
	 * Parse tabular rows using explicit mapping.
	 *
	 * @param array<int,array<int,string>> $rows
	 * @param string $defaultCurrency
	 * @param array<string,mixed> $mapping
	 * @param int $limit
	 * @return array<int,array<string,mixed>>
	 */
	protected function parseTabularRowsWithMapping($rows, $defaultCurrency, $mapping, $limit = 0)
	{
		$rows = $this->normalizeTabularRows($rows);
		if (empty($rows)) {
			return array();
		}

		$mapping = $this->sanitizeMapping($mapping);
		$startIndex = ($mapping['header_row'] >= 0) ? ($mapping['header_row'] + 1) : 0;
		$lines = array();
		$rank = 0;
		$dateContexts = array(
			'operation_date' => array(),
			'value_date' => array(),
		);

		for ($i = $startIndex; $i < count($rows); $i++) {
			$row = $rows[$i];
			if ($this->looksLikeHeaderByMapping($row, $mapping)) {
				continue;
			}

			$line = $this->parseMappedRow($row, $defaultCurrency, $mapping, $rank, $dateContexts);
			if (empty($line)) {
				continue;
			}

			$lines[] = $line;
			$rank++;
			if ($limit > 0 && count($lines) >= $limit) {
				break;
			}
		}

		return $lines;
	}

	/**
	 * Parse one mapped row into normalized line shape.
	 *
	 * @param array<int,string> $row
	 * @param string $defaultCurrency
	 * @param array<string,int> $mapping
	 * @param int $rank
	 * @return array<string,mixed>
	 */
	protected function parseMappedRow($row, $defaultCurrency, $mapping, $rank, &$dateContexts = null)
	{
		$get = function ($key) use ($row, $mapping) {
			$idx = isset($mapping[$key]) ? (int) $mapping[$key] : -1;
			if ($idx < 0 || !isset($row[$idx])) {
				return '';
			}
			return trim((string) $row[$idx]);
		};

		$operationDateRaw = $get('operation_date');
		$valueDateRaw = $get('value_date');

		$amountRaw = $get('amount');
		$debitRaw = $get('debit');
		$creditRaw = $get('credit');
		$runningBalanceRaw = $get('running_balance');

		$description = $get('description');
		$paymentReference = $get('payment_reference');
		$bankReference = $get('bank_reference');
		$counterpartyIban = $get('counterparty_iban');
		$counterpartyName = $get('counterparty_name');
		$currencyRaw = $get('currency');

		$amount = null;
		$debitIdx = isset($mapping['debit']) ? (int) $mapping['debit'] : -1;
		$creditIdx = isset($mapping['credit']) ? (int) $mapping['credit'] : -1;
		if ($debitIdx >= 0 && $creditIdx >= 0 && $debitIdx === $creditIdx) {
			// Misconfigured mapping (same col selected for debit/credit): treat as single signed amount.
			$sharedRaw = ($debitRaw !== '') ? $debitRaw : $creditRaw;
			$amount = $this->parseLocalizedNumber($sharedRaw);
		} else {
			$debit = $this->parseLocalizedNumber($debitRaw);
			$credit = $this->parseLocalizedNumber($creditRaw);
			if ($debit !== null || $credit !== null) {
				$debit = ($debit !== null) ? abs($debit) : 0.0;
				$credit = ($credit !== null) ? abs($credit) : 0.0;
				$amount = (float) ($credit - $debit);
			} else {
				$amount = $this->parseLocalizedNumber($amountRaw);
			}
		}

		if ($amount === null) {
			return array();
		}
		if (abs($amount) < 0.0000001 && $description === '' && $paymentReference === '' && $counterpartyName === '' && $counterpartyIban === '') {
			return array();
		}

		$runningBalance = $this->parseLocalizedNumber($runningBalanceRaw);
		$operationDateContext = (is_array($dateContexts) && !empty($dateContexts['operation_date']) && is_array($dateContexts['operation_date'])) ? $dateContexts['operation_date'] : array();
		$valueDateContext = (is_array($dateContexts) && !empty($dateContexts['value_date']) && is_array($dateContexts['value_date'])) ? $dateContexts['value_date'] : array();
		$operationDate = $this->parseDate($operationDateRaw, $operationDateContext);
		$rowDateContext = array();
		if ($operationDate !== null) {
			$rowDateContext = $this->appendDateContext($rowDateContext, $operationDate);
			if (is_array($dateContexts)) {
				$dateContexts['operation_date'] = $this->appendDateContext($operationDateContext, $operationDate);
			}
		}
		$valueDate = $this->parseDate($valueDateRaw, array_merge($valueDateContext, $rowDateContext));
		if ($valueDate !== null && is_array($dateContexts)) {
			$dateContexts['value_date'] = $this->appendDateContext($valueDateContext, $valueDate);
		}
		if ($operationDate === null && $valueDate !== null) {
			$operationDate = $valueDate;
			if (is_array($dateContexts)) {
				$dateContexts['operation_date'] = $this->appendDateContext($operationDateContext, $operationDate);
			}
		}
		if ($valueDate === null && $operationDate !== null) {
			$valueDate = $operationDate;
			if (is_array($dateContexts)) {
				$dateContexts['value_date'] = $this->appendDateContext($valueDateContext, $valueDate);
			}
		}

		$currency = strtoupper(trim((string) $currencyRaw));
		if ($currency === '' || strlen($currency) !== 3 || !preg_match('/^[A-Z]{3}$/', $currency)) {
			$currency = strtoupper((string) $defaultCurrency);
		}

		if ($this->isLikelyCurrencyToken($counterpartyName, $currency)) {
			$counterpartyName = '';
		}
		if ($this->isLikelyCurrencyToken($paymentReference, $currency) && $description !== '' && $description !== $amountRaw) {
			$paymentReference = $description;
		}

		if ($bankReference === '') {
			$bankReference = $paymentReference;
		}

		return array(
			'line_rank' => (int) $rank,
			'operation_date' => $operationDate,
			'value_date' => $valueDate,
			'amount' => (float) $amount,
			'currency' => $currency,
			'running_balance' => $runningBalance,
			'counterparty_name' => $counterpartyName,
			'counterparty_iban' => $this->normalizeIban($counterpartyIban),
			'description' => $description,
			'payment_reference' => $paymentReference,
			'bank_reference' => $bankReference,
			'direction' => ($amount >= 0) ? 1 : -1,
			'line_uid' => sha1(implode('|', array($rank, $operationDateRaw, $valueDateRaw, $amount, $paymentReference, $description))),
		);
	}

	/**
	 * Normalize/sanitize incoming mapping payload.
	 *
	 * @param array<string,mixed> $mapping
	 * @return array<string,int>
	 */
	protected function sanitizeMapping($mapping)
	{
		$sanitized = $this->defaultMapping();
		foreach ($sanitized as $key => $defaultValue) {
			$value = isset($mapping[$key]) ? (int) $mapping[$key] : $defaultValue;
			$sanitized[$key] = ($value >= 0) ? $value : -1;
		}

		return $sanitized;
	}

	/**
	 * Detect if row still looks like a header under selected mapping.
	 *
	 * @param array<int,string> $row
	 * @param array<string,int> $mapping
	 * @return bool
	 */
	protected function looksLikeHeaderByMapping($row, $mapping)
	{
		$headerTokens = array();
		foreach ($this->getFieldAliases() as $aliases) {
			foreach ($aliases as $alias) {
				$headerTokens[$alias] = 1;
			}
		}

		foreach ($mapping as $field => $index) {
			if (!is_int($index) || $index < 0 || !isset($row[$index])) {
				continue;
			}
			$cell = $this->normalizeHeaderKey($row[$index]);
			if ($cell !== '' && isset($headerTokens[$cell])) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Parse generic tabular rows (CSV/XLS/XLSX) with shared row mapping.
	 *
	 * @param array<int,array<int,mixed>> $rows
	 * @param string $defaultCurrency
	 * @return array<int,array<string,mixed>>
	 */
	protected function parseTabularRows($rows, $defaultCurrency)
	{
		$normalizedRows = $this->normalizeTabularRows($rows);

		if (empty($normalizedRows)) {
			return array();
		}

		$headerRowIndex = $this->guessHeaderRowIndex($normalizedRows);
		$headerMap = ($headerRowIndex >= 0 && isset($normalizedRows[$headerRowIndex]))
			? $this->buildHeaderMap($normalizedRows[$headerRowIndex])
			: array();
		$columns = $this->buildColumnDefinitions($normalizedRows, $headerRowIndex);
		$mapping = $this->buildSuggestedMapping($headerMap, $columns, $normalizedRows, $headerRowIndex);
		$mapping['header_row'] = $headerRowIndex;

		return $this->parseTabularRowsWithMapping($normalizedRows, $defaultCurrency, $mapping, 0);
	}

	/**
	 * Get zero-based column index from Excel cell reference.
	 *
	 * @param string $cellRef
	 * @return int
	 */
	protected function columnIndexFromCellRef($cellRef)
	{
		if ($cellRef === '') {
			return -1;
		}
		if (!preg_match('/^([A-Z]+)\d+$/i', $cellRef, $match)) {
			return -1;
		}

		$letters = strtoupper($match[1]);
		$index = 0;
		for ($i = 0; $i < strlen($letters); $i++) {
			$index = ($index * 26) + (ord($letters[$i]) - 64);
		}

		return $index - 1;
	}

	/**
	 * Check if a shell command exists.
	 *
	 * @param string $command
	 * @return bool
	 */
	protected function isCommandAvailable($command)
	{
		if (!function_exists('exec') || trim((string) $command) === '') {
			return false;
		}

		$output = array();
		$exitCode = 1;
		@exec('command -v ' . escapeshellarg($command) . ' 2>/dev/null', $output, $exitCode);

		return $exitCode === 0 && !empty($output);
	}

	/**
	 * Detect CSV delimiter by sampling multiple non-empty lines.
	 *
	 * @param string $filePath
	 * @return string
	 */
	protected function detectCsvDelimiter($filePath)
	{
		$handle = fopen($filePath, 'r');
		if (!is_resource($handle)) {
			return ';';
		}

		$maxSampleLines = 10;
		$sampleLines = array();
		while (count($sampleLines) < $maxSampleLines && ($line = fgets($handle)) !== false) {
			$line = trim((string) $line);
			if ($line === '') {
				continue;
			}
			$sampleLines[] = $line;
		}
		fclose($handle);
		if (empty($sampleLines)) {
			return ';';
		}

		$delimiters = array(';', ',', "\t", '|');
		$bestDelimiter = ';';
		$bestScore = -1;
		foreach ($delimiters as $delimiter) {
			$linesWithDelimiter = 0;
			$totalCount = 0;
			$fieldCountFrequencies = array();
			foreach ($sampleLines as $sampleLine) {
				$count = substr_count((string) $sampleLine, (string) $delimiter);
				if ($count <= 0) {
					continue;
				}

				$linesWithDelimiter++;
				$totalCount += $count;
				$fieldsInLine = $count + 1;
				if (!isset($fieldCountFrequencies[$fieldsInLine])) {
					$fieldCountFrequencies[$fieldsInLine] = 0;
				}
				$fieldCountFrequencies[$fieldsInLine]++;
			}
			if ($linesWithDelimiter <= 0) {
				continue;
			}

			$maxConsistentLines = 0;
			foreach ($fieldCountFrequencies as $frequency) {
				$maxConsistentLines = max($maxConsistentLines, (int) $frequency);
			}
			$score = ($maxConsistentLines * 100) + ($linesWithDelimiter * 10) + $totalCount;
			if ($score > $bestScore) {
				$bestScore = $score;
				$bestDelimiter = $delimiter;
			}
		}

		return $bestDelimiter;
	}

	/**
	 * Determine if row likely contains headers.
	 *
	 * @param array<int,string> $row
	 * @return bool
	 */
	protected function isHeaderRow($row)
	{
		$headerMap = $this->buildHeaderMap((array) $row);
		if ($this->scoreHeaderMap($headerMap) >= 2) {
			return true;
		}

		$sample = $this->normalizeHeaderKey(implode('|', (array) $row));
		foreach ($this->getFieldAliases() as $aliases) {
			foreach ($aliases as $token) {
				if ($token !== '' && strpos($sample, $token) !== false) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Build lower-cased header map.
	 *
	 * @param array<int,string> $headers
	 * @return array<string,int>
	 */
	protected function buildHeaderMap($headers)
	{
		$map = array();
		foreach ($headers as $index => $header) {
			$key = $this->normalizeHeaderKey((string) $header);
			if ($key !== '' && !isset($map[$key])) {
				$map[$key] = (int) $index;
			}
		}

		return $map;
	}

	/**
	 * Extract mapped value by candidate names.
	 *
	 * @param array<int,string> $row
	 * @param array<string,int> $map
	 * @param array<int,string> $keys
	 * @param int $fallbackIndex
	 * @return string
	 */
	protected function extractMapped($row, $map, $keys, $fallbackIndex)
	{
		foreach ($keys as $key) {
			if (isset($map[$key]) && isset($row[$map[$key]])) {
				return (string) $row[$map[$key]];
			}
		}

		foreach ($map as $headerKey => $index) {
			foreach ($keys as $key) {
				if ($key === '') {
					continue;
				}
				if (strpos((string) $headerKey, (string) $key) !== false || strpos((string) $key, (string) $headerKey) !== false) {
					if (isset($row[$index])) {
						return (string) $row[$index];
					}
				}
			}
		}

		return isset($row[$fallbackIndex]) ? (string) $row[$fallbackIndex] : '';
	}

	/**
	 * Extract tag value from OFX block.
	 *
	 * @param string $block
	 * @param string $tag
	 * @return string
	 */
	protected function extractTagValue($block, $tag)
	{
		if (preg_match('/<' . preg_quote($tag, '/') . '>\s*([^<\r\n]+)/i', $block, $match)) {
			return trim($match[1]);
		}

		return '';
	}

	/**
	 * Normalize header token for matching.
	 *
	 * @param string $value
	 * @return string
	 */
	protected function normalizeHeaderKey($value)
	{
		$value = trim((string) $value);
		if ($value === '') {
			return '';
		}

		$normalized = $value;
		if (function_exists('iconv')) {
			$converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
			if (is_string($converted) && $converted !== '') {
				$normalized = $converted;
			}
		}

		$normalized = strtolower($normalized);
		$normalized = str_replace(array('-', '.', '/', '\\', '(', ')', ':', ';'), ' ', $normalized);
		$normalized = preg_replace('/\s+/', '_', $normalized);
		$normalized = preg_replace('/[^a-z0-9_]/', '', (string) $normalized);
		$normalized = trim((string) $normalized, '_');

		return $normalized;
	}

	/**
	 * Parse localized numeric value.
	 *
	 * @param string $value
	 * @return float|null
	 */
	protected function parseLocalizedNumber($value)
	{
		$value = trim((string) $value);
		if ($value === '') {
			return null;
		}

		$letterProbe = str_replace(chr(194) . chr(160), ' ', $value);
		$letterProbe = preg_replace('/\b(?:EUR|USD|GBP|CHF|CAD|AUD|JPY|NZD|NOK|SEK|DKK|PLN|CZK|HUF|RON|BRL|MXN|ZAR|INR|CNY|AOA|MZN|CVE|STN|DR|CR|DB)\b/ui', '', (string) $letterProbe);
		$letterProbe = str_replace(array('€', '$', '£', '¥'), '', (string) $letterProbe);
		if (preg_match('/[A-Za-zÀ-ÿ]/u', (string) $letterProbe)) {
			// Reject mixed alphanumeric tokens (for example statement descriptions containing refs).
			return null;
		}

		$value = str_replace(array(chr(194) . chr(160), ' '), '', $value);
		$value = str_ireplace(array('EUR', 'USD', 'GBP', 'CHF', 'CAD', 'AUD', 'JPY', 'NZD', 'NOK', 'SEK', 'DKK', 'PLN', 'CZK', 'HUF', 'RON', 'BRL', 'MXN', 'ZAR', 'INR', 'CNY', 'AOA', 'MZN', 'CVE', 'STN', 'DR', 'CR', 'DB', '€', '$', '£', '¥'), '', $value);
		$value = trim((string) $value);
		if ($value === '') {
			return null;
		}

		$negative = false;
		if (preg_match('/^\((.*)\)$/', $value, $match)) {
			$negative = true;
			$value = trim((string) $match[1]);
		}

		$hasLeadingMinus = (substr($value, 0, 1) === '-');
		$hasTrailingMinus = (substr($value, -1) === '-');
		if ($hasLeadingMinus) {
			$value = substr($value, 1);
		}
		if ($hasTrailingMinus) {
			$value = substr($value, 0, -1);
		}
		if ($hasLeadingMinus || $hasTrailingMinus) {
			$negative = true;
		}
		$value = trim((string) $value);
		if ($value === '') {
			return null;
		}
		if (strpos($value, '-') !== false) {
			// Reject malformed internal sign placement after canonical sign extraction.
			return null;
		}

		$value = preg_replace('/[^0-9,\.\-]/', '', $value);
		if ($value === '' || $value === '-' || $value === ',' || $value === '.') {
			return null;
		}

		$commaCount = substr_count($value, ',');
		$dotCount = substr_count($value, '.');

		if ($commaCount > 0 && $dotCount > 0) {
			$lastComma = strrpos($value, ',');
			$lastDot = strrpos($value, '.');
			if ($lastComma !== false && $lastDot !== false) {
				if ($lastComma > $lastDot) {
					$value = str_replace('.', '', $value);
					$value = str_replace(',', '.', $value);
				} else {
					$value = str_replace(',', '', $value);
				}
			}
		} elseif ($commaCount > 0) {
			if ($commaCount > 1 && preg_match('/,\d{3}$/', $value)) {
				$value = str_replace(',', '', $value);
			} else {
				$value = str_replace(',', '.', $value);
			}
		} elseif ($dotCount > 1 && preg_match('/\.\d{3}$/', $value)) {
			$value = str_replace('.', '', $value);
		}

		if (!is_numeric($value)) {
			return null;
		}

		$number = (float) $value;
		if ($negative) {
			$number = -abs($number);
		}

		return $number;
	}

	/**
	 * Parse flexible bank-statement date values into Y-m-d.
	 *
	 * @param string $value
	 * @param array<int,array<string,int>>|null $context
	 * @return string|null
	 */
	protected function parseDate($value, $context = null)
	{
		$value = (string) $value;
		// Defensive cleanup for malformed spreadsheet/csv cells containing binary nulls/control chars.
		$value = str_replace("\0", '', $value);
		$value = str_replace(chr(194) . chr(160), ' ', $value);
		$value = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $value);
		$value = trim($value);
		if ($value === '') {
			return null;
		}

		if (preg_match('/^\d{8}(?:\d{6}(?:\.\d+)?)?$/', $value)) {
			$token = substr($value, 0, 8);
			$ymdYear = (int) substr($token, 0, 4);
			$ymdMonth = (int) substr($token, 4, 2);
			$ymdDay = (int) substr($token, 6, 2);
			$ymdValid = ($ymdYear >= 1900 && $ymdYear <= 2200 && checkdate($ymdMonth, $ymdDay, $ymdYear));

			$dmyDay = (int) substr($token, 0, 2);
			$dmyMonth = (int) substr($token, 2, 2);
			$dmyYear = (int) substr($token, 4, 4);
			$dmyValid = ($dmyYear >= 1900 && $dmyYear <= 2200 && checkdate($dmyMonth, $dmyDay, $dmyYear));

			if ($ymdValid && $dmyValid) {
				$currentYear = (int) date('Y');
				$firstFourDigits = (int) substr($token, 0, 4);
				$preferYmd = ($firstFourDigits >= 1970 && $firstFourDigits <= ($currentYear + 5));
				if (function_exists('dol_syslog')) {
					dol_syslog(
						'KreaBank parser ambiguous 8-digit date token "' . $token . '" resolved as ' . ($preferYmd ? 'YYYYMMDD' : 'DDMMYYYY'),
						LOG_WARNING
					);
				}

				if ($preferYmd) {
					return sprintf('%04d-%02d-%02d', $ymdYear, $ymdMonth, $ymdDay);
				}

				return sprintf('%04d-%02d-%02d', $dmyYear, $dmyMonth, $dmyDay);
			}

			if ($ymdValid) {
				return sprintf('%04d-%02d-%02d', $ymdYear, $ymdMonth, $ymdDay);
			}
			if ($dmyValid) {
				return sprintf('%04d-%02d-%02d', $dmyYear, $dmyMonth, $dmyDay);
			}
		}
		if (is_numeric($value)) {
			$excelSerial = (float) $value;
			// Excel serial date (days from 1899-12-30) for typical modern bank exports.
			if ($excelSerial > 20000 && $excelSerial < 90000) {
				$timestamp = (int) round(($excelSerial - 25569) * 86400);
				if ($timestamp > 0) {
					return gmdate('Y-m-d', $timestamp);
				}
			}
		}

		$formats = array(
			'Y-m-d',
			'Y/n/j',
			'd-m-Y',
			'j-n-Y',
			'd/m/Y',
			'j/n/Y',
			'm/d/Y',
			'n/j/Y',
			'd.m.Y',
			'j.n.Y',
			'Y/m/d',
			'Y-m-d H:i:s',
			'Y-m-d H:i',
			'Y-m-d\TH:i:s.uP',
			'Y-m-d\TH:i:s.u\Z',
			'Y-m-d\TH:i:s',
			'Y-m-d\TH:i:sP',
		);
		foreach ($formats as $format) {
			$date = $this->parseDateWithStrictFormat($value, $format);
			if ($date instanceof DateTimeInterface) {
				return $date->format('Y-m-d');
			}
		}

		if ($this->isLikelyAmountTokenForDateParsing($value)) {
			return null;
		}

		$dateParts = $this->parseBankDateParts($value, $context);
		if (is_array($dateParts)) {
			return sprintf('%04d-%02d-%02d', (int) $dateParts['year'], (int) $dateParts['month'], (int) $dateParts['day']);
		}

		if (preg_match('/[A-Za-z:TZ]/', $value)) {
			$timestamp = strtotime($value);
			if ($timestamp !== false) {
				return date('Y-m-d', $timestamp);
			}
		}

		return null;
	}

	/**
	 * Parse bank-statement date parts using context-aware heuristics.
	 *
	 * @param string $raw
	 * @param array<int,array<string,int>>|null $context
	 * @return array<string,int>|false
	 */
	protected function parseBankDateParts($raw, $context = null)
	{
		$raw = trim((string) $raw);
		if ($raw === '') {
			return false;
		}

		$monthNames = $this->getBankDateMonthNames();
		$namedMonthResult = $this->matchNamedMonthDate($raw, $monthNames, $context);
		if (is_array($namedMonthResult)) {
			return $namedMonthResult;
		}

		$parts = preg_split('/[\s\-\/\.\,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY);
		if (!is_array($parts)) {
			return false;
		}

		$digits = array();
		foreach ($parts as $part) {
			$part = trim((string) $part);
			if ($part !== '' && ctype_digit($part)) {
				$digits[] = $part;
			}
		}

		$count = count($digits);
		if ($count === 3) {
			return $this->resolveThreePartBankDate($digits, $context);
		}
		if ($count === 2) {
			return $this->resolveTwoPartBankDate($digits, $context);
		}
		if ($count === 1) {
			return $this->resolveOnePartBankDate($digits[0], $context);
		}

		return false;
	}

	/**
	 * Return normalized month-name map used by bank date parsing.
	 *
	 * @return array<string,int>
	 */
	protected function getBankDateMonthNames()
	{
		return array(
			'jan' => 1,
			'january' => 1,
			'janeiro' => 1,
			'ene' => 1,
			'enero' => 1,
			'feb' => 2,
			'february' => 2,
			'fev' => 2,
			'fevereiro' => 2,
			'febrero' => 2,
			'mar' => 3,
			'march' => 3,
			'marco' => 3,
			'marzo' => 3,
			'apr' => 4,
			'april' => 4,
			'abr' => 4,
			'abril' => 4,
			'may' => 5,
			'mai' => 5,
			'maio' => 5,
			'jun' => 6,
			'june' => 6,
			'junho' => 6,
			'junio' => 6,
			'jul' => 7,
			'july' => 7,
			'julho' => 7,
			'julio' => 7,
			'aug' => 8,
			'august' => 8,
			'ago' => 8,
			'agosto' => 8,
			'sep' => 9,
			'sept' => 9,
			'september' => 9,
			'set' => 9,
			'setembro' => 9,
			'septiembre' => 9,
			'oct' => 10,
			'october' => 10,
			'out' => 10,
			'outubro' => 10,
			'octubre' => 10,
			'nov' => 11,
			'november' => 11,
			'novembro' => 11,
			'noviembre' => 11,
			'dec' => 12,
			'december' => 12,
			'dez' => 12,
			'dezembro' => 12,
			'dic' => 12,
			'diciembre' => 12,
		);
	}

	/**
	 * Resolve dates containing month names.
	 *
	 * @param string $raw
	 * @param array<string,int> $monthNames
	 * @param array<int,array<string,int>>|null $context
	 * @return array<string,int>|false
	 */
	protected function matchNamedMonthDate($raw, $monthNames, $context = null)
	{
		$normalizedRaw = $this->normalizeBankDateText($raw);
		if ($normalizedRaw === '') {
			return false;
		}

		foreach ($monthNames as $name => $monthNumber) {
			if (!preg_match('/(?:^|[^a-z])' . preg_quote($name, '/') . '(?=$|[^a-z])/', $normalizedRaw)) {
				continue;
			}

			$stripped = preg_replace('/(?:^|[^a-z])' . preg_quote($name, '/') . '(?=$|[^a-z])/', ' ', $normalizedRaw, 1);
			$tokens = preg_split('/[\s\-\/\.\,]+/', (string) $stripped, -1, PREG_SPLIT_NO_EMPTY);
			if (!is_array($tokens)) {
				$tokens = array();
			}

			$digits = array();
			foreach ($tokens as $token) {
				if (ctype_digit((string) $token)) {
					$digits[] = (string) $token;
				}
			}

			$count = count($digits);
			if ($count === 0) {
				$year = $this->inferBankDateYear(null, $context);
				if ($year === false) {
					return false;
				}
				return $this->validateBankDateParts((int) $year, (int) $monthNumber, 1);
			}

			if ($count === 1) {
				$number = (int) $digits[0];
				if ($number > 31) {
					return $this->validateBankDateParts($this->expandBankDateYear($number), (int) $monthNumber, $this->inferBankDateDay(null, $context));
				}

				$year = $this->inferBankDateYear(null, $context);
				if ($year === false) {
					return false;
				}

				return $this->validateBankDateParts((int) $year, (int) $monthNumber, $number);
			}

			if ($count === 2) {
				$a = (int) $digits[0];
				$b = (int) $digits[1];
				if ($a > 31) {
					return $this->validateBankDateParts($this->expandBankDateYear($a), (int) $monthNumber, $b);
				}
				if ($b > 31) {
					return $this->validateBankDateParts($this->expandBankDateYear($b), (int) $monthNumber, $a);
				}

				$day = ($a <= $b) ? $a : $b;
				$year = $this->expandBankDateYear(($a <= $b) ? $b : $a);
				return $this->validateBankDateParts($year, (int) $monthNumber, $day);
			}

			sort($digits, SORT_NUMERIC);
			$year = $this->expandBankDateYear((int) array_pop($digits));
			$day = (int) $digits[0];
			return $this->validateBankDateParts($year, (int) $monthNumber, $day);
		}

		return false;
	}

	/**
	 * Resolve three numeric date tokens.
	 *
	 * @param array<int,string> $parts
	 * @param array<int,array<string,int>>|null $context
	 * @return array<string,int>|false
	 */
	protected function resolveThreePartBankDate($parts, $context = null)
	{
		$a = (int) $parts[0];
		$b = (int) $parts[1];
		$c = (int) $parts[2];

		if ($a > 1900) {
			return $this->validateBankDateParts($a, $b, $c);
		}
		if ($c > 1900) {
			if ($a > 12) {
				return $this->validateBankDateParts($c, $b, $a);
			}
			if ($b > 12) {
				return $this->validateBankDateParts($c, $a, $b);
			}

			$ddmmyyyyCandidate = $this->validateBankDateParts($c, $b, $a);
			$mmddyyyyCandidate = $this->validateBankDateParts($c, $a, $b);
			if (is_array($ddmmyyyyCandidate) && !is_array($mmddyyyyCandidate)) {
				return $ddmmyyyyCandidate;
			}
			if (!is_array($ddmmyyyyCandidate) && is_array($mmddyyyyCandidate)) {
				return $mmddyyyyCandidate;
			}
			if (is_array($ddmmyyyyCandidate) && is_array($mmddyyyyCandidate)) {
				$contextMonth = $this->inferBankDateMonth(null, $context);
				if ($contextMonth !== false) {
					$ddmmyyyyMatchesContext = ((int) $ddmmyyyyCandidate['month'] === (int) $contextMonth);
					$mmddyyyyMatchesContext = ((int) $mmddyyyyCandidate['month'] === (int) $contextMonth);
					if ($ddmmyyyyMatchesContext && !$mmddyyyyMatchesContext) {
						return $ddmmyyyyCandidate;
					}
					if ($mmddyyyyMatchesContext && !$ddmmyyyyMatchesContext) {
						return $mmddyyyyCandidate;
					}
				}

				// Keep DD/MM/YYYY as conservative default when ambiguity remains unresolved.
				return $ddmmyyyyCandidate;
			}

			return false;
		}

		$candidates = array(
			array('y' => $c, 'm' => $b, 'd' => $a),
			array('y' => $a, 'm' => $b, 'd' => $c),
			array('y' => $c, 'm' => $a, 'd' => $b),
		);
		foreach ($candidates as $candidate) {
			$result = $this->validateBankDateParts($this->expandBankDateYear((int) $candidate['y']), (int) $candidate['m'], (int) $candidate['d']);
			if (is_array($result)) {
				return $result;
			}
		}

		return false;
	}

	/**
	 * Resolve two numeric date tokens using context.
	 *
	 * @param array<int,string> $parts
	 * @param array<int,array<string,int>>|null $context
	 * @return array<string,int>|false
	 */
	protected function resolveTwoPartBankDate($parts, $context = null)
	{
		$a = (int) $parts[0];
		$b = (int) $parts[1];

		if ($a > 1900) {
			$month = $this->inferBankDateMonth($b, $context);
			$day = ($b > 12) ? $b : $this->inferBankDateDay($b, $context);
			if ($month === false) {
				return false;
			}
			return $this->validateBankDateParts($a, (int) $month, (int) $day);
		}
		if ($b > 1900) {
			$month = $this->inferBankDateMonth($a, $context);
			$day = ($a > 12) ? $a : $this->inferBankDateDay($a, $context);
			if ($month === false) {
				return false;
			}
			return $this->validateBankDateParts($b, (int) $month, (int) $day);
		}

		if ($a > 31) {
			$month = $this->inferBankDateMonth($b, $context);
			if ($month === false) {
				return false;
			}
			return $this->validateBankDateParts($this->expandBankDateYear($a), (int) $month, $this->inferBankDateDay(null, $context));
		}
		if ($b > 31) {
			$month = $this->inferBankDateMonth($a, $context);
			if ($month === false) {
				return false;
			}
			return $this->validateBankDateParts($this->expandBankDateYear($b), (int) $month, $this->inferBankDateDay(null, $context));
		}

		$year = $this->inferBankDateYear(null, $context);
		if ($year === false) {
			return false;
		}
		if ($a > 12) {
			return $this->validateBankDateParts((int) $year, $b, $a);
		}

		return $this->validateBankDateParts((int) $year, $b, $a);
	}

	/**
	 * Resolve one compact or partial date token.
	 *
	 * @param string $token
	 * @param array<int,array<string,int>>|null $context
	 * @return array<string,int>|false
	 */
	protected function resolveOnePartBankDate($token, $context = null)
	{
		$token = trim((string) $token);
		$length = strlen($token);
		$number = (int) $token;

		if ($length === 8) {
			$isoCandidate = $this->validateBankDateParts((int) substr($token, 0, 4), (int) substr($token, 4, 2), (int) substr($token, 6, 2));
			if (is_array($isoCandidate)) {
				return $isoCandidate;
			}

			return $this->validateBankDateParts((int) substr($token, 4, 4), (int) substr($token, 2, 2), (int) substr($token, 0, 2));
		}

		if ($length === 6) {
			$yymmdd = $this->validateBankDateParts($this->expandBankDateYear((int) substr($token, 0, 2)), (int) substr($token, 2, 2), (int) substr($token, 4, 2));
			if (is_array($yymmdd)) {
				return $yymmdd;
			}
			$ddmmyy = $this->validateBankDateParts($this->expandBankDateYear((int) substr($token, 4, 2)), (int) substr($token, 2, 2), (int) substr($token, 0, 2));
			if (is_array($ddmmyy)) {
				return $ddmmyy;
			}
		}

		if ($number >= 1 && $number <= 31) {
			$year = $this->inferBankDateYear(null, $context);
			$month = $this->inferBankDateMonth(null, $context);
			if ($year !== false && $month !== false) {
				return $this->validateBankDateParts((int) $year, (int) $month, $number);
			}
		}

		return false;
	}

	/**
	 * Expand 2-digit year using a forward/backward sliding window.
	 *
	 * @param int $year
	 * @return int
	 */
	protected function expandBankDateYear($year)
	{
		$year = (int) $year;
		if ($year > 1900) {
			return $year;
		}

		static $cachedCurrentYear = null;
		if (!is_int($cachedCurrentYear) || $cachedCurrentYear <= 0) {
			$cachedCurrentYear = (int) date('Y');
		}

		$currentYear = (int) $cachedCurrentYear;
		$century = (int) floor($currentYear / 100);
		$pivot = ((int) ($currentYear % 100)) + 50;
		if ($year > $pivot) {
			$century--;
		}

		return ($century * 100) + $year;
	}

	/**
	 * Validate year/month/day ranges and calendar correctness.
	 *
	 * @param int $year
	 * @param int $month
	 * @param int $day
	 * @return array<string,int>|false
	 */
	protected function validateBankDateParts($year, $month, $day)
	{
		$year = (int) $year;
		$month = (int) $month;
		$day = (int) $day;
		if ($year < 1900 || $year > 2100) {
			return false;
		}
		if ($month < 1 || $month > 12) {
			return false;
		}
		if ($day < 1 || $day > 31) {
			return false;
		}
		if (!checkdate($month, $day, $year)) {
			return false;
		}

		return array(
			'year' => $year,
			'month' => $month,
			'day' => $day,
		);
	}

	/**
	 * Infer year from resolved date context.
	 *
	 * @param int|null $hint
	 * @param array<int,array<string,int>>|null $context
	 * @return int|false
	 */
	protected function inferBankDateYear($hint = null, $context = null)
	{
		if ($hint !== null && (int) $hint > 1900) {
			return (int) $hint;
		}
		if (!empty($context) && is_array($context)) {
			foreach (array_reverse($context) as $resolvedDate) {
				if (is_array($resolvedDate) && !empty($resolvedDate['year'])) {
					return (int) $resolvedDate['year'];
				}
			}
		}

		return false;
	}

	/**
	 * Infer month from context or hint.
	 *
	 * @param int|null $hint
	 * @param array<int,array<string,int>>|null $context
	 * @return int|false
	 */
	protected function inferBankDateMonth($hint = null, $context = null)
	{
		if ($hint !== null && (int) $hint >= 1 && (int) $hint <= 12) {
			return (int) $hint;
		}
		if (!empty($context) && is_array($context)) {
			foreach (array_reverse($context) as $resolvedDate) {
				if (is_array($resolvedDate) && !empty($resolvedDate['month'])) {
					return (int) $resolvedDate['month'];
				}
			}
		}

		return false;
	}

	/**
	 * Infer day from context or fallback.
	 *
	 * @param int|null $hint
	 * @param array<int,array<string,int>>|null $context
	 * @return int
	 */
	protected function inferBankDateDay($hint = null, $context = null)
	{
		if ($hint !== null && (int) $hint >= 1 && (int) $hint <= 31) {
			return (int) $hint;
		}

		return 1;
	}

	/**
	 * Normalize free-form date text to ASCII/lower-case for token matching.
	 *
	 * @param string $value
	 * @return string
	 */
	protected function normalizeBankDateText($value)
	{
		$value = trim((string) $value);
		if ($value === '') {
			return '';
		}

		$normalized = $value;
		if (function_exists('iconv')) {
			$converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $normalized);
			if (is_string($converted) && $converted !== '') {
				$normalized = $converted;
			}
		}

		if (function_exists('mb_strtolower')) {
			$normalized = mb_strtolower($normalized, 'UTF-8');
		} else {
			$normalized = strtolower($normalized);
		}

		return trim((string) preg_replace('/\s+/', ' ', $normalized));
	}

	/**
	 * Reject numeric amount-like tokens before bank-specific date heuristics run.
	 *
	 * @param string $value
	 * @return bool
	 */
	protected function isLikelyAmountTokenForDateParsing($value)
	{
		$value = trim((string) $value);
		if ($value === '') {
			return false;
		}
		if (strpos($value, '/') !== false) {
			return false;
		}
		if ($this->parseLocalizedNumber($value) === null) {
			return false;
		}
		if (strpos($value, ',') !== false) {
			return true;
		}
		if (preg_match('/\.\d{1,4}$/', $value)) {
			return true;
		}
		if (strpos($value, '-') !== false || strpos($value, '+') !== false || strpos($value, '(') !== false || strpos($value, ')') !== false) {
			return true;
		}

		return false;
	}

	/**
	 * Convert a parsed Y-m-d date string into context entry and append it.
	 *
	 * @param array<int,array<string,int>> $context
	 * @param string $dateYmd
	 * @return array<int,array<string,int>>
	 */
	protected function appendDateContext($context, $dateYmd)
	{
		$context = is_array($context) ? array_values($context) : array();
		$contextEntry = $this->buildDateContextEntry($dateYmd);
		if (empty($contextEntry)) {
			return $context;
		}

		$context[] = $contextEntry;
		if (count($context) > 24) {
			$context = array_slice($context, -24);
		}

		return $context;
	}

	/**
	 * Convert Y-m-d string into bank-date context item.
	 *
	 * @param string $dateYmd
	 * @return array<string,int>
	 */
	protected function buildDateContextEntry($dateYmd)
	{
		$dateYmd = trim((string) $dateYmd);
		if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $dateYmd, $matches)) {
			return array();
		}

		return array(
			'year' => (int) $matches[1],
			'month' => (int) $matches[2],
			'day' => (int) $matches[3],
		);
	}

	/**
	 * Parse date using exact format and reject overflowed/invalid values.
	 *
	 * @param string $value
	 * @param string $format
	 * @return DateTimeInterface|null
	 */
	protected function parseDateWithStrictFormat($value, $format)
	{
		$date = DateTime::createFromFormat('!' . $format, (string) $value);
		if (!($date instanceof DateTimeInterface)) {
			return null;
		}

		$lastErrors = DateTime::getLastErrors();
		if (is_array($lastErrors) && ((!empty($lastErrors['warning_count'])) || (!empty($lastErrors['error_count'])))) {
			return null;
		}

		return $date;
	}

	/**
	 * Check if token is likely just a currency marker.
	 *
	 * @param string $value
	 * @param string $currency
	 * @return bool
	 */
	protected function isLikelyCurrencyToken($value, $currency = '')
	{
		$value = strtoupper(trim((string) $value));
		$currency = strtoupper(trim((string) $currency));
		if ($value === '') {
			return false;
		}
		if ($currency !== '' && $value === $currency) {
			return true;
		}

		return (bool) preg_match('/^[A-Z]{3}$/', $value);
	}

	/**
	 * Normalize iban format for matching.
	 *
	 * @param string $iban
	 * @return string
	 */
	protected function normalizeIban($iban)
	{
		$iban = strtoupper((string) $iban);
		$iban = preg_replace('/\s+/', '', $iban);

		return (string) $iban;
	}
}
