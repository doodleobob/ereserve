// Native Chrome input checks against HTML rendered by AllSchedulesPresentationTest.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const http = require('node:http');
const {spawn, spawnSync} = require('node:child_process');

const directory = path.resolve(process.env.CALENDAR_RENDER_DIR || 'storage/app/calendar-default-check');
// Serve the assets referenced by Blade instead of injecting them: missing role-specific
// links must fail this check rather than being repaired by the test harness.
const assets = new Map([
    ...['app', 'resident-booking', 'all-schedules', 'management-tables'].map(name => ['/css/' + name + '.css', 'text/css']),
    ...['page-filters', 'all-schedules'].map(name => ['/js/' + name + '.js', 'text/javascript']),
]);
const pause = ms => new Promise(resolve => setTimeout(resolve, ms));

(async () => {
    let browser, socket, server;
    const pending = new Map();
    let id = 0;
    try {
        server = http.createServer((req, res) => {
            try {
                const url = new URL(req.url, 'http://localhost');
                if (assets.has(url.pathname)) {
                    res.writeHead(200, {'Content-Type': assets.get(url.pathname), 'Cache-Control': 'no-store'});
                    res.end(fs.readFileSync(path.join('public', url.pathname.slice(1))));
                    return;
                }
                const role = url.pathname.split('/')[1];
                if (!['user', 'admin', 'super_admin'].includes(role)) { res.writeHead(404); res.end(); return; }
                const eventCount = url.searchParams.get('count');
                const variant = url.searchParams.get('date') === '2026-10-16' ? 'date' : url.searchParams.get('type') === 'equipment' ? 'filter'
                    : ['0', '1', '3', '5', '20'].includes(eventCount) ? eventCount : '50';
                let html = fs.readFileSync(path.join(directory, role + '-' + variant + '.html'), 'utf8');
                const origin = new URL(html.match(/action="([^"]+\/calendar)"/)[1]).origin;
                html = html
                    .replace(/<link\b[^>]*>/g, tag => {
                        const href = tag.match(/href="([^"]+)"/)?.[1];
                        const asset = href && new URL(href, origin);
                        return asset && assets.has(asset.pathname) ? tag.replace(href, asset.pathname + asset.search) : '';
                    })
                    .replace(/<script\b[^>]*>[\s\S]*?<\/script>/g, tag => {
                        const src = tag.match(/src="([^"]+)"/)?.[1];
                        const asset = src && new URL(src, origin);
                        return asset && assets.has(asset.pathname) ? tag.replace(src, asset.pathname + asset.search) : '';
                    })
                    .replaceAll(origin, 'http://127.0.0.1:' + server.address().port + '/' + role);
                res.writeHead(200, {'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store'});
                res.end(html);
            } catch (error) { res.writeHead(500); res.end(error.message); }
        });
        await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
        const profile = path.join(directory, 'scroll-chrome-' + process.pid);
        browser = spawn('C:/Program Files/Google/Chrome/Application/chrome.exe', [
            '--headless', '--disable-gpu', '--no-first-run', '--no-default-browser-check',
            '--remote-debugging-port=0', '--user-data-dir=' + profile, 'about:blank',
        ], {windowsHide: true, stdio: 'ignore'});
        let launchError;
        browser.on('error', error => { launchError = error; });
        const portFile = path.join(profile, 'DevToolsActivePort');
        for (let attempt = 0; !fs.existsSync(portFile) && attempt < 100; attempt++) {
            if (launchError) throw launchError;
            await pause(100);
        }
        const port = fs.readFileSync(portFile, 'utf8').split('\n')[0];
        const tabs = await (await fetch('http://127.0.0.1:' + port + '/json/list')).json();
        socket = new WebSocket(tabs.find(tab => tab.type === 'page').webSocketDebuggerUrl);
        await new Promise((resolve, reject) => { socket.addEventListener('open', resolve, {once: true}); socket.addEventListener('error', reject, {once: true}); });
        socket.addEventListener('message', event => {
            const message = JSON.parse(event.data), request = pending.get(message.id);
            if (request) {
                pending.delete(message.id);
                clearTimeout(request.timer);
                message.error ? request.reject(Error(JSON.stringify(message.error))) : request.resolve(message.result);
            }
        });
        const send = (method, params = {}) => new Promise((resolve, reject) => {
            const requestId = ++id;
            const timer = setTimeout(() => { pending.delete(requestId); reject(Error('Chrome timeout: ' + method)); }, 10000);
            pending.set(requestId, {resolve, reject, timer});
            socket.send(JSON.stringify({id: requestId, method, params}));
        });
        const evaluate = async expression => {
            const result = await send('Runtime.evaluate', {expression, returnByValue: true, awaitPromise: true});
            if (result.exceptionDetails) throw Error(JSON.stringify(result.exceptionDetails));
            return result.result.value;
        };
        const waitFor = async expression => {
            for (let attempt = 0; attempt < 100; attempt++) {
                try { if (await evaluate(expression)) return; } catch {}
                await pause(50);
            }
            throw Error('Page did not reach expected state: ' + expression);
        };
        await send('Page.enable');
        const results = [], overflowResults = [];
        for (const role of ['user', 'admin', 'super_admin']) {
            for (const mobile of [false, true]) {
                await send('Emulation.setDeviceMetricsOverride', {width: mobile ? 390 : 1440, height: 900, deviceScaleFactor: 1, mobile});
                await send('Emulation.setTouchEmulationEnabled', {enabled: mobile, maxTouchPoints: 1});
                const url = 'http://127.0.0.1:' + server.address().port + '/' + role + '/calendar';
                for (const count of [0, 1, 3, 5, 20]) {
                    await send('Page.navigate', {url: url + '?count=' + count});
                    await waitFor('document.readyState === "complete" && new URL(location.href).searchParams.get("count") === "' + count + '" && document.querySelector("#schedule-heading") && document.querySelectorAll("[data-event-type]").length === ' + count);
                    if (count === 0) {
                        assert.equal(await evaluate('document.querySelector("[data-schedule-list]")'), null, role + ': empty date has a scroll container');
                        overflowResults.push({role, mobile, count});
                        continue;
                    }
                    const metrics = await evaluate(`(() => {
                        const list = document.querySelector('[data-schedule-list]'), style = getComputedStyle(list);
                        const heading = document.querySelector('#schedule-heading'), summary = document.querySelector('#schedule-summary');
                        const headingTop = heading.getBoundingClientRect().top, summaryTop = summary.getBoundingClientRect().top;
                        list.scrollTop = list.scrollHeight;
                        return {height: list.clientHeight, content: list.scrollHeight, top: list.scrollTop,
                            horizontalOverflow: list.scrollWidth > list.clientWidth,
                            lastVisible: [...list.querySelectorAll('.schedule-slot')].at(-1).getBoundingClientRect().bottom <= list.getBoundingClientRect().bottom + 1,
                            headingStationary: heading.getBoundingClientRect().top === headingTop && !list.contains(heading),
                            summaryStationary: summary.getBoundingClientRect().top === summaryTop && !list.contains(summary),
                            maxHeight: style.maxHeight, overflowY: style.overflowY};
                    })()`);
                    const context = role + ' ' + (mobile ? 'mobile' : 'desktop') + ' ' + count + ' events';
                    assert.equal(metrics.maxHeight, mobile ? '400px' : '500px', context + ': missing maximum height');
                    assert.equal(metrics.overflowY, 'auto', context + ': missing overflow rule');
                    assert.equal(metrics.top > 0, metrics.content > metrics.height, context + ': scrolling differs from actual overflow');
                    assert.ok(metrics.lastVisible && metrics.headingStationary && metrics.summaryStationary, context + ': clipped event or scrolling heading/summary');
                    assert.equal(metrics.horizontalOverflow, false, context + ': horizontal overflow');
                    assert.equal(metrics.top + metrics.height, metrics.content, context + ': cannot reach list end');
                    if (count === 1) {
                        assert.equal(metrics.top, 0, context + ': short list scrolls unnecessarily');
                        assert.ok(metrics.height < 250, context + ': short list has unnecessary empty space');
                    } else if (count >= 20) {
                        assert.ok(metrics.content > metrics.height && metrics.top > 0, context + ': long list cannot scroll');
                    }
                    overflowResults.push({role, mobile, count, ...metrics});
                }
                await send('Page.navigate', {url});
                await waitFor('document.readyState === "complete" && document.querySelectorAll("[data-event-type]").length === 50');
                await evaluate('document.querySelector("[data-schedule-list]").scrollIntoView({block:"center"}); document.querySelector("[data-schedule-list]").focus({preventScroll:true})');
                const before = await evaluate('({heading:document.querySelector("#schedule-heading").getBoundingClientRect().top,calendar:document.querySelector(".calendar-card").getBoundingClientRect().top})');
                if (mobile) {
                    const point = await evaluate('(() => {const r=document.querySelector("[data-schedule-list]").getBoundingClientRect();return {x:r.left+r.width/2,y:r.top+r.height/2};})()');
                    await send('Input.dispatchTouchEvent', {type: 'touchStart', touchPoints: [{...point, id: 1}]});
                    for (let step = 1; step <= 10; step++) {
                        await send('Input.dispatchTouchEvent', {type: 'touchMove', touchPoints: [{x: point.x, y: point.y - step * 15, id: 1}]});
                        await pause(25);
                    }
                    await send('Input.dispatchTouchEvent', {type: 'touchEnd', touchPoints: []});
                } else {
                    await send('Input.dispatchKeyEvent', {type: 'keyDown', key: 'PageDown', code: 'PageDown', windowsVirtualKeyCode: 34});
                    await send('Input.dispatchKeyEvent', {type: 'keyUp', key: 'PageDown', code: 'PageDown', windowsVirtualKeyCode: 34});
                }
                await waitFor('document.querySelector("[data-schedule-list]").scrollTop > 0');
                const after = await evaluate('({heading:document.querySelector("#schedule-heading").getBoundingClientRect().top,calendar:document.querySelector(".calendar-card").getBoundingClientRect().top})');
                assert.ok(Math.abs(before.heading - after.heading) < 1, 'Panel heading moved during inner scroll');
                assert.ok(Math.abs(before.calendar - after.calendar) < 1, 'Calendar moved during inner scroll');
                if (role === 'user') {
                    const screenshot = await send('Page.captureScreenshot', {format: 'png'});
                    fs.writeFileSync(path.join(directory, mobile ? 'all-schedules-mobile.png' : 'all-schedules-desktop.png'), Buffer.from(screenshot.data, 'base64'));
                }
                await send('Input.dispatchKeyEvent', {type: 'keyDown', key: 'Tab', code: 'Tab', windowsVirtualKeyCode: 9});
                await send('Input.dispatchKeyEvent', {type: 'keyUp', key: 'Tab', code: 'Tab', windowsVirtualKeyCode: 9});
                assert.ok(await evaluate('document.activeElement !== document.querySelector("[data-schedule-list]")'), 'Keyboard focus trapped in schedule list');

                await evaluate('document.querySelector("[data-schedule-list]").scrollTop=1000; const type=document.querySelector("#resource-type"); type.value="equipment"; type.dispatchEvent(new Event("change"))');
                await waitFor('document.readyState === "complete" && location.search.includes("type=equipment") && document.querySelectorAll("[data-event-type]").length===30');
                assert.equal(await evaluate('document.querySelector("[data-schedule-list]").scrollTop'), 0, 'Filter navigation did not reset scroll');
                await evaluate('document.querySelector("[data-schedule-list]").scrollTop=1000; [...document.querySelectorAll("a.calendar-day")].find(link => new URL(link.href).searchParams.get("date") === "2026-10-16").click()');
                await waitFor('document.readyState === "complete" && document.querySelector("#schedule-heading")?.textContent === "Schedules for October 16, 2026"');
                assert.equal(await evaluate('document.querySelector("[data-schedule-list]")'), null, 'Empty date retained a scrolling list');
                await evaluate('[...document.querySelectorAll("a.calendar-day")].find(link => new URL(link.href).searchParams.get("date") === "2026-10-15").click()');
                await waitFor('document.readyState === "complete" && document.querySelector("#schedule-heading")?.textContent === "Schedules for October 15, 2026" && document.querySelectorAll("[data-event-type]").length===30');
                assert.equal(await evaluate('document.querySelector("[data-schedule-list]").scrollTop'), 0, 'Date navigation restored a stale scroll position');
                results.push({role, input: mobile ? 'touch' : 'keyboard', passed: true});
            }
        }
        console.log('Calendar native input, filter/date navigation, and scroll reset checks passed: ' + results.length);
        console.log('Linked-asset short/long list checks passed: ' + overflowResults.length);
        console.log('Same three-event dataset: ' + JSON.stringify(overflowResults.filter(result => result.count === 3)));
    } finally {
        for (const request of pending.values()) clearTimeout(request.timer);
        if (socket) socket.close();
        if (browser?.pid) spawnSync('taskkill', ['/PID', String(browser.pid), '/T', '/F'], {windowsHide: true, timeout: 5000, stdio: 'ignore'});
        // Windows may retain the child handle after Chrome has stopped.
        browser?.unref();
        if (server) { server.closeAllConnections(); server.close(); }
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
