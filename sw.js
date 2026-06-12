// Service worker network-only: habilita instalación PWA sin cachear (siempre fresco)
self.addEventListener('install', function(e){ self.skipWaiting(); });
self.addEventListener('activate', function(e){ e.waitUntil(self.clients.claim()); });
self.addEventListener('fetch', function(e){ /* network-only passthrough: no cache */ });
