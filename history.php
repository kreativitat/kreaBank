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
require_once __DIR__ . '/lib/kreabank_history.lib.php';
require_once __DIR__ . '/class/KreaBankNativeBankAdapter.class.php';

$langs->loadLangs(array('main','banks','bills','companies','salaries','compta','trips','donations','loan','errors','kreabank@kreabank'));

if (!isModEnabled('kreabank')) {
	accessforbidden();
}
if (!isModEnabled('banque')) {
	accessforbidden($langs->trans('ModuleBankDisabled'));
}
if (!empty($user->socid) || !$user->hasRight('kreabank', 'reconciliation', 'read')) {
	accessforbidden($langs->trans('KreaBankNoPermission'));
}
if (!$user->hasRight('banque', 'lire')) {
	accessforbidden($langs->trans('ErrorForbidden'));
}

$action = GETPOST('action', 'aZ09');
$navigationAccountId = max(0, GETPOSTINT('account_context'));
$navigationAccount = kreabankNavigationAccount($navigationAccountId);
$historyReader = new KreaBankHistory($db, $conf->entity);
$canNativeWrite = ($user->hasRight('banque', 'modifier') || $user->hasRight('banque', 'consolidate'));
$historyColumns = array('event_ts' => 'KreaBankWhen', 'description' => 'KreaBankDescription', 'amount' => 'KreaBankAmount', 'links' => 'KreaBankLinkedDocuments', 'strategy' => 'Type', 'status' => 'KreaBankStatus');
$auditColumns = array('datec' => 'KreaBankWhen', 'audit_type' => 'KreaBankAction', 'login' => 'KreaBankUser', 'fk_statement_line' => 'KreaBankLine', 'summary' => 'KreaBankAuditPayloadSummary');
$historySort = kreabankListSort($historyColumns, 'event_ts', 'DESC');
$auditSort = kreabankListSort($auditColumns, 'datec', 'DESC', 'audit_');
$nativeEntryLabel = $langs->trans('KreaBankHistoryNativeEntry');
$undoActionLabel = $langs->trans('KreaBankUndo');
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
	$page = max(0, min(5000, $historyPageStored));
	$auditPage = min(5000, $requestPage);
} else {
	$page = min(5000, $requestPage);
	$auditPage = max(0, min(5000, $auditPageStored));
}
$limit = GETPOSTINT('limit');
if ($limit <= 0) {
	$limit = !empty($conf->liste_limit) ? (int) $conf->liste_limit : 25;
}
$limit = max(1, min(1000, $limit));
$offset = $page * $limit;
$auditOffset = $auditPage * $limit;
// A fixed recent window keeps sorted pages stable; never expand it when paging.
$historyPoolLimit = 5000;
$auditPoolLimit = 5000;

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
		$review = kreabankHistoryTakeUndoReview(GETPOST('undo_review', 'alphanum'), $navigationAccountId);
		require_once __DIR__ . '/class/KreaBankService.class.php';
		$service = new KreaBankService($db, $user, $langs);
		$service->undoReconciliation($reconciliationId, $reason, $review);
		setEventMessages($langs->trans('KreaBankUndoDone'), null, 'mesgs');
		$redirect = array_merge(kreabankListParameters(), array('page'=>GETPOSTINT('page'), 'history_page'=>GETPOSTINT('page')));
		header('Location: ' . $_SERVER['PHP_SELF'] . '?' . http_build_query($redirect), true, 303);
		exit;
	} catch (Exception $e) {
		setEventMessages($e->getMessage(), null, 'errors');
	}
}

$historySearch = trim((string) GETPOST('hist_search', 'restricthtml'));
$historyDescription = trim((string) GETPOST('hist_description', 'restricthtml'));
$historyAmount = trim((string) GETPOST('hist_amount', 'none'));
$historyStatus = trim((string) GETPOST('hist_status', 'aZ09')) ?: 'all';
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
$hasListButton = static function ($prefix) {
	foreach (array($prefix, $prefix . '_x', $prefix . '.x') as $name) { if (GETPOSTISSET($name)) { return true; } }
	return false;
};
$jump = GETPOSTISSET('pageplusone') && GETPOSTISSET('pageplusoneold') && GETPOSTINT('pageplusone') !== GETPOSTINT('pageplusoneold');
if ($hasListButton('button_removefilter') || GETPOSTISSET('removefilter')) {
	if (!$isAuditNav) {
		$historySearch = $historyDescription = $historyDateFrom = $historyDateTo = $historyAmount = '';
		$historyDirection = $historyLinkType = $historyAuditType = $historyStatus = 'all';
		$page = $offset = 0;
	} else {
		$auditSearchWhenFrom = $auditSearchWhenTo = $auditSearchAction = $auditSearchUser = $auditSearchLine = $auditSearchSummary = '';
		$auditPage = $auditOffset = 0;
	}
} elseif ($hasListButton('button_search') && !$jump) {
	if ($isAuditNav) { $auditPage = $auditOffset = 0; } else { $page = $offset = 0; }
}

