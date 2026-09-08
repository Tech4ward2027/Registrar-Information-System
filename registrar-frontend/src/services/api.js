import axios from "axios";

// -------------------------------------------------------
// Single axios instance for the entire app.
// Token injection and 401 handling are done here once —
// every component imports named functions from this file,
// never creates its own axios instance or calls axios directly.
// -------------------------------------------------------
const api = axios.create({
  baseURL: import.meta.env.VITE_API_URL ?? "http://localhost:8000/api",
  // withCredentials: true sends the HttpOnly 'token' cookie on every request.
  // The backend reads it via Sanctum's cookie guard — no manual header needed.
  withCredentials: true,
  headers: {
    Accept: "application/json",
    "Content-Type": "application/json",
  },
});

// No manual token header needed — withCredentials: true above causes the
// browser to send the HttpOnly 'token' cookie automatically.
// Sanctum reads and validates it on the server side.

// On 401 — clear non-sensitive local state so stale UI doesn't persist.
// The HttpOnly cookie is cleared by the server on logout; we just clear
// the local user cache here.  Navigation to "/" is handled by AuthProvider.
api.interceptors.response.use(
  (res) => res,
  (err) => {
    if (err.response?.status === 401) {
      localStorage.removeItem("user");
    }
    return Promise.reject(err);
  }
);

// -------------------------------------------------------
// SYSTEM USERS (Super Admin only)
// -------------------------------------------------------
export const getSystemUsers  = ()         => api.get("/system-users");
export const getSystemUser   = (id)       => api.get(`/system-users/${id}`);
export const createSystemUser = (data)    => api.post("/system-users", data);
export const updateSystemUser = (id, data)=> api.put(`/system-users/${id}`, data);
export const deleteSystemUser = (id)      => api.delete(`/system-users/${id}`);

// -------------------------------------------------------
// LOCAL (BREAK-GLASS) AUTH (Super Admin only)
// -------------------------------------------------------
// Enables/updates local bcrypt fallback login for a user so they can
// still sign in if the IdP is down. Backend additionally rejects any
// target whose role isn't Super Admin — see SetLocalPasswordRequest.
export const setLocalPassword = (userId, password, passwordConfirmation) =>
  api.post("/auth/local-password", {
    user_id: userId,
    password,
    password_confirmation: passwordConfirmation,
  });

// -------------------------------------------------------
// POLICIES (Super Admin only)
// -------------------------------------------------------
export const getPolicies   = ()          => api.get("/policies");
export const createPolicy  = (data)      => api.post("/policies", data);
export const updatePolicy  = (id, data)  => api.put(`/policies/${id}`, data);
export const deletePolicy  = (id)        => api.delete(`/policies/${id}`);

// -------------------------------------------------------
// ACCESS REQUESTS — self-service intake, centralized approval.
// store() only requires the 'access_requests' module (any admin);
// index/approve/reject are Super Admin only — enforced server-side by
// AccessRequestPolicy regardless of what the UI shows. getMyAccessRequests
// is available to any admin/super-admin and is hard-scoped server-side to
// the caller's own submissions (AccessRequestPolicy::viewOwn).
// -------------------------------------------------------
export const getAccessRequests    = (params = {}) => api.get("/access-requests", { params });
export const getMyAccessRequests  = (params = {}) => api.get("/access-requests/mine", { params });
export const submitAccessRequest  = (data)         => api.post("/access-requests", data);
export const approveAccessRequest = (id)           => api.post(`/access-requests/${id}/approve`);
export const rejectAccessRequest  = (id, reason)   => api.post(`/access-requests/${id}/reject`, { reason });

// -------------------------------------------------------
// ROLE ASSIGNMENTS (Super Admin only) — Multi-Role Assignments.
// Onboards/offboards a *secondary*, concurrent role onto an existing
// account (e.g. granting a restricted Admin role to someone who already
// holds Student — the "student staff" case). Powers UserManagement.jsx's
// "Roles" action/modal. `getMyRoleAssignments` in authService.js is the
// separate, any-authenticated-user version of this used by the role
// switcher — this one is the full, Super-Admin-only history/management
// view (RoleAssignmentController::index/store/revoke).
// -------------------------------------------------------
export const getRoleAssignments   = (params = {}) => api.get("/role-assignments", { params });
export const grantRoleAssignment  = (data)         => api.post("/role-assignments", data);
export const revokeRoleAssignment = (id, reason)   => api.post(`/role-assignments/${id}/revoke`, { reason });

// PATCH /role-assignments/{id}/policy — Work Item #2 — Admin Management
// Consolidation. Edits the policy on an already-Active Admin
// role_assignment in place, without a revoke/regrant cycle. This is the
// ONLY remaining way to change an admin's policy from the UI — it
// replaces the retired PATCH /system-users/{id}/policy ("Manage
// Access") endpoint entirely. Sending `null` detaches the policy (see
// EditRoleAssignmentPolicyRequest — nullable is intentional, fail-closed
// server-side).
export const editRoleAssignmentPolicy = (id, policyId) =>
  api.patch(`/role-assignments/${id}/policy`, { policy_id: policyId });

