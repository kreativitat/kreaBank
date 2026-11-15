<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
require_once __DIR__ . '/KreaBankPaymentBatchTrait.php';
require_once __DIR__ . '/KreaBankMatcher.class.php';

/** Read-only native salary payment evidence for the shared account review/correction path. */
final class KreaBankSalaryPaymentReview
{
	private $db;
	private $user;
	private $entity;
	private $available;

	public function __construct($db,$user,$entity) { $this->db=$db; $this->user=$user; $this->entity=(int)$entity; }

	public function available()
	{
		if ($this->available !== null) { return $this->available; }
		$p=$this->db->prefix();
		$salary=(bool)$this->rows('SHOW TABLES LIKE '.$this->text($p.'salary'));
		$payment=(bool)$this->rows('SHOW TABLES LIKE '.$this->text($p.'payment_salary'));
		if ($salary !== $payment) { throw new RuntimeException('The native salary payment schema is incomplete'); }
		return $this->available=$salary;
	}

	/** Same base read gate and employee hierarchy used by the installed native salary controller. */
	public function readableSql($employeeExpression)
	{
		global $conf;
		if ((int)$conf->entity !== $this->entity || !isModEnabled('salaries') || !$this->user->hasRight('salaries','read')) { return '0=1'; }
		if ($this->user->hasRight('salaries','readall')) { return '1=1'; }
		if (!method_exists($this->user,'getAllChildIds')) { return '0=1'; }
		$ids=array_values(array_unique(array_filter(array_map('intval',(array)$this->user->getAllChildIds(1)),static function($id){return $id>0;})));
		return $ids ? $employeeExpression.' IN ('.implode(',',$ids).')' : '0=1';
	}

	/** Correlated to native bank b and owner account ba; no shared-company salary access. */
	public function bankOwnershipSql()
	{
		if (!$this->available()) { return ''; }
		$p=$this->db->prefix();
		return " AND NOT EXISTS (SELECT 1 FROM {$p}payment_salary sp LEFT JOIN {$p}salary s ON s.rowid=sp.fk_salary"
			. " LEFT JOIN {$p}user eu ON eu.rowid=s.fk_user WHERE sp.fk_bank=b.rowid"
			. " AND (sp.entity<>ba.entity OR s.rowid IS NULL OR s.entity<>ba.entity OR eu.rowid IS NULL" . (isModEnabled('salaries') ? ' OR eu.entity NOT IN (0,ba.entity)' : '') . "))"
			. " AND NOT EXISTS (SELECT 1 FROM {$p}bank_url su LEFT JOIN {$p}payment_salary sp ON sp.rowid=su.url_id"
			. " LEFT JOIN {$p}salary s ON s.rowid=sp.fk_salary WHERE su.fk_bank=b.rowid AND su.type='payment_salary'"
			. " AND (sp.rowid IS NULL OR sp.fk_bank<>b.rowid OR sp.entity<>ba.entity OR s.rowid IS NULL OR s.entity<>ba.entity))";
	}

	public function visibilitySql()
	{
		if (!$this->available()) { return ''; }
		$p=$this->db->prefix();
		return " AND NOT EXISTS (SELECT 1 FROM {$p}payment_salary sp JOIN {$p}salary s ON s.rowid=sp.fk_salary"
			. ' WHERE sp.fk_bank=b.rowid AND NOT ('.$this->readableSql('s.fk_user').'))';
	}

	/** Exact amount/date plus complete payment reference or complete employee identity; never automatic evidence. */
	public function accountReview($line,$range,&$truncated=null)
	{
		$truncated=false;
		if (!$this->available() || $this->readableSql('s.fk_user')==='0=1') { return array(); }
		$amount=KreaBankPaymentBatchDecimal::normalize($line['amount_decimal']??(string)$line['amount']);
		if ($amount[0]!=='-') { return array(); }
		$p=$this->db->prefix(); $e=$this->entity;
		$rows=$this->rows("SELECT b.rowid,b.fk_account,CAST(b.amount AS DECIMAL(24,8)) AS amount,b.dateo,b.rappro,b.num_chq,"
			. "ba.ref AS account_ref,ba.label AS account_label,sp.rowid AS payment_id,sp.num_payment,s.rowid AS salary_id,s.ref AS salary_ref,s.label AS salary_label,eu.firstname,eu.lastname"
			. " FROM {$p}payment_salary sp JOIN {$p}salary s ON s.rowid=sp.fk_salary AND s.entity=sp.entity"
			. " JOIN {$p}user eu ON eu.rowid=s.fk_user AND eu.entity IN (0,s.entity) JOIN {$p}bank b ON b.rowid=sp.fk_bank"
			. " JOIN {$p}bank_account ba ON ba.rowid=b.fk_account AND ba.entity=s.entity WHERE s.entity=$e"
			. ' AND ba.rowid<>'.(int)$line['bank_account_id'].' AND ba.currency_code='.$this->text($line['currency'])
			. " AND CAST(b.amount AS DECIMAL(24,8))=$amount AND CAST(sp.amount AS DECIMAL(24,8))=".KreaBankPaymentBatchDecimal::absolute($amount)
			. ' AND b.dateo>='.$this->text($range['start']).' AND b.dateo<'.$this->text(gmdate('Y-m-d',strtotime($range['end'].' UTC')+86400))
			. ' AND ('.$this->readableSql('s.fk_user').')'.$this->bankOwnershipSql().' ORDER BY b.dateo,b.rowid,sp.rowid'.$this->db->plimit(101));
		$truncated=count($rows)>=101; $out=array();
		foreach ($rows as $row) {
			$identity=self::identity($line,$row);
			if ($identity==='') { continue; }
			$row['identity']=$identity; $out[]=$row;
		}
		return $out;
	}

