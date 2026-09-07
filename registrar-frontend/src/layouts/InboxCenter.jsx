import React, { useEffect, useMemo, useRef, useState } from 'react';
import {
  ArrowLeftIcon,
  EnvelopeOpenIcon,
  QrCodeIcon,
  CheckCircleIcon,
  PencilSquareIcon,
  ExclamationTriangleIcon,
  CreditCardIcon,
  MegaphoneIcon,
  DocumentTextIcon,
  MapPinIcon,
  EllipsisHorizontalIcon,
  ArrowTopRightOnSquareIcon,
  InformationCircleIcon,
} from '@heroicons/react/24/outline';
import { useLocation } from 'react-router-dom';
import VoiceSearchInput from '../components/VoiceSearchInput.jsx';
import { useNotificationsContext as useNotifications } from '../context/NotificationsContext';
import { useTheme } from '../context/ThemeContext';
import { InboxListSkeleton, InboxPreviewSkeleton } from '../components/LoadingSkeleton';
import ClaimTicket from '../components/ClaimTicket';
import RequirementsListCard from '../components/RequirementsListCard';
import qrCode from '../assets/qrcode.png';
// CATEGORY_MAP lives in src/constants/notificationCategories.js
// InboxCenter only uses the .category label from each entry.
import { CATEGORY_MAP } from '../constants/notificationCategories';
import {
  formatDateFull,
  formatTimeShort,
  getDateGroup,
  getNotificationProgress,
  getProgressLabel,
  filterEmails,
  groupEmailsByDate,
  getNotificationFlags,
  getRequirementsList,
} from '../utils/inboxHelpers';

const renderNotificationIcon = (mail) => {
  const type = mail._raw?.type || '';
  const title = (mail.subject || mail.from || '').toLowerCase();

  if (type === 'ready_to_claim' || title.includes('ready')) {
    return (
      <div className="w-8 h-8 rounded-full bg-[#D7EFE9] text-[#145C4B] dark:bg-[#1C3630] dark:text-[#5FE1C5] border border-teal-500/20 flex items-center justify-center shrink-0">
        <QrCodeIcon className="w-4 h-4" />
      </div>
    );
  }
  if (type === 'request_completed' || title.includes('completed') || title.includes('claimed')) {
    return (
      <div className="w-8 h-8 rounded-full bg-gray-100 text-gray-600 dark:bg-[#2A2B2E] dark:text-gray-300 border border-gray-300/30 dark:border-gray-700 flex items-center justify-center shrink-0">
        <CheckCircleIcon className="w-4 h-4" />
      </div>
    );
  }
  if (type === 'pending_signature' || title.includes('signature')) {
    return (
      <div className="w-8 h-8 rounded-full bg-amber-100 text-amber-700 dark:bg-[#2A2B2E] dark:text-amber-300 border border-amber-300/30 dark:border-gray-700 flex items-center justify-center shrink-0">
        <PencilSquareIcon className="w-4 h-4" />
      </div>
    );
  }
  if (type === 'request_forfeited' || title.includes('forfeited') || type === 'payment_invalid') {
    return (
      <div className="w-8 h-8 rounded-full bg-rose-100 text-rose-700 dark:bg-rose-950/40 dark:text-rose-400 border border-rose-300/30 dark:border-rose-800/40 flex items-center justify-center shrink-0">
        <ExclamationTriangleIcon className="w-4 h-4" />
      </div>
    );
  }
  if (type === 'request_withdrawn' || title.includes('withdrawn') || type === 'deficiency_notice_voided' || type === 'deficiency_notice_escalated' || type === 'request_closed_unable_to_process' || title.includes('deficiency notice voided') || title.includes('unable to process')) {
    return (
      <div className="w-8 h-8 rounded-full bg-red-100 text-red-700 dark:bg-red-950/40 dark:text-red-400 border border-red-300/30 dark:border-red-800/40 flex items-center justify-center shrink-0">
        <ExclamationTriangleIcon className="w-4 h-4" />
      </div>
    );
  }
  if (type === 'deficiency_notice_issued' || title.includes('deficiency notice')) {
    return (
      <div className="w-8 h-8 rounded-full bg-amber-100 text-amber-700 dark:bg-amber-950/40 dark:text-amber-300 border border-amber-300/30 dark:border-amber-800/40 flex items-center justify-center shrink-0">
        <InformationCircleIcon className="w-4 h-4" />
      </div>
    );
  }
  if (type === 'payment_verified' || title.includes('payment')) {
    return (
      <div className="w-8 h-8 rounded-full bg-emerald-100 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-400 border border-emerald-300/30 dark:border-emerald-800/40 flex items-center justify-center shrink-0">
        <CreditCardIcon className="w-4 h-4" />
      </div>
    );
  }
  if (type.includes('announcement')) {
    return (
      <div className="w-8 h-8 rounded-full bg-purple-100 text-purple-700 dark:bg-purple-950/40 dark:text-purple-400 border border-purple-300/30 dark:border-purple-800/40 flex items-center justify-center shrink-0">
        <MegaphoneIcon className="w-4 h-4" />
      </div>
    );
  }
  return (
    <div className="w-8 h-8 rounded-full bg-blue-100 text-blue-700 dark:bg-blue-950/40 dark:text-blue-400 border border-blue-300/30 dark:border-blue-800/40 flex items-center justify-center shrink-0">
      <DocumentTextIcon className="w-4 h-4" />
    </div>
  );
};

