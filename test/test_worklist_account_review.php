#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Actual list preparation with synthetic scoped reads; no ERP bootstrap/database.
 */
require_once __DIR__ . '/../class/KreaBankWorklist.class.php';

class AccountReviewWorklistFixture extends KreaBankWorklist
{
	public $fixtureLine;
	public function __construct($review)
	{
		$this->fixtureLine = array('rowid'=>11,'fk_statement'=>1,'bank_account_id'=>101,'status'=>0,'account_open'=>true);
		$service = new class($review) {
			public $user;
			private $review;
			public function __construct($review) { $this->review=$review; $this->user=new stdClass(); }
			public function getPaymentAccountReview($id,$accounts) { return $this->review; }
		};
		// A warning must short-circuit executable suggestions and unpaid creation advice.
		$db = new class { public function query($sql) { throw new RuntimeException('Unexpected discovery after account warning'); } };
		$prepared = array('account_review'=>$review,'suggestion'=>array('doc_type'=>'native_bank','doc_id'=>21));
		foreach (array('db'=>$db,'entity'=>7,'service'=>$service,'batches'=>false,
			'callbacks'=>array('prepareReviewRow'=>static function($line)use($prepared){return $prepared;})) as $name=>$value) {
			$property = new ReflectionProperty(KreaBankWorklist::class,$name);
			$property->setAccessible(true); $property->setValue($this,$value);
		}
	}
	public function load($key) { return $this->fixtureLine; }
}
$checks=0;
function accountWorklistCheck($value,$message) { global $checks; if (!$value) { throw new RuntimeException($message); } $checks++; }
$candidate=array('bank_line_id'=>21,'bank_account_id'=>102,'payment_id'=>31,'payment_type'=>'payment_supplier');
foreach (array(
	array('candidates'=>array($candidate),'truncated'=>false),
	array('candidates'=>array($candidate,$candidate),'truncated'=>false),
	array('candidates'=>array(),'truncated'=>true),
	array('candidates'=>array($candidate),'truncated'=>true)
) as $review) {
	$worklist=new AccountReviewWorklistFixture($review);
	$row=$worklist->prepare('staged:11');
	accountWorklistCheck($row['account_review']===$review,'Complete blocking review survives list preparation, including overflow');
	accountWorklistCheck($row['account_candidates']===$review['candidates'],'The exact visible warning candidates are retained without ranking');
	accountWorklistCheck($row['pair']===null,'A warning suppresses even an otherwise executable same-account pair');
	accountWorklistCheck($worklist->candidates($row['line'])===array(),'Explicit candidate search cannot bypass account correction');
}
echo 'OK: '.$checks." worklist account-review preparation checks (synthetic, no database)\n";
