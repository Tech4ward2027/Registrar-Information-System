import React, { useState, useEffect, useCallback } from 'react';
import { useSearchParams, useNavigate, Link } from 'react-router-dom';
import { useTheme } from '../context/ThemeContext';
import {
  AreaChart, Area, XAxis, YAxis, CartesianGrid, Tooltip, ResponsiveContainer,
} from 'recharts';
import {
  DocumentTextIcon, UsersIcon, ClockIcon, ShieldExclamationIcon,
  BanknotesIcon, ExclamationTriangleIcon, ArrowTrendingUpIcon, ArrowTrendingDownIcon,
  ServerIcon, CheckCircleIcon, XCircleIcon, ChevronLeftIcon, ChevronRightIcon,
  CreditCardIcon, InboxIcon, EllipsisHorizontalIcon,
} from '@heroicons/react/24/outline';
import DropdownGroup from '../components/DropDown';
import { StatCardSkeleton, ChartCardSkeleton } from '../components/LoadingSkeleton';
import {
  getAnalyticsOverview,
  getAnalyticsVolumeTrend,
  getAdminRosterHealth,
  getAccessRequestThroughput,
  getCashierVerificationHealth,
  getScheduledJobsHealth,
} from '../services/api';

// ─── Constants ────────────────────────────────────────────────────────────

const RANGE_MAP = {
  'Today':      'today',
  'This Week':  'week',
  'This Month': 'month',
  'This Year':  'year',
  'All Time':   'all',
};

const formatDate = (dateStr) => {
  if (!dateStr) return '—';
  const d = new Date(dateStr);
  if (isNaN(d.getTime())) return dateStr;
  return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
};

/**
 * SuperAdmin Analytics Dashboard
 */
/**
 * SuperAdmin Analytics Dashboard — Phase 2 of the Analytics & Audit Log
 * Revamp plan. System-level view, separate from the Registrar-facing
 * AnalyticsDashboard (queue volume/processing time/staff performance):
 * this page answers "is the system itself healthy" — roster state,
 * delegated-access throughput, and cross-system verification health.
 *
 * System-wide volume/trend reuses the existing /analytics endpoints
 * (getAnalyticsOverview/getAnalyticsVolumeTrend) rather than a duplicate
 * system-analytics endpoint — Super Admin already has access to those
 * (RoleMiddleware treats role:4 as a superset of every gated role).
 *
 * Security posture (failed logins, lockouts, IDP-unreachable events) is
 * intentionally NOT a panel here yet — it depends on the security_events
 * table proposed in Phase 3 of the plan, which hasn't been built. Add it
 * once that lands; do not approximate it from audit_log in the meantime.
 */
