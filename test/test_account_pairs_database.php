#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 * ONLY the disposable 127.0.0.1:33317/kreabank_auto_test fixture; never installation configuration.
 */
require __DIR__ . '/test_company_isolation_database.php';
$initialChecks = $checks;
function dol_trunc($value, $length, $mode = 'right', $charset = '', $dots = 1) { return substr($value,0,$length); }
class PairTestLangs extends AutoTestLangs {
	public function transnoentitiesnoconv($key) { return $key; }
	public function transnoentities($key) { return $key; }
}
$langs = new PairTestLangs();
$service = new KreaBankService($db,$user,$langs);
foreach (array('c_country', 'c_departements', 'accounting_journal') as $table) {
	$sql = preg_replace('/--[^\n]*/', '', file_get_contents(DOL_DOCUMENT_ROOT . '/install/mysql/tables/llx_' . $table . '.sql'));
	foreach (explode(';', str_replace('llx_', MAIN_DB_PREFIX, $sql)) as $statement) {
		if (trim($statement) !== '') { $db->query($statement); }
	}
}
$extrafields->attributes['bank_account'] = array('loaded' => 1, 'label' => array());
$policy = new KreaBankAccountPairs($db, 1);
$policy->configure($user, array(102 => 101));
checkAuto($policy->pairs() === array(102 => 101), 'Explicit restricted-to-main relationship persists in this company');
$jobCount = countAuto('cronjob');
$policy->configure($user, array(102 => 101));
checkAuto(countAuto('cronjob') === $jobCount, 'Saving pairs never creates or enables a scheduler');
foreach (array(array(102 => 102), array(102 => 202), array(102 => 101, 101 => 102)) as $badMap) {
	rejectAuto(static function () use ($policy, $user, $badMap) { $policy->configure($user, $badMap); }, 'Self-link, foreign ownership or cycle is rejected');
}
$policy->assertTransfer(102,101); $policy->assertTransfer(101,102);
rejectAuto(static function () use ($policy) { $policy->assertTransfer(102,202); }, 'Restricted outgoing transfer rejects other accounts');
rejectAuto(static function () use ($policy) { $policy->assertTransfer(202,102); }, 'Restricted incoming transfer rejects other accounts');
$policy->assertTransfer(101,202);
checkAuto(true, 'Main account retains its other native transfers');
rejectAuto(static function () use ($scope) { $scope->assertDocument('customer_invoice',29100,102); }, 'Restricted account cannot reconcile a customer invoice');
rejectAuto(static function () use ($scope) { $scope->assertDocument('native_bank',19101,102); }, 'A bare bank movement is not a linked transfer');
function pairFixture($id, $amount = '533.27', $reference = 'PAIR-202611010001') {
	stageAuto($id, '-' . $amount, $reference, 102);
	stageAuto($id + 1, $amount, $reference, 101);
}
pairFixture(40000);
$preview = $service->getAutomaticTransferSuggestion(40000, array(101,102));
checkAuto($preview !== null && $preview['bank_ids'] === array(0,0), 'Unique opposite statement legs with a strong reference propose a new native transfer');
checkAuto($service->getAutomaticTransferSuggestion(40000, array(102)) === null, 'Both endpoints must be selected for automatic processing');
$beforeBank = countAuto('bank'); $beforeLinks = countAuto('bank_url'); $beforeAudit = countAuto('kreabank_recon_audit');
foreach (array('INSERT INTO kbauto_bank_url', 'UPDATE kbauto_kreabank_statement_line', 'INSERT INTO kbauto_kreabank_recon_audit') as $failure) {
	$db->fail = $failure;
	rejectAuto(static function () use ($service) { $service->reconcileAutomaticTransferPair(40000, array(101,102)); }, 'Pair failure is visible: ' . $failure);
	$db->fail = '';
	checkAuto(countAuto('bank') === $beforeBank && countAuto('bank_url') === $beforeLinks && countAuto('kreabank_recon_audit') === $beforeAudit
		&& $service->getBulkReviewLine(40000,'staged')['status'] === 0 && $service->getBulkReviewLine(40001,'staged')['status'] === 0 && $db->depth === 0,
		'Both native legs, staging and audit roll back together');
}
checkAuto($service->reconcileAutomaticTransferPair(40000,array(101,102)), 'Both statement legs reconcile successfully');
checkAuto(countAuto('bank') === $beforeBank + 2 && countAuto('bank_url') === $beforeLinks + 2 && countAuto('kreabank_recon_audit') === $beforeAudit + 2,
	'One native transfer creates exactly two reciprocal links and two atomic audits');
