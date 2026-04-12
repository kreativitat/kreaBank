#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

$rootDir = dirname(__DIR__);
$serviceFile = $rootDir.'/class/KreaBankService.class.php';
$nativeFile = $rootDir.'/class/KreaBankNativeBankAdapter.class.php';
$bulkFile = $rootDir.'/bulkmatch.php';
$pendingFile = $rootDir.'/pending.php';
$historyFile = $rootDir.'/history.php';
$importFile = $rootDir.'/import.php';
$setupFile = $rootDir.'/admin/setup.php';
$moduleFile = $rootDir.'/core/modules/modKreaBank.class.php';
$parserFile = $rootDir.'/class/KreaBankParser.class.php';
$runtimeFile = $rootDir.'/test/test_reconciliation_runtime.php';
$quickFlowRuntimeFile = $rootDir.'/test/test_reconciliation_quickflows_runtime.php';

$readFile = static function ($path) {
	$content = @file_get_contents($path);
	if (!is_string($content) || $content === '') {
		throw new RuntimeException('Unable to read '.$path);
	}

	return $content;
};

$extractFunctionBody = static function ($source, $functionName) {
	$needle = 'function '.$functionName.'(';
	$start = strpos($source, $needle);
	if ($start === false) {
		return '';
	}
	$braceStart = strpos($source, '{', $start);
	if ($braceStart === false) {
		return '';
	}

	$depth = 0;
	$length = strlen($source);
	for ($i = $braceStart; $i < $length; $i++) {
		$char = $source[$i];
		if ($char === '{') {
			$depth++;
		} elseif ($char === '}') {
			$depth--;
			if ($depth === 0) {
				return substr($source, $braceStart + 1, $i - $braceStart - 1);
			}
		}
	}

	return '';
};

$assertContains = static function ($haystack, $needle, $label, &$errors) {
	if (strpos($haystack, $needle) === false) {
		$errors[] = $label.' (missing: '.$needle.')';
	}
};

try {
	$serviceSource = $readFile($serviceFile);
	$nativeSource = $readFile($nativeFile);
	$bulkSource = $readFile($bulkFile);
	$pendingSource = $readFile($pendingFile);
	$historySource = $readFile($historyFile);
	$importSource = $readFile($importFile);
	$setupSource = $readFile($setupFile);
	$moduleSource = $readFile($moduleFile);
	$parserSource = $readFile($parserFile);
} catch (Throwable $e) {
	fwrite(STDERR, $e->getMessage().PHP_EOL);
	exit(1);
}

$errors = array();

$undoBody = $extractFunctionBody($serviceSource, 'undoReconciliation');
if ($undoBody === '') {
	$errors[] = 'Unable to parse undoReconciliation body';
} else {
	$assertContains($undoBody, 'if (!$this->db->begin()) {', 'undo should validate DB transaction start', $errors);
	$assertContains($undoBody, '$this->db->commit();', 'undo should commit DB transaction', $errors);
	$assertContains($undoBody, '$this->db->rollback();', 'undo should rollback on failure', $errors);
	$assertContains($undoBody, 'if (!$this->native->markStatementLinePending($lineId))', 'undo should validate staged line reset', $errors);
}

$reconcileBody = $extractFunctionBody($serviceSource, 'reconcileLine');
if ($reconcileBody === '') {
	$errors[] = 'Unable to parse reconcileLine body';
} else {
	$assertContains($reconcileBody, '$selectedNativeBankLink = $this->findSelectedNativeBankLink($links);', 'reconcile should pre-detect selected native bank candidates', $errors);
	$assertContains($reconcileBody, '$this->resolveExistingNativeBankLineForReconciliation(', 'reconcile should reuse an existing native bank line instead of cloning it', $errors);
	$assertContains($reconcileBody, "if (\$docType === 'native_bank') {", 'reconcile should short-circuit native-bank links after target resolution', $errors);
}

$pendingBody = $extractFunctionBody($nativeSource, 'markStatementLinePending');
if ($pendingBody === '') {
	$errors[] = 'Unable to parse markStatementLinePending body';
} else {
	$assertContains($pendingBody, '!$this->hasManagedNativeLine($nativeLineId)', 'pending reset should detect externally managed native bank lines', $errors);
	$assertContains($pendingBody, 'fk_native_bank_line = NULL', 'pending reset should clear external native bank bindings', $errors);
}

