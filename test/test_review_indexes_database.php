#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Extends ONLY the guarded disposable 127.0.0.1:33317/kreabank_auto_test fixture.
 */
require __DIR__ . '/test_payment_account_review_database.php';
require __DIR__ . '/../class/KreaBankReviewIndexes.class.php';
$initialChecks = $checks;
$maintenance = new KreaBankReviewIndexes($db);
$capture = static function () use ($db, $user, $langs) {
	$service = new KreaBankService($db, $user, $langs);
	$result = array();
	foreach (array('paiement', 'paiementfourn', 'bank', 'facture', 'facture_fourn', 'kreabank_recon_audit') as $table) {
		$result[$table] = $db->query('SELECT * FROM kbauto_' . $table . ' ORDER BY rowid')->fetch_all(MYSQLI_ASSOC);
	}
	$result['linked'] = $service->getLinkedPaymentDocuments(0,150,'2026-11-01',365,231.47,101,false);
	$result['open'] = $service->getOpenDocuments(0,150,'2026-11-01',365,0,101,false);
	$result['review'] = $service->getPaymentAccountReview(70000,array(101));
	return $result;
};
$before = $capture();
checkAuto(count($maintenance->plan())===4 && count(array_filter($maintenance->plan(), static function ($entry) { return $entry['existing']===null; }))===4,'Plan finds the four missing indexes without applying DDL');
$events = array();
$wait = $db->query('SELECT @@SESSION.lock_wait_timeout AS timeout')->fetch_object()->timeout;
$result = $maintenance->apply(static function ($status,$plan,$entry) use (&$events) { $events[]=$status; });
checkAuto($events===array('planned','applying','applied','applying','applied','applying','applied','applying','applied','complete'),'Every online index is journaled before and after DDL');
checkAuto(count(array_filter($result,static function ($entry) { return $entry['existing']!==null; }))===4,'All required indexes are verified after application');
checkAuto($capture()===$before,'Indexes preserve payment, invoice, bank and audit rows and matching/account-review results');
checkAuto($db->query('SELECT @@SESSION.lock_wait_timeout AS timeout')->fetch_object()->timeout===$wait,'Session metadata lock timeout is restored');
$events=array();
$maintenance->apply(static function ($status) use (&$events) { $events[]=$status; });
checkAuto($events===array('planned','complete'),'Repeated maintenance reuses existing indexes without DDL');
$db->query('ALTER TABLE kbauto_paiement DROP INDEX idx_kb_payment_scope_date, ADD INDEX existing_native_payment_scope (entity,datep,fk_bank,rowid)');
checkAuto($maintenance->plan()[0]['existing']==='existing_native_payment_scope','Equivalent native indexes with a longer full left prefix are reused');
$db->query('ALTER TABLE kbauto_paiement ADD INDEX idx_kb_payment_scope_date (amount)');
rejectAuto(static function () use ($maintenance) { $maintenance->plan(); },'Conflicting module-owned index definitions fail closed');
$db->query('ALTER TABLE kbauto_paiement DROP INDEX idx_kb_payment_scope_date');
$db->query("UPDATE kbauto_cronjob SET processing=1 WHERE module_name='kreabank'");
// The fixture may have no jobs; create one synthetic processing record explicitly.
$db->query("INSERT INTO kbauto_cronjob (label,jobtype,module_name,processing) VALUES ('Synthetic index guard','method','kreabank',1)");
rejectAuto(static function () use ($maintenance) { $maintenance->apply(static function () {}); },'Active KreaBank jobs block online maintenance');
$db->query("DELETE FROM kbauto_cronjob WHERE label='Synthetic index guard'");
$db->query("UPDATE kbauto_cronjob SET processing=0 WHERE module_name='kreabank'");
$db->query('ALTER TABLE kbauto_paiementfourn DROP INDEX idx_kb_payment_scope_date');
rejectAuto(static function () use ($maintenance) {
	$maintenance->apply(static function ($status) { if ($status==='applying') throw new RuntimeException('Synthetic journal failure'); });
},'A journal failure before DDL stops maintenance');
checkAuto($maintenance->plan()[2]['existing']===null,'Failed journaling never applies the pending index');
$db->fail='ALTER TABLE';
rejectAuto(static function () use ($maintenance) { $maintenance->apply(static function () {}); },'Rejected online DDL is reported without retry or a blocking copy fallback');
$db->fail='';
checkAuto($maintenance->plan()[2]['existing']===null,'A failed ALTER remains available for an explicitly inspected rerun');
$maintenance->apply(static function () {});
foreach ($maintenance->plan() as $entry) {
	if ($entry['existing']===$entry['index']) { $db->query($entry['rollback']); }
}
checkAuto($capture()===$before,'Dropping only maintenance-owned indexes preserves all data and matching results');
echo 'OK: ' . ($checks-$initialChecks) . " payment index maintenance database checks\n";
