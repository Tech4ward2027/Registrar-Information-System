<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Reusable paging guard for list endpoints that have no other inputs to
 * validate (BACKEND ACTION ITEM 9.12).
 *
 * Why this exists
 * ---------------
 * Several list endpoints passed `per_page` straight to the paginator
 * (`$request->integer('per_page', 15)`), so a caller could ask for an
 * unbounded page size, and the value was never type-checked. This is the same
 * defect that produced the HTTP 500s on GET /announcements (see
 * IndexAnnouncementsRequest), here on authenticated endpoints.
 *
 * Behaviour
 * ---------
 *  - Malformed input is rejected with HTTP 422: per_page=abc, per_page[]=1,
 *    per_page=0, per_page=-1, page=99999999999999999999.
 *  - A well-formed but too-large per_page is CLAMPED to the endpoint's own
 *    ceiling rather than rejected. Some screens legitimately ask for a large
 *    page (the Business Calendar screen requests per_page=1000), so a hard
 *    422 would break them. The ceiling is chosen per endpoint in perPage().
 *
 * Authorization is deliberately `true`: access control for every endpoint that
 * uses this class is already enforced by route middleware (auth:sanctum,
 * role:, module:). This class only validates paging inputs.
 */
class PaginatedIndexRequest extends FormRequest
{
    public const DEFAULT_MAX_PER_PAGE = 100;
    public const MAX_PAGE             = 100000;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'per_page' => ['nullable', 'integer', 'min:1'],
            'page'     => ['nullable', 'integer', 'min:1', 'max:' . self::MAX_PAGE],
        ];
    }

    /**
     * Validated page size, clamped to [1, $max].
     *
     * @param int $default Size used when the caller sends no per_page.
     * @param int $max     Hard ceiling for this endpoint.
     */
    public function perPage(int $default = 15, int $max = self::DEFAULT_MAX_PER_PAGE): int
    {
        $value = $this->validated('per_page');

        $size = $value === null ? $default : (int) $value;

        return max(1, min($size, $max));
    }
}
