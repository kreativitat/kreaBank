#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 * ONLY disposable 127.0.0.1:33317 / kreabank_auto_test.
 */
require __DIR__ . '/test_own_transfers_database.php';
$initialChecks=$checks;

// A peer's opening boundary also protects a source whose own account has no opening.
$opening=new KreaBankOpeningBalance($db,$user,1);$opening->install();
$db->query("INSERT INTO kbauto_kreabank_opening_balance(entity,fk_bank_account,start_date,currency,opening_balance,ledger_balance,adjustment_amount,statement_ref,evidence_ref,fk_user,datec,facts_hash,payload_json) VALUES(1,3105,'2026-11-02','EUR',0,0,0,'SYNTHETIC-OPENING','Disposable evidence',7,NOW(),SHA2('opening',256),'{}')");
ownSettings();ownPair(905000,'871.15');
checkAuto($service->getAutomaticOwnTransferSuggestion(905000,array(3104,3105))===null,'Historical opposite source cannot enter a new owned transfer');
ownPair(905010,'872.15');ownNativePair(906010,906011,'872.15',3104,3105);
$db->query('UPDATE kbauto_kreabank_statement_line SET status=3 WHERE rowid=905011');
checkAuto($service->getAutomaticOwnTransferSuggestion(905010,array(3104,3105))===null,'Historical native peer cannot enter scalar owned-transfer reuse');
$db->query('DELETE FROM kbauto_kreabank_opening_balance WHERE entity=1 AND fk_bank_account=3105');

// Retire the synthetic rejection cases, then put many unresolved expense rows before a valid transfer.
$db->query('UPDATE kbauto_kreabank_statement_line l JOIN kbauto_kreabank_statement s ON s.rowid=l.fk_statement SET l.status=3 WHERE l.status=0 AND s.bank_account_id IN(3104,3105,3106)');
for($i=0;$i<65;$i++){
	$id=910000+$i;ownStage($id,'-'.(10+$i).'.13',3104,'Unresolved synthetic expense');
}
ownPair(920000,'901.11');ownSettings();
$conf->global->MAIN_INFO_SOCIETE_NOM='TEST01 - Example Trading Lda';
$cursor=$auto->setting('CURSOR','0');$priorityCursor=$auto->setting('PRIORITY_CURSOR','0');$beforeBank=countAuto('bank');$beforeAudit=countAuto('kreabank_recon_audit');
$preview=$auto->process($user,true);
checkAuto($preview['eligible']===1&&$preview['matches'][0]['statement_line_id']===920000&&!empty($preview['matches'][0]['own_transfer']),'Priority preview finds an identified transfer beyond the first ordinary page');
checkAuto(countAuto('bank')===$beforeBank&&countAuto('kreabank_recon_audit')===$beforeAudit&&$auto->setting('CURSOR','0')===$cursor&&$auto->setting('PRIORITY_CURSOR','0')===$priorityCursor,'Explicit preview changes neither financial records nor either cursor');
$beforePayments=countAuto('paiement')+countAuto('paiementfourn');$beforeInvoices=countAuto('facture')+countAuto('facture_fourn');
$run=$auto->process($user,false);
checkAuto($run['status']==='completed'&&$run['reconciled']===2&&$run['failed']===0&&$run['scanned']<=50,'Bounded scheduled pass executes the priority transfer');
checkAuto(countAuto('bank')===$beforeBank+2&&countAuto('paiement')+countAuto('paiementfourn')===$beforePayments&&countAuto('facture')+countAuto('facture_fourn')===$beforeInvoices,'Ordinary unresolved expenses create no movements, invoices or payments');
checkAuto((int)$auto->setting('CURSOR')>=910000&&(int)$auto->setting('CURSOR')<920000&&(int)$auto->setting('PRIORITY_CURSOR')>=920000,'Independent cursors preserve ordinary scan progress');
$again=$auto->process($user,false);
checkAuto($again['reconciled']===0&&countAuto('bank')===$beforeBank+2&&$again['scanned']>0,'Later pass continues the expense queue without duplicating the transfer');
checkAuto($service->getBulkReviewLine(910000,'staged')['status']===0,'An expense without a recorded document stays pending');
echo 'OK: '.($checks-$initialChecks)." priority/opening automation database checks\n";
