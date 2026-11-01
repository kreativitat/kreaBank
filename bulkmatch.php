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

$navigationAccountId = max(0, GETPOSTISSET('account_context') ? GETPOSTINT('account_context') : GETPOSTINT('bank_account_id'));
$navigationAccount = kreabankNavigationAccount($navigationAccountId);
$navigationAccountKind = kreabankNavigationKind();
$navigationAccountIds = kreabankNavigationAccountIds($navigationAccountKind);
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
$offset = $page * $limit;
$dateTolerance = getDolGlobalInt('KREABANK_AUTOMATCH_DATE_TOLERANCE');
if ($dateTolerance <= 0) {
	$dateTolerance = 3;
}
$bulkScanCacheStoreKey = 'kreabank_bulkmatch_scan_state';
$bulkScanMatcherRevision = 14;
$bulkScanFilterScope = array(
	'bank_account_id' => $navigationAccountId,
	'account_kind' => $navigationAccountKind,
);
$bulkScanCacheScope = ((int) $conf->entity) . '|' . ((int) $user->id) . '|' . ((int) $dateTolerance) . '|' . ((int) $bulkScanMatcherRevision) . '|' . hash('sha256', json_encode($bulkScanFilterScope));
if (empty($_SESSION['kreabank_bulk_review_secret'])) {
	$_SESSION['kreabank_bulk_review_secret'] = bin2hex(random_bytes(32));
}
$bulkReviewSecret = $_SESSION['kreabank_bulk_review_secret'];
$bulkReviewScope = (int) $conf->entity . '|' . (int) $user->id . '|' . $navigationAccountId . '|' . $navigationAccountKind;
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
$resolveLineBestSuggestion = static function ($line, $suggestions) use ($service, $resolveLineDirection, $resolveSuggestionDirection, $bulkMatchMinScore) {
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

	$validCandidates = array();

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
			'is_bulk_safe' => false,
		);
		$validCandidates[] = $candidate;
	}

	if (!empty($validCandidates)) {
		usort($validCandidates, static function ($a, $b) {
			$suggestionA = (array) $a['suggestion'];
			$suggestionB = (array) $b['suggestion'];
			$scoreA = (int) (isset($suggestionA['score']) ? $suggestionA['score'] : 0);
			$scoreB = (int) (isset($suggestionB['score']) ? $suggestionB['score'] : 0);
			if ($scoreB !== $scoreA) {
				return $scoreB <=> $scoreA;
			}
			$amountA = abs((float) (isset($suggestionA['amount_open']) ? $suggestionA['amount_open'] : 0.0));
			$amountB = abs((float) (isset($suggestionB['amount_open']) ? $suggestionB['amount_open'] : 0.0));
			if (abs($amountA - $amountB) > 0.0000001) {
				return $amountA <=> $amountB;
			}
			$typeComparison = strcmp(
				(string) (isset($suggestionA['doc_type']) ? $suggestionA['doc_type'] : ''),
				(string) (isset($suggestionB['doc_type']) ? $suggestionB['doc_type'] : '')
			);
			if ($typeComparison !== 0) {
				return $typeComparison;
			}

			return ((int) (isset($suggestionA['doc_id']) ? $suggestionA['doc_id'] : 0)) <=> ((int) (isset($suggestionB['doc_id']) ? $suggestionB['doc_id'] : 0));
		});

		$orderedSuggestions = array();
		foreach ($validCandidates as $candidate) {
			$orderedSuggestions[] = (array) $candidate['suggestion'];
		}
		$safeSuggestion = $service->getSafeSuggestion($orderedSuggestions, $bulkMatchMinScore);
		if (is_array($safeSuggestion)) {
			$safeDocType = (string) (isset($safeSuggestion['doc_type']) ? $safeSuggestion['doc_type'] : '');
			$safeDocId = (int) (isset($safeSuggestion['doc_id']) ? $safeSuggestion['doc_id'] : 0);
			foreach ($validCandidates as $candidate) {
				$candidateSuggestion = (array) $candidate['suggestion'];
				if (
					(string) (isset($candidateSuggestion['doc_type']) ? $candidateSuggestion['doc_type'] : '') === $safeDocType
					&& (int) (isset($candidateSuggestion['doc_id']) ? $candidateSuggestion['doc_id'] : 0) === $safeDocId
				) {
					$candidate['is_bulk_safe'] = true;
					return $candidate;
				}
			}
		}

		if (kreabankBulkSuggestionIsUnambiguous($orderedSuggestions)) {
			return $validCandidates[0];
		}
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

if ($action === 'bulk_match_confirm') {
	if (!$user->hasRight('kreabank', 'reconciliation', 'write') || !$canNativeWrite) {
		accessforbidden();
	}
	$enforcePostActionWithToken();
	$selectedLineIds = GETPOST('selected_line_ids', 'array');
	$reviewSignatures = GETPOST('review_signatures', 'array');
	$reviewSources = GETPOST('review_sources', 'array');
	$reviewExpires = GETPOSTINT('review_expires');
	$reviewConfirmed = GETPOSTINT('review_confirmed') === 1;
	$lineIds = array();
	if (is_array($selectedLineIds)) {
		foreach ($selectedLineIds as $selectedLineId) {
			$selectedLineId = (int) $selectedLineId;
			if ($selectedLineId > 0) {
				$lineIds[$selectedLineId] = $selectedLineId;
			}
		}
	}

	if (!$reviewConfirmed || count($lineIds) > 100) {
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
					'bulk_manual',
					0,
					'Exact displayed pair reviewed and confirmed by user',
					$score,
					null,
					$reviewedRow
				);
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
	unset($_SESSION[$bulkScanCacheStoreKey]);
	header('Location: ' . dol_buildpath('/custom/kreabank/bulkmatch.php', 1) . '?account_context=' . $navigationAccountId . kreabankNavigationKindParam() . '&limit=' . $limit);
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

	$scanState['display_rows'] = !empty($scanState['display_rows']) && is_array($scanState['display_rows']) ? $scanState['display_rows'] : array();
	$scanState['generation'] = !empty($scanState['generation']) ? $scanState['generation'] : bin2hex(random_bytes(16));
	$originalGeneration = $scanState['generation'];
	$originalOffset = (int) $scanState['next_offset'];
	$originalReloadCount = (int) $scanState['reload_count'];
	if (($scanState['display_page'] ?? -1) !== $page || ($scanState['display_limit'] ?? 0) !== $limit) {
		$scanState['display_rows'] = array();
		$scanState['reload_count'] = 0;
		$scanState['generation'] = bin2hex(random_bytes(16));
	}
	$scanState['display_page'] = $page;
	$scanState['display_limit'] = $limit;
	if ($isScanRequest) {
		$sessionIdentity = array($_SESSION['dol_login'] ?? '', $_SESSION['dol_entity'] ?? 0);
		// Matching must not block navigation, other tabs, or company switching.
		session_write_close();
		if (GETPOSTINT('resume') === 1) {
			$scanState['reload_count'] = 0;
		}
		$prepareReviewRow = static function ($line) use ($service, $dateTolerance, $appendLinkedFallbackSuggestions, $resolveLineBestSuggestion) {
			$lineId = (int) $line['rowid'];
			$suggestionsByLine = $service->getSuggestionsForLines(array($line), 0, $dateTolerance, null, false);
			$suggestions = $appendLinkedFallbackSuggestions($service, $line, $suggestionsByLine[$lineId] ?? array(), $dateTolerance);
			$resolved = $resolveLineBestSuggestion($line, $suggestions);
			if (empty($resolved['suggestion'])) {
				return null;
			}
			return array('line' => $line, 'suggestion' => $resolved['suggestion'], 'allocation' => (float) $resolved['allocation'],
				'preselected' => !empty($resolved['is_bulk_safe']), 'is_bulk_safe' => !empty($resolved['is_bulk_safe']),
				'score' => (int) $resolved['suggestion']['score']);
		};
		$scanState = kreabankAdvanceBulkScan($scanState, $pendingTotal, $scanChunk, $scanBudgetMs, $page, $limit,
			static function ($chunk, $scanOffset) use ($service, $bulkScanFilterScope) {
				return $service->getPendingLines($chunk, $scanOffset, 'b.dateo', 'ASC', $bulkScanFilterScope);
			},
			static function ($id) use ($service, $navigationAccountId, $navigationAccountIds) {
				$line = $service->getLineById($id);
				return $line && (!$navigationAccountId || (int) $line['bank_account_id'] === $navigationAccountId) && in_array((int) $line['bank_account_id'], $navigationAccountIds, true) ? $line : null;
			}, $prepareReviewRow);
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
	if ($page > 0 && $offset >= $matchedCount) {
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

$param = '&mainmenu=bank&leftmenu=kreabank_bulkmatch&account_context=' . $navigationAccountId . kreabankNavigationKindParam();
if ($limit > 0 && $limit !== (int) $conf->liste_limit) {
	$param .= '&limit=' . $limit;
}

if (!$isScanRequest) {
	llxHeader('', $langs->trans('KreaBankBulkMatch'), '', '', 0, 0, '', '', '', 'mod-kreabank page-bulkmatch bodyforlist');

	kreabankPrintWorkspaceNavigation('bulkmatch', $navigationAccount);
	print load_fiche_titre($langs->trans('KreaBankBulkMatchTitle'), '', 'title_setup');

	print '<style>';
	print '.krea-bulk-score{display:inline-block;padding:2px 6px;border-radius:10px;font-size:11px;font-weight:700;background:#eef6ff;color:#1f4e79;border:1px solid #c3d9ef}';
	print '.krea-bulk-summary{display:flex;gap:10px;flex-wrap:wrap;margin:8px 0 12px 0}';
	print '.krea-bulk-summary .badge{font-size:12px}';
	print '#krea-bulk-scan-resume[hidden],#krea-bulk-scan-status[hidden]{display:none}';
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


	print '<div id="krea-bulk-results">';
} else {
	ob_start();
}

print '<div class="krea-bulk-summary">';
print '<span class="badge badge-status1">' . $langs->trans('KreaBankPendingCount') . ': ' . ((int) $pendingTotal) . '</span>';
print '<span class="badge badge-status4">' . $langs->trans('KreaBankBulkMatchWithSuggestion') . ': ' . ((int) $matchedCount) . '</span>';
print '<span class="badge badge-status2">' . $langs->trans('KreaBankBulkMatchPreselected') . ': ' . ((int) $preselectedCount) . '</span>';
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

print '<form method="GET" id="kreaBulkMatchPagerForm" action="' . dol_escape_htmltag($_SERVER['PHP_SELF']) . '">';
print kreabankNavigationHiddenFields($navigationAccountId);
print '<input type="hidden" name="mainmenu" value="bank">';
print '<input type="hidden" name="leftmenu" value="kreabank_bulkmatch">';
print_barre_liste($langs->trans('KreaBankBulkMatchWithSuggestion'), $page, $_SERVER['PHP_SELF'], $param, '', '', '', $bulkPagerNum, $matchedCount, 'title_setup', 0, '', '', $limit, 0, 0, 1);
print '</form>';

if (empty($rows)) {
	print '<div class="opacitymedium">' . $langs->trans($scanInProgress ? 'KreaBankBulkMatchLoading' : 'KreaBankBulkMatchNoSuggestions') . '</div>';
} else {
	print '<form method="POST" action="' . dol_escape_htmltag($_SERVER['PHP_SELF']) . '" id="kreaBulkMatchForm">';
	print kreabankNavigationHiddenFields($navigationAccountId);
	print '<input type="hidden" name="token" value="' . newToken() . '">';
	print '<input type="hidden" name="action" value="bulk_match_confirm">';
	print '<input type="hidden" name="mainmenu" value="bank">';
	print '<input type="hidden" name="leftmenu" value="kreabank_bulkmatch">';
	print '<input type="hidden" name="page" value="' . ((int) $page) . '">';
	print '<input type="hidden" name="limit" value="' . ((int) $limit) . '">';
	$reviewExpires = dol_now() + 1800;
	print '<input type="hidden" name="review_expires" value="' . $reviewExpires . '">';
	print '<details class="kb-help"><summary>' . $langs->trans('KreaBankScoreGuide') . '</summary><p>' . $langs->trans('KreaBankBulkMatchScoreHelp') . '</p></details>';
	print '<label class="krea-bulk-select-all"><input type="checkbox" id="krea_bulk_match_all"' . ($preselectedCount === count($rows) ? ' checked' : '') . '> ' . $langs->trans('KreaBankSelectAll') . '</label>';
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
		$reviewSignature = kreabankBulkReviewSignature($row, $bulkReviewScope, $reviewExpires, $bulkReviewSecret);
		print '<input type="hidden" name="review_signatures[' . $lineId . ']" value="' . $reviewSignature . '">';
		print '<input type="hidden" name="review_sources[' . $lineId . ']" value="' . (!empty($line['fk_statement']) ? 'staged' : 'native') . '">';
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
		print '<tr><td class="krea-label">' . $langs->trans('BankAccount') . '</td><td>' . dol_escape_htmltag(trim(($line['bank_account_ref'] ?? '') . ' — ' . ($line['bank_account_label'] ?? ''))) . '</td></tr>';
		print '<tr><td class="krea-label">' . $langs->trans('KreaBankAccountOwner') . '</td><td>' . dol_escape_htmltag($mysoc->name) . '</td></tr>';
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
		print '<tr><td class="krea-label">' . $langs->trans('Ref') . '</td><td><a href="' . dol_escape_htmltag($docUrl) . '">' . dol_escape_htmltag($docRef) . '</a></td></tr>';
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
		print '<tr><th colspan="2">' . $langs->trans('KreaBankBulkMatchEvidence') . '</th></tr>';
		print '<tr><td class="krea-label">' . $langs->trans('KreaBankBulkMatchScore') . '</td><td><span class="krea-bulk-score">' . ((int) $suggestion['score']) . '</span>' . (!$isBulkSafe ? '<span class="krea-bulk-low-score">' . $langs->trans('KreaBankBulkMatchLowConfidence') . '</span>' : '') . '</td></tr>';
		$reasonLabels = array();
		foreach ((array) $suggestion['details'] as $reason) {
			$reasonKey = 'KreaBankMatchReason_' . $reason;
			$reasonLabels[] = $langs->trans($reasonKey) === $reasonKey ? $reason : $langs->trans($reasonKey);
		}
		print '<tr><td class="krea-label">' . $langs->trans('KreaBankBulkMatchReasons') . '</td><td>' . dol_escape_htmltag(implode(', ', $reasonLabels)) . '</td></tr>';
		print '</table>';

		print '</div>';
		print '</div>';
	}

	print '</div>';
	if ($user->hasRight('kreabank', 'reconciliation', 'write') && $canNativeWrite) {
		print '<div class="krea-bulk-actions">';
		print '<label><input type="checkbox" name="review_confirmed" value="1" required> ' . $langs->trans('KreaBankBulkMatchReviewApproval') . '</label><br>';
		print '<button type="submit" class="butAction">' . $langs->trans('KreaBankBulkMatchConfirm') . '</button>';
		print '</div>';
	}
	print '</form>';
}

if ($isScanRequest) {
	header('Content-Type: application/json');
	header('Cache-Control: no-store');
	echo json_encode(array('html' => ob_get_clean(), 'more' => $scanInProgress,
		'auto' => $scanAutoReloadEnabled, 'scanned' => $scanScannedCount, 'total' => $pendingTotal,
		'token' => currentToken()), (defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0));
	$db->close();
	exit;
}
print '</div>';
print '<div class="krea-bulk-progress" id="krea-bulk-scan-status" role="status" aria-live="polite"></div>';
print '<button type="button" class="button" id="krea-bulk-scan-resume" hidden>' . $langs->trans('KreaBankBulkMatchContinue') . '</button>';
$scanClientConfig = array('url' => $_SERVER['PHP_SELF'], 'token' => currentToken(), 'page' => $page, 'limit' => $limit,
	'account' => $navigationAccountId, 'kind' => $navigationAccountKind, 'more' => $scanInProgress, 'auto' => $scanAutoReloadEnabled,
	'loading' => $langs->transnoentities('KreaBankBulkMatchLoading'), 'paused' => $langs->transnoentities('KreaBankBulkMatchReviewPaused'),
	'failed' => $langs->transnoentities('KreaBankBulkMatchScanFailed'), 'refresh' => $langs->transnoentities('Refresh'));
print '<script type="application/json" id="krea-bulk-scan-config">' . json_encode($scanClientConfig, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . '</script>';
print '<script src="' . dol_buildpath('/custom/kreabank/js/bulkmatch.js', 1) . '?v=1.14.24" defer></script>';
print dol_get_fiche_end();
llxFooter();
$db->close();
