<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
require_once __DIR__ . '/KreaBankRule.class.php';

/** Selected-line advice only. Never called by the matcher or unattended runners. */
trait KreaBankRuleServiceTrait
{
	/** Keep amount conditions on persisted DECIMAL evidence, before legacy display repairs. */
	protected function bankRuleLineEvidence($line)
	{
		(new KreaBankReconciliationScope($this->db, $this->entity))->assertLine($line);
		$p = $this->db->prefix(); $id = (int) $line['rowid']; $e = $this->entity;
		$sql = !empty($line['fk_statement'])
			? "SELECT l.amount FROM {$p}kreabank_statement_line l JOIN {$p}kreabank_statement s ON s.rowid=l.fk_statement AND s.entity=l.entity JOIN {$p}bank_account ba ON ba.rowid=s.bank_account_id AND ba.entity=l.entity WHERE l.entity=$e AND l.rowid=$id AND ba.rowid=" . (int) $line['bank_account_id']
			: "SELECT b.amount FROM {$p}bank b JOIN {$p}bank_account ba ON ba.rowid=b.fk_account WHERE ba.entity=$e AND b.rowid=$id AND ba.rowid=" . (int) $line['bank_account_id'];
		$rows = $this->transferRows($sql);
		if (count($rows) !== 1) { throw new RuntimeException('Rule source evidence unavailable'); }
		$line['amount'] = KreaBankDecimal::normalize((string) $rows[0]->amount);
		$line['entity'] = $this->entity;
		return $line;
	}

	public function getBankRuleAdvice($line, $lock = false)
	{
		global $conf;
		if ((int) $conf->entity !== $this->entity || !empty($this->user->socid)
			|| !$this->user->hasRight('kreabank', 'reconciliation', 'read') || !$this->user->hasRight('banque', 'lire')) {
			throw new RuntimeException('Current company bank read permission required');
		}
		(new KreaBankReconciliationScope($this->db, $this->entity))->assertLine($line);
		$engine = new KreaBankRule($this->db, $this->user, $this->entity);
		if (!$engine->available()) { return array('winner'=>null, 'matches'=>array(), 'conflicts'=>false); }
		$line = $this->bankRuleLineEvidence($line);
		$advice = KreaBankRule::evaluate($engine->all(false, $lock), $line) + array('source_amount'=>$line['amount']);
		if (($advice['winner']['action']['type'] ?? '') === 'category') {
			$p = $this->db->prefix();
			$categories = $this->transferRows("SELECT rowid,label FROM {$p}categorie WHERE entity=" . $this->entity
				. ' AND rowid=' . (int) $advice['winner']['action']['category_id'] . ($lock ? ' FOR UPDATE' : ''));
			if (count($categories) !== 1) { throw new RuntimeException('The rule category is unavailable'); }
			$advice['action_evidence'] = array('category_id'=>(int) $categories[0]->rowid, 'category_label'=>(string) $categories[0]->label);
		}
		return $advice;
	}

	public static function bankRuleReviewSnapshot($advice)
	{
		$rule = $advice['winner'] ?? null;
		if (!$rule) { return null; }
		return array('id'=>(int) $rule['rowid'], 'label'=>(string) $rule['label'], 'revision'=>(string) $rule['revision'],
			'action'=>$rule['action'], 'source_amount'=>$advice['source_amount'],
			'action_evidence'=>$advice['action_evidence'] ?? array(),
			'conflicts'=>array_map(static function ($row) { return array('id'=>(int) $row['rowid'], 'label'=>(string) $row['label'], 'revision'=>(string) $row['revision']); }, $advice['matches']));
	}

	public function getBankRuleInvoiceDocuments($line, $advice = null)
	{
		$advice = $advice ?? $this->getBankRuleAdvice($line);
		$rule = $advice['winner'] ?? null;
		if (!$rule || ($rule['action']['type'] ?? '') !== 'invoice_search') { return array(); }
		$type = $rule['action']['invoice_type'];
		$id = (int) $rule['action']['thirdparty_id'];
		$direction = $type === 'customer_invoice' ? 1 : -1;
		$line = $this->bankRuleLineEvidence($line);
		if ($id <= 0 || KreaBankDecimal::compare($line['amount'], '0') !== $direction) { return array(); }
		$documents = $this->getOpenInvoiceDocuments($direction, 100, null, 0, false, '', $id);
		foreach ($documents as &$doc) { $doc['bank_rule'] = self::bankRuleReviewSnapshot($advice); }
		unset($doc);
		return $documents;
	}

	/** Only explicit selected-line reviews receive rule-only invoice candidates. */
	public function appendBankRuleManualSuggestions($line, $suggestions)
	{
		try { return $this->bankRuleManualSuggestions($line, $suggestions); }
		catch (Throwable $e) {
			if (function_exists('dol_syslog')) { dol_syslog('KreaBank rule advice unavailable; ordinary manual candidates retained', LOG_WARNING); }
			return $suggestions;
		}
	}

