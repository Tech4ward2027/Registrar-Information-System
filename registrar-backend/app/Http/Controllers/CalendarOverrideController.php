<?php

namespace App\Http\Controllers;

use App\Http\Requests\CalendarOverride\StoreCalendarOverrideRequest;
use App\Http\Requests\CalendarOverride\UpdateCalendarOverrideRequest;
use App\Http\Requests\PaginatedIndexRequest;
use App\Models\BusinessCalendarOverride;
use App\Services\CalendarOverrideService;
use Illuminate\Http\Request;

/**
 * Admin/superadmin CRUD for recurring, time-bound overrides (e.g. "closed
 * every Monday, effective <date>, until further notice"). Same gating as
 * CalendarExceptionController — see that class's docblock.
 */
class CalendarOverrideController extends Controller
{
    /** The Business Calendar screen loads the full list in one request (per_page=1000). */
    private const MAX_PER_PAGE = 1000;

    public function __construct(
        private CalendarOverrideService $overrideService,
    ) {}

    public function index(PaginatedIndexRequest $request)
    {
        $query = BusinessCalendarOverride::query()->orderByDesc('effective_from');

        if ($request->filled('calendar_id')) {
            $query->where('calendar_id', $request->integer('calendar_id'));
        }

        // Default view: rules that are still active (no end date, or an
        // end date that hasn't passed yet) — expired rules stay in the
        // table for audit history but shouldn't clutter the admin screen.
        if (!$request->boolean('include_expired')) {
            $query->where(function ($q) {
                $q->whereNull('effective_until')
                    ->orWhere('effective_until', '>=', now()->toDateString());
            });
        }

        return response()->json(
            $query->paginate($request->perPage(15, self::MAX_PER_PAGE))
        );
    }

    public function store(StoreCalendarOverrideRequest $request)
    {
        $override = $this->overrideService->create($request->validated(), $request->user(), $request);

        return response()->json($override, 201);
    }

    public function update(UpdateCalendarOverrideRequest $request, BusinessCalendarOverride $override)
    {
        $override = $this->overrideService->update($override, $request->validated(), $request->user(), $request);

        return response()->json($override);
    }

    public function destroy(Request $request, BusinessCalendarOverride $override)
    {
        $this->overrideService->delete($override, $request->user(), $request);

        return response()->json(['message' => 'Override deleted.']);
    }
}