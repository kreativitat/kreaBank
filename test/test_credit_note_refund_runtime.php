<?php
// Isolated native-boundary doubles: no Dolibarr bootstrap, bank writes or database.
$root = sys_get_temp_dir().'/kreabank-refund-'.getmypid();
foreach (array('/compta/facture/class', '/compta/paiement/class', '/compta/bank/class') as $dir) { mkdir($root.$dir, 0700, true); }
file_put_contents($root.'/compta/facture/class/facture.class.php', '<?php');
file_put_contents($root.'/compta/paiement/class/paiement.class.php', '<?php');
file_put_contents($root.'/compta/bank/class/account.class.php', '<?php');
define('DOL_DOCUMENT_ROOT', $root);
define('DOL_URL_ROOT', '');
register_shutdown_function(function () use ($root) {
 foreach (array('/compta/facture/class/facture.class.php', '/compta/paiement/class/paiement.class.php', '/compta/bank/class/account.class.php') as $file) { unlink($root.$file); }
 foreach (array('/compta/facture/class', '/compta/facture', '/compta/paiement/class', '/compta/paiement', '/compta/bank/class', '/compta/bank', '/compta', '') as $dir) { rmdir($root.$dir); }
});
function dol_string_nospecial($value) { return $value; }
function price2num($value, $mode = '') { return (float) $value; }
function getDolGlobalInt($key, $default = 0) { return $default; }
function getDolGlobalString($key, $default = '') { return $default; }
function dol_now() { return 1; }
function dol_print_date($date, $format) { return '20261113'; }
$conf = (object) array('currency' => 'EUR', 'entity'=>1);
require_once __DIR__.'/../class/KreaBankService.class.php';
class RefundDb {
 public $reconciled = 0, $existingPayment = false, $bankAmount = -200;
 public $converted = false, $queries = array(), $commits = 0, $rollbacks = 0, $payments = array(), $snapshot;
 function prefix() { return 'llx_'; }
 function query($sql) {
  $this->queries[]=$sql; $rows=[];
  if (strpos($sql,'societe_remise_except WHERE')!==false) { if ($this->converted) $rows[]=(object)['rowid'=>1]; }
  elseif (strpos($sql,'SELECT rowid FROM llx_paiement')===0) { if($this->existingPayment)$rows[]=(object)['rowid'=>1]; }
  elseif (strpos($sql,'SELECT b.rowid,b.rappro')===0) $rows[]=(object)['rowid'=>99,'rappro'=>$this->reconciled,'amount'=>$this->bankAmount,'currency_code'=>'EUR'];
  elseif (strpos($sql,'SELECT value FROM llx_const')===0) {}
  elseif (strpos($sql,'as amount_open')!==false) {}
  else $rows[]=(object)['rowid'=>1];
  return (object)['rows'=>$rows,'index'=>0];
 }
 function num_rows($result) { return count($result->rows); }
 function fetch_object($result) { return $result->rows[$result->index++] ?? false; }
 function idate($date) { return '2026-11-13 00:00:00'; }
 function plimit($limit, $offset) { return ' LIMIT '.$limit; }
 function lasterror() { return 'database error'; }
 function begin() { $this->snapshot = array($this->payments, Facture::$rows); return true; }
 function commit() { $this->commits++; return true; }
 function rollback() { $this->rollbacks++; list($this->payments, Facture::$rows) = $this->snapshot; }
}
class Facture {
 const TYPE_CREDIT_NOTE = 2, STATUS_VALIDATED = 1;
 static $rows = array();
 public $id, $entity, $type, $status, $paye, $remaining, $thirdparty, $multicurrency_code, $ref="TEST", $date=1;
 function __construct($db) {}
 function fetch($id) { if (!isset(self::$rows[$id])) return 0; foreach (self::$rows[$id] as $key => $value) $this->$key = $value; $this->id = $id; return 1; }
 function getRemainToPay($multi) { return $this->remaining; }
 function fetch_thirdparty() { $this->thirdparty = (object) array('id'=>5, 'name'=>'Test'); }
}
class Paiement {
 public $db, $amounts, $multicurrency_amounts, $multicurrency_code, $multicurrency_tx, $datepaye, $paiementid, $num_payment, $note_private, $fk_account;
 function __construct($db) { $this->db = $db; }
 function create($user, $close, $thirdparty) {
  foreach ($this->amounts as $id => $amount) {
   $this->db->payments[] = array('invoice' => $id, 'amount' => $amount, 'bank' => 0);
   Facture::$rows[$id]['remaining'] -= $amount;
   if (abs(Facture::$rows[$id]['remaining']) < 0.00001) Facture::$rows[$id]['paye'] = 1;
  }
  return count($this->db->payments);
 }
 function update_fk_bank($id) { $key = count($this->db->payments)-1; $this->db->payments[$key]['bank'] = $id; return 1; }
}
class RefundNative {
 public $fail = false, $links = array();
 function addLineLink($bank, $id, $url, $label, $type) { $this->links[] = array($bank, $id, $type); return $this->fail && count($this->links) >= $this->fail ? -1 : 1; }
 function conciliateLine($id, $label, $flag) { return 1; }
 function markStatementLineReconciled($id, $amount, $nativeId) { return 1; }
}
class RefundUser { function hasRight(...$args) { return true; } }
class RefundService extends KreaBankService {
 public $line;
 function __construct($db, $native) { $this->db=$db; $this->native=$native; $this->entity=1; $this->user=new RefundUser(); $this->line=array('rowid'=>7,'amount'=>-200,'direction'=>-1,'status'=>0,'bank_account_id'=>1,'allocated_amount'=>0,'currency'=>'EUR'); }
 function review($id, $amount) { return ['line'=>$this->line,'allocation'=>$amount,'suggestion'=>['doc_type'=>'customer_credit_note','doc_id'=>$id,'amount_open'=>-Facture::$rows[$id]['remaining'],'doc_ref'=>'TEST','thirdparty_name'=>'Test','doc_date'=>'2026-11-13 00:00:00']]; }
 function pay($id, $amount) { $refund=Facture::$rows[$id]['type']===2; return $this->createCustomerPaymentForBankLine($this->line,$id,$amount,'',$refund,$refund?$this->review($id,$amount)['suggestion']:null); }
 function approve($links, $review=null) { if($review===null)$review=['line'=>$this->line,'refund_reviews'=>array_map(fn($l)=>$this->review($l['fk_doc'],$l['allocated_amount']),$links)]; return $this->reconcileLine(7,$links,'bulk_manual',0,'',0,null,$review); }
 public function getBulkReviewLine($id,$source) { return $this->line; }
 function docs($direction) { return $this->getOpenInvoiceDocuments($direction); }
 public function getLineById($id) { return $this->line; }
 protected function resolvePaymentModeId() { return 2; }
 protected function resolveLineDateTimestamp($line) { return 1; }
 protected function resolveLinePaymentRef($line) { return 'test'; }
 protected function shouldLearnBatchMlFromReconciliation($strategy, $isAuto) { return false; }
 protected function resolveNativeLineIdFromStatementLine($line, $createIfMissing = false, $paymentModeCode = '') { return 99; }
 protected function buildNativeLineContext($line, $id) { $line['rowid']=$id; return $line; }
 protected function learnPatternsFromReconciliation($line, $links) {}
 protected function logAudit($type, $lineId = null, $reconciliationId = null, $payload = array()) { return 1; }
}
function check($condition, $message) { if (!$condition) throw new Exception($message); }
function rejects($callback, $message) { try { $callback(); } catch (Exception $e) { return; } throw new Exception($message); }
function invoice($remaining, $type=2) { return array('entity'=>1,'type'=>$type,'status'=>1,'paye'=>0,'remaining'=>$remaining); }
$db = new RefundDb(); $native = new RefundNative(); $service = new RefundService($db, $native);
Facture::$rows = array(1=>invoice(-60), 2=>invoice(-140));
$links = array(array('doc_type'=>'customer_credit_note','fk_doc'=>1,'allocated_amount'=>60), array('doc_type'=>'customer_credit_note','fk_doc'=>2,'allocated_amount'=>140));
$service->approve($links);
check(array_column($db->payments, 'amount') === array(-60.0,-140.0), 'Refund allocations must be native negative payments');
check(array_column($db->payments, 'bank') === array(99,99), 'Refunds must reuse one existing bank row');
check($db->commits===1 && $db->rollbacks===0, 'Combined refund must commit once');
rejects(function() use ($service) { $service->pay(1,60); }, 'Paid refund accepted');
Facture::$rows[1]=invoice(-60);
rejects(function() use ($service) { $service->pay(1,60.01); }, 'Over-refund accepted');
$service->pay(1,20);
check(Facture::$rows[1]['remaining']===-40.0, 'Partial refund must reduce signed liability');
rejects(function() use ($service) { $service->pay(1,40.01); }, 'Prior partial refund ignored');
Facture::$rows[1]=invoice(0);
rejects(function() use ($service) { $service->pay(1,1); }, 'Zero remaining accepted');
Facture::$rows[1]=invoice(-60);
Facture::$rows[1]['entity']=2;
rejects(function() use ($service) { $service->pay(1,60); }, 'Foreign invoice accepted');
Facture::$rows[1]=invoice(-60); $db->converted=true;
rejects(function() use ($service) { $service->pay(1,60); }, 'Converted credit accepted');
$db->converted=false; $service->line['currency']='USD';
rejects(function() use ($service) { $service->pay(1,60); }, 'Foreign bank currency accepted');
$service->line['currency']='EUR'; Facture::$rows[1]['multicurrency_code']='USD';
rejects(function() use ($service) { $service->pay(1,60); }, 'Foreign invoice currency accepted');
Facture::$rows[1]=invoice(-60); $service->line['direction']=1;
rejects(function() use ($service) { $service->pay(1,60); }, 'Refund on incoming bank line accepted');
$service->line['direction']=-1; Facture::$rows[1]=invoice(60,0);
rejects(function() use ($service) { $service->pay(1,60); }, 'Normal invoice on debit accepted');
$service->line['direction']=1; $service->pay(1,60);
check(end($db->payments)['amount']===60.0, 'Normal incoming customer payment regressed');
$service->line['direction']=-1;
rejects(function() use ($service, $links) { $service->approve(array($links[0],array('doc_type'=>'customer_credit_note','fk_doc'=>1,'allocated_amount'=>140))); }, 'Duplicate invoice accepted');
Facture::$rows=array(1=>invoice(-60),2=>invoice(-140)); $before=count($db->payments); $native->fail=count($native->links)+2;
rejects(function() use ($service,$links) { $service->approve($links); }, 'Failed native linking accepted');
check(count($db->payments)===$before && Facture::$rows[1]['remaining']===-60 && $db->rollbacks===1, 'Failed link must roll back payments and balance');
$native->fail=false; $db->reconciled=1; $before=count($db->payments);
rejects(function() use ($service,$links) { $service->approve($links); }, 'Concurrently reconciled bank row accepted');
check(count($db->payments)===$before, 'Concurrent reconciliation must not create a refund');
$db->reconciled=0; $db->existingPayment=true;
rejects(function() use ($service,$links) { $service->approve($links); }, 'Bank row with an existing native payment accepted');
check(count($db->payments)===$before, 'Existing payment guard must not create another refund');
foreach (array(-1,0,1) as $direction) {
 $db->queries=array(); $service->docs($direction); $sql=$db->queries[0];
 check(strpos($sql,'f.type as invoice_type')!==false && strpos($sql,'ABS(')!==false, 'Candidate must expose invoice type and positive allocation');
 check(($direction>0) === (strpos($sql,'NOT EXISTS')===false), 'Only debit/unspecified direction may list unconverted customer refunds');
 check(strpos($sql,'f.entity = 1')!==false && strpos($sql,'f.paye = 0')!==false, 'Candidate entity/open guard missing');
}
$db->existingPayment=false; $db->reconciled=0; Facture::$rows=[1=>invoice(-60),2=>invoice(-140)];
$db->bankAmount=-300;
rejects(function()use($service,$links){$service->approve($links);},'Changed persisted bank amount accepted');
$db->bankAmount=-200;
rejects(function()use($service,$links){$service->reconcileLine(7,$links);},'Unsigned refund accepted');
$review=['line'=>$service->line,'refund_reviews'=>[$service->review(1,60),$service->review(2,140)]];
$bad=$review; $bad['refund_reviews'][1]['allocation']=139;
rejects(function()use($service,$links,$bad){$service->approve($links,$bad);},'Changed signed allocation accepted');
$bad=$review; $bad['refund_reviews'][1]['line']['bank_account_id']=2;
rejects(function()use($service,$links,$bad){$service->approve($links,$bad);},'Mixed signed bank sources accepted');
$bad=$review; $bad['refund_reviews'][1]['suggestion']['doc_id']=1;
rejects(function()use($service,$links,$bad){$service->approve($links,$bad);},'Changed signed document accepted');
Facture::$rows[1]['remaining']=-70;
rejects(function()use($service,$links,$review){$service->approve($links,$review);},'Changed credit balance accepted after lock');
Facture::$rows[1]=invoice(60,0);
rejects(function()use($service,$links){$service->approve($links);},'Normal invoice relabelled refund accepted');
$matcher=new KreaBankMatcher();
$score=$matcher->scoreLineToDocument(['amount'=>60],['doc_type'=>'customer_credit_note','amount_open'=>60],[]);
check($score['score']===0,'Matcher allowed refund on incoming line');
$refundDocuments=[];
foreach([60,140] as $i=>$amount) $refundDocuments[]=['rowid'=>$i+1,'doc_type'=>'customer_credit_note','ref'=>'CN-'.($i+1),'ref_client'=>'','amount_open'=>$amount,'doc_date'=>'2026-11-13','thirdparty_name'=>'Example Customer','thirdparty_iban'=>''];
$combinedLine=['amount'=>-200,'operation_date'=>'2026-11-13','counterparty_name'=>'Example Customer','description'=>'Example refund','payment_reference'=>'','counterparty_iban'=>''];
$suggestions=$matcher->getSuggestions($combinedLine,$refundDocuments,[],3,1);
check(count($suggestions)===2,'Real matcher dropped constituent60/140 refund candidates for debit200');
foreach($suggestions as $suggestion)check(in_array('amount_pending',$suggestion['details'],true),'Combined refund needs explicit partial amount evidence');
check($matcher->getSafeSuggestion($suggestions,0)===null,'Constituent credit notes must not become automatically safe');
$combinedLine['counterparty_name']='Unrelated Party';$combinedLine['description']='Unrelated transaction';
check($matcher->getSuggestions($combinedLine,$refundDocuments,[],3,1)===[],'Combined refund without identity accepted by matcher');
echo "Credit-note refunds: PASS (signed combined60+140, native negative payments, reused bank row, balance/type/entity/currency/direction/duplicate guards, rollback, signed tampering, real combined matcher and candidate SQL)\n";
