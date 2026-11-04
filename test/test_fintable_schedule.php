#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Installed native configuration helpers, synthetic database only; no bootstrap.
 */
define('DOL_DOCUMENT_ROOT', getenv('KREABANK_DOLIBARR_ROOT') ?: dirname(__DIR__, 3));
define('MAIN_DB_PREFIX', 'schedule_test_');
require_once __DIR__ . '/test_fintable.php';
require_once __DIR__ . '/../class/KreaBankFintableSync.class.php';
function dol_syslog($message, $level = 0) {}
class FeedScheduleDb
{
	public $writes = array();
	public function prefix() { return 'schedule_test_'; }
	public function escape($value) { return addslashes($value); }
	public function encrypt($value) { return "'" . $this->escape($value) . "'"; }
	public function query($sql) {
		if (strpos($sql, 'SELECT ') !== 0) { $this->writes[] = $sql; }
		return true;
	}
	public function fetch_object($result) { return null; }
	public function free($result) {}
	public function begin() { return true; }
	public function commit() { return true; }
	public function rollback() { return true; }
}
class FeedScheduleHooks
{
	public function executeHooks($name, $parameters) { return 0; }
}
$hookmanager = new FeedScheduleHooks();
$conf = (object) array('global' => new stdClass(), 'file' => (object) array('instance_unique_id' => 'synthetic-schedule-fixture'));
$actor = (object) array('id' => 1, 'admin' => 1, 'entity' => 0, 'socid' => 0);
$values = array('workspace' => 'ws_fixture', 'enabled' => 1, 'refresh' => 0);
foreach (array('08:45', '08:45,20:45', ' 08:45 , 20:45 ') as $times) {
	$db = new FeedScheduleDb();
	$sync = new KreaBankFintableSync($db);
	$sync->configure($values + array('times' => $times), $actor);
	$normalized = str_replace(' ', '', $times);
	checkFeed(strpos(implode("\n", $db->writes), "'KREABANK_FINTABLE_TIMES', '" . $normalized . "'") !== false, 'Save daily and existing twice-daily schedules through native configuration');
	foreach ($db->writes as $sql) {
		checkFeed((bool) preg_match('/(?:entity = 0|, 0\))$/D', $sql), 'Every configuration write is installation-wide');
	}
}
$db = new FeedScheduleDb();
$sync = new KreaBankFintableSync($db);
$sync->configure($values, $actor);
checkFeed($conf->global->KREABANK_FINTABLE_TIMES === '08:45', 'New configurations default to one morning collection');
foreach (array('', '08:45,', '08:45,08:45', '08:45, 08:45', '08:45,12:00,20:45', '24:00', '8:45') as $times) {
	$db = new FeedScheduleDb();
	$sync = new KreaBankFintableSync($db);
	rejectFeed(static function () use ($sync, $values, $times, $actor) {
		$sync->configure($values + array('times' => $times), $actor);
	}, 'Reject empty, duplicate, excessive or malformed collection times');
	checkFeed($db->writes === array(), 'Invalid schedules write no settings');
}
$db = new FeedScheduleDb();
$sync = new KreaBankFintableSync($db);
$companyAdmin = clone $actor;
$companyAdmin->entity = 2;
rejectFeed(static function () use ($sync, $values, $companyAdmin) { $sync->configure($values, $companyAdmin); }, 'Company administrators cannot change the global collection schedule');
checkFeed($db->writes === array(), 'Denied configuration writes no settings');
print "OK: $checks Fintable schedule and contract checks\n";
