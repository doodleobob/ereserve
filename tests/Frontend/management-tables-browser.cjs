// First run: php tests/Frontend/ui-consistency-fixtures.php
const fs = require('node:fs'), path = require('node:path'), assert = require('node:assert/strict');
const runBrowser = require('./run-browser.cjs');
const directory = path.resolve('storage/app/ui-consistency-check');
const css = ['app', 'admin-analytics', 'super-admin-analytics', 'management-tables'].map(name => fs.readFileSync('public/css/' + name + '.css', 'utf8')).join('\n');
const js = ['form-validation', 'modals', 'table-actions', 'reservation-datatable', 'account-management', 'payment-confirmation']
    .map(name => fs.readFileSync('public/js/' + name + '.js', 'utf8')).join('\n');
const names = ['admin-facilities', 'admin-payments', 'admin-reservations', 'admin-official-use', 'admin-accounts',
    'super-admin-payments', 'super-admin-reservations', 'super-admin-official-use', 'super-admin-accounts', 'super-admin-residents',
    'admin-analytics', 'super-admin-analytics', 'oversight-resident-details'];
const sources = names.map(name => {
    const source = fs.readFileSync(path.join(directory, name + '.html'), 'utf8');
    const main = (source.match(/<main\b[^>]*>[\s\S]*?<\/main>/)?.[0] || '<main class="page-shell user-main">' + source + '</main>')
        .replace(/<script\b[^>]*>[\s\S]*?<\/script>/g, '');
    return {name, html: '<!doctype html><html><head><meta name="viewport" content="width=device-width, initial-scale=1"><style>' + css + '</style></head><body class="user-page">' + main + '<script>' + js + '</script></body></html>'};
});
const page = path.join(directory, 'management-tables-browser.html');
fs.writeFileSync(page, `<!doctype html><html><body><pre id="result">RUNNING</pre><script>
const sources = ${JSON.stringify(sources).replace(/</g, '\\u003c')};
(async () => {
 const results = [], reference = {};
 for (const {name, html} of sources) for (const width of [1280, 768, 390, 320]) {
  results.push(await new Promise(resolve => {
   const frame = document.createElement('iframe'); frame.style.width = width + 'px'; frame.style.height = '600px';
   frame.onload = async () => {
    try {
     const doc = frame.contentDocument, win = frame.contentWindow;
     const check = (ok, message) => { if (!ok) throw Error(message); };
     const rect = el => el.getBoundingClientRect(), tick = () => new Promise(resolve => setTimeout(resolve, 0));
     const signature = (el, keys) => { const style = win.getComputedStyle(el); return keys.map(key => style[key]).join('|'); };
     const palette = ['backgroundColor','color'];
     if (name === 'admin-facilities') {
      const footer = doc.querySelector('.admin-facility-card-footer');
      for (const [kind, selector] of [['view','.facility-modal-secondary'], ['edit','.edit-facility-button'], ['danger','.delete-facility-button']]) reference[kind + width] = signature(footer.querySelector(selector), palette);
      reference['border' + width] = win.getComputedStyle(footer.querySelector('.facility-modal-secondary')).borderColor;
     }
     win.fetch = async () => { throw Error('Unexpected mutation during dropdown layout review'); };
     for (const wrap of doc.querySelectorAll('.analytics-table-wrap')) {
      const details = wrap.closest('details'); if (details) details.open = true;
     }
     for (const table of doc.querySelectorAll('main table')) {
      for (const cell of table.querySelectorAll('th, td')) {
       const style = win.getComputedStyle(cell);
       check(style.textAlign === 'center' && style.verticalAlign === 'middle', 'Table cell alignment differs from Reservation Management');
      }
      for (const link of table.querySelectorAll('thead th a')) {
       const label = doc.createRange(); label.selectNodeContents(link.firstChild);
       const text = label.getBoundingClientRect(), icon = link.querySelector('span'), column = rect(link.closest('th'));
       const right = icon ? rect(icon).right : text.right;
       check(Math.abs((text.left + right)/2 - (column.left + column.width/2)) < 2, 'Header label and sort icon are not centered together');
      }
      for (const el of table.querySelectorAll('.reservation-status, [data-table-actions]')) {
       const cell = rect(el.closest('td')), box = rect(el);
       check(Math.abs(box.left + box.width/2 - cell.left - cell.width/2) < 1, 'Badge or Actions trigger not centered');
      }
      const row = table.querySelector('tbody tr');
      if (row && !row.querySelector('[colspan]')) {
       for (const [index, header] of [...table.querySelectorAll('thead th')].entries()) {
        if (!/^(Name|Username|Email|Resident|Resource|Purpose|Facility\\/Equipment)\\b/.test(header.textContent.trim())) continue;
        const cell = row.cells[index]; if (!cell) continue;
        const original = cell.innerHTML;
        cell.textContent = 'Long descriptive name ' + 'UnbrokenName'.repeat(10);
        check(cell.scrollWidth <= cell.clientWidth + 1 && cell.scrollHeight <= cell.clientHeight + 1, 'Long cell text overflows or is clipped');
        check(doc.documentElement.scrollWidth <= width + 1, 'Long cell text causes page overflow');
        cell.innerHTML = original;
       }
      }
     }
     check(doc.documentElement.scrollWidth <= width + 1, 'Page horizontal overflow');
     check(!doc.querySelector('[data-accepted-cancel], .management-table-actions, [data-reservation-open^="payment-edit-"]'), 'New direct actions must not be present');
     const paid = doc.querySelector('table .payment-status-paid');
     if (paid) reference['paid' + width] = signature(paid, ['backgroundColor','color','borderRadius']);
     for (const badge of doc.querySelectorAll('table .reservation-status-accepted')) check(signature(badge, ['backgroundColor','color','borderRadius']) === reference['paid' + width], 'Accepted must retain the Paid green pill');
     doc.body.style.minHeight = '3000px'; doc.querySelector('main').style.marginTop = '600px';
     for (const details of doc.querySelectorAll('[data-table-actions]')) {
      const summary = details.querySelector('summary'), menu = details.querySelector('.reservation-table-menu'), row = details.closest('tr');
      check(details.tagName === 'DETAILS' && summary.querySelectorAll('circle').length === 3, 'Vertical three-dot trigger missing');
      check(!details.open && win.getComputedStyle(menu).display === 'none', 'Actions must initially be hidden');
      summary.scrollIntoView({block:'center',inline:'center'}); await tick(); await tick();
      const height = rect(row).height, columns = [...row.cells].map(cell => rect(cell).width).join(',');
      summary.click();
      check(details.open && summary.getAttribute('aria-expanded') === 'true', 'Dropdown did not open');
      check(rect(row).height === height && [...row.cells].map(cell => rect(cell).width).join(',') === columns, 'Dropdown changed table layout');
      const bounds = rect(menu), style = win.getComputedStyle(menu);
      check(style.position === 'fixed' && style.backgroundColor === 'rgb(255, 255, 255)', 'White floating container changed');
      check(bounds.left >= 0 && bounds.right <= width && bounds.top >= 0 && bounds.bottom <= 600, 'Dropdown outside viewport');
      const actions = [...menu.querySelectorAll('button:not(:disabled), a[href]')];
      let previous;
      for (const action of actions) {
       const label = action.textContent.trim(), style = win.getComputedStyle(action), box = rect(action);
       const kind = label === 'View' ? 'view' : ['Deactivate','Delete','Cancel','Reject'].includes(label) ? 'danger' : 'edit';
       if (['Accept','Approve'].includes(label)) check(style.backgroundColor === 'rgb(21, 128, 61)' && style.color === 'rgb(255, 255, 255)', label + ' positive styling missing');
       else check(signature(action, palette) === reference[kind + width], label + ' colors differ from Facility cards');
       if (label === 'View') check(style.borderColor === reference['border' + width] && style.borderWidth === '1px', 'View border differs from Facility cards');
       check(style.padding === '8px 10px' && style.borderRadius === '6px' && box.height === (width <= 640 ? 44 : 40), 'Compact action geometry changed');
       if (previous) check(Math.abs(box.top - previous.bottom - 2) < 1, 'Vertical action spacing changed');
       check(action.contains(doc.elementFromPoint(box.left + box.width/2, box.top + box.height/2)), 'Dropdown clipped by table overflow');
       previous = box;
      }
      summary.dispatchEvent(new win.KeyboardEvent('keydown', {key:'ArrowDown',bubbles:true}));
      check(doc.activeElement === actions[0], 'Arrow navigation failed');
      actions[0].dispatchEvent(new win.KeyboardEvent('keydown', {key:'Escape',bubbles:true}));
      check(!details.open && doc.activeElement === summary, 'Escape did not close and restore focus');
      summary.click(); doc.body.click(); check(!details.open, 'Outside click did not close');
      win.scrollBy(0, rect(summary).bottom - 590); await tick(); await tick(); summary.click();
      check(rect(menu).bottom <= rect(summary).top && rect(menu).top >= 0, 'Bottom-edge menu must open upward');
      summary.click();
     }
     const statusButton = doc.querySelector('[data-table-actions] [data-account-status] button');
     if (statusButton) {
      statusButton.closest('details').querySelector('summary').click(); statusButton.click();
      const dialog = doc.getElementById('account-confirmation'); check(dialog.open, 'Status confirmation broken');
      win.EReserveModal.close(dialog);
      check(doc.activeElement === statusButton.closest('details').querySelector('summary'), 'Status confirmation focus not restored');
     }
     const accept = doc.querySelector('[data-confirm-payment]');
     if (accept) {
      accept.closest('details').querySelector('summary').click(); accept.click();
      const dialog = doc.getElementById('payment-confirmation'); check(dialog.open, 'Accept confirmation broken'); win.EReserveModal.close(dialog);
     }
     resolve({name,width,passed:true});
    } catch (error) { resolve({name,width,passed:false,error:error.message}); }
    finally { frame.remove(); }
   };
   frame.srcdoc = html; document.body.appendChild(frame);
  }));
 }
 document.getElementById('result').textContent = JSON.stringify(results);
})();
</script></body></html>`);
runBrowser(page, path.join(directory, 'dropdown-chrome-' + process.pid)).then(results => {
    fs.writeFileSync(path.join(directory, 'dropdown-color-results.json'), JSON.stringify(results, null, 2));
    const failures = results.filter(row => !row.passed);
    if (failures.length) console.error(JSON.stringify(failures, null, 2));
    assert.equal(failures.length, 0, 'Dropdown color checks failed');
    console.log('Dropdown color checks passed: ' + results.length + ' page/viewport combinations.');
}).catch(error => { console.error(error); process.exitCode = 1; });
