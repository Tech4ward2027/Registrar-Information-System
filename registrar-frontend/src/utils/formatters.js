import React from "react";

export const formatDateFormal = (date) => {
  if (!date) return "";
  const d = new Date(date);
  return d.toLocaleDateString("en-US", { year: "numeric", month: "long", day: "numeric" });
};

const getOrdinal = (n) => {
  const s = ["th", "st", "nd", "rd"];
  const v = n % 100;
  return n + (s[(v - 20) % 10] || s[v] || s[0]);
};

export const formatDateOrdinal = (date) => {
  const d = new Date(date);
  const day = d.getDate();
  const ordinal = getOrdinal(day);
  const suffix = ordinal.replace(/^\d+/, "");
  const month = d.toLocaleString("en-US", { month: "long" });
  const year = d.getFullYear();
  return React.createElement(
    React.Fragment,
    null,
    day,
    React.createElement("sup", null, suffix),
    " day of ",
    month,
    " ",
    year
  );
};

export const CURRENT_YEAR = new Date().getFullYear();

/**
 * Converts text into proper Title Case, preserving Roman numerals (e.g., III, IV)
 * and capitalized parts of hyphenated or apostrophed names.
 */
export const toProperCase = (value = "") => {
  if (!value) return "";
  return value
    .toString()
    .trim()
    .split(/\s+/)
    .map((token) => {
      if (/^[IVXLCDM]+$/i.test(token)) return token.toUpperCase();
      return token
        .toLowerCase()
        .replace(/(^|[-'])([a-z])/g, (_, sep, letter) => `${sep}${letter.toUpperCase()}`);
    })
    .join(" ");
};

/**
 * Formats a user, request, or profile object into a standard full name string.
 * Supports various formats (First Last, Last, First M., suffix handling, etc.)
 * and automatically normalizes camelCase/snake_case and nested profile patterns.
 */
export const formatName = (input, options = {}) => {
  if (!input) return "";

  // Extract nested profile if request or user object is passed
  const target =
    input.student_profile ||
    input.alumni_profile ||
    input.admin_profile ||
    input.undergrad_requestor_profile ||
    input.user?.student_profile ||
    input.user?.alumni_profile ||
    input.user?.admin_profile ||
    input.user?.undergrad_requestor_profile ||
    input;

  const first = target.first_name || target.first_name_input || target.firstName || target.target_first_name || "";
  const middle = target.middle_name || target.middleName || target.target_middle_name || "";
  const last = target.last_name || target.lastname || target.surname || target.lastName || target.target_last_name || "";
  const suffix = target.suffix || target.target_suffix || "";

  const {
    lastNameFirst = false,
    includeMiddle = true,
    middleInitialOnly = false,
  } = options;

  let formattedMiddle = middle.trim();
  if (formattedMiddle && middleInitialOnly) {
    formattedMiddle = `${formattedMiddle.charAt(0).toUpperCase()}.`;
  }

  const cleanFirst = toProperCase(first);
  const cleanMiddle = formattedMiddle ? toProperCase(formattedMiddle) : "";
  const cleanLast = toProperCase(last);
  const cleanSuffix = suffix.trim() ? toProperCase(suffix) : "";

  if (!cleanFirst && !cleanLast) {
    const rawFullName = target.full_name || target.name || input.full_name || input.name || "";
    if (rawFullName && rawFullName !== "N/A") {
      return toProperCase(rawFullName);
    }
    return "";
  }

  if (lastNameFirst) {
    let nameStr = cleanLast;
    if (cleanSuffix) {
      nameStr += ` ${cleanSuffix}`;
    }
    nameStr += `, ${cleanFirst}`;
    if (includeMiddle && cleanMiddle) {
      nameStr += ` ${cleanMiddle}`;
    }
    return nameStr.trim();
  } else {
    const parts = [cleanFirst];
    if (includeMiddle && cleanMiddle) {
      parts.push(cleanMiddle);
    }
    parts.push(cleanLast);
    if (cleanSuffix) {
      parts.push(cleanSuffix);
    }
    return parts.filter(Boolean).join(" ").trim();
  }
};