// Fresh fixtures: ui-consistency-fixtures.php, resource-usage-fixtures.php,
// FormValidationPresentationTest (VALIDATION_RENDER_DIR), and EquipmentInventoryTest (INVENTORY_UI_RENDER_DIR).
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const runBrowser=require('./run-browser.cjs');
const directory=path.resolve('storage/app/validation-check');fs.mkdirSync(directory,{recursive:true});
const scripts=['form-validation','modals','resource-quantity'].map(name=>fs.readFileSync('public/js/'+name+'.js','utf8')).join('\n');
const files=fs.readdirSync('storage/app/ui-consistency-check').filter(name=>name.endsWith('.html')&&!name.startsWith('browser')).map(name=>['ui/'+name,path.resolve('storage/app/ui-consistency-check',name)]);
for(const name of ['login-errors','security-errors'])files.push([name,path.join(directory,name+'.html')]);
for(const role of ['admin','super-admin'])files.push(['inventory/'+role,path.resolve('storage/app/inventory-check',role+'.html')]);
const sources=files.map(([name,file])=>{
 let html=fs.readFileSync(file,'utf8');
 const styles=[...html.matchAll(/href="[^"\s]*\/css\/([^?"\s]+)[^"\s]*"/g)].map(match=>'public/css/'+match[1]).filter(file=>fs.existsSync(file)).map(file=>fs.readFileSync(file,'utf8')).join('\n');
 html=html.replace(/<link\b[^>]*>/g,'').replace(/<script\b[^>]*>[\s\S]*?<\/script>/g,'').replace('</head>',()=>'<style>'+styles+'</style></head>').replace('</body>',()=>'<script>'+scripts+'</script></body>');
 return {name,html};
});
const page=path.join(directory,'browser.html');
fs.writeFileSync(page,`<!doctype html><html><head><meta charset="utf-8"></head><body><pre id="result">RUNNING</pre><script>
const sources=${JSON.stringify(sources).replace(/</g,'\\u003c')};
(async()=>{const results=[];
for(const {name,html} of sources)for(const width of [375,768,1366]){
 results.push(await new Promise(resolve=>{
  const frame=document.createElement('iframe');frame.style.width=width+'px';frame.style.height='900px';
  frame.onload=()=>{let forms=0,fields=0;try{
   const doc=frame.contentDocument,win=frame.contentWindow,api=win.EReserveValidation;
   const check=(ok,message)=>{if(!ok)throw Error(message);};
   if(name==='login-errors'){
    const input=doc.querySelector('[name=email]'),error=doc.getElementById(input.getAttribute('aria-describedby').split(' ').at(-1));
    check(input.value==='resident@example.test'&&error.textContent==='Email is not recognized.','SSR email/value hydration');
    const password=doc.querySelector('[name=password]');check(password.closest('.password-field').nextElementSibling.textContent==='Password is required.','Password error inside toggle wrapper');
   }
   if(name==='security-errors')check(doc.querySelector('#setup-password').getAttribute('aria-invalid')==='true'&&!doc.querySelector('#current_password').hasAttribute('aria-invalid'),'Security error leaked to password-change form');
   for(const form of doc.querySelectorAll('form')){
    if(form.closest('[hidden]'))continue;
    const controls=[...form.elements].filter(c=>c.matches('input,select,textarea')&&c.name&&c.type!=='hidden'&&!c.disabled&&!c.closest('[hidden],fieldset[disabled]'));
    if(!controls.length)continue;
    const modal=form.closest('dialog');if(modal&&!modal.open)modal.showModal();
    const errors={};for(const c of controls)errors[c.name.replace(/\\[\\]$/,'')]=['Review '+c.name+': '+('invalid value ').repeat(12)];
    api.show(form,errors);forms++;fields+=controls.length;
    check(!form.querySelector('[data-action-error]:not([hidden])'),'Field errors duplicated in form summary');
    for(const control of controls){
     const id=(control.getAttribute('aria-describedby')||'').split(' ').at(-1),error=doc.getElementById(id);
     check(error&&error.textContent.includes('Review '+control.name.replace(/\\[\\]$/,'')),'Missing own error for '+control.name);
     check(control.getAttribute('aria-invalid')==='true','Missing invalid state');
     const anchor=['checkbox','radio'].includes(control.type)?control.closest('label')||control:control.closest('.password-field,.search-field')||control;
     const group=control.closest('fieldset.modal-form-group,fieldset.reservation-access-options');
     const peers=controls.filter(c=>c.name===control.name),last=peers.at(-1);
     if(control===last)check(error.previousElementSibling===anchor||(group&&group.lastElementChild===error),'Error outside its field: '+control.name);
     const a=anchor.getBoundingClientRect(),e=error.getBoundingClientRect();
     if(control===last)check(e.top>=a.bottom-1,'Error overlaps its control: '+control.name);
     check(error.scrollWidth<=error.clientWidth+1,'Error text overflow');
    }
    const changed=controls.find(c=>c.type!=='file')||controls[0],oldError=doc.getElementById(changed.getAttribute('aria-describedby').split(' ').at(-1)).textContent;
    changed.dispatchEvent(new win.Event('input',{bubbles:true}));
    check(![...form.querySelectorAll('[data-validation-error]')].some(e=>e.textContent===oldError),'Edited server error not cleared');
    api.show(form,{...errors,_form:['The schedule changed. Please review it.']});
    check(form.querySelector('[data-action-error]').textContent.includes('The schedule changed.'),'General error is not in form summary');
    api.clear(form);check(!form.querySelector('[data-validation-error]')&&!form.querySelector('[aria-invalid=true]'),'Reset leaves stale validation');
    if(modal)modal.close();
   }
   resolve({name,width,forms,fields,passed:true});
  }catch(error){resolve({name,width,forms,fields,passed:false,error:error.message});}finally{frame.remove();}};
  frame.srcdoc=html;document.body.appendChild(frame);
 }));
}
document.getElementById('result').textContent=JSON.stringify(results);})();</script></body></html>`);
runBrowser(page,path.join(directory,'chrome-validation')).then(results=>{
 fs.writeFileSync(path.join(directory,'results.json'),JSON.stringify(results,null,2));
 assert.ok(results.every(result=>result.passed),JSON.stringify(results.filter(result=>!result.passed)));
 console.log(JSON.stringify({layouts:results.length,forms:results.reduce((n,r)=>n+r.forms,0),fields:results.reduce((n,r)=>n+r.fields,0),passed:true}));
}).catch(error=>{console.error(error);process.exitCode=1;});
