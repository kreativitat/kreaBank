#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Extends ONLY the disposable 127.0.0.1:33317/kreabank_auto_test fixture.
 */
require __DIR__ . '/test_automatic_reconciliation_database.php';
require_once __DIR__ . '/../class/KreaBankOpeningBalance.class.php';
$initialChecks = $checks;
$opening = new KreaBankOpeningBalance($db, $user, 1);
checkAuto(!$opening->available() && $opening->get(101) === null && $opening->all() === array(), 'Reading an old installation never creates opening schema');
$opening->install(); $opening->install();
checkAuto($opening->available() && countAuto('kreabank_opening_balance') === 0, 'Explicit opening-schema installation is idempotent and performs no financial writes');
foreach (array(103,104,105,106,107,108,109,110) as $account) {
	$db->query("INSERT INTO kbauto_bank_account (rowid,entity,ref,label,currency_code,clos,courant,rappro,fk_pays) VALUES ($account,1,'OPEN-$account','Synthetic opening','EUR',0,1,1,1)");
}
function openingState($account = 103)
{
	global $db;
	return array('bank'=>$db->query("SELECT rowid,CAST(amount AS DECIMAL(24,8)) amount,rappro,num_releve,fk_user_rappro FROM kbauto_bank WHERE fk_account=$account ORDER BY rowid")->fetch_all(MYSQLI_ASSOC),
		'openings'=>countAuto('kreabank_opening_balance'),'audit'=>countAuto('kreabank_recon_audit'),
		'sources'=>$db->query("SELECT l.* FROM kbauto_kreabank_statement_line l JOIN kbauto_kreabank_statement s ON s.rowid=l.fk_statement WHERE s.bank_account_id=$account ORDER BY l.rowid")->fetch_all(MYSQLI_ASSOC));
}
bankAuto(970001,'100.00','BEFORE-A',103,'2026-10-30');
bankAuto(970002,'-20.00','BEFORE-B',103,'2026-10-31');
bankAuto(970003,'30.00','EXISTING-MATCH',103,'2026-10-31');
$db->query("UPDATE kbauto_bank SET rappro=1,num_releve='EXISTING-OCT',fk_user_rappro=77 WHERE rowid=970003");
bankAuto(970004,'500.00','ON-CUTOFF',103,'2026-11-01');
stageAuto(971001,'12.34','UNMATCHED-HISTORICAL',103);
$db->query("UPDATE kbauto_kreabank_statement_line SET operation_date='2026-10-31' WHERE rowid=971001");
stageAuto(971002,'500.00','ON-CUTOFF',103);
$before = openingState();
$review = $opening->preview(103,'2026-11-01','125.12','OPEN-20261101','Synthetic booked bank statement closing 31 October');
checkAuto(openingState() === $before, 'Preview performs no reconciliation, native movement, audit or source write');
checkAuto($review['ledger_balance'] === '110' && $review['opening_balance'] === '125.12' && $review['adjustment_amount'] === '15.12'
	&& $review['native_count'] === 3 && $review['native_unreconciled_count'] === 2 && $review['staged_count'] === 1 && $review['balance_date'] === '2026-10-31',
	'Preview excludes cutoff-day movements and calculates only the exact opening difference');
