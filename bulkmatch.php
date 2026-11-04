<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file       kreabank/bulkmatch.php
 * \ingroup    kreabank
 * \brief      Bulk reconcile review page (line + best match).
 */

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'bulk_scan') {
	define('NOTOKENRENEWAL', 1);
}

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

require_once __DIR__ . '/lib/kreabank.lib.php';
require_once __DIR__ . '/lib/kreabank_bulk.lib.php';
require_once __DIR__ . '/lib/kreabank_manual_review.lib.php';
require_once __DIR__ . '/lib/kreabank_review_view.lib.php';
require_once __DIR__ . '/class/KreaBankService.class.php';

$langs->loadLangs(array('errors', 'companies', 'kreabank@kreabank'));

if (!isModEnabled('kreabank')) {
	accessforbidden();
}
if (!isModEnabled('banque')) {
	accessforbidden($langs->trans('ModuleBankDisabled'));
}
if (!$user->hasRight('kreabank', 'reconciliation', 'read')) {
	accessforbidden($langs->trans('KreaBankNoPermission'));
}
if (!$user->hasRight('banque', 'lire')) {
	accessforbidden($langs->trans('ErrorForbidden'));
}

$singleReview = defined('KREABANK_MATCHED_REVIEW') && KREABANK_MATCHED_REVIEW;
$reviewPage = $singleReview ? 'matched' : 'bulkmatch';
$reviewTitle = $singleReview ? 'KreaBankReviewMatched' : 'KreaBankBulkMatch';
$targetLineId = $singleReview ? max(0, GETPOSTINT('line_id')) : 0;
$targetLineSource = (string) GETPOST('line_source', 'aZ09');
$targetDocument = (string) GETPOST('review_doc', 'aZ09');

$navigationAccountId = max(0, GETPOSTISSET('account_context') ? GETPOSTINT('account_context') : GETPOSTINT('bank_account_id'));
$navigationAccount = kreabankNavigationAccount($navigationAccountId);
$service = new KreaBankService($db, $user, $langs);
$canNativeWrite = ($user->hasRight('banque', 'modifier') || $user->hasRight('banque', 'consolidate'));
$action = GETPOST('action', 'aZ09');
$isScanRequest = ($action === 'bulk_scan');
$scanChunk = (int) getDolGlobalInt('KREABANK_BULKMATCH_SCAN_CHUNK_SIZE');
if ($scanChunk <= 0) {
	$scanChunk = 200;
}
$scanChunk = max(20, min(2000, $scanChunk));
$scanBudgetMs = (int) getDolGlobalInt('KREABANK_BULKMATCH_SCAN_REQUEST_BUDGET_MS');
if ($scanBudgetMs <= 0) {
	$scanBudgetMs = 1200;
}
$scanBudgetMs = max(200, min(10000, $scanBudgetMs));
$page = GETPOSTISSET('pageplusone') ? (GETPOSTINT('pageplusone') - 1) : GETPOSTINT('page');
if ($page < 0) {
	$page = 0;
}
$limit = GETPOSTINT('limit');
if ($limit <= 0) {
	$limit = !empty($conf->liste_limit) ? (int) $conf->liste_limit : 25;
}
$limit = max(1, min(100, $limit));
$limit = $singleReview ? 1 : $limit;
$offset = $page * $limit;
$dateTolerance = getDolGlobalInt('KREABANK_AUTOMATCH_DATE_TOLERANCE');
if ($dateTolerance <= 0) {
	$dateTolerance = 3;
}
$bulkScanCacheStoreKey = $singleReview ? 'kreabank_matched_scan_state' : 'kreabank_bulkmatch_scan_state';
$bulkScanMatcherRevision = 18;
$bulkColumns = array('operation_date' => 'KreaBankDate', 'description' => 'KreaBankDescription', 'amount' => 'KreaBankAmount', 'doc_ref' => 'KreaBankDocument', 'score' => 'KreaBankAutoScore');
$bulkSort = kreabankListSort($bulkColumns, 'operation_date');
$bulkScanFilterScope = array(
	'bank_account_id' => $navigationAccountId,
);
$bulkScanCacheScope = ((int) $conf->entity) . '|' . ((int) $user->id) . '|' . ((int) $dateTolerance) . '|' . ((int) $bulkScanMatcherRevision) . '|' . hash('sha256', json_encode($bulkScanFilterScope));
if (empty($_SESSION['kreabank_bulk_review_secret'])) {
	$_SESSION['kreabank_bulk_review_secret'] = bin2hex(random_bytes(32));
}
$bulkReviewSecret = $_SESSION['kreabank_bulk_review_secret'];
$bulkReviewScope = (int) $conf->entity . '|' . (int) $user->id . '|' . $navigationAccountId;
$bulkScanCacheTtl = (int) getDolGlobalInt('KREABANK_BULKMATCH_SCAN_CACHE_TTL');
if ($bulkScanCacheTtl <= 0) {
	$bulkScanCacheTtl = 120;
}
$bulkMatchMinScore = (int) getDolGlobalInt('KREABANK_BULKMATCH_MIN_SCORE');
if ($bulkMatchMinScore <= 0) {
	$bulkMatchMinScore = 100;
}
$bulkMatchMinScore = max(1, min(1000, $bulkMatchMinScore));
$bulkAutoReloadMax = (int) getDolGlobalInt('KREABANK_BULKMATCH_AUTO_RELOAD_MAX');
if ($bulkAutoReloadMax <= 0) {
	$bulkAutoReloadMax = 30;
}
$bulkAutoReloadMax = max(1, min(200, $bulkAutoReloadMax));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	if (!kreabankIsTokenValid()) {
		accessforbidden('Invalid token');
	}
}
$enforcePostActionWithToken = static function () {
	if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !kreabankIsTokenValid()) {
		accessforbidden('Invalid token');
	}
};

