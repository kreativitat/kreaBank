<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file       kreabank/history.php
 * \ingroup    kreabank
 * \brief      Reconciliation history and audit log.
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

$langs->loadLangs(array('errors', 'kreabank@kreabank'));

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

$action = GETPOST('action', 'aZ09');
$service = new KreaBankService($db, $user, $langs);
$canNativeWrite = ($user->hasRight('banque', 'modifier') || $user->hasRight('banque', 'consolidate'));
$nativeEntryLabel = 'LAN&Ccedil;AMENTO';
$undoActionLabel = 'DESFAZER';
$requestPage = GETPOSTISSET('pageplusone') ? (GETPOSTINT('pageplusone') - 1) : GETPOSTINT('page');
if ($requestPage < 0) {
	$requestPage = 0;
}
$isAuditNav = (GETPOST('audit_nav', 'alpha') !== '');
$historyPageStored = GETPOSTINT('history_page');
$auditPageStored = GETPOSTINT('audit_page');
$page = 0;
$auditPage = 0;
if ($isAuditNav) {
	$page = max(0, $historyPageStored);
	$auditPage = $requestPage;
} else {
	$page = $requestPage;
	$auditPage = max(0, $auditPageStored);
}
$limit = GETPOSTINT('limit');
if ($limit <= 0) {
	$limit = !empty($conf->liste_limit) ? (int) $conf->liste_limit : 25;
}
$limit = max(1, min(1000, $limit));
$offset = $page * $limit;
$auditOffset = $auditPage * $limit;
$historyPoolLimit = max(300, (($page + 1) * $limit) + 500);
$historyPoolLimit = min(5000, $historyPoolLimit);
$auditPoolLimit = max(300, (($auditPage + 1) * $limit) + 500);
$auditPoolLimit = min(5000, $auditPoolLimit);

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

if ($action === 'undo' && $user->hasRight('kreabank', 'reconciliation', 'undo') && $canNativeWrite) {
	$enforcePostActionWithToken();
	$reconciliationId = GETPOSTINT('reconciliation_id');
	$reason = GETPOST('reason', 'alphanohtml');
	try {
		$service->undoReconciliation($reconciliationId, $reason);
		setEventMessages($langs->trans('KreaBankUndoDone'), null, 'mesgs');
	} catch (Exception $e) {
		setEventMessages($e->getMessage(), null, 'errors');
	}
}

$historySearch = trim((string) GETPOST('hist_search', 'restricthtml'));
$historyDescription = trim((string) GETPOST('hist_description', 'restricthtml'));
$historyDateFrom = trim((string) GETPOST('hist_date_from', 'alphanohtml'));
$historyDateTo = trim((string) GETPOST('hist_date_to', 'alphanohtml'));
$historyDirection = trim((string) GETPOST('hist_direction', 'aZ09'));
$historyLinkType = trim((string) GETPOST('hist_link_type', 'aZ09'));
$historyAuditType = trim((string) GETPOST('hist_audit_type', 'aZ09'));
$auditSearchWhenFrom = trim((string) GETPOST('audit_search_when_from', 'alphanohtml'));
$auditSearchWhenTo = trim((string) GETPOST('audit_search_when_to', 'alphanohtml'));
$auditSearchAction = trim((string) GETPOST('audit_search_action', 'restricthtml'));
$auditSearchUser = trim((string) GETPOST('audit_search_user', 'restricthtml'));
$auditSearchLine = trim((string) GETPOST('audit_search_line', 'alphanohtml'));
$auditSearchSummary = trim((string) GETPOST('audit_search_summary', 'restricthtml'));
if ($historyDirection === '') {
	$historyDirection = 'all';
}
if ($historyLinkType === '') {
	$historyLinkType = 'all';
}
if ($historyAuditType === '') {
	$historyAuditType = 'all';
}
if (GETPOST('button_removefilter_x', 'alpha') !== '' || GETPOST('button_removefilter', 'alpha') !== '' || GETPOST('removefilter', 'alpha') !== '') {
	$historySearch = '';
	$historyDescription = '';
	$historyDateFrom = '';
	$historyDateTo = '';
	$historyDirection = 'all';
	$historyLinkType = 'all';
	$historyAuditType = 'all';
	$auditSearchWhenFrom = '';
	$auditSearchWhenTo = '';
	$auditSearchAction = '';
	$auditSearchUser = '';
	$auditSearchLine = '';
	$auditSearchSummary = '';
	$page = 0;
	$auditPage = 0;
	$offset = 0;
	$auditOffset = 0;
}

$recent = array();
$auditRows = array();
try {
	$recent = $service->getRecentReconciliations($historyPoolLimit);
	$auditRows = $service->getAuditHistory($auditPoolLimit);
} catch (Exception $e) {
	setEventMessages($e->getMessage(), null, 'errors');
}

$normalizeDateToTimestamp = static function ($value, $endOfDay = false) {
	$value = trim((string) $value);
	if ($value === '' || !preg_match('/^\d{4}\-\d{2}\-\d{2}$/', $value)) {
		return 0;
	}
	$ts = strtotime($value . ($endOfDay ? ' 23:59:59' : ' 00:00:00'));
	if ($ts === false || $ts <= 0) {
		return 0;
	}

	return (int) $ts;
};
$filterDateFromTs = $normalizeDateToTimestamp($historyDateFrom, false);
$filterDateToTs = $normalizeDateToTimestamp($historyDateTo, true);
$auditFilterDateFromTs = $normalizeDateToTimestamp($auditSearchWhenFrom, false);
$auditFilterDateToTs = $normalizeDateToTimestamp($auditSearchWhenTo, true);
$normalizedSearch = kreabankNormalizeText($historySearch);
$normalizedDescriptionSearch = kreabankNormalizeText($historyDescription);
$normalizedAuditActionSearch = kreabankNormalizeText($auditSearchAction);
$normalizedAuditUserSearch = kreabankNormalizeText($auditSearchUser);
$normalizedAuditSummarySearch = kreabankNormalizeText($auditSearchSummary);
$parseIntegerFilter = static function ($rawValue) {
	$rawValue = trim((string) $rawValue);
	if ($rawValue === '') {
		return null;
	}
	if (preg_match('/^(<=|>=|<>|!=|=|<|>)\s*(-?\d+)$/', $rawValue, $matches)) {
		return array(
			'operator' => $matches[1],
			'value' => (int) $matches[2],
		);
	}
	if (preg_match('/^-?\d+$/', $rawValue)) {
		return array(
			'operator' => '=',
			'value' => (int) $rawValue,
		);
	}

	return null;
};
$auditLineFilter = $parseIntegerFilter($auditSearchLine);
$currentHttpHost = strtolower((string) preg_replace('/:\d+$/', '', (string) (isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '')));
$isAllowedAbsoluteUrlPrefix = static function ($urlPrefix) use ($currentHttpHost) {
	$urlPrefix = trim((string) $urlPrefix);
	if ($urlPrefix === '' || $currentHttpHost === '') {
		return false;
	}
	$urlParts = parse_url($urlPrefix);
	if (!is_array($urlParts)) {
		return false;
	}
	$scheme = strtolower((string) (isset($urlParts['scheme']) ? $urlParts['scheme'] : ''));
	if ($scheme !== 'http' && $scheme !== 'https') {
		return false;
	}
	$host = strtolower((string) (isset($urlParts['host']) ? $urlParts['host'] : ''));

	return ($host !== '' && $host === $currentHttpHost);
};

