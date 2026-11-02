import { useState } from 'react';
import { BellIcon, XMarkIcon } from '@heroicons/react/24/outline';
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
      className={`px-4 py-3 border-b flex items-center justify-between gap-3 ${
        isDark ? 'bg-[#1e1f23] border-[#3e4042]' : 'bg-[#4a0808] border-black/20'
      }`}
    >
      <div className="flex items-center gap-3 min-w-0 flex-1">
        <BellIcon
          aria-hidden="true"
          className="w-5 h-5 shrink-0 text-pup-yellow"
        />
        <div className="min-w-0 flex-1">
          <p className="text-xs sm:text-[13px] font-semibold text-white leading-snug">
            Get status updates even when PUPT-Registrar is closed.
          </p>
          {push.error && (
            <p role="alert" className="mt-0.5 text-[11px] font-medium text-red-300">
              {push.error}
            </p>
          )}
        </div>
      </div>

      <div className="flex items-center gap-2.5 shrink-0">
        <button
          type="button"
          onClick={handleEnable}
          disabled={push.busy}
          className="rounded-xl bg-pup-yellow px-4 py-1.5 text-xs sm:text-sm font-bold text-pup-maroon hover:brightness-105 active:scale-95 transition-all disabled:cursor-not-allowed disabled:opacity-60 shadow-xs"
        >
          {push.busy ? 'Enabling…' : 'Enable'}
        </button>

        <button
          type="button"
          onClick={handleDismiss}
          aria-label="Dismiss notification prompt"
          title="Dismiss"
          className="p-1 text-white/70 hover:text-white transition-colors"
        >
          <XMarkIcon className="w-4 h-4 sm:w-5 sm:h-5" />
        </button>
      </div>
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