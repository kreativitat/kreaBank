<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/** Private displayed payment facts, bound to this actor and company for fifteen minutes. */
function kreabankPlannedPaymentRemember($context)
{
	global $conf, $user;
	$reviews = $_SESSION['kreabank_planned_payments'] ?? array();
	foreach ($reviews as $key=>$review) {
		if (($review['expires'] ?? 0) < time()) { unset($reviews[$key]); }
	}
	$reviews = array_slice($reviews, -99, null, true);
	$key = bin2hex(random_bytes(24));
	$reviews[$key] = array('entity'=>(int) $conf->entity,'user'=>(int) $user->id,'expires'=>time()+900,'context'=>$context);
	$_SESSION['kreabank_planned_payments'] = $reviews;
	return $key;
}

/** GET can review the same facts; a financial POST consumes them before execution. */
function kreabankPlannedPaymentReview($key, $take = false)
{
	global $conf, $user;
	if (!is_string($key) || !preg_match('/^[a-f0-9]{48}$/D', $key)) { throw new DomainException('KreaBankWorklistChanged'); }
	$review = $_SESSION['kreabank_planned_payments'][$key] ?? null;
	if ($take) { unset($_SESSION['kreabank_planned_payments'][$key]); }
	if (!$review || $review['expires'] < time() || $review['entity'] !== (int) $conf->entity || $review['user'] !== (int) $user->id) {
		throw new DomainException('KreaBankWorklistChanged');
	}
	return $review['context'];
}

/** The URL contains an opaque review identity, never trusted financial form fields. */
function kreabankPlannedPaymentUrl($type, $id, $review)
{
	if (!in_array($type,array('salary','tax'),true) || !is_scalar($id) || !preg_match('/^[1-9][0-9]{0,9}$/D',(string) $id)
		|| !is_string($review) || !preg_match('/^[a-f0-9]{48}$/D',$review)) { return ''; }
	return '/custom/kreabank/recordpayment.php?' . http_build_query(array('action'=>'create','type'=>$type,'id'=>(int) $id,'review'=>$review),'','&',PHP_QUERY_RFC3986);
}

/** Native payment-card evidence; every amount and destination is fixed by the reviewed source. */
function kreabankPlannedPaymentEvidence($context, $langs)
{
	$e = 'kreabankWorklistEscape';
	$rows = array(
		kreabankWorklistTranslate($langs,$context['type'] === 'salary' ? 'Salary' : 'SocialContribution')=>$context['reference'] . ' · ' . $context['label'],
		kreabankWorklistTranslate($langs,'Date')=>kreabankWorklistDate($context['booking_date']),
		kreabankWorklistTranslate($langs,'BankAccount')=>$context['source']['bank_account_ref'],
	);
	$html = '<div class="fichecenter"><table class="border centpercent">';
	foreach ($rows as $label=>$value) { $html .= '<tr><td class="titlefield">' . $e($label) . '</td><td>' . $e($value) . '</td></tr>'; }
	foreach (array('KreaBankWorklistRemaining'=>$context['remaining'],'Amount'=>$context['amount']) as $label=>$amount) {
		$html .= '<tr><td class="titlefield">' . $e(kreabankWorklistTranslate($langs,$label)) . '</td><td>' . kreabankWorklistMoney($amount,$context['currency']) . '</td></tr>';
	}
	return $html . '</table></div><br>';
}
