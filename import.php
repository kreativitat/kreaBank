<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file       kreabank/import.php
 * \ingroup    kreabank
 * \brief      Statement import page with mapping confirmation.
 */

$res = 0;
if (!$res && !empty($_SERVER['CONTEXT_DOCUMENT_ROOT'])) {
	$res = @include $_SERVER['CONTEXT_DOCUMENT_ROOT'] . '/main.inc.php';
}
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);
$i = strlen($tmp) - 1;
$j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] === $tmp2[$j]) {
	$i--;
	$j--;
}
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1)) . '/main.inc.php')) {
	$res = @include substr($tmp, 0, ($i + 1)) . '/main.inc.php';
}
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1))) . '/main.inc.php')) {
	$res = @include dirname(substr($tmp, 0, ($i + 1))) . '/main.inc.php';
}
if (!$res && file_exists('../main.inc.php')) {
	$res = @include '../main.inc.php';
}
if (!$res && file_exists('../../main.inc.php')) {
	$res = @include '../../main.inc.php';
}
if (!$res && file_exists('../../../main.inc.php')) {
	$res = @include '../../../main.inc.php';
}
if (!$res) {
	die('Include of main fails');
}

require_once DOL_DOCUMENT_ROOT . '/core/lib/files.lib.php';
require_once __DIR__ . '/lib/kreabank.lib.php';
require_once __DIR__ . '/class/KreaBankService.class.php';

/** @var DoliDB $db */
/** @var User $user */
/** @var Translate $langs */

$langs->loadLangs(array('banks', 'errors', 'kreabank@kreabank'));

if (!isModEnabled('kreabank')) {
	accessforbidden();
}
if (!isModEnabled('banque')) {
	accessforbidden($langs->trans('ModuleBankDisabled'));
}
if (!$user->hasRight('kreabank', 'reconciliation', 'write')) {
	accessforbidden($langs->trans('KreaBankNoPermission'));
}
if (!$user->hasRight('banque', 'modifier')) {
	accessforbidden($langs->trans('ErrorForbidden'));
}

if (function_exists('register_shutdown_function')) {
	register_shutdown_function(static function () {
		$lastError = error_get_last();
		if (!is_array($lastError) || empty($lastError['type'])) {
			return;
		}

		$fatalTypes = array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR);
		if (!in_array((int) $lastError['type'], $fatalTypes, true)) {
			return;
		}

		if (function_exists('dol_syslog')) {
			dol_syslog(
				'KreaBank import shutdown fatal: ' . ((string) $lastError['message']) . ' at ' . ((string) $lastError['file']) . ':' . ((int) $lastError['line']),
				LOG_ERR
			);
		}
	});
}

$action = GETPOST('action', 'aZ09');
$service = new KreaBankService($db, $user, $langs);
$importResult = null;
$analysis = null;
$lastImportedBankAccountId = 0;

$wizardStoreKey = 'kreabank_import_wizard_e' . ((int) $conf->entity) . '_u' . ((int) $user->id);
if (empty($_SESSION[$wizardStoreKey]) || !is_array($_SESSION[$wizardStoreKey])) {
	$_SESSION[$wizardStoreKey] = array();
}

$cleanupWizard = function ($token) use (&$wizardStoreKey) {
	if ($token === '' || empty($_SESSION[$wizardStoreKey][$token]) || !is_array($_SESSION[$wizardStoreKey][$token])) {
		return;
	}
	$wizard = $_SESSION[$wizardStoreKey][$token];
	if (!empty($wizard['file_path']) && is_string($wizard['file_path']) && file_exists($wizard['file_path'])) {
		@unlink($wizard['file_path']);
	}
	unset($_SESSION[$wizardStoreKey][$token]);
};

$wizardMaxEntries = (int) getDolGlobalInt('KREABANK_IMPORT_WIZARD_MAX_ENTRIES');
if ($wizardMaxEntries <= 0) {
	$wizardMaxEntries = 5;
}
$enforceWizardStoreLimit = function ($maxEntries) use (&$wizardStoreKey, $cleanupWizard) {
	$maxEntries = (int) $maxEntries;
	if ($maxEntries <= 0 || empty($_SESSION[$wizardStoreKey]) || !is_array($_SESSION[$wizardStoreKey])) {
		return;
	}
	if (count($_SESSION[$wizardStoreKey]) <= $maxEntries) {
		return;
	}

	$entries = array();
	foreach ($_SESSION[$wizardStoreKey] as $storedToken => $storedWizard) {
		if (!is_array($storedWizard)) {
			$cleanupWizard((string) $storedToken);
			continue;
		}
		$lastTouched = 0;
		if (!empty($storedWizard['tms'])) {
			$lastTouched = (int) $storedWizard['tms'];
		} elseif (!empty($storedWizard['datec'])) {
			$lastTouched = (int) $storedWizard['datec'];
		}
		$entries[] = array(
			'token' => (string) $storedToken,
			'tms' => (int) $lastTouched,
		);
	}
	if (count($entries) <= $maxEntries) {
		return;
	}

	usort($entries, static function ($a, $b) {
		$ta = (int) $a['tms'];
		$tb = (int) $b['tms'];
		if ($ta !== $tb) {
			return $ta <=> $tb;
		}

		return strcmp((string) $a['token'], (string) $b['token']);
	});

	$removeCount = count($entries) - $maxEntries;
	for ($i = 0; $i < $removeCount; $i++) {
		if (!empty($entries[$i]['token'])) {
			$cleanupWizard((string) $entries[$i]['token']);
		}
	}
};

$wizardTokenTtl = (int) getDolGlobalInt('KREABANK_IMPORT_WIZARD_TTL');
if ($wizardTokenTtl <= 0) {
	$wizardTokenTtl = 86400;
}
$wizardNow = dol_now();
$activeWizardFiles = array();
foreach ($_SESSION[$wizardStoreKey] as $storedToken => $storedWizard) {
	if (!is_array($storedWizard)) {
		unset($_SESSION[$wizardStoreKey][$storedToken]);
		continue;
	}
	$filePath = '';
	if (!empty($storedWizard['file_path']) && is_string($storedWizard['file_path'])) {
		$filePath = $storedWizard['file_path'];
		$activeWizardFiles[$filePath] = true;
	}
	$lastTouched = 0;
	if (!empty($storedWizard['tms'])) {
		$lastTouched = (int) $storedWizard['tms'];
	} elseif (!empty($storedWizard['datec'])) {
		$lastTouched = (int) $storedWizard['datec'];
	} elseif ($filePath !== '' && file_exists($filePath)) {
		$lastTouched = (int) @filemtime($filePath);
	}
	if ($lastTouched > 0 && ($wizardNow - $lastTouched) > $wizardTokenTtl) {
		$cleanupWizard((string) $storedToken);
	}
}
$enforceWizardStoreLimit($wizardMaxEntries);
$wizardImportTempDir = DOL_DATA_ROOT . '/kreabank/import';
if (is_dir($wizardImportTempDir)) {
	$wizardTempFiles = glob($wizardImportTempDir . '/wiz_*');
	if (is_array($wizardTempFiles)) {
		foreach ($wizardTempFiles as $wizardTempFile) {
			if (!is_string($wizardTempFile) || $wizardTempFile === '' || is_dir($wizardTempFile)) {
				continue;
			}
			if (!empty($activeWizardFiles[$wizardTempFile])) {
				continue;
			}
			$fileMtime = (int) @filemtime($wizardTempFile);
			if ($fileMtime > 0 && ($wizardNow - $fileMtime) > $wizardTokenTtl) {
				@unlink($wizardTempFile);
			}
		}
	}
}

$wizardToken = GETPOST('wizard_token', 'alphanohtml');
$currentWizard = (!empty($wizardToken) && !empty($_SESSION[$wizardStoreKey][$wizardToken]) && is_array($_SESSION[$wizardStoreKey][$wizardToken]))
	? $_SESSION[$wizardStoreKey][$wizardToken]
	: null;
if (!empty($wizardToken) && !empty($currentWizard)) {
	$currentWizard['tms'] = $wizardNow;
	$_SESSION[$wizardStoreKey][$wizardToken] = $currentWizard;
}

$normalizeErrorMessage = function ($message) {
	$text = trim((string) $message);

	return $text;
};

$enforcePostActionWithToken = static function () {
	if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !kreabankIsTokenValid()) {
		accessforbidden('Invalid token');
	}
};

$normalizeLineRanks = function ($rawRanks) {
	$ranks = array();
	if (!is_array($rawRanks)) {
		return $ranks;
	}
	foreach ($rawRanks as $rawRank) {
		$rank = (int) $rawRank;
		if ($rank > 0) {
			$ranks[$rank] = $rank;
		}
	}

	return array_values($ranks);
};

$buildReconcileRedirectUrl = function ($lineId = 0) {
	$url = dol_buildpath('/custom/kreabank/reconcile.php?mainmenu=bank&leftmenu=kreabank_reconcile', 1);
	if ((int) $lineId > 0) {
		$url .= '&line_id=' . ((int) $lineId);
	}

	return $url;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	if (!kreabankIsTokenValid()) {
		accessforbidden('Invalid token');
	}
}

if ($action === 'cancel' && !empty($wizardToken)) {
	$cleanupWizard($wizardToken);
	$currentWizard = null;
	$wizardToken = '';
	setEventMessages($langs->trans('KreaBankImportCancelled'), null, 'warnings');
}

if ($action === 'finish_duplicate_review' && !empty($wizardToken) && !empty($currentWizard)) {
	$redirectLineId = 0;
	if (
		!empty($currentWizard['last_import_result']) && is_array($currentWizard['last_import_result'])
		&& !empty($currentWizard['last_import_result']['bank_line_ids']) && is_array($currentWizard['last_import_result']['bank_line_ids'])
	) {
		$redirectLineId = (int) reset($currentWizard['last_import_result']['bank_line_ids']);
	}
	$cleanupWizard($wizardToken);
	$currentWizard = null;
	$wizardToken = '';
	setEventMessages($langs->trans('KreaBankImportDuplicateReviewDone'), null, 'mesgs');
	header('Location: ' . $buildReconcileRedirectUrl($redirectLineId));
	exit;
}

if ($action === 'delete_statement') {
	$enforcePostActionWithToken();
	$deleteBankAccountId = GETPOSTINT('delete_bank_account_id');
	$deleteStatementRef = trim((string) GETPOST('delete_statement_ref', 'restricthtml'));

	try {
		$deleteResult = $service->deleteImportedStatement($deleteBankAccountId, $deleteStatementRef, false);
		setEventMessages($langs->trans('KreaBankImportDeleteDone', (string) ((int) $deleteResult['deleted_lines'])), null, 'mesgs');
	} catch (Exception $e) {
		setEventMessages($normalizeErrorMessage($e->getMessage()), null, 'errors');
	}
}

if ($action === 'open_native_statement') {
	$enforcePostActionWithToken();
	$openBankAccountId = GETPOSTINT('open_bank_account_id');
	$openStatementRef = trim((string) GETPOST('open_statement_ref', 'restricthtml'));

	try {
		if ($openBankAccountId <= 0 || $openStatementRef === '') {
			throw new Exception($langs->trans('KreaBankInvalidInput'));
		}

		$service->ensureNativeStatementLines($openBankAccountId, $openStatementRef);
		$nativeStatementUrl = dol_buildpath('/compta/bank/releve.php?num=' . rawurlencode($openStatementRef) . '&account=' . $openBankAccountId, 1);
		header('Location: ' . $nativeStatementUrl);
		exit;
	} catch (Exception $e) {
		setEventMessages($normalizeErrorMessage($e->getMessage()), null, 'errors');
	}
}

