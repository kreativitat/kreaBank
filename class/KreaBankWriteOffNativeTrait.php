<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
require_once __DIR__ . '/KreaBankDecimal.class.php';

/** Native invoice lifecycle only; the service owns scope, locks, review and atomic audit. */
trait KreaBankWriteOffNativeTrait
{
	private function writeOffNativeInvoice($type, $id)
	{
		if ($type === 'customer_invoice') {
			require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';
			$invoice = new Facture($this->db);
		} elseif ($type === 'supplier_invoice') {
			require_once DOL_DOCUMENT_ROOT . '/fourn/class/fournisseur.facture.class.php';
			$invoice = new FactureFournisseur($this->db);
		} else { throw new DomainException('Unsupported invoice difference type'); }
		if ($invoice->fetch((int) $id) <= 0 || (int) $invoice->entity !== $this->entity || (int) $invoice->type === 2) { throw new DomainException('Invoice unavailable in the current company'); }
		return $invoice;
	}

	public function assertWriteOffRemaining($type, $id, $expected)
	{
		$invoice = $this->writeOffNativeInvoice($type, $id);
		$remaining = $invoice->getRemainToPay(0);
		if (!is_numeric($remaining) || KreaBankDecimal::compare((string) $remaining, $expected) !== 0) { throw new DomainException('Native invoice remaining amount changed'); }
	}

	public function completeWriteOffInvoice($type, $id, $closeCode, $note, $expectedDifference)
	{
		$invoice = $this->writeOffNativeInvoice($type, $id);
		$remaining = $invoice->getRemainToPay(0);
		if ($type === 'customer_invoice' && (int) $invoice->status === 2) {
			if ($closeCode === 'discount_vat' && $invoice->close_code === 'discount_vat' && is_numeric($remaining)
				&& KreaBankDecimal::compare((string) $remaining, '0') === 0 && KreaBankDecimal::compare($expectedDifference, '0') > 0) { return; }
			throw new DomainException('Native customer invoice closing differs from the reviewed reason');
		}
		// A native supplier payment hook may close an agreed discount using its own policy.
		if ($type === 'supplier_invoice' && (int) $invoice->status === 2 && $invoice->close_code === 'discount_vat'
			&& is_numeric($remaining) && KreaBankDecimal::compare((string) $remaining, '0') === 0) { return; }
		if (!is_numeric($remaining) || KreaBankDecimal::compare((string) $remaining, $expectedDifference) !== 0) { throw new DomainException('Native payment residual differs from the reviewed amount'); }
		// Supplier residuals follow payment->create() and its native hooks, never forced paid.
		if ($type === 'supplier_invoice') { return; }
		if (!in_array($closeCode, array('discount_vat', 'bankcharge', 'other'), true)
			|| $invoice->setPaid($this->user, $closeCode, $note) <= 0) { throw new RuntimeException('Native customer invoice difference closing failed'); }
	}

	public function reverseWriteOffPayment($type, $paymentId, $invoiceId, $bankId)
	{
		$invoice = $this->writeOffNativeInvoice($type, $invoiceId);
		if ((int) $invoice->getVentilExportCompta() !== 0) { throw new DomainException('Accounting-posted invoices cannot undo a write-off'); }
		if ($type === 'customer_invoice') {
			require_once DOL_DOCUMENT_ROOT . '/compta/paiement/class/paiement.class.php';
			$payment = new Paiement($this->db);
		} else {
			require_once DOL_DOCUMENT_ROOT . '/fourn/class/paiementfourn.class.php';
			$payment = new PaiementFourn($this->db);
		}
		$table = $type === 'customer_invoice' ? 'paiement' : 'paiementfourn';
		$owned = $this->db->query('SELECT rowid FROM ' . $this->db->prefix() . $table . ' WHERE entity=' . $this->entity . ' AND rowid=' . (int) $paymentId . ' FOR UPDATE');
		// Paiement::fetch() in Dolibarr 24 does not populate its entity property.
		if (!$owned || !$this->db->fetch_object($owned) || $payment->fetch((int) $paymentId) <= 0
			|| (int) $payment->bank_line !== (int) $bankId) { throw new DomainException('Native write-off payment changed'); }
		if ($invoice->setUnpaid($this->user) <= 0) { throw new RuntimeException('Native invoice reopening failed'); }
		// Native payment delete otherwise deletes the bank movement, including imported evidence.
		if ($payment->update_fk_bank(0) <= 0) { throw new RuntimeException('Cannot detach reversed write-off payment'); }
		$payment->bank_line = 0;
		if ($payment->delete($this->user) <= 0) { throw new RuntimeException('Native write-off payment deletion failed'); }
	}
}
