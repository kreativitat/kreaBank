#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Pure presentation checks and intercepted browser fixtures; no ERP bootstrap/database.
 */
require_once __DIR__ . '/../lib/kreabank_worklist_view.lib.php';

function dol_escape_htmltag($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function dol_buildpath($path, $type = 0) { return ($GLOBALS['worklistFixtureUrlRoot'] ?? '') . $path; }
function dol_print_date($timestamp, $format) { return gmdate('d/m/Y', $timestamp); }
function kreabankPrice($value) { return number_format((float) $value, 2, ($GLOBALS['langs']->defaultlang ?? '') === 'pt_PT' ? ',' : '.', ($GLOBALS['langs']->defaultlang ?? '') === 'pt_PT' ? ' ' : ','); }
function dolButtonToOpenUrlInDialogPopup($name,$label,$button,$url,$disabled='',$css='',$open='',$close='',$key='') {
	return '<a href="#" class="' . dol_escape_htmltag($css) . '" data-fixture-native-url="' . dol_escape_htmltag($url . '&dol_hide_topmenu=1&dol_hide_leftmenu=1&dol_openinpopup=' . $name) . '">' . $button . '</a><div id="idfordialog' . $name . '" class="hidden" data-fixture-native-close="' . dol_escape_htmltag($close) . '"></div>';
}

/** Read shipped language resources without bootstrapping Dolibarr or a database. */
function worklistFixtureLanguages($locale)
{
	if (!in_array($locale, array('en_US', 'pt_PT'), true)) { throw new InvalidArgumentException('Unsupported fixture locale'); }
	$messages = array();
	$nativeRoot = getenv('KREABANK_DOLIBARR_ROOT') ?: dirname(__DIR__, 3);
	$files = array();
	foreach (array('main', 'banks', 'bills', 'salaries', 'trips', 'donations', 'loan') as $native) { $files[] = $nativeRoot . '/langs/' . $locale . '/' . $native . '.lang'; }
	$files[] = __DIR__ . '/../langs/' . $locale . '/kreabank.lang';
	$files[] = __DIR__ . '/../langs/' . $locale . '/worklist.lang';
	foreach ($files as $file) {
		if (!is_file($file)) { throw new RuntimeException('Missing fixture language resource: ' . $file); }
		foreach (file($file, FILE_IGNORE_NEW_LINES) as $line) {
			$line = trim($line);
			if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) { continue; }
			list($key, $value) = explode('=', $line, 2);
			$messages[trim($key)] = $value;
		}
	}
	return new class($messages, $locale) {
		public $defaultlang;
		private $messages;
		public function __construct($messages, $locale) { $this->messages = $messages; $this->defaultlang = $locale; }
		public function trans($key) { return $this->messages[$key] ?? $key; }
	};
}
$langs = worklistFixtureLanguages('en_US');

