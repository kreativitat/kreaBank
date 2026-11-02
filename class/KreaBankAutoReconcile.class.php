<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
require_once __DIR__ . '/KreaBankService.class.php';

/** Company-scoped scheduling only; matching and writes stay in KreaBankService. */
class KreaBankAutoReconcile
{
	public $db;
	public $entity;
	public $output = '';
	public $error = '';
	public $errors = array();

	public function __construct($db)
	{
		global $conf;
		$this->db = $db;
		$this->entity = (int) $conf->entity;
	}

	public function setting($suffix, $default = '')
	{
		if (!in_array($suffix, array('MODE', 'ACCOUNTS', 'CURSOR', 'PAIRS_ENABLED', 'TPA_ENABLED'), true)) {
			throw new Exception('Invalid automatic reconciliation setting');
		}
		$rows = $this->rows('SELECT value FROM ' . $this->db->prefix() . 'const WHERE entity=' . $this->entity
			. " AND name='KREABANK_AUTO_NATIVE_" . $suffix . "'");
		return $rows ? (string) $rows[0]->value : $default;
	}

	public function accounts()
	{
		return $this->rows('SELECT rowid,ref,label,currency_code FROM ' . $this->db->prefix() . 'bank_account'
			. ' WHERE entity=' . $this->entity . ' AND clos=0 AND rappro=1 AND courant<>2 ORDER BY ref,rowid');
	}

	/** Saving configuration also installs/updates exactly one native job for this company. */
	public function configure($actor, $mode, $accountIds, $pairedTransfers = false, $tpaReceipts = false)
	{
		$this->assertActor($actor, true);
		if ($pairedTransfers && !$actor->hasRight('banque', 'transfer')) { throw new Exception('Bank transfer permission is required'); }
		if (!in_array($mode, array('off', 'preview', 'enabled'), true)) {
			throw new Exception('Invalid automatic reconciliation mode');
		}
		$ids = array_values(array_unique(array_map('intval', (array) $accountIds)));
		$eligible = array_map(static function ($account) { return (int) $account->rowid; }, $this->accounts());
		if (array_diff($ids, $eligible) || ($mode !== 'off' && !$ids)) {
			throw new Exception('Select open reconciliation accounts owned by the current company');
		}
		require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
		require_once DOL_DOCUMENT_ROOT . '/cron/class/cronjob.class.php';
		$this->acquireLock();
		try {
			if (!$this->db->begin()) {
				throw new Exception('Cannot start automatic reconciliation configuration');
			}
			try {
				$this->saveSetting('MODE', $mode);
				$this->saveSetting('ACCOUNTS', implode(',', $ids));
				$this->saveSetting('CURSOR', '0');
				$this->saveSetting('PAIRS_ENABLED', $pairedTransfers ? '1' : '0');
				$this->saveSetting('TPA_ENABLED', $tpaReceipts ? '1' : '0');
				$jobs = $this->rows('SELECT rowid FROM ' . $this->db->prefix() . 'cronjob WHERE entity=' . $this->entity
					. " AND objectname='KreaBankAutoReconcile' AND methodename='run'");
				if (count($jobs) > 1) {
					throw new Exception('Duplicate automatic reconciliation jobs require administrator review');
				}
				$job = new Cronjob($this->db);
				if ($jobs && $job->fetch((int) $jobs[0]->rowid) <= 0) {
					throw new Exception('Cannot load automatic reconciliation job');
				}
				$job->entity = $this->entity;
				$job->label = 'KreaBank automatic bank reconciliation';
				$job->jobtype = 'method';
				$job->classesname = '/kreabank/class/KreaBankAutoReconcile.class.php';
				$job->objectname = 'KreaBankAutoReconcile';
				$job->methodename = 'run';
				$job->module_name = 'kreabank';
				$job->frequency = 15;
				$job->unitfrequency = 60;
				$job->priority = 60;
				$job->status = $mode === 'off' ? 0 : 1;
				$job->test = '1';
				$job->datestart = dol_now();
				$job->datenextrun = dol_now() + 900;
				$result = $jobs ? $job->update($actor) : $job->create($actor);
				if ($result <= 0 || !$this->db->commit()) {
					throw new Exception('Automatic reconciliation configuration was not saved');
				}
			} catch (Throwable $e) {
				$this->db->rollback();
				throw $e;
			}
		} finally {
			$this->releaseLock();
		}
	}

