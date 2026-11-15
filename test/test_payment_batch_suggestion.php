#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Pure subset checks and SELECT-only trait fixtures; no ERP bootstrap/database.
 */
require_once __DIR__ . '/../class/KreaBankPaymentBatchSuggestion.class.php';
require_once __DIR__ . '/../class/KreaBankTaxReconciliation.class.php';

$checks = 0;
function suggestionCheck($ok, $label) { global $checks; if (!$ok) { throw new RuntimeException($label); } $checks++; }
function suggestionRow($id, $amount, $changes = array()) {
	return array_replace(array('rowid'=>$id, 'entity'=>7, 'bank_account_id'=>101, 'bank_account_ref'=>'SYNTHETIC',
		'currency'=>'EUR', 'amount_decimal'=>$amount, 'doc_date'=>'2031-04-10', 'description'=>'Synthetic existing payment',
		'ref'=>'FIXTURE-' . $id, 'payment_refs'=>'', 'url_types'=>''), $changes);
}
$source = array('rowid'=>11, 'entity'=>7, 'bank_account_id'=>101, 'currency'=>'EUR', 'amount'=>'-100.00000000',
	'operation_date'=>'2031-04-10', 'statement_ref'=>'SYNTHETIC-STATEMENT', 'allocated_amount'=>'0', 'status'=>0, 'is_duplicate'=>0, 'fk_native_bank_line'=>null);
$rows = array(suggestionRow(21,'-40'), suggestionRow(22,'-60'), suggestionRow(23,'-13'));
$suggest = static function ($rows, $changes = array(), $truncated = false) use ($source) {
	return KreaBankPaymentBatchSuggestion::suggest(array_replace($source,$changes), $rows, $truncated);
};
$unique = $suggest($rows);
suggestionCheck($unique['status']==='unique' && $unique['ids']===array(21,22), 'A unique exact two-payment sum is suggested');
suggestionCheck($suggest(array_reverse($rows))===$unique, 'Selection is deterministic in native ID order');
suggestionCheck($suggest(array(suggestionRow(21,'-40'),suggestionRow(22,'-60'),suggestionRow(23,'-30'),suggestionRow(24,'-70')))['status']==='ambiguous', 'A later second solution invalidates the first exact sum');
suggestionCheck($suggest(array(suggestionRow(21,'-40'),suggestionRow(22,'-40'),suggestionRow(23,'-60')))['status']==='ambiguous', 'Equal amounts with different native IDs are competing subsets');
suggestionCheck($suggest(array(suggestionRow(21,'-100')))['ids']===array(), 'One exact movement is not a payment batch');
suggestionCheck($suggest(array(suggestionRow(21,'-100'),suggestionRow(22,'-40'),suggestionRow(23,'-60')))['ids']===array(22,23), 'Single-member matches are excluded from the two-or-more group search');
suggestionCheck($suggest(array(suggestionRow(21,'-49.99999999'),suggestionRow(22,'-50')))['status']==='none', 'A one-unit decimal remainder never becomes a rounded exact sum');
suggestionCheck($suggest(array(suggestionRow(21,'-0.00000001'),suggestionRow(22,'-0.00000002')),array('amount'=>'-0.00000003'))['ids']===array(21,22), 'Sub-cent evidence retains all eight decimals');
suggestionCheck($suggest(array(suggestionRow(21,'4999999999999999.99999999'),suggestionRow(22,'5000000000000000')),array('amount'=>'9999999999999999.99999999'))['ids']===array(21,22), 'Exact sums exceed integer and IEEE-754 ranges safely');
suggestionCheck($suggest(array(suggestionRow(21,'-6000000000000000'),suggestionRow(22,'-6000000000000000'),suggestionRow(23,'-6000000000000000')),array('amount'=>'-9000000000000000'))['status']==='none', 'An oversized candidate-universe total never overflows the exact subset comparison');
suggestionCheck($suggest(array(suggestionRow(21,'40'),suggestionRow(22,'60')),array('amount'=>'100'))['ids']===array(21,22), 'Inflow batches use the same exact signed contract');
suggestionCheck($suggest($rows,array(),true)['status']==='truncated' && !$suggest($rows,array(),true)['ids'], 'A truncated candidate universe cannot establish uniqueness');
suggestionCheck($suggest(array(suggestionRow(21,'-40'),suggestionRow(21,'-60')))['status']==='invalid', 'Repeated native IDs cannot inflate a subset');
foreach (array('entity'=>8,'bank_account_id'=>102,'currency'=>'USD','doc_date'=>'2031-04-09','amount_decimal'=>'40') as $field=>$value) {
	$bad = $rows; $bad[0][$field]=$value;
	suggestionCheck($suggest($bad)['status']==='invalid' && !$suggest($bad)['ids'], 'Invalid candidate scope/date/direction fails closed: ' . $field);
}
foreach (array('status'=>2,'is_duplicate'=>1,'fk_native_bank_line'=>88,'allocated_amount'=>'0.01','amount'=>'-1e2','operation_date'=>'2031-02-30') as $field=>$value) {
	suggestionCheck($suggest($rows,array($field=>$value))['status']==='invalid', 'Invalid source evidence fails closed: ' . $field);
}
suggestionCheck($suggest(array(suggestionRow(21,'-40',array('doc_date'=>'2031-04-10 00:00:00')),suggestionRow(22,'-60',array('doc_date'=>'2031-04-10 23:59:59'))))['ids']===array(21,22), 'Booking-day endpoints retain same-day timestamps');
foreach (array(0.1,'1e2','1,00','10000000000000000','1.000000001') as $badAmount) {
	suggestionCheck($suggest(array(suggestionRow(21,$badAmount),suggestionRow(22,'-60')))['status']==='invalid', 'Malformed or rounded input amounts are rejected');
}
$many = array(); for ($i=0;$i<=KreaBankPaymentBatchSuggestion::MAX_CANDIDATES;$i++) { $many[]=suggestionRow(100+$i,'-1'); }
suggestionCheck($suggest($many)['status']==='bounded' && !$suggest($many)['ids'], 'Candidate limits do not silently prune potential alternatives');
$powers = array(); for ($i=0;$i<16;$i++) { $powers[]=suggestionRow(200+$i,'-' . (1 << $i)); }
suggestionCheck($suggest($powers,array('amount'=>'-40000'))['status']==='bounded' && !$suggest($powers,array('amount'=>'-40000'))['ids'], 'State limits return no preselection instead of pruning ambiguity');
$nearTotal = array(); for ($i=0;$i<17;$i++) { $nearTotal[]=suggestionRow(400+$i,'-' . (1 << $i)); }
$nearSuggestion=$suggest($nearTotal,array('amount'=>'-131070'));
suggestionCheck($nearSuggestion['status']==='unique' && $nearSuggestion['ids']===range(401,416), 'Exact complementary search proves a unique sixteen-of-seventeen near-total batch');
$nearTotal[1]['amount_decimal']='-1';
suggestionCheck($suggest($nearTotal,array('amount'=>'-131069'))['status']==='ambiguous', 'Competing excluded remainders remain ambiguous in complementary search');

