#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Render shipped rule views with synthetic dependencies. No ERP bootstrap or database.
 */
error_reporting(E_ALL);
set_error_handler(static function ($severity,$message,$file,$line) { throw new RuntimeException($message . ' at ' . $file . ':' . $line); });
require_once __DIR__ . '/../lib/kreabank_rules_view.lib.php';
function dol_escape_htmltag($value, ...$args) { return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8'); }
function dol_buildpath($path, $absolute=0) { return $path; }
function newToken() { return 'synthetic-rule-token'; }
function getNonce() { return 'synthetic-rule-nonce'; }
function kreabankPrice($value) { return number_format((float)$value,2,($GLOBALS['ruleViewLocale']??'en_US')==='pt_PT'?',':'.',($GLOBALS['ruleViewLocale']??'en_US')==='pt_PT'?'.':','); }
function load_fiche_titre($title,...$args) { return '<h1>'.$title.'</h1>'; }
function kreabankAdminPrepareHead() { return array(); }
function dol_get_fiche_head(...$args) { return '<nav class="fixture-tabs"><a href="#">Settings</a><a class="active" href="#">'.$GLOBALS['langs']->trans('KreaBankRules').'</a></nav>'; }
function dol_get_fiche_end() { return ''; }
function llxHeader(...$args)
{
	$locale=$GLOBALS['ruleViewLocale']??'en_US';
	print '<!doctype html><html lang="'.str_replace('_','-',$locale).'"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>KreaBank rules — synthetic fixture</title><style>'
		. 'body{margin:0;color:#243c48;background:#fff;font:14px Arial,sans-serif}.fixture-shell{display:grid;grid-template-columns:170px minmax(0,1fr)}.fixture-menu{padding:30px 18px;background:#f4f6f6;border-right:1px solid #dde4e5;line-height:2.5}.fixture-page{padding:28px;min-width:0}.fixture-note{font-size:12px;color:#536779;margin-bottom:20px}.fixture-tabs{display:flex;gap:20px;border-bottom:1px solid #d6dfe2;padding-bottom:12px;margin:18px 0}.fixture-tabs a{color:inherit;text-decoration:none}.fixture-tabs .active{font-weight:bold}h1{font-size:22px}h3{margin-top:28px}h4{margin:24px 0 12px}.opacitymedium,.krea-muted{color:#546a74}table{border-collapse:collapse}th,td{padding:10px 12px;text-align:left;vertical-align:middle}table.border td{border-bottom:1px solid #e3e9ea}.liste_titre{background:#f2f5f5}.oddeven:nth-child(even){background:#f9fbfb}.centpercent{width:100%}.titlefield{width:200px}.button,.butAction{display:inline-block;border:1px solid #bacbd0;border-radius:4px;padding:9px 12px;background:#f3f6f5;color:#243c48;text-decoration:none;cursor:pointer;margin:2px}.small{padding:6px 9px}.button-save,.kb-primary-action{background:#31586b;color:#fff;border-color:#31586b}.flat{border:1px solid #b8c7cd;border-radius:4px;padding:8px;background:#fff;box-sizing:border-box;max-width:100%}.width75{width:75px}.width100{width:100px}.minwidth200{min-width:200px}.maxwidth300{max-width:300px}.div-table-responsive{overflow-x:auto}.inline-block{display:inline-block}.nowrap{white-space:nowrap}.right{text-align:right}.center{text-align:center;margin-top:20px}.tabsAction{text-align:right;margin-top:20px}.sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}.krea-doc-item{padding:18px;border:1px solid #d4dfe3;border-radius:5px;max-width:700px}.kb-candidate-heading{display:flex;justify-content:space-between;gap:20px;margin-bottom:16px}.kb-candidate-amount{font-size:18px}.kb-candidate-amount small{font-size:12px}.error{color:#9b3123}button:focus-visible,a:focus-visible,input:focus-visible,select:focus-visible,summary:focus-visible{outline:3px solid #729ba8;outline-offset:2px}#fixture-submit{display:block;padding:14px;overflow-wrap:anywhere}#fixture-submit:empty{display:none}@media(max-width:760px){.fixture-shell{grid-template-columns:minmax(0,1fr)}.fixture-menu{display:none}.fixture-page{padding:14px}.titlefield{min-width:120px}.maxwidth75onsmartphone{max-width:75vw}}'
		. '</style></head><body><div class="fixture-shell"><aside class="fixture-menu" aria-label="Native administration menu">Configuration<br>Modules<br>Banking</aside><main class="fixture-page"><p class="fixture-note">Synthetic data · All submissions intercepted · No ERP connection</p>';
}
function llxFooter()
{
	print '</main></div><output id="fixture-submit" role="status"></output><script>document.addEventListener("submit",function(event){event.preventDefault();const data=new FormData(event.target);document.getElementById("fixture-submit").textContent="Synthetic submission: "+JSON.stringify(Object.fromEntries(data));});document.querySelectorAll("a").forEach(function(link){link.addEventListener("click",function(event){event.preventDefault();document.getElementById("fixture-submit").textContent="Synthetic navigation: "+link.getAttribute("href");});});</script></body></html>';
}
class RulesViewLangs
{
	public $messages=array();
	public function __construct($locale)
	{
		foreach(file(__DIR__.'/../langs/'.$locale.'/kreabank.lang',FILE_IGNORE_NEW_LINES) as $line){
			if($line==='' || $line[0]==='#' || strpos($line,'=')===false){continue;}
			list($key,$value)=explode('=',$line,2);$this->messages[$key]=$value;
		}
		$nativeRoot=getenv('KREABANK_DOLIBARR_ROOT')?:dirname(__DIR__,3);
		foreach(array('main','admin','banks','companies','categories') as $name){
			$file=$nativeRoot.'/langs/'.$locale.'/'.$name.'.lang';
			if(!is_file($file)){continue;}
			foreach(file($file,FILE_IGNORE_NEW_LINES) as $line){if($line===''||$line[0]==='#'||strpos($line,'=')===false){continue;}list($key,$value)=explode('=',$line,2);if(!isset($this->messages[$key])){$this->messages[$key]=$value;}}
		}
	}
	public function trans($key,...$args)
	{
		if(strpos($key,'KreaBank')===0&&!isset($this->messages[$key])){throw new RuntimeException('Missing rule-view translation: '.$key);}
		$text=$this->messages[$key]??$key;
		return $args?vsprintf($text,$args):$text;
	}
}

function rulesViewRow($malicious=false)
{
	$label=$malicious?'Monthly fee <img src=x onerror=alert(1)>':'Monthly bank fees';
	return array('rowid'=>1,'entity'=>1,'label'=>$label,'active'=>1,'priority'=>100,'fk_bank_account'=>101,'direction'=>'out',
		'conditions'=>array('all'=>array(array('field'=>'description','operator'=>'contains','value'=>'Monthly fee'),array('field'=>'amount','operator'=>'between','value'=>'-30','max'=>'-1')),'any'=>array(array('field'=>'currency','operator'=>'equals','value'=>'EUR'))),
		'action'=>array('type'=>'quick_entry','thirdparty_id'=>201,'accounting_account'=>'6221','payment_mode'=>'PRE','vat'=>'23','label'=>$malicious?'Fee <script>alert(1)</script>':'Monthly bank service fee'),
		'hit_count'=>7,'last_used'=>'2026-11-14 09:12:00','revision'=>str_repeat('a',64));
}
function renderRulesView($case='admin',$locale='en_US',$options=array())
{
	global $langs;
	$GLOBALS['ruleViewLocale']=$locale;$langs=new RulesViewLangs($locale);
	$row=rulesViewRow(!empty($options['malicious']));
	if($case==='quick'){
		$review=array('line'=>array('operation_date'=>'2026-11-14','currency'=>'EUR'),'allocation'=>'12.34',
			'suggestion'=>array('thirdparty_name'=>!empty($options['malicious'])?'Synthetic bank <img src=x onerror=alert(2)>':'Synthetic bank services'),
			'rule_creation'=>$row['action'],'bank_rule'=>array('id'=>1,'label'=>$row['label'],'revision'=>$row['revision'],
				'conflicts'=>array(array('label'=>$row['label']),array('label'=>!empty($options['malicious'])?'Other <script>alert(3)</script>':'General service fees'))));
		ob_start();llxHeader();print load_fiche_titre($langs->trans('KreaBankReconcile'));print kreabankRuleQuickCard($review,'synthetic-rule-review',$langs);
		print '<form method="post" id="synthetic-rule-review"><input type="hidden" name="action" value="confirm_rule_entry"><input type="hidden" name="review_doc" value="quick_entry__0"><input type="hidden" name="review_signature" value="synthetic-only"></form>';llxFooter();return ob_get_clean();
	}
	$rules=new class($row,!empty($options['missing'])) {
		private $row;private $missing;
		public function __construct($row,$missing){$this->row=$row;$this->missing=$missing;}
		public function available(){return !$this->missing;}
		public function all(){return array($this->row);}
		public function options(){return array('accounts'=>array((object)array('rowid'=>101,'ref'=>'BANK-TEST','label'=>'Synthetic account')),
			'thirdparties'=>array((object)array('rowid'=>201,'nom'=>'Synthetic bank services')),'categories'=>array((object)array('rowid'=>301,'label'=>'Bank fees')),
			'accounting_accounts'=>array((object)array('rowid'=>401,'account_number'=>'6221','label'=>'Bank service charges')),'payment_modes'=>array((object)array('code'=>'PRE','libelle'=>'Direct debit')));}
	};
	$canWrite=empty($options['readonly']);$action='edit';$editId=1;$preview=null;$failed=null;
	$db=new class {public function close(){}};
	$source=file_get_contents(__DIR__.'/../admin/rules.php');$start=strpos($source,"llxHeader('',");
	if($start===false){throw new RuntimeException('Shipped rule configuration view unavailable');}
	ob_start();eval(substr($source,$start));return ob_get_clean();
}

if(($argv[1]??'')==='--html'){print renderRulesView($argv[2]??'admin',$argv[3]??'en_US');exit;}
$checks=0;
$check=static function($ok,$label)use(&$checks){if(!$ok){throw new RuntimeException($label);}$checks++;};
$parse=static function($html){$dom=new DOMDocument();$previous=libxml_use_internal_errors(true);$dom->loadHTML($html);libxml_clear_errors();libxml_use_internal_errors($previous);return new DOMXPath($dom);};
foreach(array('en_US','pt_PT') as $locale){
	$langs=new RulesViewLangs($locale);$admin=renderRulesView('admin',$locale);$xpath=$parse($admin);
	$reference=new RulesViewLangs('en_US');foreach($reference->messages as $key=>$value){if(strpos($key,'KreaBankRule')===0){$check(isset($langs->messages[$key])&&$langs->messages[$key]!=='','Complete rule translation '.$key.' '.$locale);}}
	$check(strpos($admin,$langs->trans('KreaBankRules'))!==false,'Configuration heading translated '.$locale);
	$check($xpath->query('//*[contains(concat(" ",normalize-space(@class)," ")," error ")]')->length===0,'Configuration renders without a swallowed preparation error '.$locale);
	$check($xpath->query('//select[@id="rule_action"]/option[@selected and @value="quick_entry"]')->length===1,'Stored action selection '.$locale);
	$check($xpath->query('//select[@id="payment_mode"]/option[@selected and @value="PRE"]')->length===1,'Explicit native payment mode selection '.$locale);
	$check($xpath->query('//select[@id="accounting_account"]/option[@selected and @value="6221"]')->length===1,'Stored accounting hint selection '.$locale);
	$check($xpath->query('//input[@id="vat" and @value="23"]')->length===1,'Stored VAT hint '.$locale);
	$check($xpath->query('//select[@id="condition-all-0"]/option[@selected and @value="description|contains"]')->length===1,'Stored condition selection '.$locale);
	$check($xpath->query('//select[@id="condition-all-1"]/option[@selected and @value="amount|between"]')->length===1,'Stored interval selection '.$locale);
	$check($xpath->query('//form[.//input[@name="action" and @value="save"]]//input[@name="revision" and @value="'.str_repeat('a',64).'"]')->length===1,'Configuration revision bound to save '.$locale);
	$depth=0;preg_match_all('~<form\b[^>]*>|</form>~i',$admin,$tags);foreach($tags[0] as $tag){$depth+=stripos($tag,'</')===0?-1:1;$check($depth>=0&&$depth<=1,'No nested forms '.$locale);}$check($depth===0,'All configuration forms closed '.$locale);
	$check($xpath->query('//label[@for="rule_action"]')->length===1 && $xpath->query('//label[@for="vat"]')->length===1,'Action controls have labels '.$locale);
	$quick=renderRulesView('quick',$locale);$quickXPath=$parse($quick);
	$check(strpos($quick,$langs->trans('KreaBankRuleEvidence','Monthly bank fees'))!==false,'Rule evidence uses translated label '.$locale);
	$check(strpos($quick,'General service fees')!==false&&strpos($quick,$langs->trans('KreaBankRuleAccountingHints','6221',kreabankPrice('23')))!==false,'Conflict and review-only hints rendered '.$locale);
	$check($quickXPath->query('//button[@form="synthetic-rule-review" and @type="submit"]')->length===1,'Quick card action targets associated signed form '.$locale);
	$check($quickXPath->query('//form[@id="synthetic-rule-review"]//input[@name="review_doc" and @value="quick_entry__0"]')->length===1,'Quick review document identity explicit '.$locale);
	$unsafe=renderRulesView('quick',$locale,array('malicious'=>true));$unsafeXPath=$parse($unsafe);
	$check($unsafeXPath->query('//img | //script[not(contains(.,"Synthetic submission"))]')->length===0,'Quick labels/counterparties/conflicts escaped '.$locale);
	$unsafeAdmin=renderRulesView('admin',$locale,array('malicious'=>true));$unsafeAdminXPath=$parse($unsafeAdmin);
	$check($unsafeAdminXPath->query('//img | //script[contains(.,"alert(1)")]')->length===0,'Configuration labels and input values escaped '.$locale);
	$readonly=$parse(renderRulesView('admin',$locale,array('readonly'=>true)));
	$check($readonly->query('//form//input[@name="action" and @value!="preview"]')->length===0,'Read-only users receive preview forms only '.$locale);
	$missing=renderRulesView('admin',$locale,array('missing'=>true));$check(strpos($missing,$langs->trans('KreaBankRulesInstall'))!==false&&$parse($missing)->query('//form')->length===0,'Missing schema notice offers no mutation '.$locale);
}
echo 'OK: '.$checks." synthetic bank-rule view checks\n";
