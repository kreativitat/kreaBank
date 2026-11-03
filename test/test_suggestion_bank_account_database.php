#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Extends ONLY the guarded disposable 127.0.0.1:33317/kreabank_auto_test fixture.
 */
require __DIR__ . '/test_company_isolation_database.php';
$initialChecks = $checks;
$documents = $service->getOpenDocuments(0,250,'2026-11-01',3,0,0,false);
$native = array();
foreach ($documents as $document) {
	if ($document['doc_type']==='native_bank') { $native[$document['rowid']]=$document; }
}
checkAuto($native[19100]['bank_account_id']===101 && $native[19100]['bank_account_ref']==='TEST A','Native suggestion reads its own bank account reference');
checkAuto($native[19101]['bank_account_id']===102 && $native[19101]['bank_account_ref']==='TEST C','Different owned accounts retain distinct references');
checkAuto(!isset($native[19102]),'Foreign-company account references remain excluded');
foreach (array(array('paiement',88000,19101),array('paiementfourn',88001,19100),array('paiement',88002,19102)) as $fixture) {
	list($table,$id,$bank)=$fixture;
	$db->query("INSERT INTO kbauto_$table (rowid,entity,ref,datec,datep,amount,fk_bank,fk_paiement) VALUES ($id,1,'SYNTHETIC-$id',NOW(),'2026-11-01',17,$bank,1)");
}
$linked = array();
foreach ($service->getLinkedPaymentDocuments(0,250,'2026-11-01',3,17,0,false) as $document) { $linked[$document['rowid']]=$document; }
checkAuto($linked[88000]['bank_account_ref']==='TEST C' && $linked[88000]['bank_account_id']===102,'Linked customer payment shows its recorded account');
checkAuto($linked[88001]['bank_account_ref']==='TEST A' && $linked[88001]['bank_account_id']===101,'Linked supplier payment shows its recorded account');
checkAuto(!isset($linked[88002]),'A payment cannot expose a foreign account reference');
$matcher = new KreaBankMatcher();
$suggestions = $matcher->getSuggestions($line,array($native[19100],$linked[88000],$linked[88001]),array(),3);
foreach ($suggestions as $suggestion) {
	checkAuto($suggestion['bank_account_ref']===($suggestion['doc_id']===88000?'TEST C':'TEST A'),'Matcher preserves the actual target account for presentation');
}
$plain=$native[19100];unset($plain['bank_account_id'],$plain['bank_account_ref']);
checkAuto($matcher->scoreLineToDocument($line,$plain,array(),3)===$matcher->scoreLineToDocument($line,$native[19100],array(),3),'Account presentation metadata does not change matching scores');
echo 'OK: ' . ($checks-$initialChecks) . " suggestion bank account database checks\n";
