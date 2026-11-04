<?php

namespace App\Http\Requests\Representative;

use App\Models\RequestRepresentative;
use Illuminate\Foundation\Http\FormRequest;

class LiftRequestorFlagRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('liftFlag', RequestRepresentative::class);
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }
}
