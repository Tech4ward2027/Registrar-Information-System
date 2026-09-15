import React, { useState, useMemo } from "react";
import {
  XMarkIcon,
  CheckIcon,
  CalendarDaysIcon,
  SparklesIcon,
  MagnifyingGlassIcon,
  ArrowPathIcon,
  CheckCircleIcon,
} from "@heroicons/react/24/outline";
import { formatDate } from "./BusinessCalendarComponents.jsx";

/**
 * Remaining Official Philippine National Holidays & Special Non-Working Days for 2026 (Nov–Dec)
 */
export const OFFICIAL_PH_HOLIDAYS_2026 = [
  { label: "All Saints' Day", type: "holiday", date: "2026-11-01", end_date: "2026-11-01", category: "Special Non-Working Day" },
  { label: "All Souls' Day", type: "holiday", date: "2026-11-02", end_date: "2026-11-02", category: "Special Non-Working Day" },
  { label: "Bonifacio Day", type: "holiday", date: "2026-11-30", end_date: "2026-11-30", category: "Regular Holiday" },
  { label: "Feast of the Immaculate Conception of Mary", type: "holiday", date: "2026-12-08", end_date: "2026-12-08", category: "Special Non-Working Day" },
  { label: "Christmas Eve", type: "holiday", date: "2026-12-24", end_date: "2026-12-24", category: "Special Non-Working Day" },
  { label: "Christmas Day", type: "holiday", date: "2026-12-25", end_date: "2026-12-25", category: "Regular Holiday" },
  { label: "Rizal Day", type: "holiday", date: "2026-12-30", end_date: "2026-12-30", category: "Regular Holiday" },
  { label: "Last Day of the Year", type: "holiday", date: "2026-12-31", end_date: "2026-12-31", category: "Special Non-Working Day" },
];

/**
 * Official Philippine National Holidays & Special Non-Working Days for 2027
 * (As declared in Official Proclamation Section 1)
 */
export const OFFICIAL_PH_HOLIDAYS_2027 = [
  { label: "New Year's Day", type: "holiday", date: "2027-01-01", end_date: "2027-01-01", category: "Regular Holiday" },
  { label: "Chinese New Year", type: "holiday", date: "2027-02-06", end_date: "2027-02-06", category: "Special Non-Working Day" },
  { label: "Maundy Thursday", type: "holiday", date: "2027-03-25", end_date: "2027-03-25", category: "Regular Holiday" },
  { label: "Good Friday", type: "holiday", date: "2027-03-26", end_date: "2027-03-26", category: "Regular Holiday" },
  { label: "Black Saturday", type: "holiday", date: "2027-03-27", end_date: "2027-03-27", category: "Special Non-Working Day" },
  { label: "Araw ng Kagitingan", type: "holiday", date: "2027-04-09", end_date: "2027-04-09", category: "Regular Holiday" },
  { label: "Labor Day", type: "holiday", date: "2027-05-01", end_date: "2027-05-01", category: "Regular Holiday" },
  { label: "Independence Day", type: "holiday", date: "2027-06-12", end_date: "2027-06-12", category: "Regular Holiday" },
  { label: "Ninoy Aquino Day", type: "holiday", date: "2027-08-21", end_date: "2027-08-21", category: "Special Non-Working Day" },
  { label: "National Heroes Day", type: "holiday", date: "2027-08-30", end_date: "2027-08-30", category: "Regular Holiday" },
  { label: "All Saints' Day", type: "holiday", date: "2027-11-01", end_date: "2027-11-01", category: "Special Non-Working Day" },
  { label: "All Souls' Day", type: "holiday", date: "2027-11-02", end_date: "2027-11-02", category: "Special Non-Working Day" },
  { label: "Bonifacio Day", type: "holiday", date: "2027-11-30", end_date: "2027-11-30", category: "Regular Holiday" },
  { label: "Feast of the Immaculate Conception of Mary", type: "holiday", date: "2027-12-08", end_date: "2027-12-08", category: "Special Non-Working Day" },
  { label: "Christmas Eve", type: "holiday", date: "2027-12-24", end_date: "2027-12-24", category: "Special Non-Working Day" },
  { label: "Christmas Day", type: "holiday", date: "2027-12-25", end_date: "2027-12-25", category: "Regular Holiday" },
  { label: "Rizal Day", type: "holiday", date: "2027-12-30", end_date: "2027-12-30", category: "Regular Holiday" },
  { label: "Last Day of the Year", type: "holiday", date: "2027-12-31", end_date: "2027-12-31", category: "Special Non-Working Day" },
];

