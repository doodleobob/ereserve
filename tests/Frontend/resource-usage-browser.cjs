const fs = require('node:fs'), path = require('node:path'), assert = require('node:assert/strict');
const runBrowser = require('./run-browser.cjs');
const directory = path.resolve('storage/app/ui-consistency-check');
const css = ['app', 'management-tables'].map(name => fs.readFileSync(`public/css/${name}.css`, 'utf8')).join('\n');
const js = ['modals', 'table-actions', 'reservation-datatable', 'mobile-navigation', 'resources-navigation'].map(name => fs.readFileSync(`public/js/${name}.js`, 'utf8')).join('\n');
const sources = ['admin-resource-usage', 'super-admin-resource-usage'].map(name => ({name, html: fs.readFileSync(path.join(directory, name + '.html'), 'utf8').replace(/<link\b[^>]*>/g, '').replace(/<script\b[^>]*>[\s\S]*?<\/script>/g, '').replace('</head>', `<style>${css}</style></head>`).replace('</body>', `<script>${js}</script></body>`)}));
const page = path.join(directory, 'resource-usage-browser.html');
fs.writeFileSync(page, `<!doctype html><html><body><pre id="result">RUNNING</pre><script>
const sources = ${JSON.stringify(sources).replace(/</g, '\\u003c')};
(async () => {
 const results = [];
 for (const {name, html} of sources) for (const width of [375,390,430,768,1024,1366,1920]) {
  results.push(await new Promise(resolve => {
   const frame = document.createElement('iframe'); frame.style.width = width+'px'; frame.style.height='900px';
   frame.onload = () => {
    try {
     const doc=frame.contentDocument, win=frame.contentWindow, check=(ok,message)=>{if(!ok)throw Error(message);};
     win.fetch=async()=>{throw Error('Unexpected network request');};
     const table=doc.querySelector('[data-resource-usage-table] table'), wrapper=table.parentElement;
     check(doc.documentElement.scrollWidth<=width+1,'Page overflow');
     check(wrapper.scrollWidth>wrapper.clientWidth || width===1920,'Wide table must scroll within page');
     check(table.querySelectorAll('thead th').length===(name.startsWith('super')?17:16),'Table columns differ');
     for(const cell of table.querySelectorAll('th,td')) check(win.getComputedStyle(cell).textAlign==='center','Shared alignment missing');
     check(doc.querySelectorAll('a[href$="/resource-usage"]').length>=2,'Desktop/mobile navigation missing');
     check(!!doc.querySelector('[name="barangay"]')===name.startsWith('super'),'Barangay filter scope');
     check(!!doc.querySelector('[name="request_key"]')===!name.startsWith('super'),'Super Admin must be read-only');
     for(const menu of doc.querySelectorAll('[data-table-actions]')) {
      const trigger=menu.querySelector('summary'); trigger.scrollIntoView({block:'center',inline:'center'});
      const row=menu.closest('tr'), before=row.getBoundingClientRect().height;
      trigger.click();
      const actions=menu.querySelector('.reservation-table-menu'), bounds=actions.getBoundingClientRect();
      check(menu.open && win.getComputedStyle(actions).position==='fixed','Action menu does not float');
      check(bounds.left>=0&&bounds.right<=width&&bounds.top>=0&&bounds.bottom<=900,'Clipped action menu');
      check(before===row.getBoundingClientRect().height,'Menu changes row height');
      for(const button of actions.querySelectorAll('button')) {
       if(!menu.open) trigger.click();
       check(win.getComputedStyle(button).whiteSpace==='nowrap','Action label wraps');
       check(button.getBoundingClientRect().height>=40,'Action target too small');
       button.click();
       const dialog=doc.getElementById(button.dataset.reservationOpen), box=dialog.getBoundingClientRect();
       check(dialog.open,'Modal failed to open');
       check(box.left>=0&&box.right<=width&&box.top>=0&&box.bottom<=900,'Clipped modal');
       check(dialog.contains(doc.activeElement),'Focus not moved into modal');
       check(dialog.scrollWidth<=dialog.clientWidth+1,'Modal content overflows');
       const footer=dialog.querySelector('.facility-modal-actions');
       check(footer&&footer.querySelector('.facility-modal-secondary'),'Shared footer missing');
       for(const control of footer.querySelectorAll('button')) check(control.getBoundingClientRect().height>=40,'Modal button too small');
       dialog.querySelector('[data-modal-close]').click();
       check(!dialog.open,'Modal close failed');
      }
      menu.open=false;
     }
     const form=doc.querySelector('[data-reservation-action]');
     if(form) {
      const key=form.querySelector('[name="request_key"]'), original=key.value;
      const dialog=form.closest('dialog'); win.EReserveModal.open(dialog); form.reset();
      check(key.value===original,'Retry key changed on modal reset'); win.EReserveModal.close(dialog);
     }
     resolve({name,width,passed:true});
    }catch(error){resolve({name,width,passed:false,error:error.message});}finally{frame.remove();}
   };frame.srcdoc=html;document.body.appendChild(frame);
  }));
 }
 document.getElementById('result').textContent=JSON.stringify(results);
})();</script></body></html>`);
runBrowser(page, path.join(directory, 'chrome-resource-usage')).then(results => {
 fs.writeFileSync(path.join(directory,'resource-usage-results.json'),JSON.stringify(results,null,2));
 assert.equal(results.length,14); assert.ok(results.every(row=>row.passed),JSON.stringify(results.filter(row=>!row.passed)));
 console.log('PASS: Resource Usage, both roles at all seven widths; table, menus, modals, focus, navigation, retry keys.');
}).catch(error=>{console.error(error);process.exitCode=1;});
