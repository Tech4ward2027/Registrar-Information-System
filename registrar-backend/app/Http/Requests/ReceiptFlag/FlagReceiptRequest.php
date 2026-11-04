<?php

namespace App\Http\Requests\ReceiptFlag;

use Illuminate\Foundation\Http\FormRequest;

class FlagReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('flagReceipt', $this->route('documentRequest'));
    }

    public function rules(): array
    {
        return [
            // Stays in the audit trail only; the Super Admin alert carries
            // the request number and nothing else.
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ];
    }
}
