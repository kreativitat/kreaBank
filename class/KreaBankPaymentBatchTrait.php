<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/** Exact signed DECIMAL(24,8) arithmetic without platform integer/float limits. */
final class KreaBankPaymentBatchDecimal
{
	public static function normalize($value)
	{
		if ((!is_string($value) && !is_int($value)) || !preg_match('/^([+-]?)([0-9]{1,16})(?:\.([0-9]{1,8}))?$/D', (string) $value, $parts)) {
			throw new DomainException('Invalid exact bank amount');
		}
		$whole = ltrim($parts[2], '0');
		$decimal = ($whole === '' ? '0' : $whole) . '.' . str_pad($parts[3] ?? '', 8, '0');
		return $parts[1] === '-' && $decimal !== '0.00000000' ? '-' . $decimal : $decimal;
	}

	public static function absolute($value)
	{
		return ltrim(self::normalize($value), '-');
	}

	public static function sum($values)
	{
		$total = '0';
		foreach ($values as $value) {
			$normalized = self::normalize($value);
			$negative = $normalized[0] === '-';
			$digits = ltrim(str_replace(array('-', '.'), '', $normalized), '0') ?: '0';
			$totalNegative = $total[0] === '-';
			$current = ltrim($total, '-');
			if ($negative === $totalNegative) {
				$carry = 0; $result = '';
				for ($i = strlen($current) - 1, $j = strlen($digits) - 1; $i >= 0 || $j >= 0 || $carry; $i--, $j--) {
					$next = ($i >= 0 ? (int) $current[$i] : 0) + ($j >= 0 ? (int) $digits[$j] : 0) + $carry;
					$result = ($next % 10) . $result; $carry = intdiv($next, 10);
				}
				$total = ($negative && $result !== '0' ? '-' : '') . $result;
			} else {
				$comparison = strlen($current) <=> strlen($digits);
				if (!$comparison) { $comparison = strcmp($current, $digits); }
				$larger = $comparison >= 0 ? $current : $digits;
				$smaller = $comparison >= 0 ? $digits : $current;
				$borrow = 0; $result = '';
				for ($i = strlen($larger) - 1, $j = strlen($smaller) - 1; $i >= 0; $i--, $j--) {
					$next = (int) $larger[$i] - ($j >= 0 ? (int) $smaller[$j] : 0) - $borrow;
					$borrow = $next < 0 ? 1 : 0;
					$result = ($next < 0 ? $next + 10 : $next) . $result;
				}
				$result = ltrim($result, '0') ?: '0';
				$total = (($comparison >= 0 ? $totalNegative : $negative) && $result !== '0' ? '-' : '') . $result;
			}
		}
		$negative = $total[0] === '-';
		$digits = str_pad(ltrim($total, '-'), 9, '0', STR_PAD_LEFT);
		return ($negative ? '-' : '') . substr($digits, 0, -8) . '.' . substr($digits, -8);
	}
}

/** Shared service extension; all mutations retain the native bank adapter lifecycle. */
trait KreaBankPaymentBatchTrait
{
	public function hasPaymentBatchSchema()
	{
		foreach (array('kreabank_payment_batch','kreabank_payment_batch_line','kreabank_payment_batch_draft') as $table) {
			if (!$this->tableExists($this->db->prefix() . $table)) { return false; }
		}
		return true;
	}

	/** Read-only bounded discovery. A total alone never chooses a group. */
	public function listBatchCandidates($stagingId, $search = '')
	{
		$this->assertPaymentBatchActor(true);
		$source = $this->paymentBatchSource($stagingId);
		$search = trim((string) $search);
		if (strlen($search) > 256) { throw new DomainException('Bank search is too long'); }
		$p = $this->db->prefix();
		$sql = "SELECT b.rowid,b.fk_account AS bank_account_id,ba.ref AS bank_account_ref,ba.currency_code AS currency,"
			. " CAST(b.amount AS DECIMAL(24,8)) AS amount_decimal,b.dateo AS doc_date,b.label AS description,b.num_chq AS ref,"
			. " (SELECT GROUP_CONCAT(DISTINCT u.type ORDER BY u.type) FROM {$p}bank_url u WHERE u.fk_bank=b.rowid) AS url_types,"
			. " (SELECT GROUP_CONCAT(u.label ORDER BY u.rowid SEPARATOR ' / ') FROM {$p}bank_url u WHERE u.fk_bank=b.rowid) AS payment_refs"
			. " FROM {$p}bank b JOIN {$p}bank_account ba ON ba.rowid=b.fk_account"
			. ' WHERE ba.entity=' . $this->entity . ' AND b.fk_account=' . (int) $source['bank_account_id']
			. ' AND b.rappro=0 AND COALESCE(b.fk_bordereau,0)=0 AND b.amount' . ($source['amount'][0] === '-' ? '<0' : '>0')
			. " AND COALESCE(b.fk_type,'')<>'SOLD'"
			. " AND NOT EXISTS (SELECT 1 FROM {$p}kreabank_bankmeta m WHERE m.fk_bank_line=b.rowid)"
			. " AND NOT EXISTS (SELECT 1 FROM {$p}kreabank_statement_line m WHERE m.fk_native_bank_line=b.rowid)"
			. " AND NOT EXISTS (SELECT 1 FROM {$p}kreabank_payment_batch_line m WHERE m.active_bank_line=b.rowid)"
			. $this->paymentBatchOwnershipSql();
		foreach (preg_split('/\s+/u', $search, -1, PREG_SPLIT_NO_EMPTY) as $token) {
			$needle = str_replace(array('!', '%', '_'), array('!!', '!%', '!_'), $token);
			$sql .= " AND (CONCAT_WS(' ',b.label,b.num_chq,ba.ref,b.dateo) LIKE " . $this->sqlText('%' . $needle . '%') . " ESCAPE '!'"
				. " OR EXISTS (SELECT 1 FROM {$p}bank_url u WHERE u.fk_bank=b.rowid AND u.label LIKE " . $this->sqlText('%' . $needle . '%') . " ESCAPE '!'))";
		}
		$sql .= ' ORDER BY ABS(DATEDIFF(b.dateo,' . $this->sqlText($source['operation_date']) . ')),b.dateo,b.rowid' . $this->db->plimit(101);
		$rows = $this->paymentBatchRows($sql);
		$truncated = count($rows) > 100;
		$rows = array_slice($rows, 0, 100);
		foreach ($rows as &$row) {
			$row['id'] = (int) $row['rowid']; $row['rowid'] = (int) $row['rowid'];
			$row['amount'] = $row['amount_decimal'] = KreaBankPaymentBatchDecimal::normalize($row['amount_decimal']);
			$row['operation_date'] = $row['doc_date'];
		}
		unset($row);
		return array('source'=>$source, 'rows'=>$rows, 'truncated'=>$truncated);
	}

