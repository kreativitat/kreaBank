<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
require_once __DIR__ . '/KreaBankDecimal.class.php';
require_once __DIR__ . '/KreaBankFintableMapper.class.php';

/** Company-local, advisory rules. This class never creates or reconciles a financial record. */
class KreaBankRule
{
	private $db;
	private $user;
	private $entity;

	public function __construct($db, $user, $entity) { $this->db = $db; $this->user = $user; $this->entity = (int) $entity; }

	public function available()
	{
		$this->assertContext();
		$rows = $this->rows("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=" . $this->text($this->db->prefix() . 'kreabank_rule'));
		return count($rows) === 1;
	}

	public function all($activeOnly = false, $lock = false)
	{
		$this->assertRead();
		if ($lock && (int) ($this->db->transaction_opened ?? $this->db->depth ?? 0) <= 0) { throw new DomainException('Rule locks require a transaction'); }
		if (!$this->available()) { return array(); }
		$sql = 'SELECT * FROM ' . $this->db->prefix() . 'kreabank_rule WHERE entity=' . $this->entity
			. ($activeOnly ? ' AND active=1' : '') . ' ORDER BY priority,rowid' . ($lock ? ' FOR UPDATE' : '');
		return array_map(array(__CLASS__, 'decode'), $this->rows($sql));
	}

	/** Rules are plain arrays so evaluation requires no Dolibarr bootstrap or database. */
	public static function evaluate($rules, $line)
	{
		$rules = array_values($rules);
		usort($rules, static function ($a, $b) { return ((int) $a['priority'] <=> (int) $b['priority']) ?: ((int) $a['rowid'] <=> (int) $b['rowid']); });
		$matches = array();
		foreach ($rules as $rule) {
			if (self::matches($rule, $line)) { $matches[] = $rule; }
		}
		return array('winner' => $matches ? $matches[0] : null, 'matches' => $matches, 'conflicts' => count($matches) > 1);
	}

	public static function matches($rule, $line)
	{
		if ((int) ($rule['active'] ?? 0) !== 1 || (int) ($rule['entity'] ?? 0) <= 0
			|| (int) ($line['entity'] ?? 0) !== (int) $rule['entity']
			|| ((int) ($rule['fk_bank_account'] ?? 0) > 0 && (int) $rule['fk_bank_account'] !== (int) ($line['bank_account_id'] ?? 0))) { return false; }
		try {
			$amount = KreaBankDecimal::normalize($line['amount'] ?? '');
			$direction = (string) ($rule['direction'] ?? '');
			if (!in_array($direction, array('in','out','both'), true)
				|| ($direction === 'in' && KreaBankDecimal::compare($amount, '0') <= 0)
				|| ($direction === 'out' && KreaBankDecimal::compare($amount, '0') >= 0)) { return false; }
			$conditions = self::validateConditions($rule['conditions'] ?? array());
			foreach ($conditions['all'] as $condition) { if (!self::conditionMatches($condition, $line)) { return false; } }
			if ($conditions['any']) {
				$any = false;
				foreach ($conditions['any'] as $condition) { if (self::conditionMatches($condition, $line)) { $any = true; break; } }
				if (!$any) { return false; }
			}
			return true;
		} catch (Throwable $e) { return false; }
	}

	public static function validateConditions($conditions)
	{
		if (!is_array($conditions) || array_diff(array_keys($conditions), array('all','any'))) { throw new InvalidArgumentException('Invalid rule condition groups'); }
		$out = array('all'=>array(), 'any'=>array());
		foreach ($out as $group=>$unused) {
			if (isset($conditions[$group]) && (!is_array($conditions[$group]) || count($conditions[$group]) > 12)) { throw new InvalidArgumentException('Too many rule conditions'); }
			foreach ($conditions[$group] ?? array() as $condition) {
				if (!is_array($condition) || array_diff(array_keys($condition), array('field','operator','value','max'))) { throw new InvalidArgumentException('Invalid rule condition'); }
				$field = (string) ($condition['field'] ?? ''); $op = (string) ($condition['operator'] ?? '');
				$value = $condition['value'] ?? null;
				$allowed = array('description'=>array('contains','not_contains','regex'), 'counterparty_name'=>array('contains'), 'iban'=>array('equals'), 'amount'=>array('equals','between'), 'currency'=>array('equals'));
				if (!isset($allowed[$field]) || !in_array($op, $allowed[$field], true) || !is_string($value) || $value === '' || strlen($value) > 256) { throw new InvalidArgumentException('Invalid rule field, operator or value'); }
				$c = array('field'=>$field, 'operator'=>$op, 'value'=>$value);
				if ($field === 'amount') {
					$c['value'] = KreaBankDecimal::normalize($value);
					if ($op === 'between') {
						$c['max'] = KreaBankDecimal::normalize($condition['max'] ?? '');
						if (KreaBankDecimal::compare($c['value'], $c['max']) > 0) { throw new InvalidArgumentException('Rule amount interval is reversed'); }
					}
				} elseif ($field === 'iban') {
					$c['value'] = KreaBankFintableMapper::iban($value);
					if ($c['value'] === '') { throw new InvalidArgumentException('Rule IBAN checksum is invalid'); }
				} elseif ($field === 'currency') {
					$c['value'] = strtoupper($value);
					if (!preg_match('/^[A-Z]{3}$/D', $c['value'])) { throw new InvalidArgumentException('Invalid rule currency'); }
				} elseif ($op === 'regex') { self::regex($value); }
				$out[$group][] = $c;
			}
		}
		if (!$out['all'] && !$out['any']) { throw new InvalidArgumentException('A rule requires at least one condition'); }
		return $out;
	}

