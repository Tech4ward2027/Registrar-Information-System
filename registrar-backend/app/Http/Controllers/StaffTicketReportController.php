<?php

namespace App\Http\Controllers;

use App\Http\Requests\Ticket\Admin\TicketReportRangeRequest;
use App\Models\AuditLog;
use App\Services\AuditLogger;
use App\Services\Tickets\TicketReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Support Ticketing — the SLA and volume summary and the audited log export
 * (tickets/Manage). Read-only; the export leaves an audit row.
 */
class StaffTicketReportController extends Controller
{
    public function __construct(
        private readonly TicketReportService $reports,
        private readonly AuditLogger $audit,
    ) {
    }

    // GET /admin/tickets/summary?from=YYYY-MM-DD&to=YYYY-MM-DD
    public function summary(TicketReportRangeRequest $request): JsonResponse
    {
        [$from, $to] = $request->range();

        return response()->json(['data' => $this->reports->summary($from, $to)]);
    }

    // GET /admin/tickets/export?from=YYYY-MM-DD&to=YYYY-MM-DD   (CSV download)
    //
    // The audit row is written BEFORE any data is streamed, so an export cannot
    // happen without leaving a record (fail closed): if the audit write fails,
    // the request fails and nothing is sent.
    public function export(TicketReportRangeRequest $request): StreamedResponse
    {
        [$from, $to] = $request->range();

        $rows = $this->reports->exportCount($from, $to);
        $max  = (int) config('tickets.reports.export_max_rows');

        if ($rows > $max) {
            throw ValidationException::withMessages([
                'to' => ["That range has {$rows} events; the export is limited to {$max}. Choose a shorter range."],
            ]);
        }

        $this->audit->log($request, $request->user(), AuditLog::ACTION_TICKET_LOG_EXPORTED, [
            'format'    => 'csv',
            'date_from' => $from,
            'date_to'   => $to,
            'row_count' => $rows,
        ]);

        $chunk = (int) config('tickets.reports.export_chunk_size');

        return response()->streamDownload(function () use ($from, $to, $chunk) {
            $out = fopen('php://output', 'w');

            // Explicit enclosure/escape: PHP 8.4 deprecates relying on the default escape.
            fputcsv($out, TicketReportService::exportHeader(), ',', '"', '');

            foreach ($this->reports->exportRows($from, $to, $chunk) as $row) {
                fputcsv($out, $row, ',', '"', '');
            }

            fclose($out);
        }, "ticket-log_{$from}_{$to}.csv", [
            'Content-Type'  => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store',
        ]);
    }
}