$recent = array();
$auditRows = array();
try {
	$recent = $historyReader->recent($historyPoolLimit, 0, $navigationAccountId);
	if ($navigationAccountId === 0) {
		$auditRows = $historyReader->audits($auditPoolLimit);
	}
} catch (Exception $e) {
	setEventMessages($e->getMessage(), null, 'errors');
}

$normalizeDateToTimestamp = static function ($value, $endOfDay = false) {
	$value = trim((string) $value);
	if ($value === '') { return 0; }
	if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $parts) || !checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
		return -1;
	}
	$ts = strtotime($value . ($endOfDay ? ' 23:59:59' : ' 00:00:00'));
	if ($ts === false || $ts <= 0) {
		return -1;
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
$availableAuditTypes = array();
$availableLinkTypes = array();
$preparedRecentRows = array();
$lineLinksCache = array();
$summaryLinesCount = 0;
$summaryCreditTotal = array();
$summaryDebitTotal = array();
$summaryLinkedDocs = 0;

try {
	$lineLinksCache = $historyReader->links($recent, new KreaBankNativeBankAdapter($db, $user, $langs, $conf->entity));
} catch (Exception $e) {
	setEventMessages($e->getMessage(), null, 'errors');
}

foreach ($recent as $row) {
	$lineId = (int) (isset($row['rowid']) ? $row['rowid'] : 0);
	if ($lineId <= 0) {
		continue;
	}

	$rawLinks = $lineLinksCache[$row['line_source'] . ':' . $lineId] ?? array();
	$strategy = (string) $row['strategy'];
	$strategyLabel = kreabankHistoryCodeLabel($strategy, 'strategy', $langs);
	$availableAuditTypes[$strategy] = true;
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
		$docUrl = kreabankHistoryDocumentUrl($urlPrefix, $docId, (string) ($_SERVER['HTTP_HOST'] ?? ''));
		if ($docType !== '') {
			$availableLinkTypes[$docType] = true;
			$linkTypesForRow[$docType] = true;
		}
		$preparedLinks[] = array(
			'doc_type' => $docType,
			'fk_doc' => $docId,
			'doc_ref' => $docRef,
			'doc_type_label' => kreabankHistoryCodeLabel($docType, 'document', $langs),
			'doc_ref_label' => kreabankHistoryReferenceLabel($docRef, $docId, $langs),
			'doc_url' => $docUrl,
		);
	}
	$auditInfo = $row['history_audit'] ?? array();
	$eventDateRaw = !empty($auditInfo['datec']) ? (string) $auditInfo['datec'] : (string) (isset($row['date_validate']) ? $row['date_validate'] : '');
	$eventTs = strtotime($eventDateRaw);
	if ($eventTs === false || $eventTs <= 0) {
		$eventDateRaw = (string) (isset($row['operation_date']) ? $row['operation_date'] : '');
		$eventTs = strtotime($eventDateRaw);
	}

	$displayStatus = !empty($row['needs_review']) ? 'review' : (!empty($row['is_reversed']) ? 'reversed' : 'reconciled');
	if ($historyStatus !== 'all' && $historyStatus !== $displayStatus) { continue; }
	if (!kreabankHistoryAmountMatches((string) $row['amount'], $historyAmount)) { continue; }
	$amount = (float) (isset($row['amount']) ? $row['amount'] : 0.0);
	$descriptionRaw = (string) (isset($row['description']) ? $row['description'] : '');
	$descriptionLabel = kreabankHistoryNativeLabel($descriptionRaw, $langs);
	$normalizedDescription = kreabankNormalizeText($descriptionRaw . ' ' . $descriptionLabel);
	if ($historyAuditType !== 'all' && $historyAuditType !== $strategy) { continue; }
	if ($historyDirection === 'debit' && !($amount < -0.00001)) {
		continue;
	}
	if ($historyDirection === 'credit' && !($amount > 0.00001)) {
		continue;
	}
	if ($filterDateFromTs < 0 || $filterDateToTs < 0) { continue; }
	if ($filterDateFromTs > 0 && ($eventTs <= 0 || $eventTs < $filterDateFromTs)) {
		continue;
	}
	if ($filterDateToTs > 0 && ($eventTs <= 0 || $eventTs > $filterDateToTs)) {
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
		$descriptionLabel,
		$strategy,
		$strategyLabel,
		(string) $eventDateRaw,
		(string) $amount,
		kreabankPrice($amount),
		(string) (isset($row['currency']) ? $row['currency'] : ''),
		(string) (!empty($auditInfo['audit_type']) ? $auditInfo['audit_type'] : ''),
		kreabankHistoryCodeLabel($auditInfo['audit_type'] ?? '', 'action', $langs),
		(string) (!empty($auditInfo['login']) ? $auditInfo['login'] : ''),
		(string) $lineId,
	);
	foreach ($preparedLinks as $preparedLink) {
		$rowSearchParts[] = (string) $preparedLink['doc_type'];
		$rowSearchParts[] = (string) $preparedLink['doc_ref'];
		$rowSearchParts[] = (string) $preparedLink['doc_type_label'];
		$rowSearchParts[] = (string) $preparedLink['doc_ref_label'];
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
		'strategy_label' => $strategyLabel,
		'description_label' => $descriptionLabel,
		'links' => $preparedLinks,
	);

	$summaryLinesCount++;
	$summaryLinkedDocs += count($preparedLinks);
	if ($amount > 0) {
		$summaryCreditTotal[$row['currency']][] = (string) $row['amount'];
	} elseif ($amount < 0) {
		$summaryDebitTotal[$row['currency']][] = ltrim((string) $row['amount'], '-');
	}
}