$latestAuditByLine = array();
$availableAuditTypes = array();
foreach ($auditRows as $auditRow) {
	$auditType = trim((string) (isset($auditRow['audit_type']) ? $auditRow['audit_type'] : ''));
	if ($auditType !== '') {
		$availableAuditTypes[$auditType] = true;
	}
	$statementLineId = (int) (isset($auditRow['fk_statement_line']) ? $auditRow['fk_statement_line'] : 0);
	if ($statementLineId <= 0 || isset($latestAuditByLine[$statementLineId])) {
		continue;
	}
	$latestAuditByLine[$statementLineId] = array(
		'datec' => (string) (isset($auditRow['datec']) ? $auditRow['datec'] : ''),
		'audit_type' => $auditType,
		'login' => (string) (isset($auditRow['login']) ? $auditRow['login'] : ''),
	);
}

$availableLinkTypes = array();
$preparedRecentRows = array();
$lineLinksCache = array();
$summaryLinesCount = 0;
$summaryCreditTotal = 0.0;
$summaryDebitTotal = 0.0;
$summaryLinkedDocs = 0;

$recentLineIds = array();
foreach ($recent as $recentRow) {
	$recentLineId = (int) (isset($recentRow['rowid']) ? $recentRow['rowid'] : 0);
	if ($recentLineId > 0) {
		$recentLineIds[$recentLineId] = $recentLineId;
	}
}
if (!empty($recentLineIds)) {
	try {
		$lineLinksCache = $service->getLineLinksBatch(array_values($recentLineIds));
	} catch (Exception $e) {
		$lineLinksCache = array();
	}
}

foreach ($recent as $row) {
	$lineId = (int) (isset($row['rowid']) ? $row['rowid'] : 0);
	if ($lineId <= 0) {
		continue;
	}

	$rawLinks = !empty($lineLinksCache[$lineId]) && is_array($lineLinksCache[$lineId]) ? $lineLinksCache[$lineId] : array();
	$preparedLinks = array();
	$linkTypesForRow = array();
	foreach ($rawLinks as $link) {
		if (!is_array($link)) {
			continue;
		}
		$docType = trim((string) (isset($link['doc_type']) ? $link['doc_type'] : ''));
		$docId = (int) (isset($link['fk_doc']) ? $link['fk_doc'] : 0);
		$docRef = trim((string) (isset($link['doc_ref']) ? $link['doc_ref'] : ''));
		$urlPrefix = trim((string) (isset($link['url']) ? $link['url'] : ''));
		$docUrl = '';
		if ($urlPrefix !== '' && $docId > 0) {
			if (preg_match('/^https?\:\/\//i', $urlPrefix) && $isAllowedAbsoluteUrlPrefix($urlPrefix)) {
				$docUrl = $urlPrefix . $docId;
			} elseif (!preg_match('/^https?\:\/\//i', $urlPrefix)) {
				$docUrl = dol_buildpath($urlPrefix . $docId, 1);
			}
		}
		if ($docType !== '') {
			$availableLinkTypes[$docType] = true;
			$linkTypesForRow[$docType] = true;
		}
		$preparedLinks[] = array(
			'doc_type' => $docType,
			'fk_doc' => $docId,
			'doc_ref' => $docRef,
			'doc_url' => $docUrl,
		);
	}
	$auditInfo = isset($latestAuditByLine[$lineId]) ? $latestAuditByLine[$lineId] : array();
	$eventDateRaw = !empty($auditInfo['datec']) ? (string) $auditInfo['datec'] : (string) (isset($row['date_validate']) ? $row['date_validate'] : '');
	$eventTs = strtotime($eventDateRaw);
	if ($eventTs === false || $eventTs <= 0) {
		$eventDateRaw = (string) (isset($row['operation_date']) ? $row['operation_date'] : '');
		$eventTs = strtotime($eventDateRaw);
	}

	$amount = (float) (isset($row['amount']) ? $row['amount'] : 0.0);
	$descriptionRaw = (string) (isset($row['description']) ? $row['description'] : '');
	$normalizedDescription = kreabankNormalizeText($descriptionRaw);
	if ($historyDirection === 'debit' && !($amount < -0.00001)) {
		continue;
	}
	if ($historyDirection === 'credit' && !($amount > 0.00001)) {
		continue;
	}
	if ($filterDateFromTs > 0 && $eventTs > 0 && $eventTs < $filterDateFromTs) {
		continue;
	}
	if ($filterDateToTs > 0 && $eventTs > 0 && $eventTs > $filterDateToTs) {
		continue;
	}
	if ($historyLinkType !== 'all' && empty($linkTypesForRow[$historyLinkType])) {
		continue;
	}
	if ($normalizedDescriptionSearch !== '' && strpos($normalizedDescription, $normalizedDescriptionSearch) === false) {
		continue;
	}

	$rowSearchParts = array(
		$descriptionRaw,
		(string) $eventDateRaw,
		(string) $amount,
		price($amount),
		(string) (isset($row['currency']) ? $row['currency'] : ''),
		(string) (!empty($auditInfo['audit_type']) ? $auditInfo['audit_type'] : ''),
		(string) (!empty($auditInfo['login']) ? $auditInfo['login'] : ''),
		(string) $lineId,
	);
	foreach ($preparedLinks as $preparedLink) {
		$rowSearchParts[] = (string) $preparedLink['doc_type'];
		$rowSearchParts[] = (string) $preparedLink['doc_ref'];
		$rowSearchParts[] = (string) $preparedLink['fk_doc'];
		$rowSearchParts[] = (string) $preparedLink['doc_url'];
	}
	$rowSearchBlob = kreabankNormalizeText(implode(' ', $rowSearchParts));
	if ($normalizedSearch !== '' && strpos($rowSearchBlob, $normalizedSearch) === false) {
		continue;
	}

	$preparedRecentRows[] = array(
		'row' => $row,
		'line_id' => $lineId,
		'amount' => $amount,
		'event_ts' => (int) $eventTs,
		'event_date' => $eventDateRaw,
		'event_audit_type' => (string) (!empty($auditInfo['audit_type']) ? $auditInfo['audit_type'] : ''),
		'event_login' => (string) (!empty($auditInfo['login']) ? $auditInfo['login'] : ''),
		'links' => $preparedLinks,
	);

	$summaryLinesCount++;
	$summaryLinkedDocs += count($preparedLinks);
	if ($amount > 0) {
		$summaryCreditTotal += $amount;
	} elseif ($amount < 0) {
		$summaryDebitTotal += abs($amount);
	}
}

