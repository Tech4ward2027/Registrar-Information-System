/* eslint-env serviceworker */
/*
 * PUPT-GuiSIS service worker — Web Push only.
 *
 * Deliberately has NO fetch handler and NO caching: this file must never
 * change how the app loads, only deliver background notifications.
 *
 * Served from the site root (scope "/") with Cache-Control: no-cache so a
 * new version is picked up on the next visit. See nginx.conf.
 */

self.addEventListener('install', () => {
  // Activate immediately; there is no cache state to migrate.
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(self.clients.claim());
});

// Only same-origin absolute paths are ever opened. Guards against a
// malformed or tampered payload turning a tap into an open redirect.
const safePath = (value) =>
  typeof value === 'string' && value.startsWith('/') && !value.startsWith('//')
    ? value
    : '/';

self.addEventListener('push', (event) => {
  let data = {};
  try {
    data = event.data ? event.data.json() : {};
  } catch {
    data = {};
  }

  const title = typeof data.title === 'string' && data.title ? data.title : 'PUPT-GuiSIS';

  const options = {
    body: typeof data.body === 'string' ? data.body : '',
    icon: typeof data.icon === 'string' ? safePath(data.icon) : '/icons/icon-192.png',
    // Same tag replaces the earlier banner (one per request, not one per
    // status change); renotify makes the replacement still alert the user.
    tag: typeof data.tag === 'string' ? data.tag : undefined,
    renotify: typeof data.tag === 'string',
    timestamp: typeof data.timestamp === 'number' ? data.timestamp : Date.now(),
    data: {
      url: safePath(data.url),
      notificationId: data.notification_id ?? null,
    },
  };

  // A notification must ALWAYS be shown for a push (userVisibleOnly
  // contract; Safari revokes subscriptions that stay silent).
  event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();

  const path = safePath(event.notification.data && event.notification.data.url);

  event.waitUntil(
    (async () => {
      const windows = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });

      // Reuse an open tab: focus it and let the app route client-side
      // (no full reload, no lost state).
      for (const client of windows) {
        if (new URL(client.url).origin === self.location.origin && 'focus' in client) {
          await client.focus();
          client.postMessage({ type: 'PUSH_NAVIGATE', url: path });
          return;
        }
      }

      await self.clients.openWindow(new URL(path, self.location.origin).href);
    })(),
  );
});
