// Render ResidentReservationPresentationTest with RESIDENT_PRESENTATION_RENDER_DIR first.
const fs = require('node:fs'), path = require('node:path'), assert = require('node:assert/strict');
const runBrowser = require('./run-browser.cjs');
const directory = path.resolve('storage/app/resident-presentation-check');
const encode = value => JSON.stringify(value).replace(/</g, '\\u003c');
const html = fs.readFileSync(path.join(directory, 'reservations.html'), 'utf8');
const css = ['app', 'resident-reservations', 'hourly-booking'].map(name => fs.readFileSync(`public/css/${name}.css`, 'utf8')).join('\n');
const scripts = ['form-validation', 'modals', 'page-filters', 'resident-reservations', 'mobile-navigation'].map(name => fs.readFileSync(`public/js/${name}.js`, 'utf8')).join('\n');
const source = html.replace(/<link\b[^>]*>/g, '').replace(/<script\b[^>]*>[\s\S]*?<\/script>/g, '')
    .replace('</head>', `<style>${css}</style></head>`).replace('</body>', `<script>${scripts}</script></body>`);
fs.writeFileSync(path.join(directory, 'preview.html'), source);
const page = path.join(directory, 'browser.html');
fs.writeFileSync(page, `<!doctype html><html><body><pre id="result">RUNNING</pre><script>
(async () => {
 const results = [];
 for (const width of [1440, 1024, 768, 600, 390, 320]) results.push(await new Promise(resolve => {
  const frame = document.createElement('iframe');
  frame.style.width = width + 'px'; frame.style.height = '900px';
  frame.onload = async () => { try {
   const doc = frame.contentDocument, win = frame.contentWindow;
   const check = (ok, message) => { if (!ok) throw Error(message); };
   const tick = () => new Promise(resolve => setTimeout(resolve, 0));
   check(doc.documentElement.scrollWidth <= width + 1, 'Page horizontal overflow');
   const cards = [...doc.querySelectorAll('.resident-reservation-card')];
   check(cards.length === 3, 'Missing fixture cards');
   for (const card of cards) {
    const rect = card.getBoundingClientRect();
    check(rect.left >= 0 && rect.right <= width + 1, 'Card outside viewport');
    check(card.scrollWidth <= card.clientWidth + 1, 'Card horizontal overflow');
    const purpose = card.querySelector('.resident-card-purpose dd');
    check(purpose && card.querySelector('.resident-card-purpose dt').textContent === 'Purpose', 'Purpose label missing');
    check(win.getComputedStyle(purpose).whiteSpace === 'pre-wrap', 'Purpose whitespace lost');
    check(purpose.scrollWidth <= purpose.clientWidth + 1 && purpose.scrollHeight <= purpose.clientHeight + 1, 'Purpose clipped');
    for (const action of card.querySelectorAll('.resident-card-actions > *')) {
     const box = action.getBoundingClientRect();
     check(box.height >= 44 && box.left >= rect.left && box.right <= rect.right + 1, 'Action inaccessible');
    }
    for (const value of card.querySelectorAll('.resident-card-meta dd')) {
     const box = value.getBoundingClientRect();
     check(box.right <= rect.right && box.left >= rect.left, 'Detail outside card');
    }
   }
   const equipment = doc.getElementById('resident-reservation-1');
   check(equipment.querySelector('.resident-resource-type').textContent === 'Equipment', 'Equipment classification missing');
   check(equipment.textContent.includes('20 units') && equipment.textContent.includes('XXX'), 'Stored equipment details changed');
   check(equipment.querySelector('a').pathname === '/equipment/chairs', 'Wrong equipment link');
   const facility = doc.getElementById('resident-reservation-2');
   check(facility.querySelector('.resident-resource-type').textContent === 'Facility', 'Facility classification missing');
   check(facility.querySelector('a').pathname === '/facilities/hall', 'Wrong facility link');
   check(facility.querySelector('.resident-card-purpose dd').getBoundingClientRect().height > 40, 'Long purpose did not wrap');
   const overnight = doc.getElementById('resident-reservation-3');
   for (const text of ['October 22, 2026', 'October 23, 2026', '11:35 PM', '1:10 AM', '1 hour 35 minutes', 'Not recorded (legacy booking)']) {
    check(overnight.textContent.includes(text), 'Missing overnight/legacy detail: ' + text);
   }
   const filters = doc.querySelector('.resident-reservation-filters');
   check(filters.elements.sort.querySelector('[value="facility"]').textContent === 'Resource Name', 'Sort label not updated');
   let submits = 0; filters.submit = () => submits++;
   for (const [name, value] of [['sort', 'facility'], ['status', 'accepted']]) {
    filters.elements[name].value = value; filters.elements[name].dispatchEvent(new win.Event('change'));
   }
   check(submits === 2, 'Filter hooks broken');
   const trigger = doc.getElementById('resident-details-button-3');
   trigger.focus(); trigger.click();
   const dialog = doc.getElementById('resident-details-3');
   check(dialog.open && dialog.contains(doc.activeElement), 'View Details did not open/focus');
   check(dialog.textContent.includes('11:35 PM') && dialog.textContent.includes('1:10 AM'), 'Details lost historical minutes');
   check(dialog.scrollWidth <= dialog.clientWidth + 1, 'Details overflow');
   dialog.querySelector('[data-modal-close]').click(); await tick(); await tick();
   check(!dialog.open && doc.activeElement === trigger, 'Details close/focus broken');
   resolve({width, passed:true});
  } catch (error) { resolve({width, passed:false, error:error.message}); } };
  frame.srcdoc = ${encode(source)}; document.body.appendChild(frame);
 }));
 document.getElementById('result').textContent = JSON.stringify(results);
})();
</script></body></html>`);
(async () => {
    const results = await runBrowser(page, path.join(directory, 'chrome-' + process.pid));
    console.log(JSON.stringify(results));
    assert.equal(results.filter(result => !result.passed).length, 0, 'Reservation presentation regressions');
    console.log(`Reservation presentation browser checks passed: ${results.length} viewports.`);
})().catch(error => { console.error(error); process.exitCode = 1; });
