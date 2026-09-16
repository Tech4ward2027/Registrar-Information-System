<?php

use App\Casts\EncryptedDate;
use App\Casts\EncryptedText;
use App\Models\UndergradRequestorProfile;
use Carbon\Carbon;

/**
 * Undergrad Requestor Registration — Phase 7.
 *
 * EncryptedPayloadTest.php covers the underlying encrypt/decrypt
 * primitive in isolation. This file covers the thin Eloquent-cast layer
 * on top of it — get()/set() — including EncryptedDate's extra
 * responsibility of handing back a Carbon instance so existing callers
 * (e.g. UndergradRequestorVerificationDetailResource's
 * ->date_of_birth?->toDateString()) keep working unchanged.
 *
 * CastsAttributes::get()/set() both take a Model as their first
 * argument but neither cast actually reads from it — the model
 * parameter exists purely to satisfy Laravel's interface — so an
 * unsaved model instance is a valid, DB-free stand-in here. Confirmed
 * against both cast classes' source before relying on it.
 */

beforeEach(function () {
    config()->set('undergrad_requestor.pii_encryption.enabled', true);
});

// ═════════════════════════════════════════════════════════════════════
// EncryptedText
// ═════════════════════════════════════════════════════════════════════

test('EncryptedText set() encrypts, get() decrypts back to the original string', function () {
    $cast  = new EncryptedText();
    $model = new UndergradRequestorProfile();

    $stored = $cast->set($model, 'present_address', '123 Sample St., Taguig City', []);

    expect($stored)->toBeArray();
    expect($stored['present_address'])->not->toBe('123 Sample St., Taguig City');

    $retrieved = $cast->get($model, 'present_address', $stored['present_address'], []);

    expect($retrieved)->toBe('123 Sample St., Taguig City');
});

test('EncryptedText passes null through set() and get() unchanged', function () {
    $cast  = new EncryptedText();
    $model = new UndergradRequestorProfile();

    $stored = $cast->set($model, 'reason_for_non_enrollment', null, []);
    expect($stored['reason_for_non_enrollment'])->toBeNull();

    expect($cast->get($model, 'reason_for_non_enrollment', null, []))->toBeNull();
});

test('EncryptedText get() tolerates a legacy plaintext value already in the column', function () {
    $cast  = new EncryptedText();
    $model = new UndergradRequestorProfile();

    // A row written before Phase 6's backfill ran.
    expect($cast->get($model, 'phone', '09171234567', []))->toBe('09171234567');
});

// ═════════════════════════════════════════════════════════════════════
// EncryptedDate
// ═════════════════════════════════════════════════════════════════════

test('EncryptedDate set() then get() round-trips to an equal Carbon date', function () {
    $cast  = new EncryptedDate();
    $model = new UndergradRequestorProfile();

    $stored = $cast->set($model, 'date_of_birth', '2000-05-15', []);
    expect($stored['date_of_birth'])->not->toContain('2000-05-15'); // ciphertext, not plaintext

    $retrieved = $cast->get($model, 'date_of_birth', $stored['date_of_birth'], []);

    expect($retrieved)->toBeInstanceOf(Carbon::class);
    expect($retrieved->toDateString())->toBe('2000-05-15');
});

test('EncryptedDate normalises whatever date shape it is given before encrypting', function () {
    $cast  = new EncryptedDate();
    $model = new UndergradRequestorProfile();

    $fromCarbon = $cast->set($model, 'date_of_birth', Carbon::parse('2000-05-15'), []);
    $fromString = $cast->set($model, 'date_of_birth', '2000-05-15', []);

    expect($cast->get($model, 'date_of_birth', $fromCarbon['date_of_birth'], [])->toDateString())
        ->toBe($cast->get($model, 'date_of_birth', $fromString['date_of_birth'], [])->toDateString());
});

test('EncryptedDate set() stores null for both null and empty-string input', function () {
    $cast  = new EncryptedDate();
    $model = new UndergradRequestorProfile();

    expect($cast->set($model, 'date_of_birth', null, [])['date_of_birth'])->toBeNull();
    expect($cast->set($model, 'date_of_birth', '', [])['date_of_birth'])->toBeNull();
});

test('EncryptedDate get() returns null instead of throwing on a damaged payload', function () {
    $cast  = new EncryptedDate();
    $model = new UndergradRequestorProfile();

    // Simulates a value that decrypted to garbage (e.g. a wrong-key
    // decrypt that EncryptedPayload::decode() already logged and
    // returned verbatim) reaching Carbon::parse().
    expect($cast->get($model, 'date_of_birth', 'not-a-parseable-date-#$%', []))->toBeNull();
});

test('EncryptedDate get() tolerates a legacy plaintext date already in the column', function () {
    $cast  = new EncryptedDate();
    $model = new UndergradRequestorProfile();

    $retrieved = $cast->get($model, 'date_of_birth', '2000-05-15', []);

    expect($retrieved)->toBeInstanceOf(Carbon::class);
    expect($retrieved->toDateString())->toBe('2000-05-15');
});

// Full save()/refresh() round-trip through a real, persisted model —
// exercising Eloquent's cast pipeline end-to-end rather than calling
// get()/set() directly — is DB-backed integration coverage, which
// UndergradRequestorSecurityTest.php's "self-declared PII is encrypted
// in the database but reads back as plaintext" already provides. Not
// duplicated here to keep this file DB-free.
