<?php

namespace App\Services;

/**
 * CashierFailureDiagnosisService
 * ==============================
 * Pure, deterministic mapping from what RIS knows about a failed cashier
 * verification to a list of diagnosis codes (Cashier Reconciliation /
 * System Health, Phase 2b).
 *
 * NO I/O. No database, no HTTP, no clock, no config, no randomness: the
 * same input always yields the same output, which is what makes it
 * trivially unit-testable and why this is deliberately NOT an AI feature
 * (name matching is rules, not inference).
 *
 * WORDING RULE. Codes are "likely contributing factors", never a fault
 * verdict. The Cashier API does not return the name it has on file, so
 * the cashier-side cause cannot be determined from RIS. Nothing here, and
 * nothing in any UI built on it, may claim otherwise.
 *
 * Which name is inspected: format flags look at the LOCAL profile (the
 * name RIS actually built its candidate formats from). PROFILE_DRIFT and
 * FMT_MISSING_MIDDLE compare it with the OGOS/alumni snapshot, so they
 * can only fire when a snapshot exists.
 */
class CashierFailureDiagnosisService
{
    // ── Codes (must match FailureReasonCode::STARTER_CODES) ──────────────
    public const OGOS_NOT_FOUND            = 'OGOS_NOT_FOUND';
    public const OGOS_UNREACHABLE          = 'OGOS_UNREACHABLE';
    public const ALUMNI_LOOKUP_FAILED      = 'ALUMNI_LOOKUP_FAILED';
    public const NO_SNAPSHOT               = 'NO_SNAPSHOT';
    public const PROFILE_DRIFT             = 'PROFILE_DRIFT';
    public const ALL_CANDIDATES_EXHAUSTED  = 'ALL_CANDIDATES_EXHAUSTED';
    public const FMT_MISSING_MIDDLE        = 'FMT_MISSING_MIDDLE';
    public const FMT_SUFFIX_IN_SURNAME     = 'FMT_SUFFIX_IN_SURNAME';
    public const FMT_NON_ASCII             = 'FMT_NON_ASCII';
    public const FMT_HYPHEN_OR_SPACING     = 'FMT_HYPHEN_OR_SPACING';
    public const FMT_CASE_INCONSISTENT     = 'FMT_CASE_INCONSISTENT';

    // ── EnrichCashierFailureJob's failure_reason values (stored in audit
    // rows already — values must not change). ──────────────────────────────
    public const SOURCE_FAILURE_OGOS_NOT_FOUND   = 'NOT_FOUND_IN_OGOS';
    public const SOURCE_FAILURE_OGOS_UNREACHABLE = 'OGOS_UNREACHABLE_AFTER_RETRIES';
    public const SOURCE_FAILURE_ALUMNI           = 'ALUMNI_SYSTEM_UNAVAILABLE_OR_NOT_FOUND';
    public const SOURCE_FAILURE_NO_PROFILE       = 'NO_PROFILE_ON_ACTOR';

    private const NAME_PARTS = ['first_name', 'middle_name', 'last_name', 'suffix'];

    /** Suffix tokens recognised at the END of a surname ("Santos Jr.", "Reyes, III"). */
    private const SUFFIX_IN_SURNAME = '/(?:^|[\s,])(?:jr|sr|ii|iii|iv|v)\.?$/i';

