#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Source-only status/count contracts with a read-only SQL observer. No ERP bootstrap or database.
 */
function getDolGlobalInt($key,$default=0) { return $default; }
require_once __DIR__.'/../class/KreaBankWorklist.class.php';

$checks=0;
function statusCheck($ok,$message) { global $checks; if (!$ok) { throw new RuntimeException($message); } $checks++; }
function statusState($request=array()) { return kreabankWorklistState($request,10); }
function statusSource($id,$changes=array())
{
	return array_merge(array('rowid'=>$id,'fixture_entity'=>7,'source_storage'=>'staging','statement_id'=>$id,'statement_ref'=>'ST-'.$id,
		'bank_account_id'=>11,'bank_account_ref'=>'SYNTHETIC','operation_date'=>'2031-04-10','line_rank'=>$id,'description'=>'Synthetic source '.$id,
		'payment_reference'=>'','bank_reference'=>'','amount'=>'100.00000000','currency'=>'EUR','status'=>0,'is_duplicate'=>0,
		'expected_native_id'=>0,'native_id'=>null,'native_amount'=>null,'native_currency'=>'EUR','rappro'=>0,'num_releve'=>'',
		'group_id'=>0,'group_valid'=>0,'account_closed'=>0,'account_reconcilable'=>1,'fixture_account_type'=>1),$changes);
}

class WorklistStatusDb
{
	public $queries=array(),$records=array(),$drafts=array(),$schema=true,$overflow=false,$draftOverflow=false;
	public function prefix() { return 'kbstatus_'; }
	public function escape($value) { return str_replace("'","''",(string)$value); }
	public function plimit($limit,$offset=0) { return ' LIMIT '.(int)$offset.','.(int)$limit; }
	public function fetch_object($result) { $row=array_shift($result->rows); return $row===null ? false : (object)$row; }
	public function query($sql)
	{
		$this->queries[]=$sql; $rows=array();
		if (strpos($sql,'SHOW TABLES LIKE ')===0) { $rows=$this->schema ? array(array('table'=>'synthetic')) : array(); }
		elseif (strpos($sql,'SELECT COUNT(*) nb FROM information_schema.tables')===0) { $rows=array(array('nb'=>0)); }
		elseif (strpos($sql,'SELECT s.rowid,s.ref,s.bank_account_id,s.import_payload')===0) { $rows=array(); }
		elseif (strpos($sql,'SELECT d.fk_statement_line FROM ')===0) {
			if (!preg_match('/WHERE d.entity=([0-9]+) AND d.fk_user=([0-9]+)/',$sql,$scope)
				|| strpos($sql,'l.entity=d.entity')===false || strpos($sql,'ba.entity=s.entity')===false) { throw new RuntimeException('Draft ownership scope is missing'); }
			foreach ($this->drafts as $draft) {
				foreach ($this->records as $source) {
					if ($draft['entity']===(int)$scope[1] && $draft['actor']===(int)$scope[2] && $source['fixture_entity']===$draft['entity']
						&& $source['source_storage']==='staging' && $source['rowid']===$draft['id']) { $rows[]=array('fk_statement_line'=>$draft['id']); break; }
				}
			}
			if ($this->draftOverflow) { $rows=array_fill(0,KreaBankWorklist::MAX_ROWS+1,array('fk_statement_line'=>1)); }
		} elseif (strpos($sql,'SELECT h.*,ba.clos AS account_closed')===0) {
			if (!preg_match('/bank_account ba ON ba.rowid=h.bank_account_id AND ba.entity=([0-9]+)/',$sql,$scope)
				|| strpos($sql,'h.is_duplicate=0 AND ba.courant<>2')===false) { throw new RuntimeException('Source ownership scope is missing'); }
			$rows=array_values(array_filter($this->records,static function($source)use($scope,$sql){
				if ($source['fixture_entity']!==(int)$scope[1] || $source['is_duplicate'] || $source['fixture_account_type']===2) { return false; }
				if (strpos($sql,'AND h.status IN (0,1)')!==false && !kreabankWorklistPendingSource($source)) { return false; }
				if (strpos($sql,"AND (h.status=2 OR (h.source_storage='legacy_native' AND h.rappro=1))")!==false
					&& $source['status']!==2 && !($source['source_storage']==='legacy_native' && $source['rappro']===1)) { return false; }
				return true;
			}));
			if ($this->overflow) { $rows=array_fill(0,KreaBankWorklist::MAX_ROWS+1,statusSource(1)); }
		} else { throw new RuntimeException('The status projection attempted an unexpected query or mutation'); }
		return (object)array('rows'=>$rows);
	}
}

