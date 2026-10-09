// First run: php tests/Frontend/ui-consistency-fixtures.php
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const runBrowser = require('./run-browser.cjs');
const directory = path.resolve('storage/app/ui-consistency-check');
const selectedPages = process.argv.slice(2);
const css = ['app', 'admin-analytics', 'super-admin-analytics', 'dashboard-overview', 'resident-reservations']
    .map(name => fs.readFileSync('public/css/' + name + '.css', 'utf8')).join('\n');
const scripts = ['modals', 'mobile-navigation', 'account-management', 'facility-management', 'facility-gallery', 'auth', 'reservation-datatable', 'vendor/chart.umd.min', 'admin-analytics', 'super-admin-analytics']
    .map(name => fs.readFileSync('public/js/' + name + '.js', 'utf8')).join('\n');
const sources = fs.readdirSync(directory).filter(name => name.endsWith('.html') && /^(resident|admin|super-admin|auth)-/.test(name) && !name.endsWith('-preview.html'))
    .filter(name => !selectedPages.length || selectedPages.includes(name.replace('.html', '')))
    .map(name => {
        const source = fs.readFileSync(path.join(directory, name), 'utf8');
        const pageScripts = ['page-filters', 'profile', 'resident-reservations', 'reservation-time-validation'].filter(script => source.includes('js/' + script + '.js'))
            .map(script => fs.readFileSync('public/js/' + script + '.js', 'utf8')).join('\n');
        let html = source
            .replace(/<link\b[^>]*>/g, '')
            .replace(/<script\b(?![^>]*type="application\/json")[^>]*>[\s\S]*?<\/script>/g, '');
        html = html.replace('</head>', '<style>' + css + '</style></head>')
            .replace('</body>', '<script>' + scripts + '\n' + pageScripts + '</script></body>');
        fs.writeFileSync(path.join(directory, name.replace('.html', '-preview.html')), html);
        return {name: name.replace('.html', ''), html};
    });
