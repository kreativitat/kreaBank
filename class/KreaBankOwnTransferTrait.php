<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
require_once __DIR__ . '/KreaBankOwnTransferEvidence.class.php';
require_once __DIR__ . '/KreaBankReconciliationLock.class.php';

/** Ordinary owned-account transfers; expense/payment creation is never reachable here. */
trait KreaBankOwnTransferTrait
{
	private $ownTransferPaymentTables;
	/** Preview either one existing native leg or two mutually identified imported legs. */
	public function getAutomaticOwnTransferSuggestion($lineId, $accountIds, $lock = false)
	{
		$this->assertTransferActor();
		$settings = $this->ownTransferSettings($accountIds, $lock);
		if (!$settings) { return null; }
		$source = $this->transferStatementRows(' AND l.rowid=' . (int) $lineId, $lock);
		if (count($source) !== 1) { return null; }
		$left = $source[0]; $account = (int) $left->bank_account_id;
		if ((string)$left->statement_currency !== (string)$left->currency || $this->automaticStatementSourceClaimed((int)$left->rowid,$lock) || !in_array($account, $settings['accounts'], true)
			|| $this->getPaymentAccountReview((int) $left->rowid, $settings['accounts'])) { return null; }
		$policy = new KreaBankAccountPairs($this->db, $this->entity);
		$native = $this->transferNativeCandidates($left, $lock);
		if (count($native) > 1) { return null; }
		$counterparts = $this->ownTransferSources($left, $settings['company_name'], $policy, $lock);
		if ($counterparts === null || count($counterparts) > 1) { return null; }
		if ($native) {
			$graph = $this->ownTransferNativeGraph((int) $native[0]->rowid, $left, $settings, $policy, $lock);
			if (!$graph || !$this->ownTransferUniqueClaim($left, (string)$graph['peer_iban'], $settings['company_name'], $lock)) { return null; }
			$lines = array($left); $banks = array((int) $graph['bank_id']);
			if ($counterparts) {
				$right = $counterparts[0];
				if ((int) $right->bank_account_id !== (int) $graph['peer_account'] || $graph['peer_reconciled']
					|| !in_array((int) $right->bank_account_id, $settings['accounts'], true)
					|| $this->getPaymentAccountReview((int) $right->rowid, $settings['accounts'])) { return null; }
				$reverse = $this->ownTransferSources($right, $settings['company_name'], $policy, $lock);
				if ($reverse === null || count($reverse) !== 1 || (int) $reverse[0]->rowid !== (int) $left->rowid
					|| !$this->ownTransferUniqueClaim($right, (string) $left->account_iban, $settings['company_name'], $lock)) { return null; }
				$rightNative = $this->transferNativeCandidates($right, $lock);
				if (count($rightNative) !== 1 || (int) $rightNative[0]->rowid !== (int) $graph['peer_bank_id']) { return null; }
				$rightGraph = $this->ownTransferNativeGraph((int) $rightNative[0]->rowid, $right, $settings, $policy, $lock);
				if (!$rightGraph || (int) $rightGraph['peer_bank_id'] !== (int) $graph['bank_id']) { return null; }
				$lines[] = $right; $banks[] = (int) $graph['peer_bank_id'];
			}
			return array('lines'=>$lines, 'bank_ids'=>$banks, 'graph'=>$graph, 'created_transfer'=>false, 'settings'=>$settings);
		}
		if (count($counterparts) !== 1) { return null; }
		$right = $counterparts[0];
		if (!in_array((int) $right->bank_account_id, $settings['accounts'], true)
			|| $this->getPaymentAccountReview((int) $right->rowid, $settings['accounts'])) { return null; }
		$reverse = $this->ownTransferSources($right, $settings['company_name'], $policy, $lock);
		if ($reverse === null || count($reverse) !== 1 || (int) $reverse[0]->rowid !== (int) $left->rowid
			|| $this->transferNativeCandidates($right, $lock)
			|| !$this->ownTransferUniqueClaim($left, (string) $right->account_iban, $settings['company_name'], $lock)
			|| !$this->ownTransferUniqueClaim($right, (string) $left->account_iban, $settings['company_name'], $lock)) { return null; }
		global $conf;
		if ((string) $left->currency !== (string) $conf->currency
			|| !$this->ownTransferValueDateValid($left) || !$this->ownTransferValueDateValid($right)
			|| $this->ownTransferUnbankedPayment($left, $lock) || $this->ownTransferUnbankedPayment($right, $lock)) { return null; }
		// Without exact mutually supplied IBANs, require the same bank booking day as well as both direction-bound names.
		$leftProof = KreaBankOwnTransferEvidence::ownedIdentity((array) $left, $settings['company_name'], (string) $right->account_iban);
		$rightProof = KreaBankOwnTransferEvidence::ownedIdentity((array) $right, $settings['company_name'], (string) $left->account_iban);
		if (($leftProof !== 'iban' || $rightProof !== 'iban') && $left->operation_date !== $right->operation_date) { return null; }
		return array('lines'=>array($left,$right), 'bank_ids'=>array(0,0), 'graph'=>null, 'created_transfer'=>true, 'settings'=>$settings);
	}

