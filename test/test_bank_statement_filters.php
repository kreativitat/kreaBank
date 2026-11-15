#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
error_reporting(E_ALL);
set_error_handler(static function ($severity, $message, $file, $line) { if (error_reporting() & $severity) { throw new ErrorException($message, 0, $severity, $file, $line); } return false; });
require __DIR__ . '/../lib/kreabank_statement_list.lib.php';
$checks = 0;
function checkStatementFilter($ok,$message) { global $checks; if (!$ok) { throw new RuntimeException($message); } $checks++; }
$state=kreabankStatementListState(array(),25);
checkStatementFilter($state['limit']===25 && $state['page']===0,'Native configured list size replaces the fixed 50-row page');
$state=kreabankStatementListState(array('page'=>9,'limit'=>15,'search_description'=>'  Test  ','button_search_x'=>'x'),25);
checkStatementFilter($state['page']===0 && $state['limit']===15 && $state['filters']['description']==='Test','Searching starts at page one while preserving size and normalized filters');
$state=kreabankStatementListState(array('pageplusone'=>3,'pageplusoneold'=>1,'button_search_x'=>'x'),25);
checkStatementFilter($state['page']===2,'Native one-based page entry takes precedence over the implicit search button');
$state=kreabankStatementListState(array('page'=>4,'search_status'=>'pending','search_credit'=>'0','button_removefilter_x'=>'x'),25);
checkStatementFilter($state['page']===0 && array_filter($state['filters'])===array(),'Native reset clears every table filter and page');
checkStatementFilter(kreabankStatementListState(array('page'=>-3,'limit'=>999999),25)['limit']===5000,'Requests are bounded to the largest native page-size choice');
checkStatementFilter(kreabankStatementListState(array('search_description'=>array('injected'),'token'=>'secret','kind'=>'all'),25)['filters']['description']==='','Nested request values and unrelated state cannot become filters');
$db=new class { public function escape($value) { return addslashes($value); } };
$where=kreabankStatementListWhere($db,array('debit'=>'>=12,34567890','credit'=>'0','running_balance'=>'-9999999999999999.12345678'));
checkStatementFilter(strpos($where,'>= 12.34567890')!==false && strpos($where,'= -9999999999999999.12345678')!==false,'Decimal filter literals preserve all source precision without float conversion');
checkStatementFilter(strpos($where,'END) = 0')!==false,'Zero is an active credit filter');
$where=kreabankStatementListWhere($db,array('operation_to'=>'2026-11-03'));
checkStatementFilter(strpos($where,"h.operation_date<DATE_ADD('2026-11-03', INTERVAL 1 DAY)")!==false,'End dates include late native timestamps on the selected day');
foreach (array(array('operation_from'=>'2026-02-30'),array('debit'=>'1 OR 1=1'),array('running_balance'=>'!=1; DELETE'),array('status'=>'reconciled OR 1=1')) as $filters) {
	checkStatementFilter(strpos(kreabankStatementListWhere($db,$filters),'AND 1=0')!==false,'Invalid dates, amounts and statuses fail closed');
}
checkStatementFilter(strpos(kreabankStatementListWhere($db,array('description'=>'100%_!')),'100!%!_!!')!==false,'Text filters treat percent, underscore and escape characters literally');
$countsSql=kreabankStatementListStatusCountsSql($db,'(COMPLETE_OWNED_BALANCES) h',array('description'=>'visible','status'=>'review'));
checkStatementFilter(strpos($countsSql,"h.description LIKE '%visible%'")!==false && strpos($countsSql,")='review'")===false && strpos($countsSql,'FROM (COMPLETE_OWNED_BALANCES) h')!==false,'Status counts retain other predicates after the complete balance projection and ignore only the active status');
checkStatementFilter(array_keys(kreabankStatementListStatusTabs(array()))===array('pending','reconciled',''),'Empty statements retain All, Pending and Reconciled without irrelevant exceptional tabs');
checkStatementFilter(array_keys(kreabankStatementListStatusTabs(array('registered'=>3,'duplicate'=>1),'skipped'))===array('pending','registered','reconciled','skipped','duplicate',''),'Nonempty exceptional states and a selected empty legacy state remain available');

