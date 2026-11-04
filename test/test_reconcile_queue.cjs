/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later */
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(require.resolve('../js/reconcile.js'), 'utf8');
const storage = new Map();
let checks = 0;
function boot({ids = ['11', '12', '13'], scope = '7:901:81:b.dateo:ASC', url = 'https://example.test/reconcile.php', selected = new URL(url).searchParams.get('line_id') || ids[0], blocked = false} = {}) {
    const events = () => ({addEventListener(name, handler) { this[name] = handler; }});
    let navigation;
    const queue = ids.map(id => ({dataset: {queueId: id, search: id === '12' ? 'Comissão' : 'Pagamento'}, href: `https://example.test/reconcile.php?account_context=81&line_id=${id}&sortfield=b.dateo&sortorder=ASC`, click() { navigation = this.href; }}));
    const list = {children: [...queue], appendChild(line) { this.children = this.children.filter(item => item !== line).concat(line); }};
    const next = {...events(), disabled: ids.length < 2};
    const search = {...events(), value: ''};
    const disclosure = {...events(), open: true};
    const elements = {'kb-pending-search': search, 'kb-pending-list': list, 'kb-next-line': next, 'kb-queue-empty': {}, 'kb-queue-count': {}};
    const root = {dataset: {queueScope: scope}, classList: {add() {}}, querySelectorAll(selector) { return selector === '.kb-queue-line' ? queue : []; }, querySelector(selector) {
        if (selector === '.kb-queue-disclosure') { return disclosure; }
        if (selector === '.kb-queue-line.selected') { return queue.find(line => line.dataset.queueId === selected) || null; }
        return null;
    }};
    vm.runInNewContext(source, {
        URL, document: {querySelector() { return root; }, getElementById(id) { return elements[id]; }},
        window: {location: {href: url}, matchMedia() { return {...events(), matches: false}; }, sessionStorage: {getItem(key) { if (blocked) { throw Error('Storage denied'); } return storage.get(key) || null; }, setItem(key, value) { if (blocked) { throw Error('Storage denied'); } storage.set(key, value); }}}
    });
    return {order: () => list.children.map(line => line.dataset.queueId), next: () => next.click(), search(value) { search.value = value; search.input(); }, nextButton: next, navigation: () => navigation};
}
function check(actual, expected) { assert.deepEqual(actual, expected); checks++; }
let page = boot();
page.next();
check(page.order(), ['12', '13', '11']);
check(new URL(page.navigation()).searchParams.get('line_id'), '12');
check(new URL(page.navigation()).searchParams.get('account_context'), '81');
page = boot({url: page.navigation()}); // Real navigation re-renders in server sort order; restore this tab's queue.
check(page.order(), ['12', '13', '11']);
page.next();
check(page.order(), ['13', '11', '12']);
page = boot({url: page.navigation()});
page.next();
check(page.order(), ['11', '12', '13']);
check(boot({ids: ['12', '13', '14']}).order(), ['12', '13', '14']); // Removed/new IDs.
check(boot({scope: '8:901:81:b.dateo:ASC'}).order(), ['11', '12', '13']);
check(boot({scope: '7:902:81:b.dateo:ASC'}).order(), ['11', '12', '13']);
check(boot({scope: '7:901:82:b.dateo:ASC'}).order(), ['11', '12', '13']);
check(boot({scope: '7:901:81:b.dateo:DESC'}).order(), ['11', '12', '13']);
page = boot();
page.search('pagamento');
page.next();
check(page.order(), ['13', '11', '12']); // Keep cyclic order with the visible target first.
check(new URL(page.navigation()).searchParams.get('line_id'), '13');
check(new URL(page.navigation()).searchParams.get('queue_search'), 'pagamento');
page.search('comissao');
check(page.nextButton.disabled, true);
page.search('missing');
check(page.nextButton.disabled, true);
check(boot({ids: ['11']}).nextButton.disabled, true);
check(boot({ids: []}).nextButton.disabled, true);
storage.set('kreabank:pending-order:7:901:81:b.dateo:ASC', '{invalid');
check(boot().order(), ['11', '12', '13']);
storage.set('kreabank:pending-order:7:901:81:b.dateo:ASC', JSON.stringify(['999', '13', '13', '12', '11']));
check(boot().order(), ['13', '12', '11']);
page = boot({blocked: true});
page.next();
check(page.order(), ['12', '13', '11']);
page = boot({blocked: true, url: page.navigation()});
check(page.order(), ['12', '13', '11']);
page.next();
check(new URL(page.navigation()).searchParams.get('queue_order'), '13,11,12');
check(new URL(page.navigation()).searchParams.get('action'), null);
// Manual selection, sort changes and server defaults can select a row away from
// the restored queue head. Next must follow that selected row, not the head.
storage.clear();
page = boot({selected: '12'});
page.next();
check(new URL(page.navigation()).searchParams.get('line_id'), '13');
check(page.order(), ['13', '11', '12']);
page = boot({url: page.navigation()});
check(page.order(), ['13', '11', '12']);
page.next();
check(new URL(page.navigation()).searchParams.get('line_id'), '11');
storage.set('kreabank:pending-order:7:901:81:b.dateo:ASC', JSON.stringify(['13', '11', '12']));
page = boot({selected: '11'});
page.next();
check(new URL(page.navigation()).searchParams.get('line_id'), '12');
check(page.order(), ['12', '13', '11']);
page = boot({selected: '13'});
page.search('pagamento');
page.next();
check(new URL(page.navigation()).searchParams.get('line_id'), '11');
check(page.order(), ['11', '12', '13']);
page = boot({selected: '12'}); // The selected commission is hidden by the search.
page.search('pagamento');
page.next();
check(new URL(page.navigation()).searchParams.get('line_id'), '11');
check(page.order(), ['11', '12', '13']);
page = boot({selected: '999', blocked: true}); // Removed/out-of-window selection.
page.next();
check(new URL(page.navigation()).searchParams.get('line_id'), '11');
check(new URL(page.navigation()).searchParams.get('queue_order'), '11,12,13');
console.log(`OK: ${checks} pending queue navigation checks (no ERP writes)`);
