<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
require_once __DIR__ . '/KreaBankDecimal.class.php';

/** Positive settlement receipts only; the service owns transactions and native persistence. */
trait KreaBankAutomaticTpaTrait
{
	/** Exact bank descriptors, rather than a merchant-name substring, identify the settlement type. */
	protected static function automaticTpaDescriptor($description)
	{
		$identity = KreaBankMatcher::tpaSettlementIdentity($description);
		if ($identity !== '') { return array('kind'=>'terminal', 'identity'=>$identity); }
		$text = trim((string) preg_replace('/\s+/u', ' ', (string) $description));
		return preg_match('/^TRF CRED SEPA\+ DE UNICRE S\.A\.$/iD', $text)
			? array('kind'=>'unicre', 'identity'=>'') : null;
	}

	/** Terminal counters can repeat: only the same exact signed amount identifies a competing credit. */
	protected static function automaticTpaSameCredit($identity, $amount, $otherIdentity, $otherAmount)
	{
		return $identity !== '' && $identity === $otherIdentity && KreaBankDecimal::compare($amount, '0') > 0
			&& KreaBankDecimal::compare($amount, $otherAmount) === 0;
	}

	/** Read-only advice. Every eligibility check is repeated with locks before native writes. */
	public function getAutomaticTpaSuggestion($lineId, $accountIds, $lock = false)
	{
		global $conf;
		$this->assertAutomaticActor();
		if ($this->getPaymentAccountReview($lineId, $accountIds)) { return null; }
		$p = $this->db->prefix(); $suffix = $lock ? ' FOR UPDATE' : '';
		$settings = array();
		foreach ($this->transferRows("SELECT name,value FROM {$p}const WHERE entity=" . $this->entity
			. " AND name IN ('KREABANK_AUTO_NATIVE_TPA_ENABLED','KREABANK_AUTO_NATIVE_ACCOUNTS','KREABANK_AUTO_NATIVE_TPA_PAYMENT_MODE')" . $suffix) as $setting) {
			$settings[$setting->name] = $setting->value;
		}
		if (($settings['KREABANK_AUTO_NATIVE_TPA_ENABLED'] ?? '') !== '1') { return null; }
		$paymentMode = (string) ($settings['KREABANK_AUTO_NATIVE_TPA_PAYMENT_MODE'] ?? '');
		if (!array_key_exists($paymentMode, KreaBankNativeBankAdapter::activePaymentModes($this->db, $lock))) { return null; }
		$selected = array_intersect(array_map('intval', (array) $accountIds), array_map('intval', explode(',', $settings['KREABANK_AUTO_NATIVE_ACCOUNTS'] ?? '')));
		$rows = $this->transferStatementRows(' AND l.rowid=' . (int) $lineId, $lock);
		if (count($rows) !== 1) { return null; }
		$raw = $rows[0]; $amount = self::automaticDecimal($raw->amount);
		if ($this->automaticStatementSourceClaimed((int) $raw->rowid, $lock) || !in_array((int) $raw->bank_account_id, $selected, true) || KreaBankDecimal::compare($amount, '0') <= 0
			|| (string) $raw->currency !== (string) $conf->currency || (string) $raw->statement_currency !== (string) $raw->currency) { return null; }
		if (!empty($raw->value_date) && (!preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/D', $raw->value_date, $parts)
			|| !checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]))) { return null; }
		$settlement = self::automaticTpaDescriptor($raw->description);
		if (!$settlement || (new KreaBankAccountPairs($this->db, $this->entity))->mainAccount((int) $raw->bank_account_id)) { return null; }
		$provider = array();
		if ($settlement['kind'] === 'unicre') {
			$provider = $this->automaticTpaProviderEvidence($raw, $lock);
			if (!$provider) { return null; }
			$settlement['identity'] = 'unicre:' . hash('sha256', (string) $raw->bank_reference);
		}
		$identity = $settlement['identity']; $account = (int) $raw->bank_account_id;
		$tolerance = max(0, min(31, (int) getDolGlobalInt('KREABANK_AUTOMATCH_DATE_TOLERANCE', 3)));
		$range = $this->resolveOpenDocumentsDateRange($raw->operation_date, $tolerance);
		$end = gmdate('Y-m-d', strtotime($range['end'] . ' UTC') + 86400);
		$where = $settlement['kind'] === 'terminal'
			? " AND l.description LIKE '%TPA%' AND l.operation_date=" . $this->sqlDate($raw->operation_date)
			: ' AND (l.bank_reference=' . $this->sqlText((string) $raw->bank_reference) . ' OR l.line_uid=' . $this->sqlText((string) $raw->line_uid) . ')';
		$others = $this->transferRows("SELECT l.rowid,l.description,l.amount FROM {$p}kreabank_statement_line l"
			. " JOIN {$p}kreabank_statement s ON s.rowid=l.fk_statement AND s.entity=l.entity"
			. ' WHERE l.entity=' . $this->entity . ' AND s.bank_account_id=' . $account . ' AND l.rowid<>' . (int) $lineId
			. $where . ' ORDER BY l.rowid' . $this->db->plimit(1001) . $suffix);
		if (count($others) >= 1001) { return null; }
		foreach ($others as $other) {
			if ($settlement['kind'] === 'unicre' || self::automaticTpaSameCredit($identity, $amount,
				KreaBankMatcher::tpaSettlementIdentity($other->description), (string) $other->amount)) { return null; }
		}
		// Include reconciled/imported rows. A different proven settlement is distinct evidence,
		// while an unidentified equal amount remains a possible duplicate requiring review.
		$native = $this->transferRows("SELECT b.rowid,b.amount,b.dateo,b.label,b.num_chq,b.rappro,b.num_releve,b.fk_type,"
			. "m.description,m.bank_reference,m.entity AS meta_entity,m.currency AS meta_currency,m.status AS meta_status,m.source_type AS meta_source_type"
			. " FROM {$p}bank b JOIN {$p}bank_account ba ON ba.rowid=b.fk_account"
			. " LEFT JOIN {$p}kreabank_bankmeta m ON m.fk_bank_line=b.rowid"
			. ' WHERE ba.entity=' . $this->entity . ' AND b.fk_account=' . $account
			. ' AND b.dateo>=' . $this->sqlDate($range['start']) . ' AND b.dateo<' . $this->sqlDate($end)
			. ' ORDER BY b.rowid' . $this->db->plimit(1001) . $suffix);
		if (count($native) >= 1001) { return null; }
		$reuse = 0;
		foreach ($native as $candidate) {
			$sameAmount = KreaBankDecimal::compare((string) $candidate->amount, $amount) === 0;
			$nativeIdentity = $this->automaticTpaNativeIdentity($candidate, $settlement, $raw);
			if ($nativeIdentity === null) {
				if ($sameAmount || $this->automaticTpaNativeMayReferTo($candidate, $settlement, $raw)) { return null; }
				continue;
			}
			if (!$sameAmount && $nativeIdentity !== $identity) { continue; }
			$claims = $this->automaticTpaNativeClaims($candidate, $raw, $nativeIdentity, $lock);
			if ($claims === null) { return null; }
			if ($nativeIdentity === $identity && !$sameAmount) {
				// A completed, audited different amount from this booking day is a separate
				// credit. An unexplained native amount change is never repaired automatically.
				if ($settlement['kind'] !== 'terminal' || !$claims || !$this->automaticTpaDistinctPostedCredit($candidate, $claims[0], $raw, $identity, $lock)) { return null; }
				continue;
			}
			if ($sameAmount && $nativeIdentity !== '' && $nativeIdentity !== $identity) { continue; }
			if (!$sameAmount || $nativeIdentity !== $identity || $reuse || (int) $candidate->rappro !== 0 || $claims
				|| (int) $candidate->meta_status === 2 || !empty($candidate->meta_source_type)
				|| !$this->automaticTpaNativeReceiptUnlinked((int) $candidate->rowid, $lock)) { return null; }
			$reuse = (int) $candidate->rowid;
		}
		$payments = $this->transferRows("SELECT rowid FROM {$p}paiement WHERE entity=" . $this->entity
			. ' AND (fk_bank IS NULL OR fk_bank=0) AND amount=' . $amount
			. ' AND datep>=' . $this->sqlDate($range['start']) . ' AND datep<' . $this->sqlDate($end)
			. $this->db->plimit(1) . $suffix);
		if ($payments) { return null; }
		$line = $this->native->getStatementLineById((int) $lineId);
		return array('line'=>$line, 'identity'=>$identity, 'kind'=>$settlement['kind'], 'payment_mode'=>$paymentMode,
			'bank_line_id'=>$reuse, 'amount_decimal'=>$amount, 'provider_source_uid'=>$provider['source_uid'] ?? '');
	}

	/** A provider credit needs its persisted identity and complete normalized source payload. */
	private function automaticTpaProviderEvidence($raw, $lock)
	{
		if ($raw->source_type !== 'fintable' || !preg_match('/^fintable:[a-f0-9]{64}$/D', (string) $raw->line_uid)
			|| !is_string($raw->bank_reference) || strlen($raw->bank_reference) < 8 || strlen($raw->bank_reference) > 190
			|| preg_match('/[\x00-\x20\x7f]/', $raw->bank_reference)) { return null; }
		$rows = $this->transferRows('SELECT source_uid,provider_transaction_id,external_id_hash,content_hash,source_payload FROM '
			. $this->db->prefix() . 'kreabank_feed_transaction WHERE entity=' . $this->entity . ' AND fk_statement_line=' . (int) $raw->rowid
			. ' ORDER BY rowid' . ($lock ? ' FOR UPDATE' : ''));
		if (count($rows) !== 1) { return null; }
		$record = $rows[0]; $payload = json_decode($record->source_payload, true);
		if (!is_array($payload) || !hash_equals((string) $record->content_hash, hash('sha256', (string) $record->source_payload))
			|| ($payload['line_uid'] ?? '') !== $raw->line_uid || $record->source_uid !== $raw->line_uid
			|| ($payload['bank_reference'] ?? '') !== $raw->bank_reference || ($payload['operation_date'] ?? '') !== $raw->operation_date
			|| ($payload['currency'] ?? '') !== $raw->currency || (self::automaticTpaDescriptor($payload['description'] ?? '')['kind'] ?? '') !== 'unicre') { return null; }
		try { if (KreaBankDecimal::compare((string) ($payload['amount'] ?? ''), (string) $raw->amount) !== 0) { return null; } }
		catch (InvalidArgumentException $error) { return null; }
		if ($raw->bank_reference !== $record->provider_transaction_id
			&& (!is_string($record->external_id_hash) || !hash_equals($record->external_id_hash, hash('sha256', $raw->bank_reference)))) { return null; }
		return array('source_uid'=>(string) $record->source_uid);
	}

	/** Native labels can have display prefixes; refunds/fees never become settlement identities. */
	private function automaticTpaNativeIdentity($candidate, $settlement, $raw)
	{
		$identities = array(); $unicre = false;
		foreach (array($candidate->label, $candidate->num_chq, $candidate->description) as $text) {
			if (preg_match('/\b(?:ESTORNO|DEVOLU[CÇ][AÃ]O|COMISS[AÃ]O|CANCELAMENTO|REFUND|CHARGEBACK)\b/iu', (string) $text)) { return null; }
			$descriptor = self::automaticTpaDescriptor(preg_replace('/^.*?\b(?=FECHO TPA )/iu', '', (string) $text));
			if (($descriptor['kind'] ?? '') === 'terminal') { $identities[$descriptor['identity']] = true; }
			$unicre = $unicre || (($descriptor['kind'] ?? '') === 'unicre');
		}
		if (count($identities) > 1 || ($identities && $unicre)) { return null; }
		if ($identities) { return (string) key($identities); }
		if (!$unicre) { return ''; }
		$reference = trim((string) $candidate->bank_reference);
		if ($reference !== '' && !empty($candidate->num_chq)
			&& $candidate->num_chq !== KreaBankNativeBankAdapter::nativePaymentReference($reference)) { return null; }
		if ($reference === '') { $reference = trim((string) $candidate->num_chq); }
		if ($settlement['kind'] === 'unicre' && $candidate->num_chq === KreaBankNativeBankAdapter::nativePaymentReference((string) $raw->bank_reference)) {
			$reference = (string) $raw->bank_reference;
		}
		return strlen($reference) >= 8 ? 'unicre:' . hash('sha256', $reference) : '';
	}

	/** Contradictory evidence can block a related receipt, but never authorizes one. */
	private function automaticTpaNativeMayReferTo($candidate, $settlement, $raw)
	{
		if ($settlement['kind'] === 'unicre') {
			return (string) $candidate->bank_reference === (string) $raw->bank_reference
				|| (string) $candidate->num_chq === KreaBankNativeBankAdapter::nativePaymentReference((string) $raw->bank_reference);
		}
		foreach (array($candidate->label, $candidate->num_chq, $candidate->description) as $text) {
			// Remove reversal words only to detect a reference to the source. The
			// original text was rejected above and is never reusable identity evidence.
			$text = preg_replace('/\b(?:ESTORNO|DEVOLU[CÇ][AÃ]O|COMISS[AÃ]O|CANCELAMENTO|REFUND|CHARGEBACK)\b/iu', '', (string) $text);
			$text = preg_replace('/^.*?\b(?=FECHO TPA )/iu', '', $text);
			if (KreaBankMatcher::tpaSettlementIdentity($text) === $settlement['identity']) { return true; }
		}
		return false;
	}

	/** Null denotes inconsistent ownership/mappings; an empty array is an unclaimed native row. */
	private function automaticTpaNativeClaims($candidate, $raw, $identity, $lock)
	{
		if ($candidate->meta_entity !== null && ((int) $candidate->meta_entity !== $this->entity
			|| (string) $candidate->meta_currency !== (string) $raw->currency)) { return null; }
		$p = $this->db->prefix(); $id = (int) $candidate->rowid;
		$claims = $this->transferRows("SELECT l.*,s.entity AS statement_entity,s.bank_account_id,s.ref AS statement_ref,s.source_type FROM {$p}kreabank_statement_line l"
			. " LEFT JOIN {$p}kreabank_statement s ON s.rowid=l.fk_statement WHERE l.fk_native_bank_line=$id ORDER BY l.rowid"
			. $this->db->plimit(2) . ($lock ? ' FOR UPDATE' : ''));
		if (count($claims) > 1) { return null; }
		foreach ($claims as $claim) {
			$descriptor = self::automaticTpaDescriptor($claim->description);
			$claimIdentity = ($descriptor['kind'] ?? '') === 'unicre' ? 'unicre:' . hash('sha256', (string) $claim->bank_reference) : ($descriptor['identity'] ?? '');
			if ((int) $claim->entity !== $this->entity || (int) $claim->statement_entity !== $this->entity
				|| (int) $claim->bank_account_id !== (int) $raw->bank_account_id || (string) $claim->currency !== (string) $raw->currency
				|| (int) $claim->status !== 2 || (int) $candidate->rappro !== 1 || !empty($claim->is_duplicate)
				|| $this->automaticStatementSourceClaimed((int) $claim->rowid, $lock)
				|| ($candidate->meta_entity !== null && (int) $candidate->meta_status !== 2)
				|| KreaBankDecimal::compare((string) $claim->amount, (string) $candidate->amount) !== 0
				|| KreaBankDecimal::compare((string) $claim->allocated_amount, (string) $candidate->amount) !== 0
				|| $claimIdentity === '' || $claimIdentity !== $identity || $claim->statement_ref !== $candidate->num_releve
				|| ($claim->source_type === 'fintable' && !$this->automaticFeedEvidenceValid((int) $claim->rowid, $lock))) { return null; }
		}
		if ($this->paymentBatchMapsAvailable() && $this->transferRows("SELECT rowid FROM {$p}kreabank_payment_batch_line WHERE active_bank_line=$id"
			. $this->db->plimit(1) . ($lock ? ' FOR UPDATE' : ''))) { return null; }
		return $claims;
	}

	/** Only a complete original auto receipt can explain a different same-counter amount. */
	private function automaticTpaDistinctPostedCredit($candidate, $claim, $raw, $identity, $lock)
	{
		if ($claim->operation_date !== $raw->operation_date || substr((string) $candidate->dateo, 0, 10) !== $claim->operation_date) { return false; }
		$audits = $this->transferRows('SELECT audit_type,payload_json FROM ' . $this->db->prefix() . 'kreabank_recon_audit WHERE entity=' . $this->entity
			. ' AND fk_statement_line=' . (int) $claim->rowid . " AND audit_type IN ('reconcile','undo') ORDER BY rowid DESC"
			. $this->db->plimit(1) . ($lock ? ' FOR UPDATE' : ''));
		if (!$audits || $audits[0]->audit_type !== 'reconcile') { return false; }
		$audit = json_decode($audits[0]->payload_json, true);
		return is_array($audit) && ($audit['strategy'] ?? '') === 'auto_tpa'
			&& (int) ($audit['native_bank_line_id'] ?? 0) === (int) $candidate->rowid
			&& ($audit['terminal_settlement'] ?? '') === $identity;
	}

	/** Reuse never captures a transfer, invoice payment or another financial object's movement. */
	private function automaticTpaNativeReceiptUnlinked($id, $lock)
	{
		$p = $this->db->prefix(); $suffix = $lock ? ' FOR UPDATE' : '';
		if ($this->transferRows("SELECT rowid FROM {$p}bank_url WHERE fk_bank=$id AND type<>'company'" . $this->db->plimit(1) . $suffix)) { return false; }
		foreach (array('paiement','paiementfourn','paiementcharge','payment_salary','payment_various','payment_expensereport','payment_donation','payment_loan','payment_vat') as $table) {
			if ($this->tableExists($p . $table) && $this->transferRows("SELECT rowid FROM {$p}{$table} WHERE fk_bank=$id" . $this->db->plimit(1) . $suffix)) { return false; }
		}
		return true;
	}

	/** Create or reuse one proven credit; all original evidence, claims and audit commit atomically. */
	public function reconcileAutomaticTpaReceipt($lineId, $accountIds)
	{
		$this->assertAutomaticActor();
		if (!$this->db->begin()) { throw new Exception('Cannot start card-terminal receipt reconciliation'); }
		try {
			$this->transferRows('SELECT rowid FROM ' . $this->db->prefix() . 'bank_account WHERE entity=' . $this->entity . ' ORDER BY rowid FOR UPDATE');
			$mode = $this->transferRows('SELECT value FROM ' . $this->db->prefix() . 'const WHERE entity=' . $this->entity
				. " AND name='KREABANK_AUTO_NATIVE_MODE' FOR UPDATE");
			if (!$mode || $mode[0]->value !== 'enabled') { $this->db->rollback(); return false; }
			$match = $this->getAutomaticTpaSuggestion($lineId, $accountIds, true);
			if (!$match) { $this->db->rollback(); return false; }
			$line = $match['line']; $nativeId = (int) $match['bank_line_id']; $created = $nativeId === 0;
			$scope = new KreaBankReconciliationScope($this->db, $this->entity); $scope->assertLine($line);
			if ($created) { $nativeId = $this->native->ensureNativeLineForStatementLine((int) $lineId, false, true, $match['payment_mode']); }
			else { $this->resolveExistingNativeBankLineForReconciliation($line, $nativeId, $match['amount_decimal']); }
			$scope->assertDocument('native_bank', $nativeId, (int) $line['bank_account_id']);
			$persisted = $this->transferRows('SELECT amount,fk_type FROM ' . $this->db->prefix() . 'bank WHERE rowid=' . $nativeId . ' FOR UPDATE');
			if (count($persisted) !== 1 || ($created && (string) $persisted[0]->fk_type !== $match['payment_mode'])
				|| KreaBankDecimal::compare((string) $persisted[0]->amount, $match['amount_decimal']) !== 0) {
				throw new Exception('Native card receipt amount differs from source evidence');
			}
			$this->finalizeNativeReconciliation($line, $nativeId, $match['amount_decimal'], 'staged', array(
				'strategy'=>'auto_tpa', 'is_auto'=>1, 'native'=>1, 'native_bank_line_id'=>$nativeId,
				'terminal_settlement'=>$match['kind'] === 'terminal' ? $match['identity'] : '',
				'settlement_kind'=>$match['kind'], 'settlement_identity'=>$match['identity'], 'settlement_amount'=>$match['amount_decimal'],
				'provider_source_uid'=>$match['provider_source_uid'], 'created_receipt'=>$created, 'operation_code'=>(string) $persisted[0]->fk_type,
			));
			if (!$this->db->commit()) { throw new Exception('Card-terminal receipt commit was not confirmed; inspect before retrying'); }
			return true;
		} catch (Throwable $e) { $this->db->rollback(); throw $e; }
	}
}
