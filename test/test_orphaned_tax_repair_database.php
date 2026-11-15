#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 * ONLY the guarded disposable 127.0.0.1:33317/kreabank_auto_test fixture.
 * Rehearses a reviewed maintenance composition, not a new runtime repair API.
 * Never bootstrap an installation or read its configuration/credentials.
 */
require __DIR__ . '/test_automatic_reconciliation_database.php';
require_once __DIR__ . '/../lib/kreabank_integration.lib.php';
$initialChecks=$checks;
// The final rollback-only creation probe needs the same native dictionaries as
// the existing movement-creation fixture, without application bootstrap/hooks.
foreach (array('c_country','c_departements','accounting_journal','c_paiement') as $table) {
	$sql=preg_replace('/--[^\n]*/','',file_get_contents(DOL_DOCUMENT_ROOT.'/install/mysql/tables/llx_'.$table.'.sql'));
	foreach (explode(';',str_replace('llx_',MAIN_DB_PREFIX,$sql)) as $statement) { if (trim($statement)!=='') { $db->query($statement); } }
}
function dol_trunc($value,$length,$mode='right',$charset='',$dots=1) { return substr($value,0,$length); }
function dol_print_error($db,$message='') {}
class OrphanTaxFixtureLangs extends AutoTestLangs
{
	public function transnoentitiesnoconv($key) { return $key; }
	public function transnoentities($key) { return $key; }
}
$langs=new OrphanTaxFixtureLangs();
$extrafields->attributes['bank_account']=array('loaded'=>1,'label'=>array());

class OrphanTaxFixtureDb extends DoliDB
{
	public $repairOnly=false;
	public $repairWrites=array();
	public function query($sql)
	{
		if ($this->repairOnly && !preg_match('/^(SELECT|SHOW)\s/i',$sql)) {
			if (!preg_match('/^(DELETE FROM kbauto_kreabank_bankmeta\b|UPDATE kbauto_kreabank_statement_line\b|INSERT INTO kbauto_kreabank_recon_audit\b)/',$sql)) {
				throw new RuntimeException('Orphan repair attempted an unexpected or financial write');
			}
			$this->repairWrites[]=$sql;
		}
		return parent::query($sql);
	}
}

