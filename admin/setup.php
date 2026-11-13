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
$item->fieldAttr['placeholder'] = 'LOTE, SALARIO, FOLHA';
$item->helpText = $langs->trans('KreaBankBatchHintKeywordsHelp');

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
$item->helpText = $langs->trans('KreaBankBatchMlClassifierHelp');

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
$item->helpText = $langs->trans('KreaBankBulkScanCacheTtlHelp');

$item = $formSetup->newItem('KREABANK_BULKMATCH_SCAN_CHUNK_SIZE');
$item->fieldAttr['type'] = 'number';
$item->fieldAttr['min'] = '20';
$item->fieldAttr['max'] = '2000';
$item->helpText = $langs->trans('KreaBankBulkScanChunkSizeHelp');

$item = $formSetup->newItem('KREABANK_BULKMATCH_SCAN_REQUEST_BUDGET_MS');
$item->fieldAttr['type'] = 'number';
$item->fieldAttr['min'] = '200';
$item->fieldAttr['max'] = '10000';
$item->helpText = $langs->trans('KreaBankBulkScanRequestBudgetHelp');

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
$item->helpText = $langs->trans('KreaBankAuditRetentionHelp');

if (versioncompare(explode('.', DOL_VERSION), array(15)) < 0 && $action == 'update' && !empty($user->admin)) {
	$formSetup->saveConfFromPost();
}

if ($action === 'purge_audit_retention' && $_SERVER['REQUEST_METHOD'] === 'POST') {
	if (!kreabankIsTokenValid()) {
		accessforbidden('Invalid token');
	}
	try {
		$deletedRows = (int) $service->purgeAuditRowsOlderThanRetention();
		setEventMessages($langs->trans('KreaBankAuditPurgeCompleted', $deletedRows), null, 'mesgs');
	} catch (Exception $e) {
		setEventMessages($langs->trans('KreaBankAuditPurgeFailed', $e->getMessage()), null, 'errors');
	}
}

include DOL_DOCUMENT_ROOT . '/core/actions_setmoduleoptions.inc.php';

$auditRetentionDiagnostics = $service->getAuditRetentionDiagnostics();
$integrityDiagnostics = $service->getReferentialIntegrityDiagnostics();
$batchMlValidationReport = $service->getBatchMlValidationReport();

$batchMlStatusLabels = array(
	'disabled' => $langs->trans('KreaBankMlStatusDisabled'),
	'disabled_by_setup_or_dependency' => $langs->trans('KreaBankMlStatusDisabledBySetupOrDependency'),
	'model_not_ready' => $langs->trans('KreaBankMlStatusModelNotReady'),
	'class_coverage_insufficient' => $langs->trans('KreaBankMlStatusClassCoverageInsufficient'),
	'holdout_split_failed' => $langs->trans('KreaBankMlStatusHoldoutSplitFailed'),
	'classifier_unavailable' => $langs->trans('KreaBankMlStatusClassifierUnavailable'),
	'validation_train_failed' => $langs->trans('KreaBankMlStatusValidationTrainFailed'),
	'validation_no_predictions' => $langs->trans('KreaBankMlStatusValidationNoPredictions'),
	'ok' => $langs->trans('KreaBankMlStatusOk'),
	'validation_prediction_failed' => $langs->trans('KreaBankMlStatusPredictionFailed'),
);
$batchMlStatus = (string) $batchMlValidationReport['status'];

$form = new Form($db);
$title = 'KreaBankSetup';

llxHeader('', $langs->trans($title), '', '', 0, 0, '', '', '', 'mod-kreabank page-admin');

$linkback = '<a href="' . ($backtopage ? $backtopage : DOL_URL_ROOT . '/admin/modules.php?restore_lastsearch_values=1') . '">' . $langs->trans('BackToModuleList') . '</a>';
print load_fiche_titre($langs->trans($title), $linkback, 'title_setup');

$head = kreabankAdminPrepareHead();
print dol_get_fiche_head($head, 'settings', $langs->trans($title), -1, 'bank.svg@kreabank');

print '<div class="opacitymedium">' . $langs->trans('KreaBankSetupPage') . '</div><br>';
if (!$mlRuntimeSupported) {
	print '<div class="warning">' . $langs->trans('KreaBankMlDisabledByPhpVersion', PHP_VERSION) . '</div><br>';
}
print $formSetup->generateOutput(true);

