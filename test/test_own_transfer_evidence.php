#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
require_once __DIR__ . '/../class/KreaBankOwnTransferEvidence.class.php';
$checks = 0;
function checkOwnTransferEvidence($actual, $expected, $label)
{
	global $checks;
	if ($actual !== $expected) { throw new RuntimeException($label . ': ' . var_export($actual, true)); }
	$checks++;
}
function ownTransferEvidence($description, $amount = '125.00', $company = 'DG42 - Example & Partners, Lda.', $iban = '', $ownedIban = '')
{
	return KreaBankOwnTransferEvidence::ownedIdentity(array('description'=>$description, 'amount'=>$amount, 'counterparty_iban'=>$iban), $company, $ownedIban);
}
foreach (array(
	'TRF.IMED. DE EXAMPLE e PARTNERS LDA-R12345678',
	'TRF IMEDIATA SEPA+ DE Example & Partners, Unipessoal Lda',
	'TRF CRED INTRABANC DE EXAMPLE E PARTNERS, LDA.',
	'TRANSF. CREDITO SEPA+ DE EXAMPLE E PARTNERS LDA',
	'TRANSFERENCIA IMEDIATA DE EXAMPLE E PARTNERS LIMITADA',
	"  trf.imed. de EXAMPLE e PARTNERS LDA-R1234\n",
) as $description) {
	checkOwnTransferEvidence(ownTransferEvidence($description), 'company', 'Complete incoming company identity');
}
foreach (array(
	'TRF.IMED. P/ EXAMPLE E PARTNERS LDA-E12345678',
	'TRF IMEDIATA SEPA+ Online 123456789 PARA Example e Partners',
	'TRF CRED INTRAB Online 123456 P/ EXAMPLE & PARTNERS, LDA',
	'TRANSF. CREDITO SEPA+ PARA EXAMPLE E PARTNERS LDA',
	'TRANSFERENCIA IMEDIATA PARA EXAMPLE E PARTNERS LIMITADA',
) as $description) {
	checkOwnTransferEvidence(ownTransferEvidence($description, '-125.00'), 'company', 'Complete outgoing company identity');
}
checkOwnTransferEvidence(ownTransferEvidence('TRF.IMED. DE EXAMPLECO LDA', '1', 'Exampleco Lda'), 'company', 'Distinctive complete single-word legal identity');
checkOwnTransferEvidence(ownTransferEvidence('TRF DE CAFE ACORES UNIPESSOAL LDA', '1', 'DG42 - Café Açores, Lda'), 'company', 'Accents and narrow corporate suffixes');
checkOwnTransferEvidence(ownTransferEvidence('TRF PARA EXAMPLE E PARTNERS LDA', '-0.00000001'), 'company', 'Exact tiny debit sign');
checkOwnTransferEvidence(ownTransferEvidence('TRF DE EXAMPLE E PARTNERS LDA', '0.00000001'), 'company', 'Exact tiny credit sign');
checkOwnTransferEvidence(KreaBankOwnTransferEvidence::companyKey('DG42 - Example & Partners, Lda.'), 'EXAMPLE PARTNERS', 'Native display prefix and ampersand');
checkOwnTransferEvidence(KreaBankOwnTransferEvidence::companyKey('Example e Partners Unipessoal Lda'), 'EXAMPLE PARTNERS', 'Legal conjunction normalization');
checkOwnTransferEvidence(KreaBankOwnTransferEvidence::companyKey('Example Partners S.A.'), 'EXAMPLE PARTNERS', 'Public limited company suffix');
checkOwnTransferEvidence(KreaBankOwnTransferEvidence::companyKey('Example E Lda'), 'EXAMPLE E', 'A terminal E remains part of a legal name');
checkOwnTransferEvidence(KreaBankOwnTransferEvidence::companyKey('Lda'), '', 'Generic legal suffix cannot identify a company');
checkOwnTransferEvidence(ownTransferEvidence('TRF DE DG42 - EXAMPLE E PARTNERS LDA'), 'company', 'Matching internal company prefix is normalized');
checkOwnTransferEvidence(ownTransferEvidence('TRF DE DG43 - EXAMPLE E PARTNERS LDA'), '', 'Conflicting internal company prefix is not discarded');

$owned = 'GB82WEST12345698765432';
$foreign = 'DE89370400440532013000';
checkOwnTransferEvidence(ownTransferEvidence('TRF DE DISPLAY LABEL', '1', 'Exampleco', 'GB82 WEST 1234 5698 7654 32', $owned), 'iban', 'Exact checksum-valid owned counterparty IBAN');
checkOwnTransferEvidence(ownTransferEvidence('TRF PARA DISPLAY LABEL', '-1', 'Exampleco', $owned, $owned), 'iban', 'Owned IBAN retains outgoing direction');
checkOwnTransferEvidence(ownTransferEvidence('TRF DE EXAMPLE E PARTNERS LDA', '1', 'Example e Partners', $foreign, $owned), '', 'Contradictory IBAN blocks company fallback');
checkOwnTransferEvidence(ownTransferEvidence('TRF DE EXAMPLE E PARTNERS LDA', '1', 'Example e Partners', 'GB00WEST12345698765432', $owned), '', 'Invalid supplied IBAN blocks company fallback');
checkOwnTransferEvidence(ownTransferEvidence('TRF DE EXAMPLE E PARTNERS LDA', '1', 'Example e Partners', $owned, ''), '', 'An IBAN must identify a supplied owned counterpart');
checkOwnTransferEvidence(ownTransferEvidence('TRF DE EXAMPLE E PARTNERS LDA', '1', 'Example e Partners', $owned, 'invalid'), '', 'Invalid owned-account IBAN cannot establish ownership');
checkOwnTransferEvidence(ownTransferEvidence('TRF DE DG43 - EXAMPLE E PARTNERS LDA', '1', 'DG42 - Example e Partners', $owned, $owned), '', 'Explicit company-code conflict also prevents IBAN approval');

