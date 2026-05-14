<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

require_once DOL_DOCUMENT_ROOT . '/compta/bank/class/account.class.php';

/**
 * Adapter to persist KreaBank statement imports and native reconciliation links.
 *
 * Pending statements are staged in KreaBank tables. Dolibarr native bank rows are
 * only created or bound during reconciliation.
 */
class KreaBankNativeBankAdapter
{
	/** @var DoliDB */
	protected $db;

	/** @var User */
	protected $user;

	/** @var Translate */
	protected $langs;

	/** @var int */
	protected $entity;

	/** @var bool */
	protected $metaTableChecked = false;

	/** @var bool */
	protected $stagingTablesChecked = false;

	/**
	 * @param DoliDB $db
	 * @param User $user
	 * @param Translate $langs
	 * @param int $entity
	 */
	public function __construct($db, $user, $langs, $entity)
	{
		$this->db = $db;
		$this->user = $user;
		$this->langs = $langs;
		$this->entity = (int) $entity;
	}

	/**
	 * Ensure all native-assistant storage objects exist.
	 *
	 * @return void
	 */
	public function ensureSchema()
	{
		$this->ensureMetaTable();
		$this->ensureStagingTables();
	}

	/**
	 * Import parsed statement lines into KreaBank staging tables.
	 *
	 * @param array<int,array<string,mixed>> $lines
	 * @param string $fileName
	 * @param string $format
	 * @param int $bankAccountId
	 * @param string|null $statementDate
	 * @param array<string,mixed> $options
	 * @return array<string,mixed>
	 */
	public function importLines($lines, $fileName, $format, $bankAccountId, $statementDate = null, $options = array())
	{
		$this->ensureMetaTable();
		$this->ensureStagingTables();

		$bankAccountId = (int) $bankAccountId;
		if ($bankAccountId <= 0) {
			throw new Exception($this->langs->trans('ErrorFieldRequired', $this->langs->trans('BankAccount')));
		}

		$account = $this->assertBankAccountIsOpen($bankAccountId);
		$defaultCurrency = $this->resolveImportCurrency($bankAccountId, $account);

		$statementRef = $this->buildImportedStatementRef($bankAccountId, $statementDate, $fileName);
		$statementId = 0;
		$insertedLineIds = array();
		$imported = 0;
		$duplicates = 0;
		$duplicatesSkipped = 0;
		$duplicatesImported = 0;
		$duplicateDetails = array();
		$allowDuplicateImport = !empty($options['allow_duplicate_import']);
		$duplicateDetailsLimit = !empty($options['duplicate_details_limit']) ? (int) $options['duplicate_details_limit'] : 300;
		if ($duplicateDetailsLimit <= 0) {
			$duplicateDetailsLimit = 300;
		}

		if (!$this->db->begin()) {
			throw new Exception('Failed to start database transaction');
		}

		try {
			foreach ($lines as $index => $line) {
				$normalized = $this->normalizeImportLine($line, $statementDate, $defaultCurrency);
				if (abs((float) $normalized['amount']) < 0.00001) {
					continue;
				}

				$duplicateHash = $this->buildIdempotencyHash($bankAccountId, $normalized);
				$matchedDuplicateHash = $duplicateHash;
				$duplicateSource = '';
				$hashForStorage = $duplicateHash;
				$isDuplicate = $this->hasHash($bankAccountId, $duplicateHash, $duplicateSource);
				if (!$isDuplicate) {
					$legacyDuplicateHash = $this->buildLegacyIdempotencyHash($bankAccountId, $normalized);
					if ($legacyDuplicateHash !== '' && $legacyDuplicateHash !== $duplicateHash && $this->hasHash($bankAccountId, $legacyDuplicateHash, $duplicateSource)) {
						$isDuplicate = true;
						$matchedDuplicateHash = $legacyDuplicateHash;
					}
				}
				if ($isDuplicate) {
					$duplicates++;
					if (count($duplicateDetails) < $duplicateDetailsLimit) {
						$duplicateDetails[] = array(
							'line_rank' => ((int) $index + 1),
							'operation_date' => (string) $normalized['operation_date'],
							'value_date' => (string) $normalized['value_date'],
							'amount' => (float) $normalized['amount'],
							'currency' => (string) $normalized['currency'],
							'payment_reference' => (string) $normalized['payment_reference'],
							'bank_reference' => (string) $normalized['bank_reference'],
							'description' => (string) $normalized['description'],
							'idempotency_hash' => $matchedDuplicateHash,
							'source' => ($duplicateSource !== '' ? $duplicateSource : 'unknown'),
						);
					}

					if (!$allowDuplicateImport) {
						$duplicatesSkipped++;
						continue;
					}

					$duplicatesImported++;
					$hashForStorage = $this->buildForcedIdempotencyHash(
						$duplicateHash,
						$statementRef,
						((int) $index + 1),
						(string) $normalized['line_uid']
					);
				}

				if ($statementId <= 0) {
					$statementId = $this->insertStatementRow(
						$statementRef,
						$bankAccountId,
						(string) $format,
						(string) $fileName,
						(string) $statementDate,
						(string) $defaultCurrency
					);
					if ($statementId <= 0) {
						throw new Exception($this->db->lasterror());
					}
				}

				$newLineId = $this->insertStatementLineRow(
					$statementId,
					((int) $index + 1),
					$normalized,
					$hashForStorage,
					($isDuplicate ? 1 : 0)
				);
				if ((int) $newLineId <= 0) {
					throw new Exception($this->db->lasterror());
				}

				$insertedLineIds[] = (int) $newLineId;
				$imported++;
			}

			$this->db->commit();
		} catch (Exception $e) {
			$this->db->rollback();
			throw $e;
		}

		return array(
			'statement_ref' => ($imported > 0 ? $statementRef : ''),
			'statement_id' => $statementId,
			'imported_lines' => $imported,
			'duplicates' => $duplicates,
			'duplicates_skipped' => $duplicatesSkipped,
			'duplicates_imported' => $duplicatesImported,
			'duplicates_details' => $duplicateDetails,
			'duplicates_reconciled' => 0,
			'format' => $format,
			'bank_line_ids' => $insertedLineIds,
		);
	}

	/**
	 * Get pending imported statement lines.
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
		$this->ensureMetaTable();
		$this->ensureStagingTables();

		$stagedRows = $this->getPendingLinesFromStaging((int) $limit, (int) $offset, (string) $sortfield, (string) $sortorder, (array) $filters, (bool) $includeSkipped);
		if (!empty($stagedRows)) {
			return $stagedRows;
		}

		$sql = 'SELECT b.rowid as rowid, 0 as fk_statement, b.rowid as fk_native_bank_line,';
		$sql .= ' COALESCE(l.operation_date, b.dateo) as operation_date, COALESCE(l.value_date, b.datev) as value_date,';
		$sql .= ' CASE';
		$sql .= ' WHEN l.direction < 0 THEN -ABS(COALESCE(l.amount, b.amount))';
		$sql .= ' WHEN l.direction > 0 THEN ABS(COALESCE(l.amount, b.amount))';
		$sql .= ' ELSE COALESCE(l.amount, b.amount)';
		$sql .= ' END as amount,';
		$sql .= " COALESCE(l.currency, ba.currency_code, 'EUR') as currency,";
		$sql .= ' l.running_balance, l.counterparty_name, l.counterparty_iban,';
		$sql .= ' COALESCE(l.description, b.label) as description, l.payment_reference, l.bank_reference,';
		$sql .= ' CASE';
		$sql .= ' WHEN l.direction <> 0 THEN l.direction';
		$sql .= ' WHEN COALESCE(l.amount, b.amount) > 0 THEN 1';
		$sql .= ' WHEN COALESCE(l.amount, b.amount) < 0 THEN -1';
		$sql .= ' ELSE 0';
		$sql .= ' END as direction,';
		$sql .= ' CASE';
		$sql .= ' WHEN (l.status = 2 OR b.rappro = 1) THEN ABS(CASE';
		$sql .= ' WHEN l.direction < 0 THEN -ABS(COALESCE(l.amount, b.amount))';
		$sql .= ' WHEN l.direction > 0 THEN ABS(COALESCE(l.amount, b.amount))';
		$sql .= ' ELSE COALESCE(l.amount, b.amount)';
		$sql .= ' END)';
		$sql .= ' ELSE 0';
		$sql .= ' END as allocated_amount,';
		$sql .= ' l.status, l.idempotency_hash as duplicate_hash, 0 as is_duplicate, NULL as duplicate_of,';
		$sql .= ' b.num_releve as statement_ref, l.source_type, l.source_file, b.fk_account as bank_account_id,';
		$sql .= ' ba.ref as bank_account_ref, ba.label as bank_account_label, ba.currency_code as bank_currency,';
		$sql .= ' b.label as native_label, b.note as native_note, b.rappro';
		$sql .= ' FROM ' . $this->db->prefix() . 'kreabank_bankmeta as l';
		$sql .= ' INNER JOIN ' . $this->db->prefix() . 'bank as b ON b.rowid = l.fk_bank_line';
		$sql .= ' INNER JOIN ' . $this->db->prefix() . 'bank_account as ba ON ba.rowid = b.fk_account';
		$sql .= ' WHERE l.entity = ' . ((int) $this->entity);
		$sql .= ' AND ba.entity = ' . ((int) $this->entity);
		$sql .= ' AND ba.clos = 0';
		$sql .= ' AND ba.rappro = 1';
		$sql .= " AND b.num_releve IS NOT NULL AND b.num_releve <> ''";
		$sql .= $this->buildPendingLinesStatusSql((bool) $includeSkipped);
		$sql .= ' AND b.rappro = 0';
		$sql .= $this->buildPendingLinesFilterSql((array) $filters);
		$sql .= $this->buildPendingLinesOrderBySql((string) $sortfield, (string) $sortorder);
		$sql .= $this->db->plimit((int) $limit, (int) $offset);

		$resql = $this->db->query($sql);
		if (!$resql) {
			throw new Exception($this->db->lasterror());
		}

		$rows = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$rows[] = $this->stagedRowToLineArray($obj);
		}

		if (!empty($rows)) {
			return $rows;
		}

		// Backward compatibility: only fallback when no native/meta imported lines exist at all.
		// Do not fallback on filtered-empty results, otherwise staged rows can leak into scoped searches.
		if (!$this->hasAnyNativeMetaImportedLines()) {
			return $this->getPendingLinesFromStaging((int) $limit, (int) $offset, (string) $sortfield, (string) $sortorder, (array) $filters, (bool) $includeSkipped);
		}

		return array();
	}

	/**
	 * Get pending lines from legacy staged tables (1.11.x compatibility path).
	 *
	 * @param int $limit
	 * @param int $offset
	 * @param string $sortfield
	 * @param string $sortorder
	 * @param array<string,mixed> $filters
	 * @param bool $includeSkipped
	 * @return array<int,array<string,mixed>>
	 */
	protected function getPendingLinesFromStaging($limit = 200, $offset = 0, $sortfield = 'operation_date', $sortorder = 'ASC', $filters = array(), $includeSkipped = false)
	{
		$this->ensureStagingTables();

		$sql = 'SELECT l.rowid, l.fk_statement, l.fk_native_bank_line, l.operation_date, l.value_date,';
		$sql .= ' l.amount, l.currency, l.running_balance, l.counterparty_name, l.counterparty_iban,';
		$sql .= ' l.description, l.payment_reference, l.bank_reference, l.direction, l.allocated_amount,';
		$sql .= ' l.status, l.duplicate_hash, l.is_duplicate, l.duplicate_of,';
		$sql .= ' s.ref as statement_ref, s.source_type, s.filename as source_file, s.bank_account_id,';
		$sql .= ' ba.ref as bank_account_ref, ba.label as bank_account_label, ba.currency_code as bank_currency,';
		$sql .= ' b.label as native_label, b.note as native_note, b.rappro';
		$sql .= ' FROM ' . $this->db->prefix() . 'kreabank_statement_line as l';
		$sql .= ' INNER JOIN ' . $this->db->prefix() . 'kreabank_statement as s ON s.rowid = l.fk_statement';
		$sql .= ' INNER JOIN ' . $this->db->prefix() . 'bank_account as ba ON ba.rowid = s.bank_account_id';
		$sql .= ' LEFT JOIN ' . $this->db->prefix() . 'bank as b ON b.rowid = l.fk_native_bank_line';
		$sql .= ' LEFT JOIN ' . $this->db->prefix() . 'kreabank_bankmeta as m ON m.entity = ' . ((int) $this->entity) . ' AND m.fk_bank_line = l.fk_native_bank_line';
		$sql .= ' WHERE l.entity = ' . ((int) $this->entity);
		$sql .= ' AND s.entity = ' . ((int) $this->entity);
		$sql .= ' AND ba.entity = ' . ((int) $this->entity);
		$sql .= ' AND ba.clos = 0';
		$sql .= ' AND ba.rappro = 1';
		$sql .= " AND s.ref IS NOT NULL AND s.ref <> ''";
		$sql .= ' AND m.rowid IS NULL';
		$sql .= ' AND (b.rowid IS NULL OR b.rappro = 0)';
		$sql .= $this->buildPendingLinesStatusSql((bool) $includeSkipped);
		$sql .= $this->buildPendingLinesFilterSql((array) $filters);
		$sql .= $this->buildPendingLinesOrderBySql((string) $sortfield, (string) $sortorder);
		$sql .= $this->db->plimit((int) $limit, (int) $offset);

		$resql = $this->db->query($sql);
		if (!$resql) {
			throw new Exception($this->db->lasterror());
		}

		$rows = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$rows[] = $this->stagedRowToLineArray($obj);
		}

