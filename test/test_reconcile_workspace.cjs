/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later */
'use strict';
const assert = require('node:assert/strict');
const {allocation} = require('../js/reconcile.js');
let checks = 0;
function check(target, amounts, ready, difference) {
    const state = allocation(target, amounts);
    assert.equal(state.ready, ready);
    assert.ok(Math.abs(state.difference - difference) < 1e-12);
    checks++;
}
check('125.50', [], false, 125.5);
check('125.50', ['75.25'], false, 50.25);
check('125.50', ['75.25', '50.25'], true, 0);
check('125.50', ['125.51'], true, -0.01);
check('125.50', ['125.510001'], false, -0.010001);
check('125.50', ['125.489999'], false, 0.010001);
check('0.30', ['0.1', '0.2'], true, 0);
check('100.000001', ['50.0000005', '50.0000005'], true, 0);
check('100.000001', ['100.010002'], false, -0.010001);
check('125.50', ['125.50', '125.50'], false, -125.5);
check('0', ['0'], false, 0);
assert.throws(() => allocation('125.50', ['invalid'])); checks++;
console.log(`OK: ${checks} exact allocation feedback checks`);
