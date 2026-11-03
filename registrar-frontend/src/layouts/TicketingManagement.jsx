import React, { useState, useMemo, useRef, useEffect, useCallback } from 'react';
import { useParams, useSearchParams, useNavigate, useLocation } from 'react-router-dom';
import {
  LifebuoyIcon,
  ArchiveBoxIcon,
  QueueListIcon,
  EyeIcon,
  ArrowPathIcon,
  ExclamationTriangleIcon,
} from '@heroicons/react/24/outline';
import {
  getTicketQueue,
  getTicketCategories,
  claimTicket,
} from '../services/api';
import { useTheme } from '../context/ThemeContext';
import { useAuth } from '../context/AuthProvider';
import { useToast } from '../context/NotificationToastContext';
import { MODULE_KEYS, hasModuleAction } from '../utils/policy';
import {
  TICKET_STATUS_META,
  formatPriorityLabel,
  formatSlaStateLabel,
  getSlaBadgeClass,
  parseTicketApiError,
} from '../utils/ticketHelpers';
import { useTicketLiveUpdates, useRetryAfterCooldown } from '../hooks/useTicketLiveUpdates';
import VoiceSearchInput from '../components/VoiceSearchInput.jsx';
import DashboardDropdown from '../components/DashboardDropdown.jsx';
import { StatCard, Th, Td, Pagination } from '../components/StaffDashboardComponents';
import TicketDetailModal from '../components/tickets/TicketDetailModal';
import TicketCategoriesAdminPanel from '../components/tickets/TicketCategoriesAdminPanel.jsx';
import TicketBlocksAdminPanel from '../components/tickets/TicketBlocksAdminPanel.jsx';
import TicketReportsAdminPanel from '../components/tickets/TicketReportsAdminPanel.jsx';

const ITEMS_PER_PAGE = 15;

const MANAGEMENT_TABS = [
  { key: 'queue', label: 'Ticket Queue' },
  { key: 'categories', label: 'Categories', manageOnly: true },
  { key: 'blocks', label: 'Spam & Blocklist', manageOnly: true },
  { key: 'reports', label: 'Reports & Export', manageOnly: true },
];

const PRIORITY_OPTIONS = [
  { value: 'All', label: 'All' },
  { value: 'high', label: 'High' },
  { value: 'medium', label: 'Medium' },
  { value: 'low', label: 'Low' },
];

const SCOPE_OPTIONS = [
  { value: 'all', label: 'All Queue' },
  { value: 'unclaimed', label: 'Unclaimed' },
  { value: 'mine', label: 'Claimed by Me' },
];

const SLA_FILTER_OPTIONS = [
  { value: '', label: 'All SLA' },
  { value: 'at_risk', label: 'At Risk' },
  { value: 'breached', label: 'Breached' },
];

