<?php
/* Copyright (C) 2004-2017 Laurent Destailleur <eldy@users.sourceforge.net>
 * Copyright (C) 2024 Frédéric France <frederic.france@free.fr>
 * Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    kreabank/admin/setup.php
 * \ingroup kreabank
 * \brief   KreaBank setup page.
 */

$res = 0;
if (!$res && !empty($_SERVER['CONTEXT_DOCUMENT_ROOT'])) {
	$res = @include $_SERVER['CONTEXT_DOCUMENT_ROOT'] . '/main.inc.php';
}
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);
$i = strlen($tmp) - 1;
$j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) {
	$i--;
	$j--;
}
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1)) . '/main.inc.php')) {
	$res = @include substr($tmp, 0, ($i + 1)) . '/main.inc.php';
}
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1))) . '/main.inc.php')) {
	$res = @include dirname(substr($tmp, 0, ($i + 1))) . '/main.inc.php';
}
if (!$res && file_exists('../../main.inc.php')) {
	$res = @include '../../main.inc.php';
}
if (!$res && file_exists('../../../main.inc.php')) {
	$res = @include '../../../main.inc.php';
}
if (!$res) {
	die('Include of main fails');
}

require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
require_once '../lib/kreabank.lib.php';
require_once '../class/KreaBankService.class.php';

if (!class_exists('FormSetup')) {
	require_once DOL_DOCUMENT_ROOT . '/core/class/html.formsetup.class.php';
}

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var HookManager $hookmanager
 * @var Translate $langs
 * @var User $user
 */

$langs->loadLangs(array('admin', 'kreabank@kreabank'));

if (!$user->admin) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');
$backtopage = GETPOST('backtopage', 'alpha');

$hookmanager->initHooks(array('kreabanksetup', 'globalsetup'));
$mlRuntimeSupported = (defined('PHP_VERSION_ID') ? ((int) PHP_VERSION_ID >= 80000) : version_compare((string) PHP_VERSION, '8.0.0', '>='));
$service = new KreaBankService($db, $user, $langs);

