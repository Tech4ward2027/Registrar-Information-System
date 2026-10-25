import React, { useState, useMemo, useRef, useEffect } from 'react';
import { useLocation, useNavigate } from 'react-router-dom';
import { CheckCircleIcon } from '@heroicons/react/24/solid';
import { useNotificationsContext as useNotifications } from '../context/NotificationsContext';
import { useTheme } from '../context/ThemeContext';
import { useHeaderResponsiveState } from '../utils/helpers';
import { usePushNotifications } from '../hooks/usePushNotifications';
import { PushOptInCard, PushStatusRow } from './PushOptInCard';

// CATEGORY_MAP lives in src/constants/notificationCategories.js
// — edit it there; changes apply to both NotificationModal and NotificationToast.
import { CATEGORY_MAP } from '../constants/notificationCategories';

const formatTime = (isoString) => {
  if (!isoString) return '';
  const diff = Math.floor((Date.now() - new Date(isoString)) / 1000);
  if (diff < 60)   return `${diff}s ago`;
  if (diff < 3600) return `${Math.floor(diff / 60)}m ago`;
  if (diff < 86400) return `${Math.floor(diff / 3600)}h ago`;
  return `${Math.floor(diff / 86400)}d ago`;
};

// -------------------------------------------------------
// NotificationItem
// -------------------------------------------------------
const NotificationItem = ({ notif, onClick }) => {
  const meta = CATEGORY_MAP[notif.type] ?? { category: 'System', color: 'bg-blue-400' };
  const isUnread = !notif.read_at;
  const { isDark } = useTheme();

  const rowClasses = isDark
    ? 'hover:bg-white/3 border-white/3'
    : 'hover:bg-white/3 border-white/3';

  const categoryClasses = isDark
    ? (isUnread ? 'text-pup-yellow' : 'text-white/20')
    : (isUnread ? 'text-pup-yellow' : 'text-white/20');

  const timeClasses = isDark
    ? 'text-white/20 group-hover:text-white/40'
    : 'text-white/20 group-hover:text-white/40';

  const titleClasses = isDark
    ? (isUnread ? 'text-white font-bold' : 'text-white/30 font-medium')
    : (isUnread ? 'text-white font-bold' : 'text-white/30 font-medium');

  const messageClasses = isDark
    ? (isUnread ? 'text-white/80' : 'text-white/20')
    : (isUnread ? 'text-white/80' : 'text-white/20');

  return (
    <div
      onClick={onClick}
      className={`flex items-start gap-3 px-4 py-3 cursor-pointer transition-all border-b group relative sm:px-5 sm:py-4 ${rowClasses}`}
    >
      {/* Status Dot */}
      <div className="mt-1.5 shrink-0">
        <div className={`w-2.5 h-2.5 rounded-full transition-all duration-300 ring-4 ring-black/20 sm:w-3 sm:h-3 ${
          isUnread ? meta.color : 'bg-white/10'
        }`} />
      </div>

      {/* Content */}
      <div className="flex-1 min-w-0">
        <div className="flex justify-between items-start mb-1">
          <span className={`text-[9px] font-black uppercase tracking-[0.15em] transition-colors ${categoryClasses}`}>
            {meta.category}
          </span>
          <span className={`text-[10px] font-bold ml-2 shrink-0 transition-colors ${timeClasses}`}>
            {formatTime(notif.created_at)}
          </span>
        </div>

        <h3 className={`text-[12px] leading-snug mb-0.5 transition-colors sm:text-[13px] ${titleClasses}`}>
          {notif.title}
        </h3>
        <p className={`text-[11px] leading-normal line-clamp-2 transition-colors sm:text-[12px] ${messageClasses}`}>
          {notif.message}
        </p>
      </div>

      {/* Hover Accent Sidebar */}
      <div className="absolute left-0 top-0 bottom-0 w-1 bg-pup-yellow scale-y-0 group-hover:scale-y-100 transition-transform duration-300 origin-center" />
    </div>
  );
};

