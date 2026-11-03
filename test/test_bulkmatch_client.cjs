/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(require('node:path').join(__dirname, '../js/bulkmatch.js'), 'utf8');
const tick = () => new Promise(resolve => setImmediate(resolve));
let checks = 0;
function check(condition, message) { assert.ok(condition, message); checks++; }
function harness(overrides = {}) {
    const config = {url: '/custom/kreabank/bulkmatch.php', token: 'fixture', account: 101,
        page: 0, limit: 25, more: true, auto: true, loading: 'Loading', paused: 'Paused', failed: 'Failed', refresh: 'Refresh', ...overrides};
    const calls = [];
    const timers = new Map();
    const events = {};
    const listeners = {};
    const windowEvents = {};
    const toggle = {};
    const approval = {checked: false, matches: selector => selector === '[name="review_confirmed"]'};
    const submit = {disabled: false};
    let reviewRefreshes = 0;
    let boxes = [{value: '1', checked: false, matches: selector => selector === '.krea-bulk-match-line'}];
    let html = 'original';
    let replacements = 0;
    const progress = {innerHTML: ''};
    const status = {setAttribute() {}, removeAttribute() {}};
    const resume = {addEventListener(name, fn) { listeners[name] = fn; }};
    const results = {addEventListener(name, fn) { events[name] = fn; },
        querySelector(selector) { return selector === '[name="review_confirmed"]' ? approval : toggle; },
        querySelectorAll(selector) { return selector === 'button[type="submit"]' ? [submit] : boxes; },
        get innerHTML() { return html; }, set innerHTML(value) {
            replacements++;
            html = value;
            boxes = JSON.parse(value).map(box => ({...box, matches: selector => selector === '.krea-bulk-match-line'}));
            approval.checked = false;
        }};
    let reloads = 0;
    const redirects = [];
    const window = {location: {href: 'https://erp.example.test/custom/kreabank/bulkmatch.php', origin: 'https://erp.example.test', reload() { reloads++; }, assign(url) { redirects.push(url); }},
        kreabankReviewRefresh() { reviewRefreshes++; },
        setTimeout(fn) { const id = Symbol(); timers.set(id, fn); return id; }, clearTimeout(id) { timers.delete(id); },
        addEventListener(name, fn) { windowEvents[name] = fn; }};
    const elements = {'krea-bulk-scan-config': {textContent: JSON.stringify(config)}, 'krea-bulk-results': results, 'krea-bulk-progress': progress,
        'krea-bulk-scan-status': status, 'krea-bulk-scan-resume': resume};
    const fetch = (url, options) => new Promise(resolve => calls.push({url, options, resolve}));
    vm.runInNewContext(source, {document: {getElementById: id => elements[id]}, window, URL, URLSearchParams, AbortController, fetch});
    return {calls, timers, events, listeners, windowEvents, results, status, resume, approval, submit,
        redirects, progress, reviewRefreshes: () => reviewRefreshes, replacements: () => replacements, boxes: () => boxes, reloads: () => reloads,
        respond(index, data, ok = true, status = 200) { calls[index].resolve({ok, status, json: async () => data}); },
        runTimer() { const [id, fn] = timers.entries().next().value; timers.delete(id); fn(); }};
}
(async function () {
    const h = harness();
    check(h.calls.length === 1 && h.timers.size === 0, 'Only one scan starts; an unfinished request never overlaps another');
    const posted = new URLSearchParams(h.calls[0].options.body);
    check(posted.get('action') === 'bulk_scan' && posted.get('token') === 'fixture' && posted.get('account_context') === '101', 'Background requests carry only read-only scan action, token and explicit account context');
    h.respond(0, {html: JSON.stringify([{value: '1', checked: false}]), more: true, auto: true, token: 'next'});
    await tick();
    check(h.calls.length === 1 && h.timers.size === 1, 'A subsequent batch waits for the previous response');
    h.runTimer();
    const selected = h.boxes()[0]; selected.checked = true;
    h.events.change({target: selected});
    h.respond(1, {html: JSON.stringify([{value: '1', checked: false}]), more: true, auto: true, token: 'next'});
    await tick();
    check(h.boxes()[0] === selected && selected.checked && h.timers.size === 0, 'Review pauses pending updates without replacing the user selection');
    h.listeners.click();
    check(new URLSearchParams(h.calls[2].options.body).get('resume') === '1', 'The user can explicitly resume after pausing');
    h.respond(2, {html: JSON.stringify([{value: '1', checked: false}, {value: '2', checked: true}]), more: false, auto: false, token: 'next'});
    await tick();
    check(h.boxes()[0].checked && h.boxes()[1].checked && h.resume.hidden && h.timers.size === 0, 'Resuming preserves saved selections and a completed scan stops polling');
    const conflict = harness();
    conflict.respond(0, {error: 'Context changed'}, false, 409);
    await tick();
    check(conflict.status.textContent === 'Context changed' && conflict.timers.size === 0, 'A concurrent context change stops requests visibly');
    conflict.listeners.click();
    check(conflict.reloads() === 1 && conflict.calls.length === 1, 'A stale context requires an explicit page refresh instead of a blind retry');
    const failed = harness();
    failed.calls[0].resolve({ok: false, status: 500, json: async () => { throw new Error('private parser diagnostic'); }});
    await tick();
    check(failed.status.textContent === 'Failed' && !failed.resume.hidden, 'Unexpected errors show a generic retry state without technical details');
    const leaving = harness();
    leaving.windowEvents.pagehide();
    check(leaving.calls[0].options.signal.aborted, 'Leaving the page cancels its outstanding browser request');
    const reviewed = harness();
    reviewed.approval.checked = true;
    reviewed.events.change({target: reviewed.approval});
    reviewed.respond(0, {html: JSON.stringify([{value: '2', checked: false}]), more: true, auto: true, token: 'next'});
    await tick();
    check(reviewed.approval.checked && reviewed.boxes()[0].value === '1', 'Approving the displayed pairs pauses in-flight presentation updates');
    reviewed.boxes()[0].checked = true;
    reviewed.events.change({target: reviewed.boxes()[0]});
    check(!reviewed.approval.checked, 'Changing a selection requires approving the new selected set');
    check(reviewed.reviewRefreshes() >= 3, 'Initial selection, approval and changes refresh the visible selection summary');
    reviewed.events.submit({defaultPrevented: true});
    check(!reviewed.submit.disabled, 'A presentation gate cancellation must not mark the form submitted or block a later valid approval');
    reviewed.approval.checked = true;
    reviewed.events.submit({preventDefault() { throw new Error('First reviewed submit must proceed'); }});
    check(reviewed.submit.disabled && reviewed.calls[0].options.signal.aborted, 'Submitting disables the button and stops background work');
    let prevented = false;
    reviewed.events.submit({preventDefault() { prevented = true; }});
    check(prevented, 'A second submit is blocked while the first is pending');
    const single = harness({url: '/custom/kreabank/matched.php', limit: 1, page: 2});
    const singlePost = new URLSearchParams(single.calls[0].options.body);
    check(single.calls[0].url.pathname === '/custom/kreabank/matched.php' && singlePost.get('limit') === '1' && singlePost.get('page') === '2', 'Individual matched scanning preserves its endpoint and one-pair page');
    single.respond(0, {redirect: '/custom/kreabank/matched.php?account_context=101'});
    await tick();
    check(single.redirects[0] === 'https://erp.example.test/custom/kreabank/matched.php?account_context=101', 'A removed last match returns to the remaining queue');
    const foreign = harness();
    foreign.respond(0, {redirect: 'https://other.example.test/private'});
    await tick();
    check(!foreign.redirects.length && foreign.status.textContent === 'Failed', 'Scan redirects cannot leave the ERP origin');
    const stable = harness({presentation: 'loading'});
    const focused = stable.boxes()[0];
    stable.respond(0, {html: JSON.stringify([{value: '2', checked: false}]), progressHtml: '20%', presentation: 'loading', more: true, auto: true});
    await tick();
    check(stable.replacements() === 0 && stable.boxes()[0] === focused && stable.progress.innerHTML === '20%', 'Progress alone never rebuilds the review panel or moves focus');
    stable.runTimer();
    stable.respond(1, {html: JSON.stringify([{value: '2', checked: false}]), progressHtml: 'Complete', presentation: 'ready', more: false, auto: false});
    await tick();
    check(stable.replacements() === 1 && stable.boxes()[0].value === '2' && stable.timers.size === 0, 'A completed search displays its results once and stops updating');
    check(stable.reloads() === 0 && stable.redirects.length === 0, 'Normal progress and completion never reload or navigate the page');
    console.log(`OK: ${checks} bulk review browser behavior checks`);
}()).catch(error => { console.error(error); process.exitCode = 1; });
