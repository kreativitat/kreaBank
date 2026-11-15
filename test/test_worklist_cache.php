#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Synthetic presentation and actual row-controller checks; no ERP bootstrap/DB.
 */
require_once __DIR__.'/../lib/kreabank_worklist.lib.php';
$checks=0;
function cacheCheck($ok,$message){global $checks;if(!$ok){throw new RuntimeException($message);}$checks++;}
function cacheSource($id,$amount=null){return array('rowid'=>$id,'fk_statement'=>$id,'statement_id'=>$id,'source_storage'=>'staging','bank_account_id'=>101,
	'bank_account_ref'=>'SYNTHETIC','amount'=>$amount??'-'.$id.'.00','amount_decimal'=>$amount??'-'.$id.'.00','currency'=>'EUR','operation_date'=>'2031-11-10',
	'description'=>'Synthetic '.$id,'payment_reference'=>'','bank_reference'=>'','status'=>0,'account_open'=>true,'needs_review'=>false);}
class CacheFixtureRead
{
	public $sources,$calls=array(),$visibility=array(),$denied=false;
	public function __construct($sources){$this->sources=$sources;}
	public function compact($key){
		$this->calls[]=$key;$line=$this->sources[$key];$id=(int)$line['rowid'];
		$pair=$id===3?null:array('line'=>$line,'suggestion'=>array('doc_type'=>'native_bank','doc_id'=>100+$id,'doc_ref'=>'NATIVE-'.(100+$id),
			'amount_open'=>$line['amount'],'doc_date'=>$line['operation_date'],'bank_account_id'=>101,'bank_account_ref'=>'SYNTHETIC','thirdparty_name'=>'Synthetic payee'));
		$prepared=array('key'=>$key,'line'=>$line,'pair'=>$pair,'account_candidates'=>array(),'account_review'=>null,'batch'=>array(),'completed'=>null);
		return array('key'=>$key,'status'=>'pending','match'=>'Synthetic '.$id,'payment_accounts'=>array(101),'payment_account'=>'SYNTHETIC',
			'dependencies'=>kreabankWorklistDependencies($prepared),'_prepared'=>$prepared);
	}
	public function presentationVisible($prepared){$this->visibility[]=$prepared['key'];return !$this->denied;}
}
function cacheFixture($peers=false){
	$sources=array();for($id=1;$id<=11;$id++){$sources['staged:'.$id]=cacheSource($id);}
	if($peers){$sources['staged:3']=cacheSource(3,'-1.00');$sources['staged:3']['bank_account_id']=102;}
	$read=new CacheFixtureRead($sources);$facts=kreabankWorklistSourceState($sources);$scope=hash('sha256',json_encode($facts));
	$scan=kreabankWorklistRebaseScan(array(),'company-user-filter-rights',$scope,$facts,time());
	$scan['targets']=array_slice(array_keys($sources),0,10);kreabankWorklistPresentationPage($scan,$scan['targets'],10);
	foreach($scan['targets'] as $key){kreabankWorklistStoreSummary($scan,$key,$read->compact($key));}
	if($peers){$scan['summaries']['staged:4']['dependencies']['tokens']=array('native_bank:101');}
	return array($sources,$read,$scan);
}
function cacheRenderActualRows(&$cache,$sources,$read,$reuse){
	$worklist=$read;$sourceKeys=array_keys($sources);$state=kreabankWorklistState(array());$baseParams=$state['params'];
	$page=array('rows'=>array());foreach(array_slice($sources,0,10,true) as $key=>$line){$page['rows'][]=array('key'=>$key,'line'=>$line);}
	kreabankWorklistPresentationPage($cache,array_column($page['rows'],'key'),10);
	$reusePrepared=$reuse;$expand='';$mode='batch';$search='';$canWrite=true;$canMove=true;$canCreate=true;$canUndo=false;
	$paymentUrl=static function(){return '';};$pairDescriptor=static function(){return array('action'=>'confirm_pair','fields'=>array());};
	$candidateModel=static function($pair){$s=$pair['suggestion'];return array('doc_type'=>$s['doc_type'],'doc_id'=>$s['doc_id'],'account_ref'=>'SYNTHETIC');};
	$langs=new class{public function trans($key){return $key;}};$service=new stdClass();$modelRows=array();$_SESSION=array();
	$controller=file_get_contents(__DIR__.'/../reconciliationlist.php');$start=strpos($controller,"foreach (\$page['rows'] as \$indexRow)");$end=strpos($controller,"\nob_start();",$start);
	if($start===false||$end===false){throw new LogicException('Actual row controller boundary changed');}
	eval(substr($controller,$start,$end-$start));return $modelRows;
}

