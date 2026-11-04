import test from 'node:test';
import assert from 'node:assert/strict';
import {
  validateProofFile,
  validateRepresentativeForm,
  hasLiveRepresentative,
  representativeStatusLabel,
  parseApiError,
  formatBytes,
  MAX_PROOF_BYTES,
  PROOF_KINDS,
} from './representativeHelpers.js';

const file = (name, size = 100, type = 'application/pdf') => ({ name, size, type });
const goodFiles = () => Object.fromEntries(PROOF_KINDS.map(({ kind }) => [kind, file(`${kind}.pdf`)]));
const goodForm = () => ({ fullName: 'Maria Dela Cruz', email: 'maria@example.org', relationship: 'parent', files: goodFiles() });

test('validateProofFile accepts pdf/jpg/png within the limit', () => {
  assert.equal(validateProofFile(file('a.pdf')), null);
  assert.equal(validateProofFile(file('a.JPG', 10, 'image/jpeg')), null);
  assert.equal(validateProofFile(file('a.png', 10, 'image/png')), null);
});

test('validateProofFile rejects wrong type, empty, oversize and missing files', () => {
  assert.match(validateProofFile(file('a.exe', 10, 'application/x-msdownload')), /PDF, JPG or PNG/);
  assert.match(validateProofFile(file('a.pdf', 10, 'text/html')), /PDF, JPG or PNG/);
  assert.match(validateProofFile(file('a.pdf', MAX_PROOF_BYTES + 1)), /too large/);
  assert.match(validateProofFile(file('a.pdf', 0)), /empty/);
  assert.match(validateProofFile(null), /choose a file/);
});

test('validateRepresentativeForm passes a complete form and flags every missing piece', () => {
  assert.deepEqual(validateRepresentativeForm(goodForm()), {});

  const errors = validateRepresentativeForm({ fullName: ' ', email: 'nope', relationship: 'cousin', files: {} });
  assert.ok(errors.fullName && errors.email && errors.relationship);
  for (const { kind } of PROOF_KINDS) assert.ok(errors[kind], `${kind} should be required`);
});

test('all three proofs are required individually', () => {
  const form = goodForm();
  form.files.event_proof = null;
  assert.deepEqual(Object.keys(validateRepresentativeForm(form)), ['event_proof']);
});

test('a revoked representative frees the slot; pending and verified occupy it', () => {
  assert.equal(hasLiveRepresentative(null), false);
  assert.equal(hasLiveRepresentative({ status: 'revoked' }), false);
  assert.equal(hasLiveRepresentative({ status: 'pending' }), true);
  assert.equal(hasLiveRepresentative({ status: 'verified' }), true);
});

test('labels and byte formatting', () => {
  assert.equal(representativeStatusLabel('pending'), 'Pending verification');
  assert.equal(representativeStatusLabel('???'), 'Unknown');
  assert.equal(formatBytes(512), '512 B');
  assert.equal(formatBytes(2048), '2 KB');
  assert.equal(formatBytes(5 * 1024 * 1024), '5.0 MB');
});

test('parseApiError prefers field errors, then message, then fallback; handles rate limits', () => {
  assert.equal(parseApiError({ response: { data: { errors: { email: ['Bad email.'] }, message: 'x' } } }), 'Bad email.');
  assert.equal(parseApiError({ response: { data: { message: 'Forbidden.' } } }), 'Forbidden.');
  assert.equal(parseApiError({}, 'fallback'), 'fallback');
  assert.match(parseApiError({ rateLimited: true, retryAfterSeconds: 30 }), /30 seconds/);
});
