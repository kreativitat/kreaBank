<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
require_once __DIR__ . '/../class/kreabankintegration.class.php';

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
