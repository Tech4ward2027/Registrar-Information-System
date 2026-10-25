<?php

namespace App\Http\Controllers;

use App\Http\Requests\RequestDocument\StoreRequestDocumentRequest;
use App\Http\Requests\RequestDocument\UpdateRequestDocumentRequest;
use App\Models\DocumentRequest;
use App\Models\RequestDocument;
use App\Models\SystemUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Request document line-items.
 *
 * Explicit validation on all mutations — no $request->all() mass assignment.
 *
 * READ ACCESS (index / show)
 * --------------------------
 * These two endpoints sit in the shared authenticated route group, so any
 * role can reach them. Row-level access is therefore enforced HERE, through
 * one shared query (visibleQuery()), so index and show can never drift apart:
 *
 *   - Staff (Admin / Super Admin) with dashboard "View" access: every row.
 *   - Everyone else (Student / Alumni / Undergrad Requestor): only line
 *     items whose parent request they own.
 *   - A row the caller may not see answers 404, not 403, so the endpoint
 *     cannot be used to probe which ids exist.
 *
 * The parent request is eager-loaded under the relation's real name,
 * `documentRequest` (the model has no `request` relation).
 */
class RequestDocumentController extends Controller
{
    private const DEFAULT_PER_PAGE = 25;
    private const MAX_PER_PAGE     = 100;

    // GET /request-documents
    public function index(Request $request): JsonResponse
    {
        $perPage = (int) $request->integer('per_page', self::DEFAULT_PER_PAGE);
        $perPage = max(1, min($perPage, self::MAX_PER_PAGE));

        return response()->json(
            $this->visibleQuery()
                ->orderByDesc('request_document_id')
                ->paginate($perPage),
            200
        );
    }

    // GET /request-documents/{id}
    public function show($id): JsonResponse
    {
        $reqDoc = $this->visibleQuery()->findOrFail($id);

        return response()->json($reqDoc, 200);
    }

    public function store(StoreRequestDocumentRequest $request)
    {
        $validated = $request->validated();

        // Ensure the authenticated student/alumni owns the parent request.
        // Without this check any student could append line-items to another
        // student's request by guessing the integer request_id.
        DocumentRequest::where('request_id', $validated['request_id'])
            ->where('user_id', Auth::id())
            ->firstOrFail();

        $reqDoc = RequestDocument::create($validated);
        return response()->json($reqDoc, 201);
    }

    public function update(UpdateRequestDocumentRequest $request, $id)
    {
        $reqDoc = RequestDocument::findOrFail($id);

        $reqDoc->update($request->validated());
        return response()->json($reqDoc, 200);
    }

    public function destroy($id)
    {
        $reqDoc = RequestDocument::findOrFail($id);

        try {
            $reqDoc->delete();
        } catch (\Illuminate\Database\QueryException $e) {
            if ($e->getCode() === '23000') {
                return response()->json([
                    'message' => 'Cannot delete this request document because it is still referenced elsewhere.',
                ], 409);
            }

            throw $e;
        }

        return response()->json(['message' => 'Request document deleted'], 200);
    }

    /**
     * The single source of truth for "which line items may this caller read".
     *
     * @return Builder<RequestDocument>
     */
    private function visibleQuery(): Builder
    {
        $user = Auth::user();

        if (!$user instanceof SystemUser) {
            abort(401, 'Unauthenticated.');
        }

        $query = RequestDocument::query()->with(['documentRequest', 'documentType']);

        if ($user->isStaff()) {
            // Same coarse gate the /document-requests read routes apply
            // ('module:dashboard,View'). An admin whose policy grants no
            // dashboard access must not be able to read the same data here.
            if (!$user->hasModuleAccess('dashboard', 'View')) {
                abort(403, 'You do not have access to this resource.');
            }

            return $query;
        }

        return $query->whereHas(
            'documentRequest',
            fn (Builder $q) => $q->where('user_id', $user->user_id)
        );
    }
}