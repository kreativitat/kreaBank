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
require_once __DIR__ . '/../lib/kreabank_worklist.lib.php';
$conf = (object) array('global' => (object) array(
	'MAIN_MAX_DECIMALS_UNIT'=>'8', 'MAIN_MAX_DECIMALS_TOT'=>'8', 'MAIN_MAX_DECIMALS_SHOWN'=>'8...'
));
$conf->currency = 'EUR';
$conf->use_javascript_ajax = 1;
$conf->file = (object) array('dol_document_root'=>array('main'=>DOL_DOCUMENT_ROOT));
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
	foreach (array('main','banks','bills','salaries','trips','donations','loan','kreabank','worklist') as $file) { $langs->load($file, 2, 0, $locale, 1); }
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
			'batch_candidates'=>array(array('key'=>'native_bank__2','reference'=>'PAY-2','date'=>'2026-11-14','amount'=>'-1234.56789012','currency'=>'EUR','account_ref'=>'BANK-A', 'consequence'=>'KreaBankWorklistRecordsPayment',
				'action'=>array('action'=>'confirm_pair','fields'=>array('review'=>'synthetic'),'label'=>'KreaBankWorklistReconcile'))))));
	$html = kreabankWorklistTable($model, $langs);
	$text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
	foreach (array('KreaBankWorklistStatementAccount','KreaBankWorklistPaymentAccount','KreaBankWorklistMatch','KreaBankWorklistChangeAccount','KreaBankWorklistCorrectionEffect','KreaBankWorklistBatchesUnavailable','KreaBankWorklistRecordsPayment') as $key) {
		checkWorklistNativeTranslation(strpos($text, $langs->transnoentities($key)) !== false, 'The rendered label decodes exactly once: ' . $locale . '/' . $key);
	}
	checkWorklistNativeTranslation(strpos($html, '<script>unsafe()') === false && strpos($html, '&lt;script&gt;unsafe()&lt;/script&gt;') !== false, 'Untrusted source markup remains escaped in ' . $locale);
	checkWorklistNativeTranslation(strpos($text, $description) !== false, 'Literal source entities remain evidence rather than being decoded as translations in ' . $locale);
	checkWorklistNativeTranslation(strpos($html, '&amp;ccedil;') === false && strpos($html, '&amp;ecirc;') === false, 'Portuguese control labels are never double encoded');
	checkWorklistNativeTranslation(strpos($text, '1234.56789012') === false, 'Visible monetary evidence retains the native two-decimal display');
	$paymentRef = 'DG01-PAY-0001 / (paiement) / Ação &amp; Filhos';
	$model['rows'][0]['review']['reference'] = $paymentRef;
	$model['rows'][0]['review']['description'] = '(paiement) / Ação &amp; Filhos';
	$model['rows'][0]['review']['correction']['fields']['review_label'] = $paymentRef;
	$model['rows'][0]['batch_candidates'][0]['reference'] = '(banktransfert)';
	$model['rows'][0]['batch_candidates'][0]['description'] = 'Customer (paiement) department';
	$model['filters']['search_match'] = '(banktransfert)';
	$before = serialize($model);
	$html = kreabankWorklistTable($model, $langs);
	$dom = new DOMDocument();
	$previous = libxml_use_internal_errors(true);
	$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
	libxml_clear_errors(); libxml_use_internal_errors($previous);
	$xpath = new DOMXPath($dom);
	$reference = $xpath->query('//td[@class="kb-wl-match"]/strong')->item(0)->textContent;
	$expected = 'DG01-PAY-0001 / ' . $langs->transnoentities('Payment') . ' / Ação &amp; Filhos';
	checkWorklistNativeTranslation($reference === $expected && substr_count($reference, 'DG01-PAY-0001') === 1, 'Native payment labels translate inside a composite reference without repeating its document reference: ' . $locale);
	checkWorklistNativeTranslation($xpath->query('//td[@class="kb-wl-match"]/span')->item(0)->textContent === $langs->transnoentities('Payment') . ' / Ação &amp; Filhos', 'The proposed payment subtitle translates the same reserved marker: ' . $locale);
	checkWorklistNativeTranslation($xpath->query('//dialog/p/strong')->item(0)->textContent === $expected, 'Account correction repeats the translated displayed reference: ' . $locale);
	checkWorklistNativeTranslation($xpath->query('//div[@class="kb-wl-candidate-evidence"]/strong')->item(0)->textContent === $langs->transnoentities('BankTransfer'), 'Native bank-transfer titles use the installed banks translation exactly once: ' . $locale);
	checkWorklistNativeTranslation($xpath->query('//div[@class="kb-wl-candidate-evidence"]/span')->item(0)->textContent === 'Customer (paiement) department', 'Marker-like customer prose is not rewritten: ' . $locale);
	checkWorklistNativeTranslation($xpath->query('//input[@name="review_label"]')->item(0)->getAttribute('value') === $paymentRef
		&& $xpath->query('//input[@name="search_match"]')->item(0)->getAttribute('value') === '(banktransfert)', 'Translated display never rewrites signed review or search evidence: ' . $locale);
	checkWorklistNativeTranslation(serialize($model) === $before && strpos($html, '&amp;ecirc;') === false, 'Native label translations preserve the original model and Portuguese encoding: ' . $locale);
	foreach (array('SupplierInvoicePayment','CustomerInvoicePayment','SocialContributionPayment','VariousPayment','SalaryPayment','ExpenseReportPayment','DonationPayment','LoanPayment','WithdrawalPayment','BankTransferPayment') as $key) {
		$translated = $langs->transnoentities($key);
		checkWorklistNativeTranslation($translated !== $key && kreabankWorklistNativeLabel('(' . $key . ')', $langs) === $translated, 'Reserved movement marker uses an installed native translation: ' . $locale . '/' . $key);
	}
	$model['rows'][0]['review'] = array('doc_type'=>'supplier_invoice','reference'=>'INVOICE-1','account_ref'=>'BANK-A','account_planned'=>true,
		'action'=>array('action'=>'confirm_pair','fields'=>array('review'=>'stale-before-payment-policy')));
	$planned = kreabankWorklistTable($model, $langs);
	$previous = libxml_use_internal_errors(true);
	$dom->loadHTML('<?xml encoding="utf-8" ?>' . $planned);
	libxml_clear_errors(); libxml_use_internal_errors($previous);
	$xpath = new DOMXPath($dom);
	$account = $xpath->query('//span[@class="kb-wl-planned-account"]')->item(0);
	checkWorklistNativeTranslation($account && $account->getAttribute('title') === $langs->transnoentities('KreaBankWorklistPlannedPaymentAccount') && strpos($account->textContent, 'BANK-A') !== false, 'Planned destination displays the exact bank and native translated tooltip: ' . $locale);
	$disabled = $xpath->query('//td[contains(@class,"kb-wl-actions")]/button[@disabled and @type="button" and not(@form)]')->item(0);
	checkWorklistNativeTranslation($disabled && $disabled->getAttribute('title') === $langs->transnoentities('KreaBankWorklistPaymentRequired') && strpos($disabled->getAttribute('class'),'kb-wl-primary') === false,
		'Actual native labels explain the disabled unpaid proposal without a green submit action: ' . $locale);
	$model['rows'][0]['line']['bank_account_id'] = 1;
	$model['rows'][0]['review']['doc_id'] = 51;
	$model['rows'][0]['review']['payment_url'] = '/fourn/facture/paiement.php?action=create&facid=51&accountid=1&year=2026&month=11&day=14&reyear=2026&remonth=11&reday=14&amount_51=1234.56789012';
	$before = serialize($model);
	$popup = kreabankWorklistTable($model, $langs);
	$previous = libxml_use_internal_errors(true);
	$dom->loadHTML('<?xml encoding="utf-8" ?>' . $popup);
	libxml_clear_errors(); libxml_use_internal_errors($previous);
	$xpath = new DOMXPath($dom);
	$opener = $xpath->query('//div[@data-kb-payment-popup]/a[contains(@class,"kb-wl-record-payment")]')->item(0);
	checkWorklistNativeTranslation($opener && trim($opener->textContent) === $langs->transnoentities('KreaBankWorklistRecordPayment') && $opener->getAttribute('href') === '#',
		'The installed native popup renders the translated payment opener: ' . $locale);
	$scripts = '';
	foreach ($dom->getElementsByTagName('script') as $script) { $scripts .= $script->textContent; }
	checkWorklistNativeTranslation(strpos($scripts, '/fourn/facture/paiement.php?action=create&facid=51&accountid=1&year=2026&month=11&day=14') !== false
		&& strpos($scripts, 'dol_hide_topmenu=1&dol_hide_leftmenu=1&dol_openinpopup=kbwlpay') !== false
		&& strpos($scripts, "new Event('kb-worklist-payment-close',{bubbles:true})") !== false,
		'The actual Dolibarr helper owns the iframe and read-only close notification: ' . $locale);
	checkWorklistNativeTranslation(serialize($model) === $before && $xpath->query('//td[contains(@class,"kb-wl-actions")]/button[@disabled]')->length === 1,
		'The payment popup preserves evidence and leaves unpaid reconciliation disabled: ' . $locale);
	$selectionModel=$model;$selectionModel['rows'][0]['review']=null;
	$selectionModel['rows'][0]['draft']=array('member_keys'=>array('1','2'));
	$selectionModel['rows'][0]['batch_form']=array('fields'=>array('source_key'=>'staged:1'));
	$selectionHtml=kreabankWorklistTable($selectionModel,$langs);
	$previous=libxml_use_internal_errors(true);$dom->loadHTML('<?xml encoding="utf-8" ?>'.$selectionHtml);libxml_clear_errors();libxml_use_internal_errors($previous);$xpath=new DOMXPath($dom);
	checkWorklistNativeTranslation($xpath->query('//input[@name="q" or @name="batch_name"]')->length===0,'Native selector rendering omits the removed search/name fields: '.$locale);
	foreach(array('data-kb-save-draft'=>array('KreaBankWorklistSaveDraft','KreaBankWorklistSaveDraftHelp'),'data-kb-batch-review'=>array('KreaBankWorklistReviewSelection','KreaBankWorklistReviewSelectionHelp'))as$attribute=>$keys){
		$button=$xpath->query('//button[@'.$attribute.']')->item(0);
		checkWorklistNativeTranslation($button && trim($button->textContent)===$langs->transnoentities($keys[0]) && $button->getAttribute('aria-label')===$langs->transnoentities($keys[0]),'Native visible and accessible action labels decode exactly once: '.$locale.'/'.$keys[0]);
		checkWorklistNativeTranslation($button->getAttribute('title')===$langs->transnoentities($keys[1]) && strpos($selectionHtml,'&amp;ccedil;')===false,'Native hover explanation preserves Portuguese encoding: '.$locale.'/'.$keys[1]);
	}
}
// Render the actual controller's title block with installed native helpers. The
// native sort cleanup regex does not consume underscores in existing sort keys.
$controller = file_get_contents(__DIR__ . '/../reconciliationlist.php');
$start = strpos($controller, '$headers = array();');
$end = strpos($controller, '$scan = null;', $start);
if ($start === false || $end === false) { throw new RuntimeException('Native header test boundary changed'); }
$_SERVER['PHP_SELF'] = '/custom/kreabank/reconciliationlist.php';
foreach (array('operation_date','bank_account_ref','payment_account') as $sort) {
	$state = kreabankWorklistState(array('sortfield'=>$sort,'sortorder'=>'ASC','search_account'=>'101','search_description'=>'Salary & reference','limit'=>15));
	eval(substr($controller, $start, $end - $start));
	foreach ($headers as $field=>$html) {
		$dom = new DOMDocument();
		$previous = libxml_use_internal_errors(true);
		$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
		libxml_clear_errors();
		libxml_use_internal_errors($previous);
		foreach ($dom->getElementsByTagName('a') as $link) {
			parse_str(parse_url($link->getAttribute('href'), PHP_URL_QUERY), $query);
			checkWorklistNativeTranslation(($query['sortfield'] ?? '') === $field && !array_intersect(array_keys($query), array('_date','_account','_account_ref')), 'Native sort links retain the exact field without orphaned underscore fragments');
			checkWorklistNativeTranslation(($query['search_account'] ?? '') === '101' && ($query['search_description'] ?? '') === 'Salary & reference' && ($query['limit'] ?? '') === '15', 'Native sort links retain header filters and page size');
		}
	}
}
print "OK: $checks native worklist translation/render checks\n";
