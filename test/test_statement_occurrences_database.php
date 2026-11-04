#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 * ONLY the guarded disposable localhost:33317/kreabank_auto_test fixture.
 */
require __DIR__ . '/test_statement_evidence_database.php';
function dol_trunc($value, $length, $mode='right', $charset='', $dots=1) { return substr($value,0,$length); }
class OccurrenceTestLangs extends AutoTestLangs { public function transnoentitiesnoconv($key) { return $key; } public function transnoentities($key) { return $key; } }
$langs=new OccurrenceTestLangs();$service=new KreaBankService($db,$user,$langs);
foreach(array('c_country','c_departements','accounting_journal') as $table){
	$sql=preg_replace('/--[^\n]*/','',file_get_contents(DOL_DOCUMENT_ROOT.'/install/mysql/tables/llx_'.$table.'.sql'));
	foreach(explode(';',str_replace('llx_',MAIN_DB_PREFIX,$sql)) as $statement){if(trim($statement)!=='')$db->query($statement);}
}
$extrafields->attributes['bank_account']=array('loaded'=>1,'label'=>array());
$checks=0;$sha=str_repeat('c',64);
$first=array('line_uid'=>'xlsx:'.$sha.':row:8','operation_date'=>'2026-07-27','value_date'=>'2026-07-27',
	'amount'=>'-1.04','currency'=>'EUR','description'=>'Two distinct bank fees','running_balance'=>'20.00');
$second=$first;$second['line_uid']='xlsx:'.$sha.':row:9';$second['running_balance']='18.96';
$parsed=array('format'=>'xlsx','lines'=>array($first,$second));
$beforeBank=countAuto('bank');
$result=$service->importStatementFromParsed($parsed,'occurrences.xlsx',120,'2026-11-04',array('source_file_sha256'=>$sha));
checkAuto($result['imported_lines']===2 && $result['duplicates']===0 && $result['duplicates_imported']===0,'Distinct equal-day/amount/description source rows remain two ordinary movements');
$again=$service->importStatementFromParsed($parsed,'occurrences.xlsx',120,'2026-11-04',array('source_file_sha256'=>$sha));
checkAuto($again['imported_lines']===0 && $again['duplicates_skipped']===2,'Replaying the same file rows cannot duplicate either movement');
$changed=$parsed;$changed['lines'][1]['amount']='-1.05';
rejectAuto(static function()use($service,$changed,$sha){$service->importStatementFromParsed($changed,'occurrences.xlsx',120,'2026-11-04',array('source_file_sha256'=>$sha));},'A reused file row with changed financial facts is rejected');
$bad=$parsed;$bad['lines'][1]['line_uid']='xlsx:'.str_repeat('d',64).':row:9';
rejectAuto(static function()use($service,$bad,$sha){$service->importStatementFromParsed($bad,'occurrences.xlsx',120,'2026-11-04',array('source_file_sha256'=>$sha));},'One import cannot mix row identities from different file checksums');
rejectAuto(static function()use($service,$parsed,$sha){$service->importStatementFromParsed($parsed,'occurrences.xlsx',120,'2026-11-04',array('source_file_sha256'=>$sha,'allow_duplicate_import'=>1));},'Verified row identity cannot be combined with forced duplicate import');
$legacy=$first;$legacy['line_uid']='xlsx:'.$sha.':row:10';$legacy['description']='Previously imported file row';
$old=$service->importStatementFromParsed(array('format'=>'xlsx','lines'=>array($legacy)),'old.xlsx',120,'2026-11-04');
$oldAgain=$service->importStatementFromParsed(array('format'=>'xlsx','lines'=>array($legacy)),'old.xlsx',120,'2026-11-04',array('source_file_sha256'=>$sha));
checkAuto($old['imported_lines']===1 && $oldAgain['duplicates_skipped']===1,'Older file-row identities retain their original duplicate hash');
$plain=$first;$plain['line_uid']='';$plain['description']='Ordinary unverified import';
$ordinary=$service->importStatementFromParsed(array('format'=>'xlsx','lines'=>array($plain,$plain)),'ordinary.xlsx',120,'2026-11-04');
checkAuto($ordinary['imported_lines']===1 && $ordinary['duplicates_skipped']===1,'Ordinary file imports retain their existing contextual duplicate checks');
checkAuto(countAuto('bank')===$beforeBank,'Import and retry create no native financial movements');
print "OK: $checks verified source occurrence checks\n";
