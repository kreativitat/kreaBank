#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
if (PHP_SAPI !== 'cli') {
	http_response_code(403);
	exit;
}
$options = getopt('', array('root:', 'login:', 'apply', 'preview', 'collect'));
$root = isset($options['root']) ? realpath($options['root']) : false;
if (!$root || !is_file($root . '/master.inc.php') || empty($options['login'])) {
	fwrite(STDERR, "Usage: php install_fintable.php --root=/dolibarr/htdocs --login=admin [--apply|--preview|--collect]\nConfiguration JSON, including a replacement token if needed, is accepted only on stdin.\n");
	exit(2);
}
define('NOLOGIN', 1);
define('NOREQUIREMENU', 1);
define('NOREQUIREHTML', 1);
define('NOREQUIREAJAX', 1);
define('NOREQUIRETRAN', 1);
require $root . '/master.inc.php';
require_once DOL_DOCUMENT_ROOT . '/user/class/user.class.php';
require_once __DIR__ . '/../class/KreaBankFintableSync.class.php';
try {
	$user = new User($db);
	if ($user->fetch('', $options['login']) <= 0 || empty($user->admin) || !empty($user->entity) || (int) $user->statut !== 1) {
		throw new RuntimeException('An active installation administrator is required');
	}
	$user->loadRights('', 1);
	$sync = new KreaBankFintableSync($db);
	if (isset($options['apply'])) {
		$input = stream_get_contents(STDIN, 65536);
		$values = json_decode($input, true);
		if (!is_array($values)) {
			throw new RuntimeException('Configuration JSON is required on stdin');
		}
		$sync->ensureSchema();
		$sync->configure($values, $user);
		$job = $sync->installJob($user);
		print json_encode(array('configured' => true, 'cron_job_id' => $job, 'credential_encrypted' => strpos($sync->setting('KREABANK_FINTABLE_TOKEN'), 'dolcrypt:') === 0)) . "\n";
	} elseif (isset($options['collect']) || isset($options['preview'])) {
		print json_encode($sync->collect($user, null, !isset($options['collect']))) . "\n";
	} else {
		print json_encode(array('active_entities' => $sync->activeEntities(), 'apply_required' => true)) . "\n";
	}
} catch (Throwable $e) {
	fwrite(STDERR, $e->getMessage() . "\n");
	exit(1);
}
