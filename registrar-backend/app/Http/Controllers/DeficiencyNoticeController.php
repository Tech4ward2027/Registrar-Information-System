<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\DocumentRequest;
use App\Models\RequestRemark;
use App\Models\SystemUser;
use App\Http\Requests\DeficiencyNotice\ClearDeficiencyNoticeRequest;
use App\Http\Requests\DeficiencyNotice\IssueDeficiencyNoticeRequest;
use App\Http\Requests\DeficiencyNotice\VoidDeficiencyNoticeRequest;
use App\Services\AuditLogger;
use App\Services\DeficiencyNoticeService;
use Illuminate\Support\Facades\Auth;

/**
 * Deficiency Notice & Withdrawn Status — Phase 3.
 *
 * Thin HTTP adapter for the Deficiency Notice resource (request_remarks)
 * — same division of responsibility DocumentRequestController follows:
 * validate input (via the FormRequest classes, whose authorize() methods
 * replace any explicit $this->authorize() call), delegate to
 * DeficiencyNoticeService, audit-log, return JSON. All business logic
 * (transaction/locking, guards, notifications) lives in
 * DeficiencyNoticeService.
 *
 * Kept as its own controller (rather than more methods on
 * DocumentRequestController) because request_remarks is its own
 * resource with its own route-model binding on two of its three routes
 * (clear()/void() act on a RequestRemark, not a DocumentRequest) — same
 * reasoning RequestDocumentController already exists as a separate
 * controller from DocumentRequestController for request_document.
 */
class DeficiencyNoticeController extends Controller
{
    private const RELATIONS = [
        'issuedByUser',
        'clearedByUser',
        'voidedByUser',
    ];

    public function __construct(
        private DeficiencyNoticeService $deficiencyNoticeService,
        private AuditLogger             $auditLogger,
    ) {}

    // -------------------------------------------------------------------------
    // POST /document-requests/{documentRequest}/deficiency-notices
    // -------------------------------------------------------------------------
    public function issue(IssueDeficiencyNoticeRequest $request, DocumentRequest $documentRequest)
    {
        $validated = $request->validated();

        /** @var SystemUser $actor */
        $actor = Auth::user();

        $remark = $this->deficiencyNoticeService->issue($documentRequest, $validated);

        $this->auditLogger->log($request, $actor, AuditLog::ACTION_DEFICIENCY_NOTICE_ISSUED, [
            'request_id' => $remark->request_id,
            'remark_id'  => $remark->remark_id,
            'item_key'   => $remark->item_key,
        ]);

        return response()->json($remark->load(self::RELATIONS), 201);
    }

    // -------------------------------------------------------------------------
    // POST /deficiency-notices/{deficiencyNotice}/clear
    //
    // Route parameter is named {deficiencyNotice} (not {requestRemark})
    // for a clearer, resource-facing URL — Laravel's implicit route-
    // model binding resolves by matching this parameter name to the
    // controller argument name below, not by the model's class name, so
    // RequestRemark $deficiencyNotice below is what makes that binding
    // work.
    // -------------------------------------------------------------------------
    public function clear(ClearDeficiencyNoticeRequest $request, RequestRemark $deficiencyNotice)
    {
        /** @var SystemUser $actor */
        $actor = Auth::user();

        $remark = $this->deficiencyNoticeService->clear($deficiencyNotice);

        $this->auditLogger->log($request, $actor, AuditLog::ACTION_DEFICIENCY_NOTICE_CLEARED, [
            'request_id' => $remark->request_id,
            'remark_id'  => $remark->remark_id,
        ]);

        return response()->json($remark->load(self::RELATIONS), 200);
    }

    // -------------------------------------------------------------------------
    // POST /deficiency-notices/{deficiencyNotice}/void
    // -------------------------------------------------------------------------
    public function void(VoidDeficiencyNoticeRequest $request, RequestRemark $deficiencyNotice)
    {
        $validated = $request->validated();

        /** @var SystemUser $actor */
        $actor = Auth::user();

        $remark = $this->deficiencyNoticeService->void($deficiencyNotice, $validated);

        $this->auditLogger->log($request, $actor, AuditLog::ACTION_DEFICIENCY_NOTICE_VOIDED, [
            'request_id'  => $remark->request_id,
            'remark_id'   => $remark->remark_id,
            'void_reason' => $remark->void_reason,
        ]);

        return response()->json($remark->load(self::RELATIONS), 200);
    }
}
