#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
// Exercise installed native formatting without a bootstrap, credentials or database.
define('DOL_DOCUMENT_ROOT', getenv('KREABANK_DOLIBARR_ROOT') ?: dirname(__DIR__, 3));
define('DOL_URL_ROOT', '');
require_once DOL_DOCUMENT_ROOT . '/core/lib/functions.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/class/translate.class.php';
require_once __DIR__ . '/../lib/kreabank.lib.php';
require_once __DIR__ . '/../class/actions_kreabank.class.php';

$conf = (object) array('global' => (object) array(
	'MAIN_MAX_DECIMALS_UNIT' => '8',
	'MAIN_MAX_DECIMALS_TOT' => '8',
	'MAIN_MAX_DECIMALS_SHOWN' => '8...',
));
$langs = new Translate(DOL_DOCUMENT_ROOT, $conf);
$checks = 0;
function checkAmountDisplay($actual, $expected, $message)
{
	global $checks;
	$checks++;
	if ($actual !== $expected) {
		throw new RuntimeException($message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
	}
}

foreach (array(
	array(',', 'None', array('0,00', '12,00', '12,30', '1234,57', '-1234,57', '0,30', '1000,00')),
	array('.', ',', array('0.00', '12.00', '12.30', '1,234.57', '-1,234.57', '0.30', '1,000.00')),
) as $locale) {
	$langs->tab_translate = array('SeparatorDecimal' => $locale[0], 'SeparatorThousand' => $locale[1]);
	$before = clone $conf->global;
	$amounts = array(0, 12, '12.30000000', '1234.56789012', '-1234.56789012', 0.1 + 0.2, '999.99999999');
	$original = $amounts;
	foreach ($amounts as $index => $amount) {
		checkAmountDisplay(kreabankPrice($amount), $locale[2][$index], 'Two-decimal localized display');
	}
	checkAmountDisplay($amounts, $original, 'Formatting preserves source values');
	checkAmountDisplay($conf->global == $before, true, 'Formatting preserves global precision settings');
	checkAmountDisplay(price2num('1234.56789012', 'MU'), '1234.56789012', 'Unit calculations retain eight decimals');
}

class AmountDisplayUser
{
	public $allowed = true;
	public function hasRight(...$path) { return $this->allowed; }
}
$user = new AmountDisplayUser();
$hook = new ActionsKreaBank(null);
$object = null;
$action = '';
$_GET = $_POST = array();
$_SERVER['PHP_SELF'] = '/compta/bank/bankentries_list.php';
foreach (array('2', '8') as $precision) {
	$conf->global->MAIN_MAX_DECIMALS_UNIT = $precision;
	$conf->global->MAIN_MAX_DECIMALS_TOT = $precision;
	$conf->global->MAIN_MAX_DECIMALS_SHOWN = '8...';
	$hook->doActions(array(), $object, $action, null);
	checkAmountDisplay(price('1234.56789012'), '1,234.57', 'Native bank list obeys display cap');
	checkAmountDisplay(price(12), '12.00', 'Native bank list pads ordinary amounts');
	checkAmountDisplay($conf->global->MAIN_MAX_DECIMALS_UNIT, $precision, 'Native hook preserves unit precision');
	checkAmountDisplay($conf->global->MAIN_MAX_DECIMALS_TOT, $precision, 'Native hook preserves total precision');
}
checkAmountDisplay(price2num('1234.56789012', 'MU'), '1234.56789012', 'Native hook preserves calculation values');
$conf->global->MAIN_MAX_DECIMALS_SHOWN = '8...';
$_SERVER['PHP_SELF'] = '/product/card.php';
$hook->doActions(array(), $object, $action, null);
checkAmountDisplay($conf->global->MAIN_MAX_DECIMALS_SHOWN, '8...', 'Non-banking pages keep their display precision');
$_SERVER['PHP_SELF'] = '/compta/bank/bankentries_list.php';
$user->allowed = false;
$hook->doActions(array(), $object, $action, null);
checkAmountDisplay($conf->global->MAIN_MAX_DECIMALS_SHOWN, '8...', 'Users outside the workspace keep native behavior');

print "OK: $checks amount display checks using native Dolibarr formatting\n";
