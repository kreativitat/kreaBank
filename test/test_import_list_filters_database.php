#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 * ONLY the guarded disposable 127.0.0.1:33317/kreabank_auto_test fixture.
 * This suite resets synthetic tables through the existing native integration base.
 */
require __DIR__ . '/test_bank_integration_database.php';
require_once __DIR__ . '/../lib/kreabank_import_list.lib.php';
$initialImportChecks = $checks;

// Install only the new additive schema in the disposable fixture. Native payments
// are never executed: these records describe representative read projections.
$schema = preg_replace('/--[^\n]*/', '', file_get_contents(__DIR__ . '/../sql/llx_kreabank_payment_batch.sql'));
foreach (explode(';', str_replace('llx_', MAIN_DB_PREFIX, $schema)) as $statement) {
	if (trim($statement) !== '' && !$db->query($statement)) { throw new RuntimeException('Cannot install disposable batch fixture'); }
}
$accountReference = "IMP%_O'B";
$db->query("INSERT INTO kbauto_bank_account (rowid,entity,ref,label,currency_code,clos,courant,rappro,fk_pays) VALUES(32101,1,'" . $db->escape($accountReference) . "','Synthetic import account','EUR',0,1,1,1)");
$detailReference = "IMPORT%_O'Brien";
$legacyReference = "LEGACY%_O'Brien";
stageAuto(90000, '-3.00000003', 'GROUPED-IMPORT', 32101);
$db->query("UPDATE kbauto_kreabank_statement SET date_import='2026-11-13 12:00:00' WHERE rowid=90000");
$db->query("UPDATE kbauto_kreabank_statement_line SET operation_date='2026-11-04',status=2,allocated_amount=3.00000003 WHERE rowid=90000");
$db->query("INSERT INTO kbauto_kreabank_payment_batch (rowid,entity,fk_statement_line,active_statement_line,bank_account_id,amount,currency,operation_date,statement_ref,name,status,source_snapshot,fk_user,datec) VALUES(90000,1,90000,90000,32101,-3.00000003,'EUR','2026-11-04','ST-90000','Synthetic salary group',1,'{}',7,NOW())");
foreach (array(92000=>'-1.00000001', 92001=>'-2.00000002') as $nativeId=>$amount) {
	bankAuto($nativeId, $amount, 'GROUP-' . $nativeId, 32101, '2026-11-04');
	$db->query("UPDATE kbauto_bank SET rappro=1,num_releve='ST-90000' WHERE rowid=$nativeId");
	$db->query("INSERT INTO kbauto_kreabank_payment_batch_line (entity,fk_batch,fk_bank_line,active_bank_line,amount,snapshot_json) VALUES(1,90000,$nativeId,$nativeId,$amount,'{}')");
}
stageAuto(90050, '-3.00000003', 'INCOMPLETE-GROUP-IMPORT', 32101);
$db->query("UPDATE kbauto_kreabank_statement SET date_import='2026-11-13 12:00:00' WHERE rowid=90050");
$db->query("UPDATE kbauto_kreabank_statement_line SET operation_date='2026-11-04',status=2,allocated_amount=3.00000003 WHERE rowid=90050");
$db->query("INSERT INTO kbauto_kreabank_payment_batch (rowid,entity,fk_statement_line,active_statement_line,bank_account_id,amount,currency,operation_date,statement_ref,name,status,source_snapshot,fk_user,datec) VALUES(90050,1,90050,90050,32101,-3.00000003,'EUR','2026-11-04','ST-90050','Synthetic incomplete group',1,'{}',7,NOW())");
foreach (array(92050=>'-1.00000001',92051=>'-2.00000002') as $nativeId=>$amount) {
	bankAuto($nativeId,$amount,'INCOMPLETE-'.$nativeId,32101,'2026-11-04');
	$db->query("UPDATE kbauto_bank SET rappro=" . ($nativeId===92050 ? 1 : 0) . ",num_releve='ST-90050' WHERE rowid=$nativeId");
	$db->query("INSERT INTO kbauto_kreabank_payment_batch_line (entity,fk_batch,fk_bank_line,active_bank_line,amount,snapshot_json) VALUES(1,90050,$nativeId,$nativeId,$amount,'{}')");
}

