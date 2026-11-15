<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
require_once __DIR__ . '/KreaBankWriteOff.class.php';

/** Reviewed differences extend the existing service transaction; no separate executor. */
trait KreaBankWriteOffServiceTrait
{
	public function writeOffConfiguration($lock = false)
	{
		global $conf;
		if ($this->entity <= 0 || $this->entity !== (int) $conf->entity) { return null; }
		$names = array();
		foreach (array('ENABLED', 'MAX_ABS', 'MAX_PERCENT', 'ACCOUNT_BANK_FEE', 'ACCOUNT_ROUNDING', 'ACCOUNT_DISCOUNT_GRANTED', 'ACCOUNT_DISCOUNT_RECEIVED') as $suffix) {
			$names[] = 'KREABANK_WRITEOFF_' . $suffix;
		}
		$quoted = array_map(function ($name) { return "'" . $this->db->escape($name) . "'"; }, $names);
		$rows = $this->transferRows('SELECT name,value FROM ' . $this->db->prefix() . 'const WHERE entity=' . $this->entity
			. ' AND name IN (' . implode(',', $quoted) . ') ORDER BY name' . ($lock ? ' FOR UPDATE' : ''));
		if (count($rows) !== count($names)) { return null; }
		$values = array();
		foreach ($rows as $row) {
			if (!in_array($row->name, $names, true) || array_key_exists($row->name, $values)) { return null; }
			$values[$row->name] = (string) $row->value;
		}
		return KreaBankWriteOff::configuration($values, (string) $conf->currency);
	}

	public function canWriteOffInvoice($docType, $undo = false)
	{
		global $conf;
		if ($this->entity <= 0 || $this->entity !== (int) $conf->entity || empty($this->user->id) || (int) $this->user->statut !== 1 || !empty($this->user->socid)
			|| (!empty($this->user->entity) && (int) $this->user->entity !== $this->entity) || !isModEnabled('kreabank') || !isModEnabled('banque')
			|| !$this->user->hasRight('kreabank', 'reconciliation', $undo ? 'undo' : 'write')
			|| !$this->user->hasRight('banque', 'lire')
			|| (!$this->user->hasRight('banque', 'modifier') && !$this->user->hasRight('banque', 'consolidate'))) { return false; }
		if ($docType === 'customer_invoice') {
			return isModEnabled('invoice') && $this->user->hasRight('facture', 'lire')
				&& $this->user->hasRight('facture', 'paiement') && $this->user->hasRight('facture', 'creer');
		}
		if ($docType === 'supplier_invoice') {
			return isModEnabled('supplier_invoice') && $this->user->hasRight('fournisseur', 'facture', 'lire')
				&& ($this->user->hasRight('fournisseur', 'facture', 'creer') || $this->user->hasRight('supplier_invoice', 'write'))
				&& (!$undo || $this->user->hasRight('fournisseur', 'facture', 'supprimer') || $this->user->hasRight('supplier_invoice', 'delete'));
		}
		return false;
	}

	/** Advisory selected-card preparation only. This is never a shared matcher candidate. */
	public function getWriteOffReview($line, $docType, $invoiceId, $reason = '')
	{
		return $this->prepareWriteOffReview($line, $docType, $invoiceId, $reason, $this->writeOffConfiguration());
	}

