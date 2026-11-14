<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/** Read-only verification of every active group; never substitute a representative native ID. */
final class KreaBankPaymentBatchProjection
{
	public static function available($db)
	{
		$names = array();
		foreach (array('kreabank_payment_batch','kreabank_payment_batch_line') as $suffix) { $names[] = "'" . $db->escape($db->prefix() . $suffix) . "'"; }
		$result = $db->query('SELECT COUNT(*) nb FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN (' . implode(',', $names) . ')');
		if (!$result) { throw new RuntimeException('Unable to inspect payment batch schema'); }
		$row = $db->fetch_object($result);
		return (int) ($row->nb ?? 0) === 2;
	}

	public static function aggregateSql($db, $entity)
	{
		$p = $db->prefix(); $e = (int) $entity;
		if ($e <= 0) { throw new InvalidArgumentException('Company context required'); }
		$validMember = "n.rowid IS NOT NULL AND m.active_bank_line=m.fk_bank_line AND m.amount=CAST(n.amount AS DECIMAL(24,8)) AND n.amount*l.amount>0"
			. " AND n.rappro=1 AND n.num_releve=s.ref AND n.dateo IS NOT NULL AND n.dateo>'0000-00-00'"
			. " AND NOT EXISTS (SELECT 1 FROM {$p}kreabank_statement_line claim WHERE claim.fk_native_bank_line=n.rowid)"
			. " AND NOT EXISTS (SELECT 1 FROM {$p}kreabank_bankmeta meta WHERE meta.fk_bank_line=n.rowid)"
			. " AND NOT EXISTS (SELECT 1 FROM {$p}kreabank_payment_batch_line other JOIN {$p}kreabank_payment_batch og ON og.rowid=other.fk_batch AND og.entity=other.entity"
			. " WHERE other.active_bank_line=n.rowid AND other.rowid<>m.rowid AND og.status=1)";
		return "SELECT g.fk_statement_line,g.rowid batch_id,COUNT(m.rowid) component_count,COUNT(n.rowid) native_count,SUM(CAST(n.amount AS DECIMAL(24,8))) native_amount,MIN(n.rappro) rappro,"
			. " MIN(n.num_releve) num_releve,MAX(n.dateo) max_operation_date,MIN(n.dateo) min_operation_date,"
			. " CASE WHEN COUNT(m.rowid)>=2 AND COUNT(n.rowid)=COUNT(m.rowid) AND SUM(m.amount)=g.amount AND SUM(CAST(n.amount AS DECIMAL(24,8)))=g.amount"
			. " AND l.status=2 AND l.is_duplicate=0 AND l.fk_native_bank_line IS NULL AND l.allocated_amount=ABS(l.amount)"
			. " AND l.amount=g.amount AND l.currency=g.currency AND ba.currency_code=g.currency AND s.bank_account_id=g.bank_account_id"
			. " AND l.operation_date=g.operation_date AND s.ref=g.statement_ref AND g.active_statement_line=l.rowid"
			. " AND MIN(CASE WHEN $validMember THEN 1 ELSE 0 END)=1 THEN 1 ELSE 0 END is_valid"
			. " FROM {$p}kreabank_payment_batch g JOIN {$p}kreabank_statement_line l ON l.rowid=g.fk_statement_line AND l.entity=g.entity"
			. " JOIN {$p}kreabank_statement s ON s.rowid=l.fk_statement AND s.entity=l.entity"
			. " JOIN {$p}bank_account ba ON ba.rowid=s.bank_account_id AND ba.entity=l.entity"
			. " LEFT JOIN {$p}kreabank_payment_batch_line m ON m.fk_batch=g.rowid AND m.entity=g.entity"
			. " LEFT JOIN {$p}bank n ON n.rowid=m.fk_bank_line AND n.fk_account=s.bank_account_id"
			. " WHERE g.entity=$e AND g.status=1 GROUP BY g.rowid,g.fk_statement_line,g.amount,g.currency,g.bank_account_id,g.operation_date,g.statement_ref,g.active_statement_line,"
			. " l.rowid,l.status,l.is_duplicate,l.fk_native_bank_line,l.allocated_amount,l.amount,l.currency,l.operation_date,s.bank_account_id,s.ref,ba.currency_code";
	}
}
