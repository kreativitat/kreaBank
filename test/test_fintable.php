#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
require_once __DIR__ . '/../class/KreaBankFintableMapper.class.php';
$checks = 0;
function checkFeed($condition, $message)
{
	global $checks;
	$checks++;
	if (!$condition) {
		throw new RuntimeException($message);
	}
}
function rejectFeed($callable, $message)
{
	try {
		$callable();
	} catch (Exception $e) {
		checkFeed(true, $message);
		return;
	}
	checkFeed(false, $message);
}
$account = array('id' => 'acc_fixture', 'currency' => 'EUR');
$tx = array('id' => 'tx_one', 'account_id' => 'acc_fixture', 'pending' => false, 'amount' => '-1000.12000000',
	'currency' => 'EUR', 'date' => '2026-10-30', 'description' => '10,00', 'ext_id' => 'entry-one');
$one = KreaBankFintableMapper::transaction($tx, $account, 'ws_fixture');
checkFeed($one['line']['amount'] === '-1000.12', 'Money must remain an exact signed decimal string');
$other = $tx;
$other['id'] = 'tx_two';
checkFeed(KreaBankFintableMapper::transaction($other, $account, 'ws_fixture')['uid'] !== $one['uid'], 'Identical-looking movements need distinct identities');
checkFeed(KreaBankFintableMapper::iban(' GB82 WEST 1234 5698 7654 32 ') === 'GB82WEST12345698765432', 'IBAN whitespace normalization');
checkFeed(KreaBankFintableMapper::iban('GB83WEST12345698765432') === '', 'Bad IBAN checksum');
foreach (array(1.25, '1e3', '1,00', '1.123456789', '1;DELETE') as $bad) {
	rejectFeed(static function () use ($bad) { KreaBankFintableMapper::decimal($bad); }, 'Reject noncanonical monetary input');
}
foreach (array(array('pending' => true), array('pending' => null), array('account_id' => 'acc_other'), array('currency' => 'USD'), array('date' => '2026-02-30')) as $bad) {
	rejectFeed(static function () use ($tx, $bad, $account) { KreaBankFintableMapper::transaction(array_merge($tx, $bad), $account, 'ws_fixture'); }, 'Reject malformed/cross-account transaction');
}
foreach (array('2026-03-29T08:50:00+01:00', '2026-10-25T08:50:00+00:00') as $date) {
	checkFeed(substr(KreaBankFintableMapper::slot(strtotime($date), '08:45,20:45', 'Europe/Lisbon'), -5) === '08:45', 'DST keeps local wall-clock slot');
}
checkFeed(KreaBankFintableMapper::slot(strtotime('2026-11-01T08:00:00Z'), '08:45,20:45', 'Europe/Lisbon') === null, 'No collection before first slot');
foreach (array('2026-03-29T08:50:00+01:00', '2026-10-25T08:50:00+00:00', '2026-11-01T21:00:00Z') as $date) {
	checkFeed(substr(KreaBankFintableMapper::slot(strtotime($date), '08:45', 'Europe/Lisbon'), -5) === '08:45', 'Daily schedule retains the same slot through evening and DST');
}
checkFeed(KreaBankFintableMapper::slot(strtotime('2026-11-02T08:00:00Z'), '08:45', 'Europe/Lisbon') === null, 'Daily schedule waits for the next morning');
$requests = array();
$client = new KreaBankFintableClient(str_repeat('x', 32), 'ws_fixture', static function ($method, $url) use (&$requests) {
	$requests[] = $url;
	parse_str(parse_url($url, PHP_URL_QUERY), $query);
	checkFeed($query['workspace_id'] === 'ws_fixture', 'Every API request pins its workspace');
	return array('status' => 200, 'body' => json_encode(array('data' => array(), 'next_cursor' => isset($query['cursor']) ? null : 'opaque+/=cursor', 'workspace_id' => 'ws_fixture')));
});
checkFeed(count(iterator_to_array($client->transactionPages('acc_fixture'))) === 2, 'Read every transaction page');
checkFeed(strpos($requests[1], 'opaque%2B%2F%3Dcursor') !== false, 'Encode opaque cursor without changing it');
foreach (array(401, 403, 429, 500) as $status) {
	$client = new KreaBankFintableClient(str_repeat('x', 32), 'ws_fixture', static function () use ($status) {
		return array('status' => $status, 'body' => 'sensitive response must not appear in diagnostics');
	});
	rejectFeed(static function () use ($client) { $client->get('accounts'); }, 'HTTP errors fail closed');
}
$client = new KreaBankFintableClient(str_repeat('x', 32), 'ws_fixture', static function () {
	return array('status' => 200, 'body' => '{"data":[],"next_cursor":"repeated"}');
});
rejectFeed(static function () use ($client) { iterator_to_array($client->transactionPages('acc_fixture')); }, 'Reject cursor loops');
$client = new KreaBankFintableClient(str_repeat('x', 32), 'ws_fixture', static function () {
	return array('status' => 200, 'body' => '{"data":[],"workspace_id":"ws_other"}');
});
rejectFeed(static function () use ($client) { $client->get('accounts'); }, 'Reject workspace mismatch');
rejectFeed(static function () use ($client) { $client->get('https://other.example'); }, 'Reject arbitrary destinations');
print "OK: $checks Fintable contract checks\n";
