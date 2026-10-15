<?php

namespace App\Http\Controllers;

use App\Http\Requests\DocumentRequest\LogbookExportAuditRequest;
use App\Models\AuditLog;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;

/**
 * Records that a staff member exported the logbook.
 *
 * The export itself is generated client-side from rows the user was already
 * allowed to view, so this endpoint is the only place the server can learn
 * it happened. The frontend calls it BEFORE building the file and aborts the
 * export if this call fails (fail closed), so an export cannot happen
 * without leaving an audit row.
 *
 * Metadata only: range, mode and counts. Never row contents or personal data.
 */
class LogbookExportAuditController extends Controller
{
    public function __construct(private readonly AuditLogger $auditLogger)
    {
    }

    public function store(LogbookExportAuditRequest $request): JsonResponse
    {
        $data = $request->validated();

        $this->auditLogger->log($request, $request->user(), AuditLog::ACTION_LOGBOOK_EXPORTED, [
            'format'        => $data['format'],
            'mode'          => $data['mode'],
            'date_from'     => $data['date_from'] ?? null,
            'date_to'       => $data['date_to'] ?? null,
            'row_count'     => (int) $data['row_count'],
            'section_count' => (int) $data['section_count'],
        ]);

        return response()->json(['message' => 'Export recorded.'], 201);
    }
}