checkAuto($service->getBulkReviewLine(40000,'staged')['status'] === 2 && $service->getBulkReviewLine(40001,'staged')['status'] === 2,
	'Both imported legs complete together');
checkAuto(!$service->reconcileAutomaticTransferPair(40001,array(101,102)) && countAuto('bank') === $beforeBank + 2, 'Opposite-side retry creates no duplicate movement');
pairFixture(40010,'534.27','TRANSFERENCIA');
checkAuto($service->getAutomaticTransferSuggestion(40010,array(101,102)) === null, 'Amount/date and generic transfer text cannot authorize automation');
pairFixture(40020,'535.27','PAIR-202611010020');
stageAuto(40022,'535.27','PAIR-202611010020',101);
checkAuto($service->getAutomaticTransferSuggestion(40020,array(101,102)) === null, 'Two equally strong opposite legs remain pending');
$db->query('UPDATE kbauto_kreabank_statement_line SET is_duplicate=1 WHERE rowid=40022');
checkAuto($service->getAutomaticTransferSuggestion(40020,array(101,102)) !== null, 'Duplicate evidence is never consumed as another transfer');
pairFixture(40030,'536.27','PAIR-202611010030');
stageAuto(40032,'-536.27','PAIR-202611010030',102);
checkAuto($service->getAutomaticTransferSuggestion(40030,array(101,102)) === null, 'Reverse competition prevents selecting the first same-side claim');
pairFixture(40040,'537.27','PAIR-202611010040');
bankAuto(49040,'-537.27','PAIR-202611010040',102);
checkAuto($service->getAutomaticTransferSuggestion(40040,array(101,102)) === null, 'A one-sided existing ledger movement prevents duplicate creation');
bankAuto(49041,'537.27','PAIR-202611010040',101);
$db->query("INSERT INTO kbauto_bank_url(fk_bank,url_id,url,label,type) VALUES (49040,49041,'/compta/bank/line.php?rowid=','(banktransfert)','banktransfert'),(49041,49040,'/compta/bank/line.php?rowid=','(banktransfert)','banktransfert')");
stageAuto(49040,'600.17','UNRELATED-49040',101); // Deliberate staged/native numeric collision.
$beforeBank = countAuto('bank');
checkAuto($service->reconcileAutomaticTransferPair(40040,array(101,102)) && countAuto('bank') === $beforeBank, 'Existing reciprocal native transfer is reused without duplicate entries');
checkAuto($service->getBulkReviewLine(49040,'staged')['status']===0, 'Native transfer IDs cannot complete an unrelated staging row with the same number');
pairFixture(40050,'538.27','');
$db->query("UPDATE kbauto_kreabank_statement_line SET counterparty_iban=CASE rowid WHEN 40050 THEN 'GB82WEST12345698765432' ELSE 'NL91ABNA0417164300' END WHERE rowid IN (40050,40051)");
checkAuto($service->getAutomaticTransferSuggestion(40050,array(101,102)) !== null, 'Two checksum-valid IBANs identify the configured opposite accounts');
$db->query("UPDATE kbauto_kreabank_statement_line SET counterparty_iban='DE89370400440532013000' WHERE rowid=40051");
checkAuto($service->getAutomaticTransferSuggestion(40050,array(101,102)) === null, 'Contradictory counterparty IBAN is never overridden');
pairFixture(40060,'539.27','PAIR-202611010060');
$db->query("UPDATE kbauto_kreabank_statement_line SET currency='USD' WHERE rowid=40061");
checkAuto($service->getAutomaticTransferSuggestion(40060,array(101,102)) === null, 'Foreign-currency pairs remain pending');
$denied = clone $user; $denied->allowed = false;
$deniedService = new KreaBankService($db,$denied,$langs);
rejectAuto(static function () use ($deniedService) { $deniedService->reconcileAutomaticTransferPair(40020,array(101,102)); }, 'Permission loss prevents pair execution');
// Multiple restricted accounts may share one main account; no names or production IDs are inferred.
$db->query("INSERT INTO kbauto_bank_account(rowid,entity,ref,label,currency_code,clos,courant,rappro,fk_pays) VALUES(103,1,'TEST D','Another restricted account','EUR',0,1,1,1)");
$policy->configure($user,array(102=>101,103=>101));
checkAuto($policy->counterparts(101) === array(102,103), 'One main account supports multiple explicitly configured restricted accounts');
stageAuto(40070,'-540.27','PAIR-202611010070',103); stageAuto(40071,'540.27','PAIR-202611010070',101);
checkAuto($service->getAutomaticTransferSuggestion(40070,array(101,103)) !== null, 'Another configured pair uses the same reconciliation path');
// The same company/account isolation checks still apply after relationship configuration.
$conf->entity=2;
rejectAuto(static function () use ($service) { $service->getAutomaticTransferSuggestion(40070,array(101,103)); }, 'Changed company cannot execute a previous company pair');
$conf->entity=1;
echo 'OK: ' . ($checks-$initialChecks) . " account-pair database checks\n";
// Stale configuration forms must not overwrite another administrator's changes.
rejectAuto(static function () use ($policy,$user) { $policy->configure($user,array(102=>101),'stale-revision'); }, 'Stale configuration updates are rejected');
checkAuto($policy->pairs() === array(102=>101,103=>101), 'Concurrent configuration survives a stale form');
// The native form is guarded even without KreaBank workspace read permission.
require_once __DIR__ . '/../class/actions_kreabank.class.php';
function GETPOSTINT($name) { return (int) ($GLOBALS['pairRequest'][$name] ?? 0); }
function GETPOSTISSET($name) { return isset($GLOBALS['pairRequest'][$name]); }
function GETPOST($name, $type = '') { return $GLOBALS['pairRequest'][$name] ?? ''; }
$hooks = new ActionsKreaBank($db); $object = null;
$_SERVER['PHP_SELF']='/compta/bank/transfer.php';
$pairRequest = array('1_account_from'=>102,'1_account_to'=>103);
$action='add';
checkAuto($hooks->doActions(array(),$object,$action,null) === -1 && $action === '', 'Native transfer hook stops an invalid pair before the native action block');
$pairRequest = array('1_account_from'=>102,'1_account_to'=>101);
$action='add';
checkAuto($hooks->doActions(array(),$object,$action,null) === 0 && $action === 'add', 'Native transfer between configured endpoints keeps its normal lifecycle');
// Quick-entry flows must reject before creating any financial objects.
$bankCount=countAuto('bank'); $quickCount=countAuto('kreabank_quick_entry');
rejectAuto(static function () use ($service) { $service->createQuickEntryAndReconcile(40020,'fee','Synthetic fee',535.27); }, 'Restricted account rejects quick entry before materialization');
checkAuto(countAuto('bank')===$bankCount && countAuto('kreabank_quick_entry')===$quickCount, 'Denied quick flow leaves bank and quick-entry data untouched');
// Scheduled preview must deduplicate the two sides and remain entirely read-only.
$mode=$auto->setting('MODE'); $cursor=$auto->setting('CURSOR');
$db->query("UPDATE kbauto_const SET value='101,102,103' WHERE name='KREABANK_AUTO_NATIVE_ACCOUNTS' AND entity=1");
dolibarr_set_const($db,'KREABANK_AUTO_NATIVE_PAIRS_ENABLED','1','chaine',0,'',1);
$db->query('UPDATE kbauto_kreabank_statement_line SET status=3 WHERE rowid<40070 AND status=0');
$beforeBank=countAuto('bank');$beforeAudit=countAuto('kreabank_recon_audit');
$preview=$auto->process($user,true);
checkAuto($preview['eligible']===1 && count($preview['matches'])===1 && $preview['matches'][0]['paired_statement_line_id']===40071,
	'One pair appears once in the existing scheduler preview');