	/** Rehydrate explicit draft selections even when they fall outside a search page. */
	public function getPaymentBatchSelectedCandidates($stagingId, $ids)
	{
		$this->assertPaymentBatchActor(true);
		$ids = $this->paymentBatchIds($ids, false);
		$source = $this->paymentBatchSource($stagingId);
		$rows = array(); $unavailable = array();
		foreach ($ids as $id) {
			try { $rows[] = $this->paymentBatchComponent($id, $source); }
			catch (DomainException $error) { $unavailable[] = $id; }
		}
		// Database failures propagate: a failed lookup is never evidence of absence.
		return array('rows'=>$rows, 'unavailable'=>$unavailable);
	}

	/** Explicit text search spans all dates; results still use the shared native provider/matcher. */
	public function searchWorklistNativeDocuments($line, $search)
	{
		$this->assertPaymentBatchActor(true);
		if (!is_array($line) || (int) ($line['rowid'] ?? 0) <= 0 || !is_string($search) || strlen($search) > 256) { throw new DomainException('Invalid native movement search'); }
		$source = !empty($line['fk_statement']) ? 'staged' : 'native'; $id = (int) $line['rowid'];
		$fresh = $this->getBulkReviewLine($id, $source);
		if (!$fresh || (int) $fresh['status'] !== 0 || (int) ($fresh['is_duplicate'] ?? 0)) { throw new DomainException('Statement source changed; reload the current row'); }
		(new KreaBankReconciliationScope($this->db, $this->entity))->assertLine($fresh);
		$p = $this->db->prefix(); $e = (int) $this->entity; $account = (int) $fresh['bank_account_id'];
		$raw = $source === 'staged'
			? $this->paymentBatchRows("SELECT l.amount,l.currency FROM {$p}kreabank_statement_line l JOIN {$p}kreabank_statement s ON s.rowid=l.fk_statement AND s.entity=l.entity WHERE l.entity=$e AND l.rowid=$id AND s.bank_account_id=$account")
			: $this->paymentBatchRows("SELECT CAST(b.amount AS DECIMAL(24,8)) amount,ba.currency_code currency FROM {$p}bank b JOIN {$p}bank_account ba ON ba.rowid=b.fk_account WHERE ba.entity=$e AND b.rowid=$id AND ba.rowid=$account");
		if (count($raw) !== 1) { throw new DomainException('Statement source changed; reload the current row'); }
		$amount = KreaBankPaymentBatchDecimal::normalize($raw[0]['amount']);
		if ($amount === '0.00000000') { throw new DomainException('Cannot search a zero-amount statement'); }
		$fresh['amount_decimal'] = $amount;
		$sql = "SELECT b.rowid FROM {$p}bank b JOIN {$p}bank_account ba ON ba.rowid=b.fk_account WHERE ba.entity=$e AND ba.rowid=$account"
			. ' AND CAST(b.amount AS DECIMAL(24,8))=' . $amount . ' AND ba.currency_code=' . $this->sqlText($raw[0]['currency'])
			. " AND ba.clos=0 AND ba.rappro=1 AND b.rappro=0"
			. " AND NOT EXISTS (SELECT 1 FROM {$p}kreabank_bankmeta m WHERE m.fk_bank_line=b.rowid AND COALESCE(m.source_type,'')<>'')"
			. " AND NOT EXISTS (SELECT 1 FROM {$p}kreabank_statement_line m WHERE m.fk_native_bank_line=b.rowid)"
			. $this->paymentBatchOwnershipSql(false);
		if ($this->tableExists($p . 'kreabank_payment_batch_line')) { $sql .= " AND NOT EXISTS (SELECT 1 FROM {$p}kreabank_payment_batch_line m WHERE m.active_bank_line=b.rowid)"; }
		$nativeSourceId = (int) ($fresh['native_bank_line_id'] ?? ($source === 'native' ? $id : 0));
		if ($nativeSourceId > 0) { $sql .= ' AND b.rowid<>' . $nativeSourceId; }
		foreach (preg_split('/\s+/u', trim($search), -1, PREG_SPLIT_NO_EMPTY) as $token) {
			$needle = str_replace(array('!', '%', '_'), array('!!', '!%', '!_'), $token);
			$like = $this->sqlText('%' . $needle . '%');
			$condition = "CONCAT_WS(' ',b.label,b.note,b.num_chq,ba.ref,DATE_FORMAT(b.dateo,'%Y-%m-%d'),DATE_FORMAT(b.dateo,'%d/%m/%Y'),CAST(b.amount AS CHAR)) LIKE $like ESCAPE '!'"
				. " OR EXISTS (SELECT 1 FROM {$p}bank_url u WHERE u.fk_bank=b.rowid AND u.label LIKE $like ESCAPE '!')";
			foreach (array(array('paiement','paiement_facture','facture','fk_paiement','fk_facture'),array('paiementfourn','paiementfourn_facturefourn','facture_fourn','fk_paiementfourn','fk_facturefourn')) as $tables) {
				list($pay,$alloc,$invoice,$paymentKey,$invoiceKey) = $tables;
				$extra = $pay === 'paiementfourn' ? ',f.ref_supplier' : '';
				$condition .= " OR EXISTS (SELECT 1 FROM {$p}{$pay} pay LEFT JOIN {$p}{$alloc} a ON a.{$paymentKey}=pay.rowid"
					. " LEFT JOIN {$p}{$invoice} f ON f.rowid=a.{$invoiceKey} AND f.entity=pay.entity LEFT JOIN {$p}societe soc ON soc.rowid=f.fk_soc"
					. " WHERE pay.entity=$e AND pay.fk_bank=b.rowid AND CONCAT_WS(' ',pay.ref,pay.num_paiement,f.ref,soc.nom{$extra}) LIKE $like ESCAPE '!')";
			}
			$sql .= ' AND (' . $condition . ')';
		}
		$sql .= ' ORDER BY ABS(DATEDIFF(b.dateo,' . $this->sqlText($fresh['operation_date']) . ')),b.dateo,b.rowid' . $this->db->plimit(101);
		$matches = $this->paymentBatchRows($sql); $truncated = count($matches) > 100; $documents = array();
		foreach (array_slice($matches, 0, 100) as $match) {
			$documentsForId = $this->getOpenNativeBankDocuments((int) $fresh['direction'], 20, null, 0, $nativeSourceId, $account, (int) $match['rowid']);
			foreach ($documentsForId as $document) {
				try { (new KreaBankReconciliationScope($this->db, $this->entity))->assertDocument('native_bank', (int) $document['rowid'], $account); }
				catch (DomainException $error) { continue; }
				$documents[] = $document;
			}
		}
		$documents = (new KreaBankTaxReconciliation($this->db, $this->user, $this->entity))->decorateMovements($documents);
		$tolerance = max(1, (int) getDolGlobalInt('KREABANK_AUTOMATCH_DATE_TOLERANCE', 3));
		return array('line'=>$fresh, 'suggestions'=>$this->matcher->getSuggestions($fresh,$documents,$this->getPatterns(),$tolerance,0), 'truncated'=>$truncated);
	}

