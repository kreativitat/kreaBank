/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const source = fs.readFileSync(require('node:path').join(__dirname, '../js/lists.js'), 'utf8');
function cell(text, raw, tagName = 'TD') {
    return {tagName, textContent: text, colSpan: 1, attrs: raw === undefined ? {} : {'data-kb-sort-value': raw},
        getAttribute(name) { return this.attrs[name] ?? null; },
        setAttribute(name, value) { this.attrs[name] = value; },
        appendChild(child) { this.button = child; },
        querySelector(selector) { return selector === '.kb-sort-button' ? this.button : null; }};
}
const headings = [cell('Name', undefined, 'TH'), cell('Amount', undefined, 'TH'), cell('Date', undefined, 'TH'), cell('Actions', undefined, 'TH')];
const header = {cells: headings};
const checkbox = {checked: true, value: 'signed-pair-7'};
const rows = [
    {id: 1, cells: [cell('Account 10'), cell('1.000,10 EUR', '1000.10'), cell('01/11/2026', '2026-11-01'), cell('Open')], checkbox},
    {id: 2, cells: [cell('Account 2'), cell('-20,50 EUR', '-20.50'), cell('31/10/2026', '2026-10-31'), cell('Open')]},
    {id: 3, cells: [cell('Account 3'), cell('9,00 EUR', '9.00'), cell('02/01/2026', '2026-01-02'), cell('Open')]},
    {id: 4, cells: [cell('Account 4'), cell('9,00 EUR', '9.00'), cell('02/01/2026', '2026-01-02'), cell('Open')]},
    {id: 5, cells: [cell('Account 5'), cell('—', ''), cell('—', ''), cell('Open')]}
];
const body = {children: rows.slice(), appendChild(row) { this.children = this.children.filter(item => item !== row); this.children.push(row); }};
rows.forEach(row => { row.parentNode = body; });
const table = {rows: [header, ...rows], getAttribute(name) { return name === 'data-kb-sort-skip' ? '3' : null; }};
const document = {documentElement: {lang: 'pt-PT'}, querySelectorAll(selector) { assert.equal(selector, 'table[data-kb-sort]'); return [table]; },
    createElement(tag) { assert.equal(tag, 'button'); return {addEventListener(type, callback) { this[type] = callback; }}; }};
vm.runInNewContext(source, {document, Intl});
assert.ok(headings[0].button, 'The first data column is sortable when not skipped');
assert.equal(headings[3].button, undefined, 'Action columns remain unsortable');
assert.equal(headings[1].button.type, 'button', 'Sorting inside a form cannot submit it');
headings[1].button.click();
assert.deepEqual(body.children.map(row => row.id), [2, 3, 4, 1, 5], 'Raw numeric amounts sort correctly despite localized display text');
assert.equal(headings[1].attrs['aria-sort'], 'ascending');
headings[1].button.click();
assert.deepEqual(body.children.map(row => row.id), [1, 3, 4, 2, 5], 'Descending amounts preserve ties and keep missing values last');
headings[2].button.click();
assert.deepEqual(body.children.map(row => row.id), [3, 4, 2, 1, 5], 'ISO dates order chronologically rather than by localized day');
assert.equal(headings[1].attrs['aria-sort'], 'none', 'Only the active header announces its sort direction');
headings[0].button.click();
assert.deepEqual(body.children.map(row => row.id), [2, 3, 4, 5, 1], 'Text uses natural ordering');
assert.equal(body.children[4].checkbox, checkbox, 'Sorting moves existing rows and retains their form controls');
assert.equal(checkbox.checked, true, 'Sorting does not change a financial selection');
console.log('OK: 11 local table sorting checks');