	private function bankRuleManualSuggestions($line, $suggestions)
	{
		$advice = $this->getBankRuleAdvice($line);
		if (($advice['winner']['action']['type'] ?? '') === 'category') {
			$nativeIds = $this->bankRuleNativeCandidateIds($line, array_column(array_filter($suggestions, static function ($s) { return $s['doc_type'] === 'native_bank'; }), 'doc_id'));
			foreach ($suggestions as &$suggestion) {
				if ($suggestion['doc_type'] === 'native_bank' && (int) ($suggestion['bank_account_id'] ?? 0) === (int) $line['bank_account_id']
					&& isset($nativeIds[(int) $suggestion['doc_id']])) {
					$suggestion['bank_rule'] = self::bankRuleReviewSnapshot($advice);
				}
			}
			unset($suggestion);
			return $suggestions;
		}
		$documents = $this->getBankRuleInvoiceDocuments($line, $advice);
		foreach ($documents as $doc) {
			$key = $doc['doc_type'] . '__' . $doc['rowid'];
			$target = self::bankRuleInvoiceFacts($doc);
			$found = false;
			foreach ($suggestions as &$suggestion) {
				if ($suggestion['doc_type'] . '__' . $suggestion['doc_id'] === $key) {
					$suggestion['bank_rule'] = $doc['bank_rule']; $suggestion['bank_rule_target'] = $target; $found = true;
				}
			}
			unset($suggestion);
			if (!$found) {
				$suggestions[] = array('doc_type'=>$doc['doc_type'], 'doc_id'=>$doc['rowid'], 'doc_ref'=>$doc['ref'],
					'doc_date'=>$doc['doc_date'], 'thirdparty_name'=>$doc['thirdparty_name'],
					'amount_open'=>$doc['amount_open_exact'], 'score'=>1, 'details'=>array('rule_advisory'),
					'bank_rule'=>$doc['bank_rule'], 'bank_rule_target'=>$target);
			}
		}
		return $suggestions;
	}

	/** Batch-read owned native DECIMAL amounts; legacy float projections are presentation only. */
	public function bankRuleNativeCandidateIds($line, $ids)
	{
		$ids = array_values(array_unique(array_filter(array_map('intval', $ids), static function ($id) { return $id > 0; })));
		if (!$ids) { return array(); }
		$source = $this->bankRuleLineEvidence($line); $p = $this->db->prefix(); $out = array();
		foreach ($this->transferRows("SELECT b.rowid,b.amount FROM {$p}bank b JOIN {$p}bank_account ba ON ba.rowid=b.fk_account WHERE ba.entity="
			. $this->entity . ' AND ba.rowid=' . (int) $line['bank_account_id'] . ' AND b.rappro=0 AND b.rowid IN (' . implode(',', $ids) . ')') as $row) {
			if (KreaBankDecimal::compare((string) $row->amount, $source['amount']) === 0) { $out[(int) $row->rowid] = true; }
		}
		return $out;
	}

	private static function bankRuleInvoiceFacts($doc)
	{
		return array('type'=>$doc['doc_type'], 'id'=>(int) $doc['rowid'], 'ref'=>$doc['ref'], 'thirdparty_id'=>(int) $doc['fk_soc'],
			'amount_open'=>KreaBankDecimal::normalize($doc['amount_open_exact']), 'date'=>(string) $doc['doc_date']);
	}

	/** Caller holds source/account locks and the encompassing reconciliation transaction. */
	protected function assertBankRuleReview($row, $line)
	{
		$expected = $row['bank_rule'] ?? null;
		if (!$expected) { return; }
		$this->assertAutomaticActor(true);
		$advice = $this->getBankRuleAdvice($line, true);
		if (self::bankRuleReviewSnapshot($advice) !== $expected) {
			throw new RuntimeException('Bank rule changed; review the suggestion again');
		}
		$action = $advice['winner']['action'];
		if (($action['type'] ?? '') === 'invoice_search') {
			$type = $row['suggestion']['doc_type']; $id = (int) $row['suggestion']['doc_id'];
			if ($type !== $action['invoice_type']) { throw new RuntimeException('Rule invoice type changed'); }
			$table = $type === 'customer_invoice' ? 'facture' : 'facture_fourn';
			$rows = $this->transferRows('SELECT rowid FROM ' . $this->db->prefix() . $table
				. ' WHERE entity=' . $this->entity . ' AND rowid=' . $id . ' AND fk_soc=' . (int) $action['thirdparty_id'] . ' FOR UPDATE');
			if (count($rows) !== 1) { throw new RuntimeException('Rule invoice counterparty changed'); }
			$paymentTable = $type === 'customer_invoice' ? 'paiement_facture' : 'paiementfourn_facturefourn';
			$invoiceKey = $type === 'customer_invoice' ? 'fk_facture' : 'fk_facturefourn';
			$this->transferRows('SELECT pl.rowid FROM ' . $this->db->prefix() . $paymentTable . ' pl JOIN ' . $this->db->prefix() . $table
				. ' f ON f.rowid=pl.' . $invoiceKey . ' WHERE f.entity=' . $this->entity . ' AND f.rowid=' . $id . ' FOR UPDATE');
			$creditKey = $type === 'customer_invoice' ? 'fk_facture' : 'fk_invoice_supplier';
			$this->transferRows('SELECT rowid FROM ' . $this->db->prefix() . 'societe_remise_except WHERE entity=' . $this->entity . ' AND ' . $creditKey . '=' . $id . ' FOR UPDATE');
			$fresh = null;
			foreach ($this->getBankRuleInvoiceDocuments($line, $advice) as $doc) { if ((int) $doc['rowid'] === $id) { $fresh = self::bankRuleInvoiceFacts($doc); } }
			if (!$fresh || $fresh !== ($row['bank_rule_target'] ?? null)) { throw new RuntimeException('Rule invoice changed; review it again'); }
		} elseif (($action['type'] ?? '') === 'category' && ($row['suggestion']['doc_type'] ?? '') === 'native_bank') {
			(new KreaBankReconciliationScope($this->db, $this->entity))->assertDocument('native_bank', (int) $row['suggestion']['doc_id'], (int) $line['bank_account_id']);
		} elseif (empty($row['rule_creation'])) {
			throw new RuntimeException('Rule action requires reviewed creation');
		}
	}

