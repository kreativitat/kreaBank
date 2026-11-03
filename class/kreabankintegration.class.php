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
			. " SUM(CASE WHEN l.status=2 AND (b.rowid IS NULL OR b.rappro<>1 OR ABS(b.amount-l.amount)>0.01 OR COALESCE(b.num_releve,'')<>s.ref OR l.currency<>ba.currency_code) THEN 1 ELSE 0 END) issue_lines,s.currency"
			. " FROM {$p}kreabank_statement s JOIN {$p}bank_account ba ON ba.rowid=s.bank_account_id AND ba.entity=$e"
			. " LEFT JOIN {$p}kreabank_statement_line l ON l.fk_statement=s.rowid AND l.entity=s.entity"
			. " LEFT JOIN {$p}bank b ON b.rowid=l.fk_native_bank_line AND b.fk_account=ba.rowid"
			. " WHERE s.entity=$e$account GROUP BY s.rowid,s.bank_account_id,ba.ref,ba.label,s.ref,s.source_type,s.filename,s.date_import,s.currency";
		$sql .= " UNION ALL SELECT b.fk_account,ba.ref,ba.label,b.num_releve,MIN(COALESCE(m.operation_date,b.dateo)),MAX(COALESCE(m.operation_date,b.dateo)),"
			. " COUNT(b.rowid),SUM(b.amount),COALESCE(MAX(m.source_type),''),COALESCE(MAX(m.source_file),''),MAX(m.datec),COUNT(b.rowid),"
			. " SUM(CASE WHEN b.rappro=1 THEN 1 ELSE 0 END),SUM(CASE WHEN m.status=2 AND b.rappro<>1 THEN 1 ELSE 0 END),ba.currency_code"
			. " FROM {$p}bank b JOIN {$p}bank_account ba ON ba.rowid=b.fk_account AND ba.entity=$e"
			. " LEFT JOIN {$p}kreabank_bankmeta m ON m.fk_bank_line=b.rowid AND m.entity=$e"
			. " WHERE COALESCE(b.num_releve,'')<>''$account"
			. " AND (m.rowid IS NOT NULL OR b.note LIKE 'KreaBank import %' OR b.note LIKE 'KreaBank staged import %')"
			. " AND NOT EXISTS (SELECT 1 FROM {$p}kreabank_statement s WHERE s.entity=$e AND s.bank_account_id=ba.rowid AND s.ref=b.num_releve)"
			. " AND NOT EXISTS (SELECT 1 FROM {$p}kreabank_statement_line l WHERE l.entity=$e AND l.fk_native_bank_line=b.rowid)"
			. " GROUP BY b.fk_account,ba.ref,ba.label,b.num_releve,ba.currency_code";
		return $sql;
	}

	/** Only owned accounts with staged statements or recognizable legacy imports. */
	public function statementAccounts($accountId = 0)
	{
		$p = $this->db->prefix();
		$e = $this->entity;
		$account = (int) $accountId > 0 ? ' AND ba.rowid=' . (int) $accountId : '';
		return $this->query("SELECT ba.rowid,ba.ref,ba.label FROM {$p}bank_account ba WHERE ba.entity=$e$account AND ("
			. " EXISTS (SELECT 1 FROM {$p}kreabank_statement s WHERE s.entity=$e AND s.bank_account_id=ba.rowid)"
			. " OR EXISTS (SELECT 1 FROM {$p}bank b LEFT JOIN {$p}kreabank_bankmeta m ON m.fk_bank_line=b.rowid AND m.entity=$e"
			. " WHERE b.fk_account=ba.rowid AND COALESCE(b.num_releve,'')<>''"
			. " AND (m.rowid IS NOT NULL OR b.note LIKE 'KreaBank import %' OR b.note LIKE 'KreaBank staged import %')))"
			. ' ORDER BY ba.clos,ba.ref,ba.label,ba.rowid');
	}

	/** Current-company account overview; balances use native operation dates. */
	public function accounts($kind = 'bank', $accountId = 0, $sortfield = 'ref', $sortorder = 'ASC', $limit = 0, $offset = 0, $withFeed = false)
	{
		$p = $this->db->prefix();
		$e = $this->entity;
		$columns = array('ref' => 'ba.ref', 'currency_code' => 'ba.currency_code', 'balance' => 'balance', 'pending_count' => 'pending_count');
		$feedColumns = '';
		$feedJoin = '';
		$connected = '0';
		if ($withFeed) {
			// Keep unconfigured installations independent of the optional feed schema.
			$config = $this->db->fetch_object($this->query("SELECT value FROM {$p}const WHERE entity=0 AND name='KREABANK_FINTABLE_WORKSPACE'"));
			$feedColumns = ',NULL feed_balance,NULL bank_updated_at,NULL feed_collected_at,NULL feed_status';
			if ($config && trim((string) $config->value) !== '') {
				$feedColumns = ",CASE WHEN f.status IN ('mapping_review','unavailable') THEN NULL ELSE f.balance END feed_balance,f.bank_updated_at,f.last_attempt feed_collected_at,f.status feed_status";
				$feedJoin = " LEFT JOIN {$p}kreabank_feed_account f ON f.fk_bank_account=ba.rowid AND f.entity=ba.entity"
					. ' AND f.workspace_id=' . $this->quote($config->value)
					. " AND f.currency=ba.currency_code AND f.iban=UPPER(REPLACE(REPLACE(ba.iban_prefix,' ',''),'-',''))"
					. ' AND ba.courant<>2';
				$connected = "f.rowid IS NOT NULL AND f.status NOT IN ('mapping_review','unavailable')";
			}
			$columns['feed_balance'] = 'feed_balance';
			$columns['bank_updated_at'] = 'bank_updated_at';
			$columns['overview'] = "CASE WHEN UPPER(LEFT(ba.ref,3))='CO_' THEN 0 WHEN $connected THEN 1 ELSE 2 END";
		}
		$order = ($columns[$sortfield] ?? $columns['ref']) . (strtoupper($sortorder) === 'DESC' ? ' DESC' : ' ASC') . ',ba.rowid';
		if ($withFeed && $sortfield === 'overview') {
			$order = $columns['overview'] . ' ASC,ba.ref ASC,ba.rowid';
		}
		if ($withFeed && in_array($sortfield, array('feed_balance', 'bank_updated_at'), true)) {
			$order = $sortfield . ' IS NULL ASC,' . $order;
		}
		$now = $this->quote($this->db->idate(dol_now()));
		$accountFilter = (int) $accountId > 0 ? ' AND ba.rowid=' . (int) $accountId : '';
		$filter = $kind === 'all' ? '' : ($kind === 'closed' ? ' AND ba.clos=1' : ' AND ba.clos=0' . ($kind === 'cash' ? ' AND ba.courant=2' : ' AND ba.courant<>2'));
		$sql = "SELECT ba.rowid,ba.ref,ba.label,ba.currency_code,ba.clos,COALESCE(nb.balance,0) balance,COALESCE(st.pending_count,0)+COALESCE(lg.pending_count,0) pending_count" . $feedColumns
			. " FROM {$p}bank_account ba LEFT JOIN (SELECT b.fk_account,SUM(b.amount) balance FROM {$p}bank b JOIN {$p}bank_account owner ON owner.rowid=b.fk_account AND owner.entity=$e WHERE b.dateo<=$now GROUP BY b.fk_account) nb ON nb.fk_account=ba.rowid"
			. " LEFT JOIN (SELECT s.bank_account_id,COUNT(*) pending_count FROM {$p}kreabank_statement_line l JOIN {$p}kreabank_statement s ON s.rowid=l.fk_statement AND s.entity=l.entity LEFT JOIN {$p}bank b ON b.rowid=l.fk_native_bank_line WHERE s.entity=$e AND l.status IN (0,1) AND COALESCE(b.rappro,0)=0 GROUP BY s.bank_account_id) st ON st.bank_account_id=ba.rowid"
			. " LEFT JOIN (SELECT b.fk_account,COUNT(*) pending_count FROM {$p}kreabank_bankmeta m JOIN {$p}bank b ON b.rowid=m.fk_bank_line WHERE m.entity=$e AND m.status IN (0,1) AND b.rappro=0 AND NOT EXISTS (SELECT 1 FROM {$p}kreabank_statement_line l WHERE l.entity=$e AND l.fk_native_bank_line=b.rowid) GROUP BY b.fk_account) lg ON lg.fk_account=ba.rowid"
			. $feedJoin . " WHERE ba.entity=$e$filter$accountFilter ORDER BY $order";
		if ((int) $limit > 0) {
			$sql .= $this->db->plimit((int) $limit, max(0, (int) $offset));
		}
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
			. " b.rowid native_id,b.amount native_amount,b.rappro,b.num_releve,l.fk_native_bank_line expected_native_id,ba.currency_code native_currency,'staging' source_storage"
			. " FROM {$p}kreabank_statement s JOIN {$p}bank_account ba ON ba.rowid=s.bank_account_id AND ba.entity=$e"
			. " JOIN {$p}kreabank_statement_line l ON l.fk_statement=s.rowid AND l.entity=s.entity"
			. " LEFT JOIN {$p}bank b ON b.rowid=l.fk_native_bank_line AND b.fk_account=ba.rowid"
			. " WHERE s.entity=$e AND ba.rowid=$a AND s.ref=$r"
			. " UNION ALL SELECT b.rowid,b.rowid,COALESCE(m.operation_date,b.dateo),COALESCE(m.value_date,b.datev),b.amount,ba.currency_code,m.running_balance,m.payment_reference,m.bank_reference,m.counterparty_name,m.counterparty_iban,COALESCE(m.description,b.label),COALESCE(m.status,0),0,b.rowid,b.amount,b.rappro,b.num_releve,b.rowid,ba.currency_code,'legacy_native'"
			. " FROM {$p}bank b JOIN {$p}bank_account ba ON ba.rowid=b.fk_account AND ba.entity=$e"
			. " LEFT JOIN {$p}kreabank_bankmeta m ON m.fk_bank_line=b.rowid AND m.entity=$e"
			. " WHERE ba.rowid=$a AND b.num_releve=$r"
			. " AND (m.rowid IS NOT NULL OR b.note LIKE 'KreaBank import %' OR b.note LIKE 'KreaBank staged import %')"
			. " AND NOT EXISTS (SELECT 1 FROM {$p}kreabank_statement s WHERE s.entity=$e AND s.bank_account_id=ba.rowid AND s.ref=$r)"
			. " AND NOT EXISTS (SELECT 1 FROM {$p}kreabank_statement_line l WHERE l.entity=$e AND l.fk_native_bank_line=b.rowid)";
	}

	/** All original statement content, including pending evidence and unmapped legacy imports. */
	public function bankStatementSql($accountId = 0)
	{
		$p = $this->db->prefix();
		$e = $this->entity;
		$account = (int) $accountId > 0 ? ' AND ba.rowid=' . (int) $accountId : '';
		return "SELECT l.rowid,l.line_rank,s.rowid statement_id,s.ref statement_ref,ba.rowid bank_account_id,ba.ref bank_account_ref,ba.label bank_account_label,"
			. " l.operation_date,l.value_date,l.description,l.payment_reference,l.bank_reference,l.amount,l.currency,l.running_balance,l.status,l.is_duplicate,"
			. " b.rowid native_id,b.amount native_amount,b.rappro,b.num_releve,l.fk_native_bank_line expected_native_id,ba.currency_code native_currency,'staging' source_storage,s.source_type"
			. " FROM {$p}kreabank_statement s JOIN {$p}bank_account ba ON ba.rowid=s.bank_account_id AND ba.entity=$e"
			. " JOIN {$p}kreabank_statement_line l ON l.fk_statement=s.rowid AND l.entity=s.entity"
			. " LEFT JOIN {$p}bank b ON b.rowid=l.fk_native_bank_line AND b.fk_account=ba.rowid"
			. " WHERE s.entity=$e$account"
			. " UNION ALL SELECT b.rowid,b.rowid,0,b.num_releve,ba.rowid,ba.ref,ba.label,"
			. " COALESCE(m.operation_date,b.dateo),COALESCE(m.value_date,b.datev),COALESCE(m.description,b.label),m.payment_reference,m.bank_reference,"
			. " b.amount,ba.currency_code,m.running_balance,COALESCE(m.status,0),0,b.rowid,b.amount,b.rappro,b.num_releve,b.rowid,ba.currency_code,'legacy_native',m.source_type"
			. " FROM {$p}bank b JOIN {$p}bank_account ba ON ba.rowid=b.fk_account AND ba.entity=$e"
			. " LEFT JOIN {$p}kreabank_bankmeta m ON m.fk_bank_line=b.rowid AND m.entity=$e"
			. " WHERE COALESCE(b.num_releve,'')<>''$account"
			. " AND (m.rowid IS NOT NULL OR b.note LIKE 'KreaBank import %' OR b.note LIKE 'KreaBank staged import %')"
			. " AND NOT EXISTS (SELECT 1 FROM {$p}kreabank_statement_line l JOIN {$p}kreabank_statement s ON s.rowid=l.fk_statement AND s.entity=l.entity"
			. " WHERE l.entity=$e AND s.bank_account_id=ba.rowid AND l.fk_native_bank_line=b.rowid)";
	}

	/** Read-only running balances anchored to a successfully collected bank snapshot. */
	public function bankStatementWithBalancesSql($accountId = 0)
	{
		$p = $this->db->prefix();
		$e = $this->entity;
		$source = '(' . $this->bankStatementSql($accountId) . ') h';
		$config = $this->db->fetch_object($this->query("SELECT value FROM {$p}const WHERE entity=0 AND name='KREABANK_FINTABLE_WORKSPACE'"));
		if (!$config || trim((string) $config->value) === '') {
			return 'SELECT h.*,h.running_balance display_balance,0 balance_calculated,NULL balance_updated_at FROM ' . $source;
		}
		// A completed collection proves that every returned page was persisted. A newer
		// failed/partial collection, changed mapping, conflict or missing source link
		// invalidates the snapshot as a running-balance anchor for the entire account.
		$payload = "CASE WHEN JSON_VALID(x.source_payload) THEN x.source_payload ELSE '{}' END";
		$anchor = "SELECT f.rowid,f.fk_bank_account,f.currency,f.balance,f.bank_updated_at FROM {$p}kreabank_feed_account f"
			. " JOIN {$p}bank_account ba ON ba.rowid=f.fk_bank_account AND ba.entity=f.entity"
			. " WHERE f.entity=$e AND f.workspace_id=" . $this->quote($config->value)
			. " AND f.currency=ba.currency_code AND f.iban=UPPER(REPLACE(REPLACE(ba.iban_prefix,' ',''),'-','')) AND ba.courant<>2"
			. " AND f.balance IS NOT NULL AND f.status IN ('ready','stale') AND COALESCE(f.watermark,'')<>''"
			. " AND STR_TO_DATE(LEFT(f.bank_updated_at,10),'%Y-%m-%d') IS NOT NULL"
			. " AND f.last_success>=f.last_attempt AND f.history_to<=LEFT(f.bank_updated_at,10)"
			. " AND NOT EXISTS (SELECT 1 FROM {$p}kreabank_feed_transaction x"
			. " LEFT JOIN {$p}kreabank_statement_line xl ON xl.rowid=x.fk_statement_line AND xl.entity=x.entity AND xl.line_uid=x.source_uid"
			. " LEFT JOIN {$p}kreabank_statement xs ON xs.rowid=xl.fk_statement AND xs.entity=xl.entity"
			. " WHERE x.fk_feed_account=f.rowid AND (x.entity<>f.entity OR x.status NOT IN ('imported','zero')"
			. " OR (x.status='imported' AND (xl.rowid IS NULL OR xs.rowid IS NULL OR xs.bank_account_id<>f.fk_bank_account"
			. " OR xs.source_type<>'fintable' OR xl.currency<>f.currency OR xl.is_duplicate<>0 OR xl.operation_date IS NULL"
			. " OR xl.operation_date>LEFT(f.bank_updated_at,10)"
			. " OR JSON_EXTRACT($payload,'$.amount') IS NULL OR JSON_EXTRACT($payload,'$.currency') IS NULL OR JSON_EXTRACT($payload,'$.operation_date') IS NULL"
			. " OR xl.amount<>CAST(JSON_UNQUOTE(JSON_EXTRACT($payload,'$.amount')) AS DECIMAL(24,8))"
			. " OR xl.currency<>JSON_UNQUOTE(JSON_EXTRACT($payload,'$.currency'))"
			. " OR xl.operation_date<>JSON_UNQUOTE(JSON_EXTRACT($payload,'$.operation_date'))))))"
			. " AND NOT EXISTS (SELECT 1 FROM {$p}kreabank_feed_transaction x WHERE x.fk_feed_account=f.rowid AND x.status='imported' GROUP BY x.fk_statement_line HAVING COUNT(*)>1)"
			. " AND NOT EXISTS (SELECT 1 FROM {$p}kreabank_statement_line xl JOIN {$p}kreabank_statement xs ON xs.rowid=xl.fk_statement AND xs.entity=xl.entity"
			. " WHERE xs.entity=f.entity AND xs.bank_account_id=f.fk_bank_account AND xs.source_type='fintable'"
			. " AND NOT EXISTS (SELECT 1 FROM {$p}kreabank_feed_transaction x WHERE x.fk_feed_account=f.rowid AND x.entity=f.entity"
			. " AND x.fk_statement_line=xl.rowid AND x.source_uid=xl.line_uid AND x.status='imported'))";
		// Exact DECIMAL subtraction includes every later source line, independent of
		// the visible page or chosen sort. Same-day ordering follows the default table.
		$later = "SELECT SUM(nl.amount) FROM {$p}kreabank_feed_transaction nt"
			. " JOIN {$p}kreabank_statement_line nl ON nl.rowid=nt.fk_statement_line AND nl.entity=nt.entity"
			. " JOIN {$p}kreabank_statement ns ON ns.rowid=nl.fk_statement AND ns.entity=nl.entity"
			. " WHERE nt.fk_feed_account=f.rowid AND nt.entity=$e AND nt.status='imported' AND ns.bank_account_id=h.bank_account_id"
			. " AND (nl.operation_date>h.operation_date OR (nl.operation_date=h.operation_date AND (ns.rowid>h.statement_id"
			. " OR (ns.rowid=h.statement_id AND (nl.line_rank>h.line_rank OR (nl.line_rank=h.line_rank AND nl.rowid>h.rowid))))))";
		return "SELECT h.*,COALESCE(h.running_balance,f.balance-COALESCE(($later),0)) display_balance,"
			. " CASE WHEN h.running_balance IS NULL AND f.rowid IS NOT NULL THEN 1 ELSE 0 END balance_calculated,f.bank_updated_at balance_updated_at"
			. " FROM $source LEFT JOIN {$p}kreabank_feed_transaction t ON h.source_storage='staging' AND t.fk_statement_line=h.rowid AND t.entity=$e AND t.status='imported'"
			. " AND NOT EXISTS (SELECT 1 FROM {$p}kreabank_feed_transaction t2 WHERE t2.entity=t.entity AND t2.fk_statement_line=t.fk_statement_line AND t2.rowid<>t.rowid)"
			. " LEFT JOIN ($anchor) f ON f.rowid=t.fk_feed_account AND f.fk_bank_account=h.bank_account_id AND f.currency=h.currency";
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
