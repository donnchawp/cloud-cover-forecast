/**
 * Cloud Cover Forecast PWA Service Worker
 * Provides offline support and caching for the forecast app.
 */

const CACHE_VERSION = 'v20';
const STATIC_CACHE = `ccf-static-${CACHE_VERSION}`;
const DYNAMIC_CACHE = `ccf-dynamic-${CACHE_VERSION}`;
const API_CACHE = `ccf-api-${CACHE_VERSION}`;

// Static assets to cache on install.
const STATIC_ASSETS = [
  '/forecast-app/',
  '/wp-content/plugins/cloud-cover-forecast/assets/css/forecast-app.css',
  '/wp-content/plugins/cloud-cover-forecast/assets/js/forecast-app.js',
  '/wp-content/plugins/cloud-cover-forecast/assets/js/forecast-storage.js',
];

// Install event - cache static assets.
self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(STATIC_CACHE).then((cache) => {
      return cache.addAll(STATIC_ASSETS).catch((err) => {
        console.warn('Some static assets failed to cache:', err);
      });
    })
  );
  self.skipWaiting();
});

// Activate event - clean up old caches.
self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) => {
      return Promise.all(
        keys
          .filter((key) => key.startsWith('ccf-') && key !== STATIC_CACHE && key !== DYNAMIC_CACHE && key !== API_CACHE)
          .map((key) => caches.delete(key))
      );
    })
  );
  self.clients.claim();
});

// Fetch event - network-first for API, cache-first for static.
self.addEventListener('fetch', (event) => {
  const { request } = event;
  const url = new URL(request.url);

  // Skip non-GET requests.
  if (request.method !== 'GET') {
    return;
  }

  // Handle API requests (AJAX endpoints).
  if (url.pathname.includes('admin-ajax.php') && url.search.includes('ccf_')) {
    event.respondWith(networkFirstStrategy(request, API_CACHE, 300)); // 5 min cache for API.
    return;
  }

  // Handle static assets - use network-first for JS/CSS to ensure updates are fetched.
  if (isStaticAsset(url.pathname)) {
    const strategy = /\.(js|css)$/.test(url.pathname)
      ? networkFirstStrategy(request, STATIC_CACHE, 86400)  // 24h cache for code
      : cacheFirstStrategy(request, STATIC_CACHE);          // Cache-first for images/fonts
    event.respondWith(strategy);
    return;
  }

  // Handle the app shell (forecast-app page).
  if (url.pathname.includes('/forecast-app')) {
    event.respondWith(networkFirstStrategy(request, DYNAMIC_CACHE, 3600)); // 1 hour cache for app shell.
    return;
  }

  // Default: network only.
  event.respondWith(fetch(request));
});

/**
 * Cache-first strategy: try cache, fall back to network.
 */
async function cacheFirstStrategy(request, cacheName) {
  const cache = await caches.open(cacheName);
  const cached = await cache.match(request);

  if (cached) {
    return cached;
  }

  try {
    const response = await fetch(request);
    if (response.ok) {
      cache.put(request, response.clone());
    }
    return response;
  } catch (err) {
    return new Response('Offline', { status: 503, statusText: 'Service Unavailable' });
  }
}

/**
 * Network-first strategy: try network, fall back to cache.
 */
async function networkFirstStrategy(request, cacheName, maxAge) {
  const cache = await caches.open(cacheName);

  try {
    const response = await fetch(request);
    if (response.ok) {
      const cloned = response.clone();
      const headers = new Headers(cloned.headers);
      headers.set('sw-cached-at', Date.now().toString());
      const body = await cloned.blob();
      const cachedResponse = new Response(body, {
        status: cloned.status,
        statusText: cloned.statusText,
        headers,
      });
      cache.put(request, cachedResponse);
    }
    return response;
  } catch (err) {
    const cached = await cache.match(request);
    if (cached) {
      // Check if cached response is still valid.
      const cachedAt = cached.headers.get('sw-cached-at');
      if (cachedAt) {
        const age = (Date.now() - parseInt(cachedAt, 10)) / 1000;
        if (age < maxAge) {
          return cached;
        }
      }
      return cached; // Return stale cache as fallback.
    }
    return new Response(JSON.stringify({ success: false, error: 'Offline' }), {
      status: 503,
      headers: { 'Content-Type': 'application/json' },
    });
  }
}

/**
 * Check if URL is a static asset.
 */
function isStaticAsset(pathname) {
  return /\.(css|js|png|jpg|jpeg|gif|svg|woff|woff2|ttf|eot|ico)$/.test(pathname);
}

// Handle messages from the app.
self.addEventListener('message', (event) => {
  if (event.data && event.data.type === 'SKIP_WAITING') {
    self.skipWaiting();
  }

  if (event.data && event.data.type === 'CLEAR_CACHE') {
    event.waitUntil(
      caches.keys().then((keys) => {
        return Promise.all(keys.filter((key) => key.startsWith('ccf-')).map((key) => caches.delete(key)));
      })
    );
  }
});
