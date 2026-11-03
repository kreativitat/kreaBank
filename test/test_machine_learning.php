#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Synthetic vectors and private temporary files only. No installation bootstrap/database.
 */
$writer = ($argv[1] ?? '') === '--template-writer';
$testData = $writer ? $argv[2] : sys_get_temp_dir() . '/kreabank-ml-' . bin2hex(random_bytes(8));
if (!$writer) { mkdir($testData, 0700); }
define('DOL_DATA_ROOT', $testData);
define('DOL_DOCUMENT_ROOT', getenv('KREABANK_DOLIBARR_ROOT') ?: dirname(__DIR__, 3));
function getDolGlobalInt($name, $default = 0) { return (int) ($GLOBALS['conf']->global->$name ?? $default); }
function getDolGlobalString($name, $default = '') { return (string) ($GLOBALS['conf']->global->$name ?? $default); }
function dol_string_nospecial($value) { return $value; }
function dol_mkdir($path) { return is_dir($path) || mkdir($path, 0700, true); }
function dol_syslog($message, $level = 0) {}
function dol_print_date($timestamp, $format = '%Y-%m-%d') { return gmdate(strtr($format, array('%Y'=>'Y','%m'=>'m','%d'=>'d','%H'=>'H','%M'=>'i','%S'=>'s')), $timestamp); }
$conf = (object) array('entity' => 1, 'global' => new stdClass());
require_once __DIR__ . '/../class/KreaBankService.class.php';
class LearningProbe extends KreaBankService
{
	public $fixtureSamples = array();
	public $supplierTraining = array();
	public $owned = true;
	public $failPredictions = false;
	public function __construct() { $this->entity = 1; }
	public function call($method, ...$arguments) { return $this->$method(...$arguments); }
	public function classifier(&$meta) { return parent::instantiateBatchMlClassifier(24, $meta); }
	protected function loadBatchMlSamples() { return $this->fixtureSamples; }
	protected function loadSupplierMlTrainingSamples($account = 0) { return $this->supplierTraining; }
	protected function isMlLineOwned($line) { return $this->owned; }
	protected function instantiateBatchMlClassifier($count, &$meta) {
		if ($this->failPredictions) { $meta = array('code' => 'broken'); return new class {
			public function train($x, $y) {}
			public function predict($x) { throw new RuntimeException('Synthetic failure'); }
		}; }
		return parent::instantiateBatchMlClassifier($count, $meta);
	}
}
class HeaderLearningProbe extends KreaBankParser
{
	public function call($method, ...$arguments) { return $this->$method(...$arguments); }
}
if ($writer) {
	$service = new LearningProbe();
	for ($i=0; $i<20; $i++) {
		$service->call('persistTemplateMlSamples', array('amount'=>0), array('columns'=>array(array('label'=>'Concurrent '.$argv[3].' header '.$i))));
	}
	exit(0);
}
$checks = 0;
function checkLearning($condition, $label) {
	global $checks; $checks++;
	if (!$condition) { throw new RuntimeException($label); }
}
try {
	$service = new LearningProbe();
	$parser = new HeaderLearningProbe();
	checkLearning($service->call('isBatchPhpMlAvailable'), 'Bundled PHP-ML loads on the test runtime');
	$raw = array(1, 12, 135, 987.65, 70, 0);
	$vector = $service->call('normalizeBatchMlVector', $raw);
	checkLearning($vector === $service->call('validateBatchMlVector', $vector), 'Normalized snapshot values survive validation exactly');
	checkLearning($vector !== $service->call('normalizeBatchMlVector', $vector), 'Fixture reproduces the former destructive double scaling');
	foreach (array(array(0,0,0,0,0), array(0,0,0,0,0,0,0), array(0,0,INF,0,0,0), array(0,0,NAN,0,0,0), array(0,0,'bad',0,0,0), array(0,0,1.1,0,0,0)) as $bad) {
		checkLearning($service->call('validateBatchMlVector', $bad) === array(), 'Malformed or incompatible normalized vectors are rejected');
	}
	checkLearning($service->call('normalizeBatchMlVector', array(0,0,'bad',0,0,0)) === array(), 'Malformed raw vectors are rejected before feature generation');
	checkLearning($service->call('batchMlDistance', array(1,0), array(1,0,1)) === 999.0, 'Different dimensions cannot look like zero-distance neighbors');

	// Exercise all four actual vendor implementations, with no classifier test doubles.
	foreach (array('knn', 'naive_bayes', 'decision_tree', 'random_forest') as $code) {
		$conf->global->KREABANK_BATCH_ML_CLASSIFIER = $code;
		$meta = array(); $model = $service->classifier($meta);
		checkLearning($meta['code'] === $code, 'Configured classifier does not silently fall back: ' . $code);
		$x = $y = array();
		for ($i=0; $i<24; $i++) { $x[] = array((float) ($i%2), $i/30, $i/40, 0.2, 0.3, 0.0); $y[] = $i%2; }
		$model->train($x, $y);
		checkLearning(in_array((int) $model->predict($x[1]), array(0,1), true), 'Actual classifier predicts: ' . $code);
		$key = $service->call('buildBatchMlClassifierCacheKey', array(array('features'=>$vector, 'label'=>1)), $meta);
		$service->call('persistBatchMlClassifierCache', $model, $key, $meta);
		$cached = $service->call('loadCachedBatchMlClassifier', $key);
		checkLearning($cached && get_class($cached) === get_class($model), 'Cache restores the configured class: ' . $code);
		checkLearning($cached->predict($x[1]) === $model->predict($x[1]), 'Cache preserves prediction: ' . $code);
		checkLearning((fileperms($service->call('getBatchMlClassifierCachePath')) & 0777) === 0600, 'Model cache contains private training data and is owner-only');
	}
	$conf->global->KREABANK_BATCH_ML_CLASSIFIER = 'knn';
	$samples = array_fill(0, 200, array('features'=>$vector, 'label'=>1));
	$key = $service->call('buildBatchMlClassifierCacheKey', $samples, array());
	$samples[0]['label'] = 0;
	checkLearning($key !== $service->call('buildBatchMlClassifierCacheKey', $samples, array()), 'Cache key includes samples older than the last 120');
	checkLearning($service->call('loadCachedBatchMlClassifier', 'unknown') === null, 'Mismatched cache keys are rejected');
	class ForbiddenLearningCache { public function __wakeup() { $GLOBALS['unsafe_wakeup'] = true; } public function predict($x) { return 1; } }
	file_put_contents($service->call('getBatchMlClassifierCachePath'), json_encode(array('cache_key'=>'hostile','model_data'=>base64_encode(serialize(new ForbiddenLearningCache())))));
	checkLearning($service->call('loadCachedBatchMlClassifier', 'hostile') === null && empty($GLOBALS['unsafe_wakeup']), 'Cache does not instantiate arbitrary serialized classes or execute wakeup methods');

	// Real temporal holdout: later unique observations, two labels in both partitions.
	for ($i=0; $i<40; $i++) { $service->fixtureSamples[] = array('features'=>array((float) ($i%2), 0.2+$i/1000, 0.4, 0.5, 0.6, 0.0), 'label'=>$i%2); }
	$report = $service->getBatchMlValidationReport();
	checkLearning($report['status']==='ok' && $report['train_count']===32 && $report['test_count']===8 && $report['evaluated_count']===8, 'Chronological validation evaluates every future test observation');
	checkLearning($report['precision_pct']===100.0 && $report['f1_pct']===100.0 && $report['baseline_accuracy_pct']===50.0, 'Separable synthetic holdout outperforms its real majority-class baseline');
	$service->fixtureSamples[0] = $service->fixtureSamples[39];
	checkLearning($service->getBatchMlValidationReport()['duplicate_train_count']===1, 'Repeated feature groups cannot leak from train to holdout');
	$service->failPredictions=true;
	$report=$service->getBatchMlValidationReport();
	checkLearning($report['status']==='validation_prediction_failed' && $report['prediction_errors']===8 && $report['accuracy_pct']===0.0, 'Failed predictions cannot disappear into a successful quality score');
	$batchLine=array('rowid'=>1,'bank_account_id'=>101,'amount'=>-30,'operation_date'=>'2026-11-01','description'=>'Payments');
	checkLearning($service->predictBatchMl($batchLine)['reason']==='prediction_failed', 'Prediction exceptions produce an explicit non-positive result');
	$service->failPredictions=false;
	$service->owned=false;
	checkLearning($service->predictBatchMl($batchLine)['reason']==='outside_company', 'Predictions deny a source outside the active company');
	$service->owned=true;
	foreach (array('manual'=>true,'drag_drop'=>true,'bulk_manual'=>false,'auto_native'=>false,'auto_pair'=>false,'auto_tpa'=>false,'quick_supplier_invoice'=>false) as $strategy=>$expected) {
		checkLearning($service->call('shouldLearnBatchMlFromReconciliation', $strategy, 0)===$expected && !$service->call('shouldLearnBatchMlFromReconciliation', $strategy, 1), 'Learning requires explicit manual ground truth: '.$strategy);
	}

	$zero=array_fill(0,6,0.0);
	$ties=array_merge(array_fill(0,4,array('features'=>$zero,'label'=>1)),array_fill(0,4,array('features'=>$zero,'label'=>0)));
	checkLearning($service->call('estimateBatchMlProbability',$zero,$ties)===0.5, 'Equidistant contradictory neighbors do not depend on insertion order');
	checkLearning($service->call('estimateBatchMlProbability',$zero,array_reverse($ties))===0.5, 'Reversing tied rows cannot flip confidence');

	$line=array('rowid'=>9000,'bank_account_id'=>101,'amount'=>-75.0,'description'=>'ACME Coffee Roasters','counterparty_name'=>'ACME Coffee Roasters');
	$other=array('amount'=>-75.0,'description'=>'Northern Paper Mills','counterparty_name'=>'Northern Paper Mills');
	$training=array();
	for($i=0;$i<24;$i++) {
		$historical=$i<12?$line:$other;
		$training[]=array('features'=>$service->call('buildSupplierMlFeatureVector',$historical),'label'=>$i<12?11:22,'bank_line_id'=>$i+1,'line'=>$historical);
	}
	$service->supplierTraining=array('samples'=>$training,'label_names'=>array(11=>'ACME Coffee Roasters',22=>'Northern Paper Mills'));
	$prediction=$service->predictSupplierForLine($line);
	checkLearning($prediction['is_confident']===1 && $prediction['predicted_socid']===11 && $prediction['support_count']===12, 'Repeated real identity evidence supports the correct synthetic supplier');
	$unknown=array_merge($line,array('counterparty_name'=>'Unknown Railway Group','description'=>'Unknown Railway Group'));
	checkLearning($service->predictSupplierForLine($unknown)['reason']==='insufficient_identity_support', 'Unseen supplier identity abstains despite identical amounts');
	checkLearning(!$service->call('supplierMlIdentityMatches',array_merge($line,array('counterparty_iban'=>'GB82WEST12345698765432')),array_merge($line,array('counterparty_iban'=>'DE89370400440532013000'))), 'Contradictory checksum-valid IBANs override a matching name');
	checkLearning(!$service->call('supplierMlIdentityMatches',array_merge($line,array('counterparty_iban'=>'GB00WEST12345698765432')),$line), 'Invalid IBAN cannot authorize supplier support');
	checkLearning($service->predictSupplierForLine(array_merge($line,array('amount'=>75)))['reason']==='not_supplier_outflow', 'Refunds cannot train or predict a standard supplier expense');

	// Approved unusual headers become useful without exposing another company or legacy shared file.
	$service->call('persistTemplateMlSamples',array('amount'=>0),array('columns'=>array(array('label'=>'Custom monetary figure'))));
	$paths=$parser->call('getMlTrainingStoreCandidates');
	checkLearning(count($paths)===1 && strpos($paths[0],'entity1.json')!==false, 'Only the current company header store is loaded');
	$mapping=array('amount'=>-1,'description'=>-1);
	checkLearning($parser->call('applyMlHeaderMapping',$mapping,array('custom_monetary_figure'=>0))['amount']===0, 'Exact approved custom header maps reliably');
	checkLearning($parser->call('applyMlHeaderMapping',$mapping,array('custom_monetary_figure'=>0,'custom monetary figure'=>1))['amount']===-1, 'Competing columns require review instead of taking the first');
	file_put_contents(DOL_DATA_ROOT.'/kreabank/header_templates.json',json_encode(array(array('field'=>'amount','header'=>'Legacy shared payload'))));
	$conf->entity=2;
	checkLearning($parser->call('loadMlTrainingSamplesFromStore')===array(), 'Foreign and unowned legacy header samples are excluded');
	checkLearning($parser->call('applyMlHeaderMapping',$mapping,array('custom_monetary_figure'=>0))['amount']===-1, 'Reused parser invalidates its classifier after a company change');
	checkLearning(!$service->call('isBatchMlEnabled') && !$service->call('isSupplierMlEnabled'), 'Reused service cannot predict in a changed company');
	$conf->entity=1;
	$service->call('persistTemplateMlSamples',array('description'=>0),array('columns'=>array(array('label'=>'Custom monetary figure'))));
	$parser=new HeaderLearningProbe();
	checkLearning($parser->call('applyMlHeaderMapping',$mapping,array('custom_monetary_figure'=>0))===$mapping, 'Conflicting approved labels abstain');
	checkLearning((fileperms($paths[0]) & 0777)===0600, 'Header training stores are owner-only');
	checkLearning($parser->call('applyMlHeaderMapping',$mapping,array('unrelated mystery zyx'=>0))===$mapping, 'Unrelated headers remain unmapped for review');
	$processes = array();
	for ($i=0; $i<4; $i++) {
		$pipes = array();
		$process = proc_open(array(PHP_BINARY, __FILE__, '--template-writer', DOL_DATA_ROOT, (string) $i), array(0=>array('pipe','r'),1=>array('pipe','w'),2=>array('pipe','w')), $pipes);
		if (!is_resource($process)) { throw new RuntimeException('Cannot start concurrent template writer'); }
		fclose($pipes[0]);
		$processes[] = array($process, $pipes);
	}
	foreach ($processes as $entry) {
		$output = stream_get_contents($entry[1][1]).stream_get_contents($entry[1][2]);
		fclose($entry[1][1]); fclose($entry[1][2]);
		checkLearning(proc_close($entry[0])===0 && $output==='', 'Concurrent template writer completes without errors');
	}
	$rows=json_decode(file_get_contents($paths[0]),true);
	$headers=array_column($rows,'header');
	for ($i=0; $i<4; $i++) {
		for ($j=0; $j<20; $j++) { checkLearning(in_array('Concurrent '.$i.' header '.$j,$headers,true), 'Concurrent saves retain every approved header'); }
	}
	$before=file_get_contents($paths[0]);
	$service->call('persistTemplateMlSamples',array('amount'=>0),array('columns'=>array(array('label'=>str_repeat('x',256)))));
	checkLearning(file_get_contents($paths[0])===$before, 'Oversized header examples cannot grow the learning store');
	file_put_contents($paths[0],'{"incomplete":');
	$service->call('persistTemplateMlSamples',array('amount'=>0),array('columns'=>array(array('label'=>'New approved header'))));
	checkLearning(file_get_contents($paths[0])==='{"incomplete":', 'Corrupt prior evidence is retained instead of overwritten by a fresh optional sample');
	echo 'OK: '.$checks." machine learning runtime checks\n";
} finally {
	foreach (glob(DOL_DATA_ROOT.'/kreabank/*') ?: array() as $file) { unlink($file); }
	if(is_dir(DOL_DATA_ROOT.'/kreabank')) { rmdir(DOL_DATA_ROOT.'/kreabank'); }
	rmdir(DOL_DATA_ROOT);
}
