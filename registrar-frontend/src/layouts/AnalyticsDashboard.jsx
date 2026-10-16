import React, { useState, useEffect, useCallback } from 'react';
import { useTheme } from '../context/ThemeContext';
import { useAuth } from '../context/AuthProvider';
import {
  BarChart, Bar, PieChart, Pie, Cell,
  XAxis, YAxis, CartesianGrid, Tooltip, ResponsiveContainer,
  AreaChart, Area, RadialBarChart, RadialBar,
} from 'recharts';
import {
  DocumentTextIcon, BellAlertIcon, CheckCircleIcon, ClockIcon,
  ArrowTrendingUpIcon, ArrowTrendingDownIcon,
} from '@heroicons/react/24/outline';
import DropdownGroup from '../components/DropDown';
import AIInsightCard from '../components/AIInsightCard';
import AIQueryChat   from '../components/AIQueryChat';
import MonthRangeModal from '../components/MonthRangeModal';
import { StatCardSkeleton, ChartCardSkeleton } from '../components/LoadingSkeleton';
import {
  getDocumentTypes,
  getAnalyticsOverview,
  getAnalyticsVolumeTrend,
  getAnalyticsByDocType,
  getAnalyticsByStatus,
  getAnalyticsProcessingTime,
  getAnalyticsPeakHours,
  getAnalyticsByPurpose,
  postAnalyticsAiReport,
  postAnalyticsAiQuery,
} from '../services/api';
import SuccessToast from '../components/SuccessToast.jsx';
import ErrorToast from '../components/ErrorToast.jsx';

// ─── Constants ────────────────────────────────────────────────────────────

const RANGE_MAP = {
  'Today':      'today',
  'This Week':  'week',
  'This Month': 'month',
  'This Year':  'year',
  'All Time':   'all',
};

const DOC_COLORS  = ['#800000','#A52A2A','#D2691E','#E9967A','#C04000','#B03000'];
const PIE_COLORS  = ['#800000','#FFC72C','#A52A2A','#E9967A','#D2691E'];
const HOUR_COLOR  = '#800000';

/**
 * Sorts Staff Performance rows by an arbitrary numeric column, nulls last
 * regardless of direction (a null rate/forfeit-rate isn't "0", it's "not
 * enough data" and shouldn't visually rank as the best or worst).
 */
const sortStaffRows = (rows, { key, dir }) => {
  const sign = dir === 'asc' ? 1 : -1;
  return [...rows].sort((a, b) => {
    let av = a[key];
    let bv = b[key];
    if (key === 'display_name') {
      av = (a.display_name?.trim() || a.email || '').toLowerCase();
      bv = (b.display_name?.trim() || b.email || '').toLowerCase();
      return av.localeCompare(bv) * sign;
    }
    if (av == null && bv == null) return 0;
    if (av == null) return 1;
    if (bv == null) return -1;
    return (av - bv) * sign;
  });
};

// ─── Component ────────────────────────────────────────────────────────────

