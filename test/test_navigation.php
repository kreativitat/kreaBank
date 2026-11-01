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
	public function loadLangs($files) {}
	public function trans($key) { return $key; }
}
class NavigationTestDb
{
	public $queries = array();
	public $fail = false;
	public function prefix() { return 'navtest_'; }
	public function escape($value) { return addslashes($value); }
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
checkNavigation($newHead === $head, 'Shared header replaces native tabs without inserting another navigation layer');
$tabs = kreabankPrepareHead(45);
checkNavigation(array_column($tabs, 2) === array('movements', 'statement', 'reconcile', 'feed', 'history'), 'Operational tabs exclude account management');
$user->allowed[] = 'kreabank.reconciliation.write';
$user->allowed[] = 'banque.modifier';
checkNavigation(array_column(kreabankPrepareHead(45), 2) === array('movements', 'statement', 'reconcile', 'feed', 'history'), 'Write actions do not add another navigation layer');
foreach (kreabankPrepareHead(45) as $tab) {
	checkNavigation((strpos($tab[0], 'account_context=45') !== false || preg_match('/[?&]id=45(?:&|$)/', $tab[0])) && strpos($tab[0], 'line_id') === false, 'Carry account without copying a stale line/action');
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
checkNavigation($hooks->completeTabsHead($parameters, $object, $action, null) === 0, 'Native hook never adds a second bank tab bar');
function dol_escape_htmltag($text) { return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'); }
function GETPOSTINT($key) { return (int) ($GLOBALS['navigationRequest'][$key] ?? 0); }
function GETPOST($key, $type = '') { return $GLOBALS['navigationRequest'][$key] ?? ''; }
function GETPOSTISSET($key) { return isset($GLOBALS['navigationRequest'][$key]); }
$mysoc = (object) array('name' => 'Synthetic company');
$html = kreabankBankingHeader('accounts');
checkNavigation(strpos($html, '<nav ') === false && strpos($html, 'KreaBankLedgerExplanation') === false, 'Accounts are a standalone page without operational tabs');
checkNavigation(strpos($html, '<form') === false, 'Account overview keeps list filters outside the banking navigation');
$accountSelect = kreabankAccountFilterSelect();
checkNavigation(strpos($accountSelect, 'name="account_context"') !== false && strpos(end($db->queries), 'WHERE entity=7 ') !== false, 'Shared account control lists only current-company accounts');
$_SERVER['PHP_SELF'] = '/compta/bank/bankentries_list.php';
$navigationRequest = array('kreabank_matched' => 0);
$hooks->printFieldListWhere(array(),$object,$action,null);
checkNavigation(strpos($hooks->resprints, 'ba.entity=7') !== false, 'Native movements restrict owner company even when accounts are shared');
$navigationRequest = array('kreabank_matched' => 1);
$html = kreabankBankingHeader('movements', (object) array('id' => 45));
checkNavigation(strpos($html, 'name="view"') === false && strpos($html, 'name="account_context"') !== false && strpos($html, 'name="kind"') !== false, 'Operational pages use the new account/type filter without the old redirect form');
checkNavigation(strpos($html, 'id=45&amp;kreabank_matched=1') !== false && strpos($html, 'KreaBankReconciledMovements') !== false, 'Movement views retain selected account and expose reconciled entries');
checkNavigation(strpos($html, 'kb-more') === false && strpos($html, '<details') === false, 'Account tools do not return as a floating header menu');
$accountTools = kreabankAccountToolEntries(45);
checkNavigation(count($accountTools) === 6, 'Retain every selected-account tool');
foreach ($accountTools as $entry) {
	checkNavigation(strpos($entry['url'], '/compta/bank/') === 0 && (strpos($entry['url'], 'id=45') !== false || strpos($entry['url'], 'account=45') !== false), 'Native account-tab links keep the selected account and native URL convention');
}
try { kreabankAccountToolEntries(0); throw new RuntimeException('All-accounts tools accepted'); } catch (InvalidArgumentException $e) { $checks++; }
$html = kreabankBankingHeader('documents', (object) array('id' => 45));
checkNavigation(strpos($html, 'kb-account-tabs') !== false && preg_match('/aria-current="page"[^>]*href="[^"]*document\.php\?id=45/', $html), 'Account-tool tabs retain the selected account and mark the actual native page');
checkNavigation(strpos($html, '/admin/automation.php') === false, 'Automatic reconciliation is absent from operational tabs');
checkNavigation(kreabankBankingUrl('reports', 0) === kreabankWorkspaceUrl('accounts'), 'Clearing the account context returns account-specific pages to the overview');
function complete_head_from_modules(...$arguments) {}
checkNavigation(in_array('automation', array_column(kreabankAdminPrepareHead(), 2), true), 'Automatic reconciliation remains available in module configuration');
$_SERVER['PHP_SELF'] = '/compta/bank/budget.php';
$navigationRequest = array('id' => 45);
$queryCount = count($db->queries);
checkNavigation($hooks->doActions(array(), $object, $action, null) === 0 && count($db->queries) === $queryCount, 'The main hook does not interpret unrelated banking-resource IDs as account IDs');
$_SERVER['PHP_SELF'] = '/compta/bank/transfer.php';
$hooks->resprints = 'previous hook output';
$queryCount = count($db->queries);
checkNavigation($hooks->printCommonFooter(array(), $object, $action, null) === 0 && $hooks->resprints === '' && count($db->queries) === $queryCount, 'Internal transfers do not inject a workspace header, selector or tabs');
$navigationRequest = array('kind' => 'cash', 'account_context' => 45);
foreach (array('movements', 'statement', 'reconcile', 'feed', 'history') as $page) {
	$html = kreabankBankingHeader($page, (object) array('id' => 45));
	$dom = new DOMDocument();
	@$dom->loadHTML($html);
	$xpath = new DOMXPath($dom);
	checkNavigation($xpath->query('//header//form')->length === 0 && $xpath->query('//form[contains(@class,"kb-accounts-filters")]')->length === 1, 'Each operational page has one separate filter panel');
	checkNavigation($xpath->query('//nav[contains(@class,"kb-primary")]/a')->length === 5, 'Exactly five operational tabs remain for selected accounts');
	checkNavigation($xpath->query('//form/input[@name="view"]')->length === 0 && $xpath->query('//select[@name="kind"]/option[@value="cash"][@selected]')->length === 1, 'Account type submits on the current operational page');
	checkNavigation(strpos($html, 'KreaBankNavAccounts') === false && strpos($html, 'KreaBankAccountOptions') === false, 'Account management stays outside operational tabs');
}
checkNavigation(strpos(kreabankWorkspaceUrl('pending', 45), 'kind=cash') !== false && strpos(kreabankNativeEntriesUrl(45), 'kind=cash') !== false && strpos(kreabankStatementUrl(45), 'kind=cash') !== false, 'Type filter survives cross-page links');
checkNavigation(strpos(kreabankNavigationHiddenFields(0), 'name="kind" value="cash"') !== false, 'Search and action forms preserve type even in all-account views');
$db->queries = array();
$adapter->getPendingLines(10, 0, 'operation_date', 'ASC', array('bank_account_id' => 45, 'account_kind' => 'cash'));
$adapter->getPendingLinesCount(array('bank_account_id' => 45, 'account_kind' => 'cash'));
$adapter->getRecentReconciledLines(10, 0, 45, 'cash');
foreach ($db->queries as $sql) {
	checkNavigation(strpos($sql, 'ba.clos=0 AND ba.courant=2') !== false && strpos($sql, 'ba.entity = 7') !== false, 'Pending rows, counts and both history storage paths apply type within the owner company');
}
$_SERVER['PHP_SELF'] = '/compta/bank/bankentries_list.php';
$param = '';
$hooks->printFieldListWhere(array(), $object, $action, null);
checkNavigation(strpos($hooks->resprints, 'ba.entity=7') !== false && strpos($hooks->resprints, 'ba.rowid=45') !== false && strpos($hooks->resprints, 'ba.courant=2') !== false, 'Native movement filtering applies both fields before pagination');
checkNavigation(strpos($param, 'account_context=45') !== false && strpos($param, 'kind=cash') !== false, 'Native sort and pagination links preserve both filters');
$hooks->printFieldPreListTitle(array(), $object, $action, null);
checkNavigation(strpos($hooks->resprints, 'name="account_context" value="45"') !== false && strpos($hooks->resprints, 'name="kind" value="cash"') !== false, 'Native search forms retain the shared filter');
$navigationRequest = array('account_context' => 0, 'id' => 999);
try { $hooks->doActions(array(), $object, $action, null); throw new RuntimeException('Foreign native ID accepted'); } catch (LogicException $e) { $checks++; }
require_once __DIR__ . '/../class/KreaBankFintableSync.class.php';
$feed = new KreaBankFintableSync($db);
$feed->accountStatus(7, true, 'cash');
checkNavigation(strpos(end($db->queries), 'b.entity=7') !== false && strpos(end($db->queries), 'b.courant=2') !== false && strpos(end($db->queries), 'b.courant<>2') !== false, 'Connection filters retain owner-company and open-bank eligibility');
$navigationRequest = array('kind' => 'invalid');
checkNavigation(kreabankNavigationKind() === 'all', 'Unknown request type normalizes to the operational default');
try { kreabankAccountKindSql('invalid'); throw new RuntimeException('Invalid type accepted'); } catch (InvalidArgumentException $e) { $checks++; }
$navigationRequest = array();
print "OK: $checks navigation and account-scope checks\n";