$preparedRecentRows = kreabankListOrderRows($preparedRecentRows, $historySort, static function ($item, $field) {
	if ($field === 'links') { return implode(' ', array_map(static function ($link) { return $link['doc_type_label'] . ' ' . $link['doc_ref_label']; }, $item['links'])); }
	if ($field === 'strategy') { return $item['strategy_label']; }
	if ($field === 'description') { return $item['description_label']; }
	if ($field === 'status') { return (int) ($item['row']['needs_review'] ?? 0); }
	return $item[$field] ?? $item['row'][$field] ?? '';
});
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
$availableAuditTypeOptions = array_keys($availableAuditTypes);
foreach (array('document'=>&$availableLinkTypeOptions,'strategy'=>&$availableAuditTypeOptions) as $kind=>&$options) {
	usort($options, static function ($left, $right) use ($kind, $langs) {
		return strnatcasecmp(kreabankHistoryCodeLabel($left,$kind,$langs),kreabankHistoryCodeLabel($right,$kind,$langs)) ?: strcmp($left,$right);
	});
}
unset($options);

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
		$parts[] = kreabankWorklistTranslate($langs,'Type') . ': ' . kreabankHistoryCodeLabel($payload['strategy'],'strategy',$langs);
	}
	if (isset($payload['confidence_score'])) {
		$parts[] = kreabankWorklistTranslate($langs,'KreaBankHistoryScore') . ': ' . ((int) $payload['confidence_score']);
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
			$parts[] = method_exists($langs,'transnoentities') ? $langs->transnoentities('KreaBankHistoryLinksCount',(string) $linkCount) : $langs->trans('KreaBankHistoryLinksCount',(string) $linkCount);
			$parts[] = kreabankWorklistTranslate($langs,'KreaBankAllocate') . ': ' . kreabankPrice($allocated);
		}
		if (!empty($linkTypes)) {
			$parts[] = implode(', ', array_map(static function ($type) use ($langs) { return kreabankHistoryCodeLabel($type,'document',$langs); },array_keys($linkTypes)));
		}
	}
	if (!empty($payload['reason'])) {
		$parts[] = kreabankWorklistTranslate($langs,'KreaBankReason') . ': ' . (string) $payload['reason'];
	}
	if (!empty($payload['quick_entry_id'])) {
		$parts[] = kreabankWorklistTranslate($langs,'KreaBankHistoryActionQuickEntry') . ' #' . ((int) $payload['quick_entry_id']);
	}

	$summary = !empty($parts) ? implode(' | ', $parts) : '-';

	return array(
		'summary' => $summary,
	);
};

$preparedAuditRows = array();
foreach ($auditRows as $auditRow) {
	$auditType = trim((string) (isset($auditRow['audit_type']) ? $auditRow['audit_type'] : ''));
	if ($auditType === 'trigger_event') {
		continue;
	}
	$actionLabel = kreabankHistoryCodeLabel($auditType,'action',$langs);
	if ($normalizedAuditActionSearch !== '' && strpos(kreabankNormalizeText($auditType . ' ' . $actionLabel), $normalizedAuditActionSearch) === false) {
		continue;
	}
	$auditDateRaw = (string) (isset($auditRow['datec']) ? $auditRow['datec'] : '');
	$auditTs = strtotime($auditDateRaw);
	if ($auditFilterDateFromTs < 0 || $auditFilterDateToTs < 0) { continue; }
	if ($auditFilterDateFromTs > 0 && ($auditTs <= 0 || $auditTs < $auditFilterDateFromTs)) {
		continue;
	}
	if ($auditFilterDateToTs > 0 && ($auditTs <= 0 || $auditTs > $auditFilterDateToTs)) {
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
		$auditSummarySearchBlob = kreabankNormalizeText((string) $summary['summary'] . ' ' . (string) ($auditRow['payload_json'] ?? ''));
		if (strpos($auditSummarySearchBlob, $normalizedAuditSummarySearch) === false) {
			continue;
		}
	}
	$auditSearchBlob = kreabankNormalizeText(
		(string) (isset($auditRow['audit_type']) ? $auditRow['audit_type'] : '') . ' ' .
			$actionLabel . ' ' .
			(string) (isset($auditRow['login']) ? $auditRow['login'] : '') . ' ' .
			(string) (isset($summary['summary']) ? $summary['summary'] : '') . ' ' .
			(string) (isset($auditRow['fk_statement_line']) ? $auditRow['fk_statement_line'] : 0) . ' ' .
			(string) (isset($auditRow['datec']) ? $auditRow['datec'] : '') . ' ' . (string) ($auditRow['payload_json'] ?? '')
	);
	if ($normalizedSearch !== '' && strpos($auditSearchBlob, $normalizedSearch) === false) {
		continue;
	}

	$preparedAuditRows[] = array(
		'audit' => $auditRow,
		'summary' => $summary,
		'action_label' => $actionLabel,
	);
}
$preparedAuditRows = kreabankListOrderRows($preparedAuditRows, $auditSort, static function ($item, $field) {
	if ($field === 'audit_type') { return $item['action_label']; }
	return $field === 'summary' ? $item['summary']['summary'] : ($item['audit'][$field] ?? '');
});
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

