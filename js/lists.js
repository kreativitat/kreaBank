/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
(function () {
    'use strict';
    const collator = new Intl.Collator(document.documentElement.lang || undefined, {numeric: true, sensitivity: 'base'});
    // Raw attributes keep localized dates and amounts out of comparison logic.
    function value(cell) {
        const raw = cell.getAttribute('data-kb-sort-value');
        if (raw !== null) return raw === '' ? null : (/^[+-]?\d+(?:\.\d+)?$/.test(raw) ? Number(raw) : raw);
        return cell.textContent.trim();
    }
    document.querySelectorAll('table[data-kb-sort]').forEach(function (table) {
        const header = Array.from(table.rows).find(row => row.cells.length > 1 && Array.from(row.cells).every(cell => cell.tagName === 'TH' && cell.colSpan === 1));
        if (!header) return;
        const skipped = (table.getAttribute('data-kb-sort-skip') || '').split(',').filter(part => part !== '').map(Number);
        const rows = Array.from(table.rows).filter(row => row !== header && row.cells.length === header.cells.length && Array.from(row.cells).every(cell => cell.tagName === 'TD' && cell.colSpan === 1));
        Array.from(header.cells).forEach(function (cell, column) {
            if (skipped.includes(column) || cell.querySelector('input,button,a')) return;
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'kb-sort-button';
            const label = cell.textContent.trim();
            button.textContent = label + ' ↕';
            cell.textContent = '';
            cell.scope = 'col';
            cell.setAttribute('aria-sort', 'none');
            cell.appendChild(button);
            button.addEventListener('click', function () {
                const descending = cell.getAttribute('aria-sort') === 'ascending';
                Array.from(header.cells).forEach(function (other) {
                    other.setAttribute('aria-sort', 'none');
                    const control = other.querySelector('.kb-sort-button');
                    if (control) control.textContent = control.textContent.replace(/ [↕↑↓]$/, ' ↕');
                });
                cell.setAttribute('aria-sort', descending ? 'descending' : 'ascending');
                button.textContent = label + (descending ? ' ↓' : ' ↑');
                const ordered = rows.map((row, index) => ({row, index, value: value(row.cells[column])}));
                ordered.sort(function (a, b) {
                    if (a.value === null || b.value === null) return a.value === b.value ? a.index - b.index : (a.value === null ? 1 : -1);
                    const comparison = typeof a.value === 'number' && typeof b.value === 'number' ? a.value - b.value : collator.compare(String(a.value), String(b.value));
                    return comparison ? (descending ? -comparison : comparison) : a.index - b.index;
                });
                ordered.forEach(({row}) => row.parentNode.appendChild(row));
            });
        });
    });
}());
