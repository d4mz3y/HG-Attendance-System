const CACHE = 'hg-attendance-kiosk-v2';
const SHELL = ['/scan', '/logo.png', '/favicon.png', '/manifest.webmanifest', '/build/manifest.json'];

async function productionAssets() {
    const response = await fetch('/build/manifest.json', { cache: 'no-store' });
    if (!response.ok) throw new Error('The production asset manifest is unavailable.');
    const manifest = await response.json();
    const paths = new Set();
    Object.values(manifest).forEach((entry) => {
        if (entry.file) paths.add(`/build/${entry.file}`);
        (entry.css ?? []).forEach((file) => paths.add(`/build/${file}`));
        (entry.assets ?? []).forEach((file) => paths.add(`/build/${file}`));
    });
    return [...paths];
}

self.addEventListener('install', (event) => {
    event.waitUntil((async () => {
        const cache = await caches.open(CACHE);
        const assets = await productionAssets();
        await cache.addAll([...SHELL, ...assets]);
    })());
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(caches.keys().then((keys) => Promise.all(keys.filter((key) => key !== CACHE).map((key) => caches.delete(key)))));
    self.clients.claim();
});

self.addEventListener('fetch', (event) => {
    const request = event.request;
    const url = new URL(request.url);
    const isKioskNavigation = request.mode === 'navigate' && url.pathname === '/scan';
    const isKioskAsset = url.pathname.startsWith('/build/') || ['/logo.png', '/favicon.png', '/manifest.webmanifest'].includes(url.pathname);
    if (request.method !== 'GET' || url.origin !== self.location.origin || (!isKioskNavigation && !isKioskAsset)) return;

    event.respondWith(fetch(request).then((response) => {
        if (response.ok) caches.open(CACHE).then((cache) => cache.put(request, response.clone()));
        return response;
    }).catch(async () => {
        const cached = await caches.match(request);
        if (cached) return cached;
        if (isKioskNavigation) return caches.match('/scan');
        throw new Error('Offline resource unavailable.');
    }));
});