stageAuto(90010, '5.12345678', 'VISIBLE-PAYMENT', 32101);
$db->query("UPDATE kbauto_kreabank_statement SET ref='" . $db->escape($detailReference) . "',date_import='2026-11-14 23:59:59' WHERE rowid=90010");
$db->query("UPDATE kbauto_kreabank_statement_line SET operation_date='2026-11-14',description='100%_literal O\'Brien',bank_reference='VISIBLE-FILE-REFERENCE' WHERE rowid=90010");
foreach (array(
	90013=>array('5.00',0,93013,'2026-11-13','EUR',0),
	90014=>array('-7.25',2,93014,'2026-11-14','EUR',0),
	90015=>array('11.00',2,99999999,'2026-11-14','EUR',0),
	90016=>array('6.00',2,93016,'2026-11-14','EUR',0),
	90017=>array('13.00',2,93017,'2026-11-14','USD',0),
	90018=>array('-2.00',3,0,'2026-11-14','EUR',0),
	90019=>array('1.00',0,0,'2026-11-14','EUR',1),
	90020=>array('8.00',0,93020,'2026-11-15','EUR',0),
) as $id=>$facts) {
	list($amount,$status,$nativeId,$date,$currency,$duplicate) = $facts;
	$db->query("INSERT INTO kbauto_kreabank_statement_line (rowid,entity,fk_statement,line_uid,line_rank,operation_date,value_date,amount,currency,description,payment_reference,status,fk_native_bank_line,is_duplicate,datec)"
		. " VALUES($id,1,90010,'import-$id'," . ($id-90009) . ",'$date','$date',$amount,'$currency','Synthetic line $id','VISIBLE-$id',$status," . ($nativeId ?: 'NULL') . ",$duplicate,NOW())");
	if ($nativeId && $nativeId !== 99999999) {
		bankAuto($nativeId, $id === 90016 ? '6.01000001' : ($id === 90013 ? '4.99' : $amount), 'NATIVE-' . $id, 32101, $date);
		if ($id !== 90013) { $db->query("UPDATE kbauto_bank SET rappro=1,num_releve='" . $db->escape($detailReference) . "' WHERE rowid=$nativeId"); }
	}
}
stageAuto(90011, '1.00', 'NEXT-DAY-IMPORT', 32101);
$db->query("UPDATE kbauto_kreabank_statement SET date_import='2026-11-15 00:00:00' WHERE rowid=90011");
stageAuto(90012, '1.00', 'OTHER-OWNED-ACCOUNT', 102);
stageAuto(90030, '1.00', 'FOREIGN-IMPORT', 202, 2);
stageAuto(90040, '9999999999999999.12345678', 'EXACT-MAXIMUM', 32101);
$db->query("UPDATE kbauto_kreabank_statement SET date_import='2026-11-13 12:00:00' WHERE rowid=90040");

foreach (array(92010=>array('10.00','2026-11-14 00:00:00',0,0),92011=>array('20.00','2026-11-14 23:59:59',1,2),92012=>array('-30.00','2026-11-15 00:00:00',0,2)) as $id=>$facts) {
	list($amount,$date,$reconciled,$status) = $facts;
	bankAuto($id,$amount,'LEGACY-'.$id,32101,$date);
	$db->query("UPDATE kbauto_bank SET num_releve='" . $db->escape($legacyReference) . "',note='KreaBank import synthetic',rappro=$reconciled WHERE rowid=$id");
	$db->query("INSERT INTO kbauto_kreabank_bankmeta (entity,fk_bank_line,bank_account_id,source_type,idempotency_hash,amount,currency,status,description,datec) VALUES(1,$id,32101,'csv',SHA2('import-$id',256),$amount,'EUR',$status,'Legacy source $id','2026-11-14 23:59:59')");
}
$importReport = new KreaBankIntegration($db,1);