	/** Internal preparation can reuse only configuration read by this service, including its locks. */
	private function prepareWriteOffReview($line, $docType, $invoiceId, $reason, $config, $lock = false)
	{
		if (!$config || !$this->canWriteOffInvoice($docType)) { return null; }
		$scope = new KreaBankReconciliationScope($this->db, $this->entity);
		$scope->assertLine($line);
		$scope->assertDocument($docType, (int) $invoiceId, (int) $line['bank_account_id']);
		if ((new KreaBankAccountPairs($this->db, $this->entity))->mainAccount((int) $line['bank_account_id']) > 0
			|| (!empty($line['fk_statement']) && $this->getPaymentAccountReview((int) $line['rowid'], array((int) $line['bank_account_id'])))) { return null; }
		$source = $this->writeOffSourceEvidence($line, $lock);
		if (!$this->writeOffSourceUnclaimed($source, $lock)) { return null; }
		$invoice = $this->writeOffInvoiceEvidence($docType, (int) $invoiceId, $lock);
		if (!$invoice) { return null; }
		$writeoff = KreaBankWriteOff::inspect($source, $invoice, $config, $reason);
		if (!$writeoff) { return null; }
		foreach ($writeoff['reasons'] as $available) {
			if (!$this->writeOffAccountingAccount($config['accounts'][$available], $lock)) { return null; }
		}
		$writeoff['invoice_id'] = (int) $invoiceId;
		$writeoff['source_snapshot'] = hash('sha256', json_encode($source));
		$writeoff['invoice_evidence'] = $invoice['allocation_snapshot'];
		return array('line' => $line, 'suggestion' => array('doc_type' => $docType, 'doc_id' => (int) $invoiceId,
			'doc_ref' => $invoice['ref'], 'doc_date' => $invoice['datef'], 'thirdparty_name' => $invoice['thirdparty_name'],
			'bank_account_id' => 0, 'amount_open' => $invoice['amount_open'], 'score' => 1, 'details' => array()),
			'allocation' => $writeoff['bank_amount'], 'preselected' => false, 'is_bulk_safe' => false,
			'writeoff' => $writeoff);
	}

	/** One database preparation per invoice card; each reason remains a separately signed choice. */
	public function getWriteOffReviews($line, $docType, $invoiceId)
	{
		$config = $this->writeOffConfiguration();
		if (!$config) { return array(); }
		$base = $this->prepareWriteOffReview($line, $docType, $invoiceId, '', $config);
		if (!$base) { return array(); }
		$out = array();
		foreach ($base['writeoff']['reasons'] as $reason) {
			$details = KreaBankWriteOff::reasonDetails($config, $docType, $reason);
			if (!$details) { continue; }
			$row = $base;
			foreach ($details as $key => $value) { $row['writeoff'][$key] = $value; }
			$out[$reason] = $row;
		}
		return $out;
	}

	/** Called after existing account/source locks and before the existing native payment helper. */
	protected function revalidateWriteOffReview($row, $line)
	{
		$type = $row['suggestion']['doc_type'] ?? '';
		$id = (int) ($row['suggestion']['doc_id'] ?? 0);
		if (!$this->canWriteOffInvoice($type) || empty($row['writeoff']['reason'])) { throw new DomainException('Review a configured invoice difference again'); }
		$config = $this->writeOffConfiguration(true);
		if (!$config || hash('sha256', json_encode($config)) !== ($row['writeoff']['config_snapshot'] ?? '')) { throw new DomainException('Invoice difference configuration changed; review it again'); }
		$current = $this->prepareWriteOffReview($line, $type, $id, $row['writeoff']['reason'], $config, true);
		if (!$current || $current['writeoff'] !== $row['writeoff'] || $current['suggestion'] !== $row['suggestion']
			|| KreaBankDecimal::normalize($row['allocation']) !== $current['allocation']) { throw new DomainException('Invoice difference or limits changed; review it again'); }
		$this->native->assertWriteOffRemaining($type, $id, $current['writeoff']['amount_open']);
		return $current;
	}

