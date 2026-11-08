const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const {spawnSync} = require('node:child_process');
const {pathToFileURL} = require('node:url');

const directory = path.resolve('storage/app/final-super-admin-browser-check');
const chrome = 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const css = ['app.css', 'management-tables.css'].map(name => fs.readFileSync(path.join('public/css', name), 'utf8')).join('\n');
const js = ['form-validation.js', 'modals.js', 'table-actions.js', 'mobile-navigation.js', 'admin-account-edit.js']
    .map(name => fs.readFileSync(path.join('public/js', name), 'utf8')).join('\n');
const pages = Object.fromEntries(['admin-edit', 'permission-editor', 'audit-index', 'audit-show'].map(name => {
    let html = fs.readFileSync(path.join(directory, name + '.html'), 'utf8');
    html = html.replace(/<link[^>]+rel="stylesheet"[^>]*>/gi, '').replace(/<script\b[^>]*src=[^>]*><\/script>/gi, '');
    html = html.replace('</head>', `<style>${css}</style><script>window.__browserErrors=[];window.addEventListener('error',event=>window.__browserErrors.push(event.message));</script></head>`);
    html = html.replace('</body>', `<script>${js}</script><script>
      window.EReserveModal.refreshMain=async()=>{};
      window.fetch=async(url,options={})=>{if(options.method==='POST')window.__savedPermissions=Array.from(options.body.entries());return {ok:true,redirected:false,json:async()=>({success:true,message:'Saved successfully.'}),text:async()=>document.documentElement.outerHTML};};
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
 if(name==='admin-edit'){
   const form=d.querySelector('[data-admin-edit-form]'),dialog=d.querySelector('#admin-edit-confirmation');check(form&&dialog,'Admin edit form or confirmation missing');
   check(form.elements.name.value==='Browser Review Admin'&&form.elements.barangay.value==='Washington','Admin record did not load');
   form.elements.name.value='';form.requestSubmit();check(!dialog.open&&form.elements.name.matches(':invalid'),'Required field validation failed');
   form.elements.name.value='Browser Review Admin';form.requestSubmit();check(dialog.open,'Save did not open confirmation');dialog.querySelector('[data-modal-close]').click();check(!dialog.open&&!w.__confirmedSave,'Cancel did not dismiss without save');
   form.requestSubmit();dialog.querySelector('[data-admin-edit-confirm]').click();check(w.__confirmedSave,'Confirm did not submit the edit');
 }
 if(name==='permission-editor'){
   const form=d.querySelector('#admin-permission-update-form'),dialog=d.querySelector('#admin-permission-confirmation');check(form&&dialog,'Permission editor form or confirmation missing');
   const checkbox=d.querySelector('input[value="analytics.view"]');check(checkbox,'analytics.view control missing');checkbox.click();
   d.querySelector('[data-modal-open="admin-permission-confirmation"]').click();check(dialog.open,'Review did not open confirmation');
   dialog.querySelector('[data-modal-close]').click();check(!dialog.open&&!checkbox.checked,'Cancel should discard staged restriction changes');
   checkbox.click();
   d.querySelector('[data-modal-open="admin-permission-confirmation"]').click();form.querySelector('button[type="submit"]').click();await wait();await wait();
   check(!dialog.open&&d.querySelector('.reservation-table-toast')?.textContent==='Saved successfully.','Permission save confirmation failed: open='+dialog.open+', toast='+d.querySelector('.reservation-table-toast')?.textContent+', data='+JSON.stringify(w.__savedPermissions)+', errors='+JSON.stringify(w.__browserErrors));
   check(w.__savedPermissions.some(([key,value])=>key==='restricted_permissions[]'&&value==='analytics.view'),'Selected permission was not submitted');
 }
 if(name==='audit-index'){
   check(d.querySelectorAll('tbody tr').length===25,'Audit page should render 25 records');
   check(d.querySelector('[aria-label="Pagination"]')?.textContent.includes('Page 1 of 2'),'Audit pagination missing');
   check(d.querySelector('a[rel="next"]')?.href.includes('page=2'),'Next page link missing');
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
