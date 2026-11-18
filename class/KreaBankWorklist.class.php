<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
require_once __DIR__ . '/kreabankintegration.class.php';
require_once __DIR__ . '/KreaBankPlannedPayment.class.php';
require_once __DIR__ . '/KreaBankSalaryPaymentReview.class.php';
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
	private $customerTransferReviewer;
	private $draftSourceIds;
	private $draftActor = 0;

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
		$rows=$this->rows($this->sourceSql($state).$this->db->plimit(self::MAX_ROWS+1));
		if (count($rows)>self::MAX_ROWS) { throw new RuntimeException('KreaBankWorklistRefineFilters'); }
		$out=$this->classifySources($rows);
		if ($state['filters']['status']!=='all') {
			$out=array_filter($out,static function($row)use($state){
				return $state['filters']['status']==='pending' ? in_array($row['worklist_status'],array('pending','draft'),true)
					: $row['worklist_status']===$state['filters']['status'];
			});
		}
		$this->sourceRows=$out;
		return $out;
	}

	/** Other status tabs are counted from source evidence without running suggestions for those rows. */
	public function statusCounts($state, $summaries = array(), $complete = false)
	{
		$unknown=array_fill_keys(array('pending','reconciled','draft','review','all'),null);
		if (($state['filters']['match']!=='' || $state['filters']['payment_account']!=='') && !$complete) { return $unknown; }
		$rows=$this->rows($this->sourceSql($state,false).$this->db->plimit(self::MAX_ROWS+1));
		if (count($rows)>self::MAX_ROWS) { return $unknown; }
		try { $sources=$this->classifySources($rows); }
		catch (RuntimeException $error) {
			if ($error->getMessage()==='KreaBankWorklistRefineFilters') { return $unknown; }
			throw $error;
		}
		return kreabankWorklistStatusCounts($sources,$state,$summaries,$complete);
	}

	private function sourceSql($state, $includeStatus = true)
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
		if ($includeStatus && in_array($f['status'], array('pending','draft'), true)) { $where .= ' AND h.opening_reconciled=0 AND h.status IN (0,1) AND COALESCE(h.rappro,0)=0 AND ba.clos=0 AND ba.rappro=1'; }
		elseif ($includeStatus && $f['status'] === 'reconciled') { $where .= " AND (h.opening_reconciled=1 OR h.status=2 OR (h.source_storage='legacy_native' AND h.rappro=1))"; }
		return 'SELECT h.*,ba.clos AS account_closed,ba.rappro AS account_reconcilable FROM (' . $projection->bankStatementSql((int)$f['account']) . ') h JOIN ' . $this->db->prefix()
			. 'bank_account ba ON ba.rowid=h.bank_account_id AND ba.entity=' . $this->entity . $where
			. ' ORDER BY h.operation_date,h.bank_account_id,h.statement_id,h.line_rank,h.rowid,h.source_storage';
	}

	private function classifySources($rows)
	{
		$drafts=$this->draftSourceIds(); $out=array();
		foreach ($rows as $row) {
			$key = ($row['source_storage'] === 'staging' ? 'staged:' : 'native:') . (int) $row['rowid'];
			$row['worklist_status']=kreabankWorklistProjectionStatus($row,$row['source_storage']==='staging' && isset($drafts[(int)$row['rowid']]));
			$out[$key] = $row;
		}
		return $out;
	}

	/** The native planning table is actor-private; fetch its owned identities only once per request. */
	private function draftSourceIds()
	{
		$actor=(int)($this->service->user->id??0);
		if ($this->draftSourceIds!==null && $this->draftActor===$actor) { return $this->draftSourceIds; }
		$this->draftActor=$actor; $this->draftSourceIds=null;
		if (!$this->batches || $actor<=0) { return $this->draftSourceIds=array(); }
		$p=$this->db->prefix(); $e=$this->entity;
		$rows=$this->rows("SELECT d.fk_statement_line FROM {$p}kreabank_payment_batch_draft d"
			. " JOIN {$p}kreabank_statement_line l ON l.rowid=d.fk_statement_line AND l.entity=d.entity"
			. " JOIN {$p}kreabank_statement s ON s.rowid=l.fk_statement AND s.entity=l.entity"
			. " JOIN {$p}bank_account ba ON ba.rowid=s.bank_account_id AND ba.entity=s.entity"
			. " WHERE d.entity=$e AND d.fk_user=$actor ORDER BY d.fk_statement_line".$this->db->plimit(self::MAX_ROWS+1));
		if (count($rows)>self::MAX_ROWS) { throw new RuntimeException('KreaBankWorklistRefineFilters'); }
		$this->draftSourceIds=array();
		foreach ($rows as $row) { $this->draftSourceIds[(int)$row['fk_statement_line']]=true; }
		return $this->draftSourceIds;
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
		foreach (array('group_id','group_count','group_valid','group_date','opening_reconciled') as $field) { $line[$field] = $raw[0][$field] ?? null; }
		$line['needs_review'] = kreabankIntegrationLineState((object) $raw[0],$raw[0]['statement_ref']) === 'KreaBankLinkNeedsReview';
		$line['worklist_status']=$raw[0]['worklist_status']??kreabankWorklistProjectionStatus($raw[0]);
		return $line;
	}

	/** Targeted visibility checks only; cached presentation never grants financial approval. */
	public function presentationVisible($prepared)
	{
		$p=$this->db->prefix(); $e=$this->entity; $records=array(); $line=$prepared['line'];
		if (!empty($prepared['pair']['suggestion'])) { $records[]=$prepared['pair']['suggestion']; }
		if (!empty($prepared['completed']['doc_id'])) { $records[]=$prepared['completed']+array('bank_account_id'=>$line['bank_account_id']); }
		foreach (($prepared['account_candidates']??array()) as $candidate) {
			$records[]=array('doc_type'=>'native_bank','doc_id'=>$candidate['bank_line_id'],'bank_account_id'=>$candidate['bank_account_id']);
		}
		foreach (($prepared['batch']['active']['components']??array()) as $component) {
			$records[]=array('doc_type'=>'native_bank','doc_id'=>$component['fk_bank_line'],'bank_account_id'=>$component['bank_account_id'],'active_group'=>true);
		}
		$salary=new KreaBankSalaryPaymentReview($this->db,$this->service->user,$e);
		foreach ($records as $record) {
			$type=$record['doc_type']; $id=(int)$record['doc_id']; $account=(int)($record['bank_account_id']??$line['bank_account_id']);
			if (!empty($record['customer_transfer'])) {
				if (!$this->customerTransferReviewer) { $this->customerTransferReviewer = new KreaBankCustomerTransfer($this->db,$this->service->user,$e); }
				$current = $this->customerTransferReviewer->metadataForBankIds(array($id));
				if (($current[$id] ?? null) !== $record['customer_transfer']) { return false; }
			}
			if ($type==='salary') {
				if (!$this->rows("SELECT s.rowid FROM {$p}salary s JOIN {$p}user eu ON eu.rowid=s.fk_user AND eu.entity IN (0,s.entity)"
					. " WHERE s.rowid=$id AND s.entity=$e AND s.paye=0 AND (".$salary->readableSql('s.fk_user').')')) { return false; }
				continue;
			}
			if ($type==='native_bank') {
				$sql="SELECT b.rowid FROM {$p}bank b JOIN {$p}bank_account ba ON ba.rowid=b.fk_account WHERE b.rowid=$id AND ba.entity=$e AND ba.rowid=$account";
				$sql.=KreaBankTaxReconciliation::bankOwnershipSql($p).$salary->bankOwnershipSql().$salary->visibilitySql();
				foreach(array(array('paiement','paiement_facture','facture','fk_paiement','fk_facture'),array('paiementfourn','paiementfourn_facturefourn','facture_fourn','fk_paiementfourn','fk_facturefourn')) as $tables){
					list($payment,$allocation,$invoice,$payKey,$invoiceKey)=$tables;
					$sql.=" AND NOT EXISTS (SELECT 1 FROM {$p}{$payment} pay WHERE pay.fk_bank=b.rowid AND (pay.entity<>$e OR EXISTS (SELECT 1 FROM {$p}{$allocation} a LEFT JOIN {$p}{$invoice} f ON f.rowid=a.$invoiceKey WHERE a.$payKey=pay.rowid AND (f.rowid IS NULL OR f.entity<>$e))))";
				}
				if (!$this->rows($sql)) { return false; }
				continue;
			}
			if ($type==='tax' && !(new KreaBankTaxReconciliation($this->db,$this->service->user,$e))->canRead()) { return false; }
			try { (new KreaBankReconciliationScope($this->db,$e))->assertDocument($type,$id,$account ?: (int)$line['bank_account_id']); }
			catch (Exception $error) { return false; }
		}
		return true;
	}

	/** Compact index plus one transient prepared row; controller bounds full envelopes to the page. */
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
			if (($row['account_review']['evidence'] ?? '') !== 'amount_date') { $references[] = ($candidate['payment_ref'] ?? $candidate['reference'] ?? '') . ' ' . ($candidate['reference'] ?? ''); }
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
		$status = $row['line']['needs_review'] ? 'review' : (((int) $row['line']['status'] === 2 || ($row['line']['worklist_status']??'')==='reconciled') ? 'reconciled' : (!empty($row['batch']['draft']) ? 'draft' : 'pending'));
		return array('key'=>$key,'match'=>implode(' ', $references),'payment_accounts'=>array_values(array_unique($accounts)),
			'payment_account'=>implode(', ', array_unique($accountRefs)),'status'=>$status,
			'dependencies'=>kreabankWorklistDependencies($row),'_prepared'=>$row);
	}

	public function prepare($key)
	{
		$line = $this->load($key);
		$batch = $this->batches && !empty($line['fk_statement']) && empty($line['opening_reconciled']) ? $this->readBatch($line) : array();
		$pair = null; $accountCandidates = array(); $accountReview = null; $completed = null;
		if (empty($line['opening_reconciled']) && (int) $line['status'] === 0 && $line['account_open'] && empty($line['needs_review']) && empty($batch['active'])) {
			$accountReview = !empty($line['fk_statement']) ? $this->service->getWorklistPaymentAccountReview((int) $line['rowid'],array($line['bank_account_id'])) : null;
			$prepared = $accountReview ? array() : ($this->callbacks['prepareReviewRow'])($line);
			$accountReview = $accountReview ?: ($prepared['account_review'] ?? null);
			if (!$accountReview && !empty($prepared['suggestion']['doc_id'])) { $pair = $prepared; }
			$accountCandidates = $accountReview['candidates'] ?? array();
			$payments = new KreaBankPlannedPayment($this->db,$this->service->user,$this->entity,$this->service);
			if (($pair['suggestion']['doc_type'] ?? '') === 'tax' && $payments->permissions('tax')) {
				try { $payments->snapshot($line,'tax',(int) $pair['suggestion']['doc_id']); }
				catch (DomainException $error) { $pair = null; }
			}
			// Existing native/document proposals take precedence. Payroll advice never enters the matcher.
			if (!$pair && !$accountReview && empty($batch['draft']) && empty($line['needs_review'])) {
				$advice = $payments->propose($line);
				if (($advice['status'] ?? '') === 'unique') {
					$c = $advice['candidate'];
					$pair = array('line'=>$line,'suggestion'=>array('doc_type'=>$c['type'],'doc_id'=>(int) $c['id'],'doc_ref'=>$c['reference'],
						'doc_date'=>$c['doc_date'],'amount_open'=>'-' . $c['remaining'],'thirdparty_name'=>$c['label']),
						'preselected'=>false,'is_bulk_safe'=>false);
				}
			}
		}
		if ((int) $line['status'] === 2 && empty($batch['active']) && !empty($line['native_bank_line_id'])) {
			$p = $this->db->prefix();
			$native = $this->rows("SELECT b.rowid,b.label,b.num_chq,b.dateo,CAST(b.amount AS DECIMAL(24,8)) amount FROM {$p}bank b JOIN {$p}bank_account ba ON ba.rowid=b.fk_account AND ba.entity=" . $this->entity
				. ' WHERE b.rowid=' . (int) $line['native_bank_line_id'] . ' AND b.fk_account=' . (int) $line['bank_account_id']);
			if ($native) { $n=$native[0]; $completed = array('doc_type'=>'native_bank','doc_id'=>(int) $n['rowid'],'reference'=>$n['num_chq'] ?: '#' . $n['rowid'],'date'=>$n['dateo'],'amount'=>$n['amount'],'currency'=>$line['currency'],'account_ref'=>$line['bank_account_ref'],'description'=>$n['label']); }
		}
		return array('key'=>$key,'line'=>$line,'pair'=>$pair,'account_review'=>$accountReview,'account_candidates'=>$accountCandidates,'batch'=>$batch,'completed'=>$completed);
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
		if (!empty($line['fk_statement']) && $this->service->getPaymentAccountReview((int) $line['rowid'],array($line['bank_account_id']))) { return array(); }
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
