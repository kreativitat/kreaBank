<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use Luracast\Restler\RestException;

require_once __DIR__ . '/../lib/kreabank_integration.lib.php';

/**
 * Read-only banking evidence for the authenticated current company.
 * @access protected
 * @class DolibarrApiAccess {@requires user}
 */
class KreaBankApi extends DolibarrApi
{
	public function __construct()
	{
		global $db;
		$this->db = $db;
	}

	/**
	 * List owned accounts with native balances and imported pending counts.
	 * @param string $kind all, bank, cash or closed
	 * @param int $bank_account_id Optional owned account
	 * @param int $limit Records per page, 1 to 500
	 * @param int $page Zero-based page
	 * @return array
	 * @url GET accounts
	 */
	public function accounts($kind = 'all', $bank_account_id = 0, $limit = 100, $page = 0)
	{
		$report = $this->report();
		list($limit, $page) = $this->pagination($limit, $page);
		$account = $this->accountId($bank_account_id);
		if (!in_array($kind, array('all', 'bank', 'cash', 'closed'), true)) {
			throw new RestException(400, 'Invalid account kind');
		}
		try {
			return $this->collection($report->accounts($kind, $account, 'ref', 'ASC', $limit + 1, $page * $limit), $limit, $page);
		} catch (RuntimeException $error) {
			throw new RestException(503, 'Unable to read bank accounts');
		}
	}

	/**
	 * List staged and legacy imported statements without materializing movements.
	 * @param int $bank_account_id Optional owned account
	 * @param int $limit Records per page, 1 to 500
	 * @param int $page Zero-based page
	 * @return array
	 * @url GET statements
	 */
	public function statements($bank_account_id = 0, $limit = 100, $page = 0)
	{
		$report = $this->report();
		list($limit, $page) = $this->pagination($limit, $page);
		$account = $this->accountId($bank_account_id);
		$sql = 'SELECT h.* FROM (' . $report->statementsSql($account) . ') h'
			. ' ORDER BY h.imported_at DESC,h.bank_account_id,h.statement_ref';
		return $this->selectCollection($report, $sql, $limit, $page);
	}

	/**
	 * Read one statement summary; a reference is always scoped to an owned account.
	 * @param int $bank_account_id Owned account ID
	 * @param string $reference Exact statement reference
	 * @return array
	 * @url GET statement
	 */
	public function statement($bank_account_id, $reference)
	{
		$report = $this->report();
		$account = $this->accountId($bank_account_id, true);
		$reference = $this->reference($reference);
		try {
			$statement = $report->statement($account, $reference);
		} catch (RuntimeException $error) {
			throw new RestException(503, 'Unable to read bank statement');
		}
		if (!$statement) {
			throw new RestException(404, 'Statement not found');
		}
		return array('entity_id' => $this->entity(), 'statement' => (array) $statement);
	}

	/**
	 * Read original imported lines and separately identified native ledger links.
	 * @param int $bank_account_id Owned account ID
	 * @param string $reference Exact statement reference
	 * @param int $limit Records per page, 1 to 500
	 * @param int $page Zero-based page
	 * @return array
	 * @url GET statement/lines
	 */
	public function statementLines($bank_account_id, $reference, $limit = 100, $page = 0)
	{
		$report = $this->report();
		list($limit, $page) = $this->pagination($limit, $page);
		$account = $this->accountId($bank_account_id, true);
		$reference = $this->reference($reference);
		$this->statement($account, $reference);
		$sql = 'SELECT h.* FROM (' . $report->linesSql($account, $reference) . ') h ORDER BY h.operation_date,h.line_rank,h.rowid';
		$result = $this->selectCollection($report, $sql, $limit, $page);
		foreach ($result['items'] as &$line) {
			$line['state'] = kreabankIntegrationLineState((object) $line, $reference);
		}
		unset($line);
		return $result;
	}

	/**
	 * Company-wide audit metadata, excluding payloads, IP addresses and logins.
	 * Line identifiers in this audit refer to native bank rows, never staging IDs.
	 * @param int $limit Records per page, 1 to 500
	 * @param int $page Zero-based page
	 * @return array
	 * @url GET audit
	 */
	public function audit($limit = 100, $page = 0)
	{
		$report = $this->report();
		list($limit, $page) = $this->pagination($limit, $page);
		$sql = 'SELECT a.rowid,a.audit_type,a.fk_statement_line native_bank_line_id,a.fk_reconciliation native_reconciliation_id,a.datec'
			. ' FROM ' . $this->db->prefix() . 'kreabank_recon_audit a WHERE a.entity=' . $this->entity()
			. ' ORDER BY a.datec DESC,a.rowid DESC';
		return $this->selectCollection($report, $sql, $limit, $page);
	}

	private function report()
	{
		$user = DolibarrApiAccess::$user;
		if (!isModEnabled('kreabank') || !isModEnabled('banque') || !$user || !empty($user->socid)
			|| !$user->hasRight('banque', 'lire') || !$user->hasRight('kreabank', 'reconciliation', 'read')) {
			throw new RestException(403, 'Bank and KreaBank read permissions are required');
		}
		return new KreaBankIntegration($this->db, $this->entity());
	}

	private function entity()
	{
		global $conf;
		if (empty($conf->entity) || !ctype_digit((string) $conf->entity) || (int) $conf->entity <= 0) {
			throw new RestException(403, 'A current company is required');
		}
		return (int) $conf->entity;
	}

	private function integer($value, $minimum, $maximum)
	{
		if ((!is_int($value) && !is_string($value)) || !preg_match('/^(0|[1-9][0-9]*)$/D', (string) $value)
			|| $value < $minimum || $value > $maximum) {
			throw new RestException(400, 'Invalid integer parameter');
		}
		return (int) $value;
	}

	private function pagination($limit, $page)
	{
		$limit = $this->integer($limit, 1, 500);
		return array($limit, $this->integer($page, 0, intdiv(PHP_INT_MAX, 501)));
	}

	private function accountId($value, $required = false)
	{
		$id = $this->integer($value, $required ? 1 : 0, 2147483647);
		if ($id > 0) {
			$sql = 'SELECT rowid FROM ' . $this->db->prefix() . 'bank_account WHERE rowid=' . $id . ' AND entity=' . $this->entity();
			$result = $this->db->query($sql);
			if (!$result) {
				throw new RestException(503, 'Unable to verify bank account');
			}
			if (!$this->db->fetch_object($result)) {
				throw new RestException(404, 'Bank account not found');
			}
		}
		return $id;
	}

	private function reference($reference)
	{
		if (!is_string($reference) || trim($reference) === '' || strlen($reference) > 100
			|| preg_match('/[\x00-\x1f\x7f]/', $reference)) {
			throw new RestException(400, 'Invalid statement reference');
		}
		return $reference;
	}

	private function selectCollection($report, $sql, $limit, $page)
	{
		try {
			return $this->collection($report->query($sql . $this->db->plimit($limit + 1, $page * $limit)), $limit, $page);
		} catch (RuntimeException $error) {
			throw new RestException(503, 'Unable to read bank evidence');
		}
	}

	private function collection($result, $limit, $page)
	{
		$items = array();
		while ($row = $this->db->fetch_object($result)) {
			$items[] = (array) $row;
		}
		$hasMore = count($items) > $limit;
		return array('entity_id' => $this->entity(), 'items' => array_slice($items, 0, $limit),
			'limit' => $limit, 'page' => $page, 'has_more' => $hasMore);
	}
}
