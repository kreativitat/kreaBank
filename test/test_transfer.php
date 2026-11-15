#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>; GPL-3.0-or-later
 * Render the installed native view with synthetic controls. No ERP bootstrap,
 * database, credentials or native action block; browser submissions are blocked.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
error_reporting(E_ALL);
set_error_handler(static function ($level, $message) { if (error_reporting() & $level) { throw new RuntimeException($message); } return false; });
define('DOL_DOCUMENT_ROOT', getenv('KREABANK_DOLIBARR_ROOT') ?: dirname(__DIR__, 3));
require_once DOL_DOCUMENT_ROOT . '/core/class/translate.class.php';
require_once __DIR__ . '/../lib/kreabank_transfer.lib.php';
$fixtureCase = $argv[2] ?? 'default';
$locale = $argv[3] ?? 'pt_PT';
class TransferFixtureLangs
{
	public $labels;
	private $native;
	public function __construct($locale) {
		$this->labels = array();
		foreach (file(__DIR__ . '/../langs/' . $locale . '/kreabank.lang', FILE_IGNORE_NEW_LINES) as $line) {
			if (preg_match('/^([A-Za-z0-9_]+)=(.*)$/', $line, $match)) { $this->labels[$match[1]] = $match[2]; }
		}
		$this->labels = array_merge($this->labels, array('TransferFrom' => 'From', 'TransferTo' => 'To', 'Type' => 'Type', 'Date' => 'Date', 'Description' => 'Description', 'Amount' => 'Amount', 'AmountToOthercurrency' => 'Amount in destination currency', 'ChequeNumber' => 'Cheque number', 'MenuBankInternalTransfer' => 'Internal transfer', 'TransferDesc' => 'Native transfer help', 'Save' => 'Save', 'Add' => 'Add'));
		$this->native = new Translate(DOL_DOCUMENT_ROOT, (object) array());
		$this->native->defaultlang = $locale;
		$this->native->tab_translate = $this->labels;
	}
	public function load($file) {}
	public function trans(...$args) { return $this->native->trans(...$args); }
	public function transnoentities(...$args) { return $this->native->transnoentities(...$args); }
}
function dol_escape_htmltag($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function dol_buildpath($path, $absolute = 0) { return '/custom' . $path; }
function dolBuildUrl($path) { return $path; }
function getNonce() { return 'synthetic-nonce'; }
function newToken() { return 'synthetic-token'; }
function getDolGlobalString($key) { return $key === 'BANK_TRANSFER_ASK_CHQ_NUMBER_OF_SRC_ACCOUNT' && $GLOBALS['fixtureCase'] === 'cheque'; }
function isModEnabled($key) { return true; }
function GETPOST($key, $type = '') { return $GLOBALS['fixtureRequest'][$key] ?? ''; }
function GETPOSTINT($key) { return (int) GETPOST($key); }
function img_picto(...$args) { return '<span class="paddingright" aria-hidden="true">↗</span>'; }
function dol_getIdFromCode(...$args) { return 3; }
function load_fiche_titre($title, ...$args) { return '<h1>' . dol_escape_htmltag($title) . '</h1>'; }
function llxHeader(...$args) {
	print '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Synthetic internal transfer</title><script src="/includes/jquery/js/jquery.min.js"></script><link rel="stylesheet" href="/includes/jquery/plugins/select2/dist/css/select2.min.css"><script src="/includes/jquery/plugins/select2/dist/js/select2.full.min.js"></script><style>body{font:14px Arial;color:#344b5d;margin:0}#id-right{padding:24px}h1{font-size:23px}.hideobject,.hidejs{display:none}table.noborder>tbody>tr>td{height:32px}input,select{box-sizing:border-box;border:1px solid #cbd6df;border-radius:4px;padding:8px}.button{border:1px solid #cbd6df;border-radius:4px;padding:10px 16px;background:#355d4a;color:white;cursor:pointer}.kb-transfer-form .inline-block{display:block}.nowraponall{white-space:nowrap}#fixture-result{display:block;margin-top:24px}</style></head><body><main id="id-right">';
}
function llxFooter() {
	print '<output id="fixture-result">Synthetic fixture · submissions blocked</output><script>var f=document.forms.add;var before=JSON.stringify(Array.from(new FormData(f)));var nodes=Array.from(f.elements);f.addEventListener("submit",function(e){e.preventDefault();document.getElementById("fixture-result").textContent="Synthetic submission intercepted";});window.verifyTransferFixture=function(){if(before!==JSON.stringify(Array.from(new FormData(f)))||nodes.some(function(n){return !f.contains(n);}))throw new Error("Transfer enhancement changed native form data or controls");if(document.documentElement.scrollWidth>innerWidth)throw new Error("Transfer page overflows viewport");f.querySelectorAll(".kb-transfer-field").forEach(function(cell){var r=cell.getBoundingClientRect();if(!r.height)return;cell.querySelectorAll("label,input:not([type=hidden]):not(.select2-hidden-accessible),select:not(.select2-hidden-accessible),.select2-container").forEach(function(control){var c=control.getBoundingClientRect();if(c.height&&(c.top<r.top-1||c.bottom>r.bottom+1))throw new Error("Transfer control overflows native cell height");});});var parent=f.parentElement;var style=getComputedStyle(parent);var available=parent.clientWidth-parseFloat(style.paddingLeft)-parseFloat(style.paddingRight);if(Math.abs(f.getBoundingClientRect().width-available)>2)throw new Error("Transfer card does not use available width");var combo=f.querySelector("[role=combobox]");if(!combo||!combo.getAttribute("aria-labelledby").includes("kb-transfer-label-1_account_from "))throw new Error("Native account label or selected value missing");document.getElementById("fixture-result").textContent="PASS: original controls, exact POST values, native selector labels, field bounds, full width and viewport preserved; submissions blocked";};</script>';
	print kreabankTransferAssets() . '<script>jQuery(verifyTransferFixture);</script></main></body></html>';
}
class Form
{
	public function __construct($db) {}
	public function select_comptes($selected, $name, ...$args) {
		print '<select class="selectbankaccount" name="' . $name . '" id="select' . $name . '"' . ($selected ? ' view="view"' : '') . '>';
		foreach (array(-1 => '', 11 => 'Synthetic current account', 12 => 'Synthetic reserve account', 13 => 'Synthetic USD account') as $id => $label) {
			print '<option value="' . $id . '" data-currency-code="' . ($id === 13 ? 'USD' : ($id > 0 ? 'EUR' : '')) . '"' . ((int) $selected === $id ? ' selected' : '') . '>' . $label . '</option>';
		}
		print '</select><script>jQuery(function(){jQuery("#select' . $name . '").select2({width:"resolve"});});</script>';
	}
	public function select_types_paiements($selected, $name, ...$args) { print '<select name="' . $name . '"><option value="3">Bank transfer</option></select>'; }
	public function selectDate($value, $prefix, ...$args) { return '<div class="inline-block"><input name="' . $prefix . '" id="' . $prefix . '" class="hasDatepicker" value="15/11/2026"><input type="hidden" name="' . $prefix . 'day" value="15"><input type="hidden" name="' . $prefix . 'month" value="11"><input type="hidden" name="' . $prefix . 'year" value="2026"></div>'; }
}
class TransferFixtureDb { public function close() {} }
function transferFixtureRender($case, $language) {
	global $db, $langs, $error, $MAXLINESFORTRANSFERT, $dateo, $fixtureRequest, $fixtureCase;
	$fixtureCase = $case;
	$db = new TransferFixtureDb();
	$langs = new TransferFixtureLangs($language);
	$error = $case === 'error' ? 1 : 0;
	$MAXLINESFORTRANSFERT = 20;
	$dateo = array();
	$fixtureRequest = $error ? array('1_account_from' => 11, '1_account_to' => 12, '1_label' => 'Reviewed <transfer> & "reference"', '1_amount' => '123.45000001', '4_account_from' => 12, '4_account_to' => 13, '4_amount' => '99.12000001', '4_amountto' => '103.77000009', '4_label' => 'Retry row 4') : array();
	$_SERVER['PHP_SELF'] = '/synthetic-transfer-do-not-submit';
	$source = file_get_contents(DOL_DOCUMENT_ROOT . '/compta/bank/transfer.php');
	$start = strpos($source, '$form = new Form($db);');
	if ($start === false) { throw new RuntimeException('Installed native transfer view not found'); }
	ob_start();
	eval(substr($source, $start));
	return ob_get_clean();
}
if (($argv[1] ?? '') === '--html') { echo transferFixtureRender($fixtureCase, $locale); exit; }
$checks = 0;
foreach (array('en_US', 'pt_PT') as $language) {
	foreach (array('default', 'error', 'cheque') as $case) {
		$html = transferFixtureRender($case, $language);
		$dom = new DOMDocument();
		@$dom->loadHTML($html);
		$xpath = new DOMXPath($dom);
		$config = json_decode($xpath->query("//script[@id='kb-transfer-labels']")->item(0)->textContent, true, 512, JSON_THROW_ON_ERROR);
		foreach (array('add' => 'KreaBankTransferAdd', 'transfer' => 'KreaBankTransferNumber', 'help' => 'KreaBankTransferHelp', 'from' => 'KreaBankTransferFrom', 'to' => 'KreaBankTransferTo') as $name => $key) {
			$checks++;
			if ($config[$name] !== $langs->labels[$key]) { throw new RuntimeException($language . ': plain-text label or numbering placeholder changed: ' . $name); }
		}
		foreach (array("//form[@name='add']" => 1, "//input[@name='token' and @value='synthetic-token']" => 1, "//tr[contains(@class,'numvir')]" => 19, "//select[contains(@name,'_account_from')]" => 19, "//input[contains(@name,'_num_chq')]" => ($case === 'cheque' ? 19 : 0), "//script[contains(@src,'transfer.js')]" => 1) as $query => $expected) {
			$checks++;
			if ($xpath->query($query)->length !== $expected) { throw new RuntimeException($case . ': ' . $query); }
		}
		if ($case === 'error') {
			$checks++;
			if ($xpath->query("//input[@name='1_amount']")->item(0)->getAttribute('value') !== '123.45000001'
				|| $xpath->query("//input[@name='1_label']")->item(0)->getAttribute('value') !== $fixtureRequest['1_label']) { throw new RuntimeException('Retry precision or escaped description changed'); }
		}
	}
}
echo 'OK: ' . $checks . " native transfer rendering checks (synthetic, no database)\n";
