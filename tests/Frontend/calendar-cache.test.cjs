const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

test('shared calendar and dashboard always fetch fresh availability and fall back to offline', async () => {
    for (const pathname of ['/calendar?barangay=all', '/dashboard?facility=taft-court']) {
        for (const offline of [false, true]) {
            const listeners = {};
            let fetchCount = 0;
            vm.runInNewContext(fs.readFileSync('public/sw.js', 'utf8'), {
                self: {addEventListener: (type, callback) => { listeners[type] = callback; }}, URL,
                fetch: async () => { fetchCount++; if (offline) throw Error('offline'); return 'fresh'; },
                caches: {match: path => { assert.equal(path, '/offline'); return 'offline page'; }},
            });
            let result;
            listeners.fetch({request: {method: 'GET', mode: 'navigate', url: 'https://ereserve.test' + pathname}, respondWith: response => { result = response; }});
            assert.equal(await result, offline ? 'offline page' : 'fresh');
            assert.equal(fetchCount, 1);
        }
    }
});