// GET /role-assignments/search-users?q= — typeahead lookup across ALL
// roles (student/alumni/admin/super admin), used by GrantRoleUserPicker
// to find a target account. Deliberately separate from getSystemUsers(),
// which only ever returns admin/super-admin accounts.
export const searchGrantableUsers = (q) => api.get("/role-assignments/search-users", { params: { q } });

// -------------------------------------------------------
// ACADEMIC RECORDS
// -------------------------------------------------------
export const getAcademicRecords = ()         => api.get("/academic-records");
export const getAcademicRecord  = (id)       => api.get(`/academic-records/${id}`);

// -------------------------------------------------------
// REQUEST STATUSES
// -------------------------------------------------------
export const getRequestStatuses = () => api.get("/request-statuses");
export const getRequestStatus   = (id) => api.get(`/request-statuses/${id}`);

// -------------------------------------------------------
// REQUEST PURPOSES
// Previously missing — RequestForm.jsx was calling axios.get directly.
// -------------------------------------------------------
export const getRequestPurposes = () => api.get("/request-purposes");

// -------------------------------------------------------
// PROGRAMS (read-only — populated automatically on student login)
// -------------------------------------------------------
export const getPrograms = () => api.get("/programs");

// -------------------------------------------------------
// DOCUMENT TYPES (read: all | write: Admin+)
// -------------------------------------------------------
export const getDocumentTypes  = (includeArchived = false) =>
  api.get("/document-types", { params: includeArchived ? { include_archived: 1 } : {} });
export const getDocumentType   = (id)        => api.get(`/document-types/${id}`);
export const createDocumentType = (data)     => api.post("/document-types", data);
export const updateDocumentType = (id, data) => api.put(`/document-types/${id}`, data);
export const deleteDocumentType = (id)       => api.delete(`/document-types/${id}`);
export const archiveDocumentType = (id, reason) => api.patch(`/document-types/${id}/archive`, { reason });
export const restoreDocumentType = (id)      => api.patch(`/document-types/${id}/restore`);

// -------------------------------------------------------
// LOGBOOK CATEGORIES (read: all | write: Admin+)
// -------------------------------------------------------
export const getLogbookCategories  = ()          => api.get("/logbook-categories");
export const getLogbookCategory    = (id)        => api.get(`/logbook-categories/${id}`);
export const createLogbookCategory = (data)      => api.post("/logbook-categories", data);
export const updateLogbookCategory = (id, data)  => api.put(`/logbook-categories/${id}`, data);
export const deleteLogbookCategory = (id)        => api.delete(`/logbook-categories/${id}`);

// Fulfillment tracks (Phase 3 claim grouping) — same shape as Logbook
// Category above. See FulfillmentTrackController: assigning a non-null
// fulfillment_track_id to a document_type/certificate_type is what
// actually makes RequestReleaseGroupService::assignReleaseGroups() split
// a request into more than one claim ticket. Before this was wired into
// DocumentManagement.jsx, no type in the system ever had a track
// assigned — the whole grouping feature existed in code but was
// unreachable in practice.
export const getFulfillmentTracks  = ()          => api.get("/fulfillment-tracks");
export const getFulfillmentTrack   = (id)        => api.get(`/fulfillment-tracks/${id}`);
export const createFulfillmentTrack = (data)     => api.post("/fulfillment-tracks", data);
export const updateFulfillmentTrack = (id, data) => api.put(`/fulfillment-tracks/${id}`, data);
export const deleteFulfillmentTrack = (id)       => api.delete(`/fulfillment-tracks/${id}`);

// -------------------------------------------------------
// CERTIFICATIONS (read: all | write: Admin+)
// -------------------------------------------------------
export const getCertifications         = (includeArchived = false) =>
  api.get("/certifications", { params: includeArchived ? { include_archived: 1 } : {} });
export const getCertification          = (id)        => api.get(`/certifications/${id}`);
export const createCertification       = (data)      => api.post("/certifications", data);
export const updateCertification       = (id, data)  => api.put(`/certifications/${id}`, data);
export const deleteCertification       = (id)        => api.delete(`/certifications/${id}`);
export const archiveCertification      = (id, reason)        => api.patch(`/certifications/${id}/archive`, { reason });
export const restoreCertification      = (id)        => api.patch(`/certifications/${id}/restore`);
export const getCertificationLayouts   = ()          => api.get("/certifications/layouts");
export const updateCertificationLayout = (id, data)  => api.put(`/certifications/${id}/layout`, data);
export const uploadCertificationLayoutLogo = (id, formData) =>
  api.post(`/certifications/${id}/layout/logo`, formData, {
    headers: { "Content-Type": "multipart/form-data" },
  });

