<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
require_once __DIR__ . '/KreaBankPaymentBatchTrait.php';
require_once __DIR__ . '/KreaBankAccountPairs.class.php';
require_once __DIR__ . '/KreaBankMatcher.class.php';
require_once __DIR__ . '/KreaBankFintableMapper.class.php';

/** List-only advice and native payment creation. This class never reconciles a source. */
final class KreaBankPlannedPayment
{
	private $db;
	private $user;
	private $entity;
	private $service;
	private $failureStage = 'review';
	const MAX_CANDIDATES = 100;

	public function __construct($db, $user, $entity, $service)
	{
		$this->db = $db; $this->user = $user; $this->entity = (int) $entity; $this->service = $service;
	}

	/** Fixed diagnostic stage only; never exposes document, user or native error data. */
	public function failureStage()
	{
		return $this->failureStage;
	}

	/** Advice requires the same read and write authority as the reviewed payment form. */
	public function permissions($type)
	{
		global $conf;
		if (!in_array($type, array('salary','tax'), true) || $this->entity <= 0 || (int) $conf->entity !== $this->entity
			|| empty($this->user->id) || !empty($this->user->socid) || (int) $this->user->statut !== 1
			|| (!empty($this->user->entity) && (int) $this->user->entity !== $this->entity)
			|| !isModEnabled('kreabank') || !isModEnabled('banque')
			|| !$this->user->hasRight('banque','lire') || !$this->user->hasRight('banque','modifier')
			|| !$this->user->hasRight('kreabank','reconciliation','read') || !$this->user->hasRight('kreabank','reconciliation','write')) { return false; }
		if ($type === 'tax') { return isModEnabled('tax') && $this->user->hasRight('tax','charges','lire') && $this->user->hasRight('tax','charges','creer'); }
		return isModEnabled('salaries') && $this->user->hasRight('salaries','read') && $this->user->hasRight('salaries','write')
			&& ($this->user->hasRight('salaries','readall') || ($this->user->hasRight('salaries','read') && method_exists($this->user,'getAllChildIds')));
	}

	/** Read-only, bounded, identity-supported advice; competing exact parents fail closed. */
	public function propose($line)
	{
		$types = array_values(array_filter(array('salary','tax'), function ($type) { return $this->permissions($type); }));
		if (!$types) { return array('status'=>'unavailable','candidate'=>null); }
		try { $source = $this->source($this->sourceId($line)); $this->conflicts($source); }
		catch (DomainException $error) { return array('status'=>'unavailable','candidate'=>null); }
		$p = $this->db->prefix(); $date = $source['operation_date']; $amount = KreaBankPaymentBatchDecimal::absolute($source['amount']);
		$candidates = array();
		foreach ($types as $type) {
			list($parent,$payment,$key) = $this->tables($type);
			if (!$this->hasTable($parent) || !$this->hasTable($payment)) { continue; }
			$day = $type === 'salary' ? 'd.dateep' : 'd.date_ech';
			$start = $this->shift($date, $type === 'salary' ? -45 : -35); $end = $type === 'salary' ? $date : $this->shift($date,35);
			$rows = $this->rows("SELECT d.rowid FROM {$p}{$parent} d WHERE d.entity=" . $this->entity . ' AND d.paye=0'
				. " AND $day>=" . $this->text($start) . " AND $day<" . $this->text($this->shift($end,1))
				. " AND CAST(d.amount AS DECIMAL(24,8))-COALESCE((SELECT SUM(CAST(q.amount AS DECIMAL(24,8))) FROM {$p}{$payment} q WHERE q.$key=d.rowid),0)=$amount"
				. ' ORDER BY d.rowid' . $this->db->plimit(self::MAX_CANDIDATES + 1));
			if (count($rows) > self::MAX_CANDIDATES) { return array('status'=>'truncated','candidate'=>null); }
			foreach ($rows as $row) {
				try { $candidates[] = $this->snapshot($line,$type,(int) $row['rowid']); }
				catch (DomainException $error) { continue; }
				if (count($candidates) > 1) { return array('status'=>'ambiguous','candidate'=>null); }
			}
		}
		return array('status'=>$candidates ? 'unique' : 'none','candidate'=>$candidates ? $candidates[0] : null);
	}

