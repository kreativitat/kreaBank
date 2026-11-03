#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
$options = getopt('', array('root:', 'login:', 'apply', 'backup-dir:'));
$root = isset($options['root']) ? realpath($options['root']) : false;
if (!$root || !is_file($root . '/master.inc.php') || empty($options['login'])) {
	fwrite(STDERR, "Usage: php optimize_reconciliation.php --root=/dolibarr/htdocs --login=admin [--apply --backup-dir=/private/backup]\n");
	exit(2);
}
define('NOLOGIN', 1);
define('NOSESSION', 1);
define('NOREQUIREMENU', 1);
define('NOREQUIREHTML', 1);
require $root . '/master.inc.php';
require_once DOL_DOCUMENT_ROOT . '/user/class/user.class.php';
require_once __DIR__ . '/../class/KreaBankReviewIndexes.class.php';
try {
	$user = new User($db);
	if ($user->fetch('', $options['login']) <= 0 || empty($user->admin) || !empty($user->entity) || !empty($user->socid) || (int) $user->statut !== 1) {
		throw new RuntimeException('An active installation administrator is required');
	}
	$maintenance = new KreaBankReviewIndexes($db);
	if (!isset($options['apply'])) {
		print json_encode(array('plan' => $maintenance->plan()), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
		exit;
	}
	$backup = isset($options['backup-dir']) ? realpath($options['backup-dir']) : false;
	if (!$backup || !is_writable($backup) || $backup === $root || strpos($backup, $root . '/') === 0) {
		throw new RuntimeException('An existing writable backup directory outside the webroot is required');
	}
	$path = $backup . '/payment-index-journal.json';
	$handle = fopen($path, 'x');
	if (!$handle || !chmod($path, 0600)) { throw new RuntimeException('Cannot create a new private index journal; inspect any previous outcome'); }
	$state = array('started_at_utc' => gmdate('c'), 'events' => array());
	try {
		$result = $maintenance->apply(static function ($status, $plan, $entry) use (&$state, $handle) {
			$state['plan'] = $plan;
			$state['events'][] = array('status' => $status, 'entry' => $entry, 'at_utc' => gmdate('c'));
			$encoded = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
			if (!rewind($handle) || fwrite($handle, $encoded) !== strlen($encoded) || !ftruncate($handle, strlen($encoded)) || !fflush($handle)) {
				throw new RuntimeException('Cannot persist the payment index journal');
			}
		});
	} finally { fclose($handle); }
	print json_encode(array('plan' => $result, 'journal' => $path, 'business_data_changed' => false, 'scheduler_changed' => false), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
} catch (Throwable $e) {
	fwrite(STDERR, $e->getMessage() . "\n");
	exit(1);
}
