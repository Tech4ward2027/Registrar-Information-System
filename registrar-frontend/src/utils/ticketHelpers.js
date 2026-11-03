// -------------------------------------------------------
// Support Ticketing UI helpers, formatters, and error parsers.
//
// IMPORTANT:
// - Status transitions, priority assignment, and SLA deadlines/states
//   are ALWAYS driven by backend values (never computed client-side).
// - Tickets are addressed exclusively by `id` (26-char lowercase ULID `public_id`).
// -------------------------------------------------------

export const isTicketsFeatureEnabled = (user) => user?.features?.tickets === true;

export const TICKET_STATUS_META = {
  open: {
    label: "Open",
    light: "bg-sky-50 text-sky-700 border-sky-200",
    dark: "bg-sky-950/50 text-sky-300 border-sky-800/60",
    dot: "bg-sky-500",
  },
  in_progress: {
    label: "In Progress",
    light: "bg-indigo-50 text-indigo-700 border-indigo-200",
    dark: "bg-indigo-950/50 text-indigo-300 border-indigo-800/60",
    dot: "bg-indigo-500",
  },
  pending_client: {
    label: "Pending Client",
    light: "bg-amber-50 text-amber-800 border-amber-200",
    dark: "bg-amber-950/50 text-amber-300 border-amber-800/60",
    dot: "bg-amber-500",
  },
  resolved: {
    label: "Resolved",
    light: "bg-emerald-50 text-emerald-700 border-emerald-200",
    dark: "bg-emerald-950/50 text-emerald-300 border-emerald-800/60",
    dot: "bg-emerald-500",
  },
  closed: {
    label: "Closed",
    light: "bg-gray-100 text-gray-600 border-gray-300",
    dark: "bg-zinc-800/80 text-gray-400 border-zinc-700",
    dot: "bg-gray-400",
  },
};

export const TICKET_PRIORITY_META = {
  high: {
    label: "High",
    light: "bg-rose-50 text-rose-700 border-rose-200",
    dark: "bg-rose-950/50 text-rose-300 border-rose-800/60",
  },
  medium: {
    label: "Medium",
    light: "bg-amber-50 text-amber-800 border-amber-200",
    dark: "bg-amber-950/50 text-amber-300 border-amber-800/60",
  },
  low: {
    label: "Low",
    light: "bg-slate-100 text-slate-700 border-slate-200",
    dark: "bg-slate-800/70 text-slate-300 border-slate-700",
  },
};

export const TICKET_SLA_META = {
  on_track: {
    label: "On Track",
    light: "bg-emerald-50 text-emerald-700 border-emerald-200",
    dark: "bg-emerald-950/40 text-emerald-300 border-emerald-800/50",
  },
  at_risk: {
    label: "At Risk",
    light: "bg-amber-50 text-amber-800 border-amber-300",
    dark: "bg-amber-950/50 text-amber-300 border-amber-700/60",
  },
  breached: {
    label: "Breached",
    light: "bg-red-50 text-red-700 border-red-300",
    dark: "bg-red-950/50 text-red-300 border-red-800/60",
  },
  paused: {
    label: "SLA Paused",
    light: "bg-purple-50 text-purple-700 border-purple-200",
    dark: "bg-purple-950/40 text-purple-300 border-purple-800/50",
  },
  met: {
    label: "Met",
    light: "bg-emerald-50 text-emerald-700 border-emerald-200",
    dark: "bg-emerald-950/40 text-emerald-300 border-emerald-800/50",
  },
  missed: {
    label: "Missed",
    light: "bg-rose-50 text-rose-700 border-rose-200",
    dark: "bg-rose-950/40 text-rose-300 border-rose-800/50",
  },
  none: {
    label: "No SLA",
    light: "bg-gray-100 text-gray-500 border-gray-200",
    dark: "bg-zinc-800 text-gray-400 border-zinc-700",
  },
};

export const getStatusBadgeClass = (status, isDark) => {
  const meta = TICKET_STATUS_META[status] || TICKET_STATUS_META.open;
  return isDark ? meta.dark : meta.light;
};

export const getPriorityBadgeClass = (priority, isDark) => {
  const meta = TICKET_PRIORITY_META[priority] || TICKET_PRIORITY_META.low;
  return isDark ? meta.dark : meta.light;
};

export const getSlaBadgeClass = (state, isDark) => {
  const meta = TICKET_SLA_META[state] || TICKET_SLA_META.none;
  return isDark ? meta.dark : meta.light;
};

export const formatPriorityLabel = (priority) =>
  TICKET_PRIORITY_META[priority]?.label || (priority ? String(priority).toUpperCase() : "Low");

export const formatSlaStateLabel = (state) =>
  TICKET_SLA_META[state]?.label || (state ? String(state).replace(/_/g, " ") : "N/A");

export const formatEventLabel = (eventType) => {
  const map = {
    created: "Ticket submitted",
    claimed: "Claimed from queue",
    released: "Released back to queue",
    claim_expired: "Stale claim auto-released",
    staff_replied: "Staff replied",
    staff_asked_question: "Staff requested information (Pending Client)",
    requester_replied: "Requester replied",
    resolved: "Marked Resolved",
    returned: "Returned to In Progress by requester",
    confirmed: "Resolution confirmed by requester",
    auto_closed: "Automatically closed after resolution window",
    closed: "Ticket closed",
    spam_flagged: "Flagged as spam",
    sla_first_response_at_risk: "First-response SLA flagged at risk",
    sla_first_response_breached: "First-response SLA breached",
    sla_resolution_at_risk: "Resolution SLA flagged at risk",
    sla_resolution_breached: "Resolution SLA breached",
  };
  if (map[eventType]) return map[eventType];
  return String(eventType || "event")
    .replace(/_/g, " ")
    .replace(/\b\w/g, (c) => c.toUpperCase());
};

