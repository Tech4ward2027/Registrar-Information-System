import React, { useState, useEffect, useCallback } from "react";
import { useSearchParams } from "react-router-dom";
import {
  XMarkIcon,
  CalendarDaysIcon,
  ArrowPathIcon,
} from "@heroicons/react/24/outline";
import { useTheme } from "../context/ThemeContext";
import {
  getCalendarExceptions,
  createCalendarException,
  updateCalendarException,
  deleteCalendarException,
  getCalendarOverrides,
  createCalendarOverride,
  updateCalendarOverride,
  deleteCalendarOverride,
  getBusinessHoursStatus,
} from "../services/api";
import VoiceSearchInput from "../components/VoiceSearchInput.jsx";
import SuccessToast from "../components/SuccessToast.jsx";
import ErrorToast from "../components/ErrorToast.jsx";
import ConfirmationModal from "../components/ConfirmationModal.jsx";
import InputGroup from "../components/InputGroup.jsx";
import DropdownGroup from "../components/DropDown.jsx";

// Import custom calendar components, helpers, and types
import {
  EXCEPTION_TYPES,
  DAYS_OF_WEEK,
  capitalize,
  getTodayDateString,
  getIncomingHolidays,
  OfficeStatusCards,
  CalendarGridView,
  FormActions,
  ExceptionsTable,
  OverridesTable,
  Pagination,
} from "../components/BusinessCalendarComponents.jsx";

import ImportHolidaysModal, {
  OFFICIAL_PH_HOLIDAYS_2026,
  OFFICIAL_PH_HOLIDAYS_2027,
} from "../components/ImportHolidaysModal.jsx";

const PER_PAGE = 10;

const EMPTY_EXCEPTION_FORM = { type: "holiday", label: "", date: "", end_date: "", closed_from_time: "" };
const EMPTY_OVERRIDE_FORM = { day_of_week: "monday", is_closed: true, label: "", effective_from: "", effective_until: "" };



/**
 * Admin/superadmin screen for the business calendar's two closure mechanisms.
 * Updated to display a premium month-based Calendar Grid, List view, and status cards.
 */
