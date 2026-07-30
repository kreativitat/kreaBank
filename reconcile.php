<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file       kreabank/reconcile.php
 * \ingroup    kreabank
 * \brief      Dual-pane reconciliation workspace.
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
require_once DOL_DOCUMENT_ROOT . '/core/class/html.form.class.php';

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

$action = GETPOST('action', 'aZ09');
$lineId = GETPOSTINT('line_id');
$openDocumentsSearch = trim((string) GETPOST('open_doc_search', 'restricthtml'));
$forceBatchDetect = (GETPOSTINT('force_batch_detect') > 0 ? 1 : 0);
$service = new KreaBankService($db, $user, $langs);
$form = new Form($db);
$canNativeWrite = ($user->hasRight('banque', 'modifier') || $user->hasRight('banque', 'consolidate'));
$canQuickSupplierInvoice = (isModEnabled('supplier_invoice') && $user->hasRight('fournisseur', 'facture', 'lire') && $user->hasRight('fournisseur', 'facture', 'creer'));
$canQuickTaxEntry = (isModEnabled('tax') && $user->hasRight('tax', 'charges', 'lire') && $user->hasRight('tax', 'charges', 'creer'));
$quickCreateSuccessAlert = array(
	'enabled' => 0,
	'message' => '',
	'url' => '',
	'ref' => '',
);

$safeScore = getDolGlobalInt('KREABANK_AUTOMATCH_SAFE_SCORE');
if ($safeScore <= 0) {
	$safeScore = 150;
}
$minSuggestionScore = getDolGlobalInt('KREABANK_AUTOMATCH_PARTIAL_SCORE');
if ($minSuggestionScore < 0) {
	$minSuggestionScore = 90;
}
$dateTolerance = getDolGlobalInt('KREABANK_AUTOMATCH_DATE_TOLERANCE');
if ($dateTolerance <= 0) {
	$dateTolerance = 3;
}
$batchDetectMinCandidates = (int) getDolGlobalInt('KREABANK_BATCH_MIN_CANDIDATES');
if ($batchDetectMinCandidates <= 0) {
	$batchDetectMinCandidates = 4;
}
$batchDetectMinCoveragePct = (int) getDolGlobalInt('KREABANK_BATCH_MIN_COVERAGE_PCT');
if ($batchDetectMinCoveragePct <= 0) {
	$batchDetectMinCoveragePct = 90;
}
$batchDetectMinCoveragePct = max(10, min(200, $batchDetectMinCoveragePct));
$batchHintKeywordsRaw = implode(',', kreabankGetBatchHintKeywords());

$decodeHtmlEntitiesRecursive = static function ($value, $maxDepth = 4) {
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
	$decoded = trim((string) preg_replace('/\s+/', ' ', $decoded));

	return $decoded;
};

$sanitizeUiErrorMessage = static function ($rawMessage) use ($langs) {
	$message = trim((string) preg_replace('/\s+/', ' ', (string) $rawMessage));
	if ($message === '' || strlen($message) > 500) {
		return (string) $langs->trans('Error');
	}

	$sensitivePatterns = array(
		'/\b(select\s+.+\s+from|insert\s+into|update\s+\S+\s+set|delete\s+from|alter\s+table|drop\s+table|create\s+table)\b/i',
		'/\b(sqlstate|mysql|mariadb|postgres|sqlite|pdo|odbc)\b/i',
		'/\b(unknown column|duplicate entry|constraint|foreign key|syntax error|access denied)\b/i',
		'/\bllx_[a-z0-9_]+\b/i',
		'/\b(backtrace|stack trace|trace:|fatal error)\b/i',
	);
	foreach ($sensitivePatterns as $sensitivePattern) {
		if (preg_match($sensitivePattern, $message)) {
			return (string) $langs->trans('Error');
		}
	}

	return $message;
};

$reportActionException = static function ($exception, $context = '', $safeOverride = '') use ($sanitizeUiErrorMessage, $langs) {
	$rawMessage = '';
	if ($exception instanceof Throwable || $exception instanceof Exception) {
		$rawMessage = (string) $exception->getMessage();
	}

	$context = trim((string) $context);
	if (function_exists('dol_syslog')) {
		$logMessage = 'KreaBank reconcile action error';
		if ($context !== '') {
			$logMessage .= ' [' . $context . ']';
		}
		if ($rawMessage !== '') {
			$logMessage .= ': ' . $rawMessage;
		}
		dol_syslog($logMessage, LOG_ERR);
	}

	$uiMessage = trim((string) $safeOverride);
	if ($uiMessage === '') {
		$uiMessage = (string) $sanitizeUiErrorMessage($rawMessage);
	}
	if ($uiMessage === '') {
		$uiMessage = (string) $langs->trans('Error');
	}

	setEventMessages($uiMessage, null, 'errors');
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	if (!kreabankIsTokenValid()) {
		accessforbidden('Invalid token');
	}
}

if ($action === 'batch_safe' && $user->hasRight('kreabank', 'reconciliation', 'write') && $canNativeWrite) {
	try {
		$result = $service->batchApproveSafe($safeScore, $minSuggestionScore, $dateTolerance, 400);
		if ((int) $result['approved'] > 0) {
			setEventMessages($langs->trans('KreaBankBatchDone') . ' (' . $result['approved'] . '/' . $result['attempted'] . ')', null, 'mesgs');
		} else {
			setEventMessages($langs->trans('KreaBankNoSecureSuggestions'), null, 'warnings');
		}
	} catch (Exception $e) {
		$reportActionException($e, 'batch_safe');
	}
}

if ($action === 'reconcile' && $user->hasRight('kreabank', 'reconciliation', 'write') && $canNativeWrite) {
	$lineId = GETPOSTINT('line_id');
	$selectedDocs = GETPOST('selected_docs', 'array');
	$links = array();
	$lineForReconcile = null;
	$lineOutstandingForReconcile = 0.0;
	if ($lineId > 0) {
		$lineForReconcile = $service->getLineById($lineId);
		if (!empty($lineForReconcile)) {
			$lineOutstandingForReconcile = max(0.0, abs((float) $lineForReconcile['amount']) - abs((float) $lineForReconcile['allocated_amount']));
			if ($lineOutstandingForReconcile <= 0.00001) {
				$lineOutstandingForReconcile = abs((float) $lineForReconcile['amount']);
			}
		}
	}

	if (!empty($selectedDocs) && is_array($selectedDocs)) {
		// Intentionally restricted to native_bank in manual UI submit:
		// the drag/drop/manual workspace only renders native-bank candidates for explicit user assignment.
		// Other doc types remain supported by the service layer through suggestion/auto flows.
		$allowedManualDocTypes = array('native_bank');
		$scoreMap = GETPOST('match_score', 'array');
		$reasonMap = GETPOST('match_reasons', 'array');
		$refMap = GETPOST('doc_ref', 'array');
		$docAmountMap = GETPOST('doc_amount', 'array');
		$sourceLineAmountCache = array();

		foreach ($selectedDocs as $docKey) {
			$docKey = (string) $docKey;
			if (strpos($docKey, '__') === false) {
				continue;
			}
			list($docType, $docIdString) = explode('__', $docKey, 2);
			if (!in_array($docType, $allowedManualDocTypes, true)) {
				continue;
			}
			$docId = (int) $docIdString;
			if ($docId <= 0) {
				continue;
			}

			$allocatedAmount = 0.0;
			if ($docType === 'native_bank') {
				if (is_array($docAmountMap) && isset($docAmountMap[$docKey])) {
					$allocatedAmount = abs((float) price2num((string) $docAmountMap[$docKey], 'MU'));
				}
				if ($allocatedAmount <= 0.00001) {
					if (!array_key_exists($docId, $sourceLineAmountCache)) {
						$sourceLine = $service->getLineById($docId);
						$sourceLineAmountCache[$docId] = (!empty($sourceLine) ? abs((float) $sourceLine['amount']) : 0.0);
					}
					$allocatedAmount = (float) $sourceLineAmountCache[$docId];
				}
			}
			if ($allocatedAmount <= 0.00001) {
				$allocatedAmount = abs((float) $lineOutstandingForReconcile);
			}
			if ($allocatedAmount <= 0.00001) {
				continue;
			}

			$matchReasons = array();
			$rawReasons = (is_array($reasonMap) && isset($reasonMap[$docKey])) ? (string) $reasonMap[$docKey] : '';
			if ($rawReasons !== '') {
				foreach (explode(',', $rawReasons) as $reasonToken) {
					$reasonToken = trim((string) $reasonToken);
					if ($reasonToken !== '') {
						$matchReasons[] = $reasonToken;
					}
				}
			}

			$links[] = array(
				'doc_type' => $docType,
				'fk_doc' => $docId,
				'doc_ref' => (is_array($refMap) && isset($refMap[$docKey])) ? (string) $refMap[$docKey] : '',
				'allocated_amount' => $allocatedAmount,
				'match_score' => (is_array($scoreMap) && isset($scoreMap[$docKey])) ? (int) $scoreMap[$docKey] : 0,
				'match_reasons' => $matchReasons,
			);
		}
	}

	if (!empty($links)) {
		try {
			$maxScore = 0;
			foreach ($links as $l) {
				$maxScore = max($maxScore, (int) $l['match_score']);
			}
			$service->reconcileLine($lineId, $links, 'manual', 0, '', $maxScore);
			setEventMessages($langs->trans('KreaBankReconcileDone'), null, 'mesgs');
		} catch (Exception $e) {
			$reportActionException($e, 'reconcile');
		}
	} else {
		setEventMessages($langs->trans('KreaBankInvalidInput'), null, 'warnings');
	}
}

if ($action === 'quick_assign' && $user->hasRight('kreabank', 'reconciliation', 'write') && $canNativeWrite) {
	$lineId = GETPOSTINT('line_id');
	$docType = trim((string) GETPOST('doc_type', 'alphanohtml'));
	$docId = GETPOSTINT('doc_id');
	$allocatedAmount = abs((float) price2num(GETPOST('allocated_amount', 'alpha'), 'MU'));
	$matchScore = GETPOSTINT('match_score');
	$matchReasons = GETPOST('match_reasons', 'alpha');
	// Same manual UI constraint as reconcile action: quick_assign accepts only native_bank rows.
	$allowedQuickAssignTypes = array('native_bank');
	if (!in_array($docType, $allowedQuickAssignTypes, true)) {
		$docType = '';
	}

	if ($lineId > 0 && $docId > 0 && $allocatedAmount > 0 && !empty($docType)) {
		try {
			$service->reconcileLine($lineId, array(array(
				'doc_type' => $docType,
				'fk_doc' => $docId,
				'allocated_amount' => $allocatedAmount,
				'match_score' => $matchScore,
				'match_reasons' => $matchReasons ? explode(',', $matchReasons) : array('drag_drop'),
			)), 'drag_drop', 0, '', $matchScore);
			setEventMessages($langs->trans('KreaBankReconcileDone'), null, 'mesgs');
		} catch (Exception $e) {
			$reportActionException($e, 'quick_assign');
		}
	} else {
		setEventMessages($langs->trans('KreaBankInvalidInput'), null, 'warnings');
	}
}

if ($action === 'quick_entry' && $user->hasRight('kreabank', 'reconciliation', 'write') && $canNativeWrite) {
	$lineId = GETPOSTINT('line_id');
	$entryType = trim((string) GETPOST('entry_type', 'alphanohtml'));
	$label = GETPOST('entry_label', 'alphanohtml');
	$amount = abs((float) price2num(GETPOST('entry_amount', 'alpha'), 'MU'));
	$submitAction = trim((string) GETPOST('entry_submit_action', 'aZ09'));
	$supplierSocid = GETPOSTINT('entry_supplier_socid');
	$supplierPredictedSocid = GETPOSTINT('entry_supplier_predicted_socid');
	$supplierLookup = trim((string) GETPOST('entry_supplier_lookup', 'alphanohtml'));
	$supplierRef = trim((string) GETPOST('entry_ref_supplier', 'alphanohtml'));
	$note = GETPOST('entry_note', 'restricthtml');
	$invoiceProductLines = array();
	$invoiceProductLinesJson = trim((string) GETPOST('entry_product_lines_json', 'none'));
	if ($invoiceProductLinesJson !== '') {
		$decodedProductLines = json_decode($invoiceProductLinesJson, true);
		if (is_array($decodedProductLines)) {
			foreach ($decodedProductLines as $decodedProductLine) {
				if (!is_array($decodedProductLine)) {
					continue;
				}
				$productId = !empty($decodedProductLine['product_id']) ? (int) $decodedProductLine['product_id'] : 0;
				$productLabel = trim((string) (!empty($decodedProductLine['label']) ? $decodedProductLine['label'] : ''));
				$productQty = abs((float) price2num((string) (!empty($decodedProductLine['qty']) ? $decodedProductLine['qty'] : 0), 'MS'));
				$productAmount = abs((float) price2num((string) (!empty($decodedProductLine['amount']) ? $decodedProductLine['amount'] : 0), 'MU'));
				if ($productId <= 0 && $productQty <= 0.00001 && $productAmount <= 0.00001) {
					continue;
				}
				$invoiceProductLines[] = array(
					'product_id' => $productId,
					'label' => $productLabel,
					'qty' => $productQty,
					'amount' => $productAmount,
				);
			}
		}
	}
	$productLineSlots = GETPOSTINT('entry_product_line_slots');
	if ($productLineSlots <= 0 || $productLineSlots > 12) {
		$productLineSlots = 6;
	}
	if (empty($invoiceProductLines)) {
		for ($productLineIndex = 0; $productLineIndex < $productLineSlots; $productLineIndex++) {
			$productId = GETPOSTINT('entry_product_line_product_' . $productLineIndex);
			$productLabel = trim((string) GETPOST('entry_product_line_label_' . $productLineIndex, 'alphanohtml'));
			$productQty = abs((float) price2num(GETPOST('entry_product_line_qty_' . $productLineIndex, 'alpha'), 'MS'));
			$productAmount = abs((float) price2num(GETPOST('entry_product_line_amount_' . $productLineIndex, 'alpha'), 'MU'));
			if ($productId <= 0 && $productQty <= 0.00001 && $productAmount <= 0.00001) {
				continue;
			}
			$invoiceProductLines[] = array(
				'product_id' => $productId,
				'label' => $productLabel,
				'qty' => $productQty,
				'amount' => $productAmount,
			);
		}
	}
	if (!in_array($entryType, array('expense', 'bank_fee', 'payment', 'tax'), true)) {
		$entryType = 'expense';
	}
	if (!in_array($submitAction, array('payment_entry', 'supplier_invoice', 'tax_entry', 'skip_line'), true)) {
		$submitAction = 'payment_entry';
	}

	if ($submitAction === 'skip_line') {
		$skipReason = trim((string) GETPOST('entry_note', 'alphanohtml'));
		if ($lineId > 0) {
			try {
				$service->skipLine($lineId, $skipReason);
				setEventMessages($langs->trans('KreaBankSkipDone'), null, 'mesgs');
				$lineId = 0;
			} catch (Exception $e) {
				$reportActionException($e, 'quick_entry_skip_line');
			}
		} else {
			setEventMessages($langs->trans('KreaBankInvalidInput'), null, 'warnings');
		}
	} elseif ($lineId > 0 && $label !== '' && $amount > 0) {
		try {
			if ($submitAction === 'supplier_invoice') {
				if (!$canQuickSupplierInvoice) {
					accessforbidden($langs->trans('KreaBankNoPermission'));
				}
				if (empty($invoiceProductLines)) {
					throw new Exception($langs->trans('KreaBankQuickSupplierLinesNoSelection'));
				}
				$resolvedSupplierId = ((int) $supplierSocid > 0 ? (int) $supplierSocid : (int) $supplierPredictedSocid);
				if ($resolvedSupplierId <= 0 && $supplierLookup !== '') {
					$resolvedSupplier = $service->resolveSupplierFromVatOrName($supplierLookup, (int) $supplierPredictedSocid);
					$resolvedSupplierId = !empty($resolvedSupplier['id']) ? (int) $resolvedSupplier['id'] : 0;
				}
				if ($resolvedSupplierId <= 0) {
					throw new Exception($langs->trans('ErrorFieldRequired', $langs->transnoentitiesnoconv('KreaBankQuickSupplierInvoiceSupplier')));
				}
				$created = $service->createQuickSupplierInvoiceAndReconcile($lineId, $label, $amount, $resolvedSupplierId, $note, $supplierRef, $invoiceProductLines);
				$invoiceId = (int) (!empty($created['invoice_id']) ? $created['invoice_id'] : 0);
				$invoiceRef = (string) (!empty($created['invoice_ref']) ? $created['invoice_ref'] : ($invoiceId > 0 ? '#' . $invoiceId : ''));
				$quickCreateSuccessAlert = array(
					'enabled' => 1,
					'message' => (string) $langs->trans('KreaBankQuickSupplierInvoiceDone'),
					'url' => ($invoiceId > 0 ? dol_buildpath('/fourn/facture/card.php?facid=' . $invoiceId, 1) : ''),
					'ref' => $invoiceRef,
				);
			} elseif ($submitAction === 'tax_entry') {
				if (!$canQuickTaxEntry) {
					accessforbidden($langs->trans('KreaBankNoPermission'));
				}
				$created = $service->createQuickTaxContributionAndReconcile($lineId, $label, $amount, $note);
				$taxId = (int) (!empty($created['social_contribution_id']) ? $created['social_contribution_id'] : 0);
				$taxRef = (string) (!empty($created['social_contribution_ref']) ? $created['social_contribution_ref'] : ($taxId > 0 ? '#' . $taxId : ''));
				$quickCreateSuccessAlert = array(
					'enabled' => 1,
					'message' => (string) $langs->trans('KreaBankQuickTaxEntryDone'),
					'url' => ($taxId > 0 ? dol_buildpath('/compta/sociales/card.php?id=' . $taxId, 1) : ''),
					'ref' => $taxRef,
				);
			} else {
				$fkSoc = ((int) $supplierSocid > 0 ? (int) $supplierSocid : 0);
				$service->createQuickEntryAndReconcile($lineId, $entryType ?: 'expense', $label, $amount, $fkSoc, $note);
				setEventMessages($langs->trans('KreaBankQuickEntryDone'), null, 'mesgs');
			}
		} catch (Exception $e) {
			$errorMessage = (string) $e->getMessage();
			if ($errorMessage === 'Unable to resolve supplier from VAT/name lookup or ML prediction.') {
				$errorMessage = $langs->trans('ErrorFieldRequired', $langs->transnoentitiesnoconv('KreaBankQuickSupplierInvoiceSupplier'));
			}
			$reportActionException($e, 'quick_entry', (string) $sanitizeUiErrorMessage($errorMessage));
		}
	} else {
		setEventMessages($langs->trans('KreaBankInvalidInput'), null, 'warnings');
	}
}

