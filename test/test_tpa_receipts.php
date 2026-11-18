#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
require_once __DIR__ . '/../class/KreaBankMatcher.class.php';
require_once __DIR__ . '/../class/KreaBankAutomaticTpaTrait.php';
$cases = array(
 'FECHO TPA 0001234567 PERIODO N 007' => '0001234567/007',
 'fecho tpa 01234567 período n 7 SHOP' => '01234567/7',
 'FECHO TPA 0123456/0001234567/007 SHOP' => '0123456/0001234567/007',
 'FECHO TPA 0123456/0001234567/007' => '0123456/0001234567/007',
 'FECHO TPA 1234567 PERIODO N 007' => '1234567/007',
 'TPA' => '', 'TPA 1234567890' => '', 'FECHO TPA 1234567890' => '',
 'FECHO TPA 123456/1234567' => '', 'FECHO TPA 1234567 PERIODO N 7X' => '',
 'COMISSAO FECHO TPA 1234567 PERIODO N 7' => '',
 'FECHO TPA 1234567 PERIODO N 7 ESTORNO' => '',
 'FECHO TPA 1234567 PERIODO N 7 DEVOLUÇÃO' => '',
 'FECHO TPA 1234567 PERIODO N 7 COMISSÃO' => '',
 'TRANSFER TPA 1234567 PERIODO N 7' => '',
);
foreach ($cases as $description => $expected) {
 if (KreaBankMatcher::tpaSettlementIdentity($description) !== $expected) { throw new RuntimeException('Terminal identity classification failed'); }
}
class AutomaticTpaPurePolicy
{
 use KreaBankAutomaticTpaTrait;
 public static function descriptor($value) { return self::automaticTpaDescriptor($value); }
 public static function sameCredit($identity,$amount,$otherIdentity,$otherAmount) { return self::automaticTpaSameCredit($identity,$amount,$otherIdentity,$otherAmount); }
 public function nativeIdentity($candidate,$settlement,$source) { return $this->automaticTpaNativeIdentity($candidate,$settlement,$source); }
}
$policyChecks = 0;
foreach (array('TRF CRED SEPA+ DE UNICRE S.A.', 'trf cred sepa+ de unicre s.a.', " TRF  CRED SEPA+ DE UNICRE S.A. \n") as $description) {
 if (AutomaticTpaPurePolicy::descriptor($description) !== array('kind'=>'unicre','identity'=>'')) { throw new RuntimeException('Exact settlement originator descriptor rejected'); }
 $policyChecks++;
}
foreach (array('UNICRE', 'TRANSFER UNICRE', 'TRF CRED SEPA+ DE UNICRE S.A. CUSTOMER', 'TRF CRED SEPA+ DE SAMPLE UNICRE S.A.',
 'TRF DEB SEPA+ DE UNICRE S.A.', 'TRF CRED SEPA+ DE UNICRE S.A. ESTORNO', 'TRF CRED SEPA+ DE UNICRE SA') as $description) {
 if (AutomaticTpaPurePolicy::descriptor($description) !== null) { throw new RuntimeException('Unproven settlement originator descriptor accepted'); }
 $policyChecks++;
}
foreach (array(
 array('0012345678/007','55.80','0012345678/007','55.80000000',true),
 array('0012345678/007','55.80','0012345678/007','55.81',false),
 array('0012345678/007','55.80','12345678/007','55.80',false),
 array('0012345678/007','9007199254740992.01','0012345678/007','9007199254740992.02',false),
 array('0012345678/007','55.80','0012345678/007','-55.80',false),
 array('0012345678/007','0','0012345678/007','0',false),
) as $case) {
 if (AutomaticTpaPurePolicy::sameCredit($case[0],$case[1],$case[2],$case[3]) !== $case[4]) { throw new RuntimeException('Exact settlement amount policy failed'); }
 $policyChecks++;
}
$policy = new AutomaticTpaPurePolicy();
$candidate = (object) array('label'=>'Receipt / FECHO TPA 0012345678 PERIODO N 7','num_chq'=>'','description'=>'');
if ($policy->nativeIdentity($candidate,array('kind'=>'terminal'),new stdClass()) !== '0012345678/7') { throw new RuntimeException('Native display prefix obscured settlement identity'); }
$candidate->description = 'FECHO TPA 0012345678 PERIODO N 8';
if ($policy->nativeIdentity($candidate,array('kind'=>'terminal'),new stdClass()) !== null) { throw new RuntimeException('Conflicting native identities were accepted'); }
$policyChecks += 2;
foreach (array(
 array('ESTORNO FECHO TPA 0012345678 PERIODO N 7','FECHO TPA 0012345678 PERIODO N 7',''),
 array('FECHO TPA 0012345678 PERIODO N 7','REFUND',''),
 array('FECHO TPA 0012345678 PERIODO N 7','','COMISSÃO FECHO TPA 0012345678 PERIODO N 7'),
 array('FECHO TPA 0012345678 PERIODO N 7','FECHO TPA 0012345678 PERIODO N 8','CANCELAMENTO'),
) as $fields) {
 $candidate=(object)array_combine(array('label','num_chq','description'),$fields);
 if ($policy->nativeIdentity($candidate,array('kind'=>'terminal'),new stdClass())!==null) { throw new RuntimeException('Another field overrode disqualifying native receipt evidence'); }
 $policyChecks++;
}
echo 'OK: ' . count($cases) . ' TPA identity checks, ' . $policyChecks . " exact settlement policy checks\n";
