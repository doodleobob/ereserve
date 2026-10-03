const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const {spawnSync}=require('node:child_process'),{pathToFileURL}=require('node:url');
const directory=path.resolve('storage/app/payment-ui-check');
const fixtures=Object.fromEntries(['empty','paid','refunded'].map(name=>[name,fs.readFileSync(path.join(directory,name+'.html'),'utf8')]));
const reports=Object.fromEntries(['pdf','xlsx','csv'].map(format=>[format,fs.readFileSync(path.join(directory,'report.'+format)).toString('base64')]));
const source='<!doctype html><html><head><meta name="viewport" content="width=device-width, initial-scale=1"><style>'+fs.readFileSync('public/css/app.css','utf8')+'</style></head><body class="user-page">'+fixtures.paid.match(/<main\b[^>]*>[\s\S]*?<\/main>/)[0].replace(/<script\b[^>]*>[\s\S]*?<\/script>/g,'')+'<script>'+fs.readFileSync('public/js/modals.js','utf8')+'\n'+fs.readFileSync('public/js/reservation-datatable.js','utf8')+'</script></body></html>';
const encode=value=>JSON.stringify(value).replace(/</g,'\\u003c');
const page=path.join(directory,'browser.html');
fs.writeFileSync(page,`<!doctype html><html><body><pre id="result">RUNNING</pre><script>
const source=${encode(source)},fixtures=${encode(fixtures)},reports=${encode(reports)};
Promise.all([1280,768,390,320].map(width=>new Promise(resolve=>{
 const frame=document.createElement('iframe');frame.style.width=width+'px';frame.style.height='900px';
 frame.onload=async()=>{try {
  const doc=frame.contentDocument,win=frame.contentWindow,check=(ok,message)=>{if(!ok)throw Error(message);},tick=()=>new Promise(resolve=>setTimeout(resolve,0));
  let posts=0,gets=0,downloads=0,complete,body,lastUrl='',mutation='',tableFixture=fixtures.refunded,downloadResult;
  const before=win.location.href;
  win.history.pushState=win.history.replaceState=(_,__,url)=>lastUrl=String(url);
  win.fetch=async(url,options={})=>{
   if(options.method==='POST'){posts++;body=options.body;mutation=String(url);return new Promise(resolve=>complete=resolve);}
   if(String(url).includes('/payments/export')){downloads++;lastUrl=String(url);return new Promise(resolve=>complete=resolve);}
   gets++;return {ok:true,redirected:false,text:async()=>tableFixture};
  };
  win.URL.createObjectURL=()=> 'blob:report';win.URL.revokeObjectURL=()=>{};
  doc.addEventListener('click',event=>{if(event.target.matches('a[download]')){event.preventDefault();downloadResult={name:event.target.download,url:event.target.href};}});
  check(doc.querySelectorAll('thead th').length===8,'Payments requires eight columns');
  check(doc.documentElement.scrollWidth<=width+1,'Page horizontal overflow');
  check(doc.querySelector('[data-payment-total]').textContent==='₱500.00','Summary must use reservation total');
  check(doc.querySelector('[data-payment-count]').textContent==='1','Summary count incorrect');
  check(JSON.stringify([...doc.querySelectorAll('.reservation-table-menu button')].map(b=>b.textContent.trim()))===JSON.stringify(['View','Edit']),'Expected only View/Edit');
  const open=kind=>{doc.querySelector('[data-reservation-open^="payment-'+kind+'-"]').click();const dialog=doc.querySelector('dialog[open]');check(dialog,'Same-page modal missing');const bounds=dialog.getBoundingClientRect();check(bounds.left>=0&&bounds.right<=width&&bounds.height<=900,'Modal outside viewport');return dialog;};
  let dialog=open('view');
  for(const label of ['Payment ID','Reservation ID','Resident','Resource','Reservation Date','Reservation Time','Total','Payment Status'])check([...dialog.querySelectorAll('dt')].some(dt=>dt.textContent===label),'Missing View field '+label);
  check(dialog.textContent.includes('Preview Resident')&&dialog.textContent.includes('₱500.00')&&dialog.textContent.includes('Paid'),'View relationships incorrect');
  check(!dialog.querySelector('form'),'View must be read-only');dialog.querySelector('[data-reservation-close]').click();
  dialog=open('edit');let form=dialog.querySelector('form');
  check(form.elements.payment_status.value==='paid','Edit must prefill current payment status');
  check(!form.querySelector('[name="total"]')&&!form.querySelector('[name="total_payment"]')&&!form.querySelector('[name="reservation_date"]'),'Reservation fields must be read-only');
  form.elements.payment_status.value='refunded';form.elements.receipt_confirmed.checked=true;
  form.requestSubmit();form.dispatchEvent(new win.Event('submit',{bubbles:true,cancelable:true}));
  check(posts===1,'Duplicate edit submitted');check(body.get('_method')==='PATCH'&&body.get('payment_status')==='refunded'&&mutation.endsWith('/1'),'PATCH existing payment missing');
  check([...dialog.querySelectorAll('button')].every(b=>b.disabled),'Edit buttons must disable during save');
  check(!dialog.dispatchEvent(new win.Event('cancel',{cancelable:true})),'Escape must not dismiss processing edit');
  complete({ok:false,json:async()=>({errors:{payment_status:['Transition rejected.']}})});await tick();await tick();
  check(dialog.open&&!form.querySelector('[data-action-error]').hidden&&form.elements.payment_status.value==='refunded','Validation must retain dialog and values');
  form.requestSubmit();check(posts===2,'Edit retry missing');complete({ok:true,json:async()=>({success:true,message:'Payment updated successfully.'})});await tick();await tick();
  check(gets===1&&!doc.querySelector('dialog[open]'),'Edit must close and refresh');
  check(doc.querySelector('tbody').textContent.includes('Refunded')&&doc.querySelector('[data-payment-total]').textContent==='₱500.00','Refreshed status/summary incorrect');
  check(doc.querySelector('.reservation-table-toast').textContent==='Payment updated successfully.','Edit toast missing');
  dialog=open('edit');check(dialog.querySelector('[name="payment_status"]').value==='refunded','Refreshed edit status incorrect');dialog.querySelector('[data-reservation-close]').click();
  doc.querySelector('[name="search"]').value='Preview Court';doc.querySelector('[name="status"]').value='refunded';doc.querySelector('#payment-filters').requestSubmit();await tick();await tick();
  check(lastUrl.includes('search=Preview+Court')&&lastUrl.includes('status=refunded'),'Filters must use AJAX');
  // Real downloaded bytes are supplied to the fetch transport; clicks are intercepted to keep the fixture on-page.
  for(const format of ['pdf','xlsx','csv']){
   const link=[...doc.querySelectorAll('[data-payment-download]')].find(a=>a.href.includes('format='+format));
   const prior=downloads;link.click();link.click();check(downloads===prior+1,'Duplicate report request');
   const bytes=Uint8Array.from(atob(reports[format]),c=>c.charCodeAt(0));const type={pdf:'application/pdf',xlsx:'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',csv:'text/csv'}[format];
   complete({ok:true,redirected:false,blob:async()=>new win.Blob([bytes],{type}),headers:{get:()=> 'attachment; filename="ereserve-payment-report.'+format+'"'}});await tick();await tick();
   check(downloadResult?.name==='ereserve-payment-report.'+format,'Report filename missing');
  }
  const link=doc.querySelector('[data-payment-download]');link.click();complete({ok:false,json:async()=>({message:'Download failed.'})});await tick();await tick();check(doc.querySelector('.reservation-table-toast').textContent==='Download failed.','Download error missing');
  doc.querySelector('[name="per_page"]').value='100';doc.querySelector('[name="per_page"]').dispatchEvent(new win.Event('change',{bubbles:true}));await tick();await tick();check(lastUrl.includes('per_page=100'),'Rows must use AJAX');
  doc.querySelector('th a').click();await tick();await tick();check(lastUrl.includes('sort=id'),'Sort must use AJAX');
  tableFixture=fixtures.empty;doc.querySelector('#payment-filters').requestSubmit();await tick();await tick();check(doc.querySelector('tbody').textContent.includes('No payment records found.'),'Empty state missing');
  check(doc.documentElement.scrollWidth<=width+1,'Refreshed page overflow');check(win.location.href===before,'Payments navigated away');
  resolve({width,passed:true});
 }catch(error){resolve({width,passed:false,error:error.message});}};
 frame.srcdoc=source;document.body.appendChild(frame);
}))).then(results=>document.getElementById('result').textContent=JSON.stringify(results));
</script></body></html>`);
const result=spawnSync('C:/Program Files/Google/Chrome/Application/chrome.exe',['--headless','--disable-gpu','--no-first-run','--no-default-browser-check','--user-data-dir='+path.join(directory,'chrome-'+process.pid),'--dump-dom','--virtual-time-budget=10000',pathToFileURL(page).href],{encoding:'utf8',maxBuffer:16*1024*1024,timeout:45000,windowsHide:true});
if(result.error)throw result.error;
const match=result.stdout.match(/<pre id="result">([^<]+)<\/pre>/);assert.ok(match,'Browser returned no test results');
const results=JSON.parse(match[1]);for(const row of results)assert.equal(row.passed,true,JSON.stringify(row));
console.log('Payments desktop/mobile Chrome checks passed: '+results.length);