	/** Complete inside reconcileLine's transaction and return the exact undo evidence for its audit. */
	protected function completeWriteOffReview($row, $created, $nativeLine)
	{
		$type = $row['suggestion']['doc_type']; $id = (int) $row['suggestion']['doc_id'];
		$paymentId = (int) ($created['payment_id'] ?? 0);
		$p = $this->db->prefix(); $bankId = (int) $nativeLine['rowid'];
		$bank = $this->transferRows("SELECT b.amount,b.rappro,ba.currency_code FROM {$p}bank b JOIN {$p}bank_account ba ON ba.rowid=b.fk_account WHERE ba.entity={$this->entity} AND b.rowid=$bankId AND b.fk_account=" . (int) $row['line']['bank_account_id'] . ' FOR UPDATE');
		$signed = $type === 'customer_invoice' ? $row['writeoff']['bank_amount'] : KreaBankDecimal::negate($row['writeoff']['bank_amount']);
		if (count($bank) !== 1 || (int) $bank[0]->rappro !== 0 || (string) $bank[0]->currency_code !== (string) $row['line']['currency']
			|| KreaBankDecimal::compare((string) $bank[0]->amount, $signed) !== 0) { throw new DomainException('Native bank movement did not preserve the exact reviewed signed amount'); }
		$payment = $this->writeOffPaymentEvidence($type, $paymentId, true);
		if (!$payment || (int) $payment['row']['fk_bank'] !== (int) $nativeLine['rowid'] || count($payment['allocations']) !== 1
			|| (int) $payment['allocations'][0]['invoice_id'] !== $id
			|| KreaBankDecimal::compare((string) $payment['row']['amount'], $row['writeoff']['bank_amount']) !== 0
			|| KreaBankDecimal::compare((string) $payment['allocations'][0]['amount'], $row['writeoff']['bank_amount']) !== 0) {
			throw new DomainException('Native payment did not preserve the exact reviewed amount');
		}
		// Native discount closing makes getRemainToPay() return zero. Verify the actual
		// persisted invoice-minus-allocation residual before accepting that lifecycle.
		$afterPayment = $this->writeOffInvoiceEvidence($type, $id, true);
		if (!$afterPayment || KreaBankDecimal::compare($afterPayment['amount_open'], $row['writeoff']['difference']) !== 0) {
			throw new DomainException('Native payment residual differs from the reviewed amount');
		}
		$note = 'KreaBank ' . $row['writeoff']['reason'] . '; difference=' . $row['writeoff']['difference'] . '; account=' . $row['writeoff']['account'];
		$this->native->completeWriteOffInvoice($type, $id, $row['writeoff']['close_code'], $note, $row['writeoff']['difference']);
		$invoice = $this->writeOffInvoiceEvidence($type, $id, true);
		$completed = $row['writeoff'];
		if ($type === 'supplier_invoice' && (int) $invoice['status'] === 2) { $completed['remaining_status'] = 'supplier_native_closed'; }
		return $completed + array('payment_id' => $paymentId, 'native_bank_line_id' => (int) $nativeLine['rowid'],
			'invoice_after' => KreaBankWriteOff::snapshot($invoice), 'allocations_after' => $invoice['allocation_snapshot'],
			'payment_after' => hash('sha256', json_encode($payment)));
	}

	/** Current audited write-off identity is bound into the displayed History undo snapshot. */
	public function writeOffUndoSnapshot($line)
	{
		$p = $this->db->prefix();
		$audits = $this->transferRows("SELECT rowid,payload_json FROM {$p}kreabank_recon_audit WHERE entity={$this->entity} AND audit_type='reconcile' AND fk_statement_line="
			. (int) $line['rowid'] . ' ORDER BY datec DESC,rowid DESC' . $this->db->plimit(1001));
		foreach (array_slice($audits, 0, 1000) as $audit) {
			$payload = json_decode($audit->payload_json, true);
			if (!is_array($payload)) { continue; }
			$source = $line['line_source'] ?? (!empty($line['fk_statement']) ? 'staged' : 'native');
			if (isset($payload['source']) && $payload['source'] !== $source) { continue; }
			if (!empty($payload['native_bank_line_id']) && (int) $payload['native_bank_line_id'] !== (int) $line['native_bank_line_id']) { continue; }
			if (empty($payload['source']) && empty($payload['native_bank_line_id']) && empty($payload['batch_id'])) { continue; }
			if (!empty($payload['batch_id']) && (int) $payload['batch_id'] !== (int) ($line['group_id'] ?? 0)) { continue; }
			if (($payload['strategy'] ?? '') !== 'manual_writeoff') { return null; }
			if (empty($payload['writeoff']) || !is_array($payload['writeoff'])) { throw new DomainException('Write-off audit needs review'); }
			return array('audit_id' => (int) $audit->rowid, 'audit_hash' => hash('sha256', $audit->payload_json), 'writeoff' => $payload['writeoff']);
		}
		if (count($audits) > 1000) { throw new DomainException('This reconciliation audit exceeds the undo review bound'); }
		return null;
	}

