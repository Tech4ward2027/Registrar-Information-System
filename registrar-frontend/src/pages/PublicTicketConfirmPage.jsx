import React, { useState, useEffect } from "react";
import { Link, useNavigate } from "react-router-dom";
import { CheckCircleIcon, XCircleIcon, ShieldCheckIcon } from "@heroicons/react/24/outline";
import { confirmPublicTicketEmail } from "../services/api";
import LandingPage from "../layouts/LandingPage.jsx";
import logoImage from "../assets/puplogoimage.png";
import { parseTicketApiError } from "../utils/ticketHelpers";
import { useRetryAfterCooldown } from "../hooks/useTicketLiveUpdates";

export const PUBLIC_TICKET_TOKEN_STORAGE_KEY = "ris_public_ticket_token";

const extractTokenFromHash = () => {
  const rawHash = window.location.hash ? window.location.hash.slice(1) : "";
  if (!rawHash) return "";
  const params = new URLSearchParams(rawHash);
  if (params.get("token")) return params.get("token");
  return rawHash.startsWith("token=") ? decodeURIComponent(rawHash.slice(6)) : "";
};

const PublicTicketConfirmPage = () => {
  const navigate = useNavigate();
  const [token, setToken] = useState("");
  const [confirming, setConfirming] = useState(false);
  const [createdTicket, setCreatedTicket] = useState(null);
  const [errorMsg, setErrorMsg] = useState("");
  const cooldown = useRetryAfterCooldown();

  // Extract token from URL fragment and immediately strip it from history.
  // Deliberately does NOT auto-submit: email security scanners prefetch links,
  // and confirmation tokens are single-use.
  useEffect(() => {
    const extracted = extractTokenFromHash();
    if (extracted) {
      setToken(extracted);
      window.history.replaceState(null, "", window.location.pathname + window.location.search);
    }
  }, []);

  const handleConfirmClick = async () => {
    if (!token || confirming) return;
    setConfirming(true);
    setErrorMsg("");
    try {
      const res = await confirmPublicTicketEmail(token);
      const ticketData = res.data?.data || null;
      const trackingToken = res.data?.tracking_token || "";

      if (trackingToken) {
        try {
          sessionStorage.setItem(PUBLIC_TICKET_TOKEN_STORAGE_KEY, trackingToken);
        } catch {
          // ignore storage quota errors
        }
      }
      setCreatedTicket(ticketData);
    } catch (err) {
      const parsed = await parseTicketApiError(
        err,
        "Verification failed. This link may have already been used or expired."
      );
      if (parsed.status === 429) cooldown.startCooldown(parsed.retryAfterSeconds);
      setErrorMsg(parsed.message);
    } finally {
      setConfirming(false);
    }
  };

  return (
    <div className="relative min-h-screen w-full overflow-hidden">
      <div className="fixed inset-0 z-0 pointer-events-none overflow-hidden">
        <LandingPage />
      </div>

      <div className="fixed inset-0 z-50 flex items-center justify-center p-4 overflow-y-auto bg-black/65 backdrop-blur-md">
        <div
          style={{ colorScheme: "light" }}
          className="w-full max-w-md my-auto rounded-2xl bg-white text-gray-900 p-6 shadow-2xl border border-gray-200 font-sans text-center space-y-4"
        >
          <img src={logoImage} alt="PUP Logo" className="w-12 h-12 mx-auto object-contain" />

          {createdTicket ? (
            <div className="space-y-3">
              <div className="w-12 h-12 rounded-full bg-emerald-100 text-emerald-600 flex items-center justify-center mx-auto">
                <CheckCircleIcon className="w-7 h-7" />
              </div>
              <h1 className="text-lg font-extrabold text-[#800000]">Support Ticket Created!</h1>
              <p className="text-xs text-gray-600 leading-relaxed">
                Your email has been verified and your ticket{" "}
                <span className="font-black text-[#800000]">{createdTicket.ticket_number}</span> is now in the
                Registrar&apos;s queue. We also emailed you a tracking link for future visits.
              </p>
              <div className="pt-2 space-y-2">
                <button
                  type="button"
                  onClick={() => navigate("/support/ticket")}
                  className="w-full px-5 py-2.5 rounded-xl text-xs font-bold bg-[#800000] hover:bg-[#660000] text-white shadow cursor-pointer"
                >
                  Open &amp; Track Ticket Now
                </button>
                <Link to="/" className="block text-xs font-semibold text-gray-500 hover:text-gray-800 pt-1">
                  Return to Home
                </Link>
              </div>
            </div>
          ) : !token ? (
            <div className="space-y-3">
              <div className="w-12 h-12 rounded-full bg-rose-100 text-rose-600 flex items-center justify-center mx-auto">
                <XCircleIcon className="w-7 h-7" />
              </div>
              <h1 className="text-lg font-bold text-gray-900">Missing Verification Token</h1>
              <p className="text-xs text-gray-600 leading-relaxed">
                This confirmation link is incomplete or was already used. Please open the full link from your email or
                submit a new support inquiry.
              </p>
              <div className="pt-2 flex flex-col gap-2">
                <Link
                  to="/support"
                  className="w-full px-5 py-2.5 rounded-xl text-xs font-bold bg-[#800000] hover:bg-[#660000] text-white shadow"
                >
                  Go to Support Desk
                </Link>
                <Link to="/" className="text-xs font-semibold text-gray-500 hover:text-gray-800">
                  Return to Home
                </Link>
              </div>
            </div>
          ) : (
            <div className="space-y-4">
              <div className="w-12 h-12 rounded-full bg-amber-100 text-[#800000] flex items-center justify-center mx-auto">
                <ShieldCheckIcon className="w-7 h-7" />
              </div>
              <h1 className="text-lg font-extrabold text-[#800000]">Confirm Your Support Ticket</h1>
              <p className="text-xs text-gray-600 leading-relaxed">
                Click the button below to confirm your email address and officially submit your ticket to the PUP
                Taguig Registrar&apos;s Office.
              </p>

              {errorMsg && (
                <div className="p-3 rounded-xl bg-rose-50 border border-rose-200 text-rose-800 text-xs text-left font-medium">
                  {errorMsg}
                </div>
              )}

              <div className="pt-1 space-y-2">
                <button
                  type="button"
                  disabled={confirming || cooldown.isCoolingDown}
                  onClick={handleConfirmClick}
                  className="w-full px-5 py-2.5 rounded-xl text-xs font-bold bg-[#800000] hover:bg-[#660000] text-white shadow disabled:opacity-50 cursor-pointer"
                >
                  {cooldown.isCoolingDown
                    ? `Try again in ${cooldown.secondsLeft}s`
                    : confirming
                    ? "Confirming & Creating Ticket…"
                    : "Confirm Email & Create Ticket"}
                </button>
                <Link to="/support" className="block text-xs font-semibold text-gray-500 hover:text-gray-800 pt-1">
                  Submit a new inquiry instead
                </Link>
              </div>
            </div>
          )}
        </div>
      </div>
    </div>
  );
};

export default PublicTicketConfirmPage;

