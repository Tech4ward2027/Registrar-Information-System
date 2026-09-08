<?php

namespace App\Services;

use App\Models\CertificationType;
use App\Models\DocumentType;

/**
 * CashierPatternConflictChecker
 *
 * Enforces an invariant CashierDocumentSuggester::indexPattern() previously
 * could only warn about after the fact: a normalised cashier label should
 * belong to at most one document/certificate type at a time.
 *
 * Two types silently sharing a pattern doesn't break the strict
 * CashierDocumentMatcher (it checks whichever type the student explicitly
 * picked, independently of any other type). But it does make the
 * *suggester* index ambiguous — whichever type happens to be read from the
 * DB first silently wins the shared key, and the other type is invisibly
 * starved of auto-suggestions with no error surfaced to anyone. See
 * CashierDocumentSuggester::indexPattern()'s docblock for the log-and-move-on
 * behaviour this class exists to prevent in the first place.
 *
 * Used from two places that can attach a pattern to a type:
 *   - App\Rules\CashierPatternsAreConflictFree — validates the admin-typed
 *     pattern list on DocumentType/CertificationType create & update.
 *   - UnmatchedCashierItemController::resolve() — the *other* door a
 *     pattern can be attached through. Without checking here too, an admin
 *     could recreate the exact same conflict via that screen instead,
 *     silently — closing one door while leaving the other open defeats the
 *     point of adding this check at all.
 *
 * Deliberately scans every row on both tables rather than a targeted query:
 * the JSON pattern column can't be searched by *normalised* form in SQL
 * without a generated/virtual column, and both catalogs are small,
 * infrequently-written admin tables (tens/hundreds of rows, edited rarely).
 * This mirrors the exact same full-table-into-PHP-memory approach
 * CashierDocumentSuggester::buildPatternIndex() already uses for the
 * identical reason — see that method's docblock.
 */
final class CashierPatternConflictChecker
{
    /**
     * Find which of the given normalised pattern keys are already
     * registered to some OTHER document/certificate type.
     *
     * @param  string[]                       $normalisedKeys  Keys already
     *         run through CashierLabelNormalizer::normalize().
     * @param  'document'|'certificate'|null   $excludeType     Table the
     *         record being written belongs to, so it isn't compared
     *         against its own existing patterns.
     * @param  int|null                        $excludeId       Primary key
     *         of the record being written. Null when creating — there's
     *         nothing to exclude yet.
     * @return array<string,string> normalised key => display name of the
     *         OTHER type that already owns it.
     */
    public function findConflicts(array $normalisedKeys, ?string $excludeType = null, ?int $excludeId = null): array
    {
        if (empty($normalisedKeys)) {
            return [];
        }

        $conflicts = [];

        $documentTypes = DocumentType::query()
            ->when(
                $excludeType === 'document' && $excludeId !== null,
                fn ($query) => $query->where('document_type_id', '!=', $excludeId)
            )
            ->get(['document_type_id', 'document_name', 'cashier_document_patterns']);

        foreach ($documentTypes as $type) {
            foreach ($this->decodePatterns($type->cashier_document_patterns) as $pattern) {
                $key = CashierLabelNormalizer::normalize((string) $pattern);
                if ($key !== '' && !isset($conflicts[$key]) && in_array($key, $normalisedKeys, true)) {
                    $conflicts[$key] = $type->document_name;
                }
            }
        }

        $certificateTypes = CertificationType::query()
            ->when(
                $excludeType === 'certificate' && $excludeId !== null,
                fn ($query) => $query->where('certificate_type_id', '!=', $excludeId)
            )
            ->get(['certificate_type_id', 'certificate_name', 'cashier_document_patterns']);

        foreach ($certificateTypes as $type) {
            foreach ($this->decodePatterns($type->cashier_document_patterns) as $pattern) {
                $key = CashierLabelNormalizer::normalize((string) $pattern);
                if ($key !== '' && !isset($conflicts[$key]) && in_array($key, $normalisedKeys, true)) {
                    $conflicts[$key] = $type->certificate_name;
                }
            }
        }

        return $conflicts;
    }

    /**
     * Decode cashier_document_patterns the same defensive way
     * CashierDocumentMatcher/CashierDocumentSuggester do — the 'array'
     * cast normally returns a PHP array already, but this guards against
     * null and the raw-JSON-string case (e.g. a raw query bypassing casts).
     *
     * @return string[]
     */
    private function decodePatterns(mixed $raw): array
    {
        if ($raw === null) {
            return [];
        }

        if (is_array($raw)) {
            return $raw;
        }

        $decoded = json_decode((string) $raw, true);

        return is_array($decoded) ? $decoded : [];
    }
}
