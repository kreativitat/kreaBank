#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 * ONLY the guarded disposable localhost:33317/kreabank_auto_test fixture.
 */
require __DIR__ . '/test_account_correction_database.php';
$initialTaxChecks = $checks;
// Enable only this test request, never an installation's module configuration.
// The base fixture's isModEnabled() consults this explicit synthetic opt-in.
$GLOBALS['taxFixtureEnabled'] = true;
foreach (array('c_paiement','c_country','c_departements','accounting_journal') as $table) {
    $sql = preg_replace('/--[^\n]*/', '', file_get_contents(DOL_DOCUMENT_ROOT . '/install/mysql/tables/llx_' . $table . '.sql'));
    foreach (explode(';', str_replace('llx_', MAIN_DB_PREFIX, $sql)) as $statement) {
        if (trim($statement) !== '') { $db->query($statement); }
    }
}
$db->query("INSERT INTO kbauto_c_paiement (id,entity,code,libelle,active) VALUES (1,1,'VIR','Fixture transfer',1)");
$db->query("INSERT INTO kbauto_c_chargesociales (id,libelle,active,code) VALUES (1,'Fixture tax',1,'TEST')");
function taxFixture($id, $amount = '125.50', $paid = 0, $entity = 1) {
    global $db;
    $db->query("INSERT INTO kbauto_chargesociales(rowid,entity,date_ech,libelle,fk_type,amount,paye,fk_mode_reglement,periode) VALUES ($id,$entity,'2026-11-01','Synthetic tax $id',1,$amount,$paid,1,'2026-11-01')");
}
function taxPaymentFixture($id, $taxId, $amount, $bank = 0) {
    global $db;
    $db->query("INSERT INTO kbauto_paiementcharge(rowid,fk_charge,datep,amount,fk_typepaiement,num_paiement,fk_bank) VALUES ($id,$taxId,'2026-11-01',$amount,1,'TAX-REFERENCE-$id',$bank)");
}
function taxReviewFixture($source, $type, $target) {
    global $service;
    $line = $service->getBulkReviewLine($source, 'staged');
    $rows = kreabankManualReviewCallbacks($service, 3, 100)['prepareDocumentReviews']($line);
    return $rows[$type . '__' . $target] ?? null;
}
function applyTaxReview($row, $actor = null) {
    global $service;
    return kreabankExecuteManualReview($actor ?: $service, $row);
}
require_once __DIR__ . '/../lib/kreabank_manual_review.lib.php';
// True native lifecycle classes are loaded by the feature. Supply only missing
// framework utilities/trigger registration, never mock tax/payment persistence.
function dol_trunc($value, $length, ...$args) { return mb_substr($value, 0, $length); }
function dol_sanitizeFileName($name, ...$args) { return $name; }
function dol_getIP(...$args) { return '127.0.0.1'; }
function getDolGlobalFloat($key, $default = 0) { return (float) ($GLOBALS['conf']->global->$key ?? $default); }
function getDolGlobalBool($key) { return (bool) getDolGlobalInt($key); }
function dol_include_once($file, ...$args) { return include_once DOL_DOCUMENT_ROOT . $file; }
function dol_buildpath($path, $absolute = 0) { return $path; }
class Conf extends stdClass {}
class Translate extends AutoTestLangs { public function transnoentitiesnoconv($key) { return $key; } }
$oldConf = $conf; $conf = new Conf();
foreach ((array) $oldConf as $key=>$value) { $conf->$key=$value; }
$conf->modules_parts=array('triggers'=>array());
function dol_osencode($value) { return $value; }
$langs = new Translate();
$service = new KreaBankService($db,$user,$langs);
$extrafields->attributes['bank_account'] = array('loaded'=>1,'label'=>array());
$conf->modules = array();
$conf->file = (object) array('dol_document_root'=>array(DOL_DOCUMENT_ROOT));
$conf->societe = (object) array('enabled'=>0);
$conf->notification = (object) array('enabled'=>0);
$conf->global->MAIN_ROUNDING_RULE_TOT = 0;
// New payment against an existing tax: no new tax/invoice; source is reconciled.
taxFixture(90000); stageAuto(90000, '-125.50', '');
$row = taxReviewFixture(90000, 'tax', 90000);
checkAuto($row && $row['suggestion']['amount_open'] === -125.5, 'Existing unpaid tax is a signed debit candidate');
checkAuto($service->getSafeSuggestion(array_merge(array($row['suggestion']), array()), 100) === null, 'A tax payment is never unattended');
$beforeTax = array(countAuto('chargesociales'), countAuto('paiementcharge'), countAuto('bank'), countAuto('kreabank_recon_audit'));
checkAuto(applyTaxReview($row) === 90000, 'Review records a payment against the existing tax');
checkAuto(countAuto('chargesociales') === $beforeTax[0] && countAuto('paiementcharge') === $beforeTax[1]+1 && countAuto('bank') === $beforeTax[2]+1, 'Creates one payment and one native movement, no duplicate tax');
checkAuto((int) $db->query('SELECT paye FROM kbauto_chargesociales WHERE rowid=90000')->fetch_object()->paye === 1, 'Native lifecycle closes a fully paid tax');
checkAuto($service->getBulkReviewLine(90000,'staged')['status'] === 2 && countAuto('kreabank_recon_audit') === $beforeTax[3]+1, 'Payment, native reconciliation, staging and audit commit together');
rejectAuto(static function () use ($row) { applyTaxReview($row); }, 'Duplicate reviewed submission cannot create another tax payment');
// Existing unbanked payment is reused, even when its tax is already paid.
taxFixture(90001,'63.25',1); taxPaymentFixture(91001,90001,'63.25'); stageAuto(90001,'-63.25','');
$row = taxReviewFixture(90001,'payment_tax',91001);
checkAuto($row !== null, 'Unbanked tax payment is offered independently of the tax paid status');
$before = countAuto('paiementcharge');
checkAuto(applyTaxReview($row) === 90001 && countAuto('paiementcharge') === $before, 'Attaching an existing tax payment creates no payment');
checkAuto((int) $db->query('SELECT fk_bank FROM kbauto_paiementcharge WHERE rowid=91001')->fetch_object()->fk_bank > 0, 'Existing tax payment points at the reconciled native movement');
// Exact remaining balance, partial payments and paid/excess exclusion.
taxFixture(90002,'200'); taxPaymentFixture(91002,90002,'75'); stageAuto(90002,'-125','');
$row = taxReviewFixture(90002,'tax',90002);
checkAuto($row && $row['suggestion']['amount_open'] === -125.0, 'Tax candidates subtract all previous payments');
$db->beforeBegin = static function () use ($db) { $db->query('UPDATE kbauto_chargesociales SET amount=201 WHERE rowid=90002'); };
rejectAuto(static function () use ($row) { applyTaxReview($row); }, 'Tax edits after display are rejected under the tax lock');
$db->query('UPDATE kbauto_chargesociales SET amount=200 WHERE rowid=90002');
// Rollback payment creation, native paid state, links, mapping and audit.
$row = taxReviewFixture(90002,'tax',90002);
$before = array(countAuto('paiementcharge'),countAuto('bank'),countAuto('bank_url'),countAuto('kreabank_recon_audit'));
foreach (array('UPDATE kbauto_chargesociales SET paye', 'INSERT INTO kbauto_paiementcharge','INSERT INTO kbauto_bank_url','INSERT INTO kbauto_kreabank_recon_audit','UPDATE kbauto_kreabank_statement_line') as $failure) {
    $db->fail=$failure;
    rejectAuto(static function () use ($row) { applyTaxReview($row); }, 'Tax write failure rolls back: '.$failure);
    $db->fail='';
    checkAuto($before === array(countAuto('paiementcharge'),countAuto('bank'),countAuto('bank_url'),countAuto('kreabank_recon_audit')) && $db->depth===0
        && (int) $db->query('SELECT paye FROM kbauto_chargesociales WHERE rowid=90002')->fetch_object()->paye===0
        && $service->getBulkReviewLine(90002,'staged')['status']===0, 'Failed tax reconciliation preserves all financial state');
}
// Source may pay only part of a larger existing tax.
stageAuto(90003,'-25','TAX-90003'); taxFixture(90003,'100');
$db->query("UPDATE kbauto_chargesociales SET ref='TAX-90003' WHERE rowid=90003");
checkAuto(applyTaxReview(taxReviewFixture(90003,'tax',90003))===90003
    && (int)$db->query('SELECT paye FROM kbauto_chargesociales WHERE rowid=90003')->fetch_object()->paye===0, 'Native partial tax payment leaves tax open');
