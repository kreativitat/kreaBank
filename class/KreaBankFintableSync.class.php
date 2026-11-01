<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
require_once __DIR__ . '/KreaBankFintableMapper.class.php';

/** Installation-wide collection, with an explicit owner on every financial row. */
class KreaBankFintableSync
{
	public $db;
	public $error = '';
	public $errors = array();
	public $output = '';
	private $client;

	public function __construct($db, $client = null)
	{
		$this->db = $db;
		$this->client = $client;
	}

	public function ensureSchema()
	{
		$sql = file_get_contents(__DIR__ . '/../sql/llx_kreabank_fintable.sql');
		$sql = preg_replace('/^--.*$/m', '', $sql);
		foreach (explode(';', str_replace('llx_', $this->db->prefix(), $sql)) as $statement) {
			if (trim($statement) !== '') {
				$this->query($statement);
			}
		}
	}

	public function setting($name, $default = '')
	{
		if (!preg_match('/^KREABANK_FINTABLE_[A-Z_]+$/D', $name)) {
			throw new RuntimeException('Invalid Fintable setting');
		}
		$row = $this->one('SELECT value FROM ' . $this->db->prefix() . 'const WHERE entity=0 AND name=' . $this->text($name));
		return $row ? (string) $row->value : (string) $default;
	}