if ($action === 'download_statement_csv') {
	$enforcePostActionWithToken();
	$downloadBankAccountId = GETPOSTINT('download_bank_account_id');
	$downloadStatementRef = trim((string) GETPOST('download_statement_ref', 'restricthtml'));

	try {
		if ($downloadBankAccountId <= 0 || $downloadStatementRef === '') {
			throw new Exception($langs->trans('KreaBankInvalidInput'));
		}
		$bankAccountEntityList = getEntity('bank_account');
		if ($bankAccountEntityList === '' || $bankAccountEntityList === null) {
			$bankAccountEntityList = (string) ((int) $conf->entity);
		}
		// Compatibility path: legacy staged statements are materialized into native rows on demand.
		$service->ensureNativeStatementLines($downloadBankAccountId, $downloadStatementRef);

		$sqlLines = 'SELECT b.rowid,';
		$sqlLines .= ' COALESCE(m.operation_date, b.dateo) as operation_date,';
		$sqlLines .= ' COALESCE(m.value_date, b.datev) as value_date,';
		$sqlLines .= ' CASE';
		$sqlLines .= ' WHEN m.direction < 0 THEN -ABS(COALESCE(m.amount, b.amount))';
		$sqlLines .= ' WHEN m.direction > 0 THEN ABS(COALESCE(m.amount, b.amount))';
		$sqlLines .= ' ELSE COALESCE(m.amount, b.amount)';
		$sqlLines .= ' END as amount,';
		$sqlLines .= " COALESCE(m.currency, ba.currency_code, 'EUR') as currency,";
		$sqlLines .= ' m.running_balance, m.payment_reference, m.bank_reference,';
		$sqlLines .= ' m.counterparty_name, m.counterparty_iban, COALESCE(m.description, b.label) as description,';
		$sqlLines .= ' CASE WHEN (m.status = 2 OR b.rappro = 1) THEN 2 ELSE COALESCE(m.status, 0) END as status,';
		$sqlLines .= ' 0 as is_duplicate';
		$sqlLines .= ' FROM ' . $db->prefix() . 'bank as b';
		$sqlLines .= ' INNER JOIN ' . $db->prefix() . 'bank_account as ba ON ba.rowid = b.fk_account';
		$sqlLines .= ' LEFT JOIN ' . $db->prefix() . 'kreabank_bankmeta as m ON m.entity = ' . ((int) $conf->entity) . ' AND m.fk_bank_line = b.rowid';
		$sqlLines .= ' WHERE ba.entity IN (' . $bankAccountEntityList . ')';
		$sqlLines .= ' AND b.fk_account = ' . ((int) $downloadBankAccountId);
		$sqlLines .= " AND b.num_releve = '" . $db->escape($downloadStatementRef) . "'";
		$sqlLines .= " AND (m.rowid IS NOT NULL OR b.note LIKE 'KreaBank import %' OR b.note LIKE 'KreaBank staged import %')";
		$sqlLines .= ' ORDER BY COALESCE(m.operation_date, b.dateo) ASC, b.rowid ASC';
		$resLines = $db->query($sqlLines);
		if (!$resLines) {
			throw new Exception($db->lasterror());
		}
		if ($db->num_rows($resLines) <= 0) {
			throw new Exception($langs->trans('KreaBankImportDeleteNotFound'));
		}

		$safeRef = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string) $downloadStatementRef);
		if ($safeRef === '' || $safeRef === null) {
			$safeRef = 'statement_' . $downloadBankAccountId;
		}
		$fileName = 'kreabank_' . $safeRef . '.csv';

		while (ob_get_level() > 0) {
			ob_end_clean();
		}
		header('Content-Type: text/csv; charset=UTF-8');
		header('Content-Disposition: attachment; filename="' . $fileName . '"');
		header('Cache-Control: max-age=0, must-revalidate');
		header('Pragma: public');
		header('Expires: 0');

		$out = fopen('php://output', 'w');
		if ($out === false) {
			throw new Exception('Unable to open CSV output stream');
		}

		fwrite($out, "\xEF\xBB\xBF");
		$separator = ';';
		fputcsv($out, array(
			'line_rank',
			'operation_date',
			'value_date',
			'amount',
			'currency',
			'running_balance',
			'payment_reference',
			'bank_reference',
			'counterparty_name',
			'counterparty_iban',
			'description',
			'status',
			'is_duplicate',
		), $separator);

		$lineRank = 0;
		while ($objLine = $db->fetch_object($resLines)) {
			$lineRank++;
			fputcsv($out, array(
				$lineRank,
				(string) (!empty($objLine->operation_date) ? $objLine->operation_date : ''),
				(string) (!empty($objLine->value_date) ? $objLine->value_date : ''),
				(string) (!is_null($objLine->amount) ? $objLine->amount : ''),
				(string) (!empty($objLine->currency) ? $objLine->currency : ''),
				(string) (!is_null($objLine->running_balance) ? $objLine->running_balance : ''),
				(string) (!empty($objLine->payment_reference) ? $objLine->payment_reference : ''),
				(string) (!empty($objLine->bank_reference) ? $objLine->bank_reference : ''),
				(string) (!empty($objLine->counterparty_name) ? $objLine->counterparty_name : ''),
				(string) (!empty($objLine->counterparty_iban) ? $objLine->counterparty_iban : ''),
				(string) (!empty($objLine->description) ? $objLine->description : ''),
				(int) (!empty($objLine->status) ? $objLine->status : 0),
				(int) (!empty($objLine->is_duplicate) ? $objLine->is_duplicate : 0),
			), $separator);
		}
		fclose($out);
		$db->close();
		exit;
	} catch (Exception $e) {
		setEventMessages($langs->trans('KreaBankImportDownloadFailed', $normalizeErrorMessage($e->getMessage())), null, 'errors');
	}
}