/**
 * Premium, Industry-Standard Bulk Import Modal for Official Philippine Holidays
 */
export const ImportHolidaysModal = ({
  isOpen,
  onClose,
  isDark,
  existingExceptions = [],
  onImportSelected,
}) => {
  const [selectedYear, setSelectedYear] = useState("2027");
  const [searchQuery, setSearchQuery] = useState("");

  const isAlreadyAdded = (dateStr, label) => {
    return (existingExceptions || []).some((e) => {
      if (!e) return false;
      return e.date?.slice(0, 10) === dateStr || e.label?.trim().toLowerCase() === label.trim().toLowerCase();
    });
  };

  // Preset items for selected year
  const rawHolidays = selectedYear === "2026" ? OFFICIAL_PH_HOLIDAYS_2026 : OFFICIAL_PH_HOLIDAYS_2027;

  // Filtered by search query
  const currentHolidays = useMemo(() => {
    const q = searchQuery.toLowerCase().trim();
    if (!q) return rawHolidays;
    return rawHolidays.filter(
      (h) => h.label.toLowerCase().includes(q) || h.category.toLowerCase().includes(q) || h.date.includes(q)
    );
  }, [rawHolidays, searchQuery]);

  // Track checked dates
  const [selectedDates, setSelectedDates] = useState(() => {
    return OFFICIAL_PH_HOLIDAYS_2027.filter((h) => !isAlreadyAdded(h.date, h.label)).map((h) => h.date);
  });

  const [importing, setImporting] = useState(false);

  if (!isOpen) return null;

  const handleYearChange = (year) => {
    setSelectedYear(year);
    const yearHolidays = year === "2026" ? OFFICIAL_PH_HOLIDAYS_2026 : OFFICIAL_PH_HOLIDAYS_2027;
    const available = yearHolidays.filter((h) => !isAlreadyAdded(h.date, h.label)).map((h) => h.date);
    setSelectedDates(available);
  };

  const handleToggleSelect = (dateStr) => {
    setSelectedDates((prev) =>
      prev.includes(dateStr) ? prev.filter((d) => d !== dateStr) : [...prev, dateStr]
    );
  };

  const handleSelectAllNew = () => {
    const available = currentHolidays.filter((h) => !isAlreadyAdded(h.date, h.label)).map((h) => h.date);
    setSelectedDates((prev) => Array.from(new Set([...prev, ...available])));
  };

  const handleDeselectAll = () => {
    const currentDatesSet = new Set(currentHolidays.map((h) => h.date));
    setSelectedDates((prev) => prev.filter((d) => !currentDatesSet.has(d)));
  };

  const handleSubmit = async (e) => {
    e.preventDefault();
    if (selectedDates.length === 0 || importing) return;
    setImporting(true);
    try {
      const allPresets = [...OFFICIAL_PH_HOLIDAYS_2026, ...OFFICIAL_PH_HOLIDAYS_2027];
      const itemsToImport = allPresets.filter((h) => selectedDates.includes(h.date));
      await onImportSelected(itemsToImport);
      onClose();
    } catch (err) {
      console.error("Bulk holiday import error:", err);
    } finally {
      setImporting(false);
    }
  };

  const unaddedCount = currentHolidays.filter((h) => !isAlreadyAdded(h.date, h.label)).length;
  const selectedCountForYear = currentHolidays.filter((h) => selectedDates.includes(h.date) && !isAlreadyAdded(h.date, h.label)).length;

  return (
    <div className="fixed inset-0 z-99999 flex items-center justify-center p-4">
      {/* Backdrop */}
      <div
        className="fixed inset-0 bg-black/50 backdrop-blur-sm z-40"
        onClick={!importing ? onClose : undefined}
      />

      {/* Modal Card - Identical to LogbookDateRangeModal & MonthRangeModal */}
      <div
        className={`relative z-50 w-full max-w-xl my-4 rounded-xl shadow-[0_20px_50px_rgba(0,0,0,0.3)] overflow-hidden border flex flex-col max-h-[90vh] animate-in fade-in zoom-in duration-200 ${
          isDark
            ? "bg-[#242526] border-[#3e4042] text-[#e4e6eb]"
            : "bg-white border-[#800000]/20 text-gray-900"
        }`}
      >
        {/* Header - Identical to LogbookDateRangeModal & MonthRangeModal */}
        <div
          className={`px-6 py-5 border-b-4 shrink-0 ${
            isDark ? "bg-[#1f1f1f] border-[#b98b00]" : "bg-[#800000] border-[#FFD700]"
          }`}
        >
          <div className="flex items-center justify-between">
            <h3 className="text-xl text-white font-black uppercase tracking-tighter">
              Import Official Holidays
            </h3>
            <button
              onClick={onClose}
              disabled={importing}
              className="p-2 rounded hover:opacity-90 shrink-0 text-white cursor-pointer disabled:opacity-50"
              title="Close"
            >
              <XMarkIcon className="w-6 h-6 text-white" />
            </button>
          </div>
        </div>

        {/* Body - Identical layout & spacing */}
        <div className={`flex-1 overflow-y-auto px-6 py-6 space-y-6 ${isDark ? "text-[#e4e6eb]" : "text-[#4a0000]"}`}>
          
          {/* Controls section: Year Switcher & Search Bar */}
          <div className="space-y-4">
            <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
              {/* Year Switcher Pills */}
              <div className="flex flex-wrap items-center gap-2">
                <span className={`text-[10px] font-semibold uppercase tracking-widest ${isDark ? "text-[#6b6c6e]" : "text-gray-400"}`}>
                  Year:
                </span>

                <button
                  type="button"
                  onClick={() => handleYearChange("2026")}
                  className={`flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-[11px] font-semibold transition-all duration-150 border cursor-pointer ${
                    selectedYear === "2026"
                      ? isDark
                        ? "bg-[#800000] border-[#9a0000] text-[#FFD700] shadow-sm"
                        : "bg-[#800000] border-[#800000] text-[#FFD700] shadow-sm"
                      : isDark
                        ? "bg-[#2d2e30] border-[#3e4042] text-[#b0b3b8] hover:border-[#6b6c6e] hover:text-[#e4e6eb]"
                        : "bg-white border-gray-200 text-gray-600 hover:border-gray-400 hover:text-gray-800"
                  }`}
                >
                  2026 Remaining
                  <span className={`text-[9px] font-normal px-1 py-0.5 rounded ${
                    selectedYear === "2026"
                      ? "bg-white/20 text-current"
                      : isDark ? "bg-[#3e4042] text-[#6b6c6e]" : "bg-gray-100 text-gray-400"
                  }`}>
                    {OFFICIAL_PH_HOLIDAYS_2026.length}
                  </span>
                </button>

                <button
                  type="button"
                  onClick={() => handleYearChange("2027")}
                  className={`flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-[11px] font-semibold transition-all duration-150 border cursor-pointer ${
                    selectedYear === "2027"
                      ? isDark
                        ? "bg-[#800000] border-[#9a0000] text-[#FFD700] shadow-sm"
                        : "bg-[#800000] border-[#800000] text-[#FFD700] shadow-sm"
                      : isDark
                        ? "bg-[#2d2e30] border-[#3e4042] text-[#b0b3b8] hover:border-[#6b6c6e] hover:text-[#e4e6eb]"
                        : "bg-white border-gray-200 text-gray-600 hover:border-gray-400 hover:text-gray-800"
                  }`}
                >
                  2027 Proclamation
                  <span className={`text-[9px] font-normal px-1 py-0.5 rounded ${
                    selectedYear === "2027"
                      ? "bg-white/20 text-current"
                      : isDark ? "bg-[#3e4042] text-[#6b6c6e]" : "bg-gray-100 text-gray-400"
                  }`}>
                    {OFFICIAL_PH_HOLIDAYS_2027.length}
                  </span>
                </button>
              </div>

              {/* Quick Select / Deselect */}
              <div className="flex items-center gap-2 text-xs">
                <button
                  type="button"
                  onClick={handleSelectAllNew}
                  disabled={unaddedCount === 0}
                  className={`text-[11px] font-bold hover:underline cursor-pointer disabled:opacity-40 disabled:no-underline ${
                    isDark ? "text-[#f5c542]" : "text-[#800000]"
                  }`}
                >
                  Select All
                </button>
                <span className={`text-[10px] ${isDark ? "text-[#6b6c6e]" : "text-gray-300"}`}>|</span>
                <button
                  type="button"
                  onClick={handleDeselectAll}
                  disabled={selectedDates.length === 0}
                  className={`text-[11px] font-medium hover:underline cursor-pointer disabled:opacity-40 disabled:no-underline ${
                    isDark ? "text-[#b0b3b8]" : "text-gray-500"
                  }`}
                >
                  Clear
                </button>
              </div>
            </div>

            {/* Search Input matching standard input style */}
            <div className="relative">
              <input
                type="text"
                value={searchQuery}
                onChange={(e) => setSearchQuery(e.target.value)}
                placeholder="Search holiday name or category..."
                className={`text-xs px-3 py-2 pl-9 rounded-lg border focus:outline-none focus:ring-2 transition-colors w-full ${
                  isDark
                    ? "bg-[#2d2e30] border-[#4e4f50] text-[#e4e6eb] focus:ring-[#800000]/50 placeholder-gray-500"
                    : "bg-white border-gray-300 text-gray-700 focus:ring-[#800000]/30 placeholder-gray-400"
                }`}
              />
              <MagnifyingGlassIcon className={`w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 ${
                isDark ? "text-[#6b6c6e]" : "text-gray-400"
              }`} />
            </div>
          </div>

          {/* Holiday Checklist */}
          <div className="space-y-2 max-h-[42vh] overflow-y-auto pr-1">
            {currentHolidays.length === 0 ? (
              <div className={`p-6 rounded-lg border text-center text-xs ${
                isDark ? "border-[#3e4042] bg-[#2d2e30] text-[#b0b3b8]" : "border-gray-200 bg-gray-50 text-gray-500"
              }`}>
                No holidays found matching "{searchQuery}".
              </div>
            ) : (
              currentHolidays.map((item) => {
                const added = isAlreadyAdded(item.date, item.label);
                const isChecked = selectedDates.includes(item.date);

                return (
                  <div
                    key={item.date}
                    onClick={() => !added && handleToggleSelect(item.date)}
                    className={`p-3 rounded-lg border transition-colors flex items-center justify-between ${
                      added
                        ? isDark
                          ? "bg-[#1f2022] border-[#3e4042] opacity-50 cursor-not-allowed"
                          : "bg-gray-100 border-gray-200 opacity-60 cursor-not-allowed"
                        : isChecked
                          ? isDark
                            ? "bg-[#800000]/30 border-[#9a0000] text-[#FFD700] cursor-pointer"
                            : "bg-[#800000]/5 border-[#800000]/30 text-gray-900 cursor-pointer"
                          : isDark
                            ? "bg-[#2d2e30] border-[#3e4042] hover:border-[#6b6c6e] cursor-pointer"
                            : "bg-white border-gray-200 hover:border-gray-300 cursor-pointer"
                    }`}
                  >
                    <div className="flex items-center gap-3 min-w-0">
                      <input
                        type="checkbox"
                        disabled={added}
                        checked={added || isChecked}
                        onChange={() => !added && handleToggleSelect(item.date)}
                        className="h-4 w-4 rounded cursor-pointer accent-[#800000] dark:accent-[#f5c542]"
                      />

                      <div className="min-w-0">
                        <div className="flex items-center gap-2">
                          <span className={`text-xs font-bold truncate ${
                            added
                              ? "line-through text-gray-400 dark:text-gray-500"
                              : isDark ? "text-[#e4e6eb]" : "text-gray-900"
                          }`}>
                            {item.label}
                          </span>

                          <span className={`text-[9px] font-semibold px-1.5 py-0.5 rounded border uppercase ${
                            item.category === "Regular Holiday"
                              ? isDark
                                ? "bg-blue-950/40 text-blue-300 border-blue-800/50"
                                : "bg-blue-50 text-blue-700 border-blue-200"
                              : isDark
                                ? "bg-amber-950/40 text-amber-300 border-amber-800/50"
                                : "bg-amber-50 text-amber-800 border-amber-200"
                          }`}>
                            {item.category}
                          </span>
                        </div>

                        <span className={`text-[11px] block mt-0.5 ${isDark ? "text-[#b0b3b8]" : "text-gray-500"}`}>
                          {formatDate(item.date)}
                        </span>
                      </div>
                    </div>

                    {added && (
                      <span className={`text-[10px] font-semibold px-2 py-0.5 rounded ${
                        isDark ? "text-emerald-400 bg-emerald-950/40" : "text-emerald-700 bg-emerald-50"
                      }`}>
                        Added
                      </span>
                    )}
                  </div>
                );
              })
            )}
          </div>
        </div>

        {/* Footer - Identical to LogbookDateRangeModal & MonthRangeModal */}
        <div className={`px-6 py-4 border-t-2 shrink-0 flex items-center justify-between gap-3 ${
          isDark ? "bg-[#1f1f1f] border-[#3e4042]" : "bg-gray-50 border-gray-200"
        }`}>
          <span className={`text-xs font-medium ${isDark ? "text-[#b0b3b8]" : "text-gray-500"}`}>
            {selectedDates.length} selected
          </span>

          <div className="flex items-center gap-3">
            <button
              onClick={onClose}
              disabled={importing}
              className={`px-4 py-2 text-xs font-bold uppercase tracking-widest rounded transition-colors duration-150 ${
                isDark ? "text-[#f5c542] hover:bg-[#2a2a2a]" : "text-[#800000] hover:bg-gray-200"
              }`}
            >
              Cancel
            </button>
            <button
              onClick={handleSubmit}
              disabled={importing || selectedDates.length === 0}
              className={`px-5 py-2 rounded-md font-bold text-xs uppercase tracking-widest transition-colors duration-150 shadow-sm ${
                importing || selectedDates.length === 0
                  ? "bg-gray-400 text-gray-200 cursor-not-allowed shadow-none"
                  : isDark
                    ? "bg-[#3a3b3c] hover:bg-[#4e4f50] text-[#e4e6eb] border border-[#4e4f50]"
                    : "bg-[#800000] hover:bg-[#4a0000] text-[#FFD700]"
              }`}
            >
              {importing ? "Importing..." : `Import (${selectedDates.length})`}
            </button>
          </div>
        </div>
      </div>
    </div>
  );
};

export default ImportHolidaysModal;
