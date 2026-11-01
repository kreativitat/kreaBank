<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/** Explicit, company-owned restricted-account -> main-account relationships. */
class KreaBankAccountPairs
{
	private $db;
	private $entity;

	public function __construct($db, $entity)
	{
		$this->db = $db;
		$this->entity = (int) $entity;
	}

	public function pairs($lock = false)
	{
		global $conf;
		if ($this->entity <= 0 || (int) $conf->entity !== $this->entity) {
			throw new DomainException('Bank account pair company context changed');
		}
		$rows = $this->rows('SELECT value FROM ' . $this->db->prefix() . 'const WHERE entity=' . $this->entity
			. " AND name='KREABANK_ACCOUNT_PAIRS'" . ($lock ? ' FOR UPDATE' : ''));
		$map = $rows ? json_decode($rows[0]->value, true) : array();
		if (!is_array($map) || count($rows) > 1) {
			throw new DomainException('Invalid bank account pairs; review module configuration');
		}
		foreach ($map as $restricted => $main) {
			if (!ctype_digit((string) $restricted) || (int) $restricted <= 0 || !is_int($main) || $main <= 0
				|| (int) $restricted === $main || isset($map[$main])) {
				throw new DomainException('Account pairs cannot contain self-links, chains or cycles');
			}
		}
		return $map;
	}

	public function accounts()
	{
		return $this->rows('SELECT rowid,ref,label,currency_code,clos,courant,rappro FROM ' . $this->db->prefix()
			. 'bank_account WHERE entity=' . $this->entity . ' ORDER BY ref,rowid');
	}

	public function configure($actor, $map, $expectedRevision = null)
	{
		if (empty($actor->admin) || empty($actor->id) || !empty($actor->socid) || (int) $actor->statut !== 1
			|| (!empty($actor->entity) && (int) $actor->entity !== $this->entity)
			|| !$actor->hasRight('banque', 'modifier') || !$actor->hasRight('kreabank', 'reconciliation', 'write')) {
			throw new DomainException('Administrator and bank reconciliation write permissions are required');
		}
		$this->pairs();
		if (!isModEnabled('kreabank') || !isModEnabled('banque') || (isModEnabled('multicompany')
			&& !$this->rows('SELECT rowid FROM ' . $this->db->prefix() . 'entity WHERE rowid=' . $this->entity . ' AND active=1'))) {
			throw new DomainException('Bank account pairs require an active company and enabled bank modules');
		}
		if (!is_array($map)) {
			throw new DomainException('Invalid bank account pairs');
		}
		$clean = array();
		foreach ($map as $restricted => $main) {
			if ((string) $main === '' || (string) $main === '0') { continue; }
			if (!ctype_digit((string) $restricted) || !ctype_digit((string) $main) || (int) $restricted <= 0 || (int) $main <= 0) {
				throw new DomainException('Invalid bank account pair');
			}
			$clean[(int) $restricted] = (int) $main;
		}
		require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
		if (!$this->db->begin()) { throw new DomainException('Cannot start account pair configuration'); }
		try {
			// Pair execution takes the same account locks before reading configuration.
			$this->rows('SELECT rowid FROM ' . $this->db->prefix() . 'bank_account WHERE entity=' . $this->entity . ' ORDER BY rowid FOR UPDATE');
			$current = $this->pairs(true);
			if ($expectedRevision !== null && !hash_equals(hash('sha256', json_encode((object) $current)), (string) $expectedRevision)) {
				throw new DomainException('Account pairs changed; reload the configuration before saving');
			}
			foreach ($clean as $restricted => $main) {
				if ($restricted === $main || isset($clean[$main])) {
					throw new DomainException('Account pairs cannot contain self-links, chains or cycles');
				}
				$this->assertAccounts($restricted, $main, true);
			}
			if (dolibarr_set_const($this->db, 'KREABANK_ACCOUNT_PAIRS', json_encode((object) $clean), 'chaine', 0, '', $this->entity) <= 0
				|| !$this->db->commit()) {
				throw new DomainException('Account pairs were not saved');
			}
		} catch (Throwable $e) {
			$this->db->rollback();
			throw $e;
		}
	}

