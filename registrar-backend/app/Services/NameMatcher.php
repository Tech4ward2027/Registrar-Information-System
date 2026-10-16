<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\StringSimilarity;

/**
 * NameMatcher
 *
 * IMPORTANT — this class's role changed shape during design once a real
 * API constraint was discovered; read this before touching it.
 *
 * The original design was "compare RIS's submitted name against the
 * Cashier's on-file name after a failed lookup, and rescue near-misses."
 * That's not buildable: per the Cashier System API doc, a NOT_FOUND
 * response is exactly `{"valid": false, "reason": "NOT_FOUND"}` — no
 * `data`, no on-file name — and a wrong name vs. a wrong OR number
 * return that identical shape. RIS never receives the name that's
 * actually on file when a lookup fails, so there is nothing to
 * fuzzy-compare against after the fact.
 *
 * What's actually buildable instead: generate several plausible
 * formattings of the same person's name from data RIS already has (the
 * student/alumni profile), and let the caller retry verifyPayment() with
 * each candidate until one succeeds or all are exhausted. This works
 * because the Cashier API is a live, repeatable endpoint we can call
 * more than once — the compensating control is "try harder," not
 * "second-guess the API's answer."
 *
 * Why this is needed at all
 * -------------------------
 * The Cashier System's "Customer Name" field is free text (confirmed via
 * screenshot of the live payment form, 2026-08-11) — the placeholder
 * "LAST NAME, FIRST NAME M.I." is a hint, not an enforced format.
 * Nothing stops a cashier admin from typing a full middle name, dropping
 * it entirely, reordering it, or attaching a suffix to the last name
 * instead of the given names. RIS's own formatCustomerName() produces one
 * specific format (middle initial, no full middle name — fixed
 * 2026-08-11 for the same reason). If the admin's on-file string uses a
 * different convention than the one RIS guesses, the OR is real and paid
 * but verification still fails.
 *
 * Design
 * ------
 * Candidates are ordered from most- to least-likely, and the caller
 * should stop at the first one that returns valid:true (see
 * DocumentRequestController::store()). The OR number itself is never
 * varied — it's the real credential (single-use enforced, not
 * practically guessable). Only the name formatting is guessed, because
 * that's the only piece a free-text form makes unreliable.
 *
 * This class does not call the API, log anything, or decide pass/fail —
 * it only generates candidate strings. The caller owns the retry loop
 * and the audit trail.
 *
 * Safety cap
 * ----------
 * Every candidate this method returns is a live call to a third-party
 * API in the caller's retry loop. MAX_CANDIDATES bounds that regardless
 * of how many variant dimensions get added over time (suffix placement,
 * punctuation, word order, ...), so a future well-intentioned addition
 * can't silently turn a handful of API calls into dozens per submission.
 * Entries are pruned from the *end* of the priority-ordered list, so the
 * most-likely formats are always kept.
 */
class NameMatcher
{
    /**
     * Hard ceiling on the number of candidates returned. Chosen to comfortably
     * cover every variant dimension currently implemented (suffix placement x
     * punctuation x middle-name handling x word order) while keeping a single
     * document-request submission from firing an unbounded number of calls at
     * the Cashier API.
     */
    private const MAX_CANDIDATES = 16;

    /** Longest input we'll accept per name part, applied before any processing. */
    private const MAX_PART_LENGTH = 100;

    /**
     * Recognized name suffixes, shared between candidate generation and the
     * legacy compare() path so the two can never silently drift apart.
     */
    private const KNOWN_SUFFIXES = ['JR', 'SR', 'II', 'III', 'IV', 'V'];

