<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
require_once __DIR__ . '/KreaBankPaymentBatchTrait.php';

/** Read-only evidence for an existing customer transfer receipt and its native movement. */
final class KreaBankCustomerTransfer
{
	const MAX_BANKS = 400;
	const MAX_ALLOCATIONS = 200;
	private $db;
	private $user;
	private $entity;
	private $tables = array();

	public function __construct($db, $user, $entity) { $this->db=$db; $this->user=$user; $this->entity=(int)$entity; }

	/** Transfer receipts are whole existing movements; a cent tolerance cannot authorize pairing. */
	public static function matchesSource($metadata, $line)
	{
		if (!is_array($metadata) || !is_array($line) || (int)($metadata['bank_account_id']??0)<=0
			|| (int)($metadata['bank_account_id']??0)!==(int)($line['bank_account_id']??0)
			|| (string)($metadata['currency']??'')==='' || (string)$metadata['currency']!==(string)($line['currency']??'')) { return false; }
		$amount=$line['amount_decimal']??($line['amount']??'');
		if (is_float($amount)) { if (!is_finite($amount)) { return false; } $amount=number_format($amount,8,'.',''); }
		try { $amount=KreaBankPaymentBatchDecimal::normalize($amount); $expected=KreaBankPaymentBatchDecimal::normalize($metadata['amount']??''); }
		catch (DomainException $error) { return false; }
		return $expected!=='0.00000000' && $expected[0]!=='-' && $amount===$expected;
	}

	/** Extra invoice/customer facts follow current native read permissions, never write authority. */
	public function canRead()
	{
		global $conf;
		return $this->entity>0 && (int)($conf->entity??0)===$this->entity && !empty($this->user->id)
			&& empty($this->user->socid) && (int)($this->user->statut??0)===1
			&& (empty($this->user->entity) || (int)$this->user->entity===$this->entity)
			&& isModEnabled('kreabank') && isModEnabled('banque') && isModEnabled('facture')
			&& $this->user->hasRight('banque','lire') && $this->user->hasRight('kreabank','reconciliation','read')
			&& $this->user->hasRight('facture','lire');
	}

	/** Preserve native identity/eligibility; only verified receipt evidence changes presentation. */
	public function enrich(array $documents)
	{
		$ids=array();
		foreach ($documents as $document) {
			if (($document['doc_type']??'')==='native_bank' && (float)($document['amount_open']??0)>0) { $ids[]=(int)$document['rowid']; }
		}
		$metadata=$this->metadataForBankIds($ids);
		foreach ($documents as &$document) {
			$id=(int)($document['rowid']??0);
			if (($document['doc_type']??'')!=='native_bank' || !isset($metadata[$id])) { continue; }
			$receipt=$metadata[$id];
			$document['ref_client']=implode(', ', $receipt['invoice_refs']);
			$document['payment_ref']=implode(' / ',array_unique(array_filter(array($receipt['ref'],$receipt['number']),static function($value){return $value!=='';})));
			$document['thirdparty_name']=$receipt['customer_name'];
			$document['fk_soc']=$receipt['customer_id'];
			$document['customer_payment_id']=$receipt['payment_id'];
			$document['customer_transfer']=$receipt;
		}
		unset($document);
		return $documents;
	}

