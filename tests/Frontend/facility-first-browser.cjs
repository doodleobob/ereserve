// Render fixtures by running FacilityFirstReservationTest with FACILITY_FIRST_RENDER_DIR=storage/app/facility-first-check.
const fs = require('node:fs'), path = require('node:path'), assert = require('node:assert/strict');
const runBrowser = require('./run-browser.cjs');
const directory = path.resolve('storage/app/facility-first-check');
const css = ['app', 'resident-booking', 'management-tables', 'hourly-booking'].map(name => fs.readFileSync('public/css/' + name + '.css', 'utf8')).join('\n');
const validation = fs.readFileSync('public/js/form-validation.js', 'utf8') + '\n' + fs.readFileSync('public/js/reservation-time-validation.js', 'utf8');
const filters = fs.readFileSync('public/js/page-filters.js', 'utf8');
const sources = ['dashboard', 'listing', 'details', 'schedules'].map(name => {
    const html = fs.readFileSync(path.join(directory, name + '.html'), 'utf8')
        .replace(/<link\b[^>]*>/g, '').replace(/<script\b[^>]*>[\s\S]*?<\/script>/g, '')
        .replace('</head>', '<style>' + css + '</style></head>')
        .replace('</body>', `<script>window.fetch=async()=>{throw Error('Offline browser fixture');};</script><script data-server-now="${Date.parse('2026-10-15T10:30:00+08:00')}">${validation}</script><script>${filters}</script></body>`);
    return {name, html};
});
const encode = value => JSON.stringify(value).replace(/</g, '\\u003c');
const page = path.join(directory, 'browser.html');
fs.writeFileSync(page, `<!doctype html><html><body><pre id="result">RUNNING</pre><script>
const sources=${encode(sources)};
(async()=>{const results=[];for(const {name,html} of sources)for(const width of [1440,1024,768,390,320])results.push(await new Promise(resolve=>{
 const frame=document.createElement('iframe');frame.style.width=width+'px';frame.style.height='900px';
 frame.onload=()=>{try{
  const doc=frame.contentDocument,win=frame.contentWindow,check=(ok,message)=>{if(!ok)throw Error(message);},rect=el=>el.getBoundingClientRect();
  check(doc.documentElement.scrollWidth<=width+1,'Page horizontal overflow');
  const main=doc.querySelector('main'),forms=[...main.querySelectorAll('form[method="POST"]')];
  check(forms.length===(name==='details'?1:0),'Unexpected reservation form');
  if(name==='dashboard'){
   check(!doc.querySelector('.calendar-grid'),'Dashboard contains full calendar');
   check(main.textContent.includes('My Pending Reservations')&&main.textContent.includes('My Accepted Reservations'),'Personal counts missing');
   check([...doc.querySelectorAll('nav a')].some(a=>a.href.endsWith('/facilities')),'Browse link broken');
   check([...doc.querySelectorAll('nav a')].some(a=>a.href.endsWith('/calendar')),'Schedule link broken');
  }
  if(name==='listing'){
   const actions=doc.querySelectorAll('.resident-facility-card-footer a');
   check(actions.length===1&&actions[0].textContent==='View Facility'&&actions[0].href.endsWith('/facilities/taft-hall'),'Dedicated details action broken');
   check(!main.querySelector('[data-modal-open^="reserve-facility-"]'),'Duplicate booking entry point');
   check(rect(actions[0]).height>=44,'Small card touch target');
  }
  if(name==='details'||name==='schedules'){
   check(doc.querySelectorAll('.calendar-grid').length===1,'Calendar missing or duplicated');
   for(const direction of ['Previous month','Next month']){
    const link=doc.querySelector('[aria-label="'+direction+'"]');
    check(link.href.includes(name==='details'?'/facilities/taft-hall?':'/calendar?'),'Month navigation loses resource');
    check(link.href.includes('month=2026-'+(direction==='Next month'?'11':'09')),'Wrong adjacent month');
   }
  }
  if(name==='details'){
   check(!doc.querySelector('[aria-label="Calendar filters"]'),'Resource needs selecting again');
   const info=doc.querySelector('.resident-facility-information'),calendar=doc.querySelector('.calendar-card'),schedule=doc.querySelector('.day-schedule-card'),booking=doc.querySelector('#reservation');
   check(rect(info).bottom<=rect(calendar).top,'Calendar appears before facility information');
   if(width>900)check(rect(booking).left>=rect(calendar).right,'Desktop form not beside calendar');
   else check(rect(booking).top>=rect(schedule).bottom,'Mobile form not stacked');
   check(rect(schedule).top>=rect(calendar).bottom,'Daily schedule not below calendar');
   const form=forms[0];check(form.action.endsWith('/facilities/taft-hall/reservations'),'Wrong reservation endpoint');
   check(form.elements.reservation_date.value==='2026-10-16'&&form.elements.start_time.value==='23:00'&&form.elements.end_time.value==='01:00'&&form.elements.end_next_day.value==='1','Selected hourly overnight time lost');
   check(form.querySelector('[data-booking-end]').textContent.includes('October 17, 2026'),'Overnight summary missing');
   check(form.querySelector('button[type=submit]').disabled,'Unverified offline selection allowed submission');
   const choose=form,start=choose.elements.start_time,end=choose.elements.end_time;
   check(start.tagName==='SELECT'&&end.tagName==='SELECT','Hourly dropdowns missing');
   end.value=start.value;end.dispatchEvent(new win.Event('input',{bubbles:true}));
   check(choose.querySelector('button[type=submit]').disabled,'Equal times accepted');
   end.value='01:00';end.dispatchEvent(new win.Event('input',{bubbles:true}));
   check(choose.querySelector('button[type=submit]').disabled,'Unverified overnight selection allowed submission');
   for(const label of choose.querySelectorAll('label'))check(label.control,'Time input label disconnected');
  }
  if(name==='schedules'){
   const form=doc.querySelector('[aria-label="Calendar filters"]');check(form.elements.barangay.value==='Taft'&&form.elements.facility.value==='taft-hall','Central filters lost');
   check(!doc.querySelector('.schedule-resource-link a,.custom-time-selection'),'All Schedules should remain read-only');
  }
  resolve({name,width,passed:true});
 }catch(error){resolve({name,width,passed:false,error:error.message});}finally{frame.remove();}};
 frame.srcdoc=html;document.body.appendChild(frame);
}));document.getElementById('result').textContent=JSON.stringify(results);})();</script></body></html>`);
runBrowser(page, path.join(directory, 'chrome-' + process.pid)).then(results => {
    fs.writeFileSync(path.join(directory, 'results.json'), JSON.stringify(results, null, 2));
    const failures = results.filter(result => !result.passed);
    if (failures.length) console.error(JSON.stringify(failures, null, 2));
    assert.equal(failures.length, 0);
    console.log('Facility-first browser checks passed: ' + results.length + ' page/viewport scenarios.');
}).catch(error => {console.error(error); process.exitCode = 1;});