	/** Canonical, serializable facts. The controller binds these to its single-use session review. */
	public function snapshot($line, $type, $id, $lock = false)
	{
		if (!$this->permissions($type)) { throw new DomainException('Current native payment and bank permissions are required'); }
		$id = $this->id($id); $source = $this->source($this->sourceId($line),$lock);
		if ((int) $line['fk_statement'] !== $source['fk_statement']) { throw new DomainException('The staged source identity changed'); }
		$this->conflicts($source,$lock);
		list($table,$payment,$key) = $this->tables($type); $p = $this->db->prefix(); $suffix = $lock ? ' FOR UPDATE' : '';
		$parents = $this->rows("SELECT d.*,CAST(d.amount AS DECIMAL(24,8)) AS amount_decimal FROM {$p}{$table} d WHERE d.entity=" . $this->entity . " AND d.rowid=$id$suffix");
		if (count($parents) !== 1 || (int) $parents[0]['paye'] !== 0) { throw new DomainException('The owned unpaid document is no longer available'); }
		$parent = $parents[0]; $parent['amount_decimal'] = KreaBankPaymentBatchDecimal::normalize($parent['amount_decimal']);
		if ($parent['amount_decimal'][0] === '-' || $parent['amount_decimal'] === '0.00000000') { throw new DomainException('The parent has no positive unpaid amount'); }
		$payments = $this->rows("SELECT q.*,CAST(q.amount AS DECIMAL(24,8)) AS amount_decimal FROM {$p}{$payment} q WHERE q.$key=$id ORDER BY q.rowid$suffix");
		$terms = array($parent['amount_decimal']);
		foreach ($payments as &$paid) {
			$paid['amount_decimal'] = KreaBankPaymentBatchDecimal::normalize($paid['amount_decimal']);
			if ($paid['amount_decimal'][0] === '-' || $paid['amount_decimal'] === '0.00000000' || (int) $paid['fk_bank'] <= 0
				|| ($type === 'salary' && (int) $paid['entity'] !== $this->entity)) { throw new DomainException('An existing or inconsistent payment requires native review'); }
			if (!$this->rows("SELECT b.rowid FROM {$p}bank b JOIN {$p}bank_account a ON a.rowid=b.fk_account AND a.entity=" . $this->entity . ' WHERE b.rowid=' . (int) $paid['fk_bank'] . $suffix)) {
				throw new DomainException('An earlier payment has missing or foreign bank evidence');
			}
			$terms[] = '-' . $paid['amount_decimal'];
		}
		unset($paid);
		$remaining = KreaBankPaymentBatchDecimal::sum($terms);
		if ($remaining !== KreaBankPaymentBatchDecimal::absolute($source['amount'])) { throw new DomainException('The exact remaining amount changed'); }
		$employee = array();
		if ($type === 'salary') {
			$employeeId = (int) $parent['fk_user'];
			if (!$this->user->hasRight('salaries','readall') && !in_array($employeeId,array_map('intval',(array) $this->user->getAllChildIds(1)),true)) {
				throw new DomainException('The salary is outside the current employee scope');
			}
			$employees = $this->rows("SELECT rowid,entity,firstname,lastname,statut FROM {$p}user WHERE rowid=$employeeId$suffix");
			if (count($employees) !== 1 || !in_array((int) $employees[0]['entity'],array(0,$this->entity),true)) { throw new DomainException('The employee identity is unavailable in this company'); }
			$employee = $employees[0];
			$date = (string) $parent['dateep'];
			if (!$this->date($date) || $date > $source['operation_date'] || $date < $this->shift($source['operation_date'],-45)
				|| !$this->salaryIdentity($source,$employee)) { throw new DomainException('The salary period or complete employee identity does not match'); }
			$label = trim($employee['firstname'] . ' ' . $employee['lastname']);
			$reference = (string) ($parent['ref'] ?: ($parent['ref_ext'] ?: $parent['label']));
			$mode = (int) $parent['fk_typepayment']; $parentUrl = '/salaries/card.php?id=' . $id;
		} else {
			$date = substr((string) $parent['date_ech'],0,10);
			if (!$this->date($date) || $date < $this->shift($source['operation_date'],-35) || $date > $this->shift($source['operation_date'],35)
				|| !$this->taxIdentity($source,$parent)) { throw new DomainException('The tax due date or complete reference does not match'); }
			$label = (string) $parent['libelle']; $reference = (string) ($parent['ref'] ?: $parent['libelle']);
			$mode = (int) $parent['fk_mode_reglement']; $parentUrl = '/compta/sociales/card.php?id=' . $id;
		}
		$this->competingSources($source,$type,$parent,$employee,$lock);
		return array('version'=>1,'entity'=>$this->entity,'user'=>(int) $this->user->id,'type'=>$type,'id'=>$id,
			'source'=>$source,'parent'=>$parent,'payments'=>$payments,'employee'=>$employee,
			'amount'=>KreaBankPaymentBatchDecimal::absolute($source['amount']),'remaining'=>$remaining,'booking_date'=>$source['operation_date'],
			'currency'=>$source['currency'],'label'=>$label,'reference'=>$reference,'parent_url'=>$parentUrl,'payment_mode_id'=>$mode,
			'doc_type'=>$type,'doc_id'=>$id,'doc_ref'=>$reference,'doc_date'=>$date,'document_date'=>$date,'amount_open'=>$source['amount'],'thirdparty_name'=>$label);
	}

