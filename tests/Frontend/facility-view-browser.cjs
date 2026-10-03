const fs = require('node:fs'), path = require('node:path'), assert = require('node:assert/strict');
const {pathToFileURL} = require('node:url');
const runBrowser = require('./run-browser.cjs');
const directory = path.resolve('storage/app/facility-layout-check');
const css = fs.readFileSync('public/css/app.css', 'utf8');
const scripts = ['modals', 'facility-management', 'facility-gallery'].map(name => fs.readFileSync('public/js/' + name + '.js', 'utf8')).join('\n');
const sources = ['admin', 'super-admin', 'resident-detail'].map(role => {
    const html = fs.readFileSync(path.join(directory, role + '.html'), 'utf8');
    const main = html.match(/<main\b[^>]*>[\s\S]*?<\/main>/)[0].replace(/<script\b[^>]*>[\s\S]*?<\/script>/g, '')
        .replace(/src="\/storage\/([^"\s]+)"/g, (_, file) => 'src="' + pathToFileURL(path.join(directory, file.endsWith('layout-photo.png') ? 'photo.svg' : 'uploads/' + file)).href + '"');
    return {role, main, source: '<!doctype html><html><head><meta name="viewport" content="width=device-width, initial-scale=1"><style>' + css + '</style></head><body class="user-page">' + main + '<script>' + scripts + '</script></body></html>'};
});
const encode = value => JSON.stringify(value).replace(/</g, '\\u003c');
const page = path.join(directory, 'view-browser.html');
fs.writeFileSync(page, `<!doctype html><html><body><pre id="result">RUNNING</pre><script>
const sources=${encode(sources)};
(async()=>{const results=[];for(const {role,source,main} of sources)for(const [width,height] of [[1280,900],[768,700],[640,600],[390,568],[320,568],[240,400]])results.push(await new Promise(resolve=>{
 const frame=document.createElement('iframe');frame.style.width=width+'px';frame.style.height=height+'px';
 frame.onload=async()=>{try{
  const doc=frame.contentDocument,win=frame.contentWindow,check=(ok,message)=>{if(!ok)throw Error(message);},rect=element=>element.getBoundingClientRect(),tick=()=>new Promise(resolve=>setTimeout(resolve,0));
  await tick();const before=win.location.href;let requests=0;
  win.fetch=async()=>{requests++;throw Error('Unexpected request');};
  const galleryCheck=async(gallery)=>{
   const slides=[...gallery.querySelectorAll('[data-carousel-slide]')],indicators=[...gallery.querySelectorAll('[data-carousel-indicator]')];
   const active=index=>{check(slides.every((slide,i)=>slide.hidden===(i!==index)),'Carousel slide visibility incorrect');check(indicators.every((button,i)=>button.getAttribute('aria-current')===(i===index?'true':'false')),'Carousel indicator state incorrect');};
   active(0);gallery.querySelector('[data-carousel-next]').click();active(1);gallery.querySelector('[data-carousel-next]').click();active(0);gallery.querySelector('[data-carousel-previous]').click();active(1);
   indicators[0].click();active(0);gallery.dispatchEvent(new win.KeyboardEvent('keydown',{key:'ArrowRight',bubbles:true,cancelable:true}));active(1);
   for(const slide of slides){const image=slide.querySelector('img');check(win.getComputedStyle(image).objectFit==='contain','Image is cropped or stretched');await image.decode();check(image.naturalWidth>0,'Image failed to load');}
   check(slides[0].querySelector('img').naturalWidth>slides[0].querySelector('img').naturalHeight,'Missing landscape photo');check(slides[1].querySelector('img').naturalHeight>slides[1].querySelector('img').naturalWidth,'Missing portrait photo');
  };
  if(role==='resident-detail'){await galleryCheck(doc.querySelector('[data-facility-carousel]'));resolve({role,width,height,passed:true});return;}
  const triggers=[...doc.querySelectorAll('[data-modal-open^="view-facility-"]')];check(triggers.length===6,'Missing View actions');
  for(const trigger of triggers){
   trigger.click();const modal=doc.getElementById(trigger.dataset.modalOpen),body=modal.querySelector('.facility-view-body'),header=modal.querySelector('.facility-modal-header'),footer=modal.querySelector('.facility-modal-actions');
   check(modal.open&&modal.classList.contains('ereserve-modal'),'View does not use shared dialog');check(doc.documentElement.classList.contains('modal-open'),'Background scroll unlocked');
   check(!modal.querySelector('form,input,textarea,select,a'),'View contains editable controls or navigation');
   check(footer.querySelectorAll('button').length===1&&footer.textContent.trim()==='Close','Footer has management actions');
   const box=rect(modal);check(box.left>=0&&box.right<=width+1&&box.top>=0&&box.bottom<=height+1,'View outside viewport');
   check(body.scrollWidth<=body.clientWidth+1,'View body horizontal overflow');
   check(rect(header).top>=box.top&&rect(footer).bottom<=box.bottom+1,'Header/Close inaccessible');
   check(rect(footer.querySelector('button')).height>=44&&rect(footer.querySelector('button')).width<rect(footer).width/2,'Close not standard content-fit button');
   const facts=modal.querySelector('.facility-view-facts');check(win.getComputedStyle(facts).gridTemplateColumns.split(' ').length===(width<=640?1:3),'Details fail to stack responsively');
   const title=rect(modal.querySelector('h2')),badge=rect(modal.querySelector('.availability-badge'));check(title.right<=badge.left+1||title.bottom<=badge.top+1,'Name overlaps availability');
   body.scrollTop=body.scrollHeight;if(body.scrollHeight>body.clientHeight)check(body.scrollTop>0,'Long body does not scroll');check(Math.abs(rect(header).top-box.top)<1&&rect(footer).bottom<=box.bottom+1,'Scrolling hides header/footer');
   if(trigger.dataset.modalOpen.endsWith('-0')){
    check(modal.querySelector('.facility-view-description dd').textContent==='Full description for community events. '.repeat(13),'Description truncated');
    check(facts.textContent.includes('Barangay / Location')&&!facts.querySelector('dt').textContent.includes('Category'),'Equivalent location missing combined label');
    check([...facts.querySelectorAll('dt')].filter(el=>el.textContent.trim()==='Location').length===0,'Equivalent location repeated');
    await galleryCheck(modal.querySelector('[data-facility-carousel]'));
   }
   if(trigger.dataset.modalOpen.endsWith('-2'))check(facts.textContent.includes('LongLocation'.repeat(12)),'Distinct location omitted');
   if(trigger.dataset.modalOpen.endsWith('-3'))check(modal.textContent.includes('Currently in Use')&&modal.textContent.includes('9:00 AM - 11:00 AM'),'Current availability details lost');
   if(trigger.dataset.modalOpen.endsWith('-4'))check(modal.querySelectorAll('img').length===1&&!modal.querySelector('[data-facility-carousel]'),'Single photo gets extra carousel');
   if(trigger.dataset.modalOpen.endsWith('-1'))check(!modal.querySelector('img')&&modal.querySelector('.facility-detail-image > svg'),'Missing photo fallback');
   footer.querySelector('[data-modal-close]').click();await tick();check(!modal.open&&doc.activeElement===trigger,'Close fails to restore trigger focus');check(!doc.documentElement.classList.contains('modal-open'),'Scroll lock remains');
   if(modal.querySelector('[data-facility-carousel]')){trigger.click();check(!modal.querySelector('[data-carousel-slide]').hidden,'Gallery did not reset on close');modal.querySelector('.facility-modal-header [data-modal-close]').click();await tick();}
  }
  check(requests===0&&win.location.href===before,'View navigates or fetches data');
  win.fetch=async()=>{requests++;return{ok:true,redirected:false,text:async()=>main};};await win.EReserveModal.refreshMain();
  doc.dispatchEvent(new win.Event('modals:updated')); // Reinitialization must not attach duplicate handlers.
  doc.querySelector('[data-modal-open="view-facility-layout-resource-0"]').click();const refreshed=doc.getElementById('view-facility-layout-resource-0');await galleryCheck(refreshed.querySelector('[data-facility-carousel]'));
  refreshed.querySelector('.facility-modal-actions [data-modal-close]').click();await tick();check(!refreshed.open&&requests===1,'Refresh or Close produced unexpected requests');
  resolve({role,width,height,passed:true});
 }catch(error){resolve({role,width,height,passed:false,error:error.message});}};
 frame.srcdoc=source;document.body.appendChild(frame);
}));document.getElementById('result').textContent=JSON.stringify(results);})();
</script></body></html>`);
for (const {role, source} of sources) fs.writeFileSync(path.join(directory, role + '-view-preview.html'), source);
runBrowser(page, path.join(directory, 'chrome-view-' + process.pid)).then(results => {
fs.writeFileSync(path.join(directory,'view-results.json'),JSON.stringify(results,null,2));
const failures=results.filter(row=>!row.passed);if(failures.length)console.error(JSON.stringify(failures,null,2));
assert.equal(results.length,18,'Missing View scenarios');assert.equal(failures.length,0,'Facility View checks failed');
console.log('Facility View and resident gallery browser scenarios passed: '+results.length);
}).catch(error => { console.error(error); process.exitCode = 1; });
