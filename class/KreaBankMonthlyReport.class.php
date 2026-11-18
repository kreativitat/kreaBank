<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

require_once __DIR__ . '/kreabankintegration.class.php';
require_once __DIR__ . '/KreaBankPaymentBatchProjection.class.php';

/** Current, read-only evidence for a calendar-month reconciliation report. */
class KreaBankMonthlyReport
{
	const MAX_ROWS = 20000;
	private $db;
	private $entity;

	public function __construct($db, $entity)
	{
		$this->db = $db;
		$this->entity = (int) $entity;
		if ($this->entity <= 0 || PHP_INT_SIZE < 8) {
			throw new InvalidArgumentException('A company context and 64-bit PHP are required');
		}
	}

	/** SELECT only: no service initialization, matching, schema synchronization or writes. */
	public function load($accountId, $month, $confirmedBankBalance = '')
	{
		list($start, $end, $next) = $this->period($month);
		$accountId = (int) $accountId;
		if ($accountId <= 0) {
			throw new InvalidArgumentException('An owned bank account is required');
		}
		$p = $this->db->prefix();
		$e = $this->entity;
		$projection = new KreaBankIntegration($this->db, $e);
		$result = $projection->query("SELECT rowid,ref,label,currency_code,entity FROM {$p}bank_account WHERE rowid=$accountId AND entity=$e");
		$account = $this->db->fetch_object($result);
		if (!$account) {
			throw new InvalidArgumentException('Bank account is unavailable');
		}
		$opening = null;
		// Read independently of the execution service: reports already own their
		// account scope and must never install the optional migration schema.
		$openingSchema = $projection->query("SELECT COUNT(*) nb FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='" . $this->db->escape($p . 'kreabank_opening_balance') . "'");
		$openingSchemaRow = $this->db->fetch_object($openingSchema);
		if ((int) ($openingSchemaRow->nb ?? 0) === 1) {
			$openingResult = $projection->query("SELECT o.rowid,o.entity,o.fk_bank_account,o.start_date,o.currency,o.opening_balance,o.ledger_balance,o.adjustment_amount,o.fk_adjustment_bank_line,o.statement_ref"
				. " FROM {$p}kreabank_opening_balance o JOIN {$p}bank_account ba ON ba.rowid=o.fk_bank_account AND ba.entity=o.entity"
				. " WHERE o.entity=$e AND ba.rowid=$accountId");
			$openingRow = $this->db->fetch_object($openingResult);
			if ($openingRow) { $opening = (array) $openingRow; }
		}
		// Claims deliberately include future and foreign-account statements in this
		// company: they cannot make an already claimed movement safe by date filtering.
		$hasGroups = KreaBankPaymentBatchProjection::available($this->db);
		$claimRows = "SELECT l.fk_native_bank_line FROM {$p}kreabank_statement_line l"
			. " JOIN {$p}kreabank_statement s ON s.rowid=l.fk_statement AND s.entity=l.entity"
			. " WHERE l.entity=$e AND l.fk_native_bank_line IS NOT NULL AND l.is_duplicate=0";
		if ($hasGroups) {
			$claimRows .= " UNION ALL SELECT m.fk_bank_line FROM {$p}kreabank_payment_batch_line m"
				. " JOIN {$p}kreabank_payment_batch g ON g.rowid=m.fk_batch AND g.entity=m.entity"
				. " WHERE m.entity=$e AND g.status=1 AND g.active_statement_line IS NOT NULL AND m.active_bank_line IS NOT NULL";
		}
		$claims = 'SELECT claims.fk_native_bank_line,COUNT(*) claim_count FROM (' . $claimRows . ') claims GROUP BY claims.fk_native_bank_line';
		$source = '(' . $projection->bankStatementSql($accountId) . ') h';
		$bank = $this->rows($projection, 'SELECT h.*,n.dateo native_operation_date,COALESCE(c.claim_count,0) claim_count FROM ' . $source
			. " LEFT JOIN {$p}bank n ON n.rowid=h.native_id AND n.fk_account=h.bank_account_id"
			. " LEFT JOIN ($claims) c ON c.fk_native_bank_line=h.expected_native_id"
			. " WHERE h.operation_date<'$next' OR h.operation_date IS NULL OR h.operation_date='0000-00-00'"
			. ' ORDER BY h.operation_date,h.statement_id,h.line_rank,h.rowid');
		$native = $this->rows($projection, "SELECT b.rowid,b.fk_account bank_account_id,b.dateo operation_date,b.label description,b.amount,b.fk_type,b.rappro,b.num_releve,ba.currency_code currency,COALESCE(c.claim_count,0) claim_count"
			. " FROM {$p}bank b JOIN {$p}bank_account ba ON ba.rowid=b.fk_account AND ba.entity=$e"
			. " LEFT JOIN ($claims) c ON c.fk_native_bank_line=b.rowid WHERE ba.rowid=$accountId"
			. " AND (b.dateo<'$next' OR b.dateo IS NULL OR b.dateo='0000-00-00') ORDER BY b.dateo,b.rowid");
		if ($hasGroups) {
			// Read all components of in-scope sources, including future posting dates.
			// Foreign/moved native rows do not disclose their current account or values.
			$components = $this->rows($projection, "SELECT g.fk_statement_line,g.rowid group_id,m.fk_bank_line expected_native_id,"
				. " m.active_bank_line active_native_id,m.amount expected_amount,n.rowid,n.fk_account bank_account_id,n.dateo operation_date,"
				. " n.amount,n.rappro,n.num_releve,ba.currency_code currency,COALESCE(c.claim_count,0) claim_count"
				. " FROM {$p}kreabank_payment_batch g JOIN {$p}kreabank_statement_line l ON l.rowid=g.fk_statement_line AND l.entity=g.entity"
				. " JOIN {$p}kreabank_statement s ON s.rowid=l.fk_statement AND s.entity=l.entity"
				. " JOIN {$p}bank_account ba ON ba.rowid=s.bank_account_id AND ba.entity=l.entity AND ba.rowid=$accountId"
				. " LEFT JOIN {$p}kreabank_payment_batch_line m ON m.fk_batch=g.rowid AND m.entity=g.entity"
				. " LEFT JOIN {$p}bank n ON n.rowid=m.fk_bank_line AND n.fk_account=ba.rowid"
				. " LEFT JOIN ($claims) c ON c.fk_native_bank_line=m.fk_bank_line"
				. " WHERE g.entity=$e AND g.status=1 AND (l.operation_date<'$next' OR l.operation_date IS NULL OR l.operation_date='0000-00-00')"
				. ' ORDER BY g.fk_statement_line,m.fk_bank_line');
			$bySource = array();
			foreach ($components as $component) { $bySource[(int) $component['fk_statement_line']][] = $component; }
			foreach ($bank as &$row) {
				if (!empty($row['group_id'])) { $row['group_components'] = $bySource[(int) $row['rowid']] ?? array(); }
			}
			unset($row);
		}
		return $this->assemble((array) $account, $month, $bank, $native, $confirmedBankBalance, $opening);
	}