if (!empty($preparedRecentRows)) {
	usort($preparedRecentRows, static function ($a, $b) {
		$ta = (int) (isset($a['event_ts']) ? $a['event_ts'] : 0);
		$tb = (int) (isset($b['event_ts']) ? $b['event_ts'] : 0);
		if ($tb !== $ta) {
			return $tb <=> $ta;
		}

		$la = (int) (isset($a['line_id']) ? $a['line_id'] : 0);
		$lb = (int) (isset($b['line_id']) ? $b['line_id'] : 0);

		return $lb <=> $la;
	});
}
$historyTotalRecords = count($preparedRecentRows);
if ($page > 0 && $offset >= $historyTotalRecords) {
	$page = 0;
	$offset = 0;
}
$preparedRecentRowsPage = array_slice($preparedRecentRows, $offset, $limit);
$historyCurrentPageCount = count($preparedRecentRowsPage);
$historyPagerNum = $historyCurrentPageCount;
if ($limit > 0 && ($offset + $historyCurrentPageCount) < $historyTotalRecords) {
	// Dolibarr pager shows next navigation when num > limit.
	$historyPagerNum = $limit + 1;
}

$availableLinkTypeOptions = array_keys($availableLinkTypes);
sort($availableLinkTypeOptions);
$availableAuditTypeOptions = array_keys($availableAuditTypes);
sort($availableAuditTypeOptions);
$auditTypeToLangKey = static function ($auditType) {
	$auditType = trim((string) $auditType);
	if ($auditType === '') {
		return '';
	}
	$normalized = str_replace(array('-', '_'), ' ', strtolower($auditType));
	$normalized = ucwords($normalized);
	$normalized = str_replace(' ', '', $normalized);

	return 'KreaBankAudit' . $normalized;
};

$summarizeAuditPayload = static function ($audit) use ($langs) {
	$payloadRaw = (string) (isset($audit['payload_json']) ? $audit['payload_json'] : '');
	$payload = json_decode($payloadRaw, true);
	if (!is_array($payload)) {
		return array(
			'summary' => ($payloadRaw !== '' ? $payloadRaw : '-'),
		);
	}

	$parts = array();
	if (!empty($payload['strategy'])) {
		$parts[] = $langs->trans('Type') . ': ' . (string) $payload['strategy'];
	}
	if (isset($payload['confidence_score'])) {
		$parts[] = 'Score: ' . ((int) $payload['confidence_score']);
	}
	if (!empty($payload['links']) && is_array($payload['links'])) {
		$linkCount = 0;
		$allocated = 0.0;
		$linkTypes = array();
		foreach ($payload['links'] as $payloadLink) {
			if (!is_array($payloadLink)) {
				continue;
			}
			$linkCount++;
			$allocated += abs((float) (isset($payloadLink['allocated_amount']) ? $payloadLink['allocated_amount'] : 0));
			$linkType = trim((string) (isset($payloadLink['doc_type']) ? $payloadLink['doc_type'] : ''));
			if ($linkType !== '') {
				$linkTypes[$linkType] = true;
			}
		}
		if ($linkCount > 0) {
			$parts[] = $langs->trans('KreaBankHistoryLinksCount', (string) $linkCount);
			$parts[] = $langs->trans('KreaBankAllocate') . ': ' . price($allocated);
		}
		if (!empty($linkTypes)) {
			$parts[] = implode(', ', array_keys($linkTypes));
		}
	}
	if (!empty($payload['reason'])) {
		$parts[] = $langs->trans('KreaBankReason') . ': ' . (string) $payload['reason'];
	}
	if (!empty($payload['quick_entry_id'])) {
		$parts[] = 'Quick entry #' . ((int) $payload['quick_entry_id']);
	}

	$summary = !empty($parts) ? implode(' | ', $parts) : '-';

	return array(
		'summary' => $summary,
	);
};

$preparedAuditRows = array();
foreach ($auditRows as $auditRow) {
	$auditType = trim((string) (isset($auditRow['audit_type']) ? $auditRow['audit_type'] : ''));
	if ($historyAuditType !== 'all' && $auditType !== $historyAuditType) {
		continue;
	}
	if ($normalizedAuditActionSearch !== '' && strpos(kreabankNormalizeText($auditType), $normalizedAuditActionSearch) === false) {
		continue;
	}
	$auditDateRaw = (string) (isset($auditRow['datec']) ? $auditRow['datec'] : '');
	$auditTs = strtotime($auditDateRaw);
	if ($filterDateFromTs > 0 && $auditTs > 0 && $auditTs < $filterDateFromTs) {
		continue;
	}
	if ($filterDateToTs > 0 && $auditTs > 0 && $auditTs > $filterDateToTs) {
		continue;
	}
	if ($auditFilterDateFromTs > 0 && $auditTs > 0 && $auditTs < $auditFilterDateFromTs) {
		continue;
	}
	if ($auditFilterDateToTs > 0 && $auditTs > 0 && $auditTs > $auditFilterDateToTs) {
		continue;
	}
	$auditLogin = (string) (isset($auditRow['login']) ? $auditRow['login'] : '');
	if ($normalizedAuditUserSearch !== '' && strpos(kreabankNormalizeText($auditLogin), $normalizedAuditUserSearch) === false) {
		continue;
	}
	$auditLineId = (int) (isset($auditRow['fk_statement_line']) ? $auditRow['fk_statement_line'] : 0);
	if ($auditLineFilter !== null) {
		$lineMatches = true;
		$filterLineValue = (int) $auditLineFilter['value'];
		switch ($auditLineFilter['operator']) {
			case '<':
				$lineMatches = ($auditLineId < $filterLineValue);
				break;
			case '<=':
				$lineMatches = ($auditLineId <= $filterLineValue);
				break;
			case '>':
				$lineMatches = ($auditLineId > $filterLineValue);
				break;
			case '>=':
				$lineMatches = ($auditLineId >= $filterLineValue);
				break;
			case '<>':
			case '!=':
				$lineMatches = ($auditLineId !== $filterLineValue);
				break;
			case '=':
			default:
				$lineMatches = ($auditLineId === $filterLineValue);
				break;
		}
		if (!$lineMatches) {
			continue;
		}
	}

	$summary = $summarizeAuditPayload($auditRow);
	if ($normalizedAuditSummarySearch !== '') {
		$auditSummarySearchBlob = kreabankNormalizeText((string) $summary['summary']);
		if (strpos($auditSummarySearchBlob, $normalizedAuditSummarySearch) === false) {
			continue;
		}
	}
	$auditSearchBlob = kreabankNormalizeText(
		(string) (isset($auditRow['audit_type']) ? $auditRow['audit_type'] : '') . ' ' .
			(string) (isset($auditRow['login']) ? $auditRow['login'] : '') . ' ' .
			(string) (isset($summary['summary']) ? $summary['summary'] : '') . ' ' .
			(string) (isset($auditRow['fk_statement_line']) ? $auditRow['fk_statement_line'] : 0) . ' ' .
			(string) (isset($auditRow['datec']) ? $auditRow['datec'] : '')
	);
	if ($normalizedSearch !== '' && strpos($auditSearchBlob, $normalizedSearch) === false) {
		continue;
	}

	$preparedAuditRows[] = array(
		'audit' => $auditRow,
		'summary' => $summary,
	);
}
$auditTotalRecords = count($preparedAuditRows);
if ($auditPage > 0 && $auditOffset >= $auditTotalRecords) {
	$auditPage = 0;
	$auditOffset = 0;
}
$preparedAuditRowsPage = array_slice($preparedAuditRows, $auditOffset, $limit);
$auditCurrentPageCount = count($preparedAuditRowsPage);
$auditPagerNum = $auditCurrentPageCount;
if ($limit > 0 && ($auditOffset + $auditCurrentPageCount) < $auditTotalRecords) {
	$auditPagerNum = $limit + 1;
}

