/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later */
(function () {
    'use strict';
    const SCALE = 100000000n;

    /** Persisted decimal8 strings stay exact; no POST amount is rewritten. */
    function decimal8(value) {
        const match = String(value).trim().match(/^([+-]?)(\d+)(?:\.(\d{1,8}))?$/);
        if (!match) throw new TypeError('Invalid decimal amount');
        const units = BigInt(match[2]) * SCALE + BigInt((match[3] || '').padEnd(8, '0'));
        return match[1] === '-' ? -units : units;
    }

    function allocation(target, amounts) {
        const targetUnits = decimal8(target);
        const units = amounts.map(decimal8);
        const total = units.reduce((sum, amount) => sum + amount, 0n);
        const difference = targetUnits - total;
        const sameDirection = targetUnits !== 0n && units.every(amount => amount !== 0n && (amount > 0n) === (targetUnits > 0n));
        return {total, difference, count:units.length, ready:units.length >= 2 && sameDirection && difference === 0n};
    }

    function money(units, currency, locale, nativeFormat) {
        const negative = units < 0n;
        const absolute = negative ? -units : units;
        const cents = (absolute + 500000n) / 1000000n;
        const integer = cents / 100n;
        const fraction = String(cents % 100n).padStart(2, '0');
        const formatter = new Intl.NumberFormat(locale || 'en-US', {maximumFractionDigits:0});
        const separator = nativeFormat ? nativeFormat.decimalSeparator : new Intl.NumberFormat(locale || 'en-US').formatToParts(1.1).find(part => part.type === 'decimal').value;
        const grouped = nativeFormat ? String(integer).replace(/\B(?=(\d{3})+(?!\d))/g, () => nativeFormat.groupSeparator) : formatter.format(integer);
        return (negative && cents !== 0n ? '−' : '') + grouped + separator + fraction + (currency ? ' ' + currency : '');
    }

    function exactAmount(units) {
        const value = units < 0n ? -units : units;
        return (units < 0n ? '-' : '') + String(value / SCALE) + '.' + String(value % SCALE).padStart(8, '0');
    }

    function initialise(root) {
        const locale = root.dataset.locale || 'en-US';
        const nativeFormat = root.hasAttribute('data-group-separator') && root.hasAttribute('data-decimal-separator')
            ? {groupSeparator:root.dataset.groupSeparator, decimalSeparator:root.dataset.decimalSeparator} : null;
        root.querySelectorAll('[data-kb-batch]').forEach(batch => {
            const update = () => {
                const checked = [...batch.querySelectorAll('[data-kb-member]:checked:not(:disabled)')];
                let state;
                try { state = allocation(batch.dataset.target, checked.map(input => input.dataset.amount)); }
                catch (_) { state = null; }
                const selected = batch.querySelector('[data-kb-selected-total]');
                const difference = batch.querySelector('[data-kb-difference]');
                selected.textContent = state ? money(state.total, batch.dataset.currency, locale, nativeFormat) : '—';
                difference.textContent = state ? money(state.difference, batch.dataset.currency, locale, nativeFormat) : '—';
                selected.title = state ? exactAmount(state.total) + ' ' + batch.dataset.currency : '';
                difference.title = state ? exactAmount(state.difference) + ' ' + batch.dataset.currency : '';
                const count = batch.querySelector('[data-kb-selected-count]');
                count.textContent = count.dataset.label + ': ' + String(checked.length);
                batch.querySelector('[data-kb-batch-feedback]').textContent = state && state.ready ? batch.dataset.ready : batch.dataset.incomplete;
                batch.querySelectorAll('[data-kb-batch-review]').forEach(button => { button.disabled = !state || !state.ready; });
            };
            batch.addEventListener('change', update);
            update();
        });
        root.querySelectorAll('[data-kb-dialog]').forEach(opener => {
            const dialog = document.getElementById(opener.dataset.kbDialog);
            if (!dialog || typeof dialog.showModal !== 'function') { opener.disabled = true; return; }
            opener.addEventListener('click', () => { dialog.__kbOpener = opener; dialog.showModal(); });
        });
        root.querySelectorAll('.kb-wl-dialog').forEach(dialog => {
            dialog.querySelectorAll('[data-kb-dialog-close]').forEach(button => button.addEventListener('click', () => dialog.close()));
            dialog.addEventListener('keydown', event => {
                if (event.key !== 'Tab') return;
                const controls = [...dialog.querySelectorAll('button:not(:disabled),input:not(:disabled):not([type="hidden"]),select:not(:disabled),a[href]')];
                const first = controls[0];
                const last = controls[controls.length - 1];
                if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
                else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
            });
            dialog.addEventListener('close', () => { if (dialog.__kbOpener && dialog.__kbOpener.isConnected) dialog.__kbOpener.focus(); });
        });
        root.querySelectorAll('form[method="post"]').forEach(form => {
            form.addEventListener('submit', event => {
                if (form.dataset.kbSubmitting === 'true') { event.preventDefault(); return; }
                form.dataset.kbSubmitting = 'true';
                // Preserve the successful submitter's name/value in the actual native POST.
                form.setAttribute('aria-busy', 'true');
                setTimeout(() => {
                    if (event.defaultPrevented) { delete form.dataset.kbSubmitting; form.removeAttribute('aria-busy'); }
                }, 0);
            });
        });
        const scanForm = root.querySelector('form[data-kb-scan]');
        const scan = root.querySelector('.kb-wl-scan');
        if (scanForm && scan && scanForm.elements.action && scanForm.elements.action.value === 'advance_scan' && typeof fetch === 'function') {
            let requests = 0;
            let paused = false;
            let running = false;
            let timer;
            let request;
            const editing = () => paused || !!root.querySelector('.kb-wl-detail-row');
            const message = text => { scan.querySelector('[data-kb-scan-message]').textContent = text; };
            const advance = async () => {
                if (running || editing() || requests >= 30) return;
                running = true;
                requests++;
                request = new AbortController();
                try {
                    const response = await fetch(scanForm.getAttribute('action'), {method:'POST', body:new FormData(scanForm), credentials:'same-origin', headers:{'X-KreaBank-Scan':'1'}, signal:request.signal});
                    if (!response.ok && response.status !== 503) throw new Error(response.status === 409 ? 'changed' : 'failed');
                    const result = await response.json();
                    const generation = scanForm.elements.scan_generation;
                    const cursor = scanForm.elements.scan_cursor;
                    if (!generation || !cursor || result.generation !== generation.value || !Number.isInteger(result.cursor) || result.cursor < Number(cursor.value)
                        || !Number.isInteger(result.processed) || !Number.isInteger(result.total) || result.processed < 0 || result.total < result.processed || typeof result.done !== 'boolean') {
                        throw new Error('changed');
                    }
                    cursor.value = String(result.cursor);
                    scan.querySelector('[data-kb-scan-count]').textContent = result.processed + ' / ' + result.total;
                    const progress = scan.querySelector('[data-kb-scan-progress]');
                    if (progress) { progress.max = Math.max(1, result.total); progress.value = result.processed; }
                    if (!response.ok) { message(scan.dataset.failed); return; }
                    if (result.page_ready === true && scan.dataset.pageReady !== '1') {
                        scan.dataset.pageReady = '1';
                        if (!editing()) window.location.reload();
                        return;
                    }
                    if (result.done) {
                        message(scan.dataset.ready);
                        if (!editing()) window.location.reload();
                        return;
                    }
                    if (!editing() && requests < 30) timer = setTimeout(advance, 600);
                    else if (requests >= 30) message(scan.dataset.paused);
                } catch (error) {
                    if (error.name !== 'AbortError') message(error.message === 'changed' ? scan.dataset.changed : scan.dataset.failed);
                } finally { running = false; }
            };
            const pause = () => { paused = true; clearTimeout(timer); message(scan.dataset.paused); };
            root.addEventListener('input', pause);
            root.addEventListener('change', pause);
            scanForm.addEventListener('submit', event => {
                event.preventDefault();
                if (root.querySelector('.kb-wl-detail-row')) { message(scan.dataset.paused); return; }
                paused = false;
                requests = 0;
                clearTimeout(timer);
                advance();
            });
            window.addEventListener('pagehide', () => { clearTimeout(timer); if (request) request.abort(); }, {once:true});
            if (!editing() && scan.dataset.pageReady !== '1') timer = setTimeout(advance, 200);
            else if (editing()) message(scan.dataset.paused);
        }
    }

    if (typeof module !== 'undefined' && module.exports) module.exports = {decimal8, allocation, money, initialise};
    if (typeof document !== 'undefined') {
        const start = () => document.querySelectorAll('.kb-worklist').forEach(initialise);
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
        window.addEventListener('pageshow', event => {
            if (event.persisted) document.querySelectorAll('.kb-worklist form[data-kb-submitting]').forEach(form => { delete form.dataset.kbSubmitting; form.removeAttribute('aria-busy'); });
        });
    }
}());
