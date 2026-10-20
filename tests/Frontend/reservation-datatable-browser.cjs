const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const {spawnSync}=require('node:child_process'),{pathToFileURL}=require('node:url');
const directory=path.resolve('storage/app/datatable-ui-check');
const css=['app','management-tables'].map(name=>fs.readFileSync('public/css/'+name+'.css','utf8')).join('\n');
const script=fs.readFileSync('public/js/table-actions.js','utf8')+'\n'+fs.readFileSync('public/js/modals.js','utf8')+'\n'+fs.readFileSync('public/js/reservation-datatable.js','utf8')+'\n'+fs.readFileSync('public/js/payment-confirmation.js','utf8');
const fixtures=Object.fromEntries(['pending','accepted','rejected','cancelled'].map(status=>[status,fs.readFileSync(path.join(directory,status+'.html'),'utf8')]));
const scenarios=['accept','reject','edit','cancel','rejected','cancelled','filters'];
const sources=scenarios.map(scenario=>({scenario,source:'<!doctype html><html><head><meta name="viewport" content="width=device-width, initial-scale=1"><style>'+css+'</style></head><body class="user-page">'+fixtures[['edit','cancel'].includes(scenario)?'accepted':scenario==='rejected'||scenario==='cancelled'?scenario:'pending'].match(/<main\b[^>]*>[\s\S]*?<\/main>/)[0].replace(/<script\b[^>]*>[\s\S]*?<\/script>/g,'')+'<script>'+script+'</script></body></html>'}));
const page=path.join(directory,'browser.html');
fs.writeFileSync(page,`<!doctype html><html><body><pre id="result">RUNNING</pre><script>
const fixtures=${JSON.stringify(fixtures).replace(/</g,'\\u003c')};
const sources=${JSON.stringify(sources).replace(/</g,'\\u003c')};
Promise.all(sources.flatMap(({scenario,source})=>[1440,1280,1100,1024,768,540,480,390,320].map(width=>new Promise(resolve=>{
 const frame=document.createElement('iframe');frame.style.width=width+'px';frame.style.height='900px';
 frame.onload=async()=>{try {
  const doc=frame.contentDocument,win=frame.contentWindow;const check=(ok,message)=>{if(!ok)throw Error(message);};
  const tick=()=>new Promise(resolve=>setTimeout(resolve,0));
  const before=win.location.href;let requestCount=0,refreshed=0,lastUrl='',mutation='',latestBody;
  win.history.pushState=win.history.replaceState=(_,__,url)=>{lastUrl=String(url);};
  win.fetch=async(url,options={})=>{if(options.method==='POST'){requestCount++;mutation=String(url);latestBody=options.body;return {ok:true,json:async()=>({success:true,message:'Reservation updated.'})};}refreshed++;return {ok:true,redirected:false,text:async()=>fixtures[scenario==='cancel'?'cancelled':scenario==='reject'?'rejected':scenario==='edit'||scenario==='accept'?'accepted':'pending']};};
  check(doc.documentElement.scrollWidth<=width+1,'Page horizontal overflow');
  check(doc.querySelectorAll('thead th').length===8,'Eight DataTable columns required');
  check(doc.querySelector('select[name="per_page"]').options.length===4,'Rows-per-page options missing');
  check(doc.querySelector('th:last-child a')===null,'Actions must not be sortable');
  const row=doc.querySelector('[data-reservation-id]');const id=row.dataset.reservationId;
  const rect=selector=>doc.querySelector(selector).getBoundingClientRect();
  const from=rect('#from_date'),to=rect('#to_date'),search=rect('.reservation-table-search');
  check(Math.abs(from.width-to.width)<1,'Date input widths differ');
  if(width>480) check(Math.abs(from.top-to.top)<1 && to.left>from.right,'Date fields must remain adjacent');
  else check(to.top>from.bottom,'Date fields must stack on narrow mobile screens');
  if(width>=1100) {
   const first=['#search','#status','#reservation-category','#conflict','#date_range'].map(rect);
   check(first.every(r=>Math.abs(r.top-first[0].top)<1),'Desktop filter row misaligned');
   check(Math.abs(search.bottom-from.bottom)<1,'Search button not aligned with dates');
  }
  for(const el of doc.querySelectorAll('#reservation-filters input:not([type=hidden]),#reservation-filters select,.reservation-table-search')) {
   const r=el.getBoundingClientRect();
   check(r.height>=44 && r.left>=0 && r.right<=width,'Filter control size/overflow');
  }
  const cells=row.cells,headers=doc.querySelectorAll('thead th');
  check(cells[1].clientWidth>cells[2].clientWidth,'Resident needs slightly more width than Resource');
  const center=el=>{const r=el.getBoundingClientRect();return r.left+r.width/2;};
  // Both roles share this table; Super Admin omits the Barangay-specific layout class.
  const management=doc.querySelector('#reservation-management');
  for(const barangayLayout of [false,true]) {
   management.classList.toggle('barangay-reservation-management',barangayLayout);
   for(const cell of cells) {
    check(win.getComputedStyle(cell).textAlign==='center','Cell must be centered: '+cell.cellIndex);
    check(win.getComputedStyle(cell).verticalAlign==='middle','Cell must be vertically centered: '+cell.cellIndex);
   }
   check(Math.abs(center(cells[6])-center(cells[6].querySelector('.reservation-status')))<1,'Status badge not centered');
   check(Math.abs(center(cells[7])-center(cells[7].querySelector('summary')))<1,'Actions button not centered');
   for(const header of headers) {
    check(win.getComputedStyle(header).textAlign==='center','Header must be centered');
    check(win.getComputedStyle(header).verticalAlign==='middle','Header must be vertically centered');
    const link=header.querySelector('a');
    if(link) {
     const label=doc.createRange();label.selectNodeContents(link.firstChild);
     const labelBounds=label.getBoundingClientRect(),iconBounds=link.querySelector('span').getBoundingClientRect();
     check(Math.abs(center(header)-(labelBounds.left+iconBounds.right)/2)<1,'Header text and sorting icon must be centered together');
    }
   }
  }
  const originals=[1,2,4,5].map(i=>cells[i].innerHTML);
  cells[1].textContent='Alexandria Maria de los Santos VeryLongUnbrokenResidentSurname'.repeat(2);
  cells[2].innerHTML='Community Multipurpose Equipment Storage and Recreation Center<small>Equipment</small>';
  cells[4].innerHTML='11:15 PM – 3:45 AM<small>Ends Nov 1, 2026</small>';
  cells[5].textContent='₱9,999,999,999.99';
  for(const barangayLayout of [false,true]) {
   management.classList.toggle('barangay-reservation-management',barangayLayout);
   for(const cell of cells) check(cell.scrollWidth<=cell.clientWidth+1,'Cell content spills into adjacent column: '+cell.cellIndex);
   check(cells[1].scrollHeight<=cells[1].clientHeight+1,'Long resident name clipped');
   check(doc.documentElement.scrollWidth<=width+1,'Long content causes page overflow');
  }
  const scroll=doc.querySelector('.reservation-datatable-scroll');
  scroll.scrollLeft=scroll.scrollWidth;
  const summary=doc.querySelector('.reservation-table-actions summary');
  summary.scrollIntoView({block:'center',inline:'nearest'});await tick();summary.click();
  const menuBounds=rect('.reservation-table-menu');
  check(menuBounds.left>=0 && menuBounds.right<=width && menuBounds.top>=0 && menuBounds.bottom<=win.innerHeight,'Actions clipped after horizontal scroll');
  summary.click();scroll.scrollLeft=0;
  [1,2,4,5].forEach((index,i)=>cells[index].innerHTML=originals[i]);
  const menu=doc.querySelector('.reservation-table-actions');menu.open=true;
  const labels=[...menu.querySelectorAll('button')].map(button=>button.textContent.trim());
  check(JSON.stringify(labels)===JSON.stringify(scenario==='rejected'||scenario==='cancelled'?['View']:['edit','cancel'].includes(scenario)?['View','Edit']:['View','Accept','Reject']),'Status action matrix incorrect');
  const open=selector=>{doc.querySelector(selector).click();const dialog=doc.querySelector('dialog[open]');check(dialog,'Modal did not open');check(dialog.getBoundingClientRect().left>=0&&dialog.getBoundingClientRect().right<=width,'Modal outside viewport');return dialog;};
  let dialog=open('[data-reservation-open="view-'+id+'"]');check(dialog.textContent.includes('Purpose'),'Details missing');dialog.querySelector('[data-reservation-close]').click();check(!dialog.open,'View Close failed');
  if(scenario==='accept') {
   doc.querySelector('[data-confirm-payment]').click();dialog=doc.querySelector('#payment-confirmation');check(dialog.open,'Accept confirmation did not open');
   check(dialog.querySelector('[data-confirmed-resident]').textContent.includes('Resident:'),'Resident missing from confirmation');
   check(dialog.querySelector('[data-confirmed-schedule]').textContent.includes('Schedule:'),'Schedule missing from confirmation');
   const form=dialog.querySelector('form');check(form.checkValidity(),'Accept amount invalid');form.requestSubmit();await tick();await tick();
   check(requestCount===1&&mutation.endsWith('/accept')&&latestBody.get('payment_confirmed')==='1','Acceptance request incorrect');
   check(doc.querySelector('tr[data-reservation-id]').textContent.includes('Accepted'),'Accepted status did not refresh');
   check(doc.querySelector('[data-reservation-open^="edit-"]')&&!doc.querySelector('[data-confirm-payment]'),'Accepted actions did not refresh');
  } else if(scenario==='reject') {
   dialog=open('[data-reservation-open^="reject-"]');const form=dialog.querySelector('form');check(!form.checkValidity(),'Rejection requires confirmation');form.elements.rejection_confirmed.checked=true;form.requestSubmit();await tick();await tick();
   check(requestCount===1&&mutation.endsWith('/reject')&&latestBody.get('rejection_confirmed')==='1','Rejection request incorrect');
   check(doc.querySelector('tr[data-reservation-id]').textContent.includes('Rejected'),'Rejected status did not refresh');
   check(doc.querySelectorAll('.reservation-table-menu button').length===1,'Rejected actions did not refresh');
  } else if(['edit','cancel'].includes(scenario)) {
   dialog=open('[data-reservation-open^="edit-"]');const form=dialog.querySelector('form');
   check(!dialog.querySelector('[data-edit-step="choose"]').hidden,'Edit choice missing');
   dialog.querySelector('[data-edit-choice="cancel"]').click();check(form.elements.action.value==='cancel','Cancel step missing');
   check(form.elements.reservation_date.disabled || form.elements.reservation_date.closest('fieldset').disabled,'Inactive schedule still enabled');
   check(!form.checkValidity(),'Cancellation requires reason and confirmation');
   dialog.querySelector('[data-edit-step="cancel"] [data-edit-choice="choose"]').click();
   check(!dialog.querySelector('[data-edit-step="choose"]').hidden,'Back did not return to choices');
   dialog.querySelector('[data-edit-choice="reschedule"]').click();
   check(form.elements.action.value==='reschedule','Reschedule step missing');
   form.elements.end_time.value='';check(!form.checkValidity(),'Empty end time accepted');form.elements.end_time.value='16:00';
   dialog.querySelector('[data-modal-close]').click();await tick();
   dialog=open('[data-reservation-open^="edit-"]');check(!dialog.querySelector('[data-edit-step="choose"]').hidden,'Reopening did not reset choices');
   dialog.querySelector('[data-edit-choice="'+(scenario==='cancel'?'cancel':'reschedule')+'"]').click();
   if(scenario==='cancel'){form.elements.cancellation_reason.value='User Requested Cancellation';form.elements.cancellation_confirmed.checked=true;form.elements.cancellation_notes.value='Resident called.';}
   check(form.checkValidity(),'Edit form invalid: '+[...form.elements].filter(el=>el.willValidate&&!el.validity.valid).map(el=>el.name+'='+el.value+': '+el.validationMessage).join('; '));
   form.requestSubmit();await tick();await tick();
   check(requestCount===1&&mutation.endsWith('/edit')&&latestBody.get('_method')==='PATCH','Edit request incorrect: '+requestCount+' '+mutation+' '+latestBody?.get('_method'));
   check(latestBody.get('total_payment')===null,'Edit must preserve payment');
   check(latestBody.get('action')===(scenario==='cancel'?'cancel':'reschedule'),'Edit action incorrect');
   if(scenario==='cancel'){check(latestBody.get('cancellation_confirmed')==='1','Cancellation confirmation missing');check(latestBody.get('reservation_date')===null,'Inactive schedule submitted');check(doc.querySelectorAll('.reservation-table-menu button').length===1,'Cancelled actions did not refresh');}
   else {check(latestBody.get('cancellation_reason')===null,'Inactive cancellation submitted');check(doc.querySelectorAll('.reservation-table-menu button').length===2,'Rescheduled actions did not refresh');}
  } else if(scenario==='filters') {
   const filterForm=doc.querySelector('#reservation-filters');
   const combined={search:'Community workshop',status:'accepted',category:'Equipment',conflict:'official_use',date_range:'month',from_date:'2026-10-20',to_date:'2026-10-25'};
   for(const [name,value] of Object.entries(combined)) filterForm.elements[name].value=value;
   check(!filterForm.elements.from_date.disabled && !filterForm.elements.to_date.disabled,'Existing date bounds must remain usable with presets');
   filterForm.requestSubmit();await tick();await tick();
   const submitted=new URL(lastUrl);
   for(const [name,value] of Object.entries(combined)) check(submitted.searchParams.get(name)===value,'Search/filter value omitted: '+name);
   check(!submitted.searchParams.has('page'),'New search must reset pagination');
   doc.querySelector('select[name="status"]').value='accepted';doc.querySelector('select[name="status"]').dispatchEvent(new win.Event('change',{bubbles:true}));await tick();await tick();check(lastUrl.includes('status=accepted'),'Filter did not use AJAX');
   doc.querySelector('th a').click();await tick();await tick();check(lastUrl.includes('sort=id'),'Sort did not use AJAX');
   doc.querySelector('select[name="per_page"]').value='100';doc.querySelector('select[name="per_page"]').dispatchEvent(new win.Event('change',{bubbles:true}));await tick();await tick();check(lastUrl.includes('per_page=100'),'Rows-per-page did not use AJAX');
  }
  if(['accept','reject','edit','cancel'].includes(scenario)){check(refreshed===1,'Table was not refreshed after mutation');check(doc.querySelector('.reservation-table-toast'),'Success toast missing');}
  check(win.location.href===before,'Action navigated away');check(doc.documentElement.scrollWidth<=width+1,'Updated page horizontal overflow');
  resolve({scenario,width,passed:true});
 }catch(error){resolve({scenario,width,passed:false,error:error.message});}};
 frame.srcdoc=source;document.body.appendChild(frame);
})))).then(results=>document.getElementById('result').textContent=JSON.stringify(results));
</script></body></html>`);
const result=spawnSync('C:/Program Files/Google/Chrome/Application/chrome.exe',['--headless','--disable-gpu','--no-first-run','--no-default-browser-check','--user-data-dir='+path.join(directory,'chrome-'+process.pid),'--dump-dom','--virtual-time-budget=8000',pathToFileURL(page).href],{encoding:'utf8',maxBuffer:16*1024*1024,timeout:45000,windowsHide:true});
if(result.error)throw result.error;
const match=result.stdout.match(/<pre id="result">([^<]+)<\/pre>/);assert.ok(match,'Browser returned no test results');
const results=JSON.parse(match[1]);for(const row of results)assert.equal(row.passed,true,JSON.stringify(row));
console.log('Reservation DataTable desktop/mobile Chrome checks passed: '+results.length);
