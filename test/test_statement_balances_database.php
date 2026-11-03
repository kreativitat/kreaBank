#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 * ONLY the guarded disposable database on 127.0.0.1:33317.
 */
require __DIR__ . '/test_bank_statement_database.php';
require_once __DIR__ . '/../class/KreaBankFintableSync.class.php';
$checks = 0;
$plain = $db->fetch_object($report->query('SELECT h.* FROM (' . $report->bankStatementWithBalancesSql(101) . ') h WHERE h.rowid=701'));
checkAuto($plain->display_balance === '0.00000000' && (int) $plain->balance_calculated === 0, 'Unconfigured feed preserves original bank balances without optional tables');
(new KreaBankFintableSync($db))->ensureSchema();
$db->query("INSERT INTO kbauto_const(name,value,type,entity) VALUES('KREABANK_FINTABLE_WORKSPACE','ws_balance','chaine',0)");
$db->query("INSERT INTO kbauto_bank_account(rowid,entity,ref,label,iban_prefix,currency_code,clos,courant,fk_pays) VALUES(120,1,'BALANCE','Balance fixture','FR1420041010050500013M02606','EUR',0,1,1)");
$db->query("INSERT INTO kbauto_kreabank_feed_account(rowid,entity,fk_bank_account,workspace_id,provider_account_id,provider_connection_id,iban,currency,balance,bank_updated_at,watermark,history_from,history_to,last_attempt,last_success,status,datec) VALUES(20,1,120,'ws_balance','acc_balance','conn_fixture','FR1420041010050500013M02606','EUR',3323.75,'2026-11-03T08:57:41Z','2026-11-03T09:10:05Z','2026-11-01','2026-11-03','2026-11-03 09:10:05','2026-11-03 09:10:05','ready',NOW())");
foreach (array(901=>array('-5.94','2026-11-03'),902=>array('100.00','2026-11-02'),903=>array('-20.00','2026-11-02'),904=>array('-10.00','2026-11-01')) as $id=>$fixture) {
	list($amount,$date)=$fixture;
	stageAuto($id,$amount,'BALANCE',120,1,'fintable');
	$db->query("UPDATE kbauto_kreabank_statement_line SET operation_date='$date',value_date='$date' WHERE rowid=$id");
	$payload=$db->escape(json_encode(array('amount'=>$amount,'currency'=>'EUR','operation_date'=>$date)));
	$db->query("INSERT INTO kbauto_kreabank_feed_transaction(entity,fk_feed_account,provider_transaction_id,source_uid,financial_hash,content_hash,fk_statement_line,source_payload,status,datec) VALUES(1,20,'tx_$id','fixture-$id',SHA2('financial-$id',256),SHA2('content-$id',256),$id,'$payload','imported',NOW())");
}
stageAuto(905,'5.00','FILE',120);
$db->query('UPDATE kbauto_kreabank_statement_line SET running_balance=12.12345678 WHERE rowid=905');
$balances = static function () use ($db,$report) {
	$result=$report->query('SELECT h.* FROM (' . $report->bankStatementWithBalancesSql(120) . ') h ORDER BY h.operation_date DESC,h.statement_id DESC,h.line_rank DESC,h.rowid DESC');
	$rows=array(); while($row=$db->fetch_object($result)) $rows[(int)$row->rowid]=$row;
	return $rows;
};
$before=array(countAuto('bank'),countAuto('kreabank_statement_line'),countAuto('kreabank_feed_transaction'));
$rows=$balances();
checkAuto($rows[901]->display_balance==='3323.75000000' && $rows[903]->display_balance==='3329.69000000' && $rows[902]->display_balance==='3349.69000000' && $rows[904]->display_balance==='3249.69000000', 'Derives each line backwards from the bank snapshot in stable statement order');
checkAuto($rows[905]->display_balance==='12.12345678' && (int)$rows[905]->balance_calculated===0 && (int)$rows[901]->balance_calculated===1, 'Original balances and calculated balances remain distinguishable');
checkAuto($rows[901]->running_balance===null, 'Calculated balances do not overwrite source evidence');
$db->query('UPDATE kbauto_kreabank_feed_account SET balance=9999999999999999.12345678 WHERE rowid=20');
checkAuto($balances()[901]->display_balance==='9999999999999999.12345678', 'Large source balances retain all eight decimal places without float conversion');
$db->query('UPDATE kbauto_kreabank_feed_account SET balance=0 WHERE rowid=20');
checkAuto($balances()[901]->display_balance==='0.00000000', 'A real zero bank snapshot remains a valid balance anchor');
$db->query('UPDATE kbauto_kreabank_feed_account SET balance=3323.75 WHERE rowid=20');
$source='(' . $report->bankStatementWithBalancesSql(120) . ') h';
$page=$db->fetch_object($report->query('SELECT h.* FROM '.$source.' ORDER BY h.display_balance DESC,h.rowid'.$db->plimit(1,1)));
checkAuto($page->display_balance===$rows[(int)$page->rowid]->display_balance, 'Sorting and pagination never restart the running balance');
foreach (array("status='failed'","status='mapping_review'","last_attempt='2026-11-03 10:00:00'","workspace_id='ws_old'","currency='USD'","iban='DE89370400440532013000'","entity=2","bank_updated_at='2026-11-02T08:57:41Z'") as $change) {
	$db->query('UPDATE kbauto_kreabank_feed_account SET '.$change.' WHERE rowid=20');
	checkAuto($balances()[901]->display_balance===null, 'Reject invalid/incomplete snapshot: '.$change);
	$db->query("UPDATE kbauto_kreabank_feed_account SET status='ready',last_attempt='2026-11-03 09:10:05',workspace_id='ws_balance',currency='EUR',iban='FR1420041010050500013M02606',entity=1,bank_updated_at='2026-11-03T08:57:41Z' WHERE rowid=20");
}
foreach (array("status='review'","fk_statement_line=NULL","source_payload='{}'","source_payload='invalid json'") as $change) {
	$db->begin();
	$db->query('UPDATE kbauto_kreabank_feed_transaction SET '.$change." WHERE provider_transaction_id='tx_902'");
	checkAuto($balances()[901]->display_balance===null, 'Conflicts, missing links or missing financial payload invalidate the account anchor');
	$db->rollback();
}
$db->query('UPDATE kbauto_kreabank_statement_line SET amount=99 WHERE rowid=902');
checkAuto($balances()[901]->display_balance===null, 'Edited persisted source amount cannot silently alter running balances');
$db->query('UPDATE kbauto_kreabank_statement_line SET amount=100 WHERE rowid=902');
$db->query('UPDATE kbauto_kreabank_statement_line SET is_duplicate=1 WHERE rowid=902');
checkAuto($balances()[901]->display_balance===null, 'Suspected duplicate invalidates the running-balance anchor');
$db->query('UPDATE kbauto_kreabank_statement_line SET is_duplicate=0 WHERE rowid=902');
$db->begin();
$db->query("INSERT INTO kbauto_kreabank_feed_transaction(entity,fk_feed_account,provider_transaction_id,source_uid,financial_hash,content_hash,fk_statement_line,source_payload,status,datec) SELECT entity,fk_feed_account,'tx_duplicate',source_uid,financial_hash,content_hash,fk_statement_line,source_payload,status,datec FROM kbauto_kreabank_feed_transaction WHERE provider_transaction_id='tx_902'");
checkAuto($balances()[901]->display_balance===null && count($balances())===5, 'Duplicate source mappings invalidate balances without duplicating display rows');
$db->rollback();
checkAuto($before===array(countAuto('bank'),countAuto('kreabank_statement_line'),countAuto('kreabank_feed_transaction')), 'Balance display performs no financial or source writes');
print "OK: $checks calculated statement balance checks\n";