	public static function validateAction($action)
	{
		if (!is_array($action)) { throw new InvalidArgumentException('Invalid rule action'); }
		$type = $action['type'] ?? '';
		if ($type === 'invoice_search') {
			if (!ctype_digit((string) ($action['thirdparty_id'] ?? '')) || (int) ($action['thirdparty_id'] ?? 0) <= 0 || !in_array($action['invoice_type'] ?? '', array('customer_invoice','supplier_invoice'), true)) { throw new InvalidArgumentException('Choose an existing third party and invoice type'); }
			return array('type'=>$type, 'thirdparty_id'=>(int) $action['thirdparty_id'], 'invoice_type'=>$action['invoice_type']);
		}
		if ($type === 'category') {
			if (!ctype_digit((string) ($action['category_id'] ?? '')) || (int) ($action['category_id'] ?? 0) <= 0) { throw new InvalidArgumentException('Choose a native bank movement category'); }
			return array('type'=>$type, 'category_id'=>(int) $action['category_id']);
		}
		if ($type !== 'quick_entry' || !ctype_digit((string) ($action['thirdparty_id'] ?? '')) || (int) ($action['thirdparty_id'] ?? 0) <= 0
			|| !preg_match('/^[a-z0-9_.-]{1,32}$/iD', (string) ($action['accounting_account'] ?? ''))
			|| !preg_match('/^[a-z0-9_]{1,6}$/iD', (string) ($action['payment_mode'] ?? ''))
			|| !is_string($action['label'] ?? null) || trim($action['label']) === '' || strlen($action['label']) > 255) { throw new InvalidArgumentException('Complete the quick-entry supplier, account, VAT, label and payment mode'); }
		$vat = KreaBankDecimal::normalize($action['vat'] ?? '');
		if (KreaBankDecimal::compare($vat, '0') < 0 || KreaBankDecimal::compare($vat, '100') > 0) { throw new InvalidArgumentException('Invalid rule VAT percentage'); }
		return array('type'=>$type, 'thirdparty_id'=>(int) $action['thirdparty_id'], 'accounting_account'=>$action['accounting_account'], 'vat'=>$vat, 'label'=>trim($action['label']), 'payment_mode'=>$action['payment_mode']);
	}

