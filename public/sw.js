// Offline support: keeps the app's files so the page opens without a connection. Answers are never cached here;
// they stay in the page's own storage until the server can be reached again.
const CACHE = 'pdsync-v5';
const SHELL = ['./', 'library.php', 'app.css', 'preview.css', 'ui.js', 'app.js', 'library.js', 'vendor/cally-0.9.2.js', 'fonts/geist-latin-wght-normal.woff2', 'fonts/geist-mono-latin-wght-normal.woff2', 'fonts/instrument-serif-latin-400-normal.woff2', 'fonts/instrument-serif-latin-400-italic.woff2', 'fonts/roboto-condensed-latin-700-normal.woff2', 'icon.svg',
  'manifest.webmanifest', 'psgc/places.json', 'pds/page1.png', 'pds/page2.png', 'pds/page3.png', 'pds/page4.png'];
// Server endpoints: always live, never served from the cache.
const LIVE = /\/(api|account|auth|stats|admin|view|xlsx|privacy|forms|form-page)\.php/;

self.addEventListener('install', (e) => {
  e.waitUntil(caches.open(CACHE).then((c) => Promise.allSettled(SHELL.map((u) => c.add(u)))).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (e) => {
  e.waitUntil(caches.keys().then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k)))).then(() => self.clients.claim()));
});

self.addEventListener('fetch', (e) => {
  const req = e.request, url = new URL(req.url);
  if (req.method !== 'GET' || url.origin !== location.origin || LIVE.test(url.pathname)) return;
  // Pages (editor and library): network first so sign-in state is current, the last copy when offline.
  if (req.mode === 'navigate') {
    const key = url.pathname.endsWith('/library.php') ? 'library.php' : './';
    e.respondWith(fetch(req).then((res) => {
      if (res.ok && (key === 'library.php' || url.pathname.endsWith('/'))) caches.open(CACHE).then((c) => c.put(key, res.clone()));
      return res;
    }).catch(() => caches.match(key).then((hit) => hit || caches.match('./'))));
    return;
  }
  // Files: the cached copy at once, refreshed in the background for next time.
  e.respondWith(caches.match(req, { ignoreSearch: !url.search.includes('v=') }).then((hit) => {
    const net = fetch(req).then((res) => {
      if (res.ok) caches.open(CACHE).then((c) => c.put(req, res.clone()));
      return res;
    }).catch(() => hit);
    return hit || net;
  }));
});
