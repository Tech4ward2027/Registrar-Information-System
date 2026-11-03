import React, { useState, useEffect, useCallback, useRef, useMemo } from "react";
import { useNavigate } from "react-router-dom";
import {
  ArrowPathIcon,
  HandRaisedIcon,
  ClockIcon,
  ExclamationTriangleIcon,
  FunnelIcon,
  InboxIcon,
  UserIcon,
  GlobeAltIcon,
  SparklesIcon,
} from "@heroicons/react/24/outline";
import { useTheme } from "../../context/ThemeContext";
import { useAuth } from "../../context/AuthProvider";
import {
  getTicketQueue,
  getTicketCategories,
  getTicketCategoriesAdmin,
  claimTicket,
} from "../../services/api";
import { MODULE_KEYS, hasModuleAction } from "../../utils/policy";
import {
  getStatusBadgeClass,
  getPriorityBadgeClass,
  getSlaBadgeClass,
  formatPriorityLabel,
  formatSlaStateLabel,
  formatTicketDateTime,
  formatTicketRelative,
  parseTicketApiError,
} from "../../utils/ticketHelpers";
import {
  useTicketLiveUpdates,
  useRetryAfterCooldown,
} from "../../hooks/useTicketLiveUpdates";
import VoiceSearchInput from "../VoiceSearchInput.jsx";
import DropdownGroup from "../DropDown.jsx";
import SuccessToast from "../SuccessToast.jsx";
import ErrorToast from "../ErrorToast.jsx";

const SCOPE_TABS = [
  { key: "unclaimed", label: "Unclaimed Queue" },
  { key: "mine", label: "Claimed by Me" },
  { key: "all", label: "All Tickets" },
];

const STATUS_OPTIONS = [
  { value: "", label: "Active (Open / In Progress / Pending)" },
  { value: "open", label: "Open" },
  { value: "in_progress", label: "In Progress" },
  { value: "pending_client", label: "Pending Client" },
  { value: "resolved", label: "Resolved" },
  { value: "closed", label: "Closed" },
];

const PRIORITY_OPTIONS = [
  { value: "", label: "All Priorities" },
  { value: "high", label: "High Priority" },
  { value: "medium", label: "Medium Priority" },
  { value: "low", label: "Low Priority" },
];

const SLA_OPTIONS = [
  { value: "", label: "Any SLA State" },
  { value: "at_risk", label: "SLA At Risk" },
  { value: "breached", label: "SLA Breached" },
];

