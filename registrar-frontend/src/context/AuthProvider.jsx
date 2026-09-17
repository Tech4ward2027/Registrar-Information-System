import React, { createContext, useContext, useState, useEffect, useCallback } from "react";
import { useNavigate } from "react-router-dom";
import api from "../services/api";
import {
  fetchCurrentUser,
  logoutRequest,
  ssoCallbackRequest,
  localLoginRequest,
  switchRoleRequest,
  fetchMyRoleAssignments,
} from "../services/authService";
import { resetEcho } from "../services/echo";
import ErrorToast from "../components/ErrorToast";

const AuthContext = createContext();

// -------------------------------------------------------
// Role name constants — mirrors backend UserResource.
// -------------------------------------------------------
// eslint-disable-next-line react-refresh/only-export-components
export const ROLES = {
  STUDENT:     "student",
  ALUMNI:      "alumni",
  ADMIN:       "admin",
  SUPER_ADMIN: "super_admin",
  UNDERGRAD_REQUESTOR: "undergrad_requestor",
};

// eslint-disable-next-line react-refresh/only-export-components
export const ROLE_HOME = {
  [ROLES.STUDENT]:     "/student",
  [ROLES.ALUMNI]:      "/alumni",
  [ROLES.ADMIN]:       "/staff",
  [ROLES.SUPER_ADMIN]: "/super-admin",
  [ROLES.UNDERGRAD_REQUESTOR]: "/student",
};

// Mirrors SystemUser::ROLE_STUDENT / ROLE_ALUMNI / ROLE_ADMIN /
// ROLE_SUPER_ADMIN — role_assignments rows carry role_id (an int), not
// the role_name string UserResource returns, so the switcher needs both
// directions of this mapping.
// eslint-disable-next-line react-refresh/only-export-components
export const ROLE_ID = {
  STUDENT:     1,
  ALUMNI:      2,
  ADMIN:       3,
  SUPER_ADMIN: 4,
  UNDERGRAD_REQUESTOR: 5,
};

const ROLE_ID_TO_NAME = {
  [ROLE_ID.STUDENT]:     ROLES.STUDENT,
  [ROLE_ID.ALUMNI]:      ROLES.ALUMNI,
  [ROLE_ID.ADMIN]:       ROLES.ADMIN,
  [ROLE_ID.SUPER_ADMIN]: ROLES.SUPER_ADMIN,
  [ROLE_ID.UNDERGRAD_REQUESTOR]: ROLES.UNDERGRAD_REQUESTOR,

};

