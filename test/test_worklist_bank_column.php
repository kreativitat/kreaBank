#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Actual list row-model branch with synthetic reads; no ERP bootstrap/database.
 */
require_once __DIR__.'/../lib/kreabank_worklist.lib.php';
require_once __DIR__.'/../lib/kreabank_worklist_view.lib.php';
$checks=0;
function bankColumnCheck($value,$message) { global $checks; if(!$value)throw new RuntimeException($message);$checks++; }
function bankColumnModel($review,$options=array())
{
	$line=array('rowid'=>11,'fk_statement'=>1,'bank_account_id'=>101,'bank_account_ref'=>'SOURCE-BANK','account_open'=>true,
		'status'=>0,'currency'=>'EUR','amount'=>'-105.14000000','amount_decimal'=>'-105.14000000','operation_date'=>'2031-08-20');
	$prepared=array('line'=>$line,'account_review'=>$review,'account_candidates'=>$review['candidates'],'pair'=>null,'batch'=>array(),'completed'=>null);
	$worklist=new class($prepared) {
		private $prepared;
		public function __construct($prepared) {$this->prepared=$prepared;}
		public function prepare($key) {return $this->prepared;}
		public function compact($key) {return array('key'=>$key,'_prepared'=>$this->prepared);}
	};
	$native=array('rowid'=>81,'fk_account'=>102,'target_account_id'=>101,'snapshot'=>'exact-native-source-parent-facts','can_move'=>true);
	$native=array_merge($native,$options['native']??array());
	$service=new class($native) {
		private $native; public $reads=array();
		public function __construct($native) {$this->native=$native;}
		public function searchOtherAccountMovements($id,$search,$native) {$this->reads[]=array($id,$search,$native);return array($this->native);}
	};
	$langs=new class {public function trans($key){return $key;}};
	$page=array('rows'=>array(array('key'=>'staged:11','line'=>$line)));
	$sourceKeys=array('staged:11');
	$cache=array('summaries'=>array('staged:11'=>array()));$expand=!empty($options['expanded'])?'staged:11':'';$mode='batch';$search='';
	$canWrite=empty($options['readonly']);$canMove=$canWrite;$canCreate=$canWrite;$canUndo=false;$baseParams=array('search_account'=>'101');
	$remembered=array();$remember=static function($kind,$payload)use(&$remembered){$remembered[]=array($kind,$payload);return 'synthetic-review';};
	$paymentUrl=$candidateModel=$pairDescriptor=static function(){throw new LogicException('A bank warning cannot create a financial approval');};
	$_SESSION=array();$modelRows=array();
	$source=file_get_contents(__DIR__.'/../reconciliationlist.php');
	$start=strpos($source,'foreach ($page[\'rows\'] as $indexRow)');$end=strpos($source,"\nob_start();",$start);
	if($start===false||$end===false)throw new LogicException('Row preparation boundaries changed');
	eval(substr($source,$start,$end-$start));
	return array($modelRows[0],$remembered,$service->reads);
}
$candidate=array('bank_line_id'=>81,'bank_account_id'=>102,'account_ref'=>'OTHER-BANK','account_label'=>'Other bank',
	'payment_type'=>'native_bank','payment_id'=>81,'payment_ref'=>'#81','reference'=>'Existing native payment',
	'date'=>'2031-08-20','amount'=>'-105.14000000','reconciled'=>false);
$review=array('evidence'=>'amount_date','candidates'=>array($candidate),'truncated'=>false);
foreach(array(false,true) as $expanded) {
	list($row,$proof,$reads)=bankColumnModel($review,array('expanded'=>$expanded));
	bankColumnCheck($row['account_mismatch'] && empty($row['review']) && !$row['create_available'],'Weak evidence stays outside Match and blocks new creation');
	bankColumnCheck(count($row['bank_reviews'])===1 && $row['bank_reviews'][0]['possible'],'Collapsed and expanded rows carry the same possible-bank warning');
	bankColumnCheck($proof[0]===array('move',array('key'=>'staged:11','bank_line'=>81,'snapshot'=>'exact-native-source-parent-facts','old'=>102,'target'=>101)),
		'The column correction signs only the exact displayed native/source/account facts');
	bankColumnCheck($reads===array(array(11,'',81)) && empty($row['other_accounts']) && empty($row['batch_form']),
		'The exact correction remains independent of the retired batch section and selection');
}
foreach(array(array('readonly'=>true),array('native'=>array('can_move'=>false)),array('native'=>array('fk_account'=>103))) as $options) {
	list($row,$proof)=bankColumnModel($review,$options);
	bankColumnCheck(count($row['bank_reviews'])===1 && empty($row['bank_reviews'][0]['correction']) && !$proof,
		'Readonly, locked and changed-account records keep the warning without a financial form');
}
$ambiguous=$review;$ambiguous['candidates'][]=array_merge($candidate,array('bank_line_id'=>82,'payment_id'=>82,'payment_ref'=>'#82'));
list($row,$proof,$reads)=bankColumnModel($ambiguous);
bankColumnCheck(count($row['bank_reviews'])===2 && $row['ambiguous'] && !$proof && !$reads,'Same-bank duplicates remain distinct, with no arbitrary correction chosen');
$truncated=$review;$truncated['truncated']=true;
list($row,$proof,$reads)=bankColumnModel($truncated);
bankColumnCheck($row['account_mismatch'] && !$proof && !$reads && !empty($row['notice']),'A truncated search never creates an executable correction');
$strong=$review;unset($strong['evidence']);list($row,$proof)=bankColumnModel($strong);
bankColumnCheck(!empty($row['review']) && count($row['bank_reviews'])===1 && $proof,'Strong Match evidence and bank correction remain independent column models');
echo 'OK: '.$checks." bank-column controller checks (synthetic, no database)\n";
