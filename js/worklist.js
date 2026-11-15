/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later */
(function () {
    'use strict';
    const SCALE = 100000000n;
    const POSITION_STORAGE_KEY = 'kreabank.worklist.position.v1';

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
        return {total, difference, count:units.length, ready:units.length >= 1 && sameDirection && difference === 0n};
    }

    function selectionReady(target, members, maxMembers = 500) {
        const state = allocation(target, members.map(member => member.amount));
        const eligible = members.length === 1 ? members[0].singleEligible !== false
            : members.length <= maxMembers && members.every(member => member.groupEligible !== false);
        return state.ready && eligible;
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

    /** One-use viewport state contains navigation coordinates, never form/review data. */
    function positionNavigation(root) {
        const storageKey = POSITION_STORAGE_KEY;
        const scope = root.dataset.navigationScope;
        const context = root.dataset.navigationContext;
        const page = Number(root.dataset.navigationPage);
        const reload = () => window.location.reload();
        const samePage = value => {
            try {
                const url = new URL(value, window.location.href);
                return !url.username && !url.password && url.origin === window.location.origin && url.pathname === window.location.pathname;
            } catch (_) { return false; }
        };
        const navigate = (value, encoded = '') => {
            if (!samePage(value)) return false;
            if (encoded) {
                const record = JSON.parse(encoded);
                record.time = Date.now();
                try { window.sessionStorage.setItem(storageKey, JSON.stringify(record)); } catch (_) {}
            }
            window.location.assign(new URL(value, window.location.href).href);
            return true;
        };
        if (!scope || !context || !Number.isInteger(page) || page < 0) return {reload, capture:() => value => navigate(value)};
        const rows = () => [...root.querySelectorAll('.kb-wl-row[data-line-key]')];
        const clear = () => { try { window.sessionStorage.removeItem(storageKey); } catch (_) {} };
        const forgetCancelled = (event, encoded) => setTimeout(() => {
            if (!event.defaultPrevented) return;
            try { if (window.sessionStorage.getItem(storageKey) === encoded) clear(); } catch (_) {}
        }, 0);
        const stickyTop = () => {
            let bottom = 0;
            document.querySelectorAll('#id-top, #id-top1, .kb-wl-table thead, .kb-wl-table th').forEach(element => {
                const rect = element.getBoundingClientRect();
                if (['fixed','sticky'].includes(window.getComputedStyle(element).position) && rect.top <= bottom + 1 && rect.bottom > bottom) bottom = rect.bottom;
            });
            return bottom + 8;
        };
        const owner = element => {
            if (!element || typeof element.closest !== 'function') return null;
            const holder = element.closest('[data-line-key]');
            if (holder && root.contains(holder)) return rows().find(row => row.dataset.lineKey === holder.dataset.lineKey) || null;
            const dialog = element.closest('dialog');
            return dialog && dialog.__kbOpener ? owner(dialog.__kbOpener) : null;
        };
        let keyboard = false;
        document.addEventListener('keydown', () => { keyboard = true; }, true);
        document.addEventListener('pointerdown', () => { keyboard = false; }, true);
        const save = (row, kind, focused, withFocus, persist = true) => {
            if (!row) return '';
            const wrapper = row.closest('.div-table-responsive');
            const record = {scope, context, page, row:row.dataset.lineKey, index:rows().indexOf(row),
                offset:Math.max(stickyTop(), Math.min(window.innerHeight - 48, row.getBoundingClientRect().top)),
                left:wrapper ? wrapper.scrollLeft : 0, time:Date.now(), kind, keyboard:!!withFocus,
                focusId:focused && owner(focused) === row ? focused.id.slice(0,160) : ''};
            const encoded = JSON.stringify(record);
            if (!persist) return encoded;
            try { window.sessionStorage.setItem(storageKey, encoded); } catch (_) { return ''; }
            return encoded;
        };
        document.addEventListener('click', event => {
            const link = event.target.closest && event.target.closest('a[href]');
            if (!link || event.defaultPrevented) return;
            if (!event.ctrlKey && !event.metaKey && !event.shiftKey && !event.altKey && event.button === 0
                && root.contains(link) && link.dataset.kbRowNavigation && samePage(link.href) && (!link.target || link.target === '_self')) {
                forgetCancelled(event, save(owner(link), 'row', link, event.detail === 0 || keyboard));
            } else { clear(); }
        });
        root.addEventListener('submit', event => {
            const form = event.target;
            if (event.defaultPrevented || !samePage(form.getAttribute('action'))) return;
            if (form.id === 'searchFormList') { clear(); return; }
            let row = owner(event.submitter) || owner(document.activeElement);
            if (!row) {
                for (const control of form.elements) { row = owner(control); if (row) break; }
            }
            if (!row) { clear(); return; }
            forgetCancelled(event, save(row, 'submit', document.activeElement, keyboard));
        });
        let pending;
        try { pending = JSON.parse(window.sessionStorage.getItem(storageKey)); } catch (_) {}
        clear();
        if (pending && pending.scope === scope && pending.context === context && Date.now() - pending.time >= 0 && Date.now() - pending.time < 120000
            && /^(staged|native):[1-9]\d*$/.test(pending.row) && Number.isInteger(pending.index) && pending.index >= 0 && pending.index < 1000
            && Number.isInteger(pending.page) && pending.page >= 0 && Number.isFinite(pending.offset) && Number.isFinite(pending.left) && pending.left >= 0) {
            const available = rows();
            const exact = available.find(row => row.dataset.lineKey === pending.row);
            const relatedPage = pending.page === page || (exact && pending.kind !== 'refresh') || (page === 0 && ['submit','popup'].includes(pending.kind) && !exact);
            const row = relatedPage ? exact || available[Math.min(pending.index, available.length - 1)] : null;
            if (row) {
                let cancelled = false;
                let focused = false;
                const cancel = () => { cancelled = true; };
                ['wheel','touchstart','pointerdown','keydown'].forEach(name => document.addEventListener(name, cancel, {once:true, passive:true}));
                const restore = () => {
                    if (cancelled || !row.isConnected) return;
                    const wrapper = row.closest('.div-table-responsive');
                    if (wrapper) wrapper.scrollLeft = pending.left;
                    const offset = Math.max(stickyTop(), Math.min(window.innerHeight - 48, pending.offset));
                    window.scrollTo(window.scrollX, Math.max(0, window.scrollY + row.getBoundingClientRect().top - offset));
                    if (pending.keyboard && !focused) {
                        let previous = typeof pending.focusId === 'string' ? document.getElementById(pending.focusId) : null;
                        if (previous && previous.matches('[data-kb-payment-popup]')) previous = previous.querySelector('a.kb-wl-record-payment');
                        const target = previous && owner(previous) === row ? previous : row;
                        if (target === row) row.tabIndex = -1;
                        target.focus({preventScroll:true});
                        focused = true;
                    }
                };
                const schedule = () => window.requestAnimationFrame(() => window.requestAnimationFrame(restore));
                schedule();
                if (document.readyState !== 'complete') window.addEventListener('load', schedule, {once:true});
            }
        }
        return {capture:(element, focused, withFocus) => {
            const target = focused && focused.id ? focused : element;
            const encoded = save(owner(element), 'popup', target, withFocus, false);
            return value => navigate(value, encoded);
        }, reload:() => {
            const top = stickyTop();
            const visible = rows().filter(item => { const rect = item.getBoundingClientRect(); return rect.bottom > top && rect.top < window.innerHeight; });
            const row = visible.find(item => item.getBoundingClientRect().top >= top) || visible[0];
            save(row, 'refresh', null, false);
            reload();
        }};
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
                const ready = !!state && selectionReady(batch.dataset.target, checked.map(input => ({amount:input.dataset.amount, singleEligible:input.dataset.singleEligible !== '0', groupEligible:input.dataset.groupEligible !== '0'})), Number(batch.dataset.maxMembers || 500));
                const selected = batch.querySelector('[data-kb-selected-total]');
                const difference = batch.querySelector('[data-kb-difference]');
                selected.textContent = state ? money(state.total, batch.dataset.currency, locale, nativeFormat) : '—';
                difference.textContent = state ? money(state.difference, batch.dataset.currency, locale, nativeFormat) : '—';
                selected.title = state ? exactAmount(state.total) + ' ' + batch.dataset.currency : '';
                difference.title = state ? exactAmount(state.difference) + ' ' + batch.dataset.currency : '';
                const count = batch.querySelector('[data-kb-selected-count]');
                count.textContent = count.dataset.label + ': ' + String(checked.length);
                batch.querySelector('[data-kb-batch-feedback]').textContent = ready ? batch.dataset.ready : batch.dataset.incomplete;
                batch.querySelectorAll('[data-kb-batch-review]').forEach(button => { button.disabled = !ready; });
                const detail = batch.closest('.kb-wl-detail');
                const groupName = detail && detail.querySelector('[data-kb-group-name]');
                if (groupName) groupName.hidden = checked.length < 2;
                batch.querySelectorAll('[data-kb-save-draft]').forEach(button => { button.hidden = checked.length === 1 || Number(batch.dataset.maxMembers || 500) === 1; });
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
        const navigation = positionNavigation(root);
        let paymentRefreshPending = false;
        let pausePaymentAnalysis = () => {};
        const paymentReturns = new WeakMap();
        root.addEventListener('click', event => {
            const opener = event.target.closest && event.target.closest('a.kb-wl-record-payment');
            const popup = opener && opener.closest('[data-kb-payment-popup]');
            if (!popup || !root.contains(popup) || !/^(staged|native):[1-9]\d*$/.test(popup.dataset.lineKey)) return;
            const state = {refresh:navigation.capture(popup, opener, true), submitted:false, submissions:[], opener, cleanup:[], documents:new WeakSet()};
            paymentReturns.set(popup, state);
            paymentRefreshPending = true;
            pausePaymentAnalysis();
            // Native click handlers create the iframe after capture; trusted events can
            // run a microtask checkpoint between listeners, so bind in the next task.
            const bindingTimer = setTimeout(() => {
                const frame = document.getElementById(popup.dataset.kbPaymentFrame);
                if (!frame || paymentReturns.get(popup) !== state) return;
                const watch = () => {
                    let doc;
                    try { doc = frame.contentDocument; } catch (_) { return; }
                    if (!doc || state.documents.has(doc)) return;
                    state.documents.add(doc);
                    const paymentForm = form => form && String(form.method).toLowerCase() === 'post'
                        && form.elements.action && ['add_paiement','confirm_paiement','add_payment'].includes(form.elements.action.value);
                    const cancel = event => {
                        event.preventDefault();
                        const name = /^iframedialog(kbwlpay[a-f0-9]{16})$/.exec(frame.id);
                        const dialog = name && document.getElementById('idfordialog' + name[1]);
                        if (dialog && dialog.contains(frame) && typeof window.jQuery === 'function') {
                            const nativeDialog = window.jQuery(dialog);
                            if (nativeDialog && typeof nativeDialog.dialog === 'function') nativeDialog.dialog('close');
                        }
                    };
                    const clicked = event => {
                        const control = event.target.closest && event.target.closest('input[name="cancel"],button[name="cancel"]');
                        // Cancel must close even when HTML required fields would block submit.
                        if (control && control.type === 'submit' && paymentForm(control.form)) cancel(event);
                    };
                    const submitted = event => {
                        const form = event.target;
                        if (!paymentForm(form)) return;
                        if (event.submitter && event.submitter.name === 'cancel') {
                            // Native supplier Cancel is a submit input; keep it out of the POST lifecycle.
                            cancel(event);
                            // A prior genuine write attempt still needs its single row refresh on close.
                            return;
                        }
                        state.submissions.push(event);
                        state.submitted = true;
                    };
                    doc.addEventListener('click', clicked, true);
                    doc.addEventListener('submit', submitted, true);
                    state.cleanup.push(() => doc.removeEventListener('click', clicked, true));
                    state.cleanup.push(() => doc.removeEventListener('submit', submitted, true));
                };
                frame.addEventListener('load', watch);
                state.cleanup.push(() => frame.removeEventListener('load', watch));
                watch();
            }, 0);
            state.cleanup.push(() => clearTimeout(bindingTimer));
        }, true);
        root.addEventListener('kb-worklist-payment-close', event => {
            const popup = event.target.closest && event.target.closest('[data-kb-payment-popup]');
            const state = popup && paymentReturns.get(popup);
            if (!state || !root.contains(popup)) return;
            paymentReturns.delete(popup);
            state.cleanup.forEach(cleanup => cleanup());
            paymentRefreshPending = false;
            pausePaymentAnalysis();
            if (state.opener.isConnected) state.opener.focus({preventScroll:true});
            // Read final native validation flags at close; prevented events must never
            // erase another genuine attempt, regardless of capture/bubble ordering.
            state.submitted = state.submissions.some(submission => !submission.defaultPrevented);
            // X, Escape and native Cancel return to the unchanged list. No request or scan.
            if (!state.submitted) return;
            let url;
            try { url = new URL(popup.dataset.kbPaymentRefresh, window.location.href); } catch (_) { return; }
            if (url.origin !== window.location.origin || url.pathname !== window.location.pathname || url.username || url.password
                || url.searchParams.get('refresh_payment') !== popup.dataset.lineKey || url.searchParams.has('action')) return;
            // A native POST attempt warrants one fresh row read, never an assumed success.
            state.refresh(url.href);
        });
        const scanForm = root.querySelector('form[data-kb-scan]');
        const scan = root.querySelector('.kb-wl-scan');
        if (scanForm && scan && scanForm.elements.action && scanForm.elements.action.value === 'advance_scan' && typeof fetch === 'function') {
            let requests = 0;
            let paused = scan.dataset.autoScan === '0';
            let running = false;
            let timer;
            let request;
            const editing = () => paused || paymentRefreshPending || !!root.querySelector('.kb-wl-detail-row');
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
                    if (paymentRefreshPending) { message(scan.dataset.paused); return; }
                    if (result.page_ready === true && scan.dataset.pageReady !== '1') {
                        scan.dataset.pageReady = '1';
                        if (!editing()) navigation.reload();
                        return;
                    }
                    if (result.done) {
                        message(scan.dataset.ready);
                        if (!editing() && scan.dataset.pageReady !== '1') { scan.dataset.pageReady = '1'; navigation.reload(); }
                        return;
                    }
                    if (!editing() && requests < 30) timer = setTimeout(advance, 600);
                    else if (requests >= 30) message(scan.dataset.paused);
                } catch (error) {
                    if (error.name !== 'AbortError') message(error.message === 'changed' ? scan.dataset.changed : scan.dataset.failed);
                } finally { running = false; }
            };
            const pause = () => { paused = true; clearTimeout(timer); if (request) request.abort(); message(scan.dataset.paused); };
            pausePaymentAnalysis = pause;
            root.addEventListener('input', pause);
            root.addEventListener('change', pause);
            scanForm.addEventListener('submit', event => {
                event.preventDefault();
                if (paymentRefreshPending || root.querySelector('.kb-wl-detail-row')) { message(scan.dataset.paused); return; }
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

    if (typeof module !== 'undefined' && module.exports) module.exports = {decimal8, allocation, selectionReady, money, initialise};
    if (typeof document !== 'undefined') {
        const start = () => document.querySelectorAll('.kb-worklist').forEach(initialise);
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
        window.addEventListener('pageshow', event => {
            if (event.persisted) {
                try { window.sessionStorage.removeItem(POSITION_STORAGE_KEY); } catch (_) {}
                document.querySelectorAll('.kb-worklist form[data-kb-submitting]').forEach(form => { delete form.dataset.kbSubmitting; form.removeAttribute('aria-busy'); });
            }
        });
    }
}());
