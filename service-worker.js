// service-worker.js — Kapanou Gestion Commerciale
//
// Ce service worker ne gere QUE les fichiers statiques de l'application
// (manifest, icones). Toutes les autres requetes -- pages PHP dynamiques
// (login, menu, caisse, factures...), qu'elles soient en GET ou en POST --
// passent directement au reseau, sans jamais etre interceptees. On evite
// ainsi tout risque d'interference avec la connexion, les sessions ou les
// formulaires.
//
// Tous les chemins sont resolus relativement a l'URL de CE fichier, donc ce
// service worker fonctionne sans modification que l'appli soit a la racine
// du domaine ou dans un sous-dossier (ex: /sutura-group/).

const CACHE_NAME = 'kapanou-static-v3';
const SCOPE_URL = new URL('.', self.location); // dossier contenant ce fichier

function scoped(relativePath) {
  return new URL(relativePath, SCOPE_URL).pathname;
}

const STATIC_ASSETS = [
  scoped('manifest.json'),
  scoped('assets/icons/icon-192.png'),
  scoped('assets/icons/icon-512.png'),
  scoped('assets/icons/icon-maskable-192.png'),
  scoped('assets/icons/icon-maskable-512.png')
];

const ASSETS_DIR = scoped('assets/icons/');
const MANIFEST_PATH = scoped('manifest.json');

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => cache.addAll(STATIC_ASSETS))
  );
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(
        keys
          .filter((key) => key !== CACHE_NAME)
          .map((key) => caches.delete(key))
      )
    )
  );
  self.clients.claim();
});

self.addEventListener('fetch', (event) => {
  const { request } = event;

  // On ne gere que les requetes GET du meme site.
  if (request.method !== 'GET' || !request.url.startsWith(self.location.origin)) {
    return; // laisse passer tel quel, pas d'interception
  }

  const url = new URL(request.url);
  const isStaticAsset = url.pathname.startsWith(ASSETS_DIR) || url.pathname === MANIFEST_PATH;

  if (!isStaticAsset) {
    // Toute page dynamique (login, menu, caisse, factures...) : on ne touche
    // a rien, on laisse le navigateur faire une requete reseau normale.
    return;
  }

  // Uniquement pour les fichiers statiques : cache d'abord, reseau en secours.
  event.respondWith(
    caches.match(request).then((cached) => cached || fetch(request))
  );
});
