<?php

namespace App\Enums;

/**
 * Deficiency Notice & Withdrawn Status — Phase 1.
 *
 * Single source of truth for document_request.withdrawal_reason — the
 * fixed reason list agreed in Phase 0 of the implementation plan. Mirrors
 * the convention already established by RequestChannelEnum: a type-safe
 * enum backing a plain string DB column, rather than a MySQL ENUM column
 * (see RequestChannelEnum's docblock and the job_run_logs migration for
 * why this project avoids DB-level enums — a future reason should never
 * need a migration to add, only a new case here).
 *
 * WHY A SEPARATE withdrawal_detail COLUMN EXISTS ALONGSIDE THIS ENUM:
 * The implementation plan's Phase 2 frontend spec calls for "reason
 * dropdown (+ required free text when other)", but only ever lists a
 * single `withdrawal_reason` column on document_request. That leaves no
 * place to store the free text a staff member types when they pick
 * Other — exactly the same problem Phase 3's Deficiency Notice design
 * solves with its own two-column split (request_remarks.item_key, the
 * machine code, vs. request_remarks.detail, the free text required only
 * when item_key = 'other'). For consistency with that established
 * pattern, and so a staff member's typed explanation is never silently
 * dropped, this feature adds `withdrawal_detail` (nullable text) as a
 * companion column — see the add_withdrawn_status migration and
 * DocumentRequestService::withdraw()'s validation of it.
 *
 * Referenced by:
 *   - WithdrawDocumentRequestRequest::rules() (validates the value is one
 *     of these cases, and that withdrawal_detail is present when Other)
 *   - DocumentRequestService::withdraw() (resolves the human-readable
 *     label() for the request_withdrawn notification's :withdrawal_reason
 *     placeholder)
 */
enum WithdrawalReasonEnum: string
{
    /**
     * The student/alumni paid for and/or requested the wrong document or
     * certificate type at the cashier. The paid OR stays attached to
     * this (now withdrawn) request for finance reconciliation — see
     * DocumentRequestService::withdraw()'s docblock.
     */
    case WrongItemPaid = 'wrong_item_paid';

    /**
     * The requestor accidentally filed the same request twice (e.g. a
     * double-submit on the request form, or a resubmission meant to
     * correct a mistake in the original). superseded_by_request_id
     * should normally be set alongside this reason, pointing at the
     * corrected/duplicate request that actually proceeds.
     */
    case DuplicateSubmission = 'duplicate_submission';

    /**
     * The requestor no longer needs the document(s)/certificate(s) —
     * e.g. they found another way to satisfy whatever required the
     * document, or their plans changed.
     */
    case StudentNoLongerNeeds = 'student_no_longer_needs';

    /**
     * Catch-all for a withdrawal reason that doesn't fit the above.
     * REQUIRES withdrawal_detail to be filled in — see
     * WithdrawDocumentRequestRequest::withValidator().
     */
    case Other = 'other';

    /**
     * Human-readable label used to build the request_withdrawn
     * notification's message (see NotificationService::buildMessage()'s
     * :placeholder substitution). For Other, the caller substitutes the
     * staff-entered withdrawal_detail text instead of this generic label
     * — see DocumentRequestService::withdraw().
     */
    public function label(): string
    {
        return match ($this) {
            self::WrongItemPaid        => 'Wrong item was paid for',
            self::DuplicateSubmission  => 'Duplicate submission',
            self::StudentNoLongerNeeds => 'No longer needed',
            self::Other                => 'Other',
        };
    }
}
