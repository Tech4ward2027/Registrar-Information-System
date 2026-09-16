import React, { useEffect, useRef, useState } from "react";
import { useSearchParams, Link } from "react-router-dom";
import { useTheme } from "../context/ThemeContext";
import { confirmUndergradRequestorEmail } from "../services/api";
import LandingPage from "../layouts/LandingPage.jsx";
import logoImage from "../assets/puplogoimage.png";
import { CheckCircleIcon, XCircleIcon } from "@heroicons/react/24/outline";

const UndergradRequestorVerifyEmailPage = () => {
  const [params] = useSearchParams();
    const isDark = false;

  const hasFired = useRef(false);
  const [loading, setLoading] = useState(true);
  const [success, setSuccess] = useState(false);
  const [errorMessage, setErrorMessage] = useState("");

  const email = params.get("email") || "";
  const token = params.get("token") || "";

  useEffect(() => {
    if (hasFired.current) return;
    hasFired.current = true;

    if (!email || !token) {
      setLoading(false);
      setErrorMessage("Invalid or incomplete verification link. The link must include both email and verification token.");
      return;
    }

    setLoading(true);
    confirmUndergradRequestorEmail(email, token)
      .then(() => {
        setSuccess(true);
        setErrorMessage("");
      })
      .catch((err) => {
        setSuccess(false);
        const backendMsg =
          err?.response?.data?.errors?.token?.[0] ||
          err?.response?.data?.message ||
          "Email verification failed. The link may be invalid or expired.";
        setErrorMessage(backendMsg);
      })
      .finally(() => {
        setLoading(false);
      });
  }, [email, token]);

  const cardClasses = isDark ? "bg-[#242526] text-white border-[#3e4042]" : "bg-white text-gray-900 border-gray-100";

  return (
    <div className="relative min-h-screen w-full overflow-hidden">
      {/* Campus Background Layer */}
      <div className="fixed inset-0 z-0 pointer-events-none overflow-hidden">
        <LandingPage />
      </div>

      {/* Fullscreen Overlay with Campus Backdrop Blur */}
      <div className="fixed inset-0 z-50 flex items-center justify-center p-4 overflow-y-auto custom-scrollbar bg-black/60 backdrop-blur-md">
        <div className={`w-full max-w-sm my-auto rounded-2xl p-6 shadow-2xl border font-sans text-center relative z-10 transition-all ${cardClasses}`}>
          {/* Top Close / Return to Home Button */}
          <Link
            to="/"
            className="absolute top-3.5 right-3.5 p-1.5 rounded-full text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 hover:bg-gray-100 dark:hover:bg-[#3a3b3c] transition-colors cursor-pointer"
            aria-label="Close"
            title="Return to Home"
          >
            <svg className="w-5 h-5" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </Link>

          {/* Centered PUP Seal */}
          <img src={logoImage} alt="PUP Logo" className="w-12 h-12 mx-auto mb-2 object-contain" />

          {loading ? (
            <div className="py-4 space-y-3">
              <div className="w-9 h-9 border-3 border-[#800000] dark:border-[#F8BF1E] border-t-transparent rounded-full animate-spin mx-auto" />
              <h2 className={`text-base font-bold ${isDark ? "text-white" : "text-[#800000]"}`}>
                Verifying Email...
              </h2>
              <p className={`text-xs ${isDark ? "text-[#b0b3b8]" : "text-gray-500"}`}>
                Please wait while we confirm your email verification token.
              </p>
            </div>
          ) : success ? (
            <div className="space-y-3 pt-1 w-full">
              <div className="w-12 h-12 bg-emerald-100 dark:bg-emerald-950/60 text-emerald-600 dark:text-emerald-400 rounded-full flex items-center justify-center mx-auto shadow-inner">
                <CheckCircleIcon className="w-7 h-7" />
              </div>

              <h2 className={`text-lg font-extrabold ${isDark ? "text-white" : "text-[#800000]"}`}>
                Email Confirmed!
              </h2>

              <p className={`text-xs leading-relaxed ${isDark ? "text-[#b0b3b8]" : "text-gray-600"}`}>
                Your email address has been confirmed. A Registrar Admin will review your submission shortly.
              </p>

              <div className="pt-2 w-full">
                <Link
                  to="/"
                  className="inline-block w-full px-5 py-2.5 rounded-xl font-bold text-xs bg-[#800000] text-white hover:bg-[#660000] transition-colors shadow-md text-center"
                >
                  Return to Home
                </Link>
              </div>
            </div>
          ) : (
            <div className="space-y-3 pt-1 w-full">
              <div className="w-12 h-12 bg-rose-100 dark:bg-rose-950/60 text-rose-600 dark:text-rose-400 rounded-full flex items-center justify-center mx-auto shadow-inner">
                <XCircleIcon className="w-7 h-7" />
              </div>

              <h2 className={`text-lg font-bold ${isDark ? "text-white" : "text-gray-900"}`}>
                Verification Failed
              </h2>

              <p className={`text-xs leading-relaxed ${isDark ? "text-[#b0b3b8]" : "text-gray-600"}`}>
                {errorMessage}
              </p>

              <div className="pt-2 flex flex-col gap-2 w-full">
                <Link
                  to="/undergrad-requestor/register"
                  className="px-5 py-2.5 rounded-xl font-bold text-xs bg-[#800000] text-white hover:bg-[#660000] transition-colors shadow-md text-center"
                >
                  Resubmit Form
                </Link>
                <Link
                  to="/"
                  className={`px-4 py-1.5 rounded-lg text-xs font-medium transition-colors text-center ${
                    isDark ? "text-[#b0b3b8] hover:text-white" : "text-gray-500 hover:text-gray-800"
                  }`}
                >
                  Back to Home
                </Link>
              </div>
            </div>
          )}
        </div>
      </div>
    </div>
  );
};

export default UndergradRequestorVerifyEmailPage;
