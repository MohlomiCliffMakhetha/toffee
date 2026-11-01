// toffee service worker: makes the app installable and shows notifications that
// arrive while the app is closed (Web Push). The app itself is never cached (always live; while it's
// down for a moment, a "toffee is updating" page kept on the phone shows instead, see SHELL);
// only vendor/ is: the emoji picker, its 775 KB of emoji data and the fonts. Those folders
// carry their version in the name (picmo-5.8.5, emojibase-data-17.0.0), so a copy never goes
// stale: an upgrade is a new folder, fetched fresh. So 😊 opens instantly after the first time.
const VENDOR = 'toffee-vendor-v1';
// "toffee is updating": kept on the phone, for when toffee can't be reached (a new version going
// live: the front door answers 502/503/504 for a few minutes; or no connection). It retries by itself.
const SHELL = 'toffee-shell-v1';
const UPDATING = ['/updating.html', '/toffee-logo-64.webp?v=2'];
self.addEventListener('install', (e) => {
    self.skipWaiting();
    e.waitUntil(caches.open(SHELL).then((c) => c.addAll(UPDATING)).catch(() => {}));   // (if it can't be fetched now, next time)
});
self.addEventListener('activate', (e) => e.waitUntil(self.clients.claim()));
const updatingPage = () => caches.match('/updating.html').then((page) => page
    ? new Response(page.body, { status: 503, headers: { 'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store' } })
    : null);

self.addEventListener('fetch', (e) => {
    const url = new URL(e.request.url);
    // Opening toffee (a page, not the API or files): the live page as always; if toffee is down
    // for a moment, our own "updating" page instead of the front door's error.
    if (e.request.mode === 'navigate' && url.origin === self.location.origin) {
        e.respondWith(fetch(e.request).then(async (res) => (res.status >= 502 && res.status <= 504 ? (await updatingPage()) || res : res),
            async () => (await updatingPage()) || Response.error()));
        return;
    }
    if (url.origin === self.location.origin && UPDATING.includes(url.pathname + url.search)) {   // (its logo: from the phone when toffee is down)
        e.respondWith(fetch(e.request).catch(() => caches.match(e.request)));
        return;
    }
    if (e.request.method !== 'GET' || url.origin !== self.location.origin || !url.pathname.startsWith('/vendor/')) return;
    e.respondWith(caches.open(VENDOR).then(async (cache) => {
        const hit = await cache.match(e.request);
        if (hit) return hit;
        const res = await fetch(e.request);
        if (res.ok) cache.put(e.request, res.clone());
        return res;
    }));
});

self.addEventListener('push', (e) => {
    let m = {};
    try { m = e.data ? e.data.json() : {}; } catch (err) { m = { body: e.data ? e.data.text() : '' }; }
    // A call: stays on screen until you act on it, rings with a long buzz each time it comes again,
    // and (where phones show buttons) has Answer.
    const shown = self.registration.showNotification(m.title || 'toffee', {
        body: m.body || '', icon: '/icon-192.png?v=3', badge: '/badge-96.png?v=2',   // (badge: the white shape Android shows in the status bar)
        tag: m.tag, renotify: !!m.tag, data: { url: m.url || '/' },
        silent: false, vibrate: m.call ? [500, 200, 500, 200, 500, 200, 500] : [200, 100, 200],   // the phone's notification sound, and a buzz
        requireInteraction: !!m.call,
        actions: m.call ? [{ action: 'answer', title: 'Answer' }] : [],
    });
    // The unread count on the app's icon (installed apps on iPhone and Android).
    const badge = typeof m.badge === 'number' && self.navigator.setAppBadge
        ? self.navigator.setAppBadge(m.badge).catch(() => {}) : null;
    // A call: an open toffee rings at once (not at its next check).
    const ring = /^toffee-(call|room)-/.test(String(m.tag || ''))
        ? self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((list) => list.forEach((c) => c.postMessage({ type: 'call' }))) : null;
    e.waitUntil(Promise.all([shown, badge, ring]));
});

// Tap a notification: open toffee at that comment (reusing an open window if there is one).
self.addEventListener('notificationclick', (e) => {
    e.notification.close();
    const url = new URL(e.notification.data.url || '/', self.location.origin).href;
    e.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((list) => {
        for (const c of list) {
            if ('focus' in c) { c.postMessage({ type: 'open', url }); return c.focus(); }
        }
        return self.clients.openWindow(url);
    }));
});

// The browser replaced this phone's subscription (its push service does that now and then).
// Tell toffee straight away, proving it's us with the old subscription's secret, so
// notifications keep coming without anyone having to open the app.
self.addEventListener('pushsubscriptionchange', (e) => {
    const old = e.oldSubscription;
    if (!old) return;   // nothing to prove it with: the app re-registers the next time it's opened
    e.waitUntil((async () => {
        const sub = e.newSubscription || await self.registration.pushManager.subscribe({
            userVisibleOnly: true, applicationServerKey: old.options.applicationServerKey });
        await fetch('/api.php?a=push_resubscribe', {
            method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ old_endpoint: old.endpoint, old_auth: old.toJSON().keys.auth, sub: sub.toJSON() }),
        });
    })().catch(() => {}));
});