foreach (array(
	'TRF.IMED. DE EXAMPLE LDA',
	'TRF.IMED. DE EXAMPLE E PARTNERS HOLDINGS LDA',
	'TRF.IMED. DE NOTEXAMPLE E PARTNERS LDA',
	'TRF.IMED. DE EXAMPLE E PARTNERS EXTRA',
	'TRF.IMED. DE EXAMPLE E PARTNERS LDA-R12',
	'TRF.IMED. DE EXAMPLE E PARTNERS LDA-20261118',
	'TRF.IMED. DE EXAMPLE E PARTNERS LDA-R1234 TEXT',
	'TRF.IMED. DE EXAMPLE E PARTNERS LDA / THIRD PARTY',
	'TRF.IMED. PARA EXAMPLE E PARTNERS LDA',
	'TRF.IMED. P/ EXAMPLE E PARTNERS LDA',
	'TRANSFERENCIA EXAMPLE E PARTNERS LDA',
	'EXAMPLE E PARTNERS LDA',
	'ANUL.TRF.IMED. DE EXAMPLE E PARTNERS LDA',
	'DEVOLUCAO TRF DE EXAMPLE E PARTNERS LDA',
	'TRF LOTE DE EXAMPLE E PARTNERS LDA',
	'TRF COBR DUC DE EXAMPLE E PARTNERS LDA',
	'TRF SALARIO DE EXAMPLE E PARTNERS LDA',
	'TRF VENCIMENTOS DE EXAMPLE E PARTNERS LDA',
	'TRF TAXA DE EXAMPLE E PARTNERS LDA',
	'TRF COMISSAO DE EXAMPLE E PARTNERS LDA',
	'TRF JUROS DE EXAMPLE E PARTNERS LDA',
	'TRF PAGAMENTO DE EXAMPLE E PARTNERS LDA',
	'TRF DE EXAMPLE E PARTNERS LDA ESTORNO',
	'TRF DE EXAMPLE E PARTNERS LDA REFUND',
	'TRANSFERENCIA CONTA SERVICO DE 1234 5678 9012',
	'TRF UNRECOGNIZED RAIL DE EXAMPLE E PARTNERS LDA',
) as $description) {
	checkOwnTransferEvidence(ownTransferEvidence($description), '', 'Incomplete, contradictory or non-transfer source remains ineligible');
}
foreach (array('TRF LOTE DE DISPLAY LABEL', 'TRF SALARIO DE DISPLAY LABEL', 'TRF COBR DUC DE DISPLAY LABEL', 'TRF COMISSAO DE DISPLAY LABEL', 'TRANSFERENCIA CONTA SERVICO DE 1234 5678 9012', 'TRANSFERENCIA') as $description) {
	checkOwnTransferEvidence(ownTransferEvidence($description, '1', 'Exampleco', $owned, $owned), '', 'Unrelated flow cannot authorize a transfer through an IBAN');
}
foreach (array('0', '-0.00000000', '1,25', '1e2', '', null, false, 125.00) as $amount) {
	checkOwnTransferEvidence(ownTransferEvidence('TRF DE EXAMPLE E PARTNERS LDA', $amount), '', 'Only nonzero exact decimal evidence is accepted');
}
checkOwnTransferEvidence(ownTransferEvidence('TRF DE EXAMPLE E PARTNERS LDA', '-1'), '', 'Debit cannot use incoming DE identity');
foreach (array(-1, '-1', 2, '1x', array(), true) as $direction) {
	checkOwnTransferEvidence(KreaBankOwnTransferEvidence::ownedIdentity(array('amount'=>'1', 'direction'=>$direction, 'description'=>'TRF DE EXAMPLE E PARTNERS LDA'), 'Example e Partners'), '', 'Declared direction must agree with persisted sign');
}
foreach (array(0, '0', 1, '1') as $direction) {
	checkOwnTransferEvidence(KreaBankOwnTransferEvidence::ownedIdentity(array('amount'=>'1', 'direction'=>$direction, 'description'=>'TRF DE EXAMPLE E PARTNERS LDA'), 'Example e Partners'), 'company', 'Valid or unspecified declared direction retains exact sign');
}
foreach (array("TRF DE EXAMPLE E PARTNERS LDA\0", "TRF DE EXAMPLE \xFF PARTNERS LDA", array(), str_repeat('A', 2001)) as $description) {
	checkOwnTransferEvidence(ownTransferEvidence($description), '', 'Malformed or excessive text fails closed');
}
$line = array('amount'=>'1', 'direction'=>1, 'description'=>'TRF DE EXAMPLE E PARTNERS LDA', 'counterparty_iban'=>'');
$before = $line;
KreaBankOwnTransferEvidence::ownedIdentity($line, 'Example e Partners Lda');
checkOwnTransferEvidence($line, $before, 'Original financial evidence remains unchanged');
echo 'OK: ' . $checks . " own-transfer identity checks\n";
