<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

// Use the existing bounded scan, signed review and reconciliation controller.
define('KREABANK_MATCHED_REVIEW', true);
require __DIR__ . '/bulkmatch.php';
