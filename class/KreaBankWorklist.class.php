<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
require_once __DIR__ . '/kreabankintegration.class.php';
require_once __DIR__ . '/../lib/kreabank_worklist.lib.php';
require_once __DIR__ . '/../lib/kreabank_manual_review.lib.php';
require_once __DIR__ . '/../lib/kreabank_integration.lib.php';

/** Read/preparation adapter for the separate list; execution stays in KreaBankService. */
class KreaBankWorklist
{
	const MAX_ROWS = 20000;
	private $db;
	private $entity;
	private $service;
	private $callbacks;
	private $batches;
	private $sourceRows = array();
	private $searchTruncated = false;

	public function __construct($db, $entity, $service)
	{
		$this->db = $db; $this->entity = (int) $entity; $this->service = $service;
		if ($this->entity <= 0) { throw new InvalidArgumentException('Company context required'); }
		$this->callbacks = kreabankManualReviewCallbacks($service, max(1, (int) getDolGlobalInt('KREABANK_AUTOMATCH_DATE_TOLERANCE', 3)), max(1, (int) getDolGlobalInt('KREABANK_BULKMATCH_MIN_SCORE', 100)), false);
		$this->batches = true;
		foreach (array('kreabank_payment_batch','kreabank_payment_batch_line','kreabank_payment_batch_draft') as $table) {
			$result = $db->query("SHOW TABLES LIKE '" . $db->escape($db->prefix() . $table) . "'");
			if (!$result) { throw new RuntimeException('KreaBankWorklistReadFailed'); }
			if (!$db->fetch_object($result)) { $this->batches = false; }
		}
	}

	public function batchesAvailable() { return $this->batches; }
	public function searchTruncated() { return $this->searchTruncated; }

	public function accounts()
	{
		return $this->rows('SELECT rowid id,ref,currency_code,clos,rappro,courant FROM ' . $this->db->prefix() . 'bank_account WHERE entity=' . $this->entity . ' AND courant<>2 ORDER BY clos,ref,rowid');
	}

	/** Combined imported generations. SQL filters precede the bounded complete scan. */
	public function sources($state)
	{
		$f = $state['filters']; $projection = new KreaBankIntegration($this->db, $this->entity);
		$where = ' WHERE h.is_duplicate=0 AND ba.courant<>2';
		if ($f['account'] !== '') { $where .= ' AND h.bank_account_id=' . (int) $f['account']; }
		if ($f['date_from'] !== '') { $where .= ' AND h.operation_date>=' . $this->quote($f['date_from']); }
		if ($f['date_to'] !== '') { $where .= ' AND h.operation_date<' . 'DATE_ADD(' . $this->quote($f['date_to']) . ',INTERVAL 1 DAY)'; }
		if ($f['description'] !== '') {
			$like = $this->quote('%' . strtr($f['description'], array('!'=>'!!','%'=>'!%','_'=>'!_')) . '%');
			$where .= " AND CONCAT_WS(' ',h.description,h.payment_reference,h.bank_reference) LIKE $like ESCAPE '!'";
		}
		if ($f['amount'] !== '') {
			preg_match('/^(>=|<=|<>|>|<|=)?\s*([+-]?\d+(?:[.,]\d{1,8})?)$/D', $f['amount'], $m);
			$where .= ' AND h.amount' . ($m[1] ?: '=') . str_replace(',', '.', $m[2]);
		}
		if (in_array($f['status'], array('pending','draft'), true)) { $where .= ' AND h.status IN (0,1) AND COALESCE(h.rappro,0)=0 AND ba.clos=0 AND ba.rappro=1'; }
		elseif ($f['status'] === 'reconciled') { $where .= " AND (h.status=2 OR (h.source_storage='legacy_native' AND h.rappro=1))"; }
		$sql = 'SELECT h.* FROM (' . $projection->bankStatementSql() . ') h JOIN ' . $this->db->prefix()
			. 'bank_account ba ON ba.rowid=h.bank_account_id AND ba.entity=' . $this->entity . $where
			. ' ORDER BY h.operation_date,h.bank_account_id,h.statement_id,h.line_rank,h.rowid,h.source_storage';
		$rows = $this->rows($sql . $this->db->plimit(self::MAX_ROWS + 1));
		if (count($rows) > self::MAX_ROWS) { throw new RuntimeException('KreaBankWorklistRefineFilters'); }
		$out = array();
		foreach ($rows as $row) {
			$key = ($row['source_storage'] === 'staging' ? 'staged:' : 'native:') . (int) $row['rowid'];
			$out[$key] = $row;
		}
		$this->sourceRows = $out;
		return $out;
	}

