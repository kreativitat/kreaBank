#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Synthetic files only; no ERP bootstrap, credentials or database.
 */
require_once __DIR__ . '/../class/KreaBankParser.class.php';

class ParserSafetyProbe extends KreaBankParser
{
	public $temporaryFiles = array();
	public function rows($path) { return $this->loadXlsxRowsByZip($path); }
	public function column($reference) { return $this->columnIndexFromCellRef($reference); }
	public function limits($zip) { return $this->assertXlsxArchiveLimits($zip); }
	public function copyEntry($zip, $name) { return $this->copyZipEntryToTempFile($zip, $name, 'kb-safety-'); }
	public function alternateRows($path) { return $this->loadExcelRowsWithPhpSpreadsheet($path); }
	public function convertedRows($path) { return $this->loadExcelRowsByCommandConversion($path); }
	protected function removeTempFile($path, $context = '')
	{
		$this->temporaryFiles[] = $path;
		return parent::removeTempFile($path, $context);
	}
}

/** Exercise exact boundaries with small fixtures instead of allocating large ZIP bombs. */
class SmallParserSafetyProbe extends ParserSafetyProbe
{
	const MAX_XLSX_ENTRY_BYTES = 1024;
	const MAX_XLSX_UNCOMPRESSED_BYTES = 1536;
	const MAX_XLSX_MATERIALIZED_CELLS = 8;
	const MAX_XLSX_ROWS = 3;
}

$checks = 0;
function parserSafetyCheck($condition, $label)
{
	global $checks;
	$checks++;
	if (!$condition) { throw new RuntimeException($label); }
}
function parserSafetyRejects($operation, $label)
{
	try { $operation(); } catch (RuntimeException $error) { parserSafetyCheck(true, $label); return; }
	parserSafetyCheck(false, $label);
}
function parserSafetyZip($path, $entries)
{
	$zip = new ZipArchive();
	if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) { throw new RuntimeException('Cannot create synthetic archive'); }
	foreach ($entries as $name => $contents) { $zip->addFromString($name, $contents); }
	$zip->close();
}
function parserSafetySheet($cells)
{
	return '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1">' . $cells . '</row></sheetData></worksheet>';
}

