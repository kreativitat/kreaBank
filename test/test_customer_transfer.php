#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Installed request-independent native helpers; no bootstrap, database or payment.
 * Actual persisted receipt/allocation eligibility is tested by the guarded DB suite.
 */
$nativeRoot=getenv('KREABANK_DOLIBARR_ROOT') ?: dirname(__DIR__,3);
if (!is_file($nativeRoot.'/core/class/translate.class.php')) { fwrite(STDERR,"Set KREABANK_DOLIBARR_ROOT to installed Dolibarr source.\n");exit(2); }
define('DOL_DOCUMENT_ROOT',$nativeRoot);define('DOL_URL_ROOT','');
require_once DOL_DOCUMENT_ROOT.'/core/lib/functions.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/translate.class.php';
require_once __DIR__.'/../class/KreaBankCustomerTransfer.class.php';
require_once __DIR__.'/../class/KreaBankMatcher.class.php';
require_once __DIR__.'/../lib/kreabank_bulk.lib.php';
require_once __DIR__.'/../lib/kreabank_worklist_view.lib.php';
$conf=(object)array('entity'=>1,'currency'=>'EUR','modules'=>array('kreabank'=>1,'banque'=>1,'facture'=>1),
	'global'=>(object)array('MAIN_MAX_DECIMALS_UNIT'=>8,'MAIN_MAX_DECIMALS_TOT'=>8),
	'file'=>(object)array('dol_document_root'=>array('main'=>DOL_DOCUMENT_ROOT)));
class CustomerTransferPureActor
{
	public $id=9,$entity=0,$socid=0,$statut=1,$denied=array();
	public function hasRight($a,$b,$c='') { return !in_array(implode('.',array_filter(array($a,$b,$c))),$this->denied,true); }
}
$user=new CustomerTransferPureActor();
$readTripwire=new class { public function query($sql) { throw new RuntimeException('Unexpected SQL in empty/unauthorized customer-transfer read'); } };
$helper=new KreaBankCustomerTransfer($readTripwire,$user,1);
$checks=0;
function customerTransferCheck($ok,$message) { global $checks;if(!$ok){throw new RuntimeException($message);}$checks++; }
customerTransferCheck($helper->canRead(),'Current internal banking and invoice reader can inspect existing receipt evidence');
customerTransferCheck($helper->metadataForBankIds(array())===array() && $helper->enrich(array())===array(),'Empty advice never opens a database or creates business records');
$unrelated=array(array('rowid'=>11,'doc_type'=>'customer_invoice','amount_open'=>379.42,'ref'=>'INV-EXISTING'));
customerTransferCheck($helper->enrich($unrelated)===$unrelated,'An invoice candidate is never converted into a transfer receipt');
foreach(array('banque.lire','kreabank.reconciliation.read','facture.lire')as$right){
	$user->denied=array($right);
	customerTransferCheck(!$helper->canRead() && $helper->enrich($unrelated)===$unrelated,'Missing native/module read authority cannot enrich or query customer records');
}
$user->denied=array('banque.modifier','kreabank.reconciliation.write');
customerTransferCheck($helper->canRead(),'Read-only native evidence does not require payment or reconciliation write rights');
$user->denied=array();
foreach(array('id'=>0,'socid'=>17,'entity'=>2,'statut'=>0)as$field=>$value){
	$old=$user->$field;$user->$field=$value;
	customerTransferCheck(!$helper->canRead(),'Anonymous, external, foreign and disabled actors cannot inspect transfer receipts');
	$user->$field=$old;
}
$conf->entity=2;customerTransferCheck(!$helper->canRead(),'Changing company invalidates the retained evidence reader');$conf->entity=1;
foreach(array('kreabank','banque','facture')as$module){
	unset($conf->modules[$module]);customerTransferCheck(!$helper->canRead(),'Every participating native/module read scope remains required');$conf->modules[$module]=1;
}

