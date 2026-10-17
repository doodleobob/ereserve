// Generate fixtures with RESOURCE_FILTER_RENDER_DIR=storage/app/resource-filter-check.
const fs = require('node:fs'), path = require('node:path'), http = require('node:http'), assert = require('node:assert/strict');
const runBrowser = require('./run-browser.cjs');
const directory = path.resolve('storage/app/resource-filter-check');
const assetPaths = ['css/app.css', 'css/resident-booking.css', 'css/management-tables.css', 'js/facility-management.js'];

function checkFilters(name) {
    const check = (condition, message) => { if (!condition) throw Error(message); };
    const search = document.querySelector('#facility-search'), category = document.querySelector('#facility-category');
    const status = document.querySelector('#facility-status'), barangay = document.querySelector('[data-browse-barangay]');
    const cards = [...document.querySelectorAll('[data-facility-card]')];
    const visible = () => cards.filter(card => getComputedStyle(card).display !== 'none');
    const change = (field, value) => { field.value = value; field.dispatchEvent(new Event(field === search ? 'input' : 'change', {bubbles: true})); };
    check([...category.options].map(option => option.value).join() === 'all,facility,equipment', 'Category options missing');
    check(search.placeholder === 'Search facilities and equipment...', 'Search placeholder incorrect');
    check(document.documentElement.scrollWidth <= innerWidth + 1, 'Horizontal page overflow');
    if (name === 'default') {
        check(visible().length === 3, 'Initial cards missing');
        change(category, 'equipment');
        check(visible().length === 2 && visible().every(card => card.dataset.category === 'equipment'), 'Category does not visually hide facility cards');
        change(status, 'available');
        change(search, ' sOuNd ');
        check(visible().length === 1 && visible()[0].dataset.name === 'sound system', 'Combined filters failed');
        check(category.value === 'equipment' && status.value === 'available', 'Selections reset during filtering');
        const url = new URL(location.href);
        check(url.searchParams.get('category') === 'equipment' && url.searchParams.get('status') === 'available' && url.searchParams.get('search') === ' sOuNd ', 'Filters are not retained in URL for reload');
        change(search, 'missing');
        check(visible().length === 0 && !document.querySelector('[data-empty-facilities]').hidden, 'Empty results are not displayed correctly');
        change(search, ''); change(status, 'all'); change(category, 'facility');
        check(visible().length === 1 && visible()[0].dataset.category === 'facility', 'Facility category failed');
        change(category, 'all');
        check(visible().length === 3 && document.querySelector('[data-empty-facilities]').hidden, 'Clearing filters did not restore cards');
        change(search, 'all');
        check(new URL(location.href).searchParams.get('search') === 'all', 'Literal search for all was treated as a dropdown default');
        change(search, '');
        change(category, 'equipment'); change(status, 'unavailable');
        check(visible().length === 1 && visible()[0].dataset.name === 'sound backup', 'Availability filter failed');
        change(search, 'Sound');
        let submitted;
        barangay.form.submit = () => { submitted = Object.fromEntries(new FormData(barangay.form)); };
        change(barangay, 'Washington');
        check(submitted.barangay === 'Washington' && submitted.category === 'equipment' && submitted.status === 'unavailable' && submitted.search === 'Sound', 'Barangay navigation drops filters');
    } else {
        check(category.value === 'equipment' && status.value === 'available', 'Category/status not restored on load');
        check(search.value === (name === 'empty' ? 'missing' : 'Sound'), 'Search not restored on load');
        check(visible().length === (name === 'empty' ? 0 : 1), 'Restored filters not applied visually on load');
        check(barangay.value === (name === 'other-barangay' ? 'Washington' : 'Taft'), 'Barangay selection lost');
        if (name === 'other-barangay') check(cards.length === 1 && visible()[0].dataset.name === 'washington sound', 'Barangay scope changed');
    }
    // Back/Forward restoration must reapply the restored native control values.
    category.value = 'all'; status.value = 'all'; search.value = '';
    window.dispatchEvent(new Event('pageshow'));
    check(visible().length === cards.length, 'Restored controls did not refresh cards');
}

(async () => {
    const server = http.createServer((req, res) => {
        const url = new URL(req.url, 'http://localhost');
        if (url.pathname === '/browser.html') {
            res.setHeader('Content-Type', 'text/html; charset=utf-8');
            res.end(fs.readFileSync(path.join(directory, 'browser.html'))); return;
        }
        if (assetPaths.includes(url.pathname.slice(1))) {
            res.setHeader('Content-Type', url.pathname.endsWith('.css') ? 'text/css' : 'text/javascript');
            res.end(fs.readFileSync(path.join('public', url.pathname.slice(1)))); return;
        }
        const name = url.pathname.slice(1);
        if (!['default', 'filtered', 'empty', 'other-barangay'].includes(name)) { res.writeHead(404); res.end(); return; }
        let html = fs.readFileSync(path.join(directory, name + '.html'), 'utf8');
        html = html.replace(/<link\b[^>]*>|<script\b[^>]*>[\s\S]*?<\/script>/g, tag => {
            const value = tag.match(/(?:href|src)="([^"]+)"/)?.[1];
            const asset = value && new URL(value, 'http://localhost');
            return asset && assetPaths.includes(asset.pathname.slice(1)) ? tag.replace(value, asset.pathname + asset.search) : '';
        });
        res.setHeader('Content-Type', 'text/html; charset=utf-8'); res.end(html);
    });
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    try {
        const page = path.join(directory, 'browser.html');
        const origin = 'http://127.0.0.1:' + server.address().port;
        fs.writeFileSync(page, `<!doctype html><html><body><pre id="result">RUNNING</pre><script>
        const checkSource=${JSON.stringify(checkFilters.toString()).replace(/</g, '\\u003c')};
        (async()=>{const results=[];for(const name of ['default','filtered','empty','other-barangay'])for(const width of [1440,768,390,320])results.push(await new Promise(resolve=>{
            const frame=document.createElement('iframe');frame.style.width=width+'px';frame.style.height='900px';
            frame.onload=()=>{try{frame.contentWindow.eval('('+checkSource+')('+JSON.stringify(name)+')');resolve({name,width,passed:true});}catch(error){resolve({name,width,passed:false,error:error.message});}finally{frame.remove();}};
            frame.src=${JSON.stringify(origin)}+'/'+name;document.body.appendChild(frame);
        }));document.querySelector('#result').textContent=JSON.stringify(results);})();</script></body></html>`);
        const results = await runBrowser(new URL(origin + '/browser.html'), path.join(directory, 'chrome-' + process.pid));
        const failures = results.filter(result => !result.passed);
        fs.writeFileSync(path.join(directory, 'results.json'), JSON.stringify(results, null, 2));
        assert.deepEqual(failures, []);
        console.log('Resident resource filter browser checks passed: ' + results.length);
    } finally { server.closeAllConnections(); server.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
