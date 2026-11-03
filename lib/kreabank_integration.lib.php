<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
require_once __DIR__ . '/../class/kreabankintegration.class.php';

/** Source balance and freshness remain distinct from native ledger amounts. */
function kreabankAccountFeedCells($row)
{
	global $langs;
	$balance = $row->feed_balance === null ? '—' : kreabankPrice($row->feed_balance);
	$updated = !empty($row->bank_updated_at) ? strtotime($row->bank_updated_at) : false;
	$state = $row->feed_status ?: 'unmapped';
	if ($state === 'ready' && (!$updated || dol_now() - $updated > 129600)) {
		$state = 'stale';
	}
	$html = '<td class="kb-money">' . $balance;
	if ($state !== 'ready') {
		$states = array('stale', 'failed', 'refresh_wait', 'mapping_review', 'unavailable', 'unmapped');
		$html .= '<small>' . dol_escape_htmltag($langs->trans('KreaBankFeedState_' . (in_array($state, $states, true) ? $state : 'failed'))) . '</small>';
	}
	return $html . '</td><td>' . ($updated ? dol_print_date($updated, 'dayhour') : '—') . '</td>';
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

function kreabankNativeEntriesUrl($accountId = 0, $matched = false, $reference = '')
{
	return dol_buildpath('/compta/bank/bankentries_list.php', 1) . '?mainmenu=bank'
		. ((int) $accountId > 0 ? '&id=' . (int) $accountId : '')
		. ($matched ? '&kreabank_matched=1' : '')
		. ($reference !== '' ? '&search_num_releve=' . rawurlencode($reference) : '');
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
