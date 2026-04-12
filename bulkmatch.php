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

$service = new KreaBankService($db, $user, $langs);
$canNativeWrite = ($user->hasRight('banque', 'modifier') || $user->hasRight('banque', 'consolidate'));
$action = GETPOST('action', 'aZ09');
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
$limit = max(1, min(1000, $limit));
$offset = $page * $limit;
$dateTolerance = getDolGlobalInt('KREABANK_AUTOMATCH_DATE_TOLERANCE');
if ($dateTolerance <= 0) {
	$dateTolerance = 3;
}
$bulkScanCacheStoreKey = 'kreabank_bulkmatch_scan_state';
$bulkScanMatcherRevision = 10;
$bulkScanFilterScope = array(
	'bank_account_id' => GETPOSTINT('bank_account_id'),
);
$bulkScanCacheScope = ((int) $conf->entity) . '|' . ((int) $user->id) . '|' . ((int) $dateTolerance) . '|' . ((int) $bulkScanMatcherRevision) . '|' . hash('sha256', json_encode($bulkScanFilterScope));
$bulkScanCacheTtl = (int) getDolGlobalInt('KREABANK_BULKMATCH_SCAN_CACHE_TTL');
if ($bulkScanCacheTtl <= 0) {
	$bulkScanCacheTtl = 120;
}
$bulkScanMaxRows = 5000;
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

