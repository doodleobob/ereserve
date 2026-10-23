const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const {spawnSync}=require('node:child_process'),{pathToFileURL}=require('node:url');
const directory=path.resolve('storage/app/payment-ui-check');
const fixtures=Object.fromEntries(['empty','paid','refund_pending','refunded'].map(name=>[name,fs.readFileSync(path.join(directory,name+'.html'),'utf8')]));
const reports=Object.fromEntries(['pdf','xlsx','csv'].map(format=>[format,fs.readFileSync(path.join(directory,'report.'+format)).toString('base64')]));
const source='<!doctype html><html><head><meta name="viewport" content="width=device-width, initial-scale=1"><style>'+['app','management-tables'].map(name=>fs.readFileSync('public/css/'+name+'.css','utf8')).join('\n')+'</style></head><body class="user-page">'+fixtures.paid.match(/<main\b[^>]*>[\s\S]*?<\/main>/)[0].replace(/<script\b[^>]*>[\s\S]*?<\/script>/g,'')+'<script>'+['form-validation.js','modals.js','reservation-datatable.js','table-actions.js'].map(file=>fs.readFileSync('public/js/'+file,'utf8')).join('\n')+'</script></body></html>';
const encode=value=>JSON.stringify(value).replace(/</g,'\\u003c');
const page=path.join(directory,'browser.html');
fs.writeFileSync(page,`<!doctype html><html><body><pre id="result">RUNNING</pre><script>
const source=${encode(source)},fixtures=${encode(fixtures)},reports=${encode(reports)};
Promise.all([1280,768,390,320].map(width=>new Promise(resolve=>{
 const frame=document.createElement('iframe');frame.style.width=width+'px';frame.style.height='900px';
 frame.onload=async()=>{try {
  const doc=frame.contentDocument,win=frame.contentWindow,check=(ok,message)=>{if(!ok)throw Error(message);},tick=()=>new Promise(resolve=>setTimeout(resolve,0));
  let posts=0,gets=0,downloads=0,complete,body,lastUrl='',mutation='',tableFixture=fixtures.refund_pending,downloadResult;
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
  const labels=()=>[...doc.querySelectorAll('.reservation-table-menu button')].map(b=>b.textContent.trim());
  check(JSON.stringify(labels())===JSON.stringify(['View']),'Paid payment must only offer View');
  check(!doc.querySelector('[data-reservation-open^="payment-edit-"]')&&!doc.querySelector('dialog[id^="payment-edit-"]'),'Edit UI must be removed');
  const showMenu=async()=>{
   const details=doc.querySelector('.reservation-table-actions'),summary=details.querySelector('summary');
   summary.scrollIntoView({block:'center',inline:'center'});await tick();await tick();
   const row=details.closest('tr'),before=row.getBoundingClientRect(),columns=[...row.cells].map(cell=>cell.getBoundingClientRect().width);
   summary.click();
   const menu=details.querySelector('.reservation-table-menu'),bounds=menu.getBoundingClientRect();
   check(details.open&&summary.getAttribute('aria-expanded')==='true','Actions dropdown must open');
   check(row.getBoundingClientRect().height===before.height,'Dropdown expanded the row');
   check(JSON.stringify([...row.cells].map(cell=>cell.getBoundingClientRect().width))===JSON.stringify(columns),'Dropdown changed column widths');
   check(bounds.left>=0&&bounds.right<=width&&bounds.top>=0&&bounds.bottom<=900,'Dropdown outside viewport');
   check(bounds.width<=200,'Dropdown must remain compact');
   const button=menu.querySelector('button'),rect=button.getBoundingClientRect();
   check(button.contains(doc.elementFromPoint(rect.left+rect.width/2,rect.top+rect.height/2)),'Dropdown is clipped by table overflow');
   return details;
  };
  let menu=await showMenu();
  menu.querySelector('summary').dispatchEvent(new win.KeyboardEvent('keydown',{key:'ArrowDown',bubbles:true}));
  check(doc.activeElement===menu.querySelector('button'),'Keyboard must reach first action');
  doc.activeElement.dispatchEvent(new win.KeyboardEvent('keydown',{key:'Escape',bubbles:true}));
  check(!menu.open&&doc.activeElement===menu.querySelector('summary'),'Escape must close and return focus');
  menu=await showMenu();doc.body.click();check(!menu.open,'Outside click must close dropdown');
  menu=await showMenu();win.dispatchEvent(new win.Event('resize'));check(!menu.open,'Resize must close dropdown');
  menu=await showMenu();doc.querySelector('.reservation-datatable-scroll').dispatchEvent(new win.Event('scroll'));check(!menu.open,'Table scroll must close dropdown');
  const open=async kind=>{const menu=await showMenu();doc.querySelector('[data-reservation-open^="payment-'+kind+'-"]').click();const dialog=doc.querySelector('dialog[open]');check(dialog&&!menu.open,'Action must close dropdown and open modal');const bounds=dialog.getBoundingClientRect();check(bounds.left>=0&&bounds.right<=width&&bounds.height<=900,'Modal outside viewport');return dialog;};
  let dialog=await open('view');
  for(const label of ['Payment ID','Reservation ID','Resident','Resource','Reservation Date','Reservation Time','Total','Payment Status'])check([...dialog.querySelectorAll('dt')].some(dt=>dt.textContent===label),'Missing View field '+label);
  check(dialog.textContent.includes('Preview Resident')&&dialog.textContent.includes('₱500.00')&&dialog.textContent.includes('Paid'),'View relationships incorrect');
  check(!dialog.querySelector('form'),'View must be read-only');dialog.querySelector('[data-reservation-close]').click();
  check(doc.activeElement===doc.querySelector('.reservation-table-actions summary'),'Modal must return focus to visible Actions trigger');
  // The server now reflects cancellation performed through the reservation workflow.
  doc.querySelector('#payment-filters').requestSubmit();await tick();await tick();
  check(gets===1&&posts===0,'Loading cancellation status must not mutate payments');
  check(doc.querySelector('tbody').textContent.includes('Refund Pending')&&!doc.querySelector('tbody').textContent.includes('Paid')&&doc.querySelector('[data-payment-total]').textContent==='₱500.00','Refreshed status/summary incorrect');
  check(JSON.stringify(labels())===JSON.stringify(['View','Confirm Refund']),'Pending refund actions incorrect');
  dialog=await open('refund');let form=dialog.querySelector('form');
  check(form.elements.payment_status.value==='refunded','Refund action must submit refunded');
  form.requestSubmit();check(posts===0,'Refund requires confirmation before submission');
  form.elements.receipt_confirmed.checked=true;form.requestSubmit();
  form.dispatchEvent(new win.Event('submit',{bubbles:true,cancelable:true}));
  check(posts===1&&body.get('receipt_confirmed')==='1'&&body.get('payment_status')==='refunded','Confirmed refund submits once');
  check(body.get('_method')==='PATCH'&&mutation.endsWith('/1'),'PATCH existing payment missing');
  check([...dialog.querySelectorAll('button')].every(b=>b.disabled),'Refund buttons must disable during save');
  check(!dialog.dispatchEvent(new win.Event('cancel',{cancelable:true})),'Escape must not dismiss processing refund');
  complete({ok:false,json:async()=>({errors:{receipt_confirmed:['Confirm that the money was returned.']}})});await tick();await tick();
  check(dialog.open&&!form.querySelector('[data-action-error]').hidden,'Validation must retain dialog');
  form.requestSubmit();check(posts===2,'Refund retry missing');
  tableFixture=fixtures.refunded;
  complete({ok:true,json:async()=>({success:true,message:'Payment updated successfully.'})});await tick();await tick();
  check(gets===2&&!doc.querySelector('[data-reservation-open^="payment-refund-"]'),'Completed refund must remove Confirm Refund action');
  check(doc.querySelector('tbody').textContent.includes('Refunded'),'Completed refund status missing');
  check(JSON.stringify(labels())===JSON.stringify(['View']),'Refunded payment must only offer View');
  dialog=await open('view');check(dialog.textContent.includes('Refunded'),'Refunded details missing');dialog.querySelector('[data-reservation-close]').click();
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