	private function rows($projection, $sql)
	{
		$result = $projection->query($sql . $this->db->plimit(self::MAX_ROWS + 1));
		$rows = array();
		while ($row = $this->db->fetch_object($result)) {
			$rows[] = (array) $row;
			if (count($rows) > self::MAX_ROWS) {
				throw new RuntimeException('Monthly report exceeds its complete-evidence limit');
			}
		}
		return $rows;
	}

	/** Pure assembly accepts the same rows returned by the scoped SQL loader. */
	public function assemble($account, $month, $bankRows, $nativeRows, $confirmedBankBalance = '', $opening = null)
	{
		list($start, $end, $next) = $this->period($month);
		$account = (array) $account;
		if (empty($account['rowid']) || empty($account['currency_code'])
			|| (isset($account['entity']) && (int) $account['entity'] !== $this->entity)) {
			throw new InvalidArgumentException('An owned bank account is required');
		}
		if (count($bankRows) > self::MAX_ROWS || count($nativeRows) > self::MAX_ROWS) {
			throw new RuntimeException('Monthly report exceeds its complete-evidence limit');
		}
		$confirmed = (string) $confirmedBankBalance !== '' ? $this->units($confirmedBankBalance) : null;
		$opening = $this->opening($opening, $account, $start);
		$issues = array_fill_keys(array('InvalidDates','InvalidAmounts','CurrencyMismatch','DuplicateEvidence','ConflictingLinks','ReconciliationFlags','UnreconciledNative'), 0);
		$bank = array();
		$native = array();
		$claims = array();
		$seen = array();
		$futureNative = array();
		$ledgerComplete = true;
		foreach ($bankRows as $raw) {
			$raw = (array) $raw;
			$id = (string) ($raw['source_storage'] ?? 'staging') . ':' . (int) ($raw['rowid'] ?? 0);
			if (isset($seen[$id])) {
				throw new RuntimeException('Duplicate report row identity');
			}
			$seen[$id] = true;
			$raw['_id'] = $id;
			if (empty($raw['is_duplicate']) && !empty($raw['expected_native_id'])) {
				$key = (int) $raw['expected_native_id'];
				$claims[$key] = ($claims[$key] ?? 0) + 1;
			}
			if (empty($raw['is_duplicate']) && !empty($raw['group_id'])) {
				foreach ((array) ($raw['group_components'] ?? array()) as $component) {
					$key = (int) ($component['expected_native_id'] ?? 0);
					if ($key > 0) { $claims[$key] = ($claims[$key] ?? 0) + 1; }
				}
			}
			$bank[] = $raw;
		}
		foreach ($nativeRows as $raw) {
			$raw = (array) $raw;
			$id = (int) ($raw['rowid'] ?? 0);
			if ($id <= 0 || isset($native[$id])) {
				throw new RuntimeException('Invalid native report row identity');
			}
			$raw['_date'] = $this->date($raw['operation_date'] ?? $raw['dateo'] ?? '');
			$raw['_units'] = $this->safeUnits($raw['amount'] ?? '', $issues);
			$raw['_valid'] = $this->validRow($raw, $account, $issues);
			if ($raw['_date'] !== '' && $raw['_date'] >= $next) {
				$futureNative[$id] = $raw;
				continue;
			}
			if (!$raw['_valid']) { $ledgerComplete = false; }
			$native[$id] = $raw;
		}
		// Native balances follow posting order, independently of the visible page
		// and of whether earlier movements are still open or already matched.
		uasort($native, static function ($a, $b) {
			return strcmp($a['_date'], $b['_date']) ?: ((int) $a['rowid'] <=> (int) $b['rowid']);
		});
		$openingValid = $opening === null || $this->openingLedgerValid($opening, $native);
		$covered = array();
		$openingCovered = array();
		$timingNative = array();
		$bankDetails = array();
		$bankPending = array();
		$bankSummary = $this->summary();
		$balanceRows = array();
		foreach ($bank as $raw) {
			$raw['_date'] = $this->date($raw['operation_date'] ?? '');
			$raw['_units'] = $this->safeUnits($raw['amount'] ?? '', $issues);
			$raw['_valid'] = $this->validRow($raw, $account, $issues);
			if ($raw['_date'] !== '' && $raw['_date'] >= $next) {
				continue;
			}
			$duplicate = !empty($raw['is_duplicate']);
			$historical = $opening !== null && $raw['_valid'] && $raw['_date'] < $opening['start_date'];
			if ($duplicate) {
				$issues['DuplicateEvidence']++;
			}
			$openingMatched = false;
			if (!empty($raw['group_id'])) {
				$group = $this->groupState($raw, $account, $native, $futureNative, $claims, $start, $next, $issues, $opening['start_date'] ?? null);
				$matched = !$duplicate && $group['matched'];
				$review = $group['review'];
				$openingMatched = $matched && $group['opening'];
				foreach ($group['ids'] as $id) {
					if ($matched) { $covered[$id] = true; }
					if ($openingMatched) { $openingCovered[$id] = true; }
					if (!$duplicate && $group['timing']) { $timingNative[$id] = true; }
				}
				$raw['native_ids'] = $group['ids'];
			} else {
				$nativeId = (int) ($raw['expected_native_id'] ?? $raw['native_id'] ?? 0);
				$n = $native[$nativeId] ?? null;
				$conflict = $nativeId > 0 && max($claims[$nativeId] ?? 0, (int) ($raw['claim_count'] ?? 0), (int) ($n['claim_count'] ?? 0)) > 1;
				if ($conflict) {
					$issues['ConflictingLinks']++;
				}
				$matched = !$duplicate && !$conflict && $raw['_valid'] && $n && $n['_valid']
					&& ((int) ($raw['status'] ?? 0) === 2 || ($raw['source_storage'] ?? '') === 'legacy_native') && (int) ($n['rappro'] ?? 0) === 1
					&& (int) ($raw['native_id'] ?? 0) === $nativeId
					&& $raw['_units'] === $n['_units']
					&& (string) ($raw['statement_ref'] ?? '') !== ''
					&& (string) ($raw['statement_ref'] ?? '') === (string) ($n['num_releve'] ?? '');
				// A valid native counterpart beyond cutoff is timing evidence, not a
				// broken match. The source remains open in this period.
				$review = !$raw['_valid'] || $conflict;
				$linkInvalid = $nativeId > 0 && (empty($raw['native_id'])
					|| (string) ($raw['native_currency'] ?? '') !== (string) $account['currency_code']
					|| $this->safeUnits($raw['native_amount'] ?? '', $issues) !== $raw['_units']);
				$futureDate = $this->date($raw['native_operation_date'] ?? ($futureNative[$nativeId]['_date'] ?? ''));
				$targetDate = $n['_date'] ?? $futureDate;
				if ($opening !== null && $nativeId > 0 && $raw['_date'] !== '' && $targetDate !== ''
					&& (($raw['_date'] < $opening['start_date']) !== ($targetDate < $opening['start_date']))) { $linkInvalid = true; }
				if ($nativeId > 0 && !$n && ($futureDate === '' || $futureDate < $next)) { $linkInvalid = true; }
				$flagsInvalid = (int) ($raw['status'] ?? 0) === 2 && ($nativeId <= 0 || $linkInvalid
					|| (int) ($raw['rappro'] ?? 0) !== 1 || (string) ($raw['num_releve'] ?? '') !== (string) ($raw['statement_ref'] ?? ''));
				// Opening application reconciles an existing native row with its
				// migration reference while deliberately preserving the staging status.
				$openingFlag = $historical && $openingValid && !$linkInvalid && !$conflict && $n && $n['_valid']
					&& $n['_date'] < $opening['start_date'] && $n['_units'] === $raw['_units']
					&& (int) ($raw['native_id'] ?? 0) === $nativeId && (int) ($n['rappro'] ?? 0) === 1
					&& (string) ($opening['statement_ref'] ?? '') !== ''
					&& (string) ($n['num_releve'] ?? '') === (string) $opening['statement_ref']
					&& (string) ($raw['num_releve'] ?? '') === (string) $opening['statement_ref'];
				if (($raw['source_storage'] ?? '') === 'staging' && (int) ($raw['status'] ?? 0) !== 2 && $nativeId > 0 && (int) ($raw['rappro'] ?? 0) === 1 && !$openingFlag) { $flagsInvalid = true; }
				if (!$duplicate && ($linkInvalid || $flagsInvalid || ((int) ($raw['status'] ?? 0) === 2 && $n && !$matched))) {
					$matched = false;
					$review = true;
					$issues['ReconciliationFlags']++;
				}
				if ($matched) {
					$covered[$nativeId] = true;
					if ($raw['_date'] < $start && $n['_date'] < $start) { $openingCovered[$nativeId] = true; }
				}
				$openingMatched = isset($openingCovered[$nativeId]);
			}
			// A migration declaration is not a source/native match. Keep raw link
			// integrity checks above, but accept intact historical evidence separately.
			$historical = $historical && !$duplicate && !$review;
			$accepted = $matched || $historical;
			$status = $duplicate ? 'duplicate' : ($historical ? 'opening' : ($matched ? 'matched' : ($review ? 'review' : 'pending')));
			$detail = $this->detail($raw, $raw['_id'], $status, $end, $start, (string) ($raw['source_storage'] ?? 'staging'), (int) ($raw['native_id'] ?? 0), $issues);
			if ($raw['_date'] === '' || $raw['_date'] >= $start || !$accepted) {
				$bankDetails[] = $detail;
			}
			if (!$duplicate && !$accepted) {
				$bankPending[] = $detail;
			}
			if (!$duplicate && $raw['_valid']) {
				$this->accumulate($bankSummary, $raw, $accepted, $start, $detail['age'], $openingMatched || $historical);
				if ($opening === null || $raw['_date'] >= $opening['start_date']) { $balanceRows[] = $raw; }
			}
		}
		$ledgerSummary = $this->summary();
		$ledgerDetails = array();
		$ledgerPending = array();
		$runningBalance = 0;
		$runningValid = true;
		foreach ($native as $id => $raw) {
			if (!$raw['_valid']) { $runningValid = false; }
			if ($runningValid) { $runningBalance = $this->add($runningBalance, $raw['_units']); }
			$raw['_balance_units'] = $runningValid ? $runningBalance : null;
			$matched = isset($covered[$id]);
			$historical = $opening !== null && $raw['_valid'] && $raw['_date'] < $opening['start_date'];
			$conflict = max((int) ($raw['claim_count'] ?? 0), $claims[$id] ?? 0) > 1;
			if ($conflict) { $issues['ConflictingLinks']++; }
			$review = !$raw['_valid'] || $conflict || (!$matched && !$historical && (int) ($raw['rappro'] ?? 0) === 1 && !isset($timingNative[$id]));
			if (!$matched && !$historical && (int) ($raw['rappro'] ?? 0) === 1 && !isset($timingNative[$id])) {
				$issues['UnreconciledNative']++;
			}
			$historical = $historical && !$review;
			$accepted = $matched || $historical;
			$detail = $this->detail($raw, 'native:' . $id, $historical ? 'opening' : ($matched ? 'matched' : ($review ? 'review' : 'pending')), $end, $start, 'native', $id, $issues);
			if ($raw['_date'] === '' || $raw['_date'] >= $start || !$accepted) {
				$ledgerDetails[] = $detail;
			}
			if (!$accepted) {
				$ledgerPending[] = $detail;
			}
			if ($raw['_valid']) {
				$this->accumulate($ledgerSummary, $raw, $accepted, $start, $detail['age'], isset($openingCovered[$id]) || $historical);
			}
		}
		$balance = $this->balances($balanceRows, $start, $issues, $opening);
		$sourceIncomplete = $issues['InvalidDates'] + $issues['InvalidAmounts'] + $issues['CurrencyMismatch'] + $issues['DuplicateEvidence'];
		$bankClose = $confirmed !== null ? $confirmed : (!$sourceIncomplete ? $balance['close'] : null);
		$bankOpen = $bankClose !== null ? $this->add($bankClose, -$bankSummary['movement']) : null;
		if ($opening !== null) {
			$bankOpen = null;
			if (!$sourceIncomplete && ($start === $opening['start_date'] || count($balanceRows) > 0)) {
				$bankOpen = $opening['_units'];
				foreach ($balanceRows as $raw) { if ($raw['_date'] < $start) { $bankOpen = $this->add($bankOpen, $raw['_units']); } }
			}
		}
		$bankSummary['opening'] = $bankOpen;
		$bankSummary['closing'] = $bankClose;
		$ledgerSummary['closing'] = $this->add($ledgerSummary['opening'], $ledgerSummary['movement']);
		$bankAdjusted = $bankClose !== null ? $this->add($bankClose, $ledgerSummary['pending_amount']) : null;
		$ledgerAdjusted = $this->add($ledgerSummary['closing'], $bankSummary['pending_amount']);
		$blocked = $sourceIncomplete + $issues['ConflictingLinks'] + $issues['ReconciliationFlags'] + ($balance['chain'] ? 0 : 1) + ($openingValid ? 0 : 1);
		$difference = !$blocked && $bankAdjusted !== null ? $this->add($bankAdjusted, -$ledgerAdjusted) : null;
		$openingDifference = !$blocked && $bankOpen !== null ? $this->add($this->add($bankOpen, $ledgerSummary['_prior_pending']), -$this->add($ledgerSummary['opening'], $bankSummary['_prior_pending'])) : null;
		if (!$ledgerComplete) { $ledgerSummary['opening'] = null; $ledgerSummary['closing'] = null; $ledgerAdjusted = null; $bankAdjusted = null; }
		$checks = array(array('key'=>'CurrentEvidence','status'=>'warning','value'=>''));
		if ($opening !== null) { $checks[] = array('key'=>'HistoricalOpening','status'=>$openingValid ? 'ok' : 'error','value'=>$opening['start_date']); }
		foreach ($issues as $key => $value) {
			$checks[] = array('key'=>$key,'status'=>$value ? ($key === 'UnreconciledNative' ? 'warning' : 'error') : 'ok','value'=>(string) $value);
		}
		$checks[] = array('key'=>'BankRows','status'=>'ok','value'=>(string) count($bankDetails));
		$checks[] = array('key'=>'LedgerRows','status'=>'ok','value'=>(string) count($ledgerDetails));
		$checks[] = array('key'=>'BalanceOrder','status'=>$balance['order'] ? 'ok' : 'warning','value'=>'');
		$checks[] = array('key'=>'BalanceChain','status'=>$balance['chain'] ? 'ok' : 'error','value'=>'');
		$checks[] = array('key'=>'BankBalance','status'=>$bankClose !== null ? 'ok' : 'warning','value'=>$bankClose !== null ? $this->decimal($bankClose) : '');
		$checks[] = array('key'=>'BalanceConfirmed','status'=>$confirmed !== null ? 'ok' : 'warning','value'=>$confirmed !== null ? $end : ($balance['date'] ?: ''));
		foreach (array('Bank'=>$bankSummary,'Ledger'=>$ledgerSummary) as $name=>$side) {
			$checks[] = array('key'=>'Missing' . $name . 'Period','status'=>$side['count'] ? 'ok' : 'warning','value'=>(string) $side['count']);
		}
		$checks[] = array('key'=>'OpeningDifference','status'=>$openingDifference === null ? 'warning' : ($openingDifference === 0 ? 'ok' : 'error'),'value'=>$openingDifference !== null ? $this->decimal($openingDifference) : '');
		$checks[] = array('key'=>'UnexplainedDifference','status'=>$difference === null ? 'warning' : ($difference === 0 ? 'ok' : 'error'),'value'=>$difference !== null ? $this->decimal($difference) : '');
		$checks[] = array('key'=>'AgedItems','status'=>$bankSummary['over90'] + $ledgerSummary['over90'] ? 'warning' : 'ok','value'=>(string) ($bankSummary['over90'] + $ledgerSummary['over90']));
		$report = array('account'=>array_intersect_key($account, array_flip(array('rowid','ref','label','currency_code'))), 'month'=>$month,'start'=>$start,'end'=>$end,'generated_at'=>gmdate('Y-m-d H:i:s'),
			'bank'=>$this->formatSummary($bankSummary),'ledger'=>$this->formatSummary($ledgerSummary),
			'map'=>array('bank_close'=>$this->nullableDecimal($bankClose),'ledger_close'=>$this->nullableDecimal($ledgerSummary['closing']),'bank_adjusted'=>$this->nullableDecimal($bankAdjusted),'ledger_adjusted'=>$this->nullableDecimal($ledgerAdjusted),'difference'=>$this->nullableDecimal($difference),'opening_difference'=>$this->nullableDecimal($openingDifference),'month_difference'=>$difference !== null && $openingDifference !== null ? $this->decimal($this->add($difference, -$openingDifference)) : null,'balance_date'=>$confirmed !== null ? $end : $balance['date'],'balance_confirmed'=>$confirmed !== null),
			'checks'=>$checks,'bank_rows'=>$bankDetails,'ledger_rows'=>$ledgerDetails,'bank_pending'=>$bankPending,'ledger_pending'=>$ledgerPending);
		if ($opening !== null) {
			$report['map'] += array('opening_date'=>$opening['start_date'],'opening_balance'=>$this->decimal($opening['_units']),
				'opening_valid'=>$openingValid,'balance_basis'=>$confirmed !== null ? 'confirmed' : 'opening_projection');
		}
		return $report;
	}