$resolveLineDirection = static function ($line) {
	$lineDirection = (int) (!empty($line['direction']) ? $line['direction'] : 0);
	if ($lineDirection === 0) {
		$lineAmount = (float) (!empty($line['amount']) ? $line['amount'] : 0.0);
		if ($lineAmount > 0.0000001) {
			$lineDirection = 1;
		} elseif ($lineAmount < -0.0000001) {
			$lineDirection = -1;
		}
	}

	return $lineDirection;
};
$resolveSuggestionDirection = static function ($suggestion) {
	$docType = (string) (isset($suggestion['doc_type']) ? $suggestion['doc_type'] : '');
	if ($docType === 'customer_invoice') {
		return 1;
	}
	if ($docType === 'supplier_invoice') {
		return -1;
	}

	$suggestionAmount = (float) (isset($suggestion['amount_open']) ? $suggestion['amount_open'] : 0.0);
	if ($suggestionAmount > 0.0000001) {
		return 1;
	}
	if ($suggestionAmount < -0.0000001) {
		return -1;
	}

	return 0;
};
$resolveLineBestSuggestion = static function ($line, $suggestions) use ($resolveLineDirection, $resolveSuggestionDirection, $bulkMatchMinScore) {
	$outstanding = max(0.0, abs((float) $line['amount']) - abs((float) $line['allocated_amount']));
	if ($outstanding <= 0.00001) {
		$outstanding = abs((float) $line['amount']);
	}
	if ($outstanding <= 0.00001) {
		return array(
			'suggestion' => null,
			'allocation' => 0.0,
			'outstanding' => 0.0,
		);
	}

	$lineDirection = $resolveLineDirection($line);
	$allowedDocTypes = array(
		'native_bank' => 1,
		'payment' => 1,
		'payment_supplier' => 1,
		'payment_linked' => 1,
		'payment_supplier_linked' => 1,
		'customer_invoice' => 1,
		'supplier_invoice' => 1,
	);

	$firstValidCandidate = null;
	$firstSafeCandidate = null;

	foreach ((array) $suggestions as $suggestion) {
		$docType = (string) (isset($suggestion['doc_type']) ? $suggestion['doc_type'] : '');
		if (!isset($allowedDocTypes[$docType])) {
			continue;
		}
		$matchScore = (int) (isset($suggestion['score']) ? $suggestion['score'] : 0);
		if ($matchScore <= 0) {
			continue;
		}

		$suggestionDirection = $resolveSuggestionDirection($suggestion);
		if ($lineDirection !== 0 && $suggestionDirection !== 0 && $lineDirection !== $suggestionDirection) {
			continue;
		}

		$amountOpen = abs((float) (isset($suggestion['amount_open']) ? $suggestion['amount_open'] : 0.0));
		if ($amountOpen <= 0.00001) {
			continue;
		}

		$allocation = min($outstanding, $amountOpen);
		if ($allocation <= 0.00001) {
			continue;
		}
		if (abs($allocation - $outstanding) > 0.01) {
			continue;
		}

		$candidate = array(
			'suggestion' => $suggestion,
			'allocation' => $allocation,
			'outstanding' => $outstanding,
			'is_bulk_safe' => ($matchScore >= $bulkMatchMinScore),
		);
		if ($firstValidCandidate === null) {
			$firstValidCandidate = $candidate;
		}
		if (!empty($candidate['is_bulk_safe'])) {
			$firstSafeCandidate = $candidate;
			break;
		}
	}

	if (is_array($firstSafeCandidate)) {
		return $firstSafeCandidate;
	}
	if (is_array($firstValidCandidate)) {
		return $firstValidCandidate;
	}

	return array(
		'suggestion' => null,
		'allocation' => 0.0,
		'outstanding' => $outstanding,
		'is_bulk_safe' => false,
	);
};
$buildLinkedFallbackSuggestions = static function ($service, $line, $dateTolerance) use ($resolveLineDirection) {
	if (empty($line) || !is_array($line)) {
		return array();
	}

	$outstanding = max(0.0, abs((float) $line['amount']) - abs((float) (!empty($line['allocated_amount']) ? $line['allocated_amount'] : 0.0)));
	if ($outstanding <= 0.00001) {
		$outstanding = abs((float) $line['amount']);
	}
	if ($outstanding <= 0.00001) {
		return array();
	}

	$lineDate = !empty($line['operation_date']) ? (string) $line['operation_date'] : (!empty($line['value_date']) ? (string) $line['value_date'] : '');
	$lineTimestamp = ($lineDate !== '' ? strtotime($lineDate) : false);
	try {
		$linkedDocuments = $service->getLinkedPaymentDocuments(0, 150, ($lineDate !== '' ? $lineDate : null), 365, $outstanding, (int) (!empty($line['bank_account_id']) ? $line['bank_account_id'] : 0), false);
	} catch (Exception $e) {
		return array();
	}

	$fallbackSuggestions = array();
	foreach ((array) $linkedDocuments as $document) {
		if (!is_array($document)) {
			continue;
		}
		$docType = (string) (!empty($document['doc_type']) ? $document['doc_type'] : '');
		$docId = (int) (!empty($document['rowid']) ? $document['rowid'] : 0);
		if ($docId <= 0 || $docType === '') {
			continue;
		}
		if (!in_array($docType, array('payment_linked', 'payment_supplier_linked'), true)) {
			continue;
		}
		$docAmountAbs = abs((float) (isset($document['amount_open']) ? $document['amount_open'] : 0.0));
		if ($docAmountAbs <= 0.00001 || abs($docAmountAbs - $outstanding) > 0.01) {
			continue;
		}

		$details = array('amount');
		$score = 70;
		$dateForProximity = !empty($document['linked_bank_date']) ? (string) $document['linked_bank_date'] : (string) (!empty($document['doc_date']) ? $document['doc_date'] : '');
		if ($lineTimestamp !== false) {
			$proximityTimestamp = ($dateForProximity !== '' ? strtotime($dateForProximity) : false);
			if ($proximityTimestamp === false || $proximityTimestamp <= 0) {
				continue;
			}
			$distanceDays = (int) floor(abs($lineTimestamp - $proximityTimestamp) / 86400);
			if ($distanceDays > (int) $dateTolerance) {
				continue;
			}
			$score += 30;
			$details[] = 'date';
		}

		$docRef = trim((string) (!empty($document['ref']) ? $document['ref'] : ''));
		if ($docRef === '') {
			$docRef = '#' . $docId;
		}

		$fallbackSuggestions[] = array(
			'doc_type' => $docType,
			'doc_id' => $docId,
			'doc_ref' => $docRef,
			'doc_date' => (string) (!empty($document['doc_date']) ? $document['doc_date'] : ''),
			'thirdparty_name' => (string) (!empty($document['thirdparty_name']) ? $document['thirdparty_name'] : ''),
			'amount_open' => (float) (isset($document['amount_open']) ? $document['amount_open'] : 0.0),
			'score' => $score,
			'details' => $details,
			'strategy_level' => 1,
		);
	}

	return $fallbackSuggestions;
};
$appendLinkedFallbackSuggestions = static function ($service, $line, $suggestions, $dateTolerance) use ($buildLinkedFallbackSuggestions) {
	$baseSuggestions = array_values(is_array($suggestions) ? $suggestions : array());
	$fallbackSuggestions = $buildLinkedFallbackSuggestions($service, $line, $dateTolerance);
	if (empty($fallbackSuggestions)) {
		return $baseSuggestions;
	}

	$seen = array();
	foreach ($baseSuggestions as $suggestion) {
		$docType = (string) (isset($suggestion['doc_type']) ? $suggestion['doc_type'] : '');
		$docId = (int) (isset($suggestion['doc_id']) ? $suggestion['doc_id'] : 0);
		if ($docType !== '' && $docId > 0) {
			$seen[$docType . '__' . $docId] = 1;
		}
	}
	foreach ($fallbackSuggestions as $suggestion) {
		$docType = (string) (isset($suggestion['doc_type']) ? $suggestion['doc_type'] : '');
		$docId = (int) (isset($suggestion['doc_id']) ? $suggestion['doc_id'] : 0);
		if ($docType === '' || $docId <= 0) {
			continue;
		}
		$key = $docType . '__' . $docId;
		if (isset($seen[$key])) {
			continue;
		}
		$baseSuggestions[] = $suggestion;
		$seen[$key] = 1;
	}

	return $baseSuggestions;
};
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