// -------------------------------------------------------
// UNMATCHED CASHIER ITEMS (Admin+) — receipt labels
// CashierDocumentSuggester couldn't match to any document/certificate
// type's cashier_document_patterns. Backs the admin review screen that
// closes the naming-drift loop: attach a label to the right type once
// and every future receipt using it auto-matches, no deploy needed.
// -------------------------------------------------------
export const getUnmatchedCashierItems = (params = {}) =>
  api.get("/unmatched-cashier-items", { params });
export const resolveUnmatchedCashierItem = (id, data) =>
  api.post(`/unmatched-cashier-items/${id}/resolve`, data);
export const dismissUnmatchedCashierItem = (id) =>
  api.post(`/unmatched-cashier-items/${id}/dismiss`);

// -------------------------------------------------------
// CASHIER OR OVERRIDES (Admin with the "cashier_overrides" module, or
// Super Admin) — the scoped, audited bypass for one (or_number,
// student) pair when a real receipt is wrongly rejected by the
// Cashier API. See CashierOrOverrideController on the backend for the
// full design rationale.
// -------------------------------------------------------
export const getCashierOverrides = (params = {}) =>
  api.get("/cashier-overrides", { params });
export const createCashierOverride = (data) =>
  api.post("/cashier-overrides", data);
export const revokeCashierOverride = (id) =>
  api.post(`/cashier-overrides/${id}/revoke`);
export const searchCashierOverrideUsers = (q) =>
  api.get("/cashier-overrides/search-users", { params: { q } });

// -------------------------------------------------------
// FREE DOCUMENT/CERTIFICATE REQUESTS (FESPEC-0008) — Admin with the
// "free_requests" module, or Super Admin. Staff on-behalf-of filing for
// the Free Documents/Certificates Request Policy and the First Copy
// Free Issuance for Graduates Policy. Same on-behalf-of shape as
// CASHIER OR OVERRIDES above (search an account, then act on it), but
// with a read-only eligibility pre-check step in between — see
// FreeRequestController on the backend for the full design rationale.
//
// 'View' (search-accounts, eligibility) vs 'File' (store) are two
// separate module actions server-side — searchFreeRequestAccounts()
// and checkFreeRequestEligibility() only need 'View'; fileFreeRequest()
// needs 'File'. A user with only 'View' can staff the lookup/eligibility
// screen but will get a 403 attempting to actually file — surface that
// the same way other module-gated 403s are already handled in this app,
// rather than trying to pre-guess it client-side.
// -------------------------------------------------------

// GET /free-requests/search-accounts?q=...&student_number=...&program=...
// — typeahead lookup for the student/alumni account staff are filing on
// behalf of. Distinct from searchGrantableUsers()/searchCashierOverrideUsers()
// above: this one returns FreeRequestAccountResource, which additionally
// carries student_number / program / year_of_graduation — the exact fields
// the First Copy policy's in-person records-check step (§3.4) is verified
// against, not just a name/email picker.
//
// Free Documents/Certificates Request Policy §3.3: "Full Name (required),
// Student Number (optional), Program (optional)". `q` is the required name
// search; `studentNumber`/`program` are optional disambiguation filters for
// when a name search returns more than one plausible match (e.g. two
// students sharing a name) — both default to undefined so this call stays
// backward-compatible with existing single-argument callers, and axios
// omits a param entirely from the querystring when its value is undefined.
export const searchFreeRequestAccounts = (q, studentNumber = undefined, program = undefined) =>
  api.get("/free-requests/search-accounts", {
    params: { q, student_number: studentNumber, program },
  });

// POST /free-requests/eligibility — read-only pre-check. Shows staff the
// eligibility indicator for every item under consideration BEFORE they
// commit to filing. Never writes anything server-side; the backend
// re-runs this same check again, under a row lock, at actual filing
// time, so treat this response as advisory only — it can go stale
// between here and the fileFreeRequest() call (e.g. another admin files
// the graduate's one-time COG in the meantime).
//
// data: { target_user_id, documents?: [{ document_type_id }],
//          certificates?: [{ certificate_type_id }] }
//
// Response shape (res.data):
//   { target_user_id, results: [{
//       eligible, kind: "document"|"certificate", type_id, type_label,
//       reason_code, reason, requires_graduate_verification,
//       free_issuance_limit, remaining
//   }] }
// reason_code is a stable machine-readable slug (e.g. "not_graduate",
// "limit_reached", "not_free_eligible") — switch on that for UI
// state, not the human-readable `reason` sentence.
export const checkFreeRequestEligibility = (data) =>
  api.post("/free-requests/eligibility", data);

