#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
require_once __DIR__ . '/../lib/kreabank_bulk.lib.php';
require_once __DIR__ . '/test_reconciliation_runtime.php';
require_once __DIR__ . '/../class/KreaBankMatcher.class.php';
$checks = 0;
function checkReview($condition, $label) {
	global $checks;
	if (!$condition) { throw new RuntimeException($label); }
	$checks++;
}
$row = array(
	'line' => array('rowid' => 10, 'fk_statement' => 3, 'bank_account_id' => 101, 'status' => 0,
		'amount' => -47.66, 'currency' => 'EUR', 'operation_date' => '2026-11-01',
		'description' => 'TRF.IMED. P/ EXAMPLE RECIPIENT-E12345'),
	'suggestion' => array('doc_type' => 'native_bank', 'doc_id' => 20, 'doc_ref' => 'BANK-20',
		'doc_date' => '2026-11-01', 'thirdparty_name' => '(paiement) / Example Recipient', 'amount_open' => -47.66),
	'allocation' => 47.66,
);
$secret = str_repeat('synthetic-secret-', 4);
$scope = '1|7|101';
$expires = 2000;
$signature = kreabankBulkReviewSignature($row, $scope, $expires, $secret);
$valid = static function ($candidate, $context = '1|7|101', $time = 1000, $confirmed = true, $account = 101) use ($secret, $expires, $signature) {
	return kreabankBulkReviewIsValid($candidate, $context, $expires, $signature, $secret, $time, $confirmed, $account);
};
checkReview($valid($row), 'An explicitly reviewed, unchanged pair is eligible without automatic confidence');
checkReview(!$valid($row, $scope, 1000, false), 'Checkbox selection alone is not manual approval');
checkReview(!$valid($row, $scope, 2001), 'Expired review requires fresh approval');
checkReview(!$valid($row, $scope, 1), 'Expiry cannot be extended beyond the review window');
foreach (array('2|7|101', '1|8|101', '1|7|102') as $otherScope) {
	checkReview(!$valid($row, $otherScope), 'Review is bound to company, user and account context');
}
checkReview(!$valid($row, $scope, 1000, true, 102), 'Explicit account filter is enforced');
foreach (array('rowid' => 11, 'fk_statement' => 0, 'bank_account_id' => 102, 'amount' => 47.66,
	'currency' => 'USD', 'operation_date' => '2026-10-31', 'description' => 'Changed payee', 'status' => 2) as $field => $value) {
	$changed = $row; $changed['line'][$field] = $value;
	checkReview(!$valid($changed), 'Changed source fact rejected: ' . $field);
}
foreach (array('doc_id' => 21, 'doc_type' => 'supplier_invoice', 'amount_open' => -47.65,
	'doc_ref' => 'OTHER', 'doc_date' => '2026-10-31', 'thirdparty_name' => 'Different Person',
	'bank_account_id' => 102, 'bank_account_ref' => 'OTHER BANK') as $field => $value) {
	$changed = $row; $changed['suggestion'][$field] = $value;
	checkReview(!$valid($changed), 'Changed target fact rejected: ' . $field);
}
$changed = $row; $changed['allocation'] = 40;
checkReview(!$valid($changed), 'Allocation is bound to the displayed pair');
foreach (array('', str_repeat('0', 64), array('nested')) as $invalid) {
	checkReview(!kreabankBulkReviewIsValid($row, $scope, $expires, $invalid, $secret, 1000, true, 101), 'Missing, tampered and malformed signatures fail closed');
}
$matcher = new KreaBankMatcher();
$document = array('rowid' => 20, 'doc_type' => 'native_bank', 'ref' => '', 'ref_client' => '',
	'doc_date' => '2026-11-01', 'amount_open' => -47.66, 'thirdparty_name' => '(paiement) / Example Recipient');
$score = $matcher->scoreLineToDocument($row['line'], $document, array(), 3);
checkReview($score['score'] === 125 && in_array('name_description', $score['details'], true), 'A complete name in the description counts as additional evidence');
checkReview($matcher->getSafeSuggestion(array($score), 100) === null, 'Description name evidence cannot authorize unattended matching');
$line = $row['line']; $line['counterparty_name'] = 'Example Recipient';
$score = $matcher->scoreLineToDocument($line, $document, array(), 3);
checkReview($score['score'] === 135 && in_array('name_partial', $score['details'], true), 'Native payment display markers do not obstruct structured name matches');
foreach (array('TRF P/ Example Recipientson', 'TRF P/ Different Recipient', 'TRF P/ Recipient Example') as $description) {
	$line = $row['line']; $line['description'] = $description;
	$score = $matcher->scoreLineToDocument($line, $document, array(), 3);
	checkReview($score['score'] === 100, 'A different, incomplete or reordered description name does not gain points');
}
$line = $row['line']; $line['counterparty_name'] = 'Different Person';
$score = $matcher->scoreLineToDocument($line, $document, array(), 3);
checkReview(!in_array('name_description', $score['details'], true), 'Description cannot override a conflicting structured counterparty');
echo 'OK: ' . $checks . " manual bulk review checks\n";
