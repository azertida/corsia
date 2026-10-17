/* Corsia — mise en cache de l'appli pour qu'elle s'ouvre vite.
   À chaque mise à jour, augmenter CACHE_NAME pour que les téléphones reprennent les nouveaux fichiers.
   Le serveur des inscriptions (autre adresse) n'est jamais mis en cache. */
const CACHE_NAME = 'corsia-v3.1';
const FICHIERS = [
  './', './index.html', './qrcode.js', './manifest.webmanifest',
  './icones/icon-192.png', './icones/icon-512.png', './icones/apple-touch-icon.png', './icones/favicon-32.png',
  './polices/fraunces-latin-600-normal.woff2', './polices/fraunces-latin-700-normal.woff2',
  './polices/atkinson-hyperlegible-latin-400-normal.woff2', './polices/atkinson-hyperlegible-latin-700-normal.woff2',
  ...['poulpe', 'capybara', 'loutre', 'tortue', 'meduse', 'dauphin', 'phoque', 'manchot', 'baleine',
      'grenouille', 'poisson-globe', 'crabe'].map((a) => `./animaux/${a}.png`),
];

self.addEventListener('install', (e) => {
  e.waitUntil(caches.open(CACHE_NAME).then((c) => c.addAll(FICHIERS)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (e) => {
  e.waitUntil(caches.keys()
    .then((cles) => Promise.all(cles.filter((k) => k.startsWith('corsia-') && k !== CACHE_NAME).map((k) => caches.delete(k))))
    .then(() => self.clients.claim()));
});

self.addEventListener('fetch', (e) => {
  const url = new URL(e.request.url);
  if (e.request.method !== 'GET' || url.origin !== self.location.origin) return;   // le serveur : jamais en cache
  if (e.request.mode === 'navigate') {
    // La page : d'abord le réseau (pour recevoir les mises à jour), sinon la copie en cache.
    e.respondWith(fetch(e.request).then((r) => {
      const copie = r.clone(); caches.open(CACHE_NAME).then((c) => c.put('./index.html', copie)); return r;
    }).catch(() => caches.match('./index.html')));
    return;
  }
  e.respondWith(caches.match(e.request, { ignoreSearch: true }).then((r) => r || fetch(e.request)));
});