checkAuto(countAuto('bank')===$beforeBank && countAuto('kreabank_recon_audit')===$beforeAudit && $auto->setting('CURSOR')===$cursor,
	'Pair preview creates no movement or audit and does not advance the scheduled cursor');
$db->query("UPDATE kbauto_const SET value='0' WHERE name='KREABANK_AUTO_NATIVE_CURSOR' AND entity=1");
$db->query("UPDATE kbauto_const SET value='enabled' WHERE name='KREABANK_AUTO_NATIVE_MODE' AND entity=1");
$result=$auto->process($user,false);
checkAuto($result['reconciled']===2 && $result['failed']===0, 'Existing company scheduler executes both transfer legs together');
checkAuto($auto->process($user,false)['reconciled']===0 && countAuto('bank')===$beforeBank+2, 'Repeated scheduled passes do not duplicate paired transfers');
echo 'OK: ' . ($checks-$initialChecks) . " total account-pair database checks\n";
// A preview is not permission to execute stale facts.
pairFixture(40080,'541.27','PAIR-202611010080');
checkAuto($service->getAutomaticTransferSuggestion(40080,array(101,102)) !== null, 'Preview identifies a new isolated transfer');
$db->beforeBegin = static function () use ($db) { $db->query('UPDATE kbauto_kreabank_statement_line SET amount=541.28 WHERE rowid=40081'); };
$beforeBank=countAuto('bank');
checkAuto(!$service->reconcileAutomaticTransferPair(40080,array(101,102)) && countAuto('bank')===$beforeBank, 'Execution rejects an amount changed after preview');
$db->query('UPDATE kbauto_kreabank_statement_line SET amount=541.27 WHERE rowid=40081');
// Independent connections cannot execute the same company pair simultaneously.
$otherDb = new DoliDB();
$otherDb->query('SET SESSION innodb_lock_wait_timeout=1');
$otherService = new KreaBankService($otherDb,$user,$langs);
$db->begin();
$db->query('SELECT rowid FROM kbauto_bank_account WHERE entity=1 AND rowid=102 FOR UPDATE');
rejectAuto(static function () use ($otherService) { $otherService->reconcileAutomaticTransferPair(40080,array(101,102)); }, 'Another connection cannot pass the execution account lock');
$db->rollback();
checkAuto($otherService->reconcileAutomaticTransferPair(40080,array(101,102)) && !$service->reconcileAutomaticTransferPair(40080,array(101,102)), 'After lock release only one connection can complete the pair');
checkAuto(countAuto('bank')===$beforeBank+2, 'Concurrent retries preserve exactly two native transfer legs');
echo 'OK: ' . ($checks-$initialChecks) . " total account-pair checks including concurrent connections\n";

