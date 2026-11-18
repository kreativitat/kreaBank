<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
require_once __DIR__ . '/KreaBankUberSettings.class.php';
require_once __DIR__ . '/KreaBankReconciliationLock.class.php';

/** Explicit payout review only. Never called by the matcher, scheduler or learning. */
trait KreaBankUberSettlementTrait
{
	public function listUberSettlements($sourceKey)
	{
		$this->assertUberSettlementActor();
		$id = $this->worklistMovementSourceId($sourceKey);
		$source = $this->uberSettlementSource($id);
		$settings = new KreaBankUberSettings($this->db, $this->entity);
		$map = $settings->mappings();
		if (!$map) { return array(); }
		$bridge = new KreaEatsBankIntegration($this->db, $this->user, $this->entity);
		$choices = $bridge->findPayouts($source['bank_account_id'], KreaBankUberSettings::cents($source['amount']), $source['operation_date']);
		$result = array();
		foreach ($choices as $payout) {
			if (!isset($map[(int) $payout['store']['rowid']])) { continue; }
			$result[] = $this->uberSettlementAccounts($payout, $map, false) + array('source_key'=>$sourceKey);
		}
		return $result;
	}

	public function prepareUberSettlementReview($sourceKey, $settlementId)
	{
		$this->assertUberSettlementActor();
		$facts = $this->uberSettlementFacts($sourceKey, $settlementId, false);
		return $this->uberSettlementSign(array('action'=>'uber_settlement','source_key'=>$sourceKey) + $facts);
	}

	/** Both transfer legs, bank mapping, KreaEats association and audit share one transaction. */
	public function reconcileUberSettlement($review)
	{
		$this->assertUberSettlementActor();
		$this->uberSettlementVerify($review);
		if ((int) ($this->db->transaction_opened ?? 0) !== 0) { throw new LogicException('Uber settlement must own its complete transaction'); }
		$this->ensureCoreSchema();
		$companyLock = new KreaBankReconciliationLock($this->db, $this->entity);
		$companyLock->acquire();
		try {
			if (!$this->db->begin()) { throw new RuntimeException('Cannot start Uber settlement reconciliation'); }
			$p = $this->db->prefix();
			$this->worklistMovementRows("SELECT rowid FROM {$p}bank_account WHERE entity=" . $this->entity . ' ORDER BY rowid FOR UPDATE');
			$this->lockReconciliationRow('kreabank_statement_line', $this->worklistMovementSourceId($review['source_key']));
			$fresh = $this->uberSettlementFacts($review['source_key'], (int) $review['payout']['settlement']['rowid'], true);
			foreach (array('source','payout','mapping_revision','transfer','operation_code') as $key) {
				if ($fresh[$key] !== $review[$key]) { throw new DomainException('Uber settlement changed; review the current payout again'); }
			}
			$source = $fresh['source']; $payout = $fresh['payout']; $transfer = $fresh['transfer'];
			$created = $transfer['state'] === 'create';
			if ($created) {
				list($clearingId, $bankId) = $this->uberSettlementCreateTransfer($fresh);
			} else {
				$clearingId = $transfer['clearing_line_id']; $bankId = $transfer['bank_line_id'];
			}
			$this->uberSettlementAssertNative($bankId, $clearingId, $fresh, true);
			$bridge = new KreaEatsBankIntegration($this->db, $this->user, $this->entity);
			$linkId = $bridge->associate((int) $payout['settlement']['rowid'], $bankId, $payout['snapshot_hash']);
			$line = $this->native->getStatementLineById($source['rowid']);
			if (!$line) { throw new DomainException('The bank statement is unavailable'); }
			(new KreaBankReconciliationScope($this->db, $this->entity))->assertLine($line);
			(new KreaBankReconciliationScope($this->db, $this->entity))->assertDocument('native_bank', $bankId, $source['bank_account_id']);
			$this->finalizeNativeReconciliation($line, $bankId, $source['amount'], 'staged', array(
				'strategy'=>'manual_uber','is_auto'=>0,'native'=>1,'source'=>'staged','native_bank_line_id'=>$bankId,
				'paired_bank_line_id'=>$clearingId,'created_transfer'=>$created,'identity'=>'reviewed_uber_settlement',
				'uber_settlement_id'=>(int) $payout['settlement']['rowid'],'uber_store_id'=>(int) $payout['store']['rowid'],
				'uber_payout_ref'=>$payout['settlement']['payout_ref'],'uber_source_hash'=>$payout['snapshot_hash'],'uber_link_id'=>$linkId,
				'clearing_account_id'=>(int) $payout['clearing_account']['rowid'],'amount_decimal'=>$source['amount'],
				'currency'=>$source['currency'],'operation_code'=>$fresh['operation_code'],'source_key'=>$review['source_key'],
			));
			if ((int) $this->db->transaction_opened !== 1 || !$this->db->commit()) { throw new RuntimeException('Uber settlement commit is unknown; inspect its status before retrying'); }
			return $bankId;
		} catch (Throwable $error) { $this->db->rollback(); throw $error; }
		finally { $companyLock->release(); }
	}