list($sources,$read,$scan)=cacheFixture();$read->calls=array();$read->visibility=array();
$rows=cacheRenderActualRows($scan,$sources,$read,true);
cacheCheck(!$read->calls && count($read->visibility)===10 && count($rows)===10,'Authorized continuation renders ten unchanged rows with zero repeated prepare/matcher calls and current target visibility checks');
$beforeExpiry=$scan['presentations']['staged:2']['expires'];
cacheRenderActualRows($scan,$sources,$read,true);
cacheCheck($scan['presentations']['staged:2']['expires']===$beforeExpiry && !$read->calls,'Continuation never renews the fixed presentation expiry');
$read->calls=array();cacheRenderActualRows($scan,$sources,$read,false);
cacheCheck(count($read->calls)===10,'An intentional refresh refreshes only the visible recommendations without restarting a full scan');

list($sources,$read,$scan)=cacheFixture();$session=array('kreabank_worklist_scan'=>$scan);$oldGeneration=$scan['generation'];
kreabankWorklistInvalidateScan($session,kreabankWorklistActionEffect('staged:1',array('line'=>$sources['staged:1'])));
unset($sources['staged:1']);$read->sources=$sources;$facts=kreabankWorklistSourceState($sources);
$scan=kreabankWorklistRebaseScan($session['kreabank_worklist_scan'],'company-user-filter-rights',hash('sha256',json_encode($facts)),$facts,time());
cacheCheck(count($scan['summaries'])===9 && isset($scan['summaries']['staged:2']),'Consuming one source preserves nine unrelated completed summaries');
$scan['targets']=kreabankWorklistScanTargets(array_keys($sources),array_slice(array_keys($sources),0,10),$scan['summaries'],10);
kreabankWorklistPresentationPage($scan,$scan['targets'],10);$read->calls=array();kreabankWorklistAdvanceScan($scan,array($read,'compact'));
cacheCheck($read->calls===array('staged:11'),'One new visible row grants exactly one new preparation');
$read->calls=array();cacheRenderActualRows($scan,$sources,$read,true);
cacheCheck(!$read->calls,'Redirected GET performs zero repeated row preparations after the single newly visible row was analysed');
cacheCheck(!kreabankWorklistScanAdmitted($scan,$scan['scope'],$oldGeneration,0),'A pre-action background generation cannot publish over continued results');

list($sources,$read,$scan)=cacheFixture(true);$session=array('kreabank_worklist_scan'=>$scan,'kreabank_worklist_reviews'=>array(
	'shared'=>array('payload'=>array('key'=>'staged:4')),'unrelated'=>array('payload'=>array('key'=>'staged:2'))));
$effect=kreabankWorklistActionEffect('staged:1',array('line'=>$sources['staged:1'],'pair'=>array('suggestion'=>array('doc_type'=>'native_bank','doc_id'=>101))));
kreabankWorklistInvalidateScan($session,$effect);$continued=$session['kreabank_worklist_scan'];
cacheCheck(!isset($continued['summaries']['staged:1']) && !isset($continued['summaries']['staged:3']) && !isset($continued['summaries']['staged:4']),
	'Consumed, shared-target and previous no-pair amount/date peers are invalidated, including other owned banks');
cacheCheck(isset($continued['summaries']['staged:2'],$session['kreabank_worklist_reviews']['unrelated']) && !isset($session['kreabank_worklist_reviews']['shared']),
	'Unrelated completed records and reviews survive while dependent old approvals are removed');
