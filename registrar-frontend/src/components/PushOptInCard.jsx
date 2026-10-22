import { useState } from 'react';
import { BellIcon } from '@heroicons/react/24/outline';
import { useAuth } from '../context/AuthProvider';
import { useTheme } from '../context/ThemeContext';

const DISMISS_DAYS = 14;
const dismissKey = (userId) => `ris.push.dismissed.${userId}`;

const readDismissed = (userId) => {
  try {
    const at = Number(localStorage.getItem(dismissKey(userId)));
    return at > 0 && Date.now() - at < DISMISS_DAYS * 86400000;
  } catch {
    return false;
  }
};

/**
 * "Enable Background Notifications" card.
 * `push` is the object returned by usePushNotifications(); it is passed in
 * (not called here) so the card and PushStatusRow share one state.
 * Renders nothing unless the user can actually opt in.
 */
export const PushOptInCard = ({ push }) => {
  const { user } = useAuth();
  const { isDark } = useTheme();
  const userId = user?.user_id;

  const [dismissed, setDismissed] = useState(() => readDismissed(userId));
  const [attempted, setAttempted] = useState(false);

  const handleEnable = async () => {
    setAttempted(true);
    await push.enable();
  };

  const handleDismiss = () => {
    try { localStorage.setItem(dismissKey(userId), String(Date.now())); } catch { /* ignore */ }
    setDismissed(true);
  };

  // The user just pressed Block in the browser prompt: say how to undo it.
  if (push.status === 'denied' && attempted) {
    return (
      <p role="status" className={`mx-3 mt-3 rounded-xl px-4 py-3 text-xs ${isDark ? 'bg-[#1a1b1e] text-[#b0b3b8]' : 'bg-white/10 text-white/70'}`}>
        Notifications are blocked for this site. To turn them on, allow notifications in your browser&apos;s site settings, then try again.
      </p>
    );
  }

  if (push.status !== 'prompt' || dismissed) return null;

  return (
    <section
      aria-label="Background notifications"
      className={`mx-3 mt-3 rounded-2xl border p-4 ${
        isDark ? 'bg-[#1a1b1e] border-[#3e4042]' : 'bg-[#f5eeee] border-[#e5c9c9]'
      }`}
    >
      <div className="flex items-start gap-3">
        <BellIcon aria-hidden="true" className={`w-5 h-5 mt-0.5 shrink-0 ${isDark ? 'text-pup-yellow' : 'text-pup-maroon'}`} />
        <div className="min-w-0">
          <h3 className={`text-sm font-bold ${isDark ? 'text-[#e4e6eb]' : 'text-gray-900'}`}>
            Enable Background Notifications
          </h3>
          <p className={`mt-1 text-xs leading-relaxed ${isDark ? 'text-[#b0b3b8]' : 'text-gray-500'}`}>
            Receive urgent status updates even when PUPT-GuiSIS is closed.
          </p>
        </div>
      </div>

      {push.error && (
        <p role="alert" className="mt-2 text-xs font-semibold text-red-500">{push.error}</p>
      )}

      <button
        type="button"
        onClick={handleEnable}
        disabled={push.busy}
        className="mt-3 w-full rounded-xl bg-pup-maroon bg-pup-maroon-hover py-2.5 text-sm font-semibold text-white transition-colors disabled:cursor-not-allowed disabled:opacity-60"
      >
        {push.busy ? 'Enabling…' : 'Enable'}
      </button>

      <button
        type="button"
        onClick={handleDismiss}
        className={`mt-2 w-full text-center text-[11px] font-semibold ${isDark ? 'text-[#b0b3b8] hover:text-white' : 'text-gray-500 hover:text-gray-800'}`}
      >
        Not now
      </button>
    </section>
  );
};

/**
 * Slim "on / turn off" row shown once subscribed, so users can opt out.
 */
export const PushStatusRow = ({ push }) => {
  const { isDark } = useTheme();

  if (push.status !== 'subscribed') return null;

  return (
    <div className="flex items-center justify-between gap-3 w-full">
      <span className={`flex items-center gap-2 text-[11px] font-semibold ${isDark ? 'text-[#b0b3b8]' : 'text-white/60'}`}>
        <BellIcon aria-hidden="true" className="w-4 h-4" />
        Background notifications on
      </span>
      <button
        type="button"
        onClick={push.disable}
        disabled={push.busy}
        className="text-[11px] font-bold text-pup-yellow hover:underline disabled:opacity-60"
      >
        {push.busy ? 'Turning off…' : 'Turn off'}
      </button>
    </div>
  );
};
