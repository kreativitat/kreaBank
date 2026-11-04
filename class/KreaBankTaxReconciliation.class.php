<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/** Native tax projections and payment lifecycle, called only by the shared service. */
class KreaBankTaxReconciliation
{
	private $db;
	private $user;
	private $entity;

	public function __construct($db, $user, $entity)
	{
		$this->db = $db;
		$this->user = $user;
		$this->entity = (int) $entity;
	}

	public function canRead()
	{
		return isModEnabled('tax') && $this->user->hasRight('tax', 'charges', 'lire');
	}

	/** Correlated to native bank b and its owned account ba. */
	public static function bankOwnershipSql($p)
	{
		return " AND NOT EXISTS (SELECT 1 FROM {$p}paiementcharge pc LEFT JOIN {$p}chargesociales t ON t.rowid=pc.fk_charge"
			. " WHERE pc.fk_bank=b.rowid AND (t.rowid IS NULL OR t.entity<>ba.entity))"
			. " AND NOT EXISTS (SELECT 1 FROM {$p}bank_url tu LEFT JOIN {$p}paiementcharge pc ON pc.rowid=tu.url_id"
			. " LEFT JOIN {$p}chargesociales t ON t.rowid=pc.fk_charge WHERE tu.fk_bank=b.rowid AND tu.type='payment_sc'"
			. " AND (pc.rowid IS NULL OR pc.fk_bank<>b.rowid OR t.rowid IS NULL OR t.entity<>ba.entity))"
			. " AND NOT EXISTS (SELECT 1 FROM {$p}bank_url tu LEFT JOIN {$p}chargesociales t ON t.rowid=tu.url_id"
			. " WHERE tu.fk_bank=b.rowid AND tu.type='sc' AND (t.rowid IS NULL OR t.entity<>ba.entity"
			. " OR NOT EXISTS (SELECT 1 FROM {$p}paiementcharge pc WHERE pc.fk_charge=t.rowid AND pc.fk_bank=b.rowid)))";
	}

	/** Tax-like evidence plus exact amount/date is advisory, never automatic identity. */
	public function accountReview($line, $range)
	{
		if (!$this->canRead() || (float) $line['amount'] >= 0
			|| !preg_match('/\b(DUC|IVA|IRS|IRC|IUC|IMI|IMT|TSU|tax|taxa|imposto|finan[çc]as|seguran[çc]a\s+social)\b/iu', $line['description'] . ' ' . $line['counterparty_name'])) {
			return array();
		}
		$p = $this->db->prefix();
		$amount = number_format((float) $line['amount'], 8, '.', '');
		$sql = "SELECT b.rowid,b.fk_account,b.amount,b.dateo,b.rappro,ba.ref AS account_ref,ba.label AS account_label,pc.rowid AS payment_id,t.libelle"
			. " FROM {$p}paiementcharge pc JOIN {$p}chargesociales t ON t.rowid=pc.fk_charge"
			. " JOIN {$p}bank b ON b.rowid=pc.fk_bank JOIN {$p}bank_account ba ON ba.rowid=b.fk_account AND ba.entity=t.entity"
			. ' WHERE t.entity=' . $this->entity . ' AND ba.rowid<>' . (int) $line['bank_account_id']
			. " AND ba.currency_code='" . $this->db->escape($line['currency']) . "' AND b.amount=$amount AND pc.amount=ABS(b.amount)"
			. " AND b.dateo>='" . $range['start'] . "' AND b.dateo<='" . $range['end'] . " 23:59:59'"
			. self::bankOwnershipSql($p) . ' ORDER BY b.dateo,b.rowid,pc.rowid' . $this->db->plimit(101);
		return $this->rows($sql);
	}

	private function rows($sql)
	{
		$result = $this->db->query($sql);
		if (!$result) { throw new Exception('Unable to verify native tax records'); }
		$rows = array();
		while ($row = $this->db->fetch_object($result)) { $rows[] = $row; }
		return $rows;
	}

