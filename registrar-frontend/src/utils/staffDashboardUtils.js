/**
 * Status names that correspond to an actual reachable (or historically
 * reachable) value of document_request.status_id, per
 * app/Enums/RequestStatusEnum.php on the backend.
 *
 * The `request_status` reference table also contains rows that were never
 * wired into the document-request workflow at all — e.g. "On Hold",
 * "Rejected" (that name belongs to a *different* feature, Access
 * Requests), "Returned", "Draft" — or that describe an unrelated concept
 * ("Archived" is the separate document_request.is_archived flag, not a
 * status). Those rows exist only for legacy/reference-table reasons and
 * must never be offered as selectable options in the Staff Dashboard
 * status filter: picking one would silently return zero results forever,
 * since no request can ever hold that status.
 *
 * Order here also defines the order options are shown in the filter.
 * Keep this list in sync with RequestStatusEnum on the backend.
 */
import { formatName } from './formatters';

export const WORKFLOW_STATUS_NAMES = [
  'Awaiting Submission',
  'Processing',
  'Pending Signature',
  'Ready to Claim',
  'Completed',
  'Forfeited',
  'Withdrawn',
  'Closed - Unable to Process',
  // Deprecated: unreachable for any new request (see the @deprecated note
  // on RequestStatusEnum::Cancelled) but kept selectable so staff can
  // still filter historical requests that were cancelled before the
  // status was retired.
  'Cancelled',
];

/**
 * Builds the list of status names safe to show in the Staff Dashboard
 * status filter, from the live reference-data rows returned by the API.
 * Filters out reference rows with no corresponding reachable workflow
 * status (see WORKFLOW_STATUS_NAMES above), de-dupes, and orders them to
 * match the workflow's natural progression rather than table insertion
 * order.
 */
export const getWorkflowStatusOptions = (requestStatuses) => {
  const allowedLowerNames = new Set(WORKFLOW_STATUS_NAMES.map(n => n.toLowerCase()));

  const availableNames = (requestStatuses ?? [])
    .map(s => s?.status_name)
    .filter(Boolean)
    .filter((name, index, self) => self.indexOf(name) === index)
    .filter(name => allowedLowerNames.has(name.toLowerCase()));

  return WORKFLOW_STATUS_NAMES.filter(name =>
    availableNames.some(available => available.toLowerCase() === name.toLowerCase())
  );
};

export const STATUS_FALLBACK = {
  PENDING: 1,
  PENDING_SIGNATURE: 6,
  READY: 2,
  COMPLETED: 3,
  FORFEITED: 4,
  // See RequestStatusEnum::AwaitingSubmission (backend) — status_id 12,
  // the starting status for requests with a requires_source_submission
  // item (CTC / Authentication Fee). Falls back to 12 for the same
  // reason the others fall back to their fixed ids: if the reference-data
  // fetch hasn't resolved yet, we still want a correct id rather than
  // `undefined` breaking every `statusId === resolvedStatusIds.X` check.
  AWAITING_SUBMISSION: 12,
  WITHDRAWN: 13,
};

export const COMPLETED_VISIBILITY_MS = 24 * 60 * 60 * 1000;

// Deprecated: was the localStorage key for the old client-only
// printedCertificateIds flag. Removed along with that flag now that
// certificatesGenerated (derived from the server's generated_at column
// in mapDocumentRequest below) is the real, persisted signal. Left as a
// no-op export rather than deleted outright in case anything external
// still imports it, or a browser has a stale value sitting under this
// key — it's inert either way.
export const PRINTED_CERTIFICATE_STORAGE_KEY = 'printed-certificate-request-ids';

/**
 * Helper to convert ALL CAPS names into Title Case (e.g. Karl Christian Caya).
 */
export const formatTitleCase = (str) => {
  if (!str) return '';
  return str
    .toLowerCase()
    .split(' ')
    .filter(Boolean)
    .map(word => {
      return word
        .split('-')
        .map(part => part.charAt(0).toUpperCase() + part.slice(1))
        .join('-');
    })
    .join(' ');
};

/**
 * Resolves API status name to ID mappings based on database reference statuses.
 */
export const resolveStatusIds = (requestStatuses) => {
  const lowerNameToId = Object.fromEntries(
    (requestStatuses ?? [])
      .filter(s => s?.status_name && s?.status_id)
      .map(s => [s.status_name.toLowerCase(), Number(s.status_id)])
  );
  return {
    PENDING: lowerNameToId.pending ?? STATUS_FALLBACK.PENDING,
    // 'pending signature' is a distinct exact-match key from 'pending'
    // above — this does NOT collide with the PENDING lookup. See the doc
    // block on migration 2026_08_15_000000_add_pending_signature_status
    // (backend) for the history of why that distinction matters here.
    PENDING_SIGNATURE: lowerNameToId['pending signature'] ?? STATUS_FALLBACK.PENDING_SIGNATURE,
    READY: lowerNameToId['ready to claim'] ?? STATUS_FALLBACK.READY,
    COMPLETED: lowerNameToId.completed ?? STATUS_FALLBACK.COMPLETED,
    FORFEITED: lowerNameToId.forfeited ?? STATUS_FALLBACK.FORFEITED,
    // 'awaiting submission' lowercases to a string distinct from 'pending'
    // — same non-collision reasoning as PENDING_SIGNATURE above (see
    // RequestStatusEnum::AwaitingSubmission's doc block on the backend).
    AWAITING_SUBMISSION: lowerNameToId['awaiting submission'] ?? STATUS_FALLBACK.AWAITING_SUBMISSION,
  };
};

