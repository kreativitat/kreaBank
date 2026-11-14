#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
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

// Run the actual controller's scoped list preparation and row template without ERP bootstrap.
require_once __DIR__.'/../lib/kreabank_list.lib.php';
function GETPOST($key,$type=''){return $_GET[$key]??'';}
function GETPOSTISSET($key){return isset($_GET[$key]);}
function dol_escape_htmltag($value){return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');}
function dol_print_date($value,$format){return gmdate('Y-m-d',$value);}
function kreabankPrice($value){return number_format((float)$value,2,'.','');}
function kreabankIntegrationLineState($row,$reference){return 'Pending';}
function kreabankStatementReferences($row){return array();}
function kreabankStatementUrl($account,$reference=''){return '/statement.php?account_context='.$account.'&ref='.rawurlencode($reference);}
$controller=file_get_contents(__DIR__.'/../bankstatement.php');
$start=strpos($controller,'$columns = array(');$end=strpos($controller,'$form = new Form($db);',$start);
$bodyStart=strpos($controller,'$shown = 0;',$end);$bodyEnd=strpos($controller,"print '</tbody></table></div></form>'",$bodyStart);
$run=static function($accountId,$request)use($controller,$start,$end,$bodyStart,$bodyEnd){
 $_GET=$request;$account=$accountId?(object)array('id'=>$accountId):null;$conf=(object)array('liste_limit'=>25);
 $db=new class{
  public function escape($value){return addslashes($value);}
  public function plimit($limit,$offset=0){return ' LIMIT '.$offset.','.$limit;}
  public function fetch_object($result){return array_shift($result->rows);}
  public function num_rows($result){return count($result->rows);}
  public function jdate($value){return strtotime($value.' UTC');}
 };
 $langs=new class{public function trans($key,...$args){return $key;}};
 $report=new class{
  public $account;public $queries=array();public $original;
  public function bankStatementWithBalancesSql($account){$this->account=$account;return 'COMPLETE_OWNED_BALANCES_'.$account;}
  public function query($sql){
   $this->queries[]=$sql;$row=(object)array('rowid'=>1,'bank_account_id'=>101,'operation_date'=>'2026-11-01','value_date'=>'2026-11-02','description'=>'Synthetic evidence','amount'=>'-15.80000000','balance_calculated'=>0,'display_balance'=>'1109.70000000','currency'=>'EUR','bank_account_ref'=>'OWNER','statement_ref'=>'SOURCE-REF','evidence_statement_ref'=>'PROOF-REF','is_duplicate'=>0,'native_id'=>0,'group_id'=>0);
   $this->original=serialize($row);$rows=array(clone $row,clone $row);
   if(strpos($sql,"h.statement_ref LIKE '%ABSENT%'")!==false){$rows=array();}
   if(strpos($sql,'SELECT COUNT(*)')===0){return(object)array('rows'=>array((object)array('nb'=>count($rows))));}
   if(preg_match('/ LIMIT (\d+),(\d+)$/',$sql,$parts)){$rows=array_slice($rows,(int)$parts[1],(int)$parts[2]);}
   return(object)array('rows'=>$rows);
  }
 };
 eval(substr($controller,$start,$end-$start));
 ob_start();print '<table><tbody>';eval(substr($controller,$bodyStart,$bodyEnd-$bodyStart));print '</tbody></table>';$html=ob_get_clean();
 return compact('columns','filters','sort','params','where','source','total','page','num','report','html');
};
$selected=$run(101,array('search_statement_ref'=>'ABSENT','search_bank_account_ref'=>'OWNER','sortfield'=>'statement_ref','limit'=>1,'page'=>1));
checkStatementFilter(!isset($selected['columns']['statement_ref']) && isset($selected['columns']['bank_account_ref']) && $selected['filters']['statement_ref']==='', 'Selected owned bank hides only the highlighted source column and clears its invisible filter');
checkStatementFilter(!isset($selected['params']['search_statement_ref']) && $selected['params']['search_bank_account_ref']==='OWNER' && $selected['sort']['field']==='operation_date', 'Hidden source filter/sort cannot survive in native paging links while the bank column remains filterable');
checkStatementFilter($selected['report']->account===101 && $selected['source']==='(COMPLETE_OWNED_BALANCES_101) h' && $selected['total']===2 && $selected['page']===1 && $selected['num']===1, 'Selected bank retains the complete scoped balance projection, count and native page');
checkStatementFilter(strpos(implode(' ',$selected['report']->queries),'h.statement_ref LIKE')===false && substr_count(implode(' ',$selected['report']->queries),'h.bank_account_ref LIKE')===2, 'Count and rows drop only the hidden source predicate, after balance preparation');
checkStatementFilter(strpos($selected['html'],'SOURCE-REF')===false && strpos($selected['html'],'PROOF-REF')===false && strpos($selected['html'],'OWNER')!==false && strpos($selected['html'],'1109.70')!==false, 'Selected-bank cells omit source links but retain bank identity and supplied balance');
$all=$run(0,array('search_statement_ref'=>'SOURCE-REF','sortfield'=>'statement_ref','limit'=>1,'page'=>1));
checkStatementFilter(isset($all['columns']['statement_ref'],$all['columns']['bank_account_ref']) && $all['params']['search_statement_ref']==='SOURCE-REF' && $all['sort']['field']==='statement_ref', 'All-bank view retains both columns and the source filter/sort');
checkStatementFilter(substr_count(implode(' ',$all['report']->queries),'h.statement_ref LIKE')===2 && strpos($all['html'],'SOURCE-REF')!==false && strpos($all['html'],'PROOF-REF')!==false, 'All-bank count/data apply the same source filter and render both original and reviewed source references');
foreach(array($selected,$all) as $case){
 $previous=libxml_use_internal_errors(true);$dom=new DOMDocument();$dom->loadHTML($case['html']);$xp=new DOMXPath($dom);libxml_clear_errors();libxml_use_internal_errors($previous);
 checkStatementFilter($xp->query('//tbody/tr[1]/td')->length===count($case['columns'])+1 && $xp->query('//tbody/tr[1]/td[contains(@class,"right")]')->length===3, 'Conditional source column keeps row/action cells aligned and preserves three numeric columns');
}
$empty=$run(0,array('search_statement_ref'=>'ABSENT'));
checkStatementFilter($empty['total']===0 && strpos($empty['html'],'colspan="'.(count($empty['columns'])+1).'"')!==false, 'Empty all-bank filters retain the correct dynamic table colspan');
$_GET=array();
print "OK: $checks bank statement filter and pagination checks\n";
