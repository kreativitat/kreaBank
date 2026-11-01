#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 * ONLY the disposable localhost:33317/kreabank_auto_test fixtures.
 */
require __DIR__ . '/test_bulk_review_database.php';
$initialChecks = $checks;
$scope = new KreaBankReconciliationScope($db, 1);
stageAuto(9100, '17.00'); bankAuto(19100, '17.00');
bankAuto(19101, '17.00', 'OTHER ACCOUNT', 102);
bankAuto(19102, '17.00', 'FOREIGN ACCOUNT', 202);
$line = $service->getBulkReviewLine(9100, 'staged');
$scope->assertLine($line); $scope->assertDocument('native_bank',19100,101);
checkAuto(true, 'Owned source and same-account native movement are accepted');
rejectAuto(static function () use($scope){$scope->assertDocument('native_bank',19101,101);}, 'Another current-company bank account is rejected');
rejectAuto(static function () use($scope){$scope->assertDocument('native_bank',19102,101);}, 'Foreign-company native movement is rejected');
$foreignLine = $line; $foreignLine['bank_account_id']=202;
rejectAuto(static function () use($service,$foreignLine){$service->getSuggestionsForLines([$foreignLine]);}, 'Forged prepared source cannot read candidates in another account');
$db->query('UPDATE kbauto_kreabank_statement SET entity=2 WHERE rowid=9100');
rejectAuto(static function () use($scope,$line){$scope->assertLine($line);}, 'Stage and statement parent must have identical company ownership');
$db->query('UPDATE kbauto_kreabank_statement SET entity=1 WHERE rowid=9100');
$conf->entity=2;
rejectAuto(static function () use($scope,$line){$scope->assertLine($line);}, 'Changed request company cannot reuse an old service context');
$conf->entity=1;
$db->query("INSERT INTO kbauto_facture(rowid,ref,entity,fk_soc,datec,datef,total_ttc,fk_statut,paye,type) VALUES (29100,'OWNED-INVOICE',1,1,NOW(),'2026-11-01',17,1,0,0),(29101,'FOREIGN-INVOICE',2,1,NOW(),'2026-11-01',17,1,0,0)");
$scope->assertDocument('customer_invoice',29100,101);
rejectAuto(static function () use($scope){$scope->assertDocument('customer_invoice',29101,101);}, 'Foreign invoice is rejected regardless of its displayed customer name');
$db->query("INSERT INTO kbauto_paiement(rowid,entity,ref,datec,datep,amount,fk_bank,fk_paiement) VALUES (39100,1,'OWNED-PAYMENT',NOW(),'2026-11-01',17,19100,1),(39101,1,'WRONG-ACCOUNT-PAYMENT',NOW(),'2026-11-01',17,19102,1)");
$scope->assertDocument('payment_linked',39100,101);
rejectAuto(static function () use($scope){$scope->assertDocument('payment_linked',39101,101);}, 'Current-company payment cannot point to a foreign bank account');
$db->query('INSERT INTO kbauto_paiement_facture(fk_paiement,fk_facture,amount) VALUES (39100,29101,17)');
rejectAuto(static function () use($scope){$scope->assertDocument('payment_linked',39100,101);}, 'Current-company payment cannot conceal a foreign invoice allocation');
$candidates=$service->getLinkedPaymentDocuments(1,100,'2026-11-01',3,17,101,false);
checkAuto(!in_array(39100,array_column($candidates,'rowid'),true) && !in_array(39101,array_column($candidates,'rowid'),true), 'Candidate reads reject foreign invoice parents and bank ownership');
$bankCount=countAuto('bank');$auditCount=countAuto('kreabank_recon_audit');
rejectAuto(static function () use($service){$service->reconcileLine(9100,[['doc_type'=>'native_bank','fk_doc'=>19101,'allocated_amount'=>17]]);}, 'Execution rejects another account before materialization');
checkAuto(countAuto('bank')===$bankCount && countAuto('kreabank_recon_audit')===$auditCount && $service->getBulkReviewLine(9100,'staged')['status']===0, 'Denied write leaves ledger, staging and audit unchanged');
$four=[];for($i=0;$i<4;$i++){$four[]=['doc_type'=>'customer_invoice','doc_id'=>500+$i,'score'=>100];}
checkAuto(!kreabankBulkSuggestionIsUnambiguous($four), 'Four equal amount/date invoices cannot choose the first customer');
checkAuto(kreabankBulkSuggestionIsUnambiguous([$four[0]]), 'A unique weak pair remains available for explicit manual review');
$four[0]['score']=135;
checkAuto(kreabankBulkSuggestionIsUnambiguous($four), 'A 35-point lead can distinguish manual review candidates');
echo 'OK: '.($checks-$initialChecks)." company/account isolation database checks\n";
