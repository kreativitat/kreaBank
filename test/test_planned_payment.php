#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
require_once __DIR__.'/../class/KreaBankPlannedPayment.class.php';
$conf=(object)array('entity'=>4);
function isModEnabled($module) { return !in_array($module,$GLOBALS['disabledPlannedModules']??array(),true); }
class PlannedPaymentPureUser
{
	public $id=9,$socid=0,$entity=0,$statut=1,$denied=array();
	public function hasRight($a,$b,$c='') { return !in_array(implode('.',array_filter(array($a,$b,$c))),$this->denied,true); }
	public function getAllChildIds($include=1) { return array(9); }
}
$user=new PlannedPaymentPureUser();
$adapter=new KreaBankPlannedPayment(null,$user,4,null);
$checks=0;
function plannedPureCheck($condition,$label) { global $checks; if (!$condition) { throw new RuntimeException($label); } $checks++; }
function plannedPureInvoke($method,...$args) { global $adapter; $reflection=new ReflectionMethod($adapter,$method); $reflection->setAccessible(true); return $reflection->invokeArgs($adapter,$args); }
plannedPureCheck($adapter->failureStage()==='review','A new payment adapter exposes only the fixed review stage');
try { $adapter->create(array(),1,'',''); } catch (DomainException $error) {}
plannedPureCheck($adapter->failureStage()==='review','An invalid review fails before accepting financial input');
try { $adapter->create(array('version'=>1,'entity'=>4,'user'=>9,'type'=>'salary','id'=>1),0,'',''); } catch (DomainException $error) {}
plannedPureCheck($adapter->failureStage()==='input','Invalid payment mode syntax reports a fixed input stage without a database');
plannedPureCheck($adapter->permissions('salary') && $adapter->permissions('tax'),'Native salary and tax permissions admit the reviewed form');
foreach (array('banque.lire','banque.modifier','kreabank.reconciliation.read','kreabank.reconciliation.write') as $right) {
	$user->denied=array($right);
	plannedPureCheck(!$adapter->permissions('salary') && !$adapter->permissions('tax'),'Missing common banking authority denies both payment types');
}
$user->denied=array('tax.charges.creer');
plannedPureCheck(!$adapter->permissions('tax') && $adapter->permissions('salary'),'Tax creation permission is separate from salary write authority');
$user->denied=array('salaries.readall','salaries.read');
plannedPureCheck(!$adapter->permissions('salary'),'Salary write alone cannot expose employee salaries');
$user->denied=array('salaries.read');
plannedPureCheck(!$adapter->permissions('salary'),'Read-all and write do not bypass the native base salary read gate');
$user->denied=array('salaries.readall');
plannedPureCheck($adapter->permissions('salary'),'Native read with hierarchy is admitted for document-specific checking');
$user->denied=array();
foreach (array('socid'=>22,'entity'=>5,'statut'=>0,'id'=>0) as $field=>$value) {
	$old=$user->$field; $user->$field=$value;
	plannedPureCheck(!$adapter->permissions('salary') && !$adapter->permissions('tax'),'External, foreign, disabled and anonymous users are denied');
	$user->$field=$old;
}
$conf->entity=5; plannedPureCheck(!$adapter->permissions('salary'),'Company switching invalidates the adapter'); $conf->entity=4;
plannedPureCheck(!$adapter->permissions('supplier_invoice') && !$adapter->permissions('quick_tax'),'No quick document creation or arbitrary payment type is exposed');
$employee=array('firstname'=>'Avery','lastname'=>'Synthetic Example');
foreach (array('Transfer Avery Synthetic Example','TRANSFER AVERY SYNTHETIC EXAMPLE','Synthetic Example, Avery') as $description) {
	plannedPureCheck(plannedPureInvoke('salaryIdentity',array('description'=>$description),$employee),'The complete employee identity supports salary advice');
}
foreach (array('Avery','Synthetic Example','Avery Synthetic','Other Avery Synthetic Exampleton','Salary October') as $description) {
	plannedPureCheck(!plannedPureInvoke('salaryIdentity',array('description'=>$description),$employee),'Partial names, fragments and period labels cannot identify a salary');
}
plannedPureCheck(!plannedPureInvoke('salaryIdentity',array('description'=>'Avery Synthetic Example'),array('firstname'=>'','lastname'=>'Avery Synthetic Example')),'Both native name parts are required');
$tax=array('ref'=>'TX-2031-012345','libelle'=>'Synthetic liability October 2031');
foreach (array('Payment TX-2031-012345','Payment Synthetic liability October 2031') as $description) {
	plannedPureCheck(plannedPureInvoke('taxIdentity',array('description'=>$description),$tax),'A complete liability reference or specific full label supports tax advice');
}
foreach (array('TX-2031-01234','XTX-2031-012345X','Synthetic liability','Tax payment','2031-012345') as $description) {
	plannedPureCheck(!plannedPureInvoke('taxIdentity',array('description'=>$description),$tax),'Partial tax references and generic bank labels cannot identify a liability');
}
foreach (array('IRS','IVA','IMPOSTO','TAX','Social Security','Tax payment','Pagamento de imposto','Pagamento segurança social','Autoridade Tributária Aduaneira') as $label) {
	plannedPureCheck(!plannedPureInvoke('taxIdentity',array('description'=>$label),array('ref'=>'TX1','libelle'=>$label)),'Generic tax categories never identify a specific parent');
}
plannedPureCheck(plannedPureInvoke('taxIdentity',array('bank_reference'=>'203101234567890'),array('ref'=>'203101234567890','libelle'=>'IRS')),'A complete long numeric liability reference remains valid identity');
foreach (array('UTC','Europe/Lisbon','America/New_York','Pacific/Auckland') as $zone) {
	date_default_timezone_set($zone);
	plannedPureCheck(plannedPureInvoke('shift','2031-11-09',-45)==='2031-09-25'
		&& plannedPureInvoke('shift','2032-03-01',-1)==='2032-02-29','Calendar lookbacks are stable across DST, leap days and server time zones');
}
foreach (array('2031-02-29','2032-02-30','2031-13-01','2031-11-9','2031-11-09 00:00:00') as $date) {
	plannedPureCheck(!plannedPureInvoke('date',$date),'Malformed native/source calendar dates fail closed');
}
echo "OK: $checks planned-payment permission and relevance checks\n";
