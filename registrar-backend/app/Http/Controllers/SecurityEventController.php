<?php

namespace App\Http\Controllers;

use App\Http\Requests\PaginatedIndexRequest;
use App\Models\SecurityEvent;
use Illuminate\Http\Request;

/**
 * SecurityEventController
 * ========================
 * Read-only endpoints over the security_events table (Phase 3f). Same
 * filter/pagination shape as AuditLogController by design — the frontend
 * "Security Events" tab reuses the Audit Log page's table/pagination
 * component (see plan doc Phase 3g), so the two response shapes are kept
 * as close to identical as the underlying data allows.
 *
 * Gated role:4 (Super Admin) only at the route level — see routes/api.php.
 */
class SecurityEventController extends Controller
{
    // -------------------------------------------------------
    // GET /security-events
    //
    // Supports:
    //   ?search=juan@example.com   → searches email
    //   ?event_type=login_failed   → filter by event_type
    //   ?reason=bad_password       → filter by reason
    //   ?per_page=20                → pagination (default 20)
    //
    // Example: GET /security-events?event_type=login_failed&per_page=50
    // -------------------------------------------------------
    public function index(PaginatedIndexRequest $request)
    {
        $query = SecurityEvent::query()->orderByDesc('created_at');

        if ($search = $request->query('search')) {
            $query->where('email', 'like', '%' . $search . '%');
        }

        if ($eventType = $request->query('event_type')) {
            $query->where('event_type', $eventType);
        }

        if ($reason = $request->query('reason')) {
            $query->where('reason', $reason);
        }

        $perPage = $request->perPage(20);

        $events = $query->paginate($perPage);

        return response()->json([
            'data' => $events->map(fn ($event) => $this->format($event)),
            'meta' => [
                'current_page' => $events->currentPage(),
                'last_page'    => $events->lastPage(),
                'per_page'     => $events->perPage(),
                'total'        => $events->total(),
            ],
        ]);
    }

    // -------------------------------------------------------
    // GET /security-events/filters
    //
    // Returns the distinct values available for each filter dropdown —
    // same "call once on page load" contract as AuditLogController::filters().
    // -------------------------------------------------------
    public function filters()
    {
        return response()->json([
            'event_types' => SecurityEvent::distinct()->pluck('event_type')->sort()->values(),
            'reasons'     => SecurityEvent::distinct()->whereNotNull('reason')
                                  ->pluck('reason')->sort()->values(),
        ]);
    }

    // -------------------------------------------------------
    // Format a single event for the frontend.
    // Matches the Audit Log table's columns (Timestamp, User/email,
    // "Role" slot repurposed as Event Type, Action slot as Reason,
    // Browser) so the shared table component needs no branching logic
    // per tab — see ReportManagement.jsx.
    // -------------------------------------------------------
    private function format(SecurityEvent $event): array
    {
        // created_at is stored in UTC; convert to the display timezone
        // (Asia/Manila) before formatting — same fix as
        // AuditLogController::format(), applied here too since this tab
        // sits right next to the Audit Log tab on the same screen and
        // showing UTC on one and local time on the other would just be
        // a different flavor of the same "our times are wrong" bug.
        $localTimestamp = $event->created_at->copy()
            ->setTimezone(config('app.display_timezone', 'Asia/Manila'));

        return [
            'id'         => $event->security_event_id,
            'email'      => $event->email ?? 'Unknown',
            'event_type' => $this->formatLabel($event->event_type),
            'reason'     => $event->reason ? $this->formatLabel($event->reason) : null,
            'ip_address' => $event->ip_address,
            'date'       => $localTimestamp->format('Y-m-d'),
            'time'       => $localTimestamp->format('H:i:s'),
        ];
    }

    // -------------------------------------------------------
    // Convert snake_case values to readable labels for the UI.
    // e.g. 'login_failed' → 'Login Failed', 'bad_password' → 'Bad Password'
    // -------------------------------------------------------
    private function formatLabel(string $value): string
    {
        return ucwords(str_replace('_', ' ', $value));
    }
}