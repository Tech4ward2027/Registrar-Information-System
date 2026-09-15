<?php

namespace App\DTOs\Ogos;

/**
 * Undergrad Requestor Registration — Phase 4 (D6).
 *
 * The outcome of the live, advisory OGOS enrollment check run when a
 * Registrar Admin opens or decides a pending Undergrad Requestor
 * submission.
 *
 * This exists as its own small DTO — rather than the lookup simply
 * returning ?OgosStudentDTO like AlumniSystemClientInterface::
 * tryLookupAlumniByEmail() does — because this feature needs THREE
 * outcomes kept distinct, and a nullable DTO can only express two:
 *
 *   performed = false             → the check never ran (OGOS
 *                                   unreachable, misconfigured, or
 *                                   erroring). Persisted as
 *                                   ogos_lookup_performed_at = NULL,
 *                                   ogos_match_found = NULL.
 *   performed = true,  match=false → the check RAN and OGOS does not
 *                                   know this person. Persisted as
 *                                   ogos_lookup_performed_at = now(),
 *                                   ogos_match_found = false. This is
 *                                   the EXPECTED, uninformative case —
 *                                   OGOS only indexes currently-enrolled
 *                                   students, and an Undergrad Requestor
 *                                   by definition is not one.
 *   performed = true,  match=true  → the check RAN and OGOS reports this
 *                                   person as currently enrolled. This
 *                                   is the actually-interesting signal:
 *                                   a misclassification (they should be
 *                                   using the Student flow) or a fraud
 *                                   indicator. Surfaced prominently.
 *
 * Collapsing the first two into a single "null" would make the Admin UI
 * — and the permanent verification record — unable to tell "we checked
 * and found nothing" from "we never checked," which is precisely the
 * distinction the create_undergrad_requestor_verifications_table
 * migration's nullable ogos_match_found column was designed to preserve.
 *
 * readonly, matching the OgosStudentDTO/OgosPersonalInfoDTO convention
 * in this namespace.
 */
readonly class OgosEnrollmentLookupResult
{
    private function __construct(
        public bool $performed,
        public bool $matchFound,
        public ?OgosStudentDTO $student = null,
        /**
         * Short, non-sensitive reason the lookup could not be performed
         * (e.g. 'OGOS unreachable'). Logged and shown to the Admin as
         * "advisory check unavailable" context — never an exception
         * message dumped verbatim to an API response.
         */
        public ?string $unavailableReason = null,
    ) {}

    /** OGOS answered and knows this person as currently enrolled. */
    public static function match(OgosStudentDTO $student): self
    {
        return new self(performed: true, matchFound: true, student: $student);
    }

    /** OGOS answered and does not know this person — the expected case. */
    public static function noMatch(): self
    {
        return new self(performed: true, matchFound: false);
    }

    /** The lookup could not be performed at all. */
    public static function unavailable(string $reason): self
    {
        return new self(performed: false, matchFound: false, unavailableReason: $reason);
    }

    /**
     * The value to persist into
     * undergrad_requestor_verifications.ogos_match_found — NULL when the
     * check never ran, so "unknown" and "known-false" stay distinct in
     * the permanent record (see the class docblock).
     */
    public function matchFoundColumnValue(): ?bool
    {
        return $this->performed ? $this->matchFound : null;
    }
}