foreach (array('clos'=>1,'rappro'=>0,'courant'=>2) as $field=>$badValue) {
	$db->query("UPDATE kbauto_bank_account SET $field=$badValue WHERE rowid=103");
	rejectAuto(static function () use ($opening) { $opening->preview(103,'2026-11-01','125.12','OPEN-INELIGIBLE','Synthetic unsupported account'); }, 'Server-side setup rejects closed, unreconcilable and cash accounts');
	$originalValue = $field === 'clos' ? 0 : 1;
	$db->query("UPDATE kbauto_bank_account SET $field=$originalValue WHERE rowid=103");
}
rejectAuto(static function () use ($opening) { $opening->preview(202,'2026-11-01','0','OPEN-20261101','Other company'); }, 'A shared or other-company account is never available to setup');
$denied = clone $user; $denied->admin = 0;
rejectAuto(static function () use ($db,$denied) { (new KreaBankOpeningBalance($db,$denied,1))->preview(103,'2026-11-01','0','OPEN-20261101','Synthetic evidence'); }, 'Only an administrator with current financial permissions can prepare an opening');
$denied->admin = 1; $denied->allowed = false;
rejectAuto(static function () use ($db,$denied) { (new KreaBankOpeningBalance($db,$denied,1))->install(); }, 'Administrator status cannot replace native and module financial rights');
foreach (array('actor_id'=>8,'entity'=>2,'expires_at'=>time()-1,'facts_hash'=>str_repeat('0',64)) as $key=>$value) {
	$bad = $review; $bad[$key] = $value;
	rejectAuto(static function () use ($opening,$bad) { $opening->apply($bad); }, 'Reject altered, expired or differently scoped opening reviews');
}
checkAuto(openingState() === $before && $db->depth === 0, 'Rejected opening reviews leave all records unchanged');
$db->beforeBegin = static function () use ($db) { $db->query('UPDATE kbauto_bank SET amount=101 WHERE rowid=970001'); };
rejectAuto(static function () use ($opening,$review) { $opening->apply($review); }, 'Execution rejects a native movement changed after the displayed preview');
checkAuto($db->depth === 0 && countAuto('kreabank_opening_balance') === 0, 'A stale preview cannot create an opening or leak a transaction');
$db->query('UPDATE kbauto_bank SET amount=100 WHERE rowid=970001');
foreach (array('UPDATE kbauto_bank SET','INSERT INTO kbauto_kreabank_opening_balance','INSERT INTO kbauto_kreabank_recon_audit') as $failure) {
	$db->fail = $failure;
	rejectAuto(static function () use ($opening,$review) { $opening->apply($review); }, 'A native, opening-record or audit failure is visible');
	$db->fail = '';
	checkAuto(openingState() === $before && $db->depth === 0, 'Native flags, opening adjustment, sources and audit roll back together');
}
$payments = array(countAuto('paiement'),countAuto('paiementfourn'),countAuto('paiementcharge'),countAuto('bank_url'));
$applied = $opening->apply($review);
checkAuto($applied['start_date'] === '2026-11-01' && $applied['currency'] === 'EUR' && KreaBankOpeningBalance::decimal($applied['opening_balance']) === '125.12', 'The reviewed opening is persisted for its exact account and currency');
$adjustment = $db->query('SELECT * FROM kbauto_bank WHERE rowid=' . (int) $applied['fk_adjustment_bank_line'])->fetch_object();
checkAuto($adjustment && KreaBankOpeningBalance::decimal((string)$adjustment->amount) === '15.12' && $adjustment->fk_type === 'SOLD'
	&& $adjustment->dateo === '2026-10-31' && (int)$adjustment->rappro === 1 && $adjustment->num_releve === 'OPEN-20261101', 'The native opening entry posts only the reviewed difference before the cutoff');
$old = $db->query('SELECT rappro,num_releve,fk_user_rappro FROM kbauto_bank WHERE rowid=970003')->fetch_object();
checkAuto((int)$old->rappro === 1 && $old->num_releve === 'EXISTING-OCT' && (int)$old->fk_user_rappro === 77, 'Previously reconciled native statement references and actors remain intact');
$rows = $db->query('SELECT rowid,rappro,num_releve FROM kbauto_bank WHERE rowid IN (970001,970002,970004) ORDER BY rowid')->fetch_all(MYSQLI_ASSOC);
checkAuto((int)$rows[0]['rappro'] === 1 && (int)$rows[1]['rappro'] === 1 && (int)$rows[2]['rappro'] === 0 && $rows[0]['num_releve'] === 'OPEN-20261101', 'Only historical unreconciled native rows receive the migration statement');
checkAuto(array(countAuto('paiement'),countAuto('paiementfourn'),countAuto('paiementcharge'),countAuto('bank_url')) === $payments
	&& openingState()['sources'] === $before['sources'], 'Opening reconciliation creates no payment, invoice allocation, fabricated mapping or source rewrite');