/**
 * Default dashboard visibility rules:
 *  - Awaiting Submission / Pending / Processing / Pending Signature /
 *    Ready to Claim → always shown
 *  - Completed → shown only within 1 day of the request date
 *  - Everything else (Forfeited, Cancelled, ...) → hidden unless filtered/searched
 */
export const isDefaultVisible = (req, resolvedStatusIds) => {
  const { statusId, statusName, timestamp } = req;
  const name = String(statusName ?? '').trim().toLowerCase();
  if (statusId === resolvedStatusIds.AWAITING_SUBMISSION || name === 'awaiting submission') return true;
  if (statusId === resolvedStatusIds.PENDING || name === 'pending')         return true;
  if (name === 'processing')                                           return true;
  if (statusId === resolvedStatusIds.PENDING_SIGNATURE || name === 'pending signature') return true;
  if (statusId === resolvedStatusIds.READY || name === 'ready to claim')    return true;
  if (statusId === resolvedStatusIds.COMPLETED || name === 'completed') {
    return timestamp > 0 && (Date.now() - timestamp) <= COMPLETED_VISIBILITY_MS;
  }
  return false;
};

/**
 * Transforms raw API requests into flat dashboard data models.
 */
export const mapDocumentRequest = (r, resolvedStatusIds, docTypeName) => {
  const requestDate = r.requested_at ? new Date(r.requested_at) : null;

  let computedStatusId = r.status?.status_id;
  let computedStatusName = r.status?.status_name;

  const isArchived = Boolean(r.is_archived);

  // BUG FIX (client-side auto-forfeit race condition — see
  // routes/console.php and useStaffDashboard.js for the full writeup):
  // this used to locally recompute "is this forfeited yet?" from
  // requested_at (creation date) and relabel the row as Forfeited in the
  // UI ahead of the backend actually forfeiting it — using a different
  // clock than the backend's own rule (which measures from the most
  // recent transition INTO ReadyToClaim, not from creation). That made
  // the two implementations silently disagree on which requests were
  // actually overdue, on top of the write-race problem in the hook.
  // The backend (ShredExpiredRequests, now hourly) is the single source
  // of truth for this transition — status_id/status_name below always
  // reflect exactly what the server reports, nothing computed on top.

  const finalCertName = r.certificates?.length > 0
    ? r.certificates.map(c => c.certification_type?.certificate_name).filter(Boolean).join(', ')
    : null;

  // Real, server-derived replacement for the old printedCertificateIds
  // localStorage flag — true when the request has no certificates at all
  // (nothing to gate), or when EVERY certificate line item has actually
  // been generated (generated_at set — see migration
  // 2026_08_29_000010_add_generated_at_to_request_certificate). Requiring
  // all rather than "at least one" matches the per-item guard in
  // RequestItemStatusService::guardCertificateGenerated(), which checks
  // each certificate independently; the whole-request buttons in
  // StaffDashboard.jsx that read this field are coarser (one badge for
  // the whole row), so "all generated" is the safer bar to clear before
  // showing that row as unblocked.
  const certificatesGenerated = !(r.certificates?.length > 0)
    || r.certificates.every(c => c.generated_at != null);

  const hasCertificates = Boolean(r.certificates && r.certificates.length > 0);
  const isCertificate = hasCertificates;

  const getDocName = d =>
    d.document_type?.document_name ||
    docTypeName(d.document_type_id) ||
    `Unknown Doc (ID: ${d.document_type_id})`;

  const totalCopies = (r.documents?.reduce((sum, d) => sum + (Number(d.number_of_copies) || 1), 0) || 0) + 
                      (r.certificates?.reduce((sum, c) => sum + (Number(c.number_of_copies) || 1), 0) || 0) || 1;

  const documentDetailsArray = (() => {
    const docs = [];
    if (r.certificates?.length > 0) {
      r.certificates.forEach(c => {
        if (c.certification_type?.certificate_name) {
          docs.push(`Certification: ${c.certification_type.certificate_name}`);
        }
      });
    }
    if (r.documents?.length > 0) {
      r.documents.forEach(d => docs.push(getDocName(d)));
    }
    return docs;
  })();

  return {
    id: r.request_id,
    rawRequest: {
      ...r,
      status: {
        ...(r.status || {}),
        status_id: computedStatusId,
        status_name: computedStatusName,
      },
    },
    studentName: formatName(r) || 'N/A',
    studentNumber: r.academic_record?.student_number
      ?? r.alumni_academic_record?.student_number
      ?? 'N/A',
    userType: r.student_profile ? 'Student' : 'Alumni',
    certName: finalCertName,
    certificateNames: r.certificates?.map(c => c.certification_type?.certificate_name).filter(Boolean) ?? [],
    isCertificate,
    certificatesGenerated,
    copies: totalCopies,
    documentDetailsArray,
    course: r.student_profile?.course ?? '',
    major: r.student_profile?.major ?? '',
    educationLevel: r.student_profile?.education_level ?? '',
    syAdmitted: r.academic_record?.sy_admitted ?? '',
    dateGraduated: r.academic_record?.date_graduated ?? '',
    diplomaNum: r.academic_record?.diploma_number ?? '',
    eventTitle: r.event_title ?? '',
    or_number: r.or_number ?? '',
    date: requestDate
      ? requestDate.toLocaleDateString('en-GB', { day: '2-digit', month: 'long', year: 'numeric' })
      : 'N/A',
    time: requestDate
      ? requestDate.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false })
      : '',
    statusId: computedStatusId,
    statusName: computedStatusName,
    timestamp: requestDate ? requestDate.getTime() : 0,
    // Archive state — a flag independent of status_id, not a status
    // itself. Restoring a record leaves statusId/statusName exactly
    // as they were (Archive Rules policy).
    isArchived,
    archivedOn: r.archived_on ?? null,
    archivedBy: r.archived_by_user?.email ?? null,
    withdrawalReason: r.withdrawal_reason ?? null,
    withdrawalDetail: r.withdrawal_detail ?? null,
    supersededByRequestId: r.superseded_by_request_id ?? null,
    closureReason: r.closure_reason ?? null,
    closureDetail: r.closure_detail ?? null,
    closureProofReference: r.closure_proof_reference ?? null,
    openDeficiencyNotice: r.open_deficiency_notice ?? null,
  };
};

