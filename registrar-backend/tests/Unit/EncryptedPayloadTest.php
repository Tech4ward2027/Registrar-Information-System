<?php

use App\Support\EncryptedPayload;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

/**
 * Undergrad Requestor Registration — Phase 7.
 *
 * EncryptedPayload is the single implementation both cast classes
 * (EncryptedText, EncryptedDate) AND the Phase 6 backfill migration
 * depend on — see its own class docblock. It touches no database, so
 * these are pure unit tests against Crypt/Log, not RefreshDatabase
 * feature tests. UndergradRequestorSecurityTest.php separately proves
 * the end-to-end behaviour through a real Eloquent model; this file
 * proves the primitive underneath it in isolation, including the
 * failure paths that are awkward to trigger through the model layer
 * (a genuine APP_KEY mismatch).
 */

beforeEach(function () {
    config()->set('undergrad_requestor.pii_encryption.enabled', true);
});

// ═════════════════════════════════════════════════════════════════════
// encode() / decode() — round trip
// ═════════════════════════════════════════════════════════════════════

test('encode then decode returns the original value unchanged', function () {
    $original = '123 Sample St., Taguig City';

    $encoded = EncryptedPayload::encode($original);

    expect($encoded)->not->toBe($original);
    expect(EncryptedPayload::decode($encoded))->toBe($original);
});

test('null passes through both directions untouched', function () {
    expect(EncryptedPayload::encode(null))->toBeNull();
    expect(EncryptedPayload::decode(null))->toBeNull();
});

test('an empty string passes through both directions untouched', function () {
    // Deliberately distinct from null: a nullable column stores NULL for
    // "no value", not an encrypted empty string, which would cost ~250
    // bytes to say nothing.
    expect(EncryptedPayload::encode(''))->toBe('');
    expect(EncryptedPayload::decode(''))->toBe('');
});

test('two encodings of the same plaintext produce different ciphertext', function () {
    // Random IV per encryption — asserting this guards against a future
    // change that accidentally makes encryption deterministic, which
    // would leak equality between two rows via ciphertext comparison
    // alone.
    $a = EncryptedPayload::encode('09171234567');
    $b = EncryptedPayload::encode('09171234567');

    expect($a)->not->toBe($b);
    expect(EncryptedPayload::decode($a))->toBe('09171234567');
    expect(EncryptedPayload::decode($b))->toBe('09171234567');
});

// ═════════════════════════════════════════════════════════════════════
// Legacy / plaintext tolerance — the entire reason this class exists
// instead of Laravel's built-in 'encrypted' cast
// ═════════════════════════════════════════════════════════════════════

test('decode tolerates a plain, never-encrypted value with no error', function () {
    expect(EncryptedPayload::decode('2000-05-15'))->toBe('2000-05-15');
    expect(EncryptedPayload::decode('plain text address, no encryption'))->toBe('plain text address, no encryption');
});

test('decode does not log a warning for an ordinary plaintext value', function () {
    Log::spy();

    EncryptedPayload::decode('just some plain text');

    Log::shouldNotHaveReceived('warning');
});

test('encode leaves the value as-is when encryption is disabled by config', function () {
    config()->set('undergrad_requestor.pii_encryption.enabled', false);

    $value = EncryptedPayload::encode('09171234567');

    expect($value)->toBe('09171234567');
});

test('re-encoding an already-encrypted value does not double-wrap it', function () {
    $once  = EncryptedPayload::encode('123 Sample St., Taguig City');
    $twice = EncryptedPayload::encode($once);

    expect($twice)->toBe($once);
    expect(EncryptedPayload::decode($twice))->toBe('123 Sample St., Taguig City');
});

// ═════════════════════════════════════════════════════════════════════
// looksEncrypted() — used by the backfill migration to skip finished rows
// ═════════════════════════════════════════════════════════════════════

test('looksEncrypted is false for plaintext and true for real ciphertext', function () {
    expect(EncryptedPayload::looksEncrypted('2000-05-15'))->toBeFalse();

    $encrypted = EncryptedPayload::encode('2000-05-15');
    expect(EncryptedPayload::looksEncrypted($encrypted))->toBeTrue();
});

test('looksEncrypted is false for a value that merely resembles the payload shape', function () {
    // Same JSON keys (iv/value/mac) as a real payload, base64-wrapped,
    // but not a value Crypt can actually decrypt — must not be
    // misclassified as "already done".
    $fakeShape = base64_encode(json_encode([
        'iv'    => 'not-a-real-iv',
        'value' => 'not-real-ciphertext',
        'mac'   => 'not-a-real-mac',
    ]));

    expect(EncryptedPayload::looksEncrypted($fakeShape))->toBeFalse();
});

// ═════════════════════════════════════════════════════════════════════
// APP_KEY-mismatch failure mode — must be loud, not silently corrupt
// ═════════════════════════════════════════════════════════════════════

test('decode returns the undecryptable value as-is and logs a warning when the payload shape is right but the key is wrong', function () {
    Log::spy();

    // Structurally a real Laravel encrypted payload, but never actually
    // produced by our Crypt instance — mimics what a genuine APP_KEY
    // rotation looks like from decode()'s point of view: right shape,
    // wrong key, DecryptException every time.
    $undecryptable = base64_encode(json_encode([
        'iv'    => base64_encode(random_bytes(16)),
        'value' => base64_encode(random_bytes(32)),
        'mac'   => hash('sha256', 'not-the-real-mac'),
    ]));

    $result = EncryptedPayload::decode($undecryptable);

    expect($result)->toBe($undecryptable);
    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message) => str_contains($message, 'APP_KEY rotation'));
});

test('encode/decode survive a full APP_KEY rotation as the documented, expected failure', function () {
    $encoded = EncryptedPayload::encode('123 Sample St., Taguig City');

    // Simulate the operational incident EncryptedPayload's docblock
    // warns about: APP_KEY changes out from under already-encrypted
    // data. Regenerating triggers Laravel to build a new encrypter on
    // next resolution — old ciphertext becomes permanently unreadable
    // with the new key, by design.
    config()->set('app.key', 'base64:' . base64_encode(random_bytes(32)));
    app()->forgetInstance('encrypter');
    Crypt::clearResolvedInstances();

    Log::spy();

    $result = EncryptedPayload::decode($encoded);

    // Documented behaviour, not a crash: the stale ciphertext comes
    // back verbatim and the incident is logged, so the Admin queue
    // keeps rendering instead of 500-ing on one bad row.
    expect($result)->toBe($encoded);
    Log::shouldHaveReceived('warning')->once();
});