	/** A draft belongs to this user; active reconciliation belongs to the company. */
	public function loadPaymentBatch($stagingId)
	{
		$this->assertPaymentBatchActor(true);
		$source = $this->paymentBatchSource($stagingId, true);
		$p = $this->db->prefix(); $id = (int) $stagingId;
		$active = $this->paymentBatchRows("SELECT * FROM {$p}kreabank_payment_batch WHERE entity=" . $this->entity . " AND active_statement_line=$id AND status=1");
		$group = $active ? $active[0] : null;
		if ($group) {
			$group['components'] = $this->paymentBatchRows("SELECT m.*,b.fk_account AS bank_account_id,ba.ref AS bank_account_ref,b.label AS description,b.dateo AS doc_date,b.num_chq AS ref,b.rappro,b.num_releve FROM {$p}kreabank_payment_batch_line m"
				. " JOIN {$p}bank b ON b.rowid=m.fk_bank_line JOIN {$p}bank_account ba ON ba.rowid=b.fk_account AND ba.entity=m.entity"
				. ' WHERE m.entity=' . $this->entity . ' AND m.fk_batch=' . (int) $group['rowid'] . ' ORDER BY m.fk_bank_line');
		}
		$drafts = $this->paymentBatchRows("SELECT name,ids_json FROM {$p}kreabank_payment_batch_draft WHERE entity=" . $this->entity . ' AND fk_user=' . (int) $this->user->id . " AND fk_statement_line=$id");
		$draft = $drafts ? array('name'=>$drafts[0]['name'], 'ids'=>json_decode($drafts[0]['ids_json'], true)) : null;
		return array('source'=>$source, 'active'=>$group, 'draft'=>$draft);
	}

	public function savePaymentBatchDraft($stagingId, $ids, $name)
	{
		$this->assertPaymentBatchActor();
		$ids = $this->paymentBatchIds($ids, false); $name = $this->paymentBatchName($name);
		$source = $this->paymentBatchSource($stagingId);
		foreach ($ids as $id) { $this->paymentBatchComponent($id, $source); }
		$p = $this->db->prefix();
		$this->paymentBatchQuery("INSERT INTO {$p}kreabank_payment_batch_draft (entity,fk_user,fk_statement_line,name,ids_json,datec) VALUES ("
			. $this->entity . ',' . (int) $this->user->id . ',' . (int) $stagingId . ',' . $this->sqlText($name) . ',' . $this->sqlText(json_encode($ids)) . ',NOW())'
			. ' ON DUPLICATE KEY UPDATE name=VALUES(name),ids_json=VALUES(ids_json)');
		return array('name'=>$name, 'ids'=>$ids);
	}

	public function preparePaymentBatchReview($stagingId, $ids, $name)
	{
		$this->assertPaymentBatchActor();
		$ids = $this->paymentBatchIds($ids, true);
		$source = $this->paymentBatchSource($stagingId);
		$this->paymentBatchAssertAccountReview($source);
		$components = array();
		foreach ($ids as $id) { $components[] = $this->paymentBatchComponent($id, $source); }
		$total = KreaBankPaymentBatchDecimal::sum(array_column($components, 'amount'));
		if ($total !== $source['amount']) { throw new DomainException('Selected payment total must equal the exact statement amount'); }
		return $this->paymentBatchSign(array('action'=>'reconcile','staging_id'=>(int) $stagingId,'ids'=>$ids,
			'name'=>$this->paymentBatchName($name),'source'=>$source,'components'=>$components,'amount'=>$total));
	}