export const formatTicketDateTime = (isoString) => {
  if (!isoString) return "—";
  const date = new Date(isoString);
  if (Number.isNaN(date.getTime())) return "—";
  return date.toLocaleString("en-PH", {
    timeZone: "Asia/Manila",
    month: "short",
    day: "numeric",
    year: "numeric",
    hour: "numeric",
    minute: "2-digit",
  });
};

export const formatTicketRelative = (isoString) => {
  if (!isoString) return "";
  const date = new Date(isoString);
  if (Number.isNaN(date.getTime())) return "";
  const diffSec = Math.floor((Date.now() - date.getTime()) / 1000);
  if (diffSec < 45) return "Just now";
  if (diffSec < 3600) return `${Math.floor(diffSec / 60)}m ago`;
  if (diffSec < 86400) return `${Math.floor(diffSec / 3600)}h ago`;
  if (diffSec < 604800) return `${Math.floor(diffSec / 86400)}d ago`;
  return date.toLocaleDateString("en-PH", {
    timeZone: "Asia/Manila",
    month: "short",
    day: "numeric",
    year: "numeric",
  });
};

export const getManilaDateString = (daysOffset = 0) => {
  const d = new Date(Date.now() + daysOffset * 86400000);
  return new Intl.DateTimeFormat("en-CA", {
    timeZone: "Asia/Manila",
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
  }).format(d);
};

export const getRoleTicketRoot = (roleName) => {
  switch (roleName) {
    case "student":
      return "/student/tickets";
    case "alumni":
      return "/alumni/tickets";
    case "undergrad_requestor":
      return "/undergrad/tickets";
    case "admin":
      return "/staff/tickets";
    case "super_admin":
      return "/super-admin/tickets";
    default:
      return null;
  }
};

/**
 * Standardized parser for Support Ticketing API errors (403, 404, 409, 422, 429).
 * Also handles Blob error payloads (e.g. GET /admin/tickets/export returning 422 JSON inside a Blob).
 */
export const parseTicketApiError = async (err, fallbackMessage = "Something went wrong. Please try again.") => {
  const status = err?.response?.status ?? null;
  let data = err?.response?.data ?? null;

  if (typeof Blob !== "undefined" && data instanceof Blob) {
    try {
      const text = await data.text();
      data = JSON.parse(text);
    } catch {
      data = null;
    }
  }

  const code = data?.code || null;
  const fieldErrors = data?.errors && typeof data.errors === "object" ? data.errors : {};
  const firstFieldError = Object.values(fieldErrors).flat().find(Boolean) || null;
  const retryAfterSeconds = err?.retryAfterSeconds ?? null;

  if (status === 429 || err?.rateLimited) {
    const waitPart = retryAfterSeconds
      ? `Please wait ${retryAfterSeconds} second${retryAfterSeconds === 1 ? "" : "s"} before trying again.`
      : "Please wait a moment before trying again.";
    return {
      status: 429,
      code: code || "rate_limited",
      message: data?.message ? `${data.message} ${waitPart}` : `Too many requests. ${waitPart}`,
      fieldErrors,
      retryAfterSeconds,
      claimedBy: null,
    };
  }

  if (status === 409) {
    const claimedBy = data?.claimed_by || null;
    if (code === "already_claimed") {
      return {
        status,
        code,
        message: claimedBy?.name
          ? `This ticket was already claimed by ${claimedBy.name}.`
          : data?.message || "This ticket has already been claimed by another staff member.",
        fieldErrors,
        retryAfterSeconds: null,
        claimedBy,
      };
    }
    if (code === "not_claimed") {
      return {
        status,
        code,
        message: data?.message || "This ticket is no longer claimed.",
        fieldErrors,
        retryAfterSeconds: null,
        claimedBy: null,
      };
    }
    if (code === "return_limit_reached") {
      const limit = data?.limit;
      return {
        status,
        code,
        message:
          data?.message ||
          `This ticket has reached the maximum number of returns${limit ? ` (${limit})` : ""} and cannot be reopened again.`,
        fieldErrors,
        retryAfterSeconds: null,
        claimedBy: null,
      };
    }
    if (code === "open_tickets_limit") {
      return {
        status,
        code,
        message:
          data?.message ||
          "This email address already has the maximum number of open tickets allowed. Please resolve or follow up on your existing tickets first.",
        fieldErrors,
        retryAfterSeconds: null,
        claimedBy: null,
      };
    }
    if (code === "invalid_transition" || code === "action_not_allowed") {
      return {
        status,
        code,
        message: data?.message || "This action is no longer allowed in the ticket's current status.",
        fieldErrors,
        retryAfterSeconds: null,
        claimedBy: null,
      };
    }
  }

  if (status === 403) {
    return {
      status,
      code: code || "forbidden",
      message: data?.message || "You do not have permission to perform this action on this ticket.",
      fieldErrors,
      retryAfterSeconds: null,
      claimedBy: null,
    };
  }

  if (status === 404) {
    return {
      status,
      code: code || "not_found",
      message:
        code === "invalid_ticket_link"
          ? data?.message || "This ticket tracking link is invalid or has expired."
          : data?.message || "Ticket not found or unavailable.",
      fieldErrors,
      retryAfterSeconds: null,
      claimedBy: null,
    };
  }

  if (status === 422) {
    return {
      status,
      code: code || "validation_error",
      message: firstFieldError || data?.message || "Please check the highlighted fields and try again.",
      fieldErrors,
      retryAfterSeconds: null,
      claimedBy: null,
    };
  }

  return {
    status,
    code,
    message: data?.message || firstFieldError || fallbackMessage,
    fieldErrors,
    retryAfterSeconds: null,
    claimedBy: null,
  };
};