$historyParam = '&mainmenu=bank&leftmenu=kreabank_history';
if ($historySearch !== '') {
	$historyParam .= '&hist_search=' . urlencode($historySearch);
}
if ($historyDescription !== '') {
	$historyParam .= '&hist_description=' . urlencode($historyDescription);
}
if ($historyDateFrom !== '') {
	$historyParam .= '&hist_date_from=' . urlencode($historyDateFrom);
}
if ($historyDateTo !== '') {
	$historyParam .= '&hist_date_to=' . urlencode($historyDateTo);
}
if ($historyDirection !== '' && $historyDirection !== 'all') {
	$historyParam .= '&hist_direction=' . urlencode($historyDirection);
}
if ($historyLinkType !== '' && $historyLinkType !== 'all') {
	$historyParam .= '&hist_link_type=' . urlencode($historyLinkType);
}
if ($historyAuditType !== '' && $historyAuditType !== 'all') {
	$historyParam .= '&hist_audit_type=' . urlencode($historyAuditType);
}
if ($auditSearchWhenFrom !== '') {
	$historyParam .= '&audit_search_when_from=' . urlencode($auditSearchWhenFrom);
}
if ($auditSearchWhenTo !== '') {
	$historyParam .= '&audit_search_when_to=' . urlencode($auditSearchWhenTo);
}
if ($auditSearchAction !== '') {
	$historyParam .= '&audit_search_action=' . urlencode($auditSearchAction);
}
if ($auditSearchUser !== '') {
	$historyParam .= '&audit_search_user=' . urlencode($auditSearchUser);
}
if ($auditSearchLine !== '') {
	$historyParam .= '&audit_search_line=' . urlencode($auditSearchLine);
}
if ($auditSearchSummary !== '') {
	$historyParam .= '&audit_search_summary=' . urlencode($auditSearchSummary);
}
if ($limit > 0 && $limit !== (int) $conf->liste_limit) {
	$historyParam .= '&limit=' . $limit;
}
if ($auditPage > 0) {
	$historyParam .= '&audit_page=' . $auditPage;
}
$auditParam = '&mainmenu=bank&leftmenu=kreabank_history';
if ($historySearch !== '') {
	$auditParam .= '&hist_search=' . urlencode($historySearch);
}
if ($historyDescription !== '') {
	$auditParam .= '&hist_description=' . urlencode($historyDescription);
}
if ($historyDateFrom !== '') {
	$auditParam .= '&hist_date_from=' . urlencode($historyDateFrom);
}
if ($historyDateTo !== '') {
	$auditParam .= '&hist_date_to=' . urlencode($historyDateTo);
}
if ($historyDirection !== '' && $historyDirection !== 'all') {
	$auditParam .= '&hist_direction=' . urlencode($historyDirection);
}
if ($historyLinkType !== '' && $historyLinkType !== 'all') {
	$auditParam .= '&hist_link_type=' . urlencode($historyLinkType);
}
if ($historyAuditType !== '' && $historyAuditType !== 'all') {
	$auditParam .= '&hist_audit_type=' . urlencode($historyAuditType);
}
if ($auditSearchWhenFrom !== '') {
	$auditParam .= '&audit_search_when_from=' . urlencode($auditSearchWhenFrom);
}
if ($auditSearchWhenTo !== '') {
	$auditParam .= '&audit_search_when_to=' . urlencode($auditSearchWhenTo);
}
if ($auditSearchAction !== '') {
	$auditParam .= '&audit_search_action=' . urlencode($auditSearchAction);
}
if ($auditSearchUser !== '') {
	$auditParam .= '&audit_search_user=' . urlencode($auditSearchUser);
}
if ($auditSearchLine !== '') {
	$auditParam .= '&audit_search_line=' . urlencode($auditSearchLine);
}
if ($auditSearchSummary !== '') {
	$auditParam .= '&audit_search_summary=' . urlencode($auditSearchSummary);
}
if ($limit > 0 && $limit !== (int) $conf->liste_limit) {
	$auditParam .= '&limit=' . $limit;
}
$auditParam .= '&audit_nav=1';
$auditParam .= '&history_page=' . $page;
$auditParam .= '&audit_page=' . $auditPage;

llxHeader('', $langs->trans('KreaBankHistory'), '', '', 0, 0, '', '', '', 'mod-kreabank page-history bodyforlist');

print load_fiche_titre($langs->trans('KreaBankHistory'), '', 'title_setup');
$head = kreabankPrepareHead();
print dol_get_fiche_head($head, 'history', $langs->trans('KreaBankHistory'), -1, 'bank');

print '<style>';
print '.krea-history-search-tool{display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;background:#f8f9fb;border:1px solid #e2e4e8;border-radius:6px;padding:10px;margin:8px 0 12px 0}';
print '.krea-history-kpi{display:grid;grid-template-columns:repeat(4,minmax(140px,1fr));gap:8px;margin:0 0 12px 0}';
print '.krea-history-kpi .item{background:#fff;border:1px solid #ddd;border-radius:6px;padding:10px}';
print '.krea-history-kpi .item .label{font-size:11px;color:#666;text-transform:uppercase;letter-spacing:.4px}';
print '.krea-history-kpi .item .value{font-size:20px;font-weight:700;line-height:1.1;margin-top:3px}';
print '.krea-history-link{display:inline-block;margin:2px 4px 2px 0;padding:2px 6px;border-radius:4px;background:#eef6ff;border:1px solid #d2e5ff;font-size:11px}';
print '.krea-history-row-top td{vertical-align:top!important}';
print '.krea-history-salary-group{margin:0 0 10px 0;padding:7px 10px;border:1px dashed #d9dee6;border-radius:4px;background:#fbfcfe}';
print '.krea-history-salary-group:last-child{margin-bottom:0}';
print '.krea-history-sub{color:#666;font-size:12px}';
print '.krea-history-pre{max-height:160px;overflow:auto;font-size:11px;line-height:1.35;background:#f7f7f7;border:1px solid #e4e4e4;padding:6px;border-radius:4px;white-space:pre-wrap;word-break:break-word}';
print '.krea-history-action-btn{font-size:11px!important;padding:3px 8px!important;line-height:1.25;display:inline-block;text-transform:uppercase}';
print '@media (max-width: 1100px){.krea-history-kpi{grid-template-columns:repeat(2,minmax(140px,1fr))}}';
print '@media (max-width: 700px){.krea-history-kpi{grid-template-columns:1fr}}';
print '</style>';