if (!isset($conf->global->KREABANK_DISCARD_ZERO_INVOICES)) {
	dolibarr_set_const($db, 'KREABANK_DISCARD_ZERO_INVOICES', '1', 'yesno', 0, '', $conf->entity);
	$conf->global->KREABANK_DISCARD_ZERO_INVOICES = 1;
}
if (!isset($conf->global->KREABANK_OPEN_DOC_DATE_INTERVAL_DAYS)) {
	dolibarr_set_const($db, 'KREABANK_OPEN_DOC_DATE_INTERVAL_DAYS', '7', 'chaine', 0, '', $conf->entity);
	$conf->global->KREABANK_OPEN_DOC_DATE_INTERVAL_DAYS = 7;
}
if (!isset($conf->global->KREABANK_BATCH_MIN_CANDIDATES)) {
	dolibarr_set_const($db, 'KREABANK_BATCH_MIN_CANDIDATES', '4', 'integer', 0, '', $conf->entity);
	$conf->global->KREABANK_BATCH_MIN_CANDIDATES = 4;
}
if (!isset($conf->global->KREABANK_BATCH_MIN_COVERAGE_PCT)) {
	dolibarr_set_const($db, 'KREABANK_BATCH_MIN_COVERAGE_PCT', '90', 'integer', 0, '', $conf->entity);
	$conf->global->KREABANK_BATCH_MIN_COVERAGE_PCT = 90;
}
if (!isset($conf->global->KREABANK_BATCH_HINT_KEYWORDS)) {
	dolibarr_set_const($db, 'KREABANK_BATCH_HINT_KEYWORDS', 'LOTE', 'chaine', 0, '', $conf->entity);
	$conf->global->KREABANK_BATCH_HINT_KEYWORDS = 'LOTE';
}
if (!isset($conf->global->KREABANK_BATCH_ML_ENABLED)) {
	dolibarr_set_const($db, 'KREABANK_BATCH_ML_ENABLED', '1', 'yesno', 0, '', $conf->entity);
	$conf->global->KREABANK_BATCH_ML_ENABLED = 1;
}
if (!isset($conf->global->KREABANK_BATCH_ML_CLASSIFIER)) {
	dolibarr_set_const($db, 'KREABANK_BATCH_ML_CLASSIFIER', 'knn', 'chaine', 0, '', $conf->entity);
	$conf->global->KREABANK_BATCH_ML_CLASSIFIER = 'knn';
}
if (!isset($conf->global->KREABANK_BATCH_ML_AUTO_THRESHOLD)) {
	dolibarr_set_const($db, 'KREABANK_BATCH_ML_AUTO_THRESHOLD', '80', 'integer', 0, '', $conf->entity);
	$conf->global->KREABANK_BATCH_ML_AUTO_THRESHOLD = 80;
}
if (!isset($conf->global->KREABANK_BATCH_ML_MIN_SAMPLES)) {
	dolibarr_set_const($db, 'KREABANK_BATCH_ML_MIN_SAMPLES', '24', 'integer', 0, '', $conf->entity);
	$conf->global->KREABANK_BATCH_ML_MIN_SAMPLES = 24;
}
if (!isset($conf->global->KREABANK_SUPPLIER_ML_ENABLED)) {
	dolibarr_set_const($db, 'KREABANK_SUPPLIER_ML_ENABLED', '1', 'yesno', 0, '', $conf->entity);
	$conf->global->KREABANK_SUPPLIER_ML_ENABLED = 1;
}
if (!isset($conf->global->KREABANK_SUPPLIER_ML_MIN_CONFIDENCE)) {
	dolibarr_set_const($db, 'KREABANK_SUPPLIER_ML_MIN_CONFIDENCE', '70', 'integer', 0, '', $conf->entity);
	$conf->global->KREABANK_SUPPLIER_ML_MIN_CONFIDENCE = 70;
}
if (!isset($conf->global->KREABANK_SUPPLIER_ML_MIN_SAMPLES)) {
	dolibarr_set_const($db, 'KREABANK_SUPPLIER_ML_MIN_SAMPLES', '18', 'integer', 0, '', $conf->entity);
	$conf->global->KREABANK_SUPPLIER_ML_MIN_SAMPLES = 18;
}
if (!isset($conf->global->KREABANK_BULKMATCH_SCAN_CACHE_TTL)) {
	dolibarr_set_const($db, 'KREABANK_BULKMATCH_SCAN_CACHE_TTL', '120', 'integer', 0, '', $conf->entity);
	$conf->global->KREABANK_BULKMATCH_SCAN_CACHE_TTL = 120;
}
if (!isset($conf->global->KREABANK_BULKMATCH_SCAN_CHUNK_SIZE)) {
	dolibarr_set_const($db, 'KREABANK_BULKMATCH_SCAN_CHUNK_SIZE', '200', 'integer', 0, '', $conf->entity);
	$conf->global->KREABANK_BULKMATCH_SCAN_CHUNK_SIZE = 200;
}
if (!isset($conf->global->KREABANK_BULKMATCH_SCAN_REQUEST_BUDGET_MS)) {
	dolibarr_set_const($db, 'KREABANK_BULKMATCH_SCAN_REQUEST_BUDGET_MS', '1200', 'integer', 0, '', $conf->entity);
	$conf->global->KREABANK_BULKMATCH_SCAN_REQUEST_BUDGET_MS = 1200;
}
if (!isset($conf->global->KREABANK_AUDIT_RETENTION_DAYS)) {
	dolibarr_set_const($db, 'KREABANK_AUDIT_RETENTION_DAYS', '365', 'integer', 0, '', $conf->entity);
	$conf->global->KREABANK_AUDIT_RETENTION_DAYS = 365;
}

$formSetup = new FormSetup($db);

$formSetup->newItem('KreaBankMatchingTitle')->setAsTitle();

$item = $formSetup->newItem('KREABANK_AUTOMATCH_DATE_TOLERANCE');
$item->fieldAttr['type'] = 'number';
$item->fieldAttr['min'] = '0';
$item->helpText = $langs->trans('KreaBankAutomatchDateToleranceHelp');

$item = $formSetup->newItem('KREABANK_BATCH_MIN_CANDIDATES');
$item->fieldAttr['type'] = 'number';
$item->fieldAttr['min'] = '1';
$item->helpText = $langs->trans('KreaBankBatchMinCandidatesHelp');

$item = $formSetup->newItem('KREABANK_BATCH_MIN_COVERAGE_PCT');
$item->fieldAttr['type'] = 'number';
$item->fieldAttr['min'] = '10';
$item->fieldAttr['max'] = '200';
$item->helpText = $langs->trans('KreaBankBatchMinCoveragePctHelp');

