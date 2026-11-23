"use strict";

importScripts("assets/js/offline-sync.js");

const CACHE_PREFIX = "home-school-pwa-";
const CACHE_VERSION = "v7";
const STATIC_CACHE = `${CACHE_PREFIX}static-${CACHE_VERSION}`;
const PAGE_CACHE = `${CACHE_PREFIX}pages-${CACHE_VERSION}`;
const PUBLIC_DATA_CACHE = `${CACHE_PREFIX}public-data-v1`;
const APP_SCOPE = new URL(self.registration.scope);
const SCOPE_PATH = APP_SCOPE.pathname.endsWith("/")
  ? APP_SCOPE.pathname
  : `${APP_SCOPE.pathname}/`;
const scopedUrl = (path) => new URL(path, APP_SCOPE).href;
const OFFLINE_URL = scopedUrl("offline.html");

const PUBLIC_PAGES = new Set([
  "index.html",
  "apropos.html",
  "formations.html",
  "galerie.html",
  "temoignages.html",
  "faq.html",
  "contact.html"
]);
const PUBLIC_GET_APIS = new Set([
  "api/evenements.php",
  "api/formations.php"
]);
const STATIC_ASSET_EXTENSIONS = /\.(?:css|js|png|jpe?g|webp|gif|svg|ico|woff2?)$/i;
const PRECACHE_STATIC = [
  "offline.html",
  "manifest.webmanifest",
  "assets/css/style.css",
  "assets/css/pwa.css",
  "assets/css/site-images.css",
  "assets/css/gallery.css",
  "assets/js/script.js",
  "assets/js/offline-sync.js",
  "assets/js/gallery-data.js",
  "assets/js/pwa.js",
  "assets/images/logo/favicon.png",
  "assets/images/logo/logo-officiel-bleu.png",
  "assets/images/logo/logo-officiel-blanc.png",
  "assets/images/logo/pwa-icon.svg",
  "assets/images/logo/pwa-maskable.svg",
  "assets/images/logo/pwa-icon-180.png",
  "assets/images/logo/pwa-icon-192.png",
  "assets/images/logo/pwa-icon-512.png",
  "assets/images/logo/pwa-maskable-512.png",
  "assets/images/salles/salles-026.webp",
  "assets/images/formations/formations-001.webp",
  "assets/images/formations/formations-003.webp"
];
const PRECACHE_PAGES = [...PUBLIC_PAGES];

const relativePath = (url) => {
  if (!url.pathname.startsWith(SCOPE_PATH)) {
    return null;
  }
  return url.pathname.slice(SCOPE_PATH.length);
};

const isAdminPath = (path) =>
  path === "admin" || path.startsWith("admin/");

async function networkFirst(request, cacheName, fallback) {
  let response;
  try {
    response = await fetch(request);
  } catch (error) {
    const cached = await fallback();
    if (cached) {
      return cached;
    }
    throw error;
  }

  if (response.ok) {
    try {
      const cache = await caches.open(cacheName);
      await cache.put(request, response.clone());
    } catch (error) {
      console.error("Impossible d’enregistrer une réponse publique dans le cache.", error);
    }
  }
  return response;
}

async function cachedOfflinePage() {
  const cache = await caches.open(STATIC_CACHE);
  return cache.match(OFFLINE_URL);
}

async function cachedPublicPage(path, request) {
  const cache = await caches.open(PAGE_CACHE);
  const key = path === "" ? scopedUrl("index.html") : request;
  return cache.match(key, { ignoreSearch: true });
}

async function cachedPublicData(request) {
  const cache = await caches.open(PUBLIC_DATA_CACHE);
  return cache.match(request, { ignoreSearch: true });
}

self.addEventListener("install", (event) => {
  event.waitUntil(
    Promise.all([
      caches.open(STATIC_CACHE).then((cache) =>
        cache.addAll(PRECACHE_STATIC.map(scopedUrl))
      ),
      caches.open(PAGE_CACHE).then((cache) =>
        cache.addAll(PRECACHE_PAGES.map(scopedUrl))
      )
    ])
  );
});

self.addEventListener("activate", (event) => {
  event.waitUntil(
    caches
      .keys()
      .then((keys) =>
        Promise.all(
          keys
            .filter(
              (key) =>
                key.startsWith(CACHE_PREFIX) &&
                ![STATIC_CACHE, PAGE_CACHE, PUBLIC_DATA_CACHE].includes(key)
            )
            .map((key) => caches.delete(key))
        )
      )
      .then(() => self.clients.claim())
  );
});

