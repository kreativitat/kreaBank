#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 * ONLY disposable 127.0.0.1:33317/kreabank_auto_test. No installation bootstrap/config.
 * Uses the installed DoliDBMysqli transaction implementation and Account lifecycle.
 */
if (PHP_SAPI !== 'cli') { exit(2); }
$nativeRoot=getenv('KREABANK_DOLIBARR_ROOT')?:dirname(__DIR__,3);
if(!is_file($nativeRoot.'/core/db/mysqli.class.php')){fwrite(STDERR,"Set KREABANK_DOLIBARR_ROOT to installed native source.\n");exit(2);}
define('DOL_DOCUMENT_ROOT',$nativeRoot);define('DOL_URL_ROOT','');define('MAIN_DB_PREFIX','kbuber_');
function dol_syslog($message,$level=0){}
function dol_now(){return time();}
function dol_mktime($hour,$minute,$second,$month,$day,$year,$zone='auto',$check=1){return$zone==='gmt'?gmmktime($hour,$minute,$second,$month,$day,$year):mktime($hour,$minute,$second,$month,$day,$year);}
function dol_print_date($time,$format='%Y%m%d',$zone='tzserver'){
	$format=strtr($format,array('%Y'=>'Y','%m'=>'m','%d'=>'d','%H'=>'H','%M'=>'i','%S'=>'s'));
	return $zone==='gmt'?gmdate($format,$time):date($format,$time);
}
function dol_string_nospecial($value){return $value;}
function getDolGlobalInt($name,$default=0){return(int)($GLOBALS['conf']->global->$name??$default);}
function getDolGlobalString($name,$default=''){return(string)($GLOBALS['conf']->global->$name??$default);}
function isModEnabled($name){return in_array($name,array('kreabank','banque','multicompany','kreaeats'),true);}
function getEntity($name){return(string)$GLOBALS['conf']->entity;}
function price2num($value,$mode=''){return(string)$value;}
function dol_escape_htmltag($value){return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');}
function dol_strlen($value){return strlen((string)$value);}
function dol_substr($value,$start,$length,$charset='',$dots=0){return substr($value,$start,$length);}
function dol_trunc($value,$length,$mode='right',$charset='',$dots=1){return substr($value,0,$length);}
function dol_print_error($db,$message=''){throw new RuntimeException('Synthetic native database error: '.$db->lasterror());}
class User{
	public $id=7;public $admin=1;public $statut=1;public $socid=0;public $entity=0;public $denied=array();
	public function hasRight($a,$b,$c=''){return!in_array(implode('.',array_filter(array($a,$b,$c))),$this->denied,true);}
}
class UberDatabaseLangs{
	public function trans($key){return$key;}
	public function transnoentitiesnoconv($key){return$key;}
	public function transnoentities($key){return$key;}
	public function load($key){return 1;}
}
$conf=(object)array('entity'=>1,'currency'=>'EUR','global'=>new stdClass(),'db'=>(object)array('character_set'=>'utf8mb4','dolibarr_main_db_collation'=>'utf8mb4_unicode_ci'));
$user=new User();$langs=new UberDatabaseLangs();
$extrafields=(object)array('attributes'=>array('bank'=>array('loaded'=>1,'label'=>array()),'bank_account'=>array('loaded'=>1,'label'=>array())));
require_once DOL_DOCUMENT_ROOT.'/core/db/DoliDB.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/db/mysqli.class.php';
$probe=new mysqli('127.0.0.1','root','','',33317);
$probe->query('CREATE DATABASE IF NOT EXISTS kreabank_auto_test');$probe->close();
class UberDatabase extends DoliDBMysqli{
	public $failure='';
	public function query($sql,$usesavepoint=0,$type='auto',$resultMode=0){
		if($this->failure!==''&&strpos($sql,$this->failure)===0){return false;}
		return parent::query($sql,$usesavepoint,$type,$resultMode);
	}
}
$db=new UberDatabase('mysqli','127.0.0.1','root','','kreabank_auto_test',33317);
if(!$db->connected||$db->database_name!=='kreabank_auto_test'||(int)$db->database_port!==33317){throw new RuntimeException('Disposable database guard failed');}
function uberSql($sql){global$db;$r=$db->query($sql);if(!$r){throw new RuntimeException($db->lasterror().' ['.$sql.']');}return$r;}
function uberRows($sql){global$db;$r=uberSql($sql);$rows=array();while($row=$db->fetch_object($r)){$rows[]=(array)$row;}return$rows;}
$checks=0;
function uberDbCheck($ok,$label){global$checks;if(!$ok){throw new RuntimeException($label);}$checks++;}
function uberDbReject($call,$label){$caught=false;try{$call();}catch(Throwable$error){$caught=true;}uberDbCheck($caught,$label);}
function uberCount($table){return(int)uberRows('SELECT COUNT(*) n FROM '.MAIN_DB_PREFIX.$table)[0]['n'];}
uberSql('SET FOREIGN_KEY_CHECKS=0');
foreach(uberRows('SHOW TABLES')as$row){$name=array_values($row)[0];if(strpos($name,MAIN_DB_PREFIX)===0){uberSql('DROP TABLE `'.$name.'`');}}
uberSql('SET FOREIGN_KEY_CHECKS=1');
foreach(array('bank_account','bank','bank_url','const','cronjob','paiement','paiement_facture','facture','paiementfourn','paiementfourn_facturefourn','facture_fourn','societe','societe_rib','societe_remise_except','chargesociales','paiementcharge','salary','payment_salary','c_chargesociales','c_country','c_departements','accounting_journal')as$table){
	$sql=preg_replace('/--[^\n]*/','',file_get_contents(DOL_DOCUMENT_ROOT.'/install/mysql/tables/llx_'.$table.'.sql'));
	foreach(explode(';',str_replace('llx_',MAIN_DB_PREFIX,$sql))as$statement){if(trim($statement)!==''){uberSql($statement);}}
}
uberSql('CREATE TABLE kbuber_entity(rowid INT PRIMARY KEY,active INT NOT NULL)');
uberSql('CREATE TABLE kbuber_user(rowid INT PRIMARY KEY,login VARCHAR(80))');
uberSql('INSERT INTO kbuber_entity VALUES(1,1),(2,1)');
uberSql("INSERT INTO kbuber_societe(rowid,entity,nom,fournisseur) VALUES(1,1,'Synthetic provider',1),(2,2,'Foreign provider',1)");
uberSql("INSERT INTO kbuber_bank_account(rowid,entity,ref,label,currency_code,clos,courant,rappro,fk_pays) VALUES(101,1,'RECEIVER','Synthetic receiving bank','EUR',0,1,1,1),(103,1,'CLEARING','Synthetic clearing cash','EUR',0,2,0,1),(104,1,'OTHER','Another owned bank','EUR',0,1,1,1),(202,2,'FOREIGN','Foreign account','EUR',0,1,1,1)");
require_once __DIR__.'/../class/KreaBankService.class.php';
$service=new KreaBankService($db,$user,$langs);
(new KreaBankOpeningBalance($db,$user,1))->install();
$batchSql=preg_replace('/--[^\n]*/','',file_get_contents(__DIR__.'/../sql/llx_kreabank_payment_batch.sql'));
foreach(explode(';',str_replace('llx_',MAIN_DB_PREFIX,$batchSql))as$statement){if(trim($statement)!==''){uberSql($statement);}}
$sql=preg_replace('/--[^\n]*/','',file_get_contents(dirname(__DIR__,2).'/kreaeats/sql/llx_kreaeats.sql'));
foreach(explode(';',str_replace('llx_',MAIN_DB_PREFIX,$sql))as$statement){if(trim($statement)!==''){uberSql($statement);}}
uberSql("INSERT INTO kbuber_kreaeats_store(rowid,entity,store_uuid,label,fk_account,fk_supplier,currency_code) VALUES(1,1,'00000000-0000-4000-8000-000000000001','Synthetic restaurant',101,1,'EUR'),(2,2,'00000000-0000-4000-8000-000000000002','Foreign restaurant',202,2,'EUR')");
$settings=new KreaBankUberSettings($db,1);
$settings->configure($user,array(1=>103),$settings->revision());
uberDbCheck($settings->mappings()===array(1=>103),'Clearing mapping is explicit and current-company');
uberDbReject(static function()use($settings,$user){$settings->configure($user,array(1=>202),$settings->revision());},'Foreign clearing account is denied');
uberDbReject(static function()use($settings,$user){$settings->configure($user,array(1=>101),$settings->revision());},'Receiving and clearing accounts cannot be the same');
uberDbReject(static function()use($settings,$user){$settings->configure($user,array(1=>104),str_repeat('0',64));},'Stale configuration cannot overwrite current mapping');
function uberFixture($id,$net){global$db;
	$ref='SYNTH-UBER-PAYOUT-'.$id;$amount=KreaBankUberSettings::money($net);
	$raw=array('Store UUID'=>'00000000-0000-4000-8000-000000000001','Payout reference ID'=>$ref);
	$evidence=array(array('profile'=>'uber-payment-eur-v1','source_row'=>1,'order_ref'=>'SYNTH-ORDER-'.$id,'order_date'=>'2026-11-01','payout_date'=>'2026-11-01','raw'=>$raw,'gross'=>$net+5000,'fees'=>-5000,'adjustments'=>0,'net'=>$net,'difference'=>0));
	$mapping=array('profile'=>'uber-payment-eur-v1','store_uuid'=>$raw['Store UUID']);
	uberSql("INSERT INTO kbuber_kreaeats_batch(rowid,entity,fk_store,report_id,section_id,source_hash,date_start,date_end,mapping_json,fk_user_author,datec) VALUES($id,1,1,'SYNTH-REPORT-$id','payment','".str_repeat('a',64)."','2026-10-26','2026-11-01','".$db->escape(json_encode($mapping))."',7,NOW())");
	uberSql("INSERT INTO kbuber_kreaeats_settlement(rowid,entity,fk_batch,fk_store,payout_ref,gross,fees,adjustments,net,evidence_json) VALUES($id,1,$id,1,'$ref',".($net+5000).",-5000,0,$net,'".$db->escape(json_encode($evidence))."')");
	uberSql("INSERT INTO kbuber_kreabank_statement(rowid,entity,ref,source_type,bank_account_id,filename,currency,date_import,datec,fk_user_import) VALUES($id,1,'SYNTH-ST-$id','csv',101,'synthetic.csv','EUR',NOW(),NOW(),7)");
	uberSql("INSERT INTO kbuber_kreabank_statement_line(rowid,entity,fk_statement,line_uid,line_rank,operation_date,value_date,amount,currency,description,payment_reference,direction,duplicate_hash,datec) VALUES($id,1,$id,'synthetic-$id',1,'2026-11-01','2026-11-02',$amount,'EUR','Synthetic provider credit','',1,'synthetic-hash-$id',NOW())");
}
function uberNative($id,$amount,$account,$label='Synthetic transfer',$code='LIQ'){
	global$db;uberSql("INSERT INTO kbuber_bank(rowid,fk_account,datec,dateo,datev,amount,label,fk_type,rappro) VALUES($id,$account,NOW(),'2026-11-01','2026-11-02',$amount,'".$db->escape($label)."','$code',0)");
}
function uberNativePair($credit,$debit,$amount,$code='LIQ'){
	uberNative($credit,$amount,101,'Existing generic native transfer',$code);uberNative($debit,'-'.$amount,103,'Existing generic native transfer',$code);
	uberSql("INSERT INTO kbuber_bank_url(fk_bank,url_id,url,label,type) VALUES($credit,$debit,'/compta/bank/line.php?rowid=','(banktransfert)','banktransfert'),($debit,$credit,'/compta/bank/line.php?rowid=','(banktransfert)','banktransfert')");
}
$_SESSION=array();
uberFixture(50001,42001);
uberDbCheck(count($service->listUberSettlements('staged:50001'))===1,'Complete payout is advised by exact receiver, cent amount and date');
$review=$service->prepareUberSettlementReview('staged:50001',50001);
uberDbCheck($review['transfer']['state']==='create'&&$review['operation_code']==='LIQ','Reviewed cash clearing transfer uses native LIQ semantics');
$before=uberCount('bank');$payments=uberCount('paiement');$supplierPayments=uberCount('paiementfourn');
$bankId=$service->reconcileUberSettlement($review);
uberDbCheck(uberCount('bank')===$before+2&&uberCount('bank_url')===2,'One approval creates exactly one reciprocal transfer');
uberDbCheck(uberCount('kreaeats_link')===1&&uberCount('kreaeats_audit')===1,'Payout association and evidence audit commit with the bank transfer');
uberDbCheck(uberCount('paiement')===$payments&&uberCount('paiementfourn')===$supplierPayments,'No sales or commission payment is created');
$bank=uberRows('SELECT * FROM kbuber_bank WHERE rowid='.$bankId)[0];
uberDbCheck($bank['fk_type']==='LIQ'&&substr($bank['dateo'],0,10)==='2026-11-01'&&substr($bank['datev'],0,10)==='2026-11-02'&&(int)$bank['rappro']===1,'Receiving native dates, cash type and statement reconciliation remain exact');
uberDbCheck($db->transaction_opened===0,'Native and KreaEats nested methods leave the outer transaction closed');
uberDbReject(static function()use($service,$review){$service->reconcileUberSettlement($review);},'Duplicate approval is rejected');
require_once __DIR__.'/../class/KreaBankHistory.class.php';
$history=new KreaBankHistory($db,1);$completed=$history->find(50001,'staged',101);
uberDbCheck($completed&&!$completed['needs_review'],'Shared History validates the Uber reconciliation');
uberDbCheck($service->undoReconciliation(50001,'Synthetic reviewed Undo',KreaBankHistory::snapshot($completed)),'Shared scalar Undo accepts the Uber bank reconciliation');
uberDbCheck(uberCount('bank')===$before+2&&uberCount('kreaeats_link')===1&&uberCount('bank_url')===2,'Undo preserves both transfer legs and the independent KreaEats association');
uberDbCheck((int)uberRows('SELECT status FROM kbuber_kreabank_statement_line WHERE rowid=50001')[0]['status']===0,'Undo leaves the statement pending');
$ordinary=$service->prepareWorklistSelectionPair('staged:50001',$bankId);
uberDbCheck((int)$ordinary['suggestion']['doc_id']===$bankId,'After Undo the unchanged bank leg remains available through ordinary reviewed reconciliation');
foreach(array('INSERT INTO kbuber_bank_url','INSERT INTO kbuber_kreaeats_link','INSERT INTO kbuber_kreaeats_audit','UPDATE kbuber_kreabank_statement_line','INSERT INTO kbuber_kreabank_recon_audit')as$i=>$failure){
	$id=50100+$i;uberFixture($id,43000+$i);$review=$service->prepareUberSettlementReview('staged:'.$id,$id);
	$beforeBank=uberCount('bank');$beforeLink=uberCount('kreaeats_link');$beforeAudit=uberCount('kreaeats_audit');
	$beforeNativeLink=uberCount('bank_url');$beforeBankAudit=uberCount('kreabank_recon_audit');
	$db->failure=$failure;
	uberDbReject(static function()use($service,$review){$service->reconcileUberSettlement($review);},'Injected failure is visible: '.$failure);
	$db->failure='';
	uberDbCheck(uberCount('bank')===$beforeBank&&uberCount('bank_url')===$beforeNativeLink&&uberCount('kreabank_recon_audit')===$beforeBankAudit
		&&uberCount('kreaeats_link')===$beforeLink&&uberCount('kreaeats_audit')===$beforeAudit&&$db->transaction_opened===0
		&&(int)uberRows('SELECT status FROM kbuber_kreabank_statement_line WHERE rowid='.$id)[0]['status']===0,'Both legs, all links/audits and statement status roll back after '.$failure);
}
uberFixture(50200,44000);uberNativePair(60200,60201,'440.00');$review=$service->prepareUberSettlementReview('staged:50200',50200);$beforeBank=uberCount('bank');
uberDbCheck($review['transfer']['state']==='reuse','An intact generic existing transfer can be explicitly reviewed');
uberDbCheck($service->reconcileUberSettlement($review)===60200&&uberCount('bank')===$beforeBank,'The existing exact native pair is reused without duplication');
uberFixture(50210,44010);uberNative(60210,'440.10',101);
uberDbReject(static function()use($service){$service->prepareUberSettlementReview('staged:50210',50210);},'A one-sided native receipt blocks creation');
uberFixture(50220,44020);uberNativePair(60220,60221,'440.20','VIR');
uberDbReject(static function()use($service){$service->prepareUberSettlementReview('staged:50220',50220);},'Cash transfer legs cannot claim another operation code');
uberFixture(50230,44030);uberNative(60230,'1.00',104,'SYNTH-UBER-PAYOUT-50230');uberSql("UPDATE kbuber_bank SET dateo='2026-01-01' WHERE rowid=60230");
uberDbReject(static function()use($service){$service->prepareUberSettlementReview('staged:50230',50230);},'The same full payout reference in another owned account blocks duplication regardless of amount/date');
uberFixture(50240,44040);$review=$service->prepareUberSettlementReview('staged:50240',50240);uberSql("UPDATE kbuber_kreaeats_settlement SET review_note='New independent review' WHERE rowid=50240");
uberDbReject(static function()use($service,$review){$service->reconcileUberSettlement($review);},'Changed payout review facts invalidate approval');
uberFixture(50250,44050);$review=$service->prepareUberSettlementReview('staged:50250',50250);uberSql("UPDATE kbuber_kreabank_statement_line SET amount=440.51 WHERE rowid=50250");
uberDbReject(static function()use($service,$review){$service->reconcileUberSettlement($review);},'Changed bank amount invalidates approval');
uberFixture(50260,44060);$review=$service->prepareUberSettlementReview('staged:50260',50260);$denied=clone$user;$denied->denied=array('banque.transfer');$deniedService=new KreaBankService($db,$denied,$langs);
uberDbReject(static function()use($deniedService,$review){$deniedService->reconcileUberSettlement($review);},'Lost native transfer permission denies execution');
$conf->entity=2;uberDbReject(static function()use($service,$review){$service->reconcileUberSettlement($review);},'Another company cannot use the reviewed payout');$conf->entity=1;
foreach(array('UTC','Europe/Lisbon','America/New_York')as$i=>$zone){
	date_default_timezone_set($zone);$id=50300+$i;uberFixture($id,45000+$i);$review=$service->prepareUberSettlementReview('staged:'.$id,$id);$native=$service->reconcileUberSettlement($review);
	$dates=uberRows('SELECT dateo,datev FROM kbuber_bank WHERE rowid='.$native)[0];
	uberDbCheck(substr($dates['dateo'],0,10)==='2026-11-01'&&substr($dates['datev'],0,10)==='2026-11-02','Native storage preserves bank/value calendar days in '.$zone);
}
date_default_timezone_set('UTC');
uberFixture(50400,46000);uberFixture(50401,46000);
uberSql("UPDATE kbuber_kreaeats_settlement SET payout_ref='SYNTH-UBER-PAYOUT-50400' WHERE rowid=50401");
uberDbReject(static function()use($service){$service->prepareUberSettlementReview('staged:50400',50400);},'A payout split over retained source reports cannot enter reviewed bank settlement');
uberFixture(50410,46010);
uberSql("INSERT INTO kbuber_kreaeats_link(entity,fk_settlement,kind,request_key,fk_target,amount,snapshot_json,reason,fk_user_author,datec,active) VALUES(1,50410,'bank','".str_repeat('d',32)."',999,46010,'{}','Synthetic removed bank association',7,NOW(),0)");
uberDbReject(static function()use($service){$service->prepareUberSettlementReview('staged:50410',50410);},'A removed payout association cannot be silently recreated');
uberFixture(50420,46020);uberNativePair(60420,60421,'460.20');
uberSql("INSERT INTO kbuber_bank_url(fk_bank,url_id,url,label,type) VALUES(60420,1,'/synthetic/','Synthetic unrelated payment','payment')");
uberDbReject(static function()use($service){$service->prepareUberSettlementReview('staged:50420',50420);},'Additional native financial links deny transfer reuse');
uberFixture(50430,46030);uberNativePair(60430,60431,'460.30');$review=$service->prepareUberSettlementReview('staged:50430',50430);
uberSql('UPDATE kbuber_bank SET amount=-460.31 WHERE rowid=60431');
uberDbReject(static function()use($service,$review){$service->reconcileUberSettlement($review);},'A changed reciprocal transfer leg invalidates its approval');
uberFixture(50440,46040);$review=$service->prepareUberSettlementReview('staged:50440',50440);
$settings->configure($user,array(1=>104),$settings->revision());
uberDbReject(static function()use($service,$review){$service->reconcileUberSettlement($review);},'Clearing configuration changes invalidate approval');
$settings->configure($user,array(1=>103),$settings->revision());
uberFixture(50450,46050);uberSql("UPDATE kbuber_kreabank_statement_line SET operation_date='2026-11-09' WHERE rowid=50450");
uberDbReject(static function()use($service){$service->prepareUberSettlementReview('staged:50450',50450);},'A credit outside the payout date window is not eligible');
uberFixture(50460,46060);uberSql("UPDATE kbuber_kreabank_statement_line SET currency='USD' WHERE rowid=50460");
uberDbReject(static function()use($service){$service->prepareUberSettlementReview('staged:50460',50460);},'Currency mismatch never creates a clearing transfer');
uberFixture(50470,46070);
uberSql("INSERT INTO kbuber_paiement(rowid,entity,ref,datep,amount,fk_paiement) VALUES(60470,1,'SYNTH-UNBANKED','2026-11-01',460.70,1)");
uberDbReject(static function()use($service){$service->prepareUberSettlementReview('staged:50470',50470);},'An unbanked existing payment blocks duplicate movement creation');
uberFixture(50480,46080);$review=$service->prepareUberSettlementReview('staged:50480',50480);
$denied=clone$user;$denied->denied=array('kreaeats.reconcile');$deniedService=new KreaBankService($db,$denied,$langs);
uberDbReject(static function()use($deniedService,$review){$deniedService->reconcileUberSettlement($review);},'KreaEats association write permission is required in addition to bank rights');
uberFixture(50490,46090);uberSql("UPDATE kbuber_kreabank_statement_line SET amount=460.90000001 WHERE rowid=50490");
uberDbReject(static function()use($service){$service->prepareUberSettlementReview('staged:50490',50490);},'Bank subcent evidence is rejected rather than rounded');
uberFixture(50500,47000);
uberSql("INSERT INTO kbuber_kreabank_opening_balance(entity,fk_bank_account,start_date,currency,opening_balance,ledger_balance,adjustment_amount,statement_ref,evidence_ref,fk_user,datec,facts_hash,payload_json) VALUES(1,103,'2026-11-02','EUR',0,0,0,'SYNTH-OPEN','Synthetic evidence',7,NOW(),'".str_repeat('e',64)."','{}')");
uberDbReject(static function()use($service){$service->prepareUberSettlementReview('staged:50500',50500);},'A new clearing movement cannot precede its immutable opening boundary');
uberSql('DELETE FROM kbuber_kreabank_opening_balance WHERE fk_bank_account=103');
uberFixture(50510,47010);
uberSql("INSERT INTO kbuber_kreabank_opening_balance(entity,fk_bank_account,start_date,currency,opening_balance,ledger_balance,adjustment_amount,statement_ref,evidence_ref,fk_user,datec,facts_hash,payload_json) VALUES(1,101,'2026-11-02','EUR',0,0,0,'SYNTH-OPEN','Synthetic evidence',7,NOW(),'".str_repeat('f',64)."','{}')");
uberDbReject(static function()use($service){$service->prepareUberSettlementReview('staged:50510',50510);},'A historical receiving source cannot cross the opening boundary');
uberSql('DELETE FROM kbuber_kreabank_opening_balance WHERE fk_bank_account=101');
uberFixture(50520,47020);$review=$service->prepareUberSettlementReview('staged:50520',50520);
$db2=new UberDatabase('mysqli','127.0.0.1','root','','kreabank_auto_test',33317);$service2=new KreaBankService($db2,$user,$langs);
$held=new KreaBankReconciliationLock($db,1);$held->acquire();
uberDbReject(static function()use($service2,$review){$service2->reconcileUberSettlement($review);},'A concurrent company reconciliation lock prevents another settlement');
$held->release();
$review=$service2->prepareUberSettlementReview('staged:50520',50520);$beforeBank=uberCount('bank');
uberDbCheck($service2->reconcileUberSettlement($review)>0&&uberCount('bank')===$beforeBank+2,'A fresh retry after the competing operation creates one transfer');
uberDbReject(static function()use($service){$service->prepareUberSettlementReview('staged:50520',50520);},'A completed source cannot prepare another payout transfer');
$db2->close();
echo 'OK: '.$checks." native reviewed Uber settlement database checks\n";
