<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/*
|--------------------------------------------------------------------------
| FreeIssuanceEligibilitySeeder — FESPEC-0008 Phase 9 (Rollout)
|--------------------------------------------------------------------------
| Flips the two Phase 1 columns (is_free_eligible, free_issuance_limit) on
| the three real rows the First Copy Free Issuance for Graduates Policy
| and the base Free Documents/Certificates Request Policy actually cover:
|
|   - document_type_id    15  Transcript of Records            limit 1
|   - certificate_type_id  6  Certificate of  Graduation        limit 1
|   - document_type_id    17  Request for Leave of Absences     limit NULL (unlimited)
|
| IDs confirmed against this codebase's own DatabaseSeeder — not the
| original FESPEC narrative, which described these as one flat
| "document_type" grouping before the real schema split TOR (document_type)
| and COG (certificate_type) into two different tables. See
| FreeRequestEligibilityService's docblock for how these two columns
| drive every rule (opt-in gate, graduate-scoping, and the limit count) —
| no code change is needed for this data to take effect.
|
| ── Why this is NOT in DatabaseSeeder::run() ──────────────────────────
| Every other private seedX() method in DatabaseSeeder is baseline
| reference data — safe, and intended, to re-apply on every
| `migrate:fresh --seed`. This is different: it is the deliberate,
| one-time act of turning the Free Request feature live in a given
| environment (see the Phase 1 migration's docblock: "belongs behind
| the feature flag, not baked into schema migrations"). Auto-wiring it
| into the default seed run would silently activate the feature on
| every fresh install/staging reset the moment this file merges,
| skipping the staging validation and sign-off Phase 9 actually calls
| for. Run it explicitly, on purpose, when you are ready:
|
|   php artisan db:seed --class=FreeIssuanceEligibilitySeeder
|
| Idempotent (updateOrInsert-style targeted update) — safe to re-run.
|
| ── ASSUMPTION FLAGGED FOR CONFIRMATION ───────────────────────────────
| document_type_id 15 is the plain/base "Transcript of Records" row.
| The TOR split (2026_08_29_000003) fans this same document_name out
| into 10 additional variant rows (ids 30-39) for different
| program/copy-count combinations, all sharing logbook_category_id =
| LOGBOOK_TOR_ID. This seeder only flips id 15. If the graduate-facing
| "first free TOR" is meant to resolve to one of the specific variant
| rows instead (e.g. a graduate/2nd-copy variant such as 34-39), that
| is a real product decision this seeder does not make on your behalf
| — confirm which row(s) before relying on this in production, and
| extend the $targets list below if more than one row needs the flag.
|--------------------------------------------------------------------------
*/

class FreeIssuanceEligibilitySeeder extends Seeder
{
    /**
     * table => [primary key column, [ [where-value, is_free_eligible, free_issuance_limit], ... ] ]
     */
    private const TARGETS = [
        'document_type' => [
            'key' => 'document_type_id',
            'rows' => [
                // Transcript of Records (Graduates) — First Copy Free Issuance Policy §3.1: one-time, lifetime.
                ['id' => 15, 'limit' => 1],
                // Leave of Absence — base Free Documents/Certificates Request Policy: unlimited, no cap.
                ['id' => 17, 'limit' => null],
            ],
        ],
        'certificate_type' => [
            'key' => 'certificate_type_id',
            'rows' => [
                // Certificate of Graduation — First Copy Free Issuance Policy §3.1: one-time, lifetime.
                ['id' => 6, 'limit' => 1],
            ],
        ],
    ];

    public function run(): void
    {
        foreach (self::TARGETS as $table => $spec) {
            foreach ($spec['rows'] as $row) {
                $exists = DB::table($table)->where($spec['key'], $row['id'])->exists();

                if (!$exists) {
                    // Fail loudly rather than silently no-op — an ID mismatch here
                    // (e.g. against a database seeded from a different fixture set)
                    // must not result in a "successful" run that flipped nothing.
                    Log::channel('free_requests')->error(
                        "FreeIssuanceEligibilitySeeder: {$table}.{$spec['key']} = {$row['id']} not found — skipped.",
                    );

                    $this->command?->error(
                        "  ✗ {$table}.{$spec['key']} = {$row['id']} not found. Skipped — verify seeded IDs before re-running."
                    );

                    continue;
                }

                // Note: document_type/certificate_type are both declared
                // `public $timestamps = false` on their models (no
                // updated_at column exists on either table) — do not add
                // an updated_at write here, it will fail on every driver,
                // not just SQLite.
                DB::table($table)
                    ->where($spec['key'], $row['id'])
                    ->update([
                        'is_free_eligible'     => true,
                        'free_issuance_limit'  => $row['limit'],
                    ]);

                $limitLabel = $row['limit'] === null ? 'unlimited' : (string) $row['limit'];

                Log::channel('free_requests')->info(
                    "FreeIssuanceEligibilitySeeder: {$table}.{$spec['key']} = {$row['id']} set is_free_eligible=true, free_issuance_limit={$limitLabel}."
                );

                $this->command?->info(
                    "  ✓ {$table}.{$spec['key']} = {$row['id']} → is_free_eligible=true, free_issuance_limit={$limitLabel}"
                );
            }
        }
    }
}