if ($isScanRequest) {
	$enforcePostActionWithToken();
}

$manualReviewCallbacks = kreabankManualReviewCallbacks($service, $dateTolerance, $bulkMatchMinScore, $singleReview);
$prepareReviewRow = $manualReviewCallbacks['prepareReviewRow'];
$resolveLineBestSuggestion = $manualReviewCallbacks['resolveLineBestSuggestion'];
$buildLinkedFallbackSuggestions = $manualReviewCallbacks['buildLinkedFallbackSuggestions'];
$appendLinkedFallbackSuggestions = $manualReviewCallbacks['appendLinkedFallbackSuggestions'];
$resolveSuggestionCardUrl = static function ($docType, $docId) {
	$docType = (string) $docType;
	$docId = (int) $docId;
	if ($docId <= 0) {
		return '';
	}
	if ($docType === 'native_bank') {
		return dol_buildpath('/compta/bank/line.php?rowid=' . $docId, 1);
	}
	if ($docType === 'payment' || $docType === 'payment_linked') {
		return dol_buildpath('/compta/paiement/card.php?id=' . $docId, 1);
	}
	if ($docType === 'payment_supplier' || $docType === 'payment_supplier_linked') {
		return dol_buildpath('/fourn/paiement/card.php?id=' . $docId, 1);
	}
	if ($docType === 'customer_invoice') {
		return dol_buildpath('/compta/facture/card.php?facid=' . $docId, 1);
	}
	if ($docType === 'supplier_invoice') {
		return dol_buildpath('/fourn/facture/card.php?facid=' . $docId, 1);
	}

	return '';
};


if ($action === 'bulk_match_confirm') {
	if (!$user->hasRight('kreabank', 'reconciliation', 'write') || !$canNativeWrite) {
		accessforbidden();
	}
	$enforcePostActionWithToken();
	$selectedLineIds = GETPOST('selected_line_ids', 'array');
	$reviewSignatures = GETPOST('review_signatures', 'array');
	$reviewSources = GETPOST('review_sources', 'array');
	$reviewExpires = GETPOSTINT('review_expires');
	// The individual submit button approves its sole signed displayed pair.
	$reviewConfirmed = $singleReview || GETPOSTINT('review_confirmed') === 1;
	$lineIds = array();
	if (is_array($selectedLineIds)) {
		foreach ($selectedLineIds as $selectedLineId) {
			$selectedLineId = (int) $selectedLineId;
			if ($selectedLineId > 0) {
				$lineIds[$selectedLineId] = $selectedLineId;
			}
		}
	}

	if (!$reviewConfirmed || count($lineIds) > ($singleReview ? 1 : 100)) {
		setEventMessages($langs->trans('KreaBankBulkMatchReviewRequired'), null, 'warnings');
	} elseif (empty($lineIds)) {
		setEventMessages($langs->trans('KreaBankNoLinesSelected'), null, 'warnings');
	} else {
		$done = 0;
		$noMatch = 0;
		$changed = 0;
		$failed = 0;
		$errors = array();
		foreach ($lineIds as $lineId) {
			try {
				$line = $service->getBulkReviewLine((int) $lineId, $reviewSources[$lineId] ?? '');
				if (empty($line) || (int) $line['status'] !== 0) {
					$noMatch++;
					continue;
				}

				if (!empty($line['fk_statement']) && $service->getPaymentAccountReview($lineId, array($line['bank_account_id']))) {
					$changed++;
					continue;
				}
				$freshSuggestions = $service->getSuggestionsForLines(array($line), 0, $dateTolerance, null, false);
				$suggestions = $freshSuggestions[(int) $lineId] ?? array();
				$suggestions = $appendLinkedFallbackSuggestions($service, $line, $suggestions, $dateTolerance);
				$resolved = $resolveLineBestSuggestion($line, $suggestions);
				$suggestion = !empty($resolved['suggestion']) ? (array) $resolved['suggestion'] : null;
				$allocation = (float) $resolved['allocation'];
				if (!$suggestion || $allocation <= 0.00001) {
					$noMatch++;
					continue;
				}
				$reviewedRow = array('line' => $line, 'suggestion' => $suggestion, 'allocation' => $allocation);
				if (!kreabankBulkReviewIsValid($reviewedRow, $bulkReviewScope, $reviewExpires,
					$reviewSignatures[$lineId] ?? '', $bulkReviewSecret, dol_now(), $reviewConfirmed, $navigationAccountId)) {
					$changed++;
					continue;
				}

				kreabankExecuteManualReview($service, $reviewedRow);
				$done++;
			} catch (Exception $e) {
				$failed++;
				$errors[] = $e->getMessage();
			}
		}

		setEventMessages($langs->trans('KreaBankBulkMatchDone', (string) $done, (string) count($lineIds)), null, $done > 0 ? 'mesgs' : 'warnings');
		if ($noMatch > 0) {
			setEventMessages($langs->trans('KreaBankBulkMatchNoMatchSkipped', (string) $noMatch), null, 'warnings');
		}
		if ($changed > 0) {
			setEventMessages($langs->trans('KreaBankBulkMatchReviewChanged', (string) $changed), null, 'warnings');
		}
		if ($failed > 0) {
			$errorList = array_slice(array_values(array_unique($errors)), 0, 5);
			setEventMessages($langs->trans('KreaBankBulkMatchFailed', (string) $failed), $errorList, 'errors');
		}
	}

	// Bulk confirmation can change pending/matchable lines; force cache refresh.
	unset($_SESSION['kreabank_bulkmatch_scan_state'], $_SESSION['kreabank_matched_scan_state']);
	header('Location: ' . dol_buildpath('/custom/kreabank/' . $reviewPage . '.php', 1) . '?account_context=' . $navigationAccountId . '&limit=' . $limit . '&sortfield=' . $bulkSort['field'] . '&sortorder=' . $bulkSort['order']);
	exit;
}

