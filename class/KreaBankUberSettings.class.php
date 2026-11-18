<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
require_once __DIR__ . '/KreaBankAccountPairs.class.php';

/** Explicit store-to-clearing-account configuration; no accounting or scheduling writes. */
class KreaBankUberSettings
{
	const SETTING = 'KREABANK_UBER_CLEARING_ACCOUNTS';
	private $db;
	private $entity;

	public function __construct($db, $entity)
	{
		$this->db = $db;
		$this->entity = (int) $entity;
	}

	public function mappings($lock = false)
	{
		$this->assertContext();
		$rows = $this->rows('SELECT value FROM ' . $this->db->prefix() . 'const WHERE entity=' . $this->entity
			. " AND name='" . self::SETTING . "'" . ($lock ? ' FOR UPDATE' : ''));
		$map = $rows ? json_decode($rows[0]->value, true) : array();
		if (count($rows) > 1 || !is_array($map)) { throw new DomainException('Review the Uber clearing-account configuration'); }
		$clean = array();
		foreach ($map as $store => $account) {
			if (!ctype_digit((string) $store) || (int) $store <= 0 || !is_int($account) || $account <= 0) {
				throw new DomainException('Review the Uber clearing-account configuration');
			}
			$clean[(int) $store] = $account;
		}
		ksort($clean, SORT_NUMERIC);
		return $clean;
	}

	public function revision()
	{
		return self::mapRevision($this->mappings());
	}

	public static function mapRevision($map)
	{
		ksort($map, SORT_NUMERIC);
		return hash('sha256', json_encode((object) $map));
	}

	/** Exact bounded provider cents, including signed charge and adjustment totals. */
	public static function money($cents)
	{
		if ((!is_int($cents) && !is_string($cents)) || !preg_match('/^-?[0-9]{1,12}$/D', (string) $cents)) {
			throw new DomainException('Invalid Uber cent amount');
		}
		$value = (string) $cents;
		$negative = $value[0] === '-';
		$digits = ltrim((string) $cents, '-0');
		$digits = str_pad($digits === '' ? '0' : $digits, 3, '0', STR_PAD_LEFT);
		return ($negative && (int) $cents !== 0 ? '-' : '') . substr($digits, 0, -2) . '.' . substr($digits, -2);
	}

	/** Bank decimals must already be exact cent values; never round source evidence. */
	public static function cents($decimal)
	{
		$decimal = KreaBankPaymentBatchDecimal::normalize($decimal);
		if (substr($decimal, -6) !== '000000') { throw new DomainException('Uber payouts require exact cent amounts'); }
		$digits = str_replace('.', '', substr($decimal, 0, -6));
		$negative = $digits[0] === '-';
		$digits = ltrim($digits, '-0');
		if (strlen($digits) > 12) { throw new DomainException('Uber payout amount is outside the supported range'); }
		return ($negative ? -1 : 1) * (int) $digits;
	}

