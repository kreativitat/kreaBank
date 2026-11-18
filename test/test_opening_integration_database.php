#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 * ONLY disposable 127.0.0.1:33317/kreabank_auto_test; the imported fixture resets kbauto_*.
 */
require __DIR__ . '/test_opening_balance_database.php';
require_once __DIR__ . '/../class/kreabankintegration.class.php';
require_once __DIR__ . '/../class/KreaBankWorklist.class.php';
require_once __DIR__ . '/../class/KreaBankHistory.class.php';
require_once __DIR__ . '/../lib/kreabank_worklist_undo.lib.php';
require_once __DIR__ . '/../lib/kreabank_statement_list.lib.php';
$checks = 0;
$projection = new KreaBankIntegration($db, 1);
$sourceSql = '(' . $projection->bankStatementSql(103) . ') h';
$historical = $db->query('SELECT h.* FROM ' . $sourceSql . ' WHERE h.rowid=971001')->fetch_object();
checkAuto((int) $historical->opening_reconciled === 1 && (int) $historical->status === 0 && !$historical->native_id

	&& kreabankIntegrationLineState($historical, $historical->statement_ref) === 'KreaBankOpeningReconciled',
	'The statement exposes opening acceptance without inventing a native match or rewriting staged evidence');
$filtered = $db->query('SELECT h.rowid FROM ' . $sourceSql . ' WHERE (' . kreabankStatementListStatusSql() . ")='reconciled' ORDER BY h.rowid")->fetch_all(MYSQLI_ASSOC);
checkAuto(array_column($filtered, 'rowid') === array('971001','971003'), 'Statement filters include both initial and later historical imports in reconciled');
$statement = $projection->statement(103, 'ST-971001');
checkAuto((int) $statement->reconciled_lines === 1 && (int) $statement->issue_lines === 0 && (int) $statement->native_lines === 0,
	'Statement totals distinguish historical reconciliation from native materialization');
$detail = $db->query('SELECT h.* FROM (' . $projection->linesSql(103, 'ST-971001') . ') h')->fetch_object();
checkAuto((int) $detail->opening_reconciled === 1 && (int) $detail->status === 0, 'Statement detail uses the same historical state');
$overview = $db->fetch_object($projection->accounts('all',103));
checkAuto((int) $overview->pending_count === 1 && KreaBankOpeningBalance::decimal((string) $overview->balance) === '625.12',
	'Account pending counts exclude historical evidence while balances retain actual native movements and the opening difference');
$worklist = new KreaBankWorklist($db, 1, $service);
$state = kreabankWorklistState(array('search_account'=>'103'));
$pending = $worklist->sources($state);
checkAuto(array_keys($pending) === array('staged:971002'), 'The start date itself remains in pending reconciliation');
$counts = $worklist->statusCounts($state);
checkAuto($counts['pending'] === 1 && $counts['reconciled'] === 2 && $counts['all'] === 3 && $counts['review'] === 0,
	'Worklist counts use the same complete opening state as the statement and account overview');
$state['filters']['status'] = 'reconciled';
$closed = $worklist->sources($state);
checkAuto(count($closed) === 2 && isset($closed['staged:971001'],$closed['staged:971003']), 'Reconciled worklist sources include historical imports');
$prepared = $worklist->prepare('staged:971001');
checkAuto(!empty($prepared['line']['opening_reconciled']) && !$prepared['line']['needs_review'] && $prepared['pair'] === null
	&& $prepared['account_review'] === null && $prepared['batch'] === array(), 'Historical sources prepare no match, payment-bank correction or saved batch action');
$compact = $worklist->compact('staged:971001');
checkAuto($compact['status'] === 'reconciled', 'A raw pending staging status cannot reopen an applied historical source');
$history = new KreaBankHistory($db, 1);
$row = $history->find(971001,'staged',103);
checkAuto($row && !$row['needs_review'] && $row['strategy'] === 'opening_balance' && !$row['native_bank_line_id'],
	'History presents the explicit opening declaration without a fabricated ledger link');
checkAuto(kreabankWorklistPrepareUndo($history,'staged:971001',103) === null, 'Opening declarations receive no ordinary Undo review');
$before = openingState();
rejectAuto(static function () use ($service) {
	$service->reconcileLine(971001,array(array('doc_type'=>'native_bank','fk_doc'=>970004,'allocated_amount'=>'12.34')));
}, 'A direct reconciliation request cannot bypass the historical cutoff');
rejectAuto(static function () use ($service) { $service->createQuickEntryAndReconcile(971001,'payment','Synthetic forbidden old creation','12.34'); },
	'A historical source cannot create a quick-entry helper or financial movement');
