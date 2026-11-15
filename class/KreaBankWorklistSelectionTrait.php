<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/** One selector; scalar execution remains in reconcileLine(), groups in the batch service. */
trait KreaBankWorklistSelectionTrait
{
	public function listWorklistSelectionCandidates($key, $search = '')
	{
		$this->assertPaymentBatchActor(true);
		$search = trim((string) $search);
		if (strlen($search) > 256) { throw new DomainException('Bank search is too long'); }
		$source = $this->worklistSelectionSource($key);
		$grouping = $this->worklistSelectionGrouping($key);
		$groups = $grouping ? $this->listBatchCandidates((int) $source['line']['rowid'], $search) : null;
		$rows = array();
		foreach (($groups['rows'] ?? array()) as $row) {
			$row['group_eligible'] = true;
			$row['single_eligible'] = KreaBankPaymentBatchDecimal::normalize($row['amount_decimal']) === $source['amount'];
			$rows[(int) $row['rowid']] = $row;
		}
		$singleRows = $this->worklistSelectionNativeRows($source, 0, $search, false, $singleTruncated);
		$truncated = !empty($groups['truncated']) || $singleTruncated;
		foreach (array_slice($singleRows, 0, 100) as $raw) {
			try { $document = $this->worklistSelectionDocument($source, (int) $raw['rowid']); }
			catch (DomainException $error) { continue; }
			$id = (int) $raw['rowid'];
			if (!isset($rows[$id])) { $rows[$id] = $this->worklistSelectionCandidate($raw, $document); }
			$rows[$id]['single_eligible'] = true;
		}
		ksort($rows, SORT_NUMERIC);
		if (count($rows) > 100) { $truncated = true; }
		$rows = array_values(array_slice($rows, 0, 100, true));
		$suggestion = array('status'=>'none', 'ids'=>array(), 'candidate_count'=>count($rows));
		if ($search !== '') { $suggestion['status'] = 'filtered'; }
		elseif ($truncated) { $suggestion['status'] = 'truncated'; }
		else {
			$groupAdvice = $groups ? $this->getPaymentBatchSuggestion((int) $source['line']['rowid'], $groups) : array('status'=>'none','ids'=>array());
			$singles = array_column(array_values(array_filter($rows, static function ($row) { return $row['single_eligible']; })), 'rowid');
			if (count($singles) > 1 || $groupAdvice['status'] === 'ambiguous' || ($singles && $groupAdvice['status'] === 'unique')) {
				$suggestion['status'] = 'ambiguous';
			} elseif (!in_array($groupAdvice['status'], array('none','unique'), true)) {
				$suggestion['status'] = $groupAdvice['status'];
			} elseif ($singles) { $suggestion['status'] = 'unique'; $suggestion['ids'] = array_map('intval', $singles); }
			elseif ($groupAdvice['status'] === 'unique') { $suggestion['status'] = 'unique'; $suggestion['ids'] = $groupAdvice['ids']; }
		}
		return array('source'=>$source['line'], 'rows'=>$rows, 'truncated'=>$truncated, 'grouping_available'=>$grouping,
			'search'=>$search, 'suggestion'=>$suggestion);
	}

	/** Retain planning members; multiple selections may use only the existing group contract. */
	public function getWorklistSelectionMembers($key, $ids)
	{
		$this->assertPaymentBatchActor(true);
		$ids = $this->paymentBatchIds($ids, false);
		$source = $this->worklistSelectionSource($key);
		$grouping = $this->worklistSelectionGrouping($key);
		$members = $grouping ? $this->getPaymentBatchSelectedCandidates((int) $source['line']['rowid'], $ids) : array('rows'=>array(),'unavailable'=>$ids);
		foreach ($members['rows'] as &$row) {
			$row['group_eligible'] = true;
			$row['single_eligible'] = $row['amount_decimal'] === $source['amount'];
		}
		unset($row);
		if (count($ids) === 1 && $members['unavailable']) {
			try {
				$raw = $this->worklistSelectionNativeRows($source, $ids[0]);
				if (count($raw) !== 1) { throw new DomainException('Selected movement is unavailable'); }
				$document = $this->worklistSelectionDocument($source, $ids[0]);
				$members = array('rows'=>array($this->worklistSelectionCandidate($raw[0], $document)), 'unavailable'=>array());
			} catch (DomainException $error) { /* Preserve the explicit unavailable ID. */ }
		}
		return $members;
	}

