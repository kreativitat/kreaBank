#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
require_once __DIR__ . '/../class/KreaBankMatcher.class.php';
$cases = array(
 'FECHO TPA 0001234567 PERIODO N 007' => '1234567/7',
 'fecho tpa 01234567 período n 7 SHOP' => '1234567/7',
 'FECHO TPA 0123456/0001234567/007 SHOP' => '123456/1234567/7',
 'FECHO TPA 0123456/0001234567/007' => '123456/1234567/7',
 'TPA' => '', 'TPA 1234567890' => '', 'FECHO TPA 1234567890' => '',
 'FECHO TPA 123456/1234567' => '', 'FECHO TPA 1234567 PERIODO N 7X' => '',
 'COMISSAO FECHO TPA 1234567 PERIODO N 7' => '',
 'FECHO TPA 1234567 PERIODO N 7 ESTORNO' => '',
 'FECHO TPA 1234567 PERIODO N 7 DEVOLUÇÃO' => '',
 'FECHO TPA 1234567 PERIODO N 7 COMISSÃO' => '',
 'TRANSFER TPA 1234567 PERIODO N 7' => '',
);
foreach ($cases as $description => $expected) {
 if (KreaBankMatcher::tpaSettlementIdentity($description) !== $expected) { throw new RuntimeException('Terminal identity classification failed'); }
}
echo 'OK: ' . count($cases) . " TPA identity checks\n";
