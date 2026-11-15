#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
require_once __DIR__ . '/../class/KreaBankRule.class.php';
$checks = 0;
$check = static function ($condition, $label) use (&$checks) { if (!$condition) { throw new RuntimeException($label); } $checks++; };
$reject = static function ($call, $label) use ($check) { $thrown = false; try { $call(); } catch (Throwable $e) { $thrown = true; } $check($thrown, $label); };
$line = array('entity'=>1,'bank_account_id'=>101,'amount'=>'-12.34000000','currency'=>'EUR','description'=>'Monthly BANK fee 2026','counterparty_name'=>'Banco Teste','counterparty_iban'=>'GB82WEST12345698765432');
$rule = array('rowid'=>1,'entity'=>1,'label'=>'Fees','active'=>1,'priority'=>100,'fk_bank_account'=>null,'direction'=>'out', 'conditions'=>array('all'=>array(array('field'=>'description','operator'=>'contains','value'=>'bank fee'))),'action'=>array('type'=>'category','category_id'=>5));
$check(KreaBankRule::matches($rule,$line), 'Description matching ignores case');
$other=$rule; $other['entity']=2; $check(!KreaBankRule::matches($other,$line),'Foreign-company rule never matches');
$other=$rule; $other['fk_bank_account']=202; $check(!KreaBankRule::matches($other,$line),'Another account never matches');
$other=$rule; $other['active']=0; $check(!KreaBankRule::matches($other,$line),'Rules are inactive until explicitly enabled');
$other=$rule; $other['direction']='in'; $check(!KreaBankRule::matches($other,$line),'Direction is required');
$condition = static function ($field,$operator,$value,$max=null) use ($rule) { $r=$rule; $r['conditions']=array('all'=>array(array('field'=>$field,'operator'=>$operator,'value'=>$value))); if($max!==null){$r['conditions']['all'][0]['max']=$max;} return $r; };
$check(KreaBankRule::matches($condition('description','not_contains','refund'),$line),'Negative description condition');
$check(!KreaBankRule::matches($condition('description','not_contains','fee'),$line),'Negative description exclusion');
$check(KreaBankRule::matches($condition('description','regex','^Monthly\s+BANK\s+fee\b'),$line),'Bounded regex matches');
$check(KreaBankRule::matches($condition('counterparty_name','contains','banco'),$line),'Counterparty contains');
$check(KreaBankRule::matches($condition('iban','equals','GB82 WEST 1234 5698 7654 32'),$line),'IBAN checksum and spaces');
$check(!KreaBankRule::matches($condition('iban','equals','GB83WEST12345698765432'),$line),'Invalid checksum cannot match');
$check(KreaBankRule::matches($condition('amount','equals','-12.34'),$line),'Exact persisted decimal value');
$check(!KreaBankRule::matches($condition('amount','equals','-12.34000001'),$line),'Sub-cent values are not rounded');
$check(KreaBankRule::matches($condition('amount','between','-13','-12.34'),$line),'Inclusive decimal interval');
$check(KreaBankRule::matches($condition('currency','equals','eur'),$line),'Currency normalization');
$check(!KreaBankRule::matches($condition('currency','equals','USD'),$line),'Currency mismatch');
$any=$rule; $any['conditions']['any']=array(array('field'=>'currency','operator'=>'equals','value'=>'USD'),array('field'=>'counterparty_name','operator'=>'contains','value'=>'Banco'));
$check(KreaBankRule::matches($any,$line),'OR group accepts one matching condition with all required conditions');
$any['conditions']['all'][]=array('field'=>'description','operator'=>'contains','value'=>'missing');
$check(!KreaBankRule::matches($any,$line),'OR group never bypasses AND conditions');
$low=$rule; $low['rowid']=2; $low['priority']=1;
$advice=KreaBankRule::evaluate(array($rule,$low),$line);
$check($advice['winner']['rowid']===2 && $advice['conflicts'] && count($advice['matches'])===2,'Priority wins and all conflicts remain visible');
$low['priority']=100; $advice=KreaBankRule::evaluate(array($low,$rule),$line);
$check($advice['winner']['rowid']===1,'Row identity resolves equal priority deterministically');
foreach(array('(',str_repeat('a',257),'(*ACCEPT)','(?R)') as $regex){$reject(static function()use($condition,$regex){KreaBankRule::validateConditions($condition('description','regex',$regex)['conditions']);},'Invalid or unsafe regex rejected');}
$bad=$line; $bad['description']=str_repeat('a',4097); $check(!KreaBankRule::matches($condition('description','regex','a+'),$bad),'Regex input length bounded');
$bad=$line; $bad['description']=str_repeat('a',4000).'!'; $check(!KreaBankRule::matches($condition('description','regex','^(a+)+$'),$bad),'Regex resource exhaustion fails closed');
$reject(static function(){KreaBankRule::validateConditions(array());},'Catch-all rules require a condition');
$reject(static function()use($condition){KreaBankRule::validateConditions($condition('amount','between','2','1')['conditions']);},'Reversed amount interval rejected');
$reject(static function(){KreaBankRule::validateAction(array('type'=>'quick_entry','thirdparty_id'=>1,'accounting_account'=>'622','vat'=>'23','label'=>'Fee'));},'Payment mode must be explicitly selected');
$reject(static function(){KreaBankRule::validateAction(array('type'=>'invoice_search','thirdparty_id'=>1,'invoice_type'=>'supplier_credit_note'));},'Credit-note search action excluded');
$check(KreaBankDecimal::add('999999999999999999999.99','0.01')==='1000000000000000000000','Large values do not overflow an integer');
$check(KreaBankDecimal::subtract('12.34','12.34000001')==='-0.00000001','Exact difference retains evidence precision');
$check(KreaBankDecimal::multiply('0.03','33.333333333334')==='1.00000000000002','Exact products retain percentage boundary precision');
$check(KreaBankDecimal::compare('1.00000000000002','1')===1,'Intermediate precision remains comparable');
foreach(array('1e2','1,20',1.2,'NaN') as $bad){$reject(static function()use($bad){KreaBankDecimal::normalize($bad);},'Floating, localized or non-decimal inputs rejected');}
echo 'OK: '.$checks." bank-rule pure checks\n";
