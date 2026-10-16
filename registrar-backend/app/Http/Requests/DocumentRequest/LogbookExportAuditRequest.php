<?php

namespace App\Http\Requests\DocumentRequest;

use App\Models\SystemUser;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the body of POST /document-requests/logbook/export-audit.
 *
 * The logbook DOCX is built in the browser, so the server never sees the
 * file. This payload only describes WHAT was exported (range, mode, size)
 * for the audit trail. It carries no names, emails or request contents.
 */
class LogbookExportAuditRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Role/module gating (role:3,4 + module:logbook,Export) is done by
        // route middleware. This only rejects a non-SystemUser principal.
        return $this->user() instanceof SystemUser;
    }

    public function rules(): array
    {
        return [
            'format'        => ['required', 'in:docx'],
            'mode'          => ['required', 'in:documents,certifications'],
            'date_from'     => ['nullable', 'date_format:Y-m-d'],
            'date_to'       => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'row_count'     => ['required', 'integer', 'min:0', 'max:1000000'],
            'section_count' => ['required', 'integer', 'min:0', 'max:1000'],
        ];
    }
}
