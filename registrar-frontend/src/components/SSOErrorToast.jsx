import React from 'react';
import { ExclamationCircleIcon, ArrowLeftIcon, ArrowTopRightOnSquareIcon } from '@heroicons/react/24/solid';

const SSOErrorToast = ({ onBack }) => {
  const steps = [
    {
      number: 1,
      title: 'Check Your One Portal Account',
      description: 'Go to One Portal and check if you already have an existing account. If you don’t have one yet, proceed to registration.',
      url: 'https://one-portal.isaxbsit2027.com/landing',
      label: 'One Portal'
    },
    {
      number: 2,
      title: 'Register at GuiSIS',
      description: 'Log in to GuiSIS and complete the IIR Form. This is necessary to link your account with the Registrar system.',
      url: 'https://www.guisis.dllbsit2027.com/',
      label: 'GuiSIS'
    },
    {
      number: 3,
      title: 'Login & Make a Request',
      description: 'Once your registration is complete, return to the Registrar portal, log in with IDP, and submit your document request.',
    }
  ];

  return (
    <div className="min-h-screen w-full flex items-center justify-center p-3 sm:p-6 bg-white overflow-y-auto">
      <div className="w-full max-w-xl max-h-[calc(100vh-1.5rem)] sm:max-h-[calc(100vh-3rem)] overflow-y-auto my-auto rounded-xl border border-white/20 bg-[#800000] text-white shadow-2xl transition-all">
        {/* Header */}
        <div className="flex items-start sm:items-center px-4 py-3.5 sm:px-5 sm:py-4 bg-pup-maroon border-b border-white/15">
          <div className="flex h-9 w-9 sm:h-10 sm:w-10 items-center justify-center rounded-lg bg-white text-pup-maroon shrink-0 shadow-sm mt-0.5 sm:mt-0">
            <ExclamationCircleIcon className="h-6 w-6 sm:h-7 sm:w-7" strokeWidth={2.5} />
          </div>

          <div className="ml-3 min-w-0 flex-1">
            <h1 className="text-base sm:text-xl font-bold leading-snug text-white">
              Oops! Hang tight — your account is not set up yet.
            </h1>
            <p className="mt-1 text-xs sm:text-sm leading-snug text-white/85">
              You’ve signed in successfully, but your account hasn’t been added to the system.
            </p>
          </div>
        </div>

        {/* Content */}
        <div className="p-4 sm:p-5">
          <h2 className="text-[11px] sm:text-xs font-bold uppercase tracking-widest text-white/70">
            What to do?
          </h2>

          <div className="mt-3 space-y-2.5 sm:space-y-3">
            {steps.map((step) => (
              <div
                key={step.number}
                className="flex flex-col sm:flex-row sm:items-center justify-between gap-2.5 sm:gap-3 rounded-lg border border-white/15 bg-white/5 p-3 sm:p-4 transition-colors hover:bg-white/[0.07]"
              >
                <div className="flex gap-2.5 sm:gap-3 min-w-0">
                  <div className="flex h-6 w-6 sm:h-7 sm:w-7 shrink-0 items-center justify-center rounded-md bg-white text-pup-maroon text-xs font-extrabold shadow-xs">
                    {step.number}
                  </div>
                  <div className="min-w-0 flex-1">
                    <h3 className="text-xs sm:text-sm font-bold text-white leading-tight">
                      {step.title}
                    </h3>
                    <p className="mt-1 text-xs leading-relaxed text-white/80">
                      {step.description}
                    </p>
                  </div>
                </div>

                {step.url && (
                  <a
                    href={step.url}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="inline-flex items-center justify-center gap-1.5 shrink-0 w-full sm:w-auto px-3.5 py-1.5 text-xs font-semibold rounded-md bg-white/10 hover:bg-white/20 text-white border border-white/30 transition-all self-stretch sm:self-center"
                  >
                    <span>{step.label}</span>
                    <ArrowTopRightOnSquareIcon className="w-3.5 h-3.5" />
                  </a>
                )}
              </div>
            ))}
          </div>

          {/* Action */}
          <div className="mt-4 sm:mt-5 flex items-center border-t border-white/15 pt-3.5 sm:pt-4">
            <button
              type="button"
              onClick={onBack || (() => window.location.href = '/')}
              className="inline-flex items-center justify-center gap-2 w-full sm:w-auto px-4 py-2.5 sm:py-2 rounded-lg border border-white/20 text-xs sm:text-sm font-semibold text-white transition-colors hover:bg-white/10 active:scale-[0.98] cursor-pointer"
            >
              <ArrowLeftIcon className="h-4 w-4" />
              Back to Login
            </button>
          </div>
        </div>
      </div>
    </div>
  );
};

export default SSOErrorToast;
