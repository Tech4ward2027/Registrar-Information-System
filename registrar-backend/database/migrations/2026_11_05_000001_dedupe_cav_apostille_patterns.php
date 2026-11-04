<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One label, one owner: CAV / Apostille cashier patterns.
 *
 * Production has three labels claimed by BOTH the legacy catch-all d14
 * ("CAV/Apostille (DFA)") and a newer, more specific active type:
 *
 *   "CAV (CHED)"                                     d14 + d88
 *   "CAV/Apostille (DFA) -undergraduate"             d14 + d89
 *   "CAV/Apostille (DFA) with Special Certification" d14 + d90
 *
 * A receipt carrying one of these labels therefore matches two active types,
 * which makes the suggestion ambiguous. The most specific entry should own
 * its label, so this migration removes those three labels from d14 and leaves
 * d14 with the general "CAV/Apostille (DFA)" pattern.
 *
 * Safety:
 *  - A label is removed from d14 ONLY when an ACTIVE (non-archived) d88/d89/d90
 *    already owns it (by name or pattern, ignoring case and punctuation), so no
 *    label is ever left without an owner. On databases that do not have
 *    d88-d90 (fresh installs, old local data) the migration changes nothing.
 *  - Only d14's cashier_document_patterns column is touched. No rows are
 *    deleted and no other table is written.
 *  - Idempotent: running it again after it has applied changes nothing.
 *  - down() puts the three labels back on d14 (re-creating the overlap), and
 *    skips any label d14 already has.
 */
return new class extends Migration
{
    private const DOCUMENT_TABLE = 'document_type';
    private const PRIMARY_KEY = 'document_type_id';
    private const LEGACY_ID = 14;
    private const SPECIFIC_IDS = [88, 89, 90];

    /** Labels that d14 should no longer own once a specific type owns them. */
    private const LABELS = [
        'CAV (CHED)',
        'CAV/Apostille (DFA) -undergraduate',
        'CAV/Apostille (DFA) with Special Certification',
    ];

    public function up(): void
    {
        if (!Schema::hasTable(self::DOCUMENT_TABLE)) {
            return;
        }

        DB::transaction(function () {
            $legacy = $this->legacyRow();
            if ($legacy === null) {
                return;
            }

            $current = $this->decodePatterns($legacy->cashier_document_patterns);
            $owned = $this->activeSpecificOwnedKeys();

            $removable = [];
            foreach (self::LABELS as $label) {
                if (isset($owned[$this->normalize($label)])) {
                    $removable[$this->normalize($label)] = true;
                }
            }
            if ($removable === []) {
                return;
            }

            $next = array_values(array_filter(
                $current,
                fn ($pattern) => !is_string($pattern) || !isset($removable[$this->normalize($pattern)])
            ));

            if ($next === $current) {
                return;
            }

            $this->savePatterns($next);
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable(self::DOCUMENT_TABLE)) {
            return;
        }

        DB::transaction(function () {
            $legacy = $this->legacyRow();
            if ($legacy === null) {
                return;
            }

            $current = $this->decodePatterns($legacy->cashier_document_patterns);
            $have = [];
            foreach ($current as $pattern) {
                if (is_string($pattern)) {
                    $have[$this->normalize($pattern)] = true;
                }
            }

            $next = $current;
            foreach (self::LABELS as $label) {
                if (!isset($have[$this->normalize($label)])) {
                    $next[] = $label;
                }
            }

            if ($next !== $current) {
                $this->savePatterns($next);
            }
        });
    }

    private function legacyRow(): ?object
    {
        return DB::table(self::DOCUMENT_TABLE)
            ->where(self::PRIMARY_KEY, self::LEGACY_ID)
            ->lockForUpdate()
            ->first();
    }

    /**
     * Normalized names and patterns held by the ACTIVE specific types.
     *
     * @return array<string, true>
     */
    private function activeSpecificOwnedKeys(): array
    {
        $rows = DB::table(self::DOCUMENT_TABLE)
            ->whereIn(self::PRIMARY_KEY, self::SPECIFIC_IDS)
            ->where('is_archived', false)
            ->get(['document_name', 'cashier_document_patterns']);

        $keys = [];
        foreach ($rows as $row) {
            $strings = $this->decodePatterns($row->cashier_document_patterns);
            $strings[] = $row->document_name;
            foreach ($strings as $string) {
                if (is_string($string) && $string !== '') {
                    $keys[$this->normalize($string)] = true;
                }
            }
        }

        return $keys;
    }

    /** @return array<int, mixed> */
    private function decodePatterns(mixed $raw): array
    {
        if (is_array($raw)) {
            return array_values($raw);
        }
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? array_values($decoded) : [];
    }

    /** @param array<int, mixed> $patterns */
    private function savePatterns(array $patterns): void
    {
        DB::table(self::DOCUMENT_TABLE)
            ->where(self::PRIMARY_KEY, self::LEGACY_ID)
            ->update([
                'cashier_document_patterns' => $patterns === []
                    ? null
                    : json_encode(array_values($patterns), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]);
    }

    /** Lowercase and collapse every non-alphanumeric run, so "-undergraduate" == "- Undergraduate". */
    private function normalize(string $value): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', strtolower($value)));
    }
};