if ($action === 'bulk_match_confirm' && $user->hasRight('kreabank', 'reconciliation', 'write') && $canNativeWrite) {
	$enforcePostActionWithToken();
	$selectedLineIds = GETPOST('selected_line_ids', 'array');
	$lineIds = array();
	if (is_array($selectedLineIds)) {
		foreach ($selectedLineIds as $selectedLineId) {
			$selectedLineId = (int) $selectedLineId;
			if ($selectedLineId > 0) {
				$lineIds[$selectedLineId] = $selectedLineId;
			}
		}
	}

	if (empty($lineIds)) {
		setEventMessages($langs->trans('KreaBankNoLinesSelected'), null, 'warnings');
	} else {
		$done = 0;
		$noMatch = 0;
		$lowConfidence = 0;
		$failed = 0;
		$errors = array();
		$linesById = array();

		foreach ($lineIds as $lineId) {
			try {
				$line = $service->getLineById((int) $lineId);
				if (!empty($line) && is_array($line)) {
					$linesById[(int) $lineId] = $line;
				}
			} catch (Exception $e) {
				// Keep per-line resilience; detailed error is reported in reconcile attempt below.
			}
		}
		$suggestionsByLine = array();
		try {
			$suggestionsByLine = $service->getSuggestionsForLines(array_values($linesById), 0, $dateTolerance, null, false);
		} catch (Exception $e) {
			// Fallback to per-line suggestion loading in the loop below.
		}

		foreach ($lineIds as $lineId) {
			try {
				$line = !empty($linesById[(int) $lineId]) ? $linesById[(int) $lineId] : $service->getLineById((int) $lineId);
				if (empty($line) || (int) $line['status'] !== 0) {
					$noMatch++;
					continue;
				}

				$suggestions = !empty($suggestionsByLine[(int) $lineId]) ? (array) $suggestionsByLine[(int) $lineId] : $service->getSuggestionsForLine((int) $lineId, 0, $dateTolerance, null, false);
				$suggestions = $appendLinkedFallbackSuggestions($service, $line, $suggestions, $dateTolerance);
				$resolved = $resolveLineBestSuggestion($line, $suggestions);
				$suggestion = !empty($resolved['suggestion']) ? (array) $resolved['suggestion'] : null;
				$allocation = (float) $resolved['allocation'];
				if (!$suggestion || $allocation <= 0.00001) {
					$noMatch++;
					continue;
				}
				if (empty($resolved['is_bulk_safe'])) {
					$lowConfidence++;
					continue;
				}

				$score = (int) (isset($suggestion['score']) ? $suggestion['score'] : 0);
				$details = (array) (isset($suggestion['details']) ? $suggestion['details'] : array());
				$service->reconcileLine(
					(int) $lineId,
					array(array(
						'doc_type' => (string) $suggestion['doc_type'],
						'fk_doc' => (int) $suggestion['doc_id'],
						'doc_ref' => (string) $suggestion['doc_ref'],
						'allocated_amount' => $allocation,
						'match_score' => $score,
						'match_reasons' => $details,
					)),
					'bulk_match',
					0,
					'Bulk match confirmed by user',
					$score
				);
				$done++;
			} catch (Exception $e) {
				$failed++;
				$errors[] = $e->getMessage();
			}
		}

		if ($done > 0) {
			setEventMessages($langs->trans('KreaBankBulkMatchDone', (string) $done, (string) count($lineIds)), null, 'mesgs');
		}
		if ($noMatch > 0) {
			setEventMessages($langs->trans('KreaBankBulkMatchNoMatchSkipped', (string) $noMatch), null, 'warnings');
		}
		if ($lowConfidence > 0) {
			setEventMessages($langs->trans('KreaBankBulkMatchLowConfidenceSkipped', (string) $lowConfidence), null, 'warnings');
		}
		if ($failed > 0) {
			$errorList = array_slice(array_values(array_unique($errors)), 0, 5);
			setEventMessages($langs->trans('KreaBankBulkMatchFailed', (string) $failed), $errorList, 'errors');
		}
	}

	// Bulk confirmation can change pending/matchable lines; force cache refresh.
	unset($_SESSION[$bulkScanCacheStoreKey]);
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
	$pendingTotal = (int) $service->getPendingLinesCount();
	$pendingFingerprintPayload = array(
		'pending_total' => $pendingTotal,
		'entity' => (int) $conf->entity,
		'filter_scope' => $bulkScanFilterScope,
	);
	if ($pendingTotal > 0) {
		$firstPending = $service->getPendingLines(1, 0, 'b.rowid', 'ASC');
		$lastPending = $service->getPendingLines(1, 0, 'b.rowid', 'DESC');
		$headSample = $service->getPendingLines(min(10, $pendingTotal), 0, 'b.rowid', 'ASC');
		$tailSample = $service->getPendingLines(min(10, $pendingTotal), 0, 'b.rowid', 'DESC');
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

	$requestStart = microtime(true);
	if (empty($scanState['done'])) {
		for (;;) {
			$elapsedMs = (microtime(true) - $requestStart) * 1000.0;
			if ($elapsedMs >= (float) $scanBudgetMs) {
				break;
			}

			$scanOffset = (int) (!empty($scanState['next_offset']) ? $scanState['next_offset'] : 0);
			if ($scanOffset >= $pendingTotal) {
				$scanState['done'] = true;
				break;
			}

			$pendingChunk = $service->getPendingLines($scanChunk, $scanOffset, 'b.dateo', 'ASC');
			if (empty($pendingChunk)) {
				$scanState['done'] = true;
				break;
			}
			$suggestionsByLine = $service->getSuggestionsForLines($pendingChunk, 0, $dateTolerance, null, false);

			foreach ($pendingChunk as $line) {
				$lineId = (int) $line['rowid'];
				$suggestions = !empty($suggestionsByLine[$lineId]) ? (array) $suggestionsByLine[$lineId] : array();
				$suggestions = $appendLinkedFallbackSuggestions($service, $line, $suggestions, $dateTolerance);
				$resolved = $resolveLineBestSuggestion($line, $suggestions);
				$suggestion = !empty($resolved['suggestion']) ? (array) $resolved['suggestion'] : null;
				if (!$suggestion) {
					continue;
				}

				$scanState['matched_rows'][] = array(
					'line_id' => (int) (!empty($line['rowid']) ? $line['rowid'] : 0),
					'doc_type' => (string) (!empty($suggestion['doc_type']) ? $suggestion['doc_type'] : ''),
					'doc_id' => (int) (!empty($suggestion['doc_id']) ? $suggestion['doc_id'] : 0),
					'score' => (int) (!empty($suggestion['score']) ? $suggestion['score'] : 0),
					'allocation' => (float) $resolved['allocation'],
				);
			}

			$scanState['next_offset'] = $scanOffset + count($pendingChunk);
			if (count($scanState['matched_rows']) > $bulkScanMaxRows) {
				$scanState['matched_rows'] = array_slice($scanState['matched_rows'], count($scanState['matched_rows']) - $bulkScanMaxRows);
			}

			if (count($pendingChunk) < $scanChunk || (int) $scanState['next_offset'] >= $pendingTotal) {
				$scanState['done'] = true;
				break;
			}
		}
	}
	if ((int) (!empty($scanState['next_offset']) ? $scanState['next_offset'] : 0) >= $pendingTotal) {
		$scanState['done'] = true;
	}
	$scanState['scope'] = $bulkScanCacheScope;
	$scanState['fingerprint'] = $pendingFingerprint;
	$scanState['pending_total'] = (int) $pendingTotal;
	if (empty($scanState['done'])) {
		$scanState['reload_count'] = max(0, (int) (!empty($scanState['reload_count']) ? $scanState['reload_count'] : 0)) + 1;
	} else {
		$scanState['reload_count'] = 0;
	}
	$scanState['tms'] = dol_now();
	$_SESSION[$bulkScanCacheStoreKey] = $scanState;

	$matchedRows = (!empty($scanState['matched_rows']) && is_array($scanState['matched_rows'])) ? $scanState['matched_rows'] : array();
	$scanScannedCount = min($pendingTotal, (int) (!empty($scanState['next_offset']) ? $scanState['next_offset'] : 0));
	$scanInProgress = empty($scanState['done']);
	$scanAutoReloadCount = max(0, (int) (!empty($scanState['reload_count']) ? $scanState['reload_count'] : 0));
	$scanAutoReloadEnabled = ($scanInProgress && $scanAutoReloadCount <= $bulkAutoReloadMax);
	$scanProgressPct = ($pendingTotal > 0 ? (int) floor(($scanScannedCount * 100) / $pendingTotal) : 100);

	$matchedCount = count($matchedRows);
	if ($page > 0 && $offset >= $matchedCount) {
		$page = 0;
		$offset = 0;
	}
	$rows = array_slice($matchedRows, $offset, $limit);
	$currentPageCount = count($rows);
	$bulkPagerNum = $currentPageCount;
	if ($limit > 0 && ($offset + $currentPageCount) < $matchedCount) {
		// Dolibarr pager shows next navigation when num > limit.
		$bulkPagerNum = $limit + 1;
	}
	$rows = array();
	$preselectedCount = 0;
	$pageMatchedRows = array_slice($matchedRows, $offset, $limit);
	if (!empty($pageMatchedRows)) {
		$pageLinesById = array();
		foreach ($pageMatchedRows as $matchedRow) {
			if (!is_array($matchedRow)) {
				continue;
			}
			$lineId = (int) (!empty($matchedRow['line_id']) ? $matchedRow['line_id'] : 0);
			if ($lineId <= 0 || isset($pageLinesById[$lineId])) {
				continue;
			}
			try {
				$line = $service->getLineById($lineId);
				if (!empty($line) && is_array($line) && (int) (!empty($line['status']) ? $line['status'] : 0) === 0) {
					$pageLinesById[$lineId] = $line;
				}
			} catch (Exception $e) {
				// Keep resilient rendering; stale line will be skipped.
			}
		}
		$pageSuggestionsByLine = array();
		if (!empty($pageLinesById)) {
			try {
				$pageSuggestionsByLine = $service->getSuggestionsForLines(array_values($pageLinesById), 0, $dateTolerance, null, false);
			} catch (Exception $e) {
				$pageSuggestionsByLine = array();
			}
		}
		$findSuggestionByDoc = static function ($suggestions, $docType, $docId) {
			$docType = (string) $docType;
			$docId = (int) $docId;
			foreach ((array) $suggestions as $suggestion) {
				if (!is_array($suggestion)) {
					continue;
				}
				if ((string) (!empty($suggestion['doc_type']) ? $suggestion['doc_type'] : '') !== $docType) {
					continue;
				}
				if ((int) (!empty($suggestion['doc_id']) ? $suggestion['doc_id'] : 0) !== $docId) {
					continue;
				}

				return $suggestion;
			}

			return null;
		};

		foreach ($pageMatchedRows as $matchedRow) {
			if (!is_array($matchedRow)) {
				continue;
			}
			$lineId = (int) (!empty($matchedRow['line_id']) ? $matchedRow['line_id'] : 0);
			$docType = (string) (!empty($matchedRow['doc_type']) ? $matchedRow['doc_type'] : '');
			$docId = (int) (!empty($matchedRow['doc_id']) ? $matchedRow['doc_id'] : 0);
			$cachedScore = (int) (!empty($matchedRow['score']) ? $matchedRow['score'] : 0);
			$cachedAllocation = (float) (!empty($matchedRow['allocation']) ? $matchedRow['allocation'] : 0.0);
			if ($lineId <= 0 || $docType === '' || $docId <= 0 || empty($pageLinesById[$lineId])) {
				continue;
			}
			$line = $pageLinesById[$lineId];
			$suggestions = !empty($pageSuggestionsByLine[$lineId]) ? (array) $pageSuggestionsByLine[$lineId] : array();
			$suggestions = $appendLinkedFallbackSuggestions($service, $line, $suggestions, $dateTolerance);
			$suggestion = $findSuggestionByDoc($suggestions, $docType, $docId);
			if (!is_array($suggestion)) {
				$suggestion = array(
					'doc_type' => $docType,
					'doc_id' => $docId,
					'doc_ref' => '#' . $docId,
					'doc_date' => '',
					'thirdparty_name' => '',
					'amount_open' => $cachedAllocation,
					'score' => $cachedScore,
					'details' => array(),
				);
			}
			$suggestionScore = (int) (!empty($suggestion['score']) ? $suggestion['score'] : $cachedScore);
			$isBulkSafe = ($suggestionScore >= $bulkMatchMinScore);
			if ($isBulkSafe) {
				$preselectedCount++;
			}
			$rows[] = array(
				'line' => $line,
				'suggestion' => $suggestion,
				'allocation' => $cachedAllocation,
				'preselected' => $isBulkSafe,
				'is_bulk_safe' => $isBulkSafe,
				'score' => $suggestionScore,
			);
		}
	}
} catch (Exception $e) {
	setEventMessages($e->getMessage(), null, 'errors');
}

$param = '&mainmenu=bank&leftmenu=kreabank_bulkmatch';
if ($limit > 0 && $limit !== (int) $conf->liste_limit) {
	$param .= '&limit=' . $limit;
}

llxHeader('', $langs->trans('KreaBankBulkMatch'), '', '', 0, 0, '', '', '', 'mod-kreabank page-bulkmatch bodyforlist');

print load_fiche_titre($langs->trans('KreaBankBulkMatchTitle'), '', 'title_setup');
$head = kreabankPrepareHead();
print dol_get_fiche_head($head, 'bulkmatch', $langs->trans('KreaBankBulkMatch'), -1, 'bank');

print '<style>';
print '.krea-bulk-intro{margin:8px 0 14px 0;padding:10px 12px;border:1px solid #d5e0ea;background:#f8fbfe;border-radius:8px}';
print '.krea-bulk-intro-title{margin:0;font-size:18px;font-weight:800;color:#204060}';
print '.krea-bulk-intro-subtitle{margin:6px 0 0 0;color:#486174;font-size:13px}';
print '.krea-bulk-score{display:inline-block;padding:2px 6px;border-radius:10px;font-size:11px;font-weight:700;background:#eef6ff;color:#1f4e79;border:1px solid #c3d9ef}';
print '.krea-bulk-summary{display:flex;gap:10px;flex-wrap:wrap;margin:8px 0 12px 0}';
print '.krea-bulk-summary .badge{font-size:12px}';
print '.krea-bulk-progress{margin:0 0 12px 0;padding:10px 12px;border:1px solid #d8e4ef;background:#f5f9fc;border-radius:8px}';
print '.krea-bulk-progress-bar{height:8px;background:#dbe6f2;border-radius:999px;overflow:hidden;margin:6px 0 0 0}';
print '.krea-bulk-progress-bar > span{display:block;height:100%;background:#2f6f9f}';
print '.krea-bulk-warning{margin:0 0 12px 0;padding:10px 12px;border:1px solid #f0d7a5;background:#fff8e8;border-radius:8px;color:#664d03}';
print '.krea-bulk-actions{display:flex;justify-content:flex-end;margin-top:12px}';
print '.krea-bulk-select-all{margin:0 0 8px 0;display:flex;align-items:center;gap:6px}';
print '.krea-bulk-list{display:flex;flex-direction:column;gap:10px}';
print '.krea-bulk-row{display:flex;gap:10px;align-items:stretch;border:1px solid #d8e1ea;border-radius:8px;background:#fff;padding:10px}';
print '.krea-bulk-row-check{display:flex;align-items:flex-start;padding-top:6px}';
print '.krea-bulk-row-panels{flex:1;display:grid;grid-template-columns:minmax(220px,1fr) minmax(220px,1fr) minmax(160px,.5fr);gap:10px}';
print '.krea-bulk-panel{width:100%;border:1px solid #dbe4ec;border-radius:6px;overflow:hidden;background:#fbfdff;border-collapse:collapse}';
print '.krea-bulk-panel th{padding:6px 8px;background:#f2f6fa;color:#2b4a63;font-size:11px;text-transform:uppercase;letter-spacing:.2px;text-align:left}';
print '.krea-bulk-panel td{padding:6px 8px;border-top:1px solid #e7edf3;vertical-align:top}';
print '.krea-bulk-panel .krea-label{color:#6f8090;white-space:nowrap;width:95px}';
print '.krea-dir-badge{display:inline-block;padding:2px 7px;border-radius:10px;font-size:10px;font-weight:700;line-height:1.35;margin-left:6px;vertical-align:middle}';
print '.krea-dir-credit{background:#e8f7ef;color:#1e7f3f;border:1px solid #bfe6ce}';
print '.krea-dir-debit{background:#fdeeee;color:#a02f2f;border:1px solid #f2c1c1}';
print '.krea-payment-badge{display:inline-block;padding:2px 7px;border-radius:10px;font-size:10px;font-weight:700;line-height:1.35;margin-left:6px;vertical-align:middle;background:#eef3ff;color:#2f4aa0;border:1px solid #c8d4ff;text-decoration:none}';
print '.krea-payment-badge:hover{text-decoration:none;filter:brightness(.98)}';
print '.krea-bulk-low-score{display:inline-block;padding:2px 6px;border-radius:10px;font-size:11px;font-weight:700;background:#fff3cd;color:#7a5b00;border:1px solid #f2d890;margin-left:6px}';
print '@media (max-width: 1200px){.krea-bulk-row-panels{grid-template-columns:1fr}}';
print '</style>';

print '<div class="krea-bulk-intro">';
print '<h3 class="krea-bulk-intro-title">' . $langs->trans('KreaBankBulkMatchTitle') . '</h3>';
print '<p class="krea-bulk-intro-subtitle">' . $langs->trans('KreaBankBulkMatchSubtitle') . '</p>';
print '</div>';

print '<div class="krea-bulk-summary">';
print '<span class="badge badge-status1">' . $langs->trans('KreaBankPendingCount') . ': ' . ((int) $pendingTotal) . '</span>';
print '<span class="badge badge-status4">' . $langs->trans('KreaBankBulkMatchWithSuggestion') . ': ' . ((int) $matchedCount) . '</span>';
print '<span class="badge badge-status2">' . $langs->trans('KreaBankBulkMatchPreselected') . ': ' . ((int) $preselectedCount) . '</span>';
print '</div>';
if ($scanInProgress) {
	print '<div class="krea-bulk-progress">';
	print '<strong>Bulk scan in progress:</strong> ' . ((int) $scanScannedCount) . ' / ' . ((int) $pendingTotal) . ' lines scanned (' . ((int) $scanProgressPct) . '%).';
	print '<div class="krea-bulk-progress-bar"><span style="width:' . ((int) $scanProgressPct) . '%"></span></div>';
	print '</div>';
	if (!$scanAutoReloadEnabled) {
		print '<div class="krea-bulk-warning">' . $langs->trans('KreaBankBulkMatchRefreshPaused', (string) $scanAutoReloadCount, (string) $bulkAutoReloadMax) . ' <a class="butActionRefused" href="' . dol_escape_htmltag($_SERVER['REQUEST_URI']) . '" style="float:right">' . $langs->trans('Refresh') . '</a></div>';
	}
}

print '<form method="GET" id="kreaBulkMatchPagerForm" action="' . dol_escape_htmltag($_SERVER['PHP_SELF']) . '">';
print '<input type="hidden" name="mainmenu" value="bank">';
print '<input type="hidden" name="leftmenu" value="kreabank_bulkmatch">';
print_barre_liste($langs->trans('KreaBankBulkMatchWithSuggestion'), $page, $_SERVER['PHP_SELF'], $param, '', '', '', $bulkPagerNum, $matchedCount, 'title_setup', 0, '', '', $limit, 0, 0, 1);
print '</form>';

if (empty($rows)) {
	print '<div class="opacitymedium">' . $langs->trans('KreaBankNoStatementLines') . '</div>';
} else {
	print '<form method="POST" action="' . dol_escape_htmltag($_SERVER['PHP_SELF']) . '" id="kreaBulkMatchForm">';
	print '<input type="hidden" name="token" value="' . newToken() . '">';
	print '<input type="hidden" name="action" value="bulk_match_confirm">';
	print '<input type="hidden" name="mainmenu" value="bank">';
	print '<input type="hidden" name="leftmenu" value="kreabank_bulkmatch">';
	print '<input type="hidden" name="page" value="' . ((int) $page) . '">';
	print '<input type="hidden" name="limit" value="' . ((int) $limit) . '">';
	print '<label class="krea-bulk-select-all"><input type="checkbox" id="krea_bulk_match_all" checked> ' . $langs->trans('KreaBankSelectAll') . '</label>';
	print '<div class="krea-bulk-list">';
	$normalizeCompact = static function ($value) {
		$value = dol_string_nospecial((string) $value);
		$value = strtoupper((string) preg_replace('/[^A-Z0-9]/', '', (string) $value));

		return trim((string) $value);
	};
	$formatDisplayDate = static function ($value) {
		$value = trim((string) $value);
		if ($value === '') {
			return '<span class="opacitymedium">-</span>';
		}
		$ts = strtotime($value);
		if ($ts === false || $ts <= 0) {
			return '<span class="opacitymedium">-</span>';
		}

		return dol_print_date($ts, 'day');
	};
	$hasDegemaModule = function_exists('isModEnabled') && isModEnabled('degema');

	foreach ($rows as $row) {
		$line = (array) $row['line'];
		$suggestion = !empty($row['suggestion']) ? (array) $row['suggestion'] : null;
		$lineId = (int) $line['rowid'];
		$lineDate = !empty($line['operation_date']) ? (string) $line['operation_date'] : (!empty($line['value_date']) ? (string) $line['value_date'] : '');
		$isPreselected = !empty($row['preselected']);
		$isBulkSafe = !empty($row['is_bulk_safe']);
		$lineDirection = (int) (!empty($line['direction']) ? $line['direction'] : 0);
		if ($lineDirection === 0) {
			$lineAmountRaw = (float) (!empty($line['amount']) ? $line['amount'] : 0.0);
			if ($lineAmountRaw > 0.0000001) {
				$lineDirection = 1;
			} elseif ($lineAmountRaw < -0.0000001) {
				$lineDirection = -1;
			}
		}
		$lineDirectionClass = '';
		$lineDirectionLabel = '';
		if ($lineDirection > 0) {
			$lineDirectionClass = 'krea-dir-credit';
			$lineDirectionLabel = $langs->trans('Credit');
		} elseif ($lineDirection < 0) {
			$lineDirectionClass = 'krea-dir-debit';
			$lineDirectionLabel = $langs->trans('Debit');
		}

		print '<div class="krea-bulk-row">';
		print '<div class="krea-bulk-row-check"><input type="checkbox" class="krea-bulk-match-line" name="selected_line_ids[]" value="' . $lineId . '"' . ($isPreselected ? ' checked' : '') . '></div>';
		print '<div class="krea-bulk-row-panels">';
		$counterpartyValue = trim((string) (!empty($line['counterparty_name']) ? $line['counterparty_name'] : ''));
		if ($counterpartyValue === '') {
			$counterpartyValue = trim((string) (!empty($line['description']) ? $line['description'] : ''));
		}
		$counterpartyExtra = trim((string) (!empty($line['counterparty_iban']) ? $line['counterparty_iban'] : ''));
		if ($counterpartyExtra !== '') {
			$counterpartyMainNormalized = $normalizeCompact($counterpartyValue);
			$counterpartyExtraNormalized = $normalizeCompact($counterpartyExtra);
			$looksLikeIban = (bool) preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}$/', $counterpartyExtraNormalized);
			$isDuplicateText = (
				$counterpartyExtraNormalized === ''
				|| $counterpartyExtraNormalized === $counterpartyMainNormalized
				|| ($counterpartyMainNormalized !== '' && strpos($counterpartyMainNormalized, $counterpartyExtraNormalized) !== false)
				|| ($counterpartyExtraNormalized !== '' && $counterpartyMainNormalized !== '' && strpos($counterpartyExtraNormalized, $counterpartyMainNormalized) !== false)
			);
			if (!$looksLikeIban || $isDuplicateText) {
				$counterpartyExtra = '';
			}
		}

		print '<table class="krea-bulk-panel">';
		print '<tr><th colspan="2">' . $langs->trans('KreaBankPendingStatementLines') . ($lineDirectionLabel !== '' ? '<span class="krea-dir-badge ' . $lineDirectionClass . '">' . dol_escape_htmltag((string) $lineDirectionLabel) . '</span>' : '') . '</th></tr>';
		print '<tr><td class="krea-label">' . $langs->trans('KreaBankDate') . '</td><td class="nowrap">' . $formatDisplayDate($lineDate) . '</td></tr>';
		print '<tr><td class="krea-label">' . $langs->trans('KreaBankCounterparty') . '</td><td><strong>' . dol_escape_htmltag($counterpartyValue) . '</strong>' . ($counterpartyExtra !== '' ? '<br><span class="opacitymedium">' . dol_escape_htmltag($counterpartyExtra) . '</span>' : '') . '</td></tr>';
		print '<tr><td class="krea-label">' . $langs->trans('KreaBankAmount') . '</td><td class="right nowrap"><strong>' . price((float) $line['amount']) . ' ' . dol_escape_htmltag((string) $line['currency']) . '</strong></td></tr>';
		print '</table>';

		$docType = (string) (!empty($suggestion['doc_type']) ? $suggestion['doc_type'] : '');
		$docId = (int) $suggestion['doc_id'];
		$docUrl = $resolveSuggestionCardUrl($docType, $docId);
		$docRef = (string) (!empty($suggestion['doc_ref']) ? $suggestion['doc_ref'] : ('#' . $docId));
		$docThirdparty = trim((string) (!empty($suggestion['thirdparty_name']) ? $suggestion['thirdparty_name'] : ''));
		if ($docThirdparty === '') {
			$docThirdparty = $docRef;
		}
		$docDirection = $resolveSuggestionDirection($suggestion);
		$docDirectionClass = '';
		$docDirectionLabel = '';
		if ($docDirection > 0) {
			$docDirectionClass = 'krea-dir-credit';
			$docDirectionLabel = $langs->trans('Credit');
		} elseif ($docDirection < 0) {
			$docDirectionClass = 'krea-dir-debit';
			$docDirectionLabel = $langs->trans('Debit');
		}
		$docDate = $formatDisplayDate((string) (!empty($suggestion['doc_date']) ? $suggestion['doc_date'] : ''));
		$docAmount = (float) (isset($suggestion['amount_open']) ? $suggestion['amount_open'] : 0.0);
		$supplierPaymentId = (int) (isset($suggestion['supplier_payment_id']) ? $suggestion['supplier_payment_id'] : 0);
		$supplierPaymentCardUrl = ($hasDegemaModule && $supplierPaymentId > 0 ? dol_buildpath('/custom/degema/paiement_card.php?id=' . $supplierPaymentId, 1) : '');
		$paymentBadgeHtml = '';
		if ($supplierPaymentCardUrl !== '') {
			$paymentBadgeHtml = '<a class="krea-payment-badge" href="' . $supplierPaymentCardUrl . '" target="_blank" rel="noopener">' . dol_escape_htmltag($langs->trans('KreaBankPaymentBadge') . ' #' . $supplierPaymentId) . '</a>';
		}
		print '<table class="krea-bulk-panel">';
		print '<tr><th colspan="2">' . $langs->trans('KreaBankBulkMatchSuggested') . ($docDirectionLabel !== '' ? '<span class="krea-dir-badge ' . $docDirectionClass . '">' . dol_escape_htmltag((string) $docDirectionLabel) . '</span>' : '') . $paymentBadgeHtml . '</th></tr>';
		print '<tr><td class="krea-label">' . $langs->trans('KreaBankDate') . '</td><td class="nowrap">' . $docDate . '</td></tr>';
		print '<tr><td class="krea-label">' . $langs->trans('KreaBankCounterparty') . '</td><td><strong>' . dol_escape_htmltag($docThirdparty) . '</strong></td></tr>';
		print '<tr><td class="krea-label">' . $langs->trans('KreaBankAmount') . '</td><td class="right nowrap">';
		if ($docUrl !== '') {
			print '<a href="' . $docUrl . '" target="_blank" rel="noopener"><strong>' . price($docAmount) . ' ' . dol_escape_htmltag((string) $line['currency']) . '</strong></a>';
		} else {
			print '<strong>' . price($docAmount) . ' ' . dol_escape_htmltag((string) $line['currency']) . '</strong>';
		}
		print '</td></tr>';
		print '</table>';

		print '<table class="krea-bulk-panel">';
		print '<tr><th colspan="2">' . $langs->trans('KreaBankMatchProbability') . '</th></tr>';
		print '<tr><td class="krea-label">' . $langs->trans('KreaBankBulkMatchScore') . '</td><td><span class="krea-bulk-score">' . ((int) $suggestion['score']) . '</span>' . (!$isBulkSafe ? '<span class="krea-bulk-low-score">' . $langs->trans('KreaBankBulkMatchLowConfidence') . '</span>' : '') . '</td></tr>';
		print '<tr><td class="krea-label">' . $langs->trans('KreaBankBulkMatchReasons') . '</td><td>' . dol_escape_htmltag(implode(', ', (array) $suggestion['details'])) . '</td></tr>';
		print '</table>';

		print '</div>';
		print '</div>';
	}

	print '</div>';
	if ($user->hasRight('kreabank', 'reconciliation', 'write') && $canNativeWrite) {
		print '<div class="krea-bulk-actions">';
		print '<button type="submit" class="butAction">' . $langs->trans('KreaBankBulkMatchConfirm') . '</button>';
		print '</div>';
	}
	print '</form>';
}

print '<script>';
print 'var bulkToggle = document.getElementById("krea_bulk_match_all");';
print 'var lineSelectors = document.querySelectorAll(".krea-bulk-match-line");';
print 'if (bulkToggle && lineSelectors.length) {';
print 'bulkToggle.addEventListener("change", function(){';
print 'lineSelectors.forEach(function(selector){ selector.checked = bulkToggle.checked; });';
print '});';
print 'lineSelectors.forEach(function(selector){';
print 'selector.addEventListener("change", function(){';
print 'if (!selector.checked) { bulkToggle.checked = false; return; }';
print 'var allChecked = true;';
print 'lineSelectors.forEach(function(innerSelector){ if (!innerSelector.checked) { allChecked = false; } });';
print 'bulkToggle.checked = allChecked;';
print '});';
print '});';
print '}';
if ($scanInProgress) {
	if ($scanAutoReloadEnabled) {
		print 'window.setTimeout(function(){ window.location.reload(); }, 900);';
	}
}
print '</script>';

print dol_get_fiche_end();
llxFooter();
$db->close();
