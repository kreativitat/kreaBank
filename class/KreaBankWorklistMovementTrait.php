<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/** Reviewed native movement creation for the separate reconciliation list. */
trait KreaBankWorklistMovementTrait
{
	/** Prepare immutable statement evidence; opening a review creates no financial record. */
	public function prepareWorklistMovementReview($sourceKey)
	{
		$this->assertWorklistMovementActor();
		$id = $this->worklistMovementSourceId($sourceKey);
		$source = $this->worklistMovementSource($id);
		$this->assertWorklistMovementNoConflict($source);
		$modes = KreaBankNativeBankAdapter::activePaymentModes($this->db);
		if (!$modes) { throw new DomainException('Select an active native payment mode before creating a movement'); }
		$review = array('action'=>'create_movement','source_key'=>$sourceKey,'source'=>$source,'modes'=>$modes,
			'entity'=>(int) $this->entity,'user'=>(int) $this->user->id,'expires'=>time()+900,'nonce'=>bin2hex(random_bytes(24)));
		if (empty($_SESSION['kreabank_worklist_movement_secret'])) { $_SESSION['kreabank_worklist_movement_secret'] = bin2hex(random_bytes(32)); }
		$review['signature'] = hash_hmac('sha256', $this->worklistMovementJson($review), $_SESSION['kreabank_worklist_movement_secret']);
		$_SESSION['kreabank_worklist_movement_reviews'][$review['nonce']] = array('expires'=>$review['expires'],'signature'=>$review['signature']);
		foreach ($_SESSION['kreabank_worklist_movement_reviews'] as $nonce=>$saved) {
			if ($saved['expires'] < time()) { unset($_SESSION['kreabank_worklist_movement_reviews'][$nonce]); }
		}
		while (count($_SESSION['kreabank_worklist_movement_reviews']) > 30) { array_shift($_SESSION['kreabank_worklist_movement_reviews']); }
		return $review;
	}