$rows = array();
$matchedRows = array();
$matchedCount = 0;
$preselectedCount = 0;
$pendingTotal = 0;
$bulkPagerNum = 0;
$scanInProgress = false;
$scanAutoReloadCount = 0;
$scanAutoReloadEnabled = false;
$scanProgressPct = 0;
$scanScannedCount = 0;
$scanState = array();

try {
	if ($targetLineId > 0) {
		// Read one explicit source through the same owned-company review and executor.
		$page = 0;
		$offset = 0;
		$line = $service->getBulkReviewLine($targetLineId, $targetLineSource);
		if ($line && (int) $line['status'] === 0 && (!$navigationAccountId || (int) $line['bank_account_id'] === $navigationAccountId)) {
			$pendingTotal = 1;
			$row = $prepareReviewRow($line);
			if ($row && ($targetDocument === '' || $targetDocument === $row['suggestion']['doc_type'] . '__' . (int) $row['suggestion']['doc_id'])) {
				$rows = array($row);
				$matchedCount = 1;
				$preselectedCount = !empty($row['preselected']) ? 1 : 0;
			} else {
				setEventMessages($langs->trans('KreaBankBulkMatchReviewChanged', '1'), null, 'warnings');
			}
		}
		$scanScannedCount = $pendingTotal;
		$scanProgressPct = 100;
	} else {
	$pendingTotal = (int) $service->getPendingLinesCount($bulkScanFilterScope);
	$pendingFingerprintPayload = array(
		'pending_total' => $pendingTotal,
		'entity' => (int) $conf->entity,
		'filter_scope' => $bulkScanFilterScope,
	);
	if ($pendingTotal > 0) {
		$firstPending = $service->getPendingLines(1, 0, 'b.rowid', 'ASC', $bulkScanFilterScope);
		$lastPending = $service->getPendingLines(1, 0, 'b.rowid', 'DESC', $bulkScanFilterScope);
		$headSample = $service->getPendingLines(min(10, $pendingTotal), 0, 'b.rowid', 'ASC', $bulkScanFilterScope);
		$tailSample = $service->getPendingLines(min(10, $pendingTotal), 0, 'b.rowid', 'DESC', $bulkScanFilterScope);
		if (!empty($firstPending[0]) && is_array($firstPending[0])) {
			$pendingFingerprintPayload['first'] = array(
				'rowid' => (int) $firstPending[0]['rowid'],
				'amount' => (float) $firstPending[0]['amount'],
				'date' => (string) (!empty($firstPending[0]['operation_date']) ? $firstPending[0]['operation_date'] : ''),
			);
		}
		if (!empty($lastPending[0]) && is_array($lastPending[0])) {
			$pendingFingerprintPayload['last'] = array(
				'rowid' => (int) $lastPending[0]['rowid'],
				'amount' => (float) $lastPending[0]['amount'],
				'date' => (string) (!empty($lastPending[0]['operation_date']) ? $lastPending[0]['operation_date'] : ''),
			);
		}
		$sampleToFingerprintRows = static function ($rows) {
			$signature = array();
			foreach ((array) $rows as $row) {
				if (!is_array($row)) {
					continue;
				}
				$signature[] = array(
					'rowid' => (int) (!empty($row['rowid']) ? $row['rowid'] : 0),
					'amount' => (float) (!empty($row['amount']) ? $row['amount'] : 0.0),
					'date' => (string) (!empty($row['operation_date']) ? $row['operation_date'] : ''),
					'direction' => (int) (!empty($row['direction']) ? $row['direction'] : 0),
				);
			}

			return $signature;
		};
		$pendingFingerprintPayload['sample_head'] = $sampleToFingerprintRows($headSample);
		$pendingFingerprintPayload['sample_tail'] = $sampleToFingerprintRows($tailSample);
	}
	$pendingFingerprint = hash('sha256', json_encode($pendingFingerprintPayload));

	$scanState = array(
		'scope' => $bulkScanCacheScope,
		'fingerprint' => $pendingFingerprint,
		'pending_total' => (int) $pendingTotal,
		'next_offset' => 0,
		'done' => ($pendingTotal <= 0),
		'matched_rows' => array(),
		'reload_count' => 0,
		'tms' => 0,
	);

	if (!empty($_SESSION[$bulkScanCacheStoreKey]) && is_array($_SESSION[$bulkScanCacheStoreKey])) {
		$cachedScan = (array) $_SESSION[$bulkScanCacheStoreKey];
		$cachedTms = !empty($cachedScan['tms']) ? (int) $cachedScan['tms'] : 0;
		$cacheAge = ($cachedTms > 0 ? (dol_now() - $cachedTms) : PHP_INT_MAX);
		$cacheIsFresh = ($cacheAge <= $bulkScanCacheTtl);
		$scopeMatches = ((string) (!empty($cachedScan['scope']) ? $cachedScan['scope'] : '') === $bulkScanCacheScope);
		$fingerprintMatches = ((string) (!empty($cachedScan['fingerprint']) ? $cachedScan['fingerprint'] : '') === $pendingFingerprint);
		$pendingMatches = ((int) (!empty($cachedScan['pending_total']) ? $cachedScan['pending_total'] : -1) === $pendingTotal);
		if ($cacheIsFresh && $scopeMatches && $fingerprintMatches && $pendingMatches) {
			$scanState = $cachedScan;
			if (empty($scanState['matched_rows']) || !is_array($scanState['matched_rows'])) {
				$scanState['matched_rows'] = array();
			}
			$normalizedMatchedRows = array();
			foreach ($scanState['matched_rows'] as $matchedRow) {
				if (!is_array($matchedRow)) {
					continue;
				}
				// Backward compatibility: convert pre-compact scan cache format.
				if (empty($matchedRow['line_id']) && !empty($matchedRow['line']) && is_array($matchedRow['line']) && !empty($matchedRow['suggestion']) && is_array($matchedRow['suggestion'])) {
					$normalizedMatchedRows[] = array(
						'line_id' => (int) (!empty($matchedRow['line']['rowid']) ? $matchedRow['line']['rowid'] : 0),
						'doc_type' => (string) (!empty($matchedRow['suggestion']['doc_type']) ? $matchedRow['suggestion']['doc_type'] : ''),
						'doc_id' => (int) (!empty($matchedRow['suggestion']['doc_id']) ? $matchedRow['suggestion']['doc_id'] : 0),
						'score' => (int) (!empty($matchedRow['suggestion']['score']) ? $matchedRow['suggestion']['score'] : 0),
						'allocation' => (float) (!empty($matchedRow['allocation']) ? $matchedRow['allocation'] : 0.0),
					);
					continue;
				}
				$normalizedMatchedRows[] = array(
					'scan_position' => (int) ($matchedRow['scan_position'] ?? 0),
					'operation_date' => (string) ($matchedRow['operation_date'] ?? ''),
					'description' => (string) ($matchedRow['description'] ?? ''),
					'amount' => (float) ($matchedRow['amount'] ?? 0),
					'doc_ref' => (string) ($matchedRow['doc_ref'] ?? ''),
					'line_id' => (int) (!empty($matchedRow['line_id']) ? $matchedRow['line_id'] : 0),
					'doc_type' => (string) (!empty($matchedRow['doc_type']) ? $matchedRow['doc_type'] : ''),
					'doc_id' => (int) (!empty($matchedRow['doc_id']) ? $matchedRow['doc_id'] : 0),
					'score' => (int) (!empty($matchedRow['score']) ? $matchedRow['score'] : 0),
					'allocation' => (float) (!empty($matchedRow['allocation']) ? $matchedRow['allocation'] : 0.0),
				);
			}
			$scanState['matched_rows'] = $normalizedMatchedRows;
			$scanState['next_offset'] = max(0, (int) (!empty($scanState['next_offset']) ? $scanState['next_offset'] : 0));
		}
	}

	$scanState['matched_rows'] = kreabankListOrderRows($scanState['matched_rows'], $bulkSort, static function ($row, $field) { return $row[$field] ?? null; });
	$scanState['display_rows'] = !empty($scanState['display_rows']) && is_array($scanState['display_rows']) ? $scanState['display_rows'] : array();
	$scanState['generation'] = !empty($scanState['generation']) ? $scanState['generation'] : bin2hex(random_bytes(16));
	$originalGeneration = $scanState['generation'];
	$originalOffset = (int) $scanState['next_offset'];
	$originalReloadCount = (int) $scanState['reload_count'];
	if (($scanState['display_page'] ?? -1) !== $page || ($scanState['display_limit'] ?? 0) !== $limit || ($scanState['display_sort'] ?? '') !== $bulkSort['field'] . '|' . $bulkSort['order']) {
		$scanState['display_rows'] = array();
		$scanState['reload_count'] = 0;
		$scanState['generation'] = bin2hex(random_bytes(16));
	}
	$scanState['display_page'] = $page;
	$scanState['display_limit'] = $limit;
	$scanState['display_sort'] = $bulkSort['field'] . '|' . $bulkSort['order'];
	if ($isScanRequest) {
		$sessionIdentity = array($_SESSION['dol_login'] ?? '', $_SESSION['dol_entity'] ?? 0);
		// Matching must not block navigation, other tabs, or company switching.
		session_write_close();
		if (GETPOSTINT('resume') === 1) {
			$scanState['reload_count'] = 0;
		}
		$scanState = kreabankAdvanceBulkScan($scanState, $pendingTotal, $scanChunk, $scanBudgetMs, $page, $limit,
			static function ($chunk, $scanOffset) use ($service, $bulkScanFilterScope) {
				return $service->getPendingLines($chunk, $scanOffset, 'b.dateo', 'ASC', $bulkScanFilterScope);
			},
			static function ($id) use ($service, $navigationAccountId) {
				$line = $service->getLineById($id);
				return $line && (!$navigationAccountId || (int) $line['bank_account_id'] === $navigationAccountId) ? $line : null;
			}, $prepareReviewRow, null, $bulkSort);
		$scanState['reload_count'] = kreabankBulkScanNeedsWork($scanState, $page, $limit) ? $scanState['reload_count'] + 1 : 0;
		if (!session_start()) {
			throw new RuntimeException('Cannot save bulk review progress');
		}
		$currentState = $_SESSION[$bulkScanCacheStoreKey] ?? array();
		if (!kreabankBulkScanCanSave($currentState,
			array('generation' => $originalGeneration, 'next_offset' => $originalOffset, 'reload_count' => $originalReloadCount),
			$sessionIdentity, array($_SESSION['dol_login'] ?? '', $_SESSION['dol_entity'] ?? 0))) {
			session_write_close();
			http_response_code(409);
			header('Content-Type: application/json');
			echo json_encode(array('error' => $langs->transnoentities('KreaBankBulkMatchContextChanged')));
			exit;
		}
	}
	$scanState['scope'] = $bulkScanCacheScope;
	$scanState['fingerprint'] = $pendingFingerprint;
	$scanState['pending_total'] = (int) $pendingTotal;
	$scanState['tms'] = dol_now();
	$_SESSION[$bulkScanCacheStoreKey] = $scanState;
	if ($isScanRequest) {
		session_write_close();
	}

	$matchedRows = $scanState['matched_rows'];
	$matchedCount = count($matchedRows);
	$scanScannedCount = min($pendingTotal, (int) $scanState['next_offset']);
	$scanInProgress = kreabankBulkScanNeedsWork($scanState, $page, $limit);
	$scanAutoReloadCount = (int) $scanState['reload_count'];
	$scanAutoReloadEnabled = ($scanInProgress && $scanAutoReloadCount < $bulkAutoReloadMax);
	$scanProgressPct = $pendingTotal > 0 ? (int) floor($scanScannedCount * 100 / $pendingTotal) : 100;
	if ($page > 0 && $offset >= $matchedCount && (!$singleReview || !$scanInProgress)) {
		if ($singleReview) {
			$redirect = kreabankWorkspaceUrl('matched', $navigationAccountId) . '&sortfield=' . $bulkSort['field'] . '&sortorder=' . $bulkSort['order'];
			if ($isScanRequest) {
				header('Content-Type: application/json');
				header('Cache-Control: no-store');
				echo json_encode(array('redirect' => $redirect));
			} else {
				header('Location: ' . $redirect);
			}
			exit;
		}
		$page = 0;
		$offset = 0;
	}
	// Presentation is bounded to this page. Confirmation always recomputes suggestions.
	foreach (array_slice($matchedRows, $offset, $limit) as $match) {
		$row = $scanState['display_rows'][(int) $match['line_id']] ?? null;
		if ($row) {
			$rows[] = $row;
			$preselectedCount += !empty($row['preselected']) ? 1 : 0;
		}
	}
	$bulkPagerNum = min($limit, max(0, $matchedCount - $offset));
	if ($offset + $bulkPagerNum < $matchedCount) {
		$bulkPagerNum = $limit + 1;
	}
	}
} catch (Exception $e) {
	if ($isScanRequest) {
		http_response_code(500);
		header('Content-Type: application/json');
		echo json_encode(array('error' => $langs->transnoentities('KreaBankBulkMatchScanFailed')));
		dol_syslog('KreaBank bulk review scan failed for company ' . (int) $conf->entity, LOG_ERR);
		exit;
	}
	setEventMessages($e->getMessage(), null, 'errors');
}

