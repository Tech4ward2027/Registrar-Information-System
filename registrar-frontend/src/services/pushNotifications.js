// pushNotifications.js
// -------------------------------------------------------
// Browser-side Web Push plumbing. UI-free: the hook and components call
// these functions; nothing here touches React.
//
// Flow: register /sw.js → ask the server for its VAPID public key →
// pushManager.subscribe() → POST the subscription to /push/subscriptions.
// -------------------------------------------------------
import api from './api';

const SW_URL = '/sw.js';

// localStorage marker: which user_id this browser's push subscription was
// registered for. Stops a subscription left behind by user A from being
// silently re-attached to user B on a shared computer.
const OWNER_KEY = 'ris.push.owner';

const readOwner = () => {
  try { return localStorage.getItem(OWNER_KEY); } catch { return null; }
};
const writeOwner = (userId) => {
  try {
    if (userId == null) localStorage.removeItem(OWNER_KEY);
    else localStorage.setItem(OWNER_KEY, String(userId));
  } catch { /* storage unavailable — marker is best-effort */ }
};

export const isPushSupported = () =>
  typeof window !== 'undefined' &&
  window.isSecureContext &&
  'serviceWorker' in navigator &&
  'PushManager' in window &&
  'Notification' in window;

export const getPermission = () =>
  isPushSupported() ? Notification.permission : 'denied';

// base64url → Uint8Array (the format pushManager.subscribe() needs).
const urlBase64ToUint8Array = (base64Url) => {
  const padding = '='.repeat((4 - (base64Url.length % 4)) % 4);
  const base64 = (base64Url + padding).replace(/-/g, '+').replace(/_/g, '/');
  const raw = atob(base64);
  return Uint8Array.from(raw, (c) => c.charCodeAt(0));
};

const sameKey = (buffer, base64Url) => {
  if (!buffer) return false;
  const a = new Uint8Array(buffer);
  const b = urlBase64ToUint8Array(base64Url);
  return a.length === b.length && a.every((v, i) => v === b[i]);
};

// The server's VAPID public key, or null when push is disabled server-side
// (503). Cached for the page lifetime so opening the notification panel
// repeatedly doesn't re-hit the API.
let publicKeyPromise = null;
export const fetchPublicKey = () => {
  if (!publicKeyPromise) {
    publicKeyPromise = api
      .get('/push/public-key')
      .then((res) => res.data?.data?.public_key ?? null)
      .catch((err) => {
        // 503 = disabled (stable answer). Anything else may be transient —
        // don't cache a failure.
        if (err.response?.status !== 503) publicKeyPromise = null;
        return null;
      });
  }
  return publicKeyPromise;
};

const getRegistration = async () => {
  await navigator.serviceWorker.register(SW_URL, { scope: '/' });
  return navigator.serviceWorker.ready;
};

const toPayload = (subscription) => {
  const json = subscription.toJSON();
  const encodings = window.PushManager.supportedContentEncodings ?? [];
  return {
    endpoint: json.endpoint,
    keys: json.keys,
    content_encoding: encodings.includes('aes128gcm') ? 'aes128gcm' : 'aesgcm',
  };
};

/**
 * Read-only snapshot used to decide what the opt-in UI should show.
 * Never prompts and never calls the server except for the key check.
 *
 * @returns {'unsupported'|'unavailable'|'denied'|'subscribed'|'prompt'}
 */
export const getPushState = async (userId) => {
  if (!isPushSupported()) return 'unsupported';
  if (Notification.permission === 'denied') return 'denied';

  if (Notification.permission === 'granted') {
    const reg = await navigator.serviceWorker.getRegistration(SW_URL);
    const sub = await reg?.pushManager.getSubscription();
    if (sub && readOwner() === String(userId)) return 'subscribed';
  }

  return (await fetchPublicKey()) ? 'prompt' : 'unavailable';
};

/**
 * Subscribe this browser and register it with the backend.
 * MUST be called from a user gesture (a click) — the permission prompt and,
 * on iOS, the whole flow are blocked otherwise.
 *
 * @returns {Promise<'subscribed'|'denied'|'prompt'>}
 */
export const enablePush = async (userId) => {
  // First await in the click handler, so the gesture is still "live".
  const permission = await Notification.requestPermission();
  if (permission !== 'granted') return permission === 'denied' ? 'denied' : 'prompt';

  const publicKey = await fetchPublicKey();
  if (!publicKey) throw new Error('Push notifications are not enabled on the server.');

  const reg = await getRegistration();
  let sub = await reg.pushManager.getSubscription();

  // VAPID keys were rotated since this browser subscribed → the old
  // subscription can never be delivered to. Replace it.
  if (sub && !sameKey(sub.options?.applicationServerKey, publicKey)) {
    await sub.unsubscribe();
    sub = null;
  }

  if (!sub) {
    sub = await reg.pushManager.subscribe({
      userVisibleOnly: true,
      applicationServerKey: urlBase64ToUint8Array(publicKey),
    });
  }

  await api.post('/push/subscriptions', toPayload(sub));
  writeOwner(userId);
  return 'subscribed';
};

/**
 * Re-register an existing subscription on app load (idempotent upsert).
 * Self-heals a subscription the browser rotated or the server pruned.
 * Does nothing unless the user already opted in on this browser.
 */
export const syncPushSubscription = async (userId) => {
  if (!isPushSupported() || Notification.permission !== 'granted') return;
  if (readOwner() !== String(userId)) return;

  const publicKey = await fetchPublicKey();
  if (!publicKey) return;

  const reg = await getRegistration();
  const sub = await reg.pushManager.getSubscription();
  if (!sub || !sameKey(sub.options?.applicationServerKey, publicKey)) return;

  await api.post('/push/subscriptions', toPayload(sub));
};

/**
 * Turn background notifications off for this browser: tell the server, then
 * drop the browser subscription. Never throws.
 */
export const disablePush = async () => {
  try {
    if (!isPushSupported()) return;
    const reg = await navigator.serviceWorker.getRegistration(SW_URL);
    const sub = await reg?.pushManager.getSubscription();
    if (sub) {
      try {
        await api.post('/push/subscriptions/unsubscribe', { endpoint: sub.endpoint });
      } catch {
        // Server unreachable: the browser-side unsubscribe below still stops
        // delivery, and the server prunes the dead endpoint on its next send.
      }
      await sub.unsubscribe();
    }
  } catch (err) {
    console.error('[push] disable failed:', err);
  } finally {
    writeOwner(null);
  }
};

/**
 * Logout helper: unsubscribe so the next person on a shared computer
 * doesn't receive this account's notifications. Bounded so logout can
 * never hang on it.
 */
export const disablePushOnLogout = (timeoutMs = 3000) =>
  Promise.race([disablePush(), new Promise((resolve) => setTimeout(resolve, timeoutMs))]);