	/** Create exactly the reviewed statement movement through the existing native adapter. */
	public function createWorklistMovementAndReconcile($review, $paymentMode, $note = '')
	{
		$this->assertWorklistMovementActor();
		$this->verifyWorklistMovementReview($review);
		$id = $this->worklistMovementSourceId($review['source_key']);
		if (!is_string($paymentMode) || !isset($review['modes'][$paymentMode])) { throw new DomainException('Select a reviewed active native payment mode'); }
		if (!is_string($note) || mb_strlen($note, 'UTF-8') > 1000 || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $note)) {
			throw new DomainException('Enter a note of at most 1000 characters');
		}
		$note = trim($note);
		// Adapter schema guards must be settled before the atomic financial transaction.
		$this->ensureCoreSchema();
		$p = $this->db->prefix();
		if (!$this->db->begin()) { throw new RuntimeException('Cannot start bank movement creation'); }
		try {
			// Match the shared transfer/receipt lock order, including possible wrong-account corrections.
			$this->worklistMovementRows("SELECT rowid FROM {$p}bank_account WHERE entity=" . $this->entity . ' ORDER BY rowid FOR UPDATE');
			$this->lockReconciliationRow('kreabank_statement_line', $id);
			$source = $this->worklistMovementSource($id, true);
			if ($source !== $review['source']) { throw new DomainException('The statement changed; review the current movement again'); }
			if (!isset(KreaBankNativeBankAdapter::activePaymentModes($this->db, true)[$paymentMode])) { throw new DomainException('The selected native payment mode is no longer active'); }
			$this->assertWorklistMovementNoConflict($source, true);
			$line = $this->native->getStatementLineById($id);
			if (!$line || (int) ($line['fk_statement'] ?? 0) !== (int) $source['fk_statement']) { throw new DomainException('The statement source is unavailable'); }
			(new KreaBankReconciliationScope($this->db, $this->entity))->assertLine($line);
			$nativeId = $this->native->ensureNativeLineForStatementLine($id, false, false, '', $paymentMode);
			$native = $this->worklistMovementRows("SELECT b.rowid,b.fk_account,CAST(b.amount AS DECIMAL(24,8)) AS amount,b.fk_type,b.dateo,b.datev,b.rappro"
				. " FROM {$p}bank b JOIN {$p}bank_account a ON a.rowid=b.fk_account WHERE a.entity=" . $this->entity . " AND b.rowid=$nativeId FOR UPDATE");
			if (count($native) !== 1 || (int) $native[0]['fk_account'] !== $source['bank_account_id'] || (int) $native[0]['rappro'] !== 0
				|| KreaBankPaymentBatchDecimal::normalize($native[0]['amount']) !== $source['amount'] || $native[0]['fk_type'] !== $paymentMode
				|| substr((string) $native[0]['dateo'], 0, 10) !== $source['operation_date']
				|| substr((string) $native[0]['datev'], 0, 10) !== ($source['value_date'] ?: $source['operation_date'])) {
				throw new DomainException('The native movement differs from the exact statement evidence');
			}
			$this->finalizeNativeReconciliation($line, $nativeId, KreaBankPaymentBatchDecimal::absolute($source['amount']), 'staged', array(
				'strategy'=>'manual_worklist_movement','is_auto'=>0,'native'=>1,'source'=>'staged','native_bank_line_id'=>$nativeId,
				'created_native'=>1,'operation_code'=>$paymentMode,'amount_decimal'=>$source['amount'],'currency'=>$source['currency'],
				'operation_date'=>$source['operation_date'],'value_date'=>$source['value_date'],'note'=>$note,
				'source_key'=>$review['source_key'],'review_source_hash'=>hash('sha256', $this->worklistMovementJson($source)),
			));
			if (!$this->db->commit()) { throw new RuntimeException('Bank movement commit was not confirmed; inspect its status before retrying'); }
			return $nativeId;
		} catch (Throwable $error) { $this->db->rollback(); throw $error; }
	}

	private function assertWorklistMovementActor()
	{
		$this->assertAutomaticActor(true);
		$this->assertAutomaticActor();
		if (getDolGlobalInt('BANK_DISABLE_DIRECT_INPUT')) { throw new DomainException('Direct bank movement creation is disabled'); }
	}

	private function worklistMovementSourceId($key)
	{
		if (!is_string($key) || !preg_match('/^staged:([1-9][0-9]{0,9})$/D', $key, $parts)) {
			throw new DomainException('Only a staged statement can create a new bank movement');
		}
		return (int) $parts[1];
	}

	/** Capture raw decimals and financial evidence without the service's repaired display projection. */
	private function worklistMovementSource($id, $lock = false)
	{
		global $conf;
		$p = $this->db->prefix(); $e = (int) $this->entity;
		$rows = $this->worklistMovementRows("SELECT l.*,s.ref AS statement_ref,s.source_type,s.filename AS source_file,s.currency AS statement_currency,"
			. "s.bank_account_id,a.ref AS bank_account_ref,a.currency_code AS bank_currency,a.clos,a.rappro,a.courant"
			. " FROM {$p}kreabank_statement_line l JOIN {$p}kreabank_statement s ON s.rowid=l.fk_statement AND s.entity=l.entity"
			. " JOIN {$p}bank_account a ON a.rowid=s.bank_account_id AND a.entity=s.entity WHERE l.entity=$e AND l.rowid=$id" . ($lock ? ' FOR UPDATE' : ''));
		if (count($rows) !== 1) { throw new DomainException('The statement is unavailable in the current company'); }
		$source = $rows[0];
		foreach (array('rowid','entity','fk_statement','fk_native_bank_line','bank_account_id','direction','status','is_duplicate','duplicate_of','clos','rappro','courant') as $field) { $source[$field] = (int) $source[$field]; }
		$source['amount'] = KreaBankPaymentBatchDecimal::normalize($source['amount']);
		$source['allocated_amount'] = KreaBankPaymentBatchDecimal::normalize($source['allocated_amount']);
		if ($source['running_balance'] !== null) { $source['running_balance'] = KreaBankPaymentBatchDecimal::normalize($source['running_balance']); }
		if ($source['status'] !== 0 || $source['allocated_amount'] !== '0.00000000' || $source['fk_native_bank_line'] || $source['is_duplicate'] || $source['duplicate_of']
			|| $source['clos'] || $source['rappro'] !== 1 || $source['courant'] === 2 || $source['amount'] === '0.00000000'
			|| $source['currency'] !== $source['bank_currency'] || $source['currency'] !== $source['statement_currency'] || $source['currency'] !== (string) $conf->currency
			|| trim((string) $source['statement_ref']) === '' || !$this->worklistMovementDate($source['operation_date'])
			|| (!empty($source['value_date']) && !$this->worklistMovementDate($source['value_date']))
			|| ($source['direction'] !== 0 && $source['direction'] !== ($source['amount'][0] === '-' ? -1 : 1))) {
			throw new DomainException('The statement is unavailable for new bank movement creation');
		}
		(new KreaBankAccountPairs($this->db, $e))->assertDocument('quick_entry', 0, $source['bank_account_id']);
		$source['feed_facts'] = array();
		if ($source['source_type'] === 'fintable') {
			if (!$this->automaticFeedEvidenceValid($id, $lock)) { throw new DomainException('Bank feed evidence changed or requires conflict review'); }
			$source['feed_facts'] = $this->worklistMovementRows("SELECT ft.rowid,ft.fk_feed_account,ft.source_uid,ft.financial_hash,ft.content_hash,ft.status,ft.conflict_payload,"
				. "fa.fk_bank_account,fa.entity,fa.currency,fa.status AS account_status FROM {$p}kreabank_feed_transaction ft"
				. " JOIN {$p}kreabank_feed_account fa ON fa.rowid=ft.fk_feed_account AND fa.entity=ft.entity WHERE ft.entity=$e AND ft.fk_statement_line=$id ORDER BY ft.rowid" . ($lock ? ' FOR UPDATE' : ''));
		}
		$source['batch_facts'] = array();
		$batch = $this->worklistMovementHasTable('kreabank_payment_batch');
		$members = $this->worklistMovementHasTable('kreabank_payment_batch_line');
		if ($batch !== $members) { throw new DomainException('The payment batch schema is incomplete'); }
		$source['batch_schema'] = $batch;
		if ($batch) {
			$source['batch_facts'] = $this->worklistMovementRows("SELECT rowid,status,active_statement_line FROM {$p}kreabank_payment_batch WHERE entity=$e AND fk_statement_line=$id AND active_statement_line IS NOT NULL ORDER BY rowid" . ($lock ? ' FOR UPDATE' : ''));
			if ($source['batch_facts']) { throw new DomainException('An active payment batch already owns the statement'); }
		}
		$source['meta_facts'] = trim((string) $source['line_uid']) === '' ? array() : $this->worklistMovementRows(
			"SELECT rowid,fk_bank_line,status FROM {$p}kreabank_bankmeta WHERE entity=$e AND bank_account_id=" . $source['bank_account_id']
			. ' AND source_type=' . $this->sqlText($source['source_type']) . ' AND source_line_uid=' . $this->sqlText($source['line_uid'])
			. ' ORDER BY rowid' . $this->db->plimit(101) . ($lock ? ' FOR UPDATE' : ''));
		if ($source['meta_facts']) { throw new DomainException('The statement source already has native movement evidence'); }
		return $source;
	}

	/** Search absence is checked independently of the row's typed search and displayed page. */
	private function assertWorklistMovementNoConflict($source, $lock = false)
	{
		$p = $this->db->prefix(); $e = (int) $this->entity; $account = $source['bank_account_id']; $id = $source['rowid'];
		$tolerance = max(0, min(31, (int) getDolGlobalInt('KREABANK_AUTOMATCH_DATE_TOLERANCE', 3)));
		$range = $this->resolveOpenDocumentsDateRange($source['operation_date'], $tolerance);
		$start = $this->sqlDate($range['start']); $end = $this->sqlDate(gmdate('Y-m-d', strtotime($range['end'] . ' UTC') + 86400));
		$references = array_values(array_unique(array_filter(array(trim((string) $source['payment_reference']), trim((string) $source['bank_reference'])), static function ($value) { return $value !== ''; })));
		$refList = $references ? implode(',', array_map(array($this, 'sqlText'), $references)) : '';
		$refSql = $refList !== '' ? " OR b.num_chq IN ($refList) OR EXISTS (SELECT 1 FROM {$p}bank_url u WHERE u.fk_bank=b.rowid AND u.label IN ($refList))" : '';
		$native = $this->worklistMovementRows("SELECT b.rowid FROM {$p}bank b JOIN {$p}bank_account a ON a.rowid=b.fk_account WHERE a.entity=$e"
			. ' AND a.currency_code=' . $this->sqlText($source['currency']) . ' AND CAST(b.amount AS DECIMAL(24,8))=' . $source['amount']
			. " AND ((b.fk_account=$account AND b.dateo>=$start AND b.dateo<$end)$refSql) ORDER BY b.rowid" . $this->db->plimit(101) . ($lock ? ' FOR UPDATE' : ''));
		if ($native) { throw new DomainException(count($native) > 100 ? 'Existing movement search is incomplete; review the current bank entries' : 'An existing bank movement requires review before creating another'); }
		$identity = array();
		if (trim((string) $source['duplicate_hash']) !== '') { $identity[] = 'l.duplicate_hash=' . $this->sqlText($source['duplicate_hash']); }
		if (trim((string) $source['line_uid']) !== '') { $identity[] = '(l.line_uid=' . $this->sqlText($source['line_uid']) . ' AND s.source_type=' . $this->sqlText($source['source_type']) . ')'; }
		if ($identity && $this->worklistMovementRows("SELECT l.rowid FROM {$p}kreabank_statement_line l JOIN {$p}kreabank_statement s ON s.rowid=l.fk_statement AND s.entity=l.entity"
			. " WHERE l.entity=$e AND s.bank_account_id=$account AND l.rowid<>$id AND (" . implode(' OR ', $identity) . ') ORDER BY l.rowid' . $this->db->plimit(101) . ($lock ? ' FOR UPDATE' : ''))) {
			throw new DomainException('Another statement has the same source identity; review the duplicate evidence');
		}
		$absolute = KreaBankPaymentBatchDecimal::absolute($source['amount']);
		$paymentTable = $source['amount'][0] === '-' ? 'paiementfourn' : 'paiement';
		$paymentRef = $refList !== '' ? " OR p.ref IN ($refList) OR p.num_paiement IN ($refList)" : '';
		$payments = $this->worklistMovementRows("SELECT p.rowid FROM {$p}{$paymentTable} p WHERE p.entity=$e AND ABS(CAST(p.amount AS DECIMAL(24,8)))=$absolute"
			. " AND (((p.fk_bank IS NULL OR p.fk_bank=0) AND p.datep>=$start AND p.datep<$end)$paymentRef) ORDER BY p.rowid" . $this->db->plimit(101) . ($lock ? ' FOR UPDATE' : ''));
		if ($payments) { throw new DomainException('An existing invoice payment requires review before creating a bank movement'); }
		if ($this->worklistMovementHasTable('payment_various')) {
			$variousRef = $refList !== '' ? " OR p.ref IN ($refList) OR p.num_payment IN ($refList)" : '';
			$various = $this->worklistMovementRows("SELECT p.rowid FROM {$p}payment_various p WHERE p.entity=$e AND p.sens=" . ($source['amount'][0] === '-' ? 0 : 1)
				. " AND ABS(CAST(p.amount AS DECIMAL(24,8)))=$absolute AND (((p.fk_bank IS NULL OR p.fk_bank=0) AND p.datep>=$start AND p.datep<$end)$variousRef) ORDER BY p.rowid"
				. $this->db->plimit(101) . ($lock ? ' FOR UPDATE' : ''));
			if ($various) { throw new DomainException('An existing miscellaneous payment requires review before creating a bank movement'); }
		}
		if ($source['amount'][0] === '-') {
			foreach (array(array('payment_salary','salary','fk_salary','num_payment'),array('paiementcharge','chargesociales','fk_charge','num_paiement')) as $tables) {
				list($payment,$parent,$parentKey,$reference) = $tables;
				$hasPayment = $this->worklistMovementHasTable($payment); $hasParent = $this->worklistMovementHasTable($parent);
				if (!$hasPayment && !$hasParent) { continue; }
				if (!$hasPayment || !$hasParent) { throw new DomainException('The native payment schema is incomplete'); }
				$paymentRef = $refList !== '' ? " OR p.$reference IN ($refList)" : '';
				$rows = $this->worklistMovementRows("SELECT p.rowid FROM {$p}{$payment} p JOIN {$p}{$parent} d ON d.rowid=p.$parentKey WHERE d.entity=$e"
					. " AND ABS(CAST(p.amount AS DECIMAL(24,8)))=$absolute AND (((p.fk_bank IS NULL OR p.fk_bank=0) AND p.datep>=$start AND p.datep<$end)$paymentRef) ORDER BY p.rowid"
					. $this->db->plimit(101) . ($lock ? ' FOR UPDATE' : ''));
				if ($rows) { throw new DomainException('An existing salary or tax payment requires review before creating a bank movement'); }
			}
		}
		if ($this->getWorklistPaymentAccountReview($id, array($account))) { throw new DomainException('An existing payment in another account requires review'); }
	}

	private function worklistMovementDate($value)
	{
		return is_string($value) && preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/D', $value, $parts) && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
	}

	private function worklistMovementRows($sql)
	{
		$result = $this->db->query($sql);
		if (!$result) { throw new RuntimeException('Cannot verify bank movement evidence; check the module schema and database'); }
		$rows = array();
		while ($row = $this->db->fetch_object($result)) { $rows[] = (array) $row; }
		return $rows;
	}

	private function worklistMovementHasTable($suffix)
	{
		return count($this->worklistMovementRows("SHOW TABLES LIKE '" . $this->db->escape($this->db->prefix() . $suffix) . "'")) > 0;
	}

	private function worklistMovementJson($value)
	{
		$json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		if ($json === false) { throw new DomainException('Cannot encode the movement review'); }
		return $json;
	}

	private function verifyWorklistMovementReview($review)
	{
		if (!is_array($review)) { throw new DomainException('The bank movement review is missing'); }
		$nonce = $review['nonce'] ?? ''; $signature = $review['signature'] ?? '';
		if (!is_string($nonce) || !preg_match('/^[a-f0-9]{48}$/D', $nonce)) { throw new DomainException('The bank movement review changed or expired'); }
		$saved = $_SESSION['kreabank_worklist_movement_reviews'][$nonce] ?? null;
		unset($_SESSION['kreabank_worklist_movement_reviews'][$nonce], $review['signature']);
		if (!$saved || ($review['action'] ?? '') !== 'create_movement' || ($review['entity'] ?? 0) !== (int) $this->entity || ($review['user'] ?? 0) !== (int) $this->user->id
			|| ($review['expires'] ?? 0) < time() || ($review['expires'] ?? PHP_INT_MAX) > time()+900
			|| !is_string($signature) || !preg_match('/^[a-f0-9]{64}$/D', $signature) || !hash_equals($saved['signature'], $signature)
			|| !hash_equals(hash_hmac('sha256', $this->worklistMovementJson($review), $_SESSION['kreabank_worklist_movement_secret'] ?? ''), $signature)) {
			throw new DomainException('The bank movement review changed or expired; review the current statement');
		}
	}
}