	/** The persisted boundary is company/account/currency specific, never global. */
	private function opening($opening, $account, $reportStart)
	{
		if ($opening === null) { return null; }
		$opening = (array) $opening;
		if ((int) ($opening['entity'] ?? 0) !== $this->entity
			|| (int) ($opening['fk_bank_account'] ?? 0) !== (int) $account['rowid']
			|| (string) ($opening['currency'] ?? '') !== (string) $account['currency_code']) {
			throw new InvalidArgumentException('Historical opening belongs to another company, account or currency');
		}
		$date = (string) ($opening['start_date'] ?? '');
		if ($date === '' || $this->date($date) !== $date) { throw new InvalidArgumentException('Historical opening date is invalid'); }
		// A monthly report that straddles or precedes the boundary retains its
		// ordinary evidence contract; it cannot claim a month-start opening.
		if ($reportStart < $date) { return null; }
		$opening['_units'] = $this->units($opening['opening_balance'] ?? '');
		$opening['_ledger_units'] = $this->units($opening['ledger_balance'] ?? '');
		$opening['_adjustment_units'] = $this->units($opening['adjustment_amount'] ?? '');
		if ($this->add($opening['_ledger_units'], $opening['_adjustment_units']) !== $opening['_units']
			|| (($opening['_adjustment_units'] !== 0) !== ((int) ($opening['fk_adjustment_bank_line'] ?? 0) > 0))) {
			throw new InvalidArgumentException('Historical opening amounts or adjustment identity disagree');
		}
		return $opening;
	}