	/** paiementcharge has no entity: its chargesociales parent is the boundary. */
	public function documents($direction, $limit, $range, $accountId)
	{
		global $conf;
		if (!$this->canRead() || (int) $direction > 0) { return array(); }
		$p = $this->db->prefix();
		if ((int) $accountId > 0) {
			$accounts = $this->rows("SELECT currency_code FROM {$p}bank_account WHERE rowid=" . (int) $accountId . ' AND entity=' . $this->entity);
			if (!$accounts || strtoupper($accounts[0]->currency_code) !== strtoupper($conf->currency)) { return array(); }
		}
		$dates = static function ($column) use ($range) {
			return empty($range['enabled']) ? '' : " AND $column >= '" . $range['start'] . "' AND $column <= '" . $range['end'] . " 23:59:59'";
		};
		$remaining = "CAST(t.amount AS DECIMAL(24,8))-COALESCE((SELECT SUM(CAST(pc.amount AS DECIMAL(24,8))) FROM {$p}paiementcharge pc WHERE pc.fk_charge=t.rowid),0)";
		$taxes = $this->rows("SELECT t.rowid,t.rowid AS tax_id,t.fk_type,t.fk_mode_reglement,t.ref,t.libelle,t.date_ech AS doc_date,($remaining) AS amount_open,c.libelle AS type_label"
			. " FROM {$p}chargesociales t LEFT JOIN {$p}c_chargesociales c ON c.id=t.fk_type"
			. ' WHERE t.entity=' . $this->entity . " AND t.paye=0 AND t.amount>0 AND ($remaining)>0"
			. $dates('t.date_ech') . ' ORDER BY t.date_ech,t.rowid' . $this->db->plimit(max(20, (int) $limit)));
		$payments = $this->rows("SELECT pc.rowid,t.rowid AS tax_id,t.fk_type,pc.fk_typepaiement AS fk_mode_reglement,pc.num_paiement AS ref,t.libelle,pc.datep AS doc_date,pc.amount AS amount_open,c.libelle AS type_label"
			. " FROM {$p}paiementcharge pc JOIN {$p}chargesociales t ON t.rowid=pc.fk_charge"
			. " LEFT JOIN {$p}c_chargesociales c ON c.id=t.fk_type WHERE t.entity=" . $this->entity
			. ' AND COALESCE(pc.fk_bank,0)=0 AND pc.amount>0' . $dates('pc.datep')
			. ' ORDER BY pc.datep,pc.rowid' . $this->db->plimit(max(20, (int) $limit)));
		$documents = array();
		foreach (array('tax'=>$taxes, 'payment_tax'=>$payments) as $type=>$rows) {
			foreach ($rows as $row) {
				$documents[] = array('rowid'=>(int) $row->rowid,'doc_type'=>$type,
					'ref'=>trim((string) $row->ref) ?: '#' . $row->rowid,
					'ref_client'=>(string) $row->libelle,'description'=>trim($row->libelle . ' ' . $row->type_label),
					'thirdparty_name'=>(string) $row->libelle,'thirdparty_iban'=>'','fk_soc'=>0,
					'amount_open'=>-(float) $row->amount_open,'doc_date'=>$row->doc_date,'currency'=>$conf->currency,
					'tax_id'=>(int) $row->tax_id,'tax_type_id'=>(int) $row->fk_type,'tax_payment_mode_id'=>(int) $row->fk_mode_reglement);
			}
		}
		return $documents;
	}