const encode = value => JSON.stringify(value).replace(/</g, '\\u003c');
const residentDetails = fs.readFileSync(path.join(directory, 'oversight-resident-details.html'), 'utf8');
const page = path.join(directory, 'browser.html');
fs.writeFileSync(page, `<!doctype html><html><body><pre id="result">RUNNING</pre><script>
const sources = ${encode(sources)};
const selectedPages = ${encode(selectedPages)};
const residentDetails = ${encode(residentDetails)};
(async () => {
 const results = [], createStyles = {};
 for (const {name, html} of sources) for (const width of (selectedPages.length
  ? [1920, 1680, 1440, 1366, 1280, 1024, 900, 768, 640, 390, 320]
  : [1440, 1280, 1024, 768, 390, 320])) {
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
     if (/accounts/.test(name) || name === 'super-admin-residents') {
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
      if (name === 'super-admin-residents') {
       check(!create && !doc.querySelector('[data-account-status]'), 'Super Admin resident mutation action');
       check(values.has('barangay'), 'Missing resident Barangay filter');
       check(doc.querySelector('[data-account-view]') && doc.getElementById('account-details'), 'Missing resident View modal');
       let requests = 0;
       win.fetch = async (_, options) => {
        check(!options.method || options.method === 'GET', 'Resident View sent a mutation');
        requests++;
        return {ok: true, redirected: false, text: async () => residentDetails};
       };
       doc.querySelector('[data-account-view]').click();
       await new Promise(resolve => setTimeout(resolve, 0));
       const modal = doc.getElementById('account-details');
       check(requests === 1 && modal.open && modal.querySelector('[data-account-details]'), 'Resident View did not load');
       check(!modal.querySelector('[data-account-status], form, input, select'), 'Resident details contains mutation controls');
       check(modal.querySelector('.facility-modal-actions').textContent.trim() === 'Close', 'Resident View footer is not Close only');
       modal.querySelector('[data-modal-close]').click();
      }
     }
     if (name === 'super-admin-payments') {
      check(doc.querySelectorAll('.reservation-datatable thead th').length === 9, 'Missing cross-barangay payment column');
      check(!doc.querySelector('[data-reservation-open^="payment-edit-"]') && !doc.querySelector('dialog[id^="payment-edit-"]'), 'Super Admin payment mutation action');
      const values = new win.FormData(doc.getElementById('payment-filters'));
      check(['search','barangay','status','from_date','to_date'].every(key => values.has(key)), 'Missing payment filter');
      const buttons = [...doc.querySelector('.filter-actions').children];
      check(buttons.length === 2 && buttons[0].textContent.trim() === 'Apply' && buttons[1].textContent.trim() === 'Reset', 'Payment Apply/Reset missing');
      check(Math.abs(rect(buttons[0]).height - rect(buttons[1]).height) < 1, 'Payment Apply/Reset heights differ');
      const view = doc.querySelector('[data-reservation-open^="payment-view-"]');
      view.click();
      const modal = doc.getElementById(view.dataset.reservationOpen);
      check(modal.open, 'Payment View did not open');
      check(!modal.querySelector('form, input, select'), 'Payment View contains editable fields');
      check(modal.querySelector('.facility-modal-actions').textContent.trim() === 'Close', 'Payment View footer is not Close only');
      modal.querySelector('[data-reservation-close]').click();
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
     for (const link of nav.filter(visible)) {
      check(link.scrollWidth <= link.clientWidth + 1, 'Navigation text clips');
      const icon = link.querySelector('svg'); if (icon) check(rect(icon).width === 16, 'Navigation icon shrinks');
      check(rect(link).height >= 44, 'Navigation touch target too short');
      const label = link.querySelector('.nav-label');
      if (label) check(label.scrollWidth <= label.clientWidth + 1, 'Navigation label clips');
     }
     if (doc.querySelector('.user-nav') && width <= 900) {
      check(!visible(doc.querySelector('.user-nav')), 'Mobile horizontal navigation remains visible');
      check(visible(doc.querySelector('.mobile-menu-button')), 'Mobile hamburger missing');
      const menu = doc.querySelector('.mobile-menu-button');
      menu.click(); check(doc.getElementById('mobile-navigation').open, 'Drawer did not open');
      const links = [...doc.querySelectorAll('.drawer-link')];
      check(JSON.stringify(links.map(link => [link.href, link.textContent.trim()])) === JSON.stringify(nav.map(link => [link.href, link.textContent.trim()])), 'Drawer destinations differ from desktop');
      check(links.filter(link => link.getAttribute('aria-current') === 'page').length === 1, 'Drawer current page missing or duplicated');
      for (const link of links) {
       check(link.scrollWidth <= link.clientWidth + 1, 'Drawer navigation text clips');
       check(rect(link).height >= 44, 'Drawer navigation touch target too short');
       check(rect(link.querySelector('svg')).width === 22, 'Drawer navigation icon shrinks');
       const label = link.querySelector('.nav-label');
       check(label.scrollWidth <= label.clientWidth + 1, 'Drawer navigation label clips');
      }
      check(win.getComputedStyle(links.find(link => link.classList.contains('active'))).backgroundColor === 'rgb(238, 245, 255)', 'Drawer active item background missing');
      doc.getElementById('mobile-navigation').close();
      await new Promise(resolve => setTimeout(resolve, 0));
     }
     if (name.startsWith('super-admin-') && width > 900) {
      const expected = ['Super Admin Dashboard', 'Calendar', 'Reservation Management', 'Official Use', 'Payments', 'Facility Management', 'Resident Management', 'Admin Management', 'Analytics', 'Profile'];
      check(JSON.stringify(nav.map(link => link.textContent.trim())) === JSON.stringify(expected), 'Super Admin navigation order');
      const container = doc.querySelector('.nav-inner'), active = nav.filter(link => link.classList.contains('active'));
      check(active.length === 1, 'Navigation active state missing or duplicated');
      check(win.getComputedStyle(active[0]).backgroundColor === 'rgb(238, 245, 255)', 'Active item background missing');
      if (name === 'super-admin-accounts') check(active[0].textContent.trim() === 'Admin Management', 'Admin Management not active');
      if (width > 900) {
       check(nav.every(link => Math.abs(rect(link).top - rect(nav[0]).top) < 1), 'Super Admin navigation split across rows');
       check(rect(nav[7]).width < rect(container).width / 4, 'Admin Management spans the navigation width');
       check(nav.every(link => parseFloat(win.getComputedStyle(link).fontSize) >= 16), 'Navigation text was squeezed');
      }
      if (width >= 1280) check(container.scrollWidth <= container.clientWidth + 1, 'Desktop navigation requires scrolling');
      if (width <= 1024 && container.scrollWidth > container.clientWidth) {
       container.scrollLeft = container.scrollWidth;
       check(container.scrollLeft > 0, 'Narrow navigation cannot scroll to final modules');
       check(rect(nav.at(-1)).right <= rect(container).right + 1, 'Profile inaccessible after scrolling');
       container.scrollLeft = 0;
      }
      for (let index = 1; index < nav.length; index++) check(rect(nav[index]).left >= rect(nav[index - 1]).right - 1, 'Navigation items overlap');
     }
     for (const modal of doc.querySelectorAll('dialog.ereserve-modal')) {
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
