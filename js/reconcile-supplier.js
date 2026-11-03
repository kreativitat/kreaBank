/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
* SPDX-License-Identifier: GPL-3.0-or-later */
(function () {
    "use strict";
    var kreaParseNumber = function(value){
        var parsed = parseFloat(value);
        return isNaN(parsed) ? 0 : parsed;
    };
    var kreaRefreshSupplierRows = function(modal){
        if (!modal) { return; }
        var rows = modal.querySelectorAll(".krea-product-line-row");
        var visibleRows = 0;
        rows.forEach(function(row){ if (!row.classList.contains("krea-product-line-row-hidden")) { visibleRows++; } });
        rows.forEach(function(row){
            var removeButton = row.querySelector(".krea-product-line-remove");
            if (!removeButton) { return; }
            if (row.classList.contains("krea-product-line-row-hidden") || visibleRows <= 1) {
                removeButton.style.display = "none";
            } else {
                removeButton.style.display = "";
            }
        });
        var addButton = modal.querySelector(".krea-product-line-add");
        if (addButton) {
            addButton.disabled = (visibleRows >= rows.length);
        }
    };
    var kreaDecodeHtml = function(value){
        if (!value) { return ""; }
        var decoded = String(value);
        if (typeof htmlEntityDecodeJs === "function") { decoded = htmlEntityDecodeJs(decoded); }
        var node = document.createElement("div");
        node.innerHTML = decoded;
        return (node.textContent || node.innerText || "").replace(/\s+/g, " ").trim();
    };
    var kreaNormalizeSearch = function(value){
        var normalized = String(value || "");
        if (typeof normalized.normalize === "function") { normalized = normalized.normalize("NFD").replace(/[\u0300-\u036f]/g, ""); }
        return normalized.toUpperCase().replace(/\s+/g, " ").trim();
    };
    var kreaMatchesSearchTerm = function(term, candidate){
        if (term === "") { return true; }
        var keywords = term.split(" ");
        for (var i = 0; i < keywords.length; i++) {
            if (keywords[i] && candidate.indexOf(keywords[i]) === -1) { return false; }
        }
        return true;
    };
    var kreaSupplierSearchTerms = {};
    var kreaEnhanceSearchableSelect = function(selectElement){
        if (!selectElement || !window.jQuery || !jQuery.fn || typeof jQuery.fn.select2 !== "function") { return; }
        var $select = jQuery(selectElement);
        var currentValue = $select.val();
        var dropdownParent = null;
        var modalParent = selectElement.closest(".krea-modal-overlay");
        if (modalParent) { dropdownParent = jQuery(modalParent); }
        if ($select.hasClass("select2-hidden-accessible")) {
            try { $select.select2("destroy"); } catch (errorSelect2Destroy) { /* keep default rendering */ }
        }
        $select.select2({
            dir: "ltr",
            width: "resolve",
            minimumInputLength: 0,
            minimumResultsForSearch: 0,
            dropdownParent: (dropdownParent || jQuery("body")),
            language: (typeof select2arrayoflanguage === "undefined") ? "en" : select2arrayoflanguage,
            matcher: function(params, data){
                var term = kreaNormalizeSearch(params && params.term ? params.term : "");
                if (term === "") { return data; }
                var optionHtml = (data && data.element) ? (jQuery(data.element).attr("data-html") || "") : "";
                var candidate = kreaNormalizeSearch((data && data.text ? data.text : "") + " " + kreaDecodeHtml(optionHtml));
                return kreaMatchesSearchTerm(term, candidate) ? data : null;
            },
            theme: "default",
            containerCssClass: ":all:",
            selectionCssClass: ":all:",
            dropdownCssClass: "ui-dialog",
            templateResult: function(data, container){
                if (data.element) { jQuery(container).addClass(jQuery(data.element).attr("class")); }
                if (data.id == "-1" && jQuery(data.element).attr("data-html") == undefined) { return "&nbsp;"; }
                if (jQuery(data.element).attr("data-html") != undefined) {
                    return kreaDecodeHtml(jQuery(data.element).attr("data-html"));
                }
                return data.text;
            },
            templateSelection: function(selection){
                if (selection.id == "-1") { return "<span class=\"placeholder\">" + selection.text + "</span>"; }
                return selection.text;
            },
            escapeMarkup: function(markup){ return markup; }
        });
        if (typeof currentValue !== "undefined" && currentValue !== null) {
            $select.val(currentValue).trigger("change.select2");
        }
    };
    var kreaExtractProductMetaFromText = function(rawText){
        var text = (rawText ? String(rawText) : "").replace(/\s+/g, " ").trim();
        if (text === "") { return {ref: "", label: ""}; }
        var pieces = text.split(" - ");
        var ref = (pieces.length > 0 ? pieces[0].trim() : "");
        var label = "";
        if (pieces.length > 1) { label = pieces[1].trim(); }
        if (label === "" && pieces.length > 2) { label = pieces.slice(1, -1).join(" - ").trim(); }
        if (label === "") { label = text; }
        return {ref: ref, label: label};
    };
    var kreaReadProductMeta = function(row){
        var meta = {id: 0, ref: "", label: ""};
        if (!row) { return meta; }
        var productField = row.querySelector("select[name^=entry_product_line_product_],input[name^=entry_product_line_product_]");
        if (!productField) { return meta; }
        meta.id = parseInt(productField.value || "0", 10) || 0;
        var sourceText = "";
        if (productField.tagName && productField.tagName.toUpperCase() === "SELECT") {
            var selectedOption = productField.options && productField.selectedIndex >= 0 ? productField.options[productField.selectedIndex] : null;
            if (selectedOption) {
                sourceText = selectedOption.getAttribute("data-html") || selectedOption.text || "";
                sourceText = kreaDecodeHtml(sourceText);
            }
        } else {
            var searchInput = row.querySelector("input[name^=search_entry_product_line_product_]");
            sourceText = searchInput ? String(searchInput.value || "") : "";
        }
        var parsed = kreaExtractProductMetaFromText(sourceText);
        meta.ref = parsed.ref || (meta.id > 0 ? String(meta.id) : "");
        meta.label = parsed.label || "";
        return meta;
    };
    var kreaSyncProductRow = function(row, productMeta){
        if (!row) { return; }
        var meta = productMeta || kreaReadProductMeta(row);
        var labelInput = row.querySelector("input[name^=entry_product_line_label_]");
        if (labelInput) {
            labelInput.value = (meta.id > 0 ? (meta.label || "") : "");
        }
        var productSelect = row.querySelector("select[name^=entry_product_line_product_]");
        if (productSelect && meta.id > 0 && meta.ref !== "") {
            var select2Container = document.getElementById("select2-" + productSelect.id + "-container");
            if (select2Container) {
                select2Container.textContent = meta.ref;
                select2Container.setAttribute("title", meta.ref);
            }
        }
    };
    var kreaReadProductId = function(row){
        return kreaReadProductMeta(row).id;
    };
    var kreaBuildSupplierLines = function(modal){
        var payload = {lines: [], hasSelectedLine: false, hasIncompleteLine: false, currentTotal: 0};
        if (!modal) { return payload; }
        modal.querySelectorAll(".krea-product-line-row").forEach(function(row){
            if (row.classList.contains("krea-product-line-row-hidden")) { return; }
            var productMeta = kreaReadProductMeta(row);
            var productId = productMeta.id;
            kreaSyncProductRow(row, productMeta);
            var qtyInput = row.querySelector("input[name^=entry_product_line_qty_]");
            var amountInput = row.querySelector("input[name^=entry_product_line_amount_]");
            var labelInput = row.querySelector("input[name^=entry_product_line_label_]");
            var qtyValue = kreaParseNumber(qtyInput ? qtyInput.value : 0);
            var amountValue = kreaParseNumber(amountInput ? amountInput.value : 0);
            var lineLabel = labelInput ? String(labelInput.value || "").trim() : "";
            var lineIsEmpty = (productId <= 0 && qtyValue <= 0 && amountValue <= 0);
            if (lineIsEmpty) { return; }
            payload.hasSelectedLine = true;
            if (productId <= 0 || qtyValue <= 0 || amountValue <= 0) {
                payload.hasIncompleteLine = true;
                return;
            }
            payload.currentTotal += amountValue;
            payload.lines.push({product_id: productId, label: lineLabel, qty: qtyValue, amount: Math.round(amountValue * 100) / 100});
        });
        return payload;
    };
    var kreaSyncSupplierLookup = function(form){
        if (!form) { return; }
        var lookupInput = form.querySelector("input[name=entry_supplier_lookup]");
        if (!lookupInput) { return; }
        var searchInput = form.querySelector("input[name=search_entry_supplier_socid]");
        var supplierField = form.querySelector("select[name=entry_supplier_socid],input[name=entry_supplier_socid]");
        var lookupValue = "";
        if (supplierField && supplierField.tagName && supplierField.tagName.toUpperCase() === "SELECT") {
            var selectedOption = supplierField.options && supplierField.selectedIndex >= 0 ? supplierField.options[supplierField.selectedIndex] : null;
            if (selectedOption && parseInt(selectedOption.value || "0", 10) > 0) {
                lookupValue = kreaDecodeHtml(selectedOption.getAttribute("data-html") || selectedOption.text || "");
            }
        }
        if (lookupValue === "" && searchInput && String(searchInput.value || "").trim() !== "") {
            lookupValue = String(searchInput.value || "").trim();
        }
        if (lookupValue === "" && supplierField && supplierField.id && kreaSupplierSearchTerms[supplierField.id]) {
            lookupValue = String(kreaSupplierSearchTerms[supplierField.id] || "").trim();
        }
        if (lookupValue === "" && supplierField && supplierField.id) {
            var renderedNode = document.getElementById("select2-" + supplierField.id + "-container");
            if (renderedNode && !renderedNode.querySelector(".placeholder")) {
                lookupValue = String(renderedNode.textContent || "").trim();
            }
        }
        lookupInput.value = lookupValue;
    };
    var kreaReadSupplierId = function(form){
        if (!form) { return 0; }
        var supplierField = form.querySelector("select[name=entry_supplier_socid],input[name=entry_supplier_socid]");
        return parseInt(supplierField && supplierField.value ? supplierField.value : "0", 10) || 0;
    };
    var kreaUpdateSupplierInvoiceOpenState = function(form){
        if (!form) { return; }
        var hasSupplier = (kreaReadSupplierId(form) > 0);
        var feedback = form.querySelector("[data-kb-supplier-feedback]");
        if (feedback && hasSupplier) { feedback.hidden = true; }
        form.querySelectorAll("[data-krea-supplier-modal-open]").forEach(function(openButton){
            openButton.disabled = false;
            if (!hasSupplier) {
                openButton.setAttribute("aria-disabled", "true");
                openButton.setAttribute("title", openButton.getAttribute("data-krea-msg-supplier") || "");
            } else {
                openButton.removeAttribute("aria-disabled");
                openButton.removeAttribute("title");
            }
        });
    };
    var kreaSetSupplierModalState = function(modal){
        if (!modal) { return; }
        var form = modal.closest("form");
        if (form) { kreaSyncSupplierLookup(form); }
        var targetAmount = kreaParseNumber(modal.getAttribute("data-target-total"));
        if (targetAmount < 0) { targetAmount = 0; }
        var build = kreaBuildSupplierLines(modal);
        var targetNodeId = modal.getAttribute("data-target-total-id");
        var currentNodeId = modal.getAttribute("data-current-total-id");
        var feedbackId = modal.getAttribute("data-feedback-id");
        var confirmId = modal.getAttribute("data-confirm-id");
        var jsonInputId = modal.getAttribute("data-json-input-id");
        var targetNode = targetNodeId ? document.getElementById(targetNodeId) : null;
        var currentNode = currentNodeId ? document.getElementById(currentNodeId) : null;
        var feedbackNode = feedbackId ? document.getElementById(feedbackId) : null;
        var confirmButton = confirmId ? document.getElementById(confirmId) : null;
        var jsonInput = jsonInputId ? document.getElementById(jsonInputId) : null;
        var formatter;
        try { formatter = new Intl.NumberFormat(form.dataset.locale, {minimumFractionDigits:2, maximumFractionDigits:2}); }
        catch (_) { formatter = new Intl.NumberFormat('en', {minimumFractionDigits:2, maximumFractionDigits:2}); }
        if (targetNode) { targetNode.textContent = formatter.format(targetAmount); }
        if (currentNode) { currentNode.textContent = formatter.format(build.currentTotal); }
        if (jsonInput) { jsonInput.value = JSON.stringify(build.lines); }
        var message = "";
        var isValid = true;
        if (!build.hasSelectedLine) {
            message = modal.getAttribute("data-msg-empty") || "";
            isValid = false;
        } else if (build.hasIncompleteLine) {
            message = modal.getAttribute("data-msg-incomplete") || "";
            isValid = false;
        } else if (Math.abs(build.currentTotal - targetAmount) > 0.01) {
            message = modal.getAttribute("data-msg-mismatch") || "";
            isValid = false;
        } else if (form) {
            var supplierField = form.querySelector("select[name=entry_supplier_socid],input[name=entry_supplier_socid]");
            var supplierId = parseInt(supplierField && supplierField.value ? supplierField.value : "0", 10) || 0;
            if (supplierId <= 0) {
                message = modal.getAttribute("data-msg-supplier") || "";
                isValid = false;
            }
        }
        if (feedbackNode) {
            if (message !== "") {
                feedbackNode.textContent = message;
                feedbackNode.style.display = "block";
            } else {
                feedbackNode.textContent = "";
                feedbackNode.style.display = "none";
            }
        }
        if (confirmButton) {
            confirmButton.disabled = !isValid;
        }
    };
    var kreaCloseSupplierModal = function(modal){
        if (!modal) { return; }
        modal.classList.remove("is-open");
        modal.querySelector("[name=entry_submit_action][value=supplier_invoice]").type = "button";
        modal.style.display = "none";
        if (modal._returnFocus) { modal._returnFocus.focus(); }
    };
    var kreaOpenSupplierModal = function(modal, form){
        if (!modal) { return; }
        if (form) {
            var amountInput = form.querySelector("input[name=entry_amount]");
            if (amountInput) {
                var targetAmount = kreaParseNumber(amountInput.value);
                if (targetAmount > 0) {
                    modal.setAttribute("data-target-total", targetAmount.toFixed(2));
                    var firstAmountInput = modal.querySelector("input[name^=entry_product_line_amount_]");
                    if (firstAmountInput && kreaParseNumber(firstAmountInput.value) <= 0) { firstAmountInput.value = targetAmount.toFixed(2); }
                }
            }
        }
        modal._returnFocus = document.activeElement;
        modal.classList.add("is-open");
        modal.querySelector("[name=entry_submit_action][value=supplier_invoice]").type = "submit";
        modal.style.display = "flex";
        modal.focus();
        kreaRefreshSupplierRows(modal);
        kreaSetSupplierModalState(modal);
    };
    document.addEventListener("input", function(event){
        if (!event.target || !event.target.classList || !event.target.classList.contains("select2-search__field")) { return; }
        var controls = event.target.getAttribute("aria-controls") || "";
        var match = controls.match(/^select2-(.+)-results$/);
        if (!match || !match[1]) { return; }
        var fieldId = match[1];
        var supplierField = document.getElementById(fieldId);
        if (!supplierField || supplierField.name !== "entry_supplier_socid") { return; }
        kreaSupplierSearchTerms[fieldId] = String(event.target.value || "").trim();
    });
    document.querySelectorAll("select[name=entry_supplier_socid],select[name^=entry_product_line_product_]").forEach(function(selectElement){
        kreaEnhanceSearchableSelect(selectElement);
    });
    document.querySelectorAll("form[id^=krea_quick_entry_form_]").forEach(function(form){
        form.addEventListener("submit", function(event){
            if (!event.submitter || event.submitter.value !== "supplier_invoice") { return; }
            kreaSyncSupplierLookup(form);
            var modal = form.querySelector(".krea-supplier-lines-modal");
            kreaSetSupplierModalState(modal);
            if (modal && modal.querySelector("[name=entry_submit_action][value=supplier_invoice]").disabled) { event.preventDefault(); }
        });
        var supplierField = form.querySelector("select[name=entry_supplier_socid],input[name=entry_supplier_socid]");
        if (supplierField) {
            supplierField.addEventListener("change", function(){ kreaSyncSupplierLookup(form); kreaUpdateSupplierInvoiceOpenState(form); });
        }
        kreaUpdateSupplierInvoiceOpenState(form);
        var previous = [];
        try { previous = JSON.parse(form.getAttribute("data-initial-lines") || "[]"); } catch (_) { previous = []; }
        previous.slice(0, 6).forEach(function(line, index){
            var row = form.querySelector("[data-krea-product-row=\"" + index + "\"]");
            if (!row) { return; }
            row.classList.remove("krea-product-line-row-hidden");
            var select = row.querySelector("select[name^=entry_product_line_product_]");
            if (select && line.product_id > 0) {
                var option = new Option(line.label || String(line.product_id), line.product_id, true, true);
                select.add(option);
                if (window.jQuery) { jQuery(select).trigger("change.select2"); }
            }
            row.querySelector("input[name^=entry_product_line_label_]").value = line.label || "";
            row.querySelector("input[name^=entry_product_line_qty_]").value = line.qty || "";
            row.querySelector("input[name^=entry_product_line_amount_]").value = line.amount > 0 ? Number(line.amount).toFixed(2) : "";
        });
    });
    document.querySelectorAll("[data-krea-supplier-modal-open]").forEach(function(openButton){
        openButton.addEventListener("click", function(){
            var modalId = openButton.getAttribute("data-krea-supplier-modal-open");
            var formId = openButton.getAttribute("data-krea-form-id");
            var modal = modalId ? document.getElementById(modalId) : null;
            var form = formId ? document.getElementById(formId) : null;
            if (form && kreaReadSupplierId(form) <= 0) {
                var supplierMessage = openButton.getAttribute("data-krea-msg-supplier") || "";
                var supplierField = form.querySelector("select[name=entry_supplier_socid],input[name=entry_supplier_socid]");
                var feedback = form.querySelector("[data-kb-supplier-feedback]");
                if (feedback) { feedback.textContent = supplierMessage; feedback.hidden = false; }
                if (supplierField && window.jQuery && jQuery(supplierField).hasClass("select2-hidden-accessible")) { jQuery(supplierField).select2("open"); }
                else if (supplierField) { supplierField.focus(); }
                kreaUpdateSupplierInvoiceOpenState(form);
                return;
            }
            kreaOpenSupplierModal(modal, form);
        });
    });
    document.querySelectorAll("[data-krea-modal-close]").forEach(function(closeButton){
        closeButton.addEventListener("click", function(){
            var modalId = closeButton.getAttribute("data-krea-modal-close");
            var modal = modalId ? document.getElementById(modalId) : null;
            kreaCloseSupplierModal(modal);
        });
    });
    document.querySelectorAll(".krea-supplier-lines-modal").forEach(function(modal){
        modal.querySelectorAll(".krea-product-line-row").forEach(function(row){ kreaSyncProductRow(row); });
        modal.addEventListener("keydown", function(event){
            if (event.key !== "Tab") { return; }
            var focusable = Array.from(modal.querySelectorAll("button,input,select,textarea,[tabindex]")).filter(function(node){ return !node.disabled && node.tabIndex >= 0 && node.getClientRects().length > 0; });
            var first = focusable[0], last = focusable[focusable.length - 1];
            if (!first) { event.preventDefault(); return; }
            if (event.shiftKey && (document.activeElement === first || document.activeElement === modal)) { event.preventDefault(); last.focus(); }
            else if (!event.shiftKey && (document.activeElement === last || document.activeElement === modal)) { event.preventDefault(); first.focus(); }
        });
        kreaRefreshSupplierRows(modal);
        kreaSetSupplierModalState(modal);
        modal.addEventListener("click", function(event){
            if (event.target === modal) { kreaCloseSupplierModal(modal); }
        });
        var addButton = modal.querySelector(".krea-product-line-add");
        if (addButton) {
            addButton.addEventListener("click", function(){
                var nextRow = modal.querySelector(".krea-product-line-row-hidden");
                if (!nextRow) { return; }
                nextRow.classList.remove("krea-product-line-row-hidden");
                var qtyInput = nextRow.querySelector("input[name^=entry_product_line_qty_]");
                if (qtyInput && kreaParseNumber(qtyInput.value) <= 0) { qtyInput.value = "1"; }
                var amountInput = nextRow.querySelector("input[name^=entry_product_line_amount_]");
                if (amountInput && kreaParseNumber(amountInput.value) > 0) { amountInput.value = (Math.round(kreaParseNumber(amountInput.value) * 100) / 100).toFixed(2); }
                kreaSyncProductRow(nextRow);
                kreaRefreshSupplierRows(modal);
                kreaSetSupplierModalState(modal);
            });
        }
        modal.querySelectorAll(".krea-product-line-remove").forEach(function(removeButton){
            removeButton.addEventListener("click", function(){
                var row = removeButton.closest(".krea-product-line-row");
                if (!row) { return; }
                row.classList.add("krea-product-line-row-hidden");
                row.querySelectorAll("input").forEach(function(input){
                    if (input.name && input.name.indexOf("entry_product_line_qty_") === 0) { input.value = ""; return; }
                    if (input.name && input.name.indexOf("entry_product_line_amount_") === 0) { input.value = ""; return; }
                    if (input.name && input.name.indexOf("entry_product_line_label_") === 0) { input.value = ""; return; }
                    if (input.name && input.name.indexOf("entry_product_line_product_") === 0) { input.value = ""; return; }
                    if (input.name && input.name.indexOf("search_entry_product_line_product_") === 0) { input.value = ""; }
                });
                row.querySelectorAll("select[name^=entry_product_line_product_]").forEach(function(select){ select.value = ""; });
                kreaRefreshSupplierRows(modal);
                kreaSetSupplierModalState(modal);
            });
        });
        modal.querySelectorAll("input[name^=entry_product_line_amount_]").forEach(function(amountInput){
            amountInput.addEventListener("blur", function(){
                var value = kreaParseNumber(amountInput.value);
                if (value <= 0) { amountInput.value = ""; kreaSetSupplierModalState(modal); return; }
                amountInput.value = (Math.round(value * 100) / 100).toFixed(2);
                kreaSetSupplierModalState(modal);
            });
        });
        modal.addEventListener("input", function(event){
            if (event.target && event.target.name && (event.target.name.indexOf("entry_product_line_") === 0 || event.target.name.indexOf("search_entry_product_line_product_") === 0)) {
                kreaSetSupplierModalState(modal);
            }
        });
        modal.addEventListener("change", function(event){
            if (event.target && event.target.name && (event.target.name.indexOf("entry_product_line_") === 0 || event.target.name.indexOf("search_entry_product_line_product_") === 0)) {
                kreaSetSupplierModalState(modal);
            }
        });
    });
    document.addEventListener("keydown", function(event){
        if (event.key !== "Escape") { return; }
        document.querySelectorAll(".krea-supplier-lines-modal.is-open").forEach(function(modal){
            kreaCloseSupplierModal(modal);
        });
    });
})();