print '<h3 style="margin-top:0">' . $langs->trans('KreaBankHistory') . '</h3>';
print '<form method="GET" id="kreaHistoryFilterForm" action="' . dol_escape_htmltag($_SERVER['PHP_SELF']) . '">';
print '<input type="hidden" name="mainmenu" value="bank">';
print '<input type="hidden" name="leftmenu" value="kreabank_history">';
print '<input type="hidden" name="page" value="0">';
print '<input type="hidden" name="history_page" value="0">';
print '<input type="hidden" name="audit_page" value="0">';
print '<input type="hidden" name="limit" value="' . ((int) $limit) . '">';
print '<input type="hidden" name="audit_search_when_from" value="' . dol_escape_htmltag($auditSearchWhenFrom) . '">';
print '<input type="hidden" name="audit_search_when_to" value="' . dol_escape_htmltag($auditSearchWhenTo) . '">';
print '<input type="hidden" name="audit_search_action" value="' . dol_escape_htmltag($auditSearchAction) . '">';
print '<input type="hidden" name="audit_search_user" value="' . dol_escape_htmltag($auditSearchUser) . '">';
print '<input type="hidden" name="audit_search_line" value="' . dol_escape_htmltag($auditSearchLine) . '">';
print '<input type="hidden" name="audit_search_summary" value="' . dol_escape_htmltag($auditSearchSummary) . '">';
print '</form>';

print '<div class="krea-history-kpi">';
print '<div class="item"><div class="label">' . $langs->trans('KreaBankHistorySummaryLines') . '</div><div class="value">' . ((int) $summaryLinesCount) . '</div></div>';
print '<div class="item"><div class="label">' . $langs->trans('KreaBankHistorySummaryCredits') . '</div><div class="value">' . price($summaryCreditTotal) . '</div></div>';
print '<div class="item"><div class="label">' . $langs->trans('KreaBankHistorySummaryDebits') . '</div><div class="value">' . price($summaryDebitTotal) . '</div></div>';
print '<div class="item"><div class="label">' . $langs->trans('KreaBankHistorySummaryLinks') . '</div><div class="value">' . ((int) $summaryLinkedDocs) . '</div></div>';
print '</div>';

print '<div class="krea-history-search-tool">';
print '<div><label class="krea-history-sub">' . $langs->trans('Search') . '</label><br>';
print '<input type="text" name="hist_search" class="flat" style="min-width:600px;max-width:100%" value="' . dol_escape_htmltag($historySearch) . '" placeholder="' . dol_escape_htmltag($langs->trans('KreaBankHistorySearchPlaceholder')) . '" form="kreaHistoryFilterForm"></div>';
print '<div class="nowraponall"><button type="submit" class="liste_titre button_search reposition" name="button_search_x" value="x" form="kreaHistoryFilterForm"><span class="fas fa-search"></span></button><button type="submit" class="liste_titre button_removefilter reposition" name="button_removefilter_x" value="x" form="kreaHistoryFilterForm"><span class="fas fa-times"></span></button></div>';
print '</div>';

print '<form method="GET" id="kreaHistoryPagerForm" action="' . dol_escape_htmltag($_SERVER['PHP_SELF']) . '">';
print '<input type="hidden" name="mainmenu" value="bank">';
print '<input type="hidden" name="leftmenu" value="kreabank_history">';
print '<input type="hidden" name="history_page" value="' . ((int) $page) . '">';
print '<input type="hidden" name="audit_page" value="' . ((int) $auditPage) . '">';
print '<input type="hidden" name="hist_search" value="' . dol_escape_htmltag($historySearch) . '">';
print '<input type="hidden" name="hist_description" value="' . dol_escape_htmltag($historyDescription) . '">';
print '<input type="hidden" name="hist_date_from" value="' . dol_escape_htmltag($historyDateFrom) . '">';
print '<input type="hidden" name="hist_date_to" value="' . dol_escape_htmltag($historyDateTo) . '">';
print '<input type="hidden" name="hist_direction" value="' . dol_escape_htmltag($historyDirection) . '">';
print '<input type="hidden" name="hist_link_type" value="' . dol_escape_htmltag($historyLinkType) . '">';
print '<input type="hidden" name="hist_audit_type" value="' . dol_escape_htmltag($historyAuditType) . '">';
print '<input type="hidden" name="audit_search_when_from" value="' . dol_escape_htmltag($auditSearchWhenFrom) . '">';
print '<input type="hidden" name="audit_search_when_to" value="' . dol_escape_htmltag($auditSearchWhenTo) . '">';
print '<input type="hidden" name="audit_search_action" value="' . dol_escape_htmltag($auditSearchAction) . '">';
print '<input type="hidden" name="audit_search_user" value="' . dol_escape_htmltag($auditSearchUser) . '">';
print '<input type="hidden" name="audit_search_line" value="' . dol_escape_htmltag($auditSearchLine) . '">';
print '<input type="hidden" name="audit_search_summary" value="' . dol_escape_htmltag($auditSearchSummary) . '">';
print_barre_liste($langs->trans('KreaBankHistory'), $page, $_SERVER['PHP_SELF'], $historyParam, '', '', '', $historyPagerNum, $historyTotalRecords, 'title_setup', 0, '', '', $limit, 0, 0, 1);
print '</form>';

