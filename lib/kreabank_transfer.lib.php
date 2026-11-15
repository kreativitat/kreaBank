<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>; GPL-3.0-or-later */

/** Read the five most-used committed descriptions, counting each owned transfer once. */
function kreabankTransferDescriptions($db, $user, $entity)
{
	$entity = (int) $entity;
	if ($entity <= 0 || !empty($user->socid) || !$user->hasRight('banque', 'lire')
		|| !$user->hasRight('banque', 'transfer') || !$user->hasRight('kreabank', 'reconciliation', 'read')) {
		return array();
	}
	$p = $db->prefix();
	$sql = "SELECT MIN(TRIM(b.label)) AS description, COUNT(DISTINCT b.rowid) AS uses, MAX(b.dateo) AS last_used"
		. " FROM {$p}bank b JOIN {$p}bank_account source_account ON source_account.rowid=b.fk_account AND source_account.entity={$entity}"
		. " JOIN {$p}bank_url u ON u.fk_bank=b.rowid AND u.type='banktransfert'"
		. " JOIN {$p}bank peer ON peer.rowid=u.url_id AND peer.fk_account<>b.fk_account"
		. " JOIN {$p}bank_account target_account ON target_account.rowid=peer.fk_account AND target_account.entity={$entity}"
		. " WHERE b.amount<0 AND peer.amount>0 AND TRIM(b.label)<>'' AND b.label=peer.label"
		. " AND EXISTS (SELECT 1 FROM {$p}bank_url reciprocal WHERE reciprocal.fk_bank=peer.rowid AND reciprocal.type='banktransfert' AND reciprocal.url_id=b.rowid)"
		. " GROUP BY TRIM(b.label) ORDER BY uses DESC, last_used DESC, description ASC"
		. $db->plimit(5);
	$result = $db->query($sql);
	if (!$result) { throw new RuntimeException('Transfer description history is unavailable'); }
	$descriptions = array();
	while ($row = $db->fetch_object($result)) { $descriptions[] = (string) $row->description; }
	$db->free($result);
	return $descriptions;
}

/** Presentation assets only; native transfer.php retains the form and all writes. */
function kreabankTransferAssets($descriptions = array(), $descriptionError = '')
{
	global $langs;
	$langs->load('kreabank@kreabank');
	$labels = array(
		'add' => $langs->transnoentities('KreaBankTransferAdd'),
		'transfer' => $langs->transnoentities('KreaBankTransferNumber', '%s'),
		'help' => $langs->transnoentities('KreaBankTransferHelp'),
		'from' => $langs->transnoentities('KreaBankTransferFrom'),
		'to' => $langs->transnoentities('KreaBankTransferTo'),
		'descriptions' => array_slice(array_values($descriptions), 0, 5),
		'descriptionPlaceholder' => $langs->transnoentities('KreaBankTransferDescriptionPlaceholder'),
		'descriptionError' => (string) $descriptionError,
	);
	$nonce = dol_escape_htmltag(getNonce());
	return '<link rel="stylesheet" href="' . dol_escape_htmltag(dol_buildpath('/kreabank/css/transfer.css', 1)) . '?v=1.20.39">'
		. '<script type="application/json" id="kb-transfer-labels" nonce="' . $nonce . '">'
		. json_encode($labels, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . '</script>'
		. '<script src="' . dol_escape_htmltag(dol_buildpath('/kreabank/js/transfer.js', 1)) . '?v=1.20.39" nonce="' . $nonce . '"></script>';
}
