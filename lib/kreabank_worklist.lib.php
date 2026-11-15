<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/** This list approves recorded movements/payments; unpaid documents remain review-only. */
function kreabankWorklistPairCanReconcile($pair)
{
	return in_array($pair['suggestion']['doc_type'] ?? '', array('native_bank','payment','payment_supplier','payment_linked','payment_supplier_linked','payment_tax'), true);
}

/** Status is persisted source/link evidence and private planning state, never a matching result. */
function kreabankWorklistProjectionStatus($source, $hasDraft = false)
{
	require_once __DIR__ . '/kreabank_integration.lib.php';
	$nativeState=kreabankIntegrationLineState((object)$source,(string)($source['statement_ref']??''));
	if ($nativeState==='KreaBankLinkNeedsReview') { return 'review'; }
	if ($nativeState==='KreaBankReconciled') { return 'reconciled'; }
	return $hasDraft ? 'draft' : 'pending';
}

/** Match the existing Pending source gate; historic closed/skipped rows remain available in All. */
function kreabankWorklistPendingSource($source)
{
	return in_array((int)$source['status'],array(0,1),true) && (int)($source['rappro']??0)===0
		&& (int)($source['account_closed']??1)===0 && (int)($source['account_reconcilable']??0)===1;
}

/** Exact scoped counts never turn an incomplete derived filter into an apparent empty result. */
function kreabankWorklistStatusCounts($sources, $state, $summaries = array(), $complete = false)
{
	$unknown=array_fill_keys(array('pending','reconciled','draft','review','all'),null);
	$derived=$state['filters']['match']!=='' || $state['filters']['payment_account']!=='';
	if ($derived && !$complete) { return $unknown; }
	$rows=array();
	foreach ($sources as $key=>$source) {
		if ($derived && (!isset($summaries[$key]) || !array_key_exists('match',$summaries[$key]) || !array_key_exists('payment_accounts',$summaries[$key]))) { return $unknown; }
		$row=$derived ? $summaries[$key] : array();
		$row['key']=$key; $row['line']=$source; $row['status']=$source['worklist_status']; $rows[]=$row;
	}
	$state['filters']['status']='all';
	$rows=kreabankWorklistFilterRows($rows,$state);
	$counts=array_fill_keys(array_keys($unknown),0);
	foreach ($rows as $row) {
		$counts['all']++; $status=$row['status'];
		if ($status==='review' || $status==='reconciled') { $counts[$status]++; }
		elseif (kreabankWorklistPendingSource($row['line'])) {
			$counts['pending']++;
			if ($status==='draft') { $counts['draft']++; }
		}
	}
	return $counts;
}

/** Unpaid documents show a planned account; existing payments retain their recorded account. */
function kreabankWorklistPaymentAccount($pair)
{
	$suggestion = $pair['suggestion'];
	$planned = in_array($suggestion['doc_type'], array('customer_invoice','supplier_invoice','tax','salary'), true);
	return array('id'=>(int) ($planned ? ($pair['line']['bank_account_id'] ?? 0) : ($suggestion['bank_account_id'] ?? 0)),
		'ref'=>(string) ($planned ? ($pair['line']['bank_account_ref'] ?? '') : ($suggestion['bank_account_ref'] ?? '')), 'planned'=>$planned);
}

/** A cached review may render only beneath the exact source it describes. */
function kreabankWorklistSelectionReviewMatches($stored, $key, $line, $scope, $now)
{
	if (!is_array($stored) || ($stored['scope'] ?? '') !== $scope || ($stored['expires'] ?? 0) < $now
		|| ($stored['payload']['key'] ?? '') !== $key) { return false; }
	if (($stored['kind'] ?? '') === 'pair' && !empty($stored['payload']['selection'])) {
		$source = $stored['payload']['pair']['line'] ?? array();
		return (int) ($source['rowid'] ?? 0) === (int) $line['rowid']
			&& (int) ($source['bank_account_id'] ?? 0) === (int) $line['bank_account_id']
			&& (!empty($source['fk_statement']) ? 'staged:' : 'native:') . (int) ($source['rowid'] ?? 0) === $key;
	}
	$batch = $stored['payload']['batch'] ?? array(); $source = $batch['source'] ?? array();
	return ($stored['kind'] ?? '') === 'batch' && ($batch['action'] ?? '') === 'reconcile'
		&& $key === 'staged:' . (int) ($batch['staging_id'] ?? 0)
		&& (int) ($source['rowid'] ?? 0) === (int) $line['rowid']
		&& (int) ($source['bank_account_id'] ?? 0) === (int) $line['bank_account_id'];
}