	/** Native conciliation, normalized mappings, source completion and audit are one transaction. */
	public function reconcilePaymentBatch($review)
	{
		$this->assertPaymentBatchActor();
		$this->paymentBatchVerify($review, 'reconcile');
		$ids = $this->paymentBatchIds($review['ids'], true);
		$id = (int) $review['staging_id']; $p = $this->db->prefix();
		if (!$this->db->begin()) { throw new RuntimeException('Cannot start payment batch reconciliation'); }
		try {
			$this->paymentBatchLock($review['source'], $ids);
			$source = $this->paymentBatchSource($id, false, true);
			$this->paymentBatchAssertAccountReview($source);
			$components = array();
			foreach ($ids as $bankId) { $components[] = $this->paymentBatchComponent($bankId, $source, false, true); }
			if ($source !== $review['source'] || $components !== $review['components']
				|| KreaBankPaymentBatchDecimal::sum(array_column($components, 'amount')) !== $source['amount']) {
				throw new DomainException('The statement or selected payments changed; review them again');
			}
			$this->paymentBatchQuery("INSERT INTO {$p}kreabank_payment_batch (entity,fk_statement_line,active_statement_line,bank_account_id,amount,currency,operation_date,statement_ref,name,status,source_snapshot,fk_user,datec) VALUES ("
				. $this->entity . ",$id,$id," . (int) $source['bank_account_id'] . ',' . $source['amount'] . ',' . $this->sqlText($source['currency']) . ',' . $this->sqlText($source['operation_date']) . ',' . $this->sqlText($source['statement_ref']) . ',' . $this->sqlText($review['name']) . ',1,' . $this->sqlText(json_encode($source)) . ',' . (int) $this->user->id . ',NOW())');
			$batchId = (int) $this->db->last_insert_id($p . 'kreabank_payment_batch');
			if ($batchId <= 0) { throw new RuntimeException('Cannot identify payment batch'); }
			foreach ($components as $component) {
				$bankId = (int) $component['rowid'];
				$this->paymentBatchQuery("INSERT INTO {$p}kreabank_payment_batch_line (entity,fk_batch,fk_bank_line,active_bank_line,amount,snapshot_json) VALUES ("
					. $this->entity . ",$batchId,$bankId,$bankId," . $component['amount'] . ',' . $this->sqlText(json_encode($component)) . ')');
				if ($this->native->conciliateLine($bankId, $source['statement_ref'], 0) <= 0) { throw new RuntimeException('Cannot reconcile a payment batch component'); }
			}
			$this->paymentBatchQuery("UPDATE {$p}kreabank_statement_line SET status=2,allocated_amount=ABS(amount),fk_native_bank_line=NULL WHERE entity=" . $this->entity . " AND rowid=$id AND status=0 AND allocated_amount=0 AND fk_native_bank_line IS NULL");
			$after = $this->paymentBatchSource($id, true);
			if ((int) $after['status'] !== 2 || $after['allocated_amount'] !== KreaBankPaymentBatchDecimal::absolute($source['amount'])) { throw new RuntimeException('Cannot complete payment batch source'); }
			$this->paymentBatchQuery("DELETE FROM {$p}kreabank_payment_batch_draft WHERE entity=" . $this->entity . " AND fk_statement_line=$id AND fk_user=" . (int) $this->user->id);
			if (!$this->logAudit('reconcile', $id, $batchId, array('strategy'=>'manual_payment_batch','source'=>'staged','batch_id'=>$batchId,'bank_ids'=>$ids,'amount'=>$source['amount'],'name'=>$review['name']))) {
				throw new RuntimeException('Cannot persist payment batch audit');
			}
			if (!$this->db->commit()) { throw new RuntimeException('Cannot commit payment batch; inspect current status before retrying'); }
			return $batchId;
		} catch (Throwable $e) { $this->db->rollback(); throw $e; }
	}

	public function preparePaymentBatchUndoReview($stagingId)
	{
		$this->assertPaymentBatchUndoActor();
		$loaded = $this->loadPaymentBatch($stagingId);
		if (!$loaded['active']) { throw new DomainException('No active payment batch'); }
		$group = $loaded['active']; $source = $loaded['source'];
		$this->paymentBatchAssertActiveSource($source, $group);
		$ids = $this->paymentBatchIds(array_column($group['components'], 'fk_bank_line'), true);
		$components = array();
		foreach ($ids as $id) { $components[] = $this->paymentBatchComponent($id, $source, (int) $group['rowid']); }
		return $this->paymentBatchSign(array('action'=>'undo','staging_id'=>(int) $stagingId,'batch_id'=>(int) $group['rowid'],
			'ids'=>$ids,'source'=>$source,'components'=>$components,'amount'=>$source['amount']));
	}

