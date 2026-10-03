const {test}=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');

function setup(fetch,official=false) {
 const handlers={},dialogEvents={},buttons=[{disabled:false},{disabled:false,textContent:'Save'}],error={hidden:true},attributes=new Set();
 const state={notices:[],refreshed:0,urls:[]};
 const root={dataset:{page:'1'},setAttribute(){},removeAttribute(){}};
 const action=official?'https://ereserve.test/official-uses':'https://ereserve.test/reservations/1/reject';
 const form={id:'action',action,elements:[],querySelectorAll:()=>[],getAttribute:()=>action,matches:()=>true,reportValidity:()=>true,closest:()=>dialog,querySelector:selector=>selector==='button[type=submit]'?buttons[1]:selector==='[data-action-error]:not([hidden])'?(error.hidden?null:error):error,reset(){}};
 const dialog={id:'test-dialog',hasAttribute:name=>attributes.has(name),setAttribute:name=>attributes.add(name),removeAttribute:name=>attributes.delete(name),open:true,querySelector:selector=>selector==='[data-action-error]'?error:form,querySelectorAll:selector=>selector==='form'?[form]:selector==='[data-facility-photo-preview]'||selector==='input[type=file]'?[]:buttons,addEventListener:(event,handler)=>{const previous=dialogEvents[event];dialogEvents[event]=e=>{previous?.(e);handler(e);};},dispatchEvent:event=>dialogEvents[event.type]?.(event),close(){this.open=false;dialogEvents.close();}};
 const next={querySelector:()=>root,querySelectorAll:()=>[]};
 const main={replaceWith(){state.refreshed++;}};
 const selector=official?'[data-official-use-table]':'[data-reservation-table]';
 const document={documentElement:{classList:{toggle(){}}},querySelector:query=>query==='main'?main:query===selector?root:null,querySelectorAll:()=>[dialog],createElement:()=>({dataset:{},setAttribute(){},remove(){}}),body:{appendChild:notice=>state.notices.push(notice)},addEventListener:(event,handler)=>handlers[event]=handler,dispatchEvent(){}};
 const window={location:{href:'https://ereserve.test/reservations?status=pending&sort=id&page=1'},history:{replaceState:(_,__,url)=>state.urls.push(String(url)),pushState:(_,__,url)=>state.urls.push(String(url))},addEventListener(){}};
 vm.runInNewContext(fs.readFileSync('public/js/modals.js','utf8')+'\n'+fs.readFileSync('public/js/reservation-datatable.js','utf8'),{document,window,fetch,FormData:class{constructor(source){assert.equal(source,form);}},DOMParser:class{parseFromString(){return {querySelector:()=>next};}},AbortController,URL,URLSearchParams,Event,setTimeout(){}});
 return {form,dialog,buttons,error,state,dialogEvents,submit:()=>handlers.submit({target:form,preventDefault(){}})};
}

test('DataTable mutations disable actions, prevent duplicates and refresh with current filters',async()=>{
 let complete,calls=0;const ui=setup(async(url,options)=>{
  if(options.method==='POST'){calls++;return new Promise(resolve=>complete=resolve);}
  assert.equal(String(url),'https://ereserve.test/reservations?status=pending&sort=id&page=1');
  return {ok:true,redirected:false,text:async()=>'<main>updated</main>'};
 });
 const pending=ui.submit();await ui.submit();assert.equal(calls,1);assert.ok(ui.buttons.every(button=>button.disabled));
 let prevented=false;ui.dialogEvents.cancel({preventDefault(){prevented=true;}});assert.ok(prevented);
 complete({ok:true,json:async()=>({success:true,message:'Reservation rejected.'})});await pending;
 assert.equal(ui.dialog.open,false);assert.equal(ui.state.refreshed,1);assert.equal(ui.state.notices[0].textContent,'Reservation rejected.');assert.ok(ui.buttons.every(button=>!button.disabled));
 assert.ok(ui.state.urls[0].includes('status=pending&sort=id&page=1'));
});

test('DataTable displays server validation without closing the modal and allows retry',async()=>{
 let calls=0;const ui=setup(async()=>{calls++;return {ok:false,json:async()=>({errors:{reservation:['Only pending reservations can be rejected.']}})};});
 await ui.submit();assert.equal(ui.dialog.open,true);assert.equal(ui.error.hidden,false);assert.equal(ui.error.textContent,'Only pending reservations can be rejected.');assert.equal(ui.state.refreshed,0);assert.ok(ui.buttons.every(button=>!button.disabled));
 await ui.submit();assert.equal(calls,2);
});

test('DataTable distinguishes a saved mutation from a failed list refresh',async()=>{
 const ui=setup(async(_,options)=>options.method==='POST'?{ok:true,json:async()=>({success:true,message:'Saved.'})}:{ok:false});
 await ui.submit();assert.equal(ui.dialog.open,false);assert.equal(ui.state.refreshed,0);assert.match(ui.state.notices[0].textContent,/Reservation saved, but the table could not refresh/);
});

test('Official Use saves once, closes modal, refreshes filtered table and shows toast',async()=>{
 let complete,calls=0;
 const ui=setup(async(url,options)=>{
  if(options.method==='POST'){assert.equal(url,'https://ereserve.test/official-uses');calls++;return new Promise(resolve=>complete=resolve);}
  return {ok:true,redirected:false,text:async()=>'<main>official uses</main>'};
 },true);
 const pending=ui.submit();await ui.submit();assert.equal(calls,1);assert.ok(ui.buttons.every(button=>button.disabled));
 complete({ok:true,json:async()=>({success:true,message:'Official Use saved successfully.'})});await pending;
 assert.equal(ui.dialog.open,false);assert.equal(ui.state.refreshed,1);assert.equal(ui.state.notices[0].textContent,'Official Use saved successfully.');
});

test('Official Use overlap errors stay in modal and a saved result reports refresh failure',async()=>{
 const invalid=setup(async()=>({ok:false,json:async()=>({errors:{date:['This schedule overlaps an existing Official Use for this resource.']}})}),true);
 await invalid.submit();assert.equal(invalid.dialog.open,true);assert.match(invalid.error.textContent,/overlaps an existing Official Use/);assert.equal(invalid.state.refreshed,0);
 const saved=setup(async(_,options)=>options.method==='POST'?{ok:true,json:async()=>({success:true,message:'Saved.'})}:{ok:false},true);
 await saved.submit();assert.equal(saved.dialog.open,false);assert.match(saved.state.notices[0].textContent,/Official Use saved, but the table could not refresh/);
});
