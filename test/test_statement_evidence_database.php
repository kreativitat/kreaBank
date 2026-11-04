#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 * ONLY the guarded disposable 127.0.0.1:33317/kreabank_auto_test fixture.
 */
require __DIR__ . '/test_bank_statement_filters_database.php';
$checks = 0;
checkAuto(KreaBankStatementEvidence::description('Transferência  SEPA')===KreaBankStatementEvidence::description('Transferencia SEPA'),'Original file descriptions tolerate bank-feed accent and whitespace normalization');
checkAuto(KreaBankStatementEvidence::decimal('9999999999999999.12345678')==='9999999999999999.12345678'
	&& KreaBankStatementEvidence::decimal('-0.00')==='0.00000000','Evidence preserves large decimals and actual zero without float conversion');
$fixtures = array(1001=>array('5.00','2026-08-01','12.00'),1002=>array('7.00','2026-08-01','7.00'),
	1003=>array('-2.00','2026-08-02','10.00'),1004=>array('4.00','2026-08-02','14.00'));
$lines = array();
foreach ($fixtures as $id=>$f) {
	stageAuto($id,$f[0],'EVIDENCE',120);
	$db->query("UPDATE kbauto_kreabank_statement_line SET operation_date='$f[1]',value_date='$f[1]' WHERE rowid=$id");
	$lines[$id] = array('staged_id'=>$id,'operation_date'=>$f[1],'value_date'=>$id===1003?'2026-07-30':$f[1],
		'amount'=>$f[0],'running_balance'=>$f[2],'currency'=>'EUR','description'=>'Received EVIDENCE','source_row'=>1005-$id);
}
bankAuto(1001,'5.00','EVIDENCE',120);
$db->query("UPDATE kbauto_bank SET rappro=1,num_releve='ST-1001' WHERE rowid=1001");
$db->query('UPDATE kbauto_kreabank_statement_line SET status=2,fk_native_bank_line=1001 WHERE rowid=1001');
$statementId=(int)$db->fetch_object($db->query("SELECT rowid FROM kbauto_kreabank_statement WHERE ref='ST-1004'"))->rowid;
$proof=array('version'=>1,'sha256'=>str_repeat('a',64),'filename'=>'Original statement.xlsx','order'=>'DESC',
	'lines'=>array($lines[1004],$lines[1003],$lines[1001],$lines[1002]));
$original=$db->query('SELECT * FROM kbauto_kreabank_statement_line WHERE rowid BETWEEN 1001 AND 1004 ORDER BY rowid')->fetch_all(MYSQLI_ASSOC);
$ledger=$db->query('SELECT * FROM kbauto_bank ORDER BY rowid')->fetch_all(MYSQLI_ASSOC);
$audit=countAuto('kreabank_recon_audit');
$service=new KreaBankService($db,$user,$langs);
$recorded=$service->recordStatementEvidence($statementId,$proof);
checkAuto($recorded['lines']===4 && !$recorded['already_recorded'],'Records original file evidence once across import batches');
checkAuto($original===$db->query('SELECT * FROM kbauto_kreabank_statement_line WHERE rowid BETWEEN 1001 AND 1004 ORDER BY rowid')->fetch_all(MYSQLI_ASSOC), 'Source rows, feed ranks, identities and reconciliation state remain byte-identical');
checkAuto($ledger===$db->query('SELECT * FROM kbauto_bank ORDER BY rowid')->fetch_all(MYSQLI_ASSOC),'Original ledger dates, amounts and reconciliations remain untouched');
checkAuto(countAuto('kreabank_recon_audit')===$audit+1,'Original file attachment has one atomic audit');
$read = static function ($order='ASC',$offset=0,$limit=100) use ($report,$db) {
	$res=$report->query('SELECT h.* FROM ('.$report->bankStatementWithBalancesSql(120).') h WHERE h.rowid BETWEEN 1001 AND 1004 ORDER BY '
		.kreabankStatementListOrder($order,'h.operation_date').$db->plimit($limit,$offset));
	$out=array();while($r=$db->fetch_object($res))$out[]=$r;return $out;
};
$ascending=$read(); $descending=$read('DESC');
checkAuto(array_column($ascending,'rowid')===array('1002','1001','1003','1004'),'Chronological file sequence overrides same-day import batch and row IDs');
checkAuto(array_column($descending,'rowid')===array_reverse(array_column($ascending,'rowid')),'Descending order reverses the exact original sequence');
checkAuto(array_column($ascending,'display_balance')===array('7.00000000','12.00000000','10.00000000','14.00000000'),'Original debit and credit balances remain continuous');
checkAuto(array_column($read('ASC',2,2),'rowid')===array('1003','1004'),'Pagination applies after the complete original order');
checkAuto($ascending[2]->value_date==='2026-07-30' && (int)$ascending[2]->balance_calculated===0,'Shows original value dates and supplied balances without changing feed data');
checkAuto($filtered(array('value_to'=>'2026-07-30'))[0]===1,'Header filters use original file value dates');
checkAuto($service->recordStatementEvidence($statementId,$proof)['already_recorded'] && countAuto('kreabank_recon_audit')===$audit+1,'Retry is idempotent without duplicate rows or audits');
$user->allowed=false;
rejectAuto(static function()use($service,$statementId,$proof){$service->recordStatementEvidence($statementId,$proof);},'Rejects missing bank/import write permissions');
$user->allowed=true;
rejectAuto(static function()use($service,$proof){$service->recordStatementEvidence(704,$proof);},'Foreign-company attachment target is denied');
foreach (array('foreign','other_account','amount','duplicate_id','balance','overwrite') as $case) {
	$bad=$proof;
	if($case==='foreign')$bad['lines'][0]['staged_id']=704;
	if($case==='other_account')$bad['lines'][0]['staged_id']=703;
	if($case==='amount')$bad['lines'][0]['amount']='4.01';
	if($case==='duplicate_id')$bad['lines'][0]['staged_id']=1003;
	if($case==='balance')$bad['lines'][0]['running_balance']='14.01';
	if($case==='overwrite')$bad['sha256']=str_repeat('b',64);
	rejectAuto(static function()use($service,$statementId,$bad){$service->recordStatementEvidence($statementId,$bad);},'Rejects conflicting original evidence: '.$case);
}
$otherId=(int)$db->fetch_object($db->query("SELECT rowid FROM kbauto_kreabank_statement WHERE ref='ST-1003'"))->rowid;
$db->fail='INSERT INTO kbauto_kreabank_recon_audit';
rejectAuto(static function()use($service,$otherId,$proof){$service->recordStatementEvidence($otherId,$proof);},'Audit failure rolls back original evidence');
$db->fail='';
checkAuto($db->fetch_object($db->query('SELECT import_payload FROM kbauto_kreabank_statement WHERE rowid='.$otherId))->import_payload===null,'Failed attachment leaves no partial metadata');
$db->begin();
$db->query('UPDATE kbauto_kreabank_statement_line SET amount=50 WHERE rowid=1001');
checkAuto($read()[0]->statement_sequence===null,'Changed persisted amounts cannot reuse the original row sequence or balance');
$db->rollback();
checkAuto($ledger===$db->query('SELECT * FROM kbauto_bank ORDER BY rowid')->fetch_all(MYSQLI_ASSOC),'Repeated sorted/filtered reads create no financial records');
print "OK: $checks original statement evidence checks\n";
