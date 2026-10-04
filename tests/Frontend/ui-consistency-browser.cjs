// First run: php tests/Frontend/ui-consistency-fixtures.php
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const runBrowser = require('./run-browser.cjs');
const directory = path.resolve('storage/app/ui-consistency-check');
const css = ['app', 'admin-analytics', 'super-admin-analytics', 'dashboard-overview']
    .map(name => fs.readFileSync('public/css/' + name + '.css', 'utf8')).join('\n');
const scripts = ['modals', 'account-management', 'facility-management', 'facility-gallery', 'auth', 'reservation-datatable', 'vendor/chart.umd.min', 'admin-analytics', 'super-admin-analytics']
    .map(name => fs.readFileSync('public/js/' + name + '.js', 'utf8')).join('\n');
const sources = fs.readdirSync(directory).filter(name => name.endsWith('.html') && /^(resident|admin|super-admin|auth)-/.test(name) && !name.endsWith('-preview.html'))
    .map(name => {
        let html = fs.readFileSync(path.join(directory, name), 'utf8')
            .replace(/<link\b[^>]*>/g, '')
            .replace(/<script\b(?![^>]*type="application\/json")[^>]*>[\s\S]*?<\/script>/g, '');
        html = html.replace('</head>', '<style>' + css + '</style></head>')
            .replace('</body>', '<script>' + scripts + '</script></body>');
        fs.writeFileSync(path.join(directory, name.replace('.html', '-preview.html')), html);
        return {name: name.replace('.html', ''), html};
    });