self.addEventListener("message", (event) => {
  if (event.data && event.data.type === "SKIP_WAITING") {
    self.skipWaiting();
  }
});

self.addEventListener("push", (event) => {
  if (!event.data) {
    console.error("Notification push reçue sans contenu.");
    return;
  }
  event.waitUntil((async () => {
    let payload;
    try {
      payload = event.data.json();
    } catch (error) {
      console.error("Le contenu de la notification push n’est pas un JSON valide.", error);
      return;
    }

    if (
      !payload ||
      typeof payload.title !== "string" ||
      typeof payload.body !== "string" ||
      typeof payload.url !== "string"
    ) {
      console.error("Le contenu de la notification push est incomplet.");
      return;
    }

    const target = new URL(payload.url, self.location.origin);
    if (
      target.origin !== self.location.origin ||
      !target.pathname.startsWith(SCOPE_PATH)
    ) {
      console.error("Le lien de la notification ne correspond pas au site HOME SCHOOL.");
      return;
    }

    await self.registration.showNotification(payload.title, {
      body: payload.body,
      icon: scopedUrl("assets/images/logo/pwa-icon-192.png"),
      badge: scopedUrl("assets/images/logo/pwa-icon-192.png"),
      data: {
        url: target.href,
        category: typeof payload.category === "string" ? payload.category : "information"
      }
    });
  })());
});

self.addEventListener("notificationclick", (event) => {
  event.notification.close();
  event.waitUntil((async () => {
    const rawUrl = event.notification.data && event.notification.data.url;
    const target = new URL(
      typeof rawUrl === "string" ? rawUrl : APP_SCOPE.href,
      self.location.origin
    );
    if (target.origin !== self.location.origin || !target.pathname.startsWith(SCOPE_PATH)) {
      console.error("Le lien de la notification est refusé car il est hors du site.");
      return;
    }

    const windows = await self.clients.matchAll({ type: "window", includeUncontrolled: true });
    for (const client of windows) {
      if (new URL(client.url).origin === self.location.origin && "focus" in client) {
        await client.navigate(target.href);
        return client.focus();
      }
    }
    return self.clients.openWindow(target.href);
  })());
});

self.addEventListener("fetch", (event) => {
  const request = event.request;
  if (request.method !== "GET") {
    return;
  }

  const url = new URL(request.url);
  if (url.origin !== self.location.origin) {
    return;
  }

  const path = relativePath(url);
  if (path === null || isAdminPath(path)) {
    return;
  }

  if (PUBLIC_GET_APIS.has(path)) {
    event.respondWith(
      networkFirst(request, PUBLIC_DATA_CACHE, () => cachedPublicData(request))
    );
    return;
  }

  if (request.mode === "navigate") {
    if (PUBLIC_PAGES.has(path) || path === "") {
      event.respondWith(
        networkFirst(request, PAGE_CACHE, () =>
          cachedPublicPage(path, request).then(
            (cached) => cached || cachedOfflinePage()
          )
        )
      );
      return;
    }

    if (path === "inscription.php") {
      event.respondWith(
        fetch(request).catch(async () => {
          const offline = await cachedOfflinePage();
          return (
            offline ||
            new Response(
              "Vous êtes actuellement hors connexion. Certaines fonctionnalités nécessitent une connexion Internet.",
              { status: 503, headers: { "Content-Type": "text/plain; charset=utf-8" } }
            )
          );
        })
      );
    }
    return;
  }

  if (
    path.startsWith("assets/") &&
    path !== "assets/css/admin.css" &&
    !path.startsWith("assets/images/diplomes/") &&
    STATIC_ASSET_EXTENSIONS.test(path)
  ) {
    event.respondWith(
      caches.open(STATIC_CACHE).then(async (cache) => {
        const cached = await cache.match(request, { ignoreSearch: true });
        if (cached) {
          return cached;
        }
        const response = await fetch(request);
        if (response.ok) {
          try {
            await cache.put(request, response.clone());
          } catch (error) {
            console.error("Impossible d’enregistrer une ressource publique dans le cache.", error);
          }
        }
        return response;
      })
    );
  }
});
