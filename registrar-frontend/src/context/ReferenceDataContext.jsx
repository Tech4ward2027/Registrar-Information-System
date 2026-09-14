import { createContext, useContext, useState, useEffect } from "react";
import {
  getDocumentTypes,
  getCertifications,
  getRequestStatuses,
  getRequestPurposes,
  getPrograms,
  getSignatories,
  getLogbookCategories,
  getFulfillmentTracks,
} from "../services/api";
import { useAuth } from "./AuthProvider";

/**
 * ReferenceDataContext
 * --------------------
 * Fetches all reference / lookup data from the API once the user is
 * authenticated and makes it available throughout the app.
 *
 * Why this replaces constants.js hardcoded maps:
 *   - The DB is the source of truth. If an admin adds a new document type,
 *     the frontend picks it up immediately without a code deploy.
 *   - The old DOC_TYPE_MAP, CERTIFICATION_MAP, and PURPOSE_MAP were integer-keyed
 *     objects that would silently drift from the database.
 *
 * Fix (2026-05-14): gated the fetch on auth readiness.
 *   Previously the useEffect fired immediately on mount — before AuthProvider
 *   had resolved the /me call — causing 401s on every reference data endpoint
 *   at login time. Now we wait until `authLoading` is false AND `user` is set.
 *
 * Usage:
 *   const { documentTypes, certifications, signatories, statuses, purposes, loading } = useReferenceData();
 *
 * Convenience helpers are also exported:
 *   docTypeName(id)   → string | undefined
 *   certName(id)      → string | undefined
 *   purposeName(id)   → string | undefined
 *   statusConfig(id)  → { label, classes } | undefined
 *
 * Note: GET /signatories is admin-only server-side (unlike document
 * types/certifications, which any authenticated role can read) — see
 * routes/api.php. For non-admin sessions that request will 403 and
 * Promise.allSettled below simply leaves `signatories` as []; that's
 * expected, since only certificate generation (an admin-only flow)
 * needs it.
 */

// Status display config is purely presentational — it does NOT need to come
// from the API and is safe to keep here because the backend enum values are
// stable by design (changing status IDs is a breaking migration, not a routine
// admin action). We map over API-fetched statuses and merge this in.
const STATUS_DISPLAY = {
  1:  { label: "Processing",         classes: "bg-yellow-100 text-yellow-700 border-yellow-200" },
  2:  { label: "Ready to Claim",     classes: "bg-green-100 text-green-700 border-green-200"  },
  3:  { label: "Completed",          classes: "bg-gray-100 text-gray-700 border-gray-200"     },
  4:  { label: "Forfeited",          classes: "bg-red-100 text-red-700 border-red-200"        },
  5:  { label: "Cancelled",          classes: "bg-orange-100 text-orange-700 border-orange-200" },
  6:  { label: "Awaiting Signature", classes: "bg-orange-100 text-orange-700 border-orange-200" },
  // RequestStatusEnum::AwaitingSubmission (backend) — the CTC / Authentication
  // Fee starting status. Given its own color (not reused from Pending
  // Signature's orange) so staff can tell the two "waiting on something
  // outside our control" states apart at a glance.
  12: { label: "Awaiting Submission", classes: "bg-emerald-100 text-emerald-700 border-emerald-200" },
};

const ReferenceDataContext = createContext(null);