// Parent ownership is mandatory even with an owned bank account.
taxFixture(90004,'125.50',0,2);
$scope=new KreaBankReconciliationScope($db,1);
rejectAuto(static function () use ($scope) { $scope->assertDocument('tax',90004,101); }, 'Foreign tax is denied');
taxPaymentFixture(91004,90004,'125.50');
rejectAuto(static function () use ($scope) { $scope->assertDocument('payment_tax',91004,101); }, 'Tax payment inherits its parent company');
// Already banked tax from another owned account: review, correction, then reuse.
taxFixture(90005,'53.29',1); bankAuto(92005,'-53.29','',102); taxPaymentFixture(95005,90005,'53.29',92005);
$db->query("INSERT INTO kbauto_bank_url(fk_bank,url_id,url,label,type) VALUES (92005,95005,'/compta/payment_sc/card.php?id=','Payment','payment_sc'),(92005,90005,'/compta/sociales/card.php?id=','Synthetic tax 90005','sc')");
stageAuto(90005,'-53.29','');
$db->query("UPDATE kbauto_kreabank_statement_line SET description='Pag. DUC 123456789012345' WHERE rowid=90005");
$warning=$service->getPaymentAccountReview(90005,array(101));
checkAuto($warning && $warning['candidates'][0]['payment_type']==='payment_tax', 'DUC debit flags a same-amount/date tax payment in another owned account');
checkAuto($service->getAutomaticNativeSuggestion(90005,array(101))===null && !taxReviewFixture(90005,'tax',90005), 'Wrong-account tax cannot trigger unattended or duplicate payment approval');
$entry=$service->searchOtherAccountMovements(90005,'Synthetic tax 90005')[0] ?? null;
checkAuto($entry && $entry['can_move'] && strpos($entry['tax_docs'],'90005')!==false, 'Tax references are searchable and eligible for reviewed account correction');
$paymentBefore=(array)$db->query('SELECT * FROM kbauto_paiementcharge WHERE rowid=95005')->fetch_object();
$taxBefore=(array)$db->query('SELECT * FROM kbauto_chargesociales WHERE rowid=90005')->fetch_object();
checkAuto($service->changeMovementAccount(90005,92005,$entry['snapshot'],102,101), 'Reviewed correction moves the native tax movement');
checkAuto($paymentBefore===(array)$db->query('SELECT * FROM kbauto_paiementcharge WHERE rowid=95005')->fetch_object()
    && $taxBefore===(array)$db->query('SELECT * FROM kbauto_chargesociales WHERE rowid=90005')->fetch_object(), 'Correction preserves tax and payment identities and amounts');
