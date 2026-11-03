/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later */
(function () {
    'use strict';
    // Exact decimal sums for display/gating; the original hidden amounts are never changed.
    function decimal(value) {
        var match = String(value).match(/^(-?)(\d+)(?:\.(\d+))?$/);
        if (!match) { throw new Error('Invalid allocation amount'); }
        return {units: BigInt((match[1] || '') + match[2] + (match[3] || '')), scale: (match[3] || '').length};
    }
    function allocation(outstanding, amounts) {
        var target = decimal(outstanding);
        var values = amounts.map(decimal);
        var scale = Math.max(2, target.scale, ...values.map(function (value) { return value.scale; }));
        function units(value) { return value.units * (10n ** BigInt(scale - value.scale)); }
        var total = values.reduce(function (sum, value) { return sum + units(value); }, 0n);
        var difference = units(target) - total;
        var absolute = difference < 0n ? -difference : difference;
        return {total: Number(total) / Math.pow(10, scale), difference: Number(difference) / Math.pow(10, scale), ready: values.length > 0 && total > 0n && absolute <= 10n ** BigInt(scale - 2)};
    }
    // Exposed only to the pure Node regression test, without bootstrapping a browser or ERP.
    if (typeof module === 'object' && module.exports) { module.exports = {allocation: allocation}; }
    if (typeof document === 'undefined') { return; }
    var root = document.querySelector('.kb-recon');
    if (!root) { return; }
    root.classList.add('kb-enhanced');
    var tabs = Array.from(root.querySelectorAll('[role=tab]'));
    function workflow(name, focus) {
        tabs.forEach(function (tab) {
            var active = tab.id === 'kb-tab-' + name;
            tab.setAttribute('aria-selected', String(active));
            tab.tabIndex = active ? 0 : -1;
            var panel = document.getElementById(tab.getAttribute('aria-controls'));
            panel.hidden = !active;
            panel.setAttribute('role', 'tabpanel');
            panel.setAttribute('aria-labelledby', tab.id);
            if (active && focus) { tab.focus(); }
        });
    }
    tabs.forEach(function (tab, index) {
        tab.addEventListener('click', function () { workflow(tab.id.replace('kb-tab-', ''), false); });
        tab.addEventListener('keydown', function (event) {
            var next;
            if (event.key === 'ArrowRight' || event.key === 'ArrowLeft') { next = 1 - index; }
            if (event.key === 'Home') { next = 0; }
            if (event.key === 'End') { next = tabs.length - 1; }
            if (typeof next !== 'undefined') { event.preventDefault(); workflow(tabs[next].id.replace('kb-tab-', ''), true); }
        });
    });
    workflow(root.dataset.initialWorkflow || 'match', false);
    var disclosure = root.querySelector('.kb-queue-disclosure');
    var mobile = window.matchMedia('(max-width:760px)');
    function queueLayout() { disclosure.open = !mobile.matches; }
    queueLayout();
    mobile.addEventListener('change', queueLayout);
    var search = document.getElementById('kb-pending-search');
    var queue = Array.from(root.querySelectorAll('.kb-queue-line'));
    function normalize(text) { return text.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase(); }
    function filterQueue() {
        var terms = normalize(search.value).trim().split(/\s+/);
        var count = 0;
        queue.forEach(function (line) {
            line.hidden = !terms.every(function (term) { return normalize(line.dataset.search).includes(term); });
            if (!line.hidden) { count++; }
            var url = new URL(line.href);
            if (search.value) { url.searchParams.set('queue_search', search.value); }
            else { url.searchParams.delete('queue_search'); }
            line.href = url.toString();
        });
        document.getElementById('kb-queue-empty').hidden = count > 0;
        document.getElementById('kb-queue-count').textContent = count + ' / ' + queue.length;
    }
    search.addEventListener('input', filterQueue);
    search.value = new URL(window.location.href).searchParams.get('queue_search') || '';
    if (queue.length) { filterQueue(); }
    function scrollToSelection() {
        var selected = root.querySelector('.kb-queue-line.selected');
        if (disclosure.open && selected && !selected.hidden) {
            var list = document.getElementById('kb-pending-list');
            list.scrollTop = selected.offsetTop - list.offsetTop - list.clientHeight / 2;
        }
    }
    disclosure.addEventListener('toggle', scrollToSelection);
    scrollToSelection();
    var form = document.getElementById('manualReconcileForm');
    if (form) {
        var selectors = Array.from(form.querySelectorAll('input[name="selected_docs[]"]'));
        var button = document.getElementById('manualReconcileButton');
        var feedback = document.getElementById('kb-selection-feedback');
        var formatter;
        try { formatter = new Intl.NumberFormat(form.dataset.locale, {minimumFractionDigits: 2, maximumFractionDigits: 2}); }
        catch (_) { formatter = new Intl.NumberFormat('en', {minimumFractionDigits: 2, maximumFractionDigits: 2}); }
        function money(amount) { return formatter.format(amount) + ' ' + form.dataset.currency; }
        function refresh() {
            var chosen = selectors.filter(function (input) { return input.checked && !input.disabled; });
            var state;
            try {
                state = allocation(form.dataset.outstanding, chosen.map(function (input) {
                    return input.closest('.krea-doc-item').querySelector('input[name^="doc_amount["]').value;
                }));
            } catch (_) { if (button) { button.disabled = true; } return false; }
            selectors.forEach(function (input) { input.closest('.krea-doc-item').classList.toggle('kb-selected', input.checked); });
            document.getElementById('kb-selected-total').textContent = money(state.total);
            document.getElementById('kb-selection-difference').textContent = money(state.difference);
            form.querySelector('.kb-allocation').classList.toggle('kb-balanced', state.ready);
            feedback.textContent = !chosen.length ? feedback.dataset.empty : (state.ready ? feedback.dataset.ready : feedback.dataset.mismatch);
            feedback.classList.toggle('kb-mismatch', chosen.length > 0 && !state.ready);
            if (button) { button.disabled = !state.ready; }
            return state.ready;
        }
        selectors.forEach(function (input) { input.addEventListener('change', refresh); });
        form.addEventListener('submit', function (event) { if (!refresh()) { event.preventDefault(); feedback.focus(); } });
        refresh();
    }
    root.querySelectorAll('form[id^=krea_quick_entry_form_]').forEach(function (entryForm) {
        var choices = Array.from(entryForm.querySelectorAll('input[name=creation_choice]'));
        function creationChoice() {
            var value = (entryForm.querySelector('input[name=creation_choice]:checked') || choices[0]).value;
            entryForm.querySelectorAll('[data-kb-create-action]').forEach(function (button) {
                button.hidden = button.dataset.kbCreateAction !== value;
                // A hidden submit button must never become the implicit Enter-key action.
                button.disabled = button.hidden;
                button.type = !button.hidden && value !== 'supplier_invoice' ? 'submit' : 'button';
            });
            entryForm.querySelectorAll('[data-kb-invoice-field],[data-kb-supplier-field]').forEach(function (field) {
                field.hidden = field.hasAttribute('data-kb-invoice-field') ? value !== 'supplier_invoice' : value === 'tax_entry';
                field.querySelectorAll('input,select').forEach(function (input) { input.disabled = field.hidden; });
            });
        }
        choices.forEach(function (input) { input.addEventListener('change', creationChoice); });
        creationChoice();
        entryForm.addEventListener('submit', function (event) {
            var value = (entryForm.querySelector('input[name=creation_choice]:checked') || choices[0]).value;
            if (!event.submitter || event.submitter.value !== value) {
                event.preventDefault();
                var action = entryForm.querySelector('[data-kb-create-action="' + value + '"]');
                if (action) { action.click(); }
            }
        });
    });
    root.querySelectorAll('form[method=POST]').forEach(function (writeForm) {
        writeForm.addEventListener('submit', function (event) {
            if (event.defaultPrevented) { return; }
            if (writeForm.getAttribute('aria-busy') === 'true') { event.preventDefault(); return; }
            writeForm.setAttribute('aria-busy', 'true');
        });
    });
})();
