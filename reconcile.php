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
 * \brief      Selected-line reconciliation workspace.
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
$navigationAccountId = max(0, GETPOSTINT('account_context'));
$navigationAccount = kreabankNavigationAccount($navigationAccountId);
$pendingColumns = array('b.dateo' => 'KreaBankDate', 'm.description' => 'KreaBankDescription', 'b.amount' => 'KreaBankAmount');
$pendingSort = kreabankListSort($pendingColumns, 'b.dateo');
$documentColumns = array('suggestion' => 'KreaBankBulkMatchSuggested', 'ref' => 'Ref', 'doc_date' => 'KreaBankDate', 'thirdparty_name' => 'KreaBankCounterparty', 'amount_open' => 'KreaBankAmount');
$documentSort = kreabankListSort($documentColumns, 'suggestion', 'DESC', 'doc_');
$service = new KreaBankService($db, $user, $langs);
$form = new Form($db);
$canNativeWrite = ($user->hasRight('banque', 'modifier') || $user->hasRight('banque', 'consolidate'));
$canQuickSupplierInvoice = (isModEnabled('supplier_invoice') && $user->hasRight('fournisseur', 'facture', 'lire') && $user->hasRight('fournisseur', 'facture', 'creer'));
$canQuickTaxEntry = (isModEnabled('tax') && $user->hasRight('tax', 'charges', 'lire') && $user->hasRight('tax', 'charges', 'creer'));
$quickEntryFailed = false;
$quickEntryFormState = array();
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
			$quickEntryFailed = true;
			$quickEntryFormState = array(
				'choice' => $submitAction, 'label' => $label, 'amount' => $amount, 'note' => $note,
				'supplier_id' => $supplierSocid, 'supplier_lookup' => $supplierLookup,
				'supplier_ref' => $supplierRef, 'lines' => $invoiceProductLines,
			);
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

	if (!empty($requestedLine)) {
		if ($navigationAccountId > 0 && (int) $requestedLine['bank_account_id'] !== $navigationAccountId) {
			$requestedLine = null;
			$lineId = 0;
			$requestedLineId = 0;
		} elseif ($navigationAccountId === 0) {
			$navigationAccountId = (int) $requestedLine['bank_account_id'];
			$navigationAccount = kreabankNavigationAccount($navigationAccountId);
		}
	}

	$pendingLines = $service->getPendingLines(200, 0, $pendingSort['field'], $pendingSort['order'], array('bank_account_id' => $navigationAccountId));
	$pendingLinesTotal = $service->getPendingLinesCount(array('bank_account_id' => $navigationAccountId));
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
$renderQuickEntryForm = static function ($line, $openDocSearch, $prefillLabel, $prefillAmount, $prefillNote, $supplierLookupPrefill = '', $supplierRefPrefill = '', $supplierPredictedSocid = 0, $supplierPrediction = array(), $showSupplierInvoiceButton = false, $showTaxEntryButton = false) use ($navigationAccountId, $langs, $forceBatchDetect, $form, $quickSupplierProductLineSlots, $decodeHtmlEntitiesRecursive, $quickEntryFormState) {
	if (empty($line)) {
		return;
	}

	$lineIdValue = !empty($line['rowid']) ? (int) $line['rowid'] : 0;
	if ($lineIdValue <= 0) {
		return;
	}

	$formDomId = 'krea_quick_entry_form_' . $lineIdValue;
	$supplierModalId = 'krea_supplier_invoice_modal_' . $lineIdValue;
	$supplierTargetTotalId = 'krea_supplier_invoice_target_' . $lineIdValue;
	$supplierCurrentTotalId = 'krea_supplier_invoice_current_' . $lineIdValue;
	$supplierFeedbackId = 'krea_supplier_invoice_feedback_' . $lineIdValue;
	$supplierConfirmId = 'krea_supplier_invoice_confirm_' . $lineIdValue;
	$supplierJsonInputId = 'krea_supplier_invoice_lines_json_' . $lineIdValue;
	if ($quickEntryFormState) {
		$prefillLabel = $quickEntryFormState['label'];
		$prefillAmount = $quickEntryFormState['amount'];
		$prefillNote = $quickEntryFormState['note'];
		$supplierLookupPrefill = $quickEntryFormState['supplier_lookup'];
		$supplierRefPrefill = $quickEntryFormState['supplier_ref'];
		$supplierPredictedSocid = $quickEntryFormState['supplier_id'];
	}
	$prefillAmountNumber = (float) price2num((string) $prefillAmount, 'MU');
	if ($prefillAmountNumber <= 0) {
		$prefillAmountNumber = 0.0;
	}
	print '<form id="' . $formDomId . '" method="POST" action="' . dol_escape_htmltag($_SERVER['PHP_SELF']) . '" data-locale="' . dol_escape_htmltag(str_replace('_', '-', $langs->defaultlang)) . '" data-initial-lines="' . dol_escape_htmltag(json_encode($quickEntryFormState['lines'] ?? array())) . '">';
	print kreabankNavigationHiddenFields($navigationAccountId);
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
	print '<fieldset class="kb-create-choices"><legend>' . $langs->trans('KreaBankWorkspaceCreateChoice') . '</legend>';
	$choices = array('payment_entry' => 'Bank');
	if ($showTaxEntryButton) { $choices['tax_entry'] = 'Tax'; }
	if ($showSupplierInvoiceButton) { $choices['supplier_invoice'] = 'Invoice'; }
	foreach ($choices as $choice => $label) {
		print '<label class="kb-create-choice"><input type="radio" name="creation_choice" value="' . $choice . '"' . ($choice === ($quickEntryFormState['choice'] ?? 'payment_entry') ? ' checked' : '') . '><span><strong>' . $langs->trans('KreaBankWorkspaceChoice' . $label) . '</strong><small>' . $langs->trans('KreaBankWorkspaceConsequence' . $label) . '</small></span></label>';
	}
	print '</fieldset>';
	print '<input type="hidden" name="entry_type" value="payment">';
	print '<div class="kb-entry-fields">';
	print '<div class="kb-field kb-field-wide"><label for="kb-entry-label">' . $langs->trans('KreaBankQuickEntryLabel') . '</label><input id="kb-entry-label" type="text" name="entry_label" value="' . dol_escape_htmltag((string) $prefillLabel) . '" required maxlength="190"></div>';
	print '<div class="kb-field"><label for="kb-entry-amount">' . $langs->trans('KreaBankQuickEntryAmount') . ' (' . dol_escape_htmltag($line['currency']) . ')</label><input id="kb-entry-amount" type="number" step="0.01" min="0.01" name="entry_amount" value="' . number_format($prefillAmountNumber, 2, '.', '') . '" required inputmode="decimal"></div>';
	$selectedSupplierInput = trim((string) $supplierLookupPrefill);
	if ($selectedSupplierInput === '' && !empty($supplierPrediction['predicted_name'])) { $selectedSupplierInput = $decodeHtmlEntitiesRecursive($supplierPrediction['predicted_name']); }
	$selectedSupplierId = ((int) $supplierPredictedSocid > 0 ? (int) $supplierPredictedSocid : '');
	print '<div class="kb-field" data-kb-supplier-field><label for="entry_supplier_socid">' . $langs->trans('KreaBankQuickSupplierInvoiceSupplier') . '</label>';
	print $form->select_company($selectedSupplierId, 'entry_supplier_socid', '(s.fournisseur:>:0)', 'KreaBankQuickSupplierLookupPlaceholder', 0, 0, array(), 0, 'minwidth300', '', $selectedSupplierInput, 1);
	if (!empty($supplierPrediction['is_confident']) && !empty($supplierPrediction['predicted_name'])) {
		print '<small>' . $langs->trans('KreaBankSupplierMlPrediction', dol_escape_htmltag($decodeHtmlEntitiesRecursive($supplierPrediction['predicted_name'])), (int) $supplierPrediction['probability_pct'], (int) $supplierPrediction['threshold_pct']) . '</small>';
	}
	print '<small data-kb-supplier-feedback role="alert" hidden></small></div>';
	print '<div class="kb-field kb-field-wide" data-kb-invoice-field><label for="kb-entry-ref">' . $langs->trans('KreaBankQuickSupplierInvoiceRefSupplier') . '</label><input id="kb-entry-ref" type="text" name="entry_ref_supplier" value="' . dol_escape_htmltag((string) $supplierRefPrefill) . '"></div>';
	print '<div class="kb-field kb-field-wide"><label for="kb-entry-note">' . $langs->trans('KreaBankReason') . '</label><textarea id="kb-entry-note" name="entry_note" rows="2">' . dol_escape_htmltag((string) $prefillNote, 0, 1) . '</textarea></div></div>';
	print '<div class="kb-create-actions">';
	print '<button class="button kb-primary-action" data-kb-create-action="payment_entry" type="submit" name="entry_submit_action" value="payment_entry">' . $langs->trans('KreaBankWorkspaceCreateBank') . '</button>';
	if ($showTaxEntryButton) { print '<button class="button kb-primary-action" data-kb-create-action="tax_entry" type="submit" name="entry_submit_action" value="tax_entry">' . $langs->trans('KreaBankWorkspaceCreateTax') . '</button>'; }
	if ($showSupplierInvoiceButton) {
		$supplierRequiredMsg = $langs->transnoentitiesnoconv('ErrorFieldRequired', $langs->transnoentitiesnoconv('KreaBankQuickSupplierInvoiceSupplier'));
		print '<button class="button kb-primary-action" data-kb-create-action="supplier_invoice" type="button" data-krea-supplier-modal-open="' . $supplierModalId . '" data-krea-form-id="' . $formDomId . '" data-krea-msg-supplier="' . dol_escape_htmltag($supplierRequiredMsg) . '">' . $langs->trans('KreaBankWorkspaceReviewInvoice') . '</button>';
	}
	print '</div>';
	if (!empty($showSupplierInvoiceButton)) {
		print '<div id="' . $supplierModalId . '" class="krea-modal-overlay krea-supplier-lines-modal" role="dialog" aria-modal="true" aria-labelledby="' . $supplierModalId . '_title" tabindex="-1"';
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
		print '<div class="krea-modal-title" id="' . $supplierModalId . '_title">' . $langs->trans('KreaBankQuickSupplierLinesTitle') . '</div>';
		print '<button type="button" aria-label="' . dol_escape_htmltag($langs->trans('Close')) . '" class="butAction krea-modal-close" data-krea-modal-close="' . $supplierModalId . '">x</button>';
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
			print '<td><label class="kb-sr-only" for="entry_product_line_product_' . $lineSlot . '">' . $langs->trans('Product') . '</label>' . $form->select_produits(
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
			print '<td><input class="flat minwidth200 krea-product-line-label" type="text" aria-label="' . dol_escape_htmltag($langs->trans('Description')) . '" name="entry_product_line_label_' . $lineSlot . '" value="" readonly></td>';
			print '<td class="center" data-label="' . dol_escape_htmltag($langs->trans('Qty')) . '"><input class="flat right krea-product-line-qty" type="number" step="0.000001" min="0" aria-label="' . dol_escape_htmltag($langs->trans('Qty')) . '" name="entry_product_line_qty_' . $lineSlot . '" value="' . $qtyDefault . '"></td>';
			print '<td class="right" data-label="' . dol_escape_htmltag($langs->trans('Amount')) . '"><input class="flat right krea-product-line-amount" type="number" step="0.01" min="0" aria-label="' . dol_escape_htmltag($langs->trans('Amount')) . '" name="entry_product_line_amount_' . $lineSlot . '" value="' . $amountDefault . '" inputmode="decimal"></td>';
			print '<td class="center"><button type="button" class="butAction krea-product-line-remove" data-krea-product-remove="' . $lineSlot . '" title="' . dol_escape_htmltag($langs->transnoentitiesnoconv('Delete')) . '" aria-label="' . dol_escape_htmltag($langs->transnoentitiesnoconv('Delete')) . '"' . ($lineSlot === 0 ? ' style="display:none"' : '') . '>' . $deleteIcon . '</button></td>';
			print '</tr>';
		}
		print '</table>';
		print '<div style="margin-top:10px;display:flex;gap:8px;align-items:center;flex-wrap:wrap">';
		print '<button type="button" class="butAction krea-product-line-add">' . $langs->trans('KreaBankQuickSupplierLinesAddRow') . '</button>';
		print '<span class="krea-muted">' . $langs->trans('KreaBankQuickSupplierLinesTarget') . ': <strong id="' . $supplierTargetTotalId . '">' . kreabankPrice($prefillAmountNumber) . '</strong></span>';
		print '<span class="krea-muted">' . $langs->trans('KreaBankQuickSupplierLinesCurrent') . ': <strong id="' . $supplierCurrentTotalId . '">' . kreabankPrice(0) . '</strong></span>';
		print '</div>';
		print '<div id="' . $supplierFeedbackId . '" role="status" aria-live="polite" class="warning" style="margin-top:8px;display:none"></div>';
		print '<div class="tabsAction" style="margin-top:10px">';
		print '<button type="button" class="butAction" data-krea-modal-close="' . $supplierModalId . '">' . $langs->trans('KreaBankQuickSupplierLinesClose') . '</button>';
		print '<button class="button kb-primary-action" type="button" name="entry_submit_action" value="supplier_invoice" id="' . $supplierConfirmId . '">' . $langs->trans('KreaBankWorkspaceCreateInvoice') . '</button>';
		print '</div>';
		print '</div>';
		print '</div>';
	}
	print '</form>';
};

