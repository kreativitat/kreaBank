#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
if (PHP_SAPI !== 'cli') {
	http_response_code(403);
	exit;
}
$options = getopt('', array('root:', 'login:', 'apply', 'backup-dir:'));
$root = isset($options['root']) ? realpath($options['root']) : false;
if (!$root || !is_file($root . '/master.inc.php') || empty($options['login'])) {
	fwrite(STDERR, "Usage: php update_navigation.php --root=/dolibarr/htdocs --login=admin [--apply --backup-dir=/private/backup]\n");
	exit(2);
}
define('NOLOGIN', 1);
define('NOREQUIREMENU', 1);
define('NOREQUIREHTML', 1);
define('NOREQUIREAJAX', 1);
require $root . '/master.inc.php';
require_once DOL_DOCUMENT_ROOT . '/user/class/user.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
require_once __DIR__ . '/../core/modules/modKreaBank.class.php';
try {
	$user = new User($db);
	if ($user->fetch('', $options['login']) <= 0 || empty($user->admin) || !empty($user->entity) || !empty($user->socid) || (int) $user->statut !== 1) {
		throw new RuntimeException('An active installation administrator is required');
	}
	$rows = static function ($sql) use ($db) {
		$result = $db->query($sql);
		if (!$result) { throw new RuntimeException('Unable to read navigation registration'); }
		$rows = array();
		while ($row = $db->fetch_object($result)) { $rows[] = (array) $row; }
		return $rows;
	};
	$entities = $rows("SELECT DISTINCT entity FROM " . $db->prefix() . "const WHERE name = 'MAIN_MODULE_KREABANK' AND value <> '0' AND entity > 0 ORDER BY entity");
	$entityIds = array_map('intval', array_column($entities, 'entity'));
	if (!$entityIds) { throw new RuntimeException('KreaBank is not enabled in any company'); }
	if (!isset($options['apply'])) {
		print json_encode(array('entities' => $entityIds, 'apply_required' => true)) . "\n";
		exit;
	}
	$backup = isset($options['backup-dir']) ? realpath($options['backup-dir']) : false;
	if (!$backup || !is_writable($backup) || $backup === $root || strpos($backup, $root . '/') === 0) {
		throw new RuntimeException('An existing writable backup directory outside the webroot is required');
	}
	$backupFile = $backup . '/navigation-registration.json';
	if (file_exists($backupFile)) { throw new RuntimeException('Registration backup already exists; inspect the previous outcome'); }
	$snapshot = array(
		'entities' => $entityIds,
		'const' => $rows("SELECT * FROM " . $db->prefix() . "const WHERE name IN ('MAIN_MODULE_KREABANK_HOOKS', 'MAIN_MODULE_KREABANK_CSS')"),
		'menu' => $rows("SELECT * FROM " . $db->prefix() . "menu WHERE module = 'kreabank' AND menu_handler = 'all'"),
	);
	$encoded = json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
	if ($encoded === false || file_put_contents($backupFile, $encoded . "\n", LOCK_EX) === false || !chmod($backupFile, 0600)) {
		throw new RuntimeException('Unable to save navigation registration backup');
	}
	$originalEntity = $conf->entity;
	if (!$db->begin()) { throw new RuntimeException('Unable to start navigation update'); }
	try {
		foreach ($entityIds as $entityId) {
			$conf->entity = $entityId;
			$module = new modKreaBank($db);
			foreach (array('hooks', 'css') as $part) {
				if (dolibarr_set_const($db, 'MAIN_MODULE_KREABANK_' . strtoupper($part), json_encode($module->module_parts[$part]), 'chaine', 0, '', $entityId) <= 0) {
					throw new RuntimeException('Unable to update navigation hooks/assets');
				}
			}
			if ($module->delete_menus() !== 0 || $module->insert_menus() !== 0) {
				throw new RuntimeException('Unable to update KreaBank menus');
			}
		}
		if (!$db->commit()) { throw new RuntimeException('Unable to commit navigation update'); }
	} catch (Throwable $e) {
		$db->rollback();
		throw $e;
	} finally {
		$conf->entity = $originalEntity;
	}
	print json_encode(array('updated_entities' => $entityIds, 'backup' => $backupFile, 'business_data_changed' => false, 'scheduler_changed' => false)) . "\n";
} catch (Throwable $e) {
	fwrite(STDERR, $e->getMessage() . "\n");
	exit(1);
}