	public static function identity($line,$row)
	{
		foreach (array($row['num_payment'],$row['num_chq']) as $reference) {
			$reference=trim((string)$reference);
			if (strlen(preg_replace('/[^a-z0-9]/i','',$reference))<8 || !preg_match('/[0-9]/',$reference)) { continue; }
			foreach (array('payment_reference','bank_reference','description') as $field) {
				if (preg_match('/(?<![a-z0-9])'.preg_quote($reference,'/').'(?![a-z0-9])/i',(string)($line[$field]??''))) { return $reference; }
			}
		}
		if (trim((string)($line['payment_reference']??''))!=='' || trim((string)$row['firstname'])==='' || trim((string)$row['lastname'])==='') { return ''; }
		$matcher=new KreaBankMatcher(); $name=trim($row['firstname'].' '.$row['lastname']);
		return $matcher->hasCompleteCounterpartyNameMatch($line,$name) || $matcher->hasCompleteCounterpartyNameMatch($line,$row['lastname'].' '.$row['firstname']) ? $name : '';
	}

	/** Exact raw native facts, not a truncated GROUP_CONCAT signature. Caller holds bank/source locks when locked. */
	public function correctionFacts($bankId,$nativeAmount,$lock=false)
	{
		if (!$this->available()) { return array('payments'=>array(),'can_move'=>true); }
		$p=$this->db->prefix(); $id=(int)$bankId; $suffix=$lock?' FOR UPDATE':'';
		$payments=$this->rows("SELECT sp.*,CAST(sp.amount AS DECIMAL(24,8)) AS amount_decimal FROM {$p}payment_salary sp WHERE sp.fk_bank=$id ORDER BY sp.rowid".$this->db->plimit(2).$suffix);
		if (!$payments) { return array('payments'=>array(),'can_move'=>true); }
		$facts=array('payments'=>$payments,'parents'=>array(),'employees'=>array(),'links'=>$this->rows("SELECT * FROM {$p}bank_url WHERE fk_bank=$id ORDER BY rowid$suffix"),'can_move'=>count($payments)===1);
		foreach ($payments as $payment) {
			$parent=$this->rows("SELECT s.*,CAST(s.amount AS DECIMAL(24,8)) AS amount_decimal FROM {$p}salary s WHERE s.rowid=".(int)$payment['fk_salary'].$suffix);
			$employee=$parent ? $this->rows("SELECT rowid,entity,firstname,lastname,statut FROM {$p}user WHERE rowid=".(int)$parent[0]['fk_user'].$suffix) : array();
			$facts['parents'][]=$parent; $facts['employees'][]=$employee;
			if (count($parent)!==1 || count($employee)!==1 || (int)$payment['entity']!==$this->entity || (int)$parent[0]['entity']!==$this->entity
				|| !in_array((int)$employee[0]['entity'],array(0,$this->entity),true) || !$this->user->hasRight('salaries','write')
				|| !$this->rows("SELECT rowid FROM {$p}salary s WHERE s.rowid=".(int)$payment['fk_salary'].' AND ('.$this->readableSql('s.fk_user').')')
				|| KreaBankPaymentBatchDecimal::normalize($payment['amount_decimal'])!==KreaBankPaymentBatchDecimal::absolute((string)$nativeAmount)
				|| KreaBankPaymentBatchDecimal::normalize((string)$nativeAmount)[0]!=='-') { $facts['can_move']=false; }
		}
		if ($facts['can_move']) {
			$paymentLinks=0; $employeeLinks=0; $employeeId=(int)$facts['parents'][0][0]['fk_user'];
			foreach ($facts['links'] as $link) {
				if ($link['type']==='payment_salary' && (int)$link['url_id']===(int)$payments[0]['rowid']) { $paymentLinks++; }
				elseif ($link['type']==='user' && (int)$link['url_id']===$employeeId) { $employeeLinks++; }
				else { $facts['can_move']=false; }
			}
			if ($paymentLinks!==1 || $employeeLinks!==1) { $facts['can_move']=false; }
		}
		return $facts;
	}

	private function text($value) { return "'".$this->db->escape($value)."'"; }
	private function rows($sql) {
		$result=$this->db->query($sql); if (!$result) { throw new RuntimeException('Cannot verify native salary payment evidence'); }
		$rows=array(); while ($row=$this->db->fetch_object($result)) { $rows[]=(array)$row; } return $rows;
	}
}