/** Test-only exposure of the existing adapter/audit composition used by maintenance. */
class OrphanTaxFixtureService extends KreaBankService
{
	private function orphanRows($sql)
	{
		$result=$this->db->query($sql);
		if (!$result) { throw new RuntimeException('Orphan fixture read failed'); }
		$rows=array(); while ($row=$this->db->fetch_object($result)) { $rows[]=(array)$row; }
		return $rows;
	}
	private function orphanSource($id,$lock=false)
	{
		$p=$this->db->prefix();
		$rows=$this->orphanRows("SELECT l.*,s.ref AS statement_ref,s.bank_account_id,s.currency AS statement_currency,ba.currency_code AS bank_currency"
			. " FROM {$p}kreabank_statement_line l JOIN {$p}kreabank_statement s ON s.rowid=l.fk_statement AND s.entity=l.entity"
			. " JOIN {$p}bank_account ba ON ba.rowid=s.bank_account_id AND ba.entity=s.entity"
			. ' WHERE l.entity='.(int)$this->entity.' AND l.rowid='.(int)$id.($lock?' FOR UPDATE':''));
		if (count($rows)!==1) { throw new DomainException('Orphan source must be owned by this company'); }
		return $rows[0];
	}
	public function orphanReview($sourceId,$bankId,$taxId,$paymentId,$auditId)
	{
		$p=$this->db->prefix();$source=$this->orphanSource($sourceId);
		$meta=$this->orphanRows("SELECT * FROM {$p}kreabank_bankmeta WHERE fk_bank_line=".(int)$bankId.' ORDER BY rowid');
		$audit=$this->orphanRows("SELECT * FROM {$p}kreabank_recon_audit WHERE rowid=".(int)$auditId);
		return array('entity'=>(int)$this->entity,'user'=>(int)$this->user->id,'source_id'=>(int)$sourceId,'bank_id'=>(int)$bankId,
			'tax_id'=>(int)$taxId,'payment_id'=>(int)$paymentId,'audit_id'=>(int)$auditId,'source'=>$source,'meta'=>$meta,'audit'=>$audit);
	}
	public function repairOrphan($review)
	{
		global $conf;
		if (($review['entity']??0)!==(int)$this->entity || (int)$conf->entity!==(int)$this->entity
			|| ($review['user']??0)!==(int)$this->user->id || empty($this->user->id) || !empty($this->user->socid)
			|| !$this->user->hasRight('banque','modifier') || !$this->user->hasRight('kreabank','reconciliation','write')
			|| !$this->user->hasRight('kreabank','reconciliation','undo')) { throw new DomainException('Reviewed maintenance authority changed'); }
		$p=$this->db->prefix();$id=(int)$review['source_id'];$bank=(int)$review['bank_id'];$tax=(int)$review['tax_id'];$payment=(int)$review['payment_id'];
		if (min($id,$bank,$tax,$payment,(int)$review['audit_id'])<=0 || !$this->db->begin()) { throw new DomainException('Invalid orphan repair identity'); }
		try {
			$account=(int)$review['source']['bank_account_id'];
			$accounts=$this->orphanRows("SELECT rowid FROM {$p}bank_account WHERE entity=".(int)$this->entity." AND rowid=$account FOR UPDATE");
			$source=$this->orphanSource($id,true);
			if (count($accounts)!==1 || $source!==$review['source'] || (int)$source['status']!==2 || (int)$source['is_duplicate']!==0
				|| (int)$source['fk_native_bank_line']!==$bank || KreaBankPaymentBatchDecimal::normalize($source['amount'])[0]!=='-'
				|| KreaBankPaymentBatchDecimal::normalize($source['allocated_amount'])!==KreaBankPaymentBatchDecimal::absolute($source['amount'])
				|| $source['currency']!==$source['statement_currency'] || $source['currency']!==$source['bank_currency']) {
				throw new DomainException('Exact orphan source changed');
			}
			$claims=$this->orphanRows("SELECT rowid,entity FROM {$p}kreabank_statement_line WHERE fk_native_bank_line=$bank ORDER BY rowid FOR UPDATE");
			if (count($claims)!==1 || (int)$claims[0]['rowid']!==$id || (int)$claims[0]['entity']!==(int)$this->entity) { throw new DomainException('Orphan native identity has competing claims'); }
			if ($this->paymentBatchMapsAvailable() && ($this->orphanRows("SELECT rowid FROM {$p}kreabank_payment_batch WHERE active_statement_line=$id FOR UPDATE")
				|| $this->orphanRows("SELECT rowid FROM {$p}kreabank_payment_batch_line WHERE active_bank_line=$bank FOR UPDATE"))) { throw new DomainException('A payment group still owns the orphan identity'); }
			// Absence is checked globally: a foreign financial object is never ours to remove.
			$mustBeAbsent=array(
				"SELECT rowid FROM {$p}bank WHERE rowid=$bank FOR UPDATE",
				"SELECT rowid FROM {$p}bank_url WHERE fk_bank=$bank OR (type='payment_sc' AND url_id=$payment) OR (type='sc' AND url_id=$tax) FOR UPDATE",
				"SELECT rowid FROM {$p}chargesociales WHERE rowid=$tax FOR UPDATE",
				"SELECT rowid FROM {$p}paiementcharge WHERE rowid=$payment OR fk_charge=$tax OR fk_bank=$bank FOR UPDATE",
				"SELECT rowid FROM {$p}paiement WHERE fk_bank=$bank FOR UPDATE",
				"SELECT rowid FROM {$p}paiementfourn WHERE fk_bank=$bank FOR UPDATE",
			);
			if ($this->tableExists($p.'payment_salary')) { $mustBeAbsent[]="SELECT rowid FROM {$p}payment_salary WHERE fk_bank=$bank FOR UPDATE"; }
			if ($this->tableExists($p.'accounting_bookkeeping')) { $mustBeAbsent[]="SELECT rowid FROM {$p}accounting_bookkeeping WHERE doc_type='bank' AND fk_doc=$bank FOR UPDATE"; }
			foreach ($mustBeAbsent as $sql) { if ($this->orphanRows($sql)) { throw new DomainException('Financial evidence still exists; do not reset the source'); } }
			$meta=$this->orphanRows("SELECT * FROM {$p}kreabank_bankmeta WHERE fk_bank_line=$bank ORDER BY rowid FOR UPDATE");
			$audit=$this->orphanRows("SELECT * FROM {$p}kreabank_recon_audit WHERE rowid=".(int)$review['audit_id'].' FOR UPDATE');
			if (count($meta)!==1 || $meta!==$review['meta'] || count($audit)!==1 || $audit!==$review['audit']
				|| (int)$meta[0]['entity']!==(int)$this->entity || (int)$meta[0]['bank_account_id']!==$account
				|| $meta[0]['idempotency_hash']!==$source['duplicate_hash'] || (int)$meta[0]['status']!==2
				|| KreaBankPaymentBatchDecimal::normalize($meta[0]['amount'])!==KreaBankPaymentBatchDecimal::normalize($source['amount'])
				|| $meta[0]['currency']!==$source['currency'] || (int)$audit[0]['entity']!==(int)$this->entity
				|| (int)$audit[0]['fk_statement_line']!==$id || $audit[0]['audit_type']!=='quick_tax_entry') { throw new DomainException('Historical orphan evidence changed'); }
			$payload=json_decode($audit[0]['payload_json'],true);
			if (!is_array($payload) || (int)($payload['payment_bank_line_id']??0)!==$bank || (int)($payload['payment_id']??0)!==$payment
				|| (int)($payload['social_contribution_id']??0)!==$tax
				|| KreaBankPaymentBatchDecimal::normalize((string)($payload['allocated_amount']??''))!==KreaBankPaymentBatchDecimal::absolute($source['amount'])) {
				throw new DomainException('Quick-tax audit does not identify the reviewed orphan');
			}
			$metaId=(int)$meta[0]['rowid'];
			if (!$this->db->query("DELETE FROM {$p}kreabank_bankmeta WHERE rowid=$metaId AND entity=".(int)$this->entity
				." AND bank_account_id=$account AND fk_bank_line=$bank AND idempotency_hash=".$this->sqlText($meta[0]['idempotency_hash']))
				|| $this->orphanRows("SELECT rowid FROM {$p}kreabank_bankmeta WHERE rowid=$metaId")
				|| !$this->native->markStatementLinePending($id,'staged')) { throw new RuntimeException('Cannot reset the orphan source'); }
			$after=$this->orphanSource($id);
			if ((int)$after['status']!==0 || KreaBankPaymentBatchDecimal::normalize($after['allocated_amount'])!=='0.00000000'
				|| $after['fk_native_bank_line']!==null) { throw new RuntimeException('Orphan reset did not clear the complete stale state'); }
			if (!$this->logAudit('orphan_tax_repair',$id,$id,array('source'=>'staged','reason'=>'Synthetic reviewed historical orphan cleanup',
				'former_native_bank_line_id'=>$bank,'quick_tax_audit'=>$audit[0],'before'=>$source,'orphan_bankmeta'=>$meta[0],'after'=>$after))) { throw new RuntimeException('Cannot persist orphan repair evidence'); }
			if (!$this->db->commit()) { throw new RuntimeException('Cannot confirm orphan repair commit'); }
			return $after;
		} catch (Throwable $error) { $this->db->rollback(); throw $error; }
	}
	public function probeOrdinaryNativeCreation($id)
	{
		$this->db->begin();
		try { $created=$this->native->ensureNativeLineForStatementLine($id); $this->db->rollback(); return $created; }
		catch (Throwable $error) { $this->db->rollback(); throw $error; }
	}
}

