<?php

namespace App\Http\Controllers;

use App\Http\Requests\Opcr\OpcrPerformanceRequest;
use App\Services\OpcrPerformanceService;
use Illuminate\Http\JsonResponse;

/**
 * GET /opcr/performance — average processing time per service category for a
 * rating period (OPCR Timeliness). Read-only. Route middleware
 * (role:3,4 + module:logbook,View) is the access gate; see routes/api.php.
 */
class OpcrPerformanceController extends Controller
{
    public function __construct(private OpcrPerformanceService $performance) {}

    public function index(OpcrPerformanceRequest $request): JsonResponse
    {
        $validated = $request->validated();

        return response()->json(
            $this->performance->report($validated['from'], $validated['to'], $request->includeArchived()),
            200
        );
    }
}