// -------------------------------------------------------
// Map a raw API notification → inbox display shape
// -------------------------------------------------------
const toMailItem = (n) => {
  const type = n.type || '';
  const title = n.title || '';

  let dotColor = null;
  if (
    type === 'request_forfeited' ||
    title.toLowerCase().includes('forfeited') ||
    type === 'request_withdrawn' ||
    title.toLowerCase().includes('withdrawn') ||
    type === 'deficiency_notice_voided' ||
    type === 'deficiency_notice_escalated' ||
    type === 'request_closed_unable_to_process' ||
    title.toLowerCase().includes('deficiency notice voided')
  ) {
    dotColor = 'bg-red-500';
  } else if (
    type === 'deficiency_notice_issued' ||
    title.toLowerCase().includes('deficiency notice')
  ) {
    dotColor = 'bg-amber-500';
  } else if (
    type.startsWith('reminder_') ||
    title.toLowerCase().includes('reminder')
  ) {
    dotColor = 'bg-yellow-500';
  } else if (
    type === 'request_submitted' ||
    type === 'announcement_sent' ||
    type === 'announcement_published' ||
    title.toLowerCase().includes('announcement') ||
    title.toLowerCase().includes('submitted')
  ) {
    dotColor = 'bg-blue-500';
  } else if (
    type === 'request_completed' ||
    title.toLowerCase().includes('completed') ||
    title.toLowerCase().includes('claimed')
  ) {
    dotColor = 'bg-gray-500';
  } else if (
    type === 'ready_to_claim' ||
    type === 'admin_new_request' ||
    title.toLowerCase().includes('ready') ||
    title.toLowerCase().includes('new request') ||
    title.toLowerCase().includes('new document request')
  ) {
    dotColor = 'bg-green-500';
  } else if (
    type === 'awaiting_submission' ||
    title.toLowerCase().includes('source document required')
  ) {
    dotColor = 'bg-purple-500';
  }
  

  const mailItem = {
    id: n.id,
    from: n.title,
    email: 'no-reply@ris.local',
    subject: n.title,
    preview: n.message,
    category: CATEGORY_MAP[n.type]?.category ?? 'Notification',
    time: n.created_at,
    unread: !n.read_at,
    dotColor,
    // Keep original for markAsRead and requirements checklist
    _raw: n,
  };

  const lifecycleAlert = getLifecycleAlert(mailItem);
  const isPendingSignature = n.type === 'pending_signature' || String(n.title || '').toLowerCase().includes('signature');
  return {
    ...mailItem,
    preview: lifecycleAlert
      ? `${lifecycleAlert.title} ${lifecycleAlert.message}`
      : isPendingSignature
        ? 'Pending signature. Your document is awaiting signature from the authorized signatory before it can be released to you.'
      : mailItem.preview,
  };
};

