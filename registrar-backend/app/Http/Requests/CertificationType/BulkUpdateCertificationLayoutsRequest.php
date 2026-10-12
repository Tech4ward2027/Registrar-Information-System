<?php

namespace App\Http\Requests\CertificationType;

use App\Services\CertificationLayoutService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * PUT /certifications/layouts/bulk
 *
 * Body: { "layouts": [ { "certificate_type_id": 1, <layout fields...> }, ... ] }
 *
 * Per-item field rules are identical to UpdateCertificationLayoutRequest.
 * Authorization is the route's 'role:3' group; the archived-template rule is
 * a business rule on record state and is enforced (under a row lock) in
 * CertificationLayoutService, same split as the single-item endpoint.
 */
class BulkUpdateCertificationLayoutsRequest extends FormRequest
{
    /** Upper bound per call — keeps the transaction and lock window small. */
    public const MAX_ITEMS = 50;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'layouts'                           => ['required', 'array', 'min:1', 'max:' . self::MAX_ITEMS],
            'layouts.*'                         => ['required', 'array'],
            // distinct: the same certificate twice in one batch would make
            // "last one wins" silently decide the outcome.
            'layouts.*.certificate_type_id'     => ['required', 'integer', 'distinct', 'exists:certificate_type,certificate_type_id'],
            'layouts.*.layout_header_left_url'  => 'nullable|string|max:2048',
            'layouts.*.layout_header_right_url' => 'nullable|string|max:2048',
            'layouts.*.layout_footer_urls'      => 'nullable|array',
            'layouts.*.layout_footer_urls.*'    => 'string|max:2048',
            'layouts.*.layout_header_logo_size' => 'nullable|integer|min:24|max:240',
            'layouts.*.layout_footer_logo_size' => 'nullable|integer|min:16|max:240',
            'layouts.*.layout_header_lines'     => 'nullable|array',
            'layouts.*.layout_header_lines.*'   => 'nullable|string|max:255',
            'layouts.*.layout_header_font_size' => 'nullable|integer|min:8|max:36',
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                foreach ((array) $this->input('layouts', []) as $i => $item) {
                    if (is_array($item) && array_intersect_key($item, array_flip(CertificationLayoutService::LAYOUT_FIELDS)) === []) {
                        $validator->errors()->add("layouts.$i", 'Each entry must include at least one layout field to update.');
                    }
                }
            },
        ];
    }
}