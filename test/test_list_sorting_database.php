#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 * ONLY the disposable database guarded by the automatic reconciliation suite.
 */
require __DIR__ . '/test_automatic_reconciliation_database.php';
require_once __DIR__ . '/../lib/kreabank_integration.lib.php';
$conf->entity = 1;
$checks = 0;
$db->query("INSERT INTO kbauto_bank_account (rowid,entity,ref,label,currency_code,clos,courant,rappro,fk_pays) VALUES
 (9901,1,'SORT-1','Sorting fixture 1','EUR',0,1,1,1),
 (9902,1,'SORT-2','Sorting fixture 2','EUR',0,1,1,1),
 (9903,1,'SORT-3','Sorting fixture 3','EUR',0,1,1,1),
 (9904,2,'SORT-FOREIGN','Other company fixture','EUR',0,1,1,1)");
foreach (array(9901 => '-20.50', 9902 => '9.00', 9903 => '1000.10', 9904 => '-9999.00') as $id => $amount) {
	bankAuto($id, $amount, 'SORT-' . $id, $id);
}
$report = new KreaBankIntegration($db, 1);
$bankCount = countAuto('bank');
foreach (array('ASC' => array(9901, 9902, 9903), 'DESC' => array(9903, 9902, 9901)) as $direction => $expected) {
	$result = $report->accounts('all', 0, 'balance', $direction);
	$ids = array();
	while ($account = $db->fetch_object($result)) {
		checkAuto((int) $account->rowid !== 9904, 'Sorting never includes an account owned by another company');
		if ((int) $account->rowid >= 9901) { $ids[] = (int) $account->rowid; }
	}
	checkAuto($ids === $expected, 'Native account balances sort numerically in ' . $direction . ' order');
}
$result = $report->accounts('all', 9904, 'pending_count', 'DESC');
checkAuto($db->num_rows($result) === 0, 'A sorted direct account selection cannot cross company boundaries');
$result = $report->accounts('all', 0, 'balance', 'ASC');
$all = array();while($row=$db->fetch_object($result)){$all[]=(int)$row->rowid;}
$paged = array();
for($offset=0;$offset<count($all);$offset+=2){
	$result=$report->accounts('all',0,'balance','ASC',2,$offset);
	while($row=$db->fetch_object($result)){$paged[]=(int)$row->rowid;}
}
checkAuto($paged===$all && count(array_unique($paged))===count($all),'Native account pages retain complete scoped sort order without duplicates or foreign accounts');
checkAuto(countAuto('bank') === $bankCount, 'Sorting reports never creates a native movement');
echo "OK: $checks list sorting database checks\n";