	/** Existing undo transaction owns source/account/native locks; preserve imported bank evidence. */
	protected function undoWriteOffReview($line, $review)
	{
		$current = $this->writeOffUndoSnapshot($line);
		if (!$current) {
			if (!empty($review['writeoff'])) { throw new DomainException('Write-off audit changed; review undo again'); }
			return false;
		}
		if (($review['writeoff'] ?? null) !== $current) { throw new DomainException('Review the exact write-off undo again'); }
		$writeoff = $current['writeoff']; $type = $writeoff['invoice_type']; $id = (int) $writeoff['invoice_id'];
		if (!$this->canWriteOffInvoice($type, true)) { throw new DomainException('Native payment deletion and invoice reopening permissions are required'); }
		$scope = new KreaBankReconciliationScope($this->db, $this->entity);
		$scope->assertDocument($type, $id, (int) $line['bank_account_id']);
		$invoice = $this->writeOffInvoiceEvidence($type, $id, true);
		$payment = $this->writeOffPaymentEvidence($type, (int) $writeoff['payment_id'], true);
		if (!$invoice || !$payment || KreaBankWriteOff::snapshot($invoice) !== $writeoff['invoice_after']
			|| $invoice['allocation_snapshot'] !== $writeoff['allocations_after']
			|| hash('sha256', json_encode($payment)) !== $writeoff['payment_after']
			|| (int) $payment['row']['fk_bank'] !== (int) $line['native_bank_line_id']) { throw new DomainException('Write-off invoice or payment changed; review undo again'); }
		$p = $this->db->prefix(); $bankId = (int) $line['native_bank_line_id'];
		$native = new AccountLine($this->db);
		if ($native->fetch($bankId) <= 0 || (int) $native->getVentilExportCompta() !== 0) { throw new DomainException('Accounting-posted movements cannot undo a write-off'); }
		$links = $this->transferRows("SELECT u.rowid,u.type,u.url_id FROM {$p}bank_url u JOIN {$p}bank b ON b.rowid=u.fk_bank JOIN {$p}bank_account ba ON ba.rowid=b.fk_account WHERE ba.entity={$this->entity} AND u.fk_bank=$bankId ORDER BY u.rowid FOR UPDATE");
		$linkType = $type === 'customer_invoice' ? 'payment' : 'payment_supplier';
		$paymentLinks = array_values(array_filter($links, static function ($link) { return in_array($link->type, array('payment', 'payment_supplier'), true); }));
		if (count($paymentLinks) !== 1 || $paymentLinks[0]->type !== $linkType || (int) $paymentLinks[0]->url_id !== (int) $writeoff['payment_id']) { throw new DomainException('The native payment links changed'); }
		$bankPayments = $this->transferRows("SELECT p.rowid,p.entity FROM {$p}paiement p JOIN {$p}bank b ON b.rowid=p.fk_bank JOIN {$p}bank_account ba ON ba.rowid=b.fk_account WHERE ba.entity={$this->entity} AND b.rowid=$bankId FOR UPDATE");
		$bankSupplierPayments = $this->transferRows("SELECT p.rowid,p.entity FROM {$p}paiementfourn p JOIN {$p}bank b ON b.rowid=p.fk_bank JOIN {$p}bank_account ba ON ba.rowid=b.fk_account WHERE ba.entity={$this->entity} AND b.rowid=$bankId FOR UPDATE");
		$bankPayments = array_merge($bankPayments, $bankSupplierPayments);
		if (count($bankPayments) !== 1 || (int) $bankPayments[0]->rowid !== (int) $writeoff['payment_id'] || (int) $bankPayments[0]->entity !== $this->entity) { throw new DomainException('The native bank movement has another payment claim'); }
		$this->native->reverseWriteOffPayment($type, (int) $writeoff['payment_id'], $id, $bankId);
		$sql = "DELETE u FROM {$p}bank_url u JOIN {$p}bank b ON b.rowid=u.fk_bank JOIN {$p}bank_account ba ON ba.rowid=b.fk_account WHERE ba.entity={$this->entity} AND u.rowid=" . (int) $paymentLinks[0]->rowid;
		if (!$this->db->query($sql)) { throw new RuntimeException('Cannot remove reversed payment link'); }
		$restored = $this->writeOffInvoiceEvidence($type, $id, true);
		if (!$restored || (int) $restored['status'] !== 1 || (int) $restored['paye'] !== 0
			|| KreaBankDecimal::compare($restored['amount_open'], $writeoff['amount_open']) !== 0) { throw new DomainException('Native write-off reversal did not restore the reviewed invoice balance'); }
		return true;
	}

