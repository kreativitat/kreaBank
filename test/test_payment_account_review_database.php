#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Extends ONLY the guarded disposable 127.0.0.1:33317/kreabank_auto_test fixture.
 */
require __DIR__ . '/test_company_isolation_database.php';
$initialChecks = $checks;
function accountReviewFixture($id, $supplier = false, $account = 102, $entity = 1)
{
	global $db;
	$amount = $supplier ? '-231.47' : '231.47';
	$reference = 'PAYMENT-' . $id . '-20261101';
	stageAuto($id, $amount, $reference);
	bankAuto($id + 1000, $amount, $reference, $account);
	$payment = $supplier ? 'paiementfourn' : 'paiement';
	$invoice = $supplier ? 'facture_fourn' : 'facture';
	$link = $supplier ? 'paiementfourn_facturefourn' : 'paiement_facture';
	$paymentKey = $supplier ? 'fk_paiementfourn' : 'fk_paiement';
	$invoiceKey = $supplier ? 'fk_facturefourn' : 'fk_facture';
	$db->query("INSERT INTO kbauto_$invoice(rowid,ref," . ($supplier ? "ref_supplier," : "") . "entity,fk_soc,datec,total_ttc,fk_statut,paye,type) VALUES($id,'INVOICE-$id'," . ($supplier ? "'SUPPLIER-$id'," : "") . "$entity,1,NOW(),231.47,1,1,0)");
	$db->query("INSERT INTO kbauto_$payment(rowid,entity,ref,datec,datep,amount,fk_bank,fk_paiement,num_paiement) VALUES($id,$entity,'PAY-$id',NOW(),'2026-11-01',231.47," . ($id+1000) . ",1,'$reference')");
	$db->query("INSERT INTO kbauto_$link($paymentKey,$invoiceKey,amount) VALUES($id,$id,231.47)");
	$db->query("INSERT INTO kbauto_bank_url(fk_bank,url_id,url,label,type) VALUES(" . ($id+1000) . ",$id,'/synthetic/','(paiement)','" . ($supplier ? 'payment_supplier' : 'payment') . "')");
}
// Keep only new review fixtures in the scheduled queue.
$db->query('UPDATE kbauto_kreabank_statement_line SET status=3 WHERE status=0');
accountReviewFixture(70000);
accountReviewFixture(70001, true);
$before = array(countAuto('bank'),countAuto('paiement'),countAuto('paiementfourn'),countAuto('kreabank_recon_audit'));
foreach (array(70000,70001) as $id) {
	$review = $service->getPaymentAccountReview($id,array(101));
	checkAuto($review && count($review['candidates'])===1 && $review['candidates'][0]['bank_account_id']===102
		&& $review['line']['bank_account_id']===101, 'Customer and supplier errors show recorded and statement accounts');
	checkAuto($service->getAutomaticNativeSuggestion($id,array(101))===null, 'Wrong-account payment is never an automatic candidate');
}
checkAuto($service->getPaymentAccountReview(71000,array(101))===null, 'A native ID cannot select a staged review');
checkAuto($service->getPaymentAccountReview(70000,array(102))===null, 'Source account allowlist is enforced');
settingAuto('enabled');
$preview = $auto->process($user,true);
checkAuto($preview['review_required']===2 && $preview['reconciled']===0 && count($preview['account_reviews'])===2, 'Preview asks for account review with actionable evidence');
$result = $auto->process($user,false);
checkAuto($result['review_required']===2 && $result['reconciled']===0 && $result['failed']===0, 'Scheduled matching leaves suspected errors pending');
checkAuto(array_keys($result['account_reviews'][0])===array('statement_line_id'), 'Scheduled output contains IDs only');
checkAuto($before===array(countAuto('bank'),countAuto('paiement'),countAuto('paiementfourn'),countAuto('kreabank_recon_audit')), 'Detection and preview create no ledger entries, payments or audits');
// A second possible payment is displayed, never silently selected.
accountReviewFixture(70002);
$db->query("UPDATE kbauto_paiement SET num_paiement='PAYMENT-70000-20261101' WHERE rowid=70002");
checkAuto(count($service->getPaymentAccountReview(70000,array(101))['candidates'])===2, 'Competing wrong-account payments are all left for review');
$db->query("UPDATE kbauto_paiement SET num_paiement='PAYMENT-70002-20261101' WHERE rowid=70002");
// Even if a same-account match also exists, conflicting payment evidence requires review.
bankAuto(72000,'231.47','PAYMENT-70000-20261101',101);
checkAuto($service->getAutomaticNativeSuggestion(70000,array(101))===null, 'Same-account match cannot conceal a possible misposted payment');
$db->query('DELETE FROM kbauto_bank WHERE rowid=72000');
foreach (array("amount=231.48", "amount=-231.47", "dateo='2026-09-01'") as $change) {
	$db->query("UPDATE kbauto_bank SET $change WHERE rowid=71000");
	checkAuto($service->getPaymentAccountReview(70000,array(101))===null, 'Amount, direction and date mismatches do not produce an account warning');
	$db->query("UPDATE kbauto_bank SET amount=231.47,dateo='2026-11-01' WHERE rowid=71000");
}
$db->query("UPDATE kbauto_bank_account SET currency_code='USD' WHERE rowid=102");
checkAuto($service->getPaymentAccountReview(70000,array(101))===null, 'Different currency cannot suggest an account correction');
$db->query("UPDATE kbauto_bank_account SET currency_code='EUR' WHERE rowid=102");
$db->query("UPDATE kbauto_paiement SET num_paiement='TRANSFER' WHERE rowid=70000");
$db->query("UPDATE kbauto_bank SET num_chq='TRANSFER' WHERE rowid=71000");
checkAuto($service->getPaymentAccountReview(70000,array(101))===null, 'Generic labels and amount/date alone cannot imply a wrong account');
$db->query("UPDATE kbauto_paiement SET num_paiement='PAYMENT-70000-20261101' WHERE rowid=70000");
$db->query("UPDATE kbauto_kreabank_statement_line SET payment_reference='PAYMENT-70000-202611010',description='' WHERE rowid=70000");
checkAuto($service->getPaymentAccountReview(70000,array(101))===null, 'Reference prefixes cannot imply a wrong account');
$db->query("UPDATE kbauto_kreabank_statement_line SET payment_reference='PAYMENT-70000-20261101' WHERE rowid=70000");
$db->query('UPDATE kbauto_facture SET entity=2 WHERE rowid=70000');
checkAuto($service->getPaymentAccountReview(70000,array(101))===null, 'Foreign parent invoice cannot leak a review candidate');
$db->query('UPDATE kbauto_facture SET entity=1 WHERE rowid=70000');
$db->query('INSERT INTO kbauto_paiement_facture(fk_paiement,fk_facture,amount) VALUES(70000,29101,1)');
checkAuto($service->getPaymentAccountReview(70000,array(101))===null, 'Mixed-company payment allocations fail closed');
$db->query('DELETE FROM kbauto_paiement_facture WHERE fk_paiement=70000 AND fk_facture=29101');
$db->query('UPDATE kbauto_bank SET fk_account=202 WHERE rowid=71000');
checkAuto($service->getPaymentAccountReview(70000,array(101))===null, 'Foreign bank account cannot leak a candidate');
$db->query('UPDATE kbauto_bank SET fk_account=102,rappro=1 WHERE rowid=71000');
checkAuto($service->getPaymentAccountReview(70000,array(101))['candidates'][0]['reconciled'], 'Already reconciled mistakes are shown for review without unlocking them');
$db->query('UPDATE kbauto_bank SET rappro=0 WHERE rowid=71000');
$db->query('UPDATE kbauto_facture SET type=2 WHERE rowid=70000');
checkAuto($service->getPaymentAccountReview(70000,array(101))===null, 'Credit notes do not enter ordinary payment correction review');
$db->query('UPDATE kbauto_facture SET type=0 WHERE rowid=70000');

