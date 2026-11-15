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

/** Undo may reopen an invoice and remove its payment; invalidate every affected display dependency. */
function kreabankWorklistUndoEffect($key, $line, $review)
{
	if (!is_array($line) || !is_array($review)) { throw new DomainException('Review the reconciliation again before undoing it'); }
	list($source,$id,$accountId)=kreabankWorklistUndoIdentity($key,$line['bank_account_id']??0);
	if ((int)($line['rowid']??0)!==$id || !kreabankWorklistUndoRowMatches($review,$source,$id,$accountId)
		|| ($line['currency']??'')==='' || (string)$line['currency']!==(string)($review['currency']??'')) {
		throw new DomainException('Review the reconciliation again before undoing it');
	}
	$effect=kreabankWorklistActionEffect($key,array('line'=>$line));
	$effect['tokens'][]='native_bank:'.(int)$review['native_bank_line_id'];
	if (array_key_exists('writeoff',$review)) {
		$audit=$review['writeoff'];
		if (!is_array($audit) || !is_array($audit['writeoff']??null)
			|| (!is_int($audit['audit_id']??null) && !is_string($audit['audit_id']??null))
			|| !preg_match('/^[1-9][0-9]{0,9}$/D',(string)($audit['audit_id']??''))
			|| !is_string($audit['audit_hash']??null) || !preg_match('/^[a-f0-9]{64}$/D',$audit['audit_hash'])) {
			throw new DomainException('The write-off undo evidence is incomplete; review it again');
		}
		$writeoff=$audit['writeoff']; $type=$writeoff['invoice_type']??'';
		if (!in_array($type,array('customer_invoice','supplier_invoice'),true)) { throw new DomainException('The write-off undo evidence is incomplete; review it again'); }
		foreach (array('invoice_id','payment_id') as $field) {
			$value=$writeoff[$field]??null;
			if ((!is_int($value) && !is_string($value)) || !preg_match('/^[1-9][0-9]{0,9}$/D',(string)$value)) {
				throw new DomainException('The write-off undo evidence is incomplete; review it again');
			}
		}
		try { $amount=KreaBankPaymentBatchDecimal::normalize($writeoff['amount_open']??''); }
		catch (DomainException $error) { throw new DomainException('The write-off undo evidence is incomplete; review it again'); }
		if ($amount==='0.00000000' || $amount[0]==='-') { throw new DomainException('The write-off undo evidence is incomplete; review it again'); }
		$paymentType=$type==='customer_invoice'?'payment':'payment_supplier';
		$effect['tokens'][]=$type.':'.(int)$writeoff['invoice_id'];
		$effect['tokens'][]=$paymentType.':'.(int)$writeoff['payment_id'];
		$footprint=kreabankWorklistFootprint(array('amount_decimal'=>($type==='supplier_invoice'?'-':'').$amount),$line);
		// The immutable audit retains invoice hashes, not its date. Conservatively
		// refresh equal-amount peers across this bounded scan without inventing a date.
		$footprint['all_dates']=true;
		$effect['footprints'][]=$footprint;
	}
	$effect['tokens']=array_values(array_unique($effect['tokens']));
	return $effect;
}