const encode = value => JSON.stringify(value).replace(/</g, '\\u003c');
const page = path.join(directory, 'browser.html');
fs.writeFileSync(page, `<!doctype html><html><body><pre id="result">RUNNING</pre><script>
const sources = ${encode(sources)};
(async () => {
 const results = [], createStyles = {};
 for (const {name, html} of sources) for (const width of [1440, 1280, 1024, 768, 390, 320]) {
  results.push(await new Promise(resolve => {
   const frame = document.createElement('iframe'); frame.style.width = width + 'px'; frame.style.height = '900px';
   frame.onload = async () => {
    try {
     const doc = frame.contentDocument, win = frame.contentWindow;
     const check = (ok, message) => { if (!ok) throw Error(message); };
     const rect = element => element.getBoundingClientRect();
     const visible = element => rect(element).width > 0 && rect(element).height > 0;
     await new Promise(resolve => setTimeout(resolve, 0));
     win.fetch = async () => { throw Error('Unexpected request during layout-only review'); };
     check(doc.documentElement.scrollWidth <= width + 1, 'Page horizontal overflow');
     const create = doc.querySelector('.button-create');
     if (create) {
      const header = create.closest('.page-heading');
      check(header, 'Create action outside page header');
      check(rect(create).height >= 44, 'Create action touch target');
      if (width > 640) {
       check(Math.abs(rect(create).top - rect(header).top) < 1, 'Create top alignment');
       check(Math.abs(rect(create).right - rect(header).right) < 1, 'Create right alignment');
       check(rect(create).width < rect(header).width / 2, 'Create stretches on desktop');
      } else check(rect(create).top >= rect(header.firstElementChild).bottom, 'Create did not stack');
      const style = win.getComputedStyle(create);
      const signature = ['minHeight','padding','fontSize','fontWeight','borderRadius','backgroundColor'].map(key => style[key]).join('|');
      check(!createStyles[width] || createStyles[width] === signature, 'Create family differs between pages');
      createStyles[width] = signature;
      check(rect(create.querySelector('svg')).width === 16, 'Create icon size');
      create.focus(); check(win.getComputedStyle(create).outlineStyle !== 'none', 'Missing create keyboard focus');
      create.click(); const modal = doc.getElementById(create.dataset.modalOpen || create.dataset.reservationOpen);
      check(modal.open, 'Header create modal hook broken'); modal.querySelector('[data-modal-close]').click();
     }
     if (/accounts/.test(name)) {
      const group = doc.querySelector('.filter-actions'), buttons = [...group.children];
      check(buttons.length === 2 && buttons[0].textContent.trim() === 'Apply' && buttons[1].textContent.trim() === 'Reset', 'Missing Apply/Reset group');
      check(Math.abs(rect(buttons[0]).top - rect(buttons[1]).top) < 1, 'Apply/Reset not beside each other');
      check(Math.abs(rect(buttons[0]).height - rect(buttons[1]).height) < 1, 'Apply/Reset heights differ');
      check(buttons.every(button => rect(button).width < rect(group.parentElement).width / 2), 'Filter actions stretch');
      if (width >= 1024) check([...group.parentElement.querySelectorAll('.filter-group input, .filter-group select')].every(control => Math.abs(rect(control).bottom - rect(buttons[0]).bottom) < 1), 'Filter actions misaligned');
      const values = new win.FormData(group.closest('form'));
      check(values.has('search') && values.has('status'), 'Account query field missing');
      if (name === 'admin-accounts-empty') {
       check(rect(doc.querySelector('table')).height < 200, 'Oversized empty table');
       check(rect(doc.querySelector('.account-management-card')).height < 220, 'Oversized empty table card');
      }
      if (name.startsWith('admin-')) check(!create, 'Invented resident create action');
     }
     for (const control of doc.querySelectorAll('.filter-toolbar input:not([type=hidden]), .filter-toolbar select')) {
      check(rect(control).height >= 44, 'Filter control too short');
      check(control.scrollWidth <= control.clientWidth + 1, 'Filter control overflow');
     }
     for (const action of doc.querySelectorAll('.table-actions .button, .reservation-table-menu button')) {
      const menu = action.closest('details'); if (menu) menu.open = true;
      check(rect(action).height >= (width <= 640 ? 44 : 36), 'Table action size');
      check(rect(action).width < 180, 'Table action oversized');
      if (menu) menu.open = false;
     }
     const nav = [...doc.querySelectorAll('.nav-link')];
     for (const link of nav) {
      check(link.scrollWidth <= link.clientWidth + 1, 'Navigation text clips');
      const icon = link.querySelector('svg'); if (icon) check(rect(icon).width === 16, 'Navigation icon shrinks');
     }
     for (const modal of doc.querySelectorAll('dialog')) {
      win.EReserveModal.open(modal);
      check(modal.scrollWidth <= modal.clientWidth + 1, 'Modal horizontal overflow: ' + modal.id);
      check(rect(modal).left >= 0 && rect(modal).right <= width + 1, 'Modal outside viewport');
      for (const footer of modal.querySelectorAll('.facility-modal-actions')) {
       if (!visible(footer)) continue;
       const buttons = [...footer.querySelectorAll('button, a')].filter(visible);
       check(buttons.every(button => rect(button).height >= 44), 'Modal action too short');
       if (width > 640 && buttons.length) check(Math.abs(rect(buttons.at(-1)).right - (rect(footer).right - parseFloat(win.getComputedStyle(footer).paddingRight))) < 1, 'Modal actions not right aligned');
      }
      for (const button of modal.querySelectorAll('.button-danger')) {
       if (visible(button)) check(win.getComputedStyle(button).backgroundColor === 'rgb(220, 38, 38)', 'Danger style overridden');
      }
      win.EReserveModal.close(modal);
     }
     for (const button of doc.querySelectorAll('.auth-button')) {
      check(rect(button).height >= 48 && button.scrollWidth <= button.clientWidth + 1, 'Auth button clips');
     }
     resolve({name, width, passed: true});
    } catch (error) { resolve({name, width, passed: false, error: error.message}); }
    finally { frame.remove(); }
   };
   frame.srcdoc = html; document.body.appendChild(frame);
  }));
 }
 document.getElementById('result').textContent = JSON.stringify(results);
})();
</script></body></html>`);
runBrowser(page, path.join(directory, 'chrome-' + process.pid)).then(results => {
    fs.writeFileSync(path.join(directory, 'results.json'), JSON.stringify(results, null, 2));
    const failures = results.filter(row => !row.passed);
    if (failures.length) console.error(JSON.stringify(failures, null, 2));
    assert.equal(failures.length, 0, 'UI consistency browser checks failed');
    console.log('UI consistency checks passed: ' + results.length + ' page/viewport combinations.');
}).catch(error => { console.error(error); process.exitCode = 1; });
