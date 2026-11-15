#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
require_once __DIR__ . '/../class/KreaBankWriteOff.class.php';
$checks = 0;
function checkWriteOff($ok, $message) { global $checks; if (!$ok) { throw new RuntimeException($message); } $checks++; }
$values = array('KREABANK_WRITEOFF_ENABLED'=>'1', 'KREABANK_WRITEOFF_MAX_ABS'=>'2.00', 'KREABANK_WRITEOFF_MAX_PERCENT'=>'1.00',
	'KREABANK_WRITEOFF_ACCOUNT_BANK_FEE'=>'627', 'KREABANK_WRITEOFF_ACCOUNT_ROUNDING'=>'688',
	'KREABANK_WRITEOFF_ACCOUNT_DISCOUNT_GRANTED'=>'682', 'KREABANK_WRITEOFF_ACCOUNT_DISCOUNT_RECEIVED'=>'782');
$config = KreaBankWriteOff::configuration($values, 'EUR');
$line = array('rowid'=>11, 'entity'=>1, 'bank_account_id'=>101, 'status'=>0, 'amount'=>'99.00', 'allocated_amount'=>'0', 'currency'=>'EUR', 'direction'=>1);
$invoice = array('rowid'=>21, 'entity'=>1, 'doc_type'=>'customer_invoice', 'ref'=>'SYNTHETIC-21', 'fk_soc'=>1,
	'type'=>0, 'status'=>1, 'paye'=>0, 'amount_open'=>'100.00', 'total_ttc'=>'100', 'multicurrency_code'=>'EUR');
