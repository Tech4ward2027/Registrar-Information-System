import React, { useState, useEffect, useCallback, useRef, Fragment } from "react";
import {
  ChevronLeftIcon,
  ChevronRightIcon,
  ChevronDownIcon,
  ChevronUpIcon,
  MagnifyingGlassIcon,
  ShieldExclamationIcon,
  ArrowPathIcon,
  ArrowDownTrayIcon,
  CalendarIcon,
  SparklesIcon,
  DocumentCheckIcon,
} from "@heroicons/react/24/outline";
import VoiceSearchInput from "../components/VoiceSearchInput.jsx";
import {
  getAuditLogs, getAuditLogFilters,
  getSecurityEvents, getSecurityEventFilters,
  getFreeRequestMonthlyVolumeReport,
} from "../services/api";
import ErrorToast from "../components/ErrorToast";
import SuccessToast from "../components/SuccessToast";
import LogbookDateRangeModal from "../components/LogbookDateRangeModal.jsx";
import { useTheme } from "../context/ThemeContext";
import { ReportTableSkeleton } from '../components/LoadingSkeleton';
import DashboardDropdown from "../components/DashboardDropdown.jsx";
import auditLogSheet from "../utils/auditLogSheet.js";

const PER_PAGE = 10;

const getRoleBadgeClasses = (roleName, isDark) => {
  const role = String(roleName || "").trim().toLowerCase();

  if (role.includes("super")) { // Super Admin
    if (isDark) {
      return 'bg-red-950/40 text-red-400 border-red-800/50';
    }
    return 'bg-red-50 text-[#8B0000]/70 border-red-200';
  }

  if (role.includes("student")) { // Student
    if (isDark) {
      return 'bg-yellow-950/30 text-yellow-400 border-yellow-900/50';
    }
    return 'bg-yellow-50 text-yellow-800 border-yellow-200';
  }

  if (role.includes("alumni")) { // Alumni
    if (isDark) {
      return 'bg-green-950/40 text-green-400 border-green-800/50';
    }
    return 'bg-green-50 text-green-700 border-green-200';
  }

  // Admin / Default
  if (isDark) {
    return 'bg-[#8B0000]/20 text-[#ffb3b3] border-[#8B0000]/30';
  }
  return 'bg-[#8B0000]/10 text-[#8B0000] border-[#8B0000]/20';
};

// Security-event badge: quieter palette on purpose (per plan doc 3g —
// "a small, quiet list, not a dashboard"). Amber only for the reasons
// that plausibly indicate an actual attempt against a real account
// (bad password / IDP unreachable); everything else (e.g. an email that
// doesn't exist) stays neutral-gray so a single typo doesn't read as
// alarming as a real brute-force signal.
const getEventBadgeClasses = (eventType, isDark) => {
  const type = String(eventType || "").trim().toLowerCase();

  if (type.includes("idp")) {
    if (isDark) return 'bg-orange-950/40 text-orange-400 border-orange-800/50';
    return 'bg-orange-50 text-orange-700 border-orange-200';
  }

  // login_failed and anything else
  if (isDark) return 'bg-[#2a2a2f] text-[#e4e6eb] border-[#3e4042]';
  return 'bg-gray-100 text-gray-600 border-gray-200';
};

const getReasonBadgeClasses = (reason, isDark) => {
  const r = String(reason || "").trim().toLowerCase();

  if (r.includes("bad password")) {
    if (isDark) return 'bg-red-950/40 text-red-400 border-red-800/50';
    return 'bg-red-50 text-red-700 border-red-200';
  }

  if (isDark) return 'bg-[#2a2a2f] text-[#b0b3b8] border-[#3e4042]';
  return 'bg-gray-50 text-gray-500 border-gray-200';
};

// Phase 4 — Cashier Verification Failure Diagnostics. Small status badge
// shared by the "final result" pill and the enrichment status pill inside
// the expandable detail row. Kept muted (no red) even for a failure —
// this is diagnostic data for staff, not an alarm.
const getStatusBadgeClasses = (status, isDark) => {
  const s = String(status || "").trim().toLowerCase();

  if (s === "approved" || s === "complete") {
    if (isDark) return "bg-green-950/40 text-green-400 border-green-800/50";
    return "bg-green-50 text-green-700 border-green-200";
  }

  if (s === "failed") {
    if (isDark) return "bg-orange-950/40 text-orange-400 border-orange-800/50";
    return "bg-orange-50 text-orange-700 border-orange-200";
  }

  // not_found / rejected / pending / anything else — neutral
  if (isDark) return "bg-[#2a2a2f] text-[#b0b3b8] border-[#3e4042]";
  return "bg-gray-100 text-gray-600 border-gray-200";
};

const getActionBadgeClasses = (actionName, isDark) => {
  const act = String(actionName || "").trim().toLowerCase();

  if (act.includes('delete') || act.includes('revok') || act.includes('expir') || act.includes('reject')) {
    if (isDark) {
      return 'bg-rose-950/40 text-rose-400 border-rose-800/50';
    }
    return 'bg-rose-50 text-rose-700 border-rose-200';
  }

  if (act.includes('creat') || act.includes('assign') || act.includes('grant') || act.includes('approv') || act.includes('restor')) {
    if (isDark) {
      return 'bg-emerald-950/40 text-emerald-400 border-emerald-800/50';
    }
    return 'bg-emerald-50 text-emerald-700 border-emerald-200';
  }

  if (act.includes('login') || act.includes('logout') || act.includes('switch')) {
    if (isDark) {
      return 'bg-blue-950/40 text-blue-400 border-blue-800/50';
    }
    return 'bg-blue-50 text-blue-700 border-blue-200';
  }

  if (act.includes('status') || act.includes('chang') || act.includes('edit') || act.includes('updat')) {
    if (isDark) {
      return 'bg-amber-950/40 text-amber-400 border-amber-800/50';
    }
    return 'bg-amber-50 text-amber-800 border-amber-200';
  }

  if (isDark) {
    return 'bg-[#2a2a2f] text-[#e4e6eb] border-[#3e4042]';
  }
  return 'bg-gray-100 text-gray-700 border-gray-200';
};

const formatLabel = (str) => {
  if (!str) return "";
  return str
    .split("_")
    .map(word => word.charAt(0).toUpperCase() + word.slice(1))
    .join(" ");
};