	/** Reuse banked payments as native movements; never emit a second candidate. */
	public function decorateMovements($documents)
	{
		if (!$this->canRead()) { return $documents; }
		$ids = array();
		foreach ($documents as $doc) {
			if ($doc['doc_type'] === 'native_bank') { $ids[] = (int) $doc['rowid']; }
		}
		if (!$ids) { return $documents; }
		$p = $this->db->prefix();
		$byBank = array();
		foreach ($this->rows("SELECT pc.fk_bank,pc.rowid AS payment_id,pc.num_paiement,t.rowid AS tax_id,t.libelle,c.libelle AS type_label"
			. " FROM {$p}paiementcharge pc JOIN {$p}chargesociales t ON t.rowid=pc.fk_charge"
			. " JOIN {$p}bank b ON b.rowid=pc.fk_bank JOIN {$p}bank_account ba ON ba.rowid=b.fk_account AND ba.entity=t.entity"
			. " LEFT JOIN {$p}c_chargesociales c ON c.id=t.fk_type WHERE t.entity=" . $this->entity
			. ' AND pc.fk_bank IN (' . implode(',', $ids) . ') ORDER BY pc.rowid') as $row) {
			$byBank[(int) $row->fk_bank][] = $row;
		}
		foreach ($documents as &$doc) {
			if ($doc['doc_type'] !== 'native_bank' || empty($byBank[$doc['rowid']])) { continue; }
			$labels = $refs = array();
			foreach ($byBank[$doc['rowid']] as $row) {
				$labels[] = trim($row->type_label . ' · ' . $row->libelle . ' (#' . $row->tax_id . ')');
				if (trim((string) $row->num_paiement) !== '') { $refs[] = $row->num_paiement; }
			}
			$doc['thirdparty_name'] = implode(' / ', $labels);
			$doc['description'] .= ' ' . $doc['thirdparty_name'];
			$doc['payment_ref'] = trim($doc['payment_ref'] . ' / ' . implode(' / ', $refs), ' /');
		}
		unset($doc);
		return $documents;
	}

	/** An explicit native method is used for the new bank movement and payment. */
	public function paymentModeCode($modeId)
	{
		if (!$this->canRead() || !$this->user->hasRight('tax', 'charges', 'creer')) { throw new Exception('Tax payment permission is required'); }
		$entities = preg_split('/\s*,\s*/', trim((string) getEntity('c_paiement')));
		if (!$entities || array_filter($entities, static function ($v) { return !ctype_digit($v); })) { throw new Exception('Invalid payment dictionary scope'); }
		$modes = $this->rows('SELECT code FROM ' . $this->db->prefix() . 'c_paiement WHERE id=' . (int) $modeId
			. ' AND active=1 AND entity IN (' . implode(',', array_map('intval', $entities)) . ') FOR UPDATE');
		if (!$modes || !preg_match('/^[a-z0-9_]{1,6}$/iD', $modes[0]->code)) {
			throw new Exception('Select an active default payment method on the tax before reconciliation');
		}
		return (string) $modes[0]->code;
	}

