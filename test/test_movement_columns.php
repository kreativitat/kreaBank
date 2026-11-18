#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
// Installed native column selection, without bootstrap, credentials or database.
define('DOL_DOCUMENT_ROOT', getenv('KREABANK_DOLIBARR_ROOT') ?: dirname(__DIR__, 3));
define('DOL_URL_ROOT', '');
require_once DOL_DOCUMENT_ROOT . '/core/lib/functions.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/class/translate.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/class/html.form.class.php';
require_once __DIR__ . '/../class/actions_kreabank.class.php';

class MovementColumnsUser
{
	public $allowed = true;
	public $id = 123;
	public $conf;
	public function hasRight(...$path) { return implode('.', $path) === 'banque.lire' || $this->allowed; }
}
$conf = (object) array('global' => new stdClass(), 'currency' => 'EUR', 'browser' => (object) array('layout' => 'classic'));
$user = new MovementColumnsUser();
$user->conf = (object) array('MAIN_SELECTEDFIELDS_banktransactionlist-45' => 'b.rowid,ba.ref,b.debit');
$langs = new Translate(DOL_DOCUMENT_ROOT, $conf);
$langs->setDefaultLang('en_US');
$langs->loadLangs(array('main', 'banks'));
$_SESSION = array('newtoken' => 'synthetic-movement-columns-token');
$_SERVER['REQUEST_METHOD'] = 'GET';
$hook = new ActionsKreaBank(null);
$object = (object) array('id' => 45);
$action = '';
$checks = 0;
function checkMovementColumns($ok, $message)
{
	global $checks;
	$checks++;
	if (!$ok) { throw new RuntimeException($message); }
}
$original = array(
	'b.rowid' => array('label' => 'Ref', 'checked' => 1),
	'ba.ref' => array('label' => 'BankAccount', 'checked' => 1),
	'b.debit' => array('label' => 'Debit', 'checked' => 1),
);
foreach (array(array('id' => '45'), array('account_context' => '45'), array('id' => '45', 'search_account' => '45')) as $request) {
	foreach (array(0, 1) as $textBrowser) {
		$_GET = $request;
		$_POST = array();
		$_SERVER['PHP_SELF'] = '/compta/bank/bankentries_list.php';
		$conf->global->MAIN_OPTIMIZEFORTEXTBROWSER = $textBrowser;
		$fields = $original;
		$savedPreferences = clone $user->conf;
		$params = array('arrayfields' => &$fields);
		checkMovementColumns($hook->completeArrayFields($params, $object, $action, null) === 0 && !isset($fields['ba.ref']), 'Scoped list removes the account field through the native hook');
		$html = Form::multiSelectArrayWithCheckbox('selectedfields', $fields, 'banktransactionlist-45');
		checkMovementColumns(!isset($fields['ba.ref']) && strpos($html, 'checkboxba.ref') === false, 'Saved column choices and text-browser mode cannot restore the account column or picker option');
		checkMovementColumns(isset($fields['b.rowid'], $fields['b.debit']) && $user->conf == $savedPreferences, 'Other native columns and saved user preferences remain intact');
	}
}
foreach (array(
	array('/compta/bank/bankentries_list.php', array(), true),
	array('/compta/bank/bankentries_list.php', array('search_account' => '45'), true),
	array('/compta/bank/bankentries_list.php', array('account' => '45'), true),
	array('/compta/bank/bankentries_list.php', array('id' => '0', 'search_account' => '45', 'contextpage' => 'banktransactionlist', 'sortfield' => 'ba.ref', 'page' => '1'), true),
	array('/compta/bank/bankentries_list.php', array('id' => '45'), false),
	array('/compta/bank/card.php', array('id' => '45'), true),
) as $case) {
	list($_SERVER['PHP_SELF'], $_GET, $user->allowed) = $case;
	$fields = $original;
	$params = array('arrayfields' => &$fields);
	$hook->completeArrayFields($params, $object, $action, null);
	checkMovementColumns($fields === $original, 'All-account lists, native-only readers and unrelated pages retain their original columns');
}
print "OK: $checks native movement-column checks\n";