	public function load($key)
	{
		list($source, $id) = kreabankWorklistSource($key);
		$raw = isset($this->sourceRows[$key]) ? array($this->sourceRows[$key]) : array();
		if (!$raw) {
			$projection = new KreaBankIntegration($this->db,$this->entity);
			$raw = $this->rows('SELECT h.* FROM (' . $projection->bankStatementSql() . ') h WHERE h.rowid=' . $id . " AND h.source_storage='" . ($source === 'staged' ? 'staging' : 'legacy_native') . "'");
		}
		if (count($raw) !== 1) { throw new RuntimeException('KreaBankWorklistChanged'); }
		$line = $this->service->getBulkReviewLine($id, $source);
		if (!$line) {
			$line = $raw[0]; $line['fk_statement'] = (int) $line['statement_id']; $line['native_bank_line_id'] = (int) $line['expected_native_id'];
			$line['allocated_amount'] = (int) $line['status'] === 2 ? ltrim((string) $line['amount'],'-') : '0';
		}
		$account = $this->rows('SELECT ref,clos,rappro FROM ' . $this->db->prefix() . 'bank_account WHERE rowid=' . (int) $line['bank_account_id'] . ' AND entity=' . $this->entity);
		if (!$account) { throw new RuntimeException('KreaBankWorklistChanged'); }
		$line['bank_account_ref'] = $account[0]['ref'];
		$line['account_open'] = !(int) $account[0]['clos'] && (int) $account[0]['rappro'] === 1;
		$line['amount_decimal'] = (string) $raw[0]['amount'];
		foreach (array('group_id','group_count','group_valid','group_date') as $field) { $line[$field] = $raw[0][$field] ?? null; }
		$line['needs_review'] = kreabankIntegrationLineState((object) $raw[0],$raw[0]['statement_ref']) === 'KreaBankLinkNeedsReview';
		return $line;
	}

	/** Compact index retains no executable approval; visible pairs are signed afresh. */
	public function compact($key)
	{
		$row = $this->prepare($key);
		$references = array(); $accounts = array(); $accountRefs = array();
		if (!empty($row['pair'])) {
			$s = $row['pair']['suggestion'];
			$references[] = kreabankBulkSuggestionReference($s) . ' ' . ($s['thirdparty_name'] ?? '');
			$account = kreabankWorklistPaymentAccount($row['pair']);
			if ($account['id'] > 0) { $accounts[] = $account['id']; $accountRefs[] = $account['ref']; }
		}
		foreach ($row['account_candidates'] as $candidate) {
			$references[] = ($candidate['payment_ref'] ?? $candidate['reference'] ?? '') . ' ' . ($candidate['reference'] ?? '');
			$accounts[] = (int) $candidate['bank_account_id']; $accountRefs[] = $candidate['account_ref'];
		}
		if (!empty($row['batch']['active'])) {
			$references[] = $row['batch']['active']['name'];
			foreach ($row['batch']['active']['components'] as $c) { $references[] = ($c['ref'] ?? '') . ' ' . ($c['description'] ?? ''); $accounts[] = (int) $c['bank_account_id']; $accountRefs[] = $c['bank_account_ref']; }
		}
		if (!empty($row['batch']['draft'])) {
			$references[] = $row['batch']['draft']['name'];
			foreach ($row['batch']['draft']['accounts'] as $a) { $accounts[] = (int) $a['id']; $accountRefs[] = $a['ref']; }
		}
		if (!empty($row['completed'])) { $references[] = $row['completed']['reference']; $accounts[] = (int) $row['line']['bank_account_id']; $accountRefs[] = $row['line']['bank_account_ref']; }
		$status = $row['line']['needs_review'] ? 'review' : ((int) $row['line']['status'] === 2 ? 'reconciled' : (!empty($row['batch']['draft']) ? 'draft' : 'pending'));
		return array('key'=>$key,'match'=>implode(' ', $references),'payment_accounts'=>array_values(array_unique($accounts)),
			'payment_account'=>implode(', ', array_unique($accountRefs)),'status'=>$status);
	}