llxHeader('', $langs->trans('KreaBankReconcile'), '', '', 0, 0, '', '', '', 'mod-kreabank page-reconcile');

if (!empty($quickCreateSuccessAlert['enabled']) && !empty($quickCreateSuccessAlert['message'])) {
	$stickyMessage = (string) $quickCreateSuccessAlert['message'];
	$stickyUrl = trim((string) $quickCreateSuccessAlert['url']);
	$stickyRef = trim((string) $quickCreateSuccessAlert['ref']);
	if ($stickyUrl !== '' && $stickyRef !== '') {
		$stickyMessage .= ' <a href="' . dol_escape_htmltag($stickyUrl) . '" target="_blank" rel="noopener">' . dol_escape_htmltag($stickyRef) . '</a>';
	}
	print '<div class="krea-embedded-success-alert" role="status">';
	dol_htmloutput_mesg($stickyMessage, array(), 'ok', 1);
	print '</div>';
}

kreabankPrintWorkspaceNavigation('reconcile', $navigationAccount);
print load_fiche_titre($langs->trans('KreaBankReconcile'), '', 'bank');
if ($selectedLine && !empty($selectedLine['fk_statement'])) {
	try {
		$accountReview = $service->getPaymentAccountReview($selectedLine['rowid'], array($selectedLine['bank_account_id']));
		if ($accountReview) { print kreabankPaymentAccountReviewHtml($accountReview, $langs); }
	} catch (Throwable $e) {
		print '<p class="error">' . $langs->trans('KreaBankAccountReviewUnavailable') . '</p>';
	}
}


