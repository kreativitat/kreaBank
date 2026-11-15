#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Pure selector contract/snapshot checks. No ERP bootstrap, installation or database.
 */
require_once __DIR__ . '/../class/KreaBankPaymentBatchTrait.php';
require_once __DIR__ . '/../class/KreaBankWorklistSelectionTrait.php';
require_once __DIR__ . '/../class/KreaBankPaymentBatchSuggestion.class.php';
require_once __DIR__ . '/../class/KreaBankMatcher.class.php';
require_once __DIR__ . '/../lib/kreabank_bulk.lib.php';
// Matching fixtures contain ASCII only; no application helper/bootstrap is needed.
function dol_string_nospecial($value) { return preg_replace('/[^a-zA-Z0-9 ]/', '', $value); }

// The shared scope boundary is observed here; its real SQL/pair policy has database suites.
class KreaBankReconciliationScope
{
	private $db;
	public function __construct($db,$entity) { $this->db=$db; if($entity!==7){throw new DomainException('Company changed');} }
	public function assertLine($line) { if($line['bank_account_id']!==101){throw new DomainException('Account changed');} }
	public function assertDocument($type,$id,$account) {
		$this->db->scopeCalls[]=$id;
		if($type!=='native_bank'||$account!==101||in_array($id,$this->db->scopeDenied,true)){throw new DomainException('Shared scope denied');}
	}
}
class SelectionFixtureDb
{
	public $queries=array(),$records=array(),$scopeCalls=array(),$scopeDenied=array(),$source,$meta=array(),$facts=array(),$writes=array();
	public function prefix(){return 'llx_';}
	public function plimit($limit=0,$offset=0){return ' LIMIT '.(int)$limit;}
	public function query($sql){
		$this->queries[]=$sql;$rows=array();
		if(strpos($sql,'DELETE FROM llx_kreabank_payment_batch_draft WHERE entity=7 AND fk_user=9 AND fk_statement_line=11')===0){$this->writes[]=$sql;return (object)array('rows'=>array());}
		if(strpos($sql,'SELECT ')!==0){throw new RuntimeException('Fixture forbids unexpected writes');}
		if(strpos($sql,'SELECT l.*,s.ref AS source_statement_ref')===0){$rows=array($this->source);}
		elseif(strpos($sql,'SELECT b.*,CAST(b.amount AS DECIMAL(24,8))')===0){
			$rows=array_values($this->records);
			if(preg_match('/ AND b.rowid=([0-9]+)/',$sql,$match)){$rows=array_filter($rows,static function($r)use($match){return $r['rowid']===(int)$match[1];});}
			if(preg_match('/ AND b.rowid<>([0-9]+)/',$sql,$match)){$rows=array_filter($rows,static function($r)use($match){return $r['rowid']!==(int)$match[1];});}
			$rows=array_filter($rows,static function($r)use($sql){return $r['rappro']===0 && strpos($sql,"b.dateo>='".substr($r['dateo'],0,10)."'")!==false;});
			if(strpos($sql,"LIKE '%nomatch%'")!==false){$rows=array();}
			$rows=array_slice(array_values($rows),0,strpos($sql,'LIMIT 101')!==false?101:1000);
		}
		else{foreach($this->facts as $needle=>$records){if(strpos($sql,$needle)!==false){$rows=$records;break;}}}
		return (object)array('rows'=>array_values($rows));
	}
	public function fetch_object($result){$row=array_shift($result->rows);return $row===null?false:(object)$row;}
}
class SelectionFixtureService
{
	use KreaBankPaymentBatchTrait;
	use KreaBankWorklistSelectionTrait;
	public $db,$user,$line,$groupRows=array(),$groupStatus=null,$providerDenied=array(),$schema=true,$grouping=true,$readAllowed=true,$warning=false,$draftTable=true;
	protected $entity=7,$matcher;
	public function __construct(){
		$this->db=new SelectionFixtureDb();$this->user=(object)array('id'=>9);$this->matcher=new KreaBankMatcher();
		$this->line=array('rowid'=>11,'entity'=>7,'fk_statement'=>5,'bank_account_id'=>101,'native_bank_line_id'=>0,'amount'=>-100.0,'allocated_amount'=>0.0,
			'operation_date'=>'2031-04-10','value_date'=>'2031-04-10','currency'=>'EUR','status'=>0,'is_duplicate'=>0,'direction'=>-1,
			'statement_ref'=>'SYNTHETIC','counterparty_name'=>'','counterparty_iban'=>'','description'=>'Synthetic payment','payment_reference'=>'','bank_reference'=>'');
		$this->db->source=array('rowid'=>11,'entity'=>7,'fk_statement'=>5,'bank_account_id'=>101,'amount'=>'-100.00000000','allocated_amount'=>'0.00000000',
			'operation_date'=>'2031-04-10','value_date'=>'2031-04-10','currency'=>'EUR','source_statement_currency'=>'EUR','source_statement_ref'=>'SYNTHETIC',
			'source_type'=>'csv','bank_currency'=>'EUR','bank_account_ref'=>'SYNTHETIC','status'=>0,'is_duplicate'=>0,'fk_native_bank_line'=>null);
	}
	public function native($id,$amount='-100.00000000',$date='2031-04-10'){
		$this->db->records[$id]=array('rowid'=>$id,'fk_account'=>101,'amount'=>$amount,'amount_decimal'=>$amount,'dateo'=>$date,'datev'=>$date,
			'currency'=>'EUR','bank_account_ref'=>'SYNTHETIC','rappro'=>0,'num_releve'=>'','label'=>'Synthetic native movement','num_chq'=>'FIXTURE-'.$id,'note'=>'');
	}
	public function legacy(){
		$this->line['fk_statement']=0;$this->line['native_bank_line_id']=11;
		$this->db->source=array('rowid'=>11,'fk_account'=>101,'bank_account_id'=>101,'amount'=>'-100.00000000','dateo'=>'2031-04-10','rappro'=>0,'bank_currency'=>'EUR','bank_account_ref'=>'SYNTHETIC','num_releve'=>'SYNTHETIC','note'=>'KreaBank import fixture.csv');
		$this->db->meta=array(array('rowid'=>44,'entity'=>7,'fk_bank_line'=>11,'source_type'=>'csv','description'=>'Synthetic imported evidence'));
	}
	private function assertPaymentBatchActor($readOnly=false){if(!$this->readAllowed){throw new DomainException('Permission denied');}}
	public function getBulkReviewLine($id,$source){return $id===11?$this->line:null;}
	public function hasPaymentBatchSchema(){return $this->schema;}
	private function paymentBatchSource($id){if(!$this->grouping){throw new DomainException('No groups for this source');}return $this->line+array('allocated_amount'=>'0');}
	public function listBatchCandidates($id,$search=''){return array('source'=>$this->line,'rows'=>$this->groupRows,'truncated'=>$this->groupStatus==='truncated','search'=>$search);}
	public function getPaymentBatchSuggestion($id,array $candidates){
		if($this->groupStatus!==null){return array('status'=>$this->groupStatus,'ids'=>$this->groupStatus==='unique'?array(31,32):array());}
		$source=$this->line;$source['amount']='-100.00000000';$source['allocated_amount']='0';
		return KreaBankPaymentBatchSuggestion::suggest($source,$this->groupRows);
	}
	public function getPaymentBatchSelectedCandidates($id,$ids){$rows=array_values(array_filter($this->groupRows,static function($r)use($ids){return in_array($r['rowid'],$ids,true);}));return array('rows'=>$rows,'unavailable'=>array_values(array_diff($ids,array_column($rows,'rowid'))));}
	protected function getOpenNativeBankDocuments($direction,$limit,$date,$days,$exclude,$account,$id){
		if(!isset($this->db->records[$id])||in_array($id,$this->providerDenied,true)){return array();}$r=$this->db->records[$id];
		return array(array('rowid'=>$id,'doc_type'=>'native_bank','ref'=>$r['num_chq'],'amount_open'=>(float)$r['amount_decimal'],'amount_decimal'=>$r['amount_decimal'],
			'doc_date'=>$r['dateo'],'bank_account_id'=>101,'bank_account_ref'=>'SYNTHETIC','currency'=>'EUR','description'=>$r['label'],'thirdparty_name'=>'','thirdparty_iban'=>'','payment_ref'=>''));
	}
	protected function getPatterns(){return array();}
	public function getPaymentAccountReview($id,$accounts){return $this->warning;}
	private static function automaticDecimal($value){return is_float($value)?number_format($value,8,'.',''):KreaBankPaymentBatchDecimal::normalize($value);}
	private function automaticFeedEvidenceValid($id,$lock=false){return false;}
	protected function paymentBatchUnclaimedSql(){return ' AND /* active claims checked */ 1=1';}
	private function paymentBatchOwnershipSql($groupOnly=true){return ' AND /* current parent ownership checked */ 1=1';}
	protected function sqlText($s){return "'".str_replace("'","''",$s)."'";}
	protected function tableExists($table){return $this->draftTable;}
	public function locked($pair){$this->assertWorklistSelectionReview($pair,$this->line);}
	public function clearDraft($pair){$this->clearWorklistSelectionDraft($pair,$this->line);}
}
$checks=0;
function selectionCheck($ok,$label){global $checks;if(!$ok){throw new RuntimeException($label);}$checks++;}
function selectionReject($call,$label){$caught=false;try{$call();}catch(Throwable $error){$caught=true;}selectionCheck($caught,$label);}
function selectionGroup($id,$amount){return array('rowid'=>$id,'entity'=>7,'bank_account_id'=>101,'amount_decimal'=>$amount,'currency'=>'EUR','doc_date'=>'2031-04-10','ref'=>'FIXTURE-'.$id,'payment_refs'=>'','bank_account_ref'=>'SYNTHETIC','description'=>'Synthetic component');}

