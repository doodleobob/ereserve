const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const origin = 'https://ereserve.test';
const currentCache = 'ereserve-pwa-v15';
const worker = fs.readFileSync('public/sw.js', 'utf8');
const key = input => new URL(typeof input === 'string' ? input : input.url, origin).href;
const response = (pathname, body = 'public', cacheControl = '', overrides = {}) => ({
    ok: true, type: 'basic', url: key(pathname), body,
    headers: new Headers(cacheControl ? {'Cache-Control': cacheControl} : {}),
    clone() { return response(pathname, body, cacheControl, overrides); }, ...overrides,
});

function setup(seed = {}) {
    const stores = new Map(Object.entries(seed).map(([name, entries]) => [name,
        new Map(Object.entries(entries).map(([url, value]) => [key(url), value]))]));
    const handlers = {}, reads = [], writes = [], fetches = [], deleted = [], precached = [], lifecycleOrder = [];
    let offline = false, nextResponse, claimed = false, skipped = false;
    const cache = name => {
        if (!stores.has(name)) stores.set(name, new Map());
        const store = stores.get(name);
        return {
            keys: async () => [...store.keys()].map(url => ({url})),
            match: async input => store.get(key(input)),
            delete: async input => { lifecycleOrder.push('entry:' + key(input)); return store.delete(key(input)); },
            put: async (input, value) => { writes.push({name, url: key(input)}); store.set(key(input), value); },
            addAll: async urls => { precached.push(...urls); urls.forEach(url => store.set(key(url), response(url))); },
        };
    };
    vm.runInNewContext(worker, {
        URL,
        self: {
            addEventListener: (type, handler) => { handlers[type] = handler; },
            skipWaiting: () => { skipped = true; },
            clients: {claim: async () => { lifecycleOrder.push('claim'); claimed = true; }},
        },
        fetch: async (request, options) => {
            fetches.push({request, options});
            if (offline) throw Error('Offline');
            return nextResponse || response(request.url, 'fresh');
        },
        caches: {
            keys: async () => [...stores.keys()],
            open: async name => cache(name),
            delete: async name => { lifecycleOrder.push('cache:' + name); deleted.push(name); return stores.delete(name); },
            match: async (input, options) => {
                reads.push({url: key(input), name: options?.cacheName});
                if (options?.cacheName) return stores.get(options.cacheName)?.get(key(input));
                for (const store of stores.values()) if (store.has(key(input))) return store.get(key(input));
            },
        },
    });
    return {
        stores, handlers, reads, writes, fetches, deleted, precached, lifecycleOrder,
        set offline(value) { offline = value; }, set nextResponse(value) { nextResponse = value; },
        get claimed() { return claimed; }, get skipped() { return skipped; },
        async lifecycle(type) {
            const pending = [];
            handlers[type]({waitUntil: promise => pending.push(promise)});
            await Promise.all(pending);
        },
        async request(pathname, properties = {}) {
            let pendingResponse;
            const pendingWrites = [];
            const request = {method: 'GET', mode: 'navigate', url: key(pathname), ...properties};
            handlers.fetch({request, respondWith: promise => { pendingResponse = promise; },
                waitUntil: promise => pendingWrites.push(promise)});
            const value = await pendingResponse;
            await Promise.all(pendingWrites);
            return value;
        },
    };
}

test('every current GET and redirect application route bypasses caches online and offline', async () => {
    const routes = fs.readFileSync('routes/web.php', 'utf8');
    const paths = [...routes.matchAll(/Route::(?:get|redirect)\('([^']+)'/g)]
        .map(match => match[1].replace(/\{[^}]+\}/g, 'regression-id'));
    assert.ok(paths.includes('/super-admin/analytics'));
    assert.ok(paths.includes('/two-factor-challenge'));
    assert.ok(paths.includes('/settings/security'));
    assert.ok(paths.includes('/'));
    for (const pathname of paths) {
        for (const offline of [false, true]) {
            const offlinePage = response('/offline', 'generic offline');
            const subject = setup({[currentCache]: {'/offline': offlinePage, [pathname]: response(pathname, 'private stale')},
                'ereserve-pwa-v14': {[pathname]: response(pathname, 'private legacy')}});
            subject.offline = offline;
            if (offline && pathname === '/notifications') {
                await assert.rejects(subject.request(pathname), /Offline/);
                assert.equal(subject.reads.length, 0);
            } else {
                const result = await subject.request(pathname);
                assert.equal(result.body, offline ? 'generic offline' : 'fresh', pathname);
                assert.ok(subject.reads.every(read => read.url === key('/offline') && read.name === currentCache));
            }
            assert.equal(subject.fetches.length, 1);
            assert.equal(subject.fetches[0].options.cache, 'no-store', pathname);
            assert.equal(subject.writes.length, 0, pathname);
        }
    }
});