$file = tempnam(sys_get_temp_dir(), 'kb-parser-safety-');
if ($file === false) { throw new RuntimeException('Cannot allocate synthetic file'); }
try {
	$parser = new ParserSafetyProbe();
	foreach (array('DBIT' => array('Supplier Alpha', 'PT50000201231234567890154', -125.0), 'CRDT' => array('Customer Beta', 'PT50000201231234567890155', 125.0)) as $mark => $expected) {
		$xml = '<Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.053.001.02"><BkToCstmrStmt><Stmt><Ntry><Amt Ccy="EUR">125.00</Amt><CdtDbtInd>' . $mark . '</CdtDbtInd><BookgDt><Dt>2026-11-14</Dt></BookgDt><NtryDtls><TxDtls><RltdPties><Cdtr><Nm>Supplier Alpha</Nm></Cdtr><CdtrAcct><Id><IBAN>PT50000201231234567890154</IBAN></Id></CdtrAcct><Dbtr><Nm>Customer Beta</Nm></Dbtr><DbtrAcct><Id><IBAN>PT50000201231234567890155</IBAN></Id></DbtrAcct></RltdPties></TxDtls></NtryDtls></Ntry></Stmt></BkToCstmrStmt></Document>';
		file_put_contents($file, $xml);
		$rows = $parser->parse($file, 'synthetic-camt.xml')['lines'];
		parserSafetyCheck(count($rows) === 1 && $rows[0]['counterparty_name'] === $expected[0] && $rows[0]['counterparty_iban'] === $expected[1], 'CAMT selects the other party for ' . $mark);
		parserSafetyCheck($rows[0]['amount'] === $expected[2] && $rows[0]['operation_date'] === '2026-11-14', 'CAMT keeps amount direction and booking date for ' . $mark);
	}
	file_put_contents($file, str_replace(array('<Cdtr><Nm>Supplier Alpha</Nm></Cdtr>', '<CdtrAcct><Id><IBAN>PT50000201231234567890154</IBAN></Id></CdtrAcct>', '<CdtDbtInd>CRDT</CdtDbtInd>'), array('', '', '<CdtDbtInd>DBIT</CdtDbtInd>'), $xml));
	$rows = $parser->parse($file, 'synthetic-camt.xml')['lines'];
	parserSafetyCheck($rows[0]['counterparty_name'] === '' && $rows[0]['counterparty_iban'] === '', 'Missing other-party evidence never falls back to the own-account side');

	parserSafetyCheck($parser->column('XFD1') === 16383 && $parser->column('a1') === 0, 'Valid XLSX column boundary and lowercase references remain supported');
	foreach (array('XFE1', 'ZZZZZ1', str_repeat('A', 1000) . '1') as $reference) {
		parserSafetyRejects(static function () use ($parser, $reference) { $parser->column($reference); }, 'Reject out-of-range columns before allocation');
	}
	parserSafetyZip($file, array('xl/worksheets/sheet1.xml' => parserSafetySheet('<c r="XFD1" t="inlineStr"><is><t>Boundary</t></is></c>')));
	$rows = $parser->rows($file);
	parserSafetyCheck(count($rows[0]) === 16384 && $rows[0][16383] === 'Boundary', 'Actual XLSX loader retains XFD boundary cells');
	foreach (array('XFE1', 'ZZZZZ1') as $reference) {
		parserSafetyZip($file, array('xl/worksheets/sheet1.xml' => parserSafetySheet('<c r="' . $reference . '"><v>1</v></c>')));
		parserSafetyRejects(static function () use ($parser, $file) { $parser->rows($file); }, 'Actual XLSX loader rejects oversized column references');
	}
	parserSafetyZip($file, array('xl/worksheets/sheet1.xml' => parserSafetySheet(str_repeat('<c><v>1</v></c>', 16385))));
	parserSafetyRejects(static function () use ($parser, $file) { $parser->rows($file); }, 'Implicit cell references cannot exceed the XLSX column limit');

	$small = new SmallParserSafetyProbe();
	parserSafetyZip($file, array('xl/worksheets/sheet1.xml' => parserSafetySheet('<c r="A1" t="s"><v>0</v></c>'), 'xl/sharedStrings.xml' => '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><si><t>Shared evidence</t></si></sst>'));
	parserSafetyCheck($small->rows($file) === array(array('Shared evidence')), 'Bounded loader preserves actual shared-string evidence');
	foreach (array(array('one' => str_repeat('a', 1025)), array('one' => str_repeat('a', 800), 'two' => str_repeat('b', 800))) as $entries) {
		parserSafetyZip($file, $entries);
		parserSafetyRejects(static function () use ($small, $file) { $small->rows($file); }, 'Reject per-entry and total expanded size limits before parsing');
		parserSafetyRejects(static function () use ($small, $file) { $small->alternateRows($file); }, 'Alternate spreadsheet reader cannot bypass archive limits');
		parserSafetyRejects(static function () use ($small, $file) { $small->convertedRows($file); }, 'External spreadsheet converter cannot bypass archive limits');
		foreach (array('renamed.xls', 'synthetic.xlsx') as $name) {
			parserSafetyRejects(static function () use ($small, $file, $name) { $small->parseFormatExcel($file, $name); }, 'Public Excel strategy cannot fall back around archive limits');
		}
	}
	$grid = '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>%s</sheetData></worksheet>';
	foreach (array(str_repeat('<row><c r="A1"><v>1</v></c></row>', 4), str_repeat('<row><c r="E1"><v>1</v></c></row>', 2)) as $body) {
		parserSafetyZip($file, array('xl/worksheets/sheet1.xml' => sprintf($grid, $body)));
		parserSafetyRejects(static function () use ($small, $file) { $small->rows($file); }, 'Reject excessive row count and sparse-cell amplification before dense allocation');
	}
	parserSafetyZip($file, array('xl/worksheets/sheet1.xml' => sprintf($grid, '<row><c r="C1"><v>1</v></c></row><row><c r="C2"><v>2</v></c></row><row><c r="B3"><v>3</v></c></row>')));
	$rows = $small->rows($file);
	parserSafetyCheck(count($rows) === 3 && array_sum(array_map('count', $rows)) === 8, 'Exact row and materialized-cell limits remain readable');
	parserSafetyZip($file, array('one' => str_repeat('a', 1024), 'two' => str_repeat('b', 512)));
	$zip = new ZipArchive(); $zip->open($file);
	$small->limits($zip);
	$copy = $small->copyEntry($zip, 'one');
	parserSafetyCheck(filesize($copy) === 1024, 'Exact entry and archive byte limits remain readable');
	unlink($copy); $zip->close();
	$streamArchive = new class {
		public function getStream($name) { $stream = fopen('php://temp', 'w+'); fwrite($stream, str_repeat('x', 1025)); rewind($stream); return $stream; }
	};
	parserSafetyRejects(static function () use ($small, $streamArchive) { $small->copyEntry($streamArchive, 'unexpected-expansion'); }, 'Streaming limit rejects actual bytes independently of ZIP metadata');
	foreach (array_merge($parser->temporaryFiles, $small->temporaryFiles) as $temporary) {
		parserSafetyCheck($temporary === '' || !file_exists($temporary), 'Temporary XML and rejected entry files are cleaned after success and failure');
	}
	echo 'PASS: ' . $checks . " parser safety checks\n";
} finally {
	if (file_exists($file)) { unlink($file); }
}