	/** Later edits to the established opening must stay visible, never be rebased. */
	private function openingLedgerValid($opening, $native)
	{
		$sum = 0;
		foreach ($native as $raw) {
			if ($raw['_date'] === '' || !$raw['_valid']) { return false; }
			if ($raw['_date'] >= $opening['start_date']) { continue; }
			if ((int) ($raw['rappro'] ?? 0) !== 1) { return false; }
			$sum = $this->add($sum, $raw['_units']);
		}
		if ($sum !== $opening['_units']) { return false; }
		$id = (int) ($opening['fk_adjustment_bank_line'] ?? 0);
		if ($id <= 0) { return true; }
		$row = $native[$id] ?? null;
		$previous = (new DateTimeImmutable($opening['start_date'], new DateTimeZone('UTC')))->modify('-1 day')->format('Y-m-d');
		return $row && $row['_valid'] && $row['_date'] === $previous
			&& $row['_units'] === $opening['_adjustment_units'] && (string) ($row['fk_type'] ?? '') === 'SOLD'
			&& (int) ($row['rappro'] ?? 0) === 1
			&& (string) ($row['num_releve'] ?? '') === (string) ($opening['statement_ref'] ?? '')
			&& (string) ($opening['statement_ref'] ?? '') !== '';
	}

	/** Verify the whole group against its actual members; a future leg is timing evidence. */
	private function groupState($raw, $account, $native, $futureNative, $claims, $start, $next, &$issues, $openingDate = null)
	{
		$components = (array) ($raw['group_components'] ?? array());
		$valid = $raw['_valid'] && (int) ($raw['status'] ?? 0) === 2 && (int) ($raw['group_valid'] ?? 0) === 1
			&& (int) ($raw['group_count'] ?? 0) >= 2 && count($components) === (int) $raw['group_count']
			&& empty($raw['native_id']) && empty($raw['expected_native_id'])
			&& (string) ($raw['statement_ref'] ?? '') !== '' && (string) ($raw['num_releve'] ?? '') === (string) $raw['statement_ref']
			&& (int) ($raw['rappro'] ?? 0) === 1 && $raw['_units'] !== null && $raw['_units'] !== 0;
		$sum = 0; $ids = array(); $conflict = false; $future = false; $opening = $raw['_date'] < $start;
		foreach ($components as $component) {
			$component = (array) $component;
			$id = (int) ($component['expected_native_id'] ?? 0);
			if ($id <= 0 || (int) ($component['rowid'] ?? 0) !== $id
				|| (int) ($component['bank_account_id'] ?? 0) !== (int) $account['rowid']) {
				$valid = false;
				continue;
			}
			$ids[] = $id;
			$known = $native[$id] ?? $futureNative[$id] ?? null;
			if (max((int) ($component['claim_count'] ?? 0), $claims[$id] ?? 0, (int) ($known['claim_count'] ?? 0)) > 1) { $conflict = true; }
			$date = $this->date($component['operation_date'] ?? '');
			if ($date === '') { $valid = false; }
			if ($openingDate !== null && $date !== '' && (($raw['_date'] < $openingDate) !== ($date < $openingDate))) { $valid = false; }
			if ($date >= $next) { $future = true; }
			if ($date === '' || $date >= $start) { $opening = false; }
			try {
				$units = $this->units($component['amount'] ?? '');
				$expected = $this->units($component['expected_amount'] ?? '');
				$sum = $this->add($sum, $units);
			} catch (InvalidArgumentException $e) {
				$valid = false;
				continue;
			}
			if ($units !== $expected || $units === 0 || ($units < 0) !== ($raw['_units'] < 0)
				|| (string) ($component['currency'] ?? '') !== (string) $account['currency_code']
				|| (int) ($component['active_native_id'] ?? 0) !== $id
				|| (int) ($component['group_id'] ?? 0) !== (int) $raw['group_id']
				|| (int) ($component['rappro'] ?? 0) !== 1 || (string) ($component['num_releve'] ?? '') !== (string) $raw['statement_ref']) {
				$valid = false;
			}
			if ($known) {
				if (!$known['_valid'] || $known['_units'] !== $units || $known['_date'] !== $date
					|| (int) ($known['rappro'] ?? 0) !== 1 || (string) ($known['num_releve'] ?? '') !== (string) $raw['statement_ref']) { $valid = false; }
			} elseif ($date === '' || $date < $next) {
				// Every current-period component must also be present in the complete ledger.
				$valid = false;
			}
		}
		if ($sum !== $raw['_units'] || count(array_unique($ids)) !== count($components)) { $valid = false; }
		try { if ($this->units($raw['native_amount'] ?? '') !== $sum) { $valid = false; } }
		catch (InvalidArgumentException $e) { $valid = false; }
		if ($conflict) { $issues['ConflictingLinks']++; }
		$review = !$valid || $conflict;
		if ($review && empty($raw['is_duplicate'])) { $issues['ReconciliationFlags']++; }
		return array('matched'=>$valid && !$conflict && !$future,'review'=>$review,'ids'=>array_values(array_unique($ids)),
			'opening'=>$opening,'timing'=>$valid && !$conflict && $future);
	}

