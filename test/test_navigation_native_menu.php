#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
// Exercise the installed Eldy sorting and renderer, without an ERP bootstrap or database.
define('DOL_DOCUMENT_ROOT', getenv('KREABANK_DOLIBARR_ROOT') ?: dirname(__DIR__, 3));
define('DOL_URL_ROOT', '');
require_once DOL_DOCUMENT_ROOT . '/core/lib/functions.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/class/menu.class.php';
require_once __DIR__ . '/../class/actions_kreabank.class.php';
set_error_handler(static function ($severity, $message, $file, $line) {
	if (error_reporting() & $severity) { throw new ErrorException($message, 0, $severity, $file, $line); }
	return false;
});
$conf = (object) array('global' => new stdClass(), 'file' => (object) array('dol_document_root' => array(DOL_DOCUMENT_ROOT), 'dol_url_root' => array('')));
class NativeMenuTestUser
{
	public $socid = 0;
	public function hasRight(...$rights) { return true; }
}
$user = new NativeMenuTestUser();
class NativeMenuTestHooks
{
	public $resArray = array();
	private $enabled;
	public function __construct($enabled) { $this->enabled = $enabled; }
	public function executeHooks($name, $parameters, &$items)
	{
		if (!$this->enabled) { return 0; }
		$hook = new ActionsKreaBank(null);
		$action = ''; $manager = null;
		$result = $hook->menuLeftMenuItems($parameters, $items, $action, $manager);
		$this->resArray = $hook->results;
		return $result;
	}
}
$fixture = new Menu();
$fixture->add('/compta/bank/list.php', 'Banks', 0, 1, '', 'bank', 'bank');
$fixture->add('/compta/bank/card.php?action=create', 'New account', 1);
// This is the pre-existing physical order after custom menu registration.
$fixture->add('/custom/kreatax/bankreconciliation.php?mainmenu=bank', 'Fiscal report', 1, 1, '', 'bank', 'report', 180);
$fixture->add('/custom/kreabank/reconciliationlist.php', 'Reconciliation list', 1, 1, '', 'bank', 'worklist', 1945);
$fixture->add('/compta/bank/budget.php', 'Categories', 1);
$fixture->add('/compta/bank/transfer.php', 'Transfer', 1);
$fixture->add('/compta/paiement/cheque/list.php', 'Cheque deposits', 0, 1, '', 'bank', 'deposit', 100);

/** Use the native final-menu stage, including its preserved-key sort and block endings. */
function renderNativeMenuFixture($items, $enabled)
{
	global $conf, $user;
	$source = file_get_contents(DOL_DOCUMENT_ROOT . '/core/menus/standard/eldy.lib.php');
	$start = strpos($source, "\t// Build final \$menu_array");
	$end = strpos($source, "\n\treturn count(\$menu_array);", $start === false ? 0 : $start);
	if ($start === false || $end === false) { throw new RuntimeException('Unsupported installed native menu renderer'); }
	$menu_array_before = $items; $menu_array_after = array();
	$newmenu = new Menu(); $mainmenu = 'bank'; $noout = 0; $substitarray = array();
	$hookmanager = new NativeMenuTestHooks($enabled);
	ob_start();
	eval(substr($source, $start, $end - $start));
	return ob_get_clean();
}
function nativeMenuXPath($html)
{
	$dom = new DOMDocument();
	@$dom->loadHTML('<meta charset="utf-8">' . $html);
	return new DOMXPath($dom);
}
$before = nativeMenuXPath(renderNativeMenuFixture($fixture->liste, false));
$afterHtml = renderNativeMenuFixture($fixture->liste, true);
$after = nativeMenuXPath($afterHtml);
$bankBlock = '//div[contains(concat(" ",normalize-space(@class)," ")," blockvmenu ")][.//a[normalize-space(.)="Banks"]]';
if ($before->query($bankBlock . '//a[normalize-space(.)="Fiscal report"]')->length !== 0) {
	throw new RuntimeException('Fixture must reproduce the old premature native menu block ending');
}
if ($after->query($bankBlock . '//a[normalize-space(.)="Fiscal report"]')->length !== 1
	|| $after->query($bankBlock . '//a[normalize-space(.)="Transfer"]')->length !== 1
	|| $after->query($bankBlock . '//a[normalize-space(.)="Cheque deposits"]')->length !== 0) {
	throw new RuntimeException('Fiscal report must remain in the native bank block without absorbing cheque deposits');
}
if ($after->query('//a[normalize-space(.)="Reconciliation list"]')->length !== 0) {
	throw new RuntimeException('The existing registered worklist link must be hidden');
}
$detached = $fixture->liste;
$report = $detached[2];
array_splice($detached, 2, 1);
$report['level'] = 0;
$detached[] = $report;
$relocated = nativeMenuXPath(renderNativeMenuFixture($detached, true));
if ($relocated->query($bankBlock . '//a[normalize-space(.)="Fiscal report"]')->length !== 1) {
	throw new RuntimeException('A detached fiscal heading must rejoin the owned bank block');
}
if (($argv[1] ?? '') === '--html') { print $afterHtml; exit; }
print "OK: installed native menu divider regression\n";
