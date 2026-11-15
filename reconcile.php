<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file       kreabank/reconcile.php
 * \ingroup    kreabank
 * \brief      Retired address: redirects to the workspace inside the reconciliation list.
 */

$res = 0;
if (!$res && !empty($_SERVER['CONTEXT_DOCUMENT_ROOT'])) {
	$res = @include $_SERVER['CONTEXT_DOCUMENT_ROOT'] . '/main.inc.php';
}
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);
$i = strlen($tmp) - 1;
$j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] === $tmp2[$j]) {
	$i--;
	$j--;
}
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1)) . '/main.inc.php')) {
	$res = @include substr($tmp, 0, ($i + 1)) . '/main.inc.php';
}
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1))) . '/main.inc.php')) {
	$res = @include dirname(substr($tmp, 0, ($i + 1))) . '/main.inc.php';
}
if (!$res && file_exists('../main.inc.php')) {
	$res = @include '../main.inc.php';
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

require_once __DIR__ . '/lib/kreabank.lib.php';
require_once __DIR__ . '/lib/kreabank_navigation.lib.php';

// The selected-line workspace now lives inside reconciliationlist.php (1.20.47). This address
// only keeps old links and bookmarks working: it reads nothing, writes nothing, accepts no action.
if (!empty($user->socid) || !isModEnabled('kreabank') || !isModEnabled('banque')
	|| !$user->hasRight('kreabank', 'reconciliation', 'read') || !$user->hasRight('banque', 'lire')) {
	accessforbidden();
}
$retiredAccount = kreabankNavigationAccount(GETPOSTINT('account_context'));
$retiredUrl = kreabankWorkspaceUrl('reconcile', $retiredAccount ? (int) $retiredAccount->id : 0);
if (GETPOSTINT('line_id') > 0) {
	$retiredUrl .= '&line_id=' . GETPOSTINT('line_id') . (in_array(GETPOST('line_source', 'aZ09'), array('staged', 'native'), true) ? '&line_source=' . GETPOST('line_source', 'aZ09') : '');
}
header('Location: ' . $retiredUrl);
exit;
