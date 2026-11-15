<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>; GPL-3.0-or-later */

/** Presentation assets only; native transfer.php retains the form and all writes. */
function kreabankTransferAssets()
{
	global $langs;
	$langs->load('kreabank@kreabank');
	$labels = array(
		'add' => $langs->trans('KreaBankTransferAdd'),
		'transfer' => $langs->trans('KreaBankTransferNumber'),
		'help' => $langs->trans('KreaBankTransferHelp'),
		'from' => $langs->trans('KreaBankTransferFrom'),
		'to' => $langs->trans('KreaBankTransferTo'),
	);
	$nonce = dol_escape_htmltag(getNonce());
	return '<link rel="stylesheet" href="' . dol_escape_htmltag(dol_buildpath('/kreabank/css/transfer.css', 1)) . '?v=1.20.32">'
		. '<script type="application/json" id="kb-transfer-labels" nonce="' . $nonce . '">'
		. json_encode($labels, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . '</script>'
		. '<script src="' . dol_escape_htmltag(dol_buildpath('/kreabank/js/transfer.js', 1)) . '?v=1.20.32" nonce="' . $nonce . '"></script>';
}