$db->query('UPDATE kbauto_facture SET type=3 WHERE rowid=70000');
checkAuto($service->getPaymentAccountReview(70000,array(101))!==null, 'Non-credit-note invoice types retain payment review coverage');
$db->query('UPDATE kbauto_facture SET type=0 WHERE rowid=70000');
foreach (array('UTC','Europe/Lisbon','Europe/Zurich','America/New_York') as $zone) {
	date_default_timezone_set($zone);
	$db->query("UPDATE kbauto_bank SET dateo='2026-11-04 23:59:59' WHERE rowid=71000");
	checkAuto($service->getPaymentAccountReview(70000,array(101))!==null, 'Full final tolerance day is included regardless of server timezone');
}
date_default_timezone_set('UTC');
$db->query("UPDATE kbauto_bank SET dateo='2026-11-01',num_chq='NATIVE-REFERENCE-12345' WHERE rowid=71000");
$db->query("UPDATE kbauto_kreabank_statement_line SET payment_reference='NATIVE-REFERENCE-12345' WHERE rowid=70000");
checkAuto($service->getPaymentAccountReview(70000,array(101))!==null, 'Native check reference can identify a payment even when its payment number differs');
$db->query("UPDATE kbauto_bank SET num_chq='PAYMENT-70000-20261101' WHERE rowid=71000");
$db->query("UPDATE kbauto_kreabank_statement_line SET payment_reference='PAYMENT-70000-20261101' WHERE rowid=70000");
// Truncated evidence never silently allows ordinary automation to continue.
for ($i=0;$i<101;$i++) {
	$bankId=73000+$i;$paymentId=74000+$i;
	bankAuto($bankId,'231.47','OTHER-REFERENCE-12345',102);
	$db->query("INSERT INTO kbauto_paiement(rowid,entity,ref,datep,amount,fk_bank,fk_paiement) VALUES($paymentId,1,'OTHER-$paymentId','2026-11-01',231.47,$bankId,1)");
	$db->query("INSERT INTO kbauto_paiement_facture(fk_paiement,fk_facture,amount) VALUES($paymentId,70000,231.47)");
}
checkAuto($service->getPaymentAccountReview(70000,array(101))['truncated'], 'Candidate limit is visible and blocks unattended matching');
$db->query('DELETE FROM kbauto_paiement_facture WHERE fk_paiement BETWEEN 74000 AND 74100');
$db->query('DELETE FROM kbauto_paiement WHERE rowid BETWEEN 74000 AND 74100');
$db->query('DELETE FROM kbauto_bank WHERE rowid BETWEEN 73000 AND 73100');

