#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Actual reviewed-payment controller branches with installed native request helpers.
 * Service/native-object adapters are observed doubles; no bootstrap, database or payment.
 * Financial lifecycle validation belongs to the separate guarded database suite.
 */
$nativeRoot = getenv('KREABANK_DOLIBARR_ROOT') ?: dirname(__DIR__,3);
if (!is_file($nativeRoot.'/core/lib/functions.lib.php')) { fwrite(STDERR,"Set KREABANK_DOLIBARR_ROOT to installed Dolibarr source.\n"); exit(2); }
define('DOL_DOCUMENT_ROOT',$nativeRoot); define('DOL_URL_ROOT','');
require_once DOL_DOCUMENT_ROOT.'/core/lib/functions.lib.php';
require_once __DIR__.'/../lib/kreabank.lib.php';
require_once __DIR__.'/../lib/kreabank_planned_payment.lib.php';
class PlannedPaymentControllerForbidden extends RuntimeException {}
class PlannedPaymentControllerRedirect extends RuntimeException
{
	public $location,$status;
	public function __construct($location,$status) { parent::__construct('Intercepted payment redirect'); $this->location=$location;$this->status=$status; }
}
function accessforbidden($message='') { throw new PlannedPaymentControllerForbidden($message); }
class PlannedPaymentControllerUser
{
	public $id=7,$socid=0,$denied=array();
	public function hasRight($a,$b,$c='') { return !in_array(implode('/',array_filter(array($a,$b,$c))),$this->denied,true); }
}
class PlannedPaymentControllerLangs { public function loadLangs($domains) {} }
function plannedControllerSyslog($message,$level) { $GLOBALS['planned_controller_logs'][]=array($message,$level); }
class KreaBankService { public function __construct($db,$user,$langs) { $GLOBALS['planned_controller_calls'][]=array('service'); } }
class KreaBankWorklist
{
	public function __construct($db,$entity,$service) {}
	public function load($key) { $GLOBALS['planned_controller_calls'][]=array('load',$key);return $GLOBALS['planned_controller_context']['source']; }
}
class KreaBankPlannedPayment
{
	private $stage='review';
	public function __construct($db,$user,$entity,$service) {}
	public function failureStage() { return $this->stage; }
	public function permissions($type) { $GLOBALS['planned_controller_calls'][]=array('permissions',$type);return $GLOBALS['planned_controller_options']['native_allowed']??true; }
	public function snapshot($line,$type,$id) { $GLOBALS['planned_controller_calls'][]=array('snapshot',$line,$type,$id);return $GLOBALS['planned_controller_options']['fresh']??$GLOBALS['planned_controller_context']; }
	public function create($context,$mode,$reference,$note) {
		$GLOBALS['planned_controller_calls'][]=array('create',$context,$mode,$reference,$note);
		if (!empty($GLOBALS['planned_controller_options']['fail_create'])) {
			$this->stage='verification';
			throw new DomainException('PRIVATE fixture native failure: amount=42.25000000 account=101 reference=REFERENCE-51 SQL=INSERT INTO synthetic_salary');
		}
		return array('payment_id'=>801,'native_bank_id'=>901);
	}
}
$checks=0;
function plannedControllerCheck($ok,$label) { global $checks;if(!$ok){throw new RuntimeException($label);}$checks++; }
function plannedControllerContext($type='salary')
{
	return array('type'=>$type,'id'=>51,'source'=>array('rowid'=>11,'bank_account_id'=>101,'bank_account_ref'=>'SYNTHETIC BANK'),
		'reference'=>'SYNTHETIC-51','label'=>'Synthetic existing document','booking_date'=>'2031-04-10',
		'amount'=>'42.25000000','remaining'=>'42.25000000','currency'=>'EUR','payment_mode_id'=>6);
}
function plannedControllerReview($type='salary')
{
	global $conf,$user;
	$conf=(object)array('entity'=>1);$user=(object)array('id'=>7);
	$_SESSION=array('token'=>'fixture-current-token','newtoken'=>'fixture-next-token');
	$context=plannedControllerContext($type);$key=kreabankPlannedPaymentRemember($context);
	return array($key,$_SESSION,$context);
}
function runPlannedController($get,$post,$session,$context,$options=array())
{
	global $conf,$user,$langs,$db;
	$_GET=$get;$_POST=$post;$_SESSION=$session;$_SERVER['PHP_SELF']='/custom/kreabank/recordpayment.php';
	$_SERVER['REQUEST_METHOD']=$options['method']??'GET';
	$conf=(object)array('entity'=>$options['entity']??1,'global'=>new stdClass(),'modules'=>array('kreabank'=>1,'banque'=>1),
		'file'=>(object)array('dol_document_root'=>array('main'=>DOL_DOCUMENT_ROOT)));
	if (!empty($options['disabled_module'])) { unset($conf->modules[$options['disabled_module']]); }
	$user=new PlannedPaymentControllerUser();$user->id=$options['user_id']??7;$user->socid=$options['socid']??0;$user->denied=$options['denied']??array();
	$langs=new PlannedPaymentControllerLangs();$db=null;
	$GLOBALS['planned_controller_calls']=array();$GLOBALS['planned_controller_logs']=array();$GLOBALS['planned_controller_options']=$options;$GLOBALS['planned_controller_context']=$context;
	$source=file_get_contents(__DIR__.'/../recordpayment.php');
	$start=strpos($source,'$langs->loadLangs(');$end=strpos($source,'llxHeader(',$start);
	if($start===false||$end===false){throw new LogicException('Reviewed-payment controller boundaries changed');}
	$branch=substr($source,$start,$end-$start);
	$branch=preg_replace_callback("/header\\(('Location: '\\s*\\..*?),true,303\\);\\s*exit;/",static function($match){
		return 'throw new PlannedPaymentControllerRedirect(substr('.$match[1].',10),303);';
	},$branch,1,$redirects);
	// An exit never enters the production Throwable handler. Preserve that boundary
	// for the test-only redirect sentinel; every ordinary exception remains unchanged.
	$catch='} catch (Throwable $exception) {';
	$branch=str_replace($catch,'} catch (PlannedPaymentControllerRedirect $redirect) { throw $redirect; '.$catch,$branch,$handlers);
	$branch=str_replace('dol_syslog(','plannedControllerSyslog(',$branch,$loggers);
	if($redirects!==1||$handlers!==1||$loggers!==1){throw new LogicException('Reviewed-payment redirect or warning-log contract changed');}
	$location=null;$status=null;$forbidden=false;$error=null;$receipt=null;$cancelled=false;
	try { eval($branch); }
	catch(PlannedPaymentControllerRedirect $redirect){$location=$redirect->location;$status=$redirect->status;}
	catch(PlannedPaymentControllerForbidden $exception){$forbidden=true;}
	return array('calls'=>$GLOBALS['planned_controller_calls'],'session'=>$_SESSION,'location'=>$location,'status'=>$status,
		'forbidden'=>$forbidden,'error'=>$error,'receipt'=>$receipt,'cancelled'=>$cancelled,'popup'=>$popup??'','logs'=>$GLOBALS['planned_controller_logs']);
}
function plannedControllerCreates($result) { return array_values(array_filter($result['calls'],static function($call){return $call[0]==='create';})); }