$item = $formSetup->newItem('KREABANK_BATCH_HINT_KEYWORDS');
$batchHintLabel = $langs->trans('KREABANK_BATCH_HINT_KEYWORDS');
if ($batchHintLabel === 'KREABANK_BATCH_HINT_KEYWORDS') {
	$batchHintLabel = 'Palavras-chave para deteccao de lote (separadas por virgula)';
}
$batchHintHelp = $langs->trans('KreaBankBatchHintKeywordsHelp');
if ($batchHintHelp === 'KreaBankBatchHintKeywordsHelp') {
	$batchHintHelp = 'Palavras-chave separadas por virgula pesquisadas no texto da linha para sugerir modo lote (exemplo: LOTE, SALARIO, FOLHA).';
}
$item->nameText = $batchHintLabel;
$item->fieldAttr['placeholder'] = 'LOTE, SALARIO, FOLHA';
$item->helpText = $batchHintHelp;

$item = $formSetup->newItem('KREABANK_BATCH_ML_ENABLED')->setAsYesNo();
$item->helpText = $langs->trans('KreaBankBatchMlEnabledHelp');
if (!$mlRuntimeSupported) {
	$item->helpText .= ' ' . $langs->trans('KreaBankMlRequiresPhp8Help', PHP_VERSION);
}

$item = $formSetup->newItem('KREABANK_BATCH_ML_CLASSIFIER')->setAsSelect(array(
	'knn' => 'KNN',
	'naive_bayes' => 'Naive Bayes',
	'decision_tree' => 'Decision Tree',
	'random_forest' => 'Random Forest',
));
$item->nameText = 'Batch ML classifier algorithm';
$item->helpText = 'Select the classifier family used for batch prediction training and inference.';

$item = $formSetup->newItem('KREABANK_BATCH_ML_AUTO_THRESHOLD');
$item->fieldAttr['type'] = 'number';
$item->fieldAttr['min'] = '50';
$item->fieldAttr['max'] = '99';
$item->helpText = $langs->trans('KreaBankBatchMlAutoThresholdHelp');

$item = $formSetup->newItem('KREABANK_BATCH_ML_MIN_SAMPLES');
$item->fieldAttr['type'] = 'number';
$item->fieldAttr['min'] = '8';
$item->fieldAttr['max'] = '2000';
$item->helpText = $langs->trans('KreaBankBatchMlMinSamplesHelp');

$item = $formSetup->newItem('KREABANK_SUPPLIER_ML_ENABLED')->setAsYesNo();
$item->helpText = $langs->trans('KreaBankSupplierMlEnabledHelp');
if (!$mlRuntimeSupported) {
	$item->helpText .= ' ' . $langs->trans('KreaBankMlRequiresPhp8Help', PHP_VERSION);
}

$item = $formSetup->newItem('KREABANK_SUPPLIER_ML_MIN_CONFIDENCE');
$item->fieldAttr['type'] = 'number';
$item->fieldAttr['min'] = '35';
$item->fieldAttr['max'] = '99';
$item->helpText = $langs->trans('KreaBankSupplierMlMinConfidenceHelp');

$item = $formSetup->newItem('KREABANK_SUPPLIER_ML_MIN_SAMPLES');
$item->fieldAttr['type'] = 'number';
$item->fieldAttr['min'] = '8';
$item->fieldAttr['max'] = '2000';
$item->helpText = $langs->trans('KreaBankSupplierMlMinSamplesHelp');

$item = $formSetup->newItem('KREABANK_AUTOMATCH_SAFE_SCORE');
$item->fieldAttr['type'] = 'number';
$item->fieldAttr['min'] = '0';
$item->helpText = $langs->trans('KreaBankAutomatchSafeScoreHelp');

$item = $formSetup->newItem('KREABANK_AUTOMATCH_PARTIAL_SCORE');
$item->fieldAttr['type'] = 'number';
$item->fieldAttr['min'] = '0';
$item->helpText = $langs->trans('KreaBankAutomatchPartialScoreHelp');

$formSetup->newItem('KREABANK_HIDE_RECONCILED_LINES')->setAsYesNo();
$item = $formSetup->newItem('KREABANK_DISCARD_ZERO_INVOICES')->setAsYesNo();
$item->helpText = $langs->trans('KreaBankDiscardZeroInvoicesHelp');
$item = $formSetup->newItem('KREABANK_OPEN_DOC_DATE_INTERVAL_DAYS');
$item->fieldAttr['type'] = 'number';
$item->fieldAttr['min'] = '0';
$item->helpText = $langs->trans('KreaBankOpenDocDateIntervalDaysHelp');