$readOnlyUser = new class extends User {
	public function hasRight($module,$right,$subright='') {
		return ($module==='banque' && $right==='lire') || ($module==='kreabank' && $right==='reconciliation' && $subright==='read');
	}
};
$readOnlyService = new KreaBankService($db,$readOnlyUser,$langs);
checkAuto($readOnlyService->getPaymentAccountReview(70000,array(101))!==null, 'Authorized read-only users can see account warnings');
rejectAuto(static function () use ($readOnlyService) { $readOnlyService->getAutomaticNativeSuggestion(70000,array(101)); }, 'Read-only review never grants automatic write authority');
// Execution rechecks review evidence after the reviewed source/candidate locks are taken.
bankAuto(72000,'231.47','PAYMENT-70000-20261101',101);
$bulkRow = reviewedFixture(70000,72000);
rejectAuto(static function () use ($bulkRow) { applyReviewedFixture($bulkRow); }, 'Even a signed ordinary bulk pair cannot bypass a wrong-account warning');
checkAuto($service->getBulkReviewLine(70000,'staged')['status']===0, 'Rejected bulk approval preserves the pending source');
$db->query('DELETE FROM kbauto_bank WHERE rowid=72000');
$denied = clone $user; $denied->allowed=false;
$deniedService = new KreaBankService($db,$denied,$langs);
rejectAuto(static function () use ($deniedService) { $deniedService->getPaymentAccountReview(70000,array(101)); }, 'Permission loss blocks evidence reads');
$conf->entity=2;
rejectAuto(static function () use ($service) { $service->getPaymentAccountReview(70000,array(101)); }, 'Changed company invalidates the review service');
$conf->entity=1;
$policy = new KreaBankAccountPairs($db,1);
$policy->configure($user,array(102=>101));
checkAuto($service->getPaymentAccountReview(70000,array(101))===null, 'Restricted accounts cannot be proposed for ordinary invoice payment correction');
$policy->configure($user,array());
// Simulate a reviewed native correction, retaining the existing payment and invoice allocation.
$db->query("UPDATE kbauto_bank SET fk_account=101,num_chq='PAYMENT-70000-20261101' WHERE rowid=71000");
checkAuto($service->getPaymentAccountReview(70000,array(101))===null, 'Warning disappears after the recorded account is corrected');
checkAuto($service->getAutomaticNativeSuggestion(70000,array(101))!==null, 'Corrected payment resumes ordinary safe same-account matching');
checkAuto($service->reconcileAutomaticNativeLine(70000,array(101))===true, 'Corrected native payment reconciles through the existing executor');
checkAuto(countAuto('bank')===$before[0]+1 && countAuto('paiement')===$before[1]+1, 'Correction and reconciliation do not duplicate the existing payment or bank movement');
// A feed with no remittance reference still needs an actionable account warning.
accountReviewFixture(70003, true);
$db->query("INSERT INTO kbauto_societe (rowid,entity,nom,fournisseur) VALUES (11001,1,'AC42 - Fábrica Exemplo, Lda.',1),(11002,1,'Outra Empresa, Lda.',1)");
$db->query('UPDATE kbauto_facture_fourn SET fk_soc=11001 WHERE rowid=70003');
$db->query("UPDATE kbauto_kreabank_statement_line SET payment_reference=NULL,counterparty_name=NULL,description='Trf imediata FABRICA EXEMPLO LDA' WHERE rowid=70003");
$db->query("UPDATE kbauto_paiementfourn SET num_paiement='' WHERE rowid=70003");
$db->query('UPDATE kbauto_bank SET num_chq=NULL WHERE rowid=71003');
$beforeNames = array(countAuto('bank'),countAuto('paiementfourn'),countAuto('kreabank_recon_audit'));
$review = $service->getPaymentAccountReview(70003,array(101));
checkAuto($review && count($review['candidates'])===1 && $review['candidates'][0]['payment_id']===70003 && $review['candidates'][0]['bank_account_id']===102, 'Reference-less feed detects the exact-amount/date payment through its complete invoice counterparty');
checkAuto($service->getAutomaticNativeSuggestion(70003,array(101))===null, 'Name evidence remains review-only and cannot authorize cross-account matching');
checkAuto($beforeNames===array(countAuto('bank'),countAuto('paiementfourn'),countAuto('kreabank_recon_audit')), 'Name detection changes no ledger records, payments or audits');
foreach (array('FABRICA','FABRICA EXEMPLO2 LDA','UNRELATED SUPPLIER LDA') as $description) {
	$db->query("UPDATE kbauto_kreabank_statement_line SET description='$description' WHERE rowid=70003");
	checkAuto($service->getPaymentAccountReview(70003,array(101))===null, 'Partial and unrelated payee names do not generate a warning');
}
$db->query("UPDATE kbauto_kreabank_statement_line SET description='Trf imediata FABRICA EXEMPLO LDA',payment_reference='DIFFERENT-REFERENCE-123456' WHERE rowid=70003");
checkAuto($service->getPaymentAccountReview(70003,array(101))===null, 'Name evidence cannot override a conflicting explicit bank payment reference');
$db->query("UPDATE kbauto_kreabank_statement_line SET description='',payment_reference=NULL,counterparty_name='Fábrica Exemplo, Lda.' WHERE rowid=70003");
checkAuto($service->getPaymentAccountReview(70003,array(101))!==null, 'The complete structured counterparty name also identifies review evidence');
foreach (array("amount=-231.48", "operation_date='2026-09-01'", "currency='USD'") as $change) {
	$db->query("UPDATE kbauto_kreabank_statement_line SET $change WHERE rowid=70003");
	checkAuto($service->getPaymentAccountReview(70003,array(101))===null, 'Name evidence retains exact amount, currency and date scope');
	$db->query("UPDATE kbauto_kreabank_statement_line SET amount=-231.47,operation_date='2026-11-01',currency='EUR' WHERE rowid=70003");
}
$db->query("UPDATE kbauto_paiementfourn_facturefourn SET amount=231.46 WHERE fk_paiementfourn=70003");
checkAuto($service->getPaymentAccountReview(70003,array(101))===null, 'Incomplete invoice allocation totals cannot supply counterparty evidence');
$db->query("UPDATE kbauto_paiementfourn_facturefourn SET amount=231.47 WHERE fk_paiementfourn=70003");
$db->query("INSERT INTO kbauto_facture_fourn (rowid,ref,ref_supplier,entity,fk_soc,datec,total_ttc,fk_statut,paye,type) VALUES (70004,'SYNTHETIC-OTHER','OTHER',1,11002,NOW(),1,1,1,0)");
$db->query('INSERT INTO kbauto_paiementfourn_facturefourn (fk_paiementfourn,fk_facturefourn,amount) VALUES (70003,70004,0)');
checkAuto($service->getPaymentAccountReview(70003,array(101))===null, 'Mixed invoice counterparties cannot infer a payment identity');
$db->query('DELETE FROM kbauto_paiementfourn_facturefourn WHERE fk_paiementfourn=70003 AND fk_facturefourn=70004');
$db->query('UPDATE kbauto_facture_fourn SET entity=2 WHERE rowid=70003');
checkAuto($service->getPaymentAccountReview(70003,array(101))===null, 'Name evidence cannot disclose foreign invoice parents');
$db->query('UPDATE kbauto_facture_fourn SET entity=1 WHERE rowid=70003');
$db->query('UPDATE kbauto_bank SET fk_account=202 WHERE rowid=71003');
checkAuto($service->getPaymentAccountReview(70003,array(101))===null, 'Name evidence cannot disclose foreign bank accounts');
$db->query('UPDATE kbauto_bank SET fk_account=102,rappro=1 WHERE rowid=71003');
checkAuto($service->getPaymentAccountReview(70003,array(101))['candidates'][0]['reconciled'], 'Name-based warnings preserve existing reconciliation locks');
echo 'OK: '.($checks-$initialChecks)." payment account review database checks\n";