	/** Return the number of imported legs completed by one atomic native transfer operation. */
	public function reconcileAutomaticOwnTransfer($lineId, $accountIds)
	{
		$this->assertTransferActor();
		$companyLock = new KreaBankReconciliationLock($this->db, $this->entity);
		$companyLock->acquire();
		try {
			if (!$this->db->begin()) { throw new RuntimeException('Cannot start owned-account transfer reconciliation'); }
			$p = $this->db->prefix();
			$this->transferRows("SELECT rowid FROM {$p}bank_account WHERE entity=" . $this->entity . ' ORDER BY rowid FOR UPDATE');
			$match = $this->getAutomaticOwnTransferSuggestion((int) $lineId, $accountIds, true);
			if (!$match || $match['settings']['mode'] !== 'enabled') { $this->db->rollback(); return 0; }
			$ids = array_map(static function ($line) { return (int) $line->rowid; }, $match['lines']);
			$sorted = $ids; sort($sorted, SORT_NUMERIC);
			foreach ($sorted as $id) { $this->lockReconciliationRow('kreabank_statement_line', $id); }
			$banks = $match['bank_ids']; $bankLocks = $banks;
			if ($match['graph']) { $bankLocks[] = (int) $match['graph']['peer_bank_id']; }
			$bankLocks = array_unique(array_filter($bankLocks)); sort($bankLocks, SORT_NUMERIC);
			foreach ($bankLocks as $id) {
				$this->lockReconciliationRow('bank', $id);
				$this->transferRows("SELECT rowid FROM {$p}bank_url WHERE fk_bank=" . (int) $id . ' ORDER BY rowid FOR UPDATE');
			}
			$fresh = $this->getAutomaticOwnTransferSuggestion((int) $lineId, $accountIds, true);
			if ($fresh != $match) { throw new RuntimeException('Owned-account transfer evidence changed; review before retrying'); }
			if ($match['created_transfer']) {
				foreach ($ids as $index=>$id) { $banks[$index] = $this->native->ensureNativeLineForStatementLine($id, true); }
				foreach ($banks as $index=>$id) {
					if ($this->native->addLineLink($id, $banks[1-$index], DOL_URL_ROOT . '/compta/bank/line.php?rowid=', '(banktransfert)', 'banktransfert') <= 0) {
						throw new RuntimeException('Cannot link both owned-account transfer legs');
					}
				}
			}
			foreach ($banks as $index=>$id) {
				$line = $this->native->getStatementLineById($ids[$index]);
				$sourceAmount = (string) $match['lines'][$index]->amount;
				$persisted = $this->transferRows("SELECT amount,fk_type FROM {$p}bank WHERE rowid=" . (int) $id . ' FOR UPDATE');
				if (!$line || count($persisted) !== 1 || self::automaticDecimal($persisted[0]->amount) !== self::automaticDecimal($sourceAmount)
					|| ($match['created_transfer'] && (string) $persisted[0]->fk_type !== 'VIR')) { throw new RuntimeException('Native owned transfer differs from its source'); }
				(new KreaBankReconciliationScope($this->db, $this->entity))->assertDocument('native_bank', (int) $id, (int) $line['bank_account_id']);
				$this->assertPaymentBatchMovementUnclaimed((int) $id);
				$this->finalizeNativeReconciliation($line, (int) $id, KreaBankDecimal::abs($sourceAmount), 'staged', array(
					'strategy'=>'auto_transfer', 'is_auto'=>1, 'native'=>1, 'source'=>'staged', 'native_bank_line_id'=>(int) $id,
					'paired_statement_line_id'=>count($ids)===2 ? $ids[1-$index] : (int) ($match['graph']['peer_source_id'] ?? 0),
					'paired_bank_line_id'=>count($banks)===2 ? $banks[1-$index] : (int) $match['graph']['peer_bank_id'],
					'created_transfer'=>$match['created_transfer'], 'identity'=>'owned_account_transfer',
				));
			}
			if (!$this->db->commit()) { throw new RuntimeException('Owned transfer commit is unknown; inspect its state before retrying'); }
			return count($ids);
		} catch (Throwable $e) { $this->db->rollback(); throw $e; }
		finally { $companyLock->release(); }
	}

