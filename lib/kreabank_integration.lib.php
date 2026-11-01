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

/** Same source totals and native links in both workspaces. No staging writes or schema setup. */
function kreabankIntegrationPanel($accountId = 0, $reference = '')
{
	global $db, $conf, $langs, $user;
	if (!$user->hasRight('banque', 'lire') || !$user->hasRight('kreabank', 'reconciliation', 'read')) {
		return '';
	}
	$langs->load('kreabank@kreabank');
	$report = new KreaBankIntegration($db, $conf->entity);
	$result = $report->query('SELECT COALESCE(SUM(imported_lines),0) imported_lines, COALESCE(SUM(native_lines),0) native_lines,'
		. ' COALESCE(SUM(reconciled_lines),0) reconciled_lines, COALESCE(SUM(issue_lines),0) issue_lines FROM (' . $report->statementsSql($accountId) . ') h');
	$row = $db->fetch_object($result);
	$html = '<section class="kreabank-integration" aria-label="KreaBank"><div><strong>KreaBank · ' . $langs->trans('KreaBankConnectedBanking') . '</strong>';
	$html .= '<p>' . $langs->trans('KreaBankLedgerExplanation') . '</p>';
	if (basename($_SERVER['PHP_SELF'] ?? '') === 'bankentries_list.php') {
		$html .= '<p>' . $langs->trans('KreaBankPostingDateExplanation') . '</p>';
		if (GETPOSTINT('kreabank_matched')) {
			$html .= '<p><strong>' . $langs->trans('KreaBankMatchedMovements') . '</strong> · <a href="' . dol_escape_htmltag(kreabankNativeEntriesUrl($accountId)) . '">' . $langs->trans('KreaBankAllNativeMovements') . '</a></p>';
		}
	}
	$html .= '<p>' . $langs->trans('KreaBankIntegrationCounts', (int) $row->imported_lines, (int) $row->native_lines, (int) $row->reconciled_lines) . '</p>';
	if ((int) $accountId > 0) {
		$result = $report->query('SELECT COALESCE(SUM(b.amount),0) balance, ba.currency_code FROM ' . $db->prefix() . 'bank_account ba LEFT JOIN '
			. $db->prefix() . "bank b ON b.fk_account=ba.rowid AND b.dateo<='" . $db->escape($db->idate(dol_now())) . "' WHERE ba.entity=" . (int) $conf->entity . ' AND ba.rowid=' . (int) $accountId . ' GROUP BY ba.rowid,ba.currency_code');
		$balance = $db->fetch_object($result);
		if ($balance) {
			$html .= '<p><strong>' . $langs->trans('KreaBankNativeBalance') . ': ' . price($balance->balance) . ' ' . dol_escape_htmltag($balance->currency_code) . '</strong></p>';
		}
	}
	if ((int) $row->issue_lines > 0) {
		$html .= '<p class="warning"><strong>' . $langs->trans('KreaBankLinkIssues', (int) $row->issue_lines) . '</strong></p>';
	}
	$html .= '</div><nav aria-label="' . dol_escape_htmltag($langs->trans('KreaBankConnectedBanking')) . '">';
	$links = array(
		array(kreabankStatementUrl($accountId, $reference), 'KreaBankImportedStatements'),
		array(kreabankNativeEntriesUrl($accountId), 'KreaBankNativeMovements'),
		array(kreabankNativeEntriesUrl($accountId, true), 'KreaBankMatchedMovements'),
		array(kreabankWorkspaceUrl('reconcile', $accountId), 'KreaBankReconcile'),
	);
	if ((int) $accountId > 0) {
		$links[] = array(dol_buildpath('/compta/bank/releve.php', 1) . '?account=' . (int) $accountId . ($reference !== '' ? '&num=' . rawurlencode($reference) : ''), 'KreaBankNativeStatements');
	}
	foreach ($links as $link) {
		$html .= '<a href="' . dol_escape_htmltag($link[0]) . '">' . $langs->trans($link[1]) . '</a>';
	}
	return $html . '</nav></section>';
}
