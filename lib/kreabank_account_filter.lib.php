<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/** Account-type predicates never replace the query's owner-company boundary. */
function kreabankAccountKindSql($kind, $alias = 'ba')
{
	if (!preg_match('/^[a-z][a-z0-9_]*$/iD', $alias)) {
		throw new InvalidArgumentException('Invalid account alias');
	}
	switch ($kind) {
		case 'all':
			return '';
		case 'bank':
			return ' AND ' . $alias . '.clos=0 AND ' . $alias . '.courant<>2';
		case 'cash':
			return ' AND ' . $alias . '.clos=0 AND ' . $alias . '.courant=2';
		case 'closed':
			return ' AND ' . $alias . '.clos=1';
		default:
			throw new InvalidArgumentException('Unknown account type filter');
	}
}
