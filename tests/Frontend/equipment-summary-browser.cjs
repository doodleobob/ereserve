const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const runBrowser=require('./run-browser.cjs');
const directory=path.resolve('storage/app/equipment-summary-check');
const css=['app','management-tables'].map(name=>fs.readFileSync('public/css/'+name+'.css','utf8')).join('\n');
const js=['modals','table-actions','reservation-datatable','payment-confirmation'].map(name=>fs.readFileSync('public/js/'+name+'.js','utf8')).join('\n');
const sources=['admin','super-admin'].map(role=>({role,html:fs.readFileSync(path.join(directory,role+'.html'),'utf8').replace(/<link\b[^>]*>/g,'').replace(/<script\b[^>]*>[\s\S]*?<\/script>/g,'').replace('</head>','<style>'+css+'</style></head>').replace('</body>','<script>'+js+'</script></body>')}));
const encode=value=>JSON.stringify(value).replace(/</g,'\\u003c');
const page=path.join(directory,'browser.html');
fs.writeFileSync(page,`<!doctype html><html><body><pre id="result">RUNNING</pre><script>
const sources=${encode(sources)},responses=${fs.readFileSync(path.join(directory,'responses.json'),'utf8').replace(/</g,'\\u003c')};
(async()=>{
 const results=[];
 for(const {role,html} of sources)for(const width of [375,390,430,768,1024,1366,1920])results.push(await new Promise(resolve=>{
  const frame=document.createElement('iframe');frame.style.width=width+'px';frame.style.height='900px';
  frame.onload=async()=>{try{
   const doc=frame.contentDocument,win=frame.contentWindow,check=(ok,label)=>{if(!ok)throw Error(label);};
   const flush=async()=>{for(let i=0;i<12;i++)await Promise.resolve();};
   let mode='available',finish,posts=0;
   win.fetch=async(url,options={})=>{
    if(options.method==='POST'){posts++;return new Promise(resolve=>finish=resolve);}
    if(String(url).includes('equipment-availability'))return {ok:true,redirected:false,json:async()=>responses[mode]};
    return {ok:false};
   };
   check(doc.documentElement.scrollWidth<=width+1,'Page overflow');
   const panel=doc.querySelector('[data-equipment-availability-url]:not(button)'),details=panel.closest('dialog');
   doc.querySelector('[data-reservation-open="'+details.id+'"]').click();await flush();
   check(details.open&&panel.querySelector('.equipment-availability-summary'),'Fresh details summary missing');
   details.querySelector('[data-modal-close]').click();
   if(role==='super-admin'){
    check(!doc.querySelector('[data-confirm-payment]'),'Super Admin acceptance control exposed');
    resolve({role,width,passed:true});return;
   }
   const button=doc.querySelector('button[data-equipment-availability-url]'),dialog=doc.querySelector('#payment-confirmation'),form=dialog.querySelector('form'),confirm=form.querySelector('button[type=submit]'),cancel=form.querySelector('[data-cancel-payment]');
   for(const status of ['available','insufficient']){
    mode=status;button.click();check(confirm.disabled,'Submit enabled before review');await flush();
    const summary=dialog.querySelector('.equipment-availability-summary'),box=dialog.getBoundingClientRect();
    check(summary,'Summary missing');check(confirm.disabled===(status==='insufficient'),'Wrong blocking state');
    check(summary.textContent.includes('Return Risk'),'Overdue warning hidden');
    check(box.left>=0&&box.right<=width&&box.top>=0&&box.bottom<=900,'Modal exceeds viewport');
    check(dialog.scrollWidth<=dialog.clientWidth+1,'Modal horizontal overflow');
    for(const row of summary.querySelectorAll('dl>div')){
     check(win.getComputedStyle(row.querySelector('dd')).textAlign==='right','Quantities not right aligned');
     check(row.scrollWidth<=row.clientWidth+1,'Quantity row overflow');
    }
    check(confirm.classList.contains('facility-modal-primary')&&cancel.classList.contains('facility-modal-secondary'),'Existing footer styles changed');
    check(confirm.getBoundingClientRect().height===cancel.getBoundingClientRect().height,'Footer button heights differ');
    check(dialog.contains(doc.activeElement),'Modal focus missing');
    if(status==='insufficient'){form.requestSubmit();await flush();check(posts===0,'Blocked acceptance submitted');}
    cancel.click();
   }
   const facility=doc.querySelector('[data-confirm-payment]:not([data-equipment-availability-url])');
   if(facility){facility.click();check(dialog.querySelector('[data-acceptance-availability]').hidden,'Equipment summary leaked into facility');check(!confirm.disabled,'Facility submit blocked');cancel.click();}
   mode='available';button.click();await flush();form.requestSubmit();form.requestSubmit();await flush();
   check(posts===1&&confirm.disabled,'Duplicate acceptance not prevented');
   finish({ok:false,json:async()=>({errors:{quantity:['Only 40 units remain.']}})});await flush();
   check(dialog.open&&dialog.querySelector('[data-action-error]').textContent.includes('40'),'Server revalidation error missing');
   resolve({role,width,passed:true});
  }catch(error){resolve({role,width,passed:false,error:error.message});}finally{frame.remove();}};
  frame.srcdoc=html;document.body.appendChild(frame);
 }));
 document.getElementById('result').textContent=JSON.stringify(results);
})();</script></body></html>`);
runBrowser(page,path.join(directory,'chrome-summary')).then(results=>{
 fs.writeFileSync(path.join(directory,'results.json'),JSON.stringify(results,null,2));
 assert.equal(results.length,14);assert.ok(results.every(result=>result.passed),JSON.stringify(results.filter(result=>!result.passed)));
 console.log('PASS: acceptance summary, both roles at seven widths; loading, risk, blocking, duplicate submits, server errors and facility compatibility.');
}).catch(error=>{console.error(error);process.exitCode=1;});
