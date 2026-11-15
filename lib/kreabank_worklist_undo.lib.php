<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
require_once __DIR__.'/kreabank_history.lib.php';
require_once __DIR__.'/kreabank_worklist.lib.php';

/** Explicit source/account identities precede every History lookup and nonce consumption. */
function kreabankWorklistUndoIdentity($key, $accountId)
{
	try { list($source,$id)=kreabankWorklistSource($key); }
	catch (InvalidArgumentException $error) { throw new DomainException('Review the reconciliation again before undoing it'); }
	if ((!is_int($accountId) && !is_string($accountId)) || !preg_match('/^[1-9][0-9]{0,9}$/D',(string)$accountId)) {
		throw new DomainException('Review the reconciliation again before undoing it');
	}
	return array($source,$id,(int)$accountId);
}

/** Groups retain their own signed batch Undo; integrity warnings cannot be bypassed here. */
function kreabankWorklistUndoRowMatches($row, $source, $id, $accountId)
{
	return is_array($row) && (int)($row['rowid']??0)===$id && ($row['line_source']??'')===$source
		&& (int)($row['bank_account_id']??0)===$accountId && (int)($row['native_bank_line_id']??0)>0
		&& (int)($row['rappro']??0)===1 && empty($row['needs_review']) && empty($row['group_id']) && empty($row['is_reversed']);
}

/** Prepare only the exact fresh History row; the opaque native nonce keeps its original snapshot. */
function kreabankWorklistPrepareUndo($history, $key, $accountId)
{
	try { list($source,$id,$accountId)=kreabankWorklistUndoIdentity($key,$accountId); }
	catch (DomainException $error) { return null; }
	$row=$history->find($id,$source,$accountId);
	if (!kreabankWorklistUndoRowMatches($row,$source,$id,$accountId)) { return null; }
	return array('key'=>$key,'snapshot'=>KreaBankHistory::snapshot($row),'nonce'=>kreabankHistoryUndoReview($row));
}

/** Consume once, re-read History, and return the immutable review for the service's locked validation. */
function kreabankWorklistTakeUndo($history, $key, $accountId, $nonce)
{
	list($source,$id,$accountId)=kreabankWorklistUndoIdentity($key,$accountId);
	$review=kreabankHistoryTakeUndoReview($nonce,$accountId);
	if ((int)($review['rowid']??0)!==$id || ($review['line_source']??'')!==$source
		|| (int)($review['bank_account_id']??0)!==$accountId || (int)($review['native_bank_line_id']??0)<=0) {
		throw new DomainException('Review the reconciliation again before undoing it');
	}
	$fresh=$history->find($id,$source,$accountId);
	if (!kreabankWorklistUndoRowMatches($fresh,$source,$id,$accountId) || KreaBankHistory::snapshot($fresh)!==$review) {
		throw new DomainException('The reconciliation changed; review it again');
	}
	return $review;
}