const SuperAdminAnalyticsDashboard = () => {
  const { isDark } = useTheme();
  const navigate = useNavigate();
  const [searchParams, setSearchParams] = useSearchParams();

  const validTabs = ['analytics', 'roster'];
  const tabFromUrl = searchParams.get('tab');
  const activeTab = validTabs.includes(tabFromUrl) ? tabFromUrl : 'analytics';

  const handleTabChange = (tabKey) => {
    setSearchParams({ tab: tabKey });
  };

  const [dateRange, setDateRange] = useState('This Month');

  const [overview, setOverview]     = useState(null);
  const [volumeData, setVolumeData] = useState([]);
  const [roster, setRoster]         = useState(null);
  const [accessThroughput, setAccessThroughput] = useState(null);
  const [cashierHealth, setCashierHealth] = useState(null);
  const [jobsHealth, setJobsHealth] = useState(null);
  const [jobsPage, setJobsPage] = useState(1);
  const [backlogPage, setBacklogPage] = useState(1);

  const [loading, setLoading] = useState(true);
  const [error, setError]     = useState(null);

  const buildParams = useCallback(
    () => ({ range: RANGE_MAP[dateRange] ?? 'month' }),
    [dateRange]
  );

  useEffect(() => {
    let cancelled = false;
    setLoading(true);
    setError(null);

    const params = buildParams();

    Promise.all([
      getAnalyticsOverview(params),
      getAnalyticsVolumeTrend(params),
      getAdminRosterHealth(),
      getAccessRequestThroughput(params),
      getCashierVerificationHealth(params),
      getScheduledJobsHealth(),
    ])
      .then(([ovRes, volRes, rosterRes, accessRes, cashierRes, jobsRes]) => {
        if (cancelled) return;
        setOverview(ovRes.data);
        setVolumeData(volRes.data);
        setRoster(rosterRes.data);
        setAccessThroughput(accessRes.data);
        setCashierHealth(cashierRes.data);
        setJobsHealth(jobsRes.data);
      })
      .catch((err) => {
        if (cancelled) return;
        console.error('System analytics fetch error:', err);
        setError('Could not load system analytics. Please try again.');
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });

    return () => { cancelled = true; };
  }, [buildParams]);

  const overviewTrend = () => {
    if (!overview) return { label: '—', status: 'neutral' };
    const pct = overview.volume_change_pct;
    if (pct == null) return { label: 'No prior data', status: 'neutral' };
    return {
      label:  `${pct > 0 ? '+' : ''}${pct}% vs prev period`,
      status: pct > 0 ? 'up' : pct < 0 ? 'down' : 'neutral',
    };
  };
  const trend = overviewTrend();

  return (
    <div className={`space-y-6 font-sans ${isDark ? 'text-[#e4e6eb]' : 'text-gray-900'}`}>

      {/* ── Tab Switcher Navigation (Outside container card, matching Admin Management) ── */}
      <div className="hidden md:flex justify-center mx-4 sm:mx-6 mb-5">
        <div className={`inline-flex px-8 py-3.5 rounded-full transition-all duration-300 hover:-translate-y-0.5 ${isDark
          ? 'bg-[#242526] border border-[#3e4042] shadow-[0_2px_8px_rgba(0,0,0,0.2)] hover:shadow-[0_4px_16px_rgba(0,0,0,0.35)]'
          : 'bg-white border border-gray-200/80 shadow-[0_2px_8px_rgba(0,0,0,0.05)] hover:shadow-[0_4px_16px_rgba(0,0,0,0.1)]'
          } gap-8 items-center`}>
          <button
            onClick={() => handleTabChange("analytics")}
            className={`text-sm relative rounded-full flex items-center justify-center shrink-0 font-semibold transition-all duration-200 hover:scale-105 active:scale-95 cursor-pointer whitespace-nowrap ${activeTab === "analytics"
              ? isDark
                ? "text-yellow-400 font-bold"
                : "text-pup-dark-maroon font-black"
              : isDark
                ? "text-[#b0b3b8] hover:text-white"
                : "text-gray-500 hover:text-gray-900"
              }`}
          >
            System Analytics
          </button>
          <button
            onClick={() => handleTabChange("roster")}
            className={`text-sm relative rounded-full flex items-center justify-center shrink-0 font-semibold transition-all duration-200 hover:scale-105 active:scale-95 cursor-pointer whitespace-nowrap ${activeTab === "roster"
              ? isDark
                ? "text-yellow-400 font-bold"
                : "text-pup-dark-maroon font-black"
              : isDark
                ? "text-[#b0b3b8] hover:text-white"
                : "text-gray-500 hover:text-gray-900"
              }`}
          >
            Admin Roster Health
          </button>
        </div>
      </div>

      {/* ── Main Container Card (Matching Admin Management design format) ── */}
      <div className={`rounded-2xl p-4 sm:p-6 space-y-6 ${isDark ? 'bg-[#242526] text-[#e4e6eb] border border-[#3e4042]' : 'bg-white text-gray-900 shadow-md border border-gray-200/80'}`}>

        {/* ── Header + filter (Matching Admin Accounts title style) ── */}
        <div className="flex flex-col lg:flex-row items-start lg:items-end justify-between gap-3 w-full">
          <div>
            <h1 className={`text-xl font-bold ${isDark ? 'text-white' : 'text-gray-900'}`}>
              {activeTab === 'analytics' ? 'System Analytics' : 'Admin Roster Health'}
            </h1>
            <p className={`text-xs mt-1 ${isDark ? 'text-gray-400' : 'text-gray-500'}`}>
              {activeTab === 'analytics'
                ? 'System volume overview, access request throughput, and cashier verification metrics.'
                : 'Live snapshot of admin role assignments, pending activations, IDP sync failures, and scheduled jobs health.'}
            </p>
          </div>

          {activeTab === 'analytics' && (
            <div className="w-full sm:w-44">
              <DropdownGroup
                name="dateRange"
                value={dateRange}
                onChange={(e) => setDateRange(e.target.value)}
                options={['Today', 'This Week', 'This Month', 'This Year', 'All Time']}
              />
            </div>
          )}
        </div>

        {error && (
          <div className={`px-4 py-3 rounded-2xl text-sm font-bold border ${isDark ? 'bg-rose-950/40 border-rose-900 text-rose-300' : 'bg-rose-50 border-rose-200 text-rose-700'}`}>
            {error}
          </div>
        )}

        {activeTab === 'analytics' ? (
          <>
            {/* ── System volume (reuses existing /analytics endpoints) ── */}
            <div className="grid grid-cols-1 sm:grid-cols-3 gap-6">
              {loading || !overview
                ? Array.from({ length: 3 }).map((_, i) => <StatCardSkeleton key={i} isDark={isDark} />)
                : <>
                  <StatCard
                    title="Total Requests"
                    value={overview.total.toLocaleString()}
                    trend={trend.label}
                    status={trend.status}
                    icon={<DocumentTextIcon className="w-6 h-6" />}
                    lightColor="bg-amber-50" iconColor="text-amber-700"
                    isDark={isDark}
                  />
                  <StatCard
                    title="Claimed Docs"
                    value={overview.completed.toLocaleString()}
                    trend={overview?.completion_rate != null ? `${overview.completion_rate}% completion rate` : '—'}
                    status={overview?.completion_rate >= 70 ? 'up' : 'down'}
                    icon={<DocumentTextIcon className="w-6 h-6" />}
                    lightColor="bg-blue-50" iconColor="text-blue-700"
                    isDark={isDark}
                  />
                  <StatCard
                    title="Avg Processing Time"
                    value={overview.avg_processing_minutes != null ? `${overview.avg_processing_minutes}m` : '—'}
                    trend="System-wide average"
                    status="neutral"
                    icon={<ClockIcon className="w-6 h-6" />}
                    lightColor="bg-emerald-50" iconColor="text-emerald-700"
                    isDark={isDark}
                  />
                </>
              }
            </div>

            <div className="grid grid-cols-1 gap-6">
              {loading || volumeData.length === 0
                ? <ChartCardSkeleton isDark={isDark} />
                : (
                  <div className={`border p-6 rounded-4xl shadow-sm min-w-0 ${isDark ? 'border-[#3e4042] bg-[#242526]' : 'border-slate-200 bg-white'}`}>
                    <ChartHeader title="System Volume" sub="Requests Over Time — All Registrars" isDark={isDark} />
                    <div className="h-56">
                      <ResponsiveContainer width="100%" height={224}>
                        <AreaChart data={volumeData}>
                          <defs>
                            <linearGradient id="colorSaMaroon" x1="0" y1="0" x2="0" y2="1">
                              <stop offset="5%" stopColor="#800000" stopOpacity={0.1} />
                              <stop offset="95%" stopColor="#800000" stopOpacity={0} />
                            </linearGradient>
                          </defs>
                          <CartesianGrid strokeDasharray="3 3" vertical={false} stroke={isDark ? '#3e4042' : '#f1f5f9'} />
                          <XAxis dataKey="label" tick={{ fontSize: 11, fontWeight: 600, fill: isDark ? '#b0b3b8' : '#64748b' }} axisLine={false} tickLine={false} />
                          <YAxis tick={{ fontSize: 11, fill: isDark ? '#b0b3b8' : '#64748b' }} axisLine={false} tickLine={false} />
                          <Tooltip content={(props) => <SimpleTooltip {...props} isDark={isDark} unit="Requests" />} />
                          <Area type="monotone" dataKey="total" stroke="#800000" strokeWidth={3} fillOpacity={1} fill="url(#colorSaMaroon)" />
                        </AreaChart>
                      </ResponsiveContainer>
                    </div>
                  </div>
                )
              }
            </div>

            {/* ── Access request throughput + Cashier verification health ── */}
            <div className="grid grid-cols-1 md:grid-cols-2 gap-6 items-stretch">
              {loading || !accessThroughput
                ? <ChartCardSkeleton isDark={isDark} />
                : (
                  <PanelCard isDark={isDark} title="Access requests" icon={<UsersIcon className="w-5 h-5" />} className="h-full flex flex-col justify-between">
                    {accessThroughput.requested > 0 ? (
                      /* ── Active Pending State (Image 2) ── */
                      <div className="space-y-4 my-auto">
                        <div className={`p-4 rounded-2xl flex items-center gap-3.5 border ${
                          isDark ? 'bg-amber-950/30 border-amber-900/50' : 'bg-[#fffbeb] border-amber-200/80'
                        }`}>
                          <div className={`w-10 h-10 rounded-full flex items-center justify-center shrink-0 ${
                            isDark ? 'bg-amber-900/60 text-amber-300' : 'bg-[#fef3c7] text-[#92400e]'
                          }`}>
                            <ClockIcon className="w-5 h-5" />
                          </div>
                          <div>
                            <h4 className={`text-sm font-bold ${isDark ? 'text-amber-200' : 'text-[#78350f]'}`}>
                              {accessThroughput.requested} {accessThroughput.requested === 1 ? 'request is' : 'requests are'} waiting on you
                            </h4>
                            <p className={`text-xs ${isDark ? 'text-amber-400/80' : 'text-[#92400e]'}`}>
                              Nothing else needs attention right now.
                            </p>
                          </div>
                        </div>

                        <Link
                          to="/super-admin/user?tab=access-requests"
                          className={`w-full block py-3 rounded-2xl text-center text-sm font-bold text-white transition-all shadow-sm ${
                            isDark ? 'bg-[#800000] hover:bg-[#990000]' : 'bg-[#800000] hover:bg-[#660000]'
                          }`}
                        >
                          Review it now
                        </Link>
                      </div>
                    ) : accessThroughput.total === 0 ? (
                      /* ── Empty State (Image 1) ── */
                      <div className="flex flex-col items-center justify-center py-6 text-center my-auto">
                        <div className={`w-14 h-14 rounded-full flex items-center justify-center mb-3 ${isDark ? 'bg-zinc-800 text-gray-400' : 'bg-gray-100 text-gray-400'}`}>
                          <InboxIcon className="w-7 h-7" />
                        </div>
                        <h4 className={`text-base font-bold mb-1 ${isDark ? 'text-gray-200' : 'text-gray-800'}`}>
                          All quiet — nothing to review
                        </h4>
                        <p className={`text-xs text-center max-w-xs ${isDark ? 'text-gray-400' : 'text-gray-500'}`}>
                          No one has requested access {dateRange.toLowerCase() === 'all time' ? 'yet' : dateRange.toLowerCase()}. New requests will show up here as soon as they come in.
                        </p>
                      </div>
                    ) : (
                      /* ── All Caught Up (Decided) ── */
                      <div className="flex flex-col items-center justify-center py-6 text-center my-auto">
                        <div className={`w-14 h-14 rounded-full flex items-center justify-center mb-3 ${isDark ? 'bg-emerald-950/40 text-emerald-400' : 'bg-emerald-50 text-emerald-600'}`}>
                          <CheckCircleIcon className="w-7 h-7" />
                        </div>
                        <h4 className={`text-base font-bold mb-1 ${isDark ? 'text-gray-200' : 'text-gray-800'}`}>
                          All caught up — 0 requests waiting
                        </h4>
                        <p className={`text-xs text-center max-w-xs ${isDark ? 'text-gray-400' : 'text-gray-500'}`}>
                          All access requests submitted {dateRange.toLowerCase()} have been reviewed.
                        </p>
                      </div>
                    )}

                    {/* ── 3-Column Stats Row at Bottom ── */}
                    <div className="pt-4 border-t border-gray-100 dark:border-[#3e4042]">
                      <div className="grid grid-cols-3 text-center divide-x divide-gray-100 dark:divide-[#3e4042]">
                        <div>
                          <p className={`text-xl font-extrabold ${isDark ? 'text-white' : 'text-gray-900'}`}>{accessThroughput.total}</p>
                          <p className={`text-xs mt-1 ${isDark ? 'text-gray-400' : 'text-gray-500'}`}>Total {dateRange.toLowerCase()}</p>
                        </div>
                        <div>
                          <p className={`text-xl font-extrabold ${isDark ? 'text-white' : 'text-gray-900'}`}>{accessThroughput.fulfilled}</p>
                          <p className={`text-xs mt-1 ${isDark ? 'text-gray-400' : 'text-gray-500'}`}>Approved</p>
                        </div>
                        <div>
                          <p className={`text-xl font-extrabold ${isDark ? 'text-white' : 'text-gray-900'}`}>{accessThroughput.rejected}</p>
                          <p className={`text-xs mt-1 ${isDark ? 'text-gray-400' : 'text-gray-500'}`}>Declined</p>
                        </div>
                      </div>

                      <p className={`text-xs text-center mt-4 ${isDark ? 'text-gray-400' : 'text-gray-500'}`}>
                        {accessThroughput.approval_rate == null
                          ? "Approval rate and review time will show up once you've made your first decision."
                          : `Approval rate: ${accessThroughput.approval_rate}% · Avg review time: ${accessThroughput.avg_time_to_review_hours ?? 0}h`}
                      </p>
                    </div>
                  </PanelCard>
                )
              }

              {loading || !cashierHealth
                ? <ChartCardSkeleton isDark={isDark} />
                : (
                  <PanelCard
                    isDark={isDark}
                    title="Cashier Verification Health"
                    icon={<CreditCardIcon className="w-5 h-5" />}
                  >
                    <div className="grid grid-cols-3 gap-4 mb-4">
                      <MiniStat isDark={isDark} label="Attempts" value={cashierHealth.total_attempts} />
                      <div>
                        <p className={`text-[10px] font-bold uppercase tracking-wider ${isDark ? 'text-[#9a9a9a]' : 'text-slate-400'}`}>Matched</p>
                        <p className="text-xl font-black text-emerald-600 dark:text-emerald-400">{cashierHealth.matched?.toLocaleString?.() ?? cashierHealth.matched}</p>
                      </div>
                      <div>
                        <p className={`text-[10px] font-bold uppercase tracking-wider ${isDark ? 'text-[#9a9a9a]' : 'text-slate-400'}`}>Unmatched</p>
                        <p className={`text-xl font-black ${cashierHealth.unmatched > 0 ? 'text-rose-600 dark:text-rose-400' : (isDark ? 'text-[#e4e6eb]' : 'text-slate-800')}`}>
                          {cashierHealth.unmatched?.toLocaleString?.() ?? cashierHealth.unmatched}
                        </p>
                      </div>
                    </div>

                    <div className={`pt-3 border-t ${isDark ? 'border-[#3e4042]' : 'border-slate-100'}`}>
                      <div className="flex justify-between text-sm">
                        <span className={isDark ? 'text-[#9a9a9a]' : 'text-slate-400'}>Match rate</span>
                        <span className="font-bold text-emerald-600 dark:text-emerald-400">
                          {cashierHealth.match_rate != null ? `${cashierHealth.match_rate}%` : '—'}
                        </span>
                      </div>
                      <div className="w-full h-2 rounded-full bg-gray-100 dark:bg-zinc-800 overflow-hidden mt-2 mb-3">
                        <div
                          className="h-full bg-emerald-500 rounded-full transition-all duration-500"
                          style={{ width: `${cashierHealth.match_rate ?? 0}%` }}
                        />
                      </div>
                    </div>

                    <div className={`mt-2 pt-3 border-t ${isDark ? 'border-[#3e4042]' : 'border-slate-100'}`}>
                      <div className="flex items-center justify-between text-xs font-bold mb-2.5">
                        <span className={`flex items-center gap-1.5 ${isDark ? 'text-gray-200' : 'text-gray-900'}`}>
                          <ExclamationTriangleIcon className="w-4 h-4 text-amber-500 shrink-0" />
                          Unresolved backlog
                        </span>
                        <span className="min-w-[24px] px-2 py-0.5 text-center rounded-full text-xs font-bold bg-amber-100 text-amber-800 dark:bg-amber-950/60 dark:text-amber-300 shrink-0">
                          {cashierHealth.unresolved_backlog}
                        </span>
                      </div>

                      {cashierHealth.top_backlog_items.length > 0 ? (() => {
                        const BACKLOG_PER_PAGE = 5;
                        const backlogItems = cashierHealth.top_backlog_items;
                        const totalBacklogPages = Math.ceil(backlogItems.length / BACKLOG_PER_PAGE);
                        const paginatedBacklog = backlogItems.slice((backlogPage - 1) * BACKLOG_PER_PAGE, backlogPage * BACKLOG_PER_PAGE);

                        const pageNumbers = () => {
                          const pages = [];
                          const maxVisible = 5;
                          if (totalBacklogPages <= maxVisible) {
                            for (let i = 1; i <= totalBacklogPages; i++) pages.push(i);
                          } else {
                            pages.push(1);
                            if (backlogPage > 3) pages.push("...");
                            const start = Math.max(2, backlogPage - 1);
                            const end = Math.min(totalBacklogPages - 1, backlogPage + 1);
                            for (let i = start; i <= end; i++) pages.push(i);
                            if (backlogPage < totalBacklogPages - 2) pages.push("...");
                            pages.push(totalBacklogPages);
                          }
                          return pages;
                        };

                        return (
                          <>
                            <div className="space-y-1">
                              {paginatedBacklog.map((item) => (
                                <div
                                  key={item.id}
                                  className={`flex items-center justify-between py-1.5 px-0 text-xs ${
                                    isDark ? 'text-gray-200' : 'text-gray-700'
                                  }`}
                                >
                                  <span className="truncate font-medium pr-2">{item.raw_label}</span>
                                  <span className={`min-w-[24px] px-2 py-0.5 text-center rounded-md text-[11px] font-bold shrink-0 ${isDark ? 'bg-zinc-800 text-gray-300' : 'bg-gray-100 text-gray-600'}`}>
                                    {item.occurrence_count}
                                  </span>
                                </div>
                              ))}
                            </div>

                            {totalBacklogPages > 1 && (
                              <div className={`flex items-center justify-center gap-1 px-0 py-3 mt-2 border-t ${isDark ? 'border-[#3e4042]' : 'border-gray-100'}`}>
                                <button
                                  onClick={() => setBacklogPage((p) => Math.max(1, p - 1))}
                                  disabled={backlogPage === 1}
                                  className={`flex items-center gap-1 text-xs px-2 py-1 disabled:opacity-40 cursor-pointer ${isDark ? 'text-[#b0b3b8] hover:text-white' : 'text-gray-500 hover:text-gray-800'}`}
                                >
                                  <ChevronLeftIcon className="w-3.5 h-3.5" /> Previous
                                </button>
                                {pageNumbers().map((p, i) => (
                                  <button
                                    key={i}
                                    onClick={() => typeof p === "number" && setBacklogPage(p)}
                                    disabled={p === "..."}
                                    className={`w-7 h-7 rounded-lg text-xs font-medium transition-colors cursor-pointer ${
                                      backlogPage === p
                                        ? (isDark ? 'bg-yellow-400 text-gray-900 font-bold' : 'bg-[#800000] text-white font-bold')
                                        : (isDark ? 'text-[#b0b3b8] hover:bg-[#2a2a2f]' : 'text-gray-500 hover:bg-gray-100')
                                    } ${p === "..." ? "cursor-default pointer-events-none" : ""}`}
                                  >
                                    {p}
                                  </button>
                                ))}
                                <button
                                  onClick={() => setBacklogPage((p) => Math.min(totalBacklogPages, p + 1))}
                                  disabled={backlogPage === totalBacklogPages}
                                  className={`flex items-center gap-1 text-xs px-2 py-1 disabled:opacity-40 cursor-pointer ${isDark ? 'text-[#b0b3b8] hover:text-white' : 'text-gray-500 hover:text-gray-800'}`}
                                >
                                  Next <ChevronRightIcon className="w-3.5 h-3.5" />
                                </button>
                              </div>
                            )}
                          </>
                        );
                      })() : (
                        <p className={`text-xs italic ${isDark ? 'text-[#7a7a7a]' : 'text-slate-400'}`}>No unresolved backlog items.</p>
                      )}

                      {cashierHealth.unresolved_backlog > 0 && (
                        <div className="pt-2">
                          <Link
                            to="/super-admin/documents?tab=unmatched-cashier"
                            className={`inline-flex items-center gap-1 text-xs font-bold ${
                              isDark ? 'text-amber-400 hover:text-amber-300' : 'text-[#800000] hover:text-maroon-700'
                            } hover:underline`}
                          >
                            View all {cashierHealth.unresolved_backlog} &rarr;
                          </Link>
                        </div>
                      )}
                    </div>
                  </PanelCard>
                )
              }
            </div>
          </>
        ) : (
          /* ── Admin roster health + Scheduled jobs health ── */
          <div className="space-y-6">
            <div>
              <ChartHeader isDark={isDark} />
              {loading || !roster
                ? <div className="grid grid-cols-1 md:grid-cols-3 gap-6">{Array.from({ length: 3 }).map((_, i) => <ChartCardSkeleton key={i} isDark={isDark} />)}</div>
                : (
                  <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
                    {/* Role breakdown */}
                    <div className={`rounded-2xl border p-5 shadow-xs ${isDark ? 'bg-[#242526] border-[#3e4042]' : 'bg-white border-gray-200/80'}`}>
                      <div className="flex items-center justify-between mb-4">
                        <div className="flex items-center gap-2">
                          <UsersIcon className={`w-5 h-5 ${isDark ? 'text-amber-400' : 'text-[#800000]'}`} />
                          <h3 className={`text-base font-bold ${isDark ? 'text-white' : 'text-gray-900'}`}>
                            Role Assignments
                          </h3>
                        </div>
                      </div>
                      {roster.role_breakdown.length === 0
                        ? <EmptyRow isDark={isDark} label="No admin or super admin role assignments yet." />
                        : (() => {
                            const zeroActiveRoles = roster.role_breakdown.filter((r) => Number(r.active_count) === 0);
                            const hasZeroActive = zeroActiveRoles.length > 0;
                            const zeroRoleNames = zeroActiveRoles.map((r) => r.role_name).join(', ');

                            return (
                              <>
                                <table className="w-full text-sm">
                                  <thead>
                                    <tr className={`text-[10px] font-bold uppercase tracking-wider ${isDark ? 'text-[#9a9a9a]' : 'text-slate-400'}`}>
                                      <th className="text-left pb-2 font-bold">Role</th>
                                      <th className="text-center pb-2 font-bold">Active</th>
                                      <th className="text-center pb-2 font-bold">Due to Expire</th>
                                      <th className="text-center pb-2 font-bold">Revoked</th>
                                    </tr>
                                  </thead>
                                  <tbody>
                                    {roster.role_breakdown.map((row) => {
                                      const isZeroActive = Number(row.active_count) === 0;
                                      return (
                                        <tr key={row.role_id} className={`border-t ${isDark ? 'border-[#3e4042]' : 'border-slate-100'}`}>
                                          <td className={`py-2.5 font-bold ${isDark ? 'text-gray-200' : 'text-gray-900'}`}>{row.role_name}</td>
                                          <td className="py-2.5 text-center font-bold">
                                            <span className={isZeroActive ? 'text-rose-500 font-extrabold' : (isDark ? 'text-white' : 'text-gray-900')}>
                                              {row.active_count}
                                            </span>
                                          </td>
                                          <td className={`py-2.5 text-center ${Number(row.due_to_expire_count) > 0 ? 'text-amber-500 font-bold' : (isDark ? 'text-gray-300' : 'text-gray-600')}`}>
                                            {row.due_to_expire_count}
                                          </td>
                                          <td className={`py-2.5 text-center font-normal ${isDark ? 'text-gray-400' : 'text-slate-400'}`}>
                                            {row.revoked_count}
                                          </td>
                                        </tr>
                                      );
                                    })}
                                  </tbody>
                                </table>

                                {hasZeroActive && (
                                  <div className={`mt-4 p-3 rounded-xl border flex items-start gap-2.5 text-xs font-semibold ${isDark ? 'bg-rose-950/40 border-rose-900/60 text-rose-300' : 'bg-rose-50 border-rose-200 text-rose-800'}`}>
                                    <ExclamationTriangleIcon className="w-4 h-4 shrink-0 mt-0.5 text-rose-500" />
                                    <div>
                                      <span className="font-bold">Critical Alert:</span> 0 active holders for {zeroRoleNames || 'administrative role'}. System access may be locked out.
                                    </div>
                                  </div>
                                )}
                              </>
                            );
                          })()
                      }
                    </div>

                    {/* Pending activations (Card design matching reference image) */}
                    <div className={`rounded-2xl border p-5 shadow-xs ${isDark ? 'bg-[#242526] border-[#3e4042]' : 'bg-white border-gray-200/80'}`}>
                      <div className="flex items-center justify-between mb-4">
                        <div className="flex items-center gap-2">
                          <ClockIcon className={`w-5 h-5 ${isDark ? 'text-amber-400' : 'text-[#800000]'}`} />
                          <h3 className={`text-base font-bold ${isDark ? 'text-white' : 'text-gray-900'}`}>
                            Pending activations
                          </h3>
                        </div>
                        <span className={`px-2.5 py-0.5 rounded-full text-xs font-bold ${isDark ? 'bg-zinc-800 text-gray-200' : 'bg-gray-100 text-gray-700'}`}>
                          {roster.pending_activations.total}
                        </span>
                      </div>

                      {roster.pending_activations.items.length === 0 ? (
                        <p className={`text-xs italic ${isDark ? 'text-[#7a7a7a]' : 'text-slate-400'}`}>No pending activations.</p>
                      ) : (
                        <div className="divide-y divide-gray-100 dark:divide-[#3e4042]">
                          {roster.pending_activations.items.map((u) => (
                            <div key={u.user_id} className="py-3 flex items-center justify-between text-sm">
                              <span className={`font-normal truncate pr-4 ${isDark ? 'text-gray-200' : 'text-gray-800'}`}>
                                {u.email}
                              </span>
                              <span className={`shrink-0 font-normal ${isDark ? 'text-gray-400' : 'text-gray-500'}`}>
                                {formatDate(u.pending_expires_at)}
                              </span>
                            </div>
                          ))}
                        </div>
                      )}
                    </div>

                    {/* IDP sync failures */}
                    <div className={`rounded-2xl border p-5 shadow-xs ${isDark ? 'bg-[#242526] border-[#3e4042]' : 'bg-white border-gray-200/80'}`}>
                      <div className="flex items-center justify-between mb-4">
                        <div className="flex items-center gap-2">
                          <ShieldExclamationIcon className={`w-5 h-5 ${isDark ? 'text-amber-400' : 'text-[#800000]'}`} />
                          <h3 className={`text-base font-bold ${isDark ? 'text-white' : 'text-gray-900'}`}>
                            IDP sync failures
                          </h3>
                        </div>
                        <span className={`px-2.5 py-0.5 rounded-full text-xs font-bold ${isDark ? 'bg-zinc-800 text-gray-200' : 'bg-gray-100 text-gray-700'}`}>
                          {roster.idp_sync_failures.count_last_30_days}
                        </span>
                      </div>

                      {roster.idp_sync_failures.recent.length === 0 ? (
                        <p className={`text-xs italic ${isDark ? 'text-[#7a7a7a]' : 'text-slate-400'}`}>No IDP sync failures recently.</p>
                      ) : (
                        <div className="divide-y divide-gray-100 dark:divide-[#3e4042]">
                          {roster.idp_sync_failures.recent.map((f, i) => (
                            <div key={i} className="py-3 flex items-center justify-between text-sm">
                              <span className={`font-normal truncate pr-4 ${isDark ? 'text-gray-200' : 'text-gray-800'}`}>
                                {f.target_email ?? 'Unknown'}
                              </span>
                              <span className={`shrink-0 font-normal ${isDark ? 'text-gray-400' : 'text-gray-500'}`}>
                                {formatDate(f.created_at)}
                              </span>
                            </div>
                          ))}
                        </div>
                      )}
                    </div>
                  </div>
                )
              }
            </div>

            {/* ── Scheduled jobs health ── */}
            <div>
              <ChartHeader isDark={isDark} />
              {loading || !jobsHealth
                ? <ChartCardSkeleton isDark={isDark} />
                : (() => {
                    const JOBS_PER_PAGE = 5;
                    const jobsList = jobsHealth.jobs || [];
                    const totalJobsPages = Math.ceil(jobsList.length / JOBS_PER_PAGE) || 1;
                    const paginatedJobs = jobsList.slice(
                      (jobsPage - 1) * JOBS_PER_PAGE,
                      jobsPage * JOBS_PER_PAGE
                    );

                    return (
                      <div className={`border p-4 sm:p-6 rounded-2xl sm:rounded-4xl shadow-sm min-w-0 ${isDark ? 'border-[#3e4042] bg-[#242526]' : 'border-slate-200 bg-white'}`}>
                        {jobsHealth.needs_attention > 0 && (
                          <div className={`flex flex-col sm:flex-row items-start sm:items-center gap-2 mb-4 px-3.5 py-2.5 rounded-2xl text-xs font-bold border ${isDark ? 'bg-rose-950/40 border-rose-900 text-rose-300' : 'bg-rose-50 border-rose-200 text-rose-700'}`}>
                            <ExclamationTriangleIcon className="w-4 h-4 shrink-0 mt-0.5 sm:mt-0" />
                            <span>{jobsHealth.needs_attention} job(s) need attention — failed, stalled, overdue, or never recorded a run.</span>
                          </div>
                        )}

                        {/* Mobile Cards View (< md) */}
                        <div className="md:hidden space-y-3">
                          {paginatedJobs.map((job) => (
                            <div
                              key={job.job_name}
                              className={`p-3.5 rounded-2xl border text-xs space-y-2 ${
                                isDark ? 'bg-zinc-900/40 border-[#3e4042]' : 'bg-gray-50/80 border-gray-100'
                              }`}
                            >
                              <div className="flex items-start justify-between gap-2">
                                <div>
                                  <div className="font-bold text-sm text-gray-900 dark:text-white">{job.job_name}</div>
                                  <div className={`text-[11px] mt-0.5 ${isDark ? 'text-gray-400' : 'text-gray-500'}`}>{job.schedule}</div>
                                </div>
                                <JobStatusBadge status={job.status} />
                              </div>
                              {job.error_message && (
                                <div className={`text-[11px] font-normal truncate max-w-xs ${isDark ? 'text-rose-400' : 'text-rose-600'}`} title={job.error_message}>
                                  {job.error_message}
                                </div>
                              )}
                              <div className="flex items-center justify-between text-[11px] pt-2 border-t border-gray-200/50 dark:border-zinc-700/50">
                                <span className={isDark ? 'text-gray-400' : 'text-gray-500'}>
                                  Last Run: <span className="font-semibold text-gray-800 dark:text-gray-200">{job.last_started_at ? new Date(job.last_started_at).toLocaleString() : '—'}</span>
                                </span>
                                <span className={isDark ? 'text-gray-400' : 'text-gray-500'}>
                                  Rows: <span className="font-bold text-gray-800 dark:text-gray-200">{job.rows_affected ?? '—'}</span>
                                </span>
                              </div>
                            </div>
                          ))}
                        </div>

                        {/* Desktop Table View (≥ md) */}
                        <div className="hidden md:block overflow-x-auto">
                          <table className="w-full text-sm min-w-[550px]">
                            <thead>
                              <tr className={`text-[10px] font-bold uppercase tracking-wider ${isDark ? 'text-[#9a9a9a]' : 'text-slate-400'}`}>
                                <th className="text-left pb-3 font-bold">Job</th>
                                <th className="text-center pb-3 font-bold">Schedule</th>
                                <th className="text-center pb-3 font-bold">Status</th>
                                <th className="text-center pb-3 font-bold">Last Run</th>
                                <th className="text-center pb-3 font-bold">Rows</th>
                              </tr>
                            </thead>
                            <tbody>
                              {paginatedJobs.map((job) => (
                                <tr key={job.job_name} className={`border-t align-middle ${isDark ? 'border-[#3e4042]' : 'border-slate-100'}`}>
                                  <td className="py-3 text-left align-middle font-bold">
                                    <div>{job.job_name}</div>
                                    {job.error_message && (
                                      <div className={`text-[11px] font-normal mt-0.5 truncate max-w-xs ${isDark ? 'text-rose-400' : 'text-rose-600'}`} title={job.error_message}>
                                        {job.error_message}
                                      </div>
                                    )}
                                  </td>
                                  <td className={`py-3 text-center align-middle ${isDark ? 'text-[#b0b3b8]' : 'text-slate-500'}`}>{job.schedule}</td>
                                  <td className="py-3 text-center align-middle">
                                    <JobStatusBadge status={job.status} />
                                  </td>
                                  <td className={`py-3 text-center align-middle ${isDark ? 'text-[#b0b3b8]' : 'text-slate-500'}`}>
                                    {job.last_started_at ? new Date(job.last_started_at).toLocaleString() : '—'}
                                  </td>
                                  <td className="py-3 text-center align-middle font-bold">{job.rows_affected ?? '—'}</td>
                                </tr>
                              ))}
                            </tbody>
                          </table>
                        </div>

                        {totalJobsPages > 1 && (() => {
                          const pageNumbers = () => {
                            const pages = [];
                            const maxVisible = 5;
                            if (totalJobsPages <= maxVisible) {
                              for (let i = 1; i <= totalJobsPages; i++) pages.push(i);
                            } else {
                              pages.push(1);
                              if (jobsPage > 3) pages.push("...");
                              const start = Math.max(2, jobsPage - 1);
                              const end = Math.min(totalJobsPages - 1, jobsPage + 1);
                              for (let i = start; i <= end; i++) pages.push(i);
                              if (jobsPage < totalJobsPages - 2) pages.push("...");
                              pages.push(totalJobsPages);
                            }
                            return pages;
                          };

                          return (
                            <div className={`flex flex-wrap items-center justify-center gap-1.5 px-2 sm:px-4 py-3 sm:py-4 mt-4 border-t ${isDark ? 'border-[#3e4042]' : 'border-gray-100'}`}>
                              <button
                                onClick={() => setJobsPage((p) => Math.max(1, p - 1))}
                                disabled={jobsPage === 1}
                                className={`flex items-center gap-1 text-xs sm:text-sm px-2 py-1 disabled:opacity-40 cursor-pointer ${isDark ? 'text-[#b0b3b8] hover:text-white' : 'text-gray-500 hover:text-gray-800'}`}
                              >
                                <ChevronLeftIcon className="w-3.5 h-3.5 sm:w-4 sm:h-4" /> Previous
                              </button>
                              {pageNumbers().map((p, i) => (
                                <button
                                  key={i}
                                  onClick={() => typeof p === "number" && setJobsPage(p)}
                                  disabled={p === "..."}
                                  className={`w-7 h-7 sm:w-8 sm:h-8 rounded-lg text-xs sm:text-sm font-medium transition-colors cursor-pointer ${
                                    jobsPage === p
                                      ? (isDark ? 'bg-amber-400 text-gray-900 font-bold' : 'bg-[#800000] text-white font-bold')
                                      : (isDark ? 'text-[#b0b3b8] hover:bg-[#2a2a2f]' : 'text-gray-500 hover:bg-gray-100')
                                  } ${p === "..." ? "cursor-default pointer-events-none" : ""}`}
                                >
                                  {p}
                                </button>
                              ))}
                              <button
                                onClick={() => setJobsPage((p) => Math.min(totalJobsPages, p + 1))}
                                disabled={jobsPage === totalJobsPages}
                                className={`flex items-center gap-1 text-xs sm:text-sm px-2 py-1 disabled:opacity-40 cursor-pointer ${isDark ? 'text-[#b0b3b8] hover:text-white' : 'text-gray-500 hover:text-gray-800'}`}
                              >
                                Next <ChevronRightIcon className="w-3.5 h-3.5 sm:w-4 sm:h-4" />
                              </button>
                            </div>
                          );
                        })()}
                      </div>
                    );
                  })()
              }
            </div>
          </div>
        )}
      </div>
    </div>
  );
};

