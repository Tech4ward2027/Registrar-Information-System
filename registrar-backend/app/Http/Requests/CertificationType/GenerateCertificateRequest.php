<?php

namespace App\Http\Requests\CertificationType;

use App\Services\Certificates\MergeTags\MergeTagRegistry;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Shape/type validation for POST /certificates/generate.
 *
 * Authorization is the route's role + dashboard-module middleware. Anything
 * that identifies the student (name, course, OR number, graduation date, ...)
 * is resolved by the server from `student_num`; the only values the caller can
 * supply are the registry's MANUAL tags, under `fields`. Any other key there is
 * rejected, so a request can never override a server-resolved value.
 */
class GenerateCertificateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(MergeTagRegistry $registry): array
    {
        return [
            'certificate_type_id'    => ['required', 'integer', 'min:1', 'exists:certificate_type,certificate_type_id'],
            // Same pattern as the Phase 1 lookup.
            'student_num'            => ['required', 'string', 'max:50', 'regex:/^[A-Za-z0-9\-]+$/'],
            'request_id'             => ['nullable', 'required_with:request_certificate_id', 'integer', 'min:1'],
            'request_certificate_id' => ['nullable', 'integer', 'min:1'],
            'signatory_id'           => ['nullable', 'integer', 'min:1', 'exists:signatories,signatory_id'],
            'fields'                 => ['nullable', 'array', 'max:' . count($registry->manualNames())],
            'fields.*'               => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'student_num.regex' => 'The student number may only contain letters, numbers and hyphens.',
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            $fields = $this->input('fields');
            if (!is_array($fields)) {
                return;
            }

            $allowed = app(MergeTagRegistry::class)->manualNames();
            $unknown = array_values(array_filter(
                array_map('strval', array_keys($fields)),
                fn (string $key) => !in_array($key, $allowed, true)
            ));

            if ($unknown !== []) {
                $validator->errors()->add(
                    'fields',
                    'These fields cannot be set manually: ' . implode(', ', $unknown) . '.'
                );
            }
        }];
    }
}
