#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

// Source contract only: no ERP bootstrap, no database, no request is executed.
$root = dirname(__DIR__);
$checks = 0;
$check = static function ($condition, $label) use (&$checks) { if (!$condition) { throw new RuntimeException($label); } $checks++; };
$list = file_get_contents($root . '/reconciliationlist.php');
$workspace = file_get_contents($root . '/line_workspace.inc.php');
$retired = file_get_contents($root . '/reconcile.php');

// The retired address is a redirect only.
$check(strpos($retired, "kreabankWorkspaceUrl('reconcile'") !== false && strpos($retired, 'KreaBankService') === false
	&& strpos($retired, "GETPOST('action'") === false && strpos($retired, '$_POST') === false && strpos($retired, 'llxHeader') === false
	&& substr_count($retired, "\n") < 70, 'reconcile.php only redirects to the workspace inside the list');
$check(strpos($retired, 'accessforbidden();') !== false && strpos($retired, 'accessforbidden();') < strpos($retired, "header('Location: '"), 'the redirect keeps the read permission check');

// The workspace cannot be requested directly and has no second bootstrap.
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/line_workspace.inc.php') . ' 2>&1', $output, $code);
$check(trim(implode('', $output)) === '', 'the workspace file produces nothing when requested directly');
$check(strpos($workspace, "if (!defined('KREABANK_LINE_WORKSPACE')) {") !== false && strpos($workspace, 'main.inc.php') === false, 'the workspace has a direct-access guard and no bootstrap of its own');
foreach (array("!\$user->hasRight('kreabank', 'reconciliation', 'read')", "!\$user->hasRight('banque', 'lire')", 'kreabankIsTokenValid()') as $needle) {
	$check(strpos($workspace, $needle) !== false, 'the workspace keeps its own check: ' . $needle);
}

// Routing happens after the list page's own permission check and before any list logic.
$route = strpos($list, "define('KREABANK_LINE_WORKSPACE', 1);");
$check($route !== false && strpos($list, 'accessforbidden(); }') < $route && $route < strpos($list, '$service = new KreaBankService('), 'the list page routes after its access check and before its own controller');
$check(preg_match("/in_array\\(GETPOST\\('action','aZ09'\\), array\\(([^)]*)\\), true\\)\\)\\) \\{\\s*define\\('KREABANK_LINE_WORKSPACE'/", $list, $m) === 1, 'routed workspace actions are an explicit list');
preg_match_all("/'([a-z_]+)'/", $m[1], $routed);
preg_match_all("/\\\$action === '([a-z_]+)'/", $workspace, $workspaceActions);
preg_match_all("/\\\$action === '([a-z_]+)'/", substr($list, $route), $listActions);
$missing = array_diff(array_unique($workspaceActions[1]), $routed[1]);
$check(!$missing, 'every workspace action is routed: ' . implode(',', $missing));
$check(!array_intersect($routed[1], array_unique($listActions[1])) && count($listActions[1]) >= 6, 'workspace and list actions never overlap');

// Every workspace form and link comes back to the workspace.
$check(substr_count($workspace, '<form') === substr_count($workspace, '<input type="hidden" name="workspace" value="1">') && substr_count($workspace, '<form') >= 8, 'every workspace form carries the workspace marker');
$check(strpos($workspace, '/reconcile.php') === false, 'the workspace builds no link to the retired address');
$navigation = file_get_contents($root . '/lib/kreabank_navigation.lib.php');
$check(strpos($navigation, "'?mainmenu=bank&leftmenu=bank&workspace=1'") !== false, 'workspace URLs point into the reconciliation list');
foreach (array('history.php', 'pending.php', 'import.php', 'admin/guide.php', 'kreabankindex.php', 'lib/kreabank.lib.php', 'bulkmatch.php', 'class/KreaBankService.class.php') as $file) {
	$check(strpos(file_get_contents($root . '/' . $file), '/kreabank/reconcile.php') === false, 'no link to the retired address remains in ' . $file);
}
$view = file_get_contents($root . '/lib/kreabank_worklist_view.lib.php');
$check(strpos($view, "kreabankWorkspaceUrl('reconcile'") === false && strpos($view, "'mode'=>'more'") !== false && strpos($view, 'KreaBankWorklistMoreOptions') !== false, 'more options open inside the list row, never the line workspace');
$check(strpos($list, "'more_rule','more_writeoff','more_draft'") !== false && strpos($list, "'more_entry'") !== false && strpos(substr($list, 0, strpos($list, 'line_workspace.inc.php')), 'more_entry') === false, 'the row action stays a list action');
foreach (array('en_US', 'pt_PT') as $language) {
	$check(strpos(file_get_contents($root . '/langs/' . $language . '/worklist.lang'), 'KreaBankWorklistMoreOptions=') !== false, 'row link label exists in ' . $language);
}

echo 'OK: ' . $checks . " line workspace routing checks (source contract, no database)\n";
