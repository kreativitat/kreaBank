<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
require_once __DIR__ . '/kreabankintegration.class.php';
require_once __DIR__ . '/KreaBankPaymentBatchTrait.php';
require_once __DIR__ . '/KreaBankWriteOff.class.php';

/** Read-only History projection. IDs always retain their storage namespace. */
class KreaBankHistory
{
	private $db;
	private $entity;
	public function __construct($db, $entity)
	{
		$this->db = $db; $this->entity = (int) $entity;
		if ($this->entity <= 0) { throw new InvalidArgumentException('Company context required'); }
	}

	private function rows($sql)
	{
		$result = $this->db->query($sql);
		if (!$result) { throw new RuntimeException('Unable to load reconciliation history'); }
		$rows = array();
		while ($row = $this->db->fetch_object($result)) { $rows[] = (array) $row; }
		return $rows;
	}

	private function sql($accountId = 0)
	{
		return 'SELECT h.* FROM (' . (new KreaBankIntegration($this->db, $this->entity))->bankStatementSql($accountId)
			. ") h WHERE (h.status=2 OR h.rappro=1)";
	}

	public function find($id, $source, $accountId = 0)
	{
		if (!in_array($source, array('staged', 'native'), true) || (int) $id <= 0) { return null; }
		$rows = $this->rows($this->sql($accountId) . ' AND h.rowid=' . (int) $id
			. " AND h.source_storage='" . ($source === 'staged' ? 'staging' : 'legacy_native') . "'");
		if (count($rows) !== 1) { return null; }
		$audits = $this->audits(100, ' AND a.fk_statement_line=' . (int) $id . " AND a.audit_type='reconcile'");
		return $this->withReconciliationAudit($this->prepare($rows[0]), $audits[(int) $id] ?? array());
	}

	private function prepare($row)
	{
		$row['line_source'] = $row['source_storage'] === 'staging' ? 'staged' : 'native';
		$row['native_bank_line_id'] = (int) ($row['native_id'] ?? 0);
		$row['date_validate'] = $row['operation_date'];
		$row['strategy'] = !empty($row['group_id']) ? 'manual_payment_batch' : 'native';
		$row['needs_review'] = !empty($row['is_duplicate']) || (int) $row['rappro'] !== 1
			|| (string) $row['currency'] !== (string) $row['native_currency']
			|| (string) $row['num_releve'] !== (string) $row['statement_ref']
			|| (!empty($row['group_id']) ? (int) $row['group_valid'] !== 1
				: ((int) $row['native_id'] <= 0 || KreaBankPaymentBatchDecimal::normalize($row['amount']) !== KreaBankPaymentBatchDecimal::normalize($row['native_amount'])));
		return $row;
	}

	/** Fixed recent window, containing both generations and verified group evidence. */
	public function recent($limit = 5000, $offset = 0, $accountId = 0)
	{
		$rows = $this->rows($this->sql($accountId) . ' ORDER BY h.operation_date DESC,h.rowid DESC,h.source_storage'
			. $this->db->plimit(max(1, min(5000, (int) $limit)), max(0, (int) $offset)));
		$ids = array();
		foreach ($rows as $row) { $ids[(int) $row['rowid']] = (int) $row['rowid']; }
		$audits = $ids ? $this->audits(5000, ' AND a.fk_statement_line IN (' . implode(',', $ids) . ") AND a.audit_type='reconcile'") : array();
		$out = array();
		foreach ($rows as $row) {
			$out[] = $this->withReconciliationAudit($this->prepare($row), $audits[$row['rowid']] ?? array());
		}
		return $out;
	}

