<?php

namespace App\Http\Requests\DocumentRequest;

use App\Models\SystemUser;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the query string for GET /document-requests/logbook.
 *
 * Every parameter is optional; an empty query string behaves exactly like
 * the endpoint did before server-side filtering existed (newest first, all
 * Completed requests, 25 per page), so an older frontend build keeps working.
 *
 * Nothing here reaches SQL as an identifier. Category and certification
 * names, the search term and the dates are only ever bound as values by
 * App\Services\LogbookQueryService.
 */
class LogbookIndexRequest extends FormRequest
{
    public const MODE_DOCUMENTS      = 'documents';
    public const MODE_CERTIFICATIONS = 'certifications';

    /** Sentinel labels the screen sends for "no category restriction". */
    public const ALL_DOCUMENTS      = 'all document';
    public const ALL_CERTIFICATIONS = 'all certification';

    public const MAX_PER_PAGE     = 100;
    public const DEFAULT_PER_PAGE = 25;
    public const MAX_CATEGORIES   = 50;

    public function authorize(): bool
    {
        // Role / module gating (role:3,4 + module:logbook,View) is done by
        // route middleware. This only rejects a non-SystemUser principal.
        return $this->user() instanceof SystemUser;
    }

    public function rules(): array
    {
        return [
            'from'         => ['nullable', 'date_format:Y-m-d'],
            'to'           => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'search'       => ['nullable', 'string', 'max:100'],
            'mode'         => ['nullable', 'in:' . self::MODE_DOCUMENTS . ',' . self::MODE_CERTIFICATIONS],
            'categories'   => ['nullable', 'array', 'max:' . self::MAX_CATEGORIES],
            'categories.*' => ['string', 'max:255'],
            'certification' => ['nullable', 'string', 'max:255'],
            // Legacy partial-name filter kept for older callers.
            'doc_type'     => ['nullable', 'string', 'max:255'],
            'per_page'     => ['nullable', 'integer', 'min:1'],
            'page'         => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function mode(): string
    {
        return $this->input('mode') === self::MODE_CERTIFICATIONS
            ? self::MODE_CERTIFICATIONS
            : self::MODE_DOCUMENTS;
    }

    public function searchTerm(): string
    {
        return trim((string) $this->input('search', ''));
    }

    /**
     * Selected category labels, trimmed and de-duplicated. Empty when the
     * caller asked for everything (nothing selected, or the "All Document"
     * sentinel is among them).
     *
     * @return list<string>
     */
    public function categoryLabels(): array
    {
        $labels = array_values(array_unique(array_filter(
            array_map(fn ($v) => trim((string) $v), (array) $this->input('categories', [])),
            fn (string $v) => $v !== ''
        )));

        foreach ($labels as $label) {
            if (mb_strtolower($label) === self::ALL_DOCUMENTS) {
                return [];
            }
        }

        return $labels;
    }

    /** The single certification label, or null for "any certification". */
    public function certificationLabel(): ?string
    {
        $label = trim((string) $this->input('certification', ''));

        if ($label === '' || mb_strtolower($label) === self::ALL_CERTIFICATIONS) {
            return null;
        }

        return $label;
    }

    public function perPage(): int
    {
        return max(1, min((int) $this->input('per_page', self::DEFAULT_PER_PAGE), self::MAX_PER_PAGE));
    }
}