$item = $formSetup->newItem('KREABANK_BULKMATCH_SCAN_CACHE_TTL');
$item->fieldAttr['type'] = 'number';
$item->fieldAttr['min'] = '30';
$item->fieldAttr['max'] = '3600';
$item->nameText = 'Bulk scan cache TTL (seconds)';
$item->helpText = 'How long partial/full bulk scan cache stays valid for current entity and user.';

$item = $formSetup->newItem('KREABANK_BULKMATCH_SCAN_CHUNK_SIZE');
$item->fieldAttr['type'] = 'number';
$item->fieldAttr['min'] = '20';
$item->fieldAttr['max'] = '2000';
$item->nameText = 'Bulk scan chunk size';
$item->helpText = 'Number of pending lines loaded per scan chunk request.';

$item = $formSetup->newItem('KREABANK_BULKMATCH_SCAN_REQUEST_BUDGET_MS');
$item->fieldAttr['type'] = 'number';
$item->fieldAttr['min'] = '200';
$item->fieldAttr['max'] = '10000';
$item->nameText = 'Bulk scan request budget (ms)';
$item->helpText = 'Maximum scanning time per HTTP request before returning partial progress.';

$formSetup->newItem('KreaBankImportTitle')->setAsTitle();
$formSetup->newItem('KREABANK_DEFAULT_IMPORT_DELIMITER')->setAsSelect(array(
	';' => $langs->trans('KreaBankDelimiterSemicolon'),
	',' => $langs->trans('KreaBankDelimiterComma'),
	'\t' => $langs->trans('KreaBankDelimiterTab'),
));

$formSetup->newItem('KreaBankFeedTitle')->setAsTitle();
$formSetup->newItem('KREABANK_FEED_ENABLED')->setAsYesNo();
$formSetup->newItem('KREABANK_FEED_PROVIDER')->setAsSelect(array(
	'nordigen' => 'GoCardless / Nordigen',
	'gocardless' => 'GoCardless API',
));

$item = $formSetup->newItem('KREABANK_FEED_SECRET_ID');
$item->fieldAttr['placeholder'] = $langs->trans('KreaBankSecretIdPlaceholder');
$item->cssClass = 'minwidth500';

$item = $formSetup->newItem('KREABANK_FEED_SECRET_KEY');
$item->fieldAttr['placeholder'] = $langs->trans('KreaBankSecretKeyPlaceholder');
$item->cssClass = 'minwidth500';

$formSetup->newItem('KreaBankAuditTitle')->setAsTitle();
$item = $formSetup->newItem('KREABANK_AUDIT_RETENTION_DAYS');
$item->fieldAttr['type'] = 'number';
$item->fieldAttr['min'] = '1';
$item->fieldAttr['max'] = '3650';
$item->nameText = 'Audit retention (days)';
$item->helpText = 'Reconciliation audit rows older than this window are eligible for manual purge.';

if (versioncompare(explode('.', DOL_VERSION), array(15)) < 0 && $action == 'update' && !empty($user->admin)) {
	$formSetup->saveConfFromPost();
}

if ($action === 'purge_audit_retention' && $_SERVER['REQUEST_METHOD'] === 'POST') {
	if (!kreabankIsTokenValid()) {
		accessforbidden('Invalid token');
	}
	try {
		$deletedRows = (int) $service->purgeAuditRowsOlderThanRetention();
		setEventMessages('Audit purge completed. Deleted rows: ' . $deletedRows, null, 'mesgs');
	} catch (Exception $e) {
		setEventMessages('Audit purge failed: ' . $e->getMessage(), null, 'errors');
	}
}

include DOL_DOCUMENT_ROOT . '/core/actions_setmoduleoptions.inc.php';

$auditRetentionDiagnostics = $service->getAuditRetentionDiagnostics();
$integrityDiagnostics = $service->getReferentialIntegrityDiagnostics();
$batchMlValidationReport = $service->getBatchMlValidationReport();

$form = new Form($db);
$title = 'KreaBankSetup';

llxHeader('', $langs->trans($title), '', '', 0, 0, '', '', '', 'mod-kreabank page-admin');

$linkback = '<a href="' . ($backtopage ? $backtopage : DOL_URL_ROOT . '/admin/modules.php?restore_lastsearch_values=1') . '">' . $langs->trans('BackToModuleList') . '</a>';
print load_fiche_titre($langs->trans($title), $linkback, 'title_setup');

$head = kreabankAdminPrepareHead();
print dol_get_fiche_head($head, 'settings', $langs->trans($title), -1, 'kreabank@kreabank');

