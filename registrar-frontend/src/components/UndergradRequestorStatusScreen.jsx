import React from 'react';
import { ClockIcon, XCircleIcon, ArrowLeftIcon } from '@heroicons/react/24/solid';

/**
 * UndergradRequestorStatusScreen
 *
 * Undergrad Requestor Registration — Phase 3 wiring (frontend).
 *
 * Shown by SsoCallbackPage instead of CallbackErrorScreen when the
 * account IS a registered Undergrad Requestor whose review isn't
 * finished yet, or ended in rejection — as opposed to
 * CallbackErrorScreen, which is for someone with NO account in RIS at
 * all and needs to be told to go register.
 *
 * Deliberately a SEPARATE component rather than a new branch inside
 * CallbackErrorScreen: the two screens are for genuinely different
 * situations (never registered vs. registered-and-waiting-or-declined),
 * they need different copy and different visual tone ("come back later,
 * everything is fine" vs. "here's how to fix this"), and folding this
 * status into CallbackErrorScreen's existing "3 steps to register"
 * layout would either lie to a Rejected person, telling them to
 * register again when their prior submission was reviewed and
 * declined, or alarm a Pending person with a set of "fix this" steps
 * when nothing is wrong and no action is needed on their end.
 *
 * Props
 * -----
 * variant   "pending" | "rejected"   Which of the two states to render.
 * message   string                  The backend's message for this
 *                                    response, shown verbatim — see
 *                                    AuthProvider.ssoCallback's docblock
 *                                    for why the two possible source
 *                                    exceptions' wording should be
 *                                    trusted rather than re-derived here.
 * onBack    () => void               Called when the user dismisses the
 *                                    screen. The parent decides the
 *                                    navigation target (redirect to the
 *                                    IdP's logout URL for "rejected",
 *                                    since that account's IdP token was
 *                                    already revoked server-side and the
 *                                    browser's own IdP session should be
 *                                    cleared to match; a plain in-app
 *                                    navigate("/") for "pending", since
 *                                    nothing was revoked and the person
 *                                    may reasonably want to check back
 *                                    again shortly).
 */
const COPY = {
  pending: {
    icon: ClockIcon,
    accent: 'text-amber-300',
    iconWrap: 'bg-white text-amber-600',
    heading: "You're all set — your submission is under review.",
    subheading:
      "You've signed in successfully. A Registrar Admin still needs to review your onboarding submission before your account is fully active.",
    fallbackMessage:
      'Your Undergrad Requestor registration is still awaiting review. Please check back soon.',
    note:
      'No action is needed from you right now. You will be able to sign in normally once a Registrar Admin has reviewed your submission — this usually does not take long. If it has been more than a few days, please contact the Registrar\u2019s Office.',
    backLabel: 'Back to Login',
  },
  rejected: {
    icon: XCircleIcon,
    accent: 'text-red-200',
    iconWrap: 'bg-white text-red-700',
    heading: 'Your registration was not approved.',
    subheading:
      "You've signed in successfully, but your Undergrad Requestor onboarding submission was reviewed and declined.",
    fallbackMessage:
      'Your Undergrad Requestor registration was not approved. Please contact the Registrar\u2019s Office for details.',
    note:
      'For the specific reason, check the email sent to the address you registered with, or contact the Registrar\u2019s Office directly. If you believe this was a mistake, they can advise on next steps.',
    backLabel: 'Back to Login',
  },
};

const UndergradRequestorStatusScreen = ({ variant, message, onBack }) => {
  const copy = COPY[variant] ?? COPY.pending;
  const Icon = copy.icon;

  return (
    <div className="min-h-screen flex items-center justify-center p-4 sm:p-6">
      <div className="w-full max-w-xl overflow-hidden rounded-lg border border-white/20 bg-[#800000] text-white shadow-xl">

        {/* Header */}
        <div className="flex items-center px-4 py-3 bg-pup-maroon border-b border-white/15">
          <div className={`flex h-9 w-9 items-center justify-center rounded-md shrink-0 ${copy.iconWrap}`}>
            <Icon className="h-6 w-6" strokeWidth={2.5} />
          </div>
          <div className="ml-3 min-w-0">
            <p className="text-lg sm:text-xl font-semibold leading-snug text-white">
              {copy.heading}
            </p>
            <p className="mt-1 text-sm leading-snug text-white/85">
              {copy.subheading}
            </p>
          </div>
        </div>

        {/* Body */}
        <div className="px-4 py-4 sm:px-5 sm:py-5 space-y-4">
          <div className="rounded-md border border-white/15 bg-white/5 px-3 py-3">
            <p className="text-sm leading-5 text-white/90">
              {message || copy.fallbackMessage}
            </p>
          </div>

          <div className={`rounded-md border border-white/15 bg-white/5 px-3 py-3`}>
            <h2 className="text-xs font-semibold uppercase tracking-[0.18em] text-white/70 mb-1.5">
              What happens next
            </h2>
            <p className={`text-sm leading-5 ${copy.accent}`}>{copy.note}</p>
          </div>

          {/* Action */}
          <div className="flex items-center border-t border-white/15 pt-4">
            <button
              onClick={onBack}
              className="inline-flex items-center justify-center gap-2 rounded-md border border-white/20 px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-white/10 cursor-pointer"
            >
              <ArrowLeftIcon className="h-4 w-4" />
              {copy.backLabel}
            </button>
          </div>
        </div>

      </div>
    </div>
  );
};

export default UndergradRequestorStatusScreen;
