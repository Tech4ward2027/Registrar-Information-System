<?php

namespace App\Services;

/**
 * CashierPatternSanitizer
 *
 * Produces the final `cashier_document_patterns` value to persist, once
 * App\Rules\CashierPatternsAreConflictFree has already accepted the
 * submission.
 *
 * Deliberately separate from that Rule: a ValidationRule's job is to
 * report errors against the *submitted* value — it has no clean way to
 * hand back a transformed value for $request->validated() to return
 * instead. So this small, pure, DB-free cleanup step is applied explicitly
 * in the controller after validation passes. Kept here — one shared
 * function — rather than inlined separately in DocumentTypeController and
 * CertificationTypeController, so the two controllers can't drift into
 * two slightly different cleanup rules the same way the three copies of
 * label-normalisation logic once did before CashierLabelNormalizer was
 * introduced (see that class's docblock).
 */
final class CashierPatternSanitizer
{
    /**
     * Trim whitespace, drop blank entries, and de-duplicate by normalised
     * form (case/whitespace/punctuation-insensitive — see
     * CashierLabelNormalizer), keeping the first-seen original casing.
     * Re-indexes to a plain 0-based list, matching the shape
     * DocumentType/CertificationType's `array` cast expects.
     *
     * By the time this runs, CashierPatternsAreConflictFree has already
     * rejected blank entries and in-submission duplicates as validation
     * errors — this pass is defensive and idempotent, not the primary
     * line of defence. It also runs on values that skip that Rule
     * entirely (there is currently no such caller, but keeping this
     * function safe to call standalone avoids a silent trap for future
     * ones).
     *
     * @param  array $rawPatterns
     * @return string[]
     */
    public static function sanitize(array $rawPatterns): array
    {
        $seen = []; // normalised key => first-seen original (trimmed) casing

        foreach ($rawPatterns as $raw) {
            if (!is_string($raw)) {
                continue;
            }

            $trimmed = trim($raw);
            if ($trimmed === '') {
                continue;
            }

            $key = CashierLabelNormalizer::normalize($trimmed);
            if ($key === '' || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = $trimmed;
        }

        return array_values($seen);
    }
}
