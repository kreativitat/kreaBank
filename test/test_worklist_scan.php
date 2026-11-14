#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
require_once __DIR__ . '/../lib/kreabank_worklist.lib.php';
$checks = 0;
function checkScan($condition, $message) {
	global $checks;
	if (!$condition) { throw new RuntimeException($message); }
	$checks++;
}
$sources = array_map(static function ($id) { return 'staged:' . $id; }, range(1,166));
$scan = array('generation'=>'first', 'cursor'=>0, 'targets'=>kreabankWorklistScanTargets($sources,array_slice($sources,0,25),array(),25), 'summaries'=>array());
$calls = array();
$prepare = static function ($key) use (&$calls) { $calls[] = $key; return array('key'=>$key); };
for ($i=0; $i<30; $i++) { kreabankWorklistAdvanceScan($scan,$prepare); }
checkScan($calls === array_slice($sources,0,25), '166 sources automatically analyse exactly the displayed 25, with no final-chunk spill');
checkScan(count($scan['summaries']) === 25, 'Further polling adds no work after the granted batch');
$reloaded = $scan;
$reloaded['targets'] = kreabankWorklistScanTargets($sources,array_slice($sources,0,25),$scan['summaries'],25);
kreabankWorklistAdvanceScan($reloaded,$prepare);
checkScan(count($calls) === 25, 'Reloading the same page never grants another batch');
checkScan(!kreabankWorklistMoreScan($scan,$sources,25,'stale',0), 'A stale generation cannot grant work');
checkScan(!kreabankWorklistMoreScan($scan,$sources,25,'first',1), 'A stale cursor cannot grant work');
checkScan(kreabankWorklistMoreScan($scan,$sources,25,'first',0), 'An explicit current More grants another batch');
checkScan($scan['targets'] === array_slice($sources,25,25), 'More targets only the next 25 unanalysed sources');
$nextGeneration = $scan['generation'];
checkScan(!kreabankWorklistMoreScan($scan,$sources,25,'first',0) && $scan['generation']===$nextGeneration, 'Double-click/replay cannot expand the quota twice');
checkScan(!kreabankWorklistMoreScan($scan,$sources,25,$nextGeneration,0), 'An unfinished batch cannot grant more work');
kreabankWorklistAdvanceScan($scan,$prepare);
checkScan(count($calls)===33, 'A single request retains its eight-row bound');
for ($i=0; $i<30; $i++) { kreabankWorklistAdvanceScan($scan,$prepare); }
checkScan(count($calls)===50 && count(array_unique($calls))===50, 'One More grants exactly 25 more, without matching duplicates');
while (count($scan['summaries']) < 166) {
	checkScan(kreabankWorklistMoreScan($scan,$sources,25,$scan['generation'],$scan['cursor']), 'Each subsequent batch needs its current explicit grant');
	for ($i=0; $i<30; $i++) { kreabankWorklistAdvanceScan($scan,$prepare); }
}
checkScan(count($scan['targets'])===16 && count($calls)===166, 'The final partial batch stops at its 16 remaining sources');
checkScan(kreabankWorklistScanTargets($sources,array_slice($sources,25,25),array(),25)===array_slice($sources,25,25), 'Page two analyses its own source IDs');
checkScan(count(kreabankWorklistScanTargets($sources,array(),array(),15))===15, 'Derived filters begin with one configured list-size batch');
checkScan(count(kreabankWorklistScanTargets($sources,$sources,array(),9999))===100, 'A forged limit cannot exceed the native maximum');
checkScan(kreabankWorklistScanTargets($sources,array('native:1','staged:1','staged:1'),array(),25)===array('staged:1'), 'Targets retain explicit source namespaces and cannot add out-of-scope IDs');
$failed = array('targets'=>array_slice($sources,0,5),'summaries'=>array());
try {
	kreabankWorklistAdvanceScan($failed,static function ($key) { if ($key==='staged:3') { throw new RuntimeException('Synthetic failure'); } return array('key'=>$key); });
} catch (RuntimeException $expected) {}
checkScan(array_keys($failed['summaries'])===array_slice($sources,0,2), 'A failure preserves completed progress for a retry');
$calls = array(); kreabankWorklistAdvanceScan($failed,$prepare);
checkScan($calls===array_slice($sources,2,3), 'A retry matches only unfinished targets');
$slow = array('targets'=>array_slice($sources,0,5),'summaries'=>array());
kreabankWorklistAdvanceScan($slow,static function ($key) { usleep(1210000); return array('key'=>$key); });
checkScan(count($slow['summaries'])===1, 'The time budget yields between individual rows');
$claim = array('scope'=>'company-user-sources','view'=>'page-b','expires'=>time()+60,'generation'=>'current','cursor'=>4,'targets'=>array('staged:1'),'summaries'=>array());
$beforeClaim = $claim;
checkScan(!kreabankWorklistScanAdmitted($claim,'company-user-sources','page-a-generation',4,'page-a') && $claim===$beforeClaim, 'An old page cannot mutate a newer page scan');
checkScan(!kreabankWorklistScanAdmitted($claim,'another-company','current',4,'page-b'), 'A different company/source scope is denied');
checkScan(kreabankWorklistScanAdmitted($claim,'company-user-sources','current',4,'page-b'), 'The current rendered view and generation are admitted');
checkScan(kreabankWorklistClaimScan($claim), 'The admitted worker claims work under the session lock');
$leased = $claim;
checkScan(!kreabankWorklistClaimScan($claim) && $claim===$leased, 'Concurrent workers cannot claim the same batch or replace its owner');
$claim['lease']['expires'] = time()-1;
checkScan(kreabankWorklistClaimScan($claim) && $claim['lease']['id']!==$leased['lease']['id'], 'An expired abandoned worker can be retried with a new claim');
echo "OK: $checks bounded worklist scan checks\n";
