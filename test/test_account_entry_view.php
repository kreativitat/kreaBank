#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Render only the actual accounts.php row template with synthetic read-only data.
 * No application bootstrap, credentials, database connection or financial write.
 */
error_reporting(E_ALL);
set_error_handler(static function ($level, $message) { throw new RuntimeException($message); });
require_once __DIR__ . '/../lib/kreabank_navigation.lib.php';

function dol_buildpath($path, $mode = 0) { return '/erp' . $path; }
function dol_escape_htmltag($value) { return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function kreabankPrice($value) { return number_format((float) $value, 2, '.', ''); }
function dol_now() { return 1794744000; }
function dol_print_date($value, $format) { return gmdate('Y-m-d H:i', $value); }
function img_picto($label, $icon, $attributes = '') {
	return '<span data-fixture-icon="' . dol_escape_htmltag($icon) . '" ' . $attributes . '></span>';
}
class AccountEntryViewLangs
{
	public $labels = array('Modify'=>'Modificar');
	public function __construct() {
		foreach (file(__DIR__ . '/../langs/pt_PT/kreabank.lang') as $line) {
			if (strpos($line, '=') !== false && substr(ltrim($line), 0, 1) !== '#') {
				list($key, $value) = explode('=', trim($line), 2); $this->labels[$key] = $value;
			}
		}
	}
	public function trans($key) { return $this->labels[$key] ?? $key; }
}
class AccountEntryViewResult
{
	public $rows;
	public function __construct($rows) { $this->rows = $rows; }
}
class AccountEntryViewDb
{
	public function fetch_object($result) { return array_shift($result->rows); }
	public function query($sql) { throw new RuntimeException('The account entry row attempted a database query'); }
}

$source = file_get_contents(__DIR__ . '/../accounts.php');
if (!preg_match('/\$count\s*=\s*0\s*;/', $source, $match, PREG_OFFSET_CAPTURE)) { throw new RuntimeException('Account row template start changed'); }
$start = $match[0][1]; $end = strpos($source, "print '</table></div>'", $start);
if ($end === false) { throw new RuntimeException('Account row template end changed'); }
$template = substr($source, $start, $end-$start);
$langs = new AccountEntryViewLangs();
$rowDefaults = array('account_type'=>1,'currency_code'=>'EUR','feed_balance'=>null,'feed_status'=>'unmapped',
	'bank_updated_at'=>null,'balance'=>'1250.12500000','pending_count'=>4);
$fixtures = array(
	(object) array_merge($rowDefaults,array('rowid'=>17,'ref'=>'BANK & "A" <alpha>','label'=>'Account <script>unsafe()</script> & "one"')),
	(object) array_merge($rowDefaults,array('rowid'=>9042,'account_type'=>0,'ref'=>'BANK-B & <beta>','label'=>'Second bank & "two"','pending_count'=>9)),
	(object) array_merge($rowDefaults,array('rowid'=>303,'account_type'=>2,'ref'=>'CASH & "desk"','label'=>'Cash <strong>evidence</strong>','pending_count'=>2)),
);
$render = static function ($fixtures, $offset = 0, $limit = 25) use ($template, $langs) {
	$db = new AccountEntryViewDb(); $rows = new AccountEntryViewResult($fixtures);
	$paging = array('offset'=>$offset,'limit'=>$limit); $total = count($fixtures);
	$accountColumns = array_fill_keys(array('ref','currency_code','feed_balance','bank_updated_at','balance','pending_count'), '');
	// Stale overview and write inputs must never enter an operational destination URL.
	$_GET = array('kind'=>'closed','account_context'=>999,'action'=>'undo','token'=>'private-token','line_id'=>999,'view'=>'reconcile'); $_POST = array();
	ob_start();
	try { print '<table><tbody>'; eval($template); print '</tbody></table>'; return ob_get_clean(); }
	catch (Throwable $error) { ob_end_clean(); throw $error; }
};
$checks = 0;
function accountEntryCheck($condition, $message) {
	global $checks;
	if (!$condition) { throw new RuntimeException($message); } $checks++;
}
function accountEntryDom($html) {
	$previous = libxml_use_internal_errors(true); $dom = new DOMDocument();
	$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html); libxml_clear_errors(); libxml_use_internal_errors($previous);
	return new DOMXPath($dom);
}
function accountEntryUrl($link) {
	$parts = parse_url($link->getAttribute('href')); $query = array(); parse_str($parts['query'] ?? '', $query);
	return array($parts['path'] ?? '',$query);
}
$before = serialize($fixtures); $html = $render($fixtures); $xpath = accountEntryDom($html);
accountEntryCheck($xpath->query('//tbody/tr')->length === 3, 'Actual template renders two bank accounts and a cash account');
foreach ($fixtures as $index=>$fixture) {
	$row = $xpath->query('//tbody/tr')->item($index);
	$name = $xpath->query('.//a[@class="kb-account-name"]',$row)->item(0);
	$count = $xpath->query('.//a[@class="kb-count"]',$row)->item(0);
	$edit = $xpath->query('.//a[@class="kb-account-edit"]',$row)->item(0);
	accountEntryCheck($name && $count && $edit && $name->textContent === $fixture->ref
		&& $xpath->query('.//td[1]/small',$row)->item(0)->textContent === $fixture->label, 'Reference and caption are escaped without rewriting source text for account ' . $fixture->rowid);
	accountEntryCheck($count->textContent === (string) $fixture->pending_count, 'Pending counts remain attached to their own account ' . $fixture->rowid);
	list($namePath,$nameQuery) = accountEntryUrl($name); list($countPath,$countQuery) = accountEntryUrl($count); list($editPath,$editQuery) = accountEntryUrl($edit);
	if ((int) $fixture->account_type === 2) {
		accountEntryCheck($namePath === '/erp/compta/bank/bankentries_list.php' && ($nameQuery['id'] ?? '') === (string) $fixture->rowid
			&& $count->getAttribute('href') === $name->getAttribute('href'), 'Cash reference and count retain their native account movement URL');
		accountEntryCheck($xpath->query('.//a[@class="kb-account-statement" or @class="kb-account-reconcile"]',$row)->length === 0, 'Cash has no bank statement or reconciliation action');
	} else {
		$statement = $xpath->query('.//a[@class="kb-account-statement"]',$row)->item(0);
		accountEntryCheck($namePath === '/erp/custom/kreabank/bankstatement.php' && ($nameQuery['account_context'] ?? '') === (string) $fixture->rowid
			&& !isset($nameQuery['search_account'],$nameQuery['id']), 'Bank reference opens its exact owned statement account ' . $fixture->rowid);
		accountEntryCheck($statement && $statement->getAttribute('href') === $name->getAttribute('href')
			&& $xpath->query('.//span[@data-fixture-icon="fa-file-alt" and @aria-hidden="true"]',$statement)->length === 1, 'Bank statement action uses the reference URL and statement icon for account ' . $fixture->rowid);
		$label = $langs->trans('KreaBankBankStatement') . ' ' . $fixture->ref;
		accountEntryCheck($statement->getAttribute('title') === $label && $statement->getAttribute('aria-label') === $label
			&& $xpath->query('.//a[@class="kb-account-reconcile"]',$row)->length === 0, 'Statement action has its translated accessible label instead of reconciliation wording');
		accountEntryCheck($countPath === '/erp/custom/kreabank/reconciliationlist.php' && ($countQuery['search_account'] ?? '') === (string) $fixture->rowid
			&& !isset($countQuery['account_context'],$countQuery['id']) && $count->getAttribute('href') !== $name->getAttribute('href'), 'Pending count still opens the separate reconciliation list for account ' . $fixture->rowid);
	}
	accountEntryCheck($editPath === '/erp/compta/bank/card.php' && ($editQuery['id'] ?? '') === (string) $fixture->rowid
		&& $edit->getAttribute('aria-label') === $langs->trans('Modify') . ' ' . $fixture->ref && $edit->getAttribute('title') === $langs->trans('Modify')
		&& $xpath->query('.//span[@data-fixture-icon="edit"]',$edit)->length === 1, 'Native pencil retains its account detail URL and modification label');
	foreach ($xpath->query('.//a',$row) as $link) {
		list($path,$query) = accountEntryUrl($link);
		accountEntryCheck(!array_intersect(array_keys($query),array('kind','action','token','line_id','view','review','reconciliation_id'))
			&& ($query['mainmenu'] ?? '') === 'bank', 'Operational links retain bank context and exclude overview filters/write authority');
	}
}
accountEntryCheck($xpath->query('//script|//alpha|//beta|//strong')->length === 0 && strpos($html,'&lt;script&gt;unsafe()&lt;/script&gt;') !== false,
	'Untrusted account labels cannot create markup in names, captions or action attributes');
accountEntryCheck(serialize($fixtures) === $before, 'Row rendering changes no source account evidence');
$page = accountEntryDom($render($fixtures,1,1));
accountEntryCheck($page->query('//tbody/tr')->length === 1 && $page->query('//a[@class="kb-account-name"]')->item(0)->textContent === $fixtures[1]->ref,
	'Native pagination renders the requested account without reusing the first row identity');
list($pagePath,$pageQuery) = accountEntryUrl($page->query('//a[@class="kb-account-statement"]')->item(0));
accountEntryCheck($pagePath === '/erp/custom/kreabank/bankstatement.php' && ($pageQuery['account_context'] ?? '') === '9042', 'Paginated statement action retains the displayed account ID');
print 'OK: ' . $checks . " account entry row-template checks\n";