test('sensitive namespaces, descendants, queries, slash and encoded variants never cache', async () => {
    const paths = ['/super-admin/analytics?analytics_period=all', '/super-admin/analytics/', '/super-admin/future-module',
        '/two-factor-challenge/', '/two-factor-challenge/resend', '/settings/security/two-factor',
        '/email/verify/obsolete', '/admin/residents/42?secret=1', '/admin/future-module', '/notifications/',
        '/notifications/history', '/logout', '/official-use-conflicts/42/decision',
        '/%73uper-admin/analytics', '/super-admin%2Fanalytics', '/settings%2Fsecurity',
        '/%74wo-factor-challenge', '/super-admin//analytics', '/super-admin%5Canalytics', '/bad%ZZpath'];
    for (const pathname of paths) {
        const subject = setup({[currentCache]: {'/offline': response('/offline', 'generic offline')}});
        await subject.request(pathname);
        assert.equal(subject.fetches[0].options.cache, 'no-store', pathname);
        assert.equal(subject.writes.length, 0, pathname);
        subject.offline = true;
        if (pathname.startsWith('/notifications')) await assert.rejects(subject.request(pathname), /Offline/);
        else assert.equal((await subject.request(pathname)).body, 'generic offline', pathname);
        assert.ok(subject.reads.every(read => read.url === key('/offline') && read.name === currentCache));
    }
});

test('activation removes legacy eReserve caches and contaminated current entries before claiming clients', async () => {
    const offlinePage = response('/offline', 'generic offline', 'private');
    const publicAsset = response('/css/app.css', 'stylesheet');
    const subject = setup({
        'ereserve-pwa-v13': {'/login': response('/login', 'old login')},
        'ereserve-pwa-v14': {'/super-admin/analytics': response('/super-admin/analytics', 'old analytics'),
            '/two-factor-challenge': response('/two-factor-challenge', 'old challenge')},
        [currentCache]: {'/super-admin/analytics/': response('/super-admin/analytics', 'stale analytics'),
            '/%74wo-factor-challenge': response('/two-factor-challenge', 'stale challenge'),
            '/help-alias': response('/profile', 'redirected private'),
            '/marked-private': response('/marked-private', 'private', 'private, max-age=3600'),
            '/offline': offlinePage, '/css/app.css': publicAsset},
        'another-app-cache': {'/public-help': response('/public-help', 'unrelated')},
    });
    await subject.lifecycle('activate');
    assert.deepEqual(subject.deleted.sort(), ['ereserve-pwa-v13', 'ereserve-pwa-v14']);
    assert.equal(subject.claimed, true);
    assert.equal(subject.lifecycleOrder.at(-1), 'claim', 'Cleanup must finish before the new worker claims clients');
    assert.equal(subject.stores.has('another-app-cache'), true);
    assert.deepEqual([...subject.stores.get(currentCache).keys()].sort(), [key('/css/app.css'), key('/offline')].sort());
});

test('non-navigation sensitive GETs also bypass cached responses and HTTP cache preferences', async () => {
    for (const pathname of ['/super-admin/analytics', '/two-factor-challenge', '/settings/security', '/payments/export?format=pdf']) {
        for (const mode of ['same-origin', 'cors']) {
            const subject = setup({[currentCache]: {'/offline': response('/offline', 'generic offline'),
                [pathname]: response(pathname, 'private stale')}});
            assert.equal((await subject.request(pathname, {mode, cache: 'force-cache'})).body, 'fresh');
            subject.offline = true;
            assert.equal((await subject.request(pathname, {mode, cache: 'force-cache'})).body, 'generic offline');
            assert.ok(subject.fetches.every(call => call.options.cache === 'no-store'));
            assert.equal(subject.writes.length, 0);
            assert.ok(subject.reads.every(read => read.url === key('/offline') && read.name === currentCache));
        }
    }
});

