<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

require_once __DIR__ . '/KreaBankAccountPairs.class.php';

/** Deterministic owner-company and account boundaries for the existing reconciliation path. */
class KreaBankReconciliationScope
{
	private $db;
	private $entity;

	public function __construct($db, $entity)
	{
		$this->db = $db;
		$this->entity = (int) $entity;
	}

	public function assertLine($line)
	{
		global $conf;
		if ($this->entity <= 0 || (int) $conf->entity !== $this->entity || (int) ($line['bank_account_id'] ?? 0) <= 0) {
			throw new Exception('Bank reconciliation company context changed');
		}
		$p = $this->db->prefix();
		$e = $this->entity;
		$a = (int) $line['bank_account_id'];
		$id = (int) ($line['rowid'] ?? 0);
		if (!empty($line['fk_statement'])) {
			$sql = "SELECT l.rowid FROM {$p}kreabank_statement_line l JOIN {$p}kreabank_statement s ON s.rowid=l.fk_statement AND s.entity=l.entity"
				. " JOIN {$p}bank_account ba ON ba.rowid=s.bank_account_id AND ba.entity=s.entity"
				. " WHERE l.entity=$e AND l.rowid=$id AND s.rowid=" . (int) $line['fk_statement'] . " AND ba.rowid=$a";
		} else {
			$sql = "SELECT b.rowid FROM {$p}bank b JOIN {$p}bank_account ba ON ba.rowid=b.fk_account WHERE ba.entity=$e AND ba.rowid=$a AND b.rowid=$id";
		}
		$this->requireRow($sql);
	}

	public function assertDocument($type, $id, $accountId)
	{
		$p = $this->db->prefix();
		$e = $this->entity;
		$id = (int) $id;
		$a = (int) $accountId;
		if ($id <= 0 || $a <= 0) {
			throw new Exception('Invalid reconciliation document');
		}
		if ($type === 'native_bank') {
			$sql = "SELECT b.rowid FROM {$p}bank b JOIN {$p}bank_account ba ON ba.rowid=b.fk_account WHERE ba.entity=$e AND ba.rowid=$a AND b.rowid=$id";
		} elseif (in_array($type, array('payment', 'payment_linked', 'payment_supplier', 'payment_supplier_linked'), true)) {
			$supplier = in_array($type, array('payment_supplier', 'payment_supplier_linked'), true);
			$table = $supplier ? 'paiementfourn' : 'paiement';
			$link = $supplier ? 'paiementfourn_facturefourn' : 'paiement_facture';
			$invoice = $supplier ? 'facture_fourn' : 'facture';
			$paymentKey = $supplier ? 'fk_paiementfourn' : 'fk_paiement';
			$invoiceKey = $supplier ? 'fk_facturefourn' : 'fk_facture';
			$sql = "SELECT p.rowid FROM {$p}{$table} p LEFT JOIN {$p}bank b ON b.rowid=p.fk_bank LEFT JOIN {$p}bank_account ba ON ba.rowid=b.fk_account"
				. " WHERE p.entity=$e AND p.rowid=$id AND (COALESCE(p.fk_bank,0)=0 OR (ba.entity=$e AND ba.rowid=$a))"
				. " AND NOT EXISTS (SELECT 1 FROM {$p}{$link} pl JOIN {$p}{$invoice} f ON f.rowid=pl.{$invoiceKey} WHERE pl.{$paymentKey}=p.rowid AND f.entity<>$e)";
		} else {
			$tables = array('customer_invoice' => 'facture', 'supplier_invoice' => 'facture_fourn', 'quick_entry' => 'kreabank_quick_entry');
			if (!isset($tables[$type])) {
				throw new Exception('Unknown reconciliation document type');
			}
			$sql = 'SELECT rowid FROM ' . $p . $tables[$type] . " WHERE entity=$e AND rowid=$id";
		}
		$this->requireRow($sql);
		(new KreaBankAccountPairs($this->db, $this->entity))->assertDocument($type, $id, $accountId);
	}

	private function requireRow($sql)
	{
		$result = $this->db->query($sql);
		if (!$result) {
			throw new Exception('Unable to verify reconciliation ownership');
		}
		if (!$this->db->fetch_object($result)) {
			throw new Exception('Reconciliation requires the current company and the same bank account');
		}
	}
}
