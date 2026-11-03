import React, { useState, useRef, useEffect, useCallback, useMemo } from 'react';
import PropTypes from 'prop-types';
import {
  XMarkIcon,
  PaperAirplaneIcon,
  CheckCircleIcon,
  ArrowPathIcon,
  ChatBubbleLeftRightIcon,
  ClockIcon,
  EnvelopeIcon,
  ChevronDownIcon,
  SparklesIcon,
  HandRaisedIcon,
  ArrowUturnLeftIcon,
  ShieldExclamationIcon,
  ExclamationTriangleIcon,
} from '@heroicons/react/24/outline';
import { useAuth } from '../../context/AuthProvider';
import { useTheme } from '../../context/ThemeContext';
import { useToast } from '../../context/NotificationToastContext';
import { MODULE_KEYS, hasModuleAction } from '../../utils/policy';
import {
  getTicket,
  getStaffTicket,
  replyToTicket,
  confirmTicketResolution,
  returnTicket,
  claimTicket,
  releaseTicket,
  replyAsStaff,
  resolveTicket,
  flagTicketSpam,
} from '../../services/api';
import {
  TICKET_STATUS_META,
  getPriorityBadgeClass,
  getSlaBadgeClass,
  formatPriorityLabel,
  formatSlaStateLabel,
  formatEventLabel,
  formatTicketDateTime,
  parseTicketApiError,
} from '../../utils/ticketHelpers';
import { useTicketLiveUpdates, useRetryAfterCooldown } from '../../hooks/useTicketLiveUpdates';
import VoiceTextareaInput from '../VoiceTextareaInput.jsx';
import InputGroup from '../InputGroup.jsx';
import CheckboxItem from '../Checkbox.jsx';
import ConfirmationModal from '../ConfirmationModal.jsx';

const QUICK_REPLIES = [
  'Thank you for reaching out. We are currently looking into your concern.',
  'Could you please provide your document request reference number or Official Receipt details so we can verify?',
  'Your payment/document status has been verified and forwarded to the processing staff.',
  'The requested update has been completed. Please check your dashboard to verify.',
];

const RESOLUTION_MIN_LENGTH = 10;
const MESSAGE_MAX_LENGTH = 5000;

