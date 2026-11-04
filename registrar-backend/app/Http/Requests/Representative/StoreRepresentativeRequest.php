<?php

namespace App\Http\Requests\Representative;

use App\Models\RequestRepresentative;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRepresentativeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manageRepresentative', $this->route('documentRequest'));
    }

    public function rules(): array
    {
        $file = [
            'required',
            'file',
            'mimes:pdf,jpg,jpeg,png',
            'max:' . (int) config('notification_mail.representative.max_proof_kb', 5120),
        ];

        return [
            'full_name'    => ['required', 'string', 'min:2', 'max:150'],
            'email'        => ['required', 'string', 'email:rfc', 'max:100'],
            'relationship' => ['required', 'string', Rule::in(RequestRepresentative::RELATIONSHIPS)],

            // A valid government ID of the representative, a document showing
            // the relationship (birth/marriage certificate, court or notarized
            // authority), and the death certificate or medical/court document
            // for incapacity.
            RequestRepresentative::PROOF_REPRESENTATIVE_ID => $file,
            RequestRepresentative::PROOF_RELATIONSHIP      => $file,
            RequestRepresentative::PROOF_EVENT             => $file,
        ];
    }

    /** @return array<string,\Illuminate\Http\UploadedFile> */
    public function proofFiles(): array
    {
        $files = [];
        foreach (RequestRepresentative::REQUIRED_PROOFS as $kind) {
            $files[$kind] = $this->file($kind);
        }

        return $files;
    }
}