	public function prepare($key)
	{
		$line = $this->load($key);
		$batch = $this->batches && !empty($line['fk_statement']) ? $this->readBatch($line) : array();
		$pair = null; $accountCandidates = array(); $completed = null;
		if ((int) $line['status'] === 0 && $line['account_open'] && empty($batch['active'])) {
			$prepared = ($this->callbacks['prepareReviewRow'])($line);
			if (!empty($prepared['suggestion']['doc_id'])) { $pair = $prepared; }
			$accountCandidates = $prepared['account_review']['candidates'] ?? array();
		}
		if ((int) $line['status'] === 2 && empty($batch['active']) && !empty($line['native_bank_line_id'])) {
			$p = $this->db->prefix();
			$native = $this->rows("SELECT b.rowid,b.label,b.num_chq,b.dateo,CAST(b.amount AS DECIMAL(24,8)) amount FROM {$p}bank b JOIN {$p}bank_account ba ON ba.rowid=b.fk_account AND ba.entity=" . $this->entity
				. ' WHERE b.rowid=' . (int) $line['native_bank_line_id'] . ' AND b.fk_account=' . (int) $line['bank_account_id']);
			if ($native) { $n=$native[0]; $completed = array('doc_type'=>'native_bank','doc_id'=>(int) $n['rowid'],'reference'=>$n['num_chq'] ?: '#' . $n['rowid'],'date'=>$n['dateo'],'amount'=>$n['amount'],'currency'=>$line['currency'],'account_ref'=>$line['bank_account_ref'],'description'=>$n['label']); }
		}
		return array('key'=>$key,'line'=>$line,'pair'=>$pair,'account_candidates'=>$accountCandidates,'batch'=>$batch,'completed'=>$completed);
	}

	/** Viewing drafts/history must not impose the executor's pending/unmapped requirements. */
	private function readBatch($line)
	{
		$p = $this->db->prefix(); $e = $this->entity; $id = (int) $line['rowid'];
		$groups = $this->rows("SELECT * FROM {$p}kreabank_payment_batch WHERE entity=$e AND fk_statement_line=$id AND status=1 AND active_statement_line=$id");
		$group = $groups ? $groups[0] : null;
		if ($group) {
			$group['components'] = $this->rows("SELECT m.*,CAST(b.amount AS DECIMAL(24,8)) current_amount,b.fk_account bank_account_id,ba.ref bank_account_ref,b.label description,b.dateo doc_date,b.num_chq ref,b.rappro,b.num_releve"
				. " FROM {$p}kreabank_payment_batch_line m LEFT JOIN {$p}bank b ON b.rowid=m.fk_bank_line"
				. " LEFT JOIN {$p}bank_account ba ON ba.rowid=b.fk_account AND ba.entity=m.entity"
				. " WHERE m.entity=$e AND m.fk_batch=" . (int) $group['rowid'] . ' AND ba.rowid IS NOT NULL ORDER BY m.fk_bank_line');
		}
		$drafts = $this->rows("SELECT name,ids_json FROM {$p}kreabank_payment_batch_draft WHERE entity=$e AND fk_statement_line=$id AND fk_user=" . (int) $this->service->user->id);
		$draft = $drafts ? array('name'=>$drafts[0]['name'],'ids'=>json_decode($drafts[0]['ids_json'],true),'accounts'=>array()) : null;
		if ($draft && is_array($draft['ids']) && $draft['ids']) {
			$ids = array_filter(array_map('intval',$draft['ids']),static function ($id) { return $id>0; });
			if ($ids) { $draft['accounts'] = $this->rows("SELECT DISTINCT ba.rowid id,ba.ref FROM {$p}bank b JOIN {$p}bank_account ba ON ba.rowid=b.fk_account AND ba.entity=$e WHERE b.rowid IN (" . implode(',',$ids) . ') ORDER BY ba.ref,ba.rowid'); }
		}
		return array('active'=>$group,'draft'=>$draft);
	}