$review = KreaBankWriteOff::inspect($line, $invoice, $config, 'bank_fee');
checkWriteOff($review && $review['difference']==='1' && $review['bank_amount']==='99' && $review['account']==='627' && $review['close_code']==='bankcharge', 'Reviewed customer bank fee uses exact difference, account and native close reason');
checkWriteOff(KreaBankWriteOff::inspect($line, $invoice, $config, 'discount_granted')['close_code']==='discount_vat', 'Customer discount maps only to native discount closure');
checkWriteOff(KreaBankWriteOff::inspect($line, $invoice, $config, 'rounding')['close_code']==='other', 'Rounding uses native other closure');
checkWriteOff(KreaBankWriteOff::inspect($line, $invoice, $config, 'discount_received')===null, 'Supplier discount cannot authorize customer closing');
foreach (array('KREABANK_WRITEOFF_ENABLED'=>'0', 'KREABANK_WRITEOFF_MAX_ABS'=>'', 'KREABANK_WRITEOFF_MAX_PERCENT'=>'0', 'KREABANK_WRITEOFF_ACCOUNT_BANK_FEE'=>'') as $key=>$value) {
	$invalid=$values; $invalid[$key]=$value;
	checkWriteOff(KreaBankWriteOff::configuration($invalid, 'EUR')===null, 'Disabled or incomplete setup is hidden: '.$key);
}
foreach (array('1e2', '1,00', '-1', '0', ' 1', 1.1, INF, NAN) as $value) {
	$invalid=$values; $invalid['KREABANK_WRITEOFF_MAX_ABS']=$value;
	checkWriteOff(KreaBankWriteOff::configuration($invalid, 'EUR')===null, 'Invalid limit never becomes eligible');
}
$invalid=$values; $invalid['KREABANK_WRITEOFF_MAX_PERCENT']='100.000000000001';
checkWriteOff(KreaBankWriteOff::configuration($invalid, 'EUR')===null, 'Percentage over 100 is invalid');
foreach (array('99.01'=>true, '99'=>true, '98.99'=>false, '100'=>false, '100.01'=>false, '0'=>false, '-99'=>false, '98.99999999'=>false) as $amount=>$eligible) {
	$changed=$line; $changed['amount']=(string) $amount;
	checkWriteOff((KreaBankWriteOff::inspect($changed, $invoice, $config, 'rounding')!==null)===$eligible, 'Exact percent boundary and underpayment direction: '.$amount);
}
$absoluteConfig=$config; $absoluteConfig['max_percent']='100';
foreach (array('98'=>true, '97.99'=>false) as $amount=>$eligible) {
	$changed=$line; $changed['amount']=(string) $amount;
	checkWriteOff((KreaBankWriteOff::inspect($changed, $invoice, $absoluteConfig, 'rounding')!==null)===$eligible, 'Absolute limit is independently enforced: '.$amount);
}
// Percent comparison does not divide or round a recurring decimal.
$tinyInvoice=$invoice; $tinyInvoice['amount_open']='0.03'; $tinyLine=$line; $tinyLine['amount']='0.02';
$tinyConfig=$config; $tinyConfig['max_abs']='0.01'; $tinyConfig['max_percent']='33.333333333333';
checkWriteOff(KreaBankWriteOff::inspect($tinyLine, $tinyInvoice, $tinyConfig, 'rounding')===null, 'A recurring percentage fraction never rounds into eligibility');
$tinyConfig['max_percent']='33.333333333334';
checkWriteOff(KreaBankWriteOff::inspect($tinyLine, $tinyInvoice, $tinyConfig, 'rounding')!==null, 'The next exact percentage above the boundary is eligible');
foreach (array('entity'=>2, 'status'=>0, 'type'=>2, 'paye'=>1, 'multicurrency_code'=>'USD', 'amount_open'=>'99') as $key=>$value) {
	$changed=$invoice; $changed[$key]=$value;
	checkWriteOff(KreaBankWriteOff::inspect($line, $changed, $config, 'rounding')===null, 'Foreign/closed/draft/credit/changed invoice excluded: '.$key);
}
foreach (array('entity'=>2, 'status'=>2, 'bank_account_id'=>0, 'restricted'=>true, 'currency'=>'USD', 'allocated_amount'=>'0.01', 'direction'=>-1) as $key=>$value) {
	$changed=$line; $changed[$key]=$value;
	checkWriteOff(KreaBankWriteOff::inspect($changed, $invoice, $config, 'rounding')===null, 'Changed, restricted or partially allocated source excluded: '.$key);
}
$supplier=$invoice; $supplier['doc_type']='supplier_invoice'; $outflow=$line; $outflow['amount']='-99'; $outflow['direction']=-1;
$partial=KreaBankWriteOff::inspect($outflow, $supplier, $config, 'discount_received');
checkWriteOff($partial && $partial['remaining_status']==='supplier_partial' && $partial['difference']==='1', 'Supplier difference remains partial; no forced paid state');
checkWriteOff(KreaBankWriteOff::inspect($outflow, $supplier, $config, 'discount_granted')===null, 'Customer discount is excluded for supplier payments');
$changed=$values; $changed['KREABANK_WRITEOFF_ACCOUNT_BANK_FEE']='628';
$new=KreaBankWriteOff::inspect($line,$invoice,KreaBankWriteOff::configuration($changed,'EUR'),'bank_fee');
checkWriteOff($new['config_snapshot']!==$review['config_snapshot'], 'Changing configured account invalidates the displayed configuration snapshot');
foreach (array('amount_open'=>'100.01','ref'=>'CHANGED','fk_soc'=>2,'tms'=>'2026-11-15 12:00:00','close_code'=>'other') as $key=>$value) {
	$changed=$invoice; $changed[$key]=$value;
	checkWriteOff(KreaBankWriteOff::snapshot($changed)!==KreaBankWriteOff::snapshot($invoice), 'Changed invoice snapshot rejects old approval: '.$key);
}
$history = array('rowid'=>11, 'line_source'=>'staged', 'native_bank_line_id'=>31, 'history_audit'=>array('rowid'=>41,
	'fk_statement_line'=>11, 'audit_type'=>'reconcile', 'payload_json'=>json_encode(array('strategy'=>'manual_writeoff', 'source'=>'staged',
	'native_bank_line_id'=>31, 'writeoff'=>$review + array('payment_id'=>51)))));
$undo = KreaBankWriteOff::undoSnapshotFromHistory($history);
checkWriteOff($undo && $undo['audit_id']===41 && $undo['writeoff']['payment_id']===51, 'Read-only History binds exact audited payment without an execution service');
foreach (array('line_source'=>'native', 'native_bank_line_id'=>32, 'rowid'=>12) as $key=>$value) {
	$changed=$history; $changed[$key]=$value;
	checkWriteOff(KreaBankWriteOff::undoSnapshotFromHistory($changed)===null, 'History namespace/native/source mismatches cannot authorize payment reversal');
}
echo "OK: $checks exact write-off policy checks\n";
