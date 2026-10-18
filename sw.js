// Carnet : application installable et hors ligne.
// - pages : réseau d'abord, copie gardée pour la salle sans réseau (vidée à la déconnexion, voir app.js) ;
// - fichiers statiques (assets/, versionnés par ?v=) et vignettes d'exercices : cache d'abord ;
// - api.php : jamais mis en cache (les saisies hors ligne passent par la file d'attente d'app.js).
const VERSION = 'carnet-v1';
const PAGES = VERSION + '-pages', STATIC = VERSION + '-static', MEDIA = VERSION + '-media';

self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (e) => {
  e.waitUntil(caches.keys()
    .then((keys) => Promise.all(keys.filter((k) => !k.startsWith(VERSION)).map((k) => caches.delete(k))))
    .then(() => self.clients.claim()));
});

self.addEventListener('fetch', (e) => {
  const req = e.request;
  if (req.method !== 'GET') return;
  const url = new URL(req.url);
  if (url.origin === self.location.origin) {
    if (url.pathname.endsWith('/api.php')) return;
    if (req.mode === 'navigate') e.respondWith(networkFirst(req));
    else if (url.pathname.includes('/assets/')) e.respondWith(cacheFirst(req, STATIC, 200));
  } else if (url.hostname === 'raw.githubusercontent.com' && req.destination === 'image') {
    e.respondWith(cacheFirst(req, MEDIA, 400));
  }
});

async function networkFirst(req) {
  const cache = await caches.open(PAGES);
  try {
    const res = await fetch(req);
    // pas de copie des redirections (connexion expirée…) ni des erreurs
    if (res.ok && !res.redirected) cache.put(req, res.clone());
    return res;
  } catch (err) {
    return (await cache.match(req)) || offline();
  }
}

async function cacheFirst(req, name, max) {
  const cache = await caches.open(name);
  const hit = await cache.match(req);
  if (hit) return hit;
  const res = await fetch(req);
  if (res.ok || res.type === 'opaque') {
    await cache.put(req, res.clone());
    const keys = await cache.keys();
    if (keys.length > max) await Promise.all(keys.slice(0, keys.length - max).map((k) => cache.delete(k)));
  }
  return res;
}

function offline() {
  return new Response('<!doctype html><html lang="fr"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
    + '<title>Hors ligne · Carnet</title><body style="font-family:system-ui;background:#131210;color:#efe9dc;padding:2rem">'
    + '<h1>Hors ligne</h1><p>Cette page n\'a pas encore été ouverte sur cet appareil. Reviens à la séance en cours ou réessaie avec du réseau.</p>',
    { status: 503, headers: { 'Content-Type': 'text/html; charset=utf-8' } });
}