$sum = $db->query("SELECT SUM(CAST(amount AS DECIMAL(24,8))) amount FROM kbauto_bank WHERE fk_account=103 AND dateo<'2026-11-01'")->fetch_object();
checkAuto(KreaBankOpeningBalance::decimal($sum->amount) === '125.12', 'The complete native pre-cutoff balance equals the confirmed bank opening');
$payload = json_decode($db->query("SELECT payload_json FROM kbauto_kreabank_recon_audit WHERE audit_type='opening_reconciliation' ORDER BY rowid DESC LIMIT 1")->fetch_object()->payload_json,true);
checkAuto($payload['native_rows'][2]['num_releve'] === 'EXISTING-OCT' && $payload['staged_count'] === 1 && $payload['evidence_ref'] === $review['evidence_ref'], 'The durable audit preserves historical flags, exact evidence and the reviewed authority');
$done = openingState();
checkAuto((int)$opening->apply($review)['rowid'] === (int)$applied['rowid'] && openingState() === $done, 'A repeated confirmation is idempotent and creates no second adjustment');
rejectAuto(static function () use ($opening) { $opening->preview(103,'2026-11-02','125.12','OTHER','Replacement'); }, 'An applied opening cannot be silently replaced');
$historical = KreaBankOpeningBalance::historicalSql($db,1,'s.bank_account_id','l.operation_date','l.currency');
$states = $db->query("SELECT l.rowid,($historical) historical FROM kbauto_kreabank_statement_line l JOIN kbauto_kreabank_statement s ON s.rowid=l.fk_statement AND s.entity=l.entity WHERE s.bank_account_id=103 ORDER BY l.rowid")->fetch_all(MYSQLI_ASSOC);
checkAuto((int)$states[0]['historical'] === 1 && (int)$states[1]['historical'] === 0, 'Persisted migration state covers only earlier booking dates without fabricated links');
stageAuto(971003,'9.00','LATE-HISTORICAL',103);
$db->query("UPDATE kbauto_kreabank_statement_line SET operation_date='2026-10-30' WHERE rowid=971003");
checkAuto((int)$db->query("SELECT ($historical) historical FROM kbauto_kreabank_statement_line l JOIN kbauto_kreabank_statement s ON s.rowid=l.fk_statement AND s.entity=l.entity WHERE l.rowid=971003")->fetch_object()->historical === 1, 'A later import retains the established historical cutoff');
bankAuto(972001,'10.00000001','ZERO-DELTA',104,'2026-10-31');
$zero = $opening->preview(104,'2026-11-01','10.00000001','OPEN-ZERO','Synthetic zero-difference evidence');
$count = countAuto('bank');
$zeroApplied = $opening->apply($zero);
checkAuto($zeroApplied['fk_adjustment_bank_line'] === null && countAuto('bank') === $count, 'An already correct balance creates no zero or duplicate opening movement');
$precision = $opening->preview(109,'2026-11-01','1000000000.00000001','OPEN-PRECISION','Synthetic precision boundary');
$precisionState = openingState(109);
rejectAuto(static function () use ($opening,$precision) { $opening->apply($precision); }, 'A native DOUBLE unable to preserve the exact approved difference is rejected');
checkAuto(openingState(109) === $precisionState && $db->depth === 0, 'Precision loss rolls back the native adjustment, opening and audit together');
$staleSource = $opening->preview(110,'2026-11-01','0','OPEN-STALE','Synthetic newly imported historical source');
stageAuto(971004,'12.34','NEW-HISTORICAL-SOURCE',110);
$db->query("UPDATE kbauto_kreabank_statement_line SET operation_date='2026-10-31' WHERE rowid=971004");
rejectAuto(static function () use ($opening,$staleSource) { $opening->apply($staleSource); }, 'A newly imported historical source invalidates an older opening preview');
checkAuto(count($opening->all()) === 2, 'Rejected stale and imprecise openings do not become active account boundaries');
bankAuto(972002,'10','CROSS-TARGET',105,'2026-11-01');
stageAuto(973001,'10','CROSS-SOURCE',105);
$db->query("UPDATE kbauto_kreabank_statement_line SET operation_date='2026-10-31',fk_native_bank_line=972002,status=2 WHERE rowid=973001");
rejectAuto(static function () use ($opening) { $opening->preview(105,'2026-11-01','0','OPEN-CROSS','Synthetic crossed match'); }, 'Historical sources linked to current-period native movements require resolution first');
$db->query("UPDATE kbauto_bank SET dateo='2026-10-31' WHERE rowid=972002");
$db->query("UPDATE kbauto_kreabank_statement_line SET operation_date='2026-11-01' WHERE rowid=973001");
rejectAuto(static function () use ($opening) { $opening->preview(105,'2026-11-01','10','OPEN-CROSS','Synthetic reverse crossed match'); }, 'Current-period sources linked to historical native movements require resolution first');
bankAuto(972003,'15','LEGACY-CROSS',106,'2026-11-01');
$db->query("INSERT INTO kbauto_kreabank_bankmeta (entity,fk_bank_line,bank_account_id,idempotency_hash,amount,currency,operation_date,datec) VALUES(1,972003,106,SHA2('opening-legacy',256),15,'EUR','2026-10-31',NOW())");
rejectAuto(static function () use ($opening) { $opening->preview(106,'2026-11-01','0','OPEN-LEGACY','Synthetic legacy crossing'); }, 'Legacy source/native cutoff crossings cannot be hidden by opening migration');
$conf->global->BANK_STATEMENT_REGEX_RULE = '^\\d{6}$';
rejectAuto(static function () use ($opening) { $opening->preview(107,'2026-11-01','0','OPEN-INVALID','Synthetic numbering'); }, 'Setup respects native bank-statement numbering rules');
$numeric = $opening->preview(107,'2026-11-01','0','202610','Synthetic valid numbering');
checkAuto($numeric['statement_ref'] === '202610', 'Native-compatible statement numbering remains usable');
unset($conf->global->BANK_STATEMENT_REGEX_RULE);
$wrong = clone $user; $wrong->entity = 2;
rejectAuto(static function () use ($db,$wrong) { (new KreaBankOpeningBalance($db,$wrong,1))->get(103); }, 'An actor fixed to another company cannot inspect its opening records');
checkAuto((new KreaBankOpeningBalance($db,null,1))->get(103)['rowid'] === $applied['rowid'], 'Authorized read-only projections can load the owned opening without execution credentials');
$readOther = new KreaBankOpeningBalance($db,null,2); $conf->entity = 2;
checkAuto($readOther->get(103) === null && $readOther->all() === array(), 'Opening records never cross directly owned company boundaries');
$conf->entity = 1;
$schema = preg_replace('/--[^\n]*/','',file_get_contents(__DIR__.'/../sql/llx_kreabank_payment_batch.sql'));
foreach (explode(';',str_replace('llx_',MAIN_DB_PREFIX,$schema)) as $sql) { if (trim($sql) !== '') { $db->query($sql); } }
stageAuto(973002,'20','GROUP-CROSS',108);
$db->query("UPDATE kbauto_kreabank_statement_line SET operation_date='2026-10-31' WHERE rowid=973002");
bankAuto(972004,'10','GROUP-PRIOR',108,'2026-10-31'); bankAuto(972005,'10','GROUP-CURRENT',108,'2026-11-01');
$db->query("INSERT INTO kbauto_kreabank_payment_batch (rowid,entity,fk_statement_line,active_statement_line,bank_account_id,amount,currency,operation_date,statement_ref,name,status,source_snapshot,fk_user,datec) VALUES(974001,1,973002,973002,108,20,'EUR','2026-10-31','GROUP-CROSS','Synthetic crossing',1,'{}',7,NOW())");
$db->query("INSERT INTO kbauto_kreabank_payment_batch_line (entity,fk_batch,fk_bank_line,active_bank_line,amount,snapshot_json) VALUES(1,974001,972004,972004,10,'{}'),(1,974001,972005,972005,10,'{}')");
rejectAuto(static function () use ($opening) { $opening->preview(108,'2026-11-01','0','OPEN-GROUP','Synthetic group crossing'); }, 'A batch spanning the cutoff requires resolution instead of partial migration');
checkAuto($db->depth === 0, 'All opening checks leave the disposable transaction closed');
$nonTransactional = $opening->preview(107,'2026-11-01','0','OPEN-ENGINE','Synthetic transaction safety');
$db->query('ALTER TABLE kbauto_kreabank_opening_balance ENGINE=MyISAM');
rejectAuto(static function () use ($opening,$nonTransactional) { $opening->apply($nonTransactional); }, 'Execution refuses nontransactional storage before any native write');
$db->query('ALTER TABLE kbauto_kreabank_opening_balance ENGINE=InnoDB');
checkAuto($db->depth === 0 && $opening->get(107) === null, 'Storage-engine rejection leaves no partial opening');
$pairBefore = $db->query("SELECT rowid,value FROM kbauto_const WHERE entity=1 AND name='KREABANK_ACCOUNT_PAIRS'")->fetch_all(MYSQLI_ASSOC);
$db->query("DELETE FROM kbauto_const WHERE entity=1 AND name='KREABANK_ACCOUNT_PAIRS'");
$db->query("INSERT INTO kbauto_const (entity,name,value) VALUES(1,'KREABANK_ACCOUNT_PAIRS','{\"107\":104}')");
$pairMap = (new KreaBankAccountPairs($db,1))->pairs();
$restricted = $opening->preview(107,'2026-11-01','12.34','OPEN-RESTRICTED','Synthetic initial restricted-account balance');
$restrictedApplied = $opening->apply($restricted);
checkAuto((int)$restrictedApplied['fk_adjustment_bank_line'] > 0 && (new KreaBankAccountPairs($db,1))->pairs() === $pairMap,
	'An explicitly reviewed initial SOLD balance supports a restricted account without changing its transfer-only configuration');
