#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 * ONLY the disposable localhost:33317/kreabank_auto_test fixture.
 */
ob_start();
require __DIR__ . '/test_bank_integration_database.php';
require_once DOL_DOCUMENT_ROOT . '/includes/restler/framework/Luracast/Restler/AutoLoader.php';
spl_autoload_register(Luracast\Restler\AutoLoader::instance());
class DolibarrApi { protected $db; }
class DolibarrApiAccess { public static $user; }
require_once __DIR__ . '/../class/api_kreabank.class.php';

function rejectApi($code, $call, $message)
{
	global $checks;
	try { $call(); } catch (Luracast\Restler\RestException $error) {
		checkAuto($error->getCode() === $code, $message);
		return;
	}
	throw new RuntimeException('Expected API rejection: ' . $message);
}

$checks = 0;
DolibarrApiAccess::$user = $user;
$api = new KreaBankApi();
$conf->entity = 1;
stageAuto(701, '12.34567890', 'API', 101);
stageAuto(702, '12.34567890', 'API-OTHER', 102);
stageAuto(703, '12.34567890', 'API-FOREIGN', 202, 2);
stageAuto(704, '12.34567890', 'API-SECOND', 101);
stageAuto(705, '12.34567890', 'API-CURRENCY', 101);
$db->query("UPDATE kbauto_kreabank_statement SET currency='USD' WHERE ref='ST-705'");
$db->query("UPDATE kbauto_kreabank_statement_line SET currency='USD' WHERE rowid=705");
$before = array();
foreach (array('bank', 'bank_url', 'kreabank_statement_line', 'kreabank_recon_audit') as $table) {
	$before[$table] = $db->query('SELECT * FROM kbauto_' . $table . ' ORDER BY rowid')->fetch_all();
}
$accounts = $api->accounts('all', 0, 1, 0);
checkAuto(count($accounts['items']) === 1 && $accounts['has_more'] === true && $accounts['entity_id'] === 1, 'Accounts are bounded with explicit current-company provenance');
$next = $api->accounts('all', 0, 1, 1);
checkAuto($accounts['items'][0]['rowid'] !== $next['items'][0]['rowid'], 'Account pagination is applied in SQL with a stable tie break');
checkAuto($api->accounts('all', 0, 1, 100)['items'] === array(), 'Out-of-range page stays empty instead of wrapping');
$summary = $api->statement(101, 'ST-701');
checkAuto($summary['statement']['currency'] === 'EUR' && (string) $summary['statement']['amount_total'] === '12.34567890', 'Statement totals retain decimal evidence and currency');
checkAuto($api->statement(101, 'ST-705')['statement']['currency'] === 'USD', 'Imported currency is never relabelled as native account currency');
$lines = $api->statementLines(101, 'ST-701', 1);
checkAuto($lines['items'][0]['amount'] === '12.34567890' && $lines['items'][0]['source_storage'] === 'staging' && $lines['items'][0]['native_id'] === null, 'Pending evidence remains staged and keeps its original amount');
checkAuto($lines['items'][0]['state'] === 'KreaBankPending', 'Pending is never reported as reconciled');
$issues = $api->statementLines(101, 'ST-502');
checkAuto($issues['items'][0]['state'] === 'KreaBankLinkNeedsReview', 'Missing native links remain explicit review findings');
$legacy = $api->statementLines(101, 'LEGACY-INTEGRATION');
checkAuto(count($legacy['items']) === 1 && $legacy['items'][0]['source_storage'] === 'legacy_native', 'Legacy native IDs have an explicit source namespace');
$statements = $api->statements(101, 1);
checkAuto(count($statements['items']) === 1 && $statements['has_more'], 'Statement listing is bounded with continuation metadata');
foreach ($api->statements()['items'] as $statement) {
	checkAuto((int) $statement['bank_account_id'] !== 202, 'Company-wide statement listing excludes foreign accounts');
}
$audit = $api->audit(500);
foreach ($audit['items'] as $row) {
	checkAuto(array_keys($row) === array('rowid', 'audit_type', 'native_bank_line_id', 'native_reconciliation_id', 'datec'), 'Audit exposes allowlisted metadata only');
}
rejectApi(404, function () use ($api) { $api->accounts('all', 202); }, 'Shared or foreign accounts are denied');
rejectApi(404, function () use ($api) { $api->statement(202, 'ST-703'); }, 'Foreign statement detail is denied');
rejectApi(404, function () use ($api) { $api->statementLines(102, 'ST-701'); }, 'Statement reference cannot cross an owned account boundary');
rejectApi(404, function () use ($api) { $api->statement(101, "ST-701' OR 1=1 --"); }, 'Statement reference remains an escaped literal');
foreach (array(0, 501, true, '1e2', 1.5) as $limit) {
	rejectApi(400, function () use ($api, $limit) { $api->audit($limit); }, 'Invalid limit is rejected');
}
rejectApi(400, function () use ($api) { $api->statements(0, 1, -1); }, 'Negative page is rejected');
rejectApi(400, function () use ($api) { $api->statement(0, 'ST'); }, 'Statement detail requires an account');
rejectApi(400, function () use ($api) { $api->statement(101, "bad\nref"); }, 'Control characters are rejected');
rejectApi(400, function () use ($api) { $api->accounts('unknown'); }, 'Unknown account kind is rejected');
$user->allowed = false;
foreach (array('accounts', 'statements', 'audit') as $method) {
	rejectApi(403, function () use ($api, $method) { $api->$method(); }, 'Read permission is enforced');
}
$user->allowed = true;
class ApiReadUser extends User
{
	public $denied = '';
	public function hasRight($a, $b, $c = '') { return $a !== $this->denied; }
}
$readUser = new ApiReadUser();
DolibarrApiAccess::$user = $readUser;
foreach (array('banque', 'kreabank') as $permission) {
	$readUser->denied = $permission;
	rejectApi(403, function () use ($api) { $api->statement(101, 'ST-701'); }, 'Each native/module permission is independently required');
	rejectApi(403, function () use ($api) { $api->statementLines(101, 'ST-701'); }, 'Line detail enforces both permissions');
}
DolibarrApiAccess::$user = $user;
$user->socid = 8;
rejectApi(403, function () use ($api) { $api->accounts(); }, 'External users cannot read banking evidence');
$user->socid = 0;
$conf->entity = 0;
rejectApi(403, function () use ($api) { $api->audit(); }, 'Missing company fails closed');
$conf->entity = 2;
checkAuto($api->accounts('all')['items'][0]['rowid'] == 202 && $api->statement(202, 'ST-703')['entity_id'] === 2, 'API uses each authenticated request company');
$conf->entity = 1;
$db->fail = 'SELECT h.*';
rejectApi(503, function () use ($api) { $api->statements(); }, 'Database failure is an explicit lookup failure');
$db->fail = '';
foreach ($before as $table => $rows) {
	checkAuto($db->query('SELECT * FROM kbauto_' . $table . ' ORDER BY rowid')->fetch_all() === $rows, 'GETs never modify evidence, movements, links or audit');
}

// Exercise the installed Restler parser and routing, without application bootstrap.
Luracast\Restler\Scope::get('Restler')->addAPIClass('KreaBankApi');
foreach (array('accounts' => 'accounts', 'statements' => 'statements', 'statement' => 'statement', 'statement/lines' => 'statementLines', 'audit' => 'audit') as $route => $method) {
	$info = Luracast\Restler\Routes::find('kreabankapi/' . $route, 'GET', 1, array('bank_account_id' => 101, 'reference' => 'ST-701'));
	checkAuto($info->methodName === $method && $info->accessLevel === 2, 'Restler publishes authenticated GET route: ' . $route);
	rejectApi(405, function () use ($route) { Luracast\Restler\Routes::find('kreabankapi/' . $route, 'POST'); }, 'Write method is unavailable');
}
print "OK: $checks KreaBank API database and Restler contract checks\n";
ob_end_flush();