// Individual review starts only once sorting can no longer move the displayed pair.
if ($singleReview && $scanInProgress) { $rows = array(); }
$presentation = hash('sha256', json_encode($singleReview && $scanInProgress
	? array('loading', $page, $bulkSort) : array($page, $bulkSort, $matchedCount, $rows, $scanInProgress && !$rows)));

$param = '&mainmenu=bank&leftmenu=kreabank_' . $reviewPage . '&account_context=' . $navigationAccountId . '&sortfield=' . $bulkSort['field'] . '&sortorder=' . $bulkSort['order'];
if ($limit > 0 && $limit !== (int) $conf->liste_limit) {
	$param .= '&limit=' . $limit;
}

if (!$isScanRequest) {
	llxHeader('', $langs->trans($reviewTitle), '', '', 0, 0, '', '', '', 'mod-kreabank page-bulkmatch bodyforlist');

	kreabankPrintWorkspaceNavigation($reviewPage, $navigationAccount);
	print load_fiche_titre($langs->trans($singleReview ? $reviewTitle : 'KreaBankBulkMatchTitle'), '', 'title_setup');

	print '<link rel="stylesheet" href="' . dol_buildpath('/custom/kreabank/css/review-workspace.css', 1) . '?v=' . filemtime(__DIR__ . '/css/review-workspace.css') . '">';
	print '<div class="kb-review" data-review-mode="' . ($singleReview ? 'single' : 'bulk') . '">';
	print '<div class="kb-review-heading"><div><h2>' . $langs->trans('KreaBankReviewExistingRecords') . '</h2><p>' . $langs->trans($singleReview ? 'KreaBankReviewSingleHelp' : 'KreaBankReviewBulkHelp') . '</p></div><a class="kb-review-secondary" href="' . dol_escape_htmltag(kreabankWorkspaceUrl('reconcile', $navigationAccountId)) . '">' . $langs->trans('KreaBankReviewPending') . '</a></div>';
	print '<div id="krea-bulk-progress">';
} else {
	ob_start();
}