	/** Caller owns the account/source locks and outer reconciliation transaction. */
	public function attach($type, $id, $line, $amount, $native, $note, $expected)
	{
		global $conf;
		if (!$this->canRead() || !$this->user->hasRight('tax', 'charges', 'creer') || (int) $conf->entity !== $this->entity) {
			throw new Exception('Tax payment permission is required in the current company');
		}
		if ((float) $line['amount'] >= 0 || strtoupper($line['currency']) !== strtoupper($conf->currency)) {
			throw new Exception('Tax payments require a debit in company currency');
		}
		require_once DOL_DOCUMENT_ROOT . '/compta/sociales/class/chargesociales.class.php';
		require_once DOL_DOCUMENT_ROOT . '/compta/sociales/class/paymentsocialcontribution.class.php';
		$p = $this->db->prefix();
		$id = (int) $id;
		$accounts = $this->rows("SELECT currency_code FROM {$p}bank_account WHERE rowid=" . (int) $line['bank_account_id'] . ' AND entity=' . $this->entity);
		if (!$accounts || strtoupper($accounts[0]->currency_code) !== strtoupper($conf->currency)) { throw new Exception('Tax bank account currency changed'); }
		$taxId = $id;
		if ($type === 'payment_tax') {
			$parents = $this->rows("SELECT fk_charge FROM {$p}paiementcharge WHERE rowid=$id");
			$taxId = (int) ($parents[0]->fk_charge ?? 0);
		}
		$taxes = $this->rows("SELECT * FROM {$p}chargesociales WHERE rowid=$taxId AND entity=" . $this->entity . ' FOR UPDATE');
		if (count($taxes) !== 1) { throw new Exception('Tax is unavailable in the current company'); }
		$tax = $taxes[0];
		if ((int) ($expected['tax_id'] ?? 0) !== $taxId || (int) ($expected['tax_type_id'] ?? 0) !== (int) $tax->fk_type
			|| ($type === 'tax' && (int) ($expected['tax_payment_mode_id'] ?? 0) !== (int) $tax->fk_mode_reglement)) {
			throw new Exception('Tax identity or payment method changed; review the tax again');
		}
		$payments = $this->rows("SELECT * FROM {$p}paiementcharge WHERE fk_charge=$taxId ORDER BY rowid FOR UPDATE");
		$payment = new PaymentSocialContribution($this->db);
		if ($type === 'tax') {
			$remainingRows = $this->rows("SELECT CAST(t.amount AS DECIMAL(24,8))-COALESCE((SELECT SUM(CAST(pc.amount AS DECIMAL(24,8))) FROM {$p}paiementcharge pc WHERE pc.fk_charge=t.rowid),0) AS remaining FROM {$p}chargesociales t WHERE t.rowid=$taxId");
			$remaining = $remainingRows[0]->remaining;
			$this->assertDisplayed($expected, trim((string) $tax->ref) ?: '#' . $taxId, $tax->libelle, $tax->date_ech, $remaining);
			$amountDecimal = number_format($amount, 8, '.', '');
			$withinBalance = $this->rows("SELECT CAST('$amountDecimal' AS DECIMAL(24,8)) <= CAST('$remaining' AS DECIMAL(24,8)) AS valid");
			if ((int) $tax->paye !== 0 || $amount <= 0 || !(int) $withinBalance[0]->valid) {
				throw new Exception('Tax remaining amount changed; review the tax again');
			}
			$modeId = (int) $tax->fk_mode_reglement;
			$this->paymentModeCode($modeId);
			$payment->chid = $taxId;
			$payment->datepaye = strtotime($line['operation_date'] . ' UTC');
			if (!$payment->datepaye) { throw new Exception('Tax payment date is invalid'); }
			$payment->amounts = array($taxId=>$amount);
			$payment->paiementtype = $modeId;
			$payment->num_payment = (string) ($line['payment_reference'] ?? '');
			$payment->note = (string) $note;
			if ($payment->create($this->user, 1) <= 0) { throw new Exception('Unable to create native tax payment'); }
			$closed = $this->rows("SELECT paye FROM {$p}chargesociales WHERE rowid=$taxId");
			if (number_format((float) $remaining, 8, '.', '') === $amountDecimal && (int) $closed[0]->paye !== 1) {
				throw new Exception('Native tax paid-state update failed');
			}
		} else {
			$found = null;
			foreach ($payments as $existing) { if ((int) $existing->rowid === $id) { $found = $existing; } }
			if (!$found || (int) ($expected['tax_payment_mode_id'] ?? 0) !== (int) $found->fk_typepaiement || (int) $found->fk_bank > 0 || abs((float) $found->amount - $amount) > 0.00000001 || $payment->fetch($id) <= 0) {
				throw new Exception('Tax payment changed or already has a bank movement');
			}
			$this->assertDisplayed($expected, trim((string) $found->num_paiement) ?: '#' . $id, $tax->libelle, $found->datep, $found->amount);
		}
		if ($payment->update_fk_bank((int) $line['rowid']) <= 0) { throw new Exception('Unable to attach native tax payment'); }
		foreach (array(array($payment->id, '/compta/payment_sc/card.php?id=', '#' . $payment->id, 'payment_sc'),
			array($taxId, '/compta/sociales/card.php?id=', $tax->libelle, 'sc')) as $link) {
			if ($native->addLineLink((int) $line['rowid'], $link[0], DOL_URL_ROOT . $link[1], $link[2], $link[3]) <= 0) {
				throw new Exception('Unable to link native tax payment');
			}
		}
	}

	private function assertDisplayed($expected, $ref, $label, $date, $amount)
	{
		if ((string) $expected['doc_ref'] !== (string) $ref || (string) $expected['thirdparty_name'] !== (string) $label
			|| (string) $expected['doc_date'] !== (string) $date
			|| number_format(-(float) $expected['amount_open'], 8, '.', '') !== number_format((float) $amount, 8, '.', '')) {
			throw new Exception('Tax record changed; review the tax again');
		}
	}
}
