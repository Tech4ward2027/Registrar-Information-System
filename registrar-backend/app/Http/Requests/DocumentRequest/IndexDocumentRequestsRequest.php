<?php

namespace App\Http\Requests\DocumentRequest;

use App\Models\SystemUser;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the query string for GET /document-requests.
 *
 * Every parameter is optional, and an empty query string behaves exactly
 * like the endpoint did before this class existed (newest-first, default
 * dashboard window), so an older frontend build keeps working untouched.
 *
 * Nothing in here reaches SQL as an identifier: `sort` is checked against
 * a fixed allow-list and mapped to hard-coded ORDER BY expressions in
 * DocumentRequest::scopeDashboardOrder(); `status`, `classification` and
 * `document` are only ever bound as values.
 */
class IndexDocumentRequestsRequest extends FormRequest
{
    /** Same labels the staff dashboard's sort dropdown already uses. */
    public const SORTS = [
        'Recent Requests',
        'Old Requests',
        'Classification Asc',
        'Classification Desc',
        'Status Asc',
        'Status Desc',
    ];

    public const CLASSIFICATIONS = ['Student', 'Alumni', 'Undergrad Requestor'];

    public const MIN_SEARCH_LENGTH = 2;
    public const MAX_PER_PAGE      = 200;
    public const DEFAULT_PER_PAGE  = 20;

    public function authorize(): bool
    {
        // Role/module gating is done by route middleware
        // (module:dashboard,View). This only rejects an unauthenticated
        // or non-SystemUser principal before any query is built.
        return $this->user() instanceof SystemUser;
    }

    /**
     * Normalize before validation so the dashboard can keep sending its
     * "All" sentinel and free-typed casing without tripping the
     * allow-lists below.
     */
    protected function prepareForValidation(): void
    {
        $merge = [];

        if (is_string($this->input('search'))) {
            // Collapse runs of whitespace so "  juan   cruz " == "juan cruz".
            $merge['search'] = trim((string) preg_replace('/\s+/u', ' ', $this->input('search')));
        }

        foreach (['status', 'document'] as $key) {
            $value = $this->input($key);
            if (is_string($value)) {
                $value = trim($value);
                $merge[$key] = ($value === '' || strcasecmp($value, 'All') === 0) ? null : $value;
            }
        }

        $classification = $this->input('classification');
        if (is_string($classification)) {
            $classification = trim($classification);

            if ($classification === '' || strcasecmp($classification, 'All') === 0) {
                $merge['classification'] = null;
            } else {
                // Canonicalize case ("student" -> "Student"); "Undergrad"
                // is accepted as shorthand for the full label.
                $lookup = array_change_key_case(array_combine(
                    self::CLASSIFICATIONS,
                    self::CLASSIFICATIONS
                ), CASE_LOWER);
                $lookup['undergrad'] = 'Undergrad Requestor';

                $merge['classification'] = $lookup[strtolower($classification)] ?? $classification;
            }
        }

        // The current frontend sends all_statuses as the string "true", which
        // Laravel's `boolean` rule rejects (it accepts only true/false/1/0/"1"/"0").
        // Coerce the same way the old controller did (FILTER_VALIDATE_BOOLEAN).
        if ($this->has('all_statuses')) {
            $merge['all_statuses'] = filter_var(
                $this->input('all_statuses'),
                FILTER_VALIDATE_BOOLEAN,
                FILTER_NULL_ON_FAILURE
            );
        }

        if ($merge !== []) {
            $this->merge($merge);
        }
    }

    public function rules(): array
    {
        return [
            'search'         => ['nullable', 'string', 'min:' . self::MIN_SEARCH_LENGTH, 'max:100'],
            'status'         => ['nullable', 'string', 'max:60', Rule::exists('request_status', 'status_name')],
            'classification' => ['nullable', Rule::in(self::CLASSIFICATIONS)],
            'document'       => ['nullable', 'string', 'max:255'],
            'sort'           => ['nullable', Rule::in(self::SORTS)],
            'view'           => ['nullable', Rule::in(['active', 'archived'])],
            // Legacy flag kept so the current frontend build keeps working.
            'all_statuses'   => ['nullable', 'boolean'],
            'page'           => ['nullable', 'integer', 'min:1', 'max:100000'],
            'per_page'       => ['nullable', 'integer', 'min:1', 'max:' . self::MAX_PER_PAGE],
        ];
    }

    public function messages(): array
    {
        return [
            'search.min' => 'Search must be at least ' . self::MIN_SEARCH_LENGTH . ' characters.',
            'sort.in'    => 'Unsupported sort option.',
        ];
    }

    // ── Typed accessors for the controller ───────────────────────────────

    public function searchTerm(): ?string
    {
        $value = $this->validated('search');

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function statusName(): ?string
    {
        return $this->validated('status') ?: null;
    }

    public function classification(): ?string
    {
        return $this->validated('classification') ?: null;
    }

    public function documentName(): ?string
    {
        return $this->validated('document') ?: null;
    }

    public function sortOption(): ?string
    {
        return $this->validated('sort') ?: null;
    }

    public function isArchivedView(): bool
    {
        return $this->validated('view') === 'archived';
    }

    public function wantsAllStatuses(): bool
    {
        return (bool) $this->validated('all_statuses');
    }

    public function perPage(): int
    {
        return (int) ($this->validated('per_page') ?? self::DEFAULT_PER_PAGE);
    }

    /** True when any narrowing parameter is present (mirrors the old client-side "isFiltering"). */
    public function isFiltering(): bool
    {
        return $this->searchTerm() !== null
            || $this->statusName() !== null
            || $this->classification() !== null
            || $this->documentName() !== null;
    }
}
