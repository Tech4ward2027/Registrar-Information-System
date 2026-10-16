<?php

namespace App\Http\Requests\CertificationType;

use App\Enums\CertificateOrientationEnum;
use App\Enums\CertificatePaperSizeEnum;
use App\Services\Certificates\MergeTags\MergeTagRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shape/type validation for saving a certificate template. Authorization is
 * the route's role:3 group; the deeper checks (merge-tag whitelist, markup
 * sanitising, required-fields-used-in-body) live in CertificateTemplateService
 * because they depend on the parsed template, not just the request body.
 */
class UpsertCertificateTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(MergeTagRegistry $registry): array
    {
        $margin = [
            'sometimes', 'numeric',
            'min:' . config('certificate_templates.margin_min_mm'),
            'max:' . config('certificate_templates.margin_max_mm'),
        ];

        return [
            'content'            => ['required', 'string', 'max:' . config('certificate_templates.max_content_length')],
            'paper_size'         => ['sometimes', Rule::enum(CertificatePaperSizeEnum::class)],
            'orientation'        => ['sometimes', Rule::enum(CertificateOrientationEnum::class)],
            'margin_top'         => $margin,
            'margin_right'       => $margin,
            'margin_bottom'      => $margin,
            'margin_left'        => $margin,
            'required_fields'    => ['sometimes', 'array', 'max:' . count($registry->names())],
            'required_fields.*'  => ['string', 'distinct', Rule::in($registry->names())],
            'change_note'        => ['nullable', 'string', 'max:255'],
        ];
    }
}