// Independent exhaustive oracle for small groups, including repeated amounts.
foreach (array(array(1,2,3,4),array(1,1,2,3),array(2,3,5,7,8)) as $amounts) {
	foreach (range(1,15) as $target) {
		$expected=array(); $candidates=array();
		foreach ($amounts as $index=>$amount) { $candidates[]=suggestionRow(300+$index,'-' . $amount); }
		for ($mask=1;$mask<(1 << count($amounts));$mask++) {
			if (substr_count(decbin($mask),'1')<2) { continue; }
			$total=0; $ids=array();
			foreach ($amounts as $index=>$amount) { if ($mask & (1 << $index)) { $total+=$amount; $ids[]=300+$index; } }
			if ($total===$target) { $expected[]=$ids; }
		}
		$actual=$suggest($candidates,array('amount'=>'-' . $target));
		$status=count($expected)>1?'ambiguous':(count($expected)===1?'unique':'none');
		suggestionCheck($actual['status']===$status && $actual['ids']===($status==='unique'?$expected[0]:array()), 'Bounded DP agrees with independent exhaustive subsets');
	}
}

class SuggestionReadDb
{
	public $queries=array(); public $records=array();
	public function prefix() { return 'llx_'; }
	public function plimit($limit=0,$offset=0) { return ' LIMIT ' . (int) $limit; }
	public function query($sql) {
		if (strpos($sql,'SELECT ')!==0) { throw new RuntimeException('Suggestion fixture refuses all writes'); }
		$this->queries[]=$sql; $rows=array();
		if (strpos($sql,'SELECT b.rowid,ba.entity,')===0) { $rows=array_values($this->records); }
		elseif (strpos($sql,'SELECT b.*,CAST(')===0 && preg_match('/ AND b.rowid=([0-9]+)/',$sql,$match)) {
			$row=$this->records[(int)$match[1]]??null;
			if ($row && (int)$row['rappro']===(strpos($sql,'AND b.rappro=1')!==false?1:0)) { $rows=array($row); }
		}
		if (strpos($sql,"b.dateo>='2031-04-10'")!==false && strpos($sql,"b.dateo<DATE_ADD('2031-04-10',INTERVAL 1 DAY)")!==false) {
			$rows=array_values(array_filter($rows,static function($row){return substr($row['dateo'],0,10)==='2031-04-10';}));
		}
		return (object)array('rows'=>$rows);
	}
	public function fetch_object($result) { $row=array_shift($result->rows); return $row===null?false:(object)$row; }
}
class SuggestionTraitHarness
{
	use KreaBankPaymentBatchTrait;
	protected $entity=7; protected $user;
	public $source; public $db; public $readAllowed=true;
	public function __construct($source,$rows) {
		$this->source=$source; $this->db=new SuggestionReadDb();
		$this->user=new class { public function hasRight(...$rights) { return true; } };
		foreach ($rows as $row) {
			$row+=array('fk_account'=>$row['bank_account_id'],'amount'=>$row['amount_decimal'],'exact_amount'=>$row['amount_decimal'],
				'dateo'=>$row['doc_date'],'datev'=>'2031-04-10','rappro'=>0,'num_releve'=>'SYNTHETIC-STATEMENT','label'=>$row['description'],'num_chq'=>$row['ref']);
			$this->db->records[$row['rowid']]=$row;
		}
	}
	protected function assertAutomaticActor($readOnly=false) { if (!$this->readAllowed) { throw new DomainException('Synthetic read denial'); } }
	private function paymentBatchSource($id,$allowActive=false,$lock=false) { if ($id!==11) { throw new DomainException('Unknown synthetic source'); } return $this->source; }
	protected function sqlText($text) { return "'" . str_replace("'","''",$text) . "'"; }
	public function activeComponent($id) { return $this->paymentBatchComponent($id,$this->source,9); }
}
$harness = new SuggestionTraitHarness($source,array($rows[0],$rows[1],suggestionRow(24,'-40',array('doc_date'=>'2031-04-09')),suggestionRow(25,'-60',array('doc_date'=>'2031-04-11'))));
$listed=$harness->listBatchCandidates(11);
suggestionCheck(array_column($listed['rows'],'rowid')===array(21,22), 'Discovery SQL excludes both neighboring booking days');
$sql=$harness->db->queries[0];
suggestionCheck(strpos($sql,'WHERE ba.entity=7 AND b.fk_account=101')!==false && strpos($sql,"b.dateo>='2031-04-10'")!==false
	&& strpos($sql,"b.dateo<DATE_ADD('2031-04-10',INTERVAL 1 DAY)")!==false, 'Discovery shares indexed account/company/calendar bounds');
