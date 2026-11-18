<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

require_once __DIR__ . '/KreaBankDecimal.class.php';
require_once __DIR__ . '/KreaBankPaymentBatchProjection.class.php';

/** Explicit, immutable opening reconciliation. Construction and reads never install schema. */
class KreaBankOpeningBalance
{
	const MAX_ROWS = 25000;
	const REVIEW_TTL = 900;
	private $db;
	private $user;
	private $entity;
	private static $schemaCache;

	public function __construct($db, $user, $entity)
	{
		$this->db = $db;
		$this->user = $user;
		$this->entity = (int) $entity;
	}

	public function available()
	{
		$this->assertContext();
		return self::schemaAvailable($this->db, true);
	}

	/** DDL is intentionally restricted to an explicit administrator installation action. */
	public function install()
	{
		$this->assertWrite();
		if ((int) ($this->db->transaction_opened ?? $this->db->depth ?? 0) > 0) {
			throw new DomainException('KreaBankOpeningDatabaseError');
		}
		$this->query('CREATE TABLE IF NOT EXISTS ' . $this->db->prefix() . 'kreabank_opening_balance ('
			. 'rowid INTEGER AUTO_INCREMENT PRIMARY KEY,entity INTEGER NOT NULL,fk_bank_account INTEGER NOT NULL,'
			. 'start_date DATE NOT NULL,currency VARCHAR(3) NOT NULL,opening_balance DECIMAL(24,8) NOT NULL,'
			. 'ledger_balance DECIMAL(24,8) NOT NULL,adjustment_amount DECIMAL(24,8) NOT NULL,fk_adjustment_bank_line INTEGER NULL,'
			. 'statement_ref VARCHAR(50) NOT NULL,evidence_ref VARCHAR(255) NOT NULL,fk_user INTEGER NOT NULL,datec DATETIME NOT NULL,'
			. 'facts_hash CHAR(64) NOT NULL,payload_json LONGTEXT NOT NULL,'
			. 'UNIQUE KEY uk_kreabank_opening_account (entity,fk_bank_account)) ENGINE=innodb');
		if (!$this->available()) { throw new RuntimeException('KreaBankOpeningSchemaRequired'); }
	}

	/** Applied openings only, scoped through directly owned native accounts. */
	public function all()
	{
		$this->assertRead();
		if (!$this->available()) { return array(); }
		$p = $this->db->prefix();
		return $this->rows("SELECT o.*,ba.ref account_ref,ba.label account_label FROM {$p}kreabank_opening_balance o"
			. " JOIN {$p}bank_account ba ON ba.rowid=o.fk_bank_account AND ba.entity=o.entity"
			. ' WHERE o.entity=' . $this->entity . ' ORDER BY ba.ref,ba.rowid');
	}

	public function get($accountId)
	{
		$this->assertRead();
		if (!$this->available()) { return null; }
		$p = $this->db->prefix();
		$rows = $this->rows("SELECT o.*,ba.ref account_ref,ba.label account_label FROM {$p}kreabank_opening_balance o"
			. " JOIN {$p}bank_account ba ON ba.rowid=o.fk_bank_account AND ba.entity=o.entity"
			. ' WHERE o.entity=' . $this->entity . ' AND o.fk_bank_account=' . (int) $accountId);
		return $rows ? $rows[0] : null;
	}

	/** Safe SQL fragment for read projections and execution guards; identifiers come from source, never requests. */
	public static function historicalSql($db, $entity, $accountExpression, $dateExpression, $currencyExpression)
	{
		$entity = (int) $entity;
		if ($entity <= 0) { throw new InvalidArgumentException('KreaBankOpeningPermissions'); }
		foreach (array($accountExpression, $dateExpression, $currencyExpression) as $identifier) {
			$column = '[a-z_][a-z0-9_]*(?:\.[a-z_][a-z0-9_]*)?';
			if (!is_string($identifier) || (!preg_match('/^' . $column . '$/iD', $identifier)
				&& !preg_match('/^COALESCE\(' . $column . ',' . $column . '\)$/iD', $identifier))) {
				throw new InvalidArgumentException('KreaBankOpeningDatabaseError');
			}
		}
		if (!self::schemaAvailable($db)) { return '0=1'; }
		$p = $db->prefix();
		return "EXISTS (SELECT 1 FROM {$p}kreabank_opening_balance kbo JOIN {$p}bank_account kboa"
			. " ON kboa.rowid=kbo.fk_bank_account AND kboa.entity=kbo.entity WHERE kbo.entity=$entity"
			. " AND kbo.fk_bank_account=$accountExpression AND kbo.currency=$currencyExpression"
			. " AND kboa.currency_code=kbo.currency AND $dateExpression IS NOT NULL"
			. " AND $dateExpression>'0000-00-00' AND $dateExpression<kbo.start_date)";
	}

