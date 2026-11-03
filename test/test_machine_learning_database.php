#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 * ONLY the disposable localhost:33317/kreabank_auto_test fixture.
 */
require __DIR__ . '/test_automatic_reconciliation_database.php';
$initialChecks = $checks;
class LearningDatabaseProbe extends KreaBankService
{
	public function call($method, ...$arguments) { return $this->$method(...$arguments); }
}
$conf->entity = 1;
$conf->global->KREABANK_BATCH_ML_ENABLED = 1;
$conf->global->KREABANK_SUPPLIER_ML_ENABLED = 1;
$learning = new LearningDatabaseProbe($db, $user, $langs);
$vector = $learning->call('normalizeBatchMlVector', array(1,12,135,987.65,70,0));
bankAuto(30000, '-987.65', 'MANUAL-GROUND-TRUTH');
$db->query('UPDATE kbauto_bank SET rappro=1 WHERE rowid=30000');
checkAuto(!$learning->call('appendBatchMlSample', $vector, 1), 'A batch sample requires an explicit native movement identity');
checkAuto($learning->call('appendBatchMlSample', $vector, 1, '2026-11-03', 30000), 'Versioned normalized snapshot is saved');
$stored=json_decode($db->query('SELECT features_json FROM kbauto_kreabank_ml_sample ORDER BY rowid DESC LIMIT 1')->fetch_object()->features_json,true);
checkAuto($stored['version']===2 && $stored['bank_line_id']===30000 && $learning->call('validateBatchMlVector',$stored['features'])===$vector, 'Database storage preserves feature values, schema and source identity');
checkAuto($learning->call('loadBatchMlSamples')[0]['features']===$vector, 'Database read does not normalize stored features again');
$db->query("INSERT INTO kbauto_kreabank_ml_sample (entity,label,features_json,datec) VALUES (1,1,'[1,0.3,0.5,0.6,0.7,0]',NOW()),(1,0,'{\"version\":2,\"features\":[0,0,0,0,0,0]}',NOW()),(2,0,'[0,0,0,0,0,0]',NOW())");
$report=$learning->getBatchMlValidationReport();
checkAuto($report['sample_count']===1 && $report['legacy_sample_count']===1 && $report['invalid_sample_count']===1, 'Unversioned/incompatible data remains stored, excluded and reported per company');
checkAuto(countAuto('kreabank_ml_sample')===4, 'Loading never deletes historical learning evidence');
checkAuto($learning->call('appendBatchMlSample',$vector,0,'2026-11-03',30000), 'A corrected manual outcome is retained');
$loaded=$learning->call('loadBatchMlSamples');
checkAuto(count($loaded)===1 && $loaded[0]['label']===0, 'Only the latest manual label per movement influences learning');
$db->query('UPDATE kbauto_bank SET rappro=0 WHERE rowid=30000');
checkAuto($learning->call('loadBatchMlSamples')===array(), 'Undo invalidates training without deleting stored evidence');
$db->query('UPDATE kbauto_bank SET rappro=1 WHERE rowid=30000');
$db->query('UPDATE kbauto_bank SET fk_account=202 WHERE rowid=30000');
checkAuto($learning->call('loadBatchMlSamples')===array(), 'Moving a source to another company revokes its training eligibility');
$db->query('UPDATE kbauto_bank SET fk_account=101 WHERE rowid=30000');
// A long-lived service must not keep using a model whose sources were undone.
for ($i=0; $i<24; $i++) {
	$id=34000+$i; bankAuto($id, '-10.00', 'MODEL-SOURCE-'.$i);
	$db->query('UPDATE kbauto_bank SET rappro=1 WHERE rowid='.$id);
	$learning->call('appendBatchMlSample',array((float)($i%2),0.2+$i/1000,0.4,0.5,0.6,0.0),$i%2,'2026-11-03',$id);
}
checkAuto(is_object($learning->call('getBatchMlClassifier')), 'Eligible distinct sources train a live batch classifier');
$db->query('UPDATE kbauto_bank SET rappro=0 WHERE rowid BETWEEN 34000 AND 34023');
checkAuto($learning->call('getBatchMlClassifier')===null, 'Reused service rechecks undone sources before returning an in-memory classifier');
$count=countAuto('kreabank_ml_sample');
$manualLine=array('rowid'=>30000,'amount'=>-987.65,'operation_date'=>'2026-11-03');
$links=array(array('doc_type'=>'native_bank','fk_doc'=>30000));
$learning->call('learnBatchMlFromReconciliation',$manualLine,$links,'auto_native',1,$vector,30000);
$learning->call('learnBatchMlFromReconciliation',$manualLine,$links,'manual',0,array(),30000);
checkAuto(countAuto('kreabank_ml_sample')===$count, 'Automatic decisions and absent pre-mutation snapshots cannot become training truth');
$learning->call('learnBatchMlFromReconciliation',$manualLine,$links,'manual',0,$vector,30000);
checkAuto(countAuto('kreabank_ml_sample')===$count+1 && count($learning->call('loadBatchMlSamples'))===1, 'Confirmed manual learning retains exact snapshot and deduplicates the source');

