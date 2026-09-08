<?php

namespace App\Http\Controllers;

use App\Services\FreeRequestReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * FESPEC-0008 — Phase 8 Observability.
 *
 * GET /free-requests/reports/monthly-volume?year=2026
 *
 * Returns free-issuance claim volume grouped by month and document/
 * certificate type, for the registrar's oversight review. See
 * FreeRequestReportService's docblock for what "claimed" means here
 * and why it's computed the way it is.
 *
 * Gated the same way as the rest of the free-requests module's
 * read-only endpoints ('module:free_requests,View' in routes/api.php)
 * rather than a new module/action. This codebase has no separate
 * "reports" module today (ReportManagement.jsx's own data — audit logs,
 * security events — is fetched through AuditLogController/dedicated
 * security-event endpoints, each gated by ITS OWN module, not a shared
 * reports module). If free-issuance oversight later needs to be visible
 * to staff who shouldn't be able to file/search free requests
 * themselves (e.g. read-only leadership access), that's a real reason
 * to introduce a dedicated module/action — flagging it rather than
 * guessing at a shape that doesn't exist yet in this codebase.
 *
 * Response shape is a flat array of {month, type_label, count} rows
 * (long/tidy format), not a month → type_label → count nested object —
 * this is the shape ReportManagement.jsx's existing table/CSV-export
 * pattern (auditLogSheet.js) already expects for other reports: one row
 * per (dimension, dimension, measure) tuple, straightforward to render
 * in a table or feed to a charting library without reshaping first.
 */
class FreeRequestReportController extends Controller
{
    public function __construct(private readonly FreeRequestReportService $reports) {}

    public function monthlyVolume(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'year' => ['sometimes', 'integer', 'min:2000', 'max:2100'],
        ]);

        $year = $validated['year'] ?? null;
        $data = $this->reports->monthlyVolume($year);

        return response()->json([
            'year' => $year ?? now(config('app.display_timezone', 'Asia/Manila'))->year,
            'data' => $data->values(),
        ]);
    }
}
