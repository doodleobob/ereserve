// Render with EQUIPMENT_UI_RENDER_DIR=storage/app/equipment-ui-check and EquipmentQuantityTest.
const fs = require('node:fs'), path = require('node:path'), assert = require('node:assert/strict');
const runBrowser = require('./run-browser.cjs');
const directory = path.resolve('storage/app/equipment-ui-check');
const css = ['app', 'resident-booking', 'management-tables', 'all-schedules'].map(name => fs.readFileSync('public/css/' + name + '.css', 'utf8')).join('\n');
const scripts = ['form-validation', 'modals', 'resource-quantity'].map(name => fs.readFileSync('public/js/' + name + '.js', 'utf8')).join('\n');
const sources = ['equipment-details', 'facility-details', 'management', 'official-use', 'schedules'].map(name => {
    const source = fs.readFileSync(path.join(directory, name + '.html'), 'utf8')
        .replace(/<link\b[^>]*>/g, '').replace(/<script\b[^>]*>[\s\S]*?<\/script>/g, '')
        .replace('</head>', '<style>' + css + '</style></head>')
        .replace('</body>', '<script>window.fetch = async () => ({ok: true, json: async () => ({total_quantity: 100, available_quantity: 70})});\n' + scripts + '</script></body>');
    return {name, source};
});
const encode = value => JSON.stringify(value).replace(/</g, '\\u003c');
const page = path.join(directory, 'browser.html');
fs.writeFileSync(page, `<!doctype html><html><body><pre id="result">RUNNING</pre><script>
const sources = ${encode(sources)};
const tick = () => new Promise(resolve => setTimeout(resolve, 20));
(async () => {
 const results = [];
 for (const {name, source} of sources) for (const width of [1440, 768, 390, 320]) {
  results.push(await new Promise(resolve => {
   const frame = document.createElement('iframe'); frame.style.width = width + 'px'; frame.style.height = '950px';
   frame.onload = async () => {
    try {
     const doc = frame.contentDocument, win = frame.contentWindow;
     const check = (ok, message) => {if (!ok) throw Error(message)};
     await tick();
     check(doc.documentElement.scrollWidth <= width + 1, 'Page has horizontal overflow');
     if (name === 'equipment-details') {
      const form = doc.querySelector('[data-equipment-availability]');
      check(!!form && !!form.elements.quantity && !form.elements.attendees, 'Equipment uses wrong request fields');
      check(form.querySelector('[data-available-quantity]').textContent.includes('70 units'), 'Live quantity did not render');
      check(form.elements.quantity.min === '1' && form.elements.quantity.step === '1', 'Invalid quantity constraints');
      form.elements.quantity.value = '80';
      check(form.elements.quantity.value === '80', 'Quantity cannot be corrected before submitting');
      const bounds = form.elements.quantity.getBoundingClientRect();
      check(bounds.width > 0 && bounds.right <= width + 1, 'Quantity input is clipped');
     }
     if (name === 'facility-details') {
      const form = doc.querySelector('main form.reservation-form[method="POST"]');
      check(!!form.elements.attendees && !form.elements.quantity, 'Facility uses equipment fields');
     }
     if (name === 'management') {
      doc.querySelector('[data-modal-open="add-facility-modal"]').click();
      const dialog = doc.getElementById('add-facility-modal'), form = dialog.querySelector('form');
      form.elements.category.value = 'Equipment'; form.elements.category.dispatchEvent(new win.Event('change', {bubbles: true}));
      check(form.elements.capacity.disabled && !form.elements.total_quantity.disabled && form.elements.total_quantity.required, 'Equipment management fields not switched');
      form.elements.category.value = 'Facility'; form.elements.category.dispatchEvent(new win.Event('change', {bubbles: true}));
      check(!form.elements.capacity.disabled && form.elements.total_quantity.disabled, 'Facility management fields not restored');
      form.elements.category.value = 'Equipment'; form.elements.category.dispatchEvent(new win.Event('change', {bubbles: true}));
      form.reset(); await tick();
      check(!form.elements.capacity.disabled && form.elements.total_quantity.disabled, 'Reset left stale quantity fields');
      check(dialog.getBoundingClientRect().right <= width + 1, 'Management dialog is clipped');
     }
     if (name === 'official-use') {
      const form = doc.getElementById('add-official-use').querySelector('form'), select = form.elements.facility_id;
      select.value = [...select.options].find(option => option.dataset.category === 'Equipment').value;
      select.dispatchEvent(new win.Event('change', {bubbles: true}));
      check(!form.elements.quantity.disabled && form.elements.quantity.required && form.elements.quantity.max === '100', 'Official equipment quantity not enabled');
      select.value = [...select.options].find(option => option.dataset.category === 'Facility').value;
      select.dispatchEvent(new win.Event('change', {bubbles: true}));
      check(form.elements.quantity.disabled, 'Official facility quantity must be disabled');
     }
     if (name === 'schedules') {
      const schedules = doc.querySelector('.browse-schedules');
      check(schedules.textContent.includes('30 of 100 units booked'), 'Schedule quantity missing');
      check(!schedules.querySelector('button, form'), 'All Schedules gained management actions');
     }
     resolve({name, width, passed: true});
    } catch (error) {resolve({name, width, passed: false, error: error.message});}
   };
   frame.srcdoc = source; document.body.appendChild(frame);
  }));
 }
 document.getElementById('result').textContent = JSON.stringify(results);
})();
</script></body></html>`);
runBrowser(page, path.join(directory, 'chrome-profile')).then(results => {
    console.log(JSON.stringify(results));
    assert.ok(results.every(result => result.passed), 'Equipment browser checks failed');
}).catch(error => {console.error(error); process.exitCode = 1;});
