import React, { useState, useRef, useEffect } from 'react';
import PropTypes from 'prop-types';
import {
  XMarkIcon,
  PaperAirplaneIcon,
  CheckCircleIcon,
  ArrowPathIcon,
  LockClosedIcon,
  ChatBubbleLeftRightIcon,
  ClockIcon,
  EnvelopeIcon,
  ChevronDownIcon,
  SparklesIcon,
} from '@heroicons/react/24/outline';
import { useAuth } from '../../context/AuthProvider';
import { useTheme } from '../../context/ThemeContext';
import { useTickets } from '../../context/TicketContext';
import VoiceTextareaInput from '../VoiceTextareaInput.jsx';

const QUICK_REPLIES = [
  'Thank you for reaching out. We are currently looking into this matter.',
  'Please provide a screenshot of the error message for faster investigation.',
  'Your request has been verified and forwarded to the technical team.',
  'The requested change has been made. Please log out and back in to verify.',
];

const TicketDetailModal = ({ ticket, isOpen, onClose }) => {
  const { isDark } = useTheme();
  const { user } = useAuth();
  const { addMessage, updateTicketStatus, reopenTicket, claimActiveHandling, releaseActiveHandling } = useTickets();

  const [activeTab, setActiveTab] = useState('conversation'); // 'conversation' | 'activity'
  const [replyText, setReplyText] = useState('');
  const [resolutionNote, setResolutionNote] = useState('');
  const [showResolveModal, setShowResolveModal] = useState(false);
  const [reopenReason, setReopenReason] = useState('');
  const [showReopenModal, setShowReopenModal] = useState(false);
  const [statusDropdownOpen, setStatusDropdownOpen] = useState(false);
  const [showQuickReplies, setShowQuickReplies] = useState(false);
  const statusDropdownRef = useRef(null);

  // Close status dropdown when clicking outside
  useEffect(() => {
    if (!statusDropdownOpen) return;
    const handleClickOutside = (event) => {
      if (statusDropdownRef.current && !statusDropdownRef.current.contains(event.target)) {
        setStatusDropdownOpen(false);
      }
    };
    document.addEventListener('mousedown', handleClickOutside);
    return () => document.removeEventListener('mousedown', handleClickOutside);
  }, [statusDropdownOpen]);

  if (!isOpen || !ticket) return null;

  const isStaff = user?.role_name === 'admin' || user?.role_name === 'super_admin';
  const isClosed = ticket.status === 'Closed';
  const isResolved = ticket.status === 'Resolved';

  const formatTimelineDate = (dateStr) => {
    if (!dateStr) return '—';
    const d = new Date(dateStr);
    return (
      d.toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
      }) +
      ', ' +
      d.toLocaleTimeString('en-US', {
        hour: 'numeric',
        minute: '2-digit',
        hour12: true,
      })
    );
  };

  const handleSendReply = (e) => {
    if (e && e.preventDefault) e.preventDefault();
    if (!replyText.trim()) return;

    addMessage(ticket.id, replyText, false);
    setReplyText('');
  };

  const handleKeyDown = (e) => {
    if (e.ctrlKey && e.key === 'Enter') {
      e.preventDefault();
      handleSendReply();
    }
  };

  const handleStatusChangeClick = (targetStatus) => {
    setStatusDropdownOpen(false);
    setStatusError('');
    if (targetStatus === 'Resolved') {
      setShowResolveModal(true);
      return;
    }
    updateTicketStatus(ticket.id, targetStatus);
  };

  const handleConfirmResolve = (e) => {
    e.preventDefault();
    if (!resolutionNote.trim()) {
      setStatusError('Policy requirement: A resolution note must be provided before marking as Resolved.');
      return;
    }
    updateTicketStatus(ticket.id, 'Resolved', resolutionNote.trim());
    setResolutionNote('');
    setShowResolveModal(false);
  };

  const handleConfirmReopen = (e) => {
    e.preventDefault();
    reopenTicket(ticket.id, reopenReason.trim());
    setReopenReason('');
    setShowReopenModal(false);
  };

  const handleToggleClaim = () => {
    if (ticket.active_handler) {
      releaseActiveHandling(ticket.id);
    } else {
      claimActiveHandling(ticket.id);
    }
  };

  // Status badge styling for the pill in header matching StaffDashboard StatusBadge
  const getHeaderStatusPill = () => {
    const s = ticket.status;
    const normalized = String(s || '').trim().toLowerCase();

    // StaffDashboard StatusBadge styling
    const styles = isDark
      ? {
          open: 'bg-yellow-900/40 text-yellow-300 border-yellow-500/60',
          'in progress': 'bg-blue-900/40 text-blue-300 border-blue-500/60',
          'waiting for student': 'bg-amber-900/40 text-amber-300 border-amber-500/60',
          resolved: 'bg-green-900/40 text-green-300 border-green-500/60',
          closed: 'bg-gray-700/40 text-gray-200 border-gray-500/60',
        }
      : {
          open: 'bg-yellow-100 text-yellow-800 border-yellow-300',
          'in progress': 'bg-blue-100 text-blue-800 border-blue-300',
          'waiting for student': 'bg-amber-100 text-amber-800 border-amber-300',
          resolved: 'bg-green-100 text-green-800 border-green-300',
          closed: 'bg-gray-100 text-gray-800 border-gray-300',
        };

    const badgeClass =
      styles[normalized] || (isDark ? 'bg-gray-700/40 text-gray-200 border-gray-500/60' : 'bg-gray-100 text-gray-700 border-gray-300');

    return (
      <div className="relative inline-flex items-center" ref={statusDropdownRef}>
        <button
          type="button"
          onClick={() => isStaff && setStatusDropdownOpen((prev) => !prev)}
          className={`px-3 py-1 rounded-full text-[12px] font-bold border whitespace-nowrap inline-flex items-center gap-1.5 transition ${badgeClass} ${
            isStaff ? 'cursor-pointer hover:opacity-90' : 'cursor-default'
          }`}
        >
          <span>{s}</span>
          {isStaff && <ChevronDownIcon className="w-3.5 h-3.5 opacity-80" />}
        </button>

        {/* Dropdown status selector */}
        {statusDropdownOpen && (
          <div
            className={`absolute left-0 top-full mt-1.5 w-48 rounded-xl shadow-2xl border py-1.5 z-100 ${
              isDark ? 'bg-[#242526] border-[#3e4042] text-[#e4e6eb]' : 'bg-white border-gray-200 text-gray-800'
            }`}
          >
            {['Open', 'In Progress', 'Waiting for Student', 'Resolved', 'Closed'].map((opt) => (
              <button
                key={opt}
                type="button"
                onClick={() => handleStatusChangeClick(opt)}
                className={`w-full text-left px-4 py-2 text-xs font-semibold flex items-center justify-between transition-colors ${
                  isDark ? 'hover:bg-[#3a3b3c]' : 'hover:bg-gray-100'
                } ${ticket.status === opt ? 'font-bold text-pup-dark-maroon dark:text-yellow-400 bg-black/5 dark:bg-white/5' : ''}`}
              >
                <span>{opt}</span>
                {ticket.status === opt && <span className="w-1.5 h-1.5 rounded-full bg-current" />}
              </button>
            ))}
          </div>
        )}
      </div>
    );
  };

  return (
    <div className="fixed inset-0 z-9999 flex items-center justify-center p-2 sm:p-4 backdrop-blur-sm bg-black/60 overflow-y-auto">
      <div
        className={`relative z-9999 w-full max-w-2xl my-auto rounded-xl shadow-2xl overflow-hidden border flex flex-col max-h-[92vh] animate-in fade-in duration-150 ${
          isDark ? 'bg-[#1e1f20] border-[#3e4042] text-[#e4e6eb]' : 'bg-white border-gray-200 text-gray-900'
        }`}
      >
        {/* ---------------- 1. TOP MAROON HEADER ---------------- */}
        <div className="bg-[#6B1818] text-white px-5 py-4 shrink-0">
          <div className="flex items-start justify-between gap-4">
            <div>
              <span className="text-[11px] font-bold uppercase tracking-wider text-amber-200/90 block">
                SUPPORT TICKET • {ticket.category || 'TECHNICAL'}
              </span>
              <div className="flex items-center gap-2.5 mt-0.5 flex-wrap">
                <h2 className="text-2xl sm:text-3xl font-extrabold tracking-tight">
                  {ticket.ticket_number}
                </h2>
                {getHeaderStatusPill()}
              </div>
              <div className="flex items-center gap-1.5 text-xs text-white/90 mt-1 font-normal">
                <EnvelopeIcon className="w-3.5 h-3.5 text-white/80 shrink-0" />
                <span>
                  {ticket.creator?.name || 'Student Requestor'} ({ticket.creator?.email || 'user@iskolarngbayan.pup.edu.ph'})
                </span>
              </div>
            </div>

            <div className="flex items-center gap-3">
              <button
                type="button"
                onClick={onClose}
                className="text-white/80 hover:text-white transition p-1 rounded-md"
                title="Close modal"
              >
                <XMarkIcon className="w-6 h-6 stroke-[2.2]" />
              </button>
            </div>
          </div>
        </div>

        {/* ---------------- 2. SUB-HEADER / META ROW ---------------- */}
        <div
          className={`px-5 py-2.5 border-b flex flex-wrap items-center justify-between gap-3 text-xs ${
            isDark ? 'bg-[#252728] border-[#333537]' : 'bg-white border-gray-100'
          }`}
        >
          {/* Subject title */}
          <div className="flex items-center gap-2.5 min-w-0">
            <span className="font-semibold text-xs sm:text-sm truncate text-gray-700 dark:text-gray-200">
              {ticket.subject}
            </span>
          </div>
        </div>

        {/* ---------------- 3. TABS (Conversation / Activity) ---------------- */}
        <div
          className={`flex border-b px-5 text-xs sm:text-sm font-semibold ${
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
            <span className="text-[11px] font-normal text-gray-500">
              {ticket.messages?.length || 1}
            </span>
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
            <span className="text-[11px] font-normal text-gray-500">
              {ticket.activities?.length || 1}
            </span>
          </button>
        </div>

        {/* ---------------- 4. MODAL BODY (Messages or Audit Trail) ---------------- */}
        <div className="flex-1 overflow-y-auto p-5 space-y-4">
          {activeTab === 'conversation' ? (
            <div className="space-y-4">
              {/* Initial Student Post */}
              <div
                className={`p-4 rounded-xl text-xs sm:text-sm leading-relaxed ${
                  isDark ? 'bg-[#282a2c] text-gray-200' : 'bg-gray-50 text-gray-800'
                }`}
              >
                <div className="flex items-center justify-between mb-1 text-xs">
                  <span className="font-bold text-gray-900 dark:text-white">
                    {ticket.creator?.name || 'John Carlo Santos'}
                  </span>
                  <span className="text-[11px] text-gray-400">
                    {formatTimelineDate(ticket.created_at)}
                  </span>
                </div>
                <p className="whitespace-pre-wrap">{ticket.description}</p>

                {ticket.attachments && ticket.attachments.length > 0 && (
                  <div className="mt-3 pt-2 border-t border-gray-200 dark:border-gray-700 flex flex-wrap gap-2">
                    {ticket.attachments.map((att, i) => (
                      <div
                        key={i}
                        className="text-[11px] px-2 py-1 rounded bg-white dark:bg-black/30 border border-gray-200 dark:border-gray-700 flex items-center gap-1.5"
                      >
                        📎 <span className="font-medium truncate max-w-[140px]">{att.name}</span>
                        <span className="text-[10px] text-gray-400">({att.size})</span>
                      </div>
                    ))}
                  </div>
                )}
              </div>

              {/* Message Thread (Regular replies) */}
              {ticket.messages
                ?.filter((m) => m.content !== ticket.description && !m.is_internal)
                .map((msg) => {

                  const isStaff = msg.sender_type === 'staff';
                  return (
                    <div
                      key={msg.id}
                      className={`flex flex-col ${isStaff ? 'items-end' : 'items-start'}`}
                    >
                      <div
                        className={`max-w-[85%] rounded-2xl p-4 text-xs sm:text-sm leading-relaxed shadow-xs ${
                          isStaff
                            ? isDark
                              ? 'bg-pup-dark-maroon text-white border border-red-900/40'
                              : 'bg-[#6B1818] text-white'
                            : isDark
                            ? 'bg-[#282a2c] text-gray-200'
                            : 'bg-gray-100 text-gray-900'
                        }`}
                      >
                        <div className="flex items-center justify-between gap-4 mb-1 text-xs">
                          <span className={`font-bold ${isStaff ? 'text-amber-200' : 'text-gray-900 dark:text-white'}`}>
                            {msg.sender_name} {isStaff && '(Staff)'}
                          </span>
                          <span className={`text-[10px] ${isStaff ? 'text-white/70' : 'text-gray-400'}`}>
                            {formatTimelineDate(msg.timestamp)}
                          </span>
                        </div>
                        <p className="whitespace-pre-wrap">{msg.content}</p>
                      </div>
                    </div>
                  );
                })}

              {/* Resolution Note if resolved */}
              {ticket.resolution_note && (
                <div
                  className={`p-3.5 rounded-xl border flex items-start gap-2.5 text-xs ${
                    isDark
                      ? 'bg-green-950/20 border-green-800/40 text-green-300'
                      : 'bg-green-50 border-green-200 text-green-800'
                  }`}
                >
                  <CheckCircleIcon className="w-4 h-4 text-green-600 shrink-0 mt-0.5" />
                  <div>
                    <span className="font-bold">Official Resolution: </span>
                    {ticket.resolution_note}
                  </div>
                </div>
              )}
            </div>
          ) : (
            /* History & Audit Trail */
            <div className="space-y-4">
              <h4 className="text-xs font-bold uppercase tracking-wider text-gray-500">
                Timeline & Event Logs
              </h4>
              <div className="relative pl-5 space-y-4">
                <div
                  className={`absolute left-1.5 top-2 bottom-2 w-0.5 ${
                    isDark ? 'bg-gray-700' : 'bg-gray-200'
                  }`}
                />
                {ticket.activities?.map((act) => (
                  <div key={act.id} className="relative flex flex-col text-xs">
                    <div
                      className={`absolute -left-5 top-1 w-2.5 h-2.5 rounded-full ${
                        isDark ? 'bg-yellow-400 ring-4 ring-[#1e1f20]' : 'bg-[#6B1818] ring-4 ring-white'
                      }`}
                    />
                    <span className="font-bold">{act.text}</span>
                    <span className="text-[10px] text-gray-400 mt-0.5">
                      {formatTimelineDate(act.timestamp)} • Actor: {act.actor}
                    </span>
                  </div>
                ))}
              </div>
            </div>
          )}
        </div>

        {/* ---------------- 5. BOTTOM REPLY / INTERNAL NOTE FORM ---------------- */}
        {isClosed ? (
          <div
            className={`p-4 text-center text-xs font-semibold border-t ${
              isDark ? 'bg-[#18191a] text-gray-400 border-[#333537]' : 'bg-gray-50 text-gray-500 border-gray-200'
            }`}
          >
            🔒 This ticket is closed and archived (Policy §7).
          </div>
        ) : (
          <div
            className={`border-t px-5 py-3 ${
              isDark ? 'bg-[#1e1f20] border-[#333537]' : 'bg-white border-gray-200'
            }`}
          >
            {/* Reopen Request Banner if resolved */}
            {!isStaff && isResolved && (
              <div className="flex items-center justify-between py-1 mb-2">
                <span className="text-xs text-gray-500">Need more assistance on this issue?</span>
                <button
                  type="button"
                  onClick={() => setShowReopenModal(true)}
                  className="px-3 py-1 text-xs font-bold bg-amber-600 hover:bg-amber-700 text-white rounded-full flex items-center gap-1 cursor-pointer"
                >
                  <ArrowPathIcon className="w-3.5 h-3.5" />
                  Request Reopen
                </button>
              </div>
            )}

            {/* Reply Input Box */}
            <div
              className={`rounded-xl border p-2.5 transition-all ${
                isDark
                  ? 'border-[#3e4042] bg-[#242526]'
                  : 'border-gray-200 bg-white'
              }`}
            >
              <textarea
                value={replyText}
                onChange={(e) => setReplyText(e.target.value)}
                onKeyDown={handleKeyDown}
                placeholder="Write a reply..."
                rows={3}
                className={`w-full text-xs sm:text-sm bg-transparent outline-none resize-none placeholder-gray-400 leading-relaxed ${
                  isDark ? 'text-white' : 'text-gray-900'
                }`}
              />

              {/* Quick Replies Tray */}
              {showQuickReplies && (
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
                      className={`w-full text-left p-1.5 rounded transition ${
                        isDark ? 'hover:bg-[#2e2f30]' : 'hover:bg-gray-200'
                      }`}
                    >
                      {qr}
                    </button>
                  ))}
                </div>
              )}

              {/* Action Toolbar below textarea */}
              <div className="flex items-center justify-between pt-2 border-t border-gray-100 dark:border-gray-800 text-xs">
                {/* Left quick replies button */}
                <button
                  type="button"
                  onClick={() => setShowQuickReplies((prev) => !prev)}
                  className="flex items-center gap-1.5 text-gray-500 hover:text-gray-800 dark:hover:text-white font-medium cursor-pointer"
                >
                  <SparklesIcon className="w-4 h-4 text-gray-400" />
                  <span>Quick replies</span>
                </button>

                {/* Right controls */}
                <div className="flex items-center gap-3">
                  <span className="hidden sm:inline text-[11px] text-gray-400">Ctrl + Enter to send</span>

                  {/* Send Reply Split Button */}
                  <div className="inline-flex rounded-lg shadow-sm">
                    <button
                      type="button"
                      disabled={!replyText.trim()}
                      onClick={handleSendReply}
                      className={`flex items-center gap-2 px-4 py-2 rounded-l-lg font-bold text-xs transition cursor-pointer ${
                        replyText.trim()
                          ? 'bg-[#6B1818] hover:bg-[#521313] text-white'
                          : 'bg-gray-300 dark:bg-gray-700 text-gray-500 cursor-not-allowed'
                      }`}
                    >
                      <PaperAirplaneIcon className="w-3.5 h-3.5" />
                      <span>Send reply</span>
                    </button>
                    <button
                      type="button"
                      disabled={!replyText.trim()}
                      onClick={handleSendReply}
                      className={`px-2 py-2 border-l border-white/20 rounded-r-lg font-bold text-xs transition cursor-pointer ${
                        replyText.trim()
                          ? 'bg-[#6B1818] hover:bg-[#521313] text-white'
                          : 'bg-gray-300 dark:bg-gray-700 text-gray-500 cursor-not-allowed'
                      }`}
                    >
                      <ChevronDownIcon className="w-3.5 h-3.5" />
                    </button>
                  </div>
                </div>
              </div>
            </div>
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
              Staff must record an explanation of how the concern was addressed before resolving the ticket (Policy
              §5 & §7).
            </p>

            <form onSubmit={handleConfirmResolve}>
              <VoiceTextareaInput
                id="ticket-resolution-note"
                label="Resolution Details"
                labelColor="text-[11px] font-semibold uppercase tracking-wide mb-1 block"
                value={resolutionNote}
                onChange={(val) => setResolutionNote(val)}
                rows={3}
                placeholder="Explain the resolution and steps taken..."
                required
                error={statusError}
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
                  className="px-5 py-2 rounded-full text-xs font-bold uppercase tracking-wider bg-green-600 hover:bg-green-700 text-white cursor-pointer shadow"
                >
                  Confirm & Resolve
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* Reopen Ticket Modal */}
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
              Please explain why the issue persists. Conversation history will be preserved.
            </p>

            <form onSubmit={handleConfirmReopen}>
              <VoiceTextareaInput
                id="ticket-reopen-reason"
                label="Reason for Reopening"
                labelColor="text-[11px] font-semibold uppercase tracking-wide mb-1 block"
                value={reopenReason}
                onChange={(val) => setReopenReason(val)}
                rows={3}
                placeholder="Describe why the issue is still unresolved..."
                required
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
                  className="px-5 py-2 rounded-full text-xs font-bold uppercase tracking-wider bg-amber-600 hover:bg-amber-700 text-white cursor-pointer shadow"
                >
                  Reopen Ticket
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  );
};

TicketDetailModal.propTypes = {
  ticket: PropTypes.object,
  isOpen: PropTypes.bool.isRequired,
  onClose: PropTypes.func.isRequired,
};

export default TicketDetailModal;