$deleteImportedBody = $extractFunctionBody($nativeSource, 'deleteImportedStatement');
if ($deleteImportedBody === '') {
	$errors[] = 'Unable to parse deleteImportedStatement body';
} else {
	$assertContains($deleteImportedBody, '$this->hasManagedNativeLine($nativeLineId)', 'statement deletion should only delete KreaBank-managed native lines', $errors);
	$assertContains($deleteImportedBody, 'if (!$this->db->begin()) {', 'statement deletion should start an explicit DB transaction', $errors);
	$assertContains($deleteImportedBody, '$this->db->commit();', 'statement deletion should commit DB transaction on success', $errors);
	$assertContains($deleteImportedBody, '$this->db->rollback();', 'statement deletion should rollback DB transaction on failure', $errors);
}

$deleteLegacyImportedBody = $extractFunctionBody($nativeSource, 'deleteLegacyImportedStatement');
if ($deleteLegacyImportedBody === '') {
	$errors[] = 'Unable to parse deleteLegacyImportedStatement body';
} else {
	$assertContains($deleteLegacyImportedBody, 'if (!$this->db->begin()) {', 'legacy statement deletion should start an explicit DB transaction', $errors);
	$assertContains($deleteLegacyImportedBody, '$this->db->commit();', 'legacy statement deletion should commit DB transaction on success', $errors);
	$assertContains($deleteLegacyImportedBody, '$this->db->rollback();', 'legacy statement deletion should rollback DB transaction on failure', $errors);
}

$markReconciledBody = $extractFunctionBody($nativeSource, 'markStatementLineReconciled');
if ($markReconciledBody === '') {
	$errors[] = 'Unable to parse markStatementLineReconciled body';
} else {
	$assertContains($markReconciledBody, '$metaExpected = ($nativeLineId > 0 && $this->hasManagedNativeLine($nativeLineId));', 'markStatementLineReconciled should determine whether meta update is required', $errors);
	$assertContains($markReconciledBody, 'if ($metaExpected && !$metaUpdated)', 'markStatementLineReconciled should fail when required meta update is missing', $errors);
	$assertContains($markReconciledBody, 'if ($hasStagedRow && !$stagingUpdated)', 'markStatementLineReconciled should fail when required staging update is missing', $errors);
}

$pendingListBody = $extractFunctionBody($nativeSource, 'getPendingLines');
if ($pendingListBody === '') {
	$errors[] = 'Unable to parse getPendingLines body';
} else {
	$assertContains($pendingListBody, 'if (!$this->hasAnyNativeMetaImportedLines()) {', 'pending lines should fallback to staging only when no native/meta imports exist', $errors);
	$assertContains($pendingListBody, 'return array();', 'pending lines should not unconditionally return staging fallback on filtered-empty native result', $errors);
}

$statementRefExistsBody = $extractFunctionBody($nativeSource, 'statementRefExists');
if ($statementRefExistsBody === '') {
	$errors[] = 'Unable to parse statementRefExists body';
} else {
	$assertContains($statementRefExistsBody, "AND ba.entity = '.((int) \$this->entity);", 'statementRefExists native fallback should enforce entity scope', $errors);
}

$tableHasColumnBody = $extractFunctionBody($nativeSource, 'tableHasColumn');
if ($tableHasColumnBody === '') {
	$errors[] = 'Unable to parse tableHasColumn body';
} else {
	$assertContains($tableHasColumnBody, '$tableQuoted = $this->quoteSchemaIdentifier($table);', 'tableHasColumn should sanitize and quote table identifier', $errors);
}

$ensureTableColumnBody = $extractFunctionBody($nativeSource, 'ensureTableColumn');
if ($ensureTableColumnBody === '') {
	$errors[] = 'Unable to parse ensureTableColumn body';
} else {
	$assertContains($ensureTableColumnBody, '$columnQuoted = $this->quoteSchemaIdentifier($column);', 'ensureTableColumn should quote validated column identifier', $errors);
	$assertContains($ensureTableColumnBody, '$this->isSafeColumnDefinition($definition)', 'ensureTableColumn should validate DDL definition tokens', $errors);
}

$stagedRowToLineArrayBody = $extractFunctionBody($nativeSource, 'stagedRowToLineArray');
if ($stagedRowToLineArrayBody === '') {
	$errors[] = 'Unable to parse stagedRowToLineArray body';
} else {
	if (strpos($stagedRowToLineArrayBody, 'if ($isReconciled && $allocated <= 0.00001)') !== false) {
		$errors[] = 'stagedRowToLineArray should not force allocated amount to full absolute amount at read time';
	}
}

