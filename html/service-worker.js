// Service Worker — APR/PT
// Estratégia: NETWORK-FIRST (busca a versão mais nova primeiro; cai no cache quando offline).
// Assim ninguém fica preso a uma versão antiga da interface.
const CACHE = 'aprpt-shell-v34';
const SHELL = [
    '/',
    '/assets/css/style.css',
    '/assets/js/offline.js',
    '/assets/js/api.js',
    '/assets/js/app.js',
    '/manifest.webmanifest',
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE).then((c) => c.addAll(SHELL)).then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
            .then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', (event) => {
    const { request } = event;
    const url = new URL(request.url);

    // Não interceptar a API nem métodos diferentes de GET
    if (request.method !== 'GET' || url.pathname.startsWith('/api/')) {
        return;
    }

    // Network-first: online sempre pega a versão atual; offline usa o cache
    const network = fetch(request).then((response) => {
        if (response && response.ok && url.origin === self.location.origin) {
            const copy = response.clone();
            caches.open(CACHE).then((c) => c.put(request, copy)).catch(() => {});
        }
        return response;
    });

    event.respondWith(
        network.catch(() => caches.match(request).then((cached) => cached || caches.match('/')))
    );
});
