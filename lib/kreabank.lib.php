<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

require_once __DIR__ . '/kreabank_navigation.lib.php';

/**
 * Format a displayed amount with localized separators and exactly two decimals.
 * Keep source amounts and reconciliation calculations at their original precision.
 *
 * @param int|float|string|null $amount Amount to display
 * @return string
 */
function kreabankPrice($amount)
{
	return price($amount, 0, '', 0, 2, 2);
}

/** Render current-company evidence only; all corrections use the native bank-line form. */
function kreabankPaymentAccountReviewHtml($review, $langs)
{
	$line = $review['line'];
	$html = '<div class="warning kb-account-review"><h3>' . $langs->trans('KreaBankAccountReviewTitle') . '</h3>';
	$html .= '<p>' . $langs->trans('KreaBankAccountReviewHelp') . '</p>';
	$html .= '<p><strong>' . $langs->trans('KreaBankAccountReviewStatementAccount') . ':</strong> '
		. dol_escape_htmltag(trim($line['bank_account_ref'] . ' — ' . $line['bank_account_label'])) . ' · '
		. kreabankPrice($line['amount']) . ' ' . dol_escape_htmltag($line['currency']) . ' · '
		. dol_escape_htmltag($line['operation_date']) . '<br>' . dol_escape_htmltag($line['description']) . '</p>';
	$html .= '<div class="div-table-responsive"><table class="liste centpercent"><tr class="liste_titre"><th scope="col">'
		. $langs->trans('KreaBankAccountReviewRecordedAccount') . '</th><th scope="col">' . $langs->trans('KreaBankDocument')
		. '</th><th scope="col">' . $langs->trans('KreaBankDate') . '</th><th scope="col">' . $langs->trans('KreaBankAction') . '</th></tr>';
	foreach ($review['candidates'] as $candidate) {
		$paymentPath = $candidate['payment_type'] === 'payment_supplier' ? '/fourn/paiement/card.php?id=' : '/compta/paiement/card.php?id=';
		$html .= '<tr class="oddeven"><td>' . dol_escape_htmltag(trim($candidate['account_ref'] . ' — ' . $candidate['account_label']))
			. '</td><td><a href="' . dol_escape_htmltag(dol_buildpath($paymentPath . (int) $candidate['payment_id'], 1)) . '">'
			. dol_escape_htmltag($candidate['payment_ref']) . '</a><br>' . dol_escape_htmltag($candidate['reference'])
			. '</td><td class="nowrap">' . dol_escape_htmltag(substr($candidate['date'], 0, 10)) . '</td><td><a href="'
			. dol_escape_htmltag(dol_buildpath('/compta/bank/line.php?rowid=' . (int) $candidate['bank_line_id'], 1)) . '">'
			. $langs->trans('KreaBankAccountReviewOpen') . '</a>'
			. ($candidate['reconciled'] ? '<br>' . $langs->trans('KreaBankAccountReviewLocked') : '') . '</td></tr>';
	}
	$html .= '</table></div>';
	if ($review['truncated']) { $html .= '<p>' . $langs->trans('KreaBankAccountReviewTruncated') . '</p>'; }
	$html .= '<p><a href="' . dol_escape_htmltag(dol_buildpath('/custom/kreabank/reconcile.php?line_id=' . (int) $line['rowid']
		. '&account_context=' . (int) $line['bank_account_id'], 1)) . '">' . $langs->trans('KreaBankReconcile') . '</a></p></div>';
	return $html;
}

/**
 * Prepare admin pages header.
 *
 * @return array<array{string,string,string}>
 */
function kreabankAdminPrepareHead()
{
	global $langs, $conf;

	$langs->load('kreabank@kreabank');

	$h = 0;
	$head = array();

	$head[$h][0] = dol_buildpath('/custom/kreabank/admin/setup.php', 1);
	$head[$h][1] = $langs->trans('Settings');
	$head[$h][2] = 'settings';
	$h++;

	$head[$h] = array(dol_buildpath('/custom/kreabank/admin/pairs.php', 1), $langs->trans('KreaBankAccountPairs'), 'pairs');
	$h++;

	$head[$h] = array(dol_buildpath('/custom/kreabank/admin/automation.php', 1), $langs->trans('KreaBankAutomation'), 'automation');
	$h++;

	$head[$h][0] = dol_buildpath('/custom/kreabank/admin/fintable.php', 1);
	$head[$h][1] = $langs->trans('KreaBankFeeds');
	$head[$h][2] = 'fintable';
	$h++;

	$head[$h][0] = dol_buildpath('/custom/kreabank/admin/guide.php', 1);
	$head[$h][1] = $langs->trans('KreaBankGuide');
	$head[$h][2] = 'guide';
	$h++;

	$head[$h][0] = dol_buildpath('/custom/kreabank/admin/about.php', 1);
	$head[$h][1] = $langs->trans('About');
	$head[$h][2] = 'about';
	$h++;

	complete_head_from_modules($conf, $langs, null, $head, $h, 'kreabank@kreabank');
	complete_head_from_modules($conf, $langs, null, $head, $h, 'kreabank@kreabank', 'remove');

	return $head;
}

/**
 * Prepare operational pages header.
 *
 * @return array<array{string,string,string}>
 */
