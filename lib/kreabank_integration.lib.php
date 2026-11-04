<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
require_once __DIR__ . '/../class/kreabankintegration.class.php';

/** Shared source snapshot presentation for the overview and movement summary. */
function kreabankAccountFeedDisplay($row)
{
	global $langs;
	$balance = $row->feed_balance === null ? '—' : kreabankPrice($row->feed_balance);
	$updated = !empty($row->bank_updated_at) ? strtotime($row->bank_updated_at) : false;
	$state = $row->feed_status ?: 'unmapped';
	if ($state === 'ready' && (!$updated || dol_now() - $updated > 129600)) {
		$state = 'stale';
	}
	$status = '';
	if ($state !== 'ready') {
		$states = array('stale', 'failed', 'refresh_wait', 'mapping_review', 'unavailable', 'unmapped');
		$status = dol_escape_htmltag($langs->trans('KreaBankFeedState_' . (in_array($state, $states, true) ? $state : 'failed')));
	}
	return array('balance' => $balance, 'status' => $status, 'updated' => $updated ? dol_print_date($updated, 'dayhour') : '—');
}

/** Source balance and freshness remain distinct from native ledger amounts. */
function kreabankAccountFeedCells($row)
{
	$feed = kreabankAccountFeedDisplay($row);
	return '<td class="kb-money">' . $feed['balance'] . ($feed['status'] !== '' ? '<small>' . $feed['status'] . '</small>' : '')
		. '</td><td>' . $feed['updated'] . '</td>';
}

/** Selected-account balances are independent of movement filters and pagination. */
function kreabankMovementBalances($accountId)
{
	global $db, $conf, $langs;
	if ((int) $accountId <= 0) {
		return '';
	}
	$report = new KreaBankIntegration($db, $conf->entity);
	$row = $db->fetch_object($report->accounts('all', $accountId, 'ref', 'ASC', 1, 0, true));
	if (!$row) {
		return '';
	}
	$feed = kreabankAccountFeedDisplay($row);
	$currency = ' <span class="kb-balance-currency">' . dol_escape_htmltag($row->currency_code) . '</span>';
	$html = '<dl class="kb-movement-balances"><div><dt>' . $langs->trans('KreaBankLedgerBalance') . '</dt>'
		. '<dd>' . kreabankPrice($row->balance) . $currency . '</dd></div>';
	$html .= '<div><dt>' . $langs->trans('KreaBankFeedBalance') . '</dt><dd>' . $feed['balance'] . ($row->feed_balance !== null ? $currency : '')
		. ($feed['status'] !== '' ? '<small>' . $feed['status'] . '</small>' : '') . '</dd></div>';
	return $html . '<div><dt>' . $langs->trans('KreaBankFeedBankUpdated') . '</dt><dd class="kb-balance-updated">' . $feed['updated'] . '</dd></div></dl>';
}

/** Feed bank_reference holds the provider's external ID, not an operator reference. */
function kreabankStatementReferences($line, $sourceType = '')
{
	$references = array($line->payment_reference);
	if (($sourceType ?: ($line->source_type ?? '')) !== 'fintable') {
		$references[] = $line->bank_reference;
	}
	return array_unique(array_filter($references, static function ($value) { return $value !== null && $value !== ''; }));
}

function kreabankStatementUrl($accountId = 0, $reference = '')
{
	return dol_buildpath('/custom/kreabank/statement.php', 1) . '?mainmenu=bank&leftmenu=kreabank_import'
		. ((int) $accountId > 0 ? '&account_context=' . (int) $accountId : '')
		. ($reference !== '' ? '&ref=' . rawurlencode($reference) : '');
}

/** Preserve the existing boolean matched API; 2 explicitly selects unreconciled. */
function kreabankNativeEntriesUrl($accountId = 0, $matched = false, $reference = '')
{
	$filter = in_array($matched, array(true, 1, 2), true) ? (int) $matched : 0;
	return dol_buildpath('/compta/bank/bankentries_list.php', 1) . '?mainmenu=bank'
		. ((int) $accountId > 0 ? '&id=' . (int) $accountId : '')
		. ($filter ? '&kreabank_matched=' . $filter : '')
		. ($reference !== '' ? '&search_num_releve=' . rawurlencode($reference) : '');
}

/** Explicit allowlist keeps unknown filter values in the ordinary movements view. */
function kreabankMovementFilter()
{
	$filter = GETPOSTINT('kreabank_matched');
	return in_array($filter, array(1, 2), true) ? $filter : 0;
}

/** Only a verified native match is presented as reconciled. */
function kreabankIntegrationLineState($line, $reference)
{
	if ((int) $line->status === 2 || (int) $line->expected_native_id > 0) {
		if (empty($line->native_id) || (string) $line->currency !== (string) $line->native_currency || abs((float) $line->amount - (float) $line->native_amount) > 0.01
			|| ((int) $line->status === 2 && ((int) $line->rappro !== 1 || (string) $line->num_releve !== (string) $reference))) {
			return 'KreaBankLinkNeedsReview';
		}
		if ((int) $line->rappro === 1) {
			return 'KreaBankReconciled';
		}
		return 'KreaBankNativeRegistered';
	}
	return (int) $line->status === 3 ? 'KreaBankSkipped' : 'KreaBankPending';
}
