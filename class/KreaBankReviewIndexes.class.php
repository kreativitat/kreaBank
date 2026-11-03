<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/** Explicit online maintenance for payment lookups; never invoked by a web request. */
class KreaBankReviewIndexes
{
	private $db;
	private $prefix;
	private const INDEXES = array(
		'idx_kb_payment_scope_date' => array('entity', 'datep', 'fk_bank'),
		'idx_kb_payment_bank_scope' => array('fk_bank', 'entity'),
	);

	public function __construct($db)
	{
		$this->db = $db;
		$this->prefix = $db->prefix();
		if (!preg_match('/^[a-zA-Z0-9_]+$/D', $this->prefix)) {
			throw new RuntimeException('Unsupported database prefix');
		}
	}

	private function rows($sql)
	{
		$result = $this->db->query($sql);
		if (!$result) { throw new RuntimeException('Payment index maintenance query failed'); }
		if ($result === true) { return array(); }
		$rows = array();
		while ($row = $this->db->fetch_object($result)) { $rows[] = (array) $row; }
		return $rows;
	}

	/** Read-only plan; reuse full, active BTREE indexes with the required left prefix. */
	public function plan()
	{
		$version = $this->rows('SELECT VERSION() AS version')[0]['version'];
		if (stripos($version, 'MariaDB') === false || version_compare($version, '10.3', '<')) {
			throw new RuntimeException('Online payment index maintenance requires MariaDB 10.3 or newer');
		}
		$plan = array();
		foreach (array('paiement', 'paiementfourn') as $suffix) {
			$table = $this->prefix . $suffix;
			$status = $this->rows("SHOW TABLE STATUS WHERE Name = '$table'");
			if (count($status) !== 1 || strcasecmp($status[0]['Engine'], 'InnoDB') !== 0) {
				throw new RuntimeException('Payment index maintenance requires existing InnoDB payment tables');
			}
			$indexes = array();
			foreach ($this->rows("SHOW INDEX FROM `$table`") as $row) {
				$name = $row['Key_name'];
				$indexes[$name]['columns'][(int) $row['Seq_in_index'] - 1] = $row['Column_name'];
				$indexes[$name]['usable'] = ($indexes[$name]['usable'] ?? true)
					&& $row['Index_type'] === 'BTREE' && $row['Sub_part'] === null
					&& ($row['Ignored'] ?? 'NO') !== 'YES';
			}
			foreach ($indexes as &$index) { ksort($index['columns']); $index['columns'] = array_values($index['columns']); }
			unset($index);
			foreach (self::INDEXES as $name => $columns) {
				if (isset($indexes[$name]) && (!$indexes[$name]['usable'] || $indexes[$name]['columns'] !== $columns)) {
					throw new RuntimeException('Conflicting payment index definition: ' . $table . '.' . $name);
				}
				$existing = null;
				foreach ($indexes as $candidate => $index) {
					if ($index['usable'] && array_slice($index['columns'], 0, count($columns)) === $columns) {
						$existing = $candidate;
						break;
					}
				}
				$plan[] = array('table' => $table, 'index' => $name, 'columns' => $columns, 'existing' => $existing,
					'sql' => "ALTER TABLE `$table` ADD INDEX `$name` (`" . implode('`,`', $columns) . '`), ALGORITHM=INPLACE, LOCK=NONE',
					'rollback' => "ALTER TABLE `$table` DROP INDEX `$name`, ALGORITHM=INPLACE, LOCK=NONE");
			}
		}
		return $plan;
	}

	/** Journal before DDL because an interrupted online ALTER can have an unknown outcome. */
	public function apply(callable $journal)
	{
		$lock = 'kreabank_review_indexes_' . substr(hash('sha256', $this->prefix), 0, 24);
		if ((int) $this->rows("SELECT GET_LOCK('$lock', 0) AS acquired")[0]['acquired'] !== 1) {
			throw new RuntimeException('Payment index maintenance is already running');
		}
		$wait = null;
		try {
			$jobs = $this->rows("SELECT COUNT(*) AS active FROM `" . $this->prefix . "cronjob` WHERE module_name='kreabank' AND processing=1");
			if ((int) $jobs[0]['active'] !== 0) { throw new RuntimeException('A KreaBank job is active'); }
			$plan = $this->plan();
			$journal('planned', $plan, null);
			$wait = (int) $this->rows('SELECT @@SESSION.lock_wait_timeout AS timeout')[0]['timeout'];
			$this->rows('SET SESSION lock_wait_timeout=5');
			foreach ($plan as $entry) {
				if ($entry['existing'] !== null) { continue; }
				$journal('applying', $plan, $entry);
				$this->rows($entry['sql']);
				$verified = $this->plan();
				foreach ($verified as $current) {
					if ($current['table'] === $entry['table'] && $current['index'] === $entry['index'] && $current['existing'] === null) {
						throw new RuntimeException('Payment index was not found after online DDL');
					}
				}
				$journal('applied', $plan, $entry);
			}
			$journal('complete', $plan, null);
			return $this->plan();
		} finally {
			try {
				if ($wait !== null) { $this->rows('SET SESSION lock_wait_timeout=' . $wait); }
			} finally { $this->rows("SELECT RELEASE_LOCK('$lock')"); }
		}
	}
}
