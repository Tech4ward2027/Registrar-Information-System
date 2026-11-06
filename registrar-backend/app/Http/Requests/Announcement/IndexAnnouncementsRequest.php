<?php

namespace App\Http\Requests\Announcement;

use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /announcements (public, also used by staff for the archived tab).
 *
 * Validates the paging inputs so malformed or hostile values (per_page=abc,
 * per_page[]=1, per_page=-1, per_page=999999999, page=99999999999999999999)
 * are rejected with a 422 instead of reaching the query builder, where they
 * previously surfaced as HTTP 500 responses and allowed unbounded page sizes
 * on an unauthenticated endpoint.
 *
 * Same pattern as IndexDocumentRequestsRequest / ListMyTicketsRequest.
 */
class IndexAnnouncementsRequest extends FormRequest
{
    public const DEFAULT_PER_PAGE = 4;
    public const MAX_PER_PAGE     = 100;
    public const MAX_PAGE         = 100000;

    /** Public endpoint: visibility of unpublished rows is decided in the controller. */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'per_page' => ['nullable', 'integer', 'min:1', 'max:' . self::MAX_PER_PAGE],
            'page'     => ['nullable', 'integer', 'min:1', 'max:' . self::MAX_PAGE],
            'view'     => ['nullable', 'string', 'max:32'],
        ];
    }

    /** Validated page size, defaulting to DEFAULT_PER_PAGE. */
    public function perPage(): int
    {
        return (int) ($this->validated('per_page') ?? self::DEFAULT_PER_PAGE);
    }
}