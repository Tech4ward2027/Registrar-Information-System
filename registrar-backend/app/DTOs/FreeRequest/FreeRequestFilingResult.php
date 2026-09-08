<?php

namespace App\DTOs\FreeRequest;

use App\Models\DocumentRequest;
use App\Models\GraduateVerification;

/**
 * FESPEC-0008 — Free Document/Certificate Request.
 *
 * Immutable result of FreeRequestService::fileFreeRequest(). Audit
 * logging is deliberately NOT performed inside FreeRequestService (see
 * that class's docblock — this codebase's convention, established by
 * DocumentRequestController/CashierOrOverrideController, is that
 * AuditLogger is called from the controller, using the live Request,
 * after the service call succeeds). This DTO carries everything the
 * Phase 3 controller needs to write specific, accurate audit_log rows
 * (free_request_filed, and conditionally
 * free_request_graduate_verified / free_request_eligibility_overridden)
 * without re-querying anything the service already computed.
 */
class FreeRequestFilingResult
{
    /**
     * @param FreeRequestEligibilityResult[] $eligibilitySnapshots Every
     *        item's eligibility result AS EVALUATED AT FILING TIME
     *        (inside the row-locked transaction) — not the earlier,
     *        possibly-stale GET /free-requests/eligibility check the
     *        frontend may have shown the staff member seconds or
     *        minutes earlier. This is what actually justified (or was
     *        overridden to bypass) each line item, and is exactly what
     *        should be logged.
     * @param array<int,string> $overriddenTypeLabels Labels of the
     *        specific items that were only filed because of an
     *        eligibility override — empty when no override was used.
     */
    public function __construct(
        public readonly DocumentRequest $documentRequest,
        public readonly array $eligibilitySnapshots,
        public readonly bool $wasOverridden,
        public readonly ?string $overrideReason,
        public readonly array $overriddenTypeLabels,
        public readonly bool $graduateVerificationPerformed,
        public readonly ?GraduateVerification $graduateVerification,
    ) {}
}
