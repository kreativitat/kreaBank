#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Pure rendering and bounded bulk-review checks. No application bootstrap or database.
 */
require_once __DIR__ . '/../lib/kreabank.lib.php';
require_once __DIR__ . '/../lib/kreabank_bulk.lib.php';
require_once __DIR__ . '/../class/KreaBankMatcher.class.php';
function dol_buildpath($path, $absolute = 0) { return $path; }
function dol_escape_htmltag($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function price($value, ...$options) { return number_format((float) $value, 2, ',', ' '); }
$langs = new class {
	public $keys;
	public function __construct() {
		foreach (file(__DIR__ . '/../langs/pt_PT/kreabank.lang', FILE_IGNORE_NEW_LINES) as $line) {
			if (strpos($line, '=') !== false) { [$key,$value] = explode('=', $line, 2); $this->keys[$key]=$value; }
		}
	}
	public function trans($key) { return $this->keys[$key] ?? $key; }
};
$review = array('line'=>array('rowid'=>17,'bank_account_id'=>101,'bank_account_ref'=>'Banco A',
	'bank_account_label'=>'Conta principal','amount'=>'-231.47','currency'=>'EUR','operation_date'=>'2026-11-01',
	'description'=>'TRF PAYMENT-70001-20261101'), 'truncated'=>false,
	'candidates'=>array(array('payment_type'=>'payment_supplier','payment_id'=>701,'payment_ref'=>'PAY-701',
		'bank_line_id'=>801,'account_ref'=>'Banco B','account_label'=>'Conta registada por engano',
		'reference'=>'PAYMENT-70001-20261101','date'=>'2026-11-01','reconciled'=>false)));
$html = kreabankPaymentAccountReviewHtml($review,$langs);
$checks = 0;
function checkReview($condition, $message) { global $checks; if (!$condition) { throw new RuntimeException($message); } $checks++; }
$matcher = new KreaBankMatcher();
checkReview($matcher->hasCompleteCounterpartyNameMatch(array('description'=>'Trf imediata FABRICA EXEMPLO LDA'), 'AC42 - Fábrica Exemplo, Lda.'), 'Full legal name ignores accents, punctuation and a display code');
checkReview($matcher->hasCompleteCounterpartyNameMatch(array('counterparty_name'=>'Fábrica Exemplo, Lda.'), 'Fabrica Exemplo Lda'), 'Structured counterparty evidence can supply the full name');
checkReview($matcher->hasCompleteCounterpartyNameMatch(array('description'=>'TRANSFER 東京 商事'), '東京 商事'), 'Complete Unicode names retain matching coverage');
foreach (array('FABRICA', 'FABRICA EXEMPLO2 LDA', 'XFABRICA EXEMPLO LDA', 'FABRICA EXEMPLO LDAX') as $text) {
	checkReview(!$matcher->hasCompleteCounterpartyNameMatch(array('description'=>$text), 'Fábrica Exemplo, Lda.'), 'Partial names and prefixes cannot imply an account mismatch');
}
checkReview(!$matcher->hasCompleteCounterpartyNameMatch(array('description'=>'TRANSFER'), 'Transfer'), 'Generic single-word labels cannot identify a counterparty');
checkReview($matcher->getSafeSuggestion(array(array('score'=>200,'details'=>array('amount','date','name_description'))), 150) === null, 'Even a high name-only score cannot authorize unattended matching');
checkReview(strpos($html,'Banco A')!==false && strpos($html,'Banco B')!==false, 'Both account names appear in the warning');
checkReview(strpos($html,'/fourn/paiement/card.php?id=701')!==false && strpos($html,'/compta/bank/line.php?rowid=801')!==false, 'Supplier payment and native correction links preserve identity');
checkReview(strpos($html,'account_context=101')!==false && strpos($html,'-231,47 EUR')!==false, 'Statement context and signed localized amount are visible');
checkReview(strpos($html,'checkbox')===false && strpos($html,'<form')===false, 'Review card offers no bulk approval or financial mutation');
$tainted = $review;
$tainted['candidates'][0]['account_label'] = '<script>alert(1)</script>';
$tainted['line']['description'] = '<img src=x onerror=alert(1)>';
$taintedHtml = kreabankPaymentAccountReviewHtml($tainted,$langs);
checkReview(strpos($taintedHtml,'<script>')===false && strpos($taintedHtml,'<img')===false && strpos($taintedHtml,'&lt;script&gt;')!==false, 'Payment and statement evidence is escaped');
$customer = $review;
$customer['candidates'][0]['payment_type']='payment';
$customer['candidates'][0]['reconciled']=true;
$customer['truncated']=true;
$customerHtml = kreabankPaymentAccountReviewHtml($customer,$langs);
checkReview(strpos($customerHtml,'/compta/paiement/card.php?id=701')!==false, 'Customer payment links use the native customer payment card');
checkReview(strpos($customerHtml,'Já reconciliado')!==false && strpos($customerHtml,'lista pode estar incompleta')!==false, 'Locked and truncated evidence stays explicit');
$source = array(array('rowid'=>17,'status'=>0));
$prepare = static function ($line) use ($review) {
	return array('line'=>$line,'account_review'=>$review,'suggestion'=>array('doc_type'=>'account_review','doc_id'=>0),
		'allocation'=>0,'preselected'=>false,'is_bulk_safe'=>false);
};
$state = kreabankAdvanceBulkScan(array('done'=>false,'next_offset'=>0,'matched_rows'=>array()),1,20,1000,0,20,
	static function () use ($source) { return $source; },static function () use ($source) { return $source[0]; },$prepare);
checkReview($state['done'] && count($state['matched_rows'])===1 && $state['display_rows'][17]['account_review']===$review,
	'Bounded bulk scanning retains review-only lines even without an ordinary suggestion');
checkReview(!kreabankBulkReviewIsValid($state['display_rows'][17],'test',time()+60,str_repeat('0',64),str_repeat('a',32),time(),true,101),
	'A review-only line cannot authorize a bulk financial write');
if (in_array('--render', $argv, true)) {
	echo '<!doctype html><html lang="pt"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Payment account review fixture</title><style>'
		. 'body{font:15px system-ui;margin:24px;color:#222}.kb-account-review{padding:16px;background:#fff8e8;border:1px solid #d1ad53;border-radius:6px;margin-bottom:20px}h3{margin-top:0}a{color:#155991}table{border-collapse:collapse;width:100%}th,td{text-align:left;padding:10px;border-bottom:1px solid #ddd;vertical-align:top}th{background:#eee}.nowrap{white-space:nowrap}.div-table-responsive{overflow-x:auto}p{line-height:1.5}'
		. '</style><h1>Reconciliação</h1>' . $html . $customerHtml . '</html>';
} else {
	echo 'OK: ' . $checks . " payment account review rendering and bulk checks\n";
}
