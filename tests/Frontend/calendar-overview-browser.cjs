// Render with CALENDAR_RENDER_DIR=storage/app/calendar-default-check during both calendar feature suites.
const fs = require('node:fs'), path = require('node:path'), assert = require('node:assert/strict');
const runBrowser = require('./run-browser.cjs');
const directory = path.resolve(process.env.CALENDAR_RENDER_DIR || 'storage/app/calendar-default-check');
const css = ['app', 'resident-booking', 'all-schedules', 'management-tables'].map(name => fs.readFileSync('public/css/' + name + '.css', 'utf8')).join('\n');
const script = ['page-filters', 'all-schedules'].map(name => fs.readFileSync('public/js/' + name + '.js', 'utf8')).join('\n');
const roleFixtures = ['user', 'admin', 'super_admin'].flatMap(role => [0, 1, 3, 5, 20, 50].map(count => role + '-' + count));
const sources = ['all', 'equipment', 'taft', 'resource', 'empty', 'next-month', 'overnight', ...roleFixtures].map(name => {
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
   check(doc.querySelectorAll('a.calendar-day').length===(name==='next-month'?30:31),'Current month dates missing');
   check(date.type==='hidden'&&!form.querySelector('input[type="date"]'),'Redundant date filter remains');
   check(date.value==='2026-10-15'&&month.value===(name==='next-month'?'2026-11':'2026-10'),'Selected date/month changed');
   check(doc.title==='All Schedules - eReserve','Page title not updated');
   check(doc.querySelector('.day-schedule-card h3').textContent==='Schedules for October 15, 2026','Schedule title incorrect');
   check(doc.documentElement.scrollWidth<=width+1,'Page horizontal overflow');
   for(const day of doc.querySelectorAll('a.calendar-day'))check(day.scrollWidth<=day.clientWidth+1,'Calendar date text overflows its cell');
   check(!facility.disabled,'All Resources must remain selectable even with no matching resources');
   check(!doc.querySelector('main').textContent.includes('Private Booker')&&!doc.querySelector('main').textContent.includes('Private booking purpose'),'Private reservation details exposed');
   if(name!=='next-month')check(doc.querySelector('a.calendar-day.selected').getAttribute('href').includes('date=2026-10-15'),'Selected date link incorrect');
   for(const nav of doc.querySelectorAll('.calendar-nav-button'))check(new URL(nav.href).searchParams.get('date')==='2026-10-15','Month navigation drops selected date');
   const dayLink=doc.querySelector('a.calendar-day');
   check(new URL(dayLink.href).searchParams.get('date')===month.value+'-01','Clicking a day does not change date');
   check(!doc.querySelector('.reservation-form,a.schedule-slot,.schedule-slot-available,input[type="time"]'),'Calendar must be read-only');
   const panel=doc.querySelector('.browse-schedules'),list=panel.querySelector('[data-schedule-list]');
   for(const container of doc.querySelectorAll('main,.all-schedules-layout,.calendar-card,.calendar-grid,.browse-schedules')){
    check(win.getComputedStyle(container).overflowY==='visible'&&win.getComputedStyle(container).maxHeight==='none','Scrolling escaped the right-side event list');
   }
   check(win.getComputedStyle(doc.documentElement).overflowY!=='hidden'&&win.getComputedStyle(doc.body).overflowY!=='hidden','Normal page scrolling disabled');
   check(!panel.querySelector('a,button,form,input'),'Schedule panel contains actions');
   const calendar=doc.querySelector('.calendar-card'),calendarBox=calendar.getBoundingClientRect(),panelBox=panel.getBoundingClientRect();
   check(width>900?panelBox.left>=calendarBox.right:panelBox.top>=calendarBox.bottom,'Calendar/panel layout incorrect');
   if(list){
    check(win.getComputedStyle(list).overflowY==='auto','Schedule list must scroll independently');
    check(win.getComputedStyle(list).maxHeight===(width>900?'500px':'400px'),'Responsive maximum list height incorrect');
    check(win.getComputedStyle(list).scrollbarGutter==='auto','Unnecessary scrollbar space is reserved');
    check(list.scrollWidth<=list.clientWidth+1,'Schedule list horizontal overflow');
    check(!list.contains(panel.querySelector('h3'))&&!list.contains(panel.querySelector('#schedule-summary')),'Heading/summary is inside scrolling list');
    check(list.tabIndex===0&&list.getAttribute('role')==='region','Scroll region not accessible to keyboard');
    list.focus({preventScroll:true});check(doc.activeElement===list,'Scroll list cannot receive keyboard focus');
    const headingTop=panel.querySelector('h3').getBoundingClientRect().top;
    list.scrollTop=list.scrollHeight;
    check(Math.abs(panel.querySelector('h3').getBoundingClientRect().top-headingTop)<1,'Heading moved with list');
    check(Math.abs(calendar.getBoundingClientRect().top-calendarBox.top)<1,'Calendar moved with list');
    const last=list.querySelector('.schedule-resource-group:last-child .schedule-slot:last-child');
    check(last.getBoundingClientRect().bottom<=list.getBoundingClientRect().bottom+1,'Final event cannot be reached');
    const unrelated=doc.createElement('div');unrelated.setAttribute('data-schedule-list','');unrelated.style.cssText='height:20px;overflow-y:auto;';
    unrelated.innerHTML='<div style="height:100px"></div>';doc.body.appendChild(unrelated);unrelated.scrollTop=20;
    win.dispatchEvent(new win.Event('pageshow'));check(list.scrollTop===0,'Restored list did not reset to the top');
    check(unrelated.scrollTop===20,'Scroll reset affected an unrelated container');unrelated.remove();
   }
   const roleCount=name.match(/^(user|admin|super_admin)-([0-9]+)$/);
   if(roleCount){
    const count=Number(roleCount[2]);
    check(panel.querySelectorAll('[data-event-type]').length===count,'Role schedule event count differs');
    if(count===0){
     check(!list&&!panel.querySelector('.schedule-resource-group'),'Empty date has scrolling or empty groups');
     check(panel.textContent.includes('No events scheduled for this date.'),'Empty state missing');
    }else{
     check(doc.querySelector('a.calendar-day.selected').textContent.includes(count+(count===1?' event':' events')),'Calendar count differs from panel');
     if(count===1){
      check(list.scrollHeight<=list.clientHeight,'One event should not need a scrollbar');
      check(list.clientHeight<250,'One event creates unnecessary empty space');
     }
     if(count>=20)check(list.scrollHeight>list.clientHeight,'Long event list has no overflow');
     for(const event of panel.querySelectorAll('[data-event-type="reservation"]')){
      check(event.querySelector('strong').textContent==='Reservation • Booked','Booked label changed');
      check(win.getComputedStyle(event).backgroundColor==='rgb(255, 251, 235)','Booked card must remain soft yellow');
     }
    }
   }else if(name==='resource'){
    check(facility.value==='community-court'&&type.value==='all','Explicit resource/type selection lost');
    check(doc.querySelector('[data-event-type="reservation"]')&&doc.querySelector('.schedule-resource-heading h4'),'Specific-resource schedule or name missing');
    change(type,'facility');check(calls.at(-1).facility==='community-court','Matching type reset valid resource');
    change(type,'all');check(calls.at(-1).facility==='community-court','All Types reset valid resource');
    change(barangay,'Washington');check(calls.at(-1).facility==='community-court','Unchanged barangay reset valid resource');
    change(type,'equipment');check(calls.at(-1).facility==='all','Incompatible type kept stale resource');
   }else{
    check(facility.value==='all','All Resources not selected');
    check(!doc.querySelector('.reservation-form')&&!doc.querySelector('.schedule-slot-available'),'Combined view invents booking slots');
    check(!doc.querySelector('.calendar-day-full,.calendar-day-partial,.calendar-day-available'),'Combined view invents availability');
    check(!doc.querySelector('a.schedule-slot'),'Combined events must not select booking times');
    check(!doc.querySelector('.day-schedule-card').textContent.includes('Select a resource first'),'Unnecessary resource selection prompt');
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
    }else if(name==='empty'){
     check(facility.options.length===1&&doc.querySelectorAll('[data-event-type]').length===0,'Empty filter includes resources/events');
     check(doc.querySelector('.day-schedule-card').textContent.includes('No events scheduled for this date.'),'Useful empty day message missing');
    }else if(name==='next-month'){
     check(!doc.querySelector('a.calendar-day.selected')&&doc.querySelectorAll('[data-event-type]').length===2,'Navigating months lost the selected-day events');
    }else if(name==='overnight'){
     check(doc.querySelectorAll('[data-event-type]').length===5&&doc.querySelectorAll('[data-event-type="official_use"]').length===2,'Eligible event counts differ from the panel');
     check(doc.querySelector('.schedule-list').textContent.includes('Continued from Oct 14, 2026'),'Overnight continuation missing');
    }
   }
   check(calls.every(call=>call.date==='2026-10-15'&&call.month===month.value),'Filter changes dropped the selected date/month');
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
