#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Native header/filter rendering and pure state checks; no ERP bootstrap/database.
 */
define('DOL_DOCUMENT_ROOT',getenv('KREABANK_DOLIBARR_ROOT') ?: dirname(__DIR__,3));
define('DOL_URL_ROOT','');
require_once DOL_DOCUMENT_ROOT . '/core/lib/functions.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/class/translate.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/class/html.form.class.php';
require_once __DIR__ . '/../lib/kreabank_list.lib.php';
require_once __DIR__ . '/../lib/kreabank_history.lib.php';
require_once __DIR__ . '/../lib/kreabank_import_list.lib.php';
require_once __DIR__ . '/../lib/kreabank_integration.lib.php';
$conf=(object)array('global'=>new stdClass(),'currency'=>'EUR');
$_SERVER['PHP_SELF']='/custom/kreabank/history.php';
$checks=0;
function checkImportList($ok,$message){global $checks;if(!$ok){throw new RuntimeException($message);} $checks++;}
foreach(array(false,true) as $detail){
 $state=kreabankImportListState(array(),25,$detail);
 checkImportList($state['page']===0 && $state['limit']===25,'Configured native page size and page zero are defaults');
 foreach(array('button_search','button_search_x','button_search.x') as $name){checkImportList(kreabankImportListState(array('page'=>3,$name=>'x'),15,$detail)['page']===0,'Search resets native paging');}
 foreach(array('button_removefilter','button_removefilter_x','button_removefilter.x') as $name){$state=kreabankImportListState(array('page'=>3,'search_statement_ref'=>'old','search_amount'=>'0','search_status'=>'review',$name=>'x'),25,$detail);checkImportList($state['page']===0 && !array_filter($state['filters']),'Reset clears every header, including zero and status');}
 checkImportList(kreabankImportListState(array('pageplusone'=>3,'pageplusoneold'=>1,'button_search_x'=>'x'),25,$detail)['page']===2,'One-based native page jump wins over implicit search');
 checkImportList(kreabankImportListState(array('limit'=>99999,'page'=>-1),25,$detail)['limit']===5000,'Native page-size requests remain bounded');
 $state=kreabankImportListState(array('search_description'=>array('bad'),'search_statement_ref'=>array('bad'),'action'=>'undo','token'=>'secret','kind'=>'cash'),25,$detail);
 checkImportList(!array_filter($state['filters']) && !isset($state['action'],$state['token'],$state['kind']),'Nested and unrelated request state is not a filter');
}
foreach(array('en_US','pt_PT') as $locale){
 $langs=new Translate(DOL_DOCUMENT_ROOT,$conf);$langs->defaultlang=$locale;$langs->dir=array(dirname(__DIR__),DOL_DOCUMENT_ROOT);
 foreach(array('main','banks','kreabank') as $file){$langs->load($file,2,0,$locale,1);}
 $params=array('account_context'=>101,'hist_amount'=>'>=0','hist_status'=>'reconciled','hist_description'=>'A&B','sortfield'=>'amount','sortorder'=>'ASC','audit_sortfield'=>'login','audit_sortorder'=>'DESC','history_page'=>2,'audit_page'=>3,'limit'=>15);
 foreach(array(''=>array('field'=>'amount','order'=>'ASC','prefix'=>''),'audit_'=>array('field'=>'login','order'=>'DESC','prefix'=>'audit_')) as $prefix=>$sort){
  foreach(array('ASC','DESC') as $order){$sort['order']=$order;$field=$prefix===''?'amount':'login';$html=kreabankHistoryListHeader($langs->trans($prefix===''?'Amount':'Login'),$field,$sort,$params,$prefix==='');
   checkImportList(strpos($html,'liste_titre_sel')!==false && strpos($html,'class="reposition"')!==false,'Actual native sorted header and link are rendered');
   $previous=libxml_use_internal_errors(true);$dom=new DOMDocument();$dom->loadHTML('<table><tr>'.$html.'</tr></table>');libxml_clear_errors();libxml_use_internal_errors($previous);
   foreach($dom->getElementsByTagName('a') as $link){parse_str(parse_url($link->getAttribute('href'),PHP_URL_QUERY),$query);
    checkImportList(($query[$prefix.'sortfield']??'')===$field && ($query[$prefix.'sortorder']??'')===($order==='ASC'?'DESC':'ASC'),'Native-looking header toggles the correct independent sort namespace');
    checkImportList(($query['hist_amount']??'')==='>=0' && ($query['hist_description']??'')==='A&B' && ($query['limit']??'')==='15' && ($query['account_context']??'')==='101','Header retains normalized filters, size and account');
    checkImportList(!isset($query['_sortfield'],$query['audit_'],$query['action'],$query['token']),'Native adapter emits no orphaned prefix or financial action');
    checkImportList($prefix!=='' ? ($query['sortfield']??'')==='amount' && ($query['audit_nav']??'')==='1' : ($query['audit_sortfield']??'')==='login' && !isset($query['audit_nav']),'Sorting one list preserves the other list ordering');
   }
  }
 }
 $form=new Form(null);$buttons=kreabankHistoryFilterButtons($form,'kreaHistoryFilterForm');
 checkImportList(substr_count($buttons,'form="kreaHistoryFilterForm"')===2 && strpos($buttons,'fas fa-search')!==false && strpos($buttons,'fas fa-times')!==false,'Actual native filter buttons keep their explicit read-only form owner');
}
checkImportList(dol_string_nohtmltag('<>125',0,'')==='125','Native alphanohtml sanitization would erase the not-equal operator');
foreach(array(array('1.01','1.00',false),array('1.01000001','1.00',true),array('9999999999999999.12345678','9999999999999999.11345678',false),array('9999999999999999.12345678','9999999999999999.11345677',true)) as $values){checkImportList(kreabankIntegrationAmountNeedsReview($values[0],$values[1])===$values[2],'Displayed status retains exact one-cent tolerance even at maximum precision');}

