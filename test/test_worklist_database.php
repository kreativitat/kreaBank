#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 * ONLY disposable 127.0.0.1:33317/kreabank_auto_test synthetic data.
 * The imported fixture resets kbauto_* tables; never run concurrently with it.
 */
require __DIR__ . '/test_automatic_reconciliation_database.php';
require_once __DIR__ . '/../class/KreaBankWorklist.class.php';
$checks = 0;

function worklistDbState($extra = array())
{
	return kreabankWorklistState(array_merge(array('search_description'=>'WORKLIST-QA'), $extra), 25);
}
function worklistBusinessCounts()
{
	return array(countAuto('bank'),countAuto('kreabank_statement'),countAuto('kreabank_statement_line'),countAuto('kreabank_recon_audit'));
}

$db->query("INSERT INTO kbauto_bank_account(rowid,entity,ref,label,currency_code,clos,courant,rappro,fk_pays) VALUES"
	. "(30101,1,'WL A','Worklist synthetic bank A','EUR',0,1,1,1),"
	. "(30102,1,'WL B','Worklist synthetic bank B','EUR',0,1,1,1),"
	. "(30201,2,'WL FOREIGN','Foreign synthetic bank','EUR',0,1,1,1),"
	. "(30103,1,'WL CASH','Synthetic cash account','EUR',0,2,0,1),"
	. "(30104,1,'WL CLOSED','Synthetic closed bank','EUR',1,1,1,1)");
foreach (array(
	31001=>array('-3300.00000000',30101,1,'2026-11-11','SALARY'),
	31002=>array('420.00000000',30102,1,'2026-11-11','RECEIPT'),
	31003=>array('13.00000000',30201,2,'2026-11-11','FOREIGN'),
	31004=>array('1.00000000',30103,1,'2026-11-11','CASH'),
	31005=>array('2.00000000',30104,1,'2026-11-11','CLOSED'),
	31006=>array('12.34567890',30101,1,'2026-11-12','100%_!'),
	31007=>array('9999999999999999.12345678',30101,1,'2026-11-13','LARGE A'),
	31008=>array('9999999999999999.12345679',30101,1,'2026-11-13','LARGE B'),
	31009=>array('1.00000000',30101,1,'2026-11-13','DUPLICATE'),
) as $id=>$fixture) {
	stageAuto($id,$fixture[0],'WORKLIST-QA-'.$id,$fixture[1],$fixture[2]);
	$db->query("UPDATE kbauto_kreabank_statement_line SET operation_date='" . $fixture[3] . "',value_date='" . $fixture[3]
		. "',description='WORKLIST-QA " . $db->escape($fixture[4]) . "' WHERE rowid=$id");
}
$db->query('UPDATE kbauto_kreabank_statement_line SET is_duplicate=1 WHERE rowid=31009');
// A staged and a legacy-native source deliberately share the same numeric ID.
bankAuto(31001,'17.00000000','WORKLISTQA31001',30102,'2026-11-11 23:59:59');
$db->query("UPDATE kbauto_bank SET label='WORKLIST-QA LEGACY',num_releve='WL-LEGACY',note='KreaBank import synthetic' WHERE rowid=31001");
bankAuto(31002,'420.00000000','WORKLISTQA31002',30102,'2026-11-11');
$db->query("UPDATE kbauto_bank SET rappro=1,num_releve='ST-31002' WHERE rowid=31002");
$db->query('UPDATE kbauto_kreabank_statement_line SET fk_native_bank_line=31002,status=2,allocated_amount=420 WHERE rowid=31002');
bankAuto(31003,'13.00000000','WORKLISTQA31003',30201,'2026-11-11');
$db->query("UPDATE kbauto_bank SET label='WORKLIST-QA FOREIGN LEGACY',num_releve='WL-FOREIGN',note='KreaBank import synthetic' WHERE rowid=31003");
bankAuto(31004,'1.00000000','WORKLISTQA31004',30103,'2026-11-11');
$db->query("UPDATE kbauto_bank SET label='WORKLIST-QA CASH LEGACY',num_releve='WL-CASH',note='KreaBank import synthetic' WHERE rowid=31004");
bankAuto(31906,'12.34567890','WORKLISTQA31906',30101,'2026-11-12');
$db->query("UPDATE kbauto_kreabank_statement_line SET payment_reference='WORKLISTQA31906' WHERE rowid=31006");