print '<div class="krea-bulk-summary">';
print '<span>' . $langs->trans('KreaBankPendingCount') . ' <strong>' . ((int) $pendingTotal) . '</strong></span>';
print '<span>' . $langs->trans($singleReview ? 'KreaBankBulkMatchSuggested' : 'KreaBankBulkReviewResults') . ' <strong>' . ((int) $matchedCount) . '</strong></span>';
if (!$singleReview) print '<span>' . $langs->trans('KreaBankBulkMatchPreselected') . ' <strong>' . ((int) $preselectedCount) . '</strong></span>';
print '</div>';
if ($scanInProgress) {
	print '<div class="krea-bulk-progress">';
	print dol_escape_htmltag($langs->trans('KreaBankBulkMatchScanProgress', (string) $scanScannedCount, (string) $pendingTotal, (string) $scanProgressPct));
	print '<div class="krea-bulk-progress-bar"><span style="width:' . ((int) $scanProgressPct) . '%"></span></div>';
	print '</div>';
	if (!$scanAutoReloadEnabled) {
		print '<div class="krea-bulk-warning">' . $langs->trans('KreaBankBulkMatchRefreshPaused', (string) $scanAutoReloadCount, (string) $bulkAutoReloadMax) . '</div>';
	}
}

if ($isScanRequest) {
	$progressHtml = ob_get_clean();
	ob_start();
} else {
	print '</div><div id="krea-bulk-results">';
}

