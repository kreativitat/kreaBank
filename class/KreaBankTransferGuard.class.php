<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Read-only review of a native internal transfer before native transfer.php writes it.
 *
 * A leg duplicates the ledger when its owned account already holds a movement with the same
 * signed amount on a nearby date (an imported and reconciled line, or the same transfer
 * entered twice). It never writes financial records and never constructs the service.
 */
class KreaBankTransferGuard
{
	const CONFIRM_MIN_SECONDS = 5;
	const CONFIRM_MAX_SECONDS = 600;

	private $db;
	private $entity;

	public function __construct($db, $entity)
	{
		$this->db = $db;
		$this->entity = (int) $entity;
	}

	/**
	 * @param array<int,array{from:int,to:int,amount:string,amount_to:string,date:int}> $transfers
	 * @param int $toleranceDays
	 * @return int[] Existing possible duplicate movements, bounded to 101.
	 */
	public function existingMovements(array $transfers, $toleranceDays = 3)
	{
		$p = $this->db->prefix();
		$days = max(0, min(31, (int) $toleranceDays));
		$conditions = array();
		foreach ($transfers as $transfer) {
			$amount = self::decimal($transfer['amount'] ?? '');
			$amountTo = self::decimal(($transfer['amount_to'] ?? '') !== '' ? $transfer['amount_to'] : ($transfer['amount'] ?? ''));
			$date = (int) ($transfer['date'] ?? 0);
			if ($amount === '' || $date <= 0 || (int) $transfer['from'] <= 0 || (int) $transfer['to'] <= 0) { continue; }
			$start = "'" . gmdate('Y-m-d', $date - $days * 86400) . "'";
			$end = "'" . gmdate('Y-m-d', $date + ($days + 1) * 86400) . "'";
			$conditions[] = '(b.fk_account=' . (int) $transfer['from'] . ' AND CAST(b.amount AS DECIMAL(24,8))=-' . $amount . " AND b.dateo>=$start AND b.dateo<$end)";
			if ($amountTo !== '') {
				$conditions[] = '(b.fk_account=' . (int) $transfer['to'] . ' AND CAST(b.amount AS DECIMAL(24,8))=' . $amountTo . " AND b.dateo>=$start AND b.dateo<$end)";
			}
		}
		if (!$conditions) { return array(); }
		$result = $this->db->query("SELECT b.rowid FROM {$p}bank b JOIN {$p}bank_account ba ON ba.rowid=b.fk_account WHERE ba.entity=" . $this->entity
			. ' AND (' . implode(' OR ', $conditions) . ') ORDER BY b.rowid' . $this->db->plimit(101));
		if (!$result) { throw new RuntimeException('Existing transfer movement search failed'); }
		$ids = array();
		while ($row = $this->db->fetch_object($result)) { $ids[] = (int) $row->rowid; }
		return $ids;
	}

	/**
	 * One confirmation identity for the exact reviewed transfers and existing movements.
	 *
	 * @param int $userId
	 * @param array<int,array<string,mixed>> $transfers
	 * @param int[] $existing
	 * @return string
	 */
	public function confirmationKey($userId, array $transfers, array $existing)
	{
		$rows = array();
		foreach ($transfers as $transfer) {
			$rows[] = array((int) $transfer['from'], (int) $transfer['to'], self::decimal($transfer['amount'] ?? ''), self::decimal($transfer['amount_to'] ?? ''), gmdate('Y-m-d', (int) ($transfer['date'] ?? 0)));
		}
		return hash('sha256', json_encode(array($this->entity, (int) $userId, $rows, array_values(array_map('intval', $existing)))));
	}

	/**
	 * Repeating the identical transfer is the explicit confirmation; an immediate repeat is a double submit.
	 *
	 * @param array<string,int> $store Session-held first-refusal times by confirmation key (updated).
	 * @param string $key
	 * @param int $now
	 * @return bool True when this submission is a valid confirmation (the key is consumed).
	 */
	public static function consumeConfirmation(array &$store, $key, $now)
	{
		foreach ($store as $storedKey => $time) {
			if ($now - (int) $time > self::CONFIRM_MAX_SECONDS) { unset($store[$storedKey]); }
		}
		if (isset($store[$key]) && $now - (int) $store[$key] >= self::CONFIRM_MIN_SECONDS) {
			unset($store[$key]);
			return true;
		}
		if (!isset($store[$key])) { $store[$key] = (int) $now; }
		return false;
	}

	/** Best-effort audit of a confirmed duplicate-looking transfer. */
	public function auditOverride($userId, array $transfers, array $existing)
	{
		$payload = json_encode(array('transfers' => array_values($transfers), 'confirmed_movement_ids' => array_values(array_map('intval', $existing))));
		return (bool) $this->db->query('INSERT INTO ' . $this->db->prefix() . 'kreabank_recon_audit (entity,audit_type,payload_json,fk_user,datec) VALUES ('
			. $this->entity . ",'transfer_movement_override','" . $this->db->escape((string) $payload) . "'," . (int) $userId . ",'" . $this->db->idate(time()) . "')");
	}

	private static function decimal($value)
	{
		if (is_float($value) || is_int($value)) { $value = number_format((float) $value, 8, '.', ''); }
		$value = trim((string) $value);
		if (!preg_match('/^[0-9]{1,16}(?:\.[0-9]{1,8})?$/D', $value)) { return ''; }
		$parts = explode('.', $value, 2);
		$whole = ltrim($parts[0], '0');
		$fraction = rtrim($parts[1] ?? '', '0');
		$out = ($whole === '' ? '0' : $whole) . ($fraction !== '' ? '.' . $fraction : '');
		return $out === '0' ? '' : $out;
	}
}