	/** Shared credential changes require an installation administrator, not a company administrator. */
	public function configure($values, $user)
	{
		$this->assertAdministrator($user);
		require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
		$workspace = trim((string) ($values['workspace'] ?? ''));
		KreaBankFintableClient::assertId($workspace, 'ws_');
		$times = trim((string) ($values['times'] ?? '08:45,20:45'));
		if (count(array_unique(explode(',', $times))) !== 2) {
			throw new RuntimeException('Choose two distinct collection times');
		}
		$timezone = trim((string) ($values['timezone'] ?? 'Europe/Lisbon'));
		KreaBankFintableMapper::slot(time(), $times, $timezone);
		$settings = array('WORKSPACE' => $workspace, 'TIMES' => $times, 'TIMEZONE' => $timezone,
			'ENABLED' => empty($values['enabled']) ? '0' : '1', 'REFRESH' => empty($values['refresh']) ? '0' : '1');
		if (array_key_exists('alert_email', $values)) {
			$email = trim((string) $values['alert_email']);
			if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
				throw new RuntimeException('Invalid collection alert email');
			}
			$settings['ALERT_EMAIL'] = $email;
		}
		$oldWorkspace = $this->setting('KREABANK_FINTABLE_WORKSPACE');
		if ($oldWorkspace !== '' && $oldWorkspace !== $workspace) {
			$used = $this->one('SELECT COUNT(*) n FROM ' . $this->db->prefix() . 'kreabank_feed_account');
			if ($used && (int) $used->n > 0) {
				throw new RuntimeException('An imported Workspace needs an explicit source-identity migration before replacement');
			}
		}
		$token = trim((string) ($values['token'] ?? ''));
		if ($token !== '') {
			new KreaBankFintableClient($token, $workspace);
			$encrypted = dolEncrypt($token);
			if (strpos($encrypted, 'dolcrypt:') !== 0 || !hash_equals($token, (string) dolDecrypt($encrypted))) {
				throw new RuntimeException('Dolibarr credential encryption is unavailable');
			}
			$settings['TOKEN'] = $encrypted;
		}
		if (!$this->db->begin()) {
			throw new RuntimeException('Cannot start Fintable configuration transaction');
		}
		try {
			foreach ($settings as $key => $value) {
				if (dolibarr_set_const($this->db, 'KREABANK_FINTABLE_' . $key, $value, 'chaine', 0, '', 0) <= 0) {
					throw new RuntimeException('Fintable configuration was not saved');
				}
			}
			$this->db->commit();
		} catch (Throwable $e) {
			$this->db->rollback();
			throw $e;
		}
	}

	private function api()
	{
		if ($this->client === null) {
			$cipher = $this->setting('KREABANK_FINTABLE_TOKEN');
			if (strpos($cipher, 'dolcrypt:') !== 0) {
				throw new RuntimeException('An encrypted Fintable credential is required');
			}
			$this->client = new KreaBankFintableClient(dolDecrypt($cipher), $this->setting('KREABANK_FINTABLE_WORKSPACE'));
		}
		return $this->client;
	}

	/** A single native cron job checks wall-clock slots; it does not poll Fintable every tick. */
	public function run()
	{
		global $user;
		try {
			$this->assertAdministrator($user);
			if ($this->setting('KREABANK_FINTABLE_ENABLED', '0') !== '1') {
				$this->output = 'Fintable automatic collection is disabled';
				return 0;
			}
			$slot = KreaBankFintableMapper::slot(time(), $this->setting('KREABANK_FINTABLE_TIMES', '08:45,20:45'), $this->setting('KREABANK_FINTABLE_TIMEZONE', 'Europe/Lisbon'));
			if ($slot === null) {
				$this->output = 'No Fintable collection is due';
				return 0;
			}
			$result = $this->collect($user, $slot);
			$this->output = json_encode($result);
			if (!empty($result['failed']) && !empty($result['final_attempt'])) {
				$this->error = 'Fintable collection needs attention; open KreaBank bank feeds';
				return -1;
			}
			return 0;
		} catch (Throwable $e) {
			$this->error = $e->getMessage();
			$this->errors = array($this->error);
			return -1;
		}
	}

	public function collect($actor, $slot = null, $preview = false)
	{
		global $conf, $user, $langs;
		$this->assertAdministrator($actor);
		$this->ensureSchema();
		$lock = 'kb-fintable-' . substr(hash('sha256', $this->db->prefix()), 0, 24);
		$locked = $this->one('SELECT GET_LOCK(' . $this->text($lock) . ',0) acquired');
		if (!$locked || (int) $locked->acquired !== 1) {
			return array('status' => 'already_running');
		}
		$savedEntity = (int) $conf->entity;
		$savedUser = $user;
		$runId = 0;
		$attempt = 1;
		$scheduled = $slot !== null;
		if ($slot === null && !$preview) {
			$slot = 'manual:' . gmdate('YmdHis') . ':' . bin2hex(random_bytes(8));
		}
		$result = array('status' => 'completed', 'imported' => 0, 'unchanged' => 0, 'conflicts' => 0, 'failed' => 0,
			'mapped' => 0, 'unmapped' => 0, 'entities' => array());
		try {
			if ($slot !== null && !$preview) {
				$old = $this->one('SELECT * FROM ' . $this->db->prefix() . 'kreabank_feed_run WHERE slot_key=' . $this->text($slot));
				if ($old && ($old->status === 'completed' || (int) $old->attempts >= 3)) {
					return array('status' => 'already_collected', 'slot' => $slot);
				}
				if ($old) {
					$runId = (int) $old->rowid;
					$attempt = (int) $old->attempts + 1;
					$this->query('UPDATE ' . $this->db->prefix() . 'kreabank_feed_run SET status=\'running\',attempts=' . $attempt . ' WHERE rowid=' . $runId);
				} else {
					$this->query('INSERT INTO ' . $this->db->prefix() . 'kreabank_feed_run (slot_key,status,started_at) VALUES (' . $this->text($slot) . ',\'running\',' . $this->now() . ')');
					$runId = (int) $this->db->last_insert_id($this->db->prefix() . 'kreabank_feed_run');
				}
			}
			$api = $this->api();
			$profile = $api->get('me')['data'];
			if (empty($profile['can_sync'])) {
				throw new RuntimeException('Fintable bank updates are paused by the subscription; existing imports are retained');
			}
			if ($scheduled && !$preview && $this->setting('KREABANK_FINTABLE_REFRESH', '0') === '1') {
				$run = $runId ? $this->one('SELECT refresh_requested,started_at FROM ' . $this->db->prefix() . 'kreabank_feed_run WHERE rowid=' . $runId) : null;
				if (!$run || !(int) $run->refresh_requested) {
					// Mark before requesting: an ambiguous POST timeout must never repeat automatically.
					if ($runId) {
						$this->query('UPDATE ' . $this->db->prefix() . 'kreabank_feed_run SET refresh_requested=1 WHERE rowid=' . $runId);
					}
					$api->requestSync();
					if ($runId) {
						$this->query('UPDATE ' . $this->db->prefix() . 'kreabank_feed_run SET status=\'refresh_wait\' WHERE rowid=' . $runId);
					}
					return array('status' => 'refresh_wait', 'message' => 'Refresh queued; collection resumes on the next scheduler tick');
				}
			}
			$connections = array();
			foreach ($api->get('connections')['data'] as $connection) {
				$connections[$connection['id']] = $connection;
			}
			$run = $runId ? $this->one('SELECT started_at FROM ' . $this->db->prefix() . 'kreabank_feed_run WHERE rowid=' . $runId) : null;
			foreach ($connections as $connection) {
				if ($scheduled && $run && time() - strtotime($run->started_at) < 1800
					&& in_array($connection['sync_status']['state'] ?? '', array('queued', 'executing', 'retrying'), true)) {
					if ($runId) {
						$this->query('UPDATE ' . $this->db->prefix() . 'kreabank_feed_run SET status=\'refresh_wait\',attempts=GREATEST(1,attempts-1) WHERE rowid=' . $runId);
					}
					return array('status' => 'refresh_wait');
				}
			}
			$entities = $this->activeEntities();
			$native = $this->nativeAccounts($entities);
			$sources = $api->get('accounts')['data'];
			if (!$preview && $entities) {
				$sourceIds = array();
				foreach ($sources as $source) {
					KreaBankFintableClient::assertId($source['id'] ?? null, 'acc_');
					if (!empty($source['enabled'])) {
						$sourceIds[] = $this->text($source['id']);
					}
				}
				$this->query('UPDATE ' . $this->db->prefix() . 'kreabank_feed_account SET status=\'unavailable\',error_code=\'Account is disabled or unavailable in Fintable\''
					. ' WHERE workspace_id=' . $this->text($this->setting('KREABANK_FINTABLE_WORKSPACE'))
					. ' AND entity IN (' . implode(',', array_map('intval', array_keys($entities))) . ')'
					. ($sourceIds ? ' AND provider_account_id NOT IN (' . implode(',', $sourceIds) . ')' : ''));
			}
			$sourceCounts = array();
			foreach ($sources as $source) {
				$key = KreaBankFintableMapper::iban($source['iban'] ?? '');
				$sourceCounts[$key] = ($sourceCounts[$key] ?? 0) + 1;
			}
			foreach ($entities as $id => $label) {
				$result['entities'][$id] = array('label' => $label, 'mapped' => 0, 'imported' => 0, 'missing_connections' => 0);
			}
			$matchedNative = array();
			foreach ($sources as $source) {
				if (empty($source['enabled'])) {
					continue;
				}
				$iban = KreaBankFintableMapper::iban($source['iban'] ?? '');
				$candidates = $native[$iban] ?? array();
				if ($iban === '' || $sourceCounts[$iban] !== 1 || count($candidates) !== 1) {
					$result['unmapped']++;
					if (!$preview && $entities) {
						$this->query('UPDATE ' . $this->db->prefix() . 'kreabank_feed_account SET status=\'mapping_review\',error_code=\'A unique IBAN ownership match is required\''
							. ' WHERE workspace_id=' . $this->text($this->setting('KREABANK_FINTABLE_WORKSPACE'))
							. ' AND provider_account_id=' . $this->text($source['id'])
							. ' AND entity IN (' . implode(',', array_map('intval', array_keys($entities))) . ')');
					}
					continue;
				}
				$bank = $candidates[0];
				$entity = (int) $bank->entity;
				$result['mapped']++;
				$result['entities'][$entity]['mapped']++;
				$matchedNative[(int) $bank->rowid] = true;
				$mapping = null;
				try {
					KreaBankFintableClient::assertId($source['id'], 'acc_');
					if ($source['currency'] !== $bank->currency_code) {
						throw new RuntimeException('Bank account currencies do not match');
					}
					$connection = $connections[$source['connection_id']] ?? null;
					if ($preview) {
						if (!$connection || empty($connection['healthy']) || !empty($connection['needs_reconnect'])) {
							throw new RuntimeException('Bank authorization needs reconnecting in Fintable');
						}
						continue;
					}
					$conf->setEntityValues($this->db, $entity);
					$user = clone $actor;
					$user->loadRights('', 1);
					if (!isModEnabled('kreabank') || !$user->hasRight('banque', 'modifier')) {
						throw new RuntimeException('KreaBank import is not authorized in the owning entity');
					}
					$mapping = $this->saveAccount($bank, $source, $connection ?: array());
					if (!$connection || empty($connection['healthy']) || !empty($connection['needs_reconnect'])) {
						throw new RuntimeException('Bank authorization needs reconnecting in Fintable');
					}
					if (in_array($connection['sync_status']['state'] ?? '', array('queued', 'executing', 'retrying'), true)) {
						throw new RuntimeException('Bank refresh is still running; independent accounts continue');
					}
					$before = $result['imported'];
					$this->importAccount($mapping, $source, $user, $langs, $result);
					$result['entities'][$entity]['imported'] += $result['imported'] - $before;
				} catch (Throwable $e) {
					$result['failed']++;
					$result['entities'][$entity]['error'] = $e->getMessage();
					if ($mapping) {
						$this->query('UPDATE ' . $this->db->prefix() . 'kreabank_feed_account SET status=\'failed\',error_code=' . $this->text(substr($e->getMessage(), 0, 250)) . ' WHERE rowid=' . (int) $mapping->rowid . ' AND entity=' . $entity);
					}
				}
			}
			foreach ($native as $candidates) {
				foreach ($candidates as $bank) {
					if (!isset($matchedNative[(int) $bank->rowid])) {
						$result['entities'][(int) $bank->entity]['missing_connections']++;
					}
				}
			}
			$result['status'] = $result['failed'] ? 'partial' : 'completed';
			$result['final_attempt'] = $attempt >= 3;
			if ($runId) {
				$this->query('UPDATE ' . $this->db->prefix() . 'kreabank_feed_run SET status=' . $this->text($result['status']) . ',finished_at=' . $this->now() . ',result_json=' . $this->text(json_encode($result)) . ' WHERE rowid=' . $runId);
			}
			return $result;
		} catch (Throwable $e) {
			if ($runId) {
				$this->query('UPDATE ' . $this->db->prefix() . 'kreabank_feed_run SET status=\'failed\',finished_at=' . $this->now() . ',result_json=' . $this->text(json_encode(array('error' => $e->getMessage()))) . ' WHERE rowid=' . $runId);
			}
			throw $e;
		} finally {
			$user = $savedUser;
			$conf->setEntityValues($this->db, $savedEntity);
			$this->query('SELECT RELEASE_LOCK(' . $this->text($lock) . ')');
		}
	}

	public function activeEntities()
	{
		global $conf;
		if (!isModEnabled('multicompany')) {
			$entity = max(1, (int) $conf->entity);
			$enabled = $this->one('SELECT value FROM ' . $this->db->prefix() . 'const WHERE name=\'MAIN_MODULE_KREABANK\' AND entity=' . $entity);
			return $enabled && (string) $enabled->value === '1' ? array($entity => 'Company') : array();
		}
		$rows = $this->rows('SELECT e.rowid,e.label FROM ' . $this->db->prefix() . 'entity e'
			. ' LEFT JOIN ' . $this->db->prefix() . 'const c ON c.entity=e.rowid AND c.name=\'MAIN_MODULE_KREABANK\''
			. ' LEFT JOIN ' . $this->db->prefix() . 'const g ON g.entity=0 AND g.name=\'MAIN_MODULE_KREABANK\''
			. ' WHERE e.active=1 AND COALESCE(c.value,g.value,\'0\')=\'1\' ORDER BY e.rowid');
		$result = array();
		foreach ($rows as $row) {
			$result[(int) $row->rowid] = $row->label;
		}
		return $result;
	}

	private function nativeAccounts($entities)
	{
		if (!$entities) {
			return array();
		}
		$rows = $this->rows('SELECT rowid,entity,iban_prefix,currency_code,ref,label FROM ' . $this->db->prefix() . 'bank_account'
			. ' WHERE clos=0 AND courant<>2 AND entity IN (' . implode(',', array_map('intval', array_keys($entities))) . ')');
		$result = array();
		foreach ($rows as $row) {
			$iban = KreaBankFintableMapper::iban($row->iban_prefix);
			if ($iban !== '') {
				$result[$iban][] = $row;
			}
		}
		return $result;
	}

	private function saveAccount($bank, $source, $connection)
	{
		$workspace = $this->setting('KREABANK_FINTABLE_WORKSPACE');
		$mapping = $this->one('SELECT * FROM ' . $this->db->prefix() . 'kreabank_feed_account WHERE entity=' . (int) $bank->entity
			. ' AND fk_bank_account=' . (int) $bank->rowid . ' AND workspace_id=' . $this->text($workspace));
		$balance = KreaBankFintableMapper::decimal($source['balance'] ?? null, true);
		$available = KreaBankFintableMapper::decimal($source['balance_available'] ?? null, true);
		$values = 'provider_account_id=' . $this->text($source['id']) . ',provider_connection_id=' . $this->text($source['connection_id'])
			. ',iban=' . $this->text(KreaBankFintableMapper::iban($source['iban'])) . ',currency=' . $this->text($source['currency'])
			. ',balance=' . ($balance === null ? 'NULL' : $balance) . ',balance_available=' . ($available === null ? 'NULL' : $available)
			. ',bank_updated_at=' . $this->text((string) ($connection['last_successful_update'] ?? ''))
			. ',source_start_date=' . $this->text($source['sync_start_date'] ?? null) . ',last_attempt=' . $this->now();
		if (!$mapping) {
			$this->query('INSERT INTO ' . $this->db->prefix() . 'kreabank_feed_account SET entity=' . (int) $bank->entity
				. ',fk_bank_account=' . (int) $bank->rowid . ',workspace_id=' . $this->text($workspace) . ',datec=' . $this->now() . ',' . $values);
			$id = (int) $this->db->last_insert_id($this->db->prefix() . 'kreabank_feed_account');
		} else {
			$id = (int) $mapping->rowid;
			$this->query('UPDATE ' . $this->db->prefix() . 'kreabank_feed_account SET ' . $values . ' WHERE rowid=' . $id . ' AND entity=' . (int) $bank->entity);
		}
		return $this->one('SELECT * FROM ' . $this->db->prefix() . 'kreabank_feed_account WHERE rowid=' . $id . ' AND entity=' . (int) $bank->entity);
	}

	private function importAccount($mapping, $source, $actor, $langs, &$result)
	{
		require_once __DIR__ . '/KreaBankService.class.php';
		$service = new KreaBankService($this->db, $actor, $langs);
		$started = gmdate('Y-m-d\TH:i:s\Z');
		$since = !empty($mapping->watermark) ? gmdate('Y-m-d\TH:i:s\Z', strtotime($mapping->watermark) - 172800) : '';
		$lastDate = null;
		$firstDate = null;
		foreach ($this->api()->transactionPages($source['id'], $since) as $page) {
			$records = array();
			$ids = array();
			$hashes = array();
			foreach ($page as $transaction) {
				$record = KreaBankFintableMapper::transaction($transaction, $source, $mapping->workspace_id);
				if (isset($records[$record['id']])) {
					throw new RuntimeException('Repeated transaction ID in a Fintable page');
				}
				$records[$record['id']] = $record;
				$ids[] = $this->text($record['id']);
				foreach (array($record['external_id'], $record['previous_external_id']) as $external) {
					if ($external !== '') {
						$hashes[] = $this->text(hash('sha256', $external));
					}
				}
				$lastDate = $lastDate === null ? $record['line']['operation_date'] : max($lastDate, $record['line']['operation_date']);
				$firstDate = $firstDate === null ? $record['line']['operation_date'] : min($firstDate, $record['line']['operation_date']);
			}
			if (!$records) {
				continue;
			}
			$where = 'provider_transaction_id IN (' . implode(',', $ids) . ')';
			if ($hashes) {
				$where .= ' OR external_id_hash IN (' . implode(',', array_unique($hashes)) . ')';
			}
			$existing = $this->rows('SELECT * FROM ' . $this->db->prefix() . 'kreabank_feed_transaction WHERE entity=' . (int) $mapping->entity
				. ' AND fk_feed_account=' . (int) $mapping->rowid . ' AND (' . $where . ')');
			$byId = array();
			$byExternal = array();
			foreach ($existing as $row) {
				$byId[$row->provider_transaction_id] = $row;
				if ($row->external_id_hash) {
					$byExternal[$row->external_id_hash] = $row;
				}
			}
			if (!$this->db->begin()) {
				throw new RuntimeException('Cannot start Fintable import transaction');
			}
			try {
				$pageImported = 0;
				$pageUnchanged = 0;
				$conflict = null;
				$new = array();
				$lines = array();
				foreach ($records as $record) {
					$old = $byId[$record['id']] ?? null;
					foreach (array($record['external_id'], $record['previous_external_id']) as $external) {
						if ($external !== '' && isset($byExternal[hash('sha256', $external)])) {
							$candidate = $byExternal[hash('sha256', $external)];
							if ($old && $old->rowid !== $candidate->rowid) {
								throw new RuntimeException('Ambiguous Fintable transaction identity');
							}
							$old = $candidate;
						}
					}
					if ($old) {
						if ($old->financial_hash !== $record['financial_hash']) {
							$conflict = array($old, $record);
							throw new RuntimeException('A booked source amount or date changed; manual review is required');
						}
						if ($old->content_hash !== $record['content_hash']) {
							$this->query('UPDATE ' . $this->db->prefix() . 'kreabank_feed_transaction SET content_hash=' . $this->text($record['content_hash'])
								. ',source_payload=' . $this->text(json_encode($record['line'])) . ' WHERE rowid=' . (int) $old->rowid . ' AND entity=' . (int) $mapping->entity);
							// Do not rewrite a reconciled or materialized accounting record.
							$this->query('UPDATE ' . $this->db->prefix() . 'kreabank_statement_line SET description=' . $this->text($record['line']['description'])
								. ',counterparty_name=' . $this->text($record['line']['counterparty_name']) . ' WHERE rowid=' . (int) $old->fk_statement_line
								. ' AND entity=' . (int) $mapping->entity . ' AND status=0 AND fk_native_bank_line IS NULL');
						}
						$pageUnchanged++;
						continue;
					}
					$new[] = $record;
					if ($record['line']['amount'] !== '0') {
						$lines[] = $record['line'];
					}
				}
				if ($lines) {
					$import = $service->importStatementFromParsed(array('format' => 'fintable', 'lines' => $lines),
						'fintable_' . $source['id'] . '_' . gmdate('Ymd_His'), (int) $mapping->fk_bank_account, gmdate('Y-m-d'));
					$pageImported = (int) $import['imported_lines'];
				}
				foreach ($new as $record) {
					$line = null;
					if ($record['line']['amount'] !== '0') {
						$found = $this->rows('SELECT l.rowid FROM ' . $this->db->prefix() . 'kreabank_statement_line l INNER JOIN '
							. $this->db->prefix() . 'kreabank_statement s ON s.rowid=l.fk_statement AND s.entity=l.entity'
							. ' WHERE l.entity=' . (int) $mapping->entity . ' AND s.bank_account_id=' . (int) $mapping->fk_bank_account
							. ' AND l.line_uid=' . $this->text($record['uid']));
						if (count($found) !== 1) {
							throw new RuntimeException('Fintable staging identity could not be verified');
						}
						$line = (int) $found[0]->rowid;
					}
					$this->query('INSERT INTO ' . $this->db->prefix() . 'kreabank_feed_transaction SET entity=' . (int) $mapping->entity
						. ',fk_feed_account=' . (int) $mapping->rowid . ',provider_transaction_id=' . $this->text($record['id'])
						. ',external_id_hash=' . $this->text($record['external_id'] === '' ? null : hash('sha256', $record['external_id']))
						. ',source_uid=' . $this->text($record['uid']) . ',financial_hash=' . $this->text($record['financial_hash'])
						. ',content_hash=' . $this->text($record['content_hash']) . ',fk_statement_line=' . ($line === null ? 'NULL' : $line)
						. ',source_payload=' . $this->text(json_encode($record['line'])) . ',status=' . $this->text($line === null ? 'zero' : 'imported') . ',datec=' . $this->now());
				}
				$this->db->commit();
				$result['imported'] += $pageImported;
				$result['unchanged'] += $pageUnchanged;
			} catch (Throwable $e) {
				$this->db->rollback();
				if ($conflict !== null) {
					$this->query('UPDATE ' . $this->db->prefix() . 'kreabank_feed_transaction SET status=\'review\',conflict_payload='
						. $this->text(json_encode($conflict[1]['line'])) . ' WHERE rowid=' . (int) $conflict[0]->rowid . ' AND entity=' . (int) $mapping->entity);
					$result['conflicts']++;
				}
				throw $e;
			}
		}
		$bankTimestamp = !empty($mapping->bank_updated_at) ? strtotime($mapping->bank_updated_at) : false;
		$status = !$bankTimestamp || time() - $bankTimestamp > 129600 ? 'stale' : 'ready';
		$this->query('UPDATE ' . $this->db->prefix() . 'kreabank_feed_account SET watermark=' . $this->text($started)
			. ',history_from=' . $this->text($firstDate === null ? $mapping->history_from : min($firstDate, $mapping->history_from ?: $firstDate))
			. ',history_to=' . $this->text($lastDate === null ? $mapping->history_to : max($lastDate, $mapping->history_to ?: $lastDate)) . ',last_success=' . $this->now()
			. ',status=' . $this->text($status) . ',error_code=NULL WHERE rowid=' . (int) $mapping->rowid . ' AND entity=' . (int) $mapping->entity);
	}

	public function accountStatus($entity)
	{
		return $this->rows('SELECT b.rowid,b.ref,b.label,b.iban_prefix,b.currency_code,f.provider_account_id,f.balance,f.balance_available,'
			. 'f.bank_updated_at,f.history_from,f.history_to,f.last_success,f.status,f.error_code FROM ' . $this->db->prefix() . 'bank_account b'
			. ' LEFT JOIN ' . $this->db->prefix() . 'kreabank_feed_account f ON f.fk_bank_account=b.rowid AND f.entity=b.entity AND f.workspace_id='
			. $this->text($this->setting('KREABANK_FINTABLE_WORKSPACE'))
			. ' WHERE b.entity=' . (int) $entity . ' AND b.clos=0 AND b.courant<>2 ORDER BY b.ref');
	}

	public function lastRun()
	{
		return $this->one('SELECT status,started_at,finished_at,result_json FROM ' . $this->db->prefix() . 'kreabank_feed_run ORDER BY rowid DESC LIMIT 1');
	}

	public function installJob($actor)
	{
		$this->assertAdministrator($actor);
		require_once DOL_DOCUMENT_ROOT . '/cron/class/cronjob.class.php';
		$rows = $this->rows('SELECT rowid FROM ' . $this->db->prefix() . 'cronjob WHERE entity=0'
			. ' AND objectname=\'KreaBankFintableSync\' AND methodename=\'run\'');
		if (count($rows) > 1) {
			throw new RuntimeException('Duplicate Fintable scheduler jobs need administrator review');
		}
		$job = new Cronjob($this->db);
		if ($rows && $job->fetch((int) $rows[0]->rowid) <= 0) {
			throw new RuntimeException('Fintable scheduler job cannot be loaded');
		}
		$job->entity = 0;
		$job->label = 'KreaBank Fintable collection';
		$job->jobtype = 'method';
		$job->classesname = '/kreabank/class/KreaBankFintableSync.class.php';
		$job->objectname = 'KreaBankFintableSync';
		$job->methodename = 'run';
		$job->module_name = 'kreabank';
		$job->email_alert = $this->setting('KREABANK_FINTABLE_ALERT_EMAIL');
		$job->frequency = 15;
		$job->unitfrequency = 60;
		$job->priority = 50;
		$job->status = $this->setting('KREABANK_FINTABLE_ENABLED', '0') === '1' ? 1 : 0;
		$job->test = '1';
		$job->datestart = dol_now();
		$job->datenextrun = dol_now() + 300;
		$ok = $rows ? $job->update($actor) : $job->create($actor);
		if ($ok <= 0) {
			throw new RuntimeException('Fintable scheduler registration failed');
		}
		return (int) $job->id;
	}

	private function assertAdministrator($actor)
	{
		if (!is_object($actor) || empty($actor->id) || empty($actor->admin) || !empty($actor->entity) || !empty($actor->socid)) {
			throw new RuntimeException('An installation administrator is required for shared Fintable collection');
		}
	}

	private function text($value)
	{
		return $value === null ? 'NULL' : "'" . $this->db->escape((string) $value) . "'";
	}

	private function now()
	{
		return $this->text($this->db->idate(dol_now()));
	}

	private function query($sql)
	{
		$result = $this->db->query($sql);
		if (!$result) {
			throw new RuntimeException('Fintable storage operation failed; inspect database health');
		}
		return $result;
	}

	private function rows($sql)
	{
		$result = $this->query($sql);
		$rows = array();
		while ($row = $this->db->fetch_object($result)) {
			$rows[] = $row;
		}
		return $rows;
	}

	private function one($sql)
	{
		$rows = $this->rows($sql);
		return $rows ? $rows[0] : null;
	}
}
