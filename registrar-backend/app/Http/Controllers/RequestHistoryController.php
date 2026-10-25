<?php

namespace App\Http\Controllers;

use App\Http\Resources\RequestHistoryResource;
use App\Models\RequestHistory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Request history — READ ONLY.
 *
 * History is an immutable audit trail written exclusively by
 * DocumentRequestService::updateRequest(). It must never be
 * created, modified, or deleted via the API.
 */
class RequestHistoryController extends Controller
{
    private const DEFAULT_PER_PAGE = 25;
    private const MAX_PER_PAGE     = 100;

    private const RELATIONS = ['request', 'oldStatus', 'newStatus', 'changedBy'];

    // -------------------------------------------------------------------------
    // GET /request-history?page=1&per_page=25
    //
    // Paginated: the table grows with every status change, so returning all of
    // it in one response does not scale. Same response shape as
    // GET /request-documents (Laravel paginator: data, current_page, last_page,
    // per_page, total, ...). The secondary sort on the primary key makes page
    // boundaries stable when several rows share a changed_at.
    // -------------------------------------------------------------------------
    public function index(Request $request): JsonResponse
    {
        $perPage = (int) $request->integer('per_page', self::DEFAULT_PER_PAGE);
        $perPage = max(1, min($perPage, self::MAX_PER_PAGE));

        $page = RequestHistory::with(self::RELATIONS)
            ->orderByDesc('changed_at')
            ->orderByDesc('request_history_id')
            ->paginate($perPage);

        $page->setCollection(
            $page->getCollection()->map(fn (RequestHistory $row) => (new RequestHistoryResource($row))->resolve())
        );

        return response()->json($page, 200);
    }

    // -------------------------------------------------------------------------
    // GET /request-history/{id}
    // -------------------------------------------------------------------------
    public function show($id): JsonResponse
    {
        $history = RequestHistory::with(self::RELATIONS)->find($id);

        if (!$history) {
            return response()->json(['message' => 'History not found'], 404);
        }

        return (new RequestHistoryResource($history))->response()->setStatusCode(200);
    }
}