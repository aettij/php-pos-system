const CACHE = 'superma-v1';
const PRECACHE = [
    '/',
    '/public/js/app.js',
    '/public/js/api.js',
    '/public/css/app.css',
];

self.addEventListener('install', e => {
    e.waitUntil(
        caches.open(CACHE).then(cache => cache.addAll(PRECACHE))
    );
    self.skipWaiting();
});

self.addEventListener('activate', e => {
    e.waitUntil(
        caches.keys().then(keys =>
            Promise.all(keys.filter(k => k !== CACHE).map(k => caches.delete(k)))
        )
    );
    self.clients.claim();
});

self.addEventListener('fetch', e => {
    // API calls and SSE: network only
    if (e.request.url.includes('/api/')) {
        return;
    }
    // Static assets: cache first
    if (e.request.url.match(/\.(js|css|png|jpg|woff2?)$/)) {
        e.respondWith(
            caches.match(e.request).then(cached => cached || fetch(e.request).then(res => {
                const clone = res.clone();
                caches.open(CACHE).then(cache => cache.put(e.request, clone));
                return res;
            }))
        );
        return;
    }
    // Everything else: network first, fallback to cache
    e.respondWith(
        fetch(e.request).catch(() => caches.match(e.request))
    );
});