/** Open an existing native invoice-payment form; this helper never records or reconciles. */
function kreabankWorklistPaymentUrl($pair, $permissions, $companyCurrency)
{
	$s = $pair['suggestion'] ?? array(); $line = $pair['line'] ?? array();
	$type = $s['doc_type'] ?? '';
	$paths = array('customer_invoice'=>'/compta/paiement.php','supplier_invoice'=>'/fourn/facture/paiement.php');
	if (!isset($paths[$type]) || empty($permissions[$type]) || empty($line['fk_statement']) || empty($line['account_open'])
		|| (int) ($line['status'] ?? -1) !== 0 || !empty($line['native_bank_line_id']) || !empty($line['needs_review'])
		|| $companyCurrency === '' || ($line['currency'] ?? '') !== $companyCurrency
		|| (int) ($s['doc_id'] ?? 0) <= 0 || (int) ($line['bank_account_id'] ?? 0) <= 0) { return ''; }
	foreach (array($s['doc_id'],$line['bank_account_id'],$line['fk_statement']) as $id) {
		if (!is_scalar($id) || !preg_match('/^[1-9][0-9]{0,9}$/D',(string) $id)) { return ''; }
	}
	$day = substr((string) ($line['operation_date'] ?? ''),0,10);
	if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D',$day,$date) || !checkdate((int) $date[2],(int) $date[3],(int) $date[1])) { return ''; }
	try {
		if (kreabankWorklistCompareDecimal($line['allocated_amount'] ?? '0','0') !== 0
			|| kreabankWorklistCompareDecimal($line['amount_decimal'] ?? $line['amount'] ?? '0','0') !== ($type === 'customer_invoice' ? 1 : -1)) { return ''; }
		$source = ltrim((string) ($line['amount_decimal'] ?? $line['amount']),'+-');
		$open = ltrim((string) ($s['amount_open'] ?? ''),'+-');
		if (kreabankWorklistCompareDecimal($open,'0') <= 0) { return ''; }
		$amount = kreabankWorklistCompareDecimal($source,$open) <= 0 ? $source : $open;
	} catch (InvalidArgumentException $e) { return ''; }
	return $paths[$type] . '?' . http_build_query(array('action'=>'create','facid'=>(int) $s['doc_id'],'accountid'=>(int) $line['bank_account_id'],
		'year'=>(int) $date[1],'month'=>(int) $date[2],'day'=>(int) $date[3],
		'reyear'=>(int) $date[1],'remonth'=>(int) $date[2],'reday'=>(int) $date[3], 'amount_' . (int) $s['doc_id']=>$amount),'','&',PHP_QUERY_RFC3986);
}

/** Invalidate only this row after a native payment POST; cancellation never calls this. */
function kreabankWorklistRefreshPayment(&$session, $key)
{
	$state=$session['kreabank_worklist_scan']['sources'][$key]['footprint']??null;
	if ($state) { kreabankWorklistInvalidateScan($session,array('keys'=>array($key),'financial'=>true,'footprints'=>array($state))); }
	foreach ((array) ($session['kreabank_worklist_reviews'] ?? array()) as $nonce=>$review) {
		if (($review['payload']['key'] ?? '') === $key) { unset($session['kreabank_worklist_reviews'][$nonce]); }
	}
	unset($session['kreabank_worklist_batch_review'][$key]);
}