// Run the actual controller's scoped list preparation and row template without ERP bootstrap.
require_once __DIR__.'/../lib/kreabank_list.lib.php';
function GETPOST($key,$type=''){return $_GET[$key]??'';}
function GETPOSTISSET($key){return isset($_GET[$key]);}
function dol_escape_htmltag($value){return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');}
function dol_print_date($value,$format){return gmdate('Y-m-d',$value);}
function kreabankPrice($value){return number_format((float)$value,2,'.','');}
require_once __DIR__.'/../lib/kreabank_integration.lib.php';
function dol_buildpath($path,$mode=0){return $path;}
function llxHeader(...$args){}
function kreabankPrintWorkspaceNavigation($page,$account){print '<header data-account="'.(int)($account->id??0).'"></header>';}
function kreabankNavigationHiddenFields($id){return $id?'<input type="hidden" name="account_context" value="'.(int)$id.'">':'';}
function dol_get_fiche_end(){return '';}
class Form{public function __construct($db){} public function showFilterButtons(){return '<button name="button_search_x">Search</button><button name="button_removefilter_x">Reset</button>';}}
function print_barre_liste($label,$page,$path,$params,$field,$order,...$args){print '<a data-native-page href="'.dol_escape_htmltag($path.'?'.ltrim($params,'&').'&sortfield='.$field.'&sortorder='.$order.'&page='.($page+1)).'">Next</a>';}
function print_liste_field_titre($label,$path,$field,$unused,$params,$attributes,...$args){print '<th '.$attributes.'>'.dol_escape_htmltag($label).'</th>';}
function statementFilterXPath($html){$old=libxml_use_internal_errors(true);$dom=new DOMDocument();$dom->loadHTML($html);libxml_clear_errors();libxml_use_internal_errors($old);return new DOMXPath($dom);}
function statementFilterLinkQuery($link){$query=array();parse_str(parse_url($link->getAttribute('href'),PHP_URL_QUERY)??'',$query);return $query;}
$controller=file_get_contents(__DIR__.'/../bankstatement.php');
$start=strpos($controller,'$columns = array(');$end=strpos($controller,'$form = new Form($db);',$start);
$viewEnd=strpos($controller,'llxFooter();',$end);
$run=static function($accountId,$request,$overrides=array())use($controller,$start,$end,$viewEnd){
 $_SERVER['PHP_SELF']='/custom/kreabank/bankstatement.php';
 $_GET=$request;$account=$accountId?(object)array('id'=>$accountId):null;$conf=(object)array('liste_limit'=>25);
 $db=new class{
  public function escape($value){return addslashes($value);}
  public function plimit($limit,$offset=0){return ' LIMIT '.$offset.','.$limit;}
  public function fetch_object($result){return array_shift($result->rows);}
  public function num_rows($result){return count($result->rows);}
  public function jdate($value){return strtotime($value.' UTC');}
 };
 $langs=new class{public function trans($key,...$args){return $key;}};
 $report=new class($overrides){
  private $overrides;public function __construct($overrides){$this->overrides=$overrides;}
  public $account;public $queries=array();public $original;
  public function bankStatementWithBalancesSql($account){$this->account=$account;return 'COMPLETE_OWNED_BALANCES_'.$account;}
  public function query($sql){
   $this->queries[]=$sql;$row=(object)array('rowid'=>1,'bank_account_id'=>101,'operation_date'=>'2026-11-01','value_date'=>'2026-11-02','description'=>'Synthetic evidence','amount'=>'-15.80000000','balance_calculated'=>0,'display_balance'=>'1109.70000000','currency'=>'EUR','bank_account_ref'=>'OWNER','statement_ref'=>'SOURCE-REF','evidence_statement_ref'=>'PROOF-REF','is_duplicate'=>0,'native_id'=>0,'group_id'=>0,'group_count'=>0,'group_valid'=>0,'status'=>0,'expected_native_id'=>0,'payment_reference'=>'VISIBLE-PAY','bank_reference'=>'PRIVATE-FEED-ID','source_type'=>'fintable');
   foreach($this->overrides as $key=>$value){$row->$key=$value;}
   $this->original=serialize($row);$rows=array(clone $row,clone $row);
   if($this->account===105 || strpos($sql,"h.statement_ref LIKE '%ABSENT%'")!==false || strpos($sql,"h.bank_account_ref LIKE '%ABSENT%'")!==false || strpos($sql,'AND 1=0')!==false){$rows=array();}
   $status=array_search(kreabankIntegrationLineState($row,$row->statement_ref),kreabankStatementListStatusOptions(),true);
   if(strpos($sql,'SELECT COUNT(*) total')===0){
    $counts=array_fill_keys(array_keys(kreabankStatementListStatusOptions()),0);$counts['total']=count($rows);$counts[$status]=count($rows);$counts['duplicate']=$row->is_duplicate?count($rows):0;
    return(object)array('rows'=>array((object)$counts));
   }
   if(preg_match("/\\)='(pending|registered|reconciled|review|skipped)'/",$sql,$match) && $status!==$match[1]){$rows=array();}
   if(strpos($sql,'AND h.is_duplicate>0')!==false && !$row->is_duplicate){$rows=array();}
   if(preg_match('/ LIMIT (\d+),(\d+)$/',$sql,$parts)){$rows=array_slice($rows,(int)$parts[1],(int)$parts[2]);}
   return(object)array('rows'=>$rows);
  }
 };
 eval(substr($controller,$start,$end-$start));
 ob_start();eval(substr($controller,$end,$viewEnd-$end));$html=ob_get_clean();
 return compact('columns','filters','sort','params','where','source','total','statusCounts','page','num','report','html');
};
$selected=$run(101,array('search_statement_ref'=>'ABSENT','search_bank_account_ref'=>'ABSENT','sortfield'=>'bank_account_ref','limit'=>1,'page'=>1));
checkStatementFilter(!isset($selected['columns']['statement_ref']) && !isset($selected['columns']['bank_account_ref']) && !isset($selected['columns']['status']) && $selected['filters']['statement_ref']==='' && $selected['filters']['bank_account_ref']==='', 'Selected owned bank omits repeated source/account and status columns and clears invisible filters');
checkStatementFilter(!isset($selected['params']['search_statement_ref']) && !isset($selected['params']['search_bank_account_ref']) && $selected['sort']['field']==='operation_date', 'Hidden bank/source filters and sorts cannot survive native paging');
checkStatementFilter($selected['report']->account===101 && $selected['source']==='(COMPLETE_OWNED_BALANCES_101) h' && $selected['total']===2 && $selected['page']===1 && $selected['num']===1, 'Selected bank retains complete scoped balances, count and native page');
checkStatementFilter(strpos(implode(' ',$selected['report']->queries),'h.statement_ref LIKE')===false && strpos(implode(' ',$selected['report']->queries),'h.bank_account_ref LIKE')===false, 'Count and rows discard both hidden predicates after balance preparation');
$xp=statementFilterXPath($selected['html']);$cells=$xp->query('//tbody/tr[1]/td');
checkStatementFilter(strpos($cells->item(2)->textContent,'VISIBLE-PAY')!==false && strpos($selected['html'],'PRIVATE-FEED-ID')===false && strpos($selected['html'],'SOURCE-REF')===false && strpos($selected['html'],'OWNER')===false && strpos($selected['html'],'1109.70')!==false, 'Visible references and supplied balance remain without repeated bank/source or provider identifiers');
$all=$run(0,array('search_statement_ref'=>'SOURCE-REF','search_bank_account_ref'=>'OWNER','sortfield'=>'statement_ref','limit'=>1,'page'=>1));
checkStatementFilter(isset($all['columns']['statement_ref'],$all['columns']['bank_account_ref']) && !isset($all['columns']['status']) && $all['params']['search_statement_ref']==='SOURCE-REF' && $all['sort']['field']==='statement_ref', 'All-bank view retains bank/source filters and sorts but uses status tabs');
checkStatementFilter(substr_count(implode(' ',$all['report']->queries),'h.statement_ref LIKE')===2 && substr_count(implode(' ',$all['report']->queries),'h.bank_account_ref LIKE')===2 && strpos($all['html'],'SOURCE-REF')!==false && strpos($all['html'],'PROOF-REF')!==false, 'All-bank count and data share both predicates and render original/reviewed sources');
foreach(array($selected,$all) as $case){
 $xp=statementFilterXPath($case['html']);$count=count($case['columns'])+1;
 checkStatementFilter($xp->query('//thead/tr[1]/td')->length===$count && $xp->query('//thead/tr[2]/th')->length===$count && $xp->query('//tbody/tr[1]/td')->length===$count && $xp->query('//tbody/tr[1]/td[contains(@class,"right")]')->length===3, 'Header/filter/body cells align and preserve three right-aligned numeric columns');
 checkStatementFilter($xp->query('//select[@name="search_status"]')->length===0 && $xp->query('//input[@type="hidden" and @name="search_status"]')->length===1, 'The status column dropdown is replaced by exactly one hidden active tab');
}
$tabCase=$run(101,array('search_status'=>'review','search_description'=>'A & <B>','search_credit'=>'>=1.23456789','sortfield'=>'credit','sortorder'=>'ASC','limit'=>1,'page'=>1,'action'=>'delete','token'=>'private-token','kind'=>'cash'));
$xp=statementFilterXPath($tabCase['html']);$tabs=$xp->query('//nav[contains(@class,"kb-statement-status-tabs")]/a');
checkStatementFilter($tabs->length===4 && $xp->query('//nav/a[@aria-current="page"]')->length===1, 'Ordinary states and selected empty Review remain visible while unused exceptional tabs are hidden');
$statuses=array();
foreach($tabs as $link){
 $q=statementFilterLinkQuery($link);$status=$q['search_status']??'';$statuses[]=$status;
 checkStatementFilter($q['account_context']==='101' && $q['limit']==='1' && $q['page']==='0' && $q['sortfield']==='credit' && $q['sortorder']==='ASC' && $q['search_description']==='A & <B>' && $q['search_credit']==='>=1.23456789' && !isset($q['action']) && !isset($q['token']) && !isset($q['kind']), 'Status tabs preserve scoped exact filters, size and sorting, reset page and exclude write/overview state');
 if($link->hasAttribute('aria-current')){checkStatementFilter($status==='review','Selected tab follows the normalized evidence filter');}
}
checkStatementFilter($statuses===array('pending','reconciled','review',''),'All tab uses the statement empty value, preserving existing status semantics');
checkStatementFilter($xp->query('//input[@name="search_status"]')->item(0)->getAttribute('value')==='review' && statementFilterLinkQuery($xp->query('//a[@data-native-page]')->item(0))['search_status']==='review', 'Header submission and native page links retain the active status');
$reset=$run(101,array('search_status'=>'review','search_description'=>'REMOVE','search_credit'=>'0','button_removefilter_x'=>'x','page'=>1));
checkStatementFilter($reset['page']===0 && array_filter($reset['filters'],static function($value){return $value!=='';})===array('status'=>'review'), 'Column reset clears table predicates while keeping the selected status tab');
checkStatementFilter(strpos($reset['where'],"='review'")!==false && strpos($reset['where'],'REMOVE')===false, 'Reset count and data retain the visible status selection');
$invalid=$run(101,array('search_status'=>'unknown'));
checkStatementFilter($invalid['total']===0 && strpos($invalid['where'],'AND 1=0')!==false,'Unknown status fails closed');
$review=$run(101,array(),array('status'=>2,'expected_native_id'=>987,'native_id'=>987,'native_currency'=>'EUR','native_amount'=>'-16.00','rappro'=>1,'num_releve'=>'SOURCE-REF','is_duplicate'=>1));
$xp=statementFilterXPath($review['html']);$description=$xp->query('//tbody/tr[1]/td[contains(@class,"kb-statement-description")]')->item(0);
checkStatementFilter(strpos($description->textContent,'KreaBankLinkNeedsReview')!==false && strpos($description->textContent,'KreaBankDuplicate')!==false && $xp->query('.//a[contains(@href,"line.php?rowid=987")]',$description)->length===1,'Review/duplicate warnings and native movement identity survive removal of the Status column');
$native=array('expected_native_id'=>987,'native_id'=>987,'native_currency'=>'EUR','native_amount'=>'-15.80000000','rappro'=>0,'num_releve'=>'SOURCE-REF');
foreach(array('pending'=>array(),'registered'=>$native,'reconciled'=>array_merge($native,array('status'=>2,'rappro'=>1)),
 'review'=>array_merge($native,array('status'=>2,'native_amount'=>'-16.00')),'skipped'=>array('status'=>3),'duplicate'=>array('is_duplicate'=>1)) as $status=>$overrides){
 $case=$run(101,array('search_status'=>$status,'limit'=>1,'page'=>1),$overrides);$xp=statementFilterXPath($case['html']);
 checkStatementFilter($case['total']===2 && $case['num']===1 && $case['page']===1 && $case['statusCounts'][$status]===2,'Distinct '.$status.' evidence has matching scoped count and paginated rows');
 checkStatementFilter($xp->query('//nav/a[@aria-current="page"]')->length===1 && preg_match('/\\(2\\)$/',$xp->query('//nav/a[@aria-current="page"]')->item(0)->textContent)===1,'Selected '.$status.' displays its real count');
}
checkStatementFilter($review['statusCounts']['review']===2 && $review['statusCounts']['duplicate']===2 && $review['statusCounts']['']===2,'Duplicate warnings overlap ordinary evidence states without inflating All');
$xp=statementFilterXPath($selected['html']);
checkStatementFilter($xp->query('//nav/a')->length===3 && $xp->query('//nav/a[contains(@href,"search_status=registered")]')->length===0,'A normal pending statement offers only useful ordinary navigation');
$batch=$run(101,array(),array('group_id'=>8,'group_count'=>3,'group_valid'=>1,'status'=>2));
$xp=statementFilterXPath($batch['html']);$link=$xp->query('//tbody/tr[1]/td[contains(@class,"kb-statement-description")]//a')->item(0);$q=statementFilterLinkQuery($link);
checkStatementFilter($q['search_account']==='101' && $q['expand']==='staged:1' && $q['mode']==='batch' && strpos($link->textContent,'(3)')!==false,'Batch review retains its explicit statement/account identity and member count');
foreach(array($run(0,array('search_statement_ref'=>'ABSENT')),$run(105,array('search_status'=>'pending','limit'=>10,'page'=>9))) as $empty){
 checkStatementFilter($empty['total']===0 && $empty['page']===0 && strpos($empty['html'],'colspan="'.(count($empty['columns'])+1).'"')!==false,'Empty bank or filters retain a valid native first page and dynamic colspan');
}
$empty=$run(105,array('search_status'=>'pending'));
checkStatementFilter($empty['report']->account===105 && $empty['params']['account_context']===105 && strpos($empty['html'],'data-account="105"')!==false,'An empty owned bank retains its explicit scope instead of falling back to other banks');
$_GET=array();
print "OK: $checks bank statement filter and pagination checks\n";
