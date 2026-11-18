#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
define('DOL_DOCUMENT_ROOT', getenv('KREABANK_DOLIBARR_ROOT') ?: dirname(__DIR__,3));
define('DOL_URL_ROOT','');
require_once DOL_DOCUMENT_ROOT . '/core/lib/functions.lib.php';
require_once __DIR__ . '/../class/KreaBankNativeBankAdapter.class.php';
$checks=0;
$check=static function($ok,$label)use(&$checks){if(!$ok){throw new RuntimeException($label);}$checks++;};
foreach(array('', 'PAYMENT-12345678', str_repeat('A',50),str_repeat('á',50))as$reference){
	$check(KreaBankNativeBankAdapter::nativePaymentReference($reference)===$reference,'Native-length references remain exact');
}
$a=str_repeat('SAME-ACCOUNT-',8).'TRANSACTION-A';$b=str_repeat('SAME-ACCOUNT-',8).'TRANSACTION-B';
$short=KreaBankNativeBankAdapter::nativePaymentReference($a);
$check(dol_strlen($short)===50,'Long provider reference fits bank.num_chq');
$check($short!==KreaBankNativeBankAdapter::nativePaymentReference($b),'Distinct references with identical leading text stay distinct');
$check($short===KreaBankNativeBankAdapter::nativePaymentReference($a),'Retries use the same reference');
$check(substr($short,-16)===substr(hash('sha256',$a),0,16),'Bounded reference retains a digest of the complete evidence');
$unicode=KreaBankNativeBankAdapter::nativePaymentReference(str_repeat('á',70));
$check(preg_match('//u',$unicode)===1&&dol_strlen($unicode)===50,'Unicode reference is bounded by native character count');
echo 'OK: '.$checks." native payment-reference checks\n";
