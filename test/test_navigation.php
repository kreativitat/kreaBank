#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
// Only installed source is loaded. No Dolibarr bootstrap, credentials or database.
define('DOL_DOCUMENT_ROOT', getenv('KREABANK_DOLIBARR_ROOT') ?: dirname(__DIR__, 3));
require_once __DIR__ . '/../lib/kreabank.lib.php';
require_once __DIR__ . '/../class/actions_kreabank.class.php';
require_once __DIR__ . '/../class/KreaBankNativeBankAdapter.class.php';
function dol_buildpath($path, $absolute = 0) { return '/erp' . $path; }
function accessforbidden() { throw new LogicException('Forbidden'); }
class NavigationTestUser
{
	public $allowed = array('banque.lire', 'kreabank.reconciliation.read');
	public function hasRight(...$path) { return in_array(implode('.', $path), $this->allowed, true); }
}
class NavigationTestLangs
{
	public function load($file) {}
	public function trans($key) { return $key; }
}
class NavigationTestDb
{
	public $queries = array();
	public $fail = false;
	public function prefix() { return 'navtest_'; }
	public function query($sql) { $this->queries[] = $sql; return !$this->fail; }
	public function fetch_object($result) { return false; }
	public function plimit($limit, $offset) { return ' LIMIT ' . (int) $offset . ', ' . (int) $limit; }
}
class NavigationTestAdapter extends KreaBankNativeBankAdapter
{
	protected function ensureMetaTable() {}
	protected function ensureStagingTables() {}
	protected function hasAnyNativeMetaImportedLines() { return false; }
}
$checks = 0;
function checkNavigation($ok, $message)
{
	global $checks;
	$checks++;
	if (!$ok) { throw new RuntimeException($message); }
}
$db = new NavigationTestDb();
$user = new NavigationTestUser();
$langs = new NavigationTestLangs();
$conf = (object) array('entity' => 7);
$head = array(array('/card?id=45', 'Account', 'bankname'), array('/entries?id=45', 'Movements', 'journal'), array('/entries?id=45&action=reconcile', 'Reconcile', 'reconcile'), array('/extension', 'Other module', 'external'));
$newHead = kreabankCompleteAccountHead($head, 45);
checkNavigation($newHead[2][2] === 'kreabank', 'Workspace follows native movements');
checkNavigation(strpos($newHead[2][0], 'account_context=45') !== false, 'Native account link retains account');
checkNavigation($newHead[3][1] === 'KreaBankStatementNumbering' && $newHead[3][0] === $head[2][0], 'Native numbering keeps its original action');
checkNavigation($newHead[4] === $head[3], 'Preserve tabs from other modules');
checkNavigation(count(kreabankCompleteAccountHead($newHead, 45)) === count($newHead), 'Repeated hooks do not duplicate tabs');
$tabs = kreabankPrepareHead(45);
checkNavigation(array_column($tabs, 2) === array('reconcile', 'feed', 'history'), 'Read-only users have no import tab');
$user->allowed[] = 'kreabank.reconciliation.write';
$user->allowed[] = 'banque.modifier';
checkNavigation(array_column(kreabankPrepareHead(45), 2) === array('reconcile', 'import', 'feed', 'history'), 'Four primary tasks in the same order');
foreach (kreabankPrepareHead(45) as $tab) {
	checkNavigation(strpos($tab[0], 'account_context=45') !== false && strpos($tab[0], 'line_id') === false, 'Carry account without copying a stale line/action');
}
$user->allowed = array('banque.lire');
checkNavigation(array_column(kreabankPrepareHead(45), 2) === array('feed'), 'Bank-only readers retain connections access');
checkNavigation(count(kreabankCompleteAccountHead($head, 45)) === count($head), 'No workspace tab without module read permission');
try { kreabankWorkspaceUrl('../admin/setup', 45); throw new RuntimeException('Unsafe page accepted'); } catch (InvalidArgumentException $e) { $checks++; }
try { kreabankNavigationAccount(45); throw new RuntimeException('Unknown/foreign account accepted'); } catch (LogicException $e) { $checks++; }
checkNavigation(strpos(end($db->queries), 'rowid = 45 AND entity = 7') !== false, 'Account context requires current-company ownership');
$db->fail = true;
try { kreabankNavigationAccount(45); throw new LogicException('Database error accepted'); } catch (RuntimeException $e) { $checks++; }
$db->fail = false;
checkNavigation(kreabankNavigationAccount(0) === null, 'Explicit all-accounts view');
checkNavigation(strpos(kreabankNavigationHiddenFields(45), 'value="45"') !== false, 'Forms preserve account context');
$adapter = new NavigationTestAdapter($db, $user, $langs, 7);
$db->queries = array();
$adapter->getPendingLines(10, 0, 'operation_date', 'ASC', array('bank_account_id' => 45));
$adapter->getPendingLinesCount(array('bank_account_id' => 45));
$adapter->getRecentReconciledLines(10, 0, 45);
foreach ($db->queries as $sql) {
	checkNavigation(strpos($sql, 'ba.rowid = 45') !== false && strpos($sql, 'ba.entity = 7') !== false, 'Scope native and staged reads before pagination');
}
$hooks = new ActionsKreaBank($db);
$object = (object) array('id' => 45);
$action = '';
$parameters = array('type' => 'product', 'mode' => 'add', 'head' => &$head);
checkNavigation($hooks->completeTabsHead($parameters, $object, $action, null) === 0, 'Do not change unrelated object tabs');
$user->allowed[] = 'kreabank.reconciliation.read';
$parameters['type'] = 'bank';
checkNavigation($hooks->completeTabsHead($parameters, $object, $action, null) === 1 && $hooks->results[2][2] === 'kreabank', 'Native hook returns the complete account head');
print "OK: $checks navigation and account-scope checks\n";