	private function validRow($raw, $account, &$issues)
	{
		$valid = $raw['_units'] !== null;
		if ($raw['_date'] === '') { $issues['InvalidDates']++; $valid = false; }
		if ((string) ($raw['currency'] ?? '') !== (string) $account['currency_code']) { $issues['CurrencyMismatch']++; $valid = false; }
		if ((int) ($raw['bank_account_id'] ?? 0) !== (int) $account['rowid']) {
			throw new InvalidArgumentException('Report evidence belongs to another bank account');
		}
		return $valid;
	}

	private function detail($raw, $id, $status, $end, $start, $storage, $nativeId, &$issues)
	{
		$date = $raw['_date'];
		$age = $date !== '' ? max(0, (int) ((strtotime($end . ' UTC') - strtotime($date . ' UTC')) / 86400)) : 0;
		$balance = $raw['_balance_units'] ?? null;
		if (isset($raw['running_balance']) && $raw['running_balance'] !== '') { $balance = $this->safeUnits($raw['running_balance'], $issues); }
		return array('id'=>$id,'rowid'=>(int) $raw['rowid'],'date'=>$date,'description'=>(string) ($raw['description'] ?? ''),'reference'=>(string) (($raw['payment_reference'] ?? '') ?: ($raw['bank_reference'] ?? '') ?: ($raw['num_releve'] ?? '')),'amount'=>$this->nullableDecimal($raw['_units']),'currency'=>(string) ($raw['currency'] ?? ''),'status'=>$status,'age'=>$age,'carried'=>$date !== '' && $date < $start,'native_id'=>$nativeId,'source_storage'=>$storage,'balance'=>$this->nullableDecimal($balance))
			+ (!empty($raw['group_id']) ? array('group_id'=>(int) $raw['group_id'],'native_ids'=>$raw['native_ids'] ?? array()) : array());
	}

