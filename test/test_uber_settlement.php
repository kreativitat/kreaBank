#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Pure review and exact-amount contracts; no application bootstrap or database.
 */
require_once __DIR__ . '/../class/KreaBankPaymentBatchTrait.php';
require_once __DIR__ . '/../class/KreaBankWorklistMovementTrait.php';
require_once __DIR__ . '/../class/KreaBankUberSettlementTrait.php';
$checks = 0;
function uberCheck($ok, $label) { global $checks; if (!$ok) { throw new RuntimeException($label); } $checks++; }
function uberReject($call, $label) { $caught=false; try { $call(); } catch (Throwable $error) { $caught=true; } uberCheck($caught, $label); }
foreach (array(0=>'0.00',1=>'0.01',99=>'0.99',100=>'1.00',-1=>'-0.01',123456=>'1234.56',-27123=>'-271.23',999999999999=>'9999999999.99') as $cents=>$money) {
	uberCheck(KreaBankUberSettings::money($cents)===$money,'Provider cent display remains exact');
	uberCheck(KreaBankUberSettings::cents($money)===$cents,'Exact cent conversion round-trips');
}
foreach (array('1.001','0.00000001','10000000000.00','1e3',0.01) as $bad) { uberReject(static function()use($bad){KreaBankUberSettings::cents($bad);},'Fractional cents and unbounded/already-rounded evidence are rejected'); }
foreach (array('1.5','1000000000000','--1','1e2',1.5) as $bad) { uberReject(static function()use($bad){KreaBankUberSettings::money($bad);},'Invalid cent totals are rejected'); }
uberCheck(KreaBankUberSettings::mapRevision(array(3=>8,2=>9))===KreaBankUberSettings::mapRevision(array(2=>9,3=>8)),'Configuration order does not change the revision');
$originalZone=date_default_timezone_get();
foreach(array('UTC','Europe/Lisbon','Europe/Zurich','America/New_York')as$zone){
	date_default_timezone_set($zone);
	foreach(array('2026-03-29','2026-07-15','2026-11-01')as$day){
		uberCheck(date('Y-m-d',KreaBankUberSettings::nativeDayTimestamp($day))===$day,'Native bank storage preserves its server calendar in '.$zone);
	}
}
date_default_timezone_set($originalZone);
uberReject(static function(){KreaBankUberSettings::nativeDayTimestamp('2026-02-30');},'Invalid value dates never fall back to today');
$store=array('entity'=>7,'fk_account'=>12,'currency_code'=>'EUR');
$clearing=array('rowid'=>11,'entity'=>7,'currency_code'=>'EUR','clos'=>0,'courant'=>2,'rappro'=>0);
$receiving=array('rowid'=>12,'entity'=>7,'currency_code'=>'EUR','clos'=>0,'courant'=>1,'rappro'=>1);
KreaBankUberSettings::assertAccounts($store,$clearing,$receiving,array());
uberCheck(true,'An explicit open cash clearing account is supported without retyping it');
foreach (array(array('entity'=>8),array('currency_code'=>'USD'),array('clos'=>1),array('rowid'=>12)) as $change) {
	uberReject(static function()use($store,$clearing,$receiving,$change){KreaBankUberSettings::assertAccounts($store,array_replace($clearing,$change),$receiving,array());},'Foreign, closed, currency-mismatched or self clearing accounts are denied');
}
foreach (array(array('courant'=>2),array('rappro'=>0),array('entity'=>8)) as $change) {
	uberReject(static function()use($store,$clearing,$receiving,$change){KreaBankUberSettings::assertAccounts($store,$clearing,array_replace($receiving,$change),array());},'The receiving account must remain owned and reconcilable');
}
uberReject(static function()use($store,$clearing,$receiving){KreaBankUberSettings::assertAccounts($store,$clearing,$receiving,array(11=>12));},'A restricted clearing endpoint is denied');
class UberReviewHarness
{
	use KreaBankUberSettlementTrait;
	use KreaBankWorklistMovementTrait;
	protected $entity=7;
	protected $user;
	public function __construct($actor=9){$this->user=(object)array('id'=>$actor);}
	public function issue($facts){return $this->uberSettlementSign($facts);}
	public function verify($review){$this->uberSettlementVerify($review);}
}
$_SESSION=array();$h=new UberReviewHarness();
$facts=array('action'=>'uber_settlement','source_key'=>'staged:11','source'=>array('amount'=>'1234.56000000','bank_account_id'=>12),
	'payout'=>array('snapshot_hash'=>str_repeat('a',64),'settlement'=>array('rowid'=>20,'net'=>123456)),
	'mapping_revision'=>str_repeat('b',64),'transfer'=>array('state'=>'reuse','bank_line_id'=>40,'clearing_line_id'=>41),'operation_code'=>'LIQ');
$review=$h->issue($facts);$h->verify($review);
uberCheck(empty($_SESSION['kreabank_uber_reviews'][$review['nonce']]),'Accepted payout review is single-use');
uberReject(static function()use($h,$review){$h->verify($review);},'A repeated approval cannot create another transfer');
foreach(array('action','source_key','source','payout','mapping_revision','transfer','operation_code','entity','user','expires')as$key){
	$review=$h->issue($facts);
	if($key==='source'){$review[$key]['amount']='1234.57000000';}
	elseif($key==='payout'){$review[$key]['settlement']['rowid']++;}
	elseif($key==='transfer'){$review[$key]['clearing_line_id']++;}
	elseif(in_array($key,array('entity','user','expires'),true)){$review[$key]--;}
	else{$review[$key].='altered';}
	uberReject(static function()use($h,$review){$h->verify($review);},'Approval binds '.$key);
}
$review=$h->issue($facts);$other=new UberReviewHarness(10);
uberReject(static function()use($other,$review){$other->verify($review);},'Another actor cannot reuse the signed payout review');
$review=$h->issue($facts);$_SESSION['kreabank_uber_secret']=bin2hex(random_bytes(32));
uberReject(static function()use($h,$review){$h->verify($review);},'A different session cannot reuse the payout review');
echo 'OK: '.$checks." reviewed Uber settlement checks\n";