	private function assertUberSettlementActor()
	{
		$this->assertTransferActor();
		$this->assertAutomaticActor(true);
		if (!$this->user->hasRight('kreaeats', 'read') || !$this->user->hasRight('kreaeats', 'reconcile')) {
			throw new DomainException('KreaEats reconciliation permission is required');
		}
		(new KreaBankUberSettings($this->db, $this->entity))->mappings();
	}

	private function uberSettlementSource($id, $lock = false)
	{
		$source = $this->worklistMovementSource($id, $lock);
		if ($source['currency'] !== 'EUR' || KreaBankUberSettings::cents($source['amount']) <= 0) {
			throw new DomainException('Uber settlement requires a positive EUR bank credit');
		}
		$line = $this->native->getStatementLineById($id);
		if (!$line) { throw new DomainException('The statement source is unavailable'); }
		(new KreaBankReconciliationScope($this->db, $this->entity))->assertLine($line);
		return $source;
	}

	private function uberSettlementFacts($sourceKey, $settlementId, $lock)
	{
		$id = $this->worklistMovementSourceId($sourceKey);
		$source = $this->uberSettlementSource($id, $lock);
		$settings = new KreaBankUberSettings($this->db, $this->entity);
		$map = $settings->mappings($lock);
		$bridge = new KreaEatsBankIntegration($this->db, $this->user, $this->entity);
		$payout = $bridge->payout((int) $settlementId, $lock);
		if (empty($payout['eligible']) || !isset($map[(int) $payout['store']['rowid']])
			|| (int) $payout['store']['fk_account'] !== $source['bank_account_id']
			|| (int) $payout['settlement']['net'] !== KreaBankUberSettings::cents($source['amount'])
			|| $source['operation_date'] < $payout['window']['start'] || $source['operation_date'] > $payout['window']['end']) {
			throw new DomainException('The Uber payout is unavailable or does not match this bank credit');
		}
		$payout = $this->uberSettlementAccounts($payout, $map, $lock);
		$historical = KreaBankOpeningBalance::historicalSql($this->db, $this->entity, 'ca.rowid', 'l.operation_date', 'l.currency');
		$p = $this->db->prefix();
		$opening = $this->worklistMovementRows('SELECT (' . $historical . ") AS historical FROM {$p}kreabank_statement_line l JOIN {$p}bank_account ca"
			. ' ON ca.entity=l.entity AND ca.rowid=' . (int) $payout['clearing_account']['rowid'] . ' WHERE l.entity=' . $this->entity . ' AND l.rowid=' . $id);
		if (!empty($opening[0]['historical'])) { throw new DomainException('The Uber clearing movement precedes its immutable opening reconciliation'); }
		$transfer = $this->uberSettlementTransfer($source, $payout, $lock);
		if ($this->getWorklistPaymentAccountReview($id, array($source['bank_account_id']))) {
			throw new DomainException('An existing payment in another account requires review');
		}
		$operation = $transfer['state'] === 'reuse' ? (string) $transfer['facts']['native'][$transfer['bank_line_id']]['fk_type']
			: ((int) $payout['clearing_account']['courant'] === 2 ? 'LIQ' : 'VIR');
		return array('source'=>$source,'payout'=>$payout,'mapping_revision'=>KreaBankUberSettings::mapRevision($map),
			'transfer'=>$transfer,'operation_code'=>$operation);
	}

