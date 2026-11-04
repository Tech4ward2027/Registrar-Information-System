<?php

namespace App\Http\Requests\Representative;

use Illuminate\Foundation\Http\FormRequest;

class RevokeRepresentativeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('revoke', $this->route('representative'));
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }
}
