// Service Worker — cache do app shell para funcionamento offline.
// As chamadas /api/* são sempre de rede; o offline de dados será tratado com IndexedDB (fases seguintes).
const CACHE = 'aprpt-shell-v1';
const SHELL = [
    '/',
    '/assets/css/style.css',
    '/assets/js/api.js',
    '/assets/js/app.js',
    '/manifest.webmanifest',
];

self.addEventListener('install', (event) => {
    event.waitUntil(caches.open(CACHE).then((c) => c.addAll(SHELL)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) =>
            Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k)))
        ).then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', (event) => {
    const { request } = event;
    const url = new URL(request.url);

    // Não interceptar a API nem requisições não-GET
    if (request.method !== 'GET' || url.pathname.startsWith('/api/')) {
        return;
    }

    event.respondWith(
        caches.match(request).then((cached) => cached || fetch(request).then((response) => {
            const copy = response.clone();
            caches.open(CACHE).then((c) => c.put(request, copy)).catch(() => {});
            return response;
        }).catch(() => cached))
    );
});