	/** Exact selected ID, never a ranked or automatically executable choice. */
	public function prepareWorklistSelectionPair($key, $nativeId)
	{
		$this->assertPaymentBatchActor(true);
		$ids = $this->paymentBatchIds(array($nativeId), false);
		$source = $this->worklistSelectionSource($key);
		if (abs((float) $source['amount']) <= 0.00001) { throw new DomainException('The shared single-movement review does not support this amount'); }
		$target = $this->worklistSelectionTarget($source, $ids[0]);
		$document = $this->worklistSelectionDocument($source, $ids[0]);
		$suggestions = $this->matcher->getSuggestions($source['line'], array($document), $this->getPatterns(), 1, 0);
		if (count($suggestions) !== 1) { throw new DomainException('Selected movement is unavailable'); }
		return array('line'=>$source['line'], 'suggestion'=>$suggestions[0], 'allocation'=>(float) KreaBankPaymentBatchDecimal::absolute($source['amount']),
			'preselected'=>false, 'is_bulk_safe'=>false, 'score'=>(int) $suggestions[0]['score'], 'approval_context'=>'selected_native',
			'other_account_warning'=>false, 'worklist_selection'=>array('version'=>1,'key'=>$key,'source'=>$source['facts'],'target'=>$target));
	}

	/** Called only after reconcileLine() has acquired its account/source/native locks. */
	private function assertWorklistSelectionReview($review, $line)
	{
		if (!isset($review['worklist_selection'])) { return; }
		$selection = $review['worklist_selection'];
		if (empty($line['fk_statement']) || !is_array($selection) || ($selection['version'] ?? null) !== 1 || ($review['approval_context'] ?? '') !== 'selected_native'
			|| ($review['suggestion']['doc_type'] ?? '') !== 'native_bank') { throw new DomainException('Invalid movement selector review'); }
		$source = $this->worklistSelectionSource($selection['key'] ?? '', true);
		$expectedKey = 'staged:' . (int) $line['rowid'];
		if ($expectedKey !== $selection['key'] || $source['facts'] !== ($selection['source'] ?? null)
			|| $this->worklistSelectionTarget($source, (int) $review['suggestion']['doc_id'], true) !== ($selection['target'] ?? null)) {
			throw new DomainException('Statement or selected movement changed; review them again');
		}
	}

	/** Keep only the current reviewer's planning state in step with scalar completion. */
	private function clearWorklistSelectionDraft($review, $line)
	{
		if (!isset($review['worklist_selection']) || empty($line['fk_statement'])) { return; }
		$p = $this->db->prefix();
		if (!$this->tableExists($p . 'kreabank_payment_batch_draft')) { return; }
		$this->paymentBatchQuery("DELETE FROM {$p}kreabank_payment_batch_draft WHERE entity=" . (int) $this->entity
			. ' AND fk_user=' . (int) $this->user->id . ' AND fk_statement_line=' . (int) $line['rowid']);
	}

	private function worklistSelectionGrouping($key)
	{
		if (!$this->hasPaymentBatchSchema()) { return false; }
		try { $this->paymentBatchSource((int) substr($key, 7)); return true; }
		catch (DomainException $error) { return false; }
	}

	/** Only staging evidence can be mapped to another existing ledger movement. */
	private function worklistSelectionSource($key, $lock = false)
	{
		if (is_string($key) && preg_match('/^native:[1-9][0-9]{0,9}$/D', $key)) {
			throw new DomainException('Legacy native imports already occupy their bank movement and cannot select another movement');
		}
		if (!is_string($key) || !preg_match('/^staged:([1-9][0-9]{0,9})$/D', $key, $parts)) { throw new DomainException('Invalid staged statement identity'); }
		$id = (int) $parts[1]; $p = $this->db->prefix(); $e = (int) $this->entity;
		$line = $this->getBulkReviewLine($id, 'staged');
		if (!$line || empty($line['fk_statement']) || (int) $line['status'] !== 0 || !empty($line['is_duplicate']) || (float) $line['allocated_amount'] !== 0.0) { throw new DomainException('Statement is unavailable'); }
		if ($this->getWorklistPaymentAccountReview($id, array($line['bank_account_id']))) {
			throw new DomainException('Correct the payment bank account before reconciling this statement');
		}
		$sql = "SELECT l.*,s.ref AS source_statement_ref,s.currency AS source_statement_currency,s.source_type,s.bank_account_id,ba.currency_code AS bank_currency,ba.ref AS bank_account_ref"
			. " FROM {$p}kreabank_statement_line l JOIN {$p}kreabank_statement s ON s.rowid=l.fk_statement AND s.entity=l.entity"
			. " JOIN {$p}bank_account ba ON ba.rowid=s.bank_account_id AND ba.entity=s.entity WHERE l.entity=$e AND l.rowid=$id"
			. ' AND ba.clos=0 AND ba.rappro=1 AND ba.courant<>2' . ($lock ? ' FOR UPDATE' : '');
		$rows = $this->paymentBatchRows($sql);
		if (count($rows) !== 1) { throw new DomainException('Statement ownership changed'); }
		$raw = $rows[0];
		if ((int) $raw['status'] !== 0 || (int) $raw['is_duplicate'] || KreaBankPaymentBatchDecimal::normalize($raw['allocated_amount']) !== '0.00000000'
			|| $raw['currency'] !== $raw['source_statement_currency']) { throw new DomainException('Statement evidence changed'); }
		$amount = KreaBankPaymentBatchDecimal::normalize($raw['amount']);
		$day = substr((string) $raw['operation_date'], 0, 10);
		if (!preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/D', $day, $date) || !checkdate((int) $date[2], (int) $date[3], (int) $date[1])
			|| $amount === '0.00000000' || $line['currency'] !== $raw['bank_currency']
			|| self::automaticDecimal($line['amount']) !== self::automaticDecimal($amount)) { throw new DomainException('Statement exact amount, currency or date changed'); }
		if ($raw['source_type'] === 'fintable' && !$this->automaticFeedEvidenceValid($id, $lock)) { throw new DomainException('Bank feed evidence changed'); }
		$line['amount_decimal'] = $amount;
		(new KreaBankReconciliationScope($this->db, $this->entity))->assertLine($line);
		return array('line'=>$line,'amount'=>$amount,'day'=>$day,'facts'=>array('key'=>$key,'record'=>$raw,'meta'=>array()));
	}

