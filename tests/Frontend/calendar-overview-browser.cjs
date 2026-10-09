// Render with CALENDAR_RENDER_DIR=storage/app/calendar-default-check during AllResourcesCalendarTest.
const fs = require('node:fs'), path = require('node:path'), assert = require('node:assert/strict');
const runBrowser = require('./run-browser.cjs');
const directory = path.resolve('storage/app/calendar-default-check');
const css = fs.readFileSync('public/css/app.css', 'utf8');
const script = fs.readFileSync('public/js/page-filters.js', 'utf8');
const sources = ['all', 'equipment', 'taft', 'resource', 'empty'].map(name => {
    const html = fs.readFileSync(path.join(directory, name + '.html'), 'utf8')
        .replace(/<link\b[^>]*>/g, '').replace(/<script\b[^>]*>[\s\S]*?<\/script>/g, '')
        .replace('</head>', '<style>' + css + '</style></head>').replace('</body>', '<script>' + script + '</script></body>');
    return {name, html};
});
const encode = value => JSON.stringify(value).replace(/</g, '\\u003c');
const page = path.join(directory, 'browser.html');
fs.writeFileSync(page, `<!doctype html><html><body><pre id="result">RUNNING</pre><script>
const sources = ${encode(sources)};
(async () => {
 const results = [];
 for (const {name,html} of sources) for (const width of [1440,768,390,320]) results.push(await new Promise(resolve => {
  const frame = document.createElement('iframe'); frame.style.width = width+'px'; frame.style.height = '900px';
  frame.onload = () => {try {
   const doc=frame.contentDocument,win=frame.contentWindow,check=(ok,message)=>{if(!ok)throw Error(message);};
   const form=doc.querySelector('[aria-label="Calendar filters"]'),{facility,barangay,type,date,month}=form.elements;
   const calls=[]; form.submit=()=>calls.push(Object.fromEntries(new win.FormData(form)));
   const change=(field,value)=>{field.value=value;field.dispatchEvent(new win.Event('change'));};
   check(doc.querySelectorAll('.calendar-grid').length===1,'Calendar missing or duplicated');
   check(doc.querySelectorAll('a.calendar-day').length===31,'Current month dates missing');
   check(date.value==='2026-10-15'&&month.value==='2026-10','Selected date/month changed');
   check(doc.documentElement.scrollWidth<=width+1,'Page horizontal overflow');
   check(!facility.disabled,'All Resources must remain selectable even with no matching resources');
   check(!doc.querySelector('main').textContent.includes('Private Booker')&&!doc.querySelector('main').textContent.includes('Private booking purpose'),'Private reservation details exposed');
   check(doc.querySelector('a.calendar-day.selected').getAttribute('href').includes('date=2026-10-15'),'Selected date link incorrect');
   if(name==='resource'){
    check(facility.value==='community-court'&&type.value==='all','Explicit resource/type selection lost');
    check(doc.querySelector('.reservation-form')&&doc.querySelector('.schedule-slot-available'),'Specific-resource booking UI missing');
    change(type,'facility');check(calls.at(-1).facility==='community-court','Matching type reset valid resource');
    change(type,'all');check(calls.at(-1).facility==='community-court','All Types reset valid resource');
    change(barangay,'Washington');check(calls.at(-1).facility==='community-court','Unchanged barangay reset valid resource');
    change(type,'equipment');check(calls.at(-1).facility==='all','Incompatible type kept stale resource');
   }else{
    check(facility.value==='all','All Resources not selected');
    check(!doc.querySelector('.reservation-form')&&!doc.querySelector('.schedule-slot-available'),'Combined view invents booking slots');
    check(!doc.querySelector('.calendar-day-full,.calendar-day-partial,.calendar-day-available'),'Combined view invents availability');
    check(!doc.querySelector('a.schedule-slot'),'Combined events must not select booking times');
    check(doc.querySelector('.day-schedule-card').textContent.includes('Select a resource first'),'Missing resource selection prompt');
    if(name==='all'){
     check(barangay.value==='Washington'&&type.value==='all','Default filters incorrect');
     check(facility.options.length===3,'All Types did not load both resources');
     check(doc.querySelectorAll('[data-event-type="reservation"]').length===2,'Overlapping distinct resources missing');
     for(const label of ['Community Court','Sound Equipment'])check(doc.querySelector('.schedule-list').textContent.includes(label),'Resource name missing from combined event');
     change(facility,'community-court');check(calls.at(-1).facility==='community-court','Resource selection did not submit');
     change(barangay,'Taft');check(calls.at(-1).facility==='all'&&calls.at(-1).type==='all','Barangay dependencies incorrect');
    }else if(name==='equipment'){
     check(type.value==='equipment'&&facility.options.length===2,'Type did not filter resource options');
     check(doc.querySelector('.schedule-list').textContent.includes('Sound Equipment')&&!doc.querySelector('.schedule-list').textContent.includes('Community Court'),'Type did not filter events');
    }else if(name==='taft'){
     check(barangay.value==='Taft'&&doc.querySelector('.schedule-list').textContent.includes('Taft Hall'),'Barangay calendar not updated');
    }else{
     check(facility.options.length===1&&doc.querySelectorAll('[data-event-type]').length===0,'Empty filter includes resources/events');
     check(doc.querySelector('.day-schedule-card').textContent.includes('No bookings or Official Use events for this date.'),'Useful empty day message missing');
    }
   }
   const previousCalls=calls.length;
   change(date,'2026-11-03');check(calls.length===previousCalls+1&&calls.at(-1).month==='2026-11'&&calls.at(-1).date==='2026-11-03','Selected date dependency broken');
   check(calls.every(call=>call.date==='2026-10-15'||call.date==='2026-11-03'),'Filter changes dropped the selected date');
   resolve({name,width,passed:true});
  }catch(error){resolve({name,width,passed:false,error:error.message});}};
  frame.srcdoc=html;document.body.appendChild(frame);
 }));document.getElementById('result').textContent=JSON.stringify(results);
})();
</script></body></html>`);
(async()=>{
    const results=await runBrowser(page,path.join(directory,'chrome-'+process.pid));
    const failed=results.filter(row=>!row.passed);
    if(failed.length)console.error(JSON.stringify(failed));
    assert.equal(failed.length,0,'Calendar overview browser failures');
    console.log('Calendar overview/filter browser checks passed: '+results.length);
})().catch(error=>{console.error(error);process.exitCode=1;});
