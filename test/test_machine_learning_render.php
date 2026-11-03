#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Render the real diagnostic table with synthetic metrics, without an ERP bootstrap.
 */
function dol_escape_htmltag($text) { return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'); }
function kreabankPrice($number) { return number_format($number, 2, '.', ','); }
$source = file_get_contents(__DIR__ . '/../admin/setup.php');
$start = strpos($source, 'print \'<tr class="liste_titre"><th colspan="2">Batch ML validation');
$end = strpos($source, "print '</table>';", $start);
if ($start === false || $end === false) { throw new RuntimeException('Review the diagnostic table rendering boundary'); }
$batchMlValidationReport = array('classifier'=>'Random Forest <synthetic>', 'sample_count'=>120, 'train_count'=>90, 'test_count'=>24, 'accuracy_pct'=>87.5, 'precision_pct'=>90.0, 'recall_pct'=>81.82, 'f1_pct'=>85.71, 'balanced_accuracy_pct'=>87.06, 'baseline_accuracy_pct'=>54.17, 'legacy_sample_count'=>42, 'invalid_sample_count'=>2, 'duplicate_train_count'=>6, 'evaluated_count'=>24, 'prediction_errors'=>0, 'confusion'=>array('tp'=>9,'fp'=>1,'tn'=>12,'fn'=>2), 'status'=>'ok');
ob_start();
eval(substr($source, $start, $end-$start));
$table = ob_get_clean();
foreach (array('chronological holdout', '87.50%', '85.71%', '42 / 2 / 6', '24 / 0', '&lt;synthetic&gt;') as $text) {
	if (strpos($table, $text)===false) { throw new RuntimeException('Missing report content: '.$text); }
}
if (($argv[1] ?? '')==='--html') {
	echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>KreaBank learning diagnostics - synthetic fixture</title><style>body{font:14px Arial,sans-serif;color:#333;margin:24px}table{border-collapse:collapse;width:100%;max-width:1100px}th,td{text-align:left;padding:12px;border-bottom:1px solid #ddd}th{background:#eef1f4}.titlefield{width:48%}h1{font-size:22px}</style></head><body><h1>KreaBank learning diagnostics</h1><p>Synthetic rendering fixture</p><table>'.$table.'</table></body></html>';
} else { echo "OK: machine learning diagnostic table rendering\n"; }