	/** Exact singles use the shared native provider after this bounded identity search. */
	private function worklistSelectionNativeRows($source, $id = 0, $search = '', $lock = false, &$truncated = null)
	{
		$p = $this->db->prefix(); $line = $source['line']; $day = $this->sqlText($source['day']);
		$sql = "SELECT b.*,CAST(b.amount AS DECIMAL(24,8)) AS amount_decimal,ba.ref AS bank_account_ref,ba.currency_code AS currency FROM {$p}bank b"
			. " JOIN {$p}bank_account ba ON ba.rowid=b.fk_account WHERE ba.entity=" . (int) $this->entity . ' AND ba.rowid=' . (int) $line['bank_account_id']
			. ' AND ba.clos=0 AND ba.rappro=1 AND b.rappro=0 AND b.amount=' . $source['amount']
			. " AND b.dateo>=$day AND b.dateo<DATE_ADD($day,INTERVAL 1 DAY) AND ba.currency_code=" . $this->sqlText($line['currency'])
			. " AND NOT EXISTS (SELECT 1 FROM {$p}kreabank_bankmeta m WHERE m.fk_bank_line=b.rowid AND COALESCE(m.source_type,'')<>'')"
			. " AND NOT EXISTS (SELECT 1 FROM {$p}kreabank_statement_line m WHERE m.fk_native_bank_line=b.rowid)"
			. $this->paymentBatchUnclaimedSql() . $this->paymentBatchOwnershipSql(false);
		$own = (int) ($line['native_bank_line_id'] ?? 0);
		if ($own > 0) { $sql .= ' AND b.rowid<>' . $own; }
		if ($id > 0) { $sql .= ' AND b.rowid=' . (int) $id; }
		if ($search !== '') {
			$needle = $this->sqlText('%' . strtr($search, array('!'=>'!!','%'=>'!%','_'=>'!_')) . '%');
			$sql .= " AND (CONCAT_WS(' ',b.label,b.note,b.num_chq,b.dateo,ba.ref) LIKE $needle ESCAPE '!' OR EXISTS (SELECT 1 FROM {$p}bank_url u WHERE u.fk_bank=b.rowid AND u.label LIKE $needle ESCAPE '!'))";
		}
		$rows = $this->paymentBatchRows($sql . ' ORDER BY b.rowid' . ($lock ? ' FOR UPDATE' : $this->db->plimit(101)));
		$truncated = count($rows) > 100;
		// Native bank.amount can be DOUBLE: SQL equality alone is not the decimal contract.
		return array_values(array_filter($rows, static function ($row) use ($source) {
			return KreaBankPaymentBatchDecimal::normalize($row['amount_decimal']) === $source['amount']
				&& substr((string) $row['dateo'], 0, 10) === $source['day'] && $row['currency'] === $source['line']['currency'];
		}));
	}

	private function worklistSelectionDocument($source, $id)
	{
		$line = $source['line'];
		$documents = $this->getOpenNativeBankDocuments((int) $line['direction'], 20, null, 0, (int) ($line['native_bank_line_id'] ?? 0), (int) $line['bank_account_id'], $id, true);
		if (count($documents) !== 1) { throw new DomainException('Selected native movement is unavailable'); }
		(new KreaBankReconciliationScope($this->db, $this->entity))->assertDocument('native_bank', $id, (int) $line['bank_account_id']);
		return $documents[0];
	}