    /**
     * @param array{first_name?:?string,middle_name?:?string,last_name?:?string,suffix?:?string} $localProfile
     *        The RIS-side profile the candidate formats were built from.
     * @param array{first_name?:?string,middle_name?:?string,last_name?:?string,suffix?:?string}|null $snapshot
     *        On-file name from OGOS / the alumni system, or null if none.
     * @param string|null $sourceFailureReason  EnrichCashierFailureJob's failure_reason for the
     *        snapshot fetch (SOURCE_FAILURE_*), or null when the snapshot was obtained.
     * @param array<int, array{name?:mixed,valid?:mixed,reason?:mixed}> $attempts
     *        The cashier_verification audit row's `attempts`.
     *
     * @return array<int, string>  Unique codes, ordered source → data → format.
     */
    public function diagnose(
        array   $localProfile,
        ?array  $snapshot,
        ?string $sourceFailureReason,
        array   $attempts,
    ): array {
        $codes = [];

        // ── source ───────────────────────────────────────────────────────
        if ($snapshot === null) {
            $codes[] = match ($sourceFailureReason) {
                self::SOURCE_FAILURE_OGOS_NOT_FOUND   => self::OGOS_NOT_FOUND,
                self::SOURCE_FAILURE_OGOS_UNREACHABLE => self::OGOS_UNREACHABLE,
                self::SOURCE_FAILURE_ALUMNI           => self::ALUMNI_LOOKUP_FAILED,
                // No snapshot and nothing more specific explains it
                // (including NO_PROFILE_ON_ACTOR and an unknown reason).
                default                               => self::NO_SNAPSHOT,
            };
        }

        // ── data ─────────────────────────────────────────────────────────
        if ($snapshot !== null && $this->drifted($localProfile, $snapshot)) {
            $codes[] = self::PROFILE_DRIFT;
        }

        if ($this->allCandidatesExhausted($attempts)) {
            $codes[] = self::ALL_CANDIDATES_EXHAUSTED;
        }

        // ── format ───────────────────────────────────────────────────────
        $local = $this->parts($localProfile);

        if ($snapshot !== null
            && $local['middle_name'] === ''
            && $this->parts($snapshot)['middle_name'] !== '') {
            $codes[] = self::FMT_MISSING_MIDDLE;
        }

        if (preg_match(self::SUFFIX_IN_SURNAME, $local['last_name']) === 1) {
            $codes[] = self::FMT_SUFFIX_IN_SURNAME;
        }

        if ($this->hasNonAscii($local)) {
            $codes[] = self::FMT_NON_ASCII;
        }

        if ($this->hasHyphenOrIrregularSpacing($localProfile)) {
            $codes[] = self::FMT_HYPHEN_OR_SPACING;
        }

        if ($this->caseInconsistent($local, $snapshot !== null ? $this->parts($snapshot) : null)) {
            $codes[] = self::FMT_CASE_INCONSISTENT;
        }

        return array_values(array_unique($codes));
    }

    // ─────────────────────────────────────────────────────────────────────

    /** @return array{first_name:string,middle_name:string,last_name:string,suffix:string} trimmed, null-safe */
    private function parts(array $profile): array
    {
        $out = [];
        foreach (self::NAME_PARTS as $key) {
            $out[$key] = trim((string) ($profile[$key] ?? ''));
        }

        return $out;
    }

    /** Case-, whitespace- and period-insensitive form, used only for drift comparison. */
    private function comparable(string $value): string
    {
        $value = mb_strtolower($value);
        $value = str_replace('.', '', $value);

        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    private function drifted(array $local, array $snapshot): bool
    {
        $a = $this->parts($local);
        $b = $this->parts($snapshot);

        foreach (self::NAME_PARTS as $key) {
            if ($this->comparable($a[$key]) !== $this->comparable($b[$key])) {
                return true;
            }
        }

        return false;
    }

    private function allCandidatesExhausted(array $attempts): bool
    {
        if ($attempts === []) {
            return false;
        }

        foreach ($attempts as $attempt) {
            if (!is_array($attempt)
                || ($attempt['valid'] ?? false) === true
                || ($attempt['reason'] ?? null) !== 'NOT_FOUND') {
                return false;
            }
        }

        return true;
    }

    private function hasNonAscii(array $parts): bool
    {
        foreach ($parts as $value) {
            if (preg_match('/[^\x00-\x7F]/', $value) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Hyphen in first/last, a multi-word surname, or stray whitespace
     * (leading/trailing/doubled) in any part. Uses the UNtrimmed input so
     * leading/trailing whitespace is visible.
     */
    private function hasHyphenOrIrregularSpacing(array $profile): bool
    {
        foreach (self::NAME_PARTS as $key) {
            $raw = (string) ($profile[$key] ?? '');

            if ($raw !== trim($raw) || preg_match('/\s{2,}/u', $raw) === 1) {
                return true;
            }
        }

        $first = trim((string) ($profile['first_name'] ?? ''));
        $last  = trim((string) ($profile['last_name'] ?? ''));

        return str_contains($first, '-')
            || str_contains($last, '-')
            || preg_match('/\s/u', $last) === 1;
    }

    /**
     * A name part with 2+ letters stored entirely upper- or lower-case, or
     * a part that differs from the snapshot ONLY by case.
     *
     * @param array<string,string>      $local
     * @param array<string,string>|null $snapshot
     */
    private function caseInconsistent(array $local, ?array $snapshot): bool
    {
        foreach (['first_name', 'middle_name', 'last_name'] as $key) {
            $value = $local[$key];

            if (preg_match_all('/\p{L}/u', $value) >= 2
                && (mb_strtoupper($value) === $value || mb_strtolower($value) === $value)) {
                return true;
            }

            if ($snapshot !== null
                && $value !== ''
                && $snapshot[$key] !== ''
                && $value !== $snapshot[$key]
                && mb_strtolower($value) === mb_strtolower($snapshot[$key])) {
                return true;
            }
        }

        return false;
    }
}
