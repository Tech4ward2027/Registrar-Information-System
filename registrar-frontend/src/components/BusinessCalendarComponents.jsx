import React, { useRef, useState } from "react";
import {
  PencilSquareIcon,
  TrashIcon,
  CalendarDaysIcon,
  ArrowPathIcon,
  ChevronLeftIcon,
  ChevronRightIcon,
  ClockIcon,
} from "@heroicons/react/24/outline";
import DashboardDropdown from "../components/DashboardDropdown.jsx";

export const EXCEPTION_TYPES = [
  { value: "holiday", label: "Holiday" },
  { value: "suspension", label: "Suspension" },
  { value: "event", label: "Event" },
];

export const DAYS_OF_WEEK = [
  "monday", "tuesday", "wednesday", "thursday", "friday", "saturday", "sunday",
];

export const formatDate = (dateStr) => {
  if (!dateStr) return "—";
  return new Date(dateStr).toLocaleDateString("en-US", { month: "short", day: "numeric", year: "numeric" });
};

export const capitalize = (s) => (s ? s.charAt(0).toUpperCase() + s.slice(1) : s);

export const formatTime = (timeStr) => {
  if (!timeStr) return "";
  const [h, m] = timeStr.split(":");
  const d = new Date();
  d.setHours(Number(h), Number(m), 0, 0);
  return d.toLocaleTimeString("en-US", { hour: "numeric", minute: "2-digit" });
};

/**
 * Today's date formatted as a local 'YYYY-MM-DD' string.
 * Uses local getFullYear/getMonth/getDate to avoid UTC date boundary shifts.
 */
export const getTodayDateString = (refDate = new Date()) => {
  const d = refDate instanceof Date ? refDate : new Date(refDate);
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;
};

/**
 * Filter, enrich, and sort incoming (upcoming or active today) holidays & closures.
 *
 * @param {Array} exceptions - List of calendar exceptions / closures
 * @param {Object} [options]
 * @param {number} [options.limit] - Max number of items to return
 * @param {boolean} [options.holidayOnly=false] - If true, only return type === 'holiday'
 * @param {string|Date} [options.referenceDate=new Date()] - Reference date (defaults to today)
 * @returns {Array} Array of incoming holiday objects enriched with metadata (daysUntil, isToday, etc.)
 */
export const getIncomingHolidays = (exceptions = [], options = {}) => {
  const { limit, holidayOnly = false, referenceDate = new Date() } = options;
  const todayStr = getTodayDateString(referenceDate);
  const todayMs = new Date(todayStr).getTime();

  const incoming = (exceptions || [])
    .filter((e) => {
      if (!e || e.enabled === false) return false;
      if (holidayOnly && e.type !== "holiday") return false;
      const endStr = (e.end_date || e.date)?.slice(0, 10);
      return Boolean(endStr && endStr >= todayStr);
    })
    .map((e) => {
      const startStr = e.date?.slice(0, 10) ?? todayStr;
      const endStr = (e.end_date || e.date)?.slice(0, 10) ?? startStr;
      const startMs = new Date(startStr).getTime();
      const diffMs = startMs - todayMs;
      const daysUntil = Math.ceil(diffMs / (1000 * 60 * 60 * 24));
      const isToday = startStr <= todayStr && endStr >= todayStr;

      return {
        ...e,
        startDateStr: startStr,
        endDateStr: endStr,
        daysUntil: Math.max(0, daysUntil),
        isToday,
        isMultiDay: startStr !== endStr,
      };
    })
    .sort((a, b) => a.startDateStr.localeCompare(b.startDateStr));

  return typeof limit === "number" && limit > 0 ? incoming.slice(0, limit) : incoming;
};

/**
 * Get the next immediate incoming holiday or closure.
 */
export const getNextHoliday = (exceptions = [], options = {}) => {
  const incoming = getIncomingHolidays(exceptions, { ...options, limit: 1 });
  return incoming[0] || null;
};

/**
 * Check if a specific date (YYYY-MM-DD or Date object) falls on an active holiday/closure exception.
 */
export const isHolidayDate = (targetDate, exceptions = []) => {
  if (!targetDate) return false;
  const dateStr = typeof targetDate === "string" ? targetDate.slice(0, 10) : getTodayDateString(targetDate);

  return (exceptions || []).some((e) => {
    if (!e || e.enabled === false) return false;
    const start = e.date?.slice(0, 10);
    const end = (e.end_date || e.date)?.slice(0, 10);
    return Boolean(start && end && start <= dateStr && end >= dateStr);
  });
};

const formatNextOpen = (iso) => {
  if (!iso) return "";
  return new Date(iso).toLocaleString("en-US", {
    weekday: "short",
    month: "short",
    day: "numeric",
    hour: "numeric",
    minute: "2-digit",
    hour12: true,
  });
};

/**
 * Three status summary cards at the top
 */
