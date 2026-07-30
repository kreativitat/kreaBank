<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

require_once __DIR__ . '/KreaBankParser.class.php';
require_once __DIR__ . '/KreaBankMatcher.class.php';
require_once __DIR__ . '/KreaBankNativeBankAdapter.class.php';
require_once __DIR__ . '/../lib/kreabank.lib.php';

/**
 * Fallback translator for non-HTTP contexts.
 */
class KreaBankNullLangs
{
	/**
	 * @param string $key
	 * @return string
	 */
	public function trans($key)
	{
		return (string) $key;
	}
}

/**
 * Application service for bank reconciliation.
 */
class KreaBankService
{
	/** @var DoliDB */
	public $db;

	/** @var User */
	public $user;

	/** @var Translate */
	public $langs;

	/** @var int */
	protected $entity;

	/** @var KreaBankParser */
	protected $parser;

	/** @var KreaBankMatcher */
	protected $matcher;

	/** @var KreaBankNativeBankAdapter */
	protected $native;

	/** @var bool */
	protected $importProfileTableChecked = false;

	/** @var bool */
	protected $reconAuditTableChecked = false;

	/** @var bool */
	protected $quickEntryTableChecked = false;

	/** @var bool */
	protected $patternTableChecked = false;

	/** @var bool */
	protected $batchMlSampleTableChecked = false;

	/** @var bool */
	protected $coreSchemaChecked = false;

	/** @var array<int,string> */
	protected $bankCurrencyCache = array();

	/** @var bool */
	protected $batchMlClassifierReady = false;

	/** @var object|null */
	protected $batchMlClassifier = null;

	/** @var array<int,array<string,mixed>> */
	protected $batchMlSamples = array();

	/** @var string|null */
	protected $batchMlClassifierCacheKey = null;

	/**
	 * @param DoliDB $db
	 * @param User $user
	 * @param Translate $langs
	 */
	public function __construct($db, $user, $langs)
	{
		global $conf;

		$this->db = $db;
		$this->user = $user;
		$this->langs = (is_object($langs) && method_exists($langs, 'trans')) ? $langs : new KreaBankNullLangs();
		$this->entity = (int) (isset($conf->entity) ? $conf->entity : 1);
		$this->parser = new KreaBankParser();
		$this->matcher = new KreaBankMatcher();
		$this->native = new KreaBankNativeBankAdapter($this->db, $this->user, $this->langs, $this->entity);
		$this->ensureCoreSchema();
	}

	/**
	 * Ensure module schema is available and backward-compatible.
	 *
	 * @return void
	 */
	protected function ensureCoreSchema()
	{
		if ($this->coreSchemaChecked) {
			return;
		}
		$this->coreSchemaChecked = true;

		$this->native->ensureSchema();
		$this->ensureImportProfileTable();
		$this->ensureReconAuditTable();
		$this->ensureQuickEntryTable();
		$this->ensurePatternTable();
		$this->ensureBatchMlSampleTable();
	}

	/**
	 * Log database/schema diagnostics for remote troubleshooting.
	 *
	 * @param string $context
	 * @return array<string,mixed>
	 */
	public function logSchemaDiagnostics($context = '')
	{
		$context = trim((string) $context);
		$prefix = (string) $this->db->prefix();
		$databaseName = '';
		$resDbName = $this->db->query('SELECT DATABASE() as dbname');
		if ($resDbName && ($objDbName = $this->db->fetch_object($resDbName))) {
			$databaseName = (string) (!empty($objDbName->dbname) ? $objDbName->dbname : '');
		}

		$required = array(
			'kreabank_statement' => array('entity', 'ref', 'source_type', 'bank_account_id', 'statement_date', 'date_import', 'fk_user_import', 'status', 'currency', 'datec'),
			'kreabank_statement_line' => array('entity', 'fk_statement', 'fk_native_bank_line', 'line_uid', 'line_rank', 'operation_date', 'value_date', 'amount', 'currency', 'status', 'duplicate_hash', 'is_duplicate', 'datec'),
			'kreabank_bankmeta' => array('entity', 'fk_bank_line', 'bank_account_id', 'idempotency_hash', 'operation_date', 'amount', 'currency', 'status', 'datec'),
			'kreabank_import_profile' => array('entity', 'bank_account_id', 'source_type', 'fingerprint', 'layout_signature', 'mapping_json', 'template_json', 'datec'),
			'kreabank_quick_entry' => array('entity', 'fk_statement_line', 'entry_type', 'amount', 'currency', 'status', 'doc_type', 'fk_doc', 'fk_user_author', 'datec'),
			'kreabank_pattern' => array('entity', 'pattern_type', 'pattern_value', 'doc_type', 'fk_doc', 'hit_count', 'last_score', 'datec'),
			'kreabank_recon_audit' => array('entity', 'audit_type', 'fk_statement_line', 'fk_reconciliation', 'payload_json', 'datec'),
			'kreabank_ml_sample' => array('entity', 'label', 'features_json', 'datec'),
		);

		$issues = array();
		foreach ($required as $tableSuffix => $columns) {
			$tableName = $prefix . $tableSuffix;
			if (!$this->tableExists($tableName)) {
				$issues[] = 'missing_table:' . $tableName;
				continue;
			}
			foreach ($columns as $column) {
				if (!$this->tableHasColumn($tableName, $column)) {
					$issues[] = 'missing_column:' . $tableName . '.' . $column;
				}
			}
		}

		$meta = 'context=' . ($context !== '' ? $context : 'default');
		$meta .= ', entity=' . (int) $this->entity;
		$meta .= ', db=' . ($databaseName !== '' ? $databaseName : 'unknown');
		$meta .= ', prefix=' . $prefix;

		if (empty($issues)) {
			dol_syslog('KreaBank DB diagnostics OK (' . $meta . ')', LOG_INFO);
		} else {
			dol_syslog('KreaBank DB diagnostics issues (' . $meta . '): ' . implode(', ', $issues), LOG_WARNING);
		}

		return array(
			'ok' => empty($issues),
			'issues' => $issues,
			'context' => $context,
			'entity' => (int) $this->entity,
			'database' => $databaseName,
			'prefix' => $prefix,
		);
	}

	/**
	 * Get referential diagnostics for staged statements and native metadata.
	 *
	 * @return array<string,mixed>
	 */
	public function getReferentialIntegrityDiagnostics()
	{
		$result = array(
			'entity' => (int) $this->entity,
			'orphan_statement_lines' => 0,
			'orphan_statements' => 0,
			'orphan_bankmeta_rows' => 0,
			'bankmeta_account_mismatches' => 0,
			'ok' => true,
		);

		$statementTable = $this->db->prefix() . 'kreabank_statement';
		$lineTable = $this->db->prefix() . 'kreabank_statement_line';
		$bankMetaTable = $this->db->prefix() . 'kreabank_bankmeta';
		$bankTable = $this->db->prefix() . 'bank';
		$bankAccountTable = $this->db->prefix() . 'bank_account';
		if (!$this->tableExists($statementTable) || !$this->tableExists($lineTable) || !$this->tableExists($bankMetaTable)) {
			return $result;
		}

		$queryCount = function ($sql) {
			$resql = $this->db->query((string) $sql);
			if (!$resql) {
				return 0;
			}
			$obj = $this->db->fetch_object($resql);
			if (!$obj || !isset($obj->nb)) {
				return 0;
			}

			return (int) $obj->nb;
		};

		$sqlOrphanLines = 'SELECT COUNT(l.rowid) as nb';
		$sqlOrphanLines .= ' FROM ' . $lineTable . ' as l';
		$sqlOrphanLines .= ' LEFT JOIN ' . $statementTable . ' as s ON s.rowid = l.fk_statement AND s.entity = ' . ((int) $this->entity);
		$sqlOrphanLines .= ' WHERE l.entity = ' . ((int) $this->entity);
		$sqlOrphanLines .= ' AND (l.fk_statement IS NULL OR l.fk_statement <= 0 OR s.rowid IS NULL)';
		$result['orphan_statement_lines'] = $queryCount($sqlOrphanLines);

		$sqlOrphanStatements = 'SELECT COUNT(s.rowid) as nb';
		$sqlOrphanStatements .= ' FROM ' . $statementTable . ' as s';
		$sqlOrphanStatements .= ' LEFT JOIN ' . $bankAccountTable . ' as ba ON ba.rowid = s.bank_account_id';
		$sqlOrphanStatements .= ' WHERE s.entity = ' . ((int) $this->entity);
		$sqlOrphanStatements .= ' AND (s.bank_account_id IS NULL OR s.bank_account_id <= 0 OR ba.rowid IS NULL OR ba.entity <> ' . ((int) $this->entity) . ')';
		$result['orphan_statements'] = $queryCount($sqlOrphanStatements);

		$sqlOrphanMeta = 'SELECT COUNT(m.rowid) as nb';
		$sqlOrphanMeta .= ' FROM ' . $bankMetaTable . ' as m';
		$sqlOrphanMeta .= ' LEFT JOIN ' . $bankTable . ' as b ON b.rowid = m.fk_bank_line';
		$sqlOrphanMeta .= ' WHERE m.entity = ' . ((int) $this->entity);
		$sqlOrphanMeta .= ' AND (m.fk_bank_line IS NULL OR m.fk_bank_line <= 0 OR b.rowid IS NULL)';
		$result['orphan_bankmeta_rows'] = $queryCount($sqlOrphanMeta);

		$sqlMetaMismatch = 'SELECT COUNT(m.rowid) as nb';
		$sqlMetaMismatch .= ' FROM ' . $bankMetaTable . ' as m';
		$sqlMetaMismatch .= ' INNER JOIN ' . $bankTable . ' as b ON b.rowid = m.fk_bank_line';
		$sqlMetaMismatch .= ' INNER JOIN ' . $bankAccountTable . ' as ba ON ba.rowid = b.fk_account';
		$sqlMetaMismatch .= ' WHERE m.entity = ' . ((int) $this->entity);
		$sqlMetaMismatch .= ' AND (ba.entity <> ' . ((int) $this->entity) . ' OR (m.bank_account_id > 0 AND m.bank_account_id <> b.fk_account))';
		$result['bankmeta_account_mismatches'] = $queryCount($sqlMetaMismatch);

		$result['ok'] = (
			(int) $result['orphan_statement_lines'] === 0
			&& (int) $result['orphan_statements'] === 0
			&& (int) $result['orphan_bankmeta_rows'] === 0
			&& (int) $result['bankmeta_account_mismatches'] === 0
		);

		return $result;
	}

	/**
	 * Import one statement file.
	 *
	 * @param string $filePath
	 * @param string $fileName
	 * @param int $bankAccountId
	 * @param string|null $statementDate
	 * @param array<string,mixed> $options
	 * @return array<string,mixed>
	 */
	public function importStatement($filePath, $fileName, $bankAccountId = 0, $statementDate = null, $options = array())
	{
		$defaultCurrency = $this->resolveDefaultCurrency((int) $bankAccountId);
		$parsed = $this->parser->parse($filePath, $fileName, $defaultCurrency);
		return $this->importStatementFromParsed($parsed, $fileName, $bankAccountId, $statementDate, (array) $options);
	}

	/**
	 * Analyze import file and preload best mapping profile when available.
	 *
	 * @param string $filePath
	 * @param string $fileName
	 * @param int $bankAccountId
	 * @return array<string,mixed>
	 */
	public function analyzeImportFile($filePath, $fileName, $bankAccountId = 0, $forceMappingEditor = false)
	{
		$defaultCurrency = $this->resolveDefaultCurrency((int) $bankAccountId);
		$analysis = $this->parser->analyze($filePath, $fileName, $defaultCurrency, !empty($forceMappingEditor));
		if (!empty($analysis['supports_mapping']) && !empty($analysis['fingerprint'])) {
			$profile = $this->getBestImportProfile(
				(int) $bankAccountId,
				(string) $analysis['format'],
				(string) $analysis['fingerprint'],
				!empty($analysis['layout_signature']) ? (string) $analysis['layout_signature'] : ''
			);
			if (!empty($profile) && !empty($profile['mapping']) && is_array($profile['mapping'])) {
				$rawQuality = $this->evaluateRawMappingQuality(
					(!empty($analysis['preview_rows']) && is_array($analysis['preview_rows'])) ? $analysis['preview_rows'] : array(),
					$profile['mapping']
				);
				$profilePreviewLines = array();
				$profilePreviewError = '';
				if (!empty($rawQuality['ok'])) {
					try {
						$parsedPreview = $this->parser->parseWithMapping($filePath, $fileName, $profile['mapping'], $defaultCurrency);
						$profilePreviewLines = array_slice((array) $parsedPreview['lines'], 0, 20);
					} catch (Throwable $e) {
						$profilePreviewError = $e->getMessage();
					}
				}

				$quality = $this->evaluateMappingPreviewQuality($profilePreviewLines);
				if (!empty($rawQuality['ok']) && $profilePreviewError === '' && !empty($quality['ok'])) {
					$analysis['suggested_mapping'] = $profile['mapping'];
					$analysis['profile_applied'] = 1;
					$analysis['profile'] = $profile;
					$shouldReplacePreview = empty($analysis['mapping_forced']) || empty($analysis['sample_lines']);
					if ($shouldReplacePreview) {
						$analysis['sample_lines'] = $profilePreviewLines;
					}
				} else {
					$analysis['profile_rejected'] = 1;
					$analysis['profile'] = $profile;
					$analysis['profile_reject_reason'] = !empty($rawQuality['ok'])
						? ($profilePreviewError !== '' ? 'preview_parse_error' : (string) (!empty($quality['reason']) ? $quality['reason'] : 'invalid_preview'))
						: (string) (!empty($rawQuality['reason']) ? $rawQuality['reason'] : 'invalid_raw_mapping');
					$analysis['profile_reject_metrics'] = $quality;
					$analysis['profile_reject_raw_metrics'] = $rawQuality;
					$logContext = 'entity=' . (int) $this->entity . ', profile=' . (int) $profile['rowid'] . ', bank=' . (int) $bankAccountId . ', reason=' . (string) $analysis['profile_reject_reason'];
					if ($profilePreviewError !== '') {
						$logContext .= ', parse_error=' . $profilePreviewError;
					}
					if (!empty($quality)) {
						$logContext .= ', metrics=' . json_encode($quality);
					}
					if (!empty($rawQuality)) {
						$logContext .= ', raw_metrics=' . json_encode($rawQuality);
					}
					dol_syslog('KreaBank import profile rejected (' . $logContext . ')', LOG_WARNING);
				}
			}
		}

		return $analysis;
	}

	/**
	 * Validate whether mapped preview looks plausible for banking data.
	 *
	 * @param array<int,array<string,mixed>> $lines
	 * @return array<string,mixed>
	 */
	protected function evaluateMappingPreviewQuality($lines)
	{
		$metrics = array(
			'ok' => 0,
			'reason' => '',
			'line_count' => 0,
			'checked_count' => 0,
			'date_count' => 0,
			'amount_count' => 0,
			'description_count' => 0,
			'huge_amount_count' => 0,
		);

		$lines = is_array($lines) ? $lines : array();
		$metrics['line_count'] = count($lines);
		if (count($lines) < 3) {
			$metrics['reason'] = 'too_few_lines';
			return $metrics;
		}

		$checked = array_slice($lines, 0, min(15, count($lines)));
		$metrics['checked_count'] = count($checked);
		foreach ($checked as $line) {
			if (!is_array($line)) {
				continue;
			}

			$date = '';
			if (!empty($line['operation_date'])) {
				$date = trim((string) $line['operation_date']);
			} elseif (!empty($line['value_date'])) {
				$date = trim((string) $line['value_date']);
			}
			if ($date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
				$metrics['date_count']++;
			}

			if (array_key_exists('amount', $line) && is_numeric((string) $line['amount'])) {
				$metrics['amount_count']++;
				if (abs((float) $line['amount']) >= 100000.0) {
					$metrics['huge_amount_count']++;
				}
			}

			$description = trim((string) (!empty($line['description']) ? $line['description'] : ''));
			if ($description !== '') {
				$metrics['description_count']++;
			}
		}

		if ((int) $metrics['amount_count'] < max(3, (int) floor(((int) $metrics['checked_count']) * 0.60))) {
			$metrics['reason'] = 'insufficient_amounts';
			return $metrics;
		}
		if ((int) $metrics['description_count'] < max(2, (int) floor(((int) $metrics['checked_count']) * 0.40))) {
			$metrics['reason'] = 'insufficient_descriptions';
			return $metrics;
		}
		if ((int) $metrics['date_count'] <= 0) {
			$metrics['reason'] = 'missing_dates';
			return $metrics;
		}
		if ((int) $metrics['huge_amount_count'] > 0 && (int) $metrics['date_count'] < 2) {
			$metrics['reason'] = 'suspicious_huge_amounts';
			return $metrics;
		}

		$metrics['ok'] = 1;
		$metrics['reason'] = 'ok';
		return $metrics;
	}

	/**
	 * Validate profile mapping against raw preview rows before parsing.
	 *
	 * @param array<int,array<int,string>> $previewRows
	 * @param array<string,mixed> $mapping
	 * @return array<string,mixed>
	 */
	protected function evaluateRawMappingQuality($previewRows, $mapping)
	{
		$metrics = array(
			'ok' => 0,
			'reason' => '',
			'checked_rows' => 0,
			'amount_index' => -1,
			'amount_non_empty' => 0,
			'amount_alpha_count' => 0,
			'operation_date_non_empty' => 0,
			'operation_date_valid_count' => 0,
			'value_date_non_empty' => 0,
			'value_date_valid_count' => 0,
		);

		$previewRows = is_array($previewRows) ? $previewRows : array();
		$mapping = is_array($mapping) ? $mapping : array();
		if (empty($previewRows)) {
			$metrics['ok'] = 1;
			$metrics['reason'] = 'no_preview_rows';
			return $metrics;
		}

		$headerRow = isset($mapping['header_row']) ? (int) $mapping['header_row'] : -1;
		$amountIdx = isset($mapping['amount']) ? (int) $mapping['amount'] : -1;
		$debitIdx = isset($mapping['debit']) ? (int) $mapping['debit'] : -1;
		$creditIdx = isset($mapping['credit']) ? (int) $mapping['credit'] : -1;
		$operationDateIdx = isset($mapping['operation_date']) ? (int) $mapping['operation_date'] : -1;
		$valueDateIdx = isset($mapping['value_date']) ? (int) $mapping['value_date'] : -1;

		$start = ($headerRow >= 0) ? ($headerRow + 1) : 0;
		$sample = array_slice($previewRows, $start, 25);
		foreach ($sample as $row) {
			if (!is_array($row)) {
				continue;
			}
			$metrics['checked_rows']++;

			$amountCell = '';
			if ($amountIdx >= 0 && isset($row[$amountIdx])) {
				$amountCell = trim((string) $row[$amountIdx]);
			}
			if ($amountCell === '' && $amountIdx < 0 && $debitIdx >= 0 && isset($row[$debitIdx])) {
				$amountCell = trim((string) $row[$debitIdx]);
			}
			if ($amountCell === '' && $amountIdx < 0 && $creditIdx >= 0 && isset($row[$creditIdx])) {
				$amountCell = trim((string) $row[$creditIdx]);
			}
			if ($amountCell !== '') {
				$metrics['amount_non_empty']++;
				if (preg_match('/[A-Za-zÀ-ÿ]/u', $amountCell)) {
					$metrics['amount_alpha_count']++;
				}
			}

			if ($operationDateIdx >= 0 && isset($row[$operationDateIdx])) {
				$dateCell = trim((string) $row[$operationDateIdx]);
				if ($dateCell !== '') {
					$metrics['operation_date_non_empty']++;
					if ($this->isLikelyRawDateCell($dateCell)) {
						$metrics['operation_date_valid_count']++;
					}
				}
			}
			if ($valueDateIdx >= 0 && isset($row[$valueDateIdx])) {
				$dateCell = trim((string) $row[$valueDateIdx]);
				if ($dateCell !== '') {
					$metrics['value_date_non_empty']++;
					if ($this->isLikelyRawDateCell($dateCell)) {
						$metrics['value_date_valid_count']++;
					}
				}
			}
		}

		$metrics['amount_index'] = $amountIdx;

		if ((int) $metrics['amount_non_empty'] >= 3) {
			$amountAlphaRatio = ((int) $metrics['amount_non_empty'] > 0)
				? ((float) $metrics['amount_alpha_count'] / (float) $metrics['amount_non_empty'])
				: 0.0;
			if ($amountAlphaRatio >= 0.35) {
				$metrics['reason'] = 'amount_column_contains_text';
				return $metrics;
			}
		}

		if ($operationDateIdx >= 0 && (int) $metrics['operation_date_non_empty'] >= 3) {
			$operationDateRatio = ((float) $metrics['operation_date_valid_count']) / (float) max(1, (int) $metrics['operation_date_non_empty']);
			if ($operationDateRatio < 0.30) {
				$metrics['reason'] = 'operation_date_column_invalid';
				return $metrics;
			}
		}
		if ($valueDateIdx >= 0 && (int) $metrics['value_date_non_empty'] >= 3) {
			$valueDateRatio = ((float) $metrics['value_date_valid_count']) / (float) max(1, (int) $metrics['value_date_non_empty']);
			if ($valueDateRatio < 0.30) {
				$metrics['reason'] = 'value_date_column_invalid';
				return $metrics;
			}
		}

		$metrics['ok'] = 1;
		$metrics['reason'] = 'ok';
		return $metrics;
	}

	/**
	 * Lightweight date detector for raw preview cells.
	 *
	 * @param string $value
	 * @return bool
	 */
	protected function isLikelyRawDateCell($value)
	{
		$value = trim((string) $value);
		if ($value === '') {
			return false;
		}

		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
			return true;
		}
		if (preg_match('/^\d{1,2}[\/\.-]\d{1,2}[\/\.-]\d{4}$/', $value)) {
			return true;
		}
		if (is_numeric($value)) {
			$numeric = (float) $value;
			if ($numeric > 20000.0 && $numeric < 90000.0) {
				return true; // Excel serial date range
			}
		}

