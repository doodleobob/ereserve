// Run: node tests/Frontend/password-reset-pwa.cjs
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

async function check() {
    const handlers = {};
    const offlinePage = { offline: true };
    const networkPage = { fromNetwork: true };
    let offline = false;
    let cacheReads = [];
    let fetches = 0;
    const context = {
        URL,
        self: { addEventListener: (event, handler) => { handlers[event] = handler; } },
        fetch: async () => {
            fetches++;
            if (offline) throw Error('No network');
            return networkPage;
        },
        caches: {
            match: async key => { cacheReads.push(key); return offlinePage; },
            open: () => { throw Error('A password-reset request must never open or write to a cache'); },
        },
    };
    vm.runInNewContext(fs.readFileSync('public/sw.js', 'utf8'), context);
    for (const path of ['/login', '/forgot-password', '/reset-password/secret-token?email=user%40example.test', '/reset-password/']) {
        for (const disconnected of [false, true]) {
            offline = disconnected;
            cacheReads = [];
            let response;
            handlers.fetch({ request: { method: 'GET', mode: 'navigate', url: 'https://ereserve.test' + path }, respondWith: value => { response = value; } });
            assert.equal(await response, offline ? offlinePage : networkPage);
            assert.deepEqual(cacheReads, offline ? ['/offline'] : []);
        }
    }
    let intercepted = false;
    handlers.fetch({ request: { method: 'POST', url: 'https://ereserve.test/reset-password' }, respondWith: () => { intercepted = true; } });
    assert.equal(intercepted, false, 'Reset POST must go directly to Laravel');
    assert.equal(fetches, 8);
    console.log('Passed password-reset PWA checks: fresh online requests, safe offline fallback, no cached tokens, POST untouched.');
}
check().catch(error => { console.error(error); process.exitCode = 1; });
