// Render fixtures with HOURLY_RENDER_DIR=storage/app/hourly-check and HourlyBookingTest.
const fs = require('node:fs'), path = require('node:path'), assert = require('node:assert/strict');
const runBrowser = require('./run-browser.cjs');
const directory = path.resolve('storage/app/hourly-check');
const css = ['app', 'resident-booking', 'resident-reservations', 'management-tables', 'hourly-booking'].map(name => fs.readFileSync(`public/css/${name}.css`, 'utf8')).join('\n');
const validation = fs.readFileSync('public/js/reservation-time-validation.js', 'utf8');
const clock = Date.parse('2026-10-20T10:15:00+08:00');
const sources = ['facility', 'equipment', 'pending', 'accepted', 'official'].map(name => {
    const source = fs.readFileSync(path.join(directory, name + '.html'), 'utf8');
    const html = source.replace(/<link\b[^>]*>/g, '').replace(/<script\b[^>]*>[\s\S]*?<\/script>/g, '')
        .replace('</head>', '<style>' + css + '</style></head>')
        .replace('</body>', `<script>
window.clock=${clock};window.requests=[];window.waiting=[];window.delayResponses=false;
const nativeTimeout=window.setTimeout;window.setTimeout=(callback,ms,...args)=>nativeTimeout(callback,ms===180?0:ms,...args);
const NativeDate=Date;window.Date=class extends NativeDate {static now(){return window.clock;}};
window.fetch=async url=>{
 const params=new URL(url).searchParams;requests.push(params.toString());
 const start=params.get('start_time'),end=params.get('end_time'),next=params.get('end_next_day')==='1',quantity=Number(params.get('quantity')||1);
 const times=Array.from({length:24},(_,h)=>String(h).padStart(2,'0')+':00');
 const minutes=t=>Number(t?.slice(0,2)||0)*60+Number(t?.slice(3)||0);
 const hours=(minutes(end)-minutes(start)+(next?1440:0))/60;
 const data={start_options:times.filter(t=>t!=='09:00'),end_options:times.filter(t=>next?t<start:t>start),available:quantity<=20,message:quantity>20?'Only 20 units are available for the selected date and time.':null,available_quantity:20,total_quantity:40,hourly_rate:'50.00',estimated_total:start&&end?(hours*50).toFixed(2):null,end_date:next?'2026-10-23':'2026-10-22'};
 if(window.delayResponses)await new Promise(resolve=>window.waiting.push(resolve));
 return {ok:true,json:async()=>data};
};</script><script data-server-now="${clock}">${validation}</script></body>`);
    return {name, html};
});
const encode = value => JSON.stringify(value).replace(/</g, '\\u003c');
const page = path.join(directory, 'browser.html');
fs.writeFileSync(page, `<!doctype html><html><body><pre id="result">RUNNING</pre><script>
const sources=${encode(sources)};
(async()=>{const results=[];for(const {name,html} of sources)for(const width of [320,375,412,1280])results.push(await new Promise(resolve=>{
 const frame=document.createElement('iframe');frame.style.width=width+'px';frame.style.height='850px';
 frame.onload=async()=>{try{
  const doc=frame.contentDocument,win=frame.contentWindow,check=(ok,message)=>{if(!ok)throw Error(message);},tick=()=>new Promise(resolve=>setTimeout(resolve,0));
  const errors=[];win.addEventListener('error',event=>errors.push(event.message));
  const form=doc.querySelector('form[data-future-reservation]'),field=n=>form.elements.namedItem(n),date=field('reservation_date')||field('date'),start=field('start_time'),end=field('end_time'),next=field('end_next_day'),action=field('action');
  if(action){action.value='reschedule';form.querySelector('[data-edit-step="reschedule"]').hidden=false;form.querySelector('[data-edit-step="reschedule"]').disabled=false;}
  const modal=form.closest('dialog');if(modal)modal.showModal();
  check(start.tagName==='SELECT'&&end.tagName==='SELECT','Hourly dropdowns missing');
  check([...start.options].filter(o=>o.value&&!o.hasAttribute('data-legacy-time')).every(o=>o.value.endsWith(':00')),'Minute option offered');
  check(start.getBoundingClientRect().height>=44,'Time control is too small for touch');
  const fill=async(day,from,to,overnight='0')=>{date.value=day;start.value=from;end.value=to;next.value=overnight;start.dispatchEvent(new win.Event('change',{bubbles:true}));await tick();await tick();};
  const submit=form.querySelector('button[type=submit]'),summary=form.querySelector('.booking-summary')||form.querySelector('[data-hourly-summary]');
  const notice=()=>doc.getElementById(start.getAttribute('aria-describedby').split(' ').at(-1));
  if(name==='pending') {check(start.value==='13:15','Legacy time was rounded on opening');start.dispatchEvent(new win.Event('change',{bubbles:true}));await tick();check(!start.validationMessage,'Unchanged legacy schedule rejected');}
  await fill('2026-10-22','13:00','17:00');check(!submit.disabled,'Valid four-hour booking blocked');check(form.querySelector('[data-hourly-summary]').textContent.includes('Duration: 4 hours'),'Wrong duration');
  if(form.dataset.hourlyAvailability){check(summary.textContent.includes('200.00'),'Missing server estimate');check(start.querySelector('option[value="09:00"]').disabled,'Unavailable start remains selectable');check(win.requests.some(q=>q.includes('start_time=13%3A00')&&q.includes('end_time=17%3A00')),'Full interval not requested');}
  check(end.querySelector('option[value="12:00"]').disabled,'Earlier same-day end enabled');
  await fill('2026-10-22','22:00','02:00');check(submit.disabled&&notice().textContent.includes('Next day'),'Overnight inferred silently');
  await fill('2026-10-22','22:00','02:00','1');check(!submit.disabled&&summary.textContent.includes('4 hours'),'Explicit overnight rejected');
  await fill('2026-10-22','13:00','13:00');check(submit.disabled,'Zero duration accepted');
  await fill('2026-10-20','10:00','12:00');check(submit.disabled&&notice().textContent.includes('future'),'Past start accepted');
  await fill('2026-10-22','13:00','17:00');
  const injected=doc.createElement('option');injected.value='13:15';injected.textContent='13:15';start.append(injected);start.value='13:15';start.dispatchEvent(new win.Event('change',{bubbles:true}));check(submit.disabled&&notice().textContent.includes('whole-hour'),'Tampered minute accepted');injected.remove();
  await fill('2026-10-22','13:00','17:00');
  if(name==='equipment') {field('quantity').value='25';field('quantity').dispatchEvent(new win.Event('change',{bubbles:true}));await tick();await tick();check(submit.disabled&&notice().textContent.includes('Only 20'),'Quantity shortage ignored');field('quantity').value='20';field('quantity').dispatchEvent(new win.Event('change',{bubbles:true}));await tick();await tick();check(!submit.disabled&&summary.textContent.includes('200.00'),'Quantity recovery or per-reservation price failed');}
  if(form.dataset.hourlyAvailability){
   win.delayResponses=true;await fill('2026-10-22','13:00','16:00');await fill('2026-10-22','13:00','18:00');
   const latest=win.waiting.pop();latest();await tick();await tick();const oldest=win.waiting.shift();oldest();await tick();await tick();
   check(summary.textContent.includes('250.00')&&!summary.textContent.includes('150.00'),'Stale response replaced the selected estimate');win.delayResponses=false;
  }
  await fill('2026-10-20','11:00','12:00');win.clock=Date.parse('2026-10-20T11:01:00+08:00');
  const stale=new win.Event('submit',{bubbles:true,cancelable:true});form.dispatchEvent(stale);check(stale.defaultPrevented&&submit.disabled,'Expired selection submitted');
  if(action){action.value='cancel';action.dispatchEvent(new win.Event('change',{bubbles:true}));check(!submit.disabled,'Cancellation blocked by hourly validation');}
  check(doc.documentElement.scrollWidth<=width+1,'Horizontal page overflow');
  check(start.getBoundingClientRect().right<=width+1,'Time select overflows viewport');
  if(name==='facility'||name==='equipment')check(doc.querySelectorAll('.calendar-day').length>0,'Calendar date cells missing');
  check(notice().getAttribute('role')==='alert','Validation error not announced');check(errors.length===0,'JavaScript error: '+errors.join(','));
  resolve({name,width,passed:true});
 }catch(error){resolve({name,width,passed:false,error:error.message});}finally{frame.remove();}};
 frame.srcdoc=html;document.body.appendChild(frame);
}));document.getElementById('result').textContent=JSON.stringify(results);})();
</script></body></html>`);
runBrowser(page, path.join(directory, 'chrome-' + process.pid)).then(results => {
    fs.writeFileSync(path.join(directory, 'results.json'), JSON.stringify(results, null, 2));
    const failures = results.filter(result => !result.passed);
    if (failures.length) console.error(JSON.stringify(failures, null, 2));
    assert.equal(failures.length, 0);
    console.log('Hourly booking browser checks passed: ' + results.length + ' rendered form/viewport scenarios.');
}).catch(error => { console.error(error); process.exitCode = 1; });