const StaffTicketQueuePanel = ({ baseTicketsPath }) => {
  const { isDark } = useTheme();
  const { user } = useAuth();
  const navigate = useNavigate();

  const canClaim = hasModuleAction(user, MODULE_KEYS.TICKETS, "Claim");
  const canManage = hasModuleAction(user, MODULE_KEYS.TICKETS, "Manage");

  const [scope, setScope] = useState("unclaimed");
  const [statusFilter, setStatusFilter] = useState("");
  const [priorityFilter, setPriorityFilter] = useState("");
  const [categoryFilter, setCategoryFilter] = useState("");
  const [slaFilter, setSlaFilter] = useState("");
  const [searchInput, setSearchInput] = useState("");
  const [debouncedQuery, setDebouncedQuery] = useState("");

  const [categories, setCategories] = useState([]);
  const [tickets, setTickets] = useState([]);
  const [nextCursor, setNextCursor] = useState(null);
  const [loading, setLoading] = useState(true);
  const [loadingMore, setLoadingMore] = useState(false);
  const [claimingId, setClaimingId] = useState(null);
  const [liveIndicator, setLiveIndicator] = useState(false);

  const [successMsg, setSuccessMsg] = useState("");
  const [errorMsg, setErrorMsg] = useState("");
  const cooldown = useRetryAfterCooldown();

  const categoryFilterOptions = useMemo(
    () => [
      { value: "", label: "All Categories" },
      ...categories.map((cat) => ({
        value: cat.code,
        label: `${cat.code} — ${cat.name}${cat.is_active === false ? " (Inactive)" : ""}`,
      })),
    ],
    [categories]
  );

  // Debounce search query
  useEffect(() => {
    const timer = setTimeout(() => {
      setDebouncedQuery(searchInput.trim());
    }, 350);
    return () => clearTimeout(timer);
  }, [searchInput]);

  // Load categories for the filter dropdown
  useEffect(() => {
    let active = true;
    const loadCats = async () => {
      try {
        const res = canManage
          ? await getTicketCategoriesAdmin()
          : await getTicketCategories();
        if (active) {
          setCategories(Array.isArray(res.data?.data) ? res.data.data : []);
        }
      } catch {
        // Fallback silently if categories fail to load
      }
    };
    loadCats();
    return () => {
      active = false;
    };
  }, [canManage]);

  const buildQueryParams = useCallback(
    (cursor = null) => {
      const params = { scope, per_page: 20 };
      if (statusFilter) params.status = statusFilter;
      if (priorityFilter) params.priority = priorityFilter;
      if (categoryFilter) params.category = categoryFilter;
      if (slaFilter) params.sla = slaFilter;
      if (debouncedQuery) params.q = debouncedQuery;
      if (cursor) params.cursor = cursor;
      return params;
    },
    [scope, statusFilter, priorityFilter, categoryFilter, slaFilter, debouncedQuery]
  );

  const fetchQueue = useCallback(
    async ({ cursor = null, append = false, silent = false } = {}) => {
      if (append) {
        setLoadingMore(true);
      } else if (!silent) {
        setLoading(true);
      }

      try {
        const res = await getTicketQueue(buildQueryParams(cursor));
        const rows = Array.isArray(res.data?.data) ? res.data.data : [];
        const next = res.data?.meta?.next_cursor ?? null;

        setTickets((prev) => {
          if (!append) return rows;
          const existingIds = new Set(prev.map((item) => item.id));
          const uniqueNew = rows.filter((item) => !existingIds.has(item.id));
          return [...prev, ...uniqueNew];
        });
        setNextCursor(next);
      } catch (err) {
        if (!silent) {
          const parsed = await parseTicketApiError(err, "Unable to load ticket queue.");
          if (parsed.status === 429) cooldown.startCooldown(parsed.retryAfterSeconds);
          setErrorMsg(parsed.message);
        }
      } finally {
        setLoading(false);
        setLoadingMore(false);
      }
    },
    [buildQueryParams, cooldown.startCooldown]
  );

  useEffect(() => {
    fetchQueue({ cursor: null, append: false, silent: false });
  }, [fetchQueue]);

  // Throttle silent background refreshes triggered by Reverb broadcasts
  const liveRefreshTimerRef = useRef(null);
  useTicketLiveUpdates({
    staffQueue: true,
    enabled: true,
    onTicketUpdated: useCallback(() => {
      setLiveIndicator(true);
      if (liveRefreshTimerRef.current) clearTimeout(liveRefreshTimerRef.current);
      liveRefreshTimerRef.current = setTimeout(() => {
        fetchQueue({ cursor: null, append: false, silent: true });
        setTimeout(() => setLiveIndicator(false), 1800);
      }, 450);
    }, [fetchQueue]),
  });

  useEffect(() => {
    return () => {
      if (liveRefreshTimerRef.current) clearTimeout(liveRefreshTimerRef.current);
    };
  }, []);

  const handleQuickClaim = async (e, ticket) => {
    e.stopPropagation();
    if (!ticket?.id || claimingId || cooldown.isCoolingDown) return;

    setClaimingId(ticket.id);
    setErrorMsg("");
    try {
      const res = await claimTicket(ticket.id);
      const updated = res.data?.data;
      setSuccessMsg(`Claimed ${ticket.ticket_number}. Opening ticket…`);
      if (updated?.id) {
        navigate(`${baseTicketsPath}/${updated.id}`);
      } else {
        fetchQueue({ cursor: null, append: false, silent: true });
      }
    } catch (err) {
      const parsed = await parseTicketApiError(err, "Unable to claim ticket.");
      if (parsed.status === 429) cooldown.startCooldown(parsed.retryAfterSeconds);
      setErrorMsg(parsed.message);

      if (parsed.status === 409 && parsed.code === "already_claimed") {
        // Immediately reflect who claimed it or refresh the queue
        if (parsed.claimedBy) {
          setTickets((prev) =>
            prev.map((t) =>
              t.id === ticket.id
                ? {
                    ...t,
                    is_claimed: true,
                    claimed_by: {
                      id: parsed.claimedBy.id,
                      name: parsed.claimedBy.name,
                      is_me: false,
                    },
                  }
                : t
            )
          );
        }
        fetchQueue({ cursor: null, append: false, silent: true });
      }
    } finally {
      setClaimingId(null);
    }
  };

  const hasActiveFilters =
    Boolean(statusFilter) ||
    Boolean(priorityFilter) ||
    Boolean(categoryFilter) ||
    Boolean(slaFilter) ||
    Boolean(searchInput.trim());

  const clearFilters = () => {
    setStatusFilter("");
    setPriorityFilter("");
    setCategoryFilter("");
    setSlaFilter("");
    setSearchInput("");
    setDebouncedQuery("");
  };

  const renderSlaPill = (label, metric, paused) => {
    if (!metric || !metric.state || metric.state === "none") return null;
    const effectiveState =
      paused && !["met", "missed", "breached"].includes(metric.state)
        ? "paused"
        : metric.state;

    return (
      <span
        className={`inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-[11px] font-semibold border ${getSlaBadgeClass(
          effectiveState,
          isDark
        )}`}
        title={
          metric.due_at
            ? `${label} due: ${formatTicketDateTime(metric.due_at)}`
            : `${label}: ${formatSlaStateLabel(effectiveState)}`
        }
      >
        <ClockIcon className="w-3 h-3 shrink-0" />
        <span>
          {label}: {formatSlaStateLabel(effectiveState)}
        </span>
        {metric.due_at && !["met", "missed"].includes(metric.state) && (
          <span className="opacity-80 font-normal hidden sm:inline">
            · {formatTicketDateTime(metric.due_at)}
          </span>
        )}
      </span>
    );
  };

  return (
    <div className="space-y-4">
      {/* Queue Scope & Controls Card */}
      <div
        className={`rounded-2xl p-4 sm:p-5 border transition-colors ${
          isDark
            ? "bg-[#242526] border-[#3e4042] text-[#e4e6eb]"
            : "bg-white border-gray-200/80 shadow-sm text-gray-900"
        }`}
      >
        <div className="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-3 mb-4">
          {/* Scope Segmented Pills */}
          <div
            className={`inline-flex p-1 rounded-xl self-start ${
              isDark ? "bg-[#18191a] border border-[#3e4042]" : "bg-gray-100"
            }`}
          >
            {SCOPE_TABS.map((tab) => {
              const active = scope === tab.key;
              return (
                <button
                  key={tab.key}
                  type="button"
                  onClick={() => setScope(tab.key)}
                  className={`px-3.5 py-1.5 rounded-lg text-xs font-bold transition-all cursor-pointer ${
                    active
                      ? isDark
                        ? "bg-yellow-400 text-black shadow-sm"
                        : "bg-pup-dark-maroon text-white shadow-sm"
                      : isDark
                        ? "text-[#b0b3b8] hover:text-white"
                        : "text-gray-600 hover:text-gray-900"
                  }`}
                >
                  {tab.label}
                </button>
              );
            })}
          </div>

          {/* Voice Search + Refresh */}
          <div className="flex items-center gap-2 w-full lg:w-auto">
            {liveIndicator && (
              <span
                className={`inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[11px] font-semibold shrink-0 ${
                  isDark
                    ? "bg-emerald-950/60 text-emerald-300 border border-emerald-800/60"
                    : "bg-emerald-50 text-emerald-700 border border-emerald-200"
                }`}
              >
                <SparklesIcon className="w-3.5 h-3.5 animate-pulse" />
                Live sync
              </span>
            )}
            <div className="flex-1 lg:w-80">
              <VoiceSearchInput
                value={searchInput}
                onChange={setSearchInput}
                placeholder="Search TKT-2026-… or subject"
              />
            </div>

            <button
              type="button"
              onClick={() => fetchQueue({ cursor: null, append: false, silent: false })}
              disabled={loading || cooldown.isCoolingDown}
              title="Refresh queue"
              className={`inline-flex items-center gap-1.5 px-3.5 py-3 rounded-lg text-xs font-semibold border shadow-sm transition cursor-pointer disabled:opacity-50 shrink-0 ${
                isDark
                  ? "bg-[#1f1f1f] border-[#3e4042] text-gray-200 hover:bg-[#303134]"
                  : "bg-white border-gray-200 text-gray-700 hover:bg-gray-50"
              }`}
            >
              <ArrowPathIcon className={`w-4 h-4 ${loading ? "animate-spin" : ""}`} />
              <span className="hidden sm:inline">Refresh</span>
            </button>
          </div>
        </div>

        {/* Reusable Dropdown Filter Bar */}
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-2.5 items-start">
          <DropdownGroup
            name="status_filter"
            value={STATUS_OPTIONS.find((o) => o.value === statusFilter)?.label || STATUS_OPTIONS[0].label}
            options={STATUS_OPTIONS.map((o) => o.label)}
            onChange={(e) => {
              const found = STATUS_OPTIONS.find((o) => o.label === e.target.value);
              setStatusFilter(found ? found.value : "");
            }}
          />

          <DropdownGroup
            name="priority_filter"
            value={PRIORITY_OPTIONS.find((o) => o.value === priorityFilter)?.label || PRIORITY_OPTIONS[0].label}
            options={PRIORITY_OPTIONS.map((o) => o.label)}
            onChange={(e) => {
              const found = PRIORITY_OPTIONS.find((o) => o.label === e.target.value);
              setPriorityFilter(found ? found.value : "");
            }}
          />

          <DropdownGroup
            name="category_filter"
            value={
              categoryFilterOptions.find((o) => o.value === categoryFilter)?.label ||
              categoryFilterOptions[0].label
            }
            options={categoryFilterOptions.map((o) => o.label)}
            onChange={(e) => {
              const found = categoryFilterOptions.find((o) => o.label === e.target.value);
              setCategoryFilter(found ? found.value : "");
            }}
          />

          <div className="flex items-center gap-2">
            <div className="flex-1 min-w-0">
              <DropdownGroup
                name="sla_filter"
                value={SLA_OPTIONS.find((o) => o.value === slaFilter)?.label || SLA_OPTIONS[0].label}
                options={SLA_OPTIONS.map((o) => o.label)}
                onChange={(e) => {
                  const found = SLA_OPTIONS.find((o) => o.label === e.target.value);
                  setSlaFilter(found ? found.value : "");
                }}
              />
            </div>

            {hasActiveFilters && (
              <button
                type="button"
                onClick={clearFilters}
                className={`px-3 py-3 rounded-lg text-xs font-bold shrink-0 cursor-pointer transition-colors ${
                  isDark
                    ? "text-yellow-400 hover:bg-white/5"
                    : "text-pup-dark-maroon hover:bg-red-50"
                }`}
              >
                Reset
              </button>
            )}
          </div>
        </div>
      </div>

      {/* Ticket List */}
      <div
        className={`rounded-2xl border overflow-hidden ${
          isDark
            ? "bg-[#242526] border-[#3e4042]"
            : "bg-white border-gray-200/80 shadow-sm"
        }`}
      >
        {loading ? (
          <div className="p-10 text-center space-y-3">
            <ArrowPathIcon
              className={`w-7 h-7 mx-auto animate-spin ${
                isDark ? "text-yellow-400" : "text-pup-dark-maroon"
              }`}
            />
            <p className={`text-xs ${isDark ? "text-gray-400" : "text-gray-500"}`}>
              Loading support ticket queue…
            </p>
          </div>
        ) : tickets.length === 0 ? (
          <div className="p-12 text-center">
            <div
              className={`w-12 h-12 rounded-2xl mx-auto mb-3 flex items-center justify-center ${
                isDark ? "bg-[#18191a] text-gray-400" : "bg-gray-100 text-gray-400"
              }`}
            >
              {hasActiveFilters ? (
                <FunnelIcon className="w-6 h-6" />
              ) : (
                <InboxIcon className="w-6 h-6" />
              )}
            </div>
            <h3 className={`text-sm font-bold mb-1 ${isDark ? "text-white" : "text-gray-900"}`}>
              {hasActiveFilters
                ? "No tickets match these filters"
                : scope === "unclaimed"
                  ? "Unclaimed queue is clear"
                  : scope === "mine"
                    ? "You have no claimed tickets right now"
                    : "No tickets found"}
            </h3>
            <p className={`text-xs max-w-md mx-auto ${isDark ? "text-gray-400" : "text-gray-500"}`}>
              {hasActiveFilters
                ? "Try clearing one or more filters or switching queue scope."
                : "New inquiries will appear here automatically as requesters submit them."}
            </p>
          </div>
        ) : (
          <div className={`divide-y ${isDark ? "divide-[#3e4042]" : "divide-gray-100"}`}>
            {tickets.map((ticket) => {
              const showClaimBtn =
                canClaim &&
                !ticket.is_claimed &&
                ticket.status !== "closed" &&
                ticket.status !== "resolved";

              return (
                <div
                  key={ticket.id}
                  onClick={() => navigate(`${baseTicketsPath}/${ticket.id}`)}
                  role="button"
                  tabIndex={0}
                  onKeyDown={(e) => {
                    if (e.key === "Enter" || e.key === " ") {
                      e.preventDefault();
                      navigate(`${baseTicketsPath}/${ticket.id}`);
                    }
                  }}
                  className={`p-4 sm:p-5 transition-colors cursor-pointer ${
                    isDark ? "hover:bg-[#2c2d2f]" : "hover:bg-gray-50/90"
                  }`}
                >
                  <div className="flex flex-col md:flex-row md:items-start justify-between gap-3">
                    <div className="min-w-0 flex-1 space-y-2">
                      {/* Top badges row */}
                      <div className="flex flex-wrap items-center gap-2">
                        <span
                          className={`font-mono text-xs font-bold px-2 py-0.5 rounded-md ${
                            isDark
                              ? "bg-[#18191a] text-yellow-400 border border-[#3e4042]"
                              : "bg-red-50 text-pup-dark-maroon border border-red-100"
                          }`}
                        >
                          {ticket.ticket_number}
                        </span>

                        <span
                          className={`px-2.5 py-0.5 rounded-full text-[11px] font-bold border ${getPriorityBadgeClass(
                            ticket.priority,
                            isDark
                          )}`}
                        >
                          {formatPriorityLabel(ticket.priority)}
                        </span>

                        <span
                          className={`px-2.5 py-0.5 rounded-full text-[11px] font-semibold border ${getStatusBadgeClass(
                            ticket.status,
                            isDark
                          )}`}
                        >
                          {ticket.status_label || ticket.status}
                        </span>

                        {ticket.category && (
                          <span
                            className={`text-[11px] px-2 py-0.5 rounded-md ${
                              isDark
                                ? "bg-zinc-800 text-gray-300"
                                : "bg-gray-100 text-gray-700"
                            }`}
                          >
                            {ticket.category.code} · {ticket.category.name}
                          </span>
                        )}

                        {ticket.awaiting_first_response && (
                          <span
                            className={`inline-flex items-center gap-1 text-[11px] font-semibold px-2 py-0.5 rounded-md border ${
                              isDark
                                ? "bg-amber-950/40 text-amber-300 border-amber-800/50"
                                : "bg-amber-50 text-amber-800 border-amber-200"
                            }`}
                          >
                            <ExclamationTriangleIcon className="w-3 h-3" />
                            Awaiting 1st Response
                          </span>
                        )}

                        {ticket.requester_returns > 0 && (
                          <span
                            className={`text-[11px] font-semibold px-2 py-0.5 rounded-md border ${
                              isDark
                                ? "bg-rose-950/40 text-rose-300 border-rose-800/50"
                                : "bg-rose-50 text-rose-700 border-rose-200"
                            }`}
                          >
                            Returned ×{ticket.requester_returns}
                          </span>
                        )}
                      </div>

                      {/* Subject */}
                      <h3
                        className={`text-sm sm:text-base font-bold truncate ${
                          isDark ? "text-[#e4e6eb]" : "text-gray-900"
                        }`}
                      >
                        {ticket.subject}
                      </h3>

                      {/* Requester & Timestamps */}
                      <div
                        className={`flex flex-wrap items-center gap-x-4 gap-y-1 text-xs ${
                          isDark ? "text-[#b0b3b8]" : "text-gray-600"
                        }`}
                      >
                        <span className="inline-flex items-center gap-1.5 font-medium">
                          {ticket.requester?.type === "public" ? (
                            <GlobeAltIcon className="w-3.5 h-3.5 text-sky-500" />
                          ) : (
                            <UserIcon className="w-3.5 h-3.5" />
                          )}
                          <span>{ticket.requester?.name || "Requester"}</span>
                          {ticket.requester?.type === "public" && (
                            <span
                              className={`text-[10px] font-bold uppercase px-1.5 py-0.5 rounded ${
                                isDark
                                  ? "bg-sky-950/60 text-sky-300 border border-sky-800/60"
                                  : "bg-sky-50 text-sky-700 border border-sky-200"
                              }`}
                            >
                              Public
                            </span>
                          )}
                        </span>

                        <span>
                          Filed {formatTicketRelative(ticket.created_at)}
                        </span>

                        {ticket.last_activity_at && (
                          <span>
                            Last activity {formatTicketRelative(ticket.last_activity_at)}
                          </span>
                        )}
                      </div>

                      {/* Backend SLA Indicators */}
                      {ticket.sla && (
                        <div className="flex flex-wrap items-center gap-2 pt-0.5">
                          {ticket.sla.paused && (
                            <span
                              className={`inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-[11px] font-semibold border ${getSlaBadgeClass(
                                "paused",
                                isDark
                              )}`}
                            >
                              SLA Paused (Pending Client)
                            </span>
                          )}
                          {renderSlaPill(
                            "1st Resp",
                            ticket.sla.first_response,
                            ticket.sla.paused
                          )}
                          {renderSlaPill(
                            "Resolution",
                            ticket.sla.resolution,
                            ticket.sla.paused
                          )}
                        </div>
                      )}
                    </div>

                    {/* Right side: Claim state / Quick Claim button */}
                    <div className="flex md:flex-col items-center md:items-end justify-between gap-2 shrink-0">
                      {ticket.is_claimed && ticket.claimed_by ? (
                        <span
                          className={`inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-xs font-semibold border ${
                            ticket.claimed_by.is_me
                              ? isDark
                                ? "bg-yellow-400/15 text-yellow-300 border-yellow-400/40"
                                : "bg-red-50 text-pup-dark-maroon border-red-200"
                              : isDark
                                ? "bg-[#18191a] text-gray-300 border-[#3e4042]"
                                : "bg-gray-100 text-gray-700 border-gray-200"
                          }`}
                        >
                          <HandRaisedIcon className="w-3.5 h-3.5" />
                          {ticket.claimed_by.is_me
                            ? "Claimed by You"
                            : `Claimed by ${ticket.claimed_by.name}`}
                        </span>
                      ) : (
                        <span
                          className={`text-xs font-medium ${
                            isDark ? "text-gray-400" : "text-gray-400"
                          }`}
                        >
                          Unclaimed
                        </span>
                      )}

                      {showClaimBtn && (
                        <button
                          type="button"
                          onClick={(e) => handleQuickClaim(e, ticket)}
                          disabled={claimingId === ticket.id || cooldown.isCoolingDown}
                          className={`inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-xl text-xs font-bold shadow-sm transition cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed ${
                            isDark
                              ? "bg-yellow-400 text-black hover:bg-yellow-300"
                              : "bg-pup-dark-maroon text-white hover:bg-[#5c0000]"
                          }`}
                        >
                          <HandRaisedIcon className="w-3.5 h-3.5" />
                          {claimingId === ticket.id ? "Claiming…" : "Claim Ticket"}
                        </button>
                      )}
                    </div>
                  </div>
                </div>
              );
            })}
          </div>
        )}

        {/* Cursor Load More Footer */}
        {nextCursor && !loading && (
          <div
            className={`p-4 border-t text-center ${
              isDark ? "border-[#3e4042] bg-[#1f2022]" : "border-gray-100 bg-gray-50/60"
            }`}
          >
            <button
              type="button"
              onClick={() => fetchQueue({ cursor: nextCursor, append: true })}
              disabled={loadingMore || cooldown.isCoolingDown}
              className={`px-5 py-2 rounded-xl text-xs font-bold border transition cursor-pointer disabled:opacity-50 ${
                isDark
                  ? "bg-[#242526] border-[#3e4042] text-yellow-400 hover:bg-[#303134]"
                  : "bg-white border-gray-200 text-pup-dark-maroon hover:bg-gray-50"
              }`}
            >
              {loadingMore ? "Loading more tickets…" : "Load More Tickets"}
            </button>
          </div>
        )}
      </div>

      <SuccessToast message={successMsg} onClose={() => setSuccessMsg("")} />
      <ErrorToast message={errorMsg} onClose={() => setErrorMsg("")} />
    </div>
  );
};

export default StaffTicketQueuePanel;