$buildIdempotencyHashBody = $extractFunctionBody($nativeSource, 'buildIdempotencyHash');
if ($buildIdempotencyHashBody === '') {
	$errors[] = 'Unable to parse buildIdempotencyHash body';
} else {
	$assertContains($buildIdempotencyHashBody, '$bankReference = kreabankNormalizeText(', 'buildIdempotencyHash should include normalized bank reference', $errors);
	$assertContains($buildIdempotencyHashBody, '$operationType = kreabankNormalizeText(', 'buildIdempotencyHash should include normalized operation type', $errors);
}

$importLinesBody = $extractFunctionBody($nativeSource, 'importLines');
if ($importLinesBody === '') {
	$errors[] = 'Unable to parse importLines body';
} else {
	$assertContains($importLinesBody, '$legacyDuplicateHash = $this->buildLegacyIdempotencyHash($bankAccountId, $normalized);', 'importLines should keep legacy duplicate-hash compatibility checks after hash payload expansion', $errors);
}

$assertContains($nativeSource, 'protected function hasAnyNativeMetaImportedLines()', 'native adapter should expose helper for native-meta fallback gating', $errors);
$assertContains($nativeSource, 'protected function buildLegacyIdempotencyHash(', 'native adapter should keep legacy idempotency hash builder for compatibility checks', $errors);
$assertContains($nativeSource, 'protected function getRecentReconciledLinesFromStaging(', 'native adapter should expose legacy reconciled-history fallback loader', $errors);

$recentReconciledBody = $extractFunctionBody($nativeSource, 'getRecentReconciledLines');
if ($recentReconciledBody === '') {
	$errors[] = 'Unable to parse getRecentReconciledLines body';
} else {
	$assertContains($recentReconciledBody, 'if ($this->hasAnyNativeMetaImportedLines()) {', 'recent reconciled history should gate legacy fallback when native/meta imports exist', $errors);
	$assertContains($recentReconciledBody, 'return $this->getRecentReconciledLinesFromStaging((int) $limit, (int) $offset);', 'recent reconciled history should fallback to legacy staged reconciled rows when native history is empty', $errors);
}