	protected function bankRuleCategory($row)
	{
		if (empty($row['bank_rule'])) { return 0; }
		$advice = $this->getBankRuleAdvice($row['line'], true);
		return ($advice['winner']['action']['type'] ?? '') === 'category' ? (int) $advice['winner']['action']['category_id'] : 0;
	}

	public function getBankRuleQuickReview($line)
	{
		global $conf;
		if (empty($line['fk_statement']) || (int) $line['status'] !== 0 || !empty($line['native_bank_line_id'])
			|| KreaBankDecimal::compare((string) ($line['allocated_amount'] ?? '0'), '0') !== 0
			|| (string) $line['currency'] !== (string) $conf->currency
			|| !$this->user->hasRight('banque', 'modifier') || !$this->user->hasRight('kreabank', 'reconciliation', 'write')) { return null; }
		(new KreaBankAccountPairs($this->db, $this->entity))->assertDocument('quick_entry', 0, (int) $line['bank_account_id']);
		if ($this->getPaymentAccountReview((int) $line['rowid'], array((int) $line['bank_account_id']))) { return null; }
		$advice = $this->getBankRuleAdvice($line);
		if (($advice['winner']['action']['type'] ?? '') !== 'quick_entry') { return null; }
		$evidence = $this->bankRuleLineEvidence($line);
		$amount = KreaBankDecimal::abs($evidence['amount']);
		if ($amount === '0') { return null; }
		$action = $advice['winner']['action'];
		$thirdparty = $this->transferRows('SELECT nom FROM ' . $this->db->prefix() . 'societe WHERE entity=' . $this->entity . ' AND fournisseur=1 AND rowid=' . (int) $action['thirdparty_id']);
		if (count($thirdparty) !== 1) { return null; }
		return array('line'=>$line, 'suggestion'=>array('doc_type'=>'quick_entry', 'doc_id'=>0,
			'doc_ref'=>$action['label'], 'doc_date'=>$line['operation_date'], 'thirdparty_name'=>(string) $thirdparty[0]->nom,
			'amount_open'=>$amount, 'score'=>0, 'details'=>array('rule_advisory')),
			'allocation'=>$amount, 'preselected'=>false, 'is_bulk_safe'=>false,
			'bank_rule'=>self::bankRuleReviewSnapshot($advice), 'rule_creation'=>$action + array('source_amount'=>$evidence['amount']));
	}

	/** Only the controller's authenticated displayed creation enters the existing quick-entry path. */
	public function reconcileBankRuleQuickEntry($row)
	{
		if (($row['suggestion']['doc_type'] ?? '') !== 'quick_entry' || empty($row['bank_rule']) || empty($row['rule_creation'])) {
			throw new RuntimeException('Review the rule entry again');
		}
		$line = $this->getBulkReviewLine((int) $row['line']['rowid'], 'staged');
		$fresh = $line ? $this->getBankRuleQuickReview($line) : null;
		if (!$fresh || $fresh !== $row) { throw new RuntimeException('Rule entry changed; review it again'); }
		if (!$this->db->begin()) { throw new RuntimeException('Cannot start rule-entry transaction'); }
		try {
			$action = $row['rule_creation'];
			$id = $this->createQuickEntryAndReconcile((int) $line['rowid'], 'payment', $action['label'],
				$row['allocation'], (int) $action['thirdparty_id'], 'Rule: ' . $row['bank_rule']['label'], $row);
			if (!$this->db->commit()) { throw new RuntimeException('Cannot confirm rule-entry commit; inspect before retrying'); }
			return $id;
		} catch (Throwable $e) { $this->db->rollback(); throw $e; }
	}

	protected function recordBankRuleReview($row, $line)
	{
		if (!empty($row['bank_rule'])) {
			(new KreaBankRule($this->db, $this->user, $this->entity))->recordHit(
				(int) $row['bank_rule']['id'], $row['bank_rule']['revision'], $this->bankRuleLineEvidence($line));
		}
	}
}