// POST /free-requests — files the free request on behalf of
// target_user_id. Mirrors createDocumentRequest()'s documents/
// certificates array shape, plus three fields specific to this
// admin-filed flow:
//   - override / override_reason: staff have independently determined
//     an ineligible item should be filed anyway. override_reason is
//     required (min 10 chars) when override is true.
//   - verification: { credentials_verified, records_checked } — the
//     in-person attestation checkboxes. Only actually enforced by the
//     backend when the filing includes a COG/TOR item; omit entirely
//     for an LOA-only filing.
//
// On success (201): { document_request, was_overridden,
//   graduate_verification_performed } — document_request comes fully
// loaded (status, requestPurpose, documents.documentType,
// certificates.certificationType, graduateVerification), so no
// follow-up getDocumentRequest() call is needed to render a
// confirmation screen.
//
// On ineligible-without-override (422): { message, errors: [...] } —
// `errors` is the same per-item shape as the eligibility endpoint above,
// reflecting the AT-FILING-TIME check (which can differ from whatever
// checkFreeRequestEligibility() showed earlier) — re-render the
// eligibility indicator from this array rather than the stale one.
export const fileFreeRequest = (data) => api.post("/free-requests", data);

// GET /free-requests/reports/monthly-volume?year=2026 — Phase 8
// observability. Free-issuance COUNT actually claimed, grouped by
// calendar month and document/certificate type — a graduate's one-time
// COG/TOR only shows up here the month they scan/type their claim code,
// not the month staff filed it, so this can lag a filing by however
// long the item took to process. Gated by the same 'free_requests'
// module 'View' action as searchFreeRequestAccounts()/
// checkFreeRequestEligibility() above, not a separate reports
// permission — this codebase doesn't have one yet.
//
// year is optional (server defaults to the current year in the
// registrar's display timezone, Asia/Manila) — omit it to show the
// current year on first load.
//
// Response shape (res.data): { year, data: [{ month: "2026-04",
//   type_label: "Transcript of Records", count: 3 }, ...] } — flat/tidy
// rows, one per (month, type) pair, already sorted month-then-type-label
// ascending. This is the same long-format shape
// getAuditLogs()/getSecurityEvents() already hand ReportManagement.jsx
// for its table+CSV-export pattern (see auditLogSheet.js) — feed it
// into that same table pattern, or pivot it client-side into a
// month-by-type grid/chart if a visual breakdown is wanted; the backend
// intentionally doesn't pre-shape it either way.
export const getFreeRequestMonthlyVolumeReport = (year) =>
  api.get("/free-requests/reports/monthly-volume", { params: year ? { year } : {} });

// -------------------------------------------------------
// SIGNATORIES (certificate signees) — read/write: Admin only
// (unlike document-types/certifications, GET is admin-only here too —
// see routes/api.php)
// -------------------------------------------------------
export const getSignatories    = ()          => api.get("/signatories");
export const createSignatory   = (data)      => api.post("/signatories", data);
export const updateSignatory   = (id, data)  => api.put(`/signatories/${id}`, data);
export const deleteSignatory   = (id)        => api.delete(`/signatories/${id}`);

// -------------------------------------------------------
// BUSINESS CALENDAR — one-off dated closures + recurring overrides
// (Admin with the "business_calendar" module, or Super Admin)
// -------------------------------------------------------
export const getCalendarExceptions   = (params = {}) => api.get("/calendar-exceptions", { params });
export const createCalendarException = (data)        => api.post("/calendar-exceptions", data);
export const updateCalendarException = (id, data)    => api.put(`/calendar-exceptions/${id}`, data);
export const deleteCalendarException = (id)          => api.delete(`/calendar-exceptions/${id}`);

export const getCalendarOverrides    = (params = {}) => api.get("/calendar-overrides", { params });
export const createCalendarOverride  = (data)        => api.post("/calendar-overrides", data);
export const updateCalendarOverride  = (id, data)    => api.put(`/calendar-overrides/${id}`, data);
export const deleteCalendarOverride  = (id)          => api.delete(`/calendar-overrides/${id}`);

// -------------------------------------------------------
// DOCUMENT REQUESTS (read: all | write: Student/Alumni | manage: Admin+)
// Response shape from index: { current_page, data, last_page, per_page, total }
// Read records from response.data.data, not response.data.
// -------------------------------------------------------
export const getDocumentRequests  = (params = {}) => api.get("/document-requests", { params });
export const getLogbookData       = (params = {}) => api.get("/document-requests/logbook", { params });

