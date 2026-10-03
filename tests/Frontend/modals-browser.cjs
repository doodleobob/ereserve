const fs = require('node:fs'), path = require('node:path'), assert = require('node:assert/strict');
const {spawnSync} = require('node:child_process'), {pathToFileURL} = require('node:url');
const directory = path.resolve('storage/app/modal-ui-check');
const fixtures = Object.fromEntries(['facilities-empty', 'facilities-created', 'facilities-updated', 'residents-active', 'residents-inactive', 'resident-details', 'resident-details-page2', 'resident-conflicts', 'resident-decision', 'admins'].map(name => [name, fs.readFileSync(path.join(directory, name + '.html'), 'utf8')]));
const css = fs.readFileSync('public/css/app.css', 'utf8');
const scripts = ['modals', 'facility-management', 'account-management'].map(name => fs.readFileSync('public/js/' + name + '.js', 'utf8')).join('\n');
const sources = ['facilities', 'residents', 'admins', 'conflicts'].map(feature => ({feature, source: '<!doctype html><html><head><meta name="viewport" content="width=device-width, initial-scale=1"><style>' + css + '</style></head><body class="user-page">' + fixtures[{facilities: 'facilities-empty', residents: 'residents-active', admins: 'admins', conflicts: 'resident-conflicts'}[feature]].match(/<main\b[^>]*>[\s\S]*?<\/main>/)[0].replace(/<script\b[^>]*>[\s\S]*?<\/script>/g, '') + '<script>' + scripts + '</script></body></html>'}));
const encode = value => JSON.stringify(value).replace(/</g, '\\u003c');
const page = path.join(directory, 'browser.html');
fs.writeFileSync(page, `<!doctype html><html><body><pre id="result">RUNNING</pre><script>
const fixtures=${encode(fixtures)}, sources=${encode(sources)};
(async()=>{const results=[];for(const {feature,source} of sources)for(const width of [1280,768,390,320])results.push(await new Promise(resolve=>{
 const frame=document.createElement('iframe'); frame.style.width=width+'px';frame.style.height=(width<400?568:900)+'px';
 frame.onload=async()=>{try {
  const doc=frame.contentDocument,win=frame.contentWindow,tick=()=>new Promise(resolve=>setTimeout(resolve,0)),check=(ok,message)=>{if(!ok)throw Error(message);};
  let posts=0,gets=0,complete,posted,url,fixture,failRefresh=false;
  const before=win.location.href;
  win.fetch=async(target,options={})=>{
   if(options.method==='POST'){posts++;posted=options.body;url=String(target);return new Promise(resolve=>complete=resolve);}
   gets++;if(failRefresh)return {ok:false};return {ok:true,redirected:false,text:async()=>fixture};
  };
  const settle=async()=>{for(let i=0;i<4;i++)await tick();};
  const bounds=dialog=>{
   const rect=dialog.getBoundingClientRect(),header=dialog.querySelector('.facility-modal-header').getBoundingClientRect(),footer=dialog.querySelector('.facility-modal-actions').getBoundingClientRect();
   check(rect.left>=0&&rect.right<=width+1&&rect.top>=0&&rect.bottom<=win.innerHeight+1,'Modal outside viewport');
   check(dialog.scrollWidth<=dialog.clientWidth+1,'Modal horizontal overflow');
   check(header.top>=rect.top&&header.bottom<=rect.bottom,'Header inaccessible');
   check(footer.top>=rect.top&&footer.bottom<=rect.bottom+1,'Footer inaccessible');
   check(doc.documentElement.classList.contains('modal-open'),'Background scroll not locked');
   check(win.getComputedStyle(doc.body).overflow==='hidden','Body must not scroll');
  };
  const open=id=>{const trigger=doc.querySelector('[data-modal-open="'+id+'"]');trigger.focus();trigger.click();const dialog=doc.getElementById(id);check(dialog.open,'Modal did not open');bounds(dialog);return {dialog,trigger};};
  const close=async dialog=>{dialog.querySelector('.facility-modal-actions [data-modal-close]').click();await settle();check(!dialog.open&&!doc.documentElement.classList.contains('modal-open'),'Close must unlock page');};
  const busy=dialog=>{
   check([...dialog.querySelectorAll('button,input,select,textarea')].every(control=>control.disabled),'Controls must freeze during save');
   check(!dialog.dispatchEvent(new win.Event('cancel',{cancelable:true})),'Escape dismissed processing modal');
   dialog.dispatchEvent(new win.MouseEvent('click',{bubbles:true,clientX:-1,clientY:-1}));check(dialog.open,'Backdrop dismissed processing modal');
  };
  const fillFacility=form=>{
   for(const [name,value] of Object.entries({name:'Entered Court',description:'Entered description',location:'Taft',capacity:'50',hourly_rate:'100.00'}))form.elements[name].value=value;
  };
  if(feature==='facilities'){
   let {dialog,trigger}=open('add-facility-modal'),form=dialog.querySelector('form');
   form.elements.name.value='Unsaved';await close(dialog);check(doc.activeElement===trigger,'Focus did not return to action');
   ({dialog}=open('add-facility-modal'));check(form.elements.name.value==='','Cancel must discard edits');fillFacility(form);
   const input=form.querySelector('input[type=file]'),transfer=new win.DataTransfer();transfer.items.add(new win.File(['image'],'test.png',{type:'image/png'}));input.files=transfer.files;input.dispatchEvent(new win.Event('change',{bubbles:true}));
   check(!form.querySelector('[data-facility-photo-preview]').hidden,'Photo preview missing');
   form.requestSubmit();form.dispatchEvent(new win.Event('submit',{bubbles:true,cancelable:true}));check(posts===1,'Duplicate create submitted');
   check(posted.get('name')==='Entered Court'&&posted.get('photos[]').name==='test.png'&&posted.has('_token'),'Actual fields, photo, or CSRF missing');busy(dialog);
   complete({ok:false,json:async()=>({errors:{capacity:['Capacity rejected.'],'photos.0':['Photo rejected.']}})});await settle();
   check(dialog.open&&form.elements.name.value==='Entered Court'&&input.files.length===1,'Validation lost entered values or upload');
   check(form.elements.capacity.getAttribute('aria-invalid')==='true'&&input.getAttribute('aria-invalid')==='true','Field and nested photo errors missing');
   check(doc.activeElement===form.elements.capacity,'Invalid field not focused');
   form.requestSubmit();fixture=fixtures['facilities-created'];complete({ok:true,json:async()=>({success:true,message:'Facility added successfully.'})});await settle();
   check(posts===2&&gets===1&&!doc.querySelector('dialog[open]'),'Create must close and refresh');check(doc.querySelector('.reservation-table-toast').textContent==='Facility added successfully.','Success toast missing');
   let id=doc.querySelector('[data-modal-open^="view-facility-"]').dataset.modalOpen;({dialog}=open(id));check(dialog.textContent.includes('Preview Court')&&!dialog.querySelector('form'),'Facility View must show read-only existing fields');await close(dialog);
   id=doc.querySelector('[data-modal-open^="edit-facility-"]').dataset.modalOpen;({dialog}=open(id));form=dialog.querySelector('form');check(form.elements.name.value==='Preview Court','Edit not prefilled');form.elements.name.value='Updated Court';form.requestSubmit();check(posted.get('_method')==='PATCH','Edit must reuse PATCH backend');fixture=fixtures['facilities-updated'];complete({ok:true,json:async()=>({success:true,message:'Facility updated successfully.'})});await settle();
   check(doc.querySelector('.facility-card').textContent.includes('Updated Court'),'Edited card not refreshed');
   id=doc.querySelector('[data-modal-open^="delete-facility-"]').dataset.modalOpen;({dialog}=open(id));const count=posts;await close(dialog);check(posts===count,'Cancelled delete submitted');
   ({dialog}=open(id));dialog.querySelector('form').requestSubmit();check(posted.get('_method')==='DELETE','Delete must reuse existing endpoint');fixture=fixtures['facilities-empty'];complete({ok:true,json:async()=>({success:true,message:'Facility deleted successfully.'})});await settle();check(!doc.querySelector('.facility-card'),'Deleted facility remains');
   ({dialog}=open('add-facility-modal'));form=dialog.querySelector('form');fillFacility(form);form.requestSubmit();complete({ok:false,status:419,json:async()=>({message:'Session expired.'})});await settle();check(dialog.open&&form.elements.name.value==='Entered Court','Session failure must preserve form');
   failRefresh=true;form.requestSubmit();complete({ok:true,json:async()=>({success:true,message:'Saved.'})});await settle();check(!dialog.open&&doc.querySelector('.reservation-table-toast').textContent.includes('saved, but the table could not refresh'),'Saved refresh failure must not invite retry');
  }else if(feature==='residents'){
   fixture=fixtures['resident-details'];doc.querySelector('[data-account-view]').click();await settle();let dialog=doc.getElementById('account-details');bounds(dialog);check(dialog.textContent.includes('Reservation Activity')&&dialog.textContent.includes('Preview Resident'),'Resident details/history missing');
   fixture=fixtures['resident-details-page2'];dialog.querySelector('.account-pagination a[rel=next]').click();await settle();check(dialog.querySelector('.account-pagination').textContent.includes('Page 2 of 2'),'Activity pagination left modal');
   const status=dialog.querySelector('[data-account-status]');status.requestSubmit();dialog=doc.getElementById('account-confirmation');check(dialog.open&&!doc.getElementById('account-details').open,'Account action left stale view open');bounds(dialog);await close(dialog);check(posts===0,'Cancelled deactivation submitted');
   doc.querySelector('[data-account-status]').requestSubmit();dialog=doc.getElementById('account-confirmation');dialog.querySelector('form').requestSubmit();busy(dialog);check(posted.get('is_active')==='0'&&posted.get('_method')==='PATCH','Account status request incorrect');fixture=fixtures['residents-inactive'];complete({ok:true,json:async()=>({success:true,message:'Account deactivated.'})});await settle();check(doc.querySelector('[data-account-status] button').textContent==='Activate','Inactive UI not refreshed');
   doc.querySelector('[data-account-status]').requestSubmit();dialog=doc.getElementById('account-confirmation');check(dialog.textContent.includes('Activate Preview Resident'),'Activation confirmation incorrect');dialog.querySelector('form').requestSubmit();check(posted.get('is_active')==='1','Activation sent wrong value');fixture=fixtures['residents-active'];complete({ok:true,json:async()=>({success:true,message:'Account activated.'})});await settle();check(doc.querySelector('[data-account-status] button').textContent==='Deactivate','Activation UI not refreshed');
   fixture='<main>Sign in</main>';doc.querySelector('[data-account-view]').click();await settle();check(doc.getElementById('account-details').textContent.includes('unavailable'),'Malformed details response was accepted');await close(doc.getElementById('account-details'));
  }else if(feature==='conflicts'){
   const id=doc.querySelector('[data-modal-open$="-cancel"]').dataset.modalOpen;let {dialog}=open(id),form=dialog.querySelector('form');await close(dialog);check(posts===0,'Cancelled conflict decision submitted');({dialog}=open(id));form=dialog.querySelector('form');form.requestSubmit();busy(dialog);check(posted.get('decision')==='cancel'&&url.includes('/official-use-conflicts/'),'Conflict decision must use existing endpoint');fixture=fixtures['resident-decision'];complete({ok:true,json:async()=>({success:true,message:'Preference recorded.'})});await settle();check(!doc.querySelector('dialog[open]')&&doc.querySelector('.official-use-conflict').textContent.includes('Cancellation Requested'),'Conflict resolution UI did not refresh');
  }else{
   let {dialog}=open('create-admin'),form=dialog.querySelector('form');
   for(const [name,value] of Object.entries({name:'New Admin',email:'new@example.test',phone_number:'09171234567',barangay:'Taft',password:'password123',password_confirmation:'password123'}))form.elements[name].value=value;
   form.requestSubmit();busy(dialog);check(url.endsWith('/admins')&&posted.get('phone_number')==='09171234567','Create Admin fields/endpoint incorrect');
   complete({ok:false,json:async()=>({errors:{email:['Email already exists.'],phone_number:['Invalid phone.']}})});await settle();check(dialog.open&&form.elements.password.value==='password123'&&form.elements.email.getAttribute('aria-invalid')==='true','Admin validation lost fields');
   fixture=fixtures.admins;form.requestSubmit();complete({ok:true,json:async()=>({success:true,message:'Admin account created successfully.'})});await settle();check(!doc.querySelector('dialog[open]')&&doc.querySelector('.reservation-table-toast').textContent==='Admin account created successfully.','Admin save must stay on management');
  }
  check(win.location.href===before,'Modal action navigated away');check(doc.documentElement.scrollWidth<=width+1,'Page overflow');resolve({feature,width,passed:true});
 }catch(error){resolve({feature,width,passed:false,error:error.message});}};
 frame.srcdoc=source;document.body.appendChild(frame);
}));document.getElementById('result').textContent=JSON.stringify(results);})();
</script></body></html>`);
const result = spawnSync('C:/Program Files/Google/Chrome/Application/chrome.exe', ['--headless', '--disable-gpu', '--no-first-run', '--no-default-browser-check', '--user-data-dir=' + path.join(directory, 'chrome-' + process.pid), '--dump-dom', '--virtual-time-budget=10000', pathToFileURL(page).href], {encoding: 'utf8', maxBuffer: 16 * 1024 * 1024, timeout: 45000, windowsHide: true});
if (result.error) throw result.error;
const match = result.stdout.match(/<pre id="result">([^<]+)<\/pre>/);
assert.ok(match, 'Browser returned no modal results');
const results = JSON.parse(match[1]);
const failures = results.filter(row => !row.passed);
if (failures.length) console.error(JSON.stringify(failures, null, 2));
for (const row of results) assert.equal(row.passed, true, JSON.stringify(row));
console.log('Shared Facility/Resident/Admin modal Chrome checks passed: ' + results.length);
