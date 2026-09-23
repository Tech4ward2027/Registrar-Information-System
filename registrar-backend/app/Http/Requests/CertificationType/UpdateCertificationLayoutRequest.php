<?php

namespace App\Http\Requests\CertificationType;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCertificationLayoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route already sits behind the 'role:3' middleware group in
        // routes/api.php — no per-request Policy check needed here.
        // The separate "is this cert archived / template locked" check
        // stays in the controller: it's a business rule on the record's
        // state, not a shape/type rule on the request body.
        return true;
    }

    public function rules(): array
    {
        return [
            'layout_header_left_url'  => 'nullable|string|max:2048',
            'layout_header_right_url' => 'nullable|string|max:2048',
            'layout_footer_urls'      => 'nullable|array',
            'layout_footer_urls.*'    => 'string|max:2048',
            'layout_header_logo_size' => 'nullable|integer|min:24|max:240',
            'layout_footer_logo_size' => 'nullable|integer|min:16|max:240',
            'layout_header_lines'     => 'nullable|array',
            'layout_header_lines.*'   => 'nullable|string|max:255',
            'layout_header_font_size' => 'nullable|integer|min:8|max:36',
        ];
    }
}