/** Request state for the separate list. Financial actions never enter read URLs. */
function kreabankWorklistState($request, $defaultLimit = 10)
{
	$columns = array('operation_date','bank_account_ref','description','amount','match','payment_account','status');
	$clear = false;
	foreach (array('button_removefilter','button_removefilter_x','button_removefilter.x') as $button) { if (isset($request[$button])) { $clear = true; } }
	$status = is_scalar($request['search_status'] ?? '') ? (string) ($request['search_status'] ?? '') : '';
	$status = in_array($status,array('pending','draft','reconciled','review','all'),true) ? $status : 'pending';
	$accountContext = is_scalar($request['search_account'] ?? '') ? trim((string) ($request['search_account'] ?? '')) : '';
	$accountContext = ctype_digit($accountContext) && (int) $accountContext > 0 ? (string) (int) $accountContext : '';
	$filters = array();
	foreach (array('date_from','date_to','account','description','amount','match','payment_account','status') as $name) {
		$value = $clear ? '' : ($request['search_' . $name] ?? '');
		$filters[$name] = is_scalar($value) ? trim((string) $value) : '';
		if (strlen($filters[$name]) > 256) { throw new InvalidArgumentException('KreaBankWorklistInvalidFilter'); }
	}
	foreach (array('date_from','date_to') as $name) {
		$v = $filters[$name];
		if ($v !== '' && (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $v, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1]))) {
			throw new InvalidArgumentException('KreaBankWorklistInvalidFilter');
		}
	}
	if ($filters['date_from'] !== '' && $filters['date_to'] !== '' && $filters['date_from'] > $filters['date_to']) {
		throw new InvalidArgumentException('KreaBankWorklistInvalidFilter');
	}
	$filters['account'] = $clear ? $accountContext : (ctype_digit($filters['account']) ? (string) max(0, (int) $filters['account']) : '');
	if ($filters['account'] === '0') { $filters['account'] = ''; }
	if ($filters['payment_account'] !== 'none') {
		$filters['payment_account'] = ctype_digit($filters['payment_account']) ? (string) max(0, (int) $filters['payment_account']) : '';
		if ($filters['payment_account'] === '0') { $filters['payment_account'] = ''; }
	}
	$filters['status'] = $clear ? $status : (in_array($filters['status'], array('pending','draft','reconciled','review','all'), true) ? $filters['status'] : 'pending');
	if ($filters['amount'] !== '' && !preg_match('/^(?:>=|<=|<>|>|<|=)?\s*[+-]?\d+(?:[.,]\d{1,8})?$/D', $filters['amount'])) {
		throw new InvalidArgumentException('KreaBankWorklistInvalidFilter');
	}
	$sort = is_scalar($request['sortfield'] ?? '') ? (string) ($request['sortfield'] ?? '') : '';
	$sort = in_array($sort, $columns, true) ? $sort : 'operation_date';
	$order = strtoupper(is_scalar($request['sortorder'] ?? '') ? (string) ($request['sortorder'] ?? '') : '');
	$order = in_array($order, array('ASC','DESC'), true) ? $order : ($sort === 'operation_date' ? 'DESC' : 'ASC');
	$limit = (int) ($request['limit'] ?? 0);
	if ($limit <= 0) { $limit = max(1,(int) $defaultLimit); }
	$limit = min(100,$limit);
	$page = isset($request['pageplusone']) ? (int) $request['pageplusone'] - 1 : (int) ($request['page'] ?? 0);
	$page = max(0, $page);
	$jump = isset($request['pageplusone'], $request['pageplusoneold']) && (int) $request['pageplusone'] !== (int) $request['pageplusoneold'];
	foreach (array('button_search','button_search_x','button_search.x','button_removefilter','button_removefilter_x','button_removefilter.x') as $button) {
		if (array_key_exists($button, $request)) {
			if ($clear || !$jump) { $page = 0; }
			if (strpos($button, 'removefilter') !== false) {
				$filters = array_fill_keys(array_keys($filters), '');
				$filters['status'] = $status;
				$filters['account'] = $accountContext;
			}
		}
	}
	$params = array('mainmenu'=>'bank','leftmenu'=>'kreabank_worklist','sortfield'=>$sort,'sortorder'=>$order,'limit'=>$limit);
	foreach ($filters as $name=>$value) { if ($value !== '') { $params['search_' . $name] = $value; } }
	return array('filters'=>$filters,'sort'=>array('field'=>$sort,'order'=>$order,'prefix'=>''),'page'=>$page,'limit'=>$limit,'params'=>$params);
}

