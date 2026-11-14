#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

// Exercise the installed native language and formatting APIs without an ERP
// bootstrap, credentials or database. Translation override queries are disabled.
$nativeRoot = getenv('KREABANK_DOLIBARR_ROOT') ?: dirname(__DIR__, 3);
if (!is_file($nativeRoot . '/core/class/translate.class.php')) {
	fwrite(STDERR, "Set KREABANK_DOLIBARR_ROOT to installed Dolibarr source.\n");
	exit(2);
}
define('DOL_DOCUMENT_ROOT', $nativeRoot);
define('DOL_URL_ROOT', '');
require_once DOL_DOCUMENT_ROOT . '/core/lib/functions.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/class/translate.class.php';
require_once __DIR__ . '/../lib/kreabank.lib.php';
require_once __DIR__ . '/../lib/kreabank_worklist_view.lib.php';
$conf = (object) array('global' => (object) array(
	'MAIN_MAX_DECIMALS_UNIT'=>'8', 'MAIN_MAX_DECIMALS_TOT'=>'8', 'MAIN_MAX_DECIMALS_SHOWN'=>'8...'
));
$checks = 0;
function checkWorklistNativeTranslation($condition, $message)
{
	global $checks;
	if (!$condition) { throw new RuntimeException($message); }
	$checks++;
}

foreach (array('en_US'=>'Reconciliation list', 'pt_PT'=>'Lista de reconciliação') as $locale=>$title) {
	$langs = new Translate(DOL_DOCUMENT_ROOT, $conf);
	$langs->defaultlang = $locale;
	$langs->dir = array(dirname(__DIR__), DOL_DOCUMENT_ROOT);
	foreach (array('main','banks','bills','kreabank','worklist') as $file) { $langs->load($file, 2, 0, $locale, 1); }
	checkWorklistNativeTranslation(kreabankWorklistTranslate($langs, 'KreaBankWorklistTitle') === $title, 'Native translation uses the shipped title in ' . $locale);
	if ($locale === 'pt_PT') {
		checkWorklistNativeTranslation(strpos($langs->trans('KreaBankWorklistTitle'), '&ccedil;') !== false, 'The native HTML translator exposes the double-encoding regression condition');
	}
	$description = 'Ação <script>unsafe()</script> &amp; &oacute; "quote"';
	$correction = array('action'=>'correct_account', 'fields'=>array('review'=>'synthetic'), 'from_account_ref'=>'BANK-B', 'to_account_ref'=>'BANK-A');
	$model = array('url'=>'/custom/kreabank/reconciliationlist.php', 'can_write'=>true, 'token'=>'synthetic',
		'accounts'=>array(array('id'=>1,'ref'=>'BANK-A')), 'filters'=>array('search_status'=>'pending'),
		'notice'=>kreabankWorklistTranslate($langs, 'KreaBankWorklistBatchesUnavailable'),
		'rows'=>array(array('key'=>'staged:1', 'expanded'=>true, 'line'=>array('operation_date'=>'2026-11-14','bank_account_ref'=>'BANK-A','amount'=>'-1234.56789012','currency'=>'EUR','description'=>$description),
			'review'=>array('reference'=>'PAY-1','description'=>$description,'date'=>'2026-11-14','amount'=>'-1234.56789012','currency'=>'EUR','account_ref'=>'BANK-B','correction'=>$correction),
			'candidates'=>array(array('key'=>'native_bank__2','reference'=>'PAY-2','date'=>'2026-11-14','amount'=>'-1234.56789012','currency'=>'EUR','account_ref'=>'BANK-A', 'consequence'=>'KreaBankWorklistRecordsPayment',
				'action'=>array('action'=>'confirm_pair','fields'=>array('review'=>'synthetic'),'label'=>'KreaBankWorklistReconcile'))))));
	$html = kreabankWorklistTable($model, $langs);
	$text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
	foreach (array('KreaBankWorklistStatementAccount','KreaBankWorklistPaymentAccount','KreaBankWorklistMatch','KreaBankWorklistChangeAccount','KreaBankWorklistCorrectionEffect','KreaBankWorklistBatchesUnavailable','KreaBankWorklistRecordsPayment') as $key) {
		checkWorklistNativeTranslation(strpos($text, $langs->transnoentities($key)) !== false, 'The rendered label decodes exactly once: ' . $locale . '/' . $key);
	}
	checkWorklistNativeTranslation(strpos($html, '<script>unsafe()') === false && strpos($html, '&lt;script&gt;unsafe()&lt;/script&gt;') !== false, 'Untrusted source markup remains escaped in ' . $locale);
	checkWorklistNativeTranslation(strpos($text, $description) !== false, 'Literal source entities remain evidence rather than being decoded as translations in ' . $locale);
	checkWorklistNativeTranslation(strpos($html, '&amp;ccedil;') === false && strpos($html, '&amp;ecirc;') === false, 'Portuguese control labels are never double encoded');
	checkWorklistNativeTranslation(strpos($html, '1234.56789012') === false, 'Visible monetary evidence retains the native two-decimal display');
}
print "OK: $checks native worklist translation/render checks\n";