	/** Undo only conciliation and active mapping; original payment records remain untouched. */
	public function undoPaymentBatch($stagingId, $review)
	{
		$this->assertPaymentBatchUndoActor(); $this->paymentBatchVerify($review, 'undo');
		if ((int) $stagingId !== (int) $review['staging_id']) { throw new DomainException('Payment batch source changed'); }
		$ids = $this->paymentBatchIds($review['ids'], true); $p = $this->db->prefix(); $id = (int) $stagingId;
		if (!$this->db->begin()) { throw new RuntimeException('Cannot start payment batch undo'); }
		try {
			$this->paymentBatchLock($review['source'], $ids);
			$loaded = $this->loadPaymentBatch($id); $source = $this->paymentBatchSource($id, true, true); $group = $loaded['active'];
			if (!$group || (int) $group['rowid'] !== (int) $review['batch_id']
				|| $source !== $review['source'] || $this->paymentBatchIds(array_column($group['components'], 'fk_bank_line'), true) !== $ids) {
				throw new DomainException('The reconciled payment batch changed; review it again');
			}
			$this->paymentBatchAssertActiveSource($source, $group);
			$components = array();
			foreach ($ids as $bankId) { $components[] = $this->paymentBatchComponent($bankId, $source, (int) $group['rowid'], true); }
			if ($components !== $review['components'] || KreaBankPaymentBatchDecimal::sum(array_column($components, 'amount')) !== $source['amount']) {
				throw new DomainException('A reconciled payment changed; review the batch again');
			}
			foreach ($ids as $bankId) {
				if ($this->native->unconciliateLine($bankId) <= 0) { throw new RuntimeException('Cannot undo a payment batch component'); }
			}
			$batchId = (int) $group['rowid'];
			$this->paymentBatchQuery("UPDATE {$p}kreabank_payment_batch_line SET active_bank_line=NULL WHERE entity=" . $this->entity . " AND fk_batch=$batchId");
			$this->paymentBatchQuery("UPDATE {$p}kreabank_payment_batch SET active_statement_line=NULL,status=0,fk_user_undo=" . (int) $this->user->id . ",date_undo=NOW() WHERE entity=" . $this->entity . " AND rowid=$batchId AND status=1");
			$this->paymentBatchQuery("UPDATE {$p}kreabank_statement_line SET status=0,allocated_amount=0,fk_native_bank_line=NULL WHERE entity=" . $this->entity . " AND rowid=$id AND status=2");
			if (!$this->logAudit('undo', $id, $batchId, array('strategy'=>'manual_payment_batch','source'=>'staged','batch_id'=>$batchId,'bank_ids'=>$ids))) { throw new RuntimeException('Cannot persist payment batch undo audit'); }
			if (!$this->db->commit()) { throw new RuntimeException('Cannot commit payment batch undo; inspect current status'); }
			return true;
		} catch (Throwable $e) { $this->db->rollback(); throw $e; }
	}

	/** Raw persisted strings are essential: service display projections cast amounts to float. */
	private function paymentBatchSource($stagingId, $allowActive = false, $lock = false)
	{
		$p = $this->db->prefix(); $id = (int) $stagingId;
		$rows = $this->paymentBatchRows("SELECT l.*,s.ref AS statement_ref,s.source_type AS statement_source,s.bank_account_id,s.currency AS statement_currency,ba.ref AS bank_account_ref,ba.currency_code AS bank_currency,ba.clos,ba.rappro,ba.courant"
			. " FROM {$p}kreabank_statement_line l JOIN {$p}kreabank_statement s ON s.rowid=l.fk_statement AND s.entity=l.entity"
			. " JOIN {$p}bank_account ba ON ba.rowid=s.bank_account_id AND ba.entity=s.entity WHERE l.entity=" . $this->entity . " AND l.rowid=$id" . ($lock ? ' FOR UPDATE' : ''));
		if (count($rows) !== 1) { throw new DomainException('Statement must belong to the current company'); }
		$source = $rows[0];
		$source['amount'] = KreaBankPaymentBatchDecimal::normalize($source['amount']);
		$source['allocated_amount'] = KreaBankPaymentBatchDecimal::normalize($source['allocated_amount']);
		$source['feed_facts'] = array();
		if ($source['statement_source'] === 'fintable') {
			if (!$this->tableExists($p . 'kreabank_feed_transaction') || !$this->tableExists($p . 'kreabank_feed_account')) { throw new DomainException('Bank feed source requires current, conflict-free evidence'); }
			$source['feed_facts'] = $this->paymentBatchRows("SELECT ft.rowid,ft.fk_feed_account,ft.source_uid,ft.financial_hash,ft.content_hash,ft.status,ft.conflict_payload,fa.fk_bank_account,fa.entity,fa.currency,fa.status AS account_status"
				. " FROM {$p}kreabank_feed_transaction ft JOIN {$p}kreabank_feed_account fa ON fa.rowid=ft.fk_feed_account AND fa.entity=ft.entity"
				. ' WHERE ft.entity=' . $this->entity . " AND ft.fk_statement_line=$id" . ($lock ? ' FOR UPDATE' : ''));
			require_once __DIR__ . '/KreaBankFintableMapper.class.php';
			$financialHash = hash('sha256', KreaBankFintableMapper::decimal($source['amount']) . '|' . $source['operation_date'] . '|' . $source['currency']);
			if (count($source['feed_facts']) !== 1 || $source['feed_facts'][0]['status'] !== 'imported' || $source['feed_facts'][0]['conflict_payload'] !== null
				|| (int) $source['feed_facts'][0]['fk_bank_account'] !== (int) $source['bank_account_id'] || $source['feed_facts'][0]['currency'] !== $source['currency']
				|| in_array($source['feed_facts'][0]['account_status'], array('mapping_review','unavailable'), true)
				|| $source['feed_facts'][0]['source_uid'] !== $source['line_uid'] || !hash_equals($source['feed_facts'][0]['financial_hash'], $financialHash)) { throw new DomainException('Bank feed source changed or needs conflict review'); }
		}
		if ((int) $source['clos'] || (int) $source['rappro'] !== 1 || (int) $source['courant'] === 2
			|| !in_array((int) $source['status'], $allowActive ? array(0,2) : array(0), true)
			|| (!$allowActive && $source['allocated_amount'] !== '0.00000000') || (int) $source['fk_native_bank_line']
			|| (int) $source['is_duplicate'] || $source['amount'] === '0.00000000'
			|| $source['currency'] !== $source['bank_currency'] || $source['currency'] !== $source['statement_currency']
			|| !preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/D', (string) $source['operation_date'], $dateParts)
			|| !checkdate((int) $dateParts[2], (int) $dateParts[3], (int) $dateParts[1]) || trim((string) $source['statement_ref']) === ''
			|| ((int) $source['direction'] !== 0 && (int) $source['direction'] !== ($source['amount'][0] === '-' ? -1 : 1))
			|| (new KreaBankAccountPairs($this->db, $this->entity))->mainAccount((int) $source['bank_account_id'])) {
			throw new DomainException('Statement is unavailable for grouped payment reconciliation');
		}
		if (!$allowActive && $this->paymentBatchRows("SELECT rowid FROM {$p}kreabank_payment_batch WHERE entity=" . $this->entity . " AND active_statement_line=$id")) {
			throw new DomainException('Statement already has an active payment batch');
		}
		return $source;
	}

