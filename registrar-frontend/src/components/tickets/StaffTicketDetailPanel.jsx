import React, { useState, useEffect, useCallback, useRef } from "react";
import { useNavigate } from "react-router-dom";
import {
  ArrowLeftIcon,
  HandRaisedIcon,
  ArrowUturnLeftIcon,
  CheckBadgeIcon,
  ShieldExclamationIcon,
  PaperAirplaneIcon,
  ClockIcon,
  LockClosedIcon,
  CheckCircleIcon,
  UserIcon,
  GlobeAltIcon,
  ArrowPathIcon,
  XMarkIcon,
  ListBulletIcon,
  ExclamationTriangleIcon,
} from "@heroicons/react/24/outline";
import { useTheme } from "../../context/ThemeContext";
import { useAuth } from "../../context/AuthProvider";
import {
  getStaffTicket,
  claimTicket,
  releaseTicket,
  replyAsStaff,
  resolveTicket,
  flagTicketSpam,
} from "../../services/api";
import { MODULE_KEYS, hasModuleAction } from "../../utils/policy";
import {
  getStatusBadgeClass,
  getPriorityBadgeClass,
  getSlaBadgeClass,
  formatPriorityLabel,
  formatSlaStateLabel,
  formatEventLabel,
  formatTicketDateTime,
  formatTicketRelative,
  parseTicketApiError,
} from "../../utils/ticketHelpers";
import {
  useTicketLiveUpdates,
  useRetryAfterCooldown,
} from "../../hooks/useTicketLiveUpdates";
import InputGroup from "../InputGroup.jsx";
import VoiceTextareaInput from "../VoiceTextareaInput.jsx";
import CheckboxItem from "../Checkbox.jsx";
import SuccessToast from "../SuccessToast.jsx";
import ErrorToast from "../ErrorToast.jsx";