$db->query("DELETE FROM kbauto_const WHERE entity=1 AND name='KREABANK_ACCOUNT_PAIRS'");
foreach ($pairBefore as $row) { $db->query("INSERT INTO kbauto_const (rowid,entity,name,value) VALUES(" . (int)$row['rowid'] . ",1,'KREABANK_ACCOUNT_PAIRS','" . $db->escape($row['value']) . "')"); }

// Reproduce upgraded installations whose financial source is UTF-8 but module snapshots inherited Latin1.
$sqlModeBefore = $db->query('SELECT @@SESSION.sql_mode mode')->fetch_object()->mode;
$charsetBefore = $db->connection->character_set_name();
$db->connection->set_charset('utf8mb4');
$db->query("SET SESSION sql_mode=''");
try {
	$db->query('ALTER TABLE kbauto_kreabank_opening_balance MODIFY payload_json LONGTEXT CHARACTER SET latin1 NOT NULL, MODIFY evidence_ref VARCHAR(255) CHARACTER SET latin1 NOT NULL');
	$db->query('ALTER TABLE kbauto_kreabank_recon_audit MODIFY payload_json LONGTEXT CHARACTER SET latin1 NULL');
	$db->query('ALTER TABLE kbauto_bank MODIFY label VARCHAR(255) CHARACTER SET utf8mb4 NULL, MODIFY note TEXT CHARACTER SET utf8mb4 NULL');
	$db->query('ALTER TABLE kbauto_kreabank_statement_line MODIFY description TEXT CHARACTER SET utf8mb4 NULL');
	$db->query('ALTER TABLE kbauto_kreabank_bankmeta MODIFY description TEXT CHARACTER SET utf8mb4 NULL');
	foreach (array(111,112,113,114,115) as $account) {
		$db->query("INSERT INTO kbauto_bank_account (rowid,entity,ref,label,currency_code,clos,courant,rappro,fk_pays) VALUES ($account,1,'UNICODE-$account','Synthetic charset fixture','EUR',0,1,1,1)");
		bankAuto(978000+$account,'5.00','UNICODE-PRIOR',$account,'2026-10-31');
	}
	$unicode = "Synthetic \u{FFFD} deposit \u{9280}\u{884C}";
	$escapedUnicode = $db->escape($unicode);
	$db->query("UPDATE kbauto_bank SET label='$escapedUnicode',note='$escapedUnicode' WHERE rowid=978111");
	stageAuto(978501,'2.00','UNICODE-SOURCE',111);
	$db->query("UPDATE kbauto_kreabank_statement_line SET operation_date='2026-10-31',description='$escapedUnicode' WHERE rowid=978501");
	$db->query("INSERT INTO kbauto_kreabank_bankmeta (entity,fk_bank_line,bank_account_id,idempotency_hash,amount,currency,operation_date,description,datec) VALUES(1,978111,111,SHA2('unicode-opening',256),5,'EUR','2026-10-31','$escapedUnicode',NOW())");
	$unicodeReview = $opening->preview(111,'2026-11-01','10.25','OPEN-UNICODE','Synthetic bank evidence with an ASCII reference');
	$rawJson = json_encode($unicodeReview,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
	$legacyTransport = $db->query("SELECT CONVERT('" . $db->escape($rawJson) . "' USING latin1) payload")->fetch_object()->payload;
	checkAuto($legacyTransport !== $rawJson && strpos($legacyTransport,$unicode) === false, 'The regression reproduces silent loss of unescaped Unicode under non-strict Latin1 transport');
	$unicodeSourceBefore = openingState(111)['sources'];
	$legacyBefore = $db->query('SELECT * FROM kbauto_kreabank_bankmeta WHERE fk_bank_line=978111')->fetch_all(MYSQLI_ASSOC);
	$unicodeApplied = $opening->apply($unicodeReview);
	$unicodeJson = $unicodeApplied['payload_json'];
	$decoded = json_decode($unicodeJson,true,512,JSON_THROW_ON_ERROR);
	checkAuto(!preg_match('/[\x80-\xff]/',$unicodeJson) && strpos($unicodeJson,'\\ufffd') !== false && strpos($unicodeJson,'\\u9280\\u884c') !== false,
		'Only SQL transport is ASCII-escaped; unsupported source Unicode remains representable in legacy payload columns');
	checkAuto($decoded['native_rows'][0]['label'] === $unicode && $decoded['native_rows'][0]['note'] === $unicode
		&& $decoded['staged_rows'][0]['description'] === $unicode && $decoded['legacy_rows'][0]['description'] === $unicode,
		'Opening snapshots retain exact native, staged and legacy Unicode text after database readback');
	$facts = $decoded;
	foreach (array('facts_hash','issued_at','expires_at','fk_adjustment_bank_line','version') as $key) { unset($facts[$key]); }
	checkAuto(hash('sha256',json_encode($facts,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)) === $unicodeReview['facts_hash']
		&& $unicodeApplied['facts_hash'] === $unicodeReview['facts_hash'], 'Persisted Unicode snapshots reconstruct the unchanged public facts hash');
	$unicodeAudit = $db->query("SELECT payload_json FROM kbauto_kreabank_recon_audit WHERE audit_type='opening_reconciliation' AND JSON_UNQUOTE(JSON_EXTRACT(payload_json,'$.account_id'))='111'")->fetch_all(MYSQLI_ASSOC);
	checkAuto(count($unicodeAudit) === 1 && $unicodeAudit[0]['payload_json'] === $unicodeJson, 'Opening declaration and audit retain byte-identical, complete JSON transport');
	$nativeUnicode = $db->query('SELECT label,note FROM kbauto_bank WHERE rowid=978111')->fetch_object();
	checkAuto($nativeUnicode->label === $unicode && $nativeUnicode->note === $unicode && openingState(111)['sources'] === $unicodeSourceBefore
		&& $db->query('SELECT * FROM kbauto_kreabank_bankmeta WHERE fk_bank_line=978111')->fetch_all(MYSQLI_ASSOC) === $legacyBefore,
		'Unicode persistence never rewrites original native, staged or legacy evidence');

	$plainEvidence = $opening->preview(112,'2026-11-01','10.25','OPEN-PLAIN',"Unsupported plain reference \u{9280}\u{884C}");
	$plainBefore = openingState(112);
	rejectAuto(static function () use ($opening,$plainEvidence) { $opening->apply($plainEvidence); }, 'A legacy plain-text evidence column cannot silently substitute unsupported characters');
	checkAuto(openingState(112) === $plainBefore && $opening->get(112) === null && $db->depth === 0,
		'Lossy plain metadata rolls back native flags, the SOLD entry, declaration and audit together');

	/** Simulate damaged/truncated readback without changing the native lifecycle or shared database adapter. */
	class OpeningCorruptReadbackDb extends DoliDB
	{
		public $fault = '';
		public function fetch_object($result)
		{
			$row = parent::fetch_object($result);
			if ($row && isset($row->facts_hash,$row->payload_json)) {
				if ($this->fault === 'opening_json') { $row->payload_json .= ' '; }
				if ($this->fault === 'opening_identity') { $row->currency = 'USD'; }
			}
			if ($row && isset($row->audit_type,$row->payload_json) && $this->fault === 'audit_json') { $row->payload_json .= ' '; }
			return $row;
		}
	}
	$corruptDb = new OpeningCorruptReadbackDb();
	$corruptDb->connection->set_charset('utf8mb4'); $corruptDb->query("SET SESSION sql_mode=''");
	$corruptOpening = new KreaBankOpeningBalance($corruptDb,$user,1);
	foreach (array(113=>'opening_json',114=>'audit_json',115=>'opening_identity') as $account=>$fault) {
		$corruptReview = $corruptOpening->preview($account,'2026-11-01','10.25','OPEN-READBACK','Synthetic readback evidence');
		$readbackBefore = openingState($account); $corruptDb->fault = $fault;
		rejectAuto(static function () use ($corruptOpening,$corruptReview) { $corruptOpening->apply($corruptReview); }, 'Changed declaration or audit readback cannot return success: ' . $fault);
		$corruptDb->fault = '';
		checkAuto(openingState($account) === $readbackBefore && $corruptOpening->get($account) === null && $corruptDb->depth === 0,
			'Precommit readback failure leaves no native adjustment, reconciliation flag, opening or audit: ' . $fault);
	}
	$corruptDb->connection->close();
} finally {
	$db->query("SET SESSION sql_mode='" . $db->escape($sqlModeBefore) . "'");
	$db->connection->set_charset($charsetBefore);
}
echo 'OK: ' . ($checks - $initialChecks) . " opening-balance database checks\n";