/** Compare arbitrary signed eight-place database decimals without floating point. */
function kreabankWorklistCompareDecimal($left, $right)
{
	$normalize = static function ($value) {
		$value = str_replace(',', '.', trim((string) $value));
		if (!preg_match('/^([+-]?)(\d+)(?:\.(\d{1,8}))?$/D', $value, $m)) { throw new InvalidArgumentException('Invalid decimal'); }
		$digits = ltrim($m[2] . str_pad($m[3] ?? '', 8, '0'), '0') ?: '0';
		return array($digits !== '0' && $m[1] === '-', $digits);
	};
	list($negativeA,$a) = $normalize($left); list($negativeB,$b) = $normalize($right);
	if ($negativeA !== $negativeB) { return $negativeA ? -1 : 1; }
	$compare = strlen($a) === strlen($b) ? strcmp($a, $b) : strlen($a) <=> strlen($b);
	return ($negativeA ? -1 : 1) * ($compare <=> 0);
}

function kreabankWorklistAmountMatches($amount, $filter)
{
	if ($filter === '') { return true; }
	if (!preg_match('/^(>=|<=|<>|>|<|=)?\s*([+-]?\d+(?:[.,]\d{1,8})?)$/D', $filter, $m)) { return false; }
	$comparison = kreabankWorklistCompareDecimal($amount, $m[2]);
	switch ($m[1] ?: '=') {
		case '>=': return $comparison >= 0;
		case '<=': return $comparison <= 0;
		case '>': return $comparison > 0;
		case '<': return $comparison < 0;
		case '<>': return $comparison !== 0;
		default: return $comparison === 0;
	}
}

/** Filter the complete prepared population, never a visible page. */
function kreabankWorklistFilterRows($rows, $state)
{
	$f = $state['filters'];
	$contains = static function ($haystack, $needle) {
		return $needle === '' || (function_exists('mb_stripos') ? mb_stripos((string) $haystack, $needle, 0, 'UTF-8') !== false : stripos((string) $haystack, $needle) !== false);
	};
	$rows = array_values(array_filter($rows, static function ($row) use ($f, $contains) {
		$l = $row['line'];
		$date = substr((string) ($l['operation_date'] ?? ''), 0, 10);
		$status = $row['status'] ?? ((int) ($l['status'] ?? 0) === 2 ? 'reconciled' : 'pending');
		if (($f['account'] !== '' && (int) $l['bank_account_id'] !== (int) $f['account'])
			|| ($f['date_from'] !== '' && $date < $f['date_from']) || ($f['date_to'] !== '' && $date > $f['date_to'])
			|| !$contains(($l['description'] ?? '') . ' ' . ($l['payment_reference'] ?? '') . ' ' . ($l['bank_reference'] ?? ''), $f['description'])
			|| !kreabankWorklistAmountMatches($l['amount'], $f['amount']) || !$contains($row['match'] ?? '', $f['match'])
			|| ($f['status'] === 'pending' && !in_array($status, array('pending','draft'), true))
			|| ($f['status'] !== 'pending' && $f['status'] !== 'all' && $f['status'] !== $status)) { return false; }
		$accounts = $row['payment_accounts'] ?? array();
		if ($f['payment_account'] === 'none') { return !$accounts; }
		return $f['payment_account'] === '' || in_array((int) $f['payment_account'], array_map('intval', $accounts), true);
	}));
	$sort = $state['sort'];
	usort($rows, static function ($a, $b) use ($sort) {
		$field = $sort['field'];
		if ($field === 'amount') { $compare = kreabankWorklistCompareDecimal($a['line']['amount'], $b['line']['amount']); }
		else {
			$va = $a['line'][$field] ?? $a[$field] ?? ''; $vb = $b['line'][$field] ?? $b[$field] ?? '';
			$compare = strnatcasecmp((string) $va, (string) $vb);
		}
		return ($compare ? ($sort['order'] === 'DESC' ? -$compare : $compare) : strcmp($a['key'], $b['key']));
	});
	return $rows;
}

