/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later */
'use strict';
const assert = require('node:assert/strict');
const {totals} = require('../js/review-workspace.js');
assert.deepEqual(totals([]), []);
assert.deepEqual(totals([{currency:'EUR', amount:'0.1'}, {currency:'EUR', amount:'0.2'}]), [{currency:'EUR',whole:0n,fraction:'30'}]);
assert.deepEqual(totals([{currency:'EUR', amount:'125.50500000'}, {currency:'USD',amount:'10.00499999'}]), [{currency:'EUR',whole:125n,fraction:'51'}, {currency:'USD',whole:10n,fraction:'00'}]);
assert.deepEqual(totals([{currency:'EUR',amount:'9007199254740993.12'}, {currency:'EUR',amount:'0.01'}]), [{currency:'EUR',whole:9007199254740993n,fraction:'13'}]);
assert.throws(() => totals([{currency:'EUR',amount:'invalid'}]));
console.log('OK: 5 exact per-currency selection total checks');
