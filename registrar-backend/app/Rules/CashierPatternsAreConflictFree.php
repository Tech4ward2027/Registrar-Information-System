<?php

namespace App\Rules;

use App\Services\CashierLabelNormalizer;
use App\Services\CashierPatternConflictChecker;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates a whole `cashier_document_patterns` array submitted when
 * creating or editing a document/certificate type:
 *
 *   1. Every entry is a non-blank, usable label.
 *   2. No two entries in the SAME submission normalise to the same label
 *      (e.g. "TOR Fee" and "tor  fee." are the same mistake typed twice).
 *   3. No entry normalises to a label already registered on a DIFFERENT
 *      document/certificate type — see CashierPatternConflictChecker.
 *
 * Applied to the array attribute itself (e.g. `cashier_document_patterns`),
 * NOT `cashier_document_patterns.*` — checks #2 and #3 need to see the
 * whole submitted list at once to compare entries against each other,
 * which a per-item rule can't do.
 *
 * This Rule only PREVENTS bad data from being accepted; it does not clean
 * or de-duplicate the array itself. Once validation passes, use
 * App\Services\CashierPatternSanitizer::sanitize() to produce the value
 * actually written to the DB (trims stray whitespace, etc.).
 *
 * Usage (inside a FormRequest::rules()):
 *
 *   'cashier_document_patterns' => [
 *       'sometimes', 'nullable', 'array', 'max:50',
 *       new CashierPatternsAreConflictFree('document', $this->route('id')),
 *   ],
 *   'cashier_document_patterns.*' => ['string', 'max:255'],
 */
final class CashierPatternsAreConflictFree implements ValidationRule
{
    /**
     * @param  'document'|'certificate' $ownerType  Which table the record
     *         being written belongs to.
     * @param  int|null $ownerId  Primary key of the record being edited.
     *         Pass null when creating — there's nothing to exclude from
     *         the conflict scan yet.
     */
    public function __construct(
        private readonly string $ownerType,
        private readonly ?int $ownerId = null,
    ) {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Non-array / empty-array values have nothing for this rule to
        // check — the 'array' rule declared alongside this one already
        // reports a non-array submission, and an empty array (clearing
        // all patterns) is always valid.
        if (!is_array($value) || empty($value)) {
            return;
        }

        $seenWithinSubmission = []; // normalised key => first-seen original text

        foreach ($value as $index => $rawPattern) {
            $position = $index + 1;

            if (!is_string($rawPattern)) {
                continue; // the 'cashier_document_patterns.*' string rule reports this
            }

            $trimmed = trim($rawPattern);
            if ($trimmed === '') {
                $fail("Cashier match #{$position} can't be blank.");
                continue;
            }

            $key = CashierLabelNormalizer::normalize($trimmed);
            if ($key === '') {
                $fail("Cashier match #{$position} (\"{$trimmed}\") isn't a usable label — it's just punctuation/whitespace once normalised.");
                continue;
            }

            if (isset($seenWithinSubmission[$key])) {
                $fail("Cashier match #{$position} (\"{$trimmed}\") is the same as \"{$seenWithinSubmission[$key]}\" already in this list.");
                continue;
            }

            $seenWithinSubmission[$key] = $trimmed;
        }

        if (empty($seenWithinSubmission)) {
            return; // every entry already failed above — nothing left to conflict-check
        }

        // Resolved via the container rather than constructor injection:
        // FormRequest::rules() instantiates this class with `new`, which
        // doesn't run through Laravel's DI, so a plain service-locator
        // call here is the standard pattern for custom validation rules
        // that need a DB-backed dependency.
        $conflicts = app(CashierPatternConflictChecker::class)
            ->findConflicts(array_keys($seenWithinSubmission), $this->ownerType, $this->ownerId);

        foreach ($conflicts as $key => $conflictingTypeName) {
            $fail("\"{$seenWithinSubmission[$key]}\" is already registered as a cashier match for \"{$conflictingTypeName}\". A cashier label can only be linked to one document/certificate type — remove it from the other one first.");
        }
    }
}