	/** Bounded batched lookup; locked callers already own the account/source/native movement locks. */
	public function metadataForBankIds(array $ids, $lock=false)
	{
		global $conf;
		if (!$this->canRead() || !$ids || count($ids)>self::MAX_BANKS) { return array(); }
		foreach ($ids as $id) {
			if ((!is_int($id) && !is_string($id)) || !preg_match('/^[1-9][0-9]{0,9}$/D',(string)$id)) { return array(); }
		}
		$ids=array_values(array_unique(array_map('intval',$ids))); sort($ids,SORT_NUMERIC);
		if (!$this->hasTable('c_paiement')) { return array(); }
		$modeEntities=preg_split('/\s*,\s*/',trim((string)getEntity('c_paiement')));
		if (!$modeEntities || array_filter($modeEntities,static function($id){return !ctype_digit($id);})) { return array(); }
		$p=$this->db->prefix(); $e=$this->entity; $suffix=$lock?' FOR UPDATE':'';
		$sql="SELECT b.rowid AS bank_line_id,b.fk_account AS bank_account_id,CAST(b.amount AS DECIMAL(24,8)) AS bank_amount,b.dateo AS bank_date,b.fk_type,b.rappro,"
			. "ba.entity AS account_entity,ba.currency_code AS currency,ba.clos,ba.rappro AS account_reconcilable,"
			. "pay.*,CAST(pay.amount AS DECIMAL(24,8)) AS payment_amount,c.code AS mode_code,c.entity AS mode_entity"
			. " FROM {$p}bank b JOIN {$p}bank_account ba ON ba.rowid=b.fk_account JOIN {$p}paiement pay ON pay.fk_bank=b.rowid"
			. " JOIN {$p}c_paiement c ON c.id=pay.fk_paiement WHERE ba.entity=$e AND pay.entity=$e"
			. ' AND b.rowid IN ('.implode(',',$ids).") AND b.amount>0 AND pay.amount>0 AND b.fk_type IN ('TRF','VIR') AND c.code IN ('TRF','VIR')"
			. ' AND c.entity IN ('.implode(',',array_map('intval',$modeEntities)).')'
			. ' AND ba.currency_code='.$this->text((string)$conf->currency)
			. " AND NOT EXISTS (SELECT 1 FROM {$p}paiement otherpay WHERE otherpay.fk_bank=b.rowid AND otherpay.rowid<>pay.rowid)"
			. " AND NOT EXISTS (SELECT 1 FROM {$p}bank_url u WHERE u.fk_bank=b.rowid AND (u.type NOT IN ('payment','company') OR (u.type='payment' AND u.url_id<>pay.rowid)))"
			. " AND (SELECT COUNT(*) FROM {$p}paiement_facture counted WHERE counted.fk_paiement=pay.rowid) BETWEEN 1 AND ".self::MAX_ALLOCATIONS;
		foreach (array('paiementfourn','paiementcharge','payment_salary','payment_various','payment_expensereport','payment_donation','payment_loan','payment_vat') as $table) {
			if ($this->hasTable($table)) { $sql.=" AND NOT EXISTS (SELECT 1 FROM {$p}{$table} otherkind WHERE otherkind.fk_bank=b.rowid)"; }
		}
		$receipts=$this->rows($sql.' ORDER BY b.rowid,pay.rowid'.$suffix);
		if (!$receipts) { return array(); }
		$paymentIds=array_map('intval',array_column($receipts,'rowid'));
		$visibility='';
		if (!$this->user->hasRight('societe','client','voir')) {
			if (!$this->hasTable('societe_commerciaux')) { return array(); }
			$visibility=",EXISTS (SELECT 1 FROM {$p}societe_commerciaux sc WHERE sc.fk_soc=s.rowid AND sc.fk_user=".(int)$this->user->id.') AS customer_visible';
		}
		$allocations=$this->rows("SELECT a.*,CAST(a.amount AS DECIMAL(24,8)) AS exact_amount,f.rowid AS invoice_id,f.entity AS invoice_entity,f.ref AS invoice_ref,"
			. "f.fk_soc,f.type AS invoice_type,f.fk_statut AS invoice_status,f.paye AS invoice_paid,CAST(f.total_ttc AS DECIMAL(24,8)) AS invoice_total,"
			. "f.multicurrency_code AS invoice_currency,f.multicurrency_total_ttc AS invoice_multicurrency_total,"
			. "s.rowid AS customer_id,s.entity AS customer_entity,s.nom AS customer_name$visibility"
			. " FROM {$p}paiement_facture a LEFT JOIN {$p}facture f ON f.rowid=a.fk_facture LEFT JOIN {$p}societe s ON s.rowid=f.fk_soc"
			. ' WHERE a.fk_paiement IN ('.implode(',',$paymentIds).') ORDER BY a.fk_paiement,a.rowid'
			. $this->db->plimit(self::MAX_BANKS*self::MAX_ALLOCATIONS+1).$suffix);
		if (count($allocations)>self::MAX_BANKS*self::MAX_ALLOCATIONS) { return array(); }
		$byPayment=array();
		foreach ($allocations as $allocation) { $byPayment[(int)$allocation['fk_paiement']][]=$allocation; }
		$out=array();
		foreach ($receipts as $receipt) {
			$members=$byPayment[(int)$receipt['rowid']]??array();
			try { $metadata=$this->metadata($receipt,$members); }
			catch (DomainException $error) { continue; }
			if ($metadata) { $out[(int)$receipt['bank_line_id']]=$metadata; }
		}
		return $out;
	}

