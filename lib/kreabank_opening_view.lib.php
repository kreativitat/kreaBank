<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/** A financial review stays server-side and belongs to the exact actor, company and account. */
function kreabankOpeningStoreReview(&$session, $preview, $actor, $entity, $now)
{
	foreach ((array) ($session['kreabank_opening_reviews'] ?? array()) as $key => $stored) {
		if (!is_array($stored) || (int) ($stored['expires'] ?? 0) < $now) { unset($session['kreabank_opening_reviews'][$key]); }
	}
	if ((int) ($preview['actor_id'] ?? 0) !== (int) $actor || (int) ($preview['entity'] ?? 0) !== (int) $entity
		|| (int) ($preview['account_id'] ?? 0) <= 0) { throw new DomainException('KreaBankOpeningReviewChanged'); }
	$nonce = bin2hex(random_bytes(32));
	$session['kreabank_opening_reviews'][$nonce] = array('actor'=>(int) $actor, 'entity'=>(int) $entity,
		'account'=>(int) $preview['account_id'], 'expires'=>min($now + 900, (int) ($preview['expires_at'] ?? 0)), 'preview'=>$preview);
	while (count($session['kreabank_opening_reviews']) > 5) { array_shift($session['kreabank_opening_reviews']); }
	return $nonce;
}

/** Consume before execution, including rejected attempts, so a financial POST cannot be replayed. */
function kreabankOpeningConsumeReview(&$session, $nonce, $actor, $entity, $account, $now)
{
	if (!is_string($nonce) || !preg_match('/^[a-f0-9]{64}$/D', $nonce)) { throw new DomainException('KreaBankOpeningReviewChanged'); }
	$stored = $session['kreabank_opening_reviews'][$nonce] ?? null;
	unset($session['kreabank_opening_reviews'][$nonce]);
	if (!is_array($stored) || (int) ($stored['actor'] ?? 0) !== (int) $actor || (int) ($stored['entity'] ?? 0) !== (int) $entity
		|| (int) ($stored['account'] ?? 0) !== (int) $account || (int) ($stored['expires'] ?? 0) < $now
		|| !is_array($stored['preview'] ?? null)) { throw new DomainException('KreaBankOpeningReviewChanged'); }
	return $stored['preview'];
}

/** Dispatch only after the controller verifies native authorization, POST and CSRF. */
function kreabankOpeningHandleAction($engine, &$session, $action, $input, $actor, $entity, $now)
{
	if ($action === 'install') {
		$engine->install();
		return array('message'=>'KreaBankOpeningInstalled', 'redirect'=>true);
	}
	if ($action === 'preview') {
		$preview = $engine->preview((int) ($input['account_id'] ?? 0), (string) ($input['start_date'] ?? ''),
			(string) ($input['opening_balance'] ?? ''), (string) ($input['statement_ref'] ?? ''), (string) ($input['evidence_ref'] ?? ''));
		return array('review'=>$preview, 'review_nonce'=>kreabankOpeningStoreReview($session,$preview,$actor,$entity,$now));
	}
	if ($action === 'apply' || $action === 'cancel') {
		$preview = kreabankOpeningConsumeReview($session, $input['review_nonce'] ?? '', $actor, $entity, (int) ($input['account_id'] ?? 0), $now);
		if ($action === 'cancel') { return array('redirect'=>true); }
		if (($input['confirmed'] ?? '') !== '1') { throw new DomainException('KreaBankOpeningReviewChanged'); }
		$engine->apply($preview);
		return array('message'=>'KreaBankOpeningApplied', 'redirect'=>true);
	}
	throw new DomainException('KreaBankOpeningReviewChanged');
}

