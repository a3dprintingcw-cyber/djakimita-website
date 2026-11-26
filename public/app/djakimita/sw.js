// Small service worker so phones can install the Dj'aki mi ta! web app.
// It keeps the app files for offline start; account data always comes fresh from the server.
const CACHE = 'djk-app-v1';
self.addEventListener('install', (e) => { self.skipWaiting(); });
self.addEventListener('activate', (e) => {
  e.waitUntil(caches.keys().then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k)))).then(() => self.clients.claim()));
});
self.addEventListener('fetch', (e) => {
  const url = new URL(e.request.url);
  // Only the app's own files, never the API or other pages
  if (e.request.method !== 'GET' || url.origin !== location.origin || !url.pathname.startsWith('/app/djakimita/')) return;
  e.respondWith(
    fetch(e.request).then((res) => {
      if (res.ok && (url.pathname.includes('/js/') || url.pathname.includes('/assets/'))) {
        const copy = res.clone();
        caches.open(CACHE).then((c) => c.put(e.request, copy));
      }
      return res;
    }).catch(() => caches.match(e.request).then((r) => r || caches.match('/app/djakimita/')))
  );
});
