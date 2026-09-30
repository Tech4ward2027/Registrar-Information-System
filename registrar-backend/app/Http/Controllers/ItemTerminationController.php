<?php

namespace App\Http\Controllers;

use App\Http\Requests\DocumentRequest\CloseRequestUnableToProcessRequest;
use App\Http\Requests\DocumentRequest\WithdrawRequestItemRequest;
use App\Models\AuditLog;
use App\Models\DocumentRequest;
use App\Models\RequestCertificate;
use App\Models\RequestDocument;
use App\Models\SystemUser;
use App\Services\AuditLogger;
use App\Services\RequestItemTerminationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Per-item Withdraw and Close - Unable to Process:
 *
 *   POST /document-requests/{request}/documents/{document}/withdraw
 *   POST /document-requests/{request}/documents/{document}/close-unable-to-process
 *   POST /document-requests/{request}/certificates/{certificate}/withdraw
 *   POST /document-requests/{request}/certificates/{certificate}/close-unable-to-process
 *
 * Thin adapter, same division of labour as the whole-request actions:
 * validation and the policy check live in the FormRequests, every rule in
 * RequestItemTerminationService, and every action is audit-logged here,
 * unconditionally.
 *
 * Route-model binding does not scope {item} to {request}, so a mismatched
 * URL is rejected explicitly with a 404, as the item status endpoints do.
 */
class ItemTerminationController extends Controller
{
    public function __construct(
        private RequestItemTerminationService $terminations,
        private AuditLogger                   $auditLogger,
    ) {}

    public function withdrawDocument(WithdrawRequestItemRequest $request, DocumentRequest $documentRequest, RequestDocument $requestDocument): JsonResponse
    {
        $this->assertBelongs($documentRequest, $requestDocument);

        $result = $this->terminations->withdrawItem($requestDocument, $request->validated());

        return $this->respond($request, $result, AuditLog::ACTION_ITEM_WITHDRAWN, 'document');
    }

    public function withdrawCertificate(WithdrawRequestItemRequest $request, DocumentRequest $documentRequest, RequestCertificate $requestCertificate): JsonResponse
    {
        $this->assertBelongs($documentRequest, $requestCertificate);

        $result = $this->terminations->withdrawItem($requestCertificate, $request->validated());

        return $this->respond($request, $result, AuditLog::ACTION_ITEM_WITHDRAWN, 'certificate');
    }

    public function closeDocument(CloseRequestUnableToProcessRequest $request, DocumentRequest $documentRequest, RequestDocument $requestDocument): JsonResponse
    {
        $this->assertBelongs($documentRequest, $requestDocument);

        $result = $this->terminations->closeItem($requestDocument, $request->validated());

        return $this->respond($request, $result, AuditLog::ACTION_ITEM_CLOSED_UNABLE_TO_PROCESS, 'document');
    }

    public function closeCertificate(CloseRequestUnableToProcessRequest $request, DocumentRequest $documentRequest, RequestCertificate $requestCertificate): JsonResponse
    {
        $this->assertBelongs($documentRequest, $requestCertificate);

        $result = $this->terminations->closeItem($requestCertificate, $request->validated());

        return $this->respond($request, $result, AuditLog::ACTION_ITEM_CLOSED_UNABLE_TO_PROCESS, 'certificate');
    }

    private function assertBelongs(DocumentRequest $documentRequest, RequestDocument|RequestCertificate $item): void
    {
        if ((int) $item->request_id !== (int) $documentRequest->request_id) {
            abort(404, 'This item does not belong to the specified request.');
        }
    }

    /**
     * @param array{item: RequestDocument|RequestCertificate, request: DocumentRequest, request_left: bool, auto_voided_deficiency_notice_id: int|null} $result
     */
    private function respond(Request $http, array $result, string $auditAction, string $type): JsonResponse
    {
        /** @var SystemUser $actor */
        $actor = Auth::user();
        $item  = $result['item'];
        $req   = $result['request'];

        $item->load($type === 'document' ? ['documentType', 'status', 'termination'] : ['certificationType', 'status', 'termination']);

        // The proof reference (for example a death certificate description)
        // is sensitive. The person who just entered it does not need it
        // echoed back, and it stays out of every item list.
        $item->termination?->makeHidden('proof_reference');

        $termination = $item->termination;

        $this->auditLogger->log($http, $actor, $auditAction, [
            'request_id'                         => $req->request_id,
            'item_type'                          => $type,
            'item_id'                            => $item->getKey(),
            'reason'                             => $termination?->reason,
            'proof_reference'                    => $termination?->getOriginal('proof_reference'),
            'request_left'                       => $result['request_left'],
            'request_status_id'                  => (int) $req->status_id,
            'auto_voided_deficiency_notice_id'   => $result['auto_voided_deficiency_notice_id'],
        ]);

        // A notice voided because the request just ended gets its own entry,
        // as the whole-request withdraw does, so notice history stays complete.
        foreach ($result['auto_voided_deficiency_notice_ids'] ?? [] as $voidedId) {
            $this->auditLogger->log($http, $actor, AuditLog::ACTION_DEFICIENCY_NOTICE_VOIDED, [
                'request_id'  => $req->request_id,
                'remark_id'   => $voidedId,
                'auto_voided' => true,
            ]);
        }

        return response()->json([
            'item'                             => $item,
            'request'                          => [
                'request_id' => $req->request_id,
                'status_id'  => (int) $req->status_id,
            ],
            'request_left'                     => $result['request_left'],
            'auto_voided_deficiency_notice_id' => $result['auto_voided_deficiency_notice_id'],
            'auto_voided_deficiency_notice_ids' => $result['auto_voided_deficiency_notice_ids'] ?? [],
        ], 200);
    }
}