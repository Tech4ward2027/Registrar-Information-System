/**
 * representativeHelpers.js
 * ------------------------
 * Pure helpers for the Notification Policy §3.6 screens (deceased or
 * incapacitated requestor and their authorized representative).
 *
 * Everything here mirrors the backend and is only a convenience: the server
 * validates every rule again (StoreRepresentativeRequest, policies), so a
 * bypassed check here can never widen access.
 */

// Mirrors RequestRepresentative::RELATIONSHIPS.
export const RELATIONSHIP_OPTIONS = [
  { value: 'parent', label: 'Parent' },
  { value: 'child', label: 'Child' },
  { value: 'spouse', label: 'Spouse' },
  { value: 'sibling', label: 'Sibling' },
  { value: 'legal_guardian', label: 'Legal guardian' },
  { value: 'court_appointed', label: 'Court-appointed representative' },
  { value: 'other_authorized', label: 'Other authorized person' },
];

// Mirrors RequestRepresentative::REQUIRED_PROOFS. `kind` is the multipart
// field name the API expects.
export const PROOF_KINDS = [
  { kind: 'representative_id', label: "Representative's valid ID" },
  { kind: 'relationship_proof', label: 'Proof of relationship or authority (e.g. birth certificate)' },
  { kind: 'event_proof', label: 'Death certificate or proof of incapacity' },
];

export const FLAG_STATUS_OPTIONS = [
  { value: 'deceased', label: 'Deceased' },
  { value: 'incapacitated', label: 'Permanently incapacitated' },
];

// Mirrors notification_mail.representative.max_proof_kb (default 5120 KB).
export const MAX_PROOF_BYTES = 5 * 1024 * 1024;
const ALLOWED_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png'];
const ALLOWED_MIME = ['application/pdf', 'image/jpeg', 'image/png'];

export const formatBytes = (bytes) => {
  const n = Number(bytes);
  if (!Number.isFinite(n) || n < 0) return '';
  if (n < 1024) return `${n} B`;
  if (n < 1024 * 1024) return `${(n / 1024).toFixed(0)} KB`;
  return `${(n / (1024 * 1024)).toFixed(1)} MB`;
};

/** @returns {string|null} an error message, or null when the file is acceptable. */
export const validateProofFile = (file) => {
  if (!file) return 'Please choose a file.';
  const ext = String(file.name ?? '').split('.').pop().toLowerCase();
  if (!ALLOWED_EXTENSIONS.includes(ext) || (file.type && !ALLOWED_MIME.includes(file.type))) {
    return 'Only PDF, JPG or PNG files are accepted.';
  }
  if (file.size === 0) return 'The file is empty.';
  if (file.size > MAX_PROOF_BYTES) {
    return `File is too large (${formatBytes(file.size)}). The limit is ${formatBytes(MAX_PROOF_BYTES)}.`;
  }
  return null;
};

const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

/**
 * Validates the add-representative form.
 * @param {{fullName:string,email:string,relationship:string,files:Object<string,File|null>}} form
 * @returns {Object<string,string>} field -> message; empty when valid.
 */
export const validateRepresentativeForm = (form) => {
  const errors = {};
  const name = (form.fullName ?? '').trim();
  const email = (form.email ?? '').trim();

  if (name.length < 2) errors.fullName = 'Enter the representative\u2019s full name.';
  else if (name.length > 150) errors.fullName = 'Name is too long (150 characters maximum).';

  if (!EMAIL_RE.test(email) || email.length > 100) errors.email = 'Enter a valid email address.';

  if (!RELATIONSHIP_OPTIONS.some((o) => o.value === form.relationship)) {
    errors.relationship = 'Choose the relationship.';
  }

  for (const { kind } of PROOF_KINDS) {
    const problem = validateProofFile(form.files?.[kind]);
    if (problem) errors[kind] = problem;
  }

  return errors;
};

/** Builds the multipart body for POST /document-requests/{id}/representative. */
export const buildRepresentativeFormData = (form) => {
  const body = new FormData();
  body.append('full_name', form.fullName.trim());
  body.append('email', form.email.trim());
  body.append('relationship', form.relationship);
  for (const { kind } of PROOF_KINDS) body.append(kind, form.files[kind]);
  return body;
};

/** A representative that is still live (pending or verified) occupies the single slot. */
export const hasLiveRepresentative = (representative) =>
  Boolean(representative) && representative.status !== 'revoked';

export const representativeStatusLabel = (status) => ({
  pending: 'Pending verification',
  verified: 'Verified',
  revoked: 'Revoked',
}[status] ?? 'Unknown');

export const relationshipLabel = (value) =>
  RELATIONSHIP_OPTIONS.find((o) => o.value === value)?.label ?? value ?? '';

/** The first useful message from a Laravel error response. */
export const parseApiError = (err, fallback = 'Something went wrong. Please try again.') => {
  if (err?.rateLimited) {
    return err.retryAfterSeconds
      ? `Too many attempts. Try again in ${err.retryAfterSeconds} seconds.`
      : 'Too many attempts. Please wait a moment and try again.';
  }
  const data = err?.response?.data;
  const first = Object.values(data?.errors ?? {}).flat()[0];
  return first || data?.message || fallback;
};