$db=new WorklistStatusDb();
$db->records=array(statusSource(1),statusSource(2,array('amount'=>'200.00000000')),
	statusSource(3,array('amount'=>'300.00000000','status'=>2,'expected_native_id'=>103,'native_id'=>103,'native_amount'=>'300.00000000','rappro'=>1,'num_releve'=>'ST-3')),
	statusSource(4,array('amount'=>'400.00000000','status'=>2,'expected_native_id'=>104,'native_id'=>104,'native_amount'=>'399.00000000','rappro'=>1,'num_releve'=>'ST-4')),
	statusSource(5,array('account_closed'=>1)),statusSource(6,array('status'=>3)),
	statusSource(2,array('source_storage'=>'legacy_native','statement_id'=>0,'statement_ref'=>'LEGACY','bank_account_id'=>12,
		'expected_native_id'=>2,'native_id'=>2,'native_amount'=>'100.00000000','rappro'=>1,'num_releve'=>'LEGACY')),
	statusSource(8,array('status'=>2,'group_id'=>5,'group_valid'=>0)),statusSource(9),
	statusSource(10,array('fixture_entity'=>8)),statusSource(11,array('fixture_account_type'=>2)),statusSource(12,array('is_duplicate'=>1)),
	statusSource(14,array('expected_native_id'=>114)));
$db->drafts=array(array('entity'=>7,'actor'=>21,'id'=>2),array('entity'=>7,'actor'=>22,'id'=>9),array('entity'=>8,'actor'=>21,'id'=>10),array('entity'=>7,'actor'=>21,'id'=>999));
$service=(object)array('user'=>(object)array('id'=>21));
$worklist=new KreaBankWorklist($db,7,$service);
$expected=array('pending'=>3,'reconciled'=>2,'draft'=>1,'review'=>3,'all'=>10);
statusCheck($worklist->statusCounts(statusState())===$expected,'Persisted source/link facts count every status without matching');
statusCheck($worklist->statusCounts(statusState(array('search_status'=>'draft')))===$expected,'Tab counts ignore only the selected status');
statusCheck(array_keys($worklist->sources(statusState(array('search_status'=>'draft'))))===array('staged:2'),'Draft uses only the current actor’s owned planning row');
statusCheck(array_keys($worklist->sources(statusState(array('search_status'=>'review'))))===array('staged:4','staged:8','staged:14'),'Review selects scalar/group link inconsistencies directly without a scan');
statusCheck(array_keys($worklist->sources(statusState()))===array('staged:1','staged:2','staged:9'),'Pending excludes broken links while retaining the current actor’s Draft');
statusCheck(array_keys($worklist->sources(statusState(array('search_status'=>'reconciled'))))===array('staged:3','native:2'),'Reconciled excludes inconsistent scalar/group evidence before scan admission');
$all=$worklist->sources(statusState(array('search_status'=>'all')));
statusCheck($all['native:2']['worklist_status']==='reconciled' && $all['staged:2']['worklist_status']==='draft','Staging/native numeric collisions retain independent statuses');
statusCheck(count(array_filter($db->queries,static function($sql){return strpos($sql,'SELECT d.fk_statement_line FROM ')===0;}))===1,'All status requests reuse one actor-scoped draft identity read');
statusCheck(count($all)===10 && !isset($all['staged:10']) && !isset($all['staged:11']) && !isset($all['staged:12']),'Foreign companies, cash and duplicate evidence do not enter the count projection');
statusCheck($worklist->statusCounts(statusState(array('search_account'=>'12')))===array('pending'=>0,'reconciled'=>1,'draft'=>0,'review'=>0,'all'=>1),'Other account filters retain their exact status counts');
statusCheck($worklist->statusCounts(statusState(array('search_description'=>'Synthetic source 3')))===array('pending'=>0,'reconciled'=>1,'draft'=>0,'review'=>0,'all'=>1),'Description filtering applies before status totals');
statusCheck($worklist->statusCounts(statusState(array('search_amount'=>'>=300')))===array('pending'=>0,'reconciled'=>1,'draft'=>0,'review'=>1,'all'=>2),'Exact signed source amount filters constrain every tab');
statusCheck($worklist->statusCounts(statusState(array('search_date_from'=>'2031-04-11')))===array_fill_keys(array_keys($expected),0),'A known empty source filter produces genuine zero counts');