print kreabankListSortLinks($bulkColumns, $bulkSort, $targetLineId > 0 ? array('account_context'=>$navigationAccountId, 'line_id'=>$targetLineId, 'line_source'=>$targetLineSource, 'review_doc'=>$targetDocument) : array());
print '<form method="GET" id="kreaBulkMatchPagerForm" action="' . dol_escape_htmltag($_SERVER['PHP_SELF']) . '">';
print kreabankNavigationHiddenFields($navigationAccountId);
print '<input type="hidden" name="sortfield" value="' . dol_escape_htmltag($bulkSort['field']) . '"><input type="hidden" name="sortorder" value="' . $bulkSort['order'] . '">';
print '<input type="hidden" name="mainmenu" value="bank">';
print '<input type="hidden" name="leftmenu" value="kreabank_' . $reviewPage . '">';
if ($targetLineId > 0) {
	print '<input type="hidden" name="line_id" value="' . $targetLineId . '"><input type="hidden" name="line_source" value="' . dol_escape_htmltag($targetLineSource) . '"><input type="hidden" name="review_doc" value="' . dol_escape_htmltag($targetDocument) . '">';
}
if ($singleReview) {
	print '<nav class="krea-bulk-summary" aria-label="' . dol_escape_htmltag($langs->trans('KreaBankReviewMatched')) . '">';
	if ($page > 0) {
		print '<a class="button" href="' . dol_escape_htmltag($_SERVER['PHP_SELF'] . '?page=' . ($page - 1) . $param) . '">' . $langs->trans('Previous') . '</a>';
	}
	if ($matchedCount > 0 && $page < $matchedCount) {
		print '<span>' . $langs->trans('KreaBankReviewPosition', (string) ($page + 1), (string) $matchedCount) . '</span>';
	}
	if ($page + 1 < $matchedCount) {
		print '<a class="button" href="' . dol_escape_htmltag($_SERVER['PHP_SELF'] . '?page=' . ($page + 1) . $param) . '">' . $langs->trans('Next') . '</a>';
	}
	print '</nav>';
} else {
	print_barre_liste($langs->trans('KreaBankBulkReviewResults'), $page, $_SERVER['PHP_SELF'], $param, '', '', '', $bulkPagerNum, $matchedCount, 'title_setup', 0, '', '', $limit, 0, 0, 1);
}
print '</form>';

