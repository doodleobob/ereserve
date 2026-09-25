// Render a real dashboard with ANALYTICS_RENDER_PATH set while running AdminAnalyticsTest,
// then: node tests/Frontend/admin-analytics.cjs path/to/rendered.html [chrome-path]
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { pathToFileURL } = require('node:url');
const { spawnSync } = require('node:child_process');
const assert = require('node:assert/strict');
const rendered = fs.readFileSync(process.argv[2], 'utf8');
const section = rendered.slice(rendered.indexOf('<section id="admin-analytics"'), rendered.indexOf('</section>', rendered.indexOf('<section id="admin-analytics"')) + 10)
    .replace(/<link[^>]+>/g, '').replace(/<script src=[\s\S]*?<\/script>/g, '');
assert.ok(section.includes('admin-analytics-data'), 'Expected real rendered analytics');
const css = fs.readFileSync('public/css/app.css', 'utf8') + fs.readFileSync('public/css/admin-analytics.css', 'utf8');
const chart = fs.readFileSync('public/js/vendor/chart.umd.min.js', 'utf8');
const script = fs.readFileSync('public/js/admin-analytics.js', 'utf8');
const fixture = `<style>${css}</style><main class="page-shell">${section}</main><script>${chart}</script><script>${script}</script>`;
const temp = fs.mkdtempSync(path.join(os.tmpdir(), 'ereserve-analytics-'));
const page = path.join(temp, 'analytics.html');
fs.writeFileSync(page, `<!doctype html><html><body><pre id="result">RUNNING</pre><script>
const markup = ${JSON.stringify(fixture).replace(/</g, '\\u003c')};
Promise.all([1280, 768, 390, 320].map(width => new Promise(resolve => {
    const frame = document.createElement('iframe');
    frame.style.width = width + 'px'; frame.style.height = '1000px';
    frame.onload = () => setTimeout(() => {
        try {
            const doc = frame.contentDocument;
            const win = frame.contentWindow;
            if (doc.documentElement.scrollWidth > doc.documentElement.clientWidth + 1) throw Error('Horizontal page overflow');
            for (const id of ['requests', 'collection', 'status']) {
                const canvas = doc.getElementById('analytics-' + id);
                const chart = win.Chart.getChart(canvas);
                if (!chart || canvas.getBoundingClientRect().width < 50) throw Error('Chart failed: ' + id);
            }
            const collection = win.Chart.getChart(doc.getElementById('analytics-collection'));
            if (collection.data.datasets[0].data.reduce((a, b) => a + b, 0) !== 2000.75) throw Error('Wrong collection data');
            const select = doc.getElementById('analytics-period');
            select.value = 'custom'; select.dispatchEvent(new win.Event('change'));
            if (doc.getElementById('analytics-start').disabled) throw Error('Custom dates disabled');
            let submitted = false;
            select.form.requestSubmit = () => { submitted = true; };
            select.value = '7'; select.dispatchEvent(new win.Event('change'));
            if (!submitted || !doc.getElementById('analytics-start').disabled) throw Error('Preset filter failed');
            resolve({ width, passed: true });
        } catch (error) { resolve({ width, failure: error.message }); }
    }, 500);
    frame.srcdoc = markup; document.body.append(frame);
}))).then(results => { document.getElementById('result').textContent = JSON.stringify(results); });
</script></body></html>`);
try {
    const chrome = process.argv[3] || 'C:/Program Files/Google/Chrome/Application/chrome.exe';
    const result = spawnSync(chrome, ['--headless', '--disable-gpu', '--disable-background-networking', '--no-proxy-server', '--no-first-run', '--no-default-browser-check', `--user-data-dir=${path.join(temp, 'profile')}`, '--dump-dom', '--timeout=10000', '--virtual-time-budget=7000', pathToFileURL(page).href], { encoding: 'utf8', timeout: 30000, windowsHide: true, maxBuffer: 8 * 1024 * 1024 });
    if (result.error) throw result.error;
    const match = result.stdout.match(/<pre id="result">([^<]+)<\/pre>/);
    assert.ok(match, 'Browser did not return results');
    const results = JSON.parse(match[1]);
    results.forEach(result => assert.equal(result.passed, true, JSON.stringify(result)));
    console.log('Passed analytics charts, filter controls, and overflow checks at 1280, 768, 390, and 320px.');
} finally {
    const resolved = path.resolve(temp);
    if (path.dirname(resolved) === path.resolve(os.tmpdir()) && path.basename(resolved).startsWith('ereserve-analytics-')) {
        try {
            fs.rmSync(resolved, { recursive: true, force: true, maxRetries: 3 });
        } catch (error) {
            console.warn(`Temporary browser profile still locked: ${resolved} (${error.code})`);
        }
    }
}
