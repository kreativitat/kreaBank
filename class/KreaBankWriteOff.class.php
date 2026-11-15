<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
require_once __DIR__ . '/KreaBankDecimal.class.php';

/** Read-only eligibility policy. Only the existing reconciliation service executes approvals. */
final class KreaBankWriteOff
{
	private const ACCOUNTS = array(
		'bank_fee' => 'KREABANK_WRITEOFF_ACCOUNT_BANK_FEE',
		'rounding' => 'KREABANK_WRITEOFF_ACCOUNT_ROUNDING',
		'discount_granted' => 'KREABANK_WRITEOFF_ACCOUNT_DISCOUNT_GRANTED',
		'discount_received' => 'KREABANK_WRITEOFF_ACCOUNT_DISCOUNT_RECEIVED',
	);

	/** Accept native constant values, never defaults which silently enable an incomplete setup. */
	public static function configuration(array $values, $companyCurrency)
	{
		try {
			if ((string) ($values['KREABANK_WRITEOFF_ENABLED'] ?? '') !== '1'
				|| !is_string($companyCurrency) || !preg_match('/^[A-Z]{3}$/D', $companyCurrency)) { return null; }
			$absolute = KreaBankDecimal::normalize($values['KREABANK_WRITEOFF_MAX_ABS'] ?? '');
			$percent = KreaBankDecimal::normalize($values['KREABANK_WRITEOFF_MAX_PERCENT'] ?? '');
			if (KreaBankDecimal::compare($absolute, '0') <= 0 || KreaBankDecimal::compare($percent, '0') <= 0
				|| KreaBankDecimal::compare($percent, '100') > 0) { return null; }
			$accounts = array();
			foreach (self::ACCOUNTS as $reason => $key) {
				$account = $values[$key] ?? '';
				if ((!is_string($account) && !is_int($account)) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,31}$/D', (string) $account)) { return null; }
				$accounts[$reason] = (string) $account;
			}
			return array('enabled' => true, 'currency' => $companyCurrency, 'max_abs' => $absolute,
				'max_percent' => $percent, 'accounts' => $accounts);
		} catch (InvalidArgumentException $e) { return null; }
	}

	/** Reasons retain their native direction: granted for customers, received for suppliers. */
	public static function reasons($docType)
	{
		if ($docType === 'customer_invoice') { return array('bank_fee', 'rounding', 'discount_granted'); }
		if ($docType === 'supplier_invoice') { return array('bank_fee', 'rounding', 'discount_received'); }
		return array();
	}

	public static function reasonDetails(array $config, $docType, $reason)
	{
		if (!in_array($reason, self::reasons($docType), true) || !isset($config['accounts'][$reason])) { return null; }
		return array('reason' => $reason, 'account' => (string) $config['accounts'][$reason],
			'close_code' => $reason === 'bank_fee' ? 'bankcharge' : (strpos($reason, 'discount_') === 0 ? 'discount_vat' : 'other'));
	}