print '<link rel="stylesheet" href="' . dol_buildpath('/custom/kreabank/css/reconcile.css', 1) . '?v=' . filemtime(__DIR__ . '/css/reconcile.css') . '">';

print '<details class="kb-help"><summary>' . $langs->trans('KreaBankAssistedGuide') . '</summary><p>' . $langs->trans('KreaBankAssistedReconciliationSubtitle') . '</p></details>';

$canCreate = true;
$creationUnavailable = false;
if ($selectedLine) {
	try {
		$canCreate = ((new KreaBankAccountPairs($db, (int) $conf->entity))->mainAccount((int) $selectedLine['bank_account_id']) <= 0);
	} catch (Exception $e) {
		$canCreate = false;
		$creationUnavailable = true;
	}
}
$canReconcile = ($user->hasRight('kreabank', 'reconciliation', 'write') && $canNativeWrite);
// Keep explicit searches in matching; an empty initial candidate set opens permitted creation.
$requestedWorkflow = GETPOST('workflow', 'aZ09');
$initialWorkflow = ($quickEntryFailed || ($selectedLine && $canReconcile && $canCreate && ($requestedWorkflow === 'create' || ($requestedWorkflow !== 'match' && empty($documents) && $openDocumentsSearch === '' && !$forceBatchDetect)))) ? 'create' : 'match';
print '<div class="kb-recon" data-initial-workflow="' . $initialWorkflow . '">';
print '<aside class="kb-queue" aria-labelledby="kb-queue-heading"><details class="kb-queue-disclosure" open>';
print '<summary class="kb-queue-heading"><h2 id="kb-queue-heading">' . $langs->trans('KreaBankWorkspacePending') . '</h2><span class="kb-count">' . ((int) $pendingLinesTotal) . '</span></summary>';
print '<div class="kb-queue-search kb-js-only"><label for="kb-pending-search">' . $langs->trans('KreaBankWorkspaceSearchQueue') . '</label><input type="search" id="kb-pending-search" autocomplete="off" aria-controls="kb-pending-list" placeholder="' . dol_escape_htmltag($langs->trans('KreaBankWorkspaceSearchHint')) . '"></div>';
$reconciliationSortParams = array_merge(kreabankListParameters(), array('account_context' => $navigationAccountId, 'line_id' => $lineId));
print kreabankListSortLinks($pendingColumns, $pendingSort, $reconciliationSortParams);
print '<nav id="kb-pending-list" class="kb-pending-list" aria-label="' . dol_escape_htmltag($langs->trans('KreaBankWorkspacePending')) . '">';
foreach ($pendingLines as $line) {
	$isSelected = ((int) $line['rowid'] === (int) $lineId);
	$lineTitle = trim((string) $line['description']);
	if ($lineTitle === '') { $lineTitle = (string) ($line['counterparty_name'] ?: $line['payment_reference']); }
	if ($lineTitle === '') { $lineTitle = $langs->trans('KreaBankStatementLine') . ' #' . (int) $line['rowid']; }
	$lineDate = dol_print_date(strtotime((string) $line['operation_date']), 'day');
	$queueParams = array('line_id' => (int) $line['rowid'], 'sortfield' => $pendingSort['field'], 'sortorder' => $pendingSort['order']);
	$lineUrl = kreabankWorkspaceUrl('reconcile', $navigationAccountId) . '&' . http_build_query($queueParams);
	$searchText = implode(' ', array($lineTitle, $line['counterparty_name'], $line['payment_reference'], $line['bank_reference'], $line['bank_account_ref'], $lineDate, $line['amount'], kreabankPrice($line['amount']), $line['currency']));
	print '<a class="kb-queue-line' . ($isSelected ? ' selected' : '') . '" id="linecard-' . ((int) $line['rowid']) . '" href="' . dol_escape_htmltag($lineUrl) . '" data-search="' . dol_escape_htmltag($searchText) . '"' . ($isSelected ? ' aria-current="true"' : '') . '>';
	print '<span class="kb-queue-description">' . dol_escape_htmltag($lineTitle) . '</span>';
	print '<span class="kb-queue-meta"><span>' . dol_escape_htmltag($line['bank_account_ref']) . ' · ' . $lineDate . '</span><strong class="kb-money ' . ((float) $line['amount'] < 0 ? 'kb-debit' : 'kb-credit') . '">' . ((float) $line['amount'] > 0 ? '+' : '') . kreabankPrice($line['amount']) . ' ' . dol_escape_htmltag($line['currency']) . '</strong></span>';
	if (!empty($line['is_duplicate'])) { print '<span class="kb-duplicate">' . $langs->trans('KreaBankDuplicate') . '</span>'; }
	print '</a>';
}
print '</nav>';
print '<p class="kb-empty" id="kb-queue-empty"' . (!empty($pendingLines) ? ' hidden' : '') . '>' . $langs->trans(empty($pendingLines) ? 'KreaBankNoStatementLines' : 'KreaBankWorkspaceNoSearchResults') . '</p>';
print '<div class="kb-queue-footer"><span id="kb-queue-count" role="status" aria-live="polite">' . count($pendingLines) . ' / ' . ((int) $pendingLinesTotal) . '</span> · <a href="' . dol_escape_htmltag(kreabankWorkspaceUrl('pending', $navigationAccountId)) . '">' . $langs->trans('KreaBankWorkspaceAllPending') . '</a></div>';
print '</details></aside>';
print '<div class="kb-recon-main">';

