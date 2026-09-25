const {test} = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');

class Element {
    constructor() {
        this.children = []; this.events = {}; this.attributes = {}; this.hidden = false;
    }
    append(...nodes) { nodes.forEach(node => { node.parent = this; this.children.push(node); }); }
    replaceChildren() { this.children = []; }
    setAttribute(name, value) { this.attributes[name] = value; }
    addEventListener(name, callback) { this.events[name] = callback; }
    remove() { this.parent.children = this.parent.children.filter(child => child !== this); }
    focus() { this.focused = true; }
    contains(target) { return target === this || this.children.some(child => child.contains(target)); }
}

const flush = () => new Promise(resolve => setImmediate(resolve));
function setup(count = 1) {
    const selectors = ['.notification-bell', '.notification-panel', '.notification-badge', '[data-notification-list]', '[data-notification-more]', '[data-notification-error]', '.notification-toasts', '[data-read-all]'];
    const nodes = Object.fromEntries(selectors.map(selector => [selector, new Element()]));
    nodes['.notification-panel'].hidden = true;
    const center = new Element();
    center.dataset = {url: '/notifications', readAll: '/notifications/read-all', admin: 'true', user: '1'};
    center.querySelector = selector => nodes[selector];
    Object.values(nodes).forEach(node => center.append(node));
    const document = new Element();
    document.querySelector = selector => selector === '[data-notifications]' ? center : {content: 'csrf'};
    document.createElement = () => new Element();
    const timers = [], intervals = [], calls = [];
    let unread = count;
    const payload = () => ({unread_count: unread, notifications: {data: [{id: 'one', read: unread === 0, data: {event: 'submitted', title: 'New Reservation Request', message: 'Submitted', details: ['Covered Court', '<script>untrusted</script>']}, time: 'now', open_url: '/notifications/one/open'}], next_page_url: null}});
    const context = {
        document, Set, JSON, Error,
        sessionStorage: {getItem: () => null, setItem: () => {}},
        setTimeout: callback => timers.push(callback), setInterval: callback => intervals.push(callback),
        fetch: async (url, options) => { calls.push({url, options}); if (options.method === 'POST') unread = 0; return {ok: true, json: async () => payload()}; },
    };
    vm.runInNewContext(fs.readFileSync('public/js/notifications.js', 'utf8'), context);
    return {nodes, document, timers, intervals, calls};
}

test('polling updates unread badge, renders safe text, and supports open/close', async () => {
    const {nodes, document, calls} = setup(100);
    await flush();
    assert.equal(nodes['.notification-badge'].textContent, '99+');
    assert.equal(nodes['.notification-badge'].hidden, false);
    assert.equal(calls[0].options.cache, 'no-store');
    const card = nodes['[data-notification-list]'].children[0];
    assert.equal(card.className, 'notification-item unread');
    assert.equal(card.children[3].textContent, '<script>untrusted</script>');
    const form = card.children.at(-1);
    assert.equal(form.method, 'POST');
    assert.equal(form.action, '/notifications/one/open');
    assert.equal(form.children[0].value, 'csrf');
    nodes['.notification-bell'].events.click();
    assert.equal(nodes['.notification-panel'].hidden, false);
    document.events.keydown({key: 'Escape'});
    assert.equal(nodes['.notification-panel'].hidden, true);
    assert.equal(nodes['.notification-bell'].focused, true);
    await flush();
});

test('admin toast is dismissible, expires, and does not repeat on polling', async () => {
    const {nodes, intervals, timers} = setup();
    await flush();
    const toasts = nodes['.notification-toasts'];
    assert.equal(toasts.children.length, 1);
    toasts.children[0].children[0].events.click();
    assert.equal(toasts.children.length, 0);
    intervals[0]();
    await flush();
    assert.equal(toasts.children.length, 0);
    timers[0]();
    const another = setup();
    await flush();
    another.timers[0]();
    assert.equal(another.nodes['.notification-toasts'].children.length, 0);
});

test('mark all read hides badge and switches read appearance', async () => {
    const {nodes, calls} = setup();
    await flush();
    await nodes['[data-read-all]'].events.click();
    assert.equal(nodes['.notification-badge'].hidden, true);
    assert.equal(nodes['[data-notification-list]'].children[0].className, 'notification-item');
    assert.equal(calls.find(call => call.options.method === 'POST').options.headers['X-CSRF-TOKEN'], 'csrf');
});

test('service worker bypasses cache for notification polling', async () => {
    const listeners = {};
    let fetched = false;
    vm.runInNewContext(fs.readFileSync('public/sw.js', 'utf8'), {
        self: {addEventListener: (type, callback) => { listeners[type] = callback; }}, URL,
        fetch: async () => { fetched = true; return 'fresh'; },
        caches: {match: () => { throw new Error('Private notifications must not be cached'); }},
    });
    let response;
    listeners.fetch({request: {method: 'GET', url: 'https://ereserve.test/notifications?page=1'}, respondWith: value => { response = value; }});
    assert.equal(await response, 'fresh');
    assert.equal(fetched, true);
});
