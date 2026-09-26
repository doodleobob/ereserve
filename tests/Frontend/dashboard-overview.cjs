// Run with the directory rendered by DASHBOARD_RENDER_DIR during DashboardOverviewTest.
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const assert = require('node:assert/strict');
const { spawnSync } = require('node:child_process');
const { pathToFileURL } = require('node:url');

const fixtures = ['admin', 'super_admin'].map(role => {
    const html = fs.readFileSync(path.join(process.argv[2], role + '.html'), 'utf8');
    const main = html.match(/<main\b[^>]*>[\s\S]*?<\/main>/)[0].replace(/<link[^>]*>/g, '');
    const css = fs.readFileSync('public/css/app.css', 'utf8') + fs.readFileSync('public/css/dashboard-overview.css', 'utf8');
    return { role, html: `<style>${css}</style>${main}` };
});
const temp = fs.mkdtempSync(path.join(os.tmpdir(), 'ereserve-dashboard-browser-'));
try {
    const page = path.join(temp, 'check.html');
    fs.writeFileSync(page, `<!doctype html><html><body><pre id="result">RUNNING</pre><script>
    const fixtures = ${JSON.stringify(fixtures).replace(/</g, '\\u003c')};
    Promise.all(fixtures.flatMap(fixture => [1280, 1024, 768, 390, 320].map(width => new Promise(resolve => {
        const frame = document.createElement('iframe');
        frame.style.width = width + 'px'; frame.style.height = '1200px';
        frame.onload = () => {
            try {
                const doc = frame.contentDocument;
                const win = frame.contentWindow;
                if (doc.documentElement.scrollWidth > doc.documentElement.clientWidth + 1) throw Error('Horizontal overflow');
                if (doc.querySelectorAll('.stat-card').length !== 4) throw Error('Expected four cards');
                if (doc.querySelectorAll('.dashboard-grid > article').length !== 2) throw Error('Expected two sections');
                const cardColumns = win.getComputedStyle(doc.querySelector('.stats-grid')).gridTemplateColumns.split(' ').length;
                if (cardColumns !== (width > 1100 ? 4 : width > 520 ? 2 : 1)) throw Error('Unexpected card columns: ' + cardColumns);
                const sectionColumns = win.getComputedStyle(doc.querySelector('.dashboard-grid')).gridTemplateColumns.split(' ').length;
                if (sectionColumns !== (width > 900 ? 2 : 1)) throw Error('Unexpected section columns');
                if (doc.body.textContent.includes('Quick Actions') || doc.querySelector('canvas')) throw Error('Redundant content');
                resolve({ role: fixture.role, width, passed: true });
            } catch (error) { resolve({ role: fixture.role, width, failure: error.message }); }
        };
        frame.srcdoc = fixture.html;
        document.body.append(frame);
    })))).then(results => { document.getElementById('result').textContent = JSON.stringify(results); });
    </script></body></html>`);
    const chrome = process.argv[3] || 'C:/Program Files/Google/Chrome/Application/chrome.exe';
    const result = spawnSync(chrome, ['--headless', '--disable-gpu', '--disable-background-networking', '--no-proxy-server', '--no-first-run', '--no-default-browser-check', `--user-data-dir=${path.join(temp, 'profile')}`, '--dump-dom', '--timeout=10000', '--virtual-time-budget=5000', pathToFileURL(page).href], { encoding: 'utf8', timeout: 30000, windowsHide: true, maxBuffer: 8 * 1024 * 1024 });
    if (result.error) throw result.error;
    const match = result.stdout.match(/<pre id="result">([^<]+)<\/pre>/);
    assert.ok(match, 'Browser did not return results');
    const results = JSON.parse(match[1]);
    for (const result of results) assert.equal(result.passed, true, JSON.stringify(result));
    console.log('Passed both dashboard layouts at 1280, 1024, 768, 390, and 320px.');
} finally {
    const resolved = path.resolve(temp);
    if (path.dirname(resolved) === path.resolve(os.tmpdir()) && path.basename(resolved).startsWith('ereserve-dashboard-browser-')) {
        try { fs.rmSync(resolved, { recursive: true, force: true, maxRetries: 3 }); }
        catch (error) { console.warn('Temporary browser profile is still locked: ' + error.code); }
    }
}