// Exercise native getEntity(), including its Multicompany return contract. A
// malformed parent scope must fail before any customer or financial query.
$previousMc=$GLOBALS['mc']??null;$previousHooks=$GLOBALS['hookmanager']??null;
$mc=new class { public $scope;public function getEntity($element,$shared,$object){return $this->scope;} };
$hookmanager=new class { public $resPrint='';public function executeHooks(...$args){return 0;} };
foreach(array('', '1,,2', '1,', '-1', '1.0', '1 OR 1=1', '1,2) OR (1=1', array(1,2), '12345678901') as $scope){
	$mc->scope=$scope;
	customerTransferCheck($helper->metadataForBankIds(array(201))===array(),'Malformed native customer-sharing scope fails closed before SQL');
}
$mc=$previousMc;$hookmanager=$previousHooks;

$metadata=array('bank_line_id'=>201,'bank_account_id'=>101,'amount'=>'379.42000000','currency'=>'EUR',
	'payment_id'=>301,'ref'=>'RC-2031-009 & <untrusted>','number'=>'SEPA-20310410-00123456','date'=>'2031-04-10',
	'mode_code'=>'VIR','allocation_count'=>2,'invoice_refs'=>array('INV-2031-001','INV-2031-002'),
	'customer_id'=>401,'customer_name'=>'Avery Synthetic Trading Lda','facts_hash'=>str_repeat('a',64));
$line=array('rowid'=>11,'fk_statement'=>1,'bank_account_id'=>101,'direction'=>1,'status'=>0,'amount'=>'379.42000000','currency'=>'EUR',
	'operation_date'=>'2031-04-10','value_date'=>'2031-04-10','statement_ref'=>'SYNTHETIC-ST-11','description'=>'Recorded transfer',
	'payment_reference'=>$metadata['number'],'bank_reference'=>'','counterparty_name'=>'','counterparty_iban'=>'');
$document=array('rowid'=>201,'doc_type'=>'native_bank','ref'=>'(CustomerInvoicePayment)',
	'ref_client'=>implode(', ',$metadata['invoice_refs']),'payment_ref'=>$metadata['ref'],'doc_date'=>'2031-04-10',
	'amount_open'=>379.42,'bank_account_id'=>101,'bank_account_ref'=>'SYNTHETIC BANK','thirdparty_name'=>$metadata['customer_name'],
	'thirdparty_iban'=>'','description'=>'(CustomerInvoicePayment)','customer_transfer'=>$metadata);
