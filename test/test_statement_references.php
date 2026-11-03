#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
require_once __DIR__ . '/../lib/kreabank_integration.lib.php';
$line = (object) array('payment_reference' => '', 'bank_reference' => 'acc_TEST--gocardless_v3_' . str_repeat('a', 64), 'source_type' => 'fintable');
$checks = 0;
function checkReferences($condition, $message) {
	global $checks;
	if (!$condition) { throw new RuntimeException($message); }
	$checks++;
}
checkReferences(kreabankStatementReferences($line) === array(), 'Feed external IDs are hidden in the combined table');
checkReferences(kreabankStatementReferences($line, 'fintable') === array(), 'Individual feed statements use the same display rule');
$line->payment_reference = 'CHEQUE-12345';
checkReferences(array_values(kreabankStatementReferences($line)) === array('CHEQUE-12345'), 'A genuine check/payment reference remains visible');
$line->source_type = 'csv';
$line->bank_reference = 'BANK-67890';
checkReferences(array_values(kreabankStatementReferences($line)) === array('CHEQUE-12345', 'BANK-67890'), 'File imports retain their meaningful bank references');
$line->bank_reference = 'CHEQUE-12345';
checkReferences(array_values(kreabankStatementReferences($line)) === array('CHEQUE-12345'), 'Repeated display references appear once');
$line->payment_reference = '0';
$line->bank_reference = null;
checkReferences(array_values(kreabankStatementReferences($line)) === array('0'), 'Zero-valued references are not treated as missing');
print "OK: $checks statement reference display checks\n";
