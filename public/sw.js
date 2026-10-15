/*
 * Service worker minimal de l'application de contrôle : il rend l'application installable sur l'écran d'accueil (Android).
 * Aucun billet ni aucune réponse de l'API n'est mis en cache : les contrôles passent toujours par le serveur. Seule une page
 * « hors connexion » s'affiche si une page ne peut pas être chargée.
 */
const OFFLINE = `<!doctype html><html lang="fr"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Hors connexion</title><body style="margin:0;min-height:100vh;display:grid;place-items:center;background:#030919;color:#fff;font:16px/1.5 -apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;text-align:center;padding:24px"><div><h1 style="font-size:1.3rem">Pas de connexion internet</h1><p style="color:#b8c4dd">Le contrôle des billets a besoin d'internet. Vérifiez le Wi-Fi ou les données mobiles, puis réessayez.</p><p><a href="/scan.php" style="color:#fff">Réessayer</a></p></div></body></html>`;

self.addEventListener("install", () => self.skipWaiting());
self.addEventListener("activate", (e) => e.waitUntil(self.clients.claim()));
self.addEventListener("fetch", (e) => {
  if (e.request.mode !== "navigate") return; // API, images, scripts : réseau normal, sans cache
  e.respondWith(fetch(e.request).catch(() => new Response(OFFLINE, { headers: { "Content-Type": "text/html; charset=utf-8" } })));
});