if ($action === 'analyze') {
	$bankAccountId = GETPOSTINT('bank_account_id');
	$statementDate = GETPOST('statement_date', 'alpha');
	$forceMappingEditor = 0;
	if ((int) GETPOST('force_mapping_editor_present', 'int') === 1) {
		$forceMappingEditor = GETPOST('force_mapping_editor', 'int') ? 1 : 0;
	} elseif (function_exists('GETPOSTISSET') && GETPOSTISSET('force_mapping_editor')) {
		$forceMappingEditor = GETPOST('force_mapping_editor', 'int') ? 1 : 0;
	}
	$fileName = dol_sanitizeFileName((string) (isset($_FILES['statement_file']['name']) ? $_FILES['statement_file']['name'] : ''));
	$tmpName = (string) (isset($_FILES['statement_file']['tmp_name']) ? $_FILES['statement_file']['tmp_name'] : '');
	$fileError = isset($_FILES['statement_file']['error']) ? (int) $_FILES['statement_file']['error'] : 0;

	try {
		$service->logSchemaDiagnostics('import_analyze');

		if ($tmpName === '' || $fileError !== 0 || $fileName === '' || !is_readable($tmpName)) {
			throw new Exception($langs->trans('ErrorFileNotUploaded'));
		}
		$fileSize = isset($_FILES['statement_file']['size']) ? (int) $_FILES['statement_file']['size'] : 0;
		$maxImportBytes = (int) getDolGlobalInt('KREABANK_IMPORT_MAX_FILE_SIZE');
		if ($maxImportBytes <= 0) {
			$maxImportBytes = 20971520; // 20 MB default hard limit.
		}
		if ($fileSize <= 0) {
			throw new Exception($langs->trans('ErrorFileNotUploaded'));
		}
		if ($fileSize > $maxImportBytes) {
			throw new Exception($langs->trans('KreaBankImportFileTooLarge', (string) $maxImportBytes));
		}

		$newWizardToken = bin2hex(random_bytes(32));
		$importTempDir = DOL_DATA_ROOT . '/kreabank/import';
		if (!is_dir($importTempDir)) {
			dol_mkdir($importTempDir);
		}

		$storedPath = $importTempDir . '/wiz_' . $newWizardToken . '_' . preg_replace('/[^A-Za-z0-9._-]/', '_', $fileName);
		if (!dol_move_uploaded_file($tmpName, $storedPath, 0, 0, $fileError)) {
			throw new Exception($langs->trans('ErrorFileNotUploaded'));
		}

		$analysis = $service->analyzeImportFile($storedPath, $fileName, $bankAccountId, $forceMappingEditor);
		$wizardPayload = array(
			'file_path' => $storedPath,
			'file_name' => $fileName,
			'bank_account_id' => $bankAccountId,
			'statement_date' => $statementDate ?: dol_print_date(dol_now(), '%Y-%m-%d'),
			'force_mapping_editor' => $forceMappingEditor,
			'datec' => dol_now(),
			'tms' => dol_now(),
			'phase' => 'ready_confirm',
			'analysis' => $analysis,
		);
		$_SESSION[$wizardStoreKey][$newWizardToken] = $wizardPayload;
		$enforceWizardStoreLimit($wizardMaxEntries);

		$wizardToken = $newWizardToken;
		$currentWizard = $wizardPayload;

		if ($bankAccountId <= 0) {
			setEventMessages($langs->trans('ErrorFieldRequired', $langs->trans('BankAccount')), null, 'warnings');
		}
		if (!empty($analysis['profile_applied']) && !empty($analysis['profile']['label'])) {
			setEventMessages($langs->trans('KreaBankImportProfileApplied') . ': ' . dol_escape_htmltag((string) $analysis['profile']['label']), null, 'mesgs');
		}
		if (!empty($analysis['profile_rejected']) && !empty($analysis['profile']['label'])) {
			setEventMessages($langs->trans('KreaBankImportProfileRejected') . ': ' . dol_escape_htmltag((string) $analysis['profile']['label']), null, 'warnings');
		}
	} catch (Throwable $e) {
		dol_syslog('KreaBank import analyze fatal: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine(), LOG_ERR);
		setEventMessages($langs->trans('KreaBankImportAnalyzeFailed', $normalizeErrorMessage($e->getMessage())), null, 'errors');
	}
}

if ($action === 'import_selected_duplicates' && !empty($currentWizard)) {
	$isDuplicateReview = (!empty($currentWizard['phase']) && (string) $currentWizard['phase'] === 'duplicate_review');
	if (!$isDuplicateReview) {
		setEventMessages($langs->trans('KreaBankImportDuplicateSelectionLocked'), null, 'warnings');
		if (!empty($currentWizard['last_import_result']) && is_array($currentWizard['last_import_result'])) {
			$importResult = $currentWizard['last_import_result'];
		}
	}
	$analysis = !empty($currentWizard['analysis']) && is_array($currentWizard['analysis']) ? $currentWizard['analysis'] : array();
	$mapping = !empty($analysis['suggested_mapping']) && is_array($analysis['suggested_mapping']) ? $analysis['suggested_mapping'] : array();
	$selectedRanks = $isDuplicateReview ? $normalizeLineRanks(GETPOST('duplicate_line_rank', 'array')) : array();
	if (!$isDuplicateReview) {
		// nothing else to do
	} elseif (empty($selectedRanks)) {
		setEventMessages($langs->trans('KreaBankImportSelectAtLeastOneLine'), null, 'warnings');
		if (!empty($currentWizard['last_import_result']) && is_array($currentWizard['last_import_result'])) {
			$importResult = $currentWizard['last_import_result'];
		}
	} else {
		try {
			$filePath = (string) $currentWizard['file_path'];
			$fileName = (string) $currentWizard['file_name'];
			$confirmBankAccountId = (int) $currentWizard['bank_account_id'];
			$confirmStatementDate = (string) $currentWizard['statement_date'];
			if ($filePath === '' || !is_readable($filePath)) {
				throw new Exception($langs->trans('ErrorFileNotFound'));
			}
			if ($confirmBankAccountId <= 0) {
				throw new Exception($langs->trans('ErrorFieldRequired', $langs->trans('BankAccount')));
			}

			$useMappingParse = !empty($currentWizard['last_use_mapping_parse']) && !empty($analysis['supports_mapping']) && !empty($mapping);
			if ($useMappingParse) {
				$parsed = $service->parseStatementWithMapping($filePath, $fileName, $mapping, $confirmBankAccountId);
			} else {
				$parsed = $service->parseStatementFile($filePath, $fileName, $confirmBankAccountId);
			}

			$lines = (!empty($parsed['lines']) && is_array($parsed['lines'])) ? $parsed['lines'] : array();
			$selectedLookup = array_fill_keys($selectedRanks, 1);
			$selectedLines = array();
			foreach ($lines as $lineIndex => $line) {
				$lineRank = ((int) $lineIndex + 1);
				if (isset($selectedLookup[$lineRank])) {
					$selectedLines[$lineIndex] = $line;
				}
			}
			if (empty($selectedLines)) {
				throw new Exception($langs->trans('KreaBankImportSelectedLinesNotFound'));
			}

			$selectedPayload = array(
				'format' => !empty($parsed['format']) ? (string) $parsed['format'] : 'csv',
				'lines' => $selectedLines,
			);
			$importResult = $service->importStatementFromParsed(
				$selectedPayload,
				$fileName,
				$confirmBankAccountId,
				($confirmStatementDate !== '' ? $confirmStatementDate : null),
				array(
					'allow_duplicate_import' => 1,
					'duplicate_details_limit' => 300,
				)
			);
			$lastImportedBankAccountId = $confirmBankAccountId;
			setEventMessages($langs->trans('KreaBankImportSelectedLinesDone', '<strong>' . ((int) $importResult['imported_lines']) . '</strong>'), null, 'mesgs');
			$redirectLineId = 0;
			if (!empty($importResult['bank_line_ids']) && is_array($importResult['bank_line_ids'])) {
				$redirectLineId = (int) reset($importResult['bank_line_ids']);
			}

			$cleanupWizard($wizardToken);
			$currentWizard = null;
			$wizardToken = '';
			$analysis = null;
			header('Location: ' . $buildReconcileRedirectUrl($redirectLineId));
			exit;
		} catch (Throwable $e) {
			dol_syslog('KreaBank duplicate selection import fatal: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine(), LOG_ERR);
			setEventMessages($langs->trans('KreaBankImportFailed', $normalizeErrorMessage($e->getMessage())), null, 'errors');
			if (!empty($currentWizard['last_import_result']) && is_array($currentWizard['last_import_result'])) {
				$importResult = $currentWizard['last_import_result'];
			}
		}
	}
}

if ($action === 'confirm' && !empty($currentWizard)) {
	$analysis = !empty($currentWizard['analysis']) && is_array($currentWizard['analysis']) ? $currentWizard['analysis'] : array();
	$mapping = !empty($analysis['suggested_mapping']) && is_array($analysis['suggested_mapping']) ? $analysis['suggested_mapping'] : array();
	$wizardPhase = !empty($currentWizard['phase']) ? (string) $currentWizard['phase'] : 'ready_confirm';
	if ($wizardPhase === 'duplicate_review') {
		setEventMessages($langs->trans('KreaBankImportAlreadyConfirmedReviewDuplicates'), null, 'warnings');
		if (!empty($currentWizard['last_import_result']) && is_array($currentWizard['last_import_result'])) {
			$importResult = $currentWizard['last_import_result'];
		}
		if (!empty($currentWizard['bank_account_id'])) {
			$lastImportedBankAccountId = (int) $currentWizard['bank_account_id'];
		}
	} else {
		$originalMapping = $mapping;
		$mappingChanged = false;
		$mappingWasForced = !empty($analysis['mapping_forced']);
		$mappingProfileApplied = !empty($analysis['profile_applied']);
		$mappingFields = array(
			'header_row',
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

		if (!empty($analysis['supports_mapping'])) {
			foreach ($mappingFields as $field) {
				$fieldKey = 'map_' . $field;
				if (function_exists('GETPOSTISSET') && GETPOSTISSET($fieldKey)) {
					$mapping[$field] = GETPOSTINT($fieldKey);
				} else {
					$mapping[$field] = isset($mapping[$field]) ? (int) $mapping[$field] : -1;
				}
				$originalValue = isset($originalMapping[$field]) ? (int) $originalMapping[$field] : -1;
				if ((int) $mapping[$field] !== $originalValue) {
					$mappingChanged = true;
				}
			}
		}
		$useMappingParse = !empty($analysis['supports_mapping']) && ($mappingChanged || ($mappingProfileApplied && !$mappingWasForced));
		if ($useMappingParse && (int) $mapping['amount'] < 0 && (int) $mapping['debit'] < 0 && (int) $mapping['credit'] < 0) {
			setEventMessages($langs->trans('KreaBankImportAmountColumnRequired'), null, 'errors');
			$analysis['suggested_mapping'] = $mapping;
			$currentWizard['analysis'] = $analysis;
			$_SESSION[$wizardStoreKey][$wizardToken] = $currentWizard;
		} else {
			$confirmBankAccountId = GETPOSTINT('bank_account_id_confirm');
			if ($confirmBankAccountId <= 0) {
				$confirmBankAccountId = (int) $currentWizard['bank_account_id'];
			}
			$confirmStatementDate = GETPOST('statement_date_confirm', 'alpha');
			if ($confirmStatementDate === '') {
				$confirmStatementDate = (string) $currentWizard['statement_date'];
			}
			$allowDuplicateImport = GETPOST('allow_duplicate_import', 'int') ? 1 : 0;

			try {
				$filePath = (string) $currentWizard['file_path'];
				$fileName = (string) $currentWizard['file_name'];
				if ($filePath === '' || !is_readable($filePath)) {
					throw new Exception($langs->trans('ErrorFileNotFound'));
				}
				if ($confirmBankAccountId <= 0) {
					throw new Exception($langs->trans('ErrorFieldRequired', $langs->trans('BankAccount')));
				}

				if ($useMappingParse) {
					$parsed = $service->parseStatementWithMapping($filePath, $fileName, $mapping, $confirmBankAccountId);
				} else {
					$parsed = $service->parseStatementFile($filePath, $fileName, $confirmBankAccountId);
				}

				$importResult = $service->importStatementFromParsed(
					$parsed,
					$fileName,
					$confirmBankAccountId,
					$confirmStatementDate ?: null,
					array(
						'allow_duplicate_import' => $allowDuplicateImport,
						'duplicate_details_limit' => 500,
					)
				);
				$lastImportedBankAccountId = (int) $confirmBankAccountId;

				$msg = $langs->trans('KreaBankImportDone') . ' ' . $langs->trans('KreaBankImportedLines') . ': <strong>' . ((int) $importResult['imported_lines']) . '</strong>';
				$msg .= ' | ' . $langs->trans('KreaBankDetectedDuplicates') . ': <strong>' . ((int) $importResult['duplicates']) . '</strong>';
				if (!empty($importResult['duplicates_skipped']) || !empty($importResult['duplicates_imported'])) {
					$msg .= ' | ' . $langs->trans('KreaBankImportSummaryIgnored') . ': <strong>' . ((int) (!empty($importResult['duplicates_skipped']) ? $importResult['duplicates_skipped'] : 0)) . '</strong>';
					$msg .= ' | ' . $langs->trans('KreaBankImportSummaryForceImported') . ': <strong>' . ((int) (!empty($importResult['duplicates_imported']) ? $importResult['duplicates_imported'] : 0)) . '</strong>';
				}
				setEventMessages($msg, null, 'mesgs');
				if (!empty($importResult['duplicates_reconciled'])) {
					setEventMessages($langs->trans('KreaBankImportDuplicatesReconciledWarning', (string) $importResult['duplicates_reconciled']), null, 'warnings');
				}
				if (!empty($importResult['duplicates']) && empty($allowDuplicateImport)) {
					setEventMessages($langs->trans('KreaBankImportDuplicatesChooseWarning'), null, 'warnings');
				}

				if (!empty($analysis['supports_mapping']) && GETPOST('save_profile', 'alpha') === '1') {
					$profileLabel = trim((string) GETPOST('profile_label', 'restricthtml'));
					if ($profileLabel === '') {
						$profileLabel = $langs->trans('KreaBankImportProfileDefaultLabel', dol_print_date(dol_now(), '%Y-%m-%d %H:%M:%S'));
					}
					$service->saveImportProfile(
						$confirmBankAccountId,
						(string) $analysis['format'],
						(string) $analysis['fingerprint'],
						$profileLabel,
						$mapping,
						(int) $mapping['header_row'],
						array(
							'layout_signature' => !empty($analysis['layout_signature']) ? (string) $analysis['layout_signature'] : '',
							'columns' => !empty($analysis['columns']) && is_array($analysis['columns']) ? $analysis['columns'] : array(),
							'header_row_index' => isset($analysis['header_row_index']) ? (int) $analysis['header_row_index'] : -1,
							'format' => (string) $analysis['format'],
						)
					);
					setEventMessages($langs->trans('KreaBankImportProfileSaved') . ': ' . dol_escape_htmltag($profileLabel), null, 'mesgs');
				}

				$keepWizardForDuplicateSelection = (!empty($importResult['duplicates_skipped']) && empty($allowDuplicateImport));
				if ($keepWizardForDuplicateSelection) {
					$analysis['suggested_mapping'] = $mapping;
					$currentWizard['analysis'] = $analysis;
					$currentWizard['bank_account_id'] = (int) $confirmBankAccountId;
					$currentWizard['statement_date'] = (string) ($confirmStatementDate ?: $currentWizard['statement_date']);
					$currentWizard['last_import_result'] = $importResult;
					$currentWizard['last_use_mapping_parse'] = ($useMappingParse ? 1 : 0);
					$currentWizard['phase'] = 'duplicate_review';
					$_SESSION[$wizardStoreKey][$wizardToken] = $currentWizard;
				} else {
					$cleanupWizard($wizardToken);
					$currentWizard = null;
					$wizardToken = '';
					$analysis = null;
				}
			} catch (Throwable $e) {
				dol_syslog('KreaBank import confirm fatal: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine(), LOG_ERR);
				setEventMessages($langs->trans('KreaBankImportFailed', $normalizeErrorMessage($e->getMessage())), null, 'errors');
				$analysis['suggested_mapping'] = $mapping;
				$currentWizard['analysis'] = $analysis;
				$_SESSION[$wizardStoreKey][$wizardToken] = $currentWizard;
			}
		}
	}
}

if (empty($analysis) && !empty($currentWizard['analysis']) && is_array($currentWizard['analysis'])) {
	$analysis = $currentWizard['analysis'];
}
if (!is_array($importResult) && !empty($currentWizard['last_import_result']) && is_array($currentWizard['last_import_result'])) {
	$importResult = $currentWizard['last_import_result'];
	if ((int) $lastImportedBankAccountId <= 0 && !empty($currentWizard['bank_account_id'])) {
		$lastImportedBankAccountId = (int) $currentWizard['bank_account_id'];
	}
}

$bankAccountOptions = array();
$sqlAccounts = 'SELECT rowid, label, account_number FROM ' . $db->prefix() . 'bank_account';
$sqlAccounts .= ' WHERE entity = ' . ((int) $conf->entity);
$sqlAccounts .= ' AND clos = 0';
$sqlAccounts .= ' ORDER BY label ASC';
$resAccounts = $db->query($sqlAccounts);
if ($resAccounts) {
	while ($obj = $db->fetch_object($resAccounts)) {
		$bankAccountOptions[] = $obj;
	}
}

$mappingFieldLabels = array(
	'operation_date' => $langs->trans('KreaBankImportFieldOperationDate'),
	'value_date' => $langs->trans('KreaBankImportFieldValueDate'),
	'amount' => $langs->trans('KreaBankImportFieldAmount'),
	'debit' => $langs->trans('KreaBankImportFieldDebit'),
	'credit' => $langs->trans('KreaBankImportFieldCredit'),
	'running_balance' => $langs->trans('KreaBankImportFieldBalance'),
	'description' => $langs->trans('KreaBankImportFieldDescription'),
	'payment_reference' => $langs->trans('KreaBankImportFieldPaymentReference'),
	'bank_reference' => $langs->trans('KreaBankImportFieldBankReference'),
	'counterparty_iban' => $langs->trans('KreaBankImportFieldIban'),
	'counterparty_name' => $langs->trans('KreaBankImportFieldCounterparty'),
	'currency' => $langs->trans('KreaBankImportFieldCurrency'),
);

$historyLimit = GETPOSTINT('limit');
if ($historyLimit <= 0) {
	$historyLimit = (!empty($conf->liste_limit) ? (int) $conf->liste_limit : 25);
}
$historyPage = GETPOSTISSET('pageplusone') ? (GETPOSTINT('pageplusone') - 1) : GETPOSTINT('page');
if ($historyPage < 0) {
	$historyPage = 0;
}
$historyOffset = $historyLimit * $historyPage;
$historySortField = GETPOST('sortfield', 'aZ09comma');
$historySortOrder = strtoupper(GETPOST('sortorder', 'aZ09comma'));
$historySearchStatementRef = trim((string) GETPOST('search_statement_ref', 'restricthtml'));
$historySearchBankAccount = trim((string) GETPOST('search_bank_account', 'restricthtml'));
$historySearchLastOpFrom = trim((string) GETPOST('search_last_operation_from', 'alphanohtml'));
$historySearchLastOpTo = trim((string) GETPOST('search_last_operation_to', 'alphanohtml'));
$historySearchImportedLines = trim((string) GETPOST('search_imported_lines', 'alphanohtml'));
$historySearchAmountTotal = trim((string) GETPOST('search_amount_total', 'alphanohtml'));
$historySearchSourceType = trim((string) GETPOST('search_source_type', 'restricthtml'));
$historySearchSourceFile = trim((string) GETPOST('search_source_file', 'restricthtml'));
$historySearchImportedAtFrom = trim((string) GETPOST('search_imported_at_from', 'alphanohtml'));
$historySearchImportedAtTo = trim((string) GETPOST('search_imported_at_to', 'alphanohtml'));
$historySortableFields = array(
	'statement_ref' => 'h.statement_ref',
	'bank_account_label' => 'h.bank_account_label',
	'last_operation_date' => 'h.last_operation_date',
	'imported_lines' => 'h.imported_lines',
	'amount_total' => 'h.amount_total',
	'source_type' => 'h.source_type',
	'source_file' => 'h.source_file',
	'imported_at' => 'h.imported_at',
);
if (empty($historySortField) || empty($historySortableFields[$historySortField])) {
	$historySortField = 'imported_at';
}
if ($historySortOrder !== 'ASC' && $historySortOrder !== 'DESC') {
	$historySortOrder = 'DESC';
}
if (GETPOST('button_removefilter_x', 'alpha') !== '' || GETPOST('button_removefilter', 'alpha') !== '' || GETPOST('removefilter', 'alpha') !== '') {
	$historySearchStatementRef = '';
	$historySearchBankAccount = '';
	$historySearchLastOpFrom = '';
	$historySearchLastOpTo = '';
	$historySearchImportedLines = '';
	$historySearchAmountTotal = '';
	$historySearchSourceType = '';
	$historySearchSourceFile = '';
	$historySearchImportedAtFrom = '';
	$historySearchImportedAtTo = '';
	$historyPage = 0;
	$historyOffset = 0;
}
$isValidFilterDate = static function ($value) {
	$value = trim((string) $value);
	if ($value === '' || !preg_match('/^\d{4}\-\d{2}\-\d{2}$/', $value)) {
		return '';
	}

	return $value;
};
$parseNumericFilter = static function ($value, $isInt = false) {
	$value = trim((string) $value);
	if ($value === '') {
		return null;
	}
	if (!preg_match('/^(<=|>=|!=|=|<|>)?\s*([0-9]+(?:[.,][0-9]+)?)$/', $value, $matches)) {
		return null;
	}
	$operator = !empty($matches[1]) ? $matches[1] : '=';
	$numberRaw = str_replace(',', '.', (string) $matches[2]);
	$number = (float) $numberRaw;
	if ($isInt) {
		$number = (int) round($number);
	}

	return array(
		'op' => $operator,
		'value' => $number,
	);
};
$historyLastOpFromSql = $isValidFilterDate($historySearchLastOpFrom);
$historyLastOpToSql = $isValidFilterDate($historySearchLastOpTo);
$historyImportedAtFromSql = $isValidFilterDate($historySearchImportedAtFrom);
$historyImportedAtToSql = $isValidFilterDate($historySearchImportedAtTo);
$historyImportedLinesFilter = $parseNumericFilter($historySearchImportedLines, true);
$historyAmountTotalFilter = $parseNumericFilter($historySearchAmountTotal, false);

$historyRows = array();
$historyTotal = 0;
$historyPagerNum = 0;
$historySignedAmountSql = 'CASE';
$historySignedAmountSql .= ' WHEN m.direction < 0 THEN -ABS(COALESCE(m.amount, b.amount))';
$historySignedAmountSql .= ' WHEN m.direction > 0 THEN ABS(COALESCE(m.amount, b.amount))';
$historySignedAmountSql .= ' ELSE COALESCE(m.amount, b.amount)';
$historySignedAmountSql .= ' END';
$historyBankAccountEntityList = getEntity('bank_account');
if ($historyBankAccountEntityList === '' || $historyBankAccountEntityList === null) {
	$historyBankAccountEntityList = (string) ((int) $conf->entity);
}

$sqlHistoryFrom = ' FROM ' . $db->prefix() . 'bank as b';
$sqlHistoryFrom .= ' INNER JOIN ' . $db->prefix() . 'bank_account as ba ON ba.rowid = b.fk_account';
$sqlHistoryFrom .= ' LEFT JOIN ' . $db->prefix() . 'kreabank_bankmeta as m ON m.entity = ' . ((int) $conf->entity) . ' AND m.fk_bank_line = b.rowid';
$sqlHistoryWhere = ' WHERE ba.entity IN (' . $historyBankAccountEntityList . ')';
$sqlHistoryWhere .= " AND b.num_releve IS NOT NULL AND b.num_releve <> ''";
$sqlHistoryWhere .= " AND (m.rowid IS NOT NULL OR b.note LIKE 'KreaBank import %' OR b.note LIKE 'KreaBank staged import %')";
if ($historySearchStatementRef !== '') {
	$sqlHistoryWhere .= " AND b.num_releve LIKE '%" . $db->escape($historySearchStatementRef) . "%'";
}
if ($historySearchBankAccount !== '') {
	$escapedBankSearch = $db->escape($historySearchBankAccount);
	$sqlHistoryWhere .= " AND (ba.label LIKE '%" . $escapedBankSearch . "%' OR ba.ref LIKE '%" . $escapedBankSearch . "%')";
}
if ($historySearchSourceType !== '') {
	$sqlHistoryWhere .= " AND m.source_type LIKE '%" . $db->escape($historySearchSourceType) . "%'";
}
if ($historySearchSourceFile !== '') {
	$sqlHistoryWhere .= " AND m.source_file LIKE '%" . $db->escape($historySearchSourceFile) . "%'";
}
if ($historyImportedAtFromSql !== '') {
	$sqlHistoryWhere .= " AND m.datec >= '" . $db->escape($historyImportedAtFromSql) . " 00:00:00'";
}
if ($historyImportedAtToSql !== '') {
	$sqlHistoryWhere .= " AND m.datec <= '" . $db->escape($historyImportedAtToSql) . " 23:59:59'";
}
$sqlHistoryGroupBy = ' GROUP BY b.fk_account, ba.ref, ba.label, b.num_releve';
$sqlHistoryHaving = ' HAVING 1=1';
if ($historyLastOpFromSql !== '') {
	$sqlHistoryHaving .= " AND MAX(COALESCE(m.operation_date, b.dateo)) >= '" . $db->escape($historyLastOpFromSql) . "'";
}
if ($historyLastOpToSql !== '') {
	$sqlHistoryHaving .= " AND MAX(COALESCE(m.operation_date, b.dateo)) <= '" . $db->escape($historyLastOpToSql) . "'";
}
if (is_array($historyImportedLinesFilter)) {
	$sqlHistoryHaving .= ' AND COUNT(b.rowid) ' . $historyImportedLinesFilter['op'] . ' ' . ((int) $historyImportedLinesFilter['value']);
}
if (is_array($historyAmountTotalFilter)) {
	$sqlHistoryHaving .= ' AND COALESCE(SUM(' . $historySignedAmountSql . '), 0) ' . $historyAmountTotalFilter['op'] . ' ' . ((float) $historyAmountTotalFilter['value']);
}

$sqlHistoryCount = 'SELECT COUNT(*) as nb FROM (';
$sqlHistoryCount .= 'SELECT b.num_releve';
$sqlHistoryCount .= $sqlHistoryFrom;
$sqlHistoryCount .= $sqlHistoryWhere;
$sqlHistoryCount .= $sqlHistoryGroupBy;
$sqlHistoryCount .= $sqlHistoryHaving;
$sqlHistoryCount .= ') as t';
$resHistoryCount = $db->query($sqlHistoryCount);
if ($resHistoryCount && ($objCount = $db->fetch_object($resHistoryCount))) {
	$historyTotal = (int) $objCount->nb;
}
if ($historyPage > 0 && $historyOffset >= $historyTotal) {
	$historyPage = 0;
	$historyOffset = 0;
}

$sqlHistoryBase = 'SELECT b.fk_account as bank_account_id, ba.ref as bank_account_ref, ba.label as bank_account_label,';
$sqlHistoryBase .= ' b.num_releve as statement_ref,';
$sqlHistoryBase .= ' MIN(COALESCE(m.operation_date, b.dateo)) as first_operation_date,';
$sqlHistoryBase .= ' MAX(COALESCE(m.operation_date, b.dateo)) as last_operation_date,';
$sqlHistoryBase .= ' COUNT(b.rowid) as imported_lines, COALESCE(SUM(' . $historySignedAmountSql . '), 0) as amount_total,';
$sqlHistoryBase .= ' COALESCE(MAX(m.source_type), "") as source_type, COALESCE(MAX(m.source_file), "") as source_file,';
$sqlHistoryBase .= ' MAX(m.datec) as imported_at';
$sqlHistoryBase .= $sqlHistoryFrom;
$sqlHistoryBase .= $sqlHistoryWhere;
$sqlHistoryBase .= $sqlHistoryGroupBy;
$sqlHistoryBase .= $sqlHistoryHaving;

$sqlHistory = 'SELECT h.* FROM (' . $sqlHistoryBase . ') as h';
$sqlHistory .= ' ORDER BY ' . $historySortableFields[$historySortField] . ' ' . $historySortOrder;
$sqlHistory .= $db->plimit($historyLimit, $historyOffset);
$resHistory = $db->query($sqlHistory);
if ($resHistory) {
	while ($objHistory = $db->fetch_object($resHistory)) {
		$historyRows[] = $objHistory;
	}
}
$historyCurrentPageCount = count($historyRows);
$historyPagerNum = $historyCurrentPageCount;
if ($historyLimit > 0 && ($historyOffset + $historyCurrentPageCount) < $historyTotal) {
	$historyPagerNum = $historyLimit + 1;
}

if ($historyTotal <= 0) {
	// Backward compatibility (1.11.x): load import history from staged statement tables when native/meta history is empty.
	$statementTableName = $db->prefix() . 'kreabank_statement';
	$lineTableName = $db->prefix() . 'kreabank_statement_line';
	$resStatementTable = $db->query("SHOW TABLES LIKE '" . $db->escape($statementTableName) . "'");
	$resLineTable = $db->query("SHOW TABLES LIKE '" . $db->escape($lineTableName) . "'");
	$hasStatementTables = ($resStatementTable && $db->num_rows($resStatementTable) > 0 && $resLineTable && $db->num_rows($resLineTable) > 0);

	if ($hasStatementTables) {
		$sqlHistoryLegacyFrom = ' FROM ' . $statementTableName . ' as s';
		$sqlHistoryLegacyFrom .= ' INNER JOIN ' . $db->prefix() . 'bank_account as ba ON ba.rowid = s.bank_account_id';
		$sqlHistoryLegacyFrom .= ' LEFT JOIN ' . $lineTableName . ' as l ON l.fk_statement = s.rowid AND l.entity = ' . ((int) $conf->entity);
		$sqlHistoryLegacyWhere = ' WHERE s.entity = ' . ((int) $conf->entity);
		$sqlHistoryLegacyWhere .= ' AND ba.entity IN (' . $historyBankAccountEntityList . ')';
		$sqlHistoryLegacyWhere .= " AND s.ref IS NOT NULL AND s.ref <> ''";
		if ($historySearchStatementRef !== '') {
			$sqlHistoryLegacyWhere .= " AND s.ref LIKE '%" . $db->escape($historySearchStatementRef) . "%'";
		}
		if ($historySearchBankAccount !== '') {
			$escapedBankSearch = $db->escape($historySearchBankAccount);
			$sqlHistoryLegacyWhere .= " AND (ba.label LIKE '%" . $escapedBankSearch . "%' OR ba.ref LIKE '%" . $escapedBankSearch . "%')";
		}
		if ($historySearchSourceType !== '') {
			$sqlHistoryLegacyWhere .= " AND s.source_type LIKE '%" . $db->escape($historySearchSourceType) . "%'";
		}
		if ($historySearchSourceFile !== '') {
			$sqlHistoryLegacyWhere .= " AND s.filename LIKE '%" . $db->escape($historySearchSourceFile) . "%'";
		}
		if ($historyImportedAtFromSql !== '') {
			$sqlHistoryLegacyWhere .= " AND s.date_import >= '" . $db->escape($historyImportedAtFromSql) . " 00:00:00'";
		}
		if ($historyImportedAtToSql !== '') {
			$sqlHistoryLegacyWhere .= " AND s.date_import <= '" . $db->escape($historyImportedAtToSql) . " 23:59:59'";
		}

		$sqlHistoryLegacyGroupBy = ' GROUP BY s.rowid, s.bank_account_id, ba.ref, ba.label, s.ref, s.source_type, s.filename, s.date_import';
		$sqlHistoryLegacyHaving = ' HAVING 1=1';
		if ($historyLastOpFromSql !== '') {
			$sqlHistoryLegacyHaving .= " AND MAX(l.operation_date) >= '" . $db->escape($historyLastOpFromSql) . "'";
		}
		if ($historyLastOpToSql !== '') {
			$sqlHistoryLegacyHaving .= " AND MAX(l.operation_date) <= '" . $db->escape($historyLastOpToSql) . "'";
		}
		if (is_array($historyImportedLinesFilter)) {
			$sqlHistoryLegacyHaving .= ' AND COUNT(l.rowid) ' . $historyImportedLinesFilter['op'] . ' ' . ((int) $historyImportedLinesFilter['value']);
		}
		if (is_array($historyAmountTotalFilter)) {
			$sqlHistoryLegacyHaving .= ' AND COALESCE(SUM(l.amount), 0) ' . $historyAmountTotalFilter['op'] . ' ' . ((float) $historyAmountTotalFilter['value']);
		}

		$sqlHistoryLegacyCount = 'SELECT COUNT(*) as nb FROM (';
		$sqlHistoryLegacyCount .= 'SELECT s.rowid';
		$sqlHistoryLegacyCount .= $sqlHistoryLegacyFrom;
		$sqlHistoryLegacyCount .= $sqlHistoryLegacyWhere;
		$sqlHistoryLegacyCount .= $sqlHistoryLegacyGroupBy;
		$sqlHistoryLegacyCount .= $sqlHistoryLegacyHaving;
		$sqlHistoryLegacyCount .= ') as t';
		$resHistoryLegacyCount = $db->query($sqlHistoryLegacyCount);
		$historyTotal = 0;
		if ($resHistoryLegacyCount && ($objHistoryLegacyCount = $db->fetch_object($resHistoryLegacyCount))) {
			$historyTotal = (int) $objHistoryLegacyCount->nb;
		}
		if ($historyPage > 0 && $historyOffset >= $historyTotal) {
			$historyPage = 0;
			$historyOffset = 0;
		}

		$historyRows = array();
		$sqlHistoryLegacyBase = 'SELECT s.bank_account_id as bank_account_id, ba.ref as bank_account_ref, ba.label as bank_account_label,';
		$sqlHistoryLegacyBase .= ' s.ref as statement_ref, MIN(l.operation_date) as first_operation_date, MAX(l.operation_date) as last_operation_date,';
		$sqlHistoryLegacyBase .= ' COUNT(l.rowid) as imported_lines, COALESCE(SUM(l.amount), 0) as amount_total,';
		$sqlHistoryLegacyBase .= ' s.source_type as source_type, s.filename as source_file, s.date_import as imported_at';
		$sqlHistoryLegacyBase .= $sqlHistoryLegacyFrom;
		$sqlHistoryLegacyBase .= $sqlHistoryLegacyWhere;
		$sqlHistoryLegacyBase .= $sqlHistoryLegacyGroupBy;
		$sqlHistoryLegacyBase .= $sqlHistoryLegacyHaving;

		$sqlHistoryLegacy = 'SELECT h.* FROM (' . $sqlHistoryLegacyBase . ') as h';
		$sqlHistoryLegacy .= ' ORDER BY ' . $historySortableFields[$historySortField] . ' ' . $historySortOrder;
		$sqlHistoryLegacy .= $db->plimit($historyLimit, $historyOffset);
		$resHistoryLegacy = $db->query($sqlHistoryLegacy);
		if ($resHistoryLegacy) {
			while ($objHistoryLegacy = $db->fetch_object($resHistoryLegacy)) {
				$historyRows[] = $objHistoryLegacy;
			}
		}

		$historyCurrentPageCount = count($historyRows);
		$historyPagerNum = $historyCurrentPageCount;
		if ($historyLimit > 0 && ($historyOffset + $historyCurrentPageCount) < $historyTotal) {
			$historyPagerNum = $historyLimit + 1;
		}
	}
}

llxHeader('', $langs->trans('KreaBankImport'), '', '', 0, 0, '', '', '', 'mod-kreabank page-import');

print load_fiche_titre($langs->trans('KreaBankImport'), '', 'title_setup');
$head = kreabankPrepareHead();
print dol_get_fiche_head($head, 'import', $langs->trans('KreaBankImport'), -1, 'bank');

$selectedBankId = GETPOSTINT('bank_account_id');
if (!empty($currentWizard) && isset($currentWizard['bank_account_id'])) {
	$selectedBankId = (int) $currentWizard['bank_account_id'];
}
$selectedStatementDate = GETPOST('statement_date', 'alpha');
if ($selectedStatementDate === '') {
	$selectedStatementDate = !empty($currentWizard['statement_date']) ? (string) $currentWizard['statement_date'] : dol_print_date(dol_now(), '%Y-%m-%d');
}
$selectedForceMappingEditor = 0;
if ((int) GETPOST('force_mapping_editor_present', 'int') === 1) {
	$selectedForceMappingEditor = GETPOST('force_mapping_editor', 'int') ? 1 : 0;
} elseif (function_exists('GETPOSTISSET') && GETPOSTISSET('force_mapping_editor')) {
	$selectedForceMappingEditor = GETPOST('force_mapping_editor', 'int') ? 1 : 0;
}
if (is_array($currentWizard) && array_key_exists('force_mapping_editor', $currentWizard)) {
	$selectedForceMappingEditor = !empty($currentWizard['force_mapping_editor']) ? 1 : 0;
}
$isDuplicateReview = (!empty($currentWizard['phase']) && (string) $currentWizard['phase'] === 'duplicate_review');
$hasActiveWizard = (!empty($currentWizard) && is_array($currentWizard));

if (!$hasActiveWizard) {
	print '<div class="opacitymedium" style="font-size:2.1em;line-height:1.2;font-weight:800;color:#0d5b72;background:#edf7fb;border-left:6px solid #0d5b72;padding:10px 14px;border-radius:4px"><strong>Passo 1:</strong> ' . $langs->trans('KreaBankImportUpload') . '</div><br>';
} elseif (!$isDuplicateReview) {
	print '<div class="opacitymedium" style="font-size:2.1em;line-height:1.2;font-weight:800;color:#0d5b72;background:#edf7fb;border-left:6px solid #0d5b72;padding:10px 14px;border-radius:4px"><strong>Passo 2:</strong> Rever importação e confirmar.</div><br>';
} else {
	print '<div class="opacitymedium" style="font-size:2.1em;line-height:1.2;font-weight:800;color:#0d5b72;background:#edf7fb;border-left:6px solid #0d5b72;padding:10px 14px;border-radius:4px"><strong>Passo 3:</strong> Rever possíveis duplicados detetados e decidir o que importar.</div><br>';
}

if (!$hasActiveWizard) {
	print '<form method="POST" enctype="multipart/form-data" action="' . dol_escape_htmltag($_SERVER['PHP_SELF']) . '">';
	print '<input type="hidden" name="token" value="' . newToken() . '">';
	print '<input type="hidden" name="action" value="analyze">';

	print '<table class="border centpercent">';
	print '<tr><td class="titlefieldcreate">' . $langs->trans('KreaBankImportFile') . '</td><td><input class="flat minwidth400" type="file" name="statement_file" required accept=".csv,.ofx,.xml,.camt,.xls,.xlsx,.txt,.mt940,.n43,.sta,.tab"></td></tr>';
	print '<tr><td>' . $langs->trans('KreaBankImportBankAccount') . '</td><td><select class="flat minwidth300" name="bank_account_id">';
	print '<option value="0">' . $langs->trans('None') . '</option>';
	foreach ($bankAccountOptions as $account) {
		$label = trim((string) $account->label . ' ' . (string) $account->account_number);
		$selected = ($selectedBankId === (int) $account->rowid) ? ' selected' : '';
		print '<option value="' . ((int) $account->rowid) . '"' . $selected . '>' . dol_escape_htmltag($label) . '</option>';
	}
	print '</select></td></tr>';
	print '<tr><td>' . $langs->trans('KreaBankImportStatementDate') . '</td><td><input class="flat" type="date" name="statement_date" value="' . dol_escape_htmltag($selectedStatementDate) . '">';
	print '<span class="opacitymedium paddingleft">(optional)</span></td></tr>';
	print '<tr><td>Editor de campos</td><td><input type="hidden" name="force_mapping_editor_present" value="1"><label><input class="flat" type="checkbox" name="force_mapping_editor" value="1"' . ($selectedForceMappingEditor ? ' checked' : '') . '> Mostrar editor de correspondencia de campos</label></td></tr>';
	print '</table>';

	print '<div class="tabsAction">';
	print '<button class="butAction" type="submit">' . $langs->trans('KreaBankImportAnalyzeButton') . '</button>';
	print '</div>';
	print '</form>';
}

if (!empty($currentWizard) && !empty($analysis) && is_array($analysis)) {
	$suggestedMapping = !empty($analysis['suggested_mapping']) && is_array($analysis['suggested_mapping']) ? $analysis['suggested_mapping'] : array();
	$columns = !empty($analysis['columns']) && is_array($analysis['columns']) ? $analysis['columns'] : array();
	$previewRows = !empty($analysis['preview_rows']) && is_array($analysis['preview_rows']) ? $analysis['preview_rows'] : array();
	$sampleLines = !empty($analysis['sample_lines']) && is_array($analysis['sample_lines']) ? $analysis['sample_lines'] : array();
	$supportsMapping = !empty($analysis['supports_mapping']);
	$mappingPanelOpen = (!empty($selectedForceMappingEditor) || !empty($analysis['mapping_forced']));
	$mappingPanelDisplay = ($mappingPanelOpen ? 'table-row-group' : 'none');
	$mappingPreviewDisplay = ($mappingPanelOpen ? 'block' : 'none');
	$mappingToggleIconClass = ($mappingPanelOpen ? 'fa-eye-slash' : 'fa-eye');
	$mappingToggleTitle = ($mappingPanelOpen ? 'Ocultar mapeamento' : 'Mostrar mapeamento');
	$mappingToggleLabel = ($mappingPanelOpen ? 'Ocultar mapeamento' : 'Mostrar mapeamento');

	print '<br><table class="noborder centpercent">';
	print '<tr class="liste_titre"><th colspan="2">' . $langs->trans('KreaBankImportReviewTitle') . '</th></tr>';
	print '<tr class="oddeven"><td class="titlefield">' . $langs->trans('KreaBankImportFile') . '</td><td>' . dol_escape_htmltag((string) $currentWizard['file_name']) . '</td></tr>';
	print '<tr class="oddeven"><td>' . $langs->trans('Type') . '</td><td>' . dol_escape_htmltag(strtoupper((string) $analysis['format'])) . '</td></tr>';
	print '<tr class="oddeven"><td>' . $langs->trans('KreaBankImportRowsDetected') . '</td><td>' . ((int) $analysis['row_count']) . '</td></tr>';
	if ($supportsMapping) {
		print '<tr class="oddeven"><td>' . $langs->trans('KreaBankImportFingerprint') . '</td><td><span class="opacitymedium">' . dol_escape_htmltag((string) $analysis['fingerprint']) . '</span></td></tr>';
		if (!empty($analysis['layout_signature'])) {
			print '<tr class="oddeven"><td>Assinatura do layout</td><td><span class="opacitymedium">' . dol_escape_htmltag((string) $analysis['layout_signature']) . '</span></td></tr>';
		}
	}
	print '</table>';

	if ($isDuplicateReview) {
		print '<br><div class="opacitymedium">A importação deste ficheiro já foi confirmada. Escolha abaixo as ações para os possíveis duplicados.</div>';
	} else {
		print '<br><form method="POST" action="' . dol_escape_htmltag($_SERVER['PHP_SELF']) . '" id="kreabankImportConfirmForm">';
		print '<input type="hidden" name="token" value="' . newToken() . '">';
		print '<input type="hidden" name="action" value="confirm">';
		print '<input type="hidden" name="wizard_token" value="' . dol_escape_htmltag((string) $wizardToken) . '">';

		print '<table class="border centpercent">';
		print '<tr><td class="titlefieldcreate">' . $langs->trans('KreaBankImportBankAccount') . '</td><td><select class="flat minwidth300" name="bank_account_id_confirm">';
		print '<option value="0">' . $langs->trans('None') . '</option>';
		foreach ($bankAccountOptions as $account) {
			$label = trim((string) $account->label . ' ' . (string) $account->account_number);
			$selected = (((int) $currentWizard['bank_account_id']) === (int) $account->rowid) ? ' selected' : '';
			print '<option value="' . ((int) $account->rowid) . '"' . $selected . '>' . dol_escape_htmltag($label) . '</option>';
		}
		print '</select></td></tr>';
		print '<tr><td>' . $langs->trans('KreaBankImportStatementDate') . '</td><td><input class="flat" type="date" name="statement_date_confirm" value="' . dol_escape_htmltag((string) $currentWizard['statement_date']) . '"></td></tr>';
		print '<tr><td>Possíveis duplicados</td><td><label><input type="checkbox" class="flat" name="allow_duplicate_import" value="1"> Importar possíveis duplicados mesmo assim</label>';
		print '<br><span class="opacitymedium">Use apenas após validar os detalhes dos possíveis duplicados.</span></td></tr>';

		if ($supportsMapping) {
			print '<tr><td>Rever mapeamento da importacao</td><td>';
			print '<button type="button" class="button" id="kbToggleMapping" title="' . dol_escape_htmltag($mappingToggleTitle) . '" style="min-width:40px;padding:4px 8px">';
			print '<span id="kbToggleMappingIcon" class="fas ' . $mappingToggleIconClass . '"></span>';
			print '</button>';
			print ' <span id="kbToggleMappingLabel" class="opacitymedium">' . $mappingToggleLabel . '</span>';
			print '</td></tr>';
			print '<tbody id="kbMappingPanel" style="display:' . $mappingPanelDisplay . '">';
			print '<tr><td>' . $langs->trans('KreaBankImportHeaderRow') . '</td><td><input class="flat" type="number" min="-1" step="1" name="map_header_row" value="' . ((int) $suggestedMapping['header_row']) . '">';
			print ' <span class="opacitymedium">' . $langs->trans('KreaBankImportHeaderRowHelp') . '</span></td></tr>';

			foreach ($mappingFieldLabels as $fieldKey => $fieldLabel) {
				$currentValue = isset($suggestedMapping[$fieldKey]) ? (int) $suggestedMapping[$fieldKey] : -1;
				print '<tr><td>' . $fieldLabel . '</td><td><select class="flat minwidth400" name="map_' . $fieldKey . '">';
				print '<option value="-1">' . dol_escape_htmltag($langs->trans('KreaBankImportNoColumn')) . '</option>';
				foreach ($columns as $column) {
					$idx = (int) $column['index'];
					$selected = ($currentValue === $idx) ? ' selected' : '';
					$colLabel = '[' . ($idx + 1) . '] ' . (string) $column['label'];
					print '<option value="' . $idx . '"' . $selected . '>' . dol_escape_htmltag($colLabel) . '</option>';
				}
				print '</select></td></tr>';
			}

			$defaultProfileLabel = '';
			if (!empty($analysis['profile']['label'])) {
				$defaultProfileLabel = (string) $analysis['profile']['label'];
			} else {
				$defaultProfileLabel = 'Layout ' . dol_print_date(dol_now(), '%Y-%m-%d %H:%M');
			}
			print '<tr><td>' . $langs->trans('KreaBankImportSaveProfile') . '</td><td>';
			print '<label><input type="checkbox" class="flat" name="save_profile" value="1" checked> ' . $langs->trans('Yes') . '</label>';
			print ' <input class="flat minwidth300" type="text" name="profile_label" value="' . dol_escape_htmltag($defaultProfileLabel) . '">';
			print '</td></tr>';
			print '</tbody>';
		}
		print '</table>';
		print '</form>';

		print '<div class="tabsAction" style="display:flex;justify-content:flex-end;gap:8px;align-items:center;flex-wrap:wrap">';
		print '<button class="butAction" type="submit" form="kreabankImportConfirmForm">' . $langs->trans('KreaBankImportConfirmButton') . '</button>';
		print '<form method="POST" action="' . dol_escape_htmltag($_SERVER['PHP_SELF']) . '" style="margin:0">';
		print '<input type="hidden" name="token" value="' . newToken() . '">';
		print '<input type="hidden" name="action" value="cancel">';
		print '<input type="hidden" name="wizard_token" value="' . dol_escape_htmltag((string) $wizardToken) . '">';
		print '<button class="butActionDelete" type="submit">' . $langs->trans('KreaBankImportCancelWizard') . '</button>';
		print '</form>';
		print '</div>';
	}

	if (!$isDuplicateReview && $supportsMapping && !empty($previewRows)) {
		print '<div id="kbMappingRawPreview" style="display:' . $mappingPreviewDisplay . '">';
		$headerRowIndex = isset($suggestedMapping['header_row']) ? (int) $suggestedMapping['header_row'] : -1;
		$maxColumns = 0;
		foreach ($previewRows as $row) {
			$maxColumns = max($maxColumns, is_array($row) ? count($row) : 0);
		}

		print '<br><table class="noborder centpercent">';
		print '<tr class="liste_titre"><th colspan="' . ($maxColumns + 1) . '">' . $langs->trans('KreaBankImportPreviewRaw') . '</th></tr>';
		print '<tr class="liste_titre"><th>#</th>';
		for ($c = 0; $c < $maxColumns; $c++) {
			$label = 'C' . ($c + 1);
			if (!empty($columns[$c]['label'])) {
				$label = (string) $columns[$c]['label'];
			}
			print '<th>' . dol_escape_htmltag($label) . '</th>';
		}
		print '</tr>';

		foreach ($previewRows as $rowIndex => $row) {
			$rowClass = (((int) $rowIndex) === $headerRowIndex) ? ' class="impair"' : '';
			print '<tr' . $rowClass . '><td>' . ((int) $rowIndex + 1) . '</td>';
			for ($c = 0; $c < $maxColumns; $c++) {
				$cell = (is_array($row) && isset($row[$c])) ? (string) $row[$c] : '';
				print '<td>' . dol_escape_htmltag($cell) . '</td>';
			}
			print '</tr>';
		}
		print '</table>';
		print '</div>';
	}

	if (!$isDuplicateReview && !empty($sampleLines)) {
		print '<br><table class="noborder centpercent">';
		print '<tr class="liste_titre"><th colspan="7">' . $langs->trans('KreaBankImportPreviewParsed') . '</th></tr>';
		print '<tr class="liste_titre">';
		print '<th>' . $langs->trans('KreaBankDate') . '</th>';
		print '<th>' . $langs->trans('KreaBankAmount') . '</th>';
		print '<th>' . $langs->trans('KreaBankDescription') . '</th>';
		print '<th>' . $langs->trans('KreaBankReference') . '</th>';
		print '<th>' . $langs->trans('KreaBankCounterparty') . '</th>';
		print '<th>' . $langs->trans('KreaBankCurrency') . '</th>';
		print '<th>' . $langs->trans('KreaBankBalance') . '</th>';
		print '</tr>';
		foreach (array_slice($sampleLines, 0, 15) as $line) {
			if (!is_array($line)) {
				continue;
			}
			$operationDate = isset($line['operation_date']) ? (string) $line['operation_date'] : '';
			$amount = isset($line['amount']) ? (float) $line['amount'] : 0.0;
			$description = isset($line['description']) ? (string) $line['description'] : '';
			$paymentReference = isset($line['payment_reference']) ? (string) $line['payment_reference'] : '';
			$counterpartyName = isset($line['counterparty_name']) ? (string) $line['counterparty_name'] : '';
			$currency = isset($line['currency']) ? (string) $line['currency'] : '';
			$runningBalance = array_key_exists('running_balance', $line) ? $line['running_balance'] : null;

			print '<tr class="oddeven">';
			print '<td>' . dol_escape_htmltag($operationDate) . '</td>';
			print '<td>' . price($amount) . '</td>';
			print '<td>' . dol_escape_htmltag($description) . '</td>';
			print '<td>' . dol_escape_htmltag($paymentReference) . '</td>';
			print '<td>' . dol_escape_htmltag($counterpartyName) . '</td>';
			print '<td>' . dol_escape_htmltag($currency) . '</td>';
			print '<td>' . (($runningBalance !== null) ? price((float) $runningBalance) : '') . '</td>';
			print '</tr>';
		}
		print '</table>';
	}

	if (!$isDuplicateReview && $supportsMapping) {
		print '<script>';
		print 'document.addEventListener("DOMContentLoaded", function () {';
		print 'var btn = document.getElementById("kbToggleMapping");';
		print 'var icon = document.getElementById("kbToggleMappingIcon");';
		print 'var label = document.getElementById("kbToggleMappingLabel");';
		print 'var panel = document.getElementById("kbMappingPanel");';
		print 'var rawPreview = document.getElementById("kbMappingRawPreview");';
		print 'if (!btn || !panel) { return; }';
		print 'btn.addEventListener("click", function () {';
		print 'var isOpen = panel.style.display !== "none";';
		print 'panel.style.display = isOpen ? "none" : "table-row-group";';
		print 'if (rawPreview) { rawPreview.style.display = isOpen ? "none" : "block"; }';
		print 'if (icon) { icon.className = isOpen ? "fas fa-eye" : "fas fa-eye-slash"; }';
		print 'btn.title = isOpen ? "Mostrar mapeamento" : "Ocultar mapeamento";';
		print 'if (label) { label.textContent = isOpen ? "Mostrar mapeamento" : "Ocultar mapeamento"; }';
		print '});';
		print '});';
		print '</script>';
	}
}

if (is_array($importResult)) {
	$importSummaryStatementRef = (string) (!empty($importResult['statement_ref']) ? $importResult['statement_ref'] : '');
	$canOpenImportSummaryStatement = ($importSummaryStatementRef !== '' && $lastImportedBankAccountId > 0);
	print '<br><table class="noborder centpercent">';
	print '<tr class="liste_titre"><th colspan="2">' . $langs->trans('KreaBankImportDone') . '</th></tr>';
	print '<tr class="oddeven"><td>' . $langs->trans('Ref') . '</td><td>';
	if ($canOpenImportSummaryStatement) {
		print '<form method="POST" action="' . dol_escape_htmltag($_SERVER['PHP_SELF']) . '" target="_blank" style="display:inline-block;margin:0">';
		print '<input type="hidden" name="token" value="' . newToken() . '">';
		print '<input type="hidden" name="action" value="open_native_statement">';
		print '<input type="hidden" name="open_bank_account_id" value="' . $lastImportedBankAccountId . '">';
		print '<input type="hidden" name="open_statement_ref" value="' . dol_escape_htmltag($importSummaryStatementRef) . '">';
		print '<input type="hidden" name="mainmenu" value="bank">';
		print '<input type="hidden" name="leftmenu" value="kreabank_import">';
		print '<button type="submit" class="noborderimp" style="padding:0;margin:0;color:#1f4e99;text-decoration:underline;cursor:pointer">' . dol_escape_htmltag($importSummaryStatementRef) . '</button>';
		print '</form>';
	} else {
		print dol_escape_htmltag($importSummaryStatementRef);
	}
	print '</td></tr>';
	print '<tr class="oddeven"><td>' . $langs->trans('Type') . '</td><td>' . dol_escape_htmltag((string) strtoupper((string) $importResult['format'])) . '</td></tr>';
	print '<tr class="oddeven"><td>' . $langs->trans('KreaBankImportedLines') . '</td><td><strong>' . ((int) $importResult['imported_lines']) . '</strong></td></tr>';
	print '<tr class="oddeven"><td>Possíveis duplicados</td><td><strong>' . ((int) $importResult['duplicates']) . '</strong></td></tr>';
	if ($lastImportedBankAccountId > 0) {
		print '<tr class="oddeven"><td>' . $langs->trans('KreaBankImportBankAccount') . '</td><td><a target="_blank" rel="noopener" href="' . dol_buildpath('/compta/bank/card.php?id=' . $lastImportedBankAccountId, 1) . '">#' . $lastImportedBankAccountId . '</a></td></tr>';
	}
	if (!empty($importResult['bank_line_ids']) && is_array($importResult['bank_line_ids'])) {
		$firstLineId = (int) reset($importResult['bank_line_ids']);
		if ($firstLineId > 0) {
			$reconcileUrl = dol_buildpath('/custom/kreabank/reconcile.php?mainmenu=bank&leftmenu=kreabank_reconcile&line_id=' . $firstLineId, 1);
			print '<tr class="oddeven"><td>' . $langs->trans('KreaBankReconcile') . '</td><td><a target="_blank" rel="noopener" href="' . $reconcileUrl . '">#' . $firstLineId . '</a></td></tr>';
		}
	}
	print '</table>';

	$duplicatesDetails = (!empty($importResult['duplicates_details']) && is_array($importResult['duplicates_details'])) ? $importResult['duplicates_details'] : array();
	if (!empty($duplicatesDetails)) {
		$allowDuplicateSelection = ($isDuplicateReview && !empty($wizardToken) && !empty($currentWizard) && !empty($importResult['duplicates_skipped']));
		if ($allowDuplicateSelection) {
			print '<br><div class="opacitymedium">Alguns possíveis duplicados foram ignorados na confirmação. Selecione agora as linhas a importar ou conclua mantendo-as ignoradas.</div>';
			print '<form method="POST" action="' . dol_escape_htmltag($_SERVER['PHP_SELF']) . '" id="kreabankDuplicateFinishForm" style="display:none">';
			print '<input type="hidden" name="token" value="' . newToken() . '">';
			print '<input type="hidden" name="action" value="finish_duplicate_review">';
			print '<input type="hidden" name="wizard_token" value="' . dol_escape_htmltag((string) $wizardToken) . '">';
			print '</form>';
			print '<form method="POST" action="' . dol_escape_htmltag($_SERVER['PHP_SELF']) . '" id="kreabankDuplicateSelectionForm">';
			print '<input type="hidden" name="token" value="' . newToken() . '">';
			print '<input type="hidden" name="action" value="import_selected_duplicates">';
			print '<input type="hidden" name="wizard_token" value="' . dol_escape_htmltag((string) $wizardToken) . '">';
		}
		print '<br><table class="noborder centpercent">';
		print '<tr class="liste_titre"><th colspan="' . ($allowDuplicateSelection ? 8 : 7) . '">Possíveis duplicados</th></tr>';
		print '<tr class="liste_titre">';
		if ($allowDuplicateSelection) {
			print '<th class="center"><input type="checkbox" class="flat" id="kbDupSelectAll" checked></th>';
		}
		print '<th>#</th>';
		print '<th>' . $langs->trans('KreaBankDate') . '</th>';
		print '<th class="right">' . $langs->trans('KreaBankAmount') . '</th>';
		print '<th>' . $langs->trans('KreaBankReference') . '</th>';
		print '<th>' . $langs->trans('KreaBankDescription') . '</th>';
		print '<th>Origem</th>';
		print '<th>Hash</th>';
		print '</tr>';
		foreach ($duplicatesDetails as $dup) {
			if (!is_array($dup)) {
				continue;
			}
			$lineRank = isset($dup['line_rank']) ? (int) $dup['line_rank'] : 0;
			$ref = trim((string) (!empty($dup['payment_reference']) ? $dup['payment_reference'] : (!empty($dup['bank_reference']) ? $dup['bank_reference'] : '')));
			$hashPreview = '';
			if (!empty($dup['idempotency_hash'])) {
				$hashPreview = substr((string) $dup['idempotency_hash'], 0, 16);
			}
			print '<tr class="oddeven">';
			if ($allowDuplicateSelection) {
				print '<td class="center">';
				if ($lineRank > 0) {
					print '<input type="checkbox" class="flat kbDupSelectLine" name="duplicate_line_rank[]" value="' . $lineRank . '" checked>';
				} else {
					print '-';
				}
				print '</td>';
			}
			print '<td>' . ($lineRank > 0 ? $lineRank : '-') . '</td>';
			print '<td>' . dol_escape_htmltag((string) $dup['operation_date']) . '</td>';
			print '<td class="right">' . price((float) $dup['amount']) . ' ' . dol_escape_htmltag((string) (!empty($dup['currency']) ? $dup['currency'] : 'EUR')) . '</td>';
			print '<td>' . dol_escape_htmltag($ref) . '</td>';
			print '<td>' . dol_escape_htmltag((string) (!empty($dup['description']) ? $dup['description'] : '')) . '</td>';
			print '<td>' . dol_escape_htmltag((string) (!empty($dup['source']) ? $dup['source'] : 'unknown')) . '</td>';
			print '<td><span class="opacitymedium">' . dol_escape_htmltag($hashPreview) . '</span></td>';
			print '</tr>';
		}
		print '</table>';
		if ($allowDuplicateSelection) {
			print '<div class="tabsAction" style="display:flex;justify-content:flex-end;gap:8px;align-items:center;flex-wrap:wrap">';
			print '<button class="butAction" type="submit" form="kreabankDuplicateSelectionForm">Importar selecionados</button>';
			print '<button class="butActionDelete" type="submit" form="kreabankDuplicateFinishForm">Concluir sem importar</button>';
			print '</div>';
			print '</form>';
			print '<script>';
			print 'document.addEventListener("DOMContentLoaded", function () {';
			print 'var selectAll = document.getElementById("kbDupSelectAll");';
			print 'if (!selectAll) { return; }';
			print 'selectAll.addEventListener("change", function () {';
			print 'var rows = document.querySelectorAll(".kbDupSelectLine");';
			print 'for (var i = 0; i < rows.length; i++) { rows[i].checked = selectAll.checked; }';
			print '});';
			print '});';
			print '</script>';
		}
	}
}

$showHistorySection = (!$hasActiveWizard);
if ($showHistorySection) {
	$historyListParam = '&mainmenu=bank&leftmenu=kreabank_import';
	if ($historyLimit > 0) {
		$historyListParam .= '&limit=' . $historyLimit;
	}
	if ($historySearchStatementRef !== '') {
		$historyListParam .= '&search_statement_ref=' . urlencode($historySearchStatementRef);
	}
	if ($historySearchBankAccount !== '') {
		$historyListParam .= '&search_bank_account=' . urlencode($historySearchBankAccount);
	}
	if ($historySearchLastOpFrom !== '') {
		$historyListParam .= '&search_last_operation_from=' . urlencode($historySearchLastOpFrom);
	}
	if ($historySearchLastOpTo !== '') {
		$historyListParam .= '&search_last_operation_to=' . urlencode($historySearchLastOpTo);
	}
	if ($historySearchImportedLines !== '') {
		$historyListParam .= '&search_imported_lines=' . urlencode($historySearchImportedLines);
	}
	if ($historySearchAmountTotal !== '') {
		$historyListParam .= '&search_amount_total=' . urlencode($historySearchAmountTotal);
	}
	if ($historySearchSourceType !== '') {
		$historyListParam .= '&search_source_type=' . urlencode($historySearchSourceType);
	}
	if ($historySearchSourceFile !== '') {
		$historyListParam .= '&search_source_file=' . urlencode($historySearchSourceFile);
	}
	if ($historySearchImportedAtFrom !== '') {
		$historyListParam .= '&search_imported_at_from=' . urlencode($historySearchImportedAtFrom);
	}
	if ($historySearchImportedAtTo !== '') {
		$historyListParam .= '&search_imported_at_to=' . urlencode($historySearchImportedAtTo);
	}

	print '<br>';
	print '<form method="GET" id="kreaImportHistoryFilterForm" action="' . dol_escape_htmltag($_SERVER['PHP_SELF']) . '">';
	print '<input type="hidden" name="mainmenu" value="bank">';
	print '<input type="hidden" name="leftmenu" value="kreabank_import">';
	print '<input type="hidden" name="page" value="0">';
	print '<input type="hidden" name="limit" value="' . ((int) $historyLimit) . '">';
	print '<input type="hidden" name="sortfield" value="' . dol_escape_htmltag((string) $historySortField) . '">';
	print '<input type="hidden" name="sortorder" value="' . dol_escape_htmltag((string) $historySortOrder) . '">';
	print '</form>';
	print '<form method="GET" id="kreaImportHistoryPagerForm" action="' . dol_escape_htmltag($_SERVER['PHP_SELF']) . '">';
	print '<input type="hidden" name="mainmenu" value="bank">';
	print '<input type="hidden" name="leftmenu" value="kreabank_import">';
	print '<input type="hidden" name="search_statement_ref" value="' . dol_escape_htmltag($historySearchStatementRef) . '">';
	print '<input type="hidden" name="search_bank_account" value="' . dol_escape_htmltag($historySearchBankAccount) . '">';
	print '<input type="hidden" name="search_last_operation_from" value="' . dol_escape_htmltag($historySearchLastOpFrom) . '">';
	print '<input type="hidden" name="search_last_operation_to" value="' . dol_escape_htmltag($historySearchLastOpTo) . '">';
	print '<input type="hidden" name="search_imported_lines" value="' . dol_escape_htmltag($historySearchImportedLines) . '">';
	print '<input type="hidden" name="search_amount_total" value="' . dol_escape_htmltag($historySearchAmountTotal) . '">';
	print '<input type="hidden" name="search_source_type" value="' . dol_escape_htmltag($historySearchSourceType) . '">';
	print '<input type="hidden" name="search_source_file" value="' . dol_escape_htmltag($historySearchSourceFile) . '">';
	print '<input type="hidden" name="search_imported_at_from" value="' . dol_escape_htmltag($historySearchImportedAtFrom) . '">';
	print '<input type="hidden" name="search_imported_at_to" value="' . dol_escape_htmltag($historySearchImportedAtTo) . '">';

	print_barre_liste(
		$langs->trans('KreaBankImportHistory'),
		$historyPage,
		$_SERVER['PHP_SELF'],
		$historyListParam,
		$historySortField,
		$historySortOrder,
		'',
		$historyPagerNum,
		$historyTotal,
		'',
		0,
		'',
		'',
		$historyLimit
	);
	print '</form>';

	print '<div class="div-table-responsive">';
	print '<table class="tagtable nobottomiftotal liste">';
	print '<tr class="liste_titre_filter">';
	print '<td class="liste_titre"><input class="flat width100" type="text" name="search_statement_ref" value="' . dol_escape_htmltag($historySearchStatementRef) . '" placeholder="' . dol_escape_htmltag($langs->trans('Ref')) . '" form="kreaImportHistoryFilterForm"></td>';
	print '<td class="liste_titre"><input class="flat width100" type="text" name="search_bank_account" value="' . dol_escape_htmltag($historySearchBankAccount) . '" placeholder="' . dol_escape_htmltag($langs->trans('BankAccount')) . '" form="kreaImportHistoryFilterForm"></td>';
	print '<td class="liste_titre"><div style="display:flex;gap:4px;flex-direction:column;min-width:130px"><input class="flat" type="date" name="search_last_operation_from" value="' . dol_escape_htmltag($historySearchLastOpFrom) . '" title="' . dol_escape_htmltag($langs->trans('From')) . '" form="kreaImportHistoryFilterForm"><input class="flat" type="date" name="search_last_operation_to" value="' . dol_escape_htmltag($historySearchLastOpTo) . '" title="' . dol_escape_htmltag($langs->trans('To')) . '" form="kreaImportHistoryFilterForm"></div></td>';
	print '<td class="liste_titre right"><input class="flat right width75" type="text" name="search_imported_lines" value="' . dol_escape_htmltag($historySearchImportedLines) . '" placeholder=">=0" form="kreaImportHistoryFilterForm"></td>';
	print '<td class="liste_titre right"><input class="flat right width75" type="text" name="search_amount_total" value="' . dol_escape_htmltag($historySearchAmountTotal) . '" placeholder=">=0" form="kreaImportHistoryFilterForm"></td>';
	print '<td class="liste_titre"><input class="flat width100" type="text" name="search_source_type" value="' . dol_escape_htmltag($historySearchSourceType) . '" placeholder="' . dol_escape_htmltag($langs->trans('KreaBankImportSourceType')) . '" form="kreaImportHistoryFilterForm"></td>';
	print '<td class="liste_titre"><input class="flat width100" type="text" name="search_source_file" value="' . dol_escape_htmltag($historySearchSourceFile) . '" placeholder="' . dol_escape_htmltag($langs->trans('KreaBankImportSourceFile')) . '" form="kreaImportHistoryFilterForm"></td>';
	print '<td class="liste_titre"><div style="display:flex;gap:4px;flex-direction:column;min-width:130px"><input class="flat" type="date" name="search_imported_at_from" value="' . dol_escape_htmltag($historySearchImportedAtFrom) . '" title="' . dol_escape_htmltag($langs->trans('From')) . '" form="kreaImportHistoryFilterForm"><input class="flat" type="date" name="search_imported_at_to" value="' . dol_escape_htmltag($historySearchImportedAtTo) . '" title="' . dol_escape_htmltag($langs->trans('To')) . '" form="kreaImportHistoryFilterForm"></div></td>';
	print '<td class="liste_titre center maxwidthsearch"><div class="nowraponall"><button type="submit" class="liste_titre button_search reposition" name="button_search_x" value="x" form="kreaImportHistoryFilterForm"><span class="fas fa-search"></span></button><button type="submit" class="liste_titre button_removefilter reposition" name="button_removefilter_x" value="x" form="kreaImportHistoryFilterForm"><span class="fas fa-times"></span></button></div></td>';
	print '</tr>';
	print '<tr class="liste_titre">';
	print_liste_field_titre($langs->trans('Ref'), $_SERVER['PHP_SELF'], 'statement_ref', '', $historyListParam, '', $historySortField, $historySortOrder);
	print_liste_field_titre($langs->trans('BankAccount'), $_SERVER['PHP_SELF'], 'bank_account_label', '', $historyListParam, '', $historySortField, $historySortOrder);
	print_liste_field_titre($langs->trans('KreaBankDate'), $_SERVER['PHP_SELF'], 'last_operation_date', '', $historyListParam, '', $historySortField, $historySortOrder);
	print_liste_field_titre($langs->trans('KreaBankImportedLines'), $_SERVER['PHP_SELF'], 'imported_lines', '', $historyListParam, '', $historySortField, $historySortOrder);
	print_liste_field_titre($langs->trans('KreaBankAmount'), $_SERVER['PHP_SELF'], 'amount_total', '', $historyListParam, '', $historySortField, $historySortOrder);
	print_liste_field_titre($langs->trans('KreaBankImportSourceType'), $_SERVER['PHP_SELF'], 'source_type', '', $historyListParam, '', $historySortField, $historySortOrder);
	print_liste_field_titre($langs->trans('KreaBankImportSourceFile'), $_SERVER['PHP_SELF'], 'source_file', '', $historyListParam, '', $historySortField, $historySortOrder);
	print_liste_field_titre($langs->trans('KreaBankWhen'), $_SERVER['PHP_SELF'], 'imported_at', '', $historyListParam, '', $historySortField, $historySortOrder);
	print '<th class="center">' . $langs->trans('Action') . '</th>';
	print '</tr>';

	if (empty($historyRows)) {
		print '<tr class="oddeven"><td colspan="9"><span class="opacitymedium">' . $langs->trans('KreaBankImportHistoryEmpty') . '</span></td></tr>';
	} else {
		foreach ($historyRows as $row) {
			$statementRef = (string) $row->statement_ref;
			$accountId = (int) $row->bank_account_id;
			$canOpenStatement = ($statementRef !== '' && $accountId > 0);
			$accountUrl = dol_buildpath('/compta/bank/card.php?id=' . $accountId, 1);
			$lastOpDateTs = !empty($row->last_operation_date) ? $db->jdate($row->last_operation_date) : 0;
			$importedAtTs = !empty($row->imported_at) ? $db->jdate($row->imported_at) : 0;
			$confirmDeleteJs = json_encode((string) $langs->trans('KreaBankImportDeleteConfirm', $statementRef), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
			if ($confirmDeleteJs === false) {
				$confirmDeleteJs = '""';
			}

			print '<tr class="oddeven">';
			print '<td>';
			if ($canOpenStatement) {
				print '<form method="POST" action="' . dol_escape_htmltag($_SERVER['PHP_SELF']) . '" target="_blank" style="display:inline-block;margin:0">';
				print '<input type="hidden" name="token" value="' . newToken() . '">';
				print '<input type="hidden" name="action" value="open_native_statement">';
				print '<input type="hidden" name="open_bank_account_id" value="' . $accountId . '">';
				print '<input type="hidden" name="open_statement_ref" value="' . dol_escape_htmltag($statementRef) . '">';
				print '<input type="hidden" name="mainmenu" value="bank">';
				print '<input type="hidden" name="leftmenu" value="kreabank_import">';
				print '<button type="submit" class="noborderimp" style="padding:0;margin:0;color:#1f4e99;text-decoration:underline;cursor:pointer">' . dol_escape_htmltag($statementRef) . '</button>';
				print '</form>';
			} else {
				print dol_escape_htmltag($statementRef);
			}
			print '</td>';
			print '<td><a target="_blank" rel="noopener" href="' . $accountUrl . '">' . dol_escape_htmltag(trim((string) $row->bank_account_label . ' ' . (string) $row->bank_account_ref)) . '</a></td>';
			print '<td class="center">' . ($lastOpDateTs > 0 ? dol_print_date($lastOpDateTs, 'day') : '-') . '</td>';
			print '<td class="right">' . ((int) $row->imported_lines) . '</td>';
			print '<td class="right">' . price((float) $row->amount_total) . '</td>';
			print '<td>' . dol_escape_htmltag(strtoupper((string) $row->source_type)) . '</td>';
			print '<td>' . dol_escape_htmltag((string) $row->source_file) . '</td>';
			print '<td class="center">' . ($importedAtTs > 0 ? dol_print_date($importedAtTs, 'dayhour') : '-') . '</td>';
			print '<td class="center">';
			print '<span style="display:inline-flex;align-items:center;justify-content:center;gap:6px;white-space:nowrap">';
			print '<form method="POST" action="' . dol_escape_htmltag($_SERVER['PHP_SELF']) . '" style="display:inline-block;margin:0">';
			print '<input type="hidden" name="token" value="' . newToken() . '">';
			print '<input type="hidden" name="action" value="download_statement_csv">';
			print '<input type="hidden" name="download_bank_account_id" value="' . $accountId . '">';
			print '<input type="hidden" name="download_statement_ref" value="' . dol_escape_htmltag($statementRef) . '">';
			print '<input type="hidden" name="mainmenu" value="bank">';
			print '<input type="hidden" name="leftmenu" value="kreabank_import">';
			print '<input type="hidden" name="limit" value="' . ((int) $historyLimit) . '">';
			print '<input type="hidden" name="page" value="' . ((int) $historyPage) . '">';
			print '<input type="hidden" name="sortfield" value="' . dol_escape_htmltag((string) $historySortField) . '">';
			print '<input type="hidden" name="sortorder" value="' . dol_escape_htmltag((string) $historySortOrder) . '">';
			print '<input type="hidden" name="search_statement_ref" value="' . dol_escape_htmltag($historySearchStatementRef) . '">';
			print '<input type="hidden" name="search_bank_account" value="' . dol_escape_htmltag($historySearchBankAccount) . '">';
			print '<input type="hidden" name="search_last_operation_from" value="' . dol_escape_htmltag($historySearchLastOpFrom) . '">';
			print '<input type="hidden" name="search_last_operation_to" value="' . dol_escape_htmltag($historySearchLastOpTo) . '">';
			print '<input type="hidden" name="search_imported_lines" value="' . dol_escape_htmltag($historySearchImportedLines) . '">';
			print '<input type="hidden" name="search_amount_total" value="' . dol_escape_htmltag($historySearchAmountTotal) . '">';
			print '<input type="hidden" name="search_source_type" value="' . dol_escape_htmltag($historySearchSourceType) . '">';
			print '<input type="hidden" name="search_source_file" value="' . dol_escape_htmltag($historySearchSourceFile) . '">';
			print '<input type="hidden" name="search_imported_at_from" value="' . dol_escape_htmltag($historySearchImportedAtFrom) . '">';
			print '<input type="hidden" name="search_imported_at_to" value="' . dol_escape_htmltag($historySearchImportedAtTo) . '">';
			print '<button type="submit" class="noborderimp" title="' . dol_escape_htmltag($langs->trans('KreaBankImportDownloadStatementCsv')) . '">';
			print '<span class="fas fa-download" style="color:#9a9a9a" title="' . dol_escape_htmltag($langs->trans('KreaBankImportDownloadStatementCsv')) . '"></span>';
			print '</button>';
			print '</form>';
			print '<form method="POST" action="' . dol_escape_htmltag($_SERVER['PHP_SELF']) . '" style="display:inline-block;margin:0">';
			print '<input type="hidden" name="token" value="' . newToken() . '">';
			print '<input type="hidden" name="action" value="delete_statement">';
			print '<input type="hidden" name="delete_bank_account_id" value="' . $accountId . '">';
			print '<input type="hidden" name="delete_statement_ref" value="' . dol_escape_htmltag($statementRef) . '">';
			print '<input type="hidden" name="mainmenu" value="bank">';
			print '<input type="hidden" name="leftmenu" value="kreabank_import">';
			print '<input type="hidden" name="limit" value="' . ((int) $historyLimit) . '">';
			print '<input type="hidden" name="page" value="' . ((int) $historyPage) . '">';
			print '<input type="hidden" name="sortfield" value="' . dol_escape_htmltag((string) $historySortField) . '">';
			print '<input type="hidden" name="sortorder" value="' . dol_escape_htmltag((string) $historySortOrder) . '">';
			print '<input type="hidden" name="search_statement_ref" value="' . dol_escape_htmltag($historySearchStatementRef) . '">';
			print '<input type="hidden" name="search_bank_account" value="' . dol_escape_htmltag($historySearchBankAccount) . '">';
			print '<input type="hidden" name="search_last_operation_from" value="' . dol_escape_htmltag($historySearchLastOpFrom) . '">';
			print '<input type="hidden" name="search_last_operation_to" value="' . dol_escape_htmltag($historySearchLastOpTo) . '">';
			print '<input type="hidden" name="search_imported_lines" value="' . dol_escape_htmltag($historySearchImportedLines) . '">';
			print '<input type="hidden" name="search_amount_total" value="' . dol_escape_htmltag($historySearchAmountTotal) . '">';
			print '<input type="hidden" name="search_source_type" value="' . dol_escape_htmltag($historySearchSourceType) . '">';
			print '<input type="hidden" name="search_source_file" value="' . dol_escape_htmltag($historySearchSourceFile) . '">';
			print '<input type="hidden" name="search_imported_at_from" value="' . dol_escape_htmltag($historySearchImportedAtFrom) . '">';
			print '<input type="hidden" name="search_imported_at_to" value="' . dol_escape_htmltag($historySearchImportedAtTo) . '">';
			print '<button type="submit" class="noborderimp" title="' . dol_escape_htmltag($langs->trans('KreaBankImportDeleteStatement')) . '" onclick="return confirm(' . dol_escape_htmltag((string) $confirmDeleteJs) . ');">';
			print '<span class="fas fa-trash" style="color:#9a9a9a" title="' . dol_escape_htmltag($langs->trans('KreaBankImportDeleteStatement')) . '"></span>';
			print '</button>';
			print '</form>';
			print '</span>';
			print '</td>';
			print '</tr>';
		}
	}

	print '</table>';
	print '</div>';
}

print dol_get_fiche_end();
llxFooter();
$db->close();