const TicketingManagement = () => {
  const { isDark } = useTheme();
  const { user } = useAuth();
  const { addToast } = useToast();
  const { ticketId: routeTicketId } = useParams();
  const [searchParams, setSearchParams] = useSearchParams();
  const navigate = useNavigate();
  const location = useLocation();
  const cooldown = useRetryAfterCooldown();

  const canManage = hasModuleAction(user, MODULE_KEYS.TICKETS, 'Manage');
  const canClaim = hasModuleAction(user, MODULE_KEYS.TICKETS, 'Claim');

  const visibleTabs = useMemo(
    () => MANAGEMENT_TABS.filter((t) => !t.manageOnly || canManage),
    [canManage]
  );

  const rawTab = searchParams.get('tab') || 'queue';
  const activeManagementTab = visibleTabs.some((t) => t.key === rawTab) ? rawTab : 'queue';

  // Queue state
  const [tickets, setTickets] = useState([]);
  const [nextCursor, setNextCursor] = useState(null);
  const [loading, setLoading] = useState(true);
  const [loadingMore, setLoadingMore] = useState(false);
  const [errorMsg, setErrorMsg] = useState('');
  const [claimingId, setClaimingId] = useState(null);
  const [categories, setCategories] = useState([]);

  // Mode: 'active' (open, in_progress, pending_client) vs 'archived' (resolved, closed)
  const [viewMode, setViewMode] = useState('active');
  const [scope, setScope] = useState('all');
  const [slaFilter, setSlaFilter] = useState('');
  const [searchTerm, setSearchTerm] = useState('');
  const [debouncedQuery, setDebouncedQuery] = useState('');
  const [filterCategory, setFilterCategory] = useState('All');
  const [filterPriority, setFilterPriority] = useState('All');
  const [filterStatus, setFilterStatus] = useState('All');
  const [currentPage, setCurrentPage] = useState(1);
  const [selectedTicket, setSelectedTicket] = useState(null);

  // Dropdown refs & states for table header filters
  const [statusDropdownOpen, setStatusDropdownOpen] = useState(false);
  const [categoryDropdownOpen, setCategoryDropdownOpen] = useState(false);
  const [priorityDropdownOpen, setPriorityDropdownOpen] = useState(false);
  const statusDropdownRef = useRef(null);
  const categoryDropdownRef = useRef(null);
  const priorityDropdownRef = useRef(null);

  const baseTicketsPath = useMemo(() => {
    const seg = location.pathname.split('/')[1] || 'staff';
    return `/${seg}/tickets`;
  }, [location.pathname]);

  // Debounce search query for backend `q` param
  useEffect(() => {
    const timer = setTimeout(() => {
      setDebouncedQuery(searchTerm.trim());
    }, 300);
    return () => clearTimeout(timer);
  }, [searchTerm]);

  // Load categories for column dropdown
  useEffect(() => {
    let active = true;
    getTicketCategories()
      .then((res) => {
        if (!active) return;
        setCategories(Array.isArray(res.data?.data) ? res.data.data : []);
      })
      .catch(() => {});
    return () => {
      active = false;
    };
  }, []);

  const fetchQueue = useCallback(
    async ({ cursor = null, append = false, silent = false } = {}) => {
      if (activeManagementTab !== 'queue') return;
      if (append) {
        setLoadingMore(true);
      } else if (!silent) {
        setLoading(true);
      }
      setErrorMsg('');

      try {
        // When viewing 'archived' with 'All', fetch both 'resolved' and 'closed' if no single status is selected,
        // or pass the specific status directly to GET /admin/tickets.
        if (viewMode === 'archived' && filterStatus === 'All' && !cursor) {
          const baseParams = { scope, per_page: 50 };
          if (filterPriority !== 'All') baseParams.priority = filterPriority;
          if (filterCategory !== 'All') baseParams.category = filterCategory;
          if (slaFilter) baseParams.sla = slaFilter;
          if (debouncedQuery) baseParams.q = debouncedQuery;

          const [resolvedRes, closedRes] = await Promise.all([
            getTicketQueue({ ...baseParams, status: 'resolved' }),
            getTicketQueue({ ...baseParams, status: 'closed' }),
          ]);
          const resolvedRows = Array.isArray(resolvedRes.data?.data) ? resolvedRes.data.data : [];
          const closedRows = Array.isArray(closedRes.data?.data) ? closedRes.data.data : [];
          const combined = [...resolvedRows, ...closedRows].sort(
            (a, b) =>
              new Date(b.last_activity_at || b.created_at) -
              new Date(a.last_activity_at || a.created_at)
          );
          setTickets(combined);
          setNextCursor(null);
        } else {
          const params = { scope, per_page: 50 };
          if (filterStatus !== 'All') {
            params.status = filterStatus;
          }
          if (filterPriority !== 'All') params.priority = filterPriority;
          if (filterCategory !== 'All') params.category = filterCategory;
          if (slaFilter) params.sla = slaFilter;
          if (debouncedQuery) params.q = debouncedQuery;
          if (cursor) params.cursor = cursor;

          const res = await getTicketQueue(params);
          const rows = Array.isArray(res.data?.data) ? res.data.data : [];
          const next = res.data?.meta?.next_cursor ?? null;

          setTickets((prev) => {
            if (!append) return rows;
            const seen = new Set(prev.map((t) => t.id));
            return [...prev, ...rows.filter((t) => !seen.has(t.id))];
          });
          setNextCursor(next);
        }
      } catch (err) {
        const parsed = await parseTicketApiError(err, 'Unable to load ticket queue.');
        if (parsed.status === 429) cooldown.startCooldown(parsed.retryAfterSeconds);
        setErrorMsg(parsed.message);
      } finally {
        if (!silent) setLoading(false);
        setLoadingMore(false);
      }
    },
    [
      activeManagementTab,
      viewMode,
      scope,
      filterStatus,
      filterPriority,
      filterCategory,
      slaFilter,
      debouncedQuery,
      cooldown.startCooldown,
    ]
  );

  useEffect(() => {
    fetchQueue();
  }, [fetchQueue]);

  useTicketLiveUpdates({
    staffQueue: true,
    enabled: activeManagementTab === 'queue',
    onTicketUpdated: () => {
      fetchQueue({ silent: true });
    },
  });

  const handleTicketUpdated = useCallback(
    (updated) => {
      if (!updated?.id) return;
      setTickets((prev) => {
        const exists = prev.some((t) => t.id === updated.id);
        if (!exists) return prev;
        return prev.map((t) => (t.id === updated.id ? { ...t, ...updated } : t));
      });
      setSelectedTicket((prev) => (prev?.id === updated.id ? { ...prev, ...updated } : prev));
    },
    []
  );

  const handleQuickClaim = async (e, ticket) => {
    e.stopPropagation();
    if (!ticket?.id || claimingId || cooldown.isCoolingDown) return;
    setClaimingId(ticket.id);
    setErrorMsg('');
    try {
      const res = await claimTicket(ticket.id);
      const updated = res.data?.data;
      if (updated) handleTicketUpdated(updated);
      addToast?.({
        type: 'success',
        title: 'Ticket Claimed',
        message: `You claimed ${ticket.ticket_number}.`,
      });
      fetchQueue({ silent: true });
    } catch (err) {
      const parsed = await parseTicketApiError(err, 'Unable to claim ticket.');
      if (parsed.status === 429) cooldown.startCooldown(parsed.retryAfterSeconds);
      setErrorMsg(parsed.message);
      if (parsed.status === 409) fetchQueue({ silent: true });
    } finally {
      setClaimingId(null);
    }
  };

  // Counts by status for top StatCards
  const statusCounts = useMemo(() => {
    return {
      open: tickets.filter((t) => t.status === 'open').length,
      in_progress: tickets.filter((t) => t.status === 'in_progress').length,
      pending_client: tickets.filter((t) => t.status === 'pending_client').length,
      resolved: tickets.filter((t) => t.status === 'resolved').length,
      closed: tickets.filter((t) => t.status === 'closed').length,
    };
  }, [tickets]);

  const categoryOptions = useMemo(() => {
    return [
      { value: 'All', label: 'All' },
      ...categories.map((c) => ({ value: c.code, label: `${c.code} — ${c.name}` })),
    ];
  }, [categories]);

  const statusOptions = useMemo(() => {
    if (viewMode === 'archived') {
      return [
        { value: 'All', label: 'All' },
        { value: 'resolved', label: 'Resolved' },
        { value: 'closed', label: 'Closed' },
      ];
    }
    return [
      { value: 'All', label: 'All' },
      { value: 'open', label: 'Open' },
      { value: 'in_progress', label: 'In Progress' },
      { value: 'pending_client', label: 'Pending Client' },
    ];
  }, [viewMode]);

  // Client-side search refinement for requester name / instant responsiveness
  const filteredTickets = useMemo(() => {
    return tickets.filter((t) => {
      const isArchivedStatus = t.status === 'resolved' || t.status === 'closed';
      if (viewMode === 'active' && isArchivedStatus) return false;
      if (viewMode === 'archived' && !isArchivedStatus) return false;

      if (filterStatus !== 'All' && t.status !== filterStatus) return false;
      if (filterCategory !== 'All' && t.category?.code !== filterCategory) return false;
      if (filterPriority !== 'All' && t.priority !== filterPriority) return false;

      if (searchTerm.trim()) {
        const term = searchTerm.toLowerCase();
        const matchNum = t.ticket_number?.toLowerCase().includes(term);
        const matchSub = t.subject?.toLowerCase().includes(term);
        const matchName = t.requester?.name?.toLowerCase().includes(term);
        const matchCat = t.category?.name?.toLowerCase().includes(term);
        if (!matchNum && !matchSub && !matchName && !matchCat) return false;
      }

      return true;
    });
  }, [tickets, viewMode, filterStatus, filterCategory, filterPriority, searchTerm]);

  const totalPages = Math.ceil(filteredTickets.length / ITEMS_PER_PAGE) || 1;
  const safePage = Math.min(currentPage, totalPages);
  const indexOfFirstItem = (safePage - 1) * ITEMS_PER_PAGE;
  const indexOfLastItem = indexOfFirstItem + ITEMS_PER_PAGE;
  const currentItems = useMemo(
    () => filteredTickets.slice(indexOfFirstItem, indexOfLastItem),
    [filteredTickets, indexOfFirstItem, indexOfLastItem]
  );

  const handleNextPage = () => safePage < totalPages && setCurrentPage((prev) => prev + 1);
  const handlePrevPage = () => safePage > 1 && setCurrentPage((prev) => prev - 1);

  const clearAllFilters = () => {
    setScope('all');
    setSlaFilter('');
    setFilterStatus('All');
    setFilterCategory('All');
    setFilterPriority('All');
    setSearchTerm('');
    setCurrentPage(1);
    setStatusDropdownOpen(false);
    setCategoryDropdownOpen(false);
    setPriorityDropdownOpen(false);
  };

  const hasActiveFilters =
    scope !== 'all' ||
    slaFilter !== '' ||
    filterStatus !== 'All' ||
    filterCategory !== 'All' ||
    filterPriority !== 'All' ||
    searchTerm.trim() !== '';

  const renderStatusBadge = (status) => {
    const label = TICKET_STATUS_META[status]?.label || status;
    const styles = isDark
      ? {
          open: 'bg-yellow-900/20 text-yellow-400 border-yellow-600',
          in_progress: 'bg-blue-900/20 text-blue-400 border-blue-600',
          pending_client: 'bg-amber-900/20 text-amber-400 border-amber-600',
          resolved: 'bg-green-900/20 text-green-400 border-green-600',
          closed: 'bg-gray-700/20 text-gray-300 border-gray-400',
        }
      : {
          open: 'bg-yellow-100 text-yellow-700 border-yellow-200',
          in_progress: 'bg-blue-100 text-blue-700 border-blue-200',
          pending_client: 'bg-amber-100 text-amber-700 border-amber-200',
          resolved: 'bg-green-100 text-green-700 border-green-200',
          closed: 'bg-gray-100 text-gray-700 border-gray-200',
        };

    return (
      <span
        className={`inline-block px-2.5 py-0.5 rounded-full text-[11px] font-bold border whitespace-nowrap ${
          styles[status] ||
          (isDark ? 'bg-gray-700/20 text-gray-300 border-gray-400' : 'bg-gray-100 text-gray-600')
        }`}
      >
        {label}
      </span>
    );
  };

  const renderPriorityBadge = (priority) => {
    const map = isDark
      ? {
          high: 'bg-red-900/20 text-red-300 border-red-500',
          medium: 'bg-yellow-900/20 text-yellow-400 border-yellow-600',
          low: 'bg-gray-700/20 text-gray-300 border-gray-400',
        }
      : {
          high: 'bg-red-100 text-red-700 border-red-200',
          medium: 'bg-yellow-100 text-yellow-700 border-yellow-200',
          low: 'bg-gray-100 text-gray-700 border-gray-200',
        };

    return (
      <span
        className={`inline-block px-2.5 py-0.5 rounded-full text-[11px] font-bold border whitespace-nowrap ${
          map[priority] ||
          (isDark ? 'bg-blue-900/20 text-blue-400 border-blue-600' : 'bg-blue-100 text-blue-700 border-blue-200')
        }`}
      >
        {formatPriorityLabel(priority)}
      </span>
    );
  };

  const renderSlaPill = (ticket) => {
    const sla = ticket?.sla;
    if (!sla) return null;
    if (sla.paused) {
      return (
        <span
          className={`inline-block px-2 py-0.5 rounded-full text-[10px] font-bold border whitespace-nowrap ${getSlaBadgeClass(
            'paused',
            isDark
          )}`}
        >
          SLA Paused
        </span>
      );
    }
    const target = ticket.awaiting_first_response ? sla.first_response : sla.resolution;
    const state = target?.state;
    if (!state || state === 'none') return null;
    return (
      <span
        className={`inline-block px-2 py-0.5 rounded-full text-[10px] font-bold border whitespace-nowrap ${getSlaBadgeClass(
          state,
          isDark
        )}`}
      >
        {ticket.awaiting_first_response ? '1st Resp: ' : 'Res: '}
        {formatSlaStateLabel(state)}
      </span>
    );
  };

  const formatDateTime = (dateStr) => {
    if (!dateStr) return '—';
    const d = new Date(dateStr);
    if (Number.isNaN(d.getTime())) return '—';
    return d.toLocaleDateString('en-US', {
      timeZone: 'Asia/Manila',
      month: 'short',
      day: 'numeric',
      year: 'numeric',
    });
  };

  const closeTicketModal = () => {
    setSelectedTicket(null);
    if (routeTicketId) {
      navigate(baseTicketsPath, { replace: true });
    }
  };

  const modalOpen = Boolean(selectedTicket || routeTicketId);

  return (
    <div className="max-w-7xl mx-auto px-3 sm:px-5 mb-6">
      {/* Top Floating Pill Sub-Navigation (when user has Manage permission) */}
      {visibleTabs.length > 1 && (
        <div className="flex justify-center mb-5 overflow-x-auto no-scrollbar">
          <div
            className={`inline-flex px-5 sm:px-8 py-3 rounded-full transition-all duration-300 ${
              isDark
                ? 'bg-[#242526] border border-[#3e4042] shadow-[0_2px_8px_rgba(0,0,0,0.2)]'
                : 'bg-white border border-gray-200/80 shadow-[0_2px_8px_rgba(0,0,0,0.05)]'
            } gap-5 sm:gap-8 items-center`}
          >
            {visibleTabs.map((tab) => (
              <button
                key={tab.key}
                type="button"
                onClick={() => setSearchParams(tab.key === 'queue' ? {} : { tab: tab.key })}
                className={`text-xs sm:text-sm relative rounded-full flex items-center justify-center shrink-0 font-semibold transition-all duration-200 hover:scale-105 active:scale-95 cursor-pointer whitespace-nowrap ${
                  activeManagementTab === tab.key
                    ? isDark
                      ? 'text-yellow-400 font-bold'
                      : 'text-pup-dark-maroon font-black'
                    : isDark
                      ? 'text-[#b0b3b8] hover:text-white'
                      : 'text-gray-500 hover:text-gray-900'
                }`}
              >
                {tab.label}
              </button>
            ))}
          </div>
        </div>
      )}

      {activeManagementTab !== 'queue' && canManage ? (
        <div
          className={`rounded-2xl p-4 sm:p-5 shadow-sm border ${
            isDark
              ? 'bg-[#242526] border-[#3e4042] text-[#e4e6eb]'
              : 'bg-white border-gray-200 text-gray-900'
          }`}
        >
          {activeManagementTab === 'categories' ? (
            <TicketCategoriesAdminPanel />
          ) : activeManagementTab === 'blocks' ? (
            <TicketBlocksAdminPanel />
          ) : (
            <TicketReportsAdminPanel />
          )}
        </div>
      ) : (
        /* Outer Card matching Staff Dashboard layout container */
        <div
          className={`rounded-2xl p-4 sm:p-5 shadow-sm border ${
            isDark
              ? 'bg-[#242526] border-[#3e4042] text-[#e4e6eb]'
              : 'bg-white border-gray-200 text-gray-900'
          }`}
        >
          {/* Tab Navigation (Active Inquiries vs Resolved / Archived) + Queue Scope */}
          <div
            className={`flex flex-wrap justify-between items-center gap-3 border-b mb-4 ${
              isDark ? 'border-[#3e4042]' : 'border-gray-200'
            }`}
          >
            <div className="flex">
              <button
                type="button"
                onClick={() => {
                  setViewMode('active');
                  setFilterStatus('All');
                  setCurrentPage(1);
                }}
                className={`px-4 py-2 font-semibold text-xs sm:text-sm transition-all relative border-b-2 -mb-0.5 focus:outline-none flex items-center gap-1.5 cursor-pointer ${
                  viewMode === 'active'
                    ? isDark
                      ? 'text-white border-white font-bold'
                      : 'text-gray-900 border-gray-900 font-bold'
                    : isDark
                      ? 'text-[#b0b3b8] border-transparent hover:text-white'
                      : 'text-gray-500 border-transparent hover:text-gray-900'
                }`}
              >
                <QueueListIcon className="w-4 h-4" />
                <span>Active Tickets</span>
              </button>

              <button
                type="button"
                onClick={() => {
                  setViewMode('archived');
                  setFilterStatus('All');
                  setCurrentPage(1);
                }}
                className={`px-4 py-2 font-semibold text-xs sm:text-sm transition-all relative border-b-2 -mb-0.5 focus:outline-none flex items-center gap-1.5 cursor-pointer ${
                  viewMode === 'archived'
                    ? isDark
                      ? 'text-white border-white font-bold'
                      : 'text-gray-900 border-gray-900 font-bold'
                    : isDark
                      ? 'text-[#b0b3b8] border-transparent hover:text-white'
                      : 'text-gray-500 border-transparent hover:text-gray-900'
                }`}
              >
                <ArchiveBoxIcon className="w-4 h-4" />
                <span>Resolved &amp; Closed</span>
              </button>
            </div>

            {/* Scope Selector + Refresh */}
            <div className="flex items-center gap-2 pb-2">
              <div
                className={`inline-flex rounded-full p-0.5 border text-xs ${
                  isDark ? 'bg-[#18191a] border-[#3e4042]' : 'bg-gray-100 border-gray-200'
                }`}
              >
                {SCOPE_OPTIONS.map((opt) => (
                  <button
                    key={opt.value}
                    type="button"
                    onClick={() => {
                      setScope(opt.value);
                      setCurrentPage(1);
                    }}
                    className={`px-3 py-1 rounded-full font-semibold transition cursor-pointer ${
                      scope === opt.value
                        ? isDark
                          ? 'bg-yellow-400 text-gray-900 font-bold'
                          : 'bg-pup-dark-maroon text-white font-bold'
                        : isDark
                          ? 'text-[#b0b3b8] hover:text-white'
                          : 'text-gray-600 hover:text-gray-900'
                    }`}
                  >
                    {opt.label}
                  </button>
                ))}
              </div>

              <button
                type="button"
                onClick={() => fetchQueue()}
                disabled={loading || cooldown.isCoolingDown}
                className={`p-2 rounded-full border transition cursor-pointer disabled:opacity-50 ${
                  isDark
                    ? 'border-[#3e4042] text-[#b0b3b8] hover:bg-[#3a3b3c]'
                    : 'border-gray-200 text-gray-600 hover:bg-gray-50'
                }`}
                title="Refresh queue"
              >
                <ArrowPathIcon className={`w-4 h-4 ${loading ? 'animate-spin' : ''}`} />
              </button>
            </div>
          </div>

          {errorMsg && (
            <div
              className={`mb-4 p-3.5 rounded-xl border flex items-start gap-2.5 text-xs ${
                isDark
                  ? 'bg-red-950/30 border-red-800/50 text-red-300'
                  : 'bg-red-50 border-red-200 text-red-700'
              }`}
            >
              <ExclamationTriangleIcon className="w-4 h-4 shrink-0 mt-0.5" />
              <span>{errorMsg}</span>
            </div>
          )}

          {/* ---------------- CARDS (Staff Dashboard StatCard style) ---------------- */}
          {viewMode === 'archived' ? (
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 sm:gap-4 mb-4">
              <StatCard title="Resolved Inquiries" count={statusCounts.resolved} color="green" />
              <StatCard title="Closed Inquiries" count={statusCounts.closed} color="blue" />
            </div>
          ) : (
            <div className="grid grid-cols-1 sm:grid-cols-3 gap-3 sm:gap-4 mb-4">
              <StatCard title="New Inquiries" count={statusCounts.open} color="yellow" />
              <StatCard title="In Progress" count={statusCounts.in_progress} color="blue" />
              <StatCard
                title="Awaiting Student Response"
                count={statusCounts.pending_client}
                color="amber"
              />
            </div>
          )}

          {/* ---------------- TOOLBAR (Staff Dashboard Toolbar style) ---------------- */}
          <div className="mb-4 flex flex-col md:flex-row gap-2.5 justify-between items-center w-full">
            <div className="w-full sm:max-w-sm">
              <VoiceSearchInput
                value={searchTerm}
                onChange={(val) => {
                  setSearchTerm(val);
                  setCurrentPage(1);
                }}
                placeholder="Search ticket #, subject, requestor..."
                language="en-US"
              />
            </div>

            <div className="flex flex-wrap items-center gap-2 w-full md:w-auto justify-end">
              {/* SLA Filter Pills */}
              <div
                className={`inline-flex rounded-lg p-0.5 border text-xs ${
                  isDark ? 'bg-[#18191a] border-[#3e4042]' : 'bg-gray-50 border-gray-200'
                }`}
              >
                {SLA_FILTER_OPTIONS.map((opt) => (
                  <button
                    key={opt.value || 'all'}
                    type="button"
                    onClick={() => {
                      setSlaFilter(opt.value);
                      setCurrentPage(1);
                    }}
                    className={`px-2.5 py-1.5 rounded-md font-semibold transition cursor-pointer ${
                      slaFilter === opt.value
                        ? opt.value === 'breached'
                          ? 'bg-red-600 text-white'
                          : opt.value === 'at_risk'
                            ? 'bg-amber-500 text-gray-900'
                            : isDark
                              ? 'bg-[#3a3b3c] text-white'
                              : 'bg-white text-gray-900 shadow-2xs'
                        : isDark
                          ? 'text-[#b0b3b8] hover:text-white'
                          : 'text-gray-600 hover:text-gray-900'
                    }`}
                  >
                    {opt.label}
                  </button>
                ))}
              </div>

              {hasActiveFilters && (
                <button
                  type="button"
                  onClick={clearAllFilters}
                  className={`px-3 sm:px-4 py-2 rounded-lg text-xs sm:text-sm font-semibold transition-colors border shadow-xs flex items-center justify-center shrink-0 whitespace-nowrap cursor-pointer ${
                    isDark
                      ? 'bg-[#1f1f1f] text-[#b0b3b8] border-[#3e4042] hover:bg-[#2a2a2f] hover:text-[#e4e6eb]'
                      : 'bg-white text-gray-600 border-gray-200 hover:bg-gray-50 hover:text-gray-900'
                  }`}
                >
                  Clear Filters
                </button>
              )}
            </div>
          </div>

          {/* ---------------- TABLE CONTAINER ---------------- */}
          <div
            className={`overflow-x-auto rounded-xl border relative shadow-xs ${
              isDark ? 'border-[#3e4042] bg-[#18191a]' : 'border-gray-200 bg-white'
            }`}
          >
            <table className="w-full text-left border-collapse">
              <thead
                className={
                  isDark
                    ? 'bg-[#1f1f1f] border-b border-[#3e4042]'
                    : 'bg-gray-50 border-b border-gray-200'
                }
              >
                <tr>
                  <Th center>Ticket ID</Th>
                  <Th>Requestor &amp; Claim</Th>
                  <Th center>
                    <DashboardDropdown
                      isOpen={categoryDropdownOpen}
                      setIsOpen={setCategoryDropdownOpen}
                      dropdownRef={categoryDropdownRef}
                      align="center"
                      width="w-60"
                      trigger={<span>Category</span>}
                      sections={[
                        {
                          title: 'Filter by Category',
                          items: categoryOptions.map((opt) => ({
                            label: opt.label,
                            isSelected: filterCategory === opt.value,
                            onClick: () => {
                              setFilterCategory(opt.value);
                              setCurrentPage(1);
                            },
                          })),
                        },
                      ]}
                    />
                  </Th>
                  <Th>Subject</Th>
                  <Th center>
                    <DashboardDropdown
                      isOpen={priorityDropdownOpen}
                      setIsOpen={setPriorityDropdownOpen}
                      dropdownRef={priorityDropdownRef}
                      align="center"
                      width="w-48"
                      trigger={<span>Priority</span>}
                      sections={[
                        {
                          title: 'Filter by Priority',
                          items: PRIORITY_OPTIONS.map((opt) => ({
                            label: opt.label,
                            isSelected: filterPriority === opt.value,
                            onClick: () => {
                              setFilterPriority(opt.value);
                              setCurrentPage(1);
                            },
                          })),
                        },
                      ]}
                    />
                  </Th>
                  <Th center>Date &amp; SLA</Th>
                  <Th center>
                    <DashboardDropdown
                      isOpen={statusDropdownOpen}
                      setIsOpen={setStatusDropdownOpen}
                      dropdownRef={statusDropdownRef}
                      align="center"
                      width="w-52"
                      trigger={<span>Status</span>}
                      sections={[
                        {
                          title: 'Filter by Status',
                          items: statusOptions.map((opt) => ({
                            label: opt.label,
                            isSelected: filterStatus === opt.value,
                            onClick: () => {
                              setFilterStatus(opt.value);
                              setCurrentPage(1);
                            },
                          })),
                        },
                      ]}
                    />
                  </Th>
                  <th
                    className={`px-3 py-2.5 text-[11px] uppercase font-bold tracking-wider ${
                      isDark ? 'text-[#b0b3b8]' : 'text-gray-500'
                    } text-center w-36 min-w-36`}
                  >
                    Actions
                  </th>
                </tr>
              </thead>
              <tbody className={isDark ? 'divide-y divide-[#3e4042]' : 'divide-y divide-gray-100'}>
                {loading ? (
                  <tr>
                    <td colSpan="8" className="px-6 py-16 text-center">
                      <ArrowPathIcon
                        className={`w-8 h-8 mx-auto mb-2 animate-spin ${
                          isDark ? 'text-yellow-400' : 'text-pup-dark-maroon'
                        }`}
                      />
                      <p className="text-xs opacity-70">Loading ticket queue…</p>
                    </td>
                  </tr>
                ) : currentItems.length === 0 ? (
                  <tr>
                    <td colSpan="8" className="px-6 py-16 text-center">
                      <div className="flex flex-col items-center justify-center gap-3">
                        <LifebuoyIcon
                          className={`w-12 h-12 stroke-[1.2] ${
                            isDark ? 'text-gray-600' : 'text-gray-300'
                          }`}
                        />
                        <div>
                          <div
                            className={`text-base font-semibold ${
                              isDark ? 'text-gray-300' : 'text-gray-600'
                            }`}
                          >
                            No tickets found
                          </div>
                          <div
                            className={`text-xs mt-1 ${
                              isDark ? 'text-gray-500' : 'text-gray-400'
                            }`}
                          >
                            Try adjusting your search terms or active filters.
                          </div>
                        </div>
                      </div>
                    </td>
                  </tr>
                ) : (
                  currentItems.map((ticket) => {
                    const isActiveTicket = !['resolved', 'closed'].includes(ticket.status);
                    return (
                      <tr
                        key={ticket.id}
                        onClick={() => setSelectedTicket(ticket)}
                        className={`transition-colors cursor-pointer ${
                          isDark ? 'hover:bg-[#3a3b3c]' : 'hover:bg-gray-50'
                        }`}
                      >
                        <Td center>
                          <span className="font-bold text-xs text-pup-dark-maroon dark:text-yellow-400 whitespace-nowrap">
                            {ticket.ticket_number}
                          </span>
                        </Td>

                        <Td>
                          <div className="font-semibold text-xs">
                            {ticket.requester?.name || 'Requestor'}
                          </div>
                          <div
                            className={`text-[11px] flex flex-wrap items-center gap-1 ${
                              isDark ? 'text-[#b0b3b8]' : 'text-gray-500'
                            }`}
                          >
                            <span>
                              {ticket.requester?.type === 'public'
                                ? 'Public Email'
                                : 'RIS Account'}
                            </span>
                            <span>•</span>
                            {ticket.is_claimed ? (
                              <span
                                className={
                                  ticket.claimed_by?.is_me
                                    ? 'font-semibold text-emerald-600 dark:text-emerald-400'
                                    : ''
                                }
                              >
                                {ticket.claimed_by?.is_me
                                  ? 'Claimed by You'
                                  : `Claimed: ${ticket.claimed_by?.name || 'Staff'}`}
                              </span>
                            ) : (
                              <span className="text-amber-600 dark:text-amber-400 font-medium">
                                Unclaimed
                              </span>
                            )}
                          </div>
                        </Td>

                        <Td center>
                          <span className="text-xs font-medium">
                            {ticket.category?.name || ticket.category?.code || '—'}
                          </span>
                        </Td>

                        <Td>
                          <div
                            className="font-medium text-xs max-w-xs truncate"
                            title={ticket.subject}
                          >
                            {ticket.subject}
                          </div>
                        </Td>

                        <Td center>{renderPriorityBadge(ticket.priority)}</Td>

                        <Td center>
                          <div className="flex flex-col items-center gap-1">
                            <span
                              className={`text-[11px] whitespace-nowrap ${
                                isDark ? 'text-[#b0b3b8]' : 'text-gray-500'
                              }`}
                            >
                              {formatDateTime(ticket.created_at)}
                            </span>
                            {renderSlaPill(ticket)}
                          </div>
                        </Td>

                        <Td center>{renderStatusBadge(ticket.status)}</Td>

                        <td className="px-3 py-2 text-center">
                          <div className="inline-flex items-center justify-center gap-1.5">
                            {canClaim && !ticket.is_claimed && isActiveTicket && (
                              <button
                                type="button"
                                disabled={claimingId === ticket.id || cooldown.isCoolingDown}
                                onClick={(e) => handleQuickClaim(e, ticket)}
                                className={`inline-flex items-center justify-center w-18 px-2.5 py-1 text-xs font-bold rounded-lg shadow-xs transition-all active:scale-95 cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed ${
                                  isDark
                                    ? 'bg-green-900/20 hover:bg-green-900/30 text-green-400 border border-green-600'
                                    : 'bg-green-500 hover:bg-green-700 text-white'
                                }`}
                                title="Claim ticket"
                              >
                                <span>{claimingId === ticket.id ? '…' : 'Claim'}</span>
                              </button>
                            )}

                            <button
                              type="button"
                              onClick={(e) => {
                                e.stopPropagation();
                                setSelectedTicket(ticket);
                              }}
                              className={`inline-flex items-center justify-center p-1.5 rounded-lg border transition-all active:scale-95 cursor-pointer ${
                                isDark
                                  ? 'bg-[#1f1f1f] border-[#3e4042] text-[#b0b3b8] hover:text-white hover:bg-[#2a2a2f]'
                                  : 'bg-white border-gray-200 text-gray-600 hover:text-[#800000] hover:bg-gray-50 shadow-xs'
                              }`}
                              aria-label={`View ticket ${ticket.ticket_number}`}
                              title="View ticket"
                            >
                              <EyeIcon className="w-4 h-4" />
                            </button>
                          </div>
                        </td>
                      </tr>
                    );
                  })
                )}
              </tbody>
            </table>

            {nextCursor && (
              <div className="p-3 text-center border-t border-gray-100 dark:border-[#3e4042]">
                <button
                  type="button"
                  disabled={loadingMore || cooldown.isCoolingDown}
                  onClick={() => fetchQueue({ cursor: nextCursor, append: true })}
                  className={`px-4 py-1.5 rounded-full text-xs font-semibold border transition cursor-pointer disabled:opacity-50 ${
                    isDark
                      ? 'border-[#3e4042] text-[#e4e6eb] hover:bg-[#252728]'
                      : 'border-gray-200 text-gray-700 hover:bg-gray-50'
                  }`}
                >
                  {loadingMore ? 'Loading more…' : 'Load more queue items'}
                </button>
              </div>
            )}

            {/* Pagination bar matching Staff Dashboard pagination */}
            <Pagination
              filteredCount={filteredTickets.length}
              indexOfFirstItem={indexOfFirstItem}
              indexOfLastItem={indexOfLastItem}
              currentPage={safePage}
              totalPages={totalPages}
              handlePrevPage={handlePrevPage}
              handleNextPage={handleNextPage}
            />
          </div>
        </div>
      )}

      {/* Ticket Details & Action Modal */}
      {modalOpen && (
        <TicketDetailModal
          ticket={selectedTicket}
          ticketId={routeTicketId || selectedTicket?.id}
          isOpen={modalOpen}
          onClose={closeTicketModal}
          onTicketUpdated={handleTicketUpdated}
        />
      )}
    </div>
  );
};

export default TicketingManagement;