function worklistViewFixture($case)
{
	$action = array('action'=>'confirm_pair', 'fields'=>array('source_key'=>'staged:11', 'review_signature'=>'synthetic-signature', 'review_expires'=>'1800001800'));
	$line = array('rowid'=>11, 'operation_date'=>'2026-11-11', 'amount'=>'-3300.00000000', 'currency'=>'EUR', 'bank_account_ref'=>'STATEMENT-BANK', 'description'=>'SALARIES NOVEMBER', 'status'=>0);
	$candidates = array();
	foreach (array('1000.00000000', '1100.00000000', '1200.00000000') as $index => $amount) {
		$candidates[] = array('key'=>(string) ($index + 21), 'doc_type'=>'native_bank','doc_id'=>$index + 21,'reference'=>'Salary payment SAL-' . ($index + 1), 'description'=>'Employee ' . chr(65 + $index), 'date'=>'2026-11-11', 'amount'=>'-' . $amount, 'currency'=>'EUR', 'account_ref'=>'STATEMENT-BANK', 'selectable'=>true, 'selected'=>true);
	}
	$row = array('key'=>'staged:11', 'line'=>$line, 'review'=>array('doc_type'=>'payment_supplier_linked','doc_id'=>51,'reference'=>'PAY-0011', 'date'=>'2026-11-11', 'amount'=>'-3300.00000000', 'currency'=>'EUR', 'description'=>'Supplier example', 'account_ref'=>'PAYMENT-BANK', 'action'=>$action), 'candidates'=>$candidates,
		'batch_candidates'=>$candidates, 'expanded'=>false, 'batch_mode'=>false, 'search'=>'', 'batch_form'=>array('fields'=>array('source_key'=>'staged:11', 'snapshot'=>'exact-source-snapshot'), 'save_action'=>'save_draft', 'review_action'=>'review_batch', 'selected_total'=>'-3300.00000000', 'difference'=>'0.00000000'));
	$model = array('url'=>'/custom/kreabank/reconciliationlist.php', 'token'=>'synthetic-csrf', 'can_write'=>true, 'state'=>array('mainmenu'=>'bank', 'leftmenu'=>'bank', 'limit'=>25, 'page'=>1, 'search_description'=>'Salary', 'action'=>'never-in-get', 'token'=>'never-in-get'), 'filters'=>array('search_description'=>'Salary', 'search_status'=>'pending'),
		'sort'=>array('field'=>'operation_date', 'order'=>'ASC'), 'accounts'=>array(array('id'=>10, 'ref'=>'STATEMENT-BANK'), array('id'=>20, 'ref'=>'PAYMENT-BANK')),
		'pagination'=>'<div class="fixture-pager"><strong>' . dol_escape_htmltag($GLOBALS['langs']->trans('KreaBankWorklistTitle')) . ' (51)</strong><span><label class="kb-wl-sr" for="fixture-limit">' . dol_escape_htmltag($GLOBALS['langs']->trans('NbOfLines')) . '</label><select id="fixture-limit" name="limit"><option>25</option><option>50</option></select> <a href="?page=0" aria-label="' . dol_escape_htmltag($GLOBALS['langs']->trans('Previous')) . '">‹</a> <input name="pageplusone" type="number" value="2" min="1" aria-label="' . dol_escape_htmltag($GLOBALS['langs']->trans('Page')) . '" class="width50"> / 3 <a href="?page=2" aria-label="' . dol_escape_htmltag($GLOBALS['langs']->trans('Next')) . '">›</a></span></div>', 'rows'=>array($row));
	if (in_array($case, array('batch', 'reviewed', 'batchlong'), true)) {
		$model['rows'][0]['expanded'] = true;
		$model['rows'][0]['batch_mode'] = true;
		$model['rows'][0]['review'] = null;
		$model['rows'][0]['draft'] = array('name'=>'November salaries', 'member_keys'=>array_column($candidates, 'key'));
		if ($case === 'reviewed') { $model['rows'][0]['batch_review'] = array('action'=>'confirm_batch', 'fields'=>array('source_key'=>'staged:11', 'review_signature'=>'signed-group', 'review_members'=>array('native_bank__21','native_bank__22','native_bank__23'))); }
	}
	if ($case === 'batchlong') {
		$model['rows'][0]['batch_suggestion']='unique';
		for ($i=0;$i<45;$i++) { $model['rows'][0]['batch_candidates'][]=array('key'=>(string) (100+$i),'doc_type'=>'native_bank','doc_id'=>100+$i,'reference'=>'Synthetic payment '.(100+$i),'description'=>'Synthetic counterparty','date'=>'2026-11-11','amount'=>'-10.00000000','currency'=>'EUR','account_ref'=>'STATEMENT-BANK','selectable'=>true); }
	}
	if (in_array($case, array('other', 'unsafe', 'readonly'), true)) {
		$model['rows'][0]['expanded'] = true;
		$model['rows'][0]['other_accounts'] = array(array('key'=>'native_bank__90', 'doc_type'=>'native_bank','doc_id'=>90,'reference'=>'PAY-0090', 'date'=>'2026-11-11', 'amount'=>'-3300.00000000', 'currency'=>'EUR', 'description'=>'Office supplier', 'account_ref'=>'OTHER-BANK', 'selectable'=>false,
			'correction'=>array('action'=>'correct_account', 'fields'=>array('source_key'=>'staged:11','account_move_review'=>'synthetic-move-token'), 'from_account_ref'=>'OTHER-BANK', 'to_account_ref'=>'STATEMENT-BANK')));
		$model['rows'][0]['candidates'] = array(); $model['rows'][0]['batch_candidates'] = array();
	}
	if (in_array($case,array('creation','creation_unavailable'),true)) {
        $model['rows'][0]['review']=null; $model['rows'][0]['candidates']=array();
        $model['rows'][0]['expanded']=true; $model['rows'][0]['create_mode']=true; $model['rows'][0]['create_available']=true;
        $model['rows'][0]['movement_form']=array('action'=>'create_movement','fields'=>array('review'=>'synthetic-create-review','expand'=>'staged:11','mode'=>'create'),
            'source'=>array('bank_account_ref'=>'SIGNED-BANK','operation_date'=>'2026-11-12','amount'=>'-42.25000000','currency'=>'EUR','description'=>'Reviewed exact source'),
            'modes'=>array('VIR'=>'Bank transfer','CHQ'=>'Cheque'),'note'=>'<reviewed note>','payment_mode'=>'');
        if($case==='creation_unavailable') { unset($model['rows'][0]['movement_form']); $model['rows'][0]['create_available']=false; }
    }
	if ($case === 'readonly') { $model['can_write'] = false; }
	if ($case === 'direct_correction' || strpos($case,'mismatch_') === 0) {
		$model['rows'][0]['account_mismatch'] = true;
		$model['rows'][0]['review']['correction'] = array('action'=>'correct_account', 'fields'=>array('account_move_review'=>'synthetic-direct-review'), 'from_account_ref'=>'PAYMENT-BANK', 'to_account_ref'=>'STATEMENT-BANK');
		// Stale ordinary action descriptors must never override an account warning.
		$model['rows'][0]['create_available'] = true;
		if ($case === 'mismatch_salary') { $model['rows'][0]['review']['doc_type']='payment_salary'; $model['rows'][0]['review']['description']='Employee example'; }
		if ($case === 'mismatch_tax') { $model['rows'][0]['review']['doc_type']='payment_tax'; $model['rows'][0]['review']['description']='Tax November 2026'; }
		if ($case === 'mismatch_customer') { $model['rows'][0]['review']['doc_type']='payment'; }
		if (in_array($case,array('mismatch_locked','mismatch_readonly'),true)) { unset($model['rows'][0]['review']['correction']); }
		if ($case === 'mismatch_readonly') { $model['can_write']=false; }
		if (in_array($case,array('mismatch_ambiguous','mismatch_truncated'),true)) {
			$model['rows'][0]['review']=null;
			$model['rows'][0]['payment_account_ref']='PAYMENT-BANK, OTHER-BANK';
			$model['rows'][0]['ambiguous']=true;
			if ($case === 'mismatch_truncated') { $model['rows'][0]['notice']=$GLOBALS['langs']->trans('KreaBankWorklistRefineSearch'); }
		}
		if ($case === 'mismatch_stale_payment') {
			$model['rows'][0]['line']['bank_account_id']=10;
			$model['rows'][0]['review']['doc_type']='supplier_invoice';
			$model['rows'][0]['review']['account_planned']=true;
			$model['rows'][0]['review']['payment_url']='/fourn/facture/paiement.php?action=create&facid=51&accountid=10&year=2026&month=11&day=11&reyear=2026&remonth=11&reday=11&amount_51=3300.00000000';
		}
	}
	if ($case === 'completed') {
		$model['rows'][0]['line']['status'] = 2;
		$model['rows'][0]['batch_active'] = true;
		$model['rows'][0]['expanded'] = true;
		$model['rows'][0]['batch_components'] = $candidates;
		$model['rows'][0]['undo_review'] = array('action'=>'undo_batch', 'fields'=>array('batch_review'=>'synthetic-undo-review'));
	}
	if ($case === 'unavailable') {
		$model['rows'][0]['expanded'] = true;
		$model['rows'][0]['batch_mode'] = true;
		$model['rows'][0]['draft'] = array('name'=>'Saved salaries', 'member_keys'=>array_column($candidates,'key'));
		$model['rows'][0]['batch_candidates'][2]['amount'] = null;
		$model['rows'][0]['batch_candidates'][2]['description'] = 'Saved payment unavailable; remove it or refresh its evidence.';
		$model['rows'][0]['notice'] = 'A saved payment needs review.';
	}
	if ($case === 'needs_review') {
		$model['rows'][0]['line']['status'] = 2;
		$model['rows'][0]['needs_review'] = true;
		$model['rows'][0]['notice'] = 'Historical linkage is inconsistent.';
	}
	if (in_array($case,array('planned_invoice','payment_popup','single_bank'),true)) {
		$model['rows'][0]['review']['doc_type']='supplier_invoice'; $model['rows'][0]['review']['account_ref']='STATEMENT-BANK'; $model['rows'][0]['review']['account_planned']=true;
		$model['rows'][0]['review']['consequence']='KreaBankWorklistPaymentRequired';
		// Retain the old action to prove unpaid proposals override stale approval descriptors.
		$model['rows'][0]['expanded']=true; $model['rows'][0]['candidates']=array($model['rows'][0]['review']);
	}
	if ($case === 'payment_popup' || $case === 'single_bank') {
		$model['rows'][0]['line']['bank_account_id']=10;
		$model['rows'][0]['review']['payment_url']='/fourn/facture/paiement.php?action=create&facid=51&accountid=10&year=2026&month=11&day=11&reyear=2026&remonth=11&reday=11&amount_51=3300.00000000';
		$model['rows'][0]['candidates']=array($model['rows'][0]['review']);
	}
	if ($case === 'single_bank') { $model['hide_statement_account']=true; $model['filters']['search_account']='10'; $model['state']['search_account']='10'; }
	if ($case === 'empty_search') { $model['rows'][0]['expanded']=true; $model['rows'][0]['review']=null; $model['rows'][0]['candidates']=array(); $model['rows'][0]['batch_candidates']=array(); $model['rows'][0]['create_available']=true; }
	if (in_array($case,array('selection_single','selection_single_review','selection_legacy'),true)) {
		$r=&$model['rows'][0]; $r['expanded']=true; $r['review']=null;
		$r['batch_candidates']=array(array_merge($candidates[0],array('amount'=>'-3300.00000000','single_eligible'=>true,'group_eligible'=>false)));
		$r['draft']=array('name'=>'','member_keys'=>array($candidates[0]['key']));
		if ($case==='selection_single_review') { $r['batch_review']=array('action'=>'confirm_pair','fields'=>array('review'=>'signed-single-pair')); $r['batch_components']=$r['batch_candidates']; }
		if ($case==='selection_legacy') { $r['key']='native:11'; $r['batch_unavailable']=true; $r['expanded']=false; $r['max_members']=1; $r['batch_form']['grouping_available']=false; }
		unset($r);
	}
	if ($case === 'empty') { $model['rows'] = array(); }
	if ($case === 'unsafe') {
		$model['rows'][0]['line']['description'] = '<img src=x onerror=alert(1)>';
		$model['rows'][0]['review']['reference'] = '<script>untrusted()</script>';
		$model['rows'][0]['review']['date'] = '2026-02-31';
		$model['rows'][0]['other_accounts'][0]['reference'] = 'A" onclick="untrusted()';
		$model['rows'][0]['batch_form']['fields']['snapshot'] = '" onmouseover="untrusted()';
	}
	if ($case === 'ambiguous') { $model['rows'][0]['review'] = null; $model['rows'][0]['ambiguous'] = true; }
	if (in_array($case, array('loading','loading_expanded','loading_ready'), true)) {
		$model['rows'][0]['review'] = null;
		$model['rows'][0]['pending_analysis'] = true;
		$model['scan'] = array('message'=>'Analysing pending lines', 'done'=>4, 'total'=>51, 'page_ready'=>$case === 'loading_ready', 'action'=>array('action'=>'advance_scan', 'fields'=>array('scan_generation'=>'synthetic-generation', 'scan_cursor'=>4)));
		$model['rows'][0]['expanded'] = $case === 'loading_expanded';
		if ($case === 'loading_ready') {
			$model['scan']['done'] = 25; $model['scan']['total'] = 25;
			$model['scan']['action']['action'] = 'more_scan';
		}
	}
	return $model;
}

