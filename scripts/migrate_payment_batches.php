#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
$options = getopt('', array('root:', 'login:', 'apply'));
$root = isset($options['root']) ? realpath($options['root']) : false;
if (!$root || !is_file($root . '/master.inc.php') || empty($options['login'])) {
	fwrite(STDERR, "Usage: php migrate_payment_batches.php --root=/dolibarr/htdocs --login=admin [--apply]\n");
	exit(2);
}
define('NOLOGIN', 1);
define('NOSESSION', 1);
define('NOREQUIREMENU', 1);
define('NOREQUIREHTML', 1);
require $root . '/master.inc.php';
require_once DOL_DOCUMENT_ROOT . '/user/class/user.class.php';
try {
	$user = new User($db);
	if ($user->fetch('', $options['login']) <= 0 || empty($user->admin) || !empty($user->entity) || !empty($user->socid) || (int) $user->statut !== 1) {
		throw new RuntimeException('An active installation administrator is required');
	}
	$schema = file_get_contents(__DIR__ . '/../sql/llx_kreabank_payment_batch.sql');
	if ($schema === false) { throw new RuntimeException('Payment batch schema file is missing'); }
	$schema = str_replace('llx_', $db->prefix(), preg_replace('/--[^\n]*/', '', $schema));
	$tables = array('kreabank_payment_batch','kreabank_payment_batch_line','kreabank_payment_batch_draft');
	if (isset($options['apply'])) {
		// CREATE IF NOT EXISTS is rerunnable. DDL is deliberately outside financial transactions.
		foreach (explode(';', $schema) as $statement) {
			if (trim($statement) !== '' && !$db->query(trim($statement))) { throw new RuntimeException('Cannot install payment batch schema; inspect existing tables before retrying'); }
		}
	}
	$status = array();
	foreach ($tables as $table) {
		$result = $db->query("SHOW TABLES LIKE '" . $db->escape($db->prefix() . $table) . "'");
		$status[$table] = (bool) ($result && $db->num_rows($result) === 1);
	}
	print json_encode(array('applied'=>isset($options['apply']),'tables'=>$status,'business_data_changed'=>false,'scheduler_changed'=>false), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
} catch (Throwable $e) {
	fwrite(STDERR, $e->getMessage() . "\n");
	exit(1);
}
