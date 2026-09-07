<?php

namespace App\Contracts;

use App\Enums\RequestChannelEnum;
use App\Models\DocumentRequest;
use App\Models\SystemUser;

/**
 * Contract for the document-request business logic layer.
 *
 * Bind in AppServiceProvider so callers depend on this interface,
 * not the concrete DocumentRequestService class.
 * This lets you swap the implementation (e.g. for testing) without
 * touching any controller or other service.
 */
interface DocumentRequestServiceInterface
{
    /**
     * Create a new document request for a student or alumni user,
     * attach its document and certificate line-items, and send
     * the relevant notifications.
     *
     * $channel defaults to SelfService — every pre-existing call site
     * (the student/alumni Request pages) keeps creating self-service
     * requests unchanged. FESPEC-0008's FreeRequestService is the only
     * caller that ever passes AdminFiledFree, so the whole write —
     * parent row, line items, release groups, AND the channel itself —
     * lands inside the exact same transaction createRequest() already
     * wraps everything else in, rather than a second, separate update
     * after the fact that could theoretically leave a request created
     * with the wrong channel if it failed.
     */
    public function createRequest(SystemUser $user, array $validated, RequestChannelEnum $channel = RequestChannelEnum::SelfService): DocumentRequest;

    /**
     * Update a document request (status, OR number, receipt date).
     * Writes history on status change and notifies the owner.
     */
    public function updateRequest(DocumentRequest $documentRequest, array $validated): DocumentRequest;

    /**
     * Claim a request via QR scan (uuid) or manual entry (claim_code).
     *
     * Looks the request up by whichever credential is supplied, then
     * delegates the actual Completed transition to updateRequest() — so
     * the row lock, archived-is-read-only guard, and allowedTransitions()
     * check all apply exactly as they do for a manual admin status change.
     *
     * @throws \Illuminate\Http\Exceptions\HttpResponseException 404 if no
     *         matching request exists; propagates whatever updateRequest()
     *         throws (422) if the request isn't currently ReadyToClaim.
     */
    public function claimRequest(array $credential): DocumentRequest;

    /**
     * Archive a single request. Reversible — does not touch status_id.
     * Any authorized admin may archive a request regardless of its
     * current status (see Archive Eligibility Policy – Administrator).
     */
    public function archiveRequest(DocumentRequest $documentRequest, SystemUser $actor): DocumentRequest;

    /**
     * Restore a single archived request. Its status_id is left exactly
     * as it was when archived — restoring never changes status.
     */
    public function restoreRequest(DocumentRequest $documentRequest, SystemUser $actor): DocumentRequest;

    /**
     * Archive many requests by id in one call.
     *
     * @param  int[] $requestIds
     * @return array{archived: int[], skipped: int[]} ids actually archived vs. ids
     *         that didn't exist / were already archived
     */
    public function archiveRequests(array $requestIds, SystemUser $actor): array;

    /**
     * Restore many archived requests by id in one call.
     *
     * @param  int[] $requestIds
     * @return array{restored: int[], skipped: int[]}
     */
    public function restoreRequests(array $requestIds, SystemUser $actor): array;

    /**
     * Deficiency Notice & Withdrawn Status — Phase 1.
     *
     * Withdraw a request that will never be fulfilled — wrong item paid,
     * a duplicate submission, or the requestor no longer needing it (see
     * WithdrawalReasonEnum). Staff-mediated only; a separate, dedicated
     * method from updateRequest() (same reasoning as archiveRequest()
     * being separate) because it carries its own required reason field,
     * its own optional superseded_by_request_id, and always requires
     * exactly the 'Process' dashboard action rather than the
     * status-dependent set updateRequest() computes.
     *
     * Reachable only from AwaitingSubmission, Processing, or
     * PendingSignature (see RequestStatusEnum::allowedTransitions()) —
     * never from ReadyToClaim, which resolves via claim/forfeit instead.
     * The paid OR (or_number/receipt_date) is left untouched for finance
     * reconciliation.
     *
     * @param array{
     *     withdrawal_reason: string,
     *     withdrawal_detail?: string|null,
     *     superseded_by_request_id?: int|null,
     * } $data
     * @throws \Illuminate\Http\Exceptions\HttpResponseException 422 if the
     *         request is archived, its current status cannot transition
     *         to Withdrawn, or superseded_by_request_id doesn't reference
     *         an existing request.
     */
    public function withdraw(DocumentRequest $documentRequest, array $data): DocumentRequest;

    /**
     * Data Retention & Disposal Policy — Section 3.4. Closes a request
     * as ClosedUnableToProcess — see DocumentRequestService::
     * closeUnableToProcess() for full behavior.
     *
     * @throws \Illuminate\Http\Exceptions\HttpResponseException 422 if the
     *         request is archived, its current status cannot transition
     *         to ClosedUnableToProcess, or it has no open Deficiency Notice.
     */
    public function closeUnableToProcess(DocumentRequest $documentRequest, array $data): DocumentRequest;
}