checkAuto($service->getPaymentAccountReview(90005,array(101))===null, 'Tax account warning is recomputed after correction');
$row=taxReviewFixture(90005,'native_bank',92005);
// The correction response renders this targeted reload; confirmation recomputes
// the ordinary candidate. Their exact displayed/signed facts must agree.
$corrected=$service->getCorrectedAccountMovement(90005,92005);
$renderedRow=$row;
foreach (array('ref'=>'doc_ref','doc_date'=>'doc_date','thirdparty_name'=>'thirdparty_name','amount_open'=>'amount_open') as $displayKey=>$reviewKey) {
    $renderedRow['suggestion'][$reviewKey]=$corrected[$displayKey];
}
$expires=dol_now()+900;$scope='1|7|101';$secret=str_repeat('tax-review-secret-',3);
$signature=kreabankBulkReviewSignature($renderedRow,$scope,$expires,$secret);
$freshRow=taxReviewFixture(90005,'native_bank',92005);
checkAuto(kreabankBulkReviewIsValid($freshRow,$scope,$expires,$signature,$secret,dol_now(),true,101),
    'First approval after account correction authenticates the exact displayed tax movement');
$changedRow=$freshRow;$changedRow['suggestion']['thirdparty_name'].=' changed';
checkAuto(!kreabankBulkReviewIsValid($changedRow,$scope,$expires,$signature,$secret,dol_now(),true,101),
    'Changed tax display evidence still invalidates the signed review');
