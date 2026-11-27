// Service worker : met en cache l'interface (CSS/JS/icônes) ; les données restent toujours en ligne.
const V = 'acp-web-v3', SHELL = ['assets/app.css?v=3', 'assets/app.js?v=3', 'assets/pos.js?v=3', 'assets/icon-192.png', 'assets/icon-512.png', 'offline.html'];
self.addEventListener('install', e => { e.waitUntil(caches.open(V).then(c => c.addAll(SHELL)).then(() => self.skipWaiting())); });
self.addEventListener('activate', e => { e.waitUntil(caches.keys().then(k => Promise.all(k.filter(x => x !== V).map(x => caches.delete(x)))).then(() => self.clients.claim())); });
self.addEventListener('fetch', e => {
  const r = e.request; if (r.method !== 'GET') return;
  const u = new URL(r.url); if (u.origin !== location.origin) return;
  if (u.pathname.includes('/assets/')) { e.respondWith(caches.match(r).then(h => h || fetch(r).then(x => { const c = x.clone(); caches.open(V).then(ca => ca.put(r, c)); return x; }))); return; }
  if (r.mode === 'navigate') e.respondWith(fetch(r).catch(() => caches.match('offline.html')));
});