// -------------------------------------------------------
// Phase 4 — Cashier Verification Failure Diagnostics.
//
// Renders the expanded detail panel for a single cashier_verification
// audit log row: every name-candidate attempt RIS tried against the
// Cashier System, the final result, and — only on a genuine failure —
// the OGOS/alumni-system snapshot fetched asynchronously afterward.
//
// Surfaced as raw data (on-file name vs. each candidate tried), never an
// auto-generated "fault" verdict — name matching is inherently fuzzy
// (see NameMatcher's docblock on the backend). Staff read this and
// decide for themselves, the same way they would after querying OGOS by
// hand — this panel just saves them that manual step.
// -------------------------------------------------------
const CashierVerificationDetail = ({ log, isDark }) => {
  const meta = log.metadata || {};
  const attempts = Array.isArray(meta.attempts) ? meta.attempts : [];
  const enrichment = log.enrichment;

  const labelClass = isDark ? 'text-[#9a9a9a]' : 'text-gray-500';
  const valueClass = isDark ? 'text-[#e4e6eb]' : 'text-gray-800';
  const cardClass = `rounded-lg border p-3 ${isDark ? 'bg-[#1c1c1f] border-[#3e4042]' : 'bg-white border-gray-200'}`;

  return (
    <div className="flex flex-col gap-4 max-w-3xl">
      {/* Summary line */}
      <div className="flex flex-wrap items-center gap-2">
        <span className={`text-xs font-semibold ${labelClass}`}>OR #</span>
        <span className={`text-sm font-mono ${valueClass}`}>{meta.or_number ?? '—'}</span>
        <span className={`px-2 py-0.5 rounded-full text-xs font-bold border whitespace-nowrap ${getStatusBadgeClasses(meta.final_approved ? 'approved' : 'rejected', isDark)}`}>
          {meta.final_approved ? 'Approved' : 'Rejected'}
        </span>
        {meta.is_mock && (
          <span className={`px-2 py-0.5 rounded-full text-xs font-medium border whitespace-nowrap ${isDark ? 'bg-[#2a2a2f] text-[#9a9a9a] border-[#3e4042]' : 'bg-gray-100 text-gray-500 border-gray-200'}`}>
            Mock mode
          </span>
        )}
      </div>

      {/* Name candidates tried against the Cashier System */}
      {attempts.length > 0 && (
        <div>
          <p className={`text-xs font-semibold mb-2 ${labelClass}`}>Name candidates tried</p>
          <div className="flex flex-col gap-1.5">
            {attempts.map((attempt, i) => (
              <div key={i} className={`flex items-center justify-between gap-3 text-xs px-3 py-2 rounded-lg border ${isDark ? 'bg-[#1c1c1f] border-[#3e4042]' : 'bg-white border-gray-200'}`}>
                <span className={valueClass}>{attempt.name || '—'}</span>
                <span className={`px-2 py-0.5 rounded-full font-bold border whitespace-nowrap ${getStatusBadgeClasses(attempt.valid ? 'approved' : 'rejected', isDark)}`}>
                  {attempt.valid ? 'Matched' : (attempt.reason ? formatLabel(attempt.reason) : 'No match')}
                </span>
              </div>
            ))}
          </div>
        </div>
      )}

      {/* Enrichment — only shown for a genuine failure, matches the
          backend's own gating (only NOT_FOUND, non-mock attempts ever
          get a job dispatched — see verifyReceiptAgainstCashier()). */}
      {!meta.final_approved && !meta.is_mock && (
        <div>
          <p className={`text-xs font-semibold mb-2 ${labelClass}`}>On-file record</p>

          {!enrichment ? (
            <div className={`${cardClass} text-xs ${labelClass}`}>
              Looking this up in the background — check back in a moment.
            </div>
          ) : enrichment.enrichment_status === 'complete' ? (
            <div className={cardClass}>
              <div className="flex items-center justify-between mb-2">
                <span className={`text-xs font-medium ${labelClass}`}>
                  On file in {enrichment.source_system === 'ogos' ? 'OGOS' : 'the Alumni System'}
                </span>
                <span className={`px-2 py-0.5 rounded-full text-xs font-bold border whitespace-nowrap ${getStatusBadgeClasses('complete', isDark)}`}>
                  Snapshot retrieved
                </span>
              </div>
              <p className={`text-sm ${valueClass}`}>
                {[
                  enrichment.on_file_snapshot?.last_name,
                  enrichment.on_file_snapshot?.first_name,
                  enrichment.on_file_snapshot?.middle_name,
                  enrichment.on_file_snapshot?.suffix,
                ].filter(Boolean).join(', ') || '—'}
              </p>
              {(enrichment.on_file_snapshot?.student_number || enrichment.on_file_snapshot?.stud_number) && (
                <p className={`text-xs mt-1 ${labelClass}`}>
                  Student No. {enrichment.on_file_snapshot?.student_number || enrichment.on_file_snapshot?.stud_number}
                </p>
              )}
            </div>
          ) : enrichment.enrichment_status === 'not_found' ? (
            <div className={`${cardClass} text-xs ${labelClass}`}>
              No matching record found in {enrichment.source_system === 'ogos' ? 'OGOS' : 'the Alumni System'} for this account.
            </div>
          ) : (
            <div className={`${cardClass} text-xs ${labelClass}`}>
              Couldn't retrieve a snapshot{enrichment.failure_reason ? ` (${formatLabel(enrichment.failure_reason)})` : ''}. This can be re-checked later from the audit trail.
            </div>
          )}
        </div>
      )}
    </div>
  );
};

