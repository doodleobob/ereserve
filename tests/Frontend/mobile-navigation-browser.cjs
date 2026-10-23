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
const scripts = ['form-validation', 'modals', 'mobile-navigation', 'resources-navigation', 'notifications'].map(name => fs.readFileSync('public/js/' + name + '.js', 'utf8')).join('\n');
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
    fs.writeFileSync(path.join(directory, role + '.html'), prepare(fs.readFileSync(path.join(process.argv[2] || 'storage/app/ui-consistency-check', role + '-dashboard.html'), 'utf8'), css, scripts));
    if (process.argv[2]) fs.writeFileSync(path.join(directory, role + '-profile.html'), prepare(fs.readFileSync(path.join(process.argv[2], role + '-profile.html'), 'utf8'), css, scripts));
    if (process.argv[2]) fs.writeFileSync(path.join(directory, role + '-reservations.html'), prepare(fs.readFileSync(path.join(process.argv[2], role + '-reservations.html'), 'utf8'), css, scripts));
    const baseline = path.join(directory, 'baseline-' + role + '.html');
    if (!process.argv[2] && fs.existsSync(baseline)) fs.writeFileSync(path.join(directory, 'before-' + role + '.html'), prepare(fs.readFileSync(baseline, 'utf8'), fs.readFileSync(path.join(directory, 'baseline-app.css'), 'utf8')));
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
const deadline = setTimeout(() => { console.error('Browser timed out: ' + errors.slice(-1500)); stop(); process.exitCode = 1; }, 210000);
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
const check = expression => evaluate(`(() => { if (!(${expression})) throw Error(${JSON.stringify(expression)} + '\\n' + JSON.stringify({active: document.activeElement?.outerHTML.slice(0, 250), open: document.getElementById('mobile-navigation')?.open, closing: document.getElementById('mobile-navigation')?.className, bodyPosition: getComputedStyle(document.body).position})); return true; })()`);
async function navigate(file) {
    await command('Page.navigate', {url: pathToFileURL(path.join(directory, file)).href});
    for (let attempt = 0; attempt < 100; attempt++) {
        if (await evaluate(`location.href.endsWith(${JSON.stringify(file)}) && document.readyState === 'complete'`)) return;
        await sleep(20);
    }
    throw Error('Page did not load: ' + file);
}
async function key(key, code, modifiers = 0) {
    const windowsVirtualKeyCode = key === 'Tab' ? 9 : key === 'Enter' ? 13 : 27;
    await command('Input.dispatchKeyEvent', {type: 'keyDown', key, code, windowsVirtualKeyCode, modifiers, ...(key === 'Enter' ? {text: '\r'} : {})});
    await command('Input.dispatchKeyEvent', {type: 'keyUp', key, code, windowsVirtualKeyCode, modifiers});
}
async function click(x, y) {
    await command('Input.dispatchMouseEvent', {type: 'mouseMoved', x, y});
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
        const protocol = await (await fetch('http://127.0.0.1:' + port + '/json/protocol')).json();
        const safeAreaSupported = protocol.domains.find(domain => domain.domain === 'Emulation')?.commands.some(command => command.name === 'setSafeAreaInsetsOverride');
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
        for (const role of roles) for (const [width, height] of [[320,568],[375,812],[390,844],[430,932],[568,320],[768,700],[1024,800],[1279,800],[1200,900],[1280,900],[1440,900]]) {
            scenario = role + ' ' + width + 'x' + height;
            await command('Emulation.setDeviceMetricsOverride', {width, height, deviceScaleFactor: 1, mobile: width <= 1279});
            await command('Emulation.setEmulatedMedia', {features: [{name: 'prefers-reduced-motion', value: 'no-preference'}]});
            let before;
            if (!process.argv[2] && width > 1279 && fs.existsSync(path.join(directory, 'before-' + role + '.html'))) { await navigate('before-' + role + '.html'); before = await evaluate(geometry); }
            await navigate(role + '.html');
            await check('document.documentElement.scrollWidth <= innerWidth + 1');
            await check('document.querySelectorAll("[data-notifications]").length === 1 && notificationIntervals.length === 1 && notificationIntervals[0] === 15000');
            await check('document.querySelector(".notification-badge").textContent === "3"');
            // Observe the native link activation without leaving the isolated fixture.
            await check('document.querySelector(".profile-button").tagName === "A" && new URL(document.querySelector(".profile-button").href).pathname === "/profile"');
            await check('document.querySelector(".profile-button").getAttribute("aria-label") === "Profile Settings" && document.querySelector(".profile-button").title === "Profile Settings"');
            await check('!document.querySelector("[data-profile-navigation], .profile-navigation-links")');
            await check('document.querySelector(".profile-button").getBoundingClientRect().width >= 44');
            await check(`(() => {const profile=getComputedStyle(document.querySelector('.profile-button')), bell=getComputedStyle(document.querySelector('.notification-bell')); return ['backgroundColor','width','height','padding','borderRadius','border','boxShadow'].every(p=>profile[p]===bell[p]);})()`);
            await check('document.querySelector(".notification-bell").getBoundingClientRect().right <= document.querySelector(".profile-button").getBoundingClientRect().left');
            await check(`(() => {
                const bell = document.querySelector('.notification-bell svg').getBBox(), profile = document.querySelector('.profile-button svg').getBBox();
                return ['x', 'y', 'width', 'height'].every(property => Math.abs(bell[property] - profile[property]) < 0.01);
            })()`);
            await check(`(() => {
                const buttons = ['.notification-bell', '.profile-button'].map(s => document.querySelector(s));
                const expectedSize = innerWidth <= 1279 ? 22 : 18;
                const expectedButton = innerWidth <= 1279 ? 48 : 44;
                return buttons.every(button => {
                    const box = button.getBoundingClientRect(), icon = button.querySelector('svg'), rect = icon.getBoundingClientRect(), style = getComputedStyle(icon);
                    return box.width === expectedButton && box.height === expectedButton && rect.width === expectedSize && rect.height === expectedSize
                        && Math.abs(rect.x + rect.width / 2 - box.x - box.width / 2) < 0.5
                        && Math.abs(rect.y + rect.height / 2 - box.y - box.height / 2) < 0.5
                        && style.strokeWidth === '2px' && style.stroke === 'rgb(255, 255, 255)';
                });
            })()`);
            await check(`(() => {
                const badge = document.querySelector('.notification-badge').getBoundingClientRect(), bell = document.querySelector('.notification-bell').getBoundingClientRect(), profile = document.querySelector('.profile-button').getBoundingClientRect();
                return badge.width > 0 && badge.top >= 0 && badge.right < profile.left && Math.abs(badge.right - bell.right - 6) < 0.5
                    && (innerWidth > 1279 || profile.left - bell.right === 8);
            })()`);
            if ([320,375,390,430].includes(width)) {
                const shot = await command('Page.captureScreenshot', {format: 'png', clip: {x: 0, y: 0, width, height: 80, scale: 2}});
                fs.writeFileSync(path.join(directory, role + '-header-' + width + '.png'), Buffer.from(shot.data, 'base64'));
            }
            await evaluate('window.profileActivations=[];document.querySelector(".profile-button").addEventListener("click",e=>{e.preventDefault();profileActivations.push(e.currentTarget.href)});document.querySelector(".notification-bell").focus()');
            await key('Tab', 'Tab');
            await check('document.activeElement.classList.contains("profile-button")');
            await check('getComputedStyle(document.activeElement).outlineStyle !== "none"');
            await key('Enter', 'Enter');
            await check('profileActivations.length === 1 && new URL(profileActivations[0]).pathname === "/profile"');
            const profilePoint = await evaluate('(() => { const r=document.querySelector(".profile-button").getBoundingClientRect();return {x:r.x+r.width/2,y:r.y+r.height/2}; })()');
            // Start a separate pointer interaction after the keyboard-focused activation.
            await click(1, 1);
            if (width <= 1279) {
                await command('Emulation.setTouchEmulationEnabled', {enabled: true});
                await command('Input.dispatchTouchEvent', {type: 'touchStart', touchPoints: [{...profilePoint, id: 1}]});
                await command('Input.dispatchTouchEvent', {type: 'touchEnd', touchPoints: []});
                await sleep(100);
            } else await click(profilePoint.x, profilePoint.y);
            await check('profileActivations.length === 2');
            await check('getComputedStyle(document.querySelector(".profile-button")).boxShadow === "none" && !document.querySelector(".profile-button").matches(":focus-visible")');
            await evaluate('document.querySelector(".notification-bell").click()');
            await check('!document.getElementById("notification-panel").hidden');
            await click(profilePoint.x, profilePoint.y);
            await check('profileActivations.length === 3 && document.getElementById("notification-panel").hidden');
            if (width > 1279) {
                await check('new Set([...document.querySelector(".nav-inner").children].map(e=>Math.round(e.getBoundingClientRect().top))).size === 1');
                await check('document.querySelector(".profile-button").getBoundingClientRect().right <= document.querySelector(".user-tools > form").getBoundingClientRect().left');
                if (role !== 'resident') {
                    await evaluate('window.navHeight=document.querySelector(".user-nav").getBoundingClientRect().height;window.mainTop=document.querySelector("main").getBoundingClientRect().top;const summary=document.querySelector(".user-nav [data-resources-navigation] summary");summary.focus();summary.click()');
                    await check('document.querySelector(".user-nav [data-resources-navigation]").open && document.documentElement.scrollWidth <= innerWidth + 1');
                    await check('document.querySelector(".user-nav").getBoundingClientRect().height === navHeight && document.querySelector("main").getBoundingClientRect().top === mainTop');
                    await key('Escape', 'Escape');
                    await check('!document.querySelector(".user-nav [data-resources-navigation]").open');
                }

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
            await check('document.getElementById("notification-panel").hidden && document.activeElement.matches(".mobile-drawer-nav a") && !document.querySelector(".mobile-drawer-close")');
            await check('getComputedStyle(document.body).position === "fixed" && getComputedStyle(document.documentElement).overflowY === "hidden"');
            await check('document.getElementById("mobile-navigation").getBoundingClientRect().width === Math.min(innerWidth * .84, 360) || Math.abs(document.getElementById("mobile-navigation").getBoundingClientRect().width - Math.min(innerWidth * .84, 360)) < 1');
            await check('Math.abs(document.getElementById("mobile-navigation").getBoundingClientRect().bottom - innerHeight) < 1 && document.getElementById("mobile-navigation").getBoundingClientRect().top === 0 && getComputedStyle(document.getElementById("mobile-navigation")).borderRadius === "0px"');
            await check('document.querySelector(".mobile-drawer-profile strong").textContent === document.querySelector(".user-summary strong").textContent');
            await check('document.querySelector(".mobile-drawer-title").textContent === "eReserve" && document.querySelector(".mobile-drawer-email").textContent.includes("@")');
            await check('document.querySelectorAll(".mobile-menu-button").length === 1 && document.querySelector(".mobile-drawer-heading .mobile-menu-button") && document.querySelector(".user-topbar").inert');
            await evaluate('document.querySelector(".mobile-drawer-scroll").scrollTop = 0');
            await check(`(() => {
                const elements = ['.mobile-drawer-title', '.mobile-drawer-avatar', '.mobile-drawer-name', '.mobile-drawer-email', '.mobile-drawer-barangay'].map(selector => document.querySelector(selector));
                const rects = elements.map(element => element.getBoundingClientRect()), drawer = document.getElementById('mobile-navigation').getBoundingClientRect();
                return rects.every((rect, index) => Math.abs(rect.x + rect.width / 2 - drawer.width / 2) < 1 && (!index || rect.top >= rects[index - 1].bottom))
                    && rects[1].width === 56 && rects[1].height === 56
                    && elements.slice(2).every(element => getComputedStyle(element).textAlign === 'center');
            })()`);
            await check('JSON.stringify([...document.querySelectorAll(".drawer-link")].map(a=>[a.href,a.textContent.trim()])) === JSON.stringify([...document.querySelectorAll(".nav-link")].map(a=>[a.href,a.textContent.trim()]))');
            await check('[...document.querySelectorAll(".drawer-link")].filter(a => a.getClientRects().length).every(a => a.querySelector("svg") && a.getBoundingClientRect().height >= 44 && a.scrollWidth <= a.clientWidth + 1)');
            await check('document.querySelectorAll(".drawer-link[aria-current=page]").length === 1');
            if (role !== 'resident') {
                await evaluate('document.querySelector(".mobile-drawer [data-resources-navigation] summary").focus()');
                await key('Enter', 'Enter');
                await check('document.querySelector(".mobile-drawer [data-resources-navigation]").open && document.getElementById("mobile-navigation").open');
                await key('Tab', 'Tab');
                await check('document.activeElement.textContent.trim() === "Facility Management"');
                await key('Escape', 'Escape');
                await check('!document.querySelector(".mobile-drawer [data-resources-navigation]").open && document.getElementById("mobile-navigation").open');
            }
            await evaluate('document.querySelector(".mobile-menu-button").focus()');
            await key('Tab', 'Tab', 8); await check('document.activeElement.closest(".mobile-drawer-footer")');
            await key('Tab', 'Tab'); await check('document.activeElement.classList.contains("mobile-menu-button")');
            await key('Tab', 'Tab'); await check('document.activeElement.matches(".mobile-drawer-nav a")');
            await evaluate('document.querySelector(".profile-button").focus()');
            await check('document.getElementById("mobile-navigation").contains(document.activeElement)');
            await evaluate('document.querySelector(".user-main h2").setAttribute("tabindex", "0"); document.querySelector(".user-main h2").focus()');
            await check('document.getElementById("mobile-navigation").contains(document.activeElement)');
            // Stress long account values so every viewport has independently scrollable content.
            await evaluate('window.profileHTML = document.querySelector(".mobile-drawer-profile").innerHTML; document.querySelector(".mobile-drawer-name").textContent = "Authenticated account with a long name ".repeat(20); document.querySelector(".mobile-drawer-email").textContent = "longemail".repeat(100) + "@example.test"; document.querySelector(".mobile-drawer-barangay").textContent = "LongBarangayName".repeat(100)');
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
            await check('document.querySelector(".user-topbar .mobile-menu-button") && !document.querySelector(".user-topbar").inert && document.querySelector(".user-topbar .notification-bell") && document.querySelector(".user-topbar .profile-button")');
            await evaluate('document.querySelector(".mobile-menu-button").click()'); await sleep(250);
            await click(width - 8, height / 2); await sleep(220);
            await check('!document.getElementById("mobile-navigation").open && document.querySelector(".mobile-menu-button").getAttribute("aria-expanded") === "false"');
            await evaluate('document.querySelector(".mobile-menu-button").click()'); await sleep(250);
            // Real pointer input must reach the same hamburger inside the drawer.
            const togglePoint = await evaluate('(() => {const r=document.querySelector(".mobile-menu-button").getBoundingClientRect();return {x:r.x+r.width/2,y:r.y+r.height/2};})()');
            await click(togglePoint.x, togglePoint.y); await sleep(220);
            await check('!document.getElementById("mobile-navigation").open');
            await click(togglePoint.x, togglePoint.y); await sleep(250);
            await command('Input.dispatchTouchEvent', {type: 'touchStart', touchPoints: [{...togglePoint, id: 1}]});
            await command('Input.dispatchTouchEvent', {type: 'touchEnd', touchPoints: []}); await sleep(550);
            await check('!document.getElementById("mobile-navigation").open && document.querySelector(".mobile-menu-button").getAttribute("aria-expanded") === "false"');
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
        const safeAreaResults = [];
        if (safeAreaSupported) {
            await command('Emulation.setDeviceMetricsOverride', {width: 390, height: 844, deviceScaleFactor: 1, mobile: true});
            await command('Emulation.setSafeAreaInsetsOverride', {insets: {top: 47, bottom: 34, left: 0, right: 0}});
            for (const role of roles) {
                scenario = role + ' safe-area insets';
                await navigate(role + '.html');
                await evaluate('document.querySelector(".mobile-menu-button").click()'); await sleep(250);
                await check('document.getElementById("mobile-navigation").getBoundingClientRect().top === 0');
                await check('document.querySelector(".mobile-drawer-title").getBoundingClientRect().top >= 47 && document.querySelector(".mobile-menu-button").getBoundingClientRect().top >= 47');
                await check('parseFloat(getComputedStyle(document.querySelector(".mobile-drawer-heading")).paddingTop) === 55 && parseFloat(getComputedStyle(document.querySelector(".mobile-drawer-footer")).paddingBottom) === 34');
                await check('document.querySelector(".mobile-drawer-footer button").getBoundingClientRect().bottom <= innerHeight - 34');
                await key('Escape', 'Escape'); await sleep(220);
                await check('document.querySelector(".user-topbar .mobile-menu-button") && !document.querySelector(".user-topbar").inert');
                safeAreaResults.push(role);
            }
            await command('Emulation.setSafeAreaInsetsOverride', {insets: {top: 0, bottom: 0, left: 0, right: 0}});
        }
        const touchResults = [];
        if (process.argv[2]) for (const role of roles) for (const width of [320,375,390,430]) {
            scenario = role + ' reservations touch/keyboard ' + width;
            await command('Emulation.setDeviceMetricsOverride', {width, height: 844, deviceScaleFactor: 1, mobile: true});
            await command('Emulation.setTouchEmulationEnabled', {enabled: true});
            await navigate(role + '-reservations.html');
            const point = await evaluate('(() => {const r=document.querySelector(".mobile-menu-button").getBoundingClientRect();return {x:r.x+r.width/2,y:r.y+r.height/2};})()');
            const tapToggle = async () => {
                await command('Input.dispatchTouchEvent', {type: 'touchStart', touchPoints: [{...point, id: 1}]});
                await command('Input.dispatchTouchEvent', {type: 'touchEnd', touchPoints: []});
                await sleep(550);
            };
            await tapToggle();
            await check('document.getElementById("mobile-navigation").open');
            await check('document.querySelectorAll(".mobile-drawer-nav a.active").length === 1 && document.querySelector(".mobile-drawer-nav a.active").getAttribute("href").endsWith("/reservations")');
            await check('!document.querySelector(".mobile-drawer-nav a").matches(":focus, :focus-visible, .active") && getComputedStyle(document.querySelector(".mobile-drawer-nav a")).outlineStyle === "none"');
            await check('![...document.querySelectorAll(".mobile-drawer-nav a")].some(link => link.matches(":focus-visible"))');
            await tapToggle();
            await check('!document.getElementById("mobile-navigation").open');
            // Keyboard focus belongs on the current route and stays visibly indicated.
            await evaluate('document.querySelector(".mobile-menu-button").focus()');
            await key('Enter', 'Enter'); await sleep(250);
            await check('document.activeElement.matches(".mobile-drawer-nav a[aria-current=page]:focus-visible") && getComputedStyle(document.activeElement).outlineStyle !== "none"');
            await key('Escape', 'Escape'); await sleep(220);
            await check('document.activeElement.matches(".mobile-menu-button:focus-visible")');
            // A new touch after keyboard use must not inherit Dashboard's focus ring.
            await tapToggle();
            await check('![...document.querySelectorAll(".mobile-drawer-nav a")].some(link => link.matches(":focus"))');
            const shot = await command('Page.captureScreenshot', {format: 'png'});
            fs.writeFileSync(path.join(directory, role + '-reservations-' + width + '.png'), Buffer.from(shot.data, 'base64'));
            await key('Escape', 'Escape'); await sleep(220);
            touchResults.push({role, width});
        }
        if (process.argv[2]) for (const role of roles) {
            await navigate(role + '-profile.html');
            await check('document.querySelector(".profile-button").classList.contains("active") && document.querySelector(".profile-button").getAttribute("aria-current") === "page"');
            await command('Input.dispatchMouseEvent', {type: 'mouseMoved', x: 0, y: 0});
            await check(`(() => {const profile=getComputedStyle(document.querySelector('.profile-button')), bell=getComputedStyle(document.querySelector('.notification-bell'));return profile.backgroundColor===bell.backgroundColor && profile.boxShadow==='none' && profile.outlineStyle==='none';})()`);
            await evaluate('location.hash="security"'); await sleep(30);
            await check('document.querySelector(".profile-button").getAttribute("aria-current") === "page"');
        }
        assert.deepEqual(consoleErrors, [], 'JavaScript console errors');
        fs.writeFileSync(path.join(directory, 'results.json'), JSON.stringify(results, null, 2));
        console.log('Mobile navigation: ' + results.length + ' role/viewport scenarios passed; native keyboard, pointer, scroll, notification and modal checks passed.');
        console.log('Desktop comparisons: ' + results.filter(row => row.baselineCompared).length + '.');
        console.log('Reservations touch/keyboard focus: ' + touchResults.length + ' role/viewport scenarios passed.');
        console.log('Emulated safe-area insets: ' + (safeAreaSupported ? safeAreaResults.length + ' roles passed.' : 'not supported by this browser.'));
    } finally { stop(); }
})().catch(error => { console.error('Scenario: ' + scenario); console.error(error); process.exitCode = 1; });
