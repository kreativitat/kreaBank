<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
require_once __DIR__ . '/KreaBankDecimal.class.php';
require_once __DIR__ . '/KreaBankMatcher.class.php';

/** Conservative draft advice for explicit review, never an automatic match. */
final class KreaBankDraftInvoiceReview
{
	public static function relevant($line, $header, $docType, $entity, $companyCurrency)
	{
		if ((int) ($line['entity'] ?? 0) !== (int) $entity || (int) ($header->entity ?? 0) !== (int) $entity
			|| (int) ($line['status'] ?? -1) !== 0 || (int) ($header->fk_statut ?? -1) !== 0
			|| !empty($header->paye) || (int) ($header->type ?? -1) !== 0
			|| !in_array($docType, array('customer_invoice', 'supplier_invoice'), true)
			|| strtoupper($line['currency'] ?? '') !== strtoupper($companyCurrency)
			|| (!empty($header->multicurrency_code) && strtoupper($header->multicurrency_code) !== strtoupper($companyCurrency))) { return false; }
		try {
			$amount = KreaBankDecimal::normalize($line['amount']);
			$total = KreaBankDecimal::normalize((string) $header->total_ttc);
			if (KreaBankDecimal::compare($amount, '0') !== ($docType === 'customer_invoice' ? 1 : -1)
				|| KreaBankDecimal::compare($total, '0') <= 0
				|| KreaBankDecimal::compare(KreaBankDecimal::abs($amount), $total) !== 0
				|| KreaBankDecimal::compare((string) ($line['allocated_amount'] ?? '0'), '0') !== 0) { return false; }
		} catch (InvalidArgumentException $e) { return false; }
		if ((new KreaBankMatcher())->hasCompleteCounterpartyNameMatch($line, $header->review_thirdparty ?? '')) { return true; }
		foreach (array($header->ref ?? '', $docType === 'customer_invoice' ? ($header->ref_client ?? '') : ($header->ref_supplier ?? '')) as $reference) {
			// Temporary IDs and short/generic references are not bank identity evidence.
			$reference = strtoupper(trim((string) $reference));
			$core = preg_replace('/[^A-Z0-9]/', '', $reference);
			if (strpos($core, 'PROV') === 0 || strlen($core) < 8 || !preg_match('/[A-Z]/', $core) || !preg_match('/[0-9]/', $core)) { continue; }
			$words = static function ($text) { return trim(preg_replace('/[^A-Z0-9]+/', ' ', strtoupper((string) $text))); };
			foreach (array('description', 'payment_reference', 'bank_reference') as $field) {
				if (strpos(' ' . $words($line[$field] ?? '') . ' ', ' ' . $words($reference) . ' ') !== false) { return true; }
			}
		}
		return false;
	}
}