$s=new SelectionFixtureService();$s->native(21);$listed=$s->listWorklistSelectionCandidates('staged:11');
selectionCheck($listed['suggestion']['status']==='unique'&&$listed['suggestion']['ids']===array(21),'A unique native singleton is advisory');
selectionCheck($listed['rows'][0]['single_eligible']&&!$listed['rows'][0]['group_eligible'],'Scalar native eligibility does not grant group eligibility');
selectionCheck(!$s->db->writes,'Discovery never writes a draft or financial record');
$s->groupRows=array(selectionGroup(31,'-40'),selectionGroup(32,'-60'));
selectionCheck($s->listWorklistSelectionCandidates('staged:11')['suggestion']['status']==='ambiguous','An exact singleton competes with an exact multi-member group');
$s->db->records=array();selectionCheck($s->listWorklistSelectionCandidates('staged:11')['suggestion']['ids']===array(31,32),'Existing unique multi-member advice is preserved');
$s->native(21);$s->groupStatus='bounded';selectionCheck($s->listWorklistSelectionCandidates('staged:11')['suggestion']['status']==='bounded','An unfinished group search cannot authorize singleton preselection');
$s->groupStatus='truncated';selectionCheck($s->listWorklistSelectionCandidates('staged:11')['suggestion']['status']==='truncated','Truncated groups fail closed');
$s->groupStatus=null;$s->groupRows=array();$s->native(22);
selectionCheck($s->listWorklistSelectionCandidates('staged:11')['suggestion']['status']==='ambiguous','Two exact native singletons stay unselected');
selectionCheck($s->listWorklistSelectionCandidates('staged:11','nomatch')['suggestion']['status']==='filtered','Search never establishes automatic uniqueness');
$s->db->records=array();for($i=100;$i<201;$i++){$s->native($i);}
$listed=$s->listWorklistSelectionCandidates('staged:11');selectionCheck($listed['truncated']&&$listed['suggestion']['ids']===array()&&count($listed['rows'])===100,'Bounded native discovery advertises truncation');
$s=new SelectionFixtureService();$s->grouping=false;$s->native(21);$s->native(22,'-100.00000000','2031-04-11');
selectionCheck(count($s->listWorklistSelectionCandidates('staged:11')['rows'])===1,'Adjacent-day native movements are excluded');
selectionCheck($s->getWorklistSelectionMembers('staged:11',array(21))['unavailable']===array(),'A valid single remains selectable when grouping is unavailable');
selectionCheck($s->getWorklistSelectionMembers('staged:11',array(22))['unavailable']===array(22),'Retained scalar selections cannot carry another booking day');
selectionCheck($s->getWorklistSelectionMembers('staged:11',array(21,22))['unavailable']===array(21,22),'Multiple selections cannot borrow scalar eligibility');
selectionReject(static function()use($s){$s->getWorklistSelectionMembers('staged:11',array(21,21));},'Duplicate native IDs never inflate a selection');
selectionReject(static function()use($s){$s->listWorklistSelectionCandidates('11');},'Source namespaces stay explicit');
$s->schema=false;selectionCheck(!$s->listWorklistSelectionCandidates('staged:11')['grouping_available'],'Scalar selection works without optional group schema');
$pair=$s->prepareWorklistSelectionPair('staged:11',21);$s->locked($pair);
selectionCheck($pair['approval_context']==='selected_native'&&!$pair['preselected']&&!$pair['is_bulk_safe'],'Explicit selection produces the ordinary manual row shape');
selectionCheck($pair['worklist_selection']['target']['record']['amount_decimal']==='-100.00000000','Review retains exact persisted native amounts');
selectionCheck((bool)array_filter($s->db->queries,static function($q){return strpos($q,'FOR UPDATE')!==false;}),'Execution revalidation requests row locks');
$s->warning=true;selectionCheck($s->prepareWorklistSelectionPair('staged:11',21)['other_account_warning'],'Existing native approval retains the signed wrong-account warning');$s->warning=false;
$scope='7|9|staged:11';$expires=time()+600;$secret=str_repeat('x',64);$signature=kreabankBulkReviewSignature($pair,$scope,$expires,$secret);
$changed=$pair;$changed['worklist_selection']['target']['record']['dateo']='2031-04-11';
selectionCheck(!kreabankBulkReviewIsValid($changed,$scope,$expires,$signature,$secret,time(),true,101),'The standard signature binds selector exact/date provenance');
foreach(array('dateo'=>'2031-04-11','amount_decimal'=>'-99.99999999','label'=>'Edited native evidence','rappro'=>1)as$field=>$value){
	$before=$s->db->records[21][$field];$s->db->records[21][$field]=$value;
	selectionReject(static function()use($s,$pair){$s->locked($pair);},'Locked execution rejects native change: '.$field);$s->db->records[21][$field]=$before;
}
$s->db->source['operation_date']='2031-04-11';selectionReject(static function()use($s,$pair){$s->locked($pair);},'Locked execution rejects source date edits');$s->db->source['operation_date']='2031-04-10';
$s->db->facts['FROM llx_paiement WHERE fk_bank=21']=array(array('rowid'=>71,'amount'=>'100'));
selectionReject(static function()use($s,$pair){$s->locked($pair);},'Locked execution rejects payment evidence edits');$s->db->facts=array();
$s->db->facts['SELECT DISTINCT c.*']=array(array('rowid'=>81,'amount'=>'100.00000000'));
selectionReject(static function()use($s,$pair){$s->locked($pair);},'Locked execution also binds owned transfer counterpart evidence');$s->db->facts=array();
$s->db->scopeDenied=array(21);selectionReject(static function()use($s){$s->prepareWorklistSelectionPair('staged:11',21);},'The shared scope can deny a restricted transfer');$s->db->scopeDenied=array();
$s->providerDenied=array(21);selectionReject(static function()use($s){$s->prepareWorklistSelectionPair('staged:11',21);},'The shared native provider remains authoritative');$s->providerDenied=array();
selectionReject(static function()use($s){$s->prepareWorklistSelectionPair('staged:11',22);},'Explicit native ID lookup never widens the booking day');
$s->clearDraft($pair);selectionCheck(count($s->db->writes)===1&&strpos($s->db->writes[0],'fk_user=9')!==false,'Scalar completion deletes only the current reviewer draft');
$s->draftTable=false;$s->clearDraft($pair);selectionCheck(count($s->db->writes)===1,'Missing optional draft schema is left untouched');
$s->draftTable=true;$s->clearDraft(array());selectionCheck(count($s->db->writes)===1,'Other reconciliation entrypoints never clear drafts');
$s->legacy();$queriesBeforeLegacy=count($s->db->queries);
selectionReject(static function()use($s){$s->listWorklistSelectionCandidates('native:11');},'Legacy ledger imports cannot discover replacement native targets through the unified selector');
selectionReject(static function()use($s){$s->getWorklistSelectionMembers('native:11',array(21));},'Retained legacy selector members cannot reopen a replacement ledger mapping');
selectionReject(static function()use($s){$s->prepareWorklistSelectionPair('native:11',21);},'Legacy imported bank evidence cannot be remapped to a distinct native movement');
selectionReject(static function()use($s){$s->prepareWorklistSelectionPair('native:11',11);},'Legacy own-ledger identity cannot masquerade as a staged selection');
$oldLegacy=$pair;$oldLegacy['line']=$s->line;$oldLegacy['worklist_selection']['key']='native:11';
selectionReject(static function()use($s,$oldLegacy){$s->locked($oldLegacy);},'Previously displayed legacy selector approvals fail closed at the execution assertion');
selectionReject(static function()use($s,$pair){$s->locked($pair);},'An overlapping native source ID cannot execute a staged selector review');
selectionCheck(count($s->db->queries)===$queriesBeforeLegacy,'Unsupported legacy selector requests stop before candidate SQL or financial changes');
$s->db->meta=array();$s->db->source['note']='KreaBank staged import csv / fixture.csv';
selectionReject(static function()use($s){$s->prepareWorklistSelectionPair('native:11',21);},'Historical imports without metadata retain the same staged-only selector boundary');
$s->readAllowed=false;selectionReject(static function()use($s){$s->listWorklistSelectionCandidates('staged:11');},'Selector discovery enforces the existing actor gate');
$runtime=file_get_contents(__DIR__.'/../class/KreaBankService.class.php');
$hook=strpos($runtime,'$this->assertWorklistSelectionReview($reviewedRow, $line)');
selectionCheck($hook>strpos($runtime,'$this->lockReconciliationRow(\'bank\', (int) $selectedNativeBankLink[\'fk_doc\'])')&&$hook<strpos($runtime,'$resolvedNativeBankLine = $this->resolveExistingNativeBankLineForReconciliation('),'The selector assertion is inside existing source/native locks before execution');
$cleanup=strpos($runtime,'$this->clearWorklistSelectionDraft($reviewedRow, $line)');
selectionCheck($cleanup>strpos($runtime,'$this->finalizeNativeReconciliation($line, $nativeLineId, $isWriteOff')&&$cleanup<strpos($runtime,"if (!\$this->db->commit())",$cleanup),'Draft cleanup participates in the common atomic completion');
echo 'OK: '.$checks." unified worklist selection checks (synthetic, no database)\n";