$before = worklistBusinessCounts();
$tableCount = $db->num_rows($db->query('SHOW TABLES'));
$worklist = new KreaBankWorklist($db, 1, $service);
checkAuto(!$worklist->batchesAvailable(), 'Missing optional batch schema leaves the separate read list available');
$sources = $worklist->sources(worklistDbState());
checkAuto(count($sources) === 5 && isset($sources['staged:31001'],$sources['native:31001']), 'Combined pending projection contains staging and legacy sources together without numeric ID collisions');
checkAuto(!isset($sources['staged:31003'],$sources['native:31003'],$sources['staged:31004'],$sources['native:31004'],$sources['staged:31005'],$sources['staged:31009']), 'Pending sources exclude foreign companies, cash, closed accounts and duplicate evidence');
$staged = $worklist->load('staged:31001');
$native = $worklist->load('native:31001');
checkAuto((int) $staged['fk_statement'] === 31001 && (int) $native['fk_statement'] === 0
	&& (int) $staged['bank_account_id'] === 30101 && (int) $native['bank_account_id'] === 30102,
	'Explicit source loading cannot confuse colliding staging/native IDs or their bank accounts');
rejectAuto(static function () use ($worklist) { $worklist->load('staged:31003'); }, 'Direct foreign-company staged lookup is denied');
rejectAuto(static function () use ($worklist) { $worklist->load('native:31003'); }, 'Direct foreign-company native lookup is denied');
rejectAuto(static function () use ($db,$service) { new KreaBankWorklist($db, 0, $service); }, 'Missing company context is denied');
checkAuto(!$worklist->sources(worklistDbState(array('search_account'=>'30201'))), 'Foreign account filter cannot widen direct owner-company scope');
$all = $worklist->sources(worklistDbState(array('search_status'=>'all')));
checkAuto(count($all) === 7 && isset($all['staged:31005']) && !isset($all['native:31002']), 'All-status list retains closed-account evidence while mapped native rows are counted only through their staged source');
$completed = $worklist->sources(worklistDbState(array('search_status'=>'reconciled')));
checkAuto(array_keys($completed) === array('staged:31002'), 'Completed list includes the verified staged/native pair once');
$dated = $worklist->sources(worklistDbState(array('search_date_from'=>'2026-11-11','search_date_to'=>'2026-11-11')));
checkAuto(count($dated) === 2 && isset($dated['native:31001']), 'SQL date filter includes the whole ending day for native timestamps');
$amount = $worklist->sources(worklistDbState(array('search_amount'=>'>9999999999999999.12345678')));
checkAuto(array_keys($amount) === array('staged:31008') && (string) $amount['staged:31008']['amount'] === '9999999999999999.12345679', 'SQL signed amount filtering preserves all eight decimal places for maximum-size evidence');
$literal = $worklist->sources(kreabankWorklistState(array('search_description'=>'100%_!'), 25));
checkAuto(array_keys($literal) === array('staged:31006'), 'SQL text filters escape LIKE wildcards as literal bank evidence');
$accountIds = array_map('intval', array_column($worklist->accounts(), 'id'));
checkAuto(in_array(30101,$accountIds,true) && in_array(30102,$accountIds,true)
	&& !in_array(30201,$accountIds,true) && !in_array(30103,$accountIds,true), 'Both account filter choices are limited to directly owned bank accounts');

$compact = $worklist->compact('staged:31006');
checkAuto($compact['key'] === 'staged:31006' && in_array(30101,$compact['payment_accounts'],true), 'Compact scan records the actual proposed payment bank separately from statement scope');
checkAuto(!array_intersect(array_keys($compact), array('pair','review','signature','token','allocation','selected')), 'Compact scan cache does not contain an executable approval');
$prepared = array();
foreach ($sources as $key=>$line) {
	$prepared[] = array('key'=>$key,'line'=>$line,'status'=>'pending','match'=>$key === 'staged:31008' ? 'LAST-PAYMENT' : '',
		'payment_accounts'=>$key === 'staged:31008' ? array(30102) : array(),'payment_account'=>$key === 'staged:31008' ? 'WL B' : '');
}
$state = worklistDbState(array('limit'=>1,'search_match'=>'LAST-PAYMENT','search_payment_account'=>'30102'));
$page = kreabankWorklistPage(kreabankWorklistFilterRows($prepared,$state),$state);
checkAuto($page['total'] === 1 && $page['rows'][0]['key'] === 'staged:31008', 'Candidate filters run over the complete company-scoped result before native pagination');
checkAuto(worklistBusinessCounts() === $before && $db->num_rows($db->query('SHOW TABLES')) === $tableCount,
	'Source loading, compact matching and missing-schema discovery create no financial, audit or schema records');