const getLifecycleAlert = (mail) => {
  const type = mail?._raw?.type || '';
  const title = (mail?.subject || mail?.from || '').toLowerCase();
  const rawMessage = mail?._raw?.message || mail?.preview || '';

  const valueAfter = (prefix) => {
    const start = rawMessage.toLowerCase().indexOf(prefix.toLowerCase());
    return start === -1 ? '' : rawMessage.slice(start + prefix.length).replace(/^\s*[:.-]\s*/, '').trim();
  };

  if (type === 'request_withdrawn' || title.includes('withdrawn')) {
    const reason = mail?._raw?.withdrawal_reason || valueAfter('withdrawn:') || valueAfter('withdrawn');
    return {
      title: 'Withdrawn.',
      message: reason ? `Reason: ${reason}` : 'This request has been withdrawn and will not be fulfilled.',
      classes: 'bg-red-50 border-red-200 text-red-900 dark:bg-red-950/30 dark:border-red-700/50 dark:text-red-200',
      titleClasses: 'text-red-700 dark:text-red-300',
      iconClasses: 'text-red-600 dark:text-red-400',
    };
  }

  if (type === 'deficiency_notice_voided' || title.includes('deficiency notice voided')) {
    const reason = mail?._raw?.void_reason || valueAfter('voided:') || valueAfter('voided');
    return {
      title: 'Deficiency notice voided.',
      message: reason ? `Reason: ${reason}` : 'The deficiency notice has been voided. Please contact the Registrar\'s Office if you need assistance.',
      classes: 'bg-red-50 border-red-200 text-red-900 dark:bg-red-950/30 dark:border-red-700/50 dark:text-red-200',
      titleClasses: 'text-red-700 dark:text-red-300',
      iconClasses: 'text-red-600 dark:text-red-400',
    };
  }

  if (type === 'deficiency_notice_escalated' || title.includes('deficiency notice escalated')) {
    return {
      title: 'Deficiency notice escalated.',
      message: 'This notice has remained unresolved for 30+ days and requires Registrar review.',
      classes: 'bg-red-50 border-red-200 text-red-900 dark:bg-red-950/30 dark:border-red-700/50 dark:text-red-200',
      titleClasses: 'text-red-700 dark:text-red-300',
      iconClasses: 'text-red-600 dark:text-red-400',
    };
  }

  if (type === 'request_closed_unable_to_process' || title.includes('unable to process')) {
    return {
      title: 'Closed - Unable to Process.',
      message: mail?._raw?.closure_reason ? `Reason: ${mail._raw.closure_reason}` : 'This request could not be completed and has been closed.',
      classes: 'bg-red-50 border-red-200 text-red-900 dark:bg-red-950/30 dark:border-red-700/50 dark:text-red-200',
      titleClasses: 'text-red-700 dark:text-red-300',
      iconClasses: 'text-red-600 dark:text-red-400',
    };
  }

  if (type === 'deficiency_notice_issued' || title.includes('deficiency notice')) {
    const missingItem = mail?._raw?.item_label || valueAfter('on hold:') || valueAfter('deficiency notice:');
    return {
      title: 'Deficiency notice.',
      message: missingItem ? `Missing: ${missingItem}` : 'Your request is on hold until the missing item is submitted to the Registrar\'s Office.',
      classes: 'bg-amber-50 border-amber-200 text-amber-900 dark:bg-[#332200]/80 dark:border-amber-600/40 dark:text-amber-200',
      titleClasses: 'text-amber-500',
      iconClasses: 'text-amber-500',
    };
  }

  return null;
};