	/** Explicit manual choices use the same per-card eligibility as the existing workspace. */
	public function candidates($line, $search = '')
	{
		$rows = ($this->callbacks['prepareDocumentReviews'])($line);
		$this->searchTruncated = false; $suggestions = array(); $searchedKeys = array();
		if ($search !== '') {
			foreach (array($this->service->searchWorklistNativeDocuments($line,$search),$this->service->searchWorklistOpenDocuments($line,$search)) as $found) {
				$this->searchTruncated = $this->searchTruncated || $found['truncated'];
				$suggestions = array_merge($suggestions,$found['suggestions']);
				foreach ($found['suggestions'] as $suggestion) { $searchedKeys[$suggestion['doc_type'] . '__' . (int) $suggestion['doc_id']] = true; }
			}
		}
		$key = (!empty($line['fk_statement']) ? 'staged:' : 'native:') . (int) $line['rowid'];
		$corrected = $_SESSION['kreabank_worklist_corrected'][$key] ?? null;
		if ($corrected && $corrected['expires'] >= time()) { $suggestions = array_merge($suggestions,$this->service->getWorklistCorrectedSuggestions($line,$corrected['bank_line'])); }
		$accountWarning = !empty($line['fk_statement']) && $this->service->getPaymentAccountReview((int) $line['rowid'],array($line['bank_account_id']));
		foreach ($suggestions as $suggestion) {
			$type = $suggestion['doc_type'];
			if ($accountWarning && $type !== 'native_bank') { continue; }
			$resolved = ($this->callbacks['resolveLineBestSuggestion'])($line,array($suggestion),true);
			if (empty($resolved['suggestion'])) { continue; }
			if ($type === 'native_bank' && ((int) $suggestion['bank_account_id'] !== (int) $line['bank_account_id'] || abs((float) $suggestion['amount_open']-(float) $line['amount'])>0.00000001)) { continue; }
			$row = array('line'=>$line,'suggestion'=>$suggestion,'allocation'=>$resolved['allocation'],'preselected'=>false,'is_bulk_safe'=>false);
			if ($type === 'native_bank') { $row['approval_context']='selected_native'; $row['other_account_warning']=(bool) $accountWarning; }
			$rows[$type . '__' . (int) $suggestion['doc_id']] = $row;
		}
		if ($search === '') { return $rows; }
		return array_filter($rows, static function ($row) use ($search,$searchedKeys) {
			$s = $row['suggestion'];
			if (isset($searchedKeys[$s['doc_type'] . '__' . (int) $s['doc_id']])) { return true; }
			return stripos(implode(' ', array($s['doc_ref'] ?? '',$s['payment_ref'] ?? '',$s['thirdparty_name'] ?? '',$s['doc_date'] ?? '',$s['amount_open'] ?? '')), $search) !== false;
		});
	}

	private function rows($sql)
	{
		$result = $this->db->query($sql); if (!$result) { throw new RuntimeException('KreaBankWorklistReadFailed'); }
		$rows = array(); while ($row = $this->db->fetch_object($result)) { $rows[] = (array) $row; }
		return $rows;
	}
	private function quote($value) { return "'" . $this->db->escape((string) $value) . "'"; }
}
