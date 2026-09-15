<?php

namespace App\Services\Ogos;

use App\Contracts\UndergradEnrollmentLookupClientInterface;
use App\DTOs\Ogos\OgosEnrollmentLookupResult;
use App\DTOs\Ogos\OgosStudentDTO;
use App\Exceptions\OgosException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Undergrad Requestor Registration — Phase 4 (D6).
 *
 * The only implementation of UndergradEnrollmentLookupClientInterface.
 * See that interface for why this exists separately from
 * OgosStudentService.
 *
 * Wraps the existing low-level OgosClient — it does NOT open its own
 * HTTP connection, re-implement M2M token handling, or duplicate the
 * OGOS envelope parsing. Everything this class adds is the never-throws
 * boundary and the three-state result.
 *
 * ── Why 404 is not an error here ──────────────────────────────────────
 * OgosClient::get() raises OgosException(404) for "no such student."
 * For the Student SSO sync that IS an error. For this check it is the
 * single most common, expected, entirely uninteresting outcome, so it
 * is translated to noMatch() rather than unavailable(). Any OTHER
 * failure (connection error, 5xx, auth failure, malformed JSON) means
 * the question genuinely went unanswered, and is translated to
 * unavailable() so the Admin UI can say so plainly instead of implying
 * OGOS cleared this person.
 */
class OgosEnrollmentLookupClient implements UndergradEnrollmentLookupClientInterface
{
    public function __construct(private readonly OgosClient $client) {}

    public function lookup(?string $studentNumber, ?string $email): OgosEnrollmentLookupResult
    {
        $studentNumber = $this->normalize($studentNumber);
        $email         = $this->normalize($email);

        if ($studentNumber === null && $email === null) {
            // Nothing to look up. Reported as "performed, no match"
            // rather than "unavailable": OGOS is not the reason there is
            // no answer, and a submission with neither identifier could
            // not have passed StoreUndergradRequestorRegistrationRequest
            // anyway.
            return OgosEnrollmentLookupResult::noMatch();
        }

        // Memoized so opening a record and then immediately approving it
        // (two separate HTTP requests into RIS, both of which run this
        // check) costs one OGOS round trip, not two. Deliberately short
        // — see config/undergrad_requestor.php.
        $cacheKey = 'undergrad_ogos_lookup:' . sha1(($studentNumber ?? '') . '|' . ($email ?? ''));
        $ttl      = (int) config('undergrad_requestor.ogos_lookup.cache_ttl_seconds', 120);

        $cached = Cache::get($cacheKey);
        if ($cached instanceof OgosEnrollmentLookupResult) {
            return $cached;
        }

        $result = $this->performLookup($studentNumber, $email);

        // Only successful answers are cached. An "unavailable" result
        // must never be sticky — if OGOS comes back thirty seconds
        // later, the next reviewer should get a real answer rather than
        // a cached outage.
        if ($result->performed) {
            Cache::put($cacheKey, $result, $ttl);
        }

        return $result;
    }

    /**
     * Try student number first, then email. The first definitive MATCH
     * wins. "Unavailable" from the first probe does not short-circuit
     * the second — a transient failure on one endpoint should not
     * suppress a usable answer from the other — but if BOTH probes come
     * back unavailable, the overall result is unavailable, never a
     * misleading "no match."
     */
    private function performLookup(?string $studentNumber, ?string $email): OgosEnrollmentLookupResult
    {
        $anyProbeSucceeded = false;
        $lastFailure       = null;

        foreach ($this->probes($studentNumber, $email) as $label => $probe) {
            try {
                $student = $probe();
                $anyProbeSucceeded = true;

                if ($student instanceof OgosStudentDTO) {
                    return OgosEnrollmentLookupResult::match($student);
                }
            } catch (OgosException $e) {
                if ($e->getCode() === 404) {
                    // Definitive: OGOS answered, and does not know them.
                    $anyProbeSucceeded = true;
                    continue;
                }

                $lastFailure = $e;
                $this->logUnavailable($label, $e);
            } catch (Throwable $e) {
                // Belt-and-braces: this method's entire contract is that
                // it cannot throw, so an unexpected error type (a cURL
                // extension issue, a DTO mapping change upstream) must
                // degrade the same way a known OgosException does rather
                // than bubbling into the Admin's review request.
                $lastFailure = $e;
                $this->logUnavailable($label, $e);
            }
        }

        if (!$anyProbeSucceeded && $lastFailure !== null) {
            return OgosEnrollmentLookupResult::unavailable('OGOS was unreachable at review time.');
        }

        return OgosEnrollmentLookupResult::noMatch();
    }

    /**
     * @return array<string, callable(): ?OgosStudentDTO>
     */
    private function probes(?string $studentNumber, ?string $email): array
    {
        $probes = [];

        if ($studentNumber !== null) {
            $probes['student_number'] = fn (): ?OgosStudentDTO => $this->client->getStudentByNumber($studentNumber);
        }

        if ($email !== null) {
            $probes['email'] = fn (): ?OgosStudentDTO => $this->client->getStudentByEmail($email);
        }

        return $probes;
    }

    /**
     * Logged at warning, not error: OGOS being unavailable does not
     * break anything in RIS — the review continues, clearly labelled as
     * "check unavailable." The identifier itself is NOT logged (it is
     * PII and the audit trail already records which submission was being
     * reviewed); only which probe failed and why.
     */
    private function logUnavailable(string $probe, Throwable $e): void
    {
        Log::warning('[UndergradRequestor] advisory OGOS lookup unavailable', [
            'probe'  => $probe,
            'reason' => $e->getMessage(),
        ]);
    }

    private function normalize(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