// SQL windows and matcher tolerance agree across daylight-saving/server timezones.
pairFixture(40100,'542.27','PAIR-202611010100');
$db->query("UPDATE kbauto_kreabank_statement_line SET operation_date='2026-11-04',value_date='2026-11-04' WHERE rowid=40101");
$originalTimezone=date_default_timezone_get();
foreach (array('UTC','Europe/Lisbon','Europe/Zurich','America/New_York') as $timezone) {
	date_default_timezone_set($timezone);
	checkAuto($service->getAutomaticTransferSuggestion(40100,array(101,102)) !== null, 'Boundary-day pair is eligible in ' . $timezone);
}
date_default_timezone_set($originalTimezone);
echo 'OK: ' . ($checks-$initialChecks) . " total account-pair database checks\n";

// Foreign-currency pairs require an existing native transfer; never infer company-currency valuation.
$db->query("INSERT INTO kbauto_bank_account(rowid,entity,ref,label,currency_code,clos,courant,rappro,fk_pays) VALUES(104,1,'USD MAIN','Synthetic dollar main','USD',0,1,1,1),(105,1,'USD CREDIT','Synthetic dollar restricted','USD',0,1,1,1)");
$policy->configure($user,array(102=>101,103=>101,105=>104));
stageAuto(40200,'-543.27','PAIR-202611010200',105); stageAuto(40201,'543.27','PAIR-202611010200',104);
$db->query("UPDATE kbauto_kreabank_statement_line SET currency='USD' WHERE rowid IN(40200,40201)");
checkAuto($service->getAutomaticTransferSuggestion(40200,array(104,105))===null, 'Same foreign currency cannot create a transfer without native company-currency valuation');
rejectAuto(static function () use ($policy,$denied) { $policy->configure($denied,array(102=>101)); }, 'Configuration requires current write permissions');
echo 'OK: ' . ($checks-$initialChecks) . " total account-pair database checks\n";