test('new worker never reads a legacy cache even if an old in-flight request recreates it', async () => {
    const subject = setup({[currentCache]: {'/offline': response('/offline', 'generic offline')}});
    await subject.lifecycle('activate');
    subject.stores.set('ereserve-pwa-v14', new Map([[key('/public-help'), response('/public-help', 'private old alias')]]));
    subject.offline = true;
    assert.equal((await subject.request('/public-help')).body, 'generic offline');
    assert.ok(subject.reads.every(read => read.name === currentCache));
});

test('private/no-store responses and redirects to sensitive routes are neither cached nor replayed', async () => {
    const values = [response('/profile', 'private redirect', '', {redirected: true}),
        response('/two-factor-challenge', 'challenge redirect', '', {redirected: true}),
        response('/help', 'private body', 'PRIVATE, max-age=3600'),
        response('/help', 'secret body', 'no-store'), response('/help', 'forbidden', '', {ok: false})];
    for (const value of values) {
        const subject = setup({[currentCache]: {'/offline': response('/offline', 'generic offline'), '/help': value}});
        subject.nextResponse = value;
        assert.equal(await subject.request('/help'), value);
        assert.equal(subject.writes.length, 0);
        subject.offline = true;
        assert.equal((await subject.request('/help')).body, 'generic offline');
    }
});

test('install keeps public precaching and a generic offline fallback', async () => {
    const subject = setup();
    await subject.lifecycle('install');
    assert.equal(subject.skipped, true);
    assert.deepEqual(subject.precached, ['/offline', '/css/app.css', '/js/modals.js', '/js/facility-management.js',
        '/js/facility-gallery.js', '/js/account-management.js', '/manifest.json', '/favicon.ico']);
    subject.offline = true;
    assert.equal((await subject.request('/two-factor-challenge')).body, 'public');
    assert.equal((await subject.request('/offline')).body, 'public');
});

test('public navigation stays network-first and public assets retain offline caching', async () => {
    const subject = setup({[currentCache]: {'/offline': response('/offline', 'generic offline')}});
    assert.equal((await subject.request('/public-help')).body, 'fresh');
    assert.equal(subject.fetches[0].options, undefined);
    assert.equal(subject.writes.length, 1);
    subject.offline = true;
    assert.equal((await subject.request('/public-help')).body, 'fresh');
    subject.offline = false;
    await subject.request('/css/app.css?v=123', {mode: 'no-cors', destination: 'style'});
    subject.offline = true;
    assert.equal((await subject.request('/css/app.css?v=123', {mode: 'no-cors', destination: 'style'})).body, 'fresh');
    subject.offline = false;
    await subject.request('/js/page-filters.js?v=123', {mode: 'no-cors', destination: 'script'});
    subject.offline = true;
    assert.equal((await subject.request('/js/page-filters.js?v=123', {mode: 'no-cors', destination: 'script'})).body, 'fresh');
    assert.ok(subject.reads.every(read => read.name === currentCache));
});

test('public opaque assets remain cacheable and route prefix lookalikes remain public', async () => {
    const subject = setup();
    subject.nextResponse = response('https://images.example.test/photo.png', 'opaque', '', {type: 'opaque', ok: false});
    await subject.request('https://images.example.test/photo.png', {mode: 'no-cors', destination: 'image'});
    assert.equal(subject.writes.length, 1);
    subject.nextResponse = undefined;
    for (const pathname of ['/analytics-guide', '/super-admin-guide', '/two-factor-challenge-help', '/admin-guide']) {
        await subject.request(pathname);
        assert.equal(subject.fetches.at(-1).options, undefined);
        assert.equal(subject.writes.at(-1).url, key(pathname));
    }
});

test('mutating requests still go directly to the network without worker interception', async () => {
    const subject = setup();
    for (const method of ['POST', 'PATCH', 'PUT', 'DELETE']) {
        assert.equal(await subject.request('/two-factor-challenge', {method}), undefined);
        assert.equal(await subject.request('/reservations/42/payment', {method}), undefined);
    }
    assert.equal(subject.fetches.length, 0);
    assert.equal(subject.reads.length, 0);
    assert.equal(subject.writes.length, 0);
});