function importIndex($filters = array(), $account = 32101, $limit = 500, $offset = 0)
{
	global $importReport,$db;
	$source = '(' . $importReport->statementsSql($account) . ') h';
	$where = kreabankImportListWhere($db,$filters);
	$total = (int) $db->fetch_object($importReport->query('SELECT COUNT(*) nb FROM ' . $source . $where))->nb;
	$result = $importReport->query('SELECT h.* FROM ' . $source . $where . ' ORDER BY h.imported_at DESC,h.bank_account_id,h.statement_ref' . $db->plimit($limit,$offset));
	$rows = array(); while ($row = $db->fetch_object($result)) { $rows[] = $row; }
	return array($total,$rows);
}
function importDetails($reference, $filters = array(), $account = 32101, $limit = 500, $offset = 0, $sourceType = 'csv')
{
	global $importReport,$db;
	$source = '(' . $importReport->linesSql($account,$reference) . ') h';
	$where = kreabankImportListWhere($db,$filters,true,$reference,$sourceType);
	$total = (int) $db->fetch_object($importReport->query('SELECT COUNT(*) nb FROM ' . $source . $where))->nb;
	$result = $importReport->query('SELECT h.* FROM ' . $source . $where . ' ORDER BY h.operation_date,h.line_rank,h.rowid,h.source_storage' . $db->plimit($limit,$offset));
	$rows = array(); while ($row = $db->fetch_object($result)) { $rows[] = $row; }
	return array($total,$rows);
}
function importIds($rows) { return array_map('intval',array_column($rows,'rowid')); }
function importSnapshots()
{
	global $db;
	$out = array();
	foreach (array('bank','bank_url','paiement','paiement_facture','paiementfourn','paiementfourn_facturefourn','facture','facture_fourn',
		'kreabank_statement','kreabank_statement_line','kreabank_recon_audit','kreabank_payment_batch','kreabank_payment_batch_line','kreabank_payment_batch_draft') as $table) {
		$out[$table] = $db->query('SELECT * FROM kbauto_' . $table . ' ORDER BY rowid')->fetch_all(MYSQLI_ASSOC);
	}
	return $out;
}

$before = importSnapshots();
list($total,$rows) = importIndex(array('statement_ref'=>$detailReference));
checkAuto($total===1 && $rows[0]->statement_ref===$detailReference && (int)$rows[0]->imported_lines===9 && (int)$rows[0]->native_lines===5 && (int)$rows[0]->reconciled_lines===1,
	'Literal header filtering preserves complete source/native/reconciled aggregation');
$referenceHeader = (array)$rows[0];
list($total,$rows) = importIndex(array('statement_ref'=>$detailReference,'imported_lines'=>'>=9','native_lines'=>'5','reconciled_lines'=>'1'));
checkAuto($total===1 && (array)$rows[0]===$referenceHeader,'Combined integer count predicates apply outside the unchanged statement aggregate');
list($total,$rows) = importIndex(array('statement_ref'=>'ST-90000','imported_lines'=>'1','native_lines'=>'2','reconciled_lines'=>'1'));
checkAuto($total===1 && (int)$rows[0]->issue_lines===0,'One grouped source remains reconciled with two native movements rather than a negative pending count');
checkAuto(importIndex(array('bank_account_ref'=>$accountReference),0)[0]===6,'Account-reference filtering uses literal quotes and wildcard characters within owned statements');
checkAuto(importIndex(array('statement_ref'=>"' OR 1=1 --"))[0]===0 && importIndex(array('imported_lines'=>'1 OR 1=1'))[0]===0,'Text and count filters cannot inject SQL');
list($total,$rows) = importIndex(array('imported_from'=>'2026-11-14','imported_to'=>'2026-11-14'));
checkAuto($total===2 && in_array($detailReference,array_column($rows,'statement_ref'),true) && in_array($legacyReference,array_column($rows,'statement_ref'),true),'Import upper-date bounds include 23:59:59 and exclude the next midnight without trimming grouped lines');
checkAuto(importIndex(array('imported_from'=>'2026-02-30'))[0]===0 && importIndex(array('reconciled_lines'=>'-1'))[0]===0,'Invalid import dates and unsigned count expressions fail closed');
checkAuto(importIndex(array(),202)[0]===0 && importIndex(array('bank_account_ref'=>'TEST B'),0)[0]===0,'Selected and text bank filters cannot expose another company');
checkAuto(importDetails('ST-90030',array(),202)[0]===0 && importDetails($detailReference,array(),102)[0]===0,'Exact detail references retain both company and account ownership boundaries');

checkAuto(importDetails($detailReference,array('description'=>"100%_literal O'Brien"))[0]===1,'Description filters treat quotes and LIKE wildcard characters literally');
checkAuto(importIds(importDetails($detailReference,array('description'=>'VISIBLE-PAYMENT'))[1])===array(90010),'Visible payment references are searchable within detail rows');
checkAuto(importDetails($detailReference,array('description'=>'VISIBLE-FILE-REFERENCE'))[0]===1
	&& importDetails($detailReference,array('description'=>'VISIBLE-FILE-REFERENCE'),32101,500,0,'fintable')[0]===0,'File bank references are searchable while hidden Fintable provider identifiers are excluded');
