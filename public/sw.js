const CACHE = 'wh-v2';
const ASSETS = [
    '/assets/css/warehouse.css',
    '/assets/js/warehouse.js',
    '/manifest.json',
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
    // نداءات الـ API تمرّ للشبكة مباشرة (التطبيق يتكفّل بالوضع دون اتصال محلياً)
    if (url.pathname.startsWith('/api/')) return;

    const isPage = url.pathname === '/warehouse' || url.pathname.startsWith('/warehouse/');
    const isAsset = url.pathname.startsWith('/assets/') || url.pathname === '/manifest.json';

    // الصفحات: الشبكة أولاً (لجلب CSRF محدّث)، ثم النسخة المخزّنة عند انقطاع الاتصال
    if (isPage) {
        event.respondWith(
            fetch(event.request).then((res) => {
                if (res && res.ok) {
                    const clone = res.clone();
                    caches.open(CACHE).then((c) => c.put(event.request, clone));
                }
                return res;
            }).catch(() =>
                caches.match(event.request).then((cached) => cached || caches.match('/warehouse'))
            )
        );
        return;
    }

    // الملفات الثابتة: النسخة المخزّنة أولاً مع تحديثها بالخلفية
    if (isAsset) {
        event.respondWith(
            caches.match(event.request).then((cached) =>
                cached || fetch(event.request).then((res) => {
                    if (res && res.ok) {
                        const clone = res.clone();
                        caches.open(CACHE).then((c) => c.put(event.request, clone));
                    }
                    return res;
                }).catch(() => cached)
            )
        );
    }
});