	private function writeOffAccountingAccount($number, $lock = false)
	{
		if (!$this->tableExists($this->db->prefix() . 'accounting_account')) { return false; }
		return count($this->transferRows('SELECT rowid FROM ' . $this->db->prefix() . 'accounting_account WHERE entity=' . $this->entity
			. " AND active=1 AND account_number='" . $this->db->escape($number) . "'" . ($lock ? ' FOR UPDATE' : ''))) === 1;
	}

	private function writeOffSourceEvidence($line, $lock = false)
	{
		$p = $this->db->prefix(); $id = (int) $line['rowid']; $account = (int) $line['bank_account_id'];
		if (!empty($line['fk_statement'])) {
			$sql = "SELECT l.entity,l.rowid,l.amount,l.allocated_amount,l.status,l.direction,l.currency,l.fk_native_bank_line AS native_bank_line_id,s.bank_account_id FROM {$p}kreabank_statement_line l JOIN {$p}kreabank_statement s ON s.rowid=l.fk_statement AND s.entity=l.entity JOIN {$p}bank_account ba ON ba.rowid=s.bank_account_id AND ba.entity=l.entity WHERE l.entity={$this->entity} AND l.rowid=$id AND s.bank_account_id=$account AND ba.currency_code=l.currency";
		} else {
			$sql = "SELECT ba.entity,b.rowid,b.rowid AS native_bank_line_id,b.amount,0 AS allocated_amount,IF(b.rappro=1,2,0) AS status,0 AS direction,ba.currency_code AS currency,b.fk_account AS bank_account_id FROM {$p}bank b JOIN {$p}bank_account ba ON ba.rowid=b.fk_account WHERE ba.entity={$this->entity} AND b.rowid=$id AND b.fk_account=$account";
		}
		$rows = $this->transferRows($sql . ($lock ? ' FOR UPDATE' : ''));
		if (count($rows) !== 1) { throw new DomainException('Invoice difference source unavailable'); }
		$source = (array) $rows[0];
		foreach (array('amount', 'allocated_amount') as $key) { $source[$key] = KreaBankDecimal::normalize((string) $source[$key]); }
		return $source;
	}

	private function writeOffSourceUnclaimed($source, $lock = false)
	{
		$p = $this->db->prefix(); $id = (int) ($source['native_bank_line_id'] ?? 0);
		if ($id <= 0) { return true; }
		$suffix = $lock ? ' FOR UPDATE' : '';
		$bank = $this->transferRows("SELECT b.amount,b.rappro,ba.currency_code FROM {$p}bank b JOIN {$p}bank_account ba ON ba.rowid=b.fk_account WHERE ba.entity={$this->entity} AND b.rowid=$id AND b.fk_account=" . (int) $source['bank_account_id'] . $suffix);
		if (count($bank) !== 1 || (int) $bank[0]->rappro !== 0 || (string) $bank[0]->currency_code !== (string) $source['currency']
			|| KreaBankDecimal::compare((string) $bank[0]->amount, $source['amount']) !== 0) { return false; }
		$links = $this->transferRows("SELECT u.rowid FROM {$p}bank_url u JOIN {$p}bank b ON b.rowid=u.fk_bank JOIN {$p}bank_account ba ON ba.rowid=b.fk_account WHERE ba.entity={$this->entity} AND b.rowid=$id AND u.type<>'company'" . $suffix);
		if ($links) { return false; }
		foreach (array('paiement', 'paiementfourn') as $table) {
			$payments = $this->transferRows("SELECT p.rowid FROM {$p}{$table} p JOIN {$p}bank b ON b.rowid=p.fk_bank JOIN {$p}bank_account ba ON ba.rowid=b.fk_account WHERE ba.entity={$this->entity} AND b.rowid=$id" . $suffix);
			if ($payments) { return false; }
		}
		return true;
	}

