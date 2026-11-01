#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Render the shipped admin views with synthetic read-only dependencies.
 * Never bootstrap an installation, read credentials or open a database.
 */
define('DOL_DOCUMENT_ROOT', getenv('KREABANK_DOLIBARR_ROOT') ?: dirname(__DIR__, 3));
$conf = (object) array('entity'=>1, 'global'=>new stdClass());
$user = new class {
	public $admin=1;
	public $socid=0;
	public function hasRight(...$parts) { return true; }
};
$langs = new class {
	public function load($file) {}
	public function loadLangs($files) {}
	public function trans($key, ...$values) { return $key; }
};
$db = new class {
	public function prefix() { return 'synthetic_'; }
	public function query($sql) {
		if (strpos($sql,'SELECT ')!==0) { throw new RuntimeException('Admin GET attempted a write'); }
		$rows = array();
		if (strpos($sql,'synthetic_bank_account')!==false) {
			$rows[]=(object) array('rowid'=>101,'ref'=>'Synthetic bank','label'=>'Synthetic main account','currency_code'=>'EUR','clos'=>0,'courant'=>1,'rappro'=>1);
		} elseif (strpos($sql,"name='KREABANK_ACCOUNT_PAIRS'")!==false) {
			$rows[]=(object) array('value'=>'{}');
		}
		return (object) array('rows'=>$rows,'position'=>0);
	}
	public function fetch_object($result) { return $result->rows[$result->position++] ?? false; }
	public function close() {}
};
function isModEnabled($module) { return true; }
function GETPOST($key,$type='') { return ''; }
function GETPOSTINT($key) { return 0; }
function GETPOSTISSET($key) { return false; }
function newToken() { return 'synthetic-csrf-token'; }
function dol_buildpath($path,$absolute=0) { return $path; }
function dol_escape_htmltag($text) { return htmlspecialchars((string) $text,ENT_QUOTES,'UTF-8'); }
function complete_head_from_modules($conf,$langs,$object,&$head,&$position,$module,$mode='') {}
function llxHeader($help='',$title='') { print '<!doctype html><html><body>'; }
function llxFooter() { print '</body></html>'; }
function load_fiche_titre($title,$link='',$icon='') { return '<h1>'.dol_escape_htmltag($title).'</h1>'; }
function dol_get_fiche_head($head,$selected,$title,$level,$icon) { return '<main>'; }
function dol_get_fiche_end() { return '</main>'; }
function accessforbidden() { throw new RuntimeException('Unexpected admin authorization rejection'); }
function renderAdminFixture($page)
{
	global $db,$conf,$langs,$user;
	$path=__DIR__.'/../admin/'.$page.'.php';
	$source=file_get_contents($path);
	$start=strpos($source,'$res = @include');
	$end=strpos($source,'require_once',$start);
	if ($start===false || $end===false) { throw new RuntimeException('Review the admin bootstrap boundary'); }
	// Replace only the installation bootstrap; execute the real shipped view and its module dependencies.
	$source=substr($source,0,$start).substr($source,$end);
	$source=str_replace('__DIR__',var_export(dirname($path),true),$source);
	$_SERVER['PHP_SELF']='/custom/kreabank/admin/'.$page.'.php';
	$_SERVER['REQUEST_METHOD']='GET';
	ob_start();
	try { eval('?>'.$source); return ob_get_contents(); }
	finally { ob_end_clean(); }
}
foreach (array('automation'=>'paired_transfers','pairs'=>'restricted_account') as $page=>$field) {
	$html=renderAdminFixture($page);
	if (strpos($html,'name="'.$field.'"')===false || strpos($html,'</html>')===false) {
		throw new RuntimeException('Admin view did not render its configuration form: '.$page);
	}
}
echo "OK: 2 self-contained admin view renders (synthetic, read-only)\n";