print '<div class="opacitymedium">' . $langs->trans('KreaBankSetupPage') . '</div><br>';
if (!$mlRuntimeSupported) {
	print '<div class="warning">' . $langs->trans('KreaBankMlDisabledByPhpVersion', PHP_VERSION) . '</div><br>';
}
print $formSetup->generateOutput(true);

print '<br><div class="fichecenter">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><th colspan="2">Batch ML validation (stratified holdout)</th></tr>';
print '<tr><td class="titlefield">Classifier</td><td>' . dol_escape_htmltag((string) $batchMlValidationReport['classifier']) . '</td></tr>';
print '<tr><td class="titlefield">Sample count</td><td>' . ((int) $batchMlValidationReport['sample_count']) . '</td></tr>';
print '<tr><td class="titlefield">Train/Test split</td><td>' . ((int) $batchMlValidationReport['train_count']) . ' / ' . ((int) $batchMlValidationReport['test_count']) . '</td></tr>';
print '<tr><td class="titlefield">Accuracy / Precision / Recall</td><td>' . price((float) $batchMlValidationReport['accuracy_pct'], 2) . '% / ' . price((float) $batchMlValidationReport['precision_pct'], 2) . '% / ' . price((float) $batchMlValidationReport['recall_pct'], 2) . '%</td></tr>';
print '<tr><td class="titlefield">Confusion matrix (TP/FP/TN/FN)</td><td>' . ((int) $batchMlValidationReport['confusion']['tp']) . ' / ' . ((int) $batchMlValidationReport['confusion']['fp']) . ' / ' . ((int) $batchMlValidationReport['confusion']['tn']) . ' / ' . ((int) $batchMlValidationReport['confusion']['fn']) . '</td></tr>';
print '<tr><td class="titlefield">Status</td><td>' . dol_escape_htmltag((string) $batchMlValidationReport['status']) . '</td></tr>';
print '</table>';
print '</div>';

print '<br><div class="fichecenter">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><th colspan="2">Referential integrity diagnostics (entity ' . ((int) $integrityDiagnostics['entity']) . ')</th></tr>';
print '<tr><td class="titlefield">Orphan staged lines</td><td>' . ((int) $integrityDiagnostics['orphan_statement_lines']) . '</td></tr>';
print '<tr><td class="titlefield">Orphan statements</td><td>' . ((int) $integrityDiagnostics['orphan_statements']) . '</td></tr>';
print '<tr><td class="titlefield">Orphan native bank metadata rows</td><td>' . ((int) $integrityDiagnostics['orphan_bankmeta_rows']) . '</td></tr>';
print '<tr><td class="titlefield">Native metadata account mismatches</td><td>' . ((int) $integrityDiagnostics['bankmeta_account_mismatches']) . '</td></tr>';
print '<tr><td class="titlefield">Status</td><td>' . (!empty($integrityDiagnostics['ok']) ? 'OK' : 'Issues detected') . '</td></tr>';
print '</table>';
print '</div>';

print '<br><div class="fichecenter">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><th colspan="2">Audit retention diagnostics (entity ' . ((int) $conf->entity) . ')</th></tr>';
print '<tr><td class="titlefield">Retention days</td><td>' . ((int) $auditRetentionDiagnostics['retention_days']) . '</td></tr>';
print '<tr><td class="titlefield">Cutoff date</td><td>' . dol_escape_htmltag((string) $auditRetentionDiagnostics['cutoff_date']) . '</td></tr>';
print '<tr><td class="titlefield">Total audit rows</td><td>' . ((int) $auditRetentionDiagnostics['total_rows']) . '</td></tr>';
print '<tr><td class="titlefield">Purgeable rows</td><td>' . ((int) $auditRetentionDiagnostics['purgeable_rows']) . '</td></tr>';
print '<tr><td class="titlefield">Oldest/Newest audit date</td><td>' . dol_escape_htmltag((string) $auditRetentionDiagnostics['oldest_date']) . ' / ' . dol_escape_htmltag((string) $auditRetentionDiagnostics['newest_date']) . '</td></tr>';
print '<tr><td class="titlefield">Manual purge</td><td>';
print '<form method="POST" action="' . dol_escape_htmltag($_SERVER['PHP_SELF']) . '" style="display:inline-block">';
print '<input type="hidden" name="token" value="' . newToken() . '">';
print '<input type="hidden" name="action" value="purge_audit_retention">';
print '<button class="button" type="submit">Purge rows older than retention</button>';
print '</form>';
print '</td></tr>';
print '</table>';
print '</div>';

print dol_get_fiche_end();
llxFooter();
$db->close();
