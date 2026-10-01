/* Temps & Congés — Direction provinciale du Transport et de la Logistique de Bouarfa (Maroc), stage de mars à juillet 2021. */
/* Service worker : permet d'installer le site comme application de bureau (PWA).
   - Les fichiers statiques (CSS, JS, icônes) sont mis en cache : ouverture instantanée.
   - Les pages et l'API ne sont JAMAIS mises en cache (données personnelles, toujours à jour).
   - Sans réseau, une page « hors ligne » s'affiche au lieu d'une erreur du navigateur. */
'use strict';

const VERSION = 'temps-conges-v1';
const STATIQUES = [
    'assets/css/style.css',
    'assets/js/app.js',
    'assets/icons/icone.svg',
    'assets/icons/icone-192.png',
    'hors-ligne.html',
];

self.addEventListener('install', (evenement) => {
    evenement.waitUntil(caches.open(VERSION).then((cache) => cache.addAll(STATIQUES)));
    self.skipWaiting();
});

self.addEventListener('activate', (evenement) => {
    evenement.waitUntil(
        caches.keys()
            .then((cles) => Promise.all(cles.filter((cle) => cle !== VERSION).map((cle) => caches.delete(cle))))
            .then(() => self.clients.claim()),
    );
});

self.addEventListener('fetch', (evenement) => {
    const requete = evenement.request;
    if (requete.method !== 'GET' || new URL(requete.url).origin !== self.location.origin) return;

    // Pages : toujours le réseau ; page hors ligne en secours
    if (requete.mode === 'navigate') {
        evenement.respondWith(fetch(requete).catch(() => caches.match('hors-ligne.html')));
        return;
    }

    // Fichiers statiques : cache d'abord, mise à jour en arrière-plan
    if (new URL(requete.url).pathname.includes('/assets/')) {
        evenement.respondWith(
            caches.open(VERSION).then(async (cache) => {
                const enCache = await cache.match(requete);
                const reseau = fetch(requete).then((reponse) => {
                    if (reponse.ok) cache.put(requete, reponse.clone());
                    return reponse;
                }).catch(() => enCache);
                return enCache || reseau;
            }),
        );
    }
});