// -------------------------------------------------------
// NotificationModal
// -------------------------------------------------------
const NotificationModal = ({ isOpen, onClose }) => {
  const navigate  = useNavigate();
  const location  = useLocation();
  const [activeTab, setActiveTab] = useState('all');
  const { isDark } = useTheme();
  const { headerHeight, isMobile } = useHeaderResponsiveState(isOpen);
  // Background (Web Push) opt-in. Deferred until the panel is first opened.
  const push = usePushNotifications({ enabled: isOpen });

  const {
    notifications,
    unreadCount,
    loading,
    loadMore,
    loadingMore,
    hasMore,
    markAsRead,
    markAllAsRead,
  } = useNotifications();

  const filteredNotifs = useMemo(() => {
    if (activeTab === 'unread') return notifications.filter(n => !n.read_at);
    return notifications;
  }, [activeTab, notifications]);

  // ── Infinite-scroll setup ──────────────────────────────────────────────
  // listRef    → the scrollable notification list container
  // sentinelRef → invisible div at the very bottom of the list
  //
  // IntersectionObserver fires loadMore() whenever the sentinel enters the
  // visible area of listRef, letting users scroll through all their
  // notifications without a separate 'View all' / page click.
  //
  // IMPORTANT: these hooks must be declared BEFORE any conditional return.
  // React requires hooks to be called in the same order on every render —
  // placing useRef/useEffect after "if (!isOpen) return null" caused a
  // "hooks called in different order" error because those hooks were skipped
  // on renders where isOpen was false.
  // ──────────────────────────────────────────────────────────────────────
  const listRef     = useRef(null);
  const sentinelRef = useRef(null);

  useEffect(() => {
    if (!isOpen) return; // don't observe while the modal is closed
    const sentinel = sentinelRef.current;
    const root     = listRef.current;
    if (!sentinel || !root) return;

    const observer = new IntersectionObserver(
      ([entry]) => {
        if (entry.isIntersecting && hasMore && !loadingMore) {
          loadMore();
        }
      },
      { root, threshold: 0, rootMargin: '0px 0px 80px 0px' },
    );

    observer.observe(sentinel);
    return () => observer.disconnect();
  }, [isOpen, hasMore, loadingMore, loadMore]);

  if (!isOpen) return null;

  const handleNotifClick = async (notif) => {
    if (!notif.read_at) await markAsRead(notif.id);

    const roleRoot = location.pathname.split('/')[1];
    const validRoleRoots = ['student', 'alumni', 'staff', 'super-admin'];
    const targetRoleRoot = validRoleRoots.includes(roleRoot) ? roleRoot : 'student';

    navigate(`/${targetRoleRoot}/inbox`, {
      state: { selectedNotificationId: notif.id, notification: notif },
    });
    onClose();
  };

  const handleMarkAllAsRead = async () => {
    await markAllAsRead();
  };

  return (
    <>
      <div className="fixed inset-0 z-40 bg-transparent" onClick={onClose} />

      <div
        style={{
          top: `${headerHeight + 12}px`,
        }}
        className={`
          fixed left-1/2 -translate-x-1/2 md:left-auto md:right-5 md:translate-x-0
          w-[calc(100vw-24px)] md:w-95
          rounded-[1.25rem] sm:rounded-3xl overflow-hidden
          border z-50
          animate-in fade-in slide-in-from-top-2 md:slide-in-from-right-4 duration-200
          ${isDark
            ? 'bg-[#242526] border-[#3e4042] shadow-[0_25px_50px_-12px_rgba(0,0,0,0.7)] ring-1 ring-white/5'
            : 'bg-pup-dark-maroon border-white/10 shadow-[0_25px_50px_-12px_rgba(0,0,0,0.7)] ring-1 ring-white/5'}
        `}
      >
        {/* Header */}
        <div className={`px-4 py-4 border-b sm:p-5 ${isDark ? 'bg-[#1a1b1e] border-[#3e4042]' : 'bg-[#510400] border-white/5'}`}>
          <div className="flex justify-between items-center mb-3 sm:mb-4">
            <h2 className={`text-base font-black tracking-tight flex items-center gap-2 sm:text-xl ${isDark ? 'text-[#e4e6eb]' : 'text-white'}`}>
              Notifications
              {unreadCount > 0 && (
                <span className="bg-pup-yellow text-pup-maroon text-[10px] px-2 py-0.5 rounded-full font-bold">
                  {unreadCount} New
                </span>
              )}
            </h2>
            <button onClick={handleMarkAllAsRead} title="Mark all as read" className="hover:scale-110 transition-transform">
              <CheckCircleIcon className={`w-4 h-4 transition-colors sm:w-5 sm:h-5 ${isDark ? 'text-[#e4e6eb] hover:text-pup-yellow' : 'text-white hover:text-pup-yellow'}`} />
            </button>
          </div>

          <div className="flex gap-2">
            <TabButton label="All"    active={activeTab === 'all'}    onClick={() => setActiveTab('all')} />
            <TabButton label="Unread" active={activeTab === 'unread'} onClick={() => setActiveTab('unread')} />
          </div>
        </div>

        {/* Background-notification opt-in (renders nothing unless relevant) */}
        <PushOptInCard push={push} />

        {/* List — scrollRef/sentinelRef power the IntersectionObserver
             that triggers loadMore() as the user reaches the bottom. */}
        <div
          ref={listRef}
          style={{
            maxHeight: isMobile ? `calc(100vh - ${headerHeight}px - 140px)` : undefined
          }}
          className={`max-h-70 overflow-y-auto custom-scrollbar sm:max-h-105 ${isDark ? 'bg-[#242526]' : 'bg-pup-dark-maroon'}`}
        >
          {loading ? (
            <LoadingState />
          ) : filteredNotifs.length > 0 ? (
            filteredNotifs.map(notif => (
              <NotificationItem
                key={notif.id}
                notif={notif}
                onClick={() => handleNotifClick(notif)}
              />
            ))
          ) : (
            <EmptyState />
          )}

          {/* Sentinel observed by the IntersectionObserver below */}
          <div ref={sentinelRef} aria-hidden="true" />

          {loadingMore && (
            <div className={`py-3 text-center text-[10px] font-bold uppercase tracking-widest animate-pulse
              ${isDark ? 'text-[#b0b3b8]' : 'text-white/40'}`}>
              Loading more…
            </div>
          )}
        </div>

        {/* Footer */}
        <div className={`px-4 py-3 border-t flex justify-center sm:p-4 ${isDark ? 'bg-[#1a1b1e] border-[#3e4042]' : 'bg-[#510400] border-white/5'}`}>
          <PushStatusRow push={push} />
        </div>
      </div>
    </>
  );
};