	private function uberSettlementAccounts($payout, $map, $lock)
	{
		$clearingId = (int) $map[(int) $payout['store']['rowid']];
		$receivingId = (int) $payout['store']['fk_account'];
		$p = $this->db->prefix();
		$rows = $this->worklistMovementRows("SELECT rowid,entity,ref,label,currency_code,clos,courant,rappro FROM {$p}bank_account WHERE entity="
			. $this->entity . " AND rowid IN ($clearingId,$receivingId) ORDER BY rowid" . ($lock ? ' FOR UPDATE' : ''));
		$accounts = array();
		foreach ($rows as $row) {
			foreach (array('rowid','entity','clos','courant','rappro') as $key) { $row[$key] = (int) $row[$key]; }
			$accounts[$row['rowid']] = $row;
		}
		if (!isset($accounts[$clearingId], $accounts[$receivingId])) { throw new DomainException('The Uber clearing or receiving account is unavailable'); }
		KreaBankUberSettings::assertAccounts($payout['store'], $accounts[$clearingId], $accounts[$receivingId],
			(new KreaBankAccountPairs($this->db, $this->entity))->pairs($lock));
		$payout['clearing_account'] = $accounts[$clearingId]; $payout['receiving_account'] = $accounts[$receivingId];
		return $payout;
	}

	/** An equal-amount nearby entry or a provider reference anywhere blocks blind creation. */
	private function uberSettlementTransfer($source, $payout, $lock)
	{
		$p = $this->db->prefix(); $from = $payout['clearing_account']['rowid']; $to = $source['bank_account_id'];
		$amount = $source['amount']; $opposite = '-' . $amount;
		$radius = max(7, min(31, (int) getDolGlobalInt('KREABANK_AUTOMATCH_DATE_TOLERANCE', 3)));
		$start = gmdate('Y-m-d', strtotime($source['operation_date'] . ' UTC') - 86400*$radius);
		$end = gmdate('Y-m-d', strtotime($source['operation_date'] . ' UTC') + 86400*($radius+1));
		$reference = strtr($payout['settlement']['payout_ref'], array('!'=>'!!','%'=>'!%','_'=>'!_'));
		$ref = $this->sqlText('%' . $reference . '%');
		$rows = $this->worklistMovementRows("SELECT b.* FROM {$p}bank b JOIN {$p}bank_account a ON a.rowid=b.fk_account WHERE a.entity=" . $this->entity
			. " AND (((b.fk_account=$to AND CAST(b.amount AS DECIMAL(24,8))=$amount)"
			. " OR (b.fk_account=$from AND CAST(b.amount AS DECIMAL(24,8))=$opposite)) AND b.dateo>=" . $this->sqlText($start)
			. ' AND b.dateo<' . $this->sqlText($end) . " OR b.label LIKE $ref ESCAPE '!' OR b.num_chq LIKE $ref ESCAPE '!')"
			. ' ORDER BY b.rowid' . $this->db->plimit(101) . ($lock ? ' FOR UPDATE' : ''));
		if (!$rows) {
			$this->assertWorklistMovementNoConflict($source, $lock);
			return array('state'=>'create','bank_line_id'=>0,'clearing_line_id'=>0,'facts'=>array());
		}
		if (count($rows) !== 2) { throw new DomainException('Existing Uber movement evidence is ambiguous; review the native transfers'); }
		$bankId = 0; $clearingId = 0;
		foreach ($rows as $row) {
			if ((int) $row['fk_account'] === $to) { $bankId = (int) $row['rowid']; }
			if ((int) $row['fk_account'] === $from) { $clearingId = (int) $row['rowid']; }
		}
		if (!$bankId || !$clearingId) { throw new DomainException('One-sided Uber transfer evidence requires review'); }
		$facts = $this->uberSettlementAssertNative($bankId, $clearingId, array('source'=>$source,'payout'=>$payout), $lock);
		return array('state'=>'reuse','bank_line_id'=>$bankId,'clearing_line_id'=>$clearingId,'facts'=>$facts);
	}

