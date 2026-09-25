const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');

(async () => {
    const handlers = {};
    const lookedUp = [];
    let offline = false;
    vm.runInNewContext(fs.readFileSync('public/sw.js', 'utf8'), {
        URL,
        self: { addEventListener: (name, handler) => { handlers[name] = handler; } },
        fetch: async () => { if (offline) throw Error('Offline'); return 'fresh dashboard'; },
        caches: {
            match: async key => { lookedUp.push(key); return 'offline page'; },
            open: () => { throw Error('Private dashboard must never be cached'); },
        },
    });
    for (const url of ['https://ereserve.test/dashboard', 'https://ereserve.test/dashboard?analytics_period=7', 'https://ereserve.test/dashboard/', 'https://ereserve.test/analytics', 'https://ereserve.test/analytics?analytics_period=7', 'https://ereserve.test/analytics/']) {
        for (const disconnected of [false, true]) {
            offline = disconnected;
            let response;
            handlers.fetch({ request: { method: 'GET', mode: 'navigate', url }, respondWith: value => { response = value; } });
            assert.equal(await response, offline ? 'offline page' : 'fresh dashboard');
        }
    }
    assert.deepEqual(lookedUp, Array(6).fill('/offline'));
    console.log('Passed 12 dashboard/analytics cache checks: online responses are fresh; offline never serves account analytics.');
})();