	private function paymentBatchAssertActiveSource($source, $group)
	{
		if ((int) $source['status'] !== 2 || $source['allocated_amount'] !== KreaBankPaymentBatchDecimal::absolute($source['amount'])
			|| (int) $group['bank_account_id'] !== (int) $source['bank_account_id'] || KreaBankPaymentBatchDecimal::normalize($group['amount']) !== $source['amount']
			|| $group['currency'] !== $source['currency'] || $group['operation_date'] !== $source['operation_date'] || $group['statement_ref'] !== $source['statement_ref']) {
			throw new DomainException('Reconciled source evidence changed; inspect the batch before undoing it');
		}
	}

	private function paymentBatchAssertAccountReview($source)
	{
		if ($this->getPaymentAccountReview((int) $source['rowid'], array((int) $source['bank_account_id']))) {
			throw new DomainException('Review the suspected payment in another bank account before approving this group');
		}
	}

	/** Disallow unknown/protected links; validate every supported payment's parent ownership. */
	private function paymentBatchOwnershipSql($groupOnly = true)
	{
		$p = $this->db->prefix(); $e = (int) $this->entity;
		$sql = $groupOnly ? " AND NOT EXISTS (SELECT 1 FROM {$p}bank_url u WHERE u.fk_bank=b.rowid AND u.type NOT IN ('payment','payment_supplier','company','payment_salary','user'))" : '';
		foreach (array(array('paiement','paiement_facture','facture','fk_paiement','fk_facture','payment'), array('paiementfourn','paiementfourn_facturefourn','facture_fourn','fk_paiementfourn','fk_facturefourn','payment_supplier')) as $tables) {
			list($pay,$alloc,$invoice,$paymentKey,$invoiceKey,$type) = $tables;
			$sql .= " AND NOT EXISTS (SELECT 1 FROM {$p}{$pay} pay WHERE pay.fk_bank=b.rowid AND (pay.entity<>$e"
				. " OR EXISTS (SELECT 1 FROM {$p}{$alloc} a LEFT JOIN {$p}{$invoice} f ON f.rowid=a.{$invoiceKey} WHERE a.{$paymentKey}=pay.rowid AND (f.rowid IS NULL OR f.entity<>$e))))"
				. " AND NOT EXISTS (SELECT 1 FROM {$p}bank_url u LEFT JOIN {$p}{$pay} pay ON pay.rowid=u.url_id WHERE u.fk_bank=b.rowid AND u.type='$type' AND (pay.rowid IS NULL OR pay.entity<>$e OR pay.fk_bank<>b.rowid))";
		}
		$sql .= " AND NOT EXISTS (SELECT 1 FROM {$p}payment_salary ps LEFT JOIN {$p}salary s ON s.rowid=ps.fk_salary WHERE ps.fk_bank=b.rowid AND (ps.entity<>$e OR s.rowid IS NULL OR s.entity<>$e))"
			. " AND NOT EXISTS (SELECT 1 FROM {$p}bank_url u LEFT JOIN {$p}payment_salary ps ON ps.rowid=u.url_id WHERE u.fk_bank=b.rowid AND u.type='payment_salary' AND (ps.rowid IS NULL OR ps.entity<>$e OR ps.fk_bank<>b.rowid))"
			. ($groupOnly ? " AND NOT EXISTS (SELECT 1 FROM {$p}bank_url u WHERE u.fk_bank=b.rowid AND u.type='user' AND NOT EXISTS (SELECT 1 FROM {$p}payment_salary ps JOIN {$p}salary s ON s.rowid=ps.fk_salary AND s.entity=$e WHERE ps.fk_bank=b.rowid AND ps.entity=$e AND s.fk_user=u.url_id))" : '')
			. KreaBankTaxReconciliation::bankOwnershipSql($p);
		return $sql;
	}