    /**
     * Generate plausible name-string candidates for the same person,
     * most-likely-correct first.
     *
     * Confirmed 2026-08-11: the Cashier System's "Customer Name" field is
     * free text an admin types by hand, and the "LAST NAME, FIRST NAME
     * M.I." placeholder on its own form is not enforced — one real OR was
     * on file simply as "Floresca Duvan" (no comma at all, not even
     * following its own form's convention). Since the admin's format
     * can't be predicted and the cashier team can't change how they enter
     * names, RIS compensates by trying multiple plausible formats rather
     * than one "correct" one.
     *
     * Confirmed 2026-08-12: the missing-comma problem has a sibling —
     * cashier admins are just as inconsistent about the *period* on a
     * middle initial or suffix as they are about the comma. "JUAN S. DELA
     * CRUZ" and "JUAN S DELA CRUZ" are the same person to a human, but the
     * Cashier API does exact string matching, so a dropped period is a
     * hard miss same as a dropped comma.
     *
     * Confirmed 2026-08-13: the suffix problem has a second sibling that
     * period-optional variants don't cover — the suffix's *slot*, not its
     * punctuation. A real OR was on file as "NONO JR., JOEGE C.", with
     * the suffix folded into the LAST name ("NONO JR.") rather than
     * appended to the given names. Every candidate before this fix put
     * the suffix after the middle initial, so a suffixed person could
     * never match regardless of how many punctuation variants existed.
     * Reproduced live against the Cashier API before landing this fix.
     *
     * Hyphenated names (2026-08-13, anticipated rather than yet observed
     * in a failure): a compound surname like "GARCIA-REYES" or a
     * hyphenated first name like "MARY-JOY" has the same free-text
     * problem as punctuation elsewhere — an admin may type the hyphen,
     * a space, or nothing at all. This adds separator variants for any
     * name part that actually contains a hyphen (zero cost when it
     * doesn't). Deliberately NOT included: candidates that drop one half
     * of a hyphenated name entirely (e.g. matching "GARCIA-REYES" against
     * just "GARCIA"). Separator guessing is safe because it's still
     * unambiguously the same name; dropping a component changes which
     * name is being searched for, and could match a different real
     * person's on-file record. For payment verification, a false match
     * is a worse failure than one more manual review, so that dimension
     * is intentionally left out rather than added later without thinking
     * it through — see also the class docblock on why any addition here
     * needs to weigh false-negative cost against false-positive risk.
     *
     * Each new variant dimension (punctuation, slot, order) is applied
     * only to the highest-priority format rather than cross-multiplied
     * across every existing candidate — a full cross product would push
     * the list into the dozens for the fraction of people who have both a
     * middle name and a suffix, and every extra candidate is one more
     * live API call before a genuinely bad OR gets rejected. See
     * MAX_CANDIDATES for the hard backstop on top of that discipline.
     *
     * @return string[]  Deduplicated, in priority order, capped at
     *                    MAX_CANDIDATES. Always includes at least the
     *                    primary "LAST, FIRST M.I." format.
     */
    public function candidatesFor(
        string $lastName,
        string $firstName,
        string $middleName = '',
        string $suffix = '',
    ): array {
        $last   = $this->sanitizePart($lastName);
        $first  = $this->sanitizePart($firstName);
        $middle = $this->sanitizePart($middleName);

        $suffixBase   = $this->sanitizeSuffix($suffix);
        $suffixDotted = $suffixBase !== '' ? $suffixBase . '.' : '';
        $suffixBare   = $suffixBase; // e.g. "JR" — admin dropped the period

        $middleLetter        = $middle !== '' ? mb_substr($middle, 0, 1) : '';
        $middleInitialDotted = $middleLetter !== '' ? $middleLetter . '.' : '';
        $middleInitialBare   = $middleLetter; // e.g. "S" — admin dropped the period
        $middleFull          = $middle;

        // Suffix folded into the LAST-name slot (e.g. "NONO JR., JOEGE C.")
        // rather than the given-name slot — see class docblock, 2026-08-13.
        $lastWithSuffixDotted = $suffixDotted !== '' ? "{$last} {$suffixDotted}" : $last;
        $lastWithSuffixBare   = $suffixBare !== '' ? "{$last} {$suffixBare}" : $last;

        // Hyphen-separator variants — see class docblock, 2026-08-13. Only
        // computed (and only added as candidates below) when the part
        // actually contains a hyphen, so a non-hyphenated name pays
        // nothing extra.
        $lastHyphenToSpace = str_contains($last, '-') ? str_replace('-', ' ', $last) : null;
        $lastHyphenRemoved = str_contains($last, '-') ? str_replace('-', '', $last) : null;
        $firstHyphenToSpace = str_contains($first, '-') ? str_replace('-', ' ', $first) : null;
        $firstHyphenRemoved = str_contains($first, '-') ? str_replace('-', '', $first) : null;

        $candidates = [
            // 1. Current standard: "LAST, FIRST M.I." — matches the API
            //    doc's own sample names (e.g. "MENDOZA, JAMES MARTIN").
            $this->buildComma($last, $first, $middleInitialDotted, $suffixDotted),
            // 1b. Suffix attached to the last name instead of the given
            //     names (2026-08-13 fix — see class docblock).
            $suffixDotted !== '' ? $this->buildComma($lastWithSuffixDotted, $first, $middleInitialDotted, '') : null,
            // 1c. Same, but the last-name-attached suffix has no period.
            $suffixBare !== '' ? $this->buildComma($lastWithSuffixBare, $first, $middleInitialDotted, '') : null,
            // 2. Same as #1, but middle initial has no trailing period —
            //    covers an admin who typed "S" instead of "S." (2026-08-12).
            $middleInitialBare !== '' ? $this->buildComma($last, $first, $middleInitialBare, $suffixDotted) : null,
            // 3. Full middle name, in case the admin typed it as-is.
            $middleFull !== '' ? $this->buildComma($last, $first, $middleFull, $suffixDotted) : null,
            // 4. No middle name at all, in case the admin omitted it.
            $this->buildComma($last, $first, '', $suffixDotted),
            // 5. Same as #1, but suffix has no trailing period — covers
            //    an admin who typed "JR" instead of "JR." (2026-08-12).
            $suffixBare !== '' ? $this->buildComma($last, $first, $middleInitialDotted, $suffixBare) : null,
            // 5b. Hyphenated last name, admin used a space instead — e.g.
            //     "GARCIA REYES" for on-file "GARCIA-REYES" (2026-08-13).
            $lastHyphenToSpace !== null ? $this->buildComma($lastHyphenToSpace, $first, $middleInitialDotted, $suffixDotted) : null,
            // 5c. Same, but admin ran the hyphenated surname together
            //     with no separator at all ("GARCIAREYES").
            $lastHyphenRemoved !== null ? $this->buildComma($lastHyphenRemoved, $first, $middleInitialDotted, $suffixDotted) : null,
            // 5d. Hyphenated first name, admin used a space instead — e.g.
            //     "MARY JOY" for on-file "MARY-JOY".
            $firstHyphenToSpace !== null ? $this->buildComma($last, $firstHyphenToSpace, $middleInitialDotted, $suffixDotted) : null,
            // 5e. Same, but no separator at all ("MARYJOY").
            $firstHyphenRemoved !== null ? $this->buildComma($last, $firstHyphenRemoved, $middleInitialDotted, $suffixDotted) : null,
            // 6. Same "last name first" order, but no comma — covers an
            //    admin who typed the name straight into the box without
            //    following the placeholder's convention at all.
            $middleInitialDotted !== '' ? $this->buildSpace([$last, $first, $middleInitialDotted, $suffixDotted]) : null,
            $this->buildSpace([$last, $first, $suffixDotted]),
            // 7. Natural spoken order ("First Last"), no comma — covers an
            //    admin who typed the name the way they'd say it out loud
            //    rather than in registrar order.
            $middleInitialDotted !== '' ? $this->buildSpace([$first, $middleInitialDotted, $last, $suffixDotted]) : null,
            $this->buildSpace([$first, $last, $suffixDotted]),
        ];

        $deduped = array_values(array_unique(array_filter(
            $candidates,
            static fn (?string $c): bool => $c !== null && $c !== ''
        )));

        return array_slice($deduped, 0, self::MAX_CANDIDATES);
    }