print '<br><div class="fichecenter">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><th colspan="2">' . $langs->trans('KreaBankBatchMlValidation') . '</th></tr>';
print '<tr><td class="titlefield">' . $langs->trans('KreaBankClassifier') . '</td><td>' . dol_escape_htmltag((string) $batchMlValidationReport['classifier']) . '</td></tr>';
print '<tr><td class="titlefield">' . $langs->trans('KreaBankSampleCount') . '</td><td>' . ((int) $batchMlValidationReport['sample_count']) . '</td></tr>';
print '<tr><td class="titlefield">' . $langs->trans('KreaBankTrainTestSplit') . '</td><td>' . ((int) $batchMlValidationReport['train_count']) . ' / ' . ((int) $batchMlValidationReport['test_count']) . '</td></tr>';
print '<tr><td class="titlefield">' . $langs->trans('KreaBankValidationMetrics') . '</td><td>' . kreabankPrice((float) $batchMlValidationReport['accuracy_pct']) . '% / ' . kreabankPrice((float) $batchMlValidationReport['precision_pct']) . '% / ' . kreabankPrice((float) $batchMlValidationReport['recall_pct']) . '%</td></tr>';
print '<tr><td class="titlefield">' . $langs->trans('KreaBankConfusionMatrix') . '</td><td>' . ((int) $batchMlValidationReport['confusion']['tp']) . ' / ' . ((int) $batchMlValidationReport['confusion']['fp']) . ' / ' . ((int) $batchMlValidationReport['confusion']['tn']) . ' / ' . ((int) $batchMlValidationReport['confusion']['fn']) . '</td></tr>';
print '<tr><td class="titlefield">' . $langs->trans('KreaBankValidationAdvancedMetrics') . '</td><td>' . kreabankPrice($batchMlValidationReport['f1_pct']) . '% / ' . kreabankPrice($batchMlValidationReport['balanced_accuracy_pct']) . '% / ' . kreabankPrice($batchMlValidationReport['baseline_accuracy_pct']) . '%</td></tr>';
print '<tr><td class="titlefield">' . $langs->trans('KreaBankValidationExcludedSamples') . '</td><td>' . ((int) $batchMlValidationReport['legacy_sample_count']) . ' / ' . ((int) $batchMlValidationReport['invalid_sample_count']) . ' / ' . ((int) $batchMlValidationReport['duplicate_train_count']) . '</td></tr>';
print '<tr><td class="titlefield">' . $langs->trans('KreaBankValidationPredictionCounts') . '</td><td>' . ((int) $batchMlValidationReport['evaluated_count']) . ' / ' . ((int) $batchMlValidationReport['prediction_errors']) . '</td></tr>';
print '<tr><td class="titlefield">' . $langs->trans('KreaBankStatus') . '</td><td>' . dol_escape_htmltag(isset($batchMlStatusLabels[$batchMlStatus]) ? $batchMlStatusLabels[$batchMlStatus] : $batchMlStatus) . '</td></tr>';
print '</table>';
print '</div>';

print '<br><div class="fichecenter">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><th colspan="2">' . $langs->trans('KreaBankIntegrityDiagnostics', (int) $integrityDiagnostics['entity']) . '</th></tr>';
print '<tr><td class="titlefield">' . $langs->trans('KreaBankOrphanStagedLines') . '</td><td>' . ((int) $integrityDiagnostics['orphan_statement_lines']) . '</td></tr>';
print '<tr><td class="titlefield">' . $langs->trans('KreaBankOrphanStatements') . '</td><td>' . ((int) $integrityDiagnostics['orphan_statements']) . '</td></tr>';
print '<tr><td class="titlefield">' . $langs->trans('KreaBankOrphanBankMetadata') . '</td><td>' . ((int) $integrityDiagnostics['orphan_bankmeta_rows']) . '</td></tr>';
print '<tr><td class="titlefield">' . $langs->trans('KreaBankMetadataAccountMismatches') . '</td><td>' . ((int) $integrityDiagnostics['bankmeta_account_mismatches']) . '</td></tr>';
print '<tr><td class="titlefield">' . $langs->trans('KreaBankStatus') . '</td><td>' . (!empty($integrityDiagnostics['ok']) ? $langs->trans('KreaBankDiagnosticsOk') : $langs->trans('KreaBankDiagnosticsIssues')) . '</td></tr>';
print '</table>';
print '</div>';

print '<br><div class="fichecenter">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><th colspan="2">' . $langs->trans('KreaBankAuditRetentionDiagnostics', (int) $conf->entity) . '</th></tr>';
print '<tr><td class="titlefield">' . $langs->trans('KreaBankRetentionDays') . '</td><td>' . ((int) $auditRetentionDiagnostics['retention_days']) . '</td></tr>';
print '<tr><td class="titlefield">' . $langs->trans('KreaBankCutoffDate') . '</td><td>' . dol_escape_htmltag((string) $auditRetentionDiagnostics['cutoff_date']) . '</td></tr>';
print '<tr><td class="titlefield">' . $langs->trans('KreaBankTotalAuditRows') . '</td><td>' . ((int) $auditRetentionDiagnostics['total_rows']) . '</td></tr>';
print '<tr><td class="titlefield">' . $langs->trans('KreaBankPurgeableRows') . '</td><td>' . ((int) $auditRetentionDiagnostics['purgeable_rows']) . '</td></tr>';
print '<tr><td class="titlefield">' . $langs->trans('KreaBankAuditDateRange') . '</td><td>' . dol_escape_htmltag((string) $auditRetentionDiagnostics['oldest_date']) . ' / ' . dol_escape_htmltag((string) $auditRetentionDiagnostics['newest_date']) . '</td></tr>';
print '<tr><td class="titlefield">' . $langs->trans('KreaBankManualPurge') . '</td><td>';
print '<form method="POST" action="' . dol_escape_htmltag($_SERVER['PHP_SELF']) . '" style="display:inline-block">';
print '<input type="hidden" name="token" value="' . newToken() . '">';
print '<input type="hidden" name="action" value="purge_audit_retention">';
print '<button class="button" type="submit">' . $langs->trans('KreaBankPurgeOldAuditRows') . '</button>';
print '</form>';
print '</td></tr>';
print '</table>';
print '</div>';

print dol_get_fiche_end();
llxFooter();
$db->close();
