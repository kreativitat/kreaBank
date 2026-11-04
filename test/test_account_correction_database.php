#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 * ONLY the guarded disposable localhost:33317/kreabank_auto_test fixture.
 */
require __DIR__ . '/test_payment_account_review_database.php';
$initialChecks = $checks;
$sql = file_get_contents(DOL_DOCUMENT_ROOT . '/install/mysql/tables/llx_accounting_bookkeeping-accounting.sql');
$sql = preg_replace('/--[^\n]*/', '', $sql);
foreach (explode(';', str_replace('llx_', MAIN_DB_PREFIX, $sql)) as $statement) {
    if (trim($statement) !== '') { $db->query($statement); }
}
// Paid invoices can have a generic statement narrative with neither a shared
// payment reference nor the invoice counterparty's name. They remain discoverable
// for manual review without becoming strong wrong-account warnings or new bills.
foreach (array(false,true) as $supplier) {
    $id = $supplier ? 80601 : 80600;
    $bankId = $id+1000;
    $signed = $supplier ? '-9.23' : '9.23';
    $payment = $supplier ? 'paiementfourn' : 'paiement';
    $invoice = $supplier ? 'facture_fourn' : 'facture';
    $allocation = $supplier ? 'paiementfourn_facturefourn' : 'paiement_facture';
    $paymentKey = $supplier ? 'fk_paiementfourn' : 'fk_paiement';
    accountReviewFixture($id,$supplier);
    $db->query("UPDATE kbauto_kreabank_statement_line SET amount=$signed,payment_reference='',description='Monthly terminal rental',counterparty_name='' WHERE rowid=$id");
    $db->query("UPDATE kbauto_bank SET amount=$signed,num_chq='',label='Existing invoice payment' WHERE rowid=$bankId");
    $db->query("UPDATE kbauto_$payment SET amount=9.23,num_paiement='' WHERE rowid=$id");
    $db->query("UPDATE kbauto_$invoice SET total_ttc=9.23,fk_statut=2,paye=1 WHERE rowid=$id");
    $db->query("UPDATE kbauto_$allocation SET amount=9.23 WHERE $paymentKey=$id");
    $beforeLookup = array(countAuto('bank'),countAuto($payment),countAuto('kreabank_recon_audit'));
    checkAuto($service->getPaymentAccountReview($id,array(101))===null, 'Generic narrative does not relax strong wrong-account warning criteria');
    $possible = $service->searchOtherAccountMovements($id);
    checkAuto(count($possible)===1 && (int)$possible[0]['rowid']===$bankId && $possible[0]['can_move'],
        'Amount/date lookup finds the existing paid '.($supplier?'supplier':'customer').' invoice payment for explicit account correction');
    checkAuto(strpos($possible[0][$supplier?'supplier_docs':'customer_docs'],'INVOICE-'.$id)!==false,
        'The paid invoice remains visible through its original payment allocations');
    checkAuto($beforeLookup===array(countAuto('bank'),countAuto($payment),countAuto('kreabank_recon_audit')),
        'Discovering a paid invoice creates no movement, payment or audit');
}
accountReviewFixture(80000, true);
$db->query("UPDATE kbauto_bank SET label='Synthetic correction payment' WHERE rowid=81000");
function correctionEntry($id = 80000, $search = '') {
    global $service;
    return $service->searchOtherAccountMovements($id, $search, $id+1000)[0] ?? null;
}
function correctEntry($entry) {
    global $service;
    return $service->changeMovementAccount($entry['source_id'], $entry['rowid'], $entry['snapshot'], $entry['fk_account'], $entry['target_account_id']);
}
// The visible bound is applied after search, including late matches and invoice evidence.
for ($i=0;$i<110;$i++) { bankAuto(82000+$i,'-231.47','UNRELATED-'.(82000+$i),102); }
$db->query("UPDATE kbauto_bank SET label='LAST-SEARCH-RESULT' WHERE rowid=82109");
checkAuto(count($service->searchOtherAccountMovements(80000,''))===101, 'Candidate overflow is visible instead of silently claiming completeness');
$last=$service->searchOtherAccountMovements(80000,'LAST-SEARCH-RESULT');
checkAuto(count($last)===1 && (int)$last[0]['rowid']===82109, 'Search finds matches beyond the first candidate page: '.json_encode(array_column($last,'rowid')));
$db->query('DELETE FROM kbauto_bank WHERE rowid BETWEEN 82000 AND 82109');
$entry = correctionEntry();
checkAuto($entry && $entry['can_move'] && (int) $entry['fk_account']===102 && $entry['target_account_id']===101, 'Other-account payment is reviewable with explicit source and target identities');
foreach (array('Synthetic correction','PAY-80000','INVOICE-80000','SUPPLIER-80000','-231,47','01/11/2026') as $query) {
    $results=$service->searchOtherAccountMovements(80000,$query);
    checkAuto(in_array(81000,array_column($results,'rowid')), 'Search filters complete scoped results before limiting: '.$query);
}
checkAuto($service->searchOtherAccountMovements(80000,'%')===array(), 'Search wildcard characters are literal');
checkAuto($service->searchOtherAccountMovements(81000,'')===array(), 'Native ID never resolves as a staging source');
$counts=array(countAuto('bank'),countAuto('paiementfourn'),countAuto('kreabank_recon_audit'));
foreach (array('rappro=1','fk_bordereau=9') as $change) {
    $db->query('UPDATE kbauto_bank SET '.$change.' WHERE rowid=81000');
    checkAuto(!correctionEntry()['can_move'], 'Native financial locks prevent inline correction');
    rejectAuto(static function () use ($entry) { correctEntry($entry); }, 'Stale unlocked evidence cannot override a native lock');
    $db->query('UPDATE kbauto_bank SET rappro=0,fk_bordereau=NULL WHERE rowid=81000');
}
$db->query("INSERT INTO kbauto_accounting_bookkeeping (rowid,doc_ref,fk_user_author,doc_date,doc_type,fk_doc,fk_docdet,numero_compte,label_compte,label_operation,debit,credit,montant,sens,code_journal,journal_label,piece_num,entity,date_creation) VALUES(80000,'SYNTHETIC',7,'2026-11-01','bank',81000,81000,'SYNTHETIC','Fixture','Fixture',0,231.47,231.47,'C','TEST','Fixture',80000,1,NOW())");
checkAuto(!correctionEntry()['can_move'], 'Native accounting dispatch blocks account changes');
rejectAuto(static function () use ($entry) { correctEntry($entry); }, 'Account correction cannot move a posted movement');
$db->query('DELETE FROM kbauto_accounting_bookkeeping WHERE rowid=80000');
$db->query("INSERT INTO kbauto_bank_url (fk_bank,url_id,url,label,type) VALUES(81000,81001,'/synthetic/','Transfer','banktransfer')");
checkAuto(!correctionEntry()['can_move'], 'Transfer and unsupported lifecycle links require native review');
$db->query("DELETE FROM kbauto_bank_url WHERE fk_bank=81000 AND type='banktransfer'");
foreach (array('fk_account=202','amount=231.47','amount=-300','amount=-231.48') as $change) {
    $db->query('UPDATE kbauto_bank SET '.$change.' WHERE rowid=81000');
    rejectAuto(static function () use ($entry) { correctEntry($entry); }, 'Changed owner, direction or amount rejects the reviewed correction');
    $db->query('UPDATE kbauto_bank SET fk_account=102,amount=-231.47 WHERE rowid=81000');
}
$db->query("UPDATE kbauto_bank SET fk_type='SOLD' WHERE rowid=81000");
checkAuto(correctionEntry()===null, 'Opening-balance movements cannot be moved through payment correction');
$db->query("UPDATE kbauto_bank SET fk_type=NULL WHERE rowid=81000");
$db->query('UPDATE kbauto_facture_fourn SET entity=2 WHERE rowid=80000');
checkAuto(correctionEntry()===null, 'Foreign invoice parent cannot leak through an owned bank movement');
$db->query('UPDATE kbauto_facture_fourn SET entity=1 WHERE rowid=80000');
$db->query("UPDATE kbauto_kreabank_statement_line SET payment_reference='CHANGED' WHERE rowid=80000");
rejectAuto(static function () use ($entry) { correctEntry($entry); }, 'Changed statement evidence invalidates display snapshot');
$db->query("UPDATE kbauto_kreabank_statement_line SET payment_reference='PAYMENT-80000-20261101' WHERE rowid=80000");
$policy=new KreaBankAccountPairs($db,1);
$policy->configure($user,array(102=>101));
checkAuto(correctionEntry()===null, 'Restricted transfer-only account cannot be moved by this workflow');
$policy->configure($user,array());
foreach (array('clos=1', "currency_code='USD'") as $change) {
    $db->query('UPDATE kbauto_bank_account SET '.$change.' WHERE rowid=101');
    checkAuto(correctionEntry()===null, 'Closed or currency-mismatched target is excluded');
    rejectAuto(static function () use ($entry) { correctEntry($entry); }, 'Account changes are revalidated before writing');
    $db->query("UPDATE kbauto_bank_account SET clos=0,currency_code='EUR' WHERE rowid=101");
}
$readOnlyEntry=$readOnlyService->searchOtherAccountMovements(80000,'PAY-80000');
checkAuto(count($readOnlyEntry)>0, 'Read-only access can inspect other owned-account movements');
rejectAuto(static function () use ($readOnlyService,$entry) { $readOnlyService->changeMovementAccount(80000,81000,$entry['snapshot'],102,101); }, 'Read-only access cannot correct an account');
$entry=correctionEntry();
foreach (array('UPDATE kbauto_bank SET fk_account','INSERT INTO kbauto_kreabank_recon_audit') as $failure) {
    $db->fail=$failure;
    rejectAuto(static function () use ($entry) { correctEntry($entry); }, 'Write failure rolls back account correction and audit');
    $db->fail='';
    checkAuto((int)$db->query('SELECT fk_account FROM kbauto_bank WHERE rowid=81000')->fetch_object()->fk_account===102
        && $counts===array(countAuto('bank'),countAuto('paiementfourn'),countAuto('kreabank_recon_audit')) && $db->depth===0, 'Failed correction leaves ledger, payment and audit unchanged');
}
$db->beforeBegin=static function () use ($db) { $db->query("UPDATE kbauto_bank SET dateo='2026-11-02' WHERE rowid=81000"); };
rejectAuto(static function () use ($entry) { correctEntry($entry); }, 'Concurrent mutation before locking invalidates reviewed facts');
$db->query("UPDATE kbauto_bank SET dateo='2026-11-01' WHERE rowid=81000");
$entry=correctionEntry();
$bankBefore=(array)$db->query('SELECT * FROM kbauto_bank WHERE rowid=81000')->fetch_object();
$payBefore=(array)$db->query('SELECT * FROM kbauto_paiementfourn WHERE rowid=80000')->fetch_object();
$allocBefore=$db->query('SELECT * FROM kbauto_paiementfourn_facturefourn WHERE fk_paiementfourn=80000')->fetch_all(MYSQLI_ASSOC);
checkAuto(correctEntry($entry)===true, 'Explicit correction moves the existing native movement');
$bankAfter=(array)$db->query('SELECT * FROM kbauto_bank WHERE rowid=81000')->fetch_object();
$bankAfter['fk_account']=$bankBefore['fk_account'];
unset($bankBefore['tms'],$bankAfter['tms']);
checkAuto($bankBefore===$bankAfter, 'Account correction preserves every other native movement field');
checkAuto($payBefore===(array)$db->query('SELECT * FROM kbauto_paiementfourn WHERE rowid=80000')->fetch_object()
    && $allocBefore===$db->query('SELECT * FROM kbauto_paiementfourn_facturefourn WHERE fk_paiementfourn=80000')->fetch_all(MYSQLI_ASSOC), 'Existing payment identity and invoice allocations remain unchanged');
checkAuto((int)$db->query('SELECT rappro FROM kbauto_bank WHERE rowid=81000')->fetch_object()->rappro===0
    && (int)$db->query('SELECT status FROM kbauto_kreabank_statement_line WHERE rowid=80000')->fetch_object()->status===0, 'Changing account performs no reconciliation');
checkAuto($service->searchOtherAccountMovements(80000,'PAY-80000')===array(), 'Corrected entry disappears from other-account results');
checkAuto($service->getCorrectedAccountMovement(80000,81000)['rowid']===81000, 'The corrected movement can be reloaded directly despite prior search or paging');
checkAuto(in_array(81000,array_column($service->getOpenDocuments(-1,300,'2026-11-01',7,80000,101,false),'rowid')), 'Corrected payment becomes an ordinary same-account candidate');
rejectAuto(static function () use ($entry) { correctEntry($entry); }, 'Retry cannot repeat an accepted account change');
checkAuto(countAuto('bank')===$counts[0] && countAuto('paiementfourn')===$counts[1]
    && countAuto('kreabank_recon_audit')===$counts[2]+1, 'One correction creates only its atomic audit');
echo 'OK: '.($checks-$initialChecks)." account correction database checks\n";