/** Native page/limit semantics applied only after complete filtering and sorting. */
function kreabankWorklistPage($rows, $state)
{
	$total = count($rows); $page = $state['page']; $limit = $state['limit'];
	if ($page * $limit >= $total) { $page = 0; }
	return array('rows'=>array_slice($rows, $page * $limit, $limit),'total'=>$total,'page'=>$page,'limit'=>$limit,'num'=>min($limit + 1, max(0, $total - $page * $limit)));
}

/** Only an explicit More request can grant matching outside the displayed page. */
function kreabankWorklistScanTargets($sourceKeys, $displayKeys, $summaries, $limit, $more = false)
{
	$owned = array_fill_keys($sourceKeys, true);
	$keys = $more ? array_values(array_filter($sourceKeys, static function ($key) use ($summaries) { return !isset($summaries[$key]); })) : ($displayKeys ?: $sourceKeys);
	return array_slice(array_values(array_unique(array_filter($keys, static function ($key) use ($owned) { return isset($owned[$key]); }))), 0, max(1, min(100, (int) $limit)));
}

/** A bounded read worker never widens its server-granted target list. */
function kreabankWorklistAdvanceScan(&$scan, $prepare)
{
	$started = microtime(true); $processed = 0;
	foreach ($scan['targets'] as $key) {
		if (isset($scan['summaries'][$key])) { continue; }
		if ($processed > 0 && (microtime(true) - $started >= 1.2 || $processed >= 8)) { break; }
		kreabankWorklistStoreSummary($scan,$key,$prepare($key)); $processed++;
	}
}

