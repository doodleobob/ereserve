const fs = require('node:fs'), path = require('node:path'), assert = require('node:assert/strict');
const {pathToFileURL} = require('node:url');
const runBrowser = require('./run-browser.cjs');
const directory = path.resolve('storage/app/facility-layout-check');
const css = fs.readFileSync('public/css/app.css', 'utf8');
const scripts = ['modals', 'facility-management', 'facility-gallery'].map(name => fs.readFileSync('public/js/' + name + '.js', 'utf8')).join('\n');
const sources = ['admin', 'resident'].map(role => {
    const html = fs.readFileSync(path.join(directory, role + '.html'), 'utf8');
    const main = html.match(/<main\b[^>]*>[\s\S]*?<\/main>/)[0].replace(/<script\b[^>]*>[\s\S]*?<\/script>/g, '').replace(/src="[^"]*layout-photo.png"/g, 'src="' + pathToFileURL(path.join(directory, 'photo.svg')).href + '"');
    return {role, source: '<!doctype html><html><head><meta name="viewport" content="width=device-width, initial-scale=1"><style>' + css + '</style></head><body class="user-page">' + main + '<script>' + scripts + '</script></body></html>'};
});
const encode = value => JSON.stringify(value).replace(/</g, '\\u003c');
const page = path.join(directory, 'browser.html');
fs.writeFileSync(page, `<!doctype html><html><body><pre id="result">RUNNING</pre><script>
const sources=${encode(sources)};
(async()=>{const results=[];for(const {role,source} of sources)for(const width of [1440,1280,1100,1024,900,768,640,390,320,240])results.push(await new Promise(resolve=>{
 const frame=document.createElement('iframe');frame.style.width=width+'px';frame.style.height='900px';
 frame.onload=async()=>{try {
  const doc=frame.contentDocument,win=frame.contentWindow,check=(ok,message)=>{if(!ok)throw Error(message);},rect=element=>element.getBoundingClientRect(),tick=()=>new Promise(resolve=>setTimeout(resolve,0));
  await tick();
  const cards=[...doc.querySelectorAll('.facility-card')],grid=doc.querySelector('.facility-grid');check(cards.length===6,'Expected all six Facility/Equipment records');
  check(doc.documentElement.scrollWidth<=width+1,'Page horizontal overflow');
  const columns=win.getComputedStyle(grid).gridTemplateColumns.split(' ').length;check(columns===(width>1100?3:width>640?2:1),'Incorrect desktop/tablet/mobile columns');
  check(rect(cards[columns-1]).right>=rect(grid).right-1,'Cards leave unused grid width');
  for(const card of cards){
   const box=rect(card),title=card.querySelector('h3'),badge=card.querySelector('.availability-badge'),body=card.querySelector('.facility-body'),footer=card.querySelector('.facility-card-footer');
   check(card.scrollWidth<=card.clientWidth+1,'Card content overflows');check(rect(title).right<=rect(badge).left+1,'Long name collides with badge');
   check(Math.abs(rect(footer).bottom+parseFloat(win.getComputedStyle(body).paddingBottom)-box.bottom)<1,'Actions are not anchored at card bottom');
   for(const info of card.querySelectorAll('.facility-description:not(.facility-summary)')){const range=doc.createRange();range.selectNodeContents(info);check(rect(info).height<=range.getBoundingClientRect().height+parseFloat(win.getComputedStyle(info).lineHeight)/2,'Information reserves blank height');}
   check(rect(card.querySelector('.facility-summary')).height<=parseFloat(win.getComputedStyle(card.querySelector('.facility-summary')).lineHeight)*3+1,'Description exceeds three lines');
   if(role==='admin'){
    const actions=[...footer.querySelectorAll('button')];check(actions.length===3,'Missing View/Edit/Delete action');
    check(actions[0].textContent.trim()==='View'&&actions[1].textContent.trim()==='Edit'&&actions[2].getAttribute('aria-label').startsWith('Delete '),'Wrong card actions');
    for(const button of actions){const b=rect(button);check(b.height>=44&&b.left>=box.left&&b.right<=box.right+1,'Clipped or undersized action');check(button.scrollWidth<=button.clientWidth+1,'Action text clipped');}
    check(actions.every(button=>Math.abs(rect(button).height-rect(actions[0]).height)<1),'Action heights differ');
    check(Math.abs(rect(actions[0]).width-rect(actions[1]).width)<1,'View and Edit widths unbalanced');
    if(width>=320)check(actions.every(button=>Math.abs(rect(button).top-rect(actions[0]).top)<1),'Delete wrapped on normal card width');
    const styles=actions.map(button=>win.getComputedStyle(button));for(const property of ['fontSize','fontWeight','borderRadius','paddingTop','paddingBottom'])check(styles.every(style=>style[property]===styles[0][property]),'Inconsistent '+property);
    const editIcon=actions[1].querySelector('svg'),deleteIcon=actions[2].querySelector('svg');check(rect(editIcon).width===rect(deleteIcon).width&&Math.abs((rect(editIcon).top-rect(actions[1]).top)-(rect(deleteIcon).top-rect(actions[2]).top))<1,'Icon alignment inconsistent');
    actions[1].focus();check(win.getComputedStyle(actions[1]).outlineStyle!=='none','Keyboard focus invisible');actions[1].disabled=true;check(win.getComputedStyle(actions[1]).opacity==='0.6','Disabled action style missing');actions[1].disabled=false;
   }
  }
  for(let index=0;index<cards.length;index+=columns){const row=cards.slice(index,index+columns);check(row.every(card=>Math.abs(rect(card).bottom-rect(row[0]).bottom)<1),'Card bottoms do not align');check(row.every(card=>Math.abs(rect(card.querySelector('.facility-card-footer')).top-rect(row[0].querySelector('.facility-card-footer')).top)<1),'Row action areas do not align');}
  check(cards.some(card=>card.textContent.includes('Equipment'))&&cards.some(card=>card.textContent.includes('Facility')),'Both resource categories must render');
  if(role==='admin'){
   const add=doc.querySelector('.add-facility-button');check(rect(add).height>=44,'Add touch target inconsistent');if(width>640)check(rect(add).width<rect(doc.querySelector('.page-heading')).width/2,'Add stretches across desktop');
   let posts=0;win.fetch=async()=>{posts++;throw Error('Unexpected mutation');};
   for(const trigger of [add,...cards[0].querySelectorAll('.facility-card-action')]){trigger.click();const modal=doc.getElementById(trigger.dataset.modalOpen);check(modal.open&&modal.classList.contains('ereserve-modal'),'Action missing shared modal');check(rect(modal).left>=0&&rect(modal).right<=width+1,'Modal outside screen');if(trigger.textContent.trim()==='View')check(modal.textContent.includes('Full description for community events. '.repeat(13).trim()),'View loses full description');modal.querySelector('[data-modal-close]').click();await tick();check(!modal.open,'Modal failed to close');}
   check(posts===0,'Viewing/cancelling triggered a mutation');
  }else check(!doc.querySelector('[data-modal-open^="delete-facility-"]'),'Resident received admin actions');
  resolve({role,width,columns,passed:true});
 }catch(error){resolve({role,width,passed:false,error:error.message});}};
 frame.srcdoc=source;document.body.appendChild(frame);
}));document.getElementById('result').textContent=JSON.stringify(results);})();
</script></body></html>`);
// Also produce standalone rendered pages for visual inspection, using the same markup.
for (const {role, source} of sources) fs.writeFileSync(path.join(directory, role + '-preview.html'), source);
runBrowser(page, path.join(directory, 'chrome-' + process.pid)).then(results => {
fs.writeFileSync(path.join(directory, 'results.json'), JSON.stringify(results, null, 2));
const failures = results.filter(row => !row.passed);if (failures.length) console.error(JSON.stringify(failures, null, 2));
assert.equal(failures.length, 0, 'Facility layout checks failed');
console.log('Facility/Equipment rendered card checks passed: ' + results.length);
}).catch(error => { console.error(error); process.exitCode = 1; });