if (($argv[1] ?? '') === '--html') {
	$case = $argv[2] ?? 'batch';
	$langs = worklistFixtureLanguages($argv[3] ?? 'en_US');
	print '<!doctype html><html lang="' . dol_escape_htmltag(str_replace('_', '-', $langs->defaultlang)) . '"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Synthetic reconciliation list</title><link rel="stylesheet" href="/theme/common/fontawesome-5/css/all.min.css"><link rel="stylesheet" href="/custom/kreabank/css/worklist.css"><script defer src="/custom/kreabank/js/worklist.js"></script><style>body{font:14px Arial,sans-serif;color:#263545;margin:0;background:#fff}.fixture-shell{display:grid;grid-template-columns:160px minmax(0,1fr)}aside{background:#eef1f5;padding:25px 15px;line-height:2.5}main{padding:24px;min-width:0}.fixture-pager{display:flex;gap:15px;justify-content:space-between;align-items:center;margin:20px 0}.liste{border-collapse:collapse;width:100%}.liste td,.liste th{padding:10px 8px;border-bottom:1px solid #ddd;text-align:left}.liste .liste_titre{background:#eef1f5}.liste_titre_filter input,.liste_titre_filter select{max-width:140px}.liste .right{text-align:right}.nowrap{white-space:nowrap}.button{background:#f6f7f8;color:#334555;border:1px solid #c6cdd5;border-radius:4px;cursor:pointer}.flat,select{font:inherit;padding:5px;border:1px solid #bdc6d0;border-radius:3px}input[type=number]{width:45px}.div-table-responsive{overflow:auto}.opacitymedium{opacity:.7}.badge{display:inline-block;padding:3px 6px;border-radius:4px;background:#edf0f3}a{color:#316b9f;text-decoration:none}.fixture-notice{font-size:12px;color:#687582}@media(max-width:700px){.fixture-shell{grid-template-columns:1fr}aside{display:none}main{padding:12px}.fixture-pager{align-items:flex-start;flex-wrap:wrap}}</style></head><body><div class="fixture-shell"><aside aria-label="Native banking menu">Banks<br>Account list<br>Transfers<br>Cheque deposits</aside><main><p class="fixture-notice">Synthetic data · No ERP connection · Submissions intercepted</p>';
	print kreabankWorklistTable(worklistViewFixture($case), $langs);
	print '</main></div><output id="fixture-submit" role="status"></output><script>document.addEventListener("submit",function(event){event.preventDefault();var data=new FormData(event.target,event.submitter);document.getElementById("fixture-submit").textContent="Synthetic submission: "+JSON.stringify(Array.from(data.entries()));});</script></body></html>';
	exit;
}