const ReportManagement = () => {
  const { isDark } = useTheme();

  // -------------------------------------------------------
  // Tab state — Audit Log / Security Events. Phase 3g: presented as an
  // in-page tab on the existing "Audit Trail" screen rather than a new
  // top-level nav item, matching the app's flat-nav convention (no
  // tab-based sub-navigation exists elsewhere) and the plan's intent for
  // this to read as "a small, quiet list, not a dashboard."
  // -------------------------------------------------------
  const [activeTab, setActiveTab] = useState('audit'); // 'audit' | 'security'

  // ── Audit Log state ──────────────────────────────────────────────────
  const [search, setSearch]                   = useState("");
  const [debouncedSearch, setDebouncedSearch] = useState("");
  const [roleFilter, setRoleFilter]           = useState("All");
  const [actionFilter, setActionFilter]       = useState("All");
  const [browserFilter, setBrowserFilter]     = useState("All");
  const [dateFrom, setDateFrom]               = useState("");
  const [dateTo, setDateTo]                   = useState("");
  const [activePreset, setActivePreset]       = useState("");
  const [isDateModalOpen, setIsDateModalOpen] = useState(false);
  const [currentPage, setCurrentPage]         = useState(1);
  const [logs, setLogs]                       = useState([]);
  const [totalPages, setTotalPages]           = useState(1);
  const [loading, setLoading]                 = useState(false);
  const [exporting, setExporting]             = useState(false);
  const [errorMsg, setErrorMsg]               = useState("");
  const [successMsg, setSuccessMsg]           = useState("");

  const [roleOptions, setRoleOptions]       = useState(["All"]);
  const [actionOptions, setActionOptions]   = useState(["All"]);
  const [browserOptions, setBrowserOptions] = useState(["All"]);

  // Phase 4 — which cashier_verification row (by id) has its detail panel
  // open, if any. Only one at a time, matching the plan's "small, quiet
  // list" intent — this isn't a dashboard.
  const [expandedLogId, setExpandedLogId] = useState(null);

  const [roleDropdownOpen, setRoleDropdownOpen]       = useState(false);
  const [actionDropdownOpen, setActionDropdownOpen]   = useState(false);
  const [browserDropdownOpen, setBrowserDropdownOpen] = useState(false);

  const roleDropdownRef    = useRef(null);
  const actionDropdownRef  = useRef(null);

  // ── Security Events state (Phase 3) ──────────────────────────────────
  const [seSearch, setSeSearch]             = useState("");
  const [seEventTypeFilter, setSeEventTypeFilter] = useState("All");
  const [seReasonFilter, setSeReasonFilter] = useState("All");
  const [seCurrentPage, setSeCurrentPage]   = useState(1);
  const [seEvents, setSeEvents]             = useState([]);
  const [seTotalPages, setSeTotalPages]     = useState(1);
  const [seLoading, setSeLoading]           = useState(false);

  const [seEventTypeOptions, setSeEventTypeOptions] = useState(["All"]);
  const [seReasonOptions, setSeReasonOptions]       = useState(["All"]);

  const [seEventTypeDropdownOpen, setSeEventTypeDropdownOpen] = useState(false);
  const [seReasonDropdownOpen, setSeReasonDropdownOpen] = useState(false);

  const seEventTypeDropdownRef = useRef(null);
  const seReasonDropdownRef = useRef(null);
  const browserDropdownRef = useRef(null);

  // ── Free Request Monthly Volume state (Phase 8) ──────────────────────
  const [freeReportYear] = useState(new Date().getFullYear());
  const [freeReportData, setFreeReportData] = useState([]);
  const [freeReportLoading, setFreeReportLoading] = useState(false);
  const [freeSearch, setFreeSearch] = useState("");
  const [freeTypeFilter, setFreeTypeFilter] = useState("All");
  const [freeCurrentPage, setFreeCurrentPage] = useState(1);
  const freeTypeDropdownRef = useRef(null);
  const [freeTypeDropdownOpen, setFreeTypeDropdownOpen] = useState(false);

  const fetchFreeReport = useCallback(async () => {
    setFreeReportLoading(true);
    try {
      const res = await getFreeRequestMonthlyVolumeReport(freeReportYear);
      setFreeReportData(res.data?.data || res.data || []);
    } catch (err) {
      console.warn("Failed to load free requests volume report:", err);
      setErrorMsg(err.response?.data?.message || "Failed to load monthly volume report.");
    } finally {
      setFreeReportLoading(false);
    }
  }, [freeReportYear]);

  useEffect(() => {
    if (activeTab === 'free_requests') {
      fetchFreeReport();
    }
  }, [activeTab, fetchFreeReport]);

  const freeTypeOptions = [...new Set(freeReportData.map((row) => row.type_label || row.name).filter(Boolean))];

  const filteredFreeData = freeReportData.filter((row) => {
    const timestamp = row.month_name || row.month || "";
    const typeLabel = row.type_label || row.name || "";
    if (freeTypeFilter !== "All" && typeLabel !== freeTypeFilter) return false;
    if (!freeSearch.trim()) return true;
    const q = freeSearch.toLowerCase().trim();
    const month = timestamp.toLowerCase();
    const normalizedTypeLabel = typeLabel.toLowerCase();
    const isGrad = normalizedTypeLabel.includes("graduation") || normalizedTypeLabel.includes("transcript") || normalizedTypeLabel.includes("tor");
    const policyLabel = isGrad ? "one-time free copy (graduates)" : "unlimited free (loa)";
    return month.includes(q) || normalizedTypeLabel.includes(q) || policyLabel.includes(q);
  });

  const formatFreeTimestamp = (row) => {
    const rawTimestamp = row.timestamp || row.claimed_at || row.changed_at;
    if (rawTimestamp) {
      const parsed = new Date(rawTimestamp);
      if (!Number.isNaN(parsed.getTime())) {
        const pad = (value) => String(value).padStart(2, "0");
        return `${parsed.getFullYear()}-${pad(parsed.getMonth() + 1)}-${pad(parsed.getDate())}`;
      }
    }

    const month = row.month_name || row.month;
    return /^\d{4}-\d{2}$/.test(month || "") ? `${month}-01` : month || "—";
  };

  const freeTotalPages = Math.max(1, Math.ceil(filteredFreeData.length / PER_PAGE));
  const safeFreeCurrentPage = Math.min(freeCurrentPage, freeTotalPages);
  const paginatedFreeData = filteredFreeData.slice(
    (safeFreeCurrentPage - 1) * PER_PAGE,
    safeFreeCurrentPage * PER_PAGE
  );

  useEffect(() => {
    const handler = setTimeout(() => {
      setDebouncedSearch(search);
    }, 350);
    return () => clearTimeout(handler);
  }, [search]);

  useEffect(() => {
    const handleClickOutside = (event) => {
      if (roleDropdownRef.current && !roleDropdownRef.current.contains(event.target)) {
        setRoleDropdownOpen(false);
      }
      if (actionDropdownRef.current && !actionDropdownRef.current.contains(event.target)) {
        setActionDropdownOpen(false);
      }
      if (seEventTypeDropdownRef.current && !seEventTypeDropdownRef.current.contains(event.target)) {
        setSeEventTypeDropdownOpen(false);
      }
      if (seReasonDropdownRef.current && !seReasonDropdownRef.current.contains(event.target)) {
        setSeReasonDropdownOpen(false);
      }
      if (browserDropdownRef.current && !browserDropdownRef.current.contains(event.target)) {
        setBrowserDropdownOpen(false);
      }
      if (freeTypeDropdownRef.current && !freeTypeDropdownRef.current.contains(event.target)) {
        setFreeTypeDropdownOpen(false);
      }
    };
    document.addEventListener('mousedown', handleClickOutside);
    return () => document.removeEventListener('mousedown', handleClickOutside);
  }, []);

  // -------------------------------------------------------
  // Load Audit Log filter dropdown options once on mount
  // -------------------------------------------------------
  useEffect(() => {
    const loadFilters = async () => {
      try {
        const res = await getAuditLogFilters();
        setRoleOptions(["All", ...(res.data.roles || [])]);
        setActionOptions(["All", ...(res.data.actions || [])]);
        setBrowserOptions(["All", ...(res.data.browsers || [])]);
      } catch (err) {
        setErrorMsg("Failed to load filter options.");
      }
    };
    loadFilters();
  }, []);

  // -------------------------------------------------------
  // Load Security Events filter dropdown options once on mount
  // -------------------------------------------------------
  useEffect(() => {
    const loadSeFilters = async () => {
      try {
        const res = await getSecurityEventFilters();
        setSeEventTypeOptions(["All", ...res.data.event_types]);
        setSeReasonOptions(["All", ...res.data.reasons]);
      } catch (err) {
        setErrorMsg("Failed to load security event filter options.");
      }
    };
    loadSeFilters();
  }, []);

  // -------------------------------------------------------
  // Fetch audit logs whenever filters, date range, or page changes
  // -------------------------------------------------------
  const fetchLogs = useCallback(async () => {
    setLoading(true);
    try {
      const res = await getAuditLogs({
        search:   debouncedSearch || undefined,
        role:     roleFilter    !== "All" ? roleFilter    : undefined,
        action:   actionFilter  !== "All" ? actionFilter  : undefined,
        browser:  browserFilter !== "All" ? browserFilter : undefined,
        from:     dateFrom || undefined,
        to:       dateTo   || undefined,
        page:     currentPage,
        per_page: PER_PAGE,
      });
      setLogs(res.data.data || []);
      setTotalPages(res.data.meta?.last_page || 1);
    } catch (err) {
      setErrorMsg(err.response?.data?.message || "Failed to load audit logs.");
    } finally {
      setLoading(false);
    }
  }, [debouncedSearch, roleFilter, actionFilter, browserFilter, dateFrom, dateTo, currentPage, search]);

  useEffect(() => {
    if (activeTab === 'audit') fetchLogs();
  }, [fetchLogs, activeTab]);

  // -------------------------------------------------------
  // Fetch security events whenever filters or page changes
  // -------------------------------------------------------
  const fetchSecurityEvents = useCallback(async () => {
    setSeLoading(true);
    try {
      const res = await getSecurityEvents({
        search:     seSearch || undefined,
        event_type: seEventTypeFilter !== "All" ? seEventTypeFilter : undefined,
        reason:     seReasonFilter    !== "All" ? seReasonFilter    : undefined,
        page:       seCurrentPage,
        per_page:   PER_PAGE,
      });
      setSeEvents(res.data.data);
      setSeTotalPages(res.data.meta.last_page);
    } catch (err) {
      setErrorMsg(err.response?.data?.message || "Failed to load security events.");
    } finally {
      setSeLoading(false);
    }
  }, [seSearch, seEventTypeFilter, seReasonFilter, seCurrentPage]);

  useEffect(() => {
    if (activeTab === 'security') fetchSecurityEvents();
  }, [fetchSecurityEvents, activeTab]);

  // Reset to page 1 when filters change
  const handleFilterChange = () => setCurrentPage(1);
  const handleSeFilterChange = () => setSeCurrentPage(1);

  const handleApplyDateFilter = (start, end, preset) => {
    setDateFrom(start);
    setDateTo(end);
    setActivePreset(preset);
    handleFilterChange();
    setIsDateModalOpen(false);
  };

  const handleExportSheet = async () => {
    if (exporting) return;
    setExporting(true);
    try {
      let allLogs = [];
      let page = 1;
      let lastPage = 1;
      const MAX_PAGES = 50;

      while (page <= lastPage && page <= MAX_PAGES) {
        const res = await getAuditLogs({
          search:   debouncedSearch || undefined,
          role:     roleFilter    !== "All" ? roleFilter    : undefined,
          action:   actionFilter  !== "All" ? actionFilter  : undefined,
          browser:  browserFilter !== "All" ? browserFilter : undefined,
          from:     dateFrom || undefined,
          to:       dateTo   || undefined,
          page:     page,
          per_page: 100,
        });

        const data = res.data.data || [];
        allLogs = allLogs.concat(data);
        lastPage = res.data.meta?.last_page || 1;
        if (data.length === 0 || page >= lastPage) break;
        page++;
      }

      if (allLogs.length === 0) {
        setErrorMsg("No logs available to export.");
        return;
      }

      const rangeLabel =
        dateFrom && dateTo
          ? `${dateFrom}_to_${dateTo}`
          : dateFrom
          ? `from_${dateFrom}`
          : dateTo
          ? `to_${dateTo}`
          : null;

      await auditLogSheet(allLogs, {
        dateRangeLabel: rangeLabel,
        roleFilter,
        actionFilter,
        browserFilter,
        search: debouncedSearch,
      });

      setSuccessMsg("Exporting Report completed.");
    } catch (err) {
      console.error("Export to Spreadsheet failed", err);
      setErrorMsg("Exporting Report failed.");
    } finally {
      setExporting(false);
    }
  };

  const isFiltered =
    roleFilter !== "All" ||
    actionFilter !== "All" ||
    browserFilter !== "All" ||
    dateFrom !== "" ||
    dateTo !== "" ||
    search.trim() !== "";

  useEffect(() => {
    const handleClickOutside = (event) => {
      if (roleDropdownRef.current && !roleDropdownRef.current.contains(event.target)) {
        setRoleDropdownOpen(false);
      }
      if (actionDropdownRef.current && !actionDropdownRef.current.contains(event.target)) {
        setActionDropdownOpen(false);
      }
      if (seEventTypeDropdownRef.current && !seEventTypeDropdownRef.current.contains(event.target)) {
        setSeEventTypeDropdownOpen(false);
      }
      if (seReasonDropdownRef.current && !seReasonDropdownRef.current.contains(event.target)) {
        setSeReasonDropdownOpen(false);
      }
      if (browserDropdownRef.current && !browserDropdownRef.current.contains(event.target)) {
        setBrowserDropdownOpen(false);
      }
    };
    document.addEventListener("mousedown", handleClickOutside);
    return () => document.removeEventListener("mousedown", handleClickOutside);
  }, []);

  const pageNumbers = (total, current) => {
    if (total <= 6) return Array.from({ length: total }, (_, i) => i + 1);
    const pages = [1, 2, 3];
    if (current > 4) pages.push("...");
    if (current > 3 && current < total - 2) pages.push(current);
    pages.push("...", total - 1, total);
    return [...new Set(pages)];
  };

  return (
    <div className="w-full flex flex-col font-sans">
      <div className="hidden md:flex justify-center mx-4 sm:mx-6 mb-5">
        <div className={`inline-flex px-8 py-3.5 rounded-full transition-all duration-300 hover:-translate-y-0.5 ${isDark
          ? 'bg-[#242526] border border-[#3e4042] shadow-[0_2px_8px_rgba(0,0,0,0.2)] hover:shadow-[0_4px_16px_rgba(0,0,0,0.35)]'
          : 'bg-white border border-gray-200/80 shadow-[0_2px_8px_rgba(0,0,0,0.05)] hover:shadow-[0_4px_16px_rgba(0,0,0,0.1)]'
        } gap-8 items-center`}>
          {[
            { key: 'audit', label: 'Audit Log' },
            { key: 'security', label: 'Security Events' },
            { key: 'free_requests', label: 'Free Issuance Volume' },
          ].map((tab) => {
            return (
              <button
                key={tab.key}
                type="button"
                onClick={() => setActiveTab(tab.key)}
                className={`text-sm relative rounded-full flex items-center justify-center gap-2 shrink-0 font-semibold transition-all duration-200 hover:scale-105 active:scale-95 cursor-pointer whitespace-nowrap ${activeTab === tab.key
                  ? isDark
                    ? 'text-yellow-400 font-bold'
                    : 'text-pup-dark-maroon font-black'
                  : isDark
                    ? 'text-[#b0b3b8] hover:text-white'
                    : 'text-gray-500 hover:text-gray-900'
                }`}
              >
                <span>{tab.label}</span>
              </button>
            );
          })}
        </div>
      </div>

      <div className={`rounded-2xl p-4 sm:p-6 ${
        isDark 
          ? 'bg-[#242526] text-[#e4e6eb] border border-[#3e4042]' 
          : 'bg-white text-gray-900 shadow-md border border-gray-200/80'
      }`}>

        {activeTab === 'audit' ? (
          <>
            <div className="flex flex-col lg:flex-row lg:items-center justify-between gap-3 mb-6">
                <div className="flex-1 min-w-0 max-w-full sm:max-w-xs">
                  <VoiceSearchInput
                    value={search}
                    onChange={(value) => {
                      setSearch(value);
                      handleFilterChange();
                    }}
                  placeholder="Search"
                  />
                </div>

                <div className="flex flex-wrap items-center gap-2.5">
            <button
              type="button"
              onClick={() => setIsDateModalOpen(true)}
              className={`px-3 py-2 rounded-lg text-xs font-semibold border shadow-sm flex items-center gap-1.5 transition-colors cursor-pointer ${
                dateFrom || dateTo
                  ? isDark
                    ? 'bg-yellow-950/40 text-yellow-400 border-yellow-800/50'
                    : 'bg-yellow-50 text-[#8B0000] border-yellow-300'
                  : isDark
                  ? 'bg-[#1f1f1f] text-[#b0b3b8] border-[#3e4042] hover:bg-[#2a2a2f] hover:text-[#e4e6eb]'
                  : 'bg-white text-gray-700 border-gray-200 hover:bg-gray-50'
              }`}
            >
              <CalendarIcon className="w-4 h-4" />
              <span>
                {dateFrom && dateTo
                  ? `${dateFrom} to ${dateTo}`
                  : dateFrom
                  ? `From ${dateFrom}`
                  : dateTo
                  ? `To ${dateTo}`
                  : "Date Range"}
              </span>
            </button>

            <button
              type="button"
              onClick={handleExportSheet}
              disabled={loading || exporting || logs.length === 0}
              title="Export report to Spreadsheet (.xlsx)"
              className={`px-3 py-2 rounded-lg text-xs font-bold uppercase tracking-wider border shadow-sm flex items-center gap-1.5 transition-colors cursor-pointer disabled:opacity-50 ${
                isDark
                  ? 'bg-[#800000] text-white hover:bg-[#6b0000] border-[#9a0000]'
                  : 'bg-[#800000] text-white hover:bg-[#6b0000] border-[#800000]'
              }`}
            >
              <ArrowDownTrayIcon className={`w-4 h-4 ${exporting ? 'animate-bounce' : ''}`} />
              <span>{exporting ? 'Exporting…' : 'Export Sheet'}</span>
            </button>

            <button
              type="button"
              onClick={() => fetchLogs()}
              disabled={loading}
              title="Refresh logs"
              className={`p-2 rounded-lg border shadow-sm flex items-center justify-center transition-colors cursor-pointer disabled:opacity-50 ${
                isDark
                  ? 'bg-[#1f1f1f] text-[#b0b3b8] border-[#3e4042] hover:bg-[#2a2a2f] hover:text-[#e4e6eb]'
                  : 'bg-white text-gray-700 border-gray-200 hover:bg-gray-50'
              }`}
            >
              <ArrowPathIcon className={`w-4 h-4 ${loading ? 'animate-spin text-yellow-500' : ''}`} />
            </button>

            {isFiltered && (
                    <button
                      type="button"
                      onClick={() => {
                        setRoleFilter('All');
                        setActionFilter('All');
                  setBrowserFilter('All');
                  setDateFrom('');
                  setDateTo('');
                  setActivePreset('');
                        setSearch('');
                  setDebouncedSearch('');
                        setCurrentPage(1);
                      }}
                      className={`px-3 py-2 rounded-lg text-xs font-semibold transition-colors border shadow-sm flex items-center justify-center cursor-pointer shrink-0 ${
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

        {/* Table */}
        <div className={`rounded-2xl overflow-hidden ${isDark ? 'bg-[#242526] border border-[#3e4042] shadow-none' : 'bg-white shadow-sm border border-gray-100'}`}>
          <div className="overflow-x-auto">
            <table className="w-full min-w-180 text-sm">
              <thead>
                <tr className={isDark ? 'border-b border-[#3e4042]' : 'border-b border-gray-100'}>
                  <th className={`px-4 py-3 text-center font-medium ${isDark ? 'text-[#b0b3b8]' : 'text-gray-500'}`}>Timestamp</th>
                  <th className={`px-4 py-3 text-center font-medium ${isDark ? 'text-[#b0b3b8]' : 'text-gray-500'}`}>User</th>
                  <th className="px-4 py-3 text-center">
                    <DashboardDropdown
                      isOpen={roleDropdownOpen}
                      setIsOpen={setRoleDropdownOpen}
                      dropdownRef={roleDropdownRef}
                      align="center"
                      trigger={
                        <span className={roleFilter !== 'All' ? (isDark ? 'text-yellow-400' : 'text-[#8b0000]') : (isDark ? 'text-[#b0b3b8]' : 'text-gray-500')}>
                          Role
                        </span>
                      }
                      sections={[
                        {
                          title: 'Filter by Role',
                          items: roleOptions.map(option => ({
                            label: formatLabel(option),
                            isSelected: roleFilter === option,
                            onClick: () => {
                              setRoleFilter(option);
                              handleFilterChange();
                            }
                          }))
                        }
                      ]}
                    />
                  </th>
                  <th className="px-4 py-3 text-center">
                    <DashboardDropdown
                      isOpen={actionDropdownOpen}
                      setIsOpen={setActionDropdownOpen}
                      dropdownRef={actionDropdownRef}
                      align="center"
                      trigger={
                        <span className={actionFilter !== 'All' ? (isDark ? 'text-yellow-400' : 'text-[#8b0000]') : (isDark ? 'text-[#b0b3b8]' : 'text-gray-500')}>
                          Action
                        </span>
                      }
                      sections={[
                        {
                          title: 'Filter by Action',
                          items: actionOptions.map(option => ({
                            label: formatLabel(option),
                            isSelected: actionFilter === option,
                            onClick: () => {
                              setActionFilter(option);
                              handleFilterChange();
                            }
                          }))
                        }
                      ]}
                    />
                  </th>
                  <th className="px-4 py-3 text-center">
                    <DashboardDropdown
                      isOpen={browserDropdownOpen}
                      setIsOpen={setBrowserDropdownOpen}
                      dropdownRef={browserDropdownRef}
                      align="center"
                      trigger={
                        <span className={browserFilter !== 'All' ? (isDark ? 'text-yellow-400' : 'text-[#8b0000]') : (isDark ? 'text-[#b0b3b8]' : 'text-gray-500')}>
                          Browser
                        </span>
                      }
                      sections={[
                        {
                          title: 'Filter by Browser',
                          items: browserOptions.map(option => ({
                            label: option,
                            isSelected: browserFilter === option,
                            onClick: () => {
                              setBrowserFilter(option);
                              handleFilterChange();
                            }
                          }))
                        }
                      ]}
                    />
                  </th>
                  <th className={`px-4 py-3 text-center font-medium ${isDark ? 'text-[#b0b3b8]' : 'text-gray-500'}`}>Details</th>
                </tr>
              </thead>
              <tbody>
                {loading ? (
                  <ReportTableSkeleton isDark={isDark} count={PER_PAGE} />
                ) : logs.length === 0 ? (
                  <tr>
                    <td colSpan={6} className="py-20">
                      <div className="flex flex-col items-center justify-center">
                        <div className={`w-16 h-16 mb-4 flex items-center justify-center rounded-full ${isDark ? 'bg-[#3a3b3c]/50' : 'bg-gray-100'}`}>
                          <MagnifyingGlassIcon className={`w-8 h-8 ${isDark ? 'text-[#b0b3b8]' : 'text-gray-400'}`} />
                        </div>
                    {/* Text */}
                        <h3 className={`text-sm font-bold mb-1 ${isDark ? 'text-[#e4e6eb]' : 'text-gray-800'}`}>
                          No Logs Found
                        </h3>
                        <p className={`text-xs ${isDark ? 'text-[#9a9a9a]' : 'text-gray-500'}`}>
                          No audit entries match your current search or filters.
                        </p>
                      </div>
                    </td>
                  </tr>
                ) : (
                  logs.map((log) => {
                    // Only cashier_verification rows ever have an
                    // expandable detail panel. Branch on action_key (the
                    // raw, stable constant from the backend), never on
                    // the formatted `action` label — see
                    // AuditLogController::format()'s own comment on why.
                    const isCashierVerification = log.action_key === 'cashier_verification';
                    const isExpanded = isCashierVerification && expandedLogId === log.id;

                    return (
                      <Fragment key={log.id}>
                        <tr className={`border-b text-center transition-colors ${isDark ? 'border-[#3e4042] hover:bg-[#2a2a2f]' : 'border-gray-50 hover:bg-gray-50'}`}>
                          <td className={`px-4 py-3 text-xs whitespace-nowrap ${isDark ? 'text-[#b0b3b8]' : 'text-gray-500'}`}>
                            {log.date} {log.time}
                          </td>

                          <td className={`px-4 py-3 font-medium ${isDark ? 'text-[#e4e6eb]' : 'text-gray-800'}`}>
                            {log.user}
                          </td>

                          <td className="px-4 py-3">
                            <span className={`px-3 py-1 rounded-full text-xs font-bold border whitespace-nowrap ${getRoleBadgeClasses(log.role, isDark)}`}>
                              {formatLabel(log.role)}
                            </span>
                          </td>

                          <td className="px-4 py-3">
                            <span className={`px-2.5 py-1 rounded-full text-xs font-bold border whitespace-nowrap ${getActionBadgeClasses(log.action, isDark)}`}>
                              {formatLabel(log.action)}
                            </span>
                          </td>

                          <td className={`px-4 py-3 text-xs ${isDark ? 'text-[#b0b3b8]' : 'text-gray-500'}`}>
                            {log.browser ?? "—"}
                          </td>

                          <td className="px-4 py-3">
                            {isCashierVerification ? (
                              <button
                                type="button"
                                onClick={() => setExpandedLogId(isExpanded ? null : log.id)}
                                className={`inline-flex items-center gap-1 text-xs font-medium px-2 py-1 rounded-lg transition-colors ${isDark ? 'text-[#b0b3b8] hover:bg-[#2a2a2f] hover:text-white' : 'text-gray-500 hover:bg-gray-100 hover:text-gray-800'}`}
                              >
                                {isExpanded ? (
                                  <>Hide <ChevronUpIcon className="w-3.5 h-3.5" /></>
                                ) : (
                                  <>View <ChevronDownIcon className="w-3.5 h-3.5" /></>
                                )}
                              </button>
                            ) : (
                              <span className={isDark ? 'text-[#5a5a5f]' : 'text-gray-300'}>—</span>
                            )}
                          </td>
                        </tr>

                        {isExpanded && (
                          <tr className={isDark ? 'bg-[#232326]' : 'bg-gray-50/60'}>
                            <td colSpan={6} className="px-6 py-4 text-left">
                              <CashierVerificationDetail log={log} isDark={isDark} />
                            </td>
                          </tr>
                        )}
                      </Fragment>
                    );
                  })
                )}
                    </tbody>
                  </table>
                </div>

                {/* Pagination */}
                <div className={`flex items-center justify-center gap-1 px-4 py-4 border-t ${isDark ? 'border-[#3e4042]' : 'border-gray-100'}`}>
                  <button onClick={() => setCurrentPage((p) => Math.max(1, p - 1))} disabled={currentPage === 1 || loading}
                    className={`flex items-center gap-1 text-sm px-2 py-1 disabled:opacity-40 cursor-pointer disabled:cursor-not-allowed ${isDark ? 'text-[#b0b3b8] hover:text-white' : 'text-gray-500 hover:text-gray-800'}`}>
                    <ChevronLeftIcon className="w-4 h-4" /> Previous
                  </button>
                  {pageNumbers(totalPages, currentPage).map((p, i) => (
                    <button key={i} onClick={() => typeof p === "number" && setCurrentPage(p)} disabled={p === "..." || loading}
                      className={`w-8 h-8 rounded-lg text-sm font-medium transition-colors cursor-pointer
                        ${currentPage === p ? 'bg-yellow-400 text-white' : (isDark ? 'text-[#b0b3b8] hover:bg-[#2a2a2f]' : 'text-gray-500 hover:bg-gray-100')}
                        ${p === "..." ? "cursor-default pointer-events-none" : ""}`}>
                      {p}
                    </button>
                  ))}
                  <button onClick={() => setCurrentPage((p) => Math.min(totalPages, p + 1))} disabled={currentPage === totalPages || totalPages === 0 || loading}
                    className={`flex items-center gap-1 text-sm px-2 py-1 disabled:opacity-40 cursor-pointer disabled:cursor-not-allowed ${isDark ? 'text-[#b0b3b8] hover:text-white' : 'text-gray-500 hover:text-gray-800'}`}>
                    Next <ChevronRightIcon className="w-4 h-4" />
                  </button>
                </div>
              </div>
          </>
        ) : activeTab === 'security' ? (
          /* Security Events Tab (Phase 3) */
          <>
            <div className="flex flex-col lg:flex-row lg:items-center justify-between gap-3 mb-6">
              <div className="flex-1 min-w-0 max-w-full sm:max-w-xs">
                <VoiceSearchInput
                  value={seSearch}
                  onChange={(value) => {
                    setSeSearch(value);
                    setSeCurrentPage(1);
                  }}
                  placeholder="Search"
                />
              </div>

              <div className="flex flex-wrap items-center gap-2.5">
                {(seSearch.trim() !== "" || seEventTypeFilter !== "All" || seReasonFilter !== "All") && (
                  <button
                    type="button"
                    onClick={() => {
                      setSeSearch("");
                      setSeEventTypeFilter("All");
                      setSeReasonFilter("All");
                      setSeCurrentPage(1);
                    }}
                    className={`px-3 py-2 rounded-lg text-xs font-semibold transition-colors border shadow-sm flex items-center justify-center cursor-pointer shrink-0 ${
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

            {/* Security Events Table */}
            <div className={`rounded-2xl overflow-hidden ${isDark ? 'bg-[#242526] border border-[#3e4042] shadow-none' : 'bg-white shadow-sm border border-gray-100'}`}>
              <div className="overflow-x-auto">
                <table className="w-full min-w-180 text-sm">
                  <thead>
                    <tr className={isDark ? 'border-b border-[#3e4042]' : 'border-b border-gray-100'}>
                      <th className={`px-4 py-3 text-center font-medium ${isDark ? 'text-[#b0b3b8]' : 'text-gray-500'}`}>Timestamp</th>
                      <th className={`px-4 py-3 text-center font-medium ${isDark ? 'text-[#b0b3b8]' : 'text-gray-500'}`}>Target Email</th>
                      <th className="px-4 py-3 text-center">
                        <DashboardDropdown
                          isOpen={seEventTypeDropdownOpen}
                          setIsOpen={setSeEventTypeDropdownOpen}
                          dropdownRef={seEventTypeDropdownRef}
                          align="center"
                          trigger={
                            <span className={seEventTypeFilter !== 'All' ? (isDark ? 'text-yellow-400' : 'text-[#8b0000]') : (isDark ? 'text-[#b0b3b8]' : 'text-gray-500')}>
                              Event Type
                            </span>
                          }
                          sections={[
                            {
                              title: 'Filter by Event Type',
                              items: seEventTypeOptions.map(option => ({
                                label: formatLabel(option),
                                isSelected: seEventTypeFilter === option,
                                onClick: () => {
                                  setSeEventTypeFilter(option);
                                  setSeCurrentPage(1);
                                }
                              }))
                            }
                          ]}
                        />
                      </th>
                      <th className="px-4 py-3 text-center">
                        <DashboardDropdown
                          isOpen={seReasonDropdownOpen}
                          setIsOpen={setSeReasonDropdownOpen}
                          dropdownRef={seReasonDropdownRef}
                          align="center"
                          trigger={
                            <span className={seReasonFilter !== 'All' ? (isDark ? 'text-yellow-400' : 'text-[#8b0000]') : (isDark ? 'text-[#b0b3b8]' : 'text-gray-500')}>
                              Failure Reason
                            </span>
                          }
                          sections={[
                            {
                              title: 'Filter by Failure Reason',
                              items: seReasonOptions.map(option => ({
                                label: formatLabel(option),
                                isSelected: seReasonFilter === option,
                                onClick: () => {
                                  setSeReasonFilter(option);
                                  setSeCurrentPage(1);
                                }
                              }))
                            }
                          ]}
                        />
                      </th>
                    </tr>
                  </thead>
                  <tbody>
                    {seLoading ? (
                      <ReportTableSkeleton isDark={isDark} count={10} />
                    ) : seEvents.length === 0 ? (
                      <tr>
                        <td colSpan={4} className="py-20">
                          <div className="flex flex-col items-center justify-center">
                            <div className={`w-16 h-16 mb-4 flex items-center justify-center rounded-full ${isDark ? 'bg-[#3a3b3c]/50' : 'bg-gray-100'}`}>
                              <ShieldExclamationIcon className={`w-8 h-8 ${isDark ? 'text-[#b0b3b8]' : 'text-gray-400'}`} />
                            </div>
                            <h3 className={`text-sm font-bold mb-1 ${isDark ? 'text-[#e4e6eb]' : 'text-gray-800'}`}>
                              No Security Events Found
                            </h3>
                            <p className={`text-xs ${isDark ? 'text-[#9a9a9a]' : 'text-gray-500'}`}>
                              No failed local-auth or IDP-unreachable events match your current search or filters.
                            </p>
                          </div>
                        </td>
                      </tr>
                    ) : (
                      seEvents.map((ev) => (
                        <tr key={ev.id} className={`border-b text-center transition-colors ${isDark ? 'border-[#3e4042] hover:bg-[#2a2a2f]' : 'border-gray-50 hover:bg-gray-50'}`}>

                          <td className={`px-4 py-3 text-xs whitespace-nowrap ${isDark ? 'text-[#b0b3b8]' : 'text-gray-500'}`}>
                            {ev.date} {ev.time}
                          </td>

                          <td className={`px-4 py-3 ${isDark ? 'text-[#e4e6eb]' : 'text-gray-800'}`}>{ev.email}</td>

                          <td className="px-4 py-3">
                            <span className={`px-3 py-1 rounded-full text-xs font-bold border whitespace-nowrap ${getEventBadgeClasses(ev.event_type, isDark)}`}>
                              {ev.event_type}
                            </span>
                          </td>

                          <td className="px-4 py-3">
                            {ev.reason ? (
                              <span className={`px-2 py-1 rounded-full text-xs font-bold border whitespace-nowrap ${getReasonBadgeClasses(ev.reason, isDark)}`}>
                                {ev.reason}
                              </span>
                            ) : (
                              <span className={`text-xs ${isDark ? 'text-[#6b6b6b]' : 'text-gray-400'}`}>—</span>
                            )}
                          </td>

                        </tr>
                      ))
                    )}
                  </tbody>
                </table>
              </div>

              {/* Pagination */}
              <div className={`flex items-center justify-center gap-1 px-4 py-4 border-t ${isDark ? 'border-[#3e4042]' : 'border-gray-100'}`}>
                <button onClick={() => setSeCurrentPage((p) => Math.max(1, p - 1))} disabled={seCurrentPage === 1}
                  className={`flex items-center gap-1 text-sm px-2 py-1 disabled:opacity-40 cursor-pointer ${isDark ? 'text-[#b0b3b8] hover:text-white' : 'text-gray-500 hover:text-gray-800'}`}>
                  <ChevronLeftIcon className="w-4 h-4" /> Previous
                </button>
                {pageNumbers(seTotalPages, seCurrentPage).map((p, i) => (
                  <button key={i} onClick={() => typeof p === "number" && setSeCurrentPage(p)} disabled={p === "..."}
                    className={`w-8 h-8 rounded-lg text-sm font-medium transition-colors cursor-pointer
                      ${seCurrentPage === p ? (isDark ? 'bg-amber-400 text-black font-bold' : 'bg-[#800000] text-white font-bold') : (isDark ? 'text-[#b0b3b8] hover:bg-[#2a2a2f]' : 'text-gray-500 hover:bg-gray-100')}
                      ${p === "..." ? "cursor-default pointer-events-none" : ""}`}>
                    {p}
                  </button>
                ))}
                <button onClick={() => setSeCurrentPage((p) => Math.min(seTotalPages, p + 1))} disabled={seCurrentPage === seTotalPages}
                  className={`flex items-center gap-1 text-sm px-2 py-1 disabled:opacity-40 cursor-pointer ${isDark ? 'text-[#b0b3b8] hover:text-white' : 'text-gray-500 hover:text-gray-800'}`}>
                  Next <ChevronRightIcon className="w-4 h-4" />
                </button>
              </div>
            </div>
          </>
        ) : (
          /* Free Requests Monthly Volume Report Tab (Audit Logs Design System) */
          <div>
            <div className="flex flex-col lg:flex-row lg:items-center justify-between gap-3 mb-6">
              <div className="flex-1 min-w-0 max-w-full sm:max-w-xs">
                <VoiceSearchInput
                  value={freeSearch}
                  onChange={(val) => {
                    setFreeSearch(val);
                    setFreeCurrentPage(1);
                  }}
                  placeholder="Search"
                />
              </div>

              {(freeSearch.trim() !== "" || freeTypeFilter !== "All") && (
                <div className="flex flex-wrap items-center gap-2.5">
                  <button
                    type="button"
                    onClick={() => {
                      setFreeSearch("");
                      setFreeTypeFilter("All");
                      setFreeCurrentPage(1);
                    }}
                    className={`px-3 py-2 rounded-lg text-xs font-semibold transition-colors border shadow-sm flex items-center justify-center cursor-pointer shrink-0 ${
                      isDark
                        ? 'bg-[#1f1f1f] text-[#b0b3b8] border-[#3e4042] hover:bg-[#2a2a2f] hover:text-[#e4e6eb]'
                        : 'bg-white text-gray-600 border-gray-200 hover:bg-gray-50 hover:text-gray-900'
                    }`}
                  >
                    Clear Filters
                  </button>
                </div>
              )}
            </div>

            {/* Volume Table (Audit Logs Table Pattern) */}
            <div className={`rounded-2xl overflow-hidden ${isDark ? 'bg-[#242526] border border-[#3e4042] shadow-none' : 'bg-white shadow-sm border border-gray-100'}`}>
              <div className="overflow-x-auto">
                <table className="w-full min-w-180 text-sm">
                  <thead>
                    <tr className={isDark ? 'border-b border-[#3e4042]' : 'border-b border-gray-100'}>
                      <th className={`px-4 py-3 text-center font-medium ${isDark ? 'text-[#b0b3b8]' : 'text-gray-500'}`}>Timestamp</th>
                      <th className="px-4 py-3 text-center">
                        <DashboardDropdown
                          isOpen={freeTypeDropdownOpen}
                          setIsOpen={setFreeTypeDropdownOpen}
                          dropdownRef={freeTypeDropdownRef}
                          align="center"
                          trigger={
                            <span className={freeTypeFilter !== 'All' ? (isDark ? 'text-yellow-400' : 'text-[#8b0000]') : (isDark ? 'text-[#b0b3b8]' : 'text-gray-500')}>
                              Document / Certificate Type
                            </span>
                          }
                          sections={[{
                            title: 'Filter by Document / Certificate Type',
                            items: ['All', ...freeTypeOptions].map((option) => ({
                              label: option === 'All' ? 'All' : option,
                              isSelected: freeTypeFilter === option,
                              onClick: () => {
                                setFreeTypeFilter(option);
                                setFreeCurrentPage(1);
                              },
                            })),
                          }]}
                        />
                      </th>
                      <th className={`px-4 py-3 text-center font-medium ${isDark ? 'text-[#b0b3b8]' : 'text-gray-500'}`}>Category Policy</th>
                      <th className={`px-4 py-3 text-center font-medium ${isDark ? 'text-[#b0b3b8]' : 'text-gray-500'}`}>Claimed Volume</th>
                    </tr>
                  </thead>
                  <tbody>
                    {freeReportLoading ? (
                      <ReportTableSkeleton isDark={isDark} count={PER_PAGE} />
                    ) : paginatedFreeData.length === 0 ? (
                      <tr>
                        <td colSpan={4} className="py-20">
                          <div className="flex flex-col items-center justify-center">
                            <div className={`w-16 h-16 mb-4 flex items-center justify-center rounded-full ${isDark ? 'bg-[#3a3b3c]/50' : 'bg-gray-100'}`}>
                              <SparklesIcon className={`w-8 h-8 ${isDark ? 'text-[#b0b3b8]' : 'text-gray-400'}`} />
                            </div>
                            <h3 className={`text-sm font-bold mb-1 ${isDark ? 'text-[#e4e6eb]' : 'text-gray-800'}`}>
                              No Free Issuance Records Found
                            </h3>
                            <p className={`text-xs ${isDark ? 'text-[#9a9a9a]' : 'text-gray-500'}`}>
                              No monthly claimed free issuance records match your current search criteria.
                            </p>
                          </div>
                        </td>
                      </tr>
                    ) : (
                      paginatedFreeData.map((row, idx) => {
                        const typeLabel = row.type_label || row.name || "Document";
                        const isGraduateType = typeLabel.toLowerCase().includes("graduation") || typeLabel.toLowerCase().includes("transcript") || typeLabel.toLowerCase().includes("tor");

                        return (
                          <tr key={idx} className={`border-b text-center transition-colors ${isDark ? 'border-[#3e4042] hover:bg-[#2a2a2f]' : 'border-gray-50 hover:bg-gray-50'}`}>
                            <td className={`px-4 py-3 text-xs font-bold whitespace-nowrap ${isDark ? 'text-[#e4e6eb]' : 'text-gray-900'}`}>
                              {formatFreeTimestamp(row)}
                            </td>
                            <td className={`px-4 py-3 text-xs font-semibold ${isDark ? 'text-[#e4e6eb]' : 'text-gray-800'}`}>
                              {typeLabel}
                            </td>
                            <td className="px-4 py-3">
                              <span className={`px-3 py-1 rounded-full text-xs font-bold border whitespace-nowrap ${
                                isGraduateType
                                  ? isDark ? 'bg-emerald-950/40 text-emerald-400 border-emerald-800/50' : 'bg-emerald-50 text-emerald-700 border-emerald-200'
                                  : isDark ? 'bg-blue-950/40 text-blue-400 border-blue-800/50' : 'bg-blue-50 text-blue-700 border-blue-200'
                              }`}>
                                {isGraduateType ? "One-Time Free Copy (Graduates)" : "Unlimited Free (LOA)"}
                              </span>
                            </td>
                            <td className={`px-4 py-3 text-xs font-extrabold font-mono ${isDark ? 'text-amber-400' : 'text-[#800000]'}`}>
                              {row.count || row.total_count || 0}
                            </td>
                          </tr>
                        );
                      })
                    )}
                  </tbody>
                </table>
              </div>

              {/* Pagination */}
              <div className={`flex items-center justify-center gap-1 px-4 py-4 border-t ${isDark ? 'border-[#3e4042]' : 'border-gray-100'}`}>
                <button onClick={() => setFreeCurrentPage((p) => Math.max(1, p - 1))} disabled={safeFreeCurrentPage === 1}
                  className={`flex items-center gap-1 text-sm px-2 py-1 disabled:opacity-40 cursor-pointer ${isDark ? 'text-[#b0b3b8] hover:text-white' : 'text-gray-500 hover:text-gray-800'}`}>
                  <ChevronLeftIcon className="w-4 h-4" /> Previous
                </button>
                {pageNumbers(freeTotalPages, safeFreeCurrentPage).map((p, i) => (
                  <button key={i} onClick={() => typeof p === "number" && setFreeCurrentPage(p)} disabled={p === "..."}
                    className={`w-8 h-8 rounded-lg text-sm font-medium transition-colors cursor-pointer
                      ${safeFreeCurrentPage === p ? (isDark ? 'bg-amber-400 text-black font-bold' : 'bg-[#800000] text-white font-bold') : (isDark ? 'text-[#b0b3b8] hover:bg-[#2a2a2f]' : 'text-gray-500 hover:bg-gray-100')}
                      ${p === "..." ? "cursor-default pointer-events-none" : ""}`}>
                      {p}
                    </button>
                ))}
                <button onClick={() => setFreeCurrentPage((p) => Math.min(freeTotalPages, p + 1))} disabled={safeFreeCurrentPage === freeTotalPages || freeTotalPages === 0}
                  className={`flex items-center gap-1 text-sm px-2 py-1 disabled:opacity-40 cursor-pointer ${isDark ? 'text-[#b0b3b8] hover:text-white' : 'text-gray-500 hover:text-gray-800'}`}>
                  Next <ChevronRightIcon className="w-4 h-4" />
                </button>
              </div>
            </div>
          </div>
        )}

        <LogbookDateRangeModal
          isOpen={isDateModalOpen}
          onClose={() => setIsDateModalOpen(false)}
          onConfirm={handleApplyDateFilter}
          initialDateFrom={dateFrom}
          initialDateTo={dateTo}
          initialActivePreset={activePreset}
          isDark={isDark}
        />

        <SuccessToast message={successMsg} onClose={() => setSuccessMsg("")} />
        <ErrorToast message={errorMsg} onClose={() => setErrorMsg("")} />
      </div>
    </div>
  );
};

export default ReportManagement;