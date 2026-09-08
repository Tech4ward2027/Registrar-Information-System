import React from "react";
import PropTypes from "prop-types";
import { ClockIcon } from "@heroicons/react/24/outline";
import { useTheme } from "../context/ThemeContext";

const parsePeriod = (periodStr) => {
  if (!periodStr) return { days: "", hours: "", minutes: "" };
  const normalized = periodStr.toLowerCase();

  let days = "";
  let hours = "";
  let minutes = "";

  const dayMatch = normalized.match(/([^\s,]+)\s*working\s*day/) || normalized.match(/([^\s,]+)\s*day/);
  if (dayMatch) {
    days = dayMatch[1];
  }

  const hourMatch = normalized.match(/([^\s,]+)\s*hour/) || normalized.match(/([^\s,]+)\s*hr/);
  if (hourMatch) {
    hours = hourMatch[1];
  }

  const minMatch = normalized.match(/([^\s,]+)\s*minute/) || normalized.match(/([^\s,]+)\s*min/);
  if (minMatch) {
    minutes = minMatch[1];
  }

  if (!days && !hours && !minutes && periodStr.trim()) {
    days = periodStr.trim();
  }

  return { days, hours, minutes };
};

const formatPeriod = ({ days, hours, minutes }) => {
  const parts = [];
  if (days !== "" && days !== undefined && days !== null) {
    const d = parseFloat(days);
    if (!isNaN(d)) {
      parts.push(`${days} working ${d === 1 ? "day" : "days"}`);
    }
  }
  if (hours !== "" && hours !== undefined && hours !== null) {
    const h = parseFloat(hours);
    if (!isNaN(h) && h !== 0) {
      parts.push(`${hours} ${h === 1 ? "hour" : "hours"}`);
    }
  }
  if (minutes !== "" && minutes !== undefined && minutes !== null) {
    const m = parseFloat(minutes);
    if (!isNaN(m) && m !== 0) {
      parts.push(`${minutes} ${m === 1 ? "minute" : "minutes"}`);
    }
  }
  return parts.join(", ") || "";
};

const ProcessPeriodInput = ({
  label = "Process Period",
  name,
  value = "",
  onChange,
  required = false,
  labelColor = "text-white",
}) => {
  const { isDark } = useTheme();

  // Derive days, hours, and minutes directly from the single source of truth (value prop)
  const { days, hours, minutes } = parsePeriod(value);

  const triggerChange = (newDays, newHours, newMinutes) => {
    const formatted = formatPeriod({
      days: newDays,
      hours: newHours,
      minutes: newMinutes,
    });
    onChange({ target: { name, value: formatted } });
  };

  const handleDaysChange = (val) => {
    triggerChange(val, hours, minutes);
  };

  const handleHoursChange = (val) => {
    triggerChange(days, val, minutes);
  };

  const handleMinutesChange = (val) => {
    triggerChange(days, hours, val);
  };

  const currentFormatted = formatPeriod({ days, hours, minutes });

  return (
    <div className="w-full flex flex-col gap-3">
      {/* Label and Title */}
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-1.5">
        <label className={`block text-sm font-medium ${isDark ? "text-[#e4e6eb]" : labelColor}`}>
          {label}
          {required && (
            <span className={isDark ? "text-[#FFC72C] ml-1" : "text-red-400 ml-1"}>*</span>
          )}
          {currentFormatted && (
            <span className={`ml-2 text-xs font-normal ${isDark ? "text-gray-400" : "text-gray-500"}`}>
              {currentFormatted}
            </span>
          )}
        </label>
      </div>
      

      {/* Responsive Wrapper Container */}
      <div className="flex flex-col gap-3 items-stretch">
        {/* Inline Input Steppers Row */}
        <div className="flex flex-wrap items-center gap-3 sm:gap-4 flex-1 shrink-0">
          {/* Days */}
          <div className="flex items-center gap-1.5 shrink-0">
            <input
              type="number"
              min="1"
              max="30"
              value={days || ""}
              onChange={(e) => handleDaysChange(e.target.value)}
              placeholder="0"
              className={`w-28 px-3 py-2 text-center rounded-lg text-sm font-medium shadow-sm transition-all duration-200 focus:outline-none focus:ring-2 focus:ring-[#FFC72C] ${isDark
                ? "bg-[#1f1f1f] text-[#e4e6eb] border border-[#3e4042] placeholder:text-[#8f949d]"
                : "bg-white text-gray-700 border border-gray-200 placeholder:text-gray-400"
                }`}
            />
            <span className={`text-xs font-semibold whitespace-nowrap ${isDark ? "text-gray-300" : "text-gray-600"}`}>
              Days
            </span>
          </div>

          {/* Hours */}
          <div className="flex items-center gap-1.5 shrink-0">
            <input
              type="number"
              min="0"
              max="23"
              value={hours || ""}
              onChange={(e) => handleHoursChange(e.target.value)}
              placeholder="0"
              className={`w-28 px-3 py-2 text-center rounded-lg text-sm font-medium shadow-sm transition-all duration-200 focus:outline-none focus:ring-2 focus:ring-[#FFC72C] ${isDark
                ? "bg-[#1f1f1f] text-[#e4e6eb] border border-[#3e4042] placeholder:text-[#8f949d]"
                : "bg-white text-gray-700 border border-gray-200 placeholder:text-gray-400"
                }`}
            />
            <span className={`text-xs font-semibold whitespace-nowrap ${isDark ? "text-gray-300" : "text-gray-600"}`}>
              Hours
            </span>
          </div>

          {/* Minutes */}
          <div className="flex items-center gap-1.5 shrink-0">
            <input
              type="number"
              min="0"
              max="59"
              value={minutes || ""}
              onChange={(e) => handleMinutesChange(e.target.value)}
              placeholder="0"
              className={`w-28 px-3 py-2 text-center rounded-lg text-sm font-medium shadow-sm transition-all duration-200 focus:outline-none focus:ring-2 focus:ring-[#FFC72C] ${isDark
                ? "bg-[#1f1f1f] text-[#e4e6eb] border border-[#3e4042] placeholder:text-[#8f949d]"
                : "bg-white text-gray-700 border border-gray-200 placeholder:text-gray-400"
                }`}
            />
            <span className={`text-xs font-semibold whitespace-nowrap ${isDark ? "text-gray-300" : "text-gray-600"}`}>
              Mins
            </span>
          </div>
        </div>
      </div>
    </div>
  );
};

ProcessPeriodInput.propTypes = {
  label: PropTypes.string,
  name: PropTypes.string.isRequired,
  value: PropTypes.string,
  onChange: PropTypes.func.isRequired,
  required: PropTypes.bool,
  labelColor: PropTypes.string,
};

export default ProcessPeriodInput;
