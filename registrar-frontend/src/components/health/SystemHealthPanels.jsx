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

const RangeSelect = ({ value, onChange, isDark }) => (
  <select
    value={value}
    onChange={(e) => onChange(Number(e.target.value))}
    aria-label="Date range"
    className={`text-xs font-semibold rounded-lg px-2 py-1 border cursor-pointer ${isDark ? 'bg-[#18191a] border-[#3e4042] text-[#e4e6eb]' : 'bg-white border-slate-200 text-slate-700'}`}
  >
    {RANGES.map((r) => <option key={r.value} value={r.value}>{r.label}</option>)}
  </select>
);

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

const TrendChart = ({ series, lines, anomalyDates, isDark, yFormatter }) => {
  const grid = isDark ? '#3e4042' : '#e2e8f0';
  const text = isDark ? '#9a9a9a' : '#94a3b8';
  return (
    <div className="w-full h-64" role="img" aria-label="Daily trend chart">
      <ResponsiveContainer width="100%" height="100%">
        <LineChart data={series} margin={{ top: 8, right: 12, left: -12, bottom: 0 }}>
          <CartesianGrid strokeDasharray="3 3" stroke={grid} />
          <XAxis dataKey="date" tick={{ fontSize: 10, fill: text }} tickFormatter={(d) => d.slice(5)} minTickGap={24} />
          <YAxis tick={{ fontSize: 10, fill: text }} allowDecimals={false} tickFormatter={yFormatter} />
          <Tooltip
            contentStyle={{
              borderRadius: 16, fontSize: 12,
              background: isDark ? '#242526' : '#fff',
              border: `1px solid ${isDark ? '#3e4042' : '#e2e8f0'}`,
              color: isDark ? '#e4e6eb' : '#1e293b',
            }}
          />
          <Legend wrapperStyle={{ fontSize: 11 }} />
          {anomalyDates.map((d) => (
            <ReferenceLine key={d} x={d} stroke="#e11d48" strokeDasharray="4 3" label={{ value: '!', fill: '#e11d48', fontSize: 12, position: 'top' }} />
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
  return (
    <div>
      <p className={`text-[10px] font-bold uppercase tracking-wider mb-2 ${isDark ? 'text-[#9a9a9a]' : 'text-slate-400'}`}>{title}</p>
      {items.length === 0 ? <Muted isDark={isDark}>{empty}</Muted> : (
        <ul className="space-y-1.5">
          {items.map((i) => (
            <li key={i.key} className="text-xs">
              <div className={`flex justify-between gap-2 ${isDark ? 'text-gray-200' : 'text-gray-700'}`}>
                <span className="truncate font-medium">{labelFor(i.key)}</span>
                <span className="font-bold shrink-0">{i.count.toLocaleString()}</span>
              </div>
              <div className={`h-1.5 rounded-full mt-1 overflow-hidden ${isDark ? 'bg-zinc-800' : 'bg-gray-100'}`}>
                <div className="h-full rounded-full bg-[#800000] dark:bg-amber-400" style={{ width: `${(i.count / max) * 100}%` }} />
              </div>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
};

// ── Alerts feed ───────────────────────────────────────────────────────────

const alertSummary = (a) => {
  if (a.type === 'repeat_failure') {
    const c = a.context ?? {};
    return `Repeat failures for one account (user #${c.user_id ?? a.dimension?.replace('user:', '') ?? '?'}): ${a.observed} in the window`;
  }
  const typical = a.baseline_median != null ? ` (typical ${a.baseline_median})` : '';
  const what = a.type === 'rate_spike'
    ? `${labelFor(a.metric)} rate ${a.observed != null ? `${Math.round(a.observed * 100)}%` : ''}`
    : `${a.metric.replace(/_/g, ' ')}${a.dimension ? ` · ${labelFor(a.dimension)}` : ''}: ${a.observed}`;
  return `${what}${typical}`;
};

const alertLink = (a) => {
  if (a.type === 'repeat_failure') return { to: '/super-admin/cashier-reconciliation?tab=failed', label: 'Open failed verifications' };
  if (a.source === 'provisioning') return { to: '/super-admin/system-analytics?tab=provisioning', label: 'View provisioning' };
  return { to: '/super-admin/system-analytics?tab=cashier', label: 'View cashier trend' };
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
      <ul className="space-y-2">
        {alerts.map((a) => {
          const link = alertLink(a);
          return (
            <li key={a.id} className={`flex flex-col sm:flex-row sm:items-center gap-2 justify-between rounded-2xl border px-3 py-2.5 ${isDark ? 'border-[#3e4042]' : 'border-slate-200'}`}>
              <div className="min-w-0">
                <div className="flex items-center gap-2 flex-wrap">
                  <span className={`px-2 py-0.5 rounded-full text-[10px] font-bold border uppercase ${SEVERITY_STYLES[a.severity] ?? 'bg-slate-100 text-slate-600 border-slate-200'}`}>{a.severity}</span>
                  <span className={`text-xs font-bold ${isDark ? 'text-gray-100' : 'text-gray-900'}`}>{alertSummary(a)}</span>
                </div>
                <p className={`text-[11px] mt-0.5 ${isDark ? 'text-gray-400' : 'text-gray-500'}`}>
                  {a.window_end ?? ''} ·{' '}
                  <Link to={link.to} className={`font-bold hover:underline ${isDark ? 'text-amber-400' : 'text-[#800000]'}`}>{link.label} &rarr;</Link>
                </p>
              </div>
              <button
                type="button"
                onClick={() => acknowledge(a.id)}
                disabled={busyId === a.id}
                className={`inline-flex items-center gap-1 text-xs font-bold px-3 py-1.5 rounded-full border cursor-pointer disabled:opacity-50 shrink-0 ${isDark ? 'border-[#3e4042] text-gray-200 hover:bg-zinc-800' : 'border-slate-200 text-slate-700 hover:bg-slate-50'}`}
              >
                <CheckIcon className="w-3.5 h-3.5" /> {busyId === a.id ? 'Saving…' : 'Acknowledge'}
              </button>
            </li>
          );
        })}
      </ul>
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
            <Stat isDark={isDark} label="Failures" value={totals.failures} />
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
          {(data.anomaly_dates ?? []).length > 0 && (
            <p className={`text-[11px] ${isDark ? 'text-gray-400' : 'text-gray-500'}`}>Dashed red lines mark days an alert was raised.</p>
          )}
          <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
            <Breakdown isDark={isDark} title="Failure reasons" items={data.failure_reasons ?? []} empty="No failures in this period." />
            <Breakdown isDark={isDark} title="Likely contributing factors" items={data.diagnosis_codes ?? []} empty="No diagnosis codes in this period." />
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
          <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
            <Breakdown isDark={isDark} title="Failure reasons" items={data.failure_reasons ?? []} empty="No provisioning failures in this period." />
            <Breakdown isDark={isDark} title="Sign-in denials (SSO)" items={data.sso_denials ?? []} empty="No sign-in denials in this period." />
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

const Stat = ({ label, value, isDark }) => (
  <div>
    <p className={`text-[10px] font-bold uppercase tracking-wider ${isDark ? 'text-[#9a9a9a]' : 'text-slate-400'}`}>{label}</p>
    <p className={`text-xl font-black ${isDark ? 'text-[#e4e6eb]' : 'text-slate-800'}`}>{value?.toLocaleString?.() ?? value}</p>
  </div>
);
