<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
/** Reviewed payment of an existing salary/tax; reconciliation remains a separate action. */
$loaded = false;
foreach (array($_SERVER['CONTEXT_DOCUMENT_ROOT'] ?? '', __DIR__ . '/../..', __DIR__ . '/../../..') as $root) {
	if ($root !== '' && is_file($root . '/main.inc.php')) { $loaded = require $root . '/main.inc.php'; if ($loaded) { break; } }
}
if (!$loaded) { die('Include of main fails'); }
require_once __DIR__ . '/lib/kreabank.lib.php';
require_once __DIR__ . '/lib/kreabank_planned_payment.lib.php';
require_once __DIR__ . '/lib/kreabank_worklist_view.lib.php';
require_once __DIR__ . '/class/KreaBankService.class.php';
require_once __DIR__ . '/class/KreaBankWorklist.class.php';
require_once __DIR__ . '/class/KreaBankPlannedPayment.class.php';
$translate = static function ($key) use ($langs) { return kreabankWorklistTranslate($langs,$key); };
$langs->loadLangs(array('banks','bills','salaries','compta','kreabank@kreabank','worklist@kreabank'));
if (!isModEnabled('kreabank') || !isModEnabled('banque') || !empty($user->socid)
	|| !$user->hasRight('banque','lire') || !$user->hasRight('kreabank','reconciliation','read')
	|| !$user->hasRight('kreabank','reconciliation','write') || !$user->hasRight('banque','modifier')) { accessforbidden(); }
$post = $_SERVER['REQUEST_METHOD'] === 'POST';
$action = GETPOST('action','aZ09');
if ($post && !kreabankIsTokenValid()) { accessforbidden('Invalid token'); }
if ((!$post && !in_array($action,array('','create'),true)) || ($post && $action !== 'add_payment')) { accessforbidden(); }
$popup = GETPOST('dol_openinpopup','aZ09');
if (!is_string($popup) || !preg_match('/^kbwlpay[a-f0-9]{16}$/D',$popup)) { $popup = ''; }
$closePopup = static function () use ($popup) {
	if ($popup !== '') {
		print '<script nonce="' . dol_escape_htmltag(getNonce()) . '">if(window.parent!==window&&window.parent.jQuery){window.parent.jQuery(' . json_encode('#idfordialog'.$popup) . ').dialog("close");}</script>';
	}
};
header('Cache-Control: no-store');
$error = ''; $context = null; $receipt = null; $cancelled = false; $review = GETPOST('review','aZ09');
$service = new KreaBankService($db,$user,$langs);
$payments = new KreaBankPlannedPayment($db,$user,(int) $conf->entity,$service);
try {
	if (!$post && GETPOST('receipt','aZ09') !== '') {
		$receipt = kreabankPlannedPaymentReview(GETPOST('receipt','aZ09'));
		if (!isset($receipt['result'])) { throw new DomainException('KreaBankWorklistChanged'); }
	} else {
		$context = kreabankPlannedPaymentReview($review);
		if (($context['type'] ?? '') !== GETPOST('type','aZ09') || (int) ($context['id'] ?? 0) !== GETPOSTINT('id')
			|| !$payments->permissions($context['type'])) { throw new DomainException('KreaBankWorklistChanged'); }
		if ($post && GETPOSTISSET('cancel')) {
			kreabankPlannedPaymentReview($review,true); $cancelled = true;
		} elseif ($post) {
			// The context is single-use even on a failed native write; reopen to review fresh evidence.
			$context = kreabankPlannedPaymentReview($review,true);
			$result = $payments->create($context,GETPOSTINT('paiementtype'),GETPOST('num_payment','alphanohtml'),GETPOST('note','restricthtml'));
			$key = kreabankPlannedPaymentRemember(array('result'=>$result));
			header('Location: ' . dol_buildpath('/custom/kreabank/recordpayment.php',1) . '?' . http_build_query(array('receipt'=>$key,'dol_hide_topmenu'=>1,'dol_hide_leftmenu'=>1,'dol_openinpopup'=>$popup),'','&',PHP_QUERY_RFC3986),true,303); exit;
		} else {
			$worklist = new KreaBankWorklist($db,(int) $conf->entity,$service);
			$line = $worklist->load('staged:' . (int) $context['source']['rowid']);
			$fresh = $payments->snapshot($line,$context['type'],(int) $context['id']);
			if ($fresh !== $context) { throw new DomainException('KreaBankWorklistChanged'); }
		}
	}
} catch (Throwable $exception) {
	$error = $post && !GETPOSTISSET('cancel') ? 'KreaBankWorklistPaymentFailed' : 'KreaBankWorklistChanged';
	dol_syslog('KreaBank reviewed payment refused: ' . get_class($exception),LOG_WARNING);
}
llxHeader('', $translate('KreaBankWorklistRecordPayment'),'','','','',array(),array('/custom/kreabank/css/planned-payment.css?v=' . filemtime(__DIR__ . '/css/planned-payment.css')));
print load_fiche_titre($translate('KreaBankWorklistRecordPayment'),'','payment');
if ($cancelled) { $closePopup(); }
elseif ($receipt) {
	print '<div class="ok">' . dol_escape_htmltag($translate('KreaBankWorklistPaymentRecorded')) . '</div>';
	$closePopup();
} elseif ($error !== '') {
	print '<div class="error">' . dol_escape_htmltag($translate($error)) . '</div>';
} else {
	$form = new Form($db);
	$modes = array();
	foreach ($payments->paymentModes() as $mode) {
		$label = $translate('PaymentType' . $mode['code']);
		$modes[(int) $mode['id']] = $label !== 'PaymentType' . $mode['code'] ? $label : $mode['label'];
	}
	$e = 'kreabankWorklistEscape';
	print '<form id="kb-wl-payment-form" method="post" action="' . $e(dol_buildpath('/custom/kreabank/recordpayment.php',1)) . '">';
	print kreabankWorklistFields(array('token'=>newToken(),'action'=>'add_payment','review'=>$review,'type'=>$context['type'],'id'=>$context['id'],
		'dol_hide_topmenu'=>1,'dol_hide_leftmenu'=>1,'dol_openinpopup'=>$popup));
	print kreabankPlannedPaymentEvidence($context,$langs);
	print '<div class="fichecenter"><table class="border centpercent kb-wl-payment-fields">';
	print '<tr><td class="titlefield fieldrequired"><label for="paiementtype">' . $e($translate('PaymentMode')) . '</label></td><td>'
		. str_replace(array('<select ','value="-1"'),array('<select required ','value=""'),$form->selectarray('paiementtype',$modes,$context['payment_mode_id'] ?? '',1)) . '</td></tr>';
	print '<tr><td><label for="num_payment">' . $e($translate('Numero')) . '</label></td><td><input type="text" class="flat maxwidth300" id="num_payment" name="num_payment" maxlength="50"></td></tr>';
	print '<tr><td><label for="note">' . $e($translate('Comments')) . '</label></td><td><textarea class="flat centpercent" id="note" name="note" rows="3" maxlength="1000"></textarea></td></tr></table></div>';
	print '<p class="opacitymedium">' . $e($translate('KreaBankWorklistPaymentSeparate')) . '</p>';
	print str_replace('name="cancel"','name="cancel" formnovalidate',$form->buttonsSaveCancel('ToMakePayment','Cancel',array(),false,'',$popup)) . '</form>';
}
llxFooter();
$db->close();
