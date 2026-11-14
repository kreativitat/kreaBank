/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later */
'use strict';
const assert = require('node:assert/strict');
const {decimal8, allocation, money} = require('../js/worklist.js');
let checks = 0;
function check(target, amounts, expectedReady, expectedDifference) {
    const original = [...amounts];
    const state = allocation(target, amounts);
    assert.equal(state.ready, expectedReady);
    assert.equal(state.difference, decimal8(expectedDifference));
    assert.deepEqual(amounts, original, 'feedback must not alter input/POST precision');
    checks++;
}
check('-3300.00000000', ['-1000.00000000','-1100.00000000','-1200.00000000'], true, '0');
check('-3300', [], false, '-3300');
check('-3300', ['-1000','-1100'], false, '-1200');
check('-3300', ['-3300'], false, '0');
check('-3300', ['-3300','0'], false, '0');
check('-3300', ['-4000','700'], false, '0');
check('-3300', ['-1000','-2300.00000001'], false, '0.00000001');
check('0.3', ['0.1','0.2'], true, '0');
check('100.00000001', ['50.00000000','50.00000001'], true, '0');
check('9007199254740993.01', ['9007199254740993','0.01'], true, '0');
check('0', ['0','0'], false, '0');
for (const value of ['NaN','1e4','1,00','','1.000000001','--1','Infinity','<script>']) {
    assert.throws(() => decimal8(value), TypeError);
    checks++;
}
assert.equal(money(decimal8('-3300'), 'EUR', 'en-US'), '−3,300.00 EUR'); checks++;
assert.equal(money(decimal8('-3300'), 'EUR', 'pt-PT').replace(/\s/g,''), '−3300,00EUR'); checks++;
assert.equal(money(decimal8('9007199254740993.01'), 'EUR', 'en-US'), '9,007,199,254,740,993.01 EUR'); checks++;
assert.equal(money(decimal8('-0.00000001'), 'EUR', 'en-US'), '0.00 EUR'); checks++;
assert.equal(money(decimal8('0.005'), 'EUR', 'en-US'), '0.01 EUR'); checks++;
assert.equal(money(decimal8('-3300'), 'EUR', 'pt-PT', {groupSeparator:' ',decimalSeparator:','}), '−3 300,00 EUR'); checks++;
assert.equal(money(decimal8('1000000.01'), 'EUR', 'en-US', {groupSeparator:'',decimalSeparator:'.'}), '1000000.01 EUR'); checks++;
console.log(`OK: ${checks} exact worklist allocation checks`);