	private function worklistSelectionCandidate($raw, $document)
	{
		return array('rowid'=>(int) $raw['rowid'],'ref'=>$document['ref'],'payment_refs'=>$document['payment_ref'] ?? '',
			'doc_date'=>$raw['dateo'],'amount_decimal'=>KreaBankPaymentBatchDecimal::normalize($raw['amount_decimal']),
			'bank_account_ref'=>$raw['bank_account_ref'],'description'=>$document['description'],'currency'=>$raw['currency'],
			'single_eligible'=>true,'group_eligible'=>false);
	}

	private function worklistSelectionTarget($source, $id, $lock = false)
	{
		$rows = $this->worklistSelectionNativeRows($source, $id, '', $lock);
		if (count($rows) !== 1) { throw new DomainException('Selected movement must have the exact amount and booking day'); }
		$this->worklistSelectionDocument($source, $id);
		$p = $this->db->prefix(); $suffix = $lock ? ' FOR UPDATE' : '';
		$facts = array('record'=>$rows[0],'links'=>$this->paymentBatchRows("SELECT * FROM {$p}bank_url WHERE fk_bank=" . (int) $id . ' ORDER BY rowid' . $suffix),
			'meta'=>$this->paymentBatchRows("SELECT * FROM {$p}kreabank_bankmeta WHERE fk_bank_line=" . (int) $id . ' ORDER BY rowid' . $suffix));
		foreach (array(array('paiement','paiement_facture','facture','fk_paiement','fk_facture'),array('paiementfourn','paiementfourn_facturefourn','facture_fourn','fk_paiementfourn','fk_facturefourn')) as $tables) {
			list($pay,$allocation,$invoice,$paymentKey,$invoiceKey) = $tables;
			$facts[$pay] = $this->paymentBatchRows("SELECT * FROM {$p}{$pay} WHERE fk_bank=" . (int) $id . ' ORDER BY rowid' . $suffix);
			$facts[$allocation] = $this->paymentBatchRows("SELECT a.* FROM {$p}{$allocation} a JOIN {$p}{$pay} p ON p.rowid=a.{$paymentKey} WHERE p.fk_bank=" . (int) $id . ' ORDER BY a.rowid' . $suffix);
			$facts[$invoice] = $this->paymentBatchRows("SELECT DISTINCT f.* FROM {$p}{$invoice} f JOIN {$p}{$allocation} a ON a.{$invoiceKey}=f.rowid JOIN {$p}{$pay} p ON p.rowid=a.{$paymentKey} WHERE p.fk_bank=" . (int) $id . ' ORDER BY f.rowid' . $suffix);
		}
		foreach (array('payment_salary','paiementcharge') as $table) { $facts[$table] = $this->paymentBatchRows("SELECT * FROM {$p}{$table} WHERE fk_bank=" . (int) $id . ' ORDER BY rowid' . $suffix); }
		$facts['salary'] = $this->paymentBatchRows("SELECT DISTINCT s.* FROM {$p}salary s JOIN {$p}payment_salary ps ON ps.fk_salary=s.rowid WHERE ps.fk_bank=" . (int) $id . ' ORDER BY s.rowid' . $suffix);
		$facts['chargesociales'] = $this->paymentBatchRows("SELECT DISTINCT t.* FROM {$p}chargesociales t JOIN {$p}paiementcharge pc ON pc.fk_charge=t.rowid WHERE pc.fk_bank=" . (int) $id . ' ORDER BY t.rowid' . $suffix);
		$facts['transfer_counterparts'] = $this->paymentBatchRows("SELECT DISTINCT c.* FROM {$p}bank c JOIN {$p}bank_account ba ON ba.rowid=c.fk_account"
			. " JOIN {$p}bank_url u ON u.url_id=c.rowid AND u.type='banktransfert' WHERE ba.entity=" . (int) $this->entity . ' AND u.fk_bank=' . (int) $id . ' ORDER BY c.rowid' . $suffix);
		$facts['transfer_links'] = $this->paymentBatchRows("SELECT DISTINCT reciprocal.* FROM {$p}bank_url reciprocal JOIN {$p}bank c ON c.rowid=reciprocal.fk_bank"
			. " JOIN {$p}bank_account ba ON ba.rowid=c.fk_account JOIN {$p}bank_url u ON u.url_id=c.rowid AND u.type='banktransfert'"
			. ' WHERE ba.entity=' . (int) $this->entity . ' AND u.fk_bank=' . (int) $id . ' ORDER BY reciprocal.rowid' . $suffix);
		return $facts;
	}
}
