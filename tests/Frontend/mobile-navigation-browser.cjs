// Render isolated fixtures first: php tests/Frontend/ui-consistency-fixtures.php
// Node's built-in WebSocket drives real Chrome keyboard, pointer and viewport input.
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const {spawn, spawnSync} = require('node:child_process');
const {pathToFileURL} = require('node:url');
const directory = path.resolve('storage/app/mobile-navigation-check');
fs.mkdirSync(directory, {recursive: true});
const css = fs.readFileSync('public/css/app.css', 'utf8');
const scripts = ['modals', 'mobile-navigation', 'notifications'].map(name => fs.readFileSync('public/js/' + name + '.js', 'utf8')).join('\n');
const prepare = (html, styles, js = '') => html.replace(/<link\b[^>]*>/g, '')
    .replace(/<script\b[^>]*>[\s\S]*?<\/script>/g, '')
    .replace('</head>', '<style>' + styles + '</style></head>')
    .replace('</body>', `<script>
window.notificationCalls = []; window.notificationIntervals = [];
window.fetch = async (url, options = {}) => {
 notificationCalls.push({url, options});
 return {ok: true, json: async () => ({unread_count: 3, notifications: {data: [], next_page_url: null}})};
};
window.setInterval = (callback, delay) => { notificationIntervals.push(delay); return 1; };
${js}</script></body>`);
const roles = ['resident', 'admin', 'super-admin'];
for (const role of roles) {
    fs.writeFileSync(path.join(directory, role + '.html'), prepare(fs.readFileSync('storage/app/ui-consistency-check/' + role + '-dashboard.html', 'utf8'), css, scripts));
    const baseline = path.join(directory, 'baseline-' + role + '.html');
    if (fs.existsSync(baseline)) fs.writeFileSync(path.join(directory, 'before-' + role + '.html'), prepare(fs.readFileSync(baseline, 'utf8'), fs.readFileSync(path.join(directory, 'baseline-app.css'), 'utf8')));
}
const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));
const profile = path.join(directory, 'chrome-cdp-' + process.pid);
const browser = spawn('C:/Program Files/Google/Chrome/Application/chrome.exe', [
    '--headless', '--disable-gpu', '--no-first-run', '--no-default-browser-check',
    '--remote-debugging-port=0', '--user-data-dir=' + profile, 'about:blank',
], {windowsHide: true, stdio: ['ignore', 'ignore', 'pipe']});
let errors = '', socket, nextId = 0, scenario = '';
browser.stderr.on('data', data => { errors += data; });
const pending = new Map(), consoleErrors = [];
const deadline = setTimeout(() => { console.error('Browser timed out: ' + errors.slice(-1500)); stop(); process.exitCode = 1; }, 120000);
function stop() {
    clearTimeout(deadline);
    socket?.close();
    if (browser.pid) spawnSync('taskkill', ['/PID', String(browser.pid), '/T', '/F'], {windowsHide: true, timeout: 5000, stdio: 'ignore'});
    browser.stderr.destroy(); browser.unref();
}
function command(method, params = {}) {
    const id = ++nextId;
    return new Promise((resolve, reject) => { pending.set(id, {resolve, reject}); socket.send(JSON.stringify({id, method, params})); });
}
async function evaluate(expression) {
    const result = await command('Runtime.evaluate', {expression, returnByValue: true, awaitPromise: true});
    if (result.exceptionDetails) throw Error(result.exceptionDetails.exception?.description || result.exceptionDetails.text);
    return result.result.value;
}
const check = expression => evaluate(`(() => { if (!(${expression})) throw Error(${JSON.stringify(expression)}); return true; })()`);
async function navigate(file) {
    await command('Page.navigate', {url: pathToFileURL(path.join(directory, file)).href});
    for (let attempt = 0; attempt < 100; attempt++) {
        if (await evaluate(`location.href.endsWith(${JSON.stringify(file)}) && document.readyState === 'complete'`)) return;
        await sleep(20);
    }
    throw Error('Page did not load: ' + file);
}
async function key(key, code, modifiers = 0) {
    const windowsVirtualKeyCode = key === 'Tab' ? 9 : 27;
    await command('Input.dispatchKeyEvent', {type: 'keyDown', key, code, windowsVirtualKeyCode, modifiers});
    await command('Input.dispatchKeyEvent', {type: 'keyUp', key, code, windowsVirtualKeyCode, modifiers});
}
async function click(x, y) {
    await command('Input.dispatchMouseEvent', {type: 'mousePressed', x, y, button: 'left', clickCount: 1});
    await command('Input.dispatchMouseEvent', {type: 'mouseReleased', x, y, button: 'left', clickCount: 1});
}
const geometry = `(() => Object.fromEntries(['.user-topbar','.topbar-inner','.brand-block','.user-tools','.user-summary','.notification-bell','.user-tools > form','.user-nav','.nav-inner','.user-main',...Array.from(document.querySelectorAll('.nav-link'), (_, i) => '.nav-link:nth-child(' + (i + 1) + ')')].map(selector => {
 const element = document.querySelector(selector), rect = element.getBoundingClientRect(), style = getComputedStyle(element);
 return [selector, [rect.x, rect.y, rect.width, rect.height, style.backgroundColor, style.color, style.fontSize, style.padding, style.borderBottom, style.display]];
})))()`;
(async () => {
    try {
        const portFile = path.join(profile, 'DevToolsActivePort');
        for (let attempt = 0; attempt < 100 && !fs.existsSync(portFile); attempt++) await sleep(100);
        if (!fs.existsSync(portFile)) throw Error('Chrome did not start: ' + errors.slice(-1500));
        const port = fs.readFileSync(portFile, 'utf8').split('\n')[0];
        const targets = await (await fetch('http://127.0.0.1:' + port + '/json/list')).json();
        socket = new WebSocket(targets.find(target => target.type === 'page').webSocketDebuggerUrl);
        await new Promise((resolve, reject) => { socket.addEventListener('open', resolve); socket.addEventListener('error', reject); });
        socket.addEventListener('message', event => {
            const message = JSON.parse(event.data);
            if (message.id) {
                const request = pending.get(message.id); pending.delete(message.id);
                message.error ? request.reject(Error(message.error.message)) : request.resolve(message.result);
            } else if (message.method === 'Runtime.exceptionThrown') consoleErrors.push(message.params.exceptionDetails.text);
            else if (message.method === 'Runtime.consoleAPICalled' && message.params.type === 'error') consoleErrors.push(message.params.args.map(arg => arg.value).join(' '));
        });
        await command('Runtime.enable'); await command('Page.enable');
        const results = [];
        for (const role of roles) for (const [width, height] of [[320,568],[390,844],[412,915],[768,700],[900,700],[901,800],[1280,900],[1440,900]]) {
            scenario = role + ' ' + width + 'x' + height;
            await command('Emulation.setDeviceMetricsOverride', {width, height, deviceScaleFactor: 1, mobile: width <= 900});
            await command('Emulation.setEmulatedMedia', {features: [{name: 'prefers-reduced-motion', value: 'no-preference'}]});
            let before;
            if (width > 900 && fs.existsSync(path.join(directory, 'before-' + role + '.html'))) { await navigate('before-' + role + '.html'); before = await evaluate(geometry); }
            await navigate(role + '.html');
            await check('document.documentElement.scrollWidth <= innerWidth + 1');
            await check('document.querySelectorAll("[data-notifications]").length === 1 && notificationIntervals.length === 1 && notificationIntervals[0] === 15000');
            await check('document.querySelector(".notification-badge").textContent === "3"');
            if (width > 900) {
                await check('!document.querySelector(".mobile-menu-button").getClientRects().length && !document.getElementById("mobile-navigation").getClientRects().length');
                await check('document.querySelector(".user-nav").getClientRects().length && document.querySelector(".user-tools > form").getClientRects().length');
                if (before) assert.deepEqual(await evaluate(geometry), before, role + ' desktop geometry changed at ' + width);
                results.push({role, width, height, desktop: true, baselineCompared: !!before});
                continue;
            }
            await check('!document.querySelector(".user-nav").getClientRects().length && !document.querySelector(".user-tools > form").getClientRects().length');
            await check('document.querySelector(".mobile-menu-button").getBoundingClientRect().height >= 44 && document.querySelector(".notification-bell").getBoundingClientRect().height >= 44');
            await evaluate('document.querySelector(".notification-bell").click()');
            await check('!document.getElementById("notification-panel").hidden && document.getElementById("notification-panel").getBoundingClientRect().right <= innerWidth');
            await evaluate('document.querySelector(".mobile-menu-button").click()'); await sleep(250);
            await check('document.getElementById("mobile-navigation").open && document.querySelector(".mobile-menu-button").getAttribute("aria-expanded") === "true"');
            await check('document.getElementById("notification-panel").hidden && document.activeElement.classList.contains("mobile-drawer-close")');
            await check('getComputedStyle(document.body).position === "fixed" && getComputedStyle(document.documentElement).overflowY === "hidden"');
            await check('document.getElementById("mobile-navigation").getBoundingClientRect().width === Math.min(innerWidth * .84, 360) || Math.abs(document.getElementById("mobile-navigation").getBoundingClientRect().width - Math.min(innerWidth * .84, 360)) < 1');
            await check('document.getElementById("mobile-navigation").getBoundingClientRect().height === innerHeight');
            await check('document.querySelector(".mobile-drawer-profile strong").textContent === document.querySelector(".user-summary strong").textContent');
            await check('JSON.stringify([...document.querySelectorAll(".drawer-link")].map(a=>[a.href,a.textContent.trim()])) === JSON.stringify([...document.querySelectorAll(".nav-link")].map(a=>[a.href,a.textContent.trim()]))');
            await check('[...document.querySelectorAll(".drawer-link")].every(a => a.querySelector("svg") && a.getBoundingClientRect().height >= 44 && a.scrollWidth <= a.clientWidth + 1)');
            await check('document.querySelectorAll(".drawer-link[aria-current=page]").length === 1');
            await key('Tab', 'Tab', 8); await check('document.activeElement.closest(".mobile-drawer-footer")');
            await key('Tab', 'Tab'); await check('document.activeElement.classList.contains("mobile-drawer-close")');
            await evaluate('document.querySelector(".user-main h2").setAttribute("tabindex", "0"); document.querySelector(".user-main h2").focus()');
            await check('document.getElementById("mobile-navigation").contains(document.activeElement)');
            // Stress long account values so every viewport has independently scrollable content.
            await evaluate('window.profileHTML = document.querySelector(".mobile-drawer-profile").innerHTML; document.querySelector(".mobile-drawer-profile strong").textContent = "Authenticated account with a long name ".repeat(20); document.querySelector(".mobile-drawer-profile > span:not(.mobile-drawer-avatar)").textContent = "long-email".repeat(100) + "@example.test"');
            await check('document.getElementById("mobile-navigation").scrollWidth <= document.getElementById("mobile-navigation").clientWidth + 1');
            // Actual wheel input must scroll only the menu and keep Logout reachable.
            const point = await evaluate('(() => {const r=document.querySelector(".mobile-drawer-scroll").getBoundingClientRect();return {x:r.left+30,y:r.top+30};})()');
            await command('Input.dispatchMouseEvent', {type: 'mouseWheel', ...point, deltaX: 0, deltaY: 800}); await sleep(120);
            await check('window.scrollY === 0 && document.querySelector(".mobile-drawer-footer").getBoundingClientRect().bottom <= innerHeight');
            await check('document.querySelector(".mobile-drawer-scroll").scrollTop > 0');
            await evaluate('document.querySelector(".mobile-drawer-profile").innerHTML = profileHTML; document.querySelector(".mobile-drawer-scroll").scrollTop = 0');
            if (width === 390) {
                await evaluate('document.querySelector(".mobile-drawer-scroll").scrollTop = 0');
                const shot = await command('Page.captureScreenshot', {format: 'png'});
                fs.writeFileSync(path.join(directory, role + '-mobile.png'), Buffer.from(shot.data, 'base64'));
            }
            await key('Escape', 'Escape'); await sleep(220);
            await check('!document.getElementById("mobile-navigation").open && document.activeElement.classList.contains("mobile-menu-button") && getComputedStyle(document.body).position !== "fixed"');
            await evaluate('document.querySelector(".mobile-menu-button").click()'); await sleep(250);
            await click(width - 8, height / 2); await sleep(220);
            await check('!document.getElementById("mobile-navigation").open && document.activeElement.classList.contains("mobile-menu-button")');
            await evaluate('document.querySelector(".mobile-menu-button").click()');
            await evaluate('document.querySelector(".mobile-drawer-close").click()'); await sleep(220);
            await check('!document.getElementById("mobile-navigation").open');
            // A queued native close event must not unlock a newly reopened drawer.
            await evaluate('document.querySelector(".mobile-menu-button").click(); document.querySelector(".drawer-link").addEventListener("click", e=>e.preventDefault(), {once:true}); document.querySelector(".drawer-link").click(); document.querySelector(".mobile-menu-button").click()');
            await sleep(30);
            await check('document.getElementById("mobile-navigation").open && document.querySelector(".mobile-menu-button").getAttribute("aria-expanded") === "true" && getComputedStyle(document.body).position === "fixed"');
            await key('Escape', 'Escape'); await sleep(220);
            // Preserve the page's scroll offset and inline style after closing.
            await evaluate('document.querySelector(".user-main").style.minHeight="2000px"; document.body.style.left="2px"; window.scrollTo(0, 200); document.querySelector(".mobile-menu-button").click()');
            await key('Escape', 'Escape'); await sleep(220);
            await check('scrollY === 200 && document.body.style.left === "2px"');
            await evaluate('document.body.style.left=""; window.scrollTo(0, 0)');
            // Links and Logout use native navigation/forms; suppress only the test's real network navigation.
            await evaluate('document.querySelector(".mobile-menu-button").click(); document.querySelector(".drawer-link").addEventListener("click", e=>e.preventDefault(), {once:true}); document.querySelector(".drawer-link").click()');
            await check('!document.getElementById("mobile-navigation").open');
            await evaluate('document.querySelector(".mobile-menu-button").click(); document.querySelector(".mobile-drawer-footer form").addEventListener("submit", e=>e.preventDefault(), {once:true}); document.querySelector(".mobile-drawer-footer form").requestSubmit()');
            await check('!document.getElementById("mobile-navigation").open');
            // Existing dialogs keep their separate scroll lock and native focus.
            await evaluate('document.querySelector(".mobile-menu-button").click(); window.testModal=document.createElement("dialog"); testModal.className="ereserve-modal facility-modal-panel"; testModal.innerHTML="<button autofocus>Existing modal</button>"; document.querySelector("main").append(testModal); EReserveModal.open(testModal)');
            await sleep(20);
            await check('!document.getElementById("mobile-navigation").open && testModal.open && testModal.contains(document.activeElement) && document.documentElement.classList.contains("modal-open")');
            await evaluate('EReserveModal.close(testModal); testModal.remove()');
            await command('Emulation.setEmulatedMedia', {features: [{name: 'prefers-reduced-motion', value: 'reduce'}]});
            await evaluate('document.querySelector(".mobile-menu-button").click()');
            await check('getComputedStyle(document.getElementById("mobile-navigation")).animationName === "none"');
            await key('Escape', 'Escape'); await check('!document.getElementById("mobile-navigation").open');
            await evaluate('document.querySelector(".mobile-menu-button").click()');
            await command('Emulation.setDeviceMetricsOverride', {width: 1280, height: 900, deviceScaleFactor: 1, mobile: false}); await sleep(30);
            await check('!document.getElementById("mobile-navigation").open && !document.documentElement.classList.contains("mobile-drawer-open")');
            results.push({role, width, height, mobile: true});
        }
        assert.deepEqual(consoleErrors, [], 'JavaScript console errors');
        fs.writeFileSync(path.join(directory, 'results.json'), JSON.stringify(results, null, 2));
        console.log('Mobile navigation: ' + results.length + ' role/viewport scenarios passed; native keyboard, pointer, scroll, notification and modal checks passed.');
        console.log('Desktop comparisons: ' + results.filter(row => row.baselineCompared).length + '.');
    } finally { stop(); }
})().catch(error => { console.error('Scenario: ' + scenario); console.error(error); process.exitCode = 1; });
