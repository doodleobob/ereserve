// Run with: node tests/Frontend/time-layout.cjs [path-to-chrome]
// Uses the real Blade form markup and stylesheet in isolated browser frames.
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const {pathToFileURL} = require('node:url');
const {spawnSync} = require('node:child_process');
const assert = require('node:assert/strict');

const blade = fs.readFileSync('resources/views/facility-show.blade.php', 'utf8');
const start = blade.indexOf('<div class="time-grid">');
const end = blade.indexOf('<label for="purpose">', start);
const markup = blade.slice(start, end).replace(/<div class="reservation-group">\s*$/, '');
const css = fs.readFileSync('public/css/app.css', 'utf8');
const fixtures = [];
for (const width of [1100, 768, 390]) {
    for (const errorField of [null, 'start_time', 'end_time']) {
        const fields = markup.replace(/@error\('([^']+)'\)([\s\S]*?)@enderror/g, (_, field) =>
            field === errorField ? '<p class="form-error">Please enter a different valid time.</p>' : '')
            .replace(/\{\{[\s\S]*?\}\}/g, '22:00');
        fixtures.push({width, errorField, html: `<style>${css}</style><main class="reservation-card"><form class="reservation-form">${fields}</form></main>`});
    }
}
const temp = fs.mkdtempSync(path.join(os.tmpdir(), 'ereserve-time-layout-'));
const fixture = path.join(temp, 'layout.html');
fs.writeFileSync(fixture, `<!doctype html><html><body><pre id="result">RUNNING</pre><script>
const fixtures = ${JSON.stringify(fixtures)};
const near = (a, b) => Math.abs(a - b) < 1;
Promise.all(fixtures.map(fixture => new Promise(resolve => {
    const frame = document.createElement('iframe');
    frame.style.width = fixture.width + 'px';
    frame.style.height = '450px';
    frame.onload = () => {
        try {
            const doc = frame.contentDocument;
            const a = doc.querySelector('#start_time').getBoundingClientRect();
            const b = doc.querySelector('#end_time').getBoundingClientRect();
            const la = doc.querySelector('[for="start_time"]').getBoundingClientRect();
            const lb = doc.querySelector('[for="end_time"]').getBoundingClientRect();
            if (!near(a.width, b.width) || !near(a.height, b.height)) throw Error('Unequal input dimensions');
            if (fixture.width > 640 && (!near(a.top, b.top) || !near(la.top, lb.top))) throw Error('Fields are not aligned');
            if (fixture.width <= 640 && b.top <= a.bottom) throw Error('Mobile fields did not stack');
            if (fixture.errorField) {
                const input = doc.querySelector('#' + fixture.errorField).getBoundingClientRect();
                if (doc.querySelector('.form-error').getBoundingClientRect().top < input.bottom) throw Error('Error is above its input');
            }
            resolve({width: fixture.width, error: fixture.errorField, passed: true});
        } catch (error) { resolve({width: fixture.width, error: fixture.errorField, failure: error.message}); }
    };
    frame.srcdoc = fixture.html;
    document.body.append(frame);
}))).then(results => { document.querySelector('#result').textContent = JSON.stringify(results); });
</script></body></html>`);
const chrome = process.argv[2] || 'C:/Program Files/Google/Chrome/Application/chrome.exe';
try {
    const result = spawnSync(chrome, ['--headless', '--disable-gpu', '--no-first-run', '--no-default-browser-check', `--user-data-dir=${path.join(temp, 'profile')}`, '--dump-dom', '--virtual-time-budget=5000', pathToFileURL(fixture).href], {encoding: 'utf8', timeout: 30000, windowsHide: true});
    if (result.error) throw result.error;
    const match = result.stdout.match(/<pre id="result">([^<]+)<\/pre>/);
    assert.ok(match, 'Browser did not produce layout results');
    const measurements = JSON.parse(match[1]);
    measurements.forEach(measurement => assert.equal(measurement.passed, true, JSON.stringify(measurement)));
    console.log(`Passed ${measurements.length} browser layout checks: desktop/tablet/mobile, with no error, Start Time error, and End Time error.`);
} finally {
    // Only remove the unique temporary directory created by this script.
    if (path.dirname(temp) === path.resolve(os.tmpdir()) && path.basename(temp).startsWith('ereserve-time-layout-')) {
        fs.rmSync(temp, {recursive: true, force: true, maxRetries: 3});
    }
}
