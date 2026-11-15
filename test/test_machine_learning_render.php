#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Render actual administration views with synthetic data and native translations.
 * No ERP bootstrap, database, credentials or record actions.
 */
define('DOL_DOCUMENT_ROOT', getenv('KREABANK_DOLIBARR_ROOT') ?: dirname(__DIR__, 3));
require_once DOL_DOCUMENT_ROOT . '/core/class/translate.class.php';
function getDolGlobalString($key) { return ''; }
function dol_escape_htmltag($text) { return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'); }
function dol_buildpath($path, $absolute = 0) { return '/custom' . $path; }
function kreabankPrice($number) { return number_format($number, 2, '.', ','); }
function newToken() { return 'synthetic-token'; }
$source = file_get_contents(__DIR__ . '/../admin/setup.php');
$start = strpos($source, '$batchMlStatusKeys = array(');
$end = strpos($source, 'print dol_get_fiche_end();', $start);
$optionsStart = strpos($source, '$classifierLabels = array(');
$optionsEnd = strpos($source, '$item = $formSetup', $optionsStart);
$aboutSource = file_get_contents(__DIR__ . '/../admin/about.php');
$aboutStart = strpos($aboutSource, '$logoUrl =');
$aboutEnd = strpos($aboutSource, '// Page end', $aboutStart);
if ($start === false || $end === false || $optionsStart === false || $optionsEnd === false || $aboutStart === false || $aboutEnd === false) {
	throw new RuntimeException('Review the administration rendering boundaries');
}
$report = array('classifier'=>'random_forest', 'sample_count'=>120, 'train_count'=>90, 'test_count'=>24, 'accuracy_pct'=>87.5, 'precision_pct'=>90.0, 'recall_pct'=>81.82, 'f1_pct'=>85.71, 'balanced_accuracy_pct'=>87.06, 'baseline_accuracy_pct'=>54.17, 'legacy_sample_count'=>42, 'invalid_sample_count'=>2, 'duplicate_train_count'=>6, 'evaluated_count'=>24, 'prediction_errors'=>0, 'confusion'=>array('tp'=>9,'fp'=>1,'tn'=>12,'fn'=>2), 'status'=>'ok');
$integrityDiagnostics = array('entity'=>91,'orphan_statement_lines'=>0,'orphan_statements'=>0,'orphan_bankmeta_rows'=>0,'bankmeta_account_mismatches'=>0,'ok'=>false);
$auditRetentionDiagnostics = array('retention_days'=>365,'cutoff_date'=>'2025-11-15','total_rows'=>18,'purgeable_rows'=>2,'oldest_date'=>'2025-10-01','newest_date'=>'2026-11-15');
$conf = (object) array('entity'=>91);
$_SERVER['PHP_SELF'] = '/synthetic-admin-do-not-submit';
$tmpmodule = new class {
	public $editor_name = 'Kreativität Works';
	public $editor_url = 'https://kreativitat.com';
	public function getName() { return 'KreaBank'; }
	public function getVersion() { return trim(file_get_contents(__DIR__ . '/../VERSION')); }
};
$checks = 0;
$check = static function ($condition, $message) use (&$checks) {
	$checks++;
	if (!$condition) { throw new RuntimeException($message); }
};
$locales = array('en_US','pt_PT');
$html = '';
foreach ($locales as $locale) {
	$langs = new Translate(DOL_DOCUMENT_ROOT, (object) array());
	$langs->defaultlang = $locale;
	foreach (file(__DIR__ . '/../langs/' . $locale . '/kreabank.lang', FILE_IGNORE_NEW_LINES) as $line) {
		if (preg_match('/^([A-Za-z0-9_]+)=(.*)$/u', $line, $match)) { $langs->tab_translate[$match[1]] = $match[2]; }
	}
	preg_match_all('/(?:trans(?:noentities)?|newItem)\(\x27((?:KreaBank|KREABANK_)[A-Za-z0-9_]+)\x27/', $source . $aboutSource, $keys);
	foreach (array_unique($keys[1]) as $key) { $check(isset($langs->tab_translate[$key]), $locale . ': missing ' . $key); }
	eval(substr($source, $optionsStart, $optionsEnd-$optionsStart));
	$render = static function ($batchMlValidationReport) use ($source, $start, $end, $langs, $classifierLabels, $integrityDiagnostics, $auditRetentionDiagnostics, $conf) {
		ob_start(); eval(substr($source, $start, $end-$start)); return ob_get_clean();
	};
	$table = $render($report);
	foreach (array('87.50%', '85.71%', '42 / 2 / 6', '24 / 0', dol_escape_htmltag($classifierLabels['random_forest']), $langs->trans('KreaBankIntegrityTitle', 91), $langs->trans('KreaBankAuditDiagnosticsTitle', 91), $langs->trans('KreaBankDiagnosticIssues')) as $text) {
		$check(strpos($table, $text)!==false, $locale . ': missing report content: ' . $text);
	}
	eval(substr($source, $start, strpos($source, "print '<br>", $start)-$start));
	foreach ($batchMlStatusKeys as $status=>$key) {
		$fixture = $report; $fixture['status']=$status;
		$check(strpos($render($fixture), $langs->trans($key))!==false, $locale . ': untranslated status ' . $status);
	}
	$fixture = $report; $fixture['status']='<unknown status>'; $fixture['classifier']='<unknown classifier>';
	$unknown = $render($fixture);
	$check(strpos($unknown, '&lt;unknown status&gt;')!==false && strpos($unknown, '&lt;unknown classifier&gt;')!==false, 'Unknown diagnostics must remain escaped');
	ob_start(); eval(substr($aboutSource, $aboutStart, $aboutEnd-$aboutStart)); $about = ob_get_clean();
	$aboutText = html_entity_decode($about, ENT_QUOTES, 'UTF-8');
	$check(strpos($aboutText, $langs->transnoentities('KreaBankAboutWhatText'))!==false, $locale . ': About text must be escaped once');
	$check(strpos($about, '/custom/kreabank/img/logo.png')!==false && strpos($about, 'kreaproducts')===false, 'About must use its own logo');
	$check(strpos($aboutText, $langs->transnoentities('KreaBankAboutCapabilityMonthly'))!==false && strpos($aboutText, $langs->transnoentities('KreapAboutLicenseValue'))!==false, 'About must describe current reports and license');
	$check(strpos($about, '<form')===false, 'About must not introduce record actions');
	$html = $table . $about;
}
if (($argv[1] ?? '')==='--html') {
	echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>KreaBank administration — synthetic fixture</title><style>body{font:14px Arial,sans-serif;color:#333;margin:24px}table{border-collapse:collapse;width:100%}th,td{text-align:left;padding:12px;border-bottom:1px solid #ddd}th{background:#eef1f4}.titlefield{width:40%}h1{font-size:22px}</style></head><body><h1>KreaBank administration — synthetic fixture</h1>' . $html . '<script>document.addEventListener("submit",function(e){e.preventDefault();});</script></body></html>';
} else { echo 'OK: ' . $checks . " bilingual administration rendering checks\n"; }
