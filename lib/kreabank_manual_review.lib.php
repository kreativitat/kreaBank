<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

require_once __DIR__ . '/kreabank_bulk.lib.php';

/** Shared read-only preparation for signed manual review in every workspace. */
function kreabankManualReviewCallbacks($service, $dateTolerance, $bulkMatchMinScore, $singleReview = true)
{
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
	$resolveLineBestSuggestion = static function ($line, $suggestions, $explicitDocument = false) use ($service, $resolveLineDirection, $resolveSuggestionDirection, $bulkMatchMinScore) {
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

		// Explicit per-card approval validates one chosen target without ranking it.
		// It never marks that choice safe for unattended or bulk preselection.
		if ($explicitDocument) {
			return count($validCandidates) === 1 ? $validCandidates[0] : array(
				'suggestion'=>null, 'allocation'=>0.0, 'outstanding'=>$outstanding, 'is_bulk_safe'=>false,
			);
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
				'bank_account_id' => (int) ($document['bank_account_id'] ?? 0),
				'bank_account_ref' => (string) ($document['bank_account_ref'] ?? ''),
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

	$prepareReviewRow = static function ($line) use ($service, $dateTolerance, $appendLinkedFallbackSuggestions, $resolveLineBestSuggestion, $singleReview) {
		$lineId = (int) $line['rowid'];
		$review = !empty($line['fk_statement']) ? $service->getPaymentAccountReview($lineId, array($line['bank_account_id'])) : null;
		if ($review) {
			// Suspected wrong-account payments have no executable match in this view.
			if ($singleReview) { return null; }
			return array('line'=>$line,'account_review'=>$review,'suggestion'=>array('doc_type'=>'account_review','doc_id'=>0),
				'allocation'=>0,'preselected'=>false,'is_bulk_safe'=>false);
		}
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
	$prepareDocumentReviews = static function ($line) use ($service, $dateTolerance, $appendLinkedFallbackSuggestions, $resolveLineBestSuggestion) {
		$lineId = (int) $line['rowid'];
		if ((int) $line['status'] !== 0 || (!empty($line['fk_statement']) && $service->getPaymentAccountReview($lineId, array($line['bank_account_id'])))) {
			return array();
		}
		$suggestionsByLine = $service->getSuggestionsForLines(array($line), 0, $dateTolerance, null, false);
		$suggestions = $appendLinkedFallbackSuggestions($service, $line, $suggestionsByLine[$lineId] ?? array(), $dateTolerance);
		$rows = array();
		foreach ($suggestions as $suggestion) {
			if (!in_array($suggestion['doc_type'], array('customer_invoice', 'supplier_invoice', 'payment', 'payment_supplier', 'payment_linked', 'payment_supplier_linked'), true)) {
				continue;
			}
			$resolved = $resolveLineBestSuggestion($line, array($suggestion), true);
			if (empty($resolved['suggestion'])) { continue; }
			$key = $suggestion['doc_type'] . '__' . (int) $suggestion['doc_id'];
			$rows[$key] = array('line'=>$line, 'suggestion'=>$suggestion, 'allocation'=>(float) $resolved['allocation'],
				'preselected'=>false, 'is_bulk_safe'=>false, 'score'=>(int) $suggestion['score']);
		}
		return $rows;
	};
	return compact('resolveLineBestSuggestion', 'buildLinkedFallbackSuggestions', 'appendLinkedFallbackSuggestions', 'prepareReviewRow', 'prepareDocumentReviews');
}

/** Execute one authenticated displayed pair through the existing service. */
function kreabankExecuteManualReview($service, $row)
{
	$suggestion = $row['suggestion'];
	$score = (int) ($suggestion['score'] ?? 0);
	return $service->reconcileLine(
		(int) $row['line']['rowid'],
		array(array(
			'doc_type' => (string) $suggestion['doc_type'],
			'fk_doc' => (int) $suggestion['doc_id'],
			'doc_ref' => (string) $suggestion['doc_ref'],
			'allocated_amount' => (float) $row['allocation'],
			'match_score' => $score,
			'match_reasons' => (array) ($suggestion['details'] ?? array()),
		)),
		'bulk_manual', 0, 'Exact displayed pair reviewed and confirmed by user', $score, null, $row
	);
}