// Optional batch tables must not make ordinary or non-executable rows unreadable.
$schema = preg_replace('/--[^\n]*/','',file_get_contents(__DIR__ . '/../sql/llx_kreabank_payment_batch.sql'));
foreach (explode(';',str_replace('llx_',MAIN_DB_PREFIX,$schema)) as $statement) { if (trim($statement) !== '') { $db->query($statement); } }
foreach (array('salary','payment_salary') as $table) {
	$sql = preg_replace('/--[^\n]*/','',file_get_contents(DOL_DOCUMENT_ROOT . '/install/mysql/tables/llx_' . $table . '.sql'));
	foreach (explode(';',str_replace('llx_',MAIN_DB_PREFIX,$sql)) as $statement) { if (trim($statement) !== '') { $db->query($statement); } }
}
stageAuto(33501,'50.00','WORKLIST-QA-PARTIAL',30101);
$db->query('UPDATE kbauto_kreabank_statement_line SET status=1,allocated_amount=10 WHERE rowid=33501');
$_SESSION = array();
$ready = new KreaBankWorklist($db,1,$service);
$ready->sources(worklistDbState(array('search_status'=>'all')));
$scalar = $ready->prepare('staged:31002');
checkAuto((int) $scalar['line']['status'] === 2 && !$scalar['line']['needs_review'] && !empty($scalar['completed'])
	&& empty($scalar['batch']['active']), 'Installed batch schema does not impose pending/unmapped execution rules on an ordinary reconciled row');
$partial = $ready->prepare('staged:33501');
checkAuto((int) $partial['line']['status'] === 1 && $partial['pair'] === null, 'Partial staging remains readable in the all-status view without offering a normal executable pair');
$closed = $ready->prepare('staged:31005');
checkAuto(!$closed['line']['account_open'] && $closed['pair'] === null, 'Closed account evidence stays readable without executing the pending-account matcher');
$policy = new KreaBankAccountPairs($db,1);
$policy->configure($user,array(30101=>30102));
$restricted = $ready->prepare('staged:31006');
checkAuto($restricted['pair'] === null && $restricted['line']['bank_account_id'] == 30101, 'A restricted account can be inspected without being accepted for an ordinary payment or batch');
$policy->configure($user,array());
checkAuto($ready->load('staged:31008')['amount_decimal'] === '9999999999999999.12345679', 'Display evidence retains the exact projected source amount independently of legacy float service fields');

stageAuto(33500,'-30.00','WORKLIST-QA-GROUP',30101);
bankAuto(34500,'-10.00','GROUP-A',30101); bankAuto(34501,'-20.00','GROUP-B',30101);
$db->query("UPDATE kbauto_bank SET rappro=1,num_releve='ST-33500' WHERE rowid IN (34500,34501)");
$db->query('UPDATE kbauto_kreabank_statement_line SET status=2,allocated_amount=30 WHERE rowid=33500');
$db->query("INSERT INTO kbauto_kreabank_payment_batch(rowid,entity,fk_statement_line,active_statement_line,bank_account_id,amount,currency,operation_date,statement_ref,name,status,source_snapshot,fk_user,datec) VALUES(39500,1,33500,33500,30101,-30,'EUR','2026-11-01','ST-33500','Read-only group',1,'{}',7,NOW())");
$db->query("INSERT INTO kbauto_kreabank_payment_batch_line(entity,fk_batch,fk_bank_line,active_bank_line,amount,snapshot_json) VALUES(1,39500,34500,34500,-10,'{}'),(1,39500,34501,34501,-20,'{}')");
$validGroup = (new KreaBankWorklist($db,1,$service))->prepare('staged:33500');
checkAuto(!$validGroup['line']['needs_review'] && count($validGroup['batch']['active']['components']) === 2, 'A normalized complete group reads through the new list without a representative native ID');
$db->query('UPDATE kbauto_bank SET amount=-20.01 WHERE rowid=34501');
$invalidGroup = new KreaBankWorklist($db,1,$service);
$groupRow = $invalidGroup->prepare('staged:33500');
checkAuto($groupRow['line']['needs_review'] && (int) $groupRow['line']['group_valid'] === 0
	&& $invalidGroup->compact('staged:33500')['status'] === 'review', 'A changed native group component is marked for review in both the row and compact filter index');