const InboxCenter = () => {
  const location = useLocation();
  const incomingNotificationId = location.state?.selectedNotificationId;
  const { isDark } = useTheme();

  const {
    notifications,
    loading,
    loadingMore,
    hasMore,
    loadMore,
    markAsRead,
    dismiss,
  } = useNotifications();

  // Derive inbox list from real notifications
  const emails = useMemo(() => notifications.map(toMailItem), [notifications]);

  const [selectedId, setSelectedId] = useState(incomingNotificationId || null);
  const [filterTab, setFilterTab] = useState('all');
  const [searchText, setSearchText] = useState('');
  const [isChecklistOpen, setIsChecklistOpen] = useState(true);
  const [mobileView, setMobileView] = useState(incomingNotificationId ? 'detail' : 'list');

  // Sync selectedId if state from navigation was passed
  useEffect(() => {
    if (incomingNotificationId) {
      setSelectedId(incomingNotificationId);
      setMobileView('detail');
    }
  }, [incomingNotificationId]);

  const filteredEmails = useMemo(() => {
    let result = emails;

    if (filterTab === 'unread') {
      result = result.filter((m) => m.unread);
    } else if (filterTab === 'requests') {
      result = result.filter((m) => m.category !== 'Announcement');
    }

    const key = searchText.trim().toLowerCase();
    if (!key) return result;
    return result.filter(
      (m) =>
        m.from.toLowerCase().includes(key) ||
        m.subject.toLowerCase().includes(key) ||
        m.preview.toLowerCase().includes(key)
    );
  }, [emails, filterTab, searchText]);

  const groupedEmails = useMemo(() => {
    const groups = { Today: [], Yesterday: [], Earlier: [] };
    filteredEmails.forEach((mail) => {
      const groupName = getDateGroup(mail.time);
      if (!groups[groupName]) {
        groups[groupName] = [];
      }
      groups[groupName].push(mail);
    });
    return groups;
  }, [filteredEmails]);

  const selectedMail = emails.find((m) => m.id === selectedId) ?? filteredEmails[0] ?? null;

  // Mark notification as read when selected/viewed
  useEffect(() => {
    if (selectedMail && selectedMail.unread) {
      markAsRead(selectedMail.id);
    }
  }, [selectedMail?.id, selectedMail?.unread, markAsRead]);

  const handleSelectMail = async (mailId) => {
    setSelectedId(mailId);
    setMobileView('detail');
    const mail = emails.find((m) => m.id === mailId);
    if (mail?.unread) {
      await markAsRead(mailId);
    }
  };

  // ── Infinite-scroll setup ───────────────────────────────────────────────
  const scrollRef = useRef(null);
  const sentinelRef = useRef(null);

  useEffect(() => {
    const sentinel = sentinelRef.current;
    const root = scrollRef.current;
    if (!sentinel || !root) return;

    const observer = new IntersectionObserver(
      ([entry]) => {
        if (entry.isIntersecting && hasMore && !loadingMore) {
          loadMore();
        }
      },
      { root, threshold: 0, rootMargin: '0px 0px 100px 0px' },
    );

    observer.observe(sentinel);
    return () => observer.disconnect();
  }, [hasMore, loadingMore, loadMore]);

  const progress = useMemo(() => getNotificationProgress(selectedMail), [selectedMail]);
  const lifecycleAlert = useMemo(() => getLifecycleAlert(selectedMail), [selectedMail]);

  const flags = useMemo(() => getNotificationFlags(selectedMail, progress), [selectedMail, progress]);
  const { isClaiming, isCompleted, isPendingSignature } = flags;

  const requirementsList = useMemo(
    () => getRequirementsList(selectedMail, notifications, isClaiming),
    [selectedMail, notifications, isClaiming]
  );

  return (
    <>
      <div className="w-full max-w-6xl mx-auto px-4">
        <div className={`overflow-hidden rounded-xl border shadow-sm ${isDark ? 'border-[#3e4042] bg-[#242526]' : 'border-gray-200 bg-white'}`}>
          <div className="grid grid-cols-1 lg:grid-cols-[320px_minmax(0,1fr)] min-h-[70vh]">

            {/* ── LEFT PANEL: inbox list ── */}
            <aside className={`border-r ${isDark ? 'border-[#3e4042] bg-[#242526]' : 'border-gray-200 bg-white'} ${mobileView === 'detail' ? 'hidden lg:block' : 'block'
              }`}>
              <div className={`px-4 py-4 border-b ${isDark ? 'border-[#3e4042]' : 'border-gray-200'}`}>
                <div className="flex items-center justify-between gap-2">
                  <h2 className={`text-lg font-bold ${isDark ? 'text-[#e4e6eb]' : 'text-gray-900'}`}>Inbox</h2>
                </div>
                <p className={`text-xs mt-1 ${isDark ? 'text-[#b0b3b8]' : 'text-gray-500'}`}>Select a message to view preview details.</p>
              </div>

              <div className={`p-3 border-b ${isDark ? 'border-[#3e4042]' : 'border-gray-200'}`}>
                <VoiceSearchInput
                  value={searchText}
                  onChange={setSearchText}
                  placeholder="Search"
                  language="en-US"
                />
              </div>

              {/* Filter Chips / Tabs */}
              <div className={`px-3 py-2 border-b flex items-center gap-1.5 overflow-x-auto no-scrollbar ${isDark ? 'border-[#3e4042]' : 'border-gray-200'}`}>
                <button
                  type="button"
                  onClick={() => setFilterTab('all')}
                  className={`px-3 py-1 rounded-full text-xs font-semibold transition-all cursor-pointer shrink-0 ${filterTab === 'all'
                    ? (isDark ? 'bg-pup-yellow text-pup-maroon font-bold' : 'bg-pup-maroon text-white font-bold')
                    : (isDark ? 'bg-[#3a3b3c] text-[#b0b3b8] hover:bg-[#4e4f50] hover:text-[#e4e6eb]' : 'bg-gray-100 text-gray-600 hover:bg-gray-200')
                    }`}
                >
                  All
                </button>

                <button
                  type="button"
                  onClick={() => setFilterTab('unread')}
                  className={`px-3 py-1 rounded-full text-xs font-semibold transition-all cursor-pointer shrink-0 ${filterTab === 'unread'
                    ? (isDark ? 'bg-pup-yellow text-pup-maroon font-bold' : 'bg-pup-maroon text-white font-bold')
                    : (isDark ? 'bg-[#3a3b3c] text-[#b0b3b8] hover:bg-[#4e4f50] hover:text-[#e4e6eb]' : 'bg-gray-100 text-gray-600 hover:bg-gray-200')
                    }`}
                >
                  Unread
                </button>
              </div>

              {/* Mail list container */}
              <div
                ref={scrollRef}
                className="max-h-[60vh] lg:max-h-[calc(72vh-130px)] overflow-y-auto"
              >
                {loading ? (
                  <InboxListSkeleton isDark={isDark} count={6} />
                ) : filteredEmails.length === 0 ? (
                  <div className={`p-8 text-center text-sm ${isDark ? 'text-[#b0b3b8]' : 'text-gray-500'}`}>
                    No messages found.
                  </div>
                ) : (
                  ['Today', 'Yesterday', 'Earlier'].map((groupName) => {
                    const items = groupedEmails[groupName];
                    if (!items || items.length === 0) return null;

                    return (
                      <div key={groupName} className="mb-1">
                        <div className={`px-4 pt-3 pb-1 text-xs font-semibold ${isDark ? 'text-[#b0b3b8]' : 'text-gray-500'}`}>
                          {groupName}
                        </div>
                        {items.map((mail) => {
                          const isActive = selectedMail?.id === mail.id;
                          const isUnread = mail.unread;

                          return (
                            <button
                              key={mail.id}
                              onClick={() => handleSelectMail(mail.id)}
                              className={`w-full text-left px-4 py-3 border-b transition-colors flex items-start gap-3 cursor-pointer ${isActive
                                ? (isDark ? 'bg-[#0B1E38] text-[#e4e6eb] border-[#132d54]' : 'bg-blue-50 text-gray-900 border-blue-100')
                                : (isDark ? 'hover:bg-[#3a3b3c] text-[#e4e6eb] border-[#3e4042]/50' : 'hover:bg-gray-50 text-gray-800 border-gray-100')
                                }`}
                            >
                              {renderNotificationIcon(mail)}
                              <div className="flex-1 min-w-0">
                                <div className="flex items-center justify-between gap-1.5">
                                  <p className={`text-sm truncate ${isUnread
                                    ? (isDark ? 'font-bold text-white' : 'font-bold text-gray-900')
                                    : (isDark ? 'font-normal text-[#b0b3b8]' : 'font-normal text-gray-500')
                                    }`}>
                                    {mail.from}
                                  </p>
                                  <div className="flex items-center gap-1 shrink-0">
                                    <span className={`text-[11px] ${isDark ? 'text-[#b0b3b8]' : 'text-gray-500'}`}>
                                      {formatTimeShort(mail.time)}
                                    </span>
                                    {isUnread && (
                                      <span className="w-2 h-2 rounded-full bg-red-500 shrink-0 ml-0.5" />
                                    )}
                                  </div>
                                </div>
                                <p className={`text-xs mt-0.5 line-clamp-2 ${isDark ? 'text-[#b0b3b8]' : 'text-gray-500'}`}>
                                  {mail.preview}
                                </p>
                              </div>
                            </button>
                          );
                        })}
                      </div>
                    );
                  })
                )}

                {/* Sentinel: observed by IntersectionObserver below */}
                <div ref={sentinelRef} aria-hidden="true" />

                {loadingMore && (
                  <div className="opacity-50">
                    <InboxListSkeleton isDark={isDark} count={2} />
                  </div>
                )}
              </div>
            </aside>

            {/* ── RIGHT PANEL: preview / compose ── */}
            <section className={`flex flex-col max-h-[70vh] lg:max-h-[calc(72vh)] ${isDark ? 'bg-[#242526]' : 'bg-white'} ${mobileView === 'list' ? 'hidden lg:flex' : 'flex'
              }`}>
              {loading ? (
                <InboxPreviewSkeleton isDark={isDark} />
              ) : selectedMail ? (
                <>
                  <header className={`px-4 md:px-6 py-4 border-b flex items-center justify-between gap-3 ${isDark ? 'border-[#3e4042] bg-[#242526]' : 'border-gray-200 bg-white'}`}>
                    <div className="flex items-center gap-3 min-w-0">
                      <button
                        type="button"
                        onClick={() => setMobileView('list')}
                        className={`lg:hidden p-1.5 rounded-lg transition-colors cursor-pointer shrink-0 ${isDark ? 'hover:bg-[#3a3b3c] text-[#e4e6eb]' : 'hover:bg-gray-100 text-gray-700'
                          }`}
                        title="Back to inbox"
                      >
                        <ArrowLeftIcon className="w-5 h-5" />
                      </button>
                      {renderNotificationIcon(selectedMail)}
                      <div className="min-w-0">
                        <h3 className={`text-lg md:text-xl font-bold leading-tight truncate ${isDark ? 'text-[#e4e6eb]' : 'text-gray-900'}`}>
                          {selectedMail.subject}
                        </h3>
                        <p className={`text-xs mt-0.5 ${isDark ? 'text-[#b0b3b8]' : 'text-gray-500'}`}>
                          {(selectedMail._raw?.document_type || selectedMail.category || 'Transcript of records')} · requested {formatDateFull(selectedMail.time)}
                        </p>
                      </div>
                    </div>

                    <button
                      type="button"
                      className={`p-1.5 rounded-lg transition-colors cursor-pointer shrink-0 ${isDark ? 'hover:bg-[#3a3b3c] text-[#b0b3b8]' : 'hover:bg-gray-100 text-gray-600'}`}
                      title="Options"
                    >
                      <EllipsisHorizontalIcon className="w-5 h-5" />
                    </button>
                  </header>

                  <div className={`flex-1 overflow-y-auto p-4 md:p-6 space-y-4 ${isDark ? 'bg-[#1a1b1e]' : 'bg-gray-50'}`}>
                    <div className="space-y-4">

                      {/* ── Message Description / Body ── */}
                      {(selectedMail.preview || selectedMail._raw?.message) && !lifecycleAlert && !isPendingSignature && (
                        <div className={`rounded-xl border p-4 sm:p-5 ${isDark ? 'border-[#3e4042] bg-[#242526]' : 'border-gray-200 bg-white shadow-xs'}`}>
                          <p className={`text-sm sm:text-base leading-relaxed ${isDark ? 'text-[#e4e6eb]' : 'text-gray-800'}`}>
                            {selectedMail._raw?.message || selectedMail.preview}
                          </p>
                        </div>
                      )}

                      {lifecycleAlert && (
                        <div className={`rounded-xl border p-4 sm:p-4.5 flex items-start gap-3.5 ${lifecycleAlert.classes}`}>
                          <InformationCircleIcon className={`w-5 h-5 shrink-0 mt-0.5 ${lifecycleAlert.iconClasses}`} />
                          <p className="text-xs sm:text-sm leading-relaxed">
                            <strong className={`font-bold mr-1 ${lifecycleAlert.titleClasses}`}>
                              {lifecycleAlert.title}
                            </strong>
                            {lifecycleAlert.message}
                          </p>
                        </div>
                      )}

                      {/* ── Requirements Checklist Card ── */}
                      <RequirementsListCard
                        requirementsList={requirementsList}
                        isChecklistOpen={isChecklistOpen}
                        onToggle={() => setIsChecklistOpen((prev) => !prev)}
                        isDark={isDark}
                      />


                      {/* ── Pending Signature Notice Box ── */}
                      {isPendingSignature && (
                        <div className={`rounded-xl border p-4 sm:p-4.5 flex items-start gap-3.5 ${isDark
                          ? 'bg-[#332200]/80 border-amber-600/40 text-[#f59e0b]'
                          : 'bg-amber-50 border-amber-200 text-amber-900'
                          }`}>
                          <InformationCircleIcon className="w-5 h-5 shrink-0 mt-0.5 text-amber-500" />
                          <p className="text-xs sm:text-sm leading-relaxed">
                            <strong className="font-bold text-amber-500 mr-1">Pending signature.</strong>
                            Your document is awaiting signature from the authorized signatory before it can be released to you.
                          </p>
                        </div>
                      )}

                      {/* ── Claim ticket ──
                        QR Code Claiming Policy v1.0 §3.2 access point 3
                        (inbox). uuid/claim_code are populated on both
                        the request_submitted notification (so the
                        ticket is available from day one, same as the
                        submit pop-up) and again on ready_to_claim (see
                        DocumentRequestService — submitRequest() and
                        notifyOwnerOfStatusChange()), so this renders on
                        whichever of those two messages the student
                        opens. Gating on field presence here — rather
                        than on selectedMail's type string — keeps this
                        in sync with the backend automatically if that
                        trigger list ever changes. ClaimTicket itself
                        also no-ops on missing props, so this is a
                        belt-and-suspenders check, not the only guard. */}
                      {selectedMail._raw?.uuid && selectedMail._raw?.claim_code && (
                        <div className={`rounded-xl border p-3.5 sm:p-5 ${isDark ? 'border-[#3e4042] bg-[#242526]' : 'border-gray-200 bg-white'}`}>
                          <p className={`text-[11px] font-bold uppercase tracking-widest mb-3 text-center sm:text-left ${isDark ? 'text-pup-yellow' : 'text-[#800000]'}`}>
                            Your Claim Ticket
                          </p>
                          <div className="flex justify-center w-full">
                            <ClaimTicket
                              uuid={selectedMail._raw.uuid}
                              claimCode={selectedMail._raw.claim_code}
                            />
                          </div>
                        </div>
                      )}

                      {/* ── Get Directions Button (Only for claiming document) ── */}
                      {isClaiming && (
                        <button
                          type="button"
                          onClick={() => {
                            window.open('https://maps.app.goo.gl/9NsWtiRdQ2UQeu188', '_blank');
                          }}
                          className="w-full bg-[#800000] hover:bg-[#6D0000] text-white font-bold py-2.5 px-4 rounded-xl transition-all shadow-sm flex items-center justify-center gap-2 text-sm cursor-pointer mt-2"
                        >
                          <MapPinIcon className="w-4 h-4" />
                          Get directions to registrar
                        </button>
                      )}

                      {/* ── Share your feedback box (Only for request_completed) ── */}
                      {isCompleted && (
                        <div className={`rounded-xl border p-3.5 sm:p-5 ${isDark ? 'border-[#3e4042] bg-[#242526]' : 'border-gray-200 bg-white shadow-sm'
                          }`}>
                          <h4 className={`text-base font-bold ${isDark ? 'text-[#e4e6eb]' : 'text-gray-900'}`}>
                            Share your feedback
                          </h4>
                          <p className={`text-xs mt-0.5 mb-4 ${isDark ? 'text-[#b0b3b8]' : 'text-gray-500'}`}>
                            Your experience helps improve the registrar's service.
                          </p>

                          {/* Inner QR Code Box */}
                          <div className={`rounded-xl border p-2 flex flex-col items-center justify-center text-center ${isDark ? 'border-[#3e4042]/70 bg-[#1a1b1e]' : 'border-gray-200 bg-gray-50'
                            }`}>
                            <img
                              src={qrCode}
                              alt="PUP SINTA"
                              className="w-28 h-28 sm:w-32 sm:h-32 object-contain bg-white p-2 rounded-2xl shadow-sm"
                            />
                            <p className={`text-xs mt-3 font-medium ${isDark ? 'text-[#b0b3b8]' : 'text-gray-600'}`}>
                              PUP SINTA
                            </p>
                          </div>

                          {/* Open Feedback Form Button */}
                          <button
                            type="button"
                            onClick={() => {
                              window.open('https://pupsinta.freshservice.com/support/home', '_blank');
                            }}
                            className="w-full bg-[#800000] hover:bg-[#6D0000] text-white font-bold py-3 px-4 rounded-xl transition-all shadow-sm flex items-center justify-center gap-2 text-sm cursor-pointer mt-4"
                          >
                            Open feedback form
                            <ArrowTopRightOnSquareIcon className="w-4 h-4" />
                          </button>
                        </div>
                      )}

                    </div>
                  </div>
                </>
              ) : (
                <div className="h-full flex flex-col items-center justify-center p-8 relative">
                  <button
                    type="button"
                    onClick={() => setMobileView('list')}
                    className={`lg:hidden absolute top-4 left-4 p-1.5 rounded-lg transition-colors cursor-pointer ${isDark ? 'hover:bg-[#3a3b3c] text-[#e4e6eb]' : 'hover:bg-gray-100 text-gray-700'
                      }`}
                    title="Back to inbox"
                  >
                    <ArrowLeftIcon className="w-5 h-5" />
                  </button>
                  <div className={`w-24 h-24 mb-6 flex items-center justify-center rounded-full transition-colors ${isDark ? 'bg-[#3a3b3c]/40' : 'bg-gray-100'}`}>
                    <EnvelopeOpenIcon className={`w-12 h-12 ${isDark ? 'text-[#b0b3b8]' : 'text-gray-400'}`} />
                  </div>

                  <h3 className={`text-lg font-bold mb-2 tracking-tight ${isDark ? 'text-[#e4e6eb]' : 'text-gray-800'}`}>
                    No Message Selected
                  </h3>

                  <p className={`text-sm text-center max-w-xs leading-relaxed ${isDark ? 'text-[#b0b3b8]' : 'text-gray-500'}`}>
                    Select a conversation from the sidebar to read its contents and view your requirements checklist.
                  </p>
                </div>
              )}
            </section>

          </div>
        </div>
      </div>
    </>
  );
};

export default InboxCenter;