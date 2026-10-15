/**
 * logbookHelpers.js
 * Shared pure-function helpers used by both Logbook.jsx (display) and
 * logbookDocx.js (export).  A single source of truth prevents drift between
 * what the UI shows and what ends up in the exported document.
 */
import { formatName } from './formatters.js';

// ---------------------------------------------------------------------------
// Date / time formatters
// ---------------------------------------------------------------------------

/** Format an ISO value as "Month DD, YYYY" */
export const formatDateLong = (value, includeTime = false) => {
  if (!value) return null;
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return null;

  const datePart = date.toLocaleDateString('en-US', {
    month: 'long',
    day: '2-digit',
    year: 'numeric',
  });

  if (!includeTime) return datePart;

  const timePart = date.toLocaleTimeString('en-US', {
    hour: '2-digit',
    minute: '2-digit',
    hour12: false,
  });

  return `${datePart} ${timePart}`;
};

/** Extract the gender/sex from a request row */
export const getGender = (row) => {
  if (!row) return '---';
  const p =
    row.student_profile ||
    row.studentProfile ||
    row.alumni_profile ||
    row.alumniProfile ||
    row.undergrad_requestor_profile ||
    row.undergradRequestorProfile ||
    row.user?.student_profile ||
    row.user?.studentProfile ||
    row.user?.alumni_profile ||
    row.user?.alumniProfile ||
    row.user?.undergrad_requestor_profile ||
    row.user?.undergradRequestorProfile;

  return (
    row.gender ||
    row.sex_at_birth ||
    p?.sex_at_birth ||
    p?.gender ||
    '---'
  );
};

/** Format an ISO value as "Month DD, YYYY HH:MM" (24-hour) */
export const formatDateTimeLong = (value) => {
  const datePart = formatDateLong(value);
  if (!datePart) return null;
  const timePart = new Date(value).toLocaleTimeString('en-US', {
    hour: '2-digit',
    minute: '2-digit',
    hour12: false,
  });
  return `${datePart} ${timePart}`;
};

/** Convert a minutes value into a human-readable duration string */
export const formatMinutesDuration = (minutesValue) => {
  if (minutesValue === null || minutesValue === undefined || minutesValue === '') return '---';

  const totalMinutes = Number(minutesValue);
  if (Number.isNaN(totalMinutes) || totalMinutes < 0) return '---';

  const wholeMinutes = Math.floor(totalMinutes);
  const days = Math.floor(wholeMinutes / 1440);
  const hours = Math.floor((wholeMinutes % 1440) / 60);
  const minutes = wholeMinutes % 60;
  const minuteLabel = minutes === 1 ? 'min' : 'mins';

  if (days > 0) return `${days}day${days > 1 ? 's' : ''} ${hours}hr${hours !== 1 ? 's' : ''} ${minutes}${minuteLabel}`;
  if (hours > 0) return `${hours}hr${hours !== 1 ? 's' : ''} ${minutes}${minuteLabel}`;
  return `${minutes}${minuteLabel}`;
};

// ---------------------------------------------------------------------------
// Row field extractors — normalise the multiple possible payload shapes
// ---------------------------------------------------------------------------