	private function uberSettlementAssertNative($bankId, $clearingId, $facts, $lock)
	{
		$p = $this->db->prefix(); $source = $facts['source']; $payout = $facts['payout'];
		$rows = $this->worklistMovementRows("SELECT * FROM {$p}bank WHERE rowid IN (" . (int) $bankId . ',' . (int) $clearingId . ') ORDER BY rowid' . ($lock ? ' FOR UPDATE' : ''));
		$native = array();
		foreach ($rows as $row) { $native[(int) $row['rowid']] = $row; }
		if (count($native) !== 2 || !isset($native[$bankId], $native[$clearingId])) { throw new DomainException('The complete Uber transfer is unavailable'); }
		$credit = $native[$bankId]; $debit = $native[$clearingId];
		if ((int) $credit['fk_account'] !== $source['bank_account_id'] || (int) $debit['fk_account'] !== (int) $payout['clearing_account']['rowid']
			|| KreaBankPaymentBatchDecimal::normalize($credit['amount']) !== $source['amount'] || KreaBankPaymentBatchDecimal::normalize($debit['amount']) !== '-' . $source['amount']
			|| (int) $credit['rappro'] !== 0 || (int) $debit['rappro'] !== 0 || (int) $credit['fk_bordereau'] || (int) $debit['fk_bordereau']
			|| substr($credit['dateo'], 0, 10) !== $source['operation_date'] || substr($debit['dateo'], 0, 10) !== $source['operation_date']
			|| substr($credit['datev'], 0, 10) !== ($source['value_date'] ?: $source['operation_date'])
			|| substr($debit['datev'], 0, 10) !== ($source['value_date'] ?: $source['operation_date'])) {
			throw new DomainException('The native Uber transfer does not match the reviewed bank evidence');
		}
		if ((string) $credit['fk_type'] === '' || $credit['fk_type'] === 'SOLD' || $credit['fk_type'] !== $debit['fk_type']
			|| ((int) $payout['clearing_account']['courant'] === 2 && $credit['fk_type'] !== 'LIQ')) {
			throw new DomainException('The native Uber transfer operation does not match its account types');
		}
		$links = $this->worklistMovementRows("SELECT * FROM {$p}bank_url WHERE fk_bank IN (" . (int) $bankId . ',' . (int) $clearingId . ') ORDER BY rowid' . ($lock ? ' FOR UPDATE' : ''));
		$financialLinks = array_values(array_filter($links, static function ($link) { return $link['type'] !== 'company'; }));
		$expected = array($bankId=>$clearingId,$clearingId=>$bankId);
		foreach ($financialLinks as $link) {
			if ($link['type'] !== 'banktransfert' || !isset($expected[(int) $link['fk_bank']]) || (int) $link['url_id'] !== $expected[(int) $link['fk_bank']]) {
				throw new DomainException('The Uber transfer has conflicting native links');
			}
			unset($expected[(int) $link['fk_bank']]);
		}
		if ($expected || count($financialLinks) !== 2) { throw new DomainException('The Uber transfer needs complete reciprocal native links'); }
		foreach (array($bankId, $clearingId) as $id) {
			$this->assertPaymentBatchMovementUnclaimed($id);
			if ($this->ownTransferHasPayment($id, $lock)
				|| $this->worklistMovementRows("SELECT rowid FROM {$p}kreabank_statement_line WHERE fk_native_bank_line=" . (int) $id . ($lock ? ' FOR UPDATE' : ''))
				|| $this->worklistMovementRows("SELECT rowid FROM {$p}kreabank_bankmeta WHERE fk_bank_line=" . (int) $id . ($lock ? ' FOR UPDATE' : ''))
				|| $this->worklistMovementRows("SELECT rowid FROM {$p}kreaeats_link WHERE kind='bank' AND fk_target=" . (int) $id . ($lock ? ' FOR UPDATE' : ''))) {
				throw new DomainException('The Uber transfer already belongs to other payment or statement evidence');
			}
		}
		return array('native'=>$native,'links'=>$links);
	}