	private function metadata($receipt, $allocations)
	{
		$amount=KreaBankPaymentBatchDecimal::normalize($receipt['bank_amount']);
		if ($amount==='0.00000000' || $amount[0]==='-' || $amount!==KreaBankPaymentBatchDecimal::normalize($receipt['payment_amount'])
			|| !$allocations || count($allocations)>self::MAX_ALLOCATIONS) { return null; }
		$currency=(string)$receipt['currency']; $customer=0; $name=''; $refs=array(); $amounts=array(); $invoices=array();
		foreach ($allocations as $allocation) {
			if ((int)$allocation['invoice_id']<=0 || (int)$allocation['invoice_entity']!==$this->entity || (int)$allocation['invoice_type']===2
				|| !in_array((int)$allocation['invoice_status'],array(1,2),true) || isset($invoices[(int)$allocation['invoice_id']])
				|| (int)$allocation['customer_id']<=0 || (int)$allocation['customer_entity']!==$this->entity
				|| (isset($allocation['customer_visible']) && !(int)$allocation['customer_visible'])
				|| !in_array((string)$allocation['invoice_currency'],array('',$currency),true)
				|| !in_array((string)($allocation['multicurrency_code']??''),array('',$currency),true)) { return null; }
			$allocated=KreaBankPaymentBatchDecimal::normalize($allocation['exact_amount']);
			if ($allocated==='0.00000000' || $allocated[0]==='-' || ($customer && $customer!==(int)$allocation['customer_id'])) { return null; }
			if (!$this->sameCurrencyAmount($allocation['multicurrency_amount']??'0',$allocated)) { return null; }
			$customer=(int)$allocation['customer_id']; $name=trim((string)$allocation['customer_name']);
			$invoices[(int)$allocation['invoice_id']]=true;
			$ref=trim((string)$allocation['invoice_ref']);
			if ($ref==='' || strlen($ref)>256 || $name==='' || strlen($name)>256) { return null; }
			$refs[]=$ref; $amounts[]=$allocated;
		}
		$ref=trim((string)$receipt['ref']); $number=trim((string)$receipt['num_paiement']);
		if (strlen($ref)>256 || strlen($number)>256 || ($ref==='' && $number==='')
			|| KreaBankPaymentBatchDecimal::sum($amounts)!==$amount || !$this->sameCurrencyAmount($receipt['multicurrency_amount']??'0',$amount)) { return null; }
		return array('bank_line_id'=>(int)$receipt['bank_line_id'],'bank_account_id'=>(int)$receipt['bank_account_id'],'amount'=>$amount,'currency'=>$currency,
			'payment_id'=>(int)$receipt['rowid'],'ref'=>$ref,'number'=>$number,'date'=>(string)$receipt['datep'],'mode_code'=>(string)$receipt['mode_code'],
			'allocation_count'=>count($allocations),'invoice_refs'=>array_values(array_unique($refs)),'customer_id'=>$customer,'customer_name'=>$name,
			'facts_hash'=>hash('sha256',json_encode(array('receipt'=>$receipt,'allocations'=>$allocations),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)));
	}

	private function sameCurrencyAmount($raw, $amount)
	{
		$value=KreaBankPaymentBatchDecimal::normalize((string)$raw);
		return $value==='0.00000000' || $value===$amount;
	}

	private function hasTable($table)
	{
		if (!array_key_exists($table,$this->tables)) { $this->tables[$table]=(bool)$this->rows('SHOW TABLES LIKE '.$this->text($this->db->prefix().$table)); }
		return $this->tables[$table];
	}

	private function text($value) { return "'".$this->db->escape((string)$value)."'"; }
	private function rows($sql)
	{
		$result=$this->db->query($sql);
		if (!$result) { throw new RuntimeException('Unable to verify existing customer transfer evidence'); }
		$out=array(); while ($row=$this->db->fetch_object($result)) { $out[]=(array)$row; } return $out;
	}
}
