/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
(function () {
    'use strict';
    const config = JSON.parse(document.getElementById('krea-bulk-scan-config').textContent);
    const results = document.getElementById('krea-bulk-results');
    const status = document.getElementById('krea-bulk-scan-status');
    const resume = document.getElementById('krea-bulk-scan-resume');
    const endpoint = new URL(config.url, window.location.href);
    if (endpoint.origin !== window.location.origin) return;
    const selections = new Map();
    let more = config.more;
    let paused = !config.auto;
    let running = false;
    let timer;
    let request;
    let reloadNeeded = false;
    let submitting = false;

    function pause() {
        paused = true;
        window.clearTimeout(timer);
        status.textContent = more ? config.paused : '';
        status.hidden = !more;
        resume.hidden = !more;
    }
    function updateToggle() {
        const toggle = results.querySelector('#krea_bulk_match_all');
        const boxes = Array.from(results.querySelectorAll('.krea-bulk-match-line'));
        if (toggle && boxes.length) {
            const checked = boxes.filter(box => box.checked).length;
            toggle.checked = checked === boxes.length;
            toggle.indeterminate = checked > 0 && checked < boxes.length;
        }
    }
    results.addEventListener('change', function (event) {
        if (event.target.id === 'krea_bulk_match_all') {
            results.querySelectorAll('.krea-bulk-match-line').forEach(function (box) {
                box.checked = event.target.checked;
                selections.set(box.value, box.checked);
            });
        } else if (event.target.matches('.krea-bulk-match-line')) {
            selections.set(event.target.value, event.target.checked);
        } else if (event.target.matches('[name="review_confirmed"]')) {
            pause();
            return;
        } else return;
        const approval = results.querySelector('[name="review_confirmed"]');
        if (approval) approval.checked = false;
        updateToggle();
        pause();
    });
    results.addEventListener('submit', function (event) {
        pause();
        if (submitting) {
            event.preventDefault();
            return;
        }
        submitting = true;
        if (request) request.abort();
        results.querySelectorAll('button[type="submit"]').forEach(button => { button.disabled = true; });
    });

    async function scan(manual) {
        if (running || !more || (paused && !manual)) return;
        paused = false;
        running = true;
        resume.disabled = true;
        status.textContent = config.loading;
        status.setAttribute('aria-busy', 'true');
        request = new AbortController();
        try {
            const body = new URLSearchParams({action: 'bulk_scan', token: config.token,
                account_context: config.account, page: config.page, limit: config.limit,
                sortfield: config.sortfield || 'operation_date', sortorder: config.sortorder || 'ASC',
                resume: manual ? '1' : '0'});
            const response = await fetch(endpoint, {method: 'POST', credentials: 'same-origin',
                headers: {'Content-Type': 'application/x-www-form-urlencoded', Accept: 'application/json'},
                body: body.toString(), signal: request.signal});
            const data = await response.json();
            if (!response.ok) {
                reloadNeeded = response.status === 409;
                const failure = new Error(config.failed);
                failure.userMessage = data.error || config.failed;
                throw failure;
            }
            if (typeof data.redirect === 'string') {
                const target = new URL(data.redirect, window.location.href);
                if (target.origin !== endpoint.origin) throw new Error(config.failed);
                window.location.assign(target.href);
                return;
            }
            if (typeof data.html !== 'string' || typeof data.more !== 'boolean') throw new Error(config.failed);
            config.token = data.token;
            more = data.more;
            if (!paused) {
                results.innerHTML = data.html;
                results.querySelectorAll('.krea-bulk-match-line').forEach(function (box) {
                    if (selections.has(box.value)) box.checked = selections.get(box.value);
                });
                updateToggle();
            }
            status.textContent = '';
            status.hidden = !more;
            if (paused || (more && !data.auto)) pause();
            else if (more) timer = window.setTimeout(function () { scan(false); }, 300);
            resume.hidden = !more || !paused;
        } catch (error) {
            if (error.name !== 'AbortError') {
                pause();
                status.textContent = error.userMessage || config.failed;
                if (reloadNeeded) resume.textContent = config.refresh;
            }
        } finally {
            running = false;
            resume.disabled = false;
            status.removeAttribute('aria-busy');
        }
    }
    resume.addEventListener('click', function () {
        if (reloadNeeded) window.location.reload();
        else scan(true);
    });
    window.addEventListener('pagehide', function () {
        window.clearTimeout(timer);
        if (request) request.abort();
    });
    updateToggle();
    if (more && !paused) scan(false);
    else pause();
}());
