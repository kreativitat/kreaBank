#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 * ONLY the guarded disposable 127.0.0.1:33317/kreabank_auto_test fixture.
 */
require __DIR__ . '/test_suggestion_bank_account_database.php';
$initialChecks=$checks;
foreach(array(array(19101,88000,'payment'),array(19100,88001,'payment_supplier')) as $link) {
	list($bank,$payment,$type)=$link;
	$db->query("INSERT INTO kbauto_bank_url(fk_bank,url_id,url,label,type) VALUES($bank,$payment,'/synthetic/','(paiement) / Synthetic supplier','$type')");
}
$read=static function() use($service) {
	$rows=array();foreach($service->getOpenDocuments(0,250,'2026-11-01',3,0,0,false) as $document){if($document['doc_type']==='native_bank')$rows[$document['rowid']]=$document;}return $rows;
};
$rows=$read();
checkAuto($rows[19100]['payment_ref']==='SYNTHETIC-88001' && $rows[19101]['payment_ref']==='SYNTHETIC-88000','Native supplier and customer payments supply their actual references');
$suggestion=$matcher->getSuggestions($line,array($rows[19100]),array(),3)[0];
checkAuto($suggestion['payment_ref']==='SYNTHETIC-88001' && strpos(kreabankBulkSuggestionReference($suggestion),'SYNTHETIC-88001 / ')===0,'Matcher carries the payment prefix to the suggested display');
$plain=$rows[19100];unset($plain['payment_ref']);
checkAuto($matcher->scoreLineToDocument($line,$plain,array(),3)===$matcher->scoreLineToDocument($line,$rows[19100],array(),3),'Payment display references do not change candidate scoring');
$db->query('UPDATE kbauto_paiementfourn SET entity=2 WHERE rowid=88001');
checkAuto($read()[19100]['payment_ref']==='','A forged foreign-company payment link cannot reveal its reference');
$db->query('UPDATE kbauto_paiementfourn SET entity=1,fk_bank=19101 WHERE rowid=88001');
checkAuto($read()[19100]['payment_ref']==='','A payment linked to another bank movement is not used for display');
$db->query('UPDATE kbauto_paiementfourn SET fk_bank=19100 WHERE rowid=88001');
$db->query('INSERT INTO kbauto_paiementfourn_facturefourn(fk_paiementfourn,fk_facturefourn,amount) VALUES(88001,999001,17)');
$db->query("INSERT INTO kbauto_facture_fourn(rowid,ref,ref_supplier,entity,fk_soc,datec,total_ttc,fk_statut,paye,type) VALUES(999001,'FOREIGN-REF','FOREIGN',2,1,NOW(),17,1,1,0)");
checkAuto($read()[19100]['payment_ref']==='','Payment parents preserve invoice company ownership for display');
echo 'OK: '.($checks-$initialChecks)." suggestion payment reference database checks\n";