	public static function validateDate($value, $today = null)
	{
		if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) {
			throw new InvalidArgumentException('KreaBankOpeningInvalidDate');
		}
		$date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
		$today = $today === null ? gmdate('Y-m-d') : $today;
		if (!$date || $date->format('Y-m-d') !== $value || $value < '1900-01-02' || $value > $today) {
			throw new InvalidArgumentException('KreaBankOpeningInvalidDate');
		}
		return $value;
	}

	public static function decimal($value)
	{
		try { $value = KreaBankDecimal::normalize($value); }
		catch (Throwable $e) { throw new InvalidArgumentException('KreaBankOpeningInvalidAmount'); }
		if (!preg_match('/^-?\d{1,16}(?:\.\d{1,8})?$/D', $value)) {
			throw new InvalidArgumentException('KreaBankOpeningInvalidAmount');
		}
		return $value;
	}

	/** Returned review belongs in the actor's server-side session, never in an editable POST payload. */
	public function preview($accountId, $startDate, $officialBalance, $statementRef, $evidenceRef)
	{
		return $this->prepare($accountId, $startDate, $officialBalance, $statementRef, $evidenceRef, false);
	}

	/** Confirm exactly the saved preview under the same account lock used by reconciliation. */
	public function apply($review)
	{
		$this->assertWrite();
		if (!is_array($review) || (int) ($review['entity'] ?? 0) !== $this->entity
			|| (int) ($review['actor_id'] ?? 0) !== (int) $this->user->id
			|| !is_int($review['issued_at'] ?? null) || !is_int($review['expires_at'] ?? null)
			|| $review['issued_at'] > time() || $review['expires_at'] < time()
			|| $review['expires_at'] !== $review['issued_at'] + self::REVIEW_TTL
			|| !is_string($review['facts_hash'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $review['facts_hash'])) {
			throw new DomainException('KreaBankOpeningReviewChanged');
		}
		if (!$this->available()) { throw new DomainException('KreaBankOpeningSchemaRequired'); }
		$this->assertTransactionalTables();
		if ((int) ($this->db->transaction_opened ?? $this->db->depth ?? 0) > 0 || !$this->db->begin()) {
			throw new RuntimeException('KreaBankOpeningDatabaseError');
		}
		try {
			$this->account((int) ($review['account_id'] ?? 0), true);
			$existing = $this->get((int) ($review['account_id'] ?? 0));
			if ($existing) {
				if (!hash_equals((string) $existing['facts_hash'], $review['facts_hash'])) { throw new DomainException('KreaBankOpeningAlreadyApplied'); }
				if (!$this->db->commit()) { throw new RuntimeException('KreaBankOpeningDatabaseError'); }
				return $existing;
			}
			$fresh = $this->prepare((int) ($review['account_id'] ?? 0), $review['start_date'] ?? '', $review['opening_balance'] ?? '',
				$review['statement_ref'] ?? '', $review['evidence_ref'] ?? '', true);
			if (!hash_equals($fresh['facts_hash'], $review['facts_hash'])) { throw new DomainException('KreaBankOpeningReviewChanged'); }
			require_once DOL_DOCUMENT_ROOT . '/compta/bank/class/account.class.php';
			foreach ($fresh['native_rows'] as $row) {
				if ((int) $row['rappro'] !== 0) { continue; }
				$native = new AccountLine($this->db);
				$native->id = (int) $row['rowid'];
				$native->num_releve = $fresh['statement_ref'];
				if ($native->update_conciliation($this->user, 0, 1) <= 0) { throw new RuntimeException('KreaBankOpeningNativeWriteFailed'); }
			}
			$adjustmentId = 0;
			if ($fresh['adjustment_amount'] !== '0') {
				$native = new AccountLine($this->db);
				$native->datec = time();
				$native->dateo = (new DateTimeImmutable($fresh['balance_date'] . ' 12:00:00', new DateTimeZone('UTC')))->getTimestamp();
				$native->datev = $native->dateo;
				$native->fk_account = $fresh['account_id'];
				$native->fk_user_author = (int) $this->user->id;
				$native->label = 'KreaBank opening balance ' . $fresh['start_date'];
				$native->amount = $fresh['adjustment_amount'];
				$native->fk_type = 'SOLD';
				$native->note_private = $fresh['evidence_ref'];
				if ($native->insert() <= 0 || (int) $native->id <= 0) { throw new RuntimeException('KreaBankOpeningNativeWriteFailed'); }
				$adjustmentId = (int) $native->id;
				$native->num_releve = $fresh['statement_ref'];
				if ($native->update_conciliation($this->user, 0, 1) <= 0) { throw new RuntimeException('KreaBankOpeningNativeWriteFailed'); }
				$saved = $this->rows('SELECT fk_account,dateo,CAST(amount AS DECIMAL(24,8)) amount,fk_type,rappro,num_releve FROM '
					. $this->db->prefix() . 'bank WHERE rowid=' . $adjustmentId);
				if (count($saved) !== 1 || (int) $saved[0]['fk_account'] !== $fresh['account_id'] || $saved[0]['dateo'] !== $fresh['balance_date']
					|| self::decimal($saved[0]['amount']) !== $fresh['adjustment_amount'] || $saved[0]['fk_type'] !== 'SOLD'
					|| (int) $saved[0]['rappro'] !== 1 || $saved[0]['num_releve'] !== $fresh['statement_ref']) {
					throw new RuntimeException('KreaBankOpeningPrecisionMismatch');
				}
			}
			$p = $this->db->prefix();
			$sum = $this->rows("SELECT COALESCE(SUM(CAST(amount AS DECIMAL(24,8))),0) amount FROM {$p}bank WHERE fk_account="
				. $fresh['account_id'] . ' AND dateo<' . $this->text($fresh['start_date']));
			if (self::decimal($sum[0]['amount']) !== $fresh['opening_balance']) { throw new RuntimeException('KreaBankOpeningPrecisionMismatch'); }
			$payload = $fresh;
			$payload['fk_adjustment_bank_line'] = $adjustmentId ?: null;
			$payload['version'] = 1;
			// Keep SQL transport ASCII-safe on existing Latin1 columns; the review/facts hash retains its original encoding contract.
			$json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
			$createdAt = $this->db->idate(time());
			$this->query("INSERT INTO {$p}kreabank_opening_balance (entity,fk_bank_account,start_date,currency,opening_balance,ledger_balance,adjustment_amount,"
				. 'fk_adjustment_bank_line,statement_ref,evidence_ref,fk_user,datec,facts_hash,payload_json) VALUES ('
				. $this->entity . ',' . $fresh['account_id'] . ',' . $this->text($fresh['start_date']) . ',' . $this->text($fresh['currency']) . ','
				. $this->text($fresh['opening_balance']) . ',' . $this->text($fresh['ledger_balance']) . ',' . $this->text($fresh['adjustment_amount']) . ','
				. ($adjustmentId ?: 'NULL') . ',' . $this->text($fresh['statement_ref']) . ',' . $this->text($fresh['evidence_ref']) . ','
				. (int) $this->user->id . ',' . $this->text($createdAt) . ',' . $this->text($fresh['facts_hash']) . ',' . $this->text($json) . ')');
			$id = (int) $this->db->last_insert_id($p . 'kreabank_opening_balance');
			if ($id <= 0) { throw new RuntimeException('KreaBankOpeningDatabaseError'); }
			$this->query("INSERT INTO {$p}kreabank_recon_audit (entity,audit_type,payload_json,fk_user,datec) VALUES ("
				. $this->entity . ",'opening_reconciliation'," . $this->text($json) . ',' . (int) $this->user->id . ',' . $this->text($createdAt) . ')');
			$auditId = (int) $this->db->last_insert_id($p . 'kreabank_recon_audit');
			$result = $this->get($fresh['account_id']);
			$this->assertPersistedOpening($result, $fresh, $id, $adjustmentId, $auditId, $createdAt, $json);
			if (!$this->db->commit()) { throw new RuntimeException('KreaBankOpeningDatabaseError'); }
			return $result;
		} catch (Throwable $e) {
			$this->db->rollback();
			throw $e;
		}
	}

	/** Native writes must roll back if storage changes any approved declaration or audit evidence. */
	private function assertPersistedOpening($saved, $review, $id, $adjustmentId, $auditId, $createdAt, $json)
	{
		if (!$saved || $auditId <= 0) { throw new RuntimeException('KreaBankOpeningDatabaseError'); }
		foreach (array('rowid'=>$id, 'entity'=>$this->entity, 'fk_bank_account'=>$review['account_id'], 'fk_user'=>(int) $this->user->id) as $key=>$expected) {
			if (!isset($saved[$key]) || (int) $saved[$key] !== $expected) { throw new RuntimeException('KreaBankOpeningDatabaseError'); }
		}
		foreach (array('start_date','currency','statement_ref','evidence_ref','facts_hash') as $key) {
			if (!isset($saved[$key]) || $saved[$key] !== $review[$key]) { throw new RuntimeException('KreaBankOpeningDatabaseError'); }
		}
		foreach (array('opening_balance','ledger_balance','adjustment_amount') as $key) {
			if (!isset($saved[$key]) || self::decimal($saved[$key]) !== $review[$key]) { throw new RuntimeException('KreaBankOpeningDatabaseError'); }
		}
		if (!array_key_exists('fk_adjustment_bank_line', $saved)
			|| ($adjustmentId > 0 ? (int) $saved['fk_adjustment_bank_line'] !== $adjustmentId : $saved['fk_adjustment_bank_line'] !== null)
			|| !isset($saved['datec'], $saved['payload_json']) || $saved['datec'] !== $createdAt || $saved['payload_json'] !== $json) { throw new RuntimeException('KreaBankOpeningDatabaseError'); }
		$audit = $this->rows('SELECT rowid,entity,audit_type,fk_statement_line,fk_reconciliation,payload_json,fk_user,datec FROM '
			. $this->db->prefix() . 'kreabank_recon_audit WHERE entity=' . $this->entity . ' AND rowid=' . (int) $auditId);
		if (count($audit) !== 1 || (int) $audit[0]['rowid'] !== $auditId || (int) $audit[0]['entity'] !== $this->entity || (int) $audit[0]['fk_user'] !== (int) $this->user->id
			|| $audit[0]['audit_type'] !== 'opening_reconciliation' || $audit[0]['fk_statement_line'] !== null || $audit[0]['fk_reconciliation'] !== null
			|| $audit[0]['payload_json'] !== $json || $audit[0]['datec'] !== $createdAt) { throw new RuntimeException('KreaBankOpeningDatabaseError'); }
	}

	private function prepare($accountId, $startDate, $officialBalance, $statementRef, $evidenceRef, $lock)
	{
		$this->assertWrite();
		$startDate = self::validateDate($startDate);
		$officialBalance = self::decimal($officialBalance);
		if (!is_string($statementRef) || trim($statementRef) === '' || strlen($statementRef) > 50 || preg_match('/[\x00-\x1f\x7f]/', $statementRef)) {
			throw new InvalidArgumentException('KreaBankOpeningInvalidReference');
		}
		$statementRef = trim($statementRef);
		$regex = function_exists('getDolGlobalString') ? getDolGlobalString('BANK_STATEMENT_REGEX_RULE') : '';
		if ($regex !== '' && @preg_match('/' . $regex . '/', $statementRef) !== 1) { throw new InvalidArgumentException('KreaBankOpeningInvalidReference'); }
		if (!is_string($evidenceRef) || trim($evidenceRef) === '' || strlen($evidenceRef) > 255 || preg_match('/[\x00-\x1f\x7f]/', $evidenceRef)) {
			throw new InvalidArgumentException('KreaBankOpeningInvalidEvidence');
		}
		$evidenceRef = trim($evidenceRef);
		if (!$this->available()) { throw new DomainException('KreaBankOpeningSchemaRequired'); }
		if ($lock && (int) ($this->db->transaction_opened ?? $this->db->depth ?? 0) <= 0) { throw new DomainException('KreaBankOpeningDatabaseError'); }
		$account = $this->account($accountId, $lock);
		$accountId = (int) $account['rowid'];
		if ($this->get($accountId)) { throw new DomainException('KreaBankOpeningAlreadyApplied'); }
		$p = $this->db->prefix(); $e = $this->entity; $suffix = $lock ? ' FOR UPDATE' : '';
		$date = $this->text($startDate);
		// Match the shared executor's account -> statement -> staging -> native lock order.
		$this->rows("SELECT rowid FROM {$p}kreabank_statement WHERE entity=$e AND bank_account_id=$accountId ORDER BY rowid" . $suffix);
		$staged = $this->rows("SELECT l.*,s.ref statement_ref,s.bank_account_id,s.currency statement_currency FROM {$p}kreabank_statement_line l"
			. " JOIN {$p}kreabank_statement s ON s.rowid=l.fk_statement AND s.entity=l.entity"
			. " WHERE l.entity=$e AND s.bank_account_id=$accountId AND (l.operation_date<$date OR l.operation_date IS NULL OR l.operation_date='0000-00-00') ORDER BY l.rowid" . $suffix);
		$native = $this->rows("SELECT rowid,fk_account,dateo,datev,CAST(amount AS DECIMAL(24,8)) amount,CAST(amount_main_currency AS DECIMAL(24,8)) amount_main_currency,"
			. "rappro,num_releve,fk_user_rappro,fk_type,label,note,fk_bordereau,fk_user_author,num_chq FROM {$p}bank"
			. " WHERE fk_account=$accountId AND (dateo<$date OR dateo IS NULL OR dateo='0000-00-00') ORDER BY rowid" . $suffix);
		$ledger = '0'; $unreconciled = 0;
		foreach ($native as &$row) {
			if (!$this->validStoredDate($row['dateo']) || !in_array((int) $row['rappro'], array(0,1), true)) { throw new DomainException('KreaBankOpeningReviewChanged'); }
			$row['amount'] = self::decimal($row['amount']);
			$ledger = self::decimal(KreaBankDecimal::add($ledger, $row['amount']));
			if ((int) $row['rappro'] === 0) { $unreconciled++; }
		}
		unset($row);
		foreach ($staged as $row) {
			if (!$this->validStoredDate($row['operation_date'])) { throw new DomainException('KreaBankOpeningReviewChanged'); }
			if ($row['currency'] !== $account['currency_code'] || $row['statement_currency'] !== $account['currency_code']) { throw new DomainException('KreaBankOpeningInvalidCurrency'); }
			self::decimal($row['amount']);
		}
		// Include claims from either side of the boundary; never seal a cross-period live match.
		$claims = $this->rows("SELECT l.rowid,l.entity,l.operation_date,l.fk_native_bank_line,l.status,s.bank_account_id,s.ref statement_ref,"
			. "b.dateo native_date,b.fk_account native_account,ba.entity native_entity FROM {$p}kreabank_statement_line l"
			. " JOIN {$p}kreabank_statement s ON s.rowid=l.fk_statement AND s.entity=l.entity JOIN {$p}bank b ON b.rowid=l.fk_native_bank_line"
			. " JOIN {$p}bank_account ba ON ba.rowid=b.fk_account WHERE ((s.bank_account_id=$accountId AND l.entity=$e AND l.operation_date<$date)"
			. " OR (b.fk_account=$accountId AND b.dateo<$date)) ORDER BY l.rowid" . $suffix);
		foreach ($claims as $row) {
			if ((int) $row['entity'] !== $e || (int) $row['native_entity'] !== $e || (int) $row['bank_account_id'] !== $accountId
				|| (int) $row['native_account'] !== $accountId || !$this->validStoredDate($row['operation_date']) || !$this->validStoredDate($row['native_date'])
				|| ($row['operation_date'] < $startDate) !== ($row['native_date'] < $startDate)) { throw new DomainException('KreaBankOpeningCrossingLinks'); }
		}
		$legacy = $this->rows("SELECT m.*,b.dateo native_date,b.fk_account native_account FROM {$p}kreabank_bankmeta m"
			. " JOIN {$p}bank b ON b.rowid=m.fk_bank_line WHERE b.fk_account=$accountId ORDER BY m.rowid" . $suffix);
		foreach ($legacy as $row) {
			$dateValue = $row['operation_date'] ?: $row['native_date'];
			if ((int) $row['entity'] !== $e || (int) $row['bank_account_id'] !== $accountId || $row['currency'] !== $account['currency_code']) {
				throw new DomainException('KreaBankOpeningInvalidCurrency');
			}
			if (!$this->validStoredDate($dateValue) || !$this->validStoredDate($row['native_date'])
				|| ($dateValue < $startDate) !== ($row['native_date'] < $startDate)) { throw new DomainException('KreaBankOpeningCrossingLinks'); }
		}
		$groups = array();
		if (KreaBankPaymentBatchProjection::available($this->db)) {
			$groups = $this->rows("SELECT g.rowid,g.entity,g.bank_account_id,g.operation_date,g.status,g.fk_statement_line,m.fk_bank_line,m.amount,"
				. "b.dateo native_date,b.fk_account native_account,l.operation_date source_date FROM {$p}kreabank_payment_batch g"
				. " JOIN {$p}kreabank_statement_line l ON l.rowid=g.fk_statement_line AND l.entity=g.entity"
				. " JOIN {$p}kreabank_payment_batch_line m ON m.fk_batch=g.rowid AND m.entity=g.entity"
				. " LEFT JOIN {$p}bank b ON b.rowid=m.fk_bank_line WHERE g.entity=$e AND g.status=1"
				. " AND (g.bank_account_id=$accountId OR b.fk_account=$accountId) ORDER BY g.rowid,m.rowid" . $suffix);
			foreach ($groups as $row) {
				if ((int) $row['bank_account_id'] !== $accountId || (int) $row['native_account'] !== $accountId
					|| !$this->validStoredDate($row['source_date']) || !$this->validStoredDate($row['native_date'])
					|| ($row['source_date'] < $startDate) !== ($row['native_date'] < $startDate)) { throw new DomainException('KreaBankOpeningCrossingLinks'); }
			}
		}
		$links = $this->rows("SELECT u.* FROM {$p}bank_url u JOIN {$p}bank b ON b.rowid=u.fk_bank"
			. " WHERE b.fk_account=$accountId AND b.dateo<$date ORDER BY u.rowid" . $suffix);
		$review = array('entity'=>$e,'actor_id'=>(int) $this->user->id,'account'=>$account,'account_id'=>$accountId,'start_date'=>$startDate,
			'balance_date'=>(new DateTimeImmutable($startDate, new DateTimeZone('UTC')))->modify('-1 day')->format('Y-m-d'),
			'currency'=>$account['currency_code'],'opening_balance'=>$officialBalance,'ledger_balance'=>$ledger,
			'adjustment_amount'=>self::decimal(KreaBankDecimal::subtract($officialBalance, $ledger)),
			'statement_ref'=>$statementRef,'evidence_ref'=>$evidenceRef,'native_count'=>count($native),'native_unreconciled_count'=>$unreconciled,
			'staged_count'=>count($staged),'native_rows'=>$native,'staged_rows'=>$staged,'crossing_rows'=>$claims,'legacy_rows'=>$legacy,
			'group_rows'=>$groups,'link_rows'=>$links,'statement_regex'=>$regex);
		$review['facts_hash'] = hash('sha256', json_encode($review, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
		$review['issued_at'] = time(); $review['expires_at'] = $review['issued_at'] + self::REVIEW_TTL;
		return $review;
	}

	private function account($id, $lock = false)
	{
		if ((int) $id <= 0) { throw new DomainException('KreaBankOpeningPermissions'); }
		$rows = $this->rows('SELECT rowid,ref,label,currency_code,entity,clos,rappro,courant FROM ' . $this->db->prefix() . 'bank_account'
			. ' WHERE rowid=' . (int) $id . ' AND entity=' . $this->entity . ($lock ? ' FOR UPDATE' : ''));
		if (count($rows) !== 1) { throw new DomainException('KreaBankOpeningPermissions'); }
		if ((int) $rows[0]['clos'] !== 0 || (int) $rows[0]['rappro'] !== 1 || (int) $rows[0]['courant'] === 2) {
			throw new DomainException('KreaBankOpeningPermissions');
		}
		if (!preg_match('/^[A-Z]{3}$/D', (string) $rows[0]['currency_code'])) { throw new DomainException('KreaBankOpeningInvalidCurrency'); }
		return $rows[0];
	}

	/** Native writes and every table whose rows/ranges protect the review must support rollback and row locks. */
	private function assertTransactionalTables()
	{
		$tables = array('bank_account','bank','bank_url','kreabank_statement','kreabank_statement_line','kreabank_bankmeta',
			'kreabank_recon_audit','kreabank_opening_balance');
		if (KreaBankPaymentBatchProjection::available($this->db)) {
			$tables[] = 'kreabank_payment_batch'; $tables[] = 'kreabank_payment_batch_line';
		}
		$names = array();
		foreach ($tables as $suffix) { $names[] = $this->text($this->db->prefix() . $suffix); }
		$rows = $this->rows('SELECT TABLE_NAME table_name,ENGINE engine FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN (' . implode(',', $names) . ')');
		if (count($rows) !== count($tables)) { throw new DomainException('KreaBankOpeningSchemaRequired'); }
		foreach ($rows as $row) {
			if (strtoupper((string) $row['engine']) !== 'INNODB') { throw new DomainException('KreaBankOpeningDatabaseError'); }
		}
	}

	private function validStoredDate($value)
	{
		if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) || $value === '0000-00-00') { return false; }
		$date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
		return $date && $date->format('Y-m-d') === $value;
	}

	private function assertContext()
	{
		global $conf;
		if ($this->entity <= 0 || !isset($conf->entity) || (int) $conf->entity !== $this->entity) { throw new DomainException('KreaBankOpeningPermissions'); }
	}
	private function assertRead()
	{
		$this->assertContext();
		// Read-only projections have already authorized their entry point and may not own a User.
		if ($this->user === null) { return; }
		if (empty($this->user->id) || !empty($this->user->socid) || (int) ($this->user->statut ?? 1) !== 1
			|| (!empty($this->user->entity) && (int) $this->user->entity !== $this->entity)
			|| !$this->user->hasRight('banque', 'lire') || !$this->user->hasRight('kreabank', 'reconciliation', 'read')) { throw new DomainException('KreaBankOpeningPermissions'); }
	}
	private function assertWrite()
	{
		$this->assertRead();
		if (empty($this->user->admin) || !$this->user->hasRight('banque', 'modifier') || !$this->user->hasRight('banque', 'consolidate')
			|| !$this->user->hasRight('kreabank', 'reconciliation', 'write') || !isModEnabled('kreabank') || !isModEnabled('banque')) {
			throw new DomainException('KreaBankOpeningPermissions');
		}
		if (isModEnabled('multicompany') && !$this->rows('SELECT rowid FROM ' . $this->db->prefix() . 'entity WHERE rowid=' . $this->entity . ' AND active=1')) {
			throw new DomainException('KreaBankOpeningPermissions');
		}
	}
	private static function tableExists($db, $suffix)
	{
		$result = $db->query('SELECT COUNT(*) nb FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=' . "'" . $db->escape($db->prefix() . $suffix) . "'");
		if (!$result) { throw new RuntimeException('KreaBankOpeningDatabaseError'); }
		$row = $db->fetch_object($result);
		return (int) ($row->nb ?? 0) === 1;
	}
	/** Cache discovery only: opening rows and dates are always read live by the generated SQL. */
	private static function schemaAvailable($db, $refresh = false)
	{
		if (!(self::$schemaCache instanceof SplObjectStorage)) { self::$schemaCache = new SplObjectStorage(); }
		if ($refresh || !self::$schemaCache->contains($db)) {
			self::$schemaCache[$db] = self::tableExists($db, 'kreabank_opening_balance');
		}
		return self::$schemaCache[$db];
	}
	private function query($sql)
	{
		try { $result = $this->db->query($sql); }
		catch (Throwable $e) { throw new RuntimeException('KreaBankOpeningDatabaseError', 0, $e); }
		if (!$result) { throw new RuntimeException('KreaBankOpeningDatabaseError'); }
		return $result;
	}
	private function rows($sql)
	{
		$locked = substr($sql, -11) === ' FOR UPDATE';
		if ($locked) { $sql = substr($sql, 0, -11); }
		$result = $this->query($sql . $this->db->plimit(self::MAX_ROWS + 1) . ($locked ? ' FOR UPDATE' : '')); $rows = array();
		while ($row = $this->db->fetch_object($result)) {
			$rows[] = (array) $row;
			if (count($rows) > self::MAX_ROWS) { throw new DomainException('KreaBankOpeningTooManyRows'); }
		}
		return $rows;
	}
	private function text($value) { return "'" . $this->db->escape((string) $value) . "'"; }
}