checkAuto($service->getAutomaticNativeSuggestion(971001,array(103)) === null, 'Explicit automatic-native source IDs respect the opening cutoff');
$nativeAdapter = new KreaBankNativeBankAdapter($db,$user,$langs,1);
rejectAuto(static function () use ($nativeAdapter) { $nativeAdapter->unconciliateLine(970001,'OPEN-20261101'); }, 'A historical native row cannot be reopened through ordinary Undo');
rejectAuto(static function () use ($nativeAdapter) { $nativeAdapter->conciliateLine(970001,'REPLACEMENT'); }, 'A historical native row cannot receive a replacement ordinary reconciliation');
rejectAuto(static function () use ($service) { $service->deleteImportedStatement(103,'ST-971001'); }, 'Opening source evidence cannot be deleted as an unreconciled statement');
checkAuto(openingState() === $before && $db->depth === 0, 'All refused historical actions preserve source evidence, movements, opening and audit');
// A newly imported native legacy row before the boundary is also historical,
// while raw native flags and balances remain honest if another tool added it.
bankAuto(975001,'2.50','LATE-LEGACY',103,'2026-10-29');
$db->query("UPDATE kbauto_bank SET num_releve='LATE-LEGACY',note='KreaBank import synthetic' WHERE rowid=975001");
$db->query("INSERT INTO kbauto_kreabank_bankmeta(entity,fk_bank_line,bank_account_id,idempotency_hash,amount,currency,status,operation_date,datec)"
	. " VALUES(1,975001,103,SHA2('opening-late-legacy',256),2.50,'EUR',0,'2026-10-29',NOW())");
$legacy = $db->query('SELECT h.* FROM ' . $sourceSql . " WHERE h.rowid=975001 AND h.source_storage='legacy_native'")->fetch_object();
checkAuto((int) $legacy->opening_reconciled === 1 && (int) $legacy->rappro === 0, 'Late historical legacy evidence uses explicit opening acceptance without pretending another tool reconciled it');
$overview = $db->fetch_object($projection->accounts('all',103));
checkAuto((int) $overview->pending_count === 1, 'A later historical legacy import cannot restore the backlog count');
$legacyCount = $projection->statement(103,'LATE-LEGACY');
checkAuto((int) $legacyCount->reconciled_lines === 1 && (int) $legacyCount->issue_lines === 0, 'Legacy statement totals share the historical boundary');
$legacyBefore = openingState(); $metadataBefore = countAuto('kreabank_bankmeta');
foreach (array(false,true) as $allowReconciled) {
	rejectAuto(static function () use ($service,$allowReconciled) { $service->deleteImportedStatement(103,'LATE-LEGACY',$allowReconciled); },
		'Legacy historical evidence cannot be deleted through either ordinary or reconciled deletion');
}
$db->query("INSERT INTO kbauto_kreabank_bankmeta(entity,fk_bank_line,bank_account_id,idempotency_hash,amount,currency,status,operation_date,datec)"
	. " VALUES(1,970001,103,SHA2('opening-applied-legacy',256),100,'EUR',0,'2026-10-30',NOW())");
$metadataBefore++;
rejectAuto(static function () use ($service) { $service->deleteImportedStatement(103,'OPEN-20261101',true); },
	'Even an explicit reconciled-deletion request cannot remove native movements accepted at opening');
checkAuto(openingState() === $legacyBefore && countAuto('kreabank_bankmeta') === $metadataBefore && $db->depth === 0,
	'Legacy deletion refusal preserves the ledger balance, metadata, opening and audit atomically');
$pendingCount = $service->getPendingLinesCount(array('bank_account_id'=>103));
$legacyPending = $service->getPendingLines(10,0,'operation_date','ASC',array('bank_account_id'=>103));
checkAuto($pendingCount === 1 && count($legacyPending) === 1 && (int) $legacyPending[0]['rowid'] === 971002,
	'The legacy queue and its count exclude staged and native historical imports before pagination');
$state['filters']['status'] = 'reconciled';
checkAuto(count($worklist->sources($state)) === 4, 'All reconciled worklist generations share the same historical declaration');
$conf->entity = 2;
$foreign = new KreaBankIntegration($db,2);
checkAuto($db->num_rows($foreign->query('SELECT h.* FROM (' . $foreign->bankStatementSql(103) . ') h')) === 0,
	'Opening acceptance never makes another company account visible');
$conf->entity = 1;
echo "OK: $checks opening integration database checks\n";