/** Convert text to Proper Case, preserving roman numerals and hyphens */
export const toProperCase = (value = '') =>
  value
    .toString()
    .trim()
    .split(/\s+/)
    .map((token) => {
      if (/^[IVXLCDM]+$/i.test(token)) return token.toUpperCase();
      return token
        .toLowerCase()
        .replace(/(^|[-'])([a-z])/g, (_, sep, letter) => `${sep}${letter.toUpperCase()}`);
    })
    .join(' ');

export const getFullName = (row) => {
  const name = formatName(row, { lastNameFirst: true, middleInitialOnly: true });
  return name || 'Walk-in Client';
};

/** Extract the course string from a request row */
export const getCourse = (row) => {
  if (!row) return '---';
  const val =
    row.course ||
    row.undergrad_requestor_profile?.program ||
    row.undergrad_requestor_profile?.course ||
    row.undergradRequestorProfile?.program ||
    row.undergradRequestorProfile?.course ||
    row.user?.undergrad_requestor_profile?.program ||
    row.user?.undergrad_requestor_profile?.course ||
    row.user?.undergradRequestorProfile?.program ||
    row.user?.undergradRequestorProfile?.course ||
    row.program ||
    row.student_profile?.academic_records?.[0]?.course ||
    row.student_profile?.course ||
    row.studentProfile?.academicRecords?.[0]?.course ||
    row.studentProfile?.course ||
    row.academic_record?.course ||
    row.academicRecord?.course ||
    row.alumni_academic_record?.course ||
    row.alumniAcademicRecord?.course ||
    '';

  return typeof val === 'string' && val.trim() ? val.trim() : '---';
};

/**
 * The e-mail to show for a request row.
 *
 * The logbook endpoint resolves `email` on the server (Alumni profile owner,
 * then Student profile owner, then the filing account). The nested reads below
 * are only a fallback for a payload from an older backend build, so an Alumni
 * requester is still found even then.
 */
export const getEmail = (row) =>
  row?.email ||
  row?.alumni_profile?.alumni?.user?.email ||
  row?.student_profile?.user?.email ||
  row?.user?.email ||
  '---';

// ---------------------------------------------------------------------------
// Processing time — read from the server, never computed in the browser.
// ---------------------------------------------------------------------------
// GET /document-requests/logbook returns, per row, the official figures from
// ProcessingTimeService (the single source of truth for the processing-time
// rule, shared with the OPCR report):
//   row.processing        { minutes, processed_at, end_status_id, claimed_at }
//   row.items_processing  [{ type: 'document'|'certificate', id, ...same }]
// `minutes` is null when a record cannot be measured; it is shown as "---",
// never replaced by a wall-clock estimate.

const EMPTY_PROCESSING = Object.freeze({
  minutes: null,
  processed_at: null,
  end_status_id: null,
  claimed_at: null,
});

/**
 * The processing figures for a row: for one document/certificate line when
 * `item` is given (the logbook is filtered to one category), otherwise for the
 * whole request. Falls back to the whole-request figure when the line has no
 * entry of its own.
 */
export const getProcessing = (row, item = null) => {
  if (!row) return EMPTY_PROCESSING;

  if (item) {
    const docId = item.request_document_id ?? item.rawItem?.request_document_id ?? null;
    const certId = item.request_certificate_id ?? item.rawItem?.request_certificate_id ?? null;
    const entries = Array.isArray(row.items_processing) ? row.items_processing : [];

    const match = entries.find(
      (entry) =>
        (docId !== null && entry?.type === 'document' && Number(entry.id) === Number(docId)) ||
        (certId !== null && entry?.type === 'certificate' && Number(entry.id) === Number(certId))
    );
    if (match) return match;
  }

  return row.processing ?? EMPTY_PROCESSING;
};

/** When processing finished: Pending Signature, or Ready to Claim when no signature was needed. */
export const getProcessedAt = (row, item = null) => getProcessing(row, item).processed_at ?? null;

/** Official processing minutes (office hours, Processing only), or null. */
export const getProcessingMinutes = (row, item = null) => {
  const minutes = getProcessing(row, item).minutes;
  return minutes === undefined ? null : minutes;
};

/** When the request/line was claimed. Never part of the processing minutes. */
export const getClaimedAt = (row, item = null) => getProcessing(row, item).claimed_at ?? null;

/** Extract document type names from a request row */
export const getDocumentNames = (row) =>
  (Array.isArray(row?.documents) ? row.documents : [])
    .map(
      (d) =>
        d?.documentType?.document_name ??
        d?.document_type?.document_name ??
        d?.document_name ??
        ''
    )
    .filter(Boolean)
    .map((n) => String(n).trim())
    .filter(Boolean);

/**
 * Extract certification type names from a request row.
 *
 * The backend eager-loads this relation as `certificationType` (see
 * RequestCertificate::certificationType() / DocumentRequestController::
 * RELATIONS => 'certificates.certificationType'), and Eloquent serializes
 * relations under their exact method name — not a snake_cased version of
 * it. The `certification_type` key below is kept only as a defensive
 * fallback for any alternate payload shape; it is not what this app's API
 * actually returns, so it must never be the *only* lookup or this always
 * resolves to an empty string (request_certificate itself has no
 * certificate_name column to fall back to).
 */
export const getCertificationNames = (row) =>
  (Array.isArray(row?.certificates) ? row.certificates : [])
    .map(
      (c) =>
        c?.certificationType?.certificate_name ??
        c?.certification_type?.certificate_name ??
        c?.certificate_name ??
        c?.name ??
        ''
    )
    .filter(Boolean)
    .map((n) => String(n).trim())
    .filter(Boolean);

// ---------------------------------------------------------------------------
// Logbook rollup helpers
// ---------------------------------------------------------------------------
// A document_type/certificate_type row can optionally point at a shared
// logbook_category (see the backend's 2026_08_29_000000_create_logbook_
// categories_table migration and DocumentType::logbookLabel() /
// CertificationType::logbookLabel()): several differently-processed types
// (e.g. every "Certified True Copy of X" variant) can be configured to
// collapse into ONE umbrella line in the logbook and its DOCX export,
// without merging their actual processing identity.
//
// These helpers are the single source of truth for resolving that display
// label on the frontend, mirroring the backend's own fallback rule
// exactly: category name if the type has one assigned, otherwise the
// type's own name. Both Logbook.jsx (on-screen grouping/filtering) and the
// DOCX export must resolve labels the same way, or the exported document
// could silently disagree with what staff saw on screen.

/**
 * Resolve the label a single document/certificate type should be logged
 * and exported under.
 *
 * @param {number|string|null|undefined} categoryId - the type's logbook_category_id (may be null/blank/absent).
 * @param {string} ownName - the type's own display name (document_name / certificate_name), used as the fallback.
 * @param {Map<number, string>|undefined} categoryNameById - lookup built from GET /logbook-categories, keyed by logbook_category_id.
 * @returns {string} Always a non-empty, trimmed string.
 */
export const resolveLogbookLabel = (categoryId, ownName, categoryNameById) => {
  const fallback = String(ownName ?? '').trim() || 'Unspecified';

  if (categoryId === null || categoryId === undefined || categoryId === '') {
    return fallback;
  }

  const numericId = Number(categoryId);
  if (Number.isNaN(numericId)) return fallback;

  const resolved = categoryNameById?.get?.(numericId);
  const trimmedResolved = resolved ? String(resolved).trim() : '';
  return trimmedResolved || fallback;
};

/**
 * Logbook display labels for every document line item on a request row.
 *
 * Reads each item's own embedded document type (`documentType`, eager-
 * loaded by the backend — see DocumentRequestController::RELATIONS)
 * rather than joining against a separately-fetched active-catalog list,
 * so this keeps resolving correctly even for archived/legacy document
 * types that may no longer appear in the live dropdown.
 *
 * @param {object} row - a request row with a `documents` array.
 * @param {Map<number, string>|undefined} categoryNameById - lookup built from GET /logbook-categories.
 * @returns {string[]} De-duplicated, non-empty labels are NOT enforced here — callers decide whether duplicates matter.
 */
export const getDocumentLogbookLabels = (row, categoryNameById) =>
  (Array.isArray(row?.documents) ? row.documents : [])
    .map((d) => {
      const type = d?.documentType ?? d?.document_type;
      const categoryId = type?.logbook_category_id ?? d?.logbook_category_id ?? null;
      const ownName = type?.document_name ?? d?.document_name ?? '';
      if (!ownName && categoryId === null) return '';
      return resolveLogbookLabel(categoryId, ownName, categoryNameById);
    })
    .filter(Boolean);

/**
 * Logbook display labels for every certificate line item on a request
 * row. Same convention as getDocumentLogbookLabels() above — see that
 * function's docblock.
 */
export const getCertificationLogbookLabels = (row, categoryNameById) =>
  (Array.isArray(row?.certificates) ? row.certificates : [])
    .map((c) => {
      const type = c?.certificationType ?? c?.certification_type;
      const categoryId = type?.logbook_category_id ?? c?.logbook_category_id ?? null;
      const ownName = type?.certificate_name ?? c?.certificate_name ?? c?.name ?? '';
      if (!ownName && categoryId === null) return '';
      return resolveLogbookLabel(categoryId, ownName, categoryNameById);
    })
    .filter(Boolean);

/**
 * Find the specific document or certificate item inside a request row whose
 * resolved logbook label equals the target (case-insensitive, exact). Matching
 * is on the label only (category name, else the type's own name), never on a
 * substring of the name, so unrelated types cannot be mistaken for a category.
 */
export const findMatchingItem = (row, categoryOrTitle, categoryNameById) => {
  if (!row || !categoryOrTitle) return null;
  const target = String(categoryOrTitle).trim().toLowerCase();
  if (!target || target === 'all document' || target === 'all certification') return null;

  const labelOf = (categoryId, ownName) =>
    resolveLogbookLabel(categoryId, ownName, categoryNameById).toLowerCase();

  if (Array.isArray(row.documents)) {
    const doc = row.documents.find((d) => {
      const type = d?.documentType ?? d?.document_type;
      const categoryId = type?.logbook_category_id ?? d?.logbook_category_id ?? null;
      const ownName = type?.document_name ?? d?.document_name ?? d?.name ?? '';
      return labelOf(categoryId, ownName) === target;
    });
    if (doc) return doc;
  }

  if (Array.isArray(row.certificates)) {
    const cert = row.certificates.find((c) => {
      const type = c?.certificationType ?? c?.certification_type;
      const categoryId = type?.logbook_category_id ?? c?.logbook_category_id ?? null;
      const ownName = type?.certificate_name ?? c?.certificate_name ?? c?.name ?? '';
      return labelOf(categoryId, ownName) === target;
    });
    if (cert) return cert;
  }

  return null;
};

/**
 * Whether a row has a document (or certificate) line logged under `label`.
 * Used to group rows into sections for the DOCX export.
 */
export const rowHasLabel = (labels, label) => {
  const target = String(label ?? '').trim().toLowerCase();
  return labels.some((l) => String(l).trim().toLowerCase() === target);
};