<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    kreabank/admin/guide.php
 * \ingroup kreabank
 * \brief   How-to guide for KreaBank module.
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

$langs->loadLangs(array('errors', 'admin', 'kreabank@kreabank'));

if (!$user->admin) {
	accessforbidden();
}

$backtopage = GETPOST('backtopage', 'alpha');

$title = 'KreaBankGuide';
llxHeader('', $langs->trans($title), '', '', 0, 0, '', '', '', 'mod-kreabank page-admin_guide');

$linkback = '<a href="' . ($backtopage ? $backtopage : DOL_URL_ROOT . '/admin/modules.php?restore_lastsearch_values=1') . '">' . $langs->trans('BackToModuleList') . '</a>';
print load_fiche_titre($langs->trans($title), $linkback, 'title_setup');

$head = kreabankAdminPrepareHead();
print dol_get_fiche_head($head, 'guide', $langs->trans($title), -1, 'kreabank@kreabank');

$capabilityKeys = array(
	'KreaBankCapabilityNativeBank',
	'KreaBankCapabilityImportWizard',
	'KreaBankCapabilityDuplicateDetection',
	'KreaBankCapabilityDualPane',
	'KreaBankCapabilityBulkMatch',
	'KreaBankCapabilityLoteSalary',
	'KreaBankCapabilityQuickEntry',
	'KreaBankCapabilityAuditUndo',
	'KreaBankCapabilitySearchFilters',
);
$workflowKeys = array(
	'KreaBankWorkflowStep1',
	'KreaBankWorkflowStep2',
	'KreaBankWorkflowStep3',
	'KreaBankWorkflowStep4',
	'KreaBankWorkflowStep5',
	'KreaBankWorkflowStep6',
	'KreaBankWorkflowStep7',
);
$safetyKeys = array(
	'KreaBankSafetyTip1',
	'KreaBankSafetyTip2',
	'KreaBankSafetyTip3',
	'KreaBankSafetyTip4',
	'KreaBankSafetyTip5',
);

$operationalPages = array(
	array(
		'label' => $langs->trans('KreaBankImport'),
		'url' => dol_buildpath('/custom/kreabank/import.php?mainmenu=bank&leftmenu=kreabank_import', 1),
		'description' => $langs->trans('KreaBankGuidePageImportDesc'),
	),
	array(
		'label' => $langs->trans('KreaBankReconcile'),
		'url' => dol_buildpath('/custom/kreabank/reconcile.php?mainmenu=bank&leftmenu=kreabank_reconcile', 1),
		'description' => $langs->trans('KreaBankGuidePageReconcileDesc'),
	),
	array(
		'label' => $langs->trans('KreaBankBulkMatch'),
		'url' => dol_buildpath('/custom/kreabank/bulkmatch.php?mainmenu=bank&leftmenu=kreabank_bulkmatch', 1),
		'description' => $langs->trans('KreaBankGuidePageBulkDesc'),
	),
	array(
		'label' => $langs->trans('KreaBankPending'),
		'url' => dol_buildpath('/custom/kreabank/pending.php?mainmenu=bank&leftmenu=kreabank_pending', 1),
		'description' => $langs->trans('KreaBankGuidePagePendingDesc'),
	),
	array(
		'label' => $langs->trans('KreaBankHistory'),
		'url' => dol_buildpath('/custom/kreabank/history.php?mainmenu=bank&leftmenu=kreabank_history', 1),
		'description' => $langs->trans('KreaBankGuidePageHistoryDesc'),
	),
);

$troubleshooting = array(
	array(
		'issue' => $langs->trans('KreaBankTroubleNoDocsIssue'),
		'fix' => $langs->trans('KreaBankTroubleNoDocsFix'),
	),
	array(
		'issue' => $langs->trans('KreaBankTroubleAllocationIssue'),
		'fix' => $langs->trans('KreaBankTroubleAllocationFix'),
	),
	array(
		'issue' => $langs->trans('KreaBankTroubleDeleteStatementIssue'),
		'fix' => $langs->trans('KreaBankTroubleDeleteStatementFix'),
	),
);