	private function paymentBatchComponent($bankId, $source, $activeBatchId = false, $lock = false)
	{
		$p = $this->db->prefix(); $bankId = (int) $bankId;
		$sql = "SELECT b.*,CAST(b.amount AS DECIMAL(24,8)) AS exact_amount,ba.currency_code AS currency,ba.ref AS bank_account_ref FROM {$p}bank b JOIN {$p}bank_account ba ON ba.rowid=b.fk_account"
			. ' WHERE ba.entity=' . $this->entity . " AND b.rowid=$bankId AND b.fk_account=" . (int) $source['bank_account_id']
			. ' AND b.rappro=' . ($activeBatchId ? '1' : '0') . " AND COALESCE(b.fk_bordereau,0)=0 AND COALESCE(b.fk_type,'')<>'SOLD'"
			. " AND NOT EXISTS (SELECT 1 FROM {$p}kreabank_bankmeta m WHERE m.fk_bank_line=b.rowid)"
			. " AND NOT EXISTS (SELECT 1 FROM {$p}kreabank_statement_line m WHERE m.fk_native_bank_line=b.rowid)"
			. ($activeBatchId ? " AND EXISTS (SELECT 1 FROM {$p}kreabank_payment_batch_line m WHERE m.entity=" . $this->entity . ' AND m.fk_batch=' . (int) $activeBatchId . ' AND m.active_bank_line=b.rowid AND m.amount=CAST(b.amount AS DECIMAL(24,8)))' : " AND NOT EXISTS (SELECT 1 FROM {$p}kreabank_payment_batch_line m WHERE m.active_bank_line=b.rowid)")
			. $this->paymentBatchOwnershipSql() . ($lock ? ' FOR UPDATE' : '');
		$rows = $this->paymentBatchRows($sql);
		if (count($rows) !== 1) { throw new DomainException('A selected payment is unavailable, already claimed or belongs to another account'); }
		$row = $rows[0]; $row['amount'] = KreaBankPaymentBatchDecimal::normalize($row['exact_amount']); unset($row['exact_amount']);
		if ($row['amount'] === '0.00000000' || ($row['amount'][0] === '-') !== ($source['amount'][0] === '-') || $row['currency'] !== $source['currency']
			|| ($activeBatchId && $row['num_releve'] !== $source['statement_ref'])) { throw new DomainException('Selected payment direction, currency or statement reference changed'); }
		$facts = array('links'=>$this->paymentBatchRows("SELECT * FROM {$p}bank_url WHERE fk_bank=$bankId ORDER BY rowid" . ($lock ? ' FOR UPDATE' : '')));
		// Lock complete payment and allocation records so edits cannot race displayed review.
		foreach (array(array('paiement','paiement_facture','facture','fk_paiement','fk_facture'), array('paiementfourn','paiementfourn_facturefourn','facture_fourn','fk_paiementfourn','fk_facturefourn')) as $tables) {
			list($pay,$alloc,$invoice,$paymentKey,$invoiceKey) = $tables;
			$facts[$pay] = $this->paymentBatchRows("SELECT * FROM {$p}{$pay} WHERE fk_bank=$bankId ORDER BY rowid" . ($lock ? ' FOR UPDATE' : ''));
			$facts[$alloc] = $this->paymentBatchRows("SELECT a.* FROM {$p}{$alloc} a JOIN {$p}{$pay} p ON p.rowid=a.{$paymentKey} WHERE p.fk_bank=$bankId ORDER BY a.rowid" . ($lock ? ' FOR UPDATE' : ''));
			$facts[$invoice] = $this->paymentBatchRows("SELECT DISTINCT f.* FROM {$p}{$invoice} f JOIN {$p}{$alloc} a ON a.{$invoiceKey}=f.rowid JOIN {$p}{$pay} p ON p.rowid=a.{$paymentKey} WHERE p.fk_bank=$bankId ORDER BY f.rowid" . ($lock ? ' FOR UPDATE' : ''));
		}
		$facts['payment_salary'] = $this->paymentBatchRows("SELECT * FROM {$p}payment_salary WHERE fk_bank=$bankId ORDER BY rowid" . ($lock ? ' FOR UPDATE' : ''));
		$facts['salary'] = $this->paymentBatchRows("SELECT DISTINCT s.* FROM {$p}salary s JOIN {$p}payment_salary ps ON ps.fk_salary=s.rowid WHERE ps.fk_bank=$bankId ORDER BY s.rowid" . ($lock ? ' FOR UPDATE' : ''));
		$facts['paiementcharge'] = $this->paymentBatchRows("SELECT * FROM {$p}paiementcharge WHERE fk_bank=$bankId ORDER BY rowid" . ($lock ? ' FOR UPDATE' : ''));
		$facts['chargesociales'] = $this->paymentBatchRows("SELECT DISTINCT t.* FROM {$p}chargesociales t JOIN {$p}paiementcharge pc ON pc.fk_charge=t.rowid WHERE pc.fk_bank=$bankId ORDER BY t.rowid" . ($lock ? ' FOR UPDATE' : ''));
		// The signed evidence detects edits without exposing complete payroll/invoice records.
		$row['payment_facts_hash'] = hash('sha256', $this->paymentBatchJson($facts));
		$row['description'] = $row['label']; $row['ref'] = $row['num_chq']; $row['doc_date'] = $row['dateo'];
		$row['bank_account_id'] = (int) $row['fk_account']; $row['amount_decimal'] = $row['amount'];
		$row['id'] = (int) $row['rowid'];
		$row['payment_refs'] = implode(' / ', array_unique(array_filter(array_column($facts['links'], 'label'))));
		$row['url_types'] = implode(',', array_unique(array_column($facts['links'], 'type')));
		return $row;
	}

	private function paymentBatchIds($ids, $requireGroup)
	{
		if (!is_array($ids) || count($ids) > 500 || ($requireGroup && count($ids) < 2)) { throw new DomainException('Select between two and 500 payment movements'); }
		$out = array();
		foreach ($ids as $id) {
			if ((!is_int($id) && !is_string($id)) || !preg_match('/^[1-9][0-9]{0,9}$/D', (string) $id) || isset($out[(int) $id])) { throw new DomainException('Invalid or duplicate payment movement'); }
			$out[(int) $id] = (int) $id;
		}
		sort($out, SORT_NUMERIC); return array_values($out);
	}

	private function paymentBatchName($name)
	{
		$name = trim((string) $name);
		if ($name === '' || mb_strlen($name, 'UTF-8') > 120 || preg_match('/[\x00-\x1f\x7f]/', $name)) { throw new DomainException('Enter a payment batch name of at most 120 characters'); }
		return $name;
	}

	private function paymentBatchSign($review)
	{
		if (empty($_SESSION['kreabank_payment_batch_secret'])) { $_SESSION['kreabank_payment_batch_secret'] = bin2hex(random_bytes(32)); }
		$review['entity'] = (int) $this->entity; $review['user'] = (int) $this->user->id;
		$review['expires'] = time() + 900; $review['nonce'] = bin2hex(random_bytes(24));
		$review['signature'] = hash_hmac('sha256', $this->paymentBatchJson($review), $_SESSION['kreabank_payment_batch_secret']);
		$_SESSION['kreabank_payment_batch_reviews'][$review['nonce']] = array('expires'=>$review['expires'],'signature'=>$review['signature']);
		foreach ($_SESSION['kreabank_payment_batch_reviews'] as $nonce=>$saved) { if ($saved['expires'] < time()) { unset($_SESSION['kreabank_payment_batch_reviews'][$nonce]); } }
		while (count($_SESSION['kreabank_payment_batch_reviews']) > 30) { array_shift($_SESSION['kreabank_payment_batch_reviews']); }
		return $review;
	}