    private function buildComma(string $last, string $first, string $middle, string $suffix): string
    {
        $given = implode(' ', array_filter([$first, $middle, $suffix]));

        return "{$last}, {$given}";
    }

    /**
     * Join non-empty parts with a single space, no comma. Used for the
     * no-punctuation candidate variants.
     */
    private function buildSpace(array $parts): string
    {
        return implode(' ', array_filter($parts, static fn (string $p): bool => $p !== ''));
    }

    /**
     * Normalize and defensively bound a single name part before it's used
     * to build strings sent to an external API.
     *
     * - Trims and uppercases (matches the Cashier convention). Uses
     *   mb_strtoupper() with an explicit UTF-8 encoding, not strtoupper() —
     *   PHP's plain strtoupper() only uppercases ASCII a-z and silently
     *   leaves accented characters (ñ, é, ü, ...) untouched, so a name
     *   like "Muñoz" would come out as "MUñOZ" — a casing no cashier
     *   admin would ever type, whether they correctly wrote "MUÑOZ" or
     *   dropped the tilde to "MUNOZ". Every candidate this method feeds
     *   into inherited that broken casing, so a name with any accented
     *   character could never match regardless of how many punctuation/
     *   order variants existed. mb_strtoupper() uppercases these
     *   characters the way a human would.
     * - Strips ASCII control characters, which have no legitimate place in
     *   a person's name and could otherwise reach an outbound HTTP call or
     *   an audit log unfiltered.
     * - Collapses internal whitespace so a pasted double-space doesn't
     *   silently produce a distinct, never-matching candidate.
     * - Truncates to MAX_PART_LENGTH as a sane upper bound — profile data
     *   should never legitimately need more, and this keeps a corrupted
     *   or malicious field from growing the request body or log entries
     *   unbounded.
     */
    private function sanitizePart(string $value): string
    {
        $value = preg_replace('/[\x00-\x1F\x7F]/', '', $value) ?? '';
        $value = preg_replace('/\s+/', ' ', $value) ?? '';
        $value = mb_strtoupper(trim($value), 'UTF-8');

        return mb_substr($value, 0, self::MAX_PART_LENGTH);
    }