suggestionCheck(strpos($sql,'kreabank_statement_line')!==false && strpos($sql,'active_bank_line=b.rowid')!==false && strpos($sql,'pay.entity<>7')!==false, 'Discovery retains staging, batch and parent-payment ownership guards');
suggestionCheck($harness->getPaymentBatchSuggestion(11,$listed)['ids']===array(21,22), 'Read-only service exposes the unique complete unsearched suggestion');
$searched=$harness->listBatchCandidates(11,'Synthetic');
suggestionCheck($harness->getPaymentBatchSuggestion(11,$searched)['status']==='filtered', 'A narrowed search cannot conceal competing subsets');
$retained=$harness->getPaymentBatchSelectedCandidates(11,array(21,24));
suggestionCheck(array_column($retained['rows'],'id')===array(21) && $retained['unavailable']===array(24), 'Old off-day draft members remain explicit unavailable selections');
$harness->db->records[24]['rappro']=1;
suggestionCheck($harness->activeComponent(24)['doc_date']==='2031-04-09', 'Historical active members retain their off-day evidence for undo');
$harness->source['amount']='-99';
suggestionCheck($harness->getPaymentBatchSuggestion(11,$listed)['status']==='changed', 'Changed source evidence invalidates initial suggestion data');
$harness->readAllowed=false; $denied=false;
try { $harness->getPaymentBatchSuggestion(11,$listed); } catch (DomainException $e) { $denied=true; }
suggestionCheck($denied, 'Suggestion uses the existing actor admission gate');
echo 'OK: ' . $checks . " payment batch suggestion checks (synthetic, no database)\n";