	public function save($data, $id = 0)
	{
		$this->assertWrite();
		$label = trim((string) ($data['label'] ?? ''));
		if ($label === '' || strlen($label) > 180 || !in_array($data['direction'] ?? '', array('in','out','both'), true)
			|| !preg_match('/^\d{1,6}$/D', (string) ($data['priority'] ?? ''))) { throw new InvalidArgumentException('Complete the rule label, direction and priority'); }
		$conditions = self::validateConditions($data['conditions'] ?? array());
		$action = self::validateAction($data['action'] ?? array());
		if (!ctype_digit((string) ($data['fk_bank_account'] ?? '0'))) { throw new InvalidArgumentException('Choose an owned account or all owned accounts'); }
		$account = (int) ($data['fk_bank_account'] ?? 0);
		if (!$this->available()) { throw new RuntimeException('Install the bank-rule schema before saving rules'); }
		if (!$this->db->begin()) { throw new RuntimeException('Cannot start bank-rule transaction'); }
		try {
			$this->rows('SELECT rowid FROM ' . $this->db->prefix() . 'bank_account WHERE entity=' . $this->entity . ' ORDER BY rowid FOR UPDATE');
			$current = null;
			foreach ($this->all(false, true) as $row) { if ((int) $row['rowid'] === (int) $id) { $current = $row; } }
			if ($id > 0 && (!$current || !hash_equals($current['revision'], (string) ($data['revision'] ?? '')))) { throw new DomainException('Rule changed; reload before saving'); }
			$this->validateReferences($account, $action, true);
			$fields = 'label=' . $this->text($label) . ',active=' . ((int) ($data['active'] ?? 0) === 1 ? '1' : '0')
				. ',priority=' . (int) $data['priority'] . ',fk_bank_account=' . ($account > 0 ? $account : 'NULL') . ',direction=' . $this->text($data['direction'])
				. ',conditions_json=' . $this->text(json_encode($conditions, JSON_THROW_ON_ERROR)) . ',action_json=' . $this->text(json_encode($action, JSON_THROW_ON_ERROR));
			if ($id > 0) {
				$this->query('UPDATE ' . $this->db->prefix() . 'kreabank_rule SET ' . $fields . ',fk_user_modif=' . (int) $this->user->id . ' WHERE entity=' . $this->entity . ' AND rowid=' . (int) $id);
			} else {
				$this->query('INSERT INTO ' . $this->db->prefix() . 'kreabank_rule SET entity=' . $this->entity . ',' . $fields . ',fk_user_creat=' . (int) $this->user->id . ',datec=' . $this->text($this->db->idate(time())));
				$id = (int) $this->db->last_insert_id($this->db->prefix() . 'kreabank_rule');
				if ($id <= 0) { throw new RuntimeException('Cannot resolve new bank rule'); }
			}
			$this->audit('rule_save', array('rule_id'=>(int) $id, 'before'=>$current,
				'after'=>array('label'=>$label,'active'=>(int) ($data['active'] ?? 0) === 1 ? 1 : 0,'priority'=>(int) $data['priority'],
					'fk_bank_account'=>$account,'direction'=>$data['direction'],'conditions'=>$conditions,'action'=>$action)));
			if (!$this->db->commit()) { throw new RuntimeException('Cannot commit bank-rule change'); }
			return (int) $id;
		} catch (Throwable $e) { $this->db->rollback(); throw $e; }
	}

	public function delete($id, $revision = '')
	{
		$this->assertWrite();
		if (!$this->db->begin()) { throw new RuntimeException('Cannot start bank-rule deletion'); }
		try {
			$current = null;
			foreach ($this->all(false, true) as $row) { if ((int) $row['rowid'] === (int) $id) { $current = $row; } }
			if (!$current || !hash_equals($current['revision'], (string) $revision)) { throw new DomainException('Rule changed; reload before deleting'); }
			$this->query('DELETE FROM ' . $this->db->prefix() . 'kreabank_rule WHERE entity=' . $this->entity . ' AND rowid=' . (int) $id);
			$this->audit('rule_delete', array('rule_id'=>(int) $id, 'before'=>$current));
			if (!$this->db->commit()) { throw new RuntimeException('Cannot commit bank-rule deletion'); }
		} catch (Throwable $e) { $this->db->rollback(); throw $e; }
	}

	/** Caller owns the reconciliation transaction and writes the associated financial audit. */
	public function recordHit($id, $revision, $line)
	{
		$this->assertRead();
		if ((int) ($this->db->transaction_opened ?? $this->db->depth ?? 0) <= 0 || !$this->user->hasRight('kreabank','reconciliation','write')
			|| (!$this->user->hasRight('banque','modifier') && !$this->user->hasRight('banque','consolidate'))) { throw new DomainException('Rule usage requires an approved reconciliation transaction'); }
		require_once __DIR__ . '/KreaBankReconciliationScope.class.php';
		(new KreaBankReconciliationScope($this->db, $this->entity))->assertLine($line);
		$advice = self::evaluate($this->all(false, true), $line);
		$rule = $advice['winner'];
		if (!$rule || (int) $rule['rowid'] !== (int) $id || !hash_equals($rule['revision'], (string) $revision)) { throw new DomainException('The displayed bank rule changed'); }
		$this->validateReferences((int) $rule['fk_bank_account'], $rule['action'], true);
		$this->query('UPDATE ' . $this->db->prefix() . 'kreabank_rule SET hit_count=hit_count+1,last_used=' . $this->text($this->db->idate(time()))
			. ' WHERE entity=' . $this->entity . ' AND rowid=' . (int) $id . ' AND active=1');
	}

