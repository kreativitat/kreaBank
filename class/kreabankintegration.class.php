<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/** Read-only projection of imported evidence and its native ledger links. */
class KreaBankIntegration
{
	private $db;
	private $entity;

	public function __construct($db, $entity)
	{
		$this->db = $db;
		$this->entity = (int) $entity;
		if ($this->entity <= 0) {
			throw new InvalidArgumentException('A company context is required');
		}
	}

	/** Both storage generations are visible together, with no native movement counted twice. */
	public function statementsSql($accountId = 0)
	{
		$p = $this->db->prefix();
		$e = $this->entity;
		$account = (int) $accountId > 0 ? ' AND ba.rowid = ' . (int) $accountId : '';
		$sql = "SELECT s.bank_account_id, ba.ref bank_account_ref, ba.label bank_account_label, s.ref statement_ref,"
			. " MIN(l.operation_date) first_operation_date, MAX(l.operation_date) last_operation_date,"
			. " COUNT(l.rowid) imported_lines, COALESCE(SUM(l.amount),0) amount_total, s.source_type, s.filename source_file, s.date_import imported_at,"
			. " SUM(CASE WHEN b.rowid IS NOT NULL THEN 1 ELSE 0 END) native_lines,"
			. " SUM(CASE WHEN l.status=2 AND b.rappro=1 AND ABS(b.amount-l.amount)<=0.01 AND b.num_releve=s.ref AND l.currency=ba.currency_code THEN 1 ELSE 0 END) reconciled_lines,"
			. " SUM(CASE WHEN l.status=2 AND (b.rowid IS NULL OR b.rappro<>1 OR ABS(b.amount-l.amount)>0.01 OR COALESCE(b.num_releve,'')<>s.ref OR l.currency<>ba.currency_code) THEN 1 ELSE 0 END) issue_lines"
			. " FROM {$p}kreabank_statement s JOIN {$p}bank_account ba ON ba.rowid=s.bank_account_id AND ba.entity=$e"
			. " LEFT JOIN {$p}kreabank_statement_line l ON l.fk_statement=s.rowid AND l.entity=s.entity"
			. " LEFT JOIN {$p}bank b ON b.rowid=l.fk_native_bank_line AND b.fk_account=ba.rowid"
			. " WHERE s.entity=$e$account GROUP BY s.rowid,s.bank_account_id,ba.ref,ba.label,s.ref,s.source_type,s.filename,s.date_import";
		$sql .= " UNION ALL SELECT b.fk_account,ba.ref,ba.label,b.num_releve,MIN(COALESCE(m.operation_date,b.dateo)),MAX(COALESCE(m.operation_date,b.dateo)),"
			. " COUNT(b.rowid),SUM(b.amount),COALESCE(MAX(m.source_type),''),COALESCE(MAX(m.source_file),''),MAX(m.datec),COUNT(b.rowid),"
			. " SUM(CASE WHEN b.rappro=1 THEN 1 ELSE 0 END),SUM(CASE WHEN m.status=2 AND b.rappro<>1 THEN 1 ELSE 0 END)"
			. " FROM {$p}bank b JOIN {$p}bank_account ba ON ba.rowid=b.fk_account AND ba.entity=$e"
			. " LEFT JOIN {$p}kreabank_bankmeta m ON m.fk_bank_line=b.rowid AND m.entity=$e"
			. " WHERE COALESCE(b.num_releve,'')<>''$account"
			. " AND (m.rowid IS NOT NULL OR b.note LIKE 'KreaBank import %' OR b.note LIKE 'KreaBank staged import %')"
			. " AND NOT EXISTS (SELECT 1 FROM {$p}kreabank_statement s WHERE s.entity=$e AND s.bank_account_id=ba.rowid AND s.ref=b.num_releve)"
			. " AND NOT EXISTS (SELECT 1 FROM {$p}kreabank_statement_line l WHERE l.entity=$e AND l.fk_native_bank_line=b.rowid)"
			. " GROUP BY b.fk_account,ba.ref,ba.label,b.num_releve";
		return $sql;
	}

	/** Current-company account overview; balances use native operation dates. */
	public function accounts($kind = 'bank')
	{
		$p = $this->db->prefix();
		$e = $this->entity;
		$now = $this->quote($this->db->idate(dol_now()));
		$filter = $kind === 'closed' ? ' AND ba.clos=1' : ' AND ba.clos=0' . ($kind === 'cash' ? ' AND ba.courant=2' : ' AND ba.courant<>2');
		$sql = "SELECT ba.rowid,ba.ref,ba.label,ba.currency_code,ba.clos,COALESCE(nb.balance,0) balance,COALESCE(st.pending_count,0)+COALESCE(lg.pending_count,0) pending_count"
			. " FROM {$p}bank_account ba LEFT JOIN (SELECT b.fk_account,SUM(b.amount) balance FROM {$p}bank b JOIN {$p}bank_account owner ON owner.rowid=b.fk_account AND owner.entity=$e WHERE b.dateo<=$now GROUP BY b.fk_account) nb ON nb.fk_account=ba.rowid"
			. " LEFT JOIN (SELECT s.bank_account_id,COUNT(*) pending_count FROM {$p}kreabank_statement_line l JOIN {$p}kreabank_statement s ON s.rowid=l.fk_statement AND s.entity=l.entity LEFT JOIN {$p}bank b ON b.rowid=l.fk_native_bank_line WHERE s.entity=$e AND l.status IN (0,1) AND COALESCE(b.rappro,0)=0 GROUP BY s.bank_account_id) st ON st.bank_account_id=ba.rowid"
			. " LEFT JOIN (SELECT b.fk_account,COUNT(*) pending_count FROM {$p}kreabank_bankmeta m JOIN {$p}bank b ON b.rowid=m.fk_bank_line WHERE m.entity=$e AND m.status IN (0,1) AND b.rappro=0 AND NOT EXISTS (SELECT 1 FROM {$p}kreabank_statement_line l WHERE l.entity=$e AND l.fk_native_bank_line=b.rowid) GROUP BY b.fk_account) lg ON lg.fk_account=ba.rowid"
			. " WHERE ba.entity=$e$filter ORDER BY ba.ref,ba.rowid";
		return $this->query($sql);
	}

