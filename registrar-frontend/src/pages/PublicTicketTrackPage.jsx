import React, { useState, useEffect, useCallback } from "react";
import { Link } from "react-router-dom";
import {
  ArrowPathIcon,
  CheckCircleIcon,
  ArrowUturnLeftIcon,
  PaperAirplaneIcon,
  LockClosedIcon,
  ExclamationTriangleIcon,
  ClockIcon,
  XMarkIcon,
} from "@heroicons/react/24/outline";
import {
  getPublicTicket,
  replyPublicTicket,
  confirmPublicTicketResolution,
  returnPublicTicket,
  resendPublicTicketLink,
} from "../services/api";
import LandingPage from "../layouts/LandingPage.jsx";
import InputGroup from "../components/InputGroup.jsx";
import VoiceTextareaInput from "../components/VoiceTextareaInput.jsx";
import ConfirmationModal from "../components/ConfirmationModal.jsx";
import logoImage from "../assets/puplogoimage.png";
import { PUBLIC_TICKET_TOKEN_STORAGE_KEY } from "./PublicTicketConfirmPage.jsx";
import {
  getStatusBadgeClass,
  getPriorityBadgeClass,
  formatPriorityLabel,
  formatTicketDateTime,
  formatTicketRelative,
  parseTicketApiError,
} from "../utils/ticketHelpers";
import { useRetryAfterCooldown } from "../hooks/useTicketLiveUpdates";

const extractHashToken = () => {
  const rawHash = window.location.hash ? window.location.hash.slice(1) : "";
  if (!rawHash) return "";
  const params = new URLSearchParams(rawHash);
  if (params.get("token")) return params.get("token");
  return rawHash.startsWith("token=") ? decodeURIComponent(rawHash.slice(6)) : "";
};