// ─── Small shared building blocks ──────────────────────────────────────────
// Deliberately not imported from AnalyticsDashboard.jsx — those are
// module-private consts in that file, not exported. Re-declared here at a
// smaller scope (no sort carets, no pie-chart specifics) since this page's
// needs are simpler; promote to a shared component file if a third
// analytics page needs the same pieces.

const StatCard = ({ title, value, trend, status, icon, lightColor, iconColor, isDark }) => {
  const chip = {
    up:      'bg-emerald-100 text-emerald-700',
    down:    'bg-rose-100 text-rose-700',
    neutral: 'bg-amber-100 text-amber-700',
  }[status] ?? 'bg-slate-100 text-slate-500';

  return (
    <div className={`relative p-6 rounded-4xl border shadow-sm ${isDark ? 'bg-[#242526] border-[#3e4042]' : 'bg-white border-slate-200'}`}>
      <div className="flex justify-between items-start">
        <div className="space-y-1">
          <p className={`text-[11px] font-black uppercase tracking-[0.12em] ${isDark ? 'text-[#9a9a9a]' : 'text-slate-400'}`}>{title}</p>
          <h3 className={`text-4xl font-black tracking-tighter ${isDark ? 'text-[#e4e6eb]' : 'text-slate-800'}`}>{value}</h3>
        </div>
        <div className={`p-3 ${lightColor} ${iconColor} rounded-2xl shadow-sm`}>{icon}</div>
      </div>
      <div className="mt-6 flex items-center gap-2">
        <div className={`flex items-center gap-1 px-2.5 py-1 rounded-full text-[11px] font-black ${chip}`}>
          {status === 'up'   && <ArrowTrendingUpIcon   className="w-3 h-3" />}
          {status === 'down' && <ArrowTrendingDownIcon className="w-3 h-3" />}
          {trend}
        </div>
      </div>
    </div>
  );
};

