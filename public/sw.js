'use strict';

const CACHE_NAME = 'dondebailar-shell-v1';
const SHELL_FILES = [
    '/offline.html',
    '/icons/app-icon.svg',
    '/assets/css/site.css',
    '/assets/js/site.js'
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME)
            .then((cache) => cache.addAll(SHELL_FILES))
            .then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(
                keys.filter((key) => key.startsWith('dondebailar-shell-') && key !== CACHE_NAME)
                    .map((key) => caches.delete(key))
            ))
            .then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', (event) => {
    const request = event.request;
    const url = new URL(request.url);

    if (request.method !== 'GET' || url.origin !== self.location.origin) {
        return;
    }

    // Alleen vaste vormgevingsbestanden worden uit cache geladen.
    // Pagina's, accounts, formulieren, meldingen en evenementgegevens
    // worden nooit opgeslagen.
    if (url.pathname.startsWith('/assets/') || url.pathname.startsWith('/icons/')) {
        event.respondWith(
            fetch(request).catch(() => caches.match(request).then((cached) => cached || Response.error()))
        );
        return;
    }

    if (request.mode === 'navigate') {
        event.respondWith(
            fetch(request).catch(() => caches.match('/offline.html'))
        );
    }
});