foreach(array('salary','tax') as$type){
	list($key,$session,$context)=plannedControllerReview($type);
	$get=array('action'=>'create','type'=>$type,'id'=>51,'review'=>$key,'dol_openinpopup'=>'kbwlpay'.str_repeat('a',16));
	$view=runPlannedController($get,array(),$session,$context);
	plannedControllerCheck(!$view['forbidden']&&$view['error']===''&&!plannedControllerCreates($view),'Fresh '.$type.' GET reviews facts without dispatching a payment');
	plannedControllerCheck(array_column($view['calls'],0)===array('service','permissions','load','snapshot')&&isset($view['session']['kreabank_planned_payments'][$key]),'GET rechecks the source/document and preserves the displayed context');
	$fresh=$context;$fresh['remaining']='41.00000000';$stale=runPlannedController($get,array(),$session,$context,array('fresh'=>$fresh));
	plannedControllerCheck($stale['error']==='KreaBankWorklistChanged'&&!plannedControllerCreates($stale),'A changed '.$type.' snapshot cannot render a payable review');
	plannedControllerCheck($stale['logs']===array(array('KreaBank reviewed payment refused: stage=review exception=DomainException',LOG_WARNING)),
		'A stale '.$type.' review logs only its fixed stage and exception class');
	foreach(array(array('type'=>$type==='salary'?'tax':'salary'),array('id'=>52),array('review'=>array($key)))as$change){
		$attempt=runPlannedController(array_merge($get,$change),array(),$session,$context);
		plannedControllerCheck($attempt['error']==='KreaBankWorklistChanged'&&!plannedControllerCreates($attempt),'Tampered type/id/nonce fails the actual native GET request boundary');
	}
	foreach(array(array('entity'=>2),array('user_id'=>8),array('native_allowed'=>false))as$options){
		$attempt=runPlannedController($get,array(),$session,$context,$options);
		plannedControllerCheck($attempt['error']==='KreaBankWorklistChanged'&&!plannedControllerCreates($attempt),'Another actor/company or missing native '.$type.' permission cannot open this context');
	}
	$badGet=runPlannedController(array_merge($get,array('action'=>'add_payment')),array(),$session,$context);
	plannedControllerCheck($badGet['forbidden']&&!$badGet['calls'],'A financial GET action is refused before adapter construction');
	$post=array('action'=>'add_payment','type'=>$type,'id'=>51,'review'=>$key,'token'=>'fixture-next-token','paiementtype'=>'6',
		'num_payment'=>'REFERENCE-51','note'=>'Reviewed existing document','dol_openinpopup'=>'kbwlpay'.str_repeat('a',16));
	foreach(array(array('token'=>'wrong'),array('action'=>'create'))as$change){
		$attempt=runPlannedController(array(),array_merge($post,$change),$session,$context,array('method'=>'POST'));
		plannedControllerCheck($attempt['forbidden']&&!$attempt['calls'],'Invalid POST action/CSRF cannot reach the '.$type.' adapter');
	}
	foreach(array(array('type'=>$type==='salary'?'tax':'salary'),array('id'=>'52'),array('review'=>array($key)))as$change){
		$attempt=runPlannedController(array(),array_merge($post,$change),$session,$context,array('method'=>'POST'));
		plannedControllerCheck(!plannedControllerCreates($attempt)&&$attempt['error']==='KreaBankWorklistPaymentFailed','Forged POST type/id/array nonce cannot dispatch a payment');
	}
	$cancel=runPlannedController(array(),$post+array('cancel'=>'Cancel'),$session,$context,array('method'=>'POST'));
	plannedControllerCheck($cancel['cancelled']&&!plannedControllerCreates($cancel)&&!isset($cancel['session']['kreabank_planned_payments'][$key]),'Cancel consumes the '.$type.' display but dispatches no financial write');
	$injected=$post+array('amount'=>'9999','amount_51'=>'9999','accountid'=>'999','bank_account_id'=>'999','reyear'=>'2099','booking_date'=>'2099-01-01');
	$saved=runPlannedController(array(),$injected,$session,$context,array('method'=>'POST'));
	$creates=plannedControllerCreates($saved);
	plannedControllerCheck($creates===array(array('create',$context,6,'REFERENCE-51','Reviewed existing document')),'Save passes the exact stored '.$type.' source/amount/account/date while ignoring injected financial fields');
	plannedControllerCheck(!isset($saved['session']['kreabank_planned_payments'][$key])&&$saved['status']===303&&$saved['location']!==null,'Successful '.$type.' dispatch consumes its review and redirects with POST/redirect/GET');
	parse_str(parse_url($saved['location'],PHP_URL_QUERY),$redirect);
	plannedControllerCheck(array_keys($redirect)===array('receipt','dol_hide_topmenu','dol_hide_leftmenu','dol_openinpopup')&&preg_match('/^[a-f0-9]{48}$/D',$redirect['receipt'])===1,'The receipt URL exposes only an opaque identity and popup presentation state');
	$replay=runPlannedController(array(),$post,$saved['session'],$context,array('method'=>'POST'));
	plannedControllerCheck($replay['error']==='KreaBankWorklistPaymentFailed'&&!plannedControllerCreates($replay),'Replayed '.$type.' POST cannot invoke create again');
	$receipt=runPlannedController($redirect,array(),$saved['session'],$context);
	plannedControllerCheck($receipt['receipt']===array('result'=>array('payment_id'=>801,'native_bank_id'=>901))&&!plannedControllerCreates($receipt),'Receipt GET reports the prior '.$type.' result without creating another payment');
	foreach(array(str_repeat('f',48),array($redirect['receipt']),$key)as$badReceipt){
		$attempt=runPlannedController(array('receipt'=>$badReceipt),array(),$saved['session'],$context);
		plannedControllerCheck(!plannedControllerCreates($attempt)&&$attempt['error']==='KreaBankWorklistChanged','Unknown, array or non-receipt contexts cannot display payment success');
	}

	$failed=runPlannedController(array(),$post,$session,$context,array('method'=>'POST','fail_create'=>true));
	plannedControllerCheck(count(plannedControllerCreates($failed))===1&&$failed['error']==='KreaBankWorklistPaymentFailed'&&!isset($failed['session']['kreabank_planned_payments'][$key]),'A refused native write requires a fresh '.$type.' review rather than a blind retry');
	plannedControllerCheck($failed['logs']===array(array('KreaBank reviewed payment refused: stage=verification exception=DomainException',LOG_WARNING)),
		'A native '.$type.' failure logs its fixed stage and class without exception text, raw financial fields or SQL');
	$expired=$session;$expired['kreabank_planned_payments'][$key]['expires']=time()-1;
	$attempt=runPlannedController(array(),$post,$expired,$context,array('method'=>'POST'));
	plannedControllerCheck(!plannedControllerCreates($attempt)&&$attempt['error']==='KreaBankWorklistPaymentFailed','Expired '.$type.' approval cannot reach financial dispatch');
}
list($key,$session,$context)=plannedControllerReview();$get=array('type'=>'salary','id'=>51,'review'=>$key);
foreach(array(array('socid'=>10),array('denied'=>array('kreabank/reconciliation/write')),array('denied'=>array('banque/modifier')),array('disabled_module'=>'kreabank'))as$options){
	$denied=runPlannedController($get,array(),$session,$context,$options);
	plannedControllerCheck($denied['forbidden']&&!$denied['calls'],'External users and missing module/native authority stop before any adapter call');
}
$arrayPopup=runPlannedController($get+array('dol_openinpopup'=>array('bad')),array(),$session,$context);
plannedControllerCheck($arrayPopup['popup']===''&&$arrayPopup['error']==='','Malformed popup presentation cannot become script content or affect payment facts');
echo "OK: $checks reviewed-payment controller checks (installed GETPOST, no database)\n";
