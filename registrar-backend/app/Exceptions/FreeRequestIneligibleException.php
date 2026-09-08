<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * FESPEC-0008 — Free Document/Certificate Request.
 *
 * Thrown by FreeRequestService::fileFreeRequest() when one or more
 * requested items fail FreeRequestEligibilityService::check() and the
 * caller did not supply a valid override (see that method's docblock).
 *
 * Carries the full array of FreeRequestEligibilityResult objects for
 * every item in the filing (not just the ineligible ones) so the Phase
 * 3 controller can render a complete, structured 422 — e.g.:
 *
 *   catch (FreeRequestIneligibleException $e) {
 *       return response()->json([
 *           'message' => $e->getMessage(),
 *           'errors'  => array_map(fn ($r) => $r->toArray(), $e->results),
 *       ], 422);
 *   }
 *
 * Deliberately a plain data-carrying exception rather than something
 * that renders its own HTTP response — matches this codebase's existing
 * convention (see PolicyException, caught in PolicyController) of
 * keeping the service layer HTTP-agnostic and letting the controller
 * decide the response shape.
 */
class FreeRequestIneligibleException extends RuntimeException
{
    /**
     * @param \App\DTOs\FreeRequest\FreeRequestEligibilityResult[] $results
     */
    public function __construct(
        public readonly array $results,
        string $message = 'One or more requested items are not eligible for a free request.',
    ) {
        parent::__construct($message);
    }
}