	/** Lock parent before every allocation and discount, with direct owner-company boundaries. */
	private function writeOffInvoiceEvidence($type, $id, $lock = false)
	{
		if (!in_array($type, array('customer_invoice', 'supplier_invoice'), true) || $id <= 0) { return null; }
		$p = $this->db->prefix(); $customer = $type === 'customer_invoice';
		$table = $customer ? 'facture' : 'facture_fourn';
		$link = $customer ? 'paiement_facture' : 'paiementfourn_facturefourn';
		$payment = $customer ? 'paiement' : 'paiementfourn';
		$invoiceKey = $customer ? 'fk_facture' : 'fk_facturefourn';
		$paymentKey = $customer ? 'fk_paiement' : 'fk_paiementfourn';
		$target = $customer ? 'fk_facture' : 'fk_invoice_supplier';
		$origin = $customer ? 'fk_facture_source' : 'fk_invoice_supplier_source';
		$rows = $this->transferRows("SELECT f.*,f.fk_statut AS status,s.nom AS thirdparty_name FROM {$p}{$table} f JOIN {$p}societe s ON s.rowid=f.fk_soc AND s.entity=f.entity WHERE f.entity={$this->entity} AND f.rowid=$id" . ($lock ? ' FOR UPDATE' : ''));
		if (count($rows) !== 1) { return null; }
		$invoice = (array) $rows[0]; $invoice['doc_type'] = $type;
		$allocations = $this->transferRows("SELECT l.*,p.entity AS payment_entity FROM {$p}{$link} l JOIN {$p}{$table} f ON f.rowid=l.{$invoiceKey} LEFT JOIN {$p}{$payment} p ON p.rowid=l.{$paymentKey} WHERE f.entity={$this->entity} AND f.rowid=$id ORDER BY l.rowid" . ($lock ? ' FOR UPDATE' : ''));
		$discounts = $this->transferRows("SELECT r.*,origin.entity AS source_entity,origin.type AS source_type FROM {$p}societe_remise_except r JOIN {$p}{$table} f ON f.rowid=r.{$target} LEFT JOIN {$p}{$table} origin ON origin.rowid=r.{$origin} WHERE f.entity={$this->entity} AND r.entity={$this->entity} AND f.rowid=$id ORDER BY r.rowid" . ($lock ? ' FOR UPDATE' : ''));
		$open = KreaBankDecimal::normalize((string) $invoice['total_ttc']);
		foreach ($allocations as $allocation) {
			if ((int) $allocation->payment_entity !== $this->entity) { throw new DomainException('Invoice payment ownership changed'); }
			$open = KreaBankDecimal::subtract($open, (string) $allocation->amount);
		}
		foreach ($discounts as $discount) {
			if ((int) $discount->source_entity !== $this->entity || !in_array((int) $discount->source_type, $customer ? array(0, 2, 3, 5) : array(0, 2, 3), true)) { throw new DomainException('Invoice credit ownership changed'); }
			$open = KreaBankDecimal::subtract($open, (string) $discount->amount_ttc);
		}
		$invoice['amount_open'] = $open;
		$invoice['allocation_snapshot'] = hash('sha256', json_encode(array($allocations, $discounts)));
		return $invoice;
	}

	private function writeOffPaymentEvidence($type, $id, $lock = false)
	{
		if ($id <= 0 || !in_array($type, array('customer_invoice', 'supplier_invoice'), true)) { return null; }
		$p = $this->db->prefix(); $customer = $type === 'customer_invoice';
		$table = $customer ? 'paiement' : 'paiementfourn'; $link = $customer ? 'paiement_facture' : 'paiementfourn_facturefourn';
		$invoice = $customer ? 'facture' : 'facture_fourn'; $invoiceKey = $customer ? 'fk_facture' : 'fk_facturefourn'; $paymentKey = $customer ? 'fk_paiement' : 'fk_paiementfourn';
		$rows = $this->transferRows("SELECT * FROM {$p}{$table} WHERE entity={$this->entity} AND rowid=$id" . ($lock ? ' FOR UPDATE' : ''));
		if (count($rows) !== 1) { return null; }
		$allocations = $this->transferRows("SELECT l.*,f.rowid AS invoice_id,f.entity AS invoice_entity FROM {$p}{$link} l JOIN {$p}{$table} p ON p.rowid=l.{$paymentKey} LEFT JOIN {$p}{$invoice} f ON f.rowid=l.{$invoiceKey} WHERE p.entity={$this->entity} AND p.rowid=$id ORDER BY l.rowid" . ($lock ? ' FOR UPDATE' : ''));
		foreach ($allocations as $allocation) { if ((int) $allocation->invoice_entity !== $this->entity) { throw new DomainException('Payment has an unavailable invoice'); } }
		return array('row' => (array) $rows[0], 'allocations' => array_map(static function ($row) { return (array) $row; }, $allocations));
	}
}