	public function query($sql)
	{
		if (!preg_match('/^SELECT\b/i', $sql)) {
			throw new LogicException('Integration reports are read only');
		}
		$result = $this->db->query($sql);
		if (!$result) {
			throw new RuntimeException('Unable to read bank integration data');
		}
		return $result;
	}

	public function statement($accountId, $reference)
	{
		$sql = 'SELECT h.* FROM (' . $this->statementsSql($accountId) . ') h WHERE h.statement_ref=' . $this->quote($reference);
		$result = $this->query($sql);
		return $this->db->fetch_object($result);
	}

	/** Raw imported amounts remain evidence; native values are shown separately on discrepancies. */
	public function linesSql($accountId, $reference)
	{
		$p = $this->db->prefix();
		$e = $this->entity;
		$a = (int) $accountId;
		$r = $this->quote($reference);
		return "SELECT l.rowid,l.line_rank,l.operation_date,l.value_date,l.amount,l.currency,l.running_balance,l.payment_reference,l.bank_reference,l.counterparty_name,l.counterparty_iban,l.description,l.status,l.is_duplicate,"
			. " b.rowid native_id,b.amount native_amount,b.rappro,b.num_releve,l.fk_native_bank_line expected_native_id,ba.currency_code native_currency"
			. " FROM {$p}kreabank_statement s JOIN {$p}bank_account ba ON ba.rowid=s.bank_account_id AND ba.entity=$e"
			. " JOIN {$p}kreabank_statement_line l ON l.fk_statement=s.rowid AND l.entity=s.entity"
			. " LEFT JOIN {$p}bank b ON b.rowid=l.fk_native_bank_line AND b.fk_account=ba.rowid"
			. " WHERE s.entity=$e AND ba.rowid=$a AND s.ref=$r"
			. " UNION ALL SELECT b.rowid,b.rowid,COALESCE(m.operation_date,b.dateo),COALESCE(m.value_date,b.datev),b.amount,ba.currency_code,m.running_balance,m.payment_reference,m.bank_reference,m.counterparty_name,m.counterparty_iban,COALESCE(m.description,b.label),COALESCE(m.status,0),0,b.rowid,b.amount,b.rappro,b.num_releve,b.rowid,ba.currency_code"
			. " FROM {$p}bank b JOIN {$p}bank_account ba ON ba.rowid=b.fk_account AND ba.entity=$e"
			. " LEFT JOIN {$p}kreabank_bankmeta m ON m.fk_bank_line=b.rowid AND m.entity=$e"
			. " WHERE ba.rowid=$a AND b.num_releve=$r"
			. " AND (m.rowid IS NOT NULL OR b.note LIKE 'KreaBank import %' OR b.note LIKE 'KreaBank staged import %')"
			. " AND NOT EXISTS (SELECT 1 FROM {$p}kreabank_statement s WHERE s.entity=$e AND s.bank_account_id=ba.rowid AND s.ref=$r)"
			. " AND NOT EXISTS (SELECT 1 FROM {$p}kreabank_statement_line l WHERE l.entity=$e AND l.fk_native_bank_line=b.rowid)";
	}

	/** A matched-list filter uses the native bank ID, never the staged row ID. */
	public function matchedWhereSql($alias = 'b')
	{
		if (!in_array($alias, array('b'), true)) {
			throw new InvalidArgumentException('Unknown native table alias');
		}
		$p = $this->db->prefix();
		$e = $this->entity;
		return " AND b.rappro=1 AND EXISTS (SELECT 1 FROM {$p}bank_account kba WHERE kba.rowid=b.fk_account AND kba.entity=$e)"
			. " AND (EXISTS (SELECT 1 FROM {$p}kreabank_statement_line kl JOIN {$p}kreabank_statement ks ON ks.rowid=kl.fk_statement AND ks.entity=kl.entity WHERE kl.entity=$e AND kl.status=2 AND kl.fk_native_bank_line=b.rowid AND ks.bank_account_id=b.fk_account)"
			. " OR EXISTS (SELECT 1 FROM {$p}kreabank_bankmeta km WHERE km.entity=$e AND km.fk_bank_line=b.rowid AND km.status=2))";
	}

	private function quote($value)
	{
		return "'" . $this->db->escape((string) $value) . "'";
	}
}