	/** Dolibarr sets the job entity before invoking this method. */
	public function run()
	{
		global $user;
		try {
			$result = $this->process($user, false);
			$this->output = json_encode($result);
			if (!empty($result['failed'])) {
				throw new Exception('Automatic reconciliation needs attention; review the pending lines and scheduler output');
			}
			return 0;
		} catch (Throwable $e) {
			$this->error = $e->getMessage();
			$this->errors = array($this->error);
			return -1;
		}
	}

	/** Preview never reconciles or advances the scheduled cursor, even when enabled. */
	public function process($actor, $preview = true)
	{
		global $langs;
		$this->assertActor($actor);
		$this->acquireLock();
		try {
			$mode = $this->setting('MODE', 'off');
			if (!$preview && $mode === 'off') {
				return array('status' => 'disabled');
			}
			if (!in_array($mode, array('off', 'preview', 'enabled'), true)) {
				throw new Exception('Invalid automatic reconciliation mode; no movements were processed');
			}
			$ids = array_filter(array_map('intval', explode(',', $this->setting('ACCOUNTS'))), static function ($id) { return $id > 0; });
			if (!$ids) {
				return array('status' => 'no_accounts');
			}
			$service = new KreaBankService($this->db, $actor, $langs);
			$cursor = $preview ? 0 : max(0, (int) $this->setting('CURSOR', '0'));
			$sql = 'SELECT l.rowid FROM ' . $this->db->prefix() . 'kreabank_statement_line l'
				. ' INNER JOIN ' . $this->db->prefix() . 'kreabank_statement s ON s.rowid=l.fk_statement AND s.entity=l.entity'
				. ' INNER JOIN ' . $this->db->prefix() . 'bank_account ba ON ba.rowid=s.bank_account_id AND ba.entity=l.entity'
				. ' WHERE l.entity=' . $this->entity . ' AND l.status=0 AND l.rowid>' . $cursor
				. ' AND ba.rowid IN (' . implode(',', $ids) . ') ORDER BY l.rowid' . $this->db->plimit(50);
			$rows = $this->rows($sql);
			$result = array('status' => $preview || $mode !== 'enabled' ? 'preview' : 'completed',
				'scanned' => 0, 'eligible' => 0, 'reconciled' => 0, 'failed' => 0, 'matches' => array(), 'errors' => array());
			$pairedTransfers = $this->setting('PAIRS_ENABLED', '0') === '1';
			$tpaReceipts = $this->setting('TPA_ENABLED', '0') === '1';
			$previewedPairs = array();
			$started = microtime(true);
			foreach ($rows as $row) {
				$cursor = (int) $row->rowid;
				$result['scanned']++;
				try {
					$pair = $pairedTransfers ? $service->getAutomaticTransferSuggestion($cursor, $ids) : null;
					if ($pair) {
						$other = (int) $pair['lines'][1]->rowid;
						if (!isset($previewedPairs[$cursor])) {
							$result['eligible']++;
							$result['matches'][] = array('statement_line_id' => $cursor, 'paired_statement_line_id' => $other,
								'bank_line_id' => (int) $pair['bank_ids'][0], 'score' => null);
							$previewedPairs[$other] = true;
							if (!$preview && $mode === 'enabled' && $service->reconcileAutomaticTransferPair($cursor, $ids)) {
								$result['reconciled'] += 2;
							}
						}
						$match = null;
					} else {
						$match = $service->getAutomaticNativeSuggestion($cursor, $ids);
					}
					if ($match) {
						$result['eligible']++;
						$result['matches'][] = array('statement_line_id' => $cursor,
							'bank_line_id' => (int) $match['suggestion']['doc_id'], 'score' => (int) $match['suggestion']['score']);
						if (!$preview && $mode === 'enabled' && $service->reconcileAutomaticNativeLine($cursor, $ids)) {
							$result['reconciled']++;
						}
					}
					if (!$pair && !$match && $tpaReceipts) {
						$tpa = $service->getAutomaticTpaSuggestion($cursor, $ids);
						if ($tpa) {
							$result['eligible']++;
							$result['matches'][] = array('statement_line_id' => $cursor, 'bank_line_id' => 0, 'score' => null, 'tpa_receipt' => true);
							if (!$preview && $mode === 'enabled' && $service->reconcileAutomaticTpaReceipt($cursor, $ids)) { $result['reconciled']++; }
						}
					}
				} catch (Throwable $e) {
					$result['failed']++;
					// No descriptions, account details or database diagnostics in the job output.
					$result['errors'][] = array('statement_line_id' => $cursor, 'reason' => 'processing_failed');
					dol_syslog('KreaBank automatic reconciliation failed for company ' . $this->entity . ', staged line ' . $cursor, LOG_ERR);
				}
				if (microtime(true) - $started >= 20) {
					break;
				}
			}
			if (!$preview) {
				require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
				// Keyset pagination revisits unresolved lines after reaching the end; no permanent starvation.
				$this->saveSetting('CURSOR', $rows ? (string) $cursor : '0');
			}
			return $result;
		} finally {
			$this->releaseLock();
		}
	}

