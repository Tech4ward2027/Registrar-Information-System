<?php

namespace App\Enums;

/**
 * Deficiency Notice & Withdrawn Status — Phase 3.
 *
 * Single source of truth for request_remarks.item_key — the fixed
 * missing-item list agreed in Phase 0 of the implementation plan.
 * Mirrors WithdrawalReasonEnum's exact convention (see that enum's
 * docblock): a type-safe enum backing a plain string DB column rather
 * than a MySQL ENUM column, so a future item type never needs a
 * migration to add — only a new case here.
 *
 * No transition graph on this enum (unlike RequestStatusEnum) — a
 * Deficiency Notice's lifecycle is open → cleared | voided, tracked by
 * request_remarks.status, not by which item was flagged. item_key never
 * changes once a notice is issued.
 *
 * Referenced by:
 *   - IssueDeficiencyNoticeRequest::rules() (validates the value is one
 *     of these cases, and that detail is present when Other)
 *   - DeficiencyNoticeService::issue() (resolves label() into the
 *     denormalized request_remarks.item_label column at issue time —
 *     see that migration's docblock for why item_label is stored
 *     rather than resolved on every read)
 */
enum DeficiencyItemEnum: string
{
    /**
     * The request is missing a required signature (e.g. from a
     * department head or dean) before registrar processing can
     * continue.
     */
    case MissingSignature = 'missing_signature';

    /**
     * The requestor has not yet submitted a valid ID needed to verify
     * their identity or claim eligibility.
     */
    case MissingValidId = 'missing_valid_id';

    /**
     * Catch-all for a missing item that doesn't fit the above.
     * REQUIRES request_remarks.detail to be filled in — see
     * IssueDeficiencyNoticeRequest::withValidator().
     */
    case Other = 'other';

    /**
     * Human-readable label written into request_remarks.item_label at
     * issue time, and used to build the deficiency_notice_issued
     * notification's :item_label placeholder (see NotificationService::
     * buildMessage()'s substitution and DeficiencyNoticeService::issue()).
     * For Other, the caller substitutes the staff-entered detail text
     * instead of this generic label — same "Other" handling
     * WithdrawalReasonEnum::label() already establishes.
     */
    public function label(): string
    {
        return match ($this) {
            self::MissingSignature => 'Missing Signature',
            self::MissingValidId   => 'Missing Valid ID',
            self::Other            => 'Other',
        };
    }
}
