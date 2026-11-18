#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
require_once __DIR__ . '/../class/KreaBankOpeningBalance.class.php';
$checks = 0;
function openingCheck($value, $label) { global $checks; if (!$value) { throw new RuntimeException($label); } $checks++; }
function openingReject($call, $label) { $failed = false; try { $call(); } catch (Throwable $e) { $failed = true; } openingCheck($failed, $label); }
foreach (array('2026-11-01','2024-02-29','1900-01-02') as $date) {
	openingCheck(KreaBankOpeningBalance::validateDate($date, '2026-11-18') === $date, 'Accept a real unambiguous cutoff date');
}
foreach (array('2026-02-29','2026-11-31','01/11/2026','2026-11-01 12:00:00','2026-11-19','1900-01-01','0000-00-00',array('2026-11-01')) as $date) {
	openingReject(static function () use ($date) { KreaBankOpeningBalance::validateDate($date, '2026-11-18'); }, 'Reject invalid, future or ambiguous cutoff dates');
}
foreach (array('0'=>'0','-0.000'=>'0','+00125.12000000'=>'125.12','-9.00000001'=>'-9.00000001','9999999999999999.99999999'=>'9999999999999999.99999999') as $value=>$expected) {
	openingCheck(KreaBankOpeningBalance::decimal((string) $value) === $expected, 'Preserve exact supported decimal evidence');
}
foreach (array('1e3','1,23','1.000000001','10000000000000000','NaN',1.25,array('1.25')) as $amount) {
	openingReject(static function () use ($amount) { KreaBankOpeningBalance::decimal($amount); }, 'Reject unsupported amount precision and non-decimal input');
}
openingCheck(KreaBankDecimal::subtract('125.12','110') === '15.12', 'Opening adjustment uses only the exact difference');
class OpeningReadDb
{
	public $installed = false;
	public $queries = array();
	public function prefix() { return 'synthetic_'; }
	public function escape($value) { return addslashes($value); }
	public function query($sql) { $this->queries[] = $sql; return (object) array('nb'=>$this->installed ? 1 : 0); }
	public function fetch_object($result) { return $result; }
}
$db = new OpeningReadDb();
openingCheck(KreaBankOpeningBalance::historicalSql($db, 2, 's.bank_account_id', 'l.operation_date', 'l.currency') === '0=1', 'An installation without the explicit schema retains existing behavior');
openingCheck(count($db->queries) === 1 && strpos($db->queries[0], 'SELECT ') === 0, 'Read-only discovery never installs tables');
KreaBankOpeningBalance::historicalSql($db, 2, 's.bank_account_id', 'l.operation_date', 'l.currency');
openingCheck(count($db->queries) === 1, 'SQL helpers discover missing schema once per database object');
$db->installed = true;
$conf = (object) array('entity'=>2);
$read = new KreaBankOpeningBalance($db,null,2);
openingCheck($read->available() && count($db->queries) === 2, 'Explicit availability refreshes schema discovery after installation');
$sql = KreaBankOpeningBalance::historicalSql($db, 2, 's.bank_account_id', 'COALESCE(m.operation_date,b.dateo)', 'ba.currency_code');
openingCheck(strpos($sql, 'kbo.entity=2') !== false && strpos($sql, 'kboa.entity=kbo.entity') !== false
	&& strpos($sql, 'kboa.currency_code=kbo.currency') !== false && strpos($sql, 'COALESCE(m.operation_date,b.dateo)<kbo.start_date') !== false,
	'Historical state binds the exact owned account, currency and strict earlier booking date');
openingCheck(count($db->queries) === 2, 'Available SQL helpers share cached discovery without caching opening dates or records');
$db->installed = false;
openingCheck(!$read->available() && KreaBankOpeningBalance::historicalSql($db,2,'s.bank_account_id','l.operation_date','l.currency') === '0=1', 'A fresh availability check invalidates discovery after schema rollback');
openingReject(static function () use ($db) { KreaBankOpeningBalance::historicalSql($db, 2, 's.bank_account_id OR 1=1', 'l.operation_date', 'l.currency'); }, 'SQL expressions cannot carry request fragments');
openingReject(static function () use ($db) { KreaBankOpeningBalance::historicalSql($db, 0, 's.bank_account_id', 'l.operation_date', 'l.currency'); }, 'SQL guards require an explicit company');
echo 'OK: ' . $checks . " opening-balance validation checks\n";
