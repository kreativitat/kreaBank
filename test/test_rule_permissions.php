#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Installed native permission registration, synthetic SQL storage only.
 * No ERP bootstrap, installation credentials or database connection.
 */
define('DOL_DOCUMENT_ROOT', getenv('KREABANK_DOLIBARR_ROOT') ?: dirname(__DIR__, 3));
define('MAIN_DB_PREFIX', 'rule_permission_test_');
require_once DOL_DOCUMENT_ROOT . '/core/lib/functions.lib.php';
require_once __DIR__ . '/../core/modules/modKreaBank.class.php';

class RulePermissionResult
{
	public $rows;
	public function __construct($rows) { $this->rows = $rows; }
}

/** Accept only the three native insert_permissions() SQL statements. */
class RulePermissionDb
{
	public $rights = array();
	public $queries = array();
	public $inserts = 0;
	public function decrypt($field) { return $field; }
	public function escape($value) { return addslashes((string) $value); }
	public function query($sql, $ignoreError = 0)
	{
		$this->queries[] = $sql;
		if (preg_match("/^SELECT value as value FROM rule_permission_test_const WHERE name = 'MAIN_MODULE_KREABANK' AND entity IN \\(0,([0-9]+)\\)$/D", $sql, $match)) {
			return new RulePermissionResult(array((object) array('value'=>in_array((int) $match[1], array(7, 9), true) ? '1' : '0')));
		}
		if (preg_match('/^SELECT count\(\*\) as nb FROM rule_permission_test_rights_def WHERE entity = ([0-9]+) AND id = ([0-9]+)$/D', $sql, $match)) {
			return new RulePermissionResult(array((object) array('nb'=>isset($this->rights[$match[1] . ':' . $match[2]]) ? 1 : 0)));
		}
		if (preg_match('/^INSERT INTO rule_permission_test_rights_def \(([^)]+)\) VALUES \((.+)\)$/D', $sql, $match)) {
			$columns = array_map('trim', explode(',', $match[1]));
			$expected = array('id','entity','libelle','module','module_origin','module_position','family','family_position','type','bydefault','perms','subperms','enabled');
			if ($columns !== $expected) { throw new RuntimeException('Unexpected native permission insert columns'); }
			$values = array_map('trim', str_getcsv($match[2], ',', "'", '\\'));
			if (count($values) !== count($columns)) { throw new RuntimeException('Unexpected native permission insert values'); }
			$row = array_combine($columns, $values); $key = $row['entity'] . ':' . $row['id'];
			if (isset($this->rights[$key])) { throw new RuntimeException('Native registration attempted a duplicate permission insert'); }
			$this->rights[$key] = $row; $this->inserts++;
			return new RulePermissionResult(array());
		}
		// This also rejects User::addrights(), permission grants, updates/deletion,
		// administrator lookups and every unsupported SQL path.
		throw new RuntimeException('Unexpected permission registration query: ' . $sql);
	}
	public function fetch_object($result) { return array_shift($result->rows); }
	public function free($result) {}
}

class RulePermissionActor
{
	public $admin = 1;
	public $grantCalls = 0;
	public $reloadCalls = 0;
	public function addrights(...$args) { $this->grantCalls++; throw new RuntimeException('Registration must not grant user permissions'); }
	public function clearrights() { $this->reloadCalls++; throw new RuntimeException('Registration must not reset user permissions'); }
	public function loadRights(...$args) { $this->reloadCalls++; throw new RuntimeException('Registration must not reload user permissions'); }
}

$checks = 0;
function checkRulePermission($condition, $label)
{
	global $checks;
	if (!$condition) { throw new RuntimeException($label); }
	$checks++;
}

$conf = (object) array('entity'=>7, 'global'=>new stdClass());
$user = new RulePermissionActor();
$db = new RulePermissionDb();
$module = new modKreaBank($db);
$historical = array(); $ruleRights = array();
foreach ($module->rights as $right) {
	if (($right[4] ?? '') === 'reconciliation') { $historical[(int) $right[0]] = $right[5]; }
	if (($right[4] ?? '') === 'rules' && ($right[5] ?? '') === 'write') { $ruleRights[] = $right; }
}
checkRulePermission($historical === array(156561=>'read', 156562=>'write', 156563=>'undo'), 'Historical KreaBank reconciliation permission identities remain unchanged');
checkRulePermission(count($ruleRights) === 1 && (int) $ruleRights[0][0] === 15656004,
	'The new rules permission uses its module-qualified identity');
checkRulePermission((int) ($ruleRights[0][3] ?? 0) === 0, 'The rules permission is not granted by default');

$legacyTax = array('id'=>'156564', 'entity'=>'7', 'module'=>'kreataxmatch', 'perms'=>'match', 'subperms'=>'read', 'bydefault'=>'1');
$db->rights['7:156564'] = $legacyTax;
foreach ($historical as $id=>$permission) {
	$db->rights['7:' . $id] = array('id'=>(string) $id, 'entity'=>'7', 'module'=>'kreabank', 'perms'=>'reconciliation', 'subperms'=>$permission, 'bydefault'=>'0');
}
$preexisting = $db->rights;
// Exercise the actual inherited implementation, filtered exactly like the
// targeted deployment refresh. Do not initialize all module permissions.
$module->rights = $ruleRights;
checkRulePermission($module->insert_permissions(0, 7, 1) === 0, 'Native permission registration succeeds without administrator grants');
checkRulePermission($db->inserts === 1 && count($db->rights) === count($preexisting) + 1, 'Only the new rules definition is inserted');
checkRulePermission($db->rights['7:156564'] === $legacyTax, 'The pre-existing KreaTaxMatch permission retains its owner and meaning');
foreach ($preexisting as $key=>$row) { checkRulePermission($db->rights[$key] === $row, 'Existing permission ' . $key . ' is unchanged'); }
$new = $db->rights['7:15656004'];
checkRulePermission($new['module'] === 'kreabank' && $new['module_origin'] === '' && $new['perms'] === 'rules'
	&& $new['subperms'] === 'write' && (int) $new['bydefault'] === 0 && $new['enabled'] === '1',
	'Native storage records the precise opt-in KreaBank rules authority');
$afterRegistration = $db->rights;
checkRulePermission($module->insert_permissions(0, 7, 1) === 0 && $db->rights === $afterRegistration && $db->inserts === 1,
	'Repeating the targeted native registration is idempotent');
checkRulePermission($module->insert_permissions(0, 9, 1) === 0 && isset($db->rights['9:15656004'])
	&& $db->rights['7:15656004'] === $new && $db->rights['7:156564'] === $legacyTax && $db->inserts === 2,
	'Forced company registration creates only its separate definition and preserves the current company');
$beforeDisabled = $db->rights;
checkRulePermission($module->insert_permissions(0, 10, 1) === 0 && $db->rights === $beforeDisabled,
	'An inactive company installation receives no permission definition');
checkRulePermission($user->grantCalls === 0 && $user->reloadCalls === 0,
	'Targeted registration neither grants permissions nor resets the current administrator');
echo 'OK: ' . $checks . " native rule permission registration checks\n";
