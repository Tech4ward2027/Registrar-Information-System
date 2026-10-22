import { useCallback, useEffect, useState } from 'react';
import { useAuth } from '../context/AuthProvider';
import { disablePush, enablePush, getPushState } from '../services/pushNotifications';

/**
 * State machine for the "Enable Background Notifications" UI.
 *
 *   loading      — checking the browser
 *   unsupported  — browser/context can't do Web Push (hide the UI)
 *   unavailable  — server has push turned off (hide the UI)
 *   prompt       — can be enabled (show the card)
 *   subscribed   — on for this browser
 *   denied       — blocked in browser settings (only the user can undo that)
 */
export const usePushNotifications = ({ enabled = true } = {}) => {
  const { user } = useAuth();
  const userId = user?.user_id;

  const [status, setStatus] = useState('loading');
  const [busy, setBusy]     = useState(false);
  const [error, setError]   = useState(null);

  useEffect(() => {
    // `enabled` lets a always-mounted caller (the notification panel) defer
    // the browser/server checks until the UI is actually opened.
    if (userId == null || !enabled) return undefined;
    let cancelled = false;

    getPushState(userId)
      .then((next) => { if (!cancelled) setStatus(next); })
      .catch(() => { if (!cancelled) setStatus('unsupported'); });

    return () => { cancelled = true; };
  }, [userId, enabled]);

  const enable = useCallback(async () => {
    setBusy(true);
    setError(null);
    try {
      setStatus(await enablePush(userId));
    } catch (err) {
      console.error('[push] enable failed:', err);
      setError('Could not enable notifications. Please try again.');
    } finally {
      setBusy(false);
    }
  }, [userId]);

  const disable = useCallback(async () => {
    setBusy(true);
    setError(null);
    await disablePush();
    setStatus('prompt');
    setBusy(false);
  }, []);

  return { status, busy, error, enable, disable };
};