	/** Bounded, read-only preview; counts do not train or update rule usage. */
	public function preview($id, $limit = 500)
	{
		$this->assertRead(); $selected = null;
		foreach ($this->all() as $rule) { if ((int) $rule['rowid'] === (int) $id) { $selected = $rule; } }
		if (!$selected) { throw new DomainException('Unknown bank rule'); }
		$selected['active'] = 1;
		$limit = max(1, min(1000, (int) $limit));
		$p = $this->db->prefix();
		$today = function_exists('dol_print_date') ? dol_print_date(time(), '%Y-%m-%d', 'tzserver') : date('Y-m-d');
		$cutoff = (new DateTimeImmutable($today))->modify('-90 days')->format('Y-m-d');
		$sql = "SELECT l.*,s.bank_account_id FROM {$p}kreabank_statement_line l JOIN {$p}kreabank_statement s ON s.rowid=l.fk_statement AND s.entity=l.entity"
			. " JOIN {$p}bank_account ba ON ba.rowid=s.bank_account_id AND ba.entity=s.entity WHERE l.entity=" . $this->entity
			. " AND s.entity=" . $this->entity . " AND ba.entity=" . $this->entity . ' AND l.operation_date>=' . $this->text($cutoff) . ' AND l.operation_date<=' . $this->text($today)
			. ((int) $selected['fk_bank_account'] > 0 ? ' AND s.bank_account_id=' . (int) $selected['fk_bank_account'] : '')
			. ' ORDER BY l.operation_date DESC,l.rowid DESC' . $this->db->plimit($limit + 1);
		$rows = $this->rows($sql); $truncated = count($rows) > $limit; $rows = array_slice($rows, 0, $limit); $matches = array();
		require_once __DIR__ . '/KreaBankReconciliationScope.class.php';
		$scope = new KreaBankReconciliationScope($this->db, $this->entity);
		foreach ($rows as $row) {
			$line = (array) $row; $scope->assertLine($line);
			if (self::matches($selected, $line)) { $matches[] = $line; }
		}
		return array('matches'=>$matches, 'scanned'=>count($rows), 'truncated'=>$truncated);
	}

	public function options()
	{
		$this->assertRead(); $p = $this->db->prefix(); $e = $this->entity;
		return array('accounts'=>$this->rows("SELECT rowid,ref,label FROM {$p}bank_account WHERE entity=$e ORDER BY ref,rowid"),
			'thirdparties'=>$this->rows("SELECT rowid,nom,fournisseur,client FROM {$p}societe WHERE entity=$e ORDER BY nom,rowid"),
			'categories'=>$this->rows("SELECT rowid,label FROM {$p}categorie WHERE entity=$e AND type=" . $this->categoryType() . ' ORDER BY label,rowid'),
			'accounting_accounts'=>$this->rows("SELECT rowid,account_number,label FROM {$p}accounting_account WHERE entity=$e AND active=1 ORDER BY account_number,rowid"),
			'payment_modes'=>$this->rows("SELECT id,code,libelle FROM {$p}c_paiement WHERE entity=$e AND active=1 ORDER BY code"));
	}

	private function validateReferences($account, $action, $lock = false)
	{
		$p = $this->db->prefix(); $e = $this->entity; $suffix = $lock ? ' FOR UPDATE' : '';
		if ($account > 0 && !$this->rows("SELECT rowid FROM {$p}bank_account WHERE entity=$e AND rowid=$account" . $suffix)) { throw new DomainException('Rule account must belong to this company'); }
		if (isset($action['thirdparty_id'])) {
			$sql = "SELECT rowid FROM {$p}societe WHERE entity=$e AND rowid=" . (int) $action['thirdparty_id'];
			$sql .= $action['type'] === 'quick_entry' || ($action['invoice_type'] ?? '') === 'supplier_invoice' ? ' AND fournisseur=1' : ' AND client IN (1,3)';
			if (!$this->rows($sql . $suffix)) { throw new DomainException('Rule third party must be an eligible current-company supplier or customer'); }
		}
		if ($action['type'] === 'category' && !$this->rows("SELECT rowid FROM {$p}categorie WHERE entity=$e AND type=" . $this->categoryType() . ' AND rowid=' . (int) $action['category_id'] . $suffix)) { throw new DomainException('Rule category must be a current-company bank movement category'); }
		if ($action['type'] === 'quick_entry') {
			if (!$this->rows("SELECT rowid FROM {$p}accounting_account WHERE entity=$e AND active=1 AND account_number=" . $this->text($action['accounting_account']) . $suffix)
				|| !$this->rows("SELECT id FROM {$p}c_paiement WHERE entity=$e AND active=1 AND code=" . $this->text($action['payment_mode']) . $suffix)) { throw new DomainException('Choose an active current-company accounting account and explicit payment mode'); }
		}
	}