$db->query("INSERT INTO kbauto_societe (rowid,entity,nom,fournisseur) VALUES (60011,1,'ACME Coffee Roasters',1),(60022,1,'Northern Paper Mills',1),(60033,2,'Foreign supplier',1)");
for($i=0;$i<24;$i++) {
	$id=31000+$i; $supplier=$i<12?60011:60022; $label=$i<12?'ACME Coffee Roasters':'Northern Paper Mills';
	bankAuto($id,'-75.00','HISTORICAL'.$i);
	$db->query("UPDATE kbauto_bank SET rappro=1,label='$label' WHERE rowid=$id");
	$db->query("INSERT INTO kbauto_bank_url (fk_bank,url_id,url,label,type) VALUES ($id,$supplier,'/societe/card.php?socid=','Supplier','company')");
}
// A positive refund, a foreign account and a mixed-company linkage must not train the expense model.
foreach(array(32000,32001,32002) as $id) {
	bankAuto($id,$id===32000?'75.00':'-75.00','EXCLUDED',$id===32001?202:101);
	$db->query("UPDATE kbauto_bank SET rappro=1,label='ACME Coffee Roasters' WHERE rowid=$id");
	$db->query("INSERT INTO kbauto_bank_url (fk_bank,url_id,url,label,type) VALUES ($id,60011,'/societe/card.php?socid=','Supplier','company')");
}
$db->query("INSERT INTO kbauto_bank_url (fk_bank,url_id,url,label,type) VALUES (32002,60033,'/societe/card.php?socid=','Foreign supplier','company')");
$training=$learning->call('loadSupplierMlTrainingSamples',101);
$bankIds=array_column($training['samples'],'bank_line_id');
checkAuto(!array_intersect($bankIds,array(32000,32001,32002)) && count(array_intersect($bankIds,range(31000,31023)))===24, 'Supplier training uses current-company reconciled outflows and excludes mixed-company links');
$target=$training['samples'][array_search(31000,$bankIds)];
checkAuto($target['line']['native_label']==='ACME Coffee Roasters' && $target['features']===$learning->call('buildSupplierMlFeatureVector',$target['line']), 'Historical native labels share the same feature builder as inference');
stageAuto(33000,'-75.00','ACME COFFEE ROASTERS');
$line=$learning->getBulkReviewLine(33000,'staged');
$prediction=$learning->predictSupplierForLine($line);
checkAuto($prediction['is_confident']===1 && $prediction['predicted_socid']===60011, 'Real scoped historical query supports the owned source supplier');
$foreign=$line; $foreign['bank_account_id']=202;
checkAuto($learning->predictSupplierForLine($foreign)['reason']==='outside_company' && $learning->predictBatchMl($foreign)['reason']==='outside_company', 'Both prediction APIs deny forged foreign-account sources');
$unknown=$line; $unknown['description']='UNKNOWN RAILWAY GROUP';
checkAuto($learning->predictSupplierForLine($unknown)['reason']==='insufficient_identity_support', 'Identical expense amount cannot identify an unknown supplier');
checkAuto((int)$db->query('SELECT status FROM kbauto_kreabank_statement_line WHERE rowid=33000')->fetch_object()->status===0, 'Learning and predictions leave the financial source pending');
$conf->entity=2;
checkAuto(!$learning->call('isBatchMlEnabled') && !$learning->call('isSupplierMlEnabled'), 'A cached service cannot follow a global company switch');
$conf->entity=1;
echo 'OK: '.($checks-$initialChecks)." machine learning database checks\n";
