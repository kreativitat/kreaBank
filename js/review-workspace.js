/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later */
(function () {
    'use strict';
    // Signed review values use eight decimal places. These totals are display-only.
    function totals(items) {
        const currencies = new Map();
        items.forEach(function (item) {
            const match = String(item.amount).match(/^(\d+)(?:\.(\d{1,8}))?$/);
            if (!match) throw new Error('Invalid review allocation');
            const amount = BigInt(match[1] + (match[2] || '').padEnd(8, '0'));
            currencies.set(item.currency, (currencies.get(item.currency) || 0n) + amount);
        });
        return Array.from(currencies, function ([currency, amount]) {
            const cents = (amount + 500000n) / 1000000n;
            return {currency, whole: cents / 100n, fraction: String(cents % 100n).padStart(2, '0')};
        });
    }
    if (typeof module === 'object' && module.exports) module.exports = {totals};
    if (typeof document === 'undefined') return;
    const results = document.getElementById('krea-bulk-results');
    if (!results) return;
    function refresh() {
        const form = results.querySelector('#kreaBulkMatchForm');
        const output = results.querySelector('#kb-review-selection');
        if (!form || !output) return; // Individual approval has no selection gate.
        const boxes = Array.from(form.querySelectorAll('.krea-bulk-match-line'));
        const chosen = boxes.filter(box => box.checked);
        const approval = form.querySelector('[name="review_confirmed"]');
        const button = form.querySelector('button[type="submit"]');
        const feedback = form.querySelector('#kb-review-feedback');
        boxes.forEach(box => box.closest('.krea-bulk-row').classList.toggle('kb-review-selected', box.checked));
        output.textContent = output.dataset.label.replace('%s', chosen.length) + ' ';
        const money = document.createElement('span');
        money.className = 'kb-review-selection-totals';
        let valid = true;
        try {
            const locale = form.dataset.locale || 'en-US';
            const formatter = new Intl.NumberFormat(locale, {maximumFractionDigits: 0});
            const separator = new Intl.NumberFormat(locale).formatToParts(1.1).find(part => part.type === 'decimal').value;
            money.textContent = totals(chosen.map(box => ({amount: box.dataset.allocation, currency: box.dataset.currency})))
                .map(value => formatter.format(value.whole) + separator + value.fraction + ' ' + value.currency).join(' · ');
        } catch (_) { valid = false; }
        output.appendChild(money);
        if (button) button.disabled = !valid || chosen.length === 0 || !approval || !approval.checked;
        feedback.textContent = !chosen.length ? feedback.dataset.select : (!approval || !approval.checked ? feedback.dataset.approve : feedback.dataset.ready);
    }
    results.addEventListener('submit', function (event) {
        const output = results.querySelector('#kb-review-selection');
        if (!output) return;
        const chosen = results.querySelectorAll('.krea-bulk-match-line:checked');
        const approval = results.querySelector('[name="review_confirmed"]');
        if (!chosen.length || !approval || !approval.checked) { event.preventDefault(); refresh(); }
    });
    window.kreabankReviewRefresh = refresh;
    refresh();
}());