$db=new OrphanTaxFixtureDb();$repair=new OrphanTaxFixtureService($db,$user,$langs);
$sourceId=97500;$bankId=97600;$taxId=97700;$paymentId=97800;
stageAuto($sourceId,'-73.41','ORPHAN-TAX-SYNTHETIC');
$db->query("UPDATE kbauto_kreabank_statement_line SET status=2,allocated_amount=73.41,fk_native_bank_line=$bankId WHERE rowid=$sourceId");
$db->query("INSERT INTO kbauto_kreabank_bankmeta(entity,fk_bank_line,bank_account_id,source_type,idempotency_hash,operation_date,value_date,amount,currency,status,datec)
 SELECT entity,$bankId,101,'csv',duplicate_hash,operation_date,value_date,amount,currency,2,NOW() FROM kbauto_kreabank_statement_line WHERE rowid=$sourceId");
$payload=json_encode(array('social_contribution_id'=>$taxId,'payment_id'=>$paymentId,'payment_bank_line_id'=>$bankId,'allocated_amount'=>73.41));
$db->query("INSERT INTO kbauto_kreabank_recon_audit(entity,audit_type,fk_statement_line,fk_reconciliation,payload_json,fk_user,datec) VALUES(1,'quick_tax_entry',$sourceId,$sourceId,'".$db->escape($payload)."',7,NOW())");
$auditId=(int)$db->last_insert_id();
function orphanFixtureState()
{
	global $db;
	$out=array();
	foreach (array('bank','bank_url','chargesociales','paiementcharge','paiement','paiement_facture','facture','paiementfourn','paiementfourn_facturefourn','facture_fourn','kreabank_statement_line','kreabank_bankmeta','kreabank_recon_audit') as $table) {
		$out[$table]=$db->query('SELECT * FROM kbauto_'.$table.' ORDER BY rowid')->fetch_all(MYSQLI_ASSOC);
	}
	return $out;
}
function orphanAttempt($repair,$review,$label)
{
	global $db;
	$before=orphanFixtureState();$db->repairOnly=true;
	try { rejectAuto(static function()use($repair,$review){$repair->repairOrphan($review);},$label); }
	finally { $db->repairOnly=false; }
	checkAuto(orphanFixtureState()===$before && $db->depth===0,'Rejected orphan repair preserves every source, helper, audit and financial row');
}
$report=new KreaBankIntegration($db,1);
$projected=$db->fetch_object($report->query('SELECT h.* FROM ('.$report->bankStatementSql(101).") h WHERE h.source_storage='staging' AND h.rowid=$sourceId"));
checkAuto(kreabankIntegrationLineState($projected,$projected->statement_ref)==='KreaBankLinkNeedsReview','Deleted financial objects with retained helper metadata reproduce the historical warning');
$review=$repair->orphanReview($sourceId,$bankId,$taxId,$paymentId,$auditId);
$before=orphanFixtureState();$db->begin();$adapter=new KreaBankNativeBankAdapter($db,$user,$langs,1);$adapter->markSchemaVerified();
checkAuto($adapter->markStatementLinePending($sourceId,'staged'),'Existing adapter can reset pending status');
checkAuto((int)$db->query("SELECT fk_native_bank_line FROM kbauto_kreabank_statement_line WHERE rowid=$sourceId")->fetch_object()->fk_native_bank_line===$bankId,'A surviving managed-meta row explains why markPending alone retains the dangling binding');
$db->rollback();checkAuto(orphanFixtureState()===$before,'The markPending-only diagnostic leaves no persisted changes');

$changed=$review;$changed['entity']=2;orphanAttempt($repair,$changed,'A foreign-company review cannot authorize orphan cleanup');
$user->allowed=false;orphanAttempt($repair,$review,'Current maintenance permissions are required');$user->allowed=true;
$db->query("UPDATE kbauto_kreabank_statement_line SET amount=-73.42 WHERE rowid=$sourceId");
orphanAttempt($repair,$review,'Changed source evidence invalidates the reviewed repair');
$db->query("UPDATE kbauto_kreabank_statement_line SET amount=-73.41 WHERE rowid=$sourceId");
$review=$repair->orphanReview($sourceId,$bankId,$taxId,$paymentId,$auditId);
bankAuto($bankId,'-73.41','FOREIGN-EXISTING-NATIVE',202);
orphanAttempt($repair,$review,'An existing native movement, including another company, is never removed');$db->query("DELETE FROM kbauto_bank WHERE rowid=$bankId");
$db->query("INSERT INTO kbauto_chargesociales(rowid,entity,fk_type,libelle,date_ech,amount,paye) VALUES($taxId,2,1,'Foreign synthetic tax','2026-11-01',73.41,1)");
orphanAttempt($repair,$review,'An existing tax, including a foreign tax, is never removed');$db->query("DELETE FROM kbauto_chargesociales WHERE rowid=$taxId");
$db->query("INSERT INTO kbauto_paiementcharge(rowid,fk_charge,amount,fk_typepaiement,fk_bank) VALUES($paymentId,$taxId,73.41,1,0)");
orphanAttempt($repair,$review,'An existing payment prevents source reset even when it has no bank movement');$db->query("DELETE FROM kbauto_paiementcharge WHERE rowid=$paymentId");
stageAuto($sourceId+1,'-73.41','COMPETING-CLAIM');$db->query('UPDATE kbauto_kreabank_statement_line SET fk_native_bank_line='.$bankId.' WHERE rowid='.($sourceId+1));
orphanAttempt($repair,$review,'A competing staging claim prevents orphan cleanup');
$db->query('UPDATE kbauto_kreabank_statement_line SET fk_native_bank_line=NULL WHERE rowid='.($sourceId+1));
$db->query("INSERT INTO kbauto_bank_url(fk_bank,url_id,url,label,type) VALUES($bankId,$paymentId,'/synthetic/','Retained tax link','payment_sc')");
orphanAttempt($repair,$review,'Retained tax bank links require investigation instead of deletion');$db->query("DELETE FROM kbauto_bank_url WHERE fk_bank=$bankId");
$db->query("UPDATE kbauto_kreabank_bankmeta SET entity=2 WHERE fk_bank_line=$bankId");
$foreignMeta=$repair->orphanReview($sourceId,$bankId,$taxId,$paymentId,$auditId);
orphanAttempt($repair,$foreignMeta,'Even a fresh review cannot remove metadata belonging to another company');$db->query("UPDATE kbauto_kreabank_bankmeta SET entity=1 WHERE fk_bank_line=$bankId");
$review=$repair->orphanReview($sourceId,$bankId,$taxId,$paymentId,$auditId);
$db->fail='INSERT INTO kbauto_kreabank_recon_audit';orphanAttempt($repair,$review,'Audit failure rolls back both metadata removal and pending reset');$db->fail='';

$before=orphanFixtureState();$db->repairWrites=array();$db->repairOnly=true;
try { $after=$repair->repairOrphan($review); } finally { $db->repairOnly=false; }
$completed=orphanFixtureState();
foreach (array('bank','bank_url','chargesociales','paiementcharge','paiement','paiement_facture','facture','paiementfourn','paiementfourn_facturefourn','facture_fourn') as $table) {
	checkAuto($completed[$table]===$before[$table],'Orphan source repair changes no financial rows: '.$table);
}
checkAuto(count($db->repairWrites)===3,'The repair writes only its orphan metadata delete, source reset and audit');
checkAuto((int)$after['status']===0 && $after['fk_native_bank_line']===null && KreaBankPaymentBatchDecimal::normalize($after['allocated_amount'])==='0.00000000','The complete orphan source becomes unmapped pending');
$immutableBefore=$review['source'];$immutableAfter=$after;
foreach (array('status','allocated_amount','fk_native_bank_line','tms') as $field) { unset($immutableBefore[$field],$immutableAfter[$field]); }
checkAuto($immutableBefore===$immutableAfter,'Source identity, amount, currency, booking date, references and import evidence are unchanged');
$audit=$db->query("SELECT payload_json FROM kbauto_kreabank_recon_audit WHERE audit_type='orphan_tax_repair' AND fk_statement_line=$sourceId")->fetch_object();
$record=json_decode($audit->payload_json,true);
checkAuto($record['orphan_bankmeta']===$review['meta'][0] && $record['before']===$review['source'] && $record['after']===$after && $record['quick_tax_audit']===$review['audit'][0],'The atomic repair audit archives the complete original orphan/source/audit evidence');
$projected=$db->fetch_object($report->query('SELECT h.* FROM ('.$report->bankStatementSql(101).") h WHERE h.source_storage='staging' AND h.rowid=$sourceId"));
checkAuto(kreabankIntegrationLineState($projected,$projected->statement_ref)==='KreaBankPending','Read-only projection no longer reports a missing native link');
orphanAttempt($repair,$review,'Replaying the old exact review cannot create a second repair audit');
$pendingState=orphanFixtureState();$created=$repair->probeOrdinaryNativeCreation($sourceId);
checkAuto($created>0,'Ordinary native creation can reuse the original source identity after orphan metadata removal');
checkAuto(orphanFixtureState()===$pendingState && $db->depth===0,'The creation probe is rolled back and leaves the repaired source pending with no new financial records');
echo 'OK: '.($checks-$initialChecks)." orphaned tax repair database checks\n";