const BusinessCalendarManagement = () => {
  const { isDark } = useTheme();
  const [searchParams, setSearchParams] = useSearchParams();

  // ---- Switcher View Mode state derived from URL ----
  const tabFromUrl = searchParams.get("tab")?.toLowerCase();
  const viewMode = (tabFromUrl === "list" || tabFromUrl === "schedule-list") ? "list" : "grid";

  const handleTabSwitch = (newTab) => {
    setSearchParams({ tab: newTab === "list" ? "Schedule-List" : "calendar" });
  };
  const [activeTab, setActiveTab] = useState("exceptions"); // 'exceptions' | 'overrides'

  // ---- Calendar grid / date navigation state ----
  const [currentMonth, setCurrentMonth] = useState(new Date().getMonth());
  const [currentYear, setCurrentYear] = useState(new Date().getFullYear());

  // ---- Office Status & Summaries state ----
  const [officeStatus, setOfficeStatus] = useState(null);
  const [calendarExceptions, setCalendarExceptions] = useState([]);
  const [calendarOverrides, setCalendarOverrides] = useState([]);
  const [calendarLoading, setCalendarLoading] = useState(false);

  // ---- Closures (exceptions) list state ----
  const [exceptions, setExceptions] = useState([]);
  const [exceptionsMeta, setExceptionsMeta] = useState({ current_page: 1, last_page: 1 });
  const [exceptionsPage, setExceptionsPage] = useState(1);
  const [exceptionsLoading, setExceptionsLoading] = useState(false);
  const [includePast, setIncludePast] = useState(false);

  // ---- Recurring overrides state ----
  const [overrides, setOverrides] = useState([]);
  const [overridesMeta, setOverridesMeta] = useState({ current_page: 1, last_page: 1 });
  const [overridesPage, setOverridesPage] = useState(1);
  const [overridesLoading, setOverridesLoading] = useState(false);
  const [includeExpired, setIncludeExpired] = useState(false);

  const [search, setSearch] = useState("");
  const [filterExceptionType, setFilterExceptionType] = useState("all"); // 'all' | 'holiday' | 'suspension' | 'event'
  const [filterOverrideStatus, setFilterOverrideStatus] = useState("all"); // 'all' | 'closed' | 'open'

  // ---- Shared form/modal state ----
  const [isFormOpen, setIsFormOpen] = useState(false);
  const [isImportModalOpen, setIsImportModalOpen] = useState(false);
  const [editingId, setEditingId] = useState(null); // null = creating
  const [exceptionForm, setExceptionForm] = useState(EMPTY_EXCEPTION_FORM);
  const [overrideForm, setOverrideForm] = useState(EMPTY_OVERRIDE_FORM);
  const [saving, setSaving] = useState(false);
  const [fieldErrors, setFieldErrors] = useState({});
  const [deleteTarget, setDeleteTarget] = useState(null); // { kind, record }

  const [successMsg, setSuccessMsg] = useState("");
  const [errorMsg, setErrorMsg] = useState("");

  const handleBulkImportHolidays = async (itemsToImport) => {
    setSaving(true);
    let successCount = 0;
    let failCount = 0;

    for (const item of itemsToImport) {
      try {
        await createCalendarException({
          type: item.type,
          label: item.label,
          date: item.date,
          end_date: item.end_date,
        });
        successCount++;
      } catch (err) {
        console.error(`Failed to import ${item.label}:`, err);
        failCount++;
      }
    }

    await Promise.all([
      fetchExceptions(exceptionsPage),
      fetchCalendarData(),
      fetchOfficeStatus(),
    ]);
    setSaving(false);

    if (successCount > 0) {
      setSuccessMsg(`Successfully imported ${successCount} official holiday${successCount > 1 ? "s" : ""}!`);
    } else if (failCount > 0) {
      setErrorMsg("Some selected holidays could not be imported.");
    }
  };

  // -------------------------------------------------------
  // Fetching
  // -------------------------------------------------------
  const fetchOfficeStatus = useCallback(async () => {
    try {
      const res = await getBusinessHoursStatus();
      setOfficeStatus(res.data);
    } catch (err) {
      console.error("Failed to load office status:", err);
    }
  }, []);

  const fetchCalendarData = useCallback(async () => {
    setCalendarLoading(true);
    try {
      const [exceptionsRes, overridesRes] = await Promise.all([
        getCalendarExceptions({ per_page: 1000, include_past: 1 }),
        getCalendarOverrides({ per_page: 1000, include_expired: 1 }),
      ]);
      setCalendarExceptions(exceptionsRes.data.data || []);
      setCalendarOverrides(overridesRes.data.data || []);
    } catch (err) {
      console.error("Failed to load calendar data:", err);
    } finally {
      setCalendarLoading(false);
    }
  }, []);

  const fetchExceptions = useCallback(async (page = 1, includePastValue = includePast) => {
    setExceptionsLoading(true);
    try {
      const res = await getCalendarExceptions({
        page,
        per_page: PER_PAGE,
        include_past: includePastValue ? 1 : 0,
      });
      setExceptions(res.data.data);
      setExceptionsMeta({ current_page: res.data.current_page, last_page: res.data.last_page });
    } catch (err) {
      console.error("Failed to load calendar closures:", err);
      setErrorMsg("Couldn't load closures. Please try again.");
    } finally {
      setExceptionsLoading(false);
    }
  }, [includePast]);

  const fetchOverrides = useCallback(async (page = 1, includeExpiredValue = includeExpired) => {
    setOverridesLoading(true);
    try {
      const res = await getCalendarOverrides({
        page,
        per_page: PER_PAGE,
        include_expired: includeExpiredValue ? 1 : 0,
      });
      setOverrides(res.data.data);
      setOverridesMeta({ current_page: res.data.current_page, last_page: res.data.last_page });
    } catch (err) {
      console.error("Failed to load recurring overrides:", err);
      setErrorMsg("Couldn't load recurring overrides. Please try again.");
    } finally {
      setOverridesLoading(false);
    }
  }, [includeExpired]);

  // Initial fetches
  useEffect(() => {
    fetchOfficeStatus();
    fetchCalendarData();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [fetchOfficeStatus, fetchCalendarData]);

  useEffect(() => {
    fetchExceptions(exceptionsPage);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [exceptionsPage, includePast]);

  useEffect(() => {
    fetchOverrides(overridesPage);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [overridesPage, includeExpired]);

  // -------------------------------------------------------
  // Calendar Grid Month Navigation
  // -------------------------------------------------------
  const handleChangeMonth = (offset) => {
    let nextMonth = currentMonth + offset;
    let nextYear = currentYear;

    if (nextMonth < 0) {
      nextMonth = 11;
      nextYear -= 1;
    } else if (nextMonth > 11) {
      nextMonth = 0;
      nextYear += 1;
    }
    setCurrentMonth(nextMonth);
    setCurrentYear(nextYear);
  };

  const handleToday = () => {
    const today = new Date();
    setCurrentMonth(today.getMonth());
    setCurrentYear(today.getFullYear());
  };

  // -------------------------------------------------------
  // Form Actions & Handlers
  // -------------------------------------------------------
  const handleAddDateEntry = (defaultDate = "") => {
    setActiveTab("exceptions");
    setEditingId(null);
    setFieldErrors({});
    setExceptionForm({
      ...EMPTY_EXCEPTION_FORM,
      date: defaultDate || EMPTY_EXCEPTION_FORM.date,
      end_date: defaultDate || EMPTY_EXCEPTION_FORM.end_date,
    });
    setIsFormOpen(true);
  };

  const handleAddWeeklyRule = (defaultDay = "monday") => {
    setActiveTab("overrides");
    setEditingId(null);
    setFieldErrors({});
    setOverrideForm({
      ...EMPTY_OVERRIDE_FORM,
      day_of_week: defaultDay || EMPTY_OVERRIDE_FORM.day_of_week,
    });
    setIsFormOpen(true);
  };

  const openEditExceptionForm = (exception) => {
    setActiveTab("exceptions");
    setEditingId(exception.holiday_id);
    setExceptionForm({
      type: exception.type,
      label: exception.label,
      date: exception.date?.slice(0, 10) ?? "",
      end_date: exception.end_date?.slice(0, 10) ?? "",
      closed_from_time: exception.closed_from_time?.slice(0, 5) ?? "",
    });
    setFieldErrors({});
    setIsFormOpen(true);
  };

  const openEditOverrideForm = (override) => {
    setActiveTab("overrides");
    setEditingId(override.override_id);
    setOverrideForm({
      day_of_week: override.day_of_week,
      is_closed: !!override.is_closed,
      label: override.label,
      effective_from: override.effective_from?.slice(0, 10) ?? "",
      effective_until: override.effective_until?.slice(0, 10) ?? "",
    });
    setFieldErrors({});
    setIsFormOpen(true);
  };

  const closeForm = () => {
    if (saving) return; // don't let the modal be dismissed mid-save
    setIsFormOpen(false);
  };

  // -------------------------------------------------------
  // Submit
  // -------------------------------------------------------
  const handleExceptionSubmit = async (e) => {
    e.preventDefault();
    setSaving(true);
    setFieldErrors({});

    const payload = {
      type: exceptionForm.type,
      label: exceptionForm.label.trim(),
      date: exceptionForm.date,
      end_date: exceptionForm.end_date || null,
      // Explicit null (not omitted) so clearing the field on an edit
      // actually clears the cutoff server-side — see
      // CalendarExceptionService::update()'s array_key_exists handling.
      closed_from_time: exceptionForm.closed_from_time || null,
    };

    try {
      if (editingId) {
        await updateCalendarException(editingId, payload);
        setSuccessMsg("Closure updated successfully!");
      } else {
        await createCalendarException(payload);
        setSuccessMsg("Closure added successfully!");
      }
      await Promise.all([
        fetchExceptions(exceptionsPage),
        fetchCalendarData(),
        fetchOfficeStatus(),
      ]);
      setIsFormOpen(false);
    } catch (err) {
      handleSubmitError(err, "closure");
    } finally {
      setSaving(false);
    }
  };

  const handleOverrideSubmit = async (e) => {
    e.preventDefault();
    setSaving(true);
    setFieldErrors({});

    const payload = {
      day_of_week: overrideForm.day_of_week,
      is_closed: overrideForm.is_closed,
      label: overrideForm.label.trim(),
      effective_from: overrideForm.effective_from,
      effective_until: overrideForm.effective_until || null,
    };

    try {
      if (editingId) {
        await updateCalendarOverride(editingId, payload);
        setSuccessMsg("Recurring override updated successfully!");
      } else {
        await createCalendarOverride(payload);
        setSuccessMsg("Recurring override added successfully!");
      }
      await Promise.all([
        fetchOverrides(overridesPage),
        fetchCalendarData(),
        fetchOfficeStatus(),
      ]);
      setIsFormOpen(false);
    } catch (err) {
      handleSubmitError(err, "recurring override");
    } finally {
      setSaving(false);
    }
  };

  const handleSubmitError = (err, resourceLabel) => {
    const status = err?.response?.status;
    if (status === 422) {
      setFieldErrors(err.response.data?.errors ?? {});
      setErrorMsg(err.response.data?.message || "Please fix the highlighted fields.");
    } else if (status === 403) {
      setErrorMsg("You don't have permission to manage the business calendar.");
    } else {
      setErrorMsg(`Couldn't save this ${resourceLabel}. Please try again.`);
    }
    console.error(`Failed to save ${resourceLabel}:`, err);
  };

  // -------------------------------------------------------
  // Delete
  // -------------------------------------------------------
  const handleDelete = async () => {
    if (!deleteTarget) return;
    const { kind, record } = deleteTarget;

    try {
      if (kind === "exception") {
        await deleteCalendarException(record.holiday_id);
        await Promise.all([
          fetchExceptions(exceptionsPage),
          fetchCalendarData(),
          fetchOfficeStatus(),
        ]);
      } else {
        await deleteCalendarOverride(record.override_id);
        await Promise.all([
          fetchOverrides(overridesPage),
          fetchCalendarData(),
          fetchOfficeStatus(),
        ]);
      }
      setSuccessMsg(kind === "exception" ? "Closure deleted." : "Recurring override deleted.");
    } catch (err) {
      console.error(`Failed to delete ${kind}:`, err);
      const status = err?.response?.status;
      setErrorMsg(
        status === 403
          ? "You don't have permission to manage the business calendar."
          : "Couldn't delete this entry. Please try again."
      );
    } finally {
      setDeleteTarget(null);
    }
  };

  // -------------------------------------------------------
  // Enable/disable — a live switch on each row, same pattern as
  // Announcement's handleToggle: fires immediately, no modal, no
  // confirmation. Disabling doesn't delete anything — the row stays in
  // the list (and can be flipped back on) but has zero effect on the
  // actual calendar the moment it's off (enforced server-side in
  // BusinessCalendarService, not just hidden in this UI).
  // -------------------------------------------------------
  const handleToggleExceptionEnabled = async (item) => {
    try {
      const res = await updateCalendarException(item.holiday_id, { enabled: !item.enabled });
      setExceptions((prev) => prev.map((e) => (e.holiday_id === item.holiday_id ? res.data : e)));
      await Promise.all([
        fetchCalendarData(),
        fetchOfficeStatus(),
      ]);
    } catch (err) {
      console.error("Failed to toggle closure:", err);
      const status = err?.response?.status;
      setErrorMsg(
        status === 403
          ? "You don't have permission to manage the business calendar."
          : "Couldn't update this closure. Please try again."
      );
    }
  };

  const handleToggleOverrideEnabled = async (item) => {
    try {
      const res = await updateCalendarOverride(item.override_id, { enabled: !item.enabled });
      setOverrides((prev) => prev.map((o) => (o.override_id === item.override_id ? res.data : o)));
      await Promise.all([
        fetchCalendarData(),
        fetchOfficeStatus(),
      ]);
    } catch (err) {
      console.error("Failed to toggle recurring override:", err);
      const status = err?.response?.status;
      setErrorMsg(
        status === 403
          ? "You don't have permission to manage the business calendar."
          : "Couldn't update this recurring override. Please try again."
      );
    }
  };

  // -------------------------------------------------------
  // Client-side search over the currently loaded page
  // -------------------------------------------------------
  const filteredExceptions = exceptions.filter((item) => {
    const query = search.toLowerCase().trim();
    const matchesSearch = !query || [item.label, item.type, item.date, item.end_date, item.closed_from_time].join(" ").toLowerCase().includes(query);
    const matchesType = filterExceptionType === "all" || item.type === filterExceptionType;
    return matchesSearch && matchesType;
  });

  const filteredOverrides = overrides.filter((item) => {
    const query = search.toLowerCase().trim();
    const matchesSearch = !query || [item.label, item.day_of_week, item.effective_from, item.effective_until]
      .join(" ")
      .toLowerCase()
      .includes(query);
    const matchesStatus = filterOverrideStatus === "all" || (filterOverrideStatus === "closed" ? item.is_closed : !item.is_closed);
    return matchesSearch && matchesStatus;
  });

  const inputClass = `w-full rounded-lg border px-3 py-2 text-sm outline-none transition-colors focus:ring-2 ${isDark
    ? "bg-[#18191a] border-[#3e4042] text-[#e4e6eb] focus:ring-yellow-400/40 focus:border-yellow-400/60"
    : "bg-white border-gray-300 text-gray-900 focus:ring-pup-maroon/30 focus:border-pup-maroon"
    }`;

  const labelClass = `mb-1 block text-xs font-semibold uppercase tracking-wider ${isDark ? "text-[#b0b3b8]" : "text-gray-600"}`;

  const TYPE_BADGE = {
    holiday: isDark ? "bg-blue-900/30 text-blue-300" : "bg-blue-50 text-blue-700",
    suspension: isDark ? "bg-red-900/30 text-red-300" : "bg-red-50 text-red-700",
    event: isDark ? "bg-purple-900/30 text-purple-300" : "bg-purple-50 text-purple-700",
  };

  // Compute upcoming exceptions count and weekly overrides count using incoming holiday helpers
  const incomingHolidays = getIncomingHolidays(calendarExceptions);
  const activeUpcomingCount = incomingHolidays.length;

  const activeWeeklyCount = calendarOverrides.filter((o) => {
    return o.enabled;
  }).length;

  return (
    <main className={`min-h-screen p-4 sm:p-6 ${isDark ? "bg-[#18191a] text-[#e4e6eb]" : "text-gray-900"}`}>
      <div className="mx-auto max-w-5xl">

        {/* Centered Switcher Pill (Hidden on mobile) */}
        <div className="hidden md:flex justify-center mx-4 sm:mx-6 mb-6">
          <div className={`inline-flex px-8 py-3.5 rounded-full transition-all duration-300 hover:-translate-y-0.5 gap-8 items-center ${isDark
            ? 'bg-[#242526] border border-[#3e4042] shadow-[0_2px_8px_rgba(0,0,0,0.2)] hover:shadow-[0_4px_16px_rgba(0,0,0,0.35)]'
            : 'bg-white border border-gray-200/80 shadow-[0_2px_8px_rgba(0,0,0,0.05)] hover:shadow-[0_4px_16px_rgba(0,0,0,0.1)]'
            }`}>
            <button
              onClick={() => handleTabSwitch("calendar")}
              className={`text-sm font-semibold transition-all duration-200 hover:scale-105 active:scale-95 cursor-pointer ${viewMode === "grid"
                ? isDark
                  ? "text-yellow-400 font-bold"
                  : "text-pup-dark-maroon font-black"
                : isDark
                  ? "text-[#b0b3b8] hover:text-white"
                  : "text-gray-500 hover:text-gray-900"
                }`}
            >
              Business Calendar
            </button>
            <button
              onClick={() => handleTabSwitch("list")}
              className={`text-sm font-semibold transition-all duration-200 hover:scale-105 active:scale-95 cursor-pointer ${viewMode === "list"
                ? isDark
                  ? "text-yellow-400 font-bold"
                  : "text-pup-dark-maroon font-black"
                : isDark
                  ? "text-[#b0b3b8] hover:text-white"
                  : "text-gray-500 hover:text-gray-900"
                }`}
            >
              Schedule List
            </button>
          </div>
        </div>

        {/* Status summary cards */}
        <OfficeStatusCards
          isDark={isDark}
          officeStatus={officeStatus}
          upcomingCount={activeUpcomingCount}
          weeklyScheduleCount={activeWeeklyCount}
          calendarExceptions={calendarExceptions}
        />

        {/* Switch View Render */}
        {viewMode === "grid" ? (
          <div className={`rounded-3xl border shadow-sm p-4 sm:p-6 ${isDark ? "border-[#3e4042] bg-[#242526]" : "border-gray-200 bg-white"}`}>
            <CalendarGridView
              isDark={isDark}
              currentMonth={currentMonth}
              currentYear={currentYear}
              onChangeMonth={handleChangeMonth}
              onToday={handleToday}
              calendarExceptions={calendarExceptions}
              calendarOverrides={calendarOverrides}
              onAddException={handleAddDateEntry}
              onAddOverride={handleAddWeeklyRule}
              onEditException={openEditExceptionForm}
              onDeleteException={(record) => setDeleteTarget({ kind: "exception", record })}
              onEditOverride={openEditOverrideForm}
              onDeleteOverride={(record) => setDeleteTarget({ kind: "override", record })}
            />
          </div>
        ) : (
          <div className={`rounded-3xl border shadow-sm p-4 sm:p-5 ${isDark ? "border-[#3e4042] bg-[#242526]" : "border-gray-200 bg-white"}`}>

            {/* Tabs and Add Button aligned in one row */}
            <div className={`flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-4 border-b pb-2 sm:pb-0.5 ${isDark ? "border-[#3e4042]" : "border-gray-200"}`}>
              {/* Tabs */}
              <div className="flex items-center gap-1">
                <button
                  onClick={() => setActiveTab("exceptions")}
                  className={`flex items-center gap-1.5 px-4 py-2.5 text-sm font-semibold transition-colors border-b-2 -mb-px cursor-pointer ${activeTab === "exceptions"
                    ? (isDark ? "border-yellow-400 text-yellow-400" : "border-pup-dark-maroon text-pup-dark-maroon")
                    : (isDark ? "border-transparent text-[#b0b3b8] hover:text-white" : "border-transparent text-gray-500 hover:text-gray-800")
                    }`}
                >
                  <CalendarDaysIcon className="h-4 w-4" />
                  Dates & events
                </button>
                <button
                  onClick={() => setActiveTab("overrides")}
                  className={`flex items-center gap-1.5 px-4 py-2.5 text-sm font-semibold transition-colors border-b-2 -mb-px cursor-pointer ${activeTab === "overrides"
                    ? (isDark ? "border-yellow-400 text-yellow-400" : "border-pup-dark-maroon text-pup-dark-maroon")
                    : (isDark ? "border-transparent text-[#b0b3b8] hover:text-white" : "border-transparent text-gray-500 hover:text-gray-800")
                    }`}
                >
                  <ArrowPathIcon className="h-4 w-4" />
                  Weekly schedule
                </button>
              </div>

              {/* Action Buttons */}
              <div className="pb-2 sm:pb-1.5 self-end sm:self-auto flex items-center gap-2">
                {activeTab === "exceptions" ? (
                  <>
                    <button
                      onClick={() => setIsImportModalOpen(true)}
                      className={`px-4 py-2 rounded-lg text-xs font-bold shadow transition-all cursor-pointer hover:scale-[1.02] active:scale-[0.98] shrink-0 ${
                        isDark
                          ? "bg-[#3a3b3c] text-[#e4e6eb] hover:bg-[#4e4f50] border border-[#4e4f50]"
                          : "bg-pup-dark-maroon text-white hover:bg-[#3a0303]"
                      }`}
                    >
                      Import Official Holidays
                    </button>
                    <button
                      onClick={() => handleAddDateEntry()}
                      className={`px-4 py-2 rounded-lg text-xs font-bold shadow transition-all cursor-pointer hover:scale-[1.02] active:scale-[0.98] shrink-0 ${
                        isDark ? "bg-yellow-400 text-black hover:bg-yellow-500" : "bg-pup-dark-maroon text-white hover:bg-[#3a0303]"
                      }`}
                    >
                      Add date entry +
                    </button>
                  </>
                ) : (
                  <button
                    onClick={() => handleAddWeeklyRule()}
                    className={`px-4 py-2 rounded-lg text-xs font-bold shadow transition-all cursor-pointer hover:scale-[1.02] active:scale-[0.98] shrink-0 ${
                      isDark ? "bg-yellow-400 text-black hover:bg-yellow-500" : "bg-pup-dark-maroon text-white hover:bg-[#3a0303]"
                    }`}
                  >
                    Add weekly rule +
                  </button>
                )}
              </div>
            </div>

            <section className={`rounded-2xl overflow-hidden border shadow-sm ${isDark ? "border-[#3e4042] bg-[#242526]" : "border-gray-100 bg-white"}`}>
              <div className={`flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 p-4 border-b ${isDark ? "border-[#3e4042] bg-[#1a1a1c]/20" : "border-gray-200 bg-gray-50/50"}`}>
                <div className="w-full sm:max-w-xl flex flex-col sm:flex-row gap-3 items-stretch sm:items-center">
                  <div className="flex-1">
                    <VoiceSearchInput value={search} onChange={(value) => setSearch(value)} placeholder="Search" />
                  </div>
                  {(search.trim() !== "" || filterExceptionType !== "all" || filterOverrideStatus !== "all") && (
                    <button
                      type="button"
                      onClick={() => {
                        setSearch("");
                        setFilterExceptionType("all");
                        setFilterOverrideStatus("all");
                      }}
                      className={`px-4 py-2 rounded-lg text-sm font-semibold transition-colors border shadow-xs flex items-center justify-center shrink-0 cursor-pointer
                        ${isDark
                          ? 'bg-[#1f1f1f] text-[#b0b3b8] border-[#3e4042] hover:bg-[#2a2a2f] hover:text-[#e4e6eb]'
                          : 'bg-white text-gray-600 border-gray-200 hover:bg-gray-50 hover:text-gray-900'
                        }`}
                    >
                      Clear Filters
                    </button>
                  )}
                </div>

                <label className={`flex items-center gap-2 text-xs font-semibold cursor-pointer select-none shrink-0 ${isDark ? "text-[#b0b3b8]" : "text-gray-500"}`}>
                  <input
                    type="checkbox"
                    checked={activeTab === "exceptions" ? includePast : includeExpired}
                    onChange={(e) =>
                      activeTab === "exceptions"
                        ? setIncludePast(e.target.checked)
                        : setIncludeExpired(e.target.checked)
                    }
                    className="h-3.5 w-3.5"
                  />
                  {activeTab === "exceptions" ? "Show past closures" : "Show expired overrides"}
                </label>
              </div>

              {activeTab === "exceptions" ? (
                <ExceptionsTable
                  items={filteredExceptions}
                  loading={exceptionsLoading}
                  isDark={isDark}
                  typeBadge={TYPE_BADGE}
                  onEdit={openEditExceptionForm}
                  onDelete={(record) => setDeleteTarget({ kind: "exception", record })}
                  onToggleEnabled={handleToggleExceptionEnabled}
                  filterType={filterExceptionType}
                  setFilterType={setFilterExceptionType}
                />
              ) : (
                <OverridesTable
                  items={filteredOverrides}
                  loading={overridesLoading}
                  isDark={isDark}
                  onEdit={openEditOverrideForm}
                  onDelete={(record) => setDeleteTarget({ kind: "override", record })}
                  onToggleEnabled={handleToggleOverrideEnabled}
                  filterStatus={filterOverrideStatus}
                  setFilterStatus={setFilterOverrideStatus}
                />
              )}

              <Pagination
                isDark={isDark}
                meta={activeTab === "exceptions" ? exceptionsMeta : overridesMeta}
                onPrev={() =>
                  activeTab === "exceptions"
                    ? setExceptionsPage((p) => Math.max(1, p - 1))
                    : setOverridesPage((p) => Math.max(1, p - 1))
                }
                onNext={() =>
                  activeTab === "exceptions"
                    ? setExceptionsPage((p) => Math.min(exceptionsMeta.last_page, p + 1))
                    : setOverridesPage((p) => Math.min(overridesMeta.last_page, p + 1))
                }
              />
            </section>
          </div>
        )}
      </div>

      {/* Form Dialog Modal */}
      {isFormOpen && (
        <div className="fixed inset-0 z-9999 flex items-center justify-center p-4">
          <div
            className="fixed inset-0 bg-black/50 backdrop-blur-sm z-40"
            onClick={closeForm}
          />
          <div className={`relative z-50 w-full max-w-lg my-4 sm:my-6 rounded-xl shadow-[0_20px_50px_rgba(0,0,0,0.35)] overflow-hidden border flex flex-col max-h-[90vh] animate-in fade-in zoom-in duration-200 ${isDark ? 'bg-[#242526] border-[#3e4042] text-[#e4e6eb]' : 'bg-white border-[#800000]/20 text-gray-900'}`}>
            <div className={`px-6 py-5 border-b-4 shrink-0 ${isDark ? 'bg-[#1f1f1f] border-[#b98b00]' : 'bg-[#800000] border-[#FFD700]'}`}>
              <div className="flex items-center justify-between">
                <div>
                  <h3 className="text-xl text-white font-black uppercase tracking-tighter">
                    {editingId
                      ? (activeTab === "exceptions" ? "Edit Closure" : "Edit Override")
                      : (activeTab === "exceptions" ? "Add Closure" : "Add Override")}
                  </h3>
                  <p className="text-white/80 text-xs mt-0.5">
                    {activeTab === "exceptions"
                      ? "One-off dated closure — takes precedence over recurring overrides."
                      : "Standing weekly rule (e.g. \"Closed every Monday until further notice\")."}
                  </p>
                </div>
                <button
                  type="button"
                  onClick={closeForm}
                  disabled={saving}
                  className="p-2 rounded hover:opacity-90 shrink-0 text-white cursor-pointer disabled:opacity-50"
                  title="Close"
                >
                  <XMarkIcon className="w-6 h-6 text-white" />
                </button>
              </div>
            </div>

            {activeTab === "exceptions" ? (
              <form onSubmit={handleExceptionSubmit} noValidate className="flex flex-col flex-1 overflow-y-auto">
                <div className="px-6 py-5 space-y-5 flex-1">
                  {!editingId && (() => {
                    const allPresets = [
                      ...OFFICIAL_PH_HOLIDAYS_2026.map(h => ({ ...h, displayLabel: `[2026] ${h.label} (${h.date})` })),
                      ...OFFICIAL_PH_HOLIDAYS_2027.map(h => ({ ...h, displayLabel: `[2027] ${h.label} (${h.date})` })),
                    ];
                    const selectedPreset = allPresets.find(
                      (h) => h.label === exceptionForm.label && h.date === exceptionForm.date
                    );

                    return (
                      <div className={`p-3.5 rounded-xl border ${
                        isDark ? "bg-[#1f2022] border-[#3e4042]" : "bg-amber-50/60 border-amber-200"
                      }`}>
                        <DropdownGroup
                          label="Preset Official PH Holiday (Optional)"
                          name="preset_holiday"
                          value={selectedPreset?.displayLabel ?? ""}
                          onChange={(e) => {
                            const found = allPresets.find((h) => h.displayLabel === e.target.value);
                            if (found) {
                              setExceptionForm((prev) => ({
                                ...prev,
                                label: found.label,
                                type: found.type,
                                date: found.date,
                                end_date: found.end_date,
                              }));
                            }
                          }}
                          options={["-- Select Preset Holiday --", ...allPresets.map((h) => h.displayLabel)]}
                          labelColor={isDark ? "text-[#e4e6eb]" : "text-[#800000]"}
                        />
                        <p className={`mt-1.5 text-[11px] ${isDark ? "text-[#b0b3b8]" : "text-gray-600"}`}>
                          Select an official national holiday to auto-fill label & date fields below.
                        </p>
                      </div>
                    );
                  })()}

                  <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                      <DropdownGroup
                        label="Type"
                        name="type"
                        value={EXCEPTION_TYPES.find(t => t.value === exceptionForm.type)?.label ?? ''}
                        onChange={(e) => {
                          const found = EXCEPTION_TYPES.find(t => t.label === e.target.value);
                          if (found) setExceptionForm((prev) => ({ ...prev, type: found.value }));
                        }}
                        options={EXCEPTION_TYPES.map(t => t.label)}
                        required
                        labelColor={isDark ? 'text-[#b0b3b8]' : 'text-gray-600'}
                      />
                      {fieldErrors.type && <p className="mt-1 text-xs font-semibold text-red-500">{fieldErrors.type[0]}</p>}
                    </div>

                    <div>
                      <InputGroup
                        label="Label"
                        name="label"
                        value={exceptionForm.label}
                        onChange={(e) => setExceptionForm((prev) => ({ ...prev, label: e.target.value }))}
                        placeholder="e.g. Typhoon suspension"
                        required
                        voiceEnabled={false}
                        labelColor={isDark ? 'text-[#b0b3b8]' : 'text-gray-600'}
                      />
                      {fieldErrors.label && <p className="mt-1 text-xs font-semibold text-red-500">{fieldErrors.label[0]}</p>}
                    </div>
                  </div>

                  <div className="grid grid-cols-2 gap-4">
                    <div>
                      <InputGroup
                        label="Start Date"
                        name="date"
                        type="date"
                        value={exceptionForm.date}
                        onChange={(e) => setExceptionForm((prev) => ({ ...prev, date: e.target.value }))}
                        required
                        // Only enforced when creating a new closure — an
                        // already-editing closure may legitimately have a
                        // past date (e.g. fixing a typo in an old entry's
                        // label), and blocking that with a min here would
                        // make the native date picker refuse to submit.
                        min={editingId ? undefined : getTodayDateString()}
                        voiceEnabled={false}
                        labelColor={isDark ? 'text-[#b0b3b8]' : 'text-gray-600'}
                      />
                      {fieldErrors.date && <p className="mt-1 text-xs font-semibold text-red-500">{fieldErrors.date[0]}</p>}
                    </div>
                    <div>
                      <InputGroup
                        label="End Date"
                        name="end_date"
                        type="date"
                        value={exceptionForm.end_date}
                        onChange={(e) => setExceptionForm((prev) => ({ ...prev, end_date: e.target.value }))}
                        min={exceptionForm.date || undefined}
                        voiceEnabled={false}
                        labelColor={isDark ? 'text-[#b0b3b8]' : 'text-gray-600'}
                      />
                      <p className={`mt-1 text-[11px] ${isDark ? 'text-[#8a8d91]' : 'text-gray-400'}`}>Leave blank for single day.</p>
                      {fieldErrors.end_date && <p className="mt-1 text-xs font-semibold text-red-500">{fieldErrors.end_date[0]}</p>}
                    </div>
                  </div>

                  <div>
                    <InputGroup
                      label="Closes Early At (Optional)"
                      name="closed_from_time"
                      type="time"
                      value={exceptionForm.closed_from_time}
                      onChange={(e) => setExceptionForm((prev) => ({ ...prev, closed_from_time: e.target.value }))}
                      // Registrar closes-early times are restricted to
                      // 8 AM–8 PM (kept in sync with the backend's
                      // Store/UpdateCalendarExceptionRequest validation) —
                      // min/max steer the native time picker away from
                      // values the server would reject anyway.
                      min="08:00"
                      max="20:00"
                      voiceEnabled={false}
                      labelColor={isDark ? 'text-[#b0b3b8]' : 'text-gray-600'}
                    />
                    <p className={`mt-1 text-[11px] ${isDark ? 'text-[#8a8d91]' : 'text-gray-400'}`}>
                      Leave blank for a full-day closure. Must be between 8:00 AM and 8:00 PM. Only affects the first day — if this is a multi-day range, later days are closed all day regardless.
                    </p>
                    {fieldErrors.closed_from_time && <p className="mt-1 text-xs font-semibold text-red-500">{fieldErrors.closed_from_time[0]}</p>}
                  </div>
                </div>

                <FormActions isDark={isDark} saving={saving} onCancel={closeForm} editingId={editingId} createLabel="Add Closure" />
              </form>
            ) : (
              <form onSubmit={handleOverrideSubmit} noValidate className="flex flex-col flex-1 overflow-y-auto">
                <div className="px-6 py-5 space-y-4 flex-1">
                  <div>
                    <DropdownGroup
                      label="Day of Week"
                      name="day_of_week"
                      value={capitalize(overrideForm.day_of_week)}
                      onChange={(e) => setOverrideForm((prev) => ({ ...prev, day_of_week: e.target.value.toLowerCase() }))}
                      options={DAYS_OF_WEEK.map(capitalize)}
                      required
                      labelColor={isDark ? 'text-[#b0b3b8]' : 'text-gray-600'}
                    />
                    {fieldErrors.day_of_week && <p className="mt-1 text-xs font-semibold text-red-500">{fieldErrors.day_of_week[0]}</p>}
                  </div>

                  <div>
                    <InputGroup
                      label="Label"
                      name="label"
                      value={overrideForm.label}
                      onChange={(e) => setOverrideForm((prev) => ({ ...prev, label: e.target.value }))}
                      placeholder="e.g. WFH Monday"
                      required
                      voiceEnabled={false}
                      labelColor={isDark ? 'text-[#b0b3b8]' : 'text-gray-600'}
                    />
                    {fieldErrors.label && <p className="mt-1 text-xs font-semibold text-red-500">{fieldErrors.label[0]}</p>}
                  </div>

                  <div>
                    <label className={`block text-xs font-bold uppercase tracking-wider mb-2 ${isDark ? 'text-[#b0b3b8]' : 'text-gray-600'}`}>Status on This Day</label>
                    <div className={`inline-flex p-1 rounded-lg border ${isDark ? 'bg-[#18191a] border-[#3e4042]' : 'bg-gray-100 border-gray-300'}`}>
                      <button
                        type="button"
                        onClick={() => setOverrideForm((prev) => ({ ...prev, is_closed: true }))}
                        className={`px-5 py-1.5 rounded-md text-xs font-bold transition-all cursor-pointer ${
                          overrideForm.is_closed
                            ? (isDark ? 'bg-amber-400 text-black shadow-xs' : 'bg-[#800000] text-white shadow-xs')
                            : (isDark ? 'text-[#b0b3b8] hover:text-white' : 'text-gray-600 hover:text-gray-900')
                        }`}
                      >
                        Closed
                      </button>
                      <button
                        type="button"
                        onClick={() => setOverrideForm((prev) => ({ ...prev, is_closed: false }))}
                        className={`px-5 py-1.5 rounded-md text-xs font-bold transition-all cursor-pointer ${
                          !overrideForm.is_closed
                            ? (isDark ? 'bg-amber-400 text-black shadow-xs' : 'bg-[#800000] text-white shadow-xs')
                            : (isDark ? 'text-[#b0b3b8] hover:text-white' : 'text-gray-600 hover:text-gray-900')
                        }`}
                      >
                        Open
                      </button>
                    </div>
                  </div>

                  <div className="grid grid-cols-2 gap-4">
                    <div>
                      <InputGroup
                        label="Effective From"
                        name="effective_from"
                        type="date"
                        value={overrideForm.effective_from}
                        onChange={(e) => setOverrideForm((prev) => ({ ...prev, effective_from: e.target.value }))}
                        required
                        voiceEnabled={false}
                        labelColor={isDark ? 'text-[#b0b3b8]' : 'text-gray-600'}
                      />
                      {fieldErrors.effective_from && <p className="mt-1 text-xs font-semibold text-red-500">{fieldErrors.effective_from[0]}</p>}
                    </div>
                    <div>
                      <InputGroup
                        label="Effective Until"
                        name="effective_until"
                        type="date"
                        value={overrideForm.effective_until}
                        onChange={(e) => setOverrideForm((prev) => ({ ...prev, effective_until: e.target.value }))}
                        min={overrideForm.effective_from || undefined}
                        voiceEnabled={false}
                        labelColor={isDark ? 'text-[#b0b3b8]' : 'text-gray-600'}
                      />
                      <p className={`mt-1 text-[11px] ${isDark ? 'text-[#8a8d91]' : 'text-gray-400'}`}>Leave blank for "until further notice."</p>
                      {fieldErrors.effective_until && <p className="mt-1 text-xs font-semibold text-red-500">{fieldErrors.effective_until[0]}</p>}
                    </div>
                  </div>
                </div>

                <FormActions isDark={isDark} saving={saving} onCancel={closeForm} editingId={editingId} createLabel="Add Override" />
              </form>
            )}
          </div>
        </div>
      )}

      {/* Bulk 2027 PH Holidays Import Modal */}
      <ImportHolidaysModal
        isOpen={isImportModalOpen}
        onClose={() => setIsImportModalOpen(false)}
        isDark={isDark}
        existingExceptions={calendarExceptions}
        onImportSelected={handleBulkImportHolidays}
      />

      {/* Confirmation Delete Dialog */}
      <ConfirmationModal
        isOpen={!!deleteTarget}
        onClose={() => setDeleteTarget(null)}
        onConfirm={handleDelete}
        type="danger"
        title={deleteTarget?.kind === "exception" ? "Delete this closure?" : "Delete this recurring override?"}
        message={
          deleteTarget
            ? `"${deleteTarget.record.label}" will no longer affect the business calendar. This can't be undone.`
            : ""
        }
      />

      <SuccessToast message={successMsg} onClose={() => setSuccessMsg("")} />
      <ErrorToast message={errorMsg} onClose={() => setErrorMsg("")} />
    </main>
  );
};

export default BusinessCalendarManagement;