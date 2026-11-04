#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 * ONLY the guarded disposable localhost:33317/kreabank_auto_test fixture.
 */
require __DIR__ . '/test_account_correction_database.php';
require __DIR__ . '/../class/KreaBankReviewIndexes.class.php';
$initialChecks = $checks;
class NavigationQueryDatabase extends DoliDB
{
    public $searchSql = '';
    public function query($sql) {
        if (strpos($sql, ' AS source_amount') !== false && strpos($sql, ' AS special_links') !== false) { $this->searchSql = $sql; }
        return parent::query($sql);
    }
}
$profileDb = new NavigationQueryDatabase();
$profile = new KreaBankService($profileDb, $user, $langs);
stageAuto(85000, '-73.29');
bankAuto(85001, '-73.29', 'SYNTHETIC-PERFORMANCE', 102);
// Old equal-amount movements and nearby different amounts must not widen the lookup.
for ($batch=0; $batch<20; $batch++) {
    $values = array();
    for ($i=0; $i<200; $i++) {
        $id = 86000+$batch*200+$i;
        $amount = $i%2 ? '-73.29' : '-73.30';
        $date = $i%2 ? '2020-01-01' : '2026-11-01';
        $values[] = "($id,102,NOW(),'$date','$date',$amount,'Synthetic performance noise',0)";
    }
    $db->query('INSERT INTO kbauto_bank (rowid,fk_account,datec,dateo,datev,amount,label,rappro) VALUES '.implode(',', $values));
}
$before = $profile->searchOtherAccountMovements(85000);
$maintenance = new KreaBankReviewIndexes($db);
$maintenance->apply(static function () {});
$after = $profile->searchOtherAccountMovements(85000);
checkAuto($before === $after && array_map('intval', array_column($after, 'rowid')) === array(85001), 'Navigation index preserves exact reviewed evidence while excluding unrelated history');
checkAuto($profileDb->searchSql !== '', 'Capture the actual other-account search SQL');
$plan = $db->query('EXPLAIN '.$profileDb->searchSql)->fetch_all(MYSQLI_ASSOC);
$bankPlan = array_values(array_filter($plan, static function ($row) { return $row['select_type'] === 'PRIMARY' && $row['table'] === 'b'; }));
checkAuto(count($bankPlan) === 1 && $bankPlan[0]['key'] === 'idx_kb_bank_amount_date_scope' && in_array($bankPlan[0]['type'], array('ref','range'), true),
    'Next uses the selective amount/date index instead of scanning native movement history: '.json_encode($bankPlan));
echo 'OK: '.($checks-$initialChecks)." reconciliation navigation performance database checks\n";
