// Render UnifiedResidentBookingTest with UNIFIED_BOOKING_RENDER_DIR=storage/app/unified-booking-check.
const fs = require('node:fs'), path = require('node:path'), assert = require('node:assert/strict');
const runBrowser = require('./run-browser.cjs');
const directory = path.resolve('storage/app/unified-booking-check');
const encode = value => JSON.stringify(value).replace(/</g, '\\u003c');
const css = ['app', 'resident-booking', 'management-tables', 'hourly-booking'].map(name => fs.readFileSync(`public/css/${name}.css`, 'utf8')).join('\n');
const validation = fs.readFileSync('public/js/reservation-time-validation.js', 'utf8');
const booking = fs.readFileSync('public/js/resident-booking.js', 'utf8');
const sources = ['equipment', 'facility'].map(name => {
    const pages = Object.fromEntries(['2026-10-22', '2026-10-23'].map(date => [date, fs.readFileSync(path.join(directory, `${name}-${date}.html`), 'utf8')]));
    const html = pages['2026-10-22'].replace(/<link\b[^>]*>/g, '').replace(/<script\b[^>]*>[\s\S]*?<\/script>/g, '')
        .replace('</head>', `<style>${css}</style></head>`)
        .replace('</body>', `<script>
window.requests=[];window.waiting=[];window.hold=false;window.offline=false;window.calendarOffline=false;
window.fetch=async url=>{
 const parsed=new URL(url),p=parsed.searchParams;
 if(!parsed.pathname.endsWith('/availability')){
  if(window.calendarOffline)throw Error('Offline');
  return {ok:true,text:async()=>(${encode(pages)})[p.get('date')]};
 }
 window.requests.push(p.toString());
 if(window.offline)throw Error('Offline');
 const from=Number(p.get('start_time').slice(0,2)),to=Number(p.get('end_time').slice(0,2)),next=p.get('end_next_day')==='1';
 const hours=to-from+(next?24:0),quantity=Number(p.get('quantity')||1);
 const data={available:quantity<=10,available_quantity:10,hourly_rate:'50.00',estimated_total:(hours*50).toFixed(2),
  message:quantity>10?'Only 10 chairs are available during the selected period.':null};
 if(window.hold)await new Promise(resolve=>window.waiting.push(resolve)); // Deliberately ignore abort to test stale protection.
 return {ok:true,json:async()=>data};
};
</script><script>${booking}</script><script data-server-now="${Date.parse('2026-10-20T10:15:00+08:00')}">${validation}</script></body>`);
    return {name, html};
});
const page = path.join(directory, 'browser.html');
fs.writeFileSync(path.join(directory, 'preview.html'), sources[0].html.replace('</body>', `<script>
const demo=document.querySelector('[data-unified-booking]');
for(const [name,value] of Object.entries({start_time:'23:00',end_time:'03:00',end_next_day:'1',quantity:'4',purpose:'Community gathering'}))demo.elements.namedItem(name).value=value;
demo.elements.namedItem('start_time').dispatchEvent(new Event('change',{bubbles:true}));
document.querySelector('[data-resident-booking]').scrollIntoView();
</script></body>`));
fs.writeFileSync(page, `<!doctype html><html><body><pre id="result">RUNNING</pre><script>
const sources=${encode(sources)},tasks=[];
for(const {name,html} of sources)for(const width of [1440,1024,768,390,320])tasks.push(new Promise(resolve=>{
 const frame=document.createElement('iframe');frame.style.width=width+'px';frame.style.height='950px';
 frame.onload=async()=>{
  const doc=frame.contentDocument,win=frame.contentWindow,errors=[];
  win.addEventListener('error',e=>errors.push(e.message));
  const check=(value,message)=>{if(!value)throw Error(message);},pause=(ms=220)=>new Promise(r=>setTimeout(r,ms));
  try{
   const form=doc.querySelector('[data-unified-booking]'),field=n=>form.elements.namedItem(n),button=form.querySelector('[type=submit]');
   const status=()=>form.querySelector('[data-booking-status]').textContent,output=n=>form.querySelector('[data-booking-'+n+']').textContent;
   const change=(name,value)=>{field(name).value=value;field(name).dispatchEvent(new win.Event('change',{bubbles:true}));};
   const fill=async(start,end,next='0')=>{field('start_time').value=start;field('end_time').value=end;change('end_next_day',next);await pause();};
   const originalUrl=win.location.href;
   check(button.disabled,'Incomplete schedule can submit');
   check(!doc.body.textContent.includes('Use Selected Time'),'Transfer step remains');
   check(doc.querySelectorAll('[name=quantity]').length===(name==='equipment'?1:0),'Duplicate or wrong quantity field');
   const detailPath=(name==='equipment'?'/equipment/':'/facilities/')+'unified-chairs';
   for(const link of doc.querySelectorAll('#availability a[href]'))check(new URL(link.href).pathname===detailPath,'Calendar link uses wrong resource route');
   check(new URL(form.querySelector('a.cancel-reservation-button').href).pathname===(name==='equipment'?'/equipment':'/facilities'),'Cancel link uses wrong resource list');
   field('purpose').value='Keep my event details';if(field('attendees'))field('attendees').value='10';
   await fill('13:00','14:00');check(!button.disabled&&output('duration')==='1 hour'&&output('total').includes('50.00'),'One-hour estimate');
   check(form.querySelector('[data-booking-retry]').getBoundingClientRect().height===0,'Retry button visible without an error');
   change('start_time','12:00');check(button.disabled&&status().includes('Checking'),'Loading state missing');await pause();
   check(output('duration')==='2 hours'&&output('start').includes('12:00'),'Start did not refresh summary');
   change('end_time','17:00');await pause();check(output('duration')==='5 hours'&&output('total').includes('250.00'),'End did not refresh duration and total');
   await fill('23:00','03:00');check(button.disabled&&status().includes('Next day'),'Overnight inferred without intent');
   change('end_next_day','1');await pause();
   check(!button.disabled&&output('duration')==='4 hours'&&output('end').includes('October 23, 2026'),'Overnight end date incorrect');
   const day=doc.querySelector('.calendar-day[href*="date=2026-10-23"]');day.click();await pause();
   check(field('reservation_date').value==='2026-10-23'&&output('end').includes('October 24, 2026'),'Date click did not synchronize form');
   check(doc.querySelector('#daily-schedule-heading').textContent==='October 23, 2026','Hourly details not refreshed');
   check(field('purpose').value==='Keep my event details'&&field('start_time').value==='23:00','Calendar change erased inputs');
   check(win.location.href===originalUrl,'Calendar navigated the page');
   if(name==='equipment'){
    for(const value of ['0','-1','1.5','41']){change('quantity',value);check(button.disabled&&status().includes('whole quantity'),'Invalid quantity '+value);}
    change('quantity','11');await pause();check(button.disabled&&status().includes('Only 10 chairs'),'Peak availability shortage not shown');
    check(field('quantity').value==='11','Shortage silently changed quantity');
    change('quantity','4');await pause();check(!button.disabled&&output('quantity')==='4'&&output('total').includes('200.00'),'Quantity recovery changed pricing');
    check(win.requests.at(-1).includes('quantity=4'),'Quantity did not trigger availability');
   }
   // Resolve newer response first, then the older request, including A -> B -> A.
   win.hold=true;change('end_time','04:00');await pause();change('end_time','05:00');await pause();change('end_time','04:00');await pause();
   check(button.disabled,'Pending verification can submit');
   win.waiting.pop()();await pause(0);check(output('total').includes('250.00'),'Latest response not applied');
   for(const release of win.waiting.splice(0))release();await pause(0);
   check(output('total').includes('250.00')&&!button.disabled,'Stale response overwrote current selection');win.hold=false;
   win.offline=true;change('end_time','06:00');await pause();
   check(button.disabled&&status().includes('could not be checked')&&output('total')==='—','Unknown availability presented as confirmed');
   check(form.querySelector('[data-booking-retry]').getBoundingClientRect().height>=44,'Retry button hidden after failure');
   win.offline=false;form.querySelector('[data-booking-retry]').click();await pause();check(!button.disabled,'Retry did not restore availability');
   win.calendarOffline=true;change('reservation_date','2026-10-22');await pause();
   check(doc.querySelector('.day-schedule-card').hidden&&doc.querySelector('[data-calendar-feedback]').textContent.includes('could not'),'Stale daily schedule shown after failure');
   check(doc.querySelector('.day-schedule-card').getBoundingClientRect().height===0,'Failed calendar refresh still displays stale rows');
   win.calendarOffline=false;change('reservation_date','2026-10-23');await pause();check(!doc.querySelector('.day-schedule-card').hidden,'Calendar retry failed');
   const before=field('reservation_date').value;doc.querySelector('[aria-label="Next month"]').click();await pause();
   check(field('reservation_date').value===before&&field('purpose').value==='Keep my event details','Month browse changed form');
   const slot=doc.querySelector('.schedule-slot-available,.equipment-slot-time[href]');slot.click();await pause();
   check(field('start_time').value==='00:00'&&field('end_time').value==='01:00'&&field('end_next_day').value==='0','Slot did not update schedule');
   check(win.location.href===originalUrl,'Slot navigated the page');
   const rect=el=>el.getBoundingClientRect(),calendar=doc.querySelector('.calendar-card'),schedule=doc.querySelector('.day-schedule-card'),card=doc.querySelector('#reservation');
   check(rect(schedule).top>=rect(calendar).bottom,'Hourly details not below calendar');
   if(width>900)check(rect(card).left>=rect(calendar).right&&Math.abs(rect(card).top-rect(calendar).top)<2,'Desktop form not aligned beside calendar');
   else check(rect(card).top>=rect(schedule).bottom,'Mobile form not stacked');
   check(doc.documentElement.scrollWidth<=width+1,'Horizontal page overflow');
   for(const control of form.querySelectorAll('input:not([type=hidden]),select,textarea,button:not([hidden])'))check(rect(control).height>=44,'Control too small');
   for(const label of form.querySelectorAll('label'))check(label.control,'Disconnected label');
   const dot=doc.querySelector('.calendar-day-status-dot');if(width<=760)check(rect(dot).width>0,'Mobile date indicator missing');
   field('purpose').value='';check(!form.checkValidity(),'Required details not validated');field('purpose').value='Keep my event details';
   let submissions=0;form.addEventListener('submit',event=>{submissions++;event.preventDefault();});
   form.requestSubmit();form.requestSubmit();
   check(submissions===1&&button.disabled,'Repeated submit not prevented');
   check(errors.length===0,'JavaScript errors: '+errors.join(','));
   resolve({name,width,passed:true});
  }catch(error){resolve({name,width,passed:false,error:error.message});}finally{frame.remove();}
 };
 frame.srcdoc=html;document.body.appendChild(frame);
}));
Promise.all(tasks).then(results=>document.getElementById('result').textContent=JSON.stringify(results));
</script></body></html>`);
runBrowser(page, path.join(directory, 'chrome-' + process.pid)).then(results => {
    fs.writeFileSync(path.join(directory, 'results.json'), JSON.stringify(results, null, 2));
    const failures = results.filter(result => !result.passed);
    if (failures.length) console.error(failures);
    assert.equal(failures.length, 0);
    console.log(`Unified resident booking browser checks passed: ${results.length} resource/viewport scenarios.`);
}).catch(error => { console.error(error); process.exitCode = 1; });