$matcher=new KreaBankMatcher();$suggestions=$matcher->getSuggestions($line,array($document),array(),3,0);$suggestion=$suggestions[0];
customerTransferCheck($suggestion['doc_type']==='native_bank' && $suggestion['doc_id']===201 && $suggestion['doc_ref']===$document['ref'] && $suggestion['customer_transfer']===$metadata,'The shared matcher keeps the existing movement reference/identity and complete recorded receipt evidence');
customerTransferCheck($suggestion['score']===220 && in_array('ref_payment',$suggestion['details'],true),'A genuine recorded receipt number uses the existing reference signal and unchanged score');
customerTransferCheck($matcher->getSafeSuggestion($suggestions,150)['doc_id']===201,'An otherwise eligible strong native proposal keeps the shared safe-suggestion contract');
$receiptLine=$line;$receiptLine['payment_reference']=$metadata['ref'];
customerTransferCheck(in_array('ref_payment',$matcher->scoreLineToDocument($receiptLine,$document,array(),3)['details'],true),'The complete actual receipt reference remains valid identity alongside its bank reference number');
foreach(array('PAY1','Transfer','VIR','PAYMENT')as$generic){
	$short=$document;$short['customer_transfer']['ref']=$generic;$short['customer_transfer']['number']='';
	$genericLine=$line;$genericLine['payment_reference']=$generic;
	$details=$matcher->scoreLineToDocument($genericLine,$short,array(),3)['details'];
	customerTransferCheck(!array_intersect(array('ref_payment','ref_exact'),$details),'Short and generic receipt metadata cannot grant strong reference evidence');
}
$bounded=$document;$bounded['customer_transfer']['ref']='INV-TRANSFER-202611';$bounded['customer_transfer']['number']='';
$substring=$line;$substring['payment_reference']='';$substring['description']='XXINV-TRANSFER-202611YY';
customerTransferCheck(!array_intersect(array('ref_payment','ref_exact'),$matcher->scoreLineToDocument($substring,$bounded,array(),3)['details']),'An embedded substring is not a whole recorded receipt reference');
$substring['description']='Transfer INV-TRANSFER-202611 received';
customerTransferCheck(in_array('ref_exact',$matcher->scoreLineToDocument($substring,$bounded,array(),3)['details'],true),'A complete bounded stored receipt reference uses the existing description reference signal');
$nameLine=$line;$nameLine['payment_reference']='';$nameLine['description']='TRF CRED SEPA Avery Synthetic Trading Lda';
$nameAdvice=$matcher->getSuggestions($nameLine,array($document),array(),3,0);
customerTransferCheck(in_array('name_description',$nameAdvice[0]['details'],true) && $nameAdvice[0]['score']===125,'Complete recorded customer identity supplies the existing manual name advice');
customerTransferCheck($matcher->getSafeSuggestion($nameAdvice,100)===null,'A customer name never becomes strong unattended reference evidence');
$nativeLabel=$document;unset($nativeLabel['customer_transfer']);
$nativeLabel['ref']='UNRELATED-NATIVE-REFERENCE';$nativeLabel['ref_client']=$metadata['customer_name'];
$additive=$nativeLabel;$additive['customer_transfer']=$metadata;$additive['ref_client'].=', '.implode(', ',$metadata['invoice_refs']);
$other=$nativeLabel;$other['rowid']=202;$other['ref_client']='';$other['thirdparty_name']='';
$labelAdvice=$matcher->getSuggestions($nameLine,array($additive,$other),array(),3,0);
$nativeLabelAdvice=$matcher->getSuggestions($nameLine,array($nativeLabel,$other),array(),3,0);
customerTransferCheck($labelAdvice[0]['score']===160 && $labelAdvice[0]['score']===$nativeLabelAdvice[0]['score']
	&& in_array('ref_client',$labelAdvice[0]['details'],true),'Appending invoice evidence preserves pre-existing complete native-label identity and its unchanged score');
customerTransferCheck(kreabankBulkSuggestionIsUnambiguous($labelAdvice) && !array_intersect(array('ref_payment','ref_exact'),$labelAdvice[0]['details'])
	&& $matcher->getSafeSuggestion($labelAdvice,150)===null,'Preserved native-label advice retains the35-point manual lead without granting strong or unattended identity');
$second=$document;$second['rowid']=202;
$tie=$matcher->getSuggestions($line,array($document,$second),array(),3,0);
customerTransferCheck($matcher->getSafeSuggestion($tie,150)===null && !kreabankBulkSuggestionIsUnambiguous($tie),'Competing equal recorded transfers never choose an arbitrary first receipt');
$gap=$tie;$gap[1]['score']=$gap[0]['score']-34;
customerTransferCheck($matcher->getSafeSuggestion($gap,150)===null,'The existing 35-point separation requirement remains intact');
$gap[1]['score']--;
customerTransferCheck($matcher->getSafeSuggestion($gap,150)['doc_id']===201,'Exactly 35 points still satisfies the existing separation rule');
$mismatch=$line;$mismatch['amount']='379.44000000';
customerTransferCheck($matcher->scoreLineToDocument($mismatch,$document,array(),3)['score']===0,'A different native amount is not converted into invoice payment or fee allocation advice');
customerTransferCheck(KreaBankCustomerTransfer::matchesSource($metadata,$line),'Exact persisted owned source amount/currency matches its recorded transfer');
foreach(array(array('amount'=>'379.43000000'),array('amount_decimal'=>'379.42000001'),array('amount'=>'-379.42000000'),array('bank_account_id'=>102),array('currency'=>'USD'))as$change){
	customerTransferCheck(!KreaBankCustomerTransfer::matchesSource($metadata,array_merge($line,$change)),'A cent/subcent difference, reverse sign, another bank or currency cannot link this receipt');
}