		return $rows;
	}

	/**
	 * Get total number of pending imported bank lines.
	 *
	 * @param array<string,mixed> $filters
	 * @param bool $includeSkipped Include manually skipped/deferred lines (status=3)
	 * @return int
	 */
	public function getPendingLinesCount($filters = array(), $includeSkipped = false)
	{
		$this->ensureMetaTable();
		$this->ensureStagingTables();

		$stagedCount = $this->getPendingLinesCountFromStaging((array) $filters, (bool) $includeSkipped);
		if ($stagedCount > 0) {
			return $stagedCount;
		}

		$sql = 'SELECT COUNT(l.rowid) as nb';
		$sql .= ' FROM ' . $this->db->prefix() . 'kreabank_bankmeta as l';
		$sql .= ' INNER JOIN ' . $this->db->prefix() . 'bank as b ON b.rowid = l.fk_bank_line';
		$sql .= ' INNER JOIN ' . $this->db->prefix() . 'bank_account as ba ON ba.rowid = b.fk_account';
		$sql .= ' WHERE l.entity = ' . ((int) $this->entity);
		$sql .= ' AND ba.entity = ' . ((int) $this->entity);
		$sql .= ' AND ba.clos = 0';
		$sql .= ' AND ba.rappro = 1';
		$sql .= " AND b.num_releve IS NOT NULL AND b.num_releve <> ''";
		$sql .= $this->buildPendingLinesStatusSql((bool) $includeSkipped);
		$sql .= ' AND b.rappro = 0';
		$sql .= $this->buildPendingLinesFilterSql((array) $filters);

		$resql = $this->db->query($sql);
		if (!$resql) {
			throw new Exception($this->db->lasterror());
		}

		$obj = $this->db->fetch_object($resql);
		if (!$obj) {
			return 0;
		}

		$nativeCount = (int) $obj->nb;
		if ($nativeCount > 0) {
			return $nativeCount;
		}

		if ($this->hasAnyNativeMetaImportedLines()) {
			return 0;
		}

		return $this->getPendingLinesCountFromStaging((array) $filters, (bool) $includeSkipped);
	}

	/**
	 * Count pending lines from legacy staged tables (1.11.x compatibility path).
	 *
	 * @param array<string,mixed> $filters
	 * @param bool $includeSkipped
	 * @return int
	 */
	protected function getPendingLinesCountFromStaging($filters = array(), $includeSkipped = false)
	{
		$this->ensureStagingTables();

		$sql = 'SELECT COUNT(l.rowid) as nb';
		$sql .= ' FROM ' . $this->db->prefix() . 'kreabank_statement_line as l';
		$sql .= ' INNER JOIN ' . $this->db->prefix() . 'kreabank_statement as s ON s.rowid = l.fk_statement';
		$sql .= ' INNER JOIN ' . $this->db->prefix() . 'bank_account as ba ON ba.rowid = s.bank_account_id';
		$sql .= ' LEFT JOIN ' . $this->db->prefix() . 'bank as b ON b.rowid = l.fk_native_bank_line';
		$sql .= ' LEFT JOIN ' . $this->db->prefix() . 'kreabank_bankmeta as m ON m.entity = ' . ((int) $this->entity) . ' AND m.fk_bank_line = l.fk_native_bank_line';
		$sql .= ' WHERE l.entity = ' . ((int) $this->entity);
		$sql .= ' AND s.entity = ' . ((int) $this->entity);
		$sql .= ' AND ba.entity = ' . ((int) $this->entity);
		$sql .= ' AND ba.clos = 0';
		$sql .= ' AND ba.rappro = 1';
		$sql .= " AND s.ref IS NOT NULL AND s.ref <> ''";
		$sql .= ' AND m.rowid IS NULL';
		$sql .= ' AND (b.rowid IS NULL OR b.rappro = 0)';
		$sql .= $this->buildPendingLinesStatusSql((bool) $includeSkipped);
		$sql .= $this->buildPendingLinesFilterSql((array) $filters);

		$resql = $this->db->query($sql);
		if (!$resql) {
			throw new Exception($this->db->lasterror());
		}

		$obj = $this->db->fetch_object($resql);
		if (!$obj) {
			return 0;
		}

		return (int) $obj->nb;
	}

	/**
	 * Get one line (staging-first, legacy-native fallback).
	 *
	 * @param int $lineId
	 * @return array<string,mixed>|null
	 */
	public function getLineById($lineId)
	{
		$lineId = (int) $lineId;
		if ($lineId <= 0) {
			return null;
		}

		if ($this->hasManagedNativeLine($lineId)) {
			$nativeManaged = $this->getLegacyNativeLineById($lineId);
			if (!empty($nativeManaged)) {
				return $nativeManaged;
			}
		}

		$staged = $this->getStagedLineById($lineId);
		if (!empty($staged)) {
			return $staged;
		}

		return $this->getLegacyNativeLineById($lineId);
	}

	/**
	 * Create native bank line for one staged line if missing.
	 *
	 * @param int $lineId
	 * @return int
	 */
	public function ensureNativeLineForStatementLine($lineId)
	{
		$this->ensureMetaTable();
		$this->ensureStagingTables();

		$lineId = (int) $lineId;
		if ($lineId <= 0) {
			throw new Exception('Invalid statement line id');
		}

		$line = $this->getStagedLineById($lineId);
		if (empty($line)) {
			$legacy = $this->getLegacyNativeLineById($lineId);
			if (!empty($legacy)) {
				return (int) $legacy['rowid'];
			}
			throw new Exception('Unknown statement line');
		}

		$existingNativeLineId = !empty($line['native_bank_line_id']) ? (int) $line['native_bank_line_id'] : 0;
		if ($existingNativeLineId > 0) {
			$nativeLine = new AccountLine($this->db);
			if ($nativeLine->fetch($existingNativeLineId) > 0) {
				return $existingNativeLineId;
			}
		}

		$account = $this->assertBankAccountIsOpen((int) $line['bank_account_id']);
		$dateo = $this->toTimestamp((string) $line['operation_date']);
		$datev = $this->toTimestamp((string) $line['value_date']);
		$amount = (float) price2num((string) $line['amount'], 'MU');
		$operationCode = $this->guessOperationCode($amount);
		$label = $this->buildLabel($line);
		$numChq = (string) (!empty($line['payment_reference']) ? $line['payment_reference'] : $line['bank_reference']);
		$statementRef = !empty($line['statement_ref']) ? (string) $line['statement_ref'] : '';
		$notePrivate = 'KreaBank staged import ' . dol_escape_htmltag((string) $line['source_type']) . ' / ' . dol_escape_htmltag((string) $line['source_file']);

		$newLineId = $account->addline(
			$dateo,
			$operationCode,
			$label,
			$amount,
			$numChq,
			0,
			$this->user,
			'',
			'',
			'',
			$datev,
			$statementRef,
			null,
			$notePrivate
		);
		if ($newLineId <= 0) {
			throw new Exception($this->langs->trans('ErrorFailedToCreateBankEntry'));
		}

		$okMeta = $this->insertMetaRow(
			(int) $newLineId,
			(int) $line['bank_account_id'],
			(string) $line['idempotency_hash'],
			$line,
			(string) $line['source_file'],
			(string) $line['source_type']
		);
		if (!$okMeta) {
			$lineObj = new AccountLine($this->db);
			if ($lineObj->fetch((int) $newLineId) > 0) {
				$lineObj->delete($this->user, 1);
			}
			throw new Exception($this->db->lasterror());
		}

		$updateSql = 'UPDATE ' . $this->db->prefix() . 'kreabank_statement_line';
		$updateSql .= ' SET fk_native_bank_line = ' . ((int) $newLineId);
		$updateSql .= ' WHERE entity = ' . ((int) $this->entity);
		$updateSql .= ' AND rowid = ' . ((int) $lineId);
		if (!$this->db->query($updateSql)) {
			throw new Exception($this->db->lasterror());
		}

		return (int) $newLineId;
	}

	/**
	 * Mark one imported line as reconciled.
	 *
	 * @param int $lineId
	 * @param float $allocatedAmount
	 * @param int $nativeLineId
	 * @return bool
	 */
	public function markStatementLineReconciled($lineId, $allocatedAmount = 0.0, $nativeLineId = 0)
	{
		$this->ensureMetaTable();
		$this->ensureStagingTables();
		$lineId = (int) $lineId;
		if ($lineId <= 0) {
			return false;
		}

		$nativeLineId = (int) $nativeLineId;
		$hasStagedRow = false;
		$sqlStageMap = 'SELECT fk_native_bank_line';
		$sqlStageMap .= ' FROM ' . $this->db->prefix() . 'kreabank_statement_line';
		$sqlStageMap .= ' WHERE entity = ' . ((int) $this->entity);
		$sqlStageMap .= ' AND rowid = ' . $lineId;
		$sqlStageMap .= $this->db->plimit(1, 0);
		$resStageMap = $this->db->query($sqlStageMap);
		if ($resStageMap && ($objStageMap = $this->db->fetch_object($resStageMap))) {
			$hasStagedRow = true;
			if ($nativeLineId <= 0) {
				$nativeLineId = (int) $objStageMap->fk_native_bank_line;
			}
		}
		if ($nativeLineId <= 0 && !$hasStagedRow) {
			$nativeLineId = $lineId;
		}

		$metaExpected = ($nativeLineId > 0 && $this->hasManagedNativeLine($nativeLineId));
		$metaUpdated = false;
		if ($metaExpected) {
			$sqlMeta = 'UPDATE ' . $this->db->prefix() . 'kreabank_bankmeta';
			$sqlMeta .= ' SET status = 2';
			$sqlMeta .= ' WHERE entity = ' . ((int) $this->entity);
			$sqlMeta .= ' AND fk_bank_line = ' . ((int) $nativeLineId);
			if (!$this->db->query($sqlMeta)) {
				return false;
			}
			$metaUpdated = true;
		}

		$stagingUpdated = false;
		if ($hasStagedRow) {
			$sqlStage = 'UPDATE ' . $this->db->prefix() . 'kreabank_statement_line';
			$sqlStage .= ' SET status = 2,';
			$sqlStage .= ' allocated_amount = ' . price2num((string) $allocatedAmount, 'MU');
			if ($nativeLineId > 0) {
				$sqlStage .= ', fk_native_bank_line = ' . ((int) $nativeLineId);
			}
			$sqlStage .= ' WHERE entity = ' . ((int) $this->entity);
			$sqlStage .= ' AND rowid = ' . $lineId;
			if (!$this->db->query($sqlStage)) {
				return false;
			}
			$stagingUpdated = true;
		}

		if ($metaExpected && !$metaUpdated) {
			return false;
		}
		if ($hasStagedRow && !$stagingUpdated) {
			return false;
		}

		return ($metaExpected || $hasStagedRow);
	}

	/**
	 * Mark one imported line back to pending.
	 *
	 * @param int $lineId
	 * @return bool
	 */
	public function markStatementLinePending($lineId)
	{
		$this->ensureMetaTable();
		$this->ensureStagingTables();
		$lineId = (int) $lineId;
		if ($lineId <= 0) {
			return false;
		}
		$nativeLineId = 0;
		$hasStagedRow = false;
		$clearNativeLineBinding = false;
		$line = $this->getStagedLineById($lineId);
		if (!empty($line)) {
			$hasStagedRow = true;
		}
		if (!empty($line['native_bank_line_id'])) {
			$nativeLineId = (int) $line['native_bank_line_id'];
			if ($nativeLineId > 0 && !$this->hasManagedNativeLine($nativeLineId)) {
				$clearNativeLineBinding = true;
			}
		}
		if ($nativeLineId <= 0 && !$hasStagedRow) {
			$nativeLineId = $lineId;
		}

		$metaUpdated = false;
		if ($nativeLineId > 0 && $this->hasManagedNativeLine($nativeLineId)) {
			$sqlMeta = 'UPDATE ' . $this->db->prefix() . 'kreabank_bankmeta';
			$sqlMeta .= ' SET status = 0';
			$sqlMeta .= ' WHERE entity = ' . ((int) $this->entity);
			$sqlMeta .= ' AND fk_bank_line = ' . ((int) $nativeLineId);
			if (!$this->db->query($sqlMeta)) {
				return false;
			}
			$metaUpdated = true;
		}

		$stagingUpdated = false;
		if ($hasStagedRow) {
			$sql = 'UPDATE ' . $this->db->prefix() . 'kreabank_statement_line';
			$sql .= ' SET status = 0, allocated_amount = 0';
			if ($clearNativeLineBinding) {
				$sql .= ' , fk_native_bank_line = NULL';
			}
			$sql .= ' WHERE entity = ' . ((int) $this->entity);
			$sql .= ' AND rowid = ' . $lineId;

			if (!$this->db->query($sql)) {
				return false;
			}
			$stagingUpdated = true;
		}

		return ($metaUpdated || $stagingUpdated);
	}

	/**
	 * Mark one imported line as manually skipped/deferred.
	 *
	 * @param int $lineId
	 * @param int $nativeLineId
	 * @return bool
	 */
	public function markStatementLineSkipped($lineId, $nativeLineId = 0)
	{
		$this->ensureMetaTable();
		$this->ensureStagingTables();
		$lineId = (int) $lineId;
		if ($lineId <= 0) {
			return false;
		}

		$nativeLineId = (int) $nativeLineId;
		$hasStagedRow = false;
		$sqlStageMap = 'SELECT fk_native_bank_line';
		$sqlStageMap .= ' FROM ' . $this->db->prefix() . 'kreabank_statement_line';
		$sqlStageMap .= ' WHERE entity = ' . ((int) $this->entity);
		$sqlStageMap .= ' AND rowid = ' . $lineId;
		$sqlStageMap .= $this->db->plimit(1, 0);
		$resStageMap = $this->db->query($sqlStageMap);
		if ($resStageMap && ($objStageMap = $this->db->fetch_object($resStageMap))) {
			$hasStagedRow = true;
			if ($nativeLineId <= 0) {
				$nativeLineId = (int) $objStageMap->fk_native_bank_line;
			}
		}
		if ($nativeLineId <= 0 && !$hasStagedRow) {
			$nativeLineId = $lineId;
		}

		$metaUpdated = false;
		if ($nativeLineId > 0 && $this->hasManagedNativeLine($nativeLineId)) {
			$sqlMeta = 'UPDATE ' . $this->db->prefix() . 'kreabank_bankmeta';
			$sqlMeta .= ' SET status = 3';
			$sqlMeta .= ' WHERE entity = ' . ((int) $this->entity);
			$sqlMeta .= ' AND fk_bank_line = ' . ((int) $nativeLineId);
			if (!$this->db->query($sqlMeta)) {
				return false;
			}
			$metaUpdated = true;
		}

		$stagingUpdated = false;
		if ($hasStagedRow) {
			$sqlStage = 'UPDATE ' . $this->db->prefix() . 'kreabank_statement_line';
			$sqlStage .= ' SET status = 3, allocated_amount = 0';
			if ($nativeLineId > 0) {
				$sqlStage .= ', fk_native_bank_line = ' . ((int) $nativeLineId);
			}
			$sqlStage .= ' WHERE entity = ' . ((int) $this->entity);
			$sqlStage .= ' AND rowid = ' . $lineId;
			if (!$this->db->query($sqlStage)) {
				return false;
			}
			$stagingUpdated = true;
		}

		return ($metaUpdated || $stagingUpdated);
	}

	/**
	 * Remove KreaBank skip note markers from one native bank line note.
	 *
	 * @param int $lineId
	 * @param string $skipPrefix
	 * @return bool
	 */
	public function clearSkipNoteFromNativeLine($lineId, $skipPrefix = 'KreaBank skip:')
	{
		$lineId = (int) $lineId;
		if ($lineId <= 0) {
			return false;
		}

		$skipPrefix = trim((string) $skipPrefix);
		if ($skipPrefix === '') {
			$skipPrefix = 'KreaBank skip:';
		}

		$sql = 'SELECT b.note as native_note';
		$sql .= ' FROM ' . $this->db->prefix() . 'bank as b';
		$sql .= ' INNER JOIN ' . $this->db->prefix() . 'bank_account as ba ON ba.rowid = b.fk_account';
		$sql .= ' WHERE b.rowid = ' . $lineId;
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

		$currentNote = (string) (!empty($obj->native_note) ? $obj->native_note : '');
		if (trim((string) $currentNote) === '') {
			return true;
		}

		$noteLines = preg_split('/\r\n|\r|\n/', (string) $currentNote);
		$filteredLines = array();
		$removed = false;
		foreach ((array) $noteLines as $noteLine) {
			$lineText = (string) $noteLine;
			$lineNormalized = ltrim($lineText);
			if ($lineNormalized !== '' && stripos($lineNormalized, $skipPrefix) === 0) {
				$removed = true;
				continue;
			}
			$filteredLines[] = $lineText;
		}

		if (!$removed) {
			return true;
		}

		$newNote = trim(implode("\n", $filteredLines));
		$update = 'UPDATE ' . $this->db->prefix() . 'bank';
		$update .= ' SET note = ' . $this->sqlText($newNote);
		$update .= ' WHERE rowid = ' . $lineId;
		$update .= ' AND fk_account IN (SELECT ba.rowid FROM ' . $this->db->prefix() . 'bank_account as ba WHERE ba.entity = ' . ((int) $this->entity) . ')';

		return (bool) $this->db->query($update);
	}

	/**
	 * Conciliate one native bank line.
	 *
	 * @param int $lineId
	 * @param string $statementLabel
	 * @param int $categoryId
	 * @return int
	 */
	public function conciliateLine($lineId, $statementLabel = '', $categoryId = 0)
	{
		$lineId = (int) $lineId;
		if ($lineId <= 0) {
			return -1;
		}

		$line = new AccountLine($this->db);
		if ($line->fetch($lineId) <= 0) {
			return -1;
		}
		$line->num_releve = $statementLabel !== '' ? $statementLabel : $this->buildStatementLabel();

		// Transaction dependency:
		// callers must persist KreaBank meta/staging reconciliation status in the same DB transaction
		// (see KreaBankService::reconcileLine -> markStatementLineReconciled) to keep native + helper tables coherent.
		return (int) $line->update_conciliation($this->user, (int) $categoryId, 1);
	}

	/**
	 * Undo conciliation of one native bank line.
	 *
	 * @param int $lineId
	 * @return int
	 */
	public function unconciliateLine($lineId)
	{
		$lineId = (int) $lineId;
		if ($lineId <= 0) {
			return -1;
		}

		$line = new AccountLine($this->db);
		if ($line->fetch($lineId) <= 0) {
			return -1;
		}
		$line->num_releve = '';

		return (int) $line->update_conciliation($this->user, 0, 0);
	}

	/**
	 * Add a native bank_url link to a bank line.
	 *
	 * @param int $lineId
	 * @param int $urlId
	 * @param string $url
	 * @param string $label
	 * @param string $type
	 * @return int
	 */
	public function addLineLink($lineId, $urlId, $url, $label, $type)
	{
		$account = new Account($this->db);
		return (int) $account->add_url_line((int) $lineId, (int) $urlId, (string) $url, (string) $label, (string) $type);
	}

	/**
	 * Return native links for one statement/native line.
	 *
	 * @param int $lineId
	 * @return array<int,array<string,mixed>>
	 */
	public function getLineLinks($lineId)
	{
		$lineId = (int) $lineId;
		if ($lineId <= 0) {
			return array();
		}
		$linksByLine = $this->getLineLinksBatch(array($lineId));

		return (!empty($linksByLine[$lineId]) && is_array($linksByLine[$lineId])) ? $linksByLine[$lineId] : array();
	}

	/**
	 * Return native links for multiple statement/native lines.
	 *
	 * @param array<int,int> $lineIds
	 * @return array<int,array<int,array<string,mixed>>>
	 */
	public function getLineLinksBatch($lineIds)
	{
		$out = array();
		$normalizedLineIds = array();
		foreach ((array) $lineIds as $rawLineId) {
			$lineId = (int) $rawLineId;
			if ($lineId <= 0) {
				continue;
			}
			$normalizedLineIds[$lineId] = $lineId;
			$out[$lineId] = array();
		}
		if (empty($normalizedLineIds)) {
			return $out;
		}

		$this->ensureStagingTables();
		$lineIdSql = implode(',', $normalizedLineIds);
		$lineToNative = array();
		$resolvedAsStaged = array();
		foreach ($normalizedLineIds as $lineId) {
			if ($this->hasManagedNativeLine((int) $lineId)) {
				$lineToNative[(int) $lineId] = (int) $lineId;
			}
		}
		$sqlLineMap = 'SELECT l.rowid as line_id, l.fk_native_bank_line as native_line_id';
		$sqlLineMap .= ' FROM ' . $this->db->prefix() . 'kreabank_statement_line as l';
		$sqlLineMap .= ' WHERE l.entity = ' . ((int) $this->entity);
		$sqlLineMap .= ' AND l.rowid IN (' . $lineIdSql . ')';
		$resLineMap = $this->db->query($sqlLineMap);
		if (!$resLineMap) {
			throw new Exception($this->db->lasterror());
		}
		while ($objMap = $this->db->fetch_object($resLineMap)) {
			$lineId = (int) $objMap->line_id;
			if (isset($lineToNative[$lineId])) {
				continue;
			}
			$nativeLineId = (int) $objMap->native_line_id;
			$resolvedAsStaged[$lineId] = true;
			if ($nativeLineId > 0) {
				$lineToNative[$lineId] = $nativeLineId;
			}
		}

		// Legacy-native calls can still send native line ids directly.
		foreach ($normalizedLineIds as $lineId) {
			if (!isset($resolvedAsStaged[$lineId])) {
				$lineToNative[$lineId] = (int) $lineId;
			}
		}
		if (empty($lineToNative)) {
			return $out;
		}

		$nativeToLineIds = array();
		foreach ($lineToNative as $lineId => $nativeLineId) {
			if ($nativeLineId <= 0) {
				continue;
			}
			if (!isset($nativeToLineIds[$nativeLineId])) {
				$nativeToLineIds[$nativeLineId] = array();
			}
			$nativeToLineIds[$nativeLineId][] = (int) $lineId;
		}
		if (empty($nativeToLineIds)) {
			return $out;
		}

		$nativeIds = array_map('intval', array_keys($nativeToLineIds));
		$nativeIdSql = implode(',', $nativeIds);
		$lineDateByNative = array();
		$sqlDates = 'SELECT rowid, dateo';
		$sqlDates .= ' FROM ' . $this->db->prefix() . 'bank';
		$sqlDates .= ' WHERE rowid IN (' . $nativeIdSql . ')';
		$resDates = $this->db->query($sqlDates);
		if (!$resDates) {
			throw new Exception($this->db->lasterror());
		}
		while ($objDate = $this->db->fetch_object($resDates)) {
			$lineDateByNative[(int) $objDate->rowid] = $objDate->dateo;
		}

		$linksByNative = array();
		$sqlLinks = 'SELECT fk_bank, url_id, url, label, type';
		$sqlLinks .= ' FROM ' . $this->db->prefix() . 'bank_url';
		$sqlLinks .= ' WHERE fk_bank IN (' . $nativeIdSql . ')';
		$sqlLinks .= ' ORDER BY fk_bank ASC, rowid ASC';
		$resLinks = $this->db->query($sqlLinks);
		if (!$resLinks) {
			throw new Exception($this->db->lasterror());
		}
		while ($objLink = $this->db->fetch_object($resLinks)) {
			$nativeLineId = (int) $objLink->fk_bank;
			if (!isset($linksByNative[$nativeLineId])) {
				$linksByNative[$nativeLineId] = array();
			}
			$linksByNative[$nativeLineId][] = array(
				'rowid' => 0,
				'fk_reconciliation' => 0,
				'doc_type' => (string) (!empty($objLink->type) ? $objLink->type : ''),
				'fk_doc' => (int) (!empty($objLink->url_id) ? $objLink->url_id : 0),
				'doc_ref' => (string) (!empty($objLink->label) ? $objLink->label : ''),
				'allocated_amount' => 0.0,
				'match_score' => 0,
				'match_reasons' => '',
				'is_reversed' => 0,
				'strategy' => 'native',
				'date_validate' => (!empty($lineDateByNative[$nativeLineId]) ? $lineDateByNative[$nativeLineId] : null),
				'url' => (string) (!empty($objLink->url) ? $objLink->url : ''),
			);
		}

		foreach ($nativeToLineIds as $nativeLineId => $relatedLineIds) {
			$nativeLinks = !empty($linksByNative[$nativeLineId]) ? (array) $linksByNative[$nativeLineId] : array();
			foreach ((array) $relatedLineIds as $lineId) {
				$lineId = (int) $lineId;
				$out[$lineId] = $nativeLinks;
			}
		}

		return $out;
	}

	/**
	 * Check if an idempotency hash already exists for account.
	 *
	 * @param int $bankAccountId
	 * @param string $hash
	 * @param string $source
	 * @return bool
	 */
	public function hasHash($bankAccountId, $hash, &$source = '')
	{
		$this->ensureMetaTable();
		$this->ensureStagingTables();

		if ($hash === '') {
			return false;
		}
		$source = '';

		$sqlStage = 'SELECT l.rowid';
		$sqlStage .= ' FROM ' . $this->db->prefix() . 'kreabank_statement_line as l';
		$sqlStage .= ' INNER JOIN ' . $this->db->prefix() . 'kreabank_statement as s ON s.rowid = l.fk_statement';
		$sqlStage .= ' WHERE l.entity = ' . ((int) $this->entity);
		$sqlStage .= ' AND s.entity = ' . ((int) $this->entity);
		$sqlStage .= ' AND s.bank_account_id = ' . ((int) $bankAccountId);
		$sqlStage .= ' AND l.duplicate_hash = ' . $this->sqlText($hash);
		$sqlStage .= $this->db->plimit(1, 0);
		$resStage = $this->db->query($sqlStage);
		if ($resStage && $this->db->fetch_object($resStage)) {
			$source = 'staging';
			return true;
		}

		$sqlMeta = 'SELECT rowid FROM ' . $this->db->prefix() . 'kreabank_bankmeta';
		$sqlMeta .= ' WHERE entity = ' . ((int) $this->entity);
		$sqlMeta .= ' AND bank_account_id = ' . ((int) $bankAccountId);
		$sqlMeta .= ' AND idempotency_hash = ' . $this->sqlText($hash);
		$sqlMeta .= $this->db->plimit(1, 0);
		$resMeta = $this->db->query($sqlMeta);
		if ($resMeta && $this->db->fetch_object($resMeta)) {
			$source = 'native_meta';
			return true;
		}

		return false;
	}

	/**
	 * Check whether one native bank line is managed by KreaBank bankmeta.
	 *
	 * @param int $nativeLineId
	 * @return bool
	 */
	protected function hasManagedNativeLine($nativeLineId)
	{
		$this->ensureMetaTable();
		$nativeLineId = (int) $nativeLineId;
		if ($nativeLineId <= 0) {
			return false;
		}

		$sql = 'SELECT rowid FROM ' . $this->db->prefix() . 'kreabank_bankmeta';
		$sql .= ' WHERE entity = ' . ((int) $this->entity);
		$sql .= ' AND fk_bank_line = ' . $nativeLineId;
		$sql .= $this->db->plimit(1, 0);
		$resql = $this->db->query($sql);

		return (bool) ($resql && $this->db->fetch_object($resql));
	}

	/**
	 * Delete one imported statement.
	 * Staged rows are removed first; legacy native import is supported as fallback.
	 *
	 * @param int $bankAccountId
	 * @param string $statementRef
	 * @param bool $allowReconciled
	 * @return array<string,int|string>
	 */
	public function deleteImportedStatement($bankAccountId, $statementRef, $allowReconciled = false)
	{
		$this->ensureMetaTable();
		$this->ensureStagingTables();

		$bankAccountId = (int) $bankAccountId;
		$statementRef = trim((string) $statementRef);
		if ($bankAccountId <= 0 || $statementRef === '') {
			return array(
				'deleted_lines' => 0,
				'reconciled_lines' => 0,
				'statement_ref' => $statementRef,
				'bank_account_id' => $bankAccountId,
			);
		}

		$sqlStatement = 'SELECT rowid FROM ' . $this->db->prefix() . 'kreabank_statement';
		$sqlStatement .= ' WHERE entity = ' . ((int) $this->entity);
		$sqlStatement .= ' AND bank_account_id = ' . $bankAccountId;
		$sqlStatement .= ' AND ref = ' . $this->sqlText($statementRef);
		$sqlStatement .= $this->db->plimit(1, 0);
		$resStatement = $this->db->query($sqlStatement);
		$objStatement = ($resStatement ? $this->db->fetch_object($resStatement) : null);
		if (empty($objStatement->rowid)) {
			return $this->deleteLegacyImportedStatement($bankAccountId, $statementRef, (bool) $allowReconciled);
		}
		$statementId = (int) $objStatement->rowid;

		$sqlLines = 'SELECT l.rowid, l.fk_native_bank_line, l.status, b.rappro';
		$sqlLines .= ' FROM ' . $this->db->prefix() . 'kreabank_statement_line as l';
		$sqlLines .= ' LEFT JOIN ' . $this->db->prefix() . 'bank as b ON b.rowid = l.fk_native_bank_line';
		$sqlLines .= ' WHERE l.entity = ' . ((int) $this->entity);
		$sqlLines .= ' AND l.fk_statement = ' . $statementId;
		$sqlLines .= ' ORDER BY l.rowid ASC';
		$resLines = $this->db->query($sqlLines);
		if (!$resLines) {
			throw new Exception($this->db->lasterror());
		}

		$rows = array();
		$reconciledCount = 0;
		while ($obj = $this->db->fetch_object($resLines)) {
			$rows[] = $obj;
			$isReconciled = ((int) $obj->status === 2 || (int) $obj->rappro === 1);
			if ($isReconciled) {
				$reconciledCount++;
			}
		}

		if (empty($rows)) {
			if (!$this->db->query('DELETE FROM ' . $this->db->prefix() . 'kreabank_statement WHERE entity = ' . ((int) $this->entity) . ' AND rowid = ' . $statementId)) {
				throw new Exception($this->db->lasterror());
			}
			return array(
				'deleted_lines' => 0,
				'reconciled_lines' => 0,
				'statement_ref' => $statementRef,
				'bank_account_id' => $bankAccountId,
			);
		}

		if ($reconciledCount > 0 && !$allowReconciled) {
			return array(
				'deleted_lines' => 0,
				'reconciled_lines' => $reconciledCount,
				'statement_ref' => $statementRef,
				'bank_account_id' => $bankAccountId,
				'blocked' => 1,
			);
		}

		$deleted = 0;
		if (!$this->db->begin()) {
			throw new Exception('Failed to start database transaction');
		}

		try {
			foreach ($rows as $obj) {
				$lineId = (int) $obj->rowid;
				$nativeLineId = (int) $obj->fk_native_bank_line;
				if ($nativeLineId > 0 && $this->hasManagedNativeLine($nativeLineId)) {
					if (!$this->db->query('DELETE FROM ' . $this->db->prefix() . 'kreabank_bankmeta WHERE entity = ' . ((int) $this->entity) . ' AND fk_bank_line = ' . $nativeLineId)) {
						throw new Exception($this->db->lasterror());
					}
					$line = new AccountLine($this->db);
					if ($line->fetch($nativeLineId) > 0) {
						if ((int) $line->rappro === 1 && $allowReconciled) {
							$line->num_releve = '';
							if ((int) $line->update_conciliation($this->user, 0, 0) <= 0) {
								throw new Exception($this->db->lasterror());
							}
						}
						if ((int) $line->delete($this->user, 1) <= 0) {
							throw new Exception($this->db->lasterror());
						}
					}
				}

				if (!$this->db->query('DELETE FROM ' . $this->db->prefix() . 'kreabank_statement_line WHERE entity = ' . ((int) $this->entity) . ' AND rowid = ' . $lineId)) {
					throw new Exception($this->db->lasterror());
				}

				$deleted++;
			}

			if (!$this->db->query('DELETE FROM ' . $this->db->prefix() . 'kreabank_statement WHERE entity = ' . ((int) $this->entity) . ' AND rowid = ' . $statementId)) {
				throw new Exception($this->db->lasterror());
			}

			$this->db->commit();
		} catch (Exception $e) {
			$this->db->rollback();
			throw $e;
		}

		return array(
			'deleted_lines' => $deleted,
			'reconciled_lines' => $reconciledCount,
			'statement_ref' => $statementRef,
			'bank_account_id' => $bankAccountId,
		);
	}

	/**
	 * Build idempotency hash for one import line.
	 *
	 * @param int $bankAccountId
	 * @param array<string,mixed> $line
	 * @return string
	 */
	public function buildIdempotencyHash($bankAccountId, $line)
	{
		$date = (string) (!empty($line['operation_date']) ? $line['operation_date'] : $line['value_date']);
		$amount = price2num((string) (float) $line['amount'], 'MU');
		$reference = kreabankNormalizeText((string) (!empty($line['payment_reference']) ? $line['payment_reference'] : ''));
		$bankReference = kreabankNormalizeText((string) (!empty($line['bank_reference']) ? $line['bank_reference'] : ''));
		$description = kreabankNormalizeText((string) $line['description']);
		$operationType = kreabankNormalizeText((string) (!empty($line['operation_type']) ? $line['operation_type'] : ''));

		$payload = array(
			$this->entity,
			(int) $bankAccountId,
			$date,
			$amount,
			$reference,
			$bankReference,
			$operationType,
			$description,
		);

		return hash('sha256', implode('|', $payload));
	}

	/**
	 * Build the legacy idempotency hash payload used before 1.12.34.
	 * Kept for backward-compatible duplicate detection against historical rows.
	 *
	 * @param int $bankAccountId
	 * @param array<string,mixed> $line
	 * @return string
	 */
	protected function buildLegacyIdempotencyHash($bankAccountId, $line)
	{
		$date = (string) (!empty($line['operation_date']) ? $line['operation_date'] : $line['value_date']);
		$amount = price2num((string) (float) $line['amount'], 'MU');
		$reference = kreabankNormalizeText((string) (!empty($line['payment_reference']) ? $line['payment_reference'] : $line['bank_reference']));
		$description = kreabankNormalizeText((string) $line['description']);

		$payload = array(
			$this->entity,
			(int) $bankAccountId,
			$date,
			$amount,
			$reference,
			$description,
		);

		return hash('sha256', implode('|', $payload));
	}

	/**
	 * Build one alternate hash for user-forced duplicate import.
	 *
	 * @param string $baseHash
	 * @param string $statementRef
	 * @param int $lineRank
	 * @param string $lineUid
	 * @return string
	 */
	protected function buildForcedIdempotencyHash($baseHash, $statementRef, $lineRank, $lineUid = '')
	{
		$payload = array(
			'forced',
			$this->entity,
			(string) $baseHash,
			(string) $statementRef,
			(int) $lineRank,
			(string) $lineUid,
		);

		return hash('sha256', implode('|', $payload));
	}

	/**
	 * Get recent reconciled imported lines.
	 *
	 * @param int $limit
	 * @param int $offset
	 * @return array<int,array<string,mixed>>
	 */
	public function getRecentReconciledLines($limit = 200, $offset = 0)
	{
		$this->ensureMetaTable();

		$sql = 'SELECT b.rowid, b.rowid as fk_native_bank_line,';
		$sql .= ' COALESCE(l.operation_date, b.dateo) as operation_date, COALESCE(l.value_date, b.datev) as value_date,';
		$sql .= ' CASE';
		$sql .= ' WHEN l.direction < 0 THEN -ABS(COALESCE(l.amount, b.amount))';
		$sql .= ' WHEN l.direction > 0 THEN ABS(COALESCE(l.amount, b.amount))';
		$sql .= ' ELSE COALESCE(l.amount, b.amount)';
		$sql .= ' END as amount,';
		$sql .= " COALESCE(l.currency, ba.currency_code, 'EUR') as currency,";
		$sql .= ' l.counterparty_name, COALESCE(l.description, b.label) as description, l.payment_reference';
		$sql .= ' FROM ' . $this->db->prefix() . 'kreabank_bankmeta as l';
		$sql .= ' INNER JOIN ' . $this->db->prefix() . 'bank as b ON b.rowid = l.fk_bank_line';
		$sql .= ' INNER JOIN ' . $this->db->prefix() . 'bank_account as ba ON ba.rowid = b.fk_account';
		$sql .= ' WHERE l.entity = ' . ((int) $this->entity);
		$sql .= ' AND ba.entity = ' . ((int) $this->entity);
		$sql .= ' AND (l.status = 2 OR b.rappro = 1)';
		$sql .= ' ORDER BY b.dateo DESC, b.rowid DESC';
		$sql .= $this->db->plimit((int) $limit, (int) $offset);

		$resql = $this->db->query($sql);
		if (!$resql) {
			throw new Exception($this->db->lasterror());
		}

		$rows = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$rows[] = array(
				'rowid' => (int) $obj->rowid,
				'fk_statement_line' => (int) $obj->rowid,
				'native_bank_line_id' => (int) $obj->fk_native_bank_line,
				'confidence_score' => 0,
				'strategy' => 'native',
				'is_auto' => 0,
				'note' => '',
				'date_validate' => (!empty($obj->value_date) ? $obj->value_date : $obj->operation_date),
				'is_reversed' => 0,
				'reverse_reason' => '',
				'amount' => (float) $obj->amount,
				'currency' => (string) (!empty($obj->currency) ? $obj->currency : 'EUR'),
				'counterparty_name' => (string) (!empty($obj->counterparty_name) ? $obj->counterparty_name : ''),
				'description' => (string) (!empty($obj->description) ? $obj->description : ''),
				'payment_reference' => (string) (!empty($obj->payment_reference) ? $obj->payment_reference : ''),
				'login' => '',
			);
		}

		if (!empty($rows)) {
			return $rows;
		}

		// Backward compatibility path for 1.11.x datasets:
		// when no native/meta imported lines exist yet, expose reconciled staged rows in history.
		if ($this->hasAnyNativeMetaImportedLines()) {
			return array();
		}

		return $this->getRecentReconciledLinesFromStaging((int) $limit, (int) $offset);
	}

	/**
	 * Get recent reconciled lines from legacy staged tables (1.11.x compatibility path).
	 *
	 * @param int $limit
	 * @param int $offset
	 * @return array<int,array<string,mixed>>
	 */
	protected function getRecentReconciledLinesFromStaging($limit = 200, $offset = 0)
	{
		$this->ensureStagingTables();
		$this->ensureMetaTable();

		$sql = 'SELECT l.rowid, l.rowid as fk_statement_line, l.fk_native_bank_line,';
		$sql .= ' COALESCE(l.operation_date, b.dateo) as operation_date, COALESCE(l.value_date, b.datev) as value_date,';
		$sql .= ' l.amount,';
		$sql .= " COALESCE(l.currency, ba.currency_code, 'EUR') as currency,";
		$sql .= ' l.counterparty_name, COALESCE(l.description, b.label) as description, l.payment_reference';
		$sql .= ' FROM ' . $this->db->prefix() . 'kreabank_statement_line as l';
		$sql .= ' INNER JOIN ' . $this->db->prefix() . 'kreabank_statement as s ON s.rowid = l.fk_statement';
		$sql .= ' INNER JOIN ' . $this->db->prefix() . 'bank_account as ba ON ba.rowid = s.bank_account_id';
		$sql .= ' LEFT JOIN ' . $this->db->prefix() . 'bank as b ON b.rowid = l.fk_native_bank_line';
		$sql .= ' LEFT JOIN ' . $this->db->prefix() . 'kreabank_bankmeta as m ON m.entity = ' . ((int) $this->entity) . ' AND m.fk_bank_line = l.fk_native_bank_line';
		$sql .= ' WHERE l.entity = ' . ((int) $this->entity);
		$sql .= ' AND s.entity = ' . ((int) $this->entity);
		$sql .= ' AND ba.entity = ' . ((int) $this->entity);
		$sql .= ' AND m.rowid IS NULL';
		$sql .= ' AND (l.status = 2 OR (b.rowid IS NOT NULL AND b.rappro = 1))';
		$sql .= ' ORDER BY COALESCE(b.dateo, l.operation_date, l.value_date) DESC, l.rowid DESC';
		$sql .= $this->db->plimit((int) $limit, (int) $offset);

		$resql = $this->db->query($sql);
		if (!$resql) {
			throw new Exception($this->db->lasterror());
		}

		$rows = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$rows[] = array(
				'rowid' => (int) $obj->rowid,
				'fk_statement_line' => (int) $obj->fk_statement_line,
				'native_bank_line_id' => (int) (!empty($obj->fk_native_bank_line) ? $obj->fk_native_bank_line : 0),
				'confidence_score' => 0,
				'strategy' => 'legacy_staging',
				'is_auto' => 0,
				'note' => '',
				'date_validate' => (!empty($obj->value_date) ? $obj->value_date : $obj->operation_date),
				'is_reversed' => 0,
				'reverse_reason' => '',
				'amount' => (float) $obj->amount,
				'currency' => (string) (!empty($obj->currency) ? $obj->currency : 'EUR'),
				'counterparty_name' => (string) (!empty($obj->counterparty_name) ? $obj->counterparty_name : ''),
				'description' => (string) (!empty($obj->description) ? $obj->description : ''),
				'payment_reference' => (string) (!empty($obj->payment_reference) ? $obj->payment_reference : ''),
				'login' => '',
			);
		}

		return $rows;
	}

	/**
	 * Build WHERE SQL fragment for pending-line filters.
	 *
	 * @param array<string,mixed> $filters
	 * @return string
	 */
	protected function buildPendingLinesFilterSql($filters = array())
	{
		$sql = '';
		if (!is_array($filters) || empty($filters)) {
			return $sql;
		}

		$searchAll = trim((string) (isset($filters['search_all']) ? $filters['search_all'] : ''));
		$searchCounterparty = trim((string) (isset($filters['search_counterparty']) ? $filters['search_counterparty'] : ''));
		$searchDescription = trim((string) (isset($filters['search_description']) ? $filters['search_description'] : ''));
		$searchReference = trim((string) (isset($filters['search_reference']) ? $filters['search_reference'] : ''));
		$searchAmount = trim((string) (isset($filters['search_amount']) ? $filters['search_amount'] : ''));
		$searchDirection = trim((string) (isset($filters['search_direction']) ? $filters['search_direction'] : ''));
		$searchDateFrom = trim((string) (isset($filters['search_date_from']) ? $filters['search_date_from'] : ''));
		$searchDateTo = trim((string) (isset($filters['search_date_to']) ? $filters['search_date_to'] : ''));

		if ($searchAll !== '') {
			$likeValue = "%" . $this->db->escape($searchAll) . "%";
			$sql .= " AND (";
			$sql .= "l.counterparty_name LIKE '" . $likeValue . "'";
			$sql .= " OR l.counterparty_iban LIKE '" . $likeValue . "'";
			$sql .= " OR l.description LIKE '" . $likeValue . "'";
			$sql .= " OR l.payment_reference LIKE '" . $likeValue . "'";
			$sql .= " OR l.bank_reference LIKE '" . $likeValue . "'";
			$sql .= " OR b.label LIKE '" . $likeValue . "'";
			$sql .= " OR b.note LIKE '" . $likeValue . "'";
			$sql .= ")";
		}
		if ($searchCounterparty !== '') {
			$counterpartyLike = "%" . $this->db->escape($searchCounterparty) . "%";
			$sql .= " AND (";
			$sql .= "l.counterparty_name LIKE '" . $counterpartyLike . "'";
			$sql .= " OR l.counterparty_iban LIKE '" . $counterpartyLike . "'";
			$sql .= ')';
		}
		if ($searchDescription !== '') {
			$descriptionLike = "%" . $this->db->escape($searchDescription) . "%";
			$sql .= " AND (";
			$sql .= "l.description LIKE '" . $descriptionLike . "'";
			$sql .= " OR b.label LIKE '" . $descriptionLike . "'";
			$sql .= " OR b.note LIKE '" . $descriptionLike . "'";
			$sql .= ')';
		}
		if ($searchReference !== '') {
			$referenceLike = "%" . $this->db->escape($searchReference) . "%";
			$sql .= " AND (";
			$sql .= "l.payment_reference LIKE '" . $referenceLike . "'";
			$sql .= " OR l.bank_reference LIKE '" . $referenceLike . "'";
			$sql .= ')';
		}
		if ($searchAmount !== '') {
			$amountValue = price2num($searchAmount, 'MU');
			if ($amountValue !== '' && is_numeric($amountValue)) {
				$sql .= ' AND ABS(ABS(l.amount) - ' . ((float) $amountValue) . ') <= 0.01';
			}
		}
		if ($searchDirection === 'debit') {
			$sql .= ' AND l.amount < -0.0000001';
		} elseif ($searchDirection === 'credit') {
			$sql .= ' AND l.amount > 0.0000001';
		}
		if ($searchDateFrom !== '' && preg_match('/^\d{4}\-\d{2}\-\d{2}$/', $searchDateFrom)) {
			$sql .= " AND l.operation_date >= '" . $this->db->escape($searchDateFrom) . "'";
		}
		if ($searchDateTo !== '' && preg_match('/^\d{4}\-\d{2}\-\d{2}$/', $searchDateTo)) {
			$sql .= " AND l.operation_date <= '" . $this->db->escape($searchDateTo) . "'";
		}

		return $sql;
	}

	/**
	 * Build status SQL fragment for pending-line queries.
	 *
	 * @param bool $includeSkipped
	 * @return string
	 */
	protected function buildPendingLinesStatusSql($includeSkipped = false)
	{
		if ((bool) $includeSkipped) {
			return ' AND (l.status < 2 OR l.status = 3)';
		}

		return ' AND l.status < 2';
	}

	/**
	 * Build ORDER BY SQL for pending-line list.
	 *
	 * @param string $sortfield
	 * @param string $sortorder
	 * @return string
	 */
	protected function buildPendingLinesOrderBySql($sortfield, $sortorder)
	{
		$allowedSortFields = array(
			'operation_date' => 'l.operation_date',
			'b.dateo' => 'l.operation_date',
			'm.counterparty_name' => 'l.counterparty_name',
			'm.description' => 'l.description',
			'b.amount' => 'l.amount',
			'm.running_balance' => 'l.running_balance',
			'm.payment_reference' => 'l.payment_reference',
			'b.rappro' => 'l.status',
			'b.rowid' => 'b.rowid',
		);
		$sortfield = trim((string) $sortfield);
		if (!isset($allowedSortFields[$sortfield])) {
			$sortfield = 'operation_date';
		}

		$sortorder = strtoupper(trim((string) $sortorder));
		if ($sortorder !== 'DESC') {
			$sortorder = 'ASC';
		}

		if ($sortfield === 'b.dateo' || $sortfield === 'operation_date') {
			return ' ORDER BY l.operation_date ' . $sortorder . ', l.rowid ' . $sortorder;
		}

		return ' ORDER BY ' . $allowedSortFields[$sortfield] . ' ' . $sortorder . ', l.operation_date ASC, l.rowid ASC';
	}

	/**
	 * Legacy native-mode statement deletion fallback.
	 *
	 * @param int $bankAccountId
	 * @param string $statementRef
	 * @param bool $allowReconciled
	 * @return array<string,int|string>
	 */
	protected function deleteLegacyImportedStatement($bankAccountId, $statementRef, $allowReconciled = false)
	{
		$sql = 'SELECT b.rowid, b.rappro';
		$sql .= ' FROM ' . $this->db->prefix() . 'bank as b';
		$sql .= ' INNER JOIN ' . $this->db->prefix() . 'bank_account as ba ON ba.rowid = b.fk_account';
		$sql .= ' INNER JOIN ' . $this->db->prefix() . 'kreabank_bankmeta as m ON m.entity = ' . ((int) $this->entity) . ' AND m.fk_bank_line = b.rowid';
		$sql .= ' WHERE ba.entity = ' . ((int) $this->entity);
		$sql .= ' AND b.fk_account = ' . ((int) $bankAccountId);
		$sql .= ' AND b.num_releve = ' . $this->sqlText($statementRef);
		$sql .= ' ORDER BY b.rowid ASC';
		$resql = $this->db->query($sql);
		if (!$resql) {
			throw new Exception($this->db->lasterror());
		}

		$lineIds = array();
		$reconciledCount = 0;
		while ($obj = $this->db->fetch_object($resql)) {
			$lineIds[] = (int) $obj->rowid;
			if ((int) $obj->rappro === 1) {
				$reconciledCount++;
			}
		}

		if (empty($lineIds)) {
			return array(
				'deleted_lines' => 0,
				'reconciled_lines' => 0,
				'statement_ref' => $statementRef,
				'bank_account_id' => $bankAccountId,
			);
		}

		if ($reconciledCount > 0 && !$allowReconciled) {
			return array(
				'deleted_lines' => 0,
				'reconciled_lines' => $reconciledCount,
				'statement_ref' => $statementRef,
				'bank_account_id' => $bankAccountId,
				'blocked' => 1,
			);
		}

		$deleted = 0;
		if (!$this->db->begin()) {
			throw new Exception('Failed to start database transaction');
		}

		try {
			foreach ($lineIds as $lineId) {
				if (!$this->db->query('DELETE FROM ' . $this->db->prefix() . 'kreabank_bankmeta WHERE entity = ' . ((int) $this->entity) . ' AND fk_bank_line = ' . $lineId)) {
					throw new Exception($this->db->lasterror());
				}

				$line = new AccountLine($this->db);
				if ($line->fetch($lineId) <= 0) {
					continue;
				}
				if ((int) $line->rappro === 1 && $allowReconciled) {
					$line->num_releve = '';
					if ((int) $line->update_conciliation($this->user, 0, 0) <= 0) {
						throw new Exception($this->db->lasterror());
					}
				}
				if ((int) $line->delete($this->user, 1) <= 0) {
					throw new Exception($this->db->lasterror());
				}
				$deleted++;
			}

			$this->db->commit();
		} catch (Exception $e) {
			$this->db->rollback();
			throw $e;
		}

		return array(
			'deleted_lines' => $deleted,
			'reconciled_lines' => $reconciledCount,
			'statement_ref' => $statementRef,
			'bank_account_id' => $bankAccountId,
		);
	}

	/**
	 * Ensure helper metadata table exists.
	 *
	 * @return void
	 */
	protected function ensureMetaTable()
	{
		if ($this->metaTableChecked) {
			return;
		}
		$this->metaTableChecked = true;

		$table = $this->db->prefix() . 'kreabank_bankmeta';
		$sql = 'CREATE TABLE IF NOT EXISTS ' . $table . ' (';
		$sql .= ' rowid INTEGER AUTO_INCREMENT PRIMARY KEY,';
		$sql .= ' entity INTEGER NOT NULL DEFAULT 1,';
		$sql .= ' fk_bank_line INTEGER NOT NULL,';
		$sql .= ' bank_account_id INTEGER NOT NULL DEFAULT 0,';
		$sql .= ' source_type VARCHAR(16) NULL,';
		$sql .= ' source_file VARCHAR(255) NULL,';
		$sql .= ' source_line_uid VARCHAR(128) NULL,';
		$sql .= ' idempotency_hash CHAR(64) NOT NULL,';
		$sql .= ' operation_date DATE NULL,';
		$sql .= ' value_date DATE NULL,';
		$sql .= ' amount DECIMAL(24,8) NOT NULL,';
		$sql .= " currency VARCHAR(3) NOT NULL DEFAULT 'EUR',";
		$sql .= ' running_balance DECIMAL(24,8) NULL,';
		$sql .= ' counterparty_name VARCHAR(255) NULL,';
		$sql .= ' counterparty_iban VARCHAR(64) NULL,';
		$sql .= ' description TEXT NULL,';
		$sql .= ' payment_reference VARCHAR(190) NULL,';
		$sql .= ' bank_reference VARCHAR(190) NULL,';
		$sql .= ' direction SMALLINT NOT NULL DEFAULT 0,';
		$sql .= ' status SMALLINT NOT NULL DEFAULT 0,';
		$sql .= ' datec DATETIME NOT NULL,';
		$sql .= ' tms TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,';
		$sql .= ' UNIQUE KEY uk_kreabank_bankmeta_hash (entity, bank_account_id, idempotency_hash),';
		$sql .= ' UNIQUE KEY uk_kreabank_bankmeta_line (entity, fk_bank_line),';
		$sql .= ' KEY idx_kreabank_bankmeta_date (operation_date),';
		$sql .= ' KEY idx_kreabank_bankmeta_status (entity, status)';
		$sql .= ' ) ENGINE=innodb';

		if (!$this->db->query($sql) && function_exists('dol_syslog')) {
			dol_syslog('KreaBank schema sync failed (create bankmeta): ' . $this->db->lasterror(), LOG_WARNING);
		}

		$this->ensureTableColumn($table, 'bank_account_id', 'INTEGER NOT NULL DEFAULT 0');
		$this->ensureTableColumn($table, 'source_type', 'VARCHAR(16) NULL');
		$this->ensureTableColumn($table, 'source_file', 'VARCHAR(255) NULL');
		$this->ensureTableColumn($table, 'source_line_uid', 'VARCHAR(128) NULL');
		$this->ensureTableColumn($table, 'operation_date', 'DATE NULL');
		$this->ensureTableColumn($table, 'value_date', 'DATE NULL');
		$this->ensureTableColumn($table, 'amount', 'DECIMAL(24,8) NOT NULL DEFAULT 0');
		$this->ensureTableColumn($table, 'currency', "VARCHAR(3) NOT NULL DEFAULT 'EUR'");
		$this->ensureTableColumn($table, 'running_balance', 'DECIMAL(24,8) NULL');
		$this->ensureTableColumn($table, 'counterparty_name', 'VARCHAR(255) NULL');
		$this->ensureTableColumn($table, 'counterparty_iban', 'VARCHAR(64) NULL');
		$this->ensureTableColumn($table, 'description', 'TEXT NULL');
		$this->ensureTableColumn($table, 'payment_reference', 'VARCHAR(190) NULL');
		$this->ensureTableColumn($table, 'bank_reference', 'VARCHAR(190) NULL');
		$this->ensureTableColumn($table, 'direction', 'SMALLINT NOT NULL DEFAULT 0');
		$this->ensureTableColumn($table, 'status', 'SMALLINT NOT NULL DEFAULT 0');
		$this->ensureTableColumn($table, 'datec', 'DATETIME NOT NULL');
		$this->ensureTableColumn($table, 'tms', 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');

		$this->ensureTableIndex($table, 'uk_kreabank_bankmeta_hash', 'UNIQUE INDEX uk_kreabank_bankmeta_hash (entity, bank_account_id, idempotency_hash)');
		$this->ensureTableIndex($table, 'uk_kreabank_bankmeta_line', 'UNIQUE INDEX uk_kreabank_bankmeta_line (entity, fk_bank_line)');
		$this->ensureTableIndex($table, 'idx_kreabank_bankmeta_date', 'INDEX idx_kreabank_bankmeta_date (operation_date)');
		$this->ensureTableIndex($table, 'idx_kreabank_bankmeta_status', 'INDEX idx_kreabank_bankmeta_status (entity, status)');
	}

	/**
	 * Ensure staged statement tables exist.
	 *
	 * @return void
	 */
	protected function ensureStagingTables()
	{
		if ($this->stagingTablesChecked) {
			return;
		}
		$this->stagingTablesChecked = true;

		$statementTable = $this->db->prefix() . 'kreabank_statement';
		$lineTable = $this->db->prefix() . 'kreabank_statement_line';

		$sqlStatement = 'CREATE TABLE IF NOT EXISTS ' . $statementTable . ' (';
		$sqlStatement .= ' rowid INTEGER AUTO_INCREMENT PRIMARY KEY,';
		$sqlStatement .= ' entity INTEGER NOT NULL DEFAULT 1,';
		$sqlStatement .= ' ref VARCHAR(64) NOT NULL,';
		$sqlStatement .= ' label VARCHAR(255) NULL,';
		$sqlStatement .= ' source_type VARCHAR(16) NOT NULL,';
		$sqlStatement .= ' filename VARCHAR(255) NULL,';
		$sqlStatement .= ' bank_account_id INTEGER NULL,';
		$sqlStatement .= ' statement_date DATE NULL,';
		$sqlStatement .= ' date_import DATETIME NOT NULL,';
		$sqlStatement .= ' fk_user_import INTEGER NOT NULL,';
		$sqlStatement .= ' status SMALLINT NOT NULL DEFAULT 0,';
		$sqlStatement .= " currency VARCHAR(3) NOT NULL DEFAULT 'EUR',";
		$sqlStatement .= ' external_id VARCHAR(128) NULL,';
		$sqlStatement .= ' last_balance DECIMAL(24,8) NULL,';
		$sqlStatement .= ' import_payload LONGTEXT NULL,';
		$sqlStatement .= ' tms TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,';
		$sqlStatement .= ' datec DATETIME NOT NULL,';
		$sqlStatement .= ' UNIQUE KEY uk_kreabank_statement_entity_ref (entity, ref),';
		$sqlStatement .= ' KEY idx_kreabank_statement_entity_date (entity, statement_date),';
		$sqlStatement .= ' KEY idx_kreabank_statement_bank (entity, bank_account_id)';
		$sqlStatement .= ' ) ENGINE=innodb';
		if (!$this->db->query($sqlStatement) && function_exists('dol_syslog')) {
			dol_syslog('KreaBank schema sync failed (create statement): ' . $this->db->lasterror(), LOG_WARNING);
		}

		$this->ensureTableColumn($statementTable, 'label', 'VARCHAR(255) NULL');
		$this->ensureTableColumn($statementTable, 'bank_account_id', 'INTEGER NULL');
		$this->ensureTableColumn($statementTable, 'statement_date', 'DATE NULL');
		$this->ensureTableColumn($statementTable, 'date_import', 'DATETIME NOT NULL');
		$this->ensureTableColumn($statementTable, 'fk_user_import', 'INTEGER NOT NULL DEFAULT 0');
		$this->ensureTableColumn($statementTable, 'status', 'SMALLINT NOT NULL DEFAULT 0');
		$this->ensureTableColumn($statementTable, 'currency', "VARCHAR(3) NOT NULL DEFAULT 'EUR'");
		$this->ensureTableColumn($statementTable, 'external_id', 'VARCHAR(128) NULL');
		$this->ensureTableColumn($statementTable, 'last_balance', 'DECIMAL(24,8) NULL');
		$this->ensureTableColumn($statementTable, 'import_payload', 'LONGTEXT NULL');
		$this->ensureTableColumn($statementTable, 'datec', 'DATETIME NOT NULL');
		$this->ensureTableColumn($statementTable, 'tms', 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');

		$this->ensureTableIndex($statementTable, 'uk_kreabank_statement_entity_ref', 'UNIQUE INDEX uk_kreabank_statement_entity_ref (entity, ref)');
		$this->ensureTableIndex($statementTable, 'idx_kreabank_statement_entity_date', 'INDEX idx_kreabank_statement_entity_date (entity, statement_date)');
		$this->ensureTableIndex($statementTable, 'idx_kreabank_statement_bank', 'INDEX idx_kreabank_statement_bank (entity, bank_account_id)');

		$sqlLine = 'CREATE TABLE IF NOT EXISTS ' . $lineTable . ' (';
		$sqlLine .= ' rowid INTEGER AUTO_INCREMENT PRIMARY KEY,';
		$sqlLine .= ' entity INTEGER NOT NULL DEFAULT 1,';
		$sqlLine .= ' fk_statement INTEGER NOT NULL,';
		$sqlLine .= ' fk_native_bank_line INTEGER NULL,';
		$sqlLine .= ' line_uid VARCHAR(128) NULL,';
		$sqlLine .= ' line_rank INTEGER NOT NULL DEFAULT 0,';
		$sqlLine .= ' operation_date DATE NULL,';
		$sqlLine .= ' value_date DATE NULL,';
		$sqlLine .= ' amount DECIMAL(24,8) NOT NULL,';
		$sqlLine .= " currency VARCHAR(3) NOT NULL DEFAULT 'EUR',";
		$sqlLine .= ' running_balance DECIMAL(24,8) NULL,';
		$sqlLine .= ' counterparty_name VARCHAR(255) NULL,';
		$sqlLine .= ' counterparty_iban VARCHAR(64) NULL,';
		$sqlLine .= ' description TEXT NULL,';
		$sqlLine .= ' payment_reference VARCHAR(190) NULL,';
		$sqlLine .= ' bank_reference VARCHAR(190) NULL,';
		$sqlLine .= ' direction SMALLINT NOT NULL DEFAULT 0,';
		$sqlLine .= ' allocated_amount DECIMAL(24,8) NOT NULL DEFAULT 0,';
		$sqlLine .= ' status SMALLINT NOT NULL DEFAULT 0,';
		$sqlLine .= ' duplicate_hash CHAR(64) NULL,';
		$sqlLine .= ' is_duplicate SMALLINT NOT NULL DEFAULT 0,';
		$sqlLine .= ' duplicate_of INTEGER NULL,';
		$sqlLine .= ' datec DATETIME NOT NULL,';
		$sqlLine .= ' tms TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,';
		$sqlLine .= ' KEY idx_kreabank_line_statement (fk_statement),';
		$sqlLine .= ' KEY idx_kreabank_line_native (fk_native_bank_line),';
		$sqlLine .= ' KEY idx_kreabank_line_status (entity, status),';
		$sqlLine .= ' KEY idx_kreabank_line_date_amount (operation_date, amount),';
		$sqlLine .= ' KEY idx_kreabank_line_hash (entity, duplicate_hash)';
		$sqlLine .= ' ) ENGINE=innodb';
		if (!$this->db->query($sqlLine) && function_exists('dol_syslog')) {
			dol_syslog('KreaBank schema sync failed (create statement_line): ' . $this->db->lasterror(), LOG_WARNING);
		}

		$this->ensureTableColumn($lineTable, 'fk_native_bank_line', 'INTEGER NULL');
		$this->ensureTableColumn($lineTable, 'line_uid', 'VARCHAR(128) NULL');
		$this->ensureTableColumn($lineTable, 'line_rank', 'INTEGER NOT NULL DEFAULT 0');
		$this->ensureTableColumn($lineTable, 'operation_date', 'DATE NULL');
		$this->ensureTableColumn($lineTable, 'value_date', 'DATE NULL');
		$this->ensureTableColumn($lineTable, 'amount', 'DECIMAL(24,8) NOT NULL DEFAULT 0');
		$this->ensureTableColumn($lineTable, 'currency', "VARCHAR(3) NOT NULL DEFAULT 'EUR'");
		$this->ensureTableColumn($lineTable, 'running_balance', 'DECIMAL(24,8) NULL');
		$this->ensureTableColumn($lineTable, 'counterparty_name', 'VARCHAR(255) NULL');
		$this->ensureTableColumn($lineTable, 'counterparty_iban', 'VARCHAR(64) NULL');
		$this->ensureTableColumn($lineTable, 'description', 'TEXT NULL');
		$this->ensureTableColumn($lineTable, 'payment_reference', 'VARCHAR(190) NULL');
		$this->ensureTableColumn($lineTable, 'bank_reference', 'VARCHAR(190) NULL');
		$this->ensureTableColumn($lineTable, 'direction', 'SMALLINT NOT NULL DEFAULT 0');
		$this->ensureTableColumn($lineTable, 'allocated_amount', 'DECIMAL(24,8) NOT NULL DEFAULT 0');
		$this->ensureTableColumn($lineTable, 'status', 'SMALLINT NOT NULL DEFAULT 0');
		$this->ensureTableColumn($lineTable, 'duplicate_hash', 'CHAR(64) NULL');
		$this->ensureTableColumn($lineTable, 'is_duplicate', 'SMALLINT NOT NULL DEFAULT 0');
		$this->ensureTableColumn($lineTable, 'duplicate_of', 'INTEGER NULL');
		$this->ensureTableColumn($lineTable, 'datec', 'DATETIME NOT NULL');
		$this->ensureTableColumn($lineTable, 'tms', 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');

		$this->ensureTableIndex($lineTable, 'idx_kreabank_line_statement', 'INDEX idx_kreabank_line_statement (fk_statement)');
		$this->ensureTableIndex($lineTable, 'idx_kreabank_line_native', 'INDEX idx_kreabank_line_native (fk_native_bank_line)');
		$this->ensureTableIndex($lineTable, 'idx_kreabank_line_status', 'INDEX idx_kreabank_line_status (entity, status)');
		$this->ensureTableIndex($lineTable, 'idx_kreabank_line_date_amount', 'INDEX idx_kreabank_line_date_amount (operation_date, amount)');
		$this->ensureTableIndex($lineTable, 'idx_kreabank_line_hash', 'INDEX idx_kreabank_line_hash (entity, duplicate_hash)');
	}

	/**
	 * @param int $statementId
	 * @return void
	 */
	protected function rollbackImportedStatement($statementId)
	{
		$statementId = (int) $statementId;
		if ($statementId <= 0) {
			return;
		}

		if (!$this->db->begin()) {
			return;
		}
		if (!$this->db->query('DELETE FROM ' . $this->db->prefix() . 'kreabank_statement_line WHERE entity = ' . ((int) $this->entity) . ' AND fk_statement = ' . $statementId)) {
			$this->db->rollback();
			return;
		}
		if (!$this->db->query('DELETE FROM ' . $this->db->prefix() . 'kreabank_statement WHERE entity = ' . ((int) $this->entity) . ' AND rowid = ' . $statementId)) {
			$this->db->rollback();
			return;
		}

		$this->db->commit();
	}

	/**
	 * @param int $bankAccountId
	 * @return Account
	 */
	protected function assertBankAccountIsOpen($bankAccountId)
	{
		$account = new Account($this->db);
		$resFetch = $account->fetch((int) $bankAccountId);
		if ($resFetch <= 0) {
			throw new Exception($this->langs->trans('ErrorRecordNotFound'));
		}
		if ((int) $account->status === Account::STATUS_CLOSED) {
			throw new Exception($this->langs->trans('ErrorBankAccountIsClosed'));
		}

		return $account;
	}

	/**
	 * @param string $statementRef
	 * @param int $bankAccountId
	 * @param string $sourceType
	 * @param string $fileName
	 * @param string $statementDate
	 * @param string $currency
	 * @return int
	 */
	protected function insertStatementRow($statementRef, $bankAccountId, $sourceType, $fileName, $statementDate = '', $currency = 'EUR')
	{
		$now = dol_now();
		$currency = strtoupper(trim((string) $currency));
		if (!preg_match('/^[A-Z]{3}$/', $currency)) {
			$currency = 'EUR';
		}
		$sql = 'INSERT INTO ' . $this->db->prefix() . 'kreabank_statement (';
		$sql .= 'entity, ref, label, source_type, filename, bank_account_id, statement_date, date_import, fk_user_import, status, currency, datec';
		$sql .= ') VALUES (';
		$sql .= ((int) $this->entity);
		$sql .= ', ' . $this->sqlText($statementRef);
		$sql .= ', ' . $this->sqlText($statementRef);
		$sql .= ', ' . $this->sqlText($sourceType);
		$sql .= ', ' . $this->sqlText($fileName);
		$sql .= ', ' . ((int) $bankAccountId);
		$sql .= ', ' . $this->sqlDate($statementDate);
		$sql .= ', ' . $this->sqlDateTime($now);
		$sql .= ', ' . ((int) $this->user->id);
		$sql .= ', 0';
		$sql .= ', ' . $this->sqlText($currency);
		$sql .= ', ' . $this->sqlDateTime($now);
		$sql .= ')';

		if (!$this->db->query($sql)) {
			return 0;
		}

		return (int) $this->db->last_insert_id($this->db->prefix() . 'kreabank_statement');
	}

	/**
	 * @param int $statementId
	 * @param int $lineRank
	 * @param array<string,mixed> $line
	 * @param string $hash
	 * @param int $isDuplicate
	 * @return int
	 */
	protected function insertStatementLineRow($statementId, $lineRank, $line, $hash, $isDuplicate = 0)
	{
		$sql = 'INSERT INTO ' . $this->db->prefix() . 'kreabank_statement_line (';
		$sql .= 'entity, fk_statement, fk_native_bank_line, line_uid, line_rank, operation_date, value_date, amount, currency, running_balance,';
		$sql .= 'counterparty_name, counterparty_iban, description, payment_reference, bank_reference, direction,';
		$sql .= 'allocated_amount, status, duplicate_hash, is_duplicate, duplicate_of, datec';
		$sql .= ') VALUES (';
		$sql .= ((int) $this->entity);
		$sql .= ', ' . ((int) $statementId);
		$sql .= ', NULL';
		$sql .= ', ' . $this->sqlText((string) $line['line_uid']);
		$sql .= ', ' . ((int) $lineRank);
		$sql .= ', ' . $this->sqlDate((string) $line['operation_date']);
		$sql .= ', ' . $this->sqlDate((string) $line['value_date']);
		$sql .= ', ' . price2num((string) $line['amount'], 'MU');
		$sql .= ', ' . $this->sqlText((string) $line['currency']);
		$sql .= ', ' . ($line['running_balance'] !== null ? price2num((string) $line['running_balance'], 'MU') : 'NULL');
		$sql .= ', ' . $this->sqlText((string) $line['counterparty_name']);
		$sql .= ', ' . $this->sqlText((string) $line['counterparty_iban']);
		$sql .= ', ' . $this->sqlText((string) $line['description']);
		$sql .= ', ' . $this->sqlText((string) $line['payment_reference']);
		$sql .= ', ' . $this->sqlText((string) $line['bank_reference']);
		$sql .= ', ' . ((int) $line['direction']);
		$sql .= ', 0';
		$sql .= ', 0';
		$sql .= ', ' . $this->sqlText($hash);
		$sql .= ', ' . ((int) $isDuplicate > 0 ? 1 : 0);
		$sql .= ', NULL';
		$sql .= ', ' . $this->sqlDateTime(dol_now());
		$sql .= ')';

		if (!$this->db->query($sql)) {
			return 0;
		}

		return (int) $this->db->last_insert_id($this->db->prefix() . 'kreabank_statement_line');
	}

	/**
	 * Insert helper metadata row.
	 *
	 * @param int $bankLineId
	 * @param int $bankAccountId
	 * @param string $hash
	 * @param array<string,mixed> $line
	 * @param string $sourceFile
	 * @param string $sourceType
	 * @return bool
	 */
	protected function insertMetaRow($bankLineId, $bankAccountId, $hash, $line, $sourceFile, $sourceType)
	{
		$sql = 'INSERT INTO ' . $this->db->prefix() . 'kreabank_bankmeta (';
		$sql .= 'entity, fk_bank_line, bank_account_id, source_type, source_file, source_line_uid, idempotency_hash,';
		$sql .= 'operation_date, value_date, amount, currency, running_balance, counterparty_name, counterparty_iban, description, payment_reference, bank_reference, direction, status, datec';
		$sql .= ') VALUES (';
		$sql .= ((int) $this->entity);
		$sql .= ', ' . ((int) $bankLineId);
		$sql .= ', ' . ((int) $bankAccountId);
		$sql .= ', ' . $this->sqlText($sourceType);
		$sql .= ', ' . $this->sqlText($sourceFile);
		$sql .= ', ' . $this->sqlText((string) $line['line_uid']);
		$sql .= ', ' . $this->sqlText($hash);
		$sql .= ', ' . $this->sqlDate((string) $line['operation_date']);
		$sql .= ', ' . $this->sqlDate((string) $line['value_date']);
		$sql .= ', ' . price2num((string) $line['amount'], 'MU');
		$sql .= ', ' . $this->sqlText((string) $line['currency']);
		$sql .= ', ' . ($line['running_balance'] !== null ? price2num((string) $line['running_balance'], 'MU') : 'NULL');
		$sql .= ', ' . $this->sqlText((string) $line['counterparty_name']);
		$sql .= ', ' . $this->sqlText((string) $line['counterparty_iban']);
		$sql .= ', ' . $this->sqlText((string) $line['description']);
		$sql .= ', ' . $this->sqlText((string) $line['payment_reference']);
		$sql .= ', ' . $this->sqlText((string) $line['bank_reference']);
		$sql .= ', ' . ((int) $line['direction']);
		$sql .= ', 0';
		$sql .= ', ' . $this->sqlDateTime(dol_now());
		$sql .= ')';

		return (bool) $this->db->query($sql);
	}

	/**
	 * @param int $lineId
	 * @return array<string,mixed>|null
	 */
	protected function getStagedLineById($lineId)
	{
		$this->ensureStagingTables();
		$lineId = (int) $lineId;
		if ($lineId <= 0) {
			return null;
		}

		$sql = 'SELECT l.rowid, l.fk_statement, l.fk_native_bank_line, l.operation_date, l.value_date, l.amount, l.currency, l.running_balance,';
		$sql .= ' l.counterparty_name, l.counterparty_iban, l.description, l.payment_reference, l.bank_reference, l.direction,';
		$sql .= ' l.allocated_amount, l.status, l.duplicate_hash, l.is_duplicate, l.duplicate_of,';
		$sql .= ' s.ref as statement_ref, s.source_type, s.filename as source_file, s.bank_account_id,';
		$sql .= ' ba.ref as bank_account_ref, ba.label as bank_account_label, ba.currency_code as bank_currency,';
		$sql .= ' b.label as native_label, b.note as native_note, b.rappro';
		$sql .= ' FROM ' . $this->db->prefix() . 'kreabank_statement_line as l';
		$sql .= ' INNER JOIN ' . $this->db->prefix() . 'kreabank_statement as s ON s.rowid = l.fk_statement';
		$sql .= ' INNER JOIN ' . $this->db->prefix() . 'bank_account as ba ON ba.rowid = s.bank_account_id';
		$sql .= ' LEFT JOIN ' . $this->db->prefix() . 'bank as b ON b.rowid = l.fk_native_bank_line';
		$sql .= ' WHERE l.entity = ' . ((int) $this->entity);
		$sql .= ' AND s.entity = ' . ((int) $this->entity);
		$sql .= ' AND l.rowid = ' . $lineId;
		$sql .= $this->db->plimit(1, 0);

		$resql = $this->db->query($sql);
		if (!$resql) {
			throw new Exception($this->db->lasterror());
		}

		$obj = $this->db->fetch_object($resql);
		if (!$obj) {
			return null;
		}

		return $this->stagedRowToLineArray($obj);
	}

	/**
	 * @param int $lineId
	 * @return array<string,mixed>|null
	 */
	protected function getLegacyNativeLineById($lineId)
	{
		$this->ensureMetaTable();
		$this->ensureStagingTables();
		$lineId = (int) $lineId;
		if ($lineId <= 0) {
			return null;
		}

		$sql = 'SELECT b.rowid, b.dateo as operation_date, b.datev as value_date, b.amount, b.label as native_label, b.note as native_note,';
		$sql .= ' b.rappro, b.num_releve, b.fk_account as bank_account_id,';
		$sql .= ' ba.ref as bank_account_ref, ba.label as bank_account_label, ba.currency_code as bank_currency,';
		$sql .= ' m.running_balance, m.counterparty_name, m.counterparty_iban, m.description, m.payment_reference, m.bank_reference,';
		$sql .= ' m.currency as imported_currency, m.direction, m.idempotency_hash, m.status as meta_status, m.source_type, m.source_file,';
		$sql .= ' sl.is_duplicate, sl.duplicate_of';
		$sql .= ' FROM ' . $this->db->prefix() . 'bank as b';
		$sql .= ' INNER JOIN ' . $this->db->prefix() . 'bank_account as ba ON ba.rowid = b.fk_account';
		$sql .= ' LEFT JOIN ' . $this->db->prefix() . 'kreabank_bankmeta as m ON m.entity = ' . ((int) $this->entity) . ' AND m.fk_bank_line = b.rowid';
		$sql .= ' LEFT JOIN ' . $this->db->prefix() . 'kreabank_statement_line as sl ON sl.entity = ' . ((int) $this->entity) . ' AND sl.fk_native_bank_line = b.rowid';
		$sql .= ' WHERE ba.entity = ' . ((int) $this->entity);
		$sql .= ' AND b.rowid = ' . $lineId;
		$sql .= ' ORDER BY sl.rowid DESC';
		$sql .= $this->db->plimit(1, 0);
		$resql = $this->db->query($sql);
		if (!$resql) {
			throw new Exception($this->db->lasterror());
		}

		$obj = $this->db->fetch_object($resql);
		if (!$obj) {
			return null;
		}

		return $this->legacyNativeRowToLineArray($obj);
	}

	/**
	 * Normalize one imported line to adapter shape.
	 *
	 * @param array<string,mixed> $line
	 * @param string|null $statementDate
	 * @param string $defaultCurrency
	 * @return array<string,mixed>
	 */
	protected function normalizeImportLine($line, $statementDate = null, $defaultCurrency = 'EUR')
	{
		$defaultCurrency = strtoupper(trim((string) $defaultCurrency));
		if (!preg_match('/^[A-Z]{3}$/', $defaultCurrency)) {
			$defaultCurrency = 'EUR';
		}
		$operationDate = (string) (!empty($line['operation_date']) ? $line['operation_date'] : $statementDate);
		if ($operationDate === '') {
			$operationDate = dol_print_date(dol_now(), '%Y-%m-%d');
		}
		$valueDate = (string) (!empty($line['value_date']) ? $line['value_date'] : $operationDate);

		$amount = (float) price2num((string) (isset($line['amount']) ? $line['amount'] : 0), 'MU');
		$currency = strtoupper(trim((string) (isset($line['currency']) ? $line['currency'] : $defaultCurrency)));
		if (!preg_match('/^[A-Z]{3}$/', $currency)) {
			$currency = $defaultCurrency;
		}

		$counterpartyName = (string) (isset($line['counterparty_name']) ? $line['counterparty_name'] : '');
		$counterpartyIban = (string) (isset($line['counterparty_iban']) ? $line['counterparty_iban'] : '');
		$description = (string) (isset($line['description']) ? $line['description'] : '');
		$paymentReference = (string) (isset($line['payment_reference']) ? $line['payment_reference'] : '');
		$bankReference = (string) (isset($line['bank_reference']) ? $line['bank_reference'] : '');

		return $this->repairBalanceMappedAsAmount(array(
			'line_uid' => (string) (isset($line['line_uid']) ? $line['line_uid'] : ''),
			'operation_date' => $operationDate,
			'value_date' => $valueDate,
			'amount' => $amount,
			'currency' => $currency,
			'running_balance' => isset($line['running_balance']) && $line['running_balance'] !== '' ? (float) price2num((string) $line['running_balance'], 'MU') : null,
			'counterparty_name' => $counterpartyName,
			'counterparty_iban' => $counterpartyIban,
			'description' => $description,
			'payment_reference' => $paymentReference,
			'bank_reference' => $bankReference,
			'direction' => isset($line['direction']) ? (int) $line['direction'] : ($amount >= 0 ? 1 : -1),
			'operation_type' => (string) (isset($line['operation_type']) ? $line['operation_type'] : ''),
		));
	}

	/**
	 * Repair a known mapping failure where the statement balance was imported as amount
	 * and the real movement amount was imported as a numeric reference/description.
	 *
	 * @param array<string,mixed> $line
	 * @return array<string,mixed>
	 */
	protected function repairBalanceMappedAsAmount($line)
	{
		if (!is_array($line) || empty($line)) {
			return $line;
		}

		$amount = isset($line['amount']) ? (float) price2num((string) $line['amount'], 'MU') : 0.0;
		if (abs($amount) < 100.0) {
			return $line;
		}

		$referenceAmount = $this->extractDecimalAmountLikeReference($line);
		if ($referenceAmount === null || abs((float) $referenceAmount) < 0.00001) {
			return $line;
		}

		$referenceAbs = abs((float) $referenceAmount);
		if ($referenceAbs > 10000.0 || abs($amount) < ($referenceAbs * 5.0)) {
			return $line;
		}

		$direction = isset($line['direction']) ? (int) $line['direction'] : 0;
		if ($direction === 0) {
			$direction = ($amount >= 0.0 ? 1 : -1);
		}

		if (!array_key_exists('running_balance', $line) || $line['running_balance'] === null || $line['running_balance'] === '') {
			$line['running_balance'] = $amount;
		}
		$line['amount'] = ($direction < 0 ? -$referenceAbs : $referenceAbs);
		$line['direction'] = $direction;
		foreach (array('description', 'payment_reference', 'bank_reference') as $field) {
			$value = isset($line[$field]) ? trim((string) $line[$field]) : '';
			if ($value !== '' && $this->isDecimalAmountTokenForValue($value, $referenceAbs)) {
				$line[$field] = '';
			}
		}

		return $line;
	}

	/**
	 * Extract a decimal amount accidentally mapped into a text/reference field.
	 *
	 * @param array<string,mixed> $line
	 * @return float|null
	 */
	protected function extractDecimalAmountLikeReference($line)
	{
		foreach (array('description', 'payment_reference', 'bank_reference') as $field) {
			$value = isset($line[$field]) ? trim((string) $line[$field]) : '';
			if ($value === '') {
				continue;
			}
			if (!preg_match('/^[+-]?\d+[,.]\d+$/', $value)) {
				continue;
			}
			$parsed = price2num($value, 'MU');
			if ($parsed === '' || !is_numeric($parsed)) {
				continue;
			}

			return (float) $parsed;
		}

		return null;
	}

	/**
	 * Check whether a token is only the decimal amount used for repair.
	 *
	 * @param string $value
	 * @param float $amount
	 * @return bool
	 */
	protected function isDecimalAmountTokenForValue($value, $amount)
	{
		$value = trim((string) $value);
		if ($value === '' || !preg_match('/^[+-]?\d+[,.]\d+$/', $value)) {
			return false;
		}
		$parsed = price2num($value, 'MU');
		if ($parsed === '' || !is_numeric($parsed)) {
			return false;
		}

		return (abs(abs((float) $parsed) - abs((float) $amount)) <= 0.00001);
	}

	/**
	 * Resolve import currency from bank account or global config.
	 *
	 * @param int $bankAccountId
	 * @param Account|null $account
	 * @return string
	 */
	protected function resolveImportCurrency($bankAccountId, $account = null)
	{
		global $conf;

		$currency = '';
		if (is_object($account) && !empty($account->currency_code)) {
			$currency = strtoupper(trim((string) $account->currency_code));
		}
		if ($currency === '' && (int) $bankAccountId > 0) {
			$sql = 'SELECT ba.currency_code';
			$sql .= ' FROM ' . $this->db->prefix() . 'bank_account as ba';
			$sql .= ' WHERE ba.entity = ' . ((int) $this->entity);
			$sql .= ' AND ba.rowid = ' . ((int) $bankAccountId);
			$sql .= $this->db->plimit(1, 0);
			$resql = $this->db->query($sql);
			if ($resql && ($obj = $this->db->fetch_object($resql))) {
				$currency = strtoupper(trim((string) (!empty($obj->currency_code) ? $obj->currency_code : '')));
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
	 * Build human-readable native label.
	 *
	 * @param array<string,mixed> $line
	 * @return string
	 */
	protected function buildLabel($line)
	{
		$parts = array();
		if (!empty($line['counterparty_name'])) {
			$parts[] = trim((string) $line['counterparty_name']);
		}
		if (!empty($line['description'])) {
			$parts[] = trim((string) $line['description']);
		}
		if (empty($parts) && !empty($line['payment_reference'])) {
			$parts[] = trim((string) $line['payment_reference']);
		}

		$label = trim(implode(' - ', $parts));
		if ($label === '') {
			$label = 'KreaBank import';
		}

		return dol_trunc($label, 190, 'right', 'UTF-8', 1);
	}

	/**
	 * Guess bank operation code from amount sign.
	 *
	 * @param float $amount
	 * @return string
	 */
	protected function guessOperationCode($amount)
	{
		return ($amount >= 0 ? 'VIR' : 'PRE');
	}

	/**
	 * Convert Y-m-d to timestamp.
	 *
	 * @param string|null $value
	 * @return int
	 */
	protected function toTimestamp($value)
	{
		if (empty($value)) {
			return dol_now();
		}
		$ts = strtotime((string) $value);
		if ($ts === false) {
			return dol_now();
		}

		return (int) $ts;
	}

	/**
	 * Build statement label used by native conciliation.
	 *
	 * @return string
	 */
	protected function buildStatementLabel()
	{
		return 'KREABANK-' . dol_print_date(dol_now(), '%Y%m%d');
	}

	/**
	 * Build a statement reference for imported lines.
	 *
	 * @param int $bankAccountId
	 * @param string|null $statementDate
	 * @param string $fileName
	 * @return string
	 */
	protected function buildImportedStatementRef($bankAccountId, $statementDate = null, $fileName = '')
	{
		$timestamp = $this->toTimestamp($statementDate);
		$datePart = dol_print_date($timestamp, '%Y%m%d');

		$fileToken = strtoupper((string) pathinfo((string) $fileName, PATHINFO_FILENAME));
		$fileToken = preg_replace('/[^A-Z0-9]+/', '', $fileToken);
		$fileToken = substr((string) $fileToken, 0, 8);

		$base = 'KB-' . $datePart;
		if ($fileToken !== '') {
			$base .= '-' . $fileToken;
		}
		$base = substr($base, 0, 28);

		$candidate = $base;
		$suffix = 1;
		while ($this->statementRefExists((int) $bankAccountId, $candidate) && $suffix < 10000) {
			$suffix++;
			$candidate = substr($base, 0, max(1, 28 - (strlen((string) $suffix) + 1))) . '-' . $suffix;
		}

		return $candidate;
	}

	/**
	 * Check if statement ref already exists for this account.
	 *
	 * @param int $bankAccountId
	 * @param string $statementRef
	 * @return bool
	 */
	protected function statementRefExists($bankAccountId, $statementRef)
	{
		if ($bankAccountId <= 0 || $statementRef === '') {
			return false;
		}

		$this->ensureStagingTables();

		$sql = 'SELECT rowid FROM ' . $this->db->prefix() . 'kreabank_statement';
		$sql .= ' WHERE entity = ' . ((int) $this->entity);
		$sql .= ' AND bank_account_id = ' . ((int) $bankAccountId);
		$sql .= ' AND ref = ' . $this->sqlText($statementRef);
		$sql .= $this->db->plimit(1, 0);
		$resql = $this->db->query($sql);
		if ($resql && $this->db->fetch_object($resql)) {
			return true;
		}

		$sqlBank = 'SELECT b.rowid';
		$sqlBank .= ' FROM ' . $this->db->prefix() . 'bank as b';
		$sqlBank .= ' INNER JOIN ' . $this->db->prefix() . 'bank_account as ba ON ba.rowid = b.fk_account';
		$sqlBank .= ' WHERE b.fk_account = ' . ((int) $bankAccountId);
		$sqlBank .= ' AND ba.entity = ' . ((int) $this->entity);
		$sqlBank .= ' AND b.num_releve = ' . $this->sqlText($statementRef);
		$sqlBank .= $this->db->plimit(1, 0);
		$resBank = $this->db->query($sqlBank);
		if (!$resBank) {
			return false;
		}

		return (bool) $this->db->fetch_object($resBank);
	}

	/**
	 * @param object $obj
	 * @return array<string,mixed>
	 */
	protected function stagedRowToLineArray($obj)
	{
		$amount = (float) $obj->amount;
		$direction = (int) $obj->direction;
		if ($direction === 0) {
			$direction = ($amount > 0 ? 1 : ($amount < 0 ? -1 : 0));
		}
		$isReconciled = ((int) $obj->status === 2 || (int) $obj->rappro === 1);
		$allocated = (float) $obj->allocated_amount;

		$line = array(
			'rowid' => (int) $obj->rowid,
			'fk_statement' => (int) $obj->fk_statement,
			'statement_ref' => (string) (!empty($obj->statement_ref) ? $obj->statement_ref : ''),
			'bank_account_id' => (int) $obj->bank_account_id,
			'bank_account_ref' => (string) (!empty($obj->bank_account_ref) ? $obj->bank_account_ref : ''),
			'bank_account_label' => (string) (!empty($obj->bank_account_label) ? $obj->bank_account_label : ''),
			'operation_date' => $obj->operation_date,
			'value_date' => $obj->value_date,
			'amount' => $amount,
			'currency' => (string) (!empty($obj->currency) ? $obj->currency : (!empty($obj->bank_currency) ? $obj->bank_currency : 'EUR')),
			'running_balance' => ($obj->running_balance !== null ? (float) $obj->running_balance : null),
			'counterparty_name' => (string) (!empty($obj->counterparty_name) ? $obj->counterparty_name : ''),
			'counterparty_iban' => (string) (!empty($obj->counterparty_iban) ? $obj->counterparty_iban : ''),
			'description' => (string) (!empty($obj->description) ? $obj->description : ''),
			'payment_reference' => (string) (!empty($obj->payment_reference) ? $obj->payment_reference : ''),
			'bank_reference' => (string) (!empty($obj->bank_reference) ? $obj->bank_reference : ''),
			'direction' => $direction,
			'allocated_amount' => $allocated,
			'status' => ($isReconciled ? 2 : (int) $obj->status),
			'is_duplicate' => (int) $obj->is_duplicate,
			'duplicate_of' => (int) $obj->duplicate_of,
			'native_label' => (string) (!empty($obj->native_label) ? $obj->native_label : ''),
			'native_note' => (string) (!empty($obj->native_note) ? $obj->native_note : ''),
			'idempotency_hash' => (string) (!empty($obj->duplicate_hash) ? $obj->duplicate_hash : ''),
			'native_bank_line_id' => (int) (!empty($obj->fk_native_bank_line) ? $obj->fk_native_bank_line : 0),
			'source_type' => (string) (!empty($obj->source_type) ? $obj->source_type : ''),
			'source_file' => (string) (!empty($obj->source_file) ? $obj->source_file : ''),
		);

		$line = $this->repairBalanceMappedAsAmount($line);
		if (abs((float) $allocated) > abs((float) $line['amount']) && (int) $line['status'] < 2) {
			$line['allocated_amount'] = 0.0;
		}

		return $line;
	}

	/**
	 * @param object $obj
	 * @return array<string,mixed>
	 */
	protected function legacyNativeRowToLineArray($obj)
	{
		$rawAmount = (float) $obj->amount;
		$metaDirection = (int) (!empty($obj->direction) ? $obj->direction : 0);
		$amount = $rawAmount;
		if ($metaDirection < 0) {
			$amount = -abs($rawAmount);
		} elseif ($metaDirection > 0) {
			$amount = abs($rawAmount);
		}

		$currency = (string) (!empty($obj->imported_currency) ? $obj->imported_currency : (!empty($obj->bank_currency) ? $obj->bank_currency : 'EUR'));
		$description = (string) (!empty($obj->description) ? $obj->description : $obj->native_label);
		$counterparty = (string) (!empty($obj->counterparty_name) ? $obj->counterparty_name : '');
		$paymentReference = (string) (!empty($obj->payment_reference) ? $obj->payment_reference : '');
		$direction = ($amount > 0 ? 1 : ($amount < 0 ? -1 : $metaDirection));
		$metaStatus = (int) (isset($obj->meta_status) ? $obj->meta_status : 0);
		$status = ((int) $obj->rappro === 1 ? 2 : $metaStatus);

		$line = array(
			'rowid' => (int) $obj->rowid,
			'fk_statement' => 0,
			'statement_ref' => (string) (!empty($obj->num_releve) ? $obj->num_releve : ''),
			'bank_account_id' => (int) $obj->bank_account_id,
			'bank_account_ref' => (string) (!empty($obj->bank_account_ref) ? $obj->bank_account_ref : ''),
			'bank_account_label' => (string) (!empty($obj->bank_account_label) ? $obj->bank_account_label : ''),
			'operation_date' => $obj->operation_date,
			'value_date' => $obj->value_date,
			'amount' => $amount,
			'currency' => $currency,
			'running_balance' => ($obj->running_balance !== null ? (float) $obj->running_balance : null),
			'counterparty_name' => $counterparty,
			'counterparty_iban' => (string) (!empty($obj->counterparty_iban) ? $obj->counterparty_iban : ''),
			'description' => $description,
			'payment_reference' => $paymentReference,
			'bank_reference' => (string) (!empty($obj->bank_reference) ? $obj->bank_reference : ''),
			'direction' => $direction,
			'allocated_amount' => ($status === 2 ? abs($amount) : 0.0),
			'status' => $status,
			'is_duplicate' => (int) (!empty($obj->is_duplicate) ? $obj->is_duplicate : 0),
			'duplicate_of' => (int) (!empty($obj->duplicate_of) ? $obj->duplicate_of : 0),
			'native_label' => (string) $obj->native_label,
			'native_note' => (string) $obj->native_note,
			'idempotency_hash' => (string) (!empty($obj->idempotency_hash) ? $obj->idempotency_hash : ''),
			'native_bank_line_id' => (int) $obj->rowid,
			'source_type' => (string) (!empty($obj->source_type) ? $obj->source_type : ''),
			'source_file' => (string) (!empty($obj->source_file) ? $obj->source_file : ''),
		);

		$line = $this->repairBalanceMappedAsAmount($line);
		if (abs((float) $line['allocated_amount']) > abs((float) $line['amount']) && (int) $line['status'] < 2) {
			$line['allocated_amount'] = 0.0;
		}

		return $line;
	}

	/**
	 * @param string $table
	 * @param string $column
	 * @return bool
	 */
	protected function tableHasColumn($table, $column)
	{
		$tableQuoted = $this->quoteSchemaIdentifier($table);
		if ($tableQuoted === '' || !$this->isSafeSchemaIdentifier($column)) {
			return false;
		}
		$resql = $this->db->query("SHOW COLUMNS FROM " . $tableQuoted . " LIKE '" . $this->db->escape($column) . "'");
		if (!$resql) {
			return false;
		}

		return ($this->db->num_rows($resql) > 0);
	}

	/**
	 * @param string $table
	 * @param string $index
	 * @return bool
	 */
	protected function tableHasIndex($table, $index)
	{
		$tableQuoted = $this->quoteSchemaIdentifier($table);
		if ($tableQuoted === '' || !$this->isSafeSchemaIdentifier($index)) {
			return false;
		}
		$sql = "SHOW INDEX FROM " . $tableQuoted . " WHERE Key_name = '" . $this->db->escape($index) . "'";
		$resql = $this->db->query($sql);
		if (!$resql) {
			return false;
		}

		return ($this->db->num_rows($resql) > 0);
	}

	/**
	 * @param string $table
	 * @param string $column
	 * @param string $definition
	 * @return void
	 */
	protected function ensureTableColumn($table, $column, $definition)
	{
		if ($this->tableHasColumn($table, $column)) {
			return;
		}
		$tableQuoted = $this->quoteSchemaIdentifier($table);
		$columnQuoted = $this->quoteSchemaIdentifier($column);
		if ($tableQuoted === '' || $columnQuoted === '' || !$this->isSafeColumnDefinition($definition)) {
			if (function_exists('dol_syslog')) {
				dol_syslog('KreaBank schema sync blocked unsafe add-column request: ' . $table . '.' . $column, LOG_WARNING);
			}
			return;
		}

		$sql = 'ALTER TABLE ' . $tableQuoted . ' ADD COLUMN ' . $columnQuoted . ' ' . $definition;
		if (!$this->db->query($sql) && function_exists('dol_syslog')) {
			dol_syslog('KreaBank schema sync failed (add column ' . $table . '.' . $column . '): ' . $this->db->lasterror(), LOG_WARNING);
		}
	}

	/**
	 * @param string $table
	 * @param string $indexName
	 * @param string $ddl
	 * @return void
	 */
	protected function ensureTableIndex($table, $indexName, $ddl)
	{
		if ($this->tableHasIndex($table, $indexName)) {
			return;
		}
		$tableQuoted = $this->quoteSchemaIdentifier($table);
		if ($tableQuoted === '' || !$this->isSafeSchemaIdentifier($indexName) || !$this->isSafeIndexDefinition($ddl)) {
			if (function_exists('dol_syslog')) {
				dol_syslog('KreaBank schema sync blocked unsafe add-index request: ' . $table . '.' . $indexName, LOG_WARNING);
			}
			return;
		}

		$sql = 'ALTER TABLE ' . $tableQuoted . ' ADD ' . $ddl;
		if (!$this->db->query($sql) && function_exists('dol_syslog')) {
			dol_syslog('KreaBank schema sync failed (add index ' . $table . '.' . $indexName . '): ' . $this->db->lasterror(), LOG_WARNING);
		}
	}

	/**
	 * Check whether native/meta imported rows exist for this entity.
	 *
	 * @return bool
	 */
	protected function hasAnyNativeMetaImportedLines()
	{
		$this->ensureMetaTable();

		$sql = 'SELECT l.rowid';
		$sql .= ' FROM ' . $this->db->prefix() . 'kreabank_bankmeta as l';
		$sql .= ' INNER JOIN ' . $this->db->prefix() . 'bank as b ON b.rowid = l.fk_bank_line';
		$sql .= ' INNER JOIN ' . $this->db->prefix() . 'bank_account as ba ON ba.rowid = b.fk_account';
		$sql .= ' WHERE l.entity = ' . ((int) $this->entity);
		$sql .= ' AND ba.entity = ' . ((int) $this->entity);
		$sql .= ' AND ba.clos = 0';
		$sql .= ' AND ba.rappro = 1';
		$sql .= " AND b.num_releve IS NOT NULL AND b.num_releve <> ''";
		$sql .= $this->db->plimit(1, 0);

		$resql = $this->db->query($sql);
		if (!$resql) {
			return false;
		}

		return (bool) $this->db->fetch_object($resql);
	}

	/**
	 * @param string $identifier
	 * @return bool
	 */
	protected function isSafeSchemaIdentifier($identifier)
	{
		$identifier = trim((string) $identifier);
		if ($identifier === '') {
			return false;
		}

		return (bool) preg_match('/^[A-Za-z0-9_]+$/', $identifier);
	}

	/**
	 * @param string $identifier
	 * @return string
	 */
	protected function quoteSchemaIdentifier($identifier)
	{
		if (!$this->isSafeSchemaIdentifier($identifier)) {
			return '';
		}

		return '`' . trim((string) $identifier) . '`';
	}

	/**
	 * @param string $definition
	 * @return bool
	 */
	protected function isSafeColumnDefinition($definition)
	{
		$definition = trim((string) $definition);
		if ($definition === '') {
			return false;
		}
		if (strpos($definition, ';') !== false || strpos($definition, '--') !== false || strpos($definition, '/*') !== false || strpos($definition, '*/') !== false) {
			return false;
		}

		return (bool) preg_match('/^[A-Za-z0-9_(),\'\s]+$/', $definition);
	}

	/**
	 * @param string $definition
	 * @return bool
	 */
	protected function isSafeIndexDefinition($definition)
	{
		$definition = trim((string) $definition);
		if ($definition === '') {
			return false;
		}
		if (strpos($definition, ';') !== false || strpos($definition, '--') !== false || strpos($definition, '/*') !== false || strpos($definition, '*/') !== false) {
			return false;
		}

		return (bool) preg_match('/^[A-Za-z0-9_(),\s]+$/', $definition);
	}

	/**
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