// BE-2 backend fix paginated /document-requests/logbook (previously an
// unbounded ->get() of every completed request ever). Logbook.jsx and
// analyticsMonthlyExport.js both filter/group across the FULL completed-
// request history client-side (by doc type, certification, date range),
// so they can't just take page 1 — they need every row.
//
// This pages through the endpoint at 100 rows/request (capped server-side
// at 100 too) and concatenates the results, so:
//   - the backend never answers one unbounded query
//   - existing consumers keep working against the full dataset unchanged
//
// This is a stopgap, not the end state — if the completed-request table
// grows large enough that paging through everything on every load becomes
// slow, the real fix is moving the doc-type/certification/date filters
// server-side (they're already partially there — see `from`/`to`/`doc_type`
// on this endpoint) and having the DOCX export hit a dedicated endpoint
// that streams matching rows instead of relying on the client having
// already loaded them all.
export const getAllLogbookData = async (params = {}) => {
  const perPage = 100;
  const MAX_PAGES = 500; // safety cap — avoids an infinite loop if last_page ever misbehaves
  let page = 1;
  let all = [];

  while (page <= MAX_PAGES) {
    const res = await getLogbookData({ ...params, page, per_page: perPage });
    const body = res.data;

    // Back-compat: if an older/unpaginated backend build is deployed,
    // the endpoint just returns a plain array — take it and stop.
    if (Array.isArray(body)) {
      all = body;
      break;
    }

    const rows = Array.isArray(body?.data) ? body.data : [];
    all = all.concat(rows);

    const lastPage = body?.last_page ?? body?.meta?.last_page ?? page;
    if (rows.length === 0 || page >= lastPage) break;
    page += 1;
  }

  return all;
};

export const getDocumentRequest   = (id)          => api.get(`/document-requests/${id}`);
export const createDocumentRequest = (data)       => api.post("/document-requests", data);

// Verifies an OR Number + Date of Payment against the cashier system and
// returns document/certificate suggestions derived from the receipt —
// does NOT create a DocumentRequest. Used by RequestForm's OR-first wizard
// step to pre-populate the Documents step before the student picks
// anything. createDocumentRequest above still re-verifies and re-matches
// strictly at final submit; this call is advisory only.
export const verifyOfficialReceipt = (data)       => api.post("/document-requests/verify-or", data);

// Public — no auth required. Used by RequestForm's confirmation screen to
// tell requesters whether the Registrar is open right now, and when
// processing will begin if not.
export const getBusinessHoursStatus = () => api.get("/business-hours/status");
export const updateDocumentRequest = (id, data)   => api.put(`/document-requests/${id}`, data);
export const deleteDocumentRequest = (id)         => api.delete(`/document-requests/${id}`);

// Archive / restore (Admin+) — reversible, does not change status_id.
export const archiveDocumentRequest  = (id)  => api.patch(`/document-requests/${id}/archive`);
export const restoreDocumentRequest  = (id)  => api.patch(`/document-requests/${id}/restore`);
export const archiveDocumentRequests = (ids) => api.post(`/document-requests/archive-bulk`, { request_ids: ids });
export const restoreDocumentRequests = (ids) => api.post(`/document-requests/restore-bulk`, { request_ids: ids });

// -------------------------------------------------------
// WITHDRAWN STATUS (Deficiency Notice & Withdrawn Status — Phase 1/2) —
// staff/admin only (same role:3 + module:dashboard,Process gate as the
// other admin status actions above). Terminal — a withdrawn request can
// never transition again. Does NOT touch or_number/receipt_date; the
// paid OR stays permanently attached for finance reconciliation.
//
// data shape:
//   {
//     withdrawal_reason: "wrong_item_paid" | "duplicate_submission"
//                       | "student_no_longer_needs" | "other",
//     withdrawal_detail?: string,   // REQUIRED when withdrawal_reason === "other",
//                                   // max 2000 chars — this is the staff-typed
//                                   // free text, e.g. from a required textarea
//                                   // that only appears when "Other" is picked
//     superseded_by_request_id?: number, // optional — id of the corrected/
//                                         // resubmitted request, if any
//   }
//
// On success returns the updated DocumentRequest (with its withdrawal_reason,
// withdrawal_detail, and superseded_by_request_id fields set) — re-render
// from the response rather than re-fetching. On failure the backend already
// distinguishes an invalid-transition 422 (e.g. trying to withdraw a
// ReadyToClaim or already-Completed/Withdrawn request) from a 422 validation
// error (missing/invalid reason, missing detail when reason is "other") —
// surface err.response.data.message / err.response.data.errors as-is.
//
// NOTE: if this request currently has an open Deficiency Notice, the
// backend auto-voids it as part of this same call (cascading this
// withdrawal_reason/detail into the notice's void_reason) — no separate
// call needed, and no separate notification is sent for that voiding.
// See DocumentRequestService::withdraw()'s docblock.
// -------------------------------------------------------
export const withdrawDocumentRequest = (id, data) =>
  api.post(`/document-requests/${id}/withdraw`, data);