const AnalyticsDashboard = () => {
  const { isDark } = useTheme();
  // Retired AI narrative card + chatbot: shown ONLY when the backend reports
  // FEATURE_ANALYTICS_AI_LEGACY on (user.features, see App\Support\FeatureFlags).
  // Strict `=== true` so a missing/undefined flag fails closed. UI convenience
  // only: the endpoints themselves return 404 server-side when the flag is off.
  const auth = useAuth();
  const aiLegacyEnabled = auth?.user?.features?.analytics_ai_legacy === true;
  
  // Filters
  const [dateRange, setDateRange]     = useState('This Month');
  const [customFrom, setCustomFrom]   = useState('');
  const [customTo, setCustomTo]       = useState('');
  const [docType, setDocType]         = useState('All Documents');
  const [documentTypes, setDocumentTypes] = useState([]);

  // Chart data
  const [overview, setOverview]             = useState(null);
  const [volumeData, setVolumeData]         = useState([]);
  const [docTypeData, setDocTypeData]       = useState([]);
  const [statusData, setStatusData]         = useState([]);
  const [processingData, setProcessingData] = useState({ by_document_type: [], by_admin: [] });
  const [peakHoursData, setPeakHoursData]   = useState([]);
  const [purposeData, setPurposeData]       = useState([]);

  // Staff Performance table sort — defaults to Avg Processing Time (asc),
  // matching the backend's default order, but any column header can be
  // clicked to re-sort. Keeps "fastest average" from being the only lens
  // on staff performance now that Min/Max, Rate, and Forfeit Rate are
  // available side by side.
  const [staffSort, setStaffSort] = useState({ key: 'avg_minutes', dir: 'asc' });

  // AI report state
  const [aiNarrative, setAiNarrative]   = useState(null);
  const [aiLoading, setAiLoading]       = useState(false);
  const [aiError, setAiError]           = useState(null);
  const [aiGeneratedAt, setAiGeneratedAt] = useState(null);
  // Export modal state
  const [exportModalOpen, setExportModalOpen] = useState(false);
  const [exportLoading, setExportLoading] = useState(false);
  const [toastSuccess, setToastSuccess] = useState('');
  const [toastError, setToastError] = useState('');

  // ── Build params object from current filters ──────────────────────────

  const buildParams = useCallback(() => {
    if (dateRange === 'Custom Range' && customFrom && customTo) {
      return { range: 'custom', from: customFrom, to: customTo };
    }
    return { range: RANGE_MAP[dateRange] ?? 'month' };
  }, [dateRange, customFrom, customTo]);

  // ── Load document type dropdown once ──────────────────────────────────

  useEffect(() => {
    getDocumentTypes()
      .then((res) => {
        const seen = new Set(['All Documents']);
        const options = ['All Documents'];

        (res.data ?? []).forEach((docType) => {
          const rawName = String(docType.document_name ?? '').trim();
          if (!rawName) return;

          const normalizedName = rawName.toLowerCase() === 'certification'
            ? 'CERTIFICATION'
            : rawName;

          const dedupeKey = normalizedName.toLowerCase();
          if (seen.has(dedupeKey)) return;
          seen.add(dedupeKey);
          options.push(normalizedName);
        });

        // If CERTIFICATION exists, add an "All Certification" option after 'All Documents'
        if (options.find((o) => String(o).toUpperCase() === 'CERTIFICATION')) {
          // insert at index 1
          options.splice(1, 0, 'All Certification');
        }
        setDocumentTypes(options);
      })
      .catch(() => setDocumentTypes(['All Documents']));
  }, []);

  // ── Reload all charts when date filter changes ────────────────────────

  useEffect(() => {
    const shouldFetch =
      dateRange !== 'Custom Range' ||
      (customFrom && customTo && customFrom <= customTo);

    if (!shouldFetch) return;

    const params = buildParams();

    // Build doc-type params — pass name filter only when not 'All Documents'
    const docParams = docType !== 'All Documents'
      ? { ...params, document_name: docType }
      : params;

    Promise.all([
      getAnalyticsOverview(params),
      getAnalyticsVolumeTrend(params),
      getAnalyticsByDocType(docParams),
      getAnalyticsByStatus(params),
      getAnalyticsProcessingTime(params),
      getAnalyticsPeakHours(params),
      getAnalyticsByPurpose(params),
    ]).then(([ovRes, volRes, docRes, statRes, procRes, peakRes, purpRes]) => {
      setOverview(ovRes.data);
      setVolumeData(volRes.data);
      setDocTypeData(docRes.data);
      setStatusData(statRes.data);
      setProcessingData(procRes.data);
      setPeakHoursData(peakRes.data);
      setPurposeData(purpRes.data);
    }).catch(err => console.error('Analytics fetch error:', err));
  }, [dateRange, customFrom, customTo, docType, buildParams]);

  // ── AI report handler ─────────────────────────────────────────────────

  const handleGenerateReport = async () => {
    setAiLoading(true);
    setAiError(null);
    try {
      const res = await postAnalyticsAiReport(buildParams());
      setAiNarrative(res.data.narrative);
      setAiGeneratedAt(res.data.generated_at);
    } catch (err) {
      setAiError(
        err.response?.data?.error ?? 'An unexpected error occurred. Please try again.'
      );
    } finally {
      setAiLoading(false);
    }
  };

  // ── Monthly export handler ─────────────────────────────────────────────
  const handleExportConfirm = async (startYM, endYM, selectedDocType = 'ALL', certType = null, options = {}) => {
    setExportLoading(true);
    setToastSuccess('');
    setToastError('');
    try {
      const { exportMonthlyDocx } = await import('../utils/analyticsMonthlyExport');
      const docTypeToSend = selectedDocType === 'All Documents' ? 'ALL' : selectedDocType;
      await exportMonthlyDocx(startYM, endYM, docTypeToSend, certType, options);
      setToastSuccess('Exported successfully! Check your downloads.');
    } catch (err) {
      console.error('Export failed', err);
      setToastError(err?.message || 'Failed to generate export.');
    } finally {
      setExportLoading(false);
      setExportModalOpen(false);
    }
  };

  // ── Derived pie stats ─────────────────────────────────────────────────

  const pieTotal     = statusData.reduce((s, r) => s + r.total, 0);
  const completedRow = statusData.find(r => r.status_name?.toLowerCase().includes('complet'));
  const successPct   = pieTotal > 0 && completedRow
    ? Math.round((completedRow.total / pieTotal) * 100)
    : 0;

  // ── Trend helper for StatCard ─────────────────────────────────────────

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

  // ─────────────────────────────────────────────────────────────────────

  return (
    <div className={`space-y-6 py-10 md:py-5 lg:py-5 min-h-screen font-sans ${isDark ? 'bg-[#18191a] text-[#e4e6eb]' : 'text-gray-900'}`}>

      {/* ── 1. FILTER BAR ── */}
      <div className="flex flex-col lg:flex-row items-start lg:items-end gap-3 w-full -mt-5">

        <div className="flex flex-col sm:flex-row items-start sm:items-end gap-3 w-full lg:w-auto lg:ml-auto">

          {/* Date Range */}
          <div className="w-full sm:w-44">
            <DropdownGroup
              name="dateRange"
              value={dateRange}
              onChange={e => setDateRange(e.target.value)}
              options={['Today','This Week','This Month','This Year','All Time','Custom Range']}
            />
          </div>

          {/* Custom date inputs — only when Custom Range selected */}
          {dateRange === 'Custom Range' && (
            <div className={`flex items-center gap-2 p-1.5 rounded-lg shadow-sm w-full sm:w-auto ${isDark ? 'bg-[#242526] border border-[#3e4042]' : 'bg-white border border-slate-200'}`}>
              <input
                type="date"
                value={customFrom}
                onChange={e => setCustomFrom(e.target.value)}
                className={`text-xs font-bold p-1.5 rounded-lg outline-none border transition-all ${isDark ? 'text-[#e4e6eb] bg-[#3a3b3c] border-[#4e4f50] focus:border-[#f5c542]' : 'text-slate-600 bg-slate-50 border-slate-100 focus:border-[#800000]'}`}
              />
              <div className={`w-2 h-px shrink-0 ${isDark ? 'bg-[#4e4f50]' : 'bg-slate-300'}`} />
              <input
                type="date"
                value={customTo}
                onChange={e => setCustomTo(e.target.value)}
                min={customFrom}
                className={`text-xs font-bold p-1.5 rounded-lg outline-none border transition-all ${isDark ? 'text-[#e4e6eb] bg-[#3a3b3c] border-[#4e4f50] focus:border-[#f5c542]' : 'text-slate-600 bg-slate-50 border-slate-100 focus:border-[#800000]'}`}
              />
            </div>
          )}

          {/* Export monthly report */}
          <div className="w-full sm:w-65">
            <button
              data-voice-action="export"
              onClick={() => setExportModalOpen(true)}
              className={`w-full flex items-center justify-center px-3 py-3 rounded-lg text-sm font-black uppercase tracking-wide shadow transition-colors ${isDark ? 'bg-[#3a3b3c] text-[#e4e6eb] hover:bg-[#4e4f50]' : 'bg-[#800000] text-white hover:bg-[#6b0000]'}`}
            >
              Export Report
            </button>
          </div>
        </div>
      </div>

      <MonthRangeModal
        isOpen={exportModalOpen}
        isDark={isDark}
        loading={exportLoading}
        onClose={() => setExportModalOpen(false)}
        onConfirm={handleExportConfirm}
        documentTypes={documentTypes}
      />

      {/* ── 2. KPI CARDS ── */}
      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
        {!overview
          ? Array.from({ length: 4 }).map((_, i) => <StatCardSkeleton key={i} isDark={isDark} />)
          : <>
              <StatCard
                title="Total Requests"
                value={overview.total.toLocaleString()}
                trend={trend.label}
                status={trend.status}
                icon={<DocumentTextIcon className="w-6 h-6" />}
                lightColor="bg-red-50" iconColor="text-[#800000]"
                isDark={isDark}
              />
              <StatCard
                title="Pending Review"
                value={overview.pending.toLocaleString()}
                trend="Awaiting processing"
                status="neutral"
                icon={<BellAlertIcon className="w-6 h-6" />}
                lightColor="bg-amber-50" iconColor="text-amber-700"
                isDark={isDark}
              />
              <StatCard
                title="Claimed Docs"
                value={overview.completed.toLocaleString()}
                trend={overview?.completion_rate != null ? `${overview.completion_rate}% completion rate` : '—'}
                status={overview?.completion_rate >= 70 ? 'up' : 'down'}
                icon={<CheckCircleIcon className="w-6 h-6" />}
                lightColor="bg-blue-50" iconColor="text-blue-700"
                isDark={isDark}
              />
              <StatCard
                title="Forfeited"
                value={overview.forfeited.toLocaleString()}
                trend={overview?.forfeit_rate != null ? `${overview.forfeit_rate}% forfeit rate` : '—'}
                status={overview?.forfeit_rate > 10 ? 'up' : 'neutral'}
                icon={<ClockIcon className="w-6 h-6" />}
                lightColor="bg-emerald-50" iconColor="text-emerald-700"
                isDark={isDark}
              />
            </>
        }
      </div>

      <div className="h-1.5 w-full bg-linear-to-r from-[#FFD700] via-[#FACC15] to-[#FFD700] rounded-full opacity-40 shadow-sm" />

      {/* ── 3. MAIN CHARTS ROW ── */}
      <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
        {volumeData.length === 0 && docTypeData.length === 0 && statusData.length === 0
          ? Array.from({ length: 3 }).map((_, i) => <ChartCardSkeleton key={i} isDark={isDark} />)
          : <>
              {/* Request Volume */}
              <div className={`border p-6 rounded-4xl shadow-sm min-w-0 ${isDark ? 'border-[#3e4042] bg-[#242526]' : 'border-slate-200 bg-white'}`}>
                <ChartHeader title="Request Volume" sub="Monthly Growth" isDark={isDark} />
                <div className="h-64">
                  <ResponsiveContainer width="100%" height={256}>
                    <AreaChart data={volumeData}>
                      <defs>
                        <linearGradient id="colorMaroon" x1="0" y1="0" x2="0" y2="1">
                          <stop offset="5%"  stopColor="#800000" stopOpacity={0.1} />
                          <stop offset="95%" stopColor="#800000" stopOpacity={0}   />
                        </linearGradient>
                      </defs>
                      <CartesianGrid strokeDasharray="3 3" vertical={false} stroke={isDark ? '#3e4042' : '#f1f5f9'} />
                      <XAxis dataKey="label" tick={{ fontSize: 11, fontWeight: 600, fill: isDark ? '#b0b3b8' : '#64748b' }} axisLine={false} tickLine={false} />
                      <YAxis tick={{ fontSize: 11, fill: isDark ? '#b0b3b8' : '#64748b' }} axisLine={false} tickLine={false} />
                      <Tooltip content={(props) => <CustomTooltip {...props} isDark={isDark} unit="Requests" />} />
                      <Area type="monotone" dataKey="total" stroke="#800000" strokeWidth={3} fillOpacity={1} fill="url(#colorMaroon)" />
                    </AreaChart>
                  </ResponsiveContainer>
                </div>
              </div>

              {/* Top Documents */}
              <div className={`border p-6 rounded-4xl shadow-sm min-w-0 ${isDark ? 'border-[#3e4042] bg-[#242526]' : 'border-slate-200 bg-white'}`}>
                <ChartHeader title="Top Documents" sub="Most Requested" isDark={isDark} />
                <div className="h-64">
                  <ResponsiveContainer width="100%" height={256}>
                    <BarChart data={docTypeData.slice(0, 6)} layout="vertical" barSize={18}>
                      <CartesianGrid strokeDasharray="3 3" horizontal={false} stroke={isDark ? '#3e4042' : '#f1f5f9'} />
                      <XAxis type="number" tick={{ fontSize: 11, fill: isDark ? '#b0b3b8' : '#64748b' }} axisLine={false} tickLine={false} />
                      <YAxis
                        type="category"
                        dataKey="document_name"
                        tick={{ fontSize: 10, fontWeight: 600, fill: isDark ? '#b0b3b8' : '#64748b' }}
                        axisLine={false}
                        tickLine={false}
                        width={110}
                        tickFormatter={(name) => name?.length > 15 ? `${name.slice(0, 14)}…` : name}
                      />
                      <Tooltip content={(props) => <CustomTooltip {...props} isDark={isDark} unit="Documents" />} cursor={{ fill: isDark ? 'rgba(255,255,255,0.05)' : 'rgba(0,0,0,0.03)' }} />
                      <Bar dataKey="total_documents" radius={[0, 6, 6, 0]}>
                        {docTypeData.slice(0, 6).map((_, i) => (
                          <Cell key={i} fill={DOC_COLORS[i % DOC_COLORS.length]} />
                        ))}
                      </Bar>
                    </BarChart>
                  </ResponsiveContainer>
                </div>
              </div>

              {/* Request Status Donut */}
              <div className={`border p-6 rounded-4xl shadow-sm flex flex-col min-w-0 ${isDark ? 'border-[#3e4042] bg-[#242526]' : 'border-slate-200 bg-white'}`}>
                <ChartHeader title="Request Status" sub="Distribution Breakdown" isDark={isDark} />
                <div className="h-64 relative">
                  <ResponsiveContainer width="100%" height={256}>
                    <PieChart>
                      <Pie data={statusData} dataKey="total" nameKey="status_name"
                        innerRadius={70} outerRadius={90} paddingAngle={8} stroke="none">
                        {statusData.map((_, i) => (
                          <Cell key={i} fill={PIE_COLORS[i % PIE_COLORS.length]} cornerRadius={10} />
                        ))}
                      </Pie>
                      <Tooltip position={{ y: 0 }} content={(props) => <CustomTooltip {...props} isDark={isDark} unit="Requests" />} />
                    </PieChart>
                  </ResponsiveContainer>
                  <div className="absolute inset-0 flex flex-col items-center justify-center pointer-events-none">
                    <span className={`text-3xl font-black ${isDark ? 'text-[#e4e6eb]' : 'text-slate-800'}`}>{successPct}%</span>
                    <span className={`text-[10px] font-bold uppercase tracking-tighter ${isDark ? 'text-[#9a9a9a]' : 'text-slate-400'}`}>Success</span>
                  </div>
                </div>
                <div className="mt-4 flex flex-wrap justify-center gap-x-6 gap-y-2">
                  {statusData.map((row, i) => (
                    <div key={row.status_id} className="flex items-center gap-2">
                      <div className="w-2.5 h-2.5 rounded-full" style={{ backgroundColor: PIE_COLORS[i % PIE_COLORS.length] }} />
                      <span className={`text-[10px] font-black uppercase tracking-widest ${isDark ? 'text-[#9a9a9a]' : 'text-slate-500'}`}>{row.status_name}</span>
                    </div>
                  ))}
                </div>
              </div>
            </>
        }
      </div>

      {/* ── 4. SECOND CHARTS ROW ── */}
      <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
        {peakHoursData.length === 0 && purposeData.length === 0 && (processingData.by_document_type ?? []).length === 0
          ? Array.from({ length: 3 }).map((_, i) => <ChartCardSkeleton key={i} isDark={isDark} />)
          : <>
              {/* Peak Hours Heatmap */}
              <div className={`border p-6 rounded-4xl shadow-sm min-w-0 ${isDark ? 'border-[#3e4042] bg-[#242526]' : 'border-slate-200 bg-white'}`}>
                <ChartHeader title="Peak Hours" sub="Requests by Hour of Day" isDark={isDark} />
                <div className="h-64">
                  <ResponsiveContainer width="100%" height={256}>
                    <BarChart data={peakHoursData} barSize={14}>
                      <CartesianGrid strokeDasharray="3 3" vertical={false} stroke={isDark ? '#3e4042' : '#f1f5f9'} />
                      <XAxis dataKey="label" tick={{ fontSize: 9, fontWeight: 600, fill: isDark ? '#b0b3b8' : '#64748b' }} axisLine={false} tickLine={false}
                        interval={3} />
                      <YAxis tick={{ fontSize: 11, fill: isDark ? '#b0b3b8' : '#64748b' }} axisLine={false} tickLine={false} />
                      <Tooltip content={(props) => <CustomTooltip {...props} isDark={isDark} unit="Requests" />} cursor={{ fill: isDark ? 'rgba(255,255,255,0.05)' : 'rgba(0,0,0,0.03)' }} />
                      <Bar dataKey="total" fill={HOUR_COLOR} radius={[4, 4, 0, 0]} />
                    </BarChart>
                  </ResponsiveContainer>
                </div>
              </div>

              {/* Requests by Purpose */}
              <div className={`border p-6 rounded-4xl shadow-sm min-w-0 ${isDark ? 'border-[#3e4042] bg-[#242526]' : 'border-slate-200 bg-white'}`}>
                <ChartHeader title="By Purpose" sub="Request Reason Breakdown" isDark={isDark} />
                <div className="h-64">
                  <ResponsiveContainer width="100%" height={256}>
                    <BarChart data={purposeData} layout="vertical" barSize={18}>
                      <CartesianGrid strokeDasharray="3 3" horizontal={false} stroke={isDark ? '#3e4042' : '#f1f5f9'} />
                      <XAxis type="number" tick={{ fontSize: 11, fill: isDark ? '#b0b3b8' : '#64748b' }} axisLine={false} tickLine={false} />
                      <YAxis type="category" dataKey="purpose_name" tick={{ fontSize: 11, fontWeight: 600, fill: isDark ? '#b0b3b8' : '#64748b' }}
                        axisLine={false} tickLine={false} width={110} />
                      <Tooltip content={(props) => <CustomTooltip {...props} isDark={isDark} unit="Requests" />} cursor={{ fill: isDark ? 'rgba(255,255,255,0.05)' : 'rgba(0,0,0,0.03)' }} />
                      <Bar dataKey="total" radius={[0, 6, 6, 0]}>
                        {purposeData.map((_, i) => (
                          <Cell key={i} fill={DOC_COLORS[i % DOC_COLORS.length]} />
                        ))}
                      </Bar>
                    </BarChart>
                  </ResponsiveContainer>
                </div>
              </div>

              {/* Processing Time by Doc Type */}
              <div className={`border p-6 rounded-4xl shadow-sm min-w-0 ${isDark ? 'border-[#3e4042] bg-[#242526]' : 'border-slate-200 bg-white'}`}>
                <ChartHeader title="Processing Time" sub="Avg Minutes by Document Type" isDark={isDark} />
                <div className="h-64 min-w-0">
                  <ResponsiveContainer width="100%" height={256}>
                    <BarChart data={processingData.by_document_type ?? []} layout="vertical" barSize={18}>
                      <CartesianGrid strokeDasharray="3 3" horizontal={false} stroke={isDark ? '#3e4042' : '#f1f5f9'} />
                      <XAxis type="number" tick={{ fontSize: 11, fill: isDark ? '#b0b3b8' : '#64748b' }} axisLine={false} tickLine={false} unit="m" />
                      <YAxis
                        type="category"
                        dataKey="document_name"
                        tick={{ fontSize: 10, fontWeight: 600, fill: isDark ? '#b0b3b8' : '#64748b' }}
                        axisLine={false}
                        tickLine={false}
                        width={110}
                        tickFormatter={(name) => name?.length > 15 ? `${name.slice(0, 14)}…` : name}
                      />
                      <Tooltip
                        content={(props) => <CustomTooltip {...props} isDark={isDark} valueFormatter={(val) => `${val} min`} />}
                        cursor={{ fill: isDark ? 'rgba(255,255,255,0.05)' : 'rgba(0,0,0,0.03)' }}
                      />
                      <Bar dataKey="avg_minutes" radius={[0, 6, 6, 0]}>
                        {(processingData.by_document_type ?? []).map((_, i) => (
                          <Cell key={i} fill={DOC_COLORS[i % DOC_COLORS.length]} />
                        ))}
                      </Bar>
                    </BarChart>
                  </ResponsiveContainer>
                </div>
              </div>
            </>
        }
      </div>

      {/* ── 5. ADMIN PROCESSING LEADERBOARD ── */}
      {(processingData.by_admin ?? []).length > 0 && (
        <div className={`border p-6 rounded-4xl shadow-sm ${isDark ? 'border-[#3e4042] bg-[#242526]' : 'border-slate-200 bg-white'}`}>
          <ChartHeader title="Staff Performance" sub="Click a column to sort" isDark={isDark} />
          <div className="mt-4 overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className={`text-center text-[10px] font-black uppercase tracking-widest border-b ${isDark ? 'text-[#9a9a9a] border-[#3e4042]' : 'text-slate-400 border-slate-100'}`}>
                  <SortableTh label="Staff Member" sortKey="display_name" staffSort={staffSort} setStaffSort={setStaffSort} isDark={isDark} align="center" />
                  <SortableTh label="Requests" sortKey="requests_handled" staffSort={staffSort} setStaffSort={setStaffSort} isDark={isDark} align="center" />
                  <SortableTh label="Rate / Active Day" sortKey="requests_per_active_day" staffSort={staffSort} setStaffSort={setStaffSort} isDark={isDark} align="center" />
                  <SortableTh label="Min / Max" sortKey="min_minutes" staffSort={staffSort} setStaffSort={setStaffSort} isDark={isDark} align="center" />
                  <SortableTh label="Avg Processing Time" sortKey="avg_minutes" staffSort={staffSort} setStaffSort={setStaffSort} isDark={isDark} align="center" />
                  <SortableTh label="Forfeit Rate" sortKey="forfeit_rate" staffSort={staffSort} setStaffSort={setStaffSort} isDark={isDark} align="center" />
                </tr>
              </thead>
              <tbody>
                {sortStaffRows(processingData.by_admin ?? [], staffSort).map((row, i) => (
                  <tr key={row.user_id ?? i} className={`border-b transition-colors ${isDark ? 'border-[#3e4042] hover:bg-[#3a3b3c]' : 'border-slate-50 hover:bg-slate-50'}`}>
                    <td className={`py-3 px-3 text-center font-bold ${isDark ? 'text-[#e4e6eb]' : 'text-slate-700'}`}>
                      {row.display_name?.trim() || row.email || 'Unknown'}
                    </td>
                    <td className={`py-3 px-3 text-center font-bold ${isDark ? 'text-[#b0b3b8]' : 'text-slate-500'}`}>
                      {row.requests_handled}
                      <span className={`block text-[10px] font-normal ${isDark ? 'text-[#8a8d91]' : 'text-slate-400'}`}>
                        {row.sample_count} sample{row.sample_count === 1 ? '' : 's'}
                      </span>
                    </td>
                    <td className={`py-3 px-3 text-center font-bold ${isDark ? 'text-[#b0b3b8]' : 'text-slate-500'}`}>
                      {row.requests_per_active_day != null ? `${row.requests_per_active_day}/day` : '—'}
                      <span className={`block text-[10px] font-normal ${isDark ? 'text-[#8a8d91]' : 'text-slate-400'}`}>
                        {row.active_days} active day{row.active_days === 1 ? '' : 's'}
                      </span>
                    </td>
                    <td className={`py-3 px-3 text-center font-bold ${isDark ? 'text-[#b0b3b8]' : 'text-slate-500'}`}>
                      {row.min_minutes}–{row.max_minutes} min
                    </td>
                    <td className="py-3 px-3 text-center">
                      <span className={`px-2.5 py-1 rounded-full text-xs font-black inline-block ${
                        row.avg_minutes <= 30
                          ? 'bg-emerald-100 text-emerald-700'
                          : row.avg_minutes <= 60
                          ? 'bg-amber-100 text-amber-700'
                          : 'bg-red-100 text-red-700'
                      }`}>
                        {row.avg_minutes} min
                      </span>
                    </td>
                    <td className="py-3 px-3 text-center">
                      {/*
                        Rework/quality signal (Step 1c) — count of requests
                        this admin touched that ended up Forfeited, as a
                        percentage of what they handled. Deliberately a
                        neutral gray badge, not red/amber/green like the
                        speed badge above: forfeiture is frequently outside
                        staff control (a student simply never returns), so
                        this is presented as a data point to investigate,
                        not a performance verdict.
                      */}
                      <span className={`px-2.5 py-1 rounded-full text-xs font-black inline-block ${isDark ? 'bg-[#3a3b3c] text-[#b0b3b8]' : 'bg-slate-100 text-slate-500'}`}>
                        {row.forfeit_rate}%
                      </span>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {/* ── 6/7. RETIRED AI NARRATIVE + CHAT — hidden unless flag is on ── */}
      {aiLegacyEnabled && (
        <>
          <AIInsightCard
            narrative={aiNarrative}
            loading={aiLoading}
            error={aiError}
            onGenerate={handleGenerateReport}
            generatedAt={aiGeneratedAt}
          />
          <AIQueryChat buildParams={buildParams} />
        </>
      )}
      <SuccessToast message={toastSuccess} onClose={() => setToastSuccess('')} />
      <ErrorToast message={toastError} onClose={() => setToastError('')} />

    </div>
  );
};

// ─── Sub-components ───────────────────────────────────────────────────────

const CustomTooltip = ({ active, payload, label, isDark, unit = 'Requests', valueFormatter }) => {
  if (!active || !payload || !payload.length) return null;

  const item = payload[0];
  let title = label;
  if (!title) {
    title = item.name || item.payload?.status_name || item.payload?.document_name || item.payload?.purpose_name;
  }

  const val = item.value;
  const formattedVal = valueFormatter
    ? valueFormatter(val)
    : `${val} ${val === 1 && unit === 'Requests' ? 'Request' : unit}`;

  const dotColor = item.color || item.fill || item.payload?.fill;

  return (
    <div
      className={`px-3.5 py-2.5 rounded-2xl text-xs border transition-all pointer-events-none ${
        isDark
          ? 'bg-[#242526] border-[#3e4042] text-[#e4e6eb]'
          : 'bg-white border-slate-200/90 text-slate-800'
      }`}
      style={{
        boxShadow: isDark
          ? '0 10px 25px -5px rgba(0, 0, 0, 0.6), 0 8px 10px -6px rgba(0, 0, 0, 0.6)'
          : '0 10px 25px -5px rgba(0, 0, 0, 0.12), 0 4px 10px -2px rgba(0, 0, 0, 0.08)',
      }}
    >
      {title && (
        <div className={`font-bold mb-1 text-[10px] uppercase tracking-wider ${isDark ? 'text-[#9a9a9a]' : 'text-slate-400'}`}>
          {title}
        </div>
      )}
      <div className="flex items-center gap-2 font-extrabold text-sm">
        {dotColor && dotColor !== 'none' && (
          <div className="w-2.5 h-2.5 rounded-full shrink-0 shadow-xs" style={{ backgroundColor: dotColor }} />
        )}
        <span>{formattedVal}</span>
      </div>
    </div>
  );
};

const ChartHeader = ({ title, sub, isDark }) => (
  <div className="mb-4">
    <h2 className={`text-xl font-black uppercase tracking-tight ${isDark ? 'text-white' : 'text-[#800000]'}`}>{title}</h2>
    <p className={`text-[10px] font-bold uppercase tracking-widest ${isDark ? 'text-[#9a9a9a]' : 'text-slate-400'}`}>{sub}</p>
  </div>
);

/**
 * Clickable table header cell for the Staff Performance table. Clicking a
 * column that's already the active sort flips direction; clicking a new
 * column sorts by it ascending. `align` mirrors the corresponding <td>'s
 * text alignment so the sort caret lines up with the values it sorts.
 */
const SortableTh = ({ label, sortKey, staffSort, setStaffSort, isDark, align = 'center' }) => {
  const active = staffSort.key === sortKey;
  const alignClass = align === 'center' ? 'text-center' : align === 'right' ? 'text-right' : 'text-left';
  return (
    <th
      className={`pb-3 px-3 select-none cursor-pointer group transition-colors ${alignClass} ${
        active
          ? isDark ? 'text-[#e4e6eb]' : 'text-[#800000]'
          : isDark ? 'text-[#9a9a9a] hover:text-[#e4e6eb]' : 'text-slate-400 hover:text-slate-700'
      }`}
      onClick={() => setStaffSort((prev) =>
        prev.key === sortKey
          ? { key: sortKey, dir: prev.dir === 'asc' ? 'desc' : 'asc' }
          : { key: sortKey, dir: 'asc' }
      )}
      title={`Sort by ${label}`}
    >
      <span className="inline-flex items-center justify-center gap-1">
        <span>{label}</span>
        {active ? (
          <span className={`text-[10px] font-black ${isDark ? 'text-[#e4e6eb]' : 'text-[#800000]'}`}>
            {staffSort.dir === 'asc' ? '▲' : '▼'}
          </span>
        ) : (
          <span className={`text-[9px] opacity-35 group-hover:opacity-75 transition-opacity ${isDark ? 'text-[#9a9a9a]' : 'text-slate-400'}`}>
            ▲
          </span>
        )}
      </span>
    </th>
  );
};

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

export default AnalyticsDashboard;