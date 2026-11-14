#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Pure exact-arithmetic and signed-review checks; no ERP bootstrap or database.
 */
require_once __DIR__ . '/../class/KreaBankPaymentBatchTrait.php';
$checks = 0;
function batchCheck($condition, $label) { global $checks; if (!$condition) { throw new RuntimeException($label); } $checks++; }
function batchReject($callable, $label) { $caught=false; try { $callable(); } catch (Throwable $e) { $caught=true; } batchCheck($caught,$label); }
batchCheck(KreaBankPaymentBatchDecimal::sum(array('0.10','0.20')) === '0.30000000', 'Decimal addition must avoid binary rounding');
batchCheck(KreaBankPaymentBatchDecimal::sum(array('-1000.00000001','-2000.00000002')) === '-3000.00000003', 'Signed salary totals retain eight decimals');
batchCheck(KreaBankPaymentBatchDecimal::sum(array('9999999999999999.99999999','-9999999999999999.99999998')) === '0.00000001', 'Maximum supported decimal values retain the final unit');
batchCheck(KreaBankPaymentBatchDecimal::sum(array('-0.00000001','-0.00000002','0.00000003')) === '0.00000000', 'Mixed signs cancel exactly');
batchCheck(KreaBankPaymentBatchDecimal::sum(array('9999999999999999.99999999','9999999999999999.99999999')) === '19999999999999999.99999998', 'Intermediate sums never overflow platform integers');
batchCheck(KreaBankPaymentBatchDecimal::normalize('-000.0000') === '0.00000000', 'Negative zero is canonical');
foreach (array(0.1,'1e3','1,00',' 1.00','10000000000000000','1.123456789','--2','NaN') as $bad) { batchReject(static function () use ($bad) { KreaBankPaymentBatchDecimal::normalize($bad); }, 'Malformed or already-rounded amounts are rejected'); }
class BatchReviewHarness
{
	use KreaBankPaymentBatchTrait;
	protected $entity=7;
	protected $user;
	public function __construct($id=9) { $this->user=(object)array('id'=>$id); }
	public function issue($review) { return $this->paymentBatchSign($review); }
	public function verify($review,$action='reconcile') { $this->paymentBatchVerify($review,$action); }
	public function ids($ids,$group=true) { return $this->paymentBatchIds($ids,$group); }
}
$_SESSION = array(); $harness = new BatchReviewHarness();
$facts = array('action'=>'reconcile','staging_id'=>11,'ids'=>array(21,22),'name'=>'November salaries','source'=>array('amount'=>'-3000.00000003','bank_account_id'=>81),
	'components'=>array(array('rowid'=>21,'amount'=>'-1000.00000001'),array('rowid'=>22,'amount'=>'-2000.00000002')));
$review = $harness->issue($facts); $harness->verify($review);
batchCheck(!isset($_SESSION['kreabank_payment_batch_reviews'][$review['nonce']]), 'Accepted reviews are single use');
batchReject(static function () use ($harness,$review) { $harness->verify($review); }, 'Replay is rejected');
foreach (array('name','ids','source','components','action','expires','entity','user') as $field) {
	$review=$harness->issue($facts);
	if ($field==='ids') { $review[$field]=array(21,23); }
	elseif ($field==='source') { $review[$field]['bank_account_id']=82; }
	elseif ($field==='components') { $review[$field][0]['amount']='-1000.00000002'; }
	elseif ($field==='entity' || $field==='user' || $field==='expires') { $review[$field]--; }
	else { $review[$field].='changed'; }
	batchReject(static function () use ($harness,$review) { $harness->verify($review); }, 'Signed review binds '.$field);
}
$review=$harness->issue($facts);
$other=new BatchReviewHarness(10);
batchReject(static function () use ($other,$review) { $other->verify($review); }, 'Another user cannot approve a reviewed group');
$review=$harness->issue($facts);
$_SESSION['kreabank_payment_batch_secret']=bin2hex(random_bytes(32));
batchReject(static function () use ($harness,$review) { $harness->verify($review); }, 'A different session cannot reuse a review');
batchCheck($harness->ids(array('22',21)) === array(21,22), 'Component locks use deterministic native ID order');
foreach (array(array(21,21),array(21),array('native_21',22),array(-21,22),range(1,501)) as $ids) { batchReject(static function () use ($harness,$ids) { $harness->ids($ids); }, 'Invalid, duplicate or unbounded component selection is rejected'); }
batchCheck($harness->ids(array(),false) === array(), 'Empty draft selection can be saved');
echo 'OK: '.$checks." payment batch pure checks\n";