$assertContains($serviceSource, 'public function getSuggestionsForLines(', 'service should expose batch suggestions API', $errors);
$assertContains($serviceSource, 'protected function loadBatchMlSamples()', 'service should keep DB-backed batch ML sample loader', $errors);
$assertContains($serviceSource, 'protected function findSelectedNativeBankLink(', 'service should expose native-bank reuse helper', $errors);
$assertContains($serviceSource, 'protected function resolveExistingNativeBankLineForReconciliation(', 'service should validate selected native bank lines without cloning them', $errors);
$assertContains($serviceSource, 'protected function getSupplierMlHashBinCount()', 'service should expose supplier ML hash-bin configuration helper', $errors);
$assertContains($serviceSource, 'return 64;', 'service should use 64 hashed bins for supplier ML text features', $errors);
$assertContains($serviceSource, 'file_put_contents($tmpPath, $payloadJson, LOCK_EX)', 'service should write batch ML cache payload to temp file with lock', $errors);
$assertContains($serviceSource, 'if (!rename($tmpPath, $path))', 'service should atomically replace batch ML cache file after temp write', $errors);
$assertContains($serviceSource, "\$nativeLinkedPaymentIds = array(", 'service should track native-linked payment ids for dedupe', $errors);
$assertContains($serviceSource, "if (\$docType === 'payment_linked' && isset(\$nativeLinkedPaymentIds['payment'][\$docId]))", 'service should suppress linked customer payment duplicates when native candidate already exists', $errors);
$assertContains($serviceSource, "if (\$docType === 'payment_supplier_linked' && isset(\$nativeLinkedPaymentIds['payment_supplier'][\$docId]))", 'service should suppress linked supplier payment duplicates when native candidate already exists', $errors);
$assertContains($serviceSource, 'public function predictSupplierForLine(', 'service should expose supplier ML prediction API', $errors);
$assertContains($serviceSource, 'protected function ensureBatchMlSampleTable(', 'service should provide DB-backed batch ML sample table', $errors);
$assertContains($serviceSource, 'public function getBatchMlValidationReport(', 'service should expose batch ML validation metrics API', $errors);
$assertContains($serviceSource, 'public function getAuditRetentionDiagnostics(', 'service should expose audit retention diagnostics API', $errors);
$assertContains($serviceSource, 'public function purgeAuditRowsOlderThanRetention(', 'service should expose audit retention purge API', $errors);
$assertContains($serviceSource, "'minimum_gap_pct' => 15", 'supplier ML should expose minimum confidence gap guard', $errors);
$assertContains($serviceSource, 'low_confidence_gap', 'supplier ML should distinguish low-gap predictions from low-confidence predictions', $errors);
$assertContains($serviceSource, 'public function createQuickSupplierInvoiceAndReconcile(', 'service should expose quick supplier invoice reconciliation API', $errors);
$assertContains($nativeSource, 'public function getLineLinksBatch(', 'native adapter should expose batch links API', $errors);
$assertContains($nativeSource, 'protected function hasManagedNativeLine(', 'native adapter should distinguish imported native lines from reused external ones', $errors);
$assertContains($nativeSource, "'operation_date' => 'l.operation_date'", 'native adapter pending sort allowlist should expose logical operation_date key', $errors);
$assertContains($historySource, 'getLineLinksBatch(', 'history should use batch link retrieval', $errors);
$assertContains($importSource, 'KREABANK_IMPORT_WIZARD_TTL', 'import wizard should support stale session TTL', $errors);
$assertContains($importSource, "glob(\$wizardImportTempDir.'/wiz_*')", 'import wizard should garbage-collect stale temp files', $errors);
$assertContains($pendingSource, "if (empty(\$sortfield)) {\n\t\$sortfield = 'operation_date';", 'pending page should default sortfield to logical operation_date key', $errors);
$assertContains($pendingSource, "if (\$sortorder !== 'ASC' && \$sortorder !== 'DESC') {\n\t\$sortorder = 'ASC';", 'pending page should validate sortorder locally', $errors);
$assertContains($pendingSource, "GETPOST('button_removefilter_x', 'alpha')", 'pending page should detect Dolibarr clear-filter button _x variant', $errors);
$assertContains($pendingSource, '$operationDateTs = ($operationDateRaw !== \'\' ? strtotime($operationDateRaw) : false);', 'pending page should guard operation date timestamp parsing', $errors);
$assertContains($pendingSource, "(\$operationDateTs !== false && \$operationDateTs > 0) ? dol_print_date(\$operationDateTs, 'day') : '<span class=\"opacitymedium\">-</span>'", 'pending page should render fallback dash when date is invalid', $errors);
$assertContains($historySource, '$enforcePostActionWithToken();', 'history undo action should enforce POST + CSRF token per action', $errors);
$assertContains($historySource, '$historyPoolLimit = max(300, (($page + 1) * $limit) + 500);', 'history should size reconciliation fetch pool from active page and list limit', $errors);
$assertContains($historySource, '$historyPoolLimit = min(5000, $historyPoolLimit);', 'history should cap reconciliation fetch pool to bounded window for production stability', $errors);
$assertContains($historySource, '$auditPoolLimit = max(300, (($auditPage + 1) * $limit) + 500);', 'history should size audit fetch pool from active audit page and list limit', $errors);
$assertContains($historySource, '$auditPoolLimit = min(5000, $auditPoolLimit);', 'history should cap audit fetch pool to bounded window for production stability', $errors);
$assertContains($historySource, '$recent = $service->getRecentReconciliations($historyPoolLimit);', 'history should fetch reconciliations using bounded pool limit', $errors);
$assertContains($historySource, '$auditRows = $service->getAuditHistory($auditPoolLimit);', 'history should fetch audit rows using bounded pool limit', $errors);
$assertContains($historySource, '$isAllowedAbsoluteUrlPrefix = static function ($urlPrefix) use ($currentHttpHost)', 'history should validate absolute document URL host before rendering links', $errors);
$assertContains($historySource, '$auditDateLabel = ($auditDateTs !== false && $auditDateTs > 0) ? dol_print_date($auditDateTs, \'dayhour\') : \'<span class="opacitymedium">-</span>\';', 'history should guard invalid audit timestamps before rendering date', $errors);
if (strpos($historySource, 'while (true)') !== false && strpos($historySource, 'getRecentReconciliations($historyFetchChunk, $recentOffset)') !== false) {
	$errors[] = 'history should not scan the full reconciliation dataset with unbounded chunk loops';
}
if (strpos($historySource, "'operator' => 'contains'") !== false) {
	$errors[] = 'history integer line filter should not fallback to substring contains matching';
}
if (strpos($historySource, 'dol_print_date(strtotime((string) $audit[\'datec\']), \'dayhour\')') !== false) {
	$errors[] = 'history should not render audit dates via unchecked strtotime inline';
}
if (strpos($historySource, 'pretty_json') !== false) {
	$errors[] = 'history audit payload processing should not keep unused pretty_json computation path';
}
$assertContains($setupSource, "KREABANK_SUPPLIER_ML_MIN_CONFIDENCE', '70'", 'setup should seed supplier ML confidence default at 70%', $errors);
$assertContains($setupSource, 'KREABANK_BATCH_ML_CLASSIFIER', 'setup should expose batch classifier selection', $errors);
$assertContains($setupSource, 'KREABANK_AUDIT_RETENTION_DAYS', 'setup should expose audit retention setting', $errors);
$assertContains($moduleSource, "KREABANK_SUPPLIER_ML_MIN_CONFIDENCE', 'integer', '70'", 'module descriptor should publish supplier ML confidence default at 70%', $errors);
$assertContains($moduleSource, "KREABANK_BATCH_ML_CLASSIFIER', 'chaine', 'knn'", 'module descriptor should define batch classifier constant', $errors);
$assertContains($moduleSource, "KREABANK_AUDIT_RETENTION_DAYS', 'integer', '365'", 'module descriptor should define audit retention constant', $errors);
$assertContains($moduleSource, "KREABANK_BULKMATCH_MIN_SCORE', 'integer', '100'", 'module descriptor should define bulkmatch minimum score constant', $errors);
$assertContains($moduleSource, "KREABANK_BULKMATCH_AUTO_RELOAD_MAX', 'integer', '30'", 'module descriptor should define bulkmatch auto-refresh limit constant', $errors);
$assertContains($bulkSource, 'KREABANK_BULKMATCH_SCAN_CHUNK_SIZE', 'bulkmatch should honor chunk-size setup', $errors);
$assertContains($bulkSource, 'KREABANK_BULKMATCH_SCAN_REQUEST_BUDGET_MS', 'bulkmatch should honor per-request scan budget', $errors);
$assertContains($bulkSource, 'KREABANK_BULKMATCH_MIN_SCORE', 'bulkmatch should honor minimum score threshold setup', $errors);
$assertContains($bulkSource, 'KREABANK_BULKMATCH_AUTO_RELOAD_MAX', 'bulkmatch should honor maximum auto-refresh attempts setup', $errors);
$assertContains($bulkSource, '$enforcePostActionWithToken();', 'bulkmatch confirm action should enforce POST + CSRF token per action', $errors);
$assertContains($bulkSource, "'line_id' => (int) (!empty(\$line['rowid']) ? \$line['rowid'] : 0)", 'bulkmatch scan cache should persist compact line id instead of full line payload', $errors);
$assertContains($bulkSource, "'doc_type' => (string) (!empty(\$suggestion['doc_type']) ? \$suggestion['doc_type'] : '')", 'bulkmatch scan cache should persist compact document type in cache', $errors);
$assertContains($bulkSource, "'doc_id' => (int) (!empty(\$suggestion['doc_id']) ? \$suggestion['doc_id'] : 0)", 'bulkmatch scan cache should persist compact document id in cache', $errors);
$assertContains($bulkSource, "'score' => (int) (!empty(\$suggestion['score']) ? \$suggestion['score'] : 0)", 'bulkmatch scan cache should persist compact score in cache', $errors);
$assertContains($bulkSource, 'KreaBankBulkMatchLowConfidenceSkipped', 'bulkmatch should warn when selected lines are skipped for low confidence', $errors);
$assertContains($bulkSource, "'is_bulk_safe' => (\$matchScore >= \$bulkMatchMinScore)", 'bulkmatch should classify suggestions by minimum score threshold', $errors);
$assertContains($bulkSource, 'reload_count', 'bulkmatch should track bounded auto-refresh attempts in scan state', $errors);
$assertContains($bulkSource, '$formatDisplayDate = static function ($value)', 'bulkmatch should centralize guarded date rendering helper', $errors);
$assertContains($bulkSource, 'if ($ts === false || $ts <= 0)', 'bulkmatch should guard strtotime failures before rendering dates', $errors);
$assertContains($bulkSource, "isModEnabled('degema')", 'bulkmatch should guard external degema link rendering by module availability', $errors);
$assertContains($bulkSource, 'fingerprint', 'bulkmatch should track pending queue fingerprint for cache invalidation', $errors);
$assertContains($parserSource, 'readFileContentSafe(', 'parser should use safe file reads with diagnostics', $errors);
$callHelperBody = $extractFunctionBody($parserSource, 'callHelper');
if ($callHelperBody === '') {
	$errors[] = 'Unable to parse callHelper body';
} else {
	$assertContains($callHelperBody, '$allowed = $this->getStrategyHelperAllowlist();', 'callHelper should resolve helper allowlist through extensible accessor', $errors);
}
$assertContains($parserSource, 'public function registerStrategyHelper(', 'parser should expose helper allowlist extension method', $errors);
$assertContains($parserSource, 'protected function getStrategyHelperAllowlist()', 'parser should expose merged strategy helper allowlist accessor', $errors);