const TicketDetailModal = ({
  ticket: initialTicket = null,
  ticketId: propTicketId = null,
  isOpen,
  onClose,
  onTicketUpdated,
}) => {
  const { isDark } = useTheme();
  const { user } = useAuth();
  const { addToast } = useToast();
  const cooldown = useRetryAfterCooldown();

  const isStaff = user?.role_name === 'admin' || user?.role_name === 'super_admin';
  const canManage = isStaff && hasModuleAction(user, MODULE_KEYS.TICKETS, 'Manage');

  const resolvedId = propTicketId || initialTicket?.id || null;

  const [detailTicket, setDetailTicket] = useState(initialTicket);
  const [loadingDetail, setLoadingDetail] = useState(false);
  const [activeTab, setActiveTab] = useState('conversation'); // 'conversation' | 'activity'

  const [replyText, setReplyText] = useState('');
  const [awaitRequester, setAwaitRequester] = useState(false);
  const [sendMenuOpen, setSendMenuOpen] = useState(false);
  const [showQuickReplies, setShowQuickReplies] = useState(false);

  const [actionBusy, setActionBusy] = useState(false);
  const [bannerError, setBannerError] = useState('');

  // Resolve modal state (staff)
  const [showResolveModal, setShowResolveModal] = useState(false);
  const [resolutionNote, setResolutionNote] = useState('');
  const [resolveError, setResolveError] = useState('');

  // Return / Reopen modal state (requester)
  const [showReopenModal, setShowReopenModal] = useState(false);
  const [reopenReason, setReopenReason] = useState('');
  const [reopenError, setReopenError] = useState('');

  // Confirm resolution modal state (requester)
  const [showConfirmCloseModal, setShowConfirmCloseModal] = useState(false);

  // Flag spam modal state (staff with Manage permission)
  const [showSpamModal, setShowSpamModal] = useState(false);
  const [spamReason, setSpamReason] = useState('');
  const [spamBlockEmail, setSpamBlockEmail] = useState(false);
  const [spamBlockIp, setSpamBlockIp] = useState(false);
  const [spamExpiresAt, setSpamExpiresAt] = useState('');
  const [spamError, setSpamError] = useState('');

  const sendMenuRef = useRef(null);
  const messagesEndRef = useRef(null);

  useEffect(() => {
    setDetailTicket(initialTicket);
  }, [initialTicket]);

  const fetchDetail = useCallback(
    async (silent = false) => {
      if (!resolvedId || !isOpen) return;
      if (!silent) setLoadingDetail(true);
      try {
        const res = isStaff ? await getStaffTicket(resolvedId) : await getTicket(resolvedId);
        const fresh = res.data?.data;
        if (fresh) {
          setDetailTicket(fresh);
          onTicketUpdated?.(fresh);
        }
      } catch (err) {
        const parsed = await parseTicketApiError(err, 'Unable to load ticket conversation.');
        if (parsed.status === 429) cooldown.startCooldown(parsed.retryAfterSeconds);
        setBannerError(parsed.message);
      } finally {
        if (!silent) setLoadingDetail(false);
      }
    },
    [resolvedId, isOpen, isStaff, onTicketUpdated, cooldown.startCooldown]
  );

  useEffect(() => {
    if (isOpen && resolvedId) {
      setBannerError('');
      setActiveTab('conversation');
      fetchDetail(Boolean(initialTicket?.messages));
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [isOpen, resolvedId]);

  useTicketLiveUpdates({
    ticketId: resolvedId,
    enabled: Boolean(isOpen && resolvedId),
    onTicketUpdated: () => {
      fetchDetail(true);
    },
  });

  // Close send dropdown on outside click
  useEffect(() => {
    if (!sendMenuOpen) return undefined;
    const handleOutside = (e) => {
      if (sendMenuRef.current && !sendMenuRef.current.contains(e.target)) {
        setSendMenuOpen(false);
      }
    };
    document.addEventListener('mousedown', handleOutside);
    return () => document.removeEventListener('mousedown', handleOutside);
  }, [sendMenuOpen]);

  const ticket = detailTicket || initialTicket;

  const activityItems = useMemo(() => {
    if (!ticket) return [];
    if (Array.isArray(ticket.events) && ticket.events.length > 0) {
      return ticket.events.map((ev, idx) => ({
        id: `${ev.type}-${ev.created_at}-${idx}`,
        text: formatEventLabel(ev.type),
        timestamp: ev.created_at,
        actor: ev.actor_name || ev.actor_type || 'System',
        transition:
          ev.from_status && ev.to_status && ev.from_status !== ev.to_status
            ? `${TICKET_STATUS_META[ev.from_status]?.label || ev.from_status} → ${
                TICKET_STATUS_META[ev.to_status]?.label || ev.to_status
              }`
            : null,
      }));
    }

    // Requester-visible lifecycle summary when staff-only events are omitted
    const items = [];
    if (ticket.created_at) {
      items.push({
        id: 'created',
        text: 'Ticket submitted',
        timestamp: ticket.created_at,
        actor: 'You',
      });
    }
    if (ticket.resolved_at) {
      items.push({
        id: 'resolved',
        text: 'Marked Resolved by Registrar Staff',
        timestamp: ticket.resolved_at,
        actor: 'Registrar Staff',
      });
    }
    if (ticket.closed_at) {
      items.push({
        id: 'closed',
        text: ticket.close_reason ? `Ticket closed (${ticket.close_reason})` : 'Ticket closed',
        timestamp: ticket.closed_at,
        actor: 'System',
      });
    }
    return items;
  }, [ticket]);

  if (!isOpen || (!ticket && !loadingDetail)) return null;

  const status = ticket?.status || 'open';
  const statusLabel = ticket?.status_label || TICKET_STATUS_META[status]?.label || status;
  const isClosed = status === 'closed';
  const isResolved = status === 'resolved';
  const actions = ticket?.actions || {};
  const categoryName =
    typeof ticket?.category === 'object' ? ticket?.category?.name : ticket?.category;
  const messages = Array.isArray(ticket?.messages) ? ticket.messages : [];
  const initialMessage = messages[0] || null;
  const replyMessages = messages.length > 1 ? messages.slice(1) : [];
  const isPublicRequester = ticket?.requester?.type === 'public';

  const applyUpdatedTicket = (updated, toastTitle, toastMessage) => {
    if (updated) {
      setDetailTicket(updated);
      onTicketUpdated?.(updated);
    }
    if (toastTitle) {
      addToast?.({
        type: 'success',
        title: toastTitle,
        message: toastMessage,
      });
    }
  };

  const handleActionError = async (err, fallback) => {
    const parsed = await parseTicketApiError(err, fallback);
    if (parsed.status === 429) cooldown.startCooldown(parsed.retryAfterSeconds);
    setBannerError(parsed.message);
    if (parsed.status === 409) {
      fetchDetail(true);
    }
    return parsed;
  };

  const handleClaim = async () => {
    if (!ticket?.id || actionBusy || cooldown.isCoolingDown) return;
    setActionBusy(true);
    setBannerError('');
    try {
      const res = await claimTicket(ticket.id);
      applyUpdatedTicket(
        res.data?.data,
        'Ticket Claimed',
        `You are now handling ${ticket.ticket_number}.`
      );
    } catch (err) {
      await handleActionError(err, 'Unable to claim ticket.');
    } finally {
      setActionBusy(false);
    }
  };

  const handleRelease = async () => {
    if (!ticket?.id || actionBusy || cooldown.isCoolingDown) return;
    setActionBusy(true);
    setBannerError('');
    try {
      const res = await releaseTicket(ticket.id);
      applyUpdatedTicket(
        res.data?.data,
        'Claim Released',
        `${ticket.ticket_number} has been returned to the shared queue.`
      );
    } catch (err) {
      await handleActionError(err, 'Unable to release ticket claim.');
    } finally {
      setActionBusy(false);
    }
  };

  const handleSendReply = async (overrideAwaitRequester = null) => {
    const body = replyText.trim();
    if (!ticket?.id || !body || actionBusy || cooldown.isCoolingDown) return;
    if (body.length > MESSAGE_MAX_LENGTH) {
      setBannerError(`Reply cannot exceed ${MESSAGE_MAX_LENGTH} characters.`);
      return;
    }

    setSendMenuOpen(false);
    setActionBusy(true);
    setBannerError('');
    try {
      const waitFlag =
        typeof overrideAwaitRequester === 'boolean' ? overrideAwaitRequester : awaitRequester;
      const res = isStaff
        ? await replyAsStaff(ticket.id, body, waitFlag)
        : await replyToTicket(ticket.id, body);

      setReplyText('');
      setAwaitRequester(false);
      applyUpdatedTicket(
        res.data?.data,
        'Reply Sent',
        isStaff && waitFlag
          ? 'Reply sent and ticket set to Pending Client (SLA paused).'
          : 'Your message has been added to the conversation.'
      );
      requestAnimationFrame(() => {
        messagesEndRef.current?.scrollIntoView({ behavior: 'smooth' });
      });
    } catch (err) {
      await handleActionError(err, 'Unable to send reply.');
    } finally {
      setActionBusy(false);
    }
  };

  const handleKeyDown = (e) => {
    if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
      e.preventDefault();
      handleSendReply();
    }
  };

  const handleConfirmResolve = async (e) => {
    e.preventDefault();
    const note = resolutionNote.trim();
    if (note.length < RESOLUTION_MIN_LENGTH) {
      setResolveError(
        `Please enter at least ${RESOLUTION_MIN_LENGTH} characters explaining how the concern was resolved.`
      );
      return;
    }

    setActionBusy(true);
    setResolveError('');
    setBannerError('');
    try {
      const res = await resolveTicket(ticket.id, note);
      setResolutionNote('');
      setShowResolveModal(false);
      applyUpdatedTicket(
        res.data?.data,
        'Ticket Marked Resolved',
        `${ticket.ticket_number} is now Resolved and will auto-close in 3 days unless returned.`
      );
    } catch (err) {
      const parsed = await handleActionError(err, 'Unable to resolve ticket.');
      if (parsed.fieldErrors?.resolution_note?.[0]) {
        setResolveError(parsed.fieldErrors.resolution_note[0]);
      } else {
        setResolveError(parsed.message);
      }
    } finally {
      setActionBusy(false);
    }
  };

  const handleRequesterConfirmClose = async () => {
    if (!ticket?.id || actionBusy || cooldown.isCoolingDown) return;
    setActionBusy(true);
    setBannerError('');
    try {
      const res = await confirmTicketResolution(ticket.id);
      applyUpdatedTicket(
        res.data?.data,
        'Resolution Confirmed',
        `Thank you! ${ticket.ticket_number} is now closed.`
      );
    } catch (err) {
      await handleActionError(err, 'Unable to confirm ticket resolution.');
    } finally {
      setActionBusy(false);
    }
  };

  const handleConfirmReopen = async (e) => {
    e.preventDefault();
    if (!ticket?.id || actionBusy || cooldown.isCoolingDown) return;

    setActionBusy(true);
    setReopenError('');
    setBannerError('');
    try {
      const res = await returnTicket(ticket.id, reopenReason.trim() || null);
      setReopenReason('');
      setShowReopenModal(false);
      applyUpdatedTicket(
        res.data?.data,
        'Ticket Returned to In Progress',
        `${ticket.ticket_number} has been returned to Registrar Staff for follow-up.`
      );
    } catch (err) {
      const parsed = await handleActionError(err, 'Unable to return ticket.');
      setReopenError(parsed.message);
    } finally {
      setActionBusy(false);
    }
  };

  const handleFlagSpam = async (e) => {
    e.preventDefault();
    if (!ticket?.id || actionBusy || cooldown.isCoolingDown) return;

    setActionBusy(true);
    setSpamError('');
    setBannerError('');
    try {
      const payload = {};
      if (spamReason.trim()) payload.reason = spamReason.trim();
      if (isPublicRequester) {
        if (spamBlockEmail) payload.block_email = true;
        if (spamBlockIp) payload.block_ip = true;
        if ((spamBlockEmail || spamBlockIp) && spamExpiresAt) {
          const dt = new Date(spamExpiresAt);
          if (!Number.isNaN(dt.getTime())) payload.block_expires_at = dt.toISOString();
        }
      }
      const res = await flagTicketSpam(ticket.id, payload);
      setShowSpamModal(false);
      setSpamReason('');
      setSpamBlockEmail(false);
      setSpamBlockIp(false);
      setSpamExpiresAt('');
      applyUpdatedTicket(
        res.data?.data,
        'Spam Flag Recorded',
        `Recorded spam flag on ${ticket.ticket_number}. Resolve the ticket when ready to close it out.`
      );
    } catch (err) {
      const parsed = await handleActionError(err, 'Unable to flag ticket as spam.');
      setSpamError(parsed.message);
    } finally {
      setActionBusy(false);
    }
  };

  const getHeaderStatusPill = () => {
    const styles = isDark
      ? {
          open: 'bg-yellow-900/40 text-yellow-300 border-yellow-500/60',
          in_progress: 'bg-blue-900/40 text-blue-300 border-blue-500/60',
          pending_client: 'bg-amber-900/40 text-amber-300 border-amber-500/60',
          resolved: 'bg-green-900/40 text-green-300 border-green-500/60',
          closed: 'bg-gray-700/40 text-gray-200 border-gray-500/60',
        }
      : {
          open: 'bg-yellow-100 text-yellow-800 border-yellow-300',
          in_progress: 'bg-blue-100 text-blue-800 border-blue-300',
          pending_client: 'bg-amber-100 text-amber-800 border-amber-300',
          resolved: 'bg-green-100 text-green-800 border-green-300',
          closed: 'bg-gray-100 text-gray-800 border-gray-300',
        };

    const badgeClass =
      styles[status] ||
      (isDark
        ? 'bg-gray-700/40 text-gray-200 border-gray-500/60'
        : 'bg-gray-100 text-gray-700 border-gray-300');

    return (
      <span
        className={`px-3 py-1 rounded-full text-[12px] font-bold border whitespace-nowrap inline-flex items-center gap-1.5 ${badgeClass}`}
      >
        <span>{statusLabel}</span>
      </span>
    );
  };

  const requesterDisplayLine = isStaff
    ? `${ticket?.requester?.name || 'Requester'}${
        ticket?.requester?.email
          ? ` (${ticket.requester.email})`
          : isPublicRequester
            ? ' (Public Email Submitter)'
            : ' (RIS Account)'
      }`
    : `${user?.display_name || 'Your Ticket'}${user?.email ? ` (${user.email})` : ''}`;

  return (
    <div className="fixed inset-0 z-9999 flex items-center justify-center p-2 sm:p-4 backdrop-blur-sm bg-black/60 overflow-y-auto">
      <div
        className={`relative z-9999 w-full max-w-3xl my-auto rounded-xl shadow-2xl overflow-hidden border flex flex-col max-h-[92vh] animate-in fade-in duration-150 ${
          isDark ? 'bg-[#1e1f20] border-[#3e4042] text-[#e4e6eb]' : 'bg-white border-gray-200 text-gray-900'
        }`}
      >
        {/* ---------------- 1. TOP MAROON HEADER ---------------- */}
        <div className="bg-[#6B1818] text-white px-5 py-4 shrink-0">
          <div className="flex items-start justify-between gap-4">
            <div className="min-w-0">
              <span className="text-[11px] font-bold uppercase tracking-wider text-amber-200/90 block truncate">
                SUPPORT TICKET • {categoryName || 'GENERAL'}
              </span>
              <div className="flex items-center gap-2.5 mt-0.5 flex-wrap">
                <h2 className="text-2xl sm:text-3xl font-extrabold tracking-tight">
                  {ticket?.ticket_number || 'Loading…'}
                </h2>
                {ticket && getHeaderStatusPill()}
                {ticket?.priority && (
                  <span
                    className={`px-2.5 py-0.5 rounded-full text-[11px] font-bold border ${getPriorityBadgeClass(
                      ticket.priority,
                      true
                    )}`}
                  >
                    {formatPriorityLabel(ticket.priority)} Priority
                  </span>
                )}
              </div>
              <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-white/90 mt-1 font-normal">
                <span className="inline-flex items-center gap-1.5">
                  <EnvelopeIcon className="w-3.5 h-3.5 text-white/80 shrink-0" />
                  <span>{requesterDisplayLine}</span>
                </span>
                {isStaff && ticket?.is_claimed && ticket?.claimed_by?.name && (
                  <span className="text-amber-200 font-semibold">
                    • Claimed by {ticket.claimed_by.is_me ? 'You' : ticket.claimed_by.name}
                  </span>
                )}
              </div>
            </div>

            <div className="flex items-center gap-1.5 shrink-0">
              <button
                type="button"
                onClick={() => fetchDetail(false)}
                disabled={loadingDetail}
                className="text-white/80 hover:text-white transition p-1.5 rounded-md cursor-pointer"
                title="Refresh ticket"
              >
                <ArrowPathIcon className={`w-5 h-5 ${loadingDetail ? 'animate-spin' : ''}`} />
              </button>
              <button
                type="button"
                onClick={onClose}
                className="text-white/80 hover:text-white transition p-1 rounded-md cursor-pointer"
                title="Close modal"
              >
                <XMarkIcon className="w-6 h-6 stroke-[2.2]" />
              </button>
            </div>
          </div>
        </div>

        {/* ---------------- 2. SUB-HEADER / META & STAFF ACTIONS ROW ---------------- */}
        <div
          className={`px-5 py-2.5 border-b flex flex-col gap-2 text-xs ${
            isDark ? 'bg-[#252728] border-[#333537]' : 'bg-white border-gray-100'
          }`}
        >
          <div className="flex flex-wrap items-center justify-between gap-2.5">
            {/* Subject title */}
            <div className="flex items-center gap-2 min-w-0 flex-1">
              <span className="font-semibold text-xs sm:text-sm text-gray-800 dark:text-gray-100 wrap-break-word">
                {ticket?.subject}
              </span>
            </div>

            {/* Staff action buttons strictly following backend ticket.actions */}
            {isStaff && ticket && (
              <div className="flex flex-wrap items-center gap-2 shrink-0">
                {actions.can_claim && (
                  <button
                    type="button"
                    disabled={actionBusy || cooldown.isCoolingDown}
                    onClick={handleClaim}
                    className={`inline-flex items-center gap-1 px-3 py-1.5 rounded-full text-xs font-bold transition cursor-pointer disabled:opacity-50 ${
                      isDark
                        ? 'bg-yellow-400 text-gray-900 hover:bg-yellow-300'
                        : 'bg-pup-dark-maroon text-white hover:bg-[#3a0303]'
                    }`}
                  >
                    <HandRaisedIcon className="w-3.5 h-3.5" />
                    <span>Claim Ticket</span>
                  </button>
                )}

                {actions.can_release && (
                  <button
                    type="button"
                    disabled={actionBusy || cooldown.isCoolingDown}
                    onClick={handleRelease}
                    className={`inline-flex items-center gap-1 px-3 py-1.5 rounded-full text-xs font-semibold border transition cursor-pointer disabled:opacity-50 ${
                      isDark
                        ? 'border-[#3e4042] text-gray-200 hover:bg-[#333537]'
                        : 'border-gray-300 text-gray-700 hover:bg-gray-100'
                    }`}
                  >
                    <ArrowUturnLeftIcon className="w-3.5 h-3.5" />
                    <span>Release Claim</span>
                  </button>
                )}

                {actions.can_resolve && (
                  <button
                    type="button"
                    disabled={actionBusy || cooldown.isCoolingDown}
                    onClick={() => {
                      setResolveError('');
                      setShowResolveModal(true);
                    }}
                    className="inline-flex items-center gap-1 px-3 py-1.5 rounded-full text-xs font-bold bg-green-600 hover:bg-green-700 text-white transition cursor-pointer disabled:opacity-50"
                  >
                    <CheckCircleIcon className="w-3.5 h-3.5" />
                    <span>Mark Resolved</span>
                  </button>
                )}

                {canManage && (
                  <button
                    type="button"
                    disabled={actionBusy || cooldown.isCoolingDown}
                    onClick={() => {
                      setSpamError('');
                      setShowSpamModal(true);
                    }}
                    className={`inline-flex items-center gap-1 px-2.5 py-1.5 rounded-full text-xs font-semibold border transition cursor-pointer disabled:opacity-50 ${
                      isDark
                        ? 'border-rose-800/60 text-rose-300 hover:bg-rose-950/40'
                        : 'border-rose-200 text-rose-700 hover:bg-rose-50'
                    }`}
                  >
                    <ShieldExclamationIcon className="w-3.5 h-3.5" />
                    <span>Flag Spam</span>
                  </button>
                )}
              </div>
            )}
          </div>

          {/* Backend SLA status strip for staff */}
          {isStaff && ticket?.sla && (
            <div className="flex flex-wrap items-center gap-2 pt-0.5 text-[11px]">
              <span
                className={`inline-flex items-center gap-1 px-2 py-0.5 rounded-full font-semibold border ${getSlaBadgeClass(
                  ticket.sla.first_response?.state,
                  isDark
                )}`}
              >
                1st Response: {formatSlaStateLabel(ticket.sla.first_response?.state)}
                {ticket.sla.first_response?.due_at &&
                !['met', 'missed', 'none'].includes(ticket.sla.first_response.state)
                  ? ` • Due ${formatTicketDateTime(ticket.sla.first_response.due_at)}`
                  : ''}
              </span>

              <span
                className={`inline-flex items-center gap-1 px-2 py-0.5 rounded-full font-semibold border ${getSlaBadgeClass(
                  ticket.sla.resolution?.state,
                  isDark
                )}`}
              >
                Resolution: {formatSlaStateLabel(ticket.sla.resolution?.state)}
                {ticket.sla.resolution?.due_at &&
                !['met', 'missed', 'none'].includes(ticket.sla.resolution.state)
                  ? ` • Due ${formatTicketDateTime(ticket.sla.resolution.due_at)}`
                  : ''}
              </span>

              {ticket.sla.paused && (
                <span className="text-purple-600 dark:text-purple-300 font-semibold">
                  • SLA clock paused while awaiting requester reply
                </span>
              )}
            </div>
          )}
        </div>

        {/* ---------------- 3. TABS (Conversation / Activity) ---------------- */}
        <div
          className={`flex border-b px-5 text-xs sm:text-sm font-semibold shrink-0 ${
            isDark ? 'border-[#333537] bg-[#1e1f20]' : 'border-gray-200 bg-white'
          }`}
        >
          <button
            type="button"
            onClick={() => setActiveTab('conversation')}
            className={`py-2.5 border-b-2 flex items-center gap-2 transition cursor-pointer ${
              activeTab === 'conversation'
                ? 'border-pup-dark-maroon text-pup-dark-maroon dark:border-yellow-400 dark:text-yellow-400 font-bold'
                : 'border-transparent text-gray-500 hover:text-gray-800 dark:text-gray-400'
            }`}
          >
            <ChatBubbleLeftRightIcon className="w-4 h-4" />
            <span>Conversation</span>
            <span className="text-[11px] font-normal text-gray-500">{messages.length}</span>
          </button>

          <button
            type="button"
            onClick={() => setActiveTab('activity')}
            className={`py-2.5 border-b-2 flex items-center gap-2 ml-6 transition cursor-pointer ${
              activeTab === 'activity'
                ? 'border-pup-dark-maroon text-pup-dark-maroon dark:border-yellow-400 dark:text-yellow-400 font-bold'
                : 'border-transparent text-gray-500 hover:text-gray-800 dark:text-gray-400'
            }`}
          >
            <ClockIcon className="w-4 h-4" />
            <span>Activity</span>
            <span className="text-[11px] font-normal text-gray-500">{activityItems.length}</span>
          </button>
        </div>

        {/* ---------------- 4. MODAL BODY (Messages or Audit Trail) ---------------- */}
        <div className="flex-1 overflow-y-auto p-5 space-y-4">
          {bannerError && (
            <div
              className={`p-3 rounded-xl border flex items-start gap-2.5 text-xs ${
                isDark
                  ? 'bg-red-950/30 border-red-800/50 text-red-300'
                  : 'bg-red-50 border-red-200 text-red-700'
              }`}
            >
              <ExclamationTriangleIcon className="w-4 h-4 shrink-0 mt-0.5" />
              <span>{bannerError}</span>
            </div>
          )}

          {loadingDetail && messages.length === 0 ? (
            <div className="py-12 text-center space-y-2">
              <ArrowPathIcon
                className={`w-7 h-7 mx-auto animate-spin ${
                  isDark ? 'text-yellow-400' : 'text-[#6B1818]'
                }`}
              />
              <p className="text-xs text-gray-500">Loading conversation…</p>
            </div>
          ) : activeTab === 'conversation' ? (
            <div className="space-y-4">
              {/* Initial Requester Post */}
              {initialMessage && (
                <div
                  className={`p-4 rounded-xl text-xs sm:text-sm leading-relaxed ${
                    isDark ? 'bg-[#282a2c] text-gray-200' : 'bg-gray-50 text-gray-800'
                  }`}
                >
                  <div className="flex items-center justify-between mb-1 text-xs gap-2">
                    <span className="font-bold text-gray-900 dark:text-white">
                      {initialMessage.sender_name || initialMessage.sender_label || 'Requester'}
                    </span>
                    <span className="text-[11px] text-gray-400">
                      {formatTicketDateTime(initialMessage.created_at)}
                    </span>
                  </div>
                  <p className="whitespace-pre-wrap wrap-break-word">{initialMessage.body}</p>
                </div>
              )}

              {/* Message Thread (Replies) */}
              {replyMessages.map((msg) => {
                if (msg.sender_type === 'system') {
                  return (
                    <div key={msg.id} className="flex justify-center">
                      <span
                        className={`px-3 py-1 rounded-full text-[11px] border ${
                          isDark
                            ? 'bg-[#242526] border-[#3e4042] text-gray-400'
                            : 'bg-gray-100 border-gray-200 text-gray-600'
                        }`}
                      >
                        {msg.body} • {formatTicketDateTime(msg.created_at)}
                      </span>
                    </div>
                  );
                }

                const fromStaff = msg.sender_type === 'staff';
                const senderTitle = msg.sender_name || msg.sender_label || (fromStaff ? 'Registrar Staff' : 'Requester');

                return (
                  <div
                    key={msg.id}
                    className={`flex flex-col ${fromStaff ? 'items-end' : 'items-start'}`}
                  >
                    <div
                      className={`max-w-[85%] rounded-2xl p-4 text-xs sm:text-sm leading-relaxed shadow-xs ${
                        fromStaff
                          ? isDark
                            ? 'bg-pup-dark-maroon text-white border border-red-900/40'
                            : 'bg-[#6B1818] text-white'
                          : isDark
                            ? 'bg-[#282a2c] text-gray-200'
                            : 'bg-gray-100 text-gray-900'
                      }`}
                    >
                      <div className="flex items-center justify-between gap-4 mb-1 text-xs">
                        <span
                          className={`font-bold ${
                            fromStaff ? 'text-amber-200' : 'text-gray-900 dark:text-white'
                          }`}
                        >
                          {senderTitle}
                        </span>
                        <span
                          className={`text-[10px] shrink-0 ${
                            fromStaff ? 'text-white/70' : 'text-gray-400'
                          }`}
                        >
                          {formatTicketDateTime(msg.created_at)}
                        </span>
                      </div>
                      <p className="whitespace-pre-wrap wrap-break-word">{msg.body}</p>
                    </div>
                  </div>
                );
              })}

              {/* Official Resolution Note */}
              {ticket?.resolution_note && (
                <div
                  className={`p-3.5 rounded-xl border flex items-start gap-2.5 text-xs ${
                    isDark
                      ? 'bg-green-950/20 border-green-800/40 text-green-300'
                      : 'bg-green-50 border-green-200 text-green-800'
                  }`}
                >
                  <CheckCircleIcon className="w-4 h-4 text-green-600 shrink-0 mt-0.5" />
                  <div className="space-y-1 min-w-0">
                    <div>
                      <span className="font-bold">Official Resolution: </span>
                      <span className="whitespace-pre-wrap wrap-break-word">{ticket.resolution_note}</span>
                    </div>
                    {(ticket.auto_close_at || typeof ticket.returns_remaining === 'number') &&
                      isResolved && (
                        <div className="text-[11px] opacity-80 flex flex-wrap gap-x-3 gap-y-0.5">
                          {ticket.auto_close_at && (
                            <span>Auto-closes {formatTicketDateTime(ticket.auto_close_at)}</span>
                          )}
                          {typeof ticket.returns_remaining === 'number' && (
                            <span>Returns remaining: {ticket.returns_remaining}</span>
                          )}
                        </div>
                      )}
                  </div>
                </div>
              )}

              <div ref={messagesEndRef} />
            </div>
          ) : (
            /* History & Audit Trail */
            <div className="space-y-4">
              <h4 className="text-xs font-bold uppercase tracking-wider text-gray-500">
                Timeline &amp; Event Logs
              </h4>
              {activityItems.length === 0 ? (
                <p className="text-xs text-gray-500">No timeline events recorded yet.</p>
              ) : (
                <div className="relative pl-5 space-y-4">
                  <div
                    className={`absolute left-1.5 top-2 bottom-2 w-0.5 ${
                      isDark ? 'bg-gray-700' : 'bg-gray-200'
                    }`}
                  />
                  {activityItems.map((act) => (
                    <div key={act.id} className="relative flex flex-col text-xs">
                      <div
                        className={`absolute -left-5 top-1 w-2.5 h-2.5 rounded-full ${
                          isDark ? 'bg-yellow-400 ring-4 ring-[#1e1f20]' : 'bg-[#6B1818] ring-4 ring-white'
                        }`}
                      />
                      <span className="font-bold">
                        {act.text}
                        {act.transition ? ` (${act.transition})` : ''}
                      </span>
                      <span className="text-[10px] text-gray-400 mt-0.5">
                        {formatTicketDateTime(act.timestamp)} • Actor: {act.actor}
                      </span>
                    </div>
                  ))}
                </div>
              )}
            </div>
          )}
        </div>

        {/* ---------------- 5. BOTTOM REPLY / RESOLUTION BAR ---------------- */}
        {isClosed ? (
          <div
            className={`p-4 text-center text-xs font-semibold border-t shrink-0 ${
              isDark ? 'bg-[#18191a] text-gray-400 border-[#333537]' : 'bg-gray-50 text-gray-500 border-gray-200'
            }`}
          >
            🔒 This ticket is closed and archived (Policy §7).
          </div>
        ) : (
          <div
            className={`border-t px-5 py-3 shrink-0 space-y-2.5 ${
              isDark ? 'bg-[#1e1f20] border-[#333537]' : 'bg-white border-gray-200'
            }`}
          >
            {/* Requester Resolution Confirmation / Return Banner */}
            {!isStaff && isResolved && (
              <div className="flex flex-wrap items-center justify-between gap-2 py-1">
                <span className="text-xs text-gray-500 dark:text-gray-400">
                  Was your concern resolved? Confirm to close, or return if the issue persists.
                </span>
                <div className="flex flex-wrap items-center gap-2">
                  {actions.can_confirm && (
                    <button
                      type="button"
                      disabled={actionBusy || cooldown.isCoolingDown}
                      onClick={() => setShowConfirmCloseModal(true)}
                      className="px-3.5 py-1.5 text-xs font-bold bg-green-600 hover:bg-green-700 text-white rounded-full flex items-center gap-1 cursor-pointer disabled:opacity-50"
                    >
                      <CheckCircleIcon className="w-3.5 h-3.5" />
                      Confirm Resolution
                    </button>
                  )}
                  {actions.can_return && (
                    <button
                      type="button"
                      disabled={actionBusy || cooldown.isCoolingDown}
                      onClick={() => {
                        setReopenError('');
                        setShowReopenModal(true);
                      }}
                      className="px-3.5 py-1.5 text-xs font-bold bg-amber-600 hover:bg-amber-700 text-white rounded-full flex items-center gap-1 cursor-pointer disabled:opacity-50"
                    >
                      <ArrowPathIcon className="w-3.5 h-3.5" />
                      Request Reopen
                    </button>
                  )}
                </div>
              </div>
            )}

            {/* Reply Input Box (shown only when backend actions.can_reply allows it) */}
            {actions.can_reply ? (
              <div
                className={`rounded-xl border p-2.5 transition-all ${
                  isDark ? 'border-[#3e4042] bg-[#242526]' : 'border-gray-200 bg-white'
                }`}
              >
                <textarea
                  value={replyText}
                  maxLength={MESSAGE_MAX_LENGTH}
                  onChange={(e) => setReplyText(e.target.value)}
                  onKeyDown={handleKeyDown}
                  placeholder={
                    isStaff
                      ? 'Write a reply to the requester...'
                      : isResolved
                        ? 'Replying here will return this ticket to In Progress...'
                        : 'Write a reply...'
                  }
                  rows={3}
                  className={`w-full text-xs sm:text-sm bg-transparent outline-none resize-none placeholder-gray-400 leading-relaxed ${
                    isDark ? 'text-white' : 'text-gray-900'
                  }`}
                />

                {/* Quick Replies Tray (Staff) */}
                {isStaff && showQuickReplies && (
                  <div
                    className={`mt-2 p-2 rounded-lg border text-xs space-y-1 ${
                      isDark ? 'bg-[#1e1f20] border-[#3e4042]' : 'bg-gray-50 border-gray-200'
                    }`}
                  >
                    <div className="font-bold text-[11px] text-gray-400 uppercase tracking-wider mb-1">
                      Select Quick Reply:
                    </div>
                    {QUICK_REPLIES.map((qr, idx) => (
                      <button
                        key={idx}
                        type="button"
                        onClick={() => {
                          setReplyText((prev) => (prev ? `${prev} ${qr}` : qr));
                          setShowQuickReplies(false);
                        }}
                        className={`w-full text-left p-1.5 rounded transition cursor-pointer ${
                          isDark ? 'hover:bg-[#2e2f30]' : 'hover:bg-gray-200'
                        }`}
                      >
                        {qr}
                      </button>
                    ))}
                  </div>
                )}

                {/* Action Toolbar below textarea */}
                <div className="flex flex-wrap items-center justify-between gap-2 pt-2 border-t border-gray-100 dark:border-gray-800 text-xs">
                  <div className="flex flex-wrap items-center gap-3">
                    {isStaff && (
                      <>
                        <button
                          type="button"
                          onClick={() => setShowQuickReplies((prev) => !prev)}
                          className="flex items-center gap-1.5 text-gray-500 hover:text-gray-800 dark:hover:text-white font-medium cursor-pointer"
                        >
                          <SparklesIcon className="w-4 h-4 text-gray-400" />
                          <span>Quick replies</span>
                        </button>

                        <label className="inline-flex items-center gap-1.5 cursor-pointer select-none text-gray-600 dark:text-gray-300">
                          <input
                            type="checkbox"
                            checked={awaitRequester}
                            onChange={(e) => setAwaitRequester(e.target.checked)}
                            className="rounded border-gray-300 accent-[#6B1818]"
                          />
                          <span>Await reply (Pending Client)</span>
                        </label>
                      </>
                    )}
                  </div>

                  {/* Right controls */}
                  <div className="flex items-center gap-3 ml-auto">
                    <span className="hidden sm:inline text-[11px] text-gray-400">
                      Ctrl + Enter to send
                    </span>

                    {/* Send Reply Split Button */}
                    <div className="relative inline-flex rounded-lg shadow-sm" ref={sendMenuRef}>
                      <button
                        type="button"
                        disabled={!replyText.trim() || actionBusy || cooldown.isCoolingDown}
                        onClick={() => handleSendReply()}
                        className={`flex items-center gap-2 px-4 py-2 ${
                          isStaff ? 'rounded-l-lg' : 'rounded-lg'
                        } font-bold text-xs transition cursor-pointer ${
                          replyText.trim() && !actionBusy && !cooldown.isCoolingDown
                            ? 'bg-[#6B1818] hover:bg-[#521313] text-white'
                            : 'bg-gray-300 dark:bg-gray-700 text-gray-500 cursor-not-allowed'
                        }`}
                      >
                        <PaperAirplaneIcon className="w-3.5 h-3.5" />
                        <span>
                          {cooldown.isCoolingDown
                            ? `Wait ${cooldown.secondsLeft}s`
                            : actionBusy
                              ? 'Sending…'
                              : isStaff && awaitRequester
                                ? 'Send & Await Reply'
                                : 'Send reply'}
                        </span>
                      </button>

                      {isStaff && (
                        <>
                          <button
                            type="button"
                            disabled={!replyText.trim() || actionBusy || cooldown.isCoolingDown}
                            onClick={() => setSendMenuOpen((prev) => !prev)}
                            className={`px-2 py-2 border-l border-white/20 rounded-r-lg font-bold text-xs transition cursor-pointer ${
                              replyText.trim() && !actionBusy && !cooldown.isCoolingDown
                                ? 'bg-[#6B1818] hover:bg-[#521313] text-white'
                                : 'bg-gray-300 dark:bg-gray-700 text-gray-500 cursor-not-allowed'
                            }`}
                            title="More send options"
                          >
                            <ChevronDownIcon className="w-3.5 h-3.5" />
                          </button>

                          {sendMenuOpen && (
                            <div
                              className={`absolute right-0 bottom-full mb-1.5 w-64 rounded-xl shadow-2xl border py-1.5 z-50 ${
                                isDark
                                  ? 'bg-[#242526] border-[#3e4042] text-[#e4e6eb]'
                                  : 'bg-white border-gray-200 text-gray-800'
                              }`}
                            >
                              <button
                                type="button"
                                onClick={() => handleSendReply(false)}
                                className={`w-full text-left px-3.5 py-2 text-xs font-semibold transition cursor-pointer ${
                                  isDark ? 'hover:bg-[#3a3b3c]' : 'hover:bg-gray-100'
                                }`}
                              >
                                Send reply (keep In Progress)
                              </button>
                              <button
                                type="button"
                                onClick={() => handleSendReply(true)}
                                className={`w-full text-left px-3.5 py-2 text-xs font-semibold transition cursor-pointer ${
                                  isDark ? 'hover:bg-[#3a3b3c]' : 'hover:bg-gray-100'
                                }`}
                              >
                                Send &amp; set Pending Client (pause SLA)
                              </button>
                            </div>
                          )}
                        </>
                      )}
                    </div>
                  </div>
                </div>
              </div>
            ) : (
              <div className="text-center text-xs text-gray-500 py-1">
                {isStaff
                  ? 'Replying is unavailable for this ticket in its current state or permission scope.'
                  : 'Maximum return limit reached — no additional replies can be added.'}
              </div>
            )}
          </div>
        )}
      </div>

      {/* Mandatory Resolution Note Modal */}
      {showResolveModal && (
        <div className="fixed inset-0 z-10000 bg-black/70 backdrop-blur-xs flex items-center justify-center p-4">
          <div
            className={`w-full max-w-md rounded-xl p-6 shadow-2xl border ${
              isDark ? 'bg-[#242526] border-[#3e4042] text-white' : 'bg-white border-[#800000]/20 text-gray-900'
            }`}
          >
            <div className="flex items-center gap-2 mb-2 text-green-600 dark:text-green-400">
              <CheckCircleIcon className="w-5 h-5" />
              <h4 className="font-bold text-base">Record Resolution Note</h4>
            </div>
            <p className="text-xs text-gray-500 mb-4 leading-relaxed">
              Staff must record an explanation of how the concern was addressed (at least{' '}
              {RESOLUTION_MIN_LENGTH} characters) before marking the ticket Resolved.
            </p>

            <form onSubmit={handleConfirmResolve}>
              <VoiceTextareaInput
                id="ticket-resolution-note"
                label="Resolution Details"
                labelColor="text-[11px] font-semibold uppercase tracking-wide mb-1 block"
                value={resolutionNote}
                onChange={(val) => {
                  setResolutionNote(val);
                  setResolveError('');
                }}
                rows={3}
                placeholder="Explain the resolution and steps taken..."
                required
                error={resolveError}
              />

              <div className="flex justify-end gap-2 mt-4 pt-3 border-t border-gray-200 dark:border-gray-700">
                <button
                  type="button"
                  onClick={() => setShowResolveModal(false)}
                  className="px-4 py-2 rounded-full text-xs font-bold border border-gray-300 dark:border-gray-600 cursor-pointer"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  disabled={actionBusy || cooldown.isCoolingDown}
                  className="px-5 py-2 rounded-full text-xs font-bold uppercase tracking-wider bg-green-600 hover:bg-green-700 text-white cursor-pointer shadow disabled:opacity-50"
                >
                  {actionBusy ? 'Resolving…' : 'Confirm & Resolve'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* Reopen / Return Ticket Modal (Requester) */}
      {showReopenModal && (
        <div className="fixed inset-0 z-10000 bg-black/70 backdrop-blur-xs flex items-center justify-center p-4">
          <div
            className={`w-full max-w-md rounded-xl p-6 shadow-2xl border ${
              isDark ? 'bg-[#242526] border-[#3e4042] text-white' : 'bg-white border-[#800000]/20 text-gray-900'
            }`}
          >
            <div className="flex items-center gap-2 mb-2 text-amber-600 dark:text-amber-400">
              <ArrowPathIcon className="w-5 h-5" />
              <h4 className="font-bold text-base">Request Ticket Reopening</h4>
            </div>
            <p className="text-xs text-gray-500 mb-4 leading-relaxed">
              Please explain why the issue persists so Registrar Staff can follow up.
              {typeof ticket?.returns_remaining === 'number'
                ? ` (${ticket.returns_remaining} return${ticket.returns_remaining === 1 ? '' : 's'} remaining)`
                : ''}
            </p>

            <form onSubmit={handleConfirmReopen}>
              <VoiceTextareaInput
                id="ticket-reopen-reason"
                label="Reason for Reopening"
                labelColor="text-[11px] font-semibold uppercase tracking-wide mb-1 block"
                value={reopenReason}
                onChange={(val) => {
                  setReopenReason(val);
                  setReopenError('');
                }}
                rows={3}
                placeholder="Describe why the issue is still unresolved..."
                error={reopenError}
              />

              <div className="flex justify-end gap-2 mt-4 pt-3 border-t border-gray-200 dark:border-gray-700">
                <button
                  type="button"
                  onClick={() => setShowReopenModal(false)}
                  className="px-4 py-2 rounded-full text-xs font-bold border border-gray-300 dark:border-gray-600 cursor-pointer"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  disabled={actionBusy || cooldown.isCoolingDown}
                  className="px-5 py-2 rounded-full text-xs font-bold uppercase tracking-wider bg-amber-600 hover:bg-amber-700 text-white cursor-pointer shadow disabled:opacity-50"
                >
                  {actionBusy ? 'Submitting…' : 'Return to In Progress'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* Flag Spam Modal (Staff with Manage permission) */}
      {showSpamModal && (
        <div className="fixed inset-0 z-10000 bg-black/70 backdrop-blur-xs flex items-center justify-center p-4">
          <div
            className={`w-full max-w-md rounded-xl p-6 shadow-2xl border ${
              isDark ? 'bg-[#242526] border-[#3e4042] text-white' : 'bg-white border-[#800000]/20 text-gray-900'
            }`}
          >
            <div className="flex items-center gap-2 mb-2 text-rose-600 dark:text-rose-400">
              <ShieldExclamationIcon className="w-5 h-5" />
              <h4 className="font-bold text-base">Flag Ticket as Spam</h4>
            </div>
            <p className="text-xs text-gray-500 mb-4 leading-relaxed">
              Records a spam audit event on {ticket?.ticket_number}. For public submissions, you can
              also block the submitter&apos;s email or IP address.
            </p>

            <form onSubmit={handleFlagSpam} className="space-y-3">
              <VoiceTextareaInput
                id="ticket-spam-reason"
                label="Reason (Optional)"
                labelColor="text-[11px] font-semibold uppercase tracking-wide mb-1 block"
                value={spamReason}
                onChange={(val) => {
                  setSpamReason(val);
                  setSpamError('');
                }}
                rows={2}
                placeholder="Reason for flagging this submission..."
                error={spamError}
              />

              {isPublicRequester && (
                <div className="space-y-2 pt-1">
                  <CheckboxItem
                    id="spam-block-email"
                    label={`Block submitter email${
                      ticket?.requester?.email ? ` (${ticket.requester.email})` : ''
                    }`}
                    checked={spamBlockEmail}
                    onChange={(e) => setSpamBlockEmail(e.target.checked)}
                  />
                  <CheckboxItem
                    id="spam-block-ip"
                    label="Block submitter IP address"
                    checked={spamBlockIp}
                    onChange={(e) => setSpamBlockIp(e.target.checked)}
                  />
                  {(spamBlockEmail || spamBlockIp) && (
                    <InputGroup
                      type="datetime-local"
                      label="Block Expiration (Optional — leave blank until lifted)"
                      name="block_expires_at"
                      value={spamExpiresAt}
                      onChange={(e) => setSpamExpiresAt(e.target.value)}
                      voiceEnabled={false}
                      labelColor="text-[11px] font-semibold uppercase tracking-wide text-gray-700 dark:text-gray-300"
                    />
                  )}
                </div>
              )}

              <div className="flex justify-end gap-2 mt-4 pt-3 border-t border-gray-200 dark:border-gray-700">
                <button
                  type="button"
                  onClick={() => setShowSpamModal(false)}
                  className="px-4 py-2 rounded-full text-xs font-bold border border-gray-300 dark:border-gray-600 cursor-pointer"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  disabled={actionBusy || cooldown.isCoolingDown}
                  className="px-5 py-2 rounded-full text-xs font-bold uppercase tracking-wider bg-rose-600 hover:bg-rose-700 text-white cursor-pointer shadow disabled:opacity-50"
                >
                  {actionBusy ? 'Flagging…' : 'Flag Spam'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* Requester Confirm Resolution Modal */}
      <ConfirmationModal
        isOpen={showConfirmCloseModal}
        onClose={() => setShowConfirmCloseModal(false)}
        onConfirm={handleRequesterConfirmClose}
        type="confirm"
        title="Confirm Ticket Resolution"
        message="Confirming closes this ticket permanently. Closed tickets cannot be reopened."
        confirmText="Confirm & Close"
      />
    </div>
  );
};

TicketDetailModal.propTypes = {
  ticket: PropTypes.object,
  ticketId: PropTypes.string,
  isOpen: PropTypes.bool.isRequired,
  onClose: PropTypes.func.isRequired,
  onTicketUpdated: PropTypes.func,
};

export default TicketDetailModal;