if ($action === 'skip_line' && $user->hasRight('kreabank', 'reconciliation', 'write') && $canNativeWrite) {
	$lineId = GETPOSTINT('line_id');
	$skipReason = trim((string) GETPOST('skip_reason', 'alphanohtml'));

	if ($lineId > 0) {
		try {
			$service->skipLine($lineId, $skipReason);
			setEventMessages($langs->trans('KreaBankSkipDone'), null, 'mesgs');
			$lineId = 0; // Continue with next pending line after skipping current one
		} catch (Exception $e) {
			$reportActionException($e, 'skip_line');
		}
	} else {
		setEventMessages($langs->trans('KreaBankInvalidInput'), null, 'warnings');
	}
}

if ($action === 'bulk_skip' && $user->hasRight('kreabank', 'reconciliation', 'write') && $canNativeWrite) {
	$selectedLineIds = GETPOST('selected_line_ids', 'array');
	$selectedLineIdsCsv = trim((string) GETPOST('selected_line_ids_csv', 'alphanohtml'));
	$skipReason = trim((string) GETPOST('skip_reason', 'alphanohtml'));
	$lineIdsToSkip = array();

	if ($selectedLineIdsCsv !== '') {
		foreach (explode(',', $selectedLineIdsCsv) as $selectedLineId) {
			$selectedLineId = (int) trim((string) $selectedLineId);
			if ($selectedLineId > 0) {
				$lineIdsToSkip[$selectedLineId] = $selectedLineId;
			}
		}
	}

	if (is_array($selectedLineIds)) {
		foreach ($selectedLineIds as $selectedLineId) {
			$selectedLineId = (int) $selectedLineId;
			if ($selectedLineId > 0) {
				$lineIdsToSkip[$selectedLineId] = $selectedLineId;
			}
		}
	}
	if (empty($lineIdsToSkip) && $lineId > 0) {
		$lineIdsToSkip[$lineId] = $lineId;
	}

	if (empty($lineIdsToSkip)) {
		setEventMessages($langs->trans('KreaBankNoLinesSelected'), null, 'warnings');
	} else {
		$attempted = count($lineIdsToSkip);
		$skipped = 0;
		$errors = array();

		foreach ($lineIdsToSkip as $selectedLineId) {
			try {
				$service->skipLine((int) $selectedLineId, $skipReason);
				$skipped++;
			} catch (Exception $e) {
				$errors[] = (string) $sanitizeUiErrorMessage($e->getMessage());
				if (function_exists('dol_syslog')) {
					dol_syslog('KreaBank reconcile action error [bulk_skip]: ' . ((string) $e->getMessage()), LOG_ERR);
				}
			}
		}

		if ($skipped > 0 && empty($errors)) {
			setEventMessages($langs->trans('KreaBankBulkSkipDone', (string) $skipped), null, 'mesgs');
			$lineId = 0;
		} elseif ($skipped > 0) {
			$firstError = (!empty($errors[0]) ? (string) $errors[0] : (string) $langs->trans('Error'));
			setEventMessages($langs->trans('KreaBankBulkSkipPartial', (string) $skipped, (string) $attempted), null, 'warnings');
			setEventMessages($langs->trans('Error') . ': ' . $firstError, null, 'errors');
			$lineId = 0;
		} else {
			$firstError = (!empty($errors[0]) ? (string) $errors[0] : (string) $langs->trans('Error'));
			setEventMessages($langs->trans('Error') . ': ' . $firstError, null, 'errors');
		}
	}
}

