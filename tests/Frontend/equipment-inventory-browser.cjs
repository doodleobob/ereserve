const fs = require('node:fs'), path = require('node:path'), assert = require('node:assert/strict');
const runBrowser = require('./run-browser.cjs');
const directory = path.resolve('storage/app/inventory-check');
const css = ['app', 'equipment-inventory', 'management-tables'].map(name => fs.readFileSync(`public/css/${name}.css`, 'utf8')).join('\n');
const js = ['form-validation', 'modals', 'table-actions', 'facility-management'].map(name => fs.readFileSync(`public/js/${name}.js`, 'utf8')).join('\n');
const sources = ['admin', 'super-admin'].flatMap(role => ['inventory', 'equipment'].map(tab => ({role, tab, html: fs.readFileSync(tab === 'inventory' ? path.join(directory, `${role}.html`) : path.resolve('storage/app/resource-separation-check', `${role.replace('-', '_')}-equipment.html`), 'utf8')
    .replace(/<link\b[^>]*>/g, '').replace(/<script\b[^>]*>[\s\S]*?<\/script>/g, '')
    .replace('</head>', `<style>${css}</style></head>`).replace('</body>', `<script>${js}</script></body>`)})));
const page = path.join(directory, 'browser.html');
fs.writeFileSync(page, `<!doctype html><html><head><meta charset="utf-8"></head><body><pre id="result">RUNNING</pre><script>
const sources = ${JSON.stringify(sources).replace(/</g, '\\u003c')};
(async () => {
 const results = [];
 for (const {role, tab, html} of sources) for (const width of [320,375,390,430,768,1024,1366,1920]) {
  results.push(await new Promise(resolve => {
   const frame = document.createElement('iframe'); frame.style.width = width+'px'; frame.style.height = '900px';
   frame.onload = async () => { try {
    const doc = frame.contentDocument, win = frame.contentWindow, check = (ok, label) => { if (!ok) throw Error(label); };
    check(doc.documentElement.scrollWidth <= width+1, 'Page overflow');
    const tabs = doc.querySelector('[aria-label="Equipment management tabs"]');
    check(tabs.querySelector('[aria-current="page"]').textContent.toLowerCase() === tab, 'Wrong active tab');
    check(win.getComputedStyle(tabs).justifyContent==='flex-start','Tabs not aligned with content');
    const activeTab=tabs.querySelector('[aria-current="page"]'),topActive=doc.querySelector('.user-nav .nav-link.active');
    check(tabs.querySelectorAll('.nav-link.active').length===1&&activeTab.classList.contains('active'),'Active navigation styling does not follow selected tab');
    for(const property of ['backgroundColor','color','borderBottomColor','borderBottomWidth','fontWeight','transitionDuration']) {
     check(win.getComputedStyle(activeTab)[property]===win.getComputedStyle(topActive)[property],'Tab differs from top navigation: '+property);
    }
    check(win.getComputedStyle(activeTab).borderRadius==='0px','Tab still has rounded button styling');
    for (const link of tabs.querySelectorAll('a')) {
     link.focus();check(doc.activeElement===link&&win.getComputedStyle(link).outlineStyle!=='none','Tab keyboard focus missing');
     check(new URL(link.href).pathname==='/equipment','Tab route changed');
     check((new URL(link.href).searchParams.get('tab')||'equipment')===link.textContent.toLowerCase(),'Tab does not select its own content');
    }
    check(tabs.previousElementSibling.classList.contains('equipment-management-heading'),'Tabs not directly below header');
    const add=doc.querySelector('[data-modal-open="add-facility-modal"]');
    add.click();check(doc.getElementById('add-facility-modal').open,'Add Equipment unavailable');
    doc.getElementById('add-facility-modal').querySelector('[data-modal-close]').click();
    if(tab==='equipment') {
     const cards=[...doc.querySelectorAll('.equipment-management-grid > .facility-card')];
     check(cards.length===(role==='admin'?1:2),'Equipment cards missing');
     for(const card of cards) {
      check(width<=600||card.getBoundingClientRect().width<=381,'Equipment card too wide');
      check(card.scrollWidth<=card.clientWidth+1,'Equipment card overflow');
      check(card.querySelector('.facility-image')&&card.querySelector('.availability-badge'),'Image or status missing');
      check(card.textContent.includes('Total Quantity')&&card.textContent.includes('Hourly Rate'),'Equipment facts missing');
      for(const button of card.querySelectorAll('[data-modal-open]')) {
       button.click();const modal=doc.getElementById(button.dataset.modalOpen),box=modal.getBoundingClientRect();
       check(modal.open&&modal.contains(doc.activeElement),'Equipment action or focus failed');
       check(box.left>=0&&box.right<=width+1&&modal.scrollWidth<=modal.clientWidth+1,'Equipment action modal overflow');
       const form=modal.querySelector('form');
       if(form)check(form.action.includes('/facilities/')&&form.querySelector('[name="_token"]'),'Equipment action route or CSRF missing');
       modal.querySelector('[data-modal-close]').click();
      }
     }
     resolve({role,tab,width,passed:true});return;
    }
    check(doc.querySelector('input[name="tab"]').value === 'inventory', 'Search loses active tab');
    check(!!doc.querySelector('select[name="barangay"]') === (role === 'super-admin'), 'Barangay filter scope');
    const table = doc.querySelector('.management-table');
    check(table.querySelectorAll('thead th').length === 11, 'Missing inventory columns');
    for (const button of doc.querySelectorAll('[data-table-actions] [data-modal-open]')) {
     const menu = button.closest('details'); menu.querySelector('summary').scrollIntoView({block:'center',inline:'center'}); menu.querySelector('summary').click();
     button.click();
     const dialog = doc.getElementById(button.dataset.modalOpen), box = dialog.getBoundingClientRect();
     check(dialog.open && dialog.contains(doc.activeElement), 'Modal or focus missing');
     check(box.left >= 0 && box.right <= width+1 && box.top >= 0 && box.bottom <= 901, 'Modal exceeds viewport');
     check(dialog.scrollWidth <= dialog.clientWidth+1, 'Modal content overflow');
     const form = dialog.querySelector('form');
     if (form) {
      check(form.querySelector('[name="confirmed"]').required, 'Confirmation is optional');
      check(form.querySelector('[name="reason"]').required, 'Reason is optional');
      check(form.querySelector('[name="request_key"]').value, 'Missing retry key');
      let posts = 0;
      win.fetch = async () => { posts++; return {ok:false, redirected:false, json:async()=>({errors:{quantity:['Insufficient usable stock.']}})}; };
      form.elements.quantity.value = '5'; form.elements.reason.value = 'Damaged chairs'; form.elements.confirmed.checked = true;
      form.requestSubmit(); form.requestSubmit();
      for (let i=0;i<15;i++) await Promise.resolve();
      check(posts === 1, 'Duplicate adjustment submitted');
      check(dialog.open && form.elements.quantity.nextElementSibling.textContent.includes('Insufficient'), 'Quantity validation message missing');
      check(form.querySelector('[data-action-error]').hidden, 'Field error duplicated in summary');
     }
     dialog.querySelector('[data-modal-close]').click(); check(!dialog.open, 'Close failed');
    }
    resolve({role,tab,width,passed:true});
   } catch(error) { resolve({role,tab,width,passed:false,error:error.message}); } finally { frame.remove(); } };
   frame.srcdoc = html; document.body.appendChild(frame);
  }));
 }
 document.getElementById('result').textContent = JSON.stringify(results);
})();</script></body></html>`);
runBrowser(page, path.join(directory, 'chrome-inventory')).then(results => {
    fs.writeFileSync(path.join(directory, 'results.json'), JSON.stringify(results, null, 2));
    assert.equal(results.length, 32);
    assert.ok(results.every(result => result.passed), JSON.stringify(results.filter(result => !result.passed)));
    console.log('Equipment and Inventory browser checks passed: 32 role/tab/viewport combinations.');
}).catch(error => { console.error(error); process.exitCode = 1; });