const ChartHeader = ({ title, sub, isDark }) => (
  <div className="mb-4">
    <h2 className={`text-lg font-bold ${isDark ? 'text-white' : 'text-gray-900'}`}>{title}</h2>
    {sub && <p className={`text-xs mt-0.5 ${isDark ? 'text-gray-400' : 'text-gray-500'}`}>{sub}</p>}
  </div>
);

const PanelCard = ({ title, sub, icon, action, isDark, children, className = '' }) => (
  <div className={`border p-6 rounded-4xl shadow-sm min-w-0 flex flex-col justify-between ${isDark ? 'border-[#3e4042] bg-[#242526]' : 'border-slate-200 bg-white'} ${className}`}>
    <div>
      <div className="flex items-center justify-between mb-4">
        <div className="flex items-center gap-2">
          <span className={isDark ? 'text-amber-400' : 'text-[#800000]'}>{icon}</span>
          <div>
            <h3 className={`text-sm font-black uppercase tracking-tight ${isDark ? 'text-[#e4e6eb]' : 'text-slate-800'}`}>{title}</h3>
            {sub && <p className={`text-[10px] font-bold uppercase tracking-widest ${isDark ? 'text-[#9a9a9a]' : 'text-slate-400'}`}>{sub}</p>}
          </div>
        </div>
        {action}
      </div>
      {children}
    </div>
  </div>
);