/**
 * Filter and sort data records.
 */
export const filterAndSortRequests = (requests, { filterStatus, filterClassification, filterDocument, searchTerm, sortOrder, viewMode, resolvedStatusIds }) => {
  const isFiltering =
    filterStatus !== 'All' ||
    filterClassification !== 'All' ||
    (filterDocument && filterDocument !== 'All') ||
    searchTerm.trim() !== '';

  return requests
    .filter(r => {
      // The Active/Archived split now happens server-side (fetchData asks
      // for ?view=archived or the default non-archived scope) — no need
      // to re-derive it from a synthetic status here.
      if (viewMode !== 'archived' && !isFiltering && !isDefaultVisible(r, resolvedStatusIds)) {
        return false;
      }

      const matchesStatus =
        filterStatus === 'All' ||
        (filterStatus === 'Completed' && r.statusId === resolvedStatusIds.COMPLETED) ||
        r.statusName === filterStatus;
      const matchesClassification =
        filterClassification === 'All' ||
        r.userType.toLowerCase() === filterClassification.toLowerCase();
      const matchesDocument =
        !filterDocument ||
        filterDocument === 'All' ||
        (r.documentDetailsArray && r.documentDetailsArray.some(d =>
          d.toLowerCase() === filterDocument.toLowerCase() ||
          d.toLowerCase().includes(filterDocument.toLowerCase()) ||
          filterDocument.toLowerCase().includes(d.toLowerCase())
        ));
      const term = searchTerm.trim().toLowerCase();
      const matchesSearch =
        term === '' ||
        r.studentName.toLowerCase().includes(term) ||
        r.studentNumber.toLowerCase().includes(term) ||
        r.id.toString().includes(term) ||
        (r.statusName && r.statusName.toLowerCase().includes(term)) ||
        (r.certName && r.certName.toLowerCase().includes(term)) ||
        (r.documentDetailsArray && r.documentDetailsArray.some(d => d.toLowerCase().includes(term)));

      return matchesStatus && matchesClassification && matchesDocument && matchesSearch;
    })
    .sort((a, b) => {
      const aCompleted = String(a.statusId) === String(resolvedStatusIds?.COMPLETED) || String(a.statusName ?? '').trim().toLowerCase() === 'completed';
      const bCompleted = String(b.statusId) === String(resolvedStatusIds?.COMPLETED) || String(b.statusName ?? '').trim().toLowerCase() === 'completed';

      if (aCompleted && !bCompleted) return 1;
      if (!aCompleted && bCompleted) return -1;

      if (sortOrder === 'Recent Requests') {
        return b.timestamp - a.timestamp;
      }
      if (sortOrder === 'Old Requests') {
        return a.timestamp - b.timestamp;
      }
      if (sortOrder === 'Classification Asc') {
        return a.userType.localeCompare(b.userType);
      }
      if (sortOrder === 'Classification Desc') {
        return b.userType.localeCompare(a.userType);
      }
      if (sortOrder === 'Status Asc') {
        return (a.statusName || '').localeCompare(b.statusName || '');
      }
      if (sortOrder === 'Status Desc') {
        return (b.statusName || '').localeCompare(a.statusName || '');
      }
      return 0;
    });
};