// Execute the shipped index setup/render regions with synthetic owned projections.
// No native bootstrap, connection, financial records or query mutation is involved.
$statementController=file_get_contents(__DIR__.'/../statement.php');
$setupStart=strpos($statementController,"if (\$statement) {\n\t\$source");
$setupEnd=strpos($statementController,'$form = new Form($db);',$setupStart);
$headerStart=strpos($statementController,'foreach ($columns as $field=>$key)',$setupEnd);
$headerEnd=strpos($statementController,'$shown = 0;',$headerStart);
$bodyEnd=strpos($statementController,"print '</tbody></table></div></form>';",$headerEnd);
$conf->liste_limit=25;
$conf->file=(object)array('dol_document_root'=>array('main'=>DOL_DOCUMENT_ROOT));
$_SERVER['PHP_SELF']='/custom/kreabank/statement.php';
$renderImportIndex=static function($account,$request)use($statementController,$setupStart,$setupEnd,$headerStart,$headerEnd,$bodyEnd){
 global $conf,$langs;
 $_GET=$request;$_POST=array();
 $navigationAccountId=$account;$navigationAccount=$account?(object)array('id'=>$account):null;$statement=null;$reference='';
 $db=new class{
  public function escape($value){return addslashes($value);}
  public function plimit($limit,$offset=0){return ' LIMIT '.$offset.','.$limit;}
  public function fetch_object($result){return array_shift($result->rows);}
  public function num_rows($result){return count($result->rows);}
  public function jdate($value){return strtotime($value.' UTC');}
 };
 $report=new class($request['search_bank_account_ref']??''){
  public $account=0;public $queries=array();private $bankFilter;
  public function __construct($bankFilter){$this->bankFilter=$bankFilter;}
  public function statementsSql($account){$this->account=$account;return 'OWNED_STATEMENTS_'.$account;}
  public function query($sql){
   $this->queries[]=$sql;$rows=array();
   foreach(array(101,101,102) as $i=>$account){
    if($this->account && $this->account!==$account){continue;}
    $row=(object)array('bank_account_id'=>$account,'bank_account_ref'=>'ACCOUNT-'.$account,'statement_ref'=>'STATEMENT-'.$i,'imported_at'=>'2026-11-01 12:00:00','imported_lines'=>7,'native_lines'=>3,'reconciled_lines'=>2,'issue_lines'=>0);
    if(strpos($sql,'h.bank_account_ref LIKE')!==false && stripos($row->bank_account_ref,$this->bankFilter)===false){continue;}
    $rows[]=$row;
   }
   if(strpos($sql,'SELECT COUNT(*)')===0){return(object)array('rows'=>array((object)array('nb'=>count($rows))));}
   if(preg_match('/ LIMIT (\d+),(\d+)$/',$sql,$parts)){$rows=array_slice($rows,(int)$parts[1],(int)$parts[2]);}
   return(object)array('rows'=>$rows);
  }
 };
 eval(substr($statementController,$setupStart,$setupEnd-$setupStart));
 $form=new Form($db);
 ob_start();print '<table><thead><tr class="liste_titre_filter">';
 eval(substr($statementController,$headerStart,$headerEnd-$headerStart));
 eval(substr($statementController,$headerEnd,$bodyEnd-$headerEnd));
 print '</tbody></table>';$html=ob_get_clean();
 return compact('html','columns','filters','params','sort','total','page','num','report');
};
$selected=$renderImportIndex(101,array('search_bank_account_ref'=>'ABSENT','sortfield'=>'bank_account_ref','sortorder'=>'ASC','limit'=>1,'page'=>1));
checkImportList(!isset($selected['columns']['bank_account_ref']) && !isset($selected['params']['search_bank_account_ref']) && $selected['filters']['bank_account_ref']==='', 'Owned selected account removes both repeated column and invisible stale bank filter');
checkImportList($selected['report']->account===101 && $selected['total']===2 && $selected['page']===1 && $selected['num']===1, 'Selected-account projection, count and pagination survive a stale hidden bank filter');
checkImportList($selected['sort']['field']==='imported_at' && strpos(implode(' ',$selected['report']->queries),'h.bank_account_ref LIKE')===false, 'Hidden account column cannot remain a sort target or count/data predicate');
checkImportList(strpos($selected['html'],'search_bank_account_ref')===false && strpos($selected['html'],'ACCOUNT-101')===false && $selected['params']['account_context']===101, 'Selected rows/filter headers and sort links omit repeated account/filter while retaining owned account navigation');
$all=$renderImportIndex(0,array('search_bank_account_ref'=>'ACCOUNT-101','sortfield'=>'bank_account_ref','limit'=>1,'page'=>1));
checkImportList(isset($all['columns']['bank_account_ref']) && $all['params']['search_bank_account_ref']==='ACCOUNT-101' && $all['sort']['field']==='bank_account_ref', 'All-bank index retains visible account column, filter and allowlisted sort');
checkImportList($all['total']===2 && $all['page']===1 && substr_count(implode(' ',$all['report']->queries),'h.bank_account_ref LIKE')===2, 'All-bank account filtering remains identical for count and paginated data');
foreach(array($selected,$all) as $case){
 $previous=libxml_use_internal_errors(true);$dom=new DOMDocument();$dom->loadHTML($case['html']);$xp=new DOMXPath($dom);libxml_clear_errors();libxml_use_internal_errors($previous);
 checkImportList($xp->query('//tbody/tr[1]/td')->length===count($case['columns'])+1 && $xp->query('//thead/tr[1]/td')->length===count($case['columns'])+1, 'Conditional account column keeps data/header/action cells aligned');
 checkImportList($xp->query('//tbody/tr[1]/td[@class="right"]')->length===3 && $xp->query('//thead/tr[2]/th[contains(@class,"right")]')->length===3, 'All three numeric columns retain native right alignment in either scope');
}
$_GET=$_POST=array();
print "OK: $checks imported-list/native-header checks\n";