function kreabankPrepareHead($accountId = 0)
{
	global $langs, $user;
	$langs->load('kreabank@kreabank');
	$head = array();
	if ($user->hasRight('kreabank', 'reconciliation', 'read') && $user->hasRight('banque', 'lire')) {
		foreach (array('movements' => 'KreaBankNavMovements', 'statement' => 'KreaBankNavStatements', 'reconcile' => 'KreaBankReconcile') as $page => $label) {
			$head[] = array(kreabankBankingUrl($page, $accountId), $langs->trans($label), $page);
		}
	}
	if ($user->hasRight('banque', 'lire')) {
		$head[] = array(kreabankWorkspaceUrl('feed', $accountId), $langs->trans('KreaBankNavConnections'), 'feed');
	}
	if ($user->hasRight('kreabank', 'reconciliation', 'read') && $user->hasRight('banque', 'lire')) {
		$head[] = array(kreabankWorkspaceUrl('history', $accountId), $langs->trans('KreaBankNavHistory'), 'history');
	}
	return $head;
}

/**
 * Return a css class based on line status.
 *
 * @param int $status 0=pending,1=partial,2=reconciled,3=ignored
 * @return string
 */
function kreabankStatusClass($status)
{
	switch ((int) $status) {
		case 1:
			return 'badge-status2';
		case 2:
			return 'badge-status4';
		case 3:
			return 'badge-status7';
		default:
			return 'badge-status1';
	}
}

/**
 * Return translated label for statement line status.
 *
 * @param int $status
 * @param Translate $langs
 * @return string
 */
function kreabankStatusLabel($status, $langs)
{
	switch ((int) $status) {
		case 1:
			return $langs->trans('KreaBankStatusPartial');
		case 2:
			return $langs->trans('KreaBankStatusReconciled');
		case 3:
			return $langs->trans('KreaBankStatusIgnored');
		default:
			return $langs->trans('KreaBankStatusPending');
	}
}

/**
 * Normalize free text to improve fuzzy matching.
 *
 * @param string $value
 * @return string
 */
function kreabankNormalizeText($value)
{
	$value = trim((string) $value);
	if ($value === '') {
		return '';
	}

	$value = preg_replace('/\s+/', ' ', $value);
	if (function_exists('mb_strtolower')) {
		$value = (string) mb_strtolower((string) $value, 'UTF-8');
	} else {
		$value = strtolower((string) $value);
	}
	$value = dol_string_nospecial((string) $value);
	$value = strtolower((string) $value);
	$value = preg_replace('/\s+/', ' ', (string) $value);

	return trim((string) $value);
}

/**
 * Get configured keywords used to hint batch mode on statement lines.
 *
 * @param string|null $rawKeywords
 * @return array<int,string>
 */
function kreabankGetBatchHintKeywords($rawKeywords = null)
{
	global $conf;

	if ($rawKeywords === null) {
		$rawKeywords = '';
		if (function_exists('getDolGlobalString')) {
			$rawKeywords = (string) getDolGlobalString('KREABANK_BATCH_HINT_KEYWORDS');
		} elseif (!empty($conf->global->KREABANK_BATCH_HINT_KEYWORDS)) {
			$rawKeywords = (string) $conf->global->KREABANK_BATCH_HINT_KEYWORDS;
		}
	}

	$tokens = preg_split('/[\r\n,;]+/', (string) $rawKeywords);
	if (!is_array($tokens)) {
		$tokens = array();
	}

	$keywords = array();
	foreach ($tokens as $token) {
		$token = strtolower(trim((string) $token));
		if ($token === '') {
			continue;
		}
		$keywords[$token] = $token;
	}

	return array_values($keywords);
}

/**
 * Detect batch-like lines using configured hint keywords.
 *
 * @param array<string,mixed> $line
 * @param string|null $rawKeywords
 * @return bool
 */
function kreabankIsBatchLikeLine($line, $rawKeywords = null)
{
	if (!is_array($line) || empty($line)) {
		return false;
	}
	$keywords = kreabankGetBatchHintKeywords($rawKeywords);
	if (empty($keywords)) {
		return false;
	}

	$parts = array(
		(string) (!empty($line['payment_reference']) ? $line['payment_reference'] : ''),
		(string) (!empty($line['bank_reference']) ? $line['bank_reference'] : ''),
		(string) (!empty($line['description']) ? $line['description'] : ''),
		(string) (!empty($line['native_label']) ? $line['native_label'] : ''),
		(string) (!empty($line['native_note']) ? $line['native_note'] : ''),
		(string) (!empty($line['counterparty_name']) ? $line['counterparty_name'] : ''),
	);
	foreach ($parts as $part) {
		$part = strtolower(trim((string) $part));
		if ($part === '') {
			continue;
		}
		foreach ($keywords as $keyword) {
			if ($keyword !== '' && strpos($part, (string) $keyword) !== false) {
				return true;
			}
		}
	}

	return false;
}

/**
 * Validate anti-CSRF token with compatibility across Dolibarr versions.
 *
 * @param string|null $token
 * @return bool
 */
function kreabankIsTokenValid($token = null)
{
	$token = (string) ($token !== null ? $token : GETPOST('token', 'alphanohtml'));
	if ($token === '') {
		return false;
	}

	$currentToken = function_exists('currentToken') ? (string) currentToken() : '';
	$newToken = function_exists('newToken') ? (string) newToken() : '';

	if ($currentToken !== '' && hash_equals($currentToken, $token)) {
		return true;
	}
	if ($newToken !== '' && hash_equals($newToken, $token)) {
		return true;
	}

	return false;
}
