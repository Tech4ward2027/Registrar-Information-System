<?php

namespace App\Http\Controllers;

use App\Http\Requests\DocumentType\ArchiveDocumentTypeRequest;
use App\Http\Requests\DocumentType\StoreDocumentTypeRequest;
use App\Http\Requests\DocumentType\UpdateDocumentTypeRequest;
use App\Models\AuditLog;
use App\Models\DocumentType;
use App\Models\SystemUser;
use App\Services\AuditLogger;
use App\Services\CashierPatternSanitizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class DocumentTypeController extends Controller
{
    public function __construct(private AuditLogger $auditLogger) {}

    /**
     * List document types.
     *
     * Excludes archived items by default: this endpoint is shared by both
     * the admin catalog management screen and the live student/alumni
     * request form (RequestForm.jsx, useAlumniRequest.js). The request
     * form has no way to distinguish archived from active items, so an
     * unfiltered list here means archived legacy document types remain
     * selectable — and payable at the cashier — for new requests.
     *
     * Admin/registrar (role 3/4 — same roles already gated on store/
     * update/archive/restore below) can pass ?include_archived=1 to see
     * archived rows for management/restore purposes.
     */
    public function index(Request $request)
    {
        $includeArchived = $request->boolean('include_archived')
            && Auth::user()
            && in_array((int) Auth::user()->role_id, [3, 4], true);

        $query = DocumentType::query();

        if (!$includeArchived) {
            $query->where('is_archived', false);
        }

        return response()->json($query->get(), 200);
    }

    public function show($id)
    {
        $docType = DocumentType::find($id);
        if (!$docType) {
            return response()->json(['message' => 'Document type not found'], 404);
        }

        return response()->json($docType, 200);
    }

    public function store(StoreDocumentTypeRequest $request)
    {
        $validated = $this->withSanitizedPatterns($request->validated());

        $docType = DocumentType::create($validated);

        /** @var SystemUser $actor */
        $actor = Auth::user();

        $this->auditLogger->log($request, $actor, AuditLog::ACTION_DOCUMENT_TYPE_CREATED, [
            'document_type_id'          => $docType->document_type_id,
            'document_name'             => $docType->document_name,
            'cashier_document_patterns' => $docType->cashier_document_patterns,
            'fulfillment_track_id'      => $docType->fulfillment_track_id,
            'logbook_category_id'       => $docType->logbook_category_id,
        ]);

        return response()->json($docType, 201);
    }

    public function update(UpdateDocumentTypeRequest $request, $id)
    {
        $docType = DocumentType::find($id);
        if (!$docType) {
            return response()->json(['message' => 'Document type not found'], 404);
        }

        $validated = $this->withSanitizedPatterns($request->validated());
        $docType->update($validated);

        /** @var SystemUser $actor */
        $actor = Auth::user();

        $this->auditLogger->log($request, $actor, AuditLog::ACTION_DOCUMENT_TYPE_UPDATED, [
            'document_type_id'          => $docType->document_type_id,
            'document_name'             => $docType->document_name,
            'changed_fields'            => array_keys($validated),
            'cashier_document_patterns' => $docType->cashier_document_patterns,
            'fulfillment_track_id'      => $docType->fulfillment_track_id,
            'logbook_category_id'       => $docType->logbook_category_id,
        ]);

        return response()->json($docType, 200);
    }

    public function destroy(Request $request, $id)
    {
        $docType = DocumentType::find($id);
        if (!$docType) {
            return response()->json(['message' => 'Document type not found'], 404);
        }

        try {
            $docType->delete();
        } catch (\Illuminate\Database\QueryException $e) {
            // MySQL error 1451 — FK constraint violation
            if ($e->getCode() === '23000') {
                return response()->json([
                    'message' => 'Cannot delete a document type that is referenced by existing document requests.',
                ], 409);
            }

            throw $e;
        }

        /** @var SystemUser $actor */
        $actor = Auth::user();

        $this->auditLogger->log($request, $actor, AuditLog::ACTION_DOCUMENT_TYPE_DELETED, [
            'document_type_id' => $docType->document_type_id,
            'document_name'    => $docType->document_name,
        ]);

        return response()->json(['message' => 'Document type deleted'], 200);
    }

    // -------------------------------------------------------------------------
    // Archive / Restore — reversible, distinct from destroy() above.
    //
    // Per the Archive Policy — Document & Certificate Management:
    //   - A type may only be archived if no request using it is still
    //     Processing or Ready to Claim ("active"). Finished/forfeited
    //     requests don't block archiving.
    //   - Every archive/restore records who did it, when, and (for
    //     archives) why.
    // -------------------------------------------------------------------------

    public function archive(ArchiveDocumentTypeRequest $request, $id)
    {
        $docType = DocumentType::find($id);
        if (!$docType) {
            return response()->json(['message' => 'Document type not found'], 404);
        }

        if ($docType->is_archived) {
            return response()->json($docType, 200);
        }

        $activeCount = $docType->activeRequestsCount();
        if ($activeCount > 0) {
            return response()->json([
                'message' => sprintf(
                    '%d active %s using this — can\'t archive yet.',
                    $activeCount,
                    $activeCount === 1 ? 'request is' : 'requests are'
                ),
                'active_requests' => $activeCount,
            ], 422);
        }

        $validated = $request->validated();

        /** @var SystemUser $actor */
        $actor = Auth::user();

        DB::transaction(function () use ($docType, $actor) {
            $docType->update([
                'is_archived' => true,
                'archived_on' => now(),
                'archived_by' => $actor->user_id,
            ]);
        });

        $this->auditLogger->log($request, $actor, AuditLog::ACTION_DOCUMENT_TYPE_ARCHIVED, [
            'document_type_id' => $docType->document_type_id,
            'document_name'    => $docType->document_name,
            'reason'           => $validated['reason'] ?? null,
        ]);

        return response()->json($docType->fresh(), 200);
    }

    public function restore(Request $request, $id)
    {
        $docType = DocumentType::find($id);
        if (!$docType) {
            return response()->json(['message' => 'Document type not found'], 404);
        }

        if (!$docType->is_archived) {
            return response()->json($docType, 200);
        }

        /** @var SystemUser $actor */
        $actor = Auth::user();

        DB::transaction(function () use ($docType) {
            $docType->update([
                'is_archived' => false,
                'archived_on' => null,
                'archived_by' => null,
            ]);
        });

        $this->auditLogger->log($request, $actor, AuditLog::ACTION_DOCUMENT_TYPE_RESTORED, [
            'document_type_id' => $docType->document_type_id,
            'document_name'    => $docType->document_name,
        ]);

        return response()->json($docType->fresh(), 200);
    }

    /**
     * If the validated payload included cashier_document_patterns, replace
     * it with the sanitized version (trimmed, blanks dropped, de-duplicated
     * by normalised form — see CashierPatternSanitizer) before it's ever
     * written to the DB.
     *
     * By the time we get here, App\Rules\CashierPatternsAreConflictFree has
     * already rejected blanks, in-submission duplicates, and cross-type
     * conflicts as validation errors — this is a final, idempotent cleanup
     * pass (e.g. trimming stray whitespace), not the primary line of
     * defence. Left absent entirely from $validated when the client didn't
     * send the field at all, so a partial update() never accidentally
     * clears existing patterns.
     */
    private function withSanitizedPatterns(array $validated): array
    {
        if (array_key_exists('cashier_document_patterns', $validated)) {
            $validated['cashier_document_patterns'] = CashierPatternSanitizer::sanitize(
                $validated['cashier_document_patterns'] ?? []
            );
        }

        return $validated;
    }
}