$summaries=array();
foreach ($all as $key=>$source) { $summaries[$key]=array('match'=>in_array($key,array('staged:2','staged:3'),true)?'chosen':'other','payment_accounts'=>$key==='staged:2'?array(11):array()); }
$derived=statusState(array('search_match'=>'chosen','search_status'=>'review'));
statusCheck($worklist->statusCounts($derived,$summaries,false)===array_fill_keys(array_keys($expected),null),'Unscanned derived filters remain unknown rather than empty');
statusCheck($worklist->statusCounts($derived,$summaries,true)===array('pending'=>1,'reconciled'=>1,'draft'=>1,'review'=>0,'all'=>2),'A complete derived index supplies exact cross-status counts');
$incomplete=$summaries; unset($incomplete['staged:1']);
statusCheck($worklist->statusCounts($derived,$incomplete,true)===array_fill_keys(array_keys($expected),null),'A partial index cannot report zero for undisplayed statuses');
statusCheck($worklist->statusCounts(statusState(array('search_payment_account'=>'11')),$summaries,true)===array('pending'=>1,'reconciled'=>0,'draft'=>1,'review'=>0,'all'=>1),'Recorded/planned payment-bank filters use the complete derived index');
statusCheck($worklist->statusCounts(statusState(array('search_payment_account'=>'none')),$summaries,true)===array('pending'=>2,'reconciled'=>2,'draft'=>0,'review'=>3,'all'=>9),'No-payment-bank is a derived filter with exact complete totals');
$service->user->id=22;
statusCheck(array_keys($worklist->sources(statusState(array('search_status'=>'draft'))))===array('staged:9'),'An actor change invalidates the private draft identity index');
statusCheck(count(array_filter($db->queries,static function($sql){return strpos($sql,'SELECT d.fk_statement_line FROM ')===0;}))===2,'Changing actor performs a newly scoped draft read');
$db->overflow=true;
statusCheck($worklist->statusCounts(statusState())===array_fill_keys(array_keys($expected),null),'Bounded source overflow never creates false empty counts');
$db->overflow=false; $db->draftOverflow=true;
$overflow=new KreaBankWorklist($db,7,$service);
statusCheck($overflow->statusCounts(statusState())===array_fill_keys(array_keys($expected),null),'Private planning overflow remains explicitly unknown');
statusCheck($overflow->statusCounts(statusState())===array_fill_keys(array_keys($expected),null),'An overflow cannot leave a cached empty planning index');
statusCheck(!array_filter($db->queries,static function($sql){return !preg_match('/^(SELECT|SHOW) /',$sql);}), 'Counts, status filtering and private planning lookup execute no mutation');
echo 'OK: '.$checks." source-only worklist status checks (no database or matcher)\n";