	private function summary()
	{
		return array('count'=>0,'matched'=>0,'pending'=>0,'pending_amount'=>0,'over30'=>0,'over90'=>0,'opening'=>0,'movement'=>0,'closing'=>0,'_prior_pending'=>0);
	}

	private function accumulate(&$summary, $raw, $matched, $start, $age, $openingMatched)
	{
		$prior = $raw['_date'] < $start;
		$key = $prior ? 'opening' : 'movement';
		$summary[$key] = $this->add($summary[$key], $raw['_units']);
		if (!$prior) { $summary['count']++; if ($matched) { $summary['matched']++; } }
		if ($prior && !$openingMatched) { $summary['_prior_pending'] = $this->add($summary['_prior_pending'], $raw['_units']); }
		if (!$matched) {
			$summary['pending']++;
			$summary['pending_amount'] = $this->add($summary['pending_amount'], $raw['_units']);
			if ($age > 30) { $summary['over30']++; }
			if ($age > 90) { $summary['over90']++; }
		}
	}

	/** Supplied balances must share one exact chain and an unambiguous daily order. */
	private function balances($rows, $start, &$issues, $opening = null)
	{
		usort($rows, static function ($a, $b) {
			return strcmp($a['_date'], $b['_date']) ?: ((int) ($a['statement_id'] ?? 0) <=> (int) ($b['statement_id'] ?? 0)) ?: ((int) ($a['line_rank'] ?? 0) <=> (int) ($b['line_rank'] ?? 0)) ?: ((int) $a['rowid'] <=> (int) $b['rowid']);
		});
		$groups = array();
		foreach ($rows as $row) { $groups[$row['_date']][] = $row; }
		$order = true;
		$ordered = array();
		foreach ($groups as $group) {
			if (count($group) > 1) {
				// With an explicit opening, unordered same-day amounts still have an
				// exact closing sum. Supplied per-row balances require their real order.
				$hasBalances = count(array_filter($group, static function ($row) { return isset($row['running_balance']) && $row['running_balance'] !== ''; })) > 0;
				$sequences = array(); $statements = array(); $ranks = array();
				foreach ($group as $row) { $sequences[] = $row['statement_sequence'] ?? null; $statements[] = (int) ($row['statement_id'] ?? 0); $ranks[] = (int) ($row['line_rank'] ?? 0); }
				$refs = array_map(static function ($row) { return (string) ($row['evidence_statement_ref'] ?? ''); }, $group);
				if (!in_array(null, $sequences, true) && count(array_unique($sequences)) === count($group) && count(array_unique($refs)) === 1 && $refs[0] !== '') {
					usort($group, static function ($a, $b) { return (int) $a['statement_sequence'] <=> (int) $b['statement_sequence']; });
				} elseif (($opening === null || $hasBalances) && (count(array_unique($statements)) !== 1 || !$statements[0] || in_array(0, $ranks, true) || count(array_unique($ranks)) !== count($group)
					|| count(array_filter($group, static function ($row) { return isset($row['running_balance']) && $row['running_balance'] !== ''; })) !== count($group))) {
					$order = false;
				}
			}
			foreach ($group as $row) { $ordered[] = $row; }
		}
		$sum = 0; $base = $opening !== null ? $opening['_units'] : null; $date = null; $chain = true;
		if ($opening !== null) { $date = (new DateTimeImmutable($opening['start_date'], new DateTimeZone('UTC')))->modify('-1 day')->format('Y-m-d'); }
		foreach ($ordered as $row) {
			$sum = $this->add($sum, $row['_units']);
			if (isset($row['running_balance']) && $row['running_balance'] !== '') {
				$value = $this->safeUnits($row['running_balance'], $issues);
				if ($value === null) { $chain = false; continue; }
				$candidate = $this->add($value, -$sum);
				if ($base !== null && $base !== $candidate) { $chain = false; }
				$base = $candidate; $date = $row['_date'];
			}
		}
		return array('close'=>$base !== null && $order && $chain && ($opening === null || count($rows) > 0) ? $this->add($base, $sum) : null,'date'=>$date,'order'=>$order,'chain'=>$chain);
	}