// -------------------------------------------------------
// DEFICIENCY NOTICE (Deficiency Notice & Withdrawn Status — Phase 3/4) —
// staff/admin only, same gate as WITHDRAWN STATUS above. A notice is a
// named, cleared/voidable HOLD on a request — issuing, clearing, or
// voiding one never changes document_request.status_id. At most one
// OPEN notice can exist per request at a time (issue() 422s otherwise).
//
// getDocumentRequest(id) already eager-loads the request's currently-open
// notice (if any) under the request payload — check that first before
// assuming issueDeficiencyNotice() is available; the UI should show the
// "on hold" banner + Clear/Void actions instead of an Issue button
// whenever an open notice is already present.
//
// NOTE — the notice object also now carries two independent time-based
// signals, not to be conflated:
//   - is_stale (boolean): Phase 4's original 14-day cosmetic badge,
//     purely computed — true once issued_at is 14+ days in the past
//     and the notice is still open. No backend action ever happens
//     because of this; it's a visual warning tier only.
//   - escalated_at (timestamp | null): Data Retention & Disposal
//     Policy §3.4's real 30-day compliance trigger. Set exactly once
//     by a nightly scheduled job once issued_at is 30+ days in the
//     past, and NEVER un-set by clearing/voiding after the fact — it's
//     a historical record, not a live flag. A non-null escalated_at
//     means Registrar Admins have already been notified this notice
//     needs a decision (extend / Withdraw / Close — Unable to
//     Process); render this as a visually STRONGER tier than the
//     plain 14-day is_stale badge, not the same color at a later date.
//
// issueDeficiencyNotice(requestId, data) — data shape:
//   {
//     item_key: "missing_signature" | "missing_valid_id" | "other",
//     detail?: string,  // REQUIRED when item_key === "other", max 2000 chars
//   }
//   Do NOT send item_label — it's a server-derived, denormalized display
//   value resolved from item_key at issue time; the backend rejects/ignores
//   any client-supplied value for it.
//
// clearDeficiencyNotice(noticeId) — marks the notice resolved (item was
//   submitted); processing may resume. No body. 422s if the notice is
//   already cleared or voided.
//
// voidDeficiencyNotice(noticeId, voidReason) — the "never resolved"
//   escalation outcome (student unreachable, deceased, etc.). Does NOT
//   auto-transition the parent request — that stays a manual staff
//   decision (e.g. calling withdrawDocumentRequest() separately once
//   they've reviewed the case; consider prompting for that in the same
//   UI action after a successful void). void_reason is required, max
//   2000 chars. 422s if the notice is already cleared or voided.
//
// All three act on the DEFICIENCY NOTICE'S OWN id — request.open_deficiency_notice
// .remark_id (the relation is eager-loaded onto every getDocumentRequest()/
// getDocumentRequests() response under that snake_case key) — NOT the
// parent document request's id. clear()/void() are not nested under
// /document-requests/{id}/....
// -------------------------------------------------------
export const issueDeficiencyNotice = (requestId, data) =>
  api.post(`/document-requests/${requestId}/deficiency-notices`, data);
export const clearDeficiencyNotice = (noticeId) =>
  api.post(`/deficiency-notices/${noticeId}/clear`);
export const voidDeficiencyNotice = (noticeId, voidReason) =>
  api.post(`/deficiency-notices/${noticeId}/void`, { void_reason: voidReason });

// -------------------------------------------------------
// CLOSED — UNABLE TO PROCESS (Data Retention & Disposal Policy §3.4) —
// staff/admin only, same role:3 + module:dashboard,Process gate as
// WITHDRAWN STATUS / DEFICIENCY NOTICE above. The policy's "worst-case
// scenario" closure: a request whose open Deficiency Notice can never
// be complied with because the requestor is deceased or otherwise
// permanently unable to respond. Terminal — cannot transition again.
//
// GUARD (backend-enforced, will 422 otherwise): the target request
// must currently have an OPEN Deficiency Notice
// (request.open_deficiency_notice must be present — check this before
// showing whatever UI action triggers this call; there is no reason to
// offer "Close — Unable to Process" on a request with no open notice).
// This is NOT an alternative to Withdraw — it always requires proof.
//
// data shape:
//   {
//     closure_reason: "requestor_deceased" | "requestor_incapacitated" | "other",
//     closure_detail?: string,          // REQUIRED when closure_reason === "other",
//                                        // max 2000 chars
//     closure_proof_reference: string,  // ALWAYS required (regardless of reason),
//                                        // max 500 chars — a text description of
//                                        // what was verified, e.g. "Death
//                                        // certificate submitted by [name],
//                                        // verified [date]". This is a
//                                        // reference/description only — there is
//                                        // no file upload here; the actual
//                                        // physical/scanned proof stays with the
//                                        // Registrar's Office's own records.
//   }
//
// On success returns the updated DocumentRequest. The request's
// previously-open Deficiency Notice is auto-voided server-side as part
// of this same call (same pattern as withdrawDocumentRequest() —
// no separate voidDeficiencyNotice() call needed first).
// -------------------------------------------------------
export const closeRequestUnableToProcess = (id, data) =>
  api.post(`/document-requests/${id}/close-unable-to-process`, data);

