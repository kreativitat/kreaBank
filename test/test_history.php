#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Render the actual History controller with synthetic read-only dependencies.
 * No ERP bootstrap, credentials or database connection.
 */
error_reporting(E_ALL);
set_error_handler(static function($level,$message){throw new RuntimeException($message);});
define('DOL_DOCUMENT_ROOT',getenv('KREABANK_DOLIBARR_ROOT') ?: dirname(__DIR__,3));
if (in_array('--native', $argv ?? array(), true)) {
	require_once DOL_DOCUMENT_ROOT . '/core/lib/functions.lib.php';
	require_once DOL_DOCUMENT_ROOT . '/core/class/translate.class.php';
	require_once __DIR__ . '/../lib/kreabank_history.lib.php';
	$conf = (object) array('global'=>new stdClass(),'currency'=>'EUR');
	$checks = 0;
	$check = static function ($condition, $message) use (&$checks) { if (!$condition) { throw new RuntimeException($message); } $checks++; };
	foreach (array('en_US','pt_PT') as $locale) {
		$langs = new Translate(DOL_DOCUMENT_ROOT,$conf); $langs->defaultlang = $locale;
		$langs->dir = array(dirname(__DIR__),DOL_DOCUMENT_ROOT);
		foreach (array('main','banks','bills','companies','salaries','compta','trips','donations','loan','kreabank') as $domain) { $langs->load($domain,2,0,$locale,1); }
		foreach (array('payment','payment_linked','payment_supplier','payment_supplier_linked','payment_salary','payment_sc','payment_tax','payment_various','payment_expensereport','payment_donation','payment_loan','payment_withdraw','banktransfert','company','user','bank_line','native_bank','customer_invoice','supplier_invoice','tax','sc','quick_entry') as $code) {
			$label = kreabankHistoryCodeLabel($code,'document',$langs);
			$check($label !== $code && strpos($label,'KreaBank') === false, 'Known document type uses installed translations: ' . $locale . '/' . $code);
		}
		foreach (array('native','legacy_staging','manual','drag_drop','bulk_manual','auto_native','auto_safe','auto_pair','auto_tpa','manual_payment_batch','manual_worklist_movement','quick_entry','quick_tax_entry','quick_supplier_invoice') as $code) {
			$label = kreabankHistoryCodeLabel($code,'strategy',$langs);
			$check($label !== $code && strpos($label,'KreaBank') === false, 'Known reconciliation strategy uses installed translations: ' . $locale . '/' . $code);
		}
		foreach (array('import','reconcile','undo','quick_entry','quick_tax_entry','quick_supplier_invoice','batch_approve','feed_sync','skip','delete_statement','account_correction','invoice_validation','statement_evidence','audit_purge','trigger_event') as $code) {
			$label = kreabankHistoryCodeLabel($code,'action',$langs);
			$check($label !== '' && strpos($label,'KreaBank') === false && strpos($label,'_') === false, 'Known audit action uses an installed plain label: ' . $locale . '/' . $code);
		}
		foreach (array('(banktransfert)'=>'BankTransfer','(paiement)'=>'Payment','(payment)'=>'Payment','(Various_Payment)'=>'VariousPayment','(SalaryPayment)'=>'SalaryPayment') as $marker=>$key) {
			$check(kreabankHistoryNativeLabel($marker,$langs) === $langs->transnoentities($key), 'Reserved native reference marker is translated: ' . $locale . '/' . $marker);
		}
		$source = 'Supplier (paiement) department &amp; Filhos';
		$check(kreabankHistoryNativeLabel($source,$langs) === $source && kreabankHistoryCodeLabel('custom_connector','action',$langs) === 'custom_connector', 'Custom source prose and unknown codes remain literal in ' . $locale);
		$check(kreabankHistoryNativeLabel('REF-001 / (banktransfert) / Ação &amp; Filhos',$langs) === 'REF-001 / ' . $langs->transnoentities('BankTransfer') . ' / Ação &amp; Filhos', 'Composite native labels preserve references and literal source entities in ' . $locale);
		if ($locale === 'pt_PT') {
			$check(kreabankHistoryCodeLabel('bulk_manual','strategy',$langs) === 'Reconciliação revista' && kreabankWorklistTranslate($langs,'KreaBankHistoryScore') === 'Pontuação', 'Portuguese history captions retain accented plain text without double encoding');
		}
	}
	echo 'OK: ' . $checks . " native History translation checks\n";
} else {
require_once __DIR__ . '/../lib/kreabank_history.lib.php';
require_once __DIR__ . '/../lib/kreabank_list.lib.php';
require_once __DIR__ . '/../class/KreaBankNativeBankAdapter.class.php';
function GETPOST($key,$type=''){return $_POST[$key] ?? $_GET[$key] ?? '';}
function GETPOSTINT($key){return (int)GETPOST($key);}
function GETPOSTISSET($key){return isset($_GET[$key]) || isset($_POST[$key]);}
function dol_escape_htmltag($value){return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');}
function dol_buildpath($path,$mode=0){return $path;}
function dol_print_date($value,$format){return gmdate('d/m/Y H:i',$value);}
function kreabankPrice($value){return number_format((float)$value,2,',',' ');}
function kreabankNormalizeText($value){return strtolower(iconv('UTF-8','ASCII//TRANSLIT',(string)$value));}
function isModEnabled($name){return true;}
function newToken(){return 'fixture-token';}
function kreabankIsTokenValid(){return GETPOST('token')==='fixture-token';}
function accessforbidden(...$args){throw new DomainException('Forbidden');}
function kreabankNavigationAccount($id){if($id && !in_array($id,array(101,102),true)){accessforbidden();}return null;}
function kreabankNavigationHiddenFields($id){return '<input type="hidden" name="account_context" value="'.$id.'">';}
function kreabankPrintWorkspaceNavigation($view,$account){print '<nav>Banking · History</nav>';}
function load_fiche_titre($title,...$args){return '<h1>'.$title.'</h1>';}
function llxHeader(...$args){print '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head><body>';}
function dol_get_fiche_end(){return '';}
function llxFooter(){print '</body></html>';}
function print_barre_liste($title,$page,$url,$params,$a,$b,$c,$num,$total,...$args){print ($args[2] ?? '');print '<div class="fixture-pager" data-page="'.$page.'" data-total="'.$total.'">'.$title.' ('.$total.')</div>';}
function getTitleFieldOfList($label,...$args){return '<th class="liste_titre">'.$label.'</th>';}
class Form {public function __construct($db){} public function showFilterButtons(){return '<button name="button_search_x" value="x">Search</button><button name="button_removefilter_x" value="x">Reset</button>';}}
function setEventMessages($message,...$args){$GLOBALS['fixtureErrors'][]=$message;}
class HistoryFixtureLangs {
	public $keys=array();
	public function __construct(){foreach(file(__DIR__.'/../langs/pt_PT/kreabank.lang') as $line){if(strpos($line,'=')!==false){list($k,$v)=explode('=',trim($line),2);$this->keys[$k]=$v;}}}
	public function loadLangs($files){foreach($files as $domain){$path=strpos($domain,'@')!==false ? __DIR__.'/../langs/pt_PT/'.explode('@',$domain)[0].'.lang' : DOL_DOCUMENT_ROOT.'/langs/pt_PT/'.$domain.'.lang';if(!is_file($path)){continue;}foreach(file($path) as $line){if(strpos($line,'=')!==false && substr(ltrim($line),0,1)!=='#'){list($k,$v)=explode('=',trim($line),2);$this->keys[$k]=$v;}}}}
	public function trans($key,...$values){$text=$this->keys[$key]??$key;foreach($values as $value){$text=preg_replace('/%s/',(string)$value,$text,1);}return $text;}
	public function transnoentities($key,...$values){return $this->trans($key,...$values);}
}
class HistoryFixtureResult {public $rows;public function __construct($rows){$this->rows=$rows;}}
class HistoryFixtureDb {
	public $queries=array();
	public function prefix(){return 'fixture_';}
	public function escape($v){return addslashes($v);}
	public function plimit($limit,$offset=0){return ' LIMIT '.$offset.','.$limit;}
	public function close(){}
	public function fetch_object($r){$row=array_shift($r->rows);return $row===null?false:(object)$row;}
	public function query($sql){
		$this->queries[]=$sql;
		if(!preg_match('/^(SELECT|SHOW) /',$sql)){throw new RuntimeException('History attempted a write');}
		if(strpos($sql,'information_schema.tables')!==false){return new HistoryFixtureResult(array(array('nb'=>0)));}
		if(strpos($sql,'SELECT h.* FROM (')===0){
			$rows=$GLOBALS['fixtureRows'];
			if(preg_match('/AND ba.rowid=(\d+)/',$sql,$m)){$rows=array_values(array_filter($rows,static function($r)use($m){return (int)$r['bank_account_id']===(int)$m[1];}));}
			return new HistoryFixtureResult($rows);
		}
		if(strpos($sql,'SELECT a.*,u.login')===0){return new HistoryFixtureResult($GLOBALS['fixtureAudits']);}
		if(strpos($sql,'SELECT fk_bank,')===0){return new HistoryFixtureResult($GLOBALS['fixtureLinks']);}
		if(strpos($sql,'SELECT rowid, dateo')===0){return new HistoryFixtureResult(array(array('rowid'=>6001,'dateo'=>'2026-11-10')));}
		return new HistoryFixtureResult(array());
	}
}
$langs=new HistoryFixtureLangs();
$user=new class {public $id=7;public $socid=0;public $allowed=true;public function hasRight(...$args){return $this->allowed;}};
$conf=(object)array('entity'=>1,'currency'=>'EUR','liste_limit'=>25);
$base=array('rowid'=>5001,'source_storage'=>'staging','native_id'=>6001,'expected_native_id'=>6001,'native_amount'=>'125.00000000','amount'=>'125.00000000','currency'=>'EUR','native_currency'=>'EUR','status'=>2,'rappro'=>1,'statement_ref'=>'ST-5001','num_releve'=>'ST-5001','operation_date'=>'2026-11-10','description'=>'Synthetic staged receipt','bank_account_id'=>101,'group_id'=>0,'group_valid'=>0,'is_duplicate'=>0);
$fixtureRows=array($base,array_merge($base,array('source_storage'=>'legacy_native','native_id'=>5001,'expected_native_id'=>5001,'native_amount'=>'17.00000000','amount'=>'17.00000000','currency'=>'USD','native_currency'=>'USD','description'=>'Synthetic native receipt','bank_account_id'=>102)),array_merge($base,array('rowid'=>5002,'native_id'=>6002,'expected_native_id'=>6002,'native_amount'=>'-20.00000000','amount'=>'-20.00000000','description'=>'Synthetic debit')));
$fixtureAudits=array(array('rowid'=>9,'audit_type'=>'reconcile','fk_statement_line'=>5001,'payload_json'=>'{"strategy":"auto_native","source":"staged","native_bank_line_id":6001,"marker":"JSON_ONLY"}','login'=>'Synthetic tester','datec'=>'2026-11-14 12:00:00'));
$fixtureLinks=array(array('fk_bank'=>6001,'url_id'=>77,'url'=>'/compta/paiement/card.php?id=','label'=>'Test payment','type'=>'payment'));
$_SESSION=array();
function renderHistory($request=array(),$method='GET'){
	global $db,$conf,$langs,$user,$fixtureErrors;
	$_GET=$method==='GET'?$request:array();$_POST=$method==='POST'?$request:array();
	$_SERVER=array('REQUEST_METHOD'=>$method,'PHP_SELF'=>'/custom/kreabank/history.php','HTTP_HOST'=>'fin.example.test');
	$db=new HistoryFixtureDb();$fixtureErrors=array();
	$code=file_get_contents(__DIR__.'/../history.php');$code=substr($code,strpos($code,"\$langs->loadLangs("));
	ob_start();try{eval($code);return ob_get_clean();}catch(Throwable $e){ob_end_clean();throw $e;}
}
$checks=0;
function historyCheck($ok,$label){global $checks;if(!$ok){throw new RuntimeException($label);} $checks++;}
$html=renderHistory();
historyCheck(!$fixtureErrors && preg_match_all('/<tr\b[^>]*class="[^"]*\bkrea-history-row-top\b/', $html)===3,'History renders actual rows without errors');
historyCheck(strpos($html,'125,00 EUR')!==false && strpos($html,'17,00 USD')!==false && strpos($html,'142,00')===false,'Summary amounts remain separated by currency');
historyCheck(strpos($html,'name="undo_review"')!==false && strpos($html,'id="kreaUndoFormstaged5001"')!==false && strpos($html,'id="kreaUndoFormnative5001"')!==false,'Colliding IDs have separate displayed Undo reviews/forms');
historyCheck(strpos($html,'min-width:600px')===false,'Global search can shrink on narrow screens');
$html=renderHistory(array('hist_audit_type'=>'auto_native'));
historyCheck(strpos($html,'Synthetic staged receipt')!==false && strpos($html,'Synthetic native receipt')===false && strpos($html,'Synthetic debit')===false,'Type filters History by its displayed reconciliation strategy');
$html=renderHistory(array('hist_description'=>'debit','hist_direction'=>'debit','hist_date_to'=>'2026-11-11'));
historyCheck(strpos($html,'Synthetic debit')!==false && strpos($html,'Synthetic staged receipt')===false,'Description, direction and event-date filters combine');
$html=renderHistory(array('hist_link_type'=>'payment'));
historyCheck(strpos($html,'Synthetic staged receipt')!==false && strpos($html,'Synthetic native receipt')===false,'Document-type filter uses the exact mapped native documents');
$html=renderHistory(array('hist_search'=>'JSON_ONLY'));
historyCheck(strpos($html,'Synthetic tester')!==false,'Global audit search includes promised JSON evidence');
$html=renderHistory(array('audit_search_action'=>'reconciliacao'));
historyCheck(strpos($html,'Synthetic tester')!==false,'Audit action filter accepts the visible translated action');
$html=renderHistory(array('audit_search_user'=>'absent'));
historyCheck(strpos($html,'Synthetic staged receipt')!==false && strpos($html,'data-total="0"')!==false,'Audit-only filters do not remove History rows');
$html=renderHistory(array('account_context'=>101));
historyCheck(strpos($html,'Synthetic native receipt')===false && strpos($html,'kreaAuditFilterForm')===false && strpos($html,'Synthetic tester')!==false,'Account view retains scoped event metadata without the company-wide audit table');
$html=renderHistory(array('page'=>1,'limit'=>1,'audit_page'=>2));
historyCheck(strpos($html,'data-page="1" data-total="3"')!==false && strpos($html,'Synthetic staged receipt')===false,'History paginates the complete sorted source set');
foreach(array(array('account_context'=>202),array('action'=>'undo')) as $request){$caught=false;try{renderHistory($request);}catch(DomainException $e){$caught=true;}historyCheck($caught,'Foreign scope or GET Undo is denied');}
$caught=false;try{renderHistory(array('action'=>'undo'),'POST');}catch(DomainException $e){$caught=true;}historyCheck($caught,'POST Undo requires a current CSRF token');
$_SESSION=array();$tokens=array();
for($i=0;$i<1000;$i++){$tokens[]=kreabankHistoryUndoReview(array_merge($base,array('rowid'=>$i+1)));}
historyCheck(kreabankHistoryTakeUndoReview($tokens[0],101)['rowid']==='1' && kreabankHistoryTakeUndoReview($tokens[999],101)['rowid']==='1000','Maximum-size page retains first and last Undo reviews');
$caught=false;try{kreabankHistoryTakeUndoReview($tokens[0],101);}catch(DomainException $e){$caught=true;}historyCheck($caught,'Displayed Undo reviews are single-use');
foreach(array('javascript:alert(1)','//foreign.test/path?id=','https://foreign.test/path?id=','/\\foreign.test/path?id=') as $prefix){historyCheck(kreabankHistoryDocumentUrl($prefix,7,'fin.example.test')==='','Unsafe document destination is rejected');}
historyCheck(kreabankHistoryDocumentUrl('/compta/paiement/card.php?id=',7,'fin.example.test')==='/compta/paiement/card.php?id=7','Native document URLs remain available');

foreach (array('>124,99999999'=>true,'=125.00000001'=>false,'<=125'=>true,'<>125'=>false,'1 OR 1=1'=>false) as $filter=>$expected) {
	historyCheck(kreabankHistoryAmountMatches('125.00000000',$filter)===$expected, 'Exact signed amount comparisons do not round: '.$filter);
}
$html=renderHistory(array('hist_amount'=>'<-1'));
historyCheck(strpos($html,'Synthetic debit')!==false && strpos($html,'Synthetic staged receipt')===false,'Amount header filters before History pagination');
$html=renderHistory(array('hist_amount'=>'<>125'));
historyCheck(strpos($html,'Synthetic staged receipt')===false && strpos($html,'Synthetic debit')!==false,'Not-equal amount input retains its comparison operator');
$html=renderHistory(array('hist_status'=>'review'));
historyCheck(preg_match('/<tr[^>]*class="[^"]*krea-history-row-top/', $html)===0,'Status header follows displayed review state');
$html=renderHistory(array('hist_date_from'=>'2026-02-30'));
historyCheck(preg_match('/<tr[^>]*class="[^"]*krea-history-row-top/', $html)===0,'Invalid dates fail closed instead of silently expanding History');
$html=renderHistory(array('pageplusone'=>2,'pageplusoneold'=>2,'limit'=>1,'button_search_x'=>'x'));
historyCheck(strpos($html,'data-page="0" data-total="3"')!==false,'Header search resets the active list page');
$html=renderHistory(array('pageplusone'=>2,'pageplusoneold'=>1,'limit'=>1,'button_search_x'=>'x'));
historyCheck(strpos($html,'data-page="1" data-total="3"')!==false,'Native page jump wins over implicit search submit');
$html=renderHistory(array('hist_amount'=>'<-1','hist_status'=>'review','audit_search_user'=>'absent','button_removefilter.x'=>'x'));
historyCheck(strpos($html,'data-total="3"')!==false && strpos($html,'data-total="0"')!==false,'History reset clears its header filters while retaining audit filters');
$html=renderHistory(array('hist_description'=>'debit','audit_search_user'=>'absent','audit_nav'=>'1','button_removefilter_x'=>'x'));
historyCheck(strpos($html,'Synthetic debit')!==false && strpos($html,'Synthetic staged receipt')===false && strpos($html,'Synthetic tester')!==false,'Audit reset preserves History filters');
$html=renderHistory(array('hist_amount'=>'>=0','hist_status'=>'reconciled','limit'=>1));
$previous=libxml_use_internal_errors(true);$dom=new DOMDocument();$dom->loadHTML($html);libxml_clear_errors();libxml_use_internal_errors($previous);$xp=new DOMXPath($dom);
historyCheck($xp->query('//form//form')->length===0 && $xp->query('//form[@method="POST"]')->length===1,'Native GET owners never nest or absorb Undo POST forms');
historyCheck($xp->query('//input[@name="hist_amount" and @form="kreaHistoryFilterForm"]')->length===1 && $xp->query('//select[@name="hist_status" and @form="kreaHistoryFilterForm"]')->length===1,'Amount and Status are associated native header controls');
historyCheck($xp->query('//button[@form="kreaHistoryFilterForm"]')->length===2 && $xp->query('//button[@form="kreaAuditFilterForm"]')->length===2,'Native search/reset controls target their own read-only form');
historyCheck($xp->query('//form[@id="kreaAuditFilterForm"]/input[@name="limit" and @value="1"]')->length===1,'Audit filters retain the shared native page size when their duplicate selector is hidden');
$sortLinks=$xp->query('//table[@id="kreaAuditTable"]//th/a');
foreach($sortLinks as $link){parse_str(parse_url($link->getAttribute('href'),PHP_URL_QUERY),$query);historyCheck(isset($query['audit_sortfield']) && ($query['sortfield']??'')==='event_ts' && ($query['hist_amount']??'')==='>=0' && !isset($query['token'],$query['action']),'Audit sort links preserve History sort/filter and exclude actions');}

// The actual controller translates reserved labels only, never persisted evidence.
$payload = array('strategy'=>'bulk_manual','source'=>'staged','native_bank_line_id'=>6001,'confidence_score'=>100,
	'links'=>array(array('doc_type'=>'payment_supplier','allocated_amount'=>'125.00000000')),
	'reason'=>'Original explanation (paiement) must stay literal','quick_entry_id'=>77,'marker'=>'RAW_JSON_EVIDENCE');
$fixtureAudits = array(array('rowid'=>9,'audit_type'=>'reconcile','fk_statement_line'=>5001,'payload_json'=>json_encode($payload),'login'=>'Synthetic tester','datec'=>'2026-11-14 12:00:00'),
	array('rowid'=>10,'audit_type'=>'account_correction','fk_statement_line'=>0,'payload_json'=>'{"reason":"Original operator note"}','login'=>'Correction operator','datec'=>'2026-11-14 11:00:00'),
	array('rowid'=>11,'audit_type'=>'custom_event','fk_statement_line'=>0,'payload_json'=>'INVALID_JSON raw strategy=bulk_manual','login'=>'Raw audit operator','datec'=>'2026-11-14 10:00:00'));
$fixtureLinks = array();
foreach (array('payment_supplier'=>'SUP-REF-77','company'=>'DeGema &amp; Filhos','banktransfert'=>'(banktransfert)','payment_salary'=>'Salary REF-88','user'=>'Human (paiement) department','bank_line'=>'Native REF-99','custom_connector'=>'Literal <script>source</script>') as $type=>$reference) {
	$fixtureLinks[] = array('fk_bank'=>6001,'url_id'=>77,'url'=>'/compta/paiement/card.php?id=','label'=>$reference,'type'=>$type);
}
$original = serialize(array($fixtureRows,$fixtureLinks,$fixtureAudits));
$html = renderHistory();
$previous=libxml_use_internal_errors(true);$dom=new DOMDocument();$dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);libxml_clear_errors();libxml_use_internal_errors($previous);$xp=new DOMXPath($dom);
$badges = array(); foreach ($xp->query('//span[@class="krea-history-link"]') as $badge) { $badges[]=$badge->textContent; }
foreach (array('payment_supplier','company','banktransfert','payment_salary','user','bank_line') as $type) {
	historyCheck(in_array(kreabankHistoryCodeLabel($type,'document',$langs),$badges,true) && !in_array(str_replace('_',' ',$type),$badges,true), 'Known associated-document badges translate the screenshot label: '.$type);
}
historyCheck(strpos($html,'Pontuação 100')!==false && strpos($html,'Score 100')===false && strpos($html,'Reconciliação revista')!==false, 'Type badges and score captions use translated text');
historyCheck($xp->query('//select[@name="hist_audit_type"]/option[@value="bulk_manual"]')->item(0)->textContent==='Reconciliação revista'
	&& $xp->query('//select[@name="hist_link_type"]/option[@value="payment_supplier"]')->item(0)->textContent===$langs->trans('SupplierInvoicePayment'), 'Filter options display the same translated labels and retain raw query IDs');
historyCheck($xp->query('//a[text()="'.$langs->trans('BankTransfer').'"]')->length===1, 'Native transfer placeholder references use the installed bank translation');
historyCheck(strpos($html,'Human (paiement) department')!==false && strpos($html,'Original explanation (paiement) must stay literal')!==false
	&& strpos($html,'DeGema &amp;amp; Filhos')!==false, 'Actual names, literal entities and operator explanations remain source evidence');
historyCheck(strpos($html,'<script>source</script>')===false && strpos($html,'&lt;script&gt;source&lt;/script&gt;')!==false, 'Untrusted document references stay escaped after translation');
historyCheck(strpos($html,'custom_connector')!==false && strpos($html,'custom_event')!==false && strpos($html,'INVALID_JSON raw strategy=bulk_manual')!==false, 'Unknown integration codes and malformed raw JSON are never guessed or masked');
historyCheck(serialize(array($fixtureRows,$fixtureLinks,$fixtureAudits))===$original, 'Rendering does not rewrite source records, links or original audit JSON');
foreach (array('pagamento de fornecedor','payment_supplier','reconciliacao revista','bulk_manual') as $query) {
	$filteredHtml=renderHistory(array('hist_search'=>$query));
	historyCheck(strpos($filteredHtml,'Synthetic staged receipt')!==false && strpos($filteredHtml,'Synthetic native receipt')===false, 'History search accepts displayed labels and retained raw codes: '.$query);
}
$filteredHtml=renderHistory(array('audit_search_action'=>'correcao'));
historyCheck(strpos($filteredHtml,'Correction operator')!==false && strpos($filteredHtml,'Raw audit operator')===false, 'Audit action filters match translated event labels');
foreach (array('reconciliacao revista','bulk_manual') as $query) {
	$filteredHtml=renderHistory(array('audit_search_summary'=>$query));
	historyCheck(strpos($filteredHtml,'Synthetic tester')!==false && strpos($filteredHtml,'Correction operator')===false, 'Audit summary filters retain translated and raw strategy search: '.$query);
}
$filteredHtml=renderHistory(array('sortfield'=>'strategy','sortorder'=>'ASC','limit'=>1));
historyCheck(strpos($filteredHtml,'Synthetic staged receipt')===false, 'History sorts translated type labels before pagination');
$filteredHtml=renderHistory(array('audit_sortfield'=>'audit_type','audit_sortorder'=>'ASC','limit'=>1));
$previous=libxml_use_internal_errors(true);$dom->loadHTML('<?xml encoding="utf-8" ?>'.$filteredHtml);libxml_clear_errors();libxml_use_internal_errors($previous);$xp=new DOMXPath($dom);
$auditText=$xp->query('//table[@id="kreaAuditTable"]')->item(0)->textContent;
historyCheck(strpos($auditText,'Correction operator')!==false && strpos($auditText,'Synthetic tester')===false, 'Audit sorts its displayed translated action labels before pagination');
$fixtureRows[0]['description']='(banktransfert)';
$filteredHtml=renderHistory(array('hist_description'=>'transferencia de credito'));
historyCheck(strpos($filteredHtml,$langs->trans('BankTransfer'))!==false && strpos($filteredHtml,'Synthetic native receipt')===false, 'Description filtering follows translated reserved native markers');
echo "OK: $checks History controller/view checks\n";
}
