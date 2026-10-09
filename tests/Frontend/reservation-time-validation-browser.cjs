// Render with FUTURE_START_RENDER_DIR=storage/app/future-start-check during FutureReservationStartTest.
const fs = require('node:fs'), path = require('node:path'), assert = require('node:assert/strict');
const runBrowser = require('./run-browser.cjs');
const directory = path.resolve('storage/app/future-start-check');
const css = fs.readFileSync('public/css/app.css', 'utf8');
const modals = fs.readFileSync('public/js/modals.js', 'utf8');
const validation = fs.readFileSync('public/js/reservation-time-validation.js', 'utf8');
const clock = Date.parse('2026-10-09T22:55:00+08:00');
const sources = ['facility', 'facility-modal', 'calendar', 'pending', 'accepted'].map(name => {
    const source = fs.readFileSync(path.join(directory, name + '.html'), 'utf8');
    const html = source.replace(/<link\b[^>]*>/g, '').replace(/<script\b[^>]*>[\s\S]*?<\/script>/g, '')
        .replace('</head>', '<style>' + css + '</style></head>')
        .replace('</body>', `<script>
window.clock=${clock}; window.validationRequests=0; window.submissions=0;
const NativeDate=Date; window.Date=class extends NativeDate {static now(){return window.clock;}};
window.fetch=async()=>{validationRequests++;return {ok:false,json:async()=>({errors:{start_time:['Server validation']}})};};
${modals}</script><script data-server-now="${clock}">${validation}</script></body>`);
    return {name, html};
});
const encode = value => JSON.stringify(value).replace(/</g, '\\u003c');
const page = path.join(directory, 'browser.html');
fs.writeFileSync(page, `<!doctype html><html><body><pre id="result">RUNNING</pre><script>
const sources=${encode(sources)};
(async()=>{const results=[];for(const {name,html} of sources)for(const width of [320,412,1280])results.push(await new Promise(resolve=>{
 const frame=document.createElement('iframe');frame.style.width=width+'px';frame.style.height='700px';
 frame.onload=async()=>{try{
  const doc=frame.contentDocument,win=frame.contentWindow,check=(ok,message)=>{if(!ok)throw Error(message);},tick=()=>new Promise(resolve=>setTimeout(resolve,0));
  const errors=[];win.addEventListener('error',event=>errors.push(event.message));
  const form=doc.querySelector('form[data-future-reservation]'),date=form.elements.namedItem('reservation_date'),start=form.elements.namedItem('start_time'),end=form.elements.namedItem('end_time'),action=form.elements.namedItem('action');
  if(action){action.value='reschedule';form.querySelector('[data-edit-step="reschedule"]').hidden=false;form.querySelector('[data-edit-step="reschedule"]').disabled=false;}
  const modal=form.closest('dialog');if(modal)win.EReserveModal.open(modal);
  const fill=(day,from,to)=>{date.value=day;start.value=from;end.value=to;start.dispatchEvent(new win.Event('input',{bubbles:true}));};
  const submit=form.querySelector('button[type=submit]');
  const notice=()=>doc.getElementById(start.getAttribute('aria-describedby').split(' ').at(-1));
  check(start.step===''||start.step==='60','Minute-level picker changed');
  for(const time of ['20:00','21:00','22:30']){
   fill('2026-10-09',time,'00:30');check(submit.disabled&&notice().textContent.includes('must be in the future'),'Past time not blocked: '+time);
   form.dispatchEvent(new win.Event('submit',{bubbles:true,cancelable:true}));check(win.validationRequests===0,'Invalid schedule reached modal fetch');
  }
  fill('2026-10-08','23:15','00:30');check(notice().textContent.includes('past')&&submit.disabled,'Past date allowed');
  fill('2026-10-09','23:00','00:30');check(!submit.disabled&&!notice().textContent,'Future start blocked');
  if(date.type==='date')check(date.min==='2026-10-09','Wrong Manila date minimum');
  check(start.min==='22:56','Today minimum lost minute precision');
  // Move the clock without an input change; capture validation must beat the AJAX handlers.
  win.clock=Date.parse('2026-10-09T23:10:00+08:00');
  const stale=new win.Event('submit',{bubbles:true,cancelable:true});form.dispatchEvent(stale);
  check(stale.defaultPrevented&&submit.disabled&&notice().textContent.includes('future'),'Stale form submitted');check(win.validationRequests===0,'Stale form made a request');
  fill('2026-10-09','23:10','00:30');check(submit.disabled,'Equal-to-now start allowed');
  fill('2026-10-09','23:17','00:09');check(!submit.disabled&&notice().hidden,'Minute-level overnight time rejected');
  fill('2026-10-09','23:17','23:17');check(submit.disabled&&notice().textContent.includes('different'),'Equal end time allowed');
  fill('2026-10-10','00:00','01:00');check(!submit.disabled&&start.min==='','Future date midnight blocked');
  win.clock=Date.parse('2026-10-09T23:59:00+08:00');fill('2026-10-09','23:59','00:30');
  check(submit.disabled,'Today accepted without any future minute');if(date.type==='date')check(date.min==='2026-10-10','Date minimum did not advance');
  win.clock=Date.parse('2026-10-10T00:00:00+08:00');fill('2026-10-10','00:01','00:30');check(!submit.disabled&&start.min==='00:01','Midnight rollover failed');
  if(action){fill('2026-10-09','20:00','21:00');action.value='cancel';action.dispatchEvent(new win.Event('change',{bubbles:true}));check(!submit.disabled&&start.validationMessage==='','Cancellation blocked by schedule');}
  // The calendar's independent date filter can still browse history.
  if(name==='calendar'){const filter=doc.getElementById('date');check(filter&&!filter.min,'Historical calendar date filter constrained');}
  check(notice().getAttribute('role')==='alert','Error lacks an accessible announcement');
  check(notice().scrollWidth<=notice().clientWidth+1,'Validation message clips');check(errors.length===0,'JavaScript error');
  // Existing AJAX refreshes replace main/forms and emit these lifecycle events.
  const replacement=form.cloneNode(true);
  replacement.querySelectorAll('[id^="future-reservation-error-"]').forEach(node=>node.remove());
  replacement.querySelectorAll('[aria-describedby]').forEach(node=>node.setAttribute('aria-describedby',node.getAttribute('aria-describedby').split(' ').filter(id=>!id.startsWith('future-reservation-error-')).join(' ')));
  replacement.elements.namedItem('reservation_date').value='2026-10-10';replacement.elements.namedItem('start_time').value='00:00';replacement.elements.namedItem('end_time').value='01:00';
  const nextAction=replacement.elements.namedItem('action');if(nextAction)nextAction.value='reschedule';
  form.replaceWith(replacement);doc.dispatchEvent(new win.Event(name==='accepted'?'reservations:updated':'modals:updated'));
  check(replacement.querySelector('button[type=submit]').disabled&&replacement.querySelector('.form-error').textContent.includes('future'),'Refreshed form missed future validation');
  replacement.elements.namedItem('start_time').value='00:01';replacement.elements.namedItem('start_time').dispatchEvent(new win.Event('change',{bubbles:true}));
  check(!replacement.querySelector('button[type=submit]').disabled,'Refreshed form did not recover');
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
    console.log('Reservation future-start browser checks passed: ' + results.length + ' rendered form/viewport scenarios.');
}).catch(error => { console.error(error); process.exitCode = 1; });
