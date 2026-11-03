#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 * ONLY the guarded disposable 127.0.0.1:33317/kreabank_auto_test fixture.
 */
require __DIR__ . '/test_statement_balances_database.php';
require __DIR__ . '/../lib/kreabank_statement_list.lib.php';
$checks=0;
$filtered = static function ($filters=array(),$account=120,$limit=1000,$offset=0) use ($report,$db) {
	$source='('.$report->bankStatementWithBalancesSql($account).') h';
	$where=kreabankStatementListWhere($db,$filters);
	$count=(int)$db->fetch_object($report->query('SELECT COUNT(*) nb FROM '.$source.$where))->nb;
	$result=$report->query('SELECT h.* FROM '.$source.$where.' ORDER BY h.operation_date DESC,h.statement_id DESC,h.line_rank DESC,h.rowid DESC,h.source_storage'.$db->plimit($limit,$offset));
	$rows=array();while($row=$db->fetch_object($result)){$rows[]=$row;}
	return array($count,$rows);
};
$ids=static function ($rows) { return array_map('intval',array_column($rows,'rowid')); };
list($total,$complete)=$filtered();
checkAuto($total===5,'Fixture exposes the complete owned-account statement');
list($total,$rows)=$filtered(array('operation_from'=>'2026-11-02','operation_to'=>'2026-11-02'));
checkAuto($total===2 && $ids($rows)===array(903,902),'Operation date bounds apply before pagination in stable statement order');
checkAuto($filtered(array('value_from'=>'2026-11-03','value_to'=>'2026-11-03'))[0]===1,'Value date has independent bounds');
checkAuto($ids($filtered(array('debit'=>'5,94'))[1])===array(901),'Debit uses its displayed positive amount with comma decimals');
checkAuto($ids($filtered(array('credit'=>'>=100'))[1])===array(902),'Credit comparisons exclude debit rows');
checkAuto($ids($filtered(array('running_balance'=>'12.12345678'))[1])===array(905),'Balance filter retains all eight source decimal places');
list($total,$rows)=$filtered(array('running_balance'=>'3329.69'));
checkAuto($total===1 && (int)$rows[0]->rowid===903 && $rows[0]->display_balance==='3329.69000000','Filtering does not recalculate balances from the reduced set');
checkAuto($filtered(array('currency'=>'USD'))[0]===0 && $filtered(array('currency'=>'EUR'))[0]===5,'Currency filters operate on source currency');
checkAuto($filtered(array('bank_account_ref'=>'BALANCE'))[0]===5 && $filtered(array('statement_ref'=>'ST-901'))[0]===1,'Account reference and source statement headers filter their own columns');
$db->query("UPDATE kbauto_kreabank_statement_line SET description='100%_literal O\'Brien',bank_reference='FILE-REFERENCE' WHERE rowid=905");
$db->query("UPDATE kbauto_kreabank_statement_line SET bank_reference='HIDDEN-PROVIDER-ID' WHERE rowid=901");
checkAuto($ids($filtered(array('description'=>'100%_literal O\'Brien'))[1])===array(905),'Quotes and LIKE wildcards search literal displayed descriptions');
checkAuto($filtered(array('description'=>"' OR 1=1 --"))[0]===0,'Text cannot inject SQL or bypass ownership');
checkAuto($filtered(array('description'=>'FILE-REFERENCE'))[0]===1 && $filtered(array('description'=>'HIDDEN-PROVIDER-ID'))[0]===0,'Reference search follows visible file references and excludes hidden feed IDs');
checkAuto($filtered(array('description'=>'PAY-701'),101)[0]===1,'Visible payment references are searchable');
$duplicates=$filtered(array('status'=>'duplicate'),101)[1];
checkAuto(in_array(701,$ids($duplicates),true) && count(array_filter($duplicates,static function($row){return (int)$row->is_duplicate>0;}))===count($duplicates),'Duplicate status filter matches the displayed warnings');
$db->query('UPDATE kbauto_kreabank_statement_line SET status=3 WHERE rowid=905');
checkAuto($filtered(array('status'=>'skipped'))[0]===1,'Skipped status filters source evidence without changing it');
foreach (array('staging','legacy_native') as $storage) {
	foreach ($filtered(array(),101)[1] as $row) {
		if ($row->source_storage!==$storage) { continue; }
		$expected=kreabankIntegrationLineState($row,$row->statement_ref);
		$key=array_search($expected,kreabankStatementListStatusOptions(),true);
		$source='('.$report->bankStatementWithBalancesSql(101).') h';
		$match=$db->fetch_object($report->query('SELECT COUNT(*) nb FROM '.$source.kreabankStatementListWhere($db,array('status'=>$key)).' AND h.rowid='.(int)$row->rowid." AND h.source_storage='".$storage."'"));
		checkAuto((int)$match->nb===1,'SQL status filter agrees with the rendered staging/legacy evidence state');
	}
}
checkAuto($filtered(array(),202)[0]===0 && $filtered(array('bank_account_ref'=>'TEST B'),0)[0]===0,'Neither selected nor text account filters expose foreign-company statements');
$all=$filtered(array('credit'=>'>=0'),101);$seen=array();
for($offset=0;$offset<$all[0];$offset+=7){list($count,$rows)=$filtered(array('credit'=>'>=0'),101,7,$offset);checkAuto($count===$all[0],'Count and rows share identical filters');foreach($rows as $row){$seen[]=$row->source_storage.':'.$row->rowid;}}
checkAuto(count($seen)===$all[0] && count(array_unique($seen))===$all[0],'Filtered pagination has no missing or repeated equal-date rows');
print "OK: $checks bank statement filter database checks\n";
