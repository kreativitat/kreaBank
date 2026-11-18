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
		foreach (array('native','legacy_staging','manual','drag_drop','bulk_manual','auto_native','auto_safe','auto_pair','auto_transfer','auto_tpa','manual_payment_batch','manual_worklist_movement','manual_uber','quick_entry','quick_tax_entry','quick_supplier_invoice') as $code) {
			$label = kreabankHistoryCodeLabel($code,'strategy',$langs);
			$check($label !== $code && strpos($label,'KreaBank') === false, 'Known reconciliation strategy uses installed translations: ' . $locale . '/' . $code);
		}
		foreach (array('import','reconcile','undo','quick_entry','quick_tax_entry','quick_supplier_invoice','batch_approve','feed_sync','skip','delete_statement','account_correction','invoice_validation','statement_evidence','audit_purge','trigger_event') as $code) {
			$label = kreabankHistoryCodeLabel($code,'action',$langs);
			$check($label !== '' && strpos($label,'KreaBank') === false && strpos($label,'_') === false, 'Known audit action uses an installed plain label: ' . $locale . '/' . $code);
		}
		foreach (array('(banktransfert)'=>'BankTransfer','(paiement)'=>'Payment','(payment)'=>'Payment','(Various_Payment)'=>'VariousPayment','(SalaryPayment)'=>'SalaryPayment') as $marker=>$key) {
			$check(kreabankHistoryNativeLabel($marker,$langs) === $langs->transnoentities($key), 'Reserved native reference marker is translated: ' . $locale . '/' . $marker);
			$check(kreabankHistoryReferenceLabel($marker,77,$langs) === '#77', 'Generic native markers retain a useful document ID beside the translated type: ' . $locale . '/' . $marker);
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
		if(strpos($sql,'SELECT g.rowid batch_id,b.rowid FROM ')===0){return new HistoryFixtureResult($GLOBALS['fixtureGroupMembers'] ?? array());}
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
function historyXPath($html){$previous=libxml_use_internal_errors(true);$dom=new DOMDocument();$dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);libxml_clear_errors();libxml_use_internal_errors($previous);return new DOMXPath($dom);}
$html=renderHistory();
historyCheck(!$fixtureErrors && preg_match_all('/<tr\b[^>]*class="[^"]*\bkrea-history-row-top\b/', $html)===3,'History renders actual rows without errors');
historyCheck(strpos($html,'125,00 EUR')!==false && strpos($html,'17,00 USD')!==false && strpos($html,'142,00')===false,'Summary amounts remain separated by currency');
historyCheck(strpos($html,'name="undo_review"')!==false && strpos($html,'id="kreaUndoFormstaged5001"')!==false && strpos($html,'id="kreaUndoFormnative5001"')!==false,'Colliding IDs have separate displayed Undo reviews/forms');
historyCheck(strpos($html,'min-width:600px')===false,'Global search can shrink on narrow screens');
$baseStatusXp=historyXPath($html);
historyCheck($baseStatusXp->query('//nav[contains(@class,"kb-history-status-tabs")]/a')->length===2 && $baseStatusXp->query('//nav[contains(@class,"kb-history-status-tabs")]/a/span[text()="(3)"]')->length===2,'Only ordinary states remain when the scoped History has no exceptions; both counts cover all rows');
$selectedEmptyXp=historyXPath(renderHistory(array('hist_status'=>'review')));
historyCheck($selectedEmptyXp->query('//nav[contains(@class,"kb-history-status-tabs")]/a')->length===3 && $selectedEmptyXp->query('//nav[contains(@class,"kb-history-status-tabs")]/a[@aria-current="page"]/span[text()="(0)"]')->length===1,'A direct empty exceptional-state URL retains its active tab and explicit zero count');
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
historyCheck(preg_match('/<tr[^>]*class="[^"]*krea-history-row-top/', $html)===0,'Status tab follows displayed review state');
$html=renderHistory(array('hist_date_from'=>'2026-02-30'));
historyCheck(preg_match('/<tr[^>]*class="[^"]*krea-history-row-top/', $html)===0,'Invalid dates fail closed instead of silently expanding History');
$html=renderHistory(array('pageplusone'=>2,'pageplusoneold'=>2,'limit'=>1,'button_search_x'=>'x'));
historyCheck(strpos($html,'data-page="0" data-total="3"')!==false,'Header search resets the active list page');
$html=renderHistory(array('pageplusone'=>2,'pageplusoneold'=>1,'limit'=>1,'button_search_x'=>'x'));
historyCheck(strpos($html,'data-page="1" data-total="3"')!==false,'Native page jump wins over implicit search submit');
$html=renderHistory(array('hist_amount'=>'<-1','hist_status'=>'review','audit_search_user'=>'absent','button_removefilter.x'=>'x'));
historyCheck(strpos($html,'data-total="3"')===false && strpos($html,'data-total="0"')!==false && strpos($html,'name="hist_status" value="review"')!==false,'History reset clears its header filters while retaining the selected status tab and audit filters');
$html=renderHistory(array('hist_description'=>'debit','audit_search_user'=>'absent','audit_nav'=>'1','button_removefilter_x'=>'x'));
historyCheck(strpos($html,'Synthetic debit')!==false && strpos($html,'Synthetic staged receipt')===false && strpos($html,'Synthetic tester')!==false,'Audit reset preserves History filters');
$html=renderHistory(array('hist_amount'=>'>=0','hist_status'=>'reconciled','limit'=>1));
$previous=libxml_use_internal_errors(true);$dom=new DOMDocument();$dom->loadHTML($html);libxml_clear_errors();libxml_use_internal_errors($previous);$xp=new DOMXPath($dom);
historyCheck($xp->query('//form//form')->length===0 && $xp->query('//form[@method="POST"]')->length===1,'Native GET owners never nest or absorb Undo POST forms');
historyCheck($xp->query('//input[@name="hist_amount" and @form="kreaHistoryFilterForm"]')->length===1 && $xp->query('//form[@id="kreaHistoryFilterForm"]/input[@type="hidden" and @name="hist_status" and @value="reconciled"]')->length===1 && $xp->query('//select[@name="hist_status"]')->length===0,'Amount retains its native header control while exactly one hidden status preserves the active tab');
historyCheck($xp->query('//button[@form="kreaHistoryFilterForm"]')->length===2 && $xp->query('//button[@form="kreaAuditFilterForm"]')->length===2,'Native search/reset controls target their own read-only form');
historyCheck($xp->query('//form[@id="kreaAuditFilterForm"]/input[@name="limit" and @value="1"]')->length===1,'Audit filters retain the shared native page size when their duplicate selector is hidden');
$sortLinks=$xp->query('//table[@id="kreaAuditTable"]//th/a');
foreach($sortLinks as $link){parse_str(parse_url($link->getAttribute('href'),PHP_URL_QUERY),$query);historyCheck(isset($query['audit_sortfield']) && ($query['sortfield']??'')==='event_ts' && ($query['hist_amount']??'')==='>=0' && !isset($query['token'],$query['action']),'Audit sort links preserve History sort/filter and exclude actions');}
historyCheck($xp->query('//form[@method="POST"]/input[@name="hist_status" and @value="reconciled"]')->length===1,'Signed Undo forms retain the active status tab');
historyCheck($xp->query('//table[@id="kreaHistoryTable"]/tr[contains(@class,"liste_titre")]/th')->length===6,'History has six headings after removing the Status column');
foreach($xp->query('//table[@id="kreaHistoryTable"]/tr') as $row){historyCheck($xp->query('./td|./th',$row)->length===6,'History filters, headings and rendered rows retain six-column alignment');}
$savedRows=$fixtureRows;$savedAudits=$fixtureAudits;
$fixtureRows[0]['rappro']=0;$fixtureRows[0]['is_reversed']=1;
$fixtureRows[1]['is_reversed']=1;
foreach(array('all'=>3,'reconciled'=>1,'review'=>1,'reversed'=>1,'unsupported'=>0) as $status=>$expectedRows){
	$statusHtml=renderHistory(array('hist_status'=>$status));
	$statusXp=historyXPath($statusHtml);
	historyCheck($statusXp->query('//table[@id="kreaHistoryTable"]/tr[contains(@class,"krea-history-row-top")]')->length===$expectedRows,'Status tabs retain the current derived-state priority and invalid values fail closed: '.$status);
	historyCheck($statusXp->query('//nav[contains(@class,"kb-history-status-tabs")]/a')->length===4 && $statusXp->query('//nav[contains(@class,"kb-history-status-tabs")]/a[@aria-current="page"]')->length===($status==='unsupported'?0:1),'Status navigation exposes the four existing states and one current valid tab');
	historyCheck($statusXp->query('//nav[contains(@class,"kb-history-status-tabs")]/a/span[text()="(3)"]')->length===1 && $statusXp->query('//nav[contains(@class,"kb-history-status-tabs")]/a/span[text()="(1)"]')->length===3,'Status counts retain the complete non-status-filtered set regardless of the selected tab: '.$status);
	if($status==='all'){
		historyCheck($statusXp->query('//tr[contains(@class,"krea-history-row-top")]/td[2]/span[contains(@class,"badge-status3")]')->length===1 && $statusXp->query('//tr[contains(@class,"krea-history-row-top")]/td[2]/span[contains(@class,"badge-status7")]')->length===1,'All keeps review and reversal warnings beside their descriptions');
	}elseif($expectedRows===0){historyCheck($statusXp->query('//table[@id="kreaHistoryTable"]//td[@colspan="6"]')->length===1,'Empty History spans all six columns');}
}
foreach(array(
	array('hist_description'=>'native','all'=>1,'reconciled'=>0,'reversed'=>1),
	array('hist_amount'=>'<0','all'=>1,'reconciled'=>1),
	array('hist_link_type'=>'payment','all'=>1,'reconciled'=>0,'review'=>1),
	array('hist_audit_type'=>'auto_native','all'=>1,'reconciled'=>0,'review'=>1),
	array('hist_date_from'=>'2026-11-14','all'=>1,'reconciled'=>0,'review'=>1),
	array('hist_search'=>'Synthetic native','all'=>1,'reconciled'=>0,'reversed'=>1),
	array('account_context'=>102,'all'=>1,'reconciled'=>0,'reversed'=>1),
	array('hist_date_from'=>'2026-02-30','all'=>0,'reconciled'=>0),
) as $case){
	$counts=array_intersect_key($case,array_flip(array('all','reconciled','review','reversed')));
	$request=array_diff_key($case,$counts)+array('hist_status'=>'all','limit'=>1,'page'=>4,'audit_search_user'=>'not present');
	$countXp=historyXPath(renderHistory($request));
	$tabCounts=array();
	foreach($countXp->query('//nav[contains(@class,"kb-history-status-tabs")]/a') as $tab){parse_str(parse_url($tab->getAttribute('href'),PHP_URL_QUERY),$query);$tabCounts[$query['hist_status']]=(int)trim($tab->lastChild->textContent,'()');}
	historyCheck($tabCounts===$counts,'Counts combine other filters and owned account before status/pagination, without independent audit filters: '.json_encode($request));
}
$fixtureRows=$savedRows;
for($i=1;$i<=4;$i++){$payload=json_decode($savedAudits[0]['payload_json'],true);$payload['marker']='receipt';$fixtureAudits[]=array_merge($savedAudits[0],array('rowid'=>100+$i,'payload_json'=>json_encode($payload)));}
$tabHtml=renderHistory(array('hist_status'=>'reconciled','hist_description'=>'Synthetic','hist_amount'=>'>=0','hist_search'=>'receipt','audit_search_user'=>'Synthetic tester','audit_page'=>1,'limit'=>1,'page'=>1,'sortfield'=>'amount','sortorder'=>'ASC','audit_sortfield'=>'login','audit_sortorder'=>'DESC','action'=>'view','token'=>'must-not-leak','button_search_x'=>'x'));
$tabXp=historyXPath($tabHtml);
foreach($tabXp->query('//nav[contains(@class,"kb-history-status-tabs")]/a') as $index=>$link){
	parse_str(parse_url($link->getAttribute('href'),PHP_URL_QUERY),$query);
	historyCheck($query['hist_status']===array('all','reconciled','review','reversed')[$index] && $query['page']==='0' && $query['history_page']==='0','Status changes reset History to its first native page');
	historyCheck($query['mainmenu']==='bank' && $query['leftmenu']==='kreabank_history' && $query['account_context']==='0' && $query['limit']==='1' && $query['audit_page']==='1','Status links retain native menu, page size and the independent audit page');
	historyCheck($query['hist_description']==='Synthetic' && $query['hist_amount']==='>=0' && $query['hist_search']==='receipt' && $query['audit_search_user']==='Synthetic tester' && $query['sortfield']==='amount' && $query['sortorder']==='ASC' && $query['audit_sortfield']==='login' && $query['audit_sortorder']==='DESC','Status links preserve both lists\' filters and sort orders');
	historyCheck(!isset($query['action']) && !isset($query['token']) && !isset($query['button_search_x']) && !isset($query['pageplusone']) && !isset($query['audit_nav']),'Status links cannot replay actions, tokens, submit buttons or stale native page inputs');
}
$fixtureAudits=$savedAudits;
$scopedHtml=renderHistory(array('account_context'=>101,'hist_status'=>'review','sortfield'=>'status','sortorder'=>'ASC'));
$scopedXp=historyXPath($scopedHtml);
foreach($scopedXp->query('//nav[contains(@class,"kb-history-status-tabs")]/a') as $link){parse_str(parse_url($link->getAttribute('href'),PHP_URL_QUERY),$query);historyCheck($query['account_context']==='101' && $query['sortfield']==='event_ts','Account-scoped status links preserve ownership context and discard the removed column sort');}