$analyzeBody = $extractFunctionBody($parserSource, 'analyze');
if ($analyzeBody === '') {
	$errors[] = 'Unable to parse analyze body';
} else {
	$assertContains($analyzeBody, 'KreaBank parser analyze forced mapping fallback parse failed:', 'analyze should log forced-mapping fallback parse failures', $errors);
	$assertContains($analyzeBody, 'KreaBank parser analyze automatic parse fallback failed:', 'analyze should log automatic fallback parse failures', $errors);
}

$detectFormatBody = $extractFunctionBody($parserSource, 'detectFormat');
if ($detectFormatBody === '') {
	$errors[] = 'Unable to parse detectFormat body';
} else {
	$assertContains($detectFormatBody, "return 'csv';", 'detectFormat should use explicit csv fallback return', $errors);
	if (strpos($detectFormatBody, "return (\$ext === 'csv' ? 'csv' : 'csv');") !== false) {
		$errors[] = 'detectFormat should not keep dead csv ternary fallback';
	}
}

$parseDateBody = $extractFunctionBody($parserSource, 'parseDate');
if ($parseDateBody === '') {
	$errors[] = 'Unable to parse parseDate body';
} else {
	$assertContains($parseDateBody, 'if ($ymdValid && $dmyValid)', 'parseDate should handle ambiguous 8-digit date tokens explicitly', $errors);
	$assertContains($parseDateBody, '$preferYmd = ($firstFourDigits >= 1970 && $firstFourDigits <= ($currentYear + 5));', 'parseDate should use plausible-year threshold when resolving ambiguous 8-digit dates', $errors);
	$assertContains($parseDateBody, 'ambiguous 8-digit date token', 'parseDate should emit ambiguity diagnostics for ambiguous compact dates', $errors);
}
$resolveThreePartBankDateBody = $extractFunctionBody($parserSource, 'resolveThreePartBankDate');
if ($resolveThreePartBankDateBody === '') {
	$errors[] = 'Unable to parse resolveThreePartBankDate body';
} else {
	$assertContains($resolveThreePartBankDateBody, '$ddmmyyyyCandidate = $this->validateBankDateParts($c, $b, $a);', 'resolveThreePartBankDate should evaluate DD/MM/YYYY candidate explicitly for ambiguous tokens', $errors);
	$assertContains($resolveThreePartBankDateBody, '$mmddyyyyCandidate = $this->validateBankDateParts($c, $a, $b);', 'resolveThreePartBankDate should evaluate MM/DD/YYYY candidate explicitly for ambiguous tokens', $errors);
	$assertContains($resolveThreePartBankDateBody, '$contextMonth = $this->inferBankDateMonth(null, $context);', 'resolveThreePartBankDate should use context month when both day/month tokens are <=12', $errors);
}

