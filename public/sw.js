const CACHE = 'vv-v13';
const ASSETS = ['/', '/css/app.css', '/js/app.js', '/manifest.json'];

// Web Share Target hand-off: the shared file is parked here between the
// POST /share-target navigation and the redirected page load that picks
// it up (see consumeSharedFile() in app.js). Kept out of the cache cleanup.
const SHARE_CACHE = 'vv-share';
const SHARE_KEY = '/share-target/file';

self.addEventListener('install', (e) => {
    e.waitUntil(caches.open(CACHE).then((c) => c.addAll(ASSETS)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (e) => {
    const keep = new Set([CACHE, SHARE_CACHE]);
    e.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(keys.filter((k) => !keep.has(k)).map((k) => caches.delete(k))))
            .then(() => self.clients.claim())
    );
});

// The "microphone went silent" notification (notifyMicSilent() in app.js):
// tapping it brings the recorder back.
self.addEventListener('notificationclick', (e) => {
    e.notification.close();
    e.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true })
        .then((list) => (list.length ? list[0].focus() : self.clients.openWindow('/'))));
});

self.addEventListener('fetch', (e) => {
    const url = new URL(e.request.url);

    if (e.request.method === 'POST' && url.pathname === '/share-target') {
        e.respondWith((async () => {
            try {
                const fd = await e.request.formData();
                const file = fd.get('audio');
                if (!file || !file.size) return Response.redirect('/?share-target=unavailable', 303);
                const cache = await caches.open(SHARE_CACHE);
                // Store first, then redirect, so the page never races the file.
                await cache.put(SHARE_KEY, new Response(file, {
                    headers: {
                        'Content-Type': file.type || 'application/octet-stream',
                        'X-File-Name': encodeURIComponent(file.name || ''),
                    },
                }));
                return Response.redirect('/?share-target=1', 303);
            } catch (_) {
                // Never let this reject: Chrome would show a network error page.
                return Response.redirect('/?share-target=unavailable', 303);
            }
        })());
        return;
    }

    if (e.request.method !== 'GET') return;
    e.respondWith(
        fetch(e.request)
            .then((res) => {
                // Skip query-string URLs (e.g. /?share-target=1) so they don't
                // linger in the runtime cache.
                if (!url.search) {
                    const copy = res.clone();
                    caches.open(CACHE).then((c) => c.put(e.request, copy));
                }
                return res;
            })
            .catch(() => caches.match(e.request))
    );
});