	/**
	 * Inspect persisted decimal evidence. Invalid input is ineligible, without writes or exceptions.
	 * The caller must additionally lock and scope accounts/invoices, verify native rights and
	 * active accounting accounts, and obtain amount_open from CommonInvoice::getRemainToPay().
	 */
	public static function inspect(array $line, array $invoice, array $config, $reason = '')
	{
		try {
			$values = array('KREABANK_WRITEOFF_ENABLED' => ($config['enabled'] ?? false) === true ? '1' : '0',
				'KREABANK_WRITEOFF_MAX_ABS' => $config['max_abs'] ?? '', 'KREABANK_WRITEOFF_MAX_PERCENT' => $config['max_percent'] ?? '');
			foreach (self::ACCOUNTS as $key => $constant) { $values[$constant] = $config['accounts'][$key] ?? ''; }
			$config = self::configuration($values, $config['currency'] ?? '');
			$type = $invoice['doc_type'] ?? '';
			if (!$config || !self::reasons($type) || (int) ($line['entity'] ?? 0) <= 0
				|| (int) ($invoice['entity'] ?? 0) !== (int) $line['entity']
				|| (int) ($line['bank_account_id'] ?? 0) <= 0 || !empty($line['restricted'])
				|| !isset($line['status'], $invoice['status'], $invoice['type'], $invoice['paye'])
				|| (int) $line['status'] !== 0 || (int) $invoice['status'] !== 1 || (int) $invoice['type'] === 2
				|| (int) $invoice['paye'] !== 0 || (int) ($invoice['rowid'] ?? 0) <= 0
				|| ($line['currency'] ?? '') !== $config['currency']
				|| ($invoice['multicurrency_code'] ?? $config['currency']) !== $config['currency']
				|| ($reason !== '' && !in_array($reason, self::reasons($type), true))) { return null; }
			$amount = KreaBankDecimal::normalize($line['amount'] ?? '');
			$open = KreaBankDecimal::normalize($invoice['amount_open'] ?? '');
			$allocation = KreaBankDecimal::normalize($line['allocated_amount'] ?? '0');
			$direction = $type === 'customer_invoice' ? 1 : -1;
			if (KreaBankDecimal::compare($amount, '0') !== $direction || KreaBankDecimal::compare($allocation, '0') !== 0
				|| KreaBankDecimal::compare($open, '0') <= 0
				|| (isset($line['direction']) && (int) $line['direction'] !== 0 && (int) $line['direction'] !== $direction)) { return null; }
			$bank = KreaBankDecimal::abs($amount);
			// Native payments round to company-currency cents. Never silently change source evidence.
			if (!self::centAmount($bank) || !self::centAmount($open)) { return null; }
			$difference = KreaBankDecimal::subtract($open, $bank);
			if (KreaBankDecimal::compare($difference, '0') <= 0
				|| KreaBankDecimal::compare($difference, $config['max_abs']) > 0
				|| KreaBankDecimal::compare(KreaBankDecimal::multiply($difference, '100'), KreaBankDecimal::multiply($open, $config['max_percent'])) > 0) { return null; }
			$details = $reason === '' ? array('reason' => '', 'account' => '', 'close_code' => '') : self::reasonDetails($config, $type, $reason);
			return array('bank_amount' => $bank, 'amount_open' => $open, 'difference' => $difference,
				'reason' => $details['reason'], 'account' => $details['account'],
				'close_code' => $details['close_code'], 'invoice_type' => $type,
				'reasons' => self::reasons($type), 'remaining_status' => $type === 'supplier_invoice' ? 'supplier_partial' : 'customer_closed',
				'config_snapshot' => hash('sha256', json_encode($config)), 'invoice_snapshot' => self::snapshot($invoice));
		} catch (InvalidArgumentException $e) { return null; }
	}

	/** Complete header facts relevant to this approval, including original closure state. */
	public static function snapshot(array $invoice)
	{
		$facts = array();
		foreach (array('doc_type', 'rowid', 'entity', 'ref', 'fk_soc', 'type', 'status', 'paye', 'multicurrency_code', 'close_code', 'close_note', 'tms') as $key) {
			$facts[$key] = (string) ($invoice[$key] ?? '');
		}
		foreach (array('amount_open', 'total_ttc') as $key) {
			$facts[$key] = isset($invoice[$key]) ? KreaBankDecimal::normalize($invoice[$key]) : '';
		}
		return hash('sha256', json_encode($facts));
	}

	/** Bind the already-scoped History projection's audit without constructing an execution service. */
	public static function undoSnapshotFromHistory(array $line)
	{
		$audit = $line['history_audit'] ?? null;
		if (!is_array($audit) || ($audit['audit_type'] ?? '') !== 'reconcile' || (int) ($audit['rowid'] ?? 0) <= 0
			|| (int) ($audit['fk_statement_line'] ?? 0) !== (int) ($line['rowid'] ?? 0)) { return null; }
		$payload = json_decode((string) ($audit['payload_json'] ?? ''), true);
		if (!is_array($payload) || ($payload['strategy'] ?? '') !== 'manual_writeoff'
			|| ($payload['source'] ?? '') !== ($line['line_source'] ?? '')
			|| (int) ($payload['native_bank_line_id'] ?? 0) !== (int) ($line['native_bank_line_id'] ?? 0)
			|| !is_array($payload['writeoff'] ?? null) || empty($payload['writeoff']['payment_id'])
			|| !in_array($payload['writeoff']['invoice_type'] ?? '', array('customer_invoice', 'supplier_invoice'), true)) { return null; }
		return array('audit_id' => (int) $audit['rowid'], 'audit_hash' => hash('sha256', $audit['payload_json']), 'writeoff' => $payload['writeoff']);
	}

	private static function centAmount($value)
	{
		return preg_match('/^[0-9]+(?:\.[0-9]{1,2})?$/D', $value) === 1;
	}
}
