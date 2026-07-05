const CACHE = 'wh-v1';
const ASSETS = [
    '/assets/css/warehouse.css',
    '/assets/js/warehouse.js',
    '/warehouse',
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE).then((cache) => cache.addAll(ASSETS)).then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) =>
            Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k)))
        ).then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', (event) => {
    const url = new URL(event.request.url);
    if (event.request.method !== 'GET') return;
    if (url.pathname.startsWith('/api/')) return;

    if (url.pathname.startsWith('/assets/') || url.pathname.startsWith('/warehouse')) {
        event.respondWith(
            caches.match(event.request).then((cached) =>
                cached || fetch(event.request).then((res) => {
                    if (res.ok) {
                        const clone = res.clone();
                        caches.open(CACHE).then((c) => c.put(event.request, clone));
                    }
                    return res;
                }).catch(() => cached)
            )
        );
    }
});
