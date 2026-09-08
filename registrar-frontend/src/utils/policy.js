// -------------------------------------------------------
// Policy / module-access helpers — frontend mirror of the backend's
// Policy::MODULE_KEYS + SystemUser::hasModuleAccess().
//
// IMPORTANT: this is a UX layer only (hide nav items, redirect away
// from a page before the API even gets a chance to reject it). The
// real security boundary is the backend's EnsureModuleAccess
// middleware — this file must never be the only thing standing
// between an admin and a module they aren't supposed to see.
//
// We deliberately do NOT re-implement "no policy -> fall back to the
// default policy" here. That resolution lives in exactly one place —
// SystemUser::effectivePermissions() — and is sent down pre-resolved
// as `user.effective_permissions` (see UserResource). Duplicating the
// fallback logic here would risk it drifting out of sync with the
// backend and silently under- or over-restricting the UI.
// -------------------------------------------------------

export const MODULE_KEYS = {
  DASHBOARD: "dashboard",
  INBOX: "inbox",
  ANALYTICS: "analytics",
  LOGBOOK: "logbook",
  PROFILE: "profile",
  ACCESS_REQUESTS: "access_requests",
  BUSINESS_CALENDAR: "business_calendar",
  CASHIER_OVERRIDES: "cashier_overrides",
  FREE_REQUESTS: "free_requests",
};

export const KEY_TO_LABEL = {
  dashboard: "Dashboard",
  inbox: "Inbox",
  analytics: "Admin Analytics",
  logbook: "Admin Logbook",
  profile: "Admin Profile",
  access_requests: "Access Requests",
  business_calendar: "Business Calendar",
  cashier_overrides: "Cashier OR Overrides",
  free_requests: "Free Requests",
};

export const LABEL_TO_KEY = Object.fromEntries(
  Object.entries(KEY_TO_LABEL).map(([key, label]) => [label, key])
);

/**
 * Granular Per-Action Permissions.
 *
 * Mirrors the backend's App\Models\Policy::MODULE_ACTIONS — the two
 * modules that grant a SUBSET of named actions instead of the default
 * single "Access" token. Keep this in sync with the backend constant;
 * it drives both hasModuleAction() below and PolicyManagement.jsx's
 * per-action checkbox groups.
 */
export const MODULE_ACTIONS = {
  dashboard: ["View", "Process", "Complete"],
  logbook: ["View", "Export"],
  free_requests: ["View", "File", "Verify", "Override"],
};

/**
 * Can this user access the given module at all?
 *
 * - No user yet (still loading / logged out): false.
 * - super_admin: always true.
 * - Any role other than admin (student, alumni): true — the policy
 *   system only ever restricts admin accounts, so nothing here should
 *   ever hide a student/alumni page.
 * - admin: true only if `user.effective_permissions[module]` is a
 *   non-empty array, mirroring the backend's own `!empty(...)` check —
 *   this does NOT check which specific action(s) are granted, only
 *   that at least one is. Use hasModuleAction() below when a specific
 *   action (e.g. "Process") needs to be checked.
 */
export function hasModuleAccess(user, moduleKey) {
  if (!user) return false;
  if (user.role_name === "super_admin") return true;
  if (user.role_name !== "admin") return true;

  const granted = user.effective_permissions?.[moduleKey];
  return Array.isArray(granted) && granted.length > 0;
}

/**
 * Can this user perform a SPECIFIC action on the given module (e.g.
 * hasModuleAction(user, "dashboard", "Process"))?
 *
 * This is a UX layer only, same caveat as hasModuleAccess() above —
 * the real security boundary is the backend's fine-grained check in
 * DocumentRequestService::updateRequest() / RequestItemStatusService
 * (and the coarse EnsureModuleAccess + role:3,4 route middleware ahead
 * of it). This function exists so the UI can hide a button the backend
 * would reject anyway, never as the only thing standing between a user
 * and an action they aren't supposed to take.
 *
 * IMPORTANT — this deliberately does NOT mirror hasModuleAccess()'s
 * "any non-admin role is never gated" shortcut. hasModuleAccess() is
 * about whole-page visibility (a student's own dashboard genuinely has
 * no policy restrictions). hasModuleAction() is about specific staff
 * capabilities — every current MODULE_ACTIONS entry (dashboard
 * Process/Complete, logbook Export) is a staff-only action, and some of
 * the components that call this (e.g. RequestDetailModal.jsx) are
 * shared with student/alumni views. Bypassing the check for student/
 * alumni here would surface staff-only controls (that the backend would
 * then correctly 403) inside a student's own request-detail modal —
 * which is exactly the bug this function used to have.
 *
 * - No user yet: false.
 * - super_admin: always true.
 * - admin: true only if `action` is present in
 *   `user.effective_permissions[module]`.
 * - Any other role (student, alumni, ...): false — these actions don't
 *   apply to non-staff accounts at all.
 */
export function hasModuleAction(user, moduleKey, action) {
  if (!user) return false;
  if (user.role_name === "super_admin") return true;
  if (user.role_name !== "admin") return false;

  const granted = user.effective_permissions?.[moduleKey];
  return Array.isArray(granted) && granted.includes(action);
}