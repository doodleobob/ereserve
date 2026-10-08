const CACHE_NAME = 'ereserve-pwa-v15';
const STATIC_CACHE_URLS = [
    '/offline',
    '/css/app.css',
    '/js/modals.js',
    '/js/facility-management.js',
    '/js/facility-gallery.js',
    '/js/account-management.js',
    '/manifest.json',
    '/favicon.ico'
];

const PRIVATE_PATHS = [
    '/', '/login', '/register', '/forgot-password', '/reset-password', '/two-factor-challenge',
    '/email', '/logout', '/settings/security', '/dashboard', '/calendar', '/analytics',
    '/super-admin', '/admin', '/admins', '/profile', '/reservations', '/official-uses',
    '/official-use-conflicts', '/payments', '/facilities', '/notifications'
];

const pathnameFor = (url) => {
    try {
        return decodeURIComponent(new URL(url).pathname)
            .replace(/\\/g, '/').replace(/\/+/g, '/').replace(/\/+$/, '') || '/';
    } catch {
        return null;
    }
};

const isPrivateUrl = (url) => {
    const pathname = pathnameFor(url);
    return pathname === null || PRIVATE_PATHS.some(path => pathname === path || (path !== '/' && pathname.startsWith(path + '/')));
};

const canCacheResponse = (response) => {
    if (!response || (response.url && isPrivateUrl(response.url))) return false;
    if (response.type === 'opaque') return true;
    return response.ok && !/(?:^|,)\s*(?:private|no-store)\b/i.test(response.headers.get('Cache-Control') || '');
};

// Never search caches belonging to earlier workers, even if an in-flight old
// request recreates one after activation has deleted it.
const matchCurrent = request => caches.match(request, {cacheName: CACHE_NAME});

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME).then((cache) => cache.addAll(STATIC_CACHE_URLS))
    );
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then(cacheNames => Promise.all(cacheNames
            .filter(cacheName => cacheName.startsWith('ereserve-pwa-') && cacheName !== CACHE_NAME)
            .map(cacheName => caches.delete(cacheName))))
            .then(() => caches.open(CACHE_NAME))
            .then(async cache => {
                const requests = await cache.keys();
                await Promise.all(requests.map(async request => {
                    if (isPrivateUrl(request.url)) return cache.delete(request);
                    const response = await cache.match(request);
                    // /offline is an explicitly public, generic precache entry,
                    // even when Laravel supplies its default private HTTP header.
                    if (pathnameFor(request.url) === '/offline' && response
                        && (!response.url || pathnameFor(response.url) === '/offline')) return;
                    if (!canCacheResponse(response)) return cache.delete(request);
                }));
            })
            .then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', (event) => {
    const request = event.request;

    if (request.method !== 'GET') {
        return;
    }

    if (isPrivateUrl(request.url)) {
        // Bypass the browser's HTTP cache as well as CacheStorage. Keep the
        // generic offline page for page requests; notification polling must fail
        // offline instead of receiving an HTML document in place of JSON.
        const network = fetch(request, {cache: 'no-store'});
        const pathname = pathnameFor(request.url);
        event.respondWith(pathname === '/notifications' || pathname?.startsWith('/notifications/')
            ? network : network.catch(() => matchCurrent('/offline')));
        return;
    }

    const cacheResponse = (response) => {
        // A public URL can redirect to login/profile or return private content.
        // CacheStorage does not enforce the response's Cache-Control header.
        if (canCacheResponse(response)) {
            const copy = response.clone();
            event.waitUntil(caches.open(CACHE_NAME).then(cache => cache.put(request, copy)));
        }
        return response;
    };
    const cachedPublicResponse = () => matchCurrent(request)
        .then(response => canCacheResponse(response) ? response : undefined);

    if (request.mode === 'navigate') {
        event.respondWith(
            fetch(request)
                .then(cacheResponse)
                .catch(() => cachedPublicResponse().then(response => response || matchCurrent('/offline')))
        );
        return;
    }

    if (request.destination === 'style') {
        event.respondWith(
            fetch(request)
                .then(cacheResponse)
                .catch(cachedPublicResponse)
        );
        return;
    }

    event.respondWith(
        cachedPublicResponse().then(response => response || fetch(request).then(cacheResponse))
    );
});