export const AuthProvider = ({ children }) => {
  const navigate = useNavigate();
  const [user, setUser] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [isLoggingOut, setIsLoggingOut] = useState(false);
  // idpOffline: true when the last login used the local fallback.
  // Post-login pages read this to show a non-blocking advisory banner.
  const [idpOffline, setIdpOffline] = useState(false);
  const [hasAgreed, setHasAgreed] = useState(
    () => localStorage.getItem("hasAgreed") === "true"
  );

  // The roles this account currently holds an Active (not
  // expired/revoked) role_assignments grant for — e.g. a student-staff
  // account has two entries here: Student and a policy-restricted
  // Admin. Drives the Navigation.jsx switcher modal. Only meaningful
  // once `user` is loaded; empty for a signed-out session.
  const [roleAssignments, setRoleAssignments] = useState([]);
  const [roleAssignmentsLoading, setRoleAssignmentsLoading] = useState(false);

  const refreshRoleAssignments = useCallback(async () => {
    setRoleAssignmentsLoading(true);
    try {
      const res = await fetchMyRoleAssignments();
      const assignments = res.data?.data ?? [];
      setRoleAssignments(assignments);
      // Returned (not just set into state) so callers that need the
      // freshly-fetched list *synchronously after the await* — namely
      // routeAfterAuth() below — don't have to read back a state value
      // that may not have re-rendered yet.
      return assignments;
    } catch {
      // Non-fatal — the switcher just won't show extra roles this time.
      setRoleAssignments([]);
      return [];
    } finally {
      setRoleAssignmentsLoading(false);
    }
  }, []);

  const agreeToTerms = () => {
    localStorage.setItem("hasAgreed", "true");
    setHasAgreed(true);
  };

  // -------------------------------------------------------
  // On app load — restore session from the HttpOnly cookie.
  // -------------------------------------------------------
  useEffect(() => {
    const initializeAuth = async () => {
      if (window.location.pathname === "/auth/callback") {
        setLoading(false);
        return;
      }
      try {
        const res      = await fetchCurrentUser();
        const userData = res.data.data;
        setUser(userData);
        refreshRoleAssignments();
      } catch {
        setUser(null);
      } finally {
        setLoading(false);
      }
    };
    initializeAuth();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  // -------------------------------------------------------
  // Shared post-login routing: anyone holding more than one Active
  // role_assignments row goes to /access-control to pick a role context
  // first; everyone else goes straight to their role's home.
  //
  // This used to key off `policy?.name === 'Student Staff'` — a
  // hardcoded check against a pre-existing system *policy* (seeded in
  // 2026_07_11_000001_create_policies_table.php, for restricted-permission
  // single-role Admins) that predates role_assignments and has nothing to
  // do with holding two roles. Navigation.jsx's switcher already migrated
  // off that same heuristic to `roleAssignments.length > 1` — see the
  // comment there ("any account granted a second role gets the switcher
  // automatically, with no extra flag to maintain"). This brings
  // routeAfterAuth() in line with it, so a genuine multi-role account
  // (granted via the new flow, under any policy name) actually sees the
  // picker instead of landing straight on one role's dashboard.
  //
  // Takes `assignments` explicitly rather than reading the roleAssignments
  // state value, since callers await refreshRoleAssignments() and call
  // this immediately after — state set inside that call may not have
  // committed to a re-render yet, but the returned array is always current.
  // -------------------------------------------------------
  const routeAfterAuth = (userData, assignments) => {
    if (Array.isArray(assignments) && assignments.length > 1) {
      navigate("/access-control", { replace: true });
      return;
    }
    const destination = ROLE_HOME[userData.role_name] ?? "/";
    navigate(destination, { replace: true });
  };

  // -------------------------------------------------------
  // login() — IDP-first with automatic local fallback.
  //
  // The backend POST /api/login tries the IDP first. If the IDP is
  // unreachable it falls back to local bcrypt and sets idp_offline: true
  // in the response body. We surface that flag here so post-login pages
  // can show a non-blocking advisory banner via the idpOffline context value.
  // -------------------------------------------------------
  const login = async (email, password) => {
    const { data } = await api.post("/login", { email, password });
    const userData = data.data ?? data.user;

    setUser(userData);
    setIdpOffline(!!data.idp_offline);
    const assignments = await refreshRoleAssignments();
    routeAfterAuth(userData, assignments);
  };

  // -------------------------------------------------------
  // localLogin() — always uses the local hash, bypasses IDP entirely.
  // Shown on the LandingPage when the user explicitly chooses it.
  // -------------------------------------------------------
  const localLogin = async (email, password) => {
    const { data } = await localLoginRequest(email, password);
    const userData = data.data ?? data.user;

    setUser(userData);
    setIdpOffline(true); // they explicitly chose local login
    const assignments = await refreshRoleAssignments();
    routeAfterAuth(userData, assignments);
  };

  // -------------------------------------------------------
  // Logout
  // -------------------------------------------------------
  const logout = async () => {
    setIsLoggingOut(true);
    setHasAgreed(false);
    setIdpOffline(false);
    localStorage.removeItem("hasAgreed");
    resetEcho();
    setUser(null);
    setRoleAssignments([]);
    try {
      // logoutRequest() handles the redirect internally:
      //   · IDP session  → window.location.href = idp_logout_url
      //   · Local session → window.location.href = "/"
      // We still catch errors so the user is never stuck on a broken state.
      await logoutRequest();
    } catch (err) {
      console.error("Logout request failed:", err);
      // Fallback: navigate home via React Router so at least the UI resets.
      navigate("/", { replace: true });
    }
  };

  // -------------------------------------------------------
  // SSO callback — called by SsoCallbackPage after IdP redirect.
  //
  // Undergrad Requestor Registration — Phase 3 wiring. The backend's
  // SsoCallbackController now returns THREE distinct shapes on a 403,
  // not one, and this function must tell them apart rather than
  // collapsing all of them into the old flat "unregistered" case:
  //
  //   - Unregistered / Deactivated / Expired invite:
  //       403 { message, logout_url }                    — has logout_url
  //   - Rejected Undergrad Requestor (D8):
  //       403 { message, logout_url, rejected: true }     — has logout_url
  //   - Still-Pending Undergrad Requestor (calm, expected state):
  //       403 { message, pending_review: true }           — NO logout_url
  //
  // Before this fix, only `status === 403 && logoutUrl` was checked, so
  // a still-Pending submission (which the backend deliberately sends
  // with NO logout_url and an 'info', not 'warning', log level — see
  // the backend controller's docblock) fell through to the generic
  // `throw err` branch below and surfaced as a scary, auto-redirecting
  // "Login failed" screen instead of the calm "you're still under
  // review" message the backend was designed to support. `rejected`
  // was also being silently dropped, so a rejected account saw the same
  // "please register" copy as someone who never registered at all.
  //
  // SsoCallbackPage reads `rejection.pendingReview` / `rejection.rejected`
  // / `rejection.logoutUrl` / `rejection.message` (all optional) off the
  // thrown error to decide which screen to render.
  // -------------------------------------------------------
  const ssoCallback = async (code) => {
    try {
      const { data } = await ssoCallbackRequest(code);
      const userData = data.data ?? data.user;

      setUser(userData);
      setIdpOffline(false);
      const assignments = await refreshRoleAssignments();
      routeAfterAuth(userData, assignments);
    } catch (err) {
      const status        = err.response?.status;
      const body          = err.response?.data ?? {};
      const logoutUrl     = body.logout_url;
      const isRejected    = status === 403 && body.rejected === true;
      const isPending     = status === 403 && body.pending_review === true;
      const isUnregistered = status === 403 && !!logoutUrl && !isRejected;

      setUser(null);

      if (isPending || isRejected || isUnregistered) {
        const rejection        = new Error(isPending ? "pending_review" : isRejected ? "rejected" : "unregistered");
        rejection.message      = body.message || rejection.message;
        rejection.logoutUrl    = logoutUrl ?? null;
        rejection.rejected     = isRejected;
        rejection.pendingReview = isPending;
        throw rejection;
      }
      throw err;
    }
  };

  // -------------------------------------------------------
  // switchRole() — Step 3/4 of Multi-Role Assignments.
  //
  // Server-enforced: POST /auth/switch-role validates the caller holds
  // an Active role_assignments row for roleId and reissues the session's
  // token stamped with it (see RoleAssignmentService::switchTo()). This
  // replaces the old client-only `activeRoleOverride` localStorage hack
  // — a session can no longer "switch" to a role it doesn't actually
  // hold, and the backend's own gates (RoleMiddleware, EnsureModuleAccess)
  // now honor the switch too, not just the UI.
  //
  // Accepts a numeric role_id (matching role_assignments.role_id /
  // SystemUser::ROLE_* — see ROLE_ID above), since that's what the
  // /role-assignments/mine list and the API both key off.
  // -------------------------------------------------------
  const switchRole = async (roleId) => {
    setLoading(true);
    try {
      const { data } = await switchRoleRequest(roleId);
      const userData = data.data ?? data.user;

      setUser(userData);
      // The set of roles held doesn't change when switching (only which
      // one is currently assumed does) — no need to refetch, but doing so
      // keeps this resilient if a grant/revoke happened concurrently.
      await refreshRoleAssignments();

      const destination = ROLE_HOME[userData.role_name] ?? "/";
      navigate(destination, { replace: true });
      
      // Defer disabling the loading state to ensure React Router mounts 
      // the new route before we check route permissions again.
      setTimeout(() => {
        setLoading(false);
      }, 100);
    } catch (err) {
      console.error('Role switch failed:', err);
      setLoading(false);
      throw err;
    }
  };

  // -------------------------------------------------------
  // Role helpers
  // -------------------------------------------------------
  const hasRole = (roleName) => user?.role_name === roleName;
  const isStaff = () => hasRole(ROLES.ADMIN) || hasRole(ROLES.SUPER_ADMIN);

  return (
    <AuthContext.Provider
      value={{
        user,
        loading,
        error,
        login,
        localLogin,
        logout,
        ssoCallback,
        hasRole,
        isStaff,
        isLoggingOut,
        idpOffline,
        hasAgreed,
        setHasAgreed,
        agreeToTerms,
        // Multi-role switching (Step 3/4)
        roleAssignments,
        roleAssignmentsLoading,
        refreshRoleAssignments,
        switchRole,
        ROLE_ID_TO_NAME,
      }}
    >
      <ErrorToast message={error} onClose={() => setError(null)} />
      {children}
    </AuthContext.Provider>
  );
};

export const useAuth = () => useContext(AuthContext);