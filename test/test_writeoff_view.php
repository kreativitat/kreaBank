#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Actual difference-option renderer and controller, synthetic facts only.
 * Never loads main.inc.php, an installation database or a native payment object.
 */
error_reporting(E_ALL);
set_error_handler(static function ($severity,$message,$file,$line) { throw new RuntimeException($message.' at '.$file.':'.$line); });
require_once __DIR__.'/../class/KreaBankWriteOff.class.php';
require_once __DIR__.'/../lib/kreabank_writeoff_view.lib.php';
require_once __DIR__.'/../lib/kreabank_bulk.lib.php';
require_once __DIR__.'/../lib/kreabank_reconcile_queue.lib.php';
function GETPOST($key,$type='') { return $GLOBALS['writeOffRequest'][$key]??''; }
function GETPOSTINT($key) { return (int)GETPOST($key); }
function dol_now() { return 1800000000; }
function newToken() { return 'synthetic-writeoff-token'; }
function kreabankIsTokenValid() { return GETPOST('token')===newToken(); }
function accessforbidden(...$args) { throw new RuntimeException('Forbidden'); }
function dol_escape_htmltag($value,...$args) { return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8'); }
function kreabankPrice($value) { return number_format((float)$value,2,($GLOBALS['writeOffLocale']??'en_US')==='pt_PT'?',':'.',($GLOBALS['writeOffLocale']??'en_US')==='pt_PT'?'.':','); }
function kreabankNavigationHiddenFields($id) { return '<input type="hidden" name="account_context" value="'.(int)$id.'">'; }
function kreabankWorkspaceUrl($page,$account) { return '/custom/kreabank/'.$page.'.php?account_context='.(int)$account; }
function setEventMessages(...$args) { $GLOBALS['writeOffEvents'][]=$args; }
function dol_syslog(...$args) {}

class WriteOffViewLangs
{
	public $messages=array();
	public function __construct($locale)
	{
		foreach(file(__DIR__.'/../langs/'.$locale.'/kreabank.lang',FILE_IGNORE_NEW_LINES) as $line){if($line===''||$line[0]==='#'||strpos($line,'=')===false){continue;}list($key,$value)=explode('=',$line,2);$this->messages[$key]=$value;}
	}
	public function trans($key,...$args)
	{
		if(strpos($key,'KreaBank')===0&&!isset($this->messages[$key])){throw new RuntimeException('Missing difference-view translation: '.$key);}
		$text=$this->messages[$key]??$key;return $args?vsprintf($text,$args):$text;
	}
}

class WriteOffViewService
{
	public $line;
	public $invoice;
	public $config;
	public $executions=array();
	public $owned=true;
	public $invoiceAllowed=true;
	public $modulesEnabled=true;
	public $fail=false;
	public function __construct($type)
	{
		$this->line=array('rowid'=>11,'entity'=>7,'fk_statement'=>3,'bank_account_id'=>81,'native_bank_line_id'=>0,'status'=>0,
			'direction'=>$type==='supplier_invoice'?-1:1,'amount'=>$type==='supplier_invoice'?'-98.00':'98.00','allocated_amount'=>'0',
			'currency'=>'EUR','operation_date'=>'2026-11-14','value_date'=>'2026-11-14','description'=>'Synthetic invoice settlement','payment_reference'=>'SYNTHETIC-22');
		$this->invoice=array('rowid'=>22,'entity'=>7,'doc_type'=>$type,'fk_soc'=>31,'ref'=>'INV-SYNTHETIC-22','status'=>1,'type'=>0,'paye'=>0,
			'multicurrency_code'=>'EUR','amount_open'=>'100.00','total_ttc'=>'100.00','close_code'=>'','close_note'=>'','tms'=>'2026-11-14 09:00:00');
		$this->config=array('enabled'=>true,'currency'=>'EUR','max_abs'=>'5.00','max_percent'=>'5.00',
			'accounts'=>array('bank_fee'=>'6221','rounding'=>'6881','discount_granted'=>'6821','discount_received'=>'7821'));
	}
	public function getBulkReviewLine($id,$source)
	{
		return $this->owned&&(int)$id===11&&$source==='staged'?$this->line:null;
	}
	public function getWriteOffReview($line,$type,$id,$reason='')
	{
		global $conf;
		if(!$this->invoiceAllowed||!$this->modulesEnabled||$type!==$this->invoice['doc_type']||(int)$id!==22
			||$conf->entity!==7||$line['entity']!==7){return null;}
		$writeoff=KreaBankWriteOff::inspect($line,$this->invoice,$this->config,$reason);
		if(!$writeoff){return null;}
		$writeoff['invoice_id']=22;$writeoff['source_snapshot']=hash('sha256',json_encode($line));$writeoff['invoice_evidence']=hash('sha256','synthetic-unpaid-allocations');
		return array('line'=>$line,'suggestion'=>array('doc_type'=>$type,'doc_id'=>22,'doc_ref'=>$this->invoice['ref'],'doc_date'=>'2026-11-14',
			'thirdparty_name'=>'Synthetic invoice counterparty','bank_account_id'=>0,'amount_open'=>$this->invoice['amount_open'],'score'=>1,'details'=>array()),
			'allocation'=>$writeoff['bank_amount'],'preselected'=>false,'is_bulk_safe'=>false,'writeoff'=>$writeoff);
	}
	public function reviews()
	{
		$reviews=array();foreach(KreaBankWriteOff::reasons($this->invoice['doc_type']) as $reason){$row=$this->getWriteOffReview($this->line,$this->invoice['doc_type'],22,$reason);if($row){$reviews[$reason]=$row;}}return $reviews;
	}
	public function reconcileLine(...$args)
	{
		if($this->fail){throw new RuntimeException('Synthetic native payment rollback');}
		$this->executions[]=$args;$this->line['status']=2;$this->line['native_bank_line_id']=91;
	}
}

/** Read-only postcommit outcome reload; financial execution remains the service double above. */
class KreaBankHistory
{
	public function __construct(...$args) {}
	public function find($id,$source,$account)
	{
		$service=$GLOBALS['service'];$writeoff=array('payment_id'=>88,'invoice_type'=>$service->invoice['doc_type'],
			'remaining_status'=>$service->invoice['doc_type']==='supplier_invoice'?'supplier_partial':'customer_closed');
		return array('rowid'=>(int)$id,'line_source'=>$source,'native_bank_line_id'=>91,
			'history_audit'=>array('rowid'=>51,'audit_type'=>'reconcile','fk_statement_line'=>(int)$id,
				'payload_json'=>json_encode(array('strategy'=>'manual_writeoff','source'=>$source,'native_bank_line_id'=>91,'writeoff'=>$writeoff))));
	}
}

function resetWriteOffView($case='customer',$locale='en_US')
{
	global $service,$conf,$user,$langs,$writeOffRequest,$writeOffEvents;
	$GLOBALS['writeOffLocale']=$locale;$langs=new WriteOffViewLangs($locale);$conf=(object)array('entity'=>7);
	$user=new class {public $id=9;public $write=true;public function hasRight(...$args){return $this->write;}};
	$service=new WriteOffViewService($case==='supplier'?'supplier_invoice':'customer_invoice');$writeOffEvents=array();
	$_SERVER['REQUEST_METHOD']='POST';$_SERVER['PHP_SELF']='/custom/kreabank/reconcile.php';
	$_SESSION=array('kreabank_bulk_review_secret'=>str_repeat('synthetic-writeoff-secret-',3),'kreabank_bulkmatch_scan_state'=>array('old'),'kreabank_matched_scan_state'=>array('old'));
	$writeOffRequest=array('token'=>newToken(),'action'=>'confirm_writeoff','line_id'=>11,'line_source'=>'staged','review_doc'=>$service->invoice['doc_type'].'__22',
		'writeoff_reason'=>'bank_fee','review_expires'=>dol_now()+1800,'queue_order'=>'11,12,14,13','queue_next'=>'12,14,13','queue_search'=>'synthetic settlement');
	foreach($service->reviews() as $reason=>$row){$writeOffRequest['writeoff_signature_'.$reason]=kreabankBulkReviewSignature($row,'7|9|81',$writeOffRequest['review_expires'],$_SESSION['kreabank_bulk_review_secret']);}
}

function submitWriteOffView($nativeWrite=true,$account=81)
{
	global $service,$conf,$user,$langs;
	$action='confirm_writeoff';$canNativeWrite=$nativeWrite;$navigationAccountId=$account;$lineId=GETPOSTINT('line_id');$db=new stdClass();
	$pendingSort=array('field'=>'b.dateo','order'=>'ASC');$queueOrder=kreabankReconcileQueueIds(GETPOST('queue_order'));$queueNext=kreabankReconcileQueueIds(GETPOST('queue_next'));$queueSearch=GETPOST('queue_search');
	$reportActionException=static function($e,...$args){$GLOBALS['writeOffEvents'][]=array('error',$e->getMessage());};
	$source=file_get_contents(__DIR__.'/../reconcile.php');$start=strpos($source,"if (\$action === 'confirm_writeoff') {");$end=strpos($source,"if (\$action === 'confirm_document') {",$start);
	if($start===false||$end===false){throw new LogicException('Review shipped write-off controller boundaries');}
	$block=substr($source,$start,$end-$start);
	$block=str_replace("header('Location: ' . \$redirectUrl); exit;",'return $redirectUrl;',$block,$redirects);
	$block=str_replace("require_once __DIR__ . '/class/KreaBankHistory.class.php';",'/* Synthetic read-only postcommit history. */',$block,$includes);
	if($redirects!==1||$includes!==1){throw new LogicException('Review shipped write-off redirect/history boundaries');}
	return eval($block);
}

function renderWriteOffForms($reviews)
{
	global $service,$conf,$user;
	$writeOffReviewRows=array($service->invoice['doc_type'].'__22'=>$reviews);$selectedLine=$service->line;$navigationAccountId=81;
	$queueNavigationFields=kreabankReconcileQueueHiddenFields(array(array('rowid'=>11),array('rowid'=>12),array('rowid'=>14),array('rowid'=>13)),11,array('field'=>'b.dateo','order'=>'ASC'),'synthetic settlement');
	$source=file_get_contents(__DIR__.'/../reconcile.php');$start=strpos($source,'foreach ($writeOffReviewRows as $docKey=>$reviews) {');$end=strpos($source,'foreach ($draftInvoiceForms as $formId=>$key)', $start);
	if($start===false||$end===false){throw new LogicException('Review shipped write-off form boundaries');}
	ob_start();eval(substr($source,$start,$end-$start));return ob_get_clean();
}

function renderWriteOffView($case='customer',$locale='en_US',$malicious=false)
{
	global $service,$langs;
	resetWriteOffView($case,$locale);$reviews=$service->reviews();$formId='kb-writeoff-'.$service->invoice['doc_type'].'__22';
	if($malicious){foreach($reviews as &$review){$review['writeoff']['account']='<img src=x onerror=alert(1)>';$review['line']['currency']='EUR<script>alert(2)</script>';}unset($review);}
	$markup='<!doctype html><html lang="'.str_replace('_','-',$locale).'"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>KreaBank invoice difference — synthetic fixture</title><style>'
		. 'body{margin:0;color:#243c48;background:#fff;font:14px Arial,sans-serif}.fixture-shell{display:grid;grid-template-columns:170px minmax(0,1fr)}.fixture-menu{padding:30px 18px;background:#f4f6f6;border-right:1px solid #dde4e5;line-height:2.5}.fixture-page{padding:28px;min-width:0}.fixture-note,.krea-muted{color:#546a74}.fixture-note{font-size:12px;margin-bottom:20px}h1{font-size:22px}h3{font-size:17px}.krea-doc-item{max-width:720px;padding:20px;border:1px solid #d4dfe3;border-radius:5px;margin-top:18px;overflow-wrap:anywhere}.kb-candidate-heading{display:flex;justify-content:space-between;gap:20px;margin-bottom:16px}.kb-candidate-amount{font-size:18px}.kb-candidate-amount small{font-size:12px}.button{border:1px solid #bacbd0;border-radius:4px;padding:10px 14px;background:#f3f6f5;color:#243c48;cursor:pointer}.kb-primary-action{background:#31586b;color:#fff;border-color:#31586b}.flat{border:1px solid #b8c7cd;border-radius:4px;padding:9px;background:#fff;box-sizing:border-box;max-width:100%}.kb-account-move{margin-top:20px;padding-top:15px;border-top:1px solid #dfe7ea}.kb-account-move summary{cursor:pointer;font-weight:600;line-height:1.5}.kb-account-move p{line-height:1.6}button:focus-visible,input:focus-visible,select:focus-visible,summary:focus-visible{outline:3px solid #729ba8;outline-offset:2px}#fixture-submit{display:block;padding:14px;overflow-wrap:anywhere}#fixture-submit:empty{display:none}@media(max-width:760px){.fixture-shell{grid-template-columns:minmax(0,1fr)}.fixture-menu{display:none}.fixture-page{padding:14px}.krea-doc-item{padding:16px}.kb-account-move select{display:block;margin-top:8px;width:100%}}'
		. '</style></head><body><div class="fixture-shell"><aside class="fixture-menu" aria-label="Native banking menu">Banks / Cash<br>New account<br>Movements<br>Transfers</aside><main class="fixture-page"><p class="fixture-note">Synthetic data · All submissions intercepted · No ERP connection</p><h1>'.$langs->trans('KreaBankReconcile').'</h1><p>'.dol_escape_htmltag($service->line['description']).' · <strong>'.kreabankPrice($service->line['amount']).' EUR</strong></p><h3>'.$langs->trans('KreaBankDocument').'</h3>'
		. '<article class="krea-doc-item"><div class="kb-candidate-heading"><span>2026-11-14</span><strong class="kb-candidate-amount">'.kreabankPrice('100.00').' <small>EUR</small></strong></div><strong>'.dol_escape_htmltag($service->invoice['ref']).'</strong><p class="krea-muted">Synthetic invoice counterparty</p>'
		. kreabankWriteOffOption($reviews,$formId,$langs).'</article>'.renderWriteOffForms($reviews)
		. '</main></div><output id="fixture-submit" role="status"></output><script>document.addEventListener("submit",function(event){event.preventDefault();const data=new FormData(event.target);document.getElementById("fixture-submit").textContent="Synthetic submission: "+JSON.stringify(Object.fromEntries(data));});</script></body></html>';
	return $markup;
}

if(($argv[1]??'')==='--html'){print renderWriteOffView($argv[2]??'customer',$argv[3]??'en_US');exit;}
$checks=0;$check=static function($ok,$label)use(&$checks){if(!$ok){throw new RuntimeException($label);}$checks++;};
$parse=static function($html){$dom=new DOMDocument();$old=libxml_use_internal_errors(true);$dom->loadHTML($html);libxml_clear_errors();libxml_use_internal_errors($old);return new DOMXPath($dom);};
foreach(array('en_US','pt_PT') as $locale){
	$reference=new WriteOffViewLangs('en_US');$translated=new WriteOffViewLangs($locale);foreach($reference->messages as $key=>$value){if(strpos($key,'KreaBankWriteOff')===0){$check(isset($translated->messages[$key])&&$translated->messages[$key]!=='','Complete difference translation '.$key.' '.$locale);}}
	foreach(array('customer','supplier') as $case){
		$html=renderWriteOffView($case,$locale);$xpath=$parse($html);$doc=$case.'_invoice__22';$form='kb-writeoff-'.$doc;
		$check($xpath->query('//details[contains(@class,"kb-account-move")]/summary')->length===1,'Optional action retains the shared invoice-card expansion '.$case.' '.$locale);
		$check($xpath->query('//select[@name="writeoff_reason" and @required and @form="'.$form.'"]')->length===1,'Explicit reason required on associated form '.$case.' '.$locale);
		$check($xpath->query('//select[@name="writeoff_reason"]/option[@value=""]')->length===1&&$xpath->query('//select[@name="writeoff_reason"]/option[@selected]')->length===0,'No reason preselected '.$case.' '.$locale);
		$check($xpath->query('//form[@id="'.$form.'"]//input[@name="review_doc" and @value="'.$doc.'"]')->length===1,'Actual controller document key rendered '.$case.' '.$locale);
		$check($xpath->query('//form[@id="'.$form.'"]//input[@name="writeoff_signature_bank_fee"]')->length===1&&$xpath->query('//form[@id="'.$form.'"]//input[@name="review_signature"]')->length===0,'Actual per-reason signature names rendered '.$case.' '.$locale);
		$check($xpath->query('//form[@id="'.$form.'"]//input[@name="line_source" and @value="staged"]')->length===1&&$xpath->query('//form[@id="'.$form.'"]//input[@name="account_context" and @value="81"]')->length===1,'Explicit source and owned account rendered '.$case.' '.$locale);
		$check($xpath->query('//form[@id="'.$form.'"]//input[@name="queue_order" and @value="11,12,14,13"]')->length===1,'Displayed queue retained '.$case.' '.$locale);
		$depth=0;preg_match_all('~<form\b[^>]*>|</form>~i',$html,$tags);foreach($tags[0] as $tag){$depth+=stripos($tag,'</')===0?-1:1;$check($depth>=0&&$depth<=1,'No nested difference forms '.$case.' '.$locale);}$check($depth===0,'Associated difference form closed '.$case.' '.$locale);
		$check(strpos($html,$translated->trans($case==='supplier'?'KreaBankWriteOffSupplierOutcome':'KreaBankWriteOffCustomerOutcome'))!==false,'Native closure outcome explained '.$case.' '.$locale);
		$unsafe=$parse(renderWriteOffView($case,$locale,true));$check($unsafe->query('//img | //script[not(contains(.,"Synthetic submission"))]')->length===0,'Currency and reason-account output escaped '.$case.' '.$locale);
		resetWriteOffView($case,$locale);$url=submitWriteOffView();$check(is_string($url)&&count($service->executions)===1,'Displayed reviewed difference reaches existing service '.$case.' '.$locale);
		$args=$service->executions[0];$check($args[2]==='manual_writeoff'&&$args[3]===0&&$args[1][0]['allocated_amount']==='98'&&$args[7]['writeoff']['reason']==='bank_fee','Manual-only exact allocation and selected reason reach service '.$case.' '.$locale);
		parse_str((string)parse_url($url,PHP_URL_QUERY),$query);$check(($query['sortfield']??'')==='b.dateo'&&($query['sortorder']??'')==='ASC'&&($query['queue_order']??'')==='11,12,14,13'&&($query['queue_next']??'')==='12,14,13'&&($query['queue_search']??'')==='synthetic settlement'&&($query['queue_continue']??'')==='1','Successful approval preserves queue sort/search/continuation '.$case.' '.$locale);
		$check(!isset($_SESSION['kreabank_bulkmatch_scan_state'],$_SESSION['kreabank_matched_scan_state']),'Successful approval invalidates stale scan caches '.$case.' '.$locale);
		$check($writeOffEvents[0][0]===$langs->trans($case==='supplier'?'KreaBankWriteOffSupplierPartialDone':'KreaBankWriteOffDone'),'Supplier partial/customer closure feedback accurate '.$case.' '.$locale);
		$before=count($service->executions);$check(submitWriteOffView()===null&&count($service->executions)===$before,'Approved review cannot be replayed '.$case.' '.$locale);
	}
}

foreach(array('token'=>'bad','writeoff_reason'=>'','writeoff_signature_bank_fee'=>str_repeat('0',64),'review_doc'=>'supplier_invoice__22','line_source'=>'native','review_expires'=>dol_now()-1,'line_id'=>12) as $field=>$value){
	resetWriteOffView();$writeOffRequest[$field]=$value;$blocked=false;try{$result=submitWriteOffView();$blocked=$result===null;}catch(RuntimeException $e){$blocked=$e->getMessage()==='Forbidden';}
	$check($blocked&&!$service->executions,'Controller rejects invalid submitted '.$field);
}
foreach(array('GET','missing module right','missing native right','foreign source','invoice rights','disabled modules','disabled config','changed source amount','changed invoice amount','changed invoice reference','changed limits','changed reason account','changed user','changed company','changed selected account') as $case){
	resetWriteOffView();$nativeWrite=true;$account=81;
	switch($case){case 'GET':$_SERVER['REQUEST_METHOD']='GET';break;case 'missing module right':$user->write=false;break;case 'missing native right':$nativeWrite=false;break;case 'foreign source':$service->owned=false;break;case 'invoice rights':$service->invoiceAllowed=false;break;case 'disabled modules':$service->modulesEnabled=false;break;case 'disabled config':$service->config['enabled']=false;break;case 'changed source amount':$service->line['amount']='97.99';break;case 'changed invoice amount':$service->invoice['amount_open']='100.01';break;case 'changed invoice reference':$service->invoice['ref']='CHANGED';break;case 'changed limits':$service->config['max_abs']='4';break;case 'changed reason account':$service->config['accounts']['bank_fee']='6229';break;case 'changed user':$user->id=10;break;case 'changed company':$conf->entity=8;break;case 'changed selected account':$account=82;break;}
	$blocked=false;try{$blocked=submitWriteOffView($nativeWrite,$account)===null;}catch(RuntimeException $e){$blocked=$e->getMessage()==='Forbidden';}
	$check($blocked&&!$service->executions,'Fresh signed evidence rejects '.$case);
}
resetWriteOffView();$writeOffRequest['writeoff_reason']='rounding';$writeOffRequest['writeoff_signature_rounding']=$writeOffRequest['writeoff_signature_bank_fee'];
$check(submitWriteOffView()===null&&!$service->executions,'A signature cannot be transplanted to another reason/account/close code');
resetWriteOffView();$service->fail=true;$check(submitWriteOffView()===null&&!$service->executions&&$service->line['status']===0,'Native failure retains pending source and prevents queue redirect');
$check(isset($_SESSION['kreabank_bulkmatch_scan_state'],$_SESSION['kreabank_matched_scan_state']),'Failed approval retains current scan state');
echo 'OK: '.$checks." synthetic write-off view/controller checks\n";