/* =========================================
   UI HELPERS
   ========================================= */
const TabButton = ({ label, active = false, onClick }) => {
  const { isDark } = useTheme();

  return (
    <button
      onClick={onClick}
      className={`px-3 py-1 text-[10px] font-black rounded-xl uppercase tracking-wider transition-all sm:px-4 sm:py-1.5 sm:text-[11px] ${
        active
          ? 'bg-pup-yellow text-pup-maroon shadow-lg shadow-pup-yellow/10'
          : (isDark
            ? 'bg-white/5 text-white/60 hover:bg-white/10 hover:text-white'
            : 'bg-white/5 text-white/60 hover:bg-white/10 hover:text-white')
      }`}
    >
      {label}
    </button>
  );
};

const EmptyState = () => {
  const { isDark } = useTheme();

  return (
    <div className="p-8 text-center sm:p-10">
      <p className={`text-xs font-bold uppercase tracking-widest ${isDark ? 'text-[#b0b3b8]' : 'text-gray-400'}`}>No notifications</p>
    </div>
  );
};

const LoadingState = () => {
  const { isDark } = useTheme();

  return (
    <div className="p-8 text-center sm:p-10">
      <p className={`text-xs font-bold uppercase tracking-widest animate-pulse ${isDark ? 'text-[#b0b3b8]' : 'text-gray-400'}`}>Loading...</p>
    </div>
  );
};

export default NotificationModal;