const fs = require('node:fs'), path = require('node:path'), assert = require('node:assert/strict');
const {pathToFileURL} = require('node:url');
const runBrowser = require('./run-browser.cjs');
const directory = path.resolve('storage/app/facility-layout-check');
const css = fs.readFileSync('public/css/app.css', 'utf8');
const scripts = ['modals', 'facility-gallery'].map(name => fs.readFileSync('public/js/' + name + '.js', 'utf8')).join('\n');
const sources = ['resident', 'resident-restricted'].map(role => {
    const html = fs.readFileSync(path.join(directory, role + '.html'), 'utf8');
    const original = html.match(/<main\b[^>]*>[\s\S]*?<\/main>/)[0];
    const filters = [...original.matchAll(/<script>([\s\S]*?)<\/script>/g)].map(match => match[1]).join('\n');
    const main = original.replace(/<script\b[^>]*>[\s\S]*?<\/script>/g, '')
        .replace(/src="\/storage\/([^"\s]+)"/g, (_, file) => 'src="' + pathToFileURL(path.join(directory, file.endsWith('layout-photo.png') ? 'photo.svg' : 'uploads/' + file)).href + '"');
    return {role, main, source: '<!doctype html><html><head><meta name="viewport" content="width=device-width, initial-scale=1"><style>' + css + '</style></head><body class="user-page">' + main + '<script>' + scripts + '\n' + filters + '</script></body></html>'};
});
const encode = value => JSON.stringify(value).replace(/</g, '\\u003c');
const page = path.join(directory, 'resident-actions-browser.html');
fs.writeFileSync(page, `<!doctype html><html><body><pre id="result">RUNNING</pre><script>
const sources=${encode(sources)};
(async()=>{const results=[];for(const {role,source,main} of sources)for(const [width,height] of [[1280,900],[768,700],[640,600],[390,568],[320,568],[240,400]])results.push(await new Promise(resolve=>{
 const frame=document.createElement('iframe');frame.style.width=width+'px';frame.style.height=height+'px';
 frame.onload=async()=>{try{
  const doc=frame.contentDocument,win=frame.contentWindow,check=(ok,message)=>{if(!ok)throw Error(message);},rect=element=>element.getBoundingClientRect(),tick=()=>new Promise(resolve=>setTimeout(resolve,0));
  const settle=async()=>{for(let i=0;i<4;i++)await tick();};await settle();
  const before=win.location.href;let posts=0,gets=0,complete,posted,target;
  win.fetch=async(url,options={})=>{
   if(options.method==='POST'){posts++;posted=options.body;target=String(url);return new Promise(resolve=>complete=resolve);}
   gets++;return {ok:true,redirected:false,text:async()=>main};
  };
  const bounds=modal=>{
   const box=rect(modal),header=rect(modal.querySelector('.facility-modal-header')),footer=rect(modal.querySelector('.facility-modal-actions'));
   check(box.left>=0&&box.right<=width+1&&box.top>=0&&box.bottom<=height+1,'Modal outside viewport');
   check(modal.scrollWidth<=modal.clientWidth+1,'Modal horizontal overflow');
   check(header.top>=box.top&&header.bottom<=box.bottom,'Header inaccessible');
   check(footer.top>=box.top&&footer.bottom<=box.bottom+1,'Footer inaccessible');
   check(doc.documentElement.classList.contains('modal-open'),'Background scroll not locked');
  };
  const cards=[...doc.querySelectorAll('[data-facility-card]')];check(cards.length===6,'Missing facility cards');
  const ids=[...doc.querySelectorAll('[id]')].map(el=>el.id);check(new Set(ids).size===ids.length,'Duplicate IDs');
  for(const card of cards){
   const buttons=[...card.querySelectorAll('.resident-facility-card-footer button')],view=buttons[0],reserve=buttons[1],details=doc.getElementById(view.dataset.modalOpen);
   check(buttons.length===2&&view.textContent==='View Details'&&reserve.textContent==='Reserve','Both card actions must remain');
   const styles=buttons.map(button=>win.getComputedStyle(button));
   for(const property of ['fontSize','fontWeight','borderRadius','paddingTop','paddingBottom','paddingLeft','paddingRight'])check(styles[0][property]===styles[1][property],'Different card button '+property);
   check(Math.abs(rect(view).width-rect(reserve).width)<1&&Math.abs(rect(view).height-rect(reserve).height)<1,'Card actions not balanced');
   check(rect(view).height>=44,'Card touch target too small');check(styles[0].backgroundColor!==styles[1].backgroundColor,'Primary/secondary actions look identical');
   check(card.scrollWidth<=card.clientWidth+1,'Card overflow');
   view.focus();view.click();bounds(details);
   check(details.open&&!doc.querySelector('.facility-reservation-modal[open]'),'View Details opens booking');
   check(!details.querySelector('form,input,select,textarea,a'),'Details contains editable fields');
   check(details.querySelector('h2').textContent===card.querySelector('h3').textContent,'Wrong facility details');
   for(const label of ['Open to','Hourly Rate','Description','Category','Capacity'])check([...details.querySelectorAll('dt')].some(el=>el.textContent.trim()===label),'Missing data: '+label);
   const body=details.querySelector('.facility-view-body');check(body.scrollWidth<=body.clientWidth+1,'Details body overflow');
   body.scrollTop=body.scrollHeight;bounds(details);
   if(view.dataset.modalOpen.endsWith('-0')){
    check(details.querySelector('.facility-view-description dd').textContent==='Full description for community events. '.repeat(13),'Description truncated');
    const gallery=details.querySelector('[data-facility-carousel]'),slides=[...gallery.querySelectorAll('[data-carousel-slide]')];
    check(slides.length===2,'Missing gallery');gallery.querySelector('[data-carousel-next]').click();check(slides[0].hidden&&!slides[1].hidden,'Carousel cannot switch');
    for(const slide of slides){const image=slide.querySelector('img');check(win.getComputedStyle(image).objectFit==='contain','Image stretched/cropped');await image.decode();check(image.naturalWidth>0,'Image failed to load');}
   }
   const book=details.querySelector('.facility-modal-actions .facility-modal-primary');check(!!book,'Details Reserve missing');
   check(book.disabled===reserve.disabled,'Availability differs across entry points');
   if(reserve.disabled){
    check(!doc.getElementById(view.dataset.modalOpen.replace('view-','reserve-')),'Blocked facility has booking form');book.click();check(details.open,'Disabled Reserve transitioned');
    details.querySelector('.facility-modal-actions [data-modal-close]').click();await settle();
   }else{
    const booking=doc.getElementById(reserve.dataset.modalOpen);
    book.click();check(!details.open&&booking.open&&doc.querySelectorAll('dialog[open]').length===1,'Details transition stacks dialogs');bounds(booking);
    check(booking.querySelector('strong').textContent===card.querySelector('h3').textContent,'Selected facility lost');
    check(!booking.querySelector('select'),'Facility selection required again');
    const fields=booking.querySelector('.reservation-form-fields');check(fields.scrollWidth<=fields.clientWidth+1,'Form body overflow');fields.scrollTop=fields.scrollHeight;bounds(booking);
    booking.querySelector('[data-modal-close]').click();await settle();check(!booking.open&&doc.activeElement===view,'Transition focus not restored to Details trigger');
    reserve.focus();reserve.click();check(booking.open&&!details.open,'Direct Reserve opens Details');bounds(booking);
    const form=booking.querySelector('form');check(form.action.endsWith('/facilities/'+reserve.dataset.modalOpen.replace('reserve-facility-','')+'/reservations'),'Wrong booking endpoint');
    for(const label of form.querySelectorAll('label'))check(label.control&&form.contains(label.control),'Broken form label');
    form.elements.purpose.value='Unsaved';booking.querySelector('.facility-modal-actions [data-modal-close]').click();await settle();
    check(!booking.open&&doc.activeElement===reserve,'Direct Reserve close/focus failed');check(form.elements.purpose.value==='','Form not reset');
   }
   check(!doc.documentElement.classList.contains('modal-open'),'Scroll lock remains');
  }
  check(posts===0&&gets===0&&win.location.href===before,'Inspecting/cancelling makes requests or navigates');
  check(!doc.querySelector('[data-modal-open^="edit-facility-"], [data-modal-open^="delete-facility-"]'),'Resident sees management actions');
  const reserve=doc.querySelector('.resident-facility-card-footer [data-modal-open^="reserve-facility-"]');reserve.click();
  let booking=doc.getElementById(reserve.dataset.modalOpen),form=booking.querySelector('form');
  form.requestSubmit();check(posts===0,'Invalid form submitted');
  const fill=()=>{Object.entries({reservation_date:'2026-10-10',start_time:'13:00',end_time:'14:00',purpose:'Community event',attendees:'10'}).forEach(([name,value])=>form.elements[name].value=value);};fill();
  form.requestSubmit();form.dispatchEvent(new win.Event('submit',{bubbles:true,cancelable:true}));check(posts===1,'Duplicate submit request');
  check(booking.getAttribute('aria-busy')==='true'&&form.querySelector('button[type=submit]').disabled,'Submission not locked');
  const cancel=new win.Event('cancel',{cancelable:true});booking.dispatchEvent(cancel);check(cancel.defaultPrevented,'Busy Escape not blocked');
  complete({ok:false,json:async()=>({errors:{reservation:['This resource is unavailable for the selected date and time due to Official Use.']}})});await settle();
  check(booking.open&&form.querySelector('[data-action-error]').textContent.includes('Official Use'),'Official Use error not visible');check(gets===0,'Validation refreshed page');bounds(booking);
  form.requestSubmit();complete({ok:false,json:async()=>({errors:{attendees:['Capacity exceeded.']}})});await settle();
  check(form.elements.attendees.getAttribute('aria-invalid')==='true'&&doc.activeElement===form.elements.attendees,'Field error/focus missing');
  form.requestSubmit();complete({ok:true,json:async()=>({success:true,message:'Reservation request submitted successfully.'})});await settle();
  check(posts===3&&gets===1,'Wrong submission/refresh count');check(target===form.action,'Booking submitted to a second endpoint');
  check(posted.get('purpose')==='Community event'&&posted.get('attendees')==='10'&&posted.has('_token'),'Reservation payload/CSRF missing');
  check(!doc.querySelector('dialog[open]')&&!doc.documentElement.classList.contains('modal-open'),'Saved modal remains open');
  check(doc.querySelector('.reservation-table-toast').textContent==='Reservation request submitted successfully.','Existing success toast missing');
  const search=doc.querySelector('#facility-search');search.value='chairs';search.dispatchEvent(new win.Event('input',{bubbles:true}));
  check([...doc.querySelectorAll('[data-facility-card]')].filter(card=>!card.hidden).length===1,'Facility filters broken after refresh');
  search.value='';search.dispatchEvent(new win.Event('input',{bubbles:true}));
  const view=doc.querySelector('[data-modal-open="view-facility-layout-resource-0"]');view.click();const details=doc.getElementById(view.dataset.modalOpen);
  check(details.open,'Details unusable after refresh');details.querySelector('.facility-modal-actions .facility-modal-primary').click();
  check(!details.open&&doc.getElementById('reserve-facility-layout-resource-0').open,'Transition unusable after refresh');
  booking=doc.getElementById('reserve-facility-layout-resource-0');form=booking.querySelector('form');fill();
  form.requestSubmit();check(target===form.action&&target.endsWith('/facilities/layout-resource-0/reservations'),'Details Reserve uses a different backend workflow');
  complete({ok:true,json:async()=>({success:true,message:'Reservation request submitted successfully.'})});await settle();
  check(posts===4&&gets===2&&!doc.querySelector('dialog[open]'),'Details Reserve submission did not complete');
  check(win.location.href===before,'Workflow navigated away');check(doc.documentElement.scrollWidth<=width+1,'Page overflow');
  resolve({role,width,height,passed:true});
 }catch(error){resolve({role,width,height,passed:false,error:error.message,stack:error.stack});}};
 frame.srcdoc=source;document.body.appendChild(frame);
}));document.getElementById('result').textContent=JSON.stringify(results);})();
</script></body></html>`);
for (const {role, source} of sources) fs.writeFileSync(path.join(directory, role + '-actions-preview.html'), source);
runBrowser(page, path.join(directory, 'chrome-resident-actions-' + process.pid)).then(results => {
    fs.writeFileSync(path.join(directory, 'resident-actions-results.json'), JSON.stringify(results, null, 2));
    const failures = results.filter(row => !row.passed);
    if (failures.length) console.error(JSON.stringify(failures, null, 2));
    assert.equal(results.length, 12, 'Missing resident scenarios');
    assert.equal(failures.length, 0, 'Resident Facility actions failed');
    console.log('Resident Facility actions browser scenarios passed: ' + results.length);
}).catch(error => {console.error(error); process.exitCode = 1;});