// -------------------------------------------------------
// BULK READY / BULK DONE (Multi-Item / Mixed-Status Batch rules) —
// staff/admin only. Backed by RequestItemStatusService::bulkAdvanceItems()
// on the server, NOT by updateDocumentRequest() — these do not flip a
// request's own status_id directly. Instead, for every request id you
// pass in, the backend checks that request's own request_document/
// request_certificate children INDIVIDUALLY, advances whichever ones are
// eligible, skips the rest without blocking eligible ones (on the same
// request or a different one in the batch), and then recomputes each
// affected request's aggregate status once at the end.
//
// Practical effect for the UI: you can safely pass a MIXED batch —
// selected requests do not all need to be in the same status first. A
// request with 2 of 3 items eligible will have those 2 updated and the
// third left alone; the request's own status only advances once every
// one of its items has. So the old client-side eligibility pre-filter
// (checking req.statusId before calling these) is no longer required —
// send whatever the user selected and read the response to see what
// actually happened.
//
// bulkReadyItems  -> targets ReadyToClaim (eligible items must currently
//                    be Processing or PendingSignature).
// bulkDoneItems   -> targets Completed (only items currently
//                    ReadyToClaim are eligible; this is the same "Ready
//                    -> Completed only" rule the single-item and
//                    whole-request endpoints already enforce).
//
// Both accept the same payload shape as archiveDocumentRequests/
// restoreDocumentRequests above: { request_ids: number[] }, 1–200
// distinct integers.
//
// Response shape (res.data), useful for building a real success/partial
// toast instead of a flat pass/fail:
//   {
//     target_status: "ReadyToClaim" | "Completed",
//     items_updated: [{ type: "document"|"certificate", id, request_id, old_status_id, new_status_id }],
//     items_skipped: [{ type, id, request_id, reason, current_status? }],
//        // reason is one of: "invalid_transition" | "certificate_not_generated"
//     requests_processed: number[],       // request ids that had >=1 item updated
//     requests_status_changed: number[],  // subset whose OWN aggregate status changed
//     requests_skipped: [{ request_id, reason }],
//        // reason is one of: "not_found" | "archived" | "no_eligible_items"
//   }
//
// A 200 response can still contain zero items_updated (e.g. every
// selected request was already Ready/Completed) — check items_updated.
// length / items_skipped before assuming success, rather than relying on
// promise resolution alone.
// -------------------------------------------------------
export const bulkReadyItems = (ids) => api.post(`/document-requests/bulk-ready`, { request_ids: ids });
export const bulkDoneItems  = (ids) => api.post(`/document-requests/bulk-done`,  { request_ids: ids });

// -------------------------------------------------------
// CLAIMING (QR Code Claiming Policy v1.0) — staff/admin only.
// Pass exactly one of the two: { uuid } from a decoded QR scan, or
// { claim_code } from the manual-entry fallback field. Never both —
// the backend rejects a payload containing both. On success the
// matching request is transitioned to Completed and returned; on
// failure the backend already distinguishes "no such code" (404) from
// "not ready to claim yet" / "already claimed" (422) via the normal
// status-transition guard, so surface err.response.data.message as-is
// rather than writing a new message client-side.
// -------------------------------------------------------
export const claimDocumentRequest = (credential) => api.post(`/document-requests/claim`, credential);

// -------------------------------------------------------
// ITEM-LEVEL STATUS (Phase 2) — staff/admin only.
// Advances ONE request_document/request_certificate row independently
// of the rest of the request — see backend RequestItemStatusService.
// Same coarse role/module gate as updateDocumentRequest above; the
// Process-vs-Complete split is enforced server-side once the target
// status is known. On success the backend returns the updated line
// item (not the parent request) — callers should re-fetch the parent
// via getDocumentRequest(id) to pick up the recomputed aggregate
// status shown elsewhere in the UI.
// -------------------------------------------------------
export const updateRequestDocumentStatus = (requestId, requestDocumentId, statusId) =>
  api.put(`/document-requests/${requestId}/documents/${requestDocumentId}`, { status_id: statusId });
export const updateRequestCertificateStatus = (requestId, requestCertificateId, statusId) =>
  api.put(`/document-requests/${requestId}/certificates/${requestCertificateId}`, { status_id: statusId });