	private function paymentBatchVerify($review, $action)
	{
		if (!is_array($review)) { throw new DomainException('Payment batch review is missing'); }
		$signature = $review['signature'] ?? ''; $nonce = $review['nonce'] ?? '';
		$saved = $_SESSION['kreabank_payment_batch_reviews'][$nonce] ?? null;
		unset($_SESSION['kreabank_payment_batch_reviews'][$nonce]);
		unset($review['signature']);
		if (!$saved || ($review['action'] ?? '') !== $action || ($review['entity'] ?? 0) !== (int) $this->entity || ($review['user'] ?? 0) !== (int) $this->user->id
			|| ($review['expires'] ?? 0) < time() || ($review['expires'] ?? PHP_INT_MAX) > time() + 900
			|| !is_string($signature) || !preg_match('/^[a-f0-9]{64}$/D', $signature)
			|| !hash_equals($saved['signature'], $signature)
			|| !hash_equals(hash_hmac('sha256', $this->paymentBatchJson($review), $_SESSION['kreabank_payment_batch_secret'] ?? ''), $signature)) {
			throw new DomainException('Payment batch review changed or expired; review the current payments');
		}
	}

	private function paymentBatchLock($source, $ids)
	{
		$p = $this->db->prefix();
		$this->paymentBatchRows("SELECT rowid FROM {$p}bank_account WHERE entity=" . $this->entity . ' AND rowid=' . (int) $source['bank_account_id'] . ' FOR UPDATE');
		$this->lockReconciliationRow('kreabank_statement_line', (int) $source['rowid']);
		foreach ($ids as $id) { $this->lockReconciliationRow('bank', $id); }
		$nativeIds = implode(',', $ids);
		$this->paymentBatchRows("SELECT rowid FROM {$p}bank_url WHERE fk_bank IN ($nativeIds) ORDER BY rowid FOR UPDATE");
		// Lock each related table globally in ID order, including parents shared by
		// different-account batches; per-component ordering alone can deadlock.
		foreach (array('paiement','paiementfourn','payment_salary','paiementcharge') as $table) {
			$this->paymentBatchRows("SELECT rowid FROM {$p}{$table} WHERE fk_bank IN ($nativeIds) ORDER BY rowid FOR UPDATE");
		}
		foreach (array(array('paiement','paiement_facture','facture','fk_paiement','fk_facture'),array('paiementfourn','paiementfourn_facturefourn','facture_fourn','fk_paiementfourn','fk_facturefourn')) as $tables) {
			list($pay,$alloc,$invoice,$paymentKey,$invoiceKey) = $tables;
			$this->paymentBatchRows("SELECT a.rowid FROM {$p}{$alloc} a JOIN {$p}{$pay} p ON p.rowid=a.{$paymentKey} WHERE p.fk_bank IN ($nativeIds) ORDER BY a.rowid FOR UPDATE");
			$this->paymentBatchRows("SELECT DISTINCT f.rowid FROM {$p}{$invoice} f JOIN {$p}{$alloc} a ON a.{$invoiceKey}=f.rowid JOIN {$p}{$pay} p ON p.rowid=a.{$paymentKey} WHERE p.fk_bank IN ($nativeIds) ORDER BY f.rowid FOR UPDATE");
		}
		$this->paymentBatchRows("SELECT DISTINCT s.rowid FROM {$p}salary s JOIN {$p}payment_salary ps ON ps.fk_salary=s.rowid WHERE ps.fk_bank IN ($nativeIds) ORDER BY s.rowid FOR UPDATE");
		$this->paymentBatchRows("SELECT DISTINCT t.rowid FROM {$p}chargesociales t JOIN {$p}paiementcharge pc ON pc.fk_charge=t.rowid WHERE pc.fk_bank IN ($nativeIds) ORDER BY t.rowid FOR UPDATE");
		$this->paymentBatchRows("SELECT rowid FROM {$p}kreabank_payment_batch WHERE entity=" . $this->entity . ' AND fk_statement_line=' . (int) $source['rowid'] . ' ORDER BY rowid FOR UPDATE');
	}

	private function paymentBatchJson($value)
	{
		$json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		if ($json === false) { throw new DomainException('Cannot encode payment batch review'); }
		return $json;
	}

	private function paymentBatchRows($sql)
	{
		$result = $this->paymentBatchQuery($sql); $rows = array();
		while ($row = $this->db->fetch_object($result)) { $rows[] = (array) $row; }
		return $rows;
	}

	private function paymentBatchQuery($sql)
	{
		$result = $this->db->query($sql);
		if (!$result) { throw new RuntimeException('Cannot read or save the payment batch; check the module schema and database'); }
		return $result;
	}

	private function assertPaymentBatchActor($readOnly = false)
	{
		$this->assertAutomaticActor(true);
		if (!$readOnly && (!$this->user->hasRight('kreabank', 'reconciliation', 'write')
			|| (!$this->user->hasRight('banque', 'modifier') && !$this->user->hasRight('banque', 'consolidate')))) {
			throw new DomainException('Native bank and module reconciliation write permissions are required');
		}
	}

	private function assertPaymentBatchUndoActor()
	{
		$this->assertPaymentBatchActor();
		if (!$this->user->hasRight('kreabank', 'reconciliation', 'undo')) { throw new DomainException('Payment batch undo permission is required'); }
	}
}