// Exercise the real History projection and native adapter, with deliberately
// nonsequential member IDs so positional guesses cannot pair the evidence.
$savedLinks=$fixtureLinks;
$fixtureRows=array(array_merge($base,array('rowid'=>5900,'native_id'=>0,'expected_native_id'=>0,'amount'=>'-1600.00000000','native_amount'=>'-1600.00000000','group_id'=>7000,'group_valid'=>1,'description'=>'Synthetic payroll batch')));
$fixtureAudits=array();$fixtureLinks=array();$fixtureGroupMembers=array();$expectedMembers=array();
for($i=1;$i<=16;$i++){
	$id=6100+(($i*7)%16);$payment=7100+$i;$employee=8100+$i;
	$fixtureGroupMembers[]=array('batch_id'=>7000,'rowid'=>$id);
	$fixtureLinks[]=array('fk_bank'=>$id,'url_id'=>$payment,'url'=>'/salaries/payment_salary/card.php?id=','label'=>'PAY-'.$payment,'type'=>'payment_salary');
	$fixtureLinks[]=array('fk_bank'=>$id,'url_id'=>$employee,'url'=>'/user/card.php?id=','label'=>'Employee '.$employee.' <synthetic>','type'=>'user');
	$expectedMembers[$id]=array($payment,$employee);
}
$sourceEvidence=serialize(array($fixtureRows,$fixtureLinks,$fixtureGroupMembers));
$batchXp=historyXPath(renderHistory());
historyCheck($batchXp->query('//tr[contains(@class,"krea-history-row-top")]')->length===1 && $batchXp->query('//div[@class="krea-history-component"]')->length===16,'A verified sixteen-member batch renders sixteen compact associated components');
foreach($expectedMembers as $id=>$identities){
	$component=$batchXp->query('//div[@class="krea-history-component" and @data-native-bank-line-id="'.$id.'"]')->item(0);
	historyCheck($component && $batchXp->query('.//a[@href="/salaries/payment_salary/card.php?id='.$identities[0].'"]',$component)->length===1
		&& $batchXp->query('.//a[@href="/user/card.php?id='.$identities[1].'"]',$component)->length===1
		&& $batchXp->query('./div[@class="krea-history-component-bank"]//a[@href="/compta/bank/line.php?rowid='.$id.'"]',$component)->length===1
		&& $batchXp->query('.//a',$component)->length===3,'Each salary payment and employee stay beside their exact owned native entry: '.$id);
}
historyCheck($batchXp->query('//div[@class="krea-history-component"]//synthetic')->length===0 && $batchXp->query('//div[@class="krea-history-component"]//a[contains(text(),"<synthetic>")]')->length===16,'Compact employee evidence remains escaped and complete');
historyCheck($batchXp->query('//form[@method="POST"]')->length===0 && $batchXp->query('//a[contains(@href,"expand=staged:5900")]')->length===1,'Component presentation leaves the existing group review action and Undo admission unchanged');
historyCheck(serialize(array($fixtureRows,$fixtureLinks,$fixtureGroupMembers))===$sourceEvidence,'Grouping never mutates financial source identities, links or references');
$reader=new KreaBankHistory(new HistoryFixtureDb(),1);
$preparedGroup=$reader->recent()[0];
historyCheck((int)$preparedGroup['native_bank_line_id']===0 && (int)$preparedGroup['group_id']===7000 && !$preparedGroup['needs_review'],'Grouped History retains its group strategy and has no representative native line');
$fixtureLinks=array();$fixtureGroupMembers=array(array('batch_id'=>7000,'rowid'=>6999));
$orphanXp=historyXPath(renderHistory());
historyCheck($orphanXp->query('//div[@data-native-bank-line-id="6999"]//a[@href="/compta/bank/line.php?rowid=6999"]')->length===1 && $orphanXp->query('//div[@class="krea-history-component-documents"]')->length===0,'A component without document links keeps its native entry without inventing a payment or parent');
$fixtureRows[0]['group_valid']=0;
$reviewXp=historyXPath(renderHistory());
historyCheck($reviewXp->query('//div[@class="krea-history-component"]')->length===0 && $reviewXp->query('//span[contains(@class,"badge-status3")]')->length===1,'Invalid group evidence remains a review warning and cannot display guessed members');
$unpaired=array(array('doc_type'=>'payment_salary','fk_doc'=>1),array('doc_type'=>'user','fk_doc'=>2),array('doc_type'=>'bank_line','fk_doc'=>3));
$unpairedComponents=kreabankHistoryLinkComponents($unpaired);
historyCheck(count($unpairedComponents)===3 && array_column($unpairedComponents,'native_bank_line_id')===array(0,0,0),'Untagged historical links remain unpaired rather than guessing from adjacency');
$conflicting=kreabankHistoryLinkComponents(array(array('native_bank_line_id'=>6999,'doc_type'=>'bank_line','fk_doc'=>6000)));
historyCheck(count($conflicting[0]['documents'])===1 && !$conflicting[0]['bank_entries'],'A different linked native entry is retained as evidence, never relabelled as its owning component');
$fixtureRows=$savedRows;$fixtureAudits=$savedAudits;$fixtureLinks=$savedLinks;$fixtureGroupMembers=array();

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
historyCheck($xp->query('//span[@class="krea-history-link" and text()="'.$langs->trans('BankTransfer').'"]/following-sibling::a[text()="#77"]')->length===1,
	'Generic native transfer markers retain their record ID next to the translated badge');
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