/** Current source evidence, independent of source order and page membership. */
function kreabankWorklistSourceFingerprint($source)
{
	ksort($source);
	return hash('sha256',json_encode($source,JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
}

/** Permissions and employee scope can change while the same user/company remain selected. */
function kreabankWorklistAccessFingerprint($user,$flags=array())
{
	$rights=array();
	foreach (array(array('banque','lire'),array('banque','modifier'),array('banque','consolidate'),
		array('kreabank','reconciliation','read'),array('kreabank','reconciliation','write'),array('kreabank','reconciliation','undo'),
		array('facture','lire'),array('facture','paiement'),array('fournisseur','facture','lire'),array('fournisseur','facture','creer'),
		array('supplier_invoice','lire'),array('supplier_invoice','creer'),array('tax','charges','lire'),array('tax','charges','creer'),
		array('salaries','read'),array('salaries','readall'),array('salaries','write')) as $right) {
		$rights[implode('.',$right)]=method_exists($user,'hasRight') ? (bool)$user->hasRight(...$right) : false;
	}
	$children=array();
	if ($rights['salaries.read'] && !$rights['salaries.readall'] && method_exists($user,'getAllChildIds')) {
		$children=array_map('intval',array_values((array)$user->getAllChildIds(1))); sort($children,SORT_NUMERIC);
	}
	return hash('sha256',json_encode(array($rights,$children,$flags,$user->entity??0,$user->socid??0,$user->statut??1)));
}

function kreabankWorklistFootprint($record,$fallback=array())
{
	$amount=$record['amount_decimal']??$record['amount_open']??$record['amount']??$fallback['amount_decimal']??$fallback['amount']??'';
	if (is_float($amount)) { $amount=number_format($amount,8,'.',''); }
	return array('account'=>(int)($record['bank_account_id']??$record['fk_account']??$fallback['bank_account_id']??0),
		'currency'=>(string)($record['currency']??$fallback['currency']??''),'amount'=>(string)$amount,
		'day'=>substr((string)($record['operation_date']??$record['doc_date']??$record['dateo']??$record['date']??$fallback['operation_date']??''),0,10));
}

/** Dependencies are display invalidation hints, never matching/approval evidence. */
function kreabankWorklistDependencies($prepared)
{
	if (isset($prepared['suggestion'])) { $prepared=array('line'=>$prepared['line'],'pair'=>$prepared); }
	$line=$prepared['line']??array(); $tokens=array(); $footprints=array(kreabankWorklistFootprint($line));
	$add=static function($type,$id)use(&$tokens){if((int)$id>0){$tokens[$type.':'.(int)$id]=true;}};
	$records=array();
	if (!empty($prepared['pair']['suggestion'])) { $records[]=$prepared['pair']['suggestion']; }
	foreach (($prepared['account_candidates']??array()) as $candidate) { $records[]=$candidate; }
	foreach (array('active','draft') as $kind) {
		$batch=$prepared['batch'][$kind]??array();
		foreach (($batch['components']??array()) as $component) { $records[]=$component; }
		foreach ((array)($batch['ids']??array()) as $id) { $add('native_bank',$id); }
	}
	foreach ($records as $record) {
		$type=$record['doc_type']??$record['payment_type']??'native_bank';
		$type=str_replace(array('payment_supplier_linked','payment_linked'),array('payment_supplier','payment'),$type);
		$add($type,$record['doc_id']??$record['payment_id']??$record['fk_bank_line']??($type==='native_bank'?($record['rowid']??$record['id']??0):0));
		foreach (array('native_bank_line_id','bank_line_id','fk_bank_line') as $field) { $add('native_bank',$record[$field]??0); }
		foreach (array('customer_payment_id'=>'payment','supplier_payment_id'=>'payment_supplier','tax_id'=>'tax','tax_payment_id'=>'payment_tax') as $field=>$kind) { $add($kind,$record[$field]??0); }
		$footprints[]=kreabankWorklistFootprint($record,$line);
	}
	return array('tokens'=>array_keys($tokens),'footprints'=>$footprints);
}

function kreabankWorklistSourceState($sources)
{
	$out=array();
	foreach ($sources as $key=>$source) { $out[$key]=array('hash'=>kreabankWorklistSourceFingerprint($source),'footprint'=>kreabankWorklistFootprint($source)); }
	return $out;
}

/** Exact shared targets plus conservative amount/date peers, including prior no-pair rows. */
function kreabankWorklistAffectedKeys($scan,$effect)
{
	$keys=array_fill_keys((array)($effect['keys']??array()),true); $tokens=array_fill_keys((array)($effect['tokens']??array()),true);
	foreach (($scan['sources']??array()) as $key=>$state) {
		if (array_intersect_key(array_fill_keys($scan['summaries'][$key]['dependencies']['tokens']??array(),true),$tokens)) { $keys[$key]=true; }
		if (empty($effect['financial'])) { continue; }
		$peer=$state['footprint'];
		foreach (($effect['footprints']??array()) as $changed) {
			if ($changed['currency']!=='' && $peer['currency']!==$changed['currency']) { continue; }
			try { if (kreabankWorklistCompareDecimal($peer['amount'],$changed['amount'])!==0) { continue; } }
			catch (InvalidArgumentException $error) { /* Incomplete evidence must invalidate conservatively. */ }
			$a=strtotime($peer['day'].' UTC'); $b=strtotime($changed['day'].' UTC');
			if (empty($changed['all_dates']) && $a!==false && $b!==false && abs($a-$b)>86400*($effect['radius']??31)) { continue; }
			// Other-account advice means equal amount/date peers in other owned banks also depend on this movement.
			$keys[$key]=true;
		}
	}
	return array_keys($keys);
}

function kreabankWorklistInvalidateScan(&$session,$effect,$clearReviews=true)
{
	$scan=$session['kreabank_worklist_scan']??array();
	if (!$scan) { return; }
	if (empty($scan['context']) || !isset($scan['sources'])) {
		$scan['generation']=bin2hex(random_bytes(16)); $scan['cursor']=0; unset($scan['lease']);
		$session['kreabank_worklist_scan']=$scan; return;
	}
	foreach (($effect['keys']??array()) as $key) { $effect['tokens']=array_merge($effect['tokens']??array(),$scan['summaries'][$key]['dependencies']['tokens']??array()); }
	$keys=kreabankWorklistAffectedKeys($scan,$effect);
	foreach ($keys as $key) { unset($scan['summaries'][$key],$scan['presentations'][$key]); }
	if ($clearReviews) {
		foreach (($session['kreabank_worklist_reviews']??array()) as $nonce=>$review) {
			if (in_array($review['payload']['key']??'', $keys,true)) { unset($session['kreabank_worklist_reviews'][$nonce]); }
		}
		foreach ($keys as $key) { unset($session['kreabank_worklist_batch_review'][$key]); }
	}
	$scan['generation']=bin2hex(random_bytes(16)); $scan['cursor']=0; $scan['reuse_once']=time()+30; unset($scan['lease']);
	$session['kreabank_worklist_scan']=$scan;
}

function kreabankWorklistActionEffect($key,$prepared,$financial=true)
{
	$dependencies=kreabankWorklistDependencies($prepared);
	return $dependencies+array('keys'=>array($key),'financial'=>$financial);
}

/** Rebase presentation progress onto fresh source facts; stale POST admission happens before this. */
function kreabankWorklistRebaseScan($scan,$context,$scope,$sourceState,$now)
{
	if (($scan['context']??'')!==$context || ($scan['expires']??0)<$now || !isset($scan['sources'])) {
		return array('context'=>$context,'scope'=>$scope,'sources'=>$sourceState,'generation'=>bin2hex(random_bytes(16)),
			'cursor'=>0,'summaries'=>array(),'presentations'=>array(),'expires'=>$now+600);
	}
	if (($scan['scope']??'')!==$scope) {
		$effect=array('keys'=>array(),'tokens'=>array(),'footprints'=>array(),'financial'=>true);
		foreach (array_unique(array_merge(array_keys($scan['sources']),array_keys($sourceState))) as $key) {
			if (($scan['sources'][$key]['hash']??null)===($sourceState[$key]['hash']??null)) { continue; }
			$effect['keys'][]=$key;
			foreach (array($scan['sources'][$key]['footprint']??null,$sourceState[$key]['footprint']??null) as $footprint) { if($footprint){$effect['footprints'][]=$footprint;} }
			$effect['tokens']=array_merge($effect['tokens'],$scan['summaries'][$key]['dependencies']['tokens']??array());
		}
		foreach (kreabankWorklistAffectedKeys($scan,$effect) as $key) { unset($scan['summaries'][$key],$scan['presentations'][$key]); }
		$scan['generation']=bin2hex(random_bytes(16)); $scan['cursor']=0; unset($scan['lease'],$scan['view'],$scan['targets']);
	}
	$scan['scope']=$scope; $scan['sources']=$sourceState;
	$scan['summaries']=array_intersect_key($scan['summaries'],$sourceState);
	$scan['presentations']=array_intersect_key($scan['presentations']??array(),$sourceState);
	return $scan;
}

/** Full prepared data is retained only for one bounded current page, at a fixed expiry. */
function kreabankWorklistPresentationPage(&$scan,$keys,$limit)
{
	$scan['visible']=array_slice(array_values($keys),0,max(1,min(100,(int)$limit)));
	$scan['presentations']=array_intersect_key($scan['presentations']??array(),array_fill_keys($scan['visible'],true));
}

function kreabankWorklistStoreSummary(&$scan,$key,$summary,$now=null)
{
	$now=$now??time(); $prepared=$summary['_prepared']??null; unset($summary['_prepared']);
	$scan['summaries'][$key]=$summary;
	if ($prepared && in_array($key,$scan['visible']??array(),true) && isset($scan['sources'][$key])) {
		$scan['presentations'][$key]=array('source'=>$scan['sources'][$key]['hash'],'context'=>$scan['context'],
			'expires'=>min($scan['expires'],$now+120),'prepared'=>$prepared);
	}
}

/** Display cache only. Financial POST code must always call fresh service preparation instead. */
function kreabankWorklistPreparedPresentation(&$scan,$key,$source,$reuse,$prepare,$now=null,$visible=null)
{
	$now=$now??time(); $saved=$scan['presentations'][$key]??null;
	if ($reuse && $saved && ($saved['context']??'')===($scan['context']??'') && ($saved['expires']??0)>=$now
		&& ($scan['expires']??0)>=$now && ($saved['source']??'')===kreabankWorklistSourceFingerprint($source)
		&& is_array($saved['prepared']??null) && (int)($saved['prepared']['line']['rowid']??0)===(int)$source['rowid']
		&& (int)($saved['prepared']['line']['bank_account_id']??0)===(int)$source['bank_account_id']) {
		try { $allowed=!$visible || $visible($saved['prepared']); } catch (Throwable $error) { $allowed=false; }
		if (!$allowed) {
			unset($scan['presentations'][$key],$scan['summaries'][$key],$scan['lease']);
			$scan['generation']=bin2hex(random_bytes(16)); $scan['cursor']=0; $scan['presentation_invalidated']=true;
			throw new RuntimeException('KreaBankWorklistChanged');
		}
		return $saved['prepared'];
	}
	unset($scan['presentations'][$key]);
	try { $summary=$prepare($key); }
	catch (Throwable $error) {
		unset($scan['summaries'][$key],$scan['lease']); $scan['generation']=bin2hex(random_bytes(16));
		$scan['cursor']=0; $scan['presentation_invalidated']=true; throw $error;
	}
	if (empty($summary['_prepared'])) { throw new RuntimeException('KreaBankWorklistChanged'); }
	$prepared=$summary['_prepared']; kreabankWorklistStoreSummary($scan,$key,$summary,$now);
	return $prepared;
}

/** One current completed batch review grants at most one further batch. */
function kreabankWorklistMoreScan(&$scan, $sourceKeys, $limit, $generation, $cursor)
{
	if ($generation !== $scan['generation'] || (int) $cursor !== $scan['cursor']) { return false; }
	if (($scan['lease']['expires'] ?? 0) > time()) { return false; }
	foreach ($scan['targets'] as $key) { if (!isset($scan['summaries'][$key])) { return false; } }
	$scan['targets'] = kreabankWorklistScanTargets($sourceKeys, array(), $scan['summaries'], $limit, true);
	$scan['generation'] = bin2hex(random_bytes(16)); $scan['cursor'] = 0;
	return true;
}

/** Reject stale requests before they can replace another page's scan state. */
function kreabankWorklistScanAdmitted($scan, $scope, $generation, $cursor, $view = null)
{
	return ($scan['scope'] ?? '') === $scope && ($scan['expires'] ?? 0) >= time()
		&& isset($scan['targets']) && ($scan['generation'] ?? '') === $generation
		&& (int) ($scan['cursor'] ?? -1) === (int) $cursor && ($view === null || ($scan['view'] ?? '') === $view);
}

/** Reserve read work before releasing the PHP session lock. */
function kreabankWorklistClaimScan(&$scan)
{
	if (($scan['lease']['expires'] ?? 0) > time()) { return false; }
	$scan['lease'] = array('id'=>bin2hex(random_bytes(16)), 'expires'=>time()+90);
	return true;
}

/** A bank reference alone cannot identify which native payment the modal moves. */
function kreabankWorklistCorrectionMatches($accountCandidates, $movement)
{
	if (count($accountCandidates) !== 1) { return false; }
	$candidate = reset($accountCandidates);
	return (int) ($candidate['bank_line_id'] ?? 0) > 0
		&& (int) $candidate['bank_line_id'] === (int) $movement['rowid']
		&& (int) $candidate['bank_account_id'] === (int) $movement['fk_account'];
}

/** Source namespaces are mandatory, even when their numeric IDs happen to match. */
function kreabankWorklistSource($key)
{
	if (!is_string($key) || !preg_match('/^(staged|native):([1-9][0-9]{0,9})$/D', $key, $m)) { throw new InvalidArgumentException('KreaBankWorklistChanged'); }
	return array($m[1], (int) $m[2]);
}