    /**
     * Same bounding as sanitizePart(), plus strips a trailing period so
     * callers can consistently re-add it (dotted) or leave it off (bare)
     * without worrying whether the source data already had one.
     */
    private function sanitizeSuffix(string $value): string
    {
        $value = $this->sanitizePart($value);

        return $value !== '' ? rtrim($value, '.') : '';
    }

    // -------------------------------------------------------------------
    // Retained below: lenient name similarity, for cases where we DO have
    // both strings to compare (e.g. a future admin-facing manual lookup
    // tool, or a Cashier API version that returns the on-file name on
    // failure). Not reachable from the retry flow above today, since
    // NOT_FOUND gives us nothing to compare against.
    // -------------------------------------------------------------------

    /**
     * Minimum Jaro-Winkler similarity for last/first name tokens to count
     * as a match. 0.90 tolerates a stray typo or punctuation difference
     * without accepting a genuinely different name.
     */
    private const STRICT_THRESHOLD = 0.90;

    /**
     * Compare two names and classify the result.
     *
     * @param  string $submitted  Name RIS generated, e.g. "DELA CRUZ, JUAN S."
     * @param  string $onFile     Name returned by the Cashier API's data.customer_name
     * @return array{
     *     match_type: 'exact'|'fuzzy'|'fail',
     *     score: float,
     *     is_match: bool,
     * }
     */
    public function compare(string $submitted, string $onFile): array
    {
        $a = $this->normalise($submitted);
        $b = $this->normalise($onFile);

        if ($a === $b) {
            return ['match_type' => 'exact', 'score' => 1.0, 'is_match' => true];
        }

        [$aLast, $aFirst, $aMiddle] = $this->splitName($a);
        [$bLast, $bFirst, $bMiddle] = $this->splitName($b);

        $lastScore  = StringSimilarity::jaroWinkler($aLast, $bLast);
        $firstScore = StringSimilarity::jaroWinkler($aFirst, $bFirst);
        $middleOk   = $this->middleMatches($aMiddle, $bMiddle);

        $isMatch = $lastScore >= self::STRICT_THRESHOLD
            && $firstScore >= self::STRICT_THRESHOLD
            && $middleOk;

        // Overall score for logging/tuning: weight last+first, middle is
        // pass/fail so it contributes 1.0 or 0.0 rather than a partial score.
        $score = round(
            ($lastScore * 0.45) + ($firstScore * 0.45) + ($middleOk ? 0.10 : 0.0),
            4
        );

        return [
            'match_type' => $isMatch ? 'fuzzy' : 'fail',
            'score'      => $score,
            'is_match'   => $isMatch,
        ];
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Normalise a name for comparison: uppercase, strip periods, collapse
     * whitespace. Does NOT reorder or split — that happens in splitName().
     *
     * Uses mb_strtoupper() (not strtoupper()) for the same reason as
     * sanitizePart() above — plain strtoupper() leaves accented characters
     * untouched, which would break comparison for any name containing one.
     */
    private function normalise(string $name): string
    {
        $name = mb_strtoupper(trim($name), 'UTF-8');
        $name = str_replace('.', '', $name);
        $name = preg_replace('/\s+/', ' ', $name) ?? $name;

        return trim($name);
    }

    /**
     * Split a normalised "LASTNAME, FIRSTNAME [MIDDLE] [SUFFIX]" string
     * into [last, first, middle]. Suffix (JR, SR, III, etc.) is folded
     * into $middle's trailing token and ignored by middleMatches(), since
     * suffix presence/absence isn't the concern here — middle name
     * ambiguity is.
     *
     * Falls back gracefully if there's no comma (treats the whole string
     * as the "last name" slot so it still gets *some* comparison rather
     * than throwing).
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private function splitName(string $normalised): array
    {
        if (!str_contains($normalised, ',')) {
            return [$normalised, '', ''];
        }

        [$last, $rest] = array_map('trim', explode(',', $normalised, 2));

        $tokens = $rest === '' ? [] : explode(' ', $rest);
        $first  = $tokens[0] ?? '';
        $middle = implode(' ', array_slice($tokens, 1));

        return [$last, $first, $middle];
    }

    /**
     * Middle name comparison is lenient by design (see class docblock):
     *   - Both empty → match (neither side gave a middle name).
     *   - Either side empty, other non-empty → match (cashier admin may
     *     omit it entirely; that's not evidence of a different person).
     *   - Both non-empty → match if one is a prefix of the other after
     *     stripping suffix words, covering "CAMERO" vs "C" vs "CAMERO JR".
     */
    private function middleMatches(string $a, string $b): bool
    {
        $a = $this->stripSuffixWords($a);
        $b = $this->stripSuffixWords($b);

        if ($a === '' || $b === '') {
            return true;
        }

        $aFirstToken = explode(' ', $a)[0];
        $bFirstToken = explode(' ', $b)[0];

        return str_starts_with($aFirstToken, $bFirstToken)
            || str_starts_with($bFirstToken, $aFirstToken);
    }

    private function stripSuffixWords(string $value): string
    {
        $tokens = array_filter(
            explode(' ', $value),
            static fn (string $t): bool => !in_array($t, self::KNOWN_SUFFIXES, true)
        );

        return implode(' ', $tokens);
    }
}