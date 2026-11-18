#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Synthetic setup renderer and review lifecycle; no bootstrap, credentials or database.
 */
error_reporting(E_ALL);
set_error_handler(static function ($severity, $message) { throw new RuntimeException($message); });
require_once __DIR__ . '/../lib/kreabank_opening_view.lib.php';
function dol_escape_htmltag($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function kreabankPrice($value) { return number_format((float) $value,2,($GLOBALS['openingLocale'] ?? '') === 'pt_PT' ? ',' : '.', ' '); }
class OpeningViewLangs
{
	private $messages = array();
	public function __construct($locale) {
		$GLOBALS['openingLocale'] = $locale;
		$native = getenv('KREABANK_DOLIBARR_ROOT') ?: dirname(__DIR__,3);
		foreach (array('en_US',$locale) as $language) {
			foreach (array($native . '/langs/' . $language . '/main.lang', $native . '/langs/' . $language . '/banks.lang', __DIR__ . '/../langs/' . $language . '/kreabank.lang') as $path) {
				if (!is_file($path)) { continue; }
				foreach (file($path,FILE_IGNORE_NEW_LINES) as $line) {
					if (strpos($line,'=') !== false && substr($line,0,1) !== '#') { list($key,$value) = explode('=',$line,2); $this->messages[$key] = $value; }
				}
			}
		}
	}
	public function trans($key, ...$args) { return $args ? vsprintf($this->messages[$key] ?? $key,$args) : ($this->messages[$key] ?? $key); }
}
$checks = 0;
function openingViewCheck($condition,$label) { global $checks; if (!$condition) { throw new RuntimeException($label); } $checks++; }
function openingViewReject($call,$label) { $caught = false; try { $call(); } catch (DomainException $e) { $caught = true; } openingViewCheck($caught,$label); }
$preview = array('entity'=>2, 'actor_id'=>7, 'issued_at'=>1000, 'expires_at'=>1900, 'account_id'=>101,
	'account'=>array('rowid'=>101,'ref'=>'BANK_TEST','label'=>'Synthetic bank <script>bad()</script>','currency_code'=>'EUR','entity'=>2),
	'start_date'=>'2026-11-01','balance_date'=>'2026-10-31','currency'=>'EUR','opening_balance'=>'1234.56000000',
	'ledger_balance'=>'1000.00000001','adjustment_amount'=>'234.55999999','statement_ref'=>'202610','evidence_ref'=>'Official statement <img src=x onerror=bad()>',
	'native_count'=>12,'native_unreconciled_count'=>4,'staged_count'=>10,'native_rows'=>array(array('private'=>'not-for-html')),
	'staged_rows'=>array(),'crossing_rows'=>array(),'facts_hash'=>str_repeat('d',64));
class OpeningViewEngine
{
	public $installed = 0;
	public $previews = array();
	public $applied = array();
	public $fixture;
	public function __construct($fixture) { $this->fixture = $fixture; }
	public function install() { $this->installed++; }
	public function preview(...$inputs) { $this->previews[] = $inputs; return $this->fixture; }
	public function apply($review) { $this->applied[] = $review; }
}
$session = array(); $engine = new OpeningViewEngine($preview);
$input = array('account_id'=>101,'start_date'=>'2026-11-01','opening_balance'=>'1234.56','statement_ref'=>'202610','evidence_ref'=>'Official bank statement');
$prepared = kreabankOpeningHandleAction($engine,$session,'preview',$input,7,2,1000);
$nonce = $prepared['review_nonce'];
openingViewCheck(count($engine->previews) === 1 && !$engine->applied && !$engine->installed,'Preview performs no financial apply or storage installation');
openingViewCheck($engine->previews[0] === array(101,'2026-11-01','1234.56','202610','Official bank statement'),'Preview forwards the exact displayed account, date, balance and evidence');
openingViewCheck(isset($session['kreabank_opening_reviews'][$nonce]) && preg_match('/^[a-f0-9]{64}$/D',$nonce),'Review is stored under an opaque random nonce');
kreabankOpeningHandleAction($engine,$session,'apply',array('account_id'=>101,'review_nonce'=>$nonce,'confirmed'=>'1','opening_balance'=>'999999.99','facts_hash'=>'tampered'),7,2,1001);
openingViewCheck($engine->applied === array($preview) && !isset($session['kreabank_opening_reviews'][$nonce]),'Apply uses only the complete server-side snapshot and consumes the review');
openingViewReject(static function () use ($engine,&$session,$nonce) { kreabankOpeningHandleAction($engine,$session,'apply',array('account_id'=>101,'review_nonce'=>$nonce,'confirmed'=>'1'),7,2,1002); },'A consumed financial review cannot be replayed');
foreach (array('actor','entity','account','expired','unconfirmed','arraynonce') as $case) {
	$nonce = kreabankOpeningStoreReview($session,$preview,7,2,1000);
	$request = array('account_id'=>$case === 'account' ? 102 : 101,'review_nonce'=>$case === 'arraynonce' ? array($nonce) : $nonce,'confirmed'=>$case === 'unconfirmed' ? '' : '1');
	openingViewReject(static function () use ($engine,&$session,$request,$case) {
		kreabankOpeningHandleAction($engine,$session,'apply',$request,$case === 'actor' ? 8 : 7,$case === 'entity' ? 3 : 2,$case === 'expired' ? 1901 : 1001);
	},'The review rejects ' . $case);
}
openingViewCheck(count($engine->applied) === 1,'Rejected reviews never execute a financial apply');
$nonce = kreabankOpeningStoreReview($session,$preview,7,2,1000);
$cancelled = kreabankOpeningHandleAction($engine,$session,'cancel',array('account_id'=>101,'review_nonce'=>$nonce),7,2,1001);
openingViewCheck(!empty($cancelled['redirect']) && count($engine->applied) === 1 && !isset($session['kreabank_opening_reviews'][$nonce]),'Cancellation consumes its review without changing financial records');
openingViewReject(static function () use ($engine,&$session) { kreabankOpeningHandleAction($engine,$session,'reset',array(),7,2,1001); },'Unsupported state-changing setup actions fail closed');
kreabankOpeningHandleAction($engine,$session,'install',array(),7,2,1001);
openingViewCheck($engine->installed === 1 && count($engine->applied) === 1,'Explicit initialization is separate from financial application');
$invalidPreview = $preview; $invalidPreview['entity'] = 3;
openingViewReject(static function () use (&$session,$invalidPreview) { kreabankOpeningStoreReview($session,$invalidPreview,7,2,1000); },'An engine snapshot for another company cannot be stored');
for ($i=0;$i<7;$i++) { kreabankOpeningStoreReview($session,$preview,7,2,1000); }
openingViewCheck(count($session['kreabank_opening_reviews']) <= 5,'Outstanding review state is bounded');

$state = array('available'=>true,'url'=>'/custom/kreabank/admin/opening.php','token'=>'synthetic-csrf','accounts'=>array($preview['account']),
	'openings'=>array(),'values'=>$input,'review'=>null,'review_nonce'=>str_repeat('a',64),'max_date'=>'2026-11-18');
$applied = array('rowid'=>1,'fk_bank_account'=>101,'account_ref'=>$preview['account']['ref'],'account_label'=>$preview['account']['label'],
	'start_date'=>$preview['start_date'],'opening_balance'=>$preview['opening_balance'],'adjustment_amount'=>$preview['adjustment_amount'],
	'currency'=>'EUR','statement_ref'=>$preview['statement_ref'],'evidence_ref'=>$preview['evidence_ref'],'datec'=>'2026-11-18 12:00:00');
foreach (array('en_US','pt_PT') as $locale) {
	$langs = new OpeningViewLangs($locale);
	foreach (array('prepare','review','applied','initialize','empty') as $mode) {
		$model = $state;
		if ($mode === 'review') { $model['review'] = $preview; }
		if ($mode === 'applied') { $model['openings'] = array($applied); $model['accounts'] = array(); }
		if ($mode === 'initialize') { $model['available'] = false; }
		if ($mode === 'empty') { $model['accounts'] = array(); }
		$html = kreabankOpeningPageHtml($model,$langs);
		openingViewCheck(strpos($html,'<script>bad') === false && strpos($html,'<img src=x') === false,'All account and bank evidence is escaped: ' . $locale . '/' . $mode);
		openingViewCheck(strpos($html,'not-for-html') === false && strpos($html,'facts_hash') === false,'Snapshot internals never enter markup: ' . $mode);
		openingViewCheck(strpos($html,'KreaBankOpening') === false,'All setup labels are translated: ' . $locale . '/' . $mode);
		$dom = new DOMDocument(); $previous = libxml_use_internal_errors(true); $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html); libxml_clear_errors(); libxml_use_internal_errors($previous); $xp = new DOMXPath($dom);
		foreach ($xp->query('//input[not(@type="hidden")]|//select') as $control) {
			openingViewCheck($control->hasAttribute('id') && $xp->query('//label[@for="' . $control->getAttribute('id') . '"]')->length === 1,'Each interactive control has one native visible label');
		}
		if ($mode === 'review') {
			openingViewCheck($xp->query('//button[@name="action" and @value="cancel" and @formnovalidate]')->length === 1,'Cancel bypasses checkbox validation and uses its own POST action');
			openingViewCheck($xp->query('//input[@name="confirmed" and @type="checkbox" and @required]')->length === 1,'Financial apply requires an explicit confirmation checkbox');
			openingViewCheck($xp->query('//input[@name="opening_balance" or @name="facts_hash" or @name="native_rows"]')->length === 0,'Apply form carries no mutable financial facts');
			openingViewCheck(strpos($html,'234.55999999') !== false,'Exact adjustment precision remains available in the amount tooltip');
		}
	}
}
$controller = file_get_contents(__DIR__ . '/../admin/opening.php');
$guard = substr($controller,strpos($controller,'if (empty($user->admin)'));
$guard = substr($guard,0,strpos($guard,'$engine ='));
function isModEnabled($name) { return !in_array($name,$GLOBALS['openingDisabledModules'] ?? array(),true); }
function accessforbidden() { throw new LogicException('Forbidden'); }
class OpeningViewUser
{
	public $admin = 1;
	public $socid = 0;
	public $denied = '';
	public function hasRight(...$parts) { return implode('.',$parts) !== $this->denied; }
}
foreach (array('admin','external','banque.modifier','banque.consolidate','kreabank.reconciliation.write','kreabank','banque') as $denial) {
	$user = new OpeningViewUser(); $openingDisabledModules = array();
	if ($denial === 'admin') { $user->admin = 0; }
	elseif ($denial === 'external') { $user->socid = 1; }
	elseif (in_array($denial,array('kreabank','banque'),true)) { $openingDisabledModules = array($denial); }
	else { $user->denied = $denial; }
	$denied = false; try { eval($guard); } catch (LogicException $error) { $denied = true; }
	openingViewCheck($denied,'Native setup authorization rejects ' . $denial);
}
$user = new OpeningViewUser(); $openingDisabledModules = array(); eval($guard);
openingViewCheck(true,'Authorized internal administrator passes the real setup guard');
$postGuard = substr($controller,strpos($controller,"if (\$_SERVER['REQUEST_METHOD']"));
$postGuard = substr($postGuard,0,strpos($postGuard,"\n\ttry"));
function kreabankIsTokenValid() { return $GLOBALS['openingValidToken'] ?? false; }
foreach (array(array('GET',true),array('POST',false)) as $case) {
	$_SERVER['REQUEST_METHOD'] = $case[0]; $openingValidToken = $case[1];
	$denied = false; try { eval($postGuard); } catch (LogicException $error) { $denied = true; }
	openingViewCheck($denied,'Real mutation guard rejects missing POST or CSRF');
}
if (($argv[1] ?? '') === '--html') {
	$locale = in_array($argv[2] ?? '',array('en_US','pt_PT'),true) ? $argv[2] : 'en_US'; $langs = new OpeningViewLangs($locale);
	$mode = $argv[3] ?? 'review';
	if ($mode === 'review') { $state['review'] = $preview; }
	elseif ($mode === 'applied') { $state['openings'] = array($applied); $state['accounts'] = array(); }
	elseif ($mode === 'initialize') { $state['available'] = false; }
	print '<!doctype html><html lang="' . $locale . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'
		. dol_escape_htmltag($langs->trans('KreaBankOpeningTitle')) . '</title></head><body><main><h1>' . $langs->trans('KreaBankOpeningTitle') . '</h1>'
		. kreabankOpeningPageHtml($state,$langs) . '</main></body></html>';
	exit;
}
print "OK: $checks opening setup view and review checks\n";
