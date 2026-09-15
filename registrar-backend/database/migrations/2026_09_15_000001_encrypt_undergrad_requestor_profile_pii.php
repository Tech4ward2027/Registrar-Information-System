<?php

use App\Support\EncryptedPayload;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Undergrad Requestor Registration — Phase 6.
 *
 * Turns on column-level encryption-at-rest for the four self-declared
 * PII columns on undergrad_requestor_profiles that nothing queries on:
 *
 *   date_of_birth, present_address, reason_for_non_enrollment, phone
 *
 * Deliberately NOT encrypted, and why:
 *
 *   email (on users)  — unique constraint + the email-match that is the
 *                       entire provisioning mechanism (D4). Encrypting
 *                       it would break both.
 *   student_number    — indexed; both advisory checks (D6) and the
 *                       duplicate-submission flag look it up.
 *   first/last name   — the Admin queue's search filter.
 *   program, last_school_year_attended — low-sensitivity, and shown in
 *                       list views where a per-row decrypt is pure cost.
 *
 * See App\Support\EncryptedPayload's docblock for why this table gets
 * treatment that student_profile does not, and for the APP_KEY
 * operational warning that comes with it.
 *
 * ── Two things happen here, in order ─────────────────────────────────
 *
 * 1. Column widening. Ciphertext for even a short value is ~250
 *    characters, so `date_of_birth DATE` and `phone VARCHAR(20)` can no
 *    longer hold their own values. Both become TEXT.
 *    present_address / reason_for_non_enrollment are already TEXT and
 *    are left alone.
 *
 *    MySQL gets a raw MODIFY; SQLite is a no-op, matching this repo's
 *    established pattern for type-changing migrations (see
 *    2026_08_03_000000_add_pending_activation_status_and_nullable_password.php).
 *    SQLite needs no change because its column types are advisory —
 *    declared affinities, not enforced constraints — so a long string
 *    stores fine in a column declared DATE. That keeps the in-memory
 *    SQLite test database working without a table rebuild.
 *
 * 2. Backfill. Any row written before this migration is plaintext.
 *    Each is encrypted in place. Idempotent in the strongest sense:
 *    EncryptedPayload::looksEncrypted() means re-running this migration,
 *    or running it against a half-processed table after an interruption,
 *    skips rows that are already done rather than double-wrapping them.
 *
 *    Written through the query builder, not the Eloquent model, on
 *    purpose — the model's casts would encrypt on write a second time.
 *
 * Safe to run against an empty table (the expected case: this feature
 * is not live yet), which is exactly why now is the cheapest possible
 * moment to do it.
 *
 * If config('undergrad_requestor.pii_encryption.enabled') is false the
 * columns are still widened but no backfill happens — the schema change
 * is forward-compatible either way, so flipping the flag on later needs
 * only `php artisan undergrad-requestors:encrypt-existing-pii`-style
 * re-run of this same helper, or simply re-saving the affected models.
 */
return new class extends Migration
{
    /**
     * The columns this migration owns. Kept in one place so up(), down()
     * and the backfill can never drift apart.
     */
    private const ENCRYPTED_COLUMNS = [
        'date_of_birth',
        'present_address',
        'reason_for_non_enrollment',
        'phone',
    ];

    public function up(): void
    {
        if (!Schema::hasTable('undergrad_requestor_profiles')) {
            return;
        }

        if (DB::getDriverName() === 'mysql') {
            // NOT NULL / NULL preserved exactly as the original
            // create-table migration declared them — a MODIFY that
            // omitted NOT NULL would silently relax the constraint.
            DB::statement('ALTER TABLE undergrad_requestor_profiles MODIFY date_of_birth TEXT NOT NULL');
            DB::statement('ALTER TABLE undergrad_requestor_profiles MODIFY phone TEXT NOT NULL');
        }

        if (!EncryptedPayload::enabled()) {
            return;
        }

        $this->transform(fn (?string $value) => EncryptedPayload::encode($value));
    }

    public function down(): void
    {
        if (!Schema::hasTable('undergrad_requestor_profiles')) {
            return;
        }

        // Decrypt BEFORE narrowing the columns back — reversing the
        // order would truncate ciphertext into unrecoverable garbage.
        $this->transform(fn (?string $value) => EncryptedPayload::decode($value));

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE undergrad_requestor_profiles MODIFY date_of_birth DATE NOT NULL');
            DB::statement('ALTER TABLE undergrad_requestor_profiles MODIFY phone VARCHAR(20) NOT NULL');
        }
    }

    /**
     * Apply $transform to every encrypted column of every row, in
     * chunks, skipping values the transform leaves unchanged.
     *
     * chunkById (not chunk) so a row updated mid-sweep cannot shift the
     * offset and cause another row to be skipped.
     */
    private function transform(callable $transform): void
    {
        DB::table('undergrad_requestor_profiles')
            ->orderBy('undergrad_requestor_profile_id')
            ->chunkById(200, function ($rows) use ($transform) {
                foreach ($rows as $row) {
                    $updates = [];

                    foreach (self::ENCRYPTED_COLUMNS as $column) {
                        $current = $row->{$column} ?? null;

                        if ($current === null || $current === '') {
                            continue;
                        }

                        $next = $transform((string) $current);

                        if ($next !== $current) {
                            $updates[$column] = $next;
                        }
                    }

                    if ($updates === []) {
                        continue;
                    }

                    // The column carries ON UPDATE CURRENT_TIMESTAMP, so
                    // a bare update would re-stamp updated_at and make an
                    // infrastructure migration look like someone edited
                    // the submission. Pin it back to its existing value:
                    // this table's timestamps are read by the Admin queue
                    // and by the D9 retention sweeps, and neither should
                    // shift because of an encryption rollout.
                    if (property_exists($row, 'updated_at')) {
                        $updates['updated_at'] = $row->updated_at;
                    }

                    DB::table('undergrad_requestor_profiles')
                        ->where('undergrad_requestor_profile_id', $row->undergrad_requestor_profile_id)
                        ->update($updates);
                }
            }, 'undergrad_requestor_profile_id');
    }
};