const StaffTicketDetailPanel = ({ ticketId, baseTicketsPath }) => {
  const { isDark } = useTheme();
  const { user } = useAuth();
  const navigate = useNavigate();

  const canManage = hasModuleAction(user, MODULE_KEYS.TICKETS, "Manage");

  const [ticket, setTicket] = useState(null);
  const [loading, setLoading] = useState(true);
  const [notFoundError, setNotFoundError] = useState("");

  // Action states
  const [actionBusy, setActionBusy] = useState(""); // "claim" | "release" | "reply" | "resolve" | "spam"
  const [replyBody, setReplyBody] = useState("");
  const [awaitRequester, setAwaitRequester] = useState(false);
  const [replyFieldErrors, setReplyFieldErrors] = useState({});

  // Resolve modal
  const [isResolveOpen, setIsResolveOpen] = useState(false);
  const [resolutionNote, setResolutionNote] = useState("");
  const [resolveFieldErrors, setResolveFieldErrors] = useState({});

  // Flag Spam modal (Manage permission)
  const [isSpamOpen, setIsSpamOpen] = useState(false);
  const [spamForm, setSpamForm] = useState({
    reason: "",
    block_email: false,
    block_ip: false,
    block_expires_at: "",
  });
  const [spamFieldErrors, setSpamFieldErrors] = useState({});

  const [successMsg, setSuccessMsg] = useState("");
  const [errorMsg, setErrorMsg] = useState("");
  const cooldown = useRetryAfterCooldown();

  const fetchDetail = useCallback(
    async ({ silent = false } = {}) => {
      if (!ticketId) return;
      if (!silent) {
        setLoading(true);
        setNotFoundError("");
      }
      try {
        const res = await getStaffTicket(ticketId);
        setTicket(res.data?.data || null);
      } catch (err) {
        const parsed = await parseTicketApiError(err, "Unable to load ticket details.");
        if (parsed.status === 429) cooldown.startCooldown(parsed.retryAfterSeconds);
        if (!silent) {
          if (parsed.status === 404 || parsed.status === 403) {
            setNotFoundError(parsed.message);
          } else {
            setErrorMsg(parsed.message);
          }
        }
      } finally {
        if (!silent) setLoading(false);
      }
    },
    [ticketId, cooldown.startCooldown]
  );

  useEffect(() => {
    fetchDetail({ silent: false });
  }, [fetchDetail]);

  // Subscribe to live Reverb updates for this ticket and staff queue
  const liveTimerRef = useRef(null);
  useTicketLiveUpdates({
    staffQueue: true,
    ticketId,
    enabled: Boolean(ticketId),
    onTicketUpdated: useCallback(
      (payload) => {
        if (
          payload?.reconnected ||
          !payload?.ticket_id ||
          payload.ticket_id === ticketId
        ) {
          if (liveTimerRef.current) clearTimeout(liveTimerRef.current);
          liveTimerRef.current = setTimeout(() => {
            fetchDetail({ silent: true });
          }, 350);
        }
      },
      [ticketId, fetchDetail]
    ),
  });

  useEffect(() => {
    return () => {
      if (liveTimerRef.current) clearTimeout(liveTimerRef.current);
    };
  }, []);

  const handleClaim = async () => {
    if (!ticket?.id || actionBusy || cooldown.isCoolingDown) return;
    setActionBusy("claim");
    setErrorMsg("");
    try {
      const res = await claimTicket(ticket.id);
      if (res.data?.data) setTicket(res.data.data);
      setSuccessMsg(`You claimed ${ticket.ticket_number}.`);
    } catch (err) {
      const parsed = await parseTicketApiError(err, "Failed to claim ticket.");
      if (parsed.status === 429) cooldown.startCooldown(parsed.retryAfterSeconds);
      setErrorMsg(parsed.message);
      fetchDetail({ silent: true });
    } finally {
      setActionBusy("");
    }
  };

  const handleRelease = async () => {
    if (!ticket?.id || actionBusy || cooldown.isCoolingDown) return;
    setActionBusy("release");
    setErrorMsg("");
    try {
      const res = await releaseTicket(ticket.id);
      if (res.data?.data) setTicket(res.data.data);
      setSuccessMsg(`Released ${ticket.ticket_number} back to the shared queue.`);
    } catch (err) {
      const parsed = await parseTicketApiError(err, "Failed to release ticket.");
      if (parsed.status === 429) cooldown.startCooldown(parsed.retryAfterSeconds);
      setErrorMsg(parsed.message);
      fetchDetail({ silent: true });
    } finally {
      setActionBusy("");
    }
  };

  const handleReplySubmit = async (e) => {
    e.preventDefault();
    if (!ticket?.id || actionBusy || cooldown.isCoolingDown) return;

    const trimmed = replyBody.trim();
    if (!trimmed) {
      setReplyFieldErrors({ body: ["Please enter a reply message."] });
      return;
    }

    setReplyFieldErrors({});
    setErrorMsg("");
    setActionBusy("reply");
    try {
      const res = await replyAsStaff(ticket.id, trimmed, awaitRequester);
      if (res.data?.data) {
        setTicket(res.data.data);
      } else {
        await fetchDetail({ silent: true });
      }
      setReplyBody("");
      setAwaitRequester(false);
      setSuccessMsg(
        awaitRequester
          ? "Reply sent. Status moved to Pending Client and SLA paused."
          : "Reply sent to requester."
      );
    } catch (err) {
      const parsed = await parseTicketApiError(err, "Failed to send reply.");
      if (parsed.status === 429) cooldown.startCooldown(parsed.retryAfterSeconds);
      if (Object.keys(parsed.fieldErrors).length > 0) {
        setReplyFieldErrors(parsed.fieldErrors);
      }
      setErrorMsg(parsed.message);
      if (parsed.status === 409) fetchDetail({ silent: true });
    } finally {
      setActionBusy("");
    }
  };

  const handleResolveSubmit = async (e) => {
    e.preventDefault();
    if (!ticket?.id || actionBusy || cooldown.isCoolingDown) return;

    const trimmed = resolutionNote.trim();
    if (trimmed.length < 10) {
      setResolveFieldErrors({
        resolution_note: ["Resolution note must be at least 10 characters."],
      });
      return;
    }

    setResolveFieldErrors({});
    setErrorMsg("");
    setActionBusy("resolve");
    try {
      const res = await resolveTicket(ticket.id, trimmed);
      if (res.data?.data) setTicket(res.data.data);
      setIsResolveOpen(false);
      setResolutionNote("");
      setSuccessMsg(`Marked ${ticket.ticket_number} as Resolved.`);
    } catch (err) {
      const parsed = await parseTicketApiError(err, "Failed to resolve ticket.");
      if (parsed.status === 429) cooldown.startCooldown(parsed.retryAfterSeconds);
      if (Object.keys(parsed.fieldErrors).length > 0) {
        setResolveFieldErrors(parsed.fieldErrors);
      }
      setErrorMsg(parsed.message);
      if (parsed.status === 409) fetchDetail({ silent: true });
    } finally {
      setActionBusy("");
    }
  };

  const canBlockSubmitter =
    ticket?.requester?.type === "public" && Boolean(ticket?.requester?.email);

  const handleSpamSubmit = async (e) => {
    e.preventDefault();
    if (!ticket?.id || actionBusy || cooldown.isCoolingDown) return;

    setSpamFieldErrors({});
    setErrorMsg("");
    setActionBusy("spam");

    const payload = {};
    if (spamForm.reason.trim()) payload.reason = spamForm.reason.trim();
    if (canBlockSubmitter && spamForm.block_email) payload.block_email = true;
    if (canBlockSubmitter && spamForm.block_ip) payload.block_ip = true;
    if (
      canBlockSubmitter &&
      (spamForm.block_email || spamForm.block_ip) &&
      spamForm.block_expires_at
    ) {
      const dt = new Date(spamForm.block_expires_at);
      if (!Number.isNaN(dt.getTime())) {
        payload.block_expires_at = dt.toISOString();
      }
    }

    try {
      const res = await flagTicketSpam(ticket.id, payload);
      if (res.data?.data) setTicket(res.data.data);
      setIsSpamOpen(false);
      setSpamForm({
        reason: "",
        block_email: false,
        block_ip: false,
        block_expires_at: "",
      });
      setSuccessMsg("Spam flag recorded on ticket.");
    } catch (err) {
      const parsed = await parseTicketApiError(err, "Failed to flag ticket as spam.");
      if (parsed.status === 429) cooldown.startCooldown(parsed.retryAfterSeconds);
      if (Object.keys(parsed.fieldErrors).length > 0) {
        setSpamFieldErrors(parsed.fieldErrors);
      }
      setErrorMsg(parsed.message);
    } finally {
      setActionBusy("");
    }
  };

  if (loading) {
    return (
      <div
        className={`rounded-2xl p-12 border text-center ${
          isDark
            ? "bg-[#242526] border-[#3e4042] text-[#e4e6eb]"
            : "bg-white border-gray-200/80 shadow-sm text-gray-900"
        }`}
      >
        <ArrowPathIcon
          className={`w-7 h-7 mx-auto mb-3 animate-spin ${
            isDark ? "text-yellow-400" : "text-pup-dark-maroon"
          }`}
        />
        <p className={`text-xs ${isDark ? "text-gray-400" : "text-gray-500"}`}>
          Loading ticket workspace…
        </p>
      </div>
    );
  }

  if (notFoundError || !ticket) {
    return (
      <div
        className={`rounded-2xl p-10 border text-center space-y-4 ${
          isDark
            ? "bg-[#242526] border-[#3e4042] text-[#e4e6eb]"
            : "bg-white border-gray-200/80 shadow-sm text-gray-900"
        }`}
      >
        <ExclamationTriangleIcon className="w-10 h-10 mx-auto text-amber-500" />
        <div>
          <h2 className="text-base font-bold mb-1">Ticket Unavailable</h2>
          <p className={`text-xs ${isDark ? "text-gray-400" : "text-gray-500"}`}>
            {notFoundError || "The requested ticket could not be found."}
          </p>
        </div>
        <button
          type="button"
          onClick={() => navigate(baseTicketsPath)}
          className={`inline-flex items-center gap-1.5 px-4 py-2 rounded-xl text-xs font-bold cursor-pointer ${
            isDark
              ? "bg-yellow-400 text-black hover:bg-yellow-300"
              : "bg-pup-dark-maroon text-white hover:bg-[#5c0000]"
          }`}
        >
          <ArrowLeftIcon className="w-4 h-4" />
          Back to Ticket Queue
        </button>
      </div>
    );
  }

  const actions = ticket.actions || {};
  const messages = Array.isArray(ticket.messages) ? ticket.messages : [];
  const events = Array.isArray(ticket.events) ? ticket.events : [];

  return (
    <div className="space-y-4">
      {/* Top Bar: Back + Ticket Actions */}
      <div
        className={`rounded-2xl p-4 sm:p-5 border ${
          isDark
            ? "bg-[#242526] border-[#3e4042] text-[#e4e6eb]"
            : "bg-white border-gray-200/80 shadow-sm text-gray-900"
        }`}
      >
        <div className="flex flex-wrap items-center justify-between gap-3 mb-4">
          <button
            type="button"
            onClick={() => navigate(baseTicketsPath)}
            className={`inline-flex items-center gap-1.5 text-xs font-semibold px-3 py-1.5 rounded-xl border transition cursor-pointer ${
              isDark
                ? "bg-[#18191a] border-[#3e4042] text-gray-300 hover:text-white"
                : "bg-gray-50 border-gray-200 text-gray-700 hover:bg-gray-100"
            }`}
          >
            <ArrowLeftIcon className="w-3.5 h-3.5" />
            Back to Queue
          </button>

          <div className="flex flex-wrap items-center gap-2">
            <button
              type="button"
              onClick={() => fetchDetail({ silent: false })}
              disabled={Boolean(actionBusy) || cooldown.isCoolingDown}
              className={`inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-xs font-semibold border cursor-pointer disabled:opacity-50 ${
                isDark
                  ? "bg-[#18191a] border-[#3e4042] text-gray-300 hover:bg-[#303134]"
                  : "bg-white border-gray-200 text-gray-700 hover:bg-gray-50"
              }`}
            >
              <ArrowPathIcon className="w-3.5 h-3.5" />
              Refresh
            </button>

            {actions.can_claim && (
              <button
                type="button"
                onClick={handleClaim}
                disabled={Boolean(actionBusy) || cooldown.isCoolingDown}
                className={`inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-xl text-xs font-bold shadow-sm cursor-pointer disabled:opacity-50 ${
                  isDark
                    ? "bg-yellow-400 text-black hover:bg-yellow-300"
                    : "bg-pup-dark-maroon text-white hover:bg-[#5c0000]"
                }`}
              >
                <HandRaisedIcon className="w-4 h-4" />
                {actionBusy === "claim" ? "Claiming…" : "Claim Ticket"}
              </button>
            )}

            {actions.can_release && (
              <button
                type="button"
                onClick={handleRelease}
                disabled={Boolean(actionBusy) || cooldown.isCoolingDown}
                className={`inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-xl text-xs font-semibold border cursor-pointer disabled:opacity-50 ${
                  isDark
                    ? "bg-[#18191a] border-[#3e4042] text-amber-300 hover:bg-[#303134]"
                    : "bg-amber-50 border-amber-200 text-amber-800 hover:bg-amber-100"
                }`}
              >
                <ArrowUturnLeftIcon className="w-3.5 h-3.5" />
                {actionBusy === "release" ? "Releasing…" : "Release Claim"}
              </button>
            )}

            {actions.can_resolve && (
              <button
                type="button"
                onClick={() => {
                  setResolveFieldErrors({});
                  setIsResolveOpen(true);
                }}
                disabled={Boolean(actionBusy) || cooldown.isCoolingDown}
                className="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-xl text-xs font-bold bg-emerald-600 text-white hover:bg-emerald-700 shadow-sm cursor-pointer disabled:opacity-50"
              >
                <CheckBadgeIcon className="w-4 h-4" />
                Mark Resolved
              </button>
            )}

            {canManage && (
              <button
                type="button"
                onClick={() => {
                  setSpamFieldErrors({});
                  setIsSpamOpen(true);
                }}
                disabled={Boolean(actionBusy) || cooldown.isCoolingDown}
                className={`inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-xs font-semibold border cursor-pointer disabled:opacity-50 ${
                  isDark
                    ? "bg-rose-950/30 border-rose-800/60 text-rose-300 hover:bg-rose-950/50"
                    : "bg-rose-50 border-rose-200 text-rose-700 hover:bg-rose-100"
                }`}
              >
                <ShieldExclamationIcon className="w-3.5 h-3.5" />
                Flag Spam
              </button>
            )}
          </div>
        </div>

        {/* Ticket Header Summary */}
        <div className="space-y-2">
          <div className="flex flex-wrap items-center gap-2">
            <span
              className={`font-mono text-xs font-bold px-2.5 py-1 rounded-lg ${
                isDark
                  ? "bg-[#18191a] text-yellow-400 border border-[#3e4042]"
                  : "bg-red-50 text-pup-dark-maroon border border-red-100"
              }`}
            >
              {ticket.ticket_number}
            </span>

            <span
              className={`px-2.5 py-0.5 rounded-full text-xs font-bold border ${getPriorityBadgeClass(
                ticket.priority,
                isDark
              )}`}
            >
              {formatPriorityLabel(ticket.priority)} Priority
            </span>

            <span
              className={`px-2.5 py-0.5 rounded-full text-xs font-semibold border ${getStatusBadgeClass(
                ticket.status,
                isDark
              )}`}
            >
              {ticket.status_label || ticket.status}
            </span>

            {ticket.category && (
              <span
                className={`text-xs px-2.5 py-0.5 rounded-lg ${
                  isDark ? "bg-zinc-800 text-gray-300" : "bg-gray-100 text-gray-700"
                }`}
              >
                {ticket.category.code} — {ticket.category.name}
              </span>
            )}

            {ticket.requester_returns > 0 && (
              <span
                className={`text-xs font-semibold px-2.5 py-0.5 rounded-lg border ${
                  isDark
                    ? "bg-rose-950/40 text-rose-300 border-rose-800/60"
                    : "bg-rose-50 text-rose-700 border-rose-200"
                }`}
              >
                Returned by requester: {ticket.requester_returns} / 2
              </span>
            )}
          </div>

          <h1 className="text-lg sm:text-xl font-extrabold leading-snug">
            {ticket.subject}
          </h1>
        </div>
      </div>

      {/* Lifecycle Status Banners: Resolved vs Closed */}
      {ticket.status === "resolved" && (
        <div
          className={`rounded-2xl p-4 border ${
            isDark
              ? "bg-emerald-950/30 border-emerald-800/60 text-emerald-200"
              : "bg-emerald-50 border-emerald-200 text-emerald-900"
          }`}
        >
          <div className="flex items-start gap-3">
            <CheckCircleIcon className="w-5 h-5 shrink-0 mt-0.5 text-emerald-500" />
            <div className="space-y-1 text-xs flex-1">
              <div className="font-bold text-sm">
                Ticket Resolved
                {ticket.resolved_by ? ` by ${ticket.resolved_by}` : ""}
                {ticket.resolved_at ? ` · ${formatTicketDateTime(ticket.resolved_at)}` : ""}
              </div>
              {ticket.resolution_note && (
                <p className="whitespace-pre-wrap leading-relaxed pt-1">
                  {ticket.resolution_note}
                </p>
              )}
              <p className="opacity-80 pt-1">
                {ticket.auto_close_at
                  ? `Awaiting requester confirmation. Scheduled to auto-close on ${formatTicketDateTime(
                      ticket.auto_close_at
                    )} unless the requester returns the ticket.`
                  : "Awaiting requester confirmation or automatic closure."}
              </p>
            </div>
          </div>
        </div>
      )}

      {ticket.status === "closed" && (
        <div
          className={`rounded-2xl p-4 border ${
            isDark
              ? "bg-zinc-900/90 border-zinc-700 text-gray-300"
              : "bg-gray-100 border-gray-300 text-gray-800"
          }`}
        >
          <div className="flex items-start gap-3">
            <LockClosedIcon className="w-5 h-5 shrink-0 mt-0.5 opacity-75" />
            <div className="space-y-1 text-xs flex-1">
              <div className="font-bold text-sm">
                Ticket Permanently Closed
                {ticket.closed_at ? ` · ${formatTicketDateTime(ticket.closed_at)}` : ""}
              </div>
              {ticket.close_reason && (
                <p className="opacity-85">
                  Close reason:{" "}
                  <span className="font-semibold">
                    {String(ticket.close_reason).replace(/_/g, " ")}
                  </span>
                </p>
              )}
              {ticket.resolution_note && (
                <div
                  className={`mt-2 p-3 rounded-xl border ${
                    isDark
                      ? "bg-[#18191a] border-[#3e4042]"
                      : "bg-white border-gray-200"
                  }`}
                >
                  <div className="font-semibold mb-1">Recorded Resolution Note:</div>
                  <p className="whitespace-pre-wrap">{ticket.resolution_note}</p>
                </div>
              )}
              <p className="opacity-75 pt-0.5">
                Closed tickets are read-only and cannot be reopened.
              </p>
            </div>
          </div>
        </div>
      )}

      {/* Main Two-Column Workspace */}
      <div className="grid grid-cols-1 lg:grid-cols-3 gap-4 items-start">
        {/* Left 2 Columns: Conversation Thread + Staff Composer */}
        <div className="lg:col-span-2 space-y-4">
          {/* Messages Card */}
          <div
            className={`rounded-2xl border overflow-hidden ${
              isDark
                ? "bg-[#242526] border-[#3e4042] text-[#e4e6eb]"
                : "bg-white border-gray-200/80 shadow-sm text-gray-900"
            }`}
          >
            <div
              className={`px-5 py-3.5 border-b flex items-center justify-between ${
                isDark ? "border-[#3e4042]" : "border-gray-100"
              }`}
            >
              <h2 className="text-sm font-bold">
                Conversation ({messages.length})
              </h2>
              <span className={`text-xs ${isDark ? "text-gray-400" : "text-gray-500"}`}>
                Filed {formatTicketDateTime(ticket.created_at)}
              </span>
            </div>

            <div className="p-4 sm:p-5 space-y-4 max-h-[620px] overflow-y-auto">
              {messages.length === 0 ? (
                <p className={`text-xs text-center py-6 ${isDark ? "text-gray-400" : "text-gray-500"}`}>
                  No messages recorded on this ticket yet.
                </p>
              ) : (
                messages.map((msg) => {
                  const isStaff = msg.sender_type === "staff";
                  const isSystem = msg.sender_type === "system";

                  if (isSystem) {
                    return (
                      <div key={msg.id} className="flex justify-center">
                        <div
                          className={`px-3.5 py-2 rounded-xl text-xs text-center max-w-xl border ${
                            isDark
                              ? "bg-[#18191a] border-[#3e4042] text-gray-300"
                              : "bg-gray-50 border-gray-200 text-gray-600"
                          }`}
                        >
                          <span className="font-semibold">{msg.body}</span>
                          <span className="block text-[10px] opacity-70 mt-0.5">
                            {formatTicketDateTime(msg.created_at)}
                          </span>
                        </div>
                      </div>
                    );
                  }

                  return (
                    <div
                      key={msg.id}
                      className={`flex flex-col ${isStaff ? "items-end" : "items-start"}`}
                    >
                      <div className="flex items-center gap-2 mb-1 px-1">
                        <span className="text-xs font-bold">
                          {msg.sender_name || (isStaff ? "Registrar Staff" : "Requester")}
                        </span>
                        <span
                          className={`text-[10px] px-1.5 py-0.5 rounded font-semibold uppercase ${
                            isStaff
                              ? isDark
                                ? "bg-yellow-400/15 text-yellow-300"
                                : "bg-red-50 text-pup-dark-maroon"
                              : isDark
                                ? "bg-zinc-800 text-gray-300"
                                : "bg-gray-100 text-gray-600"
                          }`}
                        >
                          {isStaff ? "Staff" : "Requester"}
                        </span>
                        <span className={`text-[11px] ${isDark ? "text-gray-400" : "text-gray-400"}`}>
                          {formatTicketDateTime(msg.created_at)}
                        </span>
                      </div>

                      <div
                        className={`max-w-[90%] sm:max-w-[82%] rounded-2xl px-4 py-3 text-xs sm:text-sm whitespace-pre-wrap leading-relaxed border ${
                          isStaff
                            ? isDark
                              ? "bg-[#302617] border-yellow-500/30 text-[#f3f4f6]"
                              : "bg-[#fff8f6] border-red-200 text-gray-900"
                            : isDark
                              ? "bg-[#18191a] border-[#3e4042] text-[#e4e6eb]"
                              : "bg-gray-50 border-gray-200 text-gray-900"
                        }`}
                      >
                        {msg.body}
                      </div>
                    </div>
                  );
                })
              )}
            </div>
          </div>

          {/* Staff Reply Composer (strictly gated on backend ticket.actions.can_reply) */}
          {actions.can_reply ? (
            <form
              onSubmit={handleReplySubmit}
              className={`rounded-2xl p-4 sm:p-5 border space-y-3.5 ${
                isDark
                  ? "bg-[#242526] border-[#3e4042] text-[#e4e6eb]"
                  : "bg-white border-gray-200/80 shadow-sm text-gray-900"
              }`}
            >
              {!ticket.is_claimed && (
                <div
                  className={`text-[11px] font-semibold px-3 py-1.5 rounded-lg border ${
                    isDark
                      ? "bg-yellow-400/10 border-yellow-400/30 text-yellow-300"
                      : "bg-red-50 border-red-100 text-pup-dark-maroon"
                  }`}
                >
                  Sending a reply will automatically claim this ticket for you.
                </div>
              )}

              <VoiceTextareaInput
                id="staff-reply-body"
                label="Reply as Registrar Staff"
                labelColor="text-gray-700"
                minHeightClass="min-h-28"
                maxLength={5000}
                value={replyBody}
                onChange={(val) => {
                  setReplyBody(val);
                  if (replyFieldErrors.body) setReplyFieldErrors({});
                }}
                placeholder="Write or dictate a clear response or follow-up question for the requester…"
                error={replyFieldErrors.body?.[0]}
              />

              <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 pt-1">
                <CheckboxItem
                  id="staff-await-requester"
                  name="await_requester"
                  checked={awaitRequester}
                  onChange={(e) => setAwaitRequester(e.target.checked)}
                  text="Request information from requester (sets Pending Client & pauses SLA)"
                />

                <button
                  type="submit"
                  disabled={
                    actionBusy === "reply" ||
                    !replyBody.trim() ||
                    cooldown.isCoolingDown
                  }
                  className={`inline-flex items-center justify-center gap-2 px-5 py-2.5 rounded-xl text-xs font-bold shadow cursor-pointer shrink-0 disabled:opacity-50 disabled:cursor-not-allowed ${
                    isDark
                      ? "bg-yellow-400 text-black hover:bg-yellow-300"
                      : "bg-pup-dark-maroon text-white hover:bg-[#5c0000]"
                  }`}
                >
                  <PaperAirplaneIcon className="w-4 h-4" />
                  {cooldown.isCoolingDown
                    ? `Wait ${cooldown.secondsLeft}s`
                    : actionBusy === "reply"
                      ? "Sending…"
                      : awaitRequester
                        ? "Send & Await Client"
                        : "Send Reply"}
                </button>
              </div>
            </form>
          ) : (
            ticket.status !== "closed" && (
              <div
                className={`rounded-2xl p-4 border text-xs ${
                  isDark
                    ? "bg-[#242526] border-[#3e4042] text-gray-400"
                    : "bg-gray-50 border-gray-200 text-gray-600"
                }`}
              >
                {ticket.is_claimed && !ticket.claimed_by?.is_me
                  ? `This ticket is currently claimed by ${ticket.claimed_by?.name || "another staff member"}.`
                  : "Replies are not available for your current permissions or this ticket's current status."}
              </div>
            )
          )}
        </div>

        {/* Right Column: Metadata, Backend SLA Card, and Hash-Chained Activity Trail */}
        <div className="space-y-4">
          {/* Requester & Assignment Card */}
          <div
            className={`rounded-2xl p-4 sm:p-5 border space-y-3 ${
              isDark
                ? "bg-[#242526] border-[#3e4042] text-[#e4e6eb]"
                : "bg-white border-gray-200/80 shadow-sm text-gray-900"
            }`}
          >
            <h3 className="text-xs font-extrabold uppercase tracking-wider opacity-75">
              Requester &amp; Assignment
            </h3>

            <div className="space-y-2.5 text-xs">
              <div>
                <div className="opacity-65 mb-0.5">Requester</div>
                <div className="font-bold flex items-center gap-1.5 flex-wrap">
                  {ticket.requester?.type === "public" ? (
                    <GlobeAltIcon className="w-4 h-4 text-sky-500" />
                  ) : (
                    <UserIcon className="w-4 h-4" />
                  )}
                  <span>{ticket.requester?.name || "Anonymized Requester"}</span>
                  <span
                    className={`text-[10px] px-1.5 py-0.5 rounded uppercase font-bold ${
                      ticket.requester?.type === "public"
                        ? isDark
                          ? "bg-sky-950/60 text-sky-300 border border-sky-800/60"
                          : "bg-sky-50 text-sky-700 border border-sky-200"
                        : isDark
                          ? "bg-zinc-800 text-gray-300"
                          : "bg-gray-100 text-gray-700"
                    }`}
                  >
                    {ticket.requester?.type === "public" ? "Public Email" : "RIS Account"}
                  </span>
                </div>
                {ticket.requester?.email && (
                  <div className="mt-1 font-mono text-[11px] opacity-85 break-all">
                    {ticket.requester.email}
                  </div>
                )}
              </div>

              <div className={`pt-2 border-t ${isDark ? "border-[#3e4042]" : "border-gray-100"}`}>
                <div className="opacity-65 mb-0.5">Assigned Staff</div>
                <div className="font-semibold">
                  {ticket.is_claimed && ticket.claimed_by
                    ? `${ticket.claimed_by.name}${ticket.claimed_by.is_me ? " (You)" : ""}`
                    : "Unclaimed"}
                </div>
                {ticket.claimed_at && (
                  <div className="text-[11px] opacity-75 mt-0.5">
                    Claimed {formatTicketDateTime(ticket.claimed_at)}
                  </div>
                )}
                {ticket.claim_expires_at && ticket.is_claimed && (
                  <div className="text-[11px] opacity-75 mt-0.5">
                    Stale claim release: {formatTicketDateTime(ticket.claim_expires_at)}
                  </div>
                )}
              </div>
            </div>
          </div>

          {/* Backend SLA Card */}
          {ticket.sla && (
            <div
              className={`rounded-2xl p-4 sm:p-5 border space-y-3 ${
                isDark
                  ? "bg-[#242526] border-[#3e4042] text-[#e4e6eb]"
                  : "bg-white border-gray-200/80 shadow-sm text-gray-900"
              }`}
            >
              <div className="flex items-center justify-between">
                <h3 className="text-xs font-extrabold uppercase tracking-wider opacity-75 flex items-center gap-1.5">
                  <ClockIcon className="w-4 h-4" />
                  SLA Targets
                </h3>
                {ticket.sla.paused && (
                  <span
                    className={`px-2 py-0.5 rounded-md text-[10px] font-bold border ${getSlaBadgeClass(
                      "paused",
                      isDark
                    )}`}
                  >
                    Paused
                  </span>
                )}
              </div>

              <div className="space-y-3 text-xs">
                {ticket.sla.first_response && (
                  <div
                    className={`p-3 rounded-xl border ${
                      isDark ? "bg-[#18191a] border-[#3e4042]" : "bg-gray-50 border-gray-200/80"
                    }`}
                  >
                    <div className="flex items-center justify-between gap-2 mb-1">
                      <span className="font-semibold">First Response</span>
                      <span
                        className={`px-2 py-0.5 rounded-md text-[10px] font-bold border ${getSlaBadgeClass(
                          ticket.sla.paused &&
                            !["met", "missed", "breached"].includes(
                              ticket.sla.first_response.state
                            )
                            ? "paused"
                            : ticket.sla.first_response.state,
                          isDark
                        )}`}
                      >
                        {formatSlaStateLabel(ticket.sla.first_response.state)}
                      </span>
                    </div>
                    <div className="text-[11px] opacity-80">
                      Due: {formatTicketDateTime(ticket.sla.first_response.due_at)}
                    </div>
                    {ticket.first_responded_at && (
                      <div className="text-[11px] opacity-80 mt-0.5">
                        Responded: {formatTicketDateTime(ticket.first_responded_at)}
                      </div>
                    )}
                  </div>
                )}

                {ticket.sla.resolution && (
                  <div
                    className={`p-3 rounded-xl border ${
                      isDark ? "bg-[#18191a] border-[#3e4042]" : "bg-gray-50 border-gray-200/80"
                    }`}
                  >
                    <div className="flex items-center justify-between gap-2 mb-1">
                      <span className="font-semibold">Resolution</span>
                      <span
                        className={`px-2 py-0.5 rounded-md text-[10px] font-bold border ${getSlaBadgeClass(
                          ticket.sla.paused &&
                            !["met", "missed", "breached"].includes(
                              ticket.sla.resolution.state
                            )
                            ? "paused"
                            : ticket.sla.resolution.state,
                          isDark
                        )}`}
                      >
                        {formatSlaStateLabel(ticket.sla.resolution.state)}
                      </span>
                    </div>
                    <div className="text-[11px] opacity-80">
                      Due: {formatTicketDateTime(ticket.sla.resolution.due_at)}
                    </div>
                  </div>
                )}
              </div>
            </div>
          )}

          {/* Activity / Audit Event Log */}
          <div
            className={`rounded-2xl p-4 sm:p-5 border space-y-3 ${
              isDark
                ? "bg-[#242526] border-[#3e4042] text-[#e4e6eb]"
                : "bg-white border-gray-200/80 shadow-sm text-gray-900"
            }`}
          >
            <h3 className="text-xs font-extrabold uppercase tracking-wider opacity-75 flex items-center gap-1.5">
              <ListBulletIcon className="w-4 h-4" />
              Activity History ({events.length})
            </h3>

            {events.length === 0 ? (
              <p className="text-xs opacity-70">No activity recorded yet.</p>
            ) : (
              <ol
                className={`relative border-l ml-2 space-y-3 max-h-80 overflow-y-auto pr-1 ${
                  isDark ? "border-[#3e4042]" : "border-gray-200"
                }`}
              >
                {events.map((ev, idx) => (
                  <li key={`${ev.type}-${ev.created_at}-${idx}`} className="ml-4 text-xs">
                    <span
                      className={`absolute -left-1.5 mt-1.5 w-3 h-3 rounded-full border ${
                        isDark
                          ? "bg-yellow-400 border-[#242526]"
                          : "bg-pup-dark-maroon border-white"
                      }`}
                    />
                    <div className="font-semibold leading-snug">
                      {formatEventLabel(ev.type)}
                    </div>
                    {ev.actor_name && (
                      <div className="text-[11px] opacity-80">by {ev.actor_name}</div>
                    )}
                    {ev.from_status && ev.to_status && ev.from_status !== ev.to_status && (
                      <div className="text-[11px] opacity-75 font-mono">
                        {ev.from_status} → {ev.to_status}
                      </div>
                    )}
                    <div className="text-[10px] opacity-60">
                      {formatTicketDateTime(ev.created_at)} ({formatTicketRelative(ev.created_at)})
                    </div>
                  </li>
                ))}
              </ol>
            )}
          </div>
        </div>
      </div>

      {/* Resolve Ticket Modal */}
      {isResolveOpen && (
        <div className="fixed inset-0 z-9999 flex items-center justify-center pt-16 sm:pt-4 pb-4 px-3 sm:px-4 backdrop-blur-sm bg-black/60 overflow-y-auto">
          <div
            className={`relative z-9999 w-full max-w-lg my-auto rounded-xl shadow-[0_20px_50px_rgba(0,0,0,0.4)] overflow-hidden border flex flex-col max-h-[calc(100vh-5rem)] sm:max-h-[85vh] animate-in fade-in zoom-in duration-200 ${
              isDark
                ? "bg-[#242526] border-[#3e4042] text-[#e4e6eb]"
                : "bg-white border-[#800000]/20 text-gray-900"
            }`}
          >
            <div
              className={`px-4 py-4 sm:px-6 sm:py-5 border-b-4 shrink-0 ${
                isDark ? "bg-[#1f1f1f] border-[#b98b00]" : "bg-[#800000] border-[#FFD700]"
              }`}
            >
              <div className="flex items-center justify-between gap-3">
                <div className="min-w-0 flex-1">
                  <span className="text-[10px] sm:text-xs text-amber-200 font-bold uppercase tracking-wider block">
                    Ticket Resolution
                  </span>
                  <h2 className="text-lg sm:text-xl text-white font-black uppercase tracking-tighter mt-0.5 truncate">
                    Mark {ticket.ticket_number} as Resolved
                  </h2>
                </div>
                <button
                  type="button"
                  onClick={() => setIsResolveOpen(false)}
                  className="p-1 rounded hover:opacity-90 shrink-0 text-white cursor-pointer"
                  title="Close"
                >
                  <XMarkIcon className="w-5 h-5 sm:w-6 sm:h-6" />
                </button>
              </div>
            </div>

            <form onSubmit={handleResolveSubmit} noValidate className="flex flex-col flex-1 overflow-hidden">
              <div className="flex-1 overflow-y-auto p-4 sm:p-6 space-y-4">
                <p className={`text-xs leading-relaxed ${isDark ? "text-gray-300" : "text-gray-600"}`}>
                  Provide a clear resolution summary (minimum 10 characters). This note is sent to the
                  requester. The ticket will automatically close after 3 calendar days unless the
                  requester confirms earlier or returns it.
                </p>

                <VoiceTextareaInput
                  id="staff-resolution-note"
                  name="resolution_note"
                  label="Resolution Note"
                  labelColor="text-gray-700"
                  required
                  minHeightClass="min-h-28"
                  maxLength={5000}
                  value={resolutionNote}
                  onChange={(val) => {
                    setResolutionNote(val);
                    if (resolveFieldErrors.resolution_note) setResolveFieldErrors({});
                  }}
                  placeholder="Describe how the concern was resolved or what next steps were completed…"
                  helperText="Minimum 10 characters. Sent to the requester."
                  error={resolveFieldErrors.resolution_note?.[0]}
                />
              </div>

              <div
                className={`px-4 py-3 sm:px-6 sm:py-4 border-t-2 shrink-0 flex items-center justify-end gap-2.5 ${
                  isDark ? "bg-[#1f1f1f] border-[#3e4042]" : "bg-gray-50 border-gray-200"
                }`}
              >
                <button
                  type="button"
                  onClick={() => setIsResolveOpen(false)}
                  className={`px-4 py-2 rounded-lg text-xs font-semibold cursor-pointer ${
                    isDark
                      ? "bg-[#3a3b3c] hover:bg-[#4e4f50] text-[#e4e6eb] border border-[#4e4f50]"
                      : "bg-gray-200/80 hover:bg-gray-300/70 text-gray-700"
                  }`}
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  disabled={actionBusy === "resolve" || cooldown.isCoolingDown}
                  className="px-5 py-2 rounded-lg text-xs font-bold uppercase tracking-wider bg-emerald-600 text-white hover:bg-emerald-700 shadow cursor-pointer disabled:opacity-50"
                >
                  {actionBusy === "resolve" ? "Resolving…" : "Confirm Resolution"}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* Flag Spam & Optional Blocklist Modal (Manage permission) */}
      {isSpamOpen && (
        <div className="fixed inset-0 z-9999 flex items-center justify-center pt-16 sm:pt-4 pb-4 px-3 sm:px-4 backdrop-blur-sm bg-black/60 overflow-y-auto">
          <div
            className={`relative z-9999 w-full max-w-lg my-auto rounded-xl shadow-[0_20px_50px_rgba(0,0,0,0.4)] overflow-hidden border flex flex-col max-h-[calc(100vh-5rem)] sm:max-h-[85vh] animate-in fade-in zoom-in duration-200 ${
              isDark
                ? "bg-[#242526] border-[#3e4042] text-[#e4e6eb]"
                : "bg-white border-[#800000]/20 text-gray-900"
            }`}
          >
            <div
              className={`px-4 py-4 sm:px-6 sm:py-5 border-b-4 shrink-0 ${
                isDark ? "bg-[#1f1f1f] border-[#b98b00]" : "bg-[#800000] border-[#FFD700]"
              }`}
            >
              <div className="flex items-center justify-between gap-3">
                <div className="min-w-0 flex-1">
                  <span className="text-[10px] sm:text-xs text-amber-200 font-bold uppercase tracking-wider block">
                    Abuse &amp; Spam Moderation
                  </span>
                  <h2 className="text-lg sm:text-xl text-white font-black uppercase tracking-tighter mt-0.5 truncate">
                    Flag {ticket.ticket_number} as Spam
                  </h2>
                </div>
                <button
                  type="button"
                  onClick={() => setIsSpamOpen(false)}
                  className="p-1 rounded hover:opacity-90 shrink-0 text-white cursor-pointer"
                  title="Close"
                >
                  <XMarkIcon className="w-5 h-5 sm:w-6 sm:h-6" />
                </button>
              </div>
            </div>

            <form onSubmit={handleSpamSubmit} noValidate className="flex flex-col flex-1 overflow-hidden">
              <div className="flex-1 overflow-y-auto p-4 sm:p-6 space-y-4">
                <p className={`text-xs leading-relaxed ${isDark ? "text-gray-300" : "text-gray-600"}`}>
                  Records an audited spam flag on this ticket. Ticket status does not change
                  automatically, so resolve the ticket afterwards if needed.
                </p>

                <InputGroup
                  label="Reason / Audit Note"
                  name="spam_reason"
                  value={spamForm.reason}
                  onChange={(e) => {
                    setSpamForm((prev) => ({ ...prev, reason: e.target.value }));
                    if (spamFieldErrors.reason) setSpamFieldErrors((prev) => ({ ...prev, reason: undefined }));
                  }}
                  placeholder="Optional reason (e.g., unsolicited commercial link)"
                  labelColor="text-gray-700"
                  error={spamFieldErrors.reason?.[0]}
                />

                {canBlockSubmitter ? (
                  <div
                    className={`p-4 rounded-xl border space-y-3 text-xs ${
                      isDark ? "bg-[#18191a] border-[#3e4042]" : "bg-gray-50 border-gray-200"
                    }`}
                  >
                    <div className="font-bold">Optional Public Submitter Block</div>

                    <CheckboxItem
                      id="spam-block-email"
                      name="block_email"
                      checked={spamForm.block_email}
                      onChange={(e) =>
                        setSpamForm((prev) => ({ ...prev, block_email: e.target.checked }))
                      }
                      text={`Block email (${ticket.requester.email})`}
                    />

                    <CheckboxItem
                      id="spam-block-ip"
                      name="block_ip"
                      checked={spamForm.block_ip}
                      onChange={(e) =>
                        setSpamForm((prev) => ({ ...prev, block_ip: e.target.checked }))
                      }
                      text="Block submitter IP address"
                    />

                    {(spamForm.block_email || spamForm.block_ip) && (
                      <div className="pt-1">
                        <InputGroup
                          type="datetime-local"
                          label="Block Expiration (Optional — leave blank until lifted)"
                          name="block_expires_at"
                          value={spamForm.block_expires_at}
                          onChange={(e) =>
                            setSpamForm((prev) => ({
                              ...prev,
                              block_expires_at: e.target.value,
                            }))
                          }
                          voiceEnabled={false}
                          labelColor="text-gray-700"
                          error={spamFieldErrors.block_expires_at?.[0]}
                        />
                      </div>
                    )}

                    {spamFieldErrors.block && (
                      <p className="text-xs text-rose-500">{spamFieldErrors.block[0]}</p>
                    )}
                  </div>
                ) : (
                  <p className={`text-[11px] ${isDark ? "text-gray-400" : "text-gray-500"}`}>
                    Email/IP blocklist options apply only to public ticket submitters whose
                    details are still on file.
                  </p>
                )}
              </div>

              <div
                className={`px-4 py-3 sm:px-6 sm:py-4 border-t-2 shrink-0 flex items-center justify-end gap-2.5 ${
                  isDark ? "bg-[#1f1f1f] border-[#3e4042]" : "bg-gray-50 border-gray-200"
                }`}
              >
                <button
                  type="button"
                  onClick={() => setIsSpamOpen(false)}
                  className={`px-4 py-2 rounded-lg text-xs font-semibold cursor-pointer ${
                    isDark
                      ? "bg-[#3a3b3c] hover:bg-[#4e4f50] text-[#e4e6eb] border border-[#4e4f50]"
                      : "bg-gray-200/80 hover:bg-gray-300/70 text-gray-700"
                  }`}
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  disabled={actionBusy === "spam" || cooldown.isCoolingDown}
                  className="px-5 py-2 rounded-lg text-xs font-bold uppercase tracking-wider bg-rose-600 text-white hover:bg-rose-700 shadow cursor-pointer disabled:opacity-50"
                >
                  {actionBusy === "spam" ? "Recording…" : "Record Spam Flag"}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      <SuccessToast message={successMsg} onClose={() => setSuccessMsg("")} />
      <ErrorToast message={errorMsg} onClose={() => setErrorMsg("")} />
    </div>
  );
};

export default StaffTicketDetailPanel;