	private function ownTransferValueDateValid($line)
	{
		$date = (string) ($line->value_date ?? '');
		return $date === '' || (preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/D', $date, $parts)
			&& checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]));
	}

	private function ownTransferSettings($callerAccounts, $lock)
	{
		$p = $this->db->prefix(); $values = array();
		// Lock all company names in one order before current-company settings. A shared brand
		// cannot prove account ownership when two companies normalize to the same legal name.
		$names = $this->transferRows("SELECT entity,value FROM {$p}const WHERE name='MAIN_INFO_SOCIETE_NOM' ORDER BY entity,rowid" . ($lock ? ' FOR UPDATE' : ''));
		foreach ($names as $row) {
			if ((int) $row->entity === $this->entity) { $values['MAIN_INFO_SOCIETE_NOM'] = (string) $row->value; }
		}
		foreach ($this->transferRows("SELECT name,value FROM {$p}const WHERE entity=" . $this->entity
			. " AND name IN ('KREABANK_AUTO_NATIVE_MODE','KREABANK_AUTO_NATIVE_OWN_TRANSFERS_ENABLED','KREABANK_AUTO_NATIVE_ACCOUNTS') ORDER BY name" . ($lock ? ' FOR UPDATE' : '')) as $row) { $values[$row->name] = (string) $row->value; }
		$companyKey = KreaBankOwnTransferEvidence::companyKey($values['MAIN_INFO_SOCIETE_NOM'] ?? '');
		foreach ($names as $row) {
			if ((int) $row->entity > 0 && (int) $row->entity !== $this->entity && $companyKey !== ''
				&& KreaBankOwnTransferEvidence::companyKey((string) $row->value) === $companyKey) { $values['MAIN_INFO_SOCIETE_NOM'] = ''; break; }
		}
		if (($values['KREABANK_AUTO_NATIVE_OWN_TRANSFERS_ENABLED']??'') !== '1'
			|| !in_array($values['KREABANK_AUTO_NATIVE_MODE']??'',array('off','enabled','preview'),true)) { return null; }
		$accounts = array_values(array_intersect(array_map('intval',(array)$callerAccounts),array_map('intval',explode(',',$values['KREABANK_AUTO_NATIVE_ACCOUNTS']??''))));
		sort($accounts,SORT_NUMERIC);
		return $accounts ? array('accounts'=>$accounts,'mode'=>$values['KREABANK_AUTO_NATIVE_MODE'],'company_name'=>$values['MAIN_INFO_SOCIETE_NOM']??'') : null;
	}

	/** Read all owned competing sources, including unselected accounts, before deciding uniqueness. */
	private function ownTransferSources($line, $companyName, $policy, $lock)
	{
		$p=$this->db->prefix(); $range=$this->resolveOpenDocumentsDateRange($line->operation_date,max(0,min(31,(int)getDolGlobalInt('KREABANK_AUTOMATCH_DATE_TOLERANCE',3))));
		$sql="SELECT l.*,s.bank_account_id,s.source_type,ba.iban_prefix AS account_iban FROM {$p}kreabank_statement_line l JOIN {$p}kreabank_statement s ON s.rowid=l.fk_statement AND s.entity=l.entity JOIN {$p}bank_account ba ON ba.rowid=s.bank_account_id AND ba.entity=l.entity"
			. ' WHERE l.entity='.$this->entity.' AND l.status=0 AND l.is_duplicate=0 AND s.bank_account_id<>'.(int)$line->bank_account_id
			. ' AND l.amount='.KreaBankDecimal::negate($line->amount).' AND l.currency='.$this->sqlText($line->currency)
			. ' AND l.operation_date>='.$this->sqlDate($range['start']).' AND l.operation_date<='.$this->sqlDate($range['end'])
			. ' ORDER BY l.rowid'.$this->db->plimit(501).($lock?' FOR UPDATE':'');
		$rows=$this->transferRows($sql); if(count($rows)>=501){return null;}$out=array();
		foreach($rows as $row){
			try{$policy->assertTransfer((int)$line->bank_account_id,(int)$row->bank_account_id);}catch(DomainException $e){continue;}
			if(!KreaBankOwnTransferEvidence::ownedIdentity((array)$line,$companyName,(string)$row->account_iban)
				|| !KreaBankOwnTransferEvidence::ownedIdentity((array)$row,$companyName,(string)$line->account_iban)){continue;}
			// An invalid competing source is an ambiguity, not permission to ignore its claim.
			$valid=$this->transferStatementRows(' AND l.rowid='.(int)$row->rowid,$lock);
			if(count($valid)!==1 || (string)$valid[0]->statement_currency!==(string)$valid[0]->currency || $this->automaticStatementSourceClaimed((int)$row->rowid,$lock)){return null;}$out[]=$valid[0];
		}
		return $out;
	}

	private function ownTransferUniqueClaim($line, $peerIban, $companyName, $lock)
	{
		$p=$this->db->prefix();$range=$this->resolveOpenDocumentsDateRange($line->operation_date,max(0,min(31,(int)getDolGlobalInt('KREABANK_AUTOMATCH_DATE_TOLERANCE',3))));
		$rows=$this->transferRows("SELECT l.*,s.bank_account_id FROM {$p}kreabank_statement_line l JOIN {$p}kreabank_statement s ON s.rowid=l.fk_statement AND s.entity=l.entity WHERE l.entity=".$this->entity
			.' AND l.status=0 AND l.is_duplicate=0 AND s.bank_account_id='.(int)$line->bank_account_id.' AND l.amount='.self::automaticDecimal($line->amount)
			.' AND l.currency='.$this->sqlText($line->currency).' AND l.operation_date>='.$this->sqlDate($range['start']).' AND l.operation_date<='.$this->sqlDate($range['end'])
			.' ORDER BY l.rowid'.$this->db->plimit(501).($lock?' FOR UPDATE':''));
		if(count($rows)>=501){return false;}$claims=0;
		foreach($rows as $row){if(KreaBankOwnTransferEvidence::ownedIdentity((array)$row,$companyName,$peerIban)){$claims++;}}
		return $claims===1;
	}

	/** Exact reciprocal native graph, both accounts owned; existing claims are never reassigned. */
	private function ownTransferNativeGraph($nativeId, $line, $settings, $policy, $lock)
	{
		$p=$this->db->prefix();$rows=$this->transferRows("SELECT b.rowid bank_id,b.amount,b.dateo,b.rappro,b.num_releve,ba.iban_prefix account_iban,"
			."c.rowid peer_bank_id,c.amount peer_amount,c.dateo peer_date,c.rappro peer_reconciled,c.num_releve peer_statement,ca.rowid peer_account,ca.iban_prefix peer_iban,ca.currency_code peer_currency"
			." FROM {$p}bank b JOIN {$p}bank_account ba ON ba.rowid=b.fk_account JOIN {$p}bank_url u ON u.fk_bank=b.rowid AND u.type='banktransfert'"
			." JOIN {$p}bank c ON c.rowid=u.url_id JOIN {$p}bank_account ca ON ca.rowid=c.fk_account"
			.' WHERE b.rowid='.(int)$nativeId.' AND ba.entity='.$this->entity.' AND ca.entity='.$this->entity
			.' AND b.fk_account='.(int)$line->bank_account_id.' AND c.fk_account<>b.fk_account AND b.rappro=0'
			.' AND ba.clos=0 AND ca.clos=0 AND ba.rappro=1 AND ca.rappro=1 AND ba.courant<>2 AND ca.courant<>2'
			.' AND ca.currency_code=ba.currency_code AND ca.currency_code='.$this->sqlText($line->currency)
			.($lock?' FOR UPDATE':''));
		if(count($rows)!==1){return null;}$graph=(array)$rows[0];$peer=(int)$graph['peer_bank_id'];$account=(int)$graph['peer_account'];
		$range=$this->resolveOpenDocumentsDateRange($line->operation_date,max(0,min(31,(int)getDolGlobalInt('KREABANK_AUTOMATCH_DATE_TOLERANCE',3))));
		if(!in_array($account,$settings['accounts'],true)||self::automaticDecimal($graph['amount'])!==self::automaticDecimal($line->amount)
			||KreaBankDecimal::compare($graph['peer_amount'],KreaBankDecimal::negate($line->amount))!==0
			||substr($graph['peer_date'],0,10)<$range['start']||substr($graph['peer_date'],0,10)>$range['end']
			||!KreaBankOwnTransferEvidence::ownedIdentity((array)$line,$settings['company_name'],(string)$graph['peer_iban'])){return null;}
		try{$policy->assertTransfer((int)$line->bank_account_id,$account);}catch(DomainException $e){return null;}
		foreach(array((int)$nativeId=>$peer,$peer=>(int)$nativeId)as$id=>$other){
			$links=$this->transferRows("SELECT type,url_id FROM {$p}bank_url WHERE fk_bank=$id AND type<>'company' ORDER BY rowid".($lock?' FOR UPDATE':''));
			if(count($links)!==1||$links[0]->type!=='banktransfert'||(int)$links[0]->url_id!==$other){return null;}
			if($this->ownTransferHasPayment($id,$lock)){return null;}
			$claims=$this->transferRows("SELECT rowid,entity,status,fk_statement,fk_native_bank_line,operation_date,amount,allocated_amount,is_duplicate,currency FROM {$p}kreabank_statement_line WHERE fk_native_bank_line=$id ORDER BY rowid".($lock?' FOR UPDATE':''));
			if($id===(int)$nativeId&&$claims){return null;}
			if($id===$peer){
				if(count($claims)>1){return null;}$graph['peer_source_id']=0;
				if($claims){
					$c=$claims[0];$parent=$this->transferRows("SELECT bank_account_id,ref,currency FROM {$p}kreabank_statement WHERE rowid=".(int)$c->fk_statement.' AND entity='.$this->entity.($lock?' FOR UPDATE':''));
					if((int)$c->entity!==$this->entity||(int)$c->status!==2||!(int)$graph['peer_reconciled']||count($parent)!==1
						||(int)$parent[0]->bank_account_id!==$account||$parent[0]->ref!==$graph['peer_statement']||$c->currency!==$line->currency
						||$parent[0]->currency!==$c->currency||!empty($c->is_duplicate)||KreaBankDecimal::compare($c->allocated_amount,KreaBankDecimal::abs($c->amount))!==0
						||KreaBankDecimal::compare($c->amount,$graph['peer_amount'])!==0||substr($graph['peer_date'],0,10)!==$c->operation_date
						||$this->automaticStatementSourceClaimed((int)$c->rowid,$lock)||!$this->automaticFeedEvidenceValid((int)$c->rowid,$lock)){return null;}
					$graph['peer_source_id']=(int)$c->rowid;
				}elseif((int)$graph['peer_reconciled']){return null;}
			}
			if($this->paymentBatchMapsAvailable()&&$this->transferRows("SELECT rowid FROM {$p}kreabank_payment_batch_line WHERE active_bank_line=$id".($lock?' FOR UPDATE':''))){return null;}
		}
		$historical=KreaBankOpeningBalance::historicalSql($this->db,$this->entity,'b.fk_account','b.dateo','ba.currency_code');
		if($this->transferRows("SELECT b.rowid FROM {$p}bank b JOIN {$p}bank_account ba ON ba.rowid=b.fk_account WHERE b.rowid IN (".(int)$nativeId.",$peer) AND ($historical)".($lock?' FOR UPDATE':''))){return null;}
		return $graph;
	}

	private function ownTransferHasPayment($bankId, $lock)
	{
		$p=$this->db->prefix();
		if($this->ownTransferPaymentTables===null){
			$this->ownTransferPaymentTables=array();
			foreach(array('paiement','paiementfourn','paiementcharge','payment_salary','payment_various','payment_expensereport','payment_donation','payment_loan','payment_vat')as$table){
				if($this->tableExists($p.$table)){$this->ownTransferPaymentTables[]=$table;}
			}
		}
		foreach($this->ownTransferPaymentTables as$table){
			if($this->transferRows("SELECT rowid FROM {$p}{$table} WHERE fk_bank=".(int)$bankId.$this->db->plimit(1).($lock?' FOR UPDATE':''))){return true;}
		}
		return false;
	}

	private function ownTransferUnbankedPayment($line, $lock)
	{
		$p=$this->db->prefix();$range=$this->resolveOpenDocumentsDateRange($line->operation_date,max(0,min(31,(int)getDolGlobalInt('KREABANK_AUTOMATCH_DATE_TOLERANCE',3))));
		foreach(array('paiement','paiementfourn')as$table){
			$rows=$this->transferRows("SELECT rowid FROM {$p}{$table} WHERE entity=".$this->entity.' AND (fk_bank IS NULL OR fk_bank=0) AND ABS(amount)='.KreaBankDecimal::abs($line->amount)
				.' AND datep>='.$this->sqlDate($range['start']).' AND datep<'.$this->sqlDate(gmdate('Y-m-d',strtotime($range['end'].' UTC')+86400)).$this->db->plimit(1).($lock?' FOR UPDATE':''));
			if($rows){return true;}
		}
		return false;
	}
}
