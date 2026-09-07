<?php

namespace App\Enums;

/**
 * Data Retention & Disposal Policy — Section 3.4 ("Requests That Can
 * Never Be Resolved").
 *
 * Single source of truth for document_request.closure_reason — the
 * fixed reason list for closing a request under RequestStatusEnum::
 * ClosedUnableToProcess. Mirrors WithdrawalReasonEnum's exact
 * convention (see that enum's docblock): a type-safe enum backing a
 * plain string DB column rather than a MySQL ENUM column, so a future
 * reason never needs a migration to add — only a new case here.
 *
 * IMPORTANT DISTINCTION FROM WithdrawalReasonEnum: Withdrawn (Phase 1)
 * closes out a request that was a mistake or is no longer wanted —
 * an ordinary, low-stakes administrative correction. ClosedUnableToProcess
 * closes out a request whose open Deficiency Notice can never be
 * complied with because the requestor is deceased or otherwise
 * permanently unable to respond — see the Data Retention & Disposal
 * Policy §3.4's "WORST-CASE SCENARIO" framing. The two are deliberately
 * separate enums (not one shared list) so a staff member cannot
 * accidentally select "duplicate submission" while closing a case that
 * legally requires proof of death/incapacity, and so validation can
 * require closure_proof_reference only on this path, not on an
 * ordinary Withdrawn.
 *
 * Referenced by:
 *   - CloseRequestUnableToProcessRequest::rules() (validates the value
 *     is one of these cases, and that closure_detail is present when
 *     Other)
 *   - DocumentRequestService::closeUnableToProcess() (resolves the
 *     human-readable label() for the request_closed_unable_to_process
 *     notification's :closure_reason placeholder)
 */
enum ClosureReasonEnum: string
{
    /**
     * The requestor is deceased. Requires closure_proof_reference to
     * describe the proof received (e.g. "Death certificate submitted
     * by next of kin, verified 2026-09-10") — see the Data Retention &
     * Disposal Policy §3.4, item 1's required annotation
     * ("Requestor Deceased — Deficiency Notice Unresolved").
     */
    case RequestorDeceased = 'requestor_deceased';

    /**
     * The requestor is alive but permanently unable to respond to the
     * Deficiency Notice (e.g. documented incapacity) — the policy's
     * "or is otherwise permanently unable to comply" clause alongside
     * the deceased-requestor case.
     */
    case RequestorIncapacitated = 'requestor_incapacitated';

    /**
     * Catch-all for a permanently-unresolvable circumstance that
     * doesn't fit the above. REQUIRES closure_detail to be filled in —
     * see CloseRequestUnableToProcessRequest::withValidator().
     */
    case Other = 'other';

    /**
     * Human-readable label used to build the
     * request_closed_unable_to_process notification's :closure_reason
     * placeholder. For Other, the caller substitutes the staff-entered
     * closure_detail text instead — same "Other" handling
     * WithdrawalReasonEnum::label() and DeficiencyItemEnum::label()
     * already establish.
     */
    public function label(): string
    {
        return match ($this) {
            self::RequestorDeceased      => 'Requestor deceased',
            self::RequestorIncapacitated => 'Requestor permanently unable to respond',
            self::Other                  => 'Other',
        };
    }
}