	private function assertActor($actor, $admin = false)
	{
		global $conf;
		// Native Cronjob::run_jobs replaces this property with its database value.
		$this->entity = (int) $this->entity;
		if ($this->entity <= 0 || $this->entity !== (int) $conf->entity || !isModEnabled('kreabank') || !isModEnabled('banque')
			|| empty($actor->id) || (int) $actor->statut !== 1 || !empty($actor->socid)
			|| (!empty($actor->entity) && (int) $actor->entity !== $this->entity) || ($admin && empty($actor->admin))) {
			throw new Exception('An active internal user in the current company is required');
		}
		if (isModEnabled('multicompany') && !$this->rows('SELECT rowid FROM ' . $this->db->prefix() . 'entity WHERE rowid=' . $this->entity . ' AND active=1')) {
			throw new Exception('Automatic reconciliation requires an active company');
		}
		$actor->loadRights('', 1);
		if (!$actor->hasRight('banque', 'lire') || !$actor->hasRight('banque', 'modifier') || !$actor->hasRight('kreabank', 'reconciliation', 'write')) {
			throw new Exception('Bank reconciliation write permission is required');
		}
	}

	private function saveSetting($suffix, $value)
	{
		if (dolibarr_set_const($this->db, 'KREABANK_AUTO_NATIVE_' . $suffix, $value, 'chaine', 0, '', $this->entity) <= 0) {
			throw new Exception('Automatic reconciliation setting was not saved');
		}
	}

	private function lockName()
	{
		return 'kb-auto-' . substr(hash('sha256', $this->db->prefix()), 0, 24) . '-' . $this->entity;
	}

	private function acquireLock()
	{
		$rows = $this->rows("SELECT GET_LOCK('" . $this->lockName() . "',0) acquired");
		if (!$rows || (int) $rows[0]->acquired !== 1) {
			throw new Exception('Automatic reconciliation is already running for this company');
		}
	}

	private function releaseLock()
	{
		$this->db->query("SELECT RELEASE_LOCK('" . $this->lockName() . "')");
	}

	private function rows($sql)
	{
		$res = $this->db->query($sql);
		if (!$res) {
			throw new Exception('Automatic reconciliation database operation failed');
		}
		$rows = array();
		while ($row = $this->db->fetch_object($res)) {
			$rows[] = $row;
		}
		return $rows;
	}
}