	private function categoryType()
	{
		require_once DOL_DOCUMENT_ROOT . '/categories/class/categorie.class.php';
		$category = new Categorie($this->db);
		return (int) $category->getMapId()[Categorie::TYPE_BANK_LINE];
	}
	private static function decode($row)
	{
		$row = (array) $row;
		$row['conditions'] = self::validateConditions(json_decode($row['conditions_json'], true, 32, JSON_THROW_ON_ERROR));
		$row['action'] = self::validateAction(json_decode($row['action_json'], true, 32, JSON_THROW_ON_ERROR));
		$values = array();
		foreach (array('rowid','entity','label','active','priority','fk_bank_account','direction','conditions','action') as $key) { $values[$key] = $row[$key] ?? null; }
		$row['revision'] = hash('sha256', json_encode($values, JSON_THROW_ON_ERROR));
		return $row;
	}
	private static function conditionMatches($condition, $line)
	{
		$field = $condition['field']; $value = $condition['value']; $op = $condition['operator'];
		if ($field === 'amount') { $amount = KreaBankDecimal::normalize($line['amount'] ?? ''); return $op === 'equals' ? KreaBankDecimal::compare($amount, $value) === 0 : KreaBankDecimal::compare($amount, $value) >= 0 && KreaBankDecimal::compare($amount, $condition['max']) <= 0; }
		if ($field === 'iban') { $iban = KreaBankFintableMapper::iban($line['counterparty_iban'] ?? ''); return $iban !== '' && $iban === $value; }
		if ($field === 'currency') { return strtoupper((string) ($line['currency'] ?? '')) === $value; }
		$text = (string) ($line[$field] ?? '');
		if (strlen($text) > 4096) { return false; }
		if ($op === 'regex') { return @preg_match(self::regex($value), $text) === 1; }
		$contains = function_exists('mb_stripos') ? mb_stripos($text, $value, 0, 'UTF-8') !== false : stripos($text, $value) !== false;
		return $op === 'not_contains' ? !$contains : $contains;
	}
	private static function regex($body)
	{
		if (strlen($body) > 256 || strpos($body, "\0") !== false || strpos($body, '(*') !== false || strpos($body, '(?R') !== false || strpos($body, '(?0') !== false || strpos($body, '(?C') !== false) { throw new InvalidArgumentException('Unsafe rule regular expression'); }
		$pattern = '~(*LIMIT_MATCH=10000)(*LIMIT_DEPTH=1000)' . str_replace('~', '\\~', $body) . '~iu';
		if (@preg_match($pattern, '') === false) { throw new InvalidArgumentException('Invalid rule regular expression'); }
		return $pattern;
	}
	private function assertContext()
	{
		global $conf;
		if ($this->entity <= 0 || (int) $conf->entity !== $this->entity) { throw new DomainException('Bank-rule company context changed'); }
	}
	private function assertRead()
	{
		$this->assertContext();
		if (empty($this->user->id) || (int) ($this->user->statut ?? 0) !== 1 || !empty($this->user->socid)
			|| (!empty($this->user->entity) && (int) $this->user->entity !== $this->entity)
			|| !isModEnabled('kreabank') || !isModEnabled('banque')
			|| !$this->user->hasRight('kreabank','reconciliation','read') || !$this->user->hasRight('banque','lire')) { throw new DomainException('Bank-rule read permission required'); }
	}
	private function assertWrite()
	{
		$this->assertRead();
		if (!$this->user->hasRight('kreabank','rules','write') || !$this->user->hasRight('banque','modifier')) { throw new DomainException('Bank-rule and native bank write permissions required'); }
	}
	private function audit($type, $payload)
	{
		$this->query('INSERT INTO ' . $this->db->prefix() . 'kreabank_recon_audit (entity,audit_type,payload_json,fk_user,datec) VALUES (' . $this->entity . ','
			. $this->text($type) . ',' . $this->text(json_encode($payload, JSON_THROW_ON_ERROR)) . ',' . (int) $this->user->id . ',' . $this->text($this->db->idate(time())) . ')');
	}
	private function text($value) { return "'" . $this->db->escape((string) $value) . "'"; }
	private function query($sql) { $result = $this->db->query($sql); if (!$result) { throw new RuntimeException('Bank-rule database operation failed'); } return $result; }
	private function rows($sql) { $result = $this->query($sql); $rows = array(); while ($row = $this->db->fetch_object($result)) { $rows[] = $row; } return $rows; }
}
