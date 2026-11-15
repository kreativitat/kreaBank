/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later */
'use strict';
const assert = require('node:assert/strict');
const {initialise} = require('../js/worklist.js');
let checks = 0;
const settle = () => new Promise(resolve => setTimeout(resolve,0));
function harness(scanOptions, paymentAction = 'add_paiement', deferFrame = false) {
    const handlers = () => ({events:new Map(),addEventListener(name,fn){const list=this.events.get(name)||[];list.push(fn);this.events.set(name,list);},removeEventListener(name,fn){this.events.set(name,(this.events.get(name)||[]).filter(x=>x!==fn));},emit(name,event){for(const fn of this.events.get(name)||[])fn(event);}});
    const assigned=[]; let reloads=0,focused=0,nativeCloses=0;
    global.window={location:{href:'https://bank.example.test/reconciliationlist.php?search_account=101',origin:'https://bank.example.test',pathname:'/reconciliationlist.php',assign:url=>assigned.push(url),reload:()=>{reloads++;}},addEventListener(){}};
    const name='kbwlpay'+ 'a'.repeat(16);
    const doc=handlers(), frame=Object.assign(handlers(),{id:'iframedialog'+name,contentDocument:doc});
    const nativeDialog={id:'idfordialog'+name,contains:node=>node===frame};
    let frameAvailable=!deferFrame;
    global.document={getElementById:id=>!frameAvailable?null:id===frame.id ? frame : id===nativeDialog.id ? nativeDialog : null};
    const popup={dataset:{lineKey:'staged:11',kbPaymentFrame:frame.id,kbPaymentRefresh:'https://bank.example.test/reconciliationlist.php?refresh_payment=staged:11&search_account=101'},closest:()=>popup};
    const opener={isConnected:true,closest:selector=>selector==='a.kb-wl-record-payment'?opener:popup,focus(){focused++;}};
    const form=Object.assign(handlers(),{elements:{action:{value:'advance_scan'},scan_generation:{value:'generation'},scan_cursor:{value:'0'}},getAttribute:()=>'/reconciliationlist.php'});
    const parts={};
    const scan={dataset:{autoScan:'0',pageReady:'0',paused:'Paused',ready:'Ready',failed:'Failed',changed:'Changed',...scanOptions},querySelector:key=>parts[key]||(parts[key]={})};
    const root=Object.assign(handlers(),{dataset:{locale:'en-US'},hasAttribute:()=>false,contains:node=>node===popup||node===opener,querySelectorAll:()=>[],querySelector:selector=>scanOptions?(selector==='form[data-kb-scan]'?form:selector==='.kb-wl-scan'?scan:null):null});
    window.jQuery=element=>({dialog(action){assert.equal(element,nativeDialog);assert.equal(action,'close');nativeCloses++;root.emit('kb-worklist-payment-close',{target:popup});}});
    initialise(root);
    return {root,popup,doc,frame,form,scan,nativeDialog,assigned,get reloads(){return reloads;},get focused(){return focused;},get nativeCloses(){return nativeCloses;},
        async open(){root.emit('click',{target:opener});if(deferFrame){await Promise.resolve();frameAvailable=true;}await new Promise(resolve=>setTimeout(resolve,0));await settle();},
        submit(cancel=false,prevented=false){const event={target:{method:'POST',elements:{action:{value:paymentAction}}},submitter:{name:cancel?'cancel':'save',tagName:'INPUT',type:'submit'},defaultPrevented:prevented,preventDefault(){this.defaultPrevented=true;}};doc.emit('submit',event);return event;},
        clickCancel(){const control={name:'cancel',type:'submit',form:{method:'POST',elements:{action:{value:paymentAction}}}};control.closest=()=>control;
            const event={target:control,defaultPrevented:false,preventDefault(){this.defaultPrevented=true;}};doc.emit('click',event);return event;},
        close(){root.emit('kb-worklist-payment-close',{target:popup});}};
}
async function main() {
    for (const action of ['add_paiement','confirm_paiement','add_payment']) {
        for (const close of ['X','Escape','Cancel']) {
            const h=harness(undefined,action);await h.open();
            if(close==='Cancel') {
                const cancel=h.submit(true);
                assert.equal(cancel.defaultPrevented,true,action+': native submit Cancel is prevented');checks++;
                assert.equal(h.nativeCloses,1,'Native Cancel closes exactly the dialog containing this iframe');checks++;
            }
            h.close();await settle();
            assert.equal(h.assigned.length,0,action+': '+close+' does not navigate or refresh');checks++;
            assert.equal(h.reloads,0,action+': '+close+' does not restart analysis');checks++;
            assert.equal(h.focused,1,'Focus returns without scrolling');checks++;
            assert.equal((h.doc.events.get('submit')||[]).length,0,'Frame listeners are released');checks++;
            h.close();assert.equal(h.focused,1,'Duplicate close is ignored');checks++;
        }
        const saved=harness(undefined,action);await saved.open();saved.submit();saved.close();saved.close();
        assert.equal(saved.assigned.length,1,action+': a native POST causes exactly one owned row refresh');checks++;
        assert.equal(new URL(saved.assigned[0]).searchParams.get('refresh_payment'),'staged:11');checks++;
        const prevented=harness(undefined,action);await prevented.open();prevented.frame.emit('load',{});
        assert.equal((prevented.doc.events.get('submit')||[]).length,1,'Repeated frame loads bind once per document');checks++;
        prevented.submit(false,true);await settle();prevented.close();
        assert.equal(prevented.assigned.length,0,action+': prevented submissions do not cause refresh');checks++;
        const latePrevented=harness(undefined,action);await latePrevented.open();
        const event=latePrevented.submit();event.defaultPrevented=true;await settle();latePrevented.close();
        assert.equal(latePrevented.assigned.length,0,action+': native validation can prevent the POST after capture');checks++;
        const bubblingPrevented=harness(undefined,action);await bubblingPrevented.open();
        const bubblingEvent=bubblingPrevented.submit();await Promise.resolve();bubblingEvent.preventDefault();await settle();bubblingPrevented.submit(true);
        assert.equal(bubblingPrevented.assigned.length,0,action+': bubbling native validation after a capture microtask cannot cause a refresh');checks++;
        const attempted=harness(undefined,action);await attempted.open();attempted.submit();await settle();attempted.submit(true);attempted.close();
        assert.equal(attempted.nativeCloses,1,action+': Cancel closes after an earlier genuine POST attempt');checks++;
        assert.equal(attempted.assigned.length,1,'Cancel preserves the unknown outcome of a prior attempt and refreshes exactly once');checks++;
        const cancelledValidation=harness(undefined,action);await cancelledValidation.open();cancelledValidation.submit(false,true);await settle();cancelledValidation.submit(true);
        assert.equal(cancelledValidation.assigned.length,0,'Cancel after a prevented payment attempt still performs no refresh');checks++;
        for(const preventedFirst of [true,false]) {
            const mixed=harness(undefined,action);await mixed.open();mixed.submit(false,preventedFirst);mixed.submit(false,!preventedFirst);await settle();mixed.submit(true);
            assert.equal(mixed.assigned.length,1,'A prevented submit cannot erase another genuine attempt in either event order');checks++;
        }
        const allPrevented=harness(undefined,action);await allPrevented.open();allPrevented.submit(false,true);allPrevented.submit(false,true);allPrevented.submit(true);
        assert.equal(allPrevented.assigned.length,0,'Two prevented submissions followed by immediate Cancel cause no refresh');checks++;
        const clickedCancel=harness(undefined,action);await clickedCancel.open();
        assert.equal(clickedCancel.clickCancel().defaultPrevented,true,'Cancel click is intercepted before native constraint validation');checks++;
        assert.equal(clickedCancel.nativeCloses,1,'Cancel click closes its exact native dialog without a submit event');checks++;
        assert.equal(clickedCancel.assigned.length,0,'Cancel click causes no row refresh');checks++;
        assert.equal((clickedCancel.doc.events.get('click')||[]).length,0,'Cancel click releases the frame click listener');checks++;
    }
    const objectSaved=harness(undefined,'add_payment');await objectSaved.open();objectSaved.submit();objectSaved.submit();
    // Native salary/tax success redirects to a receipt GET before the popup closes.
    objectSaved.frame.contentDocument={addEventListener(){},removeEventListener(){}};objectSaved.frame.emit('load',{});
    objectSaved.close();objectSaved.close();
    assert.equal(objectSaved.assigned.length,1,'Repeated direct native submissions and receipt navigation still refresh once on close');checks++;
    assert.equal(objectSaved.reloads,0,'The direct native receipt close does not restart list analysis');checks++;
    assert.equal((objectSaved.doc.events.get('submit')||[]).length,0,'Receipt close releases listeners from the original payment document');checks++;
    const deferred=harness(undefined,'add_paiement',true);await deferred.open();
    assert.equal((deferred.doc.events.get('submit')||[]).length,1,'The iframe is watched when native creation occurs after a capture-listener microtask checkpoint');checks++;
    deferred.submit(true);
    assert.equal(deferred.nativeCloses,1,'Delayed native iframe creation still intercepts submit Cancel');checks++;
    assert.equal(deferred.assigned.length,0,'Delayed iframe cancellation causes no refresh');checks++;
    const unrelated=harness(undefined,'create');await unrelated.open();unrelated.submit();unrelated.close();
    assert.equal(unrelated.assigned.length,0,'An unrelated iframe form action cannot request a payment refresh');checks++;
    const unrelatedCancel=harness(undefined,'create');await unrelatedCancel.open();
    assert.equal(unrelatedCancel.submit(true).defaultPrevented,false,'Unrelated form cancellation is not intercepted');checks++;
    assert.equal(unrelatedCancel.nativeCloses,0,'Unrelated forms cannot close a payment dialog');checks++;
    assert.equal(unrelatedCancel.clickCancel().defaultPrevented,false,'Unrelated form Cancel clicks remain outside the payment handler');checks++;
    unrelatedCancel.close();
    const foreignFrame=harness();await foreignFrame.open();foreignFrame.nativeDialog.contains=()=>false;
    assert.equal(foreignFrame.submit(true).defaultPrevented,true,'Cancel never POSTs when native dialog ownership is unavailable');checks++;
    assert.equal(foreignFrame.nativeCloses,0,'A dialog not containing the watched iframe cannot be closed');checks++;
    foreignFrame.close();
    for (const url of ['https://other.example.test/reconciliationlist.php?refresh_payment=staged:11','https://bank.example.test/accounts.php?refresh_payment=staged:11','https://bank.example.test/reconciliationlist.php?refresh_payment=staged:12','https://bank.example.test/reconciliationlist.php?refresh_payment=staged:11&action=confirm_pair']) {
        const h=harness();h.popup.dataset.kbPaymentRefresh=url;await h.open();h.submit();h.close();
        assert.equal(h.assigned.length,0,'Unsafe or different-source refresh is denied');checks++;
    }
    global.FormData=class {constructor(form){this.form=form;}};
    let requests=0;
    global.fetch=async()=>{requests++;throw Error('Unexpected automatic scan');};
    const paused=harness({autoScan:'0'});await new Promise(resolve=>setTimeout(resolve,250));
    assert.equal(requests,0,'Payment return never automatically scans');checks++;
    global.fetch=async()=>({ok:true,status:200,json:async()=>({generation:'generation',cursor:++requests,processed:1,total:1,done:true,page_ready:true})});
    const ready=harness({autoScan:'0'});
    ready.form.emit('submit',{preventDefault(){}});await settle();
    assert.equal(ready.reloads,1,'Explicit analysis reloads newly ready results once');checks++;
    ready.form.emit('submit',{preventDefault(){}});await settle();
    assert.equal(ready.reloads,1,'Repeated done response cannot create a reload loop');checks++;
    requests=0;let aborted=0;
    global.fetch=(url,options)=>{requests++;return new Promise((resolve,reject)=>options.signal.addEventListener('abort',()=>{aborted++;reject(new DOMException('Aborted','AbortError'));}));};
    const pending=harness({autoScan:'1'});await new Promise(resolve=>setTimeout(resolve,225));
    await pending.open();pending.close();await new Promise(resolve=>setTimeout(resolve,650));
    assert.equal(aborted,1,'Opening payment aborts the in-flight advisory scan');checks++;
    assert.equal(requests,1,'Cancellation leaves analysis paused instead of scheduling another scan');checks++;
    assert.equal(pending.assigned.length+pending.reloads,0,'In-flight cancellation cannot navigate');checks++;
    console.log(`OK: ${checks} payment cancellation and scan lifecycle checks (synthetic, no browser/network)`);
}
main().catch(error=>{console.error(error);process.exitCode=1;});
