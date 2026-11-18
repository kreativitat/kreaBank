<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/** Serialize native transfer preflight/writes and automatic reconciliation per company. */
class KreaBankReconciliationLock
{
	private $db;
	private $name;
	private $held = false;
	private $shutdownRegistered = false;

	public function __construct($db, $entity)
	{
		if (!ctype_digit((string) $entity) || (int) $entity <= 0) {
			throw new DomainException('A current company is required for reconciliation locking');
		}
		$this->db = $db;
		// Preserve the existing runner lock identity, including during rolling deployments.
		$this->name = 'kb-auto-' . substr(hash('sha256', $db->prefix()), 0, 24) . '-' . (int) $entity;
	}

	/** Each instance owns one recursive MySQL/MariaDB advisory-lock acquisition. */
	public function acquire()
	{
		if ($this->held) { return; }
		$result = $this->db->query("SELECT GET_LOCK('" . $this->name . "',0) acquired");
		$row = $result ? $this->db->fetch_object($result) : null;
		if (!$row || (string) $row->acquired !== '1') {
			throw new RuntimeException('Reconciliation or a native transfer is already running for this company, or its lock is unavailable');
		}
		$this->held = true;
	}

	/** No SQL transaction is opened: native transfer.php owns its complete transaction. */
	public function release()
	{
		if (!$this->held) { return; }
		// Native page teardown may have closed the connection before PHP shutdown.
		if (isset($this->db->connected) && !$this->db->connected) {
			$this->held = false;
			return;
		}
		$result = $this->db->query("SELECT RELEASE_LOCK('" . $this->name . "') released");
		$row = $result ? $this->db->fetch_object($result) : null;
		if (!$row || (string) $row->released !== '1') {
			throw new RuntimeException('The reconciliation lock could not be released');
		}
		$this->held = false;
	}

	/** Retain this acquisition after the hook returns, through native commit/rollback. */
	public function holdUntilShutdown()
	{
		if (!$this->held) { throw new LogicException('Acquire the reconciliation lock before retaining it'); }
		if ($this->shutdownRegistered) { return; }
		$this->shutdownRegistered = true;
		register_shutdown_function(function () {
			try { $this->release(); } catch (Throwable $e) {
				// Connection termination is the final release if the driver has already shut down.
				if (function_exists('dol_syslog')) {
					dol_syslog('KreaBank reconciliation lock shutdown release failed', LOG_WARNING);
				}
			}
		});
	}
}