if ($historyTotalRecords === 0) {
	print '<div class="opacitymedium">' . $langs->trans('None') . '</div>';
} else {
	print '<div class="div-table-responsive">';
	print '<table class="tagtable nobottomiftotal liste">';
	print '<tr class="liste_titre_filter">';
	print '<td class="liste_titre">';
	print '<div style="display:flex;gap:4px;flex-direction:column;min-width:130px">';
	print '<input class="flat" type="date" name="hist_date_from" value="' . dol_escape_htmltag($historyDateFrom) . '" title="' . $langs->trans('From') . '" form="kreaHistoryFilterForm">';
	print '<input class="flat" type="date" name="hist_date_to" value="' . dol_escape_htmltag($historyDateTo) . '" title="' . $langs->trans('To') . '" form="kreaHistoryFilterForm">';
	print '</div>';
	print '</td>';
	print '<td class="liste_titre">';
	print '<input class="flat minwidth300" type="text" name="hist_description" value="' . dol_escape_htmltag($historyDescription) . '" placeholder="' . dol_escape_htmltag($langs->trans('KreaBankHistoryDescriptionPlaceholder')) . '" title="' . dol_escape_htmltag($langs->trans('KreaBankDescription')) . '" form="kreaHistoryFilterForm">';
	print '</td>';
	print '<td class="liste_titre right">';
	print '<select name="hist_direction" class="flat" form="kreaHistoryFilterForm">';
	print '<option value="all"' . ($historyDirection === 'all' ? ' selected' : '') . '>' . $langs->trans('KreaBankDirectionAll') . '</option>';
	print '<option value="debit"' . ($historyDirection === 'debit' ? ' selected' : '') . '>' . $langs->trans('KreaBankDirectionDebit') . '</option>';
	print '<option value="credit"' . ($historyDirection === 'credit' ? ' selected' : '') . '>' . $langs->trans('KreaBankDirectionCredit') . '</option>';
	print '</select>';
	print '</td>';
	print '<td class="liste_titre">';
	print '<select name="hist_link_type" class="flat" form="kreaHistoryFilterForm">';
	print '<option value="all"' . ($historyLinkType === 'all' ? ' selected' : '') . '>' . $langs->trans('KreaBankLinkTypeAll') . '</option>';
	foreach ($availableLinkTypeOptions as $linkTypeOption) {
		$linkTypeLabel = str_replace('_', ' ', (string) $linkTypeOption);
		print '<option value="' . dol_escape_htmltag((string) $linkTypeOption) . '"' . ($historyLinkType === $linkTypeOption ? ' selected' : '') . '>' . dol_escape_htmltag($linkTypeLabel) . '</option>';
	}
	print '</select>';
	print '</td>';
	print '<td class="liste_titre">';
	print '<select name="hist_audit_type" class="flat" form="kreaHistoryFilterForm">';
	print '<option value="all"' . ($historyAuditType === 'all' ? ' selected' : '') . '>' . $langs->trans('KreaBankDirectionAll') . '</option>';
	foreach ($availableAuditTypeOptions as $auditTypeOption) {
		$auditTypeLangKey = $auditTypeToLangKey($auditTypeOption);
		$auditTypeLabel = ($auditTypeLangKey !== '' ? $langs->trans($auditTypeLangKey) : '');
		if ($auditTypeLabel === '' || $auditTypeLabel === $auditTypeLangKey) {
			$auditTypeLabel = (string) $auditTypeOption;
		}
		print '<option value="' . dol_escape_htmltag((string) $auditTypeOption) . '"' . ($historyAuditType === $auditTypeOption ? ' selected' : '') . '>' . dol_escape_htmltag((string) $auditTypeLabel) . '</option>';
	}
	print '</select>';
	print '</td>';
	print '<td class="liste_titre center">&nbsp;</td>';
	print '<td class="liste_titre center maxwidthsearch"><div class="nowraponall"><button type="submit" class="liste_titre button_search reposition" name="button_search_x" value="x" form="kreaHistoryFilterForm"><span class="fas fa-search"></span></button><button type="submit" class="liste_titre button_removefilter reposition" name="button_removefilter_x" value="x" form="kreaHistoryFilterForm"><span class="fas fa-times"></span></button></div></td>';
	print '</tr>';
	print '<tr class="liste_titre">';
	print_liste_field_titre($langs->trans('KreaBankWhen'), '', '', '', '', '');
	print_liste_field_titre($langs->trans('KreaBankDescription'), '', '', '', '', '');
	print_liste_field_titre($langs->trans('KreaBankAmount'), '', '', '', '', 'class="right"');
	print_liste_field_titre($langs->trans('KreaBankLinkedDocuments'), '', '', '', '', '');
	print_liste_field_titre($langs->trans('Type'), '', '', '', '', '');
	print_liste_field_titre($langs->trans('KreaBankStatus'), '', '', '', '', '');
	print_liste_field_titre($langs->trans('Action'), '', '', '', '', 'class="center"');
	print '</tr>';

	$renderLinkedDocument = static function ($link) {
		if (!is_array($link)) {
			return '';
		}
		$linkType = trim((string) (isset($link['doc_type']) ? $link['doc_type'] : ''));
		$linkTypeLabel = ($linkType !== '' ? str_replace('_', ' ', $linkType) : 'doc');
		$linkRef = trim((string) (isset($link['doc_ref']) ? $link['doc_ref'] : ''));
		$linkDocId = (int) (isset($link['fk_doc']) ? $link['fk_doc'] : 0);
		if ($linkRef !== '' && in_array(strtolower($linkRef), array('(paiement)', '(payment)', '(variouspayment)', '(various_payment)', '(various payment)'), true)) {
			$linkRef = '';
		}
		$linkText = ($linkRef !== '' ? $linkRef : ('#' . $linkDocId));
		$linkDocUrl = trim((string) (isset($link['doc_url']) ? $link['doc_url'] : ''));
		$out = '<div>';
		$out .= '<span class="krea-history-link">' . dol_escape_htmltag($linkTypeLabel) . '</span>';
		if ($linkDocUrl !== '') {
			$out .= '<a href="' . dol_escape_htmltag($linkDocUrl) . '" target="_blank" rel="noopener">' . dol_escape_htmltag($linkText) . '</a>';
		} else {
			$out .= '<span>' . dol_escape_htmltag($linkText) . '</span>';
		}
		$out .= '</div>';

		return $out;
	};

	foreach ($preparedRecentRowsPage as $preparedRow) {
		$row = $preparedRow['row'];
		$lineId = (int) $preparedRow['line_id'];
		$nativeLineId = (int) (isset($row['native_bank_line_id']) ? $row['native_bank_line_id'] : 0);
		$lineUrl = dol_buildpath('/custom/kreabank/reconcile.php?mainmenu=bank&leftmenu=kreabank_reconcile&line_id=' . $lineId, 1);
		$nativeLineUrl = ($nativeLineId > 0 ? dol_buildpath('/compta/bank/line.php?rowid=' . $nativeLineId, 1) : '');
		$eventTs = (int) $preparedRow['event_ts'];
		$eventLabel = ($eventTs > 0 ? dol_print_date($eventTs, 'dayhour') : dol_escape_htmltag((string) $preparedRow['event_date']));

		$description = trim((string) (isset($row['description']) ? $row['description'] : ''));
		$amount = (float) (isset($preparedRow['amount']) ? $preparedRow['amount'] : 0.0);
		$currency = (string) (isset($row['currency']) ? $row['currency'] : 'EUR');
		$links = is_array($preparedRow['links']) ? $preparedRow['links'] : array();
		$strategy = trim((string) (isset($row['strategy']) ? $row['strategy'] : 'native'));
		$confidence = (int) (isset($row['confidence_score']) ? $row['confidence_score'] : 0);
		$isReversed = ((int) (isset($row['is_reversed']) ? $row['is_reversed'] : 0) === 1);

		print '<tr class="oddeven krea-history-row-top">';
		print '<td>';
		print '<strong>' . $eventLabel . '</strong><br>';
		print '<span class="krea-history-sub">' . $langs->trans('KreaBankLine') . ': #' . $lineId . '</span>';
		if ($nativeLineUrl !== '') {
			print '<br><span class="krea-history-sub"><a href="' . $nativeLineUrl . '" target="_blank" rel="noopener">' . $nativeEntryLabel . '</a></span>';
		}
		if (!empty($preparedRow['event_login'])) {
			print '<br><span class="krea-history-sub">' . dol_escape_htmltag((string) $preparedRow['event_login']) . '</span>';
		}
		print '</td>';

		print '<td>';
		print '<strong>' . dol_escape_htmltag($description !== '' ? $description : ('#' . $lineId)) . '</strong>';
		print '</td>';
		print '<td class="right nowrap"><strong>' . price($amount) . ' ' . dol_escape_htmltag($currency) . '</strong></td>';

		print '<td>';
		if (empty($links)) {
			print '<span class="opacitymedium">' . $langs->trans('KreaBankNoLinks') . '</span>';
		} else {
			$containsSalaryPayment = false;
			foreach ($links as $candidateLink) {
				if (!is_array($candidateLink)) {
					continue;
				}
				$candidateType = trim((string) (isset($candidateLink['doc_type']) ? $candidateLink['doc_type'] : ''));
				if ($candidateType === 'payment_salary') {
					$containsSalaryPayment = true;
					break;
				}
			}
			if ($containsSalaryPayment) {
				$salaryGroups = array();
				$ungroupedLinks = array();
				$currentGroupIndex = -1;
				foreach ($links as $link) {
					if (!is_array($link)) {
						continue;
					}
					$linkType = trim((string) (isset($link['doc_type']) ? $link['doc_type'] : ''));
					if ($linkType === 'payment_salary') {
						$salaryGroups[] = array(
							'payment_salary' => $link,
							'user' => array(),
							'bank_line' => array(),
							'other' => array(),
						);
						$currentGroupIndex = (count($salaryGroups) - 1);
						continue;
					}
					if ($currentGroupIndex >= 0 && $linkType === 'user' && empty($salaryGroups[$currentGroupIndex]['user'])) {
						$salaryGroups[$currentGroupIndex]['user'] = $link;
						continue;
					}
					if ($currentGroupIndex >= 0 && $linkType === 'bank_line' && empty($salaryGroups[$currentGroupIndex]['bank_line'])) {
						$salaryGroups[$currentGroupIndex]['bank_line'] = $link;
						continue;
					}
					if ($currentGroupIndex >= 0) {
						$salaryGroups[$currentGroupIndex]['other'][] = $link;
						continue;
					}
					$ungroupedLinks[] = $link;
				}

				foreach ($salaryGroups as $salaryGroup) {
					print '<div class="krea-history-salary-group">';
					print $renderLinkedDocument($salaryGroup['payment_salary']);
					if (!empty($salaryGroup['user'])) {
						print $renderLinkedDocument($salaryGroup['user']);
					}
					if (!empty($salaryGroup['bank_line'])) {
						print $renderLinkedDocument($salaryGroup['bank_line']);
					}
					if (!empty($salaryGroup['other'])) {
						foreach ($salaryGroup['other'] as $otherLink) {
							print $renderLinkedDocument($otherLink);
						}
					}
					print '</div>';
				}
				foreach ($ungroupedLinks as $ungroupedLink) {
					print $renderLinkedDocument($ungroupedLink);
				}
			} else {
				foreach ($links as $link) {
					print $renderLinkedDocument($link);
				}
			}
		}
		print '</td>';

		print '<td>';
		print '<span class="badge badge-status4">' . dol_escape_htmltag($strategy) . '</span>';
		if ($confidence > 0) {
			print '<br><span class="krea-history-sub">Score ' . $confidence . '</span>';
		}
		print '</td>';

		if ($isReversed) {
			print '<td><span class="badge badge-status7">' . $langs->trans('KreaBankUndo') . '</span></td>';
		} else {
			print '<td><span class="badge badge-status4">' . $langs->trans('KreaBankStatusReconciled') . '</span></td>';
		}

		print '<td class="center">';
		if ($nativeLineUrl !== '') {
			print '<a class="button smallpaddingimp krea-history-action-btn" href="' . $nativeLineUrl . '" target="_blank" rel="noopener">' . $nativeEntryLabel . '</a>';
		} else {
			print '<a class="button smallpaddingimp krea-history-action-btn" href="' . $lineUrl . '" target="_blank" rel="noopener">' . $langs->trans('KreaBankReconcile') . '</a>';
		}
		if (!$isReversed && $user->hasRight('kreabank', 'reconciliation', 'undo') && $canNativeWrite) {
			$undoFormId = 'kreaUndoForm' . ((int) $lineId);
			print '<br><form method="POST" action="' . dol_escape_htmltag($_SERVER['PHP_SELF']) . '" id="' . $undoFormId . '" style="margin:4px 0 0 0">';
			print '<input type="hidden" name="token" value="' . newToken() . '">';
			print '<input type="hidden" name="mainmenu" value="bank">';
			print '<input type="hidden" name="leftmenu" value="kreabank_history">';
			print '<input type="hidden" name="action" value="undo">';
			print '<input type="hidden" name="reconciliation_id" value="' . $lineId . '">';
			print '<input type="hidden" name="history_page" value="' . ((int) $page) . '">';
			print '<input type="hidden" name="audit_page" value="' . ((int) $auditPage) . '">';
			print '<input type="hidden" name="hist_search" value="' . dol_escape_htmltag($historySearch) . '">';
			print '<input type="hidden" name="hist_description" value="' . dol_escape_htmltag($historyDescription) . '">';
			print '<input type="hidden" name="hist_date_from" value="' . dol_escape_htmltag($historyDateFrom) . '">';
			print '<input type="hidden" name="hist_date_to" value="' . dol_escape_htmltag($historyDateTo) . '">';
			print '<input type="hidden" name="hist_direction" value="' . dol_escape_htmltag($historyDirection) . '">';
			print '<input type="hidden" name="hist_link_type" value="' . dol_escape_htmltag($historyLinkType) . '">';
			print '<input type="hidden" name="hist_audit_type" value="' . dol_escape_htmltag($historyAuditType) . '">';
			print '<input type="hidden" name="audit_search_when_from" value="' . dol_escape_htmltag($auditSearchWhenFrom) . '">';
			print '<input type="hidden" name="audit_search_when_to" value="' . dol_escape_htmltag($auditSearchWhenTo) . '">';
			print '<input type="hidden" name="audit_search_action" value="' . dol_escape_htmltag($auditSearchAction) . '">';
			print '<input type="hidden" name="audit_search_user" value="' . dol_escape_htmltag($auditSearchUser) . '">';
			print '<input type="hidden" name="audit_search_line" value="' . dol_escape_htmltag($auditSearchLine) . '">';
			print '<input type="hidden" name="audit_search_summary" value="' . dol_escape_htmltag($auditSearchSummary) . '">';
			print '<input type="hidden" name="limit" value="' . ((int) $limit) . '">';
			print '<input type="hidden" name="page" value="' . ((int) $page) . '">';
			print '<a class="button smallpaddingimp krea-history-action-btn" href="#" onclick="document.getElementById(\'' . $undoFormId . '\').submit(); return false;">' . $undoActionLabel . '</a>';
			print '<br><input type="text" name="reason" class="flat maxwidth100" style="margin-top:4px" placeholder="' . dol_escape_htmltag($langs->trans('KreaBankReason')) . '">';
			print '</form>';
		}
		print '</td>';

		print '</tr>';
	}

	print '</table>';
	print '</div>';
}

