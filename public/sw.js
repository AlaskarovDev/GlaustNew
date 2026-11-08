/*
 * TradeFlow service worker — makes the app installable (PWA) and keeps it usable on a bad connection.
 * Pages are never cached: they hold the company's figures and must always be the server's, behind its login.
 * Only the build's hashed assets (immutable) and the icons are kept; a page that cannot be reached shows offline.html.
 */
const VERSION = 'tf-v1';
const SHELL = ['offline.html', 'icons/icon-192.png', 'icons/icon-512.png', 'favicon.svg'];

self.addEventListener('install', (event) => {
    event.waitUntil(caches.open(VERSION).then((c) => c.addAll(SHELL.map((p) => new URL(p, self.registration.scope)))).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
    event.waitUntil(caches.keys().then((keys) => Promise.all(keys.filter((k) => k !== VERSION).map((k) => caches.delete(k)))).then(() => self.clients.claim()));
});

// the offline page stands in for any page, so its links are made absolute (the app's own folder)
const offline = () => caches.match(new URL('offline.html', self.registration.scope)).then((res) => res
    ? res.text().then((html) => new Response(html.replaceAll('__BASE__', self.registration.scope), { headers: { 'Content-Type': 'text/html; charset=utf-8' } }))
    : Response.error());

self.addEventListener('fetch', (event) => {
    const req = event.request;
    if (req.method !== 'GET') return;
    const url = new URL(req.url);
    if (url.origin !== self.location.origin) return;

    // pages: always the network; offline → the offline page
    if (req.mode === 'navigate') {
        event.respondWith(fetch(req).catch(() => offline()));
        return;
    }
    // hashed build assets and icons: cache first (a new build has new file names)
    if (url.pathname.includes('/build/assets/') || url.pathname.includes('/icons/')) {
        event.respondWith(caches.match(req).then((hit) => hit || fetch(req).then((res) => {
            if (res.ok) {
                const copy = res.clone();
                caches.open(VERSION).then((c) => c.put(req, copy));
            }
            return res;
        })));
    }
});
