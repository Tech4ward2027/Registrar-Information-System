<?php

namespace App\Enums;

/**
 * Undergrad Requestor Registration — Phase 1 (D6).
 *
 * Single source of truth for undergrad_requestor_verifications.status —
 * mirrors the convention already established by DeficiencyItemEnum /
 * WithdrawalReasonEnum: a type-safe enum backing a plain string DB
 * column rather than a MySQL ENUM column (see that migration's
 * docblock), so a future status would only ever need a new case here,
 * never a schema migration.
 *
 * Cast natively on UndergradRequestorVerification::$casts (Laravel's
 * built-in enum casting), rather than left as bare string constants —
 * every read/write of this column goes through this enum, so a typo'd
 * status string is a compile-time/IDE-catchable error rather than a
 * silent runtime mismatch. This is a deliberate, small upgrade over the
 * plain-string-constant pattern RequestRemark::STATUS_* uses; the two
 * are not required to match, since RequestRemark predates this
 * decision.
 *
 * Lifecycle (Admin-decision-final, per D6 — no automated transitions):
 *   Pending  -> Approved   (Phase 4 approve action; unlocks Phase 3's
 *                           SSO auto-activation and Phase 5's
 *                           request-flow gate)
 *   Pending  -> Rejected   (Phase 4 reject action, requires
 *                           rejection_reason; triggers Phase 3's
 *                           AccountRejectedException + IdP token
 *                           revocation on next login attempt)
 *
 * Referenced by:
 *   - UndergradRequestorRegistrationService (creates a row at Pending)
 *   - UserProvisioningService::provision() (Phase 3 — branches on
 *     Approved/Rejected)
 *   - The Admin verification queue/decision endpoints (Phase 4)
 *   - The request-flow gate middleware (Phase 5 — requires Approved)
 */
enum UndergradRequestorVerificationStatusEnum: string
{
    case Pending  = 'Pending';
    case Approved = 'Approved';
    case Rejected = 'Rejected';

    /**
     * Human-readable label for Admin-facing UI (verification queue
     * status badges, notification templates) — same role as
     * DeficiencyItemEnum::label() / WithdrawalReasonEnum::label().
     */
    public function label(): string
    {
        return match ($this) {
            self::Pending  => 'Pending Review',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
        };
    }
}
