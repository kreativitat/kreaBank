#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
// Check the labels used in statement views, including dynamically selected states.
$keys = array();
foreach (array('statement.php', 'lib/kreabank_integration.lib.php', 'accounts.php', 'lib/kreabank_navigation.lib.php', 'lib/kreabank.lib.php') as $file) {
	preg_match_all("/'(KreaBank[A-Za-z0-9_]+)'/", file_get_contents(__DIR__ . '/../' . $file), $matches);
	$keys = array_merge($keys, $matches[1]);
}
$checks = 0;
foreach (array('en_US', 'pt_PT') as $locale) {
	$text = file_get_contents(__DIR__ . '/../langs/' . $locale . '/kreabank.lang');
	foreach (array_unique($keys) as $key) {
		if (!preg_match('/^' . preg_quote($key, '/') . '=(.+)$/m', $text)) {
			throw new RuntimeException('Missing statement label: ' . $locale . '/' . $key);
		}
		$checks++;
	}
}
print "OK: $checks connected bank translation checks\n";
