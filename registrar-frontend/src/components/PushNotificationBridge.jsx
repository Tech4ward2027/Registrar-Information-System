import { useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import { useAuth } from '../context/AuthProvider';
import { isPushSupported, syncPushSubscription } from '../services/pushNotifications';

/**
 * Renders nothing. Mounted once, inside the Router and AuthProvider, to:
 *  1. re-sync an existing push subscription with the backend after login /
 *     page load (idempotent), and
 *  2. route the already-open tab when the user taps a background
 *     notification (the service worker posts PUSH_NAVIGATE).
 */
const PushNotificationBridge = () => {
  const { user } = useAuth();
  const navigate = useNavigate();
  const userId = user?.user_id;

  useEffect(() => {
    if (userId == null) return;
    syncPushSubscription(userId).catch((err) => {
      console.error('[push] sync failed:', err);
    });
  }, [userId]);

  useEffect(() => {
    if (!isPushSupported()) return undefined;

    const onMessage = (event) => {
      const { type, url } = event.data ?? {};
      // Same-origin absolute paths only.
      if (type === 'PUSH_NAVIGATE' && typeof url === 'string' && url.startsWith('/') && !url.startsWith('//')) {
        navigate(url);
      }
    };

    navigator.serviceWorker.addEventListener('message', onMessage);
    return () => navigator.serviceWorker.removeEventListener('message', onMessage);
  }, [navigate]);

  return null;
};

export default PushNotificationBridge;