$checks = 0;
function checkWorklistView($condition, $message) { global $checks; if (!$condition) { throw new RuntimeException($message); } $checks++; }
foreach (array('en_US','pt_PT') as $locale) {
	$langs = worklistFixtureLanguages($locale);
	foreach (array('ready','batch','reviewed','batchlong','other','readonly','empty','unsafe','ambiguous','loading','direct_correction','mismatch_customer','mismatch_salary','mismatch_tax','mismatch_locked','mismatch_readonly','mismatch_ambiguous','mismatch_truncated','mismatch_stale_payment','completed','unavailable','needs_review','creation','creation_unavailable','empty_search','planned_invoice','payment_popup','single_bank','selection_single','selection_single_review','selection_legacy') as $case) {
		$html = kreabankWorklistTable(worklistViewFixture($case), $langs);
		$dom = new DOMDocument();
		$previous = libxml_use_internal_errors(true);
		$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
		libxml_clear_errors();
		libxml_use_internal_errors($previous);
		$xpath = new DOMXPath($dom);
		checkWorklistView($xpath->query('//a[contains(@href,"mode=more")] | //input[@name="entry_submit_action"] | //*[@data-krea-supplier-modal-open]')->length === 0 && strpos($html, 'fa-ellipsis-h') === false, 'Every list view omits More options and its financial forms');
		checkWorklistView($xpath->query('//form[@id="searchFormList"]')->length === 1, $case . ': native paging/search form');
		checkWorklistView($xpath->query('//form[@id="searchFormList"]//*[@name="limit"]')->length === 1, $case . ': exactly one native page-size control');
		checkWorklistView($xpath->query('//form//form')->length === 0, $case . ': no nested forms');
		checkWorklistView($xpath->query('//form[@method="get"]//input[@name="token" or @name="action" or @name="review_signature"]')->length === 0, $case . ': no write evidence in GET forms');
		checkWorklistView($xpath->query('//thead/tr[@class="liste_titre_filter"]/td')->length === ($case==='single_bank'?6:7), $case . ': column header filters');
		checkWorklistView($xpath->query('//select[@name="search_payment_account"]/option[@value="none"]')->length === 1, $case . ': unbanked payment filter');
		checkWorklistView($xpath->query('//nav[contains(@class,"kb-wl-status-tabs")]/a')->length === 5 && $xpath->query('//nav[contains(@class,"kb-wl-status-tabs")]/a[@aria-current="page"]')->length === 1
			&& $xpath->query('//select[@name="search_status"]')->length===0 && $xpath->query('//input[@type="hidden" and @name="search_status"]')->length>=1, $case . ': status tabs replace the dropdown and retain native form state');
		checkWorklistView(strpos($html, 'KreaBankWorklist') === false, $case . ': translated feature copy');
		foreach ($xpath->query('//*[@form]') as $control) {
			checkWorklistView($dom->getElementById($control->getAttribute('form')) instanceof DOMElement, $case . ': every associated control targets an existing form');
		}
		foreach ($xpath->query('//form[@method="post"]') as $form) {
			checkWorklistView($xpath->query('.//input[@name="token"]', $form)->length === 1, $case . ': CSRF token on isolated POST');
		}
        if ($case==='creation') {
            checkWorklistView($xpath->query('//input[@name="action" and @value="create_movement"]')->length===1, 'Explicit isolated creation action');
            checkWorklistView($xpath->query('//select[@name="payment_mode" and @required]/option[@selected]')->length===0, 'User must explicitly choose an active native payment mode');
            checkWorklistView(strpos($html,'SIGNED-BANK')!==false && strpos($html,'Reviewed exact source')!==false && strpos($html,kreabankPrice('-42.25'))!==false, 'Creation consent renders the exact signed source snapshot rather than a stale display projection');
            checkWorklistView($xpath->query('//tr[contains(@class,"kb-wl-detail-row")]//input[@name="q"]')->length===0, 'Creation panel is separate from candidate search');
            checkWorklistView($xpath->query('//a[contains(@href,"mode=create") and @aria-expanded="true"]')->length===1, 'Only the active creation action is expanded');
            checkWorklistView($xpath->query('//textarea[@name="movement_note"]')->item(0)->textContent==='<reviewed note>', 'Sticky note is escaped and retained');
		}
        if ($case==='creation_unavailable') {
            checkWorklistView($xpath->query('//input[@name="action" and @value="create_movement"]')->length===0, 'Unavailable source has no financial creation action');
		}
		checkWorklistView($xpath->query('//tr[contains(@class,"kb-wl-row")]//a[contains(@href,"mode=search")]')->length===0 && strpos($html,'KreaBankWorklistFindMatch')===false, $case . ': no standalone search row action or workspace');
		if (in_array($case,array('selection_single','selection_single_review'),true)) {
			checkWorklistView($xpath->query('//input[@name="action" and @value="confirm_batch"]')->length===0, 'One selected movement never has a group confirmation');
			checkWorklistView($xpath->query('//*[@data-kb-group-name and not(@hidden)]')->length===0, 'Scalar review has no visible group name');
			if ($case==='selection_single_review') { checkWorklistView($xpath->query('//input[@name="action" and @value="confirm_pair"]')->length===1 && $xpath->query('//input[@type="checkbox"]')->length===0, 'A signed scalar review displays the sole movement readonly and confirms the ordinary pair'); }
			else { checkWorklistView($xpath->query('//input[@data-kb-member and @checked and @data-single-eligible="1" and @data-group-eligible="0"]')->length===1 && $xpath->query('//*[@data-kb-save-draft and @hidden]')->length===1, 'A single exact movement is selectable while group draft controls stay hidden'); }

		}
		if ($case==='selection_legacy') { checkWorklistView($xpath->query('//tr[contains(@class,"kb-wl-row")]//a[contains(@href,"mode=batch")]')->length===0, 'Legacy ledger imports do not offer another native movement through the selector'); }
		if ($case === 'planned_invoice') {
			$planned=$xpath->query('//td//span[@class="kb-wl-planned-account"]')->item(0);
			checkWorklistView($planned && strpos($planned->textContent,'STATEMENT-BANK')!==false && $planned->getAttribute('title')===$langs->trans('KreaBankWorklistPlannedPaymentAccount'), 'Unpaid invoice shows the statement bank as a clearly planned payment destination');
			checkWorklistView($xpath->query('//td//span[@class="kb-wl-planned-account"]//span[contains(@class,"fa-clock")]')->length===1
				&& $xpath->query('//button[@data-kb-dialog]')->length===0, 'Planned bank has a compact clock hint and no existing-payment correction action');
			checkWorklistView($xpath->query('//button[@disabled and @type="button" and not(@form) and not(contains(@class,"kb-wl-primary"))]//span[contains(@class,"fa-check")]')->length === 1
				&& $xpath->query('//input[@name="action" and @value="confirm_pair"]')->length === 0, 'Unpaid row retain grey disabled checks without any approval forms or submit binding');
			checkWorklistView($xpath->query('//button[@disabled and @title="' . $langs->trans('KreaBankWorklistPaymentRequired') . '"]')->length === 1, 'The unpaid approval hint explain that payment must be recorded first');
		}
		if (in_array($case,array('batch','reviewed','batchlong'),true)) {
			checkWorklistView($xpath->query('//div[contains(@class,"kb-wl-batch-controls")]//*[@data-kb-selected-total]')->length===1 && $xpath->query('//div[contains(@class,"kb-wl-members")]//*[@data-kb-selected-total or @data-kb-batch-review]')->length===0, 'Batch totals and review action remain outside the scrolling payment list');
			checkWorklistView($xpath->query('//div[contains(@class,"kb-wl-members") and @role="region" and @tabindex="0"]')->length===1, 'The bounded payment list supports keyboard scrolling');
		}
		if ($case === 'payment_popup' || $case === 'single_bank') {
			$popups=$xpath->query('//div[@data-kb-payment-popup]');
			checkWorklistView($popups->length===1 && $xpath->query('//a[contains(@class,"kb-wl-record-payment")]')->length===1, 'The unpaid row retains the native payment popup');
			$ids=array(); foreach($popups as $popup) {
				$ids[]=$popup->getAttribute('id'); parse_str(parse_url($popup->getAttribute('data-kb-payment-refresh'),PHP_URL_QUERY),$query);
				checkWorklistView(($query['refresh_payment']??'')==='staged:11' && ($query['search_description']??'')==='Salary' && !isset($query['token'],$query['action']), 'Native POST refresh is bound to the exact source/filter without a financial action');
			}
			checkWorklistView($xpath->query('//div[@data-kb-payment-popup and starts-with(@data-kb-payment-frame,"iframedialog")]')->length===1, 'The native dialog identifies its own submission frame');
			checkWorklistView(count(array_unique($ids))===1 && $xpath->query('//input[@name="action" and @value="confirm_pair"]')->length===0, 'Native payment dialogs are unique and do not re-enable unpaid reconciliation');
		}
		if ($case==='single_bank') {
			checkWorklistView($xpath->query('//tr[contains(@class,"kb-wl-row")]/td')->length===6 && $xpath->query('//tr[@class="kb-wl-detail-row"]/td[@colspan="6"]')->length===1, 'Selected-bank rows and expanded details omit the repeated account/status columns');
			checkWorklistView($xpath->query('//select[@name="search_account"]')->length===0 && $xpath->query('//form[@id="searchFormList"]/input[@type="hidden" and @name="search_account" and @value="10"]')->length===1, 'The selected-bank view has no redundant selector and preserves its native GET filter');
		}
        if ($case === 'empty_search') {
            checkWorklistView($xpath->query('//div[@class="kb-wl-search"]/span[@class="kb-wl-search-actions"]/button[@type="submit"]')->length === 1
                && $xpath->query('//div[@class="kb-wl-search"]/span[@class="kb-wl-search-actions"]/a[contains(@href,"mode=create") and @data-kb-row-navigation]')->length === 0,
                'The unified selector filter has no duplicate creation shortcut');
            checkWorklistView($xpath->query('//section[contains(@class,"kb-wl-detail")]/a[contains(@href,"mode=create")]')->length === 0,
                'Creation is not repeated below the empty search result');
		}
        if ($case === 'ready' || $case === 'readonly') {
            $link = $xpath->query('//tr[contains(@class,"kb-wl-row")]//td[contains(@class,"kb-wl-match")]//strong/a')->item(0);
            checkWorklistView($link && $link->getAttribute('href') === '/fourn/paiement/card.php?id=51'
                && $link->getAttribute('target') === '_blank' && $link->getAttribute('rel') === 'noopener noreferrer',
                'Displayed linked-payment reference opens its payment ID in an isolated new tab');
		}
        if (in_array($case,array('batch','reviewed','completed'),true)) {
            $links=$xpath->query('//div[contains(@class,"kb-wl-candidate-evidence")]//strong/a');
            checkWorklistView($links->length===3 && $links->item(0)->getAttribute('href')==='/compta/bank/line.php?rowid=21',
                'Batch members open their own native movement, never the group or staging ID');
		}
		if ($case === 'ready') {
			checkWorklistView(strpos($html, 'STATEMENT-BANK') !== false && strpos($html, 'PAYMENT-BANK') !== false, 'Actual source/payment banks are distinct');
			checkWorklistView($xpath->query('//button[@form and @type="submit"]')->length === 1, 'Sole signed proposed pair has direct approval');
		}
		if ($case === 'batch' || $case === 'reviewed') {
			checkWorklistView($xpath->query('//input[@type="checkbox" and @data-kb-member]')->length === ($case === 'batch' ? 3 : 0), 'Fresh signed batch review is readonly; editing has explicit component selection');
			if ($case === 'batch') {
				checkWorklistView(strpos($html, 'data-amount="-1100.00000000"') !== false, 'Source precision retained in allocation feedback');
				checkWorklistView($xpath->query('//input[@name="batch_name" and @form]')->length === 1, 'Batch name belongs to separate POST');
				checkWorklistView($xpath->query('//button[@name="action" and @value="save_draft"]')->length === 1, 'Incomplete draft has explicit save action');
			}
			checkWorklistView($xpath->query('//form[@method="post"]//input[contains(@name,"amount")]')->length === 0, 'Presentation never rewrites POST amounts');
			checkWorklistView(($xpath->query('//input[@name="action" and @value="confirm_batch"]')->length === 1) === ($case === 'reviewed'), 'Only a fresh server-reviewed group can expose final approval');
		}
		if ($case === 'direct_correction' || strpos($case,'mismatch_') === 0) {
			checkWorklistView($xpath->query('//tr[contains(@class,"kb-wl-row")]//*[@data-kb-account-warning]//span[contains(@class,"fa-exclamation-circle")]')->length===1,'A wrong-bank warning stays adjacent to the recorded bank even without correction authority or a unique candidate');
			checkWorklistView($xpath->query('//td[contains(@class,"kb-wl-actions")]/button[@disabled and @type="button" and not(@form) and @title="' . $langs->trans('KreaBankWorklistAccountCorrectionRequired') . '" and @aria-label="' . $langs->trans('KreaBankWorklistAccountCorrectionRequired') . '"]')->length===1,'Wrong-bank reconciliation remains visibly inactive with a correction-specific accessible reason');
			checkWorklistView($xpath->query('//input[@name="action" and @value="confirm_pair"]')->length===0 && $xpath->query('//a[contains(@class,"kb-wl-record-payment") or contains(@href,"mode=create")]')->length===0,'An account warning suppresses stale approval, payment and movement-creation controls');
			if (in_array($case,array('mismatch_locked','mismatch_readonly','mismatch_ambiguous','mismatch_truncated'),true)) {
				checkWorklistView($xpath->query('//button[@data-kb-dialog]')->length===0 && $xpath->query('//*[@data-kb-account-warning and @role="img" and @aria-label="' . $langs->trans('KreaBankWorklistAccountMismatch') . '"]')->length===1,'Uneditable or competing wrong-bank evidence has a named warning and no arbitrary correction action');
			} else {
			checkWorklistView($xpath->query('//tr[contains(@class,"kb-wl-row")]//button[@data-kb-dialog]')->length === 2, 'Wrong-bank proposal opens correction from the payment bank reference and icon');
			checkWorklistView($xpath->query('//tr[contains(@class,"kb-wl-row")]//button[@class="kb-wl-account-ref"]')->item(0)->textContent === 'PAYMENT-BANK', 'The clickable payment bank reference retains the exact displayed account');
			checkWorklistView($xpath->query('//button[@data-kb-account-warning and @aria-haspopup="dialog" and @aria-label="' . $langs->trans('KreaBankWorklistAccountMismatch') . ' ' . $langs->trans('KreaBankWorklistChangeAccount') . '"]')->length===1,'The warning icon has an accessible correction action');
			checkWorklistView($xpath->query('//dialog//button[@data-kb-dialog-close and @type="button"]')->length===2,'Both Cancel and the close icon are non-submitting dialog controls');
			}
			if ($case==='mismatch_salary') { checkWorklistView($xpath->query('//td[@class="kb-wl-match"]//a[@href="/salaries/payment_salary/card.php?id=51"]')->length===1,'Wrong-bank salary payments retain the native payment-card link'); }
			if ($case==='mismatch_tax') { checkWorklistView($xpath->query('//td[@class="kb-wl-match"]//a[@href="/compta/payment_sc/card.php?id=51"]')->length===1,'Wrong-bank tax payments retain the native payment-card link'); }
			if ($case==='mismatch_stale_payment') { checkWorklistView($xpath->query('//*[contains(@class,"kb-wl-planned-account")]')->length===0,'An existing wrong-bank payment is never labelled as a planned destination'); }
			if (in_array($case,array('mismatch_ambiguous','mismatch_truncated'),true)) { checkWorklistView($xpath->query('//td[@class="kb-wl-match"]/strong')->length===0,'Ambiguous or incomplete account evidence never chooses a match card'); }
		}
		if ($case === 'completed') {
			checkWorklistView(strpos($html,'mode=view') !== false && $xpath->query('//div[@class="kb-wl-candidate"]')->length === 3, 'Completed group expands to its exact components');
			checkWorklistView($xpath->query('//input[@type="checkbox"]')->length === 0, 'Completed components cannot be edited');
			checkWorklistView($xpath->query('//input[@name="action" and @value="undo_batch"]')->length === 1, 'Completed group can expose explicitly reviewed undo');
		}
		if ($case === 'unavailable') {
			checkWorklistView($xpath->query('//input[@data-kb-member and @data-amount="" and @checked and not(@disabled)]')->length === 1, 'Unavailable saved member remains selected and removable, without inventing zero');
			checkWorklistView(strpos($html,'A saved payment needs review.') !== false, 'Row notice remains visible beside its proposal');
		}
		if ($case === 'needs_review') {
			$badges = $xpath->query('//td[@class="kb-wl-match"]/span[contains(@class,"warning") and @role="status"]');
			checkWorklistView($badges->length === 1 && $badges->item(0)->textContent === $langs->trans('KreaBankLinkNeedsReview'), 'Inconsistent historical reconciliation never receives a green success badge');
		}
		if ($case === 'other') {
			checkWorklistView($xpath->query('//dialog[@aria-labelledby and @aria-describedby]')->length === 1, 'Bank correction has named modal and stated effect');
			checkWorklistView(strpos($html,'OTHER-BANK') !== false && strpos($html,'STATEMENT-BANK') !== false, 'Correction shows old and destination banks');
			checkWorklistView($xpath->query('//input[@name="account_move_review" and @value="synthetic-move-token"]')->length === 1, 'Correction submits exact prepared review');
			checkWorklistView($xpath->query('//*[@data-kb-batch]//div[contains(@class,"kb-wl-other-account")]')->length===0, 'Other-account review cards remain outside allocation controls');
		}
	if ($case === 'readonly') {
			checkWorklistView($xpath->query('//form[@method="post"]')->length === 0, 'Read-only view has no financial action form');
			checkWorklistView($xpath->query('//button[@data-kb-dialog]')->length === 0, 'Read-only view cannot correct accounts');
		}
		if ($case === 'unsafe') {
			checkWorklistView(strpos($html,'<img src=x') === false && strpos($html,'<script>untrusted') === false, 'Untrusted evidence remains text');
			checkWorklistView($xpath->query('//*[@onclick or @onmouseover or @onerror]')->length === 0, 'Untrusted attributes cannot become handlers');
			checkWorklistView(strpos($html,'1970') === false && strpos($html,'&lt;script&gt;') !== false, 'Invalid dates fail closed without epoch fallbacks');
		}
	}
	foreach (array(
		'(banktransfert)'=>$langs->trans('BankTransfer'),
		'(paiement)'=>$langs->trans('Payment'),
		'(SupplierInvoicePayment)'=>$langs->trans('SupplierInvoicePayment'),
		'(CustomerInvoicePayment)'=>$langs->trans('CustomerInvoicePayment'),
		'(SocialContributionPayment)'=>$langs->trans('SocialContributionPayment'),
		'(VariousPayment)'=>$langs->trans('VariousPayment'),
		'(SalaryPayment)'=>$langs->trans('SalaryPayment'),
		'(payment_salary)'=>$langs->trans('SalaryPayment'),
		'(ExpenseReportPayment)'=>$langs->trans('ExpenseReportPayment'),
		'(DonationPayment)'=>$langs->trans('DonationPayment'),
		'(LoanPayment)'=>$langs->trans('LoanPayment'),
		'(WithdrawalPayment)'=>$langs->trans('WithdrawalPayment'),
		'(BankTransferPayment)'=>$langs->trans('BankTransferPayment'),
		'DG01-PAY-0001 / (paiement) / Ação & Filhos'=>'DG01-PAY-0001 / ' . $langs->trans('Payment') . ' / Ação & Filhos',
		' (BANKTRANSFERT) '=>' ' . $langs->trans('BankTransfer') . ' ',
		'Customer (paiement) department'=>'Customer (paiement) department',
		'(banktransfert) service'=>'(banktransfert) service',
		'Customer/(paiement)/department'=>'Customer/(paiement)/department',
		'(UnknownMarker)'=>'(UnknownMarker)',
	) as $raw=>$expected) {
		checkWorklistView(kreabankWorklistNativeLabel($raw, $langs) === $expected, 'Only reserved standalone native label segments translate: ' . $locale . '/' . $raw);
	}
	$markerModel = worklistViewFixture('batch');
	$markerModel['rows'][0]['batch_candidates'][0]['reference'] = 'DG01-PAY-0001 / (paiement)';
	$markerModel['rows'][0]['batch_candidates'][0]['description'] = '(banktransfert)';
	$markerModel['filters']['search_match'] = '(paiement)';
	$markerModel['rows'][0]['batch_form']['fields']['review_snapshot'] = '(banktransfert) / (paiement)';
	$originalModel = serialize($markerModel);
	$html = kreabankWorklistTable($markerModel, $langs);
	$dom = new DOMDocument();
	$previous = libxml_use_internal_errors(true);
	$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
	libxml_clear_errors(); libxml_use_internal_errors($previous);
	$xpath = new DOMXPath($dom);
		checkWorklistView($xpath->query('//a[contains(@href,"mode=more")] | //input[@name="entry_submit_action"] | //*[@data-krea-supplier-modal-open]')->length === 0 && strpos($html, 'fa-ellipsis-h') === false, 'Every list view omits More options and its financial forms');
	$selection = $xpath->query('//input[@data-kb-member]')->item(0);
	checkWorklistView($selection->getAttribute('aria-label') === $langs->trans('Select') . ' DG01-PAY-0001 / ' . $langs->trans('Payment'), 'Batch selection announces the same translated payment label');
	checkWorklistView($selection->getAttribute('value') === '21' && $selection->getAttribute('data-amount') === '-1000.00000000', 'Native labels never change selected identities or exact allocation evidence');
	checkWorklistView($xpath->query('//input[@name="search_match"]')->item(0)->getAttribute('value') === '(paiement)'
		&& $xpath->query('//input[@name="review_snapshot"]')->item(0)->getAttribute('value') === '(banktransfert) / (paiement)', 'Raw search and signed POST evidence remain unchanged');
	checkWorklistView(serialize($markerModel) === $originalModel, 'Rendering translated native labels does not mutate the prepared model');
}
$GLOBALS['worklistFixtureUrlRoot']='/erp';
foreach (array('native_bank'=>'/compta/bank/line.php?rowid=', 'customer_invoice'=>'/compta/facture/card.php?facid=',
    'supplier_invoice'=>'/fourn/facture/card.php?facid=', 'payment'=>'/compta/paiement/card.php?id=', 'payment_linked'=>'/compta/paiement/card.php?id=',
    'payment_supplier'=>'/fourn/paiement/card.php?id=', 'payment_supplier_linked'=>'/fourn/paiement/card.php?id=', 'tax'=>'/compta/sociales/card.php?id=',
    'payment_tax'=>'/compta/payment_sc/card.php?id=', 'payment_salary'=>'/salaries/payment_salary/card.php?id=') as $type=>$path) {
    checkWorklistView(kreabankWorklistDocumentUrl($type,2147483648)==='/erp'.$path.'2147483648', 'Native link mapping retains application prefix and explicit document identity: '.$type);
}
foreach (array(0,-1,'',null,'1 OR 1=1','1&action=delete','1.5','01',array(1)) as $badId) {
    checkWorklistView(kreabankWorklistDocumentUrl('native_bank',$badId)==='', 'Invalid navigation identities cannot become native links');
}
checkWorklistView(kreabankWorklistDocumentUrl('javascript:alert(1)',1)==='' && kreabankWorklistDocumentUrl('batch',11)==='', 'Unknown types and aggregate groups have no inferred native card');
$candidate=array('key'=>'staged:11','reference'=>'<script>unsafe()</script>','date'=>'2026-11-14','amount'=>'1','currency'=>'EUR','url'=>'javascript:alert(1)');
$html=kreabankWorklistCandidate($candidate,$langs);
checkWorklistView(strpos($html,'<a ')===false && strpos($html,'javascript:')===false && strpos($html,'&lt;script&gt;')!==false, 'Unavailable identities and arbitrary URLs remain plain escaped reference text');
unset($GLOBALS['worklistFixtureUrlRoot']);
checkWorklistView(kreabankWorklistDate('2026-02-31') === '—', 'Invalid date is not normalized into another date');
checkWorklistView(kreabankWorklistDate('2026-11-11') === '11/11/2026', 'Calendar date formatted without timezone drift');
echo 'OK: ' . $checks . " worklist view checks (synthetic, no database)\n";