$historyParam = '&mainmenu=bank&leftmenu=kreabank_history&account_context=' . $navigationAccountId;
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
$auditParam = '&mainmenu=bank&leftmenu=kreabank_history&account_context=' . $navigationAccountId;
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

foreach (array('hist_amount'=>$historyAmount,'hist_status'=>$historyStatus) as $key=>$value) {
	if ($value !== '' && $value !== 'all') { $historyParam .= '&' . $key . '=' . rawurlencode($value); $auditParam .= '&' . $key . '=' . rawurlencode($value); }
}
$sortParams = '&sortfield=' . $historySort['field'] . '&sortorder=' . $historySort['order'] . '&audit_sortfield=' . $auditSort['field'] . '&audit_sortorder=' . $auditSort['order'];
$historyParam .= $sortParams;
$auditParam .= $sortParams;
parse_str(ltrim($historyParam, '&'), $historyHeaderParams);
parse_str(ltrim($auditParam, '&'), $auditHeaderParams);
llxHeader('', $langs->trans('KreaBankHistory'), '', '', 0, 0, '', '', '', 'mod-kreabank page-history bodyforlist');

kreabankPrintWorkspaceNavigation('history', $navigationAccount);
$form = new Form($db);

print '<style>';
print '.krea-history-kpi{display:grid;grid-template-columns:repeat(4,minmax(140px,1fr));gap:8px;margin:0 0 12px 0}';
print '.krea-history-kpi .item{background:#fff;border:1px solid #ddd;border-radius:6px;padding:10px}';
print '.krea-history-kpi .item .label{font-size:11px;color:#666;text-transform:uppercase;letter-spacing:.4px}';
print '.krea-history-kpi .item .value{font-size:20px;font-weight:700;line-height:1.1;margin-top:3px}';
print '.krea-history-link{display:inline-block;margin:2px 4px 2px 0;padding:2px 6px;border-radius:4px;background:#eef6ff;border:1px solid #d2e5ff;font-size:11px}';
print '.krea-history-filter-stack{display:flex;flex-direction:column;gap:4px}';
print '.krea-history-row-top td{vertical-align:top!important}';
print '.krea-history-salary-group{margin:0 0 10px 0;padding:7px 10px;border:1px dashed #d9dee6;border-radius:4px;background:#fbfcfe}';
print '.krea-history-salary-group:last-child{margin-bottom:0}';
print '.krea-history-sub{color:#666;font-size:12px}';
print '.krea-history-pre{max-height:160px;overflow:auto;font-size:11px;line-height:1.35;background:#f7f7f7;border:1px solid #e4e4e4;padding:6px;border-radius:4px;white-space:pre-wrap;word-break:break-word}';
print '.krea-history-row-top .button.krea-history-action-btn{box-sizing:border-box;display:inline-flex!important;align-items:center;justify-content:center;vertical-align:middle;width:112px;height:28px;margin:0!important;padding:3px 8px!important;border:1px solid #4b4b4b;border-radius:3px;background:#4b4b4b;color:#fff!important;font-family:inherit;font-size:11px!important;font-weight:600;line-height:1!important;text-align:center;text-decoration:none;text-transform:uppercase;appearance:none;cursor:pointer}';
print '.krea-history-row-top .button.krea-history-action-btn:hover{background:#353535;border-color:#353535}';
print '.krea-history-row-top .button.krea-history-action-btn.krea-history-undo{background:#9a4b16;border-color:#9a4b16}';
print '.krea-history-row-top .button.krea-history-action-btn.krea-history-undo:hover{background:#7b3c12;border-color:#7b3c12}';
print '.krea-history-row-top .button.krea-history-action-btn:focus-visible{outline:2px solid #1769aa;outline-offset:2px}';
print '.krea-history-row-top .krea-history-reason{box-sizing:border-box;width:112px;height:28px;max-width:112px;min-height:0;margin:4px 0 0;padding:3px 8px;font-family:inherit;font-size:11px;line-height:1}';
print '@media (max-width: 1100px){.krea-history-kpi{grid-template-columns:repeat(2,minmax(140px,1fr))}}';
print '@media (max-width: 700px){.krea-history-kpi{grid-template-columns:1fr}}';
print '</style>';