export const OfficeStatusCards = ({ isDark, officeStatus, upcomingCount, weeklyScheduleCount, calendarExceptions = [] }) => {
  const isOpen = officeStatus?.is_open;
  const reason = officeStatus?.reason || (isOpen ? "Regular Hours" : "Closed");
  const nextOpen = officeStatus?.next_open_at;
  const closesAt = officeStatus?.closes_at;
  const nextHoliday = getNextHoliday(calendarExceptions);

  const yellowColorClass = isDark ? "border-l-yellow-400 text-yellow-400" : "border-l-yellow-400 text-yellow-500";
  const blueColorClass = isDark ? "border-l-blue-400 text-blue-400" : "border-l-blue-500 text-blue-500";

  return (
    <div className="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
      {/* 1. CURRENT OFFICE STATUS */}
      <div className={`p-6 rounded-xl shadow border flex flex-col justify-between transition-all ${
        isDark ? "bg-[#242526] border-[#3e4042]" : "bg-white border-gray-200"
      }`}>
        <div>
          <div className="flex items-center justify-between gap-2">
            <span className={`text-xs uppercase font-bold ${isDark ? "text-[#b0b3b8]" : "text-gray-400"}`}>
              CURRENT OFFICE STATUS
            </span>
            <span className={`inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[10px] font-bold border tracking-wide uppercase ${
              isOpen
                ? (isDark ? "bg-emerald-950/40 text-emerald-400 border-emerald-900/50" : "bg-emerald-50 text-emerald-700 border-emerald-200")
                : (isDark ? "bg-red-950/40 text-red-400 border-red-900/50" : "bg-red-50 text-red-750 border-red-200")
            }`}>
              <span className={`w-1.5 h-1.5 rounded-full ${isOpen ? "bg-emerald-500" : "bg-red-500"}`} />
              {isOpen ? "OPEN NOW" : "CLOSED NOW"}
            </span>
          </div>
          <h3 className={`text-xl font-extrabold mt-3 tracking-tight ${isDark ? "text-white" : "text-gray-900"}`}>
            {reason}
          </h3>
        </div>
        <div className={`text-xs mt-4 flex items-center gap-1.5 ${isDark ? "text-[#b0b3b8]" : "text-gray-500"}`}>
          <ClockIcon className="w-3.5 h-3.5 opacity-60" />
          <span>
            {isOpen
              ? `Closes at: ${formatNextOpen(closesAt)}`
              : nextOpen
                ? `Next opening: ${formatNextOpen(nextOpen)}`
                : "No scheduled re-opening"}
          </span>
        </div>
      </div>

      {/* 2. UPCOMING CLOSURES */}
      <div className={`p-6 rounded-xl shadow border border-l-4 flex flex-col justify-between transition-all ${
        isDark ? `bg-[#242526] border-[#3e4042] ${yellowColorClass}` : `bg-white border-gray-200 ${yellowColorClass}`
      }`}>
        <div>
          <span className={`text-xs uppercase font-bold ${isDark ? "text-[#b0b3b8]" : "text-gray-400"}`}>
            UPCOMING CLOSURES
          </span>
          <div className="flex items-baseline gap-1.5 mt-2">
            <span className={`text-3xl font-extrabold ${isDark ? "text-[#e4e6eb]" : "text-inherit"}`}>{upcomingCount}</span>
            <span className="text-xs font-semibold">upcoming active</span>
          </div>
        </div>
        <p className={`text-xs mt-3 leading-relaxed ${isDark ? "text-[#b0b3b8]" : "text-gray-500"}`}>
          {nextHoliday ? (
            <span>
              Next: <strong className={isDark ? "text-white" : "text-gray-800"}>{nextHoliday.label}</strong> ({formatDate(nextHoliday.date)}
              {nextHoliday.isToday ? " — Today" : nextHoliday.daysUntil > 0 ? ` — in ${nextHoliday.daysUntil} day${nextHoliday.daysUntil > 1 ? "s" : ""}` : ""})
            </span>
          ) : (
            "Declared exceptions for specific dates"
          )}
        </p>
      </div>

      {/* 3. WEEKLY SCHEDULE */}
      <div className={`p-6 rounded-xl shadow border border-l-4 flex flex-col justify-between transition-all ${
        isDark ? `bg-[#242526] border-[#3e4042] ${blueColorClass}` : `bg-white border-gray-200 ${blueColorClass}`
      }`}>
        <div>
          <span className={`text-xs uppercase font-bold ${isDark ? "text-[#b0b3b8]" : "text-gray-400"}`}>
            WEEKLY SCHEDULE
          </span>
          <div className="flex items-baseline gap-1.5 mt-2">
            <span className={`text-3xl font-extrabold ${isDark ? "text-[#e4e6eb]" : "text-inherit"}`}>{weeklyScheduleCount}</span>
            <span className="text-xs font-semibold">active standing</span>
          </div>
        </div>
        <p className={`text-xs mt-3 leading-relaxed ${isDark ? "text-[#b0b3b8]" : "text-gray-500"}`}>
          Standing rules (e.g. Work-from-Home days)
        </p>
      </div>
    </div>
  );
};

/**
 * Monthly Calendar Grid view component
 */
export const CalendarGridView = ({
  isDark,
  currentMonth,
  currentYear,
  onChangeMonth,
  onToday,
  calendarExceptions,
  calendarOverrides,
  onAddException,
  onAddOverride,
  onEditException,
  onDeleteException,
  onEditOverride,
  onDeleteOverride,
}) => {
  // Calendar months labels
  const MONTHS = [
    "January", "February", "March", "April", "May", "June",
    "July", "August", "September", "October", "November", "December"
  ];

  // Helper to build list of days for calendar display
  const getDays = () => {
    const days = [];
    const firstDayDate = new Date(currentYear, currentMonth, 1);
    
    // JS getDay() returns 0 for Sunday, 1 for Monday, etc.
    const firstDayOfWeek = firstDayDate.getDay();
    const prevMonthDate = new Date(currentYear, currentMonth, 0);
    const prevMonthDaysCount = prevMonthDate.getDate();
    
    // Padding days from previous month
    for (let i = firstDayOfWeek - 1; i >= 0; i--) {
      days.push({
        dayNumber: prevMonthDaysCount - i,
        isCurrentMonth: false,
        dateObj: new Date(currentYear, currentMonth - 1, prevMonthDaysCount - i),
      });
    }

    // Current month days
    const lastDayOfMonth = new Date(currentYear, currentMonth + 1, 0).getDate();
    for (let i = 1; i <= lastDayOfMonth; i++) {
      days.push({
        dayNumber: i,
        isCurrentMonth: true,
        dateObj: new Date(currentYear, currentMonth, i),
      });
    }

    // Padding days from next month to make grid full rows of 7
    const totalCells = Math.ceil(days.length / 7) * 7;
    const nextMonthDaysNeeded = totalCells - days.length;
    for (let i = 1; i <= nextMonthDaysNeeded; i++) {
      days.push({
        dayNumber: i,
        isCurrentMonth: false,
        dateObj: new Date(currentYear, currentMonth + 1, i),
      });
    }

    return days;
  };

  const gridDays = getDays();
  const today = new Date();
  const todayStr = `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, "0")}-${String(today.getDate()).padStart(2, "0")}`;

  const parseLocalDate = (dateStr) => {
    if (!dateStr) return new Date();
    const parts = dateStr.slice(0, 10).split("-");
    return new Date(Number(parts[0]), Number(parts[1]) - 1, Number(parts[2]));
  };

  // Evaluate the rule/exception for a given day in the calendar grid
  const getDayDetails = (dateObj) => {
    const y = dateObj.getFullYear();
    const m = String(dateObj.getMonth() + 1).padStart(2, "0");
    const d = String(dateObj.getDate()).padStart(2, "0");
    const dayStr = `${y}-${m}-${d}`;
    const dayOfWeekName = ["sunday", "monday", "tuesday", "wednesday", "thursday", "friday", "saturday"][dateObj.getDay()];

    // 1. Exception (dated closure) takes precedence
    const activeException = calendarExceptions.find((e) => {
      if (!e.enabled) return false;
      const start = e.date.slice(0, 10);
      const end = (e.end_date || e.date).slice(0, 10);
      return start <= dayStr && end >= dayStr;
    });

    if (activeException) {
      return {
        type: "exception",
        kind: activeException.type, // 'holiday', 'suspension', 'event'
        label: activeException.label,
        record: activeException,
      };
    }

    // 2. Weekly override takes next precedence
    const activeOverride = calendarOverrides.find((o) => {
      if (!o.enabled) return false;
      if (o.day_of_week !== dayOfWeekName) return false;
      const start = o.effective_from.slice(0, 10);
      const end = o.effective_until ? o.effective_until.slice(0, 10) : null;
      return start <= dayStr && (!end || end >= dayStr);
    });

    if (activeOverride) {
      return {
        type: "override",
        kind: activeOverride.is_closed ? "weekly-closed" : "weekly-open",
        label: activeOverride.label,
        record: activeOverride,
      };
    }

    // 3. Fallback: Weekend
    if (dateObj.getDay() === 0 || dateObj.getDay() === 6) {
      return {
        type: "weekend",
        kind: "weekend",
        label: "Weekend",
        record: null,
      };
    }

    return null;
  };

  // Filter exceptions that occur in the current month for the sidebar list
  const activeMonthExceptions = calendarExceptions.filter((e) => {
    const start = parseLocalDate(e.date);
    const end = parseLocalDate(e.end_date || e.date);
    const firstOfMonth = new Date(currentYear, currentMonth, 1);
    const lastOfMonth = new Date(currentYear, currentMonth + 1, 0);
    return start <= lastOfMonth && end >= firstOfMonth;
  });

  // Filter overrides that are active (effective) during or after the current month
  const activeMonthOverrides = calendarOverrides.filter((o) => {
    const firstOfMonth = new Date(currentYear, currentMonth, 1);
    const until = o.effective_until ? parseLocalDate(o.effective_until) : null;
    return !until || until >= firstOfMonth;
  });

  const handleCellClick = (dateObj, details) => {
    if (details && details.type === "exception" && details.record) {
      onEditException(details.record);
    } else if (details && details.type === "override" && details.record) {
      onEditOverride(details.record);
    } else {
      const y = dateObj.getFullYear();
      const m = String(dateObj.getMonth() + 1).padStart(2, "0");
      const d = String(dateObj.getDate()).padStart(2, "0");
      const cellStr = `${y}-${m}-${d}`;
      // A blank cell in the past isn't editable — nothing to open. Only
      // block *new* closures this way; a cell that already has a record
      // (handled above) stays clickable regardless of date, since editing
      // a past closure's other fields is still allowed. The backend
      // (StoreCalendarExceptionRequest) is the authoritative check —
      // this is just to stop the picker from pre-filling a date that
      // would be rejected anyway.
      if (cellStr < todayStr) return;
      onAddException(cellStr);
    }
  };

  return (
    <div className="grid grid-cols-1 lg:grid-cols-4 gap-6">
      {/* Calendar Grid Section */}
      <div className="lg:col-span-3">
        {/* Navigation Bar */}
        <div className="flex flex-wrap items-center gap-4 mb-5">
          <div className={`flex rounded-lg shadow-xs border ${isDark ? "bg-[#242526] border-[#3e4042]" : "bg-white border-gray-200"}`}>
            <button
              onClick={() => onChangeMonth(-1)}
              className={`p-2 transition-colors cursor-pointer rounded-l-lg hover:bg-gray-100 dark:hover:bg-[#3a3b3c] ${
                isDark ? "text-[#b0b3b8] hover:text-white" : "text-gray-600 hover:text-gray-800"
              }`}
            >
              <ChevronLeftIcon className="w-4 h-4" />
            </button>
            <button
              onClick={() => onChangeMonth(1)}
              className={`p-2 transition-colors cursor-pointer rounded-r-lg hover:bg-gray-100 dark:hover:bg-[#3a3b3c] ${
                isDark ? "text-[#b0b3b8] hover:text-white" : "text-gray-600 hover:text-gray-800"
              }`}
            >
              <ChevronRightIcon className="w-4 h-4" />
            </button>
          </div>
          <h2 className={`text-lg font-bold tracking-tight px-1 ${isDark ? "text-white" : "text-gray-900"}`}>
            {MONTHS[currentMonth]} {currentYear}
          </h2>
          <button
            onClick={onToday}
            className={`px-3 py-1.5 text-xs font-semibold rounded-lg border transition-all cursor-pointer hover:scale-[1.01] active:scale-[0.99] ${
              isDark 
                ? "bg-[#242526] border-[#3e4042] text-[#e4e6eb] hover:bg-[#2a2a2f]" 
                : "bg-white border-gray-200 text-gray-700 hover:bg-gray-50"
            }`}
          >
            Today
          </button>
        </div>

        {/* Legend */}
        <div className={`flex flex-wrap items-center gap-x-5 gap-y-2 mb-4 text-[11px] font-semibold tracking-wide uppercase ${
          isDark ? "text-[#b0b3b8]" : "text-gray-500"
        }`}>
          <span>LEGEND:</span>
          <span className="flex items-center gap-1.5"><span className="w-2 h-2 rounded-full bg-rose-500" /> Holiday</span>
          <span className="flex items-center gap-1.5"><span className="w-2 h-2 rounded-full bg-amber-500" /> Suspension</span>
          <span className="flex items-center gap-1.5"><span className="w-2 h-2 rounded-full bg-purple-500" /> Event</span>
          <span className="flex items-center gap-1.5"><span className="w-2 h-2 rounded-full bg-emerald-500" /> Weekly – open</span>
          <span className="flex items-center gap-1.5"><span className="w-2 h-2 rounded-full bg-blue-500" /> Weekly – closed</span>
          <span className="flex items-center gap-1.5"><span className="w-2 h-2 rounded-full bg-gray-400" /> Weekend</span>
        </div>

        {/* Calendar Grid Container */}
        <div className="flex flex-col gap-2">
          {/* Weekday Columns */}
          <div className={`grid grid-cols-7 text-center text-xs font-bold tracking-wider uppercase py-2 ${
            isDark ? "text-[#b0b3b8]" : "text-gray-500"
          }`}>
            <div><span className="hidden sm:inline">Sun</span><span className="sm:hidden">S</span></div>
            <div><span className="hidden sm:inline">Mon</span><span className="sm:hidden">M</span></div>
            <div><span className="hidden sm:inline">Tue</span><span className="sm:hidden">T</span></div>
            <div><span className="hidden sm:inline">Wed</span><span className="sm:hidden">W</span></div>
            <div><span className="hidden sm:inline">Thu</span><span className="sm:hidden">T</span></div>
            <div><span className="hidden sm:inline">Fri</span><span className="sm:hidden">F</span></div>
            <div><span className="hidden sm:inline">Sat</span><span className="sm:hidden">S</span></div>
          </div>

          {/* Grid Cells */}
          <div className="grid grid-cols-7 gap-2 bg-transparent">
            {gridDays.map((day, idx) => {
              const details = getDayDetails(day.dateObj);
              const y = day.dateObj.getFullYear();
              const m = String(day.dateObj.getMonth() + 1).padStart(2, "0");
              const d = String(day.dateObj.getDate()).padStart(2, "0");
              const currentCellStr = `${y}-${m}-${d}`;
              const isTodayCell = currentCellStr === todayStr;
              // An empty past day can't be turned into a new closure (see
              // handleCellClick) — styled as non-interactive so that's
              // obvious before the user tries to click it. A past day that
              // already has a record stays fully interactive (editable).
              const isUnselectablePastCell = !details && currentCellStr < todayStr;

              // Color styles for calendar capsules
              let capsuleClass = "";
              if (details) {
                if (details.kind === "holiday") {
                  capsuleClass = isDark
                    ? "bg-rose-950/30 text-rose-300 border-rose-900/40"
                    : "bg-rose-50 text-rose-700 border-rose-200";
                } else if (details.kind === "suspension") {
                  capsuleClass = isDark
                    ? "bg-amber-950/30 text-amber-300 border-amber-900/40"
                    : "bg-amber-50 text-amber-700 border-amber-200";
                } else if (details.kind === "event") {
                  capsuleClass = isDark
                    ? "bg-purple-950/30 text-purple-300 border-purple-900/40"
                    : "bg-purple-50 text-purple-700 border-purple-200";
                } else if (details.kind === "weekly-closed") {
                  capsuleClass = isDark
                    ? "bg-blue-950/30 text-blue-300 border-blue-900/40"
                    : "bg-blue-50 text-blue-700 border-blue-200";
                } else if (details.kind === "weekly-open") {
                  capsuleClass = isDark
                    ? "bg-emerald-950/30 text-emerald-300 border-emerald-900/40"
                    : "bg-emerald-50 text-emerald-700 border-emerald-200";
                } else if (details.kind === "weekend") {
                  capsuleClass = isDark
                    ? "bg-[#2d2f30] text-[#a0a3a6] border-transparent"
                    : "bg-gray-100 text-gray-500 border-transparent";
                }
              }

              // Color styles for dots in capsules
              let dotClass = "bg-gray-400";
              if (details) {
                if (details.kind === "holiday") dotClass = "bg-rose-500";
                else if (details.kind === "suspension") dotClass = "bg-amber-500";
                else if (details.kind === "event") dotClass = "bg-purple-500";
                else if (details.kind === "weekly-closed") dotClass = "bg-blue-500";
                else if (details.kind === "weekly-open") dotClass = "bg-emerald-500";
                else if (details.kind === "weekend") dotClass = "bg-gray-400";
              }

              return (
                <div
                  key={idx}
                  onClick={() => handleCellClick(day.dateObj, details)}
                  className={`min-h-[72px] sm:min-h-28 p-1 sm:p-2.5 rounded-lg sm:rounded-xl border flex flex-col justify-between transition-all select-none ${
                    isUnselectablePastCell ? "cursor-not-allowed" : "cursor-pointer"
                  } ${
                    isDark
                      ? day.isCurrentMonth
                        ? "bg-[#242526] hover:bg-[#2a2a2f]/60 border-[#3e4042]"
                        : "bg-[#18191a]/30 border-[#2d2f30]/40 text-[#8a8d91]/60"
                      : day.isCurrentMonth
                        ? "bg-white hover:bg-gray-50/80 border-gray-200"
                        : "bg-gray-50/50 border-gray-150/60 text-gray-400"
                  } ${isTodayCell ? (isDark ? "ring-2 ring-yellow-400 border-yellow-400 z-10" : "ring-2 ring-pup-dark-maroon border-pup-dark-maroon z-10") : ""} ${
                    isUnselectablePastCell ? "opacity-60 hover:bg-transparent" : ""
                  }`}
                >
                  <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-0.5">
                    <span className={`text-xs sm:text-sm font-extrabold ${
                      isTodayCell 
                        ? (isDark ? "text-yellow-400" : "text-pup-dark-maroon")
                        : day.isCurrentMonth
                          ? (isDark ? "text-white" : "text-gray-900")
                          : (isDark ? "text-[#5b5c5e]" : "text-gray-350")
                    }`}>
                      {day.dayNumber}
                    </span>
                  </div>

                  {/* Desktop text-based capsule */}
                  <div className="mt-1 hidden sm:flex flex-col gap-1 overflow-hidden">
                    {details && details.kind !== "weekend" && (
                      <div
                        className={`group/capsule relative px-2 py-0.5 rounded-md text-[9px] sm:text-[10px] font-bold border transition-all cursor-pointer flex items-center gap-1 truncate ${capsuleClass} hover:opacity-90 active:scale-[0.98]`}
                        title={`${details.label} (${capitalize(details.kind)})`}
                      >
                        <span className={`w-1.5 h-1.5 rounded-full shrink-0 ${dotClass}`} />
                        <span className="truncate pr-1">{details.label}</span>
                      </div>
                    )}
                  </div>

                  {/* Mobile-only dot indicator */}
                  <div className="flex sm:hidden justify-center items-center mt-1">
                    {details && details.kind !== "weekend" && (
                      <span 
                        className={`w-2 h-2 rounded-full ${dotClass}`} 
                        title={`${details.label} (${capitalize(details.kind)})`}
                      />
                    )}
                  </div>
                </div>
              );
            })}
          </div>
        </div>
      </div>

      {/* Sidebar Section Container */}
      <div className={`lg:col-span-1 rounded-2xl border p-5 flex flex-col gap-6 shadow-xs ${
        isDark ? "border-[#3e4042] bg-[#1a1a1c]/20" : "border-gray-200 bg-gray-50/50"
      }`}>
        {/* Active Month Closures */}
        <div>
          <h3 className={`text-xs font-bold uppercase tracking-wider mb-3 ${isDark ? "text-[#b0b3b8]" : "text-gray-500"}`}>
            {MONTHS[currentMonth].toUpperCase()} CLOSURES
          </h3>
          <div className="space-y-2 max-h-77.5 overflow-y-auto pr-1.5 custom-scrollbar">
            {activeMonthExceptions.length === 0 ? (
              <div className={`p-4 rounded-xl border text-center text-xs leading-relaxed ${
                isDark ? "border-[#3e4042] bg-[#242526]/50 text-[#b0b3b8]" : "border-gray-150 bg-white text-gray-500"
              }`}>
                No closures scheduled for this month.
              </div>
            ) : (
              activeMonthExceptions.map((item) => (
                <div
                  key={item.holiday_id}
                  className={`rounded-xl border p-3.5 relative group flex flex-col justify-between transition-all ${
                    isDark ? "bg-[#242526] border-[#3e4042]" : "bg-white border-gray-150"
                  } ${item.enabled ? "" : "opacity-50"}`}
                >
                  <div className="flex items-start justify-between gap-3">
                    <span className="text-xs font-extrabold text-gray-800 dark:text-white leading-snug">
                      {item.label}
                    </span>
                    <div className="flex items-center gap-1 shrink-0 opacity-85 group-hover:opacity-100 transition-opacity">
                      <button
                        onClick={() => onEditException(item)}
                        className={`rounded p-1 transition-colors cursor-pointer ${
                          isDark ? "hover:bg-[#3a3b3c] text-[#b0b3b8] hover:text-white" : "hover:bg-gray-100 text-gray-500 hover:text-gray-800"
                        }`}
                        title="Edit"
                      >
                        <PencilSquareIcon className="h-3.5 w-3.5" />
                      </button>
                      <button
                        onClick={() => onDeleteException(item)}
                        className={`rounded p-1 transition-colors cursor-pointer ${
                          isDark ? "hover:bg-red-950/40 text-[#b0b3b8] hover:text-red-400" : "hover:bg-red-50 text-gray-500 hover:text-red-600"
                        }`}
                        title="Delete"
                      >
                        <TrashIcon className="h-3.5 w-3.5" />
                      </button>
                    </div>
                  </div>
                  <span className={`text-[10px] mt-1.5 ${isDark ? "text-[#b0b3b8]" : "text-gray-500"}`}>
                    {item.date === item.end_date
                      ? formatDate(item.date)
                      : `${formatDate(item.date)} – ${formatDate(item.end_date)}`}
                    {item.closed_from_time && (
                      <span className="block mt-0.5 opacity-85">
                        (closed from {formatTime(item.closed_from_time)})
                      </span>
                    )}
                  </span>
                </div>
              ))
            )}
          </div>
        </div>

        <hr className={`border-t ${isDark ? "border-[#3e4042]" : "border-gray-150"}`} />

        {/* Weekly standing schedule */}
        <div>
          <h3 className={`text-xs font-bold uppercase tracking-wider mb-3 ${isDark ? "text-[#b0b3b8]" : "text-gray-500"}`}>
            WEEKLY SCHEDULE
          </h3>
          <div className="space-y-2 max-h-77.5 overflow-y-auto pr-1.5 custom-scrollbar">
            {activeMonthOverrides.length === 0 ? (
              <div className={`p-4 rounded-xl border text-center text-xs leading-relaxed ${
                isDark ? "border-[#3e4042] bg-[#242526]/50 text-[#b0b3b8]" : "border-gray-150 bg-white text-gray-500"
              }`}>
                No standing weekly rules active.
              </div>
            ) : (
              activeMonthOverrides.map((item) => (
                <div
                  key={item.override_id}
                  className={`rounded-xl border p-3.5 relative group flex flex-col justify-between transition-all ${
                    isDark ? "bg-[#242526] border-[#3e4042]" : "bg-white border-gray-150"
                  } ${item.enabled ? "" : "opacity-50"}`}
                >
                  <div className="flex items-start justify-between gap-3">
                    <span className="text-xs font-extrabold text-gray-800 dark:text-white leading-snug">
                      {capitalize(item.day_of_week)}: {item.label}
                    </span>
                    <div className="flex items-center gap-1 shrink-0 opacity-85 group-hover:opacity-100 transition-opacity">
                      <button
                        onClick={() => onEditOverride(item)}
                        className={`rounded p-1 transition-colors cursor-pointer ${
                          isDark ? "hover:bg-[#3a3b3c] text-[#b0b3b8] hover:text-white" : "hover:bg-gray-100 text-gray-500 hover:text-gray-800"
                        }`}
                        title="Edit"
                      >
                        <PencilSquareIcon className="h-3.5 w-3.5" />
                      </button>
                      <button
                        onClick={() => onDeleteOverride(item)}
                        className={`rounded p-1 transition-colors cursor-pointer ${
                          isDark ? "hover:bg-red-950/40 text-[#b0b3b8] hover:text-red-400" : "hover:bg-red-50 text-gray-500 hover:text-red-600"
                        }`}
                        title="Delete"
                      >
                        <TrashIcon className="h-3.5 w-3.5" />
                      </button>
                    </div>
                  </div>
                  <span className={`text-[10px] mt-1.5 flex flex-wrap items-center gap-x-1.5 ${isDark ? "text-[#b0b3b8]" : "text-gray-500"}`}>
                    <span className={`font-bold ${item.is_closed ? "text-red-500" : "text-emerald-500"}`}>
                      {item.is_closed ? "Closed" : "Open"}
                    </span>
                    <span className="opacity-45">•</span>
                    <span>
                      Until {item.effective_until ? formatDate(item.effective_until) : "further notice"}
                    </span>
                  </span>
                </div>
              ))
            )}
          </div>
        </div>
      </div>
    </div>
  );
};

export const FormActions = ({ isDark, saving, onCancel, editingId, createLabel }) => (
  <div className={`px-6 py-4 border-t-2 shrink-0 flex justify-end gap-4 ${isDark ? 'bg-[#1f1f1f] border-[#3e4042]' : 'bg-gray-50 border-gray-200'}`}>
    <button
      type="button"
      onClick={onCancel}
      disabled={saving}
      className={`px-4 py-2 text-xs font-bold uppercase tracking-widest rounded transition-colors duration-150 ${isDark ? 'text-[#f5c542] hover:bg-[#2a2a2a] disabled:opacity-50' : 'text-[#800000] hover:bg-gray-200 disabled:opacity-50'}`}
    >
      Cancel
    </button>
    <button
      type="submit"
      disabled={saving}
      className={
        `px-5 py-2 rounded-md font-bold text-xs uppercase tracking-widest transition-colors duration-150 shadow-sm ` +
        (!saving
          ? (isDark
              ? 'bg-[#3a3b3c] hover:bg-[#4e4f50] text-[#e4e6eb] border border-[#4e4f50]'
              : 'bg-[#800000] hover:bg-[#4a0000] text-[#FFD700]')
          : (isDark
              ? 'bg-[#3a3b3c] text-[#8f949e] border-[#4e4f50] cursor-not-allowed'
              : 'bg-[#800000] text-white cursor-not-allowed'))
      }
    >
      {saving ? "Saving..." : editingId ? "Save Changes" : createLabel}
    </button>
  </div>
);

export const ExceptionsTable = ({
  items,
  loading,
  isDark,
  typeBadge,
  onEdit,
  onDelete,
  onToggleEnabled,
  filterType,
  setFilterType,
}) => {
  const typeDropdownRef = useRef(null);
  const [typeDropdownOpen, setTypeDropdownOpen] = useState(false);

  if (loading) {
    return <div className={`py-16 text-center text-sm ${isDark ? "text-[#b0b3b8]" : "text-gray-500"}`}>Loading closures…</div>;
  }

  if (items.length === 0) {
    return (
      <div className={`flex flex-col items-center gap-2 py-16 text-center ${isDark ? "text-[#b0b3b8]" : "text-gray-500"}`}>
        <CalendarDaysIcon className="h-10 w-10 opacity-50" />
        <p className="text-sm font-medium">No closures found.</p>
        <p className="text-xs">Try a different search, or add a holiday, suspension, or event.</p>
      </div>
    );
  }

  return (
    <div className="overflow-x-auto">
      <table className="w-full min-w-200 text-sm">
        <thead>
          <tr className={isDark ? "border-b border-[#3e4042]" : "border-b border-gray-100"}>
            <th className={`px-5 py-4 text-left font-medium ${isDark ? "text-[#b0b3b8]" : "text-gray-500"}`}>Label</th>
            <th className={`px-5 py-4 text-left font-medium ${isDark ? "text-[#b0b3b8]" : "text-gray-500"}`}>
              <DashboardDropdown
                isOpen={typeDropdownOpen}
                setIsOpen={setTypeDropdownOpen}
                dropdownRef={typeDropdownRef}
                align="left"
                trigger={<span>Type</span>}
                sections={[
                  {
                    title: "Filter by Type",
                    items: [
                      { label: "All Types", isSelected: filterType === "all", onClick: () => setFilterType("all") },
                      { label: "Holiday", isSelected: filterType === "holiday", onClick: () => setFilterType("holiday") },
                      { label: "Suspension", isSelected: filterType === "suspension", onClick: () => setFilterType("suspension") },
                      { label: "Event", isSelected: filterType === "event", onClick: () => setFilterType("event") },
                    ],
                  },
                ]}
              />
            </th>
            <th className={`px-5 py-4 text-left font-medium ${isDark ? "text-[#b0b3b8]" : "text-gray-500"}`}>Dates</th>
            <th className={`px-5 py-4 text-center font-medium ${isDark ? "text-[#b0b3b8]" : "text-gray-500"}`}>Enabled</th>
            <th className={`px-5 py-4 text-center font-medium ${isDark ? "text-[#b0b3b8]" : "text-gray-500"}`}>Actions</th>
          </tr>
        </thead>
        <tbody>
          {items.map((item) => (
            <tr
              key={item.holiday_id}
              className={`border-b last:border-0 transition-colors ${isDark ? "border-[#3e4042] hover:bg-[#2a2a2f]" : "border-gray-100 hover:bg-gray-50"} ${item.enabled ? "" : "opacity-50"}`}
            >
              <td className={`px-5 py-4 font-semibold ${isDark ? "text-[#e4e6eb]" : "text-gray-800"}`}>{item.label}</td>
              <td className="px-5 py-4">
                <span className={`inline-flex px-2.5 py-1 rounded-full text-xs font-bold ${typeBadge[item.type] ?? ""}`}>
                  {capitalize(item.type)}
                </span>
              </td>
              <td className={`px-5 py-4 ${isDark ? "text-[#b0b3b8]" : "text-gray-600"}`}>
                {item.date === item.end_date
                  ? formatDate(item.date)
                  : `${formatDate(item.date)} – ${formatDate(item.end_date)}`}
                {item.closed_from_time && (
                  <span className={`block text-xs ${isDark ? "text-[#8a8d91]" : "text-gray-400"}`}>
                    (closed from {formatTime(item.closed_from_time)})
                  </span>
                )}
              </td>
              <td className="px-5 py-4 text-center">
                <EnabledSwitch isDark={isDark} enabled={item.enabled} onToggle={() => onToggleEnabled(item)} />
              </td>
              <td className="px-5 py-4 text-center">
                <RowActions isDark={isDark} onEdit={() => onEdit(item)} onDelete={() => onDelete(item)} />
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
};

export const OverridesTable = ({
  items,
  loading,
  isDark,
  onEdit,
  onDelete,
  onToggleEnabled,
  filterStatus,
  setFilterStatus,
}) => {
  const statusDropdownRef = useRef(null);
  const [statusDropdownOpen, setStatusDropdownOpen] = useState(false);

  if (loading) {
    return <div className={`py-16 text-center text-sm ${isDark ? "text-[#b0b3b8]" : "text-gray-500"}`}>Loading recurring overrides…</div>;
  }

  if (items.length === 0) {
    return (
      <div className={`flex flex-col items-center gap-2 py-16 text-center ${isDark ? "text-[#b0b3b8]" : "text-gray-500"}`}>
        <ArrowPathIcon className="h-10 w-10 opacity-50" />
        <p className="text-sm font-medium">No recurring overrides found.</p>
        <p className="text-xs">Try a different search, or add a standing weekly rule.</p>
      </div>
    );
  }

  return (
    <div className="overflow-x-auto">
      <table className="w-full min-w-225 text-sm">
        <thead>
          <tr className={isDark ? "border-b border-[#3e4042]" : "border-b border-gray-100"}>
            <th className={`px-5 py-4 text-left font-medium ${isDark ? "text-[#b0b3b8]" : "text-gray-500"}`}>Label</th>
            <th className={`px-5 py-4 text-left font-medium ${isDark ? "text-[#b0b3b8]" : "text-gray-500"}`}>Day</th>
            <th className={`px-5 py-4 text-left font-medium ${isDark ? "text-[#b0b3b8]" : "text-gray-500"}`}>
              <DashboardDropdown
                isOpen={statusDropdownOpen}
                setIsOpen={setStatusDropdownOpen}
                dropdownRef={statusDropdownRef}
                align="left"
                trigger={<span>Status</span>}
                sections={[
                  {
                    title: "Filter by Status",
                    items: [
                      { label: "All Statuses", isSelected: filterStatus === "all", onClick: () => setFilterStatus("all") },
                      { label: "Closed", isSelected: filterStatus === "closed", onClick: () => setFilterStatus("closed") },
                      { label: "Open", isSelected: filterStatus === "open", onClick: () => setFilterStatus("open") },
                    ],
                  },
                ]}
              />
            </th>
            <th className={`px-5 py-4 text-left font-medium ${isDark ? "text-[#b0b3b8]" : "text-gray-500"}`}>Effective</th>
            <th className={`px-5 py-4 text-center font-medium ${isDark ? "text-[#b0b3b8]" : "text-gray-500"}`}>Enabled</th>
            <th className={`px-5 py-4 text-center font-medium ${isDark ? "text-[#b0b3b8]" : "text-gray-500"}`}>Actions</th>
          </tr>
        </thead>
        <tbody>
          {items.map((item) => (
            <tr
              key={item.override_id}
              className={`border-b last:border-0 transition-colors ${isDark ? "border-[#3e4042] hover:bg-[#2a2a2f]" : "border-gray-100 hover:bg-gray-50"} ${item.enabled ? "" : "opacity-50"}`}
            >
              <td className={`px-5 py-4 font-semibold ${isDark ? "text-[#e4e6eb]" : "text-gray-800"}`}>{item.label}</td>
              <td className={`px-5 py-4 ${isDark ? "text-[#b0b3b8]" : "text-gray-600"}`}>{capitalize(item.day_of_week)}</td>
              <td className="px-5 py-4">
                <span className={`inline-flex px-2.5 py-1 rounded-full text-xs font-bold ${
                  item.is_closed
                    ? (isDark ? "bg-red-900/30 text-red-300" : "bg-red-50 text-red-700")
                    : (isDark ? "bg-green-900/30 text-green-300" : "bg-green-50 text-green-700")
                }`}>
                  {item.is_closed ? "Closed" : "Open"}
                </span>
              </td>
              <td className={`px-5 py-4 ${isDark ? "text-[#b0b3b8]" : "text-gray-600"}`}>
                {formatDate(item.effective_from)} – {item.effective_until ? formatDate(item.effective_until) : "Until further notice"}
              </td>
              <td className="px-5 py-4 text-center">
                <EnabledSwitch isDark={isDark} enabled={item.enabled} onToggle={() => onToggleEnabled(item)} />
              </td>
              <td className="px-5 py-4 text-center">
                <RowActions isDark={isDark} onEdit={() => onEdit(item)} onDelete={() => onDelete(item)} />
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
};

export const EnabledSwitch = ({ isDark, enabled, onToggle }) => (
  <button
    type="button"
    onClick={onToggle}
    aria-pressed={enabled}
    title={enabled ? "Enabled — click to disable" : "Disabled — click to enable"}
    className={`relative inline-flex w-10 h-6 rounded-full transition-colors duration-200 cursor-pointer focus:outline-none ${
      enabled ? (isDark ? "bg-green-950/40 border border-emerald-900/30" : "bg-gray-700") : (isDark ? "bg-[#3e4042]" : "bg-gray-300")
    }`}
  >
    <span
      className={`inline-block w-4 h-4 mt-1 rounded-full bg-white shadow transform transition-transform duration-200 ${
        enabled ? "translate-x-5" : "translate-x-1"
      }`}
    />
  </button>
);

export const RowActions = ({ isDark, onEdit, onDelete }) => (
  <div className="flex items-center justify-center gap-1">
    <button
      onClick={onEdit}
      className={`rounded-md p-2 transition-colors cursor-pointer ${isDark ? "hover:bg-[#3a3b3c] text-[#b0b3b8] hover:text-white" : "hover:bg-gray-100 text-gray-500 hover:text-gray-800"}`}
      title="Edit"
    >
      <PencilSquareIcon className="h-4 w-4" />
    </button>
    <button
      onClick={onDelete}
      className={`rounded-md p-2 transition-colors cursor-pointer ${isDark ? "hover:bg-red-950/40 text-[#b0b3b8] hover:text-red-400" : "hover:bg-red-50 text-gray-500 hover:text-red-600"}`}
      title="Delete"
    >
      <TrashIcon className="h-4 w-4" />
    </button>
  </div>
);

export const Pagination = ({ isDark, meta, onPrev, onNext }) => {
  if (!meta || meta.last_page <= 1) return null;

  return (
    <div className={`flex items-center justify-between gap-3 px-5 py-3 border-t ${isDark ? "border-[#3e4042]" : "border-gray-100"}`}>
      <span className={`text-xs font-medium ${isDark ? "text-[#b0b3b8]" : "text-gray-500"}`}>
        Page {meta.current_page} of {meta.last_page}
      </span>
      <div className="flex items-center gap-1">
        <button
          onClick={onPrev}
          disabled={meta.current_page <= 1}
          className={`rounded-md p-1.5 transition-colors disabled:opacity-40 disabled:cursor-not-allowed cursor-pointer ${isDark ? "hover:bg-[#3a3b3c] text-[#b0b3b8]" : "hover:bg-gray-100 text-gray-600"}`}
        >
          <ChevronLeftIcon className="h-4 w-4" />
        </button>
        <button
          onClick={onNext}
          disabled={meta.current_page >= meta.last_page}
          className={`rounded-md p-1.5 transition-colors disabled:opacity-40 disabled:cursor-not-allowed cursor-pointer ${isDark ? "hover:bg-[#3a3b3c] text-[#b0b3b8]" : "hover:bg-gray-100 text-gray-600"}`}
        >
          <ChevronRightIcon className="h-4 w-4" />
        </button>
      </div>
    </div>
  );
};