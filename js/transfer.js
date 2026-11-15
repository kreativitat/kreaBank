/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>; GPL-3.0-or-later */
(function () {
    'use strict';

    function enhance() {
        var table = document.getElementById('tablemouvbank');
        var config = document.getElementById('kb-transfer-labels');
        if (!table || !config || table.classList.contains('kb-transfer-table')) return;
        var form = table.closest('form');
        var add = form && form.querySelector('#btnincrement');
        var actions = form && form.querySelector('#btncont');
        var rows = Array.from(table.querySelectorAll('tr.numvir'));
        var headers = Array.from(table.querySelectorAll('.liste_titre > th, .liste_titre > td'));
        // Leave an unfamiliar native layout intact rather than hide unlabelled fields.
        if (!form || !add || !actions || !rows.length || rows.some(function (row) { return row.cells.length !== headers.length; })) return;
        var labels = JSON.parse(config.textContent);
        var descriptions = document.createElement('datalist');
        descriptions.id = 'kb-transfer-descriptions';
        (Array.isArray(labels.descriptions) ? labels.descriptions : []).slice(0, 10).forEach(function (description) {
            var option = document.createElement('option');
            option.value = description;
            descriptions.appendChild(option);
        });
        form.appendChild(descriptions);

        rows.forEach(function (row, index) {
            Array.from(row.cells).forEach(function (cell, column) {
                var control = cell.querySelector('select, input:not([type="hidden"])');
                if (!control) return;
                var name = control.name.replace(/^\d+_/, '');
                cell.classList.add('kb-transfer-field', 'kb-transfer-' + name);
                if (!control.id) control.id = 'kb-transfer-' + control.name;
                var label = document.createElement('label');
                label.htmlFor = control.id;
                label.textContent = name === 'account_from' ? labels.from : (name === 'account_to' ? labels.to : headers[column].textContent.trim());
                if (!label.textContent) label.textContent = control.name;
                label.id = 'kb-transfer-label-' + control.name;
                cell.insertBefore(label, cell.firstChild);
                control.setAttribute('aria-labelledby', label.id);
                // Native Select2 creates its focusable combobox before this footer runs.
                var combobox = cell.querySelector('[role="combobox"]');
                if (combobox) {
                    var selectedLabel = combobox.getAttribute('aria-labelledby');
                    combobox.setAttribute('aria-labelledby', label.id + (selectedLabel ? ' ' + selectedLabel : ''));
                }
                if (name === 'amount' || name === 'amountto') control.setAttribute('inputmode', 'decimal');
                if (name === 'label') {
                    control.setAttribute('list', descriptions.id);
                    control.setAttribute('autocomplete', 'off');
                    control.setAttribute('placeholder', labels.descriptionPlaceholder);
                    if (labels.descriptionError) {
                        var error = document.createElement('small');
                        error.className = 'opacitymedium';
                        error.id = control.id + '-history-error';
                        error.textContent = labels.descriptionError;
                        cell.appendChild(error);
                        control.setAttribute('aria-describedby', error.id);
                    }
                }
            });
            var heading = document.createElement('td');
            heading.className = 'kb-transfer-row-heading';
            var title = document.createElement('h2');
            title.id = 'kb-transfer-row-' + (index + 1);
            title.textContent = labels.transfer.replace('%s', String(index + 1));
            heading.appendChild(title);
            row.insertBefore(heading, row.firstChild);
            row.setAttribute('role', 'group');
            row.setAttribute('aria-labelledby', title.id);
        });

        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'button button-secondary kb-transfer-add';
        var icon = document.createElement('span');
        icon.className = 'fa fa-plus';
        icon.setAttribute('aria-hidden', 'true');
        button.appendChild(icon);
        button.appendChild(document.createTextNode(' ' + labels.add));
        button.addEventListener('click', function () {
            var next = rows.find(function (row) { return row.classList.contains('hidejs'); });
            // Invoke the original native action; do not clone controls or alter POST data.
            add.click();
            if (next && !next.classList.contains('hidejs')) {
                var focus = next.querySelector('.kb-transfer-account_from [role="combobox"], .kb-transfer-account_from select');
                if (focus && focus.classList.contains('select2-hidden-accessible')) focus = next.querySelector('.kb-transfer-account_from [role="combobox"]');
                if (focus) focus.focus();
            }
            button.disabled = !rows.some(function (row) { return row.classList.contains('hidejs'); });
        });
        add.hidden = true;
        actions.insertBefore(button, actions.firstChild);
        button.disabled = !rows.some(function (row) { return row.classList.contains('hidejs'); });
        var intro = form.previousElementSibling;
        while (intro && intro.tagName === 'BR') intro = intro.previousElementSibling;
        if (intro && intro.classList.contains('opacitymedium')) {
            intro.classList.add('kb-transfer-intro');
            intro.textContent = labels.help;
        }
        table.setAttribute('role', 'presentation');
        table.classList.add('kb-transfer-table');
        form.classList.add('kb-transfer-form');
        document.body.classList.add('kb-native-transfer');
    }

    // Run after native ready handlers restore retry rows and initialize Select2.
    if (window.jQuery) window.jQuery(enhance);
    else if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', enhance);
    else enhance();
}());
