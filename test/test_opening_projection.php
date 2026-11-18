#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Actual statement detail rendering with synthetic data; no bootstrap/database.
 */
require_once __DIR__ . '/../lib/kreabank_integration.lib.php';
require_once __DIR__ . '/../lib/kreabank_worklist.lib.php';
require_once __DIR__ . '/../lib/kreabank_statement_list.lib.php';
require_once __DIR__ . '/../lib/kreabank_worklist_undo.lib.php';
function dol_escape_htmltag($value) { return htmlspecialchars((string) $value,ENT_QUOTES,'UTF-8'); }
function dol_buildpath($path,$mode=0) { return '/erp' . $path; }
function kreabankPrice($value) { return number_format((float) $value,2,'.',','); }
$checks = 0;
function checkOpeningProjection($condition,$message) {
	global $checks;
	if (!$condition) { throw new RuntimeException($message); }
	$checks++;
}
$controller = file_get_contents(__DIR__ . '/../statement.php');
$start = strpos($controller,'$shown = 0;');
$end = strpos($controller,"} else {\n\n\twhile (",$start);
if ($start === false || $end === false) { throw new RuntimeException('Statement renderer boundary changed'); }
$fragment = substr($controller,$start,$end-$start) . '}';
$render = static function ($source,$locale) use ($fragment) {
	$messages = array();
	foreach (file(__DIR__ . '/../langs/' . $locale . '/kreabank.lang',FILE_IGNORE_NEW_LINES) as $line) {
		if ($line !== '' && $line[0] !== '#' && strpos($line,'=') !== false) { list($key,$value)=explode('=',$line,2); $messages[$key]=$value; }
	}
	$langs = new class($messages) {
		private $messages;
		public function __construct($messages) { $this->messages=$messages; }
		public function trans($key) { return $this->messages[$key] ?? $key; }
	};
	$db = new class { public function fetch_object($result) { return array_shift($result->rows); } };
	$result = (object) array('rows'=>array((object) $source));
	$statement = (object) array('source_type'=>'csv'); $reference='SOURCE-REF'; $navigationAccountId=101; $limit=10;
	ob_start(); eval($fragment); $html=ob_get_clean();
	return array($html,$langs);
};
$source = array('rowid'=>11,'operation_date'=>'2026-10-31','description'=>'Synthetic <opening> source','amount'=>'12.34000000','currency'=>'EUR',
	'payment_reference'=>'EVIDENCE-11','bank_reference'=>'','status'=>0,'is_duplicate'=>0,'native_id'=>0,'expected_native_id'=>0,
	'group_id'=>0,'native_currency'=>'EUR','native_amount'=>null,'rappro'=>0,'num_releve'=>'','opening_reconciled'=>1);
foreach (array('en_US','pt_PT') as $locale) {
	list($html,$langs)=$render($source,$locale);
	checkOpeningProjection(strpos($html,$langs->trans('KreaBankOpeningReconciled')) !== false && strpos($html,'—') !== false,
		'Historical statement detail shows opening acceptance and no invented native movement: ' . $locale);
	checkOpeningProjection(strpos($html,$langs->trans('KreaBankAwaitingReconciliation')) === false && strpos($html,$langs->trans('KreaBankNativeMissing')) === false,
		'Opening-accepted statement detail never asks to reconcile the historical source again: ' . $locale);
	checkOpeningProjection(strpos($html,'Synthetic &lt;opening&gt; source') !== false && strpos($html,'12.34') !== false,
		'Opening display preserves escaped original evidence and amount: ' . $locale);
	$pending=$source; $pending['opening_reconciled']=0; list($normal)=$render($pending,$locale);
	checkOpeningProjection(strpos($normal,$langs->trans('KreaBankAwaitingReconciliation')) !== false,
		'Absent opening retains the ordinary statement pending message: ' . $locale);
	$mapped=$source; $mapped['native_id']=81; $mapped['expected_native_id']=81; $mapped['native_amount']='12.34';
	list($linked)=$render($mapped,$locale);
	checkOpeningProjection(strpos($linked,'/compta/bank/line.php?rowid=81') !== false,
		'A genuine historical native link remains available: ' . $locale);
	checkOpeningProjection($langs->trans('KreaBankMonthlyStateOpening') === $langs->trans('KreaBankOpeningReconciled'),
		'Monthly and statement opening labels agree: ' . $locale);
}
$source['account_closed']=0; $source['account_reconcilable']=1;
checkOpeningProjection(kreabankWorklistProjectionStatus($source,true) === 'reconciled' && !kreabankWorklistPendingSource($source),
	'Opening acceptance takes precedence over a private draft and the unchanged raw pending status');
$undo=array('rowid'=>11,'line_source'=>'staged','bank_account_id'=>101,'native_bank_line_id'=>81,'rappro'=>1,'opening_reconciled'=>1);
checkOpeningProjection(!kreabankWorklistUndoRowMatches($undo,'staged',11,101), 'An old native link cannot expose an Undo action for the applied opening');
checkOpeningProjection(strpos(kreabankStatementListStatusSql(),"WHEN h.opening_reconciled=1 THEN 'reconciled'") !== false,
	'Statement filters count the explicit historical state before ordinary link checks');
echo "OK: $checks opening projection and statement renderer checks\n";