		return false;
	}

	/**
	 * Parse statement file using default parser detection.
	 *
	 * @param string $filePath
	 * @param string $fileName
	 * @param int $bankAccountId
	 * @return array{format:string,lines:array<int,array<string,mixed>>,raw:array<string,mixed>}
	 */
	public function parseStatementFile($filePath, $fileName, $bankAccountId = 0)
	{
		$defaultCurrency = $this->resolveDefaultCurrency((int) $bankAccountId);
		return $this->parser->parse($filePath, $fileName, $defaultCurrency);
	}

	/**
	 * Parse statement file with explicit mapping.
	 *
	 * @param string $filePath
	 * @param string $fileName
	 * @param array<string,mixed> $mapping
	 * @param int $bankAccountId
	 * @return array{format:string,lines:array<int,array<string,mixed>>,raw:array<string,mixed>}
	 */
	public function parseStatementWithMapping($filePath, $fileName, $mapping, $bankAccountId = 0)
	{
		$defaultCurrency = $this->resolveDefaultCurrency((int) $bankAccountId);
		return $this->parser->parseWithMapping($filePath, $fileName, $mapping, $defaultCurrency);
	}

	/**
	 * Persist statement import profile for future recognition.
	 *
	 * @param int $bankAccountId
	 * @param string $sourceType
	 * @param string $fingerprint
	 * @param string $label
	 * @param array<string,mixed> $mapping
	 * @param int $headerRow
	 * @param array<string,mixed> $templateMeta
	 * @return void
	 */
	public function saveImportProfile($bankAccountId, $sourceType, $fingerprint, $label, $mapping, $headerRow = -1, $templateMeta = array())
	{
		$this->ensureImportProfileTable();
		$fingerprint = trim((string) $fingerprint);
		$sourceType = trim((string) $sourceType);
		if ($fingerprint === '' || $sourceType === '' || !is_array($mapping)) {
			return;
		}

		$label = trim((string) $label);
		if ($label === '') {
			$label = 'Import profile ' . date('Y-m-d H:i:s');
		}

		$payload = json_encode($mapping);
		if ($payload === false) {
			return;
		}

		$layoutSignature = !empty($templateMeta['layout_signature']) ? trim((string) $templateMeta['layout_signature']) : '';
		$templatePayload = '';
		if (!empty($templateMeta) && is_array($templateMeta)) {
			$templatePayload = (string) json_encode($templateMeta);
			if ($templatePayload === 'null') {
				$templatePayload = '';
			}
		}
		$now = dol_now();
		$table = $this->db->prefix() . 'kreabank_import_profile';

		$sql = 'INSERT INTO ' . $table . ' (';
		$sql .= 'entity, bank_account_id, source_type, fingerprint, layout_signature, label, header_row, mapping_json, template_json, datec';
		$sql .= ') VALUES (';
		$sql .= $this->entity;
		$sql .= ', ' . ((int) $bankAccountId > 0 ? (int) $bankAccountId : 0);
		$sql .= ', ' . $this->sqlText($sourceType);
		$sql .= ', ' . $this->sqlText($fingerprint);
		$sql .= ', ' . $this->sqlText($layoutSignature);
		$sql .= ', ' . $this->sqlText($label);
		$sql .= ', ' . ((int) $headerRow);
		$sql .= ', ' . $this->sqlText($payload);
		$sql .= ', ' . $this->sqlText($templatePayload);
		$sql .= ', ' . $this->sqlDateTime($now);
		$sql .= ')';
		$sql .= ' ON DUPLICATE KEY UPDATE';
		$sql .= ' label = VALUES(label),';
		$sql .= ' header_row = VALUES(header_row),';
		$sql .= ' mapping_json = VALUES(mapping_json),';
		$sql .= ' layout_signature = VALUES(layout_signature),';
		$sql .= ' template_json = VALUES(template_json)';

		if ($this->db->query($sql)) {
			$this->persistTemplateMlSamples($mapping, $templateMeta);
		}
	}

	/**
	 * Import from parsed payload.
	 *
	 * @param array{format:string,lines:array<int,array<string,mixed>>} $parsed
	 * @param string $fileName
	 * @param int $bankAccountId
	 * @param string|null $statementDate
	 * @param array<string,mixed> $options
	 * @return array<string,mixed>
	 */
	public function importStatementFromParsed($parsed, $fileName, $bankAccountId = 0, $statementDate = null, $options = array())
	{
		$lines = isset($parsed['lines']) && is_array($parsed['lines']) ? $parsed['lines'] : array();
		$format = !empty($parsed['format']) ? (string) $parsed['format'] : 'csv';
		if (empty($lines)) {
			throw new Exception($this->langs->trans('ErrorNoDataToImport'));
		}

		return $this->importStatementLines($lines, $fileName, $format, $bankAccountId, $statementDate, (array) $options);
	}

	/**
	 * Import parsed lines into statement tables.
	 *
	 * @param array<int,array<string,mixed>> $lines
	 * @param string $fileName
	 * @param string $format
	 * @param int $bankAccountId
	 * @param string|null $statementDate
	 * @param array<string,mixed> $options
	 * @return array<string,mixed>
	 */
	protected function importStatementLines($lines, $fileName, $format, $bankAccountId = 0, $statementDate = null, $options = array())
	{
		if (empty($lines)) {
			throw new Exception($this->langs->trans('ErrorNoDataToImport'));
		}

		$result = $this->native->importLines($lines, $fileName, $format, (int) $bankAccountId, $statementDate, (array) $options);

		$this->logAudit(
			'import',
			null,
			null,
			array(
				'statement_id' => (int) (!empty($result['statement_id']) ? $result['statement_id'] : 0),
				'statement_ref' => !empty($result['statement_ref']) ? (string) $result['statement_ref'] : '',
				'file' => $fileName,
				'format' => $format,
				'imported_lines' => (int) $result['imported_lines'],
				'duplicates' => (int) $result['duplicates'],
				'duplicates_skipped' => (int) (!empty($result['duplicates_skipped']) ? $result['duplicates_skipped'] : $result['duplicates']),
				'duplicates_imported' => (int) (!empty($result['duplicates_imported']) ? $result['duplicates_imported'] : 0),
				'allow_duplicate_import' => !empty($options['allow_duplicate_import']) ? 1 : 0,
				'duplicates_reconciled' => (int) $result['duplicates_reconciled'],
				'bank_line_ids' => !empty($result['bank_line_ids']) ? (array) $result['bank_line_ids'] : array(),
			)
		);

		return array(
			'statement_id' => (int) (!empty($result['statement_id']) ? $result['statement_id'] : 0),
			'statement_ref' => !empty($result['statement_ref']) ? (string) $result['statement_ref'] : '',
			'imported_lines' => (int) $result['imported_lines'],
			'duplicates' => (int) $result['duplicates'],
			'duplicates_skipped' => (int) (!empty($result['duplicates_skipped']) ? $result['duplicates_skipped'] : $result['duplicates']),
			'duplicates_imported' => (int) (!empty($result['duplicates_imported']) ? $result['duplicates_imported'] : 0),
			'duplicates_reconciled' => (int) $result['duplicates_reconciled'],
			'format' => (string) $result['format'],
			'bank_line_ids' => !empty($result['bank_line_ids']) ? (array) $result['bank_line_ids'] : array(),
			'duplicates_details' => !empty($result['duplicates_details']) ? (array) $result['duplicates_details'] : array(),
		);
	}

	/**
	 * Load pending or partially reconciled statement lines.
	 *
	 * @param int $limit
	 * @param int $offset
	 * @param string $sortfield
	 * @param string $sortorder
	 * @param array<string,mixed> $filters
	 * @param bool $includeSkipped Include manually skipped/deferred lines (status=3)
	 * @return array<int,array<string,mixed>>
	 */
	public function getPendingLines($limit = 200, $offset = 0, $sortfield = 'operation_date', $sortorder = 'ASC', $filters = array(), $includeSkipped = false)
	{
		return $this->native->getPendingLines((int) $limit, (int) $offset, (string) $sortfield, (string) $sortorder, (array) $filters, (bool) $includeSkipped);
	}

	/**
	 * Get total pending statement lines count.
	 *
	 * @param array<string,mixed> $filters
	 * @param bool $includeSkipped Include manually skipped/deferred lines (status=3)
	 * @return int
	 */
	public function getPendingLinesCount($filters = array(), $includeSkipped = false)
	{
		return $this->native->getPendingLinesCount((array) $filters, (bool) $includeSkipped);
	}

	/**
	 * Get one statement line.
	 *
	 * @param int $lineId
	 * @return array<string,mixed>|null
	 */
	public function getLineById($lineId)
	{
		return $this->native->getLineById((int) $lineId);
	}

	/**
	 * Delete one imported native statement.
	 *
	 * @param int $bankAccountId
	 * @param string $statementRef
	 * @param bool $allowReconciled
	 * @return array<string,mixed>
	 */
	public function deleteImportedStatement($bankAccountId, $statementRef, $allowReconciled = false)
	{
		$bankAccountId = (int) $bankAccountId;
		$statementRef = trim((string) $statementRef);
		if ($bankAccountId <= 0 || $statementRef === '') {
			throw new Exception($this->langs->trans('KreaBankInvalidInput'));
		}

		if (!$this->db->begin()) {
			throw new Exception('Failed to start database transaction');
		}
		try {
			$result = $this->native->deleteImportedStatement($bankAccountId, $statementRef, (bool) $allowReconciled);
			if (!empty($result['blocked']) && (int) $result['reconciled_lines'] > 0) {
				throw new Exception($this->langs->trans('KreaBankImportDeleteBlockedReconciled'));
			}
			if ((int) $result['deleted_lines'] <= 0) {
				throw new Exception($this->langs->trans('KreaBankImportDeleteNotFound'));
			}

			$this->db->commit();
		} catch (Exception $e) {
			$this->db->rollback();
			throw $e;
		}

		$this->logAudit(
			'delete_statement',
			null,
			null,
			array(
				'statement_ref' => $statementRef,
				'bank_account_id' => $bankAccountId,
				'deleted_lines' => (int) $result['deleted_lines'],
				'reconciled_lines' => (int) $result['reconciled_lines'],
				'native' => 1,
			)
		);

		return $result;
	}

	/**
	 * Ensure one imported statement has native bank lines available for native statement view.
	 *
	 * @param int $bankAccountId
	 * @param string $statementRef
	 * @return array<string,int>
	 */
	public function ensureNativeStatementLines($bankAccountId, $statementRef)
	{
		$bankAccountId = (int) $bankAccountId;
		$statementRef = trim((string) $statementRef);
		if ($bankAccountId <= 0 || $statementRef === '') {
			throw new Exception($this->langs->trans('KreaBankInvalidInput'));
		}
		$bankAccountEntityList = getEntity('bank_account');
		if ($bankAccountEntityList === '' || $bankAccountEntityList === null) {
			$bankAccountEntityList = (string) ((int) $this->entity);
		}

		$sqlNative = 'SELECT COUNT(b.rowid) as nb';
		$sqlNative .= ' FROM ' . $this->db->prefix() . 'bank as b';
		$sqlNative .= ' INNER JOIN ' . $this->db->prefix() . 'bank_account as ba ON ba.rowid = b.fk_account';
		$sqlNative .= ' WHERE ba.entity IN (' . $bankAccountEntityList . ')';
		$sqlNative .= ' AND b.fk_account = ' . $bankAccountId;
		$sqlNative .= ' AND b.num_releve = ' . $this->sqlText($statementRef);
		$resNative = $this->db->query($sqlNative);
		if (!$resNative) {
			throw new Exception($this->db->lasterror());
		}
		$objNative = $this->db->fetch_object($resNative);
		$nativeCount = (!empty($objNative->nb) ? (int) $objNative->nb : 0);
		if ($nativeCount > 0) {
			return array(
				'statement_id' => 0,
				'line_count' => $nativeCount,
				'created_count' => 0,
				'existing_count' => $nativeCount,
			);
		}

		$statementTable = $this->db->prefix() . 'kreabank_statement';
		$lineTable = $this->db->prefix() . 'kreabank_statement_line';
		if (!$this->tableExists($statementTable) || !$this->tableExists($lineTable)) {
			throw new Exception($this->langs->trans('KreaBankImportDeleteNotFound'));
		}

		$sqlStatement = 'SELECT s.rowid';
		$sqlStatement .= ' FROM ' . $statementTable . ' as s';
		$sqlStatement .= ' WHERE s.entity = ' . ((int) $this->entity);
		$sqlStatement .= ' AND s.bank_account_id = ' . $bankAccountId;
		$sqlStatement .= ' AND s.ref = ' . $this->sqlText($statementRef);
		$sqlStatement .= $this->db->plimit(1, 0);
		$resStatement = $this->db->query($sqlStatement);
		if (!$resStatement) {
			throw new Exception($this->db->lasterror());
		}
		$objStatement = $this->db->fetch_object($resStatement);
		if (empty($objStatement->rowid)) {
			throw new Exception($this->langs->trans('KreaBankImportDeleteNotFound'));
		}
		$statementId = (int) $objStatement->rowid;

		$sqlLines = 'SELECT l.rowid, l.fk_native_bank_line';
		$sqlLines .= ' FROM ' . $lineTable . ' as l';
		$sqlLines .= ' WHERE l.entity = ' . ((int) $this->entity);
		$sqlLines .= ' AND l.fk_statement = ' . $statementId;
		$sqlLines .= ' ORDER BY l.line_rank ASC, l.rowid ASC';
		$resLines = $this->db->query($sqlLines);
		if (!$resLines) {
			throw new Exception($this->db->lasterror());
		}

		$lineRows = array();
		while ($objLine = $this->db->fetch_object($resLines)) {
			$lineRows[] = array(
				'line_id' => (int) $objLine->rowid,
				'native_id' => (int) $objLine->fk_native_bank_line,
			);
		}

		$lineCount = 0;
		$createdCount = 0;
		$existingCount = 0;
		if (!$this->db->begin()) {
			throw new Exception('Failed to start database transaction');
		}
		try {
			foreach ($lineRows as $lineRow) {
				$lineId = (int) $lineRow['line_id'];
				if ($lineId <= 0) {
					continue;
				}
				$lineCount++;
				$beforeNativeId = (int) $lineRow['native_id'];
				$resolvedNativeId = (int) $this->native->ensureNativeLineForStatementLine($lineId);
				if ($beforeNativeId > 0 && $beforeNativeId === $resolvedNativeId) {
					$existingCount++;
				} else {
					$createdCount++;
				}
			}
			$this->db->commit();
		} catch (Exception $e) {
			$this->db->rollback();
			throw $e;
		}

		return array(
			'statement_id' => $statementId,
			'line_count' => $lineCount,
			'created_count' => $createdCount,
			'existing_count' => $existingCount,
		);
	}

	/**
	 * Get open reconciliation candidates (native links, open payments, open invoices).
	 *
	 * @param int $direction
	 * @param int $limit
	 * @param string|null $anchorDate
	 * @param int|null $intervalDays
	 * @param bool $includeReconciledLinked Include linked payments already conciliated in native bank module
	 * @return array<int,array<string,mixed>>
	 */
	public function getOpenDocuments($direction = 0, $limit = 250, $anchorDate = null, $intervalDays = null, $excludeBankLineId = 0, $bankAccountId = 0, $includeReconciledLinked = true)
	{
		$direction = (int) $direction;
		$limit = max(20, (int) $limit);
		$excludeBankLineId = (int) $excludeBankLineId;
		$includeReconciledLinked = !empty($includeReconciledLinked);
		$excludeNativeLineId = $excludeBankLineId;
		if ($excludeBankLineId > 0) {
			$excludeLine = $this->getLineById($excludeBankLineId);
			if (!empty($excludeLine)) {
				if (!empty($excludeLine['native_bank_line_id'])) {
					$excludeNativeLineId = (int) $excludeLine['native_bank_line_id'];
				} elseif (!empty($excludeLine['fk_statement'])) {
					$excludeNativeLineId = 0;
				}
			}
		}

		$documents = array();
		$seen = array();
		$nativeLinkedPaymentIds = array(
			'payment' => array(),
			'payment_supplier' => array(),
		);
		$appendDocuments = static function ($items) use (&$documents, &$seen, &$nativeLinkedPaymentIds) {
			if (empty($items) || !is_array($items)) {
				return;
			}
			foreach ($items as $item) {
				if (!is_array($item)) {
					continue;
				}

				$docType = trim((string) (!empty($item['doc_type']) ? $item['doc_type'] : ''));
				$docId = (int) (!empty($item['rowid']) ? $item['rowid'] : 0);
				if ($docType === '' || $docId <= 0) {
					continue;
				}

				if ($docType === 'native_bank') {
					$nativeCustomerPaymentId = (int) (!empty($item['customer_payment_id']) ? $item['customer_payment_id'] : 0);
					$nativeSupplierPaymentId = (int) (!empty($item['supplier_payment_id']) ? $item['supplier_payment_id'] : 0);
					if ($nativeCustomerPaymentId > 0) {
						$nativeLinkedPaymentIds['payment'][$nativeCustomerPaymentId] = true;
					}
					if ($nativeSupplierPaymentId > 0) {
						$nativeLinkedPaymentIds['payment_supplier'][$nativeSupplierPaymentId] = true;
					}
				}

				if ($docType === 'payment_linked' && isset($nativeLinkedPaymentIds['payment'][$docId])) {
					continue;
				}
				if ($docType === 'payment_supplier_linked' && isset($nativeLinkedPaymentIds['payment_supplier'][$docId])) {
					continue;
				}

				$dedupeType = $docType;
				if ($docType === 'payment_linked') {
					$dedupeType = 'payment';
				} elseif ($docType === 'payment_supplier_linked') {
					$dedupeType = 'payment_supplier';
				}
				$key = $dedupeType . '__' . $docId;
				if (isset($seen[$key])) {
					continue;
				}

				$seen[$key] = true;
				$documents[] = $item;
			}
		};

		$appendDocuments($this->getOpenNativeBankDocuments($direction, $limit, $anchorDate, $intervalDays, $excludeNativeLineId, (int) $bankAccountId));
		if ($direction !== 0) {
			$appendDocuments($this->getOpenNativeBankDocuments(0, $limit, $anchorDate, $intervalDays, $excludeNativeLineId, (int) $bankAccountId));
		}
		$appendDocuments($this->getOpenPaymentDocuments(0, $limit, $anchorDate, $intervalDays));
		$appendDocuments($this->getOpenInvoiceDocuments($direction, $limit, $anchorDate, $intervalDays));
		$appendDocuments($this->getLinkedPaymentDocuments(0, $limit, $anchorDate, $intervalDays, null, (int) $bankAccountId, $includeReconciledLinked));

		return $documents;
	}

	/**
	 * Get open native bank lines as reconciliation candidates.
	 * Uses only bank-native tables (bank, bank_account, bank_url, optional bankmeta).
	 *
	 * @param int $direction
	 * @param int $limit
	 * @param string|null $anchorDate
	 * @param int|null $intervalDays
	 * @param int $excludeBankLineId
	 * @param int $bankAccountId
	 * @return array<int,array<string,mixed>>
	 */
	protected function getOpenNativeBankDocuments($direction = 0, $limit = 250, $anchorDate = null, $intervalDays = null, $excludeBankLineId = 0, $bankAccountId = 0)
	{
		$limit = max(20, (int) $limit);
		$excludeBankLineId = (int) $excludeBankLineId;
		$bankAccountId = (int) $bankAccountId;
		$dateRange = $this->resolveOpenDocumentsDateRange($anchorDate, $intervalDays);

		$sql = 'SELECT b.rowid as bank_line_id, b.dateo as doc_date, b.amount as amount_open, b.label as native_label, b.note as native_note,';
		$sql .= ' ba.currency_code as bank_currency,';
		$sql .= ' m.counterparty_name, m.counterparty_iban, m.description, m.payment_reference, m.bank_reference,';
		$sql .= " GROUP_CONCAT(DISTINCT bu.label ORDER BY bu.label SEPARATOR ' / ') as url_labels,";
		$sql .= " GROUP_CONCAT(DISTINCT bu.type ORDER BY bu.type SEPARATOR ',') as url_types,";
		$sql .= " MAX(CASE WHEN bu.type = 'payment_supplier' THEN bu.url_id ELSE 0 END) as supplier_payment_id,";
		$sql .= " MAX(CASE WHEN bu.type = 'payment' THEN bu.url_id ELSE 0 END) as customer_payment_id";
		$sql .= ' FROM ' . $this->db->prefix() . 'bank as b';
		$sql .= ' INNER JOIN ' . $this->db->prefix() . 'bank_account as ba ON ba.rowid = b.fk_account';
		$sql .= ' LEFT JOIN ' . $this->db->prefix() . 'kreabank_bankmeta as m ON m.entity = ' . ((int) $this->entity) . ' AND m.fk_bank_line = b.rowid';
		$sql .= ' LEFT JOIN ' . $this->db->prefix() . 'bank_url as bu ON bu.fk_bank = b.rowid';
		$sql .= ' WHERE ba.entity = ' . ((int) $this->entity);
		$sql .= ' AND ba.clos = 0';
		$sql .= ' AND ba.rappro = 1';
		$sql .= ' AND b.rappro = 0';
		if ($bankAccountId > 0) {
			$sql .= ' AND b.fk_account = ' . $bankAccountId;
		}
		if ($excludeBankLineId > 0) {
			$sql .= ' AND b.rowid <> ' . $excludeBankLineId;
		}
		// Keep reconciliation focused on imported-vs-native matching, not imported-vs-imported duplicates.
		$sql .= " AND (m.source_type IS NULL OR m.source_type = '')";
		if ($direction > 0) {
			$sql .= ' AND b.amount > 0.0000001';
		} elseif ($direction < 0) {
			$sql .= ' AND b.amount < -0.0000001';
		}
		if (!empty($dateRange['enabled'])) {
			$sql .= ' AND b.dateo >= ' . $this->sqlDate($dateRange['start']);
			$sql .= ' AND b.dateo <= ' . $this->sqlDate($dateRange['end']);
		}
		$sql .= ' GROUP BY b.rowid, b.dateo, b.amount, b.label, b.note, ba.currency_code, m.counterparty_name, m.counterparty_iban, m.description, m.payment_reference, m.bank_reference';
		$sql .= ' ORDER BY b.dateo ASC, b.rowid ASC';
		$sql .= $this->db->plimit($limit, 0);

		$resql = $this->db->query($sql);
		if (!$resql) {
			throw new Exception($this->db->lasterror());
		}

		$documents = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$rowid = (int) $obj->bank_line_id;
			$paymentReference = trim((string) (!empty($obj->payment_reference) ? $obj->payment_reference : ''));
			$bankReference = trim((string) (!empty($obj->bank_reference) ? $obj->bank_reference : ''));
			$urlLabels = trim((string) (!empty($obj->url_labels) ? $obj->url_labels : ''));
			$nativeLabel = trim((string) (!empty($obj->native_label) ? $obj->native_label : ''));
			$description = trim((string) (!empty($obj->description) ? $obj->description : ''));

			$ref = $paymentReference;
			if ($ref === '') {
				$ref = $bankReference;
			}
			if ($ref === '') {
				$ref = $urlLabels;
			}
			if ($ref === '') {
				$ref = $nativeLabel;
			}
			if ($ref === '') {
				$ref = 'BANK-' . ((int) $rowid);
			}

			$documents[] = array(
				'rowid' => $rowid,
				'doc_type' => 'native_bank',
				'ref' => $ref,
				'ref_client' => $urlLabels,
				'amount_open' => (float) $obj->amount_open,
				'doc_date' => $obj->doc_date,
				'fk_soc' => 0,
				'thirdparty_name' => (string) (!empty($obj->counterparty_name) ? $obj->counterparty_name : $urlLabels),
				'thirdparty_iban' => (string) (!empty($obj->counterparty_iban) ? $obj->counterparty_iban : ''),
				'description' => ($description !== '' ? $description : ($nativeLabel !== '' ? $nativeLabel : $urlLabels)),
				'bank_line_id' => $rowid,
				'native_label' => $nativeLabel,
				'native_note' => (string) (!empty($obj->native_note) ? $obj->native_note : ''),
				'url_types' => (string) (!empty($obj->url_types) ? $obj->url_types : ''),
				'supplier_payment_id' => (int) (!empty($obj->supplier_payment_id) ? $obj->supplier_payment_id : 0),
				'customer_payment_id' => (int) (!empty($obj->customer_payment_id) ? $obj->customer_payment_id : 0),
			);
		}

		return $documents;
	}

	/**
	 * Get open payment documents (customer/supplier) not yet linked to a bank line.
	 *
	 * @param int $direction
	 * @param int $limit
	 * @param string|null $anchorDate
	 * @param int|null $intervalDays
	 * @return array<int,array<string,mixed>>
	 */
	protected function getOpenPaymentDocuments($direction = 0, $limit = 250, $anchorDate = null, $intervalDays = null)
	{
		$documents = array();
		$limit = max(20, (int) $limit);
		$includeCustomerPayments = ((int) $direction >= 0);
		$includeSupplierPayments = ((int) $direction <= 0);
		$dateRange = $this->resolveOpenDocumentsDateRange($anchorDate, $intervalDays);

		if ($includeCustomerPayments) {
			$sql = 'SELECT p.rowid, p.ref, p.num_paiement, p.datep as doc_date, p.amount as amount_open, p.note,';
			$sql .= " GROUP_CONCAT(DISTINCT f.ref ORDER BY f.ref SEPARATOR ', ') as invoice_refs,";
			$sql .= " GROUP_CONCAT(DISTINCT s.nom ORDER BY s.nom SEPARATOR ' / ') as thirdparty_name";
			$sql .= ' FROM ' . $this->db->prefix() . 'paiement as p';
			$sql .= ' LEFT JOIN ' . $this->db->prefix() . 'paiement_facture as pf ON pf.fk_paiement = p.rowid';
			$sql .= ' LEFT JOIN ' . $this->db->prefix() . 'facture as f ON f.rowid = pf.fk_facture';
			$sql .= ' LEFT JOIN ' . $this->db->prefix() . 'societe as s ON s.rowid = f.fk_soc';
			$sql .= ' WHERE p.entity = ' . ((int) $this->entity);
			$sql .= ' AND (p.fk_bank IS NULL OR p.fk_bank = 0)';
			$sql .= ' AND ABS(p.amount) > 0.0000001';
			if (!empty($dateRange['enabled'])) {
				$sql .= ' AND p.datep >= ' . $this->sqlDate($dateRange['start']);
				$sql .= ' AND p.datep <= ' . $this->sqlDate($dateRange['end']);
			}
			$sql .= ' GROUP BY p.rowid, p.ref, p.num_paiement, p.datep, p.amount, p.note';
			$sql .= ' ORDER BY p.datep ASC, p.rowid ASC';
			$sql .= $this->db->plimit($limit, 0);

			$resql = $this->db->query($sql);
			if ($resql) {
				while ($obj = $this->db->fetch_object($resql)) {
					$ref = trim((string) (!empty($obj->ref) ? $obj->ref : $obj->num_paiement));
					if ($ref === '') {
						$ref = 'PAY-' . ((int) $obj->rowid);
					}

					$documents[] = array(
						'rowid' => (int) $obj->rowid,
						'doc_type' => 'payment',
						'ref' => $ref,
						'ref_client' => (string) (!empty($obj->invoice_refs) ? $obj->invoice_refs : ''),
						'amount_open' => (float) $obj->amount_open,
						'doc_date' => $obj->doc_date,
						'fk_soc' => 0,
						'thirdparty_name' => (string) (!empty($obj->thirdparty_name) ? $obj->thirdparty_name : ''),
						'thirdparty_iban' => '',
						'description' => (string) (!empty($obj->note) ? $obj->note : ''),
					);
				}
			}
		}

		if ($includeSupplierPayments) {
			$sqlf = 'SELECT p.rowid, p.ref, p.num_paiement, p.datep as doc_date, p.amount as amount_open, p.note,';
			$sqlf .= " GROUP_CONCAT(DISTINCT ff.ref ORDER BY ff.ref SEPARATOR ', ') as invoice_refs,";
			$sqlf .= " GROUP_CONCAT(DISTINCT s.nom ORDER BY s.nom SEPARATOR ' / ') as thirdparty_name";
			$sqlf .= ' FROM ' . $this->db->prefix() . 'paiementfourn as p';
			$sqlf .= ' LEFT JOIN ' . $this->db->prefix() . 'paiementfourn_facturefourn as pf ON pf.fk_paiementfourn = p.rowid';
			$sqlf .= ' LEFT JOIN ' . $this->db->prefix() . 'facture_fourn as ff ON ff.rowid = pf.fk_facturefourn';
			$sqlf .= ' LEFT JOIN ' . $this->db->prefix() . 'societe as s ON s.rowid = ff.fk_soc';
			$sqlf .= ' WHERE p.entity = ' . ((int) $this->entity);
			$sqlf .= ' AND (p.fk_bank IS NULL OR p.fk_bank = 0)';
			$sqlf .= ' AND ABS(p.amount) > 0.0000001';
			if (!empty($dateRange['enabled'])) {
				$sqlf .= ' AND p.datep >= ' . $this->sqlDate($dateRange['start']);
				$sqlf .= ' AND p.datep <= ' . $this->sqlDate($dateRange['end']);
			}
			$sqlf .= ' GROUP BY p.rowid, p.ref, p.num_paiement, p.datep, p.amount, p.note';
			$sqlf .= ' ORDER BY p.datep ASC, p.rowid ASC';
			$sqlf .= $this->db->plimit($limit, 0);

			$resqlf = $this->db->query($sqlf);
			if ($resqlf) {
				while ($obj = $this->db->fetch_object($resqlf)) {
					$ref = trim((string) (!empty($obj->ref) ? $obj->ref : $obj->num_paiement));
					if ($ref === '') {
						$ref = 'SPAY-' . ((int) $obj->rowid);
					}

					$documents[] = array(
						'rowid' => (int) $obj->rowid,
						'doc_type' => 'payment_supplier',
						'ref' => $ref,
						'ref_client' => (string) (!empty($obj->invoice_refs) ? $obj->invoice_refs : ''),
						'amount_open' => (float) $obj->amount_open,
						'doc_date' => $obj->doc_date,
						'fk_soc' => 0,
						'thirdparty_name' => (string) (!empty($obj->thirdparty_name) ? $obj->thirdparty_name : ''),
						'thirdparty_iban' => '',
						'description' => (string) (!empty($obj->note) ? $obj->note : ''),
					);
				}
			}
		}

		return $documents;
	}

	/**
	 * Get payment candidates already linked to another bank line.
	 * These are informational only and help identify imported duplicates.
	 *
	 * @param int $direction
	 * @param int $limit
	 * @param string|null $anchorDate
	 * @param int|null $intervalDays
	 * @param float|null $targetAmount
	 * @param int $bankAccountId
	 * @param bool $includeReconciledLinked Include linked payments already conciliated in native bank module
	 * @return array<int,array<string,mixed>>
	 */
	public function getLinkedPaymentDocuments($direction = 0, $limit = 120, $anchorDate = null, $intervalDays = null, $targetAmount = null, $bankAccountId = 0, $includeReconciledLinked = true)
	{
		$documents = array();
		$limit = max(10, (int) $limit);
		$bankAccountId = (int) $bankAccountId;
		$includeReconciledLinked = !empty($includeReconciledLinked);
		$includeCustomerPayments = ((int) $direction >= 0);
		$includeSupplierPayments = ((int) $direction <= 0);
		$dateRange = $this->resolveOpenDocumentsDateRange($anchorDate, $intervalDays);
		$targetAmountAbs = ($targetAmount !== null ? abs((float) price2num((string) $targetAmount, 'MU')) : 0.0);
		$hasTargetAmount = ($targetAmountAbs > 0.0000001);

		if ($includeCustomerPayments) {
			$sql = 'SELECT p.rowid, p.ref, p.num_paiement, p.datep as doc_date, p.amount as amount_open, p.note, p.fk_bank as linked_bank_line,';
			$sql .= ' b.dateo as linked_bank_date, b.label as linked_bank_label, b.rappro as linked_bank_reconciled,';
			$sql .= " GROUP_CONCAT(DISTINCT f.ref ORDER BY f.ref SEPARATOR ', ') as invoice_refs,";
			$sql .= " GROUP_CONCAT(DISTINCT s.nom ORDER BY s.nom SEPARATOR ' / ') as thirdparty_name";
			$sql .= ' FROM ' . $this->db->prefix() . 'paiement as p';
			$sql .= ' INNER JOIN ' . $this->db->prefix() . 'bank as b ON b.rowid = p.fk_bank';
			$sql .= ' LEFT JOIN ' . $this->db->prefix() . 'paiement_facture as pf ON pf.fk_paiement = p.rowid';
			$sql .= ' LEFT JOIN ' . $this->db->prefix() . 'facture as f ON f.rowid = pf.fk_facture';
			$sql .= ' LEFT JOIN ' . $this->db->prefix() . 'societe as s ON s.rowid = f.fk_soc';
			$sql .= ' WHERE p.entity = ' . ((int) $this->entity);
			$sql .= ' AND p.fk_bank IS NOT NULL AND p.fk_bank > 0';
			$sql .= ' AND ABS(p.amount) > 0.0000001';
			if ($bankAccountId > 0) {
				$sql .= ' AND b.fk_account = ' . $bankAccountId;
			}
			if (!$includeReconciledLinked) {
				$sql .= ' AND b.rappro = 0';
			}
			if (!empty($dateRange['enabled'])) {
				$sql .= ' AND p.datep >= ' . $this->sqlDate($dateRange['start']);
				$sql .= ' AND p.datep <= ' . $this->sqlDate($dateRange['end']);
			}
			if ($hasTargetAmount) {
				$sql .= ' AND ABS(ABS(p.amount) - ' . $targetAmountAbs . ') <= 0.01';
			}
			$sql .= ' GROUP BY p.rowid, p.ref, p.num_paiement, p.datep, p.amount, p.note, p.fk_bank, b.dateo, b.label, b.rappro';
			$sql .= ' ORDER BY p.datep ASC, p.rowid ASC';
			$sql .= $this->db->plimit($limit, 0);

			$resql = $this->db->query($sql);
			if ($resql) {
				while ($obj = $this->db->fetch_object($resql)) {
					$ref = trim((string) (!empty($obj->ref) ? $obj->ref : $obj->num_paiement));
					if ($ref === '') {
						$ref = 'PAY-' . ((int) $obj->rowid);
					}

					$documents[] = array(
						'rowid' => (int) $obj->rowid,
						'doc_type' => 'payment_linked',
						'ref' => $ref,
						'ref_client' => (string) (!empty($obj->invoice_refs) ? $obj->invoice_refs : ''),
						'amount_open' => (float) $obj->amount_open,
						'doc_date' => $obj->doc_date,
						'fk_soc' => 0,
						'thirdparty_name' => (string) (!empty($obj->thirdparty_name) ? $obj->thirdparty_name : ''),
						'thirdparty_iban' => '',
						'description' => (string) (!empty($obj->note) ? $obj->note : ''),
						'is_locked' => ((int) $obj->linked_bank_reconciled === 1 ? 1 : 0),
						'linked_bank_line' => (int) $obj->linked_bank_line,
						'linked_bank_date' => $obj->linked_bank_date,
						'linked_bank_label' => (string) (!empty($obj->linked_bank_label) ? $obj->linked_bank_label : ''),
						'linked_bank_reconciled' => (int) $obj->linked_bank_reconciled,
					);
				}
			}
		}

		if ($includeSupplierPayments) {
			$sqlf = 'SELECT p.rowid, p.ref, p.num_paiement, p.datep as doc_date, p.amount as amount_open, p.note, p.fk_bank as linked_bank_line,';
			$sqlf .= ' b.dateo as linked_bank_date, b.label as linked_bank_label, b.rappro as linked_bank_reconciled,';
			$sqlf .= " GROUP_CONCAT(DISTINCT ff.ref ORDER BY ff.ref SEPARATOR ', ') as invoice_refs,";
			$sqlf .= " GROUP_CONCAT(DISTINCT s.nom ORDER BY s.nom SEPARATOR ' / ') as thirdparty_name";
			$sqlf .= ' FROM ' . $this->db->prefix() . 'paiementfourn as p';
			$sqlf .= ' INNER JOIN ' . $this->db->prefix() . 'bank as b ON b.rowid = p.fk_bank';
			$sqlf .= ' LEFT JOIN ' . $this->db->prefix() . 'paiementfourn_facturefourn as pf ON pf.fk_paiementfourn = p.rowid';
			$sqlf .= ' LEFT JOIN ' . $this->db->prefix() . 'facture_fourn as ff ON ff.rowid = pf.fk_facturefourn';
			$sqlf .= ' LEFT JOIN ' . $this->db->prefix() . 'societe as s ON s.rowid = ff.fk_soc';
			$sqlf .= ' WHERE p.entity = ' . ((int) $this->entity);
			$sqlf .= ' AND p.fk_bank IS NOT NULL AND p.fk_bank > 0';
			$sqlf .= ' AND ABS(p.amount) > 0.0000001';
			if ($bankAccountId > 0) {
				$sqlf .= ' AND b.fk_account = ' . $bankAccountId;
			}
			if (!$includeReconciledLinked) {
				$sqlf .= ' AND b.rappro = 0';
			}
			if (!empty($dateRange['enabled'])) {
				$sqlf .= ' AND p.datep >= ' . $this->sqlDate($dateRange['start']);
				$sqlf .= ' AND p.datep <= ' . $this->sqlDate($dateRange['end']);
			}
			if ($hasTargetAmount) {
				$sqlf .= ' AND ABS(ABS(p.amount) - ' . $targetAmountAbs . ') <= 0.01';
			}
			$sqlf .= ' GROUP BY p.rowid, p.ref, p.num_paiement, p.datep, p.amount, p.note, p.fk_bank, b.dateo, b.label, b.rappro';
			$sqlf .= ' ORDER BY p.datep ASC, p.rowid ASC';
			$sqlf .= $this->db->plimit($limit, 0);

			$resqlf = $this->db->query($sqlf);
			if ($resqlf) {
				while ($obj = $this->db->fetch_object($resqlf)) {
					$ref = trim((string) (!empty($obj->ref) ? $obj->ref : $obj->num_paiement));
					if ($ref === '') {
						$ref = 'SPAY-' . ((int) $obj->rowid);
					}

					$documents[] = array(
						'rowid' => (int) $obj->rowid,
						'doc_type' => 'payment_supplier_linked',
						'ref' => $ref,
						'ref_client' => (string) (!empty($obj->invoice_refs) ? $obj->invoice_refs : ''),
						'amount_open' => (float) $obj->amount_open,
						'doc_date' => $obj->doc_date,
						'fk_soc' => 0,
						'thirdparty_name' => (string) (!empty($obj->thirdparty_name) ? $obj->thirdparty_name : ''),
						'thirdparty_iban' => '',
						'description' => (string) (!empty($obj->note) ? $obj->note : ''),
						'is_locked' => ((int) $obj->linked_bank_reconciled === 1 ? 1 : 0),
						'linked_bank_line' => (int) $obj->linked_bank_line,
						'linked_bank_date' => $obj->linked_bank_date,
						'linked_bank_label' => (string) (!empty($obj->linked_bank_label) ? $obj->linked_bank_label : ''),
						'linked_bank_reconciled' => (int) $obj->linked_bank_reconciled,
					);
				}
			}
		}

		return $documents;
	}

	/**
	 * Get open invoice documents.
	 *
	 * @param int $direction
	 * @param int $limit
	 * @param string|null $anchorDate
	 * @param int|null $intervalDays
	 * @return array<int,array<string,mixed>>
	 */
	protected function getOpenInvoiceDocuments($direction = 0, $limit = 250, $anchorDate = null, $intervalDays = null)
	{
		$documents = array();
		$limit = max(20, (int) $limit);
		$discardZeroInvoices = (int) getDolGlobalInt('KREABANK_DISCARD_ZERO_INVOICES', 1);
		$dateRange = $this->resolveOpenDocumentsDateRange($anchorDate, $intervalDays);

		if ($direction >= 0) {
			$customerAmountOpenSql = '(f.total_ttc';
			$customerAmountOpenSql .= ' - COALESCE((SELECT SUM(pf.amount) FROM ' . $this->db->prefix() . 'paiement_facture as pf WHERE pf.fk_facture = f.rowid), 0)';
			$customerAmountOpenSql .= ' - COALESCE((SELECT SUM(rc.amount_ttc) FROM ' . $this->db->prefix() . 'societe_remise_except as rc';
			$customerAmountOpenSql .= ' INNER JOIN ' . $this->db->prefix() . 'facture as rf ON rf.rowid = rc.fk_facture_source';
			$customerAmountOpenSql .= ' WHERE rc.entity = ' . ((int) $this->entity) . ' AND rf.entity = ' . ((int) $this->entity);
			$customerAmountOpenSql .= ' AND rc.fk_facture = f.rowid AND rf.type IN (0, 2, 3, 5)), 0)';
			$customerAmountOpenSql .= ')';
			$sql = 'SELECT f.rowid, f.ref, f.ref_client, ' . $customerAmountOpenSql . ' as amount_open, f.datef as doc_date, f.fk_soc, s.nom as thirdparty_name';
			$sql .= " FROM " . $this->db->prefix() . "facture as f";
			$sql .= ' LEFT JOIN ' . $this->db->prefix() . 'societe as s ON s.rowid = f.fk_soc';
			$sql .= ' WHERE f.entity = ' . ((int) $this->entity);
			$sql .= ' AND f.fk_statut = 1 AND f.paye = 0';
			$sql .= ' AND f.type <> 2';
			$sql .= ' AND ' . $customerAmountOpenSql . ' > 0.0000001';
			if (!empty($dateRange['enabled'])) {
				$sql .= ' AND f.datef >= ' . $this->sqlDate($dateRange['start']);
				$sql .= ' AND f.datef <= ' . $this->sqlDate($dateRange['end']);
			}
			if ($discardZeroInvoices) {
				$sql .= ' AND ABS(' . $customerAmountOpenSql . ') > 0.0000001';
			}
			$sql .= ' ORDER BY f.datef ASC';
			$sql .= $this->db->plimit($limit, 0);

			$resql = $this->db->query($sql);
			if ($resql) {
				while ($obj = $this->db->fetch_object($resql)) {
					$amountOpen = (float) $obj->amount_open;
					if ($amountOpen <= 0.0000001) {
						continue;
					}
					$documents[] = array(
						'rowid' => (int) $obj->rowid,
						'doc_type' => 'customer_invoice',
						'ref' => (string) $obj->ref,
						'ref_client' => (string) $obj->ref_client,
						'amount_open' => $amountOpen,
						'doc_date' => $obj->doc_date,
						'fk_soc' => (int) $obj->fk_soc,
						'thirdparty_name' => (string) $obj->thirdparty_name,
						'thirdparty_iban' => '',
					);
				}
			}
		}

		if ($direction <= 0) {
			$supplierAmountOpenSql = '(f.total_ttc';
			$supplierAmountOpenSql .= ' - COALESCE((SELECT SUM(pff.amount) FROM ' . $this->db->prefix() . 'paiementfourn_facturefourn as pff WHERE pff.fk_facturefourn = f.rowid), 0)';
			$supplierAmountOpenSql .= ' - COALESCE((SELECT SUM(rc.amount_ttc) FROM ' . $this->db->prefix() . 'societe_remise_except as rc';
			$supplierAmountOpenSql .= ' INNER JOIN ' . $this->db->prefix() . 'facture_fourn as rf ON rf.rowid = rc.fk_invoice_supplier_source';
			$supplierAmountOpenSql .= ' WHERE rc.entity = ' . ((int) $this->entity) . ' AND rf.entity = ' . ((int) $this->entity);
			$supplierAmountOpenSql .= ' AND rc.fk_invoice_supplier = f.rowid AND rf.type IN (0, 2, 3)), 0)';
			$supplierAmountOpenSql .= ')';
			$sqlf = 'SELECT f.rowid, f.ref, f.ref_supplier as ref_client, ' . $supplierAmountOpenSql . ' as amount_open, f.datef as doc_date, f.fk_soc, s.nom as thirdparty_name';
			$sqlf .= ' FROM ' . $this->db->prefix() . 'facture_fourn as f';
			$sqlf .= ' LEFT JOIN ' . $this->db->prefix() . 'societe as s ON s.rowid = f.fk_soc';
			$sqlf .= ' WHERE f.entity = ' . ((int) $this->entity);
			$sqlf .= ' AND f.fk_statut = 1 AND f.paye = 0';
			$sqlf .= ' AND f.type <> 2';
			$sqlf .= ' AND ' . $supplierAmountOpenSql . ' > 0.0000001';
			if (!empty($dateRange['enabled'])) {
				$sqlf .= ' AND f.datef >= ' . $this->sqlDate($dateRange['start']);
				$sqlf .= ' AND f.datef <= ' . $this->sqlDate($dateRange['end']);
			}
			if ($discardZeroInvoices) {
				$sqlf .= ' AND ABS(' . $supplierAmountOpenSql . ') > 0.0000001';
			}
			$sqlf .= ' ORDER BY f.datef ASC';
			$sqlf .= $this->db->plimit($limit, 0);

			$resqlf = $this->db->query($sqlf);
			if ($resqlf) {
				while ($obj = $this->db->fetch_object($resqlf)) {
					$amountOpen = (float) $obj->amount_open;
					if ($amountOpen <= 0.0000001) {
						continue;
					}
					$documents[] = array(
						'rowid' => (int) $obj->rowid,
						'doc_type' => 'supplier_invoice',
						'ref' => (string) $obj->ref,
						'ref_client' => (string) $obj->ref_client,
						'amount_open' => $amountOpen,
						'doc_date' => $obj->doc_date,
						'fk_soc' => (int) $obj->fk_soc,
						'thirdparty_name' => (string) $obj->thirdparty_name,
						'thirdparty_iban' => '',
					);
				}
			}
		}

		return $documents;
	}

	/**
	 * Compute suggestions for one line.
	 *
	 * @param int $lineId
	 * @param int $minScore
	 * @param int $dateTolerance
	 * @param int|null $openDocumentsIntervalDays
	 * @param bool $includeReconciledLinked Include linked payments already conciliated in native bank module
	 * @return array<int,array<string,mixed>>
	 */
	public function getSuggestionsForLine($lineId, $minScore = 0, $dateTolerance = 3, $openDocumentsIntervalDays = null, $includeReconciledLinked = true)
	{
		$line = $this->getLineById($lineId);
		if (!$line) {
			return array();
		}

		$patterns = $this->getPatterns();
		$documentsCache = array();

		return $this->getSuggestionsForPreparedLine($line, $patterns, (int) $minScore, (int) $dateTolerance, $documentsCache, $openDocumentsIntervalDays, (bool) $includeReconciledLinked);
	}

	/**
	 * Compute suggestions for multiple lines while reusing loaded patterns/documents.
	 *
	 * @param array<int,array<string,mixed>|int> $lines
	 * @param int $minScore
	 * @param int $dateTolerance
	 * @param int|null $openDocumentsIntervalDays
	 * @param bool $includeReconciledLinked Include linked payments already conciliated in native bank module
	 * @return array<int,array<int,array<string,mixed>>>
	 */
	public function getSuggestionsForLines($lines, $minScore = 0, $dateTolerance = 3, $openDocumentsIntervalDays = null, $includeReconciledLinked = true)
	{
		if (!is_array($lines) || empty($lines)) {
			return array();
		}

		$patterns = $this->getPatterns();
		$documentsCache = array();
		$suggestionsByLine = array();

		foreach ($lines as $line) {
			$resolvedLine = null;
			if (is_array($line)) {
				$resolvedLine = $line;
			} else {
				$lineId = (int) $line;
				if ($lineId > 0) {
					$resolvedLine = $this->getLineById($lineId);
				}
			}
			if (empty($resolvedLine) || !is_array($resolvedLine)) {
				continue;
			}
			$lineId = !empty($resolvedLine['rowid']) ? (int) $resolvedLine['rowid'] : 0;
			if ($lineId <= 0) {
				continue;
			}

			$suggestionsByLine[$lineId] = $this->getSuggestionsForPreparedLine(
				$resolvedLine,
				$patterns,
				(int) $minScore,
				(int) $dateTolerance,
				$documentsCache,
				$openDocumentsIntervalDays,
				(bool) $includeReconciledLinked
			);
		}

		return $suggestionsByLine;
	}

	/**
	 * Compute suggestions for one already-loaded statement line.
	 *
	 * @param array<string,mixed> $line
	 * @param array<int,array<string,mixed>> $patterns
	 * @param int $minScore
	 * @param int $dateTolerance
	 * @param array<string,array<int,array<string,mixed>>> $documentsCache
	 * @param int|null $openDocumentsIntervalDays
	 * @param bool $includeReconciledLinked Include linked payments already conciliated in native bank module
	 * @return array<int,array<string,mixed>>
	 */
	protected function getSuggestionsForPreparedLine($line, $patterns, $minScore, $dateTolerance, &$documentsCache, $openDocumentsIntervalDays = null, $includeReconciledLinked = true)
	{
		$lineDate = !empty($line['operation_date']) ? (string) $line['operation_date'] : (!empty($line['value_date']) ? (string) $line['value_date'] : null);
		$excludeNativeLineId = !empty($line['native_bank_line_id']) ? (int) $line['native_bank_line_id'] : 0;
		$direction = (int) (!empty($line['direction']) ? $line['direction'] : 0);
		$bankAccountId = (int) (!empty($line['bank_account_id']) ? $line['bank_account_id'] : 0);
		$intervalKey = ($openDocumentsIntervalDays === null ? 'default' : (string) ((int) $openDocumentsIntervalDays));
		$cacheKey = $direction . '|' . $bankAccountId . '|' . $excludeNativeLineId . '|' . (string) $lineDate . '|' . $intervalKey . '|' . ((int) !empty($includeReconciledLinked));
		if (!array_key_exists($cacheKey, $documentsCache)) {
			$documentsCache[$cacheKey] = $this->getOpenDocuments(
				$direction,
				400,
				$lineDate,
				$openDocumentsIntervalDays,
				(int) (!empty($line['rowid']) ? $line['rowid'] : 0),
				$bankAccountId,
				(bool) $includeReconciledLinked
			);
		}

		$documents = is_array($documentsCache[$cacheKey]) ? $documentsCache[$cacheKey] : array();

		return $this->matcher->getSuggestions($line, $documents, $patterns, (int) $dateTolerance, (int) $minScore);
	}

	/**
	 * Predict if current statement line likely represents a batch payment.
	 *
	 * @param array<string,mixed> $line
	 * @param array<int,array<string,mixed>> $documents
	 * @return array<string,mixed>
	 */
	public function predictBatchMl($line, $documents = array())
	{
		$result = array(
			'enabled' => 0,
			'is_batch' => 0,
			'probability' => 0.0,
			'probability_pct' => 0,
			'threshold_pct' => 80,
			'sample_count' => 0,
			'reason' => 'disabled',
		);

		$thresholdPct = (int) getDolGlobalInt('KREABANK_BATCH_ML_AUTO_THRESHOLD', 80);
		$thresholdPct = max(50, min(99, $thresholdPct));
		$result['threshold_pct'] = $thresholdPct;

		if (!$this->isBatchMlEnabled()) {
			$result['reason'] = 'disabled_by_setup_or_dependency';
			return $result;
		}
		$result['enabled'] = 1;

		$vector = $this->buildBatchMlFeatureVector($line, (array) $documents);
		if (empty($vector)) {
			$result['reason'] = 'no_features';
			return $result;
		}

		$classifier = $this->getBatchMlClassifier();
		$sampleCount = count($this->batchMlSamples);
		$result['sample_count'] = $sampleCount;
		if (!is_object($classifier) || !method_exists($classifier, 'predict') || $sampleCount <= 0) {
			$result['reason'] = 'model_not_ready';
			return $result;
		}

		$predicted = (int) $classifier->predict($vector);
		$probability = $this->estimateBatchMlProbability($vector, $this->batchMlSamples);
		$probabilityPct = (int) round(max(0.0, min(1.0, $probability)) * 100.0);

		$result['probability'] = $probability;
		$result['probability_pct'] = $probabilityPct;
		$result['is_batch'] = (($predicted === 1) && ($probabilityPct >= $thresholdPct) ? 1 : 0);
		$result['reason'] = 'ok';

		return $result;
	}

	/**
	 * Predict supplier from statement line using ML over historical reconciled samples.
	 *
	 * @param array<string,mixed>|int $line
	 * @return array<string,mixed>
	 */
	public function predictSupplierForLine($line)
	{
		$result = array(
			'enabled' => 0,
			'is_confident' => 0,
			'predicted_socid' => 0,
			'predicted_name' => '',
			'probability' => 0.0,
			'probability_pct' => 0,
			'threshold_pct' => 70,
			'confidence_gap_pct' => 0,
			'runner_up_probability_pct' => 0,
			'minimum_gap_pct' => 15,
			'sample_count' => 0,
			'supplier_count' => 0,
			'reason' => 'disabled',
		);

		$thresholdPct = (int) getDolGlobalInt('KREABANK_SUPPLIER_ML_MIN_CONFIDENCE', 70);
		$thresholdPct = max(35, min(99, $thresholdPct));
		$minimumGapPct = 15;
		$result['threshold_pct'] = $thresholdPct;
		$result['minimum_gap_pct'] = $minimumGapPct;

		if (!$this->isSupplierMlEnabled()) {
			$result['reason'] = 'disabled_by_setup_or_dependency';
			return $result;
		}
		$result['enabled'] = 1;

		$resolvedLine = is_array($line) ? $line : $this->getLineById((int) $line);
		if (empty($resolvedLine) || !is_array($resolvedLine)) {
			$result['reason'] = 'line_not_found';
			return $result;
		}

		$vector = $this->buildSupplierMlFeatureVector($resolvedLine);
		$expectedSupplierFeatureDimension = $this->getSupplierMlFeatureDimension();
		if (count($vector) < $expectedSupplierFeatureDimension) {
			$result['reason'] = 'no_features';
			return $result;
		}

		$bankAccountId = (int) (!empty($resolvedLine['bank_account_id']) ? $resolvedLine['bank_account_id'] : 0);
		$training = $this->loadSupplierMlTrainingSamples($bankAccountId);
		$samples = !empty($training['samples']) && is_array($training['samples']) ? (array) $training['samples'] : array();
		$labelNames = !empty($training['label_names']) && is_array($training['label_names']) ? (array) $training['label_names'] : array();

		$result['sample_count'] = count($samples);
		$result['supplier_count'] = count($labelNames);

		$minSamples = (int) getDolGlobalInt('KREABANK_SUPPLIER_ML_MIN_SAMPLES', 18);
		$minSamples = max(8, min(2000, $minSamples));
		if (count($samples) < $minSamples || count($labelNames) < 2) {
			$result['reason'] = 'model_not_ready';
			return $result;
		}

		$knnClass = '\\Phpml\\Classification\\KNearestNeighbors';
		if (!class_exists($knnClass)) {
			$result['reason'] = 'model_dependency_missing';
			return $result;
		}

		$trainVectors = array();
		$trainLabels = array();
		foreach ($samples as $sample) {
			if (!is_array($sample) || empty($sample['features']) || empty($sample['label'])) {
				continue;
			}
			$trainVectors[] = (array) $sample['features'];
			$trainLabels[] = (int) $sample['label'];
		}
		if (count($trainVectors) < $minSamples || count($trainVectors) !== count($trainLabels)) {
			$result['reason'] = 'model_not_ready';
			return $result;
		}

		$predictedSocid = 0;
		$supplierK = $this->resolveAdaptiveNeighborCount(count($trainVectors), 3, 31);
		try {
			$classifier = new $knnClass($supplierK);
			$classifier->train($trainVectors, $trainLabels);
			$predictedSocid = (int) $classifier->predict($vector);
		} catch (Throwable $e) {
			$result['reason'] = 'model_train_failed';
			return $result;
		}

		$estimation = $this->estimateSupplierMlPrediction($vector, $samples, $supplierK);
		if (!is_array($estimation) || empty($estimation['predicted_socid'])) {
			$result['reason'] = 'no_neighbors';
			return $result;
		}

		$scoreTotal = (float) (!empty($estimation['score_total']) ? $estimation['score_total'] : 0.0);
		$votes = !empty($estimation['votes']) && is_array($estimation['votes']) ? (array) $estimation['votes'] : array();
		if ($predictedSocid <= 0) {
			$predictedSocid = (int) $estimation['predicted_socid'];
		}
		if ($scoreTotal <= 0.0) {
			$result['reason'] = 'no_neighbors';
			return $result;
		}

		$predictionScore = 0.0;
		if (!empty($votes[$predictedSocid])) {
			$predictionScore = (float) $votes[$predictedSocid];
		} elseif (!empty($estimation['predicted_socid']) && !empty($votes[(int) $estimation['predicted_socid']])) {
			$predictedSocid = (int) $estimation['predicted_socid'];
			$predictionScore = (float) $votes[$predictedSocid];
		}

		$probability = max(0.0, min(1.0, ($predictionScore / $scoreTotal)));
		$probabilityPct = (int) round($probability * 100.0);
		$runnerUpScore = 0.0;
		foreach ($votes as $voteSocid => $voteScore) {
			if ((int) $voteSocid === $predictedSocid) {
				continue;
			}
			$runnerUpScore = max($runnerUpScore, (float) $voteScore);
		}
		$runnerUpProbability = max(0.0, min(1.0, ($runnerUpScore / $scoreTotal)));
		$runnerUpProbabilityPct = (int) round($runnerUpProbability * 100.0);
		$confidenceGapPct = max(0, $probabilityPct - $runnerUpProbabilityPct);

		// Require both absolute confidence and separation from the next-best label to avoid near-tie auto-fill.
		$isConfident = ($predictedSocid > 0 && $probabilityPct >= $thresholdPct && $confidenceGapPct >= $minimumGapPct);

		$predictedName = !empty($labelNames[$predictedSocid]) ? (string) $labelNames[$predictedSocid] : '';
		$predictedName = $this->decodeHtmlEntitiesRecursive($predictedName);

		$result['predicted_socid'] = (int) $predictedSocid;
		$result['predicted_name'] = $predictedName;
		$result['probability'] = $probability;
		$result['probability_pct'] = $probabilityPct;
		$result['confidence_gap_pct'] = $confidenceGapPct;
		$result['runner_up_probability_pct'] = $runnerUpProbabilityPct;
		$result['is_confident'] = ($isConfident ? 1 : 0);
		$result['reason'] = ($isConfident ? 'ok' : ($probabilityPct < $thresholdPct ? 'low_confidence' : 'low_confidence_gap'));

		return $result;
	}

	/**
	 * Resolve supplier by VAT id or name inside current entity.
	 *
	 * @param string $lookup
	 * @param int $fallbackSocid
	 * @return array<string,mixed>
	 */
	public function resolveSupplierFromVatOrName($lookup, $fallbackSocid = 0)
	{
		$lookup = trim((string) $lookup);
		$fallbackSocid = (int) $fallbackSocid;

		if ($lookup === '') {
			$fallback = $this->fetchSupplierSummaryById($fallbackSocid);
			if (!empty($fallback)) {
				$fallback['source'] = 'prediction';
				return $fallback;
			}

			return array('id' => 0, 'name' => '', 'vat' => '', 'source' => 'none');
		}

		$vatNormalized = strtoupper((string) preg_replace('/[^A-Z0-9]/', '', $lookup));
		$nameUpper = strtoupper((string) $lookup);
		$vatExpr = "REPLACE(REPLACE(REPLACE(UPPER(COALESCE(s.tva_intra, '')), ' ', ''), '-', ''), '.', '')";
		$lookupEscaped = $this->db->escape((string) $lookup);
		$nameUpperEscaped = $this->db->escape((string) $nameUpper);
		$vatNormalizedEscaped = $this->db->escape((string) $vatNormalized);

		if ($vatNormalized !== '') {
			$exactVatRows = $this->querySupplierRowsByWhere(
				$vatExpr . " = '" . $vatNormalizedEscaped . "'",
				's.nom ASC',
				5
			);
			if (count($exactVatRows) === 1) {
				$exactVatRows[0]['source'] = 'vat_exact';
				return $exactVatRows[0];
			}
			if (count($exactVatRows) > 1) {
				throw new Exception('Multiple suppliers found for VAT "' . $lookup . '". Refine supplier input.');
			}
		}

		$exactNameRows = $this->querySupplierRowsByWhere(
			"UPPER(s.nom) = '" . $nameUpperEscaped . "'",
			's.nom ASC',
			5
		);
		if (count($exactNameRows) === 1) {
			$exactNameRows[0]['source'] = 'name_exact';
			return $exactNameRows[0];
		}
		if (count($exactNameRows) > 1) {
			throw new Exception('Multiple suppliers found with exact name "' . $lookup . '". Refine supplier input.');
		}

		$likeEscaped = $this->db->escape('%' . $lookup . '%');
		$partialRows = $this->querySupplierRowsByWhere(
			"(s.nom LIKE '" . $likeEscaped . "' OR s.tva_intra LIKE '" . $likeEscaped . "')",
			'CASE WHEN UPPER(s.nom) LIKE \'' . $this->db->escape($nameUpper . '%') . '\' THEN 0 ELSE 1 END, s.nom ASC',
			8
		);
		if (count($partialRows) === 1) {
			$partialRows[0]['source'] = 'partial';
			return $partialRows[0];
		}
		if (count($partialRows) > 1) {
			$labels = array();
			foreach (array_slice($partialRows, 0, 3) as $supplierRow) {
				$labels[] = (string) $supplierRow['name'];
			}
			throw new Exception(
				'Multiple suppliers found for "' . $lookup . '": ' . implode(', ', $labels) . '. Refine by VAT id or exact name.'
			);
		}

		$fallback = $this->fetchSupplierSummaryById($fallbackSocid);
		if (!empty($fallback)) {
			$fallback['source'] = 'prediction_fallback';
			return $fallback;
		}

		throw new Exception('No supplier found for "' . $lookup . '". Search by VAT id or supplier name.');
	}

	/**
	 * Reconcile one statement line against one or more documents.
	 *
	 * @param int $lineId
	 * @param array<int,array<string,mixed>> $links
	 * @param string $strategy
	 * @param int $isAuto
	 * @param string $note
	 * @param int $confidenceScore
	 * @return int
	 */
	public function reconcileLine($lineId, $links, $strategy = 'manual', $isAuto = 0, $note = '', $confidenceScore = 0)
	{
		$line = $this->getLineById($lineId);
		if (!$line) {
			throw new Exception('Unknown bank line');
		}
		if (empty($links)) {
			throw new Exception('No links submitted for reconciliation');
		}

		if ((int) $line['status'] === 2) {
			throw new Exception('Bank line already reconciled');
		}
		$wasSkippedBeforeReconcile = ((int) $line['status'] === 3);
		$batchMlFeatureSnapshot = array();
		if ($this->shouldLearnBatchMlFromReconciliation($strategy, $isAuto)) {
			$batchMlFeatureSnapshot = $this->buildBatchMlSnapshotForLine($line);
		}

		$outstanding = abs((float) $line['amount']);
		if ($outstanding <= 0.00001) {
			throw new Exception('Cannot reconcile zero-amount bank line');
		}

		$totalAllocated = 0.0;
		foreach ($links as $link) {
			$totalAllocated += abs((float) price2num((string) $link['allocated_amount'], 'MU'));
		}
		if ($totalAllocated <= 0.00001) {
			throw new Exception('No valid allocated amount to reconcile');
		}
		if (abs($outstanding - $totalAllocated) > 0.01) {
			throw new Exception('Native bank reconciliation requires full allocation of the bank line amount');
		}
		$lineDirection = (int) (isset($line['direction']) ? $line['direction'] : 0);
		$selectedNativeBankLink = $this->findSelectedNativeBankLink($links);
		$selectedLinkedPaymentBankLink = $this->findSelectedLinkedPaymentBankLink($links);
		$nativeLineId = 0;
		$nativeLine = $line;

		usort($links, static function ($a, $b) {
			$typeA = isset($a['doc_type']) ? (string) $a['doc_type'] : '';
			$typeB = isset($b['doc_type']) ? (string) $b['doc_type'] : '';
			$priorityMap = array(
				'native_bank' => 0,
				'payment' => 0,
				'payment_supplier' => 0,
				'payment_linked' => 0,
				'payment_supplier_linked' => 0,
				'customer_invoice' => 1,
				'supplier_invoice' => 1,
				'quick_entry' => 2,
			);
			$priorityA = array_key_exists($typeA, $priorityMap) ? (int) $priorityMap[$typeA] : 9;
			$priorityB = array_key_exists($typeB, $priorityMap) ? (int) $priorityMap[$typeB] : 9;
			if ($priorityA === $priorityB) {
				return 0;
			}

			return ($priorityA < $priorityB ? -1 : 1);
		});

		if (!$this->db->begin()) {
			throw new Exception('Failed to start database transaction');
		}
		$companyLinks = array();
		try {
			if ($selectedNativeBankLink !== null) {
				$resolvedNativeBankLine = $this->resolveExistingNativeBankLineForReconciliation(
					$line,
					(int) $selectedNativeBankLink['fk_doc'],
					(float) $selectedNativeBankLink['allocated_amount']
				);
				$nativeLineId = (int) $resolvedNativeBankLine['source_line_id'];
				$nativeLine = $this->buildNativeLineContext($line, $nativeLineId);
			} elseif ($selectedLinkedPaymentBankLink !== null) {
				$resolvedNativeBankLine = $this->resolveLinkedPaymentNativeBankLineForReconciliation(
					$line,
					(string) $selectedLinkedPaymentBankLink['doc_type'],
					(int) $selectedLinkedPaymentBankLink['fk_doc'],
					(float) $selectedLinkedPaymentBankLink['allocated_amount']
				);
				$nativeLineId = (int) $resolvedNativeBankLine['source_line_id'];
				$nativeLine = $this->buildNativeLineContext($line, $nativeLineId);
			} else {
				$nativeLineId = $this->resolveNativeLineIdFromStatementLine($line, true);
				$nativeLine = $this->buildNativeLineContext($line, $nativeLineId);
			}

			foreach ($links as $link) {
				$allocatedAmount = abs((float) price2num((string) $link['allocated_amount'], 'MU'));
				if ($allocatedAmount <= 0.00001) {
					continue;
				}

				$rawDocType = trim((string) $link['doc_type']);
				$docType = $this->normalizeReconciliationDocType($rawDocType);
				$compatibilityDocType = ($rawDocType === 'payment_linked' || $rawDocType === 'payment_supplier_linked') ? $rawDocType : $docType;
				if (!$this->isDocTypeCompatibleWithLineDirection($compatibilityDocType, $lineDirection)) {
					throw new Exception('Document type ' . $docType . ' is not compatible with this statement line direction');
				}
				$docId = (int) $link['fk_doc'];
				if ($docId <= 0) {
					continue;
				}

				if ($docType === 'native_bank') {
					continue;
				} elseif ($rawDocType === 'payment_linked' || $rawDocType === 'payment_supplier_linked') {
					continue;
				} elseif ($docType === 'payment') {
					$attached = $this->attachExistingCustomerPaymentToBankLine($nativeLine, $docId, $allocatedAmount);
					$resLink = $this->native->addLineLink(
						$nativeLineId,
						(int) $attached['payment_id'],
						DOL_URL_ROOT . '/compta/paiement/card.php?id=',
						(string) $attached['payment_ref'],
						'payment'
					);
					if ($resLink <= 0) {
						throw new Exception('Failed to link customer payment to bank line');
					}
					foreach ($attached['thirdparties'] as $thirdparty) {
						$tpId = (int) $thirdparty['id'];
						if ($tpId > 0) {
							$companyLinks[$tpId] = $thirdparty;
						}
					}
				} elseif ($docType === 'payment_supplier') {
					$attached = $this->attachExistingSupplierPaymentToBankLine($nativeLine, $docId, $allocatedAmount);
					$resLink = $this->native->addLineLink(
						$nativeLineId,
						(int) $attached['payment_id'],
						DOL_URL_ROOT . '/fourn/paiement/card.php?id=',
						(string) $attached['payment_ref'],
						'payment_supplier'
					);
					if ($resLink <= 0) {
						throw new Exception('Failed to link supplier payment to bank line');
					}
					foreach ($attached['thirdparties'] as $thirdparty) {
						$tpId = (int) $thirdparty['id'];
						if ($tpId > 0) {
							$companyLinks[$tpId] = $thirdparty;
						}
					}
				} elseif ($docType === 'customer_invoice') {
					$created = $this->createCustomerPaymentForBankLine($nativeLine, $docId, $allocatedAmount, (string) $note);
					$createdPaymentLabel = '#' . ((int) $created['payment_id']);
					$resLink = $this->native->addLineLink(
						$nativeLineId,
						(int) $created['payment_id'],
						DOL_URL_ROOT . '/compta/paiement/card.php?id=',
						$createdPaymentLabel,
						'payment'
					);
					if ($resLink <= 0) {
						throw new Exception('Failed to link customer payment to bank line');
					}
					$tpId = (int) $created['thirdparty_id'];
					if ($tpId > 0) {
						$companyLinks[$tpId] = array(
							'id' => $tpId,
							'name' => (string) $created['thirdparty_name'],
							'url' => DOL_URL_ROOT . '/comm/card.php?socid=',
						);
					}
				} elseif ($docType === 'supplier_invoice') {
					$created = $this->createSupplierPaymentForBankLine($nativeLine, $docId, $allocatedAmount, (string) $note);
					$createdPaymentLabel = '#' . ((int) $created['payment_id']);
					$resLink = $this->native->addLineLink(
						$nativeLineId,
						(int) $created['payment_id'],
						DOL_URL_ROOT . '/fourn/paiement/card.php?id=',
						$createdPaymentLabel,
						'payment_supplier'
					);
					if ($resLink <= 0) {
						throw new Exception('Failed to link supplier payment to bank line');
					}
					$tpId = (int) $created['thirdparty_id'];
					if ($tpId > 0) {
						$companyLinks[$tpId] = array(
							'id' => $tpId,
							'name' => (string) $created['thirdparty_name'],
							'url' => DOL_URL_ROOT . '/fourn/card.php?socid=',
						);
					}
				} elseif ($docType === 'quick_entry') {
					$resLink = $this->native->addLineLink(
						$nativeLineId,
						$docId,
						DOL_URL_ROOT . '/custom/kreabank/reconcile.php?quick_entry_id=',
						'QuickEntry #' . $docId,
						'quick_entry'
					);
					if ($resLink <= 0) {
						throw new Exception('Failed to link quick entry to bank line');
					}
				} else {
					throw new Exception('Unsupported reconciliation document type: ' . $docType);
				}
			}

			foreach ($companyLinks as $companyLink) {
				$this->native->addLineLink(
					$nativeLineId,
					(int) $companyLink['id'],
					(string) $companyLink['url'],
					(string) $companyLink['name'],
					'company'
				);
			}

			$statementLabel = !empty($line['statement_ref']) ? (string) $line['statement_ref'] : 'KREABANK-' . dol_print_date(dol_now(), '%Y%m%d');
			$resConc = $this->native->conciliateLine($nativeLineId, $statementLabel, 0);
			if ($resConc <= 0) {
				throw new Exception('Failed to conciliate native bank line');
			}

			$this->native->markStatementLineReconciled((int) $line['rowid'], $outstanding, $nativeLineId);
			$this->db->commit();
		} catch (Exception $e) {
			$this->db->rollback();
			throw $e;
		}

		if ($wasSkippedBeforeReconcile && $nativeLineId > 0) {
			try {
				if (!$this->native->clearSkipNoteFromNativeLine((int) $nativeLineId) && function_exists('dol_syslog')) {
					dol_syslog('KreaBank skip-note cleanup warning after reconciliation line #' . ((int) $line['rowid']), LOG_WARNING);
				}
			} catch (Throwable $noteCleanupError) {
				if (function_exists('dol_syslog')) {
					dol_syslog(
						'KreaBank skip-note cleanup exception after reconciliation line #' . ((int) $line['rowid']) . ': ' . ((string) $noteCleanupError->getMessage()),
						LOG_WARNING
					);
				}
			}
		}

		try {
			$this->learnPatternsFromReconciliation($line, $links);
		} catch (Throwable $patternError) {
			if (function_exists('dol_syslog')) {
				dol_syslog(
					'KreaBank pattern learning warning after reconciliation line #' . ((int) $line['rowid']) . ': ' . ((string) $patternError->getMessage()),
					LOG_WARNING
				);
			}
		}

		$this->logAudit(
			'reconcile',
			(int) $line['rowid'],
			(int) $line['rowid'],
			array(
				'strategy' => (string) $strategy,
				'is_auto' => (int) $isAuto,
				'confidence_score' => (int) $confidenceScore,
				'links' => $links,
				'native' => 1,
				'native_bank_line_id' => $nativeLineId,
			)
		);
		$this->learnBatchMlFromReconciliation($line, $links, $strategy, $isAuto, $batchMlFeatureSnapshot);

		return (int) $line['rowid'];
	}

	/**
	 * Skip one bank line by marking it as deferred (status=3) without conciliation.
	 *
	 * @param int $lineId
	 * @param string $reason
	 * @return bool
	 */
	public function skipLine($lineId, $reason = '')
	{
		$line = $this->getLineById($lineId);
		if (!$line) {
			throw new Exception('Unknown bank line');
		}
		if ((int) $line['status'] === 2) {
			throw new Exception('Bank line already reconciled');
		}
		if ((int) $line['status'] === 3) {
			return true;
		}

		$reason = trim((string) $reason);
		$nativeLineId = 0;
		if (!$this->db->begin()) {
			throw new Exception('Failed to start database transaction');
		}
		try {
			$nativeLineId = $this->resolveNativeLineIdFromStatementLine($line, false);
			if ($reason !== '' && $nativeLineId > 0) {
				$existingNote = trim((string) $line['native_note']);
				$newNote = trim(($existingNote !== '' ? $existingNote . "\n" : '') . 'KreaBank skip: ' . $reason);
				$sql = 'UPDATE ' . $this->db->prefix() . 'bank SET note = ' . $this->sqlText($newNote);
				$sql .= ' WHERE rowid = ' . $nativeLineId;
				if (!$this->db->query($sql)) {
					throw new Exception($this->db->lasterror());
				}
			}

			$markSkipped = $this->native->markStatementLineSkipped((int) $line['rowid'], $nativeLineId);
			if (!$markSkipped) {
				throw new Exception('Failed to mark statement line as skipped');
			}

			$this->db->commit();
		} catch (Exception $e) {
			$this->db->rollback();
			throw $e;
		}

		$this->logAudit(
			'skip',
			(int) $line['rowid'],
			(int) $line['rowid'],
			array(
				'reason' => $reason,
				'native' => 1,
				'mode' => 'manual_skip',
				'native_bank_line_id' => $nativeLineId,
			)
		);

		return true;
	}

	/**
	 * Return the only suggestion that satisfies the shared automatic-match safety contract.
	 *
	 * @param array<int,array<string,mixed>> $suggestions
	 * @param int $safeScore
	 * @return array<string,mixed>|null
	 */
	public function getSafeSuggestion($suggestions, $safeScore)
	{
		return $this->matcher->getSafeSuggestion((array) $suggestions, (int) $safeScore);
	}

	/**
	 * Apply safe suggestions on a batch of pending lines.
	 *
	 * @param int $safeScore
	 * @param int $minSuggestionScore
	 * @param int $dateTolerance
	 * @param int $limit
	 * @return array<string,mixed>
	 */
	public function batchApproveSafe($safeScore, $minSuggestionScore, $dateTolerance = 3, $limit = 200)
	{
		$lines = $this->getPendingLines($limit, 0);
		$suggestionsByLine = $this->getSuggestionsForLines($lines, (int) $minSuggestionScore, (int) $dateTolerance);
		$approved = 0;
		$attempted = 0;
		$errors = array();

		foreach ($lines as $line) {
			$lineId = (int) $line['rowid'];
			$suggestions = !empty($suggestionsByLine[$lineId]) ? (array) $suggestionsByLine[$lineId] : array();
			$safe = $this->getSafeSuggestion($suggestions, (int) $safeScore);
			if (!$safe) {
				continue;
			}

			$attempted++;
			$outstanding = max(0.0, abs((float) $line['amount']) - abs((float) $line['allocated_amount']));
			$allocation = min($outstanding, abs((float) $safe['amount_open']));
			if ($allocation <= 0.00001) {
				continue;
			}
			if (abs($allocation - $outstanding) > 0.01) {
				continue;
			}

			try {
				$this->reconcileLine(
					(int) $line['rowid'],
					array(array(
						'doc_type' => $safe['doc_type'],
						'fk_doc' => (int) $safe['doc_id'],
						'doc_ref' => $safe['doc_ref'],
						'allocated_amount' => $allocation,
						'match_score' => (int) $safe['score'],
						'match_reasons' => $safe['details'],
					)),
					'auto_safe',
					1,
					'Safe auto-approval',
					(int) $safe['score']
				);
				$approved++;
			} catch (Exception $e) {
				$errors[] = $e->getMessage();
			}
		}

		$this->logAudit(
			'batch_approve',
			null,
			null,
			array(
				'attempted' => $attempted,
				'approved' => $approved,
				'errors' => $errors,
			)
		);

		return array(
			'attempted' => $attempted,
			'approved' => $approved,
			'errors' => $errors,
		);
	}

	/**
	 * Create quick entry and immediately reconcile line.
	 *
	 * @param int $lineId
	 * @param string $entryType
	 * @param string $label
	 * @param float $amount
	 * @param int $fkSoc
	 * @param string $note
	 * @return int
	 */
	public function createQuickEntryAndReconcile($lineId, $entryType, $label, $amount, $fkSoc = 0, $note = '')
	{
		$this->ensureQuickEntryTable();

		$line = $this->getLineById($lineId);
		if (!$line) {
			throw new Exception('Unknown statement line');
		}
		if ((int) (!empty($line['status']) ? $line['status'] : 0) === 2) {
			throw new Exception('Bank line already reconciled');
		}

		$amount = abs((float) price2num((string) $amount, 'MU'));
		if ($amount <= 0.00001) {
			throw new Exception('Quick entry amount must be positive');
		}

		$outstanding = max(0.0, abs((float) $line['amount']) - abs((float) $line['allocated_amount']));
		if ($amount > $outstanding) {
			$amount = $outstanding;
		}
		if (abs($amount - $outstanding) > 0.01) {
			throw new Exception('Tax quick flow requires full allocation of the statement line amount');
		}

		$now = dol_now();
		$sql = 'INSERT INTO ' . $this->db->prefix() . 'kreabank_quick_entry (';
		$sql .= 'entity, fk_statement_line, entry_type, label, amount, currency, fk_soc, status, note, fk_user_author, datec';
		$sql .= ') VALUES (';
		$sql .= $this->entity;
		$sql .= ', ' . ((int) $lineId);
		$sql .= ', ' . $this->sqlText($entryType);
		$sql .= ', ' . $this->sqlText($label);
		$sql .= ', ' . price2num((string) $amount, 'MU');
		$sql .= ', ' . $this->sqlText((string) $line['currency']);
		$sql .= ', ' . ((int) $fkSoc > 0 ? (int) $fkSoc : 'NULL');
		$sql .= ', 0';
		$sql .= ', ' . $this->sqlText($note);
		$sql .= ', ' . ((int) $this->user->id);
		$sql .= ', ' . $this->sqlDateTime($now);
		$sql .= ')';

		$resql = $this->db->query($sql);
		if (!$resql) {
			throw new Exception($this->db->lasterror());
		}

		$quickEntryId = (int) $this->db->last_insert_id($this->db->prefix() . 'kreabank_quick_entry');
		if ($quickEntryId <= 0) {
			throw new Exception('Failed to resolve created quick entry identifier');
		}
		$quickRef = 'QE' . str_pad((string) $quickEntryId, 8, '0', STR_PAD_LEFT);

		try {
			$this->reconcileLine(
				(int) $lineId,
				array(array(
					'doc_type' => 'quick_entry',
					'fk_doc' => $quickEntryId,
					'doc_ref' => $quickRef,
					'allocated_amount' => $amount,
					'match_score' => 0,
					'match_reasons' => array('manual_quick_entry'),
				)),
				'quick_entry',
				0,
				$note,
				0
			);
		} catch (Exception $e) {
			$reconcileError = trim((string) preg_replace('/\s+/', ' ', (string) $e->getMessage()));
			if ($reconcileError !== '') {
				$reconcileError = dol_trunc($reconcileError, 200, 'right', 'UTF-8', 1);
			}
			$failedNote = trim(($note ? $note . ' | ' : '') . 'Reconcile failed' . ($reconcileError !== '' ? ': ' . $reconcileError : ''));
			if ($failedNote !== '') {
				// Keep persisted failure note conservative for backward-compatible schemas.
				$failedNote = dol_trunc($failedNote, 200, 'right', 'UTF-8', 1);
			}
			$markedFailed = $this->updateQuickEntryStatusWithRetry($quickEntryId, 9, $failedNote, 3);
			if (!$markedFailed) {
				$cleanupSql = 'DELETE FROM ' . $this->db->prefix() . 'kreabank_quick_entry';
				$cleanupSql .= ' WHERE rowid = ' . ((int) $quickEntryId);
				$cleanupSql .= ' AND entity = ' . ((int) $this->entity);
				if (!$this->db->query($cleanupSql) && function_exists('dol_syslog')) {
					dol_syslog(
						'KreaBank quick entry cleanup issue after failed reconciliation #' . ((int) $quickEntryId) . ': ' . $this->db->lasterror(),
						LOG_WARNING
					);
				}
			}
			throw $e;
		}
		if (!$this->updateQuickEntryStatusWithRetry($quickEntryId, 1, '', 2) && function_exists('dol_syslog')) {
			dol_syslog('KreaBank quick entry status update warning after successful reconciliation #' . ((int) $quickEntryId), LOG_WARNING);
		}

		$this->logAudit(
			'quick_entry',
			$lineId,
			null,
			array(
				'quick_entry_id' => $quickEntryId,
				'label' => $label,
				'amount' => $amount,
				'entry_type' => $entryType,
			)
		);

		return $quickEntryId;
	}

	/**
	 * Update quick entry status safely with retries.
	 *
	 * @param int $quickEntryId
	 * @param int $status
	 * @param string $note
	 * @param int $maxAttempts
	 * @return bool
	 */
	protected function updateQuickEntryStatusWithRetry($quickEntryId, $status, $note = '', $maxAttempts = 3)
	{
		$quickEntryId = (int) $quickEntryId;
		$status = (int) $status;
		$maxAttempts = max(1, (int) $maxAttempts);
		if ($quickEntryId <= 0) {
			return false;
		}

		$note = trim((string) preg_replace('/\s+/', ' ', (string) $note));
		if ($note !== '') {
			$note = dol_trunc($note, 200, 'right', 'UTF-8', 1);
		}

		$lastError = '';
		for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
			$sql = 'UPDATE ' . $this->db->prefix() . 'kreabank_quick_entry';
			$sql .= ' SET status = ' . $status;
			if ($note !== '') {
				$sql .= ', note = ' . $this->sqlText($note);
			}
			$sql .= ' WHERE rowid = ' . $quickEntryId;
			$sql .= ' AND entity = ' . ((int) $this->entity);
			if ($this->db->query($sql)) {
				return true;
			}

			$lastError = (string) $this->db->lasterror();
			if ($attempt < $maxAttempts) {
				usleep((int) (120000 * $attempt));
			}
		}

		if (function_exists('dol_syslog')) {
			$logMessage = 'KreaBank quick entry status update failed for #' . $quickEntryId . ' (status=' . $status . ')';
			if ($lastError !== '') {
				$logMessage .= ': ' . $lastError;
			}
			dol_syslog($logMessage, LOG_WARNING);
		}

		return false;
	}

	/**
	 * Create social contribution + payment from statement line and reconcile it.
	 *
	 * @param int $lineId
	 * @param string $label
	 * @param float $amount
	 * @param string $note
	 * @param int $socialTypeId
	 * @return array<string,mixed>
	 */
	public function createQuickTaxContributionAndReconcile($lineId, $label, $amount, $note = '', $socialTypeId = 0)
	{
		require_once DOL_DOCUMENT_ROOT . '/compta/sociales/class/chargesociales.class.php';
		require_once DOL_DOCUMENT_ROOT . '/compta/sociales/class/paymentsocialcontribution.class.php';

		$line = $this->getLineById($lineId);
		if (!$line) {
			throw new Exception('Unknown statement line');
		}

		$lineDirection = (int) (isset($line['direction']) ? $line['direction'] : 0);
		if ($lineDirection === 0) {
			$lineAmount = (float) (!empty($line['amount']) ? $line['amount'] : 0.0);
			if ($lineAmount > 0.0000001) {
				$lineDirection = 1;
			} elseif ($lineAmount < -0.0000001) {
				$lineDirection = -1;
			}
		}
		if ($lineDirection > 0) {
			throw new Exception('Tax quick flow is only available for debit statement lines');
		}

		$amount = abs((float) price2num((string) $amount, 'MU'));
		if ($amount <= 0.00001) {
			throw new Exception('Tax amount must be positive');
		}

		$outstanding = max(0.0, abs((float) $line['amount']) - abs((float) $line['allocated_amount']));
		if ($outstanding <= 0.00001) {
			$outstanding = abs((float) $line['amount']);
		}
		if ($outstanding <= 0.00001) {
			throw new Exception('Cannot create tax entry for zero-amount statement line');
		}
		if ($amount > $outstanding) {
			$amount = $outstanding;
		}

		$label = trim((string) $label);
		if ($label === '') {
			$label = trim((string) (!empty($line['description']) ? $line['description'] : ''));
		}
		if ($label === '') {
			$label = trim((string) (!empty($line['payment_reference']) ? $line['payment_reference'] : ''));
		}
		if ($label === '') {
			$label = trim((string) (!empty($line['counterparty_name']) ? $line['counterparty_name'] : ''));
		}
		if ($label === '') {
			$label = 'Social contribution from bank line #' . ((int) $line['rowid']);
		}
		$label = dol_trunc((string) preg_replace('/\s+/', ' ', $label), 190, 'right', 'UTF-8', 1);

		$socialTypeId = $this->resolveSocialContributionTypeId((int) $socialTypeId);
		$nativeLineId = $this->resolveNativeLineIdFromStatementLine($line, true);
		$nativeLine = $this->buildNativeLineContext($line, $nativeLineId);
		$paymentDate = $this->resolveLineDateTimestamp($nativeLine);
		$paymentModeId = $this->resolvePaymentModeId();
		$bankAccountId = (int) (!empty($nativeLine['bank_account_id']) ? $nativeLine['bank_account_id'] : 0);
		if ($bankAccountId <= 0) {
			throw new Exception('Bank account not found for selected statement line');
		}

		$social = new ChargeSociales($this->db);
		$payment = new PaymentSocialContribution($this->db);
		$socialId = 0;
		$paymentId = 0;
		$bankUrlLinkIds = array();
		$conciliated = false;

		if (!$this->db->begin()) {
			throw new Exception('Failed to start database transaction');
		}

		try {
			$social->type = (int) $socialTypeId;
			$social->label = (string) $label;
			$social->date_ech = $paymentDate;
			$social->period = $paymentDate;
			$social->periode = $paymentDate;
			$social->amount = (float) $amount;
			$social->mode_reglement_id = (int) $paymentModeId;
			$social->fk_account = (int) $bankAccountId;
			$social->paye = ChargeSociales::STATUS_UNPAID;

			$socialId = (int) $social->create($this->user);
			if ($socialId <= 0) {
				throw new Exception($this->extractObjectErrorMessage($social, 'Failed to create social contribution'));
			}
			if ($social->fetch((int) $socialId) <= 0) {
				throw new Exception('Failed to reload social contribution #' . ((int) $socialId));
			}

			$payment->chid = (int) $socialId;
			$payment->datepaye = $paymentDate;
			$payment->amounts = array((int) $socialId => (float) $amount);
			$payment->paiementtype = (int) $paymentModeId;
			$payment->num_payment = $this->resolveLinePaymentRef($nativeLine);
			$payment->note = (string) $note;
			$payment->note_private = (string) $note;

			$paymentId = (int) $payment->create($this->user, 1);
			if ($paymentId <= 0) {
				throw new Exception($this->extractObjectErrorMessage($payment, 'Failed to create social contribution payment'));
			}

			if ((int) $payment->update_fk_bank((int) $nativeLineId) <= 0) {
				throw new Exception($this->extractObjectErrorMessage($payment, 'Failed to attach social contribution payment to bank line'));
			}
			if ($payment->fetch((int) $paymentId) <= 0) {
				throw new Exception('Failed to reload social contribution payment #' . ((int) $paymentId));
			}

			$paymentRef = trim((string) (!empty($payment->num_payment) ? $payment->num_payment : ''));
			if ($paymentRef === '') {
				$paymentRef = '(paiement)';
			}
			$linkResult = (int) $this->native->addLineLink(
				(int) $nativeLineId,
				(int) $paymentId,
				DOL_URL_ROOT . '/compta/payment_sc/card.php?id=',
				(string) $paymentRef,
				'payment_sc'
			);
			if ($linkResult <= 0) {
				throw new Exception('Failed to link social contribution payment to bank line');
			}
			$bankUrlLinkIds[] = (int) $linkResult;

			$socialLinkLabel = trim((string) $social->type_label);
			if ($socialLinkLabel === '') {
				$socialLinkLabel = trim((string) $social->label);
			}
			if ($socialLinkLabel === '') {
				$socialLinkLabel = 'SC#' . ((int) $socialId);
			}
			$linkResult = (int) $this->native->addLineLink(
				(int) $nativeLineId,
				(int) $socialId,
				DOL_URL_ROOT . '/compta/charges.php?id=',
				(string) $socialLinkLabel,
				'sc'
			);
			if ($linkResult <= 0) {
				throw new Exception('Failed to link social contribution to bank line');
			}
			$bankUrlLinkIds[] = (int) $linkResult;

			if ((int) $social->fk_user > 0) {
				require_once DOL_DOCUMENT_ROOT . '/user/class/user.class.php';
				$employee = new User($this->db);
				$employeeLabel = (string) $social->fk_user;
				if ($employee->fetch((int) $social->fk_user) > 0) {
					$employeeLabel = (string) $employee->getFullName($this->langs);
				}
				$linkResult = (int) $this->native->addLineLink(
					(int) $nativeLineId,
					(int) $social->fk_user,
					DOL_URL_ROOT . '/user/card.php?id=',
					$employeeLabel,
					'user'
				);
				if ($linkResult <= 0) {
					throw new Exception('Failed to link social contribution employee to bank line');
				}
				$bankUrlLinkIds[] = (int) $linkResult;
			}

			$statementLabel = !empty($line['statement_ref']) ? (string) $line['statement_ref'] : 'KREABANK-' . dol_print_date(dol_now(), '%Y%m%d');
			$resConc = (int) $this->native->conciliateLine((int) $nativeLineId, $statementLabel, 0);
			if ($resConc <= 0) {
				throw new Exception('Failed to conciliate native bank line');
			}
			$conciliated = true;
			if (!$this->native->markStatementLineReconciled((int) $line['rowid'], (float) $outstanding, (int) $nativeLineId)) {
				throw new Exception('Failed to mark statement line as reconciled');
			}

			$this->db->commit();
		} catch (Exception $e) {
			$this->db->rollback();
			$this->cleanupFailedQuickTaxContributionFlow(
				(int) $line['rowid'],
				(int) $nativeLineId,
				(int) $socialId,
				(int) $paymentId,
				(array) $bankUrlLinkIds,
				(bool) $conciliated
			);
			throw $e;
		}

		$this->logAudit(
			'quick_tax_entry',
			(int) $line['rowid'],
			(int) $line['rowid'],
			array(
				'social_contribution_id' => (int) $socialId,
				'social_contribution_ref' => (string) $social->ref,
				'social_contribution_label' => (string) $social->label,
				'social_type_id' => (int) $socialTypeId,
				'social_type_label' => (string) $social->type_label,
				'payment_id' => (int) $paymentId,
				'payment_bank_line_id' => (int) $nativeLineId,
				'allocated_amount' => (float) $amount,
			)
		);

		return array(
			'social_contribution_id' => (int) $socialId,
			'social_contribution_ref' => (string) $social->ref,
			'payment_id' => (int) $paymentId,
			'payment_bank_line_id' => (int) $nativeLineId,
			'allocated_amount' => (float) $amount,
		);
	}

	/**
	 * Best-effort cleanup for failed quick tax flow after partial writes.
	 *
	 * @param int $lineId
	 * @param int $nativeLineId
	 * @param int $socialId
	 * @param int $paymentId
	 * @param array<int,int> $bankUrlLinkIds
	 * @param bool $conciliated
	 * @return void
	 */
	protected function cleanupFailedQuickTaxContributionFlow($lineId, $nativeLineId, $socialId, $paymentId, $bankUrlLinkIds = array(), $conciliated = false)
	{
		$cleanupErrors = array();
		$lineId = (int) $lineId;
		$nativeLineId = (int) $nativeLineId;
		$socialId = (int) $socialId;
		$paymentId = (int) $paymentId;

		if ($conciliated && $nativeLineId > 0) {
			$resUnconciliate = (int) $this->native->unconciliateLine($nativeLineId);
			if ($resUnconciliate <= 0) {
				$cleanupErrors[] = 'failed_unconciliate_native_line_' . $nativeLineId;
			}
			if ($lineId > 0 && !$this->native->markStatementLinePending($lineId)) {
				$cleanupErrors[] = 'failed_mark_pending_line_' . $lineId;
			}
		}

		foreach (array_reverse((array) $bankUrlLinkIds) as $bankUrlLinkId) {
			$bankUrlLinkId = (int) $bankUrlLinkId;
			if ($bankUrlLinkId <= 0) {
				continue;
			}
			$sqlLink = 'DELETE FROM ' . $this->db->prefix() . 'bank_url';
			$sqlLink .= ' WHERE rowid = ' . $bankUrlLinkId;
			if ($nativeLineId > 0) {
				$sqlLink .= ' AND fk_bank = ' . $nativeLineId;
			}
			if (!$this->db->query($sqlLink)) {
				$cleanupErrors[] = 'failed_delete_bank_url_' . $bankUrlLinkId;
			}
		}

		if ($nativeLineId > 0 && $paymentId > 0) {
			$sqlPaymentLinks = 'DELETE FROM ' . $this->db->prefix() . 'bank_url';
			$sqlPaymentLinks .= ' WHERE fk_bank = ' . $nativeLineId;
			$sqlPaymentLinks .= ' AND url_id = ' . $paymentId;
			$sqlPaymentLinks .= " AND type = 'payment_sc'";
			if (!$this->db->query($sqlPaymentLinks)) {
				$cleanupErrors[] = 'failed_delete_payment_links_' . $paymentId;
			}
		}

		if ($nativeLineId > 0 && $socialId > 0) {
			$sqlSocialLinks = 'DELETE FROM ' . $this->db->prefix() . 'bank_url';
			$sqlSocialLinks .= ' WHERE fk_bank = ' . $nativeLineId;
			$sqlSocialLinks .= ' AND url_id = ' . $socialId;
			$sqlSocialLinks .= " AND type = 'sc'";
			if (!$this->db->query($sqlSocialLinks)) {
				$cleanupErrors[] = 'failed_delete_social_links_' . $socialId;
			}
		}

		if ($paymentId > 0) {
			$sqlPayment = 'DELETE FROM ' . $this->db->prefix() . 'paiementcharge';
			$sqlPayment .= ' WHERE rowid = ' . $paymentId;
			if (!$this->db->query($sqlPayment)) {
				$cleanupErrors[] = 'failed_delete_payment_' . $paymentId;
			}
		}

		if ($socialId > 0) {
			$sqlPaymentByCharge = 'DELETE FROM ' . $this->db->prefix() . 'paiementcharge';
			$sqlPaymentByCharge .= ' WHERE fk_charge = ' . $socialId;
			if (!$this->db->query($sqlPaymentByCharge)) {
				$cleanupErrors[] = 'failed_delete_payment_by_charge_' . $socialId;
			}

			$sqlSocial = 'DELETE FROM ' . $this->db->prefix() . 'chargesociales';
			$sqlSocial .= ' WHERE rowid = ' . $socialId;
			$sqlSocial .= ' AND entity = ' . ((int) $this->entity);
			if (!$this->db->query($sqlSocial)) {
				$cleanupErrors[] = 'failed_delete_social_' . $socialId;
			}
		}

		if (!empty($cleanupErrors) && function_exists('dol_syslog')) {
			dol_syslog('KreaBank quick tax cleanup issues: ' . implode(', ', $cleanupErrors), LOG_WARNING);
		}
	}

	/**
	 * Best-effort cleanup for failed quick supplier-invoice flow.
	 *
	 * @param int $invoiceId
	 * @param string $failureMessage
	 * @return void
	 */
	protected function cleanupFailedQuickSupplierInvoiceFlow($invoiceId, $failureMessage = '')
	{
		$invoiceId = (int) $invoiceId;
		if ($invoiceId <= 0) {
			return;
		}

		$cleanupErrors = array();
		$failureMessage = trim((string) preg_replace('/\s+/', ' ', (string) $failureMessage));
		if ($failureMessage !== '') {
			$failureMessage = dol_trunc($failureMessage, 120, 'right', 'UTF-8', 1);
		}

		try {
			require_once DOL_DOCUMENT_ROOT . '/fourn/class/fournisseur.facture.class.php';

			$invoice = new FactureFournisseur($this->db);
			if ($invoice->fetch($invoiceId) <= 0) {
				$cleanupErrors[] = 'failed_fetch_supplier_invoice_' . $invoiceId;
			} elseif ((int) $invoice->entity !== (int) $this->entity) {
				$cleanupErrors[] = 'supplier_invoice_entity_mismatch_' . $invoiceId;
			} else {
				$invoiceStatus = (int) $invoice->status;
				if ($invoiceStatus === FactureFournisseur::STATUS_DRAFT) {
					$deleteResult = (int) $invoice->delete($this->user);
					if ($deleteResult <= 0) {
						$cleanupErrors[] = 'failed_delete_supplier_invoice_' . $invoiceId;
					}
				} elseif ($invoiceStatus === FactureFournisseur::STATUS_VALIDATED) {
					$closeNote = 'KreaBank auto-cancel after reconciliation failure';
					if ($failureMessage !== '') {
						$closeNote .= ': ' . $failureMessage;
					}
					$cancelResult = (int) $invoice->setCanceled($this->user, FactureFournisseur::CLOSECODE_ABANDONED, $closeNote);
					if ($cancelResult <= 0) {
						$cleanupErrors[] = 'failed_cancel_supplier_invoice_' . $invoiceId;
					}
				} elseif ($invoiceStatus !== FactureFournisseur::STATUS_ABANDONED) {
					$cleanupErrors[] = 'unsupported_supplier_invoice_status_' . $invoiceId . '_' . $invoiceStatus;
				}
			}
		} catch (Throwable $cleanupError) {
			$cleanupErrors[] = 'supplier_invoice_cleanup_exception_' . $invoiceId;
		}

		if (!empty($cleanupErrors) && function_exists('dol_syslog')) {
			dol_syslog('KreaBank quick supplier invoice cleanup issues: ' . implode(', ', $cleanupErrors), LOG_WARNING);
		}
	}

	/**
	 * Create supplier invoice from statement line, create payment and reconcile.
	 *
	 * @param int $lineId
	 * @param string $label
	 * @param float $amount
	 * @param int $supplierId
	 * @param string $note
	 * @param string $supplierRef
	 * @param array<int,array<string,mixed>> $invoiceProductLines
	 * @return array<string,mixed>
	 */
	public function createQuickSupplierInvoiceAndReconcile($lineId, $label, $amount, $supplierId = 0, $note = '', $supplierRef = '', $invoiceProductLines = array())
	{
		$line = $this->getLineById($lineId);
		if (!$line) {
			throw new Exception('Unknown statement line');
		}

		$lineDirection = (int) (isset($line['direction']) ? $line['direction'] : 0);
		if ($lineDirection === 0) {
			$lineAmount = (float) (!empty($line['amount']) ? $line['amount'] : 0.0);
			if ($lineAmount > 0.0000001) {
				$lineDirection = 1;
			} elseif ($lineAmount < -0.0000001) {
				$lineDirection = -1;
			}
		}
		if ($lineDirection > 0) {
			throw new Exception('Supplier invoice quick flow is only available for debit statement lines');
		}

		$amount = abs((float) price2num((string) $amount, 'MU'));
		if ($amount <= 0.00001) {
			throw new Exception('Supplier invoice amount must be positive');
		}

		$outstanding = max(0.0, abs((float) $line['amount']) - abs((float) $line['allocated_amount']));
		if ($outstanding <= 0.00001) {
			$outstanding = abs((float) $line['amount']);
		}
		if ($outstanding <= 0.00001) {
			throw new Exception('Cannot create supplier invoice for zero-amount statement line');
		}
		if ($amount > $outstanding) {
			$amount = $outstanding;
		}

		$label = trim((string) $label);
		if ($label === '') {
			$label = trim((string) (!empty($line['description']) ? $line['description'] : ''));
		}
		if ($label === '') {
			$label = trim((string) (!empty($line['counterparty_name']) ? $line['counterparty_name'] : ''));
		}
		if ($label === '') {
			$label = trim((string) (!empty($line['payment_reference']) ? $line['payment_reference'] : ''));
		}
		if ($label === '') {
			$label = 'Supplier invoice from bank line #' . ((int) $line['rowid']);
		}
		$label = dol_trunc((string) preg_replace('/\s+/', ' ', $label), 190, 'right', 'UTF-8', 1);

		$prediction = array();
		$supplierId = (int) $supplierId;
		if ($supplierId <= 0) {
			$prediction = $this->predictSupplierForLine($line);
			if (!empty($prediction['is_confident']) && !empty($prediction['predicted_socid'])) {
				$supplierId = (int) $prediction['predicted_socid'];
			}
		}
		if ($supplierId <= 0) {
			throw new Exception('Unable to resolve supplier from VAT/name lookup or ML prediction.');
		}

		$created = $this->createSupplierInvoiceForBankLine(
			$line,
			(int) $supplierId,
			(string) $label,
			(float) $amount,
			(string) $note,
			(string) $supplierRef,
			(array) $invoiceProductLines
		);

		$confidenceScore = (!empty($prediction['is_confident']) && !empty($prediction['probability_pct'])) ? (int) $prediction['probability_pct'] : 0;

		try {
			$this->reconcileLine(
				(int) $line['rowid'],
				array(array(
					'doc_type' => 'supplier_invoice',
					'fk_doc' => (int) $created['invoice_id'],
					'doc_ref' => (string) $created['invoice_ref'],
					'allocated_amount' => (float) $amount,
					'match_score' => (int) $confidenceScore,
					'match_reasons' => !empty($prediction['is_confident']) ? array('quick_supplier_invoice', 'ml_supplier') : array('quick_supplier_invoice'),
				)),
				'quick_supplier_invoice',
				0,
				(string) $note,
				(int) $confidenceScore
			);
		} catch (Exception $e) {
			$this->cleanupFailedQuickSupplierInvoiceFlow((int) $created['invoice_id'], $e->getMessage());
			throw $e;
		}

		$this->logAudit(
			'quick_supplier_invoice',
			(int) $line['rowid'],
			(int) $line['rowid'],
			array(
				'supplier_invoice_id' => (int) $created['invoice_id'],
				'supplier_invoice_ref' => (string) $created['invoice_ref'],
				'supplier_id' => (int) $created['supplier_id'],
				'supplier_name' => (string) $created['supplier_name'],
				'allocated_amount' => (float) $amount,
				'supplier_ml' => $prediction,
				'invoice_product_lines' => (array) $invoiceProductLines,
			)
		);

		return array(
			'invoice_id' => (int) $created['invoice_id'],
			'invoice_ref' => (string) $created['invoice_ref'],
			'supplier_id' => (int) $created['supplier_id'],
			'supplier_name' => (string) $created['supplier_name'],
			'allocated_amount' => (float) $amount,
			'supplier_prediction' => $prediction,
		);
	}

	/**
	 * Undo a reconciliation.
	 *
	 * @param int $reconciliationId
	 * @param string $reason
	 * @return bool
	 */
	public function undoReconciliation($reconciliationId, $reason = '')
	{
		$lineId = (int) $reconciliationId;
		if ($lineId <= 0) {
			throw new Exception('Invalid bank line id');
		}

		$line = $this->getLineById($lineId);
		if (!$line) {
			throw new Exception('Bank line not found');
		}

		$nativeLineId = $this->resolveNativeLineIdFromStatementLine($line, false);
		if ($nativeLineId <= 0) {
			throw new Exception('Native bank line not found');
		}

		if (!$this->db->begin()) {
			throw new Exception('Failed to start database transaction');
		}
		try {
			$res = $this->native->unconciliateLine($nativeLineId);
			if ($res <= 0) {
				throw new Exception('Failed to undo native bank conciliation');
			}
			if (!$this->native->markStatementLinePending($lineId)) {
				throw new Exception('Failed to mark statement line as pending');
			}

			$this->db->commit();
		} catch (Exception $e) {
			$this->db->rollback();
			throw $e;
		}

		$this->logAudit(
			'undo',
			$lineId,
			$lineId,
			array(
				'reason' => (string) $reason,
				'native' => 1,
				'native_bank_line_id' => $nativeLineId,
			)
		);

		return true;
	}

	/**
	 * Get audit history.
	 *
	 * @param int $limit
	 * @param int $offset
	 * @return array<int,array<string,mixed>>
	 */
	public function getAuditHistory($limit = 200, $offset = 0)
	{
		$auditTable = $this->ensureReconAuditTable();

		$sql = 'SELECT a.rowid, a.audit_type, a.fk_statement_line, a.fk_reconciliation, a.payload_json, a.fk_user, a.ip_address, a.datec, u.login';
		$sql .= ' FROM ' . $auditTable . ' as a';
		$sql .= ' LEFT JOIN ' . $this->db->prefix() . 'user as u ON u.rowid = a.fk_user';
		$sql .= ' WHERE a.entity = ' . ((int) $this->entity);
		$sql .= ' ORDER BY a.datec DESC';
		$sql .= $this->db->plimit((int) $limit, (int) $offset);

		$resql = $this->db->query($sql);
		if (!$resql) {
			throw new Exception($this->db->lasterror());
		}

		$rows = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$rows[] = array(
				'rowid' => (int) $obj->rowid,
				'audit_type' => (string) $obj->audit_type,
				'fk_statement_line' => (int) $obj->fk_statement_line,
				'fk_reconciliation' => (int) $obj->fk_reconciliation,
				'payload_json' => (string) $obj->payload_json,
				'fk_user' => (int) $obj->fk_user,
				'login' => (string) $obj->login,
				'ip_address' => (string) $obj->ip_address,
				'datec' => $obj->datec,
			);
		}

		return $rows;
	}

	/**
	 * Resolve configured audit retention window (days).
	 *
	 * @return int
	 */
	public function getAuditRetentionDays()
	{
		$days = (int) getDolGlobalInt('KREABANK_AUDIT_RETENTION_DAYS', 365);
		if ($days <= 0) {
			$days = 365;
		}

		return max(1, min(3650, $days));
	}

	/**
	 * Get audit retention diagnostics for current entity.
	 *
	 * @return array<string,mixed>
	 */
	public function getAuditRetentionDiagnostics()
	{
		$auditTable = $this->ensureReconAuditTable();
		$retentionDays = $this->getAuditRetentionDays();
		$cutoffTs = dol_now() - ($retentionDays * 86400);
		$cutoffDate = dol_print_date($cutoffTs, '%Y-%m-%d %H:%M:%S');

		$summary = array(
			'retention_days' => $retentionDays,
			'cutoff_date' => $cutoffDate,
			'total_rows' => 0,
			'purgeable_rows' => 0,
			'oldest_date' => '',
			'newest_date' => '',
		);

		$sql = 'SELECT COUNT(a.rowid) as total_rows,';
		$sql .= ' SUM(CASE WHEN a.datec < ' . $this->sqlDateTime($cutoffDate) . ' THEN 1 ELSE 0 END) as purgeable_rows,';
		$sql .= ' MIN(a.datec) as oldest_date,';
		$sql .= ' MAX(a.datec) as newest_date';
		$sql .= ' FROM ' . $auditTable . ' as a';
		$sql .= ' WHERE a.entity = ' . ((int) $this->entity);
		$resql = $this->db->query($sql);
		if ($resql && ($obj = $this->db->fetch_object($resql))) {
			$summary['total_rows'] = (int) (!empty($obj->total_rows) ? $obj->total_rows : 0);
			$summary['purgeable_rows'] = (int) (!empty($obj->purgeable_rows) ? $obj->purgeable_rows : 0);
			$summary['oldest_date'] = (string) (!empty($obj->oldest_date) ? $obj->oldest_date : '');
			$summary['newest_date'] = (string) (!empty($obj->newest_date) ? $obj->newest_date : '');
		}

		return $summary;
	}

	/**
	 * Purge reconciliation audit rows older than retention cutoff.
	 *
	 * @param int|null $retentionDays
	 * @return int
	 */
	public function purgeAuditRowsOlderThanRetention($retentionDays = null)
	{
		$auditTable = $this->ensureReconAuditTable();
		$days = ($retentionDays === null ? $this->getAuditRetentionDays() : (int) $retentionDays);
		$days = max(1, min(3650, $days));
		$cutoffTs = dol_now() - ($days * 86400);
		$cutoffDate = dol_print_date($cutoffTs, '%Y-%m-%d %H:%M:%S');

		if (!$this->db->begin()) {
			throw new Exception('Failed to start database transaction');
		}
		try {
			$sql = 'DELETE FROM ' . $auditTable;
			$sql .= ' WHERE entity = ' . ((int) $this->entity);
			$sql .= ' AND datec < ' . $this->sqlDateTime($cutoffDate);
			if (!$this->db->query($sql)) {
				throw new Exception($this->db->lasterror());
			}
			$deletedRows = 0;
			$resCount = $this->db->query('SELECT ROW_COUNT() as nb');
			if ($resCount && ($objCount = $this->db->fetch_object($resCount))) {
				$deletedRows = (int) (!empty($objCount->nb) ? $objCount->nb : 0);
			}
			$this->db->commit();

			$this->logAudit('audit_purge', null, null, array(
				'retention_days' => $days,
				'cutoff_date' => $cutoffDate,
				'deleted_rows' => $deletedRows,
			));

			return $deletedRows;
		} catch (Exception $e) {
			$this->db->rollback();
			throw $e;
		}
	}

	/**
	 * Get reconciliations for a native bank line.
	 *
	 * @param int $lineId
	 * @return array<int,array<string,mixed>>
	 */
	public function getLineReconciliations($lineId)
	{
		$line = $this->getLineById((int) $lineId);
		if (!$line || (int) $line['status'] !== 2) {
			return array();
		}

		return array(
			array(
				'rowid' => (int) $line['rowid'],
				'strategy' => 'native',
				'confidence_score' => 0,
				'is_auto' => 0,
				'note' => '',
				'date_validate' => $line['operation_date'],
				'is_reversed' => 0,
			),
		);
	}

	/**
	 * Get all links for a statement line.
	 *
	 * @param int $lineId
	 * @return array<int,array<string,mixed>>
	 */
	public function getLineLinks($lineId)
	{
		$line = $this->getLineById((int) $lineId);
		if (empty($line)) {
			return array();
		}

		$nativeLineId = $this->resolveNativeLineIdFromStatementLine($line, false);
		if ($nativeLineId <= 0) {
			return array();
		}

		return $this->native->getLineLinks($nativeLineId);
	}

	/**
	 * Get links for multiple statement lines.
	 *
	 * @param array<int,int> $lineIds
	 * @return array<int,array<int,array<string,mixed>>>
	 */
	public function getLineLinksBatch($lineIds)
	{
		return $this->native->getLineLinksBatch((array) $lineIds);
	}

	/**
	 * Get recent reconciliations.
	 *
	 * @param int $limit
	 * @param int $offset
	 * @return array<int,array<string,mixed>>
	 */
	public function getRecentReconciliations($limit = 200, $offset = 0)
	{
		return $this->native->getRecentReconciledLines((int) $limit, (int) $offset);
	}

	/**
	 * Run feed synchronization (stub for provider integration).
	 *
	 * @return array<string,mixed>
	 */
	public function syncBankFeed()
	{
		if (!getDolGlobalInt('KREABANK_FEED_ENABLED')) {
			$this->logAudit('feed_sync', null, null, array('status' => 'disabled'));
			return array('status' => 'disabled', 'imported' => 0);
		}

		$provider = getDolGlobalString('KREABANK_FEED_PROVIDER');
		$secretId = getDolGlobalString('KREABANK_FEED_SECRET_ID');
		$secretKey = getDolGlobalString('KREABANK_FEED_SECRET_KEY');

		if ($provider === '' || $secretId === '' || $secretKey === '') {
			$this->logAudit('feed_sync', null, null, array('status' => 'missing_credentials', 'provider' => $provider));
			return array('status' => 'missing_credentials', 'imported' => 0, 'provider' => $provider);
		}

		// The feed connector intentionally stays non-destructive by default.
		$this->logAudit('feed_sync', null, null, array('status' => 'configured_noop', 'provider' => $provider));

		return array('status' => 'configured_noop', 'imported' => 0, 'provider' => $provider);
	}

	/**
	 * Return recurring patterns.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	protected function getPatterns()
	{
		$this->ensurePatternTable();

		$sql = 'SELECT rowid, pattern_type, pattern_value, doc_type, fk_doc, doc_ref, fk_soc, hit_count, last_score';
		$sql .= ' FROM ' . $this->db->prefix() . 'kreabank_pattern';
		$sql .= ' WHERE entity = ' . ((int) $this->entity);
		$sql .= ' ORDER BY hit_count DESC, rowid DESC';
		$sql .= $this->db->plimit(2000, 0);

		$resql = $this->db->query($sql);
		if (!$resql) {
			return array();
		}

		$rows = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$rows[] = array(
				'rowid' => (int) $obj->rowid,
				'pattern_type' => (string) $obj->pattern_type,
				'pattern_value' => (string) $obj->pattern_value,
				'doc_type' => (string) $obj->doc_type,
				'fk_doc' => (int) $obj->fk_doc,
				'doc_ref' => (string) $obj->doc_ref,
				'fk_soc' => (int) $obj->fk_soc,
				'hit_count' => (int) $obj->hit_count,
				'last_score' => (int) $obj->last_score,
			);
		}

		return $rows;
	}

	/**
	 * Check if ML-assisted batch detection can run.
	 *
	 * @return bool
	 */
	protected function isBatchMlEnabled()
	{
		if ((int) getDolGlobalInt('KREABANK_BATCH_ML_ENABLED', 1) <= 0) {
			return false;
		}

		return $this->isBatchPhpMlAvailable();
	}

	/**
	 * Check if ML-assisted supplier prediction can run.
	 *
	 * @return bool
	 */
	protected function isSupplierMlEnabled()
	{
		if ((int) getDolGlobalInt('KREABANK_SUPPLIER_ML_ENABLED', 1) <= 0) {
			return false;
		}

		return $this->isBatchPhpMlAvailable();
	}

	/**
	 * Try to load PHP-ML from this module only.
	 *
	 * @return bool
	 */
	protected function isBatchPhpMlAvailable()
	{
		$requiredClasses = array(
			'knn' => '\\Phpml\\Classification\\KNearestNeighbors',
			'naive_bayes' => '\\Phpml\\Classification\\NaiveBayes',
			'decision_tree' => '\\Phpml\\Classification\\DecisionTree',
			'random_forest' => '\\Phpml\\Classification\\RandomForest',
		);
		$classifierCode = $this->resolveBatchMlClassifierCode();
		$requiredClass = !empty($requiredClasses[$classifierCode]) ? (string) $requiredClasses[$classifierCode] : $requiredClasses['knn'];
		if ((class_exists($requiredClass) || class_exists('\\Phpml\\Classification\\KNearestNeighbors')) && class_exists('\\Phpml\\Classification\\KNearestNeighbors')) {
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
				if ((class_exists($requiredClass) || class_exists('\\Phpml\\Classification\\KNearestNeighbors')) && class_exists('\\Phpml\\Classification\\KNearestNeighbors')) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Resolve configured batch classifier code.
	 *
	 * @return string
	 */
	protected function resolveBatchMlClassifierCode()
	{
		$raw = 'knn';
		if (function_exists('getDolGlobalString')) {
			$raw = strtolower(trim((string) getDolGlobalString('KREABANK_BATCH_ML_CLASSIFIER', 'knn')));
		} elseif (!empty($GLOBALS['conf']->global->KREABANK_BATCH_ML_CLASSIFIER)) {
			$raw = strtolower(trim((string) $GLOBALS['conf']->global->KREABANK_BATCH_ML_CLASSIFIER));
		}
		if (!in_array($raw, array('knn', 'naive_bayes', 'decision_tree', 'random_forest'), true)) {
			return 'knn';
		}

		return $raw;
	}

	/**
	 * Resolve adaptive odd neighborhood size based on sample count.
	 *
	 * @param int $sampleCount
	 * @param int $minimum
	 * @param int $maximum
	 * @return int
	 */
	protected function resolveAdaptiveNeighborCount($sampleCount, $minimum = 3, $maximum = 31)
	{
		$sampleCount = max(1, (int) $sampleCount);
		$minimum = max(1, (int) $minimum);
		$maximum = max($minimum, (int) $maximum);

		$k = (int) floor(sqrt((float) $sampleCount));
		if ($k < $minimum) {
			$k = $minimum;
		}
		if ($k > $maximum) {
			$k = $maximum;
		}
		if (($k % 2) === 0) {
			$k = ($k > 1) ? ($k - 1) : 1;
		}
		if ($k > $sampleCount) {
			$k = $sampleCount;
		}
		if (($k % 2) === 0 && $k > 1) {
			$k--;
		}

		return max(1, $k);
	}

	/**
	 * Rebalance batch samples to cap class skew while preserving recency.
	 *
	 * @param array<int,array<string,mixed>> $samples
	 * @param int $maxRatio
	 * @return array<int,array<string,mixed>>
	 */
	protected function rebalanceBatchMlSamples($samples, $maxRatio = 4)
	{
		$samples = array_values((array) $samples);
		$maxRatio = max(2, (int) $maxRatio);
		if (count($samples) < 4) {
			return $samples;
		}

		$positiveIndexes = array();
		$negativeIndexes = array();
		foreach ($samples as $idx => $sample) {
			$label = isset($sample['label']) ? (int) $sample['label'] : -1;
			if ($label === 1) {
				$positiveIndexes[] = (int) $idx;
			} elseif ($label === 0) {
				$negativeIndexes[] = (int) $idx;
			}
		}

		$positives = count($positiveIndexes);
		$negatives = count($negativeIndexes);
		if ($positives === 0 || $negatives === 0) {
			return $samples;
		}

		$majorityIndexes = ($positives >= $negatives) ? $positiveIndexes : $negativeIndexes;
		$minorityCount = min($positives, $negatives);
		$maxMajorityCount = max($minorityCount, $minorityCount * $maxRatio);
		if (count($majorityIndexes) <= $maxMajorityCount) {
			return $samples;
		}

		$keepMajorityIndexes = array_slice($majorityIndexes, count($majorityIndexes) - $maxMajorityCount);
		$keepMap = array_fill_keys($keepMajorityIndexes, true);
		$majorityLabel = ($positives >= $negatives ? 1 : 0);

		$balanced = array();
		foreach ($samples as $idx => $sample) {
			$label = isset($sample['label']) ? (int) $sample['label'] : -1;
			if ($label === $majorityLabel && empty($keepMap[$idx])) {
				continue;
			}
			$balanced[] = $sample;
		}

		return $balanced;
	}

	/**
	 * Instantiate configured batch classifier.
	 *
	 * @param int $sampleCount
	 * @param array<string,mixed> $meta
	 * @return object|null
	 */
	protected function instantiateBatchMlClassifier($sampleCount, &$meta)
	{
		$meta = array(
			'code' => $this->resolveBatchMlClassifierCode(),
			'class' => '',
		);
		$sampleCount = max(1, (int) $sampleCount);

		if ($meta['code'] === 'naive_bayes' && class_exists('\\Phpml\\Classification\\NaiveBayes')) {
			$className = '\\Phpml\\Classification\\NaiveBayes';
			$meta['class'] = $className;
			return new $className();
		}
		if ($meta['code'] === 'decision_tree' && class_exists('\\Phpml\\Classification\\DecisionTree')) {
			$className = '\\Phpml\\Classification\\DecisionTree';
			$meta['class'] = $className;
			return new $className();
		}
		if ($meta['code'] === 'random_forest' && class_exists('\\Phpml\\Classification\\RandomForest')) {
			$className = '\\Phpml\\Classification\\RandomForest';
			$meta['class'] = $className;
			$meta['trees'] = 31;
			return new $className(31);
		}

		if (!class_exists('\\Phpml\\Classification\\KNearestNeighbors')) {
			return null;
		}
		$className = '\\Phpml\\Classification\\KNearestNeighbors';
		$k = $this->resolveAdaptiveNeighborCount($sampleCount, 3, 31);
		$meta['code'] = 'knn';
		$meta['class'] = $className;
		$meta['k'] = $k;

		return new $className($k);
	}

	/**
	 * Build deterministic cache key for persisted classifier.
	 *
	 * @param array<int,array<string,mixed>> $samples
	 * @param array<string,mixed> $classifierMeta
	 * @return string
	 */
	protected function buildBatchMlClassifierCacheKey($samples, $classifierMeta)
	{
		$parts = array();
		$parts[] = 'entity:' . ((int) $this->entity);
		$parts[] = 'meta:' . json_encode((array) $classifierMeta);
		$parts[] = 'count:' . count((array) $samples);
		$tail = array_slice((array) $samples, -120);
		foreach ($tail as $sample) {
			if (!is_array($sample)) {
				continue;
			}
			$label = isset($sample['label']) ? (int) $sample['label'] : -1;
			$datec = isset($sample['datec']) ? (string) $sample['datec'] : '';
			$features = isset($sample['features']) && is_array($sample['features']) ? array_slice((array) $sample['features'], 0, 6) : array();
			$featureHash = sha1(json_encode($features));
			$parts[] = $label . '|' . $datec . '|' . $featureHash;
		}

		return sha1(implode(';', $parts));
	}

	/**
	 * Decode JSON payload as associative array with bounded nesting depth.
	 *
	 * @param string $raw
	 * @param int $depth
	 * @return array<mixed>|null
	 */
	protected function decodeJsonArraySafe($raw, $depth = 16)
	{
		$raw = (string) $raw;
		if ($raw === '') {
			return null;
		}

		$decoded = json_decode($raw, true, max(1, (int) $depth));
		if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
			return null;
		}

		return $decoded;
	}

	/**
	 * Load cached classifier from disk when cache key matches.
	 *
	 * @param string $cacheKey
	 * @return object|null
	 */
	protected function loadCachedBatchMlClassifier($cacheKey)
	{
		$path = $this->getBatchMlClassifierCachePath();
		if ($path === '' || !is_readable($path)) {
			return null;
		}

		$raw = file_get_contents($path);
		if (!is_string($raw) || $raw === '') {
			return null;
		}
		$decoded = $this->decodeJsonArraySafe($raw);
		if ($decoded === null || (string) (!empty($decoded['cache_key']) ? $decoded['cache_key'] : '') !== $cacheKey) {
			return null;
		}
		$modelData = !empty($decoded['model_data']) ? (string) $decoded['model_data'] : '';
		if ($modelData === '') {
			return null;
		}

		$payload = base64_decode($modelData, true);
		if (!is_string($payload) || $payload === '') {
			return null;
		}

		try {
			$classifier = unserialize($payload);
		} catch (Throwable $e) {
			return null;
		}
		if (!is_object($classifier) || !method_exists($classifier, 'predict')) {
			return null;
		}

		return $classifier;
	}

	/**
	 * Persist trained classifier to disk cache.
	 *
	 * @param object $classifier
	 * @param string $cacheKey
	 * @param array<string,mixed> $meta
	 * @return void
	 */
	protected function persistBatchMlClassifierCache($classifier, $cacheKey, $meta = array())
	{
		$path = $this->getBatchMlClassifierCachePath();
		if ($path === '') {
			return;
		}

		$dir = dirname($path);
		if (!is_dir($dir)) {
			dol_mkdir($dir);
		}

		try {
			$serialized = serialize($classifier);
		} catch (Throwable $e) {
			return;
		}
		if (!is_string($serialized) || $serialized === '') {
			return;
		}

		$payload = array(
			'cache_key' => (string) $cacheKey,
			'meta' => (array) $meta,
			'model_data' => base64_encode($serialized),
		);
		$payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE);
		if (!is_string($payloadJson) || $payloadJson === '') {
			dol_syslog('KreaBank failed to encode batch ML classifier cache payload for entity ' . ((int) $this->entity), LOG_WARNING);
			return;
		}

		$tmpPath = $path . '.tmp.' . ((int) getmypid()) . '.' . str_replace('.', '', (string) microtime(true));
		$written = file_put_contents($tmpPath, $payloadJson, LOCK_EX);
		if ($written === false) {
			dol_syslog('KreaBank failed to write batch ML classifier cache temp file: ' . $tmpPath, LOG_WARNING);
			if (file_exists($tmpPath) && !unlink($tmpPath)) {
				dol_syslog('KreaBank failed to cleanup batch ML classifier cache temp file: ' . $tmpPath, LOG_WARNING);
			}
			return;
		}

		if (!rename($tmpPath, $path)) {
			dol_syslog('KreaBank failed to replace batch ML classifier cache file: ' . $path, LOG_WARNING);
			if (file_exists($tmpPath) && !unlink($tmpPath)) {
				dol_syslog('KreaBank failed to cleanup batch ML classifier cache temp file: ' . $tmpPath, LOG_WARNING);
			}
		}
	}

	/**
	 * Invalidate persisted classifier cache for current entity.
	 *
	 * @return void
	 */
	protected function invalidateBatchMlClassifierCache()
	{
		$path = $this->getBatchMlClassifierCachePath();
		if ($path !== '' && file_exists($path) && !unlink($path)) {
			dol_syslog('KreaBank failed to invalidate batch ML classifier cache file: ' . $path, LOG_WARNING);
		}
	}

	/**
	 * Get on-disk path for batch ML classifier cache.
	 *
	 * @return string
	 */
	protected function getBatchMlClassifierCachePath()
	{
		if (!defined('DOL_DATA_ROOT') || DOL_DATA_ROOT === '') {
			return '';
		}

		return rtrim((string) DOL_DATA_ROOT, '/') . '/kreabank/batch_ml_classifier_entity' . $this->entity . '.json';
	}

	/**
	 * Append one normalized batch ML sample into DB storage.
	 *
	 * @param array<int,float|int|string> $features
	 * @param int $label
	 * @param string|null $sampleDate
	 * @return bool
	 */
	protected function appendBatchMlSample($features, $label, $sampleDate = null)
	{
		$label = (int) $label;
		if ($label !== 0 && $label !== 1) {
			return false;
		}

		$normalized = $this->normalizeBatchMlVector((array) $features);
		if (count($normalized) < 6) {
			return false;
		}

		$table = $this->ensureBatchMlSampleTable();
		$when = '';
		if (is_string($sampleDate) && trim($sampleDate) !== '') {
			$timestamp = strtotime((string) $sampleDate);
			if ($timestamp !== false && $timestamp > 0) {
				$when = dol_print_date($timestamp, '%Y-%m-%d %H:%M:%S');
			}
		}
		if ($when === '') {
			$when = dol_print_date(dol_now(), '%Y-%m-%d %H:%M:%S');
		}

		$sql = 'INSERT INTO ' . $table . ' (entity, label, features_json, datec) VALUES (';
		$sql .= ((int) $this->entity);
		$sql .= ', ' . $label;
		$sql .= ', ' . $this->sqlText(json_encode(array_values($normalized), JSON_UNESCAPED_UNICODE));
		$sql .= ', ' . $this->sqlDateTime($when);
		$sql .= ')';

		if (!$this->db->query($sql)) {
			return false;
		}

		return true;
	}

	/**
	 * Load persisted batch ML samples from DB storage.
	 *
	 * @return array<int,array{features:array<int,float>,label:int,datec:string,rowid?:int}>
	 */
	protected function loadBatchMlSamples()
	{
		$table = $this->ensureBatchMlSampleTable();
		$sql = 'SELECT rowid, label, features_json, datec';
		$sql .= ' FROM ' . $table;
		$sql .= ' WHERE entity = ' . ((int) $this->entity);
		$sql .= ' ORDER BY rowid DESC';
		$sql .= $this->db->plimit(6000, 0);
		$resql = $this->db->query($sql);

		$samples = array();
		if ($resql) {
			while ($obj = $this->db->fetch_object($resql)) {
				$label = isset($obj->label) ? (int) $obj->label : -1;
				if ($label !== 0 && $label !== 1) {
					continue;
				}
				$decoded = $this->decodeJsonArraySafe((string) $obj->features_json);
				if ($decoded === null || count($decoded) < 6) {
					continue;
				}
				$normalized = $this->normalizeBatchMlVector($decoded);
				if (count($normalized) < 6) {
					continue;
				}
				$samples[] = array(
					'rowid' => (int) $obj->rowid,
					'features' => $normalized,
					'label' => $label,
					'datec' => (string) (!empty($obj->datec) ? $obj->datec : ''),
				);
			}
		}

		if (!empty($samples) && !empty($samples[0]['rowid'])) {
			$samples = array_reverse($samples);
		}

		if (count($samples) > 5000) {
			$samples = array_slice($samples, count($samples) - 5000);
		}

		return $samples;
	}

	/**
	 * Persist normalized batch ML samples (compatibility helper).
	 *
	 * @param array<int,array{features:array<int,float>,label:int}> $samples
	 * @return void
	 */
	protected function saveBatchMlSamples($samples)
	{
		$table = $this->ensureBatchMlSampleTable();

		$clean = array();
		foreach ((array) $samples as $sample) {
			if (!is_array($sample)) {
				continue;
			}
			$label = isset($sample['label']) ? (int) $sample['label'] : -1;
			if ($label !== 0 && $label !== 1) {
				continue;
			}
			$features = isset($sample['features']) && is_array($sample['features']) ? (array) $sample['features'] : array();
			$features = $this->normalizeBatchMlVector($features);
			if (count($features) < 6) {
				continue;
			}
			$clean[] = array(
				'features' => $features,
				'label' => $label,
			);
		}

		if (count($clean) > 5000) {
			$clean = array_slice($clean, count($clean) - 5000);
		}

		$this->db->query('DELETE FROM ' . $table . ' WHERE entity = ' . ((int) $this->entity));
		foreach ($clean as $sample) {
			$this->appendBatchMlSample($sample['features'], (int) $sample['label'], null);
		}
	}

	/**
	 * Get or train ML classifier for batch detection.
	 *
	 * @return object|null
	 */
	protected function getBatchMlClassifier()
	{
		if ($this->batchMlClassifierReady) {
			return $this->batchMlClassifier;
		}
		$this->batchMlClassifierReady = true;
		$this->batchMlClassifier = null;
		$this->batchMlSamples = array();
		$this->batchMlClassifierCacheKey = null;

		if (!$this->isBatchMlEnabled()) {
			return null;
		}

		$samples = $this->rebalanceBatchMlSamples($this->loadBatchMlSamples(), 4);
		$minSamples = (int) getDolGlobalInt('KREABANK_BATCH_ML_MIN_SAMPLES', 24);
		$minSamples = max(8, min(2000, $minSamples));
		if (count($samples) < $minSamples) {
			return null;
		}

		$positives = 0;
		$negatives = 0;
		$trainVectors = array();
		$trainLabels = array();
		foreach ($samples as $sample) {
			$label = (int) $sample['label'];
			if ($label === 1) {
				$positives++;
			} elseif ($label === 0) {
				$negatives++;
			}
			$trainVectors[] = (array) $sample['features'];
			$trainLabels[] = $label;
		}
		if ($positives < 4 || $negatives < 4) {
			return null;
		}
		if (count($trainVectors) !== count($trainLabels) || empty($trainVectors)) {
			return null;
		}

		$classifierMeta = array();
		$classifier = $this->instantiateBatchMlClassifier(count($trainVectors), $classifierMeta);
		if (!is_object($classifier) || !method_exists($classifier, 'predict')) {
			return null;
		}
		$cacheKey = $this->buildBatchMlClassifierCacheKey($samples, $classifierMeta);
		$this->batchMlClassifierCacheKey = $cacheKey;

		$cachedClassifier = $this->loadCachedBatchMlClassifier($cacheKey);
		if (is_object($cachedClassifier) && method_exists($cachedClassifier, 'predict')) {
			$this->batchMlClassifier = $cachedClassifier;
			$this->batchMlSamples = $samples;
			return $this->batchMlClassifier;
		}

		try {
			$classifier->train($trainVectors, $trainLabels);
			$this->batchMlClassifier = $classifier;
			$this->batchMlSamples = $samples;
			$this->persistBatchMlClassifierCache($classifier, $cacheKey, $classifierMeta);
		} catch (Throwable $e) {
			$this->batchMlClassifier = null;
			$this->batchMlSamples = array();
			$this->batchMlClassifierCacheKey = null;
		}

		return $this->batchMlClassifier;
	}

	/**
	 * Evaluate batch ML quality with a stratified holdout split.
	 *
	 * @return array<string,mixed>
	 */
	public function getBatchMlValidationReport()
	{
		$report = array(
			'enabled' => 0,
			'classifier' => $this->resolveBatchMlClassifierCode(),
			'sample_count' => 0,
			'train_count' => 0,
			'test_count' => 0,
			'accuracy_pct' => 0.0,
			'precision_pct' => 0.0,
			'recall_pct' => 0.0,
			'confusion' => array('tp' => 0, 'fp' => 0, 'tn' => 0, 'fn' => 0),
			'status' => 'disabled',
		);

		if (!$this->isBatchMlEnabled()) {
			$report['status'] = 'disabled_by_setup_or_dependency';
			return $report;
		}
		$report['enabled'] = 1;

		$samples = $this->rebalanceBatchMlSamples($this->loadBatchMlSamples(), 4);
		$report['sample_count'] = count($samples);
		if (count($samples) < 10) {
			$report['status'] = 'model_not_ready';
			return $report;
		}

		$byLabel = array(0 => array(), 1 => array());
		foreach ($samples as $sample) {
			if (!is_array($sample)) {
				continue;
			}
			$label = isset($sample['label']) ? (int) $sample['label'] : -1;
			if (!array_key_exists($label, $byLabel)) {
				continue;
			}
			$features = isset($sample['features']) && is_array($sample['features']) ? (array) $sample['features'] : array();
			if (count($features) < 6) {
				continue;
			}
			$byLabel[$label][] = array(
				'features' => $features,
				'label' => $label,
			);
		}
		if (count($byLabel[0]) < 2 || count($byLabel[1]) < 2) {
			$report['status'] = 'class_coverage_insufficient';
			return $report;
		}

		$train = array();
		$test = array();
		foreach (array(0, 1) as $label) {
			$classRows = $byLabel[$label];
			$classCount = count($classRows);
			$testCount = (int) floor($classCount * 0.2);
			$testCount = max(1, $testCount);
			if (($classCount - $testCount) < 1) {
				$testCount = max(0, $classCount - 1);
			}
			if ($testCount <= 0) {
				$report['status'] = 'holdout_split_failed';
				return $report;
			}
			$train = array_merge($train, array_slice($classRows, 0, $classCount - $testCount));
			$test = array_merge($test, array_slice($classRows, $classCount - $testCount));
		}

		$report['train_count'] = count($train);
		$report['test_count'] = count($test);
		if (empty($train) || empty($test)) {
			$report['status'] = 'holdout_split_failed';
			return $report;
		}

		$trainVectors = array();
		$trainLabels = array();
		foreach ($train as $sample) {
			$trainVectors[] = (array) $sample['features'];
			$trainLabels[] = (int) $sample['label'];
		}

		$classifierMeta = array();
		$classifier = $this->instantiateBatchMlClassifier(count($trainVectors), $classifierMeta);
		if (!is_object($classifier) || !method_exists($classifier, 'train') || !method_exists($classifier, 'predict')) {
			$report['status'] = 'classifier_unavailable';
			return $report;
		}
		$report['classifier'] = (string) (!empty($classifierMeta['code']) ? $classifierMeta['code'] : $report['classifier']);

		try {
			$classifier->train($trainVectors, $trainLabels);
		} catch (Throwable $e) {
			$report['status'] = 'validation_train_failed';
			return $report;
		}

		$tp = 0;
		$fp = 0;
		$tn = 0;
		$fn = 0;
		foreach ($test as $sample) {
			$expected = (int) $sample['label'];
			$predicted = 0;
			try {
				$predicted = (int) $classifier->predict((array) $sample['features']);
			} catch (Throwable $e) {
				continue;
			}
			if ($expected === 1 && $predicted === 1) {
				$tp++;
			} elseif ($expected === 0 && $predicted === 1) {
				$fp++;
			} elseif ($expected === 0 && $predicted === 0) {
				$tn++;
			} elseif ($expected === 1 && $predicted === 0) {
				$fn++;
			}
		}

		$total = $tp + $tn + $fp + $fn;
		if ($total <= 0) {
			$report['status'] = 'validation_no_predictions';
			return $report;
		}

		$accuracy = ($tp + $tn) / $total;
		$precision = (($tp + $fp) > 0 ? ($tp / ($tp + $fp)) : 0.0);
		$recall = (($tp + $fn) > 0 ? ($tp / ($tp + $fn)) : 0.0);

		$report['accuracy_pct'] = round($accuracy * 100.0, 2);
		$report['precision_pct'] = round($precision * 100.0, 2);
		$report['recall_pct'] = round($recall * 100.0, 2);
		$report['confusion'] = array(
			'tp' => $tp,
			'fp' => $fp,
			'tn' => $tn,
			'fn' => $fn,
		);
		$report['status'] = 'ok';

		return $report;
	}

	/**
	 * Estimate ML confidence using weighted nearest neighbors.
	 *
	 * @param array<int,float> $vector
	 * @param array<int,array{features:array<int,float>,label:int}> $samples
	 * @return float
	 */
	protected function estimateBatchMlProbability($vector, $samples)
	{
		if (!is_array($samples) || empty($samples)) {
			return 0.0;
		}

		$distances = array();
		foreach ($samples as $sample) {
			if (!is_array($sample) || !isset($sample['features']) || !isset($sample['label'])) {
				continue;
			}
			$features = is_array($sample['features']) ? (array) $sample['features'] : array();
			if (count($features) < 6) {
				continue;
			}
			$distances[] = array(
				'distance' => $this->batchMlDistance($vector, $features),
				'label' => (int) $sample['label'],
			);
		}

		if (empty($distances)) {
			return 0.0;
		}

		usort($distances, static function ($a, $b) {
			$da = (float) (isset($a['distance']) ? $a['distance'] : 0.0);
			$db = (float) (isset($b['distance']) ? $b['distance'] : 0.0);
			if ($da === $db) {
				return 0;
			}
			return ($da < $db) ? -1 : 1;
		});

		$k = min($this->resolveAdaptiveNeighborCount(count($distances), 3, 31), count($distances));
		$scorePositive = 0.0;
		$scoreTotal = 0.0;
		for ($i = 0; $i < $k; $i++) {
			$row = $distances[$i];
			$distance = (float) $row['distance'];
			$weight = 1.0 / (1.0 + max(0.0, $distance));
			$scoreTotal += $weight;
			if ((int) $row['label'] === 1) {
				$scorePositive += $weight;
			}
		}

		if ($scoreTotal <= 0.0) {
			return 0.0;
		}

		return max(0.0, min(1.0, ($scorePositive / $scoreTotal)));
	}

	/**
	 * Euclidean distance between two normalized vectors.
	 *
	 * @param array<int,float> $a
	 * @param array<int,float> $b
	 * @return float
	 */
	protected function batchMlDistance($a, $b)
	{
		$len = min(count((array) $a), count((array) $b));
		if ($len <= 0) {
			return 999.0;
		}

		$sum = 0.0;
		for ($i = 0; $i < $len; $i++) {
			$av = (float) (isset($a[$i]) ? $a[$i] : 0.0);
			$bv = (float) (isset($b[$i]) ? $b[$i] : 0.0);
			$delta = $av - $bv;
			$sum += ($delta * $delta);
		}

		return sqrt(max(0.0, $sum));
	}

	/**
	 * Normalize batch ML vector values to [0,1].
	 *
	 * @param array<int,float|int|string> $vector
	 * @return array<int,float>
	 */
	protected function normalizeBatchMlVector($vector)
	{
		$raw = array_values((array) $vector);
		if (count($raw) < 6) {
			return array();
		}

		$keywordHit = ((float) $raw[0] > 0.0 ? 1.0 : 0.0);
		$candidateCount = max(0.0, min(1.0, ((float) $raw[1]) / 40.0));
		$coveragePct = max(0.0, min(1.0, ((float) $raw[2]) / 250.0));
		$amount = abs((float) $raw[3]);
		$amountNorm = max(0.0, min(1.0, (log10(1.0 + $amount) / 6.0)));
		$textLen = max(0.0, min(1.0, ((float) $raw[4]) / 140.0));
		$direction = ((float) $raw[5] > 0.0 ? 1.0 : 0.0);

		return array(
			$keywordHit,
			$candidateCount,
			$coveragePct,
			$amountNorm,
			$textLen,
			$direction,
		);
	}

	/**
	 * Build batch ML feature snapshot before reconciliation mutates line/doc state.
	 *
	 * @param array<string,mixed> $line
	 * @return array<int,float>
	 */
	protected function buildBatchMlSnapshotForLine($line)
	{
		if (!is_array($line) || empty($line)) {
			return array();
		}
		if (!$this->isBatchMlEnabled()) {
			return array();
		}

		$anchorDate = !empty($line['operation_date']) ? (string) $line['operation_date'] : (!empty($line['value_date']) ? (string) $line['value_date'] : null);
		$intervalDays = (int) getDolGlobalInt('KREABANK_OPEN_DOC_DATE_INTERVAL_DAYS', 7);
		if ($intervalDays < 0) {
			$intervalDays = 0;
		}

		try {
			$documents = $this->getOpenDocuments(
				(int) (!empty($line['direction']) ? $line['direction'] : 0),
				1200,
				$anchorDate,
				$intervalDays,
				(int) (!empty($line['rowid']) ? $line['rowid'] : 0),
				(int) (!empty($line['bank_account_id']) ? $line['bank_account_id'] : 0)
			);
		} catch (Exception $e) {
			return array();
		}

		return $this->buildBatchMlFeatureVector($line, (array) $documents);
	}

	/**
	 * Learn batch ML samples from confirmed reconciliations.
	 *
	 * @param array<string,mixed> $line
	 * @param array<int,array<string,mixed>> $links
	 * @param string $strategy
	 * @param int $isAuto
	 * @param array<int,float> $featureSnapshot
	 * @return void
	 */
	protected function learnBatchMlFromReconciliation($line, $links, $strategy, $isAuto, $featureSnapshot = array())
	{
		if (!$this->isBatchMlEnabled()) {
			return;
		}
		if (!is_array($line) || empty($line) || !is_array($links) || empty($links)) {
			return;
		}
		if (!$this->shouldLearnBatchMlFromReconciliation($strategy, $isAuto)) {
			return;
		}

		$label = $this->inferBatchMlLabelFromLinks($links);
		if ($label !== 0 && $label !== 1) {
			return;
		}

		$features = (is_array($featureSnapshot) && !empty($featureSnapshot))
			? $this->normalizeBatchMlVector($featureSnapshot)
			: $this->buildBatchMlSnapshotForLine($line);
		if (count($features) < 6) {
			return;
		}

		try {
			$sampleDate = !empty($line['operation_date']) ? (string) $line['operation_date'] : '';
			$this->appendBatchMlSample($features, $label, $sampleDate);
			$this->batchMlClassifierReady = false;
			$this->batchMlClassifier = null;
			$this->batchMlSamples = array();
			$this->batchMlClassifierCacheKey = null;
			$this->invalidateBatchMlClassifierCache();
		} catch (Throwable $e) {
			// Non-blocking learning: reconciliation flow must never fail because ML storage failed.
		}
	}

	/**
	 * Guard ML learning to manual reconciliation flows.
	 *
	 * @param string $strategy
	 * @param int $isAuto
	 * @return bool
	 */
	protected function shouldLearnBatchMlFromReconciliation($strategy, $isAuto)
	{
		if ((int) $isAuto === 1) {
			return false;
		}

		$strategy = trim((string) $strategy);
		return in_array($strategy, array('manual', 'drag_drop'), true);
	}

	/**
	 * Infer binary label from reconciled links.
	 *
	 * @param array<int,array<string,mixed>> $links
	 * @return int
	 */
	protected function inferBatchMlLabelFromLinks($links)
	{
		if (!is_array($links) || empty($links)) {
			return 0;
		}

		$linkCount = 0;
		$paymentLikeCount = 0;
		foreach ($links as $link) {
			if (!is_array($link)) {
				continue;
			}
			$linkCount++;
			$docType = trim((string) (isset($link['doc_type']) ? $link['doc_type'] : ''));
			if (in_array($docType, array('native_bank', 'payment', 'payment_supplier', 'payment_linked', 'payment_supplier_linked'), true)) {
				$paymentLikeCount++;
			}
		}

		if ($linkCount <= 0) {
			return 0;
		}

		return ($linkCount >= 2 && $paymentLikeCount >= 2 ? 1 : 0);
	}

	/**
	 * Build normalized feature vector for ML batch detection.
	 *
	 * @param array<string,mixed> $line
	 * @param array<int,array<string,mixed>> $documents
	 * @return array<int,float>
	 */
	protected function buildBatchMlFeatureVector($line, $documents = array())
	{
		if (!is_array($line) || empty($line)) {
			return array();
		}

		$metrics = $this->computeBatchCandidateMetrics($line, (array) $documents);
		$outstanding = max(0.0, abs((float) $line['amount']) - abs((float) (isset($line['allocated_amount']) ? $line['allocated_amount'] : 0.0)));
		if ($outstanding <= 0.00001) {
			$outstanding = abs((float) $line['amount']);
		}

		$textRaw = trim(
			(string) (!empty($line['description']) ? $line['description'] : '') . ' ' .
				(string) (!empty($line['payment_reference']) ? $line['payment_reference'] : '') . ' ' .
				(string) (!empty($line['native_label']) ? $line['native_label'] : '')
		);
		$textLen = strlen($textRaw);
		$direction = ((float) $line['amount'] >= 0.0 ? 1.0 : 0.0);
		$keywordHit = kreabankIsBatchLikeLine($line) ? 1.0 : 0.0;

		return $this->normalizeBatchMlVector(array(
			$keywordHit,
			(float) (isset($metrics['candidate_count']) ? $metrics['candidate_count'] : 0),
			(float) (isset($metrics['coverage_pct']) ? $metrics['coverage_pct'] : 0.0),
			$outstanding,
			(float) $textLen,
			$direction,
		));
	}

	/**
	 * Compute batch-candidate metrics from open documents.
	 *
	 * @param array<string,mixed> $line
	 * @param array<int,array<string,mixed>> $documents
	 * @return array<string,mixed>
	 */
	protected function computeBatchCandidateMetrics($line, $documents)
	{
		$lineDate = $this->normalizeDateYmd(
			!empty($line['operation_date'])
				? (string) $line['operation_date']
				: (!empty($line['value_date']) ? (string) $line['value_date'] : '')
		);
		if ($lineDate === '') {
			return array(
				'candidate_count' => 0,
				'coverage_pct' => 0.0,
				'target_cents' => 0,
			);
		}

		$outstanding = max(0.0, abs((float) $line['amount']) - abs((float) (isset($line['allocated_amount']) ? $line['allocated_amount'] : 0.0)));
		if ($outstanding <= 0.00001) {
			$outstanding = abs((float) $line['amount']);
		}
		$targetCents = (int) round($outstanding * 100);
		if ($targetCents <= 0) {
			return array(
				'candidate_count' => 0,
				'coverage_pct' => 0.0,
				'target_cents' => 0,
			);
		}

		$candidateCount = 0;
		$candidateTotalCents = 0;
		foreach ((array) $documents as $document) {
			if (!$this->isBatchPaymentLikeDocument($document)) {
				continue;
			}
			$docDate = $this->normalizeDateYmd((string) (isset($document['doc_date']) ? $document['doc_date'] : ''));
			if ($docDate !== $lineDate) {
				continue;
			}
			$docAmountCents = (int) round(abs((float) (isset($document['amount_open']) ? $document['amount_open'] : 0.0)) * 100);
			if ($docAmountCents <= 0 || $docAmountCents > $targetCents) {
				continue;
			}
			$candidateCount++;
			$candidateTotalCents += $docAmountCents;
		}

		$coveragePct = (float) floor(($candidateTotalCents * 100.0) / max(1, $targetCents));

		return array(
			'candidate_count' => $candidateCount,
			'coverage_pct' => $coveragePct,
			'target_cents' => $targetCents,
		);
	}

	/**
	 * Normalize date value to YYYY-MM-DD.
	 *
	 * @param string $value
	 * @return string
	 */
	protected function normalizeDateYmd($value)
	{
		$value = trim((string) $value);
		if ($value === '') {
			return '';
		}

		$ts = strtotime($value);
		if ($ts === false || $ts <= 0) {
			return '';
		}

		return dol_print_date($ts, '%Y-%m-%d');
	}

	/**
	 * Decode HTML entities recursively and normalize whitespace.
	 *
	 * @param string $value
	 * @param int $maxDepth
	 * @return string
	 */
	protected function decodeHtmlEntitiesRecursive($value, $maxDepth = 4)
	{
		$decoded = trim((string) $value);
		$depth = 0;
		while ($decoded !== '' && $depth < (int) $maxDepth) {
			$next = html_entity_decode($decoded, ENT_QUOTES | ENT_HTML5, 'UTF-8');
			if ($next === $decoded) {
				break;
			}
			$decoded = $next;
			$depth++;
		}

		return trim((string) preg_replace('/\s+/', ' ', $decoded));
	}

	/**
	 * Resolve social contribution type id from dictionary for current company country.
	 *
	 * @param int $preferredTypeId
	 * @return int
	 */
	protected function resolveSocialContributionTypeId($preferredTypeId = 0)
	{
		global $mysoc;
		$countryId = (is_object($mysoc) && !empty($mysoc->country_id)) ? (int) $mysoc->country_id : 0;
		$countryCode = (is_object($mysoc) && !empty($mysoc->country_code)) ? (string) $mysoc->country_code : '';

		$preferredTypeId = (int) $preferredTypeId;
		if ($preferredTypeId > 0) {
			$sql = 'SELECT c.id';
			$sql .= ' FROM ' . $this->db->prefix() . 'c_chargesociales as c';
			$sql .= ' WHERE c.active = 1';
			$sql .= ' AND c.id = ' . $preferredTypeId;
			$resql = $this->db->query($sql);
			if ($resql && $this->db->fetch_object($resql)) {
				return $preferredTypeId;
			}
		}

		$sql = 'SELECT c.id';
		$sql .= ' FROM ' . $this->db->prefix() . 'c_chargesociales as c';
		$sql .= ' LEFT JOIN ' . $this->db->prefix() . 'c_country as co ON co.rowid = c.fk_pays';
		$sql .= ' WHERE c.active = 1';
		if ($countryId > 0) {
			$sql .= ' AND c.fk_pays = ' . $countryId;
		} elseif ($countryCode !== '') {
			$sql .= " AND co.code = '" . $this->db->escape($countryCode) . "'";
		}
		$sql .= ' ORDER BY c.id ASC';
		$sql .= $this->db->plimit(1, 0);

		$resql = $this->db->query($sql);
		if ($resql && ($obj = $this->db->fetch_object($resql))) {
			return (int) $obj->id;
		}

		throw new Exception('No active social contribution type found. Configure dictionary values in Setup -> Dictionaries -> Social contributions types.');
	}

	/**
	 * Check if a document behaves like a payment candidate for batch matching.
	 *
	 * @param array<string,mixed> $document
	 * @return bool
	 */
	protected function isBatchPaymentLikeDocument($document)
	{
		if (!is_array($document) || empty($document)) {
			return false;
		}

		$docType = (string) (isset($document['doc_type']) ? $document['doc_type'] : '');
		if (in_array($docType, array('payment', 'payment_supplier', 'payment_linked', 'payment_supplier_linked'), true)) {
			return true;
		}
		if ($docType !== 'native_bank') {
			return false;
		}

		$allowedNativeTypes = array(
			'payment_salary' => true,
			'user' => true,
		);
		$urlTypesRaw = strtolower(trim((string) (isset($document['url_types']) ? $document['url_types'] : '')));
		if ($urlTypesRaw === '') {
			return false;
		}

		$urlTypeTokens = preg_split('/\s*,\s*/', $urlTypesRaw);
		if (!is_array($urlTypeTokens)) {
			return false;
		}
		foreach ($urlTypeTokens as $urlTypeToken) {
			$urlTypeToken = trim((string) $urlTypeToken);
			if ($urlTypeToken === '') {
				continue;
			}
			if (strpos($urlTypeToken, 'payment') === 0 || !empty($allowedNativeTypes[$urlTypeToken])) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get supplier ML hashed-text feature bin count.
	 *
	 * @return int
	 */
	protected function getSupplierMlHashBinCount()
	{
		return 64;
	}

	/**
	 * Get expected supplier ML feature vector dimension.
	 *
	 * @return int
	 */
	protected function getSupplierMlFeatureDimension()
	{
		return (4 + $this->getSupplierMlHashBinCount());
	}

	/**
	 * Build normalized feature vector for supplier prediction.
	 *
	 * @param array<string,mixed> $line
	 * @return array<int,float>
	 */
	protected function buildSupplierMlFeatureVector($line)
	{
		if (!is_array($line) || empty($line)) {
			return array();
		}

		$outstanding = max(0.0, abs((float) $line['amount']) - abs((float) (isset($line['allocated_amount']) ? $line['allocated_amount'] : 0.0)));
		if ($outstanding <= 0.00001) {
			$outstanding = abs((float) $line['amount']);
		}

		$amountNorm = max(0.0, min(1.0, (log10(1.0 + abs((float) $outstanding)) / 6.0)));
		$directionDebit = ((float) (!empty($line['amount']) ? $line['amount'] : 0.0) < 0.0 ? 1.0 : 0.0);
		$hasIban = (!empty($line['counterparty_iban']) ? 1.0 : 0.0);
		$counterpartyNameLen = strlen(trim((string) (!empty($line['counterparty_name']) ? $line['counterparty_name'] : '')));
		$counterpartyNameLenNorm = max(0.0, min(1.0, ((float) $counterpartyNameLen / 80.0)));

		$textRaw = trim(
			(string) (!empty($line['counterparty_name']) ? $line['counterparty_name'] : '') . ' ' .
				(string) (!empty($line['counterparty_iban']) ? $line['counterparty_iban'] : '') . ' ' .
				(string) (!empty($line['description']) ? $line['description'] : '') . ' ' .
				(string) (!empty($line['payment_reference']) ? $line['payment_reference'] : '') . ' ' .
				(string) (!empty($line['bank_reference']) ? $line['bank_reference'] : '') . ' ' .
				(string) (!empty($line['native_label']) ? $line['native_label'] : '')
		);
		$textNormalized = strtolower((string) preg_replace('/[^a-z0-9]+/', ' ', (string) kreabankNormalizeText($textRaw)));
		$textTokens = preg_split('/\s+/', trim((string) $textNormalized));

		$hashBinCount = $this->getSupplierMlHashBinCount();
		$hashBins = array_fill(0, $hashBinCount, 0.0);
		$tokenCount = 0;
		if (is_array($textTokens)) {
			foreach ($textTokens as $token) {
				$token = trim((string) $token);
				if ($token === '' || strlen($token) < 2) {
					continue;
				}
				$tokenCount++;
				$hash = (int) sprintf('%u', crc32($token));
				$index = ($hash % count($hashBins));
				$hashBins[$index] += 1.0;
			}
		}
		if ($tokenCount > 0) {
			foreach ($hashBins as $index => $count) {
				$hashBins[$index] = ((float) $count / (float) $tokenCount);
			}
		}

		return array_merge(
			array(
				$amountNorm,
				$directionDebit,
				$hasIban,
				$counterpartyNameLenNorm,
			),
			$hashBins
		);
	}

	/**
	 * Load ML training samples from historical reconciliations with one supplier label.
	 *
	 * @param int $preferredBankAccountId
	 * @return array<string,mixed>
	 */
	protected function loadSupplierMlTrainingSamples($preferredBankAccountId = 0)
	{
		$preferredBankAccountId = (int) $preferredBankAccountId;
		$expectedFeatureDimension = $this->getSupplierMlFeatureDimension();

		$sql = 'SELECT b.rowid as bank_line_id, b.fk_account as bank_account_id, b.amount,';
		$sql .= ' m.counterparty_name, m.counterparty_iban, m.description, m.payment_reference, m.bank_reference,';
		$sql .= " GROUP_CONCAT(DISTINCT bu.url_id ORDER BY bu.url_id SEPARATOR ',') as supplier_ids,";
		$sql .= " GROUP_CONCAT(DISTINCT s.nom ORDER BY s.nom SEPARATOR ' / ') as supplier_names,";
		$sql .= ' COUNT(DISTINCT bu.url_id) as supplier_count, MAX(b.dateo) as line_date';
		$sql .= ' FROM ' . $this->db->prefix() . 'bank as b';
		$sql .= ' INNER JOIN ' . $this->db->prefix() . 'bank_account as ba ON ba.rowid = b.fk_account';
		$sql .= " INNER JOIN " . $this->db->prefix() . "bank_url as bu ON bu.fk_bank = b.rowid AND bu.type = 'company'";
		$sql .= ' INNER JOIN ' . $this->db->prefix() . 'societe as s ON s.rowid = bu.url_id';
		$sql .= ' LEFT JOIN ' . $this->db->prefix() . 'kreabank_bankmeta as m ON m.entity = ' . ((int) $this->entity) . ' AND m.fk_bank_line = b.rowid';
		$sql .= ' WHERE ba.entity = ' . ((int) $this->entity);
		$sql .= ' AND s.entity = ' . ((int) $this->entity);
		$sql .= ' AND s.fournisseur > 0';
		$sql .= ' AND b.rappro = 1';
		$sql .= ' AND ABS(b.amount) > 0.0000001';
		$sql .= ' GROUP BY b.rowid, b.fk_account, b.amount, m.counterparty_name, m.counterparty_iban, m.description, m.payment_reference, m.bank_reference';
		$sql .= ' HAVING COUNT(DISTINCT bu.url_id) = 1';
		if ($preferredBankAccountId > 0) {
			$sql .= ' ORDER BY CASE WHEN b.fk_account = ' . $preferredBankAccountId . ' THEN 0 ELSE 1 END ASC, line_date DESC, b.rowid DESC';
		} else {
			$sql .= ' ORDER BY line_date DESC, b.rowid DESC';
		}
		$sql .= $this->db->plimit(1800, 0);

		$resql = $this->db->query($sql);
		if (!$resql) {
			return array(
				'samples' => array(),
				'label_names' => array(),
			);
		}

		$samples = array();
		$labelNames = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$idsRaw = trim((string) (!empty($obj->supplier_ids) ? $obj->supplier_ids : ''));
			if ($idsRaw === '') {
				continue;
			}

			$idParts = explode(',', $idsRaw);
			$supplierId = !empty($idParts[0]) ? (int) $idParts[0] : 0;
			if ($supplierId <= 0) {
				continue;
			}

			$line = array(
				'rowid' => (int) $obj->bank_line_id,
				'bank_account_id' => (int) $obj->bank_account_id,
				'amount' => (float) $obj->amount,
				'allocated_amount' => 0.0,
				'counterparty_name' => (string) (!empty($obj->counterparty_name) ? $obj->counterparty_name : ''),
				'counterparty_iban' => (string) (!empty($obj->counterparty_iban) ? $obj->counterparty_iban : ''),
				'description' => (string) (!empty($obj->description) ? $obj->description : ''),
				'payment_reference' => (string) (!empty($obj->payment_reference) ? $obj->payment_reference : ''),
				'bank_reference' => (string) (!empty($obj->bank_reference) ? $obj->bank_reference : ''),
			);
			$features = $this->buildSupplierMlFeatureVector($line);
			if (count($features) < $expectedFeatureDimension) {
				continue;
			}

			$samples[] = array(
				'features' => $features,
				'label' => $supplierId,
			);
			if (!isset($labelNames[$supplierId])) {
				$supplierLabel = (string) (!empty($obj->supplier_names) ? $obj->supplier_names : '');
				$supplierLabel = $this->decodeHtmlEntitiesRecursive($supplierLabel);
				$labelNames[$supplierId] = $supplierLabel;
			}
		}

		return array(
			'samples' => $samples,
			'label_names' => $labelNames,
		);
	}

	/**
	 * Estimate supplier prediction confidence from weighted nearest neighbors.
	 *
	 * @param array<int,float> $vector
	 * @param array<int,array<string,mixed>> $samples
	 * @param int $k
	 * @return array<string,mixed>
	 */
	protected function estimateSupplierMlPrediction($vector, $samples, $k = 0)
	{
		$result = array(
			'predicted_socid' => 0,
			'probability' => 0.0,
			'score_total' => 0.0,
			'votes' => array(),
		);

		if (empty($samples) || !is_array($samples)) {
			return $result;
		}
		$expectedFeatureDimension = $this->getSupplierMlFeatureDimension();

		$distances = array();
		foreach ($samples as $sample) {
			if (!is_array($sample) || empty($sample['features']) || empty($sample['label'])) {
				continue;
			}
			$features = is_array($sample['features']) ? (array) $sample['features'] : array();
			if (count($features) < $expectedFeatureDimension) {
				continue;
			}

			$distances[] = array(
				'label' => (int) $sample['label'],
				'distance' => $this->batchMlDistance($vector, $features),
			);
		}

		if (empty($distances)) {
			return $result;
		}

		usort($distances, static function ($a, $b) {
			$da = (float) (isset($a['distance']) ? $a['distance'] : 0.0);
			$db = (float) (isset($b['distance']) ? $b['distance'] : 0.0);
			if ($da === $db) {
				return 0;
			}

			return ($da < $db ? -1 : 1);
		});

		if ((int) $k <= 0) {
			$k = $this->resolveAdaptiveNeighborCount(count($distances), 3, 31);
		}
		$k = max(1, min((int) $k, count($distances)));
		$votes = array();
		$scoreTotal = 0.0;
		for ($i = 0; $i < $k; $i++) {
			$row = $distances[$i];
			$label = (int) $row['label'];
			if ($label <= 0) {
				continue;
			}
			$distance = max(0.0, (float) $row['distance']);
			$weight = 1.0 / (1.0 + $distance);
			if (empty($votes[$label])) {
				$votes[$label] = 0.0;
			}
			$votes[$label] += $weight;
			$scoreTotal += $weight;
		}
		if ($scoreTotal <= 0.0 || empty($votes)) {
			return $result;
		}

		arsort($votes, SORT_NUMERIC);
		$predictedSocid = (int) key($votes);
		$topScore = (float) current($votes);
		$probability = max(0.0, min(1.0, ($topScore / $scoreTotal)));

		$result['predicted_socid'] = $predictedSocid;
		$result['probability'] = $probability;
		$result['score_total'] = $scoreTotal;
		$result['votes'] = $votes;

		return $result;
	}

	/**
	 * Query supplier rows for current entity by a safe SQL condition.
	 *
	 * @param string $whereSql
	 * @param string $orderBy
	 * @param int $limit
	 * @return array<int,array<string,mixed>>
	 */
	protected function querySupplierRowsByWhere($whereSql, $orderBy = 's.nom ASC', $limit = 10)
	{
		$whereSql = trim((string) $whereSql);
		if ($whereSql === '') {
			return array();
		}

		$limit = max(1, min(100, (int) $limit));
		$orderBy = trim((string) $orderBy);
		if ($orderBy === '') {
			$orderBy = 's.nom ASC';
		}

		$sql = 'SELECT s.rowid, s.nom, s.tva_intra';
		$sql .= ' FROM ' . $this->db->prefix() . 'societe as s';
		$sql .= ' WHERE s.entity = ' . ((int) $this->entity);
		$sql .= ' AND s.fournisseur > 0';
		$sql .= ' AND ' . $whereSql;
		$sql .= ' ORDER BY ' . $orderBy;
		$sql .= $this->db->plimit($limit, 0);

		$resql = $this->db->query($sql);
		if (!$resql) {
			return array();
		}

		$rows = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$rows[] = array(
				'id' => (int) (!empty($obj->rowid) ? $obj->rowid : 0),
				'name' => (string) (!empty($obj->nom) ? $obj->nom : ''),
				'vat' => (string) (!empty($obj->tva_intra) ? $obj->tva_intra : ''),
			);
		}

		return $rows;
	}

	/**
	 * Fetch supplier summary by id and validate entity/supplier role.
	 *
	 * @param int $supplierId
	 * @return array<string,mixed>
	 */
	protected function fetchSupplierSummaryById($supplierId)
	{
		require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';

		$supplierId = (int) $supplierId;
		if ($supplierId <= 0) {
			return array();
		}

		$thirdparty = new Societe($this->db);
		if ($thirdparty->fetch($supplierId) <= 0) {
			return array();
		}
		if ((int) $thirdparty->entity !== (int) $this->entity) {
			return array();
		}
		if ((int) $thirdparty->fournisseur <= 0) {
			return array();
		}

		return array(
			'id' => (int) $thirdparty->id,
			'name' => (string) $thirdparty->name,
			'vat' => (string) (!empty($thirdparty->tva_intra) ? $thirdparty->tva_intra : ''),
		);
	}

	/**
	 * Persist learned recurring patterns from validated reconciliation.
	 *
	 * @param array<string,mixed> $line
	 * @param array<int,array<string,mixed>> $links
	 * @return void
	 */
	protected function learnPatternsFromReconciliation($line, $links)
	{
		if (empty($links[0])) {
			return;
		}
		$primary = $links[0];
		$docType = (string) $primary['doc_type'];
		$docId = (int) $primary['fk_doc'];
		$docRef = !empty($primary['doc_ref']) ? (string) $primary['doc_ref'] : $this->fetchDocumentRef($docType, $docId);
		$score = !empty($primary['match_score']) ? (int) $primary['match_score'] : 0;

		$lineIban = strtoupper(preg_replace('/\s+/', '', (string) ($line['counterparty_iban'] ?? '')));
		if ($lineIban !== '' && $this->isLikelyIban($lineIban)) {
			$this->upsertPattern('iban', $lineIban, $docType, $docId, $docRef, $score);
		}

		$lineDesc = kreabankNormalizeText((string) ($line['description'] ?? ''));
		if ($lineDesc !== '' && strlen($lineDesc) >= 8) {
			$lineDesc = substr($lineDesc, 0, 120);
			$this->upsertPattern('description', $lineDesc, $docType, $docId, $docRef, $score);
		}
	}

	/**
	 * Upsert pattern row.
	 *
	 * @param string $type
	 * @param string $value
	 * @param string $docType
	 * @param int $docId
	 * @param string $docRef
	 * @param int $score
	 * @return void
	 */
	protected function upsertPattern($type, $value, $docType, $docId, $docRef, $score = 0)
	{
		$this->ensurePatternTable();

		$check = 'SELECT rowid, hit_count FROM ' . $this->db->prefix() . 'kreabank_pattern';
		$check .= ' WHERE entity = ' . ((int) $this->entity);
		$check .= ' AND pattern_type = ' . $this->sqlText($type);
		$check .= ' AND pattern_value = ' . $this->sqlText($value);
		$check .= ' AND doc_type = ' . $this->sqlText($docType);
		$check .= ' AND fk_doc = ' . ((int) $docId);
		$check .= $this->db->plimit(1, 0);
		$res = $this->db->query($check);

		$now = dol_now();
		if ($res && ($obj = $this->db->fetch_object($res))) {
			$upd = 'UPDATE ' . $this->db->prefix() . 'kreabank_pattern SET';
			$upd .= ' hit_count = ' . (((int) $obj->hit_count) + 1);
			$upd .= ', last_score = ' . ((int) $score);
			$upd .= ', last_used = ' . $this->sqlDateTime($now);
			$upd .= ', doc_ref = ' . $this->sqlText($docRef);
			$upd .= ' WHERE rowid = ' . ((int) $obj->rowid);
			$this->db->query($upd);
		} else {
			$ins = 'INSERT INTO ' . $this->db->prefix() . 'kreabank_pattern (';
			$ins .= 'entity, pattern_type, pattern_value, doc_type, fk_doc, doc_ref, hit_count, last_score, last_used, datec';
			$ins .= ') VALUES (';
			$ins .= $this->entity;
			$ins .= ', ' . $this->sqlText($type);
			$ins .= ', ' . $this->sqlText($value);
			$ins .= ', ' . $this->sqlText($docType);
			$ins .= ', ' . ((int) $docId);
			$ins .= ', ' . $this->sqlText($docRef);
			$ins .= ', 1';
			$ins .= ', ' . ((int) $score);
			$ins .= ', ' . $this->sqlDateTime($now);
			$ins .= ', ' . $this->sqlDateTime($now);
			$ins .= ')';
			$this->db->query($ins);
		}
	}

	/**
	 * Validate if token looks like an IBAN.
	 *
	 * @param string $value
	 * @return bool
	 */
	protected function isLikelyIban($value)
	{
		$value = strtoupper((string) preg_replace('/\s+/', '', (string) $value));
		if ($value === '') {
			return false;
		}

		return (bool) preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}$/', $value);
	}

	/**
	 * Persist user-approved header mappings as ML training samples.
	 *
	 * @param array<string,mixed> $mapping
	 * @param array<string,mixed> $templateMeta
	 * @return void
	 */
	protected function persistTemplateMlSamples($mapping, $templateMeta)
	{
		if (!defined('DOL_DATA_ROOT') || DOL_DATA_ROOT === '') {
			return;
		}
		if (empty($mapping) || !is_array($mapping)) {
			return;
		}

		$columns = !empty($templateMeta['columns']) && is_array($templateMeta['columns']) ? (array) $templateMeta['columns'] : array();
		if (empty($columns)) {
			return;
		}

		$fieldKeys = array(
			'operation_date',
			'value_date',
			'amount',
			'debit',
			'credit',
			'running_balance',
			'description',
			'payment_reference',
			'bank_reference',
			'counterparty_iban',
			'counterparty_name',
			'currency',
		);

		$newSamples = array();
		foreach ($fieldKeys as $fieldKey) {
			$idx = isset($mapping[$fieldKey]) ? (int) $mapping[$fieldKey] : -1;
			if ($idx < 0 || !isset($columns[$idx]) || !is_array($columns[$idx])) {
				continue;
			}
			$label = isset($columns[$idx]['label']) ? trim((string) $columns[$idx]['label']) : '';
			if ($label === '') {
				continue;
			}
			$newSamples[] = array(
				'field' => $fieldKey,
				'header' => $label,
			);
		}
		if (empty($newSamples)) {
			return;
		}

		$path = $this->getMlTemplateStorePath();
		if ($path === '') {
			return;
		}
		$dir = dirname($path);
		if (!is_dir($dir)) {
			dol_mkdir($dir);
		}

		$existing = array();
		if (is_readable($path)) {
			$raw = file_get_contents($path);
			if (is_string($raw) && $raw !== '') {
				$decoded = $this->decodeJsonArraySafe($raw);
				if ($decoded !== null) {
					$existing = $decoded;
				}
			} else {
				dol_syslog('KreaBank failed to read ML template sample store: ' . $path, LOG_WARNING);
			}
		} elseif (file_exists($path)) {
			dol_syslog('KreaBank ML template sample store is not readable: ' . $path, LOG_WARNING);
		}

		$dedup = array();
		$merged = array();
		foreach (array_merge($existing, $newSamples) as $sample) {
			if (!is_array($sample)) {
				continue;
			}
			$field = isset($sample['field']) ? trim((string) $sample['field']) : '';
			$header = isset($sample['header']) ? trim((string) $sample['header']) : '';
			if ($field === '' || $header === '') {
				continue;
			}
			$key = $field . '|' . strtolower($header);
			if (isset($dedup[$key])) {
				continue;
			}
			$dedup[$key] = 1;
			$merged[] = array('field' => $field, 'header' => $header);
		}

		if (count($merged) > 4000) {
			$merged = array_slice($merged, count($merged) - 4000);
		}

		$writeResult = file_put_contents($path, json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
		if ($writeResult === false) {
			dol_syslog('KreaBank failed to write ML template sample store: ' . $path, LOG_WARNING);
		}
	}

	/**
	 * Get on-disk path for template ML sample storage.
	 *
	 * @return string
	 */
	protected function getMlTemplateStorePath()
	{
		if (!defined('DOL_DATA_ROOT') || DOL_DATA_ROOT === '') {
			return '';
		}

		return rtrim((string) DOL_DATA_ROOT, '/') . '/kreabank/header_templates_entity' . $this->entity . '.json';
	}

	/**
	 * Retrieve best profile for a layout fingerprint.
	 *
	 * @param int $bankAccountId
	 * @param string $sourceType
	 * @param string $fingerprint
	 * @param string $layoutSignature
	 * @return array<string,mixed>|null
	 */
	protected function getBestImportProfile($bankAccountId, $sourceType, $fingerprint, $layoutSignature = '')
	{
		$this->ensureImportProfileTable();
		if ($fingerprint === '' || $sourceType === '') {
			return null;
		}

		$table = $this->db->prefix() . 'kreabank_import_profile';
		$bankScope = '';
		if ((int) $bankAccountId > 0) {
			$bankScope = ' AND bank_account_id IN (0, ' . ((int) $bankAccountId) . ')';
		} else {
			$bankScope = ' AND bank_account_id = 0';
		}
		$orderBy = ((int) $bankAccountId > 0)
			? ' ORDER BY CASE WHEN bank_account_id = ' . ((int) $bankAccountId) . ' THEN 0 ELSE 1 END, rowid DESC'
			: ' ORDER BY rowid DESC';

		$sql = 'SELECT rowid, bank_account_id, source_type, fingerprint, layout_signature, label, header_row, mapping_json, template_json';
		$sql .= ' FROM ' . $table;
		$sql .= ' WHERE entity = ' . ((int) $this->entity);
		$sql .= ' AND source_type = ' . $this->sqlText($sourceType);
		$sql .= ' AND fingerprint = ' . $this->sqlText($fingerprint);
		$sql .= $bankScope;
		$sql .= $orderBy;
		$sql .= $this->db->plimit(1, 0);

		$resql = $this->db->query($sql);
		if (!$resql) {
			return null;
		}
		$obj = $this->db->fetch_object($resql);

		if (!$obj && $layoutSignature !== '') {
			$sql2 = 'SELECT rowid, bank_account_id, source_type, fingerprint, layout_signature, label, header_row, mapping_json, template_json';
			$sql2 .= ' FROM ' . $table;
			$sql2 .= ' WHERE entity = ' . ((int) $this->entity);
			$sql2 .= ' AND source_type = ' . $this->sqlText($sourceType);
			$sql2 .= ' AND layout_signature = ' . $this->sqlText($layoutSignature);
			$sql2 .= $bankScope;
			$sql2 .= $orderBy;
			$sql2 .= $this->db->plimit(1, 0);
			$resql2 = $this->db->query($sql2);
			if ($resql2) {
				$obj = $this->db->fetch_object($resql2);
			}
		}

		if (!$obj) {
			return null;
		}

		$mapping = $this->decodeJsonArraySafe((string) $obj->mapping_json);
		if ($mapping === null) {
			return null;
		}
		if (!isset($mapping['header_row'])) {
			$mapping['header_row'] = (int) $obj->header_row;
		}

		return array(
			'rowid' => (int) $obj->rowid,
			'bank_account_id' => (int) $obj->bank_account_id,
			'source_type' => (string) $obj->source_type,
			'fingerprint' => (string) $obj->fingerprint,
			'layout_signature' => (string) $obj->layout_signature,
			'label' => (string) $obj->label,
			'header_row' => (int) $obj->header_row,
			'template_json' => (string) $obj->template_json,
			'mapping' => $mapping,
		);
	}

	/**
	 * Ensure import profile table exists for current database.
	 *
	 * @return void
	 */
	protected function ensureImportProfileTable()
	{
		if ($this->importProfileTableChecked) {
			return;
		}
		$this->importProfileTableChecked = true;

		$table = $this->db->prefix() . 'kreabank_import_profile';
		$sql = 'CREATE TABLE IF NOT EXISTS ' . $table . ' (';
		$sql .= ' rowid INTEGER AUTO_INCREMENT PRIMARY KEY,';
		$sql .= ' entity INTEGER NOT NULL DEFAULT 1,';
		$sql .= ' bank_account_id INTEGER NOT NULL DEFAULT 0,';
		$sql .= ' source_type VARCHAR(16) NOT NULL,';
		$sql .= ' fingerprint CHAR(64) NOT NULL,';
		$sql .= ' layout_signature CHAR(64) NULL,';
		$sql .= ' label VARCHAR(190) NOT NULL,';
		$sql .= ' header_row INTEGER NOT NULL DEFAULT -1,';
		$sql .= ' mapping_json LONGTEXT NOT NULL,';
		$sql .= ' template_json LONGTEXT NULL,';
		$sql .= ' datec DATETIME NOT NULL,';
		$sql .= ' tms TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,';
		$sql .= ' UNIQUE KEY uk_kreabank_import_profile (entity, bank_account_id, source_type, fingerprint),';
		$sql .= ' KEY idx_kreabank_import_profile_layout (entity, bank_account_id, source_type, layout_signature)';
		$sql .= ' ) ENGINE=innodb';
		$this->execSchemaSql($sql, 'create import_profile');

		// Keep old installs forward-compatible without dedicated migration step.
		if (!$this->tableHasColumn($table, 'layout_signature')) {
			$this->execSchemaSql('ALTER TABLE ' . $table . ' ADD COLUMN layout_signature CHAR(64) NULL', 'add import_profile.layout_signature');
		}
		if (!$this->tableHasColumn($table, 'template_json')) {
			$this->execSchemaSql('ALTER TABLE ' . $table . ' ADD COLUMN template_json LONGTEXT NULL', 'add import_profile.template_json');
		}
		if (!$this->tableHasIndex($table, 'idx_kreabank_import_profile_layout')) {
			$this->execSchemaSql('ALTER TABLE ' . $table . ' ADD INDEX idx_kreabank_import_profile_layout (entity, bank_account_id, source_type, layout_signature)', 'add import_profile.idx_layout');
		}
	}

	/**
	 * Ensure quick entry table exists for quick reconciliation flows.
	 *
	 * @return void
	 */
	protected function ensureQuickEntryTable()
	{
		if ($this->quickEntryTableChecked) {
			return;
		}
		$this->quickEntryTableChecked = true;

		$table = $this->db->prefix() . 'kreabank_quick_entry';
		$sql = 'CREATE TABLE IF NOT EXISTS ' . $table . ' (';
		$sql .= ' rowid INTEGER AUTO_INCREMENT PRIMARY KEY,';
		$sql .= ' entity INTEGER NOT NULL DEFAULT 1,';
		$sql .= ' fk_statement_line INTEGER NOT NULL,';
		$sql .= ' entry_type VARCHAR(32) NOT NULL,';
		$sql .= ' label VARCHAR(255) NOT NULL,';
		$sql .= ' amount DECIMAL(24,8) NOT NULL,';
		$sql .= " currency VARCHAR(3) NOT NULL DEFAULT 'EUR',";
		$sql .= ' fk_soc INTEGER NULL,';
		$sql .= ' status SMALLINT NOT NULL DEFAULT 0,';
		$sql .= ' doc_type VARCHAR(32) NULL,';
		$sql .= ' fk_doc INTEGER NULL,';
		$sql .= ' note TEXT NULL,';
		$sql .= ' fk_user_author INTEGER NOT NULL,';
		$sql .= ' datec DATETIME NOT NULL,';
		$sql .= ' tms TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,';
		$sql .= ' KEY idx_kreabank_quick_line (fk_statement_line),';
		$sql .= ' KEY idx_kreabank_quick_status (entity, status)';
		$sql .= ' ) ENGINE=innodb';
		$this->execSchemaSql($sql, 'create quick_entry');

		if (!$this->tableHasColumn($table, 'doc_type')) {
			$this->execSchemaSql('ALTER TABLE ' . $table . ' ADD COLUMN doc_type VARCHAR(32) NULL', 'add quick_entry.doc_type');
		}
		if (!$this->tableHasColumn($table, 'fk_doc')) {
			$this->execSchemaSql('ALTER TABLE ' . $table . ' ADD COLUMN fk_doc INTEGER NULL', 'add quick_entry.fk_doc');
		}
		if (!$this->tableHasIndex($table, 'idx_kreabank_quick_line')) {
			$this->execSchemaSql('ALTER TABLE ' . $table . ' ADD INDEX idx_kreabank_quick_line (fk_statement_line)', 'add quick_entry.idx_line');
		}
		if (!$this->tableHasIndex($table, 'idx_kreabank_quick_status')) {
			$this->execSchemaSql('ALTER TABLE ' . $table . ' ADD INDEX idx_kreabank_quick_status (entity, status)', 'add quick_entry.idx_status');
		}
	}

	/**
	 * Ensure recurring pattern table exists.
	 *
	 * @return void
	 */
	protected function ensurePatternTable()
	{
		if ($this->patternTableChecked) {
			return;
		}
		$this->patternTableChecked = true;

		$table = $this->db->prefix() . 'kreabank_pattern';
		$sql = 'CREATE TABLE IF NOT EXISTS ' . $table . ' (';
		$sql .= ' rowid INTEGER AUTO_INCREMENT PRIMARY KEY,';
		$sql .= ' entity INTEGER NOT NULL DEFAULT 1,';
		$sql .= ' pattern_type VARCHAR(16) NOT NULL,';
		$sql .= ' pattern_value VARCHAR(255) NOT NULL,';
		$sql .= ' doc_type VARCHAR(32) NOT NULL,';
		$sql .= ' fk_doc INTEGER NOT NULL,';
		$sql .= ' doc_ref VARCHAR(128) NULL,';
		$sql .= ' fk_soc INTEGER NULL,';
		$sql .= ' hit_count INTEGER NOT NULL DEFAULT 0,';
		$sql .= ' last_score INTEGER NOT NULL DEFAULT 0,';
		$sql .= ' last_used DATETIME NULL,';
		$sql .= ' datec DATETIME NOT NULL,';
		$sql .= ' tms TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,';
		$sql .= ' UNIQUE KEY uk_kreabank_pattern (entity, pattern_type, pattern_value, doc_type, fk_doc),';
		$sql .= ' KEY idx_kreabank_pattern_soc (fk_soc)';
		$sql .= ' ) ENGINE=innodb';
		$this->execSchemaSql($sql, 'create pattern');

		if (!$this->tableHasColumn($table, 'fk_soc')) {
			$this->execSchemaSql('ALTER TABLE ' . $table . ' ADD COLUMN fk_soc INTEGER NULL', 'add pattern.fk_soc');
		}
		if (!$this->tableHasIndex($table, 'uk_kreabank_pattern')) {
			$this->execSchemaSql('ALTER TABLE ' . $table . ' ADD UNIQUE INDEX uk_kreabank_pattern (entity, pattern_type, pattern_value, doc_type, fk_doc)', 'add pattern.uk');
		}
		if (!$this->tableHasIndex($table, 'idx_kreabank_pattern_soc')) {
			$this->execSchemaSql('ALTER TABLE ' . $table . ' ADD INDEX idx_kreabank_pattern_soc (fk_soc)', 'add pattern.idx_soc');
		}
	}

	/**
	 * Ensure reconciliation audit table exists using kreabank-prefixed name.
	 *
	 * @return string
	 */
	protected function ensureReconAuditTable()
	{
		$table = $this->db->prefix() . 'kreabank_recon_audit';
		if ($this->reconAuditTableChecked) {
			return $table;
		}

		$legacyTable = $this->db->prefix() . 'recon_audit';
		if (!$this->tableExists($table) && $this->tableExists($legacyTable)) {
			$this->execSchemaSql('RENAME TABLE ' . $legacyTable . ' TO ' . $table, 'rename recon_audit');
		}

		$sql = 'CREATE TABLE IF NOT EXISTS ' . $table . ' (';
		$sql .= ' rowid INTEGER AUTO_INCREMENT PRIMARY KEY,';
		$sql .= ' entity INTEGER NOT NULL DEFAULT 1,';
		$sql .= ' audit_type VARCHAR(32) NOT NULL,';
		$sql .= ' fk_statement_line INTEGER NULL,';
		$sql .= ' fk_reconciliation INTEGER NULL,';
		$sql .= ' payload_json LONGTEXT NULL,';
		$sql .= ' fk_user INTEGER NULL,';
		$sql .= ' ip_address VARCHAR(64) NULL,';
		$sql .= ' datec DATETIME NOT NULL,';
		$sql .= ' tms TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,';
		$sql .= ' KEY idx_kreabank_recon_audit_type (entity, audit_type),';
		$sql .= ' KEY idx_kreabank_recon_audit_date (datec),';
		$sql .= ' KEY idx_kreabank_recon_audit_line (fk_statement_line)';
		$sql .= ' ) ENGINE=innodb';
		$this->execSchemaSql($sql, 'create recon_audit');

		$this->reconAuditTableChecked = true;

		return $table;
	}

	/**
	 * Ensure batch ML sample table exists.
	 *
	 * @return string
	 */
	protected function ensureBatchMlSampleTable()
	{
		$table = $this->db->prefix() . 'kreabank_ml_sample';
		if ($this->batchMlSampleTableChecked) {
			return $table;
		}

		$sql = 'CREATE TABLE IF NOT EXISTS ' . $table . ' (';
		$sql .= ' rowid INTEGER AUTO_INCREMENT PRIMARY KEY,';
		$sql .= ' entity INTEGER NOT NULL DEFAULT 1,';
		$sql .= ' label SMALLINT NOT NULL DEFAULT 0,';
		$sql .= ' features_json LONGTEXT NOT NULL,';
		$sql .= ' datec DATETIME NOT NULL,';
		$sql .= ' tms TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,';
		$sql .= ' KEY idx_kreabank_ml_sample_entity (entity, rowid),';
		$sql .= ' KEY idx_kreabank_ml_sample_label (entity, label)';
		$sql .= ' ) ENGINE=innodb';
		$this->execSchemaSql($sql, 'create ml_sample');

		if (!$this->tableHasIndex($table, 'idx_kreabank_ml_sample_entity')) {
			$this->execSchemaSql('ALTER TABLE ' . $table . ' ADD INDEX idx_kreabank_ml_sample_entity (entity, rowid)', 'add ml_sample.idx_entity');
		}
		if (!$this->tableHasIndex($table, 'idx_kreabank_ml_sample_label')) {
			$this->execSchemaSql('ALTER TABLE ' . $table . ' ADD INDEX idx_kreabank_ml_sample_label (entity, label)', 'add ml_sample.idx_label');
		}

		$this->batchMlSampleTableChecked = true;

		return $table;
	}

	/**
	 * Check if a table exists in current database.
	 *
	 * @param string $tableName
	 * @return bool
	 */
	protected function tableExists($tableName)
	{
		$resql = $this->db->query("SHOW TABLES LIKE '" . $this->db->escape((string) $tableName) . "'");
		return (bool) ($resql && $this->db->num_rows($resql) > 0);
	}

	/**
	 * Check if a table contains a column.
	 *
	 * @param string $tableName
	 * @param string $columnName
	 * @return bool
	 */
	protected function tableHasColumn($tableName, $columnName)
	{
		$resql = $this->db->query("SHOW COLUMNS FROM " . $tableName . " LIKE '" . $this->db->escape((string) $columnName) . "'");
		return (bool) ($resql && $this->db->num_rows($resql) > 0);
	}

	/**
	 * Check if a table contains an index.
	 *
	 * @param string $tableName
	 * @param string $indexName
	 * @return bool
	 */
	protected function tableHasIndex($tableName, $indexName)
	{
		$sql = "SHOW INDEX FROM " . $tableName . " WHERE Key_name = '" . $this->db->escape((string) $indexName) . "'";
		$resql = $this->db->query($sql);
		return (bool) ($resql && $this->db->num_rows($resql) > 0);
	}

	/**
	 * Execute one schema query and log failures.
	 *
	 * @param string $sql
	 * @param string $context
	 * @return bool
	 */
	protected function execSchemaSql($sql, $context = '')
	{
		$resql = $this->db->query((string) $sql);
		if (!$resql && function_exists('dol_syslog')) {
			$context = trim((string) $context);
			$msg = 'KreaBank schema sync failed';
			if ($context !== '') {
				$msg .= ' (' . $context . ')';
			}
			$msg .= ': ' . $this->db->lasterror();
			dol_syslog($msg, LOG_WARNING);
		}

		return (bool) $resql;
	}

	/**
	 * Persist one audit row.
	 *
	 * @param string $type
	 * @param int|null $lineId
	 * @param int|null $reconciliationId
	 * @param array<string,mixed> $payload
	 * @return void
	 */
	protected function logAudit($type, $lineId = null, $reconciliationId = null, $payload = array())
	{
		$auditTable = $this->ensureReconAuditTable();

		$ip = '';
		if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
			$ip = (string) $_SERVER['HTTP_X_FORWARDED_FOR'];
		} elseif (!empty($_SERVER['REMOTE_ADDR'])) {
			$ip = (string) $_SERVER['REMOTE_ADDR'];
		}
		$now = dol_now();

		$sql = 'INSERT INTO ' . $auditTable . ' (';
		$sql .= 'entity, audit_type, fk_statement_line, fk_reconciliation, payload_json, fk_user, ip_address, datec';
		$sql .= ') VALUES (';
		$sql .= $this->entity;
		$sql .= ', ' . $this->sqlText($type);
		$sql .= ', ' . ($lineId ? (int) $lineId : 'NULL');
		$sql .= ', ' . ($reconciliationId ? (int) $reconciliationId : 'NULL');
		$sql .= ', ' . $this->sqlText(json_encode($payload));
		$sql .= ', ' . ((int) $this->user->id > 0 ? (int) $this->user->id : 'NULL');
		$sql .= ', ' . $this->sqlText($ip);
		$sql .= ', ' . $this->sqlDateTime($now);
		$sql .= ')';

		$this->db->query($sql);
	}

	/**
	 * Resolve native bank line id from one statement/native line.
	 *
	 * @param array<string,mixed> $line
	 * @param bool $createIfMissing
	 * @return int
	 */
	protected function resolveNativeLineIdFromStatementLine($line, $createIfMissing = false)
	{
		$nativeLineId = !empty($line['native_bank_line_id']) ? (int) $line['native_bank_line_id'] : 0;
		if ($nativeLineId > 0) {
			return $nativeLineId;
		}

		$isStagedLine = !empty($line['fk_statement']);
		if ($isStagedLine && $createIfMissing) {
			return (int) $this->native->ensureNativeLineForStatementLine((int) $line['rowid']);
		}

		if (!$isStagedLine) {
			return (int) $line['rowid'];
		}

		return 0;
	}

	/**
	 * Build line context expected by native attachment helpers.
	 *
	 * @param array<string,mixed> $line
	 * @param int $nativeLineId
	 * @return array<string,mixed>
	 */
	protected function buildNativeLineContext($line, $nativeLineId)
	{
		$nativeLine = (array) $line;
		$nativeLine['native_bank_line_id'] = (int) $nativeLineId;
		$nativeLine['rowid'] = (int) $nativeLineId;

		return $nativeLine;
	}

	/**
	 * Return the selected native-bank reconciliation link, if any.
	 *
	 * @param array<int,array<string,mixed>> $links
	 * @return array<string,mixed>|null
	 */
	protected function findSelectedNativeBankLink($links)
	{
		foreach ((array) $links as $link) {
			if (!is_array($link)) {
				continue;
			}
			$docType = trim((string) (!empty($link['doc_type']) ? $link['doc_type'] : ''));
			$docId = (int) (!empty($link['fk_doc']) ? $link['fk_doc'] : 0);
			if ($docType !== 'native_bank' || $docId <= 0) {
				continue;
			}

			return $link;
		}

		return null;
	}

	/**
	 * Return the selected linked-payment reconciliation link, if any.
	 *
	 * @param array<int,array<string,mixed>> $links
	 * @return array<string,mixed>|null
	 */
	protected function findSelectedLinkedPaymentBankLink($links)
	{
		foreach ((array) $links as $link) {
			if (!is_array($link)) {
				continue;
			}
			$docType = trim((string) (!empty($link['doc_type']) ? $link['doc_type'] : ''));
			$docId = (int) (!empty($link['fk_doc']) ? $link['fk_doc'] : 0);
			if (($docType !== 'payment_linked' && $docType !== 'payment_supplier_linked') || $docId <= 0) {
				continue;
			}

			return $link;
		}

		return null;
	}

	/**
	 * Normalize reconciliation doc type aliases.
	 *
	 * @param string $docType
	 * @return string
	 */
	protected function normalizeReconciliationDocType($docType)
	{
		$docType = trim((string) $docType);
		if ($docType === 'payment_linked') {
			return 'payment';
		}
		if ($docType === 'payment_supplier_linked') {
			return 'payment_supplier';
		}

		return $docType;
	}

	/**
	 * Check whether document type is compatible with bank line direction.
	 *
	 * @param string $docType
	 * @param int $lineDirection
	 * @return bool
	 */
	protected function isDocTypeCompatibleWithLineDirection($docType, $lineDirection)
	{
		$docType = trim((string) $docType);
		$lineDirection = (int) $lineDirection;

		if ($docType === 'quick_entry') {
			return true;
		}
		if ($docType === 'native_bank') {
			return true;
		}
		if ($docType === 'payment_linked' || $docType === 'payment_supplier_linked') {
			return true;
		}

		if ($lineDirection > 0) {
			return in_array($docType, array('payment', 'customer_invoice'), true);
		}

		if ($lineDirection < 0) {
			return in_array($docType, array('payment_supplier', 'supplier_invoice'), true);
		}

		return in_array($docType, array('native_bank', 'payment', 'payment_supplier', 'customer_invoice', 'supplier_invoice'), true);
	}

	/**
	 * Fetch a document reference by type/id.
	 *
	 * @param string $docType
	 * @param int $docId
	 * @return string
	 */
	protected function fetchDocumentRef($docType, $docId)
	{
		if ($docId <= 0) {
			return '';
		}

		if ($docType === 'customer_invoice') {
			$sql = 'SELECT ref FROM ' . $this->db->prefix() . 'facture WHERE rowid = ' . ((int) $docId) . ' AND entity = ' . ((int) $this->entity);
			$resql = $this->db->query($sql);
			if ($resql && ($obj = $this->db->fetch_object($resql))) {
				return (string) $obj->ref;
			}
		}

		if ($docType === 'supplier_invoice') {
			$sql = 'SELECT ref FROM ' . $this->db->prefix() . 'facture_fourn WHERE rowid = ' . ((int) $docId) . ' AND entity = ' . ((int) $this->entity);
			$resql = $this->db->query($sql);
			if ($resql && ($obj = $this->db->fetch_object($resql))) {
				return (string) $obj->ref;
			}
		}

		if ($docType === 'payment') {
			$sql = 'SELECT ref, num_paiement FROM ' . $this->db->prefix() . 'paiement WHERE rowid = ' . ((int) $docId) . ' AND entity = ' . ((int) $this->entity);
			$resql = $this->db->query($sql);
			if ($resql && ($obj = $this->db->fetch_object($resql))) {
				$ref = trim((string) (!empty($obj->ref) ? $obj->ref : $obj->num_paiement));
				if ($ref !== '') {
					return $ref;
				}
			}
			return 'PAY-' . ((int) $docId);
		}

		if ($docType === 'payment_supplier') {
			$sql = 'SELECT ref, num_paiement FROM ' . $this->db->prefix() . 'paiementfourn WHERE rowid = ' . ((int) $docId) . ' AND entity = ' . ((int) $this->entity);
			$resql = $this->db->query($sql);
			if ($resql && ($obj = $this->db->fetch_object($resql))) {
				$ref = trim((string) (!empty($obj->ref) ? $obj->ref : $obj->num_paiement));
				if ($ref !== '') {
					return $ref;
				}
			}
			return 'SPAY-' . ((int) $docId);
		}

		if ($docType === 'quick_entry') {
			return 'QE' . str_pad((string) $docId, 8, '0', STR_PAD_LEFT);
		}

		if ($docType === 'native_bank') {
			$line = $this->getLineById((int) $docId);
			if (!$line) {
				return 'BANK-' . ((int) $docId);
			}

			return $this->resolveNativeBankLineRef($line);
		}

		return $docType . '#' . $docId;
	}

	/**
	 * Validate an existing native bank line selected for reconciliation.
	 *
	 * @param array<string,mixed> $statementLine
	 * @param int $sourceLineId
	 * @param float $allocatedAmount
	 * @return array<string,mixed>
	 */
	protected function resolveExistingNativeBankLineForReconciliation($statementLine, $sourceLineId, $allocatedAmount)
	{
		$sourceLineId = (int) $sourceLineId;
		if ($sourceLineId <= 0) {
			throw new Exception('Source native bank line id is invalid');
		}
		$targetNativeLineId = !empty($statementLine['fk_statement'])
			? (int) (!empty($statementLine['native_bank_line_id']) ? $statementLine['native_bank_line_id'] : 0)
			: (int) (!empty($statementLine['rowid']) ? $statementLine['rowid'] : 0);
		if ($targetNativeLineId > 0 && $sourceLineId === $targetNativeLineId) {
			throw new Exception('Source and target bank lines must be different');
		}

		$sourceLine = $this->getLineById($sourceLineId);
		if (!$sourceLine) {
			throw new Exception('Source native bank line not found #' . $sourceLineId);
		}
		if ((int) $sourceLine['status'] === 2) {
			throw new Exception('Source native bank line already reconciled #' . $sourceLineId);
		}
		$targetBankAccountId = (int) (!empty($statementLine['bank_account_id']) ? $statementLine['bank_account_id'] : 0);
		$sourceBankAccountId = (int) (!empty($sourceLine['bank_account_id']) ? $sourceLine['bank_account_id'] : 0);
		if ($sourceBankAccountId <= 0) {
			throw new Exception('Source native bank line has no valid bank account #' . $sourceLineId);
		}
		if ($targetBankAccountId > 0 && $sourceBankAccountId !== $targetBankAccountId) {
			throw new Exception('Source native bank line bank account mismatch #' . $sourceLineId);
		}

		$sourceAmount = abs((float) $sourceLine['amount']);
		if ($sourceAmount <= 0.00001) {
			throw new Exception('Source native bank line amount is zero #' . $sourceLineId);
		}

		$allocatedAmount = abs((float) price2num((string) $allocatedAmount, 'MU'));
		if ($allocatedAmount > 0.00001 && abs($allocatedAmount - $sourceAmount) > 0.01) {
			throw new Exception('Allocated amount must match selected native bank line amount');
		}

		return array(
			'source_line_id' => $sourceLineId,
			'source_ref' => $this->resolveNativeBankLineRef($sourceLine),
		);
	}

	/**
	 * Resolve the native bank line already linked to a customer/supplier payment.
	 *
	 * @param array<string,mixed> $statementLine
	 * @param string $docType
	 * @param int $paymentId
	 * @param float $allocatedAmount
	 * @return array<string,mixed>
	 */
	protected function resolveLinkedPaymentNativeBankLineForReconciliation($statementLine, $docType, $paymentId, $allocatedAmount)
	{
		$docType = trim((string) $docType);
		$paymentId = (int) $paymentId;
		if (($docType !== 'payment_linked' && $docType !== 'payment_supplier_linked') || $paymentId <= 0) {
			throw new Exception('Linked payment reference is invalid');
		}

		if ($docType === 'payment_linked') {
			$sql = 'SELECT p.fk_bank, p.amount';
			$sql .= ' FROM ' . $this->db->prefix() . 'paiement as p';
			$sql .= ' WHERE p.entity = ' . ((int) $this->entity);
			$sql .= ' AND p.rowid = ' . $paymentId;
		} else {
			$sql = 'SELECT p.fk_bank, p.amount';
			$sql .= ' FROM ' . $this->db->prefix() . 'paiementfourn as p';
			$sql .= ' WHERE p.entity = ' . ((int) $this->entity);
			$sql .= ' AND p.rowid = ' . $paymentId;
		}
		$sql .= $this->db->plimit(1, 0);

		$resql = $this->db->query($sql);
		if (!$resql) {
			throw new Exception($this->db->lasterror());
		}
		$obj = $this->db->fetch_object($resql);
		if (!$obj || empty($obj->fk_bank)) {
			throw new Exception('Linked payment has no native bank line');
		}

		$bankLineId = (int) $obj->fk_bank;
		$allocatedAmount = abs((float) price2num((string) $allocatedAmount, 'MU'));
		$paymentAmount = abs((float) $obj->amount);
		if ($allocatedAmount > 0.00001 && $paymentAmount > 0.00001 && abs($allocatedAmount - $paymentAmount) > 0.01) {
			throw new Exception('Allocated amount must match linked payment amount');
		}

		return $this->resolveExistingNativeBankLineForReconciliation($statementLine, $bankLineId, $allocatedAmount);
	}

	/**
	 * Resolve display reference for one native bank line.
	 *
	 * @param array<string,mixed> $line
	 * @return string
	 */
	protected function resolveNativeBankLineRef($line)
	{
		$ref = trim((string) (!empty($line['payment_reference']) ? $line['payment_reference'] : ''));
		if ($ref === '') {
			$ref = trim((string) (!empty($line['bank_reference']) ? $line['bank_reference'] : ''));
		}
		if ($ref === '') {
			$ref = trim((string) (!empty($line['description']) ? $line['description'] : ''));
		}
		if ($ref === '') {
			$ref = trim((string) (!empty($line['native_label']) ? $line['native_label'] : ''));
		}
		if ($ref === '') {
			$ref = 'BANK-' . ((int) $line['rowid']);
		}

		return $ref;
	}

	/**
	 * Create and validate a supplier invoice based on statement line data.
	 *
	 * @param array<string,mixed> $line
	 * @param int $supplierId
	 * @param string $label
	 * @param float $amount
	 * @param string $note
	 * @param string $supplierRef
	 * @param array<int,array<string,mixed>> $invoiceProductLines
	 * @return array<string,mixed>
	 */
	protected function createSupplierInvoiceForBankLine($line, $supplierId, $label, $amount, $note = '', $supplierRef = '', $invoiceProductLines = array())
	{
		require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';
		require_once DOL_DOCUMENT_ROOT . '/fourn/class/fournisseur.facture.class.php';

		$supplierId = (int) $supplierId;
		if ($supplierId <= 0) {
			throw new Exception('Supplier id is required to create supplier invoice');
		}

		$amount = abs((float) price2num((string) $amount, 'MU'));
		if ($amount <= 0.00001) {
			throw new Exception('Supplier invoice amount must be positive');
		}

		$thirdparty = new Societe($this->db);
		if ($thirdparty->fetch($supplierId) <= 0) {
			throw new Exception('Supplier not found #' . ((int) $supplierId));
		}
		if ((int) $thirdparty->entity !== (int) $this->entity) {
			throw new Exception('Supplier is outside current entity');
		}
		if ((int) $thirdparty->fournisseur <= 0) {
			throw new Exception('Selected thirdparty is not configured as supplier');
		}

		$label = trim((string) preg_replace('/\s+/', ' ', (string) $label));
		if ($label === '') {
			$label = 'Supplier invoice from bank reconciliation';
		}
		$label = dol_trunc($label, 190, 'right', 'UTF-8', 1);
		$normalizedInvoiceLines = $this->normalizeSupplierInvoiceProductLines((array) $invoiceProductLines, (float) $amount, (string) $label);
		if (empty($normalizedInvoiceLines)) {
			$normalizedInvoiceLines = array(
				array(
					'product_id' => 0,
					'label' => $label,
					'qty' => 1.0,
					'amount_ttc' => (float) $amount,
				),
			);
		}

		$supplierRef = $this->resolveSupplierInvoiceReference($line, $supplierId, (string) $supplierRef);

		$invoice = null;
		$invoiceId = 0;
		$createError = '';
		$maxCreateAttempts = 3;
		for ($attempt = 1; $attempt <= $maxCreateAttempts; $attempt++) {
			$attemptSupplierRef = $this->resolveUniqueSupplierInvoiceReference($supplierId, $supplierRef, $attempt);

			$invoice = new FactureFournisseur($this->db);
			$invoice->socid = $supplierId;
			$invoice->type = FactureFournisseur::TYPE_STANDARD;
			$invoice->date = $this->resolveLineDateTimestamp($line);
			$invoice->ref_supplier = $attemptSupplierRef;
			$invoice->note_private = (string) $note;

			$invoiceId = (int) $invoice->create($this->user);
			if ($invoiceId > 0) {
				$supplierRef = $attemptSupplierRef;
				break;
			}

			$createError = $this->extractObjectErrorMessage($invoice, 'Failed to create supplier invoice');
			if (!$this->isDuplicateReferenceCreateError($invoice) || $attempt >= $maxCreateAttempts) {
				throw new Exception($createError);
			}
			usleep((int) (120000 * $attempt));
		}
		if ($invoiceId <= 0 || !is_object($invoice)) {
			throw new Exception($createError !== '' ? $createError : 'Failed to create supplier invoice');
		}

		foreach ($normalizedInvoiceLines as $invoiceLine) {
			$lineLabel = trim((string) (!empty($invoiceLine['label']) ? $invoiceLine['label'] : $label));
			if ($lineLabel === '') {
				$lineLabel = $label;
			}
			$lineLabel = dol_trunc((string) preg_replace('/\s+/', ' ', $lineLabel), 190, 'right', 'UTF-8', 1);
			$lineQty = abs((float) price2num((string) (!empty($invoiceLine['qty']) ? $invoiceLine['qty'] : 0), 'MS'));
			if ($lineQty <= 0.000001) {
				$lineQty = 1.0;
			}
			$lineAmountTtc = abs((float) price2num((string) (!empty($invoiceLine['amount_ttc']) ? $invoiceLine['amount_ttc'] : 0), 'MU'));
			if ($lineAmountTtc <= 0.00001) {
				$lineAmountTtc = abs((float) price2num((string) $amount, 'MU'));
			}
			$lineUnitPrice = (float) price2num((string) ($lineAmountTtc / $lineQty), 'MU');
			if ($lineUnitPrice <= 0.000001) {
				$lineUnitPrice = (float) price2num((string) $lineAmountTtc, 'MU');
			}
			$lineProductId = (int) (!empty($invoiceLine['product_id']) ? $invoiceLine['product_id'] : 0);

			$lineResult = (int) $invoice->addline(
				$lineLabel,
				$lineUnitPrice,
				0,
				0,
				0,
				$lineQty,
				$lineProductId,
				0,
				0,
				0,
				0,
				0,
				'TTC'
			);
			if ($lineResult <= 0) {
				$error = $this->extractObjectErrorMessage($invoice, 'Failed to add supplier invoice line');
				try {
					$cleanup = new FactureFournisseur($this->db);
					if ($cleanup->fetch($invoiceId) > 0 && (int) $cleanup->status === FactureFournisseur::STATUS_DRAFT) {
						$cleanup->delete($this->user);
					}
				} catch (Throwable $cleanupError) {
					// Keep original error: cleanup is best-effort only.
				}
				throw new Exception($error);
			}
		}

		if ($invoice->fetch($invoiceId) <= 0) {
			throw new Exception('Failed to reload created supplier invoice #' . ((int) $invoiceId));
		}
		$validateResult = (int) $invoice->validate($this->user);
		if ($validateResult <= 0) {
			throw new Exception($this->extractObjectErrorMessage($invoice, 'Failed to validate supplier invoice'));
		}

		return array(
			'invoice_id' => (int) $invoiceId,
			'invoice_ref' => (string) $invoice->ref,
			'supplier_id' => (int) $thirdparty->id,
			'supplier_name' => (string) $thirdparty->name,
		);
	}

	/**
	 * Normalize modal-selected supplier invoice product lines.
	 *
	 * @param array<int,array<string,mixed>> $invoiceProductLines
	 * @param float $targetAmount
	 * @param string $fallbackLabel
	 * @return array<int,array<string,mixed>>
	 */
	protected function normalizeSupplierInvoiceProductLines($invoiceProductLines, $targetAmount, $fallbackLabel)
	{
		require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';

		$normalizedLines = array();
		if (empty($invoiceProductLines) || !is_array($invoiceProductLines)) {
			return $normalizedLines;
		}

		$targetAmount = abs((float) price2num((string) $targetAmount, 'MU'));
		$fallbackLabel = dol_trunc((string) preg_replace('/\s+/', ' ', trim((string) $fallbackLabel)), 190, 'right', 'UTF-8', 1);
		$productCache = array();
		$linesTotal = 0.0;

		foreach ($invoiceProductLines as $lineIndex => $lineData) {
			if (!is_array($lineData)) {
				continue;
			}

			$productId = !empty($lineData['product_id']) ? (int) $lineData['product_id'] : 0;
			$lineLabel = trim((string) (!empty($lineData['label']) ? $lineData['label'] : ''));
			$lineQty = abs((float) price2num((string) (!empty($lineData['qty']) ? $lineData['qty'] : 0), 'MS'));
			$lineAmount = abs((float) price2num((string) (!empty($lineData['amount']) ? $lineData['amount'] : 0), 'MU'));

			if ($productId <= 0 && $lineLabel === '' && $lineQty <= 0.00001 && $lineAmount <= 0.00001) {
				continue;
			}
			if ($productId <= 0) {
				throw new Exception('Product is required on supplier invoice line #' . ((int) $lineIndex + 1));
			}
			if ($lineQty <= 0.000001) {
				throw new Exception('Quantity must be greater than zero on supplier invoice line #' . ((int) $lineIndex + 1));
			}
			if ($lineAmount <= 0.00001) {
				throw new Exception('Amount must be greater than zero on supplier invoice line #' . ((int) $lineIndex + 1));
			}

			if (!isset($productCache[$productId])) {
				$product = new Product($this->db);
				if ($product->fetch($productId) <= 0) {
					throw new Exception('Product not found #' . ((int) $productId));
				}
				if ((int) $product->entity !== (int) $this->entity) {
					throw new Exception('Product is outside current entity #' . ((int) $productId));
				}
				if ((int) $product->status_buy <= 0) {
					throw new Exception('Product is not purchasable #' . ((int) $productId));
				}
				$productLabel = trim((string) (!empty($product->label) ? $product->label : ''));
				if ($productLabel === '') {
					$productLabel = trim((string) (!empty($product->description) ? $product->description : ''));
				}
				if ($productLabel === '') {
					$productLabel = trim((string) (!empty($product->ref) ? $product->ref : ''));
				}
				if ($productLabel === '') {
					$productLabel = 'Product #' . ((int) $productId);
				}
				$productCache[$productId] = dol_trunc((string) preg_replace('/\s+/', ' ', $productLabel), 190, 'right', 'UTF-8', 1);
			}

			if ($lineLabel === '') {
				$lineLabel = (string) $productCache[$productId];
			}
			if ($lineLabel === '') {
				$lineLabel = $fallbackLabel;
			}
			if ($lineLabel === '') {
				$lineLabel = 'Supplier invoice line #' . ((int) $lineIndex + 1);
			}
			$lineLabel = dol_trunc((string) preg_replace('/\s+/', ' ', $lineLabel), 190, 'right', 'UTF-8', 1);

			$normalizedLines[] = array(
				'product_id' => $productId,
				'label' => $lineLabel,
				'qty' => $lineQty,
				'amount_ttc' => $lineAmount,
			);
			$linesTotal += $lineAmount;
		}

		if (empty($normalizedLines) || $targetAmount <= 0.00001) {
			return $normalizedLines;
		}

		$delta = (float) price2num((string) ($targetAmount - $linesTotal), 'MU');
		if (abs($delta) > 0.00001 && abs($delta) <= 0.01) {
			$lastIndex = count($normalizedLines) - 1;
			$lastAmount = (float) $normalizedLines[$lastIndex]['amount_ttc'];
			$adjustedAmount = (float) price2num((string) ($lastAmount + $delta), 'MU');
			if ($adjustedAmount <= 0.00001) {
				throw new Exception('Supplier invoice lines total must match allocated amount');
			}
			$normalizedLines[$lastIndex]['amount_ttc'] = $adjustedAmount;
			$linesTotal = (float) price2num((string) ($linesTotal + $delta), 'MU');
		}

		if (abs($linesTotal - $targetAmount) > 0.01) {
			throw new Exception('Supplier invoice lines total must match allocated amount');
		}

		return $normalizedLines;
	}

	/**
	 * Create a native customer payment linked to an existing bank line.
	 *
	 * @param array<string,mixed> $line
	 * @param int $invoiceId
	 * @param float $allocatedAmount
	 * @param string $note
	 * @return array<string,mixed>
	 */
	protected function createCustomerPaymentForBankLine($line, $invoiceId, $allocatedAmount, $note = '')
	{
		global $conf;
		require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';
		require_once DOL_DOCUMENT_ROOT . '/compta/paiement/class/paiement.class.php';

		$this->lockInvoiceForPayment('facture', (int) $invoiceId, 'Customer invoice');
		$invoice = new Facture($this->db);
		if ($invoice->fetch((int) $invoiceId) <= 0) {
			throw new Exception('Customer invoice not found #' . ((int) $invoiceId));
		}
		if ((int) $invoice->entity !== (int) $this->entity) {
			throw new Exception('Customer invoice is outside current entity');
		}
		if ((int) $invoice->type === Facture::TYPE_CREDIT_NOTE) {
			throw new Exception('Customer credit notes require a dedicated refund reconciliation workflow');
		}
		if ((int) $invoice->status !== Facture::STATUS_VALIDATED || !empty($invoice->paye)) {
			throw new Exception('Customer invoice is no longer open for payment');
		}
		$this->assertInvoiceAllocationWithinRemaining($invoice, $allocatedAmount, 'Customer invoice');
		$invoice->fetch_thirdparty();

		$payment = new Paiement($this->db);
		$payment->datepaye = $this->resolveLineDateTimestamp($line);
		$payment->amounts = array((int) $invoiceId => (float) price2num((string) $allocatedAmount, 'MT'));
		$payment->multicurrency_amounts = array((int) $invoiceId => 0);
		$payment->multicurrency_code = array(
			(int) $invoiceId => !empty($invoice->multicurrency_code) ? (string) $invoice->multicurrency_code : (string) $conf->currency,
		);
		$payment->multicurrency_tx = array(
			(int) $invoiceId => !empty($invoice->multicurrency_tx) ? (float) $invoice->multicurrency_tx : 1.0,
		);
		$payment->paiementid = $this->resolvePaymentModeId();
		$payment->num_payment = $this->resolveLinePaymentRef($line);
		$payment->note_private = (string) $note;
		$payment->fk_account = (int) $line['bank_account_id'];

		$paymentId = (int) $payment->create($this->user, 1, $invoice->thirdparty);
		if ($paymentId <= 0) {
			throw new Exception($this->extractObjectErrorMessage($payment, 'Failed to create customer payment'));
		}
		if ((int) $payment->update_fk_bank((int) $line['rowid']) <= 0) {
			throw new Exception($this->extractObjectErrorMessage($payment, 'Failed to attach customer payment to bank line'));
		}

		return array(
			'payment_id' => $paymentId,
			'thirdparty_id' => !empty($invoice->thirdparty->id) ? (int) $invoice->thirdparty->id : 0,
			'thirdparty_name' => !empty($invoice->thirdparty->name) ? (string) $invoice->thirdparty->name : '',
		);
	}

	/**
	 * Attach an existing customer payment to an existing bank line.
	 *
	 * @param array<string,mixed> $line
	 * @param int $paymentId
	 * @param float $allocatedAmount
	 * @return array<string,mixed>
	 */
	protected function attachExistingCustomerPaymentToBankLine($line, $paymentId, $allocatedAmount)
	{
		require_once DOL_DOCUMENT_ROOT . '/compta/paiement/class/paiement.class.php';

		$payment = new Paiement($this->db);
		if ($payment->fetch((int) $paymentId) <= 0) {
			throw new Exception('Customer payment not found #' . ((int) $paymentId));
		}
		if ((int) $payment->entity !== (int) $this->entity) {
			throw new Exception('Customer payment is outside current entity');
		}

		$paymentAmount = abs((float) $payment->amount);
		if ($paymentAmount <= 0.00001) {
			throw new Exception('Customer payment amount is zero #' . ((int) $paymentId));
		}

		$allocatedAmount = abs((float) price2num((string) $allocatedAmount, 'MU'));
		if ($allocatedAmount > 0.00001 && abs($allocatedAmount - $paymentAmount) > 0.01) {
			throw new Exception('Allocated amount must match selected customer payment amount');
		}

		$currentBankLine = !empty($payment->bank_line) ? (int) $payment->bank_line : 0;
		if ($currentBankLine > 0 && $currentBankLine !== (int) $line['rowid']) {
			if ($this->isNativeBankLineReconciled($currentBankLine)) {
				throw new Exception('Customer payment already linked to another reconciled bank line');
			}
			$this->removePaymentUrlLinkFromBankLine($currentBankLine, (int) $payment->id, 'payment');
		}

		if ((int) $payment->update_fk_bank((int) $line['rowid']) <= 0) {
			throw new Exception($this->extractObjectErrorMessage($payment, 'Failed to attach customer payment to bank line'));
		}

		$paymentRef = trim((string) (!empty($payment->ref) ? $payment->ref : $payment->num_payment));
		if ($paymentRef === '') {
			$paymentRef = '(paiement)';
		}

		return array(
			'payment_id' => (int) $payment->id,
			'payment_ref' => $paymentRef,
			'thirdparties' => $this->getCustomerPaymentThirdparties((int) $payment->id),
		);
	}

	/**
	 * Get customer payment third parties for bank_url links.
	 *
	 * @param int $paymentId
	 * @return array<int,array<string,mixed>>
	 */
	protected function getCustomerPaymentThirdparties($paymentId)
	{
		$sql = 'SELECT DISTINCT s.rowid as socid, s.nom as name';
		$sql .= ' FROM ' . $this->db->prefix() . 'paiement_facture as pf';
		$sql .= ' INNER JOIN ' . $this->db->prefix() . 'facture as f ON f.rowid = pf.fk_facture';
		$sql .= ' LEFT JOIN ' . $this->db->prefix() . 'societe as s ON s.rowid = f.fk_soc';
		$sql .= ' WHERE pf.fk_paiement = ' . ((int) $paymentId);
		$sql .= ' AND f.entity = ' . ((int) $this->entity);
		$sql .= ' ORDER BY s.nom ASC';

		$resql = $this->db->query($sql);
		if (!$resql) {
			return array();
		}

		$rows = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$tpId = !empty($obj->socid) ? (int) $obj->socid : 0;
			if ($tpId <= 0) {
				continue;
			}
			$rows[$tpId] = array(
				'id' => $tpId,
				'name' => (string) $obj->name,
				'url' => DOL_URL_ROOT . '/comm/card.php?socid=',
			);
		}

		return array_values($rows);
	}

	/**
	 * Create a native supplier payment linked to an existing bank line.
	 *
	 * @param array<string,mixed> $line
	 * @param int $invoiceId
	 * @param float $allocatedAmount
	 * @param string $note
	 * @return array<string,mixed>
	 */
	protected function createSupplierPaymentForBankLine($line, $invoiceId, $allocatedAmount, $note = '')
	{
		global $conf;
		require_once DOL_DOCUMENT_ROOT . '/fourn/class/fournisseur.facture.class.php';
		require_once DOL_DOCUMENT_ROOT . '/fourn/class/paiementfourn.class.php';

		$this->lockInvoiceForPayment('facture_fourn', (int) $invoiceId, 'Supplier invoice');
		$invoice = new FactureFournisseur($this->db);
		if ($invoice->fetch((int) $invoiceId) <= 0) {
			throw new Exception('Supplier invoice not found #' . ((int) $invoiceId));
		}
		if ((int) $invoice->entity !== (int) $this->entity) {
			throw new Exception('Supplier invoice is outside current entity');
		}
		if ((int) $invoice->type === FactureFournisseur::TYPE_CREDIT_NOTE) {
			throw new Exception('Supplier credit notes require a dedicated refund reconciliation workflow');
		}
		if ((int) $invoice->status !== FactureFournisseur::STATUS_VALIDATED || !empty($invoice->paye)) {
			throw new Exception('Supplier invoice is no longer open for payment');
		}
		$this->assertInvoiceAllocationWithinRemaining($invoice, $allocatedAmount, 'Supplier invoice');
		$invoice->fetch_thirdparty();

		$payment = new PaiementFourn($this->db);
		$payment->datepaye = $this->resolveLineDateTimestamp($line);
		$payment->amounts = array((int) $invoiceId => (float) price2num((string) $allocatedAmount, 'MT'));
		$payment->multicurrency_amounts = array((int) $invoiceId => 0);
		$payment->multicurrency_code = array(
			(int) $invoiceId => !empty($invoice->multicurrency_code) ? (string) $invoice->multicurrency_code : (string) $conf->currency,
		);
		$payment->multicurrency_tx = array(
			(int) $invoiceId => !empty($invoice->multicurrency_tx) ? (float) $invoice->multicurrency_tx : 1.0,
		);
		$payment->paiementid = $this->resolvePaymentModeId();
		$payment->num_payment = $this->resolveLinePaymentRef($line);
		$payment->note_private = (string) $note;
		$payment->fk_account = (int) $line['bank_account_id'];

		$paymentId = (int) $payment->create($this->user, 1, $invoice->thirdparty);
		if ($paymentId <= 0) {
			throw new Exception($this->extractObjectErrorMessage($payment, 'Failed to create supplier payment'));
		}
		if ((int) $payment->update_fk_bank((int) $line['rowid']) <= 0) {
			throw new Exception($this->extractObjectErrorMessage($payment, 'Failed to attach supplier payment to bank line'));
		}

		return array(
			'payment_id' => $paymentId,
			'thirdparty_id' => !empty($invoice->thirdparty->id) ? (int) $invoice->thirdparty->id : 0,
			'thirdparty_name' => !empty($invoice->thirdparty->name) ? (string) $invoice->thirdparty->name : '',
		);
	}

	/**
	 * Lock one invoice row before validating and creating its payment.
	 *
	 * @param string $tableName
	 * @param int $invoiceId
	 * @param string $invoiceLabel
	 * @return void
	 */
	protected function lockInvoiceForPayment($tableName, $invoiceId, $invoiceLabel)
	{
		$allowedTables = array('facture', 'facture_fourn');
		if (!in_array((string) $tableName, $allowedTables, true)) {
			throw new Exception('Unsupported invoice table for reconciliation lock');
		}

		$sql = 'SELECT rowid FROM ' . $this->db->prefix() . $tableName;
		$sql .= ' WHERE rowid = ' . ((int) $invoiceId);
		$sql .= ' AND entity = ' . ((int) $this->entity);
		$sql .= ' FOR UPDATE';
		$resql = $this->db->query($sql);
		if (!$resql) {
			throw new Exception($this->db->lasterror());
		}
		if ($this->db->num_rows($resql) !== 1) {
			throw new Exception($invoiceLabel . ' not found in current entity #' . ((int) $invoiceId));
		}
	}

	/**
	 * Validate the requested allocation against Dolibarr's current remaining balance.
	 *
	 * @param CommonInvoice $invoice
	 * @param float $allocatedAmount
	 * @param string $invoiceLabel
	 * @return void
	 */
	protected function assertInvoiceAllocationWithinRemaining($invoice, $allocatedAmount, $invoiceLabel)
	{
		if (!is_object($invoice) || empty($invoice->id) || !method_exists($invoice, 'getRemainToPay')) {
			throw new Exception($invoiceLabel . ' cannot provide a remaining balance');
		}

		$remainingRaw = $invoice->getRemainToPay(0);
		if (!is_numeric($remainingRaw)) {
			throw new Exception($this->extractObjectErrorMessage($invoice, 'Failed to read current invoice remaining balance'));
		}

		$remaining = (float) price2num((string) $remainingRaw, 'MT');
		$allocation = abs((float) price2num((string) $allocatedAmount, 'MT'));
		if ($remaining <= 0.00001) {
			throw new Exception($invoiceLabel . ' no longer has an outstanding balance');
		}
		if ($allocation <= 0.00001) {
			throw new Exception('Invoice allocation amount must be greater than zero');
		}
		if (($allocation - $remaining) > 0.01) {
			throw new Exception($invoiceLabel . ' allocation exceeds the current remaining balance');
		}
	}

	/**
	 * Attach an existing supplier payment to an existing bank line.
	 *
	 * @param array<string,mixed> $line
	 * @param int $paymentId
	 * @param float $allocatedAmount
	 * @return array<string,mixed>
	 */
	protected function attachExistingSupplierPaymentToBankLine($line, $paymentId, $allocatedAmount)
	{
		require_once DOL_DOCUMENT_ROOT . '/fourn/class/paiementfourn.class.php';

		$payment = new PaiementFourn($this->db);
		if ($payment->fetch((int) $paymentId) <= 0) {
			throw new Exception('Supplier payment not found #' . ((int) $paymentId));
		}
		if ((int) $payment->entity !== (int) $this->entity) {
			throw new Exception('Supplier payment is outside current entity');
		}

		$paymentAmount = abs((float) $payment->amount);
		if ($paymentAmount <= 0.00001) {
			throw new Exception('Supplier payment amount is zero #' . ((int) $paymentId));
		}

		$allocatedAmount = abs((float) price2num((string) $allocatedAmount, 'MU'));
		if ($allocatedAmount > 0.00001 && abs($allocatedAmount - $paymentAmount) > 0.01) {
			throw new Exception('Allocated amount must match selected supplier payment amount');
		}

		$currentBankLine = !empty($payment->bank_line) ? (int) $payment->bank_line : 0;
		if ($currentBankLine > 0 && $currentBankLine !== (int) $line['rowid']) {
			if ($this->isNativeBankLineReconciled($currentBankLine)) {
				throw new Exception('Supplier payment already linked to another reconciled bank line');
			}
			$this->removePaymentUrlLinkFromBankLine($currentBankLine, (int) $payment->id, 'payment_supplier');
		}

		if ((int) $payment->update_fk_bank((int) $line['rowid']) <= 0) {
			throw new Exception($this->extractObjectErrorMessage($payment, 'Failed to attach supplier payment to bank line'));
		}

		$paymentRef = trim((string) (!empty($payment->ref) ? $payment->ref : $payment->num_payment));
		if ($paymentRef === '') {
			$paymentRef = '(paiement)';
		}

		return array(
			'payment_id' => (int) $payment->id,
			'payment_ref' => $paymentRef,
			'thirdparties' => $this->getSupplierPaymentThirdparties((int) $payment->id),
		);
	}

	/**
	 * Get supplier payment third parties for bank_url links.
	 *
	 * @param int $paymentId
	 * @return array<int,array<string,mixed>>
	 */
	protected function getSupplierPaymentThirdparties($paymentId)
	{
		$sql = 'SELECT DISTINCT s.rowid as socid, s.nom as name';
		$sql .= ' FROM ' . $this->db->prefix() . 'paiementfourn_facturefourn as pf';
		$sql .= ' INNER JOIN ' . $this->db->prefix() . 'facture_fourn as f ON f.rowid = pf.fk_facturefourn';
		$sql .= ' LEFT JOIN ' . $this->db->prefix() . 'societe as s ON s.rowid = f.fk_soc';
		$sql .= ' WHERE pf.fk_paiementfourn = ' . ((int) $paymentId);
		$sql .= ' AND f.entity = ' . ((int) $this->entity);
		$sql .= ' ORDER BY s.nom ASC';

		$resql = $this->db->query($sql);
		if (!$resql) {
			return array();
		}

		$rows = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$tpId = !empty($obj->socid) ? (int) $obj->socid : 0;
			if ($tpId <= 0) {
				continue;
			}
			$rows[$tpId] = array(
				'id' => $tpId,
				'name' => (string) $obj->name,
				'url' => DOL_URL_ROOT . '/fourn/card.php?socid=',
			);
		}

		return array_values($rows);
	}

	/**
	 * Check if a native bank line is conciliated.
	 *
	 * @param int $bankLineId
	 * @return bool
	 */
	protected function isNativeBankLineReconciled($bankLineId)
	{
		$bankLineId = (int) $bankLineId;
		if ($bankLineId <= 0) {
			return false;
		}

		$sql = 'SELECT b.rappro';
		$sql .= ' FROM ' . $this->db->prefix() . 'bank as b';
		$sql .= ' INNER JOIN ' . $this->db->prefix() . 'bank_account as ba ON ba.rowid = b.fk_account';
		$sql .= ' WHERE b.rowid = ' . $bankLineId;
		$sql .= ' AND ba.entity = ' . ((int) $this->entity);
		$sql .= $this->db->plimit(1, 0);
		$resql = $this->db->query($sql);
		if (!$resql) {
			return false;
		}
		$obj = $this->db->fetch_object($resql);
		if (!$obj) {
			return false;
		}

		return ((int) $obj->rappro === 1);
	}

	/**
	 * Remove one payment bank_url link from a specific bank line.
	 *
	 * @param int $bankLineId
	 * @param int $paymentId
	 * @param string $linkType
	 * @return void
	 */
	protected function removePaymentUrlLinkFromBankLine($bankLineId, $paymentId, $linkType)
	{
		$bankLineId = (int) $bankLineId;
		$paymentId = (int) $paymentId;
		$linkType = trim((string) $linkType);
		if ($bankLineId <= 0 || $paymentId <= 0 || $linkType === '') {
			return;
		}

		$sql = 'DELETE FROM ' . $this->db->prefix() . 'bank_url';
		$sql .= ' WHERE fk_bank = ' . $bankLineId;
		$sql .= ' AND url_id = ' . $paymentId;
		$sql .= ' AND type = ' . $this->sqlText($linkType);
		$this->db->query($sql);
	}

	/**
	 * Resolve payment mode id with bank transfer preferred.
	 *
	 * @return int
	 */
	protected function resolvePaymentModeId()
	{
		$modeId = (int) dol_getIdFromCode($this->db, 'VIR', 'c_paiement', 'code', 'id', 1);
		if ($modeId <= 0) {
			$modeId = (int) dol_getIdFromCode($this->db, 'CHQ', 'c_paiement', 'code', 'id', 1);
		}
		if ($modeId <= 0) {
			$modeId = 1;
		}

		return $modeId;
	}

	/**
	 * Resolve payment date from bank line.
	 *
	 * @param array<string,mixed> $line
	 * @return int
	 */
	protected function resolveLineDateTimestamp($line)
	{
		$date = !empty($line['operation_date']) ? (string) $line['operation_date'] : '';
		if ($date === '' && !empty($line['value_date'])) {
			$date = (string) $line['value_date'];
		}
		$ts = $date !== '' ? strtotime($date) : false;
		if ($ts === false || $ts <= 0) {
			$ts = dol_now();
		}

		return (int) $ts;
	}

	/**
	 * Resolve payment reference from bank line.
	 *
	 * @param array<string,mixed> $line
	 * @return string
	 */
	protected function resolveLinePaymentRef($line)
	{
		$ref = trim((string) (!empty($line['payment_reference']) ? $line['payment_reference'] : $line['bank_reference']));
		if ($ref === '') {
			return '';
		}

		return dol_trunc($ref, 60, 'right', 'UTF-8', 1);
	}

	/**
	 * Resolve deterministic supplier invoice external reference for creation.
	 *
	 * @param array<string,mixed> $line
	 * @param int $supplierId
	 * @param string $preferredRef
	 * @return string
	 */
	protected function resolveSupplierInvoiceReference($line, $supplierId, $preferredRef = '')
	{
		$supplierId = (int) $supplierId;
		$candidate = trim((string) $preferredRef);
		if ($candidate === '') {
			$candidate = $this->resolveLinePaymentRef($line);
		}
		if ($candidate === '') {
			$candidate = trim((string) (!empty($line['bank_reference']) ? $line['bank_reference'] : ''));
		}
		if ($candidate === '') {
			$candidate = trim((string) (!empty($line['description']) ? $line['description'] : ''));
		}
		if ($candidate === '') {
			$candidate = 'BANK-LINE-' . ((int) (!empty($line['rowid']) ? $line['rowid'] : 0));
		}
		$candidate = trim((string) preg_replace('/\s+/', ' ', $candidate));
		$candidate = dol_trunc($candidate, 120, 'right', 'UTF-8', 1);
		if ($candidate === '') {
			$candidate = 'BANK-LINE-' . ((int) (!empty($line['rowid']) ? $line['rowid'] : 0));
		}

		return $this->resolveUniqueSupplierInvoiceReference($supplierId, $candidate);
	}

	/**
	 * Resolve unique supplier invoice external reference inside current entity.
	 *
	 * @param int $supplierId
	 * @param string $candidateRef
	 * @param int $attempt
	 * @return string
	 */
	protected function resolveUniqueSupplierInvoiceReference($supplierId, $candidateRef, $attempt = 1)
	{
		$supplierId = (int) $supplierId;
		$attempt = max(1, (int) $attempt);
		$baseRef = trim((string) preg_replace('/\s+/', ' ', (string) $candidateRef));
		$baseRef = dol_trunc($baseRef, 120, 'right', 'UTF-8', 1);
		if ($baseRef === '') {
			$baseRef = 'BANK-LINE';
		}

		$directRef = $baseRef;
		if ($attempt > 1) {
			$attemptSuffix = '-' . $attempt;
			$maxBaseLength = max(1, 120 - dol_strlen($attemptSuffix));
			$directRef = dol_trunc($baseRef, $maxBaseLength, 'right', 'UTF-8', 1) . $attemptSuffix;
		}
		if (!$this->supplierInvoiceRefExists($supplierId, $directRef)) {
			return $directRef;
		}

		$suffix = max(2, $attempt + 1);
		while ($suffix <= 9999) {
			$suffixLabel = '-' . $suffix;
			$maxBaseLength = max(1, 120 - dol_strlen($suffixLabel));
			$testRef = dol_trunc($baseRef, $maxBaseLength, 'right', 'UTF-8', 1) . $suffixLabel;
			if (!$this->supplierInvoiceRefExists($supplierId, $testRef)) {
				return $testRef;
			}
			$suffix++;
		}

		$fallbackSuffix = '-' . date('YmdHis');
		$maxBaseLength = max(1, 120 - dol_strlen($fallbackSuffix));
		return dol_trunc($baseRef, $maxBaseLength, 'right', 'UTF-8', 1) . $fallbackSuffix;
	}

	/**
	 * Check whether a supplier invoice external reference already exists.
	 *
	 * @param int $supplierId
	 * @param string $supplierRef
	 * @return bool
	 */
	protected function supplierInvoiceRefExists($supplierId, $supplierRef)
	{
		$supplierId = (int) $supplierId;
		$supplierRef = trim((string) $supplierRef);
		if ($supplierId <= 0 || $supplierRef === '') {
			return false;
		}

		$sql = 'SELECT f.rowid';
		$sql .= ' FROM ' . $this->db->prefix() . 'facture_fourn as f';
		$sql .= ' WHERE f.entity = ' . ((int) $this->entity);
		$sql .= ' AND f.fk_soc = ' . $supplierId;
		$sql .= ' AND f.ref_supplier = ' . $this->sqlText($supplierRef);
		$sql .= $this->db->plimit(1, 0);
		$resql = $this->db->query($sql);
		if (!$resql) {
			return false;
		}
		$obj = $this->db->fetch_object($resql);

		return (is_object($obj) && (int) $obj->rowid > 0);
	}

	/**
	 * Check if object creation failed because a unique reference already exists.
	 *
	 * @param object $object
	 * @return bool
	 */
	protected function isDuplicateReferenceCreateError($object)
	{
		$dbErrorCode = '';
		if (method_exists($this->db, 'lasterrno')) {
			$dbErrorCode = (string) $this->db->lasterrno();
		}
		if ($dbErrorCode === '' && method_exists($this->db, 'errno')) {
			$dbErrorCode = (string) $this->db->errno();
		}
		if ($dbErrorCode === 'DB_ERROR_RECORD_ALREADY_EXISTS') {
			return true;
		}

		$messages = array();
		if (is_object($object) && !empty($object->error)) {
			$messages[] = (string) $object->error;
		}
		if (is_object($object) && !empty($object->errors) && is_array($object->errors)) {
			foreach ($object->errors as $error) {
				if ($error !== '') {
					$messages[] = (string) $error;
				}
			}
		}

		if (empty($messages)) {
			return false;
		}

		$messageText = strtolower((string) implode(' | ', $messages));
		if ($messageText === '') {
			return false;
		}

		return (
			strpos($messageText, 'errorrefalreadyexists') !== false
			|| strpos($messageText, 'already exists') !== false
			|| strpos($messageText, 'ja existe') !== false
			|| strpos($messageText, 'existe deja') !== false
			|| strpos($messageText, 'db_error_record_already_exists') !== false
		);
	}

	/**
	 * Build readable object error.
	 *
	 * @param object $object
	 * @param string $fallback
	 * @return string
	 */
	protected function extractObjectErrorMessage($object, $fallback)
	{
		$error = '';
		if (is_object($object) && !empty($object->error)) {
			$error = (string) $object->error;
		}
		if ($error === '' && is_object($object) && !empty($object->errors) && is_array($object->errors)) {
			$error = implode(' | ', $object->errors);
		}

		return $error !== '' ? $error : (string) $fallback;
	}

	/**
	 * Resolve open-documents date range around an anchor date.
	 *
	 * @param string|null $anchorDate
	 * @param int|null $intervalDays
	 * @return array<string,mixed>
	 */
	protected function resolveOpenDocumentsDateRange($anchorDate = null, $intervalDays = null)
	{
		$days = ($intervalDays === null) ? (int) getDolGlobalInt('KREABANK_OPEN_DOC_DATE_INTERVAL_DAYS', 7) : (int) $intervalDays;
		if ($days < 0) {
			$days = 0;
		}
		if ($days > 3650) {
			$days = 3650;
		}

		$anchorDate = trim((string) $anchorDate);
		if ($anchorDate === '') {
			return array('enabled' => false, 'start' => '', 'end' => '', 'days' => $days);
		}

		$anchorTs = strtotime($anchorDate);
		if ($anchorTs === false || $anchorTs <= 0) {
			return array('enabled' => false, 'start' => '', 'end' => '', 'days' => $days);
		}

		$startTs = $anchorTs - ($days * 86400);
		$endTs = $anchorTs + ($days * 86400);

		return array(
			'enabled' => true,
			'start' => dol_print_date($startTs, '%Y-%m-%d'),
			'end' => dol_print_date($endTs, '%Y-%m-%d'),
			'days' => $days,
		);
	}

	/**
	 * Resolve default currency using bank account currency, then global conf currency.
	 *
	 * @param int $bankAccountId
	 * @return string
	 */
	protected function resolveDefaultCurrency($bankAccountId = 0)
	{
		global $conf;

		$bankAccountId = (int) $bankAccountId;
		$currency = '';
		if ($bankAccountId > 0) {
			if (isset($this->bankCurrencyCache[$bankAccountId])) {
				$currency = (string) $this->bankCurrencyCache[$bankAccountId];
			} else {
				$sql = 'SELECT ba.currency_code';
				$sql .= ' FROM ' . $this->db->prefix() . 'bank_account as ba';
				$sql .= ' WHERE ba.entity = ' . ((int) $this->entity);
				$sql .= ' AND ba.rowid = ' . $bankAccountId;
				$sql .= $this->db->plimit(1, 0);
				$resql = $this->db->query($sql);
				if ($resql && ($obj = $this->db->fetch_object($resql))) {
					$currency = strtoupper(trim((string) (!empty($obj->currency_code) ? $obj->currency_code : '')));
				}
				$this->bankCurrencyCache[$bankAccountId] = $currency;
			}
		}

		if ($currency === '' && !empty($conf->currency)) {
			$currency = strtoupper(trim((string) $conf->currency));
		}
		if (!preg_match('/^[A-Z]{3}$/', $currency)) {
			$currency = 'EUR';
		}

		return $currency;
	}

	/**
	 * SQL helper for plain text.
	 *
	 * @param string|null $value
	 * @return string
	 */
	protected function sqlText($value)
	{
		if ($value === null || $value === '') {
			return 'NULL';
		}

		return "'" . $this->db->escape((string) $value) . "'";
	}

	/**
	 * SQL helper for date.
	 *
	 * @param string|null $value
	 * @return string
	 */
	protected function sqlDate($value)
	{
		if (empty($value)) {
			return 'NULL';
		}

		return "'" . $this->db->escape((string) $value) . "'";
	}

	/**
	 * SQL helper for datetime from unix timestamp.
	 *
	 * @param int $timestamp
	 * @return string
	 */
	protected function sqlDateTime($timestamp)
	{
		if (empty($timestamp)) {
			$timestamp = dol_now();
		}
		return "'" . $this->db->idate((int) $timestamp) . "'";
	}
}
