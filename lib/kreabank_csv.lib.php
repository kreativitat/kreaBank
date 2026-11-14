<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/** Keep exact decimal amounts while neutralizing spreadsheet formulas in bank evidence. */
function kreabankCsvCell($value)
{
	$value = (string) $value;
	return !preg_match('/^-?[0-9]+(?:\.[0-9]+)?$/D', $value) && preg_match('/^[\s\x00-\x1f]*[=+@-]/', $value) ? "'" . $value : $value;
}
