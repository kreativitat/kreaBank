#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Actual controller block, synthetic session/service only; no bootstrap or database.
 */
function GETPOST($key, $filter = '') { return $GLOBALS['correctionRequest'][$key] ?? ''; }
function kreabankIsTokenValid() { return $GLOBALS['correctionToken']; }
function accessforbidden($message = '') { throw new RuntimeException('Denied'); }
function setEventMessages(...$args) {}
function runCorrectionController($saved, $request = array(), $permission = true, $method = 'POST', $token = true) {
    $GLOBALS['correctionRequest'] = $request + array('account_move_review'=>'review');
    $GLOBALS['correctionToken'] = $token;
    $_SERVER['REQUEST_METHOD'] = $method;
    $_SESSION = array('kreabank_account_moves'=>array('review'=>$saved));
    $action='change_movement_account'; $lineId=11; $navigationAccountId=101; $correctedBankLineId=0; $openDocumentsSearch='INV-11';
    $user=new class($permission) {
        public $id=7; private $allowed;
        public function __construct($allowed) { $this->allowed=$allowed; }
        public function hasRight(...$path) { return $this->allowed; }
    };
    $conf=(object)array('entity'=>1);
    $langs=new class { public function trans($key) { return $key; } };
    $service=new class {
        public $calls=array();
        public function changeMovementAccount(...$args) { $this->calls[]=$args; }
    };
    $errors=array();
    $reportActionException=static function ($e) use (&$errors) { $errors[]=$e->getMessage(); };
    $source=file_get_contents(__DIR__.'/../reconcile.php');
    $start=strpos($source, 'if ($_SERVER[\'REQUEST_METHOD\'] === \'POST\')');
    $end=strpos($source, "if (\$action === 'batch_safe'",$start);
    try { eval(substr($source,$start,$end-$start)); } catch (Throwable $e) { $errors[]=$e->getMessage(); }
    return array('calls'=>$service->calls,'errors'=>$errors,'session'=>$_SESSION,'corrected'=>$correctedBankLineId,'search'=>$openDocumentsSearch);
}
$valid=array('expires'=>time()+900,'entity'=>1,'user'=>7,'line'=>11,'bank_line'=>501,'old'=>102,'target'=>101,'snapshot'=>str_repeat('a',64));
$checks=0;
function checkCorrection($ok, $message) { global $checks; if (!$ok) { throw new RuntimeException($message); } $checks++; }
foreach (array('expires'=>time()-1,'entity'=>2,'user'=>8,'line'=>12,'target'=>202) as $field=>$value) {
    $invalid=$valid; $invalid[$field]=$value;
    $result=runCorrectionController($invalid);
    checkCorrection(!$result['calls'] && $result['errors'], 'Changed or expired session review cannot authorize a correction: '.$field);
}
foreach (array(array(true,'GET',true),array(true,'POST',false),array(false,'POST',true)) as $case) {
    $result=runCorrectionController($valid,array(),...$case);
    checkCorrection(!$result['calls'] && $result['errors'], 'POST, CSRF and current write permissions are mandatory');
}
$result=runCorrectionController($valid,array('account_move_review'=>'missing'));
checkCorrection(!$result['calls'] && $result['errors'], 'Unknown displayed review is denied');
$result=runCorrectionController($valid);
checkCorrection($result['calls']===array(array(11,501,str_repeat('a',64),102,101)), 'Service receives only server-stored displayed identities');
checkCorrection($result['corrected']===501 && $result['search']==='', 'Success reloads the corrected movement and clears the old text filter');
checkCorrection(empty($result['session']['kreabank_account_moves']), 'Review is consumed once');
echo "OK: $checks account correction controller checks\n";
