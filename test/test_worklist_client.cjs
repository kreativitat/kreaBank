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
        const opener = page.getByRole('button',{name:'Change bank',exact:true});
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
        await opener.click();
        await page.getByRole('button',{name:'Save',exact:true}).click();
        submission = await page.locator('#fixture-submit').innerText();
        assert.ok(submission.includes('correct_account') && submission.includes('synthetic-direct-review') && !submission.includes('confirm_pair')); browserCount++;

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
        await open('loading_ready','#page-ready');
        await page.waitForFunction(() => window.__scanRequests.length === 30);
        assert.equal(await page.locator('.kb-wl-scan').getAttribute('data-page-ready'),'1'); browserCount++;
        assert.equal(await page.locator('[data-kb-scan-count]').innerText(),'34 / 51'); browserCount++;

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
        await page.getByRole('button',{name:'Alterar banco',exact:true}).click();
        assert.equal(await page.getByRole('dialog').isVisible(),true); browserCount++;
        assert.equal(await page.getByRole('button',{name:'Guardar',exact:true}).count(),1); browserCount++;
        await page.screenshot({path:path.join(directory,'worklist-account-dialog-pt_PT.png'),fullPage:true});

        assert.deepEqual(errors, []); browserCount++;
        console.log(`OK: ${browserCount} Chromium worklist interactions; screenshots saved in ${directory}`);
    } finally { await browser.close(); }
}
if (process.argv[2] === '--browser') {
    browserChecks(process.argv[3]).catch(error => { console.error(error); process.exitCode = 1; });
}
