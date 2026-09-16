<?php

use App\Models\UndergradRequestorProfile;
use App\Support\EncryptedPayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/**
 * Undergrad Requestor Registration — Phase 7.
 *
 * Every migration in this feature's batch documents itself as
 * "IDEMPOTENT: guarded by Schema::hasTable()/hasColumn()" — this file
 * is what actually proves that promise instead of trusting the
 * docblocks.
 *
 * RefreshDatabase has already run every migration in the app exactly
 * once by the time each test starts. Re-including each Undergrad
 * Requestor migration file and calling ->up() again therefore exercises
 * precisely the guard every docblock claims exists, against a database
 * already in the fully-migrated end state — the same situation
 * `php artisan migrate` would face if re-run after a partial deploy
 * failure, or against a staging clone whose migrations table is out of
 * sync with its actual schema. If any guard is missing or wrong, this
 * throws (a duplicate-column/duplicate-table SQL error) rather than
 * silently passing.
 */
const UNDERGRAD_REQUESTOR_MIGRATION_FILES = [
    '2026_09_14_000000_add_undergrad_requestor_role.php',
    '2026_09_14_000001_add_pending_verification_and_rejected_status_to_users.php',
    '2026_09_14_000002_create_undergrad_requestor_profiles_table.php',
    '2026_09_14_000003_create_undergrad_requestor_verifications_table.php',
    '2026_09_14_000004_add_email_verification_columns_to_undergrad_requestor_profiles.php',
    '2026_09_15_000000_add_pii_purged_at_to_undergrad_requestor_verifications.php',
    '2026_09_15_000001_encrypt_undergrad_requestor_profile_pii.php',
    '2026_09_15_000002_add_data_privacy_consent_to_undergrad_requestor_profiles.php',
];

function loadUndergradRequestorMigration(string $filename): object
{
    $path = database_path("migrations/{$filename}");

    expect(file_exists($path))->toBeTrue("Missing migration file: {$filename}");

    // Each file `return new class extends Migration {...}` — include()
    // evaluates that return, giving a fresh instance every call (as
    // opposed to require_once, which would give a fatal
    // "cannot declare anonymous class twice" on the second load).
    return include $path;
}

test('every migration file in the batch exists and returns a Migration instance', function () {
    foreach (UNDERGRAD_REQUESTOR_MIGRATION_FILES as $file) {
        expect(loadUndergradRequestorMigration($file))
            ->toBeInstanceOf(\Illuminate\Database\Migrations\Migration::class);
    }
});

test('re-running up() on every migration against an already-migrated schema throws nothing', function () {
    foreach (UNDERGRAD_REQUESTOR_MIGRATION_FILES as $file) {
        loadUndergradRequestorMigration($file)->up();
    }

    // The schema is still exactly what the first (real) run produced —
    // a broken guard swallowing a caught exception into a half-applied
    // state would show up here as a missing table/column rather than
    // as a visible test failure at the up() call site above.
    expect(Schema::hasTable('undergrad_requestor_profiles'))->toBeTrue();
    expect(Schema::hasTable('undergrad_requestor_verifications'))->toBeTrue();

    expect(Schema::hasColumn('users', 'status'))->toBeTrue();

    expect(Schema::hasColumn('undergrad_requestor_profiles', 'email_verification_token_hash'))->toBeTrue();
    expect(Schema::hasColumn('undergrad_requestor_profiles', 'email_verification_expires_at'))->toBeTrue();
    expect(Schema::hasColumn('undergrad_requestor_profiles', 'data_privacy_consent_at'))->toBeTrue();
    expect(Schema::hasColumn('undergrad_requestor_profiles', 'data_privacy_consent_version'))->toBeTrue();
    expect(Schema::hasColumn('undergrad_requestor_profiles', 'data_privacy_consent_ip'))->toBeTrue();

    expect(Schema::hasColumn('undergrad_requestor_verifications', 'pii_purged_at'))->toBeTrue();

    expect(DB::table('roles')->where('role_id', 5)->where('role_name', 'undergrad_requestor')->exists())
        ->toBeTrue();
});

test('re-running up() twice in a row is also a no-op the third time', function () {
    // Guards belt-and-suspenders: once is the realistic ops scenario,
    // but a genuinely correct hasTable()/hasColumn() guard has no
    // reason to behave differently on a third pass than a second one.
    foreach (UNDERGRAD_REQUESTOR_MIGRATION_FILES as $file) {
        $migration = loadUndergradRequestorMigration($file);
        $migration->up();
        $migration->up();
    }

    expect(Schema::hasTable('undergrad_requestor_profiles'))->toBeTrue();
})->throwsNoExceptions();

test('the encryption backfill migration does not double-encrypt an already-encrypted row on re-run', function () {
    config()->set('undergrad_requestor.pii_encryption.enabled', true);

    $profile = UndergradRequestorProfile::factory()->create([
        'phone'           => '09171234567',
        'present_address' => '123 Sample St., Taguig City',
    ]);

    // The factory writes through the model, so the casts have already
    // encrypted these columns — read the raw stored bytes directly via
    // the query builder (bypassing the model's own decrypting cast) to
    // get the real ciphertext, the same way the migration's backfill
    // itself reads.
    $rawAfterFirstSave = DB::table('undergrad_requestor_profiles')
        ->where('undergrad_requestor_profile_id', $profile->undergrad_requestor_profile_id)
        ->first();

    expect(EncryptedPayload::looksEncrypted($rawAfterFirstSave->phone))->toBeTrue();

    // Re-run the backfill migration's up() — this is the exact scenario
    // its docblock promises is safe: "re-running this migration, or
    // running it against a half-processed table after an interruption,
    // skips rows that are already done rather than double-wrapping
    // them."
    loadUndergradRequestorMigration('2026_09_15_000001_encrypt_undergrad_requestor_profile_pii.php')->up();

    $rawAfterRerun = DB::table('undergrad_requestor_profiles')
        ->where('undergrad_requestor_profile_id', $profile->undergrad_requestor_profile_id)
        ->first();

    // Byte-for-byte identical ciphertext, not just "still decryptable".
    // looksEncrypted() is what stops encode() from wrapping an
    // already-encrypted value a second time — asserting exact equality
    // here is what actually proves that guard fired, rather than merely
    // proving the value still happens to decrypt correctly.
    expect($rawAfterRerun->phone)->toBe($rawAfterFirstSave->phone);
    expect($rawAfterRerun->present_address)->toBe($rawAfterFirstSave->present_address);

    // And the value the application actually reads is still correct.
    expect($profile->fresh()->phone)->toBe('09171234567');
    expect($profile->fresh()->present_address)->toBe('123 Sample St., Taguig City');
});

test('the backfill migration is a safe no-op against an empty table', function () {
    expect(UndergradRequestorProfile::count())->toBe(0);

    loadUndergradRequestorMigration('2026_09_15_000001_encrypt_undergrad_requestor_profile_pii.php')->up();
})->throwsNoExceptions();
