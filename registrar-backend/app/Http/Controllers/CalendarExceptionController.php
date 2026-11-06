<?php

namespace App\Http\Controllers;

use App\Http\Requests\CalendarException\StoreCalendarExceptionRequest;
use App\Http\Requests\CalendarException\UpdateCalendarExceptionRequest;
use App\Http\Requests\PaginatedIndexRequest;
use App\Models\BusinessCalendarHoliday;
use App\Services\CalendarExceptionService;
use Illuminate\Http\Request;

/**
 * Admin/superadmin CRUD for one-off dated closures (holidays, class/office
 * suspensions, one-off events like fumigation). Gated in routes/api.php by
 * ['role:3,4', 'module:business_calendar'] — both admin and super admin
 * can manage this by default only for super admin (module bypass);
 * an admin needs the "Business Calendar" module granted on their policy,
 * same fine-grained pattern as Analytics/Logbook/Access Requests.
 */
class CalendarExceptionController extends Controller
{
    /** The Business Calendar screen loads the full list in one request (per_page=1000). */
    private const MAX_PER_PAGE = 1000;

    public function __construct(
        private CalendarExceptionService $exceptionService,
    ) {}

    public function index(PaginatedIndexRequest $request)
    {
        $query = BusinessCalendarHoliday::query()->orderByDesc('date');

        if ($request->filled('calendar_id')) {
            $query->where('calendar_id', $request->integer('calendar_id'));
        }

        if ($request->filled('type')) {
            $query->where('type', $request->string('type'));
        }

        // Default view: today onward, so the admin screen isn't buried in
        // past closures. ?include_past=1 opts back into full history.
        if (!$request->boolean('include_past')) {
            $query->where('end_date', '>=', now()->toDateString());
        }

        return response()->json(
            $query->paginate($request->perPage(15, self::MAX_PER_PAGE))
        );
    }

    public function store(StoreCalendarExceptionRequest $request)
    {
        $exception = $this->exceptionService->create($request->validated(), $request->user(), $request);

        return response()->json($exception, 201);
    }

    public function update(UpdateCalendarExceptionRequest $request, BusinessCalendarHoliday $exception)
    {
        $exception = $this->exceptionService->update($exception, $request->validated(), $request->user(), $request);

        return response()->json($exception);
    }

    public function destroy(Request $request, BusinessCalendarHoliday $exception)
    {
        $this->exceptionService->delete($exception, $request->user(), $request);

        return response()->json(['message' => 'Closure deleted.']);
    }
}