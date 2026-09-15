<?php

namespace App\Contracts;

use App\DTOs\Ogos\OgosEnrollmentLookupResult;

/**
 * Undergrad Requestor Registration — Phase 4 (D6).
 *
 * The thin, advisory-only OGOS client the Admin verification queue uses
 * to answer one question: "does OGOS currently know this person as an
 * enrolled student?"
 *
 * Deliberately a NEW, narrow interface rather than a method added to
 * OgosStudentService, for two reasons:
 *
 *   1. Contract semantics. Every existing OgosStudentService/OgosClient
 *      method THROWS OgosException when OGOS is unreachable or returns
 *      404 — correct for the Student SSO sync, where a failed lookup is
 *      a real failure. Here the opposite is true: OGOS not knowing this
 *      person is the EXPECTED answer, and OGOS being down must never
 *      block a Registrar Admin from reviewing a queue of local records.
 *      This interface therefore NEVER throws — same tryX() philosophy
 *      as AlumniSystemClientInterface — and encodes "couldn't check" as
 *      a first-class result rather than an exception.
 *
 *   2. Least privilege / blast radius. The verification queue needs an
 *      existence check only. Depending on this interface (rather than
 *      the full OgosStudentService, which can also write local records
 *      via provisionStudentData()) makes it structurally impossible for
 *      a review action to trigger an OGOS-owned-table write — the exact
 *      risk D5 exists to prevent.
 *
 * Bound in AppServiceProvider to OgosEnrollmentLookupClient. Test
 * doubles swap it with $this->instance(...) / a fake, the same way the
 * alumni client's mock/real split already works.
 */
interface UndergradEnrollmentLookupClientInterface
{
    /**
     * Look this person up in OGOS by their self-declared student number,
     * falling back to their onboarding email if the student number
     * yields nothing.
     *
     * Both identifiers are tried because the two failure modes differ:
     * a mistyped/legacy student number would produce a false "no match"
     * that the email can still catch, and an Undergrad Requestor whose
     * enrollment was reinstated under a new student number would only be
     * findable by email. A hit on EITHER is a match — the check is
     * looking for a reason to doubt the submission, not to confirm it.
     *
     * NEVER throws. Returns an OgosEnrollmentLookupResult that
     * distinguishes "not performed" from "performed, no match" — see
     * that class's docblock for why that distinction is load-bearing.
     *
     * @param  string|null  $studentNumber  Self-declared; may be blank.
     * @param  string|null  $email          The onboarding email.
     */
    public function lookup(?string $studentNumber, ?string $email): OgosEnrollmentLookupResult;
}
