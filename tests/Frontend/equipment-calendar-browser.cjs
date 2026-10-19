// Fixtures: EQUIPMENT_CALENDAR_RENDER_DIR=storage/app/equipment-calendar-check with EquipmentCalendarPresentationTest.
const fs = require('node:fs'), path = require('node:path'), assert = require('node:assert/strict');
const runBrowser = require('./run-browser.cjs');
const directory = path.resolve('storage/app/equipment-calendar-check');
const css = ['app', 'resident-booking', 'management-tables'].map(name => fs.readFileSync('public/css/' + name + '.css', 'utf8')).join('\n');
const fixtures = ['empty', 'partial', 'full', 'combined', 'within-stock', 'over-stock', 'overlap', 'continued', 'unavailable', 'unverified', 'legacy', 'facility'].map(name => {
    const source = fs.readFileSync(path.join(directory, name + '.html'), 'utf8')
        .replace(/<link\b[^>]*>/g, '').replace(/<script\b[^>]*>[\s\S]*?<\/script>/g, '')
        .replace('</head>', '<style>' + css + '</style></head>');
    if (name === 'combined') fs.writeFileSync(path.join(directory, 'preview.html'), source);
    return {name, source};
});
const page = path.join(directory, 'browser.html');
fs.writeFileSync(page, `<!doctype html><html><body><pre id="result">RUNNING</pre><script>
const fixtures = ${JSON.stringify(fixtures).replace(/</g, '\\u003c')};
(async () => {
 const results = [];
 for (const {name, source} of fixtures) for (const width of [1440, 1024, 768, 390, 320]) {
  results.push(await new Promise(resolve => {
   const frame = document.createElement('iframe'); frame.style.width = width + 'px'; frame.style.height = '950px';
   frame.onload = () => {
    try {
     const doc = frame.contentDocument, win = frame.contentWindow;
     const check = (value, message) => {if (!value) throw Error(message)};
     const rect = el => el.getBoundingClientRect();
     check(doc.documentElement.scrollWidth <= width + 1, 'Horizontal page overflow');
     const calendar = doc.querySelector('.calendar-card'), panel = doc.querySelector('.day-schedule-card');
     const list = panel.querySelector('.schedule-list'), heading = panel.querySelector('header');
     if (width > 900) check(rect(panel).left >= rect(calendar).right, 'Desktop panels are not side by side');
     else check(rect(panel).top >= rect(calendar).bottom, 'Mobile panels are not stacked');
     check(list.scrollWidth <= list.clientWidth + 1, 'Schedule list horizontal overflow');
     check(rect(list).height <= (width > 900 ? 482 : 402), 'Schedule list is too tall');
     const headingTop = rect(heading).top;
     list.scrollTop = list.scrollHeight;
     check(rect(heading).top === headingTop, 'Date heading scrolled with list');
     if (name !== 'facility') check(list.scrollTop > 0, 'Long schedule does not scroll independently');
     list.scrollTop = 0;
     const form = doc.querySelector('.custom-time-selection');
     if (form) {
      check(!list.contains(form), 'Time inputs trapped inside schedule scroll');
      check(rect(form).top >= rect(list).bottom, 'Time inputs overlap schedule');
      check(form.querySelector('[name="start_time"]').type === 'time' && form.querySelector('[name="end_time"]').type === 'time', 'Custom time inputs missing');
     }
     for (const card of doc.querySelectorAll('.equipment-slot,.equipment-event')) {
      const time = rect(card.querySelector('.equipment-slot-time')), status = rect(card.querySelector('.equipment-slot-status'));
      check(time.right <= status.left + 1 || time.bottom <= status.top + 1 || status.bottom <= time.top + 1, 'Time and status overlap');
      check(rect(card.querySelector('.equipment-slot-allocation')).top >= Math.max(time.bottom, status.bottom) - 1, 'Quantity overlaps heading');
      check(card.scrollWidth <= card.clientWidth + 1, 'Card label overflow');
     }
     if (name === 'combined') {
      const slot = doc.querySelector('[data-equipment-slot="09:00"]');
      check(slot.textContent.includes('Fully Booked') && slot.textContent.includes('40 of 40 units allocated') && slot.textContent.includes('0 units remaining'), 'Combined allocation incorrect');
      check(slot.querySelector('meter').value === 40 && slot.querySelector('meter').max === 40, 'Combined indicator incorrect');
      check(doc.querySelectorAll('[data-event-type]').length === 2, 'Separate booking records missing');
     }
     if (name === 'over-stock') check(doc.querySelector('[data-equipment-slot="09:00"]').textContent.includes('50 of 40 units allocated'), 'Over-allocation was hidden');
     if (name === 'overlap') {
      check(doc.querySelector('[data-equipment-slot="09:00"]').textContent.includes('35 of 40 units allocated'), 'Peak allocation incorrect');
      check(form.elements.start_time.value === '23:17' && form.elements.end_time.value === '01:09', 'Overnight minute selection lost');
     }
     if (['unavailable', 'unverified', 'facility'].includes(name)) check(!panel.querySelector('meter'), 'Equipment indicator on unavailable stock or facility');
     for (const link of doc.querySelectorAll('a.equipment-slot-time')) {
      const url = new URL(link.href);
      check(url.searchParams.has('start_time') && url.searchParams.has('end_time') && url.hash === '#reservation', 'Time selection link lost context');
      check(rect(link).height >= 44, 'Time link is not touch friendly');
     }
     check(doc.querySelectorAll('.calendar-nav-button').length === 2 && doc.querySelectorAll('a.calendar-day').length === 31, 'Calendar navigation changed');
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
    assert.ok(results.every(result => result.passed), 'Equipment calendar browser checks failed');
}).catch(error => {console.error(error); process.exitCode = 1;});
