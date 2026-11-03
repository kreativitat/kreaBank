#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 * ONLY the disposable database guarded by the automatic reconciliation suite.
 */
require __DIR__ . '/test_automatic_reconciliation_database.php';
require_once __DIR__ . '/../lib/kreabank_integration.lib.php';
require_once __DIR__ . '/../class/KreaBankFintableSync.class.php';
$conf->entity = 1;
$checks = 0;
$report = new KreaBankIntegration($db, 1);
$before = countAuto('bank');
// The feed schema is deliberately absent until a workspace is configured.
$row = $db->fetch_object($report->accounts('bank', 101, 'feed_balance', 'ASC', 0, 0, true));
checkAuto($row->feed_balance === null && $row->bank_updated_at === null, 'Unconfigured feed remains readable without optional tables');
$sync = new KreaBankFintableSync($db);
$sync->ensureSchema();
$db->query("INSERT INTO kbauto_const (name,value,type,entity) VALUES ('KREABANK_FINTABLE_WORKSPACE','ws_fixture','chaine',0)");
foreach (array(
 array(1,101,'ws_fixture','acc_one','GB82WEST12345698765432','EUR','0.00'),
 array(1,102,'ws_fixture','acc_two','NL91ABNA0417164300','EUR','-20.50'),
 array(2,202,'ws_fixture','acc_foreign','DE89370400440532013000','EUR','999.00'),
 array(1,101,'ws_old','acc_old','GB82WEST12345698765432','EUR','888.00'),
) as $fixture) {
 list($entity,$account,$workspace,$source,$iban,$currency,$amount) = $fixture;
 $db->query("INSERT INTO kbauto_kreabank_feed_account (entity,fk_bank_account,workspace_id,provider_account_id,provider_connection_id,iban,currency,balance,bank_updated_at,last_attempt,status,datec) VALUES ($entity,$account,'$workspace','$source','conn_fixture','$iban','$currency',$amount,'2026-11-03T08:00:00Z','2026-11-03 08:45:00','ready',NOW())");
}
$read = static function ($account = 101) use ($db,$report) { return $db->fetch_object($report->accounts('all',$account,'ref','ASC',0,0,true)); };
$row = $read();
checkAuto($row->feed_balance === '0.00000000', 'A real zero balance survives as a decimal string');
checkAuto($row->bank_updated_at === '2026-11-03T08:00:00Z' && $row->feed_collected_at === '2026-11-03 08:45:00', 'Bank and balance collection times remain distinct');
$native = $db->fetch_object($report->accounts('all',101));
checkAuto(!property_exists($native,'feed_balance') && $native->balance === $row->balance, 'Default/API native balance contract is unchanged');
checkAuto(!$read(202), 'Foreign-company feed account is denied');
$rows = $report->accounts('bank',0,'feed_balance','ASC',1,0,true);
checkAuto($db->num_rows($rows) === 1 && (int)$db->fetch_object($rows)->rowid === 102, 'Numeric feed sorting occurs before pagination');
$rows = $report->accounts('bank',0,'feed_balance','DESC',1,0,true);
checkAuto((int)$db->fetch_object($rows)->rowid === 101, 'Descending feed sorting preserves zero');
$db->query("UPDATE kbauto_bank_account SET currency_code='USD' WHERE rowid=101");
checkAuto($read()->feed_balance === null, 'Changed account currency cannot expose an old snapshot');
$db->query("UPDATE kbauto_bank_account SET currency_code='EUR',iban_prefix='FR1420041010050500013M02606' WHERE rowid=101");
checkAuto($read()->feed_balance === null, 'Changed account IBAN cannot expose an old snapshot');
$db->query("UPDATE kbauto_bank_account SET iban_prefix='gb82 west 1234 5698 7654 32' WHERE rowid=101");
checkAuto($read()->feed_balance === '0.00000000', 'Formatted IBAN retains the exact normalized identity');
$db->query("UPDATE kbauto_kreabank_feed_account SET entity=2 WHERE provider_account_id='acc_one'");
checkAuto($read()->feed_balance === null, 'Misowned feed evidence cannot cross company scope');
$db->query("UPDATE kbauto_kreabank_feed_account SET entity=1,status='mapping_review' WHERE provider_account_id='acc_one'");
checkAuto($read()->feed_balance === null, 'Mapping requiring review does not supply a balance');
$db->query("UPDATE kbauto_kreabank_feed_account SET status='unavailable' WHERE provider_account_id='acc_one'");
checkAuto($read()->feed_balance === null, 'Unavailable source does not supply a balance');
$db->query("UPDATE kbauto_kreabank_feed_account SET status='failed' WHERE provider_account_id='acc_one'");
checkAuto($read()->feed_balance === '0.00000000' && $read()->feed_status === 'failed', 'A retained snapshot exposes collection failure');
$db->query("UPDATE kbauto_bank_account SET courant=2 WHERE rowid=101");
checkAuto($read()->feed_balance === null, 'Cash accounts cannot reuse a bank feed snapshot');
$db->query("UPDATE kbauto_bank_account SET courant=1,clos=1 WHERE rowid=101");
checkAuto((int)$db->fetch_object($report->accounts('closed',101,'ref','ASC',0,0,true))->rowid === 101, 'Closed-account filter is preserved');
function price($amount, $dummy = 0, $lang = '', $truncate = 0, $max = 2, $min = 2) { return number_format((float)$amount,2,',',' '); }
$row = $read();
$html = kreabankAccountFeedCells($row);
checkAuto(strpos($html,'0,00') !== false && strpos($html,'KreaBankFeedState_failed') !== false, 'Zero snapshot renders with its failed status');
checkAuto(substr($html, strrpos($html, '<td>')) === '<td>' . dol_print_date(strtotime($row->bank_updated_at), 'dayhour') . '</td>', 'Bank refresh cell contains only the date and time');
$row->feed_balance = '-20.50000000';
$row->feed_status = 'ready';
$row->bank_updated_at = '2000-01-01T00:00:00Z';
checkAuto(strpos(kreabankAccountFeedCells($row),'-20,50') !== false && strpos(kreabankAccountFeedCells($row),'KreaBankFeedState_stale') !== false, 'Aging snapshots dynamically render stale with signed amounts');
$row->feed_balance = null;
$row->feed_status = null;
$row->bank_updated_at = 'invalid';
$row->feed_collected_at = null;
$html = kreabankAccountFeedCells($row);
checkAuto(strpos($html,'—') !== false && strpos($html,'0,00') === false && strpos($html,'KreaBankFeedState_unmapped') !== false, 'Missing snapshot never becomes a false zero');
checkAuto(countAuto('bank') === $before, 'Overview never materializes or changes native bank movements');
print "OK: $checks account feed database checks\n";