$parseLocalizedNumberBody = $extractFunctionBody($parserSource, 'parseLocalizedNumber');
if ($parseLocalizedNumberBody === '') {
	$errors[] = 'Unable to parse parseLocalizedNumber body';
} else {
	$assertContains($parseLocalizedNumberBody, '$hasLeadingMinus = (substr($value, 0, 1) === \'-\');', 'parseLocalizedNumber should normalize leading minus in a single sign pass', $errors);
	$assertContains($parseLocalizedNumberBody, '$hasTrailingMinus = (substr($value, -1) === \'-\');', 'parseLocalizedNumber should normalize trailing minus in a single sign pass', $errors);
	$assertContains($parseLocalizedNumberBody, 'if (strpos($value, \'-\') !== false)', 'parseLocalizedNumber should reject malformed internal minus placement', $errors);
}

$buildExpandedFieldAliasesBody = $extractFunctionBody($parserSource, 'buildExpandedFieldAliases');
if ($buildExpandedFieldAliasesBody === '') {
	$errors[] = 'Unable to parse buildExpandedFieldAliases body';
} else {
	$assertContains($buildExpandedFieldAliasesBody, '$subAliasOwner = array();', 'buildExpandedFieldAliases should track global sub-alias ownership', $errors);
	$assertContains($buildExpandedFieldAliasesBody, 'if (strlen($part) < 6 || isset($seen[$part]))', 'buildExpandedFieldAliases should ignore short generic sub-alias tokens', $errors);
	$assertContains($buildExpandedFieldAliasesBody, 'if (!isset($subAliasOwner[$subAlias]) || (string) $subAliasOwner[$subAlias] !== (string) $fieldKey)', 'buildExpandedFieldAliases should reject cross-field sub-alias collisions', $errors);
}

