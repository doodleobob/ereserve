// Render ReservationUiRegressionTest with RESERVATION_UI_RENDER_DIR=storage/app/reservation-ui-check first.
const fs = require('node:fs'), path = require('node:path'), assert = require('node:assert/strict');
const runBrowser = require('./run-browser.cjs');
const directory = path.resolve('storage/app/reservation-ui-check');
const encode = value => JSON.stringify(value).replace(/</g, '\\u003c');
const css = ['app', 'management-tables', 'resident-reservations', 'hourly-booking']
    .map(name => fs.readFileSync(`public/css/${name}.css`, 'utf8')).join('\n');
const sources = ['admin', 'resident', 'resident-overnight'].map(role => {
    const scripts = ['modals', 'table-actions', role === 'admin' ? 'reservation-datatable' : 'resident-reservations']
        .map(name => fs.readFileSync(`public/js/${name}.js`, 'utf8')).join('\n');
    const html = fs.readFileSync(path.join(directory, role + '.html'), 'utf8');
    const source = html.replace(/<link\b[^>]*>/g, '').replace(/<script\b[^>]*>[\s\S]*?<\/script>/g, '')
        .replace('</head>', `<style>${css}</style></head>`).replace('</body>', `<script>${scripts}</script></body>`);
    fs.writeFileSync(path.join(directory, role + '-preview.html'), source);
    return {role, source};
});
const page = path.join(directory, 'browser.html');
fs.writeFileSync(page, `<!doctype html><html><body><pre id="result">RUNNING</pre><script>
(async () => {
 const results = [];
 for (const {role, source} of ${encode(sources)}) {
  for (const width of [375, 390, 430, 768, 1024, 1440]) results.push(await new Promise(resolve => {
   const frame = document.createElement('iframe'); frame.style.width = width + 'px'; frame.style.height = '1000px';
   frame.onload = async () => { try {
    const doc = frame.contentDocument, win = frame.contentWindow;
    const check = (ok, message) => { if (!ok) throw Error(message); };
    const tick = () => new Promise(resolve => setTimeout(resolve, 0));
    const box = element => element.getBoundingClientRect();
    check(doc.documentElement.scrollWidth <= width + 1, 'Page horizontal overflow');
    let rowHeight;
    if (role === 'admin') {
     const table = doc.querySelector('.reservation-datatable'), row = table.querySelector('tbody tr');
     check(table.querySelectorAll('thead th').length === 8, 'Table columns changed');
     const status = row.cells[6];
     check(status.querySelector('.reservation-status').textContent === 'Accepted', 'Actual reservation status lost');
     check(status.textContent.includes('Awaiting User Decision'), 'Resolution missing');
     check(!status.textContent.includes('2026') && !status.querySelector('.official-use-conflict'), 'Full details inside table');
     rowHeight = box(row).height;
     check(rowHeight <= 130, 'Excessive conflict row height: ' + rowHeight);
     const link = status.querySelector('button'); link.click();
     let dialog = doc.querySelector('dialog[open]');
     check(dialog && dialog.textContent.includes('Official Use Conflict #') && dialog.textContent.includes('October 23, 2026'), 'Conflict details inaccessible');
     check(dialog.textContent.includes('12:00 AM') && dialog.textContent.includes('7:00 PM'), 'Conflict times missing');
     check(box(dialog).left >= 0 && box(dialog).right <= width + 1, 'Details overflow');
     dialog.querySelector('[data-reservation-close]').click(); await tick();
     check(doc.activeElement === link, 'Details focus not restored');
     const scroll = doc.querySelector('.reservation-datatable-scroll');
     scroll.scrollLeft = scroll.scrollWidth; await tick();
     const summary = row.querySelector('summary'); summary.scrollIntoView({block: 'center'}); await tick(); summary.click();
     const menu = row.querySelector('.reservation-table-menu'), menuBox = box(menu);
     check(menuBox.left >= 0 && menuBox.right <= width + 1, 'Actions menu outside viewport');
     for (const action of menu.querySelectorAll('button')) {
      const rect = box(action), hit = doc.elementFromPoint(rect.x + rect.width / 2, rect.y + rect.height / 2);
      check(action.contains(hit), 'Actions menu clipped by table scroll container');
     }
     summary.dispatchEvent(new win.KeyboardEvent('keydown', {key: 'ArrowDown', bubbles: true}));
     check(doc.activeElement.textContent.trim() === 'View', 'Action keyboard navigation failed');
     doc.activeElement.click(); check(doc.querySelector('dialog[open]'), 'View menu action failed');
     doc.querySelector('dialog[open] [data-reservation-close]').click(); await tick();
     let lastUrl = '', refreshes = 0;
     win.history.pushState = win.history.replaceState = (_, __, url) => lastUrl = String(url);
     win.fetch = async () => { refreshes++; return {ok: true, redirected: false, text: async () => source}; };
     const change = async (selector, value) => {
      const field = doc.querySelector(selector); field.value = value;
      field.dispatchEvent(new win.Event('change', {bubbles: true})); await tick(); await tick();
     };
     await change('[name="conflict"]', 'official_use'); check(lastUrl.includes('conflict=official_use'), 'Conflict filter failed');
     await change('[name="per_page"]', '25'); check(lastUrl.includes('per_page=25'), 'Page size failed');
     doc.querySelector('th a').click(); await tick(); await tick(); check(lastUrl.includes('sort=id'), 'Sorting failed');
     doc.querySelector('[name="search"]').value = 'Community';
     doc.querySelector('#reservation-filters').dispatchEvent(new win.Event('submit', {bubbles: true, cancelable: true}));
     await tick(); await tick(); check(lastUrl.includes('search=Community') && refreshes === 4, 'Search refresh failed');
    } else {
     const card = doc.querySelector('.resident-reservation-card'), panel = card.querySelector('.official-use-conflict');
     check(card.scrollWidth <= card.clientWidth + 1 && panel.scrollWidth <= panel.clientWidth + 1, 'Card or conflict overflow');
     check(panel.textContent.includes('Awaiting User Decision') && panel.textContent.includes('administrator will process'), 'Conflict explanation missing');
     if (role === 'resident-overnight') check(panel.textContent.includes('October 24, 2026'), 'Overnight end date missing');
     const buttons = [...panel.querySelectorAll('.official-use-conflict-actions > button')];
     check(buttons.length === 2, 'Conflict actions missing');
     const [first, second] = buttons.map(box);
     check(Math.abs(first.width - second.width) < 1 && Math.abs(first.height - second.height) < 1, 'Button dimensions inconsistent');
     check(first.height >= 44, 'Touch targets too small');
     if (width <= 430) check(second.top - first.bottom >= 11 && Math.abs(first.left - second.left) < 1, 'Mobile buttons must stack with gap');
     else check(Math.abs(first.top - second.top) < 1 && second.left - first.right >= 11, 'Wide buttons must sit side by side');
     for (const button of buttons) check(box(button).right <= box(panel).right, 'Action outside panel');
     let posts = 0, complete, body, requestUrl;
     win.fetch = async (url, options) => {
      check(options.method === 'POST', 'Unexpected mutation method'); posts++; body = options.body; requestUrl = String(url);
      return new Promise(resolve => complete = resolve);
     };
     for (const [index, button] of buttons.entries()) {
      button.focus(); check(win.getComputedStyle(button).outlineStyle !== 'none', 'Keyboard focus not visible');
      button.click(); const dialog = doc.querySelector('dialog[open]'), form = dialog.querySelector('form');
      check(dialog.textContent.includes('administrator will process your request'), 'Confirmation workflow changed');
      form.requestSubmit(); form.dispatchEvent(new win.Event('submit', {bubbles: true, cancelable: true}));
      check(posts === index + 1, 'Duplicate preference submission');
      check(requestUrl.includes('/official-use-conflicts/') && body.get('decision') === (index === 0 ? 'reschedule' : 'cancel'), 'Wrong decision endpoint/payload');
      for (const field of ['status', 'reservation_date', 'start_time', 'end_time', 'total_payment']) check(!body.has(field), 'UI submits booking mutation: ' + field);
      check([...dialog.querySelectorAll('button')].every(control => control.disabled), 'Busy confirmation controls not disabled');
      complete({ok: false, json: async () => ({errors: {decision: ['Test validation response']}})}); await tick(); await tick();
      check(dialog.open && !form.querySelector('[data-action-error]').hidden, 'Request error must remain visible');
      dialog.querySelector('[data-modal-close]').click(); await tick();
     }
     for (const action of card.querySelectorAll('.resident-card-actions > *')) check(box(action).height >= 44 && box(action).right <= box(card).right, 'View action layout regressed');
     card.querySelector('.resident-card-actions button').click(); check(doc.querySelector('dialog[open]'), 'View Details failed');
     doc.querySelector('dialog[open] [data-modal-close]').click();
     check(card.querySelector('.resident-card-actions a').pathname.startsWith('/equipment/'), 'View Equipment link changed');
    }
    check(doc.documentElement.scrollWidth <= width + 1, 'Page overflow after interaction');
    resolve({role, width, rowHeight, passed: true});
   } catch (error) { resolve({role, width, passed: false, error: error.message}); } };
   frame.srcdoc = source; document.body.appendChild(frame);
  }));
 }
 document.getElementById('result').textContent = JSON.stringify(results);
})();
</script></body></html>`);
runBrowser(page, path.join(directory, 'chrome-' + process.pid)).then(results => {
    console.log(JSON.stringify(results, null, 2));
    for (const result of results) assert.equal(result.passed, true, JSON.stringify(result));
    console.log(`Reservation UI Chrome checks passed: ${results.length}`);
}).catch(error => { console.error(error); process.exitCode = 1; });