const MiniStat = ({ label, value, isDark }) => (
  <div>
    <p className={`text-[10px] font-bold uppercase tracking-wider ${isDark ? 'text-[#9a9a9a]' : 'text-slate-400'}`}>{label}</p>
    <p className={`text-xl font-black ${isDark ? 'text-[#e4e6eb]' : 'text-slate-800'}`}>{value?.toLocaleString?.() ?? value}</p>
  </div>
);

// Status pill for the Scheduled Jobs Health table — mirrors the JobRunLog
// status values the backend actually sends ('success' | 'failed' | 'running'
// | 'stalled' | 'overdue' | 'never_run', see SuperAdminAnalyticsService::
// scheduledJobsHealth()), plus a safe fallback for anything unrecognized
// rather than rendering a blank pill.
//
// 'overdue' gets its own (orange, distinct from amber 'stalled') style
// rather than reusing 'stalled' — they're different failure modes a
// SuperAdmin needs to tell apart at a glance: 'stalled' means a run is
// stuck mid-execution right now, 'overdue' means the last run finished
// cleanly but the job never started again on schedule (e.g. the
// scheduler container was down across an entire tick).
const JOB_STATUS_STYLES = {
  success:   { label: 'Success',    icon: CheckCircleIcon,        className: 'bg-emerald-50 text-emerald-700 border-emerald-200' },
  failed:    { label: 'Failed',     icon: XCircleIcon,            className: 'bg-rose-50 text-rose-700 border-rose-200' },
  running:   { label: 'Running',    icon: ClockIcon,               className: 'bg-blue-50 text-blue-700 border-blue-200' },
  stalled:   { label: 'Stalled',    icon: ExclamationTriangleIcon, className: 'bg-amber-50 text-amber-700 border-amber-200' },
  overdue:   { label: 'Overdue',    icon: ExclamationTriangleIcon, className: 'bg-orange-50 text-orange-700 border-orange-200' },
  never_run: { label: 'Never Run',  icon: ExclamationTriangleIcon, className: 'bg-slate-100 text-slate-500 border-slate-200' },
};