$readXlsxSharedStringsBody = $extractFunctionBody($parserSource, 'readXlsxSharedStrings');
if ($readXlsxSharedStringsBody === '') {
	$errors[] = 'Unable to parse readXlsxSharedStrings body';
} else {
	$assertContains($readXlsxSharedStringsBody, '$this->removeTempFile($sharedXmlTemp, \'xlsx_shared_strings\');', 'readXlsxSharedStrings should use logged temp-file cleanup', $errors);
	if (strpos($readXlsxSharedStringsBody, '@unlink($sharedXmlTemp)') !== false) {
		$errors[] = 'readXlsxSharedStrings should not suppress shared-string temp cleanup with @unlink';
	}
}

$loadXlsxRowsByZipBody = $extractFunctionBody($parserSource, 'loadXlsxRowsByZip');
if ($loadXlsxRowsByZipBody === '') {
	$errors[] = 'Unable to parse loadXlsxRowsByZip body';
} else {
	$assertContains($loadXlsxRowsByZipBody, '$this->removeTempFile($sheetXmlTemp, \'xlsx_sheet_xml\');', 'loadXlsxRowsByZip should use logged temp-file cleanup', $errors);
	if (strpos($loadXlsxRowsByZipBody, '@unlink($sheetXmlTemp)') !== false) {
		$errors[] = 'loadXlsxRowsByZip should not suppress sheet temp cleanup with @unlink';
	}
}

$decodeTextToUtf8Body = $extractFunctionBody($parserSource, 'decodeTextToUtf8');
if ($decodeTextToUtf8Body === '') {
	$errors[] = 'Unable to parse decodeTextToUtf8 body';
} else {
	$assertContains($decodeTextToUtf8Body, '$nullDensity = ($len > 0 ? ((float) ($evenNulls + $oddNulls) / (float) $len) : 0.0);', 'decodeTextToUtf8 should compute null-byte density before UTF-16 heuristic conversion', $errors);
	$assertContains($decodeTextToUtf8Body, 'if ($len >= 16 && $nullDensity >= 0.20 && ($evenNulls > 0 || $oddNulls > 0))', 'decodeTextToUtf8 should require minimum null-byte density before UTF-16 heuristic conversion', $errors);
}
$expandBankDateYearBody = $extractFunctionBody($parserSource, 'expandBankDateYear');
if ($expandBankDateYearBody === '') {
	$errors[] = 'Unable to parse expandBankDateYear body';
} else {
	$assertContains($expandBankDateYearBody, 'static $cachedCurrentYear = null;', 'expandBankDateYear should cache current year per request', $errors);
}
$detectCsvDelimiterBody = $extractFunctionBody($parserSource, 'detectCsvDelimiter');
if ($detectCsvDelimiterBody === '') {
	$errors[] = 'Unable to parse detectCsvDelimiter body';
} else {
	$assertContains($detectCsvDelimiterBody, '$maxSampleLines = 10;', 'detectCsvDelimiter should sample multiple non-empty lines', $errors);
	$assertContains($detectCsvDelimiterBody, 'while (count($sampleLines) < $maxSampleLines && ($line = fgets($handle)) !== false)', 'detectCsvDelimiter should iterate over multiple lines instead of reading only the first line', $errors);
	$assertContains($detectCsvDelimiterBody, '$fieldCountFrequencies = array();', 'detectCsvDelimiter should score delimiter consistency across sampled lines', $errors);
	$assertContains($detectCsvDelimiterBody, '$score = ($maxConsistentLines * 100) + ($linesWithDelimiter * 10) + $totalCount;', 'detectCsvDelimiter should combine consistency and presence when selecting delimiter', $errors);
}
$loadDelimitedRowsBody = $extractFunctionBody($parserSource, 'loadDelimitedRows');
if ($loadDelimitedRowsBody === '') {
	$errors[] = 'Unable to parse loadDelimitedRows body';
} else {
	$assertContains($loadDelimitedRowsBody, "\$this->readFileContentSafe(\$filePath, 0, 'load_delimited_rows')", 'loadDelimitedRows should use safe parser file reader', $errors);
	if (strpos($loadDelimitedRowsBody, 'file_get_contents(') !== false) {
		$errors[] = 'loadDelimitedRows should not call file_get_contents directly';
	}
}
$loadXlsRowsAsHtmlTableBody = $extractFunctionBody($parserSource, 'loadXlsRowsAsHtmlTable');
if ($loadXlsRowsAsHtmlTableBody === '') {
	$errors[] = 'Unable to parse loadXlsRowsAsHtmlTable body';
} else {
	$assertContains($loadXlsRowsAsHtmlTableBody, "\$this->readFileContentSafe(\$filePath, 0, 'load_xls_html')", 'loadXlsRowsAsHtmlTable should use safe parser file reader', $errors);
	if (strpos($loadXlsRowsAsHtmlTableBody, 'file_get_contents(') !== false) {
		$errors[] = 'loadXlsRowsAsHtmlTable should not call file_get_contents directly';
	}
}
$loadExcelRowsByCommandConversionBody = $extractFunctionBody($parserSource, 'loadExcelRowsByCommandConversion');
if ($loadExcelRowsByCommandConversionBody === '') {
	$errors[] = 'Unable to parse loadExcelRowsByCommandConversion body';
} else {
	$assertContains($loadExcelRowsByCommandConversionBody, 'basename((string) pathinfo((string) $filePath, PATHINFO_FILENAME))', 'loadExcelRowsByCommandConversion should derive candidate name from sanitized basename only', $errors);
	$assertContains($loadExcelRowsByCommandConversionBody, '$outDirRealPath = realpath($outDir);', 'loadExcelRowsByCommandConversion should resolve outdir realpath before candidate use', $errors);
	$assertContains($loadExcelRowsByCommandConversionBody, '$candidateDir = realpath(dirname($candidate));', 'loadExcelRowsByCommandConversion should resolve candidate directory realpath', $errors);
	$assertContains($loadExcelRowsByCommandConversionBody, '$candidateInsideOutDir = (', 'loadExcelRowsByCommandConversion should enforce candidate-outdir containment guard', $errors);
	if (strpos($loadExcelRowsByCommandConversionBody, '@unlink($candidate)') !== false) {
		$errors[] = 'loadExcelRowsByCommandConversion should not suppress candidate cleanup failures with @unlink';
	}
}
if (strpos($parserSource, '@file_get_contents') !== false) {
	$errors[] = 'parser should not suppress file reads with @file_get_contents';
}
if (strpos($serviceSource, 'getBatchMlSamplesStorePath(') !== false) {
	$errors[] = 'service should not keep legacy JSON ML sample path helper';
}
if (strpos($serviceSource, 'loadBatchMlSamplesFromLegacyStore(') !== false) {
	$errors[] = 'service should not keep legacy JSON ML sample loader';
}
if (strpos($serviceSource, 'migrateLegacyBatchMlSamplesToDb(') !== false) {
	$errors[] = 'service should not keep legacy JSON-to-DB migration path';
}
if (strpos($serviceSource, '@unlink(') !== false) {
	$errors[] = 'service should not suppress cache unlink failures with @unlink';
}
if (strpos($serviceSource, 'array_fill(0, 16, 0.0)') !== false) {
	$errors[] = 'service should not keep the old 16-bin supplier ML hash vector';
}
$assertContains($readFile($rootDir.'/reconcile.php'), "entry_submit_action\" value=\"supplier_invoice", 'reconcile should expose supplier invoice submit button in quick entry form', $errors);

