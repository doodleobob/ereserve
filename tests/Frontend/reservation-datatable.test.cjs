const {test}=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');

function setup(fetch) {
 const handlers={},dialogEvents={},buttons=[{disabled:false},{disabled:false}],error={hidden:true};
 const state={notices:[],refreshed:0,urls:[]};
 const root={dataset:{page:'1'},setAttribute(){},removeAttribute(){}};
 const form={id:'action',action:'https://ereserve.test/reservations/1/reject',getAttribute:()=> 'https://ereserve.test/reservations/1/reject',matches:()=>true,reportValidity:()=>true,closest:()=>dialog,querySelector:()=>error,reset(){}};
 const dialog={hasAttribute:()=>false,open:true,querySelector:selector=>selector==='[data-action-error]'?error:form,querySelectorAll:()=>buttons,addEventListener:(event,handler)=>dialogEvents[event]=handler,close(){this.open=false;dialogEvents.close();}};
 const next={querySelector:()=>root,querySelectorAll:()=>[]};
 const main={replaceWith(){state.refreshed++;}};
 const document={querySelector:selector=>selector==='main'?main:selector==='[data-reservation-table]'?root:null,querySelectorAll:()=>[dialog],createElement:()=>({dataset:{},setAttribute(){},remove(){}}),body:{appendChild:notice=>state.notices.push(notice)},addEventListener:(event,handler)=>handlers[event]=handler,dispatchEvent(){}};
 const window={location:{href:'https://ereserve.test/reservations?status=pending&sort=id&page=1'},history:{replaceState:(_,__,url)=>state.urls.push(String(url)),pushState:(_,__,url)=>state.urls.push(String(url))},addEventListener(){}};
 vm.runInNewContext(fs.readFileSync('public/js/reservation-datatable.js','utf8'),{document,window,fetch,FormData:class{constructor(source){assert.equal(source,form);}},DOMParser:class{parseFromString(){return {querySelector:()=>next};}},AbortController,URL,URLSearchParams,Event,setTimeout(){}});
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