$db->query('UPDATE kbauto_bank SET amount=-20 WHERE rowid=34501');
stageAuto(33502,'-15.00','WORKLIST-QA-DRAFT',30101);
bankAuto(34510,'-5.00','DRAFT-A',30101); bankAuto(34511,'-10.00','DRAFT-B',30101);
$db->query("INSERT INTO kbauto_kreabank_payment_batch_draft(entity,fk_user,fk_statement_line,name,ids_json,datec) VALUES(1,7,33502,'Read-only draft','[34510,34511]',NOW())");
$draft = (new KreaBankWorklist($db,1,$service))->compact('staged:33502');
checkAuto($draft['status'] === 'draft' && $draft['payment_accounts'] === array(30101) && $draft['payment_account'] === 'WL A', 'Draft payment-bank filters use the actual selected component accounts');

// Explicit search applies literal SQL predicates before provider limits and widens
// its dates without changing the ordinary suggestion window.
$db->query("INSERT INTO kbauto_societe(rowid,entity,nom,client) VALUES(41001,1,'Old search synthetic customer',1)");
foreach (array(41101=>array('WORKLIST-OLD-INVOICE',1,0),41102=>array('WORKLIST-PAID-PARENT',2,1),41103=>array('WORKLIST-FOREIGN-INVOICE',1,0)) as $id=>$facts) {
	$entity = $id === 41103 ? 2 : 1;
	$db->query("INSERT INTO kbauto_facture(rowid,ref,entity,fk_soc,datec,datef,total_ttc,fk_statut,paye,type) VALUES($id,'" . $facts[0] . "',$entity,41001,NOW(),'2024-01-01',37.45," . $facts[1] . ',' . $facts[2] . ',0)');
}
$db->query("INSERT INTO kbauto_paiement(rowid,entity,ref,datec,datep,amount,fk_bank,fk_paiement) VALUES(41201,1,'WORKLIST-OLD-PAYMENT',NOW(),'2024-02-01',37.45,0,1)");
$db->query('INSERT INTO kbauto_paiement_facture(fk_paiement,fk_facture,amount) VALUES(41201,41102,37.45)');
for ($i=0; $i<105; $i++) {
	$open = 42000+$i; $paid = 43000+$i; $payment = 44000+$i;
	$db->query("INSERT INTO kbauto_facture(rowid,ref,entity,fk_soc,datec,datef,total_ttc,fk_statut,paye,type) VALUES($open,'EARLIER-OPEN-$i',1,41001,NOW(),'2023-01-01',1,1,0,0),($paid,'EARLIER-PAID-$i',1,41001,NOW(),'2023-01-01',1,2,1,0)");
	$db->query("INSERT INTO kbauto_paiement(rowid,entity,ref,datec,datep,amount,fk_bank,fk_paiement) VALUES($payment,1,'EARLIER-PAYMENT-$i',NOW(),'2023-01-01',1,0,1)");
	$db->query("INSERT INTO kbauto_paiement_facture(fk_paiement,fk_facture,amount) VALUES($payment,$paid,1)");
}
stageAuto(33600,'37.45','WORKLIST-OLD-INVOICE',30101);
$searched = new KreaBankWorklist($db,1,$service); $line = $searched->load('staged:33600');
$initial = $searched->candidates($line);
checkAuto(!isset($initial['customer_invoice__41101'],$initial['payment__41201']), 'Ordinary suggestions retain their existing bounded booking-date window');
$typedInvoice = $searched->candidates($line,'WORKLIST-OLD-INVOICE');
checkAuto(isset($typedInvoice['customer_invoice__41101']) && !$searched->searchTruncated(), 'Typed invoice search reaches an old dated target beyond the initial provider page');
$typedPayment = $searched->candidates($line,'WORKLIST-OLD-PAYMENT');
checkAuto(isset($typedPayment['payment__41201']) && !$searched->searchTruncated(), 'Typed payment search applies its reference predicate before the 101-row provider limit');
checkAuto(!$service->searchWorklistOpenDocuments($line,'WORKLIST-FOREIGN-INVOICE')['suggestions'], 'Widened document search retains direct current-company invoice ownership');
$db->query("UPDATE kbauto_bank SET dateo='2024-01-01' WHERE rowid=31906");
$correctedSource = $searched->load('staged:31006');
$corrected = $service->getWorklistCorrectedSuggestions($correctedSource,31906);
checkAuto(in_array(31906,array_map('intval',array_column($corrected,'doc_id')),true), 'A reviewed corrected native movement remains discoverable outside the ordinary date window');
print "OK: $checks separate worklist database checks\n";