$pendingLines = array();
$pendingLinesTotal = 0;
$selectedLine = null;
$documents = array();
$suggestions = array();
$lineLinks = array();
$isLoteBatchLine = false;
$batchDetectWasForced = ($forceBatchDetect === 1);
$batchDetectedAuto = false;
$batchDetectedMl = false;
$batchMlProbabilityPct = 0;
$loteBatchDate = '';
$loteAllowedNativeTypes = array(
	'payment_salary' => true,
	'user' => true,
);
$normalizeDateYmd = static function ($value) {
	$value = trim((string) $value);
	if ($value === '') {
		return '';
	}

	$ts = strtotime($value);
	if ($ts === false || $ts <= 0) {
		return '';
	}

	return dol_print_date($ts, '%Y-%m-%d');
};
$isBatchPaymentDocument = static function ($document) use ($loteAllowedNativeTypes) {
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
		if (strpos($urlTypeToken, 'payment') === 0 || !empty($loteAllowedNativeTypes[$urlTypeToken])) {
			return true;
		}
	}

	return false;
};
$shouldAutoDetectBatchFromDocuments = static function ($line, $documents) use ($normalizeDateYmd, $isBatchPaymentDocument, $batchDetectMinCandidates, $batchDetectMinCoveragePct) {
	if (empty($line) || !is_array($line) || empty($documents) || !is_array($documents)) {
		return false;
	}

	$lineDate = $normalizeDateYmd(!empty($line['operation_date']) ? (string) $line['operation_date'] : (!empty($line['value_date']) ? (string) $line['value_date'] : ''));
	if ($lineDate === '') {
		return false;
	}
	$outstanding = max(0.0, abs((float) $line['amount']) - abs((float) $line['allocated_amount']));
	if ($outstanding <= 0.00001) {
		$outstanding = abs((float) $line['amount']);
	}
	$targetCents = (int) round($outstanding * 100);
	if ($targetCents <= 0) {
		return false;
	}

	$candidateCount = 0;
	$candidateTotalCents = 0;
	foreach ($documents as $document) {
		if (!$isBatchPaymentDocument($document)) {
			continue;
		}
		$docDate = $normalizeDateYmd(isset($document['doc_date']) ? (string) $document['doc_date'] : '');
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

	if ($candidateCount < (int) $batchDetectMinCandidates) {
		// Controlled by setup: KREABANK_BATCH_MIN_CANDIDATES.
		return false;
	}

	$coveragePct = (int) floor(($candidateTotalCents * 100) / max(1, $targetCents));

	// Controlled by setup: KREABANK_BATCH_MIN_COVERAGE_PCT.
	return ($coveragePct >= (int) $batchDetectMinCoveragePct);
};

$loadLineCandidates = static function ($line, $limit, $intervalDays, $anchorDate) use ($service, $dateTolerance) {
	if (empty($line) || !is_array($line)) {
		return array('documents' => array(), 'suggestions' => array());
	}

	$lineIdValue = (int) (!empty($line['rowid']) ? $line['rowid'] : 0);
	$directionValue = (int) (!empty($line['direction']) ? $line['direction'] : 0);
	$bankAccountIdValue = (int) (!empty($line['bank_account_id']) ? $line['bank_account_id'] : 0);

	return array(
		'documents' => $service->getOpenDocuments($directionValue, (int) $limit, $anchorDate, $intervalDays, $lineIdValue, $bankAccountIdValue, false),
		'suggestions' => $service->getSuggestionsForLine($lineIdValue, 0, $dateTolerance, $intervalDays, false),
	);
};

try {
	$requestedLineId = (int) $lineId;
	$requestedLine = null;
	if ($requestedLineId > 0) {
		$requestedLine = $service->getLineById($requestedLineId);
	}

	$pendingLines = $service->getPendingLines(200, 0);
	$pendingLinesTotal = $service->getPendingLinesCount();
	if ($pendingLinesTotal <= 0) {
		$pendingLinesTotal = count($pendingLines);
	}
	$pendingLineIds = array();
	foreach ($pendingLines as $pendingLine) {
		$pendingLineIds[(int) $pendingLine['rowid']] = true;
	}
	if (!empty($requestedLine) && (int) $requestedLine['status'] === 3 && empty($pendingLineIds[$requestedLineId])) {
		array_unshift($pendingLines, $requestedLine);
		$pendingLineIds[$requestedLineId] = true;
		$pendingLinesTotal++;
	}

	if (!empty($requestedLine) && (int) $requestedLine['status'] !== 2) {
		$lineId = $requestedLineId;
		$selectedLine = $requestedLine;
	} elseif (!empty($pendingLines) && ($lineId <= 0 || empty($pendingLineIds[(int) $lineId]))) {
		$lineId = (int) $pendingLines[0]['rowid'];
	}

	if (!$selectedLine && $lineId > 0) {
		$selectedLine = $service->getLineById($lineId);
	}
	if (!$selectedLine && !empty($pendingLines)) {
		$lineId = (int) $pendingLines[0]['rowid'];
		$selectedLine = $service->getLineById($lineId);
	}

	if ($selectedLine) {
		$isLoteBatchLine = ($batchDetectWasForced || kreabankIsBatchLikeLine($selectedLine, $batchHintKeywordsRaw));
		$loteBatchDate = $normalizeDateYmd(!empty($selectedLine['operation_date']) ? (string) $selectedLine['operation_date'] : (!empty($selectedLine['value_date']) ? (string) $selectedLine['value_date'] : ''));
		$openDocsAnchorDate = !empty($selectedLine['operation_date']) ? (string) $selectedLine['operation_date'] : (!empty($selectedLine['value_date']) ? (string) $selectedLine['value_date'] : null);
		$openDocsIntervalDays = (int) getDolGlobalInt('KREABANK_OPEN_DOC_DATE_INTERVAL_DAYS', 7);
		if ($openDocsIntervalDays < 0) {
			$openDocsIntervalDays = 0;
		}
		$openDocsLimit = ($isLoteBatchLine ? 2000 : 300);
		if ($openDocumentsSearch !== '') {
			// In search mode, use a broader date window so delayed/older docs can still be found.
			$openDocsIntervalDays = max($openDocsIntervalDays, 730);
			$openDocsLimit = max($openDocsLimit, 1500);
		}
		$candidateSet = $loadLineCandidates($selectedLine, $openDocsLimit, $openDocsIntervalDays, $openDocsAnchorDate);
		$documents = (array) $candidateSet['documents'];
		$suggestions = (array) $candidateSet['suggestions'];
		$lineLinks = $service->getLineLinks((int) $selectedLine['rowid']);
		if (!$isLoteBatchLine) {
			$batchMlPrediction = $service->predictBatchMl($selectedLine, $documents);
			if (!empty($batchMlPrediction['enabled']) && !empty($batchMlPrediction['is_batch'])) {
				$batchDetectedMl = true;
				$batchMlProbabilityPct = (int) (isset($batchMlPrediction['probability_pct']) ? $batchMlPrediction['probability_pct'] : 0);
				$isLoteBatchLine = true;
				if ($openDocsLimit < 2000) {
					$openDocsLimit = 2000;
					$candidateSet = $loadLineCandidates($selectedLine, $openDocsLimit, $openDocsIntervalDays, $openDocsAnchorDate);
					$documents = (array) $candidateSet['documents'];
					$suggestions = (array) $candidateSet['suggestions'];
				}
			}
		}
		if (!$isLoteBatchLine && $shouldAutoDetectBatchFromDocuments($selectedLine, $documents)) {
			$batchDetectedAuto = true;
			$isLoteBatchLine = true;
			if ($openDocsLimit < 2000) {
				$openDocsLimit = 2000;
				$candidateSet = $loadLineCandidates($selectedLine, $openDocsLimit, $openDocsIntervalDays, $openDocsAnchorDate);
				$documents = (array) $candidateSet['documents'];
				$suggestions = (array) $candidateSet['suggestions'];
			}
		}
		if (empty($documents) && empty($suggestions) && $openDocsIntervalDays < 3650) {
			$historicalOpenDocsLimit = max($openDocsLimit, 2000);
			$candidateSet = $loadLineCandidates($selectedLine, $historicalOpenDocsLimit, 3650, $openDocsAnchorDate);
			$documents = (array) $candidateSet['documents'];
			$suggestions = (array) $candidateSet['suggestions'];
		}

		if ($isLoteBatchLine) {
			$documents = array_values(array_filter($documents, static function ($document) use ($normalizeDateYmd, $loteBatchDate, $isBatchPaymentDocument) {
				if (!$isBatchPaymentDocument($document)) {
					return false;
				}
				$docDate = $normalizeDateYmd(isset($document['doc_date']) ? (string) $document['doc_date'] : '');
				if ($loteBatchDate !== '' && $docDate !== $loteBatchDate) {
					return false;
				}
				return true;
			}));

			$allowedSuggestionDocIds = array();
			foreach ($documents as $documentCandidate) {
				$allowedSuggestionDocIds[(int) $documentCandidate['rowid']] = true;
			}
			$suggestions = array_values(array_filter($suggestions, static function ($suggestion) use ($allowedSuggestionDocIds) {
				$docId = (int) (isset($suggestion['doc_id']) ? $suggestion['doc_id'] : 0);

				return !empty($allowedSuggestionDocIds[$docId]);
			}));
		}
	}
} catch (Exception $e) {
	$reportActionException($e, 'page_load');
}

$suggestionByKey = array();
foreach ($suggestions as $suggestion) {
	$key = $suggestion['doc_type'] . '__' . $suggestion['doc_id'];
	$suggestionByKey[$key] = $suggestion;
}

$autoSelectDocKeys = array();
$loteSubsetSuggestion = array(
	'exact' => false,
	'difference_cents' => 0,
	'target_cents' => 0,
	'selected_cents' => 0,
	'selected_count' => 0,
	'candidate_count' => 0,
	'keys' => array(),
);
$findSubsetForTargetAmount = static function ($documentCandidates, $targetAmountCents) {
	$targetAmountCents = (int) $targetAmountCents;
	if ($targetAmountCents <= 0) {
		return array(
			'exact' => false,
			'difference_cents' => 0,
			'target_cents' => 0,
			'selected_cents' => 0,
			'selected_count' => 0,
			'candidate_count' => 0,
			'keys' => array(),
		);
	}

	$candidatePool = array();
	foreach ($documentCandidates as $documentCandidate) {
		if (!empty($documentCandidate['is_locked'])) {
			continue;
		}
		$docType = (string) (isset($documentCandidate['doc_type']) ? $documentCandidate['doc_type'] : '');
		$docRowid = (int) (isset($documentCandidate['rowid']) ? $documentCandidate['rowid'] : 0);
		if ($docType === '' || $docRowid <= 0) {
			continue;
		}
		$docAmountCents = (int) round(abs((float) (isset($documentCandidate['amount_open']) ? $documentCandidate['amount_open'] : 0.0)) * 100);
		if ($docAmountCents <= 0 || $docAmountCents > $targetAmountCents) {
			continue;
		}

		$candidatePool[] = array(
			'doc_key' => $docType . '__' . $docRowid,
			'amount_cents' => $docAmountCents,
		);
	}

	if (empty($candidatePool)) {
		return array(
			'exact' => false,
			'difference_cents' => $targetAmountCents,
			'target_cents' => $targetAmountCents,
			'selected_cents' => 0,
			'selected_count' => 0,
			'candidate_count' => 0,
			'keys' => array(),
		);
	}

	// Keep DP input bounded for large batch lines to avoid pathological memory usage.
	usort($candidatePool, static function ($a, $b) {
		$amountA = (int) (isset($a['amount_cents']) ? $a['amount_cents'] : 0);
		$amountB = (int) (isset($b['amount_cents']) ? $b['amount_cents'] : 0);
		if ($amountB !== $amountA) {
			return $amountB <=> $amountA;
		}

		return strcmp((string) (isset($a['doc_key']) ? $a['doc_key'] : ''), (string) (isset($b['doc_key']) ? $b['doc_key'] : ''));
	});
	$maxCandidatePool = 600;
	if (count($candidatePool) > $maxCandidatePool) {
		$candidatePool = array_slice($candidatePool, 0, $maxCandidatePool);
	}

	$candidateCount = count($candidatePool);
	$maxStates = 50000;
	if ($candidateCount > 150) {
		$maxStates = 20000;
	}
	if ($candidateCount > 250) {
		$maxStates = 12000;
	}
	if ($candidateCount > 350) {
		$maxStates = 8000;
	}

	$maxPathKeys = 80;
	if ($candidateCount > 150) {
		$maxPathKeys = 64;
	}
	if ($candidateCount > 250) {
		$maxPathKeys = 48;
	}
	if ($candidateCount > 350) {
		$maxPathKeys = 36;
	}

	$paths = array(0 => array());
	foreach ($candidatePool as $docIndex => $candidateEntry) {
		$docAmountCents = (int) (isset($candidateEntry['amount_cents']) ? $candidateEntry['amount_cents'] : 0);
		$docKey = (string) (isset($candidateEntry['doc_key']) ? $candidateEntry['doc_key'] : '');
		if ($docAmountCents <= 0 || $docKey === '') {
			continue;
		}
		if ($docAmountCents === $targetAmountCents) {
			return array(
				'exact' => true,
				'difference_cents' => 0,
				'target_cents' => $targetAmountCents,
				'selected_cents' => $targetAmountCents,
				'selected_count' => 1,
				'candidate_count' => $candidateCount,
				'keys' => array($docKey),
			);
		}

		$currentSums = array_keys($paths);
		rsort($currentSums, SORT_NUMERIC);
		$newPaths = $paths;
		foreach ($currentSums as $sumAmount) {
			$nextAmount = (int) $sumAmount + $docAmountCents;
			if ($nextAmount > $targetAmountCents || isset($newPaths[$nextAmount])) {
				continue;
			}

			$nextPath = $paths[(int) $sumAmount];
			if (count($nextPath) >= $maxPathKeys) {
				continue;
			}
			$nextPath[] = (int) $docIndex;
			$newPaths[$nextAmount] = $nextPath;
			if ($nextAmount === $targetAmountCents) {
				$selectedKeys = array();
				foreach ($nextPath as $selectedDocIndex) {
					$selectedDocIndex = (int) $selectedDocIndex;
					if (isset($candidatePool[$selectedDocIndex]['doc_key'])) {
						$selectedKeys[] = (string) $candidatePool[$selectedDocIndex]['doc_key'];
					}
				}
				return array(
					'exact' => true,
					'difference_cents' => 0,
					'target_cents' => $targetAmountCents,
					'selected_cents' => $targetAmountCents,
					'selected_count' => count($selectedKeys),
					'candidate_count' => $candidateCount,
					'keys' => $selectedKeys,
				);
			}
		}
		$paths = $newPaths;
		if (count($paths) > $maxStates) {
			$stateSums = array_keys($paths);
			usort($stateSums, static function ($a, $b) use ($targetAmountCents) {
				$da = abs((int) $targetAmountCents - (int) $a);
				$db = abs((int) $targetAmountCents - (int) $b);
				if ($da !== $db) {
					return $da <=> $db;
				}

				return ((int) $b <=> (int) $a);
			});
			$stateSums = array_slice($stateSums, 0, $maxStates);
			$trimmedPaths = array();
			foreach ($stateSums as $stateSum) {
				$trimmedPaths[(int) $stateSum] = $paths[(int) $stateSum];
			}
			$paths = $trimmedPaths;
		}
	}

	$bestSum = 0;
	$bestDiff = PHP_INT_MAX;
	foreach ($paths as $sumAmount => $sumPath) {
		$diff = abs((int) $targetAmountCents - (int) $sumAmount);
		if ($diff < $bestDiff) {
			$bestDiff = $diff;
			$bestSum = (int) $sumAmount;
			continue;
		}
		if ($diff === $bestDiff && (int) $sumAmount > $bestSum) {
			$bestSum = (int) $sumAmount;
		}
	}
	$bestIndexes = (!empty($paths[$bestSum]) && is_array($paths[$bestSum])) ? $paths[$bestSum] : array();
	$bestKeys = array();
	foreach ($bestIndexes as $bestDocIndex) {
		$bestDocIndex = (int) $bestDocIndex;
		if (isset($candidatePool[$bestDocIndex]['doc_key'])) {
			$bestKeys[] = (string) $candidatePool[$bestDocIndex]['doc_key'];
		}
	}

	return array(
		'exact' => ($bestDiff === 0),
		'difference_cents' => (int) $bestDiff,
		'target_cents' => $targetAmountCents,
		'selected_cents' => $bestSum,
		'selected_count' => count($bestKeys),
		'candidate_count' => $candidateCount,
		'keys' => $bestKeys,
	);
};
if (!$isLoteBatchLine && !empty($suggestions)) {
	$safeSuggestion = $service->getSafeSuggestion($suggestions, $safeScore);
	if (is_array($safeSuggestion) && (string) $safeSuggestion['doc_type'] === 'native_bank') {
		$safeKey = (string) $safeSuggestion['doc_type'] . '__' . ((int) $safeSuggestion['doc_id']);
		$autoSelectDocKeys[$safeKey] = true;
	}
}

if (!empty($documents)) {
	usort($documents, static function ($a, $b) use ($suggestionByKey) {
		$ka = $a['doc_type'] . '__' . $a['rowid'];
		$kb = $b['doc_type'] . '__' . $b['rowid'];
		$sa = isset($suggestionByKey[$ka]) ? (int) $suggestionByKey[$ka]['score'] : 0;
		$sb = isset($suggestionByKey[$kb]) ? (int) $suggestionByKey[$kb]['score'] : 0;

		if ($sb !== $sa) {
			return $sb <=> $sa;
		}

		$typePriority = array(
			'native_bank' => 0,
			'payment' => 0,
			'payment_supplier' => 0,
			'payment_linked' => 1,
			'payment_supplier_linked' => 1,
			'customer_invoice' => 2,
			'supplier_invoice' => 2,
		);
		$pa = isset($typePriority[$a['doc_type']]) ? (int) $typePriority[$a['doc_type']] : 9;
		$pb = isset($typePriority[$b['doc_type']]) ? (int) $typePriority[$b['doc_type']] : 9;
		if ($pa !== $pb) {
			return $pa <=> $pb;
		}

		return strcmp((string) $a['ref'], (string) $b['ref']);
	});
}

if (!empty($documents)) {
	$documents = array_values(array_filter($documents, static function ($documentCandidate) use ($suggestionByKey, $isLoteBatchLine) {
		if (!empty($isLoteBatchLine)) {
			return true;
		}
		$documentKey = (string) $documentCandidate['doc_type'] . '__' . ((int) $documentCandidate['rowid']);
		$documentScore = isset($suggestionByKey[$documentKey]) ? (int) $suggestionByKey[$documentKey]['score'] : 0;

		return ($documentScore > 0);
	}));
}

$documentsBeforeSearchCount = count($documents);
if ($openDocumentsSearch !== '' && !empty($documents)) {
	$addDateVariants = static function ($candidate, callable $pushToken) {
		$candidate = trim((string) $candidate);
		if ($candidate === '' || !preg_match('/^\d{1,4}[\/\.-]\d{1,2}[\/\.-]\d{1,4}$/', $candidate)) {
			return;
		}

		$parts = preg_split('/[\/\.-]/', $candidate);
		if (!is_array($parts) || count($parts) !== 3) {
			return;
		}

		$p1 = (int) $parts[0];
		$p2 = (int) $parts[1];
		$p3 = (int) $parts[2];
		$tuples = array();

		if (strlen($parts[0]) === 4) {
			$tuples[] = array($p1, $p2, $p3); // YYYY-MM-DD
		}
		if (strlen($parts[2]) === 4) {
			$tuples[] = array($p3, $p2, $p1); // DD-MM-YYYY
			$tuples[] = array($p3, $p1, $p2); // MM-DD-YYYY
		}
		if (strlen($parts[2]) === 2) {
			$year = $p3 + ($p3 >= 70 ? 1900 : 2000);
			$tuples[] = array($year, $p2, $p1); // DD-MM-YY
			$tuples[] = array($year, $p1, $p2); // MM-DD-YY
		}

		foreach ($tuples as $tuple) {
			$year = (int) $tuple[0];
			$month = (int) $tuple[1];
			$day = (int) $tuple[2];
			if (!checkdate($month, $day, $year)) {
				continue;
			}

			$pushToken(sprintf('%04d-%02d-%02d', $year, $month, $day));
			$pushToken(sprintf('%04d/%02d/%02d', $year, $month, $day));
			$pushToken(sprintf('%02d-%02d-%04d', $day, $month, $year));
			$pushToken(sprintf('%02d/%02d/%04d', $day, $month, $year));
			$pushToken(sprintf('%04d%02d%02d', $year, $month, $day));
			$pushToken(sprintf('%02d%02d%04d', $day, $month, $year));
		}
	};

	$searchTokensMap = array();
	$pushSearchToken = static function ($value) use (&$searchTokensMap) {
		$value = trim((string) $value);
		if ($value === '') {
			return;
		}

		$normalized = kreabankNormalizeText($value);
		if ($normalized !== '') {
			$searchTokensMap[$normalized] = true;
		}

		$digitsOnly = preg_replace('/\D+/', '', $value);
		if (is_string($digitsOnly) && strlen($digitsOnly) >= 4) {
			$searchTokensMap[$digitsOnly] = true;
		}
	};

	$rawQueryTokens = preg_split('/\s+/', trim((string) $openDocumentsSearch));
	if (is_array($rawQueryTokens)) {
		foreach ($rawQueryTokens as $rawToken) {
			$pushSearchToken($rawToken);
			$addDateVariants($rawToken, $pushSearchToken);
		}
	}

	if (preg_match_all('/\d{1,4}[\/\.-]\d{1,2}[\/\.-]\d{1,4}/u', (string) $openDocumentsSearch, $dateMatches) && !empty($dateMatches[0])) {
		foreach ($dateMatches[0] as $dateCandidate) {
			$addDateVariants((string) $dateCandidate, $pushSearchToken);
		}
	}

	$searchTokens = array_keys($searchTokensMap);
	if (!empty($searchTokens)) {
		$documents = array_values(array_filter($documents, static function ($doc) use ($searchTokens, $addDateVariants) {
			$amount = (float) (isset($doc['amount_open']) ? $doc['amount_open'] : 0.0);
			$haystackTokensMap = array();
			$pushHaystackToken = static function ($value) use (&$haystackTokensMap) {
				$value = trim((string) $value);
				if ($value === '') {
					return;
				}

				$normalized = kreabankNormalizeText($value);
				if ($normalized !== '') {
					$haystackTokensMap[$normalized] = true;
				}

				$digitsOnly = preg_replace('/\D+/', '', $value);
				if (is_string($digitsOnly) && strlen($digitsOnly) >= 4) {
					$haystackTokensMap[$digitsOnly] = true;
				}
			};

			foreach ((array) $doc as $value) {
				if (!is_scalar($value)) {
					continue;
				}
				$stringValue = (string) $value;
				$pushHaystackToken($stringValue);
				$addDateVariants($stringValue, $pushHaystackToken);
			}

			$pushHaystackToken((string) $amount);
			$pushHaystackToken((string) price2num((string) $amount, 'MU'));
			$pushHaystackToken(number_format($amount, 2, '.', ''));
			$pushHaystackToken(number_format($amount, 2, ',', ''));

			$text = implode(' ', array_keys($haystackTokensMap));
			foreach ($searchTokens as $token) {
				$token = (string) $token;
				if ($token === '') {
					continue;
				}

				if (strpos($text, $token) !== false) {
					continue;
				}

				// Numeric searches must be sign-insensitive: -400 should match 400 and vice-versa.
				$unsignedToken = preg_replace('/^[\+\-]+/', '', $token);
				if (is_string($unsignedToken) && $unsignedToken !== '' && $unsignedToken !== $token && strpos($text, $unsignedToken) !== false) {
					continue;
				}

				return false;
			}

			return true;
		}));
	}
}

if ($isLoteBatchLine && $selectedLine && !empty($documents)) {
	$loteOutstanding = max(0.0, abs((float) $selectedLine['amount']) - abs((float) $selectedLine['allocated_amount']));
	if ($loteOutstanding <= 0.00001) {
		$loteOutstanding = abs((float) $selectedLine['amount']);
	}
	$loteTargetCents = (int) round($loteOutstanding * 100);
	if ($loteTargetCents > 0) {
		$loteSubsetSuggestion = $findSubsetForTargetAmount($documents, $loteTargetCents);
		if (!empty($loteSubsetSuggestion['keys']) && is_array($loteSubsetSuggestion['keys'])) {
			foreach ($loteSubsetSuggestion['keys'] as $subsetDocKey) {
				$subsetDocKey = (string) $subsetDocKey;
				if ($subsetDocKey !== '') {
					$autoSelectDocKeys[$subsetDocKey] = true;
				}
			}
		}
	}
}

$selectedOutstanding = 0.0;
$quickEntryPrefillLabel = '';
$quickEntryPrefillNote = '';
$quickSupplierLookupPrefill = '';
$quickSupplierRefSupplier = '';
$quickSupplierPrediction = array(
	'enabled' => 0,
	'is_confident' => 0,
	'predicted_socid' => 0,
	'predicted_name' => '',
	'probability_pct' => 0,
	'threshold_pct' => 0,
	'reason' => 'disabled',
);
$quickSupplierPredictedSocid = 0;
$selectedLineDirection = 0;
if ($selectedLine) {
	$selectedOutstanding = max(0, abs((float) $selectedLine['amount']) - abs((float) $selectedLine['allocated_amount']));
	$selectedLineDirection = (int) (!empty($selectedLine['direction']) ? $selectedLine['direction'] : 0);
	if ($selectedLineDirection === 0) {
		$selectedLineAmount = (float) (!empty($selectedLine['amount']) ? $selectedLine['amount'] : 0.0);
		if ($selectedLineAmount > 0.0000001) {
			$selectedLineDirection = 1;
		} elseif ($selectedLineAmount < -0.0000001) {
			$selectedLineDirection = -1;
		}
	}

	$quickEntryPrefillLabel = trim((string) (!empty($selectedLine['description']) ? $selectedLine['description'] : ''));
	if ($quickEntryPrefillLabel === '') {
		$quickEntryPrefillLabel = trim((string) (!empty($selectedLine['counterparty_name']) ? $selectedLine['counterparty_name'] : ''));
	}
	if ($quickEntryPrefillLabel === '') {
		$quickEntryPrefillLabel = trim((string) (!empty($selectedLine['payment_reference']) ? $selectedLine['payment_reference'] : ''));
	}
	$quickEntryPrefillLabel = trim((string) preg_replace('/\s+/', ' ', $quickEntryPrefillLabel));
	$quickEntryPrefillLabel = dol_trunc($quickEntryPrefillLabel, 190);

	$quickEntryNoteLines = array();
	$counterpartyIban = trim((string) (!empty($selectedLine['counterparty_iban']) ? $selectedLine['counterparty_iban'] : ''));
	$paymentReference = trim((string) (!empty($selectedLine['payment_reference']) ? $selectedLine['payment_reference'] : ''));
	$bankReference = trim((string) (!empty($selectedLine['bank_reference']) ? $selectedLine['bank_reference'] : ''));
	$counterpartyName = trim((string) (!empty($selectedLine['counterparty_name']) ? $selectedLine['counterparty_name'] : ''));

	$isGenericQuickValue = static function ($value, $currency = '') {
		$normalized = strtoupper((string) preg_replace('/[^A-Z0-9]/', '', (string) $value));
		$currencyNormalized = strtoupper((string) preg_replace('/[^A-Z0-9]/', '', (string) $currency));
		if ($normalized === '' || $normalized === $currencyNormalized) {
			return true;
		}
		if (in_array($normalized, array('EUR', 'EURO', 'REF', 'REFERENCIA', 'REFERENCE', 'N', 'NA', 'NULL'), true)) {
			return true;
		}

		return false;
	};

	if ($counterpartyName !== '' && !$isGenericQuickValue($counterpartyName, (string) $selectedLine['currency'])) {
		$quickEntryNoteLines[] = $langs->trans('ThirdParty') . ' : ' . $counterpartyName;
	}
	if ($counterpartyIban !== '') {
		$quickEntryNoteLines[] = 'IBAN: ' . $counterpartyIban;
	}
	if ($paymentReference !== '' && !$isGenericQuickValue($paymentReference, (string) $selectedLine['currency'])) {
		$quickEntryNoteLines[] = $langs->trans('KreaBankReference') . ': ' . $paymentReference;
	}
	if ($bankReference !== '' && !$isGenericQuickValue($bankReference, (string) $selectedLine['currency'])) {
		$quickEntryNoteLines[] = $langs->trans('Ref') . ': ' . $bankReference;
	}
	$quickEntryPrefillNote = implode("\n", $quickEntryNoteLines);
	$quickSupplierRefSupplier = ($paymentReference !== '' ? $paymentReference : $bankReference);
	if ($quickSupplierRefSupplier !== '') {
		$quickSupplierRefSupplier = dol_trunc($quickSupplierRefSupplier, 120, 'right', 'UTF-8', 1);
	}
	$quickSupplierLookupPrefill = $counterpartyName;
	if ($canQuickSupplierInvoice && $selectedLineDirection < 0) {
		$supplierInferenceCandidates = array();
		$pushSupplierInferenceCandidate = static function ($value) use (&$supplierInferenceCandidates) {
			$value = trim((string) preg_replace('/\s+/', ' ', (string) $value));
			if ($value === '') {
				return;
			}
			$key = strtoupper((string) preg_replace('/[^A-Z0-9]/', '', $value));
			if ($key === '' || isset($supplierInferenceCandidates[$key])) {
				return;
			}
			$supplierInferenceCandidates[$key] = $value;
		};
		$pushSupplierInferenceVariants = static function ($value) use ($pushSupplierInferenceCandidate) {
			$value = trim((string) preg_replace('/\s+/', ' ', (string) $value));
			if ($value === '') {
				return;
			}
			$pushSupplierInferenceCandidate($value);
			$withoutSuffix = trim((string) preg_replace('/[-\s]*[A-Z]?[0-9]{5,}$/', '', $value));
			if ($withoutSuffix !== '' && $withoutSuffix !== $value) {
				$pushSupplierInferenceCandidate($withoutSuffix);
			}
			if (preg_match('/(?:P\/|PARA)\s*(.+)$/iu', $value, $parts) && !empty($parts[1])) {
				$afterTransfer = trim((string) $parts[1]);
				if ($afterTransfer !== '') {
					$pushSupplierInferenceCandidate($afterTransfer);
					$afterTransferNoSuffix = trim((string) preg_replace('/[-\s]*[A-Z]?[0-9]{5,}$/', '', $afterTransfer));
					if ($afterTransferNoSuffix !== '' && $afterTransferNoSuffix !== $afterTransfer) {
						$pushSupplierInferenceCandidate($afterTransferNoSuffix);
					}
				}
			}
			if (strpos($value, '-') !== false) {
				$beforeDash = trim((string) preg_replace('/\s+/', ' ', (string) strstr($value, '-', true)));
				if ($beforeDash !== '') {
					$pushSupplierInferenceCandidate($beforeDash);
				}
			}
		};
		$pushSupplierInferenceVariants($quickSupplierLookupPrefill);
		$pushSupplierInferenceVariants($quickEntryPrefillLabel);
		$pushSupplierInferenceVariants($paymentReference);
		$pushSupplierInferenceVariants($bankReference);
		$pushSupplierInferenceVariants((string) (!empty($selectedLine['description']) ? $selectedLine['description'] : ''));
		$pushSupplierInferenceVariants((string) (!empty($selectedLine['counterparty_name']) ? $selectedLine['counterparty_name'] : ''));

		foreach ($supplierInferenceCandidates as $supplierInferenceValue) {
			try {
				$resolvedSupplier = $service->resolveSupplierFromVatOrName((string) $supplierInferenceValue, 0);
				if (!empty($resolvedSupplier['id'])) {
					$quickSupplierPredictedSocid = (int) $resolvedSupplier['id'];
					if (!empty($resolvedSupplier['name'])) {
						$quickSupplierLookupPrefill = $decodeHtmlEntitiesRecursive((string) $resolvedSupplier['name']);
					}
					break;
				}
			} catch (Exception $e) {
				// Ignore ambiguous/no-match candidate and try next inference candidate.
			}
		}

		$quickSupplierPrediction = $service->predictSupplierForLine($selectedLine);
		if ($quickSupplierPredictedSocid <= 0 && !empty($quickSupplierPrediction['is_confident']) && !empty($quickSupplierPrediction['predicted_socid'])) {
			$quickSupplierPredictedSocid = (int) $quickSupplierPrediction['predicted_socid'];
			if ($quickSupplierLookupPrefill === '' && !empty($quickSupplierPrediction['predicted_name'])) {
				$quickSupplierLookupPrefill = $decodeHtmlEntitiesRecursive((string) $quickSupplierPrediction['predicted_name']);
			}
		}
	}
}
$quickSupplierProductLineSlots = 6;
$renderQuickEntryForm = static function ($line, $openDocSearch, $entryTypeOptions, $titleKey, $defaultEntryType, $prefillLabel, $prefillAmount, $prefillNote, $supplierLookupPrefill = '', $supplierRefPrefill = '', $supplierPredictedSocid = 0, $supplierPrediction = array(), $showSupplierInvoiceButton = false, $showTaxEntryButton = false, $showTopSpacing = false) use ($langs, $forceBatchDetect, $form, $quickSupplierProductLineSlots, $decodeHtmlEntitiesRecursive) {
	if (empty($line) || empty($entryTypeOptions) || empty($titleKey)) {
		return;
	}

	$lineIdValue = !empty($line['rowid']) ? (int) $line['rowid'] : 0;
	if ($lineIdValue <= 0) {
		return;
	}

	if ($showTopSpacing) {
		print '<br>';
	}

	$formDomId = 'krea_quick_entry_form_' . $lineIdValue;
	$supplierModalId = 'krea_supplier_invoice_modal_' . $lineIdValue;
	$supplierTargetTotalId = 'krea_supplier_invoice_target_' . $lineIdValue;
	$supplierCurrentTotalId = 'krea_supplier_invoice_current_' . $lineIdValue;
	$supplierFeedbackId = 'krea_supplier_invoice_feedback_' . $lineIdValue;
	$supplierConfirmId = 'krea_supplier_invoice_confirm_' . $lineIdValue;
	$supplierJsonInputId = 'krea_supplier_invoice_lines_json_' . $lineIdValue;
	$prefillAmountNumber = (float) price2num((string) $prefillAmount, 'MU');
	if ($prefillAmountNumber <= 0) {
		$prefillAmountNumber = 0.0;
	}
	print '<h4>' . $langs->trans((string) $titleKey) . '</h4>';
	print '<form id="' . $formDomId . '" method="POST" action="' . dol_escape_htmltag($_SERVER['PHP_SELF']) . '" style="margin-bottom:12px">';
	print '<input type="hidden" name="token" value="' . newToken() . '">';
	print '<input type="hidden" name="action" value="quick_entry">';
	print '<input type="hidden" name="line_id" value="' . $lineIdValue . '">';
	print '<input type="hidden" name="open_doc_search" value="' . dol_escape_htmltag((string) $openDocSearch) . '">';
	print '<input type="hidden" name="entry_product_line_slots" value="' . ((int) $quickSupplierProductLineSlots) . '">';
	print '<input type="hidden" name="entry_supplier_lookup" id="entry_supplier_lookup_' . $lineIdValue . '" value="">';
	print '<input type="hidden" name="entry_product_lines_json" id="' . $supplierJsonInputId . '" value="">';
	if ((int) $forceBatchDetect > 0) {
		print '<input type="hidden" name="force_batch_detect" value="1">';
	}
	if ((int) $supplierPredictedSocid > 0) {
		print '<input type="hidden" name="entry_supplier_predicted_socid" value="' . ((int) $supplierPredictedSocid) . '">';
	}
	if (!empty($supplierPrediction['enabled'])) {
		$predictedName = !empty($supplierPrediction['predicted_name']) ? (string) $supplierPrediction['predicted_name'] : '';
		$predictedName = $decodeHtmlEntitiesRecursive($predictedName);
		$predictionPct = !empty($supplierPrediction['probability_pct']) ? (int) $supplierPrediction['probability_pct'] : 0;
		$predictionThreshold = !empty($supplierPrediction['threshold_pct']) ? (int) $supplierPrediction['threshold_pct'] : 0;
		if ($predictedName !== '') {
			print '<div class="' . (!empty($supplierPrediction['is_confident']) ? 'info' : 'warning') . '" style="margin-bottom:8px;">';
			print $langs->trans('KreaBankSupplierMlPrediction', dol_escape_htmltag($predictedName), (string) $predictionPct, (string) $predictionThreshold);
			print '</div>';
		} elseif (!empty($showSupplierInvoiceButton)) {
			print '<div class="warning" style="margin-bottom:8px;">' . $langs->trans('KreaBankSupplierMlNoPrediction') . '</div>';
		}
	}
	print '<table class="nobordernopadding centpercent">';
	print '<tr><td>' . $langs->trans('KreaBankQuickEntryType') . '</td><td><select class="flat" name="entry_type">';
	foreach ((array) $entryTypeOptions as $entryTypeOption) {
		$entryTypeValue = isset($entryTypeOption['value']) ? (string) $entryTypeOption['value'] : '';
		$entryTypeLabelKey = isset($entryTypeOption['label_key']) ? (string) $entryTypeOption['label_key'] : '';
		if ($entryTypeValue === '' || $entryTypeLabelKey === '') {
			continue;
		}

		$isSelected = ($entryTypeValue === (string) $defaultEntryType);
		print '<option value="' . dol_escape_htmltag($entryTypeValue) . '"' . ($isSelected ? ' selected' : '') . '>' . $langs->trans($entryTypeLabelKey) . '</option>';
	}
	print '</select></td></tr>';
	print '<tr><td>' . $langs->trans('KreaBankQuickEntryLabel') . '</td><td><input class="flat minwidth300" type="text" name="entry_label" value="' . dol_escape_htmltag((string) $prefillLabel) . '" required></td></tr>';
	print '<tr><td>' . $langs->trans('KreaBankQuickEntryAmount') . '</td><td><input class="flat" type="number" step="0.01" min="0" name="entry_amount" value="' . price2num((string) $prefillAmount, 'MU') . '"></td></tr>';
	$selectedSupplierInput = trim((string) $supplierLookupPrefill);
	if ($selectedSupplierInput === '' && !empty($supplierPrediction['predicted_name'])) {
		$selectedSupplierInput = $decodeHtmlEntitiesRecursive((string) $supplierPrediction['predicted_name']);
	}
	$selectedSupplierId = ((int) $supplierPredictedSocid > 0 ? (int) $supplierPredictedSocid : '');
	print '<tr><td>' . $langs->trans('KreaBankQuickSupplierInvoiceSupplier') . '</td><td>' . $form->select_company(
		$selectedSupplierId,
		'entry_supplier_socid',
		'(s.fournisseur:>:0)',
		'KreaBankQuickSupplierLookupPlaceholder',
		0,
		0,
		array(),
		0,
		'minwidth300',
		'',
		$selectedSupplierInput,
		1
	) . '</td></tr>';
	print '<tr><td>' . $langs->trans('KreaBankQuickSupplierInvoiceRefSupplier') . '</td><td><input class="flat minwidth300" type="text" name="entry_ref_supplier" value="' . dol_escape_htmltag((string) $supplierRefPrefill) . '"></td></tr>';
	print '<tr><td>' . $langs->trans('KreaBankReason') . '</td><td><textarea class="flat" name="entry_note" rows="2" style="width:100%">' . dol_escape_htmltag((string) $prefillNote, 0, 1) . '</textarea></td></tr>';
	print '</table>';
	print '<div class="tabsAction">';
	print '<button class="butAction" type="submit" name="entry_submit_action" value="payment_entry">' . $langs->trans('KreaBankQuickActionPayment') . '</button>';
	if (!empty($showTaxEntryButton)) {
		print '<button class="butAction" type="submit" name="entry_submit_action" value="tax_entry">' . $langs->trans('KreaBankQuickActionTax') . '</button>';
	}
	if (!empty($showSupplierInvoiceButton)) {
		$supplierRequiredMsg = $langs->transnoentitiesnoconv('ErrorFieldRequired', $langs->transnoentitiesnoconv('KreaBankQuickSupplierInvoiceSupplier'));
		print '<button class="butAction" type="button" data-krea-supplier-modal-open="' . $supplierModalId . '" data-krea-form-id="' . $formDomId . '" data-krea-msg-supplier="' . dol_escape_htmltag($supplierRequiredMsg) . '">' . $langs->trans('KreaBankQuickActionInvoice') . '</button>';
	}
	print '<button class="butAction" type="submit" name="entry_submit_action" value="skip_line" formnovalidate>' . $langs->trans('KreaBankQuickActionPending') . '</button>';
	print '</div>';
	print '<div class="krea-muted" style="margin-top:6px">' . $langs->trans('KreaBankQuickActionsHelp') . '</div>';
	if (!empty($showSupplierInvoiceButton)) {
		print '<div id="' . $supplierModalId . '" class="krea-modal-overlay krea-supplier-lines-modal"';
		print ' data-target-total="' . price2num((string) $prefillAmountNumber, 'MU') . '"';
		print ' data-target-total-id="' . $supplierTargetTotalId . '"';
		print ' data-current-total-id="' . $supplierCurrentTotalId . '"';
		print ' data-feedback-id="' . $supplierFeedbackId . '"';
		print ' data-confirm-id="' . $supplierConfirmId . '"';
		print ' data-json-input-id="' . $supplierJsonInputId . '"';
		print ' data-supplier-lookup-id="entry_supplier_lookup_' . $lineIdValue . '"';
		print ' data-msg-empty="' . dol_escape_htmltag($langs->transnoentitiesnoconv('KreaBankQuickSupplierLinesNoSelection')) . '"';
		print ' data-msg-incomplete="' . dol_escape_htmltag($langs->transnoentitiesnoconv('KreaBankQuickSupplierLinesIncomplete')) . '"';
		print ' data-msg-mismatch="' . dol_escape_htmltag($langs->transnoentitiesnoconv('KreaBankQuickSupplierLinesMismatch')) . '"';
		print ' data-msg-supplier="' . dol_escape_htmltag($supplierRequiredMsg) . '"';
		print ' style="display:none;">';
		print '<div class="krea-modal-dialog">';
		print '<div class="krea-modal-header">';
		print '<div class="krea-modal-title">' . $langs->trans('KreaBankQuickSupplierLinesTitle') . '</div>';
		print '<button type="button" class="butAction krea-modal-close" data-krea-modal-close="' . $supplierModalId . '">x</button>';
		print '</div>';
		print '<div class="krea-muted" style="margin-bottom:8px">' . $langs->trans('KreaBankQuickSupplierLinesHelp') . '</div>';
		print '<table class="nobordernopadding centpercent krea-product-line-table">';
		print '<tr class="liste_titre">';
		print '<th>' . $langs->trans('Ref') . '</th>';
		print '<th>' . $langs->trans('Description') . '</th>';
		print '<th class="center">' . $langs->trans('Qty') . '</th>';
		print '<th class="right">' . $langs->trans('Amount') . '</th>';
		print '<th class="center">' . $langs->trans('Delete') . '</th>';
		print '</tr>';
		for ($lineSlot = 0; $lineSlot < (int) $quickSupplierProductLineSlots; $lineSlot++) {
			$rowClass = 'krea-product-line-row';
			if ($lineSlot > 0) {
				$rowClass .= ' krea-product-line-row-hidden';
			}
			$qtyDefault = ($lineSlot === 0 ? '1' : '');
			$amountDefault = ($lineSlot === 0 ? number_format((float) $prefillAmountNumber, 2, '.', '') : '');
			$deleteIcon = img_picto($langs->transnoentitiesnoconv('Delete'), 'delete', 'class="krea-delete-icon"');
			print '<tr class="' . $rowClass . '" data-krea-product-row="' . $lineSlot . '">';
			print '<td>' . $form->select_produits(
				0,
				'entry_product_line_product_' . $lineSlot,
				'',
				0,
				0,
				1,
				2,
				'',
				2,
				array(),
				0,
				'',
				0,
				'minwidth200',
				1,
				'',
				null,
				1,
				1
			) . '</td>';
			print '<td><input class="flat minwidth200 krea-product-line-label" type="text" name="entry_product_line_label_' . $lineSlot . '" value="" readonly></td>';
			print '<td class="center"><input class="flat right krea-product-line-qty" type="number" step="0.000001" min="0" name="entry_product_line_qty_' . $lineSlot . '" value="' . $qtyDefault . '"></td>';
			print '<td class="right"><input class="flat right krea-product-line-amount" type="number" step="0.01" min="0" name="entry_product_line_amount_' . $lineSlot . '" value="' . $amountDefault . '" inputmode="decimal"></td>';
			print '<td class="center"><button type="button" class="butAction krea-product-line-remove" data-krea-product-remove="' . $lineSlot . '" title="' . dol_escape_htmltag($langs->transnoentitiesnoconv('Delete')) . '" aria-label="' . dol_escape_htmltag($langs->transnoentitiesnoconv('Delete')) . '"' . ($lineSlot === 0 ? ' style="display:none"' : '') . '>' . $deleteIcon . '</button></td>';
			print '</tr>';
		}
		print '</table>';
		print '<div style="margin-top:10px;display:flex;gap:8px;align-items:center;flex-wrap:wrap">';
		print '<button type="button" class="butAction krea-product-line-add">' . $langs->trans('KreaBankQuickSupplierLinesAddRow') . '</button>';
		print '<span class="krea-muted">' . $langs->trans('KreaBankQuickSupplierLinesTarget') . ': <strong id="' . $supplierTargetTotalId . '">' . price($prefillAmountNumber) . '</strong></span>';
		print '<span class="krea-muted">' . $langs->trans('KreaBankQuickSupplierLinesCurrent') . ': <strong id="' . $supplierCurrentTotalId . '">' . price(0) . '</strong></span>';
		print '</div>';
		print '<div id="' . $supplierFeedbackId . '" class="warning" style="margin-top:8px;display:none"></div>';
		print '<div class="tabsAction" style="margin-top:10px">';
		print '<button type="button" class="butAction" data-krea-modal-close="' . $supplierModalId . '">' . $langs->trans('KreaBankQuickSupplierLinesClose') . '</button>';
		print '<button class="butAction" type="submit" name="entry_submit_action" value="supplier_invoice" id="' . $supplierConfirmId . '">' . $langs->trans('KreaBankQuickActionInvoiceConfirm') . '</button>';
		print '</div>';
		print '</div>';
		print '</div>';
	}
	print '</form>';
};

$quickPaymentEntryTypeOptions = array(
	array('value' => 'payment', 'label_key' => 'KreaBankQuickEntryTypePayment'),
);

llxHeader('', $langs->trans('KreaBankReconcile'), '', '', 0, 0, '', '', '', 'mod-kreabank page-reconcile');

if (!empty($quickCreateSuccessAlert['enabled']) && !empty($quickCreateSuccessAlert['message'])) {
	$stickyMessage = (string) $quickCreateSuccessAlert['message'];
	$stickyUrl = trim((string) $quickCreateSuccessAlert['url']);
	$stickyRef = trim((string) $quickCreateSuccessAlert['ref']);
	if ($stickyUrl !== '' && $stickyRef !== '') {
		$stickyMessage .= ' <a href="' . dol_escape_htmltag($stickyUrl) . '" target="_blank" rel="noopener">' . dol_escape_htmltag($stickyRef) . '</a>';
	}
	print '<script nonce="' . getNonce() . '">';
	print 'jQuery(function(){';
	print 'if (window.jQuery && typeof jQuery.jnotify === "function") {';
	print 'jQuery.jnotify(' . json_encode($stickyMessage, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ', {type:"3000", sticky:true, showClose:true});';
	print '}';
	print '});';
	print '</script>';
	print '<noscript><div class="krea-embedded-success-alert">';
	dol_htmloutput_mesg($stickyMessage, array(), 'ok', 1);
	print '</div></noscript>';
}

print load_fiche_titre($langs->trans('KreaBankDualPaneTitle'), '', 'title_setup');
$head = kreabankPrepareHead();
print dol_get_fiche_head($head, 'reconcile', $langs->trans('KreaBankReconcile'), -1, 'bank');

print '<style>';
print '.krea-embedded-success-alert .ok{margin:0 0 12px 0}';
print '.krea-embedded-success-alert .ok a{font-weight:700;text-decoration:underline}';
print '.krea-recon-layout{display:flex;gap:16px;margin-top:8px}';
print '.krea-page-intro{margin:8px 0 12px 0;padding:10px 12px;border:1px solid #d6e2ec;background:#f8fbfe;border-radius:8px}';
print '.krea-page-intro-title{margin:0;font-size:20px;line-height:1.2;color:#1b3f5f;font-weight:800}';
print '.krea-page-intro-subtitle{margin:6px 0 0 0;color:#496276;font-size:13px;line-height:1.5}';
print '.krea-left{flex:1;min-width:0;padding-right:4px}';
print '.krea-right{flex:1;min-width:0}';
print '.krea-line-card{border:1px solid #d8d8d8;border-radius:6px;background:#fff;padding:10px;margin-bottom:10px;transition:all .2s ease}';
print '.krea-line-card.selected{border-color:#0070d2;box-shadow:0 2px 6px rgba(0,112,210,.2)}';
print '.krea-line-card-primary{border-width:2px;padding:12px;box-shadow:0 4px 12px rgba(0,112,210,.16)}';
print '.krea-line-card-secondary{opacity:.55;filter:saturate(.72)}';
print '.krea-line-card-secondary:hover{opacity:.9;filter:none}';
print '.krea-line-card.cleaning{opacity:.35;transform:scale(.98)}';
print '.krea-line-current-section{margin:8px 0 14px 0;padding:10px;border:1px solid #c8ddf2;background:#f5faff;border-radius:8px}';
print '.krea-line-other-section{margin-top:8px;padding-top:8px;border-top:1px dashed #cfd5dc}';
print '.krea-line-section-title{font-size:14px;font-weight:800;letter-spacing:.2px;text-transform:uppercase;color:#1f4e79;margin:0 0 8px 0}';
print '.krea-direction-badge{display:inline-block;padding:2px 7px;border-radius:10px;font-size:11px;font-weight:700;line-height:1.4}';
print '.krea-direction-credit{background:#e8f7ef;color:#1e7f3f;border:1px solid #bfe6ce}';
print '.krea-direction-debit{background:#fdeeee;color:#a02f2f;border:1px solid #f2c1c1}';
print '.krea-doc-item{border:1px solid #ddd;border-radius:6px;padding:8px;margin-bottom:8px;background:#fafafa}';
print '.krea-doc-item:hover{border-color:#0070d2;background:#eef6ff}';
print '.krea-doc-item-locked{background:#f7f7f7;border-style:dashed}';
print '.krea-doc-item-locked:hover{border-color:#ddd;background:#f7f7f7}';
print '.krea-badge{display:inline-block;padding:2px 6px;border-radius:4px;font-size:11px;font-weight:600;margin-right:4px}';
print '.krea-badge-safe{background:#28a745;color:#fff}';
print '.krea-badge-mid{background:#ffc107;color:#000}';
print '.krea-muted{color:#666;font-size:12px}';
print '.krea-line-meta{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-top:4px}';
print '.krea-line-meta .badge{margin-right:0}';
print '.krea-nowrap{white-space:nowrap}';
print '.krea-batch-force{display:inline-block;padding:2px 7px;border-radius:10px;font-size:10px;font-weight:700;line-height:1.35;background:#eef6ff;color:#1f4e79;border:1px solid #c3d9ef;text-decoration:none}';
print '.krea-batch-force:hover{text-decoration:none;filter:brightness(.98)}';
print '.krea-modal-overlay{position:fixed;top:0;right:0;bottom:0;left:0;z-index:1500;background:rgba(15,23,42,.45);padding:20px;display:none;align-items:flex-start;justify-content:center;overflow:auto}';
print '.krea-modal-overlay.is-open{display:flex}';
print '.krea-modal-dialog{margin-top:30px;background:#fff;border:1px solid #c8d3dd;border-radius:10px;box-shadow:0 12px 32px rgba(15,23,42,.25);width:min(1180px,100%);padding:14px}';
print '.krea-modal-header{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:6px}';
print '.krea-modal-title{font-size:16px;font-weight:700;color:#1f4e79}';
print '.krea-modal-close{border:1px solid #c8d3dd;background:#f8fbfe;border-radius:6px;min-width:30px;min-height:30px;line-height:1;font-weight:700;cursor:pointer}';
print '.select2-container--open{z-index:1700 !important}';
print '.select2-dropdown{z-index:1701 !important}';
print '.krea-right .select2-container,.krea-right .minwidth300,.krea-right .minwidth200{max-width:100% !important}';
print '.krea-product-line-table{table-layout:auto;width:100%}';
print '.krea-product-line-table th,.krea-product-line-table td{vertical-align:middle}';
print '.krea-product-line-table th:nth-child(3),.krea-product-line-table td:nth-child(3){width:88px}';
print '.krea-product-line-table th:nth-child(4),.krea-product-line-table td:nth-child(4){width:140px;white-space:nowrap}';
print '.krea-product-line-table th:nth-child(5),.krea-product-line-table td:nth-child(5){width:56px;white-space:nowrap}';
print '.krea-product-line-table .select2-container{width:100% !important;max-width:100% !important}';
print '.krea-product-line-table input[type=\"text\"],.krea-product-line-table input[type=\"number\"]{width:100%}';
print '.krea-product-line-row-hidden{display:none}';
print '.krea-product-line-table .krea-product-line-amount,.krea-product-line-table .krea-product-line-qty{text-align:right}';
print '.krea-product-line-table .krea-product-line-remove{min-width:34px;width:34px;padding:2px 4px;display:inline-flex;align-items:center;justify-content:center}';
print '.krea-product-line-table .krea-product-line-remove .krea-delete-icon{margin:0}';
print '@media (max-width: 900px){.krea-modal-dialog{padding:10px}.krea-product-line-table .minwidth200{min-width:140px}}';
print '@media (max-width: 1200px){.krea-recon-layout{flex-direction:column}}';
print '</style>';

print '<div class="krea-page-intro">';
print '<h2 class="krea-page-intro-title">' . $langs->trans('KreaBankAssistedReconciliationTitle') . '</h2>';
print '<p class="krea-page-intro-subtitle">' . $langs->trans('KreaBankAssistedReconciliationSubtitle') . '</p>';
print '</div>';

print '<div class="krea-recon-layout">';

print '<div class="krea-left">';
print '<h3>' . $langs->trans('KreaBankPendingStatementLines') . ' (' . ((int) $pendingLinesTotal) . ')</h3>';
$showBulkSkip = ($user->hasRight('kreabank', 'reconciliation', 'write') && $canNativeWrite && !empty($pendingLines));
if ($showBulkSkip) {
	print '<form method="POST" action="' . dol_escape_htmltag($_SERVER['PHP_SELF']) . '" id="bulkSkipForm" style="margin:0 0 12px 0">';
	print '<input type="hidden" name="token" value="' . newToken() . '">';
	print '<input type="hidden" name="action" value="bulk_skip">';
	print '<input type="hidden" name="selected_line_ids_csv" id="krea_selected_line_ids_csv" value="">';
	print '<input type="hidden" name="line_id" value="' . ((int) $lineId) . '">';
	print '<input type="hidden" name="open_doc_search" value="' . dol_escape_htmltag($openDocumentsSearch) . '">';
	if ((int) $forceBatchDetect > 0) {
		print '<input type="hidden" name="force_batch_detect" value="1">';
	}
	print '<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:8px">';
	print '<label style="display:flex;gap:6px;align-items:center">';
	print '<input type="checkbox" id="krea_bulk_skip_all">';
	print '<span>' . $langs->trans('KreaBankSelectAll') . '</span>';
	print '</label>';
	print '<input class="flat minwidth300" type="text" name="skip_reason" value="" placeholder="' . dol_escape_htmltag($langs->trans('KreaBankSkipReasonPlaceholder')) . '">';
	print '<button type="submit" class="butAction">' . $langs->trans('KreaBankSkipSelected') . '</button>';
	print '</div>';
}

if (empty($pendingLines)) {
	print '<div class="opacitymedium">' . $langs->trans('KreaBankNoStatementLines') . '</div>';
} else {
	$renderPendingLineCard = static function ($line, $isSelected, $isDimmed, $showBulkSkip, $langs) {
		$lineStatus = kreabankStatusLabel((int) $line['status'], $langs);
		$lineBadge = kreabankStatusClass((int) $line['status']);
		$lineOutstanding = max(0, abs((float) $line['amount']) - abs((float) $line['allocated_amount']));
		$lineDirection = (int) (!empty($line['direction']) ? $line['direction'] : 0);
		if ($lineDirection === 0) {
			$lineAmount = (float) (!empty($line['amount']) ? $line['amount'] : 0);
			if ($lineAmount > 0.0000001) {
				$lineDirection = 1;
			} elseif ($lineAmount < -0.0000001) {
				$lineDirection = -1;
			}
		}
		$lineDirectionLabel = '';
		$lineDirectionClass = '';
		if ($lineDirection > 0) {
			$lineDirectionLabel = $langs->trans('Credit');
			$lineDirectionClass = 'krea-direction-credit';
		} elseif ($lineDirection < 0) {
			$lineDirectionLabel = $langs->trans('Debit');
			$lineDirectionClass = 'krea-direction-debit';
		}
		$linePaymentReference = trim((string) (!empty($line['payment_reference']) ? $line['payment_reference'] : ''));
		$lineTitle = trim((string) (!empty($line['counterparty_name']) ? $line['counterparty_name'] : ''));
		if ($lineTitle === '') {
			$lineTitle = trim((string) (!empty($line['description']) ? $line['description'] : ''));
		}
		if ($lineTitle === '') {
			$lineTitle = $linePaymentReference;
		}
		if ($lineTitle === '') {
			$lineTitle = trim((string) (!empty($line['counterparty_iban']) ? $line['counterparty_iban'] : ''));
		}
		if ($lineTitle === '') {
			$lineTitle = trim((string) (!empty($line['bank_reference']) ? $line['bank_reference'] : ''));
		}
		if ($lineTitle === '') {
			$lineTitle = 'Linha #' . ((int) $line['rowid']);
		}

		$normalizeLineValue = static function ($value) {
			$value = trim((string) $value);
			if ($value === '') {
				return '';
			}

			$normalized = kreabankNormalizeText($value);
			$normalized = preg_replace('/\s+/', '', (string) $normalized);
			$normalized = preg_replace('/[^a-z0-9]/', '', (string) $normalized);

			return (string) $normalized;
		};
		$linePaymentReferenceNormalized = $normalizeLineValue($linePaymentReference);
		$lineDetails = array();
		$lineDetailsSeen = array();
		$appendLineDetail = static function ($value) use (&$lineDetails, &$lineDetailsSeen, $normalizeLineValue, $linePaymentReferenceNormalized) {
			$value = trim((string) $value);
			if ($value === '') {
				return;
			}
			$normalized = $normalizeLineValue($value);
			if ($normalized === '') {
				return;
			}
			if ($linePaymentReferenceNormalized !== '' && $normalized === $linePaymentReferenceNormalized) {
				return;
			}
			if (isset($lineDetailsSeen[$normalized])) {
				return;
			}
			$lineDetailsSeen[$normalized] = true;
			$lineDetails[] = $value;
		};
		$appendLineDetail((string) (!empty($line['counterparty_iban']) ? $line['counterparty_iban'] : ''));
		$appendLineDetail((string) (!empty($line['description']) ? $line['description'] : ''));
		$lineCardClass = 'krea-line-card';
		if (!empty($isSelected)) {
			$lineCardClass .= ' selected krea-line-card-primary';
		}
		if (!empty($isDimmed)) {
			$lineCardClass .= ' krea-line-card-secondary';
		}

		print '<div class="' . $lineCardClass . '" id="linecard-' . ((int) $line['rowid']) . '" data-line-id="' . ((int) $line['rowid']) . '" data-outstanding="' . price2num((string) $lineOutstanding, 'MU') . '">';
		print '<div style="display:flex;justify-content:space-between;gap:8px;align-items:flex-start">';
		print '<div style="flex:1">';
		print '<div style="font-weight:700">' . dol_escape_htmltag($lineTitle) . '</div>';
		foreach ($lineDetails as $lineDetail) {
			print '<div class="krea-muted">' . dol_escape_htmltag((string) $lineDetail) . '</div>';
		}
		if ($linePaymentReference !== '') {
			print '<div class="krea-muted">' . $langs->trans('KreaBankReference') . ': ' . dol_escape_htmltag($linePaymentReference) . '</div>';
		}
		print '<div class="krea-line-meta">';
		if ($lineDirectionLabel !== '') {
			print '<span class="krea-direction-badge ' . $lineDirectionClass . '">' . dol_escape_htmltag((string) $lineDirectionLabel) . '</span>';
		}
		print '<span class="badge ' . $lineBadge . '">' . $lineStatus . '</span>';
		if ((int) $line['is_duplicate'] === 1) {
			print '<span class="badge badge-status7">' . $langs->trans('KreaBankDuplicate') . '</span>';
		}
		if ($lineOutstanding > 0) {
			print '<span class="krea-muted">' . $langs->trans('KreaBankAllocate') . ': ' . price($lineOutstanding) . ' ' . dol_escape_htmltag((string) $line['currency']) . '</span>';
		}
		print '</div>';
		print '</div>';
		print '<div class="krea-nowrap" style="text-align:right">';
		if ($showBulkSkip) {
			print '<div style="margin-bottom:6px"><input type="checkbox" name="selected_line_ids[]" value="' . ((int) $line['rowid']) . '" class="krea-bulk-line-selector"' . ($isSelected ? ' checked' : '') . '></div>';
		}
		print '<strong>' . price((float) $line['amount']) . ' ' . dol_escape_htmltag((string) $line['currency']) . '</strong><br>';
		print '<span class="krea-muted">' . dol_print_date(strtotime((string) $line['operation_date']), 'day') . '</span>';
		print '</div>';
		print '</div>';
		print '</div>';
	};

	$selectedPendingLine = null;
	$otherPendingLines = array();
	foreach ($pendingLines as $line) {
		if ((int) $line['rowid'] === (int) $lineId && $selectedPendingLine === null) {
			$selectedPendingLine = $line;
			continue;
		}

		$otherPendingLines[] = $line;
	}
	if ($selectedPendingLine === null && !empty($pendingLines)) {
		$selectedPendingLine = $pendingLines[0];
		$otherPendingLines = array_slice($pendingLines, 1);
	}

	if (!empty($selectedPendingLine)) {
		print '<div class="krea-line-current-section">';
		print '<div class="krea-line-section-title">' . $langs->trans('KreaBankCurrentLineInReconciliation') . '</div>';
		$renderPendingLineCard($selectedPendingLine, true, false, $showBulkSkip, $langs);
		print '</div>';
	}

	if (!empty($otherPendingLines)) {
		print '<div class="krea-line-other-section">';
		print '<div class="krea-line-section-title">' . $langs->trans('KreaBankOtherStatementLines') . '</div>';
		foreach ($otherPendingLines as $line) {
			$renderPendingLineCard($line, false, true, $showBulkSkip, $langs);
		}
		print '</div>';
	}
}
if ($showBulkSkip) {
	print '</form>';
}
print '</div>';

print '<div class="krea-right">';

if (!$selectedLine) {
	print '<div class="opacitymedium">' . $langs->trans('KreaBankNoStatementLines') . '</div>';
} else {
	print '<h3>' . $langs->trans('KreaBankOpenDocuments') . '</h3>';
	print '<div class="info">';
	print '<strong>' . dol_escape_htmltag((string) $selectedLine['counterparty_name']) . '</strong> | ';
	$selectedNativeLineId = !empty($selectedLine['native_bank_line_id']) ? (int) $selectedLine['native_bank_line_id'] : 0;
	$forceBatchUrl = dol_buildpath('/custom/kreabank/reconcile.php?mainmenu=bank&leftmenu=kreabank_reconcile&line_id=' . (int) $selectedLine['rowid'] . '&force_batch_detect=1', 1);
	if ($openDocumentsSearch !== '') {
		$forceBatchUrl .= '&open_doc_search=' . urlencode((string) $openDocumentsSearch);
	}
	print $langs->trans('KreaBankAmount') . ': <strong>' . price((float) $selectedLine['amount']) . ' ' . dol_escape_htmltag((string) $selectedLine['currency']) . '</strong> | ';
	print $langs->trans('KreaBankAllocate') . ': <strong>' . price((float) $selectedOutstanding) . ' ' . dol_escape_htmltag((string) $selectedLine['currency']) . '</strong>';
	if ($selectedNativeLineId > 0) {
		print ' | <a href="' . dol_buildpath('/compta/bank/line.php?rowid=' . $selectedNativeLineId, 1) . '" target="_blank" rel="noopener">' . $langs->trans('KreaBankOpenNativeLine') . '</a>';
	}
	if (!$isLoteBatchLine) {
		print ' | <a class="krea-batch-force" href="' . $forceBatchUrl . '">' . $langs->trans('KreaBankBatchDetectForce') . '</a>';
	}
	print '</div><br>';
	if ($batchDetectedAuto) {
		print '<div class="info">' . $langs->trans('KreaBankBatchDetectedAuto') . '</div><br>';
	}
	if ($batchDetectedMl) {
		print '<div class="info">' . $langs->trans('KreaBankBatchDetectedMl', (string) $batchMlProbabilityPct) . '</div><br>';
	}
	if ($batchDetectWasForced && empty($loteSubsetSuggestion['keys'])) {
		print '<div class="warning">' . $langs->trans('KreaBankBatchNoCandidates') . '</div><br>';
	}
	if ($isLoteBatchLine && !empty($loteSubsetSuggestion['keys']) && is_array($loteSubsetSuggestion['keys'])) {
		$subsetSelectedAmount = ((float) (int) $loteSubsetSuggestion['selected_cents']) / 100;
		$subsetTargetAmount = ((float) (int) $loteSubsetSuggestion['target_cents']) / 100;
		$subsetDiffAmount = ((float) (int) $loteSubsetSuggestion['difference_cents']) / 100;
		$subsetCurrency = dol_escape_htmltag((string) (!empty($selectedLine['currency']) ? $selectedLine['currency'] : 'EUR'));
		if (!empty($loteSubsetSuggestion['exact'])) {
			print '<div class="info">Sugestão automática LOTE: ' . ((int) $loteSubsetSuggestion['selected_count']) . ' pagamentos selecionados de ' . ((int) $loteSubsetSuggestion['candidate_count']) . ' candidatos, total ' . price($subsetSelectedAmount) . ' ' . $subsetCurrency . ' (alvo ' . price($subsetTargetAmount) . ' ' . $subsetCurrency . ').</div><br>';
		} else {
			print '<div class="warning">Sugestão aproximada LOTE: ' . ((int) $loteSubsetSuggestion['selected_count']) . ' pagamentos, total ' . price($subsetSelectedAmount) . ' ' . $subsetCurrency . '. Diferença para o alvo: ' . price($subsetDiffAmount) . ' ' . $subsetCurrency . '.</div><br>';
		}
	}

	print '<form method="POST" action="' . dol_escape_htmltag($_SERVER['PHP_SELF']) . '" id="manualReconcileForm">';
	print '<input type="hidden" name="token" value="' . newToken() . '">';
	print '<input type="hidden" name="action" value="reconcile">';
	print '<input type="hidden" name="line_id" value="' . ((int) $selectedLine['rowid']) . '">';
	print '<input type="hidden" name="open_doc_search" value="' . dol_escape_htmltag($openDocumentsSearch) . '">';
	if ((int) $forceBatchDetect > 0) {
		print '<input type="hidden" name="force_batch_detect" value="1">';
	}

	if (empty($documents)) {
		print '<div class="opacitymedium">' . $langs->trans('KreaBankNoOpenDocuments') . '</div>';
	} else {
		$docCount = 0;
		$maxDocumentsToRender = ($isLoteBatchLine ? 800 : 120);
		$hasSelectableDocuments = false;
		foreach ($documents as $doc) {
			if (!empty($doc['linked_bank_reconciled'])) {
				continue;
			}
			if ($docCount >= $maxDocumentsToRender) {
				break;
			}
			$isLocked = !empty($doc['is_locked']);
			$isLockedReconciled = ($isLocked && !empty($doc['linked_bank_reconciled']));
			$lockedStatusLabel = $isLockedReconciled ? $langs->trans('KreaBankAlreadyConciliatedShort') : $langs->trans('KreaBankAlreadyLinkedShort');
			if (!$isLocked) {
				$hasSelectableDocuments = true;
			}
			$docKey = $doc['doc_type'] . '__' . $doc['rowid'];
			$suggestion = isset($suggestionByKey[$docKey]) ? $suggestionByKey[$docKey] : null;
			$score = $suggestion ? (int) $suggestion['score'] : 0;
			$details = $suggestion ? $suggestion['details'] : array();
			$docTypeLabel = (string) $doc['doc_type'];
			if ($docTypeLabel === 'native_bank') {
				$docTypeLabel = !empty($doc['url_types']) ? (string) $doc['url_types'] : 'bank_line';
			} elseif ($docTypeLabel === 'payment_linked') {
				$docTypeLabel = 'payment';
			} elseif ($docTypeLabel === 'payment_supplier_linked') {
				$docTypeLabel = 'payment_supplier';
			}
			$docCardUrl = '';
			$rawDocType = (string) $doc['doc_type'];
			if ($rawDocType === 'native_bank') {
				$docCardUrl = dol_buildpath('/compta/bank/line.php?rowid=' . (int) $doc['rowid'], 1);
			} elseif ($rawDocType === 'payment' || $rawDocType === 'payment_linked') {
				$docCardUrl = dol_buildpath('/compta/paiement/card.php?id=' . (int) $doc['rowid'], 1);
			} elseif ($rawDocType === 'payment_supplier' || $rawDocType === 'payment_supplier_linked') {
				$docCardUrl = dol_buildpath('/fourn/paiement/card.php?id=' . (int) $doc['rowid'], 1);
			} elseif ($rawDocType === 'customer_invoice') {
				$docCardUrl = dol_buildpath('/compta/facture/card.php?facid=' . (int) $doc['rowid'], 1);
			} elseif ($rawDocType === 'supplier_invoice') {
				$docCardUrl = dol_buildpath('/fourn/facture/card.php?facid=' . (int) $doc['rowid'], 1);
			}

			print '<div class="krea-doc-item' . ($isLocked ? ' krea-doc-item-locked' : '') . '">';
			print '<div style="display:flex;justify-content:space-between;gap:8px">';
			print '<div style="flex:1">';
			print '<label style="display:flex;gap:6px;align-items:flex-start">';
			if ($isLocked) {
				print '<span class="badge ' . ($isLockedReconciled ? 'badge-status4' : 'badge-status7') . '">' . $lockedStatusLabel . '</span>';
			} else {
				$shouldCheck = !empty($autoSelectDocKeys[$docKey]);
				print '<input type="checkbox" name="selected_docs[]" value="' . $docKey . '"' . ($shouldCheck ? ' checked' : '') . '>';
			}
			print '<span>';
			if ($docCardUrl !== '') {
				print '<strong><a href="' . $docCardUrl . '" target="_blank" rel="noopener">' . dol_escape_htmltag((string) $doc['ref']) . '</a></strong>';
			} else {
				print '<strong>' . dol_escape_htmltag((string) $doc['ref']) . '</strong>';
			}
			print ' <span class="krea-muted">(' . dol_escape_htmltag($docTypeLabel) . ')</span><br>';
			print '<span class="krea-muted">' . dol_escape_htmltag((string) $doc['thirdparty_name']) . ' • ' . dol_print_date(strtotime((string) $doc['doc_date']), 'day') . '</span></span>';
			print '</label>';
			if (!empty($doc['linked_bank_line'])) {
				$linkedLineUrl = dol_buildpath('/compta/bank/line.php?rowid=' . (int) $doc['linked_bank_line'], 1);
				$linkedLineDate = !empty($doc['linked_bank_date']) ? dol_print_date(strtotime((string) $doc['linked_bank_date']), 'day') : '';
				$lockedDetailLabel = $isLockedReconciled ? $langs->trans('KreaBankAlreadyConciliated') : $langs->trans('KreaBankAlreadyLinked');
				print '<div class="krea-muted">' . $lockedDetailLabel . ': <a href="' . $linkedLineUrl . '" target="_blank" rel="noopener">#' . ((int) $doc['linked_bank_line']) . '</a>' . ($linkedLineDate !== '' ? ' • ' . $linkedLineDate : '') . '</div>';
			}
			if ($score > 0) {
				$badgeClass = $score >= $safeScore ? 'krea-badge-safe' : 'krea-badge-mid';
				print '<div style="margin-top:4px">';
				print '<span class="krea-badge ' . $badgeClass . '">' . $langs->trans('KreaBankBulkMatchScore') . ' ' . $score . '</span>';
				foreach ($details as $detail) {
					print '<span class="krea-badge ' . ($score >= $safeScore ? 'krea-badge-safe' : 'krea-badge-mid') . '">' . dol_escape_htmltag((string) $detail) . '</span>';
				}
				print '</div>';
			}
			print '</div>';
			print '<div class="krea-nowrap" style="text-align:right">';
			print '<div><strong>' . price((float) $doc['amount_open']) . ' ' . dol_escape_htmltag((string) $selectedLine['currency']) . '</strong></div>';
			if ($isLocked) {
				print '<div style="margin-top:4px" class="krea-muted">' . $lockedStatusLabel . '</div>';
			}
			print '</div>';
			print '</div>';
			if (!$isLocked) {
				print '<input type="hidden" name="match_score[' . $docKey . ']" value="' . $score . '">';
				print '<input type="hidden" name="match_reasons[' . $docKey . ']" value="' . dol_escape_htmltag(implode(',', $details)) . '">';
				print '<input type="hidden" name="doc_ref[' . $docKey . ']" value="' . dol_escape_htmltag((string) $doc['ref']) . '">';
				print '<input type="hidden" name="doc_amount[' . $docKey . ']" value="' . price2num((string) abs((float) $doc['amount_open']), 'MU') . '">';
			}
			print '</div>';

			$docCount++;
		}
	}
	if (!isset($hasSelectableDocuments)) {
		$hasSelectableDocuments = false;
	}

	if ($user->hasRight('kreabank', 'reconciliation', 'write') && $canNativeWrite && !empty($hasSelectableDocuments)) {
		print '<div class="tabsAction">';
		print '<button type="submit" class="butAction" id="manualReconcileButton">' . $langs->trans('KreaBankReconcileSelected') . '</button>';
		print '</div>';
	}
	print '</form>';

	if ($user->hasRight('kreabank', 'reconciliation', 'write') && $canNativeWrite) {
		$renderQuickEntryForm(
			$selectedLine,
			$openDocumentsSearch,
			$quickPaymentEntryTypeOptions,
			'KreaBankCreateQuickPaymentEntry',
			'payment',
			$quickEntryPrefillLabel,
			$selectedOutstanding,
			$quickEntryPrefillNote,
			$quickSupplierLookupPrefill,
			$quickSupplierRefSupplier,
			$quickSupplierPredictedSocid,
			$quickSupplierPrediction,
			($canQuickSupplierInvoice && $selectedLineDirection < 0),
			($canQuickTaxEntry && $selectedLineDirection < 0),
			true
		);
	}

	if (!empty($lineLinks)) {
		print '<br><h4>' . $langs->trans('KreaBankHistory') . '</h4>';
		print '<table class="noborder centpercent">';
		print '<tr class="liste_titre"><th>' . $langs->trans('KreaBankDocument') . '</th><th class="right">' . $langs->trans('KreaBankAllocate') . '</th><th>' . $langs->trans('Type') . '</th><th>' . $langs->trans('KreaBankWhen') . '</th></tr>';
		foreach ($lineLinks as $link) {
			print '<tr class="oddeven">';
			print '<td>' . dol_escape_htmltag((string) $link['doc_ref']) . ' <span class="krea-muted">(' . dol_escape_htmltag((string) $link['doc_type']) . ')</span></td>';
			print '<td class="right">' . price((float) $link['allocated_amount']) . ' ' . dol_escape_htmltag((string) $selectedLine['currency']) . '</td>';
			print '<td>' . dol_escape_htmltag((string) $link['strategy']) . ($link['is_reversed'] ? ' <span class="badge badge-status7">' . $langs->trans('KreaBankReversed') . '</span>' : '') . '</td>';
			print '<td>' . dol_print_date(strtotime((string) $link['date_validate']), 'dayhour') . '</td>';
			print '</tr>';
		}
		print '</table>';
	}
}

print '</div>';
print '</div>';

print '<script>';
print 'var manualForm = document.getElementById("manualReconcileForm");';
print 'if (manualForm) {';
print 'manualForm.addEventListener("submit", function(){';
print 'var selectedCard = document.querySelector(".krea-line-card.selected");';
print 'if(selectedCard){ selectedCard.classList.add("cleaning"); }';
print '});';
print '}';

print 'var bulkToggle = document.getElementById("krea_bulk_skip_all");';
print 'var bulkLineSelectors = document.querySelectorAll(".krea-bulk-line-selector");';
print 'if (bulkToggle && bulkLineSelectors.length) {';
print 'bulkToggle.addEventListener("change", function(){';
print 'bulkLineSelectors.forEach(function(selector){ selector.checked = bulkToggle.checked; });';
print '});';
print 'bulkLineSelectors.forEach(function(selector){';
print 'selector.addEventListener("change", function(){';
print 'if (!selector.checked) { bulkToggle.checked = false; return; }';
print 'var allChecked = true;';
print 'bulkLineSelectors.forEach(function(innerSelector){ if (!innerSelector.checked) { allChecked = false; } });';
print 'bulkToggle.checked = allChecked;';
print '});';
print '});';
print '}';
print 'var bulkSkipForm = document.getElementById("bulkSkipForm");';
print 'var bulkIdsCsv = document.getElementById("krea_selected_line_ids_csv");';
print 'if (bulkSkipForm && bulkIdsCsv) {';
print 'bulkSkipForm.addEventListener("submit", function(){';
print 'var ids = [];';
print 'document.querySelectorAll(".krea-bulk-line-selector:checked").forEach(function(selector){';
print 'if (selector.value) { ids.push(selector.value); }';
print '});';
print 'bulkIdsCsv.value = ids.join(",");';
print '});';
print '}';
print 'var kreaParseNumber = function(value){';
print 'var parsed = parseFloat(value);';
print 'return isNaN(parsed) ? 0 : parsed;';
print '};';
print 'var kreaRefreshSupplierRows = function(modal){';
print 'if (!modal) { return; }';
print 'var rows = modal.querySelectorAll(".krea-product-line-row");';
print 'var visibleRows = 0;';
print 'rows.forEach(function(row){ if (!row.classList.contains("krea-product-line-row-hidden")) { visibleRows++; } });';
print 'rows.forEach(function(row){';
print 'var removeButton = row.querySelector(".krea-product-line-remove");';
print 'if (!removeButton) { return; }';
print 'if (row.classList.contains("krea-product-line-row-hidden") || visibleRows <= 1) {';
print 'removeButton.style.display = "none";';
print '} else {';
print 'removeButton.style.display = "";';
print '}';
print '});';
print 'var addButton = modal.querySelector(".krea-product-line-add");';
print 'if (addButton) {';
print 'addButton.disabled = (visibleRows >= rows.length);';
print '}';
print '};';
print 'var kreaDecodeHtml = function(value){';
print 'if (!value) { return ""; }';
print 'var decoded = String(value);';
print 'if (typeof htmlEntityDecodeJs === "function") { decoded = htmlEntityDecodeJs(decoded); }';
print 'var node = document.createElement("div");';
print 'node.innerHTML = decoded;';
print 'return (node.textContent || node.innerText || "").replace(/\\s+/g, " ").trim();';
print '};';
print 'var kreaNormalizeSearch = function(value){';
print 'var normalized = String(value || "");';
print 'if (typeof normalized.normalize === "function") { normalized = normalized.normalize("NFD").replace(/[\\u0300-\\u036f]/g, ""); }';
print 'return normalized.toUpperCase().replace(/\\s+/g, " ").trim();';
print '};';
print 'var kreaMatchesSearchTerm = function(term, candidate){';
print 'if (term === "") { return true; }';
print 'var keywords = term.split(" ");';
print 'for (var i = 0; i < keywords.length; i++) {';
print 'if (keywords[i] && candidate.indexOf(keywords[i]) === -1) { return false; }';
print '}';
print 'return true;';
print '};';
print 'var kreaSupplierSearchTerms = {};';
print 'var kreaEnhanceSearchableSelect = function(selectElement){';
print 'if (!selectElement || !window.jQuery || !jQuery.fn || typeof jQuery.fn.select2 !== "function") { return; }';
print 'var $select = jQuery(selectElement);';
print 'var currentValue = $select.val();';
print 'var dropdownParent = null;';
print 'var modalParent = selectElement.closest(".krea-modal-overlay");';
print 'if (modalParent) { dropdownParent = jQuery(modalParent); }';
print 'if ($select.hasClass("select2-hidden-accessible")) {';
print 'try { $select.select2("destroy"); } catch (errorSelect2Destroy) { /* keep default rendering */ }';
print '}';
print '$select.select2({';
print 'dir: "ltr",';
print 'width: "resolve",';
print 'minimumInputLength: 0,';
print 'minimumResultsForSearch: 0,';
print 'dropdownParent: (dropdownParent || jQuery("body")),';
print 'language: (typeof select2arrayoflanguage === "undefined") ? "en" : select2arrayoflanguage,';
print 'matcher: function(params, data){';
print 'var term = kreaNormalizeSearch(params && params.term ? params.term : "");';
print 'if (term === "") { return data; }';
print 'var optionHtml = (data && data.element) ? (jQuery(data.element).attr("data-html") || "") : "";';
print 'var candidate = kreaNormalizeSearch((data && data.text ? data.text : "") + " " + kreaDecodeHtml(optionHtml));';
print 'return kreaMatchesSearchTerm(term, candidate) ? data : null;';
print '},';
print 'theme: "default",';
print 'containerCssClass: ":all:",';
print 'selectionCssClass: ":all:",';
print 'dropdownCssClass: "ui-dialog",';
print 'templateResult: function(data, container){';
print 'if (data.element) { jQuery(container).addClass(jQuery(data.element).attr("class")); }';
print 'if (data.id == "-1" && jQuery(data.element).attr("data-html") == undefined) { return "&nbsp;"; }';
print 'if (jQuery(data.element).attr("data-html") != undefined) {';
print 'return kreaDecodeHtml(jQuery(data.element).attr("data-html"));';
print '}';
print 'return data.text;';
print '},';
print 'templateSelection: function(selection){';
print 'if (selection.id == "-1") { return "<span class=\\"placeholder\\">" + selection.text + "</span>"; }';
print 'return selection.text;';
print '},';
print 'escapeMarkup: function(markup){ return markup; }';
print '});';
print 'if (typeof currentValue !== "undefined" && currentValue !== null) {';
print '$select.val(currentValue).trigger("change.select2");';
print '}';
print '};';
print 'var kreaExtractProductMetaFromText = function(rawText){';
print 'var text = (rawText ? String(rawText) : "").replace(/\\s+/g, " ").trim();';
print 'if (text === "") { return {ref: "", label: ""}; }';
print 'var pieces = text.split(" - ");';
print 'var ref = (pieces.length > 0 ? pieces[0].trim() : "");';
print 'var label = "";';
print 'if (pieces.length > 1) { label = pieces[1].trim(); }';
print 'if (label === "" && pieces.length > 2) { label = pieces.slice(1, -1).join(" - ").trim(); }';
print 'if (label === "") { label = text; }';
print 'return {ref: ref, label: label};';
print '};';
print 'var kreaReadProductMeta = function(row){';
print 'var meta = {id: 0, ref: "", label: ""};';
print 'if (!row) { return meta; }';
print 'var productField = row.querySelector("select[name^=entry_product_line_product_],input[name^=entry_product_line_product_]");';
print 'if (!productField) { return meta; }';
print 'meta.id = parseInt(productField.value || "0", 10) || 0;';
print 'var sourceText = "";';
print 'if (productField.tagName && productField.tagName.toUpperCase() === "SELECT") {';
print 'var selectedOption = productField.options && productField.selectedIndex >= 0 ? productField.options[productField.selectedIndex] : null;';
print 'if (selectedOption) {';
print 'sourceText = selectedOption.getAttribute("data-html") || selectedOption.text || "";';
print 'sourceText = kreaDecodeHtml(sourceText);';
print '}';
print '} else {';
print 'var searchInput = row.querySelector("input[name^=search_entry_product_line_product_]");';
print 'sourceText = searchInput ? String(searchInput.value || "") : "";';
print '}';
print 'var parsed = kreaExtractProductMetaFromText(sourceText);';
print 'meta.ref = parsed.ref || (meta.id > 0 ? String(meta.id) : "");';
print 'meta.label = parsed.label || "";';
print 'return meta;';
print '};';
print 'var kreaSyncProductRow = function(row, productMeta){';
print 'if (!row) { return; }';
print 'var meta = productMeta || kreaReadProductMeta(row);';
print 'var labelInput = row.querySelector("input[name^=entry_product_line_label_]");';
print 'if (labelInput) {';
print 'labelInput.value = (meta.id > 0 ? (meta.label || "") : "");';
print '}';
print 'var productSelect = row.querySelector("select[name^=entry_product_line_product_]");';
print 'if (productSelect && meta.id > 0 && meta.ref !== "") {';
print 'var select2Container = document.getElementById("select2-" + productSelect.id + "-container");';
print 'if (select2Container) {';
print 'select2Container.textContent = meta.ref;';
print 'select2Container.setAttribute("title", meta.ref);';
print '}';
print '}';
print '};';
print 'var kreaReadProductId = function(row){';
print 'return kreaReadProductMeta(row).id;';
print '};';
print 'var kreaBuildSupplierLines = function(modal){';
print 'var payload = {lines: [], hasSelectedLine: false, hasIncompleteLine: false, currentTotal: 0};';
print 'if (!modal) { return payload; }';
print 'modal.querySelectorAll(".krea-product-line-row").forEach(function(row){';
print 'if (row.classList.contains("krea-product-line-row-hidden")) { return; }';
print 'var productMeta = kreaReadProductMeta(row);';
print 'var productId = productMeta.id;';
print 'kreaSyncProductRow(row, productMeta);';
print 'var qtyInput = row.querySelector("input[name^=entry_product_line_qty_]");';
print 'var amountInput = row.querySelector("input[name^=entry_product_line_amount_]");';
print 'var labelInput = row.querySelector("input[name^=entry_product_line_label_]");';
print 'var qtyValue = kreaParseNumber(qtyInput ? qtyInput.value : 0);';
print 'var amountValue = kreaParseNumber(amountInput ? amountInput.value : 0);';
print 'var lineLabel = labelInput ? String(labelInput.value || "").trim() : "";';
print 'var lineIsEmpty = (productId <= 0 && qtyValue <= 0 && amountValue <= 0);';
print 'if (lineIsEmpty) { return; }';
print 'payload.hasSelectedLine = true;';
print 'if (productId <= 0 || qtyValue <= 0 || amountValue <= 0) {';
print 'payload.hasIncompleteLine = true;';
print 'return;';
print '}';
print 'payload.currentTotal += amountValue;';
print 'payload.lines.push({product_id: productId, label: lineLabel, qty: qtyValue, amount: Math.round(amountValue * 100) / 100});';
print '});';
print 'return payload;';
print '};';
print 'var kreaSyncSupplierLookup = function(form){';
print 'if (!form) { return; }';
print 'var lookupInput = form.querySelector("input[name=entry_supplier_lookup]");';
print 'if (!lookupInput) { return; }';
print 'var searchInput = form.querySelector("input[name=search_entry_supplier_socid]");';
print 'var supplierField = form.querySelector("select[name=entry_supplier_socid],input[name=entry_supplier_socid]");';
print 'var lookupValue = "";';
print 'if (supplierField && supplierField.tagName && supplierField.tagName.toUpperCase() === "SELECT") {';
print 'var selectedOption = supplierField.options && supplierField.selectedIndex >= 0 ? supplierField.options[supplierField.selectedIndex] : null;';
print 'if (selectedOption && parseInt(selectedOption.value || "0", 10) > 0) {';
print 'lookupValue = kreaDecodeHtml(selectedOption.getAttribute("data-html") || selectedOption.text || "");';
print '}';
print '}';
print 'if (lookupValue === "" && searchInput && String(searchInput.value || "").trim() !== "") {';
print 'lookupValue = String(searchInput.value || "").trim();';
print '}';
print 'if (lookupValue === "" && supplierField && supplierField.id && kreaSupplierSearchTerms[supplierField.id]) {';
print 'lookupValue = String(kreaSupplierSearchTerms[supplierField.id] || "").trim();';
print '}';
print 'if (lookupValue === "" && supplierField && supplierField.id) {';
print 'var renderedNode = document.getElementById("select2-" + supplierField.id + "-container");';
print 'if (renderedNode && !renderedNode.querySelector(".placeholder")) {';
print 'lookupValue = String(renderedNode.textContent || "").trim();';
print '}';
print '}';
print 'lookupInput.value = lookupValue;';
print '};';
print 'var kreaReadSupplierId = function(form){';
print 'if (!form) { return 0; }';
print 'var supplierField = form.querySelector("select[name=entry_supplier_socid],input[name=entry_supplier_socid]");';
print 'return parseInt(supplierField && supplierField.value ? supplierField.value : "0", 10) || 0;';
print '};';
print 'var kreaUpdateSupplierInvoiceOpenState = function(form){';
print 'if (!form) { return; }';
print 'var hasSupplier = (kreaReadSupplierId(form) > 0);';
print 'form.querySelectorAll("[data-krea-supplier-modal-open]").forEach(function(openButton){';
print 'openButton.disabled = false;';
print 'if (!hasSupplier) {';
print 'openButton.setAttribute("aria-disabled", "true");';
print 'openButton.setAttribute("title", openButton.getAttribute("data-krea-msg-supplier") || "");';
print '} else {';
print 'openButton.removeAttribute("aria-disabled");';
print 'openButton.removeAttribute("title");';
print '}';
print '});';
print '};';
print 'var kreaSetSupplierModalState = function(modal){';
print 'if (!modal) { return; }';
print 'var form = modal.closest("form");';
print 'if (form) { kreaSyncSupplierLookup(form); }';
print 'var targetAmount = kreaParseNumber(modal.getAttribute("data-target-total"));';
print 'if (targetAmount < 0) { targetAmount = 0; }';
print 'var build = kreaBuildSupplierLines(modal);';
print 'var targetNodeId = modal.getAttribute("data-target-total-id");';
print 'var currentNodeId = modal.getAttribute("data-current-total-id");';
print 'var feedbackId = modal.getAttribute("data-feedback-id");';
print 'var confirmId = modal.getAttribute("data-confirm-id");';
print 'var jsonInputId = modal.getAttribute("data-json-input-id");';
print 'var targetNode = targetNodeId ? document.getElementById(targetNodeId) : null;';
print 'var currentNode = currentNodeId ? document.getElementById(currentNodeId) : null;';
print 'var feedbackNode = feedbackId ? document.getElementById(feedbackId) : null;';
print 'var confirmButton = confirmId ? document.getElementById(confirmId) : null;';
print 'var jsonInput = jsonInputId ? document.getElementById(jsonInputId) : null;';
print 'if (targetNode) { targetNode.textContent = targetAmount.toFixed(2); }';
print 'if (currentNode) { currentNode.textContent = build.currentTotal.toFixed(2); }';
print 'if (jsonInput) { jsonInput.value = JSON.stringify(build.lines); }';
print 'var message = "";';
print 'var isValid = true;';
print 'if (!build.hasSelectedLine) {';
print 'message = modal.getAttribute("data-msg-empty") || "";';
print 'isValid = false;';
print '} else if (build.hasIncompleteLine) {';
print 'message = modal.getAttribute("data-msg-incomplete") || "";';
print 'isValid = false;';
print '} else if (Math.abs(build.currentTotal - targetAmount) > 0.01) {';
print 'message = modal.getAttribute("data-msg-mismatch") || "";';
print 'isValid = false;';
print '} else if (form) {';
print 'var supplierField = form.querySelector("select[name=entry_supplier_socid],input[name=entry_supplier_socid]");';
print 'var supplierId = parseInt(supplierField && supplierField.value ? supplierField.value : "0", 10) || 0;';
print 'if (supplierId <= 0) {';
print 'message = modal.getAttribute("data-msg-supplier") || "";';
print 'isValid = false;';
print '}';
print '}';
print 'if (feedbackNode) {';
print 'if (message !== "") {';
print 'feedbackNode.textContent = message;';
print 'feedbackNode.style.display = "block";';
print '} else {';
print 'feedbackNode.textContent = "";';
print 'feedbackNode.style.display = "none";';
print '}';
print '}';
print 'if (confirmButton) {';
print 'confirmButton.disabled = !isValid;';
print '}';
print '};';
print 'var kreaCloseSupplierModal = function(modal){';
print 'if (!modal) { return; }';
print 'modal.classList.remove("is-open");';
print 'modal.style.display = "none";';
print '};';
print 'var kreaOpenSupplierModal = function(modal, form){';
print 'if (!modal) { return; }';
print 'if (form) {';
print 'var amountInput = form.querySelector("input[name=entry_amount]");';
print 'if (amountInput) {';
print 'var targetAmount = kreaParseNumber(amountInput.value);';
print 'if (targetAmount > 0) {';
print 'modal.setAttribute("data-target-total", targetAmount.toFixed(2));';
print 'var firstAmountInput = modal.querySelector("input[name^=entry_product_line_amount_]");';
print 'if (firstAmountInput && kreaParseNumber(firstAmountInput.value) <= 0) { firstAmountInput.value = targetAmount.toFixed(2); }';
print '}';
print '}';
print '}';
print 'modal.classList.add("is-open");';
print 'modal.style.display = "flex";';
print 'kreaRefreshSupplierRows(modal);';
print 'kreaSetSupplierModalState(modal);';
print '};';
print 'document.addEventListener("input", function(event){';
print 'if (!event.target || !event.target.classList || !event.target.classList.contains("select2-search__field")) { return; }';
print 'var controls = event.target.getAttribute("aria-controls") || "";';
print 'var match = controls.match(/^select2-(.+)-results$/);';
print 'if (!match || !match[1]) { return; }';
print 'var fieldId = match[1];';
print 'var supplierField = document.getElementById(fieldId);';
print 'if (!supplierField || supplierField.name !== "entry_supplier_socid") { return; }';
print 'kreaSupplierSearchTerms[fieldId] = String(event.target.value || "").trim();';
print '});';
print 'document.querySelectorAll("select[name=entry_supplier_socid],select[name^=entry_product_line_product_]").forEach(function(selectElement){';
print 'kreaEnhanceSearchableSelect(selectElement);';
print '});';
print 'document.querySelectorAll("form[id^=krea_quick_entry_form_]").forEach(function(form){';
print 'form.addEventListener("submit", function(){ kreaSyncSupplierLookup(form); });';
print 'var supplierField = form.querySelector("select[name=entry_supplier_socid],input[name=entry_supplier_socid]");';
print 'if (supplierField) {';
print 'supplierField.addEventListener("change", function(){ kreaSyncSupplierLookup(form); kreaUpdateSupplierInvoiceOpenState(form); });';
print '}';
print 'kreaUpdateSupplierInvoiceOpenState(form);';
print '});';
print 'document.querySelectorAll("[data-krea-supplier-modal-open]").forEach(function(openButton){';
print 'openButton.addEventListener("click", function(){';
print 'var modalId = openButton.getAttribute("data-krea-supplier-modal-open");';
print 'var formId = openButton.getAttribute("data-krea-form-id");';
print 'var modal = modalId ? document.getElementById(modalId) : null;';
print 'var form = formId ? document.getElementById(formId) : null;';
print 'if (form && kreaReadSupplierId(form) <= 0) {';
print 'var supplierMessage = openButton.getAttribute("data-krea-msg-supplier") || "";';
print 'if (supplierMessage !== "") { window.alert(supplierMessage); }';
print 'kreaUpdateSupplierInvoiceOpenState(form);';
print 'return;';
print '}';
print 'kreaOpenSupplierModal(modal, form);';
print '});';
print '});';
print 'document.querySelectorAll("[data-krea-modal-close]").forEach(function(closeButton){';
print 'closeButton.addEventListener("click", function(){';
print 'var modalId = closeButton.getAttribute("data-krea-modal-close");';
print 'var modal = modalId ? document.getElementById(modalId) : null;';
print 'kreaCloseSupplierModal(modal);';
print '});';
print '});';
print 'document.querySelectorAll(".krea-supplier-lines-modal").forEach(function(modal){';
print 'modal.querySelectorAll(".krea-product-line-row").forEach(function(row){ kreaSyncProductRow(row); });';
print 'kreaRefreshSupplierRows(modal);';
print 'kreaSetSupplierModalState(modal);';
print 'modal.addEventListener("click", function(event){';
print 'if (event.target === modal) { kreaCloseSupplierModal(modal); }';
print '});';
print 'var addButton = modal.querySelector(".krea-product-line-add");';
print 'if (addButton) {';
print 'addButton.addEventListener("click", function(){';
print 'var nextRow = modal.querySelector(".krea-product-line-row-hidden");';
print 'if (!nextRow) { return; }';
print 'nextRow.classList.remove("krea-product-line-row-hidden");';
print 'var qtyInput = nextRow.querySelector("input[name^=entry_product_line_qty_]");';
print 'if (qtyInput && kreaParseNumber(qtyInput.value) <= 0) { qtyInput.value = "1"; }';
print 'var amountInput = nextRow.querySelector("input[name^=entry_product_line_amount_]");';
print 'if (amountInput && kreaParseNumber(amountInput.value) > 0) { amountInput.value = (Math.round(kreaParseNumber(amountInput.value) * 100) / 100).toFixed(2); }';
print 'kreaSyncProductRow(nextRow);';
print 'kreaRefreshSupplierRows(modal);';
print 'kreaSetSupplierModalState(modal);';
print '});';
print '}';
print 'modal.querySelectorAll(".krea-product-line-remove").forEach(function(removeButton){';
print 'removeButton.addEventListener("click", function(){';
print 'var row = removeButton.closest(".krea-product-line-row");';
print 'if (!row) { return; }';
print 'row.classList.add("krea-product-line-row-hidden");';
print 'row.querySelectorAll("input").forEach(function(input){';
print 'if (input.name && input.name.indexOf("entry_product_line_qty_") === 0) { input.value = ""; return; }';
print 'if (input.name && input.name.indexOf("entry_product_line_amount_") === 0) { input.value = ""; return; }';
print 'if (input.name && input.name.indexOf("entry_product_line_label_") === 0) { input.value = ""; return; }';
print 'if (input.name && input.name.indexOf("entry_product_line_product_") === 0) { input.value = ""; return; }';
print 'if (input.name && input.name.indexOf("search_entry_product_line_product_") === 0) { input.value = ""; }';
print '});';
print 'row.querySelectorAll("select[name^=entry_product_line_product_]").forEach(function(select){ select.value = ""; });';
print 'kreaRefreshSupplierRows(modal);';
print 'kreaSetSupplierModalState(modal);';
print '});';
print '});';
print 'modal.querySelectorAll("input[name^=entry_product_line_amount_]").forEach(function(amountInput){';
print 'amountInput.addEventListener("blur", function(){';
print 'var value = kreaParseNumber(amountInput.value);';
print 'if (value <= 0) { amountInput.value = ""; kreaSetSupplierModalState(modal); return; }';
print 'amountInput.value = (Math.round(value * 100) / 100).toFixed(2);';
print 'kreaSetSupplierModalState(modal);';
print '});';
print '});';
print 'modal.addEventListener("input", function(event){';
print 'if (event.target && event.target.name && (event.target.name.indexOf("entry_product_line_") === 0 || event.target.name.indexOf("search_entry_product_line_product_") === 0)) {';
print 'kreaSetSupplierModalState(modal);';
print '}';
print '});';
print 'modal.addEventListener("change", function(event){';
print 'if (event.target && event.target.name && (event.target.name.indexOf("entry_product_line_") === 0 || event.target.name.indexOf("search_entry_product_line_product_") === 0)) {';
print 'kreaSetSupplierModalState(modal);';
print '}';
print '});';
print '});';
print 'document.addEventListener("keydown", function(event){';
print 'if (event.key !== "Escape") { return; }';
print 'document.querySelectorAll(".krea-supplier-lines-modal.is-open").forEach(function(modal){';
print 'kreaCloseSupplierModal(modal);';
print '});';
print '});';
print '</script>';

print dol_get_fiche_end();
llxFooter();
$db->close();
