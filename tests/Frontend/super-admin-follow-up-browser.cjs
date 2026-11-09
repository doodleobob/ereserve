const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const {spawnSync} = require('node:child_process');
const {pathToFileURL} = require('node:url');

const directory = path.resolve('storage/app/final-super-admin-browser-check');
const chrome = 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const css = ['app.css', 'management-tables.css'].map(name => fs.readFileSync(path.join('public/css', name), 'utf8')).join('\n');
const js = ['form-validation.js', 'modals.js', 'table-actions.js', 'mobile-navigation.js', 'admin-account-edit.js', 'account-management.js']
    .map(name => fs.readFileSync(path.join('public/js', name), 'utf8')).join('\n');
const pages = Object.fromEntries(['admin-edit', 'permission-editor', 'admin-management', 'resident-management', 'audit-index', 'audit-show'].map(name => {
    let html = fs.readFileSync(path.join(directory, name + '.html'), 'utf8');
    html = html.replace(/<link[^>]+rel="stylesheet"[^>]*>/gi, '').replace(/<script\b[^>]*src=[^>]*><\/script>/gi, '');
    html = html.replace('</head>', `<style>${css}</style><script>window.__browserErrors=[];window.addEventListener('error',event=>window.__browserErrors.push(event.message));</script></head>`);
    html = html.replace('</body>', `<script>${js}</script><script>
      window.EReserveModal.refreshMain=async()=>{};
      window.fetch=async(url,options={})=>{if(options.method==='POST'){window.__savedPermissions=Array.from(options.body.entries());window.__posts=(window.__posts||[]).concat([[url,options.method]]);}if(!options.method&&String(url).includes('/admins/'))return {ok:true,redirected:false,text:async()=>'<div data-account-details><dl><dt>Name</dt><dd>Browser Review Admin</dd><dt>Email</dt><dd>admin@example.test</dd><dt>Phone Number</dt><dd>Not provided</dd><dt>Barangay</dt><dd>Washington</dd><dt>Account Status</dt><dd>Active</dd><dt>Date Created</dt><dd>Nov 9, 2026</dd></dl></div>'};return {ok:true,redirected:false,json:async()=>({success:true,message:String(url).endsWith('/password-reset')?'Reset link sent safely.':'Saved successfully.'}),text:async()=>document.documentElement.outerHTML};};
      document.addEventListener('submit',event=>{event.preventDefault();if(event.target.dataset.confirmed==='true')window.__confirmedSave=true;},true);
    </script></body>`);
    return [name, html];
}));
const encoded = Object.fromEntries(Object.entries(pages).map(([key, value]) => [key, JSON.stringify(value).replace(/</g, '\\u003c')]));
const runner = path.join(directory, 'browser-review.html');
fs.writeFileSync(runner, `<!doctype html><html><body><pre id="result">RUNNING</pre><script>
const pages={${Object.entries(encoded).map(([key,value]) => `${JSON.stringify(key)}:${value}`).join(',')}};
const widths=[1280,768,390,320];const results=[];
const wait=()=>new Promise(resolve=>setTimeout(resolve,30));
async function inspect(name,width){
 const frame=document.createElement('iframe');frame.style.cssText='width:'+width+'px;height:1000px;border:0';frame.srcdoc=pages[name];document.body.appendChild(frame);
 await new Promise(resolve=>frame.onload=resolve);await wait();const d=frame.contentDocument,w=frame.contentWindow,check=(ok,message)=>{if(!ok)throw Error(message);};
 check(d.documentElement.scrollWidth<=width+1,'Horizontal overflow at '+width+'px');
 check(w.__browserErrors.length===0,'JavaScript errors: '+w.__browserErrors.join('; '));
 if(width>=1280){
   const nav=d.querySelector('.nav-inner-super-admin'),items=nav&&[...nav.children],rects=items?.map(item=>item.getBoundingClientRect());
   check(nav&&rects.length>1,'Super Admin desktop navigation missing');
   const navRect=nav.getBoundingClientRect(),groupCenter=(rects[0].left+rects.at(-1).right)/2;
   check(Math.abs(groupCenter-(navRect.left+navRect.width/2))<=2,'Super Admin navigation group is not horizontally centered at '+width+'px');
   for(let index=1;index<rects.length;index++){
     check(rects[index].left>=rects[index-1].right-1,'Super Admin navigation items overlap at '+width+'px');
     check(Math.abs((rects[index].left-rects[index-1].right)-8)<=1,'Super Admin navigation item spacing is inconsistent at '+width+'px');
   }
 }else{
   check(w.getComputedStyle(d.querySelector('.user-nav')).display==='none','Existing mobile navigation behavior changed at '+width+'px');
   check(!d.querySelector('.mobile-menu-button').hidden,'Mobile menu control is unavailable at '+width+'px');
 }
 if(name==='admin-edit'){
   const form=d.querySelector('[data-admin-edit-form]'),dialog=d.querySelector('#admin-edit-confirmation');check(form&&dialog,'Admin edit form or confirmation missing');
   const resetTrigger=d.querySelector('[data-modal-open="admin-password-reset-confirmation"]'),resetDialog=d.querySelector('#admin-password-reset-confirmation');
   check(resetTrigger&&resetDialog&&!form.contains(resetTrigger),'Password reset action missing or nested in the edit form');
   check(resetDialog.querySelector('form').action.endsWith('/password-reset'),'Password reset form did not use the existing route');
   check(form.elements.name.value==='Browser Review Admin'&&form.elements.barangay.value==='Washington','Admin record did not load');
   form.elements.name.value='';form.requestSubmit();check(!dialog.open&&form.elements.name.matches(':invalid'),'Required field validation failed');
   form.elements.name.value='Browser Review Admin';form.requestSubmit();check(dialog.open,'Save did not open confirmation: processing='+w.EReserveModal.processing+', open='+dialog.open+', invalid='+form.checkValidity()+', errors='+JSON.stringify(w.__browserErrors));dialog.querySelector('[data-modal-close]').click();check(!dialog.open&&!w.__confirmedSave,'Cancel did not dismiss without save');
   form.requestSubmit();dialog.querySelector('[data-admin-edit-confirm]').click();check(w.__confirmedSave,'Confirm did not submit the edit');
   resetTrigger.click();check(resetDialog.open,'Password reset confirmation did not open');resetDialog.querySelector('[data-modal-close]').click();
   check(!resetDialog.open&&!w.__posts,'Cancelling password reset should not send a request');
   resetTrigger.click();resetDialog.querySelector('form').requestSubmit();for(let index=0;index<20&&w.EReserveModal.processing;index++)await wait();await wait();
   check(!resetDialog.open&&w.__posts?.some(([url,method])=>url.endsWith('/password-reset')&&method==='POST'),'Confirmed password reset did not use the existing POST workflow');
   check(d.querySelector('.reservation-table-toast')?.textContent==='Reset link sent safely.','Password reset success feedback missing');
 }
 if(name==='permission-editor'){
   const form=d.querySelector('#admin-permission-update-form'),dialog=d.querySelector('#admin-permission-confirmation');check(form&&dialog,'Permission editor form or confirmation missing');
   const rows=[...d.querySelectorAll('.admin-permission-row')];check(rows.length>0,'Capability rows are missing');
   for(const row of rows){
     const info=row.querySelector('.admin-permission-info'),label=row.querySelector('.admin-permission-restriction'),input=label?.querySelector('input'),text=label?.querySelector('span');
     check(info&&label&&input&&text,'Capability information or restriction control is incomplete');
     check(Math.abs(parseFloat(w.getComputedStyle(label).gap)-8)<0.1,'Checkbox and restriction label spacing is inconsistent');
     const infoRect=info.getBoundingClientRect(),labelRect=label.getBoundingClientRect(),inputRect=input.getBoundingClientRect(),textRect=text.getBoundingClientRect();
     check(textRect.left>=inputRect.right-1&&textRect.left-inputRect.right<=9,'Checkbox and its label are separated at '+width+'px');
     if(width>900){
       check(infoRect.left<labelRect.left&&labelRect.right<=row.getBoundingClientRect().right+1,'Desktop capability row is not arranged in two columns');
       check(Math.abs((infoRect.top+infoRect.bottom)/2-(labelRect.top+labelRect.bottom)/2)<=2,'Desktop restriction control is not vertically centered');
     }else check(labelRect.top>=infoRect.bottom-1,'Narrow capability row should place restriction below its information');
   }
   const checkbox=d.querySelector('input[value="analytics.view"]');check(checkbox,'analytics.view control missing');checkbox.click();
   d.querySelector('[data-modal-open="admin-permission-confirmation"]').click();check(dialog.open,'Review did not open confirmation');
   dialog.querySelector('[data-modal-close]').click();check(!dialog.open&&!checkbox.checked,'Cancel should discard staged restriction changes');
   checkbox.click();
   d.querySelector('[data-modal-open="admin-permission-confirmation"]').click();form.querySelector('button[type="submit"]').click();await wait();await wait();
   check(!dialog.open&&d.querySelector('.reservation-table-toast')?.textContent==='Saved successfully.','Permission save confirmation failed: open='+dialog.open+', toast='+d.querySelector('.reservation-table-toast')?.textContent+', data='+JSON.stringify(w.__savedPermissions)+', errors='+JSON.stringify(w.__browserErrors));
   check(w.__savedPermissions.some(([key,value])=>key==='restricted_permissions[]'&&value==='analytics.view'),'Selected permission was not submitted');
 }
 if(name==='admin-management'||name==='resident-management'){
   const table=d.querySelector('.management-table'),scroll=d.querySelector('.management-table-scroll');check(table&&scroll,'Shared management table missing');
   check(d.querySelector('[aria-label$="pagination"]'),'Shared pagination missing');
   check(d.querySelector('form[aria-label$="filters"] input[type="search"]'),'Search control missing');
   const rows=[...table.tBodies[0].rows];check(rows.some(row=>row.textContent.includes(name==='admin-management'?'Browser Review Admin':'Browser Review Resident')),'Expected account row did not render');
   check(table.querySelector('thead th[aria-sort] a'),'Sortable table headings missing');
   const menu=table.querySelector('[data-table-actions]');menu.querySelector('summary').click();check(menu.open,'Account action menu did not open');
   if(name==='admin-management'){
     check(menu.querySelector('[data-account-view]'),'View action missing');
     check([...menu.querySelectorAll('a')].some(link=>link.textContent.includes('Edit')),'Edit action missing');
     check([...menu.querySelectorAll('a')].some(link=>link.textContent.includes('Permissions')),'Permission editor action missing');
     const view=menu.querySelector('[data-account-view]');view.click();await wait();
     const details=d.querySelector('#account-details'),detailBody=details.querySelector('[data-account-detail-body]');
     check(details.open&&detailBody.textContent.includes('Browser Review Admin'),'View did not open the existing Admin Details modal');
     check(!detailBody.querySelector('a,button,[data-account-status]'),'Admin Details modal contains management controls');
     check(details.querySelectorAll('[data-modal-close]').length===1,'Admin Details modal should have only its top-right close button');
     details.querySelector('[data-modal-close]').click();
     const status=table.querySelector('[data-account-status]');status.requestSubmit();const confirmation=d.querySelector('#account-confirmation');
     check(confirmation.open&&confirmation.querySelector('[data-account-confirm-description]').textContent.includes('Deactivate Browser Review Admin'),'Account status confirmation missing');
     confirmation.querySelector('[data-modal-close]').click();check(!confirmation.open,'Account status cancellation failed');
   }else{
     check(!table.querySelector('a[href*="/admins/"]'),'Resident page exposed admin management actions');
   }
   const filter=d.querySelector('form[aria-label$="filters"]');filter.elements.search.value='Browser Review';filter.requestSubmit();
   check(filter.elements.search.value==='Browser Review','Account search input failed');
 }
 if(name==='audit-index'){
   check(d.querySelectorAll('tbody tr').length===25,'Audit page should render 25 records');
   check(d.querySelector('[aria-label="Audit log pagination"]')?.textContent.includes('2'),'Audit pagination missing');
   check([...d.querySelectorAll('[aria-label="Audit log pagination"] a')].some(link=>link.textContent==='Next'&&link.href.includes('page=2')),'Next page link missing');
   const search=d.querySelector('[name="search"]');search.value='Browser Review Admin';
   d.querySelector('[name="actor_role"]').value='admin';
   d.querySelector('form[aria-label="Audit log filters"]').requestSubmit();
   check(d.querySelector('[name="search"]').value==='Browser Review Admin'&&d.querySelector('[name="actor_role"]').value==='admin','Audit filter controls failed');
 }
 if(name==='audit-show')check(d.body.textContent.includes('Changed a Barangay Admin account status')&&d.body.textContent.includes('Washington'),'Audit entry details did not load');
 results.push({page:name,width,passed:true});frame.remove();
}
(async()=>{try{for(const name of Object.keys(pages))for(const width of widths)await inspect(name,width);document.getElementById('result').textContent=JSON.stringify(results);}catch(error){document.getElementById('result').textContent=JSON.stringify({passed:false,error:error.message});}})();
</script></body></html>`);
const profile = path.join(directory, 'chrome-' + process.pid + '-' + Date.now());
const result = spawnSync(chrome, ['--headless', '--disable-gpu', '--disable-software-rasterizer', '--no-first-run', '--no-default-browser-check', '--user-data-dir=' + profile, '--dump-dom', '--virtual-time-budget=15000', pathToFileURL(runner).href], {encoding: 'utf8', maxBuffer: 16 * 1024 * 1024, timeout: 60000, windowsHide: true});
if (result.error) throw result.error;
const match = result.stdout.match(/<pre id="result">([^<]+)<\/pre>/);
assert.ok(match, 'Browser returned no results: ' + result.stderr.slice(-1200));
const output = JSON.parse(match[1]);
assert.ok(Array.isArray(output), JSON.stringify(output));
for (const row of output) assert.equal(row.passed, true, JSON.stringify(row));
console.log(`Super Admin follow-up browser checks passed: ${output.length} page/viewport combinations.`);