const JobStatusBadge = ({ status }) => {
  const style = JOB_STATUS_STYLES[status] ?? { label: status, icon: ServerIcon, className: 'bg-slate-100 text-slate-500 border-slate-200' };
  const Icon = style.icon;
  return (
    <span className={`inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[11px] font-bold border ${style.className}`}>
      <Icon className="w-3.5 h-3.5" />
      {style.label}
    </span>
  );
};

const EmptyRow = ({ label, isDark }) => (
  <p className={`text-xs italic ${isDark ? 'text-[#7a7a7a]' : 'text-slate-400'}`}>{label}</p>
);

const SimpleTooltip = ({ active, payload, isDark, unit }) => {
  if (!active || !payload?.length) return null;
  const item = payload[0];
  return (
    <div
      className={`px-3.5 py-2.5 rounded-2xl text-xs border pointer-events-none ${isDark ? 'bg-[#242526] border-[#3e4042] text-[#e4e6eb]' : 'bg-white border-slate-200/90 text-slate-800'}`}
      style={{ boxShadow: isDark ? '0 10px 25px -5px rgba(0,0,0,0.6)' : '0 10px 25px -5px rgba(0,0,0,0.12)' }}
    >
      <span className="font-extrabold">{item.value} {unit}</span>
    </div>
  );
};

export default SuperAdminAnalyticsDashboard;