export const ReferenceDataProvider = ({ children }) => {
  const { user, loading: authLoading } = useAuth();

  const [documentTypes,   setDocumentTypes]   = useState([]);
  const [certifications,  setCertifications]  = useState([]);
  const [statuses,        setStatuses]        = useState([]);
  const [purposes,        setPurposes]        = useState([]);
  const [programs,        setPrograms]        = useState([]);
  const [signatories,     setSignatories]     = useState([]);
  const [logbookCategories, setLogbookCategories] = useState([]);
  const [fulfillmentTracks, setFulfillmentTracks] = useState([]);
  const [loading,         setLoading]         = useState(true);

  useEffect(() => {
    // Wait until AuthProvider has finished its /me check.
    // - authLoading === true  → /me is still in-flight; do nothing yet.
    // - authLoading === false, user === null → not logged in; skip fetch
    //   (unauthenticated pages don't need reference data).
    // - authLoading === false, user !== null → authenticated; safe to fetch.
    if (authLoading) return;

    if (!user) {
      // Not authenticated — reset to empty defaults and mark done
      // so consumers don't spin forever on the loading state.
      setDocumentTypes([]);
      setCertifications([]);
      setStatuses([]);
      setPurposes([]);
      setPrograms([]);
      setSignatories([]);
      setLogbookCategories([]);
      setFulfillmentTracks([]);
      setLoading(false);
      return;
    }

    // Fetch all reference data in parallel. Individual failures are caught so
    // one unavailable endpoint cannot block the rest of the app from loading.
    const load = async () => {
      setLoading(true);
      const results = await Promise.allSettled([
        getDocumentTypes(),
        getCertifications(),
        getRequestStatuses(),
        getRequestPurposes(),
        getPrograms(),
        getSignatories(),
        getLogbookCategories(),
        getFulfillmentTracks(),
      ]);
      if (results[0].status === "fulfilled") setDocumentTypes(results[0].value.data ?? []);
      if (results[1].status === "fulfilled") setCertifications(results[1].value.data ?? []);
      if (results[2].status === "fulfilled") setStatuses(results[2].value.data ?? []);
      if (results[3].status === "fulfilled") setPurposes(results[3].value.data ?? []);
      if (results[4].status === "fulfilled") setPrograms(results[4].value.data?.data ?? []);
      // Already ordered by sort_order server-side (see SignatoryController::index).
      // Rejects here (e.g. 403 for a non-admin session) simply leave signatories
      // as [] — see the doc comment above.
      if (results[5].status === "fulfilled") setSignatories(results[5].value.data ?? []);
      if (results[6].status === "fulfilled") setLogbookCategories(results[6].value.data ?? []);
      if (results[7].status === "fulfilled") setFulfillmentTracks(results[7].value.data ?? []);
      setLoading(false);
    };

    load();
  }, [authLoading, user]); // re-run if auth state changes (login / logout)

  // ── Convenience lookup helpers ───────────────────────────────────────────

  /** Return the document name for a given document_type_id, or undefined. */
  const docTypeName = (id) =>
    documentTypes.find((d) => d.document_type_id === id)?.document_name;

  /** Return the certification name for a given certificate_type_id, or undefined. */
  const certName = (id) =>
    certifications.find((c) => c.certificate_type_id === id)?.certificate_name;

  /**
   * Return { label, classes } display config for a given status_id.
   * Falls back to STATUS_DISPLAY for styling; uses API name for the label
   * when available so the label always matches the DB value.
   */
  const statusConfig = (id) => {
    const apiStatus = statuses.find((s) => Number(s.status_id) === Number(id));
    const display   = STATUS_DISPLAY[id] ?? { label: "Unknown", classes: "bg-gray-100 text-gray-700" };
    return {
      ...display,
      label: apiStatus?.status_name ?? display.label,
    };
  };

  /** Return the purpose name for a given request_purpose_id, or undefined. */
  const purposeName = (id) =>
    purposes.find((p) => p.request_purpose_id === id)?.purpose_name;

  /**
   * Return the full program name for a given ogos_course_id, or undefined.
   * Usage: programName(student.course_id) → "BS Information Technology"
   */
  const programName = (id) =>
    programs.find((p) => Number(p.ogos_course_id) === Number(id))?.name;

  /** Return the signatory record for a given signatory_id, or undefined. */
  const signatoryById = (id) =>
    signatories.find((s) => Number(s.signatory_id) === Number(id));

  /** Return the logbook category name for a given logbook_category_id, or undefined. */
  const logbookCategoryName = (id) =>
    logbookCategories.find((c) => Number(c.logbook_category_id) === Number(id))?.name;

  /** Return the fulfillment track name for a given fulfillment_track_id, or undefined. */
  const fulfillmentTrackName = (id) =>
    fulfillmentTracks.find((t) => Number(t.fulfillment_track_id) === Number(id))?.name;

  /**
   * Re-fetch just the document types list. documentTypes is otherwise only
   * fetched once per session (on login — see the main useEffect above), so
   * any admin fix to a document_type's name/patterns made mid-session is
   * invisible to already-open tabs until they log out and back in. Call
   * this right before an OR-verification flow reads availableDocs/
   * documentOptions (see RequestForm.jsx / AlumniRequest.jsx), so a
   * suggested match's name and its selectable checkbox option always agree
   * with the current DB row, not a stale login-time snapshot.
   */
  const refreshDocumentTypes = async () => {
    try {
      const res = await getDocumentTypes();
      setDocumentTypes(res.data ?? []);
    } catch {
      // Leave the existing list as-is on failure, same reasoning as
      // refreshSignatories() below.
    }
  };

  /**
   * Re-fetch just the certifications list. Same staleness reasoning and
   * same call sites as refreshDocumentTypes() above.
   */
  const refreshCertifications = async () => {
    try {
      const res = await getCertifications();
      setCertifications(res.data ?? []);
    } catch {
      // Leave the existing list as-is on failure, same reasoning as
      // refreshSignatories() below.
    }
  };

  /**
   * Re-fetch just the signatories list. Call this after create/update/delete
   * from an admin management screen so the rest of the app (e.g. the
   * certificate signee dropdown) sees the change without a full reload of
   * every other reference dataset.
   */
  const refreshSignatories = async () => {
    try {
      const res = await getSignatories();
      setSignatories(res.data ?? []);
    } catch {
      // Leave the existing list as-is on failure (e.g. transient network
      // error) rather than clearing it out from under the UI.
    }
  };

  /**
   * Re-fetch just the logbook categories list. Call this after creating a
   * new category inline from the Add Document/Add Certificate screen
   * (DocumentManagement.jsx) so the dropdown reflects it immediately
   * without a full reload of every other reference dataset — same
   * pattern as refreshSignatories() above.
   */
  const refreshLogbookCategories = async () => {
    try {
      const res = await getLogbookCategories();
      setLogbookCategories(res.data ?? []);
    } catch {
      // Leave the existing list as-is on failure, same reasoning as
      // refreshSignatories().
    }
  };

  /**
   * Re-fetch just the fulfillment tracks list. Same pattern and same
   * reason as refreshLogbookCategories() — used after an inline create
   * from DocumentManagement.jsx.
   */
  const refreshFulfillmentTracks = async () => {
    try {
      const res = await getFulfillmentTracks();
      setFulfillmentTracks(res.data ?? []);
    } catch {
      // Leave the existing list as-is on failure, same reasoning as
      // refreshSignatories().
    }
  };

  return (
    <ReferenceDataContext.Provider
      value={{
        documentTypes,
        certifications,
        statuses,
        purposes,
        programs,
        signatories,
        logbookCategories,
        fulfillmentTracks,
        loading,
        docTypeName,
        certName,
        statusConfig,
        purposeName,
        programName,
        signatoryById,
        logbookCategoryName,
        fulfillmentTrackName,
        refreshSignatories,
        refreshLogbookCategories,
        refreshFulfillmentTracks,
        refreshDocumentTypes,
        refreshCertifications,
      }}
    >
      {children}
    </ReferenceDataContext.Provider>
  );
};

export const useReferenceData = () => {
  const ctx = useContext(ReferenceDataContext);
  if (!ctx) throw new Error("useReferenceData must be used inside ReferenceDataProvider");
  return ctx;
};