	/** Numeric native dictionary IDs; amount, account and date are never editable. */
	public function paymentModes($lock = false)
	{
		if (!$this->permissions('salary') && !$this->permissions('tax')) { throw new DomainException('Native payment permissions are required'); }
		$entities = preg_split('/\s*,\s*/',trim((string) getEntity('c_paiement')));
		if (!$entities || array_filter($entities,static function ($id) { return !ctype_digit($id); })) { throw new DomainException('Invalid payment dictionary scope'); }
		$scope = implode(',',array_map('intval',$entities));
		$rows = $this->rows('SELECT id,code,libelle AS label FROM ' . $this->db->prefix() . "c_paiement WHERE active=1 AND type IN (1,2,3) AND entity IN ($scope) ORDER BY code,id" . ($lock ? ' FOR UPDATE' : ''));
		foreach ($rows as &$row) { $row['id'] = (int) $row['id']; } unset($row);
		return $rows;
	}

	/** Native financial lifecycle inside one outer transaction; source remains pending. */
	public function create($context, $modeId, $reference, $note)
	{
		$this->failureStage = 'review';
		if (!is_array($context) || ($context['version'] ?? null) !== 1 || ($context['entity'] ?? null) !== $this->entity
			|| ($context['user'] ?? null) !== (int) $this->user->id || !$this->permissions($context['type'] ?? '')) { throw new DomainException('The payment review changed'); }
		$this->failureStage = 'input';
		$modeId = $this->id($modeId); $id = $this->id($context['id'] ?? 0);
		foreach (array(array($reference,50),array($note,1000)) as $input) {
			if (!is_string($input[0]) || mb_strlen($input[0],'UTF-8') > $input[1] || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$input[0])) { throw new DomainException('The payment reference or note is invalid'); }
		}
		$reference = trim($reference); $note = trim($note); $type = $context['type']; $p = $this->db->prefix();
		$this->failureStage = 'admission';
		if (!$this->db->begin()) { throw new RuntimeException('Cannot start native payment creation'); }
		try {
			// Same first lock as reconciliation, transfers and reviewed movement creation.
			$this->rows("SELECT rowid FROM {$p}bank_account WHERE entity=" . $this->entity . ' ORDER BY rowid FOR UPDATE');
			$line = array('rowid'=>$context['source']['rowid'] ?? 0,'fk_statement'=>$context['source']['fk_statement'] ?? 0);
			$current = $this->snapshot($line,$type,$id,true);
			if ($current !== $context) { throw new DomainException('The source, document or payments changed; review the payment again'); }
			$modes = array_column($this->paymentModes(true),null,'id');
			if (!isset($modes[$modeId])) { throw new DomainException('Select a current active native payment mode'); }
			$timestamp = (new DateTimeImmutable($context['booking_date'] . ' 12:00:00',new DateTimeZone('UTC')))->getTimestamp();
			$this->failureStage = 'payment_create';
			if ($type === 'salary') {
				require_once DOL_DOCUMENT_ROOT . '/salaries/class/paymentsalary.class.php';
				$payment = new PaymentSalary($this->db); $payment->datep = $timestamp;
				$payment->datev = (new DateTimeImmutable(($context['source']['value_date'] ?: $context['booking_date']) . ' 12:00:00',new DateTimeZone('UTC')))->getTimestamp();
				$payment->fk_salary = $id; $payment->fk_typepayment = $modeId;
				$nativeType = 'payment_salary'; $nativeLabel = '(SalaryPayment)';
			} else {
				require_once DOL_DOCUMENT_ROOT . '/compta/sociales/class/paymentsocialcontribution.class.php';
				$payment = new PaymentSocialContribution($this->db); $payment->datepaye = $timestamp;
				$payment->chid = $id; $payment->paiementtype = $modeId;
				$nativeType = 'payment_sc'; $nativeLabel = '(SocialContributionPayment)';
			}
			$payment->amounts = array($id=>$context['amount']); $payment->num_payment = $reference; $payment->note = $note;
			$paymentId = (int) $payment->create($this->user,1);
			if ($paymentId <= 0) {
				throw new RuntimeException('Native payment creation failed; no payment was retained');
			}
			$this->failureStage = 'bank_attachment';
			if ($payment->addPaymentToBank($this->user,$nativeType,$nativeLabel,$context['source']['bank_account_id'],'','') <= 0) {
				throw new RuntimeException('Native bank attachment failed; no payment was retained');
			}
			$this->failureStage = 'verification';
			$after = $this->verifyCreated($current,$paymentId,$modeId,$modes[$modeId]['code'],$reference,$nativeType);
			$this->failureStage = 'audit';
			$payload = array('strategy'=>'planned_payment_create','native_reconciled'=>0,'type'=>$type,'parent_id'=>$id,'payment_id'=>$paymentId,
				'native_bank_line_id'=>$after['native_bank_line_id'],'amount_decimal'=>$context['amount'],'currency'=>$context['currency'],
				'booking_date'=>$context['booking_date'],'review_hash'=>hash('sha256',json_encode($context,JSON_THROW_ON_ERROR)),
				'source'=>$context['source'],'parent_before'=>$context['parent'],'payments_before'=>$context['payments'],'after'=>$after);
			if (!$this->service->recordPlannedPaymentAudit($context['source']['rowid'],$payload)) { throw new RuntimeException('Cannot record the native payment audit'); }
			$this->failureStage = 'commit';
			if (!$this->db->commit()) { throw new RuntimeException('The native payment commit was not confirmed; inspect its status before retrying'); }
			$this->failureStage = 'complete';
			return array('type'=>$type,'id'=>$id,'payment_id'=>$paymentId,'native_bank_line_id'=>$after['native_bank_line_id']);
		} catch (Throwable $error) { $this->db->rollback(); throw $error; }
	}

	private function sourceId($line)
	{
		if (!is_array($line) || empty($line['fk_statement']) || !empty($line['native_bank_line_id']) || !empty($line['fk_native_bank_line'])
			|| (isset($line['source_storage']) && $line['source_storage'] !== 'staging')) { throw new DomainException('Only an unmapped staged statement can record a payment'); }
		return $this->id($line['rowid'] ?? 0);
	}

	private function source($id, $lock = false)
	{
		global $conf;
		$p = $this->db->prefix(); $e = $this->entity; $suffix = $lock ? ' FOR UPDATE' : '';
		if (isModEnabled('multicompany') && !$this->rows("SELECT rowid FROM {$p}entity WHERE rowid=$e AND active=1$suffix")) { throw new DomainException('The current company is inactive'); }
		$rows = $this->rows("SELECT l.*,s.ref AS statement_ref,s.source_type,s.filename AS source_file,s.currency AS statement_currency,"
			. "s.bank_account_id,a.ref AS bank_account_ref,a.currency_code AS bank_currency,a.clos,a.rappro,a.courant"
			. " FROM {$p}kreabank_statement_line l JOIN {$p}kreabank_statement s ON s.rowid=l.fk_statement AND s.entity=l.entity"
			. " JOIN {$p}bank_account a ON a.rowid=s.bank_account_id AND a.entity=s.entity WHERE l.entity=$e AND l.rowid=$id$suffix");
		if (count($rows) !== 1) { throw new DomainException('The owned staged statement is unavailable'); }
		$source = $rows[0];
		(new KreaBankReconciliationScope($this->db, $e))->assertLine($source);
		foreach (array('rowid','entity','fk_statement','fk_native_bank_line','bank_account_id','direction','status','is_duplicate','duplicate_of','clos','rappro','courant') as $field) { $source[$field] = (int) $source[$field]; }
		$source['amount'] = KreaBankPaymentBatchDecimal::normalize($source['amount']);
		$source['allocated_amount'] = KreaBankPaymentBatchDecimal::normalize($source['allocated_amount']);
		if ($source['status'] !== 0 || $source['allocated_amount'] !== '0.00000000' || $source['fk_native_bank_line'] || $source['is_duplicate'] || $source['duplicate_of']
			|| $source['clos'] || $source['rappro'] !== 1 || $source['courant'] === 2 || $source['amount'][0] !== '-'
			|| $source['currency'] !== $source['bank_currency'] || $source['currency'] !== $source['statement_currency'] || $source['currency'] !== (string) $conf->currency
			|| trim((string) $source['statement_ref']) === '' || !$this->date($source['operation_date'])
			|| (!empty($source['value_date']) && !$this->date($source['value_date'])) || !in_array($source['direction'],array(0,-1),true)) {
			throw new DomainException('The statement is unavailable for native payment creation');
		}
		$pairs = new KreaBankAccountPairs($this->db,$e);
		$map = $pairs->pairs($lock);
		if (isset($map[$source['bank_account_id']])) { throw new DomainException('This account permits only its native transfer leg'); }
		$source['feed_facts'] = array();
		if ($source['source_type'] === 'fintable') {
			$source['feed_facts'] = $this->rows("SELECT ft.*,fa.fk_bank_account,fa.currency AS account_currency,fa.status AS account_status FROM {$p}kreabank_feed_transaction ft"
				. " LEFT JOIN {$p}kreabank_feed_account fa ON fa.rowid=ft.fk_feed_account AND fa.entity=ft.entity WHERE ft.entity=$e AND ft.fk_statement_line=$id ORDER BY ft.rowid$suffix");
			$feed = $source['feed_facts']; $hash = hash('sha256',KreaBankFintableMapper::decimal($source['amount']) . '|' . $source['operation_date'] . '|' . $source['currency']);
			if (count($feed) !== 1 || $feed[0]['status'] !== 'imported' || $feed[0]['conflict_payload'] !== null
				|| (int) $feed[0]['fk_bank_account'] !== $source['bank_account_id'] || $feed[0]['account_currency'] !== $source['currency']
				|| in_array($feed[0]['account_status'],array('mapping_review','unavailable'),true) || $feed[0]['source_uid'] !== $source['line_uid']
				|| !hash_equals($feed[0]['financial_hash'],$hash)) { throw new DomainException('Bank feed evidence requires review'); }
		}
		$tables = array_map(array($this,'hasTable'),array('kreabank_payment_batch','kreabank_payment_batch_line','kreabank_payment_batch_draft'));
		if (count(array_unique($tables)) > 1) { throw new DomainException('The payment batch schema is incomplete'); }
		if ($tables[0]) {
			if ($this->rows("SELECT rowid FROM {$p}kreabank_payment_batch WHERE entity=$e AND active_statement_line=$id ORDER BY rowid$suffix")
				|| $this->rows("SELECT rowid FROM {$p}kreabank_payment_batch_draft WHERE entity=$e AND fk_statement_line=$id AND fk_user=" . (int) $this->user->id . $suffix)) {
				throw new DomainException('An active payment batch or saved selection requires review');
			}
		}
		$source['batch_schema'] = $tables[0];
		if (trim((string) $source['line_uid']) !== '' && $this->rows("SELECT rowid FROM {$p}kreabank_bankmeta WHERE entity=$e AND bank_account_id=" . $source['bank_account_id']
			. ' AND source_type=' . $this->text($source['source_type']) . ' AND source_line_uid=' . $this->text($source['line_uid']) . $suffix)) {
			throw new DomainException('The statement already has native import evidence');
		}
		return $source;
	}

	private function conflicts($source, $lock = false)
	{
		$p = $this->db->prefix(); $e = $this->entity; $account = $source['bank_account_id']; $suffix = $lock ? ' FOR UPDATE' : '';
		$tolerance = max(0,min(31,(int) getDolGlobalInt('KREABANK_AUTOMATCH_DATE_TOLERANCE',3)));
		$start = $this->text($this->shift($source['operation_date'],-$tolerance)); $end = $this->text($this->shift($source['operation_date'],$tolerance+1));
		// Include imported, posted and already reconciled movements: none authorizes creating another.
		if ($this->rows("SELECT b.rowid FROM {$p}bank b WHERE b.fk_account=$account AND b.dateo>=$start AND b.dateo<$end"
			. ' AND CAST(b.amount AS DECIMAL(24,8))=' . $source['amount'] . ' ORDER BY b.rowid' . $this->db->plimit(1) . $suffix)
			|| $this->service->findExistingMovementsForCreation($source)
			|| $this->service->getWorklistPaymentAccountReview($source['rowid'],array($account))) { throw new DomainException('An existing native movement or payment account requires review'); }
		$amount = KreaBankPaymentBatchDecimal::absolute($source['amount']);
		foreach (array('paiementfourn','paiement') as $payment) {
			if ($this->rows("SELECT q.rowid FROM {$p}{$payment} q WHERE q.entity=$e AND COALESCE(q.fk_bank,0)=0 AND ABS(CAST(q.amount AS DECIMAL(24,8)))=$amount"
				. " AND q.datep>=$start AND q.datep<$end ORDER BY q.rowid" . $this->db->plimit(1) . $suffix)) { throw new DomainException('An existing unbanked invoice payment requires review'); }
		}
		foreach (array('salary','tax') as $type) {
			list($parent,$payment,$parentKey) = $this->tables($type);
			if (!$this->hasTable($parent) && !$this->hasTable($payment)) { continue; }
			if (!$this->hasTable($parent) || !$this->hasTable($payment)) { throw new DomainException('The native payment schema is incomplete'); }
			$identity = $type === 'salary' ? 'u.firstname,u.lastname' : 'd.ref,d.libelle';
			$join = $type === 'salary' ? " JOIN {$p}user u ON u.rowid=d.fk_user" : '';
			$unbanked = $this->rows("SELECT q.rowid,$identity FROM {$p}{$payment} q JOIN {$p}{$parent} d ON d.rowid=q.$parentKey$join"
				. " WHERE d.entity=$e AND COALESCE(q.fk_bank,0)=0 AND CAST(q.amount AS DECIMAL(24,8))=$amount"
				. " AND q.datep>=$start AND q.datep<$end ORDER BY q.rowid" . $this->db->plimit(self::MAX_CANDIDATES+1) . $suffix);
			if (count($unbanked) > self::MAX_CANDIDATES) { throw new DomainException('Existing native payment review is incomplete'); }
			foreach ($unbanked as $candidate) {
				if ($type === 'salary' ? $this->salaryIdentity($source,$candidate) : $this->taxIdentity($source,$candidate)) {
					throw new DomainException('An existing unbanked salary or tax payment requires review');
				}
			}
			// The shared account-review provider covers invoice and tax payments only.
			// A salary for the same employee in another owned account must also block a new payment.
			if ($type === 'salary') {
				$elsewhere = $this->rows("SELECT q.rowid,u.firstname,u.lastname FROM {$p}payment_salary q"
					. " JOIN {$p}salary d ON d.rowid=q.fk_salary JOIN {$p}user u ON u.rowid=d.fk_user"
					. " JOIN {$p}bank b ON b.rowid=q.fk_bank JOIN {$p}bank_account a ON a.rowid=b.fk_account AND a.entity=$e"
					. " WHERE d.entity=$e AND b.fk_account<>$account AND a.currency_code=" . $this->text($source['currency'])
					. " AND CAST(q.amount AS DECIMAL(24,8))=$amount AND CAST(b.amount AS DECIMAL(24,8))=" . $source['amount']
					. " AND b.dateo>=$start AND b.dateo<$end ORDER BY q.rowid" . $this->db->plimit(self::MAX_CANDIDATES+1) . $suffix);
				if (count($elsewhere) > self::MAX_CANDIDATES) { throw new DomainException('Existing salary payment account review is incomplete'); }
				foreach ($elsewhere as $candidate) {
					if ($this->salaryIdentity($source,$candidate)) { throw new DomainException('An existing salary payment in another owned account requires review'); }
				}
			}
		}
	}

	private function competingSources($source, $type, $parent, $employee, $lock)
	{
		$p = $this->db->prefix(); $id = $source['rowid']; $account = $source['bank_account_id'];
		$identity = array();
		if (trim((string) $source['line_uid']) !== '') { $identity[] = '(l.line_uid=' . $this->text($source['line_uid']) . ' AND s.source_type=' . $this->text($source['source_type']) . ')'; }
		if (trim((string) $source['duplicate_hash']) !== '') { $identity[] = 'l.duplicate_hash=' . $this->text($source['duplicate_hash']); }
		$identity[] = '(CAST(l.amount AS DECIMAL(24,8))=' . $source['amount'] . ' AND l.operation_date=' . $this->text($source['operation_date']) . ')';
		$others = $this->rows("SELECT l.*,s.source_type FROM {$p}kreabank_statement_line l JOIN {$p}kreabank_statement s ON s.rowid=l.fk_statement AND s.entity=l.entity"
			. ' WHERE l.entity=' . $this->entity . " AND s.bank_account_id=$account AND l.rowid<>$id AND (" . implode(' OR ',$identity) . ') ORDER BY l.rowid'
			. $this->db->plimit(self::MAX_CANDIDATES+1) . ($lock ? ' FOR UPDATE' : ''));
		if (count($others) > self::MAX_CANDIDATES) { throw new DomainException('Competing statement review is incomplete'); }
		foreach ($others as $other) {
			if (($source['duplicate_hash'] !== '' && $other['duplicate_hash'] === $source['duplicate_hash'])
				|| ($source['line_uid'] !== '' && $other['line_uid'] === $source['line_uid'] && $other['source_type'] === $source['source_type'])
				|| ($type === 'salary' ? $this->salaryIdentity($other,$employee) : $this->taxIdentity($other,$parent))) {
				throw new DomainException('Another statement claims the same payment evidence');
			}
		}
	}

	private function verifyCreated($context, $paymentId, $modeId, $modeCode, $reference, $nativeType)
	{
		$p = $this->db->prefix(); list($parent,$payment,$key) = $this->tables($context['type']); $id = $context['id'];
		$rows = $this->rows("SELECT q.*,CAST(q.amount AS DECIMAL(24,8)) AS amount_decimal FROM {$p}{$payment} q WHERE q.$key=$id ORDER BY q.rowid FOR UPDATE");
		$created = array_values(array_filter($rows,static function ($row) use ($paymentId) { return (int) $row['rowid'] === $paymentId; }));
		if (count($rows) !== count($context['payments'])+1 || count($created) !== 1) { throw new RuntimeException('The native payment allocations differ from the reviewed document'); }
		$created = $created[0]; $bankId = (int) $created['fk_bank']; $modeField = $context['type'] === 'salary' ? 'fk_typepayment' : 'fk_typepaiement';
		if ($bankId <= 0 || KreaBankPaymentBatchDecimal::normalize($created['amount_decimal']) !== $context['amount'] || (int) $created[$modeField] !== $modeId
			|| substr($created['datep'],0,10) !== $context['booking_date'] || $created[$context['type'] === 'salary' ? 'num_payment' : 'num_paiement'] !== $reference
			|| ($context['type'] === 'salary' && (int) $created['entity'] !== $this->entity)) { throw new RuntimeException('The native payment differs from the reviewed amount, date or company'); }
		foreach ($context['payments'] as $before) {
			$old = array_values(array_filter($rows,static function ($row) use ($before) { return $row['rowid'] === $before['rowid']; }));
			if (count($old) !== 1 || $old[0] !== $before) { throw new RuntimeException('An earlier payment changed during native creation'); }
		}
		$parents = $this->rows("SELECT paye,CAST(amount AS DECIMAL(24,8)) AS amount_decimal FROM {$p}{$parent} WHERE entity=" . $this->entity . " AND rowid=$id FOR UPDATE");
		$sum = KreaBankPaymentBatchDecimal::sum(array_column($rows,'amount_decimal'));
		if (count($parents) !== 1 || (int) $parents[0]['paye'] !== 1 || KreaBankPaymentBatchDecimal::normalize($parents[0]['amount_decimal']) !== $sum) { throw new RuntimeException('The native paid-document lifecycle did not preserve the exact remaining balance'); }
		$bank = $this->rows("SELECT b.*,CAST(b.amount AS DECIMAL(24,8)) AS amount_decimal FROM {$p}bank b JOIN {$p}bank_account a ON a.rowid=b.fk_account AND a.entity=" . $this->entity . " WHERE b.rowid=$bankId FOR UPDATE");
		// Native Account::addline()/AccountLine::insert() store NULL for the falsy
		// optional references '' and '0'; the payment itself retains the exact text.
		$bankReference = $reference === '0' ? '' : $reference;
		if (count($bank) !== 1 || (int) $bank[0]['fk_account'] !== $context['source']['bank_account_id'] || (int) $bank[0]['rappro'] !== 0
			|| KreaBankPaymentBatchDecimal::normalize($bank[0]['amount_decimal']) !== $context['source']['amount']
			|| substr($bank[0]['dateo'],0,10) !== $context['booking_date'] || (string) ($bank[0]['num_chq'] ?? '') !== $bankReference || $bank[0]['fk_type'] !== $modeCode) { throw new RuntimeException('The native bank movement differs from the reviewed statement'); }
		$links = $this->rows("SELECT type,url_id FROM {$p}bank_url WHERE fk_bank=$bankId ORDER BY rowid FOR UPDATE");
		$paymentLinks = array_filter($links,static function ($row) use ($nativeType,$paymentId) { return $row['type'] === $nativeType && (int) $row['url_id'] === $paymentId; });
		if (count($paymentLinks) !== 1 || $this->source($context['source']['rowid'],true) !== $context['source']) { throw new RuntimeException('Native linkage or pending source evidence changed'); }
		return array('native_bank_line_id'=>$bankId,'payment'=>$created,'parent'=>$parents[0],'bank'=>$bank[0],'links'=>$links);
	}

	private function salaryIdentity($source, $employee)
	{
		if (trim((string) $employee['firstname']) === '' || trim((string) $employee['lastname']) === '') { return false; }
		$matcher = new KreaBankMatcher();
		return $matcher->hasCompleteCounterpartyNameMatch($source,$employee['firstname'] . ' ' . $employee['lastname'])
			|| $matcher->hasCompleteCounterpartyNameMatch($source,$employee['lastname'] . ' ' . $employee['firstname']);
	}

	private function taxIdentity($source, $parent)
	{
		$label = trim((string) $parent['libelle']); $labelWords = preg_split('/[^\p{L}\p{N}]+/u',$label,-1,PREG_SPLIT_NO_EMPTY);
		// Full authority/kind labels still need a period or specific numeric liability identity.
		$period = preg_match('/\b(?:20[0-9]{2}|[0-9]{6,}|janeiro|fevereiro|mar[çc]o|abril|maio|junho|julho|agosto|setembro|outubro|novembro|dezembro|january|february|march|april|may|june|july|august|september|october|november|december)\b/iu',$label);
		if (count($labelWords) >= 2 && $period
			&& (new KreaBankMatcher())->hasCompleteCounterpartyNameMatch($source,$label)) { return true; }
		$reference = strtoupper(trim((string) $parent['ref'])); $core = preg_replace('/[^A-Z0-9]/','',$reference);
		if (strlen($core) < 8 || !preg_match('/[0-9]/',$core) || strpos($core,'PROV') === 0) { return false; }
		$words = static function ($value) { return trim(preg_replace('/[^A-Z0-9]+/',' ',strtoupper((string) $value))); };
		foreach (array('description','payment_reference','bank_reference') as $field) {
			if (strpos(' ' . $words($source[$field] ?? '') . ' ',' ' . $words($reference) . ' ') !== false) { return true; }
		}
		return false;
	}

	private function tables($type)
	{
		if ($type === 'salary') { return array('salary','payment_salary','fk_salary'); }
		if ($type === 'tax') { return array('chargesociales','paiementcharge','fk_charge'); }
		throw new DomainException('Unsupported planned payment type');
	}
	private function id($id)
	{
		if ((!is_int($id) && !is_string($id)) || !preg_match('/^[1-9][0-9]{0,9}$/D',(string) $id)) { throw new DomainException('Invalid payment source or document identity'); }
		return (int) $id;
	}
	private function date($date) { return is_string($date) && preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/D',$date,$m) && checkdate((int) $m[2],(int) $m[3],(int) $m[1]); }
	private function shift($date, $days) { return (new DateTimeImmutable($date,new DateTimeZone('UTC')))->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d'); }
	private function text($value) { return "'" . $this->db->escape($value) . "'"; }
	private function hasTable($suffix) { return (bool) $this->rows('SHOW TABLES LIKE ' . $this->text($this->db->prefix() . $suffix)); }
	private function rows($sql)
	{
		$result = $this->db->query($sql);
		if (!$result) { throw new RuntimeException('Cannot verify planned payment evidence; check the native database schema'); }
		$rows = array(); while ($row = $this->db->fetch_object($result)) { $rows[] = (array) $row; }
		return $rows;
	}
}
