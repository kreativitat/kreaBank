#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Execute the shipped review controller with synthetic dependencies, no bootstrap/database.
 */
define('DOL_DOCUMENT_ROOT', getenv('KREABANK_DOLIBARR_ROOT') ?: dirname(__DIR__, 3));
$reviewHtmlMode = ($argv[1] ?? '') === '--html';
define('KREABANK_MATCHED_REVIEW', !$reviewHtmlMode || ($argv[2] ?? 'single') !== 'bulk');
$conf = (object) array('entity'=>7, 'liste_limit'=>25, 'global'=>new stdClass());
$mysoc = (object) array('name'=>'Synthetic company');
$user = new class { public $id=9; public $allowed=true; public $write=true; public function hasRight(...$parts) { return $this->allowed && ($this->write || !in_array(end($parts), array('write','modifier','consolidate'), true)); } };
$langs = new class {
	public $defaultlang = 'pt_PT';
	public $messages = array();
	public function load($file) {}
	public function loadLangs($files) {}
	public function trans($key, ...$args) { return isset($this->messages[$key]) ? ($args ? vsprintf($this->messages[$key], $args) : $this->messages[$key]) : $key . ($args ? ':' . implode('/', $args) : ''); }
	public function transnoentities($key, ...$args) { return $this->trans($key, ...$args); }
};
$db = new class {
	public function prefix() { return 'synthetic_'; }
	public function query($sql) { if (strpos($sql,'SELECT ')!==0) { throw new RuntimeException('Unexpected database write'); } return true; }
	public function fetch_object($result) { return false; }
	public function close() {}
};
$fixtureService = new class {
	public $suggestions=array();
	public $linkedDocuments=array();
	public $accountReview=null;
	public $pendingTotal=0;
	public $line;
	public $executions=array();
	public function getBulkReviewLine($id,$source) { return $source==='staged' && (int)$id===(int)$this->line['rowid'] ? $this->line : null; }
	public function reconcileLine(...$args) { $this->executions[]=$args; }
	public function getPendingLinesCount($filter) { return $this->pendingTotal; }
	public function getPendingLines(...$args) { return array(); }
	public function getPaymentAccountReview($id,$accounts) { return $this->accountReview; }
	public function getSuggestionsForLines($lines,...$args) { return array($lines[0]['rowid']=>$this->suggestions); }
	public function getLinkedPaymentDocuments(...$args) { return $this->linkedDocuments; }
	public function getSafeSuggestion($suggestions,$score) { return null; }
};
function isModEnabled($module) { return $module !== 'degema'; }
function GETPOST($key,$type='') { return $GLOBALS['request'][$key] ?? ''; }
function GETPOSTINT($key) { return (int) GETPOST($key); }
function GETPOSTISSET($key) { return isset($GLOBALS['request'][$key]); }
function getDolGlobalInt($key,$default=0) { return $default; }
function newToken() { return 'synthetic-csrf'; }
function currentToken() { return 'synthetic-csrf'; }
function dol_now() { return 1800000000; }
function dol_buildpath($path,$absolute=0) { return $path; }
function dol_escape_htmltag($value) { return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8'); }
function dol_string_nospecial($value) { return $value; }
function dol_print_date($value,$format) { return gmdate('Y-m-d',is_numeric($value)?$value:strtotime($value)); }
function price($value,...$args) { return number_format((float)$value,2,!empty($GLOBALS['reviewHtmlMode']) ? ',' : '.', ''); }
function llxHeader(...$args) {
	if (empty($GLOBALS['reviewHtmlMode'])) { print '<html><body>'; return; }
	print '<!doctype html><html lang="pt-PT"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Synthetic reconciliation review</title><link rel="stylesheet" href="/custom/kreabank/css/navigation.css"><style>body{font:14px Arial,sans-serif;color:#253e49;margin:0}.fixture-shell{display:grid;grid-template-columns:170px minmax(0,1fr)}aside{background:#f3f6f5;padding:25px 15px;line-height:2.5}main{padding:25px;min-width:0}.kb-header{margin-bottom:25px}.button{padding:9px;background:#f3f6f5;border:1px solid #b9c9c2;border-radius:5px}h1{font-size:20px}@media(max-width:760px){.fixture-shell{grid-template-columns:1fr}aside{display:none}main{padding:14px}}</style></head><body><div class="fixture-shell"><aside aria-label="Native banking menu">Banks<br>Account list<br>Transfers<br>Cheque deposits</aside><main><strong>Synthetic company · No ERP connection · Submissions intercepted</strong>';
}
function llxFooter() {
	if (!empty($GLOBALS['reviewHtmlMode'])) { print '</main></div><output id="fixture-submit" role="status"></output><script>document.addEventListener("submit",function(event){event.preventDefault();document.getElementById("fixture-submit").textContent="Synthetic submission: "+JSON.stringify(Object.assign(Object.fromEntries(new FormData(event.target)),{selected_line_ids:new FormData(event.target).getAll("selected_line_ids[]")}))});</script>'; }
	print '</body></html>';
}
function print_barre_liste(...$args) { print '<p>Page 1 · Synthetic review</p>'; }
function load_fiche_titre($title,...$args) { return '<h1>'.$title.'</h1>'; }
function dol_get_fiche_head(...$args) { return '<main>'; }
function dol_get_fiche_end() { return '</main>'; }
function accessforbidden(...$args) { throw new RuntimeException('Forbidden'); }
function setEventMessages(...$args) { $GLOBALS['fixtureEvents'][]=$args; }
function renderMatchedFixture()
{
	global $conf,$mysoc,$user,$langs,$db;
	$path=__DIR__.'/../bulkmatch.php';
	$source=file_get_contents($path);
	$start=strpos($source,'$res = 0;');
	$end=strpos($source,'require_once',$start);
	if ($start===false || $end===false) throw new RuntimeException('Review bootstrap boundary changed');
	$source=substr($source,0,$start).substr($source,$end);
	$source=str_replace('__DIR__',var_export(dirname($path),true),$source);
	$source=str_replace('$service = new KreaBankService($db, $user, $langs);', '$service = $GLOBALS[\'fixtureService\'];', $source);
	if (GETPOST('action')==='bulk_match_confirm') {
		// Return the actual controller result instead of redirecting/exiting the test process.
		$start=strpos($source, "header('Location: ' . dol_buildpath");
		$end=strpos($source, 'exit;', $start)+5;
		$source=substr($source,0,$start)."return compact('done','changed','failed','reviewConfirmed','lineIds');".substr($source,$end);
	}
	ob_start();
	try { $confirmation=eval('?>'.$source); $html=ob_get_contents(); }
	finally { ob_end_clean(); }
	if ($confirmation!==null) return $confirmation;
	return compact('html','prepareReviewRow','buildLinkedFallbackSuggestions','scanState','bulkScanCacheStoreKey','bulkScanCacheScope');
}
$_SERVER['REQUEST_METHOD']='GET';
$_SERVER['PHP_SELF']='/custom/kreabank/' . (KREABANK_MATCHED_REVIEW ? 'matched' : 'bulkmatch') . '.php';
$_SESSION=array();
$request=array('limit'=>100);
$view=renderMatchedFixture();
if ($reviewHtmlMode) {
	foreach (array('en_US', 'pt_PT') as $locale) {
		foreach (file(__DIR__ . '/../langs/' . $locale . '/kreabank.lang', FILE_IGNORE_NEW_LINES) as $message) {
			if ($message !== '' && $message[0] !== '#' && strpos($message, '=') !== false) { list($key,$value)=explode('=',$message,2);$langs->messages[$key]=$value; }
		}
	}
	foreach (array('BankAccount'=>'Conta bancária','Ref'=>'Referência','KreaBankCounterparty'=>'Contraparte','Previous'=>'Anterior','Next'=>'Seguinte') as $key=>$value) { $langs->messages[$key]=$value; }
	$fixtureCase = $argv[3] ?? 'ready';
	$fixtureService->pendingTotal = $fixtureCase === 'empty' ? 0 : 2;
	$view=renderMatchedFixture();
	$state=$view['scanState'];$state['done']=true;
	$state['next_offset']=$fixtureService->pendingTotal;$state['pending_total']=$fixtureService->pendingTotal;
	if (!in_array($fixtureCase,array('empty','unmatched','loading'),true)) {
		$line=array('rowid'=>11,'fk_statement'=>1,'bank_account_id'=>101,'status'=>0,'direction'=>-1,'amount'=>-125.50,'allocated_amount'=>0,'operation_date'=>'2026-11-03','currency'=>'EUR','description'=>'Pagamento de serviços · novembro','bank_account_ref'=>'BANK-TEST','bank_account_label'=>'Synthetic');
		$suggestion=array('doc_type'=>'native_bank','doc_id'=>99,'doc_ref'=>'BANK-TEST-99','doc_date'=>'2026-11-03','thirdparty_name'=>'Synthetic supplier','bank_account_id'=>101,'bank_account_ref'=>'TARGET-BANK','amount_open'=>-125.50,'score'=>180,'details'=>array('amount','ref_exact'));
		if ($fixtureCase === 'invoice') { $suggestion['doc_type']='supplier_invoice';$suggestion['amount_open']=125.50;$suggestion['score']=100; }
		$pair=array('line'=>$line,'suggestion'=>$suggestion,'allocation'=>125.50,'preselected'=>$fixtureCase!=='invoice','is_bulk_safe'=>$fixtureCase!=='invoice');
		if ($fixtureCase === 'unsafe') { $pair['line']['description']='<img src=x onerror=alert(1)>';$pair['line']['operation_date']='not-a-date';$pair['suggestion']['doc_date']='not-a-date';$pair['suggestion']['thirdparty_name']='<script>untrusted</script>'; }
		$state['matched_rows']=array(array('line_id'=>11,'operation_date'=>'2026-11-03'));
		$state['display_rows']=array(11=>$pair);
		if (!KREABANK_MATCHED_REVIEW) {
			$second=$pair;$second['line']['rowid']=12;$second['line']['description']='Pagamento a fornecedor';$second['suggestion']['doc_ref']='BANK-TEST-100';$second['preselected']=false;$second['is_bulk_safe']=false;
			$state['matched_rows'][]=array('line_id'=>12,'operation_date'=>'2026-11-03');$state['display_rows'][12]=$second;
		}
	}
	if ($fixtureCase === 'loading') { $state['done']=false;$state['next_offset']=0; }
	if ($fixtureCase === 'readonly') { $user->write=false; }
	$_SESSION[$view['bulkScanCacheStoreKey']]=$state;
	print renderMatchedFixture()['html'];exit;
}
$checks=0;
function checkMatched($ok,$message) { global $checks; if (!$ok) throw new RuntimeException($message); $checks++; }
checkMatched(strpos($view['html'],'KreaBankReviewMatched')!==false && strpos($view['html'],'KreaBankNoStatementLines')!==false && strpos($view['html'],'workflow=create')===false, 'Empty matched queue renders its own title without offering creation when nothing is pending');
checkMatched(strpos($view['html'],'"limit":1')!==false, 'User limit=100 cannot expand individual review');
checkMatched($view['bulkScanCacheStoreKey']==='kreabank_matched_scan_state' && !isset($_SESSION['kreabank_bulkmatch_scan_state']), 'Individual review has independent scan progress');
$line=array('rowid'=>11,'fk_statement'=>1,'bank_account_id'=>101,'status'=>0,'direction'=>-1,'amount'=>-20,'allocated_amount'=>0,'operation_date'=>'2026-10-20','currency'=>'EUR','description'=>'Synthetic source','bank_account_ref'=>'TEST','bank_account_label'=>'Synthetic');
$prepare=$view['prepareReviewRow'];
checkMatched($prepare($line)===null,'Unmatched sources are excluded');
$fixtureService->suggestions=array(array('doc_type'=>'native_bank','doc_id'=>99,'doc_ref'=>'TEST-99','doc_date'=>'2026-10-20','thirdparty_name'=>'Synthetic party','bank_account_id'=>101,'bank_account_ref'=>'TARGET & BANK','amount_open'=>-20,'score'=>100,'details'=>array('amount','date')));
$pair=$prepare($line);
checkMatched($pair && !$pair['preselected'] && $pair['allocation']===20.0,'Unique weak counterpart remains reviewable without automatic preselection');
$savedSuggestions=$fixtureService->suggestions;
$fixtureService->suggestions=array();
$fixtureService->linkedDocuments=array(array('rowid'=>98,'doc_type'=>'payment_supplier_linked','ref'=>'PAY-98','doc_date'=>'2026-10-20','amount_open'=>20,'bank_account_id'=>101,'bank_account_ref'=>'FALLBACK BANK'));
$fallback=$view['buildLinkedFallbackSuggestions'];
checkMatched($fallback($fixtureService,$line,3)[0]['bank_account_ref']==='FALLBACK BANK','Linked fallback suggestions retain their recorded bank account');
$fixtureService->linkedDocuments=array();
$fixtureService->suggestions=$savedSuggestions;
$fixtureService->suggestions[]=array_merge($fixtureService->suggestions[0],array('doc_id'=>100,'score'=>90));
checkMatched($prepare($line)===null,'Competing similar-score documents are excluded');
$fixtureService->suggestions=array($fixtureService->suggestions[0]);
$fixtureService->accountReview=array('synthetic'=>true);
checkMatched($prepare($line)===null,'Wrong-account evidence cannot masquerade as a matching counterpart');
$fixtureService->accountReview=null;
$other=array_merge($line,array('rowid'=>12));
$fixtureService->pendingTotal=2;
$view=renderMatchedFixture();
$state=$view['scanState'];
$state['matched_rows']=array(array('line_id'=>11,'operation_date'=>'2026-10-20'),array('line_id'=>12,'operation_date'=>'2026-10-21'));
$state['display_rows']=array(11=>$pair);
$state['pending_total']=2;
$state['done']=true;
$_SESSION['kreabank_matched_scan_state']=$state;
$rendered=renderMatchedFixture();
checkMatched(substr_count($rendered['html'],'name="selected_line_ids[]"')===1 && strpos($rendered['html'],'value="11"')!==false, 'Exactly the current pair is displayed');
checkMatched(strpos($rendered['html'],'matched.php?page=1')!==false && strpos($rendered['html'],'KreaBankReviewPosition:1/2')!==false, 'Next link and position refer to the matched queue');
checkMatched(strpos($rendered['html'],'id="krea_bulk_match_all"')===false && strpos($rendered['html'],'KreaBankConfirmCurrentMatch')!==false, 'Individual review has no select-all action and confirms the current match');
checkMatched(strpos($rendered['html'],'name="review_confirmed"')===false && strpos($rendered['html'],'type="checkbox"')===false,'Individual confirmation requires no selection or review checkboxes');
checkMatched(strpos($rendered['html'],'type="hidden" name="selected_line_ids[]" value="11"')!==false && strpos($rendered['html'],'name="review_signatures[11]"')!==false,'The button submits only its displayed signed source');
checkMatched(strpos($rendered['html'],'TARGET &amp; BANK')!==false && strpos($rendered['html'],'TARGET & BANK')===false,'Suggested account reference is displayed independently of the source and escaped');
$state['done']=false;
$_SESSION['kreabank_matched_scan_state']=$state;
$loading=renderMatchedFixture();
checkMatched(strpos($loading['html'],'id="kreaBulkMatchForm"')===false && strpos($loading['html'],'KreaBankBulkMatchLoading')!==false,'Individual review waits for stable sorted results instead of repeatedly changing its proposed pair');
checkMatched(strpos($loading['html'],'id="krea-bulk-progress"')!==false && strpos($loading['html'],'"presentation":')!==false,'Progress is separate from the signed review panel');
$state['done']=true;
$request['page']=1;
$state['display_page']=1;
$state['display_rows']=array(12=>array_merge($pair,array('line'=>$other)));
$_SESSION['kreabank_matched_scan_state']=$state;
$second=renderMatchedFixture();
checkMatched(strpos($second['html'],'value="12"')!==false && strpos($second['html'],'KreaBankReviewPosition:2/2')!==false && strpos($second['html'],'matched.php?page=0')!==false,'Second pair has previous navigation and retains a single source');
$fixtureService->line=$line;
$_SERVER['REQUEST_METHOD']='POST';
$request=array('action'=>'bulk_match_confirm','token'=>'synthetic-csrf','selected_line_ids'=>array(11),
	'review_sources'=>array(11=>'staged'),'review_expires'=>dol_now()+1800);
$request['review_signatures']=array(11=>kreabankBulkReviewSignature($pair,'7|9|0',$request['review_expires'],$_SESSION['kreabank_bulk_review_secret']));
$posted=renderMatchedFixture();
checkMatched($posted['done']===1 && count($fixtureService->executions)===1,'The individual button confirms a weak signed pair without review_confirmed');
checkMatched($fixtureService->executions[0][2]==='bulk_manual' && $fixtureService->executions[0][3]===0,'Direct confirmation remains an explicit manual reconciliation');
$request['review_signatures'][11]=str_repeat('0',64);
$posted=renderMatchedFixture();
checkMatched($posted['changed']===1 && count($fixtureService->executions)===1,'Tampered direct approval cannot execute');
$request['review_signatures'][11]=kreabankBulkReviewSignature($pair,'7|9|0',$request['review_expires'],$_SESSION['kreabank_bulk_review_secret']);
$fixtureService->suggestions[0]['doc_id']=100;
$posted=renderMatchedFixture();
checkMatched($posted['changed']===1 && count($fixtureService->executions)===1,'A changed current counterpart invalidates direct approval');
$request['token']='bad-token';
try { renderMatchedFixture(); throw new LogicException('Invalid CSRF accepted'); }
catch (RuntimeException $e) { checkMatched($e->getMessage()==='Forbidden','Direct confirmation still requires valid CSRF'); }
$_SERVER['REQUEST_METHOD']='GET';$request=array();
$user->allowed=false;
try { renderMatchedFixture(); throw new LogicException('Denied user was accepted'); }
catch (RuntimeException $e) { checkMatched($e->getMessage()==='Forbidden','Matched review enforces native and module read permissions'); }
echo 'OK: '.$checks." individual matched review checks\n";
