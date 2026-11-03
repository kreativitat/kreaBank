#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
require __DIR__ . '/../lib/kreabank_statement_list.lib.php';
$checks = 0;
function checkStatementFilter($ok,$message) { global $checks; if (!$ok) { throw new RuntimeException($message); } $checks++; }
$state=kreabankStatementListState(array(),25);
checkStatementFilter($state['limit']===25 && $state['page']===0,'Native configured list size replaces the fixed 50-row page');
$state=kreabankStatementListState(array('page'=>9,'limit'=>15,'search_description'=>'  Test  ','button_search_x'=>'x'),25);
checkStatementFilter($state['page']===0 && $state['limit']===15 && $state['filters']['description']==='Test','Searching starts at page one while preserving size and normalized filters');
$state=kreabankStatementListState(array('pageplusone'=>3,'pageplusoneold'=>1,'button_search_x'=>'x'),25);
checkStatementFilter($state['page']===2,'Native one-based page entry takes precedence over the implicit search button');
$state=kreabankStatementListState(array('page'=>4,'search_status'=>'pending','search_credit'=>'0','button_removefilter_x'=>'x'),25);
checkStatementFilter($state['page']===0 && array_filter($state['filters'])===array(),'Native reset clears every table filter and page');
checkStatementFilter(kreabankStatementListState(array('page'=>-3,'limit'=>999999),25)['limit']===5000,'Requests are bounded to the largest native page-size choice');
checkStatementFilter(kreabankStatementListState(array('search_description'=>array('injected'),'token'=>'secret','kind'=>'all'),25)['filters']['description']==='','Nested request values and unrelated state cannot become filters');
$db=new class { public function escape($value) { return addslashes($value); } };
$where=kreabankStatementListWhere($db,array('debit'=>'>=12,34567890','credit'=>'0','running_balance'=>'-9999999999999999.12345678'));
checkStatementFilter(strpos($where,'>= 12.34567890')!==false && strpos($where,'= -9999999999999999.12345678')!==false,'Decimal filter literals preserve all source precision without float conversion');
checkStatementFilter(strpos($where,'END) = 0')!==false,'Zero is an active credit filter');
$where=kreabankStatementListWhere($db,array('operation_to'=>'2026-11-03'));
checkStatementFilter(strpos($where,"h.operation_date<DATE_ADD('2026-11-03', INTERVAL 1 DAY)")!==false,'End dates include late native timestamps on the selected day');
foreach (array(array('operation_from'=>'2026-02-30'),array('debit'=>'1 OR 1=1'),array('running_balance'=>'!=1; DELETE'),array('status'=>'reconciled OR 1=1')) as $filters) {
	checkStatementFilter(strpos(kreabankStatementListWhere($db,$filters),'AND 1=0')!==false,'Invalid dates, amounts and statuses fail closed');
}
checkStatementFilter(strpos(kreabankStatementListWhere($db,array('description'=>'100%_!')),'100!%!_!!')!==false,'Text filters treat percent, underscore and escape characters literally');
print "OK: $checks bank statement filter and pagination checks\n";