	public function mainAccount($accountId)
	{
		$map = $this->pairs();
		return isset($map[(int) $accountId]) ? (int) $map[(int) $accountId] : 0;
	}

	public function counterparts($accountId)
	{
		$accountId = (int) $accountId;
		$map = $this->pairs();
		if (isset($map[$accountId])) { return array((int) $map[$accountId]); }
		return array_map('intval', array_keys($map, $accountId, true));
	}

	public function assertAccounts($from, $to, $open = false)
	{
		$rows = $this->rows('SELECT rowid,currency_code,clos,courant,rappro FROM ' . $this->db->prefix() . 'bank_account WHERE entity='
			. $this->entity . ' AND rowid IN (' . (int) $from . ',' . (int) $to . ')');
		if (count($rows) !== 2 || $rows[0]->currency_code !== $rows[1]->currency_code) {
			throw new DomainException('Paired accounts must belong to this company and use the same currency');
		}
		foreach ($rows as $row) {
			if ((int) $row->courant === 2 || ($open && ((int) $row->clos !== 0 || (int) $row->rappro !== 1))) {
				throw new DomainException('Select open bank accounts enabled for reconciliation');
			}
		}
	}

	public function assertTransfer($from, $to)
	{
		$map = $this->pairs();
		if ((isset($map[$from]) && $map[$from] !== (int) $to) || (isset($map[$to]) && $map[$to] !== (int) $from)) {
			throw new DomainException('This restricted account can transfer only to or from its configured main account');
		}
		if (isset($map[$from]) || isset($map[$to])) { $this->assertAccounts($from, $to, true); }
	}

	/** A restricted account may reconcile only its own leg of a verified native transfer. */
	public function assertDocument($type, $id, $accountId)
	{
		$main = $this->mainAccount($accountId);
		if (!$main) { return; }
		if ($type !== 'native_bank') {
			throw new DomainException('This restricted account accepts only transfers with its configured main account');
		}
		$this->assertAccounts($accountId, $main);
		$p = $this->db->prefix();
		$rows = $this->rows("SELECT u.type,u.url_id FROM {$p}bank_url u WHERE u.fk_bank=" . (int) $id . " AND u.type<>'company'");
		if (count($rows) !== 1 || $rows[0]->type !== 'banktransfert') {
			throw new DomainException('Select a native transfer linked only to the configured main account');
		}
		$other = (int) $rows[0]->url_id;
		$valid = $this->rows("SELECT b.rowid FROM {$p}bank b JOIN {$p}bank c ON c.rowid=$other JOIN {$p}bank_account a ON a.rowid=c.fk_account"
			. " JOIN {$p}bank_url u ON u.fk_bank=c.rowid AND u.type='banktransfert' AND u.url_id=b.rowid"
			. ' WHERE b.rowid=' . (int) $id . ' AND b.fk_account=' . (int) $accountId . " AND c.fk_account=$main AND a.entity=" . $this->entity
			. ' AND b.amount=-c.amount'
			. " AND NOT EXISTS (SELECT x.rowid FROM {$p}bank_url x WHERE x.fk_bank=c.rowid AND x.type<>'company'"
			. " AND (x.type<>'banktransfert' OR x.url_id<>b.rowid))");
		if (count($valid) !== 1) { throw new DomainException('Native transfer counterpart, amount or ownership is invalid'); }
	}

	private function rows($sql)
	{
		$result = $this->db->query($sql);
		if (!$result) { throw new RuntimeException('Cannot verify bank account pairs'); }
		$rows = array();
		while ($row = $this->db->fetch_object($result)) { $rows[] = $row; }
		return $rows;
	}
}