$renderCurrencyTotals = static function ($totals) use ($conf) {
	if (!$totals) { $totals = array($conf->currency => array('0')); }
	ksort($totals); $parts = array();
	foreach ($totals as $currency => $total) { $parts[] = kreabankPrice(KreaBankPaymentBatchDecimal::sum($total)) . ' ' . dol_escape_htmltag($currency); }
	return implode('<br>', $parts);
};
print '<details class="kb-help"><summary>' . $langs->trans('Summary') . '</summary><div class="krea-history-kpi">';
print '<div class="item"><div class="label">' . $langs->trans('KreaBankHistorySummaryLines') . '</div><div class="value">' . ((int) $summaryLinesCount) . '</div></div>';
print '<div class="item"><div class="label">' . $langs->trans('KreaBankHistorySummaryCredits') . '</div><div class="value">' . $renderCurrencyTotals($summaryCreditTotal) . '</div></div>';
print '<div class="item"><div class="label">' . $langs->trans('KreaBankHistorySummaryDebits') . '</div><div class="value">' . $renderCurrencyTotals($summaryDebitTotal) . '</div></div>';
print '<div class="item"><div class="label">' . $langs->trans('KreaBankHistorySummaryLinks') . '</div><div class="value">' . ((int) $summaryLinkedDocs) . '</div></div>';
print '</div></details>';

print '<form method="GET" id="kreaHistoryFilterForm" action="' . dol_escape_htmltag($_SERVER['PHP_SELF']) . '">';
print kreabankNavigationHiddenFields($navigationAccountId);
foreach (array('sortfield' => $historySort['field'], 'sortorder' => $historySort['order'], 'audit_sortfield' => $auditSort['field'], 'audit_sortorder' => $auditSort['order']) as $key => $value) {
	print '<input type="hidden" name="' . $key . '" value="' . dol_escape_htmltag($value) . '">';
}
print '<input type="hidden" name="mainmenu" value="bank">';
print '<input type="hidden" name="leftmenu" value="kreabank_history">';
print '<input type="hidden" name="history_page" value="' . ((int) $page) . '">';
print '<input type="hidden" name="audit_page" value="' . ((int) $auditPage) . '">';
print '<input type="hidden" name="audit_search_when_from" value="' . dol_escape_htmltag($auditSearchWhenFrom) . '">';
print '<input type="hidden" name="audit_search_when_to" value="' . dol_escape_htmltag($auditSearchWhenTo) . '">';
print '<input type="hidden" name="audit_search_action" value="' . dol_escape_htmltag($auditSearchAction) . '">';
print '<input type="hidden" name="audit_search_user" value="' . dol_escape_htmltag($auditSearchUser) . '">';
print '<input type="hidden" name="audit_search_line" value="' . dol_escape_htmltag($auditSearchLine) . '">';
print '<input type="hidden" name="audit_search_summary" value="' . dol_escape_htmltag($auditSearchSummary) . '">';
$globalSearch = '<input type="search" class="flat maxwidth200" id="kreaHistorySearch" name="hist_search" value="' . dol_escape_htmltag($historySearch) . '" aria-label="' . dol_escape_htmltag($langs->trans('KreaBankHistorySearchPlaceholder')) . '" title="' . dol_escape_htmltag($langs->trans('KreaBankHistorySearchPlaceholder')) . '" placeholder="' . dol_escape_htmltag($langs->trans('Search')) . '">';
print_barre_liste($langs->trans('KreaBankHistory'), $page, $_SERVER['PHP_SELF'], $historyParam, $historySort['field'], $historySort['order'], '', $historyPagerNum, $historyTotalRecords, 'title_setup', 0, $globalSearch, '', $limit, 0, 0, 1);
print '</form>';

