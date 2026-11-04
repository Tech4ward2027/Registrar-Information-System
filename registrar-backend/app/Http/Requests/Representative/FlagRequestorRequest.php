<?php

namespace App\Http\Requests\Representative;

use App\Models\RequestorStatusFlag;
use App\Models\RequestRepresentative;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FlagRequestorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('flag', RequestRepresentative::class);
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in(RequestorStatusFlag::STATUSES)],
            'note'   => ['nullable', 'string', 'max:500'],
        ];
    }
}
