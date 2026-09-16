<?php

namespace App\Http\Requests\UndergradRequestor;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Undergrad Requestor Registration — Phase 2 (D10).
 *
 * Public, unauthenticated — the frontend's confirmation-link page
 * (/undergrad-requestor/verify-email) reads {email, token} from its own
 * query string and POSTs them here. See
 * UndergradRequestorRegistrationService::confirmEmail() for why the
 * actual token check gives a single generic error rather than
 * distinguishing failure reasons.
 */
class ConfirmUndergradRequestorEmailRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => 'required|email|max:100',
            'token' => 'required|string|size:40',
        ];
    }
}
