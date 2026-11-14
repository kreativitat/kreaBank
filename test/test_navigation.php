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
$conf = (object) array('entity' => 7, 'global' => new stdClass());
$head = array(array('/card?id=45', 'Account', 'bankname'), array('/entries?id=45', 'Movements', 'journal'), array('/entries?id=45&action=reconcile', 'Reconcile', 'reconcile'), array('/extension', 'Other module', 'external'));
$newHead = kreabankCompleteAccountHead($head, 45);
checkNavigation($newHead === $head, 'Shared header replaces native tabs without inserting another navigation layer');
checkNavigation(strpos(kreabankWorkspaceUrl('reconcile', 45), 'reconcile.php') !== false, 'Dormant reconciliation keeps its direct route');
$tabs = kreabankPrepareHead(45);
checkNavigation(array_column($tabs, 2) === array('movements', 'bankstatement', 'statement', 'history'), 'Operational tabs exclude account management');
$user->allowed[] = 'kreabank.reconciliation.write';
$user->allowed[] = 'banque.modifier';
checkNavigation(array_column(kreabankPrepareHead(45), 2) === array('movements', 'bankstatement', 'statement', 'history'), 'Write actions do not add another navigation layer');
foreach (kreabankPrepareHead(45) as $tab) {
	checkNavigation((strpos($tab[0], 'account_context=45') !== false || preg_match('/[?&]id=45(?:&|$)/', $tab[0])) && strpos($tab[0], 'line_id') === false, 'Carry account without copying a stale line/action');
}
$user->allowed = array('banque.lire');
checkNavigation(kreabankPrepareHead(45) === array(), 'Bank-only feed access does not add an operational tab');
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
checkNavigation(strpos($html, 'kb-reconcile-action') !== false && strpos($html, 'reconciliationlist.php?mainmenu=bank&amp;leftmenu=bank') !== false, 'Account overview visibly opens the new reconciliation list');
checkNavigation(strpos(kreabankBankingUrl('worklist'), 'search_account=') === false, 'Combined reconciliation link omits an account filter');
checkNavigation(strpos(kreabankBankingUrl('worklist', 45), 'search_account=45') !== false && strpos(kreabankBankingUrl('worklist', 45), 'account_context=') === false, 'New list links use its actual account-filter contract');
$savedPermissions = $user->allowed;
foreach (array(array('banque.lire'), array('kreabank.reconciliation.read'), array()) as $permissions) {
	$user->allowed = $permissions;
	checkNavigation(strpos(kreabankBankingHeader('accounts'), 'kb-reconcile-action') === false, 'Reconciliation entry requires both native and module read rights');
}
$user->allowed = $savedPermissions;
$user->socid = 123;
checkNavigation(strpos(kreabankBankingHeader('accounts'), 'kb-reconcile-action') === false, 'External users cannot see the reconciliation action');
$user->socid = 0;
$accountSelect = kreabankAccountFilterSelect();
checkNavigation(strpos($accountSelect, 'name="account_context"') !== false && strpos(end($db->queries), 'WHERE entity=7 ') !== false, 'Shared account control lists only current-company accounts');
$_SERVER['PHP_SELF'] = '/compta/bank/bankentries_list.php';
$navigationRequest = array('kreabank_matched' => 0);
$hooks->printFieldListWhere(array(),$object,$action,null);
checkNavigation(strpos($hooks->resprints, 'ba.entity=7') !== false, 'Native movements restrict owner company even when accounts are shared');
$navigationRequest = array('kreabank_matched' => 1);
$html = kreabankBankingHeader('movements', (object) array('id' => 45));
checkNavigation(strpos($html, 'name="view"') === false && strpos($html, 'name="account_context"') === false && strpos($html, 'name="kind"') === false, 'Selected-account pages have no account-switching form');
checkNavigation(strpos($html, 'id=45&amp;kreabank_matched=1') !== false && strpos($html, 'KreaBankReconciledMovements') !== false, 'Movement views retain selected account and expose reconciled entries');
checkNavigation(strpos(kreabankNativeEntriesUrl(45, true), 'kreabank_matched=1') !== false && strpos(kreabankNativeEntriesUrl(45, false), 'kreabank_matched=') === false, 'Existing boolean native-entry links retain their matched/all contract');
$navigationRequest = array('kreabank_matched' => 2, 'account_context' => 45);
$html = kreabankBankingHeader('movements', (object) array('id' => 45));
checkNavigation(preg_match('/class="active" aria-current="page" href="[^"]*id=45&amp;kreabank_matched=2/',$html) === 1 && strpos($html, 'KreaBankUnreconciledMovements') !== false, 'Unreconciled view has its own selected control and owned-account link');
checkNavigation(strpos(kreabankBankingFilters('movements'), 'name="kreabank_matched" value="2"') !== false, 'All-account filtering preserves the unreconciled view');
$param = '';
$hooks->printFieldListWhere(array(), $object, $action, null);
checkNavigation(strpos($hooks->resprints, 'ba.entity=7') !== false && strpos($hooks->resprints, 'ba.rowid=45') !== false && strpos($hooks->resprints, 'b.rappro=0') !== false && strpos($hooks->resprints, 'EXISTS') === false, 'Unreconciled filters native status and company/account before rows and counts, without requiring imported metadata');
checkNavigation(strpos($param, 'kreabank_matched=2') !== false && substr_count($param, 'kreabank_matched=') === 1, 'Sort and pagination retain the exact unreconciled filter');
$hooks->printFieldPreListTitle(array(), $object, $action, null);
checkNavigation(strpos($hooks->resprints, 'name="kreabank_matched" value="2"') !== false, 'Native search forms retain the unreconciled filter');
$navigationRequest = array('kreabank_matched' => 999);
$param = '';
$hooks->printFieldListWhere(array(), $object, $action, null);
checkNavigation(kreabankMovementFilter() === 0 && strpos($hooks->resprints, 'rappro') === false && strpos($param, 'kreabank_matched') === false, 'Unknown movement filters normalize to all movements');
$navigationRequest = array('kreabank_matched' => 1);
checkNavigation(strpos($html, 'kb-more') === false && strpos($html, '<details') === false, 'Account tools do not return as a floating header menu');
$accountTools = kreabankAccountToolEntries(45);
checkNavigation(array_column($accountTools, 'key') === array('card', 'documents', 'reports'), 'Only details, documents and reports remain in account tools');
foreach (kreabankAccountToolEntries(45, true) as $entry) {
	checkNavigation(strpos($entry['url'], '/compta/bank/') === 0 && (strpos($entry['url'], 'id=45') !== false || strpos($entry['url'], 'account=45') !== false), 'Native account-tab links keep the selected account and native URL convention');
	if ($entry['key'] === 'forecast') {
		parse_str(parse_url($entry['url'], PHP_URL_QUERY), $forecastQuery);
		checkNavigation(($forecastQuery['account'] ?? '') === '45' && !isset($forecastQuery['id']), 'Native treasury requires account; id alone skips account loading and the page header');
		checkNavigation(kreabankBankingUrl('forecast', 45) === dol_buildpath($entry['url'], 1), 'Account-filter forecast redirects use the same native treasury contract');
	}
}
try { kreabankAccountToolEntries(0); throw new RuntimeException('All-accounts tools accepted'); } catch (InvalidArgumentException $e) { $checks++; }
$html = kreabankBankingHeader('documents', (object) array('id' => 45));
checkNavigation(strpos($html, 'kb-account-tabs') !== false && preg_match('/aria-current="page"[^>]*href="[^"]*document\.php\?id=45/', $html), 'Account-tool tabs retain the selected account and mark the actual native page');
checkNavigation(strpos($html, '/admin/automation.php') === false, 'Automatic reconciliation is absent from operational tabs');
$html = kreabankBankingHeader('matched', (object) array('id' => 45));
checkNavigation(preg_match('/aria-current="page"[^>]*href="[^"]*matched\.php\?[^" ]*account_context=45/', $html) === 1, 'Matched review has its own active subtab and preserves the owned account');
checkNavigation(strpos($html, '<form') === false, 'Matched review keeps its account without offering a switch');
checkNavigation(strpos($html, 'KreaBankReviewOneByOne') !== false && strpos($html, 'KreaBankBulkMatch') !== false, 'Existing individual and bulk reviews remain available');
$html = kreabankBankingHeader('forecast', (object) array('id' => 45));
checkNavigation(strpos($html, 'treso.php') === false && strpos($html, 'releve.php') === false && strpos($html, 'action=reconcile') === false, 'Legacy native tools remain directly accessible without visible tabs');
checkNavigation(kreabankBankingUrl('reports', 0) === kreabankWorkspaceUrl('accounts'), 'Clearing the account context returns account-specific pages to the overview');
$html = kreabankBankingHeader('worklist', (object) array('id' => 45, 'ref' => 'Demo', 'label' => ''), false);
checkNavigation(strpos($html, '>Demo</span>') !== false && strpos($html, 'kb-company') === false && strpos($html, '<form') === false, 'Selected worklist uses bank identity without an empty-label separator or duplicate account filter');
checkNavigation(strpos($html, 'kb-reconcile-action') === false && kreabankBankingFilters('worklist') === '', 'The list keeps its own filters without a redundant self-action');
foreach (array('movements','bankstatement','statement','history','card','documents','reports') as $entryPage) {
	$html = kreabankBankingHeader($entryPage, (object) array('id'=>45,'ref'=>'Demo','label'=>'Synthetic account'));
	$dom = new DOMDocument();
	@$dom->loadHTML('<meta charset="utf-8">' . $html);
	$xpath = new DOMXPath($dom);
	$entries = $xpath->query('//a[contains(concat(" ", normalize-space(@class), " "), " kb-reconcile-action ")]');
	checkNavigation($entries->length === 1 && strpos($entries->item(0)->getAttribute('href'), 'reconciliationlist.php') !== false && strpos($entries->item(0)->getAttribute('href'), 'search_account=45') !== false, 'Every banking header exposes the new tool with its owned account');
	checkNavigation($xpath->query('//nav[contains(@class,"kb-primary")]/a')->length === ($entryPage === 'card' || $entryPage === 'documents' || $entryPage === 'reports' ? 0 : 4), 'The new entry adds no operational tab');
}
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
$_SERVER['PHP_SELF'] = '/compta/bank/card.php';
$action = 'create';
checkNavigation($hooks->printCommonFooter(array(), $object, $action, null) === 0 && $hooks->resprints === '' && count($db->queries) === $queryCount, 'New-account creation keeps only the native form without workspace navigation');
$action = '';
$navigationRequest = array('kind' => 'cash', 'account_context' => 45);
foreach (array('movements', 'bankstatement', 'statement', 'reconcile', 'matched', 'bulkmatch', 'pending', 'feed', 'history') as $page) {
	$queriesBefore = count($db->queries);
	$html = kreabankBankingHeader($page, (object) array('id' => 45, 'ref' => 'Synthetic <account>', 'label' => 'Current & savings'));
	$dom = new DOMDocument();
	@$dom->loadHTML('<meta charset="utf-8">' . $html);
	$xpath = new DOMXPath($dom);
	checkNavigation(strpos($html, 'Synthetic company') === false && strpos($html, 'kb-company') === false, 'Bank identity replaces company text');
	checkNavigation($xpath->query('//form')->length === 0 && $xpath->query('//select[@name="account_context"]')->length === 0, 'Every selected-account view removes the account switch');
	checkNavigation(count($db->queries) === $queriesBefore, 'Locked account views never load a list of other accounts');
	checkNavigation($xpath->query('//nav[contains(@class,"kb-primary")]/a')->length === 4, 'Four operational tabs leave old reconciliation dormant');
	checkNavigation($xpath->query('//div[contains(@class,"kb-brand")]/span[@class="kb-account-context"]')->item(0)->textContent === 'Synthetic <account> — Current & savings', 'The bank reference and label remain visible and escaped beside the title');
	foreach ($xpath->query('//nav/a | //div[contains(@class,"kb-view-switch")]/a') as $link) {
		checkNavigation(preg_match('/[?&](?:id|account_context)=45(?:&|$)/', $link->getAttribute('href')) === 1, 'All operational and subview links retain the selected account');
	}
	$html = kreabankBankingHeader($page);
	$dom = new DOMDocument();
	@$dom->loadHTML('<meta charset="utf-8">' . $html);
	$xpath = new DOMXPath($dom);
	checkNavigation($xpath->query('//span[@class="kb-account-context"]')->item(0)->textContent === 'KreaBankAllAccounts', 'All-account views identify their combined scope without naming an arbitrary bank');
	$accountField = $page === 'movements' ? 'id' : 'account_context';
	checkNavigation($xpath->query('//form[contains(@class,"kb-accounts-filters")]')->length === 1 && $xpath->query('//select[@name="' . $accountField . '"]')->length === 1, 'Unscoped pages retain their current-company selector using the destination account parameter');
	if ($page === 'movements') {
		checkNavigation($xpath->query('//*[@name="account_context"]')->length === 0 && strpos($html, 'bankentries_list.php') !== false, 'Native account selection submits id without conflicting account_context, locking the destination account control');
	}
	checkNavigation((strpos(end($db->queries), 'EXISTS (SELECT 1 FROM navtest_kreabank_statement') !== false) === in_array($page, array('bankstatement', 'statement'), true), 'Only unscoped statement views restrict account choices to imported statements');
}
checkNavigation(strpos(kreabankWorkspaceUrl('pending', 45), 'kind=') === false && strpos(kreabankNativeEntriesUrl(45), 'kind=') === false && strpos(kreabankStatementUrl(45), 'kind=') === false, 'Account types do not carry into operational links');
checkNavigation(kreabankNavigationHiddenFields(0) === '', 'Operational forms never keep a hidden account-type filter');
$html = kreabankBankingFilters('accounts', 45, 'closed');
checkNavigation(strpos($html, '<form') === false && strpos($html, '<select') === false && substr_count($html, '<a') === 4 && preg_match('/class="active" aria-current="page" href="[^"]*kind=closed/', $html) && strpos($html, 'account_context=') === false, 'Account overview uses four compact type links, with no account selector or dropdown');
$db->queries = array();
$adapter->getPendingLines(10, 0, 'operation_date', 'ASC', array('bank_account_id' => 45));
$adapter->getPendingLinesCount(array('bank_account_id' => 45));
$adapter->getRecentReconciledLines(10, 0, 45);
foreach ($db->queries as $sql) {
	checkNavigation(strpos($sql, 'ba.rowid = 45') !== false && strpos($sql, 'ba.entity = 7') !== false, 'Pending rows, counts and both history storage paths retain selected-account and owner-company scope');
}
$_SERVER['PHP_SELF'] = '/compta/bank/bankentries_list.php';
$param = '';
$hooks->printFieldListWhere(array(), $object, $action, null);
checkNavigation(strpos($hooks->resprints, 'ba.entity=7') !== false && strpos($hooks->resprints, 'ba.rowid=45') !== false && strpos($hooks->resprints, 'courant') === false && strpos($hooks->resprints, 'clos') === false, 'Native movements apply only account and owner-company filters before pagination');
checkNavigation(strpos($param, 'account_context=45') !== false && strpos($param, 'kind=') === false, 'Native sort and pagination preserve the account without an account type');
$hooks->printFieldPreListTitle(array(), $object, $action, null);
checkNavigation(strpos($hooks->resprints, 'name="account_context" value="45"') !== false && strpos($hooks->resprints, 'name="kind"') === false, 'Native search forms retain only the shared account filter');
$navigationRequest = array('account_context' => 0, 'id' => 999);
try { $hooks->doActions(array(), $object, $action, null); throw new RuntimeException('Foreign native ID accepted'); } catch (LogicException $e) { $checks++; }
$navigationRequest = array('kind' => 'invalid');
checkNavigation(kreabankNavigationKind('bank') === 'bank', 'Unknown account-overview type normalizes to its bank default');
$navigationRequest = array();
$bankMenus = array(
	array('url' => '/compta/bank/list.php?leftmenu=bank', 'titre' => 'BankCash', 'level' => 0),
	array('url' => '/compta/bank/card.php?action=create', 'titre' => 'New', 'level' => 1),
	array('url' => '/compta/bank/list.php?leftmenu=bank', 'titre' => 'List', 'level' => 1, 'position' => 100),
	array('url' => '/custom/kreatax/bankreconciliation.php?mainmenu=bank&leftmenu=kreataxbankreport_bank', 'titre' => 'Fiscal report', 'level' => 2, 'perms' => 'unchanged', 'position' => 180),
	array('url' => '/custom/kreabank/reconcile.php?mainmenu=bank', 'titre' => 'Reconcile', 'level' => 1),
	array('url' => '/compta/bank/bankentries_list.php', 'titre' => 'Entries', 'level' => 1),
	array('url' => '/compta/bank/budget.php', 'titre' => 'Categories', 'level' => 1),
	array('url' => '/compta/bank/transfer.php', 'titre' => 'Transfer', 'level' => 1),
	array('url' => '/custom/kreabank/reconciliationlist.php', 'titre' => 'Separate list', 'level' => 1),
	array('url' => '/product/list.php', 'titre' => 'List', 'level' => 1),
);
$user->allowed = array('banque.lire', 'kreabank.reconciliation.read');
$menus = $bankMenus;
checkNavigation($hooks->menuLeftMenuItems(array('mainmenu' => 'bank'), $menus, $action, null) === 1, 'Workspace menu changes use the supported final-menu hook');
$expected = array_values(array_filter($bankMenus, static function ($item) { return !in_array($item['titre'], array('List', 'Reconcile', 'Entries', 'Separate list'), true) || $item['url'] === '/product/list.php'; }));
$fiscal = $expected[2];
$fiscal['level'] = 1;
array_splice($expected, 2, 1);
$expected[] = $fiscal;
checkNavigation($menus === $expected && $hooks->results === $expected, 'Marked links disappear and the fiscal report follows native siblings with URL and permissions intact');
checkNavigation(array_keys($menus) === range(0, count($menus) - 1), 'Filtered menu keeps contiguous keys required by the native renderer');
checkNavigation($hooks->menuLeftMenuItems(array('mainmenu' => 'bank'), $menus, $action, null) === 0 && $menus === $expected, 'Repeated menu hooks preserve the simplified menu');
$menus = $bankMenus;
checkNavigation($hooks->menuLeftMenuItems(array('mainmenu' => 'products'), $menus, $action, null) === 0 && $menus === $bankMenus, 'Unrelated menus are untouched');
foreach (array(array('banque.lire'), array('kreabank.reconciliation.read'), array()) as $rights) {
	$user->allowed = $rights;
	$menus = $bankMenus;
	checkNavigation($hooks->menuLeftMenuItems(array('mainmenu' => 'bank'), $menus, $action, null) === 0 && $menus === $bankMenus, 'Users without both workspace rights retain native navigation');
}
$user->allowed = array('banque.lire', 'kreabank.reconciliation.read');
$user->socid = 9;
$menus = $bankMenus;
checkNavigation($hooks->menuLeftMenuItems(array('mainmenu' => 'bank'), $menus, $action, null) === 0 && $menus === $bankMenus, 'External-user menus remain untouched');
$user->socid = 0;
print "OK: $checks navigation and account-scope checks\n";
