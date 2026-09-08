<?php

namespace App\DTOs\FreeRequest;

use App\Enums\FreeRequestItemKindEnum;

/**
 * FESPEC-0008 — Free Document/Certificate Request.
 *
 * Immutable result of FreeRequestEligibilityService::check() for ONE
 * (user, type) pair. Deliberately carries both:
 *
 *   - reasonCode: a stable, machine-readable slug (see the REASON_*
 *     constants) the frontend's eligibility indicator can switch on to
 *     decide what to show/enable, without parsing prose.
 *   - reason: the human-readable sentence shown to staff on the Free
 *     Request page and folded into audit log metadata when an override
 *     is filed against this result.
 *
 * requiresGraduateVerification and remaining are surfaced here (rather
 * than making FreeRequestService re-derive them) because both are
 * already computed as part of the eligibility check itself — see
 * FreeRequestEligibilityService::check()'s docblock for exactly how.
 */
class FreeRequestEligibilityResult
{
    // Keep these values stable — the frontend (Phase 5) matches on them.
    public const REASON_TYPE_NOT_FOUND        = 'type_not_found';
    public const REASON_NOT_FREE_ELIGIBLE     = 'not_free_eligible';
    public const REASON_NOT_VISIBLE_TO_ROLE   = 'not_visible_to_role';
    public const REASON_NOT_GRADUATE          = 'not_graduate';
    public const REASON_LIMIT_REACHED         = 'limit_reached';
    public const REASON_INVALID_TARGET_ROLE   = 'invalid_target_role';
    // Phase 7 — Security Hardening. A finite-limit (graduate-scoped)
    // type has copies remaining (REASON_LIMIT_REACHED did NOT fire),
    // but the quantity requested in THIS line item exceeds what's left
    // — e.g. 1 remaining but number_of_copies: 5 was requested. See
    // FreeRequestEligibilityService::check()'s "Rule 5" docblock for
    // why this must be checked here rather than left to
    // number_of_copies' own min/max shape validation.
    public const REASON_QUANTITY_EXCEEDS_REMAINING = 'quantity_exceeds_remaining';

    public function __construct(
        public readonly bool $eligible,
        public readonly FreeRequestItemKindEnum $kind,
        public readonly int $typeId,
        public readonly ?string $typeLabel,
        public readonly ?string $reasonCode,
        public readonly ?string $reason,
        public readonly bool $requiresGraduateVerification,
        public readonly ?int $freeIssuanceLimit,
        public readonly ?int $remaining,
    ) {}

    public static function eligible(
        FreeRequestItemKindEnum $kind,
        int $typeId,
        ?string $typeLabel,
        bool $requiresGraduateVerification,
        ?int $freeIssuanceLimit,
        ?int $remaining,
    ): self {
        return new self(
            eligible: true,
            kind: $kind,
            typeId: $typeId,
            typeLabel: $typeLabel,
            reasonCode: null,
            reason: null,
            requiresGraduateVerification: $requiresGraduateVerification,
            freeIssuanceLimit: $freeIssuanceLimit,
            remaining: $remaining,
        );
    }

    public static function ineligible(
        FreeRequestItemKindEnum $kind,
        int $typeId,
        ?string $typeLabel,
        string $reasonCode,
        string $reason,
        bool $requiresGraduateVerification = false,
        ?int $freeIssuanceLimit = null,
        ?int $remaining = null,
    ): self {
        return new self(
            eligible: false,
            kind: $kind,
            typeId: $typeId,
            typeLabel: $typeLabel,
            reasonCode: $reasonCode,
            reason: $reason,
            requiresGraduateVerification: $requiresGraduateVerification,
            freeIssuanceLimit: $freeIssuanceLimit,
            remaining: $remaining,
        );
    }

    public function toArray(): array
    {
        return [
            'eligible'                        => $this->eligible,
            'kind'                             => $this->kind->value,
            'type_id'                          => $this->typeId,
            'type_label'                       => $this->typeLabel,
            'reason_code'                      => $this->reasonCode,
            'reason'                           => $this->reason,
            'requires_graduate_verification'   => $this->requiresGraduateVerification,
            'free_issuance_limit'              => $this->freeIssuanceLimit,
            'remaining'                        => $this->remaining,
        ];
    }
}