/** Native setup tables and controls; rendering never initializes storage or writes records. */
function kreabankOpeningPageHtml($state, $langs)
{
	$escape = static function ($value) { return dol_escape_htmltag((string) $value); };
	$t = static function ($key) use ($langs) { return $langs->trans($key); };
	$url = $escape($state['url']);
	$hidden = static function ($action = '') use ($state, $escape) {
		return '<input type="hidden" name="token" value="' . $escape($state['token']) . '">'
			. ($action !== '' ? '<input type="hidden" name="action" value="' . $escape($action) . '">' : '');
	};
	$amount = static function ($value, $currency) use ($escape, $langs) {
		return '<span title="' . $escape($langs->trans('KreaBankOpeningExactAmount',(string) $value)) . '">'
			. kreabankPrice($value) . '</span> ' . $escape($currency);
	};
	$html = '<section class="kb-opening" aria-labelledby="kb-opening-intro"><p id="kb-opening-intro">' . $t('KreaBankOpeningPurpose') . '</p>';
	if (empty($state['available'])) {
		return $html . '<p>' . $t('KreaBankOpeningSchemaMissing') . '</p><form method="post" action="' . $url . '">'
			. $hidden('install') . '<button class="button button-save" type="submit">' . $t('KreaBankOpeningInstall') . '</button></form></section>';
	}
	$html .= '<h3>' . $t('KreaBankOpeningOverview') . '</h3>';
	if (!$state['openings']) { $html .= '<p class="opacitymedium">' . $t('KreaBankOpeningEmpty') . '</p>'; }
	else {
		$html .= '<div class="div-table-responsive"><table class="liste centpercent"><thead><tr class="liste_titre">';
		foreach (array('BankAccount','KreaBankOpeningStartDate','KreaBankOpeningBalance','KreaBankOpeningAdjustment','KreaBankOpeningEvidenceRef','KreaBankOpeningAppliedAt') as $key) {
			$html .= '<th scope="col"' . (in_array($key,array('KreaBankOpeningBalance','KreaBankOpeningAdjustment'),true) ? ' class="right"' : '') . '>' . $t($key) . '</th>';
		}
		$html .= '</tr></thead><tbody>';
		foreach ($state['openings'] as $row) {
			$html .= '<tr class="oddeven"><td>' . $escape($row['account_ref'] . ' — ' . $row['account_label']) . '</td><td class="nowrap">' . $escape($row['start_date'])
				. '</td><td class="right nowrap">' . $amount($row['opening_balance'],$row['currency']) . '</td><td class="right nowrap">' . $amount($row['adjustment_amount'],$row['currency'])
				. '</td><td>' . $escape($row['evidence_ref']) . '<br><span class="opacitymedium">' . $escape($row['statement_ref']) . '</span></td><td class="nowrap">' . $escape($row['datec']) . '</td></tr>';
		}
		$html .= '</tbody></table></div><p class="opacitymedium">' . $t('KreaBankOpeningImmutable') . '</p>';
	}
	if (!empty($state['review'])) {
		$r = $state['review'];
		$html .= '<h3 id="kb-opening-review-title">' . $t('KreaBankOpeningReview') . '</h3><div class="div-table-responsive"><table class="border centpercent" style="table-layout:fixed" aria-labelledby="kb-opening-review-title"><tbody>';
		$rows = array('BankAccount'=>$escape($r['account']['ref'] . ' — ' . $r['account']['label']),
			'KreaBankOpeningStartDate'=>$escape($r['start_date']), 'KreaBankOpeningBalanceDate'=>$escape($r['balance_date']),
			'KreaBankOpeningBalance'=>$amount($r['opening_balance'],$r['currency']), 'KreaBankOpeningLedger'=>$amount($r['ledger_balance'],$r['currency']),
			'KreaBankOpeningAdjustment'=>'<strong>' . $amount($r['adjustment_amount'],$r['currency']) . '</strong>',
			'KreaBankOpeningNativeCount'=>(int) $r['native_count'], 'KreaBankOpeningNativePending'=>(int) $r['native_unreconciled_count'],
			'KreaBankOpeningStagedCount'=>(int) $r['staged_count'], 'KreaBankOpeningStatementRef'=>$escape($r['statement_ref']), 'KreaBankOpeningEvidenceRef'=>$escape($r['evidence_ref']));
		foreach ($rows as $key => $value) { $html .= '<tr><th scope="row" class="titlefield left">' . $t($key) . '</th><td class="wordbreak">' . $value . '</td></tr>'; }
		$html .= '</tbody></table></div><p class="opacitymedium">' . $t('KreaBankOpeningAdjustmentHelp') . '</p><form method="post" action="' . $url . '">'
			. $hidden() . '<input type="hidden" name="review_nonce" value="' . $escape($state['review_nonce']) . '"><input type="hidden" name="account_id" value="' . (int) $r['account_id'] . '">'
			. '<p><input type="checkbox" id="kb-opening-confirmed" name="confirmed" value="1" required> <label for="kb-opening-confirmed">' . $t('KreaBankOpeningConfirm') . '</label></p>'
			. '<div class="center"><button class="button button-save" type="submit" name="action" value="apply">' . $t('KreaBankOpeningApply') . '</button>'
			. '<button class="button button-cancel" type="submit" name="action" value="cancel" formnovalidate>' . $t('Cancel') . '</button></div></form>';
		return $html . '</section>';
	}
	if (!$state['accounts']) { return $html . '<p>' . $t('KreaBankOpeningNoAccounts') . '</p></section>'; }
	$v = $state['values'];
	$html .= '<h3>' . $t('KreaBankOpeningPrepare') . '</h3><form method="post" action="' . $url . '">' . $hidden('preview')
		. '<div class="div-table-responsive"><table class="border centpercent" style="table-layout:fixed"><tbody><tr><td class="titlefield fieldrequired"><label for="kb-opening-account">' . $t('BankAccount') . '</label></td><td>'
		. '<select class="flat centpercent maxwidth500" id="kb-opening-account" name="account_id" required><option value="">—</option>';
	foreach ($state['accounts'] as $account) {
		$html .= '<option value="' . (int) $account['rowid'] . '"' . ((int) $v['account_id'] === (int) $account['rowid'] ? ' selected' : '') . '>'
			. $escape($account['ref'] . ' — ' . $account['label'] . ' (' . $account['currency_code'] . ')') . '</option>';
	}
	$html .= '</select></td></tr>';
	$fields = array('start_date'=>array('KreaBankOpeningStartDate','KreaBankOpeningStartHelp','date','10'),
		'opening_balance'=>array('KreaBankOpeningBalance','KreaBankOpeningBalanceHelp','number','26'),
		'statement_ref'=>array('KreaBankOpeningStatementRef','KreaBankOpeningStatementHelp','text','50'),
		'evidence_ref'=>array('KreaBankOpeningEvidenceRef','KreaBankOpeningEvidenceHelp','text','255'));
	foreach ($fields as $name => $f) {
		$id = 'kb-opening-' . str_replace('_','-',$name);
		$attributes = $name === 'opening_balance' ? ' step="0.01" inputmode="decimal"' : '';
		if ($name === 'start_date') { $attributes .= ' max="' . $escape($state['max_date']) . '"'; }
		$html .= '<tr><td class="titlefield fieldrequired"><label for="' . $id . '">' . $t($f[0]) . '</label></td><td><input class="flat centpercent maxwidth300" id="' . $id
			. '" name="' . $name . '" type="' . $f[2] . '" maxlength="' . $f[3] . '" required value="' . $escape($v[$name]) . '" aria-describedby="' . $id . '-help"' . $attributes
			. '><div id="' . $id . '-help" class="opacitymedium">' . $t($f[1]) . '</div></td></tr>';
	}
	return $html . '</tbody></table></div><div class="center"><button class="button button-save" type="submit">' . $t('KreaBankOpeningPreview') . '</button></div></form></section>';
}