$bulkBatchCalls = substr_count($bulkSource, 'getSuggestionsForLines(');
if ($bulkBatchCalls < 2) {
	$errors[] = 'bulkmatch should call getSuggestionsForLines in confirm and scan flows';
}

if (is_readable($runtimeFile)) {
	require_once $runtimeFile;
	if (function_exists('kreabankRunRuntimeReconciliationAssertions')) {
		$runtimeErrors = kreabankRunRuntimeReconciliationAssertions($rootDir);
		foreach ((array) $runtimeErrors as $runtimeError) {
			$errors[] = 'runtime: '.(string) $runtimeError;
		}
	} else {
		$errors[] = 'runtime assertions function not available';
	}
} else {
	$errors[] = 'runtime assertions file missing';
}

if (is_readable($quickFlowRuntimeFile)) {
	require_once $quickFlowRuntimeFile;
	if (function_exists('kreabankRunQuickFlowRuntimeAssertions')) {
		$quickFlowRuntimeErrors = kreabankRunQuickFlowRuntimeAssertions($rootDir);
		foreach ((array) $quickFlowRuntimeErrors as $quickFlowRuntimeError) {
			$errors[] = 'quick-runtime: '.(string) $quickFlowRuntimeError;
		}
	} else {
		$errors[] = 'quick-flow runtime assertions function not available';
	}
} else {
	$errors[] = 'quick-flow runtime assertions file missing';
}

if (!empty($errors)) {
	fwrite(STDERR, "Reconciliation guard checks failed:".PHP_EOL);
	foreach ($errors as $error) {
		fwrite(STDERR, ' - '.$error.PHP_EOL);
	}
	exit(1);
}

echo "OK: reconciliation guard checks".PHP_EOL;
exit(0);
