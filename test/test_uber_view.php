#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Synthetic financial-review rendering only; no ERP bootstrap or database.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../class/KreaBankUberSettings.class.php';
require_once __DIR__ . '/../lib/kreabank_uber_view.lib.php';
function dol_buildpath($path, $type = 0) { return $path; }
function dol_print_date($time, $format) { return gmdate('d/m/Y', $time); }
function kreabankPrice($amount) { return number_format((float) $amount, 2, ($GLOBALS['uberLocale'] ?? '') === 'pt_PT' ? ',' : '.', ' '); }
function dol_escape_htmltag($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function uberViewLanguage($locale)
{
	$messages = array(); $root = getenv('KREABANK_DOLIBARR_ROOT') ?: dirname(__DIR__, 3);
	foreach (array($root . '/langs/' . $locale . '/main.lang', $root . '/langs/' . $locale . '/banks.lang', __DIR__ . '/../langs/' . $locale . '/kreabank.lang', __DIR__ . '/../langs/' . $locale . '/worklist.lang') as $file) {
		foreach (file($file, FILE_IGNORE_NEW_LINES) as $line) {
			if (strpos($line, '=') !== false && substr(trim($line), 0, 1) !== '#') { list($key, $value) = explode('=', $line, 2); $messages[trim($key)] = $value; }
		}
	}
	return new class($messages) {
		private $messages;
		public function __construct($messages) { $this->messages = $messages; }
		public function trans($key) { return $this->messages[$key] ?? $key; }
	};
}
function uberViewFixture($reuse = false)
{
	return array('nonce'=>str_repeat('a', 48), 'source_key'=>'staged:11',
		'source'=>array('operation_date'=>'2026-11-10', 'amount'=>'890.00000000', 'currency'=>'EUR', 'description'=>'Synthetic bank credit <script>bad()</script>'),
		'payout'=>array('settlement'=>array('rowid'=>51, 'payout_ref'=>'SYNTHETIC-PAYOUT-51', 'gross'=>120000, 'fees'=>-30000, 'adjustments'=>-1000, 'net'=>89000),
			'store'=>array('label'=>'Synthetic restaurant <img src=x onerror=bad()>'), 'batch'=>array('date_start'=>'2026-11-02', 'date_end'=>'2026-11-08'),
			'clearing_account'=>array('ref'=>'UBER-CLEARING', 'label'=>'Verified clearing account'), 'receiving_account'=>array('ref'=>'BANK-EUR', 'label'=>'Receiving bank account')),
		'transfer'=>array('state'=>$reuse ? 'reuse' : 'create', 'clearing_line_id'=>$reuse ? 31 : 0, 'bank_line_id'=>$reuse ? 32 : 0));
}
function uberViewDocument($html)
{
	$dom = new DOMDocument(); $previous = libxml_use_internal_errors(true); $dom->loadHTML('<?xml encoding="UTF-8">' . $html); libxml_clear_errors(); libxml_use_internal_errors($previous);
	return new DOMXPath($dom);
}
$checks = 0;
$check = static function ($ok, $label) use (&$checks) { if (!$ok) { throw new RuntimeException($label); } $checks++; };
foreach (array('en_US', 'pt_PT') as $locale) {
	$GLOBALS['uberLocale'] = $locale; $langs = uberViewLanguage($locale);
	foreach (array(false, true) as $reuse) {
		$html = kreabankUberReviewHtml(uberViewFixture($reuse), '/custom/kreabank/reconciliationlist.php?search_account=21', 'synthetic-csrf', $langs);
		$x = uberViewDocument($html);
		$check($x->query('//form[@method="post"]')->length === 1 && $x->query('//form//form')->length === 0, 'Isolated native review form');
		$check($x->query('//input[@name="token" and @value="synthetic-csrf"]')->length === 1, 'CSRF token retained');
		$check($x->query('//input[@name="confirmed" and @required]')->length === 1 && $x->query('//input[@name="confirmed" and @checked]')->length === 0, 'Identity confirmation requires an explicit choice');
		$check($x->query('//input[@name="action" and @value="confirm_uber"]')->length === 1, 'Financial action is POST-only');
		$check($x->query('//input[@name="review" and @value="' . str_repeat('a', 48) . '"]')->length === 1, 'Opaque signed review identity');
		$check($x->query('//script|//img')->length === 0 && strpos($html, '&lt;script&gt;') !== false, 'Bank and provider prose is escaped');
		$check(strpos(strip_tags($html), 'KreaBankUber') === false, 'Review translations resolve');
		$check($x->query('//select|//input[@name="amount"]|//input[@name="account"]')->length === 0, 'Fixed accounts and exact amounts are not editable');
		$check($x->query('//a[contains(@href,"rowid=")]')->length === ($reuse ? 2 : 0), 'Existing transfer links identify both exact legs');
		$check($x->query('//a[@class="button button-cancel" and contains(@href,"search_account=21")]')->length === 1, 'Cancel retains the list without financial submission');
		$check(strpos($html, $reuse ? $langs->trans('KreaBankUberReuseEffect') : $langs->trans('KreaBankUberCreateEffect')) !== false, 'Review states its actual financial effect');
	}
	$candidate = array('doc_type'=>'uber_payout', 'doc_id'=>51, 'reference'=>'SYNTHETIC-PAYOUT-51', 'date'=>'2026-11-09', 'amount'=>'890.00', 'currency'=>'EUR', 'description'=>'Synthetic restaurant');
	$model = array('url'=>'/custom/kreabank/reconciliationlist.php', 'token'=>'synthetic-csrf', 'can_write'=>true, 'state'=>array('search_account'=>21, 'token'=>'never-carry', 'action'=>'never-carry'),
		'rows'=>array(array('key'=>'staged:11', 'line'=>array('operation_date'=>'2026-11-10', 'amount'=>'890.00', 'currency'=>'EUR', 'description'=>'Synthetic credit', 'status'=>0), 'review'=>$candidate, 'uber_candidates'=>array($candidate), 'batch_unavailable'=>true)));
	$x = uberViewDocument(kreabankWorklistTable($model, $langs));
	$check($x->query('//a[contains(@href,"ubersettlement.php") and contains(@href,"source_key=staged%3A11") and contains(@href,"settlement_id=51")]')->length === 1, 'Standard row links to the exact source and payout review');
	$check($x->query('//a[contains(@href,"ubersettlement.php") and (contains(@href,"token=") or contains(@href,"action="))]')->length === 0, 'Advice navigation carries no action or token');
	$model['can_write'] = false;
	$check(uberViewDocument(kreabankWorklistTable($model, $langs))->query('//a[contains(@href,"ubersettlement.php")]')->length === 0, 'Read-only users cannot invoke settlement approval');
	$model['can_write'] = true; $model['rows'][0]['account_mismatch'] = true;
	$check(uberViewDocument(kreabankWorklistTable($model, $langs))->query('//a[contains(@href,"ubersettlement.php")]')->length === 0, 'Wrong-account evidence blocks the settlement action');
}
if (($argv[1] ?? '') === '--html') {
	$GLOBALS['uberLocale'] = in_array($argv[2] ?? '', array('en_US', 'pt_PT'), true) ? $argv[2] : 'en_US'; $langs = uberViewLanguage($GLOBALS['uberLocale']);
	echo '<!doctype html><html lang="' . str_replace('_', '-', $GLOBALS['uberLocale']) . '"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Synthetic Uber review</title><link rel="stylesheet" href="/theme/eldy/style.css.php?lang=' . $GLOBALS['uberLocale'] . '"><style>body{padding:20px}input[type=checkbox]{width:auto}</style><main><h1>' . dol_escape_htmltag($langs->trans('KreaBankUberReview')) . '</h1>';
	echo kreabankUberReviewHtml(uberViewFixture(($argv[3] ?? '') === 'reuse'), '/custom/kreabank/reconciliationlist.php', 'synthetic-csrf', $langs);
	echo '</main><script>document.addEventListener("submit",function(e){e.preventDefault();document.getElementById("fixture-status").textContent="Synthetic approval intercepted";});</script><p id="fixture-status" role="status"></p></html>'; exit;
}
echo 'OK: ' . $checks . " Uber review/view checks\n";
