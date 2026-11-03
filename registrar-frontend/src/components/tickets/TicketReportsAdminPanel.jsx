import React, { useState, useEffect, useCallback } from "react";
import {
  ArrowPathIcon,
  InboxStackIcon,
  ShieldCheckIcon,
} from "@heroicons/react/24/outline";
import { useTheme } from "../../context/ThemeContext";
import { getTicketSummary } from "../../services/api";
import {
  TICKET_STATUS_META,
  getManilaDateString,
  formatTicketDateTime,
  formatPriorityLabel,
  parseTicketApiError,
} from "../../utils/ticketHelpers";
import { useRetryAfterCooldown } from "../../hooks/useTicketLiveUpdates";
import InputGroup from "../InputGroup.jsx";
import ErrorToast from "../ErrorToast.jsx";

const PRESETS = [
  { label: "Last 7 Days", days: -6 },
  { label: "Last 30 Days", days: -29 },
  { label: "Last 90 Days", days: -89 },
];

const TicketReportsAdminPanel = () => {
  const { isDark } = useTheme();
  const cooldown = useRetryAfterCooldown();

  const [fromDate, setFromDate] = useState(() => getManilaDateString(-29));
  const [toDate, setToDate] = useState(() => getManilaDateString(0));
  const [activePresetDays, setActivePresetDays] = useState(-29);

  const [summary, setSummary] = useState(null);
  const [loading, setLoading] = useState(true);
  const [errorMsg, setErrorMsg] = useState("");

  const fetchSummary = useCallback(
    async (fromArg = fromDate, toArg = toDate) => {
      setLoading(true);
      setErrorMsg("");
      try {
        const res = await getTicketSummary({ from: fromArg, to: toArg });
        setSummary(res.data?.data || null);
      } catch (err) {
        const parsed = await parseTicketApiError(
          err,
          "Unable to load ticket analytics summary."
        );
        if (parsed.status === 429) cooldown.startCooldown(parsed.retryAfterSeconds);
        setErrorMsg(parsed.message);
      } finally {
        setLoading(false);
      }
    },
    [fromDate, toDate, cooldown.startCooldown]
  );

  useEffect(() => {
    fetchSummary(fromDate, toDate);
    // Run on initial mount; subsequent changes trigger via Apply / Presets
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const applyPreset = (daysOffset) => {
    const nextFrom = getManilaDateString(daysOffset);
    const nextTo = getManilaDateString(0);
    setActivePresetDays(daysOffset);
    setFromDate(nextFrom);
    setToDate(nextTo);
    fetchSummary(nextFrom, nextTo);
  };

  const handleApplyRange = (e) => {
    e.preventDefault();
    fetchSummary(fromDate, toDate);
  };

  const queueNow = summary?.queue_now || {};
  const volume = summary?.volume || {};
  const sla = summary?.sla || {};
  const byCategory = Array.isArray(volume.by_category) ? volume.by_category : [];
  const perDay = Array.isArray(volume.per_day) ? volume.per_day : [];
  const byPriority = volume.by_priority || {};
  const byStatus = volume.by_status || {};
  const maxDayTotal = Math.max(1, ...perDay.map((d) => Number(d.total) || 0));
  const maxCatTotal = Math.max(1, ...byCategory.map((c) => Number(c.total) || 0));

  const renderSlaCard = (title, subtitle, metric) => {
    const m = metric || {};
    const pct =
      m.compliance_pct !== null && m.compliance_pct !== undefined
        ? `${Number(m.compliance_pct).toFixed(1)}%`
        : "N/A";
    const tracked = Number(m.tracked) || 0;
    const met = Number(m.met) || 0;
    const pending = Number(m.pending) || 0;
    const breached = Number(m.breached) || 0;
    const atRisk = Number(m.at_risk_flagged) || 0;
    const total = Math.max(tracked, met + pending + breached + atRisk, 1);

    return (
      <div
        className={`rounded-4xl p-4 sm:p-6 border space-y-4 shadow-sm ${
          isDark
            ? "bg-[#242526] border-[#3e4042] text-[#e4e6eb]"
            : "bg-white border-slate-200 text-gray-900"
        }`}
      >
        <div className="flex items-start justify-between gap-3">
          <div>
            <h3 className="text-sm font-extrabold flex items-center gap-1.5">
              {title}
            </h3>
            <p className={`text-xs mt-1 max-w-md ${isDark ? "text-gray-400" : "text-gray-500"}`}>
              {subtitle}
            </p>
          </div>
          <div
            className={`shrink-0 text-[11px] font-bold px-3 py-1.5 rounded-full border ${
              isDark
                ? "bg-[#18191a] border-[#3e4042] text-yellow-400"
                : "bg-emerald-50 border-emerald-100 text-emerald-700"
            }`}
          >
            {pct === "N/A" ? "Too early to tell" : "On track"}
          </div>
        </div>

        <div className={`h-2.5 rounded-full overflow-hidden flex ${isDark ? "bg-[#18191a]" : "bg-slate-100"}`}>
          <div className="bg-emerald-500" style={{ width: `${(met / total) * 100}%` }} />
          <div className="bg-blue-500" style={{ width: `${(pending / total) * 100}%` }} />
          <div className="bg-rose-500" style={{ width: `${(breached / total) * 100}%` }} />
          <div className="bg-amber-400" style={{ width: `${(atRisk / total) * 100}%` }} />
        </div>
        <div className={`flex flex-wrap gap-x-4 gap-y-1 text-[11px] ${isDark ? "text-gray-400" : "text-gray-500"}`}>
          <span><b className="text-emerald-600">■</b> On time: {met}</span>
          <span><b className="text-blue-600">■</b> Still within goal: {pending}</span>
          <span><b className="text-rose-600">■</b> Late: {breached}</span>
          <span><b className="text-amber-500">■</b> Running out of time: {atRisk}</span>
        </div>
      </div>
    );
  };

  return (
    <div
      className={`space-y-5 sm:space-y-6 py-4 sm:py-5 min-h-full font-sans ${
        isDark ? "bg-[#18191a] text-[#e4e6eb]" : "text-gray-900"
      }`}
    >
      {/* Compact date filter strip */}
      <div
        id="ticket-report-date-range"
        className={`rounded-2xl border px-3 py-3 sm:px-5 sm:py-4 shadow-sm ${
          isDark
            ? "bg-[#242526] border-[#3e4042]"
            : "bg-white border-slate-200"
        }`}
      >
        <form
          onSubmit={handleApplyRange}
          className="grid grid-cols-1 sm:grid-cols-2 lg:flex lg:items-end gap-3 text-xs"
        >
          <div className="w-full sm:min-w-40 lg:w-36">
            <InputGroup
              type="date"
              label="From"
              name="from_date"
              value={fromDate}
              max={toDate}
              onChange={(e) => {
                setActivePresetDays(null);
                setFromDate(e.target.value || getManilaDateString(-29));
              }}
              voiceEnabled={false}
              labelColor={isDark ? "text-gray-300" : "text-gray-700"}
            />
          </div>

          <div className="w-full sm:min-w-40 lg:w-36">
            <InputGroup
              type="date"
              label="To"
              name="to_date"
              value={toDate}
              min={fromDate}
              onChange={(e) => {
                setActivePresetDays(null);
                setToDate(e.target.value || getManilaDateString(0));
              }}
              voiceEnabled={false}
              labelColor={isDark ? "text-gray-300" : "text-gray-700"}
            />
          </div>

          <button
            type="submit"
            disabled={loading || cooldown.isCoolingDown}
            className={`inline-flex items-center justify-center gap-1.5 min-h-11 px-4 py-3 rounded-lg font-black border shadow-sm cursor-pointer disabled:opacity-50 ${
              isDark
              ? "bg-[#18191a] border-[#18191a] text-white hover:bg-black"
              : "bg-[#1f1b1b] border-[#1f1b1b] text-white hover:bg-black"
            }`}
          >
            Apply range
          </button>

          <span className={`hidden xl:block text-[11px] whitespace-nowrap ml-auto mr-1 ${
            isDark ? "text-gray-400" : "text-slate-500"
          }`}>
            Up to 366 days
          </span>

          <div
            className={`grid grid-cols-3 gap-0 rounded-xl p-1 sm:col-span-2 lg:w-auto ${
              isDark ? "bg-[#3a3b3c]" : "bg-[#e9e5e2]"
            }`}
          >
            {PRESETS.map((preset) => (
              <button
                key={preset.label}
                type="button"
                onClick={() => applyPreset(preset.days)}
                className={`min-h-9 px-3 py-2 rounded-lg text-[11px] sm:text-xs font-semibold whitespace-nowrap cursor-pointer ${
                  activePresetDays === preset.days
                    ? isDark
                      ? "bg-[#242526] text-yellow-300 shadow-sm"
                      : "bg-white text-pup-dark-maroon shadow-sm"
                    : isDark
                      ? "text-gray-300 hover:text-white"
                      : "text-gray-700 hover:text-pup-dark-maroon"
                }`}
              >
                {preset.label}
              </button>
            ))}
          </div>
        </form>
      </div>

      {loading ? (
        <div
          className={`rounded-4xl p-12 border text-center shadow-sm ${
            isDark
              ? "bg-[#242526] border-[#3e4042] text-[#e4e6eb]"
              : "bg-white border-slate-200 text-gray-900"
          }`}
        >
          <ArrowPathIcon
            className={`w-7 h-7 mx-auto mb-2 animate-spin ${
              isDark ? "text-yellow-400" : "text-pup-dark-maroon"
            }`}
          />
          <p className="text-xs opacity-70">Calculating report metrics…</p>
        </div>
      ) : summary ? (
        <>
          {Number(queueNow.unclaimed) > 0 && (
            <div className={`rounded-2xl border px-4 py-3 flex flex-col sm:flex-row sm:items-center justify-between gap-3 ${
              isDark
                ? "bg-amber-950/30 border-amber-800/60 text-amber-100"
                : "bg-amber-50 border-amber-200 text-amber-950"
            }`}>
              <div>
                <p className="font-extrabold text-sm">
                  {queueNow.unclaimed} {Number(queueNow.unclaimed) === 1 ? "ticket is" : "tickets are"} waiting for someone to pick them up
                </p>
                <p className="text-xs opacity-75 mt-0.5">
                  {queueNow.oldest_unclaimed_at
                    ? `The oldest came in ${formatTicketDateTime(queueNow.oldest_unclaimed_at)}.`
                    : "These tickets are ready to be claimed."}
                </p>
              </div>
            </div>
          )}

          {/* At-a-glance queue snapshot */}
          <div
            className={`rounded-4xl p-4 sm:p-6 border space-y-4 shadow-sm ${
              isDark
                ? "bg-[#242526] border-[#3e4042] text-[#e4e6eb]"
                : "bg-white border-slate-200 text-gray-900"
            }`}
          >
            <div className="flex flex-wrap items-center justify-between gap-2">
              <h3 className="text-sm font-extrabold flex items-center gap-2">
                <InboxStackIcon className="w-4 h-4" />
                At a glance
              </h3>
              <span className={`text-xs ${isDark ? "text-gray-400" : "text-gray-500"}`}>
                Where your tickets stand right now.
              </span>
            </div>

            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
              <MetricCard isDark={isDark} label="New tickets" value={volume.created ?? 0} sub="Came in during this period" />
              <MetricCard isDark={isDark} label="Waiting to be picked up" value={queueNow.unclaimed ?? 0} sub="No staff member has taken them yet" tone="amber" />
              <MetricCard isDark={isDark} label="Being handled" value={queueNow.claimed ?? 0} sub="A staff member is working on it" tone="sky" />
              <MetricCard isDark={isDark} label="Waiting on the student" value={queueNow.pending_client ?? 0} sub="We asked them for more details" />
            </div>
          </div>

          {/* SLA Compliance Cards */}
          <div>
            <h3 className="text-base font-black">Are we replying on time?</h3>
            <p className={`text-xs mt-1 ${isDark ? "text-gray-400" : "text-gray-500"}`}>
              Each ticket has a time goal based on its priority.
            </p>
          </div>
          <div className="grid grid-cols-1 lg:grid-cols-2 gap-3 sm:gap-4">
            {renderSlaCard(
              "First reply",
              "How quickly we send the first response: 4 working hours for high priority, or 8 hours for medium and low priority.",
              sla.first_response
            )}
            {renderSlaCard(
              "Full resolution",
              "How quickly we solve the concern: 1 working day for high priority, or 2 days for medium and low priority.",
              sla.resolution
            )}
          </div>

          {/* Breakdowns */}
          <div>
            <h3 className="text-base font-black">What are tickets about?</h3>
            <p className={`text-xs mt-1 ${isDark ? "text-gray-400" : "text-gray-500"}`}>
              How tickets break down by topic, urgency, and progress.
            </p>
          </div>
          <div className="grid grid-cols-1 lg:grid-cols-3 gap-3 sm:gap-4">
            {/* Volume by Category */}
            <div
              className={`rounded-4xl p-6 border space-y-4 shadow-sm ${
                isDark
                  ? "bg-[#242526] border-[#3e4042] text-[#e4e6eb]"
                : "bg-white border-slate-200 text-gray-900"
              }`}
            >
              <h3 className="text-sm font-extrabold">Topic</h3>
              {byCategory.length === 0 ? (
                <p className="text-xs opacity-65">No tickets were created during this period.</p>
              ) : (
                <div className="space-y-2.5 text-xs">
                  {byCategory.map((cat) => {
                    const total = Number(cat.total) || 0;
                    const widthPct = Math.round((total / maxCatTotal) * 100);
                    return (
                      <div key={cat.code} className="space-y-1">
                        <div className="flex items-center justify-between gap-2">
                          <span className="font-semibold truncate">
                            <span className="font-mono mr-1.5">{cat.code}</span>
                            {cat.name}
                          </span>
                          <span className="font-bold">{total}</span>
                        </div>
                        <div
                          className={`h-2 rounded-full overflow-hidden ${
                            isDark ? "bg-[#18191a]" : "bg-gray-100"
                          }`}
                        >
                          <div
                            className={`h-full rounded-full ${
                              isDark ? "bg-yellow-400" : "bg-pup-dark-maroon"
                            }`}
                            style={{ width: `${Math.max(widthPct, total > 0 ? 4 : 0)}%` }}
                          />
                        </div>
                      </div>
                    );
                  })}
                </div>
              )}
            </div>

            {/* Volume by Priority */}
            <div
              className={`rounded-4xl p-6 border space-y-5 shadow-sm ${
                isDark
                  ? "bg-[#242526] border-[#3e4042] text-[#e4e6eb]"
                  : "bg-white border-slate-200 text-gray-900"
              }`}
            >
              <div>
                <h3 className="text-sm font-extrabold mb-3">Priority</h3>
                <div className="grid grid-cols-3 gap-2 text-xs">
                  {["high", "medium", "low"].map((p) => (
                    <div key={p} className="space-y-1">
                      <div className="flex justify-between font-semibold">
                        <span>{formatPriorityLabel(p)}</span><span>{byPriority[p] ?? 0}</span>
                      </div>
                      <div className={`h-2 rounded-full ${isDark ? "bg-[#18191a]" : "bg-slate-100"}`}>
                        <div className={`h-full rounded-full ${p === "high" ? "bg-rose-500" : p === "medium" ? "bg-amber-400" : "bg-slate-400"}`} style={{ width: `${Math.min(100, ((byPriority[p] || 0) / Math.max(1, ...Object.values(byPriority).map(Number))) * 100)}%` }} />
                      </div>
                    </div>
                  ))}
                </div>
              </div>
            </div>
            <div className={`rounded-4xl p-6 border space-y-4 shadow-sm ${isDark ? "bg-[#242526] border-[#3e4042] text-[#e4e6eb]" : "bg-white border-slate-200 text-gray-900"}`}>
              <h3 className="text-sm font-extrabold">Ticket status</h3>
              <div className="space-y-3 text-xs">
                {Object.keys(TICKET_STATUS_META).map((st) => (
                  <div key={st} className="flex justify-between border-b last:border-0 pb-2 last:pb-0">
                    <span className={st === "closed" ? "opacity-60" : ""}>{TICKET_STATUS_META[st].label}</span>
                    <strong>{byStatus[st] ?? 0}</strong>
                  </div>
                ))}
              </div>
            </div>
          </div>

          {/* Tickets per day */}
          <div
            className={`rounded-4xl p-6 border space-y-4 shadow-sm ${
              isDark
                ? "bg-[#242526] border-[#3e4042] text-[#e4e6eb]"
                : "bg-white border-slate-200 text-gray-900"
            }`}
          >
            <div className="flex items-center justify-between">
              <h3 className="text-sm font-extrabold">
                Tickets per day
              </h3>
              <span className="text-xs opacity-70 flex items-center gap-1">
                <ShieldCheckIcon className="w-4 h-4" />
                {perDay.length} day{perDay.length === 1 ? "" : "s"}
              </span>
            </div>

            {perDay.length === 0 ? (
              <p className="text-xs opacity-65">No ticket activity was recorded during this period.</p>
            ) : (
              <div className="max-h-64 overflow-y-auto space-y-1.5 pr-1 text-xs">
                {perDay.map((row) => {
                  const total = Number(row.total) || 0;
                  const pct = Math.round((total / maxDayTotal) * 100);
                  return (
                    <div key={row.date} className="flex items-center gap-3">
                      <span className="font-mono w-24 shrink-0 opacity-80">
                        {row.date}
                      </span>
                      <div
                        className={`flex-1 h-2.5 rounded-full overflow-hidden ${
                          isDark ? "bg-[#18191a]" : "bg-gray-100"
                        }`}
                      >
                        <div
                          className={`h-full rounded-full ${
                            isDark ? "bg-yellow-400" : "bg-pup-dark-maroon"
                          }`}
                          style={{ width: `${Math.max(pct, total > 0 ? 3 : 0)}%` }}
                        />
                      </div>
                      <span className="w-10 text-right font-bold">{total}</span>
                    </div>
                  );
                })}
              </div>
            )}
          </div>
        </>
      ) : null}

      <ErrorToast message={errorMsg} onClose={() => setErrorMsg("")} />
    </div>
  );
};

const StatChip = ({ isDark, label, value, tone = "default" }) => {
  const toneClasses = {
    default: isDark
      ? "bg-[#18191a] border-[#3e4042] text-[#e4e6eb]"
      : "bg-gray-50 border-gray-200 text-gray-900",
    emerald: isDark
      ? "bg-emerald-950/30 border-emerald-800/50 text-emerald-300"
      : "bg-emerald-50 border-emerald-200 text-emerald-800",
    rose: isDark
      ? "bg-rose-950/30 border-rose-800/50 text-rose-300"
      : "bg-rose-50 border-rose-200 text-rose-800",
    amber: isDark
      ? "bg-amber-950/30 border-amber-800/50 text-amber-300"
      : "bg-amber-50 border-amber-200 text-amber-800",
    sky: isDark
      ? "bg-sky-950/30 border-sky-800/50 text-sky-300"
      : "bg-sky-50 border-sky-200 text-sky-800",
  };

  return (
    <div
      className={`rounded-2xl p-4 sm:p-5 border shadow-sm ${
        toneClasses[tone] || toneClasses.default
      }`}
    >
      <div className="text-[11px] opacity-75 truncate">{label}</div>
      <div className="text-lg font-black mt-0.5">{value}</div>
    </div>
  );
};

const MetricCard = ({ isDark, label, value, sub, tone = "default" }) => {
  const toneClasses = {
    default: isDark
      ? "bg-[#242526] border-[#3e4042] text-[#e4e6eb]"
      : "bg-white border-slate-200 text-gray-900",
    amber: isDark
      ? "bg-amber-950/20 border-amber-700/60 text-[#e4e6eb]"
      : "bg-amber-50 border-amber-200 text-gray-900",
    sky: isDark
      ? "bg-sky-950/20 border-sky-700/60 text-[#e4e6eb]"
      : "bg-blue-50 border-blue-200 text-gray-900",
  };

  return (
  <div
    className={`rounded-2xl p-4 border shadow-sm ${toneClasses[tone] || toneClasses.default}`}
  >
    <div className="text-xs font-semibold opacity-75">{label}</div>
    <div className="text-2xl font-black mt-1">{value}</div>
    {sub && <div className="text-[11px] opacity-65 mt-0.5">{sub}</div>}
  </div>
  );
};

export default TicketReportsAdminPanel;
