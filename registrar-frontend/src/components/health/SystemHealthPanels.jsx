import React, { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import {
  LineChart, Line, XAxis, YAxis, CartesianGrid, Tooltip, ResponsiveContainer, ReferenceLine, Legend,
} from 'recharts';
import {
  BellAlertIcon, CheckIcon, CreditCardIcon, ServerIcon, UserGroupIcon,
} from '@heroicons/react/24/outline';
import { useAuth } from '../../context/AuthProvider';
import {
  getSystemAlerts, acknowledgeSystemAlert, getCashierTrend, getProvisioningHealth,
} from '../../services/api';
import DropdownGroup from '../DropDown';

/**
 * System Health panels (Super Admin, system-analytics page).
 *
 * Every panel reads pre-aggregated rollups or alerts only; none of these
 * endpoints returns names, emails or OR numbers. The panels render only
 * when the backend reports FEATURE_SYSTEM_HEALTH on (user.features); that
 * is a UX convenience, the routes themselves return 404 when it is off.
 */

export const useSystemHealthEnabled = () => {
  const auth = useAuth();
  // Strict === true so a missing flag fails closed.
  return auth?.user?.features?.system_health === true;
};

const RANGES = [
  { value: 7, label: '7 days' },
  { value: 30, label: '30 days' },
  { value: 90, label: '90 days' },
];

const REASON_LABELS = {
  NOT_FOUND: 'OR not found',
  API_ERROR: 'Cashier API error',
  UNKNOWN: 'Unknown (before reason tracking)',
  ogos_not_found: 'OGOS: no such student',
  ogos_unreachable: 'OGOS unreachable',
  ogos_personal_info_unavailable: 'OGOS: personal info unavailable',
  ogos_addresses_unavailable: 'OGOS: addresses unavailable',
  alumni_lookup_failed: 'PUPTAPS: alumni lookup failed',
};
const labelFor = (key) => REASON_LABELS[key] ?? String(key).replace(/_/g, ' ');

const SEVERITY_STYLES = {
  critical: 'bg-rose-50 text-rose-700 border-rose-200',
  warning: 'bg-amber-50 text-amber-700 border-amber-200',
};

const errorMessage = (err, fallback) => err?.response?.data?.message || fallback;

const Card = ({ title, sub, icon, action, isDark, children }) => (
  <div className={`border p-6 rounded-4xl shadow-sm min-w-0 ${isDark ? 'border-[#3e4042] bg-[#242526]' : 'border-slate-200 bg-white'}`}>
    <div className="flex items-center justify-between gap-3 mb-4">
      <div className="flex items-center gap-2 min-w-0">
        <span className={isDark ? 'text-amber-400' : 'text-[#800000]'}>{icon}</span>
        <div className="min-w-0">
          <h3 className={`text-sm font-black uppercase tracking-tight ${isDark ? 'text-[#e4e6eb]' : 'text-slate-800'}`}>{title}</h3>
          {sub && <p className={`text-[10px] font-bold uppercase tracking-widest ${isDark ? 'text-[#9a9a9a]' : 'text-slate-400'}`}>{sub}</p>}
        </div>
      </div>
      {action}
    </div>
    {children}
  </div>
);

const Muted = ({ isDark, children }) => (
  <p className={`text-xs italic ${isDark ? 'text-[#7a7a7a]' : 'text-slate-400'}`}>{children}</p>
);

const ErrorLine = ({ isDark, children }) => (
  <p className={`text-xs font-bold ${isDark ? 'text-rose-300' : 'text-rose-700'}`}>{children}</p>
);

const RangeSelect = ({ value, onChange, isDark }) => {
  const current = RANGES.find((r) => r.value === value)?.label || `${value} days`;
  return (
    <div className="w-36">
      <DropdownGroup
        name="trendRange"
        value={current}
        onChange={(e) => {
          const found = RANGES.find((r) => r.label === e.target.value);
          if (found) onChange(found.value);
        }}
        options={RANGES.map((r) => r.label)}
        isDark={isDark}
      />
    </div>
  );
};

/** Small loader: returns { data, loading, error, reload }. */
const useLoad = (fetcher, deps) => {
  const [state, setState] = useState({ data: null, loading: true, error: null });
  const [tick, setTick] = useState(0);

  useEffect(() => {
    let cancelled = false;
    setState((s) => ({ ...s, loading: true, error: null }));
    fetcher()
      .then((res) => { if (!cancelled) setState({ data: res.data?.data ?? null, loading: false, error: null }); })
      .catch((err) => { if (!cancelled) setState({ data: null, loading: false, error: errorMessage(err, 'Could not load this panel.') }); });
    return () => { cancelled = true; };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [...deps, tick]);

  return { ...state, reload: useCallback(() => setTick((t) => t + 1), []) };
};

const formatDateLabel = (dateStr) => {
  if (!dateStr) return '';
  const d = new Date(dateStr);
  if (isNaN(d.getTime())) return dateStr;
  return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
};

const TrendTooltip = ({ active, payload, label, anomalyDates, isDark }) => {
  if (!active || !payload || !payload.length) return null;
  const isAnomaly = (anomalyDates ?? []).includes(label);
  const formattedDate = formatDateLabel(label);

  const attempts = payload.find((p) => p.dataKey === 'attempts')?.value;
  const failures = payload.find((p) => p.dataKey === 'failures')?.value;

  return (
    <div
      className={`px-3.5 py-2 rounded-xl shadow-md border text-xs ${isDark ? 'bg-[#242526] border-[#3e4042] text-gray-200' : 'bg-white border-gray-200 text-gray-800'
        }`}
    >
      <div className={`font-bold leading-tight ${isAnomaly ? (isDark ? 'text-rose-400' : 'text-[#800000]') : (isDark ? 'text-gray-200' : 'text-gray-900')}`}>
        {isAnomaly ? `Alert raised · ${formattedDate}` : formattedDate}
      </div>
      <div className={`mt-0.5 font-medium ${isDark ? 'text-gray-300' : 'text-gray-600'}`}>
        {attempts != null && `${attempts} attempts`}
        {attempts != null && failures != null && ' · '}
        {failures != null && (
          <span className={failures > 0 ? (isDark ? 'text-rose-400 font-semibold' : 'text-[#800000] font-semibold') : ''}>
            {failures} failures
          </span>
        )}
        {attempts == null && failures == null && payload.map((p) => `${p.name}: ${p.value}`).join(' · ')}
      </div>
    </div>
  );
};

const CustomLegend = ({ payload, hasAnomaly, isDark }) => (
  <div className="flex items-center justify-center gap-6 mt-3 text-xs">
    {payload?.map((entry) => (
      <div key={entry.value} className="flex items-center gap-2">
        <span
          className="inline-block w-4 h-0.5 rounded-full"
          style={{ backgroundColor: entry.color }}
        />
        <span className={isDark ? 'text-gray-300' : 'text-gray-700'}>{entry.value}</span>
      </div>
    ))}
    {hasAnomaly && (
      <div className="flex items-center gap-2">
        <span className="inline-block border-l-2 border-dashed border-[#800000] dark:border-rose-400 h-3.5" />
        <span className={isDark ? 'text-gray-300' : 'text-gray-700'}>Day an alert was raised</span>
      </div>
    )}
  </div>
);

const TrendChart = ({ series, lines, anomalyDates = [], isDark, yFormatter }) => {
  const grid = isDark ? '#3e4042' : '#e2e8f0';
  const text = isDark ? '#9a9a9a' : '#94a3b8';
  const hasAnomaly = (anomalyDates ?? []).length > 0;

  return (
    <div className="w-full h-64" role="img" aria-label="Daily trend chart">
      <ResponsiveContainer width="100%" height="100%">
        <LineChart data={series} margin={{ top: 8, right: 12, left: -12, bottom: 0 }}>
          <CartesianGrid strokeDasharray="3 3" stroke={grid} />
          <XAxis dataKey="date" tick={{ fontSize: 10, fill: text }} tickFormatter={(d) => d.slice(5)} minTickGap={24} />
          <YAxis tick={{ fontSize: 10, fill: text }} allowDecimals={false} tickFormatter={yFormatter} />
          <Tooltip content={<TrendTooltip anomalyDates={anomalyDates} isDark={isDark} />} />
          <Legend content={<CustomLegend hasAnomaly={hasAnomaly} isDark={isDark} />} />
          {anomalyDates.map((d) => (
            <ReferenceLine
              key={d}
              x={d}
              stroke={isDark ? '#fb7185' : '#800000'}
              strokeDasharray="4 3"
              strokeWidth={1.5}
            />
          ))}
          {lines.map((l) => (
            <Line key={l.key} type="monotone" dataKey={l.key} name={l.name} stroke={l.color} strokeWidth={2} dot={false} connectNulls={false} />
          ))}
        </LineChart>
      </ResponsiveContainer>
    </div>
  );
};

const Breakdown = ({ title, items, isDark, empty }) => {
  const max = Math.max(1, ...items.map((i) => i.count));
  const total = items.reduce((sum, i) => sum + i.count, 0);
  return (
    <div>
      <p className={`text-[10px] font-bold uppercase tracking-wider mb-2 ${isDark ? 'text-[#9a9a9a]' : 'text-slate-400'}`}>{title}</p>
      {items.length === 0 ? <Muted isDark={isDark}>{empty}</Muted> : (
        <ul className="space-y-1.5">
          {items.map((i) => {
            const pct = total > 0 ? Math.round((i.count / total) * 100) : 0;
            return (
              <li key={i.key} className="text-xs">
                <div className={`flex justify-between items-center gap-2 ${isDark ? 'text-gray-200' : 'text-gray-700'}`}>
                  <span className="truncate font-medium">{labelFor(i.key)}</span>
                  <span className="shrink-0 text-right">
                    <strong className="font-bold">{i.count.toLocaleString()}</strong>{' '}
                    <span className={`text-[11px] ${isDark ? 'text-gray-400' : 'text-gray-500'}`}>· {pct}%</span>
                  </span>
                </div>
                <div className={`h-1.5 rounded-full mt-1 overflow-hidden ${isDark ? 'bg-zinc-800' : 'bg-gray-100'}`}>
                  <div className="h-full rounded-full bg-[#800000] dark:bg-amber-400" style={{ width: `${(i.count / max) * 100}%` }} />
                </div>
              </li>
            );
          })}
        </ul>
      )}
    </div>
  );
};

// ── Alerts feed ───────────────────────────────────────────────────────────

const alertSummary = (a) => {
  if (a.type === 'repeat_failure') {
    // The API resolves the account into a masked label (a***@host) and role;
    // the raw user id is never shown. A deleted account has no label.
    const acct = a.account ?? {};
    const who = acct.label
      ? `${acct.label}${acct.role ? ` · ${acct.role}` : ''}`
      : (acct.exists === false ? 'account no longer exists' : 'one account');
    return `Repeat failures for ${who}: ${a.observed} in the window`;
  }
  const typical = a.baseline_median != null ? ` (typical ${a.baseline_median})` : '';
  const what = a.type === 'rate_spike'
    ? `${labelFor(a.metric)} rate ${a.observed != null ? `${Math.round(a.observed * 100)}%` : ''}`
    : `${a.metric.replace(/_/g, ' ')}${a.dimension ? ` · ${labelFor(a.dimension)}` : ''}: ${a.observed}`;
  return `${what}${typical}`;
};

const alertLink = (a) => {
  if (a.type === 'repeat_failure') {
    const uid = a.account?.user_id;
    // Deep-link to just this account's failures (FailedVerificationsPanel
    // reads ?user=). The id only travels in the Super Admin's own URL.
    return {
      to: `/super-admin/cashier-reconciliation?tab=failed${uid ? `&user=${encodeURIComponent(uid)}` : ''}`,
      label: 'Open failed verifications',
    };
  }
  if (a.source === 'provisioning') return { to: '/super-admin/system-analytics?tab=provisioning', label: 'View provisioning' };
  return { to: '/super-admin/system-analytics?tab=cashier', label: 'View cashier trend' };
};

const formatAlertDate = (dateStr) => {
  if (!dateStr) return '—';
  try {
    const d = new Date(dateStr);
    return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
  } catch {
    return dateStr;
  }
};

const getAlertDetails = (a) => {
  if (a.type === 'repeat_failure') {
    const acct = a.account ?? {};
    const who = acct.label
      ? `${acct.label}${acct.role ? ` · ${acct.role}` : ''}`
      : (acct.exists === false ? 'account no longer exists' : 'One account');
    return {
      title: 'Repeat failures',
      subtitle: who,
    };
  }

  if (a.type === 'rate_spike') {
    return {
      title: 'Failure rate spike',
      subtitle: a.dimension ? labelFor(a.dimension) : labelFor(a.metric),
    };
  }

  // Count spike: metric (e.g. failures_by_reason -> Failures by reason, failures -> Total failures or Failures)
  let title = a.metric === 'failures_by_reason'
    ? 'Failures by reason'
    : a.metric === 'failures' && !a.dimension
      ? 'Total failures'
      : a.metric === 'failures'
        ? 'Failures'
        : String(a.metric).replace(/_/g, ' ');

  // Capitalize title
  title = title.charAt(0).toUpperCase() + title.slice(1);

  const subtitle = a.dimension
    ? labelFor(a.dimension)
    : a.metric === 'failures'
      ? 'All reasons'
      : '';

  return { title, subtitle };
};

export const AlertsFeed = ({ isDark, type = null, title = 'Open alerts', limit = 10 }) => {
  const { data, loading, error, reload } = useLoad(
    () => getSystemAlerts({ status: 'open', per_page: limit, ...(type ? { type } : {}) }),
    [type, limit],
  );
  const [busyId, setBusyId] = useState(null);
  const [actionError, setActionError] = useState(null);

  const acknowledge = async (id) => {
    setBusyId(id);
    setActionError(null);
    try {
      await acknowledgeSystemAlert(id);
    } catch (err) {
      // 409 = someone else already acknowledged it; refresh either way.
      if (err?.response?.status !== 409) setActionError(errorMessage(err, 'Could not acknowledge this alert.'));
    } finally {
      setBusyId(null);
      reload();
    }
  };

  const alerts = Array.isArray(data) ? data : [];

  return (
    <Card isDark={isDark} title={title} sub="Unusual activity detected from daily rollups" icon={<BellAlertIcon className="w-5 h-5" />}>
      {loading && <Muted isDark={isDark}>Loading…</Muted>}
      {error && <ErrorLine isDark={isDark}>{error}</ErrorLine>}
      {actionError && <ErrorLine isDark={isDark}>{actionError}</ErrorLine>}
      {!loading && !error && alerts.length === 0 && <Muted isDark={isDark}>No open alerts. Nothing unusual detected.</Muted>}

      {alerts.length > 0 && (
        <div className="overflow-x-auto -mx-6 -mb-6 mt-1">
          <table className="w-full text-left border-collapse">
            <thead>
              <tr className={`border-b ${isDark ? 'border-[#3e4042] text-gray-400' : 'border-gray-100 text-gray-400'} text-[10px] font-bold uppercase tracking-wider`}>
                <th className="py-2.5 px-5">SEVERITY</th>
                <th className="py-2.5 px-3">ALERT</th>
                <th className="py-2.5 px-3">OBSERVED</th>
                <th className="py-2.5 px-3">TYPICAL</th>
                <th className="py-2.5 px-3">DATE</th>
                <th className="py-2.5 px-3">INVESTIGATE</th>
                <th className="py-2.5 px-5 text-right">ACTION</th>
              </tr>
            </thead>
            <tbody className={`divide-y ${isDark ? 'divide-[#3e4042]' : 'divide-gray-100'}`}>
              {alerts.map((a) => {
                const link = alertLink(a);
                const { title: alertTitle, subtitle: alertSub } = getAlertDetails(a);
                const isCritical = a.severity === 'critical';
                const observedDisplay = a.type === 'rate_spike'
                  ? (a.observed != null ? `${Math.round(a.observed * 100)}%` : '—')
                  : (a.observed ?? '—');
                const typicalDisplay = a.baseline_median != null
                  ? (a.type === 'rate_spike' ? `${Math.round(a.baseline_median * 100)}%` : a.baseline_median)
                  : '0';

                return (
                  <tr
                    key={a.id}
                    className={`transition-colors ${isDark ? 'hover:bg-[#2c2d2e]' : 'hover:bg-gray-50/70'}`}
                  >
                    {/* SEVERITY */}
                    <td className="py-3 px-5 whitespace-nowrap">
                      {isCritical ? (
                        <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] font-bold tracking-wide uppercase bg-[#fde8e8] text-[#991b1b] dark:bg-rose-950/40 dark:text-rose-300">
                          <span className="inline-flex items-center justify-center w-3 h-3 rounded-full bg-[#991b1b] text-white dark:bg-rose-400 dark:text-rose-950 text-[8px] font-black leading-none">!</span>
                          CRITICAL
                        </span>
                      ) : (
                        <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] font-bold tracking-wide uppercase bg-[#fef3c7] text-[#92400e] dark:bg-amber-950/40 dark:text-amber-300">
                          <span className="text-[10px] leading-none">▲</span>
                          WARNING
                        </span>
                      )}
                    </td>

                    {/* ALERT */}
                    <td className="py-3 px-3 min-w-[140px]">
                      <div className={`text-xs font-bold leading-tight ${isDark ? 'text-gray-100' : 'text-gray-900'}`}>
                        {alertTitle}
                      </div>
                      {alertSub && (
                        <div className={`text-[11px] mt-0.5 leading-tight ${isDark ? 'text-gray-400' : 'text-gray-500'}`}>
                          {alertSub}
                        </div>
                      )}
                    </td>

                    {/* OBSERVED */}
                    <td className="py-3 px-3 whitespace-nowrap">
                      <span className={`text-sm font-bold ${isCritical ? 'text-[#991b1b] dark:text-rose-400' : 'text-[#92400e] dark:text-amber-400'}`}>
                        {observedDisplay}
                      </span>
                    </td>

                    {/* TYPICAL */}
                    <td className="py-3 px-3 whitespace-nowrap">
                      <span className={`text-xs font-medium ${isDark ? 'text-gray-300' : 'text-gray-600'}`}>
                        {typicalDisplay}
                      </span>
                    </td>

                    {/* DATE */}
                    <td className="py-3 px-3 whitespace-nowrap">
                      <span className={`text-xs ${isDark ? 'text-gray-300' : 'text-gray-600'}`}>
                        {formatAlertDate(a.window_end ?? a.created_at)}
                      </span>
                    </td>

                    {/* INVESTIGATE */}
                    <td className="py-3 px-3 whitespace-nowrap">
                      <Link
                        to={link.to}
                        className={`text-xs font-semibold transition-colors hover:underline ${isDark
                            ? 'text-amber-400 hover:text-amber-300'
                            : 'text-[#800000] hover:text-[#5c0000]'
                          }`}
                      >
                        {link.label} &rarr;
                      </Link>
                    </td>

                    {/* ACTION */}
                    <td className="py-3 px-5 text-right whitespace-nowrap">
                      <button
                        type="button"
                        onClick={() => acknowledge(a.id)}
                        disabled={busyId === a.id}
                        className={`inline-flex items-center gap-1 px-3 py-1 rounded-lg border text-[11px] font-bold transition-all duration-150 cursor-pointer disabled:opacity-50 shadow-xs ${isDark
                            ? 'border-[#3e4042] bg-[#18191a] text-gray-200 hover:bg-[#2c2d2e] hover:border-gray-500'
                            : 'border-gray-200 bg-white text-gray-800 hover:bg-gray-50 hover:border-gray-300'
                          }`}
                      >
                        <CheckIcon className="w-3.5 h-3.5 stroke-[2.5]" />
                        {busyId === a.id ? 'Saving…' : 'Acknowledge'}
                      </button>
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      )}
    </Card>
  );
};

// ── Cashier tab ───────────────────────────────────────────────────────────

export const CashierTrendPanel = ({ isDark }) => {
  const [days, setDays] = useState(30);
  const { data, loading, error } = useLoad(() => getCashierTrend({ days }), [days]);

  const totals = (data?.series ?? []).reduce(
    (t, d) => ({ attempts: t.attempts + d.attempts, failures: t.failures + d.failures }),
    { attempts: 0, failures: 0 },
  );
  const rate = totals.attempts > 0 ? Math.round((totals.failures / totals.attempts) * 1000) / 10 : null;

  return (
    <Card
      isDark={isDark}
      title="Cashier verification trend"
      sub="Attempts and failures per day"
      icon={<CreditCardIcon className="w-5 h-5" />}
      action={<RangeSelect value={days} onChange={setDays} isDark={isDark} />}
    >
      {loading && <Muted isDark={isDark}>Loading…</Muted>}
      {error && <ErrorLine isDark={isDark}>{error}</ErrorLine>}
      {data && (
        <div className="space-y-5">
          <div className="grid grid-cols-3 gap-4">
            <Stat isDark={isDark} label="Attempts" value={totals.attempts} />
            <Stat
              isDark={isDark}
              label="Failures"
              value={totals.failures}
              valueColor={totals.failures > 0 ? (isDark ? 'text-rose-400' : 'text-rose-600') : undefined}
            />
            <Stat isDark={isDark} label="Failure rate" value={rate != null ? `${rate}%` : '—'} />
          </div>
          <TrendChart
            isDark={isDark}
            series={data.series}
            anomalyDates={data.anomaly_dates ?? []}
            lines={[
              { key: 'attempts', name: 'Attempts', color: '#64748b' },
              { key: 'failures', name: 'Failures', color: '#e11d48' },
            ]}
          />
          <div className={`border-t pt-5 ${isDark ? 'border-[#3e4042]' : 'border-gray-100'}`}>
            <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
              <Breakdown isDark={isDark} title="Failure reasons" items={data.failure_reasons ?? []} empty="No failures in this period." />
              <Breakdown isDark={isDark} title="Likely contributing factors" items={data.diagnosis_codes ?? []} empty="No diagnosis codes in this period." />
            </div>
          </div>
          <p className={`text-[11px] ${isDark ? 'text-gray-400' : 'text-gray-500'}`}>
            Diagnosis codes are likely contributing factors, not a fault verdict. The Cashier API does not return the name it has on file.
          </p>
          <div className={`flex items-center justify-between pt-3 border-t text-xs font-bold ${isDark ? 'border-[#3e4042] text-gray-200' : 'border-slate-100 text-gray-800'}`}>
            <span>Unresolved receipt labels: {data.unresolved_labels}</span>
            <Link to="/super-admin/cashier-reconciliation?tab=unmatched" className={`hover:underline ${isDark ? 'text-amber-400' : 'text-[#800000]'}`}>Open reconciliation &rarr;</Link>
          </div>
        </div>
      )}
    </Card>
  );
};

export const RepeatFailurePanel = ({ isDark }) => (
  <AlertsFeed isDark={isDark} type="repeat_failure" title="Repeat failures" limit={10} />
);

// ── Sign-in & Provisioning tab ────────────────────────────────────────────

export const ProvisioningPanel = ({ isDark }) => {
  const [days, setDays] = useState(30);
  const { data, loading, error } = useLoad(() => getProvisioningHealth({ days }), [days]);

  return (
    <Card
      isDark={isDark}
      title="OGOS & PUPTAPS provisioning"
      sub="Failures while signing people in"
      icon={<ServerIcon className="w-5 h-5" />}
      action={<RangeSelect value={days} onChange={setDays} isDark={isDark} />}
    >
      {loading && <Muted isDark={isDark}>Loading…</Muted>}
      {error && <ErrorLine isDark={isDark}>{error}</ErrorLine>}
      {data && (
        <div className="space-y-5">
          <TrendChart
            isDark={isDark}
            series={data.series}
            anomalyDates={data.anomaly_dates ?? []}
            lines={[
              { key: 'ogos', name: 'OGOS failures', color: '#800000' },
              { key: 'puptaps', name: 'PUPTAPS failures', color: '#d97706' },
            ]}
          />
          <p className={`text-[11px] ${isDark ? 'text-gray-400' : 'text-gray-500'}`}>
            A clean &ldquo;no such person&rdquo; answer is not counted; only outages and unavailable data are.
          </p>
          <div className={`border-t pt-5 ${isDark ? 'border-[#3e4042]' : 'border-gray-100'}`}>
            <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
              <Breakdown isDark={isDark} title="Failure reasons" items={data.failure_reasons ?? []} empty="No provisioning failures in this period." />
              <Breakdown isDark={isDark} title="Sign-in denials (SSO)" items={data.sso_denials ?? []} empty="No sign-in denials in this period." />
            </div>
          </div>
          <div className={`flex items-center gap-2 pt-3 border-t text-xs ${isDark ? 'border-[#3e4042] text-gray-300' : 'border-slate-100 text-gray-600'}`}>
            <UserGroupIcon className="w-4 h-4 shrink-0" />
            <span>
              Individual events are in the{' '}
              <Link to="/super-admin/report" className={`font-bold hover:underline ${isDark ? 'text-amber-400' : 'text-[#800000]'}`}>Audit Trail</Link>
              {' '}(Security Events &rarr; Provisioning Failed).
            </span>
          </div>
        </div>
      )}
    </Card>
  );
};

const Stat = ({ label, value, isDark, valueColor }) => (
  <div
    className={`p-4 rounded-2xl border text-center flex flex-col items-center justify-center transition-colors ${
      isDark
        ? 'bg-[#18191a] border-[#3e4042]'
        : 'bg-gray-50/70 border-gray-100 hover:border-gray-200'
    }`}
  >
    <p className={`text-[10px] font-bold uppercase tracking-wider mb-1 ${isDark ? 'text-[#9a9a9a]' : 'text-slate-400'}`}>
      {label}
    </p>
    <p className={`text-2xl font-black ${valueColor ?? (isDark ? 'text-[#e4e6eb]' : 'text-slate-800')}`}>
      {value?.toLocaleString?.() ?? value}
    </p>
  </div>
);