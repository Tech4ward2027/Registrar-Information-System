/**
 * Unit tests for logbookHelpers.js — Node's built-in test runner, no extra
 * dependency. Run from the registrar-frontend folder:
 *
 *   npm run test:unit
 *
 * Only deterministic helpers are covered. The date formatters depend on the
 * machine's time zone and are deliberately not asserted here.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';

import {
  formatMinutesDuration,
  getCertificationLogbookLabels,
  getClaimedAt,
  getCourse,
  getDocumentLogbookLabels,
  getEmail,
  getGender,
  getProcessedAt,
  getProcessing,
  getProcessingMinutes,
  findMatchingItem,
  resolveLogbookLabel,
  rowHasLabel,
} from './logbookHelpers.js';

const categories = new Map([
  [1, 'Transcript of Records'],
  [2, 'Certified True Copy of Records'],
]);

const doc = (id, name, categoryId = null) => ({
  request_document_id: id,
  documentType: { document_name: name, logbook_category_id: categoryId },
});

const cert = (id, name, categoryId = null) => ({
  request_certificate_id: id,
  certificationType: { certificate_name: name, logbook_category_id: categoryId },
});

// ── formatMinutesDuration ────────────────────────────────────────────────────

test('formatMinutesDuration formats minutes, hours and days', () => {
  assert.equal(formatMinutesDuration(0), '0mins');
  assert.equal(formatMinutesDuration(1), '1min');
  assert.equal(formatMinutesDuration(45), '45mins');
  assert.equal(formatMinutesDuration(60), '1hr 0mins');
  assert.equal(formatMinutesDuration(135), '2hrs 15mins');
  assert.equal(formatMinutesDuration(1440), '1day 0hrs 0mins');
  assert.equal(formatMinutesDuration(2 * 1440 + 3 * 60 + 5), '2days 3hrs 5mins');
});

test('formatMinutesDuration shows --- for anything that is not a usable duration', () => {
  for (const value of [null, undefined, '', 'abc', -5]) {
    assert.equal(formatMinutesDuration(value), '---');
  }
});

// ── resolveLogbookLabel ──────────────────────────────────────────────────────

test('resolveLogbookLabel uses the category name when the type has one', () => {
  assert.equal(resolveLogbookLabel(1, 'Transcript of Records (Regular)', categories), 'Transcript of Records');
});

test('resolveLogbookLabel falls back to the type\'s own name, never a substring rule', () => {
  // These names contain "tor" / "ctc" / "completion" but have no category.
  assert.equal(resolveLogbookLabel(null, 'Doctor Visit Form', categories), 'Doctor Visit Form');
  assert.equal(resolveLogbookLabel(null, 'Factor Analysis', categories), 'Factor Analysis');
  assert.equal(resolveLogbookLabel(undefined, 'Tractor Permit', categories), 'Tractor Permit');
  assert.equal(resolveLogbookLabel('', 'Victory Certificate', categories), 'Victory Certificate');
});

test('resolveLogbookLabel handles unknown ids and blank names', () => {
  assert.equal(resolveLogbookLabel(99, 'Diploma', categories), 'Diploma');
  assert.equal(resolveLogbookLabel('abc', 'Diploma', categories), 'Diploma');
  assert.equal(resolveLogbookLabel(null, '   ', categories), 'Unspecified');
  assert.equal(resolveLogbookLabel(null, undefined, undefined), 'Unspecified');
});

// ── label lists ──────────────────────────────────────────────────────────────

test('getDocumentLogbookLabels and getCertificationLogbookLabels resolve each line', () => {
  const row = {
    documents: [doc(1, 'Transcript of Records (Regular)', 1), doc(2, 'Diploma')],
    certificates: [cert(5, 'Certificate of Graduation')],
  };

  assert.deepEqual(getDocumentLogbookLabels(row, categories), ['Transcript of Records', 'Diploma']);
  assert.deepEqual(getCertificationLogbookLabels(row, categories), ['Certificate of Graduation']);
  assert.deepEqual(getDocumentLogbookLabels({}, categories), []);
});

test('rowHasLabel compares labels case-insensitively and exactly', () => {
  assert.equal(rowHasLabel(['Diploma', 'Good Moral'], 'diploma'), true);
  assert.equal(rowHasLabel(['Doctor Visit Form'], 'Transcript of Records'), false);
  assert.equal(rowHasLabel([], 'Diploma'), false);
});

// ── findMatchingItem ─────────────────────────────────────────────────────────

test('findMatchingItem matches a line by its resolved label', () => {
  const regular = doc(1, 'Transcript of Records (Regular)', 1);
  const row = { documents: [doc(2, 'Diploma'), regular] };

  assert.equal(findMatchingItem(row, 'Transcript of Records', categories), regular);
  assert.equal(findMatchingItem(row, 'transcript of records', categories), regular);
});

test('findMatchingItem never matches by a substring of the name', () => {
  const row = { documents: [doc(1, 'Doctor Visit Form'), doc(2, 'Factor Analysis')] };

  assert.equal(findMatchingItem(row, 'Transcript of Records', categories), null);
  assert.equal(findMatchingItem(row, 'Certified True Copy of Records', categories), null);
});

test('findMatchingItem finds certificates and ignores the All sentinels', () => {
  const graduation = cert(7, 'Certificate of Graduation');
  const row = { documents: [], certificates: [graduation] };

  assert.equal(findMatchingItem(row, 'Certificate of Graduation', categories), graduation);
  assert.equal(findMatchingItem(row, 'All Document', categories), null);
  assert.equal(findMatchingItem(row, 'All Certification', categories), null);
  assert.equal(findMatchingItem(null, 'Diploma', categories), null);
  assert.equal(findMatchingItem(row, '', categories), null);
});

// ── processing figures (read from the server payload) ────────────────────────

const processedRow = {
  processing: { minutes: 90, processed_at: '2026-03-10T05:30:00Z', end_status_id: 6, claimed_at: '2026-03-12T02:00:00Z' },
  items_processing: [
    { type: 'document', id: 11, minutes: 30, processed_at: '2026-03-10T03:00:00Z', end_status_id: 6, claimed_at: '2026-03-12T02:00:00Z' },
    { type: 'certificate', id: 11, minutes: 75, processed_at: '2026-03-10T04:45:00Z', end_status_id: 2, claimed_at: null },
  ],
};

test('getProcessing returns the whole-request figures when no item is given', () => {
  assert.equal(getProcessingMinutes(processedRow), 90);
  assert.equal(getProcessedAt(processedRow), '2026-03-10T05:30:00Z');
  assert.equal(getClaimedAt(processedRow), '2026-03-12T02:00:00Z');
});

test('getProcessing returns the figures of the matching document line', () => {
  const item = { request_document_id: 11 };

  assert.equal(getProcessingMinutes(processedRow, item), 30);
  assert.equal(getProcessedAt(processedRow, item), '2026-03-10T03:00:00Z');
});

test('getProcessing tells a document and a certificate with the same id apart', () => {
  const item = { request_certificate_id: 11 };

  assert.equal(getProcessingMinutes(processedRow, item), 75);
  assert.equal(getClaimedAt(processedRow, item), null);
});

test('getProcessing falls back to the whole request when the line has no entry', () => {
  assert.equal(getProcessingMinutes(processedRow, { request_document_id: 999 }), 90);
  assert.equal(getProcessingMinutes({ processing: processedRow.processing }, { request_document_id: 11 }), 90);
});

test('an unmeasurable record stays null instead of becoming a guess', () => {
  const row = { processing: { minutes: null, processed_at: null, end_status_id: null, claimed_at: null }, items_processing: [] };

  assert.equal(getProcessingMinutes(row), null);
  assert.equal(formatMinutesDuration(getProcessingMinutes(row)), '---');
  assert.equal(getProcessedAt(row), null);
  assert.equal(getClaimedAt(row), null);
});

test('a row from an older backend with no processing block yields nulls, not a crash', () => {
  for (const row of [{}, null, undefined, { history: [{ business_minutes: 5 }] }]) {
    assert.equal(getProcessingMinutes(row), null);
    assert.equal(getProcessedAt(row), null);
    assert.equal(getClaimedAt(row), null);
  }
  assert.deepEqual(getProcessing(null), { minutes: null, processed_at: null, end_status_id: null, claimed_at: null });
});

test('minutes are never summed from raw history rows in the browser', () => {
  const row = {
    history: [
      { new_status_id: 2, business_minutes: 400, changed_at: '2026-03-12T00:00:00Z' },
      { new_status_id: 6, business_minutes: 300, changed_at: '2026-03-10T05:00:00Z' },
    ],
  };

  assert.equal(getProcessingMinutes(row), null);
});

// ── getEmail ─────────────────────────────────────────────────────────────────

test('getEmail prefers the server-resolved e-mail, then falls back through the profiles', () => {
  assert.equal(getEmail({ email: 'resolved@example.com', user: { email: 'user@example.com' } }), 'resolved@example.com');
  assert.equal(getEmail({ alumni_profile: { alumni: { user: { email: 'alumni@example.com' } } } }), 'alumni@example.com');
  assert.equal(getEmail({ student_profile: { user: { email: 'student@example.com' } } }), 'student@example.com');
  assert.equal(getEmail({ user: { email: 'filer@example.com' } }), 'filer@example.com');
  assert.equal(getEmail({}), '---');
  assert.equal(getEmail(null), '---');
});

// ── Course / Gender no longer read verification payloads ─────────────────────

test('getCourse and getGender ignore the removed verification lookups', () => {
  const row = {
    undergrad_requestor_verification: {
      matched_student_profile: { course: 'BSIT', sex_at_birth: 'Male', academic_records: [{ course: 'BSIT' }] },
    },
    undergradRequestorVerification: {
      matchedStudentProfile: { course: 'BSIT', sex_at_birth: 'Male', academicRecords: [{ course: 'BSIT' }] },
    },
  };

  assert.equal(getCourse(row), '---');
  assert.equal(getGender(row), '---');
});

test('getCourse and getGender still read the profiles that carry them', () => {
  assert.equal(getCourse({ course: 'BSIT' }), 'BSIT');
  assert.equal(getCourse({ undergrad_requestor_profile: { program: ' BSCS ' } }), 'BSCS');
  assert.equal(getCourse({ student_profile: { academic_records: [{ course: 'BSA' }] } }), 'BSA');
  assert.equal(getGender({ gender: 'Female' }), 'Female');
  assert.equal(getGender({ student_profile: { sex_at_birth: 'Male' } }), 'Male');
  assert.equal(getGender(null), '---');
});