cacheCheck(isset($continued['reuse_once']) && $continued['generation']!==$scan['generation'],'A server-authorized action grants one short continuation and fences existing workers');
$planning=array('kreabank_worklist_scan'=>$scan,'kreabank_worklist_reviews'=>array('new'=>array('payload'=>array('key'=>'staged:1'))));
kreabankWorklistInvalidateScan($planning,kreabankWorklistActionEffect('staged:1',array('line'=>$sources['staged:1']),false),false);
cacheCheck(isset($planning['kreabank_worklist_reviews']['new'],$planning['kreabank_worklist_scan']['summaries']['staged:3']),
	'Saving/reviewing a private draft retains the new one-use approval and invalidates only that row');

$changed=$sources;$changed['staged:2']['description']='Changed persisted evidence';$facts=kreabankWorklistSourceState($changed);
$rebased=kreabankWorklistRebaseScan($scan,$scan['context'],'changed-source-scope',$facts,time());
cacheCheck(!isset($rebased['summaries']['staged:2']) && isset($rebased['summaries']['staged:5']),'Changed source evidence invalidates its envelope while preserving unrelated progress');
foreach(array('another-user','another-company','changed-filter','changed-rights','changed-policy') as $context){
	$fresh=kreabankWorklistRebaseScan($scan,$context,'same-sources',kreabankWorklistSourceState($sources),time());
	cacheCheck(!$fresh['summaries'] && !$fresh['presentations'],'Actor/company/filter/rights/policy changes cannot retain presentation or completed summaries');
}
$expired=$scan;$expired['expires']=time()-1;$fresh=kreabankWorklistRebaseScan($expired,$scan['context'],$scan['scope'],kreabankWorklistSourceState($sources),time());
cacheCheck(!$fresh['summaries'] && !$fresh['presentations'],'Expired scan state starts fresh without reusing old financial presentation');

list($sources,$read,$scan)=cacheFixture();$key='staged:2';$scan['presentations'][$key]['expires']=100;
$read->calls=array();kreabankWorklistPreparedPresentation($scan,$key,$sources[$key],true,array($read,'compact'),101);
cacheCheck($read->calls===array($key),'Expired prepared data is refreshed through preparation rather than displayed');
$read->denied=true;$read->calls=array();$generation=$scan['generation'];$blocked=false;
try{kreabankWorklistPreparedPresentation($scan,$key,$sources[$key],true,array($read,'compact'),102,array($read,'presentationVisible'));}catch(RuntimeException $error){$blocked=true;}
cacheCheck($blocked && !$read->calls && !isset($scan['presentations'][$key]) && !isset($scan['summaries'][$key]) && $scan['generation']!==$generation,
	'Changed target visibility evicts private cached details and fences workers without matching or displaying a stale fallback');
$scan['presentations']['staged:1']['expires']=0;$failed=false;
try{kreabankWorklistPreparedPresentation($scan,'staged:1',$sources['staged:1'],true,static function(){throw new DomainException('Unavailable');},102);}catch(DomainException $error){$failed=true;}
cacheCheck($failed && !isset($scan['presentations']['staged:1']) && !isset($scan['summaries']['staged:1']),'A failed expired-envelope refresh cannot fall back to hidden stale details');
kreabankWorklistPresentationPage($scan,array('staged:5','staged:6'),2);
cacheCheck(count($scan['presentations'])<=2 && !isset($scan['presentations']['staged:7']),'Full financial envelopes remain bounded to the current native page');
cacheCheck(!array_filter($scan['summaries'],static function($summary){return isset($summary['_prepared']);}),'Compact off-page summaries never retain full prepared financial objects');
$batch=kreabankWorklistDependencies(array('line'=>$sources['staged:2'],'batch'=>array('active'=>array('components'=>array(array('rowid'=>810,'amount'=>'-1','dateo'=>'2031-11-10'))))));
cacheCheck(in_array('native_bank:810',$batch['tokens'],true),'Group effects retain each native component identity independently of the combined amount');
echo "OK: $checks worklist cache continuation checks\n";
