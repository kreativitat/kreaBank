#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
// Load installed native evaluator and descriptor only; never bootstrap a database.
define('DOL_DOCUMENT_ROOT', getenv('KREABANK_DOLIBARR_ROOT') ?: dirname(__DIR__, 3));
require_once DOL_DOCUMENT_ROOT . '/core/lib/functions.lib.php';
require_once __DIR__ . '/../core/modules/modKreaBank.class.php';
$conf = (object) array('entity' => 1, 'global' => new stdClass());
$module = new modKreaBank(null);
$menus = array_values(array_filter($module->menu, static function ($item) { return $item['leftmenu'] === 'kreabank_automation'; }));
if ($menus) { throw new RuntimeException('Automatic reconciliation must not have a left-menu shortcut'); }
$menus = array_values(array_filter($module->menu, static function ($item) { return $item['leftmenu'] === 'kreabank_feed_readonly'; }));
if (count($menus) !== 1) { throw new RuntimeException('Bank-only readers retain the feed shortcut'); }
$perms = $menus[0]['perms'];
class NavigationPermissionUser
{
	public $admin = 1;
	public $socid = 0;
	public $allowed = array('banque.lire');
	public function hasRight(...$path) { return in_array(implode('.', $path), $this->allowed, true); }
}
$user = new NavigationPermissionUser();
$checks = 0;
function checkMenuPermission($expected)
{
	global $perms, $checks;
	if (verifCond($perms) !== $expected) { throw new RuntimeException('Native feed shortcut must be restricted to bank-only readers'); }
	$checks++;
}
checkMenuPermission(true);
$user->allowed[] = 'kreabank.reconciliation.read';
checkMenuPermission(false);
$user->allowed = array();
checkMenuPermission(false);
$user->allowed = array('kreabank.reconciliation.read');
checkMenuPermission(false);
print "OK: $checks native menu authorization checks\n";