if (!$selectedLine) {
	print '<div class="opacitymedium">' . $langs->trans('KreaBankNoStatementLines') . '</div>';
} else {
	$selectedNativeLineId = !empty($selectedLine['native_bank_line_id']) ? (int) $selectedLine['native_bank_line_id'] : 0;
	$forceBatchUrl = kreabankWorkspaceUrl('reconcile', $navigationAccountId) . '&line_id=' . (int) $selectedLine['rowid'] . '&force_batch_detect=1';
	if ($openDocumentsSearch !== '') { $forceBatchUrl .= '&open_doc_search=' . urlencode($openDocumentsSearch); }
	print '<section class="kb-source" aria-labelledby="kb-source-heading">';
	print '<div class="kb-source-top"><span class="kb-eyebrow">' . $langs->trans('KreaBankWorkspaceSelectedLine') . '</span><span class="kb-source-status">' . kreabankStatusLabel((int) $selectedLine['status'], $langs) . '</span></div>';
	print '<div class="kb-source-heading"><h2 id="kb-source-heading" tabindex="-1">' . dol_escape_htmltag((string) ($selectedLine['description'] ?: $selectedLine['counterparty_name'])) . '</h2><strong class="kb-source-amount kb-money">' . ((float) $selectedLine['amount'] > 0 ? '+' : '') . kreabankPrice($selectedLine['amount']) . ' <small>' . dol_escape_htmltag($selectedLine['currency']) . '</small></strong></div>';
	print '<dl class="kb-source-facts"><div><dt>' . $langs->trans('BankAccount') . '</dt><dd>' . dol_escape_htmltag((string) $selectedLine['bank_account_ref']) . '</dd></div><div><dt>' . $langs->trans('KreaBankDate') . '</dt><dd>' . dol_print_date(strtotime((string) $selectedLine['operation_date']), 'day') . '</dd></div><div><dt>' . $langs->trans('KreaBankWorkspaceRemaining') . '</dt><dd id="kb-source-remaining">' . kreabankPrice($selectedOutstanding) . ' ' . dol_escape_htmltag($selectedLine['currency']) . '</dd></div></dl>';
	print '<details class="kb-source-details"><summary>' . $langs->trans('KreaBankWorkspaceSourceDetails') . '</summary><dl class="kb-source-facts">';
	foreach (array('counterparty_name' => 'ThirdParty', 'counterparty_iban' => 'IBAN', 'payment_reference' => 'KreaBankReference', 'bank_reference' => 'Ref') as $field => $labelKey) {
		if (trim((string) $selectedLine[$field]) !== '') { print '<div><dt>' . $langs->trans($labelKey) . '</dt><dd>' . dol_escape_htmltag($selectedLine[$field]) . '</dd></div>'; }
	}
	print '</dl>';
	if ($selectedNativeLineId > 0) { print '<a href="' . dol_buildpath('/compta/bank/line.php?rowid=' . $selectedNativeLineId, 1) . '">' . $langs->trans('KreaBankOpenNativeLine') . '</a>'; }
	print '</details></section>';
	if (!$canReconcile) { print '<p class="kb-readonly" role="status">' . $langs->trans('KreaBankWorkspaceReadOnly') . '</p>'; }
	print '<div class="kb-workflow-switch kb-js-only" role="tablist" aria-label="' . dol_escape_htmltag($langs->trans('KreaBankWorkspaceWorkflow')) . '">';
	foreach (array('match' => 'KreaBankWorkspaceMatch', 'create' => 'KreaBankWorkspaceCreate') as $workflow => $label) {
		print '<button type="button" role="tab" id="kb-tab-' . $workflow . '" aria-controls="kb-panel-' . $workflow . '" aria-selected="' . ($workflow === $initialWorkflow ? 'true' : 'false') . '" tabindex="' . ($workflow === $initialWorkflow ? '0' : '-1') . '">' . $langs->trans($label) . '</button>';
	}
	print '</div>';
	print '<section class="kb-workflow-panel" id="kb-panel-match" aria-labelledby="kb-match-heading"><h3 id="kb-match-heading">' . $langs->trans('KreaBankWorkspaceMatch') . '</h3><p class="kb-panel-intro">' . $langs->trans('KreaBankWorkspaceMatchHelp') . '</p>';
	print '<form class="kb-document-search" method="GET" action="' . dol_escape_htmltag($_SERVER['PHP_SELF']) . '">';
	print kreabankNavigationHiddenFields($navigationAccountId);
	print '<input type="hidden" name="line_id" value="' . (int) $selectedLine['rowid'] . '">';
	if ($forceBatchDetect) { print '<input type="hidden" name="force_batch_detect" value="1">'; }
	print '<label for="kb-document-search">' . $langs->trans('KreaBankWorkspaceSearchEntries') . '</label><div><input id="kb-document-search" type="search" name="open_doc_search" value="' . dol_escape_htmltag($openDocumentsSearch) . '"><button type="submit" class="button">' . $langs->trans('Search') . '</button></div></form>';
	if (!$isLoteBatchLine) { print '<a class="kb-batch-link" href="' . dol_escape_htmltag($forceBatchUrl) . '">' . $langs->trans('KreaBankBatchDetectForce') . '</a>'; }
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
			print '<div class="info">' . $langs->trans('KreaBankWorkspaceBatchExact', (int) $loteSubsetSuggestion['selected_count'], (int) $loteSubsetSuggestion['candidate_count'], kreabankPrice($subsetSelectedAmount) . ' ' . $subsetCurrency) . '</div>';
		} else {
			print '<div class="warning">' . $langs->trans('KreaBankWorkspaceBatchPartial', (int) $loteSubsetSuggestion['selected_count'], kreabankPrice($subsetSelectedAmount) . ' ' . $subsetCurrency, kreabankPrice($subsetDiffAmount) . ' ' . $subsetCurrency) . '</div>';
		}
	}

	print '<form method="POST" action="' . dol_escape_htmltag($_SERVER['PHP_SELF']) . '" id="manualReconcileForm" data-outstanding="' . dol_escape_htmltag((string) $selectedOutstanding) . '" data-currency="' . dol_escape_htmltag($selectedLine['currency']) . '" data-locale="' . dol_escape_htmltag(str_replace('_', '-', $langs->defaultlang)) . '">';
	print kreabankNavigationHiddenFields($navigationAccountId);
	print '<input type="hidden" name="token" value="' . newToken() . '">';
	print '<input type="hidden" name="action" value="reconcile">';
	print '<input type="hidden" name="line_id" value="' . ((int) $selectedLine['rowid']) . '">';
	print '<input type="hidden" name="open_doc_search" value="' . dol_escape_htmltag($openDocumentsSearch) . '">';
	if ((int) $forceBatchDetect > 0) {
		print '<input type="hidden" name="force_batch_detect" value="1">';
	}

	print kreabankListSortLinks($documentColumns, $documentSort, $reconciliationSortParams);
	$documents = kreabankListOrderRows($documents, $documentSort, static function ($doc, $field) use ($suggestionByKey) {
		if ($field === 'suggestion') { return (int) ($suggestionByKey[$doc['doc_type'] . '__' . $doc['rowid']]['score'] ?? 0); }
		return $doc[$field] ?? '';
	});
	if (empty($documents)) {
		print '<div class="kb-empty"><strong>' . $langs->trans('KreaBankWorkspaceNoEntries') . '</strong><p>' . $langs->trans('KreaBankWorkspaceNoEntriesHelp') . '</p></div>';
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
				print '<input type="checkbox" name="selected_docs[]" value="' . $docKey . '"' . ($shouldCheck ? ' checked' : '') . (!$canReconcile ? ' disabled' : '') . '>';
			}
			print '<span>';
			if ($docCardUrl !== '') {
				print '<strong><a href="' . $docCardUrl . '" target="_blank" rel="noopener">' . dol_escape_htmltag((string) $doc['ref']) . '</a></strong>';
			} else {
				print '<strong>' . dol_escape_htmltag((string) $doc['ref']) . '</strong>';
			}
			print ' <span class="krea-muted">' . $langs->trans('KreaBankWorkspaceBankEntry') . '</span><br>';
			print '<span class="krea-muted">' . dol_escape_htmltag((string) $doc['thirdparty_name']) . ' • ' . dol_print_date(strtotime((string) $doc['doc_date']), 'day') . '</span></span>';
			print '</label>';
			if (!empty($doc['linked_bank_line'])) {
				$linkedLineUrl = dol_buildpath('/compta/bank/line.php?rowid=' . (int) $doc['linked_bank_line'], 1);
				$linkedLineDate = !empty($doc['linked_bank_date']) ? dol_print_date(strtotime((string) $doc['linked_bank_date']), 'day') : '';
				$lockedDetailLabel = $isLockedReconciled ? $langs->trans('KreaBankAlreadyConciliated') : $langs->trans('KreaBankAlreadyLinked');
				print '<div class="krea-muted">' . $lockedDetailLabel . ': <a href="' . $linkedLineUrl . '" target="_blank" rel="noopener">#' . ((int) $doc['linked_bank_line']) . '</a>' . ($linkedLineDate !== '' ? ' • ' . $linkedLineDate : '') . '</div>';
			}
			if ($score > 0) {
				$isSharedSafe = (!$isLoteBatchLine && !empty($safeSuggestion) && ((string) $safeSuggestion['doc_type'] . '__' . (int) $safeSuggestion['doc_id']) === $docKey);
				$badgeClass = $isSharedSafe ? 'krea-badge-safe' : 'krea-badge-mid';
				print '<div style="margin-top:4px">';
				print '<span class="krea-badge ' . $badgeClass . '">' . $langs->trans('KreaBankBulkMatchScore') . ' ' . $score . '</span>';
				foreach ($details as $detail) {
					$reasonKey = 'KreaBankMatchReason_' . $detail;
					$reasonLabel = $langs->trans($reasonKey);
					print '<span class="krea-badge ' . $badgeClass . '">' . dol_escape_htmltag($reasonLabel === $reasonKey ? (string) $detail : $reasonLabel) . '</span>';
				}
				print '</div>';
			}
			print '</div>';
			print '<div class="krea-nowrap" style="text-align:right">';
			print '<div><strong>' . kreabankPrice((float) $doc['amount_open']) . ' ' . dol_escape_htmltag((string) $selectedLine['currency']) . '</strong></div>';
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

	print '<div class="kb-allocation" role="status" aria-live="polite" aria-atomic="true"><div><span>' . $langs->trans('KreaBankWorkspaceSelectedTotal') . '</span><strong id="kb-selected-total">' . kreabankPrice(0) . ' ' . dol_escape_htmltag($selectedLine['currency']) . '</strong></div><div><span>' . $langs->trans('KreaBankWorkspaceDifference') . '</span><strong id="kb-selection-difference">' . kreabankPrice($selectedOutstanding) . ' ' . dol_escape_htmltag($selectedLine['currency']) . '</strong></div></div>';
	print '<p class="kb-selection-feedback" id="kb-selection-feedback" tabindex="-1" data-empty="' . dol_escape_htmltag($langs->trans('KreaBankWorkspaceSelectEntries')) . '" data-ready="' . dol_escape_htmltag($langs->trans('KreaBankWorkspaceReady')) . '" data-mismatch="' . dol_escape_htmltag($langs->trans('KreaBankWorkspaceMismatch')) . '">' . $langs->trans('KreaBankWorkspaceSelectEntries') . '</p>';
	if ($canReconcile) {
		print '<button type="submit" class="button kb-primary-action" id="manualReconcileButton" aria-describedby="kb-selection-feedback"' . (empty($hasSelectableDocuments) ? ' disabled' : '') . '>' . $langs->trans('KreaBankWorkspaceReconcileEntries') . '</button>';
	}
	print '</form></section>';
	print '<section class="kb-workflow-panel kb-create-panel" id="kb-panel-create" aria-labelledby="kb-create-heading"><h3 id="kb-create-heading">' . $langs->trans('KreaBankWorkspaceCreate') . '</h3><p class="kb-panel-intro">' . $langs->trans('KreaBankWorkspaceCreateHelp') . '</p>';
	if (!$canCreate) { print '<p class="kb-readonly">' . $langs->trans($creationUnavailable ? 'KreaBankWorkspaceCreationUnavailable' : 'KreaBankWorkspaceRestrictedCreation') . '</p>'; }
	elseif ($canReconcile) {
		$renderQuickEntryForm(
			$selectedLine, $openDocumentsSearch, $quickEntryPrefillLabel,
			$selectedOutstanding, $quickEntryPrefillNote, $quickSupplierLookupPrefill,
			$quickSupplierRefSupplier, $quickSupplierPredictedSocid, $quickSupplierPrediction,
			($canQuickSupplierInvoice && $selectedLineDirection < 0),
			($canQuickTaxEntry && $selectedLineDirection < 0)
		);
	} else { print '<p class="kb-readonly">' . $langs->trans('KreaBankWorkspaceReadOnly') . '</p>'; }
	print '</section>';
	if ($canReconcile) {
		print '<details class="kb-defer"><summary>' . $langs->trans('KreaBankWorkspaceDefer') . '</summary><form method="POST" action="' . dol_escape_htmltag($_SERVER['PHP_SELF']) . '">';
		print kreabankNavigationHiddenFields($navigationAccountId);
		print '<input type="hidden" name="token" value="' . newToken() . '"><input type="hidden" name="action" value="skip_line"><input type="hidden" name="line_id" value="' . (int) $selectedLine['rowid'] . '">';
		print '<label for="kb-defer-reason">' . $langs->trans('KreaBankReason') . '</label><input id="kb-defer-reason" name="skip_reason" type="text"><button type="submit" class="button">' . $langs->trans('KreaBankWorkspaceDeferAction') . '</button></form></details>';
	}

	if (!empty($lineLinks)) {
		print '<br><h4>' . $langs->trans('KreaBankHistory') . '</h4>';
		print '<table data-kb-sort class="noborder centpercent">';
		print '<tr class="liste_titre"><th>' . $langs->trans('KreaBankDocument') . '</th><th class="right">' . $langs->trans('KreaBankAllocate') . '</th><th>' . $langs->trans('Type') . '</th><th>' . $langs->trans('KreaBankWhen') . '</th></tr>';
		foreach ($lineLinks as $link) {
			print '<tr class="oddeven">';
			print '<td>' . dol_escape_htmltag((string) $link['doc_ref']) . ' <span class="krea-muted">(' . dol_escape_htmltag((string) $link['doc_type']) . ')</span></td>';
			print '<td class="right" data-kb-sort-value="' . dol_escape_htmltag((string) $link['allocated_amount']) . '">' . kreabankPrice((float) $link['allocated_amount']) . ' ' . dol_escape_htmltag((string) $selectedLine['currency']) . '</td>';
			print '<td>' . dol_escape_htmltag((string) $link['strategy']) . ($link['is_reversed'] ? ' <span class="badge badge-status7">' . $langs->trans('KreaBankReversed') . '</span>' : '') . '</td>';
			print '<td data-kb-sort-value="' . dol_escape_htmltag((string) $link['date_validate']) . '">' . dol_print_date(strtotime((string) $link['date_validate']), 'dayhour') . '</td>';
			print '</tr>';
		}
		print '</table>';
	}
}

print '</div>';
print '</div>';

print '<script src="' . dol_buildpath('/custom/kreabank/js/reconcile-supplier.js', 1) . '?v=' . filemtime(__DIR__ . '/js/reconcile-supplier.js') . '" nonce="' . getNonce() . '"></script>';
print '<script src="' . dol_buildpath('/custom/kreabank/js/reconcile.js', 1) . '?v=' . filemtime(__DIR__ . '/js/reconcile.js') . '" nonce="' . getNonce() . '"></script>';

print dol_get_fiche_end();
llxFooter();
$db->close();