checkAuto(importDetails($detailReference,array('description'=>"' OR 1=1 --"))[0]===0,'Detail search text cannot widen the scoped statement');
checkAuto(importIds(importDetails($detailReference,array('amount'=>'5,12345678'))[1])===array(90010)
	&& importDetails($detailReference,array('amount'=>'5.12345679'))[0]===0,'Signed amount filters preserve every source decimal place and comma input');
checkAuto(importDetails('ST-90040',array('amount'=>'9999999999999999.12345678'))[0]===1
	&& importDetails('ST-90040',array('amount'=>'9999999999999999.12345679'))[0]===0,'Maximum-size DECIMAL evidence is never rounded through floating-point filter parsing');
checkAuto(importIds(importDetails($detailReference,array('amount'=>'<-2'))[1])===array(90014),'Signed amount comparisons retain the original direction');
checkAuto(importDetails($detailReference,array('operation_from'=>'2026-11-14','operation_to'=>'2026-11-14'))[0]===7,'Staged booking-date bounds filter lines without changing statement progress totals');
checkAuto(importIds(importDetails($legacyReference,array('operation_from'=>'2026-11-14','operation_to'=>'2026-11-14'))[1])===array(92010,92011),'Legacy booking-date end bounds retain the complete final day');
checkAuto(importIds(importDetails($detailReference,array('native_id'=>'93014'))[1])===array(90014),'Native ID header filtering uses the actual scalar bank movement identity');
checkAuto(importDetails('ST-90000',array('native_id'=>'92000'))[0]===0 && importDetails('ST-90000',array('status'=>'reconciled'))[0]===1,'A normalized batch has no fake representative native ID');
checkAuto(importDetails('ST-90050',array('status'=>'review'))[0]===1 && importDetails('ST-90050',array('status'=>'reconciled'))[0]===0
	&& (int)importIndex(array('statement_ref'=>'ST-90050'))[1][0]->issue_lines===1,'A partially un-reconciled native batch stays a review issue in both header and detail projections');
checkAuto(importIds(importDetails($detailReference,array('status'=>'duplicate'))[1])===array(90019),'Duplicate filtering retains explicit duplicate evidence only');

foreach (array($detailReference,$legacyReference,'ST-90000','ST-90050') as $ref) {
	foreach (importDetails($ref)[1] as $row) {
		$status = array_search(kreabankIntegrationLineState($row,$ref),kreabankStatementListStatusOptions(),true);
		checkAuto($status!==false,'Every displayed staged/legacy/group state has an explicit header-filter option');
		$matches = importDetails($ref,array('status'=>$status))[1];
		$identity = $row->source_storage . ':' . $row->rowid;
		$identities = array_map(static function ($candidate) { return $candidate->source_storage . ':' . $candidate->rowid; },$matches);
		checkAuto(in_array($identity,$identities,true),'Header status SQL agrees with the actual renderer for '.$identity);
	}
}
foreach (array(array('status'=>'unknown'),array('amount'=>'1e3'),array('amount'=>'1.123456789'),array('native_id'=>'93014;DROP'),array('operation_to'=>'2026-99-01')) as $filters) {
	checkAuto(importDetails($detailReference,$filters)[0]===0,'Malformed detail filters fail closed rather than displaying unfiltered evidence');
}

foreach (array(false,true) as $detail) {
	$full = $detail ? importDetails($detailReference,array('amount'=>'>=0')) : importIndex();
	$seen = array();
	for ($offset=0;$offset<$full[0];$offset+=2) {
		$page = $detail ? importDetails($detailReference,array('amount'=>'>=0'),32101,2,$offset) : importIndex(array(),32101,2,$offset);
		checkAuto($page[0]===$full[0],'Count and data queries use identical outer predicates on every page');
		foreach ($page[1] as $row) { $seen[] = $detail ? $row->source_storage . ':' . $row->rowid : $row->bank_account_id . ':' . $row->statement_ref; }
	}
	checkAuto(count($seen)===$full[0] && count(array_unique($seen))===$full[0],'Stable filtered pagination loses or repeats no statement/source identity');
}
checkAuto(importSnapshots()===$before,'Repeated index/detail filtering changes no source evidence, native movements, invoices, payments, allocations, batch mappings or audit');
echo 'OK: '.($checks-$initialImportChecks)." imported statement filter database checks\n";
