#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 * ONLY the guarded disposable MariaDB at 127.0.0.1:33317/kreabank_auto_test.
 */
require __DIR__ . '/test_bank_integration_database.php';
$checks = 0;
stageAuto(701, '-12.34567890');
stageAuto(702, '45.00');
stageAuto(703, '45.00', 'SECOND-ACCOUNT', 102);
stageAuto(704, '45.00', 'FOREIGN', 202, 2);
bankAuto(702, '45.00');
bankAuto(705, '-3.00');
bankAuto(706, '11.00');
bankAuto(707, '11.00');
$db->query("UPDATE kbauto_kreabank_statement_line SET running_balance=0,value_date='2026-11-02',payment_reference='PAY-701',bank_reference='BANK-701',description='Original bank description',is_duplicate=1 WHERE rowid=701");
$db->query("UPDATE kbauto_kreabank_statement_line SET status=2,fk_native_bank_line=702 WHERE rowid=702");
$db->query("UPDATE kbauto_bank SET rappro=1,num_releve='ST-702' WHERE rowid=702");
// Legacy content sharing a staged reference must not disappear from the combined view.
$db->query("UPDATE kbauto_bank SET num_releve='ST-702',note='KreaBank import fixture' WHERE rowid=705");
$db->query("UPDATE kbauto_bank SET num_releve='ORDINARY-NATIVE' WHERE rowid=706");
$db->query("UPDATE kbauto_bank SET num_releve='LEGACY-META' WHERE rowid=707");
$db->query("INSERT INTO kbauto_kreabank_bankmeta(entity,fk_bank_line,bank_account_id,idempotency_hash,amount,currency,status,datec) VALUES(1,707,101,SHA2('statement-meta',256),11,'EUR',0,NOW())");
$before = array(countAuto('bank'), countAuto('kreabank_statement'), countAuto('kreabank_statement_line'), countAuto('kreabank_recon_audit'));
$readRows = static function ($accountId = 0) use ($report, $db) {
	$result = $report->query('SELECT h.* FROM (' . $report->bankStatementSql($accountId) . ') h ORDER BY h.operation_date DESC,h.statement_id,h.line_rank,h.rowid,h.source_storage');
	$rows = array();
	while ($row = $db->fetch_object($result)) { $rows[$row->source_storage . ':' . $row->rowid] = $row; }
	return $rows;
};
$rows = $readRows(101);
checkAuto(isset($rows['staging:701'], $rows['staging:702'], $rows['legacy_native:705'], $rows['legacy_native:707']), 'Combines pending, reconciled, overlapping-reference and metadata-only legacy imports');
checkAuto(!isset($rows['legacy_native:702']), 'Mapped native movement is not counted twice');
checkAuto(!isset($rows['legacy_native:706']), 'Ordinary native movements do not become imported bank evidence');
checkAuto(!isset($rows['staging:703']) && !isset($rows['staging:704']), 'Selected account excludes other accounts and companies');
checkAuto($rows['staging:701']->amount === '-12.34567890' && $rows['staging:701']->running_balance === '0.00000000', 'Preserves signed decimal source precision and an actual zero balance');
checkAuto($rows['staging:702']->running_balance === null, 'Missing bank balance remains unknown');
checkAuto($rows['staging:701']->value_date === '2026-11-02' && $rows['staging:701']->payment_reference === 'PAY-701' && $rows['staging:701']->bank_reference === 'BANK-701' && $rows['staging:701']->description === 'Original bank description', 'Preserves original dates, description and both references');
checkAuto((int) $rows['staging:701']->is_duplicate === 1, 'Source duplicate flag remains available to render');
checkAuto(kreabankIntegrationLineState($rows['staging:702'], $rows['staging:702']->statement_ref) === 'KreaBankReconciled', 'Reconciliation state uses each source statement identity');
$all = $readRows();
checkAuto(isset($all['staging:703']) && !isset($all['staging:704']), 'All accounts still excludes foreign-company evidence');
checkAuto($readRows(202) === array(), 'Foreign account filter returns no data');
// Ownership is checked through both the statement and native account joins.
$db->query('UPDATE kbauto_kreabank_statement SET bank_account_id=202 WHERE ref=\'ST-701\' AND entity=1');
checkAuto(!isset($readRows()['staging:701']), 'Forged foreign-account statement ownership is denied');
$db->query('UPDATE kbauto_kreabank_statement SET bank_account_id=101 WHERE ref=\'ST-701\' AND entity=1');
for ($i = 710; $i < 770; $i++) { stageAuto($i, (string) ($i - 750)); }
$source = '(' . $report->bankStatementSql(101) . ') h';
$order = ' ORDER BY h.operation_date DESC,h.statement_id DESC,h.line_rank DESC,h.rowid DESC,h.source_storage';
$count = (int) $db->fetch_object($report->query('SELECT COUNT(*) nb FROM ' . $source))->nb;
$ids = array();
for ($page = 0; $page * 50 < $count; $page++) {
	$result = $report->query('SELECT h.* FROM ' . $source . $order . $db->plimit(50, 50 * $page));
	while ($row = $db->fetch_object($result)) { $ids[] = $row->source_storage . ':' . $row->rowid; }
}

checkAuto(count($ids) === $count && count(array_unique($ids)) === $count, 'Stable pagination has no missing or repeated rows with equal operation dates');
$first = $db->fetch_object($report->query('SELECT h.* FROM ' . $source . ' WHERE h.rowid BETWEEN 710 AND 769 ORDER BY h.amount ASC,h.rowid' . $db->plimit(1, 0)));
checkAuto($first->amount === '-40.00000000', 'Numeric sorting applies before pagination');
checkAuto($before[0] === countAuto('bank') && $before[1] + 60 === countAuto('kreabank_statement') && $before[2] + 60 === countAuto('kreabank_statement_line') && $before[3] === countAuto('kreabank_recon_audit'), 'Repeated statement reads create no ledger, staging or audit records');
$db->query("INSERT INTO kbauto_bank_account (rowid,entity,ref,label,currency_code,clos,courant,fk_pays) VALUES (104,1,'LEGACY','Legacy only','EUR',1,1,1),(105,1,'EMPTY','No statements','EUR',0,1,1),(106,1,'LEDGER','Ledger only','EUR',0,1,1)");
bankAuto(780, '12.00', 'LEGACY', 104);
bankAuto(781, '12.00', 'ORDINARY', 106);
$db->query("UPDATE kbauto_bank SET num_releve='LEGACY-ONLY',note='KreaBank import fixture' WHERE rowid=780");
$db->query("UPDATE kbauto_bank SET num_releve='NATIVE-ONLY' WHERE rowid=781");
$accounts = array();
$result = $report->statementAccounts();
while ($row = $db->fetch_object($result)) { $accounts[] = (int) $row->rowid; }
checkAuto(in_array(101, $accounts, true) && in_array(102, $accounts, true), 'Statement account selector includes staged imports');
checkAuto(in_array(104, $accounts, true), 'Statement account selector includes a closed account with only legacy imports');
checkAuto(!in_array(105, $accounts, true) && !in_array(106, $accounts, true), 'Empty and ordinary-ledger-only accounts are absent from the statement selector');
checkAuto(!in_array(202, $accounts, true), 'Statement selector never exposes another company account');
print "OK: $checks combined bank statement database checks\n";