// Real print/generation signal — replaces the old printedCertificateIds
// localStorage-only flag (see staffDashboardUtils.js's certificatesGenerated
// derivation), which never reached the server and so could never actually
// gate the "must be generated before Ready to Claim" checks. Called from
// GenerateCertificate.jsx's print action. requestCertificateId is optional:
// pass it when the caller knows which specific certificate line item was
// just printed (see CertificateModal.jsx's name -> id map) to mark only
// that row; omit it to fall back to marking every ungenerated certificate
// on the request (see DocumentRequestService::markCertificatesGenerated()).
export const markCertificatesGenerated = (requestId, requestCertificateId = null) =>
  api.post(`/document-requests/${requestId}/mark-certificates-generated`, {
    ...(requestCertificateId != null ? { request_certificate_id: requestCertificateId } : {}),
  });

// -------------------------------------------------------
// REQUEST HISTORY (read-only from the frontend)
// -------------------------------------------------------
export const getRequestHistory = () => api.get("/request-history");

// -------------------------------------------------------
// AUDIT LOGS (Super Admin only)
// -------------------------------------------------------
export const getAuditLogs       = (params = {}) => api.get("/audit-logs", { params });
export const getAuditLogFilters = ()             => api.get("/audit-logs/filters");

// -------------------------------------------------------
// SECURITY EVENTS (Super Admin only) — Phase 3
// Same call shape as the audit log functions above; the two endpoints
// share the frontend Audit Trail page's table/pagination via a tab toggle
// (see ReportManagement.jsx).
// -------------------------------------------------------
export const getSecurityEvents       = (params = {}) => api.get("/security-events", { params });
export const getSecurityEventFilters = ()             => api.get("/security-events/filters");

// -------------------------------------------------------
// ANALYTICS (Admin + Super Admin)
// -------------------------------------------------------
export const getAnalyticsOverview      = (params = {}) => api.get("/analytics/overview",          { params });
export const getAnalyticsVolumeTrend   = (params = {}) => api.get("/analytics/volume-trend",      { params });
export const getAnalyticsByStatus      = (params = {}) => api.get("/analytics/by-status",          { params });
export const getAnalyticsByDocType     = (params = {}) => api.get("/analytics/by-document-type",   { params });
export const getAnalyticsProcessingTime = (params = {}) => api.get("/analytics/processing-time",  { params });
export const getAnalyticsPeakHours     = (params = {}) => api.get("/analytics/peak-hours",         { params });
export const getAnalyticsByPurpose     = (params = {}) => api.get("/analytics/by-purpose",         { params });
export const postAnalyticsAiReport     = (params = {}) => api.post("/analytics/ai-report", {},     { params });
export const postAnalyticsAiQuery      = (body  = {}) => api.post("/analytics/ai-query",  body);

// -------------------------------------------------------
// SYSTEM ANALYTICS (Super Admin only) — Phase 2 of the Analytics &
// Audit Log Revamp plan. Separate from ANALYTICS above: that group is
// scoped to a single Registrar's request queue (volume, processing
// time, staff performance); this group answers "is the system itself
// healthy" (roster state, delegated-access throughput, cross-system
// verification health). System-wide volume/trend intentionally reuses
// getAnalyticsOverview()/getAnalyticsVolumeTrend() above rather than a
// separate endpoint — Super Admin already has access to those.
// -------------------------------------------------------
export const getAdminRosterHealth         = ()             => api.get("/system-analytics/admin-roster-health");
export const getAccessRequestThroughput   = (params = {}) => api.get("/system-analytics/access-request-throughput", { params });
export const getCashierVerificationHealth = (params = {}) => api.get("/system-analytics/cashier-verification-health", { params });
export const getScheduledJobsHealth       = ()             => api.get("/system-analytics/scheduled-jobs-health");

// -------------------------------------------------------
// ANNOUNCEMENTS (read: all authenticated | write: Super Admin)
// -------------------------------------------------------
export const getAnnouncements  = (page = 1, perPage = 4) =>
  api.get("/announcements", { params: { page, per_page: perPage } });
export const getArchivedAnnouncements = (page = 1, perPage = 4) =>
  api.get("/announcements", { params: { page, per_page: perPage, view: "archived" } });
export const getAnnouncement   = (id)        => api.get(`/announcements/${id}`);
export const createAnnouncement = (data)     => api.post("/announcements", data);
export const updateAnnouncement = (id, data) => api.put(`/announcements/${id}`, data);
export const deleteAnnouncement = (id)       => api.delete(`/announcements/${id}`);
export const archiveAnnouncement = (id, reason) => api.patch(`/announcements/${id}/archive`, { reason });
export const restoreAnnouncement = (id)      => api.patch(`/announcements/${id}/restore`);

// -------------------------------------------------------
// ALUMNI SYSTEM (PUPTAPS) — proxied through RIS backend
// read: all authenticated roles
// -------------------------------------------------------
export const getAlumniSystemList   = (params = {}) => api.get("/alumni-system",      { params });
export const getAlumniSystemRecord = (id)          => api.get(`/alumni-system/${id}`);

export default api;