print '<style>';
print '.krea-guide-intro{margin:8px 0 12px 0;padding:12px;border:1px solid #d6e2ec;background:#f8fbfe;border-radius:8px}';
print '.krea-guide-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:10px}';
print '.krea-guide-card{border:1px solid #dce5ee;border-radius:8px;background:#fff;padding:12px}';
print '.krea-guide-card h3{margin:0 0 8px 0;font-size:15px;color:#1f4e79}';
print '.krea-guide-card ul,.krea-guide-card ol{margin:0 0 0 18px;padding:0}';
print '.krea-guide-card li{margin:0 0 6px 0}';
print '.krea-guide-pages td{vertical-align:top}';
print '@media (max-width: 1200px){.krea-guide-grid{grid-template-columns:1fr}}';
print '</style>';

print '<div class="krea-guide-intro">';
print '<h2 style="margin:0 0 6px 0">' . $langs->trans('KreaBankGuideTitle') . '</h2>';
print '<p style="margin:0">' . $langs->trans('KreaBankGuideIntro') . '</p>';
print '</div>';

print '<div class="krea-guide-grid">';
print '<div class="krea-guide-card">';
print '<h3>' . $langs->trans('KreaBankGuideCapabilitiesTitle') . '</h3>';
print '<ul>';
foreach ($capabilityKeys as $capabilityKey) {
	print '<li>' . $langs->trans($capabilityKey) . '</li>';
}
print '</ul>';
print '</div>';

print '<div class="krea-guide-card">';
print '<h3>' . $langs->trans('KreaBankGuideWorkflowTitle') . '</h3>';
print '<ol>';
foreach ($workflowKeys as $workflowKey) {
	print '<li>' . $langs->trans($workflowKey) . '</li>';
}
print '</ol>';
print '</div>';
print '</div>';

print '<br>';
print '<div class="krea-guide-card">';
print '<h3>' . $langs->trans('KreaBankGuidePagesTitle') . '</h3>';
print '<table class="noborder centpercent krea-guide-pages">';
print '<tr class="liste_titre"><th>' . $langs->trans('KreaBankGuidePageColumn') . '</th><th>' . $langs->trans('KreaBankGuidePurposeColumn') . '</th></tr>';
foreach ($operationalPages as $page) {
	print '<tr class="oddeven">';
	print '<td><a href="' . dol_escape_htmltag($page['url']) . '" target="_blank" rel="noopener">' . dol_escape_htmltag($page['label']) . '</a></td>';
	print '<td>' . dol_escape_htmltag($page['description']) . '</td>';
	print '</tr>';
}
print '</table>';
print '</div>';

print '<br>';
print '<div class="krea-guide-grid">';
print '<div class="krea-guide-card">';
print '<h3>' . $langs->trans('KreaBankGuideSafetyTitle') . '</h3>';
print '<ul>';
foreach ($safetyKeys as $safetyKey) {
	print '<li>' . $langs->trans($safetyKey) . '</li>';
}
print '</ul>';
print '</div>';

print '<div class="krea-guide-card">';
print '<h3>' . $langs->trans('KreaBankGuideTroubleshootingTitle') . '</h3>';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><th>' . $langs->trans('KreaBankGuideIssueColumn') . '</th><th>' . $langs->trans('KreaBankGuideFixColumn') . '</th></tr>';
foreach ($troubleshooting as $row) {
	print '<tr class="oddeven">';
	print '<td>' . dol_escape_htmltag($row['issue']) . '</td>';
	print '<td>' . dol_escape_htmltag($row['fix']) . '</td>';
	print '</tr>';
}
print '</table>';
print '</div>';
print '</div>';

print dol_get_fiche_end();
llxFooter();
$db->close();
