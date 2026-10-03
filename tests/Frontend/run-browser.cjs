const {spawn, spawnSync} = require('node:child_process');
const {pathToFileURL} = require('node:url');

// Chrome can keep its Windows output pipe open after --dump-dom finishes.
// Consume the completed results and stop only the browser started by this test.
module.exports = (page, profile) => new Promise((resolve, reject) => {
    const child = spawn('C:/Program Files/Google/Chrome/Application/chrome.exe', [
        '--headless', '--disable-gpu', '--no-first-run', '--no-default-browser-check',
        '--user-data-dir=' + profile, '--dump-dom', '--virtual-time-budget=15000', pathToFileURL(page).href,
    ], {windowsHide: true});
    let output = '', errors = '', finished = false;
    const finish = (error, results) => {
        if (finished) return;
        finished = true;
        clearTimeout(timer);
        if (child.pid) spawnSync('taskkill', ['/PID', String(child.pid), '/T', '/F'], {windowsHide: true, timeout: 5000, stdio: 'ignore'});
        child.stdout.destroy();
        child.stderr.destroy();
        child.unref();
        error ? reject(error) : resolve(results);
    };
    const timer = setTimeout(() => finish(Error('Browser results timed out: ' + errors.slice(-1000))), 45000);
    child.on('error', error => finish(error));
    child.stderr.on('data', data => { errors += data; });
    child.stdout.on('data', data => {
        output += data;
        const match = output.match(/<pre id="result">([^<]+)<\/pre>/);
        if (!match || match[1] === 'RUNNING') return;
        try { finish(null, JSON.parse(match[1])); } catch (error) { finish(error); }
    });
    child.on('exit', code => { if (!finished && code !== 0) finish(Error('Chrome exited with ' + code + ': ' + errors.slice(-1000))); });
});