/** Optional real Chromium checks; fixtures contain no ERP endpoint and intercept every submit. */
async function browserChecks(directory) {
    const path = require('node:path');
    const {pathToFileURL} = require('node:url');
    const {chromium} = require('playwright');
    const browser = await chromium.launch({executablePath:process.env.KREABANK_CHROMIUM || '/usr/bin/chromium', headless:true, args:['--no-sandbox']});
    let page = await browser.newPage({viewport:{width:1440,height:1000},locale:'en-US'});
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    let browserCount = 0;
    const open = async (name, hash = '') => {
        await page.goto('about:blank');
        await page.goto(pathToFileURL(path.join(directory, name + '.html')).href + hash);
        await page.waitForSelector('.kb-worklist');
    };
    try {
        await open('batch');
        assert.equal(await page.locator('[data-kb-selected-total]').innerText(), '−3,300.00 EUR'); browserCount++;
        assert.equal(await page.locator('[data-kb-difference]').innerText(), '0.00 EUR'); browserCount++;
        assert.equal(await page.locator('[data-kb-batch-feedback]').innerText(), 'Ready'); browserCount++;
        assert.equal(await page.locator('[data-kb-batch-review]').isDisabled(), false); browserCount++;
        // A sub-cent difference must remain inspectable even when the display rounds to 0.00.
        await page.locator('[data-kb-member]').nth(2).evaluate(input => { input.dataset.amount = '-1200.00000001'; input.dispatchEvent(new Event('change',{bubbles:true})); });
        assert.equal(await page.locator('[data-kb-difference]').innerText(),'0.00 EUR'); browserCount++;
        assert.equal(await page.locator('[data-kb-difference]').getAttribute('title'),'0.00000001 EUR'); browserCount++;
        assert.equal(await page.locator('[data-kb-batch-review]').isDisabled(),true); browserCount++;
        await page.locator('[data-kb-member]').nth(2).evaluate(input => { input.dataset.amount = '-1200.00000000'; input.dispatchEvent(new Event('change',{bubbles:true})); });
        await page.screenshot({path:path.join(directory,'worklist-desktop.png'),fullPage:true});
        const second = page.locator('[data-kb-member]').nth(1);
        const originalAmount = await second.getAttribute('data-amount');
        await second.uncheck();
        assert.equal(await page.locator('[data-kb-selected-total]').innerText(), '−2,200.00 EUR'); browserCount++;
        assert.equal(await page.locator('[data-kb-difference]').innerText(), '−1,100.00 EUR'); browserCount++;
        assert.equal(await page.locator('[data-kb-batch-review]').isDisabled(), true); browserCount++;
        assert.equal(await second.getAttribute('data-amount'), originalAmount); browserCount++;
        await page.getByRole('button',{name:'Save draft',exact:true}).click();
        let submission = await page.locator('#fixture-submit').innerText();
        assert.ok(submission.includes('save_draft') && submission.includes('native_bank__21') && submission.includes('native_bank__23') && !submission.includes('native_bank__22')); browserCount++;
        await second.check();
        await page.getByRole('button',{name:'Review batch',exact:true}).click();
        submission = await page.locator('#fixture-submit').innerText();
        assert.ok(submission.includes('review_batch') && submission.includes('exact-source-snapshot')); browserCount++;
        await page.setViewportSize({width:390,height:844});
        await page.screenshot({path:path.join(directory,'worklist-mobile.png'),fullPage:true});
        const overflow = await page.evaluate(() => ({width:document.documentElement.scrollWidth,viewport:window.innerWidth,x:window.scrollX,elements:[...document.querySelectorAll('*')].filter(element=>element.getBoundingClientRect().right>window.innerWidth&&!element.closest('.div-table-responsive')).map(element => ({element:element.tagName + '.' + element.className,width:element.getBoundingClientRect().width,scroll:element.scrollWidth,right:element.getBoundingClientRect().right,text:element.textContent.slice(0,50)}))}));
        assert.ok(overflow.width <= overflow.viewport, JSON.stringify(overflow)); browserCount++;

        await page.setViewportSize({width:1280,height:900});
        await open('direct_correction');
        const opener = page.getByRole('button',{name:'PAYMENT-BANK',exact:true});
        await opener.click();
        const dialog = page.getByRole('dialog');
        assert.equal(await dialog.isVisible(), true); browserCount++;
        assert.equal(await page.getByRole('button',{name:'Cancel',exact:true}).evaluate(button => document.activeElement === button), true); browserCount++;
        await page.keyboard.press('Tab');
        await page.keyboard.press('Tab');
        assert.equal(await dialog.evaluate(element => element.contains(document.activeElement)), true); browserCount++;
        await page.screenshot({path:path.join(directory,'worklist-account-dialog.png'),fullPage:true});
        await page.keyboard.press('Escape');
        assert.equal(await dialog.isVisible(), false); browserCount++;
        assert.equal(await opener.evaluate(button => document.activeElement === button), true); browserCount++;
        const iconOpener = page.getByRole('button',{name:'Change bank',exact:true});
        await iconOpener.click();
        assert.equal(await dialog.isVisible(), true); browserCount++;
        await page.keyboard.press('Escape');
        assert.equal(await iconOpener.evaluate(button => document.activeElement === button), true); browserCount++;
        await opener.click();
        await page.getByRole('button',{name:'Save',exact:true}).click();
        submission = await page.locator('#fixture-submit').innerText();
        assert.ok(submission.includes('correct_account') && submission.includes('synthetic-direct-review') && !submission.includes('confirm_pair')); browserCount++;

        await open('other');
        const candidateAccount = page.getByRole('button',{name:'OTHER-BANK',exact:true});
        await candidateAccount.focus();
        await page.keyboard.press('Enter');
        assert.equal(await page.getByRole('dialog').isVisible(), true); browserCount++;
        await page.getByRole('button',{name:'Cancel',exact:true}).click();
        assert.equal(await candidateAccount.evaluate(button => document.activeElement === button), true); browserCount++;

        await open('reviewed');
        assert.equal(await page.locator('[data-kb-member]').count(), 0); browserCount++;
        assert.equal(await page.locator('input[name="batch_name"]').count(), 0); browserCount++;
        await page.getByRole('button',{name:'Reconcile batch',exact:true}).click();
        submission = await page.locator('#fixture-submit').innerText();
        assert.ok(submission.includes('confirm_batch') && submission.includes('signed-group') && submission.includes('native_bank__23')); browserCount++;

        await open('completed');
        assert.equal(await page.locator('.kb-wl-candidate').count(),3); browserCount++;
        assert.equal(await page.locator('input[type="checkbox"]').count(),0); browserCount++;
        await page.getByRole('button',{name:'Undo batch',exact:true}).click();
        assert.ok((await page.locator('#fixture-submit').innerText()).includes('synthetic-undo-review')); browserCount++;
        await open('readonly');
        assert.equal(await page.locator('form[method="post"]').count(),0); browserCount++;
        assert.equal(await page.getByRole('button',{name:'Change bank',exact:true}).count(),0); browserCount++;
        await open('unavailable');
        assert.equal(await page.locator('[data-kb-selected-total]').innerText(),'—'); browserCount++;
        assert.equal(await page.locator('[data-kb-batch-review]').isDisabled(),true); browserCount++;
        assert.equal(await page.locator('[data-kb-selected-count]').innerText(),'Selected: 3'); browserCount++;
        await page.locator('[data-kb-member][data-amount=""]').uncheck();
        assert.equal(await page.locator('[data-kb-selected-total]').innerText(),'−2,100.00 EUR'); browserCount++;

        // Short synthetic timers exercise the real request loop without waiting 18 seconds.
        await page.addInitScript(() => {
            const timeout = window.setTimeout.bind(window);
            window.setTimeout = (callback, delay, ...args) => timeout(callback, Math.min(delay,5), ...args);
            window.__scanRequests = [];
            window.fetch = async (url, options) => {
                const fields = Object.fromEntries(options.body.entries());
                window.__scanRequests.push({url,fields,method:options.method,header:options.headers['X-KreaBank-Scan']});
                if (window.location.hash === '#scan-changed') return new Response('{}', {status:409});
                if (window.location.hash === '#scan-failed') return new Response(JSON.stringify({done:false,processed:Number(fields.scan_cursor)+1,total:51,generation:fields.scan_generation,cursor:Number(fields.scan_cursor)+1,page_ready:false,error:'failed'}), {status:503,headers:{'Content-Type':'application/json'}});
                return new Response(JSON.stringify({done:false,processed:Number(fields.scan_cursor)+1,total:51,generation:window.location.hash === '#scan-generation' ? 'another-generation' : fields.scan_generation,cursor:Number(fields.scan_cursor)+1,page_ready:window.location.hash === '#page-ready'}), {status:200,headers:{'Content-Type':'application/json'}});
            };
        });
        await open('loading');
        await page.waitForFunction(() => window.__scanRequests.length === 30);
        await page.waitForTimeout(100);
        const requests = await page.evaluate(() => window.__scanRequests);
        assert.equal(requests.length,30); browserCount++;
        assert.ok(requests.every((request,index) => request.method === 'POST' && request.fields.action === 'advance_scan' && request.header === '1' && Number(request.fields.scan_cursor) === index+4)); browserCount++;
        assert.equal(await page.locator('[data-kb-scan-count]').innerText(),'34 / 51'); browserCount++;
        await open('loading_expanded');
        await page.waitForTimeout(100);
        assert.equal(await page.evaluate(() => window.__scanRequests.length),0); browserCount++;
        await open('loading');
        await page.locator('input[name="search_description"]').fill('Editing filters');
        await page.waitForTimeout(50);
        const pausedCount = await page.evaluate(() => window.__scanRequests.length);
        await page.waitForTimeout(100);
        assert.equal(await page.evaluate(() => window.__scanRequests.length),pausedCount); browserCount++;

        await open('loading','#scan-changed');
        await page.waitForFunction(() => window.__scanRequests.length === 1);
        await page.waitForTimeout(100);
        assert.equal(await page.evaluate(() => window.__scanRequests.length),1); browserCount++;
        assert.equal(await page.locator('[data-kb-scan-message]').innerText(),await page.locator('.kb-wl-scan').getAttribute('data-changed')); browserCount++;
        await open('loading','#scan-generation');
        await page.waitForFunction(() => window.__scanRequests.length === 1);
        await page.waitForTimeout(100);
        assert.equal(await page.evaluate(() => window.__scanRequests.length),1); browserCount++;
        assert.equal(await page.locator('[data-kb-scan-count]').innerText(),'4 / 51'); browserCount++;
        await open('loading','#scan-failed');
        await page.waitForFunction(() => window.__scanRequests.length === 1);
        await page.waitForTimeout(100);
        assert.equal(await page.evaluate(() => window.__scanRequests.length),1); browserCount++;
        assert.equal(await page.locator('input[name="scan_cursor"]').inputValue(),'5'); browserCount++;
        await page.getByRole('button',{name:'Continue',exact:true}).click();
        await page.waitForFunction(() => window.__scanRequests.length === 2);
        assert.equal(await page.evaluate(() => window.__scanRequests[1].fields.scan_cursor),'5'); browserCount++;
        await open('loading_ready','#page-ready');
        await page.waitForTimeout(100);
        assert.equal(await page.evaluate(() => window.__scanRequests.length),0); browserCount++;
        assert.equal(await page.locator('.kb-wl-scan').getAttribute('data-page-ready'),'1'); browserCount++;
        assert.equal(await page.locator('[data-kb-scan-count]').innerText(),'25 / 25'); browserCount++;
        await page.getByRole('button',{name:'More',exact:true}).click();
        assert.ok((await page.locator('#fixture-submit').innerText()).includes('more_scan')); browserCount++;
        assert.equal(await page.evaluate(() => window.__scanRequests.length),0); browserCount++;

        page = await browser.newPage({viewport:{width:1440,height:1000},locale:'pt-PT'});
        page.on('pageerror', error => errors.push(error.message));
        await open('batch-pt_PT');
        assert.equal(await page.locator('html').getAttribute('lang'),'pt-PT'); browserCount++;
        assert.equal(await page.getByRole('button',{name:'Guardar rascunho',exact:true}).count(),1); browserCount++;
        assert.equal(await page.locator('[data-kb-batch-feedback]').innerText(),'Pronto'); browserCount++;
        assert.equal(await page.locator('[data-kb-selected-total]').innerText(),'−3 300,00 EUR'); browserCount++;
        assert.equal(await page.locator('thead th').nth(1).innerText().then(text=>text.replace(/[↕↑↓]/g,'').trim()),'Banco do extrato'); browserCount++;
        assert.equal(await page.locator('thead th').last().innerText(),'Ações'); browserCount++;
        await page.screenshot({path:path.join(directory,'worklist-desktop-pt_PT.png'),fullPage:true});
        await page.setViewportSize({width:390,height:844});
        await page.screenshot({path:path.join(directory,'worklist-mobile-pt_PT.png'),fullPage:true});
        assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)); browserCount++;
        await page.setViewportSize({width:1280,height:900});
        await open('direct_correction-pt_PT');
        await page.getByRole('button',{name:'PAYMENT-BANK',exact:true}).click();
        assert.equal(await page.getByRole('dialog').isVisible(),true); browserCount++;
        assert.equal(await page.getByRole('button',{name:'Guardar',exact:true}).count(),1); browserCount++;
        await page.screenshot({path:path.join(directory,'worklist-account-dialog-pt_PT.png'),fullPage:true});

        // Reproduce the installed native table-cell shell and its button selector specificity.
        await open('batch-pt_PT');
        await page.evaluate(() => {
            const shell = document.querySelector('.fixture-shell');
            shell.className = 'native-shell'; shell.id = 'id-container';
            document.querySelector('aside').id = 'id-left';
            document.querySelector('main').id = 'id-right';
            document.querySelector('.kb-wl-table').style.minWidth = '1403px';
            const native = document.createElement('style');
            native.textContent = '#id-container{display:table;width:100%}#id-right,#id-left{display:table-cell;float:none;vertical-align:top}#id-left{width:210px;min-width:210px;box-sizing:border-box}#id-right{width:100%;padding:20px 28px}.button:not(.bordertransp):not(.buttonpayment){display:inline-block;min-width:90px;padding:8px 15px}@media(max-width:700px){#id-container{table-layout:fixed}#id-left{display:none}#id-right{padding:16px 12px}}';
            document.head.prepend(native);
        });
        const baseline = await page.addStyleTag({content:'.kb-worklist .div-table-responsive{width:auto;min-width:0}'});
        const before = await page.evaluate(() => ({viewport:innerWidth,document:document.documentElement.scrollWidth}));
        assert.ok(before.document > before.viewport, 'Native automatic table layout must reproduce the original overflow'); browserCount++;
        await baseline.evaluate(style => style.remove());
        const native = await page.evaluate(() => {
            const wrapper = document.querySelector('.kb-worklist .div-table-responsive');
            const main = document.querySelector('#id-right').getBoundingClientRect();
            const left = document.querySelector('#id-left').getBoundingClientRect();
            const icon = getComputedStyle(document.querySelector('.kb-wl-actions .kb-wl-icon'));
            return {viewport:innerWidth,document:document.documentElement.scrollWidth,main:main.width,left:left.width,scroll:wrapper.scrollWidth,width:wrapper.clientWidth,iconWidth:icon.width,iconMin:icon.minWidth,iconDisplay:icon.display};
        });
        assert.ok(native.document <= native.viewport, JSON.stringify(native)); browserCount++;
        assert.ok(native.left >= 210 && native.main <= native.viewport-native.left+1, 'Native left menu retains its column'); browserCount++;
        assert.ok(native.scroll > native.width, 'The list scrolls inside its own constrained wrapper'); browserCount++;
        assert.deepEqual([native.iconWidth,native.iconMin,native.iconDisplay],['30px','30px','inline-flex']); browserCount++;
        await page.locator('.kb-worklist .div-table-responsive').evaluate(wrapper => { wrapper.scrollLeft = wrapper.scrollWidth; });
        assert.ok(await page.locator('.kb-wl-actions').evaluate(cell => { const row=cell.getBoundingClientRect(),wrapper=cell.closest('.div-table-responsive').getBoundingClientRect();return row.left>=wrapper.left-1 && row.right<=wrapper.right+1; }), 'End-of-row actions remain reachable by table scrolling'); browserCount++;
        assert.ok(await page.locator('.fixture-pager').evaluate(pager => pager.getBoundingClientRect().right<=innerWidth+1), 'Pagination remains in the available content width'); browserCount++;
        await page.screenshot({path:path.join(directory,'worklist-native-shell-desktop.png'),fullPage:true});
        await page.setViewportSize({width:390,height:844});
        assert.ok(await page.evaluate(() => document.documentElement.scrollWidth<=innerWidth), 'Native responsive shell stays within the mobile viewport'); browserCount++;
        assert.equal(await page.locator('#id-left').isVisible(),false); browserCount++;
        await page.locator('.kb-worklist .div-table-responsive').evaluate(wrapper => { wrapper.scrollLeft = wrapper.scrollWidth; });
        assert.ok(await page.locator('.kb-wl-actions').evaluate(cell => { const row=cell.getBoundingClientRect(),wrapper=cell.closest('.div-table-responsive').getBoundingClientRect();return row.left>=wrapper.left-1 && row.right<=wrapper.right+1; }), 'Mobile end-of-row actions remain reachable'); browserCount++;
        await page.locator('.kb-worklist .div-table-responsive').evaluate(wrapper => { wrapper.scrollLeft = 0; });
        await page.screenshot({path:path.join(directory,'worklist-native-shell-mobile.png'),fullPage:true});
        console.log(`Native shell overflow: ${before.document}px -> ${native.document}px at ${native.viewport}px; table ${native.scroll}px in ${native.width}px; icons ${native.iconWidth}`);

        assert.deepEqual(errors, []); browserCount++;
        console.log(`OK: ${browserCount} Chromium worklist interactions; screenshots saved in ${directory}`);
    } finally { await browser.close(); }
}
/** Real reload/GET/POST/303 cycles use a disposable loopback-only synthetic server. */
async function navigationBrowserChecks() {
    const fs = require('node:fs');
    const path = require('node:path');
    const http = require('node:http');
    const {chromium} = require('playwright');
    const script = fs.readFileSync(path.join(__dirname, '../js/worklist.js'), 'utf8');
    let app;
    const storageKey = 'kreabank.worklist.position.v1';
    const browser = await chromium.launch({executablePath:process.env.KREABANK_CHROMIUM || '/usr/bin/chromium', headless:true, args:['--no-sandbox']});
    const page = await browser.newPage({viewport:{width:1280,height:900}});
    page.setDefaultTimeout(5000);
    const errors = [];
    const posts = [];
    const removed = new Set();
    let draft = '';
    let analysed = false;
    let scanRequests = 0;
    let count = 0;
    page.on('pageerror', error => errors.push(error.message));
    const escape = value => String(value).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    const render = url => {
        const input = url.searchParams;
        const number = input.has('button_search') ? 0 : Math.max(0, Number(input.get('pageplusone') || 1) - 1 || Number(input.get('page') || 0));
        const state = new URLSearchParams({page:String(number),company:input.get('company') || 'one',sortorder:input.get('sortorder') || 'DESC',limit:input.get('limit') || '24',search_description:input.get('search_description') || ''});
        const href = changes => {
            const target = new URLSearchParams(state);
            for (const [key,value] of Object.entries(changes)) if (value !== null) target.set(key,value);
            return escape(app + '?' + target);
        };
        const hidden = extra => [...state,...Object.entries(extra)].map(([name,value]) => '<input type="hidden" name="' + escape(name) + '" value="' + escape(value) + '">').join('');
        const expanded = input.get('expand');
        const mode = input.get('mode') || 'search';
        const context = JSON.stringify([state.get('sortorder'),state.get('limit'),state.get('search_description')]);
        let forms = '';
        let html = '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>body{margin:0;padding-top:80px;font:16px Arial}#id-top{position:fixed;inset:0 0 auto;height:60px;background:#ddd;z-index:5}.kb-worklist{margin:16px}.spacer{height:260px}.bottom{height:1000px}.div-table-responsive{overflow:auto}table{border-collapse:collapse;min-width:1000px;width:100%}.kb-wl-row{height:90px}td{border-bottom:1px solid #ddd;padding:10px}a,button{display:inline-block;margin:4px;padding:8px}dialog{max-width:400px}.kb-wl-detail{padding:16px}.kb-wl-dialog:not([open]){display:none}</style><div id="id-top">Native fixed header</div><div class="kb-worklist" data-navigation-scope="fixture-' + escape(state.get('company')) + '" data-navigation-context="' + escape(context) + '" data-navigation-page="' + number + '">';
        html += '<form id="searchFormList" method="get" action="' + app + '"><input name="company" type="hidden" value="' + escape(state.get('company')) + '"><input name="page" type="hidden" value="' + number + '"><input name="sortorder" type="hidden" value="' + escape(state.get('sortorder')) + '"><input name="search_description" aria-label="Filter" value="' + escape(state.get('search_description')) + '"><input name="pageplusone" aria-label="Page" value="' + (number+1) + '"><select name="limit" aria-label="Page size"><option>24</option><option>12</option></select><button name="button_search" value="1">Filter</button><a id="sort" href="' + href({sortorder:'ASC'}) + '">Sort</a><a id="next" href="' + href({page:String(number+1)}) + '">Next page</a><div class="spacer"></div><div class="div-table-responsive"><table class="kb-wl-table"><tbody>';
        const ids = Array.from({length:60},(_,index)=>index+1).filter(id=>!removed.has(id)).slice(number*24,number*24+24);
        for (const id of ids) {
            const key = 'staged:' + id;
            const marker = ' data-kb-row-navigation="' + key + '"';
            const base = {expand:key,mode};
            html += '<tr class="kb-wl-row" data-line-key="' + key + '" id="row-' + id + '"><td>' + key + '</td><td>125.12345678 EUR</td><td class="kb-wl-actions"><a aria-label="Search ' + id + '"' + marker + ' href="' + href({expand:key,mode:'search'}) + '">Search</a><a aria-label="Batch ' + id + '"' + marker + ' href="' + href({expand:key,mode:'batch'}) + '">Batch</a><button type="submit" aria-label="Approve ' + id + '" form="approve-' + id + '">Approve</button><button type="button" aria-label="Correct ' + id + '" data-kb-dialog="dialog-' + id + '">Bank</button></td></tr>';
            forms += '<form method="post" action="' + app + '" id="approve-' + id + '">' + hidden({action:'confirm_pair',row:key,token:'fixture-token',review:'fixture-review'}) + '</form>';
            forms += '<form method="post" action="' + app + '" id="correct-' + id + '">' + hidden({action:'correct_account',row:key,token:'fixture-token',review:'fixture-correction'}) + '</form><dialog class="kb-wl-dialog" id="dialog-' + id + '"><button type="button" data-kb-dialog-close autofocus>Cancel dialog</button><button type="submit" form="correct-' + id + '">Save correction</button></dialog>';
            if (expanded !== key) continue;
            html += '<tr class="kb-wl-detail-row" data-line-key="' + key + '"><td colspan="3"><section class="kb-wl-detail"><div class="kb-wl-detail-heading">' + mode + '<a aria-label="Close"' + marker + ' href="' + href({}) + '">X</a></div><input type="search" id="query-' + id + '" name="q" form="search-' + id + '" value="' + escape(input.get('q') || '') + '"><button type="submit" form="search-' + id + '">Search entries</button>';
            if (mode === 'batch') html += '<input id="name-' + id + '" name="batch_name" form="batch-' + id + '" value="' + escape(draft) + '"><button type="submit" form="batch-' + id + '">Save draft</button><a aria-label="Cancel batch"' + marker + ' href="' + href({}) + '">Cancel</a>';
            html += '</section></td></tr>';
            forms += '<form method="get" action="' + app + '" id="search-' + id + '">' + hidden(base) + '</form><form method="post" action="' + app + '" id="batch-' + id + '">' + hidden({action:'save_draft',row:key,expand:key,mode:'batch',token:'fixture-token'}) + '</form>';
        }
        html += '</tbody></table></div></form>' + forms;
        if (input.has('scan') && !analysed) html += '<div class="kb-wl-scan" data-page-ready="0" data-ready="Ready" data-failed="Failed" data-changed="Changed" data-paused="Paused"><span data-kb-scan-message>Analysing</span><span data-kb-scan-count>0 / 24</span></div><form data-kb-scan method="post" action="' + app + '?scan=1"><input name="action" value="advance_scan"><input name="scan_generation" value="fixture-generation"><input name="scan_cursor" value="0"></form>';
        return html + '</div><div class="bottom"></div><script>' + script + '</script>';
    };
    const server = http.createServer(async (request, response) => {
        const url = new URL(request.url,app);
        if (url.pathname !== new URL(app).pathname) { response.writeHead(404); response.end(); return; }
        if (request.method === 'POST') {
            let body = '';
            for await (const chunk of request) body += chunk;
            const fields = Object.fromEntries(await new Response(body,{headers:{'Content-Type':request.headers['content-type']}}).formData());
            if (fields.action === 'advance_scan') {
                scanRequests++;
                await new Promise(resolve => setTimeout(resolve,300));
                analysed = true;
                response.writeHead(200,{'Content-Type':'application/json'});
                response.end(JSON.stringify({done:true,page_ready:true,processed:24,total:24,generation:'fixture-generation',cursor:1})); return;
            }
            posts.push(fields);
            if (fields.action === 'confirm_pair') removed.add(Number(fields.row.split(':')[1]));
            if (fields.action === 'save_draft') draft = fields.batch_name;
            const target = new URL(app);
            for (const key of ['company','sortorder','limit','page','search_description','expand','mode']) if (fields[key] !== undefined) target.searchParams.set(key,fields[key]);
            response.writeHead(303,{Location:target.href}); response.end(); return;
        }
        response.writeHead(200,{'Content-Type':'text/html','Cache-Control':'no-store'}); response.end(render(url));
    });
    await new Promise(resolve => server.listen(0,'127.0.0.1',resolve));
    app = 'http://127.0.0.1:'+server.address().port+'/reconciliationlist.php';
    await page.route('**/*', route => new URL(route.request().url()).origin === new URL(app).origin ? route.continue() : route.abort());
    const row = id => page.locator('.kb-wl-row[data-line-key="staged:' + id + '"]');
    const offset = id => row(id).evaluate(element => element.getBoundingClientRect().top);
    const place = async (id,top=180) => row(id).evaluate((element,y) => window.scrollBy(0,element.getBoundingClientRect().top-y),top);
    const cycle = async action => { await Promise.all([page.waitForEvent('load'),action()]); await page.waitForTimeout(100); };
    const sameOffset = async (id,before,label) => { assert.ok(Math.abs(await offset(id)-before)<3,label + ': ' + await offset(id) + ' vs ' + before); count++; };
    const seed = async changes => page.evaluate(({storageKey,changes}) => {
        const root = document.querySelector('.kb-worklist');
        sessionStorage.setItem(storageKey,JSON.stringify({scope:root.dataset.navigationScope,context:root.dataset.navigationContext,page:Number(root.dataset.navigationPage),row:'staged:12',index:11,offset:180,left:0,time:Date.now(),kind:'row',keyboard:false,focusId:'',...changes}));
    },{storageKey,changes});
    try {
        await page.goto(app);
        for (const mode of ['Search','Batch']) {
            await place(12);
            let before = await offset(12);
            await cycle(()=>page.getByRole('link',{name:mode+' 12',exact:true}).click());
            await sameOffset(12,before,mode+' opens at its parent row');
            assert.equal(await page.evaluate(key=>sessionStorage.getItem(key),storageKey),null); count++;
            before = await offset(12);
            await cycle(()=>page.getByRole('link',{name:'Close',exact:true}).click());
            await sameOffset(12,before,mode+' X closes at its parent row');
        }
        await place(12);
        await cycle(()=>page.getByRole('link',{name:'Search 12',exact:true}).click());
        await page.locator('#query-12').fill('Synthetic lookup');
        let before = await offset(12);
        await cycle(()=>page.locator('#query-12').press('Enter'));
        await sameOffset(12,before,'Inline search keeps its source row');
        assert.equal(await page.locator('#query-12').inputValue(),'Synthetic lookup'); count++;
        assert.equal(await page.locator('#query-12').evaluate(input=>document.activeElement===input),true); count++;
        await cycle(()=>page.getByRole('link',{name:'Close',exact:true}).click());
        await cycle(()=>page.getByRole('link',{name:'Batch 12',exact:true}).click());
        await page.locator('#name-12').fill('Synthetic draft');
        before = await offset(12);
        await cycle(()=>page.getByRole('button',{name:'Save draft',exact:true}).click());
        await sameOffset(12,before,'Native draft POST/redirect keeps its source row');
        assert.equal(await page.locator('#name-12').inputValue(),'Synthetic draft'); count++;
        await cycle(()=>page.getByRole('link',{name:'Cancel batch',exact:true}).click());
        await sameOffset(12,before,'Batch Cancel keeps its source row');
        await page.getByRole('button',{name:'Correct 12',exact:true}).click();
        before = await offset(12);
        await cycle(()=>page.getByRole('button',{name:'Save correction',exact:true}).click());
        await sameOffset(12,before,'Dialog POST uses the parent row of its opener');
        await page.getByRole('button',{name:'Approve 12',exact:true}).focus();
        before = await offset(12);
        await cycle(()=>page.getByRole('button',{name:'Approve 12',exact:true}).press('Enter'));
        assert.equal(await row(12).count(),0); count++;
        await sameOffset(13,before,'Approval removal selects the nearest surviving row');
        assert.equal(await row(13).evaluate(element=>document.activeElement===element),true); count++;
        assert.deepEqual(posts.map(post=>post.action),['save_draft','correct_account','confirm_pair']); count++;
        assert.ok(posts.every(post=>post.token==='fixture-token')); count++;

        await page.evaluate(()=>scrollTo(0,0));
        await seed({});
        await cycle(()=>page.getByRole('link',{name:'Next page',exact:true}).click());
        assert.equal(await page.evaluate(()=>scrollY),0); count++;
        await seed({page:1,row:'staged:30',index:5});
        await cycle(()=>page.getByRole('link',{name:'Sort',exact:true}).click());
        assert.equal(await page.evaluate(()=>scrollY),0); count++;
        await seed({});
        await page.getByRole('textbox',{name:'Filter',exact:true}).fill('New filter');
        await cycle(()=>page.getByRole('button',{name:'Filter',exact:true}).click());
        assert.equal(await page.evaluate(()=>scrollY),0); count++;
        assert.equal(await page.evaluate(key=>sessionStorage.getItem(key),storageKey),null); count++;
        await seed({});
        await page.evaluate(key=>document.addEventListener('submit',event=>{
            window.__headerPosition=sessionStorage.getItem(key);
            event.preventDefault();
        },{once:true}),storageKey);
        await page.getByRole('button',{name:'Filter',exact:true}).click();
        assert.equal(await page.evaluate(()=>window.__headerPosition),null,'The header form cannot borrow a row-owned dialog button as its navigation context'); count++;

        await page.goto(app);
        await seed({});
        await cycle(()=>page.goto(app+'?company=two'));
        assert.equal(await page.evaluate(()=>scrollY),0); count++;
        await seed({time:Date.now()-120001});
        await cycle(()=>page.reload());
        assert.equal(await page.evaluate(()=>scrollY),0); count++;
        await seed({page:3,kind:'refresh'});
        await cycle(()=>page.reload());
        assert.equal(await page.evaluate(()=>scrollY),0); count++;

        await page.goto(app);
        await place(13,30);
        await cycle(()=>page.getByRole('link',{name:'Search 13',exact:true}).click());
        assert.ok(await offset(13)>=68); count++;
        await cycle(()=>page.getByRole('link',{name:'Close',exact:true}).click());
        await page.setViewportSize({width:500,height:900});
        await page.locator('.div-table-responsive').evaluate(wrapper=>{wrapper.scrollLeft=350;});
        await place(13);
        await cycle(()=>page.getByRole('link',{name:'Search 13',exact:true}).evaluate(link=>link.click()));
        assert.equal(await page.locator('.div-table-responsive').evaluate(wrapper=>wrapper.scrollLeft),350); count++;
        await cycle(()=>page.getByRole('link',{name:'Close',exact:true}).evaluate(link=>link.click()));
        assert.equal(await page.locator('.div-table-responsive').evaluate(wrapper=>wrapper.scrollLeft),350); count++;
        await page.setViewportSize({width:1280,height:900});
        await page.evaluate(key=>{
            document.addEventListener('click',event=>{
                if(!event.target.closest('a[data-kb-row-navigation]')) return;
                window.__positionSnapshot=JSON.parse(sessionStorage.getItem(key));
                event.preventDefault();
            },{once:true});
        },storageKey);
        await page.getByRole('link',{name:'Search 13',exact:true}).click();
        await page.waitForTimeout(30);
        const snapshot = await page.evaluate(()=>window.__positionSnapshot);
        assert.deepEqual(Object.keys(snapshot).sort(),['context','focusId','index','keyboard','kind','left','offset','page','row','scope','time']); count++;
        assert.equal(await page.evaluate(key=>sessionStorage.getItem(key),storageKey),null); count++;

        await page.goto(app+'?scan=1');
        await place(13);
        before = await offset(13);
        await page.waitForEvent('load');
        await page.waitForTimeout(100);
        await sameOffset(13,before,'Read-only scan refresh retains the visible row');
        assert.equal(scanRequests,1); count++;
        assert.equal(posts.length,3,'Scanning never submits a financial action'); count++;
        assert.deepEqual(errors,[]); count++;
        console.log('OK: '+count+' isolated Chromium row navigation checks (synthetic routes only)');
    } catch (error) {
        console.error({url:page.url(),posts,errors,body:(await page.locator('body').innerText().catch(()=>'' )).slice(0,300)});
        throw error;
    } finally { await browser.close(); await new Promise(resolve=>server.close(resolve)); }
}
if (process.argv[2] === '--navigation-browser') {
    navigationBrowserChecks().catch(error => { console.error(error); process.exitCode = 1; });
} else if (process.argv[2] === '--browser') {
    browserChecks(process.argv[3]).catch(error => { console.error(error); process.exitCode = 1; });
}