	/** Account::addline() persists through idate(tzserver): keep that same server calendar. */
	public static function nativeDayTimestamp($day)
	{
		if (!is_string($day) || !preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $day)) { throw new DomainException('Invalid Uber bank date'); }
		$date = DateTimeImmutable::createFromFormat('!Y-m-d', $day, new DateTimeZone(date_default_timezone_get()));
		if (!$date || $date->format('Y-m-d') !== $day) { throw new DomainException('Invalid Uber bank date'); }
		return $date->getTimestamp();
	}

	public function accounts()
	{
		$this->assertContext();
		return $this->rows('SELECT rowid,entity,ref,label,currency_code,clos,courant,rappro FROM ' . $this->db->prefix()
			. 'bank_account WHERE entity=' . $this->entity . ' ORDER BY ref,rowid');
	}

	public function stores()
	{
		$this->assertContext();
		return (new KreaEatsBankIntegration($this->db, $GLOBALS['user'], $this->entity))->stores();
	}

	public function configure($actor, $map, $expectedRevision)
	{
		$this->assertContext();
		if (empty($actor->id) || empty($actor->admin) || !empty($actor->socid) || (int) $actor->statut !== 1
			|| (!empty($actor->entity) && (int) $actor->entity !== $this->entity)
			|| !$actor->hasRight('banque', 'modifier') || !$actor->hasRight('banque', 'transfer')
			|| !$actor->hasRight('kreabank', 'reconciliation', 'write') || !$actor->hasRight('kreaeats', 'read')) {
			throw new DomainException('Administrator, bank transfer and reconciliation permissions are required');
		}
		if (!is_array($map) || count($map) > 100) { throw new DomainException('Invalid Uber clearing-account configuration'); }
		$clean = array();
		foreach ($map as $store => $account) {
			if ((string) $account === '' || (string) $account === '0') { continue; }
			if (!ctype_digit((string) $store) || (int) $store <= 0 || !ctype_digit((string) $account) || (int) $account <= 0) {
				throw new DomainException('Invalid Uber clearing-account configuration');
			}
			$clean[(int) $store] = (int) $account;
		}
		ksort($clean, SORT_NUMERIC);
		require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
		if (!$this->db->begin()) { throw new RuntimeException('Cannot start Uber clearing-account configuration'); }
		try {
			$this->rows('SELECT rowid FROM ' . $this->db->prefix() . 'bank_account WHERE entity=' . $this->entity . ' ORDER BY rowid FOR UPDATE');
			$current = $this->mappings(true);
			if (!is_string($expectedRevision) || !hash_equals(self::mapRevision($current), $expectedRevision)) {
				throw new DomainException('Uber clearing accounts changed; reload the configuration');
			}
			$stores = array();
			foreach ($this->stores() as $store) { $stores[(int) $store['rowid']] = $store; }
			$accounts = array();
			foreach ($this->accounts() as $account) { $accounts[(int) $account->rowid] = (array) $account; }
			$pairs = (new KreaBankAccountPairs($this->db, $this->entity))->pairs(true);
			foreach ($clean as $store => $account) {
				if (!isset($stores[$store], $accounts[$account], $accounts[(int) $stores[$store]['fk_account']])) {
					throw new DomainException('Select a restaurant and accounts owned by the current company');
				}
				self::assertAccounts($stores[$store], $accounts[$account], $accounts[(int) $stores[$store]['fk_account']], $pairs);
			}
			if (dolibarr_set_const($this->db, self::SETTING, json_encode((object) $clean), 'chaine', 0, '', $this->entity) <= 0
				|| !$this->db->commit()) { throw new RuntimeException('Uber clearing accounts were not saved'); }
		} catch (Throwable $error) { $this->db->rollback(); throw $error; }
	}

	/** Cash clearing accounts retain native cash semantics; they are never retyped. */
	public static function assertAccounts($store, $clearing, $receiving, $pairs)
	{
		if ((int) $clearing['rowid'] === (int) $receiving['rowid'] || (int) $store['fk_account'] !== (int) $receiving['rowid']
			|| (int) $store['entity'] !== (int) $clearing['entity'] || (int) $store['entity'] !== (int) $receiving['entity']
			|| $store['currency_code'] !== 'EUR' || $clearing['currency_code'] !== 'EUR' || $receiving['currency_code'] !== 'EUR'
			|| (int) $clearing['clos'] !== 0 || (int) $receiving['clos'] !== 0 || (int) $receiving['rappro'] !== 1
			|| (int) $receiving['courant'] === 2 || isset($pairs[(int) $clearing['rowid']]) || isset($pairs[(int) $receiving['rowid']])) {
			throw new DomainException('Select distinct open company-owned EUR accounts without restricted-account rules');
		}
	}

	private function assertContext()
	{
		global $conf;
		$bridge = dirname(__DIR__, 2) . '/kreaeats/class/kreaeatsbankintegration.class.php';
		if ($this->entity <= 0 || (int) $conf->entity !== $this->entity || PHP_VERSION_ID < 80100
			|| !isModEnabled('kreabank') || !isModEnabled('banque') || !isModEnabled('kreaeats') || !is_file($bridge)) {
			throw new DomainException('Enable compatible KreaEats and bank modules in the current company');
		}
		require_once $bridge;
	}

	private function rows($sql)
	{
		$result = $this->db->query($sql);
		if (!$result) { throw new RuntimeException('Cannot verify Uber clearing-account configuration'); }
		$rows = array();
		while ($row = $this->db->fetch_object($result)) { $rows[] = $row; }
		return $rows;
	}
}