	private function uberSettlementCreateTransfer($facts)
	{
		$source = $facts['source']; $payout = $facts['payout'];
		$from = new Account($this->db); $to = new Account($this->db);
		if ($from->fetch($payout['clearing_account']['rowid']) <= 0 || $to->fetch($source['bank_account_id']) <= 0) {
			throw new DomainException('The reviewed Uber transfer accounts are unavailable');
		}
		$label = 'Uber payout ' . $payout['settlement']['payout_ref'];
		$date = KreaBankUberSettings::nativeDayTimestamp($source['operation_date']);
		$valueDate = KreaBankUberSettings::nativeDayTimestamp($source['value_date'] ?: $source['operation_date']);
		$note = 'Reviewed KreaEats payout #' . (int) $payout['settlement']['rowid'] . '; original bank evidence retained in KreaBank.';
		$clearingId = $from->addline($date, $facts['operation_code'], $label, '-' . $source['amount'], '', 0, $this->user, '', '', '', $valueDate, '', null, $note);
		if ($clearingId <= 0) { throw new RuntimeException('Cannot create the Uber clearing transfer leg'); }
		$bankId = $to->addline($date, $facts['operation_code'], $label, $source['amount'], '', 0, $this->user, '', '', '', $valueDate, '', null, $note);
		if ($bankId <= 0 || $from->add_url_line($clearingId, $bankId, DOL_URL_ROOT . '/compta/bank/line.php?rowid=', '(banktransfert)', 'banktransfert') <= 0
			|| $to->add_url_line($bankId, $clearingId, DOL_URL_ROOT . '/compta/bank/line.php?rowid=', '(banktransfert)', 'banktransfert') <= 0) {
			throw new RuntimeException('Cannot persist both reciprocal Uber transfer legs');
		}
		return array((int) $clearingId, (int) $bankId);
	}

	private function uberSettlementSign($review)
	{
		$review += array('entity'=>(int) $this->entity,'user'=>(int) $this->user->id,'expires'=>time()+900,'nonce'=>bin2hex(random_bytes(24)));
		if (empty($_SESSION['kreabank_uber_secret'])) { $_SESSION['kreabank_uber_secret'] = bin2hex(random_bytes(32)); }
		$review['signature'] = hash_hmac('sha256', $this->worklistMovementJson($review), $_SESSION['kreabank_uber_secret']);
		$_SESSION['kreabank_uber_reviews'][$review['nonce']] = array('signature'=>$review['signature'],'expires'=>$review['expires']);
		foreach ($_SESSION['kreabank_uber_reviews'] as $nonce=>$saved) {
			if ($saved['expires'] < time()) { unset($_SESSION['kreabank_uber_reviews'][$nonce]); }
		}
		if (count($_SESSION['kreabank_uber_reviews']) > 30) { $_SESSION['kreabank_uber_reviews'] = array_slice($_SESSION['kreabank_uber_reviews'], -30, null, true); }
		return $review;
	}

	private function uberSettlementVerify($review)
	{
		if (!is_array($review)) { throw new DomainException('The Uber settlement review is missing'); }
		$nonce = $review['nonce'] ?? ''; $signature = $review['signature'] ?? '';
		if (!is_string($nonce) || !preg_match('/^[a-f0-9]{48}$/D', $nonce)) { throw new DomainException('The Uber settlement review changed or expired'); }
		$saved = $_SESSION['kreabank_uber_reviews'][$nonce] ?? null;
		unset($_SESSION['kreabank_uber_reviews'][$nonce], $review['signature']);
		if (!$saved || ($review['action'] ?? '') !== 'uber_settlement' || ($review['entity'] ?? 0) !== (int) $this->entity
			|| ($review['user'] ?? 0) !== (int) $this->user->id || ($review['expires'] ?? 0) < time() || ($review['expires'] ?? PHP_INT_MAX) > time()+900
			|| !is_string($signature) || !hash_equals($saved['signature'], $signature)
			|| !hash_equals(hash_hmac('sha256', $this->worklistMovementJson($review), $_SESSION['kreabank_uber_secret'] ?? ''), $signature)) {
			throw new DomainException('The Uber settlement review changed or expired; review the current payout');
		}
	}
}