print '<div class="div-table-responsive kb-accounts-scroll">';
print '<table id="kreaHistoryTable" class="tagtable nobottomiftotal liste">';
print '<tr class="liste_titre_filter">';
print '<td class="liste_titre">';
print '<div style="display:flex;gap:4px;flex-direction:column;min-width:130px">';
print '<input class="flat" type="date" name="hist_date_from" value="' . dol_escape_htmltag($historyDateFrom) . '" title="' . $langs->trans('From') . '" form="kreaHistoryFilterForm">';
print '<input class="flat" type="date" name="hist_date_to" value="' . dol_escape_htmltag($historyDateTo) . '" title="' . $langs->trans('To') . '" form="kreaHistoryFilterForm">';
print '</div>';
print '</td>';
print '<td class="liste_titre">';
print '<input class="flat minwidth100 maxwidth300" type="text" name="hist_description" value="' . dol_escape_htmltag($historyDescription) . '" placeholder="' . dol_escape_htmltag($langs->trans('KreaBankHistoryDescriptionPlaceholder')) . '" title="' . dol_escape_htmltag($langs->trans('KreaBankDescription')) . '" form="kreaHistoryFilterForm">';
print '</td>';
print '<td class="liste_titre right"><div class="krea-history-filter-stack">';
print '<input class="flat width100 right" type="search" name="hist_amount" value="' . dol_escape_htmltag($historyAmount) . '" aria-label="' . dol_escape_htmltag($langs->trans('KreaBankAmount')) . '" form="kreaHistoryFilterForm">';
print '<select name="hist_direction" class="flat" form="kreaHistoryFilterForm">';
print '<option value="all"' . ($historyDirection === 'all' ? ' selected' : '') . '>' . $langs->trans('KreaBankDirectionAll') . '</option>';
print '<option value="debit"' . ($historyDirection === 'debit' ? ' selected' : '') . '>' . $langs->trans('KreaBankDirectionDebit') . '</option>';
print '<option value="credit"' . ($historyDirection === 'credit' ? ' selected' : '') . '>' . $langs->trans('KreaBankDirectionCredit') . '</option>';
print '</select>';
print '</div>';
print '</td>';
print '<td class="liste_titre">';
print '<select name="hist_link_type" class="flat" form="kreaHistoryFilterForm">';
print '<option value="all"' . ($historyLinkType === 'all' ? ' selected' : '') . '>' . $langs->trans('KreaBankLinkTypeAll') . '</option>';
foreach ($availableLinkTypeOptions as $linkTypeOption) {
	$linkTypeLabel = kreabankHistoryCodeLabel($linkTypeOption,'document',$langs);
	print '<option value="' . dol_escape_htmltag((string) $linkTypeOption) . '"' . ($historyLinkType === $linkTypeOption ? ' selected' : '') . '>' . dol_escape_htmltag($linkTypeLabel) . '</option>';
}
print '</select>';
print '</td>';
print '<td class="liste_titre">';
print '<select name="hist_audit_type" class="flat" form="kreaHistoryFilterForm">';
print '<option value="all"' . ($historyAuditType === 'all' ? ' selected' : '') . '>' . $langs->trans('KreaBankDirectionAll') . '</option>';
foreach ($availableAuditTypeOptions as $auditTypeOption) {
	$auditTypeLabel = kreabankHistoryCodeLabel($auditTypeOption,'strategy',$langs);
	print '<option value="' . dol_escape_htmltag((string) $auditTypeOption) . '"' . ($historyAuditType === $auditTypeOption ? ' selected' : '') . '>' . dol_escape_htmltag((string) $auditTypeLabel) . '</option>';
}
print '</select>';
print '</td>';
print '<td class="liste_titre center"><select class="flat maxwidth150" name="hist_status" aria-label="' . dol_escape_htmltag($langs->trans('KreaBankStatus')) . '" form="kreaHistoryFilterForm">';
foreach (array('all'=>'All','reconciled'=>'KreaBankStatusReconciled','review'=>'KreaBankLinkNeedsReview','reversed'=>'KreaBankUndo') as $value=>$label) {
	print '<option value="' . $value . '"' . ($historyStatus === $value ? ' selected' : '') . '>' . $langs->trans($label) . '</option>';
}
print '</select></td>';
print '<td class="liste_titre center maxwidthsearch">' . kreabankHistoryFilterButtons($form, 'kreaHistoryFilterForm') . '</td>';
print '</tr>';
print '<tr class="liste_titre">';
foreach ($historyColumns as $field => $label) {
	print kreabankHistoryListHeader($langs->trans($label), $field, $historySort, array_merge($historyHeaderParams, array('history_page' => 0, 'audit_page' => $auditPage)), $field === 'amount');
}
print '<th scope="col" class="center">' . $langs->trans('Action') . '</th>';
print '</tr>';

	$renderLinkedDocument = static function ($link) use ($langs) {
		if (!is_array($link)) {
			return '';
		}
		$linkType = trim((string) (isset($link['doc_type']) ? $link['doc_type'] : ''));
		$linkTypeLabel = $link['doc_type_label'] ?? ($linkType !== '' ? kreabankHistoryCodeLabel($linkType,'document',$langs) : kreabankWorklistTranslate($langs,'KreaBankHistoryDocument'));
		$linkRef = trim((string) (isset($link['doc_ref']) ? $link['doc_ref'] : ''));
		$linkDocId = (int) (isset($link['fk_doc']) ? $link['fk_doc'] : 0);
		$linkText = $linkRef !== '' ? ($link['doc_ref_label'] ?? kreabankHistoryReferenceLabel($linkRef,$linkDocId,$langs)) : ('#' . $linkDocId);
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

if ($historyTotalRecords === 0) {
	print '<tr class="oddeven"><td colspan="7"><span class="opacitymedium">' . $langs->trans('None') . '</span></td></tr>';
} else {
	foreach ($preparedRecentRowsPage as $preparedRow) {
		$row = $preparedRow['row'];
		$lineId = (int) $preparedRow['line_id'];
		$nativeLineId = (int) (isset($row['native_bank_line_id']) ? $row['native_bank_line_id'] : 0);
		$lineUrl = dol_buildpath('/custom/kreabank/reconcile.php?mainmenu=bank&leftmenu=kreabank_reconcile&account_context=' . $navigationAccountId . '&line_source=' . $row['line_source'] . '&line_id=' . $lineId, 1);
		$nativeLineUrl = ($nativeLineId > 0 ? dol_buildpath('/compta/bank/line.php?rowid=' . $nativeLineId, 1) : '');
		$eventTs = (int) $preparedRow['event_ts'];
		$eventLabel = ($eventTs > 0 ? dol_print_date($eventTs, 'dayhour') : dol_escape_htmltag((string) $preparedRow['event_date']));

		$description = trim($preparedRow['description_label']);
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
		print '<td class="right nowrap"><strong>' . kreabankPrice($amount) . ' ' . dol_escape_htmltag($currency) . '</strong></td>';

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
		print '<span class="badge badge-status4">' . dol_escape_htmltag($preparedRow['strategy_label']) . '</span>';
		if ($confidence > 0) {
			print '<br><span class="krea-history-sub">' . dol_escape_htmltag(kreabankWorklistTranslate($langs,'KreaBankHistoryScore')) . ' ' . $confidence . '</span>';
		}
		print '</td>';

		if ($row['needs_review']) {
			print '<td><span class="badge badge-status3">' . $langs->trans('KreaBankLinkNeedsReview') . '</span></td>';
		} elseif ($isReversed) {
			print '<td><span class="badge badge-status7">' . $langs->trans('KreaBankUndo') . '</span></td>';
		} else {
			print '<td><span class="badge badge-status4">' . $langs->trans('KreaBankStatusReconciled') . '</span></td>';
		}

		print '<td class="center">';
		if ($nativeLineUrl !== '') {
			print '<a class="button smallpaddingimp krea-history-action-btn" href="' . $nativeLineUrl . '" target="_blank" rel="noopener">' . $nativeEntryLabel . '</a>';
		} elseif (!empty($row['group_id'])) {
			print '<a class="button smallpaddingimp krea-history-action-btn" href="' . dol_buildpath('/custom/kreabank/reconciliationlist.php?search_account=' . $row['bank_account_id'] . '&search_status=all&expand=staged:' . $lineId, 1) . '">' . dol_escape_htmltag(kreabankWorklistTranslate($langs,'KreaBankHistoryReview')) . '</a>';
		} else {
			print '<a class="button smallpaddingimp krea-history-action-btn" href="' . $lineUrl . '" target="_blank" rel="noopener">' . $langs->trans('KreaBankReconcile') . '</a>';
		}
		if (!$isReversed && !$row['needs_review'] && empty($row['group_id']) && $user->hasRight('kreabank', 'reconciliation', 'undo') && $canNativeWrite) {
			$undoFormId = 'kreaUndoForm' . $row['line_source'] . ((int) $lineId);
			print '<br><form method="POST" action="' . dol_escape_htmltag($_SERVER['PHP_SELF']) . '" id="' . $undoFormId . '" style="margin:4px 0 0 0">';
			print kreabankNavigationHiddenFields($navigationAccountId);
			foreach (array('sortfield' => $historySort['field'], 'sortorder' => $historySort['order'], 'audit_sortfield' => $auditSort['field'], 'audit_sortorder' => $auditSort['order']) as $key => $value) {
				print '<input type="hidden" name="' . $key . '" value="' . dol_escape_htmltag($value) . '">';
			}
			print '<input type="hidden" name="token" value="' . newToken() . '">';
			print '<input type="hidden" name="mainmenu" value="bank">';
			print '<input type="hidden" name="leftmenu" value="kreabank_history">';
			print '<input type="hidden" name="action" value="undo">';
			print '<input type="hidden" name="undo_review" value="' . kreabankHistoryUndoReview($row) . '">';
			print '<input type="hidden" name="reconciliation_id" value="' . $lineId . '">';
			print '<input type="hidden" name="history_page" value="' . ((int) $page) . '">';
			print '<input type="hidden" name="audit_page" value="' . ((int) $auditPage) . '">';
			print '<input type="hidden" name="hist_search" value="' . dol_escape_htmltag($historySearch) . '">';
			print '<input type="hidden" name="hist_description" value="' . dol_escape_htmltag($historyDescription) . '">';
print '<input type="hidden" name="hist_amount" value="' . dol_escape_htmltag($historyAmount) . '">';
print '<input type="hidden" name="hist_status" value="' . dol_escape_htmltag($historyStatus) . '">';
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
			print '<button type="submit" class="button smallpaddingimp krea-history-action-btn krea-history-undo">' . $undoActionLabel . '</button>';
			print '<br><input type="text" name="reason" class="flat krea-history-reason" placeholder="' . dol_escape_htmltag($langs->trans('KreaBankReason')) . '">';
			print '</form>';
		}
		print '</td>';

		print '</tr>';
	}
}
print '</table>';
print '</div>';

// The audit log is company-wide and has no reliable account namespace for every event.
if ($navigationAccountId === 0) {
print '<br><h3>' . $langs->trans('KreaBankAuditLog') . '</h3>';
print '<form method="GET" id="kreaAuditFilterForm" action="' . dol_escape_htmltag($_SERVER['PHP_SELF']) . '">';
print kreabankNavigationHiddenFields($navigationAccountId);
print '<input type="hidden" name="limit" value="' . (int) $limit . '">';
foreach (array('sortfield' => $historySort['field'], 'sortorder' => $historySort['order'], 'audit_sortfield' => $auditSort['field'], 'audit_sortorder' => $auditSort['order']) as $key => $value) {
	print '<input type="hidden" name="' . $key . '" value="' . dol_escape_htmltag($value) . '">';
}
print '<input type="hidden" name="mainmenu" value="bank">';
print '<input type="hidden" name="leftmenu" value="kreabank_history">';
print '<input type="hidden" name="audit_nav" value="1">';
print '<input type="hidden" name="history_page" value="' . ((int) $page) . '">';
print '<input type="hidden" name="audit_page" value="' . ((int) $auditPage) . '">';
print '<input type="hidden" name="hist_search" value="' . dol_escape_htmltag($historySearch) . '">';
print '<input type="hidden" name="hist_description" value="' . dol_escape_htmltag($historyDescription) . '">';
print '<input type="hidden" name="hist_amount" value="' . dol_escape_htmltag($historyAmount) . '">';
print '<input type="hidden" name="hist_status" value="' . dol_escape_htmltag($historyStatus) . '">';
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
print '<p class="opacitymedium">' . $langs->trans('KreaBankCompanyAuditHelp') . '</p>';
print_barre_liste($langs->trans('KreaBankAuditLog'), $auditPage, $_SERVER['PHP_SELF'], $auditParam, '', '', '', $auditPagerNum, $auditTotalRecords, 'title_setup', 0, '', '', $limit, -1, 0, 1);
print '</form>';
print '<div class="div-table-responsive kb-accounts-scroll">';
print '<table id="kreaAuditTable" class="tagtable nobottomiftotal liste">';
print '<tr class="liste_titre_filter">';
print '<td class="liste_titre"><div style="display:flex;gap:4px;flex-direction:column;min-width:130px"><input class="flat" type="date" name="audit_search_when_from" value="' . dol_escape_htmltag($auditSearchWhenFrom) . '" title="' . dol_escape_htmltag($langs->trans('From')) . '" form="kreaAuditFilterForm"><input class="flat" type="date" name="audit_search_when_to" value="' . dol_escape_htmltag($auditSearchWhenTo) . '" title="' . dol_escape_htmltag($langs->trans('To')) . '" form="kreaAuditFilterForm"></div></td>';
print '<td class="liste_titre"><input class="flat width100" type="text" name="audit_search_action" value="' . dol_escape_htmltag($auditSearchAction) . '" placeholder="' . dol_escape_htmltag($langs->trans('KreaBankAction')) . '" form="kreaAuditFilterForm"></td>';
print '<td class="liste_titre"><input class="flat width100" type="text" name="audit_search_user" value="' . dol_escape_htmltag($auditSearchUser) . '" placeholder="' . dol_escape_htmltag($langs->trans('KreaBankUser')) . '" form="kreaAuditFilterForm"></td>';
print '<td class="liste_titre"><input class="flat width75" type="text" name="audit_search_line" value="' . dol_escape_htmltag($auditSearchLine) . '" placeholder="' . dol_escape_htmltag($langs->trans('KreaBankLine')) . '" form="kreaAuditFilterForm"></td>';
print '<td class="liste_titre"><input class="flat minwidth100 maxwidth200" type="search" name="audit_search_summary" value="' . dol_escape_htmltag($auditSearchSummary) . '" aria-label="' . dol_escape_htmltag($langs->trans('KreaBankAuditPayloadSummary')) . '" form="kreaAuditFilterForm"></td>';
print '<td class="liste_titre center maxwidthsearch">' . kreabankHistoryFilterButtons($form, 'kreaAuditFilterForm') . '</td>';
print '</tr>';
print '<tr class="liste_titre">';
foreach ($auditColumns as $field => $label) {
	print kreabankHistoryListHeader($langs->trans($label), $field, $auditSort, array_merge($auditHeaderParams, array('history_page' => $page, 'audit_page' => 0)));
}
print '<th></th>';
print '</tr>';

if ($auditTotalRecords === 0) {
	print '<tr class="oddeven"><td colspan="6"><span class="opacitymedium">' . $langs->trans('None') . '</span></td></tr>';
} else {
	foreach ($preparedAuditRowsPage as $preparedAuditRow) {
		$audit = $preparedAuditRow['audit'];
		$summary = $preparedAuditRow['summary'];
		$statementLineId = (int) (isset($audit['fk_statement_line']) ? $audit['fk_statement_line'] : 0);
		$actionLabel = $preparedAuditRow['action_label'];
		$auditDateRaw = (string) (isset($audit['datec']) ? $audit['datec'] : '');
		$auditDateTs = ($auditDateRaw !== '' ? strtotime($auditDateRaw) : false);
		$auditDateLabel = ($auditDateTs !== false && $auditDateTs > 0) ? dol_print_date($auditDateTs, 'dayhour') : '<span class="opacitymedium">-</span>';

		print '<tr class="oddeven">';
		print '<td>' . $auditDateLabel . '</td>';
		print '<td>' . dol_escape_htmltag((string) $actionLabel) . '</td>';
		print '<td>' . dol_escape_htmltag((string) $audit['login']) . '</td>';
		print '<td>' . ($statementLineId > 0 ? '#' . $statementLineId : '<span class="opacitymedium">-</span>') . '</td>';
		print '<td>' . dol_escape_htmltag((string) $summary['summary']) . '</td><td></td>';
		print '</tr>';
	}
}
print '</table>';
print '</div>';

}

print dol_get_fiche_end();
llxFooter();
$db->close();