$canReviewWrite = $user->hasRight('kreabank', 'reconciliation', 'write') && $canNativeWrite;
if (empty($rows)) {
	print '<section class="kb-review-empty" aria-labelledby="kb-review-empty-title"><h2 id="kb-review-empty-title">' . $langs->trans($scanInProgress ? 'KreaBankBulkMatchLoading' : ($pendingTotal > 0 ? 'KreaBankBulkMatchNoSuggestions' : 'KreaBankNoStatementLines')) . '</h2>';
	if (!$scanInProgress && $pendingTotal > 0) {
		print '<p>' . $langs->trans('KreaBankReviewNoMatchHelp') . '</p>';
		if ($canReviewWrite) { print '<a class="kb-review-primary" href="' . dol_escape_htmltag(kreabankWorkspaceUrl('reconcile', $navigationAccountId) . '&workflow=create') . '">' . $langs->trans('KreaBankWorkspaceCreate') . '</a>'; }
		else { print '<a class="kb-review-secondary" href="' . dol_escape_htmltag(kreabankWorkspaceUrl('reconcile', $navigationAccountId)) . '">' . $langs->trans('KreaBankReviewPending') . '</a>'; }
	}
	print '</section>';
} else {
	print '<form method="POST" action="' . dol_escape_htmltag($_SERVER['PHP_SELF']) . '" id="kreaBulkMatchForm" data-locale="' . dol_escape_htmltag(str_replace('_', '-', $langs->defaultlang ?? 'en_US')) . '">';
	print kreabankNavigationHiddenFields($navigationAccountId);
	print '<input type="hidden" name="sortfield" value="' . dol_escape_htmltag($bulkSort['field']) . '"><input type="hidden" name="sortorder" value="' . dol_escape_htmltag($bulkSort['order']) . '">';
	print '<input type="hidden" name="token" value="' . newToken() . '">';
	print '<input type="hidden" name="action" value="bulk_match_confirm">';
	print '<input type="hidden" name="mainmenu" value="bank"><input type="hidden" name="leftmenu" value="kreabank_' . $reviewPage . '">';
	print '<input type="hidden" name="page" value="' . ((int) $page) . '"><input type="hidden" name="limit" value="' . ((int) $limit) . '">';
	$reviewExpires = dol_now() + 1800;
	print '<input type="hidden" name="review_expires" value="' . $reviewExpires . '">';
	print '<details class="kb-help"><summary>' . $langs->trans('KreaBankScoreGuide') . '</summary><p>' . $langs->trans('KreaBankBulkMatchScoreHelp') . '</p></details>';
	if (!$singleReview && $canReviewWrite) { print '<label class="krea-bulk-select-all"><input type="checkbox" id="krea_bulk_match_all"' . ($preselectedCount === count($rows) ? ' checked' : '') . '> ' . $langs->trans('KreaBankSelectAll') . '</label>'; }
	print '<div class="krea-bulk-list">';
	foreach ($rows as $row) {
		$line = (array) $row['line'];
		if (!empty($row['account_review'])) { print kreabankPaymentAccountReviewHtml($row['account_review'], $langs); continue; }
		$lineId = (int) $line['rowid'];
		$reviewSignature = kreabankBulkReviewSignature($row, $bulkReviewScope, $reviewExpires, $bulkReviewSecret);
		print '<article class="krea-bulk-row" aria-labelledby="kb-review-source-' . $lineId . '">';
		print '<input type="hidden" name="review_signatures[' . $lineId . ']" value="' . $reviewSignature . '">';
		print '<input type="hidden" name="review_sources[' . $lineId . ']" value="' . (!empty($line['fk_statement']) ? 'staged' : 'native') . '">';
		if ($singleReview) { print '<input type="hidden" name="selected_line_ids[]" value="' . $lineId . '">'; }
		elseif ($canReviewWrite) {
			print '<div class="krea-bulk-row-check"><label><input type="checkbox" class="krea-bulk-match-line" name="selected_line_ids[]" data-allocation="' . number_format((float) $row['allocation'], 8, '.', '') . '" data-currency="' . dol_escape_htmltag($line['currency']) . '" aria-describedby="kb-review-source-' . $lineId . '" value="' . $lineId . '"' . (!empty($row['preselected']) ? ' checked' : '') . '> ' . $langs->trans('KreaBankSelectMatch') . '</label><span>' . $langs->trans(empty($row['is_bulk_safe']) ? 'KreaBankBulkMatchLowConfidence' : 'KreaBankReviewStrongEvidence') . '</span></div>';
		}
		print '<div class="kb-review-row-content">' . kreabankReviewPairHtml($row, $langs, $mysoc->name, $resolveSuggestionCardUrl);
		print '</div></article>';
	}
	print '</div>';
	if ($canReviewWrite) {
		print '<div class="krea-bulk-actions">';
		if (!$singleReview) {
			print '<output class="kb-review-selection" id="kb-review-selection" aria-live="polite" data-label="' . dol_escape_htmltag($langs->trans('KreaBankReviewSelectedCount')) . '"></output>';
			print '<label class="kb-review-approval"><input type="checkbox" name="review_confirmed" value="1" required> ' . $langs->trans('KreaBankBulkMatchReviewApproval') . '</label>';
		}
		print '<button type="submit" class="kb-review-submit" aria-describedby="kb-review-feedback">' . $langs->trans($singleReview ? (in_array($rows[0]['suggestion']['doc_type'], array('customer_invoice', 'supplier_invoice'), true) ? 'KreaBankReviewPayInvoice' : 'KreaBankConfirmCurrentMatch') : 'KreaBankReviewConfirmSelected') . '</button></div>';
		print '<p class="kb-review-feedback" id="kb-review-feedback" role="status" data-select="' . dol_escape_htmltag($langs->trans('KreaBankReviewSelectFirst')) . '" data-approve="' . dol_escape_htmltag($langs->trans('KreaBankBulkMatchReviewRequired')) . '" data-ready="' . dol_escape_htmltag($langs->trans('KreaBankReviewReady')) . '">' . $langs->trans($singleReview ? 'KreaBankReviewSingleApproval' : 'KreaBankBulkMatchReviewRequired') . '</p>';
	}
	print '</form>';
}

