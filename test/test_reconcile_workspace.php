#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Render the shipped reconciliation view with synthetic dependencies only.
 * No installation bootstrap, credentials, database or financial execution.
 */
error_reporting(E_ALL);
set_error_handler(static function ($severity, $message, $file, $line) { throw new RuntimeException($message . ' at ' . $file . ':' . $line); });
$root = dirname(__DIR__);
require_once $root . '/lib/kreabank_list.lib.php';
require_once $root . '/lib/kreabank_manual_review.lib.php';
// Load the real standalone renderer without the application's bootstrap/helpers.
$helperSource = file_get_contents($root . '/lib/kreabank.lib.php');
$helperStart = strpos($helperSource, 'function kreabankOtherAccountMovementsHtml(');
$helperEnd = strpos($helperSource, "/**\n * Prepare admin pages header.", $helperStart);
eval(substr($helperSource, $helperStart, $helperEnd - $helperStart));
function dol_escape_htmltag($value, ...$args) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function dol_buildpath($path, $absolute = 0) { return $path; }
function price2num($value, $mode = '') { return (float) $value; }
function kreabankPrice($value) { return number_format((float) $value, 2, ',', ' '); }
function newToken() { return 'synthetic-token'; }
function dol_now() { return 1800000000; }
function getDolGlobalInt($key) { return 0; }
function getNonce() { return 'synthetic-nonce'; }
function dol_print_date($timestamp, $format = '') { return date('d/m/Y', $timestamp); }
function kreabankStatusLabel($status, $langs) { return $langs->trans('KreaBankStatusPending'); }
function kreabankNavigationHiddenFields($id) { return '<input type="hidden" name="account_context" value="' . (int) $id . '">'; }
function kreabankWorkspaceUrl($page, $id = 0) { return '?view=' . $page . '&account_context=' . (int) $id; }
function GETPOST($key, $type = '') { return $GLOBALS['fixtureRequest'][$key] ?? ''; }
function kreabankPrintWorkspaceNavigation($page, $account) {
	global $langs;
	print '<header class="kb-header"><div class="kb-brand"><strong>KreaBank</strong><span class="kb-company">Synthetic company · UI fixture</span></div><nav class="kb-primary" aria-label="Banking">';
	foreach (array('NavMovements', 'BankStatement', 'NavStatements', 'Reconcile', 'NavConnections', 'NavHistory') as $key) {
		print '<a href="#"' . ($key === 'Reconcile' ? ' class="active"' : '') . '>' . $langs->trans('KreaBank' . $key) . '</a>';
	}
	print '</nav></header><div class="fixture-note">Synthetic data · All submissions are intercepted · No ERP connection</div>';
}
function dol_htmloutput_mesg($message, ...$args) { print '<p class="ok">' . $message . '</p>'; }
function load_fiche_titre($title, ...$args) { return '<h1>' . $title . '</h1>'; }
function llxHeader(...$args) {
	print '<!doctype html><html lang="pt-PT"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>KreaBank reconciliation - synthetic fixture</title><link rel="stylesheet" href="/custom/kreabank/css/navigation.css"><link rel="stylesheet" href="/custom/kreabank/css/lists.css"><style>body{font:14px Arial,sans-serif;margin:0;color:#243c48;background:#fff}.fixture-shell{display:grid;grid-template-columns:170px minmax(0,1fr)}.fixture-menu{padding:30px 18px;background:#f4f6f6;border-right:1px solid #dde4e5;line-height:2.5}.fixture-page{padding:28px;min-width:0}h1{font-size:20px}.kb-header{margin-bottom:24px}.kb-brand{margin:0 0 20px}.fixture-note{font-size:11px;color:#536779;margin:12px 0}.button,.butAction{border:1px solid #bacbd0;border-radius:5px;padding:10px;background:#f3f6f5;color:#243c48;cursor:pointer}select{padding:9px;border:1px solid #bacbd0;border-radius:5px;background:#fff;width:100%}table{border-collapse:collapse}th,td{padding:8px}th{text-align:left;background:#f4f7f6}.right{text-align:right}.tabsAction{display:flex;flex-wrap:wrap;gap:8px}.error,.warning{padding:12px;background:#fff3e9;color:#8a3f1a}.ok{padding:12px;background:#edf7ef;color:#29563c}@media(max-width:760px){.fixture-shell{grid-template-columns:minmax(0,1fr)}.fixture-menu{display:none}.fixture-page{padding:14px}.kb-primary a{padding:10px}.kb-company{max-width:22ch}}</style></head><body><div class="fixture-shell"><aside class="fixture-menu" aria-label="Native banking menu">Banks<br>Account list<br>Transfers<br>Cheque deposits</aside><main class="fixture-page">';
}
function dol_get_fiche_end() { return ''; }
function llxFooter() {
	print '</main></div><output id="fixture-submit" role="status"></output><script>document.addEventListener("submit",function(event){event.preventDefault();var data=new FormData(event.target);if(event.submitter&&event.submitter.name){data.set(event.submitter.name,event.submitter.value)}document.getElementById("fixture-submit").textContent="Synthetic submission ["+data.get("action")+" / "+data.get("entry_submit_action")+"]: "+JSON.stringify(Object.assign(Object.fromEntries(data),{selected_docs:data.getAll("selected_docs[]")}));event.target.removeAttribute("aria-busy")});document.querySelectorAll(".kb-queue-line").forEach(function(link){link.addEventListener("click",function(event){event.preventDefault();document.getElementById("fixture-submit").textContent="Synthetic navigation: "+link.getAttribute("href")})});</script></body></html>';
}
function img_picto(...$args) { return '<span aria-hidden="true">×</span>'; }
class WorkspaceFixtureLangs {
	public $defaultlang = 'pt_PT';
	public $messages = array();
	public function __construct($locale) {
		$this->defaultlang = $locale;
		foreach (array('en_US', $locale) as $lang) {
			foreach (file(dirname(__DIR__) . '/langs/' . $lang . '/kreabank.lang', FILE_IGNORE_NEW_LINES) as $line) {
				if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) { continue; }
				list($key, $value) = explode('=', $line, 2); $this->messages[$key] = $value;
			}
		}
	}
	public function trans($key, ...$args) {
		$native = array('BankAccount'=>'Conta bancária', 'Search'=>'Pesquisar', 'ThirdParty'=>'Terceiro', 'ErrorFieldRequired'=>'%s é obrigatório', 'Product'=>'Produto', 'Close'=>'Fechar', 'Description'=>'Descrição', 'Qty'=>'Quantidade', 'Amount'=>'Valor');
		$text = $this->messages[$key] ?? $native[$key] ?? $key;
		return $args ? vsprintf($text, $args) : $text;
	}
	public function transnoentitiesnoconv($key, ...$args) { return $this->trans($key, ...$args); }
}
class WorkspaceFixtureForm {
	public function select_company($id, $name, ...$args) { return '<select id="' . $name . '" name="' . $name . '"><option value="">Selecionar fornecedor</option><option value="91"' . ($id ? ' selected' : '') . '>Synthetic supplier</option></select>'; }
	public function select_produits($id, $name, ...$args) { return '<select aria-label="Produto" id="' . $name . '" name="' . $name . '"><option value="">Selecionar produto</option><option value="51">FIX-01 - Synthetic product - 23%</option></select>'; }
}
class KreaBankAccountPairs {
	public function __construct(...$args) {}
	public function mainAccount($id) { if ($GLOBALS['fixtureCase'] === 'unavailable') { throw new DomainException('Synthetic invalid configuration'); } return $GLOBALS['fixtureCase'] === 'restricted' ? 92 : 0; }
}
function renderWorkspaceFixture($case = 'debit', $locale = 'pt_PT', $options = array()) {
	global $langs, $fixtureCase;
	$fixtureCase = $case;
	$GLOBALS['fixtureRequest'] = array('workflow'=>$options['workflow'] ?? '');
	$langs = new WorkspaceFixtureLangs($locale);
	$conf = (object) array('entity'=>7);
	$db = new class { public function close() {} };
	$user = new class($case) {
		public $id = 901;
		private $case;
		public function __construct($case) { $this->case = $case; }
		public function hasRight(...$path) { return $this->case !== 'readonly' || end($path) === 'read'; }
	};
	$service = new class {
		public $documents = array();
		public function getPaymentAccountReview(...$args) { return array(); }
		public function getLinkedPaymentDocuments(...$args) { return array(); }
		public function getSafeSuggestion(...$args) { return null; }
		public function getSuggestionsForLines($lines, ...$args) {
			$items = array();
			foreach ($this->documents as $doc) {
				if (!empty($doc['is_locked']) || $GLOBALS['fixtureCase'] === 'unavailable' || ($GLOBALS['fixtureCase'] === 'restricted' && ($doc['doc_type'] !== 'native_bank' || empty($doc['allowed_transfer'])))) { continue; }
				$items[] = array_merge($doc, array('doc_id'=>$doc['rowid'], 'doc_ref'=>$doc['ref'], 'bank_account_id'=>$doc['bank_account_id'] ?? $lines[0]['bank_account_id'], 'score'=>100, 'details'=>array('amount', 'date')));
			}
			return array($lines[0]['rowid']=>$items);
		}
	};
	$form = new WorkspaceFixtureForm();
	$navigationAccountId = 81; $navigationAccount = null;
	$searchOtherAccounts = $case === 'otheraccounts' || !empty($options['search_accounts']); $otherAccountDocuments = array(); $accountMoveReviews = array(); $correctedBankLineId = 0;
	if ($case === 'otheraccounts') {
		$otherAccountDocuments = array(array('rowid'=>501,'label'=>'Pagamento de fatura · Exemplo','account_ref'=>'WRONG-BANK','dateo'=>'2026-11-03','amount'=>'-125.50','currency_code'=>'EUR','payment_refs'=>'PAY-EXAMPLE','customer_docs'=>'','supplier_docs'=>'Fatura de Exemplo','can_move'=>true));
		$accountMoveReviews = array(501=>'synthetic-account-review');
	}
	$forceBatchDetect = $options['force_batch_detect'] ?? 0; $openDocumentsSearch = $options['search'] ?? '';
	$pendingColumns = array('b.dateo'=>'KreaBankDate','m.description'=>'KreaBankDescription','b.amount'=>'KreaBankAmount');
	$pendingSort = array('field'=>'b.dateo','order'=>'ASC','prefix'=>'','sortfield_param'=>'sortfield','sortorder_param'=>'sortorder');
	$documentColumns = array('ref'=>'Ref','doc_date'=>'KreaBankDate','amount_open'=>'KreaBankAmount');
	$documentSort = array('field'=>'ref','order'=>'ASC','prefix'=>'doc_','sortfield_param'=>'doc_sortfield','sortorder_param'=>'doc_sortorder');
	$lineId = 11;
	$selectedLine = array('rowid'=>11,'fk_statement'=>0,'native_bank_line_id'=>0,'status'=>0,'description'=>'Pagamento a fornecedor · novembro','counterparty_name'=>'Synthetic supplier','counterparty_iban'=>'','payment_reference'=>'FIXTURE-2026-11','bank_reference'=>'SYNTHETIC-ONLY','bank_account_id'=>81,'bank_account_ref'=>'BANK-TEST','operation_date'=>'2026-11-03','amount'=>-125.50,'currency'=>'EUR','allocated_amount'=>0,'is_duplicate'=>0);
	if ($case === 'credit') { $selectedLine['amount'] = 125.50; $selectedLine['description'] = 'Recebimento de cliente'; }
	$selectedLine['fk_statement'] = $options['statement_id'] ?? 0;
	$pendingLines = array($selectedLine);
	foreach (array('Comissão bancária','Pagamento de imposto','Pagamento de salários','Transferência recebida','Compra de serviços','Liquidação de terminal','Transferência entre contas','Seguro mensal','Serviços de energia') as $i=>$description) {
		$line = $selectedLine; $line['rowid'] = 12 + $i; $line['description'] = $description; $line['amount'] = ($i + 1) * -17.25; $line['operation_date'] = '2026-11-0' . (($i % 3) + 1); $pendingLines[] = $line;
	}
	$pendingLines[1]['payment_reference'] = '"><img src=x onerror=alert(1)>';
	$pendingLinesTotal = count($pendingLines);
	$documents = array(); $suggestionByKey = array(); $autoSelectDocKeys = array();
	foreach (array('PAY-TEST-001'=>75.25,'PAY-TEST-002'=>50.25,'PAY-LOCKED'=>125.5) as $ref=>$value) {
		$id = 101 + count($documents);
		$documents[] = array('rowid'=>$id,'doc_type'=>'native_bank','ref'=>$ref,'amount_open'=>$case === 'credit' ? $value : -$value,'thirdparty_name'=>'Synthetic supplier','doc_date'=>'2026-11-03','is_locked'=>$ref==='PAY-LOCKED','linked_bank_reconciled'=>false,'url_types'=>'payment_supplier');
		$suggestionByKey['native_bank__' . $id] = array('score'=>110,'details'=>array('amount'));
	}
	if (in_array($case, array('noentries', 'empty'))) { $documents = array(); }
	if ($case === 'locked') { $documents = array($documents[2]); }
	if ($case === 'safe') { $autoSelectDocKeys['native_bank__103'] = true; $documents = array($documents[2]); $documents[0]['is_locked'] = false; }
	if (isset($options['documents'])) { $documents = $options['documents']; }
	if ($case === 'invoice' || $case === 'invoicechoices') {
		$selectedLine['fk_statement'] = 1;
		$documents = array(array('rowid'=>242,'doc_type'=>'supplier_invoice','ref'=>'INV-SYNTHETIC','amount_open'=>125.5,'thirdparty_name'=>'Synthetic supplier','doc_date'=>'2026-11-03'));
		if ($case === 'invoicechoices') { $documents[] = array_merge($documents[0], array('rowid'=>243, 'ref'=>'INV-SYNTHETIC-2')); }
	}
	if ($case === 'bank' || $case === 'bankchoices') {
		$selectedLine['fk_statement'] = 1;
		$documents = array(array('rowid'=>242,'doc_type'=>'native_bank','ref'=>'BANK-SYNTHETIC','amount_open'=>-125.5,'thirdparty_name'=>'Synthetic company','doc_date'=>'2026-11-03','bank_account_id'=>81,'url_types'=>'banktransfert'));
		if ($case === 'bankchoices') { $documents[] = array_merge($documents[0], array('rowid'=>243, 'ref'=>'BANK-SYNTHETIC-2')); }
	}
	$service->documents = $documents;
	if ($case === 'single') { $pendingLines = array($selectedLine); $pendingLinesTotal = 1; }
	if ($case === 'empty') { $selectedLine = null; $pendingLines = array(); $pendingLinesTotal = 0; }
	$selectedOutstanding = 125.5;
	if ($case === 'precision') { $selectedOutstanding = 125.5000001; $documents[0]['amount_open'] = -75.2500001; }
	$quickSupplierProductLineSlots = 6;
	$decodeHtmlEntitiesRecursive = static function ($value) { return html_entity_decode($value, ENT_QUOTES, 'UTF-8'); };
	$quickEntryFailed = $case === 'error'; $quickEntryFormState = array();
	if ($quickEntryFailed) { $quickEntryFormState = array('choice'=>'supplier_invoice','label'=>'Retained label','amount'=>125.50,'note'=>'Retained note','supplier_id'=>91,'supplier_lookup'=>'Synthetic supplier','supplier_ref'=>'INV-TEST','lines'=>array(array('product_id'=>51,'label'=>'Synthetic product','qty'=>1,'amount'=>125.50))); }
	$quickCreateSuccessAlert = array('enabled'=>$case === 'success','message'=>'Synthetic record created and reconciled','url'=>'','ref'=>'');
	$quickEntryPrefillLabel = $selectedLine['description'] ?? '';
	$quickEntryPrefillNote = 'Synthetic fixture'; $quickSupplierLookupPrefill = ''; $quickSupplierRefSupplier = '';
	$quickSupplierPredictedSocid = 0; $quickSupplierPrediction = array();
	$canNativeWrite = $case !== 'readonly'; $canQuickSupplierInvoice = $case !== 'limited'; $canQuickTaxEntry = $case !== 'limited';
	$selectedLineDirection = $case === 'credit' ? 1 : -1;
	$batchDetectedAuto = false; $batchDetectedMl = false; $batchDetectWasForced = false; $isLoteBatchLine = in_array($case, array('batch', 'precision'), true);
	$loteSubsetSuggestion = array(); $lineLinks = array(); $safeScore = 150; $dateTolerance = 3;
	$_SERVER['PHP_SELF'] = '/synthetic-reconcile';
	$source = file_get_contents(dirname(__DIR__) . '/reconcile.php');
	$start = strpos($source, '$renderQuickEntryForm =');
	if ($start === false) { throw new RuntimeException('Review reconciliation render boundary'); }
	$view = str_replace('__DIR__', var_export(dirname(__DIR__), true), substr($source, $start));
	ob_start();
	try { eval($view); return ob_get_contents(); }
	finally { ob_end_clean(); }
}
if (($argv[1] ?? '') === '--html') { print renderWorkspaceFixture($argv[2] ?? 'debit', $argv[3] ?? 'pt_PT'); exit; }
$checks = 0;
function workspaceCheck($condition, $message) { global $checks; $checks++; if (!$condition) { throw new RuntimeException($message); } }
foreach (array('otheraccounts','debit','credit','readonly','empty','locked','noentries','safe','error','restricted','success','limited','unavailable','single','bank','bankchoices','batch') as $case) {
	$html = renderWorkspaceFixture($case);
	workspaceCheck(strpos($html, 'kb-queue') !== false && strpos($html, '</html>') !== false, $case . ': complete view');
	workspaceCheck(strpos($html, 'Native banking menu') !== false && strpos($html, 'kb-primary') !== false, $case . ': native/shared navigation retained');
	workspaceCheck(strpos($html, 'KreaBankWorkspace') === false, $case . ': workspace translations resolve');
	workspaceCheck(strpos($html, 'kb-defer') === false && strpos($html, 'value="skip_line"') === false, $case . ': selected-line deferral removed');
	if ($case === 'empty') { workspaceCheck(strpos($html, 'manualReconcileForm') === false, 'Empty queue must not offer financial actions'); continue; }
	workspaceCheck(strpos($html, 'data-queue-scope="7:901:81:b.dateo:ASC"') !== false, $case . ': queue order is scoped to user/company/account/sort');
	workspaceCheck(strpos($html, 'id="kb-next-line"') !== false && strpos($html, 'data-queue-id="11"') !== false, $case . ': read-only queue navigation available');
	if ($case === 'single') { workspaceCheck((bool) preg_match('/id="kb-next-line"[^>]+ disabled/', $html), 'Single pending line disables Next'); }
	workspaceCheck(strpos($html, 'data-outstanding="125.5"') !== false, $case . ': raw outstanding retained');
	workspaceCheck(strpos($html, 'data-currency="EUR"') !== false && strpos($html, 'BANK-TEST') !== false, $case . ': source context');
	workspaceCheck((strpos($html, 'value="tax_entry"') !== false) === !in_array($case, array('credit','readonly','restricted','limited','unavailable')), $case . ': tax direction/permission restriction');
	workspaceCheck((strpos($html, 'value="supplier_invoice"') !== false) === !in_array($case, array('credit','readonly','restricted','limited','unavailable')), $case . ': invoice direction/permission restriction');
	if ($case === 'readonly') { workspaceCheck(strpos($html, 'id="manualReconcileButton"') === false && strpos($html, 'krea_quick_entry_form_') === false, 'Read-only view must not offer writes'); }
	if ($case === 'locked') { workspaceCheck(strpos($html, 'name="selected_docs[]"') === false, 'Locked movement must not be selectable'); }
	if ($case === 'error') { workspaceCheck(strpos($html, 'data-initial-workflow="create"') !== false && strpos($html, 'value="Retained label"') !== false && strpos($html, 'value="supplier_invoice" checked') !== false, 'Failed creation retains workflow, choice and inputs'); }
	if ($case === 'success') { workspaceCheck(strpos($html, 'role="status">') !== false && strpos($html, 'Synthetic record created and reconciled') !== false, 'Persistent accessible success state'); }
}
$invoiceDocument = array('rowid'=>242,'doc_type'=>'supplier_invoice','ref'=>'INV-SYNTHETIC','amount_open'=>125.5,'thirdparty_name'=>'Synthetic supplier','doc_date'=>'2026-11-03');
foreach (array('supplier_invoice', 'customer_invoice', 'payment', 'payment_supplier', 'payment_linked', 'payment_supplier_linked') as $type) {
	$document = array_merge($invoiceDocument, array('doc_type'=>$type));
	if (strpos($type, 'invoice') === false) { $document['amount_open'] = -125.5; }
	$html = renderWorkspaceFixture($type === 'customer_invoice' ? 'credit' : 'debit', 'pt_PT', array('documents'=>array($document), 'statement_id'=>1));
	workspaceCheck(strpos($html, 'name="selected_docs[]"') === false && strpos($html, 'name="doc_amount[') === false, $type . ': unsupported types never enter the native checkbox controller');
	workspaceCheck(strpos($html, 'name="line_source" value="staged"') !== false && strpos($html, 'name="review_doc" value="' . $type . '__242"') !== false && strpos($html, 'name="review_signature"') !== false, $type . ': signed direct submission preserves the exact source namespace and expected document');
	workspaceCheck(strpos($html, 'action="/synthetic-reconcile" id="kb-document-review-') !== false && strpos($html, 'form="kb-document-review-' . $type . '__242"') !== false && strpos($html, 'view=matched') === false, $type . ': button submits its external form on the one-by-one page');
	$invoice = strpos($type, 'invoice') !== false;
	workspaceCheck(strpos($html, $invoice ? 'Fatura existente' : 'Pagamento existente') !== false && strpos($html, 'Movimento bancário existente') === false, $type . ': actual document type displayed');
	workspaceCheck(strpos($html, $invoice ? 'Regista um pagamento desta fatura' : 'Não cria um novo pagamento') !== false, $type . ': financial consequence precedes review');
	workspaceCheck(strpos($html, 'id="manualReconcileButton"') === false && strpos($html, 'id="kb-selection-difference"') === false, $type . ': native-only allocation controls are omitted');
}
$legacyHtml = renderWorkspaceFixture('debit', 'pt_PT', array('documents'=>array($invoiceDocument)));
workspaceCheck(strpos($legacyHtml, 'name="line_source" value="native"') !== false, 'Legacy native source namespace remains explicit');
$choiceHtml = renderWorkspaceFixture('invoicechoices');
workspaceCheck(substr_count($choiceHtml, 'form="kb-document-review-supplier_invoice__') === 2 && substr_count($choiceHtml, 'name="review_signature"') === 2, 'Each equal invoice candidate has its own submit button and signed form');
workspaceCheck(strpos($choiceHtml, 'name="review_doc" value="supplier_invoice__242"') !== false && strpos($choiceHtml, 'name="review_doc" value="supplier_invoice__243"') !== false, 'The two buttons bind different invoice identities');
$bankHtml = renderWorkspaceFixture('bankchoices');
workspaceCheck(substr_count($bankHtml, 'form="kb-document-review-native_bank__') === 2 && substr_count($bankHtml, 'name="review_signature"') === 2, 'Each native movement uses the same per-card signed submit pattern');
workspaceCheck(strpos($bankHtml, 'name="selected_docs[]"') === false && strpos($bankHtml, 'id="manualReconcileButton"') === false && strpos($bankHtml, 'class="kb-allocation"') === false, 'Ordinary movement review has no second checkbox/allocation workflow');
workspaceCheck(strpos($bankHtml, 'Reconciliar este movimento') !== false && strpos($bankHtml, 'Não cria uma nova fatura nem um novo pagamento') !== false, 'Bank action and consequence identify reconciliation of existing records');
$batchHtml = renderWorkspaceFixture('batch');
workspaceCheck(substr_count($batchHtml, 'name="selected_docs[]"') === 2 && strpos($batchHtml, 'id="manualReconcileButton"') !== false && strpos($batchHtml, 'id="kb-selected-total"') !== false, 'Batch mode retains multiple native selections and allocation controls');
workspaceCheck(strpos($batchHtml, 'form="kb-document-review-native_bank__') === false, 'Batch rows do not mix per-card and combined submit actions');
$restrictedBank = array('rowid'=>242,'doc_type'=>'native_bank','ref'=>'TRANSFER-SYNTHETIC','amount_open'=>-125.5,'thirdparty_name'=>'Synthetic company','doc_date'=>'2026-11-03','bank_account_id'=>81,'allowed_transfer'=>true);
workspaceCheck(strpos(renderWorkspaceFixture('restricted', 'pt_PT', array('documents'=>array($restrictedBank))), 'form="kb-document-review-native_bank__242"') !== false, 'Restricted accounts retain allowed existing-transfer reconciliation without permitting creation');
foreach (array('readonly', 'restricted', 'unavailable') as $case) {
	$html = renderWorkspaceFixture($case, 'pt_PT', array('documents'=>array($invoiceDocument)));
	workspaceCheck(strpos($html, 'name="review_signature"') === false && strpos($html, 'form="kb-document-review-') === false, $case . ': no invoice approval action');
}
foreach (array(
	array('noentries', array(), 'create'),
	array('noentries', array('search_accounts'=>true), 'match'),
	array('credit', array('documents'=>array()), 'create'),
	array('noentries', array('search'=>'MISSING-REFERENCE'), 'match'),
	array('noentries', array('force_batch_detect'=>1), 'match'),
	array('readonly', array('documents'=>array()), 'match'),
	array('restricted', array('documents'=>array()), 'match'),
	array('unavailable', array('documents'=>array()), 'match'),
	array('debit', array(), 'match'),
	array('locked', array(), 'match'),
	array('safe', array(), 'match'),
	array('error', array('search'=>'RETAINED-SEARCH'), 'create'),
	array('debit', array('workflow'=>'create'), 'create'),
	array('noentries', array('workflow'=>'match'), 'match'),
	array('readonly', array('workflow'=>'create'), 'match'),
	array('restricted', array('workflow'=>'create'), 'match'),
) as $scenario) {
	list($case, $options, $expected) = $scenario;
	$html = renderWorkspaceFixture($case, 'pt_PT', $options);
	workspaceCheck(strpos($html, 'data-initial-workflow="' . $expected . '"') !== false, $case . ': appropriate initial workflow');
	workspaceCheck(strpos($html, 'id="kb-tab-' . $expected . '" aria-controls="kb-panel-' . $expected . '" aria-selected="true" tabindex="0"') !== false, $case . ': initial tab selection and keyboard focus agree');
}
$otherHtml = renderWorkspaceFixture('otheraccounts');
workspaceCheck(strpos($otherHtml, 'Movimentos noutras contas') !== false && strpos($otherHtml, 'WRONG-BANK') !== false, 'Other account and consequence are visible');
$libxmlPrevious = libxml_use_internal_errors(true);
$dom = new DOMDocument(); $dom->loadHTML($otherHtml);
libxml_clear_errors(); libxml_use_internal_errors($libxmlPrevious);
$xpath = new DOMXPath($dom);
workspaceCheck($xpath->query('//form[@id="manualReconcileForm"]//input[@name="account_move_review"]')->length === 0, 'Account correction is outside reconciliation form');
workspaceCheck($xpath->query('//article[contains(@class,"kb-other-account-entry")]//input[@name="selected_docs[]"]')->length === 0, 'Wrong-account results offer no reconciliation selection');
workspaceCheck($xpath->query('//input[@name="action" and @value="change_movement_account"]/../input[@name="token"]')->length === 1, 'Correction has its own CSRF and explicit action');
workspaceCheck($xpath->query('//details[contains(@class,"kb-account-move")]//button')->length === 1, 'Correction has a separate explicit confirmation');
workspaceCheck(strpos(renderWorkspaceFixture('precision'), 'value="75.2500001"') !== false, 'Native amount precision survives rendering');
workspaceCheck(strpos(renderWorkspaceFixture('precision'), 'data-outstanding="125.5000001"') !== false, 'Outstanding precision survives rendering');
// Untrusted source evidence is escaped in search metadata and in rendered content.
workspaceCheck(strpos(renderWorkspaceFixture('debit'), '<img src=x') === false, 'Statement evidence must not become markup');
workspaceCheck(strpos(renderWorkspaceFixture('debit'), 'synthetic-token') !== false, 'All write forms carry CSRF');
echo 'OK: ' . $checks . " reconciliation workspace rendering checks (synthetic, no database)\n";