	private function withReconciliationAudit($row, $audits)
	{
		foreach ($audits as $audit) {
			$payload = json_decode((string) $audit['payload_json'], true);
			if (!is_array($payload)) { continue; }
			if (isset($payload['source']) && $payload['source'] !== $row['line_source']) { continue; }
			if (!empty($payload['native_bank_line_id']) && (int) $payload['native_bank_line_id'] !== (int) $row['native_bank_line_id']) { continue; }
			// Untagged historical IDs are ambiguous when both namespaces contain that ID.
			if (empty($payload['source']) && empty($payload['native_bank_line_id']) && empty($payload['batch_id'])) { continue; }
			if (!empty($payload['batch_id']) && (int) $payload['batch_id'] !== (int) $row['group_id']) { continue; }
			$row['history_audit'] = $audit;
			$row['date_validate'] = $audit['datec'];
			$row['strategy'] = (string) ($payload['strategy'] ?? $row['strategy']);
			$row['confidence_score'] = (int) ($payload['confidence_score'] ?? 0);
			break;
		}
		if ($row['strategy'] === 'manual_writeoff' && !KreaBankWriteOff::undoSnapshotFromHistory($row)) { $row['needs_review'] = true; }
		return $row;
	}

	/** Account pages fetch only events for their displayed source IDs. No schema writes. */
	public function audits($limit = 5000, $where = '')
	{
		$rows = $this->rows('SELECT a.*,u.login FROM ' . $this->db->prefix() . 'kreabank_recon_audit a LEFT JOIN '
			. $this->db->prefix() . 'user u ON u.rowid=a.fk_user WHERE a.entity=' . $this->entity . $where
			. ' ORDER BY a.datec DESC,a.rowid DESC' . ($limit > 0 ? $this->db->plimit(min(5000, (int) $limit)) : ''));
		if ($where === '') { return $rows; }
		$out = array();
		foreach ($rows as $row) { $out[$row['fk_statement_line']][] = $row; }
		return $out;
	}

	/** Every verified group member retains its own native identity and document links. */
	public function links($rows, $adapter)
	{
		$members = array(); $groups = array(); $ids = array(); $out = array();
		foreach ($rows as $row) {
			$key = $row['line_source'] . ':' . $row['rowid']; $out[$key] = array();
			if (!empty($row['group_id'])) {
				if (!$row['needs_review']) { $groups[(int) $row['group_id']] = $key; }
			} elseif ((int) $row['native_bank_line_id'] > 0) {
				$id = (int) $row['native_bank_line_id']; $ids[$id] = $id; $members[$key][] = $id;
			}
		}
		if ($groups) {
			$p = $this->db->prefix(); $e = $this->entity;
			foreach ($this->rows("SELECT g.rowid batch_id,b.rowid FROM {$p}kreabank_payment_batch g"
				. " JOIN {$p}kreabank_payment_batch_line m ON m.fk_batch=g.rowid AND m.entity=g.entity"
				. " JOIN {$p}bank b ON b.rowid=m.active_bank_line AND b.fk_account=g.bank_account_id"
				. " JOIN {$p}bank_account ba ON ba.rowid=b.fk_account AND ba.entity=g.entity"
				. " WHERE g.entity=$e AND g.status=1 AND g.rowid IN (" . implode(',', array_keys($groups)) . ') ORDER BY g.rowid,m.rowid') as $member) {
				$id = (int) $member['rowid']; $key = $groups[$member['batch_id']];
				$ids[$id] = $id; $members[$key][] = $id;
				$out[$key][] = array('doc_type'=>'bank_line','fk_doc'=>$id,'doc_ref'=>'#' . $id,'url'=>'/compta/bank/line.php?rowid=');
			}
		}
		$links = $ids ? $adapter->getLineLinksBatch(array_values($ids), 'native') : array();
		foreach ($members as $key => $nativeIds) {
			foreach ($nativeIds as $id) { $out[$key] = array_merge($out[$key], $links[$id] ?? array()); }
		}
		return $out;
	}

	/** Exact scalar source facts, bound to one displayed undo action. */
	public static function snapshot($row)
	{
		$out = array();
		foreach (array('rowid','line_source','bank_account_id','native_bank_line_id','amount','native_amount','currency','status','rappro','statement_ref','num_releve','operation_date','description','group_id','needs_review') as $field) {
			$out[$field] = (string) ($row[$field] ?? '');
		}
		$writeoff = KreaBankWriteOff::undoSnapshotFromHistory($row);
		if ($writeoff) { $out['writeoff'] = $writeoff; }
		return $out;
	}
}