if ($isScanRequest) {
	header('Content-Type: application/json');
	header('Cache-Control: no-store');
	echo json_encode(array('html' => ob_get_clean(), 'progressHtml' => $progressHtml, 'presentation' => $presentation, 'more' => $scanInProgress,
		'auto' => $scanAutoReloadEnabled, 'scanned' => $scanScannedCount, 'total' => $pendingTotal,
		'token' => currentToken()), (defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0));
	$db->close();
	exit;
}
print '</div>';
print '<div class="krea-bulk-progress" id="krea-bulk-scan-status" role="status" aria-live="polite"></div>';
print '<button type="button" class="button" id="krea-bulk-scan-resume" hidden>' . $langs->trans('KreaBankBulkMatchContinue') . '</button>';
$scanClientConfig = array('sortfield' => $bulkSort['field'], 'sortorder' => $bulkSort['order'], 'url' => $_SERVER['PHP_SELF'], 'token' => currentToken(), 'page' => $page, 'limit' => $limit,
	'presentation' => $presentation,
	'account' => $navigationAccountId, 'more' => $scanInProgress, 'auto' => $scanAutoReloadEnabled,
	'loading' => $langs->transnoentities('KreaBankBulkMatchLoading'), 'paused' => $langs->transnoentities('KreaBankBulkMatchReviewPaused'),
	'failed' => $langs->transnoentities('KreaBankBulkMatchScanFailed'), 'refresh' => $langs->transnoentities('Refresh'));
print '<script type="application/json" id="krea-bulk-scan-config">' . json_encode($scanClientConfig, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . '</script>';
print '</div>';
print '<script src="' . dol_buildpath('/custom/kreabank/js/review-workspace.js', 1) . '?v=' . filemtime(__DIR__ . '/js/review-workspace.js') . '" defer></script>';
print '<script src="' . dol_buildpath('/custom/kreabank/js/bulkmatch.js', 1) . '?v=' . filemtime(__DIR__ . '/js/bulkmatch.js') . '" defer></script>';
print dol_get_fiche_end();
llxFooter();
$db->close();
