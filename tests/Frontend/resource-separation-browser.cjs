// Render with RESOURCE_SEPARATION_RENDER_DIR=storage/app/resource-separation-check and ResourceSeparationTest.
const fs = require('node:fs'), path = require('node:path'), assert = require('node:assert/strict');
const {spawn, spawnSync} = require('node:child_process');
const {pathToFileURL} = require('node:url');
const directory = path.resolve('storage/app/resource-separation-check');
const styles = ['app', 'resident-booking', 'management-tables'].map(name => fs.readFileSync(`public/css/${name}.css`, 'utf8')).join('\n');
const baselineStyles = process.env.RESOURCES_BASELINE_CSS
    ? [fs.readFileSync(process.env.RESOURCES_BASELINE_CSS, 'utf8'), ...['resident-booking', 'management-tables'].map(name => fs.readFileSync(`public/css/${name}.css`, 'utf8'))].join('\n') : null;
const scripts = ['form-validation', 'modals', 'mobile-navigation', 'resources-navigation', 'facility-management', 'resource-quantity'].map(name => fs.readFileSync(`public/js/${name}.js`, 'utf8')).join('\n');
for (const role of ['user', 'admin', 'super_admin']) for (const route of ['facilities', 'equipment']) {
    const html = fs.readFileSync(path.join(directory, `${role}-${route}.html`), 'utf8')
        .replace(/<link\b[^>]*>/g, '').replace(/<script\b[^>]*>[\s\S]*?<\/script>/g, '')
        .replace('</head>', `<style>${styles}</style></head>`)
        .replace('</body>', `<script>${scripts}</script></body>`);
    fs.writeFileSync(path.join(directory, `browser-${role}-${route}.html`), html);
}
const profile = path.join(directory, 'chrome-' + process.pid);
const browser = spawn('C:/Program Files/Google/Chrome/Application/chrome.exe', ['--headless', '--disable-gpu', '--no-first-run', '--no-default-browser-check', '--remote-debugging-port=0', '--user-data-dir=' + profile, 'about:blank'], {windowsHide: true, stdio: ['ignore', 'ignore', 'pipe']});
const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));
let socket, id = 0, errors = '', scenario = '';
const pending = new Map(), exceptions = [];
browser.stderr.on('data', data => { errors += data; });
const stop = () => {
    socket?.close();
    if (browser.pid) spawnSync('taskkill', ['/PID', String(browser.pid), '/T', '/F'], {windowsHide: true, timeout: 5000, stdio: 'ignore'});
    browser.stderr.destroy(); browser.unref();
};
const timeout = setTimeout(() => { stop(); console.error('Timed out', scenario); process.exitCode = 1; }, 180000);
const command = (method, params = {}) => new Promise((resolve, reject) => { const key = ++id; pending.set(key, {resolve, reject}); socket.send(JSON.stringify({id: key, method, params})); });
const evaluate = async expression => {
    const result = await command('Runtime.evaluate', {expression, returnByValue: true, awaitPromise: true});
    if (result.exceptionDetails) throw Error(result.exceptionDetails.exception?.description || result.exceptionDetails.text);
    return result.result.value;
};
const check = async expression => assert.equal(await evaluate(expression), true, scenario + ': ' + expression);
const key = async (key, code, keyCode) => {
    await command('Input.dispatchKeyEvent', {type: 'keyDown', key, code, windowsVirtualKeyCode: keyCode, ...(key === 'Enter' ? {text: '\r'} : {})});
    await command('Input.dispatchKeyEvent', {type: 'keyUp', key, code, windowsVirtualKeyCode: keyCode});
};
const pointer = async (selector, touch) => {
    const point = await evaluate(`(() => { const e=document.querySelector(${JSON.stringify(selector)});e.scrollIntoView({block:'center'});const r=e.getBoundingClientRect();return {x:r.x+r.width/2,y:r.y+r.height/2}; })()`);
    if (touch) {
        await command('Input.dispatchTouchEvent', {type: 'touchStart', touchPoints: [{...point, id: 1}]});
        await command('Input.dispatchTouchEvent', {type: 'touchEnd', touchPoints: []});
    } else {
        await command('Input.dispatchMouseEvent', {type: 'mousePressed', ...point, button: 'left', clickCount: 1});
        await command('Input.dispatchMouseEvent', {type: 'mouseReleased', ...point, button: 'left', clickCount: 1});
    }
    await sleep(80);
};
(async () => {
    try {
        const portFile = path.join(profile, 'DevToolsActivePort');
        for (let i = 0; i < 100 && !fs.existsSync(portFile); i++) await sleep(100);
        if (!fs.existsSync(portFile)) throw Error(errors.slice(-1500));
        const port = fs.readFileSync(portFile, 'utf8').split('\n')[0];
        const targets = await (await fetch(`http://127.0.0.1:${port}/json/list`)).json();
        socket = new WebSocket(targets.find(target => target.type === 'page').webSocketDebuggerUrl);
        await new Promise((resolve, reject) => { socket.addEventListener('open', resolve); socket.addEventListener('error', reject); });
        socket.addEventListener('message', event => {
            const message = JSON.parse(event.data);
            if (message.id) { const request = pending.get(message.id); pending.delete(message.id); message.error ? request.reject(Error(message.error.message)) : request.resolve(message.result); }
            else if (message.method === 'Runtime.exceptionThrown') exceptions.push(message.params.exceptionDetails.text);
        });
        await command('Runtime.enable'); await command('Page.enable');
        await command('Emulation.setEmulatedMedia', {features: [{name: 'prefers-reduced-motion', value: 'reduce'}]});
        let passed = 0;
        for (const role of ['user', 'admin', 'super_admin']) for (const route of ['facilities', 'equipment']) for (const width of [320, 375, 390, 430, 768, 1024, 1279, 1280, 1440, 1920]) {
            scenario = `${role} ${route} ${width}px`;
            const mobile = width <= 1279;
            await command('Emulation.setDeviceMetricsOverride', {width, height: 900, deviceScaleFactor: 1, mobile});
            await command('Emulation.setTouchEmulationEnabled', {enabled: mobile});
            const url = pathToFileURL(path.join(directory, `browser-${role}-${route}.html`)).href;
            await command('Page.navigate', {url});
            for (let i = 0; i < 100; i++) { if (await evaluate(`location.href === ${JSON.stringify(url)} && document.readyState === 'complete'`)) break; await sleep(20); }
            await check('document.documentElement.scrollWidth <= innerWidth + 1');
            if (mobile) {
                await pointer('.mobile-menu-button', true);
                await check('document.getElementById("mobile-navigation").open');
            }
            const scope = mobile ? '#mobile-navigation' : '.user-nav';
            if (baselineStyles) {
                const snapshot = `JSON.stringify([...document.querySelectorAll(${JSON.stringify(scope + ' a, ' + scope + ' summary')})].filter(e => !e.closest('.resources-navigation-links')).map(e => {
                    const r=e.getBoundingClientRect(), s=getComputedStyle(e);
                    return {html:e.outerHTML, x:r.x, y:r.y, width:r.width, height:r.height, color:s.color, background:s.backgroundColor,
                        padding:s.padding, margin:s.margin, gap:s.gap, font:s.font, border:s.border, radius:s.borderRadius};
                }))`;
                const current = await evaluate(snapshot);
                await evaluate(`document.querySelector('style').textContent=${JSON.stringify(baselineStyles)}`);
                assert.equal(await evaluate(snapshot), current, scenario + ': Other navigation buttons changed');
                await evaluate(`document.querySelector('style').textContent=${JSON.stringify(styles)}`);
            }
            if (!mobile) {
                await check(`([...document.querySelectorAll('.user-nav a, .user-nav summary')]).every(link => {
                    const icon = link.querySelector('svg'), style = icon && getComputedStyle(icon);
                    return icon && style.width === '18px' && style.height === '18px' && style.strokeWidth === '2px' && style.stroke === getComputedStyle(link).color;
                })`);
                await check(`new Set([...document.querySelector('.nav-inner').children].map(item => Math.round(item.getBoundingClientRect().top))).size === 1`);
                await check(`([...document.querySelectorAll('.nav-inner > a, .nav-inner summary')]).every(link => link.scrollWidth <= link.clientWidth + 1 && parseFloat(getComputedStyle(link).fontSize) >= 14)`);
                if (role === 'user') await check(`(() => {
                    const nav = document.querySelector('.nav-inner'), links = [...nav.querySelectorAll(':scope > a')], bounds = nav.getBoundingClientRect();
                    return links.length === 5 && links.every((link, index) => {
                        const r = link.getBoundingClientRect(), icon = link.querySelector('svg').getBoundingClientRect(), label = link.querySelector('.nav-label').getBoundingClientRect();
                        return Math.abs(r.width - bounds.width / 5) < 1 && Math.abs(r.left - bounds.left - index * bounds.width / 5) < 1
                            && Math.abs((icon.left + label.right) / 2 - (r.left + r.right) / 2) < 1
                            && Math.abs((icon.top + icon.bottom) / 2 - (label.top + label.bottom) / 2) < 1
                            && label.height <= parseFloat(getComputedStyle(link).lineHeight) + 1;
                    });
                })()`);
            }
            if (role !== 'user') {
                const menu = scope + ' [data-resources-navigation]';
                await evaluate(`window.testMenu=document.querySelector(${JSON.stringify(menu)}); testMenu.querySelector('summary').focus()`);
                await evaluate(`window.beforeMenu = {height:document.querySelector('.user-nav').getBoundingClientRect().height, main:document.querySelector('main').getBoundingClientRect().top + scrollY}`);
                await check('document.activeElement === testMenu.querySelector("summary")');
                await check('testMenu.querySelector("summary").getAttribute("aria-expanded") === "false"');
                await key('Enter', 'Enter', 13);
                await check('testMenu.open');
                await check('testMenu.querySelector("summary").getAttribute("aria-expanded") === "true"');
                await key('Tab', 'Tab', 9);
                await check('document.activeElement === testMenu.querySelector("a")');
                await check('getComputedStyle(document.activeElement).outlineStyle !== "none"');
                await key('Escape', 'Escape', 27);
                await check('!testMenu.open && document.activeElement === testMenu.querySelector("summary")');
                await key('ArrowDown', 'ArrowDown', 40);
                await check('testMenu.open && document.activeElement === testMenu.querySelectorAll("a")[0]');
                await key('ArrowDown', 'ArrowDown', 40);
                await check('document.activeElement === testMenu.querySelectorAll("a")[1]');
                await key('ArrowUp', 'ArrowUp', 38);
                await check('document.activeElement === testMenu.querySelectorAll("a")[0]');
                await key('Escape', 'Escape', 27);
                await pointer(menu + ' summary', mobile);
                await check('testMenu.open');
                await pointer(menu + ' summary', mobile);
                await check('!testMenu.open');
                await pointer(menu + ' summary', mobile);
                await check(`testMenu.querySelector('[aria-current="page"]').href.endsWith('/${route}')`);
                await check('testMenu.classList.contains("active") && testMenu.querySelector("summary").classList.contains("active")');
                await check('(() => { const r=testMenu.querySelector(".resources-navigation-links").getBoundingClientRect(); return r.left >= 0 && r.right <= innerWidth; })()');
                if (!mobile) {
                    await check('document.querySelector(".user-nav").getBoundingClientRect().height === beforeMenu.height && document.querySelector("main").getBoundingClientRect().top + scrollY === beforeMenu.main');
                    await check(`(() => { const panel=testMenu.querySelector('.resources-navigation-links'), r=panel.getBoundingClientRect(), trigger=testMenu.querySelector('summary').getBoundingClientRect();
                        return getComputedStyle(panel).position === 'absolute' && Math.abs(r.top-trigger.bottom) < 1 && Math.abs(r.left-trigger.left) < 1 && panel.contains(document.elementFromPoint(r.left+r.width/2, r.bottom-12)); })()`);
                    if (route === 'equipment' && [1280, 1440].includes(width)) {
                        const screenshot = await command('Page.captureScreenshot', {format: 'png'});
                        fs.writeFileSync(path.join(directory, `${role}-dropdown-${width}.png`), Buffer.from(screenshot.data, 'base64'));
                    }
                } else await check('getComputedStyle(testMenu.querySelector(".resources-navigation-links")).position === "static"');
                await check(`([...testMenu.querySelectorAll('a')]).every(link => {
                    const r=link.getBoundingClientRect(); return r.height >= 44 && r.width <= testMenu.querySelector('.resources-navigation-links').clientWidth;
                })`);
                await evaluate('testMenu.querySelector("a").addEventListener("click", e=>e.preventDefault(), {once:true})');
                await pointer(menu + ' a', mobile);
                await check('!testMenu.open');
                if (mobile) await check('!document.getElementById("mobile-navigation").open');
                else {
                    await pointer(menu + ' summary', false);
                    await pointer('.page-heading h2', false);
                    await check('!testMenu.open');
                    await evaluate('testMenu.querySelector("summary").focus()');
                    await key(' ', 'Space', 32);
                    await check('testMenu.open');
                    for (let tab = 0; tab < 3; tab++) await key('Tab', 'Tab', 9);
                    await check('!testMenu.open && !testMenu.contains(document.activeElement)');
                    // A trigger near the right edge must keep its panel inside the viewport.
                    await evaluate('testMenu.style.left=(innerWidth-testMenu.getBoundingClientRect().right-4)+"px";testMenu.querySelector("summary").click()');
                    await sleep(30);
                    await check('(() => { const r=testMenu.querySelector(".resources-navigation-links").getBoundingClientRect();return r.left>=12 && r.right<=innerWidth-11; })()');
                    await evaluate('testMenu.style.left="";testMenu.open=false;testMenu.querySelector("summary").click()');
                    await command('Emulation.setDeviceMetricsOverride', {width, height: 200, deviceScaleFactor: 1, mobile: false});
                    await evaluate('scrollTo(0,0)');
                    await sleep(30);
                    await check('(() => { const panel=testMenu.querySelector(".resources-navigation-links"),r=panel.getBoundingClientRect();return r.bottom<=innerHeight-11 && panel.scrollHeight>panel.clientHeight; })()');
                    await command('Emulation.setDeviceMetricsOverride', {width, height: 900, deviceScaleFactor: 1, mobile: false});
                    await evaluate('testMenu.open=false');
                }
                await evaluate('document.querySelector("[data-modal-open=add-facility-modal]").click()');
                await check('document.getElementById("add-facility-modal").open');
                await check(`document.querySelector('#add-facility-modal input[name="${route === 'equipment' ? 'total_quantity' : 'capacity'}"]').required`);
                await check(`!document.querySelector('#add-facility-modal input[name="${route === 'equipment' ? 'capacity' : 'total_quantity'}"]')`);
                await key('Escape', 'Escape', 27);
                await evaluate('document.querySelector(".edit-facility-button").click()');
                await check('document.querySelector("dialog[id^=edit-facility-][open]") !== null');
                await key('Escape', 'Escape', 27);
                await evaluate('document.querySelector("[data-modal-open^=view-facility-]").click()');
                await check('document.querySelector("dialog[id^=view-facility-][open]") !== null');
            } else {
                await check(`document.querySelector(${JSON.stringify(scope + ' a[href$="/' + route + '"]')}).classList.contains('active')`);
            }
            passed++;
        }
        assert.deepEqual(exceptions, []);
        console.log(`Passed ${passed} resource-page scenarios: keyboard, mouse, touch, active links, modals, and responsive layout.`);
    } catch (error) { console.error(scenario, error); process.exitCode = 1; }
    finally { clearTimeout(timeout); stop(); }
})();