const PublicTicketTrackPage = () => {
  const [token, setToken] = useState(() => {
    const fromHash = extractHashToken();
    if (fromHash) return fromHash;
    try {
      return sessionStorage.getItem(PUBLIC_TICKET_TOKEN_STORAGE_KEY) || "";
    } catch {
      return "";
    }
  });

  const [ticket, setTicket] = useState(null);
  const [loading, setLoading] = useState(Boolean(token));
  const [invalidLink, setInvalidLink] = useState(!token);
  const [bannerMsg, setBannerMsg] = useState("");
  const [errorMsg, setErrorMsg] = useState("");

  // Reply / Confirm / Return state
  const [replyBody, setReplyBody] = useState("");
  const [replyFieldError, setReplyFieldError] = useState("");
  const [replying, setReplying] = useState(false);
  const replyCooldown = useRetryAfterCooldown();

  const [isConfirmOpen, setIsConfirmOpen] = useState(false);
  const [confirming, setConfirming] = useState(false);
  const [isReturnModalOpen, setIsReturnModalOpen] = useState(false);
  const [returnReason, setReturnReason] = useState("");
  const [returning, setReturning] = useState(false);

  // Resend link fallback state
  const [resendEmail, setResendEmail] = useState("");
  const [resendNumber, setResendNumber] = useState("");
  const [resending, setResending] = useState(false);
  const [resendNotice, setResendNotice] = useState("");
  const resendCooldown = useRetryAfterCooldown();

  // Strip token from URL fragment immediately and persist only in sessionStorage
  useEffect(() => {
    const fromHash = extractHashToken();
    if (fromHash) {
      window.history.replaceState(null, "", window.location.pathname + window.location.search);
      try {
        sessionStorage.setItem(PUBLIC_TICKET_TOKEN_STORAGE_KEY, fromHash);
      } catch {
        // ignore
      }
      setToken(fromHash);
      setInvalidLink(false);
    }
  }, []);

  const loadTicket = useCallback(
    async (silent = false) => {
      if (!token) {
        setInvalidLink(true);
        setLoading(false);
        return;
      }
      if (!silent) setLoading(true);
      setErrorMsg("");
      try {
        const res = await getPublicTicket(token);
        setTicket(res.data?.data || null);
        setInvalidLink(false);
      } catch (err) {
        const parsed = await parseTicketApiError(err, "Unable to load ticket.");
        if (parsed.status === 404) {
          setInvalidLink(true);
          setTicket(null);
        } else {
          setErrorMsg(parsed.message);
        }
      } finally {
        if (!silent) setLoading(false);
      }
    },
    [token]
  );

  useEffect(() => {
    if (token) {
      loadTicket(false);
    }
  }, [token, loadTicket]);

  const handleReply = async (e) => {
    e.preventDefault();
    if (!token || !replyBody.trim() || replying) return;
    setReplyFieldError("");
    setErrorMsg("");
    setBannerMsg("");
    setReplying(true);
    try {
      const res = await replyPublicTicket(token, replyBody.trim());
      setTicket(res.data?.data || ticket);
      setReplyBody("");
      setBannerMsg("Your reply has been added to the ticket.");
    } catch (err) {
      const parsed = await parseTicketApiError(err, "Failed to post reply.");
      if (parsed.status === 429) replyCooldown.startCooldown(parsed.retryAfterSeconds);
      if (parsed.fieldErrors?.body) setReplyFieldError(parsed.fieldErrors.body[0]);
      setErrorMsg(parsed.message);
      if (parsed.status === 409) loadTicket(true);
    } finally {
      setReplying(false);
    }
  };

  const handleConfirm = async () => {
    if (!token || confirming) return;
    setConfirming(true);
    setErrorMsg("");
    setBannerMsg("");
    try {
      const res = await confirmPublicTicketResolution(token);
      setTicket(res.data?.data || ticket);
      setBannerMsg("Thank you! Your support ticket is now closed.");
    } catch (err) {
      const parsed = await parseTicketApiError(err, "Could not confirm resolution.");
      setErrorMsg(parsed.message);
      if (parsed.status === 409) loadTicket(true);
    } finally {
      setConfirming(false);
    }
  };

  const handleReturn = async (e) => {
    e.preventDefault();
    if (!token || returning) return;
    setReturning(true);
    setErrorMsg("");
    setBannerMsg("");
    try {
      const res = await returnPublicTicket(token, returnReason.trim() || null);
      setTicket(res.data?.data || ticket);
      setIsReturnModalOpen(false);
      setReturnReason("");
      setBannerMsg("Ticket returned to In Progress for further review.");
    } catch (err) {
      const parsed = await parseTicketApiError(err, "Could not return ticket.");
      setErrorMsg(parsed.message);
      if (parsed.status === 409) loadTicket(true);
    } finally {
      setReturning(false);
    }
  };

  const handleResendLink = async (e) => {
    e.preventDefault();
    setErrorMsg("");
    setResendNotice("");
    if (!resendEmail.trim() || !resendNumber.trim()) {
      setErrorMsg("Please enter both your email address and ticket number.");
      return;
    }
    setResending(true);
    try {
      const res = await resendPublicTicketLink(resendEmail.trim(), resendNumber.trim().toUpperCase());
      setResendNotice(
        res.data?.message ||
          "If an active public ticket matches that email and ticket number, a fresh tracking link has been sent."
      );
    } catch (err) {
      const parsed = await parseTicketApiError(err, "Unable to resend tracking link.");
      if (parsed.status === 429) resendCooldown.startCooldown(parsed.retryAfterSeconds);
      setErrorMsg(parsed.message);
    } finally {
      setResending(false);
    }
  };

  return (
    <div className="relative min-h-screen w-full overflow-hidden">
      <div className="fixed inset-0 z-0 pointer-events-none overflow-hidden">
        <LandingPage />
      </div>

      <div className="fixed inset-0 z-50 flex items-center justify-center p-4 overflow-y-auto custom-scrollbar bg-black/65 backdrop-blur-md">
        <div
          style={{ colorScheme: "light" }}
          className="w-full max-w-3xl my-auto rounded-2xl bg-white text-gray-900 shadow-2xl border border-[#800000]/20 font-sans overflow-hidden"
        >
          {/* Header */}
          <div className="bg-[#800000] text-white px-6 py-4 border-b-4 border-[#FFD700] flex items-center justify-between gap-3">
            <div className="flex items-center gap-3 min-w-0">
              <img src={logoImage} alt="PUP Logo" className="w-10 h-10 shrink-0 object-contain" />
              <div className="min-w-0">
                <span className="text-[10px] sm:text-xs text-amber-200 font-bold uppercase tracking-wider block">
                  PUP Taguig Registrar&apos;s Office
                </span>
                <h1 className="text-sm sm:text-base font-black uppercase tracking-tight truncate">
                  Support Ticket Tracker
                </h1>
              </div>
            </div>
            <div className="flex items-center gap-2 shrink-0">
              {ticket && (
                <button
                  type="button"
                  onClick={() => loadTicket(true)}
                  className="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-xs font-bold bg-white/10 hover:bg-white/20 text-white cursor-pointer"
                >
                  <ArrowPathIcon className="w-3.5 h-3.5" />
                  <span>Refresh</span>
                </button>
              )}
              <Link
                to="/support"
                className="px-3 py-1.5 rounded-lg text-xs font-bold bg-white/10 hover:bg-white/20 text-white"
              >
                Support Desk
              </Link>
            </div>
          </div>

          <div className="p-6 space-y-5">
            {errorMsg && (
              <div className="p-3 rounded-xl bg-rose-50 border border-rose-200 text-rose-800 text-xs font-medium">
                {errorMsg}
              </div>
            )}

            {bannerMsg && (
              <div className="p-3 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-800 text-xs font-semibold">
                {bannerMsg}
              </div>
            )}

            {loading ? (
              <div className="py-12 text-center space-y-3">
                <div className="w-8 h-8 border-3 border-[#800000] border-t-transparent rounded-full animate-spin mx-auto" />
                <p className="text-xs font-semibold text-gray-500">Loading your support ticket…</p>
              </div>
            ) : invalidLink || !ticket ? (
              <div className="space-y-4 py-2">
                <div className="text-center space-y-2">
                  <ExclamationTriangleIcon className="w-10 h-10 text-amber-500 mx-auto" />
                  <h2 className="text-base font-extrabold text-gray-900">Tracking Link Needed or Expired</h2>
                  <p className="text-xs text-gray-600 max-w-md mx-auto leading-relaxed">
                    Open the magic tracking link from your email, or enter your email address and ticket number below to
                    have a fresh link sent to you.
                  </p>
                </div>

                {resendNotice && (
                  <div className="p-3.5 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-900 text-xs">
                    {resendNotice}
                  </div>
                )}

                <form onSubmit={handleResendLink} noValidate className="max-w-md mx-auto space-y-3.5 pt-1">
                  <InputGroup
                    type="email"
                    label="Email Address"
                    name="resend_email"
                    value={resendEmail}
                    onChange={(e) => setResendEmail(e.target.value)}
                    placeholder="you@example.com"
                    required
                    voiceEnabled={false}
                    isDark={false}
                    labelColor="text-gray-700"
                  />

                  <InputGroup
                    type="text"
                    label="Ticket Number"
                    name="resend_number"
                    value={resendNumber}
                    onChange={(e) => setResendNumber(e.target.value.toUpperCase())}
                    placeholder="TKT-2026-00001"
                    required
                    voiceEnabled={false}
                    isDark={false}
                    labelColor="text-gray-700"
                  />

                  <button
                    type="submit"
                    disabled={resending || resendCooldown.isCoolingDown}
                    className="w-full py-2.5 rounded-xl text-xs font-bold bg-[#800000] hover:bg-[#4a0000] text-[#FFD700] uppercase tracking-wider shadow disabled:opacity-50 cursor-pointer"
                  >
                    {resendCooldown.isCoolingDown
                      ? `Wait ${resendCooldown.secondsLeft}s`
                      : resending
                        ? "Sending…"
                        : "Email Me a Fresh Tracking Link"}
                  </button>
                </form>
              </div>
            ) : (
              <>
                {/* Ticket Header */}
                <div className="rounded-xl p-4 border border-gray-200 bg-gray-50/70 space-y-2">
                  <div className="flex flex-wrap items-center justify-between gap-2">
                    <div className="flex flex-wrap items-center gap-2">
                      <span className="px-2.5 py-0.5 rounded-lg text-xs font-black bg-[#800000]/10 text-[#800000]">
                        {ticket.ticket_number}
                      </span>
                      <span
                        className={`px-2.5 py-0.5 rounded-full text-xs font-bold border ${getStatusBadgeClass(
                          ticket.status,
                          false
                        )}`}
                      >
                        {ticket.status_label || ticket.status}
                      </span>
                      <span
                        className={`px-2.5 py-0.5 rounded-full text-xs font-semibold border ${getPriorityBadgeClass(
                          ticket.priority,
                          false
                        )}`}
                      >
                        {formatPriorityLabel(ticket.priority)} Priority
                      </span>
                    </div>
                    <span className="text-[11px] text-gray-500">Filed {formatTicketDateTime(ticket.created_at)}</span>
                  </div>

                  <h2 className="text-base sm:text-lg font-bold text-gray-900">{ticket.subject}</h2>
                  {ticket.category && (
                    <p className="text-xs text-gray-500">
                      Category: <span className="font-semibold text-gray-700">{ticket.category.name}</span>
                    </p>
                  )}

                  {ticket.status === "pending_client" && (
                    <div className="mt-2 p-3 rounded-xl bg-amber-50 border border-amber-200 text-amber-900 text-xs flex items-start gap-2">
                      <ClockIcon className="w-4 h-4 shrink-0 mt-0.5 text-amber-600" />
                      <span>
                        <strong>Action Needed:</strong> Registrar Staff requested additional details. Please reply below.
                      </span>
                    </div>
                  )}

                  {ticket.status === "resolved" && (
                    <div className="mt-2 p-3.5 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-950 space-y-2.5">
                      <div>
                        <p className="text-xs font-bold text-emerald-900">Ticket Marked Resolved</p>
                        {ticket.resolution_note && (
                          <p className="text-xs mt-1 whitespace-pre-wrap">{ticket.resolution_note}</p>
                        )}
                        <div className="text-[11px] text-emerald-800 mt-1.5 flex flex-wrap gap-3">
                          {ticket.auto_close_at && (
                            <span>Auto-closes on {formatTicketDateTime(ticket.auto_close_at)}</span>
                          )}
                          {typeof ticket.returns_remaining === "number" && (
                            <span>Returns remaining: {ticket.returns_remaining}</span>
                          )}
                        </div>
                      </div>

                      <div className="flex flex-wrap gap-2">
                        {ticket.actions?.can_confirm && (
                          <button
                            type="button"
                            disabled={confirming}
                            onClick={() => setIsConfirmOpen(true)}
                            className="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-lg text-xs font-bold bg-emerald-600 hover:bg-emerald-700 text-white disabled:opacity-50 cursor-pointer"
                          >
                            <CheckCircleIcon className="w-4 h-4" />
                            <span>{confirming ? "Confirming…" : "Confirm Resolution"}</span>
                          </button>
                        )}
                        {ticket.actions?.can_return && (
                          <button
                            type="button"
                            onClick={() => setIsReturnModalOpen(true)}
                            className="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-lg text-xs font-bold bg-white border border-emerald-300 text-emerald-900 hover:bg-emerald-100/60 cursor-pointer"
                          >
                            <ArrowUturnLeftIcon className="w-4 h-4" />
                            <span>This Isn&apos;t Resolved</span>
                          </button>
                        )}
                      </div>
                    </div>
                  )}

                  {ticket.status === "closed" && (
                    <div className="mt-2 p-3.5 rounded-xl bg-gray-100 border border-gray-200 text-gray-700 text-xs flex items-start gap-2.5">
                      <LockClosedIcon className="w-4 h-4 shrink-0 mt-0.5 text-gray-500" />
                      <div>
                        <p className="font-bold">This ticket is closed and read-only.</p>
                        {ticket.resolution_note && <p className="mt-1 whitespace-pre-wrap">{ticket.resolution_note}</p>}
                        <p className="mt-1 text-[11px] text-gray-500">
                          Closed {formatTicketDateTime(ticket.closed_at)}. If you have a new question,{" "}
                          <Link to="/support" className="underline font-semibold text-[#800000]">
                            submit a new ticket
                          </Link>
                          .
                        </p>
                      </div>
                    </div>
                  )}
                </div>

                {/* Conversation */}
                <div className="rounded-xl border border-gray-200 overflow-hidden">
                  <div className="px-4 py-2.5 bg-gray-50 border-b border-gray-200 text-xs font-bold uppercase tracking-wider text-gray-600">
                    Conversation ({ticket.messages?.length || 0})
                  </div>
                  <div className="p-4 space-y-3 max-h-[42vh] overflow-y-auto custom-scrollbar">
                    {(ticket.messages || []).map((msg) => {
                      const isMe = msg.sender_type === "requester";
                      const isSystem = msg.sender_type === "system";
                      if (isSystem) {
                        return (
                          <div key={msg.id} className="text-center">
                            <span className="inline-block px-3 py-1 rounded-full text-[11px] bg-gray-100 text-gray-600 border border-gray-200">
                              {msg.body} • {formatTicketRelative(msg.created_at)}
                            </span>
                          </div>
                        );
                      }
                      return (
                        <div key={msg.id} className={`flex flex-col ${isMe ? "items-end" : "items-start"}`}>
                          <div className="flex items-center gap-2 mb-0.5 px-1">
                            <span className="text-[11px] font-bold text-gray-700">
                              {msg.sender_label || (isMe ? "You" : "Registrar Staff")}
                            </span>
                            <span className="text-[10px] text-gray-400">{formatTicketDateTime(msg.created_at)}</span>
                          </div>
                          <div
                            className={`max-w-[85%] rounded-2xl px-4 py-2.5 text-sm whitespace-pre-wrap break-words ${
                              isMe
                                ? "bg-[#800000] text-white rounded-br-sm"
                                : "bg-gray-100 text-gray-900 border border-gray-200 rounded-bl-sm"
                            }`}
                          >
                            {msg.body}
                          </div>
                        </div>
                      );
                    })}
                  </div>

                  {ticket.actions?.can_reply ? (
                    <form onSubmit={handleReply} className="p-4 bg-gray-50 border-t border-gray-200 space-y-3">
                      {ticket.status === "resolved" && (
                        <p className="text-[11px] text-amber-800 font-medium">
                          Replying while Resolved will return this ticket to In Progress and use 1 remaining return.
                        </p>
                      )}

                      <VoiceTextareaInput
                        id="public-reply-body"
                        label="Your Reply"
                        labelColor="text-gray-700"
                        isDark={false}
                        minHeightClass="min-h-24"
                        maxLength={5000}
                        value={replyBody}
                        onChange={(val) => {
                          setReplyBody(val);
                          if (replyFieldError) setReplyFieldError("");
                        }}
                        placeholder="Write or dictate a reply… (at most 2 links allowed)"
                        error={replyFieldError || undefined}
                      />

                      <div className="flex justify-end">
                        <button
                          type="submit"
                          disabled={replying || !replyBody.trim() || replyCooldown.isCoolingDown}
                          className="inline-flex items-center gap-1.5 px-5 py-2.5 rounded-xl text-xs font-bold bg-[#800000] hover:bg-[#4a0000] text-[#FFD700] uppercase tracking-wider shadow disabled:opacity-50 cursor-pointer"
                        >
                          <PaperAirplaneIcon className="w-4 h-4" />
                          <span>
                            {replyCooldown.isCoolingDown
                              ? `Wait ${replyCooldown.secondsLeft}s`
                              : replying
                                ? "Sending…"
                                : "Send Reply"}
                          </span>
                        </button>
                      </div>
                    </form>
                  ) : (
                    <div className="px-4 py-2.5 bg-gray-50 border-t border-gray-200 text-center text-xs text-gray-500">
                      Replies are no longer available for this ticket.
                    </div>
                  )}
                </div>
              </>
            )}
          </div>
        </div>
      </div>

      {/* Confirm Resolution Modal */}
      <ConfirmationModal
        isOpen={isConfirmOpen}
        onClose={() => setIsConfirmOpen(false)}
        onConfirm={handleConfirm}
        type="confirm"
        isDark={false}
        title="Confirm Ticket Resolution?"
        message="Confirming resolution will close this support ticket permanently. Proceed?"
        confirmText="Confirm & Close"
      />

      {/* Return ("This Isn't Resolved") Modal */}
      {isReturnModalOpen && (
        <div
          style={{ colorScheme: "light" }}
          className="fixed inset-0 z-9999 flex items-center justify-center p-4 backdrop-blur-sm bg-black/60 overflow-y-auto font-sans"
        >
          <div className="relative z-9999 w-full max-w-lg my-auto rounded-xl shadow-[0_20px_50px_rgba(0,0,0,0.4)] overflow-hidden border border-[#800000]/20 bg-white text-gray-900 flex flex-col max-h-[85vh]">
            <div className="px-6 py-4 bg-[#800000] border-b-4 border-[#FFD700] shrink-0 flex items-center justify-between gap-3">
              <div className="min-w-0 flex-1">
                <span className="text-[10px] sm:text-xs text-amber-200 font-bold uppercase tracking-wider block">
                  Support Ticket {ticket?.ticket_number || ""}
                </span>
                <h3 className="text-base sm:text-lg text-white font-black uppercase tracking-tighter mt-0.5 truncate">
                  Return Ticket to In Progress
                </h3>
              </div>
              <button
                type="button"
                onClick={() => setIsReturnModalOpen(false)}
                className="p-1 rounded hover:opacity-90 shrink-0 text-white cursor-pointer"
                title="Close"
              >
                <XMarkIcon className="w-5 h-5" />
              </button>
            </div>

            <form onSubmit={handleReturn} className="flex flex-col flex-1 overflow-hidden">
              <div className="flex-1 overflow-y-auto p-6 space-y-4">
                <p className="text-xs text-gray-600 leading-relaxed">
                  Let the Registrar&apos;s Office know what still needs attention. You have{" "}
                  <span className="font-bold text-gray-900">{ticket?.returns_remaining ?? 0}</span> return(s) left on
                  this ticket.
                </p>

                <VoiceTextareaInput
                  id="public-return-reason"
                  label="Reason for Returning (Optional)"
                  labelColor="text-gray-700"
                  isDark={false}
                  minHeightClass="min-h-28"
                  maxLength={5000}
                  value={returnReason}
                  onChange={setReturnReason}
                  placeholder="Optional: let us know what still needs help…"
                />
              </div>

              <div className="px-6 py-4 bg-gray-50 border-t-2 border-gray-200 shrink-0 flex items-center justify-end gap-2.5">
                <button
                  type="button"
                  onClick={() => setIsReturnModalOpen(false)}
                  className="px-4 py-2 rounded-lg text-xs font-semibold bg-gray-200/80 hover:bg-gray-300/70 text-gray-700 cursor-pointer"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  disabled={returning}
                  className="px-5 py-2 rounded-lg text-xs font-bold uppercase tracking-wider bg-[#800000] hover:bg-[#4a0000] text-[#FFD700] shadow disabled:opacity-50 cursor-pointer"
                >
                  {returning ? "Sending…" : "Send Back to Staff"}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  );
};

export default PublicTicketTrackPage;
