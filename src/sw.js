/*
 * Apotheke service worker (template; served by public/index.php at /sw.js with the placeholders filled in).
 * - The app shell (CSS/JS/icons/manifest + the /offline page) is precached per VERSION, which is derived from the
 *   asset hashes, so every deploy that changes an asset installs a fresh cache and drops the old one.
 * - Pages: network first. Successful list (/), item (/item/N) and /lent pages are kept as the "last seen" copy that is
 *   shown when the network is unreachable; anything else falls back to /offline.
 * - Never cached: non-GET requests, other origins, fetch()/JSON calls, backups, CSV export, sign-in/out, media other
 *   than thumbnails.
 */
'use strict';
const VERSION = '__VERSION__';
const PRECACHE = __PRECACHE__;
const SHELL = 'apotheke-shell-' + VERSION;
const PAGES = 'apotheke-pages';
const MEDIA = 'apotheke-media';
const MAX_PAGES = 60;
const MAX_MEDIA = 300;
const NEVER = /^\/(?:backup|export\.csv|login|logout|sw\.js|healthz|bulk)(?:[/.?]|$)/;
const DOWNLOAD = /^\/(?:backup\.zip|export\.csv)$/;
const PAGE_OK = /^\/(?:|item\/\d+|lent)$/;

self.addEventListener('install', e => {
  e.waitUntil(caches.open(SHELL).then(c => c.addAll(PRECACHE)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', e => {
  e.waitUntil(caches.keys()
    .then(keys => Promise.all(keys.filter(k => k.startsWith('apotheke-shell-') && k !== SHELL).map(k => caches.delete(k))))
    .then(() => self.clients.claim()));
});

async function trim(name, max) {
  const c = await caches.open(name);
  const keys = await c.keys();
  for (let i = 0; i < keys.length - max; i++) await c.delete(keys[i]);
}

async function cacheFirst(req, name, max) {
  const hit = await caches.match(req);
  if (hit) return hit;
  const res = await fetch(req);
  if (res.ok && res.type === 'basic') {
    const c = await caches.open(name);
    await c.put(req, res.clone());
    if (max) trim(name, max);
  }
  return res;
}

async function page(req, url) {
  const key = url.origin + url.pathname;   // ?p= (gallery position) etc. share one copy
  try {
    const res = await fetch(req);
    if (res.ok && !res.redirected && res.type === 'basic' && PAGE_OK.test(url.pathname) && !url.searchParams.has('select')) {
      const c = await caches.open(PAGES);
      await c.delete(key);                    // re-insert so the newest pages are kept by trim()
      await c.put(key, res.clone());
      trim(PAGES, MAX_PAGES);
    }
    return res;
  } catch (err) {
    const copy = PAGE_OK.test(url.pathname) && await caches.match(key, { cacheName: PAGES });
    return copy || offline();
  }
}

async function offline() {
  return (await caches.match('/offline', { cacheName: SHELL })) || Response.error();
}

self.addEventListener('fetch', e => {
  const req = e.request;
  if (req.method !== 'GET') return;
  const url = new URL(req.url);
  if (url.origin !== self.location.origin || DOWNLOAD.test(url.pathname)) return;
  if (NEVER.test(url.pathname)) {   // network only; a page that can't load still gets the offline page
    if (req.mode === 'navigate') e.respondWith(fetch(req).catch(offline));
    return;
  }
  if (req.headers.get('X-Requested-With') === 'fetch') return;      // instant search, stats, JSON APIs
  if (req.mode === 'navigate') { e.respondWith(page(req, url)); return; }
  if (url.pathname.startsWith('/assets/') || url.pathname === '/manifest.webmanifest') { e.respondWith(cacheFirst(req, SHELL)); return; }
  if (url.pathname.startsWith('/media/thumb/')) { e.respondWith(cacheFirst(req, MEDIA, MAX_MEDIA)); return; }
});

self.addEventListener('message', e => {
  if (e.data === 'clear') e.waitUntil(Promise.all([caches.delete(PAGES), caches.delete(MEDIA)]));
});
