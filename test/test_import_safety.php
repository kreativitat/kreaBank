#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
require_once __DIR__ . '/../lib/kreabank_csv.lib.php';
$checks = 0;
function checkImportSafety($condition, $message)
{
	global $checks;
	if (!$condition) { throw new RuntimeException($message); }
	$checks++;
}
$source = file_get_contents(__DIR__ . '/../import.php');

// Execute the real export loop, with a synthetic DB result and an in-memory stream.
$row = (object) array('operation_date'=>'2026-11-14', 'value_date'=>'2026-11-14', 'amount'=>'-123.12345678',
	'currency'=>'EUR', 'running_balance'=>'-0.00000001', 'payment_reference'=>"\t=1+1", 'bank_reference'=>'+SUM(1;2)',
	'counterparty_name'=>'@SUM(1,2)', 'counterparty_iban'=>'-CMD()', 'description'=>"=HYPERLINK(\"https://example.invalid/\";\"test\")\nsecond line",
	'status'=>0, 'is_duplicate'=>0);
$db = new class($row) {
	private $row;
	public function __construct($row) { $this->row = $row; }
	public function fetch_object($unused) { $row = $this->row; $this->row = null; return $row; }
};
$resLines = null; $separator = ';'; $out = fopen('php://temp', 'w+');
$start = strpos($source, '$lineRank = 0;');
$end = strpos($source, 'fclose($out);', $start);
checkImportSafety($start !== false && $end !== false, 'Actual statement export loop is available');
eval(substr($source, $start, $end - $start));
rewind($out); $export = fgetcsv($out, 0, ';', '"', ''); fclose($out);
checkImportSafety($export[3] === $row->amount && $export[5] === $row->running_balance, 'Export preserves signed exact decimal evidence');
foreach (array(6=>'payment_reference',7=>'bank_reference',8=>'counterparty_name',9=>'counterparty_iban',10=>'description') as $index=>$property) {
	checkImportSafety($export[$index] === "'" . $row->$property, 'Export neutralizes formula in ' . $property);
}
checkImportSafety(kreabankCsvCell("=1+1\xff") === "'=1+1\xff", 'Malformed UTF-8 cannot bypass formula protection');
checkImportSafety(kreabankCsvCell('Supplier Açúcar & Filhos') === 'Supplier Açúcar & Filhos', 'Ordinary evidence remains intact');

// Exercise the controller's actual guard before any wizard side effect.
function kreabankIsTokenValid() { return $GLOBALS['importSafetyTokenValid']; }
function accessforbidden($message) { throw new RuntimeException($message); }
$start = strpos($source, '$enforcePostActionWithToken =');
$end = strpos($source, '$normalizeLineRanks =', $start);
eval(substr($source, $start, $end - $start));
$guardStart = strpos($source, 'if (in_array($action,');
$guardEnd = strpos($source, "if (\$action === 'cancel'", $guardStart);
$guard = substr($source, $guardStart, $guardEnd - $guardStart);
foreach (array('analyze','confirm','import_selected_duplicates','cancel','finish_duplicate_review') as $action) {
	foreach (array(array('GET',true,false),array('POST',false,false),array('POST',true,true)) as $case) {
		$_SERVER['REQUEST_METHOD'] = $case[0]; $GLOBALS['importSafetyTokenValid'] = $case[1]; $accepted = true;
		try { eval($guard); } catch (RuntimeException $e) { $accepted = false; }
		checkImportSafety($accepted === $case[2], $action . ' requires POST and a current token');
	}
}
print "OK: $checks import safety checks\n";