print '<br><h3>' . $langs->trans('KreaBankAuditLog') . '</h3>';
print '<form method="GET" id="kreaAuditFilterForm" action="' . dol_escape_htmltag($_SERVER['PHP_SELF']) . '">';
print '<input type="hidden" name="mainmenu" value="bank">';
print '<input type="hidden" name="leftmenu" value="kreabank_history">';
print '<input type="hidden" name="audit_nav" value="1">';
print '<input type="hidden" name="history_page" value="' . ((int) $page) . '">';
print '<input type="hidden" name="audit_page" value="0">';
print '<input type="hidden" name="page" value="0">';
print '<input type="hidden" name="limit" value="' . ((int) $limit) . '">';
print '<input type="hidden" name="hist_search" value="' . dol_escape_htmltag($historySearch) . '">';
print '<input type="hidden" name="hist_description" value="' . dol_escape_htmltag($historyDescription) . '">';
print '<input type="hidden" name="hist_date_from" value="' . dol_escape_htmltag($historyDateFrom) . '">';
print '<input type="hidden" name="hist_date_to" value="' . dol_escape_htmltag($historyDateTo) . '">';
print '<input type="hidden" name="hist_direction" value="' . dol_escape_htmltag($historyDirection) . '">';
print '<input type="hidden" name="hist_link_type" value="' . dol_escape_htmltag($historyLinkType) . '">';
print '<input type="hidden" name="hist_audit_type" value="' . dol_escape_htmltag($historyAuditType) . '">';
print '</form>';
print '<form method="GET" id="kreaAuditPagerForm" action="' . dol_escape_htmltag($_SERVER['PHP_SELF']) . '">';
print '<input type="hidden" name="mainmenu" value="bank">';
print '<input type="hidden" name="leftmenu" value="kreabank_history">';
print '<input type="hidden" name="audit_nav" value="1">';
print '<input type="hidden" name="history_page" value="' . ((int) $page) . '">';
print '<input type="hidden" name="audit_page" value="' . ((int) $auditPage) . '">';
print '<input type="hidden" name="hist_search" value="' . dol_escape_htmltag($historySearch) . '">';
print '<input type="hidden" name="hist_description" value="' . dol_escape_htmltag($historyDescription) . '">';
print '<input type="hidden" name="hist_date_from" value="' . dol_escape_htmltag($historyDateFrom) . '">';
print '<input type="hidden" name="hist_date_to" value="' . dol_escape_htmltag($historyDateTo) . '">';
print '<input type="hidden" name="hist_direction" value="' . dol_escape_htmltag($historyDirection) . '">';
print '<input type="hidden" name="hist_link_type" value="' . dol_escape_htmltag($historyLinkType) . '">';
print '<input type="hidden" name="hist_audit_type" value="' . dol_escape_htmltag($historyAuditType) . '">';
print '<input type="hidden" name="audit_search_when_from" value="' . dol_escape_htmltag($auditSearchWhenFrom) . '">';
print '<input type="hidden" name="audit_search_when_to" value="' . dol_escape_htmltag($auditSearchWhenTo) . '">';
print '<input type="hidden" name="audit_search_action" value="' . dol_escape_htmltag($auditSearchAction) . '">';
print '<input type="hidden" name="audit_search_user" value="' . dol_escape_htmltag($auditSearchUser) . '">';
print '<input type="hidden" name="audit_search_line" value="' . dol_escape_htmltag($auditSearchLine) . '">';
print '<input type="hidden" name="audit_search_summary" value="' . dol_escape_htmltag($auditSearchSummary) . '">';
print_barre_liste($langs->trans('KreaBankAuditLog'), $auditPage, $_SERVER['PHP_SELF'], $auditParam, '', '', '', $auditPagerNum, $auditTotalRecords, 'title_setup', 0, '', '', $limit, 0, 0, 1);
print '</form>';
if ($auditTotalRecords === 0) {
	print '<div class="opacitymedium">' . $langs->trans('None') . '</div>';
} else {
	print '<div class="div-table-responsive">';
	print '<table class="tagtable nobottomiftotal liste">';
	print '<tr class="liste_titre_filter">';
	print '<td class="liste_titre"><div style="display:flex;gap:4px;flex-direction:column;min-width:130px"><input class="flat" type="date" name="audit_search_when_from" value="' . dol_escape_htmltag($auditSearchWhenFrom) . '" title="' . dol_escape_htmltag($langs->trans('From')) . '" form="kreaAuditFilterForm"><input class="flat" type="date" name="audit_search_when_to" value="' . dol_escape_htmltag($auditSearchWhenTo) . '" title="' . dol_escape_htmltag($langs->trans('To')) . '" form="kreaAuditFilterForm"></div></td>';
	print '<td class="liste_titre"><input class="flat width100" type="text" name="audit_search_action" value="' . dol_escape_htmltag($auditSearchAction) . '" placeholder="' . dol_escape_htmltag($langs->trans('KreaBankAction')) . '" form="kreaAuditFilterForm"></td>';
	print '<td class="liste_titre"><input class="flat width100" type="text" name="audit_search_user" value="' . dol_escape_htmltag($auditSearchUser) . '" placeholder="' . dol_escape_htmltag($langs->trans('KreaBankUser')) . '" form="kreaAuditFilterForm"></td>';
	print '<td class="liste_titre"><input class="flat width75" type="text" name="audit_search_line" value="' . dol_escape_htmltag($auditSearchLine) . '" placeholder="' . dol_escape_htmltag($langs->trans('KreaBankLine')) . '" form="kreaAuditFilterForm"></td>';
	print '<td class="liste_titre"><div style="display:flex;gap:6px;align-items:center"><input class="flat width100" type="text" name="audit_search_summary" value="' . dol_escape_htmltag($auditSearchSummary) . '" placeholder="' . dol_escape_htmltag($langs->trans('KreaBankAuditPayloadSummary')) . '" form="kreaAuditFilterForm"><div class="nowraponall"><button type="submit" class="liste_titre button_search reposition" name="button_search_x" value="x" form="kreaAuditFilterForm"><span class="fas fa-search"></span></button><button type="submit" class="liste_titre button_removefilter reposition" name="button_removefilter_x" value="x" form="kreaAuditFilterForm"><span class="fas fa-times"></span></button></div></div></td>';
	print '</tr>';
	print '<tr class="liste_titre">';
	print_liste_field_titre($langs->trans('KreaBankWhen'), '', '', '', '', '');
	print_liste_field_titre($langs->trans('KreaBankAction'), '', '', '', '', '');
	print_liste_field_titre($langs->trans('KreaBankUser'), '', '', '', '', '');
	print_liste_field_titre($langs->trans('KreaBankLine'), '', '', '', '', '');
	print_liste_field_titre($langs->trans('KreaBankAuditPayloadSummary'), '', '', '', '', '');
	print '</tr>';

	foreach ($preparedAuditRowsPage as $preparedAuditRow) {
		$audit = $preparedAuditRow['audit'];
		$summary = $preparedAuditRow['summary'];
		$statementLineId = (int) (isset($audit['fk_statement_line']) ? $audit['fk_statement_line'] : 0);
		$actionKey = $auditTypeToLangKey((string) (isset($audit['audit_type']) ? $audit['audit_type'] : ''));
		$actionLabel = ($actionKey !== '' ? $langs->trans($actionKey) : '');
		if ($actionLabel === '' || $actionLabel === $actionKey) {
			$actionLabel = (string) (isset($audit['audit_type']) ? $audit['audit_type'] : '');
		}
		$auditDateRaw = (string) (isset($audit['datec']) ? $audit['datec'] : '');
		$auditDateTs = ($auditDateRaw !== '' ? strtotime($auditDateRaw) : false);
		$auditDateLabel = ($auditDateTs !== false && $auditDateTs > 0) ? dol_print_date($auditDateTs, 'dayhour') : '<span class="opacitymedium">-</span>';

		print '<tr class="oddeven">';
		print '<td>' . $auditDateLabel . '</td>';
		print '<td>' . dol_escape_htmltag((string) $actionLabel) . '</td>';
		print '<td>' . dol_escape_htmltag((string) $audit['login']) . '</td>';
		print '<td>' . ($statementLineId > 0 ? '#' . $statementLineId : '<span class="opacitymedium">-</span>') . '</td>';
		print '<td>' . dol_escape_htmltag((string) $summary['summary']) . '</td>';
		print '</tr>';
	}
	print '</table>';
	print '</div>';
}

print dol_get_fiche_end();
llxFooter();
$db->close();
