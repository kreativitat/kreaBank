#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Execute the actual initial-selection controller region; no bootstrap or database.
 */
$source=file_get_contents(__DIR__.'/../reconciliationlist.php');
$start=strpos($source,"\t\t\t\t\t\$candidates = \$service->listWorklistSelectionCandidates(");
$end=strpos($source,"\t\t\t\t\t\$batchCandidates = array();",$start);
if($start===false||$end===false){throw new LogicException('Initial batch controller boundaries changed');}
$branch=substr($source,$start,$end-$start);
$checks=0;
$check=static function($ok,$label)use(&$checks){if(!$ok){throw new RuntimeException($label);}$checks++;};
foreach(array('fresh','stored','cleared','inputs','ambiguous','filtered','truncated','bounded') as $case){
	$key='staged:11';$line=array('rowid'=>11);$search=$case==='filtered'?'Explicit search':'';
	$row=array();$_SESSION=array();
	if($case==='stored'){$row['draft']=array('name'=>'Saved choice','member_keys'=>array('91','92'));}
	if($case==='cleared'){$row['draft']=array('name'=>'Cleared choice','member_keys'=>array());}
	if($case==='inputs'){$_SESSION['kreabank_worklist_inputs'][$key]=array('name'=>'Retained input','ids'=>array());}
	$before=$_SESSION;
	$service=new class($case){
		public $calls=0;public $selected;private $case;
		public function __construct($case){$this->case=$case;}
		public function listWorklistSelectionCandidates($key,$search){$this->calls++;return array('search'=>$search,'grouping_available'=>true,'suggestion'=>array('status'=>in_array($this->case,array('fresh','stored','cleared','inputs'),true)?'unique':$this->case,'ids'=>array(21,22)));}
		public function getPaymentBatchSuggestion($id,$result){$this->calls++;return array('status'=>in_array($this->case,array('fresh','stored','cleared','inputs'),true)?'unique':$this->case,'ids'=>array(21,22));}
		public function getWorklistSelectionMembers($key,$ids){$this->selected=$ids;return array('rows'=>array(),'unavailable'=>array());}
	};
	eval($branch);
	$check($_SESSION===$before,'GET advice never saves or overwrites a private draft: '.$case);
	if(in_array($case,array('stored','cleared','inputs'),true)){
		$check($service->calls===1,'Provider loads while existing user choice takes precedence: '.$case);
		$check($service->selected===($case==='stored'?array(91,92):array()),'Retained/cleared IDs survive unchanged: '.$case);
	}else{
		$check($service->calls===1,'Fresh initial opening asks once for advice: '.$case);
		$check($service->selected===($case==='fresh'?array(21,22):array()),'Only a proved unique subset becomes advisory checkboxes: '.$case);
	}
}
echo "OK: $checks initial batch selection checks (synthetic, no database)\n";