$before=array(countAuto('bank'),countAuto('paiementcharge'));
checkAuto($row && applyTaxReview($row)===90005 && $before===array(countAuto('bank'),countAuto('paiementcharge')), 'Reconcile corrected tax movement without creating a payment or movement');
// Currency, permissions, inactive modes and paid state fail closed.
stageAuto(90006,'-10','');taxFixture(90006,'10');
$row=taxReviewFixture(90006,'tax',90006);
$db->query('UPDATE kbauto_c_paiement SET active=0 WHERE id=1');
rejectAuto(static function () use($row){applyTaxReview($row);}, 'Inactive configured payment mode is rejected');
$db->query('UPDATE kbauto_c_paiement SET active=1 WHERE id=1');
$db->query("UPDATE kbauto_kreabank_statement_line SET currency='USD' WHERE rowid=90006");
rejectAuto(static function () use($row){applyTaxReview($row);}, 'Changed source currency is rejected');
$db->query("UPDATE kbauto_kreabank_statement_line SET currency='EUR' WHERE rowid=90006");
$denied=new class extends User { public function hasRight($module,$right,$subright='') { return $module!=='tax' || $subright!=='creer'; } };
$deniedService=new KreaBankService($db,$denied,$langs);
rejectAuto(static function () use($row,$deniedService){applyTaxReview($row,$deniedService);}, 'Native tax write rights are required');
rejectAuto(static function () use($service){$service->reconcileLine(90006,array(array('doc_type'=>'tax','fk_doc'=>90006,'allocated_amount'=>10)));}, 'Unsigned tax execution is rejected');
// Parent edits, stale payments and owner isolation are checked after locks.
taxFixture(90007,'27',1);taxPaymentFixture(96007,90007,'27');stageAuto(90007,'-27','');
$row=taxReviewFixture(90007,'payment_tax',96007);
taxFixture(90008,'27',1);
$db->query("UPDATE kbauto_chargesociales SET libelle='Synthetic tax 90007' WHERE rowid=90008");
$db->beforeBegin=static function() use($db){$db->query('UPDATE kbauto_paiementcharge SET fk_charge=90008 WHERE rowid=96007');};
rejectAuto(static function() use($row){applyTaxReview($row);}, 'Changed same-company tax parent invalidates a reviewed payment');
$db->query('UPDATE kbauto_paiementcharge SET fk_charge=90007 WHERE rowid=96007');
$row=taxReviewFixture(90007,'payment_tax',96007);
$db->beforeBegin=static function() use($db){$db->query('UPDATE kbauto_paiementcharge SET fk_bank=92005 WHERE rowid=96007');};
rejectAuto(static function() use($row){applyTaxReview($row);}, 'A concurrently banked payment cannot be attached twice');
$db->query('UPDATE kbauto_paiementcharge SET fk_bank=0 WHERE rowid=96007');
$docs=$service->getOpenDocuments(-1,300,'2026-11-01',3,90007,101,false);
checkAuto(!array_filter($docs,static function($doc){return $doc['doc_type']==='tax' && $doc['rowid']===90004;}), 'Candidate lookup excludes another company tax');
$creditDocs=$service->getOpenDocuments(1,300,'2026-11-01',3,0,101,false);
checkAuto(!array_filter($creditDocs,static function($doc){return in_array($doc['doc_type'],array('tax','payment_tax'),true);}), 'Credits never offer ordinary tax payments');
$deniedSuggestions=$deniedService->getSuggestionsForLines(array($service->getBulkReviewLine(90006,'staged')))[90006];
checkAuto(!array_filter($deniedSuggestions,static function($doc){return in_array($doc['doc_type'],array('tax','payment_tax'),true);}), 'Tax write denial produces no executable tax suggestion');
$db->query("UPDATE kbauto_bank_account SET currency_code='USD' WHERE rowid=101");
$foreignCurrencyDocs=$service->getOpenDocuments(-1,300,'2026-11-01',3,0,101,false);
checkAuto(!array_filter($foreignCurrencyDocs,static function($doc){return in_array($doc['doc_type'],array('tax','payment_tax'),true);}), 'Foreign-currency accounts do not offer company-currency taxes');
$db->query("UPDATE kbauto_bank_account SET currency_code='EUR' WHERE rowid=101");
// Scope checks cannot trust a tax's bank URL or a payment without a valid parent.
bankAuto(97001,'-27','',101);taxPaymentFixture(98001,90004,'27',97001);
rejectAuto(static function() use($scope){$scope->assertDocument('native_bank',97001,101);}, 'Owned movement cannot conceal a foreign tax payment');
$db->query('DELETE FROM kbauto_paiementcharge WHERE rowid=98001');
$db->query("INSERT INTO kbauto_bank_url(fk_bank,url_id,url,label,type) VALUES(97001,90004,'/synthetic/','Foreign','sc')");
rejectAuto(static function() use($scope){$scope->assertDocument('native_bank',97001,101);}, 'Foreign tax URL cannot cross company boundary');
// Correction includes all tax facts in its snapshot and preserves lock rules.
taxFixture(90009,'42.12',1);bankAuto(97009,'-42.12','',102);taxPaymentFixture(98009,90009,'42.12',97009);stageAuto(90009,'-42.12','');
$entry=$service->searchOtherAccountMovements(90009,'',97009)[0];
$db->query('UPDATE kbauto_paiementcharge SET amount=42.13 WHERE rowid=98009');
rejectAuto(static function() use($service,$entry){$service->changeMovementAccount(90009,97009,$entry['snapshot'],102,101);}, 'Tax payment amount changes invalidate reviewed correction');
$db->query('UPDATE kbauto_paiementcharge SET amount=42.12 WHERE rowid=98009');
foreach(array('rappro=1','fk_bordereau=5') as $change){
    $db->query('UPDATE kbauto_bank SET '.$change.' WHERE rowid=97009');
    checkAuto(!$service->searchOtherAccountMovements(90009,'',97009)[0]['can_move'], 'Tax account correction respects native financial locks');
    $db->query('UPDATE kbauto_bank SET rappro=0,fk_bordereau=NULL WHERE rowid=97009');
}
$entry=$service->searchOtherAccountMovements(90009,'',97009)[0];
$db->fail='INSERT INTO kbauto_kreabank_recon_audit';
rejectAuto(static function() use($service,$entry){$service->changeMovementAccount(90009,97009,$entry['snapshot'],102,101);}, 'Tax account correction audit failure is visible');
$db->fail='';
checkAuto((int)$db->query('SELECT fk_account FROM kbauto_bank WHERE rowid=97009')->fetch_object()->fk_account===102, 'Failed tax correction rolls back the account');
// The configured method drives both newly created native records.
$db->query("INSERT INTO kbauto_c_paiement(id,entity,code,libelle,active) VALUES(2,1,'MB','Fixture multibanco',1)");
taxFixture(99001,'11.23');stageAuto(99001,'-11.23','');
$db->query('UPDATE kbauto_chargesociales SET fk_mode_reglement=2 WHERE rowid=99001');
checkAuto(applyTaxReview(taxReviewFixture(99001,'tax',99001))===99001, 'Explicit configured tax payment method is supported');
$method=$db->query('SELECT pc.fk_typepaiement,b.fk_type FROM kbauto_paiementcharge pc JOIN kbauto_bank b ON b.rowid=pc.fk_bank WHERE pc.fk_charge=99001')->fetch_object();
checkAuto((int)$method->fk_typepaiement===2 && $method->fk_type==='MB', 'Native tax payment and new bank movement keep the same explicit method');
// A separate claimant holds the tax lock: the complete losing transaction rolls back.
taxFixture(99002,'13.57');stageAuto(99002,'-13.57','');
$row=taxReviewFixture(99002,'tax',99002);
$contender=new mysqli('127.0.0.1','root','','kreabank_auto_test',33317);
$contender->begin_transaction();
$contender->query('SELECT rowid FROM kbauto_chargesociales WHERE rowid=99002 FOR UPDATE');
$db->query('SET SESSION innodb_lock_wait_timeout=1');
$before=array(countAuto('bank'),countAuto('paiementcharge'),countAuto('kreabank_recon_audit'));
rejectAuto(static function() use($row){applyTaxReview($row);}, 'A competing tax lock prevents concurrent payment creation');
$contender->rollback();$contender->close();
checkAuto($before===array(countAuto('bank'),countAuto('paiementcharge'),countAuto('kreabank_recon_audit'))
    && $service->getBulkReviewLine(99002,'staged')['status']===0, 'Losing concurrent tax claim leaves no financial or audit writes');
echo 'OK: '.($checks-$initialTaxChecks)." tax reconciliation database checks\n";