	private function formatSummary($summary)
	{
		unset($summary['_prior_pending']);
		foreach (array('pending_amount','opening','movement','closing') as $key) { $summary[$key] = $this->nullableDecimal($summary[$key]); }
		return $summary;
	}

	private function period($month)
	{
		if (!is_string($month) || !preg_match('/^[1-9][0-9]{3}-(0[1-9]|1[0-2])$/D', $month)) { throw new InvalidArgumentException('Month must use YYYY-MM'); }
		$date = new DateTimeImmutable($month . '-01', new DateTimeZone('UTC'));
		return array($date->format('Y-m-d'),$date->modify('last day of this month')->format('Y-m-d'),$date->modify('+1 month')->format('Y-m-d'));
	}

	private function date($value)
	{
		$value = (string) $value;
		if (!preg_match('/^([1-9][0-9]{3})-([0-9]{2})-([0-9]{2})(?: (?:[01][0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9])?$/D', $value, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) { return ''; }
		return substr($value, 0, 10);
	}

	private function safeUnits($value, &$issues)
	{
		try { return $this->units($value); } catch (InvalidArgumentException $e) { $issues['InvalidAmounts']++; return null; }
	}

	/** Eight-place fixed point; reject values outside PHP's exact signed range. */
	private function units($value)
	{
		if (!is_string($value) && !is_int($value)) { throw new InvalidArgumentException('Amount must be an exact decimal string'); }
		if (!preg_match('/^(-?)([0-9]+)(?:\.([0-9]{1,8}))?$/D', (string) $value, $m)) { throw new InvalidArgumentException('Amount must be a dot decimal with at most eight places'); }
		$digits = ltrim($m[2] . str_pad($m[3] ?? '', 8, '0'), '0');
		if ($digits === '') { return 0; }
		$max = (string) PHP_INT_MAX;
		if (strlen($digits) > strlen($max) || (strlen($digits) === strlen($max) && strcmp($digits, $max) > 0)) { throw new OverflowException('Monthly report amount exceeds exact arithmetic range'); }
		return ($m[1] === '-' ? -1 : 1) * (int) $digits;
	}

	private function add($a, $b)
	{
		if (($b > 0 && $a > PHP_INT_MAX - $b) || ($b < 0 && $a < -PHP_INT_MAX - $b)) { throw new OverflowException('Monthly report total exceeds exact arithmetic range'); }
		return $a + $b;
	}

	private function decimal($value)
	{
		return ($value < 0 ? '-' : '') . intdiv(abs($value), 100000000) . '.' . str_pad((string) (abs($value) % 100000000), 8, '0', STR_PAD_LEFT);
	}

	private function nullableDecimal($value)
	{
		return $value === null ? null : $this->decimal($value);
	}
}
