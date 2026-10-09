// Render fixtures with PENDING_EDIT_RENDER_DIR=storage/app/pending-edit-check first.
const fs = require('node:fs'), path = require('node:path'), assert = require('node:assert/strict');
const runBrowser = require('./run-browser.cjs');
const directory = path.resolve('storage/app/pending-edit-check');
const before = fs.readFileSync(path.join(directory, 'resident-before.html'), 'utf8');
const after = fs.readFileSync(path.join(directory, 'resident-after.html'), 'utf8');
const css = ['app', 'resident-reservations'].map(name => fs.readFileSync('public/css/' + name + '.css', 'utf8')).join('\n');
const scripts = ['modals', 'page-filters', 'resident-reservations'].map(name => fs.readFileSync('public/js/' + name + '.js', 'utf8')).join('\n');
const source = before.replace(/<link\b[^>]*>/g, '').replace(/<script\b[^>]*>[\s\S]*?<\/script>/g, '')
    .replace('</head>', '<style>' + css + '</style></head>').replace('</body>', '<script>' + scripts + '</script></body>');
const encode = value => JSON.stringify(value).replace(/</g, '\\u003c');
const page = path.join(directory, 'resident-browser.html');
fs.writeFileSync(page, `<!doctype html><html><body><pre id="result">RUNNING</pre><script>
const source = ${encode(source)}, after = ${encode(after)};
(async () => {
 const results = [];
 for (const width of [1440, 768, 390, 320]) results.push(await new Promise(resolve => {
  const frame = document.createElement('iframe'); frame.style.width = width + 'px'; frame.style.height = (width < 400 ? 568 : 900) + 'px';
  frame.onload = async () => { try {
   const doc = frame.contentDocument, win = frame.contentWindow;
   const check = (ok, message) => { if (!ok) throw Error(message); };
   const tick = () => new Promise(resolve => setTimeout(resolve, 0));
   const settle = async () => { for (let i = 0; i < 6; i++) await tick(); };
   let posts = 0, gets = 0, complete, posted, target, refreshedUrl, failRefresh = false, filteredOut = false;
   win.fetch = async (url, options = {}) => {
    check(options.cache === 'no-store', 'Private actions must not use an offline response');
    if (options.method === 'POST') { posts++; target = String(url); posted = options.body; return new Promise(resolve => complete = resolve); }
    gets++; refreshedUrl = url; return failRefresh ? {ok:false} : {ok:true, redirected:false, text:async () => {
     if (!filteredOut) return after;
     const next = new win.DOMParser().parseFromString(after, 'text/html');
     next.querySelector('.resident-reservations').remove();
     return next.documentElement.outerHTML;
    }};
   };
   const bounds = dialog => {
    const box = dialog.getBoundingClientRect();
    check(box.left >= 0 && box.right <= width + 1 && box.top >= 0 && box.bottom <= win.innerHeight + 1, 'Modal outside viewport');
    check(dialog.scrollWidth <= dialog.clientWidth + 1, 'Modal horizontal overflow');
    check(dialog.contains(doc.activeElement), 'Focus did not enter dialog');
    check(doc.documentElement.classList.contains('modal-open'), 'Background scroll not locked');
    const footer = dialog.querySelector('.facility-modal-actions').getBoundingClientRect();
    check(footer.top >= box.top && footer.bottom <= box.bottom + 1, 'Modal actions inaccessible');
   };
   const open = id => { const trigger = doc.querySelector('[data-modal-open="' + id + '"]'); check(trigger, 'Missing trigger ' + id); trigger.focus(); trigger.click(); const dialog = doc.getElementById(id); check(dialog.open, 'Dialog did not open'); bounds(dialog); return {dialog, trigger}; };
   check(doc.querySelectorAll('[data-pending-reservation-action]').length === 1, 'Only eligible Pending owner may edit');
   check(!doc.querySelector('a[href$="missing-resource"]'), 'Broken legacy resource link');
   check(!doc.querySelector('.admin-reservation-table'), 'Admin controls exposed');
   check(doc.documentElement.scrollWidth <= width + 1, 'Page horizontal overflow');
   for (const button of doc.querySelectorAll('.resident-card-actions > *')) check(button.getBoundingClientRect().height >= 44, 'Action touch target too small');
   let submits = 0; let filters = doc.querySelector('.resident-reservation-filters'); filters.submit = () => submits++;
   filters.elements.status.value = 'pending'; filters.elements.status.dispatchEvent(new win.Event('change'));
   filters.elements.sort.value = 'facility'; filters.elements.sort.dispatchEvent(new win.Event('change'));
   check(submits === 2, 'Initial status/sort filter hooks broken');
   check(filters.elements.search.placeholder === 'Search resource or purpose...', 'Unsupported search advertised');
   let {dialog, trigger} = open('resident-details-1');
   check(dialog.textContent.includes('Original purpose') && dialog.textContent.includes('8:00 AM') && dialog.textContent.includes('9:00 AM'), 'Details schedule/purpose wrong');
   check(!dialog.textContent.includes('Payment Status') && !dialog.textContent.includes('Total Paid') && !dialog.textContent.includes('999.00'), 'Pending payment leaked');
   dialog.querySelector('[data-modal-close]').click(); await settle(); check(doc.activeElement === trigger, 'Close did not restore focus');
   ({dialog, trigger} = open('resident-details-2'));
   check(dialog.textContent.includes('Refunded') && dialog.textContent.includes('Total Payment') && !dialog.textContent.includes('Total Paid'), 'Refunded payment labeled paid');
   dialog.querySelector('[data-modal-close]').click(); await settle();
   ({dialog, trigger} = open('resident-edit-1'));
   let form = dialog.querySelector('form');
   check(form.querySelector('input[readonly]').value === 'Original Court' && !form.querySelector('[name=facility_id]'), 'Resource editable');
   for (const [name, value] of Object.entries({reservation_date:'2026-10-15', start_time:'08:00', end_time:'09:00', purpose:'Original purpose', attendees:'5'})) check(form.elements[name].value === value, 'Incorrect prefill ' + name);
   // Native Escape emits cancel then closes unless prevented; exercise that contract.
   const cancel = new win.Event('cancel', {cancelable:true}); dialog.dispatchEvent(cancel); check(!cancel.defaultPrevented, 'Idle Escape blocked'); dialog.close(); await settle(); check(doc.activeElement === trigger, 'Escape did not restore focus');
   ({dialog} = open('resident-edit-1')); form = dialog.querySelector('form');
   form.elements.start_time.value = '14:00'; form.elements.end_time.value = '16:00'; form.elements.purpose.value = 'Updated purpose'; form.elements.attendees.value = '12';
   form.requestSubmit(); form.dispatchEvent(new win.Event('submit', {bubbles:true, cancelable:true}));
   check(posts === 1 && dialog.hasAttribute('aria-busy'), 'Duplicate save or missing busy state');
   check([...dialog.querySelectorAll('button,input,textarea')].every(control => control.disabled), 'Busy controls remain enabled');
   const busyCancel = new win.Event('cancel', {cancelable:true}); dialog.dispatchEvent(busyCancel); check(busyCancel.defaultPrevented, 'Escape allowed during save');
   check(target.endsWith('/reservations/1/pending') && posted.get('_method') === 'PATCH' && posted.has('_token'), 'Endpoint/method/CSRF incorrect');
   check(posted.get('start_time') === '14:00' && posted.get('attendees') === '12', 'Edited values not submitted');
   complete({ok:false,status:422,json:async () => ({errors:{start_time:['Schedule rejected.'],purpose:['Purpose rejected.']}})}); await settle();
   check(dialog.open && form.elements.purpose.value === 'Updated purpose' && form.elements.start_time.value === '14:00', 'Validation lost input');
   check(form.elements.start_time.getAttribute('aria-invalid') === 'true' && form.elements.purpose.getAttribute('aria-invalid') === 'true', 'Field errors missing');
   check(doc.activeElement === form.elements.start_time && dialog.textContent.includes('Schedule rejected.'), 'Invalid field focus/message missing');
   form.requestSubmit(); complete({ok:true,json:async () => ({success:true,message:'Reservation request updated successfully.'})}); await settle();
   check(posts === 2 && gets === 1 && !doc.querySelector('dialog[open]'), 'Save did not close and refresh');
   check(refreshedUrl === win.location.href, 'Refresh discarded current URL filters');
   check(doc.getElementById('resident-reservation-1').textContent.includes('2:00 PM - 4:00 PM') && doc.getElementById('resident-reservation-1').textContent.includes('Updated purpose'), 'Updated card stale');
   check(doc.activeElement.id === 'resident-details-button-1', 'Focus lost after main refresh');
   filters = doc.querySelector('.resident-reservation-filters'); filters.submit = () => submits++;
   doc.dispatchEvent(new win.Event('modals:updated')); doc.dispatchEvent(new win.Event('modals:updated'));
   filters.elements.status.dispatchEvent(new win.Event('change')); filters.elements.sort.dispatchEvent(new win.Event('change'));
   check(submits === 4, 'Refreshed filters missing or duplicate listeners');
   ({dialog} = open('resident-edit-1')); form = dialog.querySelector('form');
   check(form.elements.start_time.value === '14:00', 'Reopened edit used stale values');
   form.requestSubmit(); complete({ok:false,status:422,json:async () => ({errors:{reservation:['This reservation is no longer pending.']}})}); await settle();
   check(dialog.open && dialog.querySelector('[data-action-error]').textContent.includes('no longer pending'), 'Admin race response hidden');
   failRefresh = true; form.requestSubmit(); complete({ok:true,json:async () => ({success:true,message:'Saved.'})}); await settle();
   check(!dialog.open && doc.querySelector('.reservation-table-toast').textContent.includes('saved, but'), 'Refresh failure invites duplicate edit');
   failRefresh = false; filteredOut = true;
   ({dialog} = open('resident-edit-1')); form = dialog.querySelector('form'); form.requestSubmit();
   complete({ok:true,json:async () => ({success:true,message:'Saved.'})}); await settle();
   check(doc.activeElement.id === 'resident-reservations-heading', 'Focus lost when updated purpose leaves filtered results');
   check(doc.documentElement.scrollWidth <= width + 1, 'Refreshed page overflow');
   resolve({width, passed:true});
  } catch (error) { resolve({width, passed:false, error:error.message}); } };
  frame.srcdoc = source; document.body.appendChild(frame);
 })); document.getElementById('result').textContent = JSON.stringify(results);
})();
</script></body></html>`);
(async () => {
    const results = await runBrowser(page, path.join(directory, 'resident-chrome-' + process.pid));
    console.log(JSON.stringify(results));
    assert.equal(results.filter(result => !result.passed).length, 0, 'Resident reservation browser regressions');
    console.log('Resident reservation browser checks passed: ' + results.length);
})().catch(error => { console.error(error); process.exitCode = 1; });