$row=array('line'=>$line,'suggestion'=>$suggestion,'allocation'=>379.42,'approval_context'=>'selected_native','other_account_warning'=>false);
$scope='1|9|101';$expires=time()+900;$secret=str_repeat('synthetic-customer-transfer-',3);
$signature=kreabankBulkReviewSignature($row,$scope,$expires,$secret);
customerTransferCheck(kreabankBulkReviewIsValid($row,$scope,$expires,$signature,$secret,time(),true,101),'The exact displayed recorded-transfer pair has a valid shared signed review');
foreach(array('ref'=>'RC-REPLACED','number'=>'OTHER-REFERENCE-987654','payment_id'=>302,'facts_hash'=>str_repeat('b',64),
	'allocation_count'=>3,'invoice_refs'=>array('INV-OTHER'),'customer_name'=>'Different Customer')as$field=>$value){
	$changed=$row;$changed['suggestion']['customer_transfer'][$field]=$value;
	customerTransferCheck(!kreabankBulkReviewIsValid($changed,$scope,$expires,$signature,$secret,time(),true,101),'Receipt, allocation and customer evidence cannot change beneath a signed approval');
}

foreach(array('en_US','pt_PT')as$locale){
	$langs=new Translate(DOL_DOCUMENT_ROOT,$conf);$langs->defaultlang=$locale;$langs->dir=array(dirname(__DIR__),DOL_DOCUMENT_ROOT);
	foreach(array('main','banks','bills','kreabank','worklist')as$file){$langs->load($file,2,0,$locale,1);}
	$candidate=array('doc_type'=>'native_bank','doc_id'=>201,'reference'=>kreabankBulkSuggestionReference($suggestion),
		'description'=>$metadata['customer_name'],'date'=>'2031-04-10','amount'=>'379.42000000','currency'=>'EUR','account_ref'=>'SYNTHETIC BANK','customer_transfer'=>$metadata);
	$html=kreabankWorklistCandidate($candidate,$langs);
	$model=array('url'=>'/custom/kreabank/reconciliationlist.php','can_write'=>true,'token'=>'synthetic','rows'=>array(array('key'=>'staged:11',
		'line'=>$line+array('bank_account_ref'=>'SYNTHETIC BANK'),'review'=>$candidate)));
	$table=kreabankWorklistTable($model,$langs);$dom=new DOMDocument();$old=libxml_use_internal_errors(true);
	$dom->loadHTML('<?xml encoding="utf-8" ?>'.$table);libxml_clear_errors();libxml_use_internal_errors($old);$xpath=new DOMXPath($dom);
	$link=$xpath->query('//td[@class="kb-wl-match"]/strong/a')->item(0);
	customerTransferCheck($link && $link->getAttribute('href')==='/compta/paiement/card.php?id=301' && strpos($link->textContent,$metadata['ref'])!==false,'The actual receipt reference opens its native customer payment card: '.$locale);
	customerTransferCheck($xpath->query('//script|//untrusted')->length===0 && strpos($table,'&lt;untrusted&gt;')!==false,'Stored receipt reference markup renders as evidence text: '.$locale);
	customerTransferCheck(strpos(html_entity_decode(strip_tags($html),ENT_QUOTES,'UTF-8'),$langs->transnoentities('KreaBankWorklistCustomerTransfer'))!==false
		&& strpos(html_entity_decode(strip_tags($html),ENT_QUOTES,'UTF-8'),$langs->transnoentities('KreaBankWorklistAllocatedInvoices').': 2')!==false,'The compact transfer/invoice-count caption uses native translations: '.$locale);
	customerTransferCheck($xpath->query('//a[contains(@class,"kb-wl-record-payment") or contains(@href,"mode=create")] | //input[@name="action"]')->length===0,'An already recorded transfer exposes no payment-creation action or financial POST without prepared approval: '.$locale);
	customerTransferCheck(strpos($table,'&amp;ecirc;')===false && strpos($table,'&amp;ccedil;')===false,'Transfer captions preserve native Portuguese encoding: '.$locale);
}
echo "OK: $checks customer-transfer permissions, matcher, signature and native-view checks